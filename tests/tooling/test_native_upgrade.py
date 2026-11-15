"""Receipt checks must reject missing, changed, or simulated production evidence."""
import copy
from datetime import datetime, timedelta, timezone
import hashlib
import io
import importlib.util
import json
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from contextlib import redirect_stdout
from unittest import mock
import zipfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('native_upgrade', ROOT / 'tests/integration/native-upgrade.py')
upgrade = importlib.util.module_from_spec(spec)
spec.loader.exec_module(upgrade)


class NativeUpgradeReceiptTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(dir=ROOT / 'docs/generated/tmp')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.release = self.root / 'docs/generated/releases/8.1.0'
        self.release.mkdir(parents=True)
        self.archive = self.release / 'cybermaps_8.1.0.zip'
        with zipfile.ZipFile(self.archive, 'w') as archive:
            for name in ('cybermaps.php', 'uninstall.php', 'readme.txt', 'changelog.txt', 'LICENSE'):
                entry = zipfile.ZipInfo('cybermaps/' + name)
                entry.external_attr = (stat.S_IFREG | 0o644) << 16
                archive.writestr(entry, '<?php\n/**\n * Version: 8.1.0\n */\n' if name == 'cybermaps.php' else name)
        self.sha = upgrade.digest(self.archive)
        Path(str(self.archive) + '.sha256').write_text(self.sha + '  ' + self.archive.name + '\n')
        self.files, _, _ = upgrade.package_contract(self.archive)
        self.baseline = {'version': '8.0.4', 'zip_sha256': 'b63cb6f54f800d758cfe49e2056bb82bfc4e122121c46c98c19ed7f6bab9e7f9'}
        self.committed = {path: (ROOT / path).read_bytes() for path in upgrade.HARNESS_FILES}
        self.committed['docs/dev/release-policy.json'] = json.dumps({'upgrade_baseline': self.baseline}).encode()
        self.harness = {path: hashlib.sha256(body).hexdigest() for path, body in self.committed.items()}
        self.patch_committed = mock.patch.object(upgrade, 'committed_bytes', lambda commit, relative: self.committed[relative])
        self.patch_committed.start()
        self.addCleanup(self.patch_committed.stop)
        self.patch_source = mock.patch.object(upgrade, 'source_manifest', lambda commit: dict(self.files))
        self.patch_source.start()
        self.addCleanup(self.patch_source.stop)
        self.path = self.release / 'native-upgrade-controlled.json'
        self.receipt = {
            'schema_version': 1, 'passed': True, 'phase': 'controlled', 'version': '8.1.0',
            'baseline_version': '8.0.4', 'baseline_zip_sha256': self.baseline['zip_sha256'],
            'commit': 'a' * 40, 'zip_sha256': self.sha, 'archive': str(self.archive.relative_to(self.root)),
            'harness': self.harness, 'package_manifest_sha256': upgrade.file_manifest_sha256(self.files),
            'wordpress_version': '7.1.4', 'php_version': '8.2',
            'validated_at': datetime.now(timezone.utc).isoformat(),
            'package_checks': {'basename_stable': True, 'update_uri_absent': True, 'custom_updater_hooks_absent': True},
            'cases': [], 'evidence': {},
        }
        for method, multisite in sorted(upgrade.METHODS):
            before = self.state('8.0.4', multisite)
            after = self.state('8.1.0', multisite)
            data = {
                'before': before, 'after': after,
                'offer': {'checked': '8.0.4', 'controlled_offer_installed': True, 'controlled_download_installed': True, 'offer': {
                    'slug': 'cybermaps', 'plugin': 'cybermaps/cybermaps.php', 'new_version': '8.1.0',
                    'package': 'https://downloads.wordpress.org/plugin/cybermaps.8.1.0.zip'}},
                'installed': {'matches_candidate': True, 'zip_sha256': self.sha, 'files': self.files},
            }
            if method == 'dashboard':
                data['browser'] = {'passed': True, 'errors': [], 'expected_version': '8.1.0',
                                   'browser': 'fixture-browser', 'installed_text': 'Version 8.1.0'}
            else:
                data['wp_cli'] = [{'name': 'cybermaps', 'old_version': '8.0.4', 'new_version': '8.1.0', 'status': 'Updated'}]
            folder = self.release / (method + str(multisite))
            folder.mkdir()
            paths = {}
            for key, value in data.items():
                file = folder / (key + '.json')
                upgrade.write_json(file, value)
                paths[key] = str(file.relative_to(self.root))
                self.receipt['evidence'][paths[key]] = upgrade.digest(file)
            self.receipt['cases'].append({'method': method, 'multisite': multisite, 'passed': True, 'evidence': paths,
                                         'local_candidate_mounted': True, 'custom_router': False})
        self.patch_root = mock.patch.object(upgrade, 'ROOT', self.root)
        self.patch_generated = mock.patch.object(upgrade, 'generated', lambda relative='': self.root / 'docs/generated' / relative)
        self.patch_root.start()
        self.patch_generated.start()
        self.addCleanup(self.patch_root.stop)
        self.addCleanup(self.patch_generated.stop)

    def state(self, version, multisite):
        site = {
            'options': {name: {'fixture': True} for name in upgrade.ROOT_OPTIONS},
            'cron': {'100000': {'cybermaps_fixture': {'event': {}}}},
            'rewrites': {'^native-upgrade-foreign/?$': 'index.php?native_upgrade_foreign=1'},
            'tables': {'wp_' + name: {'columns': [{'Field': 'id'}], 'row_count': 1, 'rows_sha256': 'a' * 64}
                       for name in ('cybermaps_audit_runs', 'cybermaps_audit_resources', 'cybermaps_audit_findings')},
            'owned_files': {} if multisite else {'ai.json': {'recorded_md5': 'a' * 32, 'sha256': 'b' * 64}},
            'foreign_file_sha256': 'c' * 64, 'static_mode': 'off' if multisite else 'well_known',
            'sitemap_index_sha256': 'd' * 64, 'sitemap_index_children': 1,
        }
        return {
            'version': version, 'loaded_version': version, 'basename': 'cybermaps/cybermaps.php', 'update_uri': '',
            'active': True, 'network_active': multisite, 'multisite': multisite,
            'sites': {str(index): copy.deepcopy(site) for index in range(1, 3 if multisite else 2)},
            'wordpress_version': '7.1.4', 'php_version': '8.2.30', 'controlled_offer_installed': True, 'controlled_download_installed': True,
        }

    def validate(self):
        upgrade.write_json(self.path, self.receipt)
        return upgrade.validate_receipt(self.path, self.sha, 'a' * 40)

    def change_evidence(self, case, key, transform, rehash=True):
        relative = self.receipt['cases'][case]['evidence'][key]
        file = self.root / relative
        data = json.loads(file.read_text())
        transform(data)
        upgrade.write_json(file, data)
        if rehash:
            self.receipt['evidence'][relative] = upgrade.digest(file)

    def test_complete_granular_receipt_passes(self):
        self.assertTrue(self.validate()['passed'])

    def test_missing_activation_scope_fails(self):
        self.receipt['cases'].pop()
        with self.assertRaisesRegex(RuntimeError, 'activation scopes'):
            self.validate()

    def test_changed_evidence_fails_even_with_passed_flags(self):
        self.change_evidence(0, 'after', lambda data: data.update(active=False), rehash=False)
        with self.assertRaisesRegex(RuntimeError, 'evidence changed'):
            self.validate()

    def test_data_loss_fails_even_with_refreshed_evidence_hash(self):
        self.change_evidence(0, 'after', lambda data: data['sites']['1']['options'].clear())
        with self.assertRaisesRegex(RuntimeError, 'changed options'):
            self.validate()

    def test_production_phase_cannot_use_controlled_offer(self):
        self.freeze_controlled()
        self.path = self.release / 'native-upgrade-wordpress.json'
        self.receipt['phase'] = 'wordpress'
        _, self.receipt['controlled_publication'] = upgrade.frozen_controlled_receipt(self.release, self.sha, 'a' * 40)
        with self.assertRaisesRegex(RuntimeError, 'Unexpected offer'):
            self.validate()

    def test_installed_file_difference_fails(self):
        self.change_evidence(0, 'installed', lambda data: data['files'].update({'cybermaps.php': '0' * 64}))
        with self.assertRaisesRegex(RuntimeError, 'file parity'):
            self.validate()

    def test_wp_cli_skipped_update_cannot_pass(self):
        self.change_evidence(0, 'wp_cli', lambda data: data[0].update(status='Skipped'))
        with self.assertRaisesRegex(RuntimeError, 'WP-CLI update evidence'):
            self.validate()

    def test_external_download_url_fails(self):
        self.change_evidence(0, 'offer', lambda data: data['offer'].update(package='https://example.com/cybermaps.zip'))
        with self.assertRaisesRegex(RuntimeError, 'WordPress.org URL'):
            self.validate()

    def test_expired_evidence_fails(self):
        self.receipt['validated_at'] = (datetime.now(timezone.utc) - timedelta(hours=25)).isoformat()
        with self.assertRaisesRegex(RuntimeError, 'expired'):
            self.validate()

    def test_frozen_policy_controls_the_upgrade_baseline(self):
        self.baseline['version'] = '8.1.0'
        self.committed['docs/dev/release-policy.json'] = json.dumps({'upgrade_baseline': self.baseline}).encode()
        with self.assertRaisesRegex(RuntimeError, 'baseline differs'):
            self.validate()

    def test_executed_harness_hash_cannot_be_advanced_without_frozen_source(self):
        self.receipt['harness'] = {**self.harness, 'tests/browser/native-upgrade.mjs': '0' * 64}
        with self.assertRaisesRegex(RuntimeError, 'harness is not bound'):
            self.validate()

    def test_actual_working_tree_harness_must_match_frozen_source(self):
        for relative, contents in self.committed.items():
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(contents)
        self.assertEqual(upgrade.verify_harness('a' * 40), self.harness)
        (self.root / 'tests/browser/native-upgrade.mjs').write_text('changed browser fixture')
        with self.assertRaisesRegex(RuntimeError, 'differs from the frozen commit'):
            upgrade.verify_harness('a' * 40)

    def http_fixture(self, offer=None, status=200):
        directory = self.release / 'http-observations'
        directory.mkdir(exist_ok=True)
        request = directory / 'request.json'
        response = directory / 'response.json'
        upgrade.write_json(request, {'plugins': json.dumps({'plugins': {'cybermaps/cybermaps.php': {'Version': '8.0.4'}}})})
        upgrade.write_json(response, {'plugins': {'cybermaps/cybermaps.php': offer} if offer else {}})
        record = {'context': 'response', 'kind': 'update-check', 'sslverify': True, 'transport': 'Requests',
                  'url': 'https://api.wordpress.org/plugins/update-check/1.1/', 'method': 'POST', 'response_code': status,
                  'error': None, 'request_file': request.name, 'request_sha256': upgrade.digest(request),
                  'response_file': response.name, 'response_sha256': upgrade.digest(response)}
        upgrade.write_json(directory / 'api-observation.json', record)
        return directory

    def test_only_successful_official_no_offer_is_pending(self):
        directory = self.http_fixture()
        with self.assertRaises(upgrade.OfferUnavailable):
            upgrade.require_offer({'offer': None}, 'wordpress', '8.1.0', directory, '8.0.4')
        with self.assertRaises(RuntimeError) as failure:
            upgrade.require_offer({'offer': None}, 'controlled', '8.1.0', directory, '8.0.4')
        self.assertNotIsInstance(failure.exception, upgrade.OfferUnavailable)

    def test_failed_http_request_is_not_distribution_pending(self):
        directory = self.http_fixture(status=503)
        with self.assertRaises(RuntimeError) as failure:
            upgrade.require_offer({'offer': None}, 'wordpress', '8.1.0', directory, '8.0.4')
        self.assertNotIsInstance(failure.exception, upgrade.OfferUnavailable)

    def test_missing_transport_proof_is_not_distribution_pending(self):
        with self.assertRaises(RuntimeError) as failure:
            upgrade.require_offer({'offer': None}, 'wordpress', '8.1.0', self.release / 'missing', '8.0.4')
        self.assertNotIsInstance(failure.exception, upgrade.OfferUnavailable)

    def test_official_offer_requires_real_download_bytes(self):
        offer = {'new_version': '8.1.0', 'package': 'https://downloads.wordpress.org/plugin/cybermaps.8.1.0.zip'}
        directory = self.http_fixture(offer)
        with self.assertRaisesRegex(RuntimeError, 'No real WordPress.org package download'):
            upgrade.verify_transport(directory, '8.1.0', '8.0.4', self.files)
        actual = directory / 'download.zip'
        actual.write_bytes(self.archive.read_bytes())
        record = {'context': 'response', 'kind': 'download', 'sslverify': True, 'transport': 'Requests',
                  'url': offer['package'], 'method': 'GET', 'response_code': 200, 'error': None,
                  'zip_file': actual.name, 'zip_sha256': upgrade.digest(actual)}
        upgrade.write_json(directory / 'download-observation.json', record)
        result = upgrade.verify_transport(directory, '8.1.0', '8.0.4', self.files)
        self.assertEqual(result['package_manifest_sha256'], upgrade.file_manifest_sha256(self.files))
        self.assertEqual(len(result['downloads']), 1)
        with self.assertRaisesRegex(RuntimeError, 'file bytes differ'):
            upgrade.verify_transport(directory, '8.1.0', '8.0.4', {**self.files, 'cybermaps.php': '0' * 64})

    def test_real_phase_never_mounts_candidate_or_all_release_history(self):
        for relative, contents in self.committed.items():
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(contents)
        folder = self.root / 'runtime'
        folder.mkdir()
        output = self.release / 'runtime-case'
        output.mkdir()
        with mock.patch.object(upgrade.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, 'Docker', '')):
            runtime = upgrade.Runtime(folder, self.archive, '7.1.4', '8.2', 'wordpress', '8.1.0', output, self.baseline, self.harness)
        self.assertIsNone(runtime.candidate)
        self.assertFalse((runtime.fixtures / 'native-upgrade-controlled.php').exists())
        with mock.patch.object(runtime, 'container', return_value=subprocess.CompletedProcess([], 0, 'ok', '')) as command:
            runtime.cli('plugin', 'list')
        arguments = list(map(str, command.call_args.args))
        self.assertFalse(any('/candidate' in item or '/history' in item for item in arguments))
        self.assertTrue(any(item.endswith(':/baseline/cybermaps.zip:ro') for item in arguments))

    def freeze_controlled(self):
        self.validate()
        files = dict(self.receipt['evidence'])
        files[str(self.path.relative_to(self.root))] = upgrade.digest(self.path)
        proof = {'identity': {'commit': 'a' * 40}, 'zip_sha256': self.sha, 'files': files}
        payload = json.dumps(proof, sort_keys=True, separators=(',', ':')).encode()
        sha = hashlib.sha256(payload).hexdigest()
        folder = self.release / 'wordpress-evidence' / sha
        (folder / 'blobs').mkdir(parents=True)
        for relative, expected in files.items():
            (folder / 'blobs' / expected).write_bytes((self.root / relative).read_bytes())
        (folder / 'manifest.json').write_bytes(payload)
        publication = dict(schema_version=1, state='svn-committed', svn_revision=321, commit='a' * 40,
                           zip_sha256=self.sha, publication_evidence={**proof, 'snapshot': str(folder.relative_to(self.root)), 'snapshot_sha256': sha})
        upgrade.write_json(self.release / 'wordpress-publication.json', publication)
        return publication

    def test_failure_cannot_replace_a_passing_canonical_receipt(self):
        self.validate()
        previous = self.path.read_bytes()
        with self.assertRaisesRegex(RuntimeError, 'Failed attempts'):
            upgrade.atomic_receipt(self.path, {**self.receipt, 'passed': False})
        self.assertEqual(self.path.read_bytes(), previous)

    def test_failed_runtime_attempts_are_retained_without_overwriting_canonical_success(self):
        self.validate()
        previous = self.path.read_bytes()
        lock_bytes = self.committed['tests/cli/composer.lock']
        for relative in ('tests/cli/composer.lock', 'docs/generated/tmp/wp-cli-modern/composer.lock'):
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(lock_bytes)
        baseline = self.root / 'docs/generated/releases/8.0.4/cybermaps_8.0.4.zip'
        baseline.parent.mkdir()
        baseline.write_text('isolated baseline test fixture')
        actual_digest = upgrade.digest
        actual_contract = upgrade.package_contract
        runtime = mock.Mock()
        runtime.start.side_effect = RuntimeError('simulated native command failure')
        argv = ['native-upgrade.py', '--zip', str(self.archive), '--phase', 'controlled', '--output', str(self.path),
                '--wordpress', '7.1.4', '--commit', 'a' * 40]
        with mock.patch.object(upgrade, 'verify_harness', return_value=self.harness), \
                mock.patch.object(upgrade, 'Runtime', return_value=runtime), \
                mock.patch.object(upgrade, 'digest', side_effect=lambda path: self.baseline['zip_sha256'] if Path(path) == baseline else actual_digest(path)), \
                mock.patch.object(upgrade, 'package_contract', side_effect=lambda path: (self.files, self.baseline['zip_sha256'], '8.0.4') if Path(path) == baseline else actual_contract(path)), \
                mock.patch('sys.argv', argv), redirect_stdout(io.StringIO()):
            for _ in range(2):
                with self.assertRaisesRegex(RuntimeError, 'simulated native command failure'):
                    upgrade.run_upgrade()
        attempts = list(self.release.glob('native-upgrade-controlled-*/receipt.json'))
        self.assertEqual(len(attempts), 2)
        self.assertTrue(all(json.loads(path.read_text())['passed'] is False for path in attempts))
        self.assertTrue(all(json.loads(path.read_text())['failure_kind'] == 'native-check-failed' for path in attempts))
        self.assertEqual(self.path.read_bytes(), previous)

    def test_controlled_receipt_frozen_for_every_unsafe_publication_state(self):
        self.validate()
        previous = self.path.read_bytes()
        for state in ('svn-prepared', 'svn-committing', 'svn-outcome-unknown', 'svn-committed', 'unknown'):
            upgrade.write_json(self.release / 'wordpress-publication.json', {'state': state})
            with self.subTest(state=state), self.assertRaisesRegex(RuntimeError, 'frozen by SVN'):
                upgrade.atomic_receipt(self.path, self.receipt)
            self.assertEqual(self.path.read_bytes(), previous)
        for state in ('inputs-frozen', 'svn-not-committed'):
            upgrade.write_json(self.release / 'wordpress-publication.json', {'state': state})
            upgrade.assert_receipt_writable(self.path, 'controlled')

    def test_verified_wordpress_receipt_cannot_be_replaced(self):
        target = self.release / 'native-upgrade-wordpress.json'
        target.write_text('original verified receipt')
        upgrade.write_json(self.release / 'wordpress-distribution.json', {'state': 'distribution-verified'})
        with self.assertRaisesRegex(RuntimeError, 'immutable'):
            upgrade.atomic_receipt(target, {**self.receipt, 'phase': 'wordpress'})
        self.assertEqual(target.read_text(), 'original verified receipt')

    def test_frozen_controlled_receipt_ignores_mutated_live_receipt_and_evidence(self):
        publication = self.freeze_controlled()
        self.path.write_text('{"passed":false}')
        relative = self.receipt['cases'][0]['evidence']['before']
        (self.root / relative).write_text('changed live evidence')
        controlled, binding = upgrade.frozen_controlled_receipt(self.release, self.sha, 'a' * 40)
        self.assertTrue(controlled['passed'])
        self.assertEqual(binding['snapshot_sha256'], publication['publication_evidence']['snapshot_sha256'])
        self.assertEqual(binding['controlled_receipt_sha256'], publication['publication_evidence']['files'][str(self.path.relative_to(self.root))])

    def test_frozen_controlled_receipt_detects_changed_snapshot_blob(self):
        publication = self.freeze_controlled()
        proof = publication['publication_evidence']
        relative = self.receipt['cases'][0]['evidence']['before']
        blob = self.root / proof['snapshot'] / 'blobs' / proof['files'][relative]
        blob.write_text('tampered frozen evidence')
        with self.assertRaisesRegex(RuntimeError, 'Frozen native update evidence changed'):
            upgrade.frozen_controlled_receipt(self.release, self.sha, 'a' * 40)

    def test_failed_incidental_check_for_updated_version_does_not_mask_baseline_proof(self):
        directory = self.http_fixture()
        record = json.loads((directory / 'api-observation.json').read_text())
        request = directory / 'updated-request.json'
        upgrade.write_json(request, {'plugins': json.dumps({'plugins': {'cybermaps/cybermaps.php': {'Version': '8.1.0'}}})})
        record.update(request_file=request.name, request_sha256=upgrade.digest(request), response_code=503)
        upgrade.write_json(directory / 'incidental-observation.json', record)
        checks = upgrade.valid_update_checks(upgrade.observations(directory), '8.0.4')
        self.assertEqual(len(checks), 1)

    def test_any_failed_tested_baseline_check_remains_a_hard_failure(self):
        directory = self.http_fixture()
        record = json.loads((directory / 'api-observation.json').read_text())
        record['response_code'] = 503
        upgrade.write_json(directory / 'failed-baseline-observation.json', record)
        with self.assertRaisesRegex(RuntimeError, 'baseline update-check request failed') as failure:
            upgrade.valid_update_checks(upgrade.observations(directory), '8.0.4')
        self.assertNotIsInstance(failure.exception, upgrade.OfferUnavailable)


if __name__ == '__main__':
    unittest.main()
