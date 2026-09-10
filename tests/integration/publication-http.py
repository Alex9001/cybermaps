"""Verify public retry and HEAD contracts using a disposable WordPress site.
Usage: python3 tests/integration/publication-http.py PHP_BINARY /tmp/site/wp-load.php
"""
import os
import pathlib
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

repo = pathlib.Path(__file__).resolve().parents[2]
with socket.socket() as reserve:
    reserve.bind(('127.0.0.1', 0))
    port = reserve.getsockname()[1]
env = dict(os.environ, CYBERMAPS_TEST_WP_BOOTSTRAP=sys.argv[2], CYBERMAPS_TEST_PLUGIN=sys.argv[3] if len(sys.argv)>3 else str(repo))
with tempfile.TemporaryFile() as logs:
    server = subprocess.Popen([sys.argv[1], '-n', '-d', 'memory_limit=512M', '-S', f'127.0.0.1:{port}', str(repo/'tests/fixtures/publication-unavailable-router.php')], env=env, stdout=logs, stderr=logs)
    try:
        for attempt in range(30):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=0.1): break
            except OSError: time.sleep(0.1)
        for path in ('/llms.txt', '/llms-tldr.txt', '/llms-full.txt'):
            for method in ('GET', 'HEAD'):
                req = urllib.request.Request(f'http://127.0.0.1:{port}{path}', method=method)
                try: response = urllib.request.urlopen(req, timeout=10)
                except urllib.error.HTTPError as error: response = error
                with response:
                    body = response.read()
                    assert response.status == 503, (path, method, response.status, body)
                    assert response.headers.get('Retry-After') == '5'
                    assert 'no-store' in response.headers.get('Cache-Control', '')
                    assert 'text/plain' in response.headers.get('Content-Type', '')
                    assert body == (b'' if method == 'HEAD' else b'Publication is being rebuilt.\n')
                print(path, method, '503; retry/no-store/body contract verified')
    except Exception:
        logs.seek(0)
        print(logs.read().decode(), file=sys.stderr)
        raise
    finally:
        server.terminate()
        server.wait(timeout=10)
