#!/usr/bin/env python3
"""Run the exact candidate on the mandatory real-WordPress matrix."""
from concurrent.futures import ThreadPoolExecutor
import json
import os
from pathlib import Path
import subprocess
import sys
from workspace import ROOT, require, package_files, git


def verify_browser(path):
    result = json.loads(Path(path).read_text())
    require(result.get('schema_version') == 1 and result.get('passed') is True
            and set(result.get('checks', [])) == {'wizard', 'icons', 'attribution-save', 'tooltip', 'mcp-setup'}
            and result.get('errors') == [] and result.get('browser'), 'Browser validation incomplete')


def verify_priming_fixture(path):
    result = json.loads(Path(path).read_text())
    require(isinstance(result, dict) and result.get('publication_priming_passed') is True,
            'Native publication priming fixture incomplete')


def verify_sql_fixture(path, name):
    require(name in ('stored_template_source', 'raw_option_store'), 'Unknown native SQL fixture')
    result = json.loads(Path(path).read_text())
    require(isinstance(result, dict) and result.get(name + '_passed') is True,
            f'Native {name} fixture incomplete')


def verify_output_buffer_fixture(path):
    result = json.loads(Path(path).read_text())
    require(isinstance(result, dict) and result.get('schema_version') == 1
            and result.get('output_buffer_ownership_passed') is True,
            'Native output_buffer_ownership fixture incomplete')
    scenarios = {'uncached', 'cached', 'not_modified', 'early_404', 'late_404',
                 'unavailable_render', 'unavailable_headers', 'unrelated'}
    expected = {(scenario, mode, method) for scenario in scenarios
                for mode in ('single', 'nested', 'fixed') for method in ('GET', 'HEAD')}
    cases = result.get('cases', [])
    require(isinstance(cases, list) and len(cases) == len(expected), 'Native output_buffer_ownership cases incomplete')
    observed = set()
    for case in cases:
        require(isinstance(case, dict) and case.get('passed') is True
                and case.get('prefix_preserved') is True and case.get('before') == case.get('after')
                and isinstance(case.get('before'), dict) and case['before'].get('level', 0) > 0
                and case.get('callback_phases_before_shutdown') == [],
                'Native output_buffer_ownership case failed')
        observed.add((case.get('scenario'), case.get('buffer_mode'), case.get('method')))
    require(observed == expected and set(result.get('style_checks', [])) == {
        'native-accumulation-filters-dependencies-repeat', 'both-report-exporters', 'exception-and-global-state'},
        'Native output_buffer_ownership coverage incomplete')


def validate_result(result, wp, php, multisite, archive_hash, commit):
    require(result['wordpress_version'] == wp and result['php_version'].startswith(php + '.'), 'Runtime version mismatch')
    require(result['zip_sha256'] == archive_hash and result['commit'] == commit, 'Runtime evidence belongs to another ZIP or commit')
    require(result.get('plugin_check_environment') == 'single-site' and result.get('wp_debug_clean') is True
            and result.get('lifecycle_passed') is True and result.get('multisite') is multisite,
            'Runtime coverage incomplete')
    require(result.get('mcp_read_only_passed') is True and result.get('mcp_adapter_version') == json.loads((ROOT / 'docs/dev/release-policy.json').read_text())['mcp_adapter_version'], 'Read-only MCP runtime coverage incomplete')
    require(result.get('sitemap_regressions_passed') is True, 'Sitemap runtime regressions incomplete')
    require(result.get('rest_representation_passed') is True, 'Native REST representation regressions incomplete')
    require(result.get('publication_priming_passed') is True, 'Native publication priming regressions incomplete')
    for key in ('configuration_persistence', 'cloudflare_persistence', 'audit_run_lock', 'static_ownership', 'state_cutover', 'stored_template_source', 'raw_option_store', 'output_buffer_ownership'):
        require(result.get(key + '_passed') is True, f'Native {key} regressions incomplete')
    require(result.get('stable_findings') == [] and result.get('experimental_findings') == [], 'Runtime findings remain')


def main():
    if sys.argv[1] == '--verify-output-buffer':
        verify_output_buffer_fixture(sys.argv[2])
        return
    if sys.argv[1] == '--verify-browser':
        verify_browser(sys.argv[2])
        return
    if sys.argv[1] == '--verify-priming':
        verify_priming_fixture(sys.argv[2])
        return
    if sys.argv[1] == '--verify-template-source':
        verify_sql_fixture(sys.argv[2], 'stored_template_source')
        return
    if sys.argv[1] == '--verify-raw-option-store':
        verify_sql_fixture(sys.argv[2], 'raw_option_store')
        return
    archive, output = map(Path, sys.argv[1:3])
    _, archive_hash = package_files(archive)
    commit = git(ROOT, 'rev-parse', 'HEAD')
    policy = json.loads((ROOT / 'docs/dev/release-policy.json').read_text())
    latest = os.environ['CYBERMAPS_LATEST_WP']
    cases = [(latest, '8.2', True), (latest, '8.2', False), (policy['minimum_wordpress'], '8.2', False)]
    cases += [(latest, php, False) for php in policy['php_versions']]
    cases += [(latest, '8.2', True)]
    def run_case(case_values):
        wp, php, multisite = case_values
        case = f'wp-{wp}-php-{php}' + ('-multisite' if multisite else '')
        browser = not multisite and wp == latest and php == '8.2'
        env = dict(os.environ, CYBERMAPS_TEST_WP=wp, CYBERMAPS_TEST_PHP=php,
                   CYBERMAPS_TEST_MULTISITE='1' if multisite else '0',
                   CYBERMAPS_BROWSER_REPORT=str(output / 'browser.json') if browser else '',
                   CYBERMAPS_TEST_UPGRADE='1' if browser else '0',
                   PLAYWRIGHT_BROWSERS_PATH=str(ROOT / 'docs/generated/tmp/playwright-browsers'))
        report = output / (case + '.json')
        subprocess.run(['bash', str(ROOT / 'bin/validate-plugin-check.sh'),
                        str(archive.parent.parent / 'cybermaps'), str(archive), str(report)], env=env, check=True)
        result = json.loads(report.read_text())
        validate_result(result, wp, php, multisite, archive_hash, commit)
        require(result['plugin_check_version'] == policy['plugin_check_version'], 'Checker version mismatch')
        print(case + ' passed', flush=True)
    unique = list(dict.fromkeys(cases))
    count = int(os.environ.get('CYBERMAPS_MATRIX_WORKERS', '3'))
    require(1 <= count <= 6, 'Use between one and six isolated matrix workers')
    with ThreadPoolExecutor(max_workers=count) as workers:
        list(workers.map(run_case, unique))
    verify_browser(output / 'browser.json')


if __name__ == '__main__':
    main()
