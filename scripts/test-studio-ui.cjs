#!/usr/bin/env node
/*
 * Pure tests of wp-plugin/assets/studio-core.js (the Divi 5 Lab screen's logic without the DOM). No dependencies.
 *
 *   node scripts/test-studio-ui.cjs                 run the tests (exit 1 on a failure)
 *   node scripts/test-studio-ui.cjs --swap <file>   read {catalog, pairs:[[from,to,{slots,pictures}]]} and print the
 *                                                    swap result of each pair as JSON (tests/StudioScreenTest compares
 *                                                    it with StudioMerge::swapRecipe on the real library)
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const core = require(path.join(__dirname, '..', 'wp-plugin', 'assets', 'studio-core.js'));

if (process.argv[2] === '--swap') {
    const input = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
    const out = input.pairs.map(([from, to, values]) => {
        const section = core.newSection(from);
        section.slots = values.slots || {};
        section.pictures = values.pictures || {};
        const r = core.swap(section, to, input.catalog);
        return { kept: r.kept.slice().sort(), dropped: r.dropped.slice().sort(), slots: r.section.slots, pictures: r.section.pictures };
    });
    process.stdout.write(JSON.stringify(out));
    process.exit(0);
}

// A small catalog shaped like the real one (StudioScreen::config()['recipes']).
const catalog = {
    'hero-a': { role: 'hero', layout: 'centered', items: 0, slots: [
        { id: 'section.0.background', role: 'picture', kind: 'picture', sample: '' },
        { id: 'heading.0', role: 'eyebrow', kind: 'text', sample: 'Your Business Name' },
        { id: 'heading.1', role: 'headline', kind: 'text', sample: 'Build Something' },
        { id: 'text.0.body.0', role: 'subhead', kind: 'text', sample: 'A line' },
        { id: 'button.0.text', role: 'button_label', kind: 'text', sample: 'Learn More' },
        { id: 'button.0.link', role: 'link', kind: 'link', sample: '' },
    ] },
    'hero-b': { role: 'hero', layout: 'split', items: 0, slots: [
        { id: 'heading.0', role: 'headline', kind: 'text', sample: 'Big words' },
        { id: 'text.0.body.0', role: 'body', kind: 'text', sample: 'Body' },
        { id: 'button.0.text', role: 'button_label', kind: 'text', sample: 'Go' },
    ] },
    'cards-3': { role: 'features', layout: 'grid', items: 3, slots: [
        { id: 'heading.0', role: 'headline', kind: 'text', sample: 'Features' },
        { id: 'heading.1', role: 'item_title', kind: 'text', sample: 'One' },
        { id: 'heading.2', role: 'item_title', kind: 'text', sample: 'Two' },
        { id: 'heading.3', role: 'item_title', kind: 'text', sample: 'Three' },
    ] },
    'cards-2': { role: 'features', layout: 'split', items: 2, slots: [
        { id: 'heading.0', role: 'headline', kind: 'text', sample: 'Features' },
        { id: 'heading.1', role: 'item_title', kind: 'text', sample: 'One' },
        { id: 'heading.2', role: 'item_title', kind: 'text', sample: 'Two' },
    ] },
    'faq': { role: 'faq', layout: 'list', items: 2, slots: [] },
};

const tests = [];
const test = (name, fn) => tests.push([name, fn]);

test('format fills %s, %d and positional placeholders', () => {
    assert.equal(core.format('%1$s (%2$d items)', 'Grid', 3), 'Grid (3 items)');
    assert.equal(core.format('Section %d: %s', 2, 'Prices'), 'Section 2: Prices');
    assert.equal(core.format('%2$s then %1$s', 'a', 'b'), 'b then a');
    assert.equal(core.format('%s and %s', 'x'), 'x and %s', 'a missing argument stays visible');
});

test('humanize', () => {
    assert.equal(core.humanize('hero-split-right'), 'Hero split right');
    assert.equal(core.humanize(''), '');
});

test('move reorders without touching the original', () => {
    const list = ['a', 'b', 'c'];
    assert.deepEqual(core.move(list, 0, 2), ['b', 'c', 'a']);
    assert.deepEqual(core.move(list, 2, 0), ['c', 'a', 'b']);
    assert.deepEqual(core.move(list, 1, 9), ['a', 'c', 'b'], 'clamped');
    assert.deepEqual(core.move(list, 5, 0), ['a', 'b', 'c']);
    assert.deepEqual(list, ['a', 'b', 'c']);
});

test('buildBrief takes included sections, known slots and non-empty words only', () => {
    const s1 = core.newSection('hero-a');
    s1.slots = { 'heading.1': '  Fix leaks fast  ', 'heading.0': '   ', 'nope.0': 'x', 'button.0.link': '/contact/' };
    s1.pictures = { 'section.0.background': { pack: 'hero-blue-1' } };
    const s2 = core.newSection('cards-3');
    s2.on = false;
    const s3 = core.newSection('faq');
    const { brief, map } = core.buildBrief({ name: ' Acme ', goal: 'book', language: 'editorial', kit: '', sections: [s1, s2, s3] }, catalog, 'New page');
    assert.deepEqual(brief, {
        version: 1, title: 'Acme', goal: 'book', language: 'editorial',
        sections: [
            { recipe: 'hero-a', slots: { 'heading.1': 'Fix leaks fast', 'button.0.link': '/contact/' }, pictures: { 'section.0.background': { pack: 'hero-blue-1' } } },
            { recipe: 'faq' },
        ],
    });
    assert.deepEqual(map, [0, 2]);
    const withKit = core.buildBrief({ name: '', goal: 'sell', language: 'luxe', kit: 'gilt-hairline', sections: [s3] }, catalog, 'New page').brief;
    assert.equal(withKit.title, 'New page');
    assert.equal(withKit.kit, 'gilt-hairline');
});

test('buildBrief sends a typed picture address trimmed and a pack choice over an address', () => {
    const s = core.newSection('hero-a');
    s.pictures = { 'section.0.background': { url: ' https://x.test/a.jpg ' } };
    assert.deepEqual(core.buildBrief({ name: 'A', goal: 'book', language: 'editorial', sections: [s] }, catalog, 'N').brief.sections[0].pictures, { 'section.0.background': { url: 'https://x.test/a.jpg' } });
    s.pictures = { 'section.0.background': { url: '' } };
    assert.equal(core.buildBrief({ name: 'A', goal: 'book', language: 'editorial', sections: [s] }, catalog, 'N').brief.sections[0].pictures, undefined);
});

test('item-count guard: a layout holding fewer items than filled cannot be picked', () => {
    const s = core.newSection('cards-3');
    s.slots = { 'heading.1': 'A', 'heading.2': 'B', 'heading.3': 'C' };
    assert.equal(core.filledItems(s, catalog), 3);
    const v = core.variants(s, catalog);
    assert.deepEqual(v.map((x) => x.recipe), ['cards-3', 'cards-2'], 'same role only, catalog order');
    assert.deepEqual(v.map((x) => x.allowed), [true, false]);
    s.slots['heading.3'] = ' ';
    assert.deepEqual(core.variants(s, catalog).map((x) => x.allowed), [true, true]);
});

test('swap keeps a value only on the same id, role and kind', () => {
    const s = core.newSection('hero-a');
    s.slots = { 'heading.0': 'Acme', 'heading.1': 'Fix leaks', 'button.0.text': 'Book', 'button.0.link': '/x/' };
    s.auto = { 'button.0.text': true };
    const r = core.swap(s, 'hero-b', catalog);
    assert.deepEqual(r.section.slots, { 'button.0.text': 'Book' });
    assert.deepEqual(r.kept, ['button.0.text']);
    assert.deepEqual(r.dropped.sort(), ['button.0.link', 'heading.0', 'heading.1'], 'heading.0 is an eyebrow there, the headline here: dropped');
    assert.deepEqual(r.section.auto, { 'button.0.text': true });
    assert.equal(r.section.recipe, 'hero-b');
    assert.equal(core.swap(s, 'missing', catalog).section, s, 'an unknown layout changes nothing');
});

test('applyStepOne offers the business name, line and action to the opening section until edited', () => {
    let state = { name: 'Acme', line: 'Leaks fixed today', action: 'Book a call', sections: [core.newSection('hero-a'), core.newSection('cards-3')] };
    state = core.applyStepOne(state, catalog);
    assert.deepEqual(state.sections[0].slots, { 'heading.1': 'Acme', 'text.0.body.0': 'Leaks fixed today', 'button.0.text': 'Book a call' });
    state.name = 'Acme Plumbing';
    state = core.applyStepOne(state, catalog);
    assert.equal(state.sections[0].slots['heading.1'], 'Acme Plumbing', 'an offered value follows step 1');
    // The person edits the headline: it is theirs now.
    state.sections[0].slots['heading.1'] = 'Same-day plumbing';
    delete state.sections[0].auto['heading.1'];
    state.name = 'Other';
    state.action = '';
    state = core.applyStepOne(state, catalog);
    assert.equal(state.sections[0].slots['heading.1'], 'Same-day plumbing');
    assert.equal(state.sections[0].slots['button.0.text'], undefined, 'an emptied action clears the offered label');
    // A hero without a subhead takes the line in its body; no hero, nothing changes.
    const b = core.applyStepOne({ name: 'A', line: 'L', action: '', sections: [core.newSection('hero-b')] }, catalog);
    assert.deepEqual(b.sections[0].slots, { 'heading.0': 'A', 'text.0.body.0': 'L' });
    const none = core.applyStepOne({ name: 'A', line: 'L', action: 'B', sections: [core.newSection('faq')] }, catalog);
    assert.deepEqual(none.sections[0].slots, {});
});

test('mergeAssistant fills empty or unchanged fields and keeps what the person changed', () => {
    const hero = core.newSection('hero-a');
    hero.slots = { 'heading.1': 'Mine', 'button.0.text': 'Sent' };
    const off = core.newSection('cards-3');
    off.on = false;
    const faq = core.newSection('faq');
    const state = { sections: [hero, off, faq] };
    const synced = { sections: [{ recipe: 'hero-a', slots: { 'heading.1': 'Before', 'button.0.text': 'Sent' } }, { recipe: 'faq' }] };
    const stored = { sections: [
        { recipe: 'hero-a', slots: { 'heading.1': 'Assistant', 'button.0.text': 'Book now', 'heading.0': 'Acme', 'ghost.9': 'x' } },
        { recipe: 'faq' },
    ] };
    const r = core.mergeAssistant(state, stored, synced, [hero.key, faq.key], catalog);
    assert.equal(r.applied, 2, 'heading.0 was empty, button.0.text was unchanged since the save');
    assert.equal(r.kept, 1, 'heading.1 was changed here after the save');
    assert.deepEqual(r.state.sections[0].slots, { 'heading.1': 'Mine', 'button.0.text': 'Book now', 'heading.0': 'Acme' });
    assert.deepEqual(state.sections[0].slots, { 'heading.1': 'Mine', 'button.0.text': 'Sent' }, 'the input is not changed');
    const moved = core.mergeAssistant(state, { sections: [{ recipe: 'faq', slots: {} }] }, synced, [hero.key], catalog);
    assert.equal(moved.applied, 0, 'a section whose layout differs is left alone');
});

test('mergeAssistant finds the sections of the last sync by key, even after a reorder or a section left out', () => {
    const hero = core.newSection('hero-a');
    const cards = core.newSection('cards-3');
    const built = core.buildBrief({ name: 'A', goal: 'book', language: 'editorial', sections: [hero, cards] }, catalog, 'N');
    assert.deepEqual(built.keys, [hero.key, cards.key]);
    assert.notEqual(hero.key, cards.key);
    // After the sync the person moves the cards up and leaves the hero out; the assistant wrote into both.
    hero.on = false;
    const state = { sections: [cards, hero] };
    const stored = { sections: [{ recipe: 'hero-a', slots: { 'heading.1': 'H' } }, { recipe: 'cards-3', slots: { 'heading.1': 'Card' } }] };
    const r = core.mergeAssistant(state, stored, built.brief, built.keys, catalog);
    assert.equal(r.applied, 2);
    assert.deepEqual(r.state.sections[0].slots, { 'heading.1': 'Card' });
    assert.deepEqual(r.state.sections[1].slots, { 'heading.1': 'H' });
    // Keys survive a layout swap and a stored brief loaded again.
    assert.equal(core.swap(cards, 'cards-2', catalog).section.key, cards.key);
    const loaded = core.fromStored({ title: 'A', sections: [{ recipe: 'faq' }, { recipe: 'faq' }] }, catalog);
    assert.equal(new Set(loaded.state.sections.map((x) => x.key)).size, 2);
});

test('prepareSend takes the assistant\'s stored words before a save, so a save never overwrites them (ruling 19)', () => {
    const hero = core.newSection('hero-a');
    hero.slots = { 'heading.1': 'Mine' };
    const base = { name: 'Acme', goal: 'book', language: 'editorial', kit: '', sections: [hero] };
    const sent = core.buildBrief(base, catalog, 'N');
    // The assistant fills the button and the eyebrow; the person, meanwhile, changes the headline.
    hero.slots['heading.1'] = 'Mine, edited';
    const stored = { version: 1, title: 'Acme', goal: 'book', language: 'editorial', sections: [{ recipe: 'hero-a', slots: { 'heading.1': 'Assistant headline', 'button.0.text': 'Book now', 'heading.0': 'Plumbers' } }] };
    const out = core.prepareSend(base, stored, sent.brief, sent.keys, catalog, 'N');
    assert.deepEqual(out.brief.sections[0].slots, { 'heading.0': 'Plumbers', 'heading.1': 'Mine, edited', 'button.0.text': 'Book now' }, 'untouched slots take the assistant\'s words, the edited one stays the person\'s');
    assert.equal(out.applied, 2);
    assert.equal(out.kept, 1);
    assert.deepEqual(out.keys, [hero.key]);
    assert.deepEqual(out.state.sections[0].slots, out.brief.sections[0].slots, 'the form shows what is sent');
    // Nothing stored yet (no brief id): the form is sent as it is.
    const plain = core.prepareSend(base, null, null, [], catalog, 'N');
    assert.deepEqual(plain.brief.sections[0].slots, { 'heading.1': 'Mine, edited' });
    assert.equal(plain.applied, 0);
});

test('one busy gate: a second action while one runs does nothing, and the gate opens again after success or failure', async () => {
    const changes = [];
    const gate = core.makeGate((busy) => changes.push(busy));
    let release;
    const first = gate.run(() => new Promise((resolve) => { release = resolve; }));
    assert.equal(gate.busy(), true);
    assert.equal(gate.run(() => { throw new Error('must not run'); }), null, 'a double click is ignored');
    release('done');
    assert.equal(await first, 'done');
    assert.equal(gate.busy(), false);
    const failed = gate.run(() => Promise.reject(new Error('network')));
    await assert.rejects(failed);
    assert.equal(gate.busy(), false, 'cleared in finally');
    const thrown = gate.run(() => { throw new Error('sync'); });
    await assert.rejects(thrown);
    assert.equal(gate.busy(), false);
    assert.deepEqual(changes, [true, false, true, false, true, false]);
});

test('frameBox renders the preview at a true device width and scales it down to fit the pane, never up', () => {
    assert.deepEqual(core.DEVICES, { desktop: 1280, tablet: 768, phone: 390 });
    const narrow = core.frameBox(760, 'desktop', 600);
    assert.equal(narrow.width, 1280, 'the frame is a real desktop, past Divi\'s 981px breakpoint');
    assert.ok(Math.abs(narrow.scale - 760 / 1280) < 1e-9 && narrow.scale > 0.59 && narrow.scale < 0.6, String(narrow.scale));
    assert.equal(narrow.wrapWidth, 760, 'the wrapper is the scaled size: no overflow from the unscaled box');
    assert.equal(narrow.height, Math.round(600 / narrow.scale), 'the frame is taller so that, scaled, it fills the target height');
    assert.equal(narrow.wrapHeight, 600);
    const wide = core.frameBox(1400, 'desktop', 700);
    assert.deepEqual(wide, { width: 1280, scale: 1, height: 700, wrapWidth: 1280, wrapHeight: 700 }, 'never scaled up');
    const tablet = core.frameBox(500, 'tablet', 600);
    assert.equal(tablet.width, 768);
    assert.ok(Math.abs(tablet.scale - 500 / 768) < 1e-9);
    assert.equal(tablet.wrapWidth, 500);
    assert.deepEqual(core.frameBox(760, 'phone', 600), { width: 390, scale: 1, height: 600, wrapWidth: 390, wrapHeight: 600 });
    assert.equal(core.frameBox(300, 'phone', 600).wrapWidth, 300);
    for (const pane of [0, -50, NaN, undefined]) {
        const box = core.frameBox(pane, 'desktop', 600);
        assert.equal(box.scale, 1, 'a pane with no width yet (hidden) is not scaled to nothing: ' + pane);
        assert.equal(box.width, 1280);
        assert.ok(box.height > 0 && box.wrapHeight > 0);
    }
    assert.equal(core.frameBox(760, 'tv', 600).width, 1280, 'an unknown device is the desktop');
    assert.equal(core.frameBox(760, 'desktop', 0).wrapHeight, 360, 'a sensible minimum height');
    for (let pane = 100; pane <= 2000; pane += 37) {
        for (const d of ['desktop', 'tablet', 'phone']) {
            const b = core.frameBox(pane, d, 640);
            assert.ok(b.scale > 0 && b.scale <= 1);
            assert.ok(b.wrapWidth <= Math.max(pane, 0) || b.scale === 1);
            assert.ok(b.wrapWidth <= pane, `wrapper ${b.wrapWidth} fits pane ${pane} (${d})`);
        }
    }
});

test('problems and unfilled places are grouped by the section on the screen', () => {
    const p = core.problemsBySection([
        { path: 'title', code: 'MISSING', message: 'm1' },
        { path: 'sections.1.slots.heading.0', code: 'UNKNOWN_SLOT', message: 'm2' },
        { path: 'sections.0', code: 'WRONG_TYPE', message: 'm3' },
        { path: 'sections.7.recipe', code: 'X', message: 'm4' },
    ], [0, 2]);
    assert.deepEqual(p.page.map((x) => x.message), ['m1', 'm4']);
    assert.deepEqual(p.sections[2], [{ path: 'sections.1.slots.heading.0', code: 'UNKNOWN_SLOT', message: 'm2', slot: 'heading.0' }]);
    assert.equal(p.sections[0][0].slot, '');
    const u = core.unfilledBySection([{ section: 0, slot: 'heading.1', role: 'headline' }, { section: 1, slot: 'x', role: 'body' }], [0, 3]);
    assert.deepEqual(u, { 0: [{ slot: 'heading.1', role: 'headline' }], 3: [{ slot: 'x', role: 'body' }] });
});

test('toSitePath and sameSiteUrl', () => {
    assert.equal(core.toSitePath('http://localhost:8181/wp-content/uploads/a.jpg', 'http://localhost:8181/'), '/wp-content/uploads/a.jpg');
    assert.equal(core.toSitePath('HTTP://LOCALHOST:8181/x.png?v=1', 'http://localhost:8181/site/'), '/x.png?v=1');
    assert.equal(core.toSitePath('https://cdn.test/a.jpg', 'http://localhost:8181/'), 'https://cdn.test/a.jpg');
    assert.equal(core.toSitePath('http://other.test/a.jpg', 'http://localhost:8181/'), null);
    assert.equal(core.toSitePath('javascript:alert(1)', 'http://localhost:8181/'), null);
    assert.equal(core.sameSiteUrl('http://localhost:8181/wp-admin/post.php?post=3', 'http://localhost:8181/wp-admin/admin.php'), 'http://localhost:8181/wp-admin/post.php?post=3');
    assert.equal(core.sameSiteUrl('https://evil.test/', 'http://localhost:8181/'), null);
    assert.equal(core.sameSiteUrl('javascript:alert(1)', 'http://localhost:8181/'), null);
});

test('fromStored reports layouts that left the library and words that lost their place', () => {
    const r = core.fromStored({ title: 'A', goal: 'book', language: 'luxe', sections: [
        { recipe: 'hero-a', slots: { 'heading.1': 'H', 'old.1': 'gone' } },
        { recipe: 'retired-recipe', slots: { 'heading.0': 'x' } },
    ] }, catalog);
    assert.equal(r.state.name, 'A');
    assert.equal(r.state.kit, '');
    assert.deepEqual(r.gone, [1]);
    assert.deepEqual(r.stale, { 0: ['old.1'] });
    assert.equal(r.state.sections[1].recipe, 'retired-recipe');
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
