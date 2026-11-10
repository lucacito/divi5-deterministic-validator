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

// ── The assistant round trip (section I) and the hand-off (section G) ──

const HASH_A = 'a'.repeat(64);
const HASH_B = 'b'.repeat(64);

test('the round-trip brief starts with empty slots and the words the assistant sends are hostile and findable', () => {
  const base = g.roundTripBrief('aied studio t I');
  assert.equal(base.sections.length, 3);
  for (const s of base.sections) assert.deepEqual(s.slots, {}, 'nothing is filled before the assistant writes');
  const { sections, where, number } = g.assistantWords('Tg');
  assert.deepEqual(where.map((w) => w.field), ['heading', 'button label', 'counter title', 'text body']);
  for (const w of where) {
    assert.equal(sections.find((s) => s.section === w.section).slots[w.slot], w.text);
    for (const c of ['"', '\\', '&', '<b>', '$5', '\n']) assert.ok(w.text.includes(c), w.field + ' lacks ' + JSON.stringify(c));
    assert.ok(w.text.includes('Tg'), 'findable on the rendered page');
  }
  assert.equal(sections.find((s) => s.section === 1).slots['number-counter.0.number'], number);
  // The brief the assistant fills must have every slot it writes (recipes of the escaping brief).
  assert.deepEqual(base.sections.map((s) => s.recipe), g.escapingBrief('t', 'T').brief.sections.map((s) => s.recipe));
  assert.equal(g.assistantWords('A').where[0].text === g.assistantWords('B').where[0].text, false);
});

test('the catalog answer is judged by its shape and by a size bound', () => {
  const ok = { goals: ['book', 'sell'], languages: [{ id: 'editorial', name: 'Editorial' }], kits: ['a'], recipes: { hero: ['hero-cta'] }, how: 'x' };
  assert.deepEqual(g.catalogProblems(ok, 4031), []);
  assert.equal(g.CATALOG_MAX_BYTES, 8000);
  assert.ok(g.catalogProblems(ok, 8001).some((p) => /bytes/.test(p)));
  assert.ok(g.catalogProblems({ ...ok, goals: [] }, 100).length >= 1);
  assert.ok(g.catalogProblems({ ...ok, recipes: {} }, 100).length >= 1);
  assert.ok(g.catalogProblems({ ...ok, how: '' }, 100).length >= 1);
  assert.ok(g.catalogProblems({ ...ok, languages: [{ name: 'x' }] }, 100).length >= 1);
  assert.ok(g.catalogProblems(null, 0).length >= 1);
});

test('open: the hash is the stored hash and every slot of every section is listed', () => {
  const open = { brief_id: 7, base_hash: HASH_A, brief: {}, sections: [{ section: 0, recipe: 'hero-cta', slots: [{ id: 'heading.1', kind: 'text', role: 'headline', sample: 's', value: '', filled: false }] }], unfilled_count: 1, page: null, rules: 'r' };
  assert.deepEqual(g.openProblems(open, { briefId: 7, hash: HASH_A, sections: 1 }), []);
  assert.ok(g.openProblems(open, { briefId: 7, hash: HASH_B, sections: 1 }).some((p) => /hash/.test(p)));
  assert.ok(g.openProblems(open, { briefId: 8, hash: HASH_A, sections: 1 }).length >= 1);
  assert.ok(g.openProblems(open, { briefId: 7, hash: HASH_A, sections: 3 }).length >= 1);
  assert.ok(g.openProblems({ ...open, unfilled_count: 0 }, { briefId: 7, hash: HASH_A, sections: 1 }).length >= 1, 'unfilled count must match the empty slots');
  assert.ok(g.openProblems(null, { briefId: 7, hash: HASH_A, sections: 1 }).length >= 1);
  assert.equal(g.slotOf(open, 0, 'heading.1').id, 'heading.1');
  assert.equal(g.slotOf(open, 5, 'heading.1'), null);
  assert.equal(g.slotOf(open, 0, 'nope'), null);
});

test('fill: every word is applied, none ignored, the hash moved, and the stored words equal the sent words exactly', () => {
  const w = g.assistantWords('Fx');
  const sent = w.where.length + 1; // four hostile fields plus the counter number
  const applied = [{ section: 0, slot: 'heading.1' }, { section: 0, slot: 'button.0.text' }, { section: 1, slot: 'number-counter.0.number' }, { section: 1, slot: 'number-counter.0.title' }, { section: 1, slot: 'text.0.body.0' }];
  const answer = { brief_id: 7, base_hash: HASH_B, applied, ignored: [], unfilled_count: 3, report: {}, page: null, message: 'm' };
  assert.deepEqual(g.fillProblems(answer, { sent, oldHash: HASH_A }), []);
  assert.ok(g.fillProblems({ ...answer, base_hash: HASH_A }, { sent, oldHash: HASH_A }).some((p) => /hash/.test(p)));
  assert.ok(g.fillProblems({ ...answer, ignored: [{ section: 0, slot: 'x' }] }, { sent, oldHash: HASH_A }).length >= 1);
  assert.ok(g.fillProblems({ ...answer, applied: applied.slice(1) }, { sent, oldHash: HASH_A }).length >= 1);
  const open = { sections: [
    { section: 0, slots: [{ id: 'heading.1', value: w.where[0].text }, { id: 'button.0.text', value: w.where[1].text }] },
    { section: 1, slots: [{ id: 'number-counter.0.title', value: w.where[2].text }, { id: 'text.0.body.0', value: w.where[3].text }, { id: 'number-counter.0.number', value: w.number }] },
  ] };
  assert.deepEqual(g.storedWordsProblems(open, w), []);
  open.sections[0].slots[0].value = w.where[0].text.replace('&', '&amp;');
  const bad = g.storedWordsProblems(open, w);
  assert.equal(bad.length, 1);
  assert.match(bad[0], /heading/);
});

test('a stale base_hash: tool refusal 409 CONFLICT carrying the current hash, nothing else', () => {
  const stale = { http: 200, error: null, isError: true, result: { code: 'CONFLICT', current_base_hash: HASH_B, message: 'm' } };
  assert.deepEqual(g.staleFillProblems(stale, HASH_B), []);
  assert.ok(g.staleFillProblems({ ...stale, isError: false }, HASH_B).length >= 1);
  assert.ok(g.staleFillProblems(stale, HASH_A).some((p) => /current/.test(p)));
  assert.ok(g.staleFillProblems({ ...stale, result: { error: 'x' } }, HASH_B).length >= 1);
  assert.ok(g.staleFillProblems({ ...stale, error: { code: -32602 } }, HASH_B).length >= 1);
  // The ajax save with a stale hash: HTTP 409, code CONFLICT, the message, and the CURRENT brief and hash.
  const ajax = { status: 409, body: { success: false, data: { code: 'CONFLICT', message: 'The brief was changed while you were editing.', brief: { sections: [] }, hash: HASH_B } } };
  assert.deepEqual(g.staleSaveProblems(ajax, HASH_B), []);
  assert.ok(g.staleSaveProblems({ ...ajax, status: 200 }, HASH_B).length >= 1);
  assert.ok(g.staleSaveProblems({ ...ajax, body: { success: false, data: { ...ajax.body.data, hash: HASH_A } } }, HASH_B).length >= 1);
  assert.ok(g.staleSaveProblems({ ...ajax, body: { success: false, data: { ...ajax.body.data, message: '' } } }, HASH_B).length >= 1);
});

test('a foreign brief id through the tool is refused exactly like a missing one', () => {
  const a = { http: 200, error: null, isError: true, result: { error: 'Brief not found.' } };
  assert.deepEqual(g.toolRefusalProblems(a, a), []);
  assert.ok(g.toolRefusalProblems(a, { ...a, result: { error: 'Brief not found!' } }).length >= 1);
  assert.ok(g.toolRefusalProblems({ ...a, isError: false }, { ...a, isError: false }).length >= 1, 'two successes are not a refusal');
  assert.ok(g.toolRefusalProblems(a, null).length >= 1);
  assert.ok(g.toolRefusalProblems({ ...a, result: { error: 'Brief not found.', page: { id: 3 } } }, { ...a, result: { error: 'Brief not found.', page: { id: 3 } } }).length >= 1, 'a refusal carries nothing but its message');
});

test('the hand-off instruction names lab_build and the brief id, once, as a whole number', () => {
  const text = 'Use the lab_build tool on http://localhost:8181 to open Lab brief #42. It lists the empty slots of every section. The page stays a draft until the site owner publishes it.';
  assert.deepEqual(g.instructionProblems(text, 42), []);
  assert.ok(g.instructionProblems(text, 43).length >= 1);
  assert.ok(g.instructionProblems(text.replace('lab_build', 'studio'), 42).length >= 1);
  assert.ok(g.instructionProblems('', 42).length >= 1);
  assert.ok(g.instructionProblems(text.replace('#42', '#420'), 42).length >= 1, '#420 is not #42');
  assert.ok(g.instructionProblems(undefined, 42).length >= 1);
});

test('the hand-off box of the screen: visible with a ready tool, absent without', () => {
  const ui = { ready: true, present: ['aied-lab-handoff', 'aied-lab-refresh', 'aied-lab-instruction-box', 'aied-lab-handoff-2'], boxHidden: true, textareaReadonly: true };
  assert.deepEqual(g.handoffUiProblems(ui), []);
  assert.deepEqual(g.handoffUiProblems({ ...ui, ready: '1' }), [], 'wp_localize_script writes true as "1"');
  assert.ok(g.handoffUiProblems({ ...ui, ready: false }).length >= 1);
  assert.ok(g.handoffUiProblems({ ...ui, ready: '' }).length >= 1, 'false is written as an empty string');
  assert.ok(g.handoffUiProblems({ ...ui, present: ['aied-lab-handoff'] }).length >= 1);
  assert.ok(g.handoffUiProblems({ ...ui, textareaReadonly: false }).length >= 1);
});

test('the screen fields: an input loses line breaks, a textarea keeps them', () => {
  const where = [{ field: 'heading', text: 'a\nb' }, { field: 'text body', text: 'c\nd' }];
  assert.equal(g.fieldValueFor('a\nb', 'INPUT'), 'ab');
  assert.equal(g.fieldValueFor('c\nd', 'TEXTAREA'), 'c\nd');
  assert.deepEqual(g.fieldProblems(where, [{ tag: 'INPUT', value: 'ab' }, { tag: 'TEXTAREA', value: 'c\nd' }]), []);
  assert.equal(g.fieldProblems(where, [{ tag: 'INPUT', value: 'a&amp;b' }, { tag: 'TEXTAREA', value: 'c\nd' }]).length, 1);
  assert.equal(g.fieldProblems(where, [{ tag: 'INPUT', value: 'ab' }]).length, 1, 'a missing field is a problem');
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
