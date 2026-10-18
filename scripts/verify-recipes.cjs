#!/usr/bin/env node
/*
 * Dev-only: the recipe render gate. Serves every selected section recipe (under the default look or a design language),
 * saves it as a throwaway DRAFT page through LayoutGate::check + wp_insert_post(wp_slash(...)), opens it with the real Divi
 * in Chromium at 1280, 900 and 400 px and reports one line per recipe/language/width:
 *
 *   PASS|FAIL|KNOWN-FAIL <recipe>/<language|default>/<width> <reason>
 *
 *   node scripts/verify-recipes.cjs [--names a,b] [--languages x,y] [--clean-orphans]    (needs `make up`; Playwright from ../layoutlab)
 *
 * Selection. No flags: every recipe under the default look (no language) and under `glass-dark`, plus every design language
 * once on three representative recipes (hero-cta, card-grid-3, testimonial), plus EVERY recipe under tech-mono and playful-pastel (a dark and a light-pastel
 * look, for the computed contrast check). `--names` narrows the recipes (the default two
 * looks), `--languages` narrows the looks (`default` = no language; every recipe), both together = their product.
 *
 * A cell FAILs when: the HTML contains PHP error text (Fatal error, Parse error, Warning:, Notice:, Deprecated:, Uncaught),
 * the post content renders nothing visible, document.documentElement.scrollWidth > innerWidth, a row (top-level `.et_pb_row`, nested
 * `divi/row` which is also `.et_pb_row`, or nested `divi/row-inner` = `.et_pb_row_inner`) with more
 * than one column (`.et_pb_column` / `.et_pb_column_inner`) shows those columns side by side at 400 px (unless the recipe is listed in
 * fixtures/shapes/recipe-render-allowances.json with a reason; the Theme Builder header/footer is never measured: everything is
 * measured inside `.et-l--post`), any visible text whose computed colour is below 3.0:1 against its effective SOLID background (code
 * `low-contrast`, baselinable only as an exact cell; the check walks the ancestors to the first solid colour, stacking translucent ones, and
 * SKIPS text above a background image or gradient because that background is unknown; an exemption is `{"contrast": "reason"}` in the
 * allowances file), a text element's lines overlap an image box (an `.et_pb_image` module or an <img>) by more than 4 px in both
 * directions at ANY width (code `text-overlap`, never baselined; an intentional overlap recipe gets `{"overlap": "reason"}` in the allowances file; a
 * plain string value keeps its original meaning, the columns exemption), or the page itself logs a console error / uncaught exception. The page is
 * scrolled once before it is measured and screenshotted so that entrance animations have finished. A recipe the validator gate
 * rejects FAILs once as `<recipe>/<language>/gate` with the violation codes.
 * Reason codes (the baseline may name the first six and low-contrast): php-error, empty, overflow, columns-side-by-side, console-error, gate, low-contrast; text-overlap is never baselined.
 * `render-error` (a timeout, navigation crash or any exception in the script's own page handling) is ALWAYS a FAIL and can never be baselined.
 *
 * KNOWN-FAIL. fixtures/shapes/recipe-baseline.txt may list `recipe|language|width|code|explanation` under `# render-failures`
 * (language `default` for no language, width exactly 1280/900/400 or `gate` for code gate, no wildcard); ONLY that exact
 * recipe/language/width/code is printed as KNOWN-FAIL and does not fail the run. Any other failure does, including the same
 * recipe at another width, in another language or for another reason. A malformed entry stops the run. The run goes through every selection and
 * reports every failure; the exit code is 1 when anything FAILs, 2 for a usage error, otherwise 0.
 *
 * Output. Full-page screenshots dist/contact-sheet/<recipe>-<language|default>-<width>.png and dist/contact-sheet/index.html
 * (self-contained, relative image paths, one row per recipe, desktop and phone for the default look and for glass-dark).
 * dist/ is git-ignored. Writing the sheet never fails the run.
 *
 * Dev site only (localhost). RUN ONE RUN AT A TIME. Every throwaway page is titled `aied recipe <runToken> <name> <language>`
 * where the runToken (process id + 3 random bytes in hex) is made once per run; the run deletes ONLY pages whose title starts
 * with `aied recipe <runToken> ` (after every recipe, at the end, and on SIGINT/SIGTERM/SIGHUP). At startup the run warns when
 * pages with the bare prefix `aied recipe ` (another run's, or leftovers) exist. `--clean-orphans` deletes ALL pages whose title
 * starts with the bare prefix (an exact prefix filter on the full page list, numeric ids only, never a search) and exits: use it
 * only when no other run is going.
 */
'use strict';

const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');

if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(SITE)) {
  console.error('Dev site only (localhost). Refusing ' + SITE);
  process.exit(2);
}

const USAGE = 'usage: node scripts/verify-recipes.cjs [--names a,b] [--languages x,y]\n       node scripts/verify-recipes.cjs --clean-orphans';
const RECIPES_FILE = path.join(ROOT, 'wp-plugin', 'data', 'section-recipes.json');
const LANGUAGES_FILE = path.join(ROOT, 'wp-plugin', 'data', 'design-languages.json');
const BASELINE_FILE = path.join(ROOT, 'fixtures', 'shapes', 'recipe-baseline.txt');
const ALLOWANCES_FILE = path.join(ROOT, 'fixtures', 'shapes', 'recipe-render-allowances.json');
const OUT_DIR = path.join(ROOT, 'dist', 'contact-sheet');
const WIDTHS = [1280, 900, 400];
const REPRESENTATIVE = ['hero-cta', 'card-grid-3', 'testimonial'];
const SHEET_LOOKS = ['default', 'glass-dark'];
const CONTRAST_LOOKS = ['tech-mono', 'playful-pastel']; // a dark and a light-pastel look every recipe is also rendered under
const BS = String.fromCharCode(92);

let optNames = null;
let optLanguages = null;
let cleanOrphans = false;
{
  const args = process.argv.slice(2);
  const list = (v) => (v ? v.split(',').map((s) => s.trim()).filter(Boolean) : []);
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--names') { optNames = list(args[++i]); if (!optNames.length) { console.error(USAGE); process.exit(2); } }
    else if (args[i] === '--languages') { optLanguages = list(args[++i]); if (!optLanguages.length) { console.error(USAGE); process.exit(2); } }
    else if (args[i] === '--clean-orphans') cleanOrphans = true;
    else { console.error(USAGE); process.exit(2); }
  }
  if (cleanOrphans && (optNames || optLanguages)) { console.error(USAGE); process.exit(2); }
}

const { chromium } = require(PW);
const { parseBaseline, knownFailures, isKnownFail, cellKey, measureInPage, PHP_ERROR } = require('./lib/recipe-checks.cjs');

const wp = (...a) => execFileSync('docker', ['compose', 'exec', '-T', 'wpcli', 'php', '-d', 'memory_limit=1G', '/usr/local/bin/wp', ...a], { cwd: ROOT, encoding: 'utf8' }).trim();

// Every throwaway page's title starts with RUN_PREFIX (the bare TITLE_PREFIX plus this run's token).
const TITLE_PREFIX = 'aied recipe ';
const RUN_TOKEN = process.pid + '-' + crypto.randomBytes(3).toString('hex');
const RUN_PREFIX = TITLE_PREFIX + RUN_TOKEN + ' ';
const created = [];
let cleaning = false; // set by the signal handler: nothing new is created after it

/** Every page's { ID, post_title } (a full list, never a content search). */
function allPages() {
  return JSON.parse(wp('post', 'list', '--post_type=page', '--post_status=any', '--fields=ID,post_title', '--format=json') || '[]');
}
const idsWithPrefix = (rows, prefix) => rows.filter((r) => typeof r.post_title === 'string' && r.post_title.startsWith(prefix)).map((r) => String(r.ID));

/**
 * Deletes this run's throwaway pages: the recorded ids plus every page whose title starts with the exact RUN_PREFIX (a page
 * could have been inserted by a call that then failed before returning its id). Never a search, never a page without that
 * prefix, never another run's page, numeric ids only. Safe to call twice. Returns true when everything went well.
 */
function sweep() {
  let ok = true;
  let swept = [];
  try { swept = idsWithPrefix(allPages(), RUN_PREFIX); } catch (e) { console.log('WARNING could not list leftover recipe pages'); ok = false; }
  for (const id of [...new Set([...created, ...swept])]) {
    if (!/^\d+$/.test(String(id))) { console.log('WARNING ignoring non-numeric page id ' + id); ok = false; continue; }
    try { wp('post', 'delete', String(id), '--force'); } catch (e) { console.log('WARNING could not delete page ' + id); ok = false; }
  }
  created.length = 0;
  return ok;
}

/** Deletes one page this run created (recorded id, numeric only); the final sweep catches anything this misses. */
function dropPage(id) {
  if (!/^\d+$/.test(String(id))) return;
  try { wp('post', 'delete', String(id), '--force'); } catch (e) { console.log('WARNING could not delete page ' + id + ' (the final sweep retries)'); return; }
  const at = created.indexOf(String(id));
  if (at >= 0) created.splice(at, 1);
}

/** --clean-orphans: deletes ALL pages whose title starts with the bare prefix (only when no other run is going). */
function removeOrphans() {
  let ok = true;
  let ids = [];
  try { ids = idsWithPrefix(allPages(), TITLE_PREFIX); } catch (e) { console.log('ERROR could not list pages'); return false; }
  for (const id of ids) {
    if (!/^\d+$/.test(id)) { console.log('WARNING ignoring non-numeric page id ' + id); ok = false; continue; }
    try { wp('post', 'delete', id, '--force'); console.log('deleted orphan recipe page ' + id); } catch (e) { console.log('WARNING could not delete page ' + id); ok = false; }
  }
  console.log(`removed ${ids.length} orphan recipe page(s)`);
  return ok;
}

if (cleanOrphans) process.exit(removeOrphans() ? 0 : 1);

/** Serves the recipe in PHP, gates it, saves a draft. Returns { id, valid, verdict }. */
function makePage(name, language) {
  if (cleaning) throw new Error('interrupted');
  // Name and language travel as one base64 JSON payload, so nothing can break out of the PHP string.
  const b64 = Buffer.from(JSON.stringify({ title: RUN_PREFIX + name + ' ' + language, name, language: language === 'default' ? null : language })).toString('base64');
  const NS = 'AiEditorDivi5' + BS + 'WP' + BS;
  const out = wp('eval', `$p=json_decode(base64_decode('${b64}'),true);$m=${NS}SectionRecipes::recipe($p['name'],$p['language']);if($m===null){echo '0|INVALID recipe or language unknown';return;}$c='<!-- wp:divi/placeholder -->'.$m.'<!-- /wp:divi/placeholder -->';$g=${NS}LayoutGate::check($c);if(!$g->isValid()){echo '0|INVALID '.implode(',',array_map(fn($v)=>$v->toArray()['code'],$g->violations()));return;}$id=wp_insert_post(wp_slash(['post_type'=>'page','post_title'=>$p['title'],'post_content'=>$c,'post_status'=>'draft','meta_input'=>['_et_pb_use_divi_5'=>'on','_et_pb_use_builder'=>'on']]));echo $id.'|valid';`);
  const [id, verdict] = out.split('|');
  if (/^\d+$/.test(id) && id !== '0') created.push(id);
  return { id, valid: verdict === 'valid', verdict };
}

// ---- data ------------------------------------------------------------------------------------------------------------
const recipes = JSON.parse(fs.readFileSync(RECIPES_FILE, 'utf8'));
const recipeNames = recipes.map((r) => r.name);
const languageIds = JSON.parse(fs.readFileSync(LANGUAGES_FILE, 'utf8')).map((l) => l.id);

/** fixtures/shapes/recipe-baseline.txt `# render-failures`: Map of exact cell key (recipe|language|width|code) => entry. A malformed line is a hard error. */
function readKnownFailures() {
  return knownFailures(parseBaseline(fs.readFileSync(BASELINE_FILE, 'utf8')).renderFailures);
}
const allowances = fs.existsSync(ALLOWANCES_FILE) ? JSON.parse(fs.readFileSync(ALLOWANCES_FILE, 'utf8')) : {};
/** True when fixtures/shapes/recipe-render-allowances.json exempts `name` from a check: a string value exempts `columns` (the original meaning), an object {columns, overlap, contrast} exempts each named check, each with its reason. */
function allowed(name, kind) {
  if (!Object.prototype.hasOwnProperty.call(allowances, name)) return false;
  const v = allowances[name];
  return typeof v === 'string' ? kind === 'columns' : !!(v && typeof v === 'object' && typeof v[kind] === 'string' && v[kind].trim());
}

/** The (recipe, language) cells to render. */
function selection() {
  const bad = (optNames || []).filter((n) => !recipeNames.includes(n));
  if (bad.length) { console.error('Unknown recipe(s): ' + bad.join(', ') + '\nValid: ' + recipeNames.join(', ')); process.exit(2); }
  const badLang = (optLanguages || []).filter((l) => l !== 'default' && !languageIds.includes(l));
  if (badLang.length) { console.error('Unknown language(s): ' + badLang.join(', ') + '\nValid: default, ' + languageIds.join(', ')); process.exit(2); }
  const cells = [];
  if (optNames || optLanguages) {
    for (const n of optNames || recipeNames) for (const l of optLanguages || ['default', 'glass-dark']) cells.push([n, l]);
  } else {
    for (const n of recipeNames) for (const l of ['default', 'glass-dark', ...CONTRAST_LOOKS]) cells.push([n, l]);
    for (const l of languageIds) for (const n of REPRESENTATIVE) if (recipeNames.includes(n)) cells.push([n, l]);
  }
  const seen = new Set();
  return cells.filter(([n, l]) => { const k = n + '/' + l; if (seen.has(k)) return false; seen.add(k); return true; });
}

// ---- run ---------------------------------------------------------------------------------------------------------------
let browserRef = null;
for (const sig of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
  process.on(sig, async () => {
    if (cleaning) return;
    cleaning = true;
    console.log('\n' + sig + ': removing the throwaway pages');
    try { if (browserRef) await browserRef.close(); } catch (e) { /* leaving anyway */ }
    const ok = sweep();
    console.log(ok ? 'Cleaned up.' : 'WARNING cleanup was incomplete; run `wp post list --post_type=page --fields=ID,post_title` and look for "' + RUN_PREFIX + '"');
    process.exit(sig === 'SIGINT' ? 130 : sig === 'SIGHUP' ? 129 : 143);
  });
}

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/** Writes dist/contact-sheet/index.html. Never throws. `status` maps "recipe/language" to PASS|FAIL|KNOWN-FAIL for this run. */
function writeContactSheet(status) {
  try {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    const cell = (r, look, width) => {
      const file = `${r.name}-${look}-${width}.png`;
      const st = status.get(r.name + '/' + look);
      const badge = st ? `<span class="b ${st.toLowerCase()}">${esc(st)}</span>` : '';
      return fs.existsSync(path.join(OUT_DIR, file))
        ? `<td class="w${width}">${badge}<a href="${esc(file)}"><img loading="lazy" src="${esc(file)}" alt="${esc(r.name)} ${esc(look)} ${width}px"></a></td>`
        : `<td class="w${width}">${badge}<em>not rendered</em></td>`;
    };
    const rows = recipes.map((r) => {
      const t = r.tags || {};
      const tags = ['role', 'layout', 'density', 'background'].map((k) => (t[k] ? `${k}: ${esc(t[k])}` : '')).filter(Boolean).concat(Array.isArray(t.mood) ? ['mood: ' + esc(t.mood.join(', '))] : [], t.needs_images ? ['needs images'] : []);
      return `<tr><th><strong>${esc(r.name)}</strong><br>${esc(r.title || '')}<br><small>${tags.join(' &middot; ')}</small></th>`
        + SHEET_LOOKS.map((look) => cell(r, look, 1280) + cell(r, look, 400)).join('') + '</tr>';
    }).join('\n');
    const html = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recipe contact sheet</title>
<style>
body{font:14px/1.4 system-ui,sans-serif;margin:16px;background:#f4f4f4;color:#111}
h1{font-size:20px}p{max-width:70em}
.wrap{overflow-x:auto}
table{border-collapse:collapse}
th,td{border:1px solid #ccc;padding:8px;vertical-align:top;background:#fff}
th{text-align:left;min-width:14em;position:sticky;left:0}
td img{display:block;max-width:100%;height:auto}
td.w1280{width:420px;min-width:420px}td.w400{width:150px;min-width:150px}
.b{display:inline-block;font:700 11px sans-serif;padding:1px 6px;border-radius:8px;margin-bottom:4px;color:#fff}
.b.pass{background:#1a7f37}.b.fail{background:#b3261e}.b.known-fail{background:#9a6700}
</style></head><body>
<h1>Recipe contact sheet</h1>
<p>Generated by <code>make verify-recipes</code> (${esc(new Date().toISOString())}). Full-page screenshots on the real Divi: the default look and glass-dark, at desktop (1280 px) and phone (400 px); a 900 px screenshot of each is in the same folder. A cell says &quot;not rendered&quot; when this folder has no image for it.</p>
<div class="wrap"><table>
<thead><tr><th>Recipe</th><th colspan="2">Default look</th><th colspan="2">glass-dark</th></tr></thead>
<tbody>
${rows}
</tbody></table></div>
</body></html>
`;
    fs.writeFileSync(path.join(OUT_DIR, 'index.html'), html);
    console.log('contact sheet: ' + path.relative(ROOT, path.join(OUT_DIR, 'index.html')));
  } catch (e) {
    console.log('WARNING could not write the contact sheet: ' + (e && e.message ? e.message : e));
  }
}

(async () => {
  let browser;
  let failures = 0;
  let knownFails = 0;
  let passes = 0;
  const status = new Map(); // "recipe/language" => worst of PASS|KNOWN-FAIL|FAIL over its widths
  const rank = { PASS: 0, 'KNOWN-FAIL': 1, FAIL: 2 };
  const known = readKnownFailures();
  const seenKnown = new Set();
  try {
    const cells = selection();
    const cookies = JSON.parse(wp('eval', '$u=1;$e=time()+3600;$t=WP_Session_Tokens::get_instance($u)->create($e);echo json_encode([[AUTH_COOKIE,wp_generate_auth_cookie($u,$e,"auth",$t)],[LOGGED_IN_COOKIE,wp_generate_auth_cookie($u,$e,"logged_in",$t)]]);'));
    // Nothing is ours yet, so every page with the bare prefix is another run's or a leftover; it is reported, never deleted.
    const foreign = idsWithPrefix(allPages(), TITLE_PREFIX).length;
    if (foreign > 0) console.log(`WARNING ${foreign} leftover or concurrent recipe pages (not deleted; run with --clean-orphans when no other run is going)`);
    fs.mkdirSync(OUT_DIR, { recursive: true });
    // Playwright would otherwise install its own signal handlers and exit before the page cleanup finishes.
    browser = await chromium.launch({ handleSIGINT: false, handleSIGTERM: false, handleSIGHUP: false });
    browserRef = browser;
    console.log(`${cells.length} recipe/language cell(s) x ${WIDTHS.length} widths, run ${RUN_TOKEN}`);

    const report = (name, lang, width, outcome, code, reason) => {
      if (cleaning) return; // interrupted: the in-flight cell's errors are noise
      let label = outcome;
      // Only the EXACT cell (recipe, language, width, code) listed in the baseline is a known failure; render-error never is.
      if (outcome === 'FAIL' && isKnownFail(known, name, lang, width, code)) {
        const e = known.get(cellKey(name, lang, width, code));
        label = 'KNOWN-FAIL';
        seenKnown.add(cellKey(name, lang, width, code));
        reason += ' [known: ' + e.explanation + ']';
      }
      if (label === 'FAIL') failures++; else if (label === 'KNOWN-FAIL') knownFails++; else passes++;
      const k = name + '/' + lang;
      if (!status.has(k) || rank[label] > rank[status.get(k)]) status.set(k, label);
      console.log(`${label} ${name}/${lang}/${width} ${reason}`);
    };

    for (const [name, lang] of cells) {
      if (cleaning) break;
      let page = null;
      try {
        page = makePage(name, lang);
        if (!page.valid) { report(name, lang, 'gate', 'FAIL', 'gate', 'not saved: ' + page.verdict); continue; }
        for (const width of WIDTHS) {
          const ctx = await browser.newContext({ viewport: { width, height: 900 } });
          try {
            await ctx.addCookies(cookies.map(([n, value]) => ({ name: n, value, url: SITE })));
            const p = await ctx.newPage();
            const consoleErrors = [];
            p.on('pageerror', (e) => consoleErrors.push('uncaught: ' + String(e && e.message ? e.message : e).slice(0, 200)));
            p.on('console', (m) => {
              if (m.type() !== 'error') return;
              const url = (m.location() && m.location().url) || '';
              if (/favicon\.ico/.test(url)) return;
              // Only errors from the page itself: its own origin, or a script error with no URL. A third-party request is not ours.
              if (url && !url.startsWith(SITE)) return;
              consoleErrors.push(('console: ' + m.text()).slice(0, 200) + (url ? ' (' + url.slice(SITE.length) + ')' : ''));
            });
            await p.goto(`${SITE}/?page_id=${page.id}&preview=true`, { waitUntil: 'load' });
            await p.waitForTimeout(800);
            // Entrance animations start when an element scrolls into view and move or hide it until they end: scroll the whole page once,
            // go back to the top and let them finish, so the screenshot and the measurements see the settled page.
            await p.evaluate(async () => { for (let y = 0; y < document.documentElement.scrollHeight; y += 300) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 120)); } window.scrollTo(0, 0); });
            await p.waitForTimeout(1500);
            const html = await p.content();
            const m = await p.evaluate(measureInPage);
            try { await p.screenshot({ path: path.join(OUT_DIR, `${name}-${lang}-${width}.png`), fullPage: true }); } catch (e) { console.log('WARNING screenshot failed for ' + name + '/' + lang + '/' + width); }

            const problems = []; // [code, reason]
            const php = PHP_ERROR.map((re) => re.exec(html)).find(Boolean);
            if (php) problems.push(['php-error', 'PHP error text in the HTML: ' + php[0].replace(/\s+/g, ' ').slice(0, 160)]);
            if (!m.hasRoot) problems.push(['empty', 'no .et-l--post content on the page']);
            else if (!m.visible) problems.push(['empty', 'the page content shows nothing visible']);
            if (m.overflow) problems.push(['overflow', `scrollWidth ${m.scrollWidth} > innerWidth ${m.innerWidth}`]);
            if (m.overlaps.length && !allowed(name, 'overlap')) {
              problems.push(['text-overlap', 'text overlaps an image: ' + m.overlaps.slice(0, 3).map((o) => `"${o.text}" over ${o.image} (${o.w}x${o.h}px)`).join('; ')]);
            }
            if (m.lowContrast.length && !allowed(name, 'contrast')) {
              problems.push(['low-contrast', 'text under 3.0:1 against its solid background: ' + m.lowContrast.slice(0, 4).map((o) => `"${o.text}" ${o.color} on ${o.bg} ${o.ratio}:1`).join('; ')]);
            }
            if (width === 400 && m.sideBySide.length && !allowed(name, 'columns')) {
              problems.push(['columns-side-by-side', 'row(s) keep their columns side by side on a phone: ' + m.sideBySide.map((s) => `row ${s.row} (${s.columns} cols, lefts ${s.lefts.join('/')})`).join('; ')]);
            }
            if (consoleErrors.length) problems.push(['console-error', [...new Set(consoleErrors)].slice(0, 3).join(' | ')]);

            if (!problems.length) report(name, lang, width, 'PASS', null, 'ok');
            else for (const [code, reason] of problems) report(name, lang, width, 'FAIL', code, `${code}: ${reason}`);
          } catch (e) {
            report(name, lang, width, 'FAIL', 'render-error', 'render-error: ' + (e && e.message ? e.message : e));
          } finally {
            await ctx.close();
          }
        }
      } catch (e) {
        report(name, lang, 'prepare', 'FAIL', 'render-error', 'render-error: could not prepare the page: ' + (e && e.message ? e.message : e));
      } finally {
        if (page && /^\d+$/.test(page.id) && page.id !== '0') dropPage(page.id);
      }
    }
    if (cleaning) return;
    for (const [key, e] of known) if (!seenKnown.has(key) && cells.some(([n, l]) => n === e.recipe && l === e.language)) console.log(`NOTE baseline render-failure "${key}" did not occur in this run: remove it if it is fixed`);
    writeContactSheet(status);
  } catch (e) {
    console.log('ERROR script: ' + (e && e.message ? e.message : e));
    failures++;
  } finally {
    if (!cleaning) {
      if (!sweep()) failures++;
      if (browser) await browser.close();
    }
  }
  if (cleaning) return;
  console.log(`\n${passes} PASS, ${knownFails} KNOWN-FAIL, ${failures} FAIL`);
  console.log(failures ? 'FAILED' : 'Every selected recipe renders cleanly (known failures aside).');
  process.exit(failures ? 1 : 0);
})();
