#!/usr/bin/env node
'use strict';
/* Pure tests of scripts/lib/kit-checks.cjs (no site, no browser): run titles, cell selection, the sheet template, expectations read from
 * resolved markup (samples are real KitResolver + RecipeTokens output for editorial), the expected-vs-computed comparison (rest and hover),
 * shadows, lift, contrast on the painted surface, overflow and the PNG decoder, and for the CARDS (card/panel on a group and on a column) the
 * same set: the sheet, expectations read from the resolved block tree, the rest/hover comparison (a panel must not change), the clip, the
 * contrast on the measured card fill, overflow, the three-column row, the zero-card rule, the grounds (the painted fill against the resolved
 * one), the kit buttons on the inverted panel, the featured card's prominence, the IMAGE (the media-radius token: the sheet, the four corners read from
 * the resolved markup, the radius on the wrapper or the img, clip, overflow, count), the ITEMS (accordion and toggle: the sheet, expectations per
 * state read from the resolved markup, the comparison, the count, the click, the contrast on the painted fill) and an OFFLINE run of the whole sheet through the
 * real PHP pipeline (scripts/lib/kit-sheet-offline.php: every kit x every language, zero leftovers, LayoutGate valid). Run first by
 * `make verify-kits`. */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const { spawnSync } = require('child_process');
const K = require('./lib/kit-checks.cjs');

const ROOT = path.resolve(__dirname, '..');
let n = 0;
const t = (name, fn) => { fn(); n++; };

// ---- run titles ----
t('run title round trip', () => {
  const title = K.runTitle('123-abcdef', 'neo-brutal', 'glass-dark');
  assert.strictEqual(title, 'aied kit 123-abcdef neo-brutal glass-dark');
  assert.deepStrictEqual(K.parseRunTitle(title), { token: '123-abcdef', kit: 'neo-brutal', language: 'glass-dark' });
  assert.ok(title.startsWith(K.TITLE_PREFIX));
  assert.strictEqual(K.parseRunTitle('aied recipe 123-abcdef hero-cta editorial'), null, 'a recipe page is not a kit page');
  assert.strictEqual(K.parseRunTitle('aied kit 123-abcdef neo-brutal'), null, 'no language');
  assert.strictEqual(K.parseRunTitle('aied kit 123-ABCDEF neo-brutal editorial'), null, 'token is lower-case hex');
  assert.strictEqual(K.parseRunTitle('My page'), null);
});

// ---- selection ----
const kitIds = JSON.parse(fs.readFileSync(path.join(ROOT, 'wp-plugin', 'data', 'style-kits.json'), 'utf8')).map((k) => k.id);
const languages = JSON.parse(fs.readFileSync(path.join(ROOT, 'wp-plugin', 'data', 'design-languages.json'), 'utf8')).map((l) => ({ id: l.id, kit: l.kit }));
t('default selection: every kit under editorial and glass-dark, plus every language under its own kit, once', () => {
  const cells = K.selectCells({}, kitIds, languages);
  const extra = languages.filter((l) => !['editorial', 'glass-dark'].includes(l.id)).length;
  assert.strictEqual(cells.length, kitIds.length * 2 + extra);
  for (const k of kitIds) for (const l of ['editorial', 'glass-dark']) assert.ok(cells.some(([a, b]) => a === k && b === l), k + '/' + l);
  for (const l of languages) assert.ok(cells.some(([a, b]) => a === l.kit && b === l.id), 'own kit of ' + l.id);
  assert.strictEqual(new Set(cells.map((c) => c.join('/'))).size, cells.length, 'no duplicate cell');
});
t('flags narrow the selection', () => {
  assert.deepStrictEqual(K.selectCells({ kits: ['neo-brutal'] }, kitIds, languages), [['neo-brutal', 'editorial'], ['neo-brutal', 'glass-dark']]);
  assert.strictEqual(K.selectCells({ languages: ['luxe'] }, kitIds, languages).length, kitIds.length);
  assert.deepStrictEqual(K.selectCells({ kits: ['neo-brutal', 'quiet-solid'], languages: ['luxe'] }, kitIds, languages), [['neo-brutal', 'luxe'], ['quiet-solid', 'luxe']]);
  assert.throws(() => K.selectCells({ kits: ['nope'] }, kitIds, languages), /Unknown kit/);
  assert.throws(() => K.selectCells({ languages: ['default'] }, kitIds, languages), /Unknown language/);
});

// ---- the sheet ----
t('the sheet carries 8 marked page-level buttons, surfaces in order (and 4 more inside the button panels)', () => {
  const m = K.sheetMarkup();
  const attrs = K.pageButtonAttrs(m);
  assert.strictEqual(K.buttonAttrs(m).length, 12, '8 page-level buttons and 2 in each of the 2 button panels');
  assert.strictEqual(attrs.length, 8);
  assert.strictEqual(K.SLOTS.length, 8);
  attrs.forEach((a, i) => {
    const s = K.SLOTS[i];
    assert.deepStrictEqual(a.aied, s.on === 'light' ? { kit: s.role } : { kit: s.role, on: s.on }, 'marker of slot ' + i);
  });
  assert.strictEqual((m.match(/<!-- wp:divi\/section /g) || []).length, 5, 'four bands and the three-column row');
  assert.ok(m.includes('{{aied:image:section-bg-slate-1}}') && m.includes('"overlaysImage":"on"'), 'the photo band has an image under a scrim');
});
t('the sheet carries 20 marked cards: a card and a panel on each band, an image-first panel, the grounds and the button panels on the two light bands, and a row of three column cards with an accent middle one', () => {
  const m = K.sheetMarkup();
  assert.deepStrictEqual(K.CARD_SLOTS.map((c) => c.key), ['card-bg', 'panel-bg', 'panel-clip', 'inverted-card-bg', 'inverted-panel-bg', 'accent-tile-bg', 'panel-buttons-bg', 'card-surface', 'panel-surface', 'inverted-card-surface', 'inverted-panel-surface', 'accent-tile-surface', 'panel-buttons-surface', 'card-accent', 'panel-accent', 'card-dark', 'panel-dark', 'column-1', 'column-2', 'column-3']);
  assert.strictEqual(K.CARD_SLOTS.length, 20);
  assert.strictEqual(new Set(K.CARD_SLOTS.map((c) => c.heading)).size, K.CARD_SLOTS.length, 'every card is found by a unique heading');
  const markers = [];
  const walk = (nodes) => {
    for (const n of nodes) {
      if (n.attrs.aied && ['card', 'panel'].includes(n.attrs.aied.kit) && (n.name === 'divi/group' || n.name === 'divi/column')) {
        const heading = n.children.find((x) => x.name === 'divi/heading');
        markers.push([n.name, n.attrs.aied, K.CARD_SLOTS.find((c) => heading && c.heading === heading.attrs.title.innerContent.desktop.value)]);
      }
      walk(n.children);
    }
  };
  walk(K.blockTree(m));
  assert.strictEqual(markers.length, 20);
  markers.forEach(([name, marker, slot]) => {
    assert.ok(slot, 'a marked block holds the heading of a slot');
    assert.strictEqual(name, 'divi/' + slot.carrier);
    const want = { kit: slot.role };
    if (slot.on !== 'light') want.on = slot.on;
    if (slot.ground) want.ground = slot.ground;
    assert.deepStrictEqual(marker, want, slot.key);
  });
  assert.deepStrictEqual(K.CARD_SLOTS.map((c) => c.on), ['light', 'light', 'light', 'light', 'light', 'light', 'light', 'surface', 'surface', 'surface', 'surface', 'surface', 'surface', 'accent', 'accent', 'dark', 'dark', 'light', 'light', 'light']);
  assert.deepStrictEqual(K.CARD_SLOTS.map((c) => c.ground), [null, null, null, 'inverted', 'inverted', 'accent', 'inverted', null, null, 'inverted', 'inverted', 'accent', 'inverted', null, null, null, null, null, 'accent', null]);
  assert.deepStrictEqual(K.CARD_SLOTS.filter((c) => c.buttons).map((c) => [c.key, c.buttons, c.role]), [['panel-buttons-bg', 2, 'panel'], ['panel-buttons-surface', 2, 'panel']]);
  assert.deepStrictEqual(K.CARD_SLOTS.filter((c) => c.featured).map((c) => c.key), ['column-2'], 'the accent middle card is the featured one');
  assert.ok(K.CARD_SLOTS.filter((c) => c.ground).every((c) => (c.ground === 'inverted' ? c.inkHeading === 'bg' && c.inkPara === 'bg' : c.inkHeading === 'accent-text' && c.inkPara === 'accent-text')), 'inks: bg on an inverted ground, accent-text on an accent one');
  assert.ok(K.CARD_SLOTS.filter((c) => !c.ground).every((c) => c.inkHeading === 'text' && c.inkPara === 'muted'));
  // A panel keeps its own padding (no kit key); the clip panel has none, so its image touches the corners; a card has no padding literal.
  const bySlot = Object.fromEntries(K.cardAttrs(m).map((c) => [c.slot.key, c.attrs]));
  assert.deepStrictEqual(bySlot['panel-dark'].module.decoration.spacing.desktop.value.padding, { top: '40px', right: '40px', bottom: '40px', left: '40px' });
  assert.deepStrictEqual(bySlot['inverted-panel-bg'].module.decoration.spacing.desktop.value.padding, { top: '40px', right: '40px', bottom: '40px', left: '40px' });
  assert.deepStrictEqual(bySlot['panel-clip'].module.decoration.spacing.desktop.value.padding, { top: '0px', right: '0px', bottom: '0px', left: '0px' });
  assert.strictEqual(bySlot['card-bg'].module.decoration.spacing, undefined, 'the kit gives a card its padding');
  assert.strictEqual(bySlot['accent-tile-surface'].module.decoration.spacing, undefined, 'an accent tile is a card: the kit gives it its padding');
  assert.strictEqual(K.CARD_SLOTS.filter((c) => c.image).map((c) => c.key).join(), 'card-bg,panel-clip', 'the bg-band card and the clip panel hold an image');
  assert.strictEqual((m.match(/wp:divi\/image /g) || []).length, 3, 'the two card images and the one media-radius image of the image slot');
  assert.ok(m.includes('"columnStructure":{"desktop":{"value":"1_3,1_3,1_3"}}') && m.includes('equal-columns_3'), 'the three-column row is the real proof-press-quotes shape');
  assert.strictEqual(K.cardAttrs(m).filter((c) => c.attrs).length, 20, 'every card is found again by its heading');
  assert.strictEqual(K.cardAttrs(m).find((c) => c.slot.key === 'column-2').attrs.module.advanced.type.desktop.value, '1_3');
  // The ink tokens of the children: heading and paragraph of an inverted ground are bg, of an accent ground accent-text; plain ones text / muted.
  const inks = (key) => { const hit = K.cardAttrs(m, K.CARD_SLOTS).find((c) => c.slot.key === key); return hit.inks; };
  assert.deepStrictEqual(inks('inverted-card-bg'), { heading: '{{aied:color:bg}}', paragraph: '{{aied:color:bg}}' });
  assert.deepStrictEqual(inks('accent-tile-surface'), { heading: '{{aied:color:accent-text}}', paragraph: '{{aied:color:accent-text}}' });
  assert.deepStrictEqual(inks('column-2'), { heading: '{{aied:color:accent-text}}', paragraph: '{{aied:color:accent-text}}' });
  assert.deepStrictEqual(inks('column-1'), { heading: '{{aied:color:text}}', paragraph: '{{aied:color:muted}}' });
});
t('the button panels hold a primary and a secondary button marked on:"panel"; the page-level buttons are not counted with them', () => {
  const m = K.sheetMarkup();
  const hits = K.cardAttrs(m).filter((c) => c.slot.buttons);
  assert.strictEqual(hits.length, 2);
  for (const h of hits) {
    assert.deepStrictEqual(h.buttons.map((b) => b.aied), [{ kit: 'control', on: 'panel' }, { kit: 'control-secondary', on: 'panel' }], h.slot.key);
  }
  assert.ok(K.cardAttrs(m).filter((c) => !c.slot.buttons).every((c) => c.buttons.length === 0), 'no other card holds a button');
  assert.deepStrictEqual(K.pageButtonAttrs(m).map((b) => b.aied.on || 'light'), ['light', 'light', 'light', 'light', 'accent', 'accent', 'dark', 'dark']);
});

// ---- expectations from resolved markup (real adapter output, editorial) ----
const UNDERLINE_ACCENT = '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"P"}}},"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"},"hover":{"color":"#ffffff"}}},"border":{"desktop":{"value":{"radius":{"topLeft":"0px","topRight":"0px","bottomRight":"0px","bottomLeft":"0px"},"styles":{"all":{"width":"0px","style":"solid","color":"#1c1a17"},"bottom":{"width":"2px","style":"solid","color":"#1c1a17"}}},"hover":{"styles":{"all":{"color":"#1c1a17"}}}}},"font":{"font":{"desktop":{"value":{"color":"#1c1a17","capitalization":"none","letterSpacing":"0px","weight":"600"},"hover":{"color":"#1c1a17"}}}}}},"builderVersion":"5.8.0","module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"10px","right":"20px","bottom":"10px","left":"20px"}}}}}}} /-->';
const SOFT_LIFT_ACCENT = '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"P"}}},"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"},"hover":{"color":"#ffffff"}}},"border":{"desktop":{"value":{"radius":{"topLeft":"12px","topRight":"12px","bottomRight":"12px","bottomLeft":"12px"},"styles":{"all":{"width":"0px","style":"solid","color":"#ffffff"}}},"hover":{"styles":{"all":{"color":"#ffffff"}}}}},"font":{"font":{"desktop":{"value":{"color":"#1c1a17","capitalization":"none","letterSpacing":"0px","weight":"600"},"hover":{"color":"#1c1a17"}}}}}},"builderVersion":"5.8.0","module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"14px","right":"28px","bottom":"14px","left":"28px"}}}},"boxShadow":{"desktop":{"hover":{"style":"preset1","vertical":"12px","blur":"24px","color":"rgba(28,22,16,0.25)"}}},"transform":{"desktop":{"hover":{"translate":{"y":"-4px"}}}}}}} /-->';
const DOUBLE_FRAME_LIGHT = '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"P"}}},"decoration":{"background":{"desktop":{"value":{"color":"rgba(255,255,255,0)"},"hover":{"color":"#1c1a17"}}},"border":{"desktop":{"value":{"radius":{"topLeft":"0px","topRight":"0px","bottomRight":"0px","bottomLeft":"0px"},"styles":{"all":{"width":"4px","style":"double","color":"#1c1a17"}}},"hover":{"styles":{"all":{"color":"#1c1a17"}}}}},"font":{"font":{"desktop":{"value":{"color":"#1c1a17","capitalization":"uppercase","letterSpacing":"2px","weight":"600"},"hover":{"color":"#faf7f2"}}}}}},"builderVersion":"5.8.0","module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"14px","right":"30px","bottom":"14px","left":"30px"}}}}}}} /-->';

t('expectations: an underline (bottom side only) and its rest values', () => {
  const [a] = K.buttonAttrs(UNDERLINE_ACCENT);
  const e = K.expectFromAttrs(a);
  assert.deepStrictEqual(e.rest.top, { width: 0, style: 'solid', color: '#1c1a17' });
  assert.deepStrictEqual(e.rest.bottom, { width: 2, style: 'solid', color: '#1c1a17' });
  assert.deepStrictEqual(e.rest.radius, [0, 0, 0, 0]);
  assert.strictEqual(e.rest.fill, '#ffffff');
  assert.strictEqual(e.rest.label, '#1c1a17');
  assert.strictEqual(e.rest.capitalization, 'none');
  assert.strictEqual(e.rest.letterSpacing, 0);
  assert.deepStrictEqual(e.rest.padding, [10, 20, 10, 20]);
  assert.deepStrictEqual(e.shadow, { rest: null, hover: null });
  assert.strictEqual(e.lift, null);
});
t('expectations: a lift kit with a hover-only soft shadow', () => {
  const e = K.expectFromAttrs(K.buttonAttrs(SOFT_LIFT_ACCENT)[0]);
  assert.strictEqual(e.lift, -4);
  assert.strictEqual(e.shadow.rest, null, 'no shadow at rest');
  assert.deepStrictEqual(e.shadow.hover, { x: null, y: 12, blur: 24, color: 'rgba(28,22,16,0.25)', style: 'preset1' });
  assert.deepStrictEqual(e.rest.radius, [12, 12, 12, 12]);
});
t('hover expectations: a transparent outline fills on hover (fill-swap)', () => {
  const e = K.expectFromAttrs(K.buttonAttrs(DOUBLE_FRAME_LIGHT)[0]);
  assert.strictEqual(e.rest.fill, 'rgba(255,255,255,0)');
  assert.deepStrictEqual(e.hover, { fill: '#1c1a17', label: '#faf7f2', border: '#1c1a17' });
  assert.deepStrictEqual(e.rest.top, { width: 4, style: 'double', color: '#1c1a17' });
  assert.strictEqual(e.rest.capitalization, 'uppercase');
  assert.strictEqual(e.rest.letterSpacing, 2);
});

t('path drift: every field the adapter always writes is required; shadow and lift stay optional', () => {
  for (const sample of [UNDERLINE_ACCENT, SOFT_LIFT_ACCENT, DOUBLE_FRAME_LIGHT]) assert.deepStrictEqual(K.missingExpectations(K.expectFromAttrs(K.buttonAttrs(sample)[0])), [], 'a full real sample has nothing missing');
  const full = () => K.expectFromAttrs(K.buttonAttrs(DOUBLE_FRAME_LIGHT)[0]);
  assert.strictEqual(full().shadow.rest, null, 'no shadow and no lift is not missing');
  assert.strictEqual(full().lift, null);
  const cases = {
    'rest.fill': (e) => { e.rest.fill = null; }, 'rest.label': (e) => { e.rest.label = null; }, 'rest.capitalization': (e) => { e.rest.capitalization = null; },
    'rest.weight': (e) => { e.rest.weight = null; }, 'rest.letterSpacing': (e) => { e.rest.letterSpacing = null; }, 'rest.padding': (e) => { e.rest.padding = null; },
    'rest.radius': (e) => { e.rest.radius[2] = null; }, 'rest.top.width': (e) => { e.rest.top.width = null; }, 'rest.top.style': (e) => { e.rest.top.style = null; },
    'rest.top.color': (e) => { e.rest.top.color = null; }, 'rest.bottom.width': (e) => { e.rest.bottom.width = null; }, 'rest.bottom.style': (e) => { e.rest.bottom.style = null; },
    'rest.bottom.color': (e) => { e.rest.bottom.color = null; }, 'hover.fill': (e) => { e.hover.fill = null; }, 'hover.label': (e) => { e.hover.label = null; }, 'hover.border': (e) => { e.hover.border = null; },
  };
  for (const [field, drop] of Object.entries(cases)) { const e = full(); drop(e); assert.deepStrictEqual(K.missingExpectations(e), [field], field + ' missing is reported'); }
  // A drifted path in the markup itself: no styles.all width, no padding, no hover font colour.
  const drifted = JSON.parse(JSON.stringify(K.buttonAttrs(DOUBLE_FRAME_LIGHT)[0]));
  delete drifted.button.decoration.border.desktop.value.styles.all.width;
  delete drifted.module.decoration.spacing;
  delete drifted.button.decoration.font.font.desktop.hover;
  assert.deepStrictEqual(K.missingExpectations(K.expectFromAttrs(drifted)), ['rest.padding', 'rest.top.width', 'rest.bottom.width', 'hover.label']);
  assert.deepStrictEqual(K.missingExpectations(K.expectFromAttrs({})).length, 16, 'an empty attribute object misses everything');
});
t('an empty selection is an error, never zero cells', () => {
  const noKit = languages.map((l) => ({ id: l.id }));
  assert.throws(() => K.selectCells({}, [], noKit), (e) => e.empty === true && /No kit\/language cell selected/.test(e.message));
  assert.throws(() => K.selectCells({}, [], languages), /names an unknown default kit/, 'a language whose kit is not in the kit list is a data error');
  assert.throws(() => K.selectCells({}, kitIds, []), /Sheet language\(s\) not in the design languages: editorial, glass-dark/);
});

// ---- comparison ----
const style = (o) => Object.assign({
  borderTopWidth: '4px', borderTopStyle: 'double', borderTopColor: 'rgb(28, 26, 23)', borderBottomWidth: '4px', borderBottomStyle: 'double', borderBottomColor: 'rgb(28, 26, 23)',
  borderTopLeftRadius: '0px', borderTopRightRadius: '0px', borderBottomRightRadius: '0px', borderBottomLeftRadius: '0px',
  backgroundColor: 'rgba(255, 255, 255, 0)', color: 'rgb(28, 26, 23)', textTransform: 'uppercase', letterSpacing: '2px', fontWeight: '600',
  paddingTop: '14px', paddingRight: '30px', paddingBottom: '14px', paddingLeft: '30px', boxShadow: 'none', transform: 'none',
}, o);
const plain = { boxShadow: 'none', transform: 'none' };
const dbl = K.expectFromAttrs(K.buttonAttrs(DOUBLE_FRAME_LIGHT)[0]);
const hoverOk = { el: style({ backgroundColor: 'rgb(28, 26, 23)', color: 'rgb(250, 247, 242)' }), wrap: plain };
t('a matching button has no problem', () => {
  assert.deepStrictEqual(K.compareButton(dbl, { rest: { el: style({}), wrap: plain }, hover: hoverOk }).problems, []);
});
t('a wrong computed border width FAILS', () => {
  const p = K.compareButton(dbl, { rest: { el: style({ borderTopWidth: '2px' }), wrap: plain }, hover: hoverOk }).problems;
  assert.strictEqual(p.length, 1);
  assert.strictEqual(p[0][0], 'border');
  assert.match(p[0][1], /border-top-width 2px, expected 4px/);
  const half = K.compareButton(dbl, { rest: { el: style({ borderTopWidth: '4.5px' }), wrap: plain }, hover: hoverOk }).problems;
  assert.deepStrictEqual(half.map((x) => x[0]), ['border'], 'half a pixel off is a failure (tolerance 0.25px)');
  assert.deepStrictEqual(K.compareButton(dbl, { rest: { el: style({ borderTopWidth: '4.2px' }), wrap: plain }, hover: hoverOk }).problems, [], 'a fifth of a pixel is within the tolerance');
});
t('wrong style, colour, radius, fill, label, transform, spacing, weight are each reported', () => {
  const codes = (o) => K.compareButton(dbl, { rest: { el: style(o), wrap: plain }, hover: hoverOk }).problems.map((x) => x[0]);
  assert.deepStrictEqual(codes({ borderBottomStyle: 'solid' }), ['border']);
  assert.deepStrictEqual(codes({ borderTopColor: 'rgb(0, 0, 0)' }), ['border']);
  assert.deepStrictEqual(codes({ borderTopLeftRadius: '3px' }), ['radius']);
  assert.deepStrictEqual(codes({ backgroundColor: 'rgb(255, 255, 255)' }), ['fill']);
  assert.deepStrictEqual(codes({ color: 'rgb(255, 0, 0)' }), ['label']);
  assert.deepStrictEqual(codes({ textTransform: 'none' }), ['text']);
  assert.deepStrictEqual(codes({ letterSpacing: 'normal' }), ['text']);
  assert.deepStrictEqual(codes({ fontWeight: '400' }), ['text']);
  assert.deepStrictEqual(codes({ paddingLeft: '20px' }), ['padding']);
});
t('hover: a button that does not fill on hover FAILS', () => {
  const p = K.compareButton(dbl, { rest: { el: style({}), wrap: plain }, hover: { el: style({}), wrap: plain } }).problems;
  assert.deepStrictEqual(p.map((x) => x[0]), ['hover', 'hover']);
  assert.match(p[0][1], /hover: background-color rgba\(255, 255, 255, 0\), expected #1c1a17/);
});
t('an unexpected shadow or movement FAILS', () => {
  const p = K.compareButton(dbl, { rest: { el: style({ boxShadow: 'rgb(0, 0, 0) 6px 6px 0px 0px' }), wrap: plain }, hover: { el: hoverOk.el, wrap: { boxShadow: 'none', transform: 'matrix(1, 0, 0, 1, 0, -4)' } } }).problems;
  assert.deepStrictEqual(p.map((x) => x[0]), ['shadow', 'lift']);
});
const lift = K.expectFromAttrs(K.buttonAttrs(SOFT_LIFT_ACCENT)[0]);
const liftRest = (o) => style(Object.assign({ borderTopWidth: '0px', borderBottomWidth: '0px', borderTopStyle: 'solid', borderBottomStyle: 'solid', borderTopColor: 'rgb(255, 255, 255)', borderBottomColor: 'rgb(255, 255, 255)', borderTopLeftRadius: '12px', borderTopRightRadius: '12px', borderBottomRightRadius: '12px', borderBottomLeftRadius: '12px', backgroundColor: 'rgb(255, 255, 255)', textTransform: 'none', letterSpacing: '0px', paddingTop: '14px', paddingRight: '28px', paddingBottom: '14px', paddingLeft: '28px' }, o));
t('lift and a hover-only shadow: pass on the button or its wrapper, fail when missing', () => {
  const hov = { el: liftRest({ boxShadow: 'rgba(28, 22, 16, 0.25) 0px 12px 24px 0px', transform: 'matrix(1, 0, 0, 1, 0, -4)' }), wrap: plain };
  const ok = K.compareButton(lift, { rest: { el: liftRest({}), wrap: plain }, hover: hov });
  assert.deepStrictEqual(ok.problems, []);
  assert.deepStrictEqual(ok.shadowWhere, { rest: null, hover: 'button' });
  const onWrap = K.compareButton(lift, { rest: { el: liftRest({}), wrap: plain }, hover: { el: liftRest({}), wrap: { boxShadow: 'rgba(28, 22, 16, 0.25) 0px 12px 24px 0px', transform: 'matrix(1, 0, 0, 1, 0, -4)' } } });
  assert.deepStrictEqual(onWrap.problems, []);
  assert.strictEqual(onWrap.shadowWhere.hover, 'wrapper');
  const none = K.compareButton(lift, { rest: { el: liftRest({}), wrap: plain }, hover: { el: liftRest({}), wrap: plain } }).problems.map((x) => x[0]);
  assert.deepStrictEqual(none, ['shadow', 'lift']);
  const hard = K.compareButton(lift, { rest: { el: liftRest({}), wrap: plain }, hover: { el: liftRest({ boxShadow: 'rgba(28, 22, 16, 0.25) 0px 12px 0px 0px', transform: 'matrix(1, 0, 0, 1, 0, -4)' }), wrap: plain } }).problems;
  assert.match(hard[0][1], /expected a blurred shadow/, 'a zero blur where the kit asks for a soft shadow');
  const moved = K.compareButton(lift, { rest: { el: liftRest({ transform: 'matrix(1, 0, 0, 1, 0, -4)' }), wrap: plain }, hover: hov }).problems.map((x) => x[0]);
  assert.deepStrictEqual(moved, ['lift'], 'moved at rest');
});
t('a hard shadow must have zero blur and the offsets the markup gives', () => {
  const e = { rest: { top: { width: 0 }, bottom: { width: 0 }, radius: [null, null, null, null], fill: null, label: null, capitalization: null, letterSpacing: null, weight: null, padding: null }, hover: {}, shadow: { rest: { x: 6, y: 6, blur: 0, color: 'rgb(28,26,23)' }, hover: null }, lift: null };
  e.shadow.hover = e.shadow.rest;
  const s = (v) => K.compareButton(e, { rest: { el: style({ borderTopWidth: '0px', borderBottomWidth: '0px', boxShadow: v }), wrap: plain }, hover: null }).problems.map((x) => x[1]);
  assert.deepStrictEqual(s('rgb(28, 26, 23) 6px 6px 0px 0px'), []);
  assert.match(s('rgb(28, 26, 23) 6px 6px 4px 0px')[0], /expected a hard shadow/);
  assert.match(s('rgb(28, 26, 23) 0px 4px 0px 0px')[0], /x offset 0px, expected 6px/);
  assert.match(s('none')[0], /expected a shadow/);
});

const QUIET_CARD = "<!-- wp:divi/group {\"module\":{\"decoration\":{\"layout\":{\"desktop\":{\"value\":{\"flexDirection\":\"column\",\"rowGap\":\"12px\"}}},\"background\":{\"desktop\":{\"value\":{\"color\":\"#ffffff\"}}},\"border\":{\"desktop\":{\"value\":{\"radius\":{\"topLeft\":\"4px\",\"topRight\":\"4px\",\"bottomRight\":\"4px\",\"bottomLeft\":\"4px\"},\"styles\":{\"all\":{\"width\":\"1px\",\"style\":\"solid\",\"color\":\"#e3dccf\"}}}}},\"spacing\":{\"desktop\":{\"value\":{\"padding\":{\"top\":\"32px\",\"right\":\"32px\",\"bottom\":\"32px\",\"left\":\"32px\"}}}}}},\"builderVersion\":\"5.8.0\"} -->";
const QUIET_PANEL = "<!-- wp:divi/group {\"module\":{\"decoration\":{\"layout\":{\"desktop\":{\"value\":{\"flexDirection\":\"column\",\"rowGap\":\"12px\"}}},\"spacing\":{\"desktop\":{\"value\":{\"padding\":{\"top\":\"40px\",\"right\":\"40px\",\"bottom\":\"40px\",\"left\":\"40px\"}}}},\"background\":{\"desktop\":{\"value\":{\"color\":\"#ffffff\"}}},\"border\":{\"desktop\":{\"value\":{\"radius\":{\"topLeft\":\"4px\",\"topRight\":\"4px\",\"bottomRight\":\"4px\",\"bottomLeft\":\"4px\"},\"styles\":{\"all\":{\"width\":\"1px\",\"style\":\"solid\",\"color\":\"#e3dccf\"}}}}}}},\"builderVersion\":\"5.8.0\"} -->";
const UNDERLINE_CARD = "<!-- wp:divi/group {\"module\":{\"decoration\":{\"layout\":{\"desktop\":{\"value\":{\"flexDirection\":\"column\",\"rowGap\":\"12px\"}}},\"background\":{\"desktop\":{\"value\":{\"color\":\"#ffffff\"}}},\"border\":{\"desktop\":{\"value\":{\"radius\":{\"topLeft\":\"0px\",\"topRight\":\"0px\",\"bottomRight\":\"0px\",\"bottomLeft\":\"0px\"},\"styles\":{\"all\":{\"width\":\"0px\",\"style\":\"solid\",\"color\":\"#1c1a17\"},\"bottom\":{\"width\":\"2px\",\"style\":\"solid\",\"color\":\"#1c1a17\"}}}}},\"spacing\":{\"desktop\":{\"value\":{\"padding\":{\"top\":\"24px\",\"right\":\"24px\",\"bottom\":\"24px\",\"left\":\"24px\"}}}}}},\"builderVersion\":\"5.8.0\"} -->";
const LIFT_CARD = "<!-- wp:divi/group {\"module\":{\"decoration\":{\"layout\":{\"desktop\":{\"value\":{\"flexDirection\":\"column\",\"rowGap\":\"12px\"}}},\"background\":{\"desktop\":{\"value\":{\"color\":\"#ffffff\"}}},\"border\":{\"desktop\":{\"value\":{\"radius\":{\"topLeft\":\"16px\",\"topRight\":\"16px\",\"bottomRight\":\"16px\",\"bottomLeft\":\"16px\"},\"styles\":{\"all\":{\"width\":\"1px\",\"style\":\"solid\",\"color\":\"#e3dccf\"}}}}},\"spacing\":{\"desktop\":{\"value\":{\"padding\":{\"top\":\"28px\",\"right\":\"28px\",\"bottom\":\"28px\",\"left\":\"28px\"}}}},\"boxShadow\":{\"desktop\":{\"hover\":{\"style\":\"preset1\",\"vertical\":\"12px\",\"blur\":\"24px\",\"color\":\"rgba(28,22,16,0.25)\"}}},\"transform\":{\"desktop\":{\"hover\":{\"translate\":{\"y\":\"-4px\"}}}}}},\"builderVersion\":\"5.8.0\"} -->";
const SWAP_CARD = "<!-- wp:divi/group {\"module\":{\"decoration\":{\"layout\":{\"desktop\":{\"value\":{\"flexDirection\":\"column\",\"rowGap\":\"12px\"}}},\"background\":{\"desktop\":{\"value\":{\"color\":\"#faf7f2\"}}},\"border\":{\"desktop\":{\"value\":{\"radius\":{\"topLeft\":\"20px\",\"topRight\":\"20px\",\"bottomRight\":\"20px\",\"bottomLeft\":\"20px\"},\"styles\":{\"all\":{\"width\":\"1px\",\"style\":\"solid\",\"color\":\"#e3dccf\"}}},\"hover\":{\"styles\":{\"all\":{\"color\":\"#1c1a17\"}}}}},\"spacing\":{\"desktop\":{\"value\":{\"padding\":{\"top\":\"28px\",\"right\":\"28px\",\"bottom\":\"28px\",\"left\":\"28px\"}}}}}},\"builderVersion\":\"5.8.0\"} -->";
const HARD_PANEL = "<!-- wp:divi/group {\"module\":{\"decoration\":{\"layout\":{\"desktop\":{\"value\":{\"flexDirection\":\"column\",\"rowGap\":\"12px\"}}},\"spacing\":{\"desktop\":{\"value\":{\"padding\":{\"top\":\"40px\",\"right\":\"40px\",\"bottom\":\"40px\",\"left\":\"40px\"}}}},\"background\":{\"desktop\":{\"value\":{\"color\":\"#ffffff\"}}},\"border\":{\"desktop\":{\"value\":{\"radius\":{\"topLeft\":\"0px\",\"topRight\":\"0px\",\"bottomRight\":\"0px\",\"bottomLeft\":\"0px\"},\"styles\":{\"all\":{\"width\":\"3px\",\"style\":\"solid\",\"color\":\"#1c1a17\"}}}}},\"boxShadow\":{\"desktop\":{\"value\":{\"style\":\"preset4\",\"horizontal\":\"6px\",\"vertical\":\"6px\",\"blur\":\"0px\",\"color\":\"#1c1a17\"}}}}},\"builderVersion\":\"5.8.0\"} -->";
const HARD_CARD = "<!-- wp:divi/group {\"module\":{\"decoration\":{\"layout\":{\"desktop\":{\"value\":{\"flexDirection\":\"column\",\"rowGap\":\"12px\"}}},\"background\":{\"desktop\":{\"value\":{\"color\":\"#ffffff\"}}},\"border\":{\"desktop\":{\"value\":{\"radius\":{\"topLeft\":\"0px\",\"topRight\":\"0px\",\"bottomRight\":\"0px\",\"bottomLeft\":\"0px\"},\"styles\":{\"all\":{\"width\":\"3px\",\"style\":\"solid\",\"color\":\"#1c1a17\"}}}}},\"spacing\":{\"desktop\":{\"value\":{\"padding\":{\"top\":\"24px\",\"right\":\"24px\",\"bottom\":\"24px\",\"left\":\"24px\"}}}},\"boxShadow\":{\"desktop\":{\"value\":{\"style\":\"preset4\",\"horizontal\":\"6px\",\"vertical\":\"6px\",\"blur\":\"0px\",\"color\":\"#1c1a17\"}}},\"transform\":{\"desktop\":{\"hover\":{\"translate\":{\"y\":\"-4px\"}}}}}},\"builderVersion\":\"5.8.0\"} -->";

// ---- cards: expectations from resolved markup (real adapter + RecipeTokens output, editorial) ----
const attrsOf = (open) => K.blockTree(open + '\n<!-- /wp:divi/group -->')[0].attrs;
const exQuiet = K.expectCardFromAttrs(attrsOf(QUIET_CARD));
const exQuietPanel = K.expectCardFromAttrs(attrsOf(QUIET_PANEL));
t('card expectations: a hairline frame, four radii, the kit padding, no shadow and no hover', () => {
  assert.strictEqual(exQuiet.rest.fill, '#ffffff');
  for (const side of ['top', 'right', 'bottom', 'left']) assert.deepStrictEqual(exQuiet.rest[side], { width: 1, style: 'solid', color: '#e3dccf' }, side);
  assert.deepStrictEqual(exQuiet.rest.radius, [4, 4, 4, 4]);
  assert.deepStrictEqual(exQuiet.rest.padding, [32, 32, 32, 32]);
  assert.deepStrictEqual(exQuiet.shadow, { rest: null, hover: null });
  assert.strictEqual(exQuiet.hoverBorder, null);
  assert.strictEqual(exQuiet.lift, null);
  assert.deepStrictEqual(exQuiet.hoverKeys, []);
  assert.deepStrictEqual(K.missingCardExpectations(exQuiet, 'card'), []);
});
t('card expectations: a panel keeps the sheet\'s literal padding and carries no hover key', () => {
  assert.deepStrictEqual(exQuietPanel.rest.padding, [40, 40, 40, 40]);
  assert.deepStrictEqual(exQuietPanel.hoverKeys, []);
  assert.deepStrictEqual(K.missingCardExpectations(exQuietPanel, 'panel'), []);
});
const exUnder = K.expectCardFromAttrs(attrsOf(UNDERLINE_CARD));
t('card expectations: an underline card draws only its bottom side and has no hover frame', () => {
  assert.deepStrictEqual([exUnder.rest.top.width, exUnder.rest.right.width, exUnder.rest.bottom.width, exUnder.rest.left.width], [0, 0, 2, 0]);
  assert.strictEqual(exUnder.rest.bottom.color, '#1c1a17');
  assert.strictEqual(exUnder.hoverBorder, null);
  assert.deepStrictEqual(K.missingCardExpectations(exUnder, 'card'), []);
});
const exLift = K.expectCardFromAttrs(attrsOf(LIFT_CARD));
const exSwap = K.expectCardFromAttrs(attrsOf(SWAP_CARD));
const exHardPanel = K.expectCardFromAttrs(attrsOf(HARD_PANEL));
const exHardCard = K.expectCardFromAttrs(attrsOf(HARD_CARD));
t('card expectations: lift with a hover-only soft shadow, a hover frame colour, a hard shadow (panel keeps only the rest shadow)', () => {
  assert.strictEqual(exLift.lift, -4);
  assert.strictEqual(exLift.shadow.rest, null);
  assert.strictEqual(exLift.shadow.hover.y, 12);
  assert.strictEqual(exLift.shadow.hover.blur, 24);
  assert.ok(exLift.hoverKeys.includes('transform') && exLift.hoverKeys.includes('boxShadow.hover'));
  assert.strictEqual(exSwap.hoverBorder, '#1c1a17', 'a hairline frame darkens to text on hover');
  assert.deepStrictEqual(exSwap.rest.radius, [20, 20, 20, 20]);
  assert.strictEqual(exSwap.rest.fill, '#faf7f2', 'a card on a surface band is filled with bg');
  assert.strictEqual(exHardPanel.shadow.rest.blur, 0);
  assert.strictEqual(exHardPanel.shadow.rest.x, 6);
  assert.deepStrictEqual(exHardPanel.hoverKeys, []);
  assert.deepStrictEqual(K.missingCardExpectations(exHardPanel, 'panel'), []);
  assert.strictEqual(exHardCard.rest.top.width, 3);
});
t('card path drift: every field the adapter always writes is required, so a null never silently skips a check', () => {
  const full = () => JSON.parse(JSON.stringify(exQuiet));
  const cases = {
    'rest.fill': (e) => { e.rest.fill = null; }, 'rest.padding': (e) => { e.rest.padding = null; }, 'rest.radius': (e) => { e.rest.radius[3] = null; },
    'rest.top.width': (e) => { e.rest.top.width = null; }, 'rest.right.style': (e) => { e.rest.right.style = null; }, 'rest.bottom.color': (e) => { e.rest.bottom.color = null; },
    'rest.left.width': (e) => { e.rest.left.width = NaN; },
  };
  for (const [field, drop] of Object.entries(cases)) { const e = full(); drop(e); assert.deepStrictEqual(K.missingCardExpectations(e, 'card'), [field], field + ' missing is reported'); }
  // a panel with a hover key is an adapter fault
  const p = JSON.parse(JSON.stringify(exQuietPanel)); p.hoverKeys = ['transform', 'border.hover'];
  assert.deepStrictEqual(K.missingCardExpectations(p, 'panel'), ['panel carries transform', 'panel carries border.hover']);
  assert.deepStrictEqual(K.missingCardExpectations(p, 'card'), [], 'the same keys are fine on a card');
  // a drifted path in the markup itself
  const drifted = JSON.parse(JSON.stringify(attrsOf(QUIET_CARD)));
  delete drifted.module.decoration.spacing; delete drifted.module.decoration.border.desktop.value.styles.all.width;
  assert.deepStrictEqual(K.missingCardExpectations(K.expectCardFromAttrs(drifted), 'card'), ['rest.padding', 'rest.top.width', 'rest.right.width', 'rest.bottom.width', 'rest.left.width']);
  assert.ok(K.missingCardExpectations(K.expectCardFromAttrs({}), 'card').length >= 13, 'an empty attribute object misses everything');
  // compareCard refuses to compare an expectation with a hole (it does not skip the check silently)
  const hole = full(); hole.rest.padding = null;
  const r = K.compareCard(hole, 'card', { rest: { el: cstyle({}) }, hover: { el: cstyle({}) } });
  assert.deepStrictEqual(r.problems.map((x) => x[0]), ['card-expectation']);
  assert.match(r.problems[0][1], /rest\.padding/);
});

// ---- cards: comparison ----
function cstyle(o) {
  const side = (k, c = 'rgb(227, 220, 207)') => ({ [`border${k}Width`]: '1px', [`border${k}Style`]: 'solid', [`border${k}Color`]: c });
  return Object.assign({}, side('Top'), side('Right'), side('Bottom'), side('Left'), {
    borderTopLeftRadius: '4px', borderTopRightRadius: '4px', borderBottomRightRadius: '4px', borderBottomLeftRadius: '4px',
    backgroundColor: 'rgb(255, 255, 255)', paddingTop: '32px', paddingRight: '32px', paddingBottom: '32px', paddingLeft: '32px',
    boxShadow: 'none', transform: 'none', overflowX: 'hidden', overflowY: 'hidden',
  }, o);
}
const same = (el) => ({ rest: { el }, hover: { el } });
const rgbOf = (c) => { const x = K.colour(c); return `rgb(${x.r}, ${x.g}, ${x.b})`; };
const codesOf = (r) => r.problems.map((x) => x[0]);
t('a matching card has no problem; a matching panel has none either', () => {
  assert.deepStrictEqual(K.compareCard(exQuiet, 'card', same(cstyle({}))).problems, []);
  assert.deepStrictEqual(K.compareCard(exQuietPanel, 'panel', { rest: { el: cstyle({ paddingTop: '40px', paddingRight: '40px', paddingBottom: '40px', paddingLeft: '40px' }) }, hover: { el: cstyle({ paddingTop: '40px', paddingRight: '40px', paddingBottom: '40px', paddingLeft: '40px' }) } }).problems, []);
});
t('card border: width, style and colour of EACH side, with a quarter-pixel tolerance', () => {
  const rest = (o) => K.compareCard(exQuiet, 'card', same(cstyle(o)));
  const bad = rest({ borderLeftWidth: '2px' }).problems;
  assert.deepStrictEqual(bad.map((x) => x[0]), ['card-border']);
  assert.match(bad[0][1], /border-left-width 2px, expected 1px/);
  assert.deepStrictEqual(codesOf(rest({ borderTopWidth: '1.5px' })), ['card-border'], 'half a pixel off fails');
  assert.deepStrictEqual(rest({ borderTopWidth: '1.2px' }).problems, [], 'a fifth of a pixel is within the tolerance');
  assert.deepStrictEqual(codesOf(rest({ borderRightStyle: 'dashed' })), ['card-border']);
  assert.deepStrictEqual(codesOf(rest({ borderBottomColor: 'rgb(0, 0, 0)' })), ['card-border']);
  // an underline: the top, right and left sides must NOT be drawn, the bottom must
  const under = (o) => K.compareCard(exUnder, 'card', same(cstyle(Object.assign({ borderTopWidth: '0px', borderRightWidth: '0px', borderLeftWidth: '0px', borderBottomWidth: '2px', borderBottomColor: 'rgb(28, 26, 23)', borderBottomLeftRadius: '0px', borderBottomRightRadius: '0px', borderTopLeftRadius: '0px', borderTopRightRadius: '0px', paddingTop: '24px', paddingRight: '24px', paddingBottom: '24px', paddingLeft: '24px' }, o))));
  assert.deepStrictEqual(under({}).problems, []);
  assert.deepStrictEqual(codesOf(under({ borderTopWidth: '2px' })), ['card-border'], 'a full frame where an underline is expected');
  assert.deepStrictEqual(codesOf(under({ borderBottomWidth: '0px' })), ['card-border'], 'the underline is missing');
});
t('card radius, fill, padding are each reported', () => {
  const codes = (o) => codesOf(K.compareCard(exQuiet, 'card', same(cstyle(o))));
  assert.deepStrictEqual(codes({ borderBottomRightRadius: '9px' }), ['card-radius']);
  assert.deepStrictEqual(codes({ borderTopLeftRadius: '4.4px' }), []);
  assert.deepStrictEqual(codes({ backgroundColor: 'rgb(250, 247, 242)' }), ['card-fill']);
  assert.deepStrictEqual(codes({ backgroundColor: 'rgba(255, 255, 255, 0)' }), ['card-fill'], 'a transparent card is a wrong fill');
  assert.deepStrictEqual(codes({ paddingLeft: '24px' }), ['card-padding']);
  assert.deepStrictEqual(codes({ paddingTop: '32.4px' }), []);
});
t('card shadow: sits on the card element, with the markup\'s offsets, blur and colour', () => {
  const hard = (o) => K.compareCard(exHardCard, 'card', same(cstyle(Object.assign({ borderTopWidth: '3px', borderRightWidth: '3px', borderBottomWidth: '3px', borderLeftWidth: '3px', borderTopLeftRadius: '0px', borderTopRightRadius: '0px', borderBottomRightRadius: '0px', borderBottomLeftRadius: '0px', borderTopColor: 'rgb(28, 26, 23)', borderRightColor: 'rgb(28, 26, 23)', borderBottomColor: 'rgb(28, 26, 23)', borderLeftColor: 'rgb(28, 26, 23)', paddingTop: '24px', paddingRight: '24px', paddingBottom: '24px', paddingLeft: '24px' }, o))));
  const sh = exHardCard.shadow.rest;
  const value = `${rgbOf(sh.color)} 6px 6px 0px 0px`;
  assert.deepStrictEqual(hard({ boxShadow: value }).problems.filter((x) => x[0] === 'card-shadow'), []);
  assert.deepStrictEqual(codesOf(hard({ boxShadow: 'none' })).filter((c) => c.startsWith('card-')), ['card-shadow', 'card-hover', 'card-hover'], 'no shadow where a hard one is expected (rest and hover), and neo-brutal lifts, so the missing lift too');
  assert.match(hard({ boxShadow: 'none' }).problems[0][1], /expected a shadow/);
  assert.match(hard({ boxShadow: value.replace('6px 6px 0px', '6px 6px 8px') }).problems[0][1], /expected a hard shadow \(0 blur\)/);
  assert.match(hard({ boxShadow: value.replace('6px 6px', '2px 6px') }).problems[0][1], /x offset 2px, expected 6px/);
  // a card that must have NO shadow
  assert.deepStrictEqual(codesOf(K.compareCard(exQuiet, 'card', same(cstyle({ boxShadow: 'rgba(0, 0, 0, 0.18) 0px 8px 24px 0px' })))).filter((c) => c === 'card-shadow'), ['card-shadow']);
});
const LIFT_SHADOW_COLOUR = (() => { const c = K.colour(exLift.shadow.hover.color); return `rgba(${c.r}, ${c.g}, ${c.b}, ${c.a})`; })();
t('card hover: the lift moves a card up 4px, a swap card darkens its frame, nothing else changes', () => {
  const liftRest = cstyle({ borderTopLeftRadius: '16px', borderTopRightRadius: '16px', borderBottomRightRadius: '16px', borderBottomLeftRadius: '16px', paddingTop: '28px', paddingRight: '28px', paddingBottom: '28px', paddingLeft: '28px' });
  const liftHover = Object.assign({}, liftRest, { boxShadow: `${LIFT_SHADOW_COLOUR} 0px 12px 24px 0px`, transform: 'matrix(1, 0, 0, 1, 0, -4)' });
  const ok = K.compareCard(exLift, 'card', { rest: { el: liftRest }, hover: { el: liftHover } });
  assert.deepStrictEqual(ok.problems, []);
  assert.deepStrictEqual(ok.shadowWhere, { rest: null, hover: 'card' });
  const noLift = K.compareCard(exLift, 'card', { rest: { el: liftRest }, hover: { el: Object.assign({}, liftHover, { transform: 'none' }) } });
  assert.deepStrictEqual(codesOf(noLift), ['card-hover']);
  assert.match(noLift.problems[0][1], /translateY 0px, expected -4px/);
  assert.deepStrictEqual(codesOf(K.compareCard(exLift, 'card', { rest: { el: liftRest }, hover: { el: Object.assign({}, liftHover, { boxShadow: 'none' }) } })), ['card-hover'], 'the hover shadow is missing');
  assert.deepStrictEqual(codesOf(K.compareCard(exLift, 'card', { rest: { el: Object.assign({}, liftRest, { transform: 'matrix(1, 0, 0, 1, 0, -4)' }) }, hover: { el: liftHover } })), ['card-hover'], 'a card already lifted at rest');
  // a card with no lift in the markup must not move
  assert.deepStrictEqual(codesOf(K.compareCard(exQuiet, 'card', { rest: { el: cstyle({}) }, hover: { el: cstyle({ transform: 'matrix(1, 0, 0, 1, 0, -4)' }) } })), ['card-hover']);
  // hover frame colour: only a markup that has one changes it
  const swapRest = cstyle({ backgroundColor: 'rgb(250, 247, 242)', borderTopLeftRadius: '20px', borderTopRightRadius: '20px', borderBottomRightRadius: '20px', borderBottomLeftRadius: '20px', paddingTop: '28px', paddingRight: '28px', paddingBottom: '28px', paddingLeft: '28px' });
  const dark = (o) => Object.assign({}, swapRest, { borderTopColor: 'rgb(28, 26, 23)', borderRightColor: 'rgb(28, 26, 23)', borderBottomColor: 'rgb(28, 26, 23)', borderLeftColor: 'rgb(28, 26, 23)' }, o);
  assert.deepStrictEqual(K.compareCard(exSwap, 'card', { rest: { el: swapRest }, hover: { el: dark({}) } }).problems, []);
  assert.deepStrictEqual(codesOf(K.compareCard(exSwap, 'card', { rest: { el: swapRest }, hover: { el: swapRest } })), ['card-hover', 'card-hover', 'card-hover', 'card-hover'], 'the frame does not darken on any side');
  assert.deepStrictEqual(codesOf(K.compareCard(exSwap, 'card', { rest: { el: swapRest }, hover: { el: dark({ backgroundColor: 'rgb(255, 255, 255)' }) } })), ['card-hover'], 'the fill changed on hover');
  assert.deepStrictEqual(codesOf(K.compareCard(exQuiet, 'card', { rest: { el: cstyle({}) }, hover: { el: cstyle({ borderTopColor: 'rgb(28, 26, 23)' }) } })), ['card-hover'], 'a frame colour change where the markup has none');
  // the hover state is required
  assert.deepStrictEqual(codesOf(K.compareCard(exQuiet, 'card', { rest: { el: cstyle({}) }, hover: null })), ['card-hover']);
  assert.match(K.compareCard(exQuiet, 'card', { rest: { el: cstyle({}) }, hover: null }).problems[0][1], /not measured/);
});
t('a PANEL must not change on hover at all: no lift, no frame colour, no shadow, no fill', () => {
  const pad = { paddingTop: '40px', paddingRight: '40px', paddingBottom: '40px', paddingLeft: '40px' };
  const rest = cstyle(pad);
  const ok = (h) => K.compareCard(exQuietPanel, 'panel', { rest: { el: rest }, hover: { el: Object.assign({}, rest, h) } });
  assert.deepStrictEqual(ok({}).problems, []);
  const lifts = ok({ transform: 'matrix(1, 0, 0, 1, 0, -4)' }).problems;
  assert.deepStrictEqual(lifts.map((x) => x[0]), ['card-hover']);
  assert.match(lifts[0][1], /the panel moved .*a panel is static/);
  assert.deepStrictEqual(codesOf(ok({ borderTopColor: 'rgb(28, 26, 23)', borderBottomColor: 'rgb(28, 26, 23)' })), ['card-hover', 'card-hover'], 'a panel whose frame darkens');
  assert.deepStrictEqual(codesOf(ok({ boxShadow: 'rgba(0, 0, 0, 0.25) 0px 12px 24px 0px' })), ['card-hover'], 'a panel that gains a shadow');
  assert.deepStrictEqual(codesOf(ok({ backgroundColor: 'rgb(250, 247, 242)' })), ['card-hover'], 'a panel whose fill changes');
  assert.deepStrictEqual(codesOf(ok({ borderLeftWidth: '3px' })), ['card-hover'], 'a panel whose frame grows');
  // a panel never gets the card's hover expectations, even when its expectation object carries them
  const wrong = JSON.parse(JSON.stringify(exQuietPanel)); wrong.lift = -4;
  assert.deepStrictEqual(codesOf(K.compareCard(wrong, 'panel', { rest: { el: rest }, hover: { el: Object.assign({}, rest, { transform: 'matrix(1, 0, 0, 1, 0, -4)' }) } })), ['card-hover']);
  // the rest shadow of a panel (hard shadow kit) is checked and stays on hover
  const hardRest = cstyle(Object.assign({ borderTopWidth: '3px', borderRightWidth: '3px', borderBottomWidth: '3px', borderLeftWidth: '3px', borderTopLeftRadius: '0px', borderTopRightRadius: '0px', borderBottomRightRadius: '0px', borderBottomLeftRadius: '0px', borderTopColor: 'rgb(28, 26, 23)', borderRightColor: 'rgb(28, 26, 23)', borderBottomColor: 'rgb(28, 26, 23)', borderLeftColor: 'rgb(28, 26, 23)' }, pad));
  const sv = `${rgbOf(exHardPanel.shadow.rest.color)} 6px 6px 0px 0px`;
  assert.deepStrictEqual(K.compareCard(exHardPanel, 'panel', { rest: { el: Object.assign({}, hardRest, { boxShadow: sv }) }, hover: { el: Object.assign({}, hardRest, { boxShadow: sv }) } }).problems, []);
  assert.deepStrictEqual(codesOf(K.compareCard(exHardPanel, 'panel', { rest: { el: Object.assign({}, hardRest, { boxShadow: sv }) }, hover: { el: Object.assign({}, hardRest, { boxShadow: 'none' }) } })), ['card-hover'], 'the hard shadow disappears on hover');
});

t('hover transform: the WHOLE transform is compared, so a scale or rotate on hover fails (panel, lift-less card, lift card)', () => {
  const pad = { paddingTop: '40px', paddingRight: '40px', paddingBottom: '40px', paddingLeft: '40px' };
  const rest = cstyle(pad);
  const panel = (tr) => codesOf(K.compareCard(exQuietPanel, 'panel', { rest: { el: rest }, hover: { el: Object.assign({}, rest, { transform: tr }) } }));
  assert.deepStrictEqual(panel('none'), []);
  assert.deepStrictEqual(panel('matrix(1, 0, 0, 1, 0, 0)'), [], 'an identity matrix is no change');
  assert.deepStrictEqual(panel('matrix(1.02, 0, 0, 1.02, 0, 0)'), ['card-hover'], 'a scale');
  assert.deepStrictEqual(panel('matrix(0.9998, 0.0175, -0.0175, 0.9998, 0, 0)'), ['card-hover'], 'a rotate');
  assert.deepStrictEqual(panel('matrix(1, 0, 0, 1, 6, 0)'), ['card-hover'], 'a sideways move');
  assert.deepStrictEqual(panel('matrix3d(1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 6, 0, 0, 1)'), ['card-hover'], 'a matrix3d sideways move');
  const card = (tr) => codesOf(K.compareCard(exQuiet, 'card', { rest: { el: cstyle({}) }, hover: { el: cstyle({ transform: tr }) } }));
  assert.deepStrictEqual(card('matrix(1.03, 0, 0, 1.03, 0, 0)'), ['card-hover'], 'a card with no lift that scales');
  const liftRest = cstyle({ borderTopLeftRadius: '16px', borderTopRightRadius: '16px', borderBottomRightRadius: '16px', borderBottomLeftRadius: '16px', paddingTop: '28px', paddingRight: '28px', paddingBottom: '28px', paddingLeft: '28px' });
  const lift = (tr) => codesOf(K.compareCard(exLift, 'card', { rest: { el: liftRest }, hover: { el: Object.assign({}, liftRest, { boxShadow: `${LIFT_SHADOW_COLOUR} 0px 12px 24px 0px`, transform: tr }) } }));
  assert.deepStrictEqual(lift('matrix(1, 0, 0, 1, 0, -4)'), []);
  assert.deepStrictEqual(lift('matrix(1.05, 0, 0, 1.05, 0, -4)'), ['card-hover'], 'a lift that also scales');
  assert.deepStrictEqual(lift('matrix(1, 0, 0, 1, 3, -4)'), ['card-hover'], 'a lift that also drifts sideways');
});

// ---- cards: clip ----
const R = (l, t, r, b) => ({ left: l, top: t, right: r, bottom: b, width: r - l, height: b - t });
const slotOf = (key) => K.CARD_SLOTS.find((c) => c.key === key);
t('card clip: a radius needs overflow hidden; the image must stay inside; the zero-padding panel must really touch the corners', () => {
  const exR = (r) => ({ rest: { radius: [r, r, r, r] } });
  const el = { overflowX: 'hidden', overflowY: 'hidden', borderTopWidth: '1px', borderLeftWidth: '1px' };
  const card = R(100, 100, 500, 400);
  // flush image in a card with a 24px radius and overflow hidden: clipped
  const flush = K.cardClipProblems(slotOf('panel-clip'), exR(24), { el, rect: card, image: { rect: R(101, 101, 499, 300) } });
  assert.deepStrictEqual(flush.problems, []);
  assert.deepStrictEqual(flush.info, { flush: true, clipped: true });
  // a radius without overflow hidden
  assert.deepStrictEqual(K.cardClipProblems(slotOf('panel-clip'), exR(24), { el: Object.assign({}, el, { overflowX: 'visible', overflowY: 'visible' }), rect: card, image: { rect: R(101, 101, 499, 300) } }).problems.map((x) => x[0]), ['card-clip']);
  assert.match(K.cardClipProblems(slotOf('panel-clip'), exR(24), { el: Object.assign({}, el, { overflowY: 'visible' }), rect: card, image: { rect: R(101, 101, 499, 300) } }).problems[0][1], /overflow is hidden\/visible/);
  // radius 0: no overflow demanded, nothing clipped
  const r0 = K.cardClipProblems(slotOf('panel-clip'), exR(0), { el: Object.assign({}, el, { overflowX: 'visible', overflowY: 'visible' }), rect: card, image: { rect: R(101, 101, 499, 300) } });
  assert.deepStrictEqual(r0.problems, []);
  assert.strictEqual(r0.info.clipped, false);
  // the image spills out of the card
  assert.deepStrictEqual(K.cardClipProblems(slotOf('card-bg'), exR(24), { el, rect: card, image: { rect: R(90, 101, 499, 300) } }).problems.map((x) => x[0]), ['card-clip']);
  // the clip panel with an inset image (padding crept in): the test no longer exercises the clip
  const inset = K.cardClipProblems(slotOf('panel-clip'), exR(24), { el, rect: card, image: { rect: R(132, 132, 468, 300) } });
  assert.deepStrictEqual(inset.problems.map((x) => x[0]), ['card-clip']);
  assert.match(inset.problems[0][1], /does not touch its corners/);
  // the bg-band card has kit padding: its image is inset, which is fine, and a small radius leaves the inset corner unclipped
  const padded = K.cardClipProblems(slotOf('card-bg'), exR(4), { el, rect: card, image: { rect: R(133, 133, 467, 300) } });
  assert.deepStrictEqual(padded.problems, []);
  assert.strictEqual(padded.info.flush, false);
  // an image slot with no image in the page, and a card without an image child where none is expected
  assert.deepStrictEqual(K.cardClipProblems(slotOf('card-bg'), exR(4), { el, rect: card, image: null }).problems.map((x) => x[0]), ['card-clip']);
  assert.deepStrictEqual(K.cardClipProblems(slotOf('card-surface'), exR(4), { el, rect: card, image: null }).problems, []);
  // a 2px radius against a flush image: the corner pixel is cut
  assert.strictEqual(K.cardClipProblems(slotOf('panel-clip'), exR(2), { el, rect: card, image: { rect: R(100, 100, 500, 300) } }).info.clipped, true);
});

// ---- cards: contrast ----
t('card contrast: heading and paragraph on the measured fill (4.5), the frame on the fill (3.0), the hairline excepted', () => {
  const fill = { r: 255, g: 255, b: 255 };
  const c = (o) => Object.assign({ el: cstyle({}), heading: { color: 'rgb(28, 26, 23)' }, paragraph: { color: 'rgb(107, 100, 90)' } }, o);
  const hair = '#e3dccf';
  assert.deepStrictEqual(K.cardContrastProblems(c({}), fill, hair).problems, []);
  assert.strictEqual(K.cardContrastProblems(c({}), fill, hair).info.hairline, true, 'the hairline frame is reported, not judged');
  const pale = K.cardContrastProblems(c({ paragraph: { color: 'rgb(200, 200, 200)' } }), fill, hair).problems;
  assert.deepStrictEqual(pale.map((x) => x[0]), ['card-contrast']);
  assert.match(pale[0][1], /paragraph colour #c8c8c8 on the card fill #ffffff is 1\.67:1, needs 4\.5/);
  assert.deepStrictEqual(K.cardContrastProblems(c({ heading: { color: 'rgb(255, 255, 255)' } }), fill, hair).problems.map((x) => x[1].split(' ')[0]), ['heading']);
  // a meaning-carrying frame needs 3.0 on the fill, a hairline does not
  const weak = c({ el: cstyle({ borderTopColor: 'rgb(200, 200, 200)', borderRightColor: 'rgb(200, 200, 200)', borderBottomColor: 'rgb(200, 200, 200)', borderLeftColor: 'rgb(200, 200, 200)' }) });
  assert.deepStrictEqual(K.cardContrastProblems(weak, fill, hair).problems.map((x) => x[0]), ['card-contrast', 'card-contrast', 'card-contrast', 'card-contrast']);
  assert.deepStrictEqual(K.cardContrastProblems(weak, fill, 'rgb(200, 200, 200)').problems, [], 'the same colour is exempt when it IS the language\'s border hairline');
  assert.deepStrictEqual(K.cardContrastProblems(c({ el: cstyle({ borderTopColor: 'rgb(28, 26, 23)' }) }), fill, hair).problems, []);
  // zero-width sides are not judged (an underline card)
  const under = c({ el: cstyle({ borderTopWidth: '0px', borderRightWidth: '0px', borderLeftWidth: '0px', borderBottomColor: 'rgb(28, 26, 23)' }) });
  assert.deepStrictEqual(K.cardContrastProblems(under, fill, hair).problems, []);
  // fail closed: no text, an unreadable colour
  assert.deepStrictEqual(K.cardContrastProblems(c({ heading: null }), fill, hair).problems.map((x) => x[0]), ['card-contrast']);
  assert.match(K.cardContrastProblems(c({ heading: null }), fill, hair).problems[0][1], /no heading in the card/);
  assert.deepStrictEqual(K.cardContrastProblems(c({ paragraph: { color: 'banana' } }), fill, hair).problems.map((x) => x[0]), ['card-contrast']);
  // a dark card fill: dark text on it fails
  assert.deepStrictEqual(K.cardContrastProblems(c({}), { r: 20, g: 20, b: 20 }, hair).problems.map((x) => x[0]).includes('card-contrast'), true);
});
t('the painted fill must match the computed one', () => {
  assert.deepStrictEqual(K.paintedFillProblems(cstyle({}), { r: 255, g: 255, b: 255 }), []);
  assert.deepStrictEqual(K.paintedFillProblems(cstyle({}), { r: 252, g: 255, b: 253 }), [], 'within 4 per channel');
  assert.deepStrictEqual(K.paintedFillProblems(cstyle({}), { r: 240, g: 240, b: 240 }).map((x) => x[0]), ['card-fill']);
  assert.deepStrictEqual(K.paintedFillProblems(cstyle({ backgroundColor: 'rgba(255, 255, 255, 0.5)' }), { r: 0, g: 0, b: 0 }), [], 'a translucent computed fill is not judged against the screenshot');
});

// ---- grounds: an inverted or accent fill, the painted fill against the RESOLVED one ----
const GROUND_INVERTED = "<!-- wp:divi/group {\"module\":{\"decoration\":{\"layout\":{\"desktop\":{\"value\":{\"flexDirection\":\"column\",\"rowGap\":\"12px\"}}},\"background\":{\"desktop\":{\"value\":{\"color\":\"#1c1a17\"}}},\"border\":{\"desktop\":{\"value\":{\"radius\":{\"topLeft\":\"16px\",\"topRight\":\"16px\",\"bottomRight\":\"16px\",\"bottomLeft\":\"16px\"},\"styles\":{\"all\":{\"width\":\"1px\",\"style\":\"solid\",\"color\":\"#1c1a17\"}}}}},\"spacing\":{\"desktop\":{\"value\":{\"padding\":{\"top\":\"28px\",\"right\":\"28px\",\"bottom\":\"28px\",\"left\":\"28px\"}}}},\"boxShadow\":{\"desktop\":{\"hover\":{\"style\":\"preset1\",\"vertical\":\"12px\",\"blur\":\"24px\",\"color\":\"rgba(28,22,16,0.25)\"}}},\"transform\":{\"desktop\":{\"hover\":{\"translate\":{\"y\":\"-4px\"}}}}}},\"builderVersion\":\"5.8.0\"} -->";
const exGround = K.expectCardFromAttrs(attrsOf(GROUND_INVERTED));
t('ground expectations: read from the resolved markup; the ground contract (opaque fill, frame = fill, no hover fill or frame) is part of the missing-field rule', () => {
  assert.strictEqual(exGround.rest.fill, '#1c1a17');
  assert.deepStrictEqual(K.missingCardExpectations(exGround, 'card', 'inverted'), []);
  assert.deepStrictEqual(K.missingCardExpectations(exGround, 'card'), [], 'without a ground nothing more is demanded');
  const f = (fn) => { const e = JSON.parse(JSON.stringify(exGround)); fn(e); return K.missingCardExpectations(e, 'card', 'inverted'); };
  assert.deepStrictEqual(f((e) => { e.rest.fill = 'rgba(28,26,23,0.5)'; }), ['ground fill is not opaque (rgba(28,26,23,0.5))', 'ground frame top is not the fill', 'ground frame right is not the fill', 'ground frame bottom is not the fill', 'ground frame left is not the fill']);
  assert.deepStrictEqual(f((e) => { e.rest.left.color = '#e3dccf'; }), ['ground frame left is not the fill']);
  assert.deepStrictEqual(f((e) => { e.hoverKeys.push('border.hover', 'background.hover'); }), ['ground carries border.hover', 'ground carries background.hover']);
  assert.deepStrictEqual(f((e) => { e.rest.fill = null; }), ['rest.fill']);
  assert.deepStrictEqual(f((e) => { e.rest.top.width = null; }), ['rest.top.width']);
});
t('ground-fill: the painted fill must be the resolved ground fill (4 per channel), whatever the computed style says; a hole in the expectation or the sample fails', () => {
  const sample = { r: 28, g: 26, b: 23 };
  assert.deepStrictEqual(K.groundFillProblems(exGround, sample), []);
  assert.deepStrictEqual(K.groundFillProblems(exGround, { r: 31, g: 29, b: 27 }), [], 'within 4 per channel');
  const off = K.groundFillProblems(exGround, { r: 36, g: 26, b: 23 });
  assert.deepStrictEqual(off.map((x) => x[0]), ['ground-fill']);
  assert.match(off[0][1], /painted fill #241a17 differs from the resolved ground fill #1c1a17 by 8/);
  assert.deepStrictEqual(K.groundFillProblems(exGround, { r: 250, g: 247, b: 242 }).map((x) => x[0]), ['ground-fill'], 'an inverted card painted as the band');
  const hole = JSON.parse(JSON.stringify(exGround)); hole.rest.fill = null;
  assert.deepStrictEqual(K.groundFillProblems(hole, sample).map((x) => x[0]), ['ground-fill'], 'a null expectation never silently passes');
  assert.match(K.groundFillProblems(hole, sample)[0][1], /expectation/);
  const translucent = JSON.parse(JSON.stringify(exGround)); translucent.rest.fill = 'rgba(28,26,23,0.5)';
  assert.deepStrictEqual(K.groundFillProblems(translucent, sample).map((x) => x[0]), ['ground-fill'], 'a translucent ground fill cannot be judged against a screenshot');
  assert.deepStrictEqual(K.groundFillProblems(exGround, null).map((x) => x[0]), ['ground-fill'], 'no sample at all fails');
});
t('card contrast on a ground: a frame equal to the fill is exempt ONLY for a ground; the inks are judged on the measured fill', () => {
  const dark = { r: 28, g: 26, b: 23 };
  const el = cstyle({ backgroundColor: 'rgb(28, 26, 23)', borderTopColor: 'rgb(28, 26, 23)', borderRightColor: 'rgb(28, 26, 23)', borderBottomColor: 'rgb(28, 26, 23)', borderLeftColor: 'rgb(28, 26, 23)' });
  const c = (o) => Object.assign({ el, heading: { color: 'rgb(250, 247, 242)' }, paragraph: { color: 'rgb(250, 247, 242)' } }, o);
  assert.deepStrictEqual(K.cardContrastProblems(c({}), dark, '#e3dccf', 'inverted').problems, []);
  assert.strictEqual(K.cardContrastProblems(c({}), dark, '#e3dccf', 'inverted').info.fillFrame, true);
  assert.deepStrictEqual(K.cardContrastProblems(c({}), dark, '#e3dccf').problems.map((x) => x[0]), ['card-contrast', 'card-contrast', 'card-contrast', 'card-contrast'], 'the same frame on a plain card is a failure');
  assert.deepStrictEqual(K.cardContrastProblems(c({ paragraph: { color: 'rgb(107, 100, 90)' } }), dark, '#e3dccf', 'inverted').problems.map((x) => x[1].split(' ')[0]), ['paragraph'], 'muted ink on an inverted ground fails');
  const pale = cstyle({ backgroundColor: 'rgb(28, 26, 23)', borderTopColor: 'rgb(60, 58, 55)', borderRightColor: 'rgb(28, 26, 23)', borderBottomColor: 'rgb(28, 26, 23)', borderLeftColor: 'rgb(28, 26, 23)' });
  assert.deepStrictEqual(K.cardContrastProblems(c({ el: pale }), dark, '#e3dccf', 'inverted').problems.map((x) => x[0]), ['card-contrast'], 'a frame that is NOT the fill is still judged');
});

// ---- the featured plan: prominence of a card against its band ----
const flat = (c, n = 16) => { const data = Buffer.alloc(n * 4); for (let i = 0; i < n; i++) data.set([c.r, c.g, c.b, 255], i * 4); return { width: n, height: 1, data }; };
t('prominence: the mean painted luminance difference between a card\'s box and its band (per pixel, so a dark frame counts)', () => {
  const band = { r: 250, g: 247, b: 242 };
  assert.strictEqual(K.boxProminence(flat(band), band), 0, 'a box the colour of the band has none');
  const white = K.boxProminence(flat({ r: 255, g: 255, b: 255 }), band);
  const accent = K.boxProminence(flat({ r: 20, g: 60, b: 160 }), band);
  assert.ok(white > 0 && white < 0.1, 'a white card on a warm band is faint: ' + white);
  assert.ok(accent > 0.5, 'a dark accent block is strong: ' + accent);
  // half the pixels are a dark frame, half white: the mean difference is the average of the two, not the difference of the means
  const mixed = { width: 2, height: 1, data: Buffer.from([255, 255, 255, 255, 0, 0, 0, 255]) };
  assert.ok(Math.abs(K.boxProminence(mixed, band) - (K.boxProminence(flat({ r: 255, g: 255, b: 255 }), band) + K.boxProminence(flat({ r: 0, g: 0, b: 0 }), band)) / 2) < 1e-9);
  assert.throws(() => K.boxProminence({ width: 0, height: 0, data: Buffer.alloc(0) }, band), /empty/);
});
t('featured: the featured card must be at least 1.5x the LARGER neighbour; stronger passes, equal and weaker fail, the ratio edge is inclusive; a hole fails closed', () => {
  assert.strictEqual(K.FEATURED_RATIO, 1.5, 'confirmed on the first live smoke, then frozen; never lowered after a failure');
  assert.deepStrictEqual(K.featuredProblems({ featured: 0.6, neighbours: [0.1, 0.2] }), []);
  assert.deepStrictEqual(K.featuredProblems({ featured: 0.3, neighbours: [0.1, 0.2] }), [], 'exactly 1.5x the larger passes');
  const weak = K.featuredProblems({ featured: 0.299, neighbours: [0.1, 0.2] });
  assert.deepStrictEqual(weak.map((x) => x[0]), ['featured']);
  assert.match(weak[0][1], /prominence 0\.299 .*needs 1\.5x/);
  assert.deepStrictEqual(K.featuredProblems({ featured: 0.2, neighbours: [0.2, 0.1] }).map((x) => x[0]), ['featured'], 'equal to a neighbour');
  assert.deepStrictEqual(K.featuredProblems({ featured: 0.1, neighbours: [0.1, 0.4] }).map((x) => x[0]), ['featured'], 'weaker than a neighbour');
  assert.deepStrictEqual(K.featuredProblems({ featured: 0.5, neighbours: [0.1, 0.4] }).map((x) => x[0]), ['featured'], 'the LARGER neighbour counts, not the smaller');
  assert.deepStrictEqual(K.featuredProblems({ featured: 0, neighbours: [0, 0] }).map((x) => x[0]), ['featured'], 'nothing at all is not featured');
  assert.deepStrictEqual(K.featuredProblems({ featured: 0.05, neighbours: [0, 0] }), [], 'plain neighbours that do not stand out at all: any visible featured card wins');
  assert.deepStrictEqual(K.featuredProblems({ featured: null, neighbours: [0.1, 0.2] }).map((x) => x[0]), ['featured']);
  assert.deepStrictEqual(K.featuredProblems({ featured: 0.5, neighbours: [0.1, NaN] }).map((x) => x[0]), ['featured']);
  assert.deepStrictEqual(K.featuredProblems({ featured: 0.5, neighbours: [0.1] }).map((x) => x[0]), ['featured'], 'two neighbours are required');
  assert.deepStrictEqual(K.featuredProblems(null).map((x) => x[0]), ['featured']);
  assert.deepStrictEqual(K.featuredProblems({ featured: 0.299, neighbours: [0.1, 0.2] }, 1.4), [], 'the ratio is a parameter (the constant is what the gate uses)');
});

// ---- a button on the inverted panel ----
const PANEL_PRIMARY = '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"P"}}},"decoration":{"background":{"desktop":{"value":{"color":"#faf7f2"},"hover":{"color":"rgba(250,247,242,0)"}}},"border":{"desktop":{"value":{"radius":{"topLeft":"12px","topRight":"12px","bottomRight":"12px","bottomLeft":"12px"},"styles":{"all":{"width":"0px","style":"solid","color":"#faf7f2"}}},"hover":{"styles":{"all":{"color":"#faf7f2"}}}}},"font":{"font":{"desktop":{"value":{"color":"#1c1a17","capitalization":"none","letterSpacing":"0px","weight":"600"},"hover":{"color":"#faf7f2"}}}}}},"builderVersion":"5.8.0","module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"14px","right":"28px","bottom":"14px","left":"28px"}}}}}}} /-->';
const PANEL_OUTLINE = '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"S"}}},"decoration":{"background":{"desktop":{"value":{"color":"rgba(250,247,242,0)"},"hover":{"color":"#faf7f2"}}},"border":{"desktop":{"value":{"radius":{"topLeft":"0px","topRight":"0px","bottomRight":"0px","bottomLeft":"0px"},"styles":{"all":{"width":"2px","style":"solid","color":"#faf7f2"}}},"hover":{"styles":{"all":{"color":"#faf7f2"}}}}},"font":{"font":{"desktop":{"value":{"color":"#faf7f2","capitalization":"none","letterSpacing":"0px","weight":"600"},"hover":{"color":"#1c1a17"}}}}}},"builderVersion":"5.8.0","module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"14px","right":"28px","bottom":"14px","left":"28px"}}}}}}} /-->';
const exPrimary = K.expectFromAttrs(K.buttonAttrs(PANEL_PRIMARY)[0]);
const exOutline = K.expectFromAttrs(K.buttonAttrs(PANEL_OUTLINE)[0]);
const PANEL = { r: 28, g: 26, b: 23 };
const bstyle = (o) => style(Object.assign({ borderTopWidth: '0px', borderBottomWidth: '0px', borderTopStyle: 'solid', borderBottomStyle: 'solid', borderTopColor: 'rgb(250, 247, 242)', borderBottomColor: 'rgb(250, 247, 242)', borderTopLeftRadius: '12px', borderTopRightRadius: '12px', borderBottomRightRadius: '12px', borderBottomLeftRadius: '12px', backgroundColor: 'rgb(250, 247, 242)', color: 'rgb(28, 26, 23)', textTransform: 'none', letterSpacing: '0px', paddingTop: '14px', paddingRight: '28px', paddingBottom: '14px', paddingLeft: '28px' }, o));
const outlineRest = (o) => bstyle(Object.assign({ borderTopWidth: '2px', borderBottomWidth: '2px', borderTopLeftRadius: '0px', borderTopRightRadius: '0px', borderBottomRightRadius: '0px', borderBottomLeftRadius: '0px', backgroundColor: 'rgba(250, 247, 242, 0)', color: 'rgb(250, 247, 242)' }, o));
const panelHoverPrimary = (o) => bstyle(Object.assign({ backgroundColor: 'rgba(250, 247, 242, 0)', color: 'rgb(250, 247, 242)' }, o));
const outlineHover = (o) => outlineRest(Object.assign({ backgroundColor: 'rgb(250, 247, 242)', color: 'rgb(28, 26, 23)' }, o));
const pb = (exp, rest, hover) => K.panelButtonProblems(exp, { rest: { el: rest, wrap: plain }, hover: hover === null ? null : { el: hover, wrap: plain } }, PANEL);
t('panel-button: a filled button on the inverted panel passes with readable label and the resolved hover colours', () => {
  const ok = pb(exPrimary, bstyle({}), panelHoverPrimary({}));
  assert.deepStrictEqual(ok.problems, []);
  assert.ok(ok.info.rest.label >= 11, 'the label contrast on its own fill is reported: ' + ok.info.rest.label);
  assert.deepStrictEqual(pb(exOutline, outlineRest({}), outlineHover({})).problems, [], 'a transparent button whose border and label are bg on the text panel passes');
});
t('panel-button: an unreadable label or a weak border on the painted panel fails, rest and hover', () => {
  const dark = pb(exPrimary, bstyle({ color: 'rgb(230, 225, 215)' }), panelHoverPrimary({}));
  assert.ok(dark.problems.some(([c, d]) => c === 'panel-button' && /rest: label/.test(d)), JSON.stringify(dark.problems));
  assert.ok(dark.problems.some(([c]) => c === 'label'), 'the label also disagrees with the markup');
  const hoverBad = pb(exPrimary, bstyle({}), panelHoverPrimary({ color: 'rgb(40, 38, 35)' }));
  assert.ok(hoverBad.problems.some(([c, d]) => c === 'panel-button' && /hover: label/.test(d)), JSON.stringify(hoverBad.problems));
  // a transparent button whose border is the panel colour is invisible
  const invisible = pb(exOutline, outlineRest({ borderTopColor: 'rgb(28, 26, 23)', borderBottomColor: 'rgb(28, 26, 23)' }), outlineHover({}));
  assert.ok(invisible.problems.some(([c, d]) => c === 'panel-button' && /border-top .* is 1:1, needs 3/.test(d)), JSON.stringify(invisible.problems));
  // a filled button is not judged by its border (the fill is the shape), but its fill must stand out of the panel is NOT demanded here either
  assert.deepStrictEqual(pb(exPrimary, bstyle({ borderTopWidth: '2px', borderTopColor: 'rgb(28, 26, 23)' }), panelHoverPrimary({})).problems.filter(([c]) => c === 'panel-button'), []);
});
t('panel-button: hover colours must be the resolved ones (code panel-button, not hover); everything else keeps its own code', () => {
  const noSwap = pb(exPrimary, bstyle({}), bstyle({}));
  assert.deepStrictEqual([...new Set(noSwap.problems.map((x) => x[0]))], ['panel-button'], JSON.stringify(noSwap.problems));
  assert.ok(noSwap.problems.some(([, d]) => /hover: background-color/.test(d)) && noSwap.problems.some(([, d]) => /hover: color/.test(d)));
  assert.deepStrictEqual(pb(exPrimary, bstyle({ borderTopLeftRadius: '3px' }), panelHoverPrimary({})).problems.map((x) => x[0]), ['radius']);
  assert.deepStrictEqual(pb(exPrimary, bstyle({ paddingLeft: '20px' }), panelHoverPrimary({})).problems.map((x) => x[0]), ['padding']);
  assert.deepStrictEqual(pb(exOutline, outlineRest({ borderTopWidth: '1px' }), outlineHover({})).problems.map((x) => x[0]), ['border']);
});
t('panel-button: the hover state is required, and an expectation with a hole is refused, never skipped', () => {
  const none = pb(exPrimary, bstyle({}), null);
  assert.deepStrictEqual(none.problems.map((x) => x[0]), ['panel-button']);
  assert.match(none.problems[0][1], /hover state was not measured/);
  const hole = JSON.parse(JSON.stringify(exPrimary)); hole.hover.label = null;
  const r = pb(hole, bstyle({}), panelHoverPrimary({}));
  assert.deepStrictEqual(r.problems.map((x) => x[0]), ['panel-button']);
  assert.match(r.problems[0][1], /expectation missing .*hover\.label/);
  assert.deepStrictEqual(pb(exPrimary, bstyle({}), panelHoverPrimary({})).problems, [], 'the unbroken expectation passes');
  assert.deepStrictEqual(K.panelButtonProblems(exPrimary, { rest: { el: bstyle({}), wrap: plain }, hover: { el: panelHoverPrimary({}), wrap: plain } }, null).problems.map((x) => x[0]), ['panel-button'], 'no panel sample fails');
});

// ---- cards: overflow, the column row, counts, the empty run ----
t('card overflow: beyond the container or the window, or a page wider than the window', () => {
  const page = { innerWidth: 400, scrollWidth: 400 };
  const c = (l, r) => ({ rect: { left: l, right: r }, parentRect: { left: 20, right: 380 } });
  assert.deepStrictEqual(K.cardOverflowProblems(c(20, 380), 'rest', page), []);
  assert.deepStrictEqual(K.cardOverflowProblems(c(21, 379), 'rest', page), []);
  assert.deepStrictEqual(K.cardOverflowProblems(c(20, 392), 'hover', page).map((x) => x[0]), ['overflow']);
  assert.match(K.cardOverflowProblems(c(20, 392), 'hover', page)[0][1], /hover: card 20..392 outside its container 20..380/);
  assert.deepStrictEqual(K.cardOverflowProblems(c(-30, 380), 'rest', { innerWidth: 400, scrollWidth: 400 }).map((x) => x[0]), ['overflow', 'overflow']);
  assert.strictEqual(K.cardOverflowProblems(c(20, 380), 'rest', { innerWidth: 400, scrollWidth: 430 }).length, 1);
});
t('the three column cards sit side by side at desktop and stack at 400px', () => {
  const side = [R(100, 100, 400, 300), R(420, 100, 720, 300), R(740, 100, 1040, 300)];
  const stack = [R(20, 100, 380, 300), R(20, 320, 380, 520), R(20, 540, 380, 740)];
  assert.deepStrictEqual(K.columnRowProblems(side, 1280), { problems: [], layout: 'side-by-side' });
  assert.deepStrictEqual(K.columnRowProblems(stack, 400), { problems: [], layout: 'stacked' });
  assert.deepStrictEqual(K.columnRowProblems(stack, 1280).problems.map((x) => x[0]), ['overflow'], 'stacked at desktop');
  assert.match(K.columnRowProblems(side, 400).problems[0][1], /do not stack at 400px \(side-by-side\)/, 'side by side on a phone');
  assert.deepStrictEqual(K.columnRowProblems(side, 900).problems, [], 'nothing is demanded between');
  assert.deepStrictEqual(K.columnRowProblems(stack, 900).problems, []);
  const uneven = [R(100, 100, 400, 300), R(420, 100, 800, 300), R(820, 100, 1040, 300)];
  assert.match(K.columnRowProblems(uneven, 1280).problems[0][1], /differ in width/);
  assert.deepStrictEqual(K.columnRowProblems(side.slice(0, 2), 1280).problems.map((x) => x[0]), ['cards']);
  const mixed = [R(100, 100, 400, 300), R(420, 100, 720, 300), R(100, 320, 400, 520)];
  assert.strictEqual(K.columnRowProblems(mixed, 400).layout, 'mixed');
  assert.strictEqual(K.columnRowProblems(mixed, 400).problems.length, 1);
});
t('the card count: every sheet card must be rendered (code cards), and no card selection at all is an error', () => {
  const found = K.CARD_SLOTS.map((s) => ({ key: s.key, found: true }));
  assert.deepStrictEqual(K.cardCountProblems(found), []);
  const lost = found.map((c, i) => (i === 2 ? { key: c.key, found: false } : c));
  const p = K.cardCountProblems(lost);
  assert.deepStrictEqual(p.map((x) => x[0]), ['cards']);
  assert.match(p[0][1], /19 cards rendered, expected 20 \(missing: panel-clip\)/);
  assert.deepStrictEqual(K.cardCountProblems([]).map((x) => x[0]), ['cards'], 'a page with no card fails');
  assert.deepStrictEqual(K.cardCountProblems(found.slice(0, 19)).map((x) => x[0]), ['cards']);
  assert.throws(() => K.cardCountProblems([], []), /no card slots/, 'an empty slot list can never pass');
});
t('an empty card or item selection fails the run, whatever else passed', () => {
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 12, cardsChecked: 144, itemsChecked: 120 }), []);
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 12, cardsChecked: 0, itemsChecked: 120 }), ['no card was checked: the card selection was empty']);
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 12, cardsChecked: 144, itemsChecked: 0 }), ['no item was checked: the item selection was empty']);
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 12, cardsChecked: 144 }), ['no item was checked: the item selection was empty'], 'a caller that forgets the item count fails, never skips');
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 0, cardsChecked: 0, itemsChecked: 0 }).length, 3);
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 0, cardsChecked: 5, itemsChecked: 5 }), ['no cell/width was checked: nothing was measured']);
});
t('the fill sample is the card\'s inside: past the frame and the corner radii, never empty', () => {
  assert.deepStrictEqual(K.cardSampleClip(R(100, 200, 500, 500), cstyle({})), { x: 107, y: 207, width: 386, height: 286 });
  const pill = K.cardSampleClip(R(0, 0, 400, 200), cstyle({ borderTopLeftRadius: '999px', borderTopRightRadius: '999px', borderBottomRightRadius: '999px', borderBottomLeftRadius: '999px' }));
  assert.ok(pill.x >= 51 && pill.width >= 1 && pill.y >= 51, 'a pill radius is capped at a quarter of the short side');
  const tiny = K.cardSampleClip(R(10, 10, 14, 14), cstyle({}));
  assert.ok(tiny.width >= 1 && tiny.height >= 1);
});

// ---- items (accordion and toggle): the sheet, expectations per state, comparison, count, click, contrast ----
const offlineRow = (kit, language) => {
  const r = spawnSync('php', [path.join(__dirname, 'lib', 'kit-sheet-offline.php'), '--kit', kit, '--language', language], { input: K.sheetMarkup(), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  assert.strictEqual(r.status, 0, 'php failed: ' + (r.error ? r.error.message : r.stderr));
  return JSON.parse(r.stdout)[0];
};
const ROWS = { quiet: offlineRow('quiet-solid', 'editorial'), brutal: offlineRow('neo-brutal', 'editorial'), under: offlineRow('underline-link', 'editorial') };
const itemHit = (row, key) => K.itemAttrs(row.markup).find((x) => x.slot.key === key);
const iexp = (row, key) => { const h = itemHit(row, key); return K.expectItemFromAttrs(h.attrs, h.slot.carrier); };
const clone = (o) => JSON.parse(JSON.stringify(o));
const EDITORIAL = JSON.parse(fs.readFileSync(path.join(ROOT, 'wp-plugin', 'data', 'design-languages.json'), 'utf8')).find((l) => l.id === 'editorial').colors;

t('the sheet carries 10 marked item blocks: an accordion on every band, a boxed and two rule toggles on the two light ones', () => {
  const m = K.sheetMarkup();
  assert.deepStrictEqual(K.ITEM_SLOTS.map((c) => c.key), ['accordion-bg', 'toggle-item-bg', 'toggle-open-bg', 'toggle-closed-bg', 'accordion-surface', 'toggle-item-surface', 'toggle-open-surface', 'toggle-closed-surface', 'accordion-accent', 'accordion-dark']);
  assert.deepStrictEqual(K.ITEM_SLOTS.map((c) => c.role), ['item', 'item', 'item-rule', 'item-rule', 'item', 'item', 'item-rule', 'item-rule', 'item', 'item']);
  assert.deepStrictEqual(K.ITEM_SLOTS.map((c) => c.on), ['light', 'light', 'light', 'light', 'surface', 'surface', 'surface', 'surface', 'accent', 'dark']);
  assert.ok(K.ITEM_SLOTS.filter((c) => c.role === 'item-rule').every((c) => c.on === 'light' || c.on === 'surface'), 'a rule row only on the two light bands');
  const titles = K.ITEM_SLOTS.flatMap((c) => c.items.map((i) => i.title));
  assert.strictEqual(new Set(titles).size, titles.length, 'every item is found by a unique title');
  assert.ok(titles.every((x) => !K.CARD_SLOTS.some((c) => c.heading === x)), 'no item title is a card heading');
  assert.deepStrictEqual(K.ITEM_SLOTS.filter((c) => c.carrier === 'accordion').map((c) => c.items.map((i) => i.open)), [[true, false, false], [true, false, false], [true, false, false], [true, false, false]], 'one item open, two closed');
  assert.deepStrictEqual(K.ITEM_SLOTS.filter((c) => c.carrier === 'toggle').map((c) => [c.key, c.items.length, c.items[0].open]), [['toggle-item-bg', 1, false], ['toggle-open-bg', 1, true], ['toggle-closed-bg', 1, false], ['toggle-item-surface', 1, false], ['toggle-open-surface', 1, true], ['toggle-closed-surface', 1, false]].sort((a, b) => K.ITEM_SLOTS.findIndex((x) => x.key === a[0]) - K.ITEM_SLOTS.findIndex((x) => x.key === b[0])));
  // Every marker names its role and the band the resolver sees; one marker per block (never on an accordion item).
  const marked = [];
  const walk = (nodes) => { for (const n of nodes) { if (n.attrs.aied && (n.name === 'divi/accordion' || n.name === 'divi/toggle' || n.name === 'divi/accordion-item')) marked.push(n); walk(n.children); } };
  walk(K.blockTree(m));
  assert.strictEqual(marked.length, 10);
  assert.ok(marked.every((n) => n.name !== 'divi/accordion-item'), 'an accordion item never carries a marker');
  const found = K.itemAttrs(m);
  assert.strictEqual(found.filter((f) => f.attrs).length, 10, 'every item block is found again by its first title');
  for (const f of found) {
    const want = { kit: f.slot.role }; if (f.slot.on !== 'light') want.on = f.slot.on;
    assert.deepStrictEqual(f.attrs.aied, want, f.slot.key);
    assert.strictEqual(f.slot.carrier === 'accordion' ? K.blockTree(m).length > 0 : true, true);
    assert.deepStrictEqual(f.items, f.slot.items.map((i) => ({ title: i.title, open: i.open })), f.slot.key + ' items read back from the markup');
  }
  // The recipe's own keys only: no colour, frame, radius or shadow of its own on a marked block (the kit supplies them).
  for (const f of found) assert.ok(!/color|"border"|boxShadow|background/.test(JSON.stringify(f.attrs).replace(/"aied":\{[^}]*\}/, '').replace(/\{\{aied:font:[a-z]+\}\}/g, '')), f.slot.key + ' carries no ink of its own');
  assert.strictEqual(K.pageButtonAttrs(m).length, 8, 'the page-level buttons stay 8');
  assert.strictEqual(K.cardAttrs(m).filter((c) => c.attrs).length, 20, 'the card slots stay 20');
  assert.strictEqual((m.match(/wp:divi\/accordion-item /g) || []).length, 12);
  assert.strictEqual((m.match(/wp:divi\/toggle /g) || []).length, 6);
});

t('item expectations: a boxed accordion item is a card inside (surface fill, text title, muted answer, one closed icon), no padding', () => {
  const e = iexp(ROWS.quiet, 'accordion-bg');
  assert.strictEqual(e.carrier, 'accordion');
  assert.strictEqual(e.open.fill, EDITORIAL.surface); assert.strictEqual(e.closed.fill, EDITORIAL.surface);
  assert.strictEqual(e.open.title, EDITORIAL.text); assert.strictEqual(e.closed.title, EDITORIAL.text);
  assert.strictEqual(e.open.body, EDITORIAL.muted);
  assert.strictEqual(e.closed.icon, EDITORIAL.text);
  assert.strictEqual(e.open.icon, null, 'an accordion item has no open icon');
  assert.strictEqual(e.rest.padding, null, 'an accordion never pads its items');
  assert.deepStrictEqual(e.hoverKeys, []);
  for (const side of ['top', 'right', 'bottom', 'left']) { assert.ok(e.rest[side].width >= 1 && e.rest[side].style && e.rest[side].color, side); assert.deepStrictEqual(e.rest[side], e.rest.top, 'a full frame is the same on every side'); }
  assert.ok(e.rest.radius.length === 4 && e.rest.radius.every((v) => typeof v === 'number'));
  assert.deepStrictEqual(K.missingItemExpectations(e, 'item'), []);
  // A surface band turns the item fill into bg; the item inside the accent band is still a surface-filled item.
  assert.strictEqual(iexp(ROWS.quiet, 'accordion-surface').open.fill, EDITORIAL.bg);
  assert.strictEqual(iexp(ROWS.quiet, 'accordion-accent').open.fill, EDITORIAL.surface);
  assert.strictEqual(iexp(ROWS.quiet, 'accordion-dark').closed.fill, EDITORIAL.surface);
});
t('item expectations: a boxed toggle takes the kit padding and both icons; its closed title is the closedTitle group', () => {
  const e = iexp(ROWS.quiet, 'toggle-item-bg');
  assert.strictEqual(e.carrier, 'toggle');
  assert.ok(Array.isArray(e.rest.padding) && e.rest.padding.length === 4 && e.rest.padding.every((v) => v > 0), 'space-card');
  assert.strictEqual(e.open.icon, EDITORIAL.text); assert.strictEqual(e.closed.icon, EDITORIAL.text);
  assert.strictEqual(e.closed.title, EDITORIAL.text);
  assert.deepStrictEqual(K.missingItemExpectations(e, 'item'), []);
});
t('item expectations: a rule row is square, drawn as an underline on the band colour, keeps the sheet\'s structural padding and carries no shadow', () => {
  for (const [key, fill] of [['toggle-open-bg', EDITORIAL.bg], ['toggle-closed-bg', EDITORIAL.bg], ['toggle-open-surface', EDITORIAL.surface]]) {
    for (const row of [ROWS.quiet, ROWS.brutal]) {
      const e = iexp(row, key);
      assert.deepStrictEqual(e.rest.radius, [0, 0, 0, 0], key);
      assert.deepStrictEqual([e.rest.top.width, e.rest.right.width, e.rest.left.width], [0, 0, 0], key + ' only the bottom rule is drawn');
      assert.ok(e.rest.bottom.width >= 1, key);
      assert.deepStrictEqual(e.rest.padding, [28, 0, 28, 0], key + ' the rule row keeps the recipe\'s padding');
      assert.strictEqual(e.shadow, null, key + ' a rule row has no shadow, whatever the kit');
      assert.strictEqual(e.open.fill, fill, key); assert.strictEqual(e.closed.fill, fill, key);
      assert.deepStrictEqual(K.missingItemExpectations(e, 'item-rule'), [], key);
    }
  }
});
t('item expectations: an underline kit draws only the bottom edge of a boxed item; a hard-shadow kit shadows a boxed item but not a rule row', () => {
  const u = iexp(ROWS.under, 'accordion-bg');
  assert.deepStrictEqual([u.rest.top.width, u.rest.right.width, u.rest.left.width], [0, 0, 0]);
  assert.strictEqual(u.rest.bottom.width, 2);
  const b = iexp(ROWS.brutal, 'accordion-bg');
  assert.ok(b.shadow && b.shadow.blur === 0 && b.shadow.x > 0 && b.shadow.y > 0, 'a hard shadow: ' + JSON.stringify(b.shadow));
  assert.strictEqual(b.rest.top.width, 3);
  assert.strictEqual(iexp(ROWS.quiet, 'accordion-bg').shadow, null);
});
t('item path drift: every field the adapter always writes is required, so a null never silently skips a check', () => {
  const acc = () => clone(iexp(ROWS.quiet, 'accordion-bg'));
  const tog = () => clone(iexp(ROWS.quiet, 'toggle-item-bg'));
  const cases = {
    'rest.radius': (e) => { e.rest.radius[3] = null; }, 'rest.top.width': (e) => { e.rest.top.width = null; }, 'rest.right.style': (e) => { e.rest.right.style = null; },
    'rest.bottom.color': (e) => { e.rest.bottom.color = null; }, 'rest.left.width': (e) => { e.rest.left.width = NaN; },
    'open.fill': (e) => { e.open.fill = null; }, 'closed.fill': (e) => { e.closed.fill = null; }, 'open.title': (e) => { e.open.title = null; },
    'closed.title': (e) => { e.closed.title = null; }, 'open.body': (e) => { e.open.body = null; }, 'closed.icon': (e) => { e.closed.icon = null; },
  };
  for (const [field, drop] of Object.entries(cases)) { const e = acc(); drop(e); assert.deepStrictEqual(K.missingItemExpectations(e, 'item'), [field], field + ' missing is reported'); }
  const e1 = tog(); e1.open.icon = null; assert.deepStrictEqual(K.missingItemExpectations(e1, 'item'), ['open.icon'], 'a toggle needs its open icon');
  const e2 = tog(); e2.rest.padding = null; assert.deepStrictEqual(K.missingItemExpectations(e2, 'item'), ['rest.padding'], 'a toggle needs its padding');
  const e3 = acc(); e3.open.icon = '#000000'; assert.deepStrictEqual(K.missingItemExpectations(e3, 'item'), ['accordion carries an open icon (Divi hides it)']);
  const e4 = acc(); e4.rest.padding = [24, 24, 24, 24]; assert.deepStrictEqual(K.missingItemExpectations(e4, 'item'), ['accordion item padding is written (it would pad the answer)']);
  const e5 = acc(); e5.hoverKeys = ['transform', 'boxShadow.hover']; assert.deepStrictEqual(K.missingItemExpectations(e5, 'item'), ['item carries transform', 'item carries boxShadow.hover']);
  const e6 = clone(iexp(ROWS.brutal, 'accordion-bg')); assert.deepStrictEqual(K.missingItemExpectations(e6, 'item-rule'), ['rule row carries a shadow'], 'a shadow on a rule row is an adapter fault');
  // A drifted path in the markup itself.
  const drifted = clone(itemHit(ROWS.quiet, 'accordion-bg').attrs);
  delete drifted.openToggle.decoration.background; delete drifted.content;
  assert.deepStrictEqual(K.missingItemExpectations(K.expectItemFromAttrs(drifted, 'accordion'), 'item'), ['open.fill', 'open.body']);
  assert.ok(K.missingItemExpectations(K.expectItemFromAttrs({}, 'accordion'), 'item').length >= 12, 'an empty attribute object misses everything');
});

// ---- items: comparison with the computed style ----
const shadowText = (s) => (s ? `${s.color.startsWith('#') ? rgbOf(s.color) : s.color.replace(/,(\S)/g, ', $1')} ${s.x || 0}px ${s.y || 0}px ${s.blur}px 0px` : 'none');
/** A measured item (the fields measureItems collects) that agrees with the expectation in the given state. */
function measured(exp, open, o = {}) {
  const st = open ? exp.open : exp.closed;
  const el = { backgroundColor: rgbOf(st.fill), boxShadow: shadowText(exp.shadow), transform: 'none', transitionDuration: '0s', transitionDelay: '0s' };
  for (const [k, cap] of [['top', 'Top'], ['right', 'Right'], ['bottom', 'Bottom'], ['left', 'Left']]) Object.assign(el, { [`border${cap}Width`]: exp.rest[k].width + 'px', [`border${cap}Style`]: exp.rest[k].style, [`border${cap}Color`]: rgbOf(exp.rest[k].color) });
  ['borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius'].forEach((k, i) => { el[k] = exp.rest.radius[i] + 'px'; });
  const pad = exp.rest.padding || [20, 20, 20, 20];
  ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'].forEach((k, i) => { el[k] = pad[i] + 'px'; });
  return Object.assign({
    open, text: 'Title', el, rect: { left: 40, right: 340, top: 100, bottom: 200, width: 300, height: 100 }, parentRect: { left: 20, right: 380 },
    title: { color: rgbOf(st.title) },
    icon: st.icon ? { color: rgbOf(st.icon), shown: true, content: '"\\e050"', display: 'block' } : { color: 'rgb(0, 0, 0)', shown: false, content: 'none', display: 'none' },
    body: { color: rgbOf(exp.open.body), visible: open },
  }, o);
}
const withEl = (m, el) => Object.assign({}, m, { el: Object.assign({}, m.el, el) });
const accExp = iexp(ROWS.quiet, 'accordion-bg');
const togExp = iexp(ROWS.quiet, 'toggle-item-bg');
const ruleExp = iexp(ROWS.quiet, 'toggle-open-bg');
const brutalExp = iexp(ROWS.brutal, 'accordion-bg');
/** An expectation whose open state differs from its closed one in fill, title and icon, so a state that does not switch is caught. */
const distinct = (e) => { const d = clone(e); d.open.fill = '#fff3d6'; d.open.title = '#3a1a00'; if (d.open.icon !== null) d.open.icon = '#7a3b00'; return d; };
t('a matching item has no problem in either state (accordion, boxed toggle, rule toggle)', () => {
  for (const [role, e] of [['item', accExp], ['item', togExp], ['item-rule', ruleExp], ['item', brutalExp], ['item', distinct(accExp)], ['item', distinct(togExp)]]) {
    for (const open of [true, false]) assert.deepStrictEqual(K.compareItem(e, role, measured(e, open), 'rest').problems, [], `${e.carrier} ${open ? 'open' : 'closed'}`);
  }
});
t('item border: width, style and colour of EACH side, a quarter-pixel tolerance, code item-border; an underline kit draws one side', () => {
  const m = measured(accExp, true);
  const bad = K.compareItem(accExp, 'item', withEl(m, { borderLeftWidth: '3px' }), 'rest').problems;
  assert.deepStrictEqual(bad.map((x) => x[0]), ['item-border']);
  assert.match(bad[0][1], /^rest: border-left-width 3px, expected 1px/);
  assert.deepStrictEqual(K.compareItem(accExp, 'item', withEl(m, { borderTopStyle: 'dashed' }), 'rest').problems.map((x) => x[0]), ['item-border']);
  assert.deepStrictEqual(K.compareItem(accExp, 'item', withEl(m, { borderBottomColor: 'rgb(255, 0, 0)' }), 'rest').problems.map((x) => x[0]), ['item-border']);
  assert.deepStrictEqual(K.compareItem(accExp, 'item', withEl(m, { borderTopWidth: '1.2px' }), 'rest').problems, [], 'within a quarter pixel');
  assert.deepStrictEqual(K.compareItem(accExp, 'item', withEl(m, { borderTopWidth: '1.5px' }), 'rest').problems.map((x) => x[0]), ['item-border'], 'half a pixel off fails');
  const under = iexp(ROWS.under, 'accordion-bg');
  assert.deepStrictEqual(K.compareItem(under, 'item', measured(under, true), 'rest').problems, []);
  assert.deepStrictEqual(K.compareItem(under, 'item', withEl(measured(under, true), { borderTopWidth: '2px' }), 'rest').problems.map((x) => x[0]), ['item-border'], 'a frame where the underline kit draws none');
  // a rule row's phase shows in the detail
  assert.match(K.compareItem(ruleExp, 'item-rule', withEl(measured(ruleExp, true), { borderBottomWidth: '4px' }), 'after click').problems[0][1], /^after click: border-bottom-width 4px/);
});
t('item radius and padding: each corner and, where the markup writes it, each padding side (code item-radius, item-padding); an accordion\'s padding is not compared', () => {
  const m = measured(accExp, true);
  const r = K.compareItem(accExp, 'item', withEl(m, { borderBottomLeftRadius: '0px' }), 'rest').problems;
  assert.deepStrictEqual(r.map((x) => x[0]), ['item-radius']);
  assert.match(r[0][1], /borderBottomLeftRadius 0px, expected /);
  const tm = measured(togExp, false);
  const p = K.compareItem(togExp, 'item', withEl(tm, { paddingLeft: '10px' }), 'rest').problems;
  assert.deepStrictEqual(p.map((x) => x[0]), ['item-padding']);
  assert.deepStrictEqual(K.compareItem(togExp, 'item', withEl(tm, { paddingLeft: String(togExp.rest.padding[3] + 0.5) + 'px' }), 'rest').problems, [], 'within 0.6px');
  assert.deepStrictEqual(K.compareItem(accExp, 'item', withEl(m, { paddingLeft: '33px' }), 'rest').problems, [], 'Divi keeps the accordion item\'s own padding: nothing was written, nothing is judged');
  const rr = K.compareItem(ruleExp, 'item-rule', withEl(measured(ruleExp, true), { paddingTop: '10px' }), 'rest').problems;
  assert.deepStrictEqual(rr.map((x) => x[0]), ['item-padding'], 'a rule row\'s structural padding is read from the markup');
});
t('item fill, title, body and icon are judged PER STATE: an open item must show the open values, a closed one the closed values', () => {
  const e = distinct(accExp);
  // The open item showing the closed look (the state did not switch):
  const stuck = Object.assign({}, measured(e, false), { open: true, body: { color: rgbOf(e.open.body), visible: true } });
  const codes = K.compareItem(e, 'item', stuck, 'after click').problems.map((x) => x[0]);
  assert.deepStrictEqual(codes, ['item-fill', 'item-title'], codes.join());
  assert.match(K.compareItem(e, 'item', stuck, 'after click').problems[0][1], /^after click: background-color rgb\(255, 255, 255\), expected #fff3d6/);
  // The closed item showing the open look:
  const wrong = Object.assign({}, measured(e, true), { open: false, icon: measured(e, false).icon, body: { color: rgbOf(e.open.body), visible: false } });
  assert.deepStrictEqual(K.compareItem(e, 'item', wrong, 'rest').problems.map((x) => x[0]), ['item-fill', 'item-title']);
  assert.deepStrictEqual(K.compareItem(e, 'item', Object.assign({}, wrong, { icon: measured(e, true).icon }), 'rest').problems.map((x) => x[0]), ['item-fill', 'item-title', 'item-icon'], 'a closed accordion item that draws no icon');
  // body is judged on an open item only (a closed answer is hidden); icon on the state that draws one
  assert.deepStrictEqual(K.compareItem(e, 'item', Object.assign(measured(e, true), { body: { color: 'rgb(1, 2, 3)', visible: true } }), 'rest').problems.map((x) => x[0]), ['item-body']);
  assert.deepStrictEqual(K.compareItem(e, 'item', Object.assign(measured(e, false), { body: { color: 'rgb(1, 2, 3)', visible: false } }), 'rest').problems, []);
  assert.deepStrictEqual(K.compareItem(e, 'item', Object.assign(measured(e, false), { icon: { color: 'rgb(1, 2, 3)', shown: true, content: '"x"', display: 'block' } }), 'rest').problems.map((x) => x[0]), ['item-icon']);
  const noIcon = K.compareItem(e, 'item', Object.assign(measured(e, false), { icon: { color: 'rgb(0, 0, 0)', shown: false, content: 'none', display: 'none' } }), 'rest').problems;
  assert.deepStrictEqual(noIcon.map((x) => x[0]), ['item-icon']);
  assert.match(noIcon[0][1], /no icon is drawn/);
  // an accordion's OPEN icon is hidden by Divi: nothing is judged there
  assert.deepStrictEqual(K.compareItem(e, 'item', Object.assign(measured(e, true), { icon: { color: 'rgb(1, 2, 3)', shown: true, content: '"x"', display: 'block' } }), 'rest').problems, []);
  // a toggle judges both icons
  const t2 = distinct(togExp);
  assert.deepStrictEqual(K.compareItem(t2, 'item', Object.assign(measured(t2, true), { icon: { color: 'rgb(1, 2, 3)', shown: true, content: '"x"', display: 'block' } }), 'rest').problems.map((x) => x[0]), ['item-icon']);
  assert.deepStrictEqual(K.compareItem(t2, 'item', Object.assign(measured(t2, true), { icon: { color: 'rgb(0, 0, 0)', shown: false, content: 'none', display: 'none' } }), 'rest').problems.map((x) => x[0]), ['item-icon']);
  // one channel off passes, three off fails
  assert.deepStrictEqual(K.compareItem(accExp, 'item', Object.assign(measured(accExp, true), { title: { color: 'rgb(29, 26, 23)' } }), 'rest').problems, []);
  assert.deepStrictEqual(K.compareItem(accExp, 'item', Object.assign(measured(accExp, true), { title: { color: 'rgb(31, 26, 23)' } }), 'rest').problems.map((x) => x[0]), ['item-title']);
});
t('item shadow: on the item element itself with the markup\'s offsets, blur and colour; a rule row must show none (code item-shadow)', () => {
  const m = measured(brutalExp, true);
  assert.deepStrictEqual(K.compareItem(brutalExp, 'item', m, 'rest').problems, []);
  assert.deepStrictEqual(K.compareItem(brutalExp, 'item', withEl(m, { boxShadow: 'none' }), 'rest').problems.map((x) => x[0]), ['item-shadow']);
  const soft = K.compareItem(brutalExp, 'item', withEl(m, { boxShadow: 'rgb(28, 26, 23) 6px 6px 12px 0px' }), 'rest').problems;
  assert.deepStrictEqual(soft.map((x) => x[0]), ['item-shadow']);
  assert.match(soft[0][1], /expected a hard shadow/);
  assert.deepStrictEqual(K.compareItem(brutalExp, 'item', withEl(m, { boxShadow: 'rgb(28, 26, 23) 2px 6px 0px 0px' }), 'rest').problems.map((x) => x[0]), ['item-shadow']);
  assert.deepStrictEqual(K.compareItem(accExp, 'item', withEl(measured(accExp, true), { boxShadow: 'rgba(0, 0, 0, 0.3) 0px 4px 8px 0px' }), 'rest').problems.map((x) => x[0]), ['item-shadow'], 'a shadow where the kit has none');
  assert.deepStrictEqual(K.compareItem(ruleExp, 'item-rule', withEl(measured(ruleExp, true), { boxShadow: 'rgba(0, 0, 0, 0.3) 0px 4px 8px 0px' }), 'rest').problems.map((x) => x[0]), ['item-shadow']);
});
t('compareItem refuses an expectation with a hole (code item-expectation) instead of skipping the check', () => {
  const hole = clone(accExp); hole.closed.title = null;
  const r = K.compareItem(hole, 'item', measured(accExp, true), 'rest');
  assert.deepStrictEqual(r.problems.map((x) => x[0]), ['item-expectation']);
  assert.match(r.problems[0][1], /closed\.title/);
});

// ---- items: count, click, contrast, overflow ----
const block = (accordion, flags, o = {}) => ({ key: 'k', found: true, accordion, count: flags.length, items: flags.map((open, j) => Object.assign({ open, text: 'T' + j, body: { visible: open } }, o)) });
const ACC = K.ITEM_SLOTS.find((s) => s.key === 'accordion-bg');
const TOG = K.ITEM_SLOTS.find((s) => s.key === 'toggle-open-bg');
t('the item count (code items): every block rendered with the items and the opening state the sheet gives it', () => {
  const lists = K.ITEM_SLOTS.map((s) => s.items);
  const good = K.ITEM_SLOTS.map((s) => block(s.carrier === 'accordion', s.items.map((i) => i.open)));
  assert.deepStrictEqual(K.itemCountProblems(good, lists), []);
  const miss = good.slice(); miss[2] = { key: 'x', found: false };
  const p = K.itemCountProblems(miss, lists);
  assert.deepStrictEqual(p.map((x) => x[0]), ['items']);
  assert.match(p[0][1], /^9 of 10 item blocks rendered as the sheet says: toggle-open-bg is not in the page/);
  const two = good.slice(); two[0] = block(true, [true, false]);
  assert.match(K.itemCountProblems(two, lists)[0][1], /accordion-bg renders 2 items, expected 3/);
  const open = good.slice(); open[0] = block(true, [false, false, false]);
  assert.match(K.itemCountProblems(open, lists)[0][1], /accordion-bg opens items \[\] at rest, expected \[1\]/);
  const body = good.slice(); body[1] = block(false, [false], { body: { visible: true } });
  assert.match(K.itemCountProblems(body, lists)[0][1], /toggle-item-bg item 1 is closed but its answer is shown/);
  const wrongKind = good.slice(); wrongKind[0] = block(false, [true, false, false]);
  assert.match(K.itemCountProblems(wrongKind, lists)[0][1], /accordion-bg is not inside an accordion/);
  assert.deepStrictEqual(K.itemCountProblems(good.slice(0, 9), lists).map((x) => x[0]), ['items'], 'a missing measurement is a failure');
  assert.throws(() => K.itemCountProblems([], [], []), /no item slots/, 'an empty slot list can never pass');
});
t('the real click (code item-click): a closed item opens, the open accordion item closes, a toggle flips, and every answer follows its item', () => {
  const before = block(true, [true, false, false]);
  assert.strictEqual(K.clickTarget(ACC, before), 1, 'the first closed item');
  assert.strictEqual(K.clickTarget(TOG, block(false, [true])), 0);
  assert.strictEqual(K.clickTarget(ACC, block(true, [true, true, true])), -1, 'nothing closed to click: the gate cannot click');
  assert.deepStrictEqual(K.itemClickProblems(ACC, before, block(true, [false, true, false]), 1), []);
  assert.deepStrictEqual(K.itemClickProblems(TOG, block(false, [true]), block(false, [false]), 0), []);
  assert.deepStrictEqual(K.itemClickProblems(K.ITEM_SLOTS.find((s) => s.key === 'toggle-closed-bg'), block(false, [false]), block(false, [true]), 0), []);
  const stay = K.itemClickProblems(ACC, before, block(true, [true, false, false]), 1);
  assert.deepStrictEqual(stay.map((x) => x[0]), ['item-click', 'item-click']);
  assert.match(stay[0][1], /^item 1 \(previously open\) did not close when item 2 opened/);
  assert.match(stay[1][1], /^item 2 \(T1\) did not open after a click on its title/);
  const both = K.itemClickProblems(ACC, before, block(true, [true, true, false]), 1);
  assert.deepStrictEqual(both.map((x) => x[0]), ['item-click']);
  assert.match(both[0][1], /previously open/);
  const toggleStay = K.itemClickProblems(TOG, block(false, [true]), block(false, [true]), 0);
  assert.match(toggleStay[0][1], /did not close after a click on its title/);
  const hiddenBody = K.itemClickProblems(ACC, before, Object.assign(block(true, [false, true, false]), { items: [{ open: false, body: { visible: false } }, { open: true, body: { visible: false } }, { open: false, body: { visible: false } }] }), 1);
  assert.deepStrictEqual(hiddenBody.map((x) => x[0]), ['item-click']);
  assert.match(hiddenBody[0][1], /item 2 is open but its answer is not shown/);
  assert.deepStrictEqual(K.itemClickProblems(ACC, before, { key: 'k', found: false }, 1).map((x) => x[0]), ['item-click']);
});
t('item contrast on the PAINTED fill (code item-contrast): title 4.5 in its state, the answer 4.5 on an open item, a drawn icon 3.0, a meaningful frame 3.0, the hairline excepted', () => {
  const fill = { r: 255, g: 255, b: 255 };
  const m = (o) => Object.assign(measured(togExp, true), o);
  assert.deepStrictEqual(K.itemContrastProblems(m({}), fill, EDITORIAL.border).problems, []);
  const pale = K.itemContrastProblems(m({ title: { color: 'rgb(200, 200, 200)' } }), fill, EDITORIAL.border).problems;
  assert.deepStrictEqual(pale.map((x) => x[0]), ['item-contrast']);
  assert.match(pale[0][1], /^title colour #c8c8c8 on the painted item fill #ffffff is 1\.67:1, needs 4\.5/);
  assert.match(K.itemContrastProblems(m({ body: { color: 'rgb(200, 200, 200)', visible: true } }), fill, null).problems[0][1], /^answer colour/);
  assert.deepStrictEqual(K.itemContrastProblems(Object.assign(m({ open: false }), { body: { color: 'rgb(200, 200, 200)', visible: false } }), fill, null).problems.filter((x) => /answer/.test(x[1])), [], 'a closed answer is hidden: not judged');
  const icon = K.itemContrastProblems(m({ icon: { color: 'rgb(200, 200, 200)', shown: true, content: '"x"', display: 'block' } }), fill, null).problems;
  assert.match(icon[0][1], /^icon colour #c8c8c8 on the painted item fill #ffffff is 1\.67:1, needs 3/);
  assert.deepStrictEqual(K.itemContrastProblems(m({ icon: { color: 'rgb(1, 2, 3)', shown: false, content: 'none', display: 'none' } }), fill, null).problems.filter((x) => /icon/.test(x[1])), [], 'a hidden icon is not judged');
  const frame = (c) => withEl(m({}), { borderTopColor: c, borderRightColor: c, borderBottomColor: c, borderLeftColor: c });
  assert.strictEqual(K.itemContrastProblems(frame('rgb(200, 200, 200)'), fill, EDITORIAL.border).problems.length, 4, 'a meaningful frame that is too weak fails on every side');
  assert.deepStrictEqual(K.itemContrastProblems(frame('rgb(200, 200, 200)'), fill, 'rgb(200, 200, 200)').problems, [], 'the language\'s border hairline is reported, not judged');
  assert.deepStrictEqual(K.itemContrastProblems(withEl(m({}), { borderTopWidth: '0px', borderRightWidth: '0px', borderLeftWidth: '0px', borderBottomColor: 'rgb(28, 26, 23)' }), fill, null).problems, [], 'an underline: undrawn sides are not judged');
  // fail closed
  assert.deepStrictEqual(K.itemContrastProblems(m({ title: null }), fill, EDITORIAL.border).problems.map((x) => x[0]), ['item-contrast']);
  assert.deepStrictEqual(K.itemContrastProblems(m({ title: { color: 'banana' } }), fill, EDITORIAL.border).problems.map((x) => x[0]), ['item-contrast']);
  assert.deepStrictEqual(K.itemContrastProblems(m({}), null, null).problems.map((x) => x[0]), ['item-contrast'], 'no painted fill sample');
  // a dark painted fill: dark text on it fails
  assert.ok(K.itemContrastProblems(m({}), { r: 10, g: 10, b: 10 }, null).problems.some((x) => x[0] === 'item-contrast'));
});
t('the painted item fill must match the computed one (code item-fill); a translucent computed fill is not judged against the screenshot', () => {
  const el = measured(accExp, true).el;
  assert.deepStrictEqual(K.itemPaintedFillProblems(el, { r: 255, g: 255, b: 255 }), []);
  assert.deepStrictEqual(K.itemPaintedFillProblems(el, { r: 252, g: 255, b: 253 }), [], 'within 4 per channel');
  const bad = K.itemPaintedFillProblems(el, { r: 240, g: 240, b: 240 });
  assert.deepStrictEqual(bad.map((x) => x[0]), ['item-fill']);
  assert.match(bad[0][1], /something is drawn over the item/);
  assert.deepStrictEqual(K.itemPaintedFillProblems(Object.assign({}, el, { backgroundColor: 'rgba(255, 255, 255, 0.5)' }), { r: 0, g: 0, b: 0 }), []);
  assert.deepStrictEqual(K.itemPaintedFillProblems(el, null).map((x) => x[0]), ['item-fill'], 'a missing sample fails');
});
t('item overflow: beyond its column or the window, or a page wider than the window', () => {
  const page = { innerWidth: 400, scrollWidth: 400 };
  const it = (l, r) => ({ rect: { left: l, right: r }, parentRect: { left: 20, right: 380 } });
  assert.deepStrictEqual(K.itemOverflowProblems(it(20, 300), 'rest', page), []);
  const o = K.itemOverflowProblems(it(20, 390), 'after click', page);
  assert.strictEqual(o[0][0], 'overflow');
  assert.match(o[0][1], /^after click: item /);
  assert.strictEqual(K.itemOverflowProblems(it(20, 300), 'rest', { innerWidth: 400, scrollWidth: 420 }).length, 1);
});
t('compareCard also holds a ground to its contract (the carry-in): a ground whose frame is not its fill is refused with card-expectation', () => {
  const e = clone(exGround); e.rest.top.color = '#ff0000';
  const r = K.compareCard(e, 'card', same(cstyle({})), 'inverted');
  assert.deepStrictEqual(r.problems.map((x) => x[0]), ['card-expectation']);
  assert.match(r.problems[0][1], /ground frame top is not the fill/);
  assert.notStrictEqual(K.compareCard(e, 'card', same(cstyle({}))).problems[0][0], 'card-expectation', 'without the ground it is just an ordinary card');
});

// ---- forms (contact form, role form): the sheet, expectations from the resolved markup, comparison, count, contrast, clip, overflow ----
const fhit = (row, key) => K.formAttrs(row.markup).find((x) => x.slot.key === key);
const fexp = (row, key = 'form-bg') => K.expectFormFromAttrs(fhit(row, key).attrs);
const SIDE4 = [['top', 'Top'], ['right', 'Right'], ['bottom', 'Bottom'], ['left', 'Left']];
/** A measured field (the fields measureForms collects) that agrees with the field expectation. */
function fieldM(ef, o = {}) {
  const el = { backgroundColor: rgbOf(ef.fill), color: rgbOf(ef.ink), boxShadow: 'none', transform: 'none', overflowX: 'visible', overflowY: 'visible' };
  for (const [k, cap] of SIDE4) Object.assign(el, { [`border${cap}Width`]: ef[k].width + 'px', [`border${cap}Style`]: ef[k].style, [`border${cap}Color`]: rgbOf(ef[k].color) });
  ['borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius'].forEach((k, i) => { el[k] = ef.radius[i] + 'px'; });
  ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'].forEach((k, i) => { el[k] = ef.padding[i] + 'px'; });
  return Object.assign({ el, placeholder: { color: rgbOf(ef.placeholder) }, rect: R(40, 100, 340, 150) }, o);
}
/** A measured submit button ({ el, wrap }) that agrees with the button expectation in a state. */
function submitM(be, state = 'rest') {
  const hover = state === 'hover';
  const sh = hover ? be.shadow.hover : be.shadow.rest;
  const edge = hover && be.hover.border ? be.hover.border : null;
  const el = {
    backgroundColor: hover ? be.hover.fill : be.rest.fill, color: hover ? be.hover.label : be.rest.label,
    borderTopWidth: be.rest.top.width + 'px', borderTopStyle: be.rest.top.style, borderTopColor: edge || be.rest.top.color,
    borderBottomWidth: be.rest.bottom.width + 'px', borderBottomStyle: be.rest.bottom.style, borderBottomColor: edge || be.rest.bottom.color,
    borderLeftWidth: be.rest.top.width + 'px', borderRightWidth: be.rest.top.width + 'px',
    textTransform: be.rest.capitalization, letterSpacing: be.rest.letterSpacing + 'px', fontWeight: String(be.rest.weight), boxShadow: shadowText(sh), transform: hover && be.lift !== null ? `matrix(1, 0, 0, 1, 0, ${be.lift})` : 'none',
    transitionDuration: '0s', transitionDelay: '0s',
  };
  ['borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius'].forEach((k, i) => { el[k] = be.rest.radius[i] + 'px'; });
  ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'].forEach((k, i) => { el[k] = be.rest.padding[i] + 'px'; });
  return { el, wrap: { boxShadow: 'none', transform: 'none' } };
}
/** A measured form (what measureForms returns for one slot) that agrees with the expectation in every respect. */
function mform(exp, o = {}) {
  const rest = submitM(exp.button, 'rest');
  const ink = rgbOf(exp.captcha.ink);
  const container = { el: { boxShadow: 'none', transform: 'none', borderTopWidth: '0px', borderRightWidth: '0px', borderBottomWidth: '0px', borderLeftWidth: '0px', overflowX: 'visible', overflowY: 'visible' }, rect: R(20, 80, 380, 420) };
  return Object.assign({
    key: 'form-bg', found: true, submitCount: 1, container,
    fields: [fieldM(exp.field), fieldM(exp.field, { rect: R(40, 160, 340, 210) })],
    submit: { el: rest.el, wrap: rest.wrap, rect: R(40, 300, 160, 340), parentRect: container.rect, scrollWidth: 100, clientWidth: 100 },
    captcha: { found: true, question: { color: ink }, row: { color: ink }, label: null, rect: R(40, 230, 200, 270) },
  }, o);
}
const fcodes = (problems) => [...new Set(problems.map((x) => x[0]))];
const withField = (m, j, el, extra = {}) => Object.assign({}, m, { fields: m.fields.map((f, i) => (i === j ? Object.assign({}, f, extra, { el: Object.assign({}, f.el, el) }) : f)) });
const qForm = fexp(ROWS.quiet); const bForm = fexp(ROWS.brutal); const uForm = fexp(ROWS.under);

t('the sheet carries 2 marked forms: one per light band, two fields each, found again by the unique text of the submit', () => {
  const m = K.sheetMarkup();
  assert.deepStrictEqual(K.FORM_SLOTS.map((c) => [c.key, c.band, c.on, c.fields]), [['form-bg', 'bg', 'light', 2], ['form-surface', 'surface', 'surface', 2]]);
  assert.strictEqual(new Set(K.FORM_SLOTS.map((c) => c.submit)).size, K.FORM_SLOTS.length, 'every form is found by a unique submit text');
  const found = K.formAttrs(m);
  assert.strictEqual(found.filter((f) => f.attrs).length, 2);
  for (const f of found) {
    const want = { kit: 'form' }; if (f.slot.on !== 'light') want.on = f.slot.on;
    assert.deepStrictEqual(f.attrs.aied, want, f.slot.key);
    assert.strictEqual(f.fields, f.slot.fields, f.slot.key + ' fields read back from the markup');
    // The recipe's own keys only: the label, the custom-button switch and the body font token; no colour, frame, radius, shadow or module decoration.
    assert.ok(!/color|"border"|boxShadow|background|"module"/.test(JSON.stringify(f.attrs).replace(/"aied":\{[^}]*\}/, '').replace(/\{\{aied:font:[a-z]+\}\}/g, '')), f.slot.key + ' carries no ink or module decoration of its own');
  }
  assert.strictEqual((m.match(/<!-- wp:divi\/contact-form /g) || []).length, 2);
  assert.strictEqual((m.match(/wp:divi\/contact-field /g) || []).length, 4);
  assert.ok(!/useBasicCaptcha/.test(m), 'the captcha is Divi\'s default: nothing is written for it');
  assert.strictEqual(K.pageButtonAttrs(m).length, 8, 'the page-level buttons stay 8');
  assert.strictEqual(K.cardAttrs(m).filter((c) => c.attrs).length, 20, 'the card slots stay 20');
  assert.strictEqual(K.itemAttrs(m).filter((c) => c.attrs).length, 10, 'the item slots stay 10');
  assert.deepStrictEqual(K.formAttrs('<!-- wp:divi/text {} /-->').map((f) => [f.attrs, f.fields]), [[null, null], [null, null]], 'a form that is not in the markup is reported as absent');
});

t('form expectations: a surface field on the bg band, a bg field on the surface band, the kit\'s frame and radius, a submit that is the kit\'s button, the band\'s captcha ink', () => {
  const pal = EDITORIAL;
  assert.ok(K.sameColour(qForm.field.fill, pal.surface), 'a field on the bg band is a surface');
  assert.ok(K.sameColour(fexp(ROWS.quiet, 'form-surface').field.fill, pal.bg), 'a field on the surface band is a bg');
  assert.ok(K.sameColour(qForm.field.ink, pal.text) && K.sameColour(qForm.field.placeholder, pal.muted), 'ink text, placeholder muted');
  assert.ok(K.sameColour(qForm.captcha.ink, pal.text));
  for (const side of ['top', 'right', 'bottom', 'left']) assert.deepStrictEqual(qForm.field[side], qForm.field.top, 'a full frame is the same on every side');
  assert.ok(qForm.field.top.width >= 1 && qForm.field.radius.length === 4 && qForm.field.radius.every((v) => typeof v === 'number') && qForm.field.padding.length === 4);
  assert.deepStrictEqual(qForm.moduleKeys, [], 'the kit writes no module.decoration on a form');
  assert.deepStrictEqual(K.missingFormExpectations(qForm), []);
  assert.deepStrictEqual(K.missingFormExpectations(bForm), []);
  assert.deepStrictEqual(K.missingFormExpectations(uForm), []);
  assert.strictEqual(qForm.button.lift, null);
  assert.deepStrictEqual([bForm.button.shadow.rest.x, bForm.button.shadow.rest.y, bForm.button.shadow.rest.blur, bForm.button.lift], [6, 6, 0, -4], 'neo-brutal: a hard shadow and a lift on the submit, read from button.decoration.*');
  assert.ok(bForm.button.rest.padding.every((v) => typeof v === 'number'), 'the padding is read from button.decoration.spacing');
  // The submit is the kit's primary control on the band: the same fill and label as the page-level primary button of the same sheet.
  const primary = K.expectFromAttrs(K.pageButtonAttrs(ROWS.quiet.markup)[0]);
  assert.strictEqual(qForm.button.rest.fill, primary.rest.fill); assert.strictEqual(qForm.button.rest.label, primary.rest.label);
});
t('an underline kit: a bottom-only field frame and a bottom-only submit with NO hover border colour', () => {
  assert.deepStrictEqual([uForm.field.top.width, uForm.field.right.width, uForm.field.left.width], [0, 0, 0]);
  assert.ok(uForm.field.bottom.width > 0);
  assert.strictEqual(uForm.button.rest.top.width, 0); assert.ok(uForm.button.rest.bottom.width > 0);
  assert.strictEqual(uForm.button.hover.border, null, 'the hover border colour only recolours the 0px sides, so it is not written');
  assert.deepStrictEqual(K.missingFormExpectations(uForm), [], 'a null hover border is expected on an underline');
  const planted = clone(uForm); planted.button.hover.border = '#ff0000';
  assert.deepStrictEqual(K.missingFormExpectations(planted), ['button underline carries a hover border colour (the edge never changes on hover)']);
  const full = clone(qForm); full.button.hover.border = null;
  assert.deepStrictEqual(K.missingFormExpectations(full), ['button.hover.border'], 'a non-underline submit must have its hover border');
});

t('form path drift: every field the adapter always writes is required; module.decoration, a field shadow and a field hover are refused', () => {
  const cases = {
    'field.fill': (e) => { e.field.fill = null; }, 'field.ink': (e) => { e.field.ink = null; }, 'field.placeholder': (e) => { e.field.placeholder = null; },
    'field.radius': (e) => { e.field.radius[1] = null; }, 'field.padding': (e) => { e.field.padding = null; },
    'field.top.width': (e) => { e.field.top.width = null; }, 'field.left.style': (e) => { e.field.left.style = null; }, 'field.bottom.color': (e) => { e.field.bottom.color = null; },
    'button.rest.fill': (e) => { e.button.rest.fill = null; }, 'button.rest.padding': (e) => { e.button.rest.padding = null; }, 'button.hover.label': (e) => { e.button.hover.label = null; },
    'captcha.ink': (e) => { e.captcha.ink = null; },
  };
  for (const [field, drop] of Object.entries(cases)) { const e = clone(qForm); drop(e); assert.deepStrictEqual(K.missingFormExpectations(e), [field], field + ' missing is reported'); }
  const e = clone(qForm); e.moduleKeys = ['boxShadow', 'spacing'];
  assert.deepStrictEqual(K.missingFormExpectations(e), ['form carries module.decoration (boxShadow,spacing): the kit must write none']);
  const s = clone(qForm); s.field.shadow = true; s.field.hover = true;
  assert.deepStrictEqual(K.missingFormExpectations(s), ['field carries a shadow (Ruling 3)', 'field carries a hover or focus state (not proven)']);
  // Drift in the markup itself: no field padding, no captcha colour, a module decoration the kit must not write.
  const attrs = clone(fhit(ROWS.quiet, 'form-bg').attrs);
  delete attrs.field.decoration.spacing; delete attrs.captcha; attrs.module = { decoration: { boxShadow: { desktop: { value: {} } } } };
  assert.deepStrictEqual(K.missingFormExpectations(K.expectFormFromAttrs(attrs)), ['field.padding', 'captcha.ink', 'form carries module.decoration (boxShadow): the kit must write none']);
  assert.ok(K.missingFormExpectations(K.expectFormFromAttrs({})).length >= 20, 'an empty attribute object misses everything');
  const hov = clone(fhit(ROWS.quiet, 'form-bg').attrs); hov.field.decoration.border.desktop.hover = { styles: { all: { color: '#000' } } };
  assert.deepStrictEqual(K.missingFormExpectations(K.expectFormFromAttrs(hov)), ['field carries a hover or focus state (not proven)']);
  assert.deepStrictEqual(fcodes(K.compareForm(e, mform(qForm))), ['form-expectation'], 'compareForm refuses an expectation with a hole instead of skipping');
});

t('a matching form has no problem (fields, captcha, container); the submit and the painted values have their own functions', () => {
  for (const exp of [qForm, bForm, uForm]) {
    assert.deepStrictEqual(K.compareForm(exp, mform(exp)), []);
    assert.deepStrictEqual(K.formButtonProblems(exp.button, submitM(exp.button, 'rest'), submitM(exp.button, 'hover')), []);
    assert.deepStrictEqual(K.formClipProblems(mform(exp)), []);
  }
});
t('form-field-border: width, style and colour of EACH side (an underline kit draws one), a quarter-pixel tolerance, no field shadow', () => {
  const m = mform(qForm);
  const bad = K.compareForm(qForm, withField(m, 0, { borderLeftWidth: '3px' }));
  assert.deepStrictEqual(fcodes(bad), ['form-field-border']);
  assert.match(bad[0][1], /^field 1 rest: border-left-width 3px, expected 1px/);
  assert.deepStrictEqual(fcodes(K.compareForm(qForm, withField(m, 1, { borderTopStyle: 'dashed' }))), ['form-field-border']);
  assert.deepStrictEqual(fcodes(K.compareForm(qForm, withField(m, 1, { borderBottomColor: 'rgb(255, 0, 0)' }))), ['form-field-border']);
  assert.deepStrictEqual(K.compareForm(qForm, withField(m, 0, { borderTopWidth: '1.2px' })), [], 'within a quarter pixel');
  assert.deepStrictEqual(fcodes(K.compareForm(qForm, withField(m, 0, { borderTopWidth: '1.5px' }))), ['form-field-border'], 'half a pixel off fails');
  assert.deepStrictEqual(fcodes(K.compareForm(qForm, withField(m, 0, { boxShadow: 'rgba(0, 0, 0, 0.2) 0px 4px 8px 0px' }))), ['form-field-border'], 'a shadow on a field');
  const u = mform(uForm);
  assert.deepStrictEqual(K.compareForm(uForm, u), []);
  assert.deepStrictEqual(fcodes(K.compareForm(uForm, withField(u, 0, { borderTopWidth: '2px' }))), ['form-field-border'], 'a frame where the underline kit draws none');
  assert.deepStrictEqual(fcodes(K.compareForm(uForm, withField(u, 1, { borderBottomWidth: '0px' }))), ['form-field-border'], 'no underline');
});
t('form-field-radius, -ink, -placeholder, -padding, -fill: each its own code, naming the field', () => {
  const m = mform(qForm);
  const one = (el, extra) => K.compareForm(qForm, withField(m, 1, el, extra));
  assert.deepStrictEqual(one({ borderTopLeftRadius: '2px' }).map((x) => x[0]), ['form-field-radius']);
  assert.match(one({ borderTopLeftRadius: '2px' })[0][1], /^field 2 rest: borderTopLeftRadius 2px, expected 4px/);
  assert.deepStrictEqual(one({ color: 'rgb(255, 0, 0)' }).map((x) => x[0]), ['form-field-ink']);
  assert.deepStrictEqual(one({}, { placeholder: { color: 'rgb(117, 117, 117)' } }).map((x) => x[0]), ['form-field-placeholder'], 'the browser default placeholder');
  assert.match(one({}, { placeholder: { color: 'rgb(117, 117, 117)' } })[0][1], /^field 2 rest: ::placeholder color rgb\(117, 117, 117\), expected #6b645a/);
  assert.deepStrictEqual(one({}, { placeholder: null }).map((x) => x[0]), ['form-field-placeholder'], 'a placeholder that was not measured fails, never skips');
  assert.deepStrictEqual(one({ paddingLeft: '3px' }).map((x) => x[0]), ['form-field-padding']);
  assert.deepStrictEqual(one({ backgroundColor: 'rgb(238, 238, 238)' }).map((x) => x[0]), ['form-field-fill'], 'Divi\'s own field grey');
  assert.deepStrictEqual(K.compareForm(qForm, withField(m, 0, { color: qForm.field.ink })), [], 'the same value is no problem');
});
t('form-captcha: the question, its row and a label in it carry the band\'s ink; a missing captcha fails', () => {
  const m = mform(qForm);
  assert.deepStrictEqual(K.compareForm(qForm, Object.assign({}, m, { captcha: Object.assign({}, m.captcha, { question: { color: 'rgb(102, 102, 102)' } }) })).map((x) => x[0]), ['form-captcha'], 'Divi\'s own captcha grey');
  assert.deepStrictEqual(fcodes(K.compareForm(qForm, Object.assign({}, m, { captcha: Object.assign({}, m.captcha, { row: { color: 'rgb(102, 102, 102)' } }) }))), ['form-captcha']);
  assert.deepStrictEqual(fcodes(K.compareForm(qForm, Object.assign({}, m, { captcha: Object.assign({}, m.captcha, { label: { color: 'rgb(102, 102, 102)' } }) }))), ['form-captcha']);
  assert.deepStrictEqual(K.compareForm(qForm, Object.assign({}, m, { captcha: Object.assign({}, m.captcha, { label: { color: rgbOf(qForm.captcha.ink) } }) })), []);
  const gone = K.compareForm(qForm, Object.assign({}, m, { captcha: { found: false } }));
  assert.deepStrictEqual(fcodes(gone), ['form-captcha']);
  assert.match(gone[0][1], /the captcha question is not on the page/);
});
t('form-container: the kit writes nothing on the form\'s own container (no shadow, no lift, no frame), at rest and on hover', () => {
  const m = mform(qForm);
  const c = (el, state = 'rest') => K.formContainerProblems({ el: Object.assign({}, m.container.el, el), rect: m.container.rect }, state);
  assert.deepStrictEqual(c({}), []);
  assert.deepStrictEqual(fcodes(c({ boxShadow: 'rgb(0, 0, 0) 6px 6px 0px 0px' })), ['form-container']);
  assert.deepStrictEqual(fcodes(c({ transform: 'matrix(1, 0, 0, 1, 0, -4)' }, 'hover')), ['form-container']);
  assert.match(c({ transform: 'matrix(1, 0, 0, 1, 0, -4)' }, 'hover')[0][1], /^hover: the form container is transformed/);
  assert.deepStrictEqual(fcodes(c({ borderLeftWidth: '2px' })), ['form-container']);
  assert.deepStrictEqual(c({ boxShadow: 'rgb(0, 0, 0) 0px 0px 0px 1px inset' }), [], 'an inset ring is not the kit\'s shadow');
  assert.deepStrictEqual(fcodes(K.compareForm(qForm, Object.assign({}, m, { container: { el: Object.assign({}, m.container.el, { boxShadow: 'rgb(0, 0, 0) 6px 6px 0px 0px' }), rect: m.container.rect } }))), ['form-container']);
});

t('form-button: the submit is judged by the button measures (rest and a real hover), every departure under the one code', () => {
  const rest = (exp, o = {}) => { const r = submitM(exp.button, 'rest'); return { el: Object.assign({}, r.el, o), wrap: r.wrap }; };
  const hover = (exp, o = {}, wrap = {}) => { const r = submitM(exp.button, 'hover'); return { el: Object.assign({}, r.el, o), wrap: Object.assign({}, r.wrap, wrap) }; };
  const run = (exp, r, h) => K.formButtonProblems(exp.button, r, h);
  assert.deepStrictEqual(run(qForm, rest(qForm), hover(qForm)), []);
  for (const [what, o] of [['fill', { backgroundColor: 'rgb(238, 238, 238)' }], ['label', { color: 'rgb(0, 0, 0)' }], ['radius', { borderTopLeftRadius: '20px' }], ['padding', { paddingLeft: '3px' }], ['text', { textTransform: 'uppercase' }], ['shadow', { boxShadow: 'rgb(0, 0, 0) 3px 3px 0px 0px' }]]) {
    const p = run(qForm, rest(qForm, o), hover(qForm));
    assert.deepStrictEqual(fcodes(p), ['form-button'], what);
    assert.match(p[0][1], new RegExp('^submit ' + what + ': '), what);
  }
  assert.deepStrictEqual(fcodes(run(qForm, rest(qForm, { borderLeftWidth: '3px' }), hover(qForm))), ['form-button'], 'a left edge where the kit draws none');
  assert.deepStrictEqual(fcodes(run(qForm, rest(qForm), hover(qForm, { backgroundColor: 'rgb(238, 238, 238)' }))), ['form-button'], 'a hover fill that is not the kit\'s');
  // The hard shadow and the lift the neo-brutal kit writes at button.decoration.*: present at rest and on hover, and the lift on hover only.
  assert.deepStrictEqual(run(bForm, rest(bForm), hover(bForm)), []);
  assert.deepStrictEqual(fcodes(run(bForm, rest(bForm, { boxShadow: 'none' }), hover(bForm))), ['form-button'], 'the hard shadow is missing at rest');
  assert.deepStrictEqual(fcodes(run(bForm, rest(bForm), hover(bForm, { transform: 'none' }))), ['form-button'], 'the lift is missing on hover');
  assert.deepStrictEqual(fcodes(run(bForm, rest(bForm, { transform: 'matrix(1, 0, 0, 1, 0, -4)' }), hover(bForm))), ['form-button'], 'moved at rest');
  assert.deepStrictEqual(fcodes(run(qForm, rest(qForm), hover(qForm, { transform: 'matrix(1, 0, 0, 1, 0, -4)' }))), ['form-button'], 'a lift the kit does not ask for');
  assert.match(run(qForm, rest(qForm), null).slice(-1)[0][1], /^submit hover: the hover state was not measured/, 'no hover measurement is a failure');
});
t('form-button on an underline submit: the bottom edge keeps its REST colour on hover', () => {
  const r = submitM(uForm.button, 'rest'); const h = submitM(uForm.button, 'hover');
  assert.strictEqual(h.el.borderBottomColor, r.el.borderBottomColor, 'the helper itself keeps the edge');
  assert.deepStrictEqual(K.formButtonProblems(uForm.button, r, h), []);
  const moved = K.formButtonProblems(uForm.button, r, { el: Object.assign({}, h.el, { borderBottomColor: 'rgb(16, 185, 129)' }), wrap: h.wrap });
  assert.deepStrictEqual(fcodes(moved), ['form-button']);
  assert.match(moved[0][1], /^submit hover: the underline's bottom edge is rgb\(16, 185, 129\) on hover, expected its rest colour #1c1a17/);
  assert.deepStrictEqual(fcodes(K.formButtonProblems(uForm.button, Object.assign({}, r, { el: Object.assign({}, r.el, { borderTopWidth: '3px' }) }), h)), ['form-button'], 'a top edge on an underline submit');
  assert.deepStrictEqual(fcodes(K.formButtonProblems(uForm.button, Object.assign({}, r, { el: Object.assign({}, r.el, { borderLeftWidth: '2px' }) }), h)), ['form-button'], 'a left edge on an underline submit');
});

t('form-field-fill from the painted fill: what is painted must be what the markup says, and a field never matches its container', () => {
  const m = mform(qForm);
  const fill = K.colour(qForm.field.fill); const surf = K.colour(EDITORIAL.bg);
  const sample = { r: fill.r, g: fill.g, b: fill.b }; const behind = { r: surf.r, g: surf.g, b: surf.b };
  const ok = K.formPaintedFillProblems(m, [sample, sample], { fields: [behind, behind] });
  assert.deepStrictEqual(ok, []);
  const covered = K.formPaintedFillProblems(m, [sample, { r: 244, g: 244, b: 244 }], { fields: [behind, behind] });
  assert.deepStrictEqual(fcodes(covered), ['form-field-fill']);
  assert.match(covered[0][1], /^field 2: painted fill #f4f4f4 differs from the computed rgb\(255, 255, 255\)/);
  const same = K.formPaintedFillProblems(m, [sample, sample], { fields: [sample, behind] });
  assert.match(same[0][1], /^field 1: the field fill #ffffff is the colour of the form behind it #ffffff \(a field never matches its container\)/);
  assert.deepStrictEqual(fcodes(K.formPaintedFillProblems(m, [sample, null], { fields: [behind, behind] })), ['form-field-fill'], 'a missing sample fails, never skips');
  assert.deepStrictEqual(fcodes(K.formPaintedFillProblems(m, null, null)), ['form-field-fill']);
});
t('form-contrast: ink and placeholder 4.5 on the painted field, the frame 3.0 on the field and on the form behind it, the submit rest and hover, the captcha 4.5', () => {
  const m = mform(qForm);
  const fill = K.colour(qForm.field.fill); const bg = K.colour(EDITORIAL.bg);
  const F = { r: fill.r, g: fill.g, b: fill.b }; const B = { r: bg.r, g: bg.g, b: bg.b };
  const surfaces = { fields: [B, B], submit: B, captcha: B };
  const ok = K.formContrastProblems(qForm, m, [F, F], surfaces, submitM(qForm.button, 'hover').el);
  assert.deepStrictEqual(ok.problems, []);
  assert.ok(ok.info.fields[0].ink >= 4.5 && ok.info.fields[0].placeholder >= 4.5 && ok.info.fields[0].top >= 3 && ok.info.fields[0].topOnForm >= 3 && ok.info.captcha >= 4.5 && ok.info.submit.rest.label >= 4.5 && ok.info.submit.hover.label >= 4.5);
  const pale = K.formContrastProblems(qForm, withField(m, 0, { color: 'rgb(200, 200, 200)' }), [F, F], surfaces, submitM(qForm.button, 'hover').el);
  assert.strictEqual(pale.problems.length, 1);
  assert.match(pale.problems[0][1], /^field 1 ink #c8c8c8 on #ffffff is 1\.67:1, needs 4\.5/);
  const palePh = K.formContrastProblems(qForm, withField(m, 1, {}, { placeholder: { color: 'rgb(190, 190, 190)' } }), [F, F], surfaces, submitM(qForm.button, 'hover').el);
  assert.match(palePh.problems[0][1], /^field 2 placeholder /);
  const faint = K.formContrastProblems(qForm, withField(m, 0, { borderTopColor: 'rgb(240, 240, 240)' }), [F, F], surfaces, submitM(qForm.button, 'hover').el);
  // Ruling 6: the frame against the painted FILL stays strict per side; the other three sides still draw the boundary against the form behind, so only the fill rule fires.
  assert.ok(faint.problems.length === 1 && faint.problems[0][0] === 'form-contrast' && /^field 1 frame top against the field fill /.test(faint.problems[0][1]), JSON.stringify(faint.problems));
  // The boundary against the form behind the field is the BETTER of the frame and the fill (WCAG 1.4.11): a dark field with a mid-grey frame that is only 2.6:1 on the
  // light form passes because the fill is 16:1 on it; a white field with a frame that is 3.03:1 on the fill but 2.84:1 on the form fails (both edges below 3).
  const sides4 = (c) => ({ borderTopColor: c, borderRightColor: c, borderBottomColor: c, borderLeftColor: c });
  const DARK = K.colour(EDITORIAL.text); const D = { r: DARK.r, g: DARK.g, b: DARK.b };
  const darkM = withField(withField(m, 0, Object.assign({ color: 'rgb(255, 255, 255)' }, sides4('rgb(154, 154, 154)')), { placeholder: { color: 'rgb(255, 255, 255)' } }), 1, Object.assign({ color: 'rgb(255, 255, 255)' }, sides4('rgb(154, 154, 154)')), { placeholder: { color: 'rgb(255, 255, 255)' } });
  const relaxed = K.formContrastProblems(qForm, darkM, [D, D], surfaces, submitM(qForm.button, 'hover').el);
  assert.deepStrictEqual(relaxed.problems, [], JSON.stringify(relaxed.problems));
  assert.ok(relaxed.info.fields[0].topOnForm < 3 && relaxed.info.fields[0].top >= 3 && relaxed.info.fields[0].fillOnForm >= 3 && relaxed.info.fields[0].boundary === relaxed.info.fields[0].fillOnForm, 'frame < 3 on the form, fill >= 3: ' + JSON.stringify(relaxed.info.fields[0]));
  const bothLow = K.formContrastProblems(qForm, withField(m, 0, sides4('rgb(148, 148, 148)')), [F, F], surfaces, submitM(qForm.button, 'hover').el);
  assert.strictEqual(bothLow.problems.length, 1, JSON.stringify(bothLow.problems));
  assert.strictEqual(bothLow.problems[0][0], 'form-contrast');
  assert.match(bothLow.problems[0][1], /^field 1 boundary: neither the frame \(best 2\.84:1\) nor the fill #ffffff \(1\.07:1\) reaches 3 on the form behind it #faf7f2/);
  const noFrame = K.formContrastProblems(qForm, withField(m, 0, { borderTopWidth: '0px', borderRightWidth: '0px', borderBottomWidth: '0px', borderLeftWidth: '0px' }), [F, F], surfaces, submitM(qForm.button, 'hover').el);
  assert.match(noFrame.problems[0][1], /^field 1 boundary: neither the frame \(best none drawn:1\) nor the fill /, 'no frame and a fill that matches the form: no boundary at all');
  assert.strictEqual(K.formContrastProblems(qForm, m, [F, F], surfaces, submitM(qForm.button, 'hover').el).problems.length, 0, 'the real sheet passes');
  const paleCaptcha = K.formContrastProblems(qForm, Object.assign({}, m, { captcha: Object.assign({}, m.captcha, { question: { color: 'rgb(210, 210, 210)' } }) }), [F, F], surfaces, submitM(qForm.button, 'hover').el);
  assert.match(paleCaptcha.problems[0][1], /^captcha ink /);
  const badLabel = K.formContrastProblems(qForm, Object.assign({}, m, { submit: Object.assign({}, m.submit, { el: Object.assign({}, m.submit.el, { color: 'rgb(154, 59, 31)' }) }) }), [F, F], surfaces, submitM(qForm.button, 'hover').el);
  assert.match(badLabel.problems[0][1], /^submit rest: label /);
  // Nothing sampled, nothing skipped.
  assert.ok(K.formContrastProblems(qForm, m, [F, null], surfaces, submitM(qForm.button, 'hover').el).problems.some(([, d]) => /field 2: the painted field fill was not sampled/.test(d)));
  assert.ok(K.formContrastProblems(qForm, m, [F, F], { fields: [B, null], submit: B, captcha: B }, submitM(qForm.button, 'hover').el).problems.some(([, d]) => /field 2: the form behind the field was not sampled/.test(d)));
  assert.ok(K.formContrastProblems(qForm, m, [F, F], { fields: [B, B], submit: null, captcha: B }, submitM(qForm.button, 'hover').el).problems.some(([, d]) => /behind the submit button was not sampled/.test(d)));
  assert.ok(K.formContrastProblems(qForm, m, [F, F], { fields: [B, B], submit: B, captcha: null }, submitM(qForm.button, 'hover').el).problems.some(([, d]) => /behind the captcha was not sampled/.test(d)));
  assert.ok(K.formContrastProblems(qForm, m, [F, F], surfaces, null).problems.some(([, d]) => /submit hover: the hover state was not measured/.test(d)));
  assert.ok(K.formContrastProblems(qForm, Object.assign({}, m, { captcha: { found: false } }), [F, F], surfaces, submitM(qForm.button, 'hover').el).problems.some(([, d]) => /the captcha question is not on the page/.test(d)));
  // An underline field draws one side: only that side is judged.
  const um = mform(uForm);
  assert.deepStrictEqual(K.formContrastProblems(uForm, um, [F, F], surfaces, submitM(uForm.button, 'hover').el).problems, []);
  assert.deepStrictEqual(Object.keys(K.formContrastProblems(uForm, um, [F, F], surfaces, submitM(uForm.button, 'hover').el).info.fields[0]).sort(), ['bottom', 'bottomOnForm', 'boundary', 'fillOnForm', 'ink', 'placeholder']);
});

t('form-clip and overflow: a field or the submit cut by a clipping container, a cut label, anything beyond the form or the window, a page wider than the window', () => {
  const m = mform(qForm);
  assert.deepStrictEqual(K.formClipProblems(m), []);
  const clipping = Object.assign({}, m, { container: { el: Object.assign({}, m.container.el, { overflowX: 'hidden' }), rect: R(20, 80, 380, 420) } });
  assert.deepStrictEqual(K.formClipProblems(clipping), []);
  const stuck = withField(clipping, 1, {}, { rect: R(40, 160, 420, 210) });
  assert.deepStrictEqual(fcodes(K.formClipProblems(stuck)), ['form-clip']);
  assert.match(K.formClipProblems(stuck)[0][1], /^rest: field 2 40\.\.420 x 160\.\.210 is cut by the form container \(overflow hidden\/visible\)/);
  assert.deepStrictEqual(K.formClipProblems(withField(m, 1, {}, { rect: R(40, 160, 420, 210) })), [], 'a container that does not clip cuts nothing (the overflow check reports the field)');
  assert.deepStrictEqual(fcodes(K.formClipProblems(Object.assign({}, m, { submit: Object.assign({}, m.submit, { scrollWidth: 140, clientWidth: 100 }) }))), ['form-clip'], 'a cut label');
  const page = { innerWidth: 400, scrollWidth: 400 };
  assert.deepStrictEqual(K.formOverflowProblems(m, 'rest', page), []);
  assert.deepStrictEqual(K.formOverflowProblems(withField(m, 0, {}, { rect: R(40, 100, 420, 150) }), 'hover', page).map((x) => x[0]), ['overflow', 'overflow'], 'beyond the form and beyond the window');
  assert.match(K.formOverflowProblems(withField(m, 0, {}, { rect: R(40, 100, 420, 150) }), 'hover', page)[0][1], /^hover: field 1 40\.\.420 outside its form 20\.\.380/);
  assert.strictEqual(K.formOverflowProblems(Object.assign({}, m, { captcha: Object.assign({}, m.captcha, { rect: R(40, 230, 500, 270) }) }), 'rest', page).length, 2, 'the captcha row is held to the form too');
  assert.strictEqual(K.formOverflowProblems(m, 'rest', { innerWidth: 400, scrollWidth: 420 }).length, 1);
});

t('form count: every sheet form on the page with its fields, ONE submit and a captcha; an empty slot list is an error, an empty run fails', () => {
  const m = mform(qForm); const s = mform(qForm, { key: 'form-surface' });
  assert.deepStrictEqual(K.formCountProblems([m, s], [2, 2]), []);
  const gone = K.formCountProblems([m, { key: 'form-surface', found: false, submitCount: 0 }], [2, 2]);
  assert.deepStrictEqual(gone.map((x) => x[0]), ['form']);
  assert.match(gone[0][1], /^1 of 2 forms rendered as the sheet says: form-surface is not in the page/);
  assert.match(K.formCountProblems([m, mform(qForm, { fields: [m.fields[0]] })], [2, 2])[0][1], /form-bg renders 1 fields, expected 2|renders 1 fields, expected 2/);
  assert.match(K.formCountProblems([m, mform(qForm, { captcha: { found: false } })], [2, 2])[0][1], /shows no captcha question/);
  assert.match(K.formCountProblems([m, mform(qForm, { submitCount: 2 })], [2, 2])[0][1], /has 2 submit buttons with its label, expected 1/);
  assert.match(K.formCountProblems([m, s, s], [2, 2])[0][1], /3 forms measured for 2 slots/);
  assert.throws(() => K.formCountProblems([], [], []), /no form slots/);
  assert.deepStrictEqual(K.emptyFormRunProblems({ formsChecked: 4 }), []);
  assert.deepStrictEqual(K.emptyFormRunProblems({ formsChecked: 0 }), ['no form was checked: the form selection was empty']);
  assert.deepStrictEqual(K.emptyFormRunProblems({}), ['no form was checked: the form selection was empty'], 'a caller that forgets the form count fails, never skips');
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 12, cardsChecked: 144, itemsChecked: 0 }), ['no item was checked: the item selection was empty'], 'the older run check is unchanged');
});

// ---- images (the media radius, role-less token): the sheet, expectations from the resolved markup, comparison, count, clip, overflow ----
const mhit = (row) => K.mediaAttrs(row.markup)[0];
const mexp = (row) => K.expectMediaFromAttrs(mhit(row).attrs);
const CORNER_KEYS = ['borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius'];
/** The computed style of one element of a measured image: the four corners (a number of px each, or a string) and the overflow. */
const mel = (radius, o = {}) => Object.assign({ overflowX: 'visible', overflowY: 'visible' }, ...CORNER_KEYS.map((k, i) => ({ [k]: (typeof radius[i] === 'number' ? radius[i] + 'px' : radius[i]) })), o);
/** What measureMedia returns for the image of a page that agrees with the expectation, the way Divi 5.15.1 paints it: the radius and overflow:hidden on the wrapper, the img inheriting the radius. */
function mmedia(exp, o = {}) {
  return Object.assign({
    key: 'media-bg', found: true, count: 1,
    img: { el: mel(exp.radius), rect: R(40, 100, 340, 250) },
    wrap: { el: mel(exp.radius, { overflowX: 'hidden', overflowY: 'hidden' }), rect: R(40, 100, 340, 250) },
    module: { el: mel([0, 0, 0, 0]), rect: R(40, 100, 340, 250) },
    parentRect: R(20, 80, 380, 400),
  }, o);
}
const SQ = [0, 0, 0, 0];
const mq = mexp(ROWS.quiet); const mu = mexp(ROWS.under);

t('the sheet carries ONE image on the bg band whose radius is the whole-value token radius-media at image.decoration.border.desktop.value.radius, a built-in pack picture, found again by its unique alt', () => {
  const m = K.sheetMarkup();
  assert.deepStrictEqual(K.MEDIA_SLOTS.map((s) => [s.key, s.band]), [['media-bg', 'bg']]);
  assert.strictEqual(new Set(K.MEDIA_SLOTS.map((s) => s.alt)).size, K.MEDIA_SLOTS.length, 'unique alt');
  const found = K.mediaAttrs(m);
  assert.strictEqual(found.length, 1);
  assert.ok(found[0].attrs, 'the image is in the sheet');
  assert.strictEqual(found[0].attrs.image.decoration.border.desktop.value.radius, '{{aied:kit:radius-media}}');
  assert.match(found[0].attrs.image.innerContent.desktop.value.src, /^\{\{aied:image:[a-z0-9-]+\}\}$/, 'a built-in pack picture, never a third-party host');
  assert.strictEqual(found[0].attrs.aied, undefined, 'a token, not a marker');
  assert.ok(!/"sync"/.test(JSON.stringify(found[0].attrs)), 'the sync key stays out');
  assert.deepStrictEqual(K.mediaAttrs('<!-- wp:divi/text {} /-->').map((x) => x.attrs), [null], 'an image that is not in the markup is reported as absent');
  // The other blocks of the sheet are unchanged by the new one.
  assert.strictEqual(K.pageButtonAttrs(m).length, 8);
  assert.strictEqual(K.cardAttrs(m).filter((c) => c.attrs).length, 20);
  assert.strictEqual(K.itemAttrs(m).filter((c) => c.attrs).length, 10);
  assert.strictEqual(K.formAttrs(m).filter((c) => c.attrs).length, 2);
});

t('image expectations: the four corners are read from the RESOLVED markup (4px for quiet-solid, 0px for underline-link: a square kit is checked too), never a table', () => {
  assert.deepStrictEqual(mq.radius, [4, 4, 4, 4]);
  assert.deepStrictEqual(mu.radius, [0, 0, 0, 0], 'a kit with media 0px resolves to four zeros, which are expectations, not holes');
  assert.deepStrictEqual(mexp(ROWS.brutal).radius, [0, 0, 0, 0]);
  assert.deepStrictEqual(K.missingMediaExpectations(mq), []);
  assert.deepStrictEqual(K.missingMediaExpectations(mu), []);
  // A path that drifted (no radius, a string that never resolved, three corners, a corner that is not a length) is a hole, never a skipped check.
  assert.deepStrictEqual(K.missingMediaExpectations(K.expectMediaFromAttrs({ image: { decoration: {} } })), ['radius']);
  assert.deepStrictEqual(K.missingMediaExpectations(K.expectMediaFromAttrs({ image: { decoration: { border: { desktop: { value: { radius: '{{aied:kit:radius-media}}' } } } } } })), ['radius']);
  assert.deepStrictEqual(K.missingMediaExpectations(K.expectMediaFromAttrs({ image: { decoration: { border: { desktop: { value: { radius: { topLeft: '8px', topRight: '8px', bottomRight: '8px' } } } } } } })), ['radius']);
  assert.deepStrictEqual(K.missingMediaExpectations(K.expectMediaFromAttrs({ image: { decoration: { border: { desktop: { value: { radius: { topLeft: '8px', topRight: 'big', bottomRight: '8px', bottomLeft: '8px' } } } } } } })), ['radius']);
  assert.deepStrictEqual(K.missingMediaExpectations(null), ['radius'], 'no expectation at all is a hole');
});

t('media-radius: the radius on the wrapper that clips (the way Divi paints it), on the img alone, or inherited by both passes; a wrong or missing corner, or a radius on a square kit, fails', () => {
  assert.deepStrictEqual(K.compareMedia(mq, mmedia(mq)), [], 'wrapper radius + overflow hidden + the img inheriting it');
  assert.deepStrictEqual(K.compareMedia(mu, mmedia(mu)), [], 'a square kit: every element square');
  // The radius painted on the img only, the wrapper square and not clipping.
  assert.deepStrictEqual(K.compareMedia(mq, mmedia(mq, { wrap: { el: mel(SQ), rect: R(40, 100, 340, 250) } })), []);
  // The img without an inherited radius under a wrapper that rounds and clips: the picture is still cut to the wrapper.
  assert.deepStrictEqual(K.compareMedia(mq, mmedia(mq, { img: { el: mel(SQ), rect: R(40, 100, 340, 250) } })), []);
  // A wrong corner, wherever it is.
  const wrongImg = K.compareMedia(mq, mmedia(mq, { img: { el: mel([4, 4, 4, 9]), rect: R(40, 100, 340, 250) } }));
  assert.deepStrictEqual(fcodes(wrongImg), ['media-radius']);
  assert.match(wrongImg[0][1], /^rest: img borderBottomLeftRadius 9px, expected 4px/);
  const wrongWrap = K.compareMedia(mq, mmedia(mq, { wrap: { el: mel([4, 4, 4, 4].map((v, i) => (i === 1 ? 16 : v)), { overflowX: 'hidden', overflowY: 'hidden' }), rect: R(40, 100, 340, 250) } }));
  assert.ok(fcodes(wrongWrap).includes('media-radius'));
  assert.match(wrongWrap[0][1], /^rest: wrapper borderTopRightRadius 16px, expected 4px/);
  // No radius anywhere: the picture keeps its square (or baked) corners.
  const none = K.compareMedia(mq, mmedia(mq, { img: { el: mel(SQ), rect: R(40, 100, 340, 250) }, wrap: { el: mel(SQ, { overflowX: 'hidden', overflowY: 'hidden' }), rect: R(40, 100, 340, 250) } }));
  assert.deepStrictEqual(fcodes(none), ['media-radius']);
  assert.match(none[0][1], /no element of the image carries the expected radius/);
  // A square kit whose image still has a rounded wrapper (a baked radius): the stray radius fails even though the img is square.
  const stray = K.compareMedia(mu, mmedia(mu, { wrap: { el: mel([16, 16, 16, 16], { overflowX: 'hidden', overflowY: 'hidden' }), rect: R(40, 100, 340, 250) } }));
  assert.deepStrictEqual(fcodes(stray), ['media-radius']);
  assert.match(stray[0][1], /wrapper borderTopLeftRadius 16px, expected 0px/);
  const strayModule = K.compareMedia(mq, mmedia(mq, { module: { el: mel([30, 30, 30, 30]), rect: R(40, 100, 340, 250) } }));
  assert.ok(strayModule.some(([c, d]) => c === 'media-radius' && /module borderTopLeftRadius 30px, expected 4px/.test(d)), 'a rounded module box is a stray too');
  assert.ok(K.compareMedia(mq, mmedia(mq, { img: null })).some(([c]) => c === 'media-radius'), 'an unmeasured img is never skipped');
  assert.deepStrictEqual(K.compareMedia(K.expectMediaFromAttrs({}), mmedia(mq)).map((x) => x[0]), ['media-expectation'], 'an expectation with a hole is one finding, not a skipped check');
  // Sub-pixel noise passes.
  assert.deepStrictEqual(K.compareMedia(mq, mmedia(mq, { img: { el: mel(['4.2px', '4px', '3.9px', '4px']), rect: R(40, 100, 340, 250) } })), []);
});

t('media-clip: a radius on the wrapper that does not clip leaves the picture square; a rounded img cut by a smaller clipping wrapper shows no rounding; an img bigger than a non-clipping wrapper sticks out', () => {
  const noClip = mmedia(mq, { img: { el: mel(SQ), rect: R(40, 100, 340, 250) }, wrap: { el: mel(mq.radius), rect: R(40, 100, 340, 250) } });
  const a = K.compareMedia(mq, noClip);
  assert.deepStrictEqual(fcodes(a), ['media-clip']);
  assert.match(a[0][1], /^rest: the wrapper carries the radius 4px but is overflow visible\/visible and the picture does not inherit it/);
  // The img inheriting the radius needs no clipping wrapper.
  assert.deepStrictEqual(K.compareMedia(mq, mmedia(mq, { wrap: { el: mel(mq.radius), rect: R(40, 100, 340, 250) } })), []);
  // A rounded img inside a clipping wrapper that is smaller: the wrapper's square edge cuts the rounded corners off.
  const cut = K.compareMedia(mq, mmedia(mq, { img: { el: mel(mq.radius), rect: R(30, 90, 350, 260) }, wrap: { el: mel(SQ, { overflowX: 'hidden', overflowY: 'hidden' }), rect: R(40, 100, 340, 250) } }));
  assert.deepStrictEqual(fcodes(cut), ['media-clip']);
  assert.match(cut[0][1], /^rest: the picture 30\.\.350 x 90\.\.260 is cut by its wrapper 40\.\.340 x 100\.\.250, so its rounded corners are not visible/);
  // An img bigger than a wrapper that does not clip sticks out of it.
  const out = K.compareMedia(mq, mmedia(mq, { img: { el: mel(mq.radius), rect: R(30, 90, 350, 260) }, wrap: { el: mel(SQ), rect: R(40, 100, 340, 250) } }));
  assert.deepStrictEqual(fcodes(out), ['media-clip']);
  assert.match(out[0][1], /sticks out of its wrapper/);
  // A square kit is never asked to clip.
  assert.deepStrictEqual(K.compareMedia(mu, mmedia(mu, { wrap: { el: mel(SQ), rect: R(40, 100, 340, 250) } })), []);
  // One pixel of rounding in the boxes is not a cut.
  assert.deepStrictEqual(K.compareMedia(mq, mmedia(mq, { img: { el: mel(mq.radius), rect: R(40, 100, 340.6, 250.6) } })), []);
});

t('image overflow: the picture, its wrapper or the module past the column or the window, or a page wider than the window', () => {
  const page = { innerWidth: 400, scrollWidth: 400 };
  const m = mmedia(mq);
  assert.deepStrictEqual(K.mediaOverflowProblems(m, 'rest', page), []);
  const wide = mmedia(mq, { img: { el: mel(mq.radius), rect: R(40, 100, 420, 250) } });
  assert.deepStrictEqual(fcodes(K.mediaOverflowProblems(wide, 'rest', page)), ['overflow']);
  assert.match(K.mediaOverflowProblems(wide, 'rest', page)[0][1], /^rest: image 40\.\.420 outside its column 20\.\.380/);
  assert.ok(K.mediaOverflowProblems(mmedia(mq, { wrap: { el: mel(mq.radius), rect: R(40, 100, 500, 250) } }), 'rest', page).some(([, d]) => /wrapper 40\.\.500/.test(d)));
  assert.ok(K.mediaOverflowProblems(mmedia(mq, { parentRect: R(20, 80, 500, 400) }), 'rest', page).some(([, d]) => /outside the window/.test(d)) === false, 'inside the window and its column');
  assert.strictEqual(K.mediaOverflowProblems(m, 'rest', { innerWidth: 400, scrollWidth: 420 }).length, 1);
});

t('image count (code media): the image must be on the page exactly once; an empty slot list is an error and a run that checked no image fails', () => {
  const m = mmedia(mq);
  assert.deepStrictEqual(K.mediaCountProblems([m], K.MEDIA_SLOTS), []);
  const gone = K.mediaCountProblems([{ key: 'media-bg', found: false, count: 0 }], K.MEDIA_SLOTS);
  assert.deepStrictEqual(gone.map((x) => x[0]), ['media']);
  assert.match(gone[0][1], /^0 of 1 images rendered as the sheet says: media-bg is not in the page/);
  assert.match(K.mediaCountProblems([mmedia(mq, { count: 2 })], K.MEDIA_SLOTS)[0][1], /media-bg is on the page 2 times, expected 1/);
  assert.match(K.mediaCountProblems([m, m], K.MEDIA_SLOTS)[0][1], /2 images measured for 1 slots/);
  assert.throws(() => K.mediaCountProblems([], []), /no image slots/);
  assert.deepStrictEqual(K.emptyMediaRunProblems({ mediaChecked: 3 }), []);
  assert.deepStrictEqual(K.emptyMediaRunProblems({ mediaChecked: 0 }), ['no image was checked: the image selection was empty']);
  assert.deepStrictEqual(K.emptyMediaRunProblems({}), ['no image was checked: the image selection was empty'], 'a caller that forgets the image count fails, never skips');
  assert.deepStrictEqual(K.emptyFormRunProblems({ formsChecked: 4 }), [], 'the form run check is unchanged');
});

// ---- rules and chips (roles rule, rule-strong, chip): the sheet, expectations from the resolved markup, comparison, contrast, count, overflow, empty run ----
const RROWS = { quiet: ROWS.quiet, dashed: offlineRow('hairline-dashed', 'editorial'), dbl: offlineRow('double-frame', 'editorial'), dotted: offlineRow('dotted-pill', 'editorial'), raw: offlineRow('raw-frame', 'editorial') };
const rhit = (row, key) => K.ruleAttrs(row.markup).find((x) => x.slot.key === key);
const rexp = (row, key) => { const h = rhit(row, key); return K.expectRuleFromAttrs(h.attrs, h.slot.carrier); };
const chit = (row, key) => K.chipAttrs(row.markup).find((x) => x.slot.key === key);
const cexp = (row, key) => { const h = chit(row, key); return K.expectChipFromAttrs(h.attrs, h.slot.carrier); };
/** The computed top line of a rule, in the form measureRules reports it. */
const lineOf = (w, style, color) => ({ borderTopWidth: w + 'px', borderTopStyle: style, borderTopColor: color });
/** What measureRules returns for a rule that agrees with its expectation (a divider: the `::before` line; a group or column: its own sides, the other three at 0px). */
function mrule(exp, o = {}) {
  const w = exp.top.width; const col = rgbOf(exp.top.color).replace(/^rgb\((.*)\)$/, (m, v) => (K.colour(exp.top.color).a < 1 ? `rgba(${v}, ${K.colour(exp.top.color).a})` : m));
  const side = (cap, width) => ({ [`border${cap}Width`]: width + 'px', [`border${cap}Style`]: width ? exp.top.style : 'none', [`border${cap}Color`]: col });
  const el = Object.assign({}, side('Top', exp.carrier === 'divider' ? 0 : w), side('Right', 0), side('Bottom', 0), side('Left', 0));
  return Object.assign({ key: 'k', found: true, count: 1, carrier: exp.carrier, el, line: lineOf(w, exp.top.style, col), rect: R(40, 100, 340, 102), parentRect: R(20, 80, 380, 400) }, o);
}
const qd = rexp(RROWS.quiet, 'rule-divider-bg'); const sd = rexp(RROWS.quiet, 'rule-strong-divider-bg');
const qg = rexp(RROWS.quiet, 'rule-group-bg'); const sg = rexp(RROWS.quiet, 'rule-strong-group-bg');
const qc = rexp(RROWS.quiet, 'rule-column-bg'); const qdark = rexp(RROWS.quiet, 'rule-divider-dark');
const dashedG = rexp(RROWS.dashed, 'rule-strong-group-bg'); const dblD = rexp(RROWS.dbl, 'rule-divider-bg');

t('the sheet carries 16 rule blocks (the old 10 plus a divider of each role on the surface and accent bands and a top-rule group of each role in an inverted panel) and 5 chips (the old 2 plus a tag on the surface and photo bands and an icon chip on the accent band), each found again by a unique heading or text', () => {
  const m = K.sheetMarkup();
  const OLD_RULES = ['rule-divider-bg', 'rule-group-bg', 'rule-strong-divider-bg', 'rule-strong-group-bg', 'rule-column-bg', 'rule-strong-divider-panel-bg', 'rule-divider-dark', 'rule-group-dark', 'rule-strong-divider-dark', 'rule-strong-group-dark'];
  const NEW_RULES = ['rule-divider-surface', 'rule-strong-divider-surface', 'rule-divider-accent', 'rule-strong-divider-accent', 'rule-group-panel-bg', 'rule-strong-group-panel-bg'];
  assert.deepStrictEqual(K.RULE_SLOTS.map((s) => s.key), [...OLD_RULES, ...NEW_RULES], 'the old slots keep their keys and order; the new ones follow');
  assert.deepStrictEqual(K.RULE_SLOTS.map((s) => [s.role, s.carrier, s.on]), [['rule', 'divider', 'light'], ['rule', 'group', 'light'], ['rule-strong', 'divider', 'light'], ['rule-strong', 'group', 'light'], ['rule', 'column', 'light'], ['rule-strong', 'divider', 'panel'], ['rule', 'divider', 'dark'], ['rule', 'group', 'dark'], ['rule-strong', 'divider', 'dark'], ['rule-strong', 'group', 'dark'],
    ['rule', 'divider', 'surface'], ['rule-strong', 'divider', 'surface'], ['rule', 'divider', 'accent'], ['rule-strong', 'divider', 'accent'], ['rule', 'group', 'panel'], ['rule-strong', 'group', 'panel']]);
  assert.strictEqual(new Set(K.RULE_SLOTS.map((s) => s.heading)).size, K.RULE_SLOTS.length, 'unique headings');
  assert.strictEqual(new Set(K.RULE_SLOTS.map((s) => s.key)).size, K.RULE_SLOTS.length, 'unique keys');
  assert.deepStrictEqual(K.CHIP_SLOTS.map((s) => [s.key, s.carrier, s.on]), [['chip-tag-bg', 'group', 'light'], ['chip-icon-bg', 'icon', 'light'], ['chip-tag-surface', 'group', 'surface'], ['chip-tag-dark', 'group', 'dark'], ['chip-icon-accent', 'icon', 'accent']]);
  assert.strictEqual(new Set(K.CHIP_SLOTS.map((s) => s.text || s.heading)).size, K.CHIP_SLOTS.length, 'unique chip texts or headings');
  const found = K.ruleAttrs(m);
  assert.strictEqual(found.length, 16);
  found.forEach((f) => assert.ok(f.attrs, f.slot.key + ' is in the sheet'));
  // Before the resolver: a marker names the role (and the band, omitted for light); a divider and an icon carry no line or radius of their own.
  assert.deepStrictEqual(found.map((f) => f.attrs.aied), K.RULE_SLOTS.map((s) => (s.on === 'light' ? { kit: s.role } : { kit: s.role, on: s.on })));
  found.filter((f) => f.slot.carrier === 'divider').forEach((f) => assert.strictEqual(f.attrs.divider, undefined, f.slot.key + ' the kit supplies the line'));
  found.filter((f) => f.slot.carrier !== 'divider').forEach((f) => assert.strictEqual(JSON.stringify(f.attrs).includes('border'), false, f.slot.key + ' the kit supplies the top edge'));
  // A panel group rule is the INNERMOST group that holds its heading (the wrapper around it is the unmarked text-filled panel).
  found.filter((f) => f.slot.carrier === 'group' && f.slot.panel).forEach((f) => assert.ok(f.attrs.aied && f.attrs.aied.on === 'panel', f.slot.key + ' is the marked inner group, not the unmarked panel around it'));
  const chips = K.chipAttrs(m);
  assert.strictEqual(chips.length, 5);
  assert.deepStrictEqual(chips.map((c) => c.attrs.aied), K.CHIP_SLOTS.map((s) => (s.on === 'light' ? { kit: 'chip' } : { kit: 'chip', on: s.on })));
  chips.filter((c) => c.slot.carrier === 'group').forEach((c) => assert.strictEqual(JSON.stringify(c.attrs).includes('border'), false, c.slot.key + ' a tag carries no frame or radius of its own'));
  chips.filter((c) => c.slot.carrier === 'icon').forEach((c) => assert.strictEqual(JSON.stringify(c.attrs).includes('border') || JSON.stringify(c.attrs).includes('"background"'), false, c.slot.key + ' an icon chip carries no radius or fill of its own'));
  assert.deepStrictEqual(K.ruleAttrs('<!-- wp:divi/text {} /-->').map((x) => x.attrs).filter(Boolean), [], 'a rule that is not in the markup is reported as absent');
  assert.ok(K.chipAttrs('<!-- wp:divi/text {} /-->').every((x) => x.attrs === null));
  // The panel around a panel group rule is a plain text-filled group and the heading and paragraph inside it are in the band's panel ink (bg), never the page's text colour.
  const panelGroup = m.slice(m.indexOf('Quiet group rule in panel on bg') - 600, m.indexOf('Quiet group rule in panel on bg') + 700);
  assert.match(panelGroup, /"background":\{"desktop":\{"value":\{"color":"\{\{aied:color:text\}\}"\}\}\}/, 'the wrapper is filled with text');
  assert.strictEqual((panelGroup.match(/\{\{aied:color:bg\}\}/g) || []).length >= 2, true, 'heading and paragraph are in the bg ink');
  // The earlier blocks of the sheet are unchanged by the new ones.
  assert.strictEqual(K.pageButtonAttrs(m).length, 8);
  assert.strictEqual(K.cardAttrs(m).filter((c) => c.attrs).length, 20);
  assert.strictEqual(K.itemAttrs(m).filter((c) => c.attrs).length, 10);
  assert.strictEqual(K.formAttrs(m).filter((c) => c.attrs).length, 2);
  assert.strictEqual(K.mediaAttrs(m).filter((c) => c.attrs).length, 1);
});

t('rule expectations are read from the RESOLVED markup (width, style, colour; the widths follow the kit), never a table', () => {
  assert.deepStrictEqual([qd.top, qd.others, qd.carrier], [{ width: 1, style: 'solid', color: '#e3dccf' }, null, 'divider']);
  assert.deepStrictEqual(sd.top, { width: 2, style: 'solid', color: '#1c1a17' });
  assert.deepStrictEqual([qg.top.width, qg.others], [1, { right: 0, bottom: 0, left: 0 }], 'a group rule draws only its top edge');
  assert.deepStrictEqual([qc.carrier, qc.top.width, qc.others], ['column', 1, { right: 0, bottom: 0, left: 0 }]);
  assert.strictEqual(qdark.top.color, 'rgba(251,246,236,0.4)', 'a quiet rule on the photo band is the 40% alpha form');
  assert.deepStrictEqual([dashedG.top.width, dashedG.top.style], [2, 'dashed'], 'a dashed kit');
  assert.deepStrictEqual([dblD.top.width, dblD.top.style], [3, 'double'], 'a double is at least 3px wide');
  assert.deepStrictEqual(rexp(RROWS.raw, 'rule-strong-group-bg').top.width, 4);
  assert.deepStrictEqual(rexp(RROWS.dotted, 'rule-divider-bg').top, { width: 2, style: 'dotted', color: '#e3dccf' });
  assert.strictEqual(rexp(RROWS.quiet, 'rule-strong-divider-panel-bg').top.color, '#faf7f2', 'an inverted panel takes the bg ink');
  for (const exp of [qd, sd, qg, sg, qc, qdark, dashedG, dblD]) assert.deepStrictEqual(K.missingRuleExpectations(exp), []);
  // A path that drifted (no line, a width that is not a length, an unknown style, a colour that never resolved, a side that is not 0) is a hole, never a skipped check.
  assert.deepStrictEqual(K.missingRuleExpectations(K.expectRuleFromAttrs({}, 'divider')), ['top.width', 'top.style', 'top.color']);
  assert.deepStrictEqual(K.missingRuleExpectations(K.expectRuleFromAttrs({ divider: { advanced: { line: { desktop: { value: { weight: '2px', style: 'wavy', color: '{{aied:color:text}}' } } } } } }, 'divider')), ['top.style', 'top.color']);
  assert.deepStrictEqual(K.missingRuleExpectations(K.expectRuleFromAttrs({ module: { decoration: { border: { desktop: { value: { styles: { top: { width: 'thick', style: 'solid', color: '#000000' } } } } } } } }, 'group')), ['top.width', 'others.right', 'others.bottom', 'others.left']);
  assert.deepStrictEqual(K.missingRuleExpectations(null), ['top']);
  assert.deepStrictEqual(K.missingRuleExpectations({ carrier: 'group', top: { width: 2, style: 'solid', color: '#000000' }, others: { right: 0, bottom: 0 } }), ['others.left']);
});

t('compareRule: a rule that agrees passes (all carriers, solid, dashed, double, alpha); a wrong style, width, colour or a stray side fails with its own code', () => {
  for (const exp of [qd, sd, qg, sg, qc, qdark, dashedG, dblD]) assert.deepStrictEqual(K.compareRule(exp, mrule(exp)), [], exp.carrier + ' ' + exp.top.width + exp.top.style);
  // The dashed line where the kit says solid, and the other way round.
  const dashedWhereSolid = K.compareRule(sg, mrule(sg, { line: lineOf(2, 'dashed', 'rgb(28, 26, 23)') }));
  assert.deepStrictEqual(fcodes(dashedWhereSolid), ['rule-style']);
  assert.match(dashedWhereSolid[0][1], /^rest: top line is dashed, expected solid/);
  assert.deepStrictEqual(fcodes(K.compareRule(dashedG, mrule(dashedG, { line: lineOf(2, 'solid', 'rgb(28, 26, 23)') }))), ['rule-style']);
  assert.deepStrictEqual(fcodes(K.compareRule(dblD, mrule(dblD, { line: lineOf(3, 'solid', 'rgb(227, 220, 207)') }))), ['rule-style'], 'a double painted solid');
  // A 1px line where the kit says 2px, and a 2px one where it says 1px.
  const thin = K.compareRule(sd, mrule(sd, { line: lineOf(1, 'solid', 'rgb(28, 26, 23)') }));
  assert.deepStrictEqual(fcodes(thin), ['rule-width']);
  assert.match(thin[0][1], /^rest: top line is 1px, expected 2px/);
  assert.deepStrictEqual(fcodes(K.compareRule(qd, mrule(qd, { line: lineOf(2, 'solid', 'rgb(227, 220, 207)') }))), ['rule-width']);
  // A wrong colour, an almost right one (within 1 per channel) and the alpha form.
  const wrong = K.compareRule(sd, mrule(sd, { line: lineOf(2, 'solid', 'rgb(220, 38, 38)') }));
  assert.deepStrictEqual(fcodes(wrong), ['rule-colour']);
  assert.match(wrong[0][1], /^rest: top line colour rgb\(220, 38, 38\), expected #1c1a17/);
  assert.deepStrictEqual(K.compareRule(sd, mrule(sd, { line: lineOf(2, 'solid', 'rgb(28, 26, 24)') })), [], 'one unit of a channel is rounding');
  assert.deepStrictEqual(fcodes(K.compareRule(qdark, mrule(qdark, { line: lineOf(1, 'solid', 'rgba(251, 246, 236, 0.9)') }))), ['rule-colour'], 'the 40% alpha is part of the colour');
  assert.deepStrictEqual(fcodes(K.compareRule(qdark, mrule(qdark, { line: lineOf(1, 'solid', 'rgb(251, 246, 236)') }))), ['rule-colour']);
  // Quarter-pixel noise passes, 0.5px does not.
  assert.deepStrictEqual(K.compareRule(sd, mrule(sd, { line: lineOf(2.2, 'solid', 'rgb(28, 26, 23)') })), []);
  assert.deepStrictEqual(fcodes(K.compareRule(sd, mrule(sd, { line: lineOf(2.5, 'solid', 'rgb(28, 26, 23)') }))), ['rule-width']);
  // Only the top edge is drawn: any other side with a width fails (group and column), and an unmeasured side is not a pass.
  const stray = K.compareRule(sg, mrule(sg, { el: Object.assign({}, mrule(sg).el, { borderBottomWidth: '2px' }) }));
  assert.deepStrictEqual(fcodes(stray), ['rule-width']);
  assert.match(stray[0][1], /^rest: bottom side is 2px, expected 0px/);
  assert.deepStrictEqual(fcodes(K.compareRule(qc, mrule(qc, { el: Object.assign({}, mrule(qc).el, { borderLeftWidth: '1px', borderRightWidth: '1px' }) }))), ['rule-width']);
  assert.ok(K.compareRule(sg, mrule(sg, { el: undefined })).some(([c]) => c === 'rule-width'), 'a group with no measured sides is never skipped');
  // A divider is judged on its line only: its module box sides do not matter.
  assert.deepStrictEqual(K.compareRule(sd, mrule(sd, { el: Object.assign({}, mrule(sd).el, { borderBottomWidth: '3px' }) })), []);
  // A rule that was not measured, and an expectation with a hole, are failures, not skips.
  assert.deepStrictEqual(fcodes(K.compareRule(sd, mrule(sd, { line: undefined }))), ['rule-width']);
  assert.deepStrictEqual(fcodes(K.compareRule(K.expectRuleFromAttrs({}, 'group'), mrule(sg))), ['rule-expectation']);
  assert.deepStrictEqual(fcodes(K.compareRule(null, mrule(sg))), ['rule-expectation'], 'a null expectation never skips');
});

t('rule-contrast: the line as painted over the PAINTED band; a strong rule needs 3.0:1, a quiet one 1.1:1; a missing sample, an unreadable colour and a line that is not drawn fail', () => {
  const white = { r: 255, g: 255, b: 255 };
  const strongOk = K.ruleContrastProblems(mrule(sd), { r: 250, g: 247, b: 242 }, 'rule-strong');
  assert.deepStrictEqual(strongOk.problems, []);
  assert.ok(strongOk.info.line > 10 && strongOk.info.need === 3.0);
  // A strong line at 2.5:1 fails, at 3.0:1 passes; the numbers are the ones Task 4 proves.
  const grey = (v) => mrule(sd, { line: lineOf(2, 'solid', `rgb(${v}, ${v}, ${v})`) });
  const at25 = K.ruleContrastProblems(grey(164), white, 'rule-strong');
  assert.deepStrictEqual(at25.problems.map((x) => x[0]), ['rule-contrast']);
  assert.match(at25.problems[0][1], /^rule-strong line #a4a4a4 on the band #ffffff is 2\.4[0-9]:1, needs 3$/);
  assert.deepStrictEqual(K.ruleContrastProblems(grey(118), white, 'rule-strong').problems, [], '118,118,118 is 4.5:1');
  assert.ok(K.ruleContrastProblems(grey(160), white, 'rule-strong').problems.length === 1, '2.6:1 fails');
  assert.deepStrictEqual(K.ruleContrastProblems(grey(149), white, 'rule-strong').problems.length, 1, '2.995:1 is under 3.0:1 (the finding compares the exact ratio, not the rounded one)');
  assert.deepStrictEqual(K.ruleContrastProblems(grey(148), white, 'rule-strong').problems.length, 0, '3.03:1 passes');
  assert.strictEqual(K.RULE_STRONG_MIN, 3.0);
  assert.strictEqual(K.RULE_QUIET_MIN, 1.1);
  // A quiet line: invisible (the band's own colour) fails, the library's faint hairline passes.
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(qd, { line: lineOf(1, 'solid', 'rgb(255, 255, 255)') }), white, 'rule').problems.map((x) => x[0]), ['rule-contrast']);
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(qd, { line: lineOf(1, 'solid', 'rgb(245, 245, 245)') }), white, 'rule').problems.length, 1, '1.09:1 is under the quiet floor');
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(qd, { line: lineOf(1, 'solid', 'rgb(240, 240, 240)') }), white, 'rule').problems, [], '1.14:1 is above it');
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(qd), { r: 250, g: 247, b: 242 }, 'rule').problems, [], 'the editorial hairline on the editorial bg');
  // The alpha form is composited over the band: 40% bone over a dark photo mean, and over a pale one it vanishes.
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(qdark), { r: 30, g: 40, b: 50 }, 'rule').problems, []);
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(qdark), { r: 245, g: 240, b: 230 }, 'rule').problems.length, 1);
  // Nothing is skipped.
  assert.match(K.ruleContrastProblems(mrule(sd), null, 'rule-strong').problems[0][1], /band behind the rule was not sampled/);
  assert.deepStrictEqual(K.ruleContrastProblems(null, white, 'rule').problems.map((x) => x[0]), ['rule-contrast']);
  assert.match(K.ruleContrastProblems(mrule(sd, { line: lineOf(2, 'solid', 'currentcolor') }), white, 'rule-strong').problems[0][1], /unreadable line colour/);
  assert.match(K.ruleContrastProblems(mrule(sd, { line: lineOf(0, 'none', 'rgb(0, 0, 0)') }), white, 'rule-strong').problems[0][1], /no line is drawn/);
});

t('rule overflow, rule count (code rule) and a run that checked no rule', () => {
  const page = { innerWidth: 400, scrollWidth: 400 };
  assert.deepStrictEqual(K.ruleOverflowProblems(mrule(qg), 'rest', page), []);
  const wide = K.ruleOverflowProblems(mrule(qg, { rect: R(40, 100, 420, 102) }), 'rest', page);
  assert.deepStrictEqual(fcodes(wide), ['overflow']);
  assert.match(wide[0][1], /^rest: rule 40\.\.420 outside its container 20\.\.380/);
  assert.strictEqual(K.ruleOverflowProblems(mrule(qg), 'rest', { innerWidth: 400, scrollWidth: 420 }).length, 1);
  const all = K.RULE_SLOTS.map((s, i) => ({ key: s.key, found: true, count: 1 }));
  assert.deepStrictEqual(K.ruleCountProblems(all, K.RULE_SLOTS), []);
  const gone = K.ruleCountProblems(all.map((m, i) => (i === 3 ? { key: m.key, found: false, count: 0 } : m)), K.RULE_SLOTS);
  assert.deepStrictEqual(gone.map((x) => x[0]), ['rule']);
  assert.match(gone[0][1], /^15 of 16 rules rendered as the sheet says: rule-strong-group-bg is not in the page/);
  assert.match(K.ruleCountProblems(all.map((m, i) => (i === 0 ? { key: m.key, found: true, count: 2 } : m)), K.RULE_SLOTS)[0][1], /rule-divider-bg is on the page 2 times, expected 1/);
  assert.match(K.ruleCountProblems([...all, all[0]], K.RULE_SLOTS)[0][1], /17 rules measured for 16 slots/);
  assert.match(K.ruleCountProblems([], K.RULE_SLOTS)[0][1], /^0 of 16 rules rendered/);
  assert.throws(() => K.ruleCountProblems([], []), /no rule slots/);
  assert.deepStrictEqual(K.emptyRuleRunProblems({ rulesChecked: 30 }), []);
  assert.deepStrictEqual(K.emptyRuleRunProblems({ rulesChecked: 0 }), ['no rule was checked: the rule selection was empty']);
  assert.deepStrictEqual(K.emptyRuleRunProblems({}), ['no rule was checked: the rule selection was empty'], 'a caller that forgets the rule count fails, never skips');
  assert.deepStrictEqual(K.emptyMediaRunProblems({ mediaChecked: 3 }), [], 'the image run check is unchanged');
});

// ---- chips ----
const tagQ = cexp(RROWS.quiet, 'chip-tag-bg'); const iconQ = cexp(RROWS.quiet, 'chip-icon-bg');
const tagRaw = cexp(RROWS.raw, 'chip-tag-bg'); const iconRaw = cexp(RROWS.raw, 'chip-icon-bg');
const tagPill = cexp(RROWS.dotted, 'chip-tag-bg'); const tagDbl = cexp(RROWS.dbl, 'chip-tag-bg'); const tagDash = cexp(RROWS.dashed, 'chip-tag-bg');
/** What measureChips returns for a chip that agrees with its expectation (a tag: the frame on all four sides and no fill; an icon chip: the fill on the module box and the glyph colour). */
const cssOf = (c) => { const x = K.colour(c); return x.a < 1 ? `rgba(${x.r}, ${x.g}, ${x.b}, ${x.a})` : `rgb(${x.r}, ${x.g}, ${x.b})`; };
function mchip(exp, o = {}) {
  const radius = Object.assign({}, ...CORNER_KEYS.map((k, i) => ({ [k]: exp.radius[i] + 'px' })));
  const fr = exp.kind === 'tag' ? Object.assign({}, ...['Top', 'Right', 'Bottom', 'Left'].map((c) => ({ [`border${c}Width`]: exp.frame.width + 'px', [`border${c}Style`]: exp.frame.style, [`border${c}Color`]: cssOf(exp.frame.color) }))) : Object.assign({}, ...['Top', 'Right', 'Bottom', 'Left'].map((c) => ({ [`border${c}Width`]: '0px', [`border${c}Style`]: 'none', [`border${c}Color`]: 'rgb(0, 0, 0)' })));
  const fill = exp.kind === 'icon' ? rgbOf(exp.fill) : 'rgba(0, 0, 0, 0)';
  return Object.assign({ key: 'k', found: true, count: 1, carrier: exp.kind === 'tag' ? 'group' : 'icon', el: Object.assign({}, radius, fr, { backgroundColor: fill }), glyph: exp.kind === 'icon' ? { color: rgbOf(exp.glyph) } : null, rect: R(40, 100, 140, 130), parentRect: R(20, 80, 380, 400) }, o);
}

t('chip expectations are read from the RESOLVED markup: the control radius (a 0px kit gives four zeros, which are expectations), the tag frame, the icon fill and glyph', () => {
  assert.deepStrictEqual(tagQ, { kind: 'tag', radius: [4, 4, 4, 4], frame: { width: 1, style: 'solid', color: '#e3dccf' } });
  assert.deepStrictEqual(iconQ, { kind: 'icon', radius: [4, 4, 4, 4], fill: '#9a3b1f', glyph: '#ffffff' });
  assert.deepStrictEqual(tagRaw.radius, [0, 0, 0, 0]);
  assert.deepStrictEqual(iconRaw.radius, [0, 0, 0, 0], 'a 0px chip kit is four zeros');
  assert.deepStrictEqual(tagPill.radius, [999, 999, 999, 999]);
  assert.deepStrictEqual(tagPill.frame, { width: 2, style: 'dotted', color: '#1c1a17' });
  assert.deepStrictEqual(tagDbl.frame, { width: 4, style: 'double', color: '#1c1a17' });
  assert.deepStrictEqual(tagDash.frame, { width: 1, style: 'dashed', color: '#e3dccf' });
  for (const e of [tagQ, iconQ, tagRaw, iconRaw, tagPill, tagDbl, tagDash]) assert.deepStrictEqual(K.missingChipExpectations(e), []);
  // Holes: no radius, a token that never resolved, three corners, a corner that is not a length, no frame, an unknown style, no fill, no glyph.
  assert.deepStrictEqual(K.missingChipExpectations(K.expectChipFromAttrs({}, 'icon')), ['radius', 'fill', 'glyph']);
  assert.deepStrictEqual(K.missingChipExpectations(K.expectChipFromAttrs({}, 'group')), ['radius', 'frame.width', 'frame.style', 'frame.color']);
  assert.deepStrictEqual(K.missingChipExpectations(K.expectChipFromAttrs({ module: { decoration: { border: { desktop: { value: { radius: '{{aied:kit:radius-control}}' } } } } } }, 'icon')), ['radius', 'fill', 'glyph']);
  assert.deepStrictEqual(K.missingChipExpectations(K.expectChipFromAttrs({ module: { decoration: { border: { desktop: { value: { radius: { topLeft: '4px', topRight: '4px', bottomRight: '4px' } } } } } } }, 'icon')).slice(0, 1), ['radius']);
  assert.deepStrictEqual(K.missingChipExpectations(K.expectChipFromAttrs({ module: { decoration: { border: { desktop: { value: { radius: { topLeft: '4px', topRight: 'big', bottomRight: '4px', bottomLeft: '4px' }, styles: { all: { width: '1px', style: 'wavy', color: '#000000' } } } } } } } }, 'group')), ['radius', 'frame.style']);
  assert.deepStrictEqual(K.missingChipExpectations(null), ['chip']);
});

t('compareChip: a chip that agrees passes (tag and icon, a 0px kit, a pill, dashed, double); a wrong corner, frame side, style, colour, a fill on a tag, a wrong icon fill or glyph fails with its own code', () => {
  for (const e of [tagQ, iconQ, tagRaw, iconRaw, tagPill, tagDbl, tagDash]) assert.deepStrictEqual(K.compareChip(e, mchip(e)), [], e.kind + e.radius);
  // Radius: a rounded chip on a 0px kit, a square one on a rounded kit, one wrong corner.
  const rounded = K.compareChip(tagRaw, mchip(tagRaw, { el: Object.assign({}, mchip(tagRaw).el, ...CORNER_KEYS.map((k) => ({ [k]: '12px' }))) }));
  assert.deepStrictEqual([...new Set(fcodes(rounded))], ['chip-radius']);
  assert.strictEqual(rounded.length, 4);
  assert.match(rounded[0][1], /^rest: borderTopLeftRadius 12px, expected 0px/);
  assert.deepStrictEqual(fcodes(K.compareChip(iconRaw, mchip(iconRaw, { el: Object.assign({}, mchip(iconRaw).el, { borderBottomLeftRadius: '999px' }) }))), ['chip-radius']);
  assert.strictEqual(K.compareChip(iconQ, mchip(iconQ, { el: Object.assign({}, mchip(iconQ).el, ...CORNER_KEYS.map((k) => ({ [k]: '0px' }))) })).length, 4, 'a square chip on a rounded kit');
  assert.deepStrictEqual(K.compareChip(tagQ, mchip(tagQ, { el: Object.assign({}, mchip(tagQ).el, { borderTopLeftRadius: '4.3px' }) })), [], 'sub-pixel noise passes');
  // Frame: a missing frame, a side of the wrong width, style or colour.
  const noFrame = K.compareChip(tagQ, mchip(tagQ, { el: Object.assign({}, mchip(tagQ).el, ...['Top', 'Right', 'Bottom', 'Left'].map((c) => ({ [`border${c}Width`]: '0px', [`border${c}Style`]: 'none' }))) }));
  assert.deepStrictEqual([...new Set(fcodes(noFrame))], ['chip-frame']);
  assert.strictEqual(noFrame.length, 4);
  assert.match(noFrame[0][1], /^rest: border-top width 0px, expected 1px/);
  const dashedWhereSolid = K.compareChip(tagQ, mchip(tagQ, { el: Object.assign({}, mchip(tagQ).el, { borderRightStyle: 'dashed' }) }));
  assert.deepStrictEqual(fcodes(dashedWhereSolid), ['chip-frame']);
  assert.match(dashedWhereSolid[0][1], /^rest: border-right style dashed, expected solid/);
  assert.deepStrictEqual(fcodes(K.compareChip(tagDbl, mchip(tagDbl, { el: Object.assign({}, mchip(tagDbl).el, { borderBottomWidth: '2px' }) }))), ['chip-frame']);
  assert.deepStrictEqual(fcodes(K.compareChip(tagQ, mchip(tagQ, { el: Object.assign({}, mchip(tagQ).el, { borderLeftColor: 'rgb(255, 0, 0)' }) }))), ['chip-frame']);
  // Fill: a tag has none; the icon's computed fill and glyph are the resolved ones.
  const filledTag = K.compareChip(tagQ, mchip(tagQ, { el: Object.assign({}, mchip(tagQ).el, { backgroundColor: 'rgb(154, 59, 31)' }) }));
  assert.deepStrictEqual(fcodes(filledTag), ['chip-fill']);
  assert.match(filledTag[0][1], /^rest: a tag has no fill, background is rgb\(154, 59, 31\)/);
  const bandFill = K.compareChip(iconQ, mchip(iconQ, { el: Object.assign({}, mchip(iconQ).el, { backgroundColor: 'rgb(250, 247, 242)' }) }));
  assert.deepStrictEqual(fcodes(bandFill), ['chip-fill']);
  assert.match(bandFill[0][1], /^rest: fill rgb\(250, 247, 242\), expected #9a3b1f/);
  assert.deepStrictEqual(fcodes(K.compareChip(iconQ, mchip(iconQ, { glyph: { color: 'rgb(154, 59, 31)' } }))), ['chip-fill']);
  assert.match(K.compareChip(iconQ, mchip(iconQ, { glyph: null }))[0][1], /glyph \(span\.et-pb-icon\) was not found/);
  // Nothing is skipped: an unmeasured chip and an expectation with a hole are findings.
  assert.deepStrictEqual(fcodes(K.compareChip(tagQ, mchip(tagQ, { el: undefined }))), ['chip']);
  assert.deepStrictEqual(fcodes(K.compareChip(K.expectChipFromAttrs({}, 'group'), mchip(tagQ))), ['chip-expectation']);
  assert.deepStrictEqual(fcodes(K.compareChip(null, mchip(tagQ))), ['chip-expectation'], 'a null expectation never skips');
});

t('chip-contrast: the glyph on the PAINTED icon fill >= 3.0 and the fill not the colour of the band; a tag frame against the painted band >= 3.0, or the quiet floor for the language hairline', () => {
  const band = { r: 250, g: 247, b: 242 }; const fill = { r: 154, g: 59, b: 31 };
  const ok = K.chipContrastProblems(iconQ, mchip(iconQ), { fill, band });
  assert.deepStrictEqual(ok.problems, []);
  assert.ok(ok.info.glyph > 3 && ok.info.fillOnBand > 1.02);
  // A glyph equal to its fill, and one just under 3.0:1.
  const same = K.chipContrastProblems(iconQ, mchip(iconQ, { glyph: { color: 'rgb(154, 59, 31)' } }), { fill, band });
  assert.deepStrictEqual(same.problems.map((x) => x[0]), ['chip-contrast']);
  assert.match(same.problems[0][1], /^glyph #9a3b1f on the chip fill #9a3b1f is 1:1, needs 3$/);
  assert.strictEqual(K.chipContrastProblems(iconQ, mchip(iconQ, { glyph: { color: 'rgb(150, 150, 150)' } }), { fill: { r: 255, g: 255, b: 255 }, band }).problems.length, 1);
  // An icon fill equal to the band: the chip cannot be seen.
  const invisible = K.chipContrastProblems(iconQ, mchip(iconQ), { fill: band, band });
  assert.ok(invisible.problems.some(([c, d]) => c === 'chip-contrast' && /is the colour of the band/.test(d)), JSON.stringify(invisible.problems));
  // A tag frame: a strong frame must be 3.0, the language hairline only the quiet floor.
  const hair = '#e3dccf';
  assert.deepStrictEqual(K.chipContrastProblems(tagQ, mchip(tagQ), { band }, hair).problems, [], 'the hairline frame on the editorial bg is above the quiet floor');
  assert.strictEqual(K.chipContrastProblems(tagQ, mchip(tagQ), { band }, null).problems.length, 4, 'without a hairline the same frame would need 3.0');
  assert.deepStrictEqual(K.chipContrastProblems(tagRaw, mchip(tagRaw), { band }, hair).problems, [], 'a text frame is 3.0+');
  const faint = mchip(tagRaw, { el: Object.assign({}, mchip(tagRaw).el, ...['Top', 'Right', 'Bottom', 'Left'].map((c) => ({ [`border${c}Color`]: 'rgb(240, 236, 228)' }))) });
  assert.deepStrictEqual([...new Set(K.chipContrastProblems(tagRaw, faint, { band }, hair).problems.map((x) => x[0]))], ['chip-contrast']);
  assert.match(K.chipContrastProblems(tagRaw, faint, { band }, hair).problems[0][1], /^frame top #f0ece4 on the band #faf7f2 is 1\.1:1, needs 3$/);
  // Nothing is skipped.
  assert.match(K.chipContrastProblems(tagQ, mchip(tagQ), { band: null }, hair).problems[0][1], /band behind the chip was not sampled/);
  assert.match(K.chipContrastProblems(iconQ, mchip(iconQ), { fill: null, band }).problems[0][1], /painted fill of the chip was not sampled/);
  assert.match(K.chipContrastProblems(iconQ, mchip(iconQ, { glyph: null }), { fill, band }).problems[0][1], /unreadable glyph colour/);
  assert.deepStrictEqual(K.chipContrastProblems(iconQ, mchip(iconQ), undefined).problems.map((x) => x[0]), ['chip-contrast']);
});

t('chip painted fill, overflow, count (code chip) and a run that checked no chip', () => {
  const m = mchip(iconQ);
  assert.deepStrictEqual(K.chipPaintedFillProblems(m, { r: 154, g: 59, b: 31 }), []);
  assert.deepStrictEqual(K.chipPaintedFillProblems(m, { r: 156, g: 60, b: 33 }), [], 'rounding');
  assert.match(K.chipPaintedFillProblems(m, { r: 40, g: 40, b: 40 })[0][1], /^painted fill #282828 differs from the computed rgb\(154, 59, 31\)/);
  assert.deepStrictEqual(fcodes(K.chipPaintedFillProblems(m, null)), ['chip-fill'], 'a missing sample is a finding');
  assert.deepStrictEqual(K.chipPaintedFillProblems(mchip(tagQ), null), [], 'a tag has no fill to paint');
  const page = { innerWidth: 400, scrollWidth: 400 };
  assert.deepStrictEqual(K.chipOverflowProblems(m, 'rest', page), []);
  assert.match(K.chipOverflowProblems(mchip(iconQ, { rect: R(40, 100, 420, 130) }), 'rest', page)[0][1], /^rest: chip 40\.\.420 outside its container/);
  const all = K.CHIP_SLOTS.map((s) => ({ key: s.key, found: true, count: 1, glyph: s.carrier === 'icon' ? { color: '#fff' } : null }));
  assert.deepStrictEqual(K.chipCountProblems(all, K.CHIP_SLOTS), []);
  const gone = K.chipCountProblems(all.map((m, i) => (K.CHIP_SLOTS[i].key === 'chip-icon-bg' ? { key: m.key, found: false, count: 0 } : m)), K.CHIP_SLOTS);
  assert.deepStrictEqual(gone.map((x) => x[0]), ['chip']);
  assert.match(gone[0][1], /^4 of 5 chips rendered as the sheet says: chip-icon-bg is not in the page/);
  assert.match(K.chipCountProblems(all.map((m, i) => (i === 0 ? { key: m.key, found: true, count: 2 } : m)), K.CHIP_SLOTS)[0][1], /chip-tag-bg is on the page 2 times, expected 1/);
  assert.match(K.chipCountProblems(all.map((m, i) => (K.CHIP_SLOTS[i].key === 'chip-icon-bg' ? { key: m.key, found: true, count: 1, glyph: null } : m)), K.CHIP_SLOTS)[0][1], /chip-icon-bg has no glyph/);
  assert.match(K.chipCountProblems([...all, all[0]], K.CHIP_SLOTS)[0][1], /6 chips measured for 5 slots/);
  assert.throws(() => K.chipCountProblems([], []), /no chip slots/);
  assert.deepStrictEqual(K.emptyChipRunProblems({ chipsChecked: 6 }), []);
  assert.deepStrictEqual(K.emptyChipRunProblems({ chipsChecked: 0 }), ['no chip was checked: the chip selection was empty']);
  assert.deepStrictEqual(K.emptyChipRunProblems({}), ['no chip was checked: the chip selection was empty'], 'a caller that forgets the chip count fails, never skips');
  assert.deepStrictEqual(K.emptyRuleRunProblems({ rulesChecked: 4 }), [], 'the rule run check is unchanged');
});

// ---- the branches the first rules-and-chips gate never rendered (guide polish Task 2): dividers on the surface and accent bands, a top-rule group in an inverted
// panel, a tag on the surface and photo bands, an icon chip on the accent band ----
const NEW_RULE_KEYS = ['rule-divider-surface', 'rule-strong-divider-surface', 'rule-divider-accent', 'rule-strong-divider-accent', 'rule-group-panel-bg', 'rule-strong-group-panel-bg'];
const NEW_CHIP_KEYS = ['chip-tag-surface', 'chip-tag-dark', 'chip-icon-accent'];
const nq = Object.fromEntries(NEW_RULE_KEYS.map((k) => [k, rexp(RROWS.quiet, k)]));
const nr = Object.fromEntries(NEW_RULE_KEYS.map((k) => [k, rexp(RROWS.raw, k)]));
const ncq = Object.fromEntries(NEW_CHIP_KEYS.map((k) => [k, cexp(RROWS.quiet, k)]));
const ncr = Object.fromEntries(NEW_CHIP_KEYS.map((k) => [k, cexp(RROWS.raw, k)]));
const newRuleSlot = (key) => K.RULE_SLOTS.find((x) => x.key === key);
const newChipSlot = (key) => K.CHIP_SLOTS.find((x) => x.key === key);

t('new rule slots: expectations are read from the RESOLVED markup per band (surface = the light colours, accent = accent-text and its 40% alpha form, panel = the bg ink and its alpha form)', () => {
  assert.deepStrictEqual(nq['rule-divider-surface'].top, { width: 1, style: 'solid', color: '#e3dccf' }, 'a quiet divider on the surface band is the language hairline');
  assert.deepStrictEqual(nq['rule-strong-divider-surface'].top, { width: 2, style: 'solid', color: '#1c1a17' });
  assert.deepStrictEqual(nq['rule-divider-accent'].top, { width: 1, style: 'solid', color: 'rgba(255,255,255,0.4)' }, 'a quiet rule on the accent band is accent-text at 40% (the alpha form)');
  assert.deepStrictEqual(nq['rule-strong-divider-accent'].top, { width: 2, style: 'solid', color: '#ffffff' });
  assert.deepStrictEqual(nq['rule-group-panel-bg'].top, { width: 1, style: 'solid', color: 'rgba(250,247,242,0.4)' }, 'a quiet group rule inside an inverted panel is bg at 40%');
  assert.deepStrictEqual(nq['rule-strong-group-panel-bg'].top, { width: 2, style: 'solid', color: '#faf7f2' });
  for (const k of ['rule-group-panel-bg', 'rule-strong-group-panel-bg']) assert.deepStrictEqual(nq[k].others, { right: 0, bottom: 0, left: 0 }, k + ' only the top edge');
  for (const k of ['rule-divider-surface', 'rule-strong-divider-surface', 'rule-divider-accent', 'rule-strong-divider-accent']) assert.strictEqual(nq[k].others, null, k + ' a divider has no other sides');
  // The widths follow the kit through the new bands as they do on the old ones (raw-frame 4px: a strong rule 4px, a quiet one 2px).
  assert.deepStrictEqual([nr['rule-strong-divider-accent'].top.width, nr['rule-divider-accent'].top.width, nr['rule-strong-group-panel-bg'].top.width, nr['rule-group-panel-bg'].top.width], [4, 2, 4, 2]);
  assert.deepStrictEqual(rexp(RROWS.dbl, 'rule-strong-divider-surface').top.style, 'double');
  assert.ok(rexp(RROWS.dbl, 'rule-group-panel-bg').top.width >= 3, 'a double is at least 3px on the panel too');
  for (const k of NEW_RULE_KEYS) { assert.deepStrictEqual(K.missingRuleExpectations(nq[k]), [], k); assert.deepStrictEqual(K.missingRuleExpectations(nr[k]), [], k); }
});

t('new rule slots: a rule that agrees passes; a wrong style, width, colour (alpha included) or a stray side fails with its own code on EVERY new slot', () => {
  for (const k of NEW_RULE_KEYS) {
    for (const exp of [nq[k], nr[k], rexp(RROWS.dashed, k), rexp(RROWS.dbl, k)]) assert.deepStrictEqual(K.compareRule(exp, mrule(exp)), [], k + ' ' + exp.top.width + exp.top.style);
    const e = nq[k]; const col = mrule(e).line.borderTopColor;
    const swapStyle = e.top.style === 'dashed' ? 'dotted' : 'dashed';
    assert.deepStrictEqual(fcodes(K.compareRule(e, mrule(e, { line: lineOf(e.top.width, swapStyle, col) }))), ['rule-style'], k + ' a wrong style');
    assert.deepStrictEqual(fcodes(K.compareRule(e, mrule(e, { line: lineOf(e.top.width + 1, e.top.style, col) }))), ['rule-width'], k + ' a wrong width');
    assert.deepStrictEqual(fcodes(K.compareRule(e, mrule(e, { line: lineOf(e.top.width, e.top.style, 'rgb(220, 38, 38)') }))), ['rule-colour'], k + ' a wrong colour');
    assert.strictEqual(K.colour(e.top.color).a < 1, /accent|panel/.test(k) && !/strong/.test(k), k + ' the alpha form is exactly the quiet rule on accent and panel');
    if (K.colour(e.top.color).a < 1) assert.deepStrictEqual(fcodes(K.compareRule(e, mrule(e, { line: lineOf(e.top.width, e.top.style, rgbOf(e.top.color)) }))), ['rule-colour'], k + ' the 40% alpha is part of the colour');
    if (e.carrier === 'group') {
      const stray = K.compareRule(e, mrule(e, { el: Object.assign({}, mrule(e).el, { borderBottomWidth: '2px' }) }));
      assert.deepStrictEqual(fcodes(stray), ['rule-width'], k + ' a stray side');
    }
  }
});

t('new rule slots: contrast on the PAINTED band with the existing floors (strong 3.0, quiet 1.1) - accent, surface and the panel', () => {
  const accent = { r: 154, g: 59, b: 31 }; const surface = { r: 255, g: 255, b: 255 }; const panel = { r: 28, g: 26, b: 23 };
  // The shipped values pass on the band they are printed on.
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(nq['rule-divider-accent']), accent, 'rule').problems, [], 'accent-text at 40% over the accent band is above 1.1');
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(nq['rule-strong-divider-accent']), accent, 'rule-strong').problems, []);
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(nq['rule-divider-surface']), surface, 'rule').problems, []);
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(nq['rule-strong-divider-surface']), surface, 'rule-strong').problems, []);
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(nq['rule-group-panel-bg']), panel, 'rule').problems, [], 'bg at 40% over the text-filled panel');
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(nq['rule-strong-group-panel-bg']), panel, 'rule-strong').problems, []);
  // A faint quiet line on the panel (the line almost the panel colour) and one the colour of the accent band fail; so do weak strong lines.
  const faintPanel = Object.assign({}, nq['rule-group-panel-bg'], { top: Object.assign({}, nq['rule-group-panel-bg'].top, { color: 'rgba(28,26,23,0.05)' }) });
  const fp = K.ruleContrastProblems(mrule(faintPanel), panel, 'rule');
  assert.deepStrictEqual(fp.problems.map((x) => x[0]), ['rule-contrast']);
  assert.match(fp.problems[0][1], /^rule line #[0-9a-f]{6} on the band #1c1a17 is 1(\.0[0-9]*)?:1, needs 1\.1$/);
  const sameAsAccent = Object.assign({}, nq['rule-strong-divider-accent'], { top: Object.assign({}, nq['rule-strong-divider-accent'].top, { color: '#9a3b1f' }) });
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(sameAsAccent), accent, 'rule-strong').problems.map((x) => x[0]), ['rule-contrast'], 'a strong line the colour of the accent band');
  const weakPanel = Object.assign({}, nq['rule-strong-group-panel-bg'], { top: Object.assign({}, nq['rule-strong-group-panel-bg'].top, { color: '#4a4540' }) });
  assert.deepStrictEqual(K.ruleContrastProblems(mrule(weakPanel), panel, 'rule-strong').problems.map((x) => x[0]), ['rule-contrast'], 'a strong line under 3.0:1 on the panel');
  // The floors are the PHP proof's: strong = shape 3.0, quiet = RuleChipAudit::QUIET_RULE_FLOOR (read from the source, never copied).
  const audit = fs.readFileSync(path.join(ROOT, 'tests', 'Support', 'RuleChipAudit.php'), 'utf8');
  assert.strictEqual(parseFloat(/QUIET_RULE_FLOOR\s*=\s*([0-9.]+);/.exec(audit)[1]), K.RULE_QUIET_MIN);
  assert.strictEqual(K.RULE_STRONG_MIN, 3.0);
  assert.strictEqual(K.CONTRAST_SHAPE, 3.0);
  // A missing band sample and an unreadable line fail on every new slot (nothing is skipped).
  for (const k of NEW_RULE_KEYS) {
    const role = newRuleSlot(k).role;
    assert.deepStrictEqual(K.ruleContrastProblems(mrule(nq[k]), null, role).problems.map((x) => x[0]), ['rule-contrast'], k);
    assert.deepStrictEqual(K.ruleContrastProblems(mrule(nq[k], { line: undefined }), panel, role).problems.map((x) => x[0]), ['rule-contrast'], k);
  }
});

t('new rule slots: a missing slot, a doubled one, a null expectation and an empty selection each fail (never skip)', () => {
  const all = K.RULE_SLOTS.map((s) => ({ key: s.key, found: true, count: 1 }));
  assert.deepStrictEqual(K.ruleCountProblems(all, K.RULE_SLOTS), []);
  for (const key of NEW_RULE_KEYS) {
    const gone = K.ruleCountProblems(all.map((m) => (m.key === key ? { key, found: false, count: 0 } : m)), K.RULE_SLOTS);
    assert.deepStrictEqual(gone.map((x) => x[0]), ['rule'], key);
    assert.match(gone[0][1], new RegExp(`^15 of 16 rules rendered as the sheet says: ${key} is not in the page`));
    const twice = K.ruleCountProblems(all.map((m) => (m.key === key ? { key, found: true, count: 2 } : m)), K.RULE_SLOTS);
    assert.match(twice[0][1], new RegExp(`${key} is on the page 2 times, expected 1`));
    // A null expectation and one with a hole fail with rule-expectation, never an empty problem list.
    assert.deepStrictEqual(fcodes(K.compareRule(null, mrule(nq[key]))), ['rule-expectation'], key + ' null expectation');
    assert.deepStrictEqual(fcodes(K.compareRule(K.expectRuleFromAttrs({}, newRuleSlot(key).carrier), mrule(nq[key]))), ['rule-expectation'], key + ' empty attributes');
    // An unmeasured rule fails too.
    assert.ok(K.compareRule(nq[key], mrule(nq[key], { line: undefined })).length > 0, key + ' unmeasured');
  }
  // An empty selection: no slot at all throws; a measurement with nothing found names every slot; a run that checked nothing fails.
  assert.throws(() => K.ruleCountProblems([], []), /no rule slots/);
  const newSlots = K.RULE_SLOTS.filter((s) => NEW_RULE_KEYS.includes(s.key));
  const none = K.ruleCountProblems([], newSlots);
  assert.match(none[0][1], /^0 of 6 rules rendered as the sheet says: rule-divider-surface is not in the page; rule-strong-divider-surface is not in the page/);
  assert.deepStrictEqual(K.emptyRuleRunProblems({ rulesChecked: 0 }), ['no rule was checked: the rule selection was empty']);
  // A rule that is not in the resolved markup is attrs null (the gate in verify-kits stops on it).
  const without = K.ruleAttrs(K.sheetMarkup().replace(/Quiet group rule in panel on bg/g, 'Renamed'));
  assert.strictEqual(without.find((x) => x.slot.key === 'rule-group-panel-bg').attrs, null);
  assert.ok(without.find((x) => x.slot.key === 'rule-strong-group-panel-bg').attrs);
});

t('new chip slots: expectations from the RESOLVED markup (a tag frame on the surface and photo bands, an icon chip on the accent band takes fill surface and glyph text)', () => {
  assert.deepStrictEqual(ncq['chip-tag-surface'], { kind: 'tag', radius: [4, 4, 4, 4], frame: { width: 1, style: 'solid', color: '#e3dccf' } }, 'the hairline frame of a hairline kit on the surface band');
  assert.deepStrictEqual(ncq['chip-tag-dark'], { kind: 'tag', radius: [4, 4, 4, 4], frame: { width: 1, style: 'solid', color: 'rgba(251,246,236,0.4)' } }, 'on the photo band the quiet frame is the 40% bone');
  assert.deepStrictEqual(ncq['chip-icon-accent'], { kind: 'icon', radius: [4, 4, 4, 4], fill: '#ffffff', glyph: '#1c1a17' }, 'on the accent band the chip is a surface-coloured box with a text glyph');
  assert.strictEqual(ncr['chip-tag-dark'].frame.color, '#fbf6ec', 'a strong frame on the photo band is the inverse ink');
  assert.strictEqual(ncr['chip-tag-surface'].frame.color, '#1c1a17');
  assert.deepStrictEqual(ncr['chip-icon-accent'].radius, [0, 0, 0, 0], 'a 0px kit is four zeros on the accent chip too');
  assert.strictEqual(cexp(RROWS.dotted, 'chip-tag-dark').frame.style, 'dotted');
  assert.ok(cexp(RROWS.dbl, 'chip-tag-surface').frame.width >= 3, 'a double frame is at least 3px on the surface band');
  for (const k of NEW_CHIP_KEYS) { assert.deepStrictEqual(K.missingChipExpectations(ncq[k]), [], k); assert.deepStrictEqual(K.missingChipExpectations(ncr[k]), [], k); }
});

t('new chip slots: a chip that agrees passes; a wrong corner, frame side, fill (an icon fill equal to the accent band) or glyph fails with its own code; a null expectation and a missing slot fail', () => {
  for (const k of NEW_CHIP_KEYS) {
    for (const e of [ncq[k], ncr[k]]) assert.deepStrictEqual(K.compareChip(e, mchip(e)), [], k);
    const e = ncq[k];
    const corner = K.compareChip(e, mchip(e, { el: Object.assign({}, mchip(e).el, { borderTopLeftRadius: '20px' }) }));
    assert.deepStrictEqual(fcodes(corner), ['chip-radius'], k);
    assert.deepStrictEqual(fcodes(K.compareChip(null, mchip(e))), ['chip-expectation'], k + ' null expectation');
    assert.deepStrictEqual(fcodes(K.compareChip(K.expectChipFromAttrs({}, newChipSlot(k).carrier), mchip(e))), ['chip-expectation'], k + ' empty attributes');
    assert.deepStrictEqual(fcodes(K.compareChip(e, mchip(e, { el: undefined }))), ['chip'], k + ' unmeasured');
  }
  const dark = ncq['chip-tag-dark'];
  assert.deepStrictEqual(fcodes(K.compareChip(dark, mchip(dark, { el: Object.assign({}, mchip(dark).el, { borderLeftColor: 'rgb(251, 246, 236)' }) }))), ['chip-frame'], 'the 40% alpha is part of the frame colour');
  assert.deepStrictEqual(fcodes(K.compareChip(dark, mchip(dark, { el: Object.assign({}, mchip(dark).el, { borderTopStyle: 'dashed' }) }))), ['chip-frame']);
  const icon = ncq['chip-icon-accent'];
  assert.deepStrictEqual(fcodes(K.compareChip(icon, mchip(icon, { el: Object.assign({}, mchip(icon).el, { backgroundColor: 'rgb(154, 59, 31)' }) }))), ['chip-fill'], 'an icon fill the accent colour of the band instead of surface');
  assert.deepStrictEqual(fcodes(K.compareChip(icon, mchip(icon, { glyph: { color: 'rgb(255, 255, 255)' } }))), ['chip-fill'], 'a glyph in the fill colour');
});

t('new chip slots: contrast on the PAINTED band - a quiet (alpha or hairline) frame needs 1.1, a strong one 3.0; the accent chip fill must not be the colour of the band and the glyph needs 3.0 on it', () => {
  const darkBand = { r: 30, g: 40, b: 50 }; const surface = { r: 255, g: 255, b: 255 }; const accent = { r: 154, g: 59, b: 31 };
  // The 40% frame on the photo band is the QUIET kind although it is not the language hairline: the PHP proof (isQuiet) treats every alpha form so.
  assert.deepStrictEqual(K.chipContrastProblems(ncq['chip-tag-dark'], mchip(ncq['chip-tag-dark']), { band: darkBand }, '#e3dccf').problems, [], 'alpha frame, quiet floor');
  assert.deepStrictEqual(K.chipContrastProblems(ncq['chip-tag-dark'], mchip(ncq['chip-tag-dark']), { band: darkBand }, null).problems, [], 'alpha frame, no hairline');
  const midBand = { r: 90, g: 90, b: 100 }; // the 40% bone over it is about 2.5:1: above the quiet floor, under the strong one
  assert.deepStrictEqual(K.chipContrastProblems(ncq['chip-tag-dark'], mchip(ncq['chip-tag-dark']), { band: midBand }, null).problems, [], 'an alpha frame at about 2.5:1 passes the quiet floor even without a hairline');
  assert.deepStrictEqual([...new Set(K.chipContrastProblems(ncr['chip-tag-dark'], mchip(ncr['chip-tag-dark'], { el: Object.assign({}, mchip(ncr['chip-tag-dark']).el, ...['Top', 'Right', 'Bottom', 'Left'].map((c) => ({ [`border${c}Color`]: 'rgb(150, 150, 160)' }))) }), { band: midBand }, null).problems.map((x) => x[0]))], ['chip-contrast'], 'the same contrast with an OPAQUE colour is a strong frame and needs 3.0');
  assert.ok(K.chipContrastProblems(ncq['chip-tag-dark'], mchip(ncq['chip-tag-dark']), { band: { r: 245, g: 240, b: 230 } }, null).problems.length > 0, 'a 40% bone frame on a pale band vanishes');
  // An OPAQUE frame that is not the hairline still needs 3.0 on the dark band.
  const faintOpaque = mchip(ncr['chip-tag-dark'], { el: Object.assign({}, mchip(ncr['chip-tag-dark']).el, ...['Top', 'Right', 'Bottom', 'Left'].map((c) => ({ [`border${c}Color`]: 'rgb(60, 70, 80)' }))) });
  assert.deepStrictEqual([...new Set(K.chipContrastProblems(ncr['chip-tag-dark'], faintOpaque, { band: darkBand }, null).problems.map((x) => x[0]))], ['chip-contrast']);
  assert.deepStrictEqual(K.chipContrastProblems(ncr['chip-tag-dark'], mchip(ncr['chip-tag-dark']), { band: darkBand }, null).problems, [], 'the strong frame is the inverse ink');
  assert.deepStrictEqual(K.chipContrastProblems(ncq['chip-tag-surface'], mchip(ncq['chip-tag-surface']), { band: surface }, '#e3dccf').problems, [], 'the hairline frame on the surface band');
  assert.strictEqual(K.chipContrastProblems(ncq['chip-tag-surface'], mchip(ncq['chip-tag-surface']), { band: surface }, null).problems.length, 4, 'without the hairline the same frame would need 3.0');
  // The icon chip on the accent band: surface fill, text glyph.
  const ok = K.chipContrastProblems(ncq['chip-icon-accent'], mchip(ncq['chip-icon-accent']), { fill: surface, band: accent });
  assert.deepStrictEqual(ok.problems, []);
  assert.ok(ok.info.glyph >= 3 && ok.info.fillOnBand > 1.02, JSON.stringify(ok.info));
  const sameBand = K.chipContrastProblems(ncq['chip-icon-accent'], mchip(ncq['chip-icon-accent']), { fill: accent, band: accent });
  assert.ok(sameBand.problems.some(([c, d]) => c === 'chip-contrast' && /is the colour of the band/.test(d)), 'an accent-band chip whose fill equals the band');
  const sameGlyph = K.chipContrastProblems(ncq['chip-icon-accent'], mchip(ncq['chip-icon-accent'], { glyph: { color: 'rgb(255, 255, 255)' } }), { fill: surface, band: accent });
  assert.match(sameGlyph.problems[0][1], /^glyph #ffffff on the chip fill #ffffff is 1:1, needs 3$/);
  for (const k of NEW_CHIP_KEYS) {
    assert.deepStrictEqual(K.chipContrastProblems(ncq[k], mchip(ncq[k]), { band: null }, null).problems.map((x) => x[0]), ['chip-contrast'], k + ' no band sample');
  }
  assert.deepStrictEqual(K.chipContrastProblems(ncq['chip-icon-accent'], mchip(ncq['chip-icon-accent']), { fill: null, band: accent }).problems.map((x) => x[0]), ['chip-contrast'], 'no painted fill sample');
});

t('new chip slots: count (a missing slot, a doubled one, an icon without its glyph), an empty selection and the empty run all fail', () => {
  const all = K.CHIP_SLOTS.map((s) => ({ key: s.key, found: true, count: 1, glyph: s.carrier === 'icon' ? { color: '#fff' } : null }));
  assert.deepStrictEqual(K.chipCountProblems(all, K.CHIP_SLOTS), []);
  for (const key of NEW_CHIP_KEYS) {
    const gone = K.chipCountProblems(all.map((m) => (m.key === key ? { key, found: false, count: 0 } : m)), K.CHIP_SLOTS);
    assert.match(gone[0][1], new RegExp(`^4 of 5 chips rendered as the sheet says: ${key} is not in the page`), key);
    assert.match(K.chipCountProblems(all.map((m) => (m.key === key ? { key, found: true, count: 2, glyph: m.glyph } : m)), K.CHIP_SLOTS)[0][1], new RegExp(`${key} is on the page 2 times, expected 1`));
  }
  assert.match(K.chipCountProblems(all.map((m) => (m.key === 'chip-icon-accent' ? { key: m.key, found: true, count: 1, glyph: null } : m)), K.CHIP_SLOTS)[0][1], /chip-icon-accent has no glyph/);
  assert.throws(() => K.chipCountProblems([], []), /no chip slots/);
  assert.match(K.chipCountProblems([], K.CHIP_SLOTS)[0][1], /^0 of 5 chips rendered/);
  assert.deepStrictEqual(K.emptyChipRunProblems({ chipsChecked: 0 }), ['no chip was checked: the chip selection was empty']);
  const without = K.chipAttrs(K.sheetMarkup().replace(/Tag chip on dark/g, 'Renamed'));
  assert.strictEqual(without.find((x) => x.slot.key === 'chip-tag-dark').attrs, null);
  assert.ok(without.find((x) => x.slot.key === 'chip-tag-surface').attrs);
});

// ---- the sheet through the REAL PHP pipeline, offline ----
t('every kit x every language: the sheet resolves with zero leftovers, passes LayoutGate, and every card, every item block and every form is in the resolved markup with a complete expectation and readable text', () => {
  const php = spawnSync('php', [path.join(__dirname, 'lib', 'kit-sheet-offline.php')], { input: K.sheetMarkup(), encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
  assert.strictEqual(php.status, 0, 'php failed: ' + (php.error ? php.error.message : php.stderr));
  const rows = JSON.parse(php.stdout);
  const langs = JSON.parse(fs.readFileSync(path.join(ROOT, 'wp-plugin', 'data', 'design-languages.json'), 'utf8'));
  const colours = Object.fromEntries(langs.map((l) => [l.id, l.colors]));
  assert.strictEqual(rows.length, kitIds.length * languages.length, 'one row per kit and language');
  assert.ok(rows.length >= 400);
  let cards = 0;
  let items_checked = 0;
  let forms_checked = 0;
  let media_checked = 0;
  let rules_checked = 0;
  let chips_checked = 0;
  const featuredFillOnly = [];
  const kitMedia = Object.fromEntries(JSON.parse(fs.readFileSync(path.join(ROOT, 'wp-plugin', 'data', 'style-kits.json'), 'utf8')).map((k) => [k.id, k.radius.media]));
  const kitControl = Object.fromEntries(JSON.parse(fs.readFileSync(path.join(ROOT, 'wp-plugin', 'data', 'style-kits.json'), 'utf8')).map((k) => [k.id, k.radius.control]));
  for (const row of rows) {
    const cell = row.kit + '/' + row.language;
    assert.deepStrictEqual(row.leftovers, [], cell + ' leftovers');
    assert.strictEqual(row.tokenLeft, false, cell + ' unresolved token');
    assert.ok(row.valid, cell + ' LayoutGate: ' + row.violations.join(','));
    assert.ok(!/"aied"/.test(row.markup), cell + ' a marker is left');
    assert.strictEqual(K.pageButtonAttrs(row.markup).length, 8, cell + ' buttons');
    K.pageButtonAttrs(row.markup).forEach((a, i) => assert.deepStrictEqual(K.missingExpectations(K.expectFromAttrs(a)), [], cell + ' button ' + i));
    const found = K.cardAttrs(row.markup);
    assert.strictEqual(found.length, K.CARD_SLOTS.length);
    const pal = colours[row.language];
    const ratio = (x, y) => require('./lib/recipe-checks.cjs').ratioOf(K.colour(x), y);
    const solid = (c) => ({ r: c.r, g: c.g, b: c.b, a: 1 });
    const fillOf = {};
    for (const { slot, attrs, buttons, inks } of found) {
      const where = `${cell} ${slot.key}`;
      assert.ok(attrs, where + ' not in the resolved markup');
      cards++;
      const exp = K.expectCardFromAttrs(attrs);
      assert.deepStrictEqual(K.missingCardExpectations(exp, slot.role, slot.ground), [], where);
      assert.strictEqual(slot.role === 'panel' ? exp.rest.padding.join() : 'x', slot.role === 'panel' ? [slot.padding, slot.padding, slot.padding, slot.padding].join() : 'x', where + ' panel padding is the sheet\'s literal');
      const fill = K.colour(exp.rest.fill);
      assert.strictEqual(fill.a, 1, where + ' the fill is opaque');
      fillOf[slot.key] = fill;
      // The inks the children carry resolve to the slot's role colours, and are readable on the resolved fill.
      assert.ok(K.sameColour(inks.heading, pal[slot.inkHeading.replace('-', '_')]), `${where} heading ink ${inks.heading} is not ${slot.inkHeading}`);
      assert.ok(K.sameColour(inks.paragraph, pal[slot.inkPara.replace('-', '_')]), `${where} paragraph ink ${inks.paragraph} is not ${slot.inkPara}`);
      assert.ok(ratio(inks.heading, fill) >= 4.5, where + ' heading ' + ratio(inks.heading, fill));
      assert.ok(ratio(inks.paragraph, fill) >= 4.5, where + ' paragraph ' + ratio(inks.paragraph, fill));
      if (slot.ground) {
        // A ground fills with text (inverted) or accent (accent), the frame is the fill, and nothing is demanded of a hover fill or frame.
        assert.ok(K.sameColour(exp.rest.fill, pal[slot.ground === 'inverted' ? 'text' : 'accent']), `${where} ground fill ${exp.rest.fill}`);
        for (const side of ['top', 'right', 'bottom', 'left']) assert.ok(K.sameColour(exp.rest[side].color, exp.rest.fill), `${where} ${side} frame is the fill`);
        assert.deepStrictEqual(exp.hoverKeys.filter((k) => k === 'border.hover' || k === 'background.hover'), [], where);
      } else {
        for (const side of ['top', 'right', 'bottom', 'left']) {
          const e = exp.rest[side];
          if (e.width > 0 && !K.sameColour(e.color, pal.border)) assert.ok(ratio(e.color, fill) >= 3, `${where} ${side} frame ${ratio(e.color, fill)}`);
        }
      }
      // The buttons of a button panel: complete expectations, readable on the panel exactly as the live gate judges them (rest and hover).
      assert.strictEqual(buttons.length, slot.buttons || 0, where + ' buttons');
      buttons.forEach((b, j) => {
        const be = K.expectFromAttrs(b);
        assert.deepStrictEqual(K.missingExpectations(be), [], `${where} button ${j}`);
        const elOf = (state) => { const h = state === 'hover'; const col = h ? be.hover.fill : be.rest.fill; return { backgroundColor: col, color: h ? be.hover.label : be.rest.label, borderTopWidth: be.rest.top.width + 'px', borderBottomWidth: be.rest.bottom.width + 'px', borderTopColor: h ? be.hover.border : be.rest.top.color, borderBottomColor: h ? be.hover.border : be.rest.bottom.color }; };
        for (const state of ['rest', 'hover']) assert.deepStrictEqual(K.contrastProblems(elOf(state), solid(fill), state).problems, [], `${where} button ${j} ${state}`);
      });
    }
    // The items: every block of the sheet is in the resolved markup with a complete expectation, the same contrast the live gate applies holds on the
    // resolved fill in BOTH states (title and answer 4.5, icon 3.0, a meaningful frame 3.0), and the fill is what the role table says.
    const foundItems = K.itemAttrs(row.markup);
    assert.strictEqual(foundItems.length, K.ITEM_SLOTS.length);
    for (const { slot, attrs, items } of foundItems) {
      const where = `${cell} ${slot.key}`;
      assert.ok(attrs, where + ' not in the resolved markup');
      assert.deepStrictEqual(items, slot.items.map((i) => ({ title: i.title, open: i.open })), where + ' items');
      items_checked++;
      const exp = K.expectItemFromAttrs(attrs, slot.carrier);
      assert.deepStrictEqual(K.missingItemExpectations(exp, slot.role), [], where);
      assert.deepStrictEqual(exp.hoverKeys, [], where + ' no hover on an item');
      const wantFill = slot.role === 'item-rule' ? (slot.on === 'surface' ? pal.surface : pal.bg) : (slot.on === 'surface' ? pal.bg : pal.surface);
      for (const state of ['open', 'closed']) {
        const st = exp[state];
        const fill = K.colour(st.fill);
        assert.strictEqual(fill.a, 1, `${where} ${state} fill is opaque`);
        assert.ok(K.sameColour(st.fill, wantFill), `${where} ${state} fill ${st.fill} is not ${wantFill}`);
        assert.ok(ratio(st.title, fill) >= 4.5, `${where} ${state} title ${ratio(st.title, fill)}`);
        if (state === 'open') assert.ok(ratio(st.body, fill) >= 4.5, `${where} answer ${ratio(st.body, fill)}`);
        if (st.icon) assert.ok(ratio(st.icon, fill) >= 3, `${where} ${state} icon ${ratio(st.icon, fill)}`);
      }
      assert.strictEqual(slot.carrier === 'accordion' ? exp.open.icon : 'x', slot.carrier === 'accordion' ? null : 'x', where + ' an accordion has no open icon');
      assert.ok(K.sameColour(exp.open.title, pal.text) && K.sameColour(exp.closed.title, pal.text) && K.sameColour(exp.open.body, pal.muted), where + ' inks');
      const fillC = K.colour(exp.open.fill);
      for (const side of ['top', 'right', 'bottom', 'left']) {
        const e = exp.rest[side];
        if (e.width > 0 && !K.sameColour(e.color, pal.border)) assert.ok(ratio(e.color, fillC) >= 3, `${where} ${side} frame ${ratio(e.color, fillC)}`);
      }
      if (slot.role === 'item-rule') {
        assert.deepStrictEqual(exp.rest.radius, [0, 0, 0, 0], where);
        assert.deepStrictEqual([exp.rest.top.width, exp.rest.right.width, exp.rest.left.width], [0, 0, 0], where + ' a rule row is an underline');
        assert.strictEqual(exp.shadow, null, where);
        assert.deepStrictEqual(exp.rest.padding, [28, 0, 28, 0], where);
      }
    }
    // The forms: both are in the resolved markup with a complete expectation, and what the role table says holds on the resolved colours, readable
    // as the live gate judges them: a field never the colour of its container, ink and placeholder 4.5 on the field, the frame 3.0 on the field and on
    // the band, the submit label and edges on the band (rest and hover), the captcha ink 4.5 on the band, and nothing on the form's own module.decoration.
    const foundForms = K.formAttrs(row.markup);
    assert.strictEqual(foundForms.length, K.FORM_SLOTS.length);
    for (const { slot, attrs, fields } of foundForms) {
      const where = `${cell} ${slot.key}`;
      assert.ok(attrs, where + ' not in the resolved markup');
      assert.strictEqual(fields, slot.fields, where + ' fields');
      forms_checked++;
      const exp = K.expectFormFromAttrs(attrs);
      assert.deepStrictEqual(K.missingFormExpectations(exp), [], where);
      assert.deepStrictEqual(exp.moduleKeys, [], where + ' the kit writes no module.decoration on a form');
      const fillC = K.colour(exp.field.fill);
      const bandC = K.colour(slot.on === 'surface' ? pal.surface : pal.bg);
      assert.strictEqual(fillC.a, 1, where + ' the field fill is opaque');
      assert.ok(K.sameColour(exp.field.fill, slot.on === 'surface' ? pal.bg : pal.surface), `${where} field fill ${exp.field.fill}`);
      assert.ok(!K.sameColour(exp.field.fill, slot.on === 'surface' ? pal.surface : pal.bg), where + ' a field never matches its container');
      assert.ok(K.sameColour(exp.field.ink, pal.text) && K.sameColour(exp.field.placeholder, pal.muted) && K.sameColour(exp.captcha.ink, pal.text), where + ' inks');
      assert.ok(ratio(exp.field.ink, fillC) >= 4.5, `${where} field ink ${ratio(exp.field.ink, fillC)}`);
      assert.ok(ratio(exp.field.placeholder, fillC) >= 4.5, `${where} placeholder ${ratio(exp.field.placeholder, fillC)}`);
      assert.ok(ratio(exp.captcha.ink, bandC) >= 4.5, `${where} captcha ${ratio(exp.captcha.ink, bandC)}`);
      let drawn = 0;
      for (const side of ['top', 'right', 'bottom', 'left']) {
        const e = exp.field[side];
        if (!(e.width > 0)) continue;
        drawn++;
        assert.ok(ratio(e.color, fillC) >= 3, `${where} ${side} frame on the field ${ratio(e.color, fillC)}`);
        assert.ok(ratio(e.color, bandC) >= 3, `${where} ${side} frame on the band ${ratio(e.color, bandC)}`);
      }
      assert.ok(drawn >= 1, where + ' a field draws at least one edge');
      const be = exp.button;
      for (const state of ['rest', 'hover']) {
        const h = state === 'hover';
        const edge = h ? (be.hover.border || be.rest.bottom.color) : null;
        const el = { backgroundColor: h ? be.hover.fill : be.rest.fill, color: h ? be.hover.label : be.rest.label, borderTopWidth: be.rest.top.width + 'px', borderBottomWidth: be.rest.bottom.width + 'px', borderTopColor: edge || be.rest.top.color, borderBottomColor: edge || be.rest.bottom.color };
        assert.deepStrictEqual(K.contrastProblems(el, solid(bandC), state).problems, [], `${where} submit ${state}`);
      }
    }
    // The image: it is in the resolved markup, its four corners are the kit's media radius (a 0px kit gives four zeros, which are checked), and it is
    // a picture of the built-in pack that now has a site-local address.
    const foundMedia = K.mediaAttrs(row.markup);
    assert.strictEqual(foundMedia.length, K.MEDIA_SLOTS.length);
    for (const { slot, attrs } of foundMedia) {
      const where = `${cell} ${slot.key}`;
      assert.ok(attrs, where + ' not in the resolved markup');
      media_checked++;
      const exp = K.expectMediaFromAttrs(attrs);
      assert.deepStrictEqual(K.missingMediaExpectations(exp), [], where);
      assert.deepStrictEqual(exp.radius, [0, 1, 2, 3].map(() => parseFloat(kitMedia[row.kit])), `${where} radius ${exp.radius} is not the kit's media ${kitMedia[row.kit]}`);
      assert.match(attrs.image.innerContent.desktop.value.src, /^https:\/\/example\.test\/assets\/images\/[a-z0-9-]+\.svg$/, where + ' the picture is a site-local pack image');
    }
    // The rules and the chips: every one is in the resolved markup with a complete expectation; a rule's colour is the language's role for its band
    // (quiet = border on light, 40% bone on the photo band, strong = text / inverse / bg on a panel), only the top edge is drawn, a double is at
    // least 3px; a chip's four corners are the kit's control radius (a 0px kit gives four zeros) and its icon is the accent fill with the accent-text glyph.
    const foundRules = K.ruleAttrs(row.markup);
    assert.strictEqual(foundRules.length, K.RULE_SLOTS.length);
    for (const { slot, attrs } of foundRules) {
      const where = `${cell} ${slot.key}`;
      assert.ok(attrs, where + ' not in the resolved markup');
      rules_checked++;
      const exp = K.expectRuleFromAttrs(attrs, slot.carrier);
      assert.deepStrictEqual(K.missingRuleExpectations(exp), [], where);
      if (slot.carrier !== 'divider') assert.deepStrictEqual(exp.others, { right: 0, bottom: 0, left: 0 }, where + ' only the top edge');
      if (exp.top.style === 'double') assert.ok(exp.top.width >= 3, where + ' a double is at least 3px');
      const strongInk = { light: pal.text, surface: pal.text, accent: pal.accent_text, dark: pal.inverse, panel: pal.bg }[slot.on];
      if (slot.role === 'rule-strong') assert.ok(K.sameColour(exp.top.color, strongInk), `${where} strong colour ${exp.top.color} is not ${strongInk}`);
      else if (slot.on === 'light' || slot.on === 'surface') assert.ok(K.sameColour(exp.top.color, pal.border), `${where} quiet colour ${exp.top.color} is not ${pal.border}`);
      else {
        const q = K.colour(exp.top.color); const base = K.colour(strongInk);
        assert.ok(q.a < 1, `${where} the quiet rule on the ${slot.on} band is the alpha form`);
        assert.ok(['r', 'g', 'b'].every((c) => base[c] === q[c]) && Math.abs(q.a - 0.4) < 0.001, `${where} the quiet colour is the strong ink at 40% (${exp.top.color} vs ${strongInk})`);
      }
    }
    const foundChips = K.chipAttrs(row.markup);
    assert.strictEqual(foundChips.length, K.CHIP_SLOTS.length);
    for (const { slot, attrs } of foundChips) {
      const where = `${cell} ${slot.key}`;
      assert.ok(attrs, where + ' not in the resolved markup');
      chips_checked++;
      const exp = K.expectChipFromAttrs(attrs, slot.carrier);
      assert.deepStrictEqual(K.missingChipExpectations(exp), [], where);
      assert.deepStrictEqual(exp.radius, [0, 1, 2, 3].map(() => parseFloat(kitControl[row.kit])), `${where} radius ${exp.radius} is not the kit's control ${kitControl[row.kit]}`);
      if (slot.carrier === 'icon' && slot.on === 'accent') assert.ok(K.sameColour(exp.fill, pal.surface) && K.sameColour(exp.glyph, pal.text), `${where} on the accent band the chip is surface with a text glyph: fill ${exp.fill} / glyph ${exp.glyph}`);
      else if (slot.carrier === 'icon') assert.ok(K.sameColour(exp.fill, pal.accent) && K.sameColour(exp.glyph, pal.accent_text), `${where} fill ${exp.fill} / glyph ${exp.glyph}`);
      else assert.ok(exp.frame.width >= 1 && (exp.frame.style !== 'double' || exp.frame.width >= 3), `${where} frame ${JSON.stringify(exp.frame)}`);
    }
    // The featured middle card against the plain ones on the bg band, by FILL alone (frames, which only make a plain card stronger, are the live check).
    const band = K.colour(pal.bg);
    const prom = (key) => K.boxProminence(flat({ r: fillOf[key].r, g: fillOf[key].g, b: fillOf[key].b }), solid(band));
    featuredFillOnly.push([cell, prom('column-2'), Math.max(prom('column-1'), prom('column-3'))]);
  }
  assert.strictEqual(cards, rows.length * K.CARD_SLOTS.length);
  assert.strictEqual(items_checked, rows.length * K.ITEM_SLOTS.length, 'every item block of every cell was checked');
  assert.strictEqual(forms_checked, rows.length * K.FORM_SLOTS.length, 'every form of every cell was checked');
  assert.strictEqual(media_checked, rows.length * K.MEDIA_SLOTS.length, 'every image of every cell was checked');
  assert.strictEqual(rules_checked, rows.length * K.RULE_SLOTS.length, 'every rule of every cell was checked');
  assert.strictEqual(chips_checked, rows.length * K.CHIP_SLOTS.length, 'every chip of every cell was checked');
  // On fills alone the accent middle card is at least FEATURED_RATIO x the larger plain neighbour in every kit x language (kits do not change the fill).
  const weakest = featuredFillOnly.filter(([, f, n]) => K.featuredProblems({ featured: f, neighbours: [n, n] }).length);
  assert.deepStrictEqual(weakest.map(([c, f, n]) => `${c} featured ${f.toFixed(3)} vs ${n.toFixed(3)}`), [], 'by fill alone the featured card is not featured in these cells');
});

// ---- parsing helpers ----
t('shadows, transforms and colours parse', () => {
  assert.deepStrictEqual(K.parseShadows('rgba(28, 22, 16, 0.18) 0px 8px 24px 0px'), [{ color: 'rgba(28, 22, 16, 0.18)', x: 0, y: 8, blur: 24, spread: 0, inset: false }]);
  assert.deepStrictEqual(K.parseShadows('none'), []);
  assert.strictEqual(K.parseShadows('rgb(0, 0, 0) 1px 1px 0px 0px inset, rgb(1, 1, 1) 2px 2px 3px 0px').length, 2);
  assert.strictEqual(K.translateY('none'), 0);
  assert.strictEqual(K.translateY('matrix(1, 0, 0, 1, 0, -4)'), -4);
  assert.strictEqual(K.translateY('matrix3d(1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, -4, 0, 1)'), -4);
  assert.ok(K.sameColour('#1c1a17', 'rgb(28, 26, 23)'));
  assert.ok(K.sameColour('rgba(255,255,255,0)', 'rgba(0, 0, 0, 0)'), 'two transparent colours are equal');
  assert.ok(!K.sameColour('rgba(255,255,255,0)', 'rgb(255, 255, 255)'));
  assert.ok(K.sameColour('rgba(28,22,16,0.25)', 'rgba(28, 22, 16, 0.25)'));
});

// ---- contrast ----
t('contrast: the label on the painted fill, and an outline on the surface', () => {
  const white = { r: 255, g: 255, b: 255 };
  const outlineSame = K.contrastProblems(style({ borderTopColor: 'rgb(255, 255, 255)', borderBottomColor: 'rgb(255, 255, 255)' }), white, 'rest').problems;
  assert.deepStrictEqual(outlineSame.map((x) => x[0]), ['contrast', 'contrast'], 'an outline the colour of the surface is invisible');
  assert.deepStrictEqual(K.contrastProblems(style({}), white, 'rest').problems, []);
  const pale = K.contrastProblems(style({ color: 'rgb(200, 200, 200)' }), white, 'rest').problems;
  assert.strictEqual(pale.length, 1);
  assert.match(pale[0][1], /label #c8c8c8 on #ffffff is 1\.67:1, needs 4\.5/);
  // A filled button: the border is not judged as the shape.
  const filled = K.contrastProblems(style({ backgroundColor: 'rgb(28, 26, 23)', color: 'rgb(255, 255, 255)', borderTopColor: 'rgb(255, 255, 255)', borderBottomColor: 'rgb(255, 255, 255)' }), white, 'rest');
  assert.deepStrictEqual(filled.problems, []);
  assert.strictEqual(filled.info.topOnFill > 10, true, 'its contrast against its own fill is reported');
});

// ---- overflow ----
t('overflow: outside the column or the window', () => {
  const page = { innerWidth: 400, scrollWidth: 400 };
  const b = (l, r) => ({ rect: { left: l, right: r }, parentRect: { left: 20, right: 380 } });
  assert.deepStrictEqual(K.overflowProblems(b(20, 300), 'rest', page), []);
  assert.strictEqual(K.overflowProblems(b(20, 390), 'hover', page)[0][0], 'overflow');
  assert.strictEqual(K.overflowProblems(b(20, 300), 'rest', { innerWidth: 400, scrollWidth: 420 }).length, 1);
});

// ---- PNG ----
function encodePng(width, height, rgba, filterOf) {
  const bpp = 4; const stride = width * bpp; const rows = [];
  for (let y = 0; y < height; y++) {
    const f = filterOf(y); const row = Buffer.alloc(stride + 1); row[0] = f;
    for (let i = 0; i < stride; i++) {
      const x = rgba[y * stride + i]; const a = i >= bpp ? rgba[y * stride + i - bpp] : 0; const b = y > 0 ? rgba[(y - 1) * stride + i] : 0; const c = i >= bpp && y > 0 ? rgba[(y - 1) * stride + i - bpp] : 0;
      let pred = 0;
      if (f === 1) pred = a; else if (f === 2) pred = b; else if (f === 3) pred = (a + b) >> 1;
      else if (f === 4) { const p = a + b - c; const pa = Math.abs(p - a); const pb = Math.abs(p - b); const pc = Math.abs(p - c); pred = pa <= pb && pa <= pc ? a : pb <= pc ? b : c; }
      row[i + 1] = (x - pred) & 255;
    }
    rows.push(row);
  }
  const chunk = (type, body) => { const len = Buffer.alloc(4); len.writeUInt32BE(body.length); return Buffer.concat([len, Buffer.from(type, 'ascii'), body, Buffer.alloc(4)]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(width, 0); ihdr.writeUInt32BE(height, 4); ihdr[8] = 8; ihdr[9] = 6;
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(Buffer.concat(rows))), chunk('IEND', Buffer.alloc(0))]);
}
t('the PNG decoder reads every filter type', () => {
  const w = 7; const h = 5; const px = Buffer.alloc(w * h * 4);
  for (let i = 0; i < px.length; i++) px[i] = (i * 37 + (i >> 3) * 11) & 255;
  const img = K.decodePng(encodePng(w, h, px, (y) => y % 5));
  assert.strictEqual(img.width, w);
  assert.strictEqual(img.height, h);
  assert.ok(Buffer.compare(img.data, px) === 0, 'pixels round-trip through None, Sub, Up, Average and Paeth');
  const flat = Buffer.alloc(2 * 2 * 4); for (let i = 0; i < 4; i++) flat.set([10, 20, 30, 255], i * 4);
  const st = K.pixelStats(K.decodePng(encodePng(2, 2, flat, () => 0)));
  assert.deepStrictEqual(st.mean, { r: 10, g: 20, b: 30 });
});

// ---- carriers (carriers adapter): the sheet, expectations from the resolved markup, comparison, quote disc, contrast on the painted band, count, overflow, empty run ----
const XROWS = Object.assign({}, RROWS, { neo: offlineRow('neo-brutal', 'editorial'), soft: offlineRow('warm-pill-soft', 'editorial'), lift: offlineRow('soft-lift', 'editorial') });
const xslot = (key) => K.CARRIER_SLOTS.find((s) => s.key === key);
const xhit = (row, key) => K.carrierAttrs(row.markup).find((x) => x.slot.key === key);
const xexp = (row, key) => { const h = xhit(row, key); return K.expectCarrierFromAttrs(h.attrs, h.slot); };
const CAR_KEYS = ['testimonial-card-bg', 'testimonial-panel-surface', 'blurb-chip-surface', 'blurb-chip-accent', 'counter-rule-strong-accent', 'counter-rule-bg', 'accent-rule-bg', 'accent-rule-surface'];
const sh = (s) => (s ? `${cssOf(s.color)} ${s.x || 0}px ${s.y || 0}px ${s.blur || 0}px 0px` : 'none');
/** What measureCarriers returns for a testimonial that agrees with its expectation at rest (the quote disc hangs above the module, overflow visible). */
function mtesti(exp, o = {}) {
  const side = (cap, s) => ({ [`border${cap}Width`]: s.width + 'px', [`border${cap}Style`]: s.style, [`border${cap}Color`]: cssOf(s.color) });
  const el = Object.assign({}, side('Top', exp.rest.top), side('Right', exp.rest.right), side('Bottom', exp.rest.bottom), side('Left', exp.rest.left),
    Object.assign({}, ...CORNER_KEYS.map((k, i) => ({ [k]: exp.rest.radius[i] + 'px' }))),
    { backgroundColor: cssOf(exp.rest.fill), paddingTop: exp.rest.padding[0] + 'px', paddingRight: exp.rest.padding[1] + 'px', paddingBottom: exp.rest.padding[2] + 'px', paddingLeft: exp.rest.padding[3] + 'px', boxShadow: sh(exp.shadow.rest), transform: 'none', overflowX: 'visible', overflowY: 'visible', color: 'rgb(28, 26, 23)' });
  return Object.assign({ key: 'k', found: true, count: 1, carrier: 'testimonial', el, quote: { content: '"“"', position: 'absolute', top: '-20px', marginTop: '0px', height: '40px' }, heading: { color: 'rgb(28, 26, 23)' }, paragraph: { color: 'rgb(28, 26, 23)' }, rect: R(40, 100, 340, 300), parentRect: R(20, 80, 380, 400) }, o);
}
/** The same testimonial under the real pointer: the lift of the markup and its hover shadow. */
const mhover = (exp, m, o = {}) => Object.assign({}, m, { el: Object.assign({}, m.el, { transform: exp.lift !== null ? `matrix(1, 0, 0, 1, 0, ${exp.lift})` : 'none', boxShadow: sh(exp.shadow.hover) }, o) });
/** What measureCarriers returns for a blurb whose icon agrees (corners, fill and glyph colour all on `.et-pb-icon`). */
function mblurb(exp, o = {}) {
  const radius = Object.assign({}, ...CORNER_KEYS.map((k, i) => ({ [k]: exp.radius[i] + 'px' })));
  return Object.assign({ key: 'k', found: true, count: 1, carrier: 'blurb', el: Object.assign({}, radius, { backgroundColor: rgbOf(exp.fill), color: rgbOf(exp.glyph) }), rect: R(40, 100, 104, 164), parentRect: R(20, 80, 380, 400) }, o);
}
/** What measureCarriers returns for a counter or an accent divider that agrees: the rule shape of mrule(). */
const mline = (exp, o = {}) => mrule(exp, o);
const mcar = (row, key, o) => { const slot = xslot(key); const exp = xexp(row, key); return slot.carrier === 'testimonial' ? mtesti(exp, o) : slot.carrier === 'blurb' ? mblurb(exp, o) : mline(exp, o); };
const compareC = (row, key, o, hoverO) => {
  const slot = xslot(key); const exp = xexp(row, key); const m = mcar(row, key, o);
  return K.compareCarrier(exp, slot, m, slot.carrier === 'testimonial' ? mhover(exp, m, hoverO) : null);
};
const BAND = { bg: { r: 250, g: 247, b: 242 }, surface: { r: 255, g: 255, b: 255 }, accent: { r: 154, g: 59, b: 31 } };
const samplesOf = (key, exp) => {
  const slot = xslot(key);
  if (slot.carrier === 'testimonial') return { fill: K.colour(exp.rest.fill) };
  if (slot.carrier === 'blurb') return { fill: K.colour(exp.fill), band: BAND[slot.band] };
  return { band: BAND[slot.band] };
};

t('the sheet carries 8 carrier slots appended after every other slot kind (unique texts, the markers of the plan), each found again by its author, title or heading', () => {
  assert.deepStrictEqual(K.CARRIER_SLOTS.map((s) => s.key), CAR_KEYS);
  assert.deepStrictEqual(K.CARRIER_SLOTS.map((s) => [s.carrier, s.role, s.band, s.on]), [['testimonial', 'card', 'bg', 'light'], ['testimonial', 'panel', 'surface', 'surface'], ['blurb', 'chip', 'surface', 'surface'], ['blurb', 'chip', 'accent', 'accent'],
    ['counter', 'rule-strong', 'accent', 'accent'], ['counter', 'rule', 'bg', 'light'], ['divider', 'rule-accent', 'bg', 'light'], ['divider', 'rule-accent', 'surface', 'surface']]);
  assert.strictEqual(new Set(K.CARRIER_SLOTS.map((s) => s.text)).size, K.CARRIER_SLOTS.length, 'unique texts');
  assert.strictEqual(new Set(K.CARRIER_SLOTS.map((s) => s.key)).size, K.CARRIER_SLOTS.length, 'unique keys');
  const m = K.sheetMarkup();
  const found = K.carrierAttrs(m);
  assert.strictEqual(found.length, 8);
  found.forEach((f) => assert.ok(f.attrs, f.slot.key + ' is in the sheet'));
  // Before the resolver a marker names the role (and the band, omitted for light) and the block carries no look of its own.
  assert.deepStrictEqual(found.map((f) => f.attrs.aied), K.CARRIER_SLOTS.map((s) => (s.on === 'light' ? { kit: s.role } : { kit: s.role, on: s.on })));
  const dump = (key) => JSON.stringify(found.find((f) => f.slot.key === key).attrs);
  for (const key of ['testimonial-card-bg', 'testimonial-panel-surface']) {
    assert.strictEqual(/"border"|"background"|"boxShadow"|"transform"/.test(dump(key)), false, key + ' the kit supplies the fill, frame, radius, shadow and lift');
    assert.ok(dump(key).includes('"overflow"') && dump(key).includes('"visible"'), key + ' the recipe keeps overflow visible (the hanging quote)');
  }
  assert.strictEqual(/"padding"/.test(dump('testimonial-card-bg')), false, 'a card takes the kit padding');
  assert.ok(/"padding"/.test(dump('testimonial-panel-surface')), 'a panel keeps its recipe padding');
  for (const key of ['blurb-chip-surface', 'blurb-chip-accent']) {
    assert.strictEqual(/"border"|"background"|"advanced":\{"color"/.test(dump(key)), false, key + ' the kit supplies the icon radius, fill and glyph colour');
    assert.ok(dump(key).includes('"iconFontSize":"32px"') && dump(key).includes('"padding"'), key + ' the recipe keeps the glyph size and padding');
  }
  assert.strictEqual(m.split('\\u0026#xe00e;').length - 1, 2, 'both blurb glyph codes are stored as \\u0026 like the library\'s');
  assert.strictEqual(m.includes('&#xe00e;'), false);
  for (const key of ['counter-rule-strong-accent', 'counter-rule-bg']) assert.strictEqual(/"border"/.test(dump(key)), false, key + ' the kit supplies the top edge');
  for (const key of ['accent-rule-bg', 'accent-rule-surface']) {
    assert.strictEqual(dump(key).includes('"divider"') || dump(key).includes('"line"'), false, key + ' the kit supplies the line');
    assert.deepStrictEqual(found.find((f) => f.slot.key === key).attrs.modulePreset, [K.ACCENT_DIVIDER_PRESET], key + ' sits over the library\'s site preset');
  }
  assert.deepStrictEqual(K.carrierAttrs('<!-- wp:divi/text {} /-->').map((x) => x.attrs).filter(Boolean), [], 'a carrier that is not in the markup is reported as absent');
  const gone = K.carrierAttrs(m.replace(/Blurb chip on accent/g, 'Renamed'));
  assert.strictEqual(gone.find((x) => x.slot.key === 'blurb-chip-accent').attrs, null);
  assert.ok(gone.find((x) => x.slot.key === 'blurb-chip-surface').attrs);
});

t('carrier expectations are read from the RESOLVED markup: a testimonial like a card, the blurb icon chip, the counter top edge like a group, the accent line (0px kits are expectations)', () => {
  assert.deepStrictEqual(xexp(XROWS.quiet, 'blurb-chip-surface'), { kind: 'icon', radius: [4, 4, 4, 4], fill: '#9a3b1f', glyph: '#ffffff' });
  assert.deepStrictEqual(xexp(XROWS.quiet, 'blurb-chip-accent'), { kind: 'icon', radius: [4, 4, 4, 4], fill: '#ffffff', glyph: '#1c1a17' }, 'on the accent band the chip is surface with a text glyph');
  assert.deepStrictEqual(xexp(XROWS.raw, 'blurb-chip-surface').radius, [0, 0, 0, 0], 'a 0px chip kit is four zeros');
  assert.deepStrictEqual(xexp(XROWS.dotted, 'blurb-chip-surface').radius, [999, 999, 999, 999]);
  assert.deepStrictEqual(xexp(XROWS.quiet, 'counter-rule-strong-accent'), { carrier: 'group', top: { width: 2, style: 'solid', color: '#ffffff' }, others: { right: 0, bottom: 0, left: 0 } });
  assert.deepStrictEqual(xexp(XROWS.quiet, 'counter-rule-bg').top, { width: 1, style: 'solid', color: '#e3dccf' });
  assert.deepStrictEqual(xexp(XROWS.raw, 'counter-rule-bg').top, { width: 2, style: 'solid', color: '#e3dccf' }, 'raw-frame 4px: a quiet rule is 2px');
  assert.deepStrictEqual(xexp(XROWS.raw, 'counter-rule-strong-accent').top.width, 4);
  assert.deepStrictEqual(xexp(XROWS.dashed, 'counter-rule-strong-accent').top.style, 'dashed');
  assert.deepStrictEqual(xexp(XROWS.quiet, 'accent-rule-bg'), { carrier: 'divider', top: { width: 2, style: 'solid', color: '#9a3b1f' }, others: null });
  assert.deepStrictEqual(xexp(XROWS.raw, 'accent-rule-surface').top, { width: 4, style: 'solid', color: '#9a3b1f' });
  assert.strictEqual(xexp(XROWS.dbl, 'accent-rule-bg').top.style, 'double');
  assert.ok(xexp(XROWS.dbl, 'accent-rule-bg').top.width >= 3, 'a double is at least 3px');
  const neo = xexp(XROWS.neo, 'testimonial-card-bg');
  assert.strictEqual(neo.lift, -4); assert.deepStrictEqual(neo.rest.radius, [0, 0, 0, 0]); assert.strictEqual(neo.rest.top.width, 3);
  assert.strictEqual(xexp(XROWS.neo, 'testimonial-panel-surface').lift, null, 'a panel has no lift');
  assert.strictEqual(xexp(XROWS.quiet, 'testimonial-panel-surface').rest.padding.join(), '24,24,24,24', 'a panel keeps the sheet\'s literal padding');
  for (const row of Object.values(XROWS)) for (const key of CAR_KEYS) assert.deepStrictEqual(K.missingCarrierExpectations(xexp(row, key), xslot(key)), [], key);
  // Holes: an absent block, an empty block, an unresolved radius token, three corners, a missing glyph, a missing top width.
  for (const s of K.CARRIER_SLOTS) {
    assert.deepStrictEqual(K.missingCarrierExpectations(null, s), ['block'], s.key + ' absent');
    assert.ok(K.missingCarrierExpectations(K.expectCarrierFromAttrs({}, s), s).length > 0, s.key + ' an empty block has holes');
  }
  assert.deepStrictEqual(K.missingCarrierExpectations(K.expectBlurbChipFromAttrs({}), xslot('blurb-chip-surface')), ['radius', 'fill', 'glyph']);
  assert.deepStrictEqual(K.missingCarrierExpectations(K.expectBlurbChipFromAttrs({ imageIcon: { decoration: { border: { desktop: { value: { radius: '{{aied:kit:radius-control}}' } } } } } }), xslot('blurb-chip-surface')), ['radius', 'fill', 'glyph']);
  assert.deepStrictEqual(K.missingCarrierExpectations(K.expectCarrierFromAttrs({ imageIcon: { decoration: { border: { desktop: { value: { radius: { topLeft: '4px', topRight: '4px', bottomRight: '4px' } } } } } } }, xslot('blurb-chip-surface')), xslot('blurb-chip-surface')).includes('radius'), true, 'three corners');
});

t('carriers that agree with the markup pass (rest, and a testimonial on a real hover): every slot under seven kits', () => {
  for (const [name, row] of Object.entries(XROWS)) for (const key of CAR_KEYS) assert.deepStrictEqual(compareC(row, key), [], `${name} ${key}`);
});

t('testimonial: fill, frame, radius, padding, shadow and hover each fail with their own code; a panel must not move at all', () => {
  const row = XROWS.neo; const exp = xexp(row, 'testimonial-card-bg'); const slot = xslot('testimonial-card-bg');
  const rest = mtesti(exp);
  const judge = (restEl, hoverEl) => { const r = Object.assign({}, rest, { el: Object.assign({}, rest.el, restEl) }); return K.compareCarrier(exp, slot, r, mhover(exp, r, hoverEl)); };
  assert.deepStrictEqual(judge({}, {}), []);
  assert.deepStrictEqual(fcodes(judge({ backgroundColor: 'rgb(0, 0, 0)' })), ['testimonial-fill']);
  assert.deepStrictEqual(fcodes(judge({ borderLeftWidth: '1px' })), ['testimonial-frame']);
  assert.deepStrictEqual(fcodes(judge({ borderTopStyle: 'dashed' })), ['testimonial-frame']);
  assert.deepStrictEqual(fcodes(judge({ borderBottomColor: 'rgb(220, 38, 38)' })), ['testimonial-frame']);
  assert.deepStrictEqual(fcodes(judge({ borderTopLeftRadius: '12px' })), ['testimonial-radius']);
  assert.deepStrictEqual(fcodes(judge({ paddingTop: '30px' })), ['testimonial-padding']);
  assert.deepStrictEqual(fcodes(judge({ boxShadow: 'none' }, { boxShadow: 'none' })), ['testimonial-shadow', 'testimonial-hover'], 'no shadow where a hard one is expected (the hover shadow is the same one)');
  assert.deepStrictEqual(fcodes(judge({}, { transform: 'none' })), ['testimonial-hover'], 'the lift is missing');
  assert.deepStrictEqual(fcodes(judge({}, { transform: 'matrix(1.05, 0, 0, 1.05, 0, -4)' })), ['testimonial-hover'], 'a lift that also scales');
  assert.deepStrictEqual(fcodes(K.compareCarrier(exp, slot, rest, null)), ['testimonial-hover'], 'the hover state is required');
  assert.match(K.compareCarrier(exp, slot, rest, null)[0][1], /not measured/);
  // A panel is static: any change on hover fails.
  const pexp = xexp(row, 'testimonial-panel-surface'); const pslot = xslot('testimonial-panel-surface'); const prest = mtesti(pexp);
  assert.deepStrictEqual(K.compareCarrier(pexp, pslot, prest, mhover(pexp, prest)), []);
  assert.deepStrictEqual(fcodes(K.compareCarrier(pexp, pslot, prest, mhover(pexp, prest, { transform: 'matrix(1, 0, 0, 1, 0, -4)' }))), ['testimonial-hover']);
  assert.deepStrictEqual(fcodes(K.compareCarrier(pexp, pslot, prest, mhover(pexp, prest, { backgroundColor: 'rgb(0, 0, 0)' }))), ['testimonial-hover']);
  // Only the hover shadow: soft-lift has a hover shadow and no rest one.
  const lexp = xexp(XROWS.lift, 'testimonial-card-bg'); const lrest = mtesti(lexp);
  assert.deepStrictEqual(K.compareCarrier(lexp, slot, lrest, mhover(lexp, lrest)), []);
  assert.deepStrictEqual(fcodes(K.compareCarrier(lexp, slot, lrest, mhover(lexp, lrest, { boxShadow: 'none' }))), ['testimonial-hover'], 'the hover shadow is missing');
  // A null expectation fails, never skips.
  assert.deepStrictEqual(fcodes(K.compareCarrier(null, slot, rest, mhover(exp, rest))), ['testimonial-expectation']);
  const hole = JSON.parse(JSON.stringify(exp)); hole.rest.padding = null;
  assert.deepStrictEqual(fcodes(K.compareCarrier(hole, slot, rest, mhover(exp, rest))), ['testimonial-expectation']);
  assert.deepStrictEqual(fcodes(K.compareCarrier(exp, slot, { found: false, count: 0 }, null)), ['testimonial'], 'an unmeasured testimonial fails');
});

t('testimonial quote disc: rendered, hanging above the module and not cut by overflow; a clipped, missing or unplaceable disc fails with testimonial-quote', () => {
  const exp = xexp(XROWS.quiet, 'testimonial-card-bg');
  const m = mtesti(exp);
  const ok = K.testimonialQuoteProblems(m);
  assert.deepStrictEqual(ok.problems, []);
  assert.deepStrictEqual(ok.info, { hangs: true, top: -20 });
  assert.strictEqual(K.testimonialQuoteProblems(mtesti(exp, { quote: Object.assign({}, m.quote, { top: '0px', marginTop: '-12px' }) })).info.hangs, true, 'a negative margin hangs it too');
  assert.strictEqual(K.testimonialQuoteProblems(mtesti(exp, { quote: Object.assign({}, m.quote, { top: '8px' }) })).info.hangs, false, 'a disc inside the box cannot be cut, and says so');
  const clipped = K.testimonialQuoteProblems(Object.assign({}, m, { el: Object.assign({}, m.el, { overflowX: 'hidden', overflowY: 'hidden' }) }));
  assert.deepStrictEqual(fcodes(clipped.problems), ['testimonial-quote']);
  assert.match(clipped.problems[0][1], /overflow is hidden\/hidden.*would be cut/);
  assert.deepStrictEqual(fcodes(K.testimonialQuoteProblems(Object.assign({}, m, { el: Object.assign({}, m.el, { overflowY: 'clip' }) })).problems), ['testimonial-quote'], 'one axis is enough');
  for (const content of ['none', 'normal', '']) assert.deepStrictEqual(fcodes(K.testimonialQuoteProblems(Object.assign({}, m, { quote: Object.assign({}, m.quote, { content }) })).problems), ['testimonial-quote'], 'content ' + JSON.stringify(content));
  assert.deepStrictEqual(fcodes(K.testimonialQuoteProblems(Object.assign({}, m, { quote: null })).problems), ['testimonial-quote'], 'no pseudo measured');
  assert.deepStrictEqual(fcodes(K.testimonialQuoteProblems(Object.assign({}, m, { quote: Object.assign({}, m.quote, { top: 'auto' }) })).problems), ['testimonial-quote'], 'an absolutely positioned disc with no placeable top');
  assert.deepStrictEqual(fcodes(K.testimonialQuoteProblems(null).problems), ['testimonial-quote']);
  // It is part of compareCarrier: a clipped quote on an otherwise agreeing testimonial is the only finding.
  const slot = xslot('testimonial-card-bg');
  assert.deepStrictEqual(fcodes(K.compareCarrier(exp, slot, Object.assign({}, m, { el: Object.assign({}, m.el, { overflowX: 'hidden', overflowY: 'hidden' }) }), mhover(exp, m))), ['testimonial-quote']);
});

t('blurb icon chip: the four corners, the fill and the glyph colour each fail with their own code; a 0px kit is four zeros', () => {
  const exp = xexp(XROWS.quiet, 'blurb-chip-surface'); const slot = xslot('blurb-chip-surface');
  const mb = (el) => { const m = mblurb(exp); return Object.assign({}, m, { el: Object.assign({}, m.el, el) }); };
  assert.deepStrictEqual(K.compareCarrier(exp, slot, mb({}), null), []);
  assert.deepStrictEqual(fcodes(K.compareCarrier(exp, slot, mb({ borderTopLeftRadius: '12px' }), null)), ['blurb-chip-radius']);
  assert.deepStrictEqual(fcodes(K.compareCarrier(exp, slot, mb({ borderBottomRightRadius: '0px' }), null)), ['blurb-chip-radius']);
  assert.deepStrictEqual(fcodes(K.compareCarrier(exp, slot, mb({ backgroundColor: 'rgba(0, 0, 0, 0)' }), null)), ['blurb-chip-fill'], 'the fill sits on another element (a wrapper) instead of the icon');
  assert.deepStrictEqual(fcodes(K.compareCarrier(exp, slot, mb({ color: 'rgb(51, 51, 51)' }), null)), ['blurb-chip-glyph'], 'Divi\'s default glyph colour');
  const raw = xexp(XROWS.raw, 'blurb-chip-surface');
  assert.deepStrictEqual(K.compareCarrier(raw, slot, mblurb(raw), null), []);
  assert.deepStrictEqual(fcodes(K.compareCarrier(raw, slot, Object.assign({}, mblurb(raw), { el: Object.assign({}, mblurb(raw).el, { borderTopLeftRadius: '4px' }) }), null)), ['blurb-chip-radius'], 'a stray radius on a 0px kit');
  assert.deepStrictEqual(fcodes(K.compareCarrier(null, slot, mblurb(exp), null)), ['blurb-chip-expectation']);
  assert.deepStrictEqual(fcodes(K.compareCarrier(exp, slot, { found: false, count: 1 }, null)), ['blurb-chip'], 'a blurb whose icon was not found');
});

t('counter rule: style, width and colour (alpha included) of the top edge, and the other three sides at 0px, each with their own code', () => {
  for (const [row, key] of [[XROWS.quiet, 'counter-rule-bg'], [XROWS.dashed, 'counter-rule-strong-accent'], [XROWS.raw, 'counter-rule-strong-accent']]) {
    const e = xexp(row, key); const slot = xslot(key); const col = mline(e).line.borderTopColor;
    assert.deepStrictEqual(K.compareCarrier(e, slot, mline(e), null), [], key);
    const swap = e.top.style === 'dashed' ? 'dotted' : 'dashed';
    assert.deepStrictEqual(fcodes(K.compareCarrier(e, slot, mline(e, { line: lineOf(e.top.width, swap, col) }), null)), ['counter-rule-style'], key + ' style');
    assert.deepStrictEqual(fcodes(K.compareCarrier(e, slot, mline(e, { line: lineOf(e.top.width + 1, e.top.style, col) }), null)), ['counter-rule-width'], key + ' width');
    assert.deepStrictEqual(fcodes(K.compareCarrier(e, slot, mline(e, { line: lineOf(e.top.width, e.top.style, 'rgb(220, 38, 38)') }), null)), ['counter-rule-colour'], key + ' colour');
    assert.deepStrictEqual(fcodes(K.compareCarrier(e, slot, mline(e, { el: Object.assign({}, mline(e).el, { borderBottomWidth: '2px' }) }), null)), ['counter-rule-width'], key + ' a stray side');
  }
  const e = xexp(XROWS.quiet, 'counter-rule-bg'); const slot = xslot('counter-rule-bg');
  assert.deepStrictEqual(fcodes(K.compareCarrier(null, slot, mline(e), null)), ['counter-rule-expectation']);
  assert.deepStrictEqual(fcodes(K.compareCarrier(e, slot, { found: false, count: 0 }, null)), ['counter-rule']);
  const hole = JSON.parse(JSON.stringify(e)); hole.top.width = null;
  assert.deepStrictEqual(fcodes(K.compareCarrier(hole, slot, mline(e), null)), ['counter-rule-expectation']);
});

t('accent rule: style, width and colour of the divider line each fail with their own code; the contrast is judged against the exported floor on the PAINTED band', () => {
  for (const [row, key] of [[XROWS.quiet, 'accent-rule-bg'], [XROWS.dashed, 'accent-rule-surface'], [XROWS.dotted, 'accent-rule-bg'], [XROWS.raw, 'accent-rule-surface']]) {
    const e = xexp(row, key); const slot = xslot(key); const col = mline(e).line.borderTopColor;
    assert.deepStrictEqual(K.compareCarrier(e, slot, mline(e), null), [], key);
    const swap = e.top.style === 'dashed' ? 'dotted' : 'dashed';
    assert.deepStrictEqual(fcodes(K.compareCarrier(e, slot, mline(e, { line: lineOf(e.top.width, swap, col) }), null)), ['accent-rule-style'], key + ' style');
    assert.deepStrictEqual(fcodes(K.compareCarrier(e, slot, mline(e, { line: lineOf(4, e.top.style, col) }), null)).includes('accent-rule-width') || e.top.width === 4, true, key + ' width (the preset\'s own 4px must not stand in for the kit)');
    assert.deepStrictEqual(fcodes(K.compareCarrier(e, slot, mline(e, { line: lineOf(e.top.width + 1, e.top.style, col) }), null)), ['accent-rule-width'], key + ' width +1');
    assert.deepStrictEqual(fcodes(K.compareCarrier(e, slot, mline(e, { line: lineOf(e.top.width, e.top.style, 'rgb(51, 51, 51)') }), null)), ['accent-rule-colour'], key + ' colour');
  }
  const e = xexp(XROWS.quiet, 'accent-rule-bg'); const m = mline(e);
  const c = (band, floor) => K.accentRuleContrastProblems(m, band, floor);
  assert.deepStrictEqual(c(BAND.bg, 2.3).problems, []);
  assert.ok(c(BAND.bg, 2.3).info.line > 5 && c(BAND.bg, 2.3).info.need === 2.3);
  const weak = mline(e, { line: lineOf(2, 'solid', 'rgb(214, 160, 140)') });
  assert.deepStrictEqual(fcodes(K.accentRuleContrastProblems(weak, BAND.bg, 2.3).problems), ['accent-rule-contrast']);
  assert.match(K.accentRuleContrastProblems(weak, BAND.bg, 2.3).problems[0][1], /needs 2\.3/);
  assert.deepStrictEqual(K.accentRuleContrastProblems(weak, BAND.bg, 1.2).problems, [], 'the floor is a parameter, not a constant in the checker');
  for (const bad of [null, undefined, 0, -1, NaN, '2.3']) assert.deepStrictEqual(fcodes(c(BAND.bg, bad).problems), ['accent-rule-contrast'], 'floor ' + String(bad));
  assert.deepStrictEqual(fcodes(c(null, 2.3).problems), ['accent-rule-contrast'], 'no band sample');
  assert.deepStrictEqual(fcodes(K.accentRuleContrastProblems(mline(e, { line: lineOf(0, 'none', 'rgb(154, 59, 31)') }), BAND.bg, 2.3).problems), ['accent-rule-contrast'], 'no line is drawn');
  assert.deepStrictEqual(fcodes(K.accentRuleContrastProblems(mline(e, { line: lineOf(2, 'solid', 'banana') }), BAND.bg, 2.3).problems), ['accent-rule-contrast'], 'unreadable colour');
});

t('the accent floor comes from the PHP export and is validated; it is never a number typed in the gate', () => {
  assert.deepStrictEqual(K.accentFloorFromRow({ accentRuleFloor: 2.3 }), { floor: 2.3, problems: [] });
  for (const bad of [undefined, null, 'x', 0, 1, -2.3, NaN, Infinity, 22]) assert.strictEqual(K.accentFloorFromRow({ accentRuleFloor: bad }).floor, null, String(bad));
  assert.strictEqual(K.accentFloorFromRow(null).floor, null);
  assert.strictEqual(K.accentFloorFromRow({}).problems.length, 1);
  const rows = JSON.parse(spawnSync('php', [path.join(__dirname, 'lib', 'kit-sheet-offline.php'), '--kit', 'quiet-solid', '--language', 'editorial', '--no-markup'], { input: K.sheetMarkup(), encoding: 'utf8' }).stdout);
  assert.deepStrictEqual(K.accentFloorFromRow(rows[0]), { floor: 2.3, problems: [] }, 'RuleChipAudit::ACCENT_RULE_FLOOR through the offline sheet');
  // The number is not typed in any of the gate\'s files.
  for (const f of ['verify-kits.cjs', path.join('lib', 'kit-checks.cjs'), path.join('lib', 'kit-carrier-run.cjs')]) assert.strictEqual(/\b2\.3\b/.test(fs.readFileSync(path.join(__dirname, f), 'utf8')), false, f + ' types the accent floor');
});

t('painted checks: a testimonial\'s painted fill and text, a blurb\'s painted fill and glyph, a counter\'s and the accent mark\'s line over the painted band', () => {
  // Agreeing carriers pass everywhere.
  for (const [name, row] of Object.entries(XROWS)) for (const key of CAR_KEYS) {
    const slot = xslot(key); const exp = xexp(row, key);
    assert.deepStrictEqual(K.carrierPaintProblems(exp, slot, mcar(row, key), samplesOf(key, exp), { hairline: '#e3dccf', accentFloor: 2.3 }).problems, [], `${name} ${key}`);
  }
  const opts = { hairline: '#e3dccf', accentFloor: 2.3 };
  // testimonial: something painted over the fill; unreadable text; a meaningful frame too faint; no sample.
  const texp = xexp(XROWS.neo, 'testimonial-card-bg'); const tslot = xslot('testimonial-card-bg'); const tm = mtesti(texp);
  assert.deepStrictEqual(fcodes(K.carrierPaintProblems(texp, tslot, tm, { fill: { r: 30, g: 30, b: 30 } }, opts).problems), ['testimonial-fill', 'testimonial-contrast'], 'a dark layer over a white card: fill differs, text unreadable on it');
  assert.deepStrictEqual(fcodes(K.carrierPaintProblems(texp, tslot, Object.assign({}, tm, { paragraph: { color: 'rgb(200, 200, 200)' } }), { fill: K.colour(texp.rest.fill) }, opts).problems), ['testimonial-contrast']);
  assert.deepStrictEqual(fcodes(K.carrierPaintProblems(texp, tslot, tm, null, opts).problems), ['testimonial-fill']);
  // blurb: fill painted over, glyph = fill colour, fill = band colour.
  const bexp = xexp(XROWS.quiet, 'blurb-chip-surface'); const bslot = xslot('blurb-chip-surface'); const bm = mblurb(bexp);
  assert.deepStrictEqual(fcodes(K.carrierPaintProblems(bexp, bslot, bm, { fill: { r: 20, g: 20, b: 20 }, band: BAND.surface }, opts).problems).includes('blurb-chip-fill'), true, 'something is painted over the icon');
  assert.deepStrictEqual(fcodes(K.carrierPaintProblems(bexp, bslot, Object.assign({}, bm, { el: Object.assign({}, bm.el, { color: rgbOf(bexp.fill) }) }), { fill: K.colour(bexp.fill), band: BAND.surface }, opts).problems), ['blurb-chip-contrast'], 'a glyph in the colour of its fill');
  assert.deepStrictEqual(fcodes(K.carrierPaintProblems(bexp, bslot, bm, { fill: K.colour(bexp.fill), band: K.colour(bexp.fill) }, opts).problems), ['blurb-chip-contrast'], 'a chip the colour of its band cannot be seen');
  assert.deepStrictEqual(fcodes(K.carrierPaintProblems(bexp, bslot, bm, { fill: K.colour(bexp.fill), band: null }, opts).problems), ['blurb-chip-contrast'], 'no band sample');
  assert.deepStrictEqual(fcodes(K.carrierPaintProblems(bexp, bslot, bm, { fill: null, band: BAND.surface }, opts).problems), ['blurb-chip-fill', 'blurb-chip-contrast'], 'no fill sample');
  // counter: strong 3.0, quiet 1.1; a line the colour of its band.
  const cexp2 = xexp(XROWS.quiet, 'counter-rule-strong-accent'); const cslot = xslot('counter-rule-strong-accent');
  assert.deepStrictEqual(fcodes(K.carrierPaintProblems(cexp2, cslot, mline(cexp2), { band: { r: 255, g: 250, b: 250 } }, opts).problems), ['counter-rule-contrast'], 'a white strong line on a near-white band');
  assert.deepStrictEqual(fcodes(K.carrierPaintProblems(cexp2, cslot, mline(cexp2), { band: null }, opts).problems), ['counter-rule-contrast']);
  const qexp = xexp(XROWS.quiet, 'counter-rule-bg'); const qslot = xslot('counter-rule-bg');
  assert.deepStrictEqual(fcodes(K.carrierPaintProblems(qexp, qslot, mline(qexp), { band: { r: 227, g: 220, b: 207 } }, opts).problems), ['counter-rule-contrast'], 'a quiet line the colour of its band');
  // accent: below the floor, no floor.
  const aexp = xexp(XROWS.quiet, 'accent-rule-bg'); const aslot = xslot('accent-rule-bg');
  assert.deepStrictEqual(fcodes(K.carrierPaintProblems(aexp, aslot, mline(aexp), { band: { r: 190, g: 90, b: 60 } }, opts).problems), ['accent-rule-contrast']);
  assert.deepStrictEqual(fcodes(K.carrierPaintProblems(aexp, aslot, mline(aexp), { band: BAND.bg }, {}).problems), ['accent-rule-contrast'], 'no floor passed');
  // An expectation with a hole is left to compareCarrier (one finding, not two).
  assert.deepStrictEqual(K.carrierPaintProblems(null, aslot, mline(aexp), { band: BAND.bg }, opts), { problems: [], info: {} });
});

t('carrier overflow: beyond the container (1px) or the window, or a page wider than the window', () => {
  const page = { innerWidth: 1280, scrollWidth: 1280 };
  assert.deepStrictEqual(K.carrierOverflowProblems({ rect: R(40, 0, 340, 10), parentRect: R(20, 0, 380, 10) }, 'rest', page), []);
  assert.deepStrictEqual(fcodes(K.carrierOverflowProblems({ rect: R(40, 0, 400, 10), parentRect: R(20, 0, 380, 10) }, 'rest', page)), ['overflow']);
  assert.match(K.carrierOverflowProblems({ rect: R(40, 0, 400, 10), parentRect: R(20, 0, 380, 10) }, 'hover', page)[0][1], /^hover: carrier /);
  assert.deepStrictEqual(fcodes(K.carrierOverflowProblems({ rect: R(40, 0, 340, 10), parentRect: R(20, 0, 380, 10) }, 'rest', { innerWidth: 400, scrollWidth: 500 })), ['overflow']);
});

t('carrier count (code carrier): a missing slot, a slot on the page twice, an element not found; an empty slot list is an error; a run that checked none fails', () => {
  const all = K.CARRIER_SLOTS.map((s) => ({ key: s.key, found: true, count: 1 }));
  assert.deepStrictEqual(K.carrierCountProblems(all), []);
  for (const key of CAR_KEYS) {
    const gone = K.carrierCountProblems(all.map((m) => (m.key === key ? { key, found: false, count: 0 } : m)));
    assert.deepStrictEqual(fcodes(gone), ['carrier'], key);
    assert.match(gone[0][1], new RegExp(`^7 of 8 carriers rendered as the sheet says: ${key} is not in the page`), key);
    assert.match(K.carrierCountProblems(all.map((m) => (m.key === key ? { key, found: true, count: 2 } : m)))[0][1], new RegExp(`${key} is on the page 2 times, expected 1`));
  }
  assert.match(K.carrierCountProblems(all.map((m) => (m.key === 'blurb-chip-accent' ? { key: m.key, found: false, count: 1 } : m)))[0][1], /blurb-chip-accent is not in the page \(its element was not found\)/, 'a blurb whose icon is missing');
  assert.throws(() => K.carrierCountProblems([], []), /no carrier slots/);
  assert.match(K.carrierCountProblems([], K.CARRIER_SLOTS)[0][1], /^0 of 8 carriers rendered/);
  assert.match(K.carrierCountProblems([...all, { key: 'x', found: true, count: 1 }])[0][1], /9 carriers measured for 8 slots/);
  assert.deepStrictEqual(K.emptyCarrierRunProblems({ carriersChecked: 0 }), ['no carrier was checked: the carrier selection was empty']);
  assert.deepStrictEqual(K.emptyCarrierRunProblems({}), ['no carrier was checked: the carrier selection was empty'], 'a caller that forgets the carrier count fails, never skips');
  assert.deepStrictEqual(K.emptyCarrierRunProblems({ carriersChecked: 16 }), []);
  // emptyRunProblems gains carriersChecked: counted when the caller passes it, the older three checks unchanged.
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 12, cardsChecked: 144, itemsChecked: 120, carriersChecked: 96 }), []);
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 12, cardsChecked: 144, itemsChecked: 120, carriersChecked: 0 }), ['no carrier was checked: the carrier selection was empty']);
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 12, cardsChecked: 144, itemsChecked: 120 }), [], 'the older call shape is unchanged');
  // The gate itself must always pass the count (a source check: dropping it would make the empty-run check skip).
  const gate = fs.readFileSync(path.join(__dirname, 'verify-kits.cjs'), 'utf8');
  assert.match(gate, /K\.emptyRunProblems\(\{[^}]*carriersChecked[^}]*\}\)/, 'verify-kits.cjs passes carriersChecked to emptyRunProblems');
  assert.match(gate, /K\.emptyCarrierRunProblems\(\{ carriersChecked \}\)/);
  assert.match(gate, /FAIL carrier: /);
});

t('every kit x every language (offline): the 8 carriers are in the resolved markup with complete expectations and the colours the role tables say', () => {
  const php = spawnSync('php', [path.join(__dirname, 'lib', 'kit-sheet-offline.php')], { input: K.sheetMarkup(), encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
  assert.strictEqual(php.status, 0, 'php failed: ' + (php.error ? php.error.message : php.stderr));
  const rows = JSON.parse(php.stdout);
  const langs = Object.fromEntries(JSON.parse(fs.readFileSync(path.join(ROOT, 'wp-plugin', 'data', 'design-languages.json'), 'utf8')).map((l) => [l.id, l.colors]));
  const kitData = Object.fromEntries(JSON.parse(fs.readFileSync(path.join(ROOT, 'wp-plugin', 'data', 'style-kits.json'), 'utf8')).map((k) => [k.id, k]));
  let checked = 0;
  for (const row of rows) {
    const cell = row.kit + '/' + row.language; const pal = langs[row.language];
    assert.strictEqual(row.accentRuleFloor, 2.3, cell + ' exports the accent floor');
    for (const { slot, attrs } of K.carrierAttrs(row.markup)) {
      const where = `${cell} ${slot.key}`;
      assert.ok(attrs, where + ' not in the resolved markup');
      assert.strictEqual(JSON.stringify(attrs).includes('"aied"'), false, where + ' a marker is left');
      checked++;
      const exp = K.expectCarrierFromAttrs(attrs, slot);
      assert.deepStrictEqual(K.missingCarrierExpectations(exp, slot), [], where);
      if (slot.carrier === 'blurb') {
        assert.deepStrictEqual(exp.radius, [0, 1, 2, 3].map(() => parseFloat(kitData[row.kit].radius.control)), `${where} radius is the kit's control radius`);
        if (slot.on === 'accent') assert.ok(K.sameColour(exp.fill, pal.surface) && K.sameColour(exp.glyph, pal.text), `${where} fill ${exp.fill} / glyph ${exp.glyph}`);
        else assert.ok(K.sameColour(exp.fill, pal.accent) && K.sameColour(exp.glyph, pal.accent_text), `${where} fill ${exp.fill} / glyph ${exp.glyph}`);
      } else if (slot.carrier === 'divider') {
        assert.ok(K.sameColour(exp.top.color, pal.accent), `${where} the accent mark is the accent colour, ${exp.top.color}`);
        assert.ok(exp.top.width >= 2, where + ' at least the strong rule width');
        if (exp.top.style === 'double') assert.ok(exp.top.width >= 3, where + ' a double is at least 3px');
        const ratio = require('./lib/recipe-checks.cjs').ratioOf(K.colour(exp.top.color), K.colour(slot.band === 'bg' ? pal.bg : pal.surface));
        assert.ok(ratio >= row.accentRuleFloor, `${where} accent on its band is ${ratio.toFixed(3)}:1, below the floor ${row.accentRuleFloor}`);
      } else if (slot.carrier === 'counter') {
        assert.deepStrictEqual(exp.others, { right: 0, bottom: 0, left: 0 }, where + ' only the top edge');
        if (slot.role === 'rule-strong') assert.ok(K.sameColour(exp.top.color, pal.accent_text), `${where} strong colour ${exp.top.color}`);
        else assert.ok(K.sameColour(exp.top.color, pal.border), `${where} quiet colour ${exp.top.color}`);
        if (exp.top.style === 'double') assert.ok(exp.top.width >= 3, where + ' a double is at least 3px');
      } else {
        assert.ok(K.sameColour(exp.rest.fill, slot.on === 'surface' ? pal.bg : pal.surface), `${where} fill ${exp.rest.fill}`);
        assert.deepStrictEqual(exp.hoverKeys.filter((k) => k === 'border.hover' || k === 'background.hover').length > 0 && slot.role === 'panel', false, where + ' a panel has no hover frame or fill');
        if (slot.role === 'panel') assert.deepStrictEqual(exp.hoverKeys, [], where + ' a panel is static');
      }
    }
  }
  assert.strictEqual(checked, rows.length * K.CARRIER_SLOTS.length, 'every carrier of every cell was checked');
});

// ---- the one bounded hover retry (a timing miss must not fail a cell; a persistent defect must) ----
const HP = (d) => ['panel-button', d];
const BG = 'hover: background-color rgba(0, 0, 0, 0), expected #faf7f2';
const FG = 'hover: color rgb(28, 26, 23), expected #faf7f2';
t('hover retry: a first measurement without a hover problem is final and is not retried', () => {
  assert.strictEqual(K.needsHoverRetry([]), false);
  assert.strictEqual(K.needsHoverRetry([['border', 'rest: border-top-width 1px, expected 2px']]), false, 'a rest problem never triggers a retry');
  const rest = [['fill', 'rest: background-color red, expected blue']];
  assert.deepStrictEqual(K.settleHover(rest, null), { problems: rest, retried: false });
  assert.deepStrictEqual(K.settleHover([], null), { problems: [], retried: false });
  assert.strictEqual(K.needsHoverRetry([HP(BG)]), true);
  assert.strictEqual(K.needsHoverRetry([['card-hover', 'the hover state was not measured']]), true);
  assert.strictEqual(K.needsHoverRetry([['lift', 'hover: translateY button 0px / wrapper 0px, expected -4px']]), true);
  assert.strictEqual(K.needsHoverRetry([['panel-button', 'hover: label #1c1a17 on #1c1a17 is 1:1, needs 4.5']]), true);
});
t('hover retry: a first mismatch followed by a match passes (the rest problems are kept)', () => {
  const r = K.settleHover([HP(BG), HP(FG)], []);
  assert.deepStrictEqual(r, { problems: [], retried: true });
  const keep = K.settleHover([['fill', 'rest: x'], HP(BG)], [['fill', 'rest: x']]);
  assert.deepStrictEqual(keep, { problems: [['fill', 'rest: x']], retried: true }, 'a rest problem is still reported after a clean retry');
});
t('hover retry: a persistent mismatch fails and names both observations', () => {
  const second = 'hover: background-color rgba(0, 0, 0, 0), expected #faf7f2';
  const r = K.settleHover([HP(BG)], [HP(second)]);
  assert.strictEqual(r.retried, true);
  assert.strictEqual(r.problems.length, 1);
  assert.strictEqual(r.problems[0][0], 'panel-button');
  assert.ok(r.problems[0][1].startsWith(second), 'the second observation is the problem');
  assert.match(r.problems[0][1], /\(retried once: first hover: background-color rgba\(0, 0, 0, 0\), expected #faf7f2, second hover: background-color rgba\(0, 0, 0, 0\), expected #faf7f2\)$/);
  // A different wrong value the second time: both values are visible.
  const r2 = K.settleHover([HP('hover: color rgb(1, 1, 1), expected #fff')], [HP('hover: color rgb(2, 2, 2), expected #fff')]);
  assert.match(r2.problems[0][1], /rgb\(1, 1, 1\).*rgb\(2, 2, 2\)/);
  // The first mismatch gone but a different one appears: still a failure.
  const r3 = K.settleHover([HP(BG)], [HP(FG)]);
  assert.strictEqual(r3.problems.length, 1);
  assert.match(r3.problems[0][1], /^hover: color/);
  // Two hover problems each, in the same order: each names ITS OWN first observation, not the other's.
  const r5 = K.settleHover([HP('hover: background-color a, expected b'), HP('hover: color c, expected d')], [HP('hover: background-color a2, expected b'), HP('hover: color c2, expected d')]);
  assert.match(r5.problems[0][1], /^hover: background-color a2, expected b \(retried once: first hover: background-color a, expected b, second hover: background-color a2, expected b\)$/);
  assert.match(r5.problems[1][1], /\(retried once: first hover: color c, expected d, second hover: color c2, expected d\)$/);
  // A different number of hover problems the second time: the first run's observations are listed together.
  const r6 = K.settleHover([HP('hover: color c, expected d'), HP('hover: background-color a, expected b')], [HP('hover: color c2, expected d')]);
  assert.match(r6.problems[0][1], /first hover: color c, expected d; hover: background-color a, expected b, second hover: color c2, expected d\)$/);
  // Rest problems alongside are kept untouched, unannotated.
  const r4 = K.settleHover([['fill', 'rest: a'], HP(BG)], [['fill', 'rest: a'], HP(BG)]);
  assert.deepStrictEqual(r4.problems[0], ['fill', 'rest: a']);
  assert.match(r4.problems[1][1], /retried once/);
});
t('hover retry: a null measurement fails, never passes', () => {
  assert.deepStrictEqual(K.settleHover(null, null, 'panel-button'), { problems: [['panel-button', 'the hover state was not measured']], retried: false });
  assert.deepStrictEqual(K.settleHover(undefined, [], 'card-hover').problems, [['card-hover', 'the hover state was not measured']], 'a missing first judgement cannot be rescued by a later clean one');
  const lost = K.settleHover([HP(BG)], null, 'panel-button');
  assert.strictEqual(lost.problems.length, 1);
  assert.match(lost.problems[0][1], /retry measurement missing/);
  assert.strictEqual(lost.retried, true);
});
t('hover retry: an overflow seen only on the first attempt is still reported, once; the comparison keeps the retry semantics', () => {
  const rect = (l, r) => ({ left: l, right: r, top: 0, bottom: 10, width: r - l, height: 10 });
  const page = { innerWidth: 1280, scrollWidth: 1280 };
  const spill = K.cardOverflowProblems({ rect: rect(100, 450), parentRect: rect(0, 400) }, 'hover', page); // first attempt: sticks out right
  const clean = K.cardOverflowProblems({ rect: rect(0, 400), parentRect: rect(0, 400) }, 'hover', page);
  assert.strictEqual(spill.length, 1);
  assert.deepStrictEqual(clean, []);
  // First: hover mismatch + overflow. Second: matching and no overflow. The mismatch is forgiven, the overflow is not.
  const r = K.settleHoverRun({ problems: [HP(BG)], extras: spill }, { problems: [], extras: clean }, 'panel-button');
  assert.deepStrictEqual(r, { problems: [], extras: spill, retried: true });
  // The same overflow on both attempts is reported once.
  assert.deepStrictEqual(K.settleHoverRun({ problems: [HP(BG)], extras: spill }, { problems: [], extras: spill }).extras, spill);
  // A different overflow on each is both reported; a persistent mismatch still fails with both observations.
  const other = K.cardOverflowProblems({ rect: rect(0, 500), parentRect: rect(0, 400) }, 'hover', page);
  const both = K.settleHoverRun({ problems: [HP(BG)], extras: spill }, { problems: [HP(BG)], extras: other });
  assert.strictEqual(both.extras.length, 2);
  assert.match(both.problems[0][1], /retried once/);
  // No retry taken: the single attempt's extras, nothing else.
  assert.deepStrictEqual(K.settleHoverRun({ problems: [], extras: spill }, null), { problems: [], extras: spill, retried: false });
  assert.deepStrictEqual(K.unionProblems([['a', '1'], ['b', '2']], [['a', '1'], ['a', '2']], null), [['a', '1'], ['b', '2'], ['a', '2']]);
});
t('hover retry: the second wait is twice the first, capped at 2000 ms, never shorter', () => {
  assert.strictEqual(K.hoverRetryWait(150), 300);
  assert.strictEqual(K.hoverRetryWait(450), 900);
  assert.strictEqual(K.hoverRetryWait(1100), 2000);
  assert.strictEqual(K.hoverRetryWait(2150), 2150, 'never shorter than the first wait');
  assert.strictEqual(K.HOVER_RETRY_CAP_MS, 2000);
});

console.log(`OK verify-kits pure parts: ${n} tests (run titles, selection, sheet, the one bounded hover retry, expectations from resolved markup, rest and hover comparison, shadows, lift, contrast, overflow, PNG; cards: sheet, expectations, comparison, panel static on hover, clip, contrast, overflow, column row, counts, empty run; grounds, panel buttons, the featured card; items: sheet, expectations per state, comparison, count, click, contrast; forms: sheet, expectations from the resolved markup, field/submit/captcha/container comparison, painted fill, contrast, clip, overflow, count; images: the media-radius token sheet, four corners from the resolved markup, radius on the wrapper or the img, clip, overflow, count, empty run; rules and chips: the sheet, expectations from the resolved markup, style/width/colour/radius/frame/fill comparison, contrast on the painted band, count, overflow, empty run; carriers: the 8 slots, expectations from the resolved markup, testimonial fill/frame/radius/padding/shadow/hover/quote, blurb chip radius/fill/glyph, counter and accent rule style/width/colour, contrast against the exported accent floor, count, overflow, empty run; offline sheet through PHP for every kit x language)`);
