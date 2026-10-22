#!/usr/bin/env node
'use strict';
/* Pure tests of scripts/lib/dogfood-render.cjs (no site, no browser): the argument parser, the output name, the run title and prefix, and the
 * verdict (it must never pass with something unmeasured). Run: node scripts/test-dogfood-render.cjs */
const assert = require('assert');
const D = require('./lib/dogfood-render.cjs');

let n = 0;
const t = (name, fn) => { fn(); n++; };

const clean = { valid: true, verdict: 'valid', cleanupOk: true, widths: { 1280: { overflow: 0, errors: [], phpError: null }, 400: { overflow: 0, errors: [], phpError: null } } };
const withWidth = (w, patch) => ({ ...clean, widths: { ...clean.widths, [w]: { ...clean.widths[w], ...patch } } });
const failsWith = (res, re) => {
  const s = D.summarize(res);
  assert.strictEqual(s.ok, false);
  assert.ok(s.lines.some((l) => re.test(l)), 'expected a line matching ' + re + ' in ' + JSON.stringify(s.lines));
  assert.strictEqual(s.lines[s.lines.length - 1], 'FAILED');
};

t('parse args', () => {
  assert.deepStrictEqual(D.parseArgs(['fixtures/dogfood/a/page.html']), { page: 'fixtures/dogfood/a/page.html', name: null, out: null, cleanOrphans: false });
  assert.deepStrictEqual(D.parseArgs(['p.html', '--out', 'x']), { page: 'p.html', name: null, out: 'x', cleanOrphans: false });
  assert.deepStrictEqual(D.parseArgs(['--out', 'x', 'p.html']), { page: 'p.html', name: null, out: 'x', cleanOrphans: false });
  assert.deepStrictEqual(D.parseArgs(['p.html', '--name', 'my-run']), { page: 'p.html', name: 'my-run', out: null, cleanOrphans: false });
  assert.deepStrictEqual(D.parseArgs(['--clean-orphans']), { cleanOrphans: true });
  const bad = (argv) => assert.deepStrictEqual(D.parseArgs(argv), { error: D.USAGE }, JSON.stringify(argv));
  bad([]);
  bad(['a.html', 'b.html']);
  bad(['--clean-orphans', 'a.html']);
  bad(['a.html', '--clean-orphans']);
  bad(['--clean-orphans', '--out', 'x']);
  bad(['a.html', '--out']);
  bad(['a.html', '--out', '--name']);
  bad(['a.html', '--out', 'x', '--out', 'y']);
  bad(['a.html', '--name', 'Bad_Id']);
  bad(['a.html', '--name']);
  bad(['a.html', '--bogus']);
  bad(['--out', 'x']);
  bad(null);
});
t('there is no --kit option (controller ruling 7): it is an unknown flag', () => {
  assert.deepStrictEqual(D.parseArgs(['p.html', '--kit', 'dotted-pill']), { error: D.USAGE });
  assert.deepStrictEqual(D.parseArgs(['p.html', '--kit=dotted-pill']), { error: D.USAGE });
  assert.ok(!/--kit/.test(D.USAGE));
});

t('out name', () => {
  assert.strictEqual(D.outName('fixtures/dogfood/hairline-dashed/page.html'), 'hairline-dashed');
  assert.strictEqual(D.outName('fixtures/dogfood/edit-glass-edge/page-edited.html'), 'edit-glass-edge-page-edited');
  assert.strictEqual(D.outName('/abs/path/Odd Name_1/page.html'), 'odd-name-1');
  assert.strictEqual(D.outName('a b/c@d.html'), 'a-b-c-d');
  assert.strictEqual(D.outName('./x/page.html'), 'x');
  assert.strictEqual(D.outName('page.html'), 'page');
  assert.strictEqual(D.outName('dir\\win\\page.html'), 'win');
  assert.match(D.outName('weird/..%2f/page.html'), /^[a-z0-9-]+$/);
});

t('run title and prefix', () => {
  const title = D.runTitle('123-abcdef', 'hairline-dashed');
  assert.strictEqual(title, 'aied dogfood 123-abcdef hairline-dashed');
  assert.ok(title.startsWith(D.TITLE_PREFIX + '123-abcdef '), 'the run prefix throwawayPages sweeps by');
  assert.strictEqual(D.TITLE_PREFIX, 'aied dogfood ');
  for (const other of ['aied kit 123-abcdef x y', 'aied recipe 123-abcdef x y', 'aied shape probe 1', 'My dogfood page']) {
    assert.ok(!other.startsWith(D.TITLE_PREFIX), other + ' is another gate\'s page');
  }
  // Two runs never share a prefix.
  assert.ok(!D.runTitle('124-abcdef', 'a').startsWith(D.TITLE_PREFIX + '123-abcdef '));
  // The bare prefix sweep of --clean-orphans matches every run, the run prefix only its own.
  assert.ok(D.runTitle('123-abcdef', 'a').startsWith(D.TITLE_PREFIX));
});

t('summary ok on a clean result', () => {
  const s = D.summarize(clean);
  assert.strictEqual(s.ok, true);
  assert.strictEqual(s.lines[s.lines.length - 1], 'OK');
  assert.ok(s.lines.some((l) => /^1280px: overflow 0/.test(l)) && s.lines.some((l) => /^400px: overflow 0/.test(l)));
  assert.ok(!s.lines.some((l) => /^FAIL /.test(l)));
});
t('summary fails on an invalid layout and names the codes', () => {
  failsWith({ ...clean, valid: false, verdict: 'INVALID PARENT_NOT_ALLOWED,UNKNOWN_BLOCK', widths: {} }, /layout not valid.*PARENT_NOT_ALLOWED,UNKNOWN_BLOCK/);
});
t('summary fails on overflow at 400', () => failsWith(withWidth(400, { overflow: 1 }), /400px horizontal overflow 1/));
t('summary fails on overflow at 1280 (negative too)', () => {
  failsWith(withWidth(1280, { overflow: 12 }), /1280px horizontal overflow 12/);
  failsWith(withWidth(1280, { overflow: -3 }), /1280px horizontal overflow -3/);
});
t('summary fails on a console error', () => failsWith(withWidth(1280, { errors: ['console: boom'] }), /1280px 1 error/));
t('summary fails on a PHP error', () => failsWith(withWidth(400, { phpError: 'Warning: x on line 3' }), /400px PHP error text/));
t('summary fails when cleanup was incomplete', () => {
  failsWith({ ...clean, cleanupOk: false }, /cleanup incomplete/);
  const { cleanupOk, ...noFlag } = clean;
  failsWith(noFlag, /cleanup incomplete/);
});
t('summary fails when a width was not measured', () => {
  failsWith({ ...clean, widths: { 1280: clean.widths[1280] } }, /400px not measured/);
  failsWith({ ...clean, widths: { 400: clean.widths[400] } }, /1280px not measured/);
  failsWith({ ...clean, widths: {} }, /1280px not measured/);
  failsWith({ ...clean, widths: { 1280: clean.widths[1280], 400: { overflow: null, errors: [], phpError: null } } }, /400px not measured/);
  failsWith({ ...clean, widths: undefined }, /not measured/);
  failsWith({}, /not valid/);
  failsWith(undefined, /not valid/);
});
t('summary lists every reason, not only the first', () => {
  const s = D.summarize({ valid: true, cleanupOk: false, widths: { 1280: { overflow: 5, errors: ['x'], phpError: null }, 400: { overflow: 2, errors: [], phpError: null } } });
  const fails = s.lines.filter((l) => /^FAIL /.test(l));
  assert.strictEqual(fails.length, 4);
});

t('summary fails after an internal script error even when everything else looks clean', () => {
  failsWith({ ...clean, error: 'boom' }, /^FAIL script error: boom$/);
  const long = 'x'.repeat(50000);
  const s = D.summarize({ ...clean, error: long });
  assert.strictEqual(s.ok, false);
  assert.strictEqual(s.lines[s.lines.length - 1], 'FAILED');
  assert.ok(s.lines.every((l) => l.length < 400), 'no printed line is longer than the cap');
  assert.strictEqual(D.shorten(long).length, D.MAX_ERROR + 3);
  assert.strictEqual(D.shorten(new Error('multi\n  line   text')), 'multi line text');
  assert.strictEqual(D.shorten('short'), 'short');
});

// idsWithPrefix and sweep of the shared throwaway-pages library, with a fake wp (no docker): the run prefix deletes only this run's pages.
t('idsWithPrefix: run prefix, bare prefix, a non-numeric id', () => {
  const { throwawayPages } = require('./lib/throwaway-pages.cjs');
  const calls = [];
  const rows = [
    { ID: 11, post_title: 'aied dogfood 123-abcdef mine' },
    { ID: 12, post_title: 'aied dogfood 124-abcdef theirs' },
    { ID: 13, post_title: 'aied dogfood 123-abcdefg lookalike' },
    { ID: 14, post_title: 'aied kit 123-abcdef other gate' },
    { ID: 15, post_title: 'My aied dogfood page' },
    { ID: 16, post_title: null },
    { ID: 'x;rm', post_title: 'aied dogfood 123-abcdef evil id' },
  ];
  const fakeWp = (...a) => {
    calls.push(a);
    if (a[0] === 'post' && a[1] === 'list') return JSON.stringify(rows);
    return '';
  };
  const pages = throwawayPages(fakeWp, D.TITLE_PREFIX, 'dogfood');
  // The real run prefix has a random token; build the rows around it.
  rows[0].post_title = pages.runPrefix + 'mine';
  rows[2].post_title = pages.runPrefix.replace(/ $/, 'g ') + 'lookalike';
  rows[6].post_title = pages.runPrefix + 'evil id';
  assert.deepStrictEqual(pages.idsWithPrefix(rows, pages.runPrefix), ['11', 'x;rm']);
  assert.deepStrictEqual(pages.idsWithPrefix(rows, D.TITLE_PREFIX), ['11', '12', '13', 'x;rm']);
  assert.deepStrictEqual(pages.idsWithPrefix(rows, 'no such prefix'), []);
  assert.deepStrictEqual(pages.idsWithPrefix([], D.TITLE_PREFIX), []);
  assert.ok(pages.runPrefix.startsWith(D.TITLE_PREFIX) && pages.runPrefix.endsWith(' '));

  // sweep deletes only this run's numeric ids, ignores the non-numeric one (and reports it), never another run's page.
  const logs = [];
  const log = console.log;
  console.log = (...m) => logs.push(m.join(' '));
  let ok;
  try { ok = pages.sweep(); } finally { console.log = log; }
  assert.strictEqual(ok, false, 'a non-numeric id makes the cleanup incomplete');
  assert.ok(logs.some((l) => /ignoring non-numeric page id x;rm/.test(l)));
  const deleted = calls.filter((c) => c[0] === 'post' && c[1] === 'delete').map((c) => c[2]);
  assert.deepStrictEqual(deleted, ['11']);
  assert.ok(calls.filter((c) => c[0] === 'post' && c[1] === 'delete').every((c) => c.includes('--force')));
  assert.strictEqual(pages.foreignCount(), 4, 'bare prefix counts every run, never deletes');
  assert.strictEqual(calls.filter((c) => c[1] === 'delete').length, 1, 'foreignCount deleted nothing');
});

t('render.json carries the verdict and the error only when there is one', () => {
  const base = { page: 'p.html', title: 'aied dogfood x p', valid: true, verdict: 'valid', widths: { 1280: { overflow: 0, errors: [], phpError: null } }, cleanupOk: true };
  assert.deepStrictEqual(Object.keys(D.renderJson(base)), ['page', 'title', 'valid', 'widths', 'cleanupOk']);
  const invalid = D.renderJson({ ...base, valid: false, verdict: 'INVALID MISSING_X,BAD_Y', widths: {} });
  assert.strictEqual(invalid.valid, false);
  assert.strictEqual(invalid.verdict, 'INVALID MISSING_X,BAD_Y');
  assert.ok(!('error' in invalid));
  const failed = D.renderJson({ ...base, valid: false, verdict: '', error: new Error('x'.repeat(D.MAX_ERROR + 50)), cleanupOk: false });
  assert.ok(typeof failed.error === 'string' && failed.error.length <= D.MAX_ERROR + 3, 'the error is cut');
  assert.ok(!('verdict' in failed), 'no verdict to report');
  assert.strictEqual(failed.cleanupOk, false);
  assert.deepStrictEqual(D.renderJson(undefined).widths, {});
});

console.log(`${n} pure dogfood-render tests passed`);
