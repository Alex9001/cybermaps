"""Exercise the packaged plugin over HTTP in the disposable release runtime."""
import base64
import hashlib
import http.cookiejar
import json
import re
import sys
if sys.flags.optimize:
    raise SystemExit('Validation refuses optimized Python; unset PYTHONOPTIMIZE and omit -O/-OO.')
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

origin = sys.argv[1]
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def request(path, data=None, method=None, headers=None):
    encoded = urllib.parse.urlencode(data).encode() if data is not None else None
    req = urllib.request.Request(origin + path, data=encoded, method=method, headers=headers or {})
    try:
        response = client.open(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


for attempt in range(30):
    try:
        request('/wp-login.php')
        break
    except urllib.error.URLError:
        time.sleep(0.2)
else:
    raise RuntimeError('Disposable WordPress HTTP server did not start.')

for path in ('/feed.json', '/ai-sitemap.xml'):
    status, headers, body = request(path)
    assert status == 200, (path, status, body[:500])
    if path.endswith('.json'):
        assert json.loads(body)['version'] == 'https://jsonfeed.org/version/1.1'
    else:
        assert ET.fromstring(body).tag.endswith('urlset')
    digest = 'sha-256=:' + base64.b64encode(hashlib.sha256(body).digest()).decode() + ':'
    assert headers['Repr-Digest'] == digest, (path, dict(headers), digest)
    head_status, head_headers, head_body = request(path, method='HEAD')
    assert head_status == 200 and head_body == b'', (path, head_status, head_body)
    assert head_headers['ETag'] == headers['ETag'] and head_headers['Repr-Digest'] == digest
    conditional, _, conditional_body = request(path, headers={'If-None-Match': headers['ETag']})
    assert conditional == 304 and conditional_body == b'', (path, conditional)

request('/wp-login.php', {'log': 'admin', 'pwd': 'cybermaps-validation', 'wp-submit': 'Log In', 'testcookie': '1'})
# Administrative operation selectors remain unread until method and nonce pass.
status, _, _ = request('/wp-admin/admin-post.php?action=cybermaps_cloudflare_oauth_start&operation%5B%5D=remove')
assert status == 403, ('Cloudflare start accepted a missing nonce', status)
status, _, _ = request('/wp-admin/admin-post.php', {'action': 'cybermaps_cloudflare_oauth_start', 'operation': 'remove'})
assert status == 405, ('Cloudflare start accepted POST', status)
status, _, page = request('/wp-admin/admin.php?page=cybermaps-settings&view=setup')
assert status == 200, status
match = re.search(rb'var cybermapsSetupWizard = (\{.*?\});', page)
assert match, 'Setup assets were not localized for the administrator.'
config = json.loads(match[1])


def ajax(action, fields=None, nonce=None):
    return request('/wp-admin/admin-ajax.php', {
        'action': 'cybermaps_setup_wizard_' + action,
        'nonce': config['nonce'] if nonce is None else nonce,
        **(fields or {}),
    })


status, _, body = ajax('bootstrap')
bootstrap = json.loads(body)
assert status == 200 and bootstrap['success'], (status, body)
payload = {'wizard_version': bootstrap['data']['wizard_version'], 'answers': bootstrap['data']['answers']}
payload['answers']['identity_name'] = '<b>HTTP Review</b>'
payload['answers']['identity_description'] = 'First <b>line</b>\nSecond line'
fields = {'payload': json.dumps(payload)}
status, _, body = ajax('preview', fields)
preview = json.loads(body)
assert status == 200 and preview['success'], (status, body)
for invalid in ('{bad', 'x' * 16385, '{"wizard_version":2,"answers":{}}'):
    status, _, body = ajax('apply', {'payload': invalid})
    assert status == 400 and json.loads(body)['success'] is False, (status, body)
status, _, body = ajax('apply', fields, nonce='invalid')
assert status == 403, (status, body)
status, _, body = ajax('apply', fields)
assert status == 400 and json.loads(body)['success'] is False, (status, body)
fields.update({
    'environment_hash': preview['data']['environment_hash'],
    'content_hash': preview['data']['preview']['content_hash'],
    'configuration_hash': preview['data']['preview']['configuration_hash'],
})
status, _, body = ajax('apply', fields)
assert status == 200 and json.loads(body)['success'], (status, body)
status, _, body = ajax('bootstrap')
answers = json.loads(body)['data']['answers']
assert answers['identity_name'] == 'HTTP Review', answers
assert answers['identity_description'] == 'First line\nSecond line', answers
print('HTTP GET/HEAD/304, representation digests, admin assets, setup rejection and preview/apply persistence passed.')
