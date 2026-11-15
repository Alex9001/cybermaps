#!/usr/bin/env python3
"""Exercise the WordPress updater from the policy-pinned previous release.

The controlled phase supplies an offer and frozen transport only. The wordpress
phase has no offer/download filters and requires the public update API to offer
the candidate. Both run the native dashboard and WP-CLI updater on single-site
and network-active installations, then compare real persisted state and files.
The first stable launch uses the explicitly pinned last beta; later baselines
are the preceding published stable release enforced by the publication policy.
"""
import argparse
from datetime import datetime, timezone
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
import uuid
import zipfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'bin'))
from workspace import configure, generated, lock, package_files, require, safe_path  # noqa: E402
from wordpress_release import manifest, normalized_zip  # noqa: E402

METHODS = {('dashboard', False), ('cli', False), ('dashboard', True), ('cli', True)}
HARNESS_FILES = (
    'tests/integration/native-upgrade.py', 'tests/integration/native-upgrade-state.php',
    'tests/integration/native-upgrade-environment.php', 'tests/integration/native-upgrade-controlled.php',
    'tests/browser/native-upgrade.mjs', 'tests/cli/composer.lock',
    'bin/workspace.py', 'bin/wordpress_release.py', 'docs/dev/release-policy.json',
)
ROOT_OPTIONS = {'cybermaps_settings', 'cybermaps_discovery_center', 'cybermaps_robots_manager',
                'cybermaps_identity_data', 'native_upgrade_unrelated_option'}
UPDATE_HOOKS = re.compile(r'\b(?:pre_set_site_transient_update_plugins|site_transient_update_plugins|'
                          r'pre_site_transient_update_plugins|plugins_api|update_plugins_[a-zA-Z0-9_]+)\b')


class OfferUnavailable(RuntimeError):
    """The real, successful WordPress.org check has not offered this version yet."""


def committed_bytes(commit, relative):
    require(re.fullmatch(r'[a-f0-9]{40}', commit), 'Use the complete frozen source commit.')
    return subprocess.check_output(['git', '-C', str(ROOT), 'show', commit + ':' + relative],
                                   stdin=subprocess.DEVNULL)


def baseline_policy(commit):
    policy = json.loads(committed_bytes(commit, 'docs/dev/release-policy.json'))
    baseline = policy['upgrade_baseline']
    require(set(baseline) == {'version', 'zip_sha256'} and re.fullmatch(r'\d+\.\d+\.\d+', baseline['version'])
            and re.fullmatch(r'[a-f0-9]{64}', baseline['zip_sha256']), 'Invalid frozen upgrade baseline policy.')
    return baseline


def harness_manifest(commit):
    return {relative: hashlib.sha256(committed_bytes(commit, relative)).hexdigest() for relative in HARNESS_FILES}


def verify_harness(commit):
    expected = harness_manifest(commit)
    for relative, sha in expected.items():
        file = safe_path(ROOT / relative)
        require(file.is_file() and digest(file) == sha, 'Native upgrade harness differs from the frozen commit: ' + relative)
    return expected


def file_manifest_sha256(files):
    return hashlib.sha256(json.dumps(files, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode()).hexdigest()


def source_manifest(commit):
    roots = ('cybermaps.php', 'uninstall.php', 'readme.txt', 'changelog.txt', 'LICENSE', 'src', 'assets', 'languages')
    payload = subprocess.check_output(['git', '-C', str(ROOT), 'archive', '--format=tar', commit, '--', *roots],
                                      stdin=subprocess.DEVNULL)
    expected = {}
    with tarfile.open(fileobj=io.BytesIO(payload)) as tree:
        for entry in tree.getmembers():
            path = Path(entry.name)
            if entry.isfile() and not any(part.startswith('.') for part in path.parts) and path.suffix != '.map':
                expected[entry.name] = hashlib.sha256(tree.extractfile(entry).read()).hexdigest()
    return expected


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + '\n')


def assert_receipt_writable(path, phase):
    require(path.name == 'native-upgrade-' + phase + '.json', 'Native upgrade receipt filename/phase mismatch.')
    publication = path.parent / 'wordpress-publication.json'
    if phase == 'controlled' and publication.exists():
        state = json.loads(publication.read_text())
        require(state.get('state') in ('inputs-frozen', 'svn-not-committed') and not state.get('svn_revision'),
                'Controlled native update evidence is frozen by SVN publication; do not replace it.')
    distribution = path.parent / 'wordpress-distribution.json'
    if phase == 'wordpress' and distribution.exists():
        state = json.loads(distribution.read_text())
        require(state.get('state') != 'distribution-verified', 'Verified WordPress native update evidence is immutable; validate the existing receipt.')


def atomic_receipt(path, receipt):
    require(receipt.get('passed') is True, 'Failed attempts must never replace a canonical native update receipt.')
    assert_receipt_writable(path, receipt['phase'])
    pending = safe_path(path.with_name(path.name + '.' + uuid.uuid4().hex + '.pending'))
    try:
        with pending.open('x') as stream:
            stream.write(json.dumps(receipt, indent=2, sort_keys=True) + '\n')
            stream.flush()
            os.fsync(stream.fileno())
        pending.replace(path)
    finally:
        pending.unlink(missing_ok=True)


def evidence_path(original, snapshot=None):
    original = safe_path(original)
    if snapshot is None:
        return original
    relative = str(original.relative_to(ROOT))
    expected = snapshot['files'].get(relative)
    require(isinstance(expected, str) and re.fullmatch(r'[a-f0-9]{64}', expected),
            'Native update evidence is absent from the immutable publication snapshot: ' + relative)
    file = safe_path(ROOT / snapshot['snapshot'] / 'blobs' / expected)
    require(file.is_file() and digest(file) == expected, 'Frozen native update evidence changed: ' + relative)
    return file


def frozen_controlled_receipt(directory, zip_sha256, commit):
    publication = json.loads((directory / 'wordpress-publication.json').read_text())
    require(publication.get('schema_version') == 1 and publication.get('state') == 'svn-committed'
            and type(publication.get('svn_revision')) is int and publication['svn_revision'] > 0
            and publication.get('zip_sha256') == zip_sha256 and publication.get('commit') == commit,
            'Real updater checks require this candidate\'s committed SVN publication.')
    proof = publication.get('publication_evidence', {})
    require(proof.get('zip_sha256') == zip_sha256 and proof.get('identity', {}).get('commit') == commit
            and isinstance(proof.get('files'), dict), 'Frozen native update publication identity differs.')
    folder = safe_path(ROOT / proof['snapshot'])
    require(folder.is_relative_to(directory / 'wordpress-evidence')
            and re.fullmatch(r'[a-f0-9]{64}', proof['snapshot_sha256']), 'Invalid native update snapshot location.')
    description = folder / 'manifest.json'
    require(digest(description) == proof['snapshot_sha256']
            and json.loads(description.read_text()) == {key: value for key, value in proof.items() if key not in ('snapshot', 'snapshot_sha256')},
            'Native update publication snapshot changed.')
    original = directory / 'native-upgrade-controlled.json'
    frozen = evidence_path(original, proof)
    controlled = validate_receipt(original, zip_sha256, commit, None, _snapshot=proof)
    require(controlled['phase'] == 'controlled', 'Snapshot native update receipt has the wrong phase.')
    return controlled, {'controlled_receipt_sha256': digest(frozen), 'snapshot_sha256': proof['snapshot_sha256'],
                        'svn_revision': publication['svn_revision']}


def package_contract(archive):
    files, sha = package_files(archive)
    with zipfile.ZipFile(archive) as source:
        header = source.read('cybermaps/cybermaps.php').decode()
        match = re.search(r'^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$', header, re.M)
        require(match, 'Candidate version missing.')
        require(not re.search(r'^\s*\*\s*Update URI:', header, re.M | re.I), 'Custom Update URI is forbidden.')
        findings = []
        for entry in source.infolist():
            if entry.filename.endswith('.php'):
                findings += [(entry.filename, item) for item in UPDATE_HOOKS.findall(source.read(entry).decode())]
        require(not findings, 'Custom updater hooks need review: ' + str(findings))
    return {name: hashlib.sha256(body).hexdigest() for name, body in files.items()}, sha, match[1]


def validate_state(before, after, multisite, version, phase, baseline_version):
    for state, expected in ((before, baseline_version), (after, version)):
        require(state['version'] == state['loaded_version'] == expected, 'Header and loaded versions disagree.')
        require(state['basename'] == 'cybermaps/cybermaps.php' and state['update_uri'] == '', 'Plugin update identity changed.')
        require(state['active'] is True and state['multisite'] is multisite and state['network_active'] is multisite,
                'Update lost the requested activation scope.')
        require(state['controlled_offer_installed'] is (phase == 'controlled'), 'Unexpected offer/download fixture.')
        require(state['controlled_download_installed'] is (phase == 'controlled'), 'Unexpected controlled download fixture.')
        require(len(state['sites']) == (2 if multisite else 1), 'Expected site coverage missing.')
    require(before['sites'].keys() == after['sites'].keys(), 'Update lost a site.')
    for site_id, old in before['sites'].items():
        new = after['sites'][site_id]
        require(set(old['options']) == ROOT_OPTIONS and all(old['options'].values()), 'Settings preservation was not seeded.')
        for key in ('options', 'cron', 'rewrites', 'tables', 'owned_files', 'foreign_file_sha256', 'static_mode'):
            require(new[key] == old[key], f'Update changed {key} on site {site_id}.')
        require(old['cron'] and old['rewrites'].get('^native-upgrade-foreign/?$') == 'index.php?native_upgrade_foreign=1',
                'Cron/rewrite preservation fixture missing.')
        require(len(old['tables']) >= 3 and sum(row['row_count'] for row in old['tables'].values()) >= 3,
                'Real persisted table rows missing.')
        for suffix in ('cybermaps_audit_runs', 'cybermaps_audit_resources', 'cybermaps_audit_findings'):
            require(any(name.endswith(suffix) and row['row_count'] >= 1 for name, row in old['tables'].items()),
                    'Persisted report fixture is incomplete.')
        require(bool(old['owned_files']) is (not multisite), 'Owned-file fixture or dynamic-only network boundary missing.')
        require(new['sitemap_index_sha256'] and re.fullmatch('[a-f0-9]{64}', new['sitemap_index_sha256'])
                and new['sitemap_index_children'] >= 1,
                'Updated sitemap generation missing.')


def observations(directory):
    """Read only retained passive observations from the actual WP HTTP API."""
    result = []
    for path in sorted(directory.glob('*-observation.json')):
        record = json.loads(path.read_text())
        require(record['context'] == 'response' and record['sslverify'] is True, 'HTTP provenance used unverified transport.')
        record['_file'] = path
        for kind in ('request', 'response', 'zip'):
            if kind + '_file' not in record:
                continue
            name = record[kind + '_file']
            require(isinstance(name, str) and Path(name).name == name, 'Unsafe HTTP provenance filename.')
            file = safe_path(directory / name)
            require(file.is_file() and digest(file) == record[kind + '_sha256'], 'Retained HTTP bytes changed.')
            record['_' + kind] = file
        result.append(record)
    return result


def valid_update_checks(records, baseline_version):
    valid = []
    for record in records:
        if record['kind'] != 'update-check':
            continue
        require(record['url'] == 'https://api.wordpress.org/plugins/update-check/1.1/'
                and record['method'] == 'POST', 'Unexpected update-check request origin or method.')
        request = json.loads(record['_request'].read_text())
        plugins = json.loads(request['plugins'])['plugins']
        if plugins.get('cybermaps/cybermaps.php', {}).get('Version') != baseline_version:
            continue
        require(record['response_code'] == 200 and record['error'] is None, 'WordPress.org baseline update-check request failed.')
        response = json.loads(record['_response'].read_text())
        require(isinstance(response, dict) and isinstance(response.get('plugins'), (dict, list))
                and (isinstance(response['plugins'], dict) or not response['plugins']), 'Malformed official update-check response.')
        valid.append((record, response['plugins'] or {}))
    require(valid, 'No successful official update check for the baseline installation was recorded.')
    return valid


def require_offer(offer, phase, version, directory, baseline_version):
    checks = valid_update_checks(observations(directory), baseline_version) if phase == 'wordpress' else []
    actual = offer.get('offer')
    if not actual:
        if phase == 'wordpress' and all('cybermaps/cybermaps.php' not in payload for _, payload in checks):
            raise OfferUnavailable('Successful WordPress.org update check has no Cybermaps offer yet.')
        raise RuntimeError('Controlled update offer is missing or differs from the official response.')
    require(actual.get('new_version') == version, 'WordPress offered an unexpected version; inspect release state.')


def verify_transport(directory, version, baseline_version, expected_files):
    records = observations(directory)
    checks = valid_update_checks(records, baseline_version)
    package = 'https://downloads.wordpress.org/plugin/cybermaps.' + version + '.zip'
    matching = [(record, payload['cybermaps/cybermaps.php']) for record, payload in checks
                if isinstance(payload.get('cybermaps/cybermaps.php'), dict)
                and payload['cybermaps/cybermaps.php'].get('new_version') == version
                and payload['cybermaps/cybermaps.php'].get('package') == package]
    require(matching, 'The official update response did not offer this exact version and package.')
    downloads = [record for record in records if record['kind'] == 'download']
    require(downloads, 'No real WordPress.org package download was recorded.')
    for record in downloads:
        require(record['url'] == package and record['method'] == 'GET' and record['response_code'] == 200
                and record['error'] is None and '_zip' in record, 'Official package download failed or has no retained bytes.')
        require(manifest(normalized_zip(record['_zip'].read_bytes())) == expected_files,
                'Downloaded official package file bytes differ from the frozen candidate.')
    return {'package_manifest_sha256': file_manifest_sha256(expected_files),
            'update_checks': [str(record['_file'].relative_to(ROOT)) for record, _ in matching],
            'downloads': [{'observation': str(record['_file'].relative_to(ROOT)), 'zip_sha256': record['zip_sha256'],
                           'zip_file': str(record['_zip'].relative_to(ROOT))} for record in downloads]}


def validate_receipt(path, zip_sha256, commit, max_age_hours=24, *, _snapshot=None, _receipt=None):
    """Recheck granular evidence, with no trust in aggregate passed flags alone."""
    path = safe_path(Path(path).absolute())
    require(path.is_relative_to(generated('releases')), 'Native update evidence must stay under releases/.')
    receipt = _receipt if _receipt is not None else json.loads(evidence_path(path, _snapshot).read_text())
    require(receipt.get('schema_version') == 1 and receipt.get('passed') is True, 'Native update checks are incomplete.')
    require(receipt.get('phase') in ('controlled', 'wordpress'), 'Unknown native upgrade phase.')
    require(path.name == 'native-upgrade-' + receipt['phase'] + '.json', 'Native upgrade receipt filename/phase mismatch.')
    require(receipt.get('zip_sha256') == zip_sha256 and receipt.get('commit') == commit,
            'Native update evidence belongs to another candidate.')
    archive = safe_path(ROOT / receipt['archive'])
    require(archive.is_relative_to(path.parent), 'Native update artifact is outside this release.')
    expected_files, actual_sha, actual_version = package_contract(archive)
    require(actual_sha == zip_sha256 and actual_version == receipt['version'], 'Native update artifact changed.')
    require(expected_files == source_manifest(commit), 'Native update candidate differs from frozen source files.')
    baseline = baseline_policy(commit)
    require(receipt.get('baseline_version') == baseline['version'] and receipt.get('baseline_zip_sha256') == baseline['zip_sha256'],
            'Native update baseline differs from the frozen release policy.')
    require(receipt.get('harness') == harness_manifest(commit), 'Executed native update harness is not bound to the frozen commit.')
    require(receipt.get('package_manifest_sha256') == file_manifest_sha256(expected_files), 'Candidate file manifest mismatch.')
    if receipt['phase'] == 'wordpress':
        require(_snapshot is None, 'Only controlled evidence is read from the prepublication snapshot.')
        controlled, binding = frozen_controlled_receipt(path.parent, zip_sha256, commit)
        require(controlled['phase'] == 'controlled' and receipt['harness'] == controlled['harness'],
                'Real updater checks must execute the same reviewed harness as the controlled phase.')
        require(receipt.get('controlled_publication') == binding, 'Real updater proof is not bound to the committed controlled snapshot.')
    require(receipt.get('package_checks') == {'basename_stable': True, 'update_uri_absent': True, 'custom_updater_hooks_absent': True},
            'Package update identity review is incomplete.')
    checked = datetime.fromisoformat(receipt['validated_at'])
    require(checked.tzinfo is not None, 'Native update evidence needs a timezone.')
    age = (datetime.now(timezone.utc) - checked).total_seconds()
    require(age >= -300 and (max_age_hours is None or age <= max_age_hours * 3600), 'Native update evidence expired.')
    evidence = receipt.get('evidence', {})
    require(evidence, 'Native update granular evidence is missing.')
    for relative, sha in evidence.items():
        original = safe_path(ROOT / relative)
        file = evidence_path(original, _snapshot)
        require(original.is_relative_to(path.parent) and file.is_file() and digest(file) == sha, 'Native update evidence changed: ' + relative)
    cases = receipt.get('cases', [])
    require(len(cases) == 4 and {(case['method'], case['multisite']) for case in cases} == METHODS,
            'Both update mechanisms and activation scopes must pass.')
    for case in cases:
        require(case['passed'] is True, 'Native update case failed.')
        require(all(file in evidence for file in case['evidence'].values()), 'Native update case evidence is unbound.')
        data = {key: json.loads(evidence_path(ROOT / relative, _snapshot).read_text()) for key, relative in case['evidence'].items()
                if key in ('before', 'after', 'offer', 'browser', 'installed', 'wp_cli')}
        validate_state(data['before'], data['after'], case['multisite'], receipt['version'], receipt['phase'], baseline['version'])
        for state in (data['before'], data['after']):
            require(state['wordpress_version'] == receipt['wordpress_version']
                    and state['php_version'].startswith(receipt['php_version'] + '.'), 'Native update runtime mismatch.')
        offer = data['offer']['offer']
        require(offer['slug'] == 'cybermaps' and offer['plugin'] == 'cybermaps/cybermaps.php'
                and offer['new_version'] == receipt['version'] and data['offer']['checked'] == baseline['version'],
                'Wrong native update offer.')
        require(data['offer']['controlled_offer_installed'] is (receipt['phase'] == 'controlled'), 'Offer provenance mismatch.')
        require(data['offer']['controlled_download_installed'] is (receipt['phase'] == 'controlled'), 'Download fixture provenance mismatch.')
        require(re.fullmatch(r'https://downloads\.wordpress\.org/plugin/cybermaps\.' + re.escape(receipt['version']) + r'\.zip', offer['package']),
                'Native update package was not the expected WordPress.org URL.')
        require(data['installed']['matches_candidate'] is True and data['installed']['zip_sha256'] == zip_sha256
                and data['installed']['files'] == expected_files, 'Installed file parity missing.')
        if case['method'] == 'dashboard':
            require(data['browser']['passed'] is True and data['browser']['errors'] == []
                    and data['browser']['expected_version'] == receipt['version'] and data['browser']['browser'],
                    'Native dashboard update evidence missing.')
            require('Version ' + receipt['version'] in data['browser']['installed_text'], 'Dashboard version proof missing.')
        else:
            rows = data['wp_cli']
            require(len(rows) == 1 and rows[0].get('name') == 'cybermaps' and rows[0].get('status') == 'Updated'
                    and rows[0].get('old_version') == baseline['version'] and rows[0].get('new_version') == receipt['version'],
                    'Native WP-CLI update evidence missing.')
        require(case.get('local_candidate_mounted') is (receipt['phase'] == 'controlled') and case.get('custom_router') is False,
                'Real updater proof must not have a local candidate or custom HTTP router.')
        if receipt['phase'] == 'wordpress':
            directory = (ROOT / case['evidence']['offer']).parent / 'http-observations'
            require(case['transport'] == verify_transport(directory, receipt['version'], baseline['version'], expected_files),
                    'Actual WordPress.org HTTP provenance differs from the receipt.')
            for file in directory.iterdir():
                require(str(file.relative_to(ROOT)) in evidence, 'HTTP provenance file is not hash-bound.')
    return receipt


class Runtime:
    def __init__(self, folder, archive, wp, php, phase, version, output, baseline, harness):
        self.folder, self.output = folder, output
        self.engine = 'docker'
        result = subprocess.run(['docker', '--version'], capture_output=True, text=True, check=True, stdin=subprocess.DEVNULL)
        if 'podman' in result.stdout.lower() and shutil.which('podman'):
            self.engine = 'podman'
        self.name = 'cybermaps-upgrade-' + uuid.uuid4().hex[:12]
        self.network, self.database, self.http = self.name + '-net', self.name + '-db', self.name + '-http'
        self.image, self.wp, self.phase, self.version = 'docker.io/library/wordpress:cli-php' + php, wp, phase, version
        self.site = folder / 'wordpress'
        self.site.mkdir()
        self.baseline = baseline
        self.baseline_archive = generated('releases/' + baseline['version'] + '/cybermaps_' + baseline['version'] + '.zip')
        self.candidate = None
        if phase == 'controlled':
            self.candidate = folder / 'candidate'
            self.candidate.mkdir()
            shutil.copyfile(archive, self.candidate / 'cybermaps.zip')
        self.fixtures = folder / 'fixtures'
        self.fixtures.mkdir()
        selected = ['tests/integration/native-upgrade-environment.php', 'tests/integration/native-upgrade-state.php',
                    'tests/browser/native-upgrade.mjs']
        if phase == 'controlled':
            selected.append('tests/integration/native-upgrade-controlled.php')
        for relative in selected:
            target = self.fixtures / Path(relative).name
            shutil.copyfile(ROOT / relative, target)
            require(digest(target) == harness[relative], 'Staged native upgrade fixture differs from frozen source.')
        self.log = output / 'commands.log'
        self.started = []

    def command(self, args, timeout=180, checked=True):
        result = subprocess.run(list(map(str, args)), capture_output=True, text=True, timeout=timeout,
                                env=dict(os.environ, TMPDIR=str(generated('tmp'))), stdin=subprocess.DEVNULL)
        with self.log.open('a') as stream:
            stream.write('$ ' + ' '.join(map(str, args)) + '\n' + result.stdout + result.stderr + '\n')
        if checked:
            require(result.returncode == 0, 'Native update command failed; see ' + str(self.log))
        return result

    def container(self, *args, **kwargs):
        return self.command([self.engine, *args], **kwargs)

    def cli(self, *args, timeout=180):
        candidate = ['-v', str(self.candidate) + ':/candidate:ro'] if self.candidate else []
        return self.container('run', '--rm', '--network', self.network, '--user', '0:0',
                              '-v', str(ROOT / 'docs/generated/tmp/wp-cli-modern') + ':/cli-tools:ro',
                              '-v', str(self.site) + ':/var/www/html',
                              '-v', str(self.fixtures) + ':/validation:ro', '-v', str(self.output) + ':/evidence',
                              '-v', str(self.baseline_archive) + ':/baseline/cybermaps.zip:ro',
                              *candidate, '-w', '/var/www/html', self.image,
                              'php', '-d', 'memory_limit=512M', '/cli-tools/vendor/wp-cli/wp-cli/php/boot-fs.php',
                              *args, '--allow-root', timeout=timeout).stdout.strip()

    def start(self):
        self.container('network', 'create', self.network)
        self.started.append(('network', self.network))
        self.container('run', '-d', '--name', self.database, '--network', self.network,
                       '-e', 'MARIADB_DATABASE=wordpress', '-e', 'MARIADB_USER=wordpress',
                       '-e', 'MARIADB_PASSWORD=wordpress', '-e', 'MARIADB_ROOT_PASSWORD=wordpress',
                       'docker.io/library/mariadb:10.11')
        self.started.append(('container', self.database))
        for _ in range(60):
            if self.container('exec', self.database, 'mariadb-admin', 'ping', '-h', '127.0.0.1',
                              '-uroot', '-pwordpress', '--silent', checked=False, timeout=5).returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError('Disposable database did not become ready.')
        self.cli('core', 'download', '--version=' + self.wp, '--force', '--quiet')
        self.cli('config', 'create', '--dbname=wordpress', '--dbuser=wordpress', '--dbpass=wordpress',
                 '--dbhost=' + self.database, '--skip-check', '--quiet')
        for key, value in {'WP_DEBUG': 'true', 'WP_DEBUG_DISPLAY': 'false', 'WP_DEBUG_LOG': 'true',
                           'DISABLE_WP_CRON': 'true', 'CYBERMAPS_NATIVE_UPGRADE_FIXTURE': 'true'}.items():
            self.cli('config', 'set', key, value, '--raw', '--quiet')
        self.cli('config', 'set', 'CYBERMAPS_NATIVE_UPGRADE_PHASE', self.phase, '--quiet')
        self.cli('config', 'set', 'CYBERMAPS_NATIVE_UPGRADE_VERSION', self.version, '--quiet')
        candidate = ['-v', str(self.candidate) + ':/candidate:ro'] if self.candidate else []
        self.container('run', '-d', '--name', self.http, '--network', self.network, '--user', '0:0',
                       '-p', '127.0.0.1::8080', '-v', str(self.site) + ':/var/www/html',
                       '-v', str(self.output) + ':/evidence', *candidate, '-w', '/var/www/html', self.image,
                       'php', '-d', 'memory_limit=512M', '-S', '0.0.0.0:8080', '-t', '/var/www/html')
        self.started.append(('container', self.http))
        self.origin = 'http://' + self.container('port', self.http, '8080/tcp').stdout.strip()
        require(re.fullmatch(r'http://127\.0\.0\.1:\d+', self.origin), 'Disposable server is not loopback-bound.')
        self.cli('core', 'install', '--url=' + self.origin, '--title=Native update fixture', '--admin_user=admin',
                 '--admin_password=cybermaps-validation', '--admin_email=admin@example.com', '--skip-email', '--quiet')
        mu = self.site / 'wp-content/mu-plugins'
        mu.mkdir(exist_ok=True)
        shutil.copyfile(self.fixtures / 'native-upgrade-environment.php', mu / 'native-upgrade-environment.php')
        if self.phase == 'controlled':
            shutil.copyfile(self.fixtures / 'native-upgrade-controlled.php', mu / 'native-upgrade-controlled.php')

    def close(self):
        debug = self.site / 'wp-content/debug.log'
        if debug.is_file():
            shutil.copyfile(debug, self.output / 'debug.log')
        if ('container', self.http) in self.started:
            response = self.container('logs', self.http, checked=False, timeout=30)
            (self.output / 'http.log').write_text(response.stdout + response.stderr)
        for kind, name in reversed(self.started):
            args = ('network', 'rm', name) if kind == 'network' else ('rm', '-f', name)
            self.container(*args, checked=False, timeout=30)

    def run_case(self, method, multisite, candidate_files, archive_hash):
        if multisite:
            self.cli('core', 'multisite-convert', '--quiet')
            self.cli('site', 'create', '--slug=second', '--title=Second update fixture', '--email=admin@example.com', '--quiet')
        activate = '--activate-network' if multisite else '--activate'
        self.cli('plugin', 'install', '/baseline/cybermaps.zip', activate, '--force', '--quiet')
        self.cli('eval-file', '/validation/native-upgrade-state.php', 'seed')
        paths = {}
        for action in ('before', 'offer'):
            path = self.output / (action + '.json')
            fixture_action = 'snapshot' if action == 'before' else action
            data = json.loads(self.cli('eval-file', '/validation/native-upgrade-state.php', fixture_action))
            write_json(path, data)
            paths[action] = str(path.relative_to(ROOT))
        offer = json.loads((self.output / 'offer.json').read_text())
        require_offer(offer, self.phase, self.version, self.output / 'http-observations', self.baseline['version'])
        if method == 'dashboard':
            browser = self.output / 'browser.json'
            self.command(['node', self.fixtures / 'native-upgrade.mjs', self.origin, browser,
                          self.version, '1' if multisite else '0'], timeout=180)
            paths['browser'] = str(browser.relative_to(ROOT))
        else:
            text = self.cli('plugin', 'update', 'cybermaps', '--format=json', '--quiet', timeout=180)
            path = self.output / 'wp-cli-update.json'
            rows = json.loads(text)
            require(len(rows) == 1 and rows[0].get('status') == 'Updated' and rows[0].get('old_version') == self.baseline['version']
                    and rows[0].get('new_version') == self.version, 'WP-CLI did not perform a native update.')
            write_json(path, rows)
            paths['wp_cli'] = str(path.relative_to(ROOT))
        after = self.output / 'after.json'
        write_json(after, json.loads(self.cli('eval-file', '/validation/native-upgrade-state.php', 'snapshot')))
        paths['after'] = str(after.relative_to(ROOT))
        validate_state(json.loads((self.output / 'before.json').read_text()), json.loads(after.read_text()),
                       multisite, self.version, self.phase, self.baseline['version'])
        installed = self.site / 'wp-content/plugins/cybermaps'
        require(not any(path.is_symlink() for path in installed.rglob('*')), 'Installed plugin contains symlinks.')
        actual = {str(path.relative_to(installed)): digest(path) for path in installed.rglob('*') if path.is_file()}
        require(actual == candidate_files, 'WordPress installed files differ from the frozen candidate.')
        parity = self.output / 'installed.json'
        write_json(parity, {'matches_candidate': True, 'zip_sha256': archive_hash, 'files': actual})
        paths['installed'] = str(parity.relative_to(ROOT))
        log = self.site / 'wp-content/debug.log'
        if log.exists():
            shutil.copyfile(log, self.output / 'debug.log')
            require(log.stat().st_size == 0, 'WordPress emitted warnings/notices during native update.')
        result = {'method': method, 'multisite': multisite, 'passed': True, 'evidence': paths,
                  'local_candidate_mounted': self.candidate is not None, 'custom_router': False}
        if self.phase == 'wordpress':
            result['transport'] = verify_transport(self.output / 'http-observations', self.version,
                                                   self.baseline['version'], candidate_files)
        return result


def run_upgrade():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--zip', required=True, type=Path)
    parser.add_argument('--phase', choices=('controlled', 'wordpress'), required=True)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--wordpress', required=True)
    parser.add_argument('--commit', required=True, help='Frozen source commit used for both native upgrade phases.')
    parser.add_argument('--php', default='8.2')
    args = parser.parse_args()
    configure()
    args.zip = safe_path(args.zip.absolute())
    args.output = safe_path(args.output.absolute())
    require(args.output.is_relative_to(generated('releases')), 'Keep release evidence in docs/generated/releases/.')
    assert_receipt_writable(args.output, args.phase)
    require(re.fullmatch(r'\d+\.\d+(?:\.\d+)?', args.wordpress) and args.php in ('8.2', '8.3', '8.4', '8.5'), 'Invalid runtime version.')
    commit = args.commit
    harness = verify_harness(commit)
    baseline = baseline_policy(commit)
    cli = ROOT / 'docs/generated/tmp/wp-cli-modern'
    require((cli / 'composer.lock').read_bytes() == (ROOT / 'tests/cli/composer.lock').read_bytes(), 'Pinned WP-CLI tools differ; run validation:setup.')
    baseline_archive = generated('releases/' + baseline['version'] + '/cybermaps_' + baseline['version'] + '.zip')
    require(digest(baseline_archive) == baseline['zip_sha256'], 'The policy-pinned upgrade baseline package changed.')
    files, sha, version = package_contract(args.zip)
    require(files == source_manifest(commit), 'Native update candidate differs from frozen source files.')
    baseline_files, _, old_version = package_contract(baseline_archive)
    require(old_version == baseline['version'] and baseline_files, 'Invalid policy-pinned update baseline.')
    controlled_binding = None
    if args.phase == 'wordpress':
        controlled, controlled_binding = frozen_controlled_receipt(args.output.parent, sha, commit)
        require(controlled['phase'] == 'controlled' and controlled['harness'] == harness, 'Controlled upgrade evidence or harness differs.')
    os.environ['PLAYWRIGHT_BROWSERS_PATH'] = str(generated('tmp/playwright-browsers'))
    args.output.parent.mkdir(parents=True, exist_ok=True)
    attempt = args.output.parent / ('native-upgrade-' + args.phase + '-' + uuid.uuid4().hex[:12])
    attempt.mkdir()
    receipt = {'schema_version': 1, 'passed': False, 'phase': args.phase, 'version': version,
               'baseline_version': baseline['version'], 'baseline_zip_sha256': baseline['zip_sha256'],
               'commit': commit, 'zip_sha256': sha, 'archive': str(args.zip.relative_to(ROOT)),
               'harness': harness, 'package_manifest_sha256': file_manifest_sha256(files),
               'wordpress_version': args.wordpress, 'php_version': args.php,
               'package_checks': {'basename_stable': True, 'update_uri_absent': True, 'custom_updater_hooks_absent': True},
               'cases': []}
    if controlled_binding:
        receipt['controlled_publication'] = controlled_binding
    try:
        for method, multisite in sorted(METHODS):
            case_output = attempt / (method + ('-network' if multisite else '-single'))
            case_output.mkdir()
            print('Native update: ' + case_output.name + ' (' + args.phase + ')', flush=True)
            with tempfile.TemporaryDirectory(prefix='cybermaps-native-upgrade-', dir=generated('tmp')) as temporary:
                runtime = Runtime(Path(temporary), args.zip, args.wordpress, args.php, args.phase, version, case_output, baseline, harness)
                try:
                    runtime.start()
                    receipt['cases'].append(runtime.run_case(method, multisite, files, sha))
                finally:
                    runtime.close()
        require(digest(args.zip) == sha and digest(baseline_archive) == baseline['zip_sha256']
                and verify_harness(commit) == harness, 'Candidate, baseline, or frozen harness changed during native update checks.')
        receipt['passed'] = True
        receipt['validated_at'] = datetime.now(timezone.utc).replace(microsecond=0).isoformat()
        receipt['evidence'] = {str(path.relative_to(ROOT)): digest(path) for path in sorted(attempt.rglob('*')) if path.is_file()}
        validate_receipt(args.output, sha, commit, _receipt=receipt)
        atomic_receipt(args.output, receipt)
    except BaseException as error:
        receipt['passed'] = False
        receipt['error'] = str(error)
        receipt['failure_kind'] = 'offer-unavailable' if isinstance(error, OfferUnavailable) else 'native-check-failed'
        raise
    finally:
        receipt.setdefault('validated_at', datetime.now(timezone.utc).replace(microsecond=0).isoformat())
        receipt['evidence'] = {str(path.relative_to(ROOT)): digest(path) for path in sorted(attempt.rglob('*')) if path.is_file()}
        with (attempt / 'receipt.json').open('x') as stream:
            stream.write(json.dumps(receipt, indent=2, sort_keys=True) + '\n')
    print('Native update checks passed: ' + str(args.output))


def main():
    with lock(allow_parent=True):
        run_upgrade()


if __name__ == '__main__':
    try:
        main()
    except OfferUnavailable as error:
        print(str(error), file=sys.stderr)
        raise SystemExit(75) from error
