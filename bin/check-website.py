#!/usr/bin/env python3
"""Validate the documentation handoff before publishing a plugin release."""
import argparse
import json
from pathlib import Path
import subprocess


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('website', type=Path)
    parser.add_argument('--commit', required=True)
    parser.add_argument('--channel', choices=['beta', 'stable'], required=True)
    parser.add_argument('--source-only', action='store_true', help='Recheck source and reviews after the full build has passed')
    args = parser.parse_args()
    website = args.website.resolve()
    plugin = Path(__file__).resolve().parents[1]
    release = json.loads((website / 'product/release.json').read_text())
    if release.get('commit') != args.commit or release.get('channel') != args.channel:
        raise ValueError('Website docs must be imported from the exact release commit and channel. Run docs:sync and review affected guides.')
    commands = [
        ['node', 'scripts/check-source.mjs', str(plugin), '--commit', args.commit, '--channel', args.channel],
        ['node', 'scripts/check-docs.mjs'],
    ]
    if not args.source_only:
        commands += [
            ['npm', 'run', 'test:docs'],
            ['npm', 'run', 'build'],
            # Match the website's existing exception for unavailable historical contracts.
            ['node', 'scripts/verify.mjs', '--allow-missing-legacy-contracts'],
        ]
    for command in commands:
        subprocess.run(command, cwd=website, check=True)
    print(f'Website documentation validated against {args.commit}.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        raise SystemExit('Website release check failed: ' + str(error)) from error
