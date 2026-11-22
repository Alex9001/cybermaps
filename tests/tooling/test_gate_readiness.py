import copy
from datetime import datetime, timedelta, timezone
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'bin'))
import release_gate as gate


class ReadinessTest(unittest.TestCase):
    def setUp(self):
        self.directory = ROOT / 'docs/generated/releases/7.5.4'
        self.identity = {'commit': 'commit', 'source_sha256': 'source', 'policy_sha256': 'policy'}
        self.record = dict(state='validation-passed', identity=self.identity,
                           completed_at=datetime.now(timezone.utc).isoformat(), zip_sha256='zip',
                           checks={name: {'status': 'passed', 'log': str((self.directory / (name + '.log')).relative_to(ROOT)),
                                          'sha256': 'hash'} for name in set(gate.COMMANDS) | {'matrix', 'browser', 'xsl'}},
                           evidence={'docs/generated/releases/7.5.4/browser.json': 'hash'})
        for target, replacement in [('identity', lambda: self.identity), ('release_dir', lambda: self.directory),
                                     ('package_files', lambda _: ({}, 'zip')), ('digest', lambda _: 'hash'), ('validate_runtime_evidence', lambda _: None)]:
            patcher = patch.object(gate, target, replacement)
            patcher.start()
            self.addCleanup(patcher.stop)

    def test_complete_current_validation_is_accepted(self):
        self.assertEqual(gate.validate_record(self.record), self.record)

    def test_missing_failed_stale_or_changed_evidence_blocks(self):
        mutations = [lambda r: r.update(state='failed'),
                     lambda r: r.update(identity={}),
                     lambda r: r.update(zip_sha256='changed'),
                     lambda r: r.update(completed_at=(datetime.now(timezone.utc) - timedelta(days=2)).isoformat()),
                     lambda r: r['checks'].pop('browser'),
                     lambda r: r['checks']['source'].update(status='failed'),
                     lambda r: r['checks']['source'].update(sha256='tampered'),
                     lambda r: r['evidence'].update({'docs/generated/releases/7.5.4/browser.json': 'tampered'})]
        for mutate in mutations:
            record = copy.deepcopy(self.record)
            mutate(record)
            with self.subTest(record=record), self.assertRaises(RuntimeError):
                gate.validate_record(record)

    def test_missing_agent_review_blocks_promotion(self):
        with patch.object(Path, 'is_file', return_value=False), self.assertRaises(RuntimeError):
            gate.validate_review(self.record)


class PublishedCheckoutTest(unittest.TestCase):
    def test_published_status_reports_the_provider_still_pending_after_the_site_is_verified(self):
        frozen = dict(tag='v8.1.2', commit='a' * 40, zip_sha256='b' * 64)
        workflow = dict(frozen, phase='wordpress-update-pending', release_id=92,
                        svn_revision=123, channel='stable')
        live = dict(frozen, state='website-verified', channel='stable', checked_at='2026-11-22T04:28:00Z',
                    website_commit='c' * 40)
        pending = dict(frozen, state='wordpress-update-pending', next_check_at='2026-11-22T10:22:43Z',
                       reason='The official update API has not advertised this version yet')
        with tempfile.TemporaryDirectory(dir=gate.configure()) as scratch, \
                patch.object(gate, 'release_dir', return_value=Path(scratch)), patch('builtins.print') as output:
            directory = Path(scratch)
            (directory / 'workflow.json').write_text(json.dumps(workflow))
            (directory / 'wordpress-pending.json').write_text(json.dumps(pending))
            (directory / 'wordpress-distribution.json').write_text(json.dumps(dict(frozen, state='distribution-verified')))
            live_path = directory / 'website-live.json'
            live_path.write_text(json.dumps(live))
            self.assertTrue(gate.status())
            self.assertEqual(json.loads(output.call_args.args[0]), dict(
                state=pending['state'], tag=frozen['tag'], svn_revision=123, next_check_at=pending['next_check_at'],
                website_verified_at=live['checked_at'], blockers=[pending['reason']]))
            live_path.write_text(json.dumps(dict(live, commit='d' * 40)))
            self.assertTrue(gate.status())
            self.assertIsNone(json.loads(output.call_args.args[0])['website_verified_at'])
            failure = dict(frozen, state='wordpress-verification-failed', reason='Native update failed')
            (directory / 'wordpress-failure.json').write_text(json.dumps(failure))
            self.assertTrue(gate.status())
            self.assertEqual(json.loads(output.call_args.args[0])['blockers'], [failure['reason']])
            (directory / 'wordpress-failure.json').write_text(json.dumps(dict(failure, tag='v8.1.1')))
            self.assertTrue(gate.status())
            self.assertEqual(json.loads(output.call_args.args[0])['state'], pending['state'])

    def test_public_receipt_and_svn_inputs_must_match_the_verified_github_release(self):
        published = dict(tag='v8.1.2', commit='a' * 40, channel='stable', zip_sha256='b' * 64, release_id=92)
        saved = {key: published[key] for key in ('tag', 'commit', 'zip_sha256')}
        with tempfile.TemporaryDirectory(dir=gate.configure()) as scratch, \
                patch.object(gate, 'release_dir', return_value=Path(scratch)), \
                patch.object(gate, 'version', return_value='8.1.2'), \
                patch('stable_release_checks.module') as loader:
            loader.return_value.verify_published.return_value = published
            self.assertIsNone(gate.published_submission(saved))
            receipt = Path(scratch) / 'github-publication.json'
            receipt.write_text(json.dumps(dict(published, phase='github-published')))
            self.assertEqual(gate.published_submission(saved), published)
            with self.assertRaisesRegex(RuntimeError, 'SVN inputs'):
                gate.published_submission(dict(saved, zip_sha256='c' * 64))
            receipt.write_text(json.dumps(dict(published, phase='github-published', release_id=93)))
            with self.assertRaisesRegex(RuntimeError, 'GitHub receipt'):
                gate.published_submission(saved)

    def test_only_publication_tool_changes_can_replay_the_original_source_identity(self):
        with tempfile.TemporaryDirectory(dir=gate.configure()) as scratch:
            root = Path(scratch)
            def git(*args):
                return subprocess.check_output(['git', '-C', scratch, *args], text=True).strip()
            git('init', '-q', '-b', 'main')
            git('config', 'user.name', 'Release fixture')
            git('config', 'user.email', 'fixture@example.invalid')
            policy = root / 'docs/dev/release-policy.json'
            for name in ('docs/dev/release-policy.json', 'vendor/composer/installed.json',
                         'docs/generated/tmp/browser-tools/package-lock.json',
                         'docs/generated/tmp/wp-cli-modern/vendor/composer/installed.json',
                         'bin/release_gate.py', 'src/plugin.php'):
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('original')
            git('add', '.')
            git('commit', '-qm', 'validated candidate')
            with patch.object(gate, 'ROOT', root), patch.object(gate, 'POLICY', policy):
                original = gate.identity()
                (root / 'bin/release_gate.py').write_text('publisher repair')
                git('commit', '-qam', 'repair publishing tools')
                self.assertNotEqual(gate.identity(), original)
                self.assertEqual(gate.identity(original['commit']), original)
                (root / 'src/plugin.php').write_text('different runtime')
                git('commit', '-qam', 'change runtime')
                with self.assertRaisesRegex(RuntimeError, 'outside the publication tools'):
                    gate.identity(original['commit'])


if __name__ == '__main__':
    unittest.main()
