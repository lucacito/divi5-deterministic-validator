#!/usr/bin/env node
/*
 * Dev-only live gate of html_to_divi styles=basic (Task 11 of the html_to_divi styles plan): run by `make verify-html-to-divi` after the PHP gate.
 *
 * Every /fixtures/html/14-styled-*.html is saved as a throwaway DRAFT through the REAL HtmlToDiviService::run (scripts/verify-html-to-divi-styles.php,
 * create=true, styles=basic), then compared three ways in a real browser:
 *   the SOURCE   the fixture's own HTML rendered with JavaScript off and every request aborted, at 1280 px
 *   the DIVI page the saved draft (logged-in preview) at 1280, 900 and 400 px
 *   the WRITTEN  the style values the converter stored in the layout
 * Each block that carries a written value must agree: Divi shows what was written, and the source shows what was written (see
 * scripts/lib/html-styles-measure.cjs for the exact rules). Codes: hs-color hs-weight hs-align hs-size hs-family hs-bg hs-padding hs-max-width
 * hs-radius hs-button hs-contrast hs-overflow hs-mapping hs-measured. Every heading, paragraph and button label of every page also has to be
 * readable (hs-contrast), styled or not; nothing is skipped silently (a source element that cannot be found FAILS, a page with no measured module
 * FAILS).
 *
 *   node scripts/verify-html-to-divi-styles.cjs        (needs `make up`; Playwright from ../layoutlab, or PLAYWRIGHT_PATH)
 *
 * Dev site only (localhost). RUN ONE RUN AT A TIME: every page is titled `aied html styles <runToken> <fixture>`; the run deletes ONLY its own
 * (by id and by that exact title prefix), also on SIGINT/SIGTERM/SIGHUP. Leftovers of a killed run:
 *   docker compose exec -T wpcli php /usr/local/bin/wp eval-file /scripts/verify-html-to-divi-styles.php clean --user=1
 * (all pages titled `aied html styles `: only when no run is going).
 * Screenshots (1280, full page): dist/html-to-divi/14-styled-dark.source.png and 14-styled-dark.divi.png, for the review.
 * Pure parts: scripts/lib/html-styles-measure.cjs, tested by scripts/test-verify-html-styles.cjs.
 */
'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');

const { devSiteOnly, makeWp, loginSession, endSession, cleanupOnSignals } = require('./lib/throwaway-pages.cjs');
devSiteOnly(SITE);
const M = require('./lib/html-styles-measure.cjs');
const { chromium } = require(PW);

const TOKEN = process.pid + '-' + crypto.randomBytes(3).toString('hex');
const PREFIX = 'aied html styles ';
const SHOT_DIR = path.join(ROOT, 'dist', 'html-to-divi');
const SHOT_PAGE = '14-styled-dark';
const wp = makeWp(ROOT);
const run = { cleaning: false, browser: null };

/** Runs the PHP helper in the wpcli container and returns the JSON it prints on its `AIED_JSON:` line. */
function php(...args) {
  const out = execFileSync('docker', ['compose', 'exec', '-T', 'wpcli', 'php', '-d', 'memory_limit=1G', '/usr/local/bin/wp', 'eval-file', '/scripts/verify-html-to-divi-styles.php', ...args, '--user=1'], { cwd: ROOT, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
  const line = out.split('\n').reverse().find((l) => l.startsWith('AIED_JSON:'));
  if (!line) throw new Error('the helper printed no result: ' + out.slice(0, 400));
  return JSON.parse(line.slice('AIED_JSON:'.length));
}

const fails = [];
const log = (ok, label) => { console.log((ok ? 'PASS  ' : 'FAIL  ') + label); if (!ok) fails.push(label); };

let session = null;
let ids = [];

/** Removes this run's pages (by id and by title prefix) and its login session. Returns true when everything went well. */
function sweep() {
  let ok = true;
  try { php('delete', ids.join(',')); } catch (e) { ok = false; console.log('WARNING could not delete pages ' + ids.join(',') + ': ' + String(e.message).slice(0, 200)); }
  try { php('clean', TOKEN); } catch (e) { ok = false; console.log('WARNING could not clean "' + PREFIX + TOKEN + ' ": ' + String(e.message).slice(0, 200)); }
  if (session) { endSession(wp, session.token); session = null; }
  return ok;
}
cleanupOnSignals(run, sweep, PREFIX + TOKEN + ' ');

/** Prints one PASS line per code that held and one FAIL line per violation; returns the checks that failed. */
function report(pageName, checks) {
  const by = new Map();
  for (const c of checks) {
    if (!by.has(c.code)) by.set(c.code, []);
    by.get(c.code).push(c);
  }
  for (const code of M.CODES) {
    const list = by.get(code) || [];
    if (list.length === 0) continue;
    const bad = list.filter((c) => !c.ok);
    if (bad.length === 0) log(true, `${pageName}: ${code} (${list.length} check${list.length === 1 ? '' : 's'})`);
    for (const c of bad) log(false, `${pageName}: ${code}: ${c.where}: ${c.message}`);
  }
}

(async () => {
  const started = Date.now();
  const all = [];
  let foreign = 0;
  try {
    foreign = php('count').count;
    if (foreign > 0) console.log(`WARNING ${foreign} page(s) titled "${PREFIX}" already exist (another run, or leftovers of a killed one): they are NOT touched; run ONE gate at a time.`);
    session = loginSession(wp);

    const made = php('create', TOKEN);
    const pages = made.pages || [];
    ids = pages.map((p) => String(p.id));
    log(pages.length > 0, `the converter saved ${pages.length} styled fixture page(s) as drafts (ids ${ids.join(', ')})`);

    run.browser = await chromium.launch();
    const browser = run.browser;
    fs.mkdirSync(SHOT_DIR, { recursive: true });

    for (const page of pages) {
      if (run.cleaning) break;
      const name = page.name;
      const paths = [...new Set(page.blocks.map((b) => b.src).filter(Boolean))];

      // ---- the SOURCE: JavaScript off, nothing fetched, 1280 px
      const sctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, javaScriptEnabled: false });
      await sctx.route('**/*', (r) => r.abort());
      const sp = await sctx.newPage();
      await sp.setContent(page.source_html, { waitUntil: 'domcontentloaded' });
      const probes = paths.length ? await sp.evaluate(M.sourceProbe, paths) : [];
      const source = {};
      paths.forEach((p, i) => { source[p] = probes[i]; });
      if (name === SHOT_PAGE) await sp.screenshot({ path: path.join(SHOT_DIR, name + '.source.png'), fullPage: true });
      await sctx.close();

      // ---- the DIVI page: 1280, 900, 400
      const leaves = {};
      let overflow = null;
      for (const width of [1280, 900, 400]) {
        const ctx = await browser.newContext({ viewport: { width, height: 900 } });
        await ctx.addCookies(session.cookies.map(([cname, value]) => ({ name: cname, value, url: SITE })));
        const dp = await ctx.newPage();
        const resp = await dp.goto(`${SITE}/?page_id=${page.id}&preview=true`, { waitUntil: 'load', timeout: 90000 });
        if (!resp || resp.status() >= 400) log(false, `${name}: the preview answered ${resp ? resp.status() : 'nothing'} at ${width} px`);
        await dp.waitForTimeout(700);
        const r = await dp.evaluate(M.diviProbe);
        leaves[width] = r.leaves;
        if (width === 400) overflow = r.scrollWidth;
        if (width === 1280 && name === SHOT_PAGE) await dp.screenshot({ path: path.join(SHOT_DIR, name + '.divi.png'), fullPage: true });
        await ctx.close();
      }

      // ---- compare
      const res = M.evaluatePage(page, source, leaves, overflow);
      const checks = res.checks.slice();
      const mapProblems = M.mappingProblems(res.mapping);
      if (mapProblems.length === 0) checks.push({ code: 'hs-mapping', ok: true, message: 'ok', where: name });
      for (const msg of mapProblems) checks.push({ code: 'hs-mapping', ok: false, message: msg, where: name });
      report(name, checks);
      all.push({ page: name, measured: res.measured });
      console.log(`INFO  ${name}: ${res.measured} block(s) measured, ${leaves[1280].length} Divi text module(s), ${res.skippedContrast} left unjudged for contrast (a background image, no paint written)`);
    }
    if (pages.some((p) => p.name === SHOT_PAGE)) console.log(`INFO  screenshots for the review: dist/html-to-divi/${SHOT_PAGE}.source.png and dist/html-to-divi/${SHOT_PAGE}.divi.png (1280 px, full page)`);

    const empty = M.emptyRunProblems(all);
    if (empty.length === 0) log(true, `hs-measured: every page measured at least one module (${all.map((a) => a.measured).join(', ')})`);
    for (const msg of empty) log(false, 'hs-measured: ' + msg);
  } catch (e) {
    log(false, 'script error: ' + (e && e.stack ? e.stack.split('\n').slice(0, 4).join(' | ') : e));
  } finally {
    if (run.browser) { try { await run.browser.close(); } catch (e) { /* leaving anyway */ } }
    const clean = sweep();
    let left = -1;
    try { left = php('count').count; } catch (e) { /* reported below */ }
    log(clean && left === foreign, `every throwaway page was deleted (${left < 0 ? 'unknown' : left - foreign} of this run left)`);
  }
  const seconds = Math.round((Date.now() - started) / 1000);
  console.log(fails.length ? `\n${fails.length} check(s) FAILED (${seconds} s)` : `\nAll styled fixtures agree with their source and with the Divi page (${seconds} s).`);
  process.exit(fails.length ? 1 : 0);
})();
