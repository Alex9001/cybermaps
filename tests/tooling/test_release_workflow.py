#!/usr/bin/env python3
"""Release/install failure-path tests using disposable fixtures inside this workspace."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
import test_release_github as fixtures
import workspace


def snapshot(root):
    return {str(p.relative_to(root)): (p.lstat().st_mode, p.lstat().st_mtime_ns,
                                     hashlib.sha256(p.read_bytes()).hexdigest() if p.is_file() else '')
            for p in root.rglob('*')}


class WorkflowTest(unittest.TestCase):
    setUp = fixtures.PublisherTest.setUp
    git = fixtures.PublisherTest.git
    website_git = fixtures.PublisherTest.website_git
    state = fixtures.PublisherTest.state
    publish = fixtures.PublisherTest.publish

    def workflow(self, action='release', failure='', success=True, *args):
        credentials = ['--svn-password-stdin'] if action in ('release', 'resume') and '--beta' not in args else []
        result = subprocess.run([sys.executable, '-B', 'bin/release-workflow.py', action, *args, *credentials],
                                cwd=self.repo, env=dict(self.env, MOCK_FAIL=failure), capture_output=True, text=True,
                                input='fixture-svn-password\n' if credentials else None)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return result

    def commands(self):
        p = Path(self.env['MOCK_STATE'] + '.commands')
        return [json.loads(line) for line in p.read_text().splitlines()] if p.exists() else []

    def configure_install(self, path):
        (self.repo / '.cybermaps-workspace.json').write_text(json.dumps(dict(website=str(self.website), install=str(path))))

    def assert_no_publication(self):
        self.assertIsNone(self.state())
        self.assertEqual(self.git('tag'), '')

    def test_dirty_website_has_zero_writes_for_all_dirty_states(self):
        for kind in ('untracked', 'unstaged', 'staged'):
            with self.subTest(kind=kind):
                path = self.website / ('untracked.txt' if kind == 'untracked' else 'package.json')
                path.write_text(kind)
                if kind == 'staged':
                    self.website_git('add', '.')
                before = snapshot(self.website)
                result = self.workflow(success=False)
                self.assertIn('Website has staged, unstaged or untracked work', result.stderr)
                self.assertEqual(before, snapshot(self.website))
                self.assertEqual(self.commands(), [])
                self.website_git('add', '.')
                self.website_git('commit', '-m', kind + ' fixture')
        self.assert_no_publication()

    def test_source_must_be_committed_and_pushed_before_import(self):
        (self.repo / 'user-work.txt').write_text('preserve')
        before = snapshot(self.website)
        self.workflow(success=False)
        self.assertEqual(before, snapshot(self.website))
        self.assert_no_publication()

    def test_unsafe_destination_and_symlink_escape(self):
        for target in (self.repo, self.website, self.install.parent, Path('/')):
            self.configure_install(target)
            before = snapshot(self.install)
            self.workflow('dev-install', success=False)
            self.assertEqual(before, snapshot(self.install))
        alias = self.base / 'alias'
        alias.symlink_to(self.base / 'wordpress', target_is_directory=True)
        self.configure_install(alias / 'wp-content/plugins/cybermaps')
        self.assertIn('Symlink', self.workflow('dev-install', success=False).stderr)
        self.assertEqual(self.commands(), [])

    def test_installation_containing_source_repository_is_refused(self):
        (self.install / '.git').write_text('gitdir: somewhere')
        before = snapshot(self.install)
        self.assertIn('source repository', self.workflow('dev-install', success=False).stderr)
        self.assertEqual(before, snapshot(self.install))

    def test_generated_output_symlink_is_refused(self):
        (self.repo / 'docs').mkdir(exist_ok=True)
        (self.repo / 'docs/generated').symlink_to(self.website, target_is_directory=True)
        before = snapshot(self.website)
        self.assertIn('Symlink', self.workflow('dev-install', success=False).stderr)
        self.assertEqual(before, snapshot(self.website))

    def test_review_pause_lists_pages_and_preserves_install(self):
        before = snapshot(self.install)
        result = self.workflow(failure='review', success=False)
        self.assertIn('docs--machine-publications.md', result.stderr)
        self.assertEqual(before, snapshot(self.install))
        self.assert_no_publication()
        # Existing review work is never auto-committed on a second invocation.
        dirty = snapshot(self.website)
        self.workflow(success=False)
        self.assertEqual(dirty, snapshot(self.website))
        self.website_git('add', '.')
        self.website_git('commit', '-m', 'Human reviewed docs')
        self.workflow()

    def test_dev_install_has_no_website_or_remote_commands(self):
        (self.website / 'user-work.txt').write_text('unfinished')
        before = snapshot(self.website)
        self.workflow('dev-install')
        self.assertEqual(before, snapshot(self.website))
        self.assertEqual((self.install / 'cybermaps.php').read_bytes(), (self.repo / 'cybermaps.php').read_bytes())
        self.assertTrue(all(c[0] == 'composer' for c in self.commands()))
        backups = list((self.repo / 'docs/generated/backups').glob('install-*/cybermaps/cybermaps.php'))
        self.assertEqual(len(backups), 1)
        self.assertEqual(backups[0].read_text(), 'previous install')
        self.assertFalse((self.repo / 'docs/generated/releases/7.4.0/workflow.json').exists())

    def test_unexpected_website_changes_are_never_committed(self):
        head = self.website_git('rev-parse', 'HEAD')
        self.assertIn('Unexpected website changes', self.workflow(failure='unrelated-write', success=False).stderr)
        self.assertEqual(head, self.website_git('rev-parse', 'HEAD'))
        self.assertEqual(self.website_git('diff', '--cached', '--name-only'), '')
        self.assertTrue((self.website / 'user-notes.md').exists())
        self.assert_no_publication()

    def test_full_workflow_validates_once_and_installs_before_publishing(self):
        self.workflow()
        commands = self.commands()
        self.assertEqual(commands.count(['composer', 'run', 'release:build']), 1)
        self.assertEqual(commands.count(['composer', 'run', 'release:workloads']), 1)
        self.assertEqual(commands.count(['composer', 'run', 'release:ready']), 3)
        svn = commands.index(['svnmucc', 'commit'])
        dates = [i for i, command in enumerate(commands) if command[:2] == ['node', 'scripts/check-launch-date.mjs']]
        self.assertGreater(len([i for i in dates if i < svn]), 1)
        github = next(i for i, c in enumerate(commands) if c[:3] == ['gh', 'release', 'edit'])
        self.assertLess(github, svn)
        self.assertNotIn(['composer', 'run', 'release:validate'], commands)
        self.assertFalse(self.state()['draft'])
        record = json.loads((self.repo / 'docs/generated/releases/7.4.0/workflow.json').read_text())
        self.assertEqual(record['phase'], 'complete')
        self.assertEqual(self.website_git('status', '--porcelain'), '')
        self.assertTrue(Path(record['rollback']).is_dir())

    def test_live_mismatch_blocks_completion_and_resume_never_republishes(self):
        self.workflow(failure='live', success=False)
        record = json.loads((self.repo / 'docs/generated/releases/7.4.0/workflow.json').read_text())
        self.assertEqual(record['phase'], 'website-verification-failed')
        published = self.state()
        before = len(self.commands())
        self.workflow('resume', '', True, '--tag', 'v7.4.0')
        self.assertEqual(published, self.state())
        after = self.commands()[before:]
        self.assertFalse(any(c[0] == 'composer' for c in after))
        self.assertFalse(any(c[:2] == ['gh', 'release'] and c[2] in ('create', 'upload', 'edit') for c in after))

    def test_interrupted_publication_resumes_draft_without_replacing_assets(self):
        self.workflow(failure='interrupt', success=False)
        asset = self.state()['assets'][0]
        self.workflow()
        self.assertEqual(asset, self.state()['assets'][0])
        self.assertFalse(self.state()['draft'])

    def test_deployment_retry_only_verifies_downloads_and_deploys(self):
        self.workflow(failure='deploy', success=False)
        published = self.state()
        count = len(self.commands())
        self.workflow('resume', '', True, '--tag', 'v7.4.0')
        after = self.commands()[count:]
        self.assertEqual(published, self.state())
        self.assertIn(['npm', 'run', 'deploy'], after)
        self.assertFalse(any(c[0] == 'composer' or c[1:3] == ['run', 'docs:sync'] for c in after))
        self.assertFalse(any(c[:2] == ['gh', 'release'] and c[2] in ('create', 'upload', 'edit') for c in after))

    def test_resume_after_publication_without_saved_workflow_state(self):
        self.workflow()
        (self.repo / 'docs/generated/releases/7.4.0/workflow.json').unlink()
        published = self.state()
        count = len(self.commands())
        self.workflow('resume', '', True, '--tag', 'v7.4.0')
        after = self.commands()[count:]
        self.assertEqual(published, self.state())
        self.assertIn(['npm', 'run', 'deploy'], after)
        self.assertFalse(any(c[0] == 'composer' for c in after))
        self.assertFalse(any(c[:2] == ['gh', 'release'] and c[2] in ('create', 'upload', 'edit') for c in after))

    def test_resume_allows_download_counts_to_increase(self):
        self.workflow()
        ids = [a['id'] for a in self.state()['assets']]
        self.workflow('resume', 'download-count', True, '--tag', 'v7.4.0')
        self.assertEqual(ids, [a['id'] for a in self.state()['assets']])

    def test_changed_package_cannot_be_installed_after_validation(self):
        self.workflow('dev-install')
        before = snapshot(self.install)
        archive = self.repo / 'docs/generated/releases/7.4.0/candidate/cybermaps_7.4.0.zip'
        with patch.object(workspace, 'ROOT', self.repo):
            with self.assertRaisesRegex(RuntimeError, 'changed after validation'):
                workspace.install(archive, self.install, expected_sha256='0' * 64)
        self.assertEqual(before, snapshot(self.install))

    def test_resume_completes_draft_but_rejects_corrupt_published_download(self):
        self.workflow(failure='interrupt', success=False)
        self.workflow('resume', '', True, '--tag', 'v7.4.0')
        published = self.state()
        before = snapshot(self.website)
        self.workflow('resume', 'download', False, '--tag', 'v7.4.0')
        self.assertEqual(before, snapshot(self.website))
        self.assertEqual(published, self.state())

    def test_stable_is_default_and_publishes_github_before_wordpress(self):
        self.workflow(failure='wordpress-pending', success=False)
        self.assertFalse(self.state()['draft'])
        self.assertFalse(self.state()['prerelease'])
        commands = self.commands()
        self.assertEqual(commands.count(['svnmucc', 'commit']), 1)
        published = next(i for i, c in enumerate(commands) if c[:3] == ['gh', 'release', 'edit'])
        self.assertLess(published, commands.index(['svnmucc', 'commit']))
        self.assertNotIn(['npm', 'run', 'deploy'], commands)
        self.workflow('resume', '', True, '--tag', 'v7.4.0')
        resumed = self.commands()[len(commands):]
        self.assertFalse(any(c[0] == 'composer' for c in resumed))
        self.assertNotIn(['svnmucc', 'commit'], resumed)
        self.assertFalse(any(c[:3] == ['gh', 'release', 'edit'] for c in resumed))
        self.assertIn(['wordpress', 'distribution-verified'], resumed)

    def test_beta_is_explicit_and_never_replaces_production_or_svn(self):
        self.workflow('release', '', True, '--beta')
        self.assertTrue(self.state()['prerelease'])
        self.assertNotIn(['svnmucc', 'commit'], self.commands())
        self.assertNotIn(['composer', 'run', 'release:workloads'], self.commands())
        self.assertNotIn(['npm', 'run', 'deploy'], self.commands())
        record = json.loads((self.repo / 'docs/generated/releases/7.4.0/workflow.json').read_text())
        self.assertEqual(record['phase'], 'beta-published')

    def test_svn_auth_failure_leaves_github_public_and_can_retry(self):
        self.workflow(failure='svn-auth', success=False)
        self.assertFalse(self.state()['draft'])
        self.assertNotIn(['svnmucc', 'commit'], self.commands())
        self.workflow('resume', '', True, '--tag', 'v7.4.0')
        self.assertFalse(self.state()['draft'])

    def test_password_pipe_is_consumed_before_any_child_command(self):
        sentinel = 'sentinel-password-not-for-child-processes'
        result = subprocess.run([sys.executable, '-B', 'bin/release-workflow.py', 'release', '--beta',
                                 '--svn-password-stdin'], cwd=self.repo,
                                env=dict(self.env, MOCK_STDIN_PROBE='1'),
                                input=sentinel + '\n', text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        observed = Path(self.env['MOCK_STATE'] + '.stdin').read_text().splitlines()
        self.assertTrue(observed)
        self.assertEqual(set(observed), {'0'})
        self.assertNotIn(sentinel, result.stdout + result.stderr + str(self.commands()))

    def test_failed_install_restores_previous_files(self):
        self.workflow('dev-install')
        before = snapshot(self.install)
        archive = self.repo / 'docs/generated/releases/7.4.0/candidate/cybermaps_7.4.0.zip'
        original = workspace.verify_files
        def fail_after_swap(path, contents):
            if path == self.install:
                raise RuntimeError('injected post-install verification failure')
            return original(path, contents)
        with patch.object(workspace, 'ROOT', self.repo), patch.object(workspace, 'verify_files', fail_after_swap):
            with self.assertRaisesRegex(RuntimeError, 'injected'):
                workspace.install(archive, self.install)
        self.assertEqual(before, snapshot(self.install))
        records = [json.loads(p.read_text()) for p in (self.repo / 'docs/generated/backups').glob('install-*/install.json')]
        self.assertIn('rolled-back', [r['phase'] for r in records])
        workspace.configure()


if __name__ == '__main__':
    unittest.main()
