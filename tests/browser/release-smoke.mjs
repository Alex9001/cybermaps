import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { writeFileSync } from 'node:fs';
const require = createRequire(resolve('docs/generated/tmp/browser-tools/package.json'));
const { chromium } = require('playwright');
const [origin, output] = process.argv.slice(2);
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
try {
  await page.goto(origin + '/wp-login.php');
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('cybermaps-validation');
  await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
  await page.goto(origin + '/wp-admin/admin.php?page=cybermaps-settings&view=setup');
  await page.waitForFunction(() => document.querySelector('#cybermaps-setup-wizard-root')?.textContent.trim().length > 20);
  if (await page.locator('#cybermaps-setup-wizard-root svg').count() === 0) throw new Error('Wizard icons missing');
  await page.goto(origin + '/wp-admin/admin.php?page=cybermaps-settings&tab=sitemaps');
  const credit = page.locator('input[name="cybermaps_settings[show_sitemap_attribution]"]');
  if (await credit.isChecked()) throw new Error('Public credit enabled without consent');
  for (const enabled of [true, false]) {
    await credit.setChecked(enabled);
    await Promise.all([page.waitForNavigation(), page.locator('#submit').click()]);
    if (await credit.isChecked() !== enabled) throw new Error('Attribution preference did not persist');
  }
  const trigger = page.locator('button[popovertarget]').first();
  if (await trigger.count() === 0) throw new Error('Accessible tooltip trigger missing');
  const target = await trigger.getAttribute('popovertarget');
  await trigger.click();
  if (!await page.locator('#' + target).isVisible()) throw new Error('Tooltip did not open');
  await page.keyboard.press('Escape');
  await page.goto(origin + '/wp-admin/admin.php?page=cybermaps-settings&tab=ai');
  if (await page.getByRole('link', {name: 'Install MCP Adapter', exact: true}).count() !== 1) throw new Error('Optional MCP install guidance missing');
  if (await page.locator('select[name="cybermaps_settings[mcp_mode]"]').count()) throw new Error('MCP opt-in shown without dependency');
  if (errors.length) throw new Error(errors.join('\n'));
  writeFileSync(output, JSON.stringify({schema_version: 1, passed: true, checks: ['wizard', 'icons', 'attribution-save', 'tooltip', 'mcp-setup'], browser: await browser.version(), errors}, null, 2));
} catch (error) {
  writeFileSync(output, JSON.stringify({schema_version: 1, passed: false, errors: [...errors, String(error)]}, null, 2));
  await page.screenshot({path: output + '.png', fullPage: true});
  throw error;
} finally { await browser.close(); }
