#!/usr/bin/env node
'use strict';
/* Pure tests of scripts/lib/kit-checks.cjs (no site, no browser): run titles, cell selection, the sheet template, expectations read from
 * resolved markup (samples are real KitResolver + RecipeTokens output for editorial), the expected-vs-computed comparison (rest and hover),
 * shadows, lift, contrast on the painted surface, overflow and the PNG decoder, and for the CARDS (card/panel on a group and on a column) the
 * same set: the sheet, expectations read from the resolved block tree, the rest/hover comparison (a panel must not change), the clip, the
 * contrast on the measured card fill, overflow, the three-column row, the zero-card rule, the grounds (the painted fill against the resolved
 * one), the kit buttons on the inverted panel, the featured card's prominence, the ITEMS (accordion and toggle: the sheet, expectations per
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
      if (n.attrs.aied && (n.name === 'divi/group' || n.name === 'divi/column')) {
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
  assert.strictEqual((m.match(/wp:divi\/image /g) || []).length, 2);
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

// ---- the sheet through the REAL PHP pipeline, offline ----
t('every kit x every language: the sheet resolves with zero leftovers, passes LayoutGate, and every card and every item block is in the resolved markup with a complete expectation and readable text', () => {
  const php = spawnSync('php', [path.join(__dirname, 'lib', 'kit-sheet-offline.php')], { input: K.sheetMarkup(), encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
  assert.strictEqual(php.status, 0, 'php failed: ' + (php.error ? php.error.message : php.stderr));
  const rows = JSON.parse(php.stdout);
  const langs = JSON.parse(fs.readFileSync(path.join(ROOT, 'wp-plugin', 'data', 'design-languages.json'), 'utf8'));
  const colours = Object.fromEntries(langs.map((l) => [l.id, l.colors]));
  assert.strictEqual(rows.length, kitIds.length * languages.length, 'one row per kit and language');
  assert.ok(rows.length >= 400);
  let cards = 0;
  let items_checked = 0;
  const featuredFillOnly = [];
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
    // The featured middle card against the plain ones on the bg band, by FILL alone (frames, which only make a plain card stronger, are the live check).
    const band = K.colour(pal.bg);
    const prom = (key) => K.boxProminence(flat({ r: fillOf[key].r, g: fillOf[key].g, b: fillOf[key].b }), solid(band));
    featuredFillOnly.push([cell, prom('column-2'), Math.max(prom('column-1'), prom('column-3'))]);
  }
  assert.strictEqual(cards, rows.length * K.CARD_SLOTS.length);
  assert.strictEqual(items_checked, rows.length * K.ITEM_SLOTS.length, 'every item block of every cell was checked');
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

console.log(`OK verify-kits pure parts: ${n} tests (run titles, selection, sheet, expectations from resolved markup, rest and hover comparison, shadows, lift, contrast, overflow, PNG; cards: sheet, expectations, comparison, panel static on hover, clip, contrast, overflow, column row, counts, empty run; grounds, panel buttons, the featured card; items: sheet, expectations per state, comparison, count, click, contrast; offline sheet through PHP for every kit x language)`);
