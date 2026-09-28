#!/usr/bin/env python3
"""Evidence for prepared documentation and the actual production website."""
from datetime import datetime, timezone
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import subprocess
import time
import urllib.request

from workspace import clean_website, git, release_dir, require, safe_path, save_json

ORIGIN = 'https://cybermaps.dev'


def digest(path):
    return hashlib.sha256(safe_path(path).read_bytes()).hexdigest()


def now():
    return datetime.now(timezone.utc).isoformat()


def fingerprint(website):
    names = subprocess.check_output(['git', '-C', str(website), 'ls-files', '-z',
                                     '--cached', '--others', '--exclude-standard']).split(b'\0')
    entries = []
    for name in sorted(set(n.decode() for n in names if n)):
        path = safe_path(website / name)
        entries.append((name, digest(path) if path.is_file() else 'DELETED'))
    return hashlib.sha256(json.dumps(entries).encode()).hexdigest()


def prepared(website, commit, channel, number, max_age=24):
    clean_website(website)
    path = release_dir(number) / 'website-prepared.json'
    require(path.is_file(), 'Website preparation missing; run the full website check')
    value = json.loads(path.read_text())
    require(value.get('schema_version') == 1 and value.get('state') == 'website-prepared',
            'Invalid website preparation evidence')
    require(all(value.get(k) == v for k, v in dict(commit=commit, channel=channel, version=number,
            website=str(website), source_sha256=fingerprint(website)).items()), 'Website preparation is stale')
    age = (datetime.now(timezone.utc) - datetime.fromisoformat(value['checked_at'])).total_seconds()
    require(0 <= age <= max_age * 3600, 'Website preparation expired')
    log = safe_path(Path(value['log']))
    require(log.is_relative_to(release_dir(number)) and digest(log) == value['log_sha256'],
            'Website preparation log changed')
    return dict(website_commit=git(website, 'rev-parse', 'HEAD'), source_sha256=value['source_sha256'],
                preparation_sha256=digest(path), channel=channel)


class Links(HTMLParser):
    def __init__(self):
        super().__init__()
        self.hrefs = set()

    def handle_starttag(self, tag, attrs):
        if tag == 'a':
            self.hrefs.update(value for key, value in attrs if key == 'href')


def fetch(path, timeout=10):
    request = urllib.request.Request(ORIGIN + path, headers={
        'User-Agent': 'Cybermaps-release-verification', 'Cache-Control': 'no-cache'})
    with urllib.request.urlopen(request, timeout=timeout) as response:
        require(response.status == 200 and response.url.startswith(ORIGIN + '/'),
                'Unexpected production response: ' + path)
        body = response.read(8 * 1024 * 1024 + 1)
        require(len(body) <= 8 * 1024 * 1024, 'Oversized production response: ' + path)
        return body


def check_live(website, published, get=fetch):
    number = published['tag'][1:]
    release = json.loads((website / 'product/release.json').read_text())
    require(all(release.get(k) == published[k] for k in ('tag', 'commit', 'channel')),
            'Website snapshot differs from published release')
    source = json.loads((website / 'product' / number / 'source.json').read_text())
    require(source.get('state') == 'tagged' and source.get('commit') == published['commit'],
            'Production verification requires a frozen tagged snapshot')
    paths = ['/product/website.json', '/product/manifest.json',
             f'/specs/ai-configuration/{number}/schema.json',
             f'/specs/ai-configuration/{number}/catalog.json', '/changelog.txt']
    evidence = {}
    for path in paths:
        body = get(path)
        expected = safe_path(website / 'dist' / path.lstrip('/')).read_bytes()
        require(body == expected, 'Live content differs from validated build: ' + path)
        evidence[path] = hashlib.sha256(body).hexdigest()
        if path == '/product/website.json':
            contract = json.loads(body)
            require(all(contract.get(k) == v for k, v in dict(version=number,
                    commit=published['commit'], channel=published['channel']).items()),
                    'Live website identifies a different release')
        if path == '/product/manifest.json':
            require(json.loads(body).get('version') == number, 'Live manifest version differs')
    downloads = get('/downloads/')
    links = Links()
    links.feed(downloads.decode())
    archive = f'https://github.com/Alex9001/cybermaps/releases/download/{published["tag"]}/cybermaps_{number}.zip'
    require({archive, archive + '.sha256'} <= links.hrefs, 'Live download links do not match the release')
    require(not any('/releases/download/' in link and link.endswith(('.zip', '.sha256'))
                    and link not in {archive, archive + '.sha256'} for link in links.hrefs),
            'Live downloads advertise a different release')
    changelog = get('/changelog/')
    require(number in changelog.decode() and number in get('/changelog.txt').decode(),
            'Live changelog is missing the release')
    for path, body in (('/downloads/', downloads), ('/changelog/', changelog)):
        evidence[path] = hashlib.sha256(body).hexdigest()
    return evidence


def verify_live(website, published, timeout=120, check=check_live, clock=time.monotonic, sleep=time.sleep):
    path = release_dir(published['tag'][1:]) / 'website-live.json'
    deadline = clock() + timeout
    record = dict(schema_version=1, state='verification-pending', origin=ORIGIN,
                  **{k: published[k] for k in ('tag', 'commit', 'channel', 'zip_sha256')},
                  website_commit=git(website, 'rev-parse', 'HEAD'), source_sha256=fingerprint(website))
    save_json(path, record)
    while True:
        try:
            def bounded_get(url):
                remaining = deadline - clock()
                require(remaining > 0, 'Production verification deadline exceeded')
                return fetch(url, timeout=min(10, remaining))
            record['responses'] = check(website, published, get=bounded_get)
            clean_website(website)
            require(record['source_sha256'] == fingerprint(website)
                    and record['website_commit'] == git(website, 'rev-parse', 'HEAD'),
                    'Website source changed during production verification')
            record.update(state='website-verified', checked_at=now())
            record.pop('error', None)
            save_json(path, record)
            return record
        except (OSError, ValueError, RuntimeError) as error:
            record.update(state='verification-failed', checked_at=now(), error=str(error))
            save_json(path, record)
            remaining = deadline - clock()
            require(remaining > 0, 'Live website verification failed: ' + str(error))
            print('Website not yet verified: ' + str(error) + '; retrying.', flush=True)
            sleep(min(10, remaining))
