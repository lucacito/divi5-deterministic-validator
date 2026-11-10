#!/usr/bin/env node
/*
 * Pure tests of scripts/lib/studio-gate.cjs, the expectation builders of the Lab live gate (scripts/verify-studio.cjs).
 * No site, no browser, no dependencies. `make verify-studio` runs these first.
 *
 *   node scripts/test-verify-studio.cjs
 */
'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const g = require(path.join(__dirname, 'lib', 'studio-gate.cjs'));

const tests = [];
const test = (name, fn) => tests.push([name, fn]);

test('the hostile text holds every character Ruling 17 names', () => {
  const s = g.nasty('x1');
  for (const c of ['"', '\\', '&', '<b>', '$5', '\n']) assert.ok(s.includes(c), 'missing ' + JSON.stringify(c));
  assert.ok(s.endsWith('x1'));
  assert.notEqual(g.nasty('a'), g.nasty('b'));
});

test('the escaping brief puts the hostile text in a heading, a button label, a counter title and a text body', () => {
  const { brief, where, number } = g.escapingBrief('aied studio t E', 'T');
  assert.deepEqual(where.map((w) => w.field), ['heading', 'button label', 'counter title', 'text body']);
  for (const w of where) assert.equal(brief.sections[w.section].slots[w.slot], w.text);
  assert.equal(brief.sections[1].slots['number-counter.0.number'], number);
  assert.match(number, /^[0-9][0-9,.]*$/, 'a counter figure must be a plain number (FIGURE_NOT_NUMBER)');
  assert.equal(new Set(where.map((w) => w.text)).size, where.length, 'each field has its own text');
});

test('gate titles carry the prefix and the run token', () => {
  const t = g.titled('123-abc', 'Book');
  assert.equal(t, 'aied studio 123-abc Book');
  assert.ok(g.isGateTitle(t, '123-abc'));
  assert.ok(g.isGateTitle(t, null));
  assert.ok(!g.isGateTitle(t, '999-zzz'));
  assert.ok(!g.isGateTitle('Lab preview (temporary) 1', null));
  assert.ok(!g.isGateTitle(undefined, null));
});

test('literal text: equal once white space is collapsed, and no entity shown', () => {
  const sent = 'Fish & Chips "q" C:\\x <b>b</b> $5\nline two';
  assert.deepEqual(g.literalShown(sent, sent), { ok: true, exact: true, why: '' });
  assert.equal(g.literalShown(sent, 'Fish & Chips "q" C:\\x <b>b</b> $5 line two').ok, true);
  assert.equal(g.literalShown(sent, 'Fish & Chips "q" C:\\x <b>b</b> $5 line two').exact, false);
  assert.equal(g.literalShown(sent, 'Fish &amp; Chips "q" C:\\x <b>b</b> $5 line two').ok, false);
  assert.equal(g.literalShown(sent, 'Fish & Chips "q" C:\\x <b>b</b> &#36;5 line two').ok, false);
  assert.equal(g.literalShown(sent, 'Fish & Chips "q" C:x <b>b</b> $5 line two').ok, false, 'a lost backslash is a failure');
  assert.equal(g.literalShown(sent, 'Fish & Chips "q" C:\\x b $5 line two').ok, false, 'a tag turned into markup is a failure');
  assert.equal(g.literalShown(sent, null).ok, false);
  assert.equal(g.literalShown('a &amp; b', 'a &amp; b').ok, true, 'an entity the person typed is literal');
});

test('rebuild expectations: overwrite only the untouched draft, a new draft with the reason otherwise', () => {
  const ok = (mode, reason, page) => ({ success: true, data: { mode, reason, page_id: page } });
  assert.deepEqual(g.rebuildProblems('untouched', ok('overwritten', null, 5)), []);
  for (const s of ['edited', 'published', 'trashed', 'missing', 'no_access', 'no_undo']) {
    assert.deepEqual(g.rebuildProblems(s, ok('new_draft', s, 9)), [], s);
    assert.notDeepEqual(g.rebuildProblems(s, ok('overwritten', null, 9)), [], s + ' must not overwrite');
  }
  assert.notDeepEqual(g.rebuildProblems('edited', ok('new_draft', 'published', 9)), []);
  assert.notDeepEqual(g.rebuildProblems('edited', ok('new_draft', 'edited', null)), [], 'a new draft needs an id');
  assert.notDeepEqual(g.rebuildProblems('edited', { success: false, data: { code: 'NOT_FOUND' } }), []);
  assert.notDeepEqual(g.rebuildProblems('nope', ok('overwritten', null, 1)), []);
});

test('two refusals are the same only when status and body are equal', () => {
  const a = { status: 404, body: { success: false, data: { code: 'NOT_FOUND', message: 'x' } } };
  assert.ok(g.sameRefusal(a, JSON.parse(JSON.stringify(a))));
  assert.ok(!g.sameRefusal(a, { status: 403, body: a.body }));
  assert.ok(!g.sameRefusal(a, { status: 404, body: { success: false, data: { code: 'NOT_FOUND', message: 'y' } } }));
  const ok = { status: 200, body: { success: true, data: {} } };
  assert.ok(!g.sameRefusal(ok, ok), 'two successes are not a refusal');
});

test('layout: full width and side by side above 1100 px, stacked at or below, never a horizontal overflow', () => {
  const side = { form: { top: 100, bottom: 900 }, preview: { top: 100, bottom: 800 } };
  const stack = { form: { top: 100, bottom: 900 }, preview: { top: 920, bottom: 1500 } };
  assert.deepEqual(g.layoutProblems({ vw: 1600, scrollWidth: 1600, layoutWidth: 1420, bodyWidth: 1420, ...side }), []);
  assert.equal(g.layoutProblems({ vw: 1600, scrollWidth: 1600, layoutWidth: 1120, bodyWidth: 1420, ...side }).length, 1);
  assert.equal(g.layoutProblems({ vw: 1280, scrollWidth: 1280, layoutWidth: 1100, bodyWidth: 1100, ...stack }).length, 1);
  assert.deepEqual(g.layoutProblems({ vw: 900, scrollWidth: 900, layoutWidth: 700, bodyWidth: 720, ...stack }), []);
  assert.deepEqual(g.layoutProblems({ vw: 1100, scrollWidth: 1100, layoutWidth: 1, bodyWidth: 9, ...stack }), []);
  assert.equal(g.layoutProblems({ vw: 400, scrollWidth: 400, layoutWidth: 380, bodyWidth: 380, ...side }).length, 1);
  assert.match(g.layoutProblems({ vw: 400, scrollWidth: 430, layoutWidth: 380, bodyWidth: 380, ...stack })[0], /overflow 30px/);
});

test('frame: the true device width, no overflow, the desktop menu on Desktop and the menu button on Phone', () => {
  assert.deepEqual(g.DEVICE_WIDTH, { desktop: 1280, tablet: 768, phone: 390 });
  assert.deepEqual(g.frameProblems('desktop', { innerWidth: 1280, scrollWidth: 1280, hamburger: false, desktopLinks: 6 }), []);
  assert.equal(g.frameProblems('desktop', { innerWidth: 760, scrollWidth: 760, hamburger: true, desktopLinks: 0 }).length, 3);
  assert.deepEqual(g.frameProblems('tablet', { innerWidth: 768, scrollWidth: 768, hamburger: true, desktopLinks: 0 }), []);
  assert.deepEqual(g.frameProblems('phone', { innerWidth: 390, scrollWidth: 390, hamburger: true, desktopLinks: 0 }), []);
  assert.equal(g.frameProblems('phone', { innerWidth: 390, scrollWidth: 400, hamburger: false, desktopLinks: 0 }).length, 2);
});

test('PHP errors are split into this plugin\'s and the others', () => {
  const lines = [
    JSON.stringify([2, 'Undefined index', '/var/www/html/wp-content/plugins/jhmg-ai-editor-for-divi-5/src/X.php', 3]),
    JSON.stringify([8192, 'Deprecated thing', '/var/www/html/wp-content/themes/Divi/x.php', 9]),
    'garbage',
    '',
  ].join('\n');
  const r = g.phpErrors(lines, '/plugins/jhmg-ai-editor-for-divi-5/');
  assert.equal(r.ours.length, 1);
  assert.equal(r.ours[0].line, 3);
  assert.equal(r.others.length, 2);
  assert.deepEqual(g.phpErrors('', 'x'), { ours: [], others: [] });
  assert.deepEqual(g.printedPhpErrors('<p>ok</p>'), []);
  assert.equal(g.printedPhpErrors('<br />\n<b>Warning</b>:  Undefined variable $x in <b>/a.php</b>').length, 1);
});

test('the screen configuration is read from the admin page', () => {
  const html = '<script>\nvar aiedLabConfig = {"nonce":"abc123","home":"http:\\/\\/localhost:8181\\/","i18n":{"a":"b; c"}};\n</script>';
  assert.equal(g.labConfig(html).nonce, 'abc123');
  assert.equal(g.labConfig(html).i18n.a, 'b; c');
  assert.equal(g.labConfig('<p>no config</p>'), null);
});

test('first difference names the place two contents part', () => {
  assert.equal(g.firstDifference('abc', 'abc'), null);
  const d = g.firstDifference('abcdef', 'abXdef');
  assert.equal(d.at, 2);
  assert.equal(d.lengthA, 6);
  assert.equal(g.firstDifference('ab', 'abc').at, 2);
});

test('the Dashboard row is found by its title and reads "Lab"', () => {
  const row = (title, src) => `<tr>\n<td><strong>${title}</strong></td><td>1 min ago</td><td><code>${src}</code></td></tr>`;
  const html = '<table><tbody>' + row('aied studio 1 &amp; C', 'Lab') + row('Other', 'REST API') + '</tbody></table>';
  assert.ok(g.dashboardShowsLab(html, 'aied studio 1 & C'));
  assert.ok(!g.dashboardShowsLab(html, 'Other'));
  assert.ok(!g.dashboardShowsLab('<table>' + row('X', 'studio') + '</table>', 'X'), 'the raw tool name is not the product name');
});

test('the small brief is three sections with plain values', () => {
  const b = g.smallBrief('aied studio t F', 'luxe', 'H');
  assert.equal(b.language, 'luxe');
  assert.equal(b.sections.length, 3);
  assert.equal(b.sections[0].slots['heading.1'], 'H');
  assert.equal(g.smallBrief('t').language, 'editorial');
});

test('the summary counts SKIP separately and fails only on FAIL', () => {
  assert.deepEqual(g.summarize([{ ok: true }, { ok: true, skip: true }, { ok: false }]), { text: '1 PASS, 1 SKIP, 1 FAIL', failed: 1, skipped: 1 });
  assert.deepEqual(g.summarize([{ ok: true }, { ok: true, skip: true }]), { text: '1 PASS, 1 SKIP, 0 FAIL', failed: 0, skipped: 1 });
  assert.deepEqual(g.summarize([]), { text: '0 PASS, 0 SKIP, 0 FAIL', failed: 0, skipped: 0 });
});

test('the removed-recipe check needs a refused preview to have been seen', () => {
  assert.equal(g.refusedPreviewSeen([]), false, 'an empty list proves nothing');
  assert.equal(g.refusedPreviewSeen(['aied_studio_preview 422']), true);
  assert.equal(g.refusedPreviewSeen(['aied_studio_preview 422', 'aied_studio_save 500']), false, 'any other failure is a failure');
  assert.equal(g.refusedPreviewSeen(['aied_studio_preview 500']), false);
  assert.equal(g.refusedPreviewSeen(undefined), false);
});

(async () => {
  let failed = 0;
  for (const [name, fn] of tests) {
    try {
      await fn();
      process.stdout.write(`ok - ${name}\n`);
    } catch (e) {
      failed++;
      process.stdout.write(`not ok - ${name}\n  ${String(e && e.message).split('\n').join('\n  ')}\n`);
    }
  }
  process.stdout.write(`${tests.length - failed} passed, ${failed} failed\n`);
  process.exit(failed ? 1 : 0);
})();
