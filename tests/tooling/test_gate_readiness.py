import copy
from datetime import datetime, timedelta, timezone
import importlib.util
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'bin'))
import release_gate as gate


class ReadinessTest(unittest.TestCase):
    def setUp(self):
        self.directory = ROOT / 'docs/generated/releases/7.5.4'
        self.identity = {'commit': 'commit', 'source_sha256': 'source', 'policy_sha256': 'policy'}
        self.record = dict(state='validation-passed', identity=self.identity,
                           completed_at=datetime.now(timezone.utc).isoformat(), zip_sha256='zip',
                           checks={name: {'status': 'passed', 'log': str((self.directory / (name + '.log')).relative_to(ROOT)),
                                          'sha256': 'hash'} for name in set(gate.COMMANDS) | {'matrix', 'browser', 'xsl'}},
                           evidence={'docs/generated/releases/7.5.4/browser.json': 'hash'})
        for target, replacement in [('identity', lambda: self.identity), ('release_dir', lambda: self.directory),
                                     ('package_files', lambda _: ({}, 'zip')), ('digest', lambda _: 'hash'), ('validate_runtime_evidence', lambda _: None)]:
            patcher = patch.object(gate, target, replacement)
            patcher.start()
            self.addCleanup(patcher.stop)

    def test_complete_current_validation_is_accepted(self):
        self.assertEqual(gate.validate_record(self.record), self.record)

    def test_missing_failed_stale_or_changed_evidence_blocks(self):
        mutations = [lambda r: r.update(state='failed'),
                     lambda r: r.update(identity={}),
                     lambda r: r.update(zip_sha256='changed'),
                     lambda r: r.update(completed_at=(datetime.now(timezone.utc) - timedelta(days=2)).isoformat()),
                     lambda r: r['checks'].pop('browser'),
                     lambda r: r['checks']['source'].update(status='failed'),
                     lambda r: r['checks']['source'].update(sha256='tampered'),
                     lambda r: r['evidence'].update({'docs/generated/releases/7.5.4/browser.json': 'tampered'})]
        for mutate in mutations:
            record = copy.deepcopy(self.record)
            mutate(record)
            with self.subTest(record=record), self.assertRaises(RuntimeError):
                gate.validate_record(record)

    def test_missing_agent_review_blocks_promotion(self):
        with patch.object(Path, 'is_file', return_value=False), self.assertRaises(RuntimeError):
            gate.validate_review(self.record)


if __name__ == '__main__':
    unittest.main()
