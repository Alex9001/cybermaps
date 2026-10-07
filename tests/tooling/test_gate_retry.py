"""Revalidate retained bytes without invoking expensive runtimes or remote writes."""
from datetime import datetime, timedelta, timezone
import importlib.util
import json
from pathlib import Path
from types import SimpleNamespace
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'bin'))
import release_gate as gate
from workspace import configure

spec = importlib.util.spec_from_file_location('retry_publisher', ROOT / 'bin/release-github.py')
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


class CandidateRetryTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(dir=configure())
        self.addCleanup(temporary.cleanup)
        self.directory = Path(temporary.name)
        self.archive = self.directory / 'candidate/cybermaps_8.0.1.zip'
        self.archive.parent.mkdir()
        self.archive.write_bytes(b'frozen ZIP')
        (self.directory / self.archive.name).write_bytes(b'frozen ZIP')
        self.identity = dict(commit='commit', source_sha256='source', policy_sha256='policy')
        self.record = dict(state='validation-passed', identity=self.identity, zip_sha256='zip',
                           completed_at=(datetime.now(timezone.utc) - timedelta(days=2)).isoformat())
        (self.directory / 'validation.json').write_text(json.dumps(self.record))
        for target, value in [('release_dir', lambda: self.directory), ('candidate', lambda: self.archive),
                              ('identity', lambda: self.identity), ('version', lambda: '8.0.1'),
                              ('package_files', lambda _: ({}, 'zip')),
                              ('upstream', lambda: dict(wordpress_version='7.1.2')),
                              ('git', lambda *args: 'v8.0.1')]:
            mock = patch.object(gate, target, value)
            mock.start()
            self.addCleanup(mock.stop)

    def fake_execute(self, record, name, command, directory, env=None):
        record['checks'][name] = dict(status='passed')
        if name == 'matrix':
            (directory / 'browser.json').write_text('{}')

    def test_expired_gate_reruns_all_checks_without_rebuilding_or_deleting_frozen_zip(self):
        proof = dict(id=1, tag='v8.0.1', commit='commit')
        fake = SimpleNamespace(verify_retry_candidate=lambda _: proof)
        loader = SimpleNamespace(loader=SimpleNamespace(exec_module=lambda _: None))
        with patch.object(gate.importlib.util, 'spec_from_file_location', return_value=loader), \
                patch.object(gate.importlib.util, 'module_from_spec', return_value=fake), \
                patch.object(gate, 'execute', side_effect=self.fake_execute) as execute:
            gate.build()
        commands = {call.args[1]: call.args[2] for call in execute.call_args_list}
        self.assertEqual(set(commands), set(gate.COMMANDS) | {'matrix', 'browser', 'xsl'})
        self.assertEqual(commands['package'][1], 'bin/validate-release.sh')
        self.assertEqual(self.archive.read_bytes(), b'frozen ZIP')
        self.assertEqual((self.directory / self.archive.name).read_bytes(), b'frozen ZIP')
        self.assertEqual(json.loads((self.directory / 'validation.json').read_text())['state'], 'validation-passed')

    def test_changed_source_or_zip_cannot_enter_revalidation(self):
        for key in ('identity', 'zip_sha256'):
            record = {**self.record, key: 'changed'}
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                gate.retained_candidate(record)

    def test_changed_promoted_bytes_cannot_enter_revalidation(self):
        (self.directory / self.archive.name).write_bytes(b'different ZIP')
        with self.assertRaisesRegex(RuntimeError, 'final artifact differs'):
            gate.retained_candidate(self.record)


class RemoteRetryTest(unittest.TestCase):
    def test_published_or_conflicting_draft_is_refused_without_writes(self):
        draft = dict(id=1, draft=True, prerelease=True, target_commitish='commit',
                     name='Cybermaps 8.0.1 — Open beta', body='notes', assets=[])
        for changed in ({'draft': False}, {'target_commitish': 'other'}, {'body': 'other'},
                        {'assets': [dict(name='unexpected.zip')]}):
            with self.subTest(changed=changed), \
                    patch.object(publisher, 'source_commit', return_value='commit'), \
                    patch.object(publisher, 'verify_tag', return_value=(True, True)), \
                    patch.object(publisher, 'release_state', return_value={**draft, **changed}), \
                    patch.object(publisher, 'release_notes', return_value='notes'), \
                    patch.object(publisher, 'package_files', return_value=({}, 'zip')), \
                    patch.object(Path, 'read_bytes', return_value=b'frozen'), \
                    patch.object(publisher, 'run') as run, self.assertRaises(RuntimeError):
                publisher.verify_retry_candidate(Path('/fixture/cybermaps_8.0.1.zip'))
            run.assert_not_called()

    def test_existing_assets_are_downloaded_and_never_replaced(self):
        draft = dict(id=1, draft=True, prerelease=True, target_commitish='commit',
                     name='Cybermaps 8.0.1 — Open beta', body='notes',
                     assets=[dict(id=2, name='cybermaps_8.0.1.zip')])
        with patch.object(publisher, 'source_commit', return_value='commit'), \
                patch.object(publisher, 'verify_tag', return_value=(True, True)), \
                patch.object(publisher, 'release_state', return_value=draft), \
                patch.object(publisher, 'release_notes', return_value='notes'), \
                patch.object(publisher, 'package_files', return_value=({}, 'zip')), \
                patch.object(Path, 'read_bytes', return_value=b'frozen'), \
                patch.object(publisher, 'run') as run:
            self.assertEqual(publisher.verify_retry_candidate(Path('/fixture/cybermaps_8.0.1.zip'))['id'], 1)
        self.assertEqual(run.call_count, 1)
        self.assertEqual(run.call_args.args[1:3], ('release', 'download'))

    def test_corrupt_download_is_refused_without_asset_mutation(self):
        draft = dict(id=1, draft=True, prerelease=True, target_commitish='commit',
                     name='Cybermaps 8.0.1 — Open beta', body='notes',
                     assets=[dict(id=2, name='cybermaps_8.0.1.zip')])
        def read_bytes(path):
            return b'corrupt' if 'draft-revalidation-' in str(path) else b'frozen'
        with patch.object(publisher, 'source_commit', return_value='commit'), \
                patch.object(publisher, 'verify_tag', return_value=(True, True)), \
                patch.object(publisher, 'release_state', return_value=draft), \
                patch.object(publisher, 'release_notes', return_value='notes'), \
                patch.object(publisher, 'package_files', return_value=({}, 'zip')), \
                patch.object(Path, 'read_bytes', autospec=True, side_effect=read_bytes), \
                patch.object(publisher, 'run') as run, self.assertRaisesRegex(RuntimeError, 'asset mismatch'):
            publisher.verify_retry_candidate(Path('/fixture/cybermaps_8.0.1.zip'))
        self.assertEqual(run.call_count, 1)
        self.assertEqual(run.call_args.args[1:3], ('release', 'download'))


if __name__ == '__main__':
    unittest.main()
