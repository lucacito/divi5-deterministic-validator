#!/usr/bin/env node
'use strict';
/* Pure tests of the comparison helpers of scripts/verify-pro-switch-design.cjs (design switch plan, Task 9; no site, no Docker): the module-list
 * comparison (equal pages, a colour off, a box off by more and by less than the tolerance, a missing and an extra module, an empty run, a
 * run with too few modules), the reading of the PHP stage's JSON lines, the one-run-at-a-time check, and (when Playwright is installed) the
 * in-page collector against synthetic pages in a real Chromium: identical pages are equal, a colour changed by one unit is found, a state
 * class that only scripts add does not change a module's key. Run first by `make verify-pro-switch-design`. */
const assert = require('assert');
const path = require('path');
const G = require('./verify-pro-switch-design.cjs');

let n = 0;
const t = (name, fn) => { try { fn(); n++; } catch (e) { console.log('FAIL ' + name + ': ' + (e && e.message ? e.message : e)); process.exit(1); } };
const tAsync = async (name, fn) => { try { await fn(); n++; } catch (e) { console.log('FAIL ' + name + ': ' + (e && e.message ? e.message : e)); process.exit(1); } };

const clone = (o) => JSON.parse(JSON.stringify(o));
/** A module as the in-page collector reports it. */
const mod = (key, over = {}, box = {}) => {
  const styles = {};
  for (const p of G.PROPS) styles[p] = 'x';
  styles.color = 'rgb(17, 17, 17)';
  styles['background-color'] = 'rgba(0, 0, 0, 0)';
  return { key, styles: { ...styles, ...over }, box: { x: 0, y: 0, w: 100, h: 40, ...box } };
};
const page = () => ['div.et_pb_section', 'div.et_pb_row', 'div.et_pb_column', 'div.et_pb_module.et_pb_text', 'div.et_pb_text_inner', 'a.et_pb_button'].map((k, i) => mod(k, {}, { y: i * 50 }));

// ---- equal ----
t('equal lists: every module compared, no problem', () => {
  const r = G.compareModules(page(), page());
  assert.strictEqual(r.checked, 6);
  assert.deepStrictEqual(r.problems, []);
});

// ---- a style off ----
t('a colour that is off is one problem naming the module, the property and both values', () => {
  const sw = page();
  sw[3].styles.color = 'rgb(18, 17, 17)';
  const r = G.compareModules(sw, page());
  assert.strictEqual(r.checked, 6);
  assert.strictEqual(r.problems.length, 1);
  assert.strictEqual(r.problems[0][0], 'style-color');
  assert.ok(/#3 div\.et_pb_module\.et_pb_text/.test(r.problems[0][1]), r.problems[0][1]);
  assert.ok(/rgb\(18, 17, 17\)/.test(r.problems[0][1]) && /rgb\(17, 17, 17\)/.test(r.problems[0][1]), 'both values are in the line');
});
t('every listed property is judged, each under its own code', () => {
  for (const p of G.PROPS) {
    const sw = page();
    sw[0].styles[p] = 'changed';
    const r = G.compareModules(sw, page());
    assert.deepStrictEqual(r.problems.map((x) => x[0]), ['style-' + p], p);
  }
  const must = ['color', 'background-color', 'font-family', 'font-weight', 'letter-spacing', 'text-transform', 'border-top-width', 'border-left-style', 'border-bottom-color', 'border-top-left-radius', 'padding-top', 'padding-left', 'box-shadow'];
  for (const p of must) assert.ok(G.PROPS.includes(p), p + ' is compared');
});

// ---- the box ----
t('a box off by 0.4 px passes, by 0.6 px fails, and a non-number fails', () => {
  const ok = page(); ok[1].box.w += 0.4; ok[2].box.x -= 0.5;
  assert.deepStrictEqual(G.compareModules(ok, page()).problems, []);
  for (const [dim, code] of [['x', 'box-x'], ['y', 'box-y'], ['w', 'box-width'], ['h', 'box-height']]) {
    const bad = page(); bad[4].box[dim] += 0.6;
    const r = G.compareModules(bad, page());
    assert.deepStrictEqual(r.problems.map((x) => x[0]), [code], dim);
  }
  const nan = page(); nan[0].box.h = NaN;
  assert.deepStrictEqual(G.compareModules(nan, page()).problems.map((x) => x[0]), ['box-height']);
});

// ---- missing and extra modules ----
t('a module missing from the switched page is named, and the others are still compared', () => {
  const sw = page(); sw.splice(3, 1);
  const r = G.compareModules(sw, page());
  assert.strictEqual(r.checked, 5);
  assert.deepStrictEqual(r.problems.map((x) => x[0]), ['module-missing']);
  assert.ok(/div\.et_pb_module\.et_pb_text/.test(r.problems[0][1]), r.problems[0][1]);
});
t('an extra module on the switched page is named', () => {
  const sw = page(); sw.splice(2, 0, mod('div.et_pb_extra'));
  const r = G.compareModules(sw, page());
  assert.strictEqual(r.checked, 6);
  assert.deepStrictEqual(r.problems.map((x) => x[0]), ['module-extra']);
  assert.ok(/et_pb_extra/.test(r.problems[0][1]));
});
t('a shifted list does not flood: one missing module early on is one problem, not one per following module', () => {
  const direct = []; for (let i = 0; i < 200; i++) direct.push(mod('div.et_pb_m' + (i % 7) + '_' + i, {}, { y: i * 10 }));
  const sw = clone(direct); sw.splice(5, 1);
  const r = G.compareModules(sw, direct);
  assert.strictEqual(r.problems.length, 1);
  assert.strictEqual(r.problems[0][0], 'module-missing');
  assert.strictEqual(r.checked, 199);
});
t('one list empty and the other not: everything is missing, nothing is compared', () => {
  const r = G.compareModules([], page());
  assert.strictEqual(r.checked, 0);
  assert.strictEqual(r.problems.length, 6);
  assert.ok(r.problems.every((x) => x[0] === 'module-missing'));
});

// ---- the empty run ----
t('an empty run is a failure, never a pass', () => {
  const r = G.compareModules([], []);
  assert.strictEqual(r.checked, 0);
  assert.deepStrictEqual(r.problems, []);
  const why = G.emptyRunProblems({ compared: r.checked });
  assert.strictEqual(why.length, 1);
  assert.ok(/nothing was compared/i.test(why[0]));
  assert.deepStrictEqual(G.emptyRunProblems({ compared: G.MIN_MODULES }), []);
});
t('a page with too few modules is a failure (a half-rendered page would pass with a handful)', () => {
  const why = G.pageProblems({ modules: page() });
  assert.strictEqual(why.length, 1);
  assert.ok(/too few|only 6/i.test(why[0][1]));
  assert.strictEqual(why[0][0], 'too-few-modules');
  const many = []; for (let i = 0; i < G.MIN_MODULES; i++) many.push(mod('div.et_pb_m' + i));
  assert.deepStrictEqual(G.pageProblems({ modules: many }), []);
  assert.strictEqual(G.pageProblems({ modules: [], hasRoot: false })[0][0], 'empty');
});

// ---- the PHP stage output ----
t('PHP stage output: check lines, info lines, noise and a stage with no check', () => {
  const out = [
    'Deprecated: something in /var/www/html/x.php',
    JSON.stringify({ type: 'check', ok: true, label: 'a' }),
    JSON.stringify({ type: 'check', ok: false, label: 'b', detail: 'was 1' }),
    JSON.stringify({ type: 'info', key: 'ids', value: { source: 5 } }),
    '{broken json',
    '',
  ].join('\n');
  const r = G.parsePhpOutput(out);
  assert.deepStrictEqual(r.checks.map((c) => [c.ok, c.label]), [[true, 'a'], [false, 'b']]);
  assert.deepStrictEqual(r.info.ids, { source: 5 });
  assert.deepStrictEqual(r.other, ['Deprecated: something in /var/www/html/x.php', '{broken json']);
  assert.strictEqual(G.parsePhpOutput('').checks.length, 0);
  assert.ok(G.stageProblems('build', { checks: [], info: {}, other: [] }, 0).length >= 1, 'a stage that reported no check is a failure');
  assert.deepStrictEqual(G.stageProblems('build', r, 0).map((x) => x), ['b' + ' (was 1)']);
  assert.ok(G.stageProblems('build', { checks: [{ ok: true, label: 'a' }], info: {}, other: ['PHP Fatal error: boom'] }, 255).some((x) => /exited 255/.test(x)), 'a non-zero exit is a failure even when every check passed');
});

// ---- one run at a time ----
t('other gates running: our own process is not another gate', () => {
  const out = '4242 node scripts/verify-pro-switch-design.cjs\n4300 node scripts/verify-kits.cjs --kits a\n4301 node /x/scripts/verify-recipes.cjs\n';
  assert.deepStrictEqual(G.parsePgrep(out, 4242).map((l) => l.split(' ')[0]), ['4300', '4301']);
  assert.deepStrictEqual(G.parsePgrep('4242 node scripts/verify-pro-switch-design.cjs\n', 4242), []);
  assert.deepStrictEqual(G.parsePgrep('', 1), []);
});

// ---- titles ----
t('run titles are `aied switch <runToken> <role>` and the roles are fixed', () => {
  assert.strictEqual(G.TITLE_PREFIX, 'aied switch ');
  assert.deepStrictEqual(G.ROLES, ['source', 'direct', 'mcp', 'changed', 'denied']);
});

// ---- the in-page collector, in a real Chromium on synthetic pages ----
(async () => {
  let chromium = null;
  const PW = process.env.PLAYWRIGHT_PATH || path.resolve(__dirname, '..', '..', 'layoutlab', 'node_modules', 'playwright');
  try { ({ chromium } = require(PW)); } catch (e) { console.log('SKIPPED the browser tests (Playwright not found at ' + PW + ')'); }
  if (chromium) {
    const html = (extra = '', color = '#111111', cls = '') => `<!doctype html><html><head><style>.et_pb_text_0{color:${color};padding:8px 4px 2px 1px;box-shadow:0 2px 4px #000}</style></head><body>
<div class="et-l et-l--post"><div class="et_pb_section et_pb_section_0 ${cls}"><div class="et_pb_row et_pb_row_0"><div class="et_pb_column et_pb_column_0"><div class="et_pb_module et_pb_text et_pb_text_0 ${cls}"><div class="et_pb_text_inner">Hello</div></div>${extra}</div></div></div></div></body></html>`;
    const browser = await chromium.launch();
    try {
      const collect = async (markup) => {
        const p = await browser.newPage({ viewport: { width: 800, height: 600 } });
        await p.setContent(markup);
        const r = await p.evaluate(G.collectModules, G.PROPS);
        await p.close();
        return r;
      };
      await tAsync('collector: a page with no .et-l--post root says so', async () => {
        const p = await browser.newPage();
        await p.setContent('<p class="et_pb_x">x</p>');
        const r = await p.evaluate(G.collectModules, G.PROPS);
        await p.close();
        assert.strictEqual(r.hasRoot, false);
        assert.deepStrictEqual(r.modules, []);
      });
      await tAsync('collector: identical pages are equal, every et_pb_ element is a module, numbered classes are normalised', async () => {
        const a = await collect(html());
        const b = await collect(html());
        assert.strictEqual(a.hasRoot, true);
        assert.strictEqual(a.modules.length, 5, a.modules.map((m) => m.key).join(' | '));
        assert.ok(a.modules.some((m) => m.key === 'div.et_pb_column.et_pb_column_N'), a.modules.map((m) => m.key).join(' | '));
        const r = G.compareModules(a.modules, b.modules);
        assert.strictEqual(r.checked, 5);
        assert.deepStrictEqual(r.problems, []);
        const text = a.modules.find((m) => /et_pb_text_N/.test(m.key));
        assert.strictEqual(text.styles['padding-top'], '8px');
        assert.strictEqual(text.styles['padding-left'], '1px');
        assert.ok(/rgb\(17, 17, 17\)/.test(text.styles.color), text.styles.color);
        assert.ok(/0px 2px 4px/.test(text.styles['box-shadow']), text.styles['box-shadow']);
      });
      await tAsync('collector: a colour one unit off is found', async () => {
        const a = await collect(html('', '#111111'));
        const b = await collect(html('', '#121111'));
        const r = G.compareModules(a.modules, b.modules);
        // The colour is inherited and a border colour defaults to currentColor, so they move with it; nothing else may.
        const codes = new Set(r.problems.map((x) => x[0]));
        assert.ok(codes.has('style-color'), [...codes].join(','));
        assert.ok([...codes].every((c) => c === 'style-color' || /^style-border-(top|right|bottom|left)-color$/.test(c)), [...codes].join(','));
      });
      await tAsync('collector: a module missing from one page is found', async () => {
        const a = await collect(html());
        const b = await collect(html('<div class="et_pb_module et_pb_button_module_wrapper">Go</div>'));
        const r = G.compareModules(a.modules, b.modules);
        // The extra module also makes its ancestors taller: those box differences are real too.
        const codes = r.problems.map((x) => x[0]);
        assert.strictEqual(codes.filter((c) => c === 'module-missing').length, 1);
        assert.ok(codes.every((c) => c === 'module-missing' || c === 'box-height'), codes.join(','));
      });
      await tAsync('collector: state classes that scripts add (animated, waypoint, sticky) do not change the key', async () => {
        const a = await collect(html());
        const b = await collect(html('', '#111111', 'et_animated et_pb_animated et-waypoint et_pb_sticky'));
        assert.deepStrictEqual(G.compareModules(a.modules, b.modules).problems, []);
        assert.deepStrictEqual(a.modules.map((m) => m.key), b.modules.map((m) => m.key));
      });
    } finally {
      await browser.close();
    }
  }
  console.log(`${n} tests passed`);
})().catch((e) => { console.log('FAIL ' + (e && e.stack ? e.stack : e)); process.exit(1); });
