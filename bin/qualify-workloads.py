#!/usr/bin/env python3
"""Qualify stable workloads, starting with the 10,000-entry report deadline."""
import argparse
import json
from pathlib import Path
import subprocess
import sys

from release_gate import load_record, require_mutable_evidence, upstream, validate_record
from stable_release_checks import module
from workspace import ROOT, configure, lock, release_dir, require


def measure(directory, archive, label, wordpress, *, preflight=False, baseline=False, images=None):
    command = [
        sys.executable, '-B', str(ROOT / 'tests/performance/run.py'),
        '--zip', str(archive), '--label', label.replace('.', '-'), '--release-version', directory.name,
        '--wordpress-version', wordpress, '--timeout', '90', '--long-timeout', '120',
        '--static-timeout', '600', '--static-slices', '50',
    ]
    if images:
        command.extend(['--image-manifest', str(images)])
    if preflight:
        command.extend(['--sizes', '10000', '--operations', 'audit'])
    else:
        command.extend(['--sizes', '1000', '10000'])
    if not baseline:
        command.append('--fail-fast')
    with subprocess.Popen(command, cwd=ROOT, text=True, stdout=subprocess.PIPE) as child:
        first = child.stdout.readline().strip()
        print(first, flush=True)
        for line in child.stdout:
            print(line, end='', flush=True)
        code = child.wait()
    measured = Path(first)
    require(measured.is_dir() and measured.parent == directory / 'audit/performance',
            'The workload produced no evidence directory.')
    require(code == 0 or (baseline and code == 2),
            f'{label} failed. Review {measured} before starting another comparison.')
    return measured


def audit_preflight(directory, archive, wordpress, verifier):
    probe = measure(directory, archive, 'audit-preflight-' + directory.name, wordpress, preflight=True)
    for redis in ('off', 'on'):
        for cache in ('on', 'off'):
            path = probe / f'10000-redis-{redis}-plugin-{cache}-audit.json'
            samples = json.loads(path.read_text())
            require(len(samples) == 1 and verifier.harness.valid_sample(samples[0])
                    and verifier.within_deadline('audit', samples[0], {'long_timeout': 120}),
                    f'Full comparison stopped at the 10,000-entry report deadline: {path}')
    return probe


def qualify(preflight_only=False):
    directory = release_dir()
    require_mutable_evidence(directory)
    number = directory.name
    archive = directory / 'candidate' / f'cybermaps_{number}.zip'
    verifier = module('tests/performance/check_release.py', 'cybermaps_workload_qualification')
    if preflight_only:
        require(archive.is_file() and verifier.harness.package_version(archive) == number,
                'Package the current candidate with bash bin/package-candidate.sh before the preflight.')
        return audit_preflight(directory, archive, upstream()['wordpress_version'], verifier)
    record = validate_record(load_record())
    commit = record['identity']['commit']
    digest = record['zip_sha256']
    wordpress = record['upstream']['wordpress_version']
    receipt = directory / 'everyday-workloads.json'
    if receipt.is_file():
        try:
            verifier.validate_receipt(receipt, digest, commit)
        except (ValueError, KeyError, OSError):
            pass
        else:
            print(f'Reusing verified workloads for {number}: {receipt}')
            return

    previous = verifier.BASELINE_VERSION
    baseline_zip = release_dir(previous) / f'cybermaps_{previous}.zip'
    require(verifier.digest(baseline_zip) == verifier.BASELINE_SHA256, 'Stable baseline ZIP differs from policy.')
    probe = audit_preflight(directory, archive, wordpress, verifier)
    before = measure(directory, baseline_zip, 'baseline-' + previous, wordpress,
                     baseline=True, images=probe / 'environment.json')
    verifier.validate_run(before, previous, verifier.BASELINE_SHA256, candidate=False, max_age_hours=24)
    after = measure(directory, archive, 'candidate-' + number, wordpress, images=before / 'environment.json')
    subprocess.run([sys.executable, '-B', str(ROOT / 'tests/performance/check_release.py'), '--version', number,
                    '--commit', commit, '--zip', str(archive), '--baseline', str(before),
                    '--candidate', str(after)], cwd=ROOT, check=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--preflight-only', action='store_true',
                        help='Check a locally packaged candidate before the runtime matrix or full comparison')
    args = parser.parse_args()
    configure()
    with lock(allow_parent=True):
        qualify(args.preflight_only)
