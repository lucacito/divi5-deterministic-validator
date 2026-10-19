#!/usr/bin/env node
'use strict';
/* Live proof (a real Chromium, SYNTHETIC pages, NOT the dev site) of the card and item halves of scripts/verify-kits.cjs: scripts/lib/kit-card-run.cjs
 * measuring a page, hovering with the real pointer, sampling the painted fill and judging it with scripts/lib/kit-checks.cjs, and
 * scripts/lib/kit-item-run.cjs measuring the accordions and toggles, clicking an item's title with the real pointer and judging the result.
 * The pages carry the class names Divi 5.15.1 renders (`et_pb_row` > `et_pb_column` > `et_pb_group`, `et_pb_heading`, `et_pb_text`,
 * `et_pb_image`) and a stylesheet GENERATED from the expectations the real PHP pipeline gives for soft-lift under editorial, so a page that
 * agrees with the kit passes, and each deliberate departure (a wrong radius, a panel that lifts, a missing card, pale text, an unclipped
 * image, columns that do not stack) is caught with the right code. The item pages carry the class names Divi 5.15.1's module.json selectors
 * name (`et_pb_accordion`, `et_pb_toggle` + `et_pb_accordion_item`, `et_pb_toggle_open|close`, `et_pb_toggle_title` with a ::before icon,
 * `et_pb_toggle_content`) and a few lines of script that open and close them on a click; each item code has its own departure, including a
 * click that opens nothing and a state fill that stays closed after the click. Needs php (the offline sheet) and Playwright (../layoutlab).
 * It cannot prove what Divi itself prints: that is `node scripts/verify-kits.cjs`. */
const assert = require('assert');
const path = require('path');
const { spawnSync } = require('child_process');
const K = require('./lib/kit-checks.cjs');
const { checkCards } = require('./lib/kit-card-run.cjs');
const { checkItems } = require('./lib/kit-item-run.cjs');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(__dirname, '..', '..', 'layoutlab', 'node_modules', 'playwright');
const { chromium } = require(PW);

const php = spawnSync('php', [path.join(__dirname, 'lib', 'kit-sheet-offline.php'), '--kit', 'soft-lift', '--language', 'editorial'], { input: K.sheetMarkup(), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
assert.strictEqual(php.status, 0, 'php failed: ' + (php.error ? php.error.message : php.stderr));
const row = JSON.parse(php.stdout)[0];
const sheet = K.cardAttrs(row.markup);
const expects = sheet.map((c) => K.expectCardFromAttrs(c.attrs));
const buttonExpects = sheet.map((c) => c.buttons.map(K.expectFromAttrs));
/** Development switch: AIED_ITEMS_ONLY=1 runs only the item half (the card half alone takes about four minutes). The full run (no variable) is the one that counts. */
const ITEMS_ONLY = process.env.AIED_ITEMS_ONLY === '1';
const N = K.CARD_SLOTS.length;
const itemSheet = K.itemAttrs(row.markup);
const clone = (o) => JSON.parse(JSON.stringify(o));
/** The expectations the item pages are built from: the real ones, but with an open state that differs from the closed one (fill, title, toggle icon), so a state that does not switch is visible. */
const itemExpects = itemSheet.map((h) => { const e = K.expectItemFromAttrs(h.attrs, h.slot.carrier); e.open.fill = '#fff3d6'; e.open.title = '#3a1a00'; if (e.open.icon !== null) e.open.icon = '#7a3b00'; return e; });
const itemLists = itemSheet.map((h) => h.items);
const NI = K.ITEM_SLOTS.length;
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

/** The stylesheet that makes a synthetic item block agree with its expectation (what Divi would print for the resolved markup). */
function itemCssFor(slot, e) {
  const key = `[data-s="${slot.key}"]`;
  const sel = slot.carrier === 'accordion' ? `${key} .et_pb_toggle` : key;
  const sides = ['top', 'right', 'bottom', 'left'].map((k) => `border-${k}:${e.rest[k].width}px ${e.rest[k].style} ${e.rest[k].color};`).join('');
  const pad = e.rest.padding || [20, 20, 20, 20];
  let css = `${sel}{box-sizing:border-box;${sides}border-radius:${e.rest.radius.map((v) => v + 'px').join(' ')};padding:${pad.map((v) => v + 'px').join(' ')};box-shadow:${shadowCss(e.shadow)};overflow:hidden;margin-bottom:10px;transition:none}`;
  css += `${sel}.et_pb_toggle_open{background:${e.open.fill}}${sel}.et_pb_toggle_close{background:${e.closed.fill}}`;
  css += `${sel} > .et_pb_toggle_title{position:relative;margin:0;padding:0 40px 0 0;cursor:pointer;font-size:20px}`;
  css += `${sel}.et_pb_toggle_open > .et_pb_toggle_title{color:${e.open.title}}${sel}.et_pb_toggle_close > .et_pb_toggle_title{color:${e.closed.title}}`;
  css += `${sel} > .et_pb_toggle_title::before{position:absolute;right:0;top:0}`;
  css += `${sel}.et_pb_toggle_close > .et_pb_toggle_title::before{content:"+";color:${e.closed.icon}}`;
  css += e.open.icon ? `${sel}.et_pb_toggle_open > .et_pb_toggle_title::before{content:"-";color:${e.open.icon}}` : `${sel}.et_pb_toggle_open > .et_pb_toggle_title::before{display:none}`;
  css += `${sel} .et_pb_toggle_content p{margin:0;padding-top:12px;color:${e.open.body}}${sel}.et_pb_toggle_close .et_pb_toggle_content{display:none}`;
  return css;
}

/**
 * The item page: every ITEM_SLOT as Divi nests it, with a few lines of script that open and close the items on a click (an accordion opens
 * the clicked item and closes the others, a toggle flips). `exps` are the expectations the stylesheet is generated from; `noClick` = slot
 * keys whose click does nothing, `noClose` = accordions that do not close the previously open item, `stuck` = slot keys whose open state
 * keeps the closed fill, `slowMs` delays every class switch, `drop` leaves a block out, `shortAccordion` removes an accordion's last item.
 */
function itemPageHtml({ extraCss = '', exps = itemExpects, noClick = [], noClose = [], stuck = [], slowMs = 0, drop = [], shortAccordion = null } = {}) {
  const live = K.ITEM_SLOTS.filter((s) => !drop.includes(s.key));
  const blocks = live.map((slot) => {
    const items = slot.items.slice(0, shortAccordion === slot.key ? slot.items.length - 1 : slot.items.length);
    const itemHtml = (it, accordion) => `<div class="et_pb_toggle ${accordion ? 'et_pb_accordion_item ' : 'et_pb_module '}${it.open ? 'et_pb_toggle_open' : 'et_pb_toggle_close'}"${accordion ? '' : ` data-s="${slot.key}"`}><h3 class="et_pb_toggle_title">${it.title}</h3><div class="et_pb_toggle_content"><p>Placeholder answer.</p></div></div>`;
    return slot.carrier === 'accordion'
      ? `<div class="et_pb_module et_pb_accordion" data-s="${slot.key}">${items.map((it) => itemHtml(it, true)).join('')}</div>`
      : itemHtml(items[0], false);
  });
  const css = live.map((slot) => itemCssFor(slot, exps[K.ITEM_SLOTS.indexOf(slot)])).join('') + stuck.map((k) => `[data-s="${k}"].stuck,[data-s="${k}"].stuck .et_pb_toggle{background:${exps[K.ITEM_SLOTS.findIndex((x) => x.key === k)].closed.fill} !important}`).join('');
  const script = `(${function (cfg) {
    document.querySelectorAll('.et_pb_toggle_title').forEach((title) => {
      title.addEventListener('click', () => {
        const item = title.closest('.et_pb_toggle');
        const root = item.closest('.et_pb_accordion') || item;
        const key = root.getAttribute('data-s');
        if (cfg.noClick.includes(key)) return;
        const apply = () => {
          const open = item.classList.contains('et_pb_toggle_open');
          if (item.classList.contains('et_pb_accordion_item')) {
            if (!cfg.noClose.includes(key)) root.querySelectorAll('.et_pb_toggle_open').forEach((o) => { o.classList.remove('et_pb_toggle_open'); o.classList.add('et_pb_toggle_close'); });
            item.classList.remove('et_pb_toggle_close'); item.classList.add('et_pb_toggle_open');
          } else {
            item.classList.toggle('et_pb_toggle_open', !open); item.classList.toggle('et_pb_toggle_close', open);
            if (cfg.stuck.includes(key)) item.classList.add('stuck');
          }
        };
        if (cfg.slowMs) setTimeout(apply, cfg.slowMs); else apply();
      });
    });
  }})(${JSON.stringify({ noClick, noClose, stuck, slowMs })})`;
  return `<style>body{margin:0;background:#faf7f2}.et_pb_row{width:90%;margin:0 auto;padding:16px 0}${css}${extraCss}</style>`
    + `<div class="et-l--post"><div class="et_pb_section"><div class="et_pb_row"><div class="et_pb_column" style="display:block">${blocks.join('')}</div></div></div></div><script>${script}</script>`;
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
    if (!ITEMS_ONLY) {
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
      const restShadow = await run(pageHtml({ extraCss: '[data-k="card-dark"]{box-shadow:0 4px 8px rgba(0,0,0,.3) !important}' }));
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

    }

    // ---- items: a real click on an item's title ----
    const FAST = 60; // the synthetic page switches at once; Divi's own slide (700 ms) is the production default of checkItems
    const runItems = async (html, { width = 1280, exps = itemExpects, lists = itemLists, before = null } = {}) => {
      const p = await browser.newPage({ viewport: { width, height: 900 } });
      await p.setContent(html);
      if (before) await before(p);
      const r = await checkItems(p, { width, expects: exps, lists, hairline: HAIRLINE, slideFloorMs: FAST });
      await p.close();
      return r;
    };
    const iokay = await runItems(itemPageHtml());
    assert.deepStrictEqual(iokay.problems, [], JSON.stringify(iokay.problems));
    assert.strictEqual(iokay.itemsChecked, NI);
    assert.strictEqual(iokay.observations.length, NI);
    assert.ok(iokay.observations.every((o) => o.click && o.click.after), 'every block was clicked');
    const acc = iokay.observations.find((o) => o.key === 'accordion-bg');
    assert.deepStrictEqual([acc.click.index, acc.click.before, acc.click.after], [1, [true, false, false], [false, true, false]], 'the first closed item opens and the open one closes');
    assert.deepStrictEqual(iokay.observations.find((o) => o.key === 'toggle-open-bg').click.after, [false], 'an open toggle closes');
    assert.deepStrictEqual(iokay.observations.find((o) => o.key === 'toggle-closed-bg').click.after, [true], 'a closed toggle opens');
    assert.ok(acc.rest[0].fill === '#fff3d6' || K.sameColour(acc.rest[0].fill, '#fff3d6'), 'the painted open fill was measured: ' + acc.rest[0].fill);
    assert.ok(K.sameColour(acc.rest[1].fill, itemExpects[0].closed.fill), 'and the painted closed fill');
    assert.ok(K.sameColour(acc.final[1].fill, '#fff3d6') && K.sameColour(acc.final[0].fill, itemExpects[0].closed.fill), 'after the click the states have traded places, and the painted fills with them');
    const iphone = await runItems(itemPageHtml(), { width: 400 });
    assert.deepStrictEqual(iphone.problems, [], JSON.stringify(iphone.problems));
    const slow = await runItems(itemPageHtml({ slowMs: 150 }));
    assert.deepStrictEqual(slow.problems, [], 'a class switch that comes 150 ms after the click is waited for: ' + JSON.stringify(slow.problems));

    // Departures, each caught with its own code.
    const ic = (r) => [...new Set(r.problems.map((x) => x[0]))]; // distinct codes: a departure that persists is reported at rest and again after the click
    const iLostBlock = await runItems(itemPageHtml({ drop: ['toggle-open-bg'] }));
    assert.deepStrictEqual(ic(iLostBlock), ['items']);
    assert.match(iLostBlock.problems[0][1], /^9 of 10 item blocks rendered as the sheet says: toggle-open-bg is not in the page/);
    assert.strictEqual(iLostBlock.itemsChecked, 0, 'a page with a block missing checks nothing else');
    const shortAcc = await runItems(itemPageHtml({ shortAccordion: 'accordion-surface' }));
    assert.match(shortAcc.problems[0][1], /accordion-surface renders 2 items, expected 3/);
    const iWrongBorder = await runItems(itemPageHtml({ extraCss: '[data-s="accordion-surface"] .et_pb_toggle{border-left-width:3px}' }));
    assert.ok(ic(iWrongBorder).includes('item-border') && ic(iWrongBorder).every((c) => ['item-border', 'item-contrast'].includes(c)), ic(iWrongBorder).join());
    assert.match(iWrongBorder.problems.find((x) => x[0] === 'item-border')[1], /^accordion-surface item 1 rest: border-left-width 3px, expected 1px/);
    const iWrongRadius = await runItems(itemPageHtml({ extraCss: '[data-s="toggle-closed-surface"]{border-bottom-left-radius:9px}' }));
    assert.deepStrictEqual(ic(iWrongRadius), ['item-radius']);
    const iWrongFill = await runItems(itemPageHtml({ extraCss: '[data-s="toggle-closed-bg"]{background:#e0e0e0 !important}' }));
    assert.ok(ic(iWrongFill).includes('item-fill'), ic(iWrongFill).join());
    assert.match(iWrongFill.problems.find((x) => x[0] === 'item-fill')[1], /^toggle-closed-bg item 1 rest: background-color rgb\(224, 224, 224\)/);
    const iWrongTitle = await runItems(itemPageHtml({ extraCss: '[data-s="accordion-accent"] .et_pb_toggle_close > .et_pb_toggle_title{color:#445566 !important}' }));
    assert.ok(ic(iWrongTitle).includes('item-title'), ic(iWrongTitle).join());
    const iWrongBody = await runItems(itemPageHtml({ extraCss: '[data-s="accordion-dark"] .et_pb_toggle_content p{color:#b0b0b0 !important}' }));
    assert.ok(ic(iWrongBody).includes('item-body') && ic(iWrongBody).includes('item-contrast'), 'a grey answer on the item fill: ' + ic(iWrongBody).join());
    const iNoIcon = await runItems(itemPageHtml({ extraCss: '[data-s="toggle-item-surface"].et_pb_toggle_close > .et_pb_toggle_title::before{content:none !important}' }));
    assert.deepStrictEqual(ic(iNoIcon), ['item-icon']);
    assert.match(iNoIcon.problems[0][1], /no icon is drawn on the closed item/);
    const iWrongIcon = await runItems(itemPageHtml({ extraCss: '[data-s="accordion-bg"] .et_pb_toggle_close > .et_pb_toggle_title::before{color:#ff0000 !important}' }));
    assert.ok(ic(iWrongIcon).includes('item-icon'), ic(iWrongIcon).join());
    const iShadowed = await runItems(itemPageHtml({ extraCss: '[data-s="toggle-open-surface"]{box-shadow:0 4px 8px rgba(0,0,0,.3) !important}' }));
    assert.ok(ic(iShadowed).includes('item-shadow'), 'a rule row with a shadow: ' + ic(iShadowed).join());
    const iWrongPad = await runItems(itemPageHtml({ extraCss: '[data-s="toggle-item-bg"]{padding-left:10px}' }));
    assert.deepStrictEqual(ic(iWrongPad), ['item-padding']);
    // Contrast on its own: the page agrees with its (pale) expectations, so only the painted-fill rule can object.
    const paleExps = clone(itemExpects); paleExps[0].closed.title = '#d8d8d8'; paleExps[0].open.title = '#d8d8d8';
    const paleTitle = await runItems(itemPageHtml({ exps: paleExps }), { exps: paleExps });
    assert.ok(ic(paleTitle).length > 0 && ic(paleTitle).every((c) => c === 'item-contrast'), 'pale titles that match the markup are still unreadable: ' + ic(paleTitle).join());
    assert.match(paleTitle.problems[0][1], /^accordion-bg item 1 title colour #d8d8d8 on the painted item fill/);
    assert.ok(paleTitle.problems.some(([, d]) => /\(after click\)/.test(d)), 'the contrast is judged again on the states after the click');

    // The click: nothing happens, the open item stays open, the answer stays hidden, the fill stays closed.
    const dead = await runItems(itemPageHtml({ noClick: ['toggle-closed-surface'] }));
    assert.deepStrictEqual(ic(dead), ['item-click']);
    assert.match(dead.problems[0][1], /^toggle-closed-surface item 1 \(Rule toggle closed on surface\) did not open after a click on its title/);
    const stays = await runItems(itemPageHtml({ noClose: ['accordion-dark'] }));
    assert.deepStrictEqual(ic(stays), ['item-click']);
    assert.match(stays.problems[0][1], /^accordion-dark item 1 \(previously open\) did not close when item 2 opened/);
    const hidden = await runItems(itemPageHtml({ extraCss: '[data-s="toggle-closed-bg"].et_pb_toggle_open .et_pb_toggle_content{display:none}' }));
    assert.ok(ic(hidden).includes('item-click') && hidden.problems.some(([, d]) => /is open but its answer is not shown after the click/.test(d)), 'an item that opens but whose answer stays hidden: ' + ic(hidden).join());
    const stuckFill = await runItems(itemPageHtml({ stuck: ['toggle-closed-bg'] }));
    assert.deepStrictEqual(ic(stuckFill).filter((c) => c !== 'item-title' && c !== 'item-fill'), [], ic(stuckFill).join());
    assert.ok(stuckFill.problems.some(([c, d]) => c === 'item-fill' && /^toggle-closed-bg item 1 after click: background-color .*expected #fff3d6 \(open item\)/.test(d)), 'a state fill that stays closed after the click: ' + JSON.stringify(stuckFill.problems));
    // Overflow covers the items; a fixed bar scrolled over an item does not disturb the painted-fill sample or the click.
    const wideItem = await runItems(itemPageHtml({ extraCss: '[data-s="accordion-bg"]{width:900px}' }), { width: 400 });
    assert.ok(ic(wideItem).includes('overflow'), 'an accordion wider than the phone: ' + ic(wideItem).join());
    const iBar = await runItems(itemPageHtml({ extraCss: '#bar{position:fixed;top:0;left:0;right:0;height:32px;background:#23282d;z-index:9}' }), { before: (page) => page.evaluate(() => { const b = document.createElement('div'); b.id = 'bar'; document.body.appendChild(b); const h = [...document.querySelectorAll('.et_pb_toggle_title')].find((n) => n.textContent.trim() === 'Accordion on accent one'); window.scrollTo(0, h.closest('.et_pb_toggle').getBoundingClientRect().top + window.scrollY - 5); }) });
    assert.deepStrictEqual(iBar.problems, [], JSON.stringify(iBar.problems));
    const emptyPage = await runItems(itemPageHtml({ drop: K.ITEM_SLOTS.map((s) => s.key) }));
    assert.deepStrictEqual(ic(emptyPage), ['items'], 'a page with no item at all fails');

    console.log('OK kit-card-run and kit-item-run in a real Chromium on synthetic pages: a matching page passes at 1280 and 400 (items: a real click opens a closed item and closes the open one), every departure is caught (and a fixed bar over a card or an item is not mistaken for one) with its code');
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });
