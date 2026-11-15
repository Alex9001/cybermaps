"""Everyday release evidence must be complete, immutable, and comparable."""
from datetime import datetime, timedelta, timezone
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('workload_release', Path(__file__).with_name('check_release.py'))
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class ReleaseEvidenceTests(unittest.TestCase):
    def setUp(self):
        gate.RELEASES.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(prefix='perf-evidence-test-', dir=gate.RELEASES)
        self.root = Path(self.temporary.name)
        self.sha = 'a' * 64
        self.commit = 'b' * 40
        self.now = datetime.now(timezone.utc).isoformat()
        self.candidate = self.make_run('candidate', '8.1.0', self.sha)
        self.baseline = self.make_run('baseline', gate.BASELINE_VERSION, gate.BASELINE_SHA256)

    def tearDown(self):
        self.temporary.cleanup()

    def write(self, path, value):
        path.write_text(json.dumps(value))

    def cli(self, operation, version):
        result = {'status': 'complete', 'analysis': {'complete': True}, 'count': 100}
        if operation == 'queue':
            result = {'enqueue': {'accepted': 1000}, 'claimed': 1000, 'acknowledged': 1000}
        if operation == 'static_sync':
            result = {'report': {'status': 'complete', 'success': True}, 'state': []}
        return {'queries': 1, 'query_seconds': .01, 'peak_memory_bytes': 1024, 'php_seconds': .1,
                'operation_seconds': .1, 'operation': operation, 'plugin': version,
                'wordpress': '7.1.4', 'php': '8.2.29', 'result': result, 'valid': True}

    def http(self, version):
        return {'queries': 1, 'query_seconds': .01, 'peak_memory_bytes': 1024, 'php_seconds': .1,
                'plugin': version, 'wordpress': '7.1.4', 'php': '8.2.29', 'state': 'cold',
                'warmup': False, 'status': 200, 'body_valid': True, 'seconds': .1, 'bytes': 100, 'valid': True}

    def make_run(self, label, version, sha):
        directory = self.root / label
        directory.mkdir()
        args = {'sizes': gate.SIZES, 'database': 'mariadb', 'lock_only': False, 'stress_page_lists': 0,
                'operations': ','.join(gate.OPERATIONS), 'cold': 3, 'warm': 20, 'timeout': 90,
                'long_timeout': 120, 'static_timeout': 600, 'static_slices': 50, 'wordpress_version': '7.1.4'}
        environment = {'started_at': self.now, 'package_version': version, 'zip_sha256': sha,
                       'args': args, 'versions': json.dumps({'plugin': version, 'wp': '7.1.4', 'php': '8.2.29'}),
                       'harness_sha256': {**{name: gate.digest(Path(__file__).with_name(name))
                           for name in gate.RUNTIME_SOURCES}, 'limits': 'e' * 64},
                       'image_roles': {'php': '1' * 64, 'db': '2' * 64, 'redis': '3' * 64},
                       'hardware': {'system': 'Linux'}, 'limits': {'php_request_memory_mib': 1024, 'storage_stop_gib': 90,
                           'db_address_space_gib': 6, 'redis_address_space_gib': 1, 'php_process_address_space_gib': 1,
                           'max_simultaneous_php_processes': 3, 'sql_helper_address_space_mib': 256, 'verified_cpu_affinity': '0,1,2,3,4,5'},
                       'cold_definition': 'application cache', 'cache_definition': 'actual plugin and Redis'}
        self.write(directory / 'environment.json', environment)
        self.write(directory / 'completion.json', {'schema': 2, 'executed': True, 'complete': True,
                   'package_version': version, 'zip_sha256': sha, 'completed_at': self.now})
        self.write(directory / 'resource-observations.json', {'storage_exceeded': False, 'samples': 10,
                   'peak_combined_rss_bytes': 10000})
        self.write(directory / 'verified-process-limits.json', {
            role: {'status': 'Cpus_allowed_list: 0-5\n', 'limits': f'Max address space {limit} {limit} bytes\n'}
            for role, limit in [('db', 6 * 1024**3), ('http', 1024**3), ('redis', 1024**3), ('cli', 1024**3)]})
        for kind in ('lock', 'queue'):
            self.write(directory / (kind + '-contention.json'), {'passed': True})
        for size in gate.SIZES:
            sql = directory / f'fixture-{size}-False.sql'
            sql.write_text('synthetic unittest evidence only')
            self.write(directory / f'fixture-{size}-False.json', {'primary_count': size,
                       'page_list_stress': False, 'sql_sha256': gate.digest(sql),
                       'inventory': {'entries': [{'loc': 'http://fixture/post-1.xml'}]}})
            for redis in ('off', 'on'):
                for cache in ('on', 'off'):
                    for operation in gate.OPERATIONS:
                        stem = f'{size}-redis-{redis}-plugin-{cache}-{operation}'
                        if operation in gate.HTTP_OPERATIONS:
                            labels = [('cold', False)] * 3 + [('warm', True)] + [('warm', False)] * 20
                            samples = [self.http(version) | {'state': state, 'warmup': warmup} for state, warmup in labels]
                        else:
                            samples = [self.cli(operation, version)]
                        if operation in ('audit', 'links'):
                            samples[0]['result']['analysis'] |= {'resource_count': size * 7 // 10, 'text_complete': True}
                            samples[0]['result']['resource_count'] = str(size * 7 // 10)
                        if operation == 'static_sync':
                            slices = [self.cli(operation, version)]
                            samples[0]['result'] |= {'slices': slices, 'independent_publication': True}
                            self.write(directory / (stem + '-continuations.json'), slices)
                            self.write(directory / (stem + '-setup.json'), {'resets': [{'success': True}],
                                       'verified_processes': 'PID STATE ARGS\n1 S php /performance/router.php\n'})
                        if operation == 'sitemaps':
                            paths = ['/sitemap.xml', '/post-1.xml']
                            samples[0]['result'] = {'expected_paths': paths,
                                                   'responses': [self.http(version) | {'path': path} for path in paths]}
                            samples[0]['result']['responses'][0]['sitemap_locations'] = ['http://fixture/post-1.xml']
                        self.write(directory / (stem + '.json'), samples)
        return directory

    def receipt(self):
        candidate, baseline = gate.validate_pair(self.candidate, self.baseline, self.sha, '8.1.0', 24)
        result = {'schema': 1, 'passed': True, 'zip_sha256': self.sha, 'commit': self.commit, 'version': '8.1.0',
                  'created_at': self.now, 'candidate': candidate, 'baseline': baseline}
        path = self.root / 'receipt.json'
        self.write(path, result)
        return path

    def test_valid_receipt_rechecks_all_raw_files(self):
        path = self.receipt()
        self.assertTrue(gate.validate_receipt(path, self.sha, self.commit)['passed'])
        (self.candidate / 'late-evidence.json').write_text('{}')
        with self.assertRaisesRegex(ValueError, 'changed after review'):
            gate.validate_receipt(path, self.sha, self.commit)

    def test_baseline_failure_is_retained_without_failing_passing_candidate(self):
        sample_path = self.baseline / '10000-redis-off-plugin-on-audit.json'
        samples = gate.read_json(sample_path)
        samples[0] |= {'error': 'operation_timeout', 'valid': False}
        self.write(sample_path, samples)
        completion_path = self.baseline / 'completion.json'
        self.write(completion_path, gate.read_json(completion_path) | {'complete': False})
        receipt = gate.validate_receipt(self.receipt(), self.sha, self.commit)
        self.assertEqual(['10000-redis-off-plugin-on-audit'], receipt['baseline']['failed_cases'])

    def test_forged_complete_flag_cannot_hide_candidate_failure(self):
        sample_path = self.candidate / '10000-redis-off-plugin-on-audit.json'
        samples = gate.read_json(sample_path)
        samples[0] |= {'error': 'operation_timeout', 'valid': True}
        self.write(sample_path, samples)
        with self.assertRaisesRegex(ValueError, 'validity disagrees'):
            self.receipt()

    def test_weaker_deadline_and_different_images_cannot_pass(self):
        environment = self.candidate / 'environment.json'
        original = gate.read_json(environment)
        relaxed = gate.read_json(environment)
        relaxed['args']['long_timeout'] = 600
        self.write(environment, relaxed)
        with self.assertRaisesRegex(ValueError, 'deadline exceeds'):
            self.receipt()
        original['image_roles']['php'] = '4' * 64
        self.write(environment, original)
        with self.assertRaisesRegex(ValueError, 'environment mismatch'):
            self.receipt()

    def test_stale_or_missing_samples_never_pass(self):
        completion = self.candidate / 'completion.json'
        original = gate.read_json(completion)
        self.write(completion, original | {'completed_at': (datetime.now(timezone.utc) - timedelta(hours=25)).isoformat()})
        with self.assertRaisesRegex(ValueError, 'stale'):
            self.receipt()
        self.write(completion, original)
        (self.candidate / '1000-redis-on-plugin-off-audit.json').unlink()
        with self.assertRaises(FileNotFoundError):
            self.receipt()

    def test_wrong_commit_or_package_receipt_fails(self):
        path = self.receipt()
        for sha, commit in [('c' * 64, self.commit), (self.sha, 'd' * 40)]:
            with self.assertRaisesRegex(ValueError, 'artifact/commit mismatch'):
                gate.validate_receipt(path, sha, commit)

    def test_whole_sitemap_deadline_is_enforced_and_baseline_overrun_retained(self):
        filename = '1000-redis-off-plugin-on-sitemaps.json'
        for directory in (self.baseline, self.candidate):
            path = directory / filename
            samples = gate.read_json(path)
            samples[0]['operation_seconds'] = 121
            self.write(path, samples)
            if directory == self.baseline:
                receipt = gate.validate_receipt(self.receipt(), self.sha, self.commit)
                self.assertEqual([filename[:-5]], receipt['baseline']['deadline_failed_cases'])
            else:
                with self.assertRaisesRegex(ValueError, 'completion deadline'):
                    self.receipt()

    def test_receipt_refresh_preserves_old_bytes_and_immutable_new_attempt(self):
        path = self.receipt()
        previous = path.read_bytes()
        record = gate.write_receipt(path, gate.read_json(path))
        archive = gate.ROOT / record['supersedes']['path']
        self.assertEqual(previous, archive.read_bytes())
        self.assertEqual(hashlib.sha256(previous).hexdigest(), record['supersedes']['sha256'])
        attempt = path.parent / 'audit/performance/receipts' / (record['attempt_id'] + '.json')
        self.assertEqual(path.read_bytes(), attempt.read_bytes())
        self.assertTrue(gate.validate_receipt(path, self.sha, self.commit)['passed'])


if __name__ == '__main__':
    unittest.main()
