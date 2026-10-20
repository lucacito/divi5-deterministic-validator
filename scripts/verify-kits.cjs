#!/usr/bin/env node
/*
 * Dev-only: the style-kit render gate. For each (kit, language) cell it builds ONE "kit sheet" through the REAL pipeline (the sheet template
 * of scripts/lib/kit-checks.cjs: a primary `control` and a secondary `control-secondary` button on each surface: light on a bg section, light
 * on a surface section, an accent band ("on":"accent") and a photo band with the overlay scrim ("on":"dark"); on the same four bands a `card`
 * and a `panel` (divi/group, each with a heading and a paragraph; the bg-band card and one zero-padding "clip" panel also hold an image),
 * plus a row of three `divi/column` cards on the bg band, and one `divi/image` on the bg band whose radius is the token `{{aied:kit:radius-media}}`; resolved in PHP exactly as SectionRecipes::recipe serves a recipe: ImageTokens with
 * the plugin's pack, KitResolver with the kit, RecipeTokens with the language), gates it through LayoutGate::check, saves it as a throwaway
 * DRAFT and opens it with the real Divi in Chromium at 1280, 900 and 400 px.
 *
 *   node scripts/verify-kits.cjs [--kits a,b] [--languages x,y] [--clean-orphans]    (needs `make up`; Playwright from ../layoutlab)
 *
 * Selection. No flags: every kit under `editorial` and under `glass-dark`, plus every design language under its own default kit (its `kit`).
 * `--kits` narrows the kits (under those two languages), `--languages` narrows the languages (every kit), both together = their product.
 *
 * The EXPECTED values of each button are read from the resolved markup the page was built from (the adapter's output), never from a table
 * here. A cell/width FAILs (one line per reason, `FAIL <kit>/<language>/<width> <code>: detail`) on: PHP error text (php-error), no visible
 * content (empty), a page wider than the window or a button outside its column or the window, at rest or on hover (overflow), a console
 * error of the page (console-error), not 8 rendered buttons (buttons), a computed style that disagrees with the kit at rest: border-top and
 * border-bottom width, style and colour (border), the four radii (radius), background colour (fill), label colour (label), text-transform,
 * letter-spacing and weight (text), padding (padding), box-shadow presence, blur (zero for hard/edge, non-zero for soft/glow), offsets and
 * colour on the button or its wrapper, whichever carries it (shadow); after a real Playwright hover(): background, label and border colours
 * (hover), the shadow (shadow) and translateY -4px for a lift kit, none otherwise (lift); contrast (contrast): the label against the fill as
 * painted over the surface >= 4.5 and, where the fill is not opaque, each drawn border side against the surface >= 3.0, rest and hover. The
 * surface is MEASURED: the mean colour of a screenshot of the button's box with every button hidden (so the photo band is the real photo
 * under the real scrim).
 *
 * The 20 CARDS (scripts/lib/kit-card-run.cjs, judged by the pure comparisons of kit-checks.cjs) are found by the unique heading each holds and
 * their expectations are read from the resolved block tree, never a table here. Codes, each `<slot> <detail>`: cards (not all 20 rendered,
 * nothing else is then checked); card-border (width, style and colour of each of the four sides: an underline kit draws only its bottom
 * edge); card-radius (the four corners); card-fill (computed background, and the painted fill under the card); card-padding (a card: the
 * kit's, a panel: its own literal); card-shadow (presence, blur, offsets, colour, on the card element itself); card-hover, after a real
 * Playwright hover(): the frame colour changes only where the markup has a hover border colour, the hover shadow, translateY -4px for a
 * lift and no movement otherwise, the fill never changes, and a PANEL must show NO change at all (fill, frame, shadow, transform);
 * card-clip (a radius needs overflow hidden/clip on the card, an image must stay inside the card, and the zero-padding panel's image must
 * really touch the corners so the radius clip is exercised: a computed-style and geometry check, not a pixel one); card-contrast (the
 * heading and the paragraph against the MEASURED card fill >= 4.5, every drawn frame side >= 3.0 unless it is the language's decorative
 * `border` hairline); overflow also covers every card (inside its container and the window, rest and hover) and the row of three column
 * cards (side by side at 1280, stacked at 400, no demand at 900); `card-expectation` is the fail-closed guard of compareCard (an expectation
 * with a hole is refused, never skipped): the cell gate below stops such a cell first, so it only shows if that gate is bypassed. The grounds
 * and the button panels add three codes (their frame, radius, padding, shadow and hover reuse the card-* codes): ground-fill (the PAINTED
 * fill of an inverted or accent card must be the fill the resolved markup says, whatever the computed style reports; a ground's frame is
 * its fill, so a frame the colour of the fill is not held to the 3.0 frame rule); panel-button (each kit button inside a button panel is
 * hovered with a real pointer and judged on the painted panel: the label against its fill >= 4.5, a transparent button's border >= 3.0
 * against the panel, rest and hover, and the hover colours as resolved; its other attributes keep the button codes border, radius, fill,
 * label, text, padding, shadow, lift); featured (the accent middle card of the row: the mean painted luminance difference between its box
 * and the band, per pixel, must be at least FEATURED_RATIO = 1.5 times the larger neighbour's, at every width; the ratio is confirmed on
 * the first live smoke and then frozen, never lowered after a failure). A run whose card selection is empty fails.
 *
 * The 10 ITEM BLOCKS (scripts/lib/kit-item-run.cjs, judged by the pure comparisons of kit-checks.cjs) are found by the unique title of their
 * first item; their expectations are read from the resolved block tree, per STATE (open and closed), never a table here. On the bg and
 * surface bands: an `item` accordion (three items, the first open), a boxed `item` toggle (closed) and two `item-rule` toggles (one open,
 * one closed); on the accent and photo bands: an `item` accordion. Codes, each `<block> item <n> <detail>`: items (a block missing, the
 * wrong number of items, the wrong item open at rest, an answer shown on a closed item: nothing else is then checked); item-border (width,
 * style and colour of each of the four sides: an underline kit and every rule row draw only the bottom edge); item-radius (the four
 * corners); item-padding (a toggle: the kit's, or a rule row's own literal; an accordion item keeps Divi's, nothing is judged);
 * item-shadow (presence, blur, offsets, colour, on the item element itself; a rule row shows none); item-fill (the computed background
 * of the item in ITS state, and the painted fill under it); item-title (the title colour in its state); item-body (the answer colour of an
 * open item); item-icon (a drawn ::before in the colour of the state; an accordion's open icon is hidden by Divi and not judged);
 * item-contrast, on the MEASURED painted fill, in the state the item is in at rest and again after the click: the title and the answer
 * >= 4.5, a drawn icon >= 3.0, every drawn frame side >= 3.0 unless it is the language's decorative `border` hairline; and item-click,
 * the real interaction: a real Playwright click on the title of an accordion's first closed item (a toggle's only item) must open it
 * (and close a toggle that was open) after Divi's slide (waited for once, then polled until the page stops changing), the previously open
 * accordion item must close, every answer must follow its item, and the item's computed fill, title and icon must switch to the values of
 * its new state (item-fill, item-title, item-icon, reported with `after click`). Overflow also covers every item, at rest and after the
 * click. A cell whose item expectations have a hole stops at the gate before any browser work; a run whose item selection is empty fails.
 *
 * The 2 FORMS (scripts/lib/kit-form-run.cjs, judged by the pure comparisons of kit-checks.cjs): a `divi/contact-form` marked `{"kit":"form"}` on
 * the bg band and one marked `{"kit":"form","on":"surface"}` on the surface band, each with two fields, the basic captcha (Divi's default) and a
 * submit button; found by the unique text of the submit, expectations read from the resolved block tree (the form's `field.*`, `button.*` and
 * `captcha.*` groups), never a table here. Codes, each `<form> <detail>`: form (a form missing, the wrong number of fields, not exactly one submit,
 * no captcha question: nothing else is then checked; a run that checked no form fails with `FAIL form:`); form-field-fill (the computed fill of
 * EVERY field, and the PAINTED fill under it with the placeholder hidden; a field never the colour of the form behind it); form-field-border (width,
 * style and colour of each of the four sides, so an underline kit draws only the bottom edge; no field shadow); form-field-radius (the four
 * corners); form-field-ink (the typed text); form-field-placeholder (`getComputedStyle(field, '::placeholder')`, the field ink does not reach it);
 * form-field-padding; form-button (the submit measured as a kit button: border top and bottom plus the left and right edges, radius, fill, label,
 * capitalization, spacing and weight, padding, shadow and lift, and on a REAL hover the fill, label, edge, shadow and lift; an underline submit
 * keeps its REST bottom edge colour on hover, the kit writes no hover border for it); form-captcha (the question, its row and its label in the
 * band's ink); form-container (the form's own container carries nothing of the kit: no shadow, no movement, no frame, at rest and on hover, because
 * the kit writes no module.decoration on a form); form-contrast (ink and placeholder against the PAINTED field fill >= 4.5, every drawn frame side >= 3.0
 * against the painted field fill (strict), the field's boundary against the form behind it >= 3.0 from the BETTER of its frame and its fill (Ruling 6,
 * WCAG 1.4.11: either edge identifies the control), the submit label and drawn edges against the form behind it, rest and hover, and the
 * captcha ink >= 4.5; the surfaces are screenshots with the element hidden, the admin bar and every fixed or sticky element hidden too); form-clip (a
 * field or the submit cut by a clipping container, or a cut submit label); and overflow also covers every field, the submit and the captcha (inside
 * the form and the window, rest and hover). A cell whose form expectations have a hole, or carry a module.decoration, a field shadow or a field hover,
 * stops at the gate before any browser work (and, if that gate were bypassed, compareForm answers `form-expectation` instead of comparing against a hole).
 *
 * The IMAGE (scripts/lib/kit-media-run.cjs, judged by the pure comparisons of kit-checks.cjs): ONE `divi/image` on the bg band whose radius is the
 * whole-value token `{{aied:kit:radius-media}}` at `image.decoration.border.desktop.value.radius` (no marker), with a built-in pack picture, found by its
 * unique alt text. The expected four corners are read from the RESOLVED markup (a kit with media 0px gives four zeros, which are checked: a square
 * image), never a table here. The page is measured for the img, its wrapper (`.et_pb_image_wrap`, where Divi prints the radius and overflow:hidden) and
 * the module box. Codes, each `<slot> <detail>`: media (the image missing or on the page twice: nothing else is then checked; a run that checked no
 * image fails with `FAIL media:`); media-radius (every element of the image that has a radius must carry the expected one in each corner, and when
 * the kit is not square at least one of the img and its wrapper must carry it; the radius is accepted on whichever the render paints it on); media-clip
 * (a wrapper that carries the radius must clip the picture unless the img inherits it, a rounded img must not be cut square by a smaller clipping wrapper,
 * and an img must not stick out of a non-clipping wrapper); and overflow also covers the picture, its wrapper and the module box (inside the column
 * and the window at 1280, 900 and 400). A cell whose image expectation has a hole stops at the gate before any browser work.
 *
 * A sheet the gate or the
 * resolver refuses FAILs once as `<kit>/<language>/gate`. Problems in the script's own page handling are `render-error`. A preview that
 * comes back as a 404 or without the page content is retried once with a fresh login.
 *
 * Output: PASS/FAIL lines and a summary; NOTE lines with measurements worth a look (an underline's contrast against its own fill, how much
 * a shadow stands out from the surface, which element carries the shadow and its corners; for cards how many carry a shadow and on which
 * element, how far the card stands out from its band, whether the flush image corner is cut by the radius, the column row's layout at 900);
 * dist/kit-sheet/<kit>-<language>-<width>.png (the full page, cards included), dist/kit-sheet/observations.json,
 * dist/kit-sheet/card-observations.json (with the button-panel contrast and the featured card's prominence), dist/kit-sheet/item-observations.json (the painted fills
 * and the click of every item), dist/kit-sheet/form-observations.json (the painted fields, the contrast of every form), dist/kit-sheet/media-observations.json (the computed corners of the img, its wrapper and the module box) and dist/kit-sheet/index.html (one row per kit: editorial and glass-dark at 1280 and 400). dist/
 * is git-ignored; writing the sheet never fails the run.
 *
 * Dev site only (localhost). RUN ONE RUN AT A TIME. Every throwaway page is titled `aied kit <runToken> <kit> <language>`; the run deletes
 * ONLY pages whose title starts with `aied kit <runToken> ` (after every cell, at the end, and on SIGINT/SIGTERM/SIGHUP).
 * `--clean-orphans` deletes ALL pages whose title starts with `aied kit ` and exits: use it only when no other run is going.
 * Pure parts: scripts/test-verify-kits.cjs (run first by `make verify-kits`).
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');

const { devSiteOnly, makeWp, loginSession, endSession, watchConsole, throwawayPages, cleanupOnSignals } = require('./lib/throwaway-pages.cjs');
devSiteOnly(SITE);

const USAGE = 'usage: node scripts/verify-kits.cjs [--kits a,b] [--languages x,y]\n       node scripts/verify-kits.cjs --clean-orphans';
const KITS_FILE = path.join(ROOT, 'wp-plugin', 'data', 'style-kits.json');
const LANGUAGES_FILE = path.join(ROOT, 'wp-plugin', 'data', 'design-languages.json');
const OUT_DIR = path.join(ROOT, 'dist', 'kit-sheet');
const WIDTHS = [1280, 900, 400];
const SHEET_LANGUAGES = ['editorial', 'glass-dark'];
const BS = String.fromCharCode(92);

let optKits = null;
let optLanguages = null;
let cleanOrphans = false;
{
  const args = process.argv.slice(2);
  const list = (v) => (v ? v.split(',').map((s) => s.trim()).filter(Boolean) : []);
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--kits') { optKits = list(args[++i]); if (!optKits.length) { console.error(USAGE); process.exit(2); } }
    else if (args[i] === '--languages') { optLanguages = list(args[++i]); if (!optLanguages.length) { console.error(USAGE); process.exit(2); } }
    else if (args[i] === '--clean-orphans') cleanOrphans = true;
    else { console.error(USAGE); process.exit(2); }
  }
  if (cleanOrphans && (optKits || optLanguages)) { console.error(USAGE); process.exit(2); }
}

const K = require('./lib/kit-checks.cjs');
const { measureInPage, PHP_ERROR, over, ratioOf } = require('./lib/recipe-checks.cjs');
const { checkCards, transitionMs } = require('./lib/kit-card-run.cjs');
const { checkItems } = require('./lib/kit-item-run.cjs');
const { checkForms } = require('./lib/kit-form-run.cjs');
const { checkMedia } = require('./lib/kit-media-run.cjs');

const wp = makeWp(ROOT);
const pages = throwawayPages(wp, K.TITLE_PREFIX, 'kit');
const { runToken: RUN_TOKEN, created, sweep, dropPage, removeOrphans } = pages;
const run = { cleaning: false, browser: null };

if (cleanOrphans) process.exit(removeOrphans() ? 0 : 1);

const { chromium } = require(PW);

/** Resolves the sheet in PHP (the real pipeline), gates it, saves a draft. Returns { id, valid, verdict, markup }. */
function makePage(kit, language) {
  if (run.cleaning) throw new Error('interrupted');
  // Everything travels as one base64 JSON payload, so nothing can break out of the PHP string.
  const b64 = Buffer.from(JSON.stringify({ title: K.runTitle(RUN_TOKEN, kit, language), kit, language, markup: K.sheetMarkup() })).toString('base64');
  const NS = 'AiEditorDivi5' + BS + 'WP' + BS;
  const out = wp('eval', `$p=json_decode(base64_decode('${b64}'),true);$k=${NS}StyleKits::get($p['kit']);$l=${NS}DesignLanguages::get($p['language']);if($k===null||$l===null||[]!==${NS}StyleKits::problems($k)){echo '0|INVALID kit or language unknown or invalid';return;}`
    + `$m=${NS}ImageTokens::resolve($p['markup'],plugins_url('assets/images/',AI_EDITOR_DIVI5_FILE),null,static fn(?string $u,string $t):?string=>apply_filters('jhmg_aied_image_token',$u,$t));`
    + `$m=${NS}RecipeTokens::resolve(${NS}KitResolver::resolve($m,$k),$l);$left=${NS}KitResolver::leftovers($m);if([]!==$left){echo '0|INVALID unresolved '.implode(',',$left);return;}if(str_contains($m,'{{aied:')){echo '0|INVALID unresolved token';return;}`
    + `$c='<!-- wp:divi/placeholder -->'.$m.'<!-- /wp:divi/placeholder -->';$g=${NS}LayoutGate::check($c);if(!$g->isValid()){echo '0|INVALID '.implode(',',array_map(fn($v)=>$v->toArray()['code'],$g->violations()));return;}`
    + `$id=wp_insert_post(wp_slash(['post_type'=>'page','post_title'=>$p['title'],'post_content'=>$c,'post_status'=>'draft','meta_input'=>['_et_pb_use_divi_5'=>'on','_et_pb_use_builder'=>'on']]));echo $id.'|valid|'.base64_encode($m);`);
  const [id, verdict, m64] = out.split('|');
  if (/^\d+$/.test(id) && id !== '0') created.push(id);
  return { id, valid: verdict === 'valid', verdict, markup: m64 ? Buffer.from(m64, 'base64').toString('utf8') : '' };
}

// ---- data ------------------------------------------------------------------------------------------------------------
const kitIds = JSON.parse(fs.readFileSync(KITS_FILE, 'utf8')).map((k) => k.id);
const languageData = JSON.parse(fs.readFileSync(LANGUAGES_FILE, 'utf8'));
const languages = languageData.map((l) => ({ id: l.id, kit: l.kit }));
/** The decorative frame colour of each design language (its `border` role): a hairline frame is not judged for contrast. */
const hairlines = Object.fromEntries(languageData.map((l) => [l.id, (l.colors || {}).border || null]));

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/** Writes dist/kit-sheet/index.html. Never throws. */
function writeSheet(status, cells) {
  try {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    const cell = (kit, lang, width) => {
      const file = `${kit}-${lang}-${width}.png`;
      const st = status.get(kit + '/' + lang);
      const badge = st ? `<span class="b ${st.toLowerCase()}">${esc(st)}</span>` : '';
      return fs.existsSync(path.join(OUT_DIR, file))
        ? `<td class="w${width}">${badge}<a href="${esc(file)}"><img loading="lazy" src="${esc(file)}" alt="${esc(kit)} ${esc(lang)} ${width}px"></a></td>`
        : `<td class="w${width}">${badge}<em>not rendered</em></td>`;
    };
    const rows = kitIds.filter((k) => cells.some(([ck]) => ck === k)).map((k) => `<tr><th><strong>${esc(k)}</strong>${languages.filter((l) => l.kit === k).map((l) => `<br><small>default kit of ${esc(l.id)}</small>`).join('')}</th>`
      + SHEET_LANGUAGES.map((l) => cell(k, l, 1280) + cell(k, l, 400)).join('') + '</tr>').join('\n');
    const own = languages.filter((l) => !SHEET_LANGUAGES.includes(l.id) && cells.some(([ck, cl]) => ck === l.kit && cl === l.id))
      .map((l) => `<tr><th><strong>${esc(l.id)}</strong><br><small>kit ${esc(l.kit)}</small></th>${cell(l.kit, l.id, 1280)}${cell(l.kit, l.id, 400)}</tr>`).join('\n');
    const html = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kit sheet</title>
<style>
body{font:14px/1.4 system-ui,sans-serif;margin:16px;background:#f4f4f4;color:#111}
h1{font-size:20px}h2{font-size:16px;margin-top:2em}p{max-width:70em}
.wrap{overflow-x:auto}
table{border-collapse:collapse}
th,td{border:1px solid #ccc;padding:8px;vertical-align:top;background:#fff}
th{text-align:left;min-width:12em;position:sticky;left:0}
td img{display:block;max-width:100%;height:auto}
td.w1280{width:420px;min-width:420px}td.w400{width:150px;min-width:150px}
.b{display:inline-block;font:700 11px sans-serif;padding:1px 6px;border-radius:8px;margin-bottom:4px;color:#fff}
.b.pass{background:#1a7f37}.b.fail{background:#b3261e}
</style></head><body>
<h1>Kit sheet</h1>
<p>Generated by <code>make verify-kits</code> (${esc(new Date().toISOString())}). Each sheet is a primary and a secondary button, a card and a panel on a bg section, a surface section, an accent band and a photo band, inverted and accent grounds and a panel with kit buttons on the two light bands, plus a row of three column cards with an accent middle one, rendered on the real Divi at desktop (1280 px) and phone (400 px); a 900 px screenshot of each is in the same folder, and <code>observations.json</code> has the measurements. A cell says &quot;not rendered&quot; when this folder has no image for it.</p>
<div class="wrap"><table>
<thead><tr><th>Kit</th><th colspan="2">editorial</th><th colspan="2">glass-dark</th></tr></thead>
<tbody>
${rows}
</tbody></table></div>
<h2>Every other design language under its own kit</h2>
<div class="wrap"><table>
<thead><tr><th>Language</th><th>1280 px</th><th>400 px</th></tr></thead>
<tbody>
${own}
</tbody></table></div>
</body></html>
`;
    fs.writeFileSync(path.join(OUT_DIR, 'index.html'), html);
    console.log('kit sheet: ' + path.relative(ROOT, path.join(OUT_DIR, 'index.html')));
  } catch (e) {
    console.log('WARNING could not write the kit sheet: ' + (e && e.message ? e.message : e));
  }
}

/** A fresh login for the run; the previous session token is destroyed first. */
function renewSession(state) {
  if (state.token) endSession(wp, state.token);
  const s = loginSession(wp);
  state.cookies = s.cookies;
  state.token = s.token;
}

/** Opens the preview, logged in; one retry with a fresh login when it is a 404 or has no page content. Returns { p, consoleErrors, html }. */
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
  const cardObservations = [];
  const itemObservations = [];
  const formObservations = [];
  const mediaObservations = [];
  let cardsChecked = 0;
  let itemsChecked = 0;
  let formsChecked = 0;
  let mediaChecked = 0;
  const state = { cookies: null, token: null };
  cleanupOnSignals(run, sweep, pages.runPrefix);
  let cells = [];
  try {
    try { cells = K.selectCells({ kits: optKits, languages: optLanguages }, kitIds, languages, SHEET_LANGUAGES); } catch (e) {
      // Unknown ids are a usage error (2); an empty selection would check nothing, which is a failure (1), never a silent pass.
      if (e.empty) { console.log('FAIL ' + e.message + '\nFAILED'); process.exit(1); }
      console.error(e.message); process.exit(2);
    }
    const foreign = pages.foreignCount();
    if (foreign > 0) console.log(`WARNING ${foreign} leftover or concurrent kit pages (not deleted; run with --clean-orphans when no other run is going)`);
    fs.mkdirSync(OUT_DIR, { recursive: true });
    browser = await chromium.launch({ handleSIGINT: false, handleSIGTERM: false, handleSIGHUP: false });
    run.browser = browser;
    console.log(`${cells.length} kit/language cell(s) x ${WIDTHS.length} widths, run ${RUN_TOKEN}`);

    const report = (kit, lang, width, problems) => {
      if (run.cleaning) return;
      const k = kit + '/' + lang;
      if (!problems.length) { passes++; console.log(`PASS ${k}/${width} ok`); if (!status.has(k)) status.set(k, 'PASS'); return; }
      failures++;
      status.set(k, 'FAIL');
      for (const [code, detail] of problems) console.log(`FAIL ${k}/${width} ${code}: ${detail}`);
    };

    for (const [kit, lang] of cells) {
      if (run.cleaning) break;
      let page = null;
      try {
        renewSession(state); // a fresh session per cell (a long run outlives one cookie); the previous one is destroyed
        page = makePage(kit, lang);
        if (!page.valid) { report(kit, lang, 'gate', [['gate', 'not saved: ' + page.verdict]]); continue; }
        const expects = K.pageButtonAttrs(page.markup).map(K.expectFromAttrs);
        if (expects.length !== K.SLOTS.length) { report(kit, lang, 'gate', [['gate', `the resolved sheet has ${expects.length} buttons, expected ${K.SLOTS.length}`]]); continue; }
        const missing = expects.flatMap((e, i) => K.missingExpectations(e).map((f) => `${K.SLOTS[i].surface}/${K.SLOTS[i].role} ${f}`));
        if (missing.length) { report(kit, lang, 'gate', [['gate', 'expectation missing (an adapter attribute path drifted): ' + missing.slice(0, 6).join(', ') + (missing.length > 6 ? ` and ${missing.length - 6} more` : '')]]); continue; }
        const cardBlocks = K.cardAttrs(page.markup);
        const absent = cardBlocks.filter((c) => !c.attrs).map((c) => c.slot.key);
        if (absent.length) { report(kit, lang, 'gate', [['gate', 'cards not found in the resolved sheet (by their heading): ' + absent.join(', ')]]); continue; }
        const cardExpects = cardBlocks.map((c) => K.expectCardFromAttrs(c.attrs));
        const cardMissing = cardExpects.flatMap((e, i) => K.missingCardExpectations(e, cardBlocks[i].slot.role, cardBlocks[i].slot.ground).map((f) => `${cardBlocks[i].slot.key} ${f}`));
        if (cardMissing.length) { report(kit, lang, 'gate', [['gate', 'card expectation missing (an adapter attribute path drifted): ' + cardMissing.slice(0, 6).join(', ') + (cardMissing.length > 6 ? ` and ${cardMissing.length - 6} more` : '')]]); continue; }
        // The kit buttons inside the button panels: the resolved sheet must hold exactly the ones the slot says, with complete expectations.
        const buttonExpects = cardBlocks.map((c) => c.buttons.map(K.expectFromAttrs));
        const wrongButtons = cardBlocks.filter((c, i) => buttonExpects[i].length !== (c.slot.buttons || 0)).map((c) => `${c.slot.key} holds ${c.buttons.length} buttons, expected ${c.slot.buttons || 0}`);
        if (wrongButtons.length) { report(kit, lang, 'gate', [['gate', 'button panels not as the sheet says: ' + wrongButtons.join(', ')]]); continue; }
        const buttonMissing = buttonExpects.flatMap((es, i) => es.flatMap((e, j) => K.missingExpectations(e).map((f) => `${cardBlocks[i].slot.key}:${j} ${f}`)));
        if (buttonMissing.length) { report(kit, lang, 'gate', [['gate', 'panel button expectation missing (an adapter attribute path drifted): ' + buttonMissing.slice(0, 6).join(', ') + (buttonMissing.length > 6 ? ` and ${buttonMissing.length - 6} more` : '')]]); continue; }
        // The item blocks (accordions and toggles): found in the resolved sheet by their first title, with complete expectations and exactly the items the sheet gives them.
        const itemBlocks = K.itemAttrs(page.markup);
        const itemAbsent = itemBlocks.filter((b) => !b.attrs).map((b) => b.slot.key);
        if (itemAbsent.length) { report(kit, lang, 'gate', [['gate', 'item blocks not found in the resolved sheet (by their first title): ' + itemAbsent.join(', ')]]); continue; }
        const itemExpects = itemBlocks.map((b) => K.expectItemFromAttrs(b.attrs, b.slot.carrier));
        const itemMissing = itemExpects.flatMap((e, i) => K.missingItemExpectations(e, itemBlocks[i].slot.role).map((f) => `${itemBlocks[i].slot.key} ${f}`));
        if (itemMissing.length) { report(kit, lang, 'gate', [['gate', 'item expectation missing (an adapter attribute path drifted): ' + itemMissing.slice(0, 6).join(', ') + (itemMissing.length > 6 ? ` and ${itemMissing.length - 6} more` : '')]]); continue; }
        const itemLists = itemBlocks.map((b) => b.items);
        // The contact forms: found in the resolved sheet by the unique text of their submit button, with complete expectations (a hole, a module.decoration the
        // kit must not write, a field shadow or hover are gate failures) and exactly the fields the sheet gives them.
        const formBlocks = K.formAttrs(page.markup);
        const formAbsent = formBlocks.filter((b) => !b.attrs).map((b) => b.slot.key);
        if (formAbsent.length) { report(kit, lang, 'gate', [['gate', 'forms not found in the resolved sheet (by their submit text): ' + formAbsent.join(', ')]]); continue; }
        const formExpects = formBlocks.map((b) => K.expectFormFromAttrs(b.attrs));
        const formMissing = formExpects.flatMap((e, i) => K.missingFormExpectations(e).map((f) => `${formBlocks[i].slot.key} ${f}`));
        if (formMissing.length) { report(kit, lang, 'gate', [['gate', 'form expectation missing (an adapter attribute path drifted): ' + formMissing.slice(0, 6).join(', ') + (formMissing.length > 6 ? ` and ${formMissing.length - 6} more` : '')]]); continue; }
        const formCounts = formBlocks.map((b) => b.fields);
        // The image: found in the resolved sheet by its unique alt text, with a complete expectation (the radius token must have resolved to four corners).
        const mediaBlocks = K.mediaAttrs(page.markup);
        const mediaAbsent = mediaBlocks.filter((b) => !b.attrs).map((b) => b.slot.key);
        if (mediaAbsent.length) { report(kit, lang, 'gate', [['gate', 'images not found in the resolved sheet (by their alt text): ' + mediaAbsent.join(', ')]]); continue; }
        const mediaExpects = mediaBlocks.map((b) => K.expectMediaFromAttrs(b.attrs));
        const mediaMissing = mediaExpects.flatMap((e, i) => K.missingMediaExpectations(e).map((f) => `${mediaBlocks[i].slot.key} ${f}`));
        if (mediaMissing.length) { report(kit, lang, 'gate', [['gate', 'image expectation missing (the radius token did not resolve to four corners): ' + mediaMissing.join(', ')]]); continue; }
        for (const width of WIDTHS) {
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
            try { await p.screenshot({ path: path.join(OUT_DIR, `${kit}-${lang}-${width}.png`), fullPage: true }); } catch (e) { console.log(`WARNING screenshot failed for ${kit}/${lang}/${width}`); }
            const rest = await p.evaluate(K.measureButtons);
            if (rest.buttons.length !== K.SLOTS.length) {
              problems.push(['buttons', `${rest.buttons.length} buttons rendered, expected ${K.SLOTS.length}`]);
            } else {
              // The surface under each button: a screenshot of its box with every button hidden (no transition, so it is hidden at once).
              await p.evaluate(() => document.querySelectorAll('.et-l--post .et_pb_button_module_wrapper, .et-l--post a.et_pb_button').forEach((n) => { n.dataset.aiedT = n.style.transition; n.style.transition = 'none'; n.dataset.aiedV = n.style.visibility; n.style.visibility = 'hidden'; }));
              await p.waitForTimeout(50);
              const surfaces = [];
              for (const b of rest.buttons) {
                const clip = { x: Math.max(0, Math.round(b.rect.left)), y: Math.max(0, Math.round(b.rect.top)), width: Math.max(1, Math.round(b.rect.width)), height: Math.max(1, Math.round(b.rect.height)) };
                surfaces.push(K.pixelStats(K.decodePng(await p.screenshot({ clip, fullPage: true }))));
              }
              await p.evaluate(() => document.querySelectorAll('.et-l--post .et_pb_button_module_wrapper, .et-l--post a.et_pb_button').forEach((n) => { n.style.visibility = n.dataset.aiedV || ''; n.style.transition = n.dataset.aiedT || ''; delete n.dataset.aiedV; delete n.dataset.aiedT; }));
              await p.waitForTimeout(50);
              for (let i = 0; i < rest.buttons.length; i++) {
                const slot = K.SLOTS[i];
                const where = `${slot.surface}/${slot.role}`;
                const b = rest.buttons[i];
                const wait = Math.min(2000, Math.max(transitionMs(b.el), transitionMs(b.wrap))) + 150;
                await p.locator(`[data-aied-btn="${i}"]`).first().hover();
                await p.waitForTimeout(wait);
                const hovAll = await p.evaluate(K.measureButtons);
                const hov = hovAll.buttons[i];
                await p.mouse.move(Math.max(0, width - 2), 450); // parked at the right edge: (0,0) would open the admin bar's menu
                await p.waitForTimeout(wait);
                const cmp = K.compareButton(expects[i], { rest: { el: b.el, wrap: b.wrap }, hover: { el: hov.el, wrap: hov.wrap } });
                const surface = surfaces[i].mean;
                const cr = K.contrastProblems(b.el, surface, 'rest');
                const ch = K.contrastProblems(hov.el, surface, 'hover');
                const ov = [...K.overflowProblems(b, 'rest', rest), ...K.overflowProblems(hov, 'hover', hovAll)];
                for (const [code, detail] of [...cmp.problems, ...cr.problems, ...ch.problems, ...ov]) problems.push([code, `${where} ${detail}`]);
                // Measurements for the report (never a verdict).
                const sw = cmp.shadowWhere;
                const shState = sw.rest ? 'rest' : sw.hover ? 'hover' : null;
                const shNode = shState === null ? null : (shState === 'rest' ? { el: b.el, wrap: b.wrap } : { el: hov.el, wrap: hov.wrap })[sw[shState] === 'button' ? 'el' : 'wrap'];
                const sh = shNode ? K.parseShadows(shNode.boxShadow)[0] : null;
                let shadowVsSurface = null;
                if (sh && sh.color) { const base = { ...surface, a: 1 }; shadowVsSurface = K.round2(ratioOf(over(K.colour(sh.color), base), base)); }
                observations.push({ kit, language: lang, width, slot: where, surface: K.hex(surface), surfaceLightest: K.hex(surfaces[i].lightest), surfaceDarkest: K.hex(surfaces[i].darkest), rest: cr.info, hover: ch.info,
                  shadow: sh ? { state: shState, on: sw[shState], value: shNode.boxShadow, shadowVsSurface, radius: shNode.borderTopLeftRadius, fill: b.el.backgroundColor } : null,
                  underline: expects[i].rest.top.width === 0 && expects[i].rest.bottom.width > 0 ? { bottomOnFill: cr.info.bottomOnFill, bottomOnSurface: cr.info.bottom } : null,
                  lift: expects[i].lift !== null ? { button: K.translateY(hov.el.transform), wrapper: K.translateY(hov.wrap.transform) } : null });
              }
            }
            if (m.hasRoot) {
              const cr = await checkCards(p, { width, expects: cardExpects, buttonExpects, hairline: hairlines[lang] });
              cardsChecked += cr.cardsChecked;
              for (const pr of cr.problems) problems.push(pr);
              for (const o of cr.observations) cardObservations.push({ kit, language: lang, columns: cr.layout, ...o });
            }
            if (m.hasRoot) {
              const ir = await checkItems(p, { width, expects: itemExpects, lists: itemLists, hairline: hairlines[lang] });
              itemsChecked += ir.itemsChecked;
              for (const pr of ir.problems) problems.push(pr);
              for (const o of ir.observations) itemObservations.push({ kit, language: lang, ...o });
            }
            if (m.hasRoot) {
              const fr = await checkForms(p, { width, expects: formExpects, counts: formCounts });
              formsChecked += fr.formsChecked;
              for (const pr of fr.problems) problems.push(pr);
              for (const o of fr.observations) formObservations.push({ kit, language: lang, ...o });
            }
            if (m.hasRoot) {
              const mr = await checkMedia(p, { width, expects: mediaExpects });
              mediaChecked += mr.mediaChecked;
              for (const pr of mr.problems) problems.push(pr);
              for (const o of mr.observations) mediaObservations.push({ kit, language: lang, ...o });
            }
            if (consoleErrors.length) problems.push(['console-error', [...new Set(consoleErrors)].slice(0, 3).join(' | ')]);
            await p.close();
          } catch (e) {
            problems.push(['render-error', (e && e.message ? e.message : String(e)).split('\n')[0]]);
          } finally {
            await ctx.close();
          }
          report(kit, lang, width, problems);
        }
      } catch (e) {
        report(kit, lang, 'prepare', [['render-error', 'could not prepare the page: ' + (e && e.message ? e.message : e)]]);
      } finally {
        if (page && /^\d+$/.test(page.id) && page.id !== '0') dropPage(page.id);
      }
    }
    if (run.cleaning) return;
    try { fs.writeFileSync(path.join(OUT_DIR, 'observations.json'), JSON.stringify(observations, null, 1)); } catch (e) { console.log('WARNING could not write observations.json'); }
    try { fs.writeFileSync(path.join(OUT_DIR, 'card-observations.json'), JSON.stringify(cardObservations, null, 1)); } catch (e) { console.log('WARNING could not write card-observations.json'); }
    try { fs.writeFileSync(path.join(OUT_DIR, 'item-observations.json'), JSON.stringify(itemObservations, null, 1)); } catch (e) { console.log('WARNING could not write item-observations.json'); }
    try { fs.writeFileSync(path.join(OUT_DIR, 'form-observations.json'), JSON.stringify(formObservations, null, 1)); } catch (e) { console.log('WARNING could not write form-observations.json'); }
    try { fs.writeFileSync(path.join(OUT_DIR, 'media-observations.json'), JSON.stringify(mediaObservations, null, 1)); } catch (e) { console.log('WARNING could not write media-observations.json'); }
    printNotes(observations);
    printCardNotes(cardObservations);
    printItemNotes(itemObservations);
    printFormNotes(formObservations);
    printMediaNotes(mediaObservations);
    writeSheet(status, cells);
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
  for (const why of K.emptyRunProblems({ checked: passes + failures, cardsChecked, itemsChecked })) { failures++; console.log('FAIL ' + why); }
  for (const why of K.emptyFormRunProblems({ formsChecked })) { failures++; console.log('FAIL form: ' + why); }
  for (const why of K.emptyMediaRunProblems({ mediaChecked })) { failures++; console.log('FAIL media: ' + why); }
  console.log(`\n${passes} PASS, ${failures} FAIL (cell/width lines) over ${cells.length} kit/language cell(s)`);
  console.log(failures ? 'FAILED' : 'Every selected kit renders as it says on the real Divi.');
  process.exit(failures ? 1 : 0);
})();

/** NOTE lines at 1280 px, one per kit/language: the underline's weakest contrast against its own fill, and the shadow (which element carries it, its corners, how far its colour stands out from the surface). */
function printNotes(obs) {
  const groups = new Map();
  for (const o of obs.filter((x) => x.width === 1280)) { const k = o.kit + '/' + o.language; if (!groups.has(k)) groups.set(k, []); groups.get(k).push(o); }
  const range = (v) => { const n = v.filter((x) => typeof x === 'number'); return n.length ? `${Math.min(...n)}..${Math.max(...n)}` : 'n/a'; };
  for (const [k, list] of groups) {
    const ul = list.filter((o) => o.underline);
    if (ul.length) console.log(`NOTE underline ${k}: bottom edge vs its own fill ${range(ul.map((o) => o.underline.bottomOnFill))}:1 (weakest ${ul.reduce((a, o) => (o.underline.bottomOnFill < a.underline.bottomOnFill ? o : a)).slot})`);
    const sh = list.filter((o) => o.shadow);
    if (sh.length) console.log(`NOTE shadow ${k}: ${[...new Set(sh.map((o) => o.shadow.state + ' on the ' + o.shadow.on + ' (radius ' + o.shadow.radius + ')'))].join(', ')}; shadow colour vs surface ${range(sh.map((o) => o.shadow.shadowVsSurface))}:1; e.g. "${sh[0].shadow.value}"`);
  }
}

/** NOTE lines for the cards, one set per kit/language: shadow carriers, how far a card stands out from its band, the flush-image clip, and the column row's layout at 900 px. */
function printCardNotes(obs) {
  const groups = new Map();
  for (const o of obs) { const k = o.kit + '/' + o.language; if (!groups.has(k)) groups.set(k, []); groups.get(k).push(o); }
  const range = (v) => { const n = v.filter((x) => typeof x === 'number'); return n.length ? `${Math.min(...n)}..${Math.max(...n)}` : 'n/a'; };
  for (const [k, list] of groups) {
    const wide = list.filter((o) => o.width === 1280);
    const sh = wide.filter((o) => o.shadow);
    if (sh.length) console.log(`NOTE card-shadow ${k}: ${sh.length} of ${wide.length} cards carry a shadow, on the card element itself (${[...new Set(sh.map((o) => o.shadow.state))].join(' and ')}); e.g. "${sh[0].shadow.value}"${wide.some((o) => o.parentShadow) ? '; a parent also carries one: ' + wide.find((o) => o.parentShadow).parentShadow : ''}`);
    const so = wide.filter((o) => typeof o.standOut === 'number');
    if (so.length) console.log(`NOTE card-standout ${k}: the card fill vs the band just above it ${range(so.map((o) => o.standOut))}:1 (weakest ${so.reduce((a, o) => (o.standOut < a.standOut ? o : a)).key})`);
    const clip = wide.find((o) => o.key === 'panel-clip');
    if (clip) console.log(`NOTE card-clip ${k}: the flush image corner ${clip.clip.flush ? (clip.clip.clipped ? 'is cut by the radius' : 'touches the card but the radius does not cut it') : 'does NOT touch the card corner'} (radius ${clip.radius})`);
    const grounds = wide.filter((o) => o.ground);
    if (grounds.length) console.log(`NOTE card-grounds ${k}: ${grounds.length} grounds (${grounds.map((o) => o.key + ' ' + o.fill).join(', ')})`);
    const pbs = wide.filter((o) => o.buttons);
    for (const o of pbs) console.log(`NOTE panel-buttons ${k} ${o.key}: label on its fill ${o.buttons.map((b) => (b.contrast.rest || {}).label + '/' + (b.contrast.hover || {}).label).join(' and ')} (rest/hover)`);
    for (const w of [1280, 900, 400]) {
      const f = list.find((o) => o.width === w && o.featured);
      if (f) console.log(`NOTE featured ${k} @${w}: prominence ${f.featured.featured === null ? 'n/a' : f.featured.featured.toFixed(3)} vs neighbours ${f.featured.neighbours.map((x) => (x === null ? 'n/a' : x.toFixed(3))).join(', ')} (ratio ${f.featured.ratio}, needs ${K.FEATURED_RATIO})`);
    }
    const cols = list.find((o) => o.width === 900 && o.columns);
    if (cols) console.log(`NOTE card-columns ${k}: the three column cards are ${cols.columns} at 900px`);
  }
}

/** NOTE lines for the items, one set per kit/language at 1280 px: which blocks open and close on the click and how readable the titles are on their painted fills. */
function printItemNotes(obs) {
  const groups = new Map();
  for (const o of obs.filter((x) => x.width === 1280)) { const k = o.kit + '/' + o.language; if (!groups.has(k)) groups.set(k, []); groups.get(k).push(o); }
  const range = (v) => { const n = v.filter((x) => typeof x === 'number'); return n.length ? `${Math.min(...n)}..${Math.max(...n)}` : 'n/a'; };
  for (const [k, list] of groups) {
    const clicked = list.filter((o) => o.click && o.click.after);
    const titles = list.flatMap((o) => o.rest.map((i) => i.contrast && i.contrast.title));
    const frames = list.flatMap((o) => o.rest.flatMap((i) => ['top', 'right', 'bottom', 'left'].map((s) => i.contrast && i.contrast[s])));
    console.log(`NOTE items ${k}: ${clicked.length} of ${list.length} blocks changed state on a real click; title on its painted fill ${range(titles)}:1; drawn frame sides ${range(frames)}:1`);
  }
}

/** NOTE lines for the forms, one per kit/language at 1280 px: how readable the fields' ink, placeholder and frame are on their painted fills, the submit label, and the captcha. */
function printFormNotes(obs) {
  const groups = new Map();
  for (const o of obs.filter((x) => x.width === 1280)) { const k = o.kit + '/' + o.language; if (!groups.has(k)) groups.set(k, []); groups.get(k).push(o); }
  const range = (v) => { const n = v.filter((x) => typeof x === 'number'); return n.length ? `${Math.min(...n)}..${Math.max(...n)}` : 'n/a'; };
  for (const [k, list] of groups) {
    const fields = list.flatMap((o) => o.contrast.fields || []);
    const frames = fields.flatMap((f) => ['top', 'right', 'bottom', 'left'].flatMap((s) => [f[s], f[s + 'OnForm']]));
    console.log(`NOTE forms ${k}: ${list.length} forms; field ink ${range(fields.map((f) => f.ink))}:1, placeholder ${range(fields.map((f) => f.placeholder))}:1, drawn frame sides ${range(frames)}:1; submit label ${range(list.map((o) => o.contrast.submit && o.contrast.submit.rest && o.contrast.submit.rest.label))}:1 at rest; captcha ${range(list.map((o) => o.contrast.captcha))}:1`);
  }
}

/** NOTE lines for the image, one per kit/language at 1280 px: the expected corner, what the img and its wrapper computed, the wrapper's overflow and whether the picture loaded. */
function printMediaNotes(obs) {
  for (const o of obs.filter((x) => x.width === 1280)) {
    console.log(`NOTE media ${o.kit}/${o.language}: expected ${o.expected[0]}px; img ${o.img ? o.img[0] : 'n/a'}, wrapper ${o.wrapper ? o.wrapper[0] : 'none'} (overflow ${o.wrapperOverflow || 'n/a'}), module ${o.module ? o.module[0] : 'none'}; picture ${o.box ? o.box.join('x') : 'n/a'} ${o.loaded ? 'loaded' : 'NOT loaded'}`);
  }
}
