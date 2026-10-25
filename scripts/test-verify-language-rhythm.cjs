#!/usr/bin/env node
'use strict';
/* Pure tests of scripts/lib/language-rhythm-checks.cjs (no site, no browser): the rhythm slots and their markup, the breakpoint of a width, expectations
 * read from RESOLVED markup (the real SectionRecipes::resolveMarkup through scripts/lib/language-type-sheet.php, never a table here), the
 * expected-vs-computed comparison for every code (a match, a mismatch, the tolerance, a missing element, a twice-found slot, a null expectation), the
 * order check on equal values and on a tablet-only inversion, the empty-run guard, and an OFFLINE run of the type sheet plus the rhythm sheet for all ten
 * design languages (zero leftovers, a valid gate, expectations equal to DesignLanguages::sectionSpace(), a cross-check through a tiny `php -r`, not
 * the source). Run by `make verify-language-type` after the type adapter's two test files. */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const R = require('./lib/language-rhythm-checks.cjs');
const T = require('./lib/language-type-checks.cjs');
const K = require('./lib/kit-checks.cjs');

const ROOT = path.resolve(__dirname, '..');
let n = 0;
const t = (name, fn) => { fn(); n++; };
const languageData = JSON.parse(fs.readFileSync(path.join(ROOT, 'wp-plugin', 'data', 'design-languages.json'), 'utf8'));
const languageIds = languageData.map((l) => l.id);

// ---- the sheet ----
t('the four rhythm slots, in document order, each found by a unique text', () => {
  assert.deepStrictEqual(R.RHYTHM_SLOTS.map((s) => s.key), ['rhythm-section', 'rhythm-open', 'rhythm-tight', 'rhythm-image']);
  assert.deepStrictEqual(R.RHYTHM_SLOTS.map((s) => s.role), ['section', 'open', 'tight', 'open']);
  assert.deepStrictEqual(R.RHYTHM_SLOTS.map((s) => s.band), ['{{aied:color:surface}}', '{{aied:color:bg}}', '{{aied:color:surface}}', '{{aied:color:bg}}']);
  assert.deepStrictEqual(R.RHYTHM_SLOTS.map((s) => s.text), ['Rhythm band, section', 'Rhythm band, open', 'Rhythm band, tight', 'Rhythm band over a photo']);
  assert.strictEqual(new Set(R.RHYTHM_SLOTS.map((s) => s.text)).size, 4);
  assert.deepStrictEqual(R.RHYTHM_SLOTS.map((s) => s.image), [null, null, null, '{{aied:image:hero-blue-1}}']);
  const typeTexts = T.TYPE_SLOTS.map((s) => s.text);
  for (const s of R.RHYTHM_SLOTS) assert.ok(!typeTexts.includes(s.text), 'no text shared with the type slots: ' + s.text);
});
t('each rhythm section has the background, NO padding of its own, one row, one column, one text, and the marker as its LAST key', () => {
  const m = R.rhythmSheetMarkup();
  const top = K.blockTree(m);
  assert.strictEqual(top.length, 4);
  top.forEach((sec, i) => {
    const slot = R.RHYTHM_SLOTS[i];
    assert.strictEqual(sec.name, 'divi/section');
    assert.deepStrictEqual(sec.attrs.aied, { rhythm: slot.role }, 'exactly {"rhythm":"<role>"}, one key');
    assert.strictEqual(Object.keys(sec.attrs).pop(), 'aied', 'the marker is the last key');
    assert.strictEqual(sec.attrs.module.decoration.spacing, undefined, 'no padding of its own: the language decides');
    const bg = sec.attrs.module.decoration.background.desktop.value;
    assert.strictEqual(bg.color, slot.band);
    assert.strictEqual(bg.image ? bg.image.url : null, slot.image);
    assert.deepStrictEqual(sec.children.map((c) => c.name), ['divi/row']);
    assert.deepStrictEqual(sec.children[0].children.map((c) => c.name), ['divi/column']);
    assert.deepStrictEqual(sec.children[0].children[0].children.map((c) => c.name), ['divi/text']);
    assert.strictEqual(sec.children[0].children[0].children[0].attrs.aied, undefined, 'only the section carries a marker');
  });
  assert.ok(!/"padding"|"syncVertical"/.test(m));
  assert.strictEqual((m.match(/aied:image:/g) || []).length, 1, 'one photo band');
});
t('the rhythm slots come after the type section: the gate page is the type sheet and then the rhythm sections', () => {
  const page = K.blockTree(T.sheetMarkup() + '\n' + R.rhythmSheetMarkup());
  assert.deepStrictEqual(page.map((s) => s.name), Array(5).fill('divi/section'));
  assert.ok(!page[0].attrs.aied, 'the type section is not a rhythm section');
  assert.deepStrictEqual(page.slice(1).map((s) => s.attrs.aied.rhythm), ['section', 'open', 'tight', 'open']);
});
t('every rhythm slot is found again in the markup, and a section with another text is not the slot', () => {
  const found = R.pageRhythmAttrs(R.rhythmSheetMarkup());
  assert.strictEqual(found.length, 4);
  assert.ok(found.every((f) => f.attrs));
  assert.strictEqual(found[1].attrs.aied.rhythm, 'open');
  const gone = R.rhythmSheetMarkup().replace('Rhythm band, tight', 'Something else');
  assert.strictEqual(R.pageRhythmAttrs(gone)[2].attrs, null);
  assert.ok(R.pageRhythmAttrs('').every((f) => f.attrs === null));
});

// ---- breakpoints ----
t('bpOf: 1280 desktop, 900 tablet, 400 phone, and Divi\'s own limits', () => {
  assert.strictEqual(R.bpOf(1280), 'desktop');
  assert.strictEqual(R.bpOf(900), 'tablet');
  assert.strictEqual(R.bpOf(400), 'phone');
  assert.strictEqual(R.bpOf(981), 'desktop');
  assert.strictEqual(R.bpOf(980), 'tablet');
  assert.strictEqual(R.bpOf(768), 'tablet');
  assert.strictEqual(R.bpOf(767), 'phone');
  assert.deepStrictEqual(T.WIDTHS.map(R.bpOf), ['desktop', 'tablet', 'phone'], 'the gate\'s widths');
  assert.throws(() => R.bpOf(0));
  assert.throws(() => R.bpOf('wide'));
});

// ---- expectations ----
const full = (d, t2, p) => ({ desktop: { top: d, bottom: d }, tablet: { top: t2, bottom: t2 }, phone: { top: p, bottom: p } });
const sectionAttrs = (spacing) => ({ module: { decoration: { spacing } } });
const pad = (top, bottom) => ({ value: { padding: { top, bottom, syncVertical: 'on' } } });
t('expectations: px top and bottom at the three breakpoints, read from the block', () => {
  const e = R.expectRhythmFromAttrs(sectionAttrs({ desktop: pad('120px', '120px'), tablet: pad('80px', '80px'), phone: pad('56px', '56px') }));
  assert.deepStrictEqual(e, full(120, 80, 56));
  assert.deepStrictEqual(R.missingRhythmExpectations(e), []);
  const odd = R.expectRhythmFromAttrs(sectionAttrs({ desktop: pad('96.5px', '100px'), tablet: pad('80px', '80px'), phone: pad('56px', '56px') }));
  assert.deepStrictEqual(odd.desktop, { top: 96.5, bottom: 100 });
});
t('expectations: a breakpoint without a value is a hole, never inherited; so is anything that is not Npx', () => {
  const noPhone = R.expectRhythmFromAttrs(sectionAttrs({ desktop: pad('120px', '120px'), tablet: pad('80px', '80px') }));
  assert.deepStrictEqual(R.missingRhythmExpectations(noPhone), ['phone.top', 'phone.bottom']);
  const bad = R.expectRhythmFromAttrs(sectionAttrs({ desktop: pad('8%', '120px'), tablet: pad('8vw', '80px'), phone: pad('', '56px') }));
  assert.deepStrictEqual(R.missingRhythmExpectations(bad), ['desktop.top', 'tablet.top', 'phone.top']);
  const none = R.expectRhythmFromAttrs({});
  assert.strictEqual(R.missingRhythmExpectations(none).length, 6);
  assert.strictEqual(R.missingRhythmExpectations(null).length, 6);
  assert.strictEqual(R.missingRhythmExpectations(undefined).length, 6);
  assert.deepStrictEqual(R.missingRhythmExpectations({ desktop: { top: NaN, bottom: 1 }, tablet: { top: 1, bottom: 1 }, phone: { top: 1, bottom: 1 } }), ['desktop.top']);
  assert.strictEqual(R.pxOf('0px'), 0);
  assert.strictEqual(R.pxOf('-4px'), null);
  assert.strictEqual(R.pxOf('120'), null);
  assert.strictEqual(R.pxOf(120), null);
});

// ---- rhythm-padding ----
const got = (top, bottom, bi = 'none') => ({ el: { paddingTop: top, paddingBottom: bottom, backgroundImage: bi } });
t('rhythm-padding: a match passes at every breakpoint, a mismatch fails per side with the slot key', () => {
  const e = full(120, 80, 56);
  assert.deepStrictEqual(R.comparePadding(e, got('120px', '120px'), 'desktop', 'rhythm-section'), []);
  assert.deepStrictEqual(R.comparePadding(e, got('80px', '80px'), 'tablet', 'rhythm-section'), []);
  assert.deepStrictEqual(R.comparePadding(e, got('56px', '56px'), 'phone', 'rhythm-section'), []);
  assert.deepStrictEqual(R.comparePadding(e, got('96px', '120px'), 'desktop', 'rhythm-section'), [['rhythm-padding', 'rhythm-section padding-top 96px at desktop, expected 120px']]);
  assert.deepStrictEqual(R.comparePadding(e, got('80px', '64px'), 'tablet', 'rhythm-open'), [['rhythm-padding', 'rhythm-open padding-bottom 64px at tablet, expected 80px']]);
  assert.strictEqual(R.comparePadding(e, got('120px', '120px'), 'phone', 'k').length, 2, 'the desktop value leaking to the phone fails both sides');
  assert.strictEqual(R.comparePadding(e, got('0px', '0px'), 'desktop', 'k').length, 2);
});
t('rhythm-padding: 0.5 px tolerance, no more', () => {
  const e = full(120, 80, 56);
  assert.deepStrictEqual(R.comparePadding(e, got('120.5px', '119.5px'), 'desktop', 'k'), []);
  assert.deepStrictEqual(R.comparePadding(e, got('120.4px', '119.6px'), 'desktop', 'k'), []);
  assert.strictEqual(R.comparePadding(e, got('120.6px', '120px'), 'desktop', 'k').length, 1);
  assert.strictEqual(R.comparePadding(e, got('120px', '119.4px'), 'desktop', 'k').length, 1);
});
t('rhythm-padding: an unreadable computed value fails, it is never skipped', () => {
  const e = full(120, 80, 56);
  for (const bad of ['', 'auto', 'normal', undefined, null, '8%']) {
    assert.strictEqual(R.comparePadding(e, got(bad, '120px'), 'desktop', 'k').length, 1, String(bad));
  }
  assert.ok(Number.isNaN(R.lengthPx('auto')));
  assert.strictEqual(R.lengthPx('56px'), 56);
  assert.strictEqual(R.lengthPx('56'), 56);
});
t('rhythm-expectation: a hole answers ONLY that code and compares nothing, even against a page that agrees', () => {
  const holey = full(120, 80, 56);
  holey.tablet.bottom = null;
  const r = R.comparePadding(holey, got('120px', '120px'), 'desktop', 'rhythm-open');
  assert.strictEqual(r.length, 1);
  assert.strictEqual(r[0][0], 'rhythm-expectation');
  assert.match(r[0][1], /^rhythm-open the resolved markup has no px tablet\.bottom/);
  assert.strictEqual(R.comparePadding(null, got('1px', '1px'), 'desktop', 'k')[0][0], 'rhythm-expectation');
  assert.strictEqual(R.comparePadding({}, got('1px', '1px'), 'desktop', 'k')[0][0], 'rhythm-expectation');
});

// ---- rhythm-order (measured) ----
const px = (o, s, t2) => ({ open: { top: o, bottom: o }, section: { top: s, bottom: s }, tight: { top: t2, bottom: t2 } });
t('rhythm-order (measured): open > section > tight passes; equal values fail; either side can fail', () => {
  assert.deepStrictEqual(R.measuredOrderProblems(px(152, 120, 72), 'desktop'), []);
  assert.deepStrictEqual(R.measuredOrderProblems(px(72, 56, 32), 'phone'), []);
  const eqOpen = R.measuredOrderProblems(px(120, 120, 72), 'desktop');
  assert.deepStrictEqual(eqOpen.map(([c]) => c), ['rhythm-order', 'rhythm-order'], 'open equal to section fails on top and on bottom');
  assert.match(eqOpen[0][1], /^padding-top at desktop: open 120px, section 120px, tight 72px must be open > section > tight \(measured\)/);
  assert.strictEqual(R.measuredOrderProblems(px(152, 72, 72), 'desktop').length, 2, 'section equal to tight fails');
  assert.strictEqual(R.measuredOrderProblems(px(56, 56, 56), 'phone').length, 2, 'all equal fails');
  assert.strictEqual(R.measuredOrderProblems(px(100, 120, 72), 'desktop').length, 2, 'open below section fails');
  const mixed = px(152, 120, 72);
  mixed.section.bottom = 160;
  const one = R.measuredOrderProblems(mixed, 'desktop');
  assert.strictEqual(one.length, 1);
  assert.match(one[0][1], /^padding-bottom/);
  assert.strictEqual(R.measuredOrderProblems(px(NaN, 120, 72), 'desktop').length, 2, 'a value that is not a number fails');
});
t('rhythm-order (measured): a tablet-only inversion fails at the tablet width and passes at the others', () => {
  assert.deepStrictEqual(R.measuredOrderProblems(px(152, 120, 72), 'desktop'), []);
  assert.deepStrictEqual(R.measuredOrderProblems(px(72, 80, 48), 'tablet').map(([c]) => c), ['rhythm-order', 'rhythm-order']);
  assert.deepStrictEqual(R.measuredOrderProblems(px(72, 56, 32), 'phone'), []);
});
t('rhythm-order (expectations): the same order read from the resolved markup, one problem per breakpoint and side', () => {
  const ok = { section: full(120, 80, 56), open: full(152, 104, 72), tight: full(72, 48, 32) };
  assert.deepStrictEqual(R.expectedOrderProblems(ok), []);
  const tabletInverted = { section: full(120, 80, 56), open: full(152, 72, 72), tight: full(72, 48, 32) };
  const p = R.expectedOrderProblems(tabletInverted);
  assert.deepStrictEqual(p.map(([c]) => c), ['rhythm-order', 'rhythm-order']);
  assert.match(p[0][1], /^expected padding-top at tablet: open 72px, section 80px/);
  assert.strictEqual(R.expectedOrderProblems({ section: full(120, 80, 56), open: full(120, 80, 56), tight: full(72, 48, 32) }).length, 6, 'open equal to section at every breakpoint and side: six cells fail');
});

// ---- rhythm-image ----
t('rhythm-image: a background-image passes, none or empty fails', () => {
  assert.deepStrictEqual(R.imageProblems(got('1px', '1px', 'url("https://x.test/a.svg")'), 'rhythm-image'), []);
  assert.deepStrictEqual(R.imageProblems(got('1px', '1px', 'none'), 'rhythm-image'), [['rhythm-image', 'rhythm-image computed background-image is none: the section lost its picture']]);
  assert.strictEqual(R.imageProblems(got('1px', '1px', ''), 'k').length, 1);
  assert.strictEqual(R.imageProblems(got('1px', '1px', undefined), 'k').length, 1);
  assert.strictEqual(R.imageProblems(got('1px', '1px', null), 'k').length, 1);
});

// ---- overflow ----
t('overflow: the page wider than the window fails with that code', () => {
  assert.deepStrictEqual(R.pageOverflowProblems({ scrollWidth: 400, innerWidth: 400 }), []);
  assert.deepStrictEqual(R.pageOverflowProblems({ scrollWidth: 401, innerWidth: 400 }), [['overflow', 'scrollWidth 401 > innerWidth 400']]);
});

// ---- rhythm (the count) ----
const seen = (...counts) => counts.map((c, i) => (c === 0 ? { key: R.RHYTHM_SLOTS[i].key, found: false, count: 0 } : { key: R.RHYTHM_SLOTS[i].key, found: true, count: c }));
t('rhythm: every slot exactly once; a missing or twice-found slot fails with that code and says which', () => {
  assert.deepStrictEqual(R.rhythmCountProblems(seen(1, 1, 1, 1)), []);
  const miss = R.rhythmCountProblems(seen(1, 0, 1, 1));
  assert.deepStrictEqual(miss.map(([c]) => c), ['rhythm']);
  assert.match(miss[0][1], /^3 of 4 rhythm slots rendered as the sheet says: rhythm-open is not in the page/);
  const twice = R.rhythmCountProblems(seen(1, 1, 2, 1));
  assert.match(twice[0][1], /rhythm-tight is on the page 2 times, expected 1/);
  assert.match(R.rhythmCountProblems(seen(0, 0, 0, 0))[0][1], /^0 of 4 rhythm slots/);
  assert.match(R.rhythmCountProblems(seen(1, 1, 1))[0][1], /rhythm-image is not in the page/, 'a short measurement fails');
  assert.match(R.rhythmCountProblems([...seen(1, 1, 1, 1), { found: true, count: 1 }])[0][1], /5 slots measured for 4 slots/);
  assert.throws(() => R.rhythmCountProblems([], []), /nothing would be checked/);
});

// ---- the empty-run guard ----
t('a run that checked no rhythm slot is a failure; one that checked some is not', () => {
  assert.deepStrictEqual(R.emptyRhythmRunProblems({ rhythmChecked: 0 }), ['no rhythm slot was checked: nothing was measured']);
  assert.deepStrictEqual(R.emptyRhythmRunProblems({ rhythmChecked: undefined }).length, 1);
  assert.deepStrictEqual(R.emptyRhythmRunProblems({ rhythmChecked: 4 }), []);
});

// ---- the offline run of the whole page (type sheet + rhythm sheet) through the REAL PHP pipeline ----
const sheet = () => T.sheetMarkup() + '\n' + R.rhythmSheetMarkup();
const offline = (input, args = []) => {
  const r = spawnSync('php', [path.join(__dirname, 'lib', 'language-type-sheet.php'), ...args], { input, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  assert.strictEqual(r.status, 0, 'php failed: ' + (r.error ? r.error.message : r.stderr));
  return JSON.parse(r.stdout);
};
const rows = offline(sheet());
t('offline: the type sheet plus the rhythm sections resolve for all ten languages with zero leftovers and a valid gate', () => {
  assert.deepStrictEqual(rows.map((r) => r.language), languageIds);
  for (const r of rows) {
    assert.deepStrictEqual(r.leftovers, [], r.language + ' leftovers');
    assert.strictEqual(r.tokenLeft, false, r.language + ' token left (the photo token resolves to a site-local URL)');
    assert.strictEqual(r.valid, true, r.language + ' gate: ' + r.violations.join());
    assert.ok(r.markup.length > 0 && !r.markup.includes('"aied"'), r.language + ': no marker reaches the markup');
    assert.ok(r.markup.includes('assets/images/hero-blue-1.svg'), r.language + ': the photo band has its picture');
  }
});
// DesignLanguages::sectionSpace() for every language and role, asked of PHP directly (a cross-check, not the source of the expectations).
const spaceOf = (() => {
  const code = `require "${ROOT}/tests/bootstrap.php"; require_once "${ROOT}/wp-plugin/src/DesignLanguages.php"; $o = []; foreach ( AiEditorDivi5\\WP\\DesignLanguages::ids() as $id ) { $l = AiEditorDivi5\\WP\\DesignLanguages::get( $id ); foreach ( [ "section", "open", "tight" ] as $r ) { $o[ $id ][ $r ] = AiEditorDivi5\\WP\\DesignLanguages::sectionSpace( $l, $r ); } } echo json_encode( $o );`;
  const r = spawnSync('php', ['-r', code], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
  assert.strictEqual(r.status, 0, 'php -r failed: ' + r.stderr);
  return JSON.parse(r.stdout);
})();
t('offline: every expectation read from the resolved markup is complete and equals DesignLanguages::sectionSpace() for the slot\'s role (a cross-check)', () => {
  for (const r of rows) {
    const blocks = R.pageRhythmAttrs(r.markup);
    blocks.forEach((b) => {
      assert.ok(b.attrs, `${r.language} ${b.slot.key} found in the resolved markup`);
      const e = R.expectRhythmFromAttrs(b.attrs);
      assert.deepStrictEqual(R.missingRhythmExpectations(e), [], `${r.language} ${b.slot.key}`);
      const want = spaceOf[r.language][b.slot.role];
      assert.ok(want, `${r.language} ${b.slot.role} has a space`);
      for (const bp of R.BREAKPOINTS) {
        assert.strictEqual(e[bp].top, want[bp], `${r.language} ${b.slot.key} ${bp} top`);
        assert.strictEqual(e[bp].bottom, want[bp], `${r.language} ${b.slot.key} ${bp} bottom`);
      }
    });
  }
});
t('offline: every language keeps the strict order open > section > tight on its own resolved expectations (the pure cross-check passes)', () => {
  for (const r of rows) {
    const byRole = {};
    R.pageRhythmAttrs(r.markup).forEach((b) => { if (!b.slot.image) byRole[b.slot.role] = R.expectRhythmFromAttrs(b.attrs); });
    assert.deepStrictEqual(R.expectedOrderProblems(byRole), [], r.language);
  }
});
t('offline: the languages differ on the rhythm sections (the gate could tell a wrong language from a right one)', () => {
  const triples = rows.map((r) => JSON.stringify(R.expectRhythmFromAttrs(R.pageRhythmAttrs(r.markup)[0].attrs)));
  assert.strictEqual(new Set(triples).size, 10);
});
t('offline: a rhythm marker the pipeline cannot resolve comes back as a refusal, never as markup', () => {
  const bad = offline(sheet().replace('"aied":{"rhythm":"tight"}', '"aied":{"rhythm":"bogus"}'), ['--language', 'luxe'])[0];
  assert.strictEqual(bad.valid, false);
  assert.strictEqual(bad.markup, '');
  assert.ok(bad.leftovers.length > 0, JSON.stringify(bad));
});

// ---- the Makefile and the live script ----
t('the Makefile target runs the four test files and then the live gate', () => {
  const mk = fs.readFileSync(path.join(ROOT, 'Makefile'), 'utf8');
  assert.match(mk.split('\n')[0], /\.PHONY:.* verify-language-type\b/);
  const target = /^verify-language-type:\n((?:\t.*\n)+)/m.exec(mk);
  assert.ok(target, 'the target exists');
  assert.deepStrictEqual(target[1].trim().split('\n').map((l) => l.trim()), [
    '@node scripts/test-verify-language-type.cjs', '@node scripts/test-language-type-run.cjs',
    '@node scripts/test-verify-language-rhythm.cjs', '@node scripts/test-language-rhythm-run.cjs',
    '@node scripts/verify-language-type.cjs $(ARGS)',
  ]);
});
t('the live script measures the rhythm too and keeps its guards', () => {
  const src = fs.readFileSync(path.join(__dirname, 'verify-language-type.cjs'), 'utf8');
  assert.ok(src.includes("require('./lib/language-rhythm-checks.cjs')") && src.includes("require('./lib/language-rhythm-run.cjs')"));
  assert.ok(src.includes('T.sheetMarkup()') && src.includes('R.rhythmSheetMarkup()'), 'one page: the type sheet and then the rhythm sections');
  assert.ok(src.includes('SectionRecipes::resolveMarkup'), 'the page is built by the real pipeline');
  assert.ok(src.includes('ImageTokens::resolve'), 'the photo token is resolved as the plugin serves a recipe');
  assert.ok(src.includes('checkRhythm('), 'the page is measured for rhythm');
  assert.ok(src.includes('emptyRhythmRunProblems'), 'a run that checked no rhythm slot fails');
  assert.ok(src.includes('missingRhythmExpectations'), 'a hole in a rhythm expectation stops the language before any browser work');
  assert.ok(src.includes('rhythm-observations.json'));
  assert.ok(!/(top|bottom|padding[A-Za-z]*)['"]\s*:\s*['"]\d+px/.test(src), 'no padding value typed in the gate (the sheet\'s own CSS is not one)');
  for (const code of ['rhythm', 'rhythm-expectation', 'rhythm-padding', 'rhythm-order', 'rhythm-image', 'overflow']) assert.ok(new RegExp('^ \\*   ' + code + '\\s', 'm').test(src), 'the header comment lists ' + code);
});

console.log(`ok ${n} tests`);
