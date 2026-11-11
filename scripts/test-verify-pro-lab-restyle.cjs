#!/usr/bin/env node
'use strict';
/* Pure tests of scripts/verify-pro-lab-restyle.cjs (Pro Lab plan, Task 9; no site, no Docker): the run token and page title formats, the
 * reading of a title back into its run and role, the argument parsing, the reading of the PHP stage's JSON lines (shared with the
 * switch-design gate), a page with too few modules, and the module comparison refusing an empty compare. Run first by
 * `make verify-pro-lab-restyle`. */
const assert = require('assert');
const G = require('./verify-pro-lab-restyle.cjs');
const S = require('./verify-pro-switch-design.cjs');

let n = 0;
const t = (name, fn) => { try { fn(); n++; } catch (e) { console.log('FAIL ' + name + ': ' + (e && e.message ? e.message : e)); process.exit(1); } };

// ---- run token and titles ----
t('the title prefix is the gate\'s own and names the tool family', () => {
  assert.strictEqual(G.TITLE_PREFIX, 'aied labrestyle ');
  assert.ok(G.TITLE_PREFIX !== S.TITLE_PREFIX, 'not the switch gate\'s prefix');
});
t('a run token is <pid>-<6 hex>', () => {
  assert.ok(G.isRunToken('1234-abcdef'));
  assert.ok(G.isRunToken('1-000000'));
  for (const bad of ['', 'abc-123456', '1234-ABCDEF', '1234-abcde', '1234-abcdefa', '1234_abcdef', ' 1234-abcdef', 'all', null, 12]) assert.ok(!G.isRunToken(bad), String(bad));
});
t('pageTitle builds `aied labrestyle <run> <role>` for a known role and refuses anything else', () => {
  assert.strictEqual(G.pageTitle('1234-abcdef', 'rest'), 'aied labrestyle 1234-abcdef rest');
  assert.strictEqual(G.pageTitle('1234-abcdef', 'ref-rest'), 'aied labrestyle 1234-abcdef ref-rest');
  assert.throws(() => G.pageTitle('nope', 'rest'));
  assert.throws(() => G.pageTitle('1234-abcdef', 'unknown-role'));
  for (const r of G.ROLES) assert.strictEqual(G.parseTitle(G.pageTitle('9-a1b2c3', r)).role, r, r);
});
t('the roles: the Lab pages, their references, and the render pair', () => {
  for (const r of ['rest', 'mcp', 'changed', 'briefedit', 'published', 'kses', 'denied', 'editor', 'ref-rest', 'ref-mcp', 'ref-published', 'ref-kses']) assert.ok(G.ROLES.includes(r), r);
  assert.deepStrictEqual(G.RENDER_PAIR, ['rest', 'ref-rest']);
  assert.deepStrictEqual(G.WIDTHS, [1280, 400]);
});
t('parseTitle reads run and role back, and refuses other titles', () => {
  assert.deepStrictEqual(G.parseTitle('aied labrestyle 1234-abcdef rest'), { run: '1234-abcdef', role: 'rest' });
  assert.deepStrictEqual(G.parseTitle('aied labrestyle 1234-abcdef ref-kses'), { run: '1234-abcdef', role: 'ref-kses' });
  for (const bad of ['aied switch 1234-abcdef source', 'aied labrestyle 1234-abcdef', 'aied labrestyle 1234-abcdef nope', 'aied labrestyle x-abcdef rest', 'Aied labrestyle 1234-abcdef rest', 'aied labrestyle 1234-abcdef rest extra', '', null]) assert.strictEqual(G.parseTitle(bad), null, String(bad));
});
t('ownRunPages keeps only this run\'s pages, by exact title', () => {
  const rows = [
    { ID: 1, post_title: 'aied labrestyle 1-aaaaaa rest' },
    { ID: 2, post_title: 'aied labrestyle 2-bbbbbb rest' },
    { ID: 3, post_title: 'aied labrestyle 1-aaaaaa ref-rest' },
    { ID: 4, post_title: 'Home' },
    { ID: 5, post_title: 'aied labrestyle 1-aaaaaa rest copy' },
  ];
  assert.deepStrictEqual(G.ownRunPages(rows, '1-aaaaaa'), { rest: '1', 'ref-rest': '3' });
  const dup = rows.concat([{ ID: 6, post_title: 'aied labrestyle 1-aaaaaa rest' }]);
  assert.strictEqual(G.ownRunPages(dup, '1-aaaaaa').rest, null, 'two pages with one title: none is trusted');
});

// ---- arguments ----
t('parseArgs: nothing, --clean-orphans, and anything else is a usage error', () => {
  assert.deepStrictEqual(G.parseArgs([]), { cleanOrphans: false });
  assert.deepStrictEqual(G.parseArgs(['--clean-orphans']), { cleanOrphans: true });
  assert.strictEqual(G.parseArgs(['--kits', 'x']), null);
  assert.strictEqual(G.parseArgs(['--clean']), null);
});

// ---- the PHP stage's output ----
t('stage JSON lines: checks, info and noise', () => {
  const out = [
    '{"type":"check","ok":true,"label":"a","detail":null}',
    'Warning: something',
    '{"type":"check","ok":false,"label":"b","detail":"why"}',
    '{"type":"info","key":"ids","value":{"rest":5}}',
    '{not json',
    '',
  ].join('\n');
  const p = G.parsePhpOutput(out);
  assert.deepStrictEqual(p.checks, [{ ok: true, label: 'a', detail: null }, { ok: false, label: 'b', detail: 'why' }]);
  assert.deepStrictEqual(p.info, { ids: { rest: 5 } });
  assert.deepStrictEqual(p.other, ['Warning: something', '{not json']);
});
t('a stage with no check, or a non-zero exit, fails even without a failed check', () => {
  assert.deepStrictEqual(G.stageProblems('build', G.parsePhpOutput('{"type":"check","ok":true,"label":"a"}'), 0), []);
  assert.ok(G.stageProblems('build', G.parsePhpOutput(''), 0).some((x) => /reported no check/.test(x)));
  assert.ok(G.stageProblems('build', G.parsePhpOutput('{"type":"check","ok":true,"label":"a"}\nFatal error: x'), 255).some((x) => /exited 255/.test(x)));
  assert.deepStrictEqual(G.stageProblems('x', G.parsePhpOutput('{"type":"check","ok":false,"label":"b","detail":"d"}'), 1).slice(0, 1), ['b (d)']);
});

// ---- the render comparison ----
const mod = (key, y) => {
  const styles = {};
  for (const p of G.PROPS) styles[p] = 'x';
  return { key, styles, box: { x: 0, y, w: 100, h: 40 } };
};
const pageOf = (k) => Array.from({ length: k }, (_, i) => mod(i % 2 ? 'div.et_pb_row' : 'div.et_pb_section', i * 50));
t('the comparison is the switch-design gate\'s own (same properties, same tolerance)', () => {
  assert.strictEqual(G.compareModules, S.compareModules);
  assert.strictEqual(G.PROPS, S.PROPS);
  assert.strictEqual(G.collectModules, S.collectModules);
});
t('an empty compare is refused: a run that compared nothing fails', () => {
  assert.strictEqual(G.compareModules([], []).checked, 0);
  assert.strictEqual(G.emptyRunProblems({ compared: 0 }).length, 1);
  assert.ok(/nothing was compared/.test(G.emptyRunProblems({ compared: 0 })[0]));
  assert.deepStrictEqual(G.emptyRunProblems({ compared: 12 }), []);
});
t('a rendered page with no content or too few modules is a problem; enough modules is not', () => {
  assert.deepStrictEqual(G.pageProblems({ hasRoot: false, modules: [] }).map((x) => x[0]), ['empty']);
  assert.deepStrictEqual(G.pageProblems({ hasRoot: true, modules: pageOf(G.MIN_MODULES - 1) }).map((x) => x[0]), ['too-few-modules']);
  assert.deepStrictEqual(G.pageProblems({ hasRoot: true, modules: pageOf(G.MIN_MODULES) }), []);
  assert.ok(G.MIN_MODULES >= 20, 'five recipes carry far more than a handful of modules');
});
t('renderVerdict: equal pages pass with a count, a difference or a page problem fails', () => {
  const ok = G.renderVerdict(pageOf(G.MIN_MODULES), pageOf(G.MIN_MODULES), { restyled: {}, reference: {} });
  assert.strictEqual(ok.compared, G.MIN_MODULES);
  assert.deepStrictEqual(ok.problems, []);
  const off = pageOf(G.MIN_MODULES); off[2].styles.color = 'red';
  assert.deepStrictEqual(G.renderVerdict(off, pageOf(G.MIN_MODULES), { restyled: {}, reference: {} }).problems.map((x) => x[0]), ['style-color']);
  const few = G.renderVerdict(pageOf(3), pageOf(3), { restyled: {}, reference: {} });
  assert.deepStrictEqual(few.problems.map((x) => x[0]), ['too-few-modules', 'too-few-modules']);
  const php = G.renderVerdict(pageOf(G.MIN_MODULES), pageOf(G.MIN_MODULES), { restyled: { php: 'Fatal error' }, reference: { consoleErrors: ['console: x'] } });
  assert.deepStrictEqual(php.problems.map((x) => x[0]), ['php-error', 'console-error']);
});

console.log(`OK ${n} pure tests of verify-pro-lab-restyle`);
