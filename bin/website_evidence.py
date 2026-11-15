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
from urllib.parse import urlparse

from workspace import ROOT, clean_website, git, release_dir, require, safe_path, save_json

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


def prepared(website, commit, channel, number, max_age=24, zip_sha256=None):
    clean_website(website)
    path = release_dir(number) / 'website-prepared.json'
    require(path.is_file(), 'Website preparation missing; run the full website check')
    value = json.loads(path.read_text())
    require(value.get('schema_version') == 1 and value.get('state') == 'website-prepared',
            'Invalid website preparation evidence')
    require(all(value.get(k) == v for k, v in dict(commit=commit, channel=channel, version=number,
            website=str(website), source_sha256=fingerprint(website)).items()), 'Website preparation is stale')
    require(zip_sha256 is None or value.get('zip_sha256') == zip_sha256, 'Website preparation used another candidate ZIP')
    age = (datetime.now(timezone.utc) - datetime.fromisoformat(value['checked_at'])).total_seconds()
    require(0 <= age <= max_age * 3600, 'Website preparation expired')
    log = safe_path(Path(value['log']))
    require(log.is_relative_to(release_dir(number)) and digest(log) == value['log_sha256'],
            'Website preparation log changed')
    result = dict(website_commit=git(website, 'rev-parse', 'HEAD'), source_sha256=value['source_sha256'],
                  preparation_sha256=digest(path), channel=channel)
    if zip_sha256 is not None:
        result['zip_sha256'] = zip_sha256
    return result


class Links(HTMLParser):
    def __init__(self):
        super().__init__()
        self.hrefs = set()
        self.title = []
        self.heading = []
        self.canonical = None
        self.image = None
        self.main_text = []
        self._main = False
        self._ignored = 0
        self._title = False
        self._heading = False

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if tag == 'a':
            self.hrefs.update(value for key, value in attrs if key == 'href')
        if tag == 'title':
            self._title = True
        if tag == 'h1':
            self._heading = True
        if tag == 'main':
            self._main = True
        if tag in ('script', 'style'):
            self._ignored += 1
        if tag == 'link' and values.get('rel') == 'canonical':
            self.canonical = values.get('href')
        if tag == 'meta' and values.get('property') == 'og:image':
            self.image = values.get('content')

    def handle_endtag(self, tag):
        if tag == 'title':
            self._title = False
        if tag == 'h1':
            self._heading = False
        if tag == 'main':
            self._main = False
        if tag in ('script', 'style'):
            self._ignored = max(0, self._ignored - 1)

    def handle_data(self, data):
        if self._title:
            self.title.append(data)
        if self._heading:
            self.heading.append(data)
        if self._main and not self._ignored:
            self.main_text.append(data)

    def facts(self):
        return (' '.join(''.join(self.title).split()), ' '.join(''.join(self.heading).split()),
                self.canonical, self.image, ' '.join(''.join(self.main_text).split()))


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
    if published['channel'] == 'stable':
        require('https://wordpress.org/plugins/cybermaps/' in links.hrefs,
                'Stable website does not advertise its verified WordPress Directory installation')
    require(not any('/releases/download/' in link and link.endswith(('.zip', '.sha256'))
                    and link not in {archive, archive + '.sha256'} for link in links.hrefs),
            'Live downloads advertise a different release')
    changelog = get('/changelog/')
    require(number in changelog.decode() and number in get('/changelog.txt').decode(),
            'Live changelog is missing the release')
    for path, body in (('/downloads/', downloads), ('/changelog/', changelog)):
        evidence[path] = hashlib.sha256(body).hexdigest()
    if published['channel'] == 'stable':
        evidence.update(stable_presentation(website, number, get))
    return evidence


def stable_presentation(website, number, get):
    """Check deployed launch content and actual images, allowing CDN HTML additions."""
    evidence = {}
    # The gallery is embedded on the home and launch pages; there is no /gallery/ route.
    for path in ('/', '/blog/', '/blog/wordpress-launch/'):
        actual = get(path)
        built = safe_path(website / 'dist' / path.lstrip('/') / 'index.html').read_bytes()
        expected, live = Links(), Links()
        expected.feed(built.decode())
        live.feed(actual.decode())
        require(all(expected.facts()) and expected.facts() == live.facts()
                and expected.hrefs <= live.hrefs, 'Live presentation differs from reviewed build: ' + path)
        evidence[path] = hashlib.sha256(actual).hexdigest()
        image = urlparse(expected.image)
        require(image.scheme == 'https' and image.netloc == 'cybermaps.dev', 'Unexpected social artwork origin')
        if image.path not in evidence:
            data = get(image.path)
            require(data == safe_path(website / 'dist' / image.path.lstrip('/')).read_bytes(),
                    'Live social artwork differs from reviewed build')
            evidence[image.path] = hashlib.sha256(data).hexdigest()
    for path in ('/feed.json', '/feed.xml'):
        data = get(path)
        require(data == safe_path(website / 'dist' / path.lstrip('/')).read_bytes(),
                'Live announcement feed differs from build: ' + path)
        if number == '8.1.0':
            require(b'/blog/wordpress-launch/' in data, 'Launch announcement missing from live feed')
        evidence[path] = hashlib.sha256(data).hexdigest()
    screenshots = json.loads((website / 'verification/screenshot-sources.json').read_text())
    policy = json.loads((ROOT / 'docs/dev/release-policy.json').read_text())
    require(screenshots.get('version') == number and screenshots.get('review_status') == 'reviewed'
            and len(screenshots.get('captures', [])) == policy['presentation']['website_screenshots'],
            'Current screenshot review is missing')
    for capture in screenshots['captures']:
        for suffix, key in (('', 'fullSha256'), ('-thumb', 'thumbnailSha256'), ('-strip', None)):
            path = '/images/gallery/' + capture['id'] + suffix + '.webp'
            data = get(path)
            require(data == safe_path(website / 'dist' / path.lstrip('/')).read_bytes()
                    and (key is None or hashlib.sha256(data).hexdigest() == capture[key]),
                    'Live screenshot differs: ' + path)
            evidence[path] = hashlib.sha256(data).hexdigest()
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
