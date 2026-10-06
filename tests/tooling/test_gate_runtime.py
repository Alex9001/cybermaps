from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'bin'))
from validate_matrix import validate_result
from release_gate import validate_runtime_evidence


class RuntimeBindingTest(unittest.TestCase):
    def test_missing_runtime_reports_cannot_be_replaced_by_a_passed_log(self):
        with self.assertRaisesRegex(RuntimeError, 'Required runtime reports missing'):
            validate_runtime_evidence({'upstream': {'wordpress_version': '7.1.2'}, 'evidence': {}})

    def test_runtime_evidence_must_describe_the_same_candidate(self):
        result = dict(wordpress_version='7.1.2', php_version='8.2.33',
                      zip_sha256='frozen', commit='commit', plugin_check_environment='single-site',
                      wp_debug_clean=True, lifecycle_passed=True, multisite=False,
                      mcp_read_only_passed=True, mcp_adapter_version='0.7.0',
                      stable_findings=[], experimental_findings=[])
        validate_result(result, '7.1.2', '8.2', False, 'frozen', 'commit')
        for key, value in [('zip_sha256', 'other'), ('commit', 'other'), ('lifecycle_passed', False),
                           ('multisite', True), ('mcp_read_only_passed', False), ('mcp_adapter_version', '0.6.0'), ('experimental_findings', [{'warning': 'new'}])]:
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                validate_result({**result, key: value}, '7.1.2', '8.2', False, 'frozen', 'commit')


if __name__ == '__main__':
    unittest.main()
