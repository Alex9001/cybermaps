"""Focused fail-closed tests; no containers or external services."""
import importlib.util
from pathlib import Path
import unittest
import json
import os
import subprocess
import tempfile
import sys
import threading
import io
import hashlib
from types import SimpleNamespace
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('performance_run', Path(__file__).with_name('run.py'))
run = importlib.util.module_from_spec(spec)
spec.loader.exec_module(run)

class EvidenceTests(unittest.TestCase):
    def sample(self):
        return dict(queries=3, query_seconds=.01, peak_memory_bytes=1000, php_seconds=.1,
                    plugin='8.0.1', wordpress='7.1', php='8.2', state='warm', status=200,
                    bytes=10, body_valid=True, seconds=.2)

    def test_missing_metrics_never_pass(self):
        sample = self.sample()
        self.assertTrue(run.valid_sample(sample))
        for key in ('queries', 'peak_memory_bytes', 'plugin'):
            partial = dict(sample)
            del partial[key]
            self.assertFalse(run.valid_sample(partial))

    def test_errors_never_pass(self):
        for extra in ({'error': 'timeout'}, {'fatal': {'type': 1}}, {'status': 404}, {'body_valid': False}, {'exit_code': 124}):
            self.assertFalse(run.valid_sample(self.sample() | extra))

    def test_partial_cli_never_passes(self):
        sample = self.sample()
        del sample['state']
        sample.update(operation='audit', operation_seconds=1, result={'status':'running'})
        self.assertFalse(run.valid_sample(sample))
        sample.update(operation='audit', result={'status':'complete', 'analysis':{'complete':False}})
        self.assertFalse(run.valid_sample(sample))
        sample.update(operation='links', result={'analysis':{'complete':False}})
        self.assertFalse(run.valid_sample(sample))
        sample.update(operation='static_sync', result={'report':{'status':'partial'}})
        self.assertFalse(run.valid_sample(sample))

    def test_invalid_scalar_metrics_and_nested_schemas(self):
        for key in ('queries', 'query_seconds', 'peak_memory_bytes', 'php_seconds', 'seconds'):
            for value in (None, float('nan'), float('inf'), -1, True, '1'):
                self.assertFalse(run.valid_sample(self.sample() | {key: value}), (key, value))
        for key in ('php', 'wordpress', 'plugin'):
            self.assertFalse(run.valid_sample(self.sample() | {key: None}))
        for operation, result in [('audit', {'status':'complete','analysis':None}), ('links', {'analysis':[]}), ('static_sync', {'report':'complete'})]:
            sample = self.sample()
            del sample['state']
            sample.update(operation=operation, operation_seconds=.1, result=result)
            self.assertFalse(run.valid_sample(sample))

    def test_successful_static_slice_requires_cleared_continuation_state(self):
        sample = self.sample()
        del sample['state']
        report = {'status':'complete', 'success':True}
        sample.update(operation='static_sync', operation_seconds=1, result={'report':report,'state':[]})
        self.assertTrue(run.valid_sample(sample))
        for state in ({'status':'pending','phase':'rag_chunks','cursor':{'batch_complete':False}}, None, 'complete', False):
            sample['result']['state'] = state
            self.assertFalse(run.valid_sample(sample))
        del sample['result']['state']
        self.assertFalse(run.valid_sample(sample))

    def test_fail_fast_records_skips_without_percentiles(self):
        run.TEMP.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix='perf-failfast-', dir=run.TEMP) as directory:
            runtime = object.__new__(run.Runtime)
            runtime.output = Path(directory)
            runtime.args = SimpleNamespace(operations='llms', cold=1, warm=4)
            runtime.operation = Mock()
            runtime.storage_check = Mock()
            runtime.persistent_cache = Mock()
            runtime.analyze_queries = Mock()
            runtime.read_http_metric = lambda sample: sample
            runtime.http_sample = Mock(side_effect=lambda path, identifier, operation: self.sample() | {'id':identifier,'status':503,'body_valid':False,'error':'unavailable'})
            fixture = {'inventory': {'entries':[{'provider_name':'post','provider_kind':'posts','page':1,'loc':'http://fixture/posts.xml'}], 'rag_id':1,
                'endpoints':{**{name:{'path':'/'+name+'.txt'} for name in ('llms','llms_full','llms_tldr')},'rest_search':{'namespace':'cybermaps/v1','route':'/search'}}}}
            runtime.measure(100, fixture)
            self.assertEqual(12, runtime.http_sample.call_count)  # Three failures for each of four cache modes.
            for path in runtime.output.glob('100-*.json'):
                samples = json.loads(path.read_text())
                self.assertEqual(6, len(samples))
                self.assertEqual(3, sum(s.get('skipped', False) for s in samples))
                self.assertTrue(all(s['valid'] is False for s in samples))
            self.assertTrue(all(row['p95_seconds'] is None and row['complete'] is False for row in json.loads((runtime.output/'summary.json').read_text())))

    def test_status_and_body_validated(self):
        self.assertIsNone(run.validate_http('sitemap_index', 200, b'<sitemapindex><sitemap/></sitemapindex>'))
        for status, body in ((404, b'not found'), (200, b'<html>error</html>'), (200, b'<sitemapindex/>'), (200, b'')):
            self.assertIsNotNone(run.validate_http('sitemap_index', status, body))
        self.assertIsNotNone(run.validate_http('search_hit', 200, b'{"total":0,"results":[]}'))
        self.assertIsNone(run.validate_http('search_miss', 200, b'{"total":0,"results":[]}'))

    def test_deep_route_is_advertised(self):
        fixture = {'entries': [{'provider_name':'post','provider_kind':'post_type','page':n,'loc':f'http://fixture/custom-{n}.xml'} for n in (4,1)],
                   'rag_id':123, 'endpoints': {name:{'path':f'/{name}.txt'} for name in ('llms','llms_full','llms_tldr')}}
        fixture['endpoints']['rest_search'] = {'namespace':'cybermaps/v1','route':'/search'}
        routes = run.discover_routes(fixture)
        self.assertEqual('/custom-1.xml', routes['sitemap_early'])
        self.assertEqual('/custom-4.xml', routes['sitemap_deep'])
        for entry in fixture['entries']:
            entry['provider_name'] = 'page'
        self.assertEqual('/custom-4.xml', run.discover_routes(fixture)['sitemap_deep'])

class WatchdogAndImageTests(unittest.TestCase):
    def test_frozen_package_preserves_original_bytes_after_source_replacement(self):
        run.TEMP.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix='perf-package-freeze-', dir=run.TEMP) as directory:
            runtime = object.__new__(run.Runtime)
            runtime.work = Path(directory)
            source = runtime.work / 'candidate.zip'
            source.write_bytes(b'original-package')
            runtime.args = SimpleNamespace(zip=source)
            expected = hashlib.sha256(source.read_bytes()).hexdigest()
            runtime.freeze_package(expected)
            source.write_bytes(b'replaced-source')
            runtime.verify_package()
            self.assertEqual(b'original-package', runtime.archive.read_bytes())
            self.assertEqual(0o444, runtime.archive.stat().st_mode & 0o777)

    def test_changed_source_before_freeze_is_rejected(self):
        run.TEMP.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix='perf-package-race-', dir=run.TEMP) as directory:
            runtime = object.__new__(run.Runtime)
            runtime.work = Path(directory)
            source = runtime.work / 'candidate.zip'
            source.write_bytes(b'replaced-before-copy')
            runtime.args = SimpleNamespace(zip=source)
            with self.assertRaisesRegex(RuntimeError, 'Frozen performance package changed'):
                runtime.freeze_package(hashlib.sha256(b'original-package').hexdigest())

    def test_main_rejects_staged_package_changes_and_preserves_failure_evidence(self):
        run.TEMP.mkdir(parents=True, exist_ok=True)
        for phase in ('setup', 'measure', 'all_valid'):
            with self.subTest(phase=phase), tempfile.TemporaryDirectory(prefix='perf-package-completion-', dir=run.TEMP) as directory:
                root = Path(directory)
                source = root / 'candidate.zip'
                source.write_bytes(b'original-package')
                instances = []
                class FakeRuntime(run.Runtime):
                    def __init__(self, args):
                        self.args = args
                        self.work = root / 'work'
                        self.output = root / 'evidence'
                        self.work.mkdir()
                        self.output.mkdir()
                        self.cleaned_up = False
                        instances.append(self)
                    def replace(self):
                        self.archive.chmod(0o644)
                        self.archive.write_bytes(b'replaced-staged-package')
                    def setup(self):
                        if phase == 'setup':
                            self.replace()
                    def lock_contention(self, queue=False):
                        pass
                    def seed(self, size, page_lists):
                        return {}
                    def measure(self, size, fixture):
                        if phase == 'measure':
                            self.replace()
                    def all_valid(self):
                        if phase == 'all_valid':
                            self.replace()
                        return True
                    def cleanup(self):
                        self.cleaned_up = True
                with patch.object(run, 'Runtime', FakeRuntime), patch.object(sys, 'argv', ['run.py', '--zip', str(source), '--label', 'frozen-proof', '--sizes', '1']), patch('builtins.print'):
                    with self.assertRaisesRegex(RuntimeError, 'Frozen performance package changed'):
                        run.main()
                completion = json.loads((root / 'evidence/completion.json').read_text())
                self.assertIs(completion['complete'], False)
                self.assertIn('Frozen performance package changed', completion['error'])
                self.assertEqual(hashlib.sha256(b'original-package').hexdigest(), completion['zip_sha256'])
                self.assertTrue(instances[0].cleaned_up)

    def test_watchdog_timeout_stops_runtime_and_fails_main(self):
        runtime = object.__new__(run.Runtime)
        runtime.watch_error = None
        runtime.watch_storage_loop = Mock(side_effect=subprocess.TimeoutExpired('du', 30))
        runtime.stop_owned_containers = Mock()
        runtime.watch_storage()
        runtime.stop_owned_containers.assert_called_once()
        self.assertIn('TimeoutExpired', runtime.watch_error)
        with self.assertRaises(RuntimeError):
            runtime.check_watchdog()

    def test_rss_handles_exit_race_but_rejects_missing_live_metrics(self):
        self.assertEqual(0, run.resident_bytes('State: Z (zombie)'))
        self.assertEqual(2048, run.resident_bytes('State: R (running)\nVmRSS: 2 kB'))
        for status in ('State: R (running)', 'VmRSS: bad kB'):
            with self.assertRaises(ValueError):
                run.resident_bytes(status)

    def test_namespace_rss_probe_rejects_missing_or_invalid_metrics(self):
        runtime = object.__new__(run.Runtime)
        runtime.env, runtime.watch_stop, runtime.started_containers, runtime.cpus = {}, threading.Event(), {'test'}, '0'
        runtime.rss_probe_retries, runtime.output = [], Path('/unused')
        for data in ({}, {'rss_bytes':None,'processes':1}, {'rss_bytes':-1,'processes':1}, {'rss_bytes':1,'processes':False}):
            with patch.object(run, 'write_json'), patch.object(run.subprocess, 'run', return_value=SimpleNamespace(returncode=0, stdout=json.dumps(data), stderr='')):
                with self.assertRaises(RuntimeError):
                    runtime.container_rss('test')
        with patch.object(run.subprocess, 'run', return_value=SimpleNamespace(returncode=0, stdout='{"rss_bytes":2048,"processes":2}')):
            self.assertEqual(2048, runtime.container_rss('test'))

    def test_complete_rss_scan_retry_preserves_failure_diagnostics(self):
        runtime = object.__new__(run.Runtime)
        runtime.env, runtime.watch_stop, runtime.started_containers, runtime.cpus = {}, threading.Event(), {'test'}, '0'
        runtime.rss_probe_retries, runtime.output = [], Path('/unused')
        failed = SimpleNamespace(returncode=125, stdout='', stderr='RSS incomplete /proc/3/status')
        complete = SimpleNamespace(returncode=0, stdout='{"rss_bytes":2048,"processes":2}')
        with patch.object(run, 'write_json') as save, patch.object(run.subprocess, 'run', side_effect=[failed, complete]):
            self.assertEqual(2048, runtime.container_rss('test'))
        self.assertEqual('RSS incomplete /proc/3/status', runtime.rss_probe_retries[0]['stderr'])
        save.assert_called_once()

    def test_malformed_workspace_size_is_rejected(self):
        runtime = object.__new__(run.Runtime)
        runtime.env, runtime.work = {}, run.TEMP
        with patch.object(run.subprocess, 'run', return_value=SimpleNamespace(returncode=0, stdout='invalid')):
            with self.assertRaises(RuntimeError):
                runtime.workspace_bytes()

    def test_audit_reset_requires_confirmed_worker_exit(self):
        runtime = object.__new__(run.Runtime)
        runtime.http = 'fixture-http'
        runtime.operation = Mock()
        for inventory in ('', 'PID STATE ARGS\n', 'PID STATE ARGS\n1 S php /performance/router.php\n2 R php /performance/runner.php audit\n'):
            runtime.command = Mock(return_value=SimpleNamespace(stdout=inventory))
            with self.assertRaises(RuntimeError):
                runtime.verify_worker_exit(inventory)
        runtime.operation.assert_not_called()

    def test_cleanup_joins_watchdog(self):
        run.TEMP.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix='perf-cleanup-', dir=run.TEMP) as directory:
            runtime = object.__new__(run.Runtime)
            runtime.output = runtime.work = Path(directory)
            runtime.watch_stop = threading.Event()
            runtime.watch_thread = Mock()
            runtime.watch_thread.is_alive.return_value = False
            runtime.peak_container_rss = runtime.resource_samples = 0
            runtime.resource_stop = runtime.watch_error = None
            runtime.storage_exceeded = False
            runtime.db, runtime.http, runtime.redis, runtime.identifier = 'test-db','test-http','test-redis','test'
            runtime.args, runtime.log = SimpleNamespace(keep=True), io.StringIO()
            runtime.command = Mock(return_value=SimpleNamespace(stdout='',stderr=''))
            runtime.cleanup()
            runtime.watch_thread.join.assert_called_once_with(timeout=35)
            self.assertTrue(runtime.watch_stop.is_set())

    def test_manifest_uses_immutable_image_ids(self):
        run.TEMP.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix='perf-image-pin-', dir=run.TEMP) as directory:
            path = Path(directory)/'manifest.json'
            ids = {'php':'a'*64,'db':'b'*64,'redis':'c'*64}
            path.write_text(json.dumps({'args':{'database':'mariadb'}, 'image_roles':ids}))
            runtime = object.__new__(run.Runtime)
            runtime.args = SimpleNamespace(database='mariadb', image_manifest=path)
            runtime.db_image = run.DB_IMAGE
            runtime.command = Mock(side_effect=lambda *args: SimpleNamespace(stdout=json.dumps([{'Id':args[-1]}])))
            runtime.pin_images()
            self.assertEqual(ids, runtime.image_roles)
            self.assertEqual(list(ids.values()), [call.args[-1] for call in runtime.command.call_args_list])


class ProcessLimitTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        run.TEMP.mkdir(parents=True, exist_ok=True)
        cls.directory = tempfile.TemporaryDirectory(prefix='perf-limits-test-', dir=run.TEMP)
        cls.binary = str(Path(cls.directory.name) / 'limits')
        cls.env = dict(os.environ, TMPDIR=cls.directory.name, TMP=cls.directory.name, TEMP=cls.directory.name)
        subprocess.run(['cc', '-static', '-O2', '-Wall', '-Wextra', '-Werror', str(Path(__file__).with_name('limits.c')), '-o', cls.binary], check=True, env=cls.env)

    @classmethod
    def tearDownClass(cls):
        cls.directory.cleanup()

    def test_child_has_real_affinity_and_address_space_caps(self):
        cpus = sorted(os.sched_getaffinity(0))[:6]
        code = 'import os,resource,json; print(json.dumps([sorted(os.sched_getaffinity(0)),list(resource.getrlimit(resource.RLIMIT_AS))]))'
        result = subprocess.run([self.binary, ','.join(map(str, cpus)), '1024', sys.executable, '-c', code], check=True, capture_output=True, text=True, env=self.env)
        self.assertEqual([cpus, [1024**3, 1024**3]], json.loads(result.stdout))

    def test_invalid_limits_rejected_before_child(self):
        for cpus, memory in [('0,1,2,3,4,5,6', '1024'), ('0', '0'), ('0', '6145')]:
            result = subprocess.run([self.binary, cpus, memory, '/bin/true'], capture_output=True, env=self.env)
            self.assertEqual(125, result.returncode)

    def test_real_proc_rss_probe(self):
        cpus = ','.join(map(str, sorted(os.sched_getaffinity(0))[:6]))
        result = subprocess.run([self.binary, cpus, '256', '--rss-probe'], capture_output=True, text=True, check=True, env=self.env)
        data = json.loads(result.stdout)
        self.assertGreater(data['rss_bytes'], 0)
        self.assertGreater(data['processes'], 0)


if __name__ == '__main__':
    unittest.main()
