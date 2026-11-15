import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'bin'))
import website_evidence as web
from workspace import configure
configure()


class WebsiteEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.site = Path(self.tmp.name)
        self.release = dict(tag='v7.5.4', commit='commit', channel='beta', zip_sha256='zip')
        self.files = {'/product/website.json': json.dumps(dict(version='7.5.4', commit='commit', channel='beta')).encode(),
                      '/product/manifest.json': b'{"version":"7.5.4"}',
                      '/specs/ai-configuration/7.5.4/schema.json': b'{"schema":1}',
                      '/specs/ai-configuration/7.5.4/catalog.json': b'{"catalog":1}',
                      '/changelog.txt': b'7.5.4\nChanges'}
        for name, content in self.files.items():
            p = self.site / 'dist' / name.lstrip('/')
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(content)
        (self.site / 'product/7.5.4').mkdir(parents=True)
        (self.site / 'product/release.json').write_text(json.dumps(self.release))
        (self.site / 'product/7.5.4/source.json').write_text('{"state":"tagged","commit":"commit"}')
        url = 'https://github.com/Alex9001/cybermaps/releases/download/v7.5.4/cybermaps_7.5.4.zip'
        self.files['/downloads/'] = f'<a href="{url}">ZIP</a><a href="{url}.sha256">Checksum</a>'.encode()
        self.files['/changelog/'] = b'<h2>7.5.4</h2>'

    def test_live_contracts_and_downloads_must_match(self):
        self.assertEqual(len(web.check_live(self.site, self.release, self.files.__getitem__)), 7)
        for path in self.files:
            with self.subTest(path=path):
                changed = dict(self.files, **{path: b'old version'})
                with self.assertRaises((RuntimeError, ValueError)):
                    web.check_live(self.site, self.release, changed.__getitem__)
        changed = dict(self.release, channel='stable')
        with self.assertRaises(RuntimeError):
            web.check_live(self.site, changed, self.files.__getitem__)

    def test_preparation_missing_stale_changed_or_expired_blocks(self):
        log = self.site / 'check.log'
        log.write_text('passed')
        value = dict(schema_version=1, state='website-prepared', commit='commit', version='7.5.4',
                     channel='beta', website=str(self.site), source_sha256='source', checked_at=web.now(),
                     log=str(log), log_sha256=web.digest(log))
        with patch.object(web, 'clean_website'), patch.object(web, 'release_dir', return_value=self.site), \
                patch.object(web, 'fingerprint', return_value='source'), patch.object(web, 'git', return_value='head'):
            path = self.site / 'website-prepared.json'
            with self.assertRaises(RuntimeError):
                web.prepared(self.site, 'commit', 'beta', '7.5.4')
            path.write_text(json.dumps(value))
            self.assertEqual(web.prepared(self.site, 'commit', 'beta', '7.5.4')['website_commit'], 'head')
            for key, wrong in [('commit', 'old'), ('channel', 'stable'), ('source_sha256', 'old'),
                               ('checked_at', '2020-01-01T00:00:00+00:00'), ('log_sha256', 'tampered')]:
                with self.subTest(key=key):
                    path.write_text(json.dumps(dict(value, **{key: wrong})))
                    with self.assertRaises(RuntimeError):
                        web.prepared(self.site, 'commit', 'beta', '7.5.4')

    def test_success_records_the_exact_published_identity(self):
        with patch.object(web, 'release_dir', return_value=self.site), \
                patch.object(web, 'git', return_value='head'), patch.object(web, 'fingerprint', return_value='source'), \
                patch.object(web, 'clean_website'):
            proof = web.verify_live(self.site, self.release,
                check=lambda *args, **kwargs: web.check_live(self.site, self.release, self.files.__getitem__))
            self.assertEqual(proof['state'], 'website-verified')
            self.assertEqual(proof['zip_sha256'], self.release['zip_sha256'])
            self.assertEqual(proof['website_commit'], 'head')

    def test_unavailable_site_has_bounded_retries_and_failure_evidence(self):
        moments = iter([0, 0, 120])
        with patch.object(web, 'release_dir', return_value=self.site), \
                patch.object(web, 'git', return_value='head'), patch.object(web, 'fingerprint', return_value='source'):
            with self.assertRaises(RuntimeError):
                web.verify_live(self.site, self.release, clock=lambda: next(moments), sleep=lambda _: None,
                                check=lambda *args, **kwargs: (_ for _ in ()).throw(OSError('offline')))
            self.assertEqual(json.loads((self.site / 'website-live.json').read_text())['state'], 'verification-failed')

    def stable_files(self):
        files = {}
        for path in ('/', '/blog/', '/blog/wordpress-launch/'):
            body = ('<title>CYBERMAPS launch</title><link rel="canonical" href="https://cybermaps.dev' + path
                    + '"><meta property="og:image" content="https://cybermaps.dev/images/launch.jpg">'
                    + '<main><h1>CYBERMAPS is stable</h1><p>Current feature description.</p>'
                    + '<a href="https://wordpress.org/plugins/cybermaps/">Install</a></main>').encode()
            files[path] = body
            target = self.site / 'dist' / path.lstrip('/') / 'index.html'
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
        files.update({'/feed.json': b'{"url":"https://cybermaps.dev/blog/wordpress-launch/"}',
                      '/feed.xml': b'<url>https://cybermaps.dev/blog/wordpress-launch/</url>',
                      '/images/launch.jpg': b'actual reviewed social artwork'})
        captures = []
        for number in range(14):
            identifier = 'screen-' + str(number)
            capture = dict(id=identifier)
            for suffix, key in (('', 'fullSha256'), ('-thumb', 'thumbnailSha256'), ('-strip', None)):
                content = (identifier + suffix).encode()
                files['/images/gallery/' + identifier + suffix + '.webp'] = content
                if key:
                    capture[key] = hashlib.sha256(content).hexdigest()
            captures.append(capture)
        for path, content in files.items():
            if not path.endswith('/'):
                target = self.site / 'dist' / path.lstrip('/')
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(content)
        (self.site / 'verification').mkdir()
        (self.site / 'verification/screenshot-sources.json').write_text(json.dumps(dict(
            version='8.1.0', review_status='reviewed', captures=captures)))
        return files

    def test_stable_launch_checks_copy_feeds_and_current_images(self):
        files = self.stable_files()
        evidence = web.stable_presentation(self.site, '8.1.0', files.__getitem__)
        self.assertEqual(len(evidence), len(files))
        for path in ('/', '/feed.json', '/images/gallery/screen-0.webp', '/images/launch.jpg'):
            with self.subTest(path=path):
                changed = dict(files)
                changed[path] = (files[path].replace(b'Current feature description.', b'Obsolete feature description.')
                                 if path == '/' else b'obsolete bytes')
                with self.assertRaises(RuntimeError):
                    web.stable_presentation(self.site, '8.1.0', changed.__getitem__)

    def test_cdn_script_addition_does_not_hide_changed_main_copy(self):
        files = self.stable_files()
        files['/'] += b'<script>Cloudflare analytics added at the edge</script>'
        self.assertTrue(web.stable_presentation(self.site, '8.1.0', files.__getitem__))

    def test_live_gallery_count_comes_from_release_policy(self):
        files = self.stable_files()
        root = self.site / 'plugin-source'
        policy = root / 'docs/dev/release-policy.json'
        policy.parent.mkdir(parents=True)
        with patch.object(web, 'ROOT', root):
            policy.write_text(json.dumps(dict(presentation=dict(website_screenshots=13))))
            with self.assertRaisesRegex(RuntimeError, 'screenshot review is missing'):
                web.stable_presentation(self.site, '8.1.0', files.__getitem__)
            policy.write_text(json.dumps(dict(presentation=dict(website_screenshots=14))))
            self.assertTrue(web.stable_presentation(self.site, '8.1.0', files.__getitem__))


if __name__ == '__main__':
    unittest.main()
