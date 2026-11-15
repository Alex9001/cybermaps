#!/usr/bin/env python3
"""Prepare truthful Directory artwork from a passing disposable capture.

This copies raw browser PNGs and records provenance. It does not grant visual
approval or create presentation/review.json. Run the presentation fixture first,
then inspect the actual images and complete the separate editorial review.
"""
import argparse
from datetime import datetime, timezone
import json
from pathlib import Path
import re
import shutil
import subprocess

from workspace import ROOT, configure, package_files, require, safe_path
from wordpress_release import manifest_sha256, sha256

BRAND_IMAGES = {'icon-128x128.png', 'icon-256x256.png', 'banner-772x250.png', 'banner-1544x500.png'}
SCREENSHOTS = {'screenshot-' + str(number) + '.png' for number in range(1, 9)}
GALLERY_IDS = {'discovery-overview', 'xml-sitemaps', 'html-sitemap', 'ai-publishing', 'identity',
               'reports', 'advanced-delivery', 'sitemap-status', 'ai-status', 'discovery-analytics',
               'debugging', 'xml-sitemap-output', 'ai-manifest-output', 'offer-catalog'}


def png_metadata(payload):
    require(payload.startswith(b'\x89PNG\r\n\x1a\n') and payload[12:16] == b'IHDR', 'Expected an unmodified PNG.')
    return dict(sha256=sha256(payload), width=int.from_bytes(payload[16:20], 'big'),
                height=int.from_bytes(payload[20:24], 'big'))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--presentation', required=True, type=Path)
    parser.add_argument('--brand-source-ref', default='v8.0.4')
    args = parser.parse_args()
    configure()
    presentation = safe_path(args.presentation.absolute())
    require(presentation.is_relative_to(ROOT / 'docs/generated/releases'), 'Use canonical release capture evidence.')
    fixture = json.loads((presentation / 'fixture.json').read_text())
    browser = json.loads((presentation / 'browser.json').read_text())
    gallery = json.loads((presentation / 'capture-manifest.json').read_text())
    defaults = json.loads((presentation / 'default-states.json').read_text())
    require(all(record.get('passed') is True for record in (fixture, browser, gallery, defaults)),
            'Fix presentation findings and recapture before preparing Directory assets.')
    version = fixture['version']
    require(re.fullmatch(r'\d+\.\d+\.\d+', version), 'Invalid captured version.')
    contents, digest = package_files(presentation / 'artifact' / ('cybermaps_' + version + '.zip'))
    normalized = manifest_sha256(contents)
    for record in (fixture, browser, gallery, defaults):
        require(record.get('version') == version and record.get('zip_sha256') == digest
                and record.get('package_manifest_sha256') == normalized, 'Capture evidence belongs to another artifact.')
    captures = gallery.get('captures', [])
    require(len(captures) == 14 and {capture['id'] for capture in captures} == GALLERY_IDS,
            'The fourteen gallery images must be present exactly once.')
    for capture in captures:
        source = safe_path(presentation / capture['file'])
        require(source.is_relative_to(presentation / 'captures') and sha256(source.read_bytes()) == capture['sha256']
                and capture.get('zip_sha256') == digest and capture.get('package_manifest_sha256') == normalized,
                'Gallery capture changed or lost artifact provenance.')
    section = re.search(r'^== Screenshots ==\s*\n(.*?)(?=^== |\Z)', contents['readme.txt'].decode(), re.M | re.S)
    require(section is not None, 'Captured readme has no screenshot captions.')
    captions = dict(re.findall(r'^(\d+)\. (.+)$', section[1], re.M))
    require(set(captions) == {str(n) for n in range(1, 9)}, 'Eight matching numbered captions are required.')
    images = safe_path(ROOT / '.wordpress-org')
    require({item.name for item in images.iterdir()} <= BRAND_IMAGES | SCREENSHOTS,
            'Unexpected Directory image inventory; review it explicitly before continuing.')
    require(re.fullmatch(r'v\d+\.\d+\.\d+', args.brand_source_ref), 'Use a specific release tag for brand provenance.')
    brand_commit = subprocess.check_output(['git', 'rev-parse', args.brand_source_ref + '^{commit}'], cwd=ROOT, text=True).strip()
    description = {}
    copies = {}
    for name in sorted(BRAND_IMAGES):
        path = safe_path(images / name)
        payload = path.read_bytes()
        previous = subprocess.check_output(['git', 'show', args.brand_source_ref + ':.wordpress-org/' + name], cwd=ROOT)
        require(payload == previous, 'Brand artwork changed; provide its actual source and review before using it: ' + name)
        description[name] = dict(**png_metadata(payload), license='GPL-2.0-or-later',
            source='Existing repository PNG preserved byte for byte from ' + args.brand_source_ref + '; no original vector source is asserted.',
            source_ref=args.brand_source_ref, source_commit=brand_commit, source_path='.wordpress-org/' + name,
            source_sha256=sha256(previous))
    directory_captures = [capture for capture in browser['captures'] if capture['file'].startswith('directory/')]
    require(len(directory_captures) == 8 and {Path(capture['file']).name for capture in directory_captures} == SCREENSHOTS,
            'All eight raw Directory screenshots must be present exactly once.')
    for capture in directory_captures:
        source = safe_path(presentation / capture['file'])
        require(source.is_relative_to(presentation / 'directory'), 'Directory screenshot escaped its evidence folder.')
        payload = source.read_bytes()
        name = source.name
        observed = png_metadata(payload)
        require(all(capture.get(key) == value for key, value in observed.items())
                and capture.get('version') == version and capture.get('zip_sha256') == digest
                and capture.get('package_manifest_sha256') == normalized, 'Directory screenshot provenance changed: ' + name)
        require(isinstance(capture.get('captured_at'), str) and capture['captured_at'], 'Capture timestamp is missing.')
        description[name] = dict(**observed, version=version, zip_sha256=digest,
            package_manifest_sha256=normalized, captured_at=capture['captured_at'],
            source='Actual browser viewport from a disposable fictional Willow Studio installation of the exact candidate ZIP. No pixel alterations.',
            source_file=source.relative_to(ROOT).as_posix(), source_sha256=observed['sha256'],
            license='GPL-2.0-or-later', caption=captions[name.split('-')[1].removesuffix('.png')])
        copies[name] = source
    # Complete every preflight before changing the separately published images.
    for name, source in copies.items():
        shutil.copyfile(source, safe_path(images / name))
    destination = safe_path(ROOT / 'docs/wporg/manifest.json')
    destination.parent.mkdir(parents=True, exist_ok=True)
    pending = safe_path(destination.with_name('manifest.json.pending'))
    pending.write_text(json.dumps(dict(schema_version=1, version=version, state='prepared-for-visual-review',
        prepared_at=datetime.now(timezone.utc).isoformat(), zip_sha256=digest,
        package_manifest_sha256=normalized, files=dict(sorted(description.items()))), indent=2) + '\n')
    pending.replace(destination)
    print('Prepared 8 exact browser screenshots and retained 4 existing brand images; manual visual review is still required.')
    print(destination)


if __name__ == '__main__':
    main()
