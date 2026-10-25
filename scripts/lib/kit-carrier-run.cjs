'use strict';
/*
 * The carrier half of scripts/verify-kits.cjs for ONE opened page at ONE width (carriers adapter, Task 6), kept apart (like kit-card-run.cjs and kit-rule-run.cjs) so a real
 * Chromium can run it against a synthetic page without the dev site (scripts/test-kit-card-run.cjs). It takes a Playwright `page` showing the kit sheet and, for each
 * slot of K.CARRIER_SLOTS (a testimonial card on bg and panel on surface, a blurb chip on surface and accent, a counter rule-strong on accent and rule on bg, a
 * rule-accent divider on bg and surface):
 *  - measures it at rest (K.measureCarriers), the painted fill (a testimonial's card, a blurb's icon: the children, the glyph and the `::before` hidden, the shadow off)
 *    or the painted band behind it (a counter's or a divider's line hidden, a blurb's chip hidden), with the admin bar and every fixed or sticky element hidden;
 *  - hovers a testimonial with the real pointer (a card lifts, a panel stays still), with the one bounded retry of the card run;
 *  - lets the pure comparisons of scripts/lib/kit-checks.cjs judge: carrier, testimonial-fill|frame|radius|padding|shadow|hover|quote|contrast, blurb-chip-radius|fill|glyph|contrast,
 *    counter-rule-style|width|colour|contrast, accent-rule-style|width|colour|contrast (the accent mark against `accentFloor`, the number the PHP exports) and overflow.
 * It only reads the page (everything hidden for a sample is restored).
 */
const K = require('./kit-checks.cjs');
const { transitionMs, hoverJudged } = require('./kit-card-run.cjs');

/** Hides every fixed and sticky element (the logged-in preview's admin bar and the menu it opens); restoreFixed undoes it. */
function hideFixed() {
  document.querySelectorAll('body *').forEach((n) => {
    const pos = getComputedStyle(n).position;
    if (pos === 'fixed' || pos === 'sticky') { n.dataset.aiedXf = n.style.visibility; n.style.visibility = 'hidden'; }
  });
}
function restoreFixed() {
  document.querySelectorAll('[data-aied-xf]').forEach((n) => { n.style.visibility = n.dataset.aiedXf || ''; delete n.dataset.aiedXf; });
}

const wholeBox = (r) => ({ x: Math.max(0, Math.round(r.left)), y: Math.max(0, Math.round(r.top)), width: Math.max(1, Math.round(r.width)), height: Math.max(1, Math.round(r.height)) });
/** The strip along the top of a rule's box (where its line is drawn), the middle half of its width, at most 6px tall. */
const stripBox = (r) => ({ x: Math.max(0, Math.round(r.left + r.width * 0.25)), y: Math.max(0, Math.round(r.top)), width: Math.max(1, Math.round(r.width * 0.5)), height: Math.max(1, Math.round(Math.min(r.height, 6))) });

async function grab(p, clip) {
  try { return K.pixelStats(K.decodePng(await p.screenshot({ clip, fullPage: true }))).mean; } catch (e) { return null; }
}

const STYLE_ID = 'aied-carrier-sample';

/**
 * @param {import('playwright').Page} p the opened sheet page
 * @param {{ width:number, expects:Array<object>, hairline:?string, accentFloor:?number }} o expects = K.expectCarrierFromAttrs of each slot in K.CARRIER_SLOTS order (read from the
 *        RESOLVED markup); hairline = the design language's decorative `border` colour; accentFloor = the number the offline sheet exports
 * @returns {Promise<{ problems:Array<[string,string]>, observations:Array<object>, carriersChecked:number }>}
 */
async function checkCarriers(p, { width, expects, hairline = null, accentFloor = null }) {
  const slots = K.CARRIER_SLOTS;
  const arg = slots.map((s) => ({ key: s.key, carrier: s.carrier, text: s.text }));
  const rest = await p.evaluate(K.measureCarriers, arg);
  const count = K.carrierCountProblems(rest.carriers, slots);
  if (count.length) return { problems: count, observations: [], carriersChecked: 0 };

  // Painted samples. Step 1: a testimonial's card fill and a blurb's icon fill (its children, glyph and the module's ::before hidden, shadow off, no transition).
  await p.evaluate(hideFixed);
  await p.evaluate((id) => {
    const st = document.createElement('style'); st.id = id;
    st.textContent = '[data-aied-carrier]::before, [data-aied-carrier]::after { visibility: hidden !important; }';
    document.head.appendChild(st);
    // The glyph of a blurb's icon is text or a pseudo element of the target itself: transparent text and hidden pseudos leave only the fill to sample.
    document.querySelectorAll('[data-aied-carrier]').forEach((n) => { n.dataset.aiedXt = n.style.transition; n.style.transition = 'none'; n.dataset.aiedXs = n.style.boxShadow; n.style.boxShadow = 'none'; n.dataset.aiedXk = n.style.color; n.style.setProperty('color', 'transparent', 'important'); });
    document.querySelectorAll('[data-aied-carrier]').forEach((n) => {
      if (n.classList.contains('et_pb_testimonial')) n.querySelectorAll('*').forEach((c) => { if (c.dataset.aiedXf !== undefined) return; c.dataset.aiedXc = c.style.visibility; c.style.visibility = 'hidden'; });
    });
  }, STYLE_ID);
  await p.waitForTimeout(50);
  const fills = [];
  for (let i = 0; i < slots.length; i++) {
    const c = rest.carriers[i];
    fills.push(slots[i].carrier === 'testimonial' || slots[i].carrier === 'blurb' ? await grab(p, K.cardSampleClip(c.rect, c.el)) : null);
  }
  await p.evaluate(() => document.querySelectorAll('[data-aied-xc]').forEach((n) => { n.style.visibility = n.dataset.aiedXc || ''; delete n.dataset.aiedXc; }));
  // Step 2: the band behind each carrier: the carrier itself hidden (a counter's border and a divider's line go with it).
  await p.evaluate(() => document.querySelectorAll('[data-aied-carrier]').forEach((n) => { n.dataset.aiedXv = n.style.visibility; n.style.visibility = 'hidden'; }));
  await p.waitForTimeout(50);
  const bands = [];
  for (let i = 0; i < slots.length; i++) {
    const c = rest.carriers[i];
    bands.push(slots[i].carrier === 'blurb' ? await grab(p, wholeBox(c.rect)) : slots[i].carrier === 'testimonial' ? null : await grab(p, stripBox(c.rect)));
  }
  await p.evaluate((id) => {
    document.querySelectorAll('[data-aied-carrier]').forEach((n) => { n.style.visibility = n.dataset.aiedXv || ''; n.style.transition = n.dataset.aiedXt || ''; n.style.boxShadow = n.dataset.aiedXs || ''; n.style.removeProperty('color'); if (n.dataset.aiedXk) n.style.color = n.dataset.aiedXk; delete n.dataset.aiedXv; delete n.dataset.aiedXt; delete n.dataset.aiedXs; delete n.dataset.aiedXk; });
    const st = document.getElementById(id); if (st) st.remove();
  }, STYLE_ID);
  await p.evaluate(restoreFixed);
  await p.waitForTimeout(50);

  const problems = [];
  const observations = [];
  for (let i = 0; i < slots.length; i++) {
    const slot = slots[i];
    const exp = expects[i];
    const c = rest.carriers[i];
    let hov = null;
    let cmp = null;
    if (slot.carrier === 'testimonial') {
      const wait = Math.min(2000, transitionMs(c.el)) + 150;
      const hovered = await hoverJudged(p, {
        width, wait, code: 'testimonial-hover',
        judge: (all) => K.compareCarrier(exp, slot, c, all && all.carriers[i] && all.carriers[i].found ? all.carriers[i] : null),
        extras: (all) => (all.carriers[i] && all.carriers[i].found ? K.carrierOverflowProblems(all.carriers[i], 'hover', all) : []),
        hoverOnce: async (ms) => {
          await p.locator(`[data-aied-carrier="${slot.key}"]`).first().hover({ timeout: 8000 });
          await p.waitForTimeout(ms);
          return p.evaluate(K.measureCarriers, arg);
        },
      });
      if (hovered.error) problems.push(['testimonial-hover', `${slot.key} could not be hovered: ${hovered.error}`]);
      hov = hovered.measurement ? hovered.measurement.carriers[i] : null;
      cmp = { problems: hovered.problems, extras: hovered.extras };
    } else cmp = { problems: K.compareCarrier(exp, slot, c, null), extras: [] };
    const paint = K.carrierPaintProblems(exp, slot, c, { fill: fills[i], band: bands[i] }, { hairline, accentFloor });
    // The page-wide scrollWidth finding is the same for every carrier: report it once (with the first), not once per carrier.
    const ov = [...K.carrierOverflowProblems(c, 'rest', rest), ...cmp.extras].filter(([, d]) => i === 0 || !/scrollWidth/.test(d));
    for (const [code, detail] of [...cmp.problems, ...paint.problems, ...ov]) problems.push([code, `${slot.key} ${detail}`]);
    const quote = slot.carrier === 'testimonial' ? K.testimonialQuoteProblems(c).info : null;
    observations.push({
      width, key: slot.key, role: slot.role, carrier: slot.carrier, on: slot.on,
      expected: exp && exp.top ? exp.top : exp && exp.rest ? { fill: exp.rest.fill, radius: exp.rest.radius } : exp && exp.kind ? { radius: exp.radius, fill: exp.fill, glyph: exp.glyph } : null,
      got: c.line && slot.carrier !== 'testimonial' && slot.carrier !== 'blurb' ? c.line : { radius: c.el.borderTopLeftRadius, fill: c.el.backgroundColor, color: c.el.color },
      fill: fills[i] ? K.hex(fills[i]) : null, band: bands[i] ? K.hex(bands[i]) : null, contrast: paint.info, quote, hoverTransform: hov && hov.el ? hov.el.transform : null,
    });
  }
  return { problems, observations, carriersChecked: slots.length };
}

module.exports = { checkCarriers };
