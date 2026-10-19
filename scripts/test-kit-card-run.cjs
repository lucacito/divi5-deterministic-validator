#!/usr/bin/env node
'use strict';
/* Live proof (a real Chromium, SYNTHETIC pages, NOT the dev site) of the card half of scripts/verify-kits.cjs: scripts/lib/kit-card-run.cjs
 * measuring a page, hovering with the real pointer, sampling the painted fill and judging it with scripts/lib/kit-checks.cjs.
 * The pages carry the class names Divi 5.15.1 renders (`et_pb_row` > `et_pb_column` > `et_pb_group`, `et_pb_heading`, `et_pb_text`,
 * `et_pb_image`) and a stylesheet GENERATED from the expectations the real PHP pipeline gives for soft-lift under editorial, so a page that
 * agrees with the kit passes, and each deliberate departure (a wrong radius, a panel that lifts, a missing card, pale text, an unclipped
 * image, columns that do not stack) is caught with the right code. Needs php (the offline sheet) and Playwright (../layoutlab).
 * It cannot prove what Divi itself prints: that is `node scripts/verify-kits.cjs`. */
const assert = require('assert');
const path = require('path');
const { spawnSync } = require('child_process');
const K = require('./lib/kit-checks.cjs');
const { checkCards } = require('./lib/kit-card-run.cjs');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(__dirname, '..', '..', 'layoutlab', 'node_modules', 'playwright');
const { chromium } = require(PW);

const php = spawnSync('php', [path.join(__dirname, 'lib', 'kit-sheet-offline.php'), '--kit', 'soft-lift', '--language', 'editorial'], { input: K.sheetMarkup(), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
assert.strictEqual(php.status, 0, 'php failed: ' + (php.error ? php.error.message : php.stderr));
const row = JSON.parse(php.stdout)[0];
const sheet = K.cardAttrs(row.markup);
const expects = sheet.map((c) => K.expectCardFromAttrs(c.attrs));
const buttonExpects = sheet.map((c) => c.buttons.map(K.expectFromAttrs));
const N = K.CARD_SLOTS.length;
const HAIRLINE = '#e3dccf';
const IMG = "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='9'><rect width='16' height='9' fill='%23336699'/></svg>";

const shadowCss = (s) => (s ? `${s.color} ${s.x || 0}px ${s.y || 0}px ${s.blur}px 0px` : 'none');
/** The stylesheet that makes a synthetic card agree with its expectation (what Divi would print for the resolved markup). */
function cssFor(slot, e) {
  const sel = `[data-k="${slot.key}"]`;
  const sides = ['top', 'right', 'bottom', 'left'].map((k) => `border-${k}:${e.rest[k].width}px ${e.rest[k].style} ${e.rest[k].color};`).join('');
  const r = e.rest.radius.map((v) => v + 'px').join(' ');
  let css = `${sel}{box-sizing:border-box;background:${e.rest.fill};${sides}border-radius:${r};padding:${e.rest.padding.map((v) => v + 'px').join(' ')};box-shadow:${shadowCss(e.shadow.rest)};${Math.max(...e.rest.radius) > 0 ? 'overflow:hidden;' : ''}transition:none}`;
  if (slot.role === 'card') {
    const hov = [];
    if (e.lift !== null) hov.push(`transform:translateY(${e.lift}px)`);
    if (e.shadow.hover && e.shadow.hover !== e.shadow.rest) hov.push(`box-shadow:${shadowCss(e.shadow.hover)}`);
    if (e.hoverBorder) hov.push(`border-color:${e.hoverBorder}`);
    if (hov.length) css += `${sel}:hover{${hov.join(';')}}`;
  }
  return css;
}

/** The stylesheet that makes a synthetic button agree with its expectation (soft-lift under editorial, on the inverted panel). */
function buttonCssFor(key, e) {
  const sel = `[data-kb="${key}"]`;
  const side = (k) => `${e.rest[k].width}px ${e.rest[k].style} ${e.rest[k].color}`;
  const r = e.rest.radius.map((v) => v + 'px').join(' ');
  let css = `${sel}{display:inline-block;box-sizing:border-box;background:${e.rest.fill};color:${e.rest.label};border-top:${side('top')};border-bottom:${side('bottom')};border-left:${side('top')};border-right:${side('top')};border-radius:${r};padding:${e.rest.padding.map((v) => v + 'px').join(' ')};text-transform:${e.rest.capitalization};letter-spacing:${e.rest.letterSpacing}px;font-weight:${e.rest.weight};box-shadow:${shadowCss(e.shadow.rest)};transition:none;text-decoration:none}`;
  const hov = [`background:${e.hover.fill}`, `color:${e.hover.label}`, `border-color:${e.hover.border}`];
  if (e.lift !== null) hov.push(`transform:translateY(${e.lift}px)`);
  if (e.shadow.hover && e.shadow.hover !== e.shadow.rest) hov.push(`box-shadow:${shadowCss(e.shadow.hover)}`);
  return css + `${sel}:hover{${hov.join(';')}}`;
}

/** The page: five bands as Divi nests them; `tweak(slot, html)` / `extraCss` apply a deliberate departure. */
function pageHtml({ extraCss = '', tweak = (s, h) => h, drop = [], textColor = null, paraColor = null } = {}) {
  const card = (slot) => {
    const idx = K.CARD_SLOTS.indexOf(slot);
    const e = expects[idx];
    const inks = sheet[idx].inks;
    const heading = textColor || inks.heading; const para = paraColor || inks.paragraph;
    const buttons = buttonExpects[idx].map((be, j) => `<div class="et_pb_module et_pb_button_module_wrapper"><a class="et_pb_button" data-kb="${slot.key}:${j}" href="#">Action ${j}</a></div>`).join('');
    const body = `${slot.image ? `<div class="et_pb_module et_pb_image"><span class="et_pb_image_wrap"><img src="${IMG}" style="display:block;width:100%;aspect-ratio:16/9"></span></div>` : ''}<div class="et_pb_module et_pb_heading"><div class="et_pb_heading_container"><h3 style="margin:0;color:${heading}">${slot.heading}</h3></div></div><div class="et_pb_module et_pb_text"><div class="et_pb_text_inner"><p style="margin:0;color:${para}">Placeholder copy for a card.</p></div></div>${buttons}`;
    const cls = slot.carrier === 'column' ? 'et_pb_column' : 'et_pb_module et_pb_group';
    return { css: cssFor(slot, e) + buttonExpects[idx].map((be, j) => buttonCssFor(`${slot.key}:${j}`, be)).join(''), html: tweak(slot, `<div class="${cls}" data-k="${slot.key}" style="display:block">${body}</div>`) };
  };
  const live = K.CARD_SLOTS.filter((s) => !drop.includes(s.key));
  const cards = live.map(card);
  const inRow = (s) => cards[live.indexOf(s)].html;
  const groups = live.filter((s) => !s.row).map((s) => `<div class="et_pb_row"><div class="et_pb_column" style="display:block;margin-bottom:24px">${inRow(s)}</div></div>`).join('');
  const cols = live.filter((s) => s.row).map(inRow).join('');
  return `<style>body{margin:0;background:#faf7f2}.et_pb_row{width:90%;margin:0 auto;padding:16px 0}.et-l--post .three{display:flex;gap:24px}.three>[data-k]{flex:1 1 0;min-width:0}@media (max-width:600px){.et-l--post .three{display:block}.three>[data-k]{margin-bottom:24px}}${cards.map((c) => c.css).join('')}${extraCss}</style>`
    + `<div class="et-l--post"><div class="et_pb_section">${groups}<div class="et_pb_row"><div class="three">${cols}</div></div></div></div>`;
}

(async () => {
  const browser = await chromium.launch();
  const run = async (html, width = 1280, before = null) => {
    const p = await browser.newPage({ viewport: { width, height: 900 } });
    await p.setContent(html);
    if (before) await before(p);
    const r = await checkCards(p, { width, expects, buttonExpects, hairline: HAIRLINE });
    await p.close();
    return r;
  };
  const codes = (r) => r.problems.map((x) => x[0]);
  try {
    // A page that agrees with the kit passes, at desktop (columns side by side) and at 400 px (stacked).
    const ok = await run(pageHtml());
    assert.deepStrictEqual(ok.problems, [], JSON.stringify(ok.problems));
    assert.strictEqual(ok.cardsChecked, N);
    assert.strictEqual(ok.layout, 'side-by-side');
    assert.strictEqual(ok.observations.length, N);
    const clip = ok.observations.find((o) => o.key === 'panel-clip');
    assert.strictEqual(clip.clip.flush, true);
    assert.strictEqual(clip.clip.clipped, true, 'the 16px radius cuts the flush image corner');
    assert.ok(ok.observations.every((o, i) => o.fill === K.hex(K.colour(expects[i].rest.fill))), 'the painted fill was measured with the children hidden and is the resolved fill: ' + ok.observations.map((o) => o.fill).join());
    assert.strictEqual(ok.observations.filter((o) => o.ground).length, 9, 'eight grounds on the two light bands and the accent middle card are reported as grounds');
    const feat = ok.observations.find((o) => o.key === 'column-2').featured;
    assert.ok(feat && feat.featured > feat.neighbours[0] * 1.5 && feat.neighbours.length === 2 && feat.ratio >= 1.5, 'the featured middle card is measured against its neighbours: ' + JSON.stringify(feat));
    assert.strictEqual(ok.observations.filter((o) => o.buttons).length, 2, 'the two button panels report their buttons');
    assert.ok(ok.observations.every((o) => o.band !== null), 'a strip of the band above every card was sampled');
    const phone = await run(pageHtml(), 400);
    assert.deepStrictEqual(phone.problems, [], JSON.stringify(phone.problems));
    assert.strictEqual(phone.layout, 'stacked');

    // Departures, each caught with its own code.
    const wrongRadius = await run(pageHtml({ extraCss: '[data-k="card-surface"]{border-top-left-radius:2px}' }));
    assert.deepStrictEqual(codes(wrongRadius), ['card-radius']);
    assert.match(wrongRadius.problems[0][1], /^card-surface rest: borderTopLeftRadius 2px, expected 16px/);
    const wrongBorder = await run(pageHtml({ extraCss: '[data-k="panel-dark"]{border-left-width:3px}' }));
    assert.ok(codes(wrongBorder).includes('card-border'), codes(wrongBorder).join());
    const wrongPad = await run(pageHtml({ extraCss: '[data-k="card-accent"]{padding-left:10px}' }));
    assert.deepStrictEqual(codes(wrongPad), ['card-padding']);
    const wrongFill = await run(pageHtml({ extraCss: '[data-k="card-dark"]{background:#000}' }));
    assert.ok(codes(wrongFill).includes('card-fill'), codes(wrongFill).join());
    assert.ok(codes(wrongFill).includes('card-contrast'), 'dark text on a black card: ' + codes(wrongFill).join());
    const panelLifts = await run(pageHtml({ extraCss: '[data-k="panel-accent"]:hover{transform:translateY(-4px)}' }));
    assert.deepStrictEqual(codes(panelLifts), ['card-hover']);
    assert.match(panelLifts.problems[0][1], /^panel-accent hover: the panel moved/);
    const panelScales = await run(pageHtml({ extraCss: '[data-k="panel-surface"]:hover{transform:scale(1.03)}' }));
    assert.ok(codes(panelScales).includes('card-hover'), codes(panelScales).join());
    assert.match(panelScales.problems.find((x) => x[0] === 'card-hover')[1], /^panel-surface hover: the panel moved/);
    const panelShadow = await run(pageHtml({ extraCss: '[data-k="panel-bg"]:hover{box-shadow:0 8px 24px rgba(0,0,0,.3)}' }));
    assert.deepStrictEqual(codes(panelShadow), ['card-hover']);
    const noLift = await run(pageHtml({ extraCss: '[data-k="card-bg"]:hover{transform:none}' }));
    assert.deepStrictEqual(codes(noLift), ['card-hover']);
    const noShadow = await run(pageHtml({ extraCss: '[data-k="column-2"]:hover{box-shadow:none}' }));
    assert.deepStrictEqual(codes(noShadow), ['card-hover']);
    const restShadow = await run(pageHtml({ extraCss: '[data-k="card-dark"]{box-shadow:0 4px 8px rgba(0,0,0,.3)}' }));
    assert.ok(codes(restShadow).includes('card-shadow'), codes(restShadow).join());
    const pale = await run(pageHtml({ paraColor: '#cccccc' }));
    assert.ok(codes(pale).length >= 12 && codes(pale).every((c) => c === 'card-contrast'), 'pale paragraph text on every card: ' + codes(pale).join());
    const unclipped = await run(pageHtml({ extraCss: '[data-k="panel-clip"]{overflow:visible}' }));
    assert.deepStrictEqual(codes(unclipped), ['card-clip']);

    // Grounds, button panels and the featured card: each new code has its own departure.
    const wrongGround = await run(pageHtml({ extraCss: '[data-k="inverted-card-bg"]{background:#8a8a8a}' }));
    assert.ok(codes(wrongGround).includes('ground-fill') && codes(wrongGround).includes('card-fill'), 'a wrong ground fill (computed and painted): ' + codes(wrongGround).join());
    assert.match(wrongGround.problems.find((x) => x[0] === 'ground-fill')[1], /^inverted-card-bg painted fill #8a8a8a differs from the resolved ground fill #1c1a17/);
    // Something drawn over a correct ground (the computed style stays right): only the painted fill gives it away.
    const overlaid = await run(pageHtml({ extraCss: '[data-k="accent-tile-surface"]{position:relative}[data-k="accent-tile-surface"]::after{content:"";position:absolute;inset:0;background:#f4f4f4}' }));
    assert.ok(codes(overlaid).includes('ground-fill'), 'a ground covered by something else: ' + codes(overlaid).join());
    assert.ok(!codes(overlaid).includes('card-fill'), 'the computed fill is still right, so only ground-fill fires');
    const unreadable = await run(pageHtml({ extraCss: '[data-kb="panel-buttons-bg:0"]{color:#eeeeee}' }));
    assert.ok(unreadable.problems.some(([c, d]) => c === 'panel-button' && /^panel-buttons-bg:0 .*label/.test(d)), 'a label that is unreadable on the inverted panel: ' + JSON.stringify(unreadable.problems));
    const hoverColours = await run(pageHtml({ extraCss: '[data-kb="panel-buttons-surface:1"]:hover{background:#c00000}' }));
    assert.ok(hoverColours.problems.some(([c, d]) => c === 'panel-button' && /hover: background-color/.test(d)), 'hover colours that are not the resolved ones: ' + JSON.stringify(hoverColours.problems));
    const invisibleBorder = await run(pageHtml({ extraCss: '[data-kb="panel-buttons-bg:1"]{border-width:2px;border-color:#1c1a17}' }));
    assert.ok(invisibleBorder.problems.some(([c, d]) => c === 'panel-button' && /^panel-buttons-bg:1 rest: border-top .* is 1:1, needs 3/.test(d)), 'a transparent button whose drawn border is the panel colour: ' + JSON.stringify(invisibleBorder.problems));
    const flatFeatured = await run(pageHtml({ extraCss: '[data-k="column-2"]{background:#ffffff}' }));
    assert.ok(codes(flatFeatured).includes('featured'), 'a featured card no more prominent than its neighbours: ' + codes(flatFeatured).join());
    assert.match(flatFeatured.problems.find((x) => x[0] === 'featured')[1], /^column-2 prominence .*needs 1\.5x/);
    const featuredPhone = await run(pageHtml({ extraCss: '[data-k="column-2"]{background:#ffffff}' }), 400);
    assert.ok(codes(featuredPhone).includes('featured'), 'the featured check also runs at 400px');
    // A fixed bar (the logged-in preview's WordPress admin bar) scrolled over a card must not disturb the painted-fill sample.
    const bar = await run(pageHtml({ extraCss: '#bar{position:fixed;top:0;left:0;right:0;height:32px;background:#23282d;z-index:9}' }), 1280, (page) => page.evaluate(() => { const b = document.createElement('div'); b.id = 'bar'; document.body.appendChild(b); const h = [...document.querySelectorAll('.et_pb_heading')].find((n) => n.textContent.trim() === 'Card on accent'); window.scrollTo(0, h.closest('.et_pb_group').getBoundingClientRect().top + window.scrollY - 5); }));
    assert.deepStrictEqual(bar.problems, [], JSON.stringify(bar.problems));
    // A fixed element INSIDE a card (a sticky badge, an admin-bar widget) is hidden once for the fill sample and restored to what it was:
    // it is not tagged twice, so it never stays hidden (and one that was hidden before stays hidden).
    for (const [initial, expected] of [['', 'visible'], ['hidden', 'hidden']]) {
      const pg = await browser.newPage({ viewport: { width: 1280, height: 900 } });
      await pg.setContent(pageHtml());
      await pg.evaluate((v) => { const c = document.querySelector('[data-aied-card="card-bg"]') || document.querySelector('[data-k="card-bg"]'); const f = document.createElement('div'); f.id = 'fx'; f.textContent = 'badge'; f.style.cssText = 'position:fixed;right:4px;bottom:4px;width:20px;height:20px;background:#c00'; if (v) f.style.visibility = v; c.appendChild(f); }, initial);
      const r = await checkCards(pg, { width: 1280, expects, buttonExpects, hairline: HAIRLINE });
      assert.deepStrictEqual(r.problems, [], JSON.stringify(r.problems));
      const after = await pg.evaluate(() => { const f = document.getElementById('fx'); return { vis: getComputedStyle(f).visibility, tagged: Object.keys(f.dataset) }; });
      assert.strictEqual(after.vis, expected, 'a fixed element inside a card is restored (was ' + JSON.stringify(initial) + ')');
      assert.deepStrictEqual(after.tagged, [], 'no leftover data attributes');
      await pg.close();
    }
    const lost = await run(pageHtml({ drop: ['panel-surface'] }));
    assert.deepStrictEqual(codes(lost), ['cards']);
    assert.match(lost.problems[0][1], /19 cards rendered, expected 20 \(missing: panel-surface\)/);
    assert.strictEqual(lost.cardsChecked, 0, 'a page with a card missing checks nothing else');
    const notStacked = await run(pageHtml({ extraCss: '@media (max-width:600px){.et-l--post .three{display:flex}}' }), 400);
    assert.ok(codes(notStacked).includes('overflow'), codes(notStacked).join());
    const wide = await run(pageHtml({ extraCss: '[data-k="card-bg"]{width:900px}' }), 400);
    assert.ok(codes(wide).includes('overflow'), 'a card wider than the phone: ' + codes(wide).join());
    console.log('OK kit-card-run in a real Chromium on synthetic pages: a matching page passes at 1280 and 400, every departure is caught (and a fixed bar over a card is not mistaken for one) with their code');
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });
