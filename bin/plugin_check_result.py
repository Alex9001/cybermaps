"""Fail-closed parser for the pinned Plugin Check strict-json CLI contract."""
import json
import sys
from pathlib import Path


def parse(stdout, stderr, status):
    if status != 0 or stderr.strip():
        raise ValueError(f'Plugin Check execution failed (exit {status}): {stderr.strip()}')
    if stdout.strip() == 'Success: Checks complete. No errors found.':
        return []
    try:
        findings = json.loads(stdout)
    except json.JSONDecodeError as error:
        raise ValueError('Plugin Check returned missing or malformed JSON') from error
    if not isinstance(findings, list):
        raise ValueError('Plugin Check returned an unrecognized report shape')
    if findings:
        raise ValueError('Plugin Check reported findings: ' + json.dumps(findings, ensure_ascii=False))
    return findings


if __name__ == '__main__':
    try:
        parse(Path(sys.argv[1]).read_text(), Path(sys.argv[2]).read_text(), int(sys.argv[3]))
    except (ValueError, OSError) as error:
        raise SystemExit(str(error)) from error
