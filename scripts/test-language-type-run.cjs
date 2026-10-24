#!/usr/bin/env node
'use strict';
/* Live proof (a real Chromium, SYNTHETIC pages, NOT the dev site) of the page half of scripts/verify-language-type.cjs: scripts/lib/language-type-run.cjs
 * measuring a page (computed weight, letter spacing, text-transform and family, the box and its column, the document's font faces after
 * document.fonts.ready) and judging it with scripts/lib/language-type-checks.cjs.
 * The pages carry the class names Divi 5.15.1 renders (`et-l--post` > `et_pb_section` > `et_pb_row` > `et_pb_column` > `et_pb_module et_pb_heading` >
 * `.et_pb_heading_container` > `h1`..`h4`; `et_pb_module et_pb_text` > `.et_pb_text_inner` > `p`) and a stylesheet GENERATED from the expectations the
 * real PHP pipeline gives for editorial and brutalist-flat (SectionRecipes::resolveMarkup through scripts/lib/language-type-sheet.php), so a page that
 * agrees with its language passes at 1280, 900 and 400 px, and each deliberate departure is caught with the right code: a wrong weight, a wrong
 * tracking, a missing uppercase, an extra uppercase, a wrong family, a slot missing, a slot twice, a heading wider than its column at 400 px, a page
 * wider than the window, and a page whose document.fonts has no loaded face for the family (type-font-loaded). A real loaded web font cannot be proven
 * offline, so the agreeing page gets a loaded FontFace from a local system font file (when this machine has one; otherwise the only problem allowed on
 * the agreeing page is type-font-loaded) and the positive case of the face rule is also the pure test with recorded face lists
 * (scripts/test-verify-language-type.cjs) and the live run.
 * Needs php (the offline sheet) and Playwright (../layoutlab or PLAYWRIGHT_PATH).
 * It cannot prove what Divi itself prints: that is `node scripts/verify-language-type.cjs`. */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const T = require('./lib/language-type-checks.cjs');
const { checkType } = require('./lib/language-type-run.cjs');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(__dirname, '..', '..', 'layoutlab', 'node_modules', 'playwright');
const { chromium } = require(PW);

const offline = (language) => {
  const r = spawnSync('php', [path.join(__dirname, 'lib', 'language-type-sheet.php'), '--language', language], { input: T.sheetMarkup(), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  assert.strictEqual(r.status, 0, 'php failed: ' + (r.error ? r.error.message : r.stderr));
  const row = JSON.parse(r.stdout)[0];
  assert.deepStrictEqual(row.leftovers, [], language + ' resolves');
  return T.pageTypeAttrs(row.markup).map((b) => T.expectTypeFromAttrs(b.attrs, b.slot));
};
const LANGS = { editorial: offline('editorial'), 'brutalist-flat': offline('brutalist-flat') };
for (const [id, exps] of Object.entries(LANGS)) for (const e of exps) assert.deepStrictEqual(T.missingTypeExpectations(e), [], id + ' expectations are complete');
// editorial: headings with no capitals and an uppercase eyebrow; brutalist-flat: uppercase display. Both directions of caps are exercised.
assert.strictEqual(LANGS.editorial[0].caps, 'none');
assert.strictEqual(LANGS.editorial[3].caps, 'uppercase');
assert.strictEqual(LANGS['brutalist-flat'][0].caps, 'uppercase');

/** A font file on this machine to stand in for a web font (its family name in the page is the language's own). Null when there is none. */
const FONT_FILE = [
  '/System/Library/Fonts/Supplemental/Verdana.ttf', '/System/Library/Fonts/Supplemental/Arial.ttf', '/System/Library/Fonts/Geneva.ttf',
  '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/dejavu/DejaVuSans.ttf', '/usr/share/fonts/TTF/DejaVuSans.ttf',
].find((f) => fs.existsSync(f)) || null;
const FONT_B64 = FONT_FILE ? fs.readFileSync(FONT_FILE).toString('base64') : null;
if (!FONT_FILE) console.log('NOTE no local font file: the agreeing page is allowed exactly type-font-loaded');

const qf = (name) => `"${name}"`;
/** The synthetic page of one language: Divi's class names and a stylesheet generated from the expectations. */
function pageHtml(exps, { extraCss = '', drop = null, dup = null, faces = true, wide = false } = {}) {
  const families = [...new Set(exps.map((e) => e.family))];
  const fontCss = faces && FONT_B64 ? families.map((f) => `@font-face{font-family:${qf(f)};src:url(data:font/ttf;base64,${FONT_B64}) format("truetype");font-weight:100 900;font-style:normal}`).join('') : '';
  const rules = T.TYPE_SLOTS.map((s, i) => {
    const e = exps[i];
    const sel = `[data-k="${s.key}"] ${s.tag}`;
    const base = `${sel}{margin:0;padding:0;font-family:${qf(e.family)},serif;font-weight:${e.weight};letter-spacing:${e.tracking}px;text-transform:${e.caps};font-size:${s.sizes.desktop};line-height:${s.lineHeight};overflow-wrap:break-word}`;
    const tablet = s.sizes.tablet ? `@media (max-width:980px){${sel}{font-size:${s.sizes.tablet}}}` : '';
    const phone = s.sizes.phone ? `@media (max-width:767px){${sel}{font-size:${s.sizes.phone}}}` : '';
    return base + tablet + phone;
  }).join('');
  const slotHtml = (s) => (s.module === 'text'
    ? `<div class="et_pb_module et_pb_text" data-k="${s.key}"><div class="et_pb_text_inner"><${s.tag}>${s.text}</${s.tag}></div></div>`
    : `<div class="et_pb_module et_pb_heading" data-k="${s.key}"><div class="et_pb_heading_container"><${s.tag} class="et_pb_module_heading">${s.text}</${s.tag}></div></div>`);
  const blocks = T.TYPE_SLOTS.filter((s) => s.key !== drop).map((s) => (s.inGroup ? `<div class="et_pb_group" style="padding:24px">${slotHtml(s)}</div>` : slotHtml(s)));
  if (dup) blocks.push(slotHtml(T.TYPE_SLOTS.find((s) => s.key === dup)));
  if (wide) blocks.push('<div class="decoy" style="width:900px;height:10px;background:#ccc"></div>');
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>
body{margin:0;background:#faf7f2;color:#111;font-family:serif}.et_pb_section{padding:32px 0}.et_pb_row{width:90%;margin:0 auto}.et_pb_column{display:block}
${fontCss}${rules}${extraCss}</style></head><body><div class="et-l--post"><div class="et_pb_section"><div class="et_pb_row"><div class="et_pb_column">${blocks.join('\n')}</div></div></div></div></body></html>`;
}

const codesOf = (r) => [...new Set(r.problems.map(([c]) => c))];

(async () => {
  const browser = await chromium.launch();
  let n = 0;
  try {
    const run = async (html, { width = 1280, exps = LANGS.editorial } = {}) => {
      const ctx = await browser.newContext({ viewport: { width, height: 900 } });
      try {
        const p = await ctx.newPage();
        await p.setContent(html, { waitUntil: 'load' });
        return await checkType(p, { width, expects: exps });
      } finally { await ctx.close(); }
    };
    /** Problems of the agreeing page: none, or (without a local font file) only type-font-loaded. */
    const clean = (r, what) => {
      const rest = FONT_B64 ? r.problems : r.problems.filter(([c]) => c !== 'type-font-loaded');
      assert.deepStrictEqual(rest, [], `${what}: ${JSON.stringify(r.problems)}`);
    };

    // ---- the agreeing pages pass at every width, for a language with plain headings and one with uppercase display ----
    for (const [id, exps] of Object.entries(LANGS)) {
      for (const width of T.WIDTHS) {
        const ok = await run(pageHtml(exps), { width, exps });
        clean(ok, `${id}/${width}`);
        assert.strictEqual(ok.typeChecked, T.TYPE_SLOTS.length, `${id}/${width}: every slot counts as checked`);
        assert.strictEqual(ok.observations.length, T.TYPE_SLOTS.length);
        n++;
      }
    }
    const obs = (await run(pageHtml(LANGS['brutalist-flat']), { exps: LANGS['brutalist-flat'] })).observations;
    assert.strictEqual(obs[0].got.textTransform, 'uppercase');
    assert.strictEqual(obs[0].got.fontWeight, '900');
    assert.strictEqual(obs[0].got.family, 'Archivo');
    assert.deepStrictEqual(obs[0].expected, LANGS['brutalist-flat'][0]);
    n++;
    // The agreeing page really did produce a loaded FontFace (what type-font-loaded judges), when a local font file exists.
    if (FONT_B64) {
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
      const p = await ctx.newPage();
      await p.setContent(pageHtml(LANGS.editorial), { waitUntil: 'load' });
      const m = await p.evaluate(T.measureType, T.TYPE_SLOTS.map((s) => ({ key: s.key, module: s.module, tag: s.tag, text: s.text })));
      await ctx.close();
      const mine = m.faces.filter((f) => f.family.replace(/["']/g, '') === 'Playfair Display');
      assert.ok(mine.length === 1 && mine[0].status === 'loaded' && mine[0].weight === '100 900', 'a loaded Playfair Display 100 900 face: ' + JSON.stringify(m.faces));
      n++;
    }

    // ---- one departure per code ----
    const S = (key) => `[data-k="${key}"]`;
    // type-weight
    let r = await run(pageHtml(LANGS.editorial, { extraCss: `${S('title-h3')} h3{font-weight:500 !important}` }));
    assert.deepStrictEqual(codesOf(r), ['type-weight'], JSON.stringify(r.problems));
    assert.match(r.problems[0][1], /^title-h3 font-weight 500, expected 600/);
    n++;
    // type-tracking
    r = await run(pageHtml(LANGS.editorial, { extraCss: `${S('display-h1')} h1{letter-spacing:0px !important}` }));
    assert.deepStrictEqual(codesOf(r), ['type-tracking'], JSON.stringify(r.problems));
    assert.match(r.problems[0][1], /^display-h1 letter-spacing (0px|normal), expected -1px/);
    r = await run(pageHtml(LANGS.editorial, { extraCss: `${S('display-h1')} h1{letter-spacing:normal !important}` }));
    assert.deepStrictEqual(codesOf(r), ['type-tracking'], '`normal` is not -1px');
    n++;
    // the tolerance: 0.04px off is not a departure
    r = await run(pageHtml(LANGS.editorial, { extraCss: `${S('eyebrow-h4')} h4{letter-spacing:2.04px !important}` }));
    clean(r, 'inside the tolerance');
    n++;
    // type-caps: a missing uppercase (editorial eyebrow) and an extra uppercase (editorial display)
    r = await run(pageHtml(LANGS.editorial, { extraCss: `${S('eyebrow-h4')} h4{text-transform:none !important}` }));
    assert.deepStrictEqual(codesOf(r), ['type-caps'], JSON.stringify(r.problems));
    assert.match(r.problems[0][1], /^eyebrow-h4 text-transform none, expected uppercase/);
    r = await run(pageHtml(LANGS.editorial, { extraCss: `${S('eyebrow-text')} p{text-transform:none !important}` }));
    assert.deepStrictEqual(codesOf(r), ['type-caps'], 'the text module is measured on its p');
    r = await run(pageHtml(LANGS.editorial, { extraCss: `${S('display-h2')} h2{text-transform:uppercase !important}` }));
    assert.deepStrictEqual(codesOf(r), ['type-caps'], JSON.stringify(r.problems));
    assert.match(r.problems[0][1], /^display-h2 text-transform uppercase, expected none/);
    n++;
    // type-family
    r = await run(pageHtml(LANGS.editorial, { extraCss: `${S('display-h1')} h1{font-family:Arial,"Playfair Display" !important}` }));
    assert.deepStrictEqual(codesOf(r), ['type-family'], JSON.stringify(r.problems));
    assert.match(r.problems[0][1], /^display-h1 font-family Arial, expected Playfair Display/);
    n++;
    // type: a slot missing, a slot twice (nothing else is then judged)
    r = await run(pageHtml(LANGS.editorial, { drop: 'eyebrow-h4' }));
    assert.deepStrictEqual(codesOf(r), ['type'], JSON.stringify(r.problems));
    assert.match(r.problems[0][1], /^4 of 5 type slots rendered as the sheet says: eyebrow-h4 is not in the page/);
    assert.strictEqual(r.typeChecked, 0, 'nothing is counted as checked when a slot is missing');
    r = await run(pageHtml(LANGS.editorial, { dup: 'display-h2' }));
    assert.deepStrictEqual(codesOf(r), ['type'], JSON.stringify(r.problems));
    assert.match(r.problems[0][1], /display-h2 is on the page 2 times, expected 1/);
    r = await run('<!doctype html><html><body><p>nothing</p></body></html>');
    assert.deepStrictEqual(codesOf(r), ['type'], 'a page without the post root has no slot');
    assert.strictEqual(r.typeChecked, 0);
    n++;
    // type-overflow: a heading wider than its column at 400 px (the section clips, so the page itself does not scroll)
    const NOWRAP = `.et_pb_section{overflow:hidden}${S('display-h1')} h1{white-space:nowrap !important;overflow-wrap:normal !important}`;
    r = await run(pageHtml(LANGS.editorial, { extraCss: NOWRAP }), { width: 400 });
    assert.deepStrictEqual(codesOf(r), ['type-overflow'], JSON.stringify(r.problems));
    assert.ok(r.problems.some(([, d]) => /^display-h1 scrollWidth \d+ > clientWidth \d+/.test(d)), JSON.stringify(r.problems));
    n++;
    // overflow: the page wider than the window
    r = await run(pageHtml(LANGS.editorial, { wide: true }), { width: 400 });
    assert.deepStrictEqual(codesOf(r), ['overflow'], JSON.stringify(r.problems));
    assert.match(r.problems[0][1], /^scrollWidth \d+ > innerWidth 400/);
    n++;
    // type-font-loaded: no loaded face for the family (no @font-face at all, and a face of another family)
    r = await run(pageHtml(LANGS.editorial, { faces: false }));
    assert.deepStrictEqual(codesOf(r), ['type-font-loaded'], JSON.stringify(r.problems));
    assert.strictEqual(r.problems.length, 5, 'every slot is judged');
    assert.match(r.problems[0][1], /^display-h1 family "Playfair Display" at weight 600 has no loaded face/);
    if (FONT_B64) {
      const other = LANGS.editorial.map((e) => Object.assign({}, e));
      // The page loads faces for the other language's families only.
      const html = pageHtml(LANGS['brutalist-flat']).replace(/<\/style>/, `${T.TYPE_SLOTS.map((s, i) => `${S(s.key)} ${s.tag}{font-family:${qf(LANGS['brutalist-flat'][i].family)} !important}`).join('')}</style>`);
      r = await run(html, { exps: LANGS.editorial });
      assert.ok(codesOf(r).includes('type-font-loaded') && codesOf(r).includes('type-family'), 'a page in another language is caught: ' + JSON.stringify(codesOf(r)));
      assert.ok(other.length === 5);
      // A face whose weight range stops short of the weight is not a loaded face for it.
      const narrow = pageHtml(LANGS.editorial).replace(/font-weight:100 900/g, 'font-weight:100 500');
      r = await run(narrow);
      assert.deepStrictEqual(codesOf(r), ['type-font-loaded'], JSON.stringify(r.problems));
      assert.match(r.problems[0][1], /100 500 loaded/);
    }
    n++;
    // type-expectation: a hole in an expectation fails its slot and judges nothing else of it, even on a page that agrees
    const holey = LANGS.editorial.map((e) => Object.assign({}, e));
    holey[2].weight = null;
    r = await run(pageHtml(LANGS.editorial), { exps: holey });
    assert.deepStrictEqual(codesOf(r).filter((c) => c !== 'type-font-loaded'), ['type-expectation'], JSON.stringify(r.problems));
    assert.match(r.problems.find(([c]) => c === 'type-expectation')[1], /^title-h3 the resolved markup has no weight/);
    assert.strictEqual(r.typeChecked, 5);
    r = await run(pageHtml(LANGS.editorial), { exps: LANGS.editorial.slice(0, 4) });
    assert.deepStrictEqual(codesOf(r), ['type-expectation'], 'a short expectation list is a failure, not a partial pass');
    assert.strictEqual(r.typeChecked, 0);
    n++;
  } finally {
    await browser.close();
  }
  console.log(`ok ${n} live checks (synthetic pages, real Chromium)`);
})().catch((e) => { console.error(e && e.stack ? e.stack : e); process.exit(1); });
