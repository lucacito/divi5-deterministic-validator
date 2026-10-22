#!/usr/bin/env node
/*
 * Dogfood render: takes ONE page an assistant wrote (a Divi 5 layout in a .html file), validates it through the real LayoutGate on the dev
 * site, saves it as ONE throwaway draft EXACTLY AS GIVEN (no wrapping, no token resolving: that is what an assistant would save), renders
 * it in a real browser at 1280 and 400 px, and deletes the draft again.
 *
 *   node scripts/dogfood-render.cjs <page.html> [--name <label>] [--out <dir>]    (needs `make up`; Playwright from ../layoutlab)
 *   node scripts/dogfood-render.cjs --clean-orphans
 *
 * An invalid layout prints its violation codes and is NOT saved (exit 1). A page that still contains `{{aied:` is reported
 * (`WARNING tokens left`), not refused. For each width it takes a full-page screenshot, `scrollWidth - innerWidth`, the page's own console
 * errors and the body text against PHP error patterns. Output: `dist/dogfood/<name>/shot-<width>.png` and `render.json`
 * (`{page, title, valid, widths, cleanupOk}`), <name> = the page's parent directory name plus `-<stem>` when the file is not `page.html`
 * (`--name` replaces it, `--out` replaces the whole directory). There is no --kit option (controller ruling 7).
 *
 * Exit 0 only when the layout is valid, both widths were measured with overflow 0, no console error, no PHP error text and the cleanup
 * finished; 1 otherwise; 2 on a usage error.
 *
 * Dev site only (localhost). RUN ONE RUN AT A TIME. Every throwaway page is titled `aied dogfood <runToken> <name>`; the run deletes ONLY
 * pages whose title starts with `aied dogfood <runToken> ` (and the ids it recorded), also on SIGINT/SIGTERM/SIGHUP. `--clean-orphans`
 * deletes ALL pages whose title starts with `aied dogfood ` and exits: use it only when no other run is going.
 * Pure parts: scripts/lib/dogfood-render.cjs, tested by scripts/test-dogfood-render.cjs.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');

const { devSiteOnly, makeWp, loginSession, endSession, watchConsole, throwawayPages, cleanupOnSignals } = require('./lib/throwaway-pages.cjs');
devSiteOnly(SITE);

const D = require('./lib/dogfood-render.cjs');
const { measureInPage, PHP_ERROR } = require('./lib/recipe-checks.cjs');

const opts = D.parseArgs(process.argv.slice(2));
if (opts.error) { console.error(opts.error); process.exit(2); }

const wp = makeWp(ROOT);
const pages = throwawayPages(wp, D.TITLE_PREFIX, 'dogfood');
const { runToken: RUN_TOKEN, created, sweep, dropPage, removeOrphans } = pages;
const run = { cleaning: false, browser: null };

if (opts.cleanOrphans) process.exit(removeOrphans() ? 0 : 1);

let content;
try { content = fs.readFileSync(opts.page, 'utf8'); } catch (e) { console.error('cannot read ' + opts.page + ': ' + (e && e.message ? e.message : e)); process.exit(2); }
if (content.trim() === '') { console.error(opts.page + ' is empty'); process.exit(2); }

const NAME = opts.name || D.outName(opts.page);
const TITLE = D.runTitle(RUN_TOKEN, NAME);
const OUT_DIR = opts.out ? path.resolve(opts.out) : path.join(ROOT, 'dist', 'dogfood', NAME);
const BS = String.fromCharCode(92);

/** Gates the content with the real LayoutGate and saves a draft only when it passes. Returns { id|null, valid, verdict }. */
function savePage() {
  if (run.cleaning) throw new Error('interrupted');
  // Everything travels as one base64 JSON payload, so nothing can break out of the PHP string.
  const b64 = Buffer.from(JSON.stringify({ c: content, t: TITLE })).toString('base64');
  const NS = 'AiEditorDivi5' + BS + 'WP' + BS;
  const out = wp('eval', `$p=json_decode(base64_decode('${b64}'),true);$c=$p['c'];$g=${NS}LayoutGate::check($c);if(!$g->isValid()){echo '0|INVALID '.implode(',',array_map(fn($v)=>$v->toArray()['code'],$g->violations()));return;}`
    + `$id=wp_insert_post(wp_slash(['post_type'=>'page','post_title'=>$p['t'],'post_content'=>$c,'post_status'=>'draft','meta_input'=>['_et_pb_use_divi_5'=>'on','_et_pb_use_builder'=>'on']]));echo $id.'|valid';`);
  const [id, verdict] = out.split('|');
  const ok = /^\d+$/.test(id) && id !== '0';
  if (ok) created.push(id);
  return { id: ok ? id : null, valid: ok && verdict === 'valid', verdict: ok ? 'valid' : (verdict || out).slice(0, 1500) };
}

/** Deletes the shot-<width>.png files of an earlier run in a reused out dir, so a width that fails now cannot show an old picture. */
function clearOldShots(dir) {
  if (!fs.existsSync(dir)) return;
  try {
    for (const f of fs.readdirSync(dir)) if (/^shot-\d+\.png$/.test(f)) fs.unlinkSync(path.join(dir, f));
  } catch (e) { console.log('WARNING could not clear old screenshots: ' + D.shorten(e)); }
}

(async () => {
  const results = { page: opts.page, title: TITLE, valid: false, verdict: '', widths: {}, cleanupOk: true };
  const state = { token: null };
  let browser = null;
  cleanupOnSignals(run, sweep, pages.runPrefix);
  try {
    if (content.includes('{{aied:')) console.log('WARNING tokens left: the page still contains {{aied: tokens (an assistant must resolve them; saved as given)');
    const foreign = pages.foreignCount();
    if (foreign > 0) console.log(`WARNING ${foreign} leftover or concurrent dogfood pages (not deleted; run with --clean-orphans when no other run is going)`);
    clearOldShots(OUT_DIR);
    const saved = savePage();
    results.valid = saved.valid;
    results.verdict = saved.verdict;
    console.log(saved.valid ? `page ${saved.id} valid (run ${RUN_TOKEN})` : `NOT saved: ${saved.verdict}`);
    if (saved.valid) {
      fs.mkdirSync(OUT_DIR, { recursive: true });
      browser = await require(PW).chromium.launch({ handleSIGINT: false, handleSIGTERM: false, handleSIGHUP: false });
      run.browser = browser;
      for (const w of D.WIDTHS) {
        if (run.cleaning) break;
        if (state.token) endSession(wp, state.token);
        const s = loginSession(wp);
        state.token = s.token;
        const ctx = await browser.newContext({ viewport: { width: w, height: 900 } });
        try {
          await ctx.addCookies(s.cookies.map(([name, value]) => ({ name, value, url: SITE })));
          const p = await ctx.newPage();
          const errors = watchConsole(p, SITE);
          const resp = await p.goto(`${SITE}/?page_id=${saved.id}&preview=true`, { waitUntil: 'load' });
          const status = resp ? resp.status() : 0;
          if (status !== 200) errors.push(`http: status ${status}`);
          await p.evaluate(async () => { for (let y = 0; y < document.documentElement.scrollHeight; y += 500) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 120)); } window.scrollTo(0, 0); });
          await p.waitForTimeout(800);
          await p.screenshot({ path: path.join(OUT_DIR, `shot-${w}.png`), fullPage: true });
          const overflow = await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          const html = await p.content();
          const php = PHP_ERROR.map((re) => re.exec(html)).find(Boolean);
          const m = await p.evaluate(measureInPage);
          if (!m.hasRoot) errors.push('empty: no .et-l--post content on the page');
          else if (!m.visible) errors.push('empty: the page content shows nothing visible');
          results.widths[w] = { overflow, errors, phpError: php ? php[0].replace(/\s+/g, ' ').slice(0, 160) : null };
        } finally {
          await ctx.close();
        }
      }
    }
  } catch (e) {
    results.error = D.shorten(e);
    console.log('ERROR script: ' + results.error);
  } finally {
    if (!run.cleaning) {
      for (const id of [...created]) dropPage(id);
      if (state.token) endSession(wp, state.token);
      results.cleanupOk = sweep();
      if (browser) { try { await browser.close(); } catch (e) { console.log('WARNING could not close the browser: ' + D.shorten(e)); } }
    }
  }
  if (run.cleaning) return;
  const summary = D.summarize(results);
  try {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    fs.writeFileSync(path.join(OUT_DIR, 'render.json'), JSON.stringify(D.renderJson(results), null, 2) + '\n');
  } catch (e) {
    console.log('WARNING could not write render.json: ' + (e && e.message ? e.message : e));
  }
  for (const line of summary.lines) console.log(line);
  console.log('output: ' + path.relative(ROOT, OUT_DIR));
  process.exit(summary.ok ? 0 : 1);
})();
