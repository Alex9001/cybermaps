#!/usr/bin/env python3
"""One canonical source: prepare, validate, install, publish and deploy locally."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import uuid

sys.dont_write_bytecode = True
from workspace import (ROOT, clean_website, configure, git, install, load_config,
                       lock, release_dir, require, run, safe_path, save_json, version)

spec = importlib.util.spec_from_file_location('publisher', ROOT / 'bin/release-github.py')
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


def changed_files(website):
    records = subprocess.check_output(['git', '-C', str(website), 'status', '--porcelain=v1',
                                       '-z', '--untracked-files=all']).split(b'\0')
    result = []
    for record in records:
        if not record:
            continue
        require(record[0:1] not in (b'R', b'C') and record[1:2] not in (b'R', b'C'),
                'Unexpected website rename; review and commit it yourself.')
        result.append(record[3:].decode())
    return result


def generated_website_file(name, number, build=False):
    if build:
        # Verification is the only nondeterministic tracked output expected from a build.
        # Changed branding/artwork or new outputs need an explicit human review.
        return name == 'verification/crawl.json'
    fixed = {'product/source-lock.json', 'product/release.json', 'product/docs-sync-report.json',
             'public/product/manifest.json', 'public/product/documentation.md',
             'public/product/website.json', 'public/changelog.txt', 'src/content/pages/changelog.md'}
    snapshots = {'documentation.md', 'features.md', 'comparison.md', 'manifest.json', 'schema.json',
                 'catalog.json', 'readme.txt', 'changelog.txt', 'ai-configuration.md',
                 'llms-tldr-whitepaper.md', 'source.json'}
    return (name in fixed or name in {'product/' + number + '/' + p for p in snapshots}
            or name in {'public/specs/ai-configuration/' + number + '/' + p
                        for p in ('schema.json', 'catalog.json')})


def commit_generated(website, number, build=False):
    names = changed_files(website)
    require(all(generated_website_file(n, number, build) for n in names),
            'Unexpected website changes; review and commit them yourself:\n' + '\n'.join(names))
    if not names:
        return
    fingerprints = {n: hashlib.sha256(safe_path(website / n).read_bytes()).hexdigest() for n in names}
    run('git', 'add', '--', *names, cwd=website)
    staged = [name for name in git(website, 'diff', '--cached', '--name-only', '-z').split('\0') if name]
    require(set(staged) == set(names), 'Staged website changes differ from workflow output; stopping.')
    require(set(changed_files(website)) == set(names) and all(
        hashlib.sha256((website / n).read_bytes()).hexdigest() == value for n, value in fingerprints.items()),
        'Website changed before the generated-output commit; stopping.')
    # Commit exactly the generated paths. Never stash, reset or include pre-existing edits.
    run('git', 'commit', '-m', 'Sync Cybermaps ' + number + (' verification' if build else ' release documentation'),
        '--', *names, cwd=website)
    clean_website(website)


class Workflow:
    def __init__(self, website, number):
        self.website = website
        self.number = number
        self.directory = release_dir(number)
        self.directory.mkdir(parents=True, exist_ok=True)
        self.state_path = self.directory / 'workflow.json'
        self.state = json.loads(self.state_path.read_text()) if self.state_path.exists() else {}
        self.log = safe_path(self.directory / ('workflow-' + uuid.uuid4().hex + '.log'))
        os.environ['CYBERMAPS_WORKFLOW_LOG'] = str(self.log)

    def phase(self, phase, **fields):
        self.state.update(phase=phase, log=str(self.log), **fields)
        save_json(self.state_path, self.state)
        print('Release: ' + phase + '. Log: ' + str(self.log), flush=True)

    def sync(self, selector, value, channel):
        clean_website(self.website)
        self.phase('importing-' + selector)
        try:
            run('npm', 'run', 'docs:sync', '--', str(ROOT), '--' + selector, value,
                '--channel', channel, cwd=self.website)
        except subprocess.CalledProcessError as error:
            details = error.output or ''
            self.phase('documentation-review' if 'Documentation review required' in details else 'import-failed')
            raise RuntimeError(details + '\nReview the affected guides and commit the website changes, '
                               'then rerun this command. No release was published by this step.') from error
        commit_generated(self.website, self.number)

    def downstream(self, tag):
        clean_website(self.website)
        self.phase('verifying-published-release')
        published = publisher.verify_published(tag)
        if self.state.get('commit'):
            require(self.state['commit'] == published['commit'], 'Saved candidate and published commit differ.')
        if self.state.get('zip_sha256'):
            require(self.state['zip_sha256'] == published['zip_sha256'], 'Saved package and published checksum differ.')
        self.phase('published-verified', **published)
        release_path = self.website / 'product/release.json'
        source_path = self.website / 'product' / self.number / 'source.json'
        frozen = json.loads(source_path.read_text()) if source_path.exists() else {}
        current = json.loads(release_path.read_text()) if release_path.exists() else {}
        if (frozen.get('state') != 'tagged' or frozen.get('commit') != published['commit']
                or any(current.get(k) != published[k] for k in ('tag', 'commit', 'channel'))):
            self.sync('tag', tag, published['channel'])
        clean_website(self.website)
        self.phase('deploying')
        try:
            # Existing guard checks newest published release/tag both before build and upload.
            run('npm', 'run', 'deploy', cwd=self.website, capture=False)
        except (RuntimeError, subprocess.CalledProcessError):
            self.phase('deployment-failed')
            commit_generated(self.website, self.number, build=True)
            raise
        commit_generated(self.website, self.number, build=True)
        self.phase('verifying-live-website')
        try:
            run('python3', str(ROOT / 'bin/check-website-live.py'), str(self.website),
                '--tag', tag, '--commit', published['commit'], '--channel', published['channel'],
                '--zip-sha256', published['zip_sha256'], capture=False)
        except (RuntimeError, subprocess.CalledProcessError):
            self.phase('website-verification-failed')
            raise
        live = self.directory / 'website-live.json'
        proof = json.loads(live.read_text())
        require(proof.get('state') == 'website-verified' and all(proof.get(k) == published[k]
                for k in ('tag', 'commit', 'channel', 'zip_sha256')), 'Live website evidence differs from release')
        self.phase('complete', website_commit=git(self.website, 'rev-parse', 'HEAD'),
                   website_live_sha256=hashlib.sha256(live.read_bytes()).hexdigest())


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['release', 'dev-install', 'resume'])
    parser.add_argument('--stable', action='store_true', help='Publish stable instead of open beta')
    parser.add_argument('--tag', help='Published tag for downstream-only resume (required with resume)')
    args = parser.parse_args()
    require((args.action == 'resume') == bool(args.tag), '--tag is required only for resume.')
    require(not args.stable or args.action == 'release', '--stable applies only to release.')
    require(not args.tag or re.fullmatch(r'v\d+\.\d+\.\d+', args.tag), 'Invalid release tag.')
    os.chdir(ROOT)
    configure()
    website, destination = load_config(website_required=args.action != 'dev-install')
    with lock():
        if args.action == 'dev-install':
            directory = release_dir()
            directory.mkdir(parents=True, exist_ok=True)
            log = safe_path(directory / ('dev-install-' + uuid.uuid4().hex + '.log'))
            os.environ['CYBERMAPS_WORKFLOW_LOG'] = str(log)
            print('Local build. Log: ' + str(log), flush=True)
            # The build performs input freshness checks, PHP lint, package parity and Plugin Check.
            run('composer', 'run', 'release:build', capture=False)
            result = install(release_dir() / 'candidate' / ('cybermaps_' + version() + '.zip'), destination)
            # Keep local builds separate from the publication/resume state.
            save_json(release_dir() / 'dev-install.json', result)
            return
        # This is deliberately before import, website build, Git commits, tags or releases.
        clean_website(website)
        number = args.tag[1:] if args.tag else version()
        workflow = Workflow(website, number)
        if args.action == 'resume':
            workflow.downstream(args.tag)
            return
        commit = publisher.source_commit()
        tag = 'v' + number
        publisher.verify_tag(tag, commit)
        state = publisher.release_state(tag)
        require(not state or state['draft'], 'Already published; use composer release:resume -- --tag ' + tag)
        channel = 'stable' if args.stable else 'beta'
        if state:
            publisher.matching_draft(state, tag, commit, not args.stable,
                                     publisher.release_notes(number, not args.stable, commit),
                                     'Cybermaps ' + number + (' — Open beta' if not args.stable else ''))
        workflow.phase('preflight-passed', commit=commit, tag=tag, channel=channel)
        workflow.sync('commit', commit, channel)

        def validated(archive, digest):
            workflow.phase('installing')
            result = install(archive, destination, expected_sha256=digest)
            workflow.phase('publishing', zip_sha256=result['sha256'], rollback=result['backup'])

        try:
            publisher.publish(website, args.stable, on_validated=validated,
                              on_website_validated=lambda: commit_generated(website, number, build=True))
        except (RuntimeError, OSError, ValueError, subprocess.CalledProcessError):
            workflow.phase('publication-incomplete')
            raise
        workflow.downstream(tag)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, ValueError, KeyError, subprocess.CalledProcessError) as error:
        raise SystemExit('Workflow stopped: ' + str(error)
                         + '\nLogs/state are under docs/generated/releases/. Review pauses need a website commit; '
                         'matching drafts can be retried with composer release. '
                         'After publication use composer release:resume -- --tag vX.Y.Z.') from error
