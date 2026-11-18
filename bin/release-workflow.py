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
from wordpress_release import Publication, Pending, OfferUnavailable
from stable_release_checks import publication_evidence, upgrade

spec = importlib.util.spec_from_file_location('publisher', ROOT / 'bin/release-github.py')
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


def stdin_password():
    """Consume the secret before any subprocess can inherit the credential pipe."""
    raw = sys.stdin.buffer.readline(16386)
    value = raw[:-1] if raw.endswith(b'\n') else raw
    if value.endswith(b'\r'):
        value = value[:-1]
    with open(os.devnull, 'rb') as empty:
        os.dup2(empty.fileno(), 0)
    require(0 < len(value) <= 16384 and b'\n' not in value and b'\r' not in value,
            'Provide exactly one nonempty SVN password line on stdin.')
    return value


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
                 'llms-tldr-whitepaper.md', 'source.json', 'editorial-source.json'}
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
        if phase in ('complete', 'beta-published'):
            for name in ('publication_error', 'deployment_error', 'next_check_at'):
                self.state.pop(name, None)
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

    def wordpress(self, archive, digest, password=None, username='oreshkin'):
        publication = Publication(self.number, self.state['commit'], archive)
        require(publication.zip_sha256 == digest, 'WordPress publication package changed')

        def fresh_evidence():
            require(publisher.source_commit() == self.state['commit'], 'Source changed before SVN publication')
            run('composer', 'run', 'release:ready', capture=False)
            clean_website(self.website)
            return publication_evidence(self.number)

        receipt = publication.publish(fresh_evidence, username=username, password=password)
        self.phase('svn-committed', svn_revision=receipt['svn_revision'])

        def native_update():
            proof = self.directory / 'native-upgrade-wordpress.json'
            distribution = self.directory / 'wordpress-distribution.json'
            previous = json.loads(distribution.read_text()) if distribution.exists() else {}
            # A completed public update is retained across deployment retries. We
            # recheck all its immutable evidence and the live distribution each time.
            max_age = None if previous.get('state') == 'distribution-verified' else 24
            if proof.exists():
                try:
                    return upgrade(self.number, digest, self.state['commit'], 'wordpress', max_age)
                except (RuntimeError, OSError, ValueError, KeyError):
                    if previous.get('state') == 'distribution-verified':
                        raise
                    # A failed update offer during cooldown remains in its attempt
                    # directory. A later retry executes a new real update test;
                    # it never turns the earlier result into a success flag.
            runtime = json.loads((self.directory / 'validation.json').read_text())['upstream']['wordpress_version']
            self.phase('verifying-native-wordpress-update')
            try:
                run('python3', '-B', str(ROOT / 'tests/integration/native-upgrade.py'),
                    '--zip', str(archive), '--phase', 'wordpress', '--output', str(proof),
                    '--wordpress', runtime, '--commit', self.state['commit'], capture=False)
            except (RuntimeError, subprocess.CalledProcessError) as error:
                if getattr(error, 'returncode', None) == 75:
                    raise OfferUnavailable('The official update API has not advertised this version yet') from error
                raise
            return upgrade(self.number, digest, self.state['commit'], 'wordpress')

        try:
            result = publication.distribution(verify_upgrade=native_update)
        except Pending:
            path = self.directory / 'wordpress-pending.json'
            if not path.exists():
                path = self.directory / 'wordpress-distribution.json'
            pending = json.loads(path.read_text())
            self.phase(pending['state'], next_check_at=pending['next_check_at'])
            raise
        self.phase('wordpress-distribution-verified', wordpress=result['wordpress'])
        return result

    def before_github(self, archive, digest):
        """Keep date/prose fixes reviewable before making GitHub latest public."""
        clean_website(self.website)
        run('node', 'scripts/check-launch-date.mjs', '--version', self.number, cwd=self.website)
        source = self.website / 'product' / self.number / 'source.json'
        snapshot = json.loads(source.read_text())
        selector = ('--tag', 'v' + self.number) if snapshot.get('state') == 'tagged' else ()
        command = ['python3', str(ROOT / 'bin/check-website.py'), str(self.website), '--commit', self.state['commit'],
                   '--channel', 'stable', '--zip', str(archive), *selector]
        try:
            run(*command, '--source-only', capture=False)
        except (RuntimeError, subprocess.CalledProcessError):
            run(*command, capture=False)
            commit_generated(self.website, self.number, build=True)
        clean_website(self.website)
        run('node', 'scripts/check-launch-date.mjs', '--version', self.number, cwd=self.website)

    def resume_committed(self, password=None, username='oreshkin'):
        """Return true only after continuing an already committed stable release."""
        path = self.directory / 'wordpress-publication.json'
        if not path.exists():
            return False
        github = publisher.release_state('v' + self.number)
        if github and not github['draft']:
            return False
        saved = json.loads(path.read_text())
        if saved.get('state') == 'inputs-frozen' and saved.get('base_revision') is None:
            return False
        archive = self.directory / ('cybermaps_' + self.number + '.zip')
        publication = Publication(self.number, saved['commit'], archive)
        record = publication.load()
        record = publication.reconcile(record)
        if not record.get('svn_revision'):
            return False
        self.phase('svn-committed', commit=saved['commit'], tag='v' + self.number,
                   channel='stable', zip_sha256=saved['zip_sha256'])
        publisher.publish_frozen(archive, saved['commit'], False,
            on_phase=self.phase, before_github=self.before_github)
        self.downstream('v' + self.number, password, username)
        return True

    def downstream(self, tag, password=None, username='oreshkin'):
        clean_website(self.website)
        self.phase('verifying-published-release')
        published = publisher.verify_published(tag)
        if self.state.get('commit'):
            require(self.state['commit'] == published['commit'], 'Saved candidate and published commit differ.')
        if self.state.get('zip_sha256'):
            require(self.state['zip_sha256'] == published['zip_sha256'], 'Saved package and published checksum differ.')
        self.phase('published-verified', **published)
        if published['channel'] == 'beta':
            # Explicit prereleases are useful for testing, but must never replace
            # the stable production website or be uploaded to WordPress.org.
            self.phase('beta-published', **published)
            return
        archive = self.directory / ('cybermaps_' + self.number + '.zip')
        distribution = self.wordpress(archive, published['zip_sha256'], password, username)
        release_path = self.website / 'product/release.json'
        source_path = self.website / 'product' / self.number / 'source.json'
        frozen = json.loads(source_path.read_text()) if source_path.exists() else {}
        current = json.loads(release_path.read_text()) if release_path.exists() else {}
        if (frozen.get('state') != 'tagged' or frozen.get('commit') != published['commit']
                or any(current.get(k) != published[k] for k in ('tag', 'commit', 'channel'))
                or current.get('wordpress') != distribution['wordpress'] or current.get('githubReleasePending')):
            self.sync('tag', tag, published['channel'])
        clean_website(self.website)
        self.phase('deploying')
        try:
            # Existing guard checks newest published release/tag both before build and upload.
            run('npm', 'run', 'deploy', cwd=self.website, capture=False)
        except (RuntimeError, subprocess.CalledProcessError) as error:
            self.phase('deployment-failed', deployment_error=str(error))
            try:
                commit_generated(self.website, self.number, build=True)
            except (RuntimeError, subprocess.CalledProcessError) as cleanup:
                raise RuntimeError('Deployment failed: ' + str(error) + '\nGenerated-output review also failed: '
                                   + str(cleanup)) from error
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
    channels = parser.add_mutually_exclusive_group()
    channels.add_argument('--stable', action='store_true', help='Publish stable (the default)')
    channels.add_argument('--beta', action='store_true', help='Explicit GitHub prerelease; no WordPress or production website publication')
    parser.add_argument('--tag', help='Frozen tag to resume, including pending WordPress distribution')
    parser.add_argument('--svn-password-stdin', action='store_true', help='Read the SVN password from stdin before checks; keep it only in memory')
    parser.add_argument('--svn-username', default='oreshkin')
    parser.add_argument('--website', help='Compatibility option; must match the canonical configured website')
    args = parser.parse_args()
    require((args.action == 'resume') == bool(args.tag), '--tag is required only for resume.')
    require(not (args.stable or args.beta) or args.action == 'release', 'Channel flags apply only to release.')
    require(not args.svn_password_stdin or args.action != 'dev-install', 'Local installs do not use SVN credentials.')
    require(not args.tag or re.fullmatch(r'v\d+\.\d+\.\d+', args.tag), 'Invalid release tag.')
    password = stdin_password() if args.svn_password_stdin else None
    os.chdir(ROOT)
    configure()
    website, destination = load_config(website_required=args.action != 'dev-install')
    require(not args.website or Path(args.website) == website, '--website must match .cybermaps-workspace.json.')
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
            state = publisher.release_state(args.tag)
            if state and not state['draft']:
                workflow.downstream(args.tag, password, args.svn_username)
                return
            if workflow.resume_committed(password, args.svn_username):
                return
            require(number == version(), 'Pre-publication resume requires the same source version')
            require(state or (workflow.directory / 'github-publication.json').exists(),
                    'No retained tag or draft is available to resume')
        else:
            require(not args.beta or not (workflow.directory / 'wordpress-publication.json').exists(),
                    'A frozen stable publication cannot be changed into a beta')
            if workflow.resume_committed(password, args.svn_username):
                return
        commit = publisher.source_commit()
        tag = 'v' + number
        publisher.verify_tag(tag, commit)
        state = publisher.release_state(tag)
        require(not state or state['draft'], 'Already published; use composer release:resume -- --tag ' + tag)
        channel = 'beta' if args.beta else 'stable'
        if args.action == 'resume':
            channel = ('beta' if state['prerelease'] else 'stable') if state else json.loads(
                (workflow.directory / 'github-publication.json').read_text())['channel']
        require(channel in ('stable', 'beta'), 'Invalid retained publication channel')
        stable = channel == 'stable'
        if stable:
            run('node', 'scripts/check-launch-date.mjs', '--version', number, cwd=website)
        if state:
            publisher.matching_draft(state, tag, commit, not stable,
                                     publisher.release_notes(number, not stable, commit),
                                     'Cybermaps ' + number + (' — Open beta' if not stable else ''))
        workflow.phase('preflight-passed', commit=commit, tag=tag, channel=channel)
        workflow.sync('commit', commit, channel)

        def validated(archive, digest):
            if stable:
                local, remote = publisher.verify_tag(tag, commit)
                Publication(number, commit, archive).freeze(reset_unpublished=not local and not remote
                                                            and publisher.release_state(tag) is None)
            workflow.phase('installing')
            result = install(archive, destination, expected_sha256=digest)
            workflow.phase('publishing', zip_sha256=result['sha256'], rollback=result['backup'])

        try:
            publisher.publish(website, stable, on_validated=validated,
                              on_website_validated=lambda: commit_generated(website, number, build=True),
                              on_phase=workflow.phase, before_github=workflow.before_github if stable else None)
        except Pending:
            raise
        except (RuntimeError, OSError, ValueError, subprocess.CalledProcessError) as error:
            workflow.phase('publication-incomplete', publication_error=str(error))
            raise
        workflow.downstream(tag, password, args.svn_username)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, ValueError, KeyError, subprocess.CalledProcessError) as error:
        raise SystemExit('Workflow stopped: ' + str(error)
                         + '\nLogs/state are under docs/generated/releases/. Review pauses need a website commit; '
                         'Resume the same frozen tag with composer release:resume -- --tag vX.Y.Z. '
                         'A WordPress distribution delay does not authorize a new package or tag.') from error
