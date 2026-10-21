'use strict';
/*
 * The rule and chip half of scripts/verify-kits.cjs for ONE opened page at ONE width, kept apart (like kit-card-run.cjs, kit-item-run.cjs, kit-form-run.cjs
 * and kit-media-run.cjs) so a real Chromium can run it against a synthetic page without the dev site (scripts/test-kit-card-run.cjs). It takes a Playwright
 * `page` showing the kit sheet and
 *  - checkRules: for each rule of the sheet (K.RULE_SLOTS: a divider and a top-rule group of each role on the bg and photo bands, a column rule, a divider
 *    in an inverted panel, a divider of each role on the surface and accent bands and a top-rule group of each role in the inverted panel) measures the line as Divi draws it (a divider's `::before`, a group or column's own `border-top-*` with the other three sides
 *    at 0), samples the PAINTED band behind it (the rule hidden, the WordPress admin bar and every fixed or sticky element hidden too) and lets the pure
 *    comparisons of scripts/lib/kit-checks.cjs judge (rule, rule-style|width|colour|contrast, overflow);
 *  - checkChips: for each chip (K.CHIP_SLOTS: a tag and an icon chip on the bg band, a tag on the surface and photo bands, an icon chip on the accent band) measures the corners, the frame (a tag), the fill and the glyph colour (an icon chip),
 *    samples the painted fill of the icon (its glyph hidden) and the painted band, and judges (chip, chip-radius|frame|fill|contrast, overflow).
 * It only reads the page (everything hidden for a sample is restored).
 */
const K = require('./kit-checks.cjs');

/** Hides every fixed and sticky element (the logged-in preview's admin bar and the menu it opens) and returns nothing; restoreFixed undoes it. */
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

/**
 * @param {import('playwright').Page} p the opened sheet page
 * @param {{ width:number, expects:Array<object> }} o expects = K.expectRuleFromAttrs of each rule in K.RULE_SLOTS order (read from the RESOLVED markup)
 * @returns {Promise<{ problems:Array<[string,string]>, observations:Array<object>, rulesChecked:number }>}
 */
async function checkRules(p, { width, expects }) {
  const slots = K.RULE_SLOTS;
  const arg = slots.map((s) => ({ key: s.key, heading: s.heading, carrier: s.carrier }));
  const rest = await p.evaluate(K.measureRules, arg);
  const count = K.ruleCountProblems(rest.rules, slots);
  if (count.length) return { problems: count, observations: [], rulesChecked: 0 };

  // The painted band behind each rule: the rule hidden at once (no transition), the admin bar and every fixed element hidden too.
  await p.evaluate(hideFixed);
  await p.evaluate(() => document.querySelectorAll('[data-aied-rule]').forEach((n) => { n.dataset.aiedXt = n.style.transition; n.style.transition = 'none'; n.dataset.aiedXv = n.style.visibility; n.style.visibility = 'hidden'; }));
  await p.waitForTimeout(50);
  const bands = [];
  for (const m of rest.rules) bands.push(await grab(p, stripBox(m.rect)));
  await p.evaluate(() => document.querySelectorAll('[data-aied-xv]').forEach((n) => { n.style.visibility = n.dataset.aiedXv || ''; n.style.transition = n.dataset.aiedXt || ''; delete n.dataset.aiedXv; delete n.dataset.aiedXt; }));
  await p.evaluate(restoreFixed);
  await p.waitForTimeout(50);

  const problems = [];
  const observations = [];
  slots.forEach((slot, i) => {
    const exp = expects[i];
    const m = rest.rules[i];
    const where = slot.key;
    const cmp = K.compareRule(exp, m);
    const contrast = K.ruleContrastProblems(m, bands[i], slot.role);
    // The page-wide scrollWidth finding is the same for every rule: report it once (with the first), not once per rule.
    const ov = K.ruleOverflowProblems(m, 'rest', rest).filter(([, d]) => i === 0 || !/scrollWidth/.test(d));
    for (const [code, detail] of [...cmp, ...contrast.problems, ...ov]) problems.push([code, `${where} ${detail}`]);
    observations.push({ width, key: slot.key, role: slot.role, carrier: slot.carrier, on: slot.on, expected: exp && exp.top ? exp.top : null, got: m.line, band: bands[i] ? K.hex(bands[i]) : null, contrast: contrast.info });
  });
  return { problems, observations, rulesChecked: slots.length };
}

/**
 * @param {import('playwright').Page} p the opened sheet page
 * @param {{ width:number, expects:Array<object>, hairline:?string }} o expects = K.expectChipFromAttrs of each chip in K.CHIP_SLOTS order; hairline = the
 *        design language's `border` colour (the quiet frame a hairline kit draws, judged against the quiet floor instead of 3.0)
 * @returns {Promise<{ problems:Array<[string,string]>, observations:Array<object>, chipsChecked:number }>}
 */
async function checkChips(p, { width, expects, hairline = null }) {
  const slots = K.CHIP_SLOTS;
  const arg = slots.map((s) => ({ key: s.key, carrier: s.carrier, text: s.text, heading: s.heading }));
  const rest = await p.evaluate(K.measureChips, arg);
  const count = K.chipCountProblems(rest.chips, slots);
  if (count.length) return { problems: count, observations: [], chipsChecked: 0 };

  // The painted fill of an icon chip (its glyph hidden), then the painted band behind every chip (the chip hidden).
  await p.evaluate(hideFixed);
  await p.evaluate(() => document.querySelectorAll('[data-aied-chip]').forEach((n) => { n.dataset.aiedXt = n.style.transition; n.style.transition = 'none'; }));
  await p.evaluate(() => document.querySelectorAll('[data-aied-chip] *').forEach((n) => { n.dataset.aiedXc = n.style.visibility; n.style.visibility = 'hidden'; }));
  await p.waitForTimeout(50);
  const fills = [];
  for (let i = 0; i < slots.length; i++) fills.push(slots[i].carrier === 'icon' ? await grab(p, K.cardSampleClip(rest.chips[i].rect, rest.chips[i].el)) : null);
  await p.evaluate(() => document.querySelectorAll('[data-aied-xc]').forEach((n) => { n.style.visibility = n.dataset.aiedXc || ''; delete n.dataset.aiedXc; }));
  await p.evaluate(() => document.querySelectorAll('[data-aied-chip]').forEach((n) => { n.dataset.aiedXv = n.style.visibility; n.style.visibility = 'hidden'; }));
  await p.waitForTimeout(50);
  const bands = [];
  for (const m of rest.chips) bands.push(await grab(p, wholeBox(m.rect)));
  await p.evaluate(() => document.querySelectorAll('[data-aied-chip]').forEach((n) => { n.style.visibility = n.dataset.aiedXv || ''; n.style.transition = n.dataset.aiedXt || ''; delete n.dataset.aiedXv; delete n.dataset.aiedXt; }));
  await p.evaluate(restoreFixed);
  await p.waitForTimeout(50);

  const problems = [];
  const observations = [];
  slots.forEach((slot, i) => {
    const exp = expects[i];
    const m = rest.chips[i];
    const where = slot.key;
    const cmp = K.compareChip(exp, m);
    const miss = K.missingChipExpectations(exp);
    const painted = slot.carrier === 'icon' && !miss.length ? K.chipPaintedFillProblems(m, fills[i]) : [];
    const contrast = miss.length ? { problems: [], info: {} } : K.chipContrastProblems(exp, m, { fill: fills[i], band: bands[i] }, hairline);
    const ov = K.chipOverflowProblems(m, 'rest', rest).filter(([, d]) => i === 0 || !/scrollWidth/.test(d));
    for (const [code, detail] of [...cmp, ...painted, ...contrast.problems, ...ov]) problems.push([code, `${where} ${detail}`]);
    observations.push({ width, key: slot.key, carrier: slot.carrier, expectedRadius: exp ? exp.radius : null, radius: m.el.borderTopLeftRadius, frame: slot.carrier === 'group' ? m.el.borderTopWidth + ' ' + m.el.borderTopStyle + ' ' + m.el.borderTopColor : null, fill: fills[i] ? K.hex(fills[i]) : null, band: bands[i] ? K.hex(bands[i]) : null, contrast: contrast.info });
  });
  return { problems, observations, chipsChecked: slots.length };
}

module.exports = { checkRules, checkChips };
