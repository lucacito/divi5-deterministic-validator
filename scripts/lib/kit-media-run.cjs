'use strict';
/*
 * The image half of scripts/verify-kits.cjs for ONE opened page at ONE width, kept apart (like kit-card-run.cjs, kit-item-run.cjs and kit-form-run.cjs)
 * so a real Chromium can run it against a synthetic page without the dev site (scripts/test-kit-card-run.cjs). It takes a Playwright `page` showing
 * the kit sheet and, for the image of the sheet (K.MEDIA_SLOTS: one `divi/image` on the bg band whose radius is the token `{{aied:kit:radius-media}}`),
 * measures the computed corner radii and overflow of the img, its wrapper (`.et_pb_image_wrap`) and the module box, their boxes and the column, and
 * lets the pure comparisons of scripts/lib/kit-checks.cjs judge (media-radius, media-clip, overflow, media). It only reads the page.
 *
 * Assumption (settled by the live smoke, not by this file): Divi 5.15.1 prints the radius and overflow:hidden on `.et_pb_image_wrap` and gives the
 * img `border-radius: inherit` (ImageModule.php, module.json styleProps.border.selector). The comparison does not depend on that: it passes when
 * the radius is on whichever of the img and the wrapper Divi paints it on, as long as the picture is clipped to it.
 */
const K = require('./kit-checks.cjs');

/**
 * @param {import('playwright').Page} p the opened sheet page
 * @param {{ width:number, expects:Array<object> }} o expects = K.expectMediaFromAttrs of each image in K.MEDIA_SLOTS order (read from the RESOLVED markup)
 * @returns {Promise<{ problems:Array<[string,string]>, observations:Array<object>, mediaChecked:number }>}
 */
async function checkMedia(p, { width, expects }) {
  const slots = K.MEDIA_SLOTS;
  const m = await p.evaluate(K.measureMedia, slots.map((s) => ({ key: s.key, alt: s.alt })));
  const count = K.mediaCountProblems(m.images, slots);
  if (count.length) return { problems: count, observations: [], mediaChecked: 0 };
  const problems = [];
  const observations = [];
  slots.forEach((slot, i) => {
    const img = m.images[i];
    const where = slot.key;
    // The page-wide scrollWidth finding is the same for every image: report it once (with the first), not once per image.
    const ov = K.mediaOverflowProblems(img, 'rest', m).filter(([, d]) => i === 0 || !/scrollWidth/.test(d));
    for (const [code, detail] of [...K.compareMedia(expects[i], img, 'rest'), ...ov]) problems.push([code, `${where} ${detail}`]);
    const corners = (part) => (part ? K.CORNER_PROPS_LIST.map((k) => part.el[k]) : null);
    observations.push({ width, key: slot.key, expected: expects[i].radius, img: corners(img.img), wrapper: corners(img.wrap), module: corners(img.module), wrapperOverflow: img.wrap ? `${img.wrap.el.overflowX}/${img.wrap.el.overflowY}` : null, box: img.img ? [Math.round(img.img.rect.width), Math.round(img.img.rect.height)] : null, loaded: img.loaded });
  });
  return { problems, observations, mediaChecked: slots.length };
}

module.exports = { checkMedia };
