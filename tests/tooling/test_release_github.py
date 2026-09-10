#!/usr/bin/env python3
"""Integration tests use real temporary Git repositories; no network or releases."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


def mock():
    args = sys.argv[2:]
    state_path = Path(os.environ['MOCK_STATE'])
    state = json.loads(state_path.read_text()) if state_path.exists() else None
    failure = os.environ.get('MOCK_FAIL', '')
    def save():
        state_path.write_text(json.dumps(state))
    def option(name):
        return args[args.index(name) + 1]
    if sys.argv[1] == 'composer':
        with open(str(state_path) + '.checks', 'a') as log:
            log.write(' '.join(args) + '\n')
        if failure == 'source-change':
            Path('cybermaps.php').write_text(' * Version: 0.0.0\n')
        if failure == 'checks':
            sys.exit(1)
        if args == ['run', 'release:build']:
            Path('clean').mkdir(exist_ok=True)
            p = Path('clean/cybermaps_7.4.0.zip')
            p.write_bytes(b'deterministic ZIP fixture')
            Path(str(p) + '.sha256').write_text(
                ('bad' if failure == 'checksum' else hashlib.sha256(p.read_bytes()).hexdigest())
                + '  ' + p.name + '\n')
        return
    if args[0] == 'auth':
        sys.exit(1 if failure == 'auth' else 0)
    if args[0] == 'api':
        if failure == 'api':
            sys.exit(1)
        print(json.dumps([[state] if state else []]))
        return
    operation = args[1]
    if operation == 'create':
        assert state is None and '--draft' in args and '--verify-tag' in args
        state = dict(id=1, tag_name=args[2], target_commitish=option('--target'),
                     draft=True, prerelease='--prerelease=true' in args,
                     name=option('--title'), body=Path(option('--notes-file')).read_text(), assets=[])
    elif operation == 'upload':
        assert state['draft'] and '--clobber' not in args
        p = Path(args[3])
        if failure == 'interrupt' and p.name.endswith('.sha256'):
            sys.exit(1)
        assert p.name not in [a['name'] for a in state['assets']]
        state['assets'].append(dict(id=len(state['assets']) + 1, name=p.name, content=p.read_bytes().hex()))
    elif operation == 'download':
        a = next(a for a in state['assets'] if a['name'] == option('--pattern'))
        content = bytes.fromhex(a['content'])
        Path(option('--dir'), a['name']).write_bytes(b'corrupt' if failure == 'download' else content)
    elif operation == 'edit':
        assert state['draft'] and len(state['assets']) == 2 and '--draft=false' in args
        assert ('--latest=true' in args) == (not state['prerelease'])
        state['draft'] = False
    else:
        raise AssertionError(args)
    save()


class PublisherTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.repo = self.base / 'source'
        self.repo.mkdir()
        self.remote = self.base / 'remote.git'
        self.env = os.environ.copy()
        for key in list(self.env):
            if key.startswith(('GIT_', 'GH_', 'MOCK_')):
                del self.env[key]
        self.env.update(GIT_CONFIG_NOSYSTEM='1', GIT_CONFIG_GLOBAL='/dev/null',
                        MOCK_STATE=str(self.base / 'state.json'))
        self.git('init', '--bare', str(self.remote))
        self.git('init', '-b', 'main')
        self.git('config', 'user.name', 'Release Test')
        self.git('config', 'user.email', 'test@example.invalid')
        self.git('config', 'url.' + str(self.remote) + '.insteadOf',
                 'https://github.com/Alex9001/cybermaps.git')
        (self.repo / 'bin').mkdir()
        for name in ['release-github.sh', 'release-github.py']:
            shutil.copy(ROOT / 'bin' / name, self.repo / 'bin' / name)
        (self.repo / 'cybermaps.php').write_text(' * Version: 7.4.0\n')
        (self.repo / 'readme.txt').write_text('Requires at least: 7.0\nRequires PHP: 8.2\n')
        (self.repo / 'changelog.txt').write_text('7.4.0\n-----\n\n* Release test.\n\n7.3.0\n-----\n* Older.\n')
        (self.repo / '.gitignore').write_text('clean/\n')
        self.git('add', '.')
        self.git('commit', '-m', 'fixture')
        self.git('push', str(self.remote), 'main')
        executables = self.base / 'mock-bin'
        executables.mkdir()
        for name in ['gh', 'composer']:
            p = executables / name
            p.write_text('#!' + sys.executable + '\nimport runpy,sys\nsys.argv = ['
                         + repr(str(Path(__file__).resolve())) + ', ' + repr(name)
                         + '] + sys.argv[1:]\nrunpy.run_path(sys.argv[0], run_name="__main__")\n')
            p.chmod(0o755)
        self.env['PATH'] = str(executables) + os.pathsep + self.env['PATH']

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.repo, env=self.env,
                                       stderr=subprocess.DEVNULL, text=True).strip()

    def state(self):
        p = Path(self.env['MOCK_STATE'])
        return json.loads(p.read_text()) if p.exists() else None

    def publish(self, success=True, stable=False, failure=''):
        result = subprocess.run(['bash', 'bin/release-github.sh'] + (['--stable'] if stable else []),
                                cwd=self.repo, env=dict(self.env, MOCK_FAIL=failure),
                                text=True, capture_output=True)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        if not success:
            self.assertTrue(self.state() is None or self.state()['draft'])
        return result

    def test_beta_and_no_overwrite(self):
        self.publish()
        state = self.state()
        self.assertFalse(state['draft'])
        self.assertTrue(state['prerelease'])
        self.assertEqual(len(state['assets']), 2)
        self.assertIn('diagnostic support bundle', state['body'])
        self.assertNotIn('Older', state['body'])
        self.assertEqual(self.git('rev-parse', 'HEAD'), self.git('rev-parse', 'v7.4.0'))
        result = subprocess.run(['bash', 'bin/release-github.sh'], cwd=self.repo, env=self.env, capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(state, self.state())

    def test_stable(self):
        self.publish(stable=True)
        self.assertFalse(self.state()['prerelease'])

    def test_dirty(self):
        (self.repo / 'untracked').touch()
        self.assertIn('Uncommitted', self.publish(False).stderr)

    def test_unpushed(self):
        self.git('commit', '--allow-empty', '-m', 'unpushed')
        self.assertIn('pushed main', self.publish(False).stderr)

    def test_wrong_branch(self):
        self.git('checkout', '-b', 'feature')
        self.publish(False)

    def test_failed_checks(self):
        self.publish(False, failure='checks')
        self.assertEqual(self.git('tag'), '')

    def test_auth_failure(self):
        self.publish(False, failure='auth')

    def test_api_failure(self):
        self.publish(False, failure='api')

    def test_local_checksum(self):
        self.publish(False, failure='checksum')
        self.assertEqual(self.git('tag'), '')

    def test_download_mismatch_then_resume(self):
        self.publish(False, failure='download')
        self.publish()

    def test_interrupted_upload_then_resume(self):
        self.publish(False, failure='interrupt')
        self.assertEqual(len(self.state()['assets']), 1)
        self.publish()

    def test_resume_mode_conflict(self):
        self.publish(False, failure='interrupt')
        self.publish(False, stable=True)

    def test_existing_artifact_conflict(self):
        self.publish(False, failure='interrupt')
        state = self.state()
        state['assets'][0]['content'] = b'wrong'.hex()
        Path(self.env['MOCK_STATE']).write_text(json.dumps(state))
        self.publish(False)

    def test_unexpected_asset(self):
        self.publish(False, failure='interrupt')
        state = self.state()
        state['assets'].append(dict(id=9, name='unexpected', content=''))
        Path(self.env['MOCK_STATE']).write_text(json.dumps(state))
        self.publish(False)

    def test_source_changes_during_checks(self):
        self.publish(False, failure='source-change')
        self.assertEqual(self.git('tag'), '')

    def test_matching_pushed_annotated_tag(self):
        self.git('tag', '-a', 'v7.4.0', '-m', 'release')
        self.git('push', str(self.remote), 'v7.4.0')
        self.publish()

    def test_draft_metadata_conflict(self):
        self.publish(False, failure='interrupt')
        state = self.state()
        state['target_commitish'] = 'different-commit'
        Path(self.env['MOCK_STATE']).write_text(json.dumps(state))
        self.publish(False)

    def test_missing_changelog(self):
        (self.repo / 'changelog.txt').write_text('No matching section')
        self.git('add', '.')
        self.git('commit', '-m', 'missing notes')
        self.git('push', str(self.remote), 'main')
        self.publish(False)
        self.assertEqual(self.git('tag'), '')

    def test_tag_conflicts(self):
        old = self.git('rev-parse', 'HEAD')
        self.git('commit', '--allow-empty', '-m', 'next')
        self.git('push', str(self.remote), 'main')
        self.git('tag', 'v7.4.0', old)
        self.publish(False)
        self.git('push', str(self.remote), 'v7.4.0')
        self.git('tag', '-d', 'v7.4.0')
        self.publish(False)


if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] in ('gh', 'composer'):
        mock()
    else:
        unittest.main()
