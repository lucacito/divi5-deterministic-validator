'use strict';
/*
 * The card half of scripts/verify-kits.cjs for ONE opened page at ONE width, kept apart so a real Chromium can run it against a synthetic
 * page without the dev site (scripts/test-kit-card-run.cjs). It takes a Playwright `page` showing the kit sheet and, for each sheet card
 * (K.CARD_SLOTS), measures it at rest, measures the painted fill under it, hovers it with the real pointer and measures again, then lets the
 * pure comparisons of scripts/lib/kit-checks.cjs judge. It only reads the page (children hidden for the fill screenshot are restored).
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
 * @param {{ width:number, expects:Array<object>, hairline:?string }} o expects = K.expectCardFromAttrs of each card in K.CARD_SLOTS order;
 *        hairline = the design language's `border` colour (the decorative frame, not judged)
 * @returns {Promise<{ problems:Array<[string,string]>, observations:Array<object>, cardsChecked:number, layout:?string }>}
 */
async function checkCards(p, { width, expects, hairline }) {
  const problems = [];
  const slots = K.CARD_SLOTS;
  const arg = slotArgs();
  const rest = await p.evaluate(K.measureCards, arg);
  const count = K.cardCountProblems(rest.cards, slots);
  if (count.length) return { problems: count, observations: [], cardsChecked: 0, layout: null };

  // The painted fill under each card (and a strip of the band just above it): children hidden, shadow off, no transition (so it applies at once).
  await p.evaluate(() => document.querySelectorAll('[data-aied-card]').forEach((card) => {
    card.dataset.aiedTr = card.style.transition; card.dataset.aiedSh = card.style.boxShadow;
    card.style.transition = 'none'; card.style.boxShadow = 'none';
    card.querySelectorAll('*').forEach((n) => { n.dataset.aiedV = n.style.visibility; n.style.visibility = 'hidden'; });
  }));
  await p.waitForTimeout(50);
  const fills = []; const bands = [];
  for (const c of rest.cards) {
    const clip = K.cardSampleClip(c.rect, c.el);
    fills.push(K.pixelStats(K.decodePng(await p.screenshot({ clip, fullPage: true }))).mean);
    let band = null;
    try {
      const y = Math.round(c.rect.top) - 10;
      if (y >= 0) band = K.pixelStats(K.decodePng(await p.screenshot({ clip: { x: Math.max(0, Math.round(c.rect.left + c.rect.width * 0.25)), y, width: Math.max(1, Math.round(c.rect.width * 0.5)), height: 6 }, fullPage: true }))).mean;
    } catch (e) { band = null; }
    bands.push(band);
  }
  await p.evaluate(() => document.querySelectorAll('[data-aied-card]').forEach((card) => {
    card.querySelectorAll('*').forEach((n) => { n.style.visibility = n.dataset.aiedV || ''; delete n.dataset.aiedV; });
    card.style.boxShadow = card.dataset.aiedSh || ''; card.style.transition = card.dataset.aiedTr || '';
    delete card.dataset.aiedSh; delete card.dataset.aiedTr;
  }));
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
      await p.mouse.move(0, 0);
      await p.waitForTimeout(wait);
    }
    const cmp = K.compareCard(exp, slot.role, { rest: { el: c.el }, hover: hov && hov.found ? { el: hov.el } : null });
    const fill = fills[i];
    const contrast = K.cardContrastProblems(c, fill, hairline);
    const painted = K.paintedFillProblems(c.el, fill);
    const clip = K.cardClipProblems(slot, exp, c);
    // The page-wide scrollWidth finding is the same for every card: report it once (with the first card), not twelve times.
    const ov = [...K.cardOverflowProblems(c, 'rest', rest), ...(hov && hov.found ? K.cardOverflowProblems(hov, 'hover', hovAll) : [])].filter(([, d]) => i === 0 || !/scrollWidth/.test(d));
    for (const [code, detail] of [...cmp.problems, ...contrast.problems, ...painted, ...clip.problems, ...ov]) problems.push([code, `${slot.key} ${detail}`]);
    const band = bands[i];
    const sh = K.parseShadows(c.el.boxShadow)[0] || (hov && hov.found ? K.parseShadows(hov.el.boxShadow)[0] : null) || null;
    observations.push({
      width, key: slot.key, role: slot.role, carrier: slot.carrier,
      fill: K.hex(fill), band: band ? K.hex(band) : null, standOut: band ? K.round2(ratioOf({ ...fill, a: 1 }, { ...band, a: 1 })) : null,
      shadow: sh ? { state: K.parseShadows(c.el.boxShadow)[0] ? 'rest' : 'hover', onCard: true, value: K.parseShadows(c.el.boxShadow)[0] ? c.el.boxShadow : hov.el.boxShadow } : null,
      parentShadow: c.parentShadow && c.parentShadow !== 'none' ? c.parentShadow : null,
      clip: clip.info, radius: c.el.borderTopLeftRadius, contrast: contrast.info,
    });
  }

  const rows = rest.cards.filter((c, i) => slots[i].row).map((c) => c.rect);
  const row = K.columnRowProblems(rows, width);
  for (const [code, detail] of row.problems) problems.push([code, detail]);
  return { problems, observations, cardsChecked: slots.length, layout: row.layout };
}

module.exports = { checkCards, transitionMs };
