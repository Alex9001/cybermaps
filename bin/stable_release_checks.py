#!/usr/bin/env python3
"""Additional evidence required for stable releases and native distribution."""
from datetime import datetime, timezone
import importlib.util
import json
from pathlib import Path
import re
import sys
import tempfile

from workspace import ROOT, package_files, release_dir, require, safe_path, run
from wordpress_release import sha256, validate_presentation, manifest

PRESENTATION_CHECKS = {
    'admin_desktop', 'admin_tablet', 'admin_mobile', 'keyboard', 'zoom_200_reflow',
    'default_empty_states', 'error_states', 'settings_save', 'destructive_actions',
    'primary_journeys', 'screenshots_current', 'optional_dependencies_truthful', 'visual_review',
}


def module(relative, name):
    path = ROOT / relative
    require(path.is_file(), 'Required release validator is missing: ' + relative)
    spec = importlib.util.spec_from_file_location(name, path)
    result = importlib.util.module_from_spec(spec)
    sys.modules[name] = result
    previous = sys.dont_write_bytecode
    try:
        sys.dont_write_bytecode = True
        spec.loader.exec_module(result)
    finally:
        sys.dont_write_bytecode = previous
    return result


def presentation_browser(value, name, captures):
    message = 'Presentation browser checks failed: ' + name
    require(value.get('passed') is True and all(value.get(key) == []
            for key in ('page_errors', 'failures')), message)
    errors = value.get('console_errors')
    if errors == []:
        return
    # The configured browser deliberately sends one log-clear POST without a
    # nonce. Keep its raw observation, but accept it only with the actual denied
    # response and the completed authority/confirmation interaction behind it.
    origin = captures.get('origin')
    require(name == 'browser.json' and value.get('phase') == 'configured'
            and isinstance(origin, str) and re.fullmatch(r'http://127\.0\.0\.1:[1-9][0-9]{0,4}', origin)
            and int(origin.rsplit(':', 1)[1]) <= 65535
            and all(value.get(key) == captures.get(key) for key in ('origin', 'version', 'zip_sha256')),
            message)
    expected = dict(url=origin + '/wp-admin/admin.php?page=cybermaps-system-status',
                    text='Failed to load resource: the server responded with a status of 403 (Forbidden)',
                    expected=True)
    require(errors == [expected] and errors[0].get('expected') is True, message)
    interactions = value.get('interactions')
    require(isinstance(interactions, list) and all(isinstance(item, dict) for item in interactions), message)
    matches = [item for item in interactions if item.get('name') == 'destructive-log-clear-authority-and-confirmation']
    require(len(matches) == 1 and matches[0].get('passed') is True, message)
    detail = matches[0].get('detail')
    require(isinstance(detail, dict) and detail.get('missing_nonce') == {'status': 403, 'body': '-1'}
            and type(detail['missing_nonce']['status']) is int
            and detail.get('confirmation_dismissed') is True
            and type(detail.get('confirmed_status')) is int and detail['confirmed_status'] == 200
            and detail.get('confirmed_result') == 'Diagnostic events cleared.', message)


def presentation(number, zip_sha256, commit, max_age_hours):
    path = release_dir(number) / 'presentation/review.json'
    record = json.loads(path.read_text())
    require(record.get('schema_version') == 1 and record.get('state') == 'presentation-verified'
            and record.get('version') == number and record.get('commit') == commit
            and record.get('zip_sha256') == zip_sha256, 'Presentation review is missing or stale')
    age = (datetime.now(timezone.utc) - datetime.fromisoformat(record['reviewed_at'])).total_seconds()
    require(0 <= age <= max_age_hours * 3600, 'Presentation review expired')
    require(isinstance(record.get('reviewer'), str) and record['reviewer'].strip(), 'Presentation reviewer missing')
    require(set(record.get('checks', {})) == PRESENTATION_CHECKS
            and all(value is True for value in record['checks'].values()), 'Presentation checks are incomplete')
    evidence_files(record, number)
    captures_path = release_dir(number) / 'presentation/capture-manifest.json'
    require(str(captures_path.relative_to(ROOT)) in record['evidence'], 'Capture manifest is not part of the visual review')
    capture_manifest = json.loads(captures_path.read_text())
    captures = capture_manifest.get('captures', [])
    policy = json.loads((ROOT / 'docs/dev/release-policy.json').read_text())
    require(len(captures) == policy['presentation']['website_screenshots']
            and capture_manifest.get('zip_sha256') == zip_sha256
            and all(capture_manifest.get(k) is True for k in ('passed', 'default_passed', 'configured_passed', 'debug_clean')),
            'Exact candidate gallery inventory or passing capture checks are missing')
    for name in ('fixture.json', 'browser.json', 'default-states.json', 'installed-files.json'):
        path = release_dir(number) / 'presentation' / name
        require(str(path.relative_to(ROOT)) in record['evidence'], 'Presentation runtime evidence is missing: ' + name)
        value = json.loads(path.read_text())
        if name == 'installed-files.json':
            contents, actual = package_files(release_dir(number) / 'candidate' / ('cybermaps_' + number + '.zip'))
            require(actual == zip_sha256 == value.get('zip_sha256') and value.get('files') == manifest(contents),
                    'Presentation installation does not match the exact candidate')
        elif name == 'fixture.json':
            require(value.get('version') == number and value.get('zip_sha256') == zip_sha256
                    and value.get('passed') is True, 'Presentation fixture did not pass for this candidate')
        else:
            presentation_browser(value, name, capture_manifest)
    reviewed_hashes = set(record['evidence'].values())
    require(all(capture.get('sha256') in reviewed_hashes for capture in captures),
            'Each actual gallery capture must be included in the visual review evidence')
    directory_assets = json.loads((ROOT / 'docs/wporg/manifest.json').read_text())
    require(all(image['sha256'] in reviewed_hashes for name, image in directory_assets['files'].items()
                if name.startswith('screenshot-')), 'Directory screenshots are missing from visual review evidence')
    return record


def evidence_files(record, number):
    evidence = record.get('evidence')
    require(isinstance(evidence, dict) and evidence, 'Release evidence references are missing')
    for relative, expected in evidence.items():
        path = safe_path(ROOT / relative)
        require(path.is_relative_to(release_dir(number)) and path.is_file()
                and sha256(path.read_bytes()) == expected, 'Release evidence changed: ' + relative)


def upgrade(number, zip_sha256, commit, phase, max_age_hours=24):
    require(phase in ('controlled', 'wordpress'), 'Unknown native update phase')
    path = release_dir(number) / ('native-upgrade-' + phase + '.json')
    validator = module('tests/integration/native-upgrade.py', 'cybermaps_native_upgrade')
    record = validator.validate_receipt(path, zip_sha256, commit, max_age_hours=max_age_hours)
    require(record.get('phase') == phase, 'Native update receipt has the wrong distribution source')
    baseline = validator.baseline_policy(commit)
    require(record.get('baseline_version') == baseline['version']
            and record.get('baseline_zip_sha256') == baseline['zip_sha256'], 'Native update baseline differs from policy')
    return dict(path=str(path.relative_to(ROOT)), sha256=sha256(path.read_bytes()),
                phase=phase, checked_at=record.get('validated_at'))


def validate(number, record, max_age_hours=24):
    contents, digest = package_files(release_dir(number) / 'candidate' / ('cybermaps_' + number + '.zip'))
    require(digest == record['zip_sha256'], 'Stable checks must use the exact validated candidate')
    current_copy(contents)
    private_reporting()
    baseline(number)
    validate_presentation(number, contents)
    commit = record['identity']['commit']
    workloads = module('tests/performance/check_release.py', 'cybermaps_release_workloads')
    workloads.validate_receipt(release_dir(number) / 'everyday-workloads.json', digest, commit,
                              max_age_hours=max_age_hours)
    upgrade(number, digest, commit, 'controlled', max_age_hours)
    presentation(number, digest, commit, max_age_hours)
    return {name: sha256((release_dir(number) / name).read_bytes()) for name in (
        'everyday-workloads.json', 'native-upgrade-controlled.json', 'presentation/review.json')}


def current_copy(contents):
    retired = re.compile(rb'open beta feedback|help shape (?:the )?beta|this (?:is an? )?open beta', re.I)
    for name, value in contents.items():
        if name.startswith(('src/', 'assets/')) and name.endswith(('.php', '.js', '.css')):
            require(not retired.search(value), 'Retired beta interface wording remains: ' + name)
    sidebar = contents.get('src/Admin/SettingsPage.php', b'')
    require(b'https://github.com/Alex9001/cybermaps/issues' in sidebar
            and b'https://wordpress.org/support/plugin/cybermaps/' in sidebar,
            'Stable support must preserve GitHub issues and WordPress.org support')
    for name in ('README.md', 'SUPPORT.md', '.github/ISSUE_TEMPLATE/bug_report.yml'):
        value = (ROOT / name).read_bytes()
        require(not retired.search(value) and b'include_prereleases' not in value,
                'Stable project metadata still promotes the beta: ' + name)


def private_reporting():
    """The advertised private security-report route must actually be enabled."""
    response = run('gh', 'api', '--hostname', 'github.com', '--method', 'GET',
                   '-H', 'Accept: application/vnd.github+json',
                   '-H', 'X-GitHub-Api-Version: 2026-03-10',
                   'repos/Alex9001/cybermaps/private-vulnerability-reporting')
    try:
        value = json.loads(response)
    except ValueError as error:
        raise RuntimeError('GitHub private vulnerability reporting returned invalid JSON') from error
    require(isinstance(value, dict) and value.get('enabled') is True,
            'GitHub private vulnerability reporting must be enabled before stable publication')


def baseline(number):
    """Every later stable launch must prove the transition from the previous stable."""
    policy = json.loads((ROOT / 'docs/dev/release-policy.json').read_text())
    expected = policy['upgrade_baseline']
    repo = 'Alex9001/cybermaps'
    pages = json.loads(run('gh', 'api', '--hostname', 'github.com', '--paginate', '--slurp',
                           'repos/' + repo + '/releases?per_page=100'))
    # During the SVN handoff the candidate itself is already public on GitHub.
    # A newer stable release must still block a stale or out-of-order handoff.
    published = [r for page in pages for r in page
                 if not r['draft'] and not r['prerelease'] and r['tag_name'] != 'v' + number]
    if not published:
        require(number == policy['first_stable_version'] and expected['version'] == '8.0.4',
                'Only the first stable launch can use the reviewed beta transition baseline')
        return
    versions = [(tuple(map(int, r['tag_name'][1:].split('.'))), r) for r in published
                if re.fullmatch(r'v\d+\.\d+\.\d+', r['tag_name'])]
    require(versions, 'Published stable versions are not identifiable')
    latest = max(versions, key=lambda item: item[0])[1]
    require(latest['tag_name'] == 'v' + expected['version'],
            'Upgrade/workload baseline must be the latest published stable release; review the policy before release')
    name = 'cybermaps_' + expected['version'] + '.zip'
    with tempfile.TemporaryDirectory(prefix='stable-baseline-') as temporary:
        for asset in (name, name + '.sha256'):
            run('gh', 'release', 'download', latest['tag_name'], '--repo', repo, '--pattern', asset, '--dir', temporary)
        _, digest = package_files(Path(temporary) / name)
        require(digest == expected['zip_sha256'], 'Previous stable baseline differs from the reviewed policy')


def publication_evidence(number):
    """Retain hashes of generated evidence; later source work must not rewrite it."""
    directory = release_dir(number)
    ready = json.loads((directory / 'release-readiness.json').read_text())
    require(ready.get('state') == 'release-ready', 'Publication requires final readiness evidence')
    required = {'everyday-workloads.json', 'native-upgrade-controlled.json', 'presentation/review.json'}
    require(set(ready.get('stable_checks', {})) == required and ready.get('website', {}).get('channel') == 'stable',
            'WordPress publication requires all stable checks and stable website preparation')
    expected_records = dict(ready['stable_checks'], **{
        'validation.json': ready['validation_sha256'], 'agent-review.json': ready['review_sha256']})
    require(all(sha256((directory / name).read_bytes()) == expected for name, expected in expected_records.items()),
            'Readiness evidence changed before the immutable publication snapshot')
    files = {}
    # Runtime logs and screenshots under this release are immutable after the
    # first public write. Capture references from the validation/review records,
    # not arbitrary temporary files or future downstream receipts.
    names = ['release-readiness.json', 'validation.json', 'agent-review.json',
             *ready.get('stable_checks', {}).keys()]
    for name in names:
        path = safe_path(directory / name)
        require(path.is_file(), 'Publication evidence is missing: ' + name)
        files[str(path.relative_to(ROOT))] = sha256(path.read_bytes())
        value = json.loads(path.read_text())
        references = dict(value.get('evidence', {})) if isinstance(value.get('evidence'), dict) else {}
        for check in value.get('checks', {}).values():
            if isinstance(check, dict) and isinstance(check.get('log'), str):
                references[check['log']] = check.get('sha256')
        for section in value.get('sections', {}).values():
            references.update(section.get('evidence', {}))
        for key in ('candidate', 'baseline'):
            measured = value.get(key)
            if isinstance(measured, dict) and isinstance(measured.get('files'), dict):
                for relative, expected in measured['files'].items():
                    references[str(Path(measured['directory']) / relative)] = expected
        for reference, expected in references.items():
            target = safe_path(ROOT / reference)
            if target.is_relative_to(ROOT / 'docs/generated/releases'):
                require(target.is_file() and sha256(target.read_bytes()) == expected,
                        'Publication evidence changed: ' + reference)
                files[reference] = expected
    return dict(identity=ready['identity'], zip_sha256=ready['zip_sha256'],
                checked_at=ready['ready_at'], stable_checks=sorted(ready['stable_checks']), files=files)
