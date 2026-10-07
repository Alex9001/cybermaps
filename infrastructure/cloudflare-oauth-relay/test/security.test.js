import assert from 'node:assert/strict';
import test from 'node:test';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import worker, { OAuthTransaction, validateCreateBody } from '../src/index.js';

if (!globalThis.crypto) globalThis.crypto = webcrypto;
const limiterNames = ['CREATE_IP_RATE_LIMITER', 'CREATE_BURST_RATE_LIMITER', 'POLL_RATE_LIMITER', 'RELAY_IP_RATE_LIMITER', 'RELAY_BURST_RATE_LIMITER'];
const body = { protocol_version: 1, code_challenge_method: 'S256', code_challenge: 'a'.repeat(43) };
function environment() {
  const env = {
    CLOUDFLARE_OAUTH_CLIENT_ID: 'fixture-client',
    CLOUDFLARE_OAUTH_SCOPES: 'zone.read zone-transform-rules.write cache-settings.write',
    CLOUDFLARE_OAUTH_REDIRECT_URI: 'https://connect.cybermaps.dev/cloudflare/callback',
    RATE_LIMIT_SECRET: 'x'.repeat(32),
    calls: [],
  };
  for (const name of limiterNames) env[name] = { limit: async () => ({ success: true }) };
  env.OAUTH_TRANSACTIONS = {
    idFromName: id => id,
    get: id => ({ fetch: async () => {
      env.calls.push(id);
      return new Response('{"status":"created"}', { status: id.startsWith('__budget__') ? 200 : 201 });
    } }),
  };
  return env;
}
function request(value, headers = {}) {
  return new Request('https://relay.test/v1/cloudflare/transactions', {
    method: 'POST', headers: { 'Content-Type': 'application/json', ...headers },
    body: typeof value === 'string' ? value : JSON.stringify(value),
  });
}

test('every required binding fails closed before any Durable Object use', async () => {
  for (const name of [...limiterNames, 'OAUTH_TRANSACTIONS']) {
    const env = environment();
    delete env[name];
    const response = await worker.fetch(request(body), env);
    assert.equal(response.status, 503, name);
    assert.deepEqual(env.calls, []);
    const health = await (await worker.fetch(new Request('https://relay.test/health'), env)).json();
    assert.equal(health.status, 'degraded');
    assert.deepEqual(health.controls, []);
  }
});

test('create schema rejects extra keys, arrays, wrong types and non-S256 lengths', async () => {
  for (const invalid of [{ ...body, unexpected: { nested: true } }, [body], { ...body, protocol_version: '1' }, { ...body, code_challenge: 'a'.repeat(44) }, null]) {
    assert.equal(validateCreateBody(invalid), false);
    const env = environment();
    assert.equal((await worker.fetch(request(invalid), env)).status, 400);
    assert.deepEqual(env.calls, []);
  }
  assert.equal((await worker.fetch(request(body), environment())).status, 201);
});

test('chunked bodies stop reading at the byte bound and cancel the stream', async () => {
  let read = 0;
  let cancelled = false;
  const stream = new ReadableStream({
    pull(controller) { read += 1024; controller.enqueue(new Uint8Array(1024)); },
    cancel() { cancelled = true; },
  }, { highWaterMark: 0 });
  const incoming = new Request('https://relay.test/v1/cloudflare/transactions', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: stream, duplex: 'half',
  });
  const env = environment();
  const response = await worker.fetch(incoming, env);
  assert.equal(response.status, 400);
  assert.equal((await response.json()).error, 'request_too_large');
  assert.equal(cancelled, true);
  assert.equal(read, 3072);
  assert.deepEqual(env.calls, []);
});

test('raw-byte length, media type and malformed JSON are enforced', async () => {
  for (const [value, headers] of [
    [JSON.stringify(body) + ' '.repeat(2048), {}],
    [JSON.stringify(body) + 'é'.repeat(1000), {}],
    [body, { 'Content-Type': 'application/jsonp' }],
    [body, { 'Content-Length': '-1' }],
    ['{not json}', {}],
  ]) {
    const env = environment();
    assert.equal((await worker.fetch(request(value, headers), env)).status, 400);
    assert.deepEqual(env.calls, []);
  }
});

function durableState() {
  const values = new Map();
  let pending = Promise.resolve();
  return {
    values,
    blockConcurrencyWhile(callback) {
      const next = pending.then(callback);
      pending = next.catch(() => {});
      return next;
    },
    storage: {
      async get(key) { return structuredClone(values.get(key)); },
      async put(key, value) { values.set(key, structuredClone(value)); },
      async deleteAll() { values.clear(); },
      async setAlarm() {},
    },
  };
}
const internal = (path, value, secret = '') => new Request('https://transaction.internal/' + path, {
  method: 'POST', headers: { Authorization: 'Bearer ' + secret },
  body: value === undefined ? undefined : JSON.stringify(value),
});
async function transactionFixture() {
  const state = durableState();
  const transaction = new OAuthTransaction(state);
  const secret = 'test-secret';
  const hash = Buffer.from(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(secret))).toString('hex');
  await transaction.fetch(internal('create', { state: 'expected-state', secretHash: hash, expiresAt: Date.now() + 300000, maxPolls: 48 }));
  return { transaction, state, secret };
}

test('serialized callbacks and consumers cannot replay or overwrite an authorization', async () => {
  const { transaction, state, secret } = await transactionFixture();
  const callbacks = await Promise.all([
    transaction.fetch(internal('callback', { state: 'expected-state', code: 'first-code' })),
    transaction.fetch(internal('callback', { state: 'expected-state', error: 'denied' })),
  ]);
  assert.deepEqual(callbacks.map(response => response.status), [200, 410]);
  const results = await Promise.all(Array.from({ length: 5 }, async () => (await transaction.fetch(internal('consume', undefined, secret))).json()));
  assert.equal(results.filter(result => result.status === 'authorized').length, 1);
  assert.equal(results.find(result => result.status === 'authorized').code, 'first-code');
  assert.equal(state.values.size, 0);
});

test('invalid state or consume secret cannot advance or delete the transaction', async () => {
  const { transaction, state } = await transactionFixture();
  assert.equal((await transaction.fetch(internal('callback', { state: 'wrong', code: 'code' }))).status, 400);
  assert.equal((await transaction.fetch(internal('consume', undefined, 'wrong'))).status, 403);
  assert.equal(state.values.get('transaction').status, 'pending');
});

test('example and production config disable invocation URL logs and declare all controls', () => {
  for (const file of ['wrangler.toml', 'wrangler.toml.example']) {
    const config = readFileSync(new URL('../' + file, import.meta.url), 'utf8');
    assert.match(config, /\[observability\.logs\]\s+invocation_logs = false/);
    for (const name of limiterNames) assert.ok(config.includes('name = "' + name + '"'));
  }
});
