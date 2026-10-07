import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';

const require = createRequire(resolve('docs/generated/tmp/browser-tools/package.json'));
const { chromium } = require('playwright');
const script = readFileSync('assets/js/webmcp.js', 'utf8');
const requests = [];
const server = createServer((request, response) => {
  const url = new URL(request.url, 'http://localhost');
  requests.push({ path: url.pathname, cookie: request.headers.cookie || '', referer: request.headers.referer || '' });
  if (url.pathname === '/fixture') {
    response.setHeader('Content-Type', 'text/html');
    response.end('<!doctype html><title>WebMCP public boundary fixture</title>');
    return;
  }
  if (url.pathname === '/redirect') {
    response.writeHead(302, { Location: '/private' });
    response.end();
    return;
  }
  if (url.pathname === '/private') {
    response.writeHead(request.headers.cookie ? 200 : 403, { 'Content-Type': 'text/markdown' });
    response.end(request.headers.cookie ? '# Private content' : 'Access denied');
    return;
  }
  if (url.pathname === '/html') {
    response.writeHead(200, { 'Content-Type': 'text/html' });
    response.end('<input name="_wpnonce" value="private-nonce">');
    return;
  }
  if (url.pathname === '/oversized') {
    response.writeHead(200, { 'Content-Type': 'text/markdown', 'Content-Length': 4194305 });
    response.end('x'.repeat(4194305));
    return;
  }
  if (url.pathname === '/streamed-oversized') {
    response.writeHead(200, { 'Content-Type': 'text/markdown' });
    response.write('x'.repeat(2097152));
    response.end('x'.repeat(2097153));
    return;
  }
  const json = ['/search', '/discovery'].includes(url.pathname);
  response.writeHead(200, { 'Content-Type': json ? 'application/json' : 'text/markdown; charset=utf-8' });
  response.end(json ? '{"public":true}' : '# Public page');
});
await new Promise(resolveListen => server.listen(0, '127.0.0.1', resolveListen));
const origin = 'http://127.0.0.1:' + server.address().port;
let browser;
try {
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  await context.addCookies([{ name: 'wordpress_logged_in_fixture', value: 'administrator', url: origin }]);
  const page = await context.newPage();
  await page.goto(origin + '/fixture');
  await page.evaluate(() => {
    window.testTools = {};
    Object.defineProperty(document, 'modelContext', { value: { registerTool(tool) { window.testTools[tool.name] = tool; } } });
    window.cybermapsWebMCP = { searchUrl: '/search', discoveryUrl: '/discovery' };
  });
  await page.addScriptTag({ content: script });
  const execute = (name, input) => page.evaluate(async ({ name, input }) => {
    try { return { result: await window.testTools[name].execute(input) }; }
    catch (error) { return { error: error.message }; }
  }, { name: 'cybermaps.' + name, input });
  assert.equal((await execute('get_page_markdown', { url: '/public' })).result.content[0].text, '# Public page');
  assert.equal((await execute('search_site', { query: 'public', limit: 2 })).result.content[0].text, '{"public":true}');
  assert.equal((await execute('list_discovery_resources', {})).result.content[0].text, '{"public":true}');
  for (const url of ['/private', '/html', '/redirect', '/oversized', '/streamed-oversized', 'https://other.test/page', 'http://user:password@127.0.0.1/page']) {
    assert.ok((await execute('get_page_markdown', { url })).error, 'Must reject ' + url);
  }
  const beforeInvalid = requests.length;
  for (const input of [{ url: {} }, { url: '/public', extra: true }, [], null, { url: 'x'.repeat(2049) }]) {
    assert.ok((await execute('get_page_markdown', input)).error);
  }
  for (const input of [{ query: 'x', limit: 21 }, { query: {} }, { query: 'x', extra: true }]) {
    assert.ok((await execute('search_site', input)).error);
  }
  assert.equal(requests.length, beforeInvalid, 'Invalid inputs must fail before network');
  const publicRequests = requests.filter(request => !['/fixture', '/favicon.ico'].includes(request.path));
  assert.ok(publicRequests.length >= 8);
  for (const request of publicRequests) {
    assert.equal(request.cookie, '', request.path + ' sent logged-in cookie');
    assert.equal(request.referer, '', request.path + ' sent referrer');
  }
  assert.equal(requests.filter(request => request.path === '/private').length, 1, 'Redirect must not reach private target');
  console.log(JSON.stringify({ passed: true, browser: await browser.version(), public_requests: publicRequests.length, checks: ['anonymous public reads', 'private read denied', 'HTML rejected', 'redirect blocked', 'byte bounds', 'runtime input validation'] }));
} finally {
  if (browser) await browser.close();
  await new Promise(resolveClose => server.close(resolveClose));
}
