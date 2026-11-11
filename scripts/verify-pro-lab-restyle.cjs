#!/usr/bin/env node
/*
 * Dev-only: the live gate of the Pro tool lab_restyle (Pro Lab plan Task 9; orchestrator). Two halves:
 *
 *   PHP (scripts/verify-pro-lab-restyle.php, run by wp eval-file in the Docker site, one stage per call): makes throwaway users and Lab
 *     pages `aied labrestyle <runToken> <role>` through the REAL free lab_build route (create:true), restyles them with lab_restyle through
 *     the REST route AND the free MCP handler, and asserts the stored result byte for byte against reference pages `ref-<role>` saved from
 *     the direct compile through the same core save path (the stage names and checks are in that file's header).
 *   Node (this file): renders the restyled page and its reference at 1280 and 400 px in a real Chromium (admin bar hidden) and compares every
 *     et_pb_ element of each with the module comparison of scripts/verify-pro-switch-design.cjs (computed styles equal, boxes within 0.5 px).
 *     It FAILS on any difference, on a module missing or extra, on a page with too few modules, and when nothing was compared.
 *
 *   node scripts/verify-pro-lab-restyle.cjs [--clean-orphans]     (needs `make up`, the free plugin and the Pro add-on active; Playwright from ../layoutlab)
 *
 * Flow: build -> restyle (preview, token, apply, references, MCP, Lab screen and rebuild, permissions) -> render and compare -> changed (a page
 * and a brief edited between preview and run) -> published -> kses -> undo (and undo of the undo) -> cleanup. Output: PASS/FAIL/SKIP lines and
 * the summary `N PASS, S SKIP, F FAIL`; screenshots in dist/lab-restyle/ (git-ignored).
 *
 * Dev site only (localhost). ONE gate at a time (the script refuses to start while another `node scripts/verify-*` runs). Every page is titled
 * `aied labrestyle <runToken> <role>`, every user `aied_lr_<runToken>_<x>`; the run deletes only its own (after the run and on
 * SIGINT/SIGTERM/SIGHUP), with the brief rows of its users and the batch rows it recorded. `--clean-orphans` deletes ALL `aied labrestyle `
 * pages, `aied_lr_` users with their brief rows and the batch rows those pages recorded; use it only when no other run is going. Pure parts:
 * scripts/test-verify-pro-lab-restyle.cjs (run first by `make verify-pro-lab-restyle`).
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync, spawnSync } = require('child_process');
const SW = require('./verify-pro-switch-design.cjs');

const TITLE_PREFIX = 'aied labrestyle ';
const LAB_ROLES = ['rest', 'mcp', 'changed', 'briefedit', 'published', 'kses', 'denied', 'editor'];
const ROLES = [...LAB_ROLES, 'ref-rest', 'ref-mcp', 'ref-published', 'ref-kses'];
const RENDER_PAIR = ['rest', 'ref-rest'];
const WIDTHS = [1280, 400];
/** Five recipes carry far more than this; fewer on a rendered page means a half-rendered page. */
const MIN_MODULES = 25;
const STAGES_AFTER_RENDER = ['changed', 'published', 'kses', 'undo'];

// ---- pure helpers (tested by scripts/test-verify-pro-lab-restyle.cjs) ----------------------------------------------------

const isRunToken = (s) => typeof s === 'string' && /^\d+-[0-9a-f]{6}$/.test(s);

/** `aied labrestyle <run> <role>`; throws on a bad run token or an unknown role. */
function pageTitle(run, role) {
  if (!isRunToken(run)) throw new Error('bad run token ' + run);
  if (!ROLES.includes(role)) throw new Error('unknown role ' + role);
  return TITLE_PREFIX + run + ' ' + role;
}

/** { run, role } of a title this gate makes, or null for any other title. */
function parseTitle(title) {
  if (typeof title !== 'string') return null;
  const m = /^aied labrestyle (\d+-[0-9a-f]{6}) ([a-z-]+)$/.exec(title);
  if (!m || !ROLES.includes(m[2])) return null;
  return { run: m[1], role: m[2] };
}

/** role => page id (string) of this run's pages; a role with two pages of the same title is null (neither is trusted). */
function ownRunPages(rows, run) {
  const seen = {};
  for (const r of rows || []) {
    const t = parseTitle(r && r.post_title);
    if (!t || t.run !== run) continue;
    seen[t.role] = seen[t.role] === undefined ? String(r.ID) : null;
  }
  return seen;
}

/** { cleanOrphans } or null for a usage error. */
function parseArgs(argv) {
  if (argv.some((a) => a !== '--clean-orphans')) return null;
  return { cleanOrphans: argv.includes('--clean-orphans') };
}

/** [[code, detail]] for one collected page: no content at all, or too few modules to be the five recipes. */
function pageProblems({ hasRoot, modules }) {
  if (hasRoot === false) return [['empty', 'no .et-l--post content on the page']];
  if (modules.length < MIN_MODULES) return [['too-few-modules', `only ${modules.length} modules on the page (at least ${MIN_MODULES} expected from five recipes): too few to prove anything`]];
  return [];
}

/**
 * The verdict for one width: page problems of each side (no content, too few modules, PHP error text, console errors), then the module
 * comparison of the restyled page with its reference. Returns { compared, problems }.
 */
function renderVerdict(restyled, reference, extra = {}) {
  const problems = [];
  for (const [side, modules] of [['restyled', restyled], ['reference', reference]]) {
    const e = extra[side] || {};
    for (const [c, d] of pageProblems({ hasRoot: e.hasRoot !== false, modules })) problems.push([c, `${side}: ${d}`]);
    if (e.php) problems.push(['php-error', `${side}: PHP error text in the HTML: ${e.php}`]);
    if (e.consoleErrors && e.consoleErrors.length) problems.push(['console-error', `${side}: ${e.consoleErrors.join(' | ')}`]);
  }
  const cmp = SW.compareModules(restyled, reference);
  for (const pr of cmp.problems) problems.push(pr);
  return { compared: cmp.checked, problems };
}

module.exports = {
  TITLE_PREFIX, LAB_ROLES, ROLES, RENDER_PAIR, WIDTHS, MIN_MODULES, isRunToken, pageTitle, parseTitle, ownRunPages, parseArgs, pageProblems, renderVerdict,
  PROPS: SW.PROPS, collectModules: SW.collectModules, compareModules: SW.compareModules, emptyRunProblems: SW.emptyRunProblems,
  parsePhpOutput: SW.parsePhpOutput, stageProblems: SW.stageProblems, parsePgrep: SW.parsePgrep,
};

// ---- the run -----------------------------------------------------------------------------------------------------------------
if (require.main === module) main().catch((e) => { console.log('ERROR script: ' + (e && e.stack ? e.stack : e)); process.exit(1); });

async function main() {
  const ROOT = path.resolve(__dirname, '..');
  const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
  const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');
  const { devSiteOnly, makeWp, loginSession, endSession, watchConsole, throwawayPages, cleanupOnSignals } = require('./lib/throwaway-pages.cjs');
  const { PHP_ERROR } = require('./lib/recipe-checks.cjs');
  devSiteOnly(SITE);

  const opts = parseArgs(process.argv.slice(2));
  if (!opts) { console.error('usage: node scripts/verify-pro-lab-restyle.cjs\n       node scripts/verify-pro-lab-restyle.cjs --clean-orphans'); process.exit(2); }

  const OUT_DIR = path.join(ROOT, 'dist', 'lab-restyle');
  const wp = makeWp(ROOT);
  const pages = throwawayPages(wp, TITLE_PREFIX, 'labrestyle');
  const { runToken: RUN, sweep, removeOrphans } = pages;
  const run = { cleaning: false, browser: null };

  /** One PHP stage in the Docker site. Prints PASS/FAIL per check and returns { failures, passes, info }. */
  function phpStage(stage, token) {
    const r = spawnSync('docker', ['compose', 'exec', '-T', 'wpcli', 'php', '-d', 'memory_limit=1G', '/usr/local/bin/wp', 'eval-file', '/scripts/verify-pro-lab-restyle.php', stage, token, '--user=1'], { cwd: ROOT, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
    const parsed = SW.parsePhpOutput(r.stdout);
    console.log(`-- stage ${stage}`);
    for (const c of parsed.checks) console.log(`${c.ok ? 'PASS' : 'FAIL'} ${c.label}` + (!c.ok && c.detail ? ` (${c.detail})` : ''));
    for (const o of parsed.other.slice(0, 12)) console.log('NOTE php: ' + o.slice(0, 300));
    if (r.error) console.log('FAIL could not run docker: ' + r.error.message);
    const passes = parsed.checks.filter((c) => c.ok).length;
    const failedChecks = parsed.checks.length - passes;
    const extra = SW.stageProblems(stage, parsed, r.error || r.status === null ? 1 : r.status).slice(failedChecks);
    for (const p of extra) console.log('FAIL ' + p);
    return { passes, failures: failedChecks + extra.length, info: parsed.info };
  }

  if (opts.cleanOrphans) {
    const r = phpStage('cleanup', 'all');
    const ok = removeOrphans();
    process.exit(ok && r.failures === 0 ? 0 : 1);
  }

  // One gate at a time: the site and the batch table are shared.
  let others = [];
  try { others = SW.parsePgrep(execFileSync('pgrep', ['-fl', 'node .*scripts/verify-'], { encoding: 'utf8' }), process.pid); } catch (e) { others = []; /* pgrep exits 1 when nothing matches */ }
  if (others.length) { console.error('Another gate is running (one at a time):\n' + others.join('\n')); process.exit(2); }

  const { chromium } = require(PW);
  const state = { cookies: null, token: null };
  const renewSession = () => { if (state.token) endSession(wp, state.token); const s = loginSession(wp); state.cookies = s.cookies; state.token = s.token; };
  const fullCleanup = () => {
    let ok = true;
    try { const r = phpStage('cleanup', RUN); if (r.failures) ok = false; } catch (e) { console.log('WARNING PHP cleanup failed: ' + (e && e.message ? e.message : e)); ok = false; }
    if (!sweep()) ok = false;
    return ok;
  };
  cleanupOnSignals(run, fullCleanup, pages.runPrefix);

  let passes = 0;
  let failures = 0;
  let skips = 0;
  const tally = (r) => { passes += r.passes; failures += r.failures; };
  const skip = (what) => { skips++; console.log('SKIP ' + what); };
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
        const got = await p.evaluate(SW.collectModules, SW.PROPS);
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
    if (foreign > 0) console.log(`WARNING ${foreign} leftover or concurrent labrestyle pages (not deleted; run with --clean-orphans when no other run is going)`);
    console.log(`lab_restyle gate, run ${RUN}`);

    const built = phpStage('build', RUN);
    tally(built);
    if (built.failures !== 0) {
      for (const s of ['restyle', 'render', ...STAGES_AFTER_RENDER]) skip(`stage ${s} (the build failed)`);
    } else {
      const restyled = phpStage('restyle', RUN);
      tally(restyled);

      // ---- render and compare the restyled page with its reference ----
      const own = ownRunPages(pages.allPages(), RUN);
      const [rRole, fRole] = RENDER_PAIR;
      const rId = own[rRole];
      const fId = own[fRole];
      if (!rId || !fId) { failures++; console.log('FAIL the restyled page and its reference are not both on the site exactly once (render not done)'); }
      else {
        browser = await chromium.launch({ handleSIGINT: false, handleSIGTERM: false, handleSIGHUP: false });
        run.browser = browser;
        for (const width of WIDTHS) {
          if (run.cleaning) break;
          let v;
          try {
            const a = await renderPage(rId, width, 'restyled');
            const b = await renderPage(fId, width, 'reference');
            v = renderVerdict(a.modules, b.modules, { restyled: a, reference: b });
            summary.push({ width, restyled: a.modules.length, reference: b.modules.length, compared: v.compared, problems: v.problems.length });
          } catch (e) {
            v = { compared: 0, problems: [['render-error', (e && e.message ? e.message : String(e)).split('\n')[0]]] };
          }
          compared += v.compared;
          if (!v.problems.length) { passes++; console.log(`PASS the restyled page renders the same as its reference at ${width}px (${v.compared} modules, ${SW.PROPS.length} styles and the box of each)`); }
          else {
            failures++;
            for (const [c, d] of v.problems.slice(0, 12)) console.log(`FAIL render/${width} ${c}: ${d}`);
            if (v.problems.length > 12) console.log(`FAIL render/${width} and ${v.problems.length - 12} more`);
          }
        }
      }

      for (const s of STAGES_AFTER_RENDER) {
        if (run.cleaning) break;
        tally(phpStage(s, RUN));
      }
    }
    for (const why of SW.emptyRunProblems({ compared })) { failures++; console.log('FAIL ' + why); }
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
  console.log(`\n${passes} PASS, ${skips} SKIP, ${failures} FAIL`);
  console.log(failures ? 'FAILED' : 'lab_restyle saves exactly what Lab builds from the restyled brief, once, undoably, and the real Divi renders it the same.');
  process.exit(failures ? 1 : 0);
}
