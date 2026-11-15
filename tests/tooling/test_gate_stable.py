"""Stable release checks fail on stale copy, evidence and upgrade baselines."""
from datetime import datetime, timedelta, timezone
from copy import deepcopy
import hashlib
import json
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'bin'))
import stable_release_checks as checks
from workspace import configure


class StableChecksTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='stable-evidence-', dir=configure())
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.directory = self.root / 'docs/generated/releases/8.1.0'
        (self.directory / 'presentation').mkdir(parents=True)
        for name in ('README.md', 'SUPPORT.md', '.github/ISSUE_TEMPLATE/bug_report.yml'):
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('Current stable support')
        for target, value in [('ROOT', self.root), ('release_dir', lambda _: self.directory),
                              ('package_files', lambda _: ({}, 'b' * 64))]:
            context = patch.object(checks, target, value)
            context.start()
            self.addCleanup(context.stop)
        self.contents = {'src/Admin/SettingsPage.php': b'https://github.com/Alex9001/cybermaps/issues '
                         b'https://wordpress.org/support/plugin/cybermaps/'}
        policy = self.root / 'docs/dev/release-policy.json'
        policy.parent.mkdir(parents=True)
        policy.write_text(json.dumps({'presentation': {'website_screenshots': 14}}))
        captures, evidence = [], {}
        for number in range(14):
            path = self.directory / 'presentation' / ('capture-' + str(number) + '.png')
            path.write_bytes(('actual image ' + str(number)).encode())
            digest = hashlib.sha256(path.read_bytes()).hexdigest()
            captures.append(dict(id=str(number), sha256=digest))
            evidence[str(path.relative_to(self.root))] = digest
        assets = self.root / 'docs/wporg/manifest.json'
        assets.parent.mkdir(parents=True)
        assets.write_text(json.dumps(dict(files={'screenshot-' + str(i + 1) + '.png': {'sha256': capture['sha256']}
                                                 for i, capture in enumerate(captures[:8])})))
        self.evidence = self.directory / 'presentation/capture-manifest.json'
        self.evidence.write_text(json.dumps(dict(version='8.1.0', origin='http://127.0.0.1:45613',
                                                zip_sha256='b' * 64, captures=captures,
                                                passed=True, default_passed=True, configured_passed=True, debug_clean=True)))
        evidence[str(self.evidence.relative_to(self.root))] = hashlib.sha256(self.evidence.read_bytes()).hexdigest()
        for name, value in {
            'fixture.json': dict(version='8.1.0', zip_sha256='b' * 64, passed=True),
            'browser.json': dict(passed=True, page_errors=[], console_errors=[], failures=[]),
            'default-states.json': dict(passed=True, page_errors=[], console_errors=[], failures=[]),
            'installed-files.json': dict(zip_sha256='b' * 64, files={}),
        }.items():
            path = self.directory / 'presentation' / name
            path.write_text(json.dumps(value))
            evidence[str(path.relative_to(self.root))] = hashlib.sha256(path.read_bytes()).hexdigest()
        self.review = dict(schema_version=1, state='presentation-verified', version='8.1.0',
                           commit='a' * 40, zip_sha256='b' * 64, reviewer='Fixture reviewer',
                           reviewed_at=datetime.now(timezone.utc).isoformat(),
                           checks={name: True for name in checks.PRESENTATION_CHECKS},
                           evidence=evidence)

    def test_stable_copy_keeps_issues_without_retired_beta_recruitment(self):
        checks.current_copy(self.contents)
        for change in (b'Open beta feedback', b'Help shape the beta', b'This open beta'):
            with self.subTest(change=change), self.assertRaises(RuntimeError):
                checks.current_copy(dict(self.contents, **{'src/Example.php': change}))
        # A protocol's status is not a product-release claim.
        checks.current_copy(dict(self.contents, **{'src/Protocol.php': b'Protocol beta draft'}))
        with self.assertRaisesRegex(RuntimeError, 'preserve GitHub issues'):
            checks.current_copy({'src/Admin/SettingsPage.php': b'Help is elsewhere'})

    def test_old_badge_cannot_silently_promote_prereleases(self):
        (self.root / 'README.md').write_text('badge?include_prereleases=true')
        with self.assertRaises(RuntimeError):
            checks.current_copy(self.contents)

    def test_visual_review_requires_current_zip_every_journey_and_actual_files(self):
        path = self.directory / 'presentation/review.json'
        path.write_text(json.dumps(self.review))
        checks.presentation('8.1.0', 'b' * 64, 'a' * 40, 24)
        for change in ({'commit': 'c' * 40}, {'zip_sha256': 'd' * 64},
                       {'reviewed_at': (datetime.now(timezone.utc) - timedelta(days=2)).isoformat()},
                       {'checks': {**self.review['checks'], 'keyboard': False}},
                       {'checks': {**self.review['checks'], 'keyboard': 1}},
                       {'evidence': {}}):
            with self.subTest(change=change):
                path.write_text(json.dumps({**self.review, **change}))
                with self.assertRaises(RuntimeError):
                    checks.presentation('8.1.0', 'b' * 64, 'a' * 40, 24)
        path.write_text(json.dumps(self.review))
        self.evidence.write_text('changed screenshot or capture provenance')
        with self.assertRaisesRegex(RuntimeError, 'evidence changed'):
            checks.presentation('8.1.0', 'b' * 64, 'a' * 40, 24)

    def nonce_browser(self):
        return dict(passed=True, page_errors=[], failures=[], phase='configured',
                    origin='http://127.0.0.1:45613', version='8.1.0', zip_sha256='b' * 64,
                    console_errors=[dict(url='http://127.0.0.1:45613/wp-admin/admin.php?page=cybermaps-system-status',
                        text='Failed to load resource: the server responded with a status of 403 (Forbidden)', expected=True)],
                    interactions=[dict(name='destructive-log-clear-authority-and-confirmation', passed=True,
                        detail=dict(missing_nonce=dict(status=403, body='-1'), confirmation_dismissed=True,
                                    confirmed_status=200, confirmed_result='Diagnostic events cleared.'))])

    def browser_review(self, browser, name='browser.json'):
        path = self.directory / 'presentation' / name
        path.write_text(json.dumps(browser))
        self.review['evidence'][str(path.relative_to(self.root))] = hashlib.sha256(path.read_bytes()).hexdigest()
        (self.directory / 'presentation/review.json').write_text(json.dumps(self.review))
        return checks.presentation('8.1.0', 'b' * 64, 'a' * 40, 24)

    def test_actual_missing_nonce_console_observation_keeps_raw_evidence(self):
        browser = self.nonce_browser()
        self.browser_review(browser)
        path = self.directory / 'presentation/browser.json'
        self.assertEqual(json.loads(path.read_text()), browser)
        self.assertEqual(self.review['evidence'][str(path.relative_to(self.root))], hashlib.sha256(path.read_bytes()).hexdigest())

    def test_expected_flag_cannot_hide_unrelated_or_unproven_console_errors(self):
        original = self.nonce_browser()
        changes = [
            ('unrelated text', lambda value: value['console_errors'][0].update(text='TypeError: failed to load 403')),
            ('wrong page', lambda value: value['console_errors'][0].update(url=original['origin'] + '/wp-admin/admin-ajax.php')),
            ('wrong origin', lambda value: value['console_errors'][0].update(url='http://127.0.0.1:45614/wp-admin/admin.php?page=cybermaps-system-status')),
            ('duplicate errors', lambda value: value['console_errors'].append(deepcopy(value['console_errors'][0]))),
            ('nonboolean expected', lambda value: value['console_errors'][0].update(expected=1)),
            ('unexpected error', lambda value: value['console_errors'][0].update(expected=False)),
            ('missing interaction', lambda value: value.update(interactions=[])),
            ('duplicate interaction', lambda value: value['interactions'].append(deepcopy(value['interactions'][0]))),
            ('failed interaction', lambda value: value['interactions'][0].update(passed=False)),
            ('nonboolean interaction', lambda value: value['interactions'][0].update(passed=1)),
            ('wrong denial', lambda value: value['interactions'][0]['detail']['missing_nonce'].update(status=401)),
            ('nonnumeric denial', lambda value: value['interactions'][0]['detail']['missing_nonce'].update(status='403')),
            ('floating denial', lambda value: value['interactions'][0]['detail']['missing_nonce'].update(status=403.0)),
            ('wrong body', lambda value: value['interactions'][0]['detail']['missing_nonce'].update(body='-1\n')),
            ('missing denial', lambda value: value['interactions'][0]['detail'].pop('missing_nonce')),
            ('confirmation failed', lambda value: value['interactions'][0]['detail'].update(confirmation_dismissed=False)),
            ('authorized action failed', lambda value: value['interactions'][0]['detail'].update(confirmed_status=403)),
            ('wrong success result', lambda value: value['interactions'][0]['detail'].update(confirmed_result='Error')),
            ('wrong phase', lambda value: value.update(phase='default')),
            ('wrong capture origin', lambda value: value.update(origin='http://127.0.0.1:45614')),
            ('wrong candidate', lambda value: value.update(zip_sha256='c' * 64)),
            ('wrong version', lambda value: value.update(version='8.0.4')),
            ('page error', lambda value: value.update(page_errors=['Actual uncaught error'])),
            ('recorded failure', lambda value: value.update(failures=['Failed journey'])),
        ]
        for name, change in changes:
            with self.subTest(name=name):
                browser = deepcopy(original)
                change(browser)
                with self.assertRaisesRegex(RuntimeError, 'Presentation browser checks failed'):
                    self.browser_review(browser)

    def test_default_state_receipt_still_rejects_every_console_error(self):
        with self.assertRaisesRegex(RuntimeError, 'Presentation browser checks failed: default-states.json'):
            self.browser_review(self.nonce_browser(), 'default-states.json')

    def test_only_first_stable_can_use_beta_baseline_when_no_stable_exists(self):
        path = self.root / 'docs/dev/release-policy.json'
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(dict(first_stable_version='8.1.0',
                                       upgrade_baseline=dict(version='8.0.4', zip_sha256='c' * 64))))
        with patch.object(checks, 'run', return_value='[[]]'):
            checks.baseline('8.1.0')
            with self.assertRaisesRegex(RuntimeError, 'Only the first stable'):
                checks.baseline('8.1.1')
        releases = [[dict(draft=False, prerelease=False, tag_name='v8.1.0', published_at='2026-11-15')]]
        with patch.object(checks, 'run', return_value=json.dumps(releases)) as run:
            with self.assertRaisesRegex(RuntimeError, 'latest published stable'):
                checks.baseline('8.1.1')
            self.assertEqual(run.call_count, 1)

    def test_upgrade_uses_frozen_policy_even_after_working_policy_changes(self):
        path = self.directory / 'native-upgrade-wordpress.json'
        path.write_text('{"fixture":"reviewed native proof"}')
        baseline = dict(version='8.0.4', zip_sha256='c' * 64)
        (self.root / 'docs/dev/release-policy.json').write_text(json.dumps(dict(
            upgrade_baseline=dict(version='8.1.0', zip_sha256='d' * 64))))
        receipt = dict(phase='wordpress', baseline_version=baseline['version'],
                       baseline_zip_sha256=baseline['zip_sha256'], validated_at='fixture')
        seen = []
        validator = SimpleNamespace(validate_receipt=lambda *args, **kwargs: receipt,
                                    baseline_policy=lambda commit: (seen.append(commit) or baseline))
        with patch.object(checks, 'module', return_value=validator):
            proof = checks.upgrade('8.1.0', 'b' * 64, 'a' * 40, 'wordpress', None)
        self.assertEqual(seen, ['a' * 40])
        self.assertEqual(proof['sha256'], hashlib.sha256(path.read_bytes()).hexdigest())


if __name__ == '__main__':
    unittest.main()
