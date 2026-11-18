#!/usr/bin/env python3
"""Real local SVN transactions and bounded fake HTTP, with no external writes."""
import hashlib
import http.client
import io
import json
from datetime import datetime, timedelta
from pathlib import Path
import shutil
import stat
import struct
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import urllib.error
import zipfile
import zlib

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'bin'))
import workspace
import wordpress_release as release

workspace.configure()


def png(width, height):
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data))
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress((b'\0' + b'\x44\x66\x88' * width) * height)) + chunk(b'IEND', b''))


def zip_bytes(files, *, comment=b''):
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, 'w', compression=zipfile.ZIP_DEFLATED) as output:
        output.comment = comment
        for name, value in files.items():
            info = zipfile.ZipInfo('cybermaps/' + name)
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            output.writestr(info, value)
    return stream.getvalue()


@unittest.skipUnless(shutil.which('svnadmin') and shutil.which('svnmucc'), 'SVN tools are required')
class WordPressPublicationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='wordpress-rehearsal-')
        self.addCleanup(self.temporary.cleanup)
        self.addCleanup(workspace.configure)
        self.base = Path(self.temporary.name)
        self.root = self.base / 'source'
        self.root.mkdir()
        self.patches = [patch.object(workspace, 'ROOT', self.root), patch.object(release, 'ROOT', self.root)]
        for context in self.patches:
            context.start()
            self.addCleanup(context.stop)
        workspace.configure()
        policy = self.root / 'docs/dev/release-policy.json'
        policy.parent.mkdir(parents=True)
        policy.write_text(json.dumps({'presentation': {'directory_screenshots': 8}}))
        self.repo = self.base / 'svn'
        self.command('svnadmin', 'create', self.repo)
        self.url = self.repo.as_uri()
        self.command('svnmucc', '-m', 'fixture', 'mkdir', self.url + '/cybermaps',
                     'mkdir', self.url + '/cybermaps/trunk', 'mkdir', self.url + '/cybermaps/tags',
                     'mkdir', self.url + '/cybermaps/assets', 'mkdir', self.url + '/unrelated')
        self.svn = release.SVN(self.url + '/cybermaps')
        self.number = '8.1.0'
        self.commit = 'a' * 40
        self.prepare()

    def command(self, *args):
        return subprocess.check_output(list(map(str, args)), stderr=subprocess.STDOUT, text=True).strip()

    def prepare(self, extra=None):
        captions = {str(n): 'Actual screen ' + str(n) for n in range(1, 9)}
        self.files = {
            'cybermaps.php': ('<?php\n/**\n * Version: ' + self.number + '\n */\n').encode(),
            'uninstall.php': b'<?php\n', 'changelog.txt': self.number.encode(), 'LICENSE': b'GPL-2.0-or-later',
            'readme.txt': ('=== CYBERMAPS ===\nStable tag: ' + self.number
                           + '\nRequires at least: 7.1\nRequires PHP: 8.2\n\nSitemaps and discovery.\n\n'
                           + '== Screenshots ==\n' + '\n'.join(n + '. ' + caption for n, caption in captions.items())
                           + '\n').encode(),
            'src/Example.php': b'<?php\n',
        }
        self.files.update(extra or {})
        directory = workspace.release_dir(self.number)
        directory.mkdir(parents=True, exist_ok=True)
        self.archive = directory / ('cybermaps_' + self.number + '.zip')
        self.archive.write_bytes(zip_bytes(self.files))
        self.digest = release.sha256(self.archive.read_bytes())
        Path(str(self.archive) + '.sha256').write_text(self.digest + '  ' + self.archive.name + '\n')
        descriptions = {}
        image_root = self.root / '.wordpress-org'
        image_root.mkdir(exist_ok=True)
        for name, size in [('icon-128x128.png', (128, 128)), ('icon-256x256.png', (256, 256)),
                           ('banner-772x250.png', (772, 250)), ('banner-1544x500.png', (1544, 500)),
                           *[('screenshot-' + n + '.png', (320, 240)) for n in captions]]:
            value = png(*size)
            (image_root / name).write_bytes(value)
            descriptions[name] = dict(sha256=release.sha256(value), width=size[0], height=size[1],
                                      source='Original fixture', license='GPL-2.0-or-later')
            if name.startswith('screenshot-'):
                descriptions[name]['caption'] = captions[name.split('-')[1].split('.')[0]]
                captured = directory / 'presentation' / name
                captured.parent.mkdir(parents=True, exist_ok=True)
                captured.write_bytes(value)
                descriptions[name].update(source_file=str(captured.relative_to(self.root)), source_sha256=release.sha256(value),
                                          package_manifest_sha256=release.manifest_sha256(self.files))
        path = self.root / 'docs/wporg/manifest.json'
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(dict(schema_version=1, version=self.number, files=descriptions)))
        self.publication = release.Publication(self.number, self.commit, self.archive, self.svn)
        self.evidence = directory / 'fixture-evidence.json'
        self.evidence.write_text('{"fixture":true}')

    def proof(self):
        return dict(identity=dict(commit=self.commit), zip_sha256=self.digest,
                    stable_checks=['everyday-workloads.json', 'native-upgrade-controlled.json', 'presentation/review.json'],
                    files={str(self.evidence.relative_to(self.root)): release.sha256(self.evidence.read_bytes())})

    def publish(self, **kwargs):
        return self.publication.publish(self.proof, **kwargs)

    def http(self, url):
        if url.startswith('https://api.wordpress.org/'):
            return json.dumps(dict(slug='cybermaps', version=self.number,
                                  download_link='https://downloads.wordpress.org/plugin/cybermaps.' + self.number + '.zip')).encode()
        if url.startswith('https://downloads.wordpress.org/'):
            return zip_bytes(self.files, comment=b'WordPress generated ZIP metadata differs')
        if url.startswith('https://ps.w.org/'):
            return (self.root / '.wordpress-org' / url.split('/')[-1].split('?')[0]).read_bytes()
        if url == release.LISTING:
            return ('<html><title>Cybermaps Directory listing</title><link rel="canonical" href="'
                    + release.LISTING + '"><p>Version ' + self.number + '</p></html>').encode()
        raise AssertionError(url)

    def test_atomic_first_release_and_idempotent_retry(self):
        before = self.svn.revision()
        record = self.publish()
        self.assertEqual(record['svn_revision'], before + 1)
        self.assertEqual(self.svn.files('trunk', before + 1), self.files)
        self.assertEqual(self.svn.files('tags/8.1.0', before + 1), self.files)
        self.assertEqual(self.command('svn', 'propget', 'svn:mime-type', self.svn.root + '/assets/icon-128x128.png'), 'image/png')
        self.publication.publish(lambda: self.fail('Committed release must not revalidate or rewrite'))
        self.assertEqual(self.svn.revision(), before + 1)

    def test_second_release_reconciles_files_without_replacing_roots(self):
        self.prepare({'src/retired/Old.php': b'old', 'assets/old.txt': b'old'})
        first = self.publish()
        self.number, self.commit = '8.1.1', 'b' * 40
        self.prepare({'src/New.php': b'new'})
        plan = self.publication.actions
        puts = set()

        def record_uploads(*args):
            actions = plan(*args)
            puts.update(actions[i + 2] for i, action in enumerate(actions) if action == 'put')
            return actions

        with patch.object(self.publication, 'actions', side_effect=record_uploads):
            second = self.publish()
        self.assertEqual(puts, {'tags/8.1.1/' + name for name in self.files} | {
            'trunk/cybermaps.php', 'trunk/readme.txt', 'trunk/changelog.txt', 'trunk/src/New.php'})
        self.assertEqual(self.svn.files('tags/8.1.1', second['svn_revision']), self.files)
        self.assertEqual(second['svn_revision'], first['svn_revision'] + 1)
        self.assertEqual(self.svn.files('trunk', second['svn_revision']), self.files)
        self.assertNotIn('src/retired/Old.php', self.files)
        changes = self.command('svn', 'log', '--verbose', '-r', second['svn_revision'], self.svn.root)
        self.assertNotRegex(changes, r'\s[RAD] /cybermaps/(trunk|assets)\n')
        self.assertIn('D /cybermaps/src', changes.replace('/trunk/', '/'))

    def test_concurrent_edit_to_an_unchanged_nested_file_blocks_the_patch(self):
        self.publish()
        self.number, self.commit = '8.1.1', 'b' * 40
        self.prepare()
        commit = self.svn.commit

        def change_file(*args, **kwargs):
            changed = self.base / 'changed.php'
            changed.write_text('a different nested file')
            self.command('svnmucc', '-m', 'concurrent edit', 'put', changed, self.svn.root + '/trunk/src/Example.php')
            commit(*args, **kwargs)

        with patch.object(self.svn, 'commit', side_effect=change_file), self.assertRaises(RuntimeError):
            self.publish()
        self.assertFalse(self.svn.exists('tags/8.1.1', self.svn.revision()))

    def test_unrelated_global_commit_does_not_block_release(self):
        def check():
            self.command('svnmucc', '-m', 'unrelated plugin', 'propset', 'fixture', 'one', self.url + '/unrelated')
            return self.proof()
        before = self.svn.revision()
        self.publication.publish(check)
        self.assertEqual(self.svn.revision(), before + 2)

    def test_owned_remote_change_after_preflight_is_not_adopted(self):
        def change():
            source = self.base / 'foreign.txt'
            source.write_text('Unreviewed remote change')
            self.command('svnmucc', '-m', 'foreign write', 'put', source, self.svn.root + '/assets/foreign.txt')
            return self.proof()
        with self.assertRaisesRegex(RuntimeError, 'Owned SVN paths changed'):
            self.publication.publish(change)
        self.assertFalse(self.svn.exists('tags/8.1.0', self.svn.revision()))

    def test_auth_failure_keeps_remote_unchanged(self):
        before = self.svn.revision()
        with patch.object(self.svn, 'commit', side_effect=release.AuthenticationRejected('Authentication rejected')):
            with self.assertRaisesRegex(RuntimeError, 'Authentication rejected'):
                self.publish()
        self.assertEqual(self.svn.revision(), before)
        self.assertEqual(self.publication.load()['state'], 'svn-not-committed')

    def test_ambiguous_success_is_queried_before_retry(self):
        actual = self.svn.commit
        def disconnected(*args, **kwargs):
            actual(*args, **kwargs)
            raise subprocess.TimeoutExpired('svnmucc', 300)
        with patch.object(self.svn, 'commit', side_effect=disconnected) as commit:
            record = self.publish()
            self.assertEqual(commit.call_count, 1)
        self.assertEqual(record['state'], 'svn-committed')
        self.publish()
        self.assertEqual(self.svn.revision(), record['svn_revision'])

    def test_preexisting_tag_is_never_adopted_or_replaced(self):
        self.command('svnmucc', '-m', 'another publication', 'mkdir', self.svn.root + '/tags/8.1.0')
        before = self.svn.revision()
        with self.assertRaisesRegex(RuntimeError, 'already exists'):
            self.publish()
        self.assertEqual(self.svn.revision(), before)

    def test_evidence_failure_happens_before_commit(self):
        before = self.svn.revision()
        def expired():
            raise RuntimeError('Evidence expired')
        with self.assertRaisesRegex(RuntimeError, 'Evidence expired'):
            self.publication.publish(expired)
        self.assertEqual(self.svn.revision(), before)

    def test_frozen_input_tampering_and_tag_mutation_are_rejected(self):
        self.publication.freeze()
        image = self.publication.inputs / 'assets/icon-128x128.png'
        image.write_bytes(b'tampered')
        with self.assertRaisesRegex(RuntimeError, 'Frozen Directory assets changed'):
            self.publish()
        image.write_bytes((self.root / '.wordpress-org/icon-128x128.png').read_bytes())
        self.publish()
        source = self.base / 'changed.php'
        source.write_text('bad')
        self.command('svnmucc', '-m', 'unauthorized tag change', 'put', source, self.svn.root + '/tags/8.1.0/cybermaps.php')
        before = self.svn.revision()
        with self.assertRaisesRegex(RuntimeError, 'another publication|Immutable WordPress tag'):
            self.publish()
        self.assertEqual(self.svn.revision(), before)

    def test_artwork_provenance_is_checked_before_any_commit(self):
        path = self.root / 'docs/wporg/manifest.json'
        data = json.loads(path.read_text())
        del data['files']['icon-128x128.png']['license']
        path.write_text(json.dumps(data))
        before = self.svn.revision()
        with self.assertRaisesRegex(RuntimeError, 'provenance'):
            self.publish()
        self.assertEqual(self.svn.revision(), before)

    def test_distribution_requires_byte_parity_and_actual_native_update(self):
        self.publish()
        with self.assertRaisesRegex(RuntimeError, 'real WordPress update'):
            self.publication.distribution(get=self.http)
        proof = dict(path='native-proof.json', sha256='f' * 64)
        result = self.publication.distribution(get=self.http, verify_upgrade=lambda: proof)
        self.assertEqual(result['state'], 'distribution-verified')
        self.assertEqual(result['wordpress']['packageManifestSha256'], release.manifest_sha256(self.files))
        second = self.publication.distribution(get=self.http, verify_upgrade=lambda: proof)
        self.assertEqual(result, second)

    def test_update_delay_is_saved_without_sleep_or_second_svn_commit(self):
        record = self.publish()
        def unavailable():
            raise release.OfferUnavailable('No real update offer yet')
        with self.assertRaises(release.Pending):
            self.publication.distribution(get=self.http, verify_upgrade=unavailable)
        state = json.loads((self.publication.directory / 'wordpress-distribution.json').read_text())
        self.assertEqual(state['state'], 'wordpress-update-pending')
        self.assertIn('next_check_at', state)
        self.assertEqual(self.svn.revision(), record['svn_revision'])

    def test_concurrent_child_addition_is_rejected_before_our_tag_commit(self):
        actual = self.svn.commit
        def addition(*args, **kwargs):
            source = self.base / 'foreign.txt'
            source.write_text('Concurrent unrelated file inside our trunk')
            self.command('svnmucc', '-m', 'race', 'put', source, self.svn.root + '/trunk/foreign.txt')
            actual(*args, **kwargs)
        with patch.object(self.svn, 'commit', side_effect=addition), self.assertRaises(RuntimeError):
            self.publish()
        self.assertFalse(self.svn.exists('tags/8.1.0', self.svn.revision()))

    def test_native_failure_is_not_misreported_as_cooldown(self):
        self.publish()
        def broken():
            raise RuntimeError('Installed files do not match')
        with self.assertRaisesRegex(RuntimeError, 'Installed files do not match'):
            self.publication.distribution(get=self.http, verify_upgrade=broken)
        self.assertFalse((self.publication.directory / 'wordpress-distribution.json').exists())

    def test_wrong_proof_is_rejected_before_any_irreversible_write(self):
        before = self.svn.revision()
        for change in ({'zip_sha256': '0' * 64}, {'identity': {'commit': 'b' * 40}}, {'stable_checks': []}):
            with self.subTest(change=change), self.assertRaisesRegex(RuntimeError, 'Stable publication evidence'):
                self.publication.publish(lambda: {**self.proof(), **change})
        self.assertEqual(self.svn.revision(), before)

    def test_unknown_absent_commit_holds_evidence_until_settling_then_rotates_attempt(self):
        with patch.object(self.svn, 'commit', side_effect=subprocess.TimeoutExpired('svnmucc', 300)):
            with self.assertRaisesRegex(release.Pending, 'still settling'):
                self.publish()
        record = self.publication.load()
        first = self.publication.attempts(record)[record['transaction']]
        original = json.loads(json.dumps(record))
        self.assertEqual(first['commit_timeout_seconds'], 15 * 60)
        twenty_minutes_later = (datetime.fromisoformat(first['commit_started_at']) + timedelta(minutes=20, seconds=1)).isoformat()
        with patch.object(release, 'now', return_value=twenty_minutes_later), self.assertRaises(release.Pending):
            self.publication.reconcile(record)
        with self.assertRaisesRegex(release.Pending, 'still settling'):
            self.publication.publish(lambda: self.fail('Uncertain publication must not refresh evidence'))
        self.assertEqual(record['publication_evidence'], original['publication_evidence'])
        later = (datetime.fromisoformat(first['commit_started_at'])
                 + timedelta(seconds=release.SVN_COMMIT_TIMEOUT + release.SVN_SERVER_MARGIN + 1)).isoformat()
        with patch.object(release, 'now', return_value=later):
            self.assertEqual(self.publication.reconcile(record)['state'], 'svn-not-committed')
            self.evidence.write_text('{"fixture":"second attempt"}')
            completed = self.publish()
        self.assertEqual(completed['state'], 'svn-committed')
        self.assertNotEqual(completed['transaction'], first['transaction'])
        self.assertEqual(len(completed['attempts']), 2)
        self.assertEqual(self.publication.attempts(completed)[first['transaction']], first)

    def test_legacy_attempt_keeps_its_original_twenty_minute_settling_window(self):
        with patch.object(self.svn, 'commit', side_effect=subprocess.TimeoutExpired('svnmucc', 300)):
            with self.assertRaises(release.Pending):
                self.publish()
        record = self.publication.load()
        attempt = self.publication.attempts(record)[record['transaction']]
        del attempt['commit_timeout_seconds']
        started = datetime.fromisoformat(attempt['commit_started_at'])
        with patch.object(self.publication, 'attempts', return_value={record['transaction']: attempt}):
            with patch.object(release, 'now', return_value=(started + timedelta(minutes=19)).isoformat()):
                with self.assertRaises(release.Pending):
                    self.publication.reconcile(record)
            with patch.object(release, 'now', return_value=(started + timedelta(minutes=20, seconds=1)).isoformat()):
                self.assertEqual(self.publication.reconcile(record)['state'], 'svn-not-committed')

    def test_late_first_commit_uses_its_original_snapshot_after_second_attempt_starts(self):
        actual, delayed = self.svn.commit, {}
        def timeout(actions, revision, message, username, password, *, uploads):
            saved = json.loads(self.publication.record_path.read_text())
            delayed.update(revision=revision, message=message, username=username, password=password,
                           uploads=uploads, record=saved)
            raise subprocess.TimeoutExpired('svnmucc', 300)
        with patch.object(self.svn, 'commit', side_effect=timeout), self.assertRaises(release.Pending):
            self.publish()
        first = delayed['record']
        self.evidence.write_text('{"fixture":"later evidence must never replace original proof"}')
        later = (datetime.fromisoformat(first['commit_started_at'])
                 + timedelta(seconds=release.SVN_COMMIT_TIMEOUT + release.SVN_SERVER_MARGIN + 1)).isoformat()
        def lands_during_retry(*args, **kwargs):
            actual(lambda private: self.publication.actions(first, private, delayed['uploads']['assets']),
                   delayed['revision'], delayed['message'], delayed['username'], delayed['password'],
                   uploads=delayed['uploads'])
            actual(*args, **kwargs)
        with patch.object(release, 'now', return_value=later), \
                patch.object(self.svn, 'commit', side_effect=lands_during_retry):
            completed = self.publish()
        self.assertEqual(completed['transaction'], first['transaction'])
        self.assertEqual(completed['publication_evidence'], first['publication_evidence'])
        self.assertEqual(len(completed['attempts']), 2)
        self.assertEqual(self.svn.files('tags/8.1.0', completed['svn_revision']), self.files)

    def test_unknown_outcome_without_attempt_cannot_be_blessed_by_absence(self):
        record = self.publication.freeze()
        self.publication.save(record, 'svn-outcome-unknown', base_revision=self.svn.revision())
        with self.assertRaisesRegex(RuntimeError, 'no retained attempt'):
            self.publication.reconcile(record)
        self.assertEqual(self.publication.load()['state'], 'svn-outcome-unknown')

    def test_attempt_record_tampering_blocks_recovery(self):
        record = self.publish()
        reference = record['attempts'][record['transaction']]
        (self.root / reference['path']).write_text('{}')
        with self.assertRaisesRegex(RuntimeError, 'attempt evidence changed'):
            self.publication.reconcile(record)

    def test_readiness_child_cannot_change_shared_staging_before_upload(self):
        self.publication.freeze()
        before = self.svn.revision()
        for relative in ('plugin/cybermaps.php', 'assets/icon-128x128.png'):
            source = self.publication.inputs / relative
            original = source.read_bytes()
            def change():
                source.write_bytes(b'Changed while readiness children were running')
                return self.proof()
            with self.subTest(relative=relative), patch.object(self.svn, 'commit') as commit:
                with self.assertRaisesRegex(RuntimeError, 'inputs changed before the transaction'):
                    self.publication.publish(change)
                commit.assert_not_called()
            source.write_bytes(original)
        self.assertEqual(self.svn.revision(), before)

    def test_svnmucc_reads_only_private_frozen_bytes_when_shared_inputs_change(self):
        actual = subprocess.run
        observed = []
        def change_shared(command, **kwargs):
            if command[0] == 'svnmucc':
                arguments = Path(command[command.index('-X') + 1])
                lines = arguments.read_text().splitlines()
                private = arguments.parent
                self.assertEqual(stat.S_IMODE(private.stat().st_mode), 0o700)
                for index, value in enumerate(lines):
                    if value == 'put':
                        source = Path(lines[index + 1])
                        self.assertTrue(source.is_relative_to(private))
                        self.assertFalse(source.is_relative_to(self.publication.inputs))
                        self.assertEqual(stat.S_IMODE(source.stat().st_mode), 0o400)
                        observed.append(source.read_bytes())
                for source in self.publication.inputs.rglob('*'):
                    if source.is_file():
                        source.write_bytes(b'Changed after private transaction preparation')
            return actual(command, **kwargs)
        with patch.object(subprocess, 'run', side_effect=change_shared):
            record = self.publish()
        self.assertTrue(observed)
        self.assertEqual(self.svn.files('trunk', record['svn_revision']), self.files)
        self.assertEqual(release.manifest(self.svn.files('assets', record['svn_revision'])), record['assets'])

    def test_crash_after_commit_retains_snapshot_when_original_gate_files_change(self):
        record = self.publish()
        self.evidence.write_text('A later local check rewrote its own current pointer')
        self.publication.save(record, 'svn-committing')
        record.pop('svn_revision')
        self.publication.save(record, 'svn-committing')
        recovered = self.publication.reconcile(self.publication.load())
        self.assertEqual(recovered['state'], 'svn-committed')
        self.assertEqual(self.svn.revision(), recovered['svn_revision'])

    def test_corrupt_wordpress_download_fails_closed(self):
        self.publish()
        def corrupt(url):
            if url.startswith('https://downloads.wordpress.org/'):
                return zip_bytes(dict(self.files, **{'cybermaps.php': b'changed'}))
            return self.http(url)
        with self.assertRaisesRegex(RuntimeError, 'file bytes differ'):
            self.publication.distribution(get=corrupt, verify_upgrade=lambda: self.fail('must not trust a different package'))

    def test_wordpress_404_is_pending_not_success(self):
        self.publish()
        def missing(url):
            raise urllib.error.HTTPError(url, 404, 'Not yet visible', {}, None)
        with self.assertRaises(release.Pending):
            self.publication.distribution(get=missing, verify_upgrade=lambda: {})

    def test_malformed_or_incomplete_api_response_is_pending(self):
        self.publish()
        for error in (None, http.client.IncompleteRead(b'partial', 20)):
            with self.subTest(error=error):
                def unavailable(url):
                    if error:
                        raise error
                    return b'<html>Temporary gateway error</html>'
                with self.assertRaises(release.Pending):
                    self.publication.distribution(get=unavailable, verify_upgrade=lambda: self.fail('No verified version'))

    def test_transient_failure_preserves_completed_distribution_evidence(self):
        self.publish()
        proof = dict(path='native-proof.json', sha256='f' * 64)
        original = self.publication.distribution(get=self.http, verify_upgrade=lambda: proof)
        path = self.publication.directory / 'wordpress-distribution.json'
        original_bytes = path.read_bytes()
        def offline(url):
            raise urllib.error.URLError('temporary network outage')
        with self.assertRaises(release.Pending):
            self.publication.distribution(get=offline, verify_upgrade=lambda: proof)
        self.assertEqual(path.read_bytes(), original_bytes)
        self.assertEqual(self.publication.distribution(get=self.http, verify_upgrade=lambda: proof), original)

    def test_new_https_commit_requires_password_before_any_child(self):
        svn = release.SVN()
        with patch.object(subprocess, 'run') as execute, self.assertRaisesRegex(RuntimeError, 'password-stdin'):
            svn.commit(['mkdir', 'tags/8.1.0'], 1, 'fixture', 'oreshkin')
        execute.assert_not_called()

    def test_secret_only_reaches_stdin(self):
        password = b'fixture-secret-only'
        result = subprocess.CompletedProcess([], 0, b'Committed', b'')
        with patch.object(subprocess, 'run', return_value=result) as execute:
            self.svn.commit(['mkdir', 'tags/8.1.0'], 1, 'fixture', 'oreshkin', password)
        command = execute.call_args.args[0]
        self.assertNotIn(password.decode(), str(command))
        self.assertEqual(execute.call_args.kwargs['timeout'], 15 * 60)
        self.assertIn('--password-from-stdin', command)
        self.assertIn('--config-dir', command)
        self.assertIn('config:auth:password-stores=', command)
        self.assertEqual(execute.call_args.kwargs['input'], password + b'\n')


class DownloadSafetyTest(unittest.TestCase):
    def test_traversal_links_and_duplicates_are_rejected(self):
        for name, mode in [('cybermaps/../escape', stat.S_IFREG), ('cybermaps/link', stat.S_IFLNK)]:
            with self.subTest(name=name):
                stream = io.BytesIO()
                with zipfile.ZipFile(stream, 'w') as output:
                    item = zipfile.ZipInfo(name)
                    item.external_attr = (mode | 0o644) << 16
                    output.writestr(item, b'bad')
                with self.assertRaises(RuntimeError):
                    release.normalized_zip(stream.getvalue())


if __name__ == '__main__':
    unittest.main()
