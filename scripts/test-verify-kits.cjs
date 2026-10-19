#!/usr/bin/env node
'use strict';
/* Pure tests of scripts/lib/kit-checks.cjs (no site, no browser): run titles, cell selection, the sheet template, expectations read from
 * resolved markup (samples are real KitResolver + RecipeTokens output for editorial), the expected-vs-computed comparison (rest and hover),
 * shadows, lift, contrast on the painted surface, overflow and the PNG decoder. Run first by `make verify-kits`. */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
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
  assert.strictEqual((m.match(/<!-- wp:divi\/section /g) || []).length, 4);
  assert.ok(m.includes('{{aied:image:section-bg-slate-1}}') && m.includes('"overlaysImage":"on"'), 'the photo band has an image under a scrim');
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

console.log(`OK verify-kits pure parts: ${n} tests (run titles, selection, sheet, expectations from resolved markup, rest and hover comparison, shadows, lift, contrast, overflow, PNG)`);
