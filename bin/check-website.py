#!/usr/bin/env python3
"""Validate the documentation handoff before publishing a plugin release."""
import argparse
import json
from pathlib import Path
import subprocess
import sys
import uuid

sys.dont_write_bytecode = True
from workspace import clean_website, configure, website_destination, release_dir, save_json, version
from website_evidence import digest, fingerprint, now, prepared


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('website', type=Path)
    parser.add_argument('--commit', required=True)
    parser.add_argument('--channel', choices=['beta', 'stable'], required=True)
    parser.add_argument('--source-only', action='store_true', help='Recheck source and reviews after the full build has passed')
    args = parser.parse_args()
    configure()
    website = website_destination(args.website)
    clean_website(website)
    plugin = Path(__file__).resolve().parents[1]
    release = json.loads((website / 'product/release.json').read_text())
    if (release.get('commit') != args.commit or release.get('channel') != args.channel
            or release.get('version') != version()):
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
    if args.source_only:
        prepared(website, args.commit, args.channel, version())
        for command in commands:
            subprocess.run(command, cwd=website, check=True)
    else:
        directory = release_dir() / 'website-checks'
        directory.mkdir(parents=True, exist_ok=True)
        log = directory / (uuid.uuid4().hex + '.log')
        print('Website validation log: ' + str(log), flush=True)
        with log.open('w') as output:
            for command in commands:
                print('Website check: ' + ' '.join(command), flush=True)
                subprocess.run(command, cwd=website, check=True, stdout=output, stderr=subprocess.STDOUT)
        save_json(release_dir() / 'website-prepared.json', dict(schema_version=1,
                  state='website-prepared', commit=args.commit, channel=args.channel,
                  version=version(), website=str(website), source_sha256=fingerprint(website),
                  checked_at=now(), log=str(log), log_sha256=digest(log)))
    print(f'Website documentation validated against {args.commit}.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        raise SystemExit('Website release check failed: ' + str(error)) from error
