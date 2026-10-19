'use strict';
/*
 * The item half of scripts/verify-kits.cjs for ONE opened page at ONE width, kept apart (like kit-card-run.cjs) so a real Chromium can run
 * it against a synthetic page without the dev site (scripts/test-kit-card-run.cjs). It takes a Playwright `page` showing the kit sheet and,
 * for each item block of the sheet (K.ITEM_SLOTS: accordions and toggles), measures every item at rest, samples the painted fill under each
 * item, then CLICKS an item's title with the real pointer (an accordion's first closed item, a toggle's only item), waits for Divi's slide
 * and measures again: the clicked item must show the open values (or, for an open toggle, the closed ones), the previously open accordion
 * item must have closed, every answer must follow its item. The pure comparisons of scripts/lib/kit-checks.cjs judge. It only reads the
 * page (children hidden for the fill screenshot are restored); the click changes which item is open, which nothing after it relies on.
 */
const K = require('./kit-checks.cjs');
const { transitionMs } = require('./kit-card-run.cjs');

/** Divi slides an answer open or closed with a jQuery animation of 700 ms; this floor (plus a poll until the page stops changing) waits it out. */
const SLIDE_FLOOR_MS = 800;

const slotArgs = () => K.ITEM_SLOTS.map((s) => ({ key: s.key, carrier: s.carrier, titles: s.items.map((i) => i.title) }));

/**
 * The painted fill under every item of every measured block: the fixed and sticky elements (the logged-in preview's admin bar) and the
 * children of each item hidden, shadow and transition off, one screenshot of the item's inner box each. Everything is restored.
 * @returns {Promise<Array<Array<{r:number,g:number,b:number}|null>>>} per slot, per item
 */
async function paintItems(p, measured) {
  await p.evaluate(() => {
    document.querySelectorAll('body *').forEach((n) => {
      const pos = getComputedStyle(n).position;
      if (pos === 'fixed' || pos === 'sticky') { n.dataset.aiedIf = n.style.visibility; n.style.visibility = 'hidden'; }
    });
    document.querySelectorAll('[data-aied-item]').forEach((it) => {
      it.dataset.aiedIt = it.style.transition; it.dataset.aiedIs = it.style.boxShadow;
      it.style.transition = 'none'; it.style.boxShadow = 'none';
      it.querySelectorAll('*').forEach((n) => { if (n.dataset.aiedIf !== undefined) return; n.dataset.aiedIv = n.style.visibility; n.style.visibility = 'hidden'; });
    });
  });
  await p.waitForTimeout(50);
  const fills = [];
  for (const block of measured) {
    const row = [];
    for (const it of block.found ? block.items : []) {
      let mean = null;
      try { mean = K.pixelStats(K.decodePng(await p.screenshot({ clip: K.cardSampleClip(it.rect, it.el), fullPage: true }))).mean; } catch (e) { mean = null; }
      row.push(mean);
    }
    fills.push(row);
  }
  await p.evaluate(() => {
    document.querySelectorAll('[data-aied-item]').forEach((it) => {
      it.querySelectorAll('*').forEach((n) => { if (n.dataset.aiedIv === undefined) return; n.style.visibility = n.dataset.aiedIv || ''; delete n.dataset.aiedIv; });
      it.style.boxShadow = it.dataset.aiedIs || ''; it.style.transition = it.dataset.aiedIt || '';
      delete it.dataset.aiedIs; delete it.dataset.aiedIt;
    });
    document.querySelectorAll('[data-aied-if]').forEach((n) => { n.style.visibility = n.dataset.aiedIf || ''; delete n.dataset.aiedIf; });
  });
  await p.waitForTimeout(50);
  return fills;
}

/** Waits out the slide after a click, then polls until the block stops changing (its items' open state, height and answer visibility are the same twice in a row). */
async function settle(p, arg, i, floorMs) {
  let last = (await p.evaluate(K.measureItems, arg)).items[i];
  const wait = last.found ? Math.max(floorMs, Math.min(2000, Math.max(...last.items.map((it) => transitionMs(it.el)))) + 150) : floorMs;
  await p.waitForTimeout(wait);
  let prev = null;
  for (let k = 0; k < 24; k++) {
    last = (await p.evaluate(K.measureItems, arg)).items[i];
    const sig = last.found ? last.items.map((it) => [it.open, Math.round(it.rect.height), it.body.visible].join(':')).join('|') : 'gone';
    if (sig === prev) return last;
    prev = sig;
    await p.waitForTimeout(150);
  }
  return last;
}

/**
 * @param {import('playwright').Page} p the opened sheet page
 * @param {{ width:number, expects:Array<object>, lists:Array<Array<{title:string,open:boolean}>>, hairline:?string, slideFloorMs?:number }} o
 *        expects = K.expectItemFromAttrs of each block in K.ITEM_SLOTS order; lists = for each block the items the RESOLVED markup gives it
 *        (K.itemAttrs(...).items); hairline = the design language's `border` colour (the decorative frame, not judged)
 * @returns {Promise<{ problems:Array<[string,string]>, observations:Array<object>, itemsChecked:number }>}
 */
async function checkItems(p, { width, expects, lists, hairline, slideFloorMs = SLIDE_FLOOR_MS }) {
  const problems = [];
  const slots = K.ITEM_SLOTS;
  const arg = slotArgs();
  const rest = await p.evaluate(K.measureItems, arg);
  const count = K.itemCountProblems(rest.items, lists, slots);
  if (count.length) return { problems: count, observations: [], itemsChecked: 0 };

  // Phase 1, at rest: frame and state values, painted fill, contrast, overflow.
  const fills = await paintItems(p, rest.items);
  const judge = (slotIdx, block, fillRow, phase, page) => {
    const slot = slots[slotIdx];
    const info = [];
    block.items.forEach((it, j) => {
      const where = `${slot.key} item ${j + 1}`;
      const cmp = K.compareItem(expects[slotIdx], slot.role, it, phase);
      const painted = K.itemPaintedFillProblems(it.el, fillRow[j]);
      const contrast = K.itemContrastProblems(it, fillRow[j], hairline);
      const ov = [...K.itemOverflowProblems(it, phase, page)].filter(([, d]) => (slotIdx === 0 && j === 0) || !/scrollWidth/.test(d));
      for (const [code, detail] of [...cmp.problems, ...painted, ...contrast.problems, ...ov]) problems.push([code, `${where} ${detail}`]);
      info.push({ index: j, open: it.open, fill: fillRow[j] ? K.hex(fillRow[j]) : null, contrast: contrast.info });
    });
    return info;
  };
  const observations = slots.map((slot, i) => ({ width, key: slot.key, role: slot.role, carrier: slot.carrier, on: slot.on, rest: judge(i, rest.items[i], fills[i], 'rest', rest) }));

  // Phase 2: a real click on a closed item's title (an open toggle's too). Every click is made first and Divi's slide is waited out ONCE
  // (an animation moves the blocks below it, which Playwright's click already waits for), then each block is measured when it stops changing.
  const clicks = [];
  for (let i = 0; i < slots.length; i++) {
    const slot = slots[i];
    const before = (await p.evaluate(K.measureItems, arg)).items[i];
    const idx = K.clickTarget(slot, before);
    if (idx < 0) { problems.push(['item-click', `${slot.key} has no item to click on`]); continue; }
    try {
      const title = p.locator(`[data-aied-item="${slot.key}:${idx}"] .et_pb_toggle_title`).first();
      await title.evaluate((n) => n.scrollIntoView({ block: 'center' }));
      await title.click({ timeout: 8000 });
      clicks.push({ i, idx, before });
    } catch (e) {
      problems.push(['item-click', `${slot.key} item ${idx + 1} could not be clicked: ${(e && e.message ? e.message : String(e)).split('\n')[0]}`]);
    } finally {
      await p.mouse.move(Math.max(0, width - 2), 450); // parked at the right edge: (0,0) would open the admin bar's menu
    }
  }
  await p.waitForTimeout(slideFloorMs);
  for (const { i, idx, before } of clicks) {
    const slot = slots[i];
    const after = await settle(p, arg, i, Math.min(slideFloorMs, 100));
    for (const [code, detail] of K.itemClickProblems(slot, before, after, idx)) problems.push([code, `${slot.key} ${detail}`]);
    if (after.found) {
      after.items.forEach((it, j) => {
        for (const [code, detail] of K.compareItem(expects[i], slot.role, it, 'after click').problems) problems.push([code, `${slot.key} item ${j + 1} ${detail}`]);
      });
    }
    observations[i].click = { index: idx, before: before.items.map((it) => it.open), after: after.found ? after.items.map((it) => it.open) : null };
  }

  // Phase 3: with every click done and the page settled, the painted fill and contrast of the states the items are in now.
  const final = await p.evaluate(K.measureItems, arg);
  const fills2 = await paintItems(p, final.items);
  final.items.forEach((block, i) => {
    if (!block.found) return;
    block.items.forEach((it, j) => {
      const where = `${slots[i].key} item ${j + 1}`;
      const painted = K.itemPaintedFillProblems(it.el, fills2[i][j]);
      const contrast = K.itemContrastProblems(it, fills2[i][j], hairline);
      const ov = K.itemOverflowProblems(it, 'after click', final).filter(([, d]) => !/scrollWidth/.test(d));
      for (const [code, detail] of [...painted, ...contrast.problems, ...ov]) problems.push([code, `${where} (after click) ${detail}`]);
    });
    observations[i].final = block.items.map((it, j) => ({ index: j, open: it.open, fill: fills2[i][j] ? K.hex(fills2[i][j]) : null }));
  });
  if (final.scrollWidth > final.innerWidth) problems.push(['overflow', `after the clicks: scrollWidth ${final.scrollWidth} > innerWidth ${final.innerWidth}`]);
  return { problems, observations, itemsChecked: slots.length };
}

module.exports = { checkItems, SLIDE_FLOOR_MS };
