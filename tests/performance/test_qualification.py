import importlib.util
import io
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'bin'))
spec = importlib.util.spec_from_file_location('qualify_workloads', ROOT / 'bin/qualify-workloads.py')
qualification = importlib.util.module_from_spec(spec)
spec.loader.exec_module(qualification)


class QualificationTest(unittest.TestCase):
    def test_semantic_version_labels_are_valid_harness_identifiers(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'docs/generated/tmp') as tmp:
            directory = Path(tmp) / '8.1.1'
            evidence = directory / 'audit/performance/audit-preflight-8-1-1-test'
            evidence.mkdir(parents=True)
            child = Mock(stdout=io.StringIO(str(evidence) + '\n'))
            child.wait.return_value = 0
            with patch.object(qualification.subprocess, 'Popen') as launch:
                launch.return_value.__enter__.return_value = child
                qualification.measure(directory, directory / 'candidate.zip', 'audit-preflight-8.1.1',
                                      '7.1.4', preflight=True)
            command = launch.call_args.args[0]
            self.assertEqual('audit-preflight-8-1-1', command[command.index('--label') + 1])
            self.assertEqual('8.1.1', command[command.index('--release-version') + 1])

    def test_failed_candidate_preflight_prevents_both_full_runs(self):
        verifier = Mock(BASELINE_VERSION='8.1.0', BASELINE_SHA256='baseline')
        verifier.digest.return_value = 'baseline'
        record = {'identity': {'commit': 'commit'}, 'zip_sha256': 'candidate',
                  'upstream': {'wordpress_version': '7.1.4'}}
        with tempfile.TemporaryDirectory(dir=ROOT / 'docs/generated/tmp') as tmp:
            directory = Path(tmp) / '8.1.1'
            with (patch.object(qualification, 'release_dir', return_value=directory),
                  patch.object(qualification, 'module', return_value=verifier),
                  patch.object(qualification, 'load_record', return_value=record),
                  patch.object(qualification, 'validate_record', return_value=record),
                  patch.object(qualification, 'measure', side_effect=RuntimeError('audit timed out')) as measure):
                with self.assertRaisesRegex(RuntimeError, 'audit timed out'):
                    qualification.qualify()
                measure.assert_called_once()
                self.assertTrue(measure.call_args.kwargs['preflight'])

    def test_preflight_can_run_before_matrix_validation(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'docs/generated/tmp') as tmp:
            directory = Path(tmp) / '8.1.1'
            archive = directory / 'candidate/cybermaps_8.1.1.zip'
            archive.parent.mkdir(parents=True)
            archive.touch()
            verifier = Mock()
            verifier.harness.package_version.return_value = '8.1.1'
            with (patch.object(qualification, 'release_dir', return_value=directory),
                  patch.object(qualification, 'module', return_value=verifier),
                  patch.object(qualification, 'upstream', return_value={'wordpress_version': '7.1.4'}),
                  patch.object(qualification, 'load_record') as record,
                  patch.object(qualification, 'audit_preflight') as probe):
                qualification.qualify(preflight_only=True)
                record.assert_not_called()
                probe.assert_called_once_with(directory, archive, '7.1.4', verifier)
