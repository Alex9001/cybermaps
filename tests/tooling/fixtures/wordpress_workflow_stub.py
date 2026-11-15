"""Offline dependency fixture for workflow ordering tests, never production code."""
import json
import os
from pathlib import Path
from workspace import package_files, release_dir, save_json


class Pending(RuntimeError):
    pass


class OfferUnavailable(RuntimeError):
    pass


def log(value):
    with Path(os.environ['MOCK_STATE'] + '.commands').open('a') as output:
        output.write(json.dumps(value) + '\n')


class Publication:
    def __init__(self, number, commit, archive):
        self.number, self.commit, self.archive = number, commit, archive
        self.directory = release_dir(number)
        self.record_path = self.directory / 'wordpress-publication.json'
        self.zip_sha256 = package_files(archive)[1]
        self.tag, self.svn = 'tags/' + number, self

    def revision(self):
        return 42

    def exists(self, tag, head):
        return bool((self.load() or {}).get('svn_revision'))

    def load(self):
        return json.loads(self.record_path.read_text()) if self.record_path.exists() else None

    def freeze(self, reset_unpublished=False):
        if self.load():
            return self.load()
        value = dict(version=self.number, commit=self.commit, tag='v' + self.number, zip_sha256=self.zip_sha256,
                     state='inputs-frozen')
        save_json(self.record_path, value)
        save_json(self.directory / 'native-upgrade-wordpress.json', dict(phase='wordpress'))
        return value

    def verify_committed(self, value):
        assert value['svn_revision'] == 42
        log(['wordpress', 'verify-committed'])
        return value

    def reconcile(self, value):
        return self.verify_committed(value) if value.get('svn_revision') else value

    def publish(self, before_commit, username=None, password=None):
        value = self.freeze()
        if value.get('svn_revision'):
            return self.verify_committed(value)
        before_commit()
        if os.environ.get('MOCK_FAIL') == 'svn-auth':
            raise RuntimeError('Injected SVN authentication failure')
        log(['svnmucc', 'commit'])
        value['svn_revision'] = 42
        value['state'] = 'svn-committed'
        save_json(self.record_path, value)
        return value

    def distribution(self, verify_upgrade):
        if os.environ.get('MOCK_FAIL') == 'wordpress-pending':
            save_json(self.directory / 'wordpress-distribution.json',
                      dict(state='wordpress-distribution-pending', next_check_at='2026-11-15T12:00:00+00:00'))
            raise Pending('Injected WordPress cooldown')
        verify_upgrade()
        log(['wordpress', 'distribution-verified'])
        value = dict(state='distribution-verified', wordpress=dict(slug='cybermaps', version=self.number,
                     svnRevision=42, updateVerified=True))
        save_json(self.directory / 'wordpress-distribution.json', value)
        return value
