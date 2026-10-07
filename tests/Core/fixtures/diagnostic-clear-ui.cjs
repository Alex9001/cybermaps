'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('assets/js/system-status.js', 'utf8');

async function check(success) {
    let listener;
    let replacements = 0;
    const feedback = { textContent: '' };
    const log = { replaceChildren() { replacements++; }, appendChild() {} };
    const button = { addEventListener(event, callback) { listener = callback; } };
    const nodes = { 'cybermaps-debug-clear': button, 'cybermaps-debug-feedback': feedback, 'cybermaps-debug-log': log };
    const context = {
        window: { confirm: () => true, cybermapsSystemStatus: { strings: { cleared: 'Events cleared' } } },
        document: { getElementById: id => nodes[id] || null, querySelector: () => null, createElement: () => ({}) },
        FormData: class { append() {} },
        fetch: async () => ({ ok: success, json: async () => success
            ? { success: true, data: { state: { entry_count: 0 } } }
            : { success: false, data: { message: 'Clear was not verified' } } })
    };
    vm.createContext(context);
    vm.runInContext(source, context);
    await listener();
    assert.equal(replacements, success ? 1 : 0);
    assert.equal(feedback.textContent, success ? 'Events cleared' : 'Clear was not verified');
    return { success, replacements, message: feedback.textContent };
}
Promise.all([check(false), check(true)]).then(results => process.stdout.write(JSON.stringify(results) + '\n'))
    .catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
