#!/usr/bin/env python3
"""Capture and inspect the actual release ZIP on a disposable WordPress site."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time
import uuid
import zipfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'bin'))
from workspace import configure, require, package_files, verify_files  # noqa: E402


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--zip', required=True, type=Path)
    parser.add_argument('--sha256', required=True)
    parser.add_argument('--wordpress', default='7.1.4')
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    archive = args.zip.resolve()
    output = args.output.resolve()
    temporary = configure()
    require(output.is_relative_to(ROOT / 'docs/generated/releases'), 'Use the canonical release evidence directory.')
    require(not output.exists() or not any(output.iterdir()), 'Preserve prior evidence; use an empty output directory.')
    files, digest = package_files(archive)
    require(digest == args.sha256, 'Candidate ZIP checksum mismatch.')
    with zipfile.ZipFile(archive) as package:
        main_source = package.read('cybermaps/cybermaps.php').decode()
    version = re.search(r'^ \* Version: ([0-9.]+)$', main_source, re.M).group(1)
    output.mkdir(parents=True, exist_ok=True)
    frozen = output / 'artifact'
    frozen.mkdir()
    shutil.copyfile(archive, frozen / archive.name)
    archive = frozen / archive.name
    require(hashlib.sha256(archive.read_bytes()).hexdigest() == digest, 'ZIP changed while freezing presentation inputs.')
    Path(str(archive) + '.sha256').write_text(digest + '  ' + archive.name + '\n')
    runtime = Path(tempfile.mkdtemp(prefix='cybermaps-presentation-', dir=temporary))
    wp_root = runtime / 'wordpress'
    wp_root.mkdir(mode=0o777)
    ident = 'cybermaps-presentation-' + uuid.uuid4().hex[:10]
    network, database, http = ident + '-net', ident + '-db', ident + '-http'
    engine = 'podman' if shutil.which('podman') else 'docker'
    image = 'docker.io/library/wordpress:cli-php8.2'
    log = (output / 'fixture.log').open('w')
    status = {'schema_version': 1, 'version': version, 'commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
              'zip_sha256': digest, 'wordpress': args.wordpress, 'started_at': datetime.now(timezone.utc).isoformat(),
              'fixture': 'Fictional Willow Studio; real plugin output. Analytics may include six actual HTTP requests from CybermapsPresentationTest/1.0. No crawler identity, UI value, or success state is fabricated.', 'passed': False}
    status['source_tree_dirty'] = bool(subprocess.check_output(['git', 'status', '--porcelain'], cwd=ROOT, text=True).strip())
    status['harness_files'] = {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest() for name in (
        'tests/integration/launch-presentation.py', 'tests/integration/launch-presentation-seed.php',
        'tests/browser/launch-presentation.mjs', 'tests/browser/package-lock.json')}
    status['package_manifest_sha256'] = hashlib.sha256(json.dumps(
        {name: hashlib.sha256(payload).hexdigest() for name, payload in files.items()},
        sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode()).hexdigest()

    def run(command, timeout=180, capture=False):
        log.write('Command: ' + ' '.join(map(str, command)) + '\n')
        log.flush()
        result = subprocess.run(list(map(str, command)), cwd=ROOT, env=dict(os.environ, TMPDIR=str(temporary)),
                                stdout=subprocess.PIPE if capture else log, stderr=log, text=True, timeout=timeout, check=True)
        return result.stdout.strip() if capture else None

    def wp(*arguments, capture=False):
        return run([engine, 'run', '--rm', '-i', '--network', network, '--user', '0:0',
                    '-e', 'CYBERMAPS_PRESENTATION_FIXTURE=1', '-v', f'{temporary}/wp-cli-modern:/cli-tools:ro',
                    '-v', f'{wp_root}:/var/www/html', '-v', f'{archive.parent}:/artifacts:ro',
                    '-v', f'{ROOT}/tests/integration:/validation:ro', '-w', '/var/www/html', image,
                    'php', '-d', 'memory_limit=512M', '/cli-tools/vendor/wp-cli/wp-cli/php/boot-fs.php',
                    *arguments, '--allow-root'], capture=capture)

    try:
        run([engine, 'network', 'create', network])
        run([engine, 'run', '-d', '--name', database, '--network', network,
             '-e', 'MARIADB_DATABASE=wordpress', '-e', 'MARIADB_USER=wordpress', '-e', 'MARIADB_PASSWORD=wordpress',
             '-e', 'MARIADB_ROOT_PASSWORD=wordpress', 'docker.io/library/mariadb:10.11'])
        for _ in range(60):
            ready = subprocess.run([engine, 'exec', database, 'mariadb-admin', 'ping', '-h', '127.0.0.1', '-uroot', '-pwordpress', '--silent'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            if ready.returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError('Disposable database did not start.')
        wp('core', 'download', '--version=' + args.wordpress, '--quiet')
        wp('config', 'create', '--dbname=wordpress', '--dbuser=wordpress', '--dbpass=wordpress', '--dbhost=' + database, '--skip-check', '--quiet')
        for key, value in [('WP_DEBUG', 'true'), ('WP_DEBUG_DISPLAY', 'false'), ('WP_DEBUG_LOG', 'true'), ('DISABLE_WP_CRON', 'true')]:
            wp('config', 'set', key, value, '--raw', '--quiet')
        wp('core', 'install', '--url=http://cybermaps.test', '--title=Willow Studio — Demo', '--admin_user=admin', '--admin_password=cybermaps-presentation', '--admin_email=admin@example.com', '--skip-email', '--quiet')
        wp('plugin', 'install', '/artifacts/' + archive.name, '--activate', '--quiet')
        verify_files(wp_root / 'wp-content/plugins/cybermaps', files)
        (output / 'installed-files.json').write_text(json.dumps({'zip_sha256': digest,
            'files': {name: hashlib.sha256(payload).hexdigest() for name, payload in files.items()}}, indent=2) + '\n')
        wp('rewrite', 'structure', '/%postname%/', '--quiet')
        wp('option', 'update', 'show_avatars', '0', '--quiet')
        run([engine, 'run', '-d', '--name', http, '--network', network, '--user', '0:0', '-e', 'PHP_CLI_SERVER_WORKERS=4',
             '-p', '127.0.0.1::8080', '-v', f'{wp_root}:/var/www/html', '-v', f'{ROOT}/tests/integration:/validation:ro',
             '-w', '/var/www/html', image, 'php', '-d', 'memory_limit=512M', '-S', '0.0.0.0:8080', '/validation/wporg-http-router.php'])
        address = run([engine, 'port', http, '8080/tcp'], capture=True)
        origin = 'http://' + address
        wp('option', 'update', 'home', origin, '--quiet')
        wp('option', 'update', 'siteurl', origin, '--quiet')
        status['origin'] = origin
        env = dict(os.environ, TMPDIR=str(temporary), PLAYWRIGHT_BROWSERS_PATH=str(temporary / 'playwright-browsers'),
                   CYBERMAPS_CAPTURE_VERSION=version, CYBERMAPS_CAPTURE_ZIP_SHA256=digest,
                   CYBERMAPS_CAPTURE_PACKAGE_SHA256=status['package_manifest_sha256'])
        for phase in ('default', 'configured'):
            if phase == 'configured':
                (output / 'seed.json').write_text(wp('eval-file', '/validation/launch-presentation-seed.php', '--use-include', capture=True) + '\n')
            print('Presentation: ' + phase + ' (' + origin + ')', flush=True)
            result = subprocess.run(['node', str(ROOT / 'tests/browser/launch-presentation.mjs'), origin, str(output), phase],
                                    cwd=ROOT, env=env, stdout=log, stderr=log, timeout=1200)
            status[phase + '_passed'] = result.returncode == 0
        debug = wp_root / 'wp-content/debug.log'
        if debug.exists():
            shutil.copyfile(debug, output / 'debug.log')
        status['debug_clean'] = not debug.exists() or debug.stat().st_size == 0
        verify_files(wp_root / 'wp-content/plugins/cybermaps', files)
        status['passed'] = status['default_passed'] and status['configured_passed'] and status['debug_clean']
        require(hashlib.sha256(archive.read_bytes()).hexdigest() == digest, 'Candidate changed during capture.')
        browser = json.loads((output / 'browser.json').read_text())
        gallery = [entry for entry in browser['captures'] if entry['file'].startswith('captures/')]
        expected_gallery = {'discovery-overview', 'xml-sitemaps', 'html-sitemap', 'ai-publishing', 'identity',
                            'reports', 'advanced-delivery', 'sitemap-status', 'ai-status', 'discovery-analytics',
                            'debugging', 'xml-sitemap-output', 'ai-manifest-output', 'offer-catalog'}
        require(len(gallery) == 14 and {item['id'] for item in gallery} == expected_gallery,
                'All fourteen current gallery screens must be captured exactly once.')
        require(all(item['zip_sha256'] == digest and item['package_manifest_sha256'] == status['package_manifest_sha256']
                    for item in gallery), 'Per-screen artifact provenance is missing or inconsistent.')
        (output / 'capture-manifest.json').write_text(json.dumps({**status, 'captured_at': datetime.now(timezone.utc).isoformat(),
            'captures': gallery,
            'license': 'GPL-2.0-or-later', 'source': 'Actual browser captures; fixture source in tests/integration/launch-presentation-seed.php.'}, indent=2) + '\n')
    except Exception as error:
        status['error'] = str(error)
        raise
    finally:
        status['finished_at'] = datetime.now(timezone.utc).isoformat()
        (output / 'fixture.json').write_text(json.dumps(status, indent=2) + '\n')
        for name in (http, database):
            subprocess.run([engine, 'rm', '-f', name], stdout=log, stderr=log)
        subprocess.run([engine, 'network', 'rm', network], stdout=log, stderr=log)
        shutil.rmtree(runtime)
        log.close()
    print(json.dumps(status, indent=2))
    return 0 if status['passed'] else 1


if __name__ == '__main__':
    sys.exit(main())
