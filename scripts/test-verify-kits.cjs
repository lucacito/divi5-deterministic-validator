#!/usr/bin/env node
'use strict';
/* Pure tests of scripts/lib/kit-checks.cjs (no site, no browser): run titles, cell selection, the sheet template, expectations read from
 * resolved markup (samples are real KitResolver + RecipeTokens output for editorial), the expected-vs-computed comparison (rest and hover),
 * shadows, lift, contrast on the painted surface, overflow and the PNG decoder, and for the CARDS (card/panel on a group and on a column) the
 * same set: the sheet, expectations read from the resolved block tree, the rest/hover comparison (a panel must not change), the clip, the
 * contrast on the measured card fill, overflow, the three-column row, the zero-card rule and an OFFLINE run of the whole sheet through the
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
t('the sheet carries 8 marked buttons, surfaces in order', () => {
  const m = K.sheetMarkup();
  const attrs = K.buttonAttrs(m);
  assert.strictEqual(attrs.length, 8);
  assert.strictEqual(K.SLOTS.length, 8);
  attrs.forEach((a, i) => {
    const s = K.SLOTS[i];
    assert.deepStrictEqual(a.aied, s.on === 'light' ? { kit: s.role } : { kit: s.role, on: s.on }, 'marker of slot ' + i);
  });
  assert.strictEqual((m.match(/<!-- wp:divi\/section /g) || []).length, 5, 'four bands and the three-column row');
  assert.ok(m.includes('{{aied:image:section-bg-slate-1}}') && m.includes('"overlaysImage":"on"'), 'the photo band has an image under a scrim');
});
t('the sheet carries 12 marked cards: a card and a panel on each band, an image-first panel, and a row of three column cards', () => {
  const m = K.sheetMarkup();
  assert.deepStrictEqual(K.CARD_SLOTS.map((c) => c.key), ['card-bg', 'panel-bg', 'panel-clip', 'card-surface', 'panel-surface', 'card-accent', 'panel-accent', 'card-dark', 'panel-dark', 'column-1', 'column-2', 'column-3']);
  assert.strictEqual(new Set(K.CARD_SLOTS.map((c) => c.heading)).size, K.CARD_SLOTS.length, 'every card is found by a unique heading');
  const markers = [];
  const walk = (nodes) => { for (const n of nodes) { if (n.attrs && n.attrs.aied && n.name !== 'divi/button') markers.push([n.name, n.attrs.aied, K.CARD_SLOTS.find((c) => c.heading === (n.children.map((x) => x).find((x) => x.name === 'divi/heading') || { attrs: {} }).attrs.title?.innerContent.desktop.value)]); walk(n.children); } };
  walk(K.blockTree(m));
  assert.strictEqual(markers.length, 12);
  markers.forEach(([name, marker, slot]) => {
    assert.ok(slot, 'a marked block holds the heading of a slot');
    assert.strictEqual(name, 'divi/' + slot.carrier);
    assert.deepStrictEqual(marker, slot.on === 'light' ? { kit: slot.role } : { kit: slot.role, on: slot.on }, slot.key);
  });
  assert.deepStrictEqual(K.CARD_SLOTS.map((c) => c.on), ['light', 'light', 'light', 'surface', 'surface', 'accent', 'accent', 'dark', 'dark', 'light', 'light', 'light']);
  // A panel keeps its own padding (no kit key); the clip panel has none, so its image touches the corners; a card has no padding literal.
  const bySlot = Object.fromEntries(K.cardAttrs(m).map((c) => [c.slot.key, c.attrs]));
  assert.deepStrictEqual(bySlot['panel-dark'].module.decoration.spacing.desktop.value.padding, { top: '40px', right: '40px', bottom: '40px', left: '40px' });
  assert.deepStrictEqual(bySlot['panel-clip'].module.decoration.spacing.desktop.value.padding, { top: '0px', right: '0px', bottom: '0px', left: '0px' });
  assert.strictEqual(bySlot['card-bg'].module.decoration.spacing, undefined, 'the kit gives a card its padding');
  assert.strictEqual(K.CARD_SLOTS.filter((c) => c.image).map((c) => c.key).join(), 'card-bg,panel-clip', 'the bg-band card and the clip panel hold an image');
  assert.strictEqual((m.match(/wp:divi\/image /g) || []).length, 2);
  assert.ok(m.includes('"columnStructure":{"desktop":{"value":"1_3,1_3,1_3"}}') && m.includes('equal-columns_3'), 'the three-column row is the real proof-press-quotes shape');
  assert.strictEqual(K.cardAttrs(m).filter((c) => c.attrs).length, 12, 'every card is found again by its heading');
  assert.strictEqual(K.cardAttrs(m).find((c) => c.slot.key === 'column-2').attrs.module.advanced.type.desktop.value, '1_3');
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
  for (const [side] of [['top'], ['right'], ['bottom'], ['left']]) assert.deepStrictEqual(exQuiet.rest[side], { width: 1, style: 'solid', color: '#e3dccf' }, side);
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
  const value = `${K.colour(sh.color) ? 'rgb(' + K.colour(sh.color).r + ', ' + K.colour(sh.color).g + ', ' + K.colour(sh.color).b + ')' : ''} 6px 6px 0px 0px`;
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
  assert.deepStrictEqual(codesOf(ok({ borderLeftWidth: '3px' })), ['card-hover', 'card-border'].filter((c) => c === 'card-hover'), 'a panel whose frame grows');
  // a panel never gets the card's hover expectations, even when its expectation object carries them
  const wrong = JSON.parse(JSON.stringify(exQuietPanel)); wrong.lift = -4;
  assert.deepStrictEqual(codesOf(K.compareCard(wrong, 'panel', { rest: { el: rest }, hover: { el: Object.assign({}, rest, { transform: 'matrix(1, 0, 0, 1, 0, -4)' }) } })), ['card-hover']);
  // the rest shadow of a panel (hard shadow kit) is checked and stays on hover
  const hardRest = cstyle(Object.assign({ borderTopWidth: '3px', borderRightWidth: '3px', borderBottomWidth: '3px', borderLeftWidth: '3px', borderTopLeftRadius: '0px', borderTopRightRadius: '0px', borderBottomRightRadius: '0px', borderBottomLeftRadius: '0px', borderTopColor: 'rgb(28, 26, 23)', borderRightColor: 'rgb(28, 26, 23)', borderBottomColor: 'rgb(28, 26, 23)', borderLeftColor: 'rgb(28, 26, 23)' }, pad));
  const c = K.colour(exHardPanel.shadow.rest.color); const sv = `rgb(${c.r}, ${c.g}, ${c.b}) 6px 6px 0px 0px`;
  assert.deepStrictEqual(K.compareCard(exHardPanel, 'panel', { rest: { el: Object.assign({}, hardRest, { boxShadow: sv }) }, hover: { el: Object.assign({}, hardRest, { boxShadow: sv }) } }).problems, []);
  assert.deepStrictEqual(codesOf(K.compareCard(exHardPanel, 'panel', { rest: { el: Object.assign({}, hardRest, { boxShadow: sv }) }, hover: { el: Object.assign({}, hardRest, { boxShadow: 'none' }) } })), ['card-hover'], 'the hard shadow disappears on hover');
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
  assert.match(p[0][1], /11 cards rendered, expected 12 \(missing: panel-clip\)/);
  assert.deepStrictEqual(K.cardCountProblems([]).map((x) => x[0]), ['cards'], 'a page with no card fails');
  assert.deepStrictEqual(K.cardCountProblems(found.slice(0, 11)).map((x) => x[0]), ['cards']);
  assert.throws(() => K.cardCountProblems([], []), /no card slots/, 'an empty slot list can never pass');
});
t('an empty card selection fails the run, whatever else passed', () => {
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 12, cardsChecked: 144 }), []);
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 12, cardsChecked: 0 }), ['no card was checked: the card selection was empty']);
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 0, cardsChecked: 0 }).length, 2);
  assert.deepStrictEqual(K.emptyRunProblems({ checked: 0, cardsChecked: 5 }), ['no cell/width was checked: nothing was measured']);
});
t('the fill sample is the card\'s inside: past the frame and the corner radii, never empty', () => {
  assert.deepStrictEqual(K.cardSampleClip(R(100, 200, 500, 500), cstyle({})), { x: 107, y: 207, width: 386, height: 286 });
  const pill = K.cardSampleClip(R(0, 0, 400, 200), cstyle({ borderTopLeftRadius: '999px', borderTopRightRadius: '999px', borderBottomRightRadius: '999px', borderBottomLeftRadius: '999px' }));
  assert.ok(pill.x >= 51 && pill.width >= 1 && pill.y >= 51, 'a pill radius is capped at a quarter of the short side');
  const tiny = K.cardSampleClip(R(10, 10, 14, 14), cstyle({}));
  assert.ok(tiny.width >= 1 && tiny.height >= 1);
});

// ---- the sheet through the REAL PHP pipeline, offline ----
t('every kit x every language: the sheet resolves with zero leftovers, passes LayoutGate, and every card is in the resolved markup with a complete expectation and readable text', () => {
  const php = spawnSync('php', [path.join(__dirname, 'lib', 'kit-sheet-offline.php')], { input: K.sheetMarkup(), encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
  assert.strictEqual(php.status, 0, 'php failed: ' + (php.error ? php.error.message : php.stderr));
  const rows = JSON.parse(php.stdout);
  const langs = JSON.parse(fs.readFileSync(path.join(ROOT, 'wp-plugin', 'data', 'design-languages.json'), 'utf8'));
  const colours = Object.fromEntries(langs.map((l) => [l.id, l.colors]));
  assert.strictEqual(rows.length, kitIds.length * languages.length, 'one row per kit and language');
  assert.ok(rows.length >= 400);
  let cards = 0;
  for (const row of rows) {
    const cell = row.kit + '/' + row.language;
    assert.deepStrictEqual(row.leftovers, [], cell + ' leftovers');
    assert.strictEqual(row.tokenLeft, false, cell + ' unresolved token');
    assert.ok(row.valid, cell + ' LayoutGate: ' + row.violations.join(','));
    assert.ok(!/"aied"/.test(row.markup), cell + ' a marker is left');
    assert.strictEqual(K.buttonAttrs(row.markup).length, 8, cell + ' buttons');
    K.buttonAttrs(row.markup).forEach((a, i) => assert.deepStrictEqual(K.missingExpectations(K.expectFromAttrs(a)), [], cell + ' button ' + i));
    const found = K.cardAttrs(row.markup);
    assert.strictEqual(found.length, K.CARD_SLOTS.length);
    for (const { slot, attrs } of found) {
      const where = `${cell} ${slot.key}`;
      assert.ok(attrs, where + ' not in the resolved markup');
      cards++;
      const exp = K.expectCardFromAttrs(attrs);
      assert.deepStrictEqual(K.missingCardExpectations(exp, slot.role), [], where);
      assert.strictEqual(slot.role === 'panel' ? exp.rest.padding.join() : 'x', slot.role === 'panel' ? [slot.padding, slot.padding, slot.padding, slot.padding].join() : 'x', where + ' panel padding is the sheet\'s literal');
      const pal = colours[row.language];
      const fill = K.colour(exp.rest.fill);
      assert.strictEqual(fill.a, 1, where + ' the fill is opaque');
      const ratio = (a, b) => require('./lib/recipe-checks.cjs').ratioOf(K.colour(a), b);
      assert.ok(ratio(pal.text, fill) >= 4.5, where + ' heading ' + ratio(pal.text, fill));
      assert.ok(ratio(pal.muted, fill) >= 4.5, where + ' paragraph ' + ratio(pal.muted, fill));
      for (const [side] of [['top'], ['right'], ['bottom'], ['left']]) {
        const e = exp.rest[side];
        if (e.width > 0 && !K.sameColour(e.color, pal.border)) assert.ok(ratio(e.color, fill) >= 3, `${where} ${side} frame ${ratio(e.color, fill)}`);
      }
    }
  }
  assert.strictEqual(cards, rows.length * K.CARD_SLOTS.length);
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

console.log(`OK verify-kits pure parts: ${n} tests (run titles, selection, sheet, expectations from resolved markup, rest and hover comparison, shadows, lift, contrast, overflow, PNG; cards: sheet, expectations, comparison, panel static on hover, clip, contrast, overflow, column row, counts, empty run, offline sheet through PHP for every kit x language)`);
