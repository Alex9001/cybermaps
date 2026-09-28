"""Bad output must never become a clean compliance report."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'bin'))
from plugin_check_result import parse


class ResultTest(unittest.TestCase):
    def test_explicit_success(self):
        for output in ('[]', 'Success: Checks complete. No errors found.\n'):
            self.assertEqual(parse(output, '', 0), [])

    def test_missing_unknown_partial_and_findings_fail(self):
        for output in ('', ' ', '{}', 'null', 'false', '{"warnings":["unsafe"]}',
                       '{"results":[]}', '[', '[]\nextra', '[{"type":"WARNING"}]',
                       'FILE: x.php\n[]', 'Success: Checks complete. No errors found.\n[]'):
            with self.subTest(output=output), self.assertRaises(ValueError):
                parse(output, '', 0)

    def test_status_and_stderr_cannot_be_hidden_by_success(self):
        for status, stderr in ((1, ''), (127, ''), (-9, ''), (0, 'warning')):
            with self.subTest(status=status, stderr=stderr), self.assertRaises(ValueError):
                parse('[]', stderr, status)


if __name__ == '__main__':
    unittest.main()
