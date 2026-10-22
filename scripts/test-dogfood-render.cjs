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

console.log(`${n} pure dogfood-render tests passed`);
