#!/usr/bin/env python3
"""Shared candidate validation and evidence-bound publication readiness."""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import importlib.util
import json
import os
import re
from pathlib import Path
import shutil
import subprocess
import sys
import urllib.request
import uuid

sys.dont_write_bytecode = True
from workspace import ROOT, configure, git, package_files, release_dir, require, safe_path, save_json, version, load_config
from website_evidence import prepared

POLICY = ROOT / 'docs/dev/release-policy.json'
COMMANDS = {
    'tests': ['composer', 'test'],
    'complexity': ['composer', 'run', 'lint:complexity'],
    'source': ['composer', 'run', 'release:check'],
    'package': ['bash', 'bin/package-candidate.sh'],
    'relay-security': ['npm', 'test', '--prefix', 'infrastructure/cloudflare-oauth-relay'],
    'webmcp-security': ['node', 'tests/browser/webmcp-security.mjs'],
    'admin-ui-contract': ['node', '--test', 'tests/browser/admin-ui-contract.mjs'],
    'performance-harness': ['python3', '-B', '-m', 'unittest', 'discover', '-s', 'tests/performance', '-p', 'test_*.py'],
}


def digest(path):
    return hashlib.sha256(safe_path(path).read_bytes()).hexdigest()


def now():
    return datetime.now(timezone.utc).isoformat()


def policy():
    value = json.loads(POLICY.read_text())
    require(value['schema_version'] == 1 and len(value['conditions']) == 17 and {'website_prepared', 'website_live', 'remote_capabilities'} <= value['conditions'].keys(), 'Invalid release policy')
    return value


def identity():
    names = subprocess.check_output(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], cwd=ROOT).split(b'\0')
    hashes = []
    for name in sorted(set(n.decode() for n in names if n)):
        path = safe_path(ROOT / name)
        hashes.append((name, digest(path) if path.is_file() else 'DELETED'))
    dependencies = {name: digest(ROOT / name) for name in ('vendor/composer/installed.json', 'docs/generated/tmp/browser-tools/package-lock.json', 'docs/generated/tmp/wp-cli-modern/vendor/composer/installed.json')}
    return dict(commit=git(ROOT, 'rev-parse', 'HEAD'), dependencies=dependencies,
                source_sha256=hashlib.sha256(json.dumps(hashes).encode()).hexdigest(),
                policy_sha256=digest(POLICY))


def fetch_json(url):
    request = urllib.request.Request(url, headers={'User-Agent': 'Cybermaps-release-validation'})
    with urllib.request.urlopen(request, timeout=30) as response:
        require(response.status == 200, 'Upstream metadata unavailable: ' + url)
        return json.load(response)


def upstream():
    checker = fetch_json('https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=plugin-check')
    require(checker.get('version') == policy()['plugin_check_version'],
            'Plugin Check pin is stale. Review and update the pin before releasing: ' + str(checker.get('version')))
    core = fetch_json('https://api.wordpress.org/core/version-check/1.7/')
    latest = core['offers'][0]['version']
    require(all(p.isdigit() for p in latest.split('.')) and tuple(map(int, latest.split('.'))) >= (7, 1),
            'Invalid latest stable WordPress version: ' + latest)
    return dict(plugin_check_version=checker['version'], wordpress_version=latest, checked_at=now())


@contextmanager
def gate_lock():
    temporary = configure()
    with (temporary / 'release-gate.lock').open('a') as stream:
        try:
            fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise RuntimeError('Another candidate validation/promotion is running') from error
        yield


def candidate():
    return release_dir() / 'candidate' / ('cybermaps_' + version() + '.zip')


def load_record():
    path = release_dir() / 'validation.json'
    require(path.is_file(), 'Candidate validation is missing; run composer release:build')
    return json.loads(path.read_text())


def validate_record(record):
    require(record.get('state') == 'validation-passed', 'Candidate validation has not passed')
    require(record.get('identity') == identity(), 'Source/policy changed after validation')
    age = (datetime.now(timezone.utc) - datetime.fromisoformat(record['completed_at'])).total_seconds()
    require(0 <= age <= policy()['evidence_max_age_hours'] * 3600, 'Validation evidence expired')
    _, archive_hash = package_files(candidate())
    require(record['zip_sha256'] == archive_hash, 'Candidate ZIP changed after validation')
    expected = set(COMMANDS) | {'matrix', 'browser', 'xsl'}
    require(set(record['checks']) == expected and all(c['status'] == 'passed' for c in record['checks'].values()),
            'Required checks are missing or did not pass')
    for check in record['checks'].values():
        path = safe_path(ROOT / check['log'])
        require(path.is_relative_to(release_dir()) and digest(path) == check['sha256'], 'Validation log changed')
    for path, value in record['evidence'].items():
        target = safe_path(ROOT / path)
        require(target.is_relative_to(release_dir()) and digest(target) == value, 'Runtime evidence changed: ' + path)
    validate_runtime_evidence(record)
    return record


def validate_runtime_evidence(record):
    from validate_matrix import validate_result
    latest = record["upstream"]["wordpress_version"]
    cases = {(policy()["minimum_wordpress"], "8.2", False), (latest, "8.2", True)}
    cases.update((latest, php, False) for php in policy()["php_versions"])
    reports = {Path(path).name: ROOT / path for path in record["evidence"]
               if re.fullmatch(r"wp-[0-9.]+-php-[0-9.]+(?:-multisite)?\.json", Path(path).name)}
    expected = {f"wp-{wp}-php-{php}" + ("-multisite" if multi else "") + ".json": (wp, php, multi)
                for wp, php, multi in cases}
    require(set(reports) == set(expected), "Required runtime reports missing")
    for name, (wp, php, multi) in expected.items():
        result = json.loads(reports[name].read_text())
        validate_result(result, wp, php, multi, record["zip_sha256"], record["identity"]["commit"])
        require(result["plugin_check_version"] == policy()["plugin_check_version"], "Checker version mismatch")


def validate_review(record):
    path = release_dir() / 'agent-review.json'
    require(path.is_file(), 'Agent policy review is missing; see docs/dev/WORDPRESS-RELEASE-CONDITIONS.md')
    review = json.loads(path.read_text())
    require(set(review) == {'schema_version', 'identity', 'zip_sha256', 'reviewer', 'reviewed_at', 'sections'},
            'Invalid agent review fields')
    require(review['schema_version'] == 1 and review['identity'] == record['identity']
            and review['zip_sha256'] == record['zip_sha256'], 'Agent review is stale')
    require(isinstance(review['reviewer'], str) and review['reviewer'].strip(), 'Review author missing')
    age = (datetime.now(timezone.utc) - datetime.fromisoformat(review['reviewed_at'])).total_seconds()
    require(0 <= age <= policy()['evidence_max_age_hours'] * 3600, 'Agent review expired')
    require(set(review['sections']) == set(policy()['review_sections']), 'Agent review sections incomplete')
    for name, section in review['sections'].items():
        require(set(section) == {'status', 'rationale', 'evidence'} and section['status'] == 'passed'
                and isinstance(section['rationale'], str) and len(section['rationale'].strip()) >= 40
                and isinstance(section['evidence'], dict) and section['evidence'], 'Incomplete policy review: ' + name)
        for reference, expected in section['evidence'].items():
            target = safe_path(ROOT / reference)
            require(target.is_relative_to(ROOT) and digest(target) == expected, 'Review evidence changed: ' + reference)
    return review


def execute(record, name, command, directory, env=None):
    path = directory / (name + '.log')
    record['checks'][name] = dict(status='running', log=str(path.relative_to(ROOT)))
    save_json(release_dir() / 'validation.json', record)
    print('Validating ' + name + ': ' + str(path), flush=True)
    with path.open('w') as output:
        environment = dict(os.environ if env is None else env,
                           PLAYWRIGHT_BROWSERS_PATH=str(ROOT / 'docs/generated/tmp/playwright-browsers'))
        result = subprocess.run(command, cwd=ROOT, stdout=output, stderr=subprocess.STDOUT, env=environment)
    record['checks'][name].update(status='passed' if result.returncode == 0 else 'failed', sha256=digest(path))
    save_json(release_dir() / 'validation.json', record)
    require(result.returncode == 0, name + ' failed; see ' + str(path))


def retained_candidate(record):
    require(record.get('identity') == identity(), 'Retained candidate source/policy changed')
    _, archive_hash = package_files(candidate())
    require(record.get('zip_sha256') == archive_hash, 'Retained candidate ZIP changed')
    final = release_dir() / candidate().name
    _, final_hash = package_files(final)
    require(final_hash == archive_hash and final.read_bytes() == candidate().read_bytes(),
            'Retained final artifact differs from candidate')
    spec = importlib.util.spec_from_file_location('cybermaps_publisher', ROOT / 'bin/release-github.py')
    publisher = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(publisher)
    proof = publisher.verify_retry_candidate(candidate())
    require(proof['commit'] == record['identity']['commit'], 'Retained draft commit changed')
    return archive_hash, proof


def build():
    directory = release_dir()
    directory.mkdir(parents=True, exist_ok=True)
    # Never overwrite an already published version's package or historical evidence.
    previous = directory / 'validation.json'
    if previous.exists():
        try:
            valid = validate_record(load_record())
            fresh = upstream()
            require(fresh['wordpress_version'] == valid['upstream']['wordpress_version'], 'Runtime matrix changed')
            print('Reusing identical validated candidate; agent review is a separate condition.')
            return
        except (RuntimeError, ValueError, KeyError, OSError):
            pass
    tag = 'v' + version()
    tagged = git(ROOT, 'tag', '--list', tag) or git(
        ROOT, 'ls-remote', 'https://github.com/Alex9001/cybermaps.git', 'refs/tags/' + tag)
    retained_hash, retry = retained_candidate(load_record()) if tagged else (None, None)
    attempt = directory / 'attempts' / uuid.uuid4().hex
    attempt.mkdir(parents=True)
    disposable = ['release-readiness.json']
    if retry is None:
        disposable += ['cybermaps_' + version() + '.zip', 'cybermaps_' + version() + '.zip.sha256']
    for name in disposable:
        (directory / name).unlink(missing_ok=True)
    record = dict(schema_version=1, state='candidate', identity=identity(), started_at=now(), checks={}, evidence={})
    if retry is not None:
        record.update(zip_sha256=retained_hash, retained_draft=retry)
    save_json(previous, record)
    try:
        record['upstream'] = upstream()
        for name, command in COMMANDS.items():
            if name == 'package' and retry is not None:
                command = ['bash', 'bin/validate-release.sh', str(directory / 'cybermaps'), str(candidate())]
            execute(record, name, command, attempt)
        _, record['zip_sha256'] = package_files(candidate())
        require(retained_hash is None or record['zip_sha256'] == retained_hash, 'Retained ZIP changed during revalidation')
        env = dict(os.environ, CYBERMAPS_LATEST_WP=record['upstream']['wordpress_version'])
        execute(record, 'matrix', ['python3', '-B', 'bin/validate_matrix.py', str(candidate()), str(attempt)], attempt, env)
        execute(record, 'xsl', ['python3', '-B', 'tests/integration/sitemap_xsl.py'], attempt)
        # The browser uses the same disposable runtime through the matrix runner.
        browser_evidence = attempt / 'browser.json'
        require(browser_evidence.is_file(), 'Browser evidence missing')
        execute(record, 'browser', ['python3', '-B', 'bin/validate_matrix.py', '--verify-browser', str(browser_evidence)], attempt)
        require(record['identity'] == identity(), 'Source changed during validation')
        _, final_hash = package_files(candidate())
        require(final_hash == record['zip_sha256'], 'ZIP changed between validation stages')
        for path in attempt.rglob('*'):
            if path.is_file():
                record['evidence'][str(path.relative_to(ROOT))] = digest(path)
        record.update(state='validation-passed', completed_at=now())
    except BaseException as error:
        record.update(state='failed', error=str(error), completed_at=now())
        raise
    finally:
        save_json(previous, record)
        save_json(attempt / 'validation.json', record)
    print('Candidate validated; publication and website deployment are pending. Complete the agent review and website preparation before composer release:ready.')


def ready():
    record = validate_record(load_record())
    validate_review(record)
    require(not git(ROOT, 'status', '--porcelain', '--untracked-files=all'), 'Commit and push source before readiness')
    require(git(ROOT, 'branch', '--show-current') == 'main', 'Readiness requires main')
    remote = git(ROOT, 'ls-remote', 'https://github.com/Alex9001/cybermaps.git', 'refs/heads/main').split()
    require(remote and remote[0] == record['identity']['commit'], 'Source must match pushed main')
    website, _ = load_config()
    website_release = json.loads((website / 'product/release.json').read_text())
    website_proof = prepared(website, record['identity']['commit'], website_release['channel'], version(),
                             policy()['evidence_max_age_hours'])
    fresh = upstream()
    require(fresh['wordpress_version'] == record['upstream']['wordpress_version'], 'New WordPress release requires validation')
    final = release_dir() / candidate().name
    for source, destination in ((candidate(), final), (Path(str(candidate()) + '.sha256'), Path(str(final) + '.sha256'))):
        if destination.exists():
            require(destination.read_bytes() == source.read_bytes(), 'Refusing to overwrite a different final artifact')
        else:
            pending = safe_path(destination.with_suffix(destination.suffix + '.pending'))
            shutil.copyfile(source, pending)
            pending.replace(destination)
    _, promoted_hash = package_files(final)
    require(promoted_hash == record['zip_sha256'], 'ZIP changed during promotion')
    save_json(release_dir() / 'release-readiness.json', dict(schema_version=1, state='release-ready',
              identity=record['identity'], zip_sha256=record['zip_sha256'], ready_at=now(), website=website_proof,
              validation_sha256=digest(release_dir() / 'validation.json'),
              review_sha256=digest(release_dir() / 'agent-review.json')))
    print('Release ready: ' + str(final))


def status():
    blockers = []
    workflow_path = release_dir() / 'workflow.json'
    workflow = json.loads(workflow_path.read_text()) if workflow_path.exists() else {}
    live_path = release_dir() / 'website-live.json'
    if workflow.get('release_id'):
        live = json.loads(live_path.read_text()) if live_path.exists() else {}
        complete = (workflow.get('phase') == 'complete' and live.get('state') == 'website-verified'
                    and workflow.get('website_live_sha256') == (digest(live_path) if live_path.exists() else None)
                    and all(live.get(k) == workflow.get(k) for k in ('tag', 'commit', 'channel', 'zip_sha256', 'website_commit')))
        print(json.dumps(dict(state='release-complete' if complete else 'published-website-pending',
              tag=workflow.get('tag'), website_verified_at=live.get('checked_at') if complete else None,
              blockers=[] if complete else ['Finish website deployment/verification with composer release:resume -- --tag ' + workflow['tag']]), indent=2))
        return not complete
    state = 'candidate-pending'
    try:
        record = validate_record(load_record())
        state = 'candidate-validated'
        validate_review(record)
        website, _ = load_config()
        website_release = json.loads((website / 'product/release.json').read_text())
        proof = prepared(website, record['identity']['commit'], website_release['channel'], version(),
                         policy()['evidence_max_age_hours'])
        if git(ROOT, 'status', '--porcelain', '--untracked-files=all'):
            blockers.append('Source must be committed and pushed before promotion')
        receipt = release_dir() / 'release-readiness.json'
        if not receipt.exists():
            blockers.append('Final promotion pending: composer release:ready')
        else:
            _, final_hash = package_files(release_dir() / candidate().name)
            require(final_hash == record['zip_sha256'], 'Final ZIP changed after promotion')
            value = json.loads(receipt.read_text())
            require(value['identity'] == record['identity'] and value['zip_sha256'] == record['zip_sha256']
                    and value['validation_sha256'] == digest(release_dir() / 'validation.json')
                    and value['review_sha256'] == digest(release_dir() / 'agent-review.json')
                    and value.get('website') == proof, 'Readiness record is stale')
    except (RuntimeError, ValueError, KeyError, OSError) as error:
        blockers.append(str(error))
    print(json.dumps(dict(state=state if blockers else 'release-ready', blockers=blockers,
                         pending=['Publication and live website verification'] if not blockers else []), indent=2))
    return bool(blockers)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['build', 'validate', 'ready', 'status'])
    args = parser.parse_args()
    os.chdir(ROOT)
    configure()
    if args.action == 'status':
        return status()
    with gate_lock():
        if args.action in ('build', 'validate'):
            build()
        else:
            ready()
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (RuntimeError, OSError, ValueError, KeyError, subprocess.CalledProcessError) as error:
        raise SystemExit('Release blocked: ' + str(error)) from error
