#!/usr/bin/env node
/*
 * The live gate of the Lab screen (internal name Studio), on the Docker dev site (real WordPress, real MariaDB, real
 * Divi 5) and in a real browser (Playwright from ../layoutlab, or PLAYWRIGHT_PATH).
 *
 *   make verify-studio                       (runs scripts/test-verify-studio.cjs first)
 *   node scripts/verify-studio.cjs [--clean-orphans]
 *
 * Groups (each check prints PASS or FAIL):
 *   A  the brief table on MariaDB: dbDelta, save/get/link/forPage/prune/delete, another user's id, a corrupt row, uninstall's
 *      Studio steps (drop and create again; uninstall.php itself is never run: it drops other tables on the shared dev DB)
 *   B  the preview draft: the strict match, updates without history rows or revisions, cleanup, the stale sweep, look-alikes
 *   C  a rebuild of an untouched draft writes exactly ONE history row and the undo restores the bytes (Ruling 12)
 *   D  kses: a user without unfiltered_html saves the preview and a page; stored vs compiled, and the render (Ruling 13)
 *   E  a slashed JSON brief through the real admin-ajax: the stored text, then the literal render by Divi (Ruling 17)
 *   F  rebuild scenarios through the real handlers: edited, published, trashed, missing, no_access, no_undo, another user's
 *      id, a removed recipe
 *   G  the screen in a real administrator session (reduced motion): two goals through the five steps (one by keyboard only),
 *      widths, the preview devices, the Media Library, the clipboard fallback, Create, Rebuild, a double click, the Pages list
 *   H  the Dashboard names a Lab history row "Lab" (Ruling 9)
 *   I  the assistant round trip through the REAL MCP handler (the tool lab_build, called with the plugin's API key as an
 *      assistant does): catalog (size bound), a brief saved by the real admin-ajax, open (hash = the stored hash), fill with
 *      hostile words (& <b> $5 quotes, a backslash, a line break), the screen showing them, a stale hash (409 through the tool
 *      and through the screen's save), another user's brief id (a plain refusal), fill with create:true rendered by Divi
 *      Group G also covers the hand-off box: visible, "Copy instruction" copies a text naming lab_build and the brief id, the
 *      fallback textarea when the clipboard is refused
 *
 * Self-cleaning: every page, user, brief row, picture and option it creates is removed at the end, on a failure and on
 * SIGINT/SIGTERM. Throwaway pages are titled `aied studio <run>`, users are `aied_studio_<run>_<x>`; options and user 1's
 * plugin user meta are recorded first and put back. `--clean-orphans` removes what an interrupted run left. Dev site only.
 * ONE gate at a time (the run creates and deletes preview drafts and briefs of user 1).
 */
'use strict';

const path = require('path');
const fs = require('fs');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');
const { devSiteOnly } = require(path.join(ROOT, 'scripts', 'lib', 'throwaway-pages.cjs'));
const g = require(path.join(ROOT, 'scripts', 'lib', 'studio-gate.cjs'));

devSiteOnly(SITE);
const OUT = path.join(ROOT, 'dist', 'studio-gate');
fs.mkdirSync(OUT, { recursive: true });

const SCREEN = SITE + '/wp-admin/admin.php?page=ai-editor-divi5&tab=studio';
const DASHBOARD = SITE + '/wp-admin/admin.php?page=ai-editor-divi5&tab=dashboard';
const AJAX = SITE + '/wp-admin/admin-ajax.php';
const PLUGIN_DIR = '/plugins/jhmg-ai-editor-for-divi-5/';

// ── The server half ──────────────────────────────────────────────────

function php(cmd, params) {
  const b64 = Buffer.from(JSON.stringify(params || {})).toString('base64');
  let out;
  try {
    out = execFileSync('docker', ['compose', 'exec', '-T', 'wpcli', 'php', '-d', 'memory_limit=1G', '/usr/local/bin/wp', 'eval-file', '/scripts/verify-studio-server.php', cmd, b64, '--user=1'], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  } catch (e) {
    throw new Error(`server command ${cmd} failed: ${String(e.stdout || '').slice(-400)} ${String(e.stderr || '').slice(-400)}`);
  }
  const at = out.lastIndexOf('@@AIED@@');
  if (at < 0) throw new Error(`server command ${cmd} printed no result: ${out.slice(-400)}`);
  return JSON.parse(out.slice(at + 8).split('\n')[0]);
}

/** One tools/call through the real MCP route with the plugin's API key (an assistant's call). */
function mcp(tool, args) { return php('mcp', { tool, arguments: args || {} }); }

// ── Checks ───────────────────────────────────────────────────────────

const fails = [];
const results = [];
function check(ok, label, detail) {
  const line = (ok ? 'PASS  ' : 'FAIL  ') + label + (detail !== undefined && detail !== null ? '  ' + JSON.stringify(detail).slice(0, 600) : '');
  console.log(line);
  results.push({ ok: !!ok, label, detail });
  if (!ok) fails.push(label);
}
/** A check that could not be run here: printed and counted as SKIP, never as PASS. */
function skip(label, why) {
  console.log('SKIP  ' + label + (why ? '  (' + why + ')' : ''));
  results.push({ ok: true, skip: true, label, detail: why || null });
}
function serverChecks(list) {
  for (const c of list) {
    if (c.skip) skip(c.label);
    else check(c.ok, c.label, c.ok ? undefined : c.detail);
  }
}
function section(name) { console.log('\n== ' + name); }

// ── HTTP as a signed-in user ─────────────────────────────────────────

/** Writes or removes the temporary error logger in mu-plugins (that folder belongs to root on the dev site). */
function rootWrite(file, code) {
  execFileSync('docker', ['compose', 'exec', '-T', '-u', '0', 'wpcli', 'sh', '-c', 'cat > "$0" && chmod 644 "$0"', file], { cwd: ROOT, input: code });
}
function rootRemove(file) {
  try { execFileSync('docker', ['compose', 'exec', '-T', '-u', '0', 'wpcli', 'rm', '-f', file], { cwd: ROOT }); } catch (e) { console.log('WARNING could not remove ' + file); }
}
const MU_FILE = '/var/www/html/wp-content/mu-plugins/aied-studio-gate-errors.php';

function cookieHeader(sess) { return sess.cookies.map(([n, v]) => n + '=' + v).join('; '); }

async function get(sess, url) {
  const r = await fetch(url, { headers: { Cookie: cookieHeader(sess) }, redirect: 'manual' });
  return { status: r.status, html: await r.text() };
}

/** POST to admin-ajax as a browser form does (application/x-www-form-urlencoded); PHP adds its magic slashes. */
async function ajax(sess, action, fields) {
  const body = new URLSearchParams({ action: 'aied_studio_' + action, nonce: sess.nonce, ...(fields || {}) });
  const r = await fetch(AJAX, { method: 'POST', headers: { Cookie: cookieHeader(sess), 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
  const text = await r.text();
  let json = null;
  try { json = JSON.parse(text); } catch (e) { /* reported below */ }
  return { status: r.status, body: json, text: json ? null : text.slice(0, 300) };
}

async function signIn(state, userId, name) {
  const s = php('session', { user: userId });
  const sess = { user: userId, name, token: s.token, cookies: s.cookies };
  state.sessions.push(sess);
  const page = await get(sess, SCREEN);
  const cfg = g.labConfig(page.html);
  sess.nonce = cfg ? cfg.nonce : null;
  sess.cfg = cfg;
  sess.screenStatus = page.status;
  sess.printed = g.printedPhpErrors(page.html);
  return sess;
}

// ── State and cleanup ────────────────────────────────────────────────

const state = { token: process.pid + '-' + crypto.randomBytes(3).toString('hex'), snapshot: null, users: null, sessions: [], pages: [], browser: null, cleaning: false, cleaned: false };
const T = (name) => g.titled(state.token, name);

function cleanup() {
  if (state.cleaned) return true;
  state.cleaned = true;
  let ok = true;
  rootRemove(MU_FILE);
  for (const s of state.sessions) {
    try { php('end-session', { user: s.user, token: s.token }); } catch (e) { /* deleted users take their sessions with them */ }
  }
  try {
    const r = php('cleanup', { token: state.token, pages: state.pages, previewOf1: state.snapshot ? state.snapshot.previewOf1 : [], rows: state.snapshot ? state.snapshot.rows : [], options: state.snapshot ? state.snapshot.options : undefined, meta1: state.snapshot ? state.snapshot.meta1 : undefined });
    console.log('cleanup: ' + JSON.stringify(r));
    state.refused = r.refused || [];
  } catch (e) { console.log('WARNING cleanup failed: ' + e.message); ok = false; }
  return ok;
}

for (const sig of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
  process.on(sig, async () => {
    if (state.cleaning) return;
    state.cleaning = true;
    console.log('\n' + sig + ': cleaning up');
    try { if (state.browser) await state.browser.close(); } catch (e) { /* leaving */ }
    cleanup();
    process.exit(sig === 'SIGINT' ? 130 : 143);
  });
}

// ── Browser helpers ──────────────────────────────────────────────────

async function newContext(browser, sess, opts) {
  const ctx = await browser.newContext({ viewport: { width: 1600, height: 1000 }, reducedMotion: 'reduce', ...(opts || {}) });
  await ctx.addCookies(sess.cookies.map(([name, value]) => ({ name, value, url: SITE })));
  return ctx;
}

function watch(p, bag, label) {
  p.on('requestfailed', () => {});
  p.on('response', (r) => {
    if (r.status() >= 400 && !r.url().startsWith(AJAX)) bag.push(`${label || 'page'}: ${r.status()} ${r.url().slice(SITE.length)} (from ${r.request().redirectedFrom() ? r.request().redirectedFrom().url().slice(SITE.length) : 'no redirect'}, frame ${r.frame() === p.mainFrame() ? 'main' : 'child'})`);
  });
  p.on('response', (r) => {
    if (r.status() < 400 || !r.url().startsWith(AJAX)) return;
    const data = r.request().postData() || '';
    const m = data.match(/name="action"\r\n\r\n([a-z_]+)/) || data.match(/action=([a-z_]+)/);
    bag.ajax = bag.ajax || [];
    bag.ajax.push((m ? m[1] : '?') + ' ' + r.status());
  });
  p.on('pageerror', (e) => bag.push('uncaught: ' + String(e && e.message ? e.message : e).slice(0, 200)));
  p.on('console', (m) => {
    if (m.type() !== 'error') return;
    const url = (m.location() && m.location().url) || '';
    if (/favicon\.ico/.test(url)) return;
    if (url && !url.startsWith(SITE)) return;
    bag.push(('console: ' + m.text()).slice(0, 200) + (url ? ' (' + url.slice(SITE.length) + ')' : ''));
  });
}

async function openScreen(p, query) {
  await p.goto(SCREEN + (query || ''), { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('#aied-lab-app:not([hidden])', { timeout: 30000 });
  await waitPreview(p);
}

/** Waits until the preview state reads "up to date" (or a failure text) and the screen is not busy. */
async function waitPreview(p, timeout) {
  await p.waitForFunction(() => {
    const t = window.aiedLabConfig.i18n;
    const s = document.getElementById('aied-lab-preview-state').textContent;
    return s === t.previewReady || s === t.previewApprox || s === t.previewFailed;
  }, null, { timeout: timeout || 60000 });
  await p.waitForFunction(() => document.getElementById('aied-lab-app').getAttribute('aria-busy') !== 'true', null, { timeout: 60000 });
}

async function waitIdle(p) {
  await p.waitForTimeout(200);
  await p.waitForFunction(() => document.getElementById('aied-lab-app').getAttribute('aria-busy') !== 'true', null, { timeout: 90000 });
}

async function frameReady(p) {
  const handle = await p.waitForSelector('#aied-lab-frame[src]', { timeout: 30000 });
  const f = await handle.contentFrame();
  await f.waitForLoadState('load', { timeout: 60000 });
  await f.waitForSelector('.et_pb_section', { timeout: 30000 });
  return f;
}

/** Measures one rendered Divi page for the escaping checks. */
async function literalTexts(f, tag) {
  // Divi starts a counter when it scrolls into view, then counts up for about two seconds.
  await f.evaluate(() => { const c = document.querySelector('.et_pb_number_counter'); if (c) c.scrollIntoView({ block: 'center' }); });
  await f.waitForTimeout(3500);
  return f.evaluate((tag) => {
    const main = document.querySelector('#et-main-area, #main-content') || document.body;
    const find = (sel, part) => Array.from(main.querySelectorAll(sel)).find((e) => e.textContent.includes(tag + '-' + part));
    const heading = find('.et_pb_heading h1, .et_pb_heading h2, .et_pb_heading h3, .et_pb_module_heading', 'heading');
    const button = find('a.et_pb_button', 'button');
    const counter = find('.et_pb_number_counter .title, .et_pb_number_counter h3', 'counter');
    const body = find('.et_pb_text p, .et_pb_text_inner', 'body');
    const num = main.querySelector('.et_pb_number_counter .percent-value');
    return {
      heading: heading ? heading.textContent : null,
      'button label': button ? button.textContent : null,
      'counter title': counter ? counter.textContent : null,
      'text body': body ? body.textContent : null,
      number: num ? num.textContent.trim() : null,
      boldElements: Array.from(main.querySelectorAll('b')).filter((b) => b.textContent === 'bold').length,
      visibleCode: (document.body.innerText || '').includes('wp:divi/'),
      printed: (document.documentElement.outerHTML.match(/<b>(?:Warning|Notice|Deprecated|Fatal error)<\/b>:/g) || []).length,
    };
  }, tag);
}

async function renderLiteral(ctx, url, where, tag, number, label) {
  const p = await ctx.newPage();
  const errors = [];
  watch(p, errors);
  await p.goto(url, { waitUntil: 'load' });
  const m = await literalTexts(p.mainFrame(), tag);
  for (const w of where) {
    const r = g.literalShown(w.text, m[w.field]);
    check(r.ok, `${label}: the ${w.field} shows the typed text literally (${r.exact ? 'byte equal' : 'line break shown as white space'})`, r.ok ? undefined : { shown: m[w.field], sent: w.text, why: r.why });
  }
  check(m.number === number, `${label}: the counter number shows ${number}`, { shown: m.number });
  check(m.boldElements === 0 && !m.visibleCode && m.printed === 0 && errors.length === 0, `${label}: no <b> element made from the text, no block code on the page, no PHP message, no console error`, { bold: m.boldElements, code: m.visibleCode, printed: m.printed, errors });
  await p.close();
  return m;
}

/** Presses Tab (or Shift+Tab) until the element matching `test` has the focus; false after `max` presses. */
async function tabTo(p, test, max, shift) {
  for (let i = 0; i < (max || 400); i++) {
    if (await p.evaluate(test)) return true;
    await p.keyboard.press(shift ? 'Shift+Tab' : 'Tab');
  }
  return p.evaluate(test);
}

const focusedId = (p) => p.evaluate(() => (document.activeElement ? document.activeElement.id : ''));

async function measureFrame(p) {
  const handle = await p.$('#aied-lab-frame');
  const f = await handle.contentFrame();
  return f.evaluate(() => {
    const vis = (e) => { const r = e.getBoundingClientRect(); const c = getComputedStyle(e); return r.width > 0 && r.height > 0 && c.visibility !== 'hidden' && c.display !== 'none'; };
    const header = document.querySelector('.et-l--header') || document.body;
    const burger = Array.from(header.querySelectorAll('.et_mobile_nav_menu, .mobile_menu_bar')).some(vis);
    const links = Array.from(header.querySelectorAll('a')).filter((a) => vis(a) && !a.closest('.et_mobile_nav_menu') && a.textContent.trim() !== '');
    const menuModule = Array.from(header.querySelectorAll('.et_pb_menu__menu')).some(vis);
    return { innerWidth: window.innerWidth, scrollWidth: document.documentElement.scrollWidth, hamburger: burger, desktopLinks: links.length, linkTexts: links.slice(0, 6).map((a) => a.textContent.trim().slice(0, 30)), menuModuleVisible: menuModule };
  });
}

async function measureLayout(p) {
  return p.evaluate(() => {
    const r = (s) => { const b = document.querySelector(s).getBoundingClientRect(); return { top: Math.round(b.top + scrollY), bottom: Math.round(b.bottom + scrollY), left: Math.round(b.left), right: Math.round(b.right), width: Math.round(b.width) }; };
    return {
      vw: window.innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
      layoutWidth: r('.aied-lab-layout').width,
      bodyWidth: r('#wpbody-content').width,
      form: r('.aied-lab-form'),
      preview: r('.aied-lab-preview'),
    };
  });
}

/**
 * A stored page against the compiled layout: byte equal, or the same blocks and values. Divi 5.15.1 re-serializes the whole
 * page with WordPress's serialize_blocks() on save when any block holds an elementType, htmlBefore or htmlAfter value
 * (HtmlSecurity::sanitize_html_fields), which writes \" as \u0022; kses does the same for a user without unfiltered_html.
 */
function sameLayout(cmp) {
  return { ok: !!(cmp && (cmp.equal || cmp.equivalent) && !cmp.visible_code), how: cmp && cmp.equal ? 'byte equal' : 'same blocks and values; WordPress re-serialized the attributes on save' };
}

// ── The run ──────────────────────────────────────────────────────────

async function main() {
  if (process.argv.includes('--clean-orphans')) {
    rootRemove(MU_FILE);
    console.log(JSON.stringify(php('orphans', {})));
    const left = php('leftovers', {});
    console.log('left: ' + JSON.stringify(left));
    process.exit(0);
  }
  const { chromium } = require(PW);

  section('Setup');
  state.snapshot = php('snapshot', {});
  console.log(`run ${state.token} on ${state.snapshot.home}: WordPress ${state.snapshot.wp}, Divi ${state.snapshot.divi}, plugin ${state.snapshot.pluginVersion}`);
  if (state.snapshot.gatePages.length || Object.keys(state.snapshot.gateUsers).length || state.snapshot.gateRows.length) {
    console.log('Leftovers of an earlier run exist (' + state.snapshot.gatePages.length + ' pages, ' + Object.keys(state.snapshot.gateUsers).length + ' users, ' + state.snapshot.gateRows.length + ' brief rows). Run `make verify-studio ARGS=--clean-orphans` first.');
    state.snapshot = null; // nothing of this run to clean up
    return 2;
  }
  const guard = php('selftest-delete', {});
  check(guard.cases >= 10 && guard.wrong.length === 0, 'setup: the cleanup only ever deletes a post of this run (title, a gate user\'s preview title, or a gate user as author)', guard);
  check(state.snapshot.hasCapture, 'setup: the universal history capture (pre_post_update) is registered on this site');
  const made = php('users', { token: state.token });
  state.users = made.ids;
  check(made.caps.n_unfiltered === false && made.caps.e_unfiltered === false && made.caps.e_edit_pages && !made.caps.e_manage && made.caps.r_edit_others === false && made.caps.r_manage && made.caps.a_unfiltered, 'setup: five throwaway users with the intended rights', made.caps);
  const mu = php('errors-on', { token: state.token });
  if (mu.file !== MU_FILE) throw new Error('unexpected mu-plugin path ' + mu.file);
  rootWrite(MU_FILE, mu.code);
  state.mu = true;
  await fetch(SITE + '/?aied_gate_selftest=' + state.token);
  const self = php('errors-read', {}).log;
  check(self.includes('aied gate self-test ' + state.token), 'setup: the temporary PHP error logger is active in web requests (a self-test notice was logged)');
  const U = state.users;

  section('A. The brief table on the real MariaDB (Ruling 8)');
  serverChecks(php('table', { token: state.token, a: U.a, b: U.b }));

  section('B. The preview draft on the real WordPress');
  serverChecks(php('preview', { token: state.token, a: U.a, b: U.b }));

  section('Sessions');
  const s1 = await signIn(state, 1, 'admin');
  const sa = await signIn(state, U.a, 'second admin');
  const sn = await signIn(state, U.n, 'admin without unfiltered_html');
  const sr = await signIn(state, U.r, 'admin without edit_others_pages');
  for (const s of [s1, sa, sn, sr]) check(s.screenStatus === 200 && !!s.nonce && s.printed.length === 0, `sessions: the Lab screen loads for the ${s.name} with a nonce and no PHP message`, { status: s.screenStatus, printed: s.printed });
  const i18n = s1.cfg.i18n;

  const browser = await chromium.launch();
  state.browser = browser;
  const ctx1 = await newContext(browser, s1);
  await ctx1.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: SITE });

  section('E. A slashed JSON brief through the real admin-ajax (Ruling 17)');
  {
    const tag = 'E' + crypto.randomBytes(2).toString('hex');
    const { brief, where, number } = g.escapingBrief(T('E escaping'), tag);
    const r = await ajax(s1, 'create', { brief: JSON.stringify(brief) });
    check(r.status === 200 && r.body && r.body.success, 'E: the create answers 200 with JSON', { status: r.status, body: r.body ? r.body.data : r.text });
    if (r.body && r.body.success) {
      state.pages.push(r.body.data.page_id);
      const row = php('brief', { user: 1, id: r.body.data.brief_id });
      for (const w of where) {
        const stored = row && row.brief.sections[w.section].slots[w.slot];
        check(stored === w.text, `E: the stored ${w.field} equals the text sent, byte for byte (quotes, backslashes, &, <b>, $5, a line break)`, stored === w.text ? undefined : { stored, sent: w.text });
      }
      const cmp = php('compare', { user: 1, id: r.body.data.brief_id, page: r.body.data.page_id });
      const same = sameLayout(cmp);
      check(same.ok && cmp.hash_matches, `E: the page holds the compiled markup (${same.how}) and the brief knows its stored hash`, same.ok ? undefined : cmp.diff);
      const post = php('post', { id: r.body.data.page_id });
      await renderLiteral(ctx1, post.preview_url, where, tag, number, 'E (render by Divi)');
    }
  }

  section('D. kses: users without unfiltered_html (Ruling 13)');
  {
    const tag = 'D' + crypto.randomBytes(2).toString('hex');
    const esc = g.escapingBrief(T('D kses service'), tag);
    const k = php('kses', { token: state.token, e: U.e, brief: esc.brief });
    serverChecks(k.checks);
    const report = (what, equal, equivalent, code, diffs) => {
      check(!code && equivalent, `D: ${what}: the stored content is the compiled layout (${equal ? 'byte equal' : 'same blocks and values; bytes differ only by WordPress re-serializing the attributes'}), no block shown as text`, equivalent && !code ? (equal ? undefined : { differences: (diffs || []).slice(0, 2) }) : { equivalent, code, diffs: (diffs || []).slice(0, 4) });
    };
    report('Editor, preview draft inserted', k.preview.insert_equal, k.preview.insert_equivalent, k.preview.visible_code, k.preview.insert_diffs);
    report('Editor, preview draft updated', k.preview.update_equal, k.preview.update_equivalent, k.preview.visible_code, k.preview.update_diffs);
    report('Editor, Studio create', k.create.equal, k.create.equivalent, k.create.visible_code, k.create.diffs);
    check(k.create.ok && k.create.valid, 'D: Editor: the created draft passes the layout check as stored', { ok: k.create.ok, code: k.create.code });
    if (k.create.id) {
      state.pages.push(k.create.id);
      await renderLiteral(ctx1, k.create.preview_url, esc.where, tag, esc.number, 'D (Editor page, render by Divi)');
    }
    // The same through the real handlers, as an administrator without unfiltered_html.
    const tag2 = 'N' + crypto.randomBytes(2).toString('hex');
    const esc2 = g.escapingBrief(T('D kses ajax'), tag2);
    const pv = await ajax(sn, 'preview', { brief: JSON.stringify(esc2.brief) });
    const pvId = pv.body && pv.body.success && pv.body.data.preview.url ? Number(new URL(pv.body.data.preview.url).searchParams.get('page_id')) : 0;
    check(pv.status === 200 && pv.body && pv.body.data.preview && pv.body.data.preview.mode === 'draft' && pvId > 0, 'D: admin without unfiltered_html: the preview action makes a draft preview', { status: pv.status });
    if (pvId) {
      const c = php('compare-brief', { page: pvId, brief: esc2.brief });
      check(c.equivalent && !c.visible_code, `D: admin without unfiltered_html, preview draft: the stored content is the compiled layout (${c.equal ? 'byte equal' : 'same blocks and values, re-serialized'}), no block shown as text`, c.equivalent ? undefined : c.diff);
    }
    const cr = await ajax(sn, 'create', { brief: JSON.stringify(esc2.brief) });
    if (cr.body && cr.body.success) {
      state.pages.push(cr.body.data.page_id);
      const c = php('compare', { user: U.n, id: cr.body.data.brief_id, page: cr.body.data.page_id });
      check(c.equivalent && !c.visible_code && c.hash_matches, `D: admin without unfiltered_html, create: the stored content is the compiled layout (${c.equal ? 'byte equal' : 'same blocks and values, re-serialized'}), and the brief knows the stored hash`, c.equivalent ? undefined : c.diff);
      const post = php('post', { id: cr.body.data.page_id });
      await renderLiteral(ctx1, post.preview_url, esc2.where, tag2, esc2.number, 'D (admin without unfiltered_html, render by Divi)');
      // Kses changes the bytes; the hash is of the stored bytes, so the page still counts as untouched.
      const rb = await ajax(sn, 'rebuild', { id: String(cr.body.data.brief_id) });
      check(g.rebuildProblems('untouched', rb.body).length === 0, 'D: admin without unfiltered_html: a rebuild still sees its own page as untouched and overwrites it', g.rebuildProblems('untouched', rb.body));
      check(php('preview-drafts', { user: U.n }).length === 0, 'D: the create removed that user\'s preview draft');
    } else {
      check(false, 'D: admin without unfiltered_html: the create succeeds', { status: cr.status, body: cr.body ? cr.body.data : cr.text });
    }
  }

  section('C. A rebuild of an untouched draft (Ruling 12) and H. the Dashboard (Ruling 9)');
  {
    const title = T('C untouched');
    const brief = g.smallBrief(title, 'editorial', 'First words');
    const cr = await ajax(s1, 'create', { brief: JSON.stringify(brief) });
    const pageId = cr.body && cr.body.success ? cr.body.data.page_id : 0;
    const briefId = cr.body && cr.body.success ? cr.body.data.brief_id : 0;
    state.pages.push(pageId);
    const before = php('post', { id: pageId });
    check(before.exists && before.status === 'draft' && before.history === 0, 'C: Create made a draft with no history row yet', { status: before.status, history: before.history });
    const changed = g.smallBrief(title, 'editorial', 'Second words');
    const sv = await ajax(s1, 'save', { brief: JSON.stringify(changed), id: String(briefId) });
    check(sv.body && sv.body.success && sv.body.data.id === briefId, 'C: Save stores the changed brief under the same id');
    const rb = await ajax(s1, 'rebuild', { id: String(briefId) });
    check(g.rebuildProblems('untouched', rb.body).length === 0 && rb.body.data.page_id === pageId, 'C: Rebuild overwrites the untouched draft in place', g.rebuildProblems('untouched', rb.body));
    const rows = php('history', { id: pageId });
    check(rows.length === 1 && rows[0].tool === 'studio' && rows[0].sha256 === before.sha, 'C: exactly ONE history row was written (the real capture hook did not add a second), holding the old content', rows.map((r) => ({ tool: r.tool, sameAsBefore: r.sha256 === before.sha })));
    const cmp = php('compare', { user: 1, id: briefId, page: pageId });
    check(sameLayout(cmp).ok && cmp.hash_matches, `C: the page now holds the rebuilt layout (${sameLayout(cmp).how}) and the brief knows its new hash`, sameLayout(cmp).ok ? undefined : cmp.diff);
    check(rb.body.data.history && rb.body.data.history.stored === true, 'C: the answer says the undo point was stored', rb.body.data.history);

    // H: the Dashboard names the source of that row.
    const dash = await get(s1, DASHBOARD);
    check(g.dashboardShowsLab(dash.html, title), 'H: the Dashboard\'s recent AI edits lists the rebuilt page with the source "Lab"');
    check(g.printedPhpErrors(dash.html).length === 0, 'H: the Dashboard prints no PHP message');

    const rs = php('restore', { page: pageId, version: rows[0] ? rows[0].version_id : 0 });
    check(rs.ok && rs.post.sha === before.sha && rs.post.len === before.len, 'C: the undo (restore of that row) brings back the previous content byte for byte', { ok: rs.ok, same: rs.post.sha === before.sha });
  }

  section('F. Rebuild scenarios through the real handlers');
  {
    const scenario = async (name, sess, mutate) => {
      const title = T('F ' + name);
      const cr = await ajax(sess, 'create', { brief: JSON.stringify(g.smallBrief(title, 'luxe', 'Words ' + name)) });
      if (!(cr.body && cr.body.success)) { check(false, `F ${name}: the create succeeds`, { status: cr.status, body: cr.body ? cr.body.data : cr.text }); return null; }
      const pageId = cr.body.data.page_id;
      const briefId = cr.body.data.brief_id;
      state.pages.push(pageId);
      const after = mutate(pageId, briefId) || php('post', { id: pageId });
      const pagesBefore = php('pages-titled', { title });
      const rb = await ajax(sess, 'rebuild', { id: String(briefId) });
      const problems = g.rebuildProblems(name, rb.body);
      if (rb.body && rb.body.success && rb.body.data.page_id) state.pages.push(rb.body.data.page_id);
      check(problems.length === 0, `F ${name}: Rebuild makes a new draft with the reason "${g.REBUILD_EXPECT[name].reason}"`, problems.length ? problems : undefined);
      const now = php('post', { id: pageId });
      const untouched = name === 'missing' ? !now.exists : now.exists && now.sha === after.sha && now.status === after.status && now.history === after.history;
      check(untouched, `F ${name}: the earlier page was left exactly as it was`, { before: { status: after.status, history: after.history }, now: { exists: now.exists, status: now.status, history: now.history } });
      if (rb.body && rb.body.success) {
        const np = php('post', { id: rb.body.data.page_id });
        const pagesAfter = php('pages-titled', { title });
        check(np.exists && np.status === 'draft' && np.id !== pageId && pagesAfter.length === pagesBefore.length + 1, `F ${name}: exactly one new draft page, linked to the brief`, { status: np.status, pages: [pagesBefore.length, pagesAfter.length] });
        const link = php('brief-for-page', { user: sess.user, page: rb.body.data.page_id });
        check(link && link.id === briefId, `F ${name}: the brief now points at the new draft`);
      }
      return { pageId, briefId };
    };
    await scenario('edited', s1, (id) => php('mutate', { page: id, how: 'edit' }));
    await scenario('published', s1, (id) => php('mutate', { page: id, how: 'publish' }));
    await scenario('trashed', s1, (id) => php('mutate', { page: id, how: 'trash' }));
    await scenario('missing', s1, (id) => php('mutate', { page: id, how: 'delete' }));
    await scenario('no_access', sr, (id) => {
      const m = php('mutate', { page: id, how: 'author', author: 1, as: U.r });
      check(m.can_edit_as === false, 'F no_access: the user who owns the brief can no longer edit the page (it belongs to another author)');
      return m;
    });
    await scenario('no_undo', s1, (id, briefId) => {
      const m = php('mutate', { page: id, how: 'huge', user: 1, brief: briefId });
      check(m.len > 524288, 'F no_undo: the page content is over PageHistory::MAX_BYTES and the brief still knows it as untouched', { bytes: m.len });
      return m;
    });

    // Another user's brief id is refused exactly like a missing one.
    const own = await ajax(s1, 'create', { brief: JSON.stringify(g.smallBrief(T('F foreign'), 'editorial')) });
    state.pages.push(own.body.data.page_id);
    const theirs = String(own.body.data.brief_id);
    const brief = JSON.stringify(g.smallBrief(T('F foreign try'), 'editorial'));
    for (const [action, extra] of [['brief', {}], ['rebuild', {}], ['handoff', {}], ['save', { brief }], ['create', { brief }]]) {
      const a = await ajax(sa, action, { ...extra, id: theirs });
      const b = await ajax(sa, action, { ...extra, id: '999999999' });
      check(g.sameRefusal(a, b) && a.status === 404, `F: another user's brief id in "${action}" is refused exactly like a missing id (404, same body)`, g.sameRefusal(a, b) ? undefined : { theirs: [a.status, a.body], missing: [b.status, b.body] });
    }
    check(php('pages-titled', { title: T('F foreign try') }).length === 0, 'F: none of those refused calls created a page');

    // A stored brief whose recipe left the library.
    const gone = await ajax(s1, 'create', { brief: JSON.stringify(g.smallBrief(T('F gone'), 'editorial', 'Keep these words')) });
    state.pages.push(gone.body.data.page_id);
    state.goneBrief = gone.body.data.brief_id;
    php('gone-recipe', { user: 1, id: state.goneBrief });
    const rg = await ajax(s1, 'rebuild', { id: String(state.goneBrief) });
    check(rg.status === 422 && rg.body && rg.body.data.code === 'RECIPE_REMOVED' && /section 1\b/.test(rg.body.data.message), 'F: a stored brief whose layout left the library: Rebuild answers RECIPE_REMOVED naming section 1', { status: rg.status, data: rg.body && rg.body.data });
    const kept = php('brief', { user: 1, id: state.goneBrief });
    check(kept && kept.brief.sections[1].recipe === 'stats-counter-band' && kept.brief.sections[0].slots['heading.1'] === 'Keep these words', 'F: the stored brief (every section\'s words) is untouched by the refused rebuild');
  }

  section('G. The screen in a real administrator session');
  const consoleBag = [];
  let labPageId = 0;
  let labBriefId = 0;
  {
    const image = php('image', { token: state.token, a: 1 });
    state.image = image;
    const p = await ctx1.newPage();
    watch(p, consoleBag, 'book');
    await openScreen(p);
    const html = await p.content();
    check(g.printedPhpErrors(html).length === 0, 'G: the screen prints no PHP message');
    const statusAttrs = await p.evaluate(() => { const s = document.getElementById('aied-lab-status'); return { role: s.getAttribute('role'), live: s.getAttribute('aria-live') }; });
    check(statusAttrs.role === 'status' && statusAttrs.live === 'polite', 'G: the status region is role=status, aria-live=polite', statusAttrs);

    // Goal 1 (book), by mouse.
    const name = T('G book');
    await p.check('input[name="aied-lab-goal"][value="book"]');
    await p.fill('#aied-lab-action', 'Book a call');
    await p.fill('#aied-lab-name', name);
    await p.fill('#aied-lab-line', 'Plumbing in town, same day');
    await p.click('.aied-lab-panel[data-step="1"] [data-goto="2"]');
    check(await focusedId(p) === 'aied-lab-h2', 'G book: Next moves the focus to the step 2 heading');
    await p.check('input[name="aied-lab-language"][value="luxe"]');
    await p.click('.aied-lab-panel[data-step="2"] [data-goto="3"]');
    const sections = await p.$$eval('#aied-lab-sections > li', (l) => l.length);
    const stack = s1.cfg.stacks.book.length;
    check(sections === stack && sections > 0, 'G book: step 3 lists the suggested sections for the goal', { sections, stack });
    await p.click('.aied-lab-panel[data-step="3"] [data-goto="4"]');

    // The Media Library picker on the hero picture.
    const media = await p.evaluate(() => {
      const set = Array.from(document.querySelectorAll('.aied-lab-picture')).find((s) => s.querySelector('button.button'));
      return set ? true : false;
    });
    check(media, 'G book: a picture field offers "Choose from Media Library" (wp.media is loaded)');
    await p.evaluate(() => Array.from(document.querySelectorAll('.aied-lab-picture button.button'))[0].click());
    await p.waitForSelector('.media-modal:visible', { timeout: 20000 });
    await p.waitForSelector(`li.attachment[data-id="${image.id}"]`, { timeout: 30000 });
    await p.click(`li.attachment[data-id="${image.id}"]`);
    await p.click('.media-modal .media-button-select');
    await p.waitForSelector('.media-modal', { state: 'hidden', timeout: 20000 });
    const imgPath = new URL(image.url).pathname;
    const chosen = await p.evaluate(() => document.querySelector('.aied-lab-picture .aied-lab-chosen').textContent);
    check(chosen.includes(imgPath), 'G book: the chosen picture\'s address is shown', { chosen });

    // Words: the headline is offered from the business name.
    const headlineId = await p.evaluate(() => {
      const cat = window.aiedLabConfig.recipes;
      const first = document.querySelector('#aied-lab-words fieldset.aied-lab-section-words');
      const input = first ? first.querySelector('input[type="text"]') : null;
      return Object.keys(cat).length && input ? input.id : '';
    });
    // The hand-off to an assistant: the tool lab_build exists, so the box is offered; the button saves the brief and copies the instruction.
    const hoUi = await p.evaluate(() => ({ ready: window.aiedLabConfig.assistantReady, present: ['aied-lab-handoff', 'aied-lab-refresh', 'aied-lab-instruction-box', 'aied-lab-handoff-2'].filter((id) => document.getElementById(id)), boxHidden: document.getElementById('aied-lab-instruction-box').hidden, textareaReadonly: document.getElementById('aied-lab-instruction').readOnly }));
    const hoVisible = await p.isVisible('#aied-lab-handoff') && await p.isVisible('#aied-lab-refresh');
    check(g.handoffUiProblems(hoUi).length === 0 && hoVisible, 'G book: the assistant hand-off is offered on step 4 now that the tool lab_build exists (both buttons visible, the instruction box hidden until needed, a read-only text)', { problems: g.handoffUiProblems(hoUi), visible: hoVisible });
    await p.click('#aied-lab-handoff');
    await p.waitForFunction((t) => document.getElementById('aied-lab-status').textContent === t, i18n.copied, { timeout: 60000 });
    await waitIdle(p);
    const hoBrief = Number(new URL(await p.evaluate(() => window.location.href)).searchParams.get('brief'));
    const hoClip = await p.evaluate(() => navigator.clipboard.readText());
    const hoBox = await p.evaluate(() => ({ value: document.getElementById('aied-lab-instruction').value, hidden: document.getElementById('aied-lab-instruction-box').hidden }));
    check(hoBrief > 0 && g.instructionProblems(hoClip, hoBrief).length === 0, 'G book: "Copy instruction for my assistant" saved the brief (the address carries &brief=<id>) and copied a text that names lab_build and that brief id', { brief: hoBrief, problems: g.instructionProblems(hoClip, hoBrief), clipboard: hoClip.slice(0, 160) });
    check(hoBox.value === hoClip && hoBox.hidden, 'G book: the clipboard worked, so the fallback box stays hidden, and the text in it is the copied one', hoBox.hidden);
    const hoRow = hoBrief ? php('brief', { user: 1, id: hoBrief }) : null;
    check(!!hoRow && hoRow.brief.title === name && hoRow.page_id === null, 'G book: the saved brief is the person\'s (their business name as the title) and no page exists yet', hoRow && { title: hoRow.brief.title, page: hoRow.page_id });
    state.handoffBrief = hoBrief;

    await p.click('.aied-lab-panel[data-step="4"] [data-goto="5"]');
    check(await focusedId(p) === 'aied-lab-h5', 'G book: Next moves the focus to the step 5 heading');
    await waitPreview(p);
    const verdict = await p.textContent('#aied-lab-verdict');
    check(verdict.includes(i18n.verdictOk), 'G book: step 5 says the layout passed the check', { verdict });
    const previewIds = php('preview-drafts', { user: 1 });
    check(previewIds.length === 1, 'G book: the preview is one draft of user 1', previewIds);

    // The preview draft shows in the Pages list while it exists, and the screen says so.
    const facts = await p.textContent('.aied-lab-facts');
    const pl = await ctx1.newPage();
    watch(pl, consoleBag, 'pages list');
    await pl.goto(SITE + '/wp-admin/edit.php?post_type=page&post_status=draft&orderby=modified&order=desc', { waitUntil: 'domcontentloaded' });
    const listed = await pl.evaluate((t) => Array.from(document.querySelectorAll('#the-list .row-title')).some((a) => a.textContent.trim() === t), g.PREVIEW_PREFIX + '1');
    await pl.close();
    check(listed && /Pages list/.test(facts), 'G book: the preview draft appears in the Pages list while it exists, and the screen\'s help text says so', { listed });

    // Widths of the screen.
    for (const w of [1600, 1280, 1100, 900, 782, 400]) {
      await p.setViewportSize({ width: w, height: 1000 });
      await p.waitForTimeout(500);
      const m = await measureLayout(p);
      const problems = g.layoutProblems(m);
      check(problems.length === 0, `G: at ${w}px the screen has no horizontal overflow and the layout ${w > g.STACK_AT ? 'fills #wpbody-content side by side' : 'stacks'}`, problems.length ? { problems, m } : { layout: m.layoutWidth, body: m.bodyWidth });
    }
    // The preview devices, at two browser widths.
    for (const w of [1600, 1280]) {
      await p.setViewportSize({ width: w, height: 1000 });
      await p.waitForTimeout(400);
      await frameReady(p);
      for (const device of ['desktop', 'tablet', 'phone']) {
        await p.click(`.aied-lab-widths [data-width="${device}"]`);
        await p.waitForTimeout(700);
        const fm = await measureFrame(p);
        const problems = g.frameProblems(device, fm);
        check(problems.length === 0, `G: browser ${w}px, preview ${device}: the frame renders at ${g.DEVICE_WIDTH[device]} CSS px` + (device === 'desktop' ? ' with the desktop navigation (mega menu links) and no menu button' : device === 'phone' ? ' with the mobile menu button' : ''), problems.length ? { problems, fm } : { links: fm.linkTexts, menuModuleVisible: fm.menuModuleVisible });
      }
      await p.click('.aied-lab-widths [data-width="desktop"]');
    }
    await p.setViewportSize({ width: 1600, height: 1000 });

    // Create as draft.
    await p.click('#aied-lab-create');
    await p.waitForSelector('#aied-lab-created:not([hidden])', { timeout: 60000 });
    await waitIdle(p);
    const href = await p.getAttribute('#aied-lab-open', 'href');
    labPageId = Number(new URL(href, SITE).searchParams.get('post'));
    state.pages.push(labPageId);
    const post = php('post', { id: labPageId });
    const link = php('brief-for-page', { user: 1, page: labPageId });
    labBriefId = link ? link.id : 0;
    const cmp = labBriefId ? php('compare', { user: 1, id: labBriefId, page: labPageId }) : { equal: false };
    check(post.exists && post.status === 'draft' && post.title === name, 'G book: Create as draft made a DRAFT page titled with the business name', { status: post.status, title: post.title });
    check(sameLayout(cmp).ok && cmp.hash_matches, `G book: the page content, re-read, is the compiled markup of the stored brief (${sameLayout(cmp).how})`, sameLayout(cmp).ok ? undefined : cmp.diff);
    check(href === post.edit_url && href.startsWith(SITE + '/wp-admin/post.php?post=' + labPageId), 'G book: "Open in Divi" points at the page\'s own edit address on this site', { href, edit: post.edit_url });
    const pics = link && link.brief.sections[0].pictures ? link.brief.sections[0].pictures : {};
    const alt = link ? link.brief.sections[0].slots['image.0.alt'] : null;
    check(pics['image.0'] && pics['image.0'].url === imgPath && alt === image.alt, 'G book: the Media Library picture is stored by its site path, with its alt text', { pictures: pics, alt });
    check(php('preview-drafts', { user: 1 }).length === 0, 'G book: after Create the preview draft is gone');
    const createdMsg = await p.textContent('#aied-lab-status');
    check(createdMsg.includes(i18n.createdDraft), 'G book: the status region says the draft was created', { status: createdMsg });
    check(labBriefId === state.handoffBrief, 'G book: Create built the page from the brief the hand-off had saved (one brief, not a second)', { handoff: state.handoffBrief, page: labBriefId });
    await p.click('#aied-lab-handoff-2');
    await p.waitForFunction((t) => document.getElementById('aied-lab-status').textContent === t, i18n.copied, { timeout: 60000 });
    await waitIdle(p);
    const hoClip2 = await p.evaluate(() => navigator.clipboard.readText());
    check(g.instructionProblems(hoClip2, labBriefId).length === 0, 'G book: after Create, "Copy instruction" on step 5 copies the same kind of instruction for the brief of the page', g.instructionProblems(hoClip2, labBriefId));

    // Rebuild of the untouched page, after a changed word (Ruling 12 on the screen).
    await p.click('.aied-lab-step[data-goto="4"]');
    if (headlineId) {
      await p.fill('#' + headlineId, name + ' v2');
    }
    await p.click('.aied-lab-step[data-goto="5"]');
    const beforeRebuild = php('post', { id: labPageId });
    await p.click('#aied-lab-rebuild');
    await p.waitForFunction((t) => document.getElementById('aied-lab-status').textContent.includes(t), i18n.rebuiltOverwritten.slice(0, 30), { timeout: 60000 });
    await waitIdle(p);
    const msg = await p.textContent('#aied-lab-status');
    check(/page title was kept/.test(msg), 'G (Ruling 12): the rebuild\'s success text says the page title was kept', { msg });
    const rows = php('history', { id: labPageId });
    const after = php('post', { id: labPageId });
    check(rows.length === 1 && rows[0].tool === 'studio' && rows[0].sha256 === beforeRebuild.sha && after.sha !== beforeRebuild.sha && after.title === name, 'G (Ruling 12): the screen\'s rebuild wrote exactly one history row (the old content), changed the layout and kept the title', { rows: rows.length, title: after.title });

    // H on the real Dashboard screen.
    const dp = await ctx1.newPage();
    watch(dp, consoleBag, 'dashboard');
    await dp.goto(DASHBOARD, { waitUntil: 'domcontentloaded' });
    const dashRow = await dp.evaluate((t) => {
      const row = Array.from(document.querySelectorAll('table.widefat tr')).find((tr) => tr.querySelector('strong') && tr.querySelector('strong').textContent === t);
      return row ? row.querySelector('code').textContent : null;
    }, name);
    await dp.close();
    check(dashRow === 'Lab', 'H: the Dashboard screen shows the Lab rebuild with the source "Lab"', { source: dashRow });

    // A double click on Rebuild after a builder edit makes at most one new draft.
    php('mutate', { page: labPageId, how: 'edit' });
    const n0 = php('pages-titled', { title: name }).length;
    await p.dblclick('#aied-lab-rebuild');
    await p.waitForFunction(() => document.getElementById('aied-lab-status').textContent.length > 0 && document.getElementById('aied-lab-app').getAttribute('aria-busy') !== 'true', null, { timeout: 60000 });
    await p.waitForTimeout(2500);
    await waitIdle(p);
    const ids = php('pages-titled', { title: name });
    for (const id of ids) if (!state.pages.includes(id)) state.pages.push(id);
    check(ids.length === n0 + 1, 'G: a double click on Rebuild (after the page was edited) made exactly one new draft', { before: n0, after: ids.length });

    await p.close();

    // The removed-recipe brief on the screen: the section is marked and replacement layouts are offered. Its preview is
    // refused (422: the brief names a layout the library does not have), which the browser logs as a failed request.
    const goneBag = [];
    const pg = await ctx1.newPage();
    watch(pg, goneBag);
    await openScreen(pg, '&brief=' + state.goneBrief);
    await pg.click('.aied-lab-step[data-goto="3"]');
    const goneUi = await pg.evaluate((t) => {
      const li = document.querySelector('#aied-lab-sections > li');
      const sel = li ? li.querySelector('select') : null;
      return { note: li ? Array.from(li.querySelectorAll('.aied-lab-note')).some((n) => n.textContent === t.recipeGone) : false, first: sel ? sel.options[0].textContent : null, options: sel ? sel.options.length : 0 };
    }, i18n);
    const previewState = await pg.textContent('#aied-lab-preview-state');
    check(goneUi.note && goneUi.first === i18n.pickReplacement && goneUi.options > 10, 'G: a brief whose layout left the library: step 3 marks the section and offers replacement layouts', goneUi);
    const goneOk = g.refusedPreviewSeen(goneBag.ajax) && goneBag.every((e) => /status of 422/.test(e));
    check(goneOk && previewState === i18n.previewFailed, 'G: on that brief the only failed request is the refused preview (422), and the screen says the preview failed', { ajax: goneBag.ajax, console: goneBag.slice(0, 3), previewState });
    await pg.close();
  }

  // Goal 2 (sell) by keyboard only, with the clipboard taken away.
  {
    const ctx2 = await newContext(browser, s1);
    await ctx2.addInitScript(() => { Object.defineProperty(Navigator.prototype, 'clipboard', { get: () => undefined, configurable: true }); });
    const p = await ctx2.newPage();
    watch(p, consoleBag, 'keyboard');
    await openScreen(p);
    const name = T('G sell');
    check(await tabTo(p, () => document.activeElement && document.activeElement.matches('.aied-lab-step[data-goto="1"]'), 200), 'G keyboard: Tab reaches the stepper');
    await p.keyboard.press('Enter');
    check(await focusedId(p) === 'aied-lab-h1', 'G keyboard: Enter on step 1 puts the focus on the step 1 heading');
    // Every step of the stepper, by Shift+Tab back to its button and Enter.
    for (const n of [2, 3, 4, 5, 1]) {
      const reached = await tabTo(p, new Function(`return document.activeElement && document.activeElement.matches('.aied-lab-step[data-goto="${n}"]')`), 60, true);
      await p.keyboard.press('Enter');
      const cur = await p.evaluate(() => (document.querySelector('.aied-lab-step[aria-current="step"]') || {}).textContent || '');
      check(reached && await focusedId(p) === 'aied-lab-h' + n && cur.trim().startsWith(String(n)), `G keyboard: the stepper's step ${n} (Shift+Tab, Enter) shows that step, marks it current and focuses its heading`);
    }
    check(await tabTo(p, () => document.activeElement && document.activeElement.name === 'aied-lab-goal'), 'G keyboard: Tab reaches the goal choices');
    for (let i = 0; i < 6 && !(await p.evaluate(() => document.querySelector('input[name="aied-lab-goal"][value="sell"]').checked)); i++) await p.keyboard.press('ArrowUp');
    check(await p.evaluate(() => document.querySelector('input[name="aied-lab-goal"][value="sell"]').checked), 'G keyboard: the arrow keys choose the goal "sell"');
    await tabTo(p, () => document.activeElement && document.activeElement.id === 'aied-lab-action');
    await p.keyboard.type('Buy now');
    await tabTo(p, () => document.activeElement && document.activeElement.id === 'aied-lab-name');
    await p.keyboard.press('End');
    await p.keyboard.press('Shift+Home');
    await p.keyboard.type(name);
    for (const n of [2, 3, 4]) {
      const ok = await tabTo(p, new Function(`return document.activeElement && document.activeElement.matches('.aied-lab-panel[data-step="${n - 1}"] [data-goto="${n}"]')`));
      await p.keyboard.press('Enter');
      check(ok && await focusedId(p) === 'aied-lab-h' + n, `G keyboard: Next on step ${n - 1} (Tab, Enter) moves the focus to the step ${n} heading`);
    }
    await waitPreview(p);
    // The hand-off with the clipboard taken away: the screen falls back to the instruction box on step 4 and selects the text.
    const hoUi = await p.evaluate(() => ({ ready: window.aiedLabConfig.assistantReady, present: ['aied-lab-handoff', 'aied-lab-refresh', 'aied-lab-instruction-box', 'aied-lab-handoff-2'].filter((id) => document.getElementById(id)), boxHidden: document.getElementById('aied-lab-instruction-box').hidden, textareaReadonly: document.getElementById('aied-lab-instruction').readOnly, clipboard: typeof navigator.clipboard }));
    check(g.handoffUiProblems(hoUi).length === 0 && hoUi.clipboard === 'undefined', 'G keyboard: the hand-off is offered on step 4 and this browser has no clipboard (taken away for the check)', { problems: g.handoffUiProblems(hoUi), clipboard: hoUi.clipboard });
    check(await tabTo(p, () => document.activeElement && document.activeElement.id === 'aied-lab-handoff'), 'G keyboard: Tab reaches "Copy instruction for my assistant"');
    await p.keyboard.press('Enter');
    await p.waitForFunction(() => !document.getElementById('aied-lab-instruction-box').hidden, null, { timeout: 60000 });
    await waitIdle(p);
    const fb = await p.evaluate(() => {
      const ta = document.getElementById('aied-lab-instruction');
      return { value: ta.value, focused: document.activeElement === ta, selectedAll: ta.selectionStart === 0 && ta.selectionEnd === ta.value.length && ta.value.length > 0, status: document.getElementById('aied-lab-status').textContent, step: (document.querySelector('.aied-lab-step[aria-current="step"]') || {}).textContent || '', url: window.location.href };
    });
    const fbBrief = Number(new URL(fb.url).searchParams.get('brief'));
    check(fbBrief > 0 && g.instructionProblems(fb.value, fbBrief).length === 0, 'G keyboard (clipboard refused): the instruction box shows a text that names lab_build and the saved brief id', { brief: fbBrief, problems: g.instructionProblems(fb.value, fbBrief) });
    check(fb.focused && fb.selectedAll && fb.status === i18n.copyManual && fb.step.trim().startsWith('4'), 'G keyboard (clipboard refused): the screen stays on step 4, selects the whole instruction in the box and says to copy it by hand', { focused: fb.focused, selectedAll: fb.selectedAll, status: fb.status.slice(0, 60), step: fb.step.trim() });
    const fbRow = fbBrief ? php('brief', { user: 1, id: fbBrief }) : null;
    check(!!fbRow && fbRow.brief.title === name, 'G keyboard (clipboard refused): the brief was saved before the instruction was offered', fbRow && fbRow.brief.title);
    const ok5 = await tabTo(p, () => document.activeElement && document.activeElement.matches('.aied-lab-panel[data-step="4"] [data-goto="5"]'));
    await p.keyboard.press('Enter');
    check(ok5 && await focusedId(p) === 'aied-lab-h5', 'G keyboard: Next on step 4 moves the focus to the step 5 heading');
    await waitPreview(p);
    check(await tabTo(p, () => document.activeElement && document.activeElement.id === 'aied-lab-create'), 'G keyboard: Tab reaches "Create as draft"');
    await p.keyboard.press('Enter');
    await p.waitForSelector('#aied-lab-created:not([hidden])', { timeout: 60000 });
    await waitIdle(p);
    const href = await p.getAttribute('#aied-lab-open', 'href');
    const pageId = Number(new URL(href, SITE).searchParams.get('post'));
    state.pages.push(pageId);
    const post = php('post', { id: pageId });
    const link = php('brief-for-page', { user: 1, page: pageId });
    const cmp = link ? php('compare', { user: 1, id: link.id, page: pageId }) : { equal: false };
    check(post.status === 'draft' && post.title === name && sameLayout(cmp).ok && link.brief.goal === 'sell', `G keyboard (goal sell): Create made a draft whose content is the compiled stored brief (${sameLayout(cmp).how})`, { status: post.status, title: post.title, goal: link && link.brief.goal });
    // After Create: the frame no longer shows the deleted preview draft, the address names the brief, a reload reopens it.
    const after = await p.evaluate(() => ({ src: document.getElementById('aied-lab-frame').getAttribute('src'), state: document.getElementById('aied-lab-preview-state').textContent, url: window.location.href, create: document.getElementById('aied-lab-create').hidden }));
    check(after.src === 'about:blank' && after.state === i18n.previewRemoved, 'G (S3): after Create the frame stops showing the deleted preview draft and says why', after);
    check(new URL(after.url).searchParams.get('brief') === String(link && link.id) && new URL(after.url).searchParams.get('tab') === 'studio' && after.create, 'G (I1): after Create the address carries &brief=<id> on the same tab, and Create gave way to Rebuild', { url: after.url, createHidden: after.create });
    const dialogs = [];
    p.on('dialog', (d) => { dialogs.push(d.type()); d.accept().catch(() => {}); });
    await p.reload({ waitUntil: 'domcontentloaded' });
    await p.waitForSelector('#aied-lab-app:not([hidden])', { timeout: 30000 });
    await waitPreview(p);
    const reopened = await p.evaluate(() => ({ created: !document.getElementById('aied-lab-created').hidden, rebuild: !!document.getElementById('aied-lab-rebuild') && !document.getElementById('aied-lab-rebuild').hidden, create: document.getElementById('aied-lab-create').hidden, open: document.getElementById('aied-lab-open').hidden ? null : document.getElementById('aied-lab-open').getAttribute('href'), name: document.getElementById('aied-lab-name').value, status: document.getElementById('aied-lab-status').textContent }));
    check(dialogs.length === 0, 'G (I1): no leave warning for a reload right after Create (nothing unsaved)', dialogs);
    check(reopened.created && reopened.rebuild && reopened.create && reopened.open === post.edit_url && reopened.name === name && reopened.status === i18n.reopened, 'G (I1): a reload of that address reopens the brief with Rebuild and Open in Divi, not Create', reopened);
    // An unsaved change arms the warning.
    await p.click('.aied-lab-step[data-goto="1"]');
    await p.focus('#aied-lab-line');
    await p.keyboard.type(' changed');
    await p.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
    await p.waitForTimeout(500);
    check(dialogs.includes('beforeunload'), 'G (I1): with an unsaved change, leaving the page asks first (beforeunload)', dialogs);
    await ctx2.close();
  }
  section('I. The assistant round trip through the real MCP handler');
  {
    const tag = 'I' + crypto.randomBytes(2).toString('hex');
    const fieldId = (i, slot) => 'aied-lab-f-' + i + '-' + slot.replace(/[^A-Za-z0-9_-]/g, '-');
    const reg = php('tool-registered', {});
    check(reg.listed && reg.ready, 'I: lab_build is in the tool list and the screen counts the assistant as ready', reg);

    // catalog: what an assistant needs to choose, kept small.
    const cat = mcp('lab_build', { action: 'catalog' });
    check(cat.http === 200 && !cat.error && !cat.isError && cat.as_user === 1, 'I: catalog: the real MCP route answers as the API key\'s user (the one the screen belongs to)', { http: cat.http, as: cat.as_user, error: cat.error });
    check(g.catalogProblems(cat.result, cat.text_bytes).length === 0, `I: catalog: goals, languages, kits and sections by role, a how text, ${cat.text_bytes} bytes (bound ${g.CATALOG_MAX_BYTES})`, g.catalogProblems(cat.result, cat.text_bytes));
    const hero = mcp('lab_build', { action: 'catalog', recipe: 'hero-cta' });
    check(!hero.isError && hero.result.slots.some((x) => x.id === 'heading.1' && x.role === 'headline'), 'I: catalog with a recipe lists its slots (heading.1 is the headline)');

    // A brief saved by the person through the real admin-ajax, then opened by the assistant.
    const base = g.roundTripBrief(T('I round trip'));
    const sv = await ajax(s1, 'save', { brief: JSON.stringify(base) });
    const briefId = sv.body && sv.body.success ? sv.body.data.id : 0;
    check(briefId > 0 && /^[0-9a-f]{64}$/.test(sv.body.data.hash), 'I: the person\'s Save through admin-ajax stores the brief and answers its hash', { status: sv.status, body: sv.body && sv.body.data });
    const row0 = php('brief', { user: 1, id: briefId });
    check(row0 && row0.brief_hash === sv.body.data.hash, 'I: the hash the screen was given is the stored brief_hash');
    const open0 = mcp('lab_build', { action: 'open', brief_id: briefId });
    const op0 = g.openProblems(open0.result, { briefId, hash: row0.brief_hash, sections: 3 });
    check(!open0.isError && op0.length === 0, 'I: open: the base_hash equals the stored hash, every section lists its slots and the unfilled count is right', op0);
    check(open0.result.unfilled_count > 8 && open0.result.page === null, 'I: open: everything is still empty and no page exists yet', { unfilled: open0.result.unfilled_count, page: open0.result.page });

    // The Lab screen open on that brief BEFORE the assistant writes (its hash is the old one afterwards).
    const bagI = [];
    bagI.ajax = [];
    const pI = await ctx1.newPage();
    watch(pI, bagI, 'assistant');
    await openScreen(pI, '&brief=' + briefId);
    await pI.click('.aied-lab-step[data-goto="4"]');
    const before = await pI.$eval('#' + fieldId(0, 'heading.1'), (e) => e.value);
    check(before === '', 'I: the screen opened on the brief shows the empty field before the assistant writes', before);

    // fill with the hostile words.
    const words = g.assistantWords(tag);
    const sent = words.sections.reduce((n, x) => n + Object.keys(x.slots).length, 0);
    const fill = mcp('lab_build', { action: 'fill', brief_id: briefId, base_hash: open0.result.base_hash, sections: words.sections });
    const fp = g.fillProblems(fill.result, { sent, oldHash: row0.brief_hash });
    check(fill.http === 200 && !fill.error && !fill.isError && fp.length === 0, `I: fill: ${sent} slots written (quotes, a backslash, &, <b>, $5 and a line break), none ignored, a new base_hash`, fp.length ? fp : fill.error);
    const row1 = php('brief', { user: 1, id: briefId });
    const exact = words.where.every((w) => row1.brief.sections[w.section].slots[w.slot] === w.text);
    check(exact && row1.brief.sections[1].slots['number-counter.0.number'] === words.number, 'I: fill: the stored words equal the words sent, byte for byte', exact ? undefined : row1.brief.sections);
    check(row1.brief_hash === fill.result.base_hash && row1.brief_hash !== row0.brief_hash, 'I: fill: the answer\'s base_hash is the new stored hash');
    const open1 = mcp('lab_build', { action: 'open', brief_id: briefId });
    const sw = g.storedWordsProblems(open1.result, words);
    check(sw.length === 0 && open1.result.unfilled_count === open0.result.unfilled_count - sent, 'I: open after fill shows the words as the stored values (literal) and the unfilled count fell by the number written', sw.length ? sw : { was: open0.result.unfilled_count, now: open1.result.unfilled_count, sent });

    // The open screen: "Get my assistant's words" brings them into the fields, literally.
    await pI.click('#aied-lab-refresh');
    await pI.waitForFunction((id) => document.getElementById(id) && document.getElementById(id).value !== '', fieldId(0, 'heading.1'), { timeout: 30000 });
    await waitIdle(pI);
    const readFields = (page) => page.evaluate((ids) => ids.map((id) => { const e = document.getElementById(id); return e ? { tag: e.tagName, value: e.value } : null; }), words.where.map((w) => fieldId(w.section, w.slot)));
    const shown = await readFields(pI);
    const fpr = g.fieldProblems(words.where, shown.map((o) => o || { tag: '', value: null }));
    check(fpr.length === 0, 'I: the open screen shows the assistant\'s words in the field values after "Get my assistant\'s words" (an input drops the line break the browser cannot show; nothing else differs)', fpr);
    const msg = await pI.textContent('#aied-lab-status');
    const rx = (x) => x.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    check(new RegExp('^' + rx(i18n.mergedSome).replace('%d', '\\d+') + '|^' + rx(i18n.mergedOne)).test(msg), 'I: the screen says places were filled by the assistant', msg);

    // A stale hash: the old base_hash is refused, through the tool and through the screen's own save, and nothing changes.
    const stale = await ajax(s1, 'save', { brief: JSON.stringify(base), id: String(briefId), base_hash: row0.brief_hash });
    const sp = g.staleSaveProblems(stale, row1.brief_hash);
    check(sp.length === 0, 'I: a screen save with the OLD hash is HTTP 409 CONFLICT with the message and the current brief and hash', sp);
    const lateWords = { section: 2, slots: { 'heading.0': 'Must not land' } };
    const staleFill = mcp('lab_build', { action: 'fill', brief_id: briefId, base_hash: row0.brief_hash, sections: [lateWords] });
    const sf = g.staleFillProblems(staleFill, row1.brief_hash);
    check(sf.length === 0, 'I: fill with the OLD base_hash is refused as CONFLICT (a tool result the assistant can act on) and names the current hash', sf);
    const row2 = php('brief', { user: 1, id: briefId });
    check(row2.brief_hash === row1.brief_hash && JSON.stringify(row2.brief) === JSON.stringify(row1.brief), 'I: after both refusals the stored brief is unchanged (the assistant\'s words survive the person\'s stale save)');

    // Another user's brief id is refused exactly like a missing one, in open and in fill.
    const foreign = await ajax(sa, 'save', { brief: JSON.stringify(g.smallBrief(T('I foreign'), 'editorial', 'Theirs')) });
    const theirs = foreign.body && foreign.body.success ? foreign.body.data.id : 0;
    const frowBefore = php('brief', { user: U.a, id: theirs });
    const o1 = mcp('lab_build', { action: 'open', brief_id: theirs });
    const o2 = mcp('lab_build', { action: 'open', brief_id: 999999999 });
    const f1 = mcp('lab_build', { action: 'fill', brief_id: theirs, base_hash: frowBefore.brief_hash, sections: [{ section: 0, slots: { 'heading.1': 'Intruder' } }] });
    const f2 = mcp('lab_build', { action: 'fill', brief_id: 999999999, base_hash: frowBefore.brief_hash, sections: [{ section: 0, slots: { 'heading.1': 'Intruder' } }] });
    check(theirs > 0 && g.toolRefusalProblems(o1, o2).length === 0 && o1.result.error === 'Brief not found.', 'I: another user\'s brief id in open is a plain "Brief not found." exactly like a missing id', g.toolRefusalProblems(o1, o2));
    check(g.toolRefusalProblems(f1, f2).length === 0, 'I: another user\'s brief id in fill is refused exactly like a missing id, even with its real hash', g.toolRefusalProblems(f1, f2));
    check(JSON.stringify(php('brief', { user: U.a, id: theirs })) === JSON.stringify(frowBefore), 'I: that other user\'s brief was not touched');

    // A preview of a whole brief saves nothing.
    const bt = T('I build preview');
    const prev = mcp('lab_build', { action: 'build', brief: g.smallBrief(bt, 'editorial', 'Preview words') });
    check(prev.result && prev.result.valid === true && prev.result.created === false && prev.result.markup_omitted === true && php('pages-titled', { title: bt }).length === 0, 'I: build without create previews a brief and creates no page', prev.result && { valid: prev.result.valid, created: prev.result.created });

    // The screen, open on the old hash, saves while the assistant has written again: a REAL 409 through the screen, resolved.
    const late = mcp('lab_build', { action: 'fill', brief_id: briefId, base_hash: row1.brief_hash, sections: [{ section: 2, slots: { 'heading.0': 'Assistant late headline' } }] });
    const row3 = php('brief', { user: 1, id: briefId });
    check(!late.isError && row3.brief_hash !== row1.brief_hash, 'I: the assistant writes again while the screen is open (the screen\'s hash is now old)', late.result && late.result.message);
    let rewrote = 0;
    await pI.route(AJAX, async (route) => {
      let body = route.request().postData() || '';
      if (!rewrote && /name="action"\r\n\r\naied_studio_save\r\n/.test(body)) {
        const next = body.replace(/(name="base_hash"\r\n\r\n)[0-9a-f]{64}/, '$1' + row1.brief_hash);
        if (next !== body) { rewrote++; body = next; }
        await route.continue({ postData: body });
        return;
      }
      await route.continue();
    });
    bagI.length = 0;
    bagI.ajax = [];
    await pI.fill('#' + fieldId(2, 'text.0.body.0'), 'The person wrote this note');
    await pI.click('#aied-lab-handoff');
    await pI.waitForFunction((t) => document.getElementById('aied-lab-status').textContent === t, i18n.copied, { timeout: 60000 });
    await waitIdle(pI);
    await pI.unroute(AJAX);
    check(rewrote === 1 && bagI.ajax.join() === 'aied_studio_save 409' && bagI.every((e) => /status of 409/.test(e)), 'I: the screen\'s save carried the old hash, got HTTP 409 once, merged and sent again (the only failed request)', { rewrote, ajax: bagI.ajax, console: bagI.slice(0, 3) });
    const row4 = php('brief', { user: 1, id: briefId });
    const keptAll = words.where.every((w) => row4.brief.sections[w.section].slots[w.slot] === w.text) && row4.brief.sections[2].slots['heading.0'] === 'Assistant late headline';
    check(keptAll && row4.brief.sections[2].slots['text.0.body.0'] === 'The person wrote this note', 'I: after the conflict the stored brief holds ALL of it: the first words, the late words of the assistant and the person\'s note', row4.brief.sections.map((x) => x.slots));
    const lateField = await pI.$eval('#' + fieldId(2, 'heading.0'), (e) => e.value);
    check(lateField === 'Assistant late headline', 'I: the screen now shows the assistant\'s late words in its field', lateField);
    await pI.close();

    // Reopened with ?brief=<id>: every word is in the fields.
    const pR = await ctx1.newPage();
    const bagR = [];
    watch(pR, bagR, 'reopened');
    await openScreen(pR, '&brief=' + briefId);
    await pR.click('.aied-lab-step[data-goto="4"]');
    const reShown = await readFields(pR);
    const rfp = g.fieldProblems(words.where, reShown.map((o) => o || { tag: '', value: null }));
    check(rfp.length === 0, 'I: the screen reopened with &brief=<id> shows the assistant\'s hostile words in the field values', rfp);
    const reLate = await pR.evaluate((ids) => ids.map((id) => document.getElementById(id).value), [fieldId(2, 'heading.0'), fieldId(2, 'text.0.body.0')]);
    check(reLate[0] === 'Assistant late headline' && reLate[1] === 'The person wrote this note', 'I: and the late words and the person\'s note', reLate);
    await pR.close();

    // fill with create:true builds the draft; the words render literally in Divi (as section E checks for the screen).
    const cur = mcp('lab_build', { action: 'open', brief_id: briefId });
    const built = mcp('lab_build', { action: 'fill', brief_id: briefId, base_hash: cur.result.base_hash, create: true, sections: [{ section: 2, slots: { 'button.0.text': 'Call us today' } }] });
    const pageId = built.result && built.result.page ? built.result.page.id : 0;
    if (pageId) state.pages.push(pageId);
    check(!built.isError && pageId > 0 && built.result.build.mode === 'created' && built.result.page.status === 'draft', 'I: fill with create:true saves the words and builds one DRAFT page', built.result && { build: built.result.build, error: built.result.error, message: built.result.message });
    if (pageId) {
      const post = php('post', { id: pageId });
      const cmp = php('compare', { user: 1, id: briefId, page: pageId });
      check(post.status === 'draft' && sameLayout(cmp).ok && cmp.hash_matches, `I: the page holds the compiled layout of the stored brief (${sameLayout(cmp).how}) and the brief knows its hash`, sameLayout(cmp).ok ? undefined : cmp.diff);
      await renderLiteral(ctx1, post.preview_url, words.where, tag, words.number, 'I (assistant words, render by Divi)');
      // The same fill again: an untouched draft is rebuilt in place, with one undo point.
      const cur2 = mcp('lab_build', { action: 'open', brief_id: briefId });
      const again = mcp('lab_build', { action: 'fill', brief_id: briefId, base_hash: cur2.result.base_hash, create: true, sections: [{ section: 2, slots: { 'button.0.text': 'Call us now' } }] });
      const rows = php('history', { id: pageId });
      check(!again.isError && again.result.build.mode === 'overwritten' && again.result.build.page.id === pageId && rows.length === 1 && rows[0].tool === 'studio', 'I: a second fill with create:true rebuilds the untouched draft in place and writes exactly one history row', again.result && { build: again.result.build, rows: rows.length });
    }
  }
  check(consoleBag.length === 0 && !(consoleBag.ajax || []).length, 'G: no console error, uncaught exception or failed admin-ajax request on any screen', { console: consoleBag.slice(0, 5), ajax: consoleBag.ajax });
  await ctx1.close();
  await browser.close();
  state.browser = null;

  section('PHP messages');
  const log = php('errors-read', {}).log;
  fs.writeFileSync(path.join(OUT, 'php-errors.log'), log);
  const errs = g.phpErrors(log.split('\n').filter((l) => !l.includes('aied gate self-test')).join('\n'), PLUGIN_DIR);
  check(errs.ours.length === 0, 'no PHP warning, notice or deprecation from this plugin in any request of the run', errs.ours.slice(0, 5));
  console.log(`(other code logged ${errs.others.length} message(s); see dist/studio-gate/php-errors.log)`);
  return null;
}

(async () => {
  let code = 0;
  try {
    const r = await main();
    if (r !== null && r !== undefined) code = r;
  } catch (e) {
    console.log('FAIL  the run stopped: ' + (e && e.stack ? e.stack.split('\n').slice(0, 4).join(' | ') : e));
    fails.push('the run stopped');
    results.push({ ok: false, label: 'the run stopped' });
  } finally {
    try { if (state.browser) await state.browser.close(); } catch (e) { /* closing */ }
    if (!process.argv.includes('--clean-orphans') && state.snapshot) {
      section('Cleanup');
      cleanup();
      const left = php('leftovers', {});
      const clean = left.pages.length === 0 && left.previews.length === 0 && Object.keys(left.users).length === 0 && left.rows.length === 0 && !left.mu && left.attachments.length === 0 && left.table
        // User 1's own preview draft from before the run may be gone (the screen's Create deletes it); none of the run's may stay.
        && left.previewOf1.every((id) => state.snapshot.previewOf1.some((b) => b.id === id)) && left.allRows === state.snapshot.rows.length;
      check(!(state.refused || []).length, 'cleanup: the guard refused no id (every id the gate was handed was a page of this run)', state.refused);
      check(clean, 'cleanup: no `aied studio` page, gate user, gate brief row, picture, preview draft or error logger is left; the brief table is back with ' + state.snapshot.rows.length + ' row(s)', left);
    }
    fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify({ token: state.token, results }, null, 2));
  }
  if (code) process.exit(code);
  const sum = g.summarize(results);
  console.log('\n' + sum.text);
  process.exit(sum.failed ? 1 : 0);
})();
