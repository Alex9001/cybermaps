#!/usr/bin/env python3
"""Verify immutable everyday-workload evidence; never manufacture passing samples."""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import uuid

ROOT = Path(__file__).resolve().parents[2]
RELEASES = ROOT / 'docs/generated/releases'
POLICY_PATH = ROOT / 'docs/dev/release-policy.json'
_baseline = json.loads(POLICY_PATH.read_text())['upgrade_baseline']
BASELINE_VERSION = _baseline['version']
BASELINE_SHA256 = _baseline['zip_sha256']
SIZES = [1000, 10000]
spec = importlib.util.spec_from_file_location('cybermaps_performance_evidence', Path(__file__).with_name('run.py'))
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)
OPERATIONS = harness.DEFAULT_OPERATIONS.split(',')
HTTP_OPERATIONS = {'sitemap_index', 'sitemap_early', 'sitemap_deep', 'llms', 'llms_full', 'llms_tldr', 'rag', 'search_hit', 'search_miss'}
RUNTIME_SOURCES = ('run.py', 'operation.php', 'runner.php', 'instrument.php', 'router.php', 'limits.c')


def require(condition, message):
    if not condition:
        raise ValueError(message)


def read_json(path):
    return json.loads(Path(path).read_text())


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def timestamp(value):
    require(isinstance(value, str), 'Evidence timestamp missing')
    parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
    require(parsed.tzinfo is not None, 'Evidence timestamp must include a timezone')
    return parsed


def fresh(value, max_age_hours):
    age = (datetime.now(timezone.utc) - timestamp(value)).total_seconds()
    require(0 <= age <= max_age_hours * 3600, 'Everyday-workload evidence is stale or future-dated')


def evidence_directory(path):
    path = Path(path).resolve(strict=True)
    require(path.is_dir() and path.is_relative_to(RELEASES.resolve()), 'Evidence must be under docs/generated/releases')
    return path


def files_manifest(directory):
    result = {}
    for path in sorted(directory.rglob('*')):
        require(not path.is_symlink(), 'Evidence symlinks are not accepted')
        if path.is_file():
            result[str(path.relative_to(directory))] = digest(path)
    require(bool(result), 'Evidence directory is empty')
    return result


def validate_static_setup(directory, stem):
    setup = read_json(directory / (stem + '-setup.json'))
    require(isinstance(setup.get('resets'), list) and bool(setup['resets']), 'Independent static reset evidence missing')
    require(setup['resets'][-1].get('success') is True, 'Prior static files were not fully purged')
    harness.Runtime.verify_worker_exit(setup.get('verified_processes', ''))


def cpu_set(value):
    result = set()
    require(isinstance(value, str) and re.fullmatch(r'[0-9,-]+', value) is not None, 'Malformed CPU affinity')
    for part in value.split(','):
        bounds = part.split('-')
        require(len(bounds) <= 2, 'Malformed CPU range')
        first, last = int(bounds[0]), int(bounds[-1])
        require(0 <= first <= last and last - first < 6, 'CPU affinity exceeds six cores')
        result.update(range(first, last + 1))
    require(0 < len(result) <= 6, 'CPU affinity exceeds six cores')
    return result


def validate_limits(directory, environment):
    limits = environment.get('limits', {})
    expected = {'php_request_memory_mib': 1024, 'storage_stop_gib': 90, 'db_address_space_gib': 6,
                'redis_address_space_gib': 1, 'php_process_address_space_gib': 1,
                'max_simultaneous_php_processes': 3, 'sql_helper_address_space_mib': 256}
    require(all(type(limits.get(key)) is int and limits[key] == value for key, value in expected.items()),
            'Declared resource limits differ from the controlled profile')
    cpus = cpu_set(limits.get('verified_cpu_affinity'))
    observations = read_json(directory / 'verified-process-limits.json')
    require(isinstance(observations, dict) and len(observations) == 4 and 'cli' in observations,
            'Verified process-limit observations incomplete')
    roles = {}
    for name, observation in observations.items():
        role = name.rsplit('-', 1)[-1]
        require(role in ('db', 'http', 'redis', 'cli') and role not in roles, 'Process-limit role is missing or duplicated')
        roles[role] = observation
        match = re.search(r'^Cpus_allowed_list:\s*([0-9,-]+)\s*$', observation.get('status', ''), re.MULTILINE)
        require(match is not None and cpu_set(match.group(1)) == cpus, 'Observed CPU affinity differs from profile')
        expected_bytes = (6 if role == 'db' else 1) * 1024**3
        match = re.search(r'^Max address space\s+(\d+)\s+(\d+)\s+bytes\s*$', observation.get('limits', ''), re.MULTILINE)
        require(match is not None and int(match.group(1)) == expected_bytes == int(match.group(2)),
                'Observed process address-space limit differs from profile')


def within_deadline(operation, sample, args):
    if operation in HTTP_OPERATIONS:
        return sample['seconds'] <= args['timeout']
    if operation == 'static_sync':
        slices = sample['result'].get('slices', [])
        return (sample['operation_seconds'] <= args['static_timeout'] and len(slices) <= args['static_slices']
                and all(item['operation_seconds'] <= args['long_timeout'] for item in slices))
    if operation == 'sitemaps' and any(item['seconds'] > args['timeout'] for item in sample['result']['responses']):
        return False
    return sample['operation_seconds'] <= args['long_timeout']


def validate_run(directory, expected_version, expected_sha256, *, candidate, max_age_hours):
    directory = evidence_directory(directory)
    environment = read_json(directory / 'environment.json')
    completion = read_json(directory / 'completion.json')
    resources = read_json(directory / 'resource-observations.json')
    require(completion.get('schema') == 2 and completion.get('executed') is True, 'Workload collection did not finish')
    require(completion.get('zip_sha256') == expected_sha256 == environment.get('zip_sha256'), 'Workload ZIP digest mismatch')
    require(completion.get('package_version') == expected_version == environment.get('package_version'), 'Measured package version mismatch')
    require(not completion.get('error') and not completion.get('watchdog_error'), 'Workload collection ended with an error')
    if candidate:
        require(completion.get('complete') is True, 'Candidate workloads did not all complete')
    fresh(completion.get('completed_at'), max_age_hours)
    require(timestamp(environment.get('started_at')) <= timestamp(completion['completed_at']), 'Workload timing is reversed')
    require(not resources.get('watchdog_error') and not resources.get('stop_reason') and resources.get('storage_exceeded') is False,
            'Workload resource/watchdog limit failed')
    require(type(resources.get('samples')) is int and resources['samples'] > 0, 'Resource observations missing')
    require(type(resources.get('peak_combined_rss_bytes')) is int and 0 < resources['peak_combined_rss_bytes'] <= 12 * 1024**3,
            'Measured resident memory is missing or exceeds the limit')
    validate_limits(directory, environment)
    for kind in ('lock', 'queue'):
        require(read_json(directory / (kind + '-contention.json')).get('passed') is True, kind + ' contention check failed')
    args = environment.get('args', {})
    require(args.get('sizes') == SIZES and args.get('database') == 'mariadb' and not args.get('lock_only')
            and not args.get('stress_page_lists'), 'Everyday fixtures must be the standard 1k/10k corpus')
    require(args.get('operations') == ','.join(OPERATIONS), 'Everyday operation inventory is incomplete')
    require(args.get('cold') == 3 and args.get('warm') == 20, 'Everyday HTTP sampling must retain 3 cold and 20 warm measurements')
    require(type(args.get('timeout')) is int and 0 < args['timeout'] <= 90, 'HTTP timeout exceeds the 90-second comparison limit')
    require(type(args.get('long_timeout')) is int and 0 < args['long_timeout'] <= 120, 'Report/slice deadline exceeds 120 seconds')
    require(type(args.get('static_timeout')) is int and 0 < args['static_timeout'] <= 600,
            'Full static synchronization deadline exceeds 600 seconds')
    require(type(args.get('static_slices')) is int and 0 < args['static_slices'] <= 50, 'Static continuation limit exceeds 50 requests')
    versions = json.loads(environment['versions'])
    require(versions.get('plugin') == expected_version and versions.get('wp') == args.get('wordpress_version'), 'Runtime version mismatch')
    for name in RUNTIME_SOURCES:
        require(environment.get('harness_sha256', {}).get(name) == digest(Path(__file__).with_name(name)),
                'Measured harness differs from the reviewed release harness: ' + name)
    failures = []
    deadline_failures = []
    for size in SIZES:
        fixture = read_json(directory / f'fixture-{size}-False.json')
        require(fixture.get('primary_count') == size and fixture.get('page_list_stress') is False, 'Fixture size mismatch')
        require(fixture.get('sql_sha256') == digest(directory / f'fixture-{size}-False.sql'), 'Seed SQL changed after measurement')
        for redis in ('off', 'on'):
            for cache in ('on', 'off'):
                for operation in OPERATIONS:
                    stem = f'{size}-redis-{redis}-plugin-{cache}-{operation}'
                    samples = read_json(directory / (stem + '.json'))
                    count = args['cold'] + args['warm'] + 1 if operation in HTTP_OPERATIONS else 1
                    require(isinstance(samples, list) and len(samples) == count, 'Requested sample accounting mismatch: ' + stem)
                    if operation in HTTP_OPERATIONS:
                        labels = [(sample.get('state'), sample.get('warmup')) for sample in samples]
                        require(labels == [('cold', False)] * 3 + [('warm', True)] + [('warm', False)] * 20,
                                'Cold/warm sample accounting mismatch: ' + stem)
                    else:
                        require(samples[0].get('operation') == operation, 'Operation identity mismatch: ' + stem)
                    validity = [harness.valid_sample(sample) for sample in samples]
                    require(all(type(sample.get('valid')) is bool and sample['valid'] == valid for sample, valid in zip(samples, validity)),
                            'Recorded sample validity disagrees with raw evidence: ' + stem)
                    if not all(validity):
                        failures.append(stem)
                    if candidate:
                        require(all(validity), 'Candidate requested work did not complete: ' + stem)
                    if any(valid and not within_deadline(operation, sample, args) for sample, valid in zip(samples, validity)):
                        deadline_failures.append(stem)
                        require(not candidate, 'Candidate operation exceeded its completion deadline: ' + stem)
                    for sample, valid in zip(samples, validity):
                        if valid:
                            require(sample['plugin'] == expected_version and sample['wordpress'] == versions['wp']
                                    and sample['php'] == versions['php'], 'Measured request runtime identity changed: ' + stem)
                    if operation == 'static_sync' and all(validity):
                        result = samples[0]['result']
                        require(result.get('independent_publication') is True and isinstance(result.get('slices'), list)
                                and bool(result['slices']), 'Full independent static completion evidence missing: ' + stem)
                        require(read_json(directory / (stem + '-continuations.json')) == result['slices'], 'Static continuation evidence mismatch')
                        validate_static_setup(directory, stem)
                    if operation in ('audit', 'links') and all(validity):
                        result = samples[0]['result']
                        require(result['analysis'].get('resource_count') == size * 7 // 10, 'Report/graph omitted fixture resources: ' + stem)
                        if operation == 'audit':
                            require(result['analysis'].get('text_complete') is True
                                    and str(result.get('resource_count')) == str(size * 7 // 10), 'Report text/resource analysis incomplete: ' + stem)
                    if operation == 'sitemaps' and all(validity):
                        result = samples[0]['result']
                        expected = ['/sitemap.xml'] + sorted({harness.urlsplit(entry['loc']).path for entry in fixture['inventory']['entries']})
                        require(result['expected_paths'] == expected, 'Complete sitemap inventory differs from fixture: ' + stem)
                        locations = result['responses'][0].get('sitemap_locations', [])
                        require(sorted(harness.urlsplit(url).path for url in locations) == expected[1:], 'Sitemap index did not advertise every measured child')
    require(completion.get('complete') == (not failures), 'Completion flag disagrees with raw requested results')
    return {'directory': str(directory.relative_to(ROOT)), 'package_version': expected_version,
            'zip_sha256': expected_sha256, 'completed_at': completion['completed_at'],
            'failed_cases': sorted(set(failures + deadline_failures)), 'deadline_failed_cases': deadline_failures,
            'files': files_manifest(directory)}, environment


def validate_pair(candidate, baseline, zip_sha256, version, max_age_hours):
    candidate_record, candidate_env = validate_run(candidate, version, zip_sha256, candidate=True, max_age_hours=max_age_hours)
    baseline_record, baseline_env = validate_run(baseline, BASELINE_VERSION, BASELINE_SHA256, candidate=False, max_age_hours=max_age_hours)
    require(Path(candidate).resolve() != Path(baseline).resolve(), 'Candidate and baseline evidence must be separate')
    for name in RUNTIME_SOURCES + ('limits',):
        require(candidate_env['harness_sha256'].get(name) == baseline_env['harness_sha256'].get(name)
                and bool(candidate_env['harness_sha256'].get(name)), 'Measured runtime harness differs: ' + name)
    for key in ('image_roles', 'hardware', 'limits', 'cold_definition', 'cache_definition'):
        require(candidate_env.get(key) == baseline_env.get(key) and bool(candidate_env.get(key)), 'Controlled environment mismatch: ' + key)
    for key in ('sizes', 'wordpress_version', 'database', 'cold', 'warm', 'timeout', 'long_timeout', 'static_timeout', 'static_slices', 'operations'):
        require(candidate_env['args'].get(key) == baseline_env['args'].get(key), 'Controlled workload mismatch: ' + key)
    for size in SIZES:
        require(read_json(Path(candidate) / f'fixture-{size}-False.json')['sql_sha256']
                == read_json(Path(baseline) / f'fixture-{size}-False.json')['sql_sha256'], 'Candidate and baseline corpus differ')
    return candidate_record, baseline_record


def validate_receipt(path, zip_sha256, commit, max_age_hours=24):
    """Re-read every raw evidence hash and outcome; raise ValueError on failure."""
    receipt = read_json(path)
    require(receipt.get('schema') == 1 and receipt.get('passed') is True, 'Everyday-workload receipt is not passing')
    require(receipt.get('zip_sha256') == zip_sha256 and receipt.get('commit') == commit, 'Everyday-workload artifact/commit mismatch')
    require(re.fullmatch(r'[a-f0-9]{40}', commit) is not None, 'A full source commit is required')
    fresh(receipt.get('created_at'), max_age_hours)
    records = validate_pair(ROOT / receipt['candidate']['directory'], ROOT / receipt['baseline']['directory'],
                            zip_sha256, receipt['version'], max_age_hours)
    require(records == (receipt['candidate'], receipt['baseline']), 'Everyday-workload evidence changed after review')
    return receipt


def write_receipt(output, receipt):
    """Archive every old/new attempt, then atomically select the new receipt."""
    output = Path(output)
    attempts = output.parent / 'audit/performance/receipts'
    attempts.mkdir(parents=True, exist_ok=True)
    record = dict(receipt, attempt_id=uuid.uuid4().hex)
    if output.exists():
        previous = output.read_bytes()
        previous_hash = hashlib.sha256(previous).hexdigest()
        preserved = attempts / ('superseded-' + previous_hash + '.json')
        if preserved.exists():
            require(preserved.read_bytes() == previous, 'Superseded receipt archive changed')
        else:
            with preserved.open('xb') as handle:
                handle.write(previous)
        record['supersedes'] = {'path': str(preserved.relative_to(ROOT)), 'sha256': previous_hash}
    encoded = (json.dumps(record, indent=2) + '\n').encode()
    immutable = attempts / (record['attempt_id'] + '.json')
    with immutable.open('xb') as handle:
        handle.write(encoded)
    temporary = output.with_name(output.name + '.' + record['attempt_id'] + '.tmp')
    with temporary.open('xb') as handle:
        handle.write(encoded)
    temporary.replace(output)
    return record


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--version', required=True)
    parser.add_argument('--commit', required=True)
    parser.add_argument('--zip', type=Path, required=True)
    parser.add_argument('--candidate', type=Path, required=True)
    parser.add_argument('--baseline', type=Path, required=True)
    args = parser.parse_args()
    require(re.fullmatch(r'\d+\.\d+\.\d+', args.version) is not None, 'Release version required')
    require(re.fullmatch(r'[a-f0-9]{40}', args.commit) is not None, 'Full source commit required')
    require(harness.package_version(args.zip) == args.version, 'Candidate ZIP version mismatch')
    zip_sha256 = digest(args.zip)
    output = RELEASES / args.version / 'everyday-workloads.json'
    if output.exists():
        try:
            previous = validate_receipt(output, zip_sha256, args.commit)
            if (ROOT / previous['candidate']['directory']).resolve() == args.candidate.resolve() and (ROOT / previous['baseline']['directory']).resolve() == args.baseline.resolve():
                print(output)
                return
        except (ValueError, KeyError, OSError):
            # Failed/stale receipts never authorize publication. Only fresh raw
            # evidence below permits replacement; previous bytes are preserved.
            pass
    candidate, baseline = validate_pair(args.candidate, args.baseline, zip_sha256, args.version, 24)
    receipt = {'schema': 1, 'version': args.version, 'commit': args.commit, 'zip_sha256': zip_sha256,
               'created_at': datetime.now(timezone.utc).isoformat(), 'passed': True,
               'candidate': candidate, 'baseline': baseline,
               'qualification': 'Controlled 1k/10k completion only; historical 100k/1m limits remain. Baseline failures are retained, not relabeled.'}
    write_receipt(output, receipt)
    validate_receipt(output, zip_sha256, args.commit)
    print(output)


if __name__ == '__main__':
    main()
