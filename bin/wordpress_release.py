#!/usr/bin/env python3
"""Immutable WordPress.org publication using URL operations, never a checkout.

The package and separately licensed Directory images are frozen before the first
remote write. A saved intent identifies an ambiguous commit; a matching tag alone
does not authorize adoption or replacement of somebody else's publication.
"""
from datetime import datetime, timedelta, timezone
import hashlib
import http.client
import io
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import stat
import subprocess
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import uuid
import xml.etree.ElementTree as ET
import zipfile

from workspace import ROOT, configure, package_files, release_dir, require, safe_path, save_json

SLUG = 'cybermaps'
SVN_ROOT = 'https://plugins.svn.wordpress.org/cybermaps'
LISTING = 'https://wordpress.org/plugins/cybermaps/'
MAX_FILES = 10000
MAX_BYTES = 128 * 1024 * 1024
SVN_COMMIT_TIMEOUT = 15 * 60
SVN_SERVER_MARGIN = 15 * 60


class Pending(RuntimeError):
    """A published immutable release is waiting for a downstream service."""


class OfferUnavailable(RuntimeError):
    """A successful official update API response does not advertise this version yet."""


class AuthenticationRejected(RuntimeError):
    """The server rejected authentication before opening a commit transaction."""


class Listing(HTMLParser):
    def __init__(self):
        super().__init__()
        self.canonical, self.title, self.in_title = None, '', False

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'link' and attrs.get('rel') == 'canonical':
            self.canonical = attrs.get('href')
        if tag == 'title':
            self.in_title = True

    def handle_endtag(self, tag):
        if tag == 'title':
            self.in_title = False

    def handle_data(self, text):
        if self.in_title:
            self.title += text


def now():
    return datetime.now(timezone.utc).isoformat()


def sha256(value):
    return hashlib.sha256(value).hexdigest()


def manifest(contents):
    return {name: sha256(value) for name, value in sorted(contents.items())}


def manifest_sha256(contents):
    # Shared with the website. Paths are relative to the cybermaps/ ZIP root.
    return sha256(json.dumps(manifest(contents), sort_keys=True, separators=(',', ':'),
                             ensure_ascii=False).encode())


def tree(directory):
    directory = safe_path(directory)
    result = {}
    for path in sorted(directory.rglob('*')):
        safe_path(path)
        require(path.is_dir() or stat.S_ISREG(path.lstat().st_mode), 'Special file in release input')
        if path.is_file():
            result[path.relative_to(directory).as_posix()] = path.read_bytes()
    require(len(result) <= MAX_FILES and sum(map(len, result.values())) <= MAX_BYTES,
            'Release tree exceeds bounded input limits')
    return result


def normalized_zip(payload):
    """Allow WordPress's ZIP metadata differences, but reject unsafe file entries."""
    require(len(payload) <= MAX_BYTES, 'WordPress download exceeds the package limit')
    result, seen = {}, set()
    with zipfile.ZipFile(io.BytesIO(payload)) as archive:
        require(len(archive.infolist()) <= MAX_FILES
                and sum(item.file_size for item in archive.infolist()) <= MAX_BYTES,
                'WordPress package exceeds file count or size limits')
        for item in archive.infolist():
            name = item.filename.rstrip('/')
            parts = name.split('/')
            require(parts[0] == SLUG and all(p and p not in ('.', '..') and not p.startswith('.') for p in parts)
                    and not re.search(r'[\\\x00-\x1f\x7f<>:"|?*]', name)
                    and name.casefold() not in seen, 'Unsafe or duplicate WordPress ZIP entry')
            seen.add(name.casefold())
            mode = item.external_attr >> 16
            kind = stat.S_IFMT(mode)
            require(kind in (0, stat.S_IFDIR if item.is_dir() else stat.S_IFREG),
                    'WordPress ZIP links or special files are forbidden')
            if not item.is_dir():
                require(len(parts) > 1, 'Invalid WordPress package root')
                result['/'.join(parts[1:])] = archive.read(item)
    require(result, 'WordPress package is empty')
    return result


def assets(number):
    path = safe_path(ROOT / 'docs/wporg/manifest.json')
    description = json.loads(path.read_text())
    require(description.get('schema_version') == 1 and description.get('version') == number
            and isinstance(description.get('files'), dict), 'Directory asset manifest is missing or stale')
    contents = tree(ROOT / '.wordpress-org')
    require(set(contents) == set(description['files']), 'Directory asset inventory differs from its manifest')
    required = {'icon-128x128.png', 'icon-256x256.png', 'banner-772x250.png', 'banner-1544x500.png'}
    require(required <= contents.keys(), 'Directory icons or banners are missing')
    for name, value in contents.items():
        require(re.fullmatch(r'(?:icon-(?:128x128|256x256)|banner-(?:772x250|1544x500)|screenshot-[1-9][0-9]?)\.png', name),
                'Unsupported Directory asset name: ' + name)
        metadata = description['files'][name]
        require(isinstance(metadata, dict) and metadata.get('sha256') == sha256(value)
                and all(isinstance(metadata.get(k), str) and metadata[k].strip() for k in ('source', 'license')),
                'Missing asset provenance or changed image: ' + name)
        require(value.startswith(b'\x89PNG\r\n\x1a\n') and value[12:16] == b'IHDR', 'Invalid PNG: ' + name)
        dimensions = (int.from_bytes(value[16:20], 'big'), int.from_bytes(value[20:24], 'big'))
        require(dimensions == (metadata.get('width'), metadata.get('height'))
                and all(0 < n <= 12000 for n in dimensions), 'Image dimensions do not match: ' + name)
        if name in required:
            expected = tuple(map(int, name.split('-')[1].removesuffix('.png').split('x')))
            require(dimensions == expected, 'Directory artwork has incorrect dimensions: ' + name)
        limit = 10 if name.startswith('screenshot-') else (4 if name.startswith('banner-') else 1)
        require(len(value) <= limit * 1024 * 1024, 'Directory image is too large: ' + name)
    return contents, description, sha256(path.read_bytes())


def validate_presentation(number, contents):
    """Deterministic checks complement, and never substitute for, editorial review."""
    images, description, _ = assets(number)
    text = contents['readme.txt'].decode()
    require(len(text.encode()) <= 10000, 'Directory readme exceeds 10 KB')
    screenshot_section = re.search(r'^== Screenshots ==\s*\n(.*?)(?=^== |\Z)', text, re.M | re.S)
    require(screenshot_section, 'Directory screenshot captions are missing')
    captions = dict(re.findall(r'^(\d+)\. (.+)$', screenshot_section[1], re.M))
    screenshots = {name for name in images if name.startswith('screenshot-')}
    policy = json.loads((ROOT / 'docs/dev/release-policy.json').read_text())
    count = policy['presentation']['directory_screenshots']
    require(len(captions) == count and set(captions) == {str(n) for n in range(1, count + 1)}
            and screenshots == {'screenshot-' + n + '.png' for n in captions},
            'Directory screenshots and numbered captions differ')
    for number, caption in captions.items():
        metadata = description['files']['screenshot-' + number + '.png']
        require(metadata.get('caption') == caption,
                'Screenshot caption differs from reviewed provenance')
        source = safe_path(ROOT / metadata['source_file'])
        require(source.is_relative_to(release_dir(description['version']))
                and metadata.get('source_sha256') == metadata['sha256'] == sha256(source.read_bytes())
                and metadata.get('package_manifest_sha256') == manifest_sha256(contents),
                'Directory screenshot is not bound to an actual capture of the exact candidate')
    header, _, rest = text.partition('\n\n')
    short = rest.split('\n', 1)[0].strip()
    require(0 < len(short) <= 150, 'Directory short description must contain 1–150 characters')
    require(re.search(r'^Stable tag:\s*' + re.escape(description['version']) + r'\s*$', header, re.M),
            'Directory stable tag differs from the release')
    require(not re.search(r'^\s*\*?\s*Requires Plugins:', contents['cybermaps.php'].decode(), re.M),
            'Optional MCP must not become a mandatory plugin dependency')
    require(not re.search(r'^\s*\*?\s*Update URI:', contents['cybermaps.php'].decode(), re.M),
            'Core must use native WordPress.org updates')


class SVN:
    def __init__(self, root=SVN_ROOT):
        require(root == SVN_ROOT or root.startswith('file:///'), 'Unexpected SVN destination')
        self.root = root.rstrip('/')

    def command(self, *args):
        try:
            result = subprocess.run(['svn', '--non-interactive', *map(str, args)],
                                    text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=180)
        except (subprocess.TimeoutExpired, OSError) as error:
            raise RuntimeError('SVN read unavailable; remote publication remains unconfirmed: ' + str(error)) from error
        require(result.returncode == 0, 'SVN read failed: ' + result.stderr.strip()[:2000])
        if '--xml' in args:
            try:
                ET.fromstring(result.stdout)
            except ET.ParseError as error:
                raise RuntimeError('SVN returned malformed XML; publication cannot be verified') from error
        return result.stdout

    def revision(self):
        value = self.command('info', '--show-item', 'revision', self.root).strip()
        require(value.isdigit(), 'Invalid SVN revision')
        return int(value)

    def names(self, revision):
        doc = ET.fromstring(self.command('list', '--xml', '-r', revision, self.root + '@' + str(revision)))
        return {entry.findtext('name'): entry.attrib['kind'] for entry in doc.findall('.//entry')}

    def exists(self, path, revision):
        parent, name = path.rsplit('/', 1) if '/' in path else ('', path)
        url = self.root + ('/' + parent if parent else '')
        doc = ET.fromstring(self.command('list', '--xml', '-r', revision, url + '@' + str(revision)))
        return name in {entry.findtext('name') for entry in doc.findall('.//entry')}

    def files(self, path, revision):
        url = self.root + '/' + path + '@' + str(revision)
        doc = ET.fromstring(self.command('list', '--xml', '--recursive', '-r', revision, url))
        entries = doc.findall('.//entry')
        require(len(entries) <= MAX_FILES and sum(int(e.findtext('size') or 0) for e in entries) <= MAX_BYTES,
                'Remote SVN subtree exceeds release limits')
        with tempfile.TemporaryDirectory(prefix='wordpress-export-') as temporary:
            destination = Path(temporary) / 'tree'
            self.command('export', '--quiet', '--ignore-externals', '-r', revision, url, destination)
            return tree(destination)

    def directories(self, path, revision):
        url = self.root + '/' + path + '@' + str(revision)
        doc = ET.fromstring(self.command('list', '--xml', '--recursive', '-r', revision, url))
        return sorted(entry.findtext('name').rstrip('/') for entry in doc.findall('.//entry')
                      if entry.attrib['kind'] == 'dir')

    def latest_change(self, path):
        doc = ET.fromstring(self.command('log', '--xml', '--limit', '1', self.root + '/' + path))
        entry = doc.find('logentry')
        require(entry is not None, 'SVN tag has no publication revision')
        date = datetime.fromisoformat((entry.findtext('date') or '').replace('Z', '+00:00'))
        require(date.tzinfo is not None, 'SVN commit time is missing')
        return int(entry.attrib['revision']), entry.findtext('msg') or '', date.isoformat()

    def transaction_revisions(self, markers, start, end):
        doc = ET.fromstring(self.command('log', '--xml', '-r', str(start) + ':' + str(end),
                                        self.root + '@' + str(end)))
        return [int(entry.attrib['revision']) for entry in doc.findall('logentry')
                if any(marker in (entry.findtext('msg') or '') for marker in markers)]

    def validate_credentials(self, username, password):
        require(re.fullmatch(r'[a-zA-Z0-9_.@-]{1,100}', username), 'Invalid SVN username')
        require(password is not None or self.root.startswith('file:///'),
                'WordPress publication requires --svn-password-stdin; cached credentials are not used')
        if password is not None:
            require(isinstance(password, bytes) and 0 < len(password) <= 16384
                    and b'\n' not in password and b'\r' not in password, 'Invalid password input')

    def commit(self, actions, revision, message, username, password=None, *, uploads=None):
        # Neither command-line arguments, logs, files nor environment contain the secret.
        self.validate_credentials(username, password)
        command = ['svnmucc', '--non-interactive', '--no-auth-cache', '--username', username,
                   '-U', self.root, '-r', str(revision), '-m', message]
        secret = None
        if password is not None:
            command.append('--password-from-stdin')
            secret = password + b'\n'
        with tempfile.TemporaryDirectory(prefix='wordpress-transaction-') as temporary:
            directory = Path(temporary)
            if uploads is not None:
                # No child runs between this private copy and svnmucc. Uploads
                # are immutable byte values, never shared release-input paths.
                for group, files in uploads.items():
                    require(group in ('plugin', 'assets'), 'Unexpected SVN upload group')
                    for name, value in files.items():
                        path = safe_path(directory / group / name)
                        require(path.is_relative_to(directory / group) and isinstance(value, bytes),
                                'Unsafe SVN upload input')
                        path.parent.mkdir(parents=True, exist_ok=True)
                        path.write_bytes(value)
                        path.chmod(0o400)
                actions = actions(directory)
            config = Path(temporary) / 'config'
            config.mkdir()
            command += ['--config-dir', str(config), '--config-option', 'config:auth:password-stores=']
            arguments = Path(temporary) / 'actions.txt'
            require(all('\n' not in arg and '\r' not in arg for arg in actions), 'Unsafe SVN action')
            arguments.write_text('\n'.join(actions) + '\n')
            command.extend(['-X', str(arguments)])
            result = subprocess.run(command, input=secret, stdout=subprocess.PIPE,
                                    stderr=subprocess.PIPE, timeout=SVN_COMMIT_TIMEOUT)
        detail = result.stderr.decode(errors='replace').strip()
        if password:
            detail = detail.replace(password.decode(errors='replace'), '[redacted]')
        if result.returncode and re.search(r'E(?:170001|215004|215003):', detail):
            raise AuthenticationRejected('SVN authentication was rejected; no release was committed. ' + detail[:1000])
        require(result.returncode == 0, 'SVN commit failed; credentials or remote state may need attention. '
                + detail[:2000])


class Publication:
    def __init__(self, number, commit, archive, svn=None):
        require(re.fullmatch(r'\d+\.\d+\.\d+', number) and re.fullmatch(r'[a-f0-9]{40}', commit),
                'Invalid publication identity')
        configure()
        self.number, self.commit, self.archive = number, commit, archive
        self.directory = release_dir(number)
        self.inputs = safe_path(self.directory / 'wordpress-inputs')
        self.record_path = self.directory / 'wordpress-publication.json'
        self.svn = svn or SVN()
        self.contents, self.zip_sha256 = package_files(archive)
        self.tag = 'tags/' + number
        self.identity = dict(version=number, tag='v' + number, commit=commit, zip_sha256=self.zip_sha256,
                             package_manifest_sha256=manifest_sha256(self.contents))

    def load(self):
        if not self.record_path.exists():
            return None
        record = json.loads(self.record_path.read_text())
        require(record.get('schema_version') == 1 and record.get('svn_root') == self.svn.root
                and all(record.get(k) == v for k, v in self.identity.items()),
                'Saved WordPress publication identity differs; never reuse this version')
        require(tree(self.inputs / 'plugin') == self.contents, 'Frozen WordPress package changed')
        require(manifest(tree(self.inputs / 'assets')) == record['assets'], 'Frozen Directory assets changed')
        return record

    def save(self, record, state, **fields):
        record.update(state=state, updated_at=now(), **fields)
        save_json(self.record_path, record)

    def evidence(self, proof, *, snapshot=False):
        required = {'everyday-workloads.json', 'native-upgrade-controlled.json', 'presentation/review.json'}
        require(isinstance(proof, dict) and proof.get('zip_sha256') == self.zip_sha256
                and proof.get('identity', {}).get('commit') == self.commit
                and set(proof.get('stable_checks', ())) == required
                and isinstance(proof.get('files'), dict) and proof['files'],
                'Stable publication evidence does not match the frozen commit, ZIP or required checks')
        if snapshot:
            directory = safe_path(ROOT / proof['snapshot'])
            require(directory.is_relative_to(self.directory / 'wordpress-evidence'), 'Invalid publication evidence snapshot')
            description = directory / 'manifest.json'
            require(sha256(description.read_bytes()) == proof['snapshot_sha256'], 'Publication evidence snapshot changed')
            saved = json.loads(description.read_text())
            require(saved == {k: v for k, v in proof.items() if k not in ('snapshot', 'snapshot_sha256')},
                    'Publication evidence snapshot identity changed')
        for relative, expected in proof['files'].items():
            require(isinstance(expected, str) and re.fullmatch(r'[0-9a-f]{64}', expected), 'Invalid evidence hash')
            source = safe_path(ROOT / relative)
            require(source.is_relative_to(ROOT / 'docs/generated/releases'), 'Evidence outside release storage')
            path = safe_path(directory / 'blobs' / expected) if snapshot else source
            require(path.is_file() and sha256(path.read_bytes()) == expected,
                    'Retained publication evidence changed: ' + relative)

    def freeze_evidence(self, proof):
        self.evidence(proof)
        payload = json.dumps(proof, sort_keys=True, separators=(',', ':')).encode()
        digest = sha256(payload)
        directory = safe_path(self.directory / 'wordpress-evidence' / digest)
        (directory / 'blobs').mkdir(parents=True, exist_ok=True)
        for relative, expected in proof['files'].items():
            destination = safe_path(directory / 'blobs' / expected)
            value = safe_path(ROOT / relative).read_bytes()
            require(sha256(value) == expected, 'Evidence changed while freezing: ' + relative)
            if destination.exists():
                require(destination.read_bytes() == value, 'Content-addressed evidence conflict')
            else:
                pending = safe_path(destination.with_suffix('.pending'))
                pending.write_bytes(value)
                pending.replace(destination)
        description = directory / 'manifest.json'
        if description.exists():
            require(description.read_bytes() == payload, 'Content-addressed evidence manifest conflict')
        else:
            pending = safe_path(description.with_suffix('.pending'))
            pending.write_bytes(payload)
            pending.replace(description)
        return dict(proof, snapshot=str(directory.relative_to(ROOT)), snapshot_sha256=digest)

    def attempts(self, record):
        result = {}
        for transaction, reference in record.get('attempts', {}).items():
            require(re.fullmatch(r'[a-f0-9]{32}', transaction), 'Invalid SVN attempt identity')
            path = safe_path(ROOT / reference['path'])
            require(path == self.directory / 'wordpress-attempts' / (transaction + '.json')
                    and sha256(path.read_bytes()) == reference['sha256'], 'SVN attempt evidence changed')
            attempt = json.loads(path.read_text())
            require(attempt.get('schema_version') == 1 and attempt.get('transaction') == transaction
                    and all(attempt.get(k) == value for k, value in self.identity.items())
                    and isinstance(attempt.get('base_revision'), int)
                    and datetime.fromisoformat(attempt['commit_started_at']).tzinfo is not None,
                    'SVN attempt does not identify this frozen release')
            result[transaction] = attempt
        return result

    def begin_attempt(self, record, revision, evidence):
        transaction = uuid.uuid4().hex
        started = now()
        attempt = dict(schema_version=1, **self.identity, transaction=transaction,
                       base_revision=revision, commit_started_at=started, commit_timeout_seconds=SVN_COMMIT_TIMEOUT,
                       publication_evidence=evidence)
        path = safe_path(self.directory / 'wordpress-attempts' / (transaction + '.json'))
        path.parent.mkdir(parents=True, exist_ok=True)
        with path.open('x') as output:
            output.write(json.dumps(attempt, indent=2) + '\n')
        references = dict(record.get('attempts', {}))
        references[transaction] = dict(path=str(path.relative_to(ROOT)), sha256=sha256(path.read_bytes()))
        self.save(record, 'svn-committing', transaction=transaction, base_revision=revision,
                  commit_started_at=started, publication_evidence=evidence, attempts=references)

    def reconcile(self, record, *, allow_absence=True):
        """Resolve an interrupted transaction before any evidence can be refreshed."""
        head = self.svn.revision()
        attempts = self.attempts(record)
        if self.svn.exists(self.tag, head):
            require(attempts, 'WordPress tag already exists; publication refused')
            return self.verify_committed(record, attempts)
        require(not record.get('svn_revision'), 'Previously committed WordPress tag disappeared')
        if attempts:
            matches = self.svn.transaction_revisions(['publication ' + value for value in attempts],
                                                    min(a['base_revision'] for a in attempts.values()), head)
            require(not matches, 'SVN recorded this transaction but its immutable tag is missing; inspect remote history')
        if record.get('state') in ('svn-committing', 'svn-outcome-unknown'):
            attempt = attempts.get(record.get('transaction'))
            require(attempt is not None, 'Uncertain SVN outcome has no retained attempt; inspect before retrying')
            # Older release clients did not record their five-minute commit timeout.
            timeout = attempt.get('commit_timeout_seconds', 300)
            require(type(timeout) is int and timeout > 0, 'Invalid recorded SVN commit timeout')
            until = datetime.fromisoformat(attempt['commit_started_at']) + timedelta(seconds=timeout + SVN_SERVER_MARGIN)
            if not allow_absence or datetime.fromisoformat(now()) < until:
                self.save(record, record['state'], next_check_at=until.isoformat())
                raise Pending('SVN outcome is still settling; evidence remains frozen. Resume after '
                              + until.isoformat() + '. A missing tag is not proof that the server stopped.')
        if record.get('base_revision') is not None:
            record.pop('next_check_at', None)
            self.save(record, 'svn-not-committed', reconciled_revision=head, reconciled_at=now())
        return record

    def freeze(self, reset_unpublished=False):
        if self.record_path.exists() and reset_unpublished:
            saved = json.loads(self.record_path.read_text())
            if any(saved.get(k) != value for k, value in self.identity.items()):
                require(saved.get('state') == 'inputs-frozen' and saved.get('base_revision') is None
                        and not saved.get('svn_revision'), 'Uncertain or public publication cannot be refrozen')
                backup = safe_path(ROOT / 'docs/generated/backups' / ('wordpress-inputs-' + self.number + '-' + uuid.uuid4().hex))
                backup.mkdir(parents=True)
                self.inputs.rename(backup / 'inputs')
                self.record_path.rename(backup / 'wordpress-publication.json')
        existing = self.load()
        if existing:
            return existing
        validate_presentation(self.number, self.contents)
        images, description, provenance_hash = assets(self.number)
        require(not self.inputs.exists(), 'Unrecorded WordPress staging exists; inspect it before retrying')
        for group, values in (('plugin', self.contents), ('assets', images)):
            for name, value in values.items():
                path = safe_path(self.inputs / group / name)
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(value)
        record = dict(schema_version=1, svn_root=self.svn.root, **self.identity,
                      assets=manifest(images), provenance=description,
                      provenance_sha256=provenance_hash, transaction=uuid.uuid4().hex)
        self.save(record, 'inputs-frozen')
        return record

    def previous(self, revision):
        names = self.svn.names(revision)
        require(names.get('trunk') == names.get('tags') == names.get('assets') == 'dir',
                'Expected existing trunk, tags and assets directories')
        previous = self.svn.files('trunk', revision)
        if previous:
            version = re.search(rb'Version:\s*(\d+\.\d+\.\d+)', previous.get('cybermaps.php', b''))
            require(version, 'Existing trunk is not an identifiable release')
            older = version[1].decode()
            require(tuple(map(int, older.split('.'))) < tuple(map(int, self.number.split('.'))),
                    'WordPress versions must advance; never rewrite a release')
            require(self.svn.exists('tags/' + older, revision)
                    and self.svn.files('tags/' + older, revision) == previous,
                    'Trunk differs from its released tag; inspect remote changes before publication')
        return previous, self.svn.files('assets', revision), {
            name: self.svn.directories(name, revision) for name in ('trunk', 'assets')}

    def actions(self, record, inputs, images):
        # Reconcile only owned files, preserving the trunk/assets directory nodes.
        # Explicitly put tag bytes: cp at the base revision cannot see new trunk bytes.
        actions = ['mkdir', self.tag]
        # Directory property writes fence concurrent child additions as well as
        # edits to known files. The value also records which artifact owns the roots.
        actions += ['propset', 'cybermaps:release-sha256', self.zip_sha256, 'trunk',
                    'propset', 'cybermaps:release-sha256', sha256(json.dumps(record['assets'], sort_keys=True).encode()), 'assets']
        targets = (
            ('trunk', self.contents, 'plugin', record['previous_files']),
            (self.tag, self.contents, 'plugin', {}),
            ('assets', images, 'assets', record['previous_assets']),
        )
        for target, values, source, previous in targets:
            parents = set()
            for name in values:
                parents.update(str(p) for p in Path(name).parents if str(p) != '.')
            old_directories = set(record['previous_directories'].get(target, ()))
            obsolete = old_directories - parents
            # Removing a directory already removes its obsolete descendants.
            removed = {p for p in obsolete if not any(str(parent) in obsolete for parent in Path(p).parents)}
            for name in sorted(removed):
                actions += ['rm', target + '/' + name]
            for name in sorted(previous.keys() - values.keys()):
                if not any(str(parent) in removed for parent in Path(name).parents):
                    actions += ['rm', target + '/' + name]
            for parent in sorted(parents - old_directories, key=lambda p: (p.count('/'), p)):
                actions += ['mkdir', target + '/' + parent]
            for name in sorted(values):
                destination = target + '/' + name
                if previous.get(name) != sha256(values[name]):
                    actions += ['put', str(inputs / source / name), destination]
                if target == 'assets':
                    actions += ['propset', 'svn:mime-type', 'image/png', destination]
        return actions

    def verify_committed(self, record, attempts=None):
        revision, message, committed_at = self.svn.latest_change(self.tag)
        attempts = self.attempts(record) if attempts is None else attempts
        matching = [attempt for transaction, attempt in attempts.items() if 'publication ' + transaction in message]
        require(len(matching) == 1, 'Existing tag belongs to another publication')
        attempt = matching[0]
        require(record.get('svn_revision', revision) == revision, 'Immutable WordPress tag changed after publication')
        require(self.svn.files(self.tag, revision) == self.contents
                and self.svn.files('trunk', revision) == self.contents
                and manifest(self.svn.files('assets', revision)) == record['assets'],
                'Committed WordPress package or Directory images differ from frozen inputs')
        head = self.svn.revision()
        require(self.svn.files('trunk', head) == self.contents
                and manifest(self.svn.files('assets', head)) == record['assets'],
                'Current WordPress trunk or artwork changed after this publication; inspect before resuming')
        self.evidence(attempt['publication_evidence'], snapshot=True)
        if not record.get('svn_revision'):
            record.pop('next_check_at', None)
            self.save(record, 'svn-committed', svn_revision=revision, committed_at=committed_at,
                      **{key: attempt[key] for key in ('transaction', 'base_revision',
                         'commit_started_at', 'publication_evidence')})
        else:
            require(record.get('transaction') == attempt['transaction']
                    and record.get('publication_evidence') == attempt['publication_evidence'],
                    'Committed release proof differs from its actual SVN attempt')
        return record

    def publish(self, before_commit, username='oreshkin', password=None):
        record = self.reconcile(self.freeze())
        if record.get('svn_revision'):
            return record
        self.svn.validate_credentials(username, password)
        head = self.svn.revision()
        previous, images, directories = self.previous(head)
        self.save(record, 'inputs-frozen', base_revision=head, previous_files=manifest(previous),
                  previous_assets=manifest(images), previous_directories=directories)
        # Revalidate evidence immediately before the irreversible write and retain
        # its identity for downstream resumes after the freshness window expires.
        evidence = self.freeze_evidence(before_commit())
        self.save(record, 'svn-prepared', publication_evidence=evidence)
        latest = self.svn.revision()
        current, current_images, current_directories = self.previous(latest)
        require(current == previous and current_images == images and current_directories == directories
                and not self.svn.exists(self.tag, latest),
                'Owned SVN paths changed after preflight; inspect before retrying')
        # WordPress shares a global revision counter with every other plugin.
        # Unrelated commits are harmless when our owned paths are unchanged.
        self.save(record, 'svn-prepared', base_revision=latest)
        # Check shared staging after all readiness children have completed. The
        # actual uploads below come only from these byte values in a private dir.
        images = tree(self.inputs / 'assets')
        require(tree(self.inputs / 'plugin') == self.contents and manifest(images) == record['assets'],
                'Frozen WordPress inputs changed before the transaction')
        self.begin_attempt(record, latest, evidence)
        try:
            self.svn.commit(lambda private: self.actions(record, private, images), latest,
                            'Release Cybermaps ' + self.number + ' (publication ' + record['transaction'] + ')',
                            username, password, uploads={'plugin': self.contents, 'assets': images})
        except AuthenticationRejected as error:
            self.save(record, 'svn-not-committed', error=str(error))
            raise
        except (RuntimeError, OSError, subprocess.TimeoutExpired) as error:
            # Timeout or a dropped response is ambiguous. Query before any possible retry.
            self.save(record, 'svn-outcome-unknown', error=str(error))
            # The server may still finish a request after the client times out.
            # An immediate read may recover success, but never authorize retry.
            recovered = self.reconcile(record, allow_absence=False)
            if recovered.get('svn_revision'):
                return recovered
            raise
        return self.verify_committed(record)

    def pending(self, state, reason):
        record = self.load()
        committed = datetime.fromisoformat(record['committed_at'])
        next_check = max(datetime.now(timezone.utc) + timedelta(minutes=15), committed + timedelta(hours=6))
        pending = dict(schema_version=1, state=state, **self.identity, svn_revision=record['svn_revision'],
                       reason=reason, checked_at=now(), next_check_at=next_check.isoformat())
        save_json(self.directory / 'wordpress-pending.json', pending)
        (self.directory / 'wordpress-failure.json').unlink(missing_ok=True)
        path = self.directory / 'wordpress-distribution.json'
        previous = json.loads(path.read_text()) if path.exists() else {}
        if previous.get('state') != 'distribution-verified':
            save_json(path, pending)
        raise Pending(reason + ' No release bytes were replaced. Resume after ' + next_check.isoformat())

    def failure(self, reason):
        save_json(self.directory / 'wordpress-failure.json', dict(schema_version=1,
                  state='wordpress-verification-failed', **self.identity, checked_at=now(), reason=reason))
        raise RuntimeError(reason)

    def distribution(self, get=None, verify_upgrade=None):
        get = get or fetch
        record = self.load()
        require(record and record.get('svn_revision'), 'WordPress SVN publication has not been verified')
        self.verify_committed(record)
        url = 'https://downloads.wordpress.org/plugin/cybermaps.' + self.number + '.zip'
        api_url = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=cybermaps'
        try:
            api_body = get(api_url)
            try:
                info = json.loads(api_body)
            except json.JSONDecodeError:
                self.pending('wordpress-distribution-pending', 'WordPress.org returned invalid API metadata; no version was verified')
            require(isinstance(info, dict), 'WordPress.org returned an unexpected API schema')
            if info.get('slug') != SLUG or info.get('version') != self.number:
                self.pending('wordpress-distribution-pending', 'WordPress.org has not exposed this version yet; '
                             'check its release confirmation/security notification if the wait persists.')
            require(info.get('download_link') == url, 'WordPress API download URL differs from the exact version')
            payload = get(url)
            require(normalized_zip(payload) == self.contents, 'WordPress.org ZIP file bytes differ from the frozen package')
            listing = get(LISTING)
            page = Listing()
            page.feed(listing.decode())
            require(page.canonical == LISTING and 'cybermaps' in page.title.lower()
                    and self.number.encode() in listing, 'Directory listing does not identify the expected release')
            responses = {api_url: sha256(api_body), url: sha256(payload), LISTING: sha256(listing)}
            for name, value in tree(self.inputs / 'assets').items():
                asset_url = 'https://ps.w.org/cybermaps/assets/' + name + '?rev=' + str(record['svn_revision'])
                actual = get(asset_url)
                if actual != value:
                    self.pending('wordpress-assets-pending', 'WordPress Directory artwork is still propagating: ' + name)
                responses[asset_url] = sha256(actual)
        except urllib.error.HTTPError as error:
            error.close()
            if error.code in (403, 404, 429, 500, 502, 503, 504):
                self.pending('wordpress-distribution-pending', 'WordPress.org distribution is not ready (HTTP '
                             + str(error.code) + '); check configured release confirmation or review messages.')
            self.failure('Unexpected WordPress.org HTTP response: ' + str(error.code))
        except (urllib.error.URLError, TimeoutError, http.client.HTTPException, ConnectionError) as error:
            self.pending('wordpress-distribution-pending', 'WordPress.org could not be verified: ' + str(error))
        except Pending:
            raise
        except (RuntimeError, OSError, ValueError, KeyError, zipfile.BadZipFile) as error:
            self.failure('WordPress distribution verification failed: ' + str(error))
        require(verify_upgrade is not None, 'A real WordPress update verification callback is required')
        try:
            proof = verify_upgrade()
        except OfferUnavailable as error:
            self.pending('wordpress-update-pending', 'Native WordPress.org update verification is incomplete: ' + str(error))
        except (RuntimeError, OSError, ValueError, KeyError, subprocess.CalledProcessError) as error:
            self.failure('Native WordPress.org update verification failed: ' + str(error))
        wordpress = dict(slug=SLUG, version=self.number, svnRevision=record['svn_revision'],
                         packageManifestSha256=self.identity['package_manifest_sha256'], verifiedAt=now(),
                         listingUrl=LISTING, downloadUrl=url, updateVerified=True)
        result = dict(schema_version=1, state='distribution-verified', **self.identity,
                      wordpress=wordpress, checked_at=now(), responses=responses, upgrade=proof,
                      publication_sha256=sha256(self.record_path.read_bytes()))
        target = self.directory / 'wordpress-distribution.json'
        existing = json.loads(target.read_text()) if target.exists() else {}
        if existing.get('state') == 'distribution-verified':
            require(all(existing.get(k) == v for k, v in self.identity.items())
                    and existing.get('upgrade') == proof
                    and existing.get('publication_sha256') == result['publication_sha256'],
                    'Completed distribution evidence changed')
            save_json(self.directory / 'wordpress-live-check.json', dict(checked_at=now(), responses=responses))
            (self.directory / 'wordpress-pending.json').unlink(missing_ok=True)
            (self.directory / 'wordpress-failure.json').unlink(missing_ok=True)
            return existing
        save_json(target, result)
        (self.directory / 'wordpress-pending.json').unlink(missing_ok=True)
        (self.directory / 'wordpress-failure.json').unlink(missing_ok=True)
        return result


def fetch(url):
    request = urllib.request.Request(url, headers={'User-Agent': 'Cybermaps-release-verification',
                                                  'Cache-Control': 'no-cache', 'Accept': '*/*'})
    with urllib.request.urlopen(request, timeout=30) as response:
        require(response.status == 200, 'Unexpected WordPress response')
        destination = urllib.parse.urlparse(response.url)
        require(destination.scheme == 'https' and destination.hostname in {
            'wordpress.org', 'api.wordpress.org', 'downloads.wordpress.org', 'ps.w.org'},
            'Unexpected WordPress response origin')
        body = response.read(MAX_BYTES + 1)
        require(len(body) <= MAX_BYTES, 'Oversized WordPress response')
        return body
