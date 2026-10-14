#!/usr/bin/env node
/*
 * Dev-only: drives the REAL Settings screen of the Docker dev site in a headless browser.
 *   - Test connection on a healthy site, with a wrong key, and with the OAuth sign-in switched on;
 *   - the Claude card: turn on, the address to paste, turn off (with the confirm dialog).
 * It switches the OAuth sign-in on and off and always leaves it off. Dev site only (localhost).
 *
 *   node scripts/verify-settings-ui.cjs            (needs `make up`; Playwright from ../layoutlab)
 *
 * Env: PLAYWRIGHT_PATH, AIED_SITE (default http://localhost:8181), AIED_SHOTS (screenshot dir, default the OS temp dir).
 */
'use strict';

const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');
const SHOTS = process.env.AIED_SHOTS || fs.mkdtempSync(path.join(os.tmpdir(), 'aied-ui-'));
const { chromium } = require(PW);

if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(SITE)) {
  console.error('Dev site only (localhost). Refusing ' + SITE);
  process.exit(2);
}

function wp(...args) {
  return execFileSync('docker', ['compose', 'exec', '-T', 'wpcli', 'php', '-d', 'memory_limit=1G', '/usr/local/bin/wp', ...args], { cwd: ROOT, encoding: 'utf8' }).trim();
}

const fails = [];
function check(ok, label) {
  console.log((ok ? 'PASS  ' : 'FAIL  ') + label);
  if (!ok) fails.push(label);
}

(async () => {
  let browser;
  try {
    if (wp('eval', "echo AiEditorDivi5\\WP\\OAuthSettings::enabled() ? 'on' : 'off';") !== 'off') {
      throw new Error('OAuth is already on; switch it off first');
    }
    // wp-admin needs the auth cookie as well as the logged-in cookie (both carry one session token).
    const cookies = JSON.parse(wp('eval', '$u=1;$e=time()+3600;$t=WP_Session_Tokens::get_instance($u)->create($e);echo json_encode([[AUTH_COOKIE,wp_generate_auth_cookie($u,$e,"auth",$t)],[LOGGED_IN_COOKIE,wp_generate_auth_cookie($u,$e,"logged_in",$t)]]);'));

    browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1600 } });
    await ctx.addCookies(cookies.map(([name, value]) => ({ name, value, url: SITE })));
    const errors = [];
    const newPage = async () => {
      const pg = await ctx.newPage();
      pg.on('pageerror', (e) => errors.push(String(e)));
      // The probes deliberately provoke 401/403 answers; the browser logs those as "Failed to load resource".
      pg.on('console', (m) => { if (m.type() === 'error' && !/^Failed to load resource/.test(m.text())) errors.push(m.text()); });
      return pg;
    };
    let page = await newPage();

    const settings = SITE + '/wp-admin/admin.php?page=ai-editor-divi5&tab=settings';
    await page.goto(settings);

    // ---- healthy
    const btn = page.locator('#aied-test-connection-btn');
    check(await btn.isVisible(), 'the Test connection button is visible (JavaScript on)');
    await btn.click();
    await page.waitForSelector('.aied-test-finding', { timeout: 30000 });
    let text = await page.locator('#aied-test-result').innerText();
    check(/Connected: the address, the key and the permissions all work/.test(text), 'healthy site: "Connected…" — ' + text.replace(/\s+/g, ' ').slice(0, 140));
    check(await page.locator('.aied-test-finding--ok').count() >= 1, 'healthy site: shown as a success state');
    check(/Test again/.test(await btn.innerText()), 'the button now offers "Test again"');
    await page.locator('.aied-test-connection').screenshot({ path: path.join(SHOTS, '1-healthy.png'), animations: 'disabled' });

    // ---- wrong key (only in the page, nothing is changed on the site)
    await page.evaluate(() => { document.getElementById('aied-api-key').dataset.key = 'not-the-key'; });
    await btn.click();
    await page.waitForFunction(() => document.querySelector('.aied-test-finding--error'), null, { timeout: 30000 });
    text = await page.locator('#aied-test-result').innerText();
    check(/did not accept the API key/.test(text), 'wrong key: a plain-English "did not accept the API key" — ' + text.replace(/\s+/g, ' ').slice(0, 140));
    check(/RewriteRule/.test(text), 'wrong key: the .htaccess lines are shown');
    check(await page.locator('.aied-test-finding--ok').count() === 0, 'wrong key: no success line next to the error');
    await page.locator('.aied-test-connection').screenshot({ path: path.join(SHOTS, '2-wrong-key.png'), animations: 'disabled' });

    // ---- results are text, never markup
    const injected = await page.evaluate(() => document.querySelectorAll('#aied-test-result script, #aied-test-result img').length);
    check(injected === 0, 'results contain no injected elements');

    // ---- the Claude card: turn on
    await page.goto(settings);
    check(await page.locator('#aied-oauth-card').isVisible(), 'the Claude card is on the Settings screen');
    check(await page.locator('#aied-oauth-url').count() === 0, 'while off, no address is shown');
    await page.locator('#aied-oauth-card button:has-text("Turn on sign-in for Claude")').click();
    await page.waitForSelector('#aied-oauth-url', { timeout: 30000 });
    const url = (await page.locator('#aied-oauth-url').innerText()).trim();
    check(url === SITE + '/wp-json/ai-editor-divi5/v1/mcp', 'the address to paste is the MCP address: ' + url);
    check(/Sign-in for Claude is on/.test(await page.locator('.notice-success').first().innerText()), 'a confirmation notice is shown');
    check(/No app is connected yet/.test(await page.locator('#aied-oauth-card').innerText()), 'no app is connected yet');
    check(/does not start with https/.test(await page.locator('#aied-oauth-card').innerText()), 'a local http site gets the honest https warning');
    // A page that has gone through a form-submit redirect cannot be screenshotted in headless Chromium here; a fresh page can.
    await page.close();
    page = await newPage();
    await page.goto(settings);
    await page.locator('#aied-oauth-card').evaluate((el) => el.scrollIntoView());
    await page.screenshot({ path: path.join(SHOTS, '3-claude-card-on.png') });

    // ---- Test connection with the sign-in on
    await page.locator('#aied-test-connection-btn').click();
    await page.waitForSelector('.aied-test-finding', { timeout: 30000 });
    await page.waitForFunction(() => /Testing/.test(document.getElementById('aied-test-connection-btn').textContent) === false, null, { timeout: 30000 });
    text = await page.locator('#aied-test-result').innerText();
    check(/Claude on the web can find the sign-in details/.test(text), 'sign-in on: the sign-in details are found — ' + text.replace(/\s+/g, ' ').slice(0, 160));
    await page.locator('.aied-test-connection').screenshot({ path: path.join(SHOTS, '4-test-with-oauth.png'), animations: 'disabled' });

    // ---- turn off through the UI (the confirm dialog)
    page.once('dialog', (d) => d.accept());
    await page.locator('#aied-oauth-card button:has-text("Turn off and disconnect")').click();
    await page.waitForSelector('#aied-oauth-card button:has-text("Turn on sign-in for Claude")', { timeout: 30000 });
    check(wp('eval', "echo AiEditorDivi5\\WP\\OAuthSettings::enabled() ? 'on' : 'off';") === 'off', 'turned off through the UI');
    check(wp('eval', "echo AiEditorDivi5\\WP\\OAuthTable::exists() ? 'table' : 'none';") === 'none', 'and the table is gone');

    check(errors.length === 0, 'no JavaScript errors in the console' + (errors.length ? ': ' + errors.join(' | ').slice(0, 300) : ''));
    console.log('Screenshots: ' + SHOTS);
  } catch (e) {
    check(false, 'script error: ' + (e && e.message ? e.message : e));
  } finally {
    try { if (wp('eval', "echo AiEditorDivi5\\WP\\OAuthSettings::enabled() ? 'on' : 'off';") === 'on') wp('eval', 'AiEditorDivi5\\WP\\OAuthSettings::disable();'); } catch (e) { /* best effort */ }
    if (browser) await browser.close();
  }
  console.log(fails.length ? '\n' + fails.length + ' check(s) FAILED' : '\nAll checks passed.');
  process.exit(fails.length ? 1 : 0);
})();
