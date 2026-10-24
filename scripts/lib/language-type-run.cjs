'use strict';
/*
 * The measuring half of scripts/verify-language-type.cjs for ONE opened page at ONE width, kept apart (like kit-media-run.cjs) so a real Chromium can
 * run it against a synthetic page without the dev site (scripts/test-language-type-run.cjs). It takes a Playwright `page` showing the type sheet and,
 * for every slot of T.TYPE_SLOTS, measures the computed weight, letter spacing, text-transform and family, the box and the column, lists the
 * document's font faces after `document.fonts.ready`, and lets the pure comparisons of language-type-checks.cjs judge. It only reads the page.
 */
const T = require('./language-type-checks.cjs');

/**
 * @param {import('playwright').Page} p the opened sheet page
 * @param {{ width:number, expects:Array<object> }} o expects = T.expectTypeFromAttrs of each slot in T.TYPE_SLOTS order (read from the RESOLVED markup)
 * @returns {Promise<{ problems:Array<[string,string]>, observations:Array<object>, typeChecked:number }>}
 */
async function checkType(p, { width, expects }) {
  const slots = T.TYPE_SLOTS;
  if (expects.length !== slots.length) return { problems: [['type-expectation', `${expects.length} expectations for ${slots.length} slots`]], observations: [], typeChecked: 0 };
  const m = await p.evaluate(T.measureType, slots.map((s) => ({ key: s.key, module: s.module, tag: s.tag, text: s.text })));
  const count = T.typeCountProblems(m.slots, slots);
  if (count.length) return { problems: count, observations: [], typeChecked: 0 };
  const problems = [...T.pageOverflowProblems(m)];
  const observations = [];
  slots.forEach((slot, i) => {
    const got = m.slots[i];
    for (const pr of T.compareType(expects[i], got, m.faces, m.innerWidth, slot.key)) problems.push(pr);
    observations.push({
      width, key: slot.key, role: slot.role, expected: expects[i],
      got: { fontWeight: got.el.fontWeight, letterSpacing: got.el.letterSpacing, textTransform: got.el.textTransform, family: T.firstFamily(got.el.fontFamily), fontSize: got.el.fontSize },
      box: [Math.round(got.rect.width), Math.round(got.rect.height)],
    });
  });
  return { problems, observations, typeChecked: slots.length };
}

module.exports = { checkType };
