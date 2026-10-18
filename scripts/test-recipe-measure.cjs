#!/usr/bin/env node
'use strict';
/* Live proof (a real Chromium, synthetic pages, NOT the dev site) that the stacking/overflow/empty measurement of
 * scripts/verify-recipes.cjs fires when it should and stays quiet when it should. Needs Playwright (../layoutlab).
 * Class names are the ones Divi 5.15.1 renders (task 2 report): top-level row `et_pb_row` > `et_pb_column`; nested `divi/row`
 * `et_pb_row et_pb_row_nested` > `et_pb_column`; nested `divi/row-inner` `et_pb_row_inner` > `et_pb_column_inner`. */
const assert = require('assert');
const path = require('path');
const { measureInPage } = require('./lib/recipe-checks.cjs');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(__dirname, '..', '..', 'layoutlab', 'node_modules', 'playwright');
const { chromium } = require(PW);

const col = (cls, style) => `<div class="${cls}" style="${style}">text</div>`;
const flex = 'display:flex';
const stack = 'display:block';
const colStyle = (mode) => (mode === 'flex' ? 'width:150px;height:40px' : 'display:block;width:auto;height:40px');
const row = (rowCls, colCls, mode) => `<div class="${rowCls}" style="${mode === 'flex' ? flex : stack}">${col(colCls, colStyle(mode))}${col(colCls, colStyle(mode))}</div>`;
const page = (inner) => `<div class="et-l--post"><div class="et_pb_section" style="min-height:60px">hello<div class="et_pb_row"><div class="et_pb_column">${inner}</div></div></div></div>`;

(async () => {
  const browser = await chromium.launch();
  const run = async (html, w = 400) => {
    const p = await browser.newPage({ viewport: { width: w, height: 800 } });
    await p.setContent(html);
    const r = await p.evaluate(measureInPage);
    await p.close();
    return r;
  };
  try {
    // top-level row
    const topBad = await run(`<div class="et-l--post"><div class="et_pb_section">hi${row('et_pb_row', 'et_pb_column', 'flex')}</div></div>`);
    assert.strictEqual(topBad.sideBySide.length, 1, 'top-level side-by-side row is reported');
    assert.strictEqual(topBad.sideBySide[0].nested, false);
    assert.strictEqual((await run(`<div class="et-l--post"><div class="et_pb_section">hi${row('et_pb_row', 'et_pb_column', 'stack')}</div></div>`)).sideBySide.length, 0, 'stacked top-level row passes');

    // nested divi/row-inner (et_pb_row_inner / et_pb_column_inner: NO et_pb_row class)
    const innerBad = await run(page(row('et_pb_row_inner', 'et_pb_column_inner', 'flex')));
    assert.strictEqual(innerBad.sideBySide.length, 1, 'nested row-inner side by side is reported');
    assert.strictEqual(innerBad.sideBySide[0].nested, true);
    assert.strictEqual(innerBad.sideBySide[0].columns, 2);
    assert.strictEqual((await run(page(row('et_pb_row_inner', 'et_pb_column_inner', 'stack')))).sideBySide.length, 0, 'stacked nested row-inner passes');

    // nested divi/row (et_pb_row et_pb_row_nested > et_pb_column)
    const nestedBad = await run(page(row('et_pb_row et_pb_row_nested', 'et_pb_column', 'flex')));
    assert.strictEqual(nestedBad.sideBySide.length, 1, 'nested divi/row side by side is reported');
    assert.strictEqual((await run(page(row('et_pb_row et_pb_row_nested', 'et_pb_column', 'stack')))).sideBySide.length, 0, 'stacked nested divi/row passes');

    // a single-column nested row is not a stacking problem
    assert.strictEqual((await run(page(`<div class="et_pb_row_inner">${col('et_pb_column_inner', 'height:40px')}</div>`))).sideBySide.length, 0);

    // Theme Builder header/footer rows outside .et-l--post are never measured
    const tb = await run(`<div class="et-l--header">${row('et_pb_row', 'et_pb_column', 'flex')}</div><div class="et-l--post"><div class="et_pb_section">hi</div></div>`);
    assert.strictEqual(tb.sideBySide.length, 0);

    // empty, overflow, no content root
    assert.strictEqual((await run('<div class="et-l--post"><div class="et_pb_section" style="height:100px"></div></div>')).visible, false);
    assert.strictEqual((await run('<div class="et-l--post"><div class="et_pb_section" style="width:900px;height:50px">hi</div></div>')).overflow, true);
    assert.strictEqual((await run('<div>hi</div>')).hasRoot, false);
    console.log('OK recipe measurement: top-level, nested divi/row and nested divi/row-inner stacking, Theme Builder ignored, empty, overflow, no root');
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error('FAIL ' + (e && e.message ? e.message : e)); process.exit(1); });
