#!/usr/bin/env node
'use strict';
/* Live proof (a real Chromium, SYNTHETIC pages, NOT the dev site) of the rhythm half of scripts/verify-language-type.cjs: scripts/lib/language-rhythm-run.cjs
 * measuring a page (the computed padding-top, padding-bottom and background-image of each rhythm section) and judging it with
 * scripts/lib/language-rhythm-checks.cjs.
 * The pages carry the class names Divi 5.15.1 renders (`et-l--post` > `et_pb_section` > `et_pb_row` > `et_pb_column` > `et_pb_module et_pb_text` >
 * `.et_pb_text_inner` > `p`) and a stylesheet GENERATED from the expectations the real PHP pipeline gives for editorial and luxe
 * (SectionRecipes::resolveMarkup through scripts/lib/language-type-sheet.php), with media queries at Divi's breakpoints (tablet max-width 980px, phone
 * max-width 767px), so a page that agrees with its language passes at 1280, 900 and 400 px, and each deliberate departure is caught with the right code:
 * a wrong desktop top, a wrong tablet bottom, the desktop value leaking to the phone, open rendered no larger than section (rhythm-order), a tablet-only
 * inversion, a slot missing, a slot twice, the photo missing, a hole in an expectation and a page wider than the window.
 * Needs php (the offline sheet) and Playwright (../layoutlab or PLAYWRIGHT_PATH).
 * It cannot prove what Divi itself prints: that is `node scripts/verify-language-type.cjs`. */
const assert = require('assert');
const path = require('path');
const { spawnSync } = require('child_process');
const T = require('./lib/language-type-checks.cjs');
const R = require('./lib/language-rhythm-checks.cjs');
const { checkRhythm } = require('./lib/language-rhythm-run.cjs');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(__dirname, '..', '..', 'layoutlab', 'node_modules', 'playwright');
const { chromium } = require(PW);

const offline = (language) => {
  const r = spawnSync('php', [path.join(__dirname, 'lib', 'language-type-sheet.php'), '--language', language], { input: T.sheetMarkup() + '\n' + R.rhythmSheetMarkup(), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  assert.strictEqual(r.status, 0, 'php failed: ' + (r.error ? r.error.message : r.stderr));
  const row = JSON.parse(r.stdout)[0];
  assert.deepStrictEqual(row.leftovers, [], language + ' resolves');
  assert.strictEqual(row.valid, true, language + ' passes the gate');
  return R.pageRhythmAttrs(row.markup).map((b) => R.expectRhythmFromAttrs(b.attrs));
};
const LANGS = { editorial: offline('editorial'), luxe: offline('luxe') };
for (const [id, exps] of Object.entries(LANGS)) {
  assert.strictEqual(exps.length, R.RHYTHM_SLOTS.length);
  for (const e of exps) assert.deepStrictEqual(R.missingRhythmExpectations(e), [], id + ' expectations are complete');
}
// Both languages order their roles strictly, and they differ (so a page in one language is not a page in the other).
assert.deepStrictEqual(LANGS.editorial[0].desktop, { top: 120, bottom: 120 });
assert.notDeepStrictEqual(LANGS.editorial[0], LANGS.luxe[0]);

const PHOTO = 'data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2210%22 height=%2210%22><rect width=%2210%22 height=%2210%22 fill=%22%23888%22/></svg>';

/** The synthetic page of one language: Divi's class names and a stylesheet generated from the expectations (desktop, then tablet and phone media queries). */
function pageHtml(exps, { extraCss = '', drop = null, dup = null, noPhoto = false, wide = false } = {}) {
  const rules = R.RHYTHM_SLOTS.map((s, i) => {
    const e = exps[i];
    const sel = `[data-k="${s.key}"]`;
    const base = `${sel}{padding:${e.desktop.top}px 0 ${e.desktop.bottom}px 0;${s.image && !noPhoto ? `background-image:url('${PHOTO}');background-size:cover;` : ''}}`;
    return base
      + `@media (max-width:980px){${sel}{padding-top:${e.tablet.top}px;padding-bottom:${e.tablet.bottom}px}}`
      + `@media (max-width:767px){${sel}{padding-top:${e.phone.top}px;padding-bottom:${e.phone.bottom}px}}`;
  }).join('');
  const slotHtml = (s) => `<div class="et_pb_section" data-k="${s.key}"><div class="et_pb_row"><div class="et_pb_column"><div class="et_pb_module et_pb_text"><div class="et_pb_text_inner"><p>${s.text}</p></div></div></div></div></div>`;
  const blocks = R.RHYTHM_SLOTS.filter((s) => s.key !== drop).map(slotHtml);
  if (dup) blocks.push(slotHtml(R.RHYTHM_SLOTS.find((s) => s.key === dup)));
  if (wide) blocks.push('<div class="decoy" style="width:900px;height:10px;background:#ccc"></div>');
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>
body{margin:0;background:#faf7f2;color:#111;font-family:serif}.et_pb_section{padding:56px 0;box-sizing:border-box}.et_pb_row{width:90%;margin:0 auto}.et_pb_column{display:block}p{margin:0}
${rules}${extraCss}</style></head><body><div class="et-l--post">${blocks.join('\n')}</div></body></html>`;
}

const codesOf = (r) => [...new Set(r.problems.map(([c]) => c))];
const S = (key) => `[data-k="${key}"]`;

(async () => {
  const browser = await chromium.launch();
  let n = 0;
  try {
    const run = async (html, { width = 1280, exps = LANGS.editorial } = {}) => {
      const ctx = await browser.newContext({ viewport: { width, height: 900 } });
      try {
        const p = await ctx.newPage();
        await p.setContent(html, { waitUntil: 'load' });
        return await checkRhythm(p, { width, expects: exps });
      } finally { await ctx.close(); }
    };

    // ---- the agreeing pages pass at every width, for two languages with different rhythms ----
    for (const [id, exps] of Object.entries(LANGS)) {
      for (const width of T.WIDTHS) {
        const ok = await run(pageHtml(exps), { width, exps });
        assert.deepStrictEqual(ok.problems, [], `${id}/${width}: ${JSON.stringify(ok.problems)}`);
        assert.strictEqual(ok.rhythmChecked, R.RHYTHM_SLOTS.length, `${id}/${width}: every slot counts as checked`);
        assert.strictEqual(ok.observations.length, R.RHYTHM_SLOTS.length);
        n++;
      }
    }
    // The observations carry the expected and the computed values of the width's breakpoint.
    const obs = (await run(pageHtml(LANGS.luxe), { width: 900, exps: LANGS.luxe })).observations;
    assert.strictEqual(obs[0].breakpoint, 'tablet');
    assert.deepStrictEqual(obs[0].expected, LANGS.luxe[0].tablet);
    assert.strictEqual(obs[0].got.paddingTop, `${LANGS.luxe[0].tablet.top}px`);
    assert.strictEqual(obs[3].got.backgroundImage, 'image');
    n++;
    // The tolerance: 0.4 px off is not a departure
    let r = await run(pageHtml(LANGS.editorial, { extraCss: `${S('rhythm-section')}{padding-top:120.4px !important}` }));
    assert.deepStrictEqual(r.problems, [], JSON.stringify(r.problems));
    n++;

    // ---- one departure per code ----
    // rhythm-padding: a wrong desktop top
    r = await run(pageHtml(LANGS.editorial, { extraCss: `${S('rhythm-section')}{padding-top:96px !important}` }));
    assert.deepStrictEqual(codesOf(r), ['rhythm-padding'], JSON.stringify(r.problems));
    assert.deepStrictEqual(r.problems.map(([, d]) => d), ['rhythm-section padding-top 96px at desktop, expected 120px']);
    n++;
    // rhythm-padding: a wrong tablet bottom, at the tablet width only
    const tabletBottom = `@media (max-width:980px){${S('rhythm-tight')}{padding-bottom:64px !important}}`;
    r = await run(pageHtml(LANGS.editorial, { extraCss: tabletBottom }), { width: 900 });
    assert.deepStrictEqual(codesOf(r), ['rhythm-padding'], JSON.stringify(r.problems));
    assert.deepStrictEqual(r.problems.map(([, d]) => d), ['rhythm-tight padding-bottom 64px at tablet, expected 48px']);
    for (const width of [1280, 400]) {
      r = await run(pageHtml(LANGS.editorial, { extraCss: `@media (min-width:768px) and (max-width:980px){${S('rhythm-tight')}{padding-bottom:64px !important}}` }), { width });
      assert.deepStrictEqual(r.problems, [], `a tablet-only departure is invisible at ${width}`);
    }
    n++;
    // rhythm-padding: the desktop value leaking to the phone (no phone step)
    const leak = R.RHYTHM_SLOTS.map((s) => `${S(s.key)}{padding-top:${LANGS.editorial[R.RHYTHM_SLOTS.indexOf(s)].desktop.top}px !important;padding-bottom:${LANGS.editorial[R.RHYTHM_SLOTS.indexOf(s)].desktop.bottom}px !important}`).join('');
    r = await run(pageHtml(LANGS.editorial, { extraCss: leak }), { width: 400 });
    assert.ok(codesOf(r).includes('rhythm-padding'), JSON.stringify(r.problems));
    assert.strictEqual(r.problems.filter(([c]) => c === 'rhythm-padding').length, 8, 'every slot, both sides');
    assert.match(r.problems[0][1], /^rhythm-section padding-top 120px at phone, expected 56px/);
    n++;
    // rhythm-order: open rendered no larger than section (equal): measured, so it fails even where the expectations are fine
    r = await run(pageHtml(LANGS.editorial, { extraCss: `${S('rhythm-open')}{padding-top:120px !important;padding-bottom:120px !important}` }));
    assert.deepStrictEqual(codesOf(r).sort(), ['rhythm-order', 'rhythm-padding'], JSON.stringify(r.problems));
    assert.strictEqual(r.problems.filter(([c]) => c === 'rhythm-order').length, 2, 'top and bottom');
    assert.match(r.problems.find(([c]) => c === 'rhythm-order')[1], /open 120px, section 120px, tight 72px must be open > section > tight \(measured\)/);
    n++;
    // rhythm-order: open rendered SMALLER than section
    r = await run(pageHtml(LANGS.editorial, { extraCss: `${S('rhythm-open')}{padding-top:100px !important;padding-bottom:100px !important}` }));
    assert.ok(codesOf(r).includes('rhythm-order'), JSON.stringify(r.problems));
    n++;
    // rhythm-order: the page and the expectations agree, but the order is wrong on the PAGE only at the tablet width: expectations that are themselves inverted
    // (a pure cross-check on the resolved markup) are also caught, and a page that follows them agrees with them but still fails the order
    const inverted = LANGS.editorial.map((e) => JSON.parse(JSON.stringify(e)));
    inverted[1].tablet = { top: 72, bottom: 72 }; // open at tablet below section (80)
    inverted[3].tablet = { top: 72, bottom: 72 };
    r = await run(pageHtml(inverted, {}), { width: 900, exps: inverted });
    assert.deepStrictEqual(codesOf(r), ['rhythm-order'], JSON.stringify(r.problems));
    assert.strictEqual(r.problems.length, 4, 'measured (top, bottom) and expected (top, bottom)');
    assert.ok(r.problems.some(([, d]) => /\(measured\)$/.test(d)) && r.problems.some(([, d]) => /\(the resolved markup\)$/.test(d)));
    r = await run(pageHtml(inverted, {}), { width: 1280, exps: inverted });
    assert.ok(r.problems.every(([, d]) => /expected padding/.test(d)), 'at 1280 only the expectation cross-check speaks: ' + JSON.stringify(r.problems));
    n++;
    // rhythm: a slot missing, a slot twice (nothing else is then judged)
    r = await run(pageHtml(LANGS.editorial, { drop: 'rhythm-open' }));
    assert.deepStrictEqual(codesOf(r), ['rhythm'], JSON.stringify(r.problems));
    assert.match(r.problems[0][1], /^3 of 4 rhythm slots rendered as the sheet says: rhythm-open is not in the page/);
    assert.strictEqual(r.rhythmChecked, 0, 'nothing is counted as checked when a slot is missing');
    r = await run(pageHtml(LANGS.editorial, { dup: 'rhythm-tight' }));
    assert.deepStrictEqual(codesOf(r), ['rhythm'], JSON.stringify(r.problems));
    assert.match(r.problems[0][1], /rhythm-tight is on the page 2 times, expected 1/);
    r = await run('<!doctype html><html><body><p>nothing</p></body></html>');
    assert.deepStrictEqual(codesOf(r), ['rhythm'], 'a page without the post root has no slot');
    assert.strictEqual(r.rhythmChecked, 0);
    n++;
    // rhythm-image: the photo missing
    r = await run(pageHtml(LANGS.editorial, { noPhoto: true }));
    assert.deepStrictEqual(codesOf(r), ['rhythm-image'], JSON.stringify(r.problems));
    assert.match(r.problems[0][1], /^rhythm-image computed background-image is none/);
    n++;
    // overflow: the page wider than the window
    r = await run(pageHtml(LANGS.editorial, { wide: true }), { width: 400 });
    assert.deepStrictEqual(codesOf(r), ['overflow'], JSON.stringify(r.problems));
    assert.match(r.problems[0][1], /^scrollWidth \d+ > innerWidth 400/);
    n++;
    // rhythm-expectation: a hole fails its slot and judges nothing else of it, even on a page that agrees; a short list is a failure, not a partial pass
    const holey = LANGS.editorial.map((e) => JSON.parse(JSON.stringify(e)));
    holey[1].phone.bottom = null;
    r = await run(pageHtml(LANGS.editorial), { width: 400, exps: holey });
    assert.deepStrictEqual(codesOf(r), ['rhythm-expectation'], JSON.stringify(r.problems));
    assert.match(r.problems[0][1], /^rhythm-open the resolved markup has no px phone\.bottom/);
    assert.strictEqual(r.rhythmChecked, 4, 'the slots were measured; the hole is what fails');
    r = await run(pageHtml(LANGS.editorial), { exps: LANGS.editorial.slice(0, 3) });
    assert.deepStrictEqual(codesOf(r), ['rhythm-expectation'], 'a short expectation list is a failure');
    assert.strictEqual(r.rhythmChecked, 0);
    n++;
    // A page in the OTHER language is caught (the gate could tell them apart)
    r = await run(pageHtml(LANGS.luxe), { exps: LANGS.editorial });
    assert.ok(codesOf(r).includes('rhythm-padding'), 'a page in another language is caught: ' + JSON.stringify(codesOf(r)));
    n++;
  } finally {
    await browser.close();
  }
  console.log(`ok ${n} live checks (synthetic pages, real Chromium)`);
})().catch((e) => { console.error(e && e.stack ? e.stack : e); process.exit(1); });
