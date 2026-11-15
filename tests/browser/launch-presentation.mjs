import { createRequire } from 'node:module';
import { resolve, join } from 'node:path';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

const require = createRequire(resolve('docs/generated/tmp/browser-tools/package.json'));
const { chromium } = require('playwright');
const { default: AxeBuilder } = require('@axe-core/playwright');
const [origin, destination, phase = 'configured'] = process.argv.slice(2);
const zipSha256 = process.env.CYBERMAPS_CAPTURE_ZIP_SHA256 || '';
const packageManifestSha256 = process.env.CYBERMAPS_CAPTURE_PACKAGE_SHA256 || '';
const version = process.env.CYBERMAPS_CAPTURE_VERSION || '';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(origin || '') || !destination) {
  throw Error('Use an isolated loopback WordPress fixture and a generated output directory.');
}
if (!/^[a-f0-9]{64}$/.test(zipSha256) || !/^[a-f0-9]{64}$/.test(packageManifestSha256) || !/^\d+\.\d+\.\d+$/.test(version)) {
  throw Error('Use the fixture orchestrator to bind captures to the installed candidate ZIP.');
}
const output = resolve(destination);
if (!output.startsWith(resolve('docs/generated/releases') + '/')) throw Error('Invalid presentation output.');
mkdirSync(output, { recursive: true });
mkdirSync(join(output, 'captures'), { recursive: true });
mkdirSync(join(output, 'qa'), { recursive: true });
mkdirSync(join(output, 'directory'), { recursive: true });
const directoryScreens = new Map(['discovery-overview', 'xml-sitemaps', 'ai-publishing', 'identity', 'sitemap-status', 'ai-status', 'reports', 'discovery-analytics'].map((id, index) => [id, index + 1]));
const settings = '/wp-admin/admin.php?page=cybermaps-settings&tab=';
const pages = [
  ['discovery-overview', settings + 'dashboard'], ['xml-sitemaps', settings + 'sitemaps'],
  ['html-sitemap', settings + 'shortcode'], ['ai-publishing', settings + 'ai'],
  ['identity', settings + 'schema'], ['reports', settings + 'review'],
  ['advanced-delivery', settings + 'advanced'],
  ['sitemap-status', '/wp-admin/admin.php?page=cybermaps-sitemap-status'],
  ['ai-status', '/wp-admin/admin.php?page=cybermaps-ai-discovery-status'],
  ['discovery-analytics', '/wp-admin/admin.php?page=cybermaps-discovery-analytics'],
  ['debugging', '/wp-admin/admin.php?page=cybermaps-system-status'],
  ['quick-setup', '/wp-admin/admin.php?page=cybermaps-settings&view=setup'],
];
const report = { schema_version: 1, phase, checked_at: new Date().toISOString(),
  origin, version, zip_sha256: zipSha256, package_manifest_sha256: packageManifestSha256,
  pages: [], interactions: [], journey_states: [], page_errors: [], console_errors: [], failures: [], captures: [],
  scope: 'Real demo installation; Axe WCAG 2.2 AA scan of plugin content, horizontal reflow and selected journeys. Automated checks do not replace visual review.' };
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
const page = await context.newPage();
const cdp = await context.newCDPSession(page);
page.setDefaultTimeout(15000);
page.on('pageerror', error => report.page_errors.push({ url: page.url(), error: String(error) }));
let intentionalRejection = false;
page.on('console', message => {
  if (message.type() === 'error') report.console_errors.push({url: page.url(), text: message.text(),
    expected: intentionalRejection && message.text().includes('403')});
});
const record = async (name, operation) => {
  try { const detail = await operation(); report.interactions.push({name, passed: true, detail}); }
  catch (error) { report.interactions.push({name, passed: false, error: String(error)}); report.failures.push(name + ': ' + error); }
};
const visit = async (path) => {
  const response = await page.goto(origin + path, {waitUntil: 'load', timeout: 60000});
  if (!response || response.status() !== 200) throw Error(`Unexpected response ${response?.status()} for ${path}`);
  await page.evaluate(() => document.fonts.ready);
  if (path.includes('view=setup')) await page.locator('#cybermaps-setup-wizard-root .cm-quick-setup__card').waitFor({state: 'visible'});
  if (path.startsWith(settings)) {
    const tab = path.slice(settings.length);
    if (!await page.locator('#cybermaps-tab-button-' + tab + '[aria-current="page"]').count()) throw Error('Requested settings tab did not render: ' + tab);
  }
  return response;
};
const capture = async (id, selector = null, folder = 'captures', position = 'top') => {
  await page.evaluate(position => {
    const remaining = Math.max(0, document.documentElement.scrollHeight - innerHeight);
    window.scrollTo({left: 0, top: position === 'bottom' ? remaining : position === 'middle' ? Math.floor(remaining / 2) : 0, behavior: 'instant'});
  }, position);
  const path = join(output, folder, id + '.png');
  if (selector) await (typeof selector === 'string' ? page.locator(selector) : selector).screenshot({path});
  else await page.screenshot({path, fullPage: folder === 'captures'});
  const bytes = readFileSync(path);
  const item = { id, file: folder + '/' + id + '.png', sha256: createHash('sha256').update(bytes).digest('hex'),
    version, zip_sha256: zipSha256, package_manifest_sha256: packageManifestSha256, captured_at: new Date().toISOString(),
    width: bytes.readUInt32BE(16), height: bytes.readUInt32BE(20), url: page.url(),
    frame: selector ? 'element' : folder === 'captures' ? 'full-page' : 'viewport', scroll_y: await page.evaluate(() => scrollY),
    alterations: 'None. Actual browser capture of a seeded fictional demo site.' };
  report.captures.push(item);
  return item;
};

try {
  await page.goto(origin + '/wp-login.php');
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('cybermaps-presentation');
  await Promise.all([page.waitForNavigation(), page.locator('#wp-submit').click()]);
  report.login_landing = page.url();
  if (!(await context.cookies()).some(cookie => cookie.name.startsWith('wordpress_logged_in_'))) throw Error('WordPress authentication failed.');
  if (phase === 'default') {
    for (const [id, path] of pages.filter(([id]) => ['discovery-overview', 'ai-publishing', 'reports', 'discovery-analytics'].includes(id))) {
      await visit(path);
      const text = await page.locator('#wpbody-content').innerText();
      if (text.includes('Open beta feedback')) throw Error('Retired beta sidebar present.');
      if (id === 'reports' && !text.includes('Run the first report')) throw Error('Expected empty report history.');
      if (id === 'discovery-analytics' && !text.includes('Recording is disabled')) throw Error('Analytics recorded data without opt-in.');
      if (id === 'ai-publishing' && await page.locator('input[name="cybermaps_settings[enable_mcp_adapter]"]').count()) throw Error('Adapter consent shown without dependency.');
      const a11y = await new AxeBuilder({page}).include('#wpbody-content').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
      const serious = a11y.violations.filter(v => ['serious', 'critical'].includes(v.impact));
      if (serious.length) report.failures.push(`Default state ${id}: ${serious.map(v => v.id).join(', ')}`);
      await capture('default-' + id, null, 'qa');
      report.pages.push({id, width: 1440, passed: serious.length === 0, state: 'fresh-default', violations: serious});
    }
  } else {
    await record('anonymous-demo-endpoint-observations', async () => {
      const userAgent = 'CybermapsPresentationTest/1.0 (fictional local demo)';
      const anonymous = await browser.newContext({userAgent});
      const observed = [];
      try {
        for (const path of ['/ai.json', '/llms.txt', '/llms-full.txt', '/llms-tldr.txt', '/ai-discovery', '/ai-usage.json']) {
          const response = await anonymous.request.get(origin + path);
          observed.push({path, status: response.status(), content_type: response.headers()['content-type']});
          if (response.status() !== 200) throw Error('Demo publication request failed: ' + path + ' ' + response.status());
        }
      } finally { await anonymous.close(); }
      return {user_agent: userAgent, requests: observed, purpose: 'Real fixture traffic for endpoint analytics; no crawler identity is impersonated and no analytics rows are inserted.'};
    });
    for (const width of [1440, 768, 390, 320, 720]) {
      await page.setViewportSize({width, height: 1000});
      if (width === 720) await cdp.send('Emulation.setDeviceMetricsOverride', {width: 720, height: 500, deviceScaleFactor: 2, mobile: false, screenWidth: 1440, screenHeight: 1000});
      for (const [id, path] of pages) {
        try {
          await visit(path);
          const geometry = await page.evaluate(() => ({
            innerWidth, scrollWidth: document.documentElement.scrollWidth,
            containment_failures: Array.from(document.querySelectorAll('.cm-arch-grid > .cm-arch-group')).filter(node => {
              const rect = node.getBoundingClientRect(), parent = node.parentElement.getBoundingClientRect();
              return rect.left < parent.left - 2 || rect.right > parent.right + 2;
            }).map(node => ({class: node.className, width: node.getBoundingClientRect().width, parent_width: node.parentElement.getBoundingClientRect().width})),
            offenders: Array.from(document.querySelectorAll('#wpbody-content *')).filter(node => {
              const rect = node.getBoundingClientRect();
              return rect.width > 0 && rect.right > innerWidth + 2 && getComputedStyle(node).visibility !== 'hidden';
            }).slice(-12).map(node => ({tag: node.tagName, id: node.id, class: node.className, text: node.textContent.trim().slice(0, 100), right: Math.round(node.getBoundingClientRect().right)})),
          }));
          const a11y = await new AxeBuilder({page}).include('#wpbody-content').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
          const violations = a11y.violations.map(v => ({id: v.id, impact: v.impact, description: v.description, help: v.helpUrl,
            nodes: v.nodes.map(n => ({target: n.target, html: n.html, summary: n.failureSummary}))}));
          const serious = violations.filter(v => ['serious', 'critical'].includes(v.impact));
          const passed = geometry.scrollWidth <= width + 2 && geometry.containment_failures.length === 0 && serious.length === 0;
          report.pages.push({id, width, mode: width === 720 ? '200%-equivalent-reflow' : 'viewport', passed, geometry, violations});
          if (!passed) report.failures.push(`Presentation ${id} at ${width}px: overflow=${geometry.scrollWidth}, parent-containment=${geometry.containment_failures.length}, serious=${serious.length}`);
          writeFileSync(join(output, 'browser-progress.json'), JSON.stringify(report, null, 2) + '\n');
          console.log(`${id} ${width}px: ${passed ? 'passed' : 'FINDINGS'} (${serious.length} serious accessibility findings, ${geometry.scrollWidth}px document)`);
          if (width === 1440 && id !== 'quick-setup') await capture(id);
          if (width === 1440 && directoryScreens.has(id)) await capture('screenshot-' + directoryScreens.get(id), null, 'directory');
          if (width === 1440 && await page.evaluate(() => document.documentElement.scrollHeight > 2500)) {
            await capture(id + '-middle', null, 'qa', 'middle');
            await capture(id + '-bottom', null, 'qa', 'bottom');
          }
          await capture(id + '-' + width, null, 'qa');
          if (id === 'ai-publishing') await capture('content-signal-control-' + width, page.locator('.cm-content-signal-control').first().locator('..'), 'qa');
          if (id === 'ai-status') await capture('protocol-conformance-' + width, page.locator('.cm-discovery-status-endpoint-row').first(), 'qa');
          if (id === 'html-sitemap') await capture('html-display-mode-' + width, page.locator('.cm-sc-mode').locator('..'), 'qa');
        } catch (error) {
          report.pages.push({id, width, passed: false, error: String(error)});
          report.failures.push(`Presentation ${id} at ${width}px: ${error}`);
        }
      }
    }
    await cdp.send('Emulation.clearDeviceMetricsOverride');
    await page.setViewportSize({width: 1440, height: 1000});
    await record('support-and-dependency-links', async () => {
      await visit(settings + 'ai');
      for (const name of ['WordPress.org support', 'Browse existing issues', 'Report a security issue privately', 'Install MCP Adapter']) {
        if (await page.getByRole('link', {name, exact: true}).count() !== 1) throw Error('Missing link: ' + name);
      }
      if (await page.locator('input[name="cybermaps_settings[enable_mcp_adapter]"]').count()) throw Error('Adapter absent but opt-in control shown.');
      return {adapter: 'absent', optional_install_guidance: true};
    });
    await record('keyboard-tooltip', async () => {
      await visit(settings + 'sitemaps');
      const trigger = page.locator('button[popovertarget]').first();
      await trigger.focus();
      await page.keyboard.press('Enter');
      const id = await trigger.getAttribute('popovertarget');
      if (!await page.locator('#' + id).isVisible()) throw Error('Tooltip did not open from keyboard.');
      await page.keyboard.press('Escape');
      if (await page.locator('#' + id).isVisible()) throw Error('Tooltip did not dismiss with Escape.');
      await page.keyboard.press('Tab');
      return await page.evaluate(() => ({tag: document.activeElement.tagName, text: document.activeElement.textContent.trim().slice(0, 120)}));
    });
    await record('xml-settings-save-and-restore', async () => {
      await visit(settings + 'sitemaps');
      const input = page.locator('input[name="cybermaps_settings[enable_xml_sitemaps]"]');
      for (const enabled of [false, true]) {
        await input.setChecked(enabled);
        await Promise.all([page.waitForNavigation(), page.locator('#submit').click()]);
        if (await input.isChecked() !== enabled) throw Error('XML management save failed.');
      }
      return {saved_disabled: true, restored_enabled: true};
    });
    await record('shortcode-controls-and-invalid-input', async () => {
      await visit(settings + 'shortcode');
      const layout = page.locator('.cm-sc-layout');
      await layout.selectOption('columns');
      if (!(await page.locator('#cm-shortcode-preview').innerText()).includes('columns')) throw Error('Shortcode preview did not update.');
      await page.locator('.cm-sc-exclude').fill('about"]');
      await page.locator('.cm-sc-exclude').blur();
      if (!await page.locator('#cm-sc-exclude-error').isVisible()) throw Error('Invalid input feedback absent.');
      await capture('shortcode-input-error', null, 'qa');
      return {preview_updated: true, invalid_input_feedback: true};
    });
    await record('keyboard-horizontal-tables', async () => {
      await page.setViewportSize({width: 390, height: 844});
      const observed = [];
      for (const [name, path, selector] of [
        ['analytics', '/wp-admin/admin.php?page=cybermaps-discovery-analytics', '.cm-analytics-table-scroll'],
        ['debugging', '/wp-admin/admin.php?page=cybermaps-system-status', '.cm-status-table-wrap'],
        ['reports', settings + 'review', '.cm-report-table-scroll'],
      ]) {
        await visit(path);
        let count = 0;
        for (const region of await page.locator(selector).all()) {
          if (!await region.evaluate(element => element.scrollWidth > element.clientWidth + 2)) continue;
          if (await region.getAttribute('tabindex') !== '0' || !await region.getAttribute('aria-label')) throw Error(name + ': unnamed or unfocusable table region');
          await region.focus();
          await page.keyboard.press('ArrowRight');
          await region.evaluate(element => new Promise((resolve, reject) => {
            const start = performance.now();
            const check = () => element.scrollLeft > 0 ? resolve() : performance.now() - start > 1000 ? reject(Error('Arrow key did not scroll table')) : requestAnimationFrame(check);
            check();
          }));
          observed.push({page: name, label: await region.getAttribute('aria-label'), scrollLeft: await region.evaluate(element => element.scrollLeft)});
          count++;
        }
        if (!count) throw Error(name + ': fixture has no horizontally scrolling table to test');
        if (name === 'analytics') {
          const feed = page.locator('.cm-request-log-card');
          if (await feed.getAttribute('tabindex') !== '0' || !await feed.getAttribute('aria-label')) throw Error('Recent request log is not a named keyboard region.');
          if (!await feed.evaluate(element => element.scrollHeight > element.clientHeight + 2)) throw Error('Fixture request log does not exercise vertical scrolling.');
          await feed.focus();
          await page.keyboard.press('ArrowDown');
          await feed.evaluate(element => new Promise((resolve, reject) => {
            const start = performance.now();
            const check = () => element.scrollTop > 0 ? resolve() : performance.now() - start > 1000 ? reject(Error('Arrow key did not scroll request log')) : requestAnimationFrame(check);
            check();
          }));
          observed.push({page: name, label: await feed.getAttribute('aria-label'), scrollTop: await feed.evaluate(element => element.scrollTop)});
        }
      }
      await page.setViewportSize({width: 1440, height: 1000});
      return observed;
    });
    await record('destructive-log-clear-authority-and-confirmation', async () => {
      await visit('/wp-admin/admin.php?page=cybermaps-system-status');
      intentionalRejection = true;
      let denied;
      try {
        denied = await page.evaluate(async () => {
          const r = await fetch('admin-ajax.php', {method: 'POST', body: new URLSearchParams({action: 'cybermaps_debug_clear'})});
          return {status: r.status, body: await r.text()};
        });
      } finally { intentionalRejection = false; }
      if (denied.status !== 403) throw Error('Missing nonce did not produce403: ' + JSON.stringify(denied));
      const initialCount = await page.locator('#cybermaps-debug-count').innerText();
      page.once('dialog', dialog => dialog.dismiss());
      await page.locator('#cybermaps-debug-clear').click();
      if (await page.locator('#cybermaps-debug-count').innerText() !== initialCount || (await page.locator('#cybermaps-debug-feedback').innerText()).trim()) throw Error('Cancelling the diagnostic clear unexpectedly changed state.');
      const clearResponse = page.waitForResponse(response => response.url().endsWith('/admin-ajax.php') && (response.request().postData() || '').includes('cybermaps_debug_clear'));
      page.once('dialog', dialog => dialog.accept());
      await page.locator('#cybermaps-debug-clear').click();
      const response = await clearResponse;
      if (response.status() !== 200 || (await response.json()).success !== true) throw Error('Authorized diagnostic clear failed.');
      await page.waitForFunction(() => document.querySelector('#cybermaps-debug-feedback')?.textContent.trim() === 'Diagnostic events cleared.');
      if (await page.locator('#cybermaps-debug-count').innerText() !== '0') throw Error('Diagnostic count was not cleared.');
      return {missing_nonce: denied, confirmation_dismissed: true, confirmed_status: response.status(), confirmed_result: await page.locator('#cybermaps-debug-feedback').innerText()};
    });
    for (const [id, path] of [['xml-sitemap-output', '/sitemap.xml'], ['ai-manifest-output', '/ai.json']]) {
      await record(id, async () => {
        const response = await visit(path);
        let formatting = 'Plugin XML stylesheet';
        if (id === 'ai-manifest-output') {
          JSON.parse(await response.text());
          const prettyPrint = page.locator('input[type="checkbox"]');
          if (await prettyPrint.count() === 1) {
            await prettyPrint.check();
            if (!await prettyPrint.isChecked()) throw Error('Native JSON Pretty-print control did not activate.');
            formatting = 'Chromium native Pretty-print checkbox';
          } else {
            formatting = 'Native raw JSON; Chromium does not expose its Pretty-print control to this browser automation API.';
          }
        }
        const item = await capture(id);
        item.browser_formatting = formatting;
        return item;
      });
    }
    await record('offer-catalog', async () => { await visit(settings + 'schema'); return await capture('offer-catalog', '#cybermaps-catalogs-container'); });
    // Mutating journeys run after the gallery and Directory captures, so each
    // published image depicts the real, documented seeded configuration.
    await record('run-new-report-and-confirmed-delete', async () => {
      await visit(settings + 'review');
      const selectedReport = () => page.locator('.cm-report-run-header h3').innerText();
      const before = await selectedReport();
      await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Run new report', exact: true}).click()]);
      const created = await selectedReport();
      if (created === before || !/^Report #\d+$/.test(created)) throw Error('Run new report did not select a new saved report.');
      await capture('report-created', null, 'qa');
      await page.getByRole('button', {name: 'Delete report', exact: true}).click();
      const dialog = page.getByRole('dialog', {name: 'Confirm Action'});
      await dialog.waitFor({state: 'visible'});
      await capture('report-delete-confirmation', null, 'qa');
      await dialog.getByRole('button', {name: 'Cancel', exact: true}).click();
      if (await selectedReport() !== created) throw Error('Cancelling deletion changed the selected report.');
      await page.getByRole('button', {name: 'Delete report', exact: true}).click();
      await Promise.all([page.waitForNavigation(), dialog.getByRole('button', {name: 'Continue', exact: true}).click()]);
      if (!(await page.locator('#wpbody-content').innerText()).includes('The selected content report was deleted.')) throw Error('Confirmed report deletion did not show its success notice.');
      if (await selectedReport() !== before) throw Error('Deleting the new report did not restore the previous report.');
      return {previous_report: before, created_report: created, cancellation_preserved_report: true, confirmed_deletion_restored_previous_report: true};
    });
    await record('quick-setup-three-steps-and-apply', async () => {
      const states = [];
      const inspectStep = async (id) => {
        for (const width of [1440, 390]) {
          await page.setViewportSize({width, height: 1000});
          const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
          const a11y = await new AxeBuilder({page}).include('#wpbody-content').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
          const serious = a11y.violations.filter(v => ['serious', 'critical'].includes(v.impact));
          const state = {id, width, scroll_width: scrollWidth, violations: serious, passed: scrollWidth <= width + 2 && serious.length === 0};
          states.push(state);
          report.journey_states.push(state);
          await capture(id + '-' + width, null, 'qa');
          if (scrollWidth > width + 2 || serious.length) throw Error(id + ' has layout or accessibility findings at ' + width + 'px: ' + serious.map(v => v.id).join(', '));
        }
        await page.setViewportSize({width: 1440, height: 1000});
      };
      const choose = async (name, value) => {
        const input = page.locator(`input[name="${name}"][value="${value}"]`);
        await input.locator('..').click();
        if (!await input.isChecked()) throw Error('Setup choice was not selected: ' + name);
      };
      await visit('/wp-admin/admin.php?page=cybermaps-settings&view=setup');
      await choose('website_type', 'corporate');
      await inspectStep('quick-setup-website-selected');
      await page.getByRole('button', {name: 'Continue', exact: true}).click();
      await choose('ai_visibility', 'on');
      await choose('operations', 'insights');
      await inspectStep('quick-setup-priorities-selected');
      await page.getByRole('button', {name: 'Continue', exact: true}).click();
      await choose('identity_type', 'Organization');
      await page.getByLabel('Public name', {exact: true}).fill('Willow Studio');
      await page.getByLabel('A short introduction').fill('A fictional design studio used for Cybermaps release presentation checks.');
      await inspectStep('quick-setup-identity-selected');
      const applyResponse = page.waitForResponse(response => response.url().endsWith('/admin-ajax.php') && (response.request().postData() || '').includes('action=cybermaps_setup_wizard_apply'));
      await page.getByRole('button', {name: 'Finish setup', exact: true}).click();
      const response = await applyResponse;
      const applied = await response.json();
      if (response.status() !== 200 || applied.success !== true) throw Error('Setup apply was rejected: ' + JSON.stringify(applied));
      await page.getByRole('heading', {name: 'You’re all set', exact: true}).waitFor();
      await inspectStep('quick-setup-receipt');
      if (!(await page.locator('#wpbody-content').innerText()).includes('MCP Adapter')) throw Error('Setup receipt omitted the optional dependency guidance.');
      await Promise.all([page.waitForNavigation(), page.getByRole('link', {name: 'Go to Overview', exact: true}).click()]);
      await visit(settings + 'schema');
      if (await page.locator('#cybermaps-identity-name').inputValue() !== 'Willow Studio') throw Error('Setup identity was not persisted.');
      if (await page.locator('[name="cybermaps_identity_data[description]"]').inputValue() !== 'A fictional design studio used for Cybermaps release presentation checks.') throw Error('Setup introduction was not persisted.');
      await visit(settings + 'ai');
      if (!await page.locator('input[name="cybermaps_settings[enable_discovery_hub]"]').isChecked()) throw Error('Setup did not enable AI publications.');
      await visit('/wp-admin/admin.php?page=cybermaps-discovery-analytics');
      if (!await page.locator('#enable_analytics').isChecked()) throw Error('Setup did not enable requested analytics.');
      return {steps: 3, actual_preview_and_apply: true, persisted_identity_and_preferences_verified: true, apply_status: response.status(), states};
    });
  }
} catch (error) { report.failures.push(String(error)); }
finally {
  report.browser = await browser.version();
  report.passed = report.failures.length === 0 && report.page_errors.length === 0 && !report.console_errors.some(item => !item.expected);
  writeFileSync(join(output, phase === 'default' ? 'default-states.json' : 'browser.json'), JSON.stringify(report, null, 2) + '\n');
  await browser.close();
}
console.log(JSON.stringify({phase, passed: report.passed, pages: report.pages.length, captures: report.captures.length, failures: report.failures, page_errors: report.page_errors}, null, 2));
if (!report.passed) process.exitCode = 1;
