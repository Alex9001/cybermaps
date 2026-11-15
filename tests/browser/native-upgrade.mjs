import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { writeFileSync } from 'node:fs';
const require = createRequire(resolve('docs/generated/tmp/browser-tools/package.json'));
const { chromium } = require('playwright');
const [origin, output, expectedVersion, networkFlag] = process.argv.slice(2);
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(origin) || !/^\d+\.\d+\.\d+$/.test(expectedVersion)) {
  throw new Error('Only a disposable loopback fixture and explicit version are supported.');
}
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
const result = { schema_version: 1, passed: false, method: 'dashboard', multisite: networkFlag === '1', expected_version: expectedVersion, errors };
try {
  await page.goto(origin + '/wp-login.php');
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('cybermaps-validation');
  await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
  const path = networkFlag === '1' ? '/wp-admin/network/plugins.php' : '/wp-admin/plugins.php';
  await page.goto(origin + path);
  const row = page.locator('tr.plugin-update-tr[data-plugin="cybermaps/cybermaps.php"]');
  await row.waitFor({ state: 'visible' });
  result.offered_text = await row.innerText();
  if (!result.offered_text.includes(expectedVersion)) throw new Error('Dashboard offered a different version.');
  await page.screenshot({ path: output + '.before.png', fullPage: true });
  const update = row.locator('.update-link');
  if (await update.count() !== 1) throw new Error('Expected the native WordPress update link.');
  await update.click();
  await page.waitForFunction(() => {
    const notice = document.querySelector('tr.plugin-update-tr[data-plugin="cybermaps/cybermaps.php"] .notice');
    if (notice?.classList.contains('notice-error')) throw new Error(notice.textContent);
    return notice?.classList.contains('notice-success');
  }, null, { timeout: 120_000 });
  result.update_notice = await row.innerText();
  await page.goto(origin + path);
  const plugin = page.locator('tr[data-plugin="cybermaps/cybermaps.php"]').first();
  result.installed_text = await plugin.innerText();
  if (!result.installed_text.includes(`Version ${expectedVersion}`)) throw new Error('Installed version is not visible after native dashboard update.');
  if (!await plugin.evaluate(element => element.classList.contains('active'))) throw new Error('Native dashboard update lost activation.');
  if (errors.length) throw new Error(errors.join('\n'));
  await page.screenshot({ path: output + '.after.png', fullPage: true });
  result.browser = await browser.version();
  result.passed = true;
} catch (error) {
  errors.push(String(error));
  await page.screenshot({ path: output + '.failure.png', fullPage: true }).catch(() => {});
  throw error;
} finally {
  writeFileSync(output, JSON.stringify(result, null, 2) + '\n');
  await browser.close();
}
