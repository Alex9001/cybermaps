// Run with: node --test tests/browser/admin-ui-contract.mjs
// Exercise the shipped scripts without network access or a WordPress database.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const edgeSource = fs.readFileSync(new URL('../../assets/js/edge-optimization.js', import.meta.url), 'utf8');
const identitySource = fs.readFileSync(new URL('../../assets/js/identity-page-selector.js', import.meta.url), 'utf8');
const maxBytes = 4 * 1024 * 1024;
const flush = async () => { for (let index = 0; index < 100; index++) await Promise.resolve(); };
const deferred = () => {
	let resolve;
	const promise = new Promise((done) => { resolve = done; });
	return { promise, resolve };
};

function element(dataset = {}) {
	const classes = new Set();
	return {
		dataset, attrs: {}, handlers: {}, children: [], disabled: false, hidden: false,
		textContent: '', value: '', tagName: 'BUTTON',
		href: 'https://site.test/wp-admin/admin-post.php?action=cybermaps_cloudflare_oauth_start',
		classList: {
			add: (name) => classes.add(name), remove: (name) => classes.delete(name),
			contains: (name) => classes.has(name),
			toggle(name, enabled) { enabled ? classes.add(name) : classes.delete(name); },
		},
		setAttribute(name, value) { this.attrs[name] = value; },
		getAttribute(name) { return this.attrs[name] ?? null; },
		removeAttribute(name) { delete this.attrs[name]; },
		addEventListener(name, callback) { this.handlers[name] = callback; },
		replaceChildren() { this.children = []; },
		appendChild(child) { this.children.push(child); },
	};
}

function streamedResponse({ text = '{}', chunks, headers = {}, status = 200, stall = false, stallCancel = false } = {}) {
	const stats = { reads: 0, readerCancels: 0, bodyCancels: 0 };
	const values = chunks || [new TextEncoder().encode(text)];
	const never = new Promise(() => {});
	const reader = {
		async read() {
			stats.reads++;
			if (stall) return never;
			return values.length ? { done: false, value: values.shift() } : { done: true };
		},
		cancel() { stats.readerCancels++; return stallCancel ? never : Promise.resolve(); },
	};
	const allHeaders = new Headers({ 'content-type': 'application/json', ...headers });
	return {
		stats, status, headers: allHeaders,
		body: { getReader: () => reader, cancel: async () => { stats.bodyCancels++; } },
		text() { throw new Error('An unbounded text() read is forbidden.'); },
	};
}

function edgeFixture({ resourceFetch, postFetch, resources, action = 'cybermaps_edge_verify', confirmed = true } = {}) {
	const button = element({ cybermapsEdgeAction: action });
	const disabledButton = element({ cybermapsEdgeAction: 'cybermaps_edge_rebuild_static' });
	disabledButton.disabled = true;
	const link = element({ cloudflareRequired: '1' });
	const disabledLink = element({ cloudflareRequired: '1' });
	for (const item of [link, disabledLink]) item.tagName = 'A';
	disabledLink.setAttribute('aria-disabled', 'true');
	const root = element(), feedback = element(), results = element(), token = element();
	const calls = [], observations = [], timerDelays = [], timers = new Map();
	let timerId = 0, now = 0;
	root.querySelectorAll = (selector) => ({
		'[data-cybermaps-edge-action]': [button, disabledButton],
		'[data-cybermaps-oauth-start]': [link, disabledLink],
		'[data-cloudflare-required="1"]': [link, disabledLink],
	}[selector] || []);
	const ids = {
		'cybermaps-edge-optimization': root, 'cybermaps-edge-feedback': feedback,
		'cybermaps-edge-verification-results': results, 'cybermaps-cloudflare-token': token,
	};
	const config = {
		ajaxUrl: 'https://site.test/wp-admin/admin-ajax.php', nonce: 'fixture-nonce',
		cloudflareDetected: confirmed, cloudflareHost: 'site.test',
		publicResources: resources || [{ url: 'https://site.test/ai.json', path: '/ai.json', profile: 'json', expected_type: 'application/json', header_policy: 'origin-authoritative', repair_scope: 'missing-static-mime-only' }],
	};
	// Observe the natural aggregation boundary without rewriting the shipped script.
	class ObservedPromise extends Promise {
		static all(values) {
			return Promise.all(values).then((rows) => { observations.push(rows); return rows; });
		}
	}
	const window = {
		cybermapsEdgeOptimization: config,
		location: { href: 'https://site.test/wp-admin/admin.php', origin: 'https://site.test' },
		setTimeout(callback, delay) { const id = ++timerId; timerDelays.push(delay); timers.set(id, { callback, due: now + delay, delay }); return id; },
		clearTimeout(id) { timers.delete(id); },
	};
	const fetch = async (url, options) => {
		const call = { url: String(url), options };
		calls.push(call);
		if (options.method === 'POST') {
			if (postFetch) return postFetch(call);
			return { ok: true, json: async () => ({ success: true, data: { status: 'idle' } }) };
		}
		return resourceFetch ? resourceFetch(call) : streamedResponse();
	};
	vm.runInNewContext(edgeSource, {
		window, document: { getElementById: (id) => ids[id] || null, createElement: () => element() },
		fetch, URL, FormData, Date, AbortController, TextDecoder, Promise: ObservedPromise, navigator: {},
	});
	return {
		button, disabledButton, link, disabledLink, root, feedback, results, token, calls, observations, timerDelays, timers,
		async nextTimer() {
			await flush();
			const entry = [...timers].sort((left, right) => left[1].due - right[1].due)[0];
			assert.ok(entry, 'Expected a pending retry or deadline.');
			timers.delete(entry[0]); now = entry[1].due; entry[1].callback(); await flush();
		},
	};
}

async function completeOperation(fixture) {
	await flush();
	let finished = false;
	const operation = fixture.button.handlers.click().then(() => { finished = true; });
	for (let count = 0; !finished && count < 20; count++) {
		await flush();
		if (!finished) await fixture.nextTimer();
	}
	assert.equal(finished, true, 'The action must settle within bounded deadlines and retries.');
	await operation;
	assert.equal(fixture.root.getAttribute('aria-busy'), 'false');
	assert.equal(fixture.button.disabled, false);
	assert.equal(fixture.disabledButton.disabled, true);
	assert.equal(fixture.disabledLink.getAttribute('aria-disabled'), 'true');
	return fixture.observations.at(-1)?.[0];
}

test('healthy origin MIME and body pass without edge, cache, CORS or Link overrides', async () => {
	const fixture = edgeFixture({ resourceFetch: () => streamedResponse({ headers: { 'content-type': 'Application/JSON; charset=UTF-8' } }) });
	const row = await completeOperation(fixture);
	assert.equal(row.ok, true);
	assert.equal(row.mime_fallback_observed, false);
	assert.equal(fixture.feedback.textContent, '1 of 1 public discovery resources passed.');
	assert.equal(fixture.feedback.classList.contains('is-error'), false);
	for (const call of fixture.calls.filter((entry) => entry.options.method === 'GET')) {
		assert.equal(new URL(call.url).search, '', 'The empty-query fallback must remain eligible.');
		assert.equal(call.options.credentials, 'omit');
		assert.equal(call.options.cache, 'no-store');
		assert.equal(call.options.signal.aborted, true, 'Finished reads release their fetch.');
	}
});

test('private, no-store, cookie and error responses are never observed as MIME fallback repairs', async () => {
	for (const scenario of [
		{ headers: { 'cache-control': 'public, private="field"', 'x-cybermaps-cloudflare-rule': 'v2-missing-mime' }, policy: true },
		{ headers: { 'cache-control': 'no-store', 'x-cybermaps-cloudflare-rule': 'v2-missing-mime' }, policy: true },
		{ headers: { 'set-cookie': 'fixture=1', 'x-cybermaps-cloudflare-rule': 'v2-missing-mime' } },
		{ status: 404, headers: { 'x-cybermaps-cloudflare-rule': 'v2-missing-mime' } },
		{ status: 503, headers: { 'x-cybermaps-cloudflare-rule': 'v2-missing-mime' } },
	]) {
		const fixture = edgeFixture({ resourceFetch: () => streamedResponse(scenario) });
		const row = await completeOperation(fixture);
		assert.equal(row.mime_fallback_observed, false);
		assert.equal(row.origin_policy_preserved, Boolean(scenario.policy));
		assert.equal(row.ok, !scenario.status, 'Healthy origin policy is valid delivery; HTTP errors fail.');
		if (scenario.status) assert.equal(fixture.feedback.classList.contains('is-error'), true);
	}
	const fallback = edgeFixture({ resourceFetch: () => streamedResponse({ headers: { 'x-cybermaps-cloudflare-rule': 'v2-missing-mime' } }) });
	assert.equal((await completeOperation(fallback)).mime_fallback_observed, true);
});

test('exact MIME, valid JSON root and API Catalog linkset are required', async () => {
	for (const scenario of [
		{ text: '{}', headers: { 'content-type': 'application/json-invalid' } },
		{ text: 'null' }, { text: '123' }, { text: '"scalar"' }, { text: '<html>error</html>' },
		{ text: '{}', profile: 'api_catalog' },
		{ text: '{"linkset":{}}', profile: 'api_catalog' },
	]) {
		const fixture = edgeFixture({
			resourceFetch: () => streamedResponse(scenario),
			resources: [{ url: 'https://site.test/ai-discovery', path: '/ai-discovery', profile: scenario.profile || 'json', expected_type: 'application/json' }],
		});
		assert.equal((await completeOperation(fixture)).ok, false);
	}
	const catalog = edgeFixture({
		resourceFetch: () => streamedResponse({ text: '{"linkset":[]}' }),
		resources: [{ url: 'https://site.test/.well-known/api-catalog', path: '/.well-known/api-catalog', profile: 'api_catalog', expected_type: 'application/json' }],
	});
	assert.equal((await completeOperation(catalog)).ok, true);
});

test('advertised and decoded stream sizes are capped and canceled without text()', async () => {
	const advertised = [];
	const tooLarge = edgeFixture({ resourceFetch: () => {
		const response = streamedResponse({ headers: { 'content-length': String(maxBytes + 1) } });
		advertised.push(response); return response;
	} });
	assert.equal((await completeOperation(tooLarge)).ok, false);
	assert.equal(advertised.length, 3, 'Verification retries are finite.');
	for (const response of advertised) {
		assert.equal(response.stats.reads, 0);
		assert.equal(response.stats.bodyCancels, 1);
	}
	const decoded = [];
	const compressed = edgeFixture({ resourceFetch: () => {
		const response = streamedResponse({ chunks: [new Uint8Array(maxBytes), new Uint8Array(1)], headers: { 'content-length': '8', 'content-encoding': 'gzip' } });
		decoded.push(response); return response;
	} });
	assert.equal((await completeOperation(compressed)).ok, false);
	for (const response of decoded) {
		assert.equal(response.stats.reads, 2);
		assert.equal(response.stats.readerCancels, 1);
	}
	const atLimit = edgeFixture({
		resourceFetch: () => streamedResponse({ text: 'x'.repeat(maxBytes), headers: { 'content-type': 'text/markdown', 'content-length': String(maxBytes) } }),
		resources: [{ url: 'https://site.test/SKILL.md', path: '/SKILL.md', profile: 'markdown', expected_type: 'text/markdown' }],
	});
	assert.equal((await completeOperation(atLimit)).ok, true);
});

test('fetch and body stalls meet the 15-second deadline, abort and unlock even if cancellation stalls', async () => {
	const pendingFetch = edgeFixture({ resourceFetch: () => new Promise(() => {}) });
	assert.equal((await completeOperation(pendingFetch)).ok, false);
	assert.equal(pendingFetch.calls.filter((call) => call.options.method === 'GET').length, 3);
	assert.equal(pendingFetch.timerDelays.filter((delay) => delay === 15000).length, 3);
	assert.ok(pendingFetch.calls.filter((call) => call.options.method === 'GET').every((call) => call.options.signal.aborted));
	const responses = [];
	const pendingBody = edgeFixture({ resourceFetch: () => {
		const response = streamedResponse({ stall: true, stallCancel: true }); responses.push(response); return response;
	} });
	assert.equal((await completeOperation(pendingBody)).ok, false);
	assert.ok(responses.every((response) => response.stats.readerCancels === 1));
	assert.equal(pendingBody.timers.size, 0);
	assert.match(pendingBody.results.children[0].children[0].textContent, /timed out/);
});

test('valid split UTF-8 passes and malformed UTF-8 fails safely', async () => {
	const bytes = new TextEncoder().encode('{"title":"é"}');
	const valid = edgeFixture({ resourceFetch: () => streamedResponse({ chunks: [bytes.slice(0, 11), bytes.slice(11)] }) });
	assert.equal((await completeOperation(valid)).ok, true);
	const invalid = edgeFixture({ resourceFetch: () => streamedResponse({ chunks: [new Uint8Array([0xc3, 0x28])] }) });
	assert.equal((await completeOperation(invalid)).ok, false);
});

test('busy OAuth activation is canceled and overlapping poll completion preserves disabled state', async () => {
	const initialPoll = deferred(), actionResponse = deferred();
	let polls = 0;
	const fixture = edgeFixture({ action: 'cybermaps_edge_clear_caches', postFetch: (call) => {
		if (call.options.body.get('action') === 'cybermaps_edge_oauth_poll') {
			polls++;
			return polls === 1 ? initialPoll.promise : { ok: true, json: async () => ({ success: true, data: { status: 'failed' } }) };
		}
		return actionResponse.promise;
	} });
	await flush();
	const operation = fixture.button.handlers.click();
	await flush();
	let prevented = false;
	fixture.link.handlers.click({ preventDefault() { prevented = true; } });
	assert.equal(prevented, true);
	assert.equal(fixture.timers.size, 0, 'Blocked links do not start another OAuth poll.');
	initialPoll.resolve({ ok: true, json: async () => ({ success: true, data: { status: 'pending' } }) });
	await flush();
	actionResponse.resolve({ ok: true, json: async () => ({ success: true, data: { message: 'Caches cleared.' } }) });
	await operation;
	assert.equal(fixture.root.getAttribute('aria-busy'), 'true');
	assert.equal(fixture.button.disabled, true);
	assert.equal(fixture.link.getAttribute('aria-disabled'), 'true');
	await fixture.nextTimer();
	assert.equal(fixture.root.getAttribute('aria-busy'), 'false');
	assert.equal(fixture.button.disabled, false);
	assert.equal(fixture.link.getAttribute('aria-disabled'), 'false');
	assert.equal(fixture.disabledButton.disabled, true);
	assert.equal(fixture.disabledLink.getAttribute('aria-disabled'), 'true');
	prevented = false;
	fixture.disabledLink.handlers.click({ preventDefault() { prevented = true; } });
	assert.equal(prevented, true);
});

function identityFixture() {
	const input = element(), feedback = element(), more = element(), wrapper = element();
	const saved = { value: '7', textContent: 'Saved selection', cloneNode() { return { ...this }; } };
	const select = { value: '7', options: [{ value: '0' }, saved], get selectedOptions() { return this.options.filter((option) => option.value === this.value); }, remove(index) { this.options.splice(index, 1); }, appendChild(option) { this.options.push(option); } };
	const handlers = {}, requests = [];
	input.matches = (selector) => selector === '.cybermaps-page-query';
	input.closest = () => wrapper;
	wrapper.querySelector = (selector) => ({ '.cybermaps-page-query': input, '.cybermaps-page-feedback': feedback, '.catalog-parent-select': select, '.cybermaps-page-next': more }[selector]);
	const searchButton = element(), nextButton = element();
	nextButton.classList.add('cybermaps-page-next');
	for (const button of [searchButton, nextButton]) button.closest = (selector) => selector === '.cybermaps-page-selector' ? wrapper : button;
	vm.runInNewContext(identitySource, {
		window: { cybermapsIdentityPages: { url: '/admin-ajax.php', nonce: 'fixture-nonce', query: 'Too short', found: 'Found', empty: 'Empty', error: 'Error' } },
		document: { addEventListener: (name, handler) => { handlers[name] = handler; }, createElement: () => ({}) },
		URLSearchParams, AbortController,
		fetch(url, options) { const pending = deferred(); requests.push({ url, options, ...pending }); return pending.promise; },
	});
	return {
		input, feedback, more, wrapper, select, requests,
		enter() { let prevented = false; handlers.keydown({ key: 'Enter', target: input, preventDefault() { prevented = true; } }); assert.equal(prevented, true); },
		edit(query) { input.value = query; handlers.input({ target: input }); },
		next() { handlers.click({ target: nextButton }); },
		async reply(index, pages, hasMore = true) {
			requests[index].resolve({ ok: true, json: async () => ({ success: true, data: { pages, next_cursor: 20, has_more: hasMore } }) }); await flush();
		},
	};
}

test('short queries cancel prior requests on editing and Enter without stale writes or Next', async () => {
	for (const mode of ['input', 'enter']) {
		const fixture = identityFixture();
		fixture.input.value = 'alpha'; fixture.enter();
		assert.equal(fixture.requests.length, 1);
		fixture.more.hidden = false;
		if (mode === 'input') fixture.edit('a');
		else { fixture.input.value = ''; fixture.enter(); }
		assert.equal(fixture.requests[0].options.signal.aborted, true);
		assert.equal(fixture.wrapper.getAttribute('aria-busy'), null);
		assert.equal(fixture.more.hidden, true);
		await fixture.reply(0, [{ id: 8, title: 'Stale Alpha result' }]);
		assert.equal(fixture.feedback.textContent, 'Too short');
		assert.equal(fixture.more.hidden, true);
		assert.deepEqual(fixture.select.options.map((option) => option.value), ['0', '7']);
		assert.equal(fixture.select.value, '7');
		assert.equal(fixture.requests.length, 1, 'Short queries do not submit a replacement request.');
	}
});

test('replacement queries fence older completion and preserve selection through pagination', async () => {
	const fixture = identityFixture();
	fixture.input.value = 'alpha'; fixture.enter();
	fixture.input.value = 'beta'; fixture.enter();
	assert.equal(fixture.requests[0].options.signal.aborted, true);
	await fixture.reply(0, [{ id: 8, title: 'Alpha' }]);
	assert.equal(fixture.wrapper.getAttribute('aria-busy'), 'true', 'An old finally cannot clear the current request state.');
	await fixture.reply(1, [{ id: 7, title: 'Duplicate saved' }, { id: 9, title: '<img src=x onerror=alert(1)>' }]);
	assert.equal(fixture.select.value, '7');
	assert.deepEqual(fixture.select.options.map((option) => option.value), ['0', '7', '9']);
	assert.equal(fixture.select.options[2].textContent, '<img src=x onerror=alert(1)>');
	assert.equal(fixture.more.hidden, false);
	fixture.next();
	assert.equal(fixture.requests[2].options.body.get('query'), 'beta');
	assert.equal(fixture.requests[2].options.body.get('cursor'), '20');
	await fixture.reply(2, [{ id: 10, title: 'Next result' }], false);
	assert.equal(fixture.select.value, '7');
	assert.equal(fixture.more.hidden, true);
	assert.equal(fixture.wrapper.getAttribute('aria-busy'), null);
});

test('edited valid query also rejects an old result before the replacement is submitted', async () => {
	const fixture = identityFixture();
	fixture.input.value = 'alpha'; fixture.enter(); fixture.edit('beta');
	await fixture.reply(0, [{ id: 8, title: 'Stale Alpha' }]);
	assert.deepEqual(fixture.select.options.map((option) => option.value), ['0', '7']);
	assert.equal(fixture.more.hidden, true);
});
