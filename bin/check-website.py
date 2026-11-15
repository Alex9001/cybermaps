#!/usr/bin/env python3
"""Validate the documentation handoff before publishing a plugin release."""
import argparse
import json
import re
from pathlib import Path
import subprocess
import sys
import uuid

sys.dont_write_bytecode = True
from workspace import clean_website, configure, website_destination, release_dir, save_json, package_files, require
from website_evidence import digest, fingerprint, now, prepared


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('website', type=Path)
    parser.add_argument('--commit', required=True)
    parser.add_argument('--tag', help='Validate an already imported frozen tag instead of the candidate snapshot')
    parser.add_argument('--zip', type=Path, help='Exact candidate or frozen release ZIP (required for stable)')
    parser.add_argument('--channel', choices=['beta', 'stable'], required=True)
    parser.add_argument('--source-only', action='store_true', help='Recheck source and reviews after the full build has passed')
    args = parser.parse_args()
    configure()
    website = website_destination(args.website)
    clean_website(website)
    plugin = Path(__file__).resolve().parents[1]
    require(re.fullmatch(r'[0-9a-f]{40}', args.commit), 'A full source commit is required')
    header = subprocess.check_output(['git', 'show', args.commit + ':cybermaps.php'], cwd=plugin, text=True)
    match = re.search(r'Version:[ \t]*(\d+\.\d+\.\d+)', header)
    require(match is not None, 'Frozen source version is missing')
    number = match[1]
    require(not args.tag or args.tag == 'v' + number, 'Frozen website tag differs from the source version')
    require(args.channel != 'stable' or args.zip is not None, 'Stable website preparation requires the exact candidate ZIP')
    archive_hash = package_files(args.zip)[1] if args.zip else None
    release = json.loads((website / 'product/release.json').read_text())
    if (release.get('commit') != args.commit or release.get('channel') != args.channel
            or release.get('version') != number):
        raise ValueError('Website docs must be imported from the exact release commit and channel. Run docs:sync and review affected guides.')
    commands = [
        ['node', 'scripts/check-source.mjs', str(plugin), '--tag' if args.tag else '--commit',
         args.tag or args.commit, '--channel', args.channel],
        ['node', 'scripts/check-docs.mjs'],
    ]
    if args.channel == 'stable':
        policy = json.loads((plugin / 'docs/dev/release-policy.json').read_text())
        commands.append(['node', 'scripts/check-screenshots.mjs', '--zip', str(args.zip),
                         '--expected-count', str(policy['presentation']['website_screenshots'])])
    if not args.source_only:
        commands += [
            ['npm', 'run', 'test:docs'],
            ['npm', 'run', 'build'],
            # Match the website's existing exception for unavailable historical contracts.
            ['node', 'scripts/verify.mjs', '--allow-missing-legacy-contracts'],
        ]
    if args.source_only:
        prepared(website, args.commit, args.channel, number, zip_sha256=archive_hash)
        for command in commands:
            subprocess.run(command, cwd=website, check=True)
    else:
        directory = release_dir(number) / 'website-checks'
        directory.mkdir(parents=True, exist_ok=True)
        log = directory / (uuid.uuid4().hex + '.log')
        print('Website validation log: ' + str(log), flush=True)
        with log.open('w') as output:
            for command in commands:
                print('Website check: ' + ' '.join(command), flush=True)
                subprocess.run(command, cwd=website, check=True, stdout=output, stderr=subprocess.STDOUT)
        save_json(release_dir(number) / 'website-prepared.json', dict(schema_version=1,
                  state='website-prepared', commit=args.commit, channel=args.channel,
                  version=number, website=str(website), source_sha256=fingerprint(website), zip_sha256=archive_hash,
                  checked_at=now(), log=str(log), log_sha256=digest(log)))
    print(f'Website documentation validated against {args.commit}.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.CalledProcessError) as error:
        raise SystemExit('Website release check failed: ' + str(error)) from error
