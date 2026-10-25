'use strict';
/*
 * The measuring half of the rhythm slots of scripts/verify-language-type.cjs for ONE opened page at ONE width, kept apart (like language-type-run.cjs) so a
 * real Chromium can run it against a synthetic page without the dev site (scripts/test-language-rhythm-run.cjs). It takes a Playwright `page` showing the
 * sheet and, for every slot of R.RHYTHM_SLOTS, measures the computed padding-top, padding-bottom and background-image of its section, and lets the pure
 * comparisons of language-rhythm-checks.cjs judge. It only reads the page.
 */
const R = require('./language-rhythm-checks.cjs');

/**
 * @param {import('playwright').Page} p the opened sheet page
 * @param {{ width:number, expects:Array<object> }} o expects = R.expectRhythmFromAttrs of each slot in R.RHYTHM_SLOTS order (read from the RESOLVED markup)
 * @returns {Promise<{ problems:Array<[string,string]>, observations:Array<object>, rhythmChecked:number }>}
 */
async function checkRhythm(p, { width, expects }) {
  const slots = R.RHYTHM_SLOTS;
  if (!Array.isArray(expects) || expects.length !== slots.length) return { problems: [['rhythm-expectation', `${Array.isArray(expects) ? expects.length : 0} expectations for ${slots.length} slots`]], observations: [], rhythmChecked: 0 };
  const bp = R.bpOf(width);
  const m = await p.evaluate(R.measureRhythm, slots.map((s) => ({ key: s.key, text: s.text })));
  const count = R.rhythmCountProblems(m.slots, slots);
  if (count.length) return { problems: count, observations: [], rhythmChecked: 0 };
  const problems = [...R.pageOverflowProblems(m)];
  const observations = [];
  const pxs = {};
  slots.forEach((slot, i) => {
    const got = m.slots[i];
    for (const pr of R.comparePadding(expects[i], got, bp, slot.key)) problems.push(pr);
    if (slot.image) for (const pr of R.imageProblems(got, slot.key)) problems.push(pr);
    if (!slot.image) pxs[slot.role] = { top: R.lengthPx(got.el.paddingTop), bottom: R.lengthPx(got.el.paddingBottom) };
    observations.push({
      width, key: slot.key, role: slot.role, breakpoint: bp, expected: expects[i][bp] || null,
      got: { paddingTop: got.el.paddingTop, paddingBottom: got.el.paddingBottom, backgroundImage: slot.image ? (got.el.backgroundImage === 'none' ? 'none' : 'image') : undefined },
      box: [Math.round(got.rect.width), Math.round(got.rect.height)],
    });
  });
  // The order is judged on what the PAGE shows; the same order on the expectations is a pure cross-check (only when no slot has a hole).
  for (const pr of R.measuredOrderProblems(pxs, bp)) problems.push(pr);
  const byRole = {};
  slots.forEach((slot, i) => { if (!slot.image) byRole[slot.role] = expects[i]; });
  if (['section', 'open', 'tight'].every((r) => byRole[r] && R.missingRhythmExpectations(byRole[r]).length === 0)) {
    for (const pr of R.expectedOrderProblems(byRole)) problems.push(pr);
  }
  return { problems, observations, rhythmChecked: slots.length };
}

module.exports = { checkRhythm };
