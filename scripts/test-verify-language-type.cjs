#!/usr/bin/env node
'use strict';
/* Pure tests of scripts/lib/language-type-checks.cjs (no site, no browser): run titles, language selection, the type sheet, expectations read from
 * RESOLVED markup (the real SectionRecipes::resolveMarkup through scripts/lib/language-type-sheet.php, never a table here), the expected-vs-computed
 * comparison for every code (a match, a mismatch, the tolerance, a missing element, an empty selection and a null expectation), `normal` == `0px`,
 * font-family parsing with quotes and fallbacks, FontFace weight-descriptor ranges and the loaded-face rule, the empty-run guard, and an OFFLINE run
 * of the whole sheet for all ten design languages (zero leftovers, a valid gate, expectations equal to each language's own `type` data: a
 * cross-check, not the source). Run first by `make verify-language-type`. */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const T = require('./lib/language-type-checks.cjs');

const ROOT = path.resolve(__dirname, '..');
let n = 0;
const t = (name, fn) => { fn(); n++; };
const clone = (o) => JSON.parse(JSON.stringify(o));
const languageData = JSON.parse(fs.readFileSync(path.join(ROOT, 'wp-plugin', 'data', 'design-languages.json'), 'utf8'));
const languageIds = languageData.map((l) => l.id);

// ---- run titles ----
t('run title round trip', () => {
  const title = T.runTitle('123-abcdef', 'glass-dark');
  assert.strictEqual(title, 'aied type 123-abcdef glass-dark');
  assert.deepStrictEqual(T.parseRunTitle(title), { token: '123-abcdef', language: 'glass-dark' });
  assert.ok(title.startsWith(T.TITLE_PREFIX));
  assert.strictEqual(T.parseRunTitle('aied kit 123-abcdef neo-brutal glass-dark'), null, 'a kit page is not a type page');
  assert.strictEqual(T.parseRunTitle('aied recipe 123-abcdef hero-cta editorial'), null);
  assert.strictEqual(T.parseRunTitle('aied type 123-ABCDEF luxe'), null, 'token is lower-case hex');
  assert.strictEqual(T.parseRunTitle('aied type 123-abcdef'), null, 'no language');
  assert.strictEqual(T.parseRunTitle('My page'), null);
  assert.ok(!'aied kit '.startsWith(T.TITLE_PREFIX) && !T.TITLE_PREFIX.startsWith('aied kit '), 'the prefix never collides with the kit gate (--clean-orphans of one must not delete the other)');
});

// ---- selection ----
t('selection: all ten by default, the named ones in order, never a duplicate', () => {
  assert.deepStrictEqual(T.selectLanguages(null, languageIds), languageIds);
  assert.strictEqual(languageIds.length, 10);
  assert.deepStrictEqual(T.selectLanguages(['luxe', 'editorial'], languageIds), ['luxe', 'editorial']);
  assert.deepStrictEqual(T.selectLanguages(['luxe', 'luxe'], languageIds), ['luxe']);
});
t('selection: an unknown language is a usage error, an empty selection is a failure (never zero pages)', () => {
  assert.throws(() => T.selectLanguages(['nope'], languageIds), (e) => /Unknown language\(s\): nope/.test(e.message) && !e.empty);
  assert.throws(() => T.selectLanguages(null, []), (e) => e.empty === true && /No language selected/.test(e.message));
  assert.throws(() => T.selectLanguages([], []), (e) => e.empty === true);
});

// ---- the sheet ----
t('the sheet has the five slots of the plan, each found by a unique text', () => {
  assert.deepStrictEqual(T.TYPE_SLOTS.map((s) => s.key), ['display-h1', 'display-h2', 'title-h3', 'eyebrow-h4', 'eyebrow-text']);
  assert.deepStrictEqual(T.TYPE_SLOTS.map((s) => s.role), ['display', 'display', 'title', 'eyebrow', 'eyebrow']);
  assert.deepStrictEqual(T.TYPE_SLOTS.map((s) => s.module), ['heading', 'heading', 'heading', 'heading', 'text']);
  assert.deepStrictEqual(T.TYPE_SLOTS.map((s) => s.tag), ['h1', 'h2', 'h3', 'h4', 'p']);
  assert.strictEqual(new Set(T.TYPE_SLOTS.map((s) => s.text)).size, 5, 'unique texts');
  assert.strictEqual(T.TYPE_SLOTS[0].text, 'Craftsmanship, delivered', 'a long word, so uppercase widening shows at 400 px');
  assert.deepStrictEqual(T.TYPE_SLOTS[0].sizes, { desktop: '104px', tablet: '68px', phone: '40px' });
  assert.deepStrictEqual(T.TYPE_SLOTS[1].sizes, { desktop: '44px', tablet: '36px', phone: '30px' });
  assert.strictEqual(T.TYPE_SLOTS[2].sizes.desktop, '22px');
  assert.strictEqual(T.TYPE_SLOTS[3].sizes.desktop, '13px');
  assert.strictEqual(T.TYPE_SLOTS[4].sizes.desktop, '12px');
  assert.strictEqual(T.TYPE_SLOTS[0].lineHeight, '1em');
  assert.deepStrictEqual(T.TYPE_SLOTS.map((s) => s.fontRole), ['display', 'display', 'display', 'eyebrow', 'eyebrow'], 'display and title in the display font, eyebrows in the eyebrow font');
});
t('the sheet carries one type marker per slot, font tokens, and no weight, spacing or capitals of its own', () => {
  const m = T.sheetMarkup();
  const flat = [];
  const walk = (nodes) => { for (const nd of nodes) { flat.push(nd); walk(nd.children); } };
  walk(require('./lib/kit-checks.cjs').blockTree(m));
  const marked = flat.filter((nd) => nd.attrs.aied);
  assert.strictEqual(marked.length, 5);
  marked.forEach((nd, i) => assert.deepStrictEqual(nd.attrs.aied, { type: T.TYPE_SLOTS[i].role }, 'the marker is exactly {"type":"<role>"}, one key'));
  assert.strictEqual(flat.filter((nd) => nd.name === 'divi/section').length, 1, 'ONE plain section');
  assert.ok(!/"weight"|"letterSpacing"|"capitalization"/.test(m), 'the language decides weight, tracking and capitals: the sheet writes none');
  assert.strictEqual((m.match(/\{\{aied:font:display\}\}/g) || []).length, 3);
  assert.strictEqual((m.match(/\{\{aied:font:eyebrow\}\}/g) || []).length, 2);
  assert.ok(m.includes('{{aied:color:bg}}'), 'the section is on the bg band');
  const group = flat.find((nd) => nd.name === 'divi/group');
  assert.ok(group && !group.attrs.aied && group.children.length === 1 && group.children[0].attrs.aied.type === 'title', 'the title sits inside an unmarked padded group like a card');
  assert.ok(group.attrs.module.decoration.spacing.desktop.value.padding.top, 'the group is padded');
  const sizes = (nd) => Object.fromEntries(['desktop', 'tablet', 'phone'].map((bp) => [bp, T.TYPE_SLOTS && nd.attrs.title.decoration.font.font[bp] && nd.attrs.title.decoration.font.font[bp].value.size]).filter(([, v]) => v));
  assert.deepStrictEqual(sizes(marked[0]), { desktop: '104px', tablet: '68px', phone: '40px' });
  assert.deepStrictEqual(sizes(marked[1]), { desktop: '44px', tablet: '36px', phone: '30px' });
});
t('every slot is found again in the markup, and a block with a wrong text or heading level is not', () => {
  const found = T.pageTypeAttrs(T.sheetMarkup());
  assert.strictEqual(found.length, 5);
  assert.ok(found.every((f) => f.attrs), 'all five found');
  const noH2 = T.sheetMarkup().replace('"headingLevel":"h2"', '"headingLevel":"h3"');
  assert.strictEqual(T.pageTypeAttrs(noH2)[1].attrs, null, 'a heading of another level is not the slot');
  const noText = T.sheetMarkup().replace('Category label', 'Something else');
  assert.strictEqual(T.pageTypeAttrs(noText)[4].attrs, null, 'a text with another content is not the slot');
  assert.strictEqual(T.pageTypeAttrs('').every((f) => f.attrs === null), true);
});

// ---- family parsing ----
t('family parsing: quotes, fallbacks and bare names', () => {
  assert.strictEqual(T.firstFamily('"Playfair Display", Georgia, serif'), 'Playfair Display');
  assert.strictEqual(T.firstFamily("'Source Sans 3', sans-serif"), 'Source Sans 3');
  assert.strictEqual(T.firstFamily('Inter'), 'Inter');
  assert.strictEqual(T.firstFamily('  Space Mono  '), 'Space Mono');
  assert.strictEqual(T.firstFamily('"Name, with comma", serif'), 'Name, with comma', 'a comma inside quotes does not end the family');
  assert.strictEqual(T.firstFamily('sans-serif'), 'sans-serif');
  assert.strictEqual(T.firstFamily(''), '');
  assert.strictEqual(T.firstFamily(null), '');
  assert.ok(T.sameFamily('"Fredoka", sans-serif', 'fredoka'));
  assert.ok(!T.sameFamily('Fredoka', 'Fredoka One'));
});

// ---- tracking ----
t('`normal` letter spacing is 0px; a length is its number; anything else is NaN', () => {
  assert.strictEqual(T.trackingPx('normal'), 0);
  assert.strictEqual(T.trackingPx('0px'), 0);
  assert.strictEqual(T.trackingPx('-1px'), -1);
  assert.strictEqual(T.trackingPx('2.5px'), 2.5);
  assert.strictEqual(T.trackingPx(' normal '), 0);
  assert.ok(Number.isNaN(T.trackingPx('0.1em')));
  assert.ok(Number.isNaN(T.trackingPx('')));
  assert.ok(Number.isNaN(T.trackingPx(undefined)));
});

// ---- weight descriptors ----
t('FontFace weight descriptors: a range, a single weight, normal, bold', () => {
  assert.deepStrictEqual(T.weightRange('100 900'), [100, 900]);
  assert.deepStrictEqual(T.weightRange('600'), [600, 600]);
  assert.deepStrictEqual(T.weightRange('normal'), [400, 400]);
  assert.deepStrictEqual(T.weightRange('bold'), [700, 700]);
  assert.deepStrictEqual(T.weightRange(' Normal '), [400, 400]);
  assert.deepStrictEqual(T.weightRange('300 700'), [300, 700]);
  assert.strictEqual(T.weightRange('900 100'), null, 'a reversed range is unreadable');
  assert.strictEqual(T.weightRange('lighter'), null);
  assert.strictEqual(T.weightRange(''), null);
  assert.strictEqual(T.weightRange(undefined), null);
});
const face = (family, weight, status = 'loaded', style = 'normal') => ({ family, weight, style, status });
t('type-font-loaded: a loaded face whose range contains the weight passes; every other case fails with that code', () => {
  assert.deepStrictEqual(T.fontLoadedProblems('Inter', 600, [face('Inter', '100 900')]), []);
  assert.deepStrictEqual(T.fontLoadedProblems('Inter', 100, [face('Inter', '100 900')]), [], 'the edges are inside');
  assert.deepStrictEqual(T.fontLoadedProblems('Inter', 900, [face('Inter', '100 900')]), []);
  assert.deepStrictEqual(T.fontLoadedProblems('Archivo', 900, [face('Archivo', '900')]), []);
  assert.deepStrictEqual(T.fontLoadedProblems('Jost', 400, [face('Jost', 'normal')]), []);
  assert.deepStrictEqual(T.fontLoadedProblems('Jost', 700, [face('Jost', 'bold')]), []);
  assert.deepStrictEqual(T.fontLoadedProblems('Playfair Display', 600, [face('"Playfair Display"', '400 900')]), [], 'quotes in the family name are stripped');
  assert.deepStrictEqual(T.fontLoadedProblems('Inter', 600, [face('Other', '100 900'), face('Inter', '100 900')]), [], 'another family beside it does not matter');
  const none = T.fontLoadedProblems('Inter', 600, []);
  assert.strictEqual(none.length, 1);
  assert.strictEqual(none[0][0], 'type-font-loaded');
  assert.match(none[0][1], /family "Inter" at weight 600 has no loaded face \(no face of that family in document\.fonts\)/);
  const loading = T.fontLoadedProblems('Inter', 600, [face('Inter', '100 900', 'loading')]);
  assert.strictEqual(loading[0][0], 'type-font-loaded');
  assert.match(loading[0][1], /100 900 loading/);
  assert.strictEqual(T.fontLoadedProblems('Inter', 600, [face('Inter', '100 900', 'error')])[0][0], 'type-font-loaded');
  assert.strictEqual(T.fontLoadedProblems('Inter', 600, [face('Inter', 'unloaded')])[0][0], 'type-font-loaded', 'an unreadable descriptor never matches');
  assert.strictEqual(T.fontLoadedProblems('Inter', 600, [face('Inter', '400')])[0][0], 'type-font-loaded', 'a loaded face of another weight');
  assert.strictEqual(T.fontLoadedProblems('Inter', 600, [face('Inter', '100 500')])[0][0], 'type-font-loaded', 'a range that stops short');
  assert.strictEqual(T.fontLoadedProblems('Inter', 600, [face('Intern', '100 900')])[0][0], 'type-font-loaded', 'another family with a similar name');
  assert.match(T.fontLoadedProblems('Inter', 600, [], 'display-h1')[0][1], /^display-h1 family/);
});

// ---- expectations from resolved markup ----
const slotOf = (key) => T.TYPE_SLOTS.find((s) => s.key === key);
const heading = (value) => ({ title: { decoration: { font: { font: { desktop: { value } } } } } });
const text = (value) => ({ content: { decoration: { bodyFont: { body: { font: { desktop: { value } } } } } } });
t('expectations: weight, tracking, capitals and family come from the resolved desktop font value', () => {
  assert.deepStrictEqual(T.expectTypeFromAttrs(heading({ family: 'Archivo', weight: '900', letterSpacing: '-1px', capitalization: 'uppercase', size: '104px' }), slotOf('display-h1')),
    { weight: 900, tracking: -1, caps: 'uppercase', family: 'Archivo' });
  assert.deepStrictEqual(T.expectTypeFromAttrs(heading({ family: 'Inter', weight: '700', letterSpacing: '0px' }), slotOf('display-h1')),
    { weight: 700, tracking: 0, caps: 'none', family: 'Inter' }, 'no capitalization key = none (Divi has no `none` value)');
  assert.deepStrictEqual(T.expectTypeFromAttrs(text({ family: 'Space Mono', weight: '700', letterSpacing: '2px', capitalization: 'uppercase' }), slotOf('eyebrow-text')),
    { weight: 700, tracking: 2, caps: 'uppercase', family: 'Space Mono' }, 'the text module reads its own path');
  assert.deepStrictEqual(T.expectTypeFromAttrs(heading({ family: '"Fredoka", sans-serif', weight: '600', letterSpacing: '1.5px' }), slotOf('title-h3')).family, 'Fredoka');
});
t('a hole in the resolved markup is a null expectation, never a default', () => {
  const full = { family: 'Inter', weight: '600', letterSpacing: '1px', capitalization: 'uppercase' };
  const hole = (drop) => { const v = clone(full); drop(v); return T.expectTypeFromAttrs(heading(v), slotOf('title-h3')); };
  assert.strictEqual(hole((v) => { delete v.weight; }).weight, null);
  assert.strictEqual(hole((v) => { v.weight = 'bold'; }).weight, null, 'a keyword is not a resolved weight');
  assert.strictEqual(hole((v) => { v.weight = '650'; }).weight, null);
  assert.strictEqual(hole((v) => { delete v.letterSpacing; }).tracking, null, 'no spacing key is NOT zero: the marker did not resolve');
  assert.strictEqual(hole((v) => { v.letterSpacing = '0.1em'; }).tracking, null);
  assert.strictEqual(hole((v) => { v.capitalization = 'capitalize'; }).caps, null, 'only uppercase or no key are roles of the adapter');
  assert.strictEqual(hole((v) => { delete v.family; }).family, null);
  assert.strictEqual(hole((v) => { v.family = ''; }).family, null);
  assert.deepStrictEqual(T.expectTypeFromAttrs({}, slotOf('title-h3')), { weight: null, tracking: null, caps: null, family: null });
  assert.deepStrictEqual(T.expectTypeFromAttrs(heading({ family: 'Inter' }), slotOf('eyebrow-text')), { weight: null, tracking: null, caps: null, family: null }, 'a heading path is not read for a text slot');
  assert.deepStrictEqual(T.missingTypeExpectations(T.expectTypeFromAttrs({}, slotOf('title-h3'))), ['weight', 'tracking', 'caps', 'family']);
  assert.deepStrictEqual(T.missingTypeExpectations(null), ['weight', 'tracking', 'caps', 'family']);
  assert.deepStrictEqual(T.missingTypeExpectations({ weight: 600, tracking: 0, caps: 'none', family: 'Inter' }), []);
  assert.deepStrictEqual(T.missingTypeExpectations({ weight: NaN, tracking: 0, caps: 'none', family: 'Inter' }), ['weight']);
});

// ---- comparison ----
const EXP = { weight: 600, tracking: 2, caps: 'uppercase', family: 'Source Sans 3' };
const measured = (o = {}) => Object.assign({
  el: { fontWeight: '600', letterSpacing: '2px', textTransform: 'uppercase', fontFamily: '"Source Sans 3", sans-serif', fontSize: '13px' },
  scrollWidth: 300, clientWidth: 300, rect: { left: 40, right: 340, width: 300 }, parentRect: { left: 40, right: 360 },
}, o);
const FACES = [face('Source Sans 3', '200 900')];
const cmp = (exp, m, faces = FACES, inner = 1280) => T.compareType(exp, m, faces, inner, 'eyebrow-h4');
const codes = (r) => r.map(([c]) => c);
t('compare: an agreeing slot has no problem', () => {
  assert.deepStrictEqual(cmp(EXP, measured()), []);
});
t('type-weight: a mismatch is caught, an equal weight in any string form is not', () => {
  assert.deepStrictEqual(codes(cmp(EXP, measured({ el: Object.assign(measured().el, { fontWeight: '500' }) }))), ['type-weight']);
  assert.match(cmp(EXP, measured({ el: Object.assign(measured().el, { fontWeight: '500' }) }))[0][1], /^eyebrow-h4 font-weight 500, expected 600/);
  assert.deepStrictEqual(codes(cmp(EXP, measured({ el: Object.assign(measured().el, { fontWeight: 'bold' }) }))), ['type-weight'], 'an unparsed keyword never equals');
});
t('type-tracking: 0.05px tolerance, `normal` equals 0px, a wrong value and an unreadable one are caught', () => {
  const tr = (v, exp = EXP) => cmp(exp, measured({ el: Object.assign(measured().el, { letterSpacing: v }) }));
  assert.deepStrictEqual(tr('2.04px'), [], 'inside the tolerance');
  assert.deepStrictEqual(tr('1.96px'), []);
  assert.deepStrictEqual(codes(tr('2.06px')), ['type-tracking'], 'just outside');
  assert.deepStrictEqual(codes(tr('1.9px')), ['type-tracking']);
  assert.deepStrictEqual(codes(tr('0px')), ['type-tracking']);
  assert.match(tr('0px')[0][1], /^eyebrow-h4 letter-spacing 0px, expected 2px/);
  assert.deepStrictEqual(tr('normal', Object.assign({}, EXP, { tracking: 0 })), [], '`normal` == 0px');
  assert.deepStrictEqual(tr('0px', Object.assign({}, EXP, { tracking: 0 })), []);
  assert.deepStrictEqual(codes(tr('normal')), ['type-tracking'], '`normal` is not 2px');
  assert.deepStrictEqual(codes(tr('0.1em')), ['type-tracking'], 'an unreadable value fails, never passes');
  assert.deepStrictEqual(tr('-1px', Object.assign({}, EXP, { tracking: -1 })), []);
});
t('type-caps: a missing uppercase and an extra uppercase are both caught', () => {
  const caps = (v, exp = EXP) => cmp(exp, measured({ el: Object.assign(measured().el, { textTransform: v }) }));
  assert.deepStrictEqual(codes(caps('none')), ['type-caps']);
  assert.match(caps('none')[0][1], /^eyebrow-h4 text-transform none, expected uppercase/);
  assert.deepStrictEqual(codes(caps('uppercase', Object.assign({}, EXP, { caps: 'none' }))), ['type-caps'], 'a `none` role measured as uppercase');
  assert.deepStrictEqual(caps('none', Object.assign({}, EXP, { caps: 'none' })), [], 'a `none` role is measured as text-transform none (Divi default)');
  assert.deepStrictEqual(codes(caps('capitalize', Object.assign({}, EXP, { caps: 'none' }))), ['type-caps']);
});
t('type-family: the first family decides, quotes and fallbacks are parsed, case is ignored', () => {
  const fam = (v) => cmp(EXP, measured({ el: Object.assign(measured().el, { fontFamily: v }) }));
  assert.deepStrictEqual(fam('"Source Sans 3", Arial'), []);
  assert.deepStrictEqual(fam("'source sans 3'"), []);
  assert.deepStrictEqual(codes(fam('Arial, "Source Sans 3"')), ['type-family'], 'the expected family only as a fallback is wrong');
  assert.match(fam('Arial, "Source Sans 3"')[0][1], /^eyebrow-h4 font-family Arial, expected Source Sans 3/);
  assert.deepStrictEqual(codes(fam('')), ['type-family']);
  assert.deepStrictEqual(codes(fam('"Source Sans"')), ['type-family']);
});
t('type-font-loaded through compareType: a missing, unloaded or wrong-weight face fails with that code', () => {
  assert.deepStrictEqual(codes(cmp(EXP, measured(), [])), ['type-font-loaded']);
  assert.deepStrictEqual(codes(cmp(EXP, measured(), [face('Source Sans 3', '200 900', 'unloaded')])), ['type-font-loaded']);
  assert.deepStrictEqual(codes(cmp(EXP, measured(), [face('Source Sans 3', '200 500')])), ['type-font-loaded']);
  assert.deepStrictEqual(cmp(EXP, measured(), [face('Source Sans 3', '600')]), []);
});
t('type-overflow: wider than its box, past the window, past its column; the borders are tolerated by 1px', () => {
  assert.deepStrictEqual(cmp(EXP, measured({ scrollWidth: 301 })), [], '1px of rounding is tolerated');
  assert.deepStrictEqual(codes(cmp(EXP, measured({ scrollWidth: 302 }))), ['type-overflow']);
  assert.match(cmp(EXP, measured({ scrollWidth: 420 }))[0][1], /^eyebrow-h4 scrollWidth 420 > clientWidth 300/);
  assert.deepStrictEqual(codes(cmp(EXP, measured({ rect: { left: 40, right: 1300, width: 1260 }, parentRect: { left: 40, right: 1400 } }))), ['type-overflow'], 'past the window');
  assert.deepStrictEqual(codes(cmp(EXP, measured({ rect: { left: 40, right: 362, width: 322 } }))), ['type-overflow'], 'past its column');
  assert.deepStrictEqual(cmp(EXP, measured({ rect: { left: 40, right: 361, width: 321 } })), [], 'within 1px of the column');
  assert.deepStrictEqual(codes(cmp(EXP, measured({ rect: { left: -20, right: 280, width: 300 }, parentRect: { left: -20, right: 360 } }))), ['type-overflow'], 'left of the window');
  assert.deepStrictEqual(cmp(EXP, measured({ rect: { left: 40, right: 340, width: 300 }, parentRect: null })), [], 'a missing column box is not a problem by itself');
});
t('type-expectation: a null expectation answers only that code and never a comparison (fail closed, never skip)', () => {
  for (const f of ['weight', 'tracking', 'caps', 'family']) {
    const exp = Object.assign({}, EXP, { [f]: null });
    const r = cmp(exp, measured());
    assert.deepStrictEqual(codes(r), ['type-expectation'], f);
    assert.match(r[0][1], new RegExp(`^eyebrow-h4 the resolved markup has no ${f}`));
    // Even against a page that would have disagreed with everything.
    assert.deepStrictEqual(codes(cmp(exp, measured({ el: { fontWeight: '100', letterSpacing: '9px', textTransform: 'none', fontFamily: 'Arial' } }), [])), ['type-expectation']);
  }
  assert.deepStrictEqual(codes(cmp(null, measured())), ['type-expectation']);
  assert.deepStrictEqual(codes(cmp({ weight: NaN, tracking: 0, caps: 'none', family: 'Inter' }, measured())), ['type-expectation']);
});
t('several departures are all reported, each with its own code', () => {
  const r = cmp(EXP, measured({ el: { fontWeight: '400', letterSpacing: 'normal', textTransform: 'none', fontFamily: 'Arial', fontSize: '13px' } }), []);
  assert.deepStrictEqual(codes(r), ['type-weight', 'type-tracking', 'type-caps', 'type-family', 'type-font-loaded']);
});
t('overflow: the page wider than the window', () => {
  assert.deepStrictEqual(T.pageOverflowProblems({ scrollWidth: 400, innerWidth: 400 }), []);
  assert.deepStrictEqual(T.pageOverflowProblems({ scrollWidth: 401, innerWidth: 400 }), [['overflow', 'scrollWidth 401 > innerWidth 400']]);
});

// ---- the count and the empty run ----
const found = (key, count = 1) => ({ key, found: true, count });
t('type: every slot exactly once; a missing one, a doubled one and an empty slot list are caught', () => {
  const all = T.TYPE_SLOTS.map((s) => found(s.key));
  assert.deepStrictEqual(T.typeCountProblems(all), []);
  const miss = all.slice(); miss[2] = { key: 'title-h3', found: false, count: 0 };
  const r = T.typeCountProblems(miss);
  assert.deepStrictEqual(codes(r), ['type']);
  assert.match(r[0][1], /^4 of 5 type slots rendered as the sheet says: title-h3 is not in the page/);
  const twice = all.slice(); twice[0] = found('display-h1', 2);
  assert.match(T.typeCountProblems(twice)[0][1], /display-h1 is on the page 2 times, expected 1/);
  assert.deepStrictEqual(codes(T.typeCountProblems([])), ['type'], 'nothing measured is a failure');
  assert.deepStrictEqual(codes(T.typeCountProblems(all.concat([found('extra')]))), ['type']);
  assert.throws(() => T.typeCountProblems(all, []), /no type slots/);
});
t('emptyTypeRunProblems: a run that checked nothing fails', () => {
  assert.deepStrictEqual(T.emptyTypeRunProblems({ checked: 30, typeChecked: 150 }), []);
  assert.strictEqual(T.emptyTypeRunProblems({ checked: 0, typeChecked: 0 }).length, 2);
  assert.match(T.emptyTypeRunProblems({ checked: 30, typeChecked: 0 })[0], /no type slot was checked/);
  assert.match(T.emptyTypeRunProblems({ checked: 0, typeChecked: 5 })[0], /no language page was checked/);
  assert.strictEqual(T.emptyTypeRunProblems({ checked: 3, typeChecked: 0 }).length, 1);
});

// ---- the offline run of the whole sheet through the REAL PHP pipeline ----
const offline = (args = []) => {
  const r = spawnSync('php', [path.join(__dirname, 'lib', 'language-type-sheet.php'), ...args], { input: T.sheetMarkup(), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  assert.strictEqual(r.status, 0, 'php failed: ' + (r.error ? r.error.message : r.stderr));
  return JSON.parse(r.stdout);
};
const rows = offline();
t('offline: the sheet resolves for all ten languages with zero leftovers and a valid gate', () => {
  assert.deepStrictEqual(rows.map((r) => r.language), languageIds);
  assert.strictEqual(rows.length, 10);
  for (const r of rows) {
    assert.deepStrictEqual(r.leftovers, [], r.language + ' leftovers');
    assert.strictEqual(r.tokenLeft, false, r.language + ' token left');
    assert.strictEqual(r.valid, true, r.language + ' gate: ' + r.violations.join());
    assert.ok(r.markup.length > 0 && !r.markup.includes('"aied"'), r.language + ': no marker reaches the markup');
    assert.strictEqual(r.kit, languageData.find((l) => l.id === r.language).kit, 'the language\'s own kit');
  }
});
t('offline: every expectation read from the resolved markup is complete and equals the language\'s own type data (a cross-check)', () => {
  for (const r of rows) {
    const lang = languageData.find((l) => l.id === r.language);
    const blocks = T.pageTypeAttrs(r.markup);
    blocks.forEach((b, i) => {
      assert.ok(b.attrs, `${r.language} ${b.slot.key} found in the resolved markup`);
      const e = T.expectTypeFromAttrs(b.attrs, b.slot);
      assert.deepStrictEqual(T.missingTypeExpectations(e), [], `${r.language} ${b.slot.key}`);
      const d = lang.type[b.slot.role];
      assert.strictEqual(e.weight, parseInt(d.weight, 10), `${r.language} ${b.slot.key} weight`);
      assert.strictEqual(e.tracking, parseFloat(d.letterSpacing), `${r.language} ${b.slot.key} tracking`);
      assert.strictEqual(e.caps, d.capitalization === 'uppercase' ? 'uppercase' : 'none', `${r.language} ${b.slot.key} caps`);
      assert.strictEqual(e.family, lang.fonts[b.slot.fontRole], `${r.language} ${b.slot.key} family`);
      // A language's own expectation agrees with itself through the comparison, and disagrees with a different language's (no two are the same triple).
      assert.deepStrictEqual(T.compareType(e, { el: { fontWeight: String(e.weight), letterSpacing: e.tracking + 'px', textTransform: e.caps, fontFamily: `"${e.family}", serif`, fontSize: '20px' }, scrollWidth: 100, clientWidth: 100, rect: { left: 0, right: 100, width: 100 }, parentRect: { left: 0, right: 200 } }, [face(e.family, '100 900')], 1280, b.slot.key), []);
    });
  }
});
t('offline: the languages differ on the sheet (the gate could tell a wrong language from a right one)', () => {
  const triples = rows.map((r) => T.pageTypeAttrs(r.markup).map((b) => { const e = T.expectTypeFromAttrs(b.attrs, b.slot); return `${e.weight}/${e.tracking}/${e.caps}/${e.family}`; }).join('|'));
  assert.strictEqual(new Set(triples).size, 10);
});
t('offline: every family the gate checks is a web font of Divi\'s Google list, so type-font-loaded has a face to find', () => {
  const names = new Set(fs.readFileSync(path.join(ROOT, 'wp-plugin', 'data', 'google-fonts-names.txt'), 'utf8').split('\n').map((s) => s.trim()).filter(Boolean));
  for (const l of languageData) for (const role of ['display', 'eyebrow']) assert.ok(names.has(l.fonts[role]), `${l.id} ${role} font ${l.fonts[role]} is not in the Google list`);
});
t('offline: one language can be asked for; an unknown one and a marker the pipeline cannot resolve come back as refusals, never as markup', () => {
  const one = offline(['--language', 'luxe']);
  assert.deepStrictEqual(one.map((r) => r.language), ['luxe']);
  const unknown = offline(['--language', 'nope'])[0];
  assert.strictEqual(unknown.valid, false);
  assert.strictEqual(unknown.markup, '');
  assert.ok(unknown.leftovers.length > 0);
  const bad = spawnSync('php', [path.join(__dirname, 'lib', 'language-type-sheet.php'), '--language', 'luxe'], { input: T.sheetMarkup().replace('"aied":{"type":"title"}', '"aied":{"type":"bogus"}'), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  assert.strictEqual(bad.status, 0, bad.stderr);
  const row = JSON.parse(bad.stdout)[0];
  assert.strictEqual(row.valid, false, 'an unresolved marker is never a valid sheet');
  assert.strictEqual(row.markup, '', 'and never handed out');
  assert.ok(row.leftovers.length > 0, 'and reported: ' + JSON.stringify(row));
  const empty = spawnSync('php', [path.join(__dirname, 'lib', 'language-type-sheet.php')], { input: '', encoding: 'utf8' });
  assert.strictEqual(empty.status, 2, 'no markup on stdin is a usage error');
});

// ---- the Makefile ----
t('the Makefile target runs the pure tests, the synthetic page tests and then the live gate', () => {
  const mk = fs.readFileSync(path.join(ROOT, 'Makefile'), 'utf8');
  assert.match(mk.split('\n')[0], /\.PHONY:.* verify-language-type\b/);
  const target = /^verify-language-type:\n((?:\t.*\n)+)/m.exec(mk);
  assert.ok(target, 'the target exists');
  assert.deepStrictEqual(target[1].trim().split('\n').map((l) => l.trim()), ['@node scripts/test-verify-language-type.cjs', '@node scripts/test-language-type-run.cjs', '@node scripts/verify-language-type.cjs $(ARGS)']);
});
t('the live script keeps its guards: dev site only, run-prefixed deletion, orphans, no typed expectation', () => {
  const src = fs.readFileSync(path.join(__dirname, 'verify-language-type.cjs'), 'utf8');
  assert.ok(src.includes('devSiteOnly(SITE)'));
  assert.ok(src.includes("throwawayPages(wp, T.TITLE_PREFIX, 'type')"));
  assert.ok(src.includes("'--clean-orphans'") && src.includes('removeOrphans'));
  assert.ok(src.includes('SectionRecipes::resolveMarkup'), 'the page is built by the real pipeline');
  assert.ok(src.includes('emptyTypeRunProblems'), 'a run that checked nothing fails');
  assert.ok(src.includes('missingTypeExpectations'), 'a hole in an expectation stops the language before any browser work');
  assert.ok(!/weight['"]?\s*:\s*['"]?[1-9]00/.test(src), 'no weight typed in the gate');
});

console.log(`ok ${n} tests`);
