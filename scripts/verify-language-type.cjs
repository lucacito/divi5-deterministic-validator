#!/usr/bin/env node
/*
 * Dev-only: the language-type render gate (type adapter plan, Task 6). For each design language it builds ONE "type sheet" through the REAL pipeline
 * (the sheet template of scripts/lib/language-type-checks.cjs: a display h1 and h2, a title h3 inside a padded group, an eyebrow h4 and an
 * eyebrow text, each carrying a type marker `"aied":{"type":"<role>"}` and the language's font tokens; resolved in PHP exactly as
 * SectionRecipes::recipe serves a recipe: SectionRecipes::resolveMarkup() with the language and ITS OWN kit), gates it through
 * LayoutGate::check, saves it as a throwaway DRAFT and opens it with the real Divi in Chromium at 1280, 900 and 400 px.
 *
 *   node scripts/verify-language-type.cjs [--languages a,b] [--clean-orphans]    (needs `make up`; Playwright from ../layoutlab)
 *
 * The EXPECTED weight, letter spacing, capitals and family of each slot are read from the resolved markup the page was built from (what
 * LanguageAdapters wrote), never from a table here. A language/width FAILs (one line per reason, `FAIL <language>/<width> <code>: detail`) on:
 *   php-error / empty / console-error   PHP error text in the HTML; no visible content; a console error of the page
 *   type                  a slot missing from the page or on it twice (nothing else is then checked); a run that checked no slot fails with `FAIL type:`
 *   type-expectation      an expectation read from the resolved markup has a hole (the marker did not resolve): the fail-closed guard, never a skip
 *   type-weight           the computed font-weight
 *   type-tracking         the computed letter-spacing (0.05 px tolerance; `normal` is 0px)
 *   type-caps             the computed text-transform (`none` for a role without a capitalization key: Divi's default)
 *   type-family           the first family of the computed font-family
 *   type-font-loaded      after document.fonts.ready, with the slot scrolled into view: a FontFace of that family, status loaded, whose weight descriptor
 *                         contains the weight (the web font really loaded at that weight; proven on the dev site only)
 *   type-overflow         the heading or text wider than its box, its column or the window
 *   overflow              the page wider than the window
 * A sheet the gate or the resolver refuses FAILs once as `<language>/gate`. Problems in the script's own page handling are `render-error`. A preview
 * that comes back as a 404 or without the page content is retried once with a fresh login.
 *
 * Output: PASS/FAIL lines and a summary; dist/type-sheet/<language>-<width>.png (the full page), dist/type-sheet/observations.json (the expected and
 * the computed values of every slot) and dist/type-sheet/index.html (one row per language at 1280 and 400 px). dist/ is git-ignored; writing the sheet
 * never fails the run.
 *
 * Dev site only (localhost). RUN ONE RUN AT A TIME, from the MAIN checkout with the branch checked out (the site runs ./wp-plugin of that checkout).
 * Every throwaway page is titled `aied type <runToken> <language>`; the run deletes ONLY pages whose title starts with `aied type <runToken> `
 * (after every language, at the end, and on SIGINT/SIGTERM/SIGHUP). `--clean-orphans` deletes ALL pages whose title starts with `aied type ` and
 * exits: use it only when no other run is going.
 * Pure parts: scripts/test-verify-language-type.cjs; the page measurement against synthetic pages: scripts/test-language-type-run.cjs
 * (both run first by `make verify-language-type`).
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');
const { devSiteOnly, makeWp, loginSession, endSession, watchConsole, throwawayPages, cleanupOnSignals } = require('./lib/throwaway-pages.cjs');
devSiteOnly(SITE);

const USAGE = 'usage: node scripts/verify-language-type.cjs [--languages a,b]\n       node scripts/verify-language-type.cjs --clean-orphans';
const LANGUAGES_FILE = path.join(ROOT, 'wp-plugin', 'data', 'design-languages.json');
const OUT_DIR = path.join(ROOT, 'dist', 'type-sheet');
const BS = String.fromCharCode(92);

let optLanguages = null;
let cleanOrphans = false;
{
  const args = process.argv.slice(2);
  const list = (v) => (v ? v.split(',').map((s) => s.trim()).filter(Boolean) : []);
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--languages') { optLanguages = list(args[++i]); if (!optLanguages.length) { console.error(USAGE); process.exit(2); } }
    else if (args[i] === '--clean-orphans') cleanOrphans = true;
    else { console.error(USAGE); process.exit(2); }
  }
  if (cleanOrphans && optLanguages) { console.error(USAGE); process.exit(2); }
}

const T = require('./lib/language-type-checks.cjs');
const { measureInPage, PHP_ERROR } = require('./lib/recipe-checks.cjs');
const { checkType } = require('./lib/language-type-run.cjs');

const wp = makeWp(ROOT);
const pages = throwawayPages(wp, T.TITLE_PREFIX, 'type');
const { runToken: RUN_TOKEN, created, sweep, dropPage, removeOrphans } = pages;
const run = { cleaning: false, browser: null };

if (cleanOrphans) process.exit(removeOrphans() ? 0 : 1);

const { chromium } = require(PW);

/** Resolves the sheet in PHP (the real pipeline, with the language's own kit), gates it, saves a draft. Returns { id, valid, verdict, markup }. */
function makePage(language) {
  if (run.cleaning) throw new Error('interrupted');
  // Everything travels as one base64 JSON payload, so nothing can break out of the PHP string.
  const b64 = Buffer.from(JSON.stringify({ title: T.runTitle(RUN_TOKEN, language), language, markup: T.sheetMarkup() })).toString('base64');
  const NS = 'AiEditorDivi5' + BS + 'WP' + BS;
  const out = wp('eval', `$p=json_decode(base64_decode('${b64}'),true);$l=${NS}DesignLanguages::get($p['language']);$k=null===$l||!is_string($l['kit']??null)?null:${NS}StyleKits::get($l['kit']);if($l===null||$k===null||[]!==${NS}StyleKits::problems($k)){echo '0|INVALID language unknown or its kit invalid';return;}`
    + `$m=${NS}SectionRecipes::resolveMarkup($p['markup'],$l,$k);if(null===$m){echo '0|INVALID unresolved (resolveMarkup refused the sheet)';return;}if(str_contains($m,'{{aied:')){echo '0|INVALID unresolved token';return;}`
    + `$c='<!-- wp:divi/placeholder -->'.$m.'<!-- /wp:divi/placeholder -->';$g=${NS}LayoutGate::check($c);if(!$g->isValid()){echo '0|INVALID '.implode(',',array_map(fn($v)=>$v->toArray()['code'],$g->violations()));return;}`
    + `$id=wp_insert_post(wp_slash(['post_type'=>'page','post_title'=>$p['title'],'post_content'=>$c,'post_status'=>'draft','meta_input'=>['_et_pb_use_divi_5'=>'on','_et_pb_use_builder'=>'on']]));echo $id.'|valid|'.base64_encode($m);`);
  const [id, verdict, m64] = out.split('|');
  if (/^\d+$/.test(id) && id !== '0') created.push(id);
  return { id, valid: verdict === 'valid', verdict, markup: m64 ? Buffer.from(m64, 'base64').toString('utf8') : '' };
}

// ---- data ------------------------------------------------------------------------------------------------------------
const languageIds = JSON.parse(fs.readFileSync(LANGUAGES_FILE, 'utf8')).map((l) => l.id);

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/** Writes dist/type-sheet/index.html. Never throws. */
function writeSheet(status, selected) {
  try {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    const cell = (lang, width) => {
      const file = `${lang}-${width}.png`;
      const st = status.get(lang);
      const badge = st ? `<span class="b ${st.toLowerCase()}">${esc(st)}</span>` : '';
      return fs.existsSync(path.join(OUT_DIR, file))
        ? `<td class="w${width}">${badge}<a href="${esc(file)}"><img loading="lazy" src="${esc(file)}" alt="${esc(lang)} ${width}px"></a></td>`
        : `<td class="w${width}">${badge}<em>not rendered</em></td>`;
    };
    const rows = selected.map((l) => `<tr><th><strong>${esc(l)}</strong></th>${cell(l, 1280)}${cell(l, 400)}</tr>`).join('\n');
    const html = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Type sheet</title>
<style>
body{font:14px/1.4 system-ui,sans-serif;margin:16px;background:#f4f4f4;color:#111}
h1{font-size:20px}p{max-width:70em}
.wrap{overflow-x:auto}
table{border-collapse:collapse}
th,td{border:1px solid #ccc;padding:8px;vertical-align:top;background:#fff}
th{text-align:left;min-width:10em;position:sticky;left:0}
td img{display:block;max-width:100%;height:auto}
td.w1280{width:520px;min-width:520px}td.w400{width:160px;min-width:160px}
.b{display:inline-block;font:700 11px sans-serif;padding:1px 6px;border-radius:8px;margin-bottom:4px;color:#fff}
.b.pass{background:#1a7f37}.b.fail{background:#b3261e}
</style></head><body>
<h1>Type sheet</h1>
<p>Generated by <code>make verify-language-type</code> (${esc(new Date().toISOString())}). Each sheet is a display h1 and h2, a title h3, an eyebrow h4 and an eyebrow text in one design language, rendered on the real Divi at desktop (1280 px) and phone (400 px); a 900 px screenshot of each is in the same folder, and <code>observations.json</code> has the expected and the computed values.</p>
<div class="wrap"><table>
<thead><tr><th>Language</th><th>1280 px</th><th>400 px</th></tr></thead>
<tbody>
${rows}
</tbody></table></div>
</body></html>
`;
    fs.writeFileSync(path.join(OUT_DIR, 'index.html'), html);
    console.log('type sheet: ' + path.relative(ROOT, path.join(OUT_DIR, 'index.html')));
  } catch (e) {
    console.log('WARNING could not write the type sheet: ' + (e && e.message ? e.message : e));
  }
}

/** A fresh login for the run; the previous session token is destroyed first. */
function renewSession(state) {
  if (state.token) endSession(wp, state.token);
  const s = loginSession(wp);
  state.cookies = s.cookies;
  state.token = s.token;
}

/** Opens the preview, logged in; one retry with a fresh login when it is a 404 or has no page content. Returns { p, consoleErrors, status }. */
async function openPreview(ctx, id, state) {
  for (let attempt = 0; attempt < 2; attempt++) {
    if (attempt > 0 || !state.cookies) renewSession(state);
    await ctx.clearCookies();
    await ctx.addCookies(state.cookies.map(([n, value]) => ({ name: n, value, url: SITE })));
    const p = await ctx.newPage();
    const consoleErrors = watchConsole(p, SITE);
    const resp = await p.goto(`${SITE}/?page_id=${id}&preview=true`, { waitUntil: 'load' });
    const ok = resp && resp.status() < 400 && (await p.$('.et-l--post'));
    if (ok || attempt === 1) return { p, consoleErrors, status: resp ? resp.status() : 0 };
    console.log(`NOTE preview of page ${id} came back ${resp ? resp.status() : 'without a response'}: logging in again and retrying`);
    await p.close();
  }
  return null;
}

(async () => {
  let browser;
  let failures = 0;
  let passes = 0;
  const status = new Map();
  const observations = [];
  let typeChecked = 0;
  const state = { cookies: null, token: null };
  cleanupOnSignals(run, sweep, pages.runPrefix);
  let selected = [];
  try {
    try { selected = T.selectLanguages(optLanguages, languageIds); } catch (e) {
      // Unknown ids are a usage error (2); an empty selection would check nothing, which is a failure (1), never a silent pass.
      if (e.empty) { console.log('FAIL ' + e.message + '\nFAILED'); process.exit(1); }
      console.error(e.message); process.exit(2);
    }
    const foreign = pages.foreignCount();
    if (foreign > 0) console.log(`WARNING ${foreign} leftover or concurrent type pages (not deleted; run with --clean-orphans when no other run is going)`);
    fs.mkdirSync(OUT_DIR, { recursive: true });
    browser = await chromium.launch({ handleSIGINT: false, handleSIGTERM: false, handleSIGHUP: false });
    run.browser = browser;
    console.log(`${selected.length} language(s) x ${T.WIDTHS.length} widths x ${T.TYPE_SLOTS.length} slots, run ${RUN_TOKEN}`);

    const report = (lang, width, problems) => {
      if (run.cleaning) return;
      if (!problems.length) { passes++; console.log(`PASS ${lang}/${width} ok`); if (!status.has(lang)) status.set(lang, 'PASS'); return; }
      failures++;
      status.set(lang, 'FAIL');
      for (const [code, detail] of problems) console.log(`FAIL ${lang}/${width} ${code}: ${detail}`);
    };

    for (const lang of selected) {
      if (run.cleaning) break;
      let page = null;
      try {
        renewSession(state); // a fresh session per language (a long run outlives one cookie); the previous one is destroyed
        page = makePage(lang);
        if (!page.valid) { report(lang, 'gate', [['gate', 'not saved: ' + page.verdict]]); continue; }
        // The slots found again in the RESOLVED markup, with complete expectations: a hole is a gate failure here, never a skipped check.
        const blocks = T.pageTypeAttrs(page.markup);
        const absent = blocks.filter((b) => !b.attrs).map((b) => b.slot.key);
        if (absent.length) { report(lang, 'gate', [['gate', 'slots not found in the resolved sheet (by their text): ' + absent.join(', ')]]); continue; }
        const expects = blocks.map((b) => T.expectTypeFromAttrs(b.attrs, b.slot));
        const missing = expects.flatMap((e, i) => T.missingTypeExpectations(e).map((f) => `${blocks[i].slot.key} ${f}`));
        if (missing.length) { report(lang, 'gate', [['gate', 'type expectation missing (the marker did not resolve, or a path drifted): ' + missing.slice(0, 6).join(', ') + (missing.length > 6 ? ` and ${missing.length - 6} more` : '')]]); continue; }
        for (const width of T.WIDTHS) {
          const ctx = await browser.newContext({ viewport: { width, height: 900 } });
          const problems = [];
          try {
            const opened = await openPreview(ctx, page.id, state);
            const { p, consoleErrors } = opened;
            await p.waitForTimeout(600);
            await p.evaluate(async () => { for (let y = 0; y < document.documentElement.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); } window.scrollTo(0, 0); });
            await p.waitForTimeout(500);
            const html = await p.content();
            const php = PHP_ERROR.map((re) => re.exec(html)).find(Boolean);
            if (php) problems.push(['php-error', 'PHP error text in the HTML: ' + php[0].replace(/\s+/g, ' ').slice(0, 160)]);
            const m = await p.evaluate(measureInPage);
            if (!m.hasRoot) problems.push(['empty', `no .et-l--post content on the page (HTTP ${opened.status})`]);
            else if (!m.visible) problems.push(['empty', 'the page content shows nothing visible']);
            if (m.hasRoot) {
              const tr = await checkType(p, { width, expects });
              typeChecked += tr.typeChecked;
              for (const pr of tr.problems) problems.push(pr);
              for (const o of tr.observations) observations.push({ language: lang, ...o });
            }
            try { await p.screenshot({ path: path.join(OUT_DIR, `${lang}-${width}.png`), fullPage: true }); } catch (e) { console.log(`WARNING screenshot failed for ${lang}/${width}`); }
            if (consoleErrors.length) problems.push(['console-error', [...new Set(consoleErrors)].slice(0, 3).join(' | ')]);
            await p.close();
          } catch (e) {
            problems.push(['render-error', (e && e.message ? e.message : String(e)).split('\n')[0]]);
          } finally {
            await ctx.close();
          }
          report(lang, width, problems);
        }
      } catch (e) {
        report(lang, 'prepare', [['render-error', 'could not prepare the page: ' + (e && e.message ? e.message : e)]]);
      } finally {
        if (page && /^\d+$/.test(page.id) && page.id !== '0') dropPage(page.id);
      }
    }
    if (run.cleaning) return;
    try { fs.writeFileSync(path.join(OUT_DIR, 'observations.json'), JSON.stringify(observations, null, 1)); } catch (e) { console.log('WARNING could not write observations.json'); }
    writeSheet(status, selected);
  } catch (e) {
    console.log('ERROR script: ' + (e && e.message ? e.message : e));
    failures++;
  } finally {
    if (!run.cleaning) {
      if (state.token) endSession(wp, state.token);
      if (!sweep()) failures++;
      if (browser) await browser.close();
    }
  }
  if (run.cleaning) return;
  for (const why of T.emptyTypeRunProblems({ checked: passes + failures, typeChecked })) { failures++; console.log('FAIL type: ' + why); }
  console.log(`\n${passes} PASS, ${failures} FAIL (language/width lines) over ${selected.length} language(s)`);
  console.log(failures ? 'FAILED' : 'Every selected language renders its type as it says on the real Divi.');
  process.exit(failures ? 1 : 0);
})();
