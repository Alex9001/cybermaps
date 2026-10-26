from pathlib import Path
import json
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'bin'))
from validate_matrix import validate_result, verify_priming_fixture, verify_sql_fixture, verify_output_buffer_fixture, verify_sitemap_ownership_fixture
from release_gate import validate_runtime_evidence


class RuntimeBindingTest(unittest.TestCase):
    def test_sitemap_ownership_requires_native_cases_and_all_http_states(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parents[2] / 'docs/generated/tmp') as temporary:
            path = Path(temporary) / 'sitemap-ownership.json'
            cases = {name: True for name in ('active_replacement', 'disabled_handover', 'foreign_filter',
                     'private_site', 'rewrite_handover', 'redirect_opt_out', 'plain_permalinks', 'static_handover')}
            result = dict(schema_version=1, sitemap_ownership_passed=True, multisite=False, cases=cases)
            for state in ('enabled', 'disabled', 'retained', 'plain', 'restored'):
                path.with_name('sitemap-ownership-' + state + '.json').write_text(json.dumps(
                    dict(schema_version=1, state=state, passed=True, checks=['fixture-check'])))
            path.write_text(json.dumps(result))
            verify_sitemap_ownership_fixture(path)
            for name in cases:
                path.write_text(json.dumps({**result, 'cases': {**cases, name: False}}))
                with self.subTest(name=name), self.assertRaisesRegex(RuntimeError, 'cases incomplete'):
                    verify_sitemap_ownership_fixture(path)
            path.write_text(json.dumps(result))
            path.with_name('sitemap-ownership-disabled.json').write_text(json.dumps(
                dict(schema_version=1, state='disabled', passed=False, checks=[])))
            with self.assertRaisesRegex(RuntimeError, 'HTTP coverage incomplete'):
                verify_sitemap_ownership_fixture(path)

    def test_missing_runtime_reports_cannot_be_replaced_by_a_passed_log(self):
        with self.assertRaisesRegex(RuntimeError, 'Required runtime reports missing'):
            validate_runtime_evidence({'upstream': {'wordpress_version': '7.1.2'}, 'evidence': {}})

    def test_runtime_evidence_must_describe_the_same_candidate(self):
        result = dict(wordpress_version='7.1.2', php_version='8.2.33',
                      zip_sha256='frozen', commit='commit', plugin_check_environment='single-site',
                      wp_debug_clean=True, lifecycle_passed=True, multisite=False,
                      mcp_read_only_passed=True, mcp_adapter_version='0.7.0', sitemap_regressions_passed=True,
                      rest_representation_passed=True,
                      publication_priming_passed=True,
                      configuration_persistence_passed=True, cloudflare_persistence_passed=True, audit_run_lock_passed=True,
                      static_ownership_passed=True, state_cutover_passed=True,
                      stored_template_source_passed=True, raw_option_store_passed=True,
                      output_buffer_ownership_passed=True, sitemap_ownership_passed=True,
                      stable_findings=[], experimental_findings=[])
        validate_result(result, '7.1.2', '8.2', False, 'frozen', 'commit')
        for key, value in [('zip_sha256', 'other'), ('commit', 'other'), ('lifecycle_passed', False),
                           ('multisite', True), ('sitemap_regressions_passed', False), ('rest_representation_passed', False), ('mcp_read_only_passed', False), ('mcp_adapter_version', '0.6.0'), ('experimental_findings', [{'warning': 'new'}])]:
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                validate_result({**result, key: value}, '7.1.2', '8.2', False, 'frozen', 'commit')

        incomplete = {key: value for key, value in result.items() if key != 'rest_representation_passed'}
        with self.assertRaisesRegex(RuntimeError, 'Native REST representation'):
            validate_result(incomplete, '7.1.2', '8.2', False, 'frozen', 'commit')

        for value in (None, False, 1):
            incomplete = dict(result)
            if value is None:
                del incomplete['publication_priming_passed']
            else:
                incomplete['publication_priming_passed'] = value
            with self.subTest(priming=value), self.assertRaisesRegex(RuntimeError, 'Native publication priming'):
                validate_result(incomplete, '7.1.2', '8.2', False, 'frozen', 'commit')

        for key in ('configuration_persistence', 'cloudflare_persistence', 'audit_run_lock', 'static_ownership', 'state_cutover', 'stored_template_source', 'raw_option_store', 'output_buffer_ownership', 'sitemap_ownership'):
            for value in (None, False, 1, 'true'):
                incomplete = dict(result)
                if value is None:
                    del incomplete[key + '_passed']
                else:
                    incomplete[key + '_passed'] = value
                with self.subTest(key=key, value=value), self.assertRaisesRegex(RuntimeError, 'Native ' + key):
                    validate_result(incomplete, '7.1.2', '8.2', False, 'frozen', 'commit')

    def test_output_buffer_fixture_cannot_pass_with_only_an_exit_or_aggregate_flag(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parents[2] / 'docs/generated/tmp') as temporary:
            path = Path(temporary) / 'buffer.json'
            for result in ({}, [], None, {'output_buffer_ownership_passed': True},
                           {'schema_version': 1, 'output_buffer_ownership_passed': True, 'cases': []},
                           {'schema_version': 1, 'output_buffer_ownership_passed': 'true'}):
                path.write_text(json.dumps(result))
                with self.subTest(result=result), self.assertRaisesRegex(RuntimeError, 'Native output_buffer_ownership'):
                    verify_output_buffer_fixture(path)

    def test_priming_fixture_requires_an_explicit_boolean_success(self):
        directory = Path(__file__).resolve().parents[2] / 'docs/generated/tmp'
        with tempfile.TemporaryDirectory(dir=directory) as temporary:
            report = Path(temporary) / 'priming.json'
            for result in ({}, [], {'publication_priming_passed': False}, {'publication_priming_passed': 1}):
                report.write_text(json.dumps(result))
                with self.subTest(result=result), self.assertRaisesRegex(RuntimeError, 'Native publication priming fixture'):
                    verify_priming_fixture(report)
            report.write_text('{')
            with self.assertRaises(json.JSONDecodeError):
                verify_priming_fixture(report)
            report.write_text(json.dumps({'publication_priming_passed': True}))
            verify_priming_fixture(report)


    def test_sql_fixtures_require_their_own_explicit_boolean_success(self):
        directory = Path(__file__).resolve().parents[2] / 'docs/generated/tmp'
        with tempfile.TemporaryDirectory(dir=directory) as temporary:
            report = Path(temporary) / 'sql-fixture.json'
            for name in ('stored_template_source', 'raw_option_store'):
                for result in ({}, [], None, {name + '_passed': False},
                               {name + '_passed': 1}, {name + '_passed': 'true'},
                               {'complete': True}, {'publication_priming_passed': True}):
                    report.write_text(json.dumps(result))
                    with self.subTest(name=name, result=result), self.assertRaisesRegex(RuntimeError, 'Native ' + name + ' fixture'):
                        verify_sql_fixture(report, name)
                report.write_text('{')
                with self.assertRaises(json.JSONDecodeError):
                    verify_sql_fixture(report, name)
                report.write_text(json.dumps({name + '_passed': True}))
                verify_sql_fixture(report, name)
            with self.assertRaisesRegex(RuntimeError, 'Unknown native SQL fixture'):
                verify_sql_fixture(report, 'unrecognized')


if __name__ == '__main__':
    unittest.main()
