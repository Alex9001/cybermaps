#!/usr/bin/env python3
"""Exercise the official adapter over HTTP on the exact installed Cybermaps ZIP."""
import base64
import json
import sys
if sys.flags.optimize:
    raise SystemExit('Validation refuses optimized Python; unset PYTHONOPTIMIZE and omit -O/-OO.')
from urllib.request import Request, urlopen
from urllib.error import HTTPError

base, credentials_file = sys.argv[1:3]
credentials = json.load(open(credentials_file))
endpoint = '/wp-json/mcp/cybermaps'


def request(path, data=None, user=None, session=None, modern=False, method=None):
    if path.startswith('/wp-json/'):
        path = '/?rest_route=/' + path[len('/wp-json/'):]
    headers = {'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream'}
    if modern:
        headers['MCP-Protocol-Version'] = '2026-07-28'
        headers['Mcp-Method'] = data['method']
        if 'name' in data['params']:
            headers['Mcp-Name'] = data['params']['name']
    if user:
        headers['Authorization'] = 'Basic ' + base64.b64encode((user + ':' + credentials[user]).encode()).decode()
    if session:
        headers['Mcp-Session-Id'] = session
        headers['MCP-Protocol-Version'] = '2025-11-25'
    try:
        response = urlopen(Request(base + path, data=None if data is None else json.dumps(data).encode(), headers=headers, method=method), timeout=30)
    except HTTPError as error:
        response = error
    body = response.read().decode()
    try:
        parsed = json.loads(body)
    except ValueError:
        parsed = body
    return response.status, response.headers, parsed


def rpc(method, params, user, session=None):
    return request(endpoint, {'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params}, user, session)


for user in ('mcp-reader', 'admin'):
    status, headers, body = rpc('initialize', {'protocolVersion': '2025-11-25', 'capabilities': {}, 'clientInfo': {'name': 'Cybermaps release gate', 'version': '1.0'}}, user)
    assert status == 200 and 'result' in body, (status, str(body)[:1200])
    session = headers.get('Mcp-Session-Id')
    assert session, 'Legacy handshake omitted the session'
    status, _, body = rpc('tools/list', {}, user, session)
    assert status == 200 and [tool['name'] for tool in body['result']['tools']] == ['cybermaps-search'], body
    status, _, body = rpc('resources/list', {}, user, session)
    resources = body['result']['resources']
    assert status == 200 and resources, body
    assert all(resource['uri'].startswith(base + '/') for resource in resources), resources
    uri = next(resource['uri'] for resource in resources if resource['uri'].endswith('/llms.txt'))
    status, _, body = rpc('resources/read', {'uri': uri}, user, session)
    assert status == 200 and body['result']['contents'][0]['text'], body
    for title, visible in [('CybermapsMCPPublic', True), ('CybermapsMCPPrivate', False), ('CybermapsMCPDraft', False), ('CybermapsMCPExcluded', False)]:
        status, _, body = rpc('tools/call', {'name': 'cybermaps-search', 'arguments': {'q': title}}, user, session)
        assert status == 200 and 'result' in body and not body['result'].get('isError'), body
        assert (title in json.dumps(body['result']['structuredContent']['results'])) is visible, body
    for arguments in ({'q': {'nested': 'input'}}, {'q': 'x', 'unknown': True}, {'q': 'x' * 201}):
        _, _, body = rpc('tools/call', {'name': 'cybermaps-search', 'arguments': arguments}, user, session)
        assert 'error' in body or body.get('result', {}).get('isError'), body
    for name in ('cybermaps-run-audit', 'cybermaps-submit-indexnow', 'cybermaps-purge-static-publications', 'cybermaps-reconcile-static-publications', 'cybermaps-test-hostile', 'mcp-adapter-execute-ability'):
        _, _, body = rpc('tools/call', {'name': name, 'arguments': {}}, user, session)
        assert 'error' in body or body.get('result', {}).get('isError'), body
    _, _, body = rpc('resources/read', {'uri': 'file:///etc/passwd'}, user, session)
    assert 'error' in body or body.get('result', {}).get('isError'), body

modern_meta = {'io.modelcontextprotocol/protocolVersion': '2026-07-28', 'io.modelcontextprotocol/clientCapabilities': {}}
status, _, body = request(endpoint, {'jsonrpc': '2.0', 'id': 2, 'method': 'tools/list', 'params': {'_meta': modern_meta}}, 'mcp-reader', modern=True)
assert status == 200 and [tool['name'] for tool in body['result']['tools']] == ['cybermaps-search'], body

status, _, body = rpc('initialize', {'protocolVersion': '2025-11-25', 'capabilities': {}, 'clientInfo': {'name': 'anonymous', 'version': '1'}}, None)
assert status in (401, 403), (status, str(body)[:1200])
for path in ('/wp-json/cybermaps/v1/mcp', '/wp-json/cybermaps/v1/oauth/token', '/wp-json/cybermaps/v1/oauth/register'):
    status, _, _ = request(path, {})
    assert status == 404, (path, status)
for ability in ('run-audit', 'submit-indexnow', 'purge-static-publications', 'reconcile-static-publications'):
    for method in ('GET', 'POST', 'DELETE'):
        status, _, body = request('/wp-json/wp-abilities/v1/abilities/cybermaps/' + ability + '/run', None if method == 'GET' else {}, 'admin', method=method)
        assert status == 404, (ability, method, status, body)
print('Official MCP Adapter: Subscriber/admin read-only catalog, resources, search eligibility, invalid input, hostile ability isolation, anonymous denial and retired routes passed.')
