#!/usr/bin/env node
'use strict';
/*
 * Pure tests of scripts/lib/html-styles-measure.cjs (no site, no browser): colour and font helpers, the contrast on a painted background, what
 * the converter wrote (read out of the REAL golden layouts), every comparison, the two "never skipped" rules and the page evaluation, each code
 * with at least one PLANTED failure that must be caught. Run first by `make verify-html-to-divi`.
 *
 *   node scripts/test-verify-html-styles.cjs             pure only
 *   node scripts/test-verify-html-styles.cjs --browser   also runs the two in-page probes in a local Chromium on synthetic pages (offline)
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const M = require('./lib/html-styles-measure.cjs');

const ROOT = path.resolve(__dirname, '..');
let n = 0;
const t = (name, fn) => { try { fn(); n++; } catch (e) { console.error('FAIL  ' + name + '\n' + (e && e.stack ? e.stack : e)); process.exit(1); } };

// ---------------------------------------------------------------- colours
t('rgbToHex reads rgb, rgba, hex and transparent', () => {
  assert.strictEqual(M.rgbToHex('rgb(15, 23, 42)'), '#0f172a');
  assert.strictEqual(M.rgbToHex('rgba(15, 23, 42, 1)'), '#0f172a');
  assert.strictEqual(M.rgbToHex('rgba(0, 0, 0, 0.5)'), '#00000080');
  assert.strictEqual(M.rgbToHex('rgba(0, 0, 0, 0)'), '#00000000');
  assert.strictEqual(M.rgbToHex('rgb(0 0 0 / 50%)'), '#00000080');
  assert.strictEqual(M.rgbToHex('#ABC'), '#aabbcc');
  assert.strictEqual(M.rgbToHex('#0F172A'), '#0f172a');
  assert.strictEqual(M.rgbToHex('transparent'), '#00000000');
  assert.strictEqual(M.rgbToHex('rebeccapurple'), null);
  assert.strictEqual(M.rgbToHex('rgb(300, 0, 0)'), null);
  assert.strictEqual(M.rgbToHex(undefined), null);
});

t('contrast: known ratios, translucent text, unreadable input', () => {
  assert.ok(Math.abs(M.contrast('#000000', '#ffffff') - 21) < 1e-9);
  assert.ok(Math.abs(M.contrast('rgb(255, 255, 255)', 'rgb(255, 255, 255)') - 1) < 1e-9);
  assert.ok(M.contrast('#777777', '#ffffff') < 4.5, '#777 on white is 4.48:1');
  assert.ok(M.contrast('#767676', '#ffffff') >= 4.5, '#767676 on white is 4.54:1');
  assert.ok(M.contrast('rgba(0, 0, 0, 0.5)', '#ffffff') < 21 && M.contrast('rgba(0, 0, 0, 0.5)', '#ffffff') > 3, 'translucent ink is laid over the background first');
  assert.strictEqual(M.contrast('nonsense', '#ffffff'), null);
  assert.strictEqual(M.contrast('#000000', 'nonsense'), null);
});

t('large text needs 3:1, normal text 4.5:1', () => {
  assert.strictEqual(M.requiredContrast('24px', '400'), 3);
  assert.strictEqual(M.requiredContrast('23px', '400'), 4.5);
  assert.strictEqual(M.requiredContrast('19px', '700'), 3);
  assert.strictEqual(M.requiredContrast('18px', '700'), 4.5);
  assert.strictEqual(M.requiredContrast('56px', '800'), 3);
});

t('paintedBackground: first opaque colour, translucent layers, the white canvas, an image is unmeasurable', () => {
  const clear = { bg: 'rgba(0, 0, 0, 0)', image: false };
  assert.deepStrictEqual(M.paintedBackground([clear, { bg: 'rgb(15, 23, 42)', image: false }, { bg: 'rgb(255, 0, 0)', image: false }]), { color: '#0f172a', unmeasurable: false });
  assert.deepStrictEqual(M.paintedBackground([clear, clear]), { color: '#ffffff', unmeasurable: false }, 'canvas');
  assert.deepStrictEqual(M.paintedBackground([]), { color: '#ffffff', unmeasurable: false });
  assert.deepStrictEqual(M.paintedBackground([{ bg: 'rgba(0, 0, 0, 0.5)', image: false }, { bg: 'rgb(255, 255, 255)', image: false }]), { color: '#808080', unmeasurable: false }, '50% black over white');
  assert.deepStrictEqual(M.paintedBackground([{ bg: 'rgba(0, 0, 0, 0.5)', image: false }]), { color: '#808080', unmeasurable: false }, 'translucent over the canvas');
  const img = M.paintedBackground([clear, { bg: 'rgba(0, 0, 0, 0)', image: true }, { bg: 'rgb(255, 255, 255)', image: false }]);
  assert.strictEqual(img.unmeasurable, true);
  assert.ok(/image/.test(img.reason));
  assert.strictEqual(M.paintedBackground([{ bg: 'rgb(1, 2, 3)', image: false }, { bg: 'rgba(0,0,0,0)', image: true }]).unmeasurable, false, 'an opaque colour above the image stops the walk');
  assert.strictEqual(M.paintedBackground([{ bg: 'weird', image: false }]).unmeasurable, true);
});

// ---------------------------------------------------------------- fonts and devices
t('firstFamily and familyList unquote and respect commas inside quotes', () => {
  assert.strictEqual(M.firstFamily('"Playfair Display", serif'), 'Playfair Display');
  assert.strictEqual(M.firstFamily("'Brand Sans', Inter, sans-serif"), 'Brand Sans');
  assert.strictEqual(M.firstFamily('Inter'), 'Inter');
  assert.strictEqual(M.firstFamily(''), '');
  assert.strictEqual(M.firstFamily(undefined), '');
  assert.deepStrictEqual(M.familyList('"A, B", Georgia , serif'), ['A, B', 'Georgia', 'serif']);
  assert.ok(M.sameFamily('Inter', 'inter'));
});

t('deviceFor and stepFor follow Divi\'s breakpoints and inheritance', () => {
  assert.deepStrictEqual([1280, 981, 980, 900, 768, 767, 400].map(M.deviceFor), ['desktop', 'desktop', 'tablet', 'tablet', 'tablet', 'phone', 'phone']);
  const s = { desktop: 56, tablet: 42, phone: 31 };
  assert.deepStrictEqual([1280, 900, 400].map((w) => M.stepFor(s, w)), [56, 42, 31]);
  assert.strictEqual(M.stepFor({ desktop: 20 }, 400), 20, 'a missing step inherits the larger one');
  assert.strictEqual(M.stepFor({ desktop: 20, tablet: 18 }, 400), 18);
  assert.strictEqual(M.stepFor({}, 1280), null);
  assert.strictEqual(M.stepFor({ phone: 10 }, 1280), null, 'a phone step alone says nothing about the desktop');
});

// ---------------------------------------------------------------- what the converter wrote (the real goldens)
function goldenBlocks(name) {
  const text = fs.readFileSync(path.join(ROOT, 'tests', 'golden', 'html-to-divi', name + '.layout.txt'), 'utf8');
  const out = [];
  for (const line of text.split('\n')) {
    const m = /^<!-- wp:(divi\/[a-z-]+) (\{.*\}) \/?-->$/.exec(line);
    if (m) out.push({ block: m[1], attrs: JSON.parse(m[2]) });
  }
  return out;
}
const goldenWritten = (name) => goldenBlocks(name).map((b) => ({ block: b.block, w: M.writtenOf(b.block, b.attrs) }));

t('writtenOf reads the inline golden: section paint, heading step sizes, button pair, row max width and padding', () => {
  const w = goldenWritten('14-styled-inline');
  const section = w.find((b) => b.block === 'divi/section').w;
  assert.deepStrictEqual([section.bg, section.padding], ['#0f172a', { top: 96, right: 24, bottom: 96, left: 24 }]);
  const h1 = w.find((b) => b.block === 'divi/heading').w;
  assert.deepStrictEqual(h1, { type: 'heading', color: '#ffffff', weight: '800', sizes: { desktop: 56, tablet: 42, phone: 31 }, align: 'center', level: 'h1' });
  const text = w.find((b) => b.block === 'divi/text').w;
  assert.deepStrictEqual([text.color, text.sizes, text.align], ['#cbd5e1', { desktop: 20 }, 'center']);
  const button = w.find((b) => b.block === 'divi/button').w;
  assert.deepStrictEqual(button, { type: 'button', text: "Shop this week's roast", color: '#111827', bg: '#f59e0b', radius: 999 });
  const rows = w.filter((b) => b.block === 'divi/row').map((b) => b.w);
  assert.strictEqual(rows[1].maxWidth, 720);
  assert.deepStrictEqual(rows[1].padding, { top: 40, right: 40, bottom: 40, left: 40 });
  assert.strictEqual(rows[0].maxWidth, undefined);
});

t('writtenOf reads the fonts, classes, backgrounds and dark goldens', () => {
  const fonts = goldenWritten('14-styled-fonts');
  assert.deepStrictEqual(fonts.filter((b) => b.w.family).map((b) => b.w.family), ['Playfair Display', 'Inter', 'Playfair Display', 'Inter', 'Georgia', 'Inter', 'Inter']);
  const classes = goldenWritten('14-styled-classes');
  const column = classes.find((b) => b.block === 'divi/column' && b.w.bg);
  assert.deepStrictEqual([column.w.bg, column.w.padding.top], ['#ffffff', 24]);
  const h2 = classes.filter((b) => b.block === 'divi/heading').find((b) => b.w.sizes && b.w.sizes.phone);
  assert.deepStrictEqual(h2.w.sizes, { desktop: 36, tablet: 27, phone: 20 });
  const bg = goldenWritten('14-styled-backgrounds').filter((b) => b.block === 'divi/section' && b.w.bg).map((b) => b.w.bg);
  assert.deepStrictEqual(bg, ['#123456']);
  const dark = goldenWritten('14-styled-dark').filter((b) => b.block === 'divi/button').map((b) => [b.w.bg, b.w.radius]);
  assert.deepStrictEqual(dark, [['#38bdf8', 6], ['#a78bfa', 6]]);
});

t('writtenOf says nothing about a block without styles, nor for an image or a divider', () => {
  assert.deepStrictEqual(M.writtenOf('divi/section', { module: { meta: { adminLabel: { desktop: { value: 'x' } } } } }), { type: 'section' });
  assert.deepStrictEqual(M.writtenOf('divi/image', { image: {} }), { type: 'other' });
  assert.deepStrictEqual(M.writtenOf('divi/heading', undefined), { type: 'heading' });
});

// ---------------------------------------------------------------- a consistent synthetic page (every check passes), then one planted failure per code
const BS = {};
const attrs = {
  section: { module: { decoration: { background: { desktop: { value: { color: '#0f172a' } } }, spacing: { desktop: { value: { padding: { top: '96px', right: '24px', bottom: '96px', left: '24px' } } } } } } },
  row: { module: { decoration: { sizing: { desktop: { value: { maxWidth: '720px' } } }, spacing: { desktop: { value: { padding: { top: '40px', right: '40px', bottom: '40px', left: '40px' } } } } } } },
  column: { module: { decoration: { sizing: { desktop: { value: { flexType: '24_24' } } } } } },
  heading: { title: { innerContent: { desktop: { value: 'Small-batch coffee, roasted every Tuesday' } }, decoration: { font: { font: { desktop: { value: { headingLevel: 'h1', color: '#ffffff', size: '56px', weight: '800', textAlign: 'center', family: 'Inter' } }, tablet: { value: { size: '42px' } }, phone: { value: { size: '31px' } } } } } } },
  text: { content: { innerContent: { desktop: { value: '<p>Order by Sunday night and your beans reach your door.</p>' } }, decoration: { bodyFont: { body: { font: { desktop: { value: { color: '#cbd5e1', size: '20px' } } } } } } }, module: { advanced: { text: { text: { desktop: { value: { orientation: 'center' } } } } } } },
  button: { button: { innerContent: { desktop: { value: { text: "Shop this week's roast", linkUrl: '/shop' } } }, decoration: { background: { desktop: { value: { color: '#f59e0b' } } }, border: { desktop: { value: { radius: { topLeft: '999px', topRight: '999px', bottomRight: '999px', bottomLeft: '999px' } } } }, font: { font: { desktop: { value: { color: '#111827' } } } } } } },
};
const SNIP = { heading: 'Small-batch coffee, roa', text: 'Order by Sunday night an', button: "Shop this week's roast" };
const clear = { bg: 'rgba(0, 0, 0, 0)', image: false };
const dark = { bg: 'rgb(15, 23, 42)', image: false };
const white = { bg: 'rgb(255, 255, 255)', image: false };
const px4 = (a, b, c, d) => ({ top: a, right: b, bottom: c, left: d });

function synthetic() {
  const page = {
    name: 'synthetic', plan_ok: true, plan_problem: '',
    blocks: [
      { i: 0, block: 'divi/section', parent: -1, snippet: SNIP.heading, written: JSON.parse(JSON.stringify(attrs.section)), src: '/html/body/section[1]' },
      { i: 1, block: 'divi/row', parent: 0, snippet: SNIP.heading, written: JSON.parse(JSON.stringify(attrs.row)), src: null },
      { i: 2, block: 'divi/column', parent: 1, snippet: SNIP.heading, written: JSON.parse(JSON.stringify(attrs.column)), src: null },
      { i: 3, block: 'divi/heading', parent: 2, snippet: SNIP.heading, written: JSON.parse(JSON.stringify(attrs.heading)), src: '/html/body/section[1]/h1' },
      { i: 4, block: 'divi/text', parent: 2, snippet: SNIP.text, written: JSON.parse(JSON.stringify(attrs.text)), src: '/html/body/section[1]/p' },
      { i: 5, block: 'divi/button', parent: 2, snippet: SNIP.button, written: JSON.parse(JSON.stringify(attrs.button)), src: '/html/body/section[1]/a' },
    ],
  };
  const source = {
    '/html/body/section[1]': { found: true, text: 'Small-batchcoffee,roastedeveryTuesdayOrderbySundaynight', tree: [dark, white], padding: px4('96px', '24px', '96px', '24px') },
    '/html/body/section[1]/h1': { found: true, text: 'Small-batchcoffee,roastedeveryTuesday', color: 'rgb(255, 255, 255)', size: '56px', weight: '800', align: 'center', family: '"Inter", sans-serif' },
    '/html/body/section[1]/p': { found: true, text: 'OrderbySundaynightandyourbeansreachyourdoor.', color: 'rgb(203, 213, 225)', size: '20px', weight: '400', align: 'center', family: 'Inter' },
    '/html/body/section[1]/a': { found: true, text: "Shopthisweek'sroast", color: 'rgb(17, 24, 39)', bg: 'rgb(245, 158, 11)', size: '16px', weight: '400', align: 'center', family: 'Inter' },
  };
  const boxes = () => ({
    section: { bg: 'rgb(15, 23, 42)', padding: px4('96px', '24px', '96px', '24px'), radius: '0px', maxWidth: 'none', boxSizing: 'border-box', width: 1280, gapLeft: 0, gapRight: 0 },
    row: { bg: 'rgba(0, 0, 0, 0)', padding: px4('40px', '40px', '40px', '40px'), radius: '0px', maxWidth: '720px', boxSizing: 'border-box', width: 720, gapLeft: 260, gapRight: 260 },
    column: { bg: 'rgba(0, 0, 0, 0)', padding: px4('0px', '0px', '0px', '0px'), radius: '0px', maxWidth: 'none', boxSizing: 'border-box', width: 720, gapLeft: 0, gapRight: 0 },
  });
  const leafSet = (headingSize) => [
    { kind: 'heading', text: 'Small-batchcoffee,roastedeveryTuesday', label: 'Small-batch coffee, roasted every Tuesday', color: 'rgb(255, 255, 255)', size: headingSize, weight: '800', align: 'center', family: 'Inter, sans-serif', tree: [clear, clear, dark, white], ...boxes() },
    { kind: 'text', text: 'OrderbySundaynightandyourbeansreachyourdoor.', label: 'Order by Sunday night and your beans reach your door.', color: 'rgb(203, 213, 225)', size: '20px', weight: '400', align: 'center', family: 'Inter, sans-serif', tree: [clear, clear, dark, white], ...boxes() },
    { kind: 'button', text: "Shopthisweek'sroast", label: "Shop this week's roast", color: 'rgb(17, 24, 39)', size: '16px', weight: '400', align: 'center', family: 'Inter, sans-serif', bg: 'rgb(245, 158, 11)', radius: '999px', tree: [{ bg: 'rgb(245, 158, 11)', image: false }, clear, dark, white], ...boxes() },
  ];
  return { page, source, leaves: { 1280: leafSet('56px'), 900: leafSet('42px'), 400: leafSet('31px') }, overflow: 400 };
}
const evalS = (s) => M.evaluatePage(s.page, s.source, s.leaves, s.overflow);
const failing = (res, code) => res.checks.filter((c) => c.code === code && !c.ok);
const passing = (res, code) => res.checks.filter((c) => c.code === code && c.ok);

t('the consistent synthetic page passes every code it can judge', () => {
  const res = evalS(synthetic());
  assert.deepStrictEqual(res.checks.filter((c) => !c.ok), []);
  for (const code of ['hs-color', 'hs-weight', 'hs-align', 'hs-size', 'hs-family', 'hs-bg', 'hs-padding', 'hs-max-width', 'hs-radius', 'hs-button', 'hs-contrast', 'hs-overflow']) {
    assert.ok(passing(res, code).length > 0, code + ' was never judged');
  }
  assert.strictEqual(res.measured, 5, 'section, row, heading, text, button; the column has nothing written');
  assert.deepStrictEqual(M.mappingProblems(res.mapping), []);
  assert.deepStrictEqual(M.emptyRunProblems([{ page: 'synthetic', measured: res.measured }]), []);
});

t('hs-color: Divi shows another colour / the source does', () => {
  let s = synthetic(); s.leaves[1280][0].color = 'rgb(0, 0, 0)';
  assert.ok(failing(evalS(s), 'hs-color').some((c) => /Divi shows #000000/.test(c.message)));
  s = synthetic(); s.source['/html/body/section[1]/p'].color = 'rgb(1, 2, 3)';
  assert.ok(failing(evalS(s), 'hs-color').some((c) => /the source shows/.test(c.message)));
});

t('hs-weight: Divi shows another weight / the source does', () => {
  let s = synthetic(); s.leaves[1280][0].weight = '400';
  assert.strictEqual(failing(evalS(s), 'hs-weight').length, 1);
  s = synthetic(); s.source['/html/body/section[1]/h1'].weight = '700';
  assert.strictEqual(failing(evalS(s), 'hs-weight').length, 1);
  s = synthetic(); s.leaves[1280][0].weight = 'bold'; s.page.blocks[3].written.title.decoration.font.font.desktop.value.weight = '700'; s.source['/html/body/section[1]/h1'].weight = '700';
  assert.strictEqual(failing(evalS(s), 'hs-weight').length, 0, '"bold" is 700');
});

t('hs-align: Divi aligns left / the source does', () => {
  let s = synthetic(); s.leaves[1280][1].align = 'left';
  assert.strictEqual(failing(evalS(s), 'hs-align').length, 1);
  s = synthetic(); s.source['/html/body/section[1]/h1'].align = 'start';
  assert.strictEqual(failing(evalS(s), 'hs-align').length, 1);
});

t('hs-size: the tablet step is ignored, the phone step is ignored, the desktop size is wrong, the source differs; a clamped source passes', () => {
  let s = synthetic(); s.leaves[900][0].size = '56px';
  assert.ok(failing(evalS(s), 'hs-size').some((c) => /900 px/.test(c.message)));
  s = synthetic(); s.leaves[400][0].size = '56px';
  assert.ok(failing(evalS(s), 'hs-size').some((c) => /400 px/.test(c.message)));
  s = synthetic(); s.leaves[1280][0].size = '48px';
  assert.ok(failing(evalS(s), 'hs-size').some((c) => /1280 px/.test(c.message)));
  s = synthetic(); s.source['/html/body/section[1]/h1'].size = '40px';
  assert.ok(failing(evalS(s), 'hs-size').some((c) => /the source shows/.test(c.message)));
  // an enormous source size is clamped to 200 by the converter: written 200, source 900
  s = synthetic();
  s.page.blocks[3].written.title.decoration.font.font = { desktop: { value: { headingLevel: 'h2', size: '200px' } } };
  s.source['/html/body/section[1]/h1'].size = '900px';
  for (const w of [1280, 900, 400]) s.leaves[w][0].size = '200px';
  assert.strictEqual(failing(evalS(s), 'hs-size').length, 0);
  // a size inherited from the desktop step when only desktop is written
  s = synthetic(); s.page.blocks[4].written.content.decoration.bodyFont.body.font = { desktop: { value: { size: '20px', color: '#cbd5e1' } } };
  assert.strictEqual(failing(evalS(s), 'hs-size').length, 0);
});

t('hs-family: Divi starts with another family / the source list lacks it', () => {
  let s = synthetic(); s.leaves[1280][0].family = '"Georgia", serif';
  assert.strictEqual(failing(evalS(s), 'hs-family').length, 1);
  s = synthetic(); s.source['/html/body/section[1]/h1'].family = 'Georgia, serif';
  assert.ok(failing(evalS(s), 'hs-family').some((c) => /source family list/.test(c.message)));
});

t('hs-bg: Divi paints another colour / the source does / a body background spreads to the section', () => {
  let s = synthetic(); s.leaves[1280][0].section.bg = 'rgb(0, 0, 0)';
  assert.ok(failing(evalS(s), 'hs-bg').some((c) => /Divi paints/.test(c.message)));
  s = synthetic(); s.source['/html/body/section[1]'].tree = [clear, white];
  assert.ok(failing(evalS(s), 'hs-bg').some((c) => /the source paints/.test(c.message)));
  s = synthetic(); s.source['/html/body/section[1]'].tree = [clear, dark, white]; // the body's paint spreads: the first opaque colour up the tree
  assert.strictEqual(failing(evalS(s), 'hs-bg').length, 0);
  s = synthetic(); s.source['/html/body/section[1]'].tree = [{ bg: 'rgba(0,0,0,0)', image: true }]; // an image in the source: not comparable, not a failure
  assert.strictEqual(failing(evalS(s), 'hs-bg').length, 0);
});

t('hs-padding: Divi pads another amount / the source does / a clamped source passes', () => {
  let s = synthetic(); s.leaves[1280][0].section.padding.top = '10px';
  assert.ok(failing(evalS(s), 'hs-padding').some((c) => /Divi pads/.test(c.message)));
  s = synthetic(); s.leaves[1280][0].row.padding.left = '0px';
  assert.ok(failing(evalS(s), 'hs-padding').some((c) => /row left/.test(c.message)));
  s = synthetic(); s.source['/html/body/section[1]'].padding.bottom = '50px';
  assert.ok(failing(evalS(s), 'hs-padding').some((c) => /the source pads/.test(c.message)));
  s = synthetic(); s.page.blocks[0].written.module.decoration.spacing.desktop.value.padding.top = '200px'; s.leaves[1280][0].section.padding.top = '200px'; s.source['/html/body/section[1]'].padding.top = '400px';
  assert.strictEqual(failing(evalS(s), 'hs-padding').length, 0, 'padding is clamped to 200 px');
});

t('hs-max-width: too wide, not centred, content-box counts the padding', () => {
  let s = synthetic(); s.leaves[1280][0].row.width = 800;
  assert.ok(failing(evalS(s), 'hs-max-width').some((c) => /800px wide/.test(c.message)));
  s = synthetic(); s.leaves[1280][0].row.gapLeft = 100; s.leaves[1280][0].row.gapRight = 420;
  assert.ok(failing(evalS(s), 'hs-max-width').some((c) => /not centred/.test(c.message)));
  s = synthetic(); s.leaves[1280][0].row.gapRight = 260.9; // within 1 px
  assert.strictEqual(failing(evalS(s), 'hs-max-width').length, 0);
  s = synthetic(); s.leaves[1280][0].row.boxSizing = 'content-box'; s.leaves[1280][0].row.width = 800; // 720 + 40 + 40
  assert.strictEqual(failing(evalS(s), 'hs-max-width').length, 0);
  s.leaves[1280][0].row.width = 801.5;
  assert.strictEqual(failing(evalS(s), 'hs-max-width').length, 1);
});

t('hs-radius: the button\'s corner is wrong / a column\'s', () => {
  let s = synthetic(); s.leaves[1280][2].radius = '4px';
  assert.ok(failing(evalS(s), 'hs-radius').some((c) => /button/.test(c.message)));
  s = synthetic(); s.page.blocks[2].written.module.decoration.border = { desktop: { value: { radius: { topLeft: '8px' } } } }; s.leaves[1280][0].column.radius = '0px';
  assert.ok(failing(evalS(s), 'hs-radius').some((c) => /column/.test(c.message)));
});

t('hs-button: wrong label, wrong fill (Divi / source), wrong label colour, unreadable pair', () => {
  let s = synthetic(); s.leaves[1280][2].label = 'Buy now';
  assert.ok(failing(evalS(s), 'hs-button').some((c) => /label/.test(c.message)));
  s = synthetic(); s.leaves[1280][2].bg = 'rgb(0, 0, 0)';
  assert.ok(failing(evalS(s), 'hs-button').some((c) => /Divi fills/.test(c.message)));
  s = synthetic(); s.source['/html/body/section[1]/a'].bg = 'rgb(0, 0, 0)';
  assert.ok(failing(evalS(s), 'hs-button').some((c) => /the source fills/.test(c.message)));
  s = synthetic(); s.leaves[1280][2].color = 'rgb(255, 255, 255)';
  assert.ok(failing(evalS(s), 'hs-button').some((c) => /Divi colours/.test(c.message)));
  s = synthetic(); s.page.blocks[5].written.button.decoration.font.font.desktop.value.color = '#ffffff'; s.leaves[1280][2].color = 'rgb(255, 255, 255)'; s.source['/html/body/section[1]/a'].color = 'rgb(255, 255, 255)';
  assert.ok(failing(evalS(s), 'hs-button').some((c) => /under 4\.5:1/.test(c.message)), 'white on amber is about 2:1');
});

t('hs-contrast: a low pair fails, large text needs 3:1, an unmeasurable background fails only under a written paint', () => {
  let s = synthetic(); s.leaves[1280][1].color = 'rgb(51, 65, 85)'; // slate-700 on slate-900
  assert.ok(failing(evalS(s), 'hs-contrast').some((c) => /under 4\.5:1/.test(c.message)));
  // large text at 3.4:1 passes, the same ratio at 16 px fails
  s = synthetic(); s.leaves[1280][1].color = 'rgb(119, 119, 119)'; s.leaves[1280][1].tree = [white]; s.leaves[1280][1].size = '24px';
  assert.strictEqual(failing(evalS(s), 'hs-contrast').length, 0);
  s.leaves[1280][1].size = '16px';
  assert.strictEqual(failing(evalS(s), 'hs-contrast').length, 1);
  // an image on the path: under the section the converter painted -> FAIL
  s = synthetic(); s.leaves[1280][1].tree = [clear, { bg: 'rgba(0, 0, 0, 0)', image: true }, white];
  assert.ok(failing(evalS(s), 'hs-contrast').some((c) => /cannot be measured/.test(c.message)));
  // the same without any written paint -> not judged, not a failure
  s = synthetic();
  for (const b of s.page.blocks) { if (b.block === 'divi/section') delete b.written.module.decoration.background; }
  s.leaves[1280][1].tree = [clear, { bg: 'rgba(0, 0, 0, 0)', image: true }, white];
  const res = evalS(s);
  assert.strictEqual(failing(res, 'hs-contrast').length, 0);
  assert.strictEqual(res.skippedContrast, 1);
});

t('hs-contrast also judges text no block wrote anything for', () => {
  const s = synthetic();
  s.leaves[1280].push({ kind: 'text', text: 'Anunstyledline', label: 'An unstyled line', color: 'rgb(200, 200, 200)', size: '16px', weight: '400', align: 'left', family: 'x', tree: [clear, white], section: null, row: null, column: null });
  assert.strictEqual(failing(evalS(s), 'hs-contrast').length, 1);
});

t('hs-overflow: a page wider than 400 px fails', () => {
  assert.strictEqual(failing(evalS(Object.assign(synthetic(), { overflow: 420 })), 'hs-overflow').length, 1);
  assert.strictEqual(failing(evalS(Object.assign(synthetic(), { overflow: 400 })), 'hs-overflow').length, 0);
  assert.strictEqual(failing(evalS(Object.assign(synthetic(), { overflow: null })), 'hs-overflow').length, 1, 'not measured is a failure');
});

t('hs-mapping: a missing or wrong source element, a missing Divi module, a module with no source, disagreeing plan and widths all FAIL', () => {
  const good = { page: 'p', block: 3, snippet: 'Hello world', srcPath: '/html/body/h1', sourceFound: true, sourceText: 'Hello  world today', diviFound: true, kind: 'module' };
  assert.deepStrictEqual(M.mappingProblems([good]), []);
  assert.strictEqual(M.mappingProblems([{ ...good, sourceFound: false }]).length, 1);
  assert.ok(/does not start with/.test(M.mappingProblems([{ ...good, sourceText: 'Goodbye' }])[0]));
  assert.ok(/does not start with/.test(M.mappingProblems([{ ...good, sourceText: 'Say Hello world' }])[0]), 'starts with, not contains');
  assert.ok(/no Divi module/.test(M.mappingProblems([{ ...good, diviFound: false }])[0]));
  assert.ok(/no source element/.test(M.mappingProblems([{ ...good, srcPath: null }])[0]));
  assert.deepStrictEqual(M.mappingProblems([{ ...good, kind: 'container', sourceText: 'Say Hello world' }]), [], 'a container only has to contain it');
  // a text module that merged two pieces: the snippet runs past the first piece (table + paragraph)
  const merged = { ...good, snippet: 'Plan Price Basic $9 Cent', sourceText: 'PlanPriceBasic$9', pieces: 2 };
  assert.deepStrictEqual(M.mappingProblems([merged]), []);
  assert.strictEqual(M.mappingProblems([{ ...merged, pieces: 1 }]).length, 1, 'a single-piece module must start with the snippet');
  assert.strictEqual(M.mappingProblems([{ ...merged, sourceText: 'Something else' }]).length, 1, 'a merged module whose first piece is not a prefix still fails');
  assert.strictEqual(M.mappingProblems([{ ...good, kind: 'container', sourceText: 'nothing' }]).length, 1);
  assert.deepStrictEqual(M.mappingProblems([]), []);
  // end to end
  let s = synthetic(); s.source['/html/body/section[1]/p'] = { found: false };
  assert.strictEqual(M.mappingProblems(evalS(s).mapping).length, 1);
  s = synthetic(); s.leaves[1280].splice(1, 1); s.leaves[900].splice(1, 1); s.leaves[400].splice(1, 1);
  assert.ok(M.mappingProblems(evalS(s).mapping).some((m) => /no Divi module/.test(m)));
  s = synthetic(); s.page.plan_ok = false; s.page.plan_problem = 'module 2 is x';
  assert.strictEqual(failing(evalS(s), 'hs-mapping').length, 1);
  s = synthetic(); s.leaves[900].pop();
  assert.ok(failing(evalS(s), 'hs-mapping').some((c) => /at 900 px/.test(c.message)));
  s = synthetic(); s.source['/html/body/section[1]'] = { found: false };
  assert.ok(M.mappingProblems(evalS(s).mapping).some((m) => /not found/.test(m)), 'a section anchor that is not found');
});

t('hs-measured: no page, and a page with zero measured modules, FAIL', () => {
  assert.strictEqual(M.emptyRunProblems([]).length, 1);
  assert.strictEqual(M.emptyRunProblems(undefined).length, 1);
  assert.deepStrictEqual(M.emptyRunProblems([{ page: 'a', measured: 3 }, { page: 'b', measured: 0 }]), ['b: no module was measured']);
  assert.deepStrictEqual(M.emptyRunProblems([{ page: 'a', measured: 1 }]), []);
  const s = synthetic(); s.leaves = { 1280: [], 900: [], 400: [] };
  const res = evalS(s);
  assert.strictEqual(res.measured, 0);
  assert.strictEqual(M.emptyRunProblems([{ page: 'synthetic', measured: res.measured }]).length, 1);
  assert.ok(M.mappingProblems(res.mapping).length >= 1, 'with no Divi module on the page every module is a mapping failure too');
});

t('the code list matches the brief', () => {
  assert.deepStrictEqual(M.CODES, ['hs-color', 'hs-weight', 'hs-align', 'hs-size', 'hs-family', 'hs-bg', 'hs-padding', 'hs-max-width', 'hs-radius', 'hs-button', 'hs-contrast', 'hs-overflow', 'hs-mapping', 'hs-measured']);
});

// ---------------------------------------------------------------- the in-page probes, in a local Chromium (offline), only with --browser
async function browserTests() {
  const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
  const { chromium } = require(PW);
  const browser = await chromium.launch();
  try {
    const sctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, javaScriptEnabled: false });
    await sctx.route('**/*', (r) => r.abort());
    const sp = await sctx.newPage();
    await sp.setContent('<!DOCTYPE html><html><head><style>body{background:#0b1120;color:#e2e8f0;font-family:"Brand Sans",Inter,sans-serif}h1{font-size:2em;font-weight:800;text-align:center}</style></head><body><section style="padding:96px 24px"><h1>Hello  world</h1><p style="color:#abcdef">Text</p></section><table><tr><td>Plan</td></tr></table></body></html>', { waitUntil: 'domcontentloaded' });
    const probes = await sp.evaluate(M.sourceProbe, ['/html/body/section[1]/h1', '/html/body/section[1]', '/html/body/table/tbody/tr/td', '/html/body/nothing']);
    assert.strictEqual(probes[0].found, true);
    assert.deepStrictEqual([probes[0].text, probes[0].size, probes[0].weight, probes[0].align], ['Helloworld', '32px', '800', 'center']);
    assert.deepStrictEqual(M.familyList(probes[0].family).slice(0, 2), ['Brand Sans', 'Inter']);
    assert.strictEqual(M.rgbToHex(probes[0].color), '#e2e8f0');
    assert.strictEqual(probes[1].padding.top, '96px');
    assert.strictEqual(M.paintedBackground(probes[1].tree).color, '#0b1120', 'the body\'s paint is the first opaque colour up the tree');
    assert.strictEqual(probes[2].found, true);
    assert.strictEqual(probes[3].found, false);
    await sctx.close();

    const dctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const dp = await dctx.newPage();
    await dp.setContent('<!DOCTYPE html><html><body><div class="et_pb_section" style="background:#0f172a;padding:10px 20px"><div class="et_pb_row" style="max-width:600px;margin:0 auto"><div class="et_pb_column">'
      + '<div class="et_pb_module et_pb_heading"><div class="et_pb_heading_container"><h1 style="color:#fff">Title</h1></div></div>'
      + '<div class="et_pb_module et_pb_text"><div class="et_pb_text_inner"><p style="color:#ccc">Body text</p></div></div>'
      + '<div class="et_pb_button_module_wrapper"><a class="et_pb_button et_pb_module" style="background:#f59e0b;color:#111827;border-radius:8px">Go</a></div>'
      + '<div class="et_pb_module et_pb_image"><img alt="x" width="10" height="10"></div>'
      + '</div></div></div></body></html>');
    const d = await dp.evaluate(M.diviProbe);
    assert.deepStrictEqual(d.leaves.map((l) => l.kind), ['heading', 'text', 'button']);
    assert.strictEqual(M.rgbToHex(d.leaves[0].color), '#ffffff');
    assert.strictEqual(M.rgbToHex(d.leaves[1].color), '#cccccc');
    assert.deepStrictEqual([d.leaves[2].label, M.rgbToHex(d.leaves[2].bg), d.leaves[2].radius], ['Go', '#f59e0b', '8px']);
    assert.strictEqual(M.rgbToHex(d.leaves[0].section.bg), '#0f172a');
    assert.strictEqual(d.leaves[0].row.width, 600);
    assert.ok(Math.abs(d.leaves[0].row.gapLeft - d.leaves[0].row.gapRight) <= 1, 'a centred row');
    assert.strictEqual(M.paintedBackground(d.leaves[0].tree).color, '#0f172a');
    assert.ok(d.scrollWidth <= 1280);
    await dctx.close();
    n += 2;
  } finally {
    await browser.close();
  }
}

(async () => {
  if (process.argv.includes('--browser')) await browserTests();
  console.log(`test-verify-html-styles: ${n} tests passed`);
})().catch((e) => { console.error('FAIL  browser tests\n' + (e && e.stack ? e.stack : e)); process.exit(1); });
