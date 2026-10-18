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

    // text over a picture: the heading's lines against an image module box
    const img = (style) => `<div class="et_pb_image" style="position:relative;${style}"><img style="display:block;width:100%;height:100%" alt="" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="></div>`;
    const sec = (inner) => `<div class="et-l--post"><div class="et_pb_section">${inner}</div></div>`;
    const over = await run(sec(img('width:200px;height:120px') + '<h2 style="margin:-40px 0 0 0;position:relative">Overlapping headline</h2>'));
    assert.strictEqual(over.overlaps.length, 1, 'a heading pulled up over an image is reported');
    assert.strictEqual(over.overlaps[0].text, 'Overlapping headline');
    assert.strictEqual((await run(sec(img('width:200px;height:120px') + '<h2 style="margin:20px 0 0 0">Clear headline</h2>'))).overlaps.length, 0, 'a heading below the image passes');
    assert.strictEqual((await run(sec(img('width:200px;height:120px') + '<h2 style="margin:-3px 0 0 0">Touching headline</h2>'))).overlaps.length, 0, 'a 3 px touch is below the 4 px threshold');
    assert.strictEqual((await run(sec(img('width:200px;height:120px') + '<h2 style="margin:-40px 0 0 0;opacity:0">Hidden headline</h2>'))).overlaps.length, 0, 'invisible text is skipped');
    assert.strictEqual((await run(sec('<div class="et_pb_image" style="width:200px;height:120px"><p>Caption inside the image module</p></div>'))).overlaps.length, 0, 'text inside the image module is not an overlap');
    assert.strictEqual((await run(`<div class="et-l--header">${img('width:200px;height:120px')}<h2 style="margin:-40px 0 0 0;position:relative">Header headline</h2></div><div class="et-l--post"><div class="et_pb_section">hi</div></div>`)).overlaps.length, 0, 'the Theme Builder header is not measured');
    // computed text contrast (threshold 3.0:1 against the effective SOLID background; an unknown background is skipped)
    const cs = (style, inner) => `<div class="et-l--post"><div class="et_pb_section" style="${style}">${inner}</div></div>`;
    const bad = await run(cs('background:#0b1020', '<p style="color:#333333;margin:0">Dark grey on near-black</p>'));
    assert.strictEqual(bad.lowContrast.length, 1, 'dark grey text on a near-black section is reported');
    assert.strictEqual(bad.lowContrast[0].text, 'Dark grey on near-black');
    assert.ok(bad.lowContrast[0].ratio < 3, 'the reported ratio is under 3.0');
    assert.strictEqual((await run(cs('background:#0b1020', '<p style="color:#f2f4fa;margin:0">Light on near-black</p>'))).lowContrast.length, 0, 'light text on a dark section passes');
    assert.strictEqual((await run(cs('background:#0b1020', '<p style="color:#666666;margin:0">Default grey</p>'))).lowContrast.length, 0, 'Divi default grey on glass-dark is 3.3:1: above the forgiving 3.0 threshold (documented limit of the check)');
    assert.strictEqual((await run(cs('background:#ffffff', '<p style="color:#cccccc;margin:0">Pale grey on white</p>'))).lowContrast.length, 1, 'pale grey on white is reported');
    // the nearest solid background wins: a dark card inside a light section
    assert.strictEqual((await run(cs('background:#ffffff', '<div style="background:#111111"><p style="color:#eeeeee;margin:0">On the card</p></div>'))).lowContrast.length, 0, 'the card background, not the section, is the background');
    assert.strictEqual((await run(cs('background:#ffffff', '<div style="background:#111111"><p style="color:#222222;margin:0">Dark on the card</p></div>'))).lowContrast.length, 1, 'dark text on a dark card inside a light section is reported');
    // a translucent layer is stacked over the solid colour below it, not mistaken for the background
    const glass = 'background:rgba(255,255,255,0.06)';
    assert.strictEqual((await run(cs('background:#0b1020', `<div style="${glass}"><p style="color:#333333;margin:0">Dark on glass</p></div>`))).lowContrast.length, 1, 'dark text on glass over a dark section is reported');
    assert.strictEqual((await run(cs('background:#0b1020', `<div style="${glass}"><p style="color:#f2f4fa;margin:0">Light on glass</p></div>`))).lowContrast.length, 0, 'light text on glass over a dark section passes');
    // an unknown background (image or gradient above the text before a solid colour) is skipped, never guessed
    const skipped = await run(cs('background-image:linear-gradient(#000,#111)', '<p style="color:#222222;margin:0">Over a gradient</p>'));
    assert.strictEqual(skipped.lowContrast.length, 0, 'text over a gradient is skipped');
    assert.strictEqual(skipped.contrastSkipped, 1, 'and counted as skipped');
    assert.strictEqual((await run(cs('background:#000000', '<div style="background-image:url(data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==)"><p style="color:#222222;margin:0">Over a photo</p></div>'))).lowContrast.length, 0, 'an image between the text and the solid colour makes the background unknown');
    // text nobody can see is not measured
    assert.strictEqual((await run(cs('background:#0b1020', '<p style="color:#333333;opacity:0;margin:0">Transparent</p><p style="color:#333333;visibility:hidden;margin:0">Hidden</p><p style="color:#333333;font-size:0;margin:0">Zero size</p>'))).lowContrast.length, 0, 'invisible text is skipped');
    assert.strictEqual((await run(`<div class="et-l--header" style="background:#0b1020"><p style="color:#333333">Header text</p></div><div class="et-l--post"><div class="et_pb_section">hi</div></div>`)).lowContrast.length, 0, 'the Theme Builder header is not measured');
    assert.strictEqual((await run(cs('background:#0b1020', '<a style="display:block;width:10px;height:10px;overflow:hidden;text-indent:-9999px;color:#333333">1</a>'))).lowContrast.length, 0, 'a number pushed off the page by a negative text-indent (the slider dot) is skipped');
    assert.strictEqual((await run(cs('background:#0b1020', '<div style="width:50px;height:10px;overflow:hidden"><p style="color:#333333;margin:0;padding-top:40px">Clipped away</p></div>'))).lowContrast.length, 0, 'text clipped away completely by an overflow hidden box is skipped');
    assert.strictEqual((await run(cs('background:#0b1020', '<div style="width:300px;height:60px;overflow:hidden"><p style="color:#333333;margin:0">Visible inside the box</p></div>'))).lowContrast.length, 1, 'text that shows inside an overflow hidden box is still measured');
    // scrim over a photo: the worst of white and black beneath the scrim decides; a bare photo or a multi-colour gradient stays unknown (counted)
    const PHOTO = 'url(data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==)';
    const scrim = (a) => `background-image:linear-gradient(rgba(7,11,26,${a}),rgba(7,11,26,${a})),${PHOTO};background-color:#000`;
    const onScrim = await run(cs(scrim(0.7), '<p style="color:#f2f4fa;margin:0">Light on the scrim</p>'));
    assert.strictEqual(onScrim.lowContrast.length + onScrim.warn.length, 0, 'light text on a 70% dark scrim passes in both worst cases');
    assert.strictEqual(onScrim.contrastChecked, 1, 'and is measured, not skipped');
    assert.strictEqual(onScrim.skippedUnknown, 0);
    const captcha = await run(cs(scrim(0.7), '<p style="color:#666666;margin:0">12 + 9 =</p>'));
    assert.strictEqual(captcha.lowContrast.length, 1, 'Divi default grey on a 70% dark scrim over a photo is a FAIL (worst case over white)');
    assert.strictEqual(captcha.lowContrast[0].scrim, true, 'the item says it was a scrim worst case');
    assert.ok(captcha.lowContrast[0].ratio < 3, 'and the ratio is under 3.0');
    assert.strictEqual((await run(cs(scrim(0.7), '<p style="color:#bbbbbb;margin:0">Mid grey on the scrim</p>'))).warn.length, 1, 'a mid grey over the worst case lands in the advisory tier');
    const nested = await run(cs(`background-image:${PHOTO};background-color:#000`, `<div style="background:rgba(7,11,26,0.7)"><p style="color:#666666;margin:0">On a translucent card over a photo</p></div>`));
    assert.strictEqual(nested.lowContrast.length, 1, 'a translucent colour over a photo is a scrim too');
    const bare = await run(cs(`background-image:${PHOTO};background-color:#000`, '<p style="color:#222222;margin:0">On a bare photo</p>'));
    assert.strictEqual(bare.lowContrast.length + bare.warn.length, 0, 'a bare photo is never judged');
    assert.strictEqual(bare.skippedUnknown, 1, 'it is counted as unknown');
    assert.strictEqual(bare.contrastChecked, 0);
    const multi = await run(cs(`background-image:linear-gradient(#000,#fff)`, '<p style="color:#222222;margin:0">On a two-colour gradient</p>'));
    assert.strictEqual(multi.skippedUnknown, 1, 'a multi-colour gradient is unknown');
    assert.strictEqual((await run(cs(`background-image:linear-gradient(rgba(7,11,26,1),rgba(7,11,26,1))`, '<p style="color:#666666;margin:0">On an opaque one-colour gradient</p>'))).warn.length, 1, 'an opaque one-colour gradient is a solid background');
    // advisory tier: 3.0 up to 4.5 warns for small text only, never fails
    const grey = await run(cs('background:#0b1020', '<p style="color:#666666;margin:0">Default grey, small</p>'));
    assert.strictEqual(grey.lowContrast.length, 0, 'default grey on glass-dark is not a failure');
    assert.strictEqual(grey.warn.length, 1, 'but it is an advisory warning');
    assert.strictEqual(grey.warn[0].text, 'Default grey, small');
    assert.strictEqual((await run(cs('background:#0b1020', '<p style="color:#666666;margin:0;font-size:24px">Default grey, large</p>'))).warn.length, 0, 'large text has no advisory band (its floor is 3.0)');
    assert.strictEqual((await run(cs('background:#0b1020', '<p style="color:#666666;margin:0;font-size:19px;font-weight:700">Default grey, bold</p>'))).warn.length, 0, 'bold text from 18.66px is large');
    assert.strictEqual((await run(cs('background:#0b1020', '<p style="color:#f2f4fa;margin:0">Fine</p>'))).warn.length, 0, 'a passing text warns nothing');
    // the counts the report prints
    const counts = await run(cs('background:#0b1020', '<p style="color:#f2f4fa;margin:0">Seen</p><p style="color:#f2f4fa;opacity:0;margin:0">Faded</p><a style="display:block;width:10px;height:10px;overflow:hidden;text-indent:-9999px">1</a>'));
    assert.deepStrictEqual([counts.contrastChecked, counts.skippedHidden, counts.skippedOffPage, counts.skippedUnknown], [1, 1, 1, 0], 'checked, hidden and off-page are counted separately');
    const all = await run(cs('background:#0b1020', '<p style="color:#f2f4fa;margin:0">Listed</p>'), 400);
    assert.strictEqual(all.contrastChecked, 1, 'a measured element is counted');
    console.log('OK recipe measurement: top-level, nested divi/row and nested divi/row-inner stacking, Theme Builder ignored, empty, overflow, no root, text over a picture, computed text contrast');
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error('FAIL ' + (e && e.message ? e.message : e)); process.exit(1); });
