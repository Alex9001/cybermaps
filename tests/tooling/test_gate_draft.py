import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'bin'))
spec = importlib.util.spec_from_file_location('publisher', ROOT / 'bin/release-github.py')
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


class DraftIdentityTest(unittest.TestCase):
    def test_created_draft_retries_by_saved_id_without_listing_or_creation(self):
        record = json.dumps({'id': 123, 'tag': 'v7.5.4'})
        state = {'id': 123, 'tag_name': 'v7.5.4', 'draft': True}
        delayed = subprocess.CalledProcessError(1, ['gh'], output='HTTP 404')
        with patch.object(Path, 'exists', return_value=True), patch.object(Path, 'read_text', return_value=record), \
                patch.object(publisher, 'run', side_effect=[delayed, json.dumps(state)]) as run, \
                patch.object(publisher.time, 'sleep'):
            self.assertEqual(publisher.release_state('v7.5.4'), state)
            self.assertEqual(run.call_count, 2)
            for call in run.call_args_list:
                self.assertEqual(call.args[-1], 'repos/Alex9001/cybermaps/releases/123')

    def test_saved_id_cannot_resolve_to_another_tag(self):
        with patch.object(Path, 'exists', return_value=True), \
                patch.object(Path, 'read_text', return_value=json.dumps({'id': 123, 'tag': 'v7.5.4'})), \
                patch.object(publisher, 'run', return_value=json.dumps({'id': 123, 'tag_name': 'v7.5.3'})), \
                self.assertRaises(RuntimeError):
            publisher.release_state('v7.5.4')


if __name__ == '__main__':
    unittest.main()
