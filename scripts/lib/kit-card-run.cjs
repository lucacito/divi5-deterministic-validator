'use strict';
/*
 * The card half of scripts/verify-kits.cjs for ONE opened page at ONE width, kept apart so a real Chromium can run it against a synthetic
 * page without the dev site (scripts/test-kit-card-run.cjs). It takes a Playwright `page` showing the kit sheet and, for each sheet card
 * (K.CARD_SLOTS), measures it at rest, measures the painted fill under it, hovers it with the real pointer and measures again, then lets the
 * pure comparisons of scripts/lib/kit-checks.cjs judge. A card with a ground (inverted / accent) is judged on its painted fill against the
 * RESOLVED ground fill (ground-fill); each kit button inside a button panel is hovered too and judged on the painted panel (panel-button);
 * the three row cards give the featured card's prominence against its neighbours (featured). It only reads the page (children hidden for
 * the fill screenshot are restored).
 */
const K = require('./kit-checks.cjs');
const { ratioOf } = require('./recipe-checks.cjs');

/** Longest transition (duration + delay) in ms of a computed style's `transition*` lists like "0.3s, 0s". */
function transitionMs(st) {
  const list = (v) => String(v || '0s').split(',').map((x) => { const t = x.trim(); return t.endsWith('ms') ? parseFloat(t) : parseFloat(t) * 1000; });
  const d = list(st.transitionDuration); const dl = list(st.transitionDelay);
  return Math.max(0, ...d.map((v, i) => (v || 0) + (dl[i % dl.length] || 0)));
}

const slotArgs = () => K.CARD_SLOTS.map((s) => ({ key: s.key, heading: s.heading, carrier: s.carrier }));

/**
 * @param {import('playwright').Page} p the opened sheet page
 * @param {{ width:number, expects:Array<object>, buttonExpects:Array<Array<object>>, hairline:?string }} o expects = K.expectCardFromAttrs of
 *        each card in K.CARD_SLOTS order; buttonExpects = for each card the K.expectFromAttrs of the kit buttons inside it (usually []);
 *        hairline = the design language's `border` colour (the decorative frame, not judged)
 * @returns {Promise<{ problems:Array<[string,string]>, observations:Array<object>, cardsChecked:number, layout:?string }>}
 */
async function checkCards(p, { width, expects, buttonExpects, hairline }) {
  const problems = [];
  const slots = K.CARD_SLOTS;
  const arg = slotArgs();
  const rest = await p.evaluate(K.measureCards, arg);
  const count = K.cardCountProblems(rest.cards, slots);
  if (count.length) return { problems: count, observations: [], cardsChecked: 0, layout: null };

  // The painted fill under each card (and a strip of the band just above it): children hidden, shadow off, no transition (so it applies at once).
  // Fixed and sticky elements (the logged-in preview's WordPress admin bar and the menu it opens under the pointer) are painted over the
  // page wherever it is scrolled, so they would land inside a card's box in the screenshot: they are hidden too (found by the live smoke).
  // Fixed and sticky first (each remembers its own visibility once), then the card children that are not already tagged: an element that is
  // both inside a card and fixed/sticky must be restored from ONE saved value, not from the "hidden" the other pass left behind.
  await p.evaluate(() => {
    document.querySelectorAll('body *').forEach((n) => {
      const pos = getComputedStyle(n).position;
      if (pos === 'fixed' || pos === 'sticky') { n.dataset.aiedF = n.style.visibility; n.style.visibility = 'hidden'; }
    });
    document.querySelectorAll('[data-aied-card]').forEach((card) => {
      card.dataset.aiedTr = card.style.transition; card.dataset.aiedSh = card.style.boxShadow;
      card.style.transition = 'none'; card.style.boxShadow = 'none';
      card.querySelectorAll('*').forEach((n) => { if (n.dataset.aiedF !== undefined) return; n.dataset.aiedV = n.style.visibility; n.style.visibility = 'hidden'; });
    });
  });
  await p.waitForTimeout(50);
  const fills = []; const bands = []; const boxes = [];
  for (let i = 0; i < rest.cards.length; i++) {
    const c = rest.cards[i];
    const clip = K.cardSampleClip(c.rect, c.el);
    fills.push(K.pixelStats(K.decodePng(await p.screenshot({ clip, fullPage: true }))).mean);
    // The whole box (frame included) of a row card: its prominence against the band is the featured check.
    boxes.push(slots[i].row ? K.decodePng(await p.screenshot({ clip: { x: Math.max(0, Math.round(c.rect.left)), y: Math.max(0, Math.round(c.rect.top)), width: Math.max(1, Math.round(c.rect.width)), height: Math.max(1, Math.round(c.rect.height)) }, fullPage: true })) : null);
    let band = null;
    try {
      const y = Math.round(c.rect.top) - 10;
      if (y >= 0) band = K.pixelStats(K.decodePng(await p.screenshot({ clip: { x: Math.max(0, Math.round(c.rect.left + c.rect.width * 0.25)), y, width: Math.max(1, Math.round(c.rect.width * 0.5)), height: 6 }, fullPage: true }))).mean;
    } catch (e) { band = null; }
    bands.push(band);
  }
  await p.evaluate(() => {
    document.querySelectorAll('[data-aied-card]').forEach((card) => {
      card.querySelectorAll('*').forEach((n) => { n.style.visibility = n.dataset.aiedV || ''; delete n.dataset.aiedV; });
      card.style.boxShadow = card.dataset.aiedSh || ''; card.style.transition = card.dataset.aiedTr || '';
      delete card.dataset.aiedSh; delete card.dataset.aiedTr;
    });
    document.querySelectorAll('[data-aied-f]').forEach((n) => { n.style.visibility = n.dataset.aiedF || ''; delete n.dataset.aiedF; });
  });
  await p.waitForTimeout(50);

  const observations = [];
  for (let i = 0; i < slots.length; i++) {
    const slot = slots[i];
    const exp = expects[i];
    const c = rest.cards[i];
    const wait = Math.min(2000, transitionMs(c.el)) + 150;
    let hovAll = null; let hov = null;
    try {
      await p.locator(`[data-aied-card="${slot.key}"]`).first().hover({ timeout: 8000 });
      await p.waitForTimeout(wait);
      hovAll = await p.evaluate(K.measureCards, arg);
      hov = hovAll.cards[i];
    } catch (e) {
      problems.push(['card-hover', `${slot.key} could not be hovered: ${(e && e.message ? e.message : String(e)).split('\n')[0]}`]);
    } finally {
      await p.mouse.move(Math.max(0, width - 2), 450); // parked at the right edge: (0,0) would open the admin bar's menu
      await p.waitForTimeout(wait);
    }
    const cmp = K.compareCard(exp, slot.role, { rest: { el: c.el }, hover: hov && hov.found ? { el: hov.el } : null });
    const fill = fills[i];
    const contrast = K.cardContrastProblems(c, fill, hairline, slot.ground);
    // A ground is judged against the fill the RESOLVED markup says (ground-fill); a plain card against its own computed fill (card-fill).
    const painted = slot.ground ? K.groundFillProblems(exp, fill) : K.paintedFillProblems(c.el, fill);
    const clip = K.cardClipProblems(slot, exp, c);
    // The page-wide scrollWidth finding is the same for every card: report it once (with the first card), not once per card.
    const ov = [...K.cardOverflowProblems(c, 'rest', rest), ...(hov && hov.found ? K.cardOverflowProblems(hov, 'hover', hovAll) : [])].filter(([, d]) => i === 0 || !/scrollWidth/.test(d));
    for (const [code, detail] of [...cmp.problems, ...contrast.problems, ...painted, ...clip.problems, ...ov]) problems.push([code, `${slot.key} ${detail}`]);

    // The kit buttons inside the card (a button panel): each hovered with the real pointer, judged on the painted panel.
    const buttonInfo = [];
    if (slot.buttons || (c.buttons && c.buttons.length)) {
      const bx = (buttonExpects && buttonExpects[i]) || [];
      if (c.buttons.length !== slot.buttons || bx.length !== slot.buttons) {
        problems.push(['panel-button', `${slot.key} ${c.buttons.length} buttons rendered and ${bx.length} expectations, expected ${slot.buttons} of each`]);
      } else {
        for (let j = 0; j < c.buttons.length; j++) {
          const b = c.buttons[j];
          const bw = Math.min(2000, Math.max(transitionMs(b.el), transitionMs(b.wrap))) + 150;
          let hb = null; let hAll = null;
          try {
            await p.locator(`[data-aied-pb="${slot.key}:${j}"]`).first().hover({ timeout: 8000 });
            await p.waitForTimeout(bw);
            hAll = await p.evaluate(K.measureCards, arg);
            hb = hAll.cards[i].found ? hAll.cards[i].buttons[j] : null;
          } catch (e) {
            problems.push(['panel-button', `${slot.key}:${j} could not be hovered: ${(e && e.message ? e.message : String(e)).split('\n')[0]}`]);
          } finally {
            await p.mouse.move(Math.max(0, width - 2), 450);
            await p.waitForTimeout(bw);
          }
          const res = K.panelButtonProblems(bx[j], { rest: { el: b.el, wrap: b.wrap }, hover: hb ? { el: hb.el, wrap: hb.wrap } : null }, fill);
          const bov = [...K.overflowProblems(b, 'rest', rest), ...(hb ? K.overflowProblems(hb, 'hover', hAll) : [])].filter(([, d]) => !/scrollWidth/.test(d));
          for (const [code, detail] of [...res.problems, ...bov]) problems.push([code, `${slot.key}:${j} ${detail}`]);
          buttonInfo.push({ index: j, contrast: res.info });
        }
      }
    }
    const band = bands[i];
    const sh = K.parseShadows(c.el.boxShadow)[0] || (hov && hov.found ? K.parseShadows(hov.el.boxShadow)[0] : null) || null;
    observations.push({
      width, key: slot.key, role: slot.role, carrier: slot.carrier, ground: slot.ground,
      fill: K.hex(fill), band: band ? K.hex(band) : null, standOut: band ? K.round2(ratioOf({ ...fill, a: 1 }, { ...band, a: 1 })) : null,
      shadow: sh ? { state: K.parseShadows(c.el.boxShadow)[0] ? 'rest' : 'hover', onCard: true, value: K.parseShadows(c.el.boxShadow)[0] ? c.el.boxShadow : hov.el.boxShadow } : null,
      parentShadow: c.parentShadow && c.parentShadow !== 'none' ? c.parentShadow : null,
      clip: clip.info, radius: c.el.borderTopLeftRadius, contrast: contrast.info,
      buttons: buttonInfo.length ? buttonInfo : undefined,
    });
  }

  // The featured plan: the accent middle card's prominence (mean painted luminance difference between its box and the band, per pixel)
  // against the larger of its two row neighbours.
  const rowIdx = slots.map((s, i) => (s.row ? i : -1)).filter((i) => i >= 0);
  const featIdx = rowIdx.filter((i) => slots[i].featured);
  if (featIdx.length !== 1) problems.push(['featured', `the sheet must have exactly one featured card, found ${featIdx.length}`]);
  else {
    const fi = featIdx[0];
    const prom = (i) => (bands[i] && boxes[i] ? K.boxProminence(boxes[i], bands[i]) : null);
    const v = { featured: prom(fi), neighbours: rowIdx.filter((i) => i !== fi).map(prom) };
    for (const [code, detail] of K.featuredProblems(v)) problems.push([code, `${slots[fi].key} ${detail}`]);
    const larger = Math.max(...v.neighbours.filter((x) => typeof x === 'number'));
    const obs = observations[fi];
    if (obs) obs.featured = { ...v, ratio: Number.isFinite(larger) && larger > 0 && v.featured !== null ? K.round2(v.featured / larger) : null };
  }

  const rows = rest.cards.filter((c, i) => slots[i].row).map((c) => c.rect);
  const row = K.columnRowProblems(rows, width);
  for (const [code, detail] of row.problems) problems.push([code, detail]);
  return { problems, observations, cardsChecked: slots.length, layout: row.layout };
}

module.exports = { checkCards, transitionMs };
