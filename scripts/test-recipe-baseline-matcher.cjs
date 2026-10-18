#!/usr/bin/env node
'use strict';
/* Pure test (no site, no browser) of the render-failure baseline parser and the exact-cell matcher in scripts/lib/recipe-checks.cjs.
 * Run by tests/RecipeLibraryTest.php; also: node scripts/test-recipe-baseline-matcher.cjs */
const assert = require('assert');
const { parseBaseline, parseEntry, knownFailures, isKnownFail, PHP_ERROR } = require('./lib/recipe-checks.cjs');

const text = [
  '# header comment', '', '# no-pairs', 'hero-cta', '', '# duplicate-layouts', 'a|b', '',
  '# render-failures',
  'hero-cta|default|400|overflow|a real, documented reason',
  'card-grid-3|glass-dark|1280|columns-side-by-side|why',
  'blog-feed|default|gate|gate|the gate said no',
].join('\n');
const b = parseBaseline(text);
assert.deepStrictEqual(b.noPairs, ['hero-cta']);
assert.deepStrictEqual(b.duplicates, ['a|b']);
assert.strictEqual(b.renderFailures.length, 3);
const known = knownFailures(b.renderFailures);

// The listed cell, and only it.
assert.strictEqual(isKnownFail(known, 'hero-cta', 'default', '400', 'overflow'), true);
assert.strictEqual(isKnownFail(known, 'hero-cta', 'default', 400, 'overflow'), true, 'a numeric width is the same cell');
assert.strictEqual(isKnownFail(known, 'hero-cta', 'default', '900', 'overflow'), false, 'another width');
assert.strictEqual(isKnownFail(known, 'hero-cta', 'default', '1280', 'overflow'), false, 'another width');
assert.strictEqual(isKnownFail(known, 'hero-cta', 'glass-dark', '400', 'overflow'), false, 'another language');
assert.strictEqual(isKnownFail(known, 'hero-cta', 'default', '400', 'empty'), false, 'another reason');
assert.strictEqual(isKnownFail(known, 'hero-cta', 'default', '400', 'console-error'), false, 'another reason');
assert.strictEqual(isKnownFail(known, 'hero-cta', 'default', '400', 'render-error'), false, 'render-error is never known');
assert.strictEqual(isKnownFail(known, 'section-intro', 'default', '400', 'overflow'), false, 'another recipe');
assert.strictEqual(isKnownFail(known, 'card-grid-3', 'glass-dark', '1280', 'columns-side-by-side'), true);
assert.strictEqual(isKnownFail(known, 'card-grid-3', 'glass-dark', '400', 'columns-side-by-side'), false);
assert.strictEqual(isKnownFail(known, 'blog-feed', 'default', 'gate', 'gate'), true);
assert.strictEqual(isKnownFail(known, 'blog-feed', 'glass-dark', 'gate', 'gate'), false);
assert.strictEqual(isKnownFail(known, 'blog-feed', 'default', 'prepare', 'render-error'), false);

// The explanation is documentation only: a "|" inside it is kept, and it never matches.
assert.strictEqual(parseEntry('x|default|400|overflow|a|b|c').explanation, 'a|b|c');

// Malformed or forbidden entries are rejected.
const bad = {
  'old three-field format': 'hero-cta|overflow|why',
  'wildcard width': 'hero-cta|default|*|overflow|why',
  'width not one of the three': 'hero-cta|default|500|overflow|why',
  'unknown code': 'hero-cta|default|400|sparkle|why',
  'render-error can never be baselined': 'hero-cta|default|400|render-error|why',
  'render-error with the prepare width': 'hero-cta|default|prepare|render-error|why',
  'gate code needs the gate width': 'hero-cta|default|400|gate|why',
  'gate width needs the gate code': 'hero-cta|default|gate|overflow|why',
  'empty explanation': 'hero-cta|default|400|overflow|',
  'bad recipe name': 'Hero CTA|default|400|overflow|why',
  'bad language': 'hero-cta|Glass Dark|400|overflow|why',
};
for (const [label, line] of Object.entries(bad)) assert.throws(() => parseEntry(line), Error, label);
assert.throws(() => parseBaseline('# render-failures\nhero-cta|overflow|why\n'), /recipe\|language\|width\|code\|explanation/);
assert.throws(() => parseBaseline('stray line\n'), /outside a section/);

// PHP error patterns (shared with the live script).
const hit = (t) => PHP_ERROR.some((r) => r.test(t));
for (const t of ['<b>Warning</b>:  Undefined variable $x in <b>/a.php</b> on line <b>3</b>', 'Fatal error: Uncaught Error: x in /a.php:3', 'Notice: foo in /a on line 2', 'Parse error: syntax error, in /a on line 1']) assert.ok(hit(t), t);
for (const t of ['<p>Notice: sale ends</p>', 'Plain Warning to you']) assert.ok(!hit(t), t);

console.log('OK recipe baseline parser and exact-cell matcher');
