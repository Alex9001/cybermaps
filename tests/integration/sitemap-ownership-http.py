"""Check sitemap handover on fresh HTTP requests after persisted option changes."""
import json
import sys
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

if sys.flags.optimize:
    raise SystemExit('Validation refuses optimized Python.')

origin, state = sys.argv[1:3]
assert state in ('enabled', 'disabled', 'retained', 'plain', 'restored')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


client = urllib.request.build_opener(NoRedirect())
checks = []


def request(path, method='GET'):
    try:
        response = client.open(urllib.request.Request(origin + path, method=method), timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def xml(path, kind):
    status, _, body = request(path)
    assert status == 200, (state, path, status, body[:500])
    document = ET.fromstring(body)
    assert document.tag.endswith(kind), (path, document.tag)
    checks.append(path)
    return document


if state in ('enabled', 'restored'):
    status, headers, _ = request('/wp-sitemap.xml')
    assert status == 301 and headers['Location'] == origin + '/sitemap.xml', (state, status, dict(headers))
    xml('/sitemap.xml', 'sitemapindex')
    checks.append('core-redirect')
else:
    path = '/?sitemap=index' if state == 'plain' else '/wp-sitemap.xml'
    index = xml(path, 'sitemapindex')
    children = [node.text for node in index.findall('{*}sitemap/{*}loc') if node.text]
    assert children and all(url.startswith(origin + '/') for url in children), children
    child = urllib.parse.urlsplit(children[0])
    xml(child.path + ('?' + child.query if child.query else ''), 'urlset')
    if state == 'disabled':
        for path in ('/sitemap.xml', '/sitemap-posts-post-1.xml', '/sitemap-news.xml', '/sitemap-rss.xml'):
            for method in ('GET', 'HEAD'):
                status, headers, body = request(path, method)
                # Core itself redirects the conventional /sitemap.xml alias.
                handed_to_core = path == '/sitemap.xml' and status == 301 and headers.get('Location') == origin + '/wp-sitemap.xml'
                assert (status == 404 or handed_to_core) and (method != 'HEAD' or body == b''), (path, method, status, dict(headers))
            checks.append('unclaimed:' + path)
        status, _, body = request('/robots.txt')
        assert status == 200 and b'/wp-sitemap.xml' in body, (status, body)
        assert ('Sitemap: ' + origin + '/sitemap.xml').encode() not in body, body
        checks.append('native-robots-advertisement')
        xml('/ai-sitemap.xml', 'urlset')
    elif state == 'retained':
        xml('/sitemap.xml', 'sitemapindex')

print(json.dumps({'schema_version': 1, 'state': state, 'passed': True, 'checks': checks}, indent=2))
