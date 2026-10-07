'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('assets/js/admin-command-center.js', 'utf8');
const start = source.indexOf('const ConfirmationController = () => {');
const end = source.indexOf('/**\n * Initialization', start);
let state = null;
let submissions = [];
let listeners = {};
class Element { closest() { return this; } }
class Form extends Element {
    constructor() { super(); this.dataset = {}; }
    requestSubmit(submitter) {
        const event = { type: 'submit', target: this, submitter, prevented: false, preventDefault() { this.prevented = true; } };
        listeners.submit(event);
        if (!event.prevented) submissions.push(submitter);
    }
}
class Button extends Element { constructor(form) { super(); this.form = form; this.dataset = { cybermapsConfirm: 'Delete?' }; } }
class Anchor extends Element {}
const form = new Form();
const button = new Button(form);
const ctx = {
    window: { Element, HTMLFormElement: Form, HTMLAnchorElement: Anchor, HTMLButtonElement: Button, location: { assign() {} } },
    document: { addEventListener(t, f) { listeners[t] = f; }, removeEventListener() {} },
    useState: () => [state, value => { state = value; }], useEffect: f => f(),
    el: (type, props, ...children) => ({ type, props, children }), Modal: 'Modal', Button: 'Button', __: s => s
};
vm.createContext(ctx);
vm.runInContext(source.slice(start, end) + '\nglobalThis.getController = ConfirmationController;', ctx);
ctx.getController();
let prevented = false;
listeners.click({ type: 'click', target: button, preventDefault() { prevented = true; } });
assert.equal(prevented, true);
ctx.getController().children[1].children[1].props.onClick();
assert.deepEqual(submissions, [button]);
assert.equal(button.dataset.cybermapsConfirmBypass, undefined);
form.requestSubmit(button);
assert.equal(submissions.length, 1, 'a second deletion must ask for confirmation again');
const status = fs.readFileSync('assets/js/system-status.js', 'utf8');
const s = status.indexOf('async ( event ) => {', status.indexOf("'cybermaps-status-verify'"));
const e = status.indexOf('\n\t} );', s) + 3;
const event = { currentTarget: { disabled: false } };
const captured = event.currentTarget;
const sctx = { announce() {}, config: { strings: {} }, post: async () => ({ verification: { passed: 1, total: 1 } }), renderVerification() {} };
vm.createContext(sctx);
vm.runInContext('globalThis.listener = ' + status.slice(s, e) + ';', sctx);
const result = sctx.listener(event);
event.currentTarget = null;
result.then(() => {
    assert.equal(captured.disabled, false);
    console.log('Admin confirmation and async button regressions passed.');
}).catch(error => { console.error(error); process.exitCode = 1; });
const { execFileSync } = require('node:child_process');
const php = String.raw`
function wp_die($message='', $title='', $args=[]) { throw new RuntimeException((string)($args['response'] ?? 500)); }
function wp_send_json_error($data, $status=400) { throw new RuntimeException((string)$status); }
function wp_send_json_success($data) { throw new RuntimeException('200'); }
function check_ajax_referer($action, $name) { return true; }
require 'tests/bootstrap.php';
$GLOBALS['cybermaps_mock_current_user_capabilities'] = ['manage_options'];
$out=[];
foreach ([['Cybermaps\\Admin\\SitemapStatus', 'handle_php_path_diagnostic', 'GET'], ['Cybermaps\\Admin\\EdgeOptimizationController', 'cloudflare_oauth_callback', 'POST'], ['Cybermaps\\Admin\\IdentityPageSelector', 'ajax_search', 'GET']] as [$class,$method,$request]) {
    $_SERVER['REQUEST_METHOD']=$request;
    $_POST=['query'=>['bad'], 'cursor'=>['bad']];
    try { (new $class())->$method(); $out[]='unexpected'; } catch (RuntimeException $e) { $out[]=$e->getMessage(); }
}
$_SERVER['REQUEST_METHOD']='POST';
$_POST=[];
$GLOBALS['cybermaps_mock_options']['cybermaps_settings']=['media_discovery_intensity'=>'standard'];
$GLOBALS['cybermaps_mock_post_types']=['post'];
$GLOBALS['cybermaps_mock_post_type_objects']=['post'=>(object)['public'=>true]];
$GLOBALS['wpdb']=new class {
    public string $posts='wp_posts'; public string $last_error='';
    function prepare($query,...$args) { return $query; }
    function get_col($query) { $this->last_error='SQL failed'; return []; }
    function get_var($query) { $this->last_error='SQL failed'; return '0'; }
};
foreach(['process_sync_batch','get_sync_stats'] as $method) {
    try { (new Cybermaps\Admin\MediaAuditor())->$method(); $out[]='unexpected'; } catch(RuntimeException $e) { $out[]=$e->getMessage(); }
}
echo json_encode($out);
`;
const env = { ...process.env, TMPDIR: process.cwd() + '/docs/generated/tmp', TMP: process.cwd() + '/docs/generated/tmp', TEMP: process.cwd() + '/docs/generated/tmp' };
assert.deepEqual(JSON.parse(execFileSync('php', ['-r', php], { env, encoding: 'utf8' })), ['405', '405', '405', '500', '500']);
console.log('Admin request-method and media database-failure regressions passed.');
const wizard = fs.readFileSync('assets/js/setup-wizard.js', 'utf8');
const wizardStart = wizard.indexOf('function finish() {');
const wizardEnd = wizard.indexOf('        if (status.loading)', wizardStart);
let liveAnswers = { identity_name: 'Submitted name' };
let resolvePreview;
let submittedApply;
let receiptAnswers;
const wizardContext = {
    validStep: () => true, status: { saving: false }, payload: () => JSON.stringify({ wizard_version: 1, answers: liveAnswers }),
    setStatus() {}, setPreview() {}, setResult() {}, setComplete() {},
    setAnswers(value) { receiptAnswers = value; }, allowExit: { current: false },
    window: { scrollTo() {} }, config: { strings: {} },
    ajax(action, values) {
        if (action === 'cybermaps_setup_wizard_preview') return new Promise(resolve => { resolvePreview = resolve; });
        submittedApply = values.payload;
        return Promise.resolve({ success: true });
    }
};
vm.createContext(wizardContext);
vm.runInContext(wizard.slice(wizardStart, wizardEnd) + '\nfinish();', wizardContext);
liveAnswers.identity_name = 'Changed during request';
resolvePreview({ environment_hash: 'environment', preview: { content_hash: 'content', configuration_hash: 'configuration' } });
setImmediate(() => {
    try {
        assert.equal(JSON.parse(submittedApply).answers.identity_name, 'Submitted name');
        assert.equal(receiptAnswers.identity_name, 'Submitted name');
        console.log('Setup wizard immutable submission and receipt regression passed.');
    } catch (error) { console.error(error); process.exitCode = 1; }
});
