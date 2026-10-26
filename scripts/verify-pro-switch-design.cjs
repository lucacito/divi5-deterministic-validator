#!/usr/bin/env node
/*
 * Dev-only: the live gate of the Pro tool switch_design (design switch plan Task 9; orchestrator). Two halves:
 *
 *   PHP (scripts/verify-pro-switch-design.php, run by wp eval-file in the Docker site, one stage per call): builds throwaway draft pages
 *     `aied switch <runToken> source|direct|mcp|changed|denied` from six clean recipes through the free pipeline (SectionRecipes::recipe
 *     under editorial + quiet-solid for the source pages, under luxe + gilt-hairline for the direct page), calls the tool through the REST
 *     route AND the free MCP handler, and asserts the saved result byte for byte (the stage names and checks are in that file's header).
 *   Node (this file): renders the switched page and the direct page at 1280 and 400 px in a real Chromium (admin bar hidden), collects every
 *     et_pb_ element of each, and compares the two lists in document order: the same modules, and for each the computed color,
 *     background-color, background-image, font-family, font-weight, letter-spacing, text-transform, borders, radii, padding and box-shadow
 *     (equal) and the bounding box (within 0.5 px). It FAILS on any difference, on a module missing or extra, on a page with too few
 *     modules, and when nothing was compared.
 *
 *   node scripts/verify-pro-switch-design.cjs [--clean-orphans]     (needs `make up`, the free plugin and the Pro add-on active; Playwright from ../layoutlab)
 *
 * Flow: build -> switch (preview, token, apply, one-time token, MCP, permissions) -> render and compare -> changed (a page edited between preview
 * and run) -> undo (byte for byte) -> cleanup. Output: PASS/FAIL lines and a summary, screenshots in dist/switch-design/ (git-ignored).
 *
 * Dev site only (localhost). ONE gate at a time (the script refuses to start while another `node scripts/verify-*` runs). Every page is titled
 * `aied switch <runToken> <role>`; the run deletes only pages with its own token (after the run and on SIGINT/SIGTERM/SIGHUP).
 * `--clean-orphans` deletes ALL pages whose title starts with `aied switch `, the throwaway users and the batch rows they recorded; use it only
 * when no other run is going. Pure parts: scripts/test-verify-pro-switch-design.cjs (run first by `make verify-pro-switch-design`).
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync, spawnSync } = require('child_process');

const TITLE_PREFIX = 'aied switch ';
const ROLES = ['source', 'direct', 'mcp', 'changed', 'denied'];
const WIDTHS = [1280, 400];
const TOL = 0.5;
/** Six recipes carry far more than this; fewer on a rendered page means a half-rendered page. */
const MIN_MODULES = 30;
const SIDES = ['top', 'right', 'bottom', 'left'];
const PROPS = [
  'color', 'background-color', 'background-image', 'font-family', 'font-weight', 'letter-spacing', 'text-transform',
  ...SIDES.flatMap((s) => [`border-${s}-width`, `border-${s}-style`, `border-${s}-color`]),
  'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius',
  ...SIDES.map((s) => `padding-${s}`),
  'box-shadow',
];
const BOX = [['x', 'box-x'], ['y', 'box-y'], ['w', 'box-width'], ['h', 'box-height']];

// ---- pure helpers (tested by scripts/test-verify-pro-switch-design.cjs) ---------------------------------------------------

/**
 * Runs IN THE BROWSER (Playwright serialises it: keep it self-contained). Every element of the post content whose class has an et_pb_ token,
 * in document order: a key (tag + its et_pb_ classes, numbered ones normalised, state classes scripts add left out), the computed styles named
 * in `props` and the bounding box in document coordinates.
 */
function collectModules(props) {
  const root = document.querySelector('.et-l--post');
  if (!root) return { hasRoot: false, modules: [] };
  const state = /(animat|waypoint|visible|sticky|loaded|inited|mounted)/;
  const sx = window.scrollX;
  const sy = window.scrollY;
  const modules = Array.from(root.querySelectorAll('[class*="et_pb_"]')).map((el) => {
    const cs = getComputedStyle(el);
    const classes = Array.from(el.classList)
      .filter((c) => /^et_pb_[a-z0-9_]+$/.test(c) && !state.test(c))
      .map((c) => c.replace(/_\d+$/, '_N'))
      .sort();
    const styles = {};
    for (const p of props) styles[p] = cs.getPropertyValue(p);
    const r = el.getBoundingClientRect();
    // the contact form's captcha line prints two RANDOM numbers on every load, so the boxes in that line differ from render to render: its
    // styles are still compared, its boxes are not (`volatile`). This deliberately also covers the contact form's submit button box: its
    // position and width depend on the width of the random captcha numbers (measured live: x and width shifted by about 7 px between two
    // loads)
    const volatile = !!el.closest('.et_contact_bottom_container');
    return { key: el.tagName.toLowerCase() + '.' + classes.join('.'), styles, volatile, box: { x: r.left + sx, y: r.top + sy, w: r.width, h: r.height } };
  });
  return { hasRoot: true, modules };
}

/** Index pairs [i, j] of a longest common subsequence of two key lists, plus the indexes left over on each side. */
function align(ka, kb) {
  const n = ka.length;
  const m = kb.length;
  if (n * m > 25e6) { // absurdly large: fall back to position by position
    const pairs = [];
    const common = Math.min(n, m);
    for (let i = 0; i < common; i++) pairs.push([i, i]);
    return { pairs, onlyA: Array.from({ length: Math.max(0, n - common) }, (_, k) => common + k), onlyB: Array.from({ length: Math.max(0, m - common) }, (_, k) => common + k) };
  }
  const w = m + 1;
  const dp = new Uint32Array((n + 1) * w);
  for (let i = n - 1; i >= 0; i--) {
    for (let j = m - 1; j >= 0; j--) {
      dp[i * w + j] = ka[i] === kb[j] ? dp[(i + 1) * w + j + 1] + 1 : Math.max(dp[(i + 1) * w + j], dp[i * w + j + 1]);
    }
  }
  const pairs = [];
  const onlyA = [];
  const onlyB = [];
  let i = 0;
  let j = 0;
  while (i < n && j < m) {
    if (ka[i] === kb[j]) { pairs.push([i, j]); i++; j++; }
    else if (dp[(i + 1) * w + j] >= dp[i * w + j + 1]) onlyA.push(i++);
    else onlyB.push(j++);
  }
  while (i < n) onlyA.push(i++);
  while (j < m) onlyB.push(j++);
  return { pairs, onlyA, onlyB };
}

/**
 * Compares the module list of the switched page with the one of the direct page. Returns { checked, problems }: `checked` = the modules found
 * on both sides and compared; `problems` = [[code, detail]], codes `module-missing` (in the direct page only), `module-extra` (in the switched
 * page only), `style-<property>` (computed values differ) and `box-x|y|width|height` (more than 0.5 px apart, or not a number).
 */
function compareModules(switched, direct, opts = {}) {
  const tol = opts.tol === undefined ? TOL : opts.tol;
  const { pairs, onlyA, onlyB } = align(switched.map((m) => m.key), direct.map((m) => m.key));
  const problems = [];
  for (const j of onlyB) problems.push(['module-missing', `#${j} ${direct[j].key} is on the direct page and not on the switched one`]);
  for (const i of onlyA) problems.push(['module-extra', `#${i} ${switched[i].key} is on the switched page and not on the direct one`]);
  for (const [i, j] of pairs) {
    const a = switched[i];
    const b = direct[j];
    const where = `#${j} ${b.key}`;
    for (const p of PROPS) {
      if (a.styles[p] !== b.styles[p]) problems.push(['style-' + p, `${where}: switched ${JSON.stringify(a.styles[p])} vs direct ${JSON.stringify(b.styles[p])}`]);
    }
    if (a.volatile === true || b.volatile === true) continue; // random captcha numbers: styles compared above, boxes not
    for (const [d, code] of BOX) {
      const x = a.box[d];
      const y = b.box[d];
      if (!Number.isFinite(x) || !Number.isFinite(y) || Math.abs(x - y) > tol) problems.push([code, `${where}: switched ${x} vs direct ${y} (more than ${tol} px apart)`]);
    }
  }
  return { checked: pairs.length, problems };
}

/** A run that compared no module did not prove anything. */
function emptyRunProblems({ compared }) {
  return compared > 0 ? [] : ['nothing was compared (0 modules): the gate checked no page, so it proves nothing'];
}

/** [[code, detail]] for one collected page: no content at all, or too few modules to be the six recipes. */
function pageProblems({ hasRoot, modules }) {
  if (hasRoot === false) return [['empty', 'no .et-l--post content on the page']];
  if (modules.length < MIN_MODULES) return [['too-few-modules', `only ${modules.length} modules on the page (at least ${MIN_MODULES} expected from six recipes): too few to prove anything`]];
  return [];
}

/** The PHP stage's stdout: JSON lines { type: 'check', ok, label, detail? } and { type: 'info', key, value }; any other line is noise, kept. */
function parsePhpOutput(stdout) {
  const checks = [];
  const info = {};
  const other = [];
  for (const line of String(stdout || '').split('\n')) {
    const s = line.trim();
    if (!s) continue;
    let o = null;
    if (s.startsWith('{')) { try { o = JSON.parse(s); } catch (e) { o = null; } }
    if (o && o.type === 'check' && typeof o.label === 'string') checks.push({ ok: o.ok === true, label: o.label, detail: o.detail === undefined ? null : o.detail });
    else if (o && o.type === 'info' && typeof o.key === 'string') info[o.key] = o.value;
    else other.push(s);
  }
  return { checks, info, other };
}

/** Why a stage counts as failed: a failed check, no check at all, or a non-zero exit. */
function stageProblems(stage, parsed, exitCode) {
  const out = parsed.checks.filter((c) => !c.ok).map((c) => c.label + (c.detail ? ` (${c.detail})` : ''));
  if (parsed.checks.length === 0) out.push(`stage ${stage} reported no check`);
  if (exitCode !== 0) out.push(`stage ${stage} exited ${exitCode}` + (parsed.other.length ? ': ' + parsed.other.slice(-3).join(' | ').slice(0, 300) : ''));
  return out;
}

/** `pgrep -fl` lines (pid and command) of other gate processes: ours is not another gate. */
function parsePgrep(out, selfPid) {
  return String(out || '').split('\n').map((l) => l.trim()).filter(Boolean).filter((l) => Number(l.split(/\s+/)[0]) !== selfPid);
}

module.exports = { TITLE_PREFIX, ROLES, WIDTHS, TOL, MIN_MODULES, PROPS, collectModules, align, compareModules, emptyRunProblems, pageProblems, parsePhpOutput, stageProblems, parsePgrep };

// ---- the run -----------------------------------------------------------------------------------------------------------------
if (require.main === module) main().catch((e) => { console.log('ERROR script: ' + (e && e.stack ? e.stack : e)); process.exit(1); });

async function main() {
  const ROOT = path.resolve(__dirname, '..');
  const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
  const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');
  const { devSiteOnly, makeWp, loginSession, endSession, watchConsole, throwawayPages, cleanupOnSignals } = require('./lib/throwaway-pages.cjs');
  const { PHP_ERROR } = require('./lib/recipe-checks.cjs');
  devSiteOnly(SITE);

  const USAGE = 'usage: node scripts/verify-pro-switch-design.cjs\n       node scripts/verify-pro-switch-design.cjs --clean-orphans';
  const args = process.argv.slice(2);
  const cleanOrphans = args.includes('--clean-orphans');
  if (args.some((a) => a !== '--clean-orphans')) { console.error(USAGE); process.exit(2); }

  const OUT_DIR = path.join(ROOT, 'dist', 'switch-design');
  const wp = makeWp(ROOT);
  const pages = throwawayPages(wp, TITLE_PREFIX, 'switch');
  const { runToken: RUN, sweep, removeOrphans } = pages;
  const run = { cleaning: false, browser: null };

  /** One PHP stage in the Docker site. Prints PASS/FAIL per check and returns { failures, passes, info }. */
  function phpStage(stage, token) {
    const r = spawnSync('docker', ['compose', 'exec', '-T', 'wpcli', 'php', '-d', 'memory_limit=1G', '/usr/local/bin/wp', 'eval-file', '/scripts/verify-pro-switch-design.php', stage, token, '--user=1'], { cwd: ROOT, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
    const parsed = parsePhpOutput(r.stdout);
    console.log(`-- stage ${stage}`);
    for (const c of parsed.checks) console.log(`${c.ok ? 'PASS' : 'FAIL'} ${c.label}` + (!c.ok && c.detail ? ` (${c.detail})` : ''));
    for (const o of parsed.other.slice(0, 12)) console.log('NOTE php: ' + o.slice(0, 300));
    if (r.error) console.log('FAIL could not run docker: ' + r.error.message);
    const passes = parsed.checks.filter((c) => c.ok).length;
    const failedChecks = parsed.checks.length - passes;
    // stageProblems lists the failed checks first (already printed above), then the stage-level problems: no check at all, a non-zero exit.
    const extra = stageProblems(stage, parsed, r.error || r.status === null ? 1 : r.status).slice(failedChecks);
    for (const p of extra) console.log('FAIL ' + p);
    return { passes, failures: failedChecks + extra.length, info: parsed.info };
  }

  if (cleanOrphans) {
    const r = phpStage('cleanup', 'all');
    const ok = removeOrphans();
    process.exit(ok && r.failures === 0 ? 0 : 1);
  }

  // One gate at a time: the site and the batch table are shared.
  let others = [];
  try { others = parsePgrep(execFileSync('pgrep', ['-fl', 'node .*scripts/verify-'], { encoding: 'utf8' }), process.pid); } catch (e) { others = []; /* pgrep exits 1 when nothing matches */ }
  if (others.length) { console.error('Another gate is running (one at a time):\n' + others.join('\n')); process.exit(2); }

  const { chromium } = require(PW);
  const state = { cookies: null, token: null };
  const renewSession = () => { if (state.token) endSession(wp, state.token); const s = loginSession(wp); state.cookies = s.cookies; state.token = s.token; };
  const pageId = (role) => {
    const rows = pages.allPages().filter((r) => r.post_title === TITLE_PREFIX + RUN + ' ' + role);
    return rows.length === 1 ? String(rows[0].ID) : null;
  };
  const fullCleanup = () => {
    let ok = true;
    try { const r = phpStage('cleanup', RUN); if (r.failures) ok = false; } catch (e) { console.log('WARNING PHP cleanup failed: ' + (e && e.message ? e.message : e)); ok = false; }
    if (!sweep()) ok = false;
    return ok;
  };
  cleanupOnSignals(run, fullCleanup, pages.runPrefix);

  let passes = 0;
  let failures = 0;
  const tally = (r) => { passes += r.passes; failures += r.failures; };
  const summary = [];
  let browser = null;

  /** Opens the preview logged in (one retry with a fresh login), measures it and takes the screenshot. */
  async function renderPage(id, width, label) {
    for (let attempt = 0; attempt < 2; attempt++) {
      if (attempt > 0 || !state.cookies) renewSession();
      const ctx = await browser.newContext({ viewport: { width, height: 900 } });
      try {
        await ctx.addCookies(state.cookies.map(([n, value]) => ({ name: n, value, url: SITE })));
        const p = await ctx.newPage();
        const consoleErrors = watchConsole(p, SITE);
        const resp = await p.goto(`${SITE}/?page_id=${id}&preview=true`, { waitUntil: 'load' });
        if (!(resp && resp.status() < 400 && (await p.$('.et-l--post'))) && attempt === 0) { console.log(`NOTE preview of page ${id} came back ${resp ? resp.status() : 'without a response'}: logging in again`); continue; }
        await p.addStyleTag({ content: '#wpadminbar{display:none!important}html{margin-top:0!important}*,*::before,*::after{animation:none!important;transition:none!important}' });
        await p.waitForTimeout(600);
        await p.evaluate(async () => { for (let y = 0; y < document.documentElement.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 80)); } });
        await p.waitForTimeout(2500); // number counters finish counting
        await p.evaluate(() => window.scrollTo(0, 0));
        await p.waitForTimeout(400);
        const html = await p.content();
        const php = PHP_ERROR.map((re) => re.exec(html)).find(Boolean);
        const got = await p.evaluate(collectModules, PROPS);
        try { fs.mkdirSync(OUT_DIR, { recursive: true }); await p.screenshot({ path: path.join(OUT_DIR, `${width}-${label}.png`), fullPage: true }); } catch (e) { console.log(`WARNING screenshot failed for ${label}/${width}`); }
        return { ...got, php: php ? php[0].replace(/\s+/g, ' ').slice(0, 160) : null, consoleErrors: [...new Set(consoleErrors)].slice(0, 3) };
      } finally {
        await ctx.close();
      }
    }
    return { hasRoot: false, modules: [], php: null, consoleErrors: [] };
  }

  let compared = 0;
  try {
    const foreign = pages.foreignCount();
    if (foreign > 0) console.log(`WARNING ${foreign} leftover or concurrent switch pages (not deleted; run with --clean-orphans when no other run is going)`);
    console.log(`design switch gate, run ${RUN}`);

    const built = phpStage('build', RUN);
    tally(built);
    if (built.failures === 0) {
      tally(phpStage('switch', RUN));

      // ---- render and compare the switched page with the direct page ----
      const sId = pageId('source');
      const dId = pageId('direct');
      if (!sId || !dId) { failures++; console.log('FAIL the switched and the direct page are not both on the site (render skipped)'); }
      else {
        browser = await chromium.launch({ handleSIGINT: false, handleSIGTERM: false, handleSIGHUP: false });
        run.browser = browser;
        for (const width of WIDTHS) {
          if (run.cleaning) break;
          const problems = [];
          let n = 0;
          try {
            const sw = await renderPage(sId, width, 'switched');
            const di = await renderPage(dId, width, 'direct');
            for (const [side, got] of [['switched', sw], ['direct', di]]) {
              for (const [c, d] of pageProblems(got)) problems.push([c, `${side}: ${d}`]);
              if (got.php) problems.push(['php-error', `${side}: PHP error text in the HTML: ${got.php}`]);
              if (got.consoleErrors.length) problems.push(['console-error', `${side}: ${got.consoleErrors.join(' | ')}`]);
            }
            const cmp = compareModules(sw.modules, di.modules);
            n = cmp.checked;
            compared += cmp.checked;
            for (const pr of cmp.problems) problems.push(pr);
            summary.push({ width, switched: sw.modules.length, direct: di.modules.length, compared: cmp.checked, problems: problems.length });
          } catch (e) {
            problems.push(['render-error', (e && e.message ? e.message : String(e)).split('\n')[0]]);
          }
          if (!problems.length) { passes++; console.log(`PASS switched page equals the direct page at ${width}px (${n} modules, ${PROPS.length} styles and the box of each)`); }
          else {
            failures++;
            for (const [c, d] of problems.slice(0, 12)) console.log(`FAIL render/${width} ${c}: ${d}`);
            if (problems.length > 12) console.log(`FAIL render/${width} and ${problems.length - 12} more`);
          }
        }
      }

      tally(phpStage('changed', RUN));
      tally(phpStage('undo', RUN));
    }
    for (const why of emptyRunProblems({ compared })) { failures++; console.log('FAIL ' + why); }
    try { fs.mkdirSync(OUT_DIR, { recursive: true }); fs.writeFileSync(path.join(OUT_DIR, 'observations.json'), JSON.stringify(summary, null, 1)); } catch (e) { console.log('WARNING could not write observations.json'); }
  } catch (e) {
    console.log('ERROR script: ' + (e && e.stack ? e.stack : e));
    failures++;
  } finally {
    if (!run.cleaning) {
      if (state.token) endSession(wp, state.token);
      if (browser) await browser.close();
      if (!fullCleanup()) failures++;
    }
  }
  if (run.cleaning) return;
  console.log(`\n${passes} PASS, ${failures} FAIL`);
  console.log(failures ? 'FAILED' : 'switch_design saves exactly the direct page, once, undoably, and the real Divi renders both the same.');
  process.exit(failures ? 1 : 0);
}
