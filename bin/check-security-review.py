#!/usr/bin/env python3
"""Annotation-blind audit bound to individually reviewed function bodies."""
from collections import Counter
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
SNiffs = 'WordPress.Security.EscapeOutput,WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput,WordPress.DB.PreparedSQL'


def inventory():
    scopes = json.loads(subprocess.check_output(['php', str(ROOT / 'bin/security-inventory.php')], text=True))
    run = subprocess.run(['php', 'vendor/bin/phpcs', '--standard=phpcs.xml.dist', '--ignore-annotations', '-q',
                          '--sniffs=' + SNiffs, '--report=json', 'cybermaps.php', 'uninstall.php', 'src'],
                         cwd=ROOT, text=True, capture_output=True)
    if run.returncode not in (0, 1, 2, 3) or run.stderr.strip():
        raise RuntimeError('Security scanner execution failed: ' + run.stderr)
    audit = json.loads(run.stdout)
    if set(audit) != {'totals', 'files'} or not isinstance(audit['files'], dict):
        raise RuntimeError('Invalid security scanner report')
    found = 0
    for path, report in audit['files'].items():
        for finding in report['messages']:
            matches = [value for value in scopes.values() if value['path'] == path and finding['line'] in value['lines']]
            if len(matches) != 1:
                raise RuntimeError(f'Finding has ambiguous scope: {path}:{finding["line"]}')
            key = finding['source'] + ':' + finding['type']
            matches[0].setdefault('findings', []).append(key)
            found += 1
    if found != audit['totals']['errors'] + audit['totals']['warnings'] or (run.returncode == 0 and found):
        raise RuntimeError('Security scanner totals/status mismatch')
    result = {}
    for key, value in scopes.items():
        value['findings'] = dict(sorted(Counter(value.get('findings', [])).items()))
        if value['requests'] or value['suppressions'] or value['findings']:
            value.pop('lines')
            result[key] = value
    return result


def compare(actual, reviewed):
    failures = []
    for key, value in actual.items():
        review = reviewed.get(key)
        if not review or review.get('inventory') != value or not review.get('rationale') or not review.get('tests'):
            failures.append('Unreviewed or changed security scope: ' + key)
    for key in reviewed.keys() - actual.keys():
        failures.append('Retired security review must be removed after inspection: ' + key)
    return failures


if __name__ == '__main__':
    try:
        actual = inventory()
        if sys.argv[1:] == ['--inventory']:
            print(json.dumps(actual, indent=2))
        else:
            ledger = json.loads((ROOT / 'docs/dev/security-reviews.json').read_text())
            if ledger.get('schema_version') != 1:
                raise RuntimeError('Invalid security review ledger')
            failures = compare(actual, ledger['scopes'])
            for review in ledger['scopes'].values():
                if any(not (ROOT / test).is_file() for test in review['tests']):
                    failures.append('Security review references a missing test')
            if failures:
                raise RuntimeError('\n'.join(failures))
            print(f'Annotation-blind security review: {len(actual)} exact function scopes verified.')
    except (RuntimeError, ValueError, OSError, KeyError) as error:
        raise SystemExit(str(error)) from error
