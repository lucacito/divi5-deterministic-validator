'use strict';
/*
 * Pure parts of the rhythm half of the language render gate (scripts/verify-language-type.cjs; rhythm adapter plan, Task 6). No site, no
 * browser: the four rhythm sections the gate adds to the type sheet's page, the expectations read from the RESOLVED markup, the comparisons
 * against what a page measured, and the guards that make a run that checked nothing fail. The in-page measurement (measureRhythm) is
 * self-contained so Playwright can serialise it.
 *
 * What the gate proves: on the real Divi, a `divi/section` that carries a rhythm marker (`"aied":{"rhythm":"<role>"}`) really shows the top and
 * bottom padding its language says for that role, at 1280 (desktop), 900 (tablet) and 400 px (phone), that the three roles keep their order on
 * the page (open > section > tight), and that a section with a background image keeps it. The expectation is never typed here: it is read from
 * the markup that the REAL pipeline (SectionRecipes::resolveMarkup, with the language and its own kit) resolved, so it follows
 * LanguageAdapters and DesignLanguages::sectionSpace().
 *
 * Codes (each problem is [code, detail]):
 *   rhythm              a slot missing from the page or on it twice, and a run that checked no rhythm slot
 *   rhythm-expectation  the fail-closed guard: the resolved markup has no px top or bottom at a breakpoint (a hole is never "inherited"), the slot is never skipped
 *   rhythm-padding      computed padding-top / padding-bottom of the slot's .et_pb_section (0.5 px tolerance)
 *   rhythm-order        MEASURED open > section > tight, top and bottom, at every width (plus the same on the expectations as a pure cross-check)
 *   rhythm-image        the photo slot's computed background-image is `none`
 *   overflow            the page wider than the window
 */

const { blockTree } = require('./kit-checks.cjs');

const dig = (o, ...keys) => keys.reduce((a, k) => (a && typeof a === 'object' ? a[k] : undefined), o);

const V = '"builderVersion":"5.8.0"';
const PADDING_TOLERANCE = 0.5;
/** The gate's widths and their breakpoints (Divi: tablet up to 980 px, phone up to 767 px). */
const BREAKPOINTS = ['desktop', 'tablet', 'phone'];

/**
 * The slots of the rhythm sheet, in document order (after the type section). `band` is the section's background colour token, `image` puts a built-in
 * pack picture under it. Every slot is found by its UNIQUE text.
 */
const RHYTHM_SLOTS = [
  { key: 'rhythm-section', role: 'section', band: '{{aied:color:surface}}', image: null, text: 'Rhythm band, section' },
  { key: 'rhythm-open', role: 'open', band: '{{aied:color:bg}}', image: null, text: 'Rhythm band, open' },
  { key: 'rhythm-tight', role: 'tight', band: '{{aied:color:surface}}', image: null, text: 'Rhythm band, tight' },
  { key: 'rhythm-image', role: 'open', band: '{{aied:color:bg}}', image: '{{aied:image:hero-blue-1}}', text: 'Rhythm band over a photo' },
];

/** 'desktop' | 'tablet' | 'phone' of a window width (the gate's 1280, 900 and 400; Divi's tablet limit is 980 px and its phone limit 767 px). */
function bpOf(width) {
  const w = Number(width);
  if (!Number.isFinite(w) || w <= 0) throw new Error('bpOf: not a width: ' + width);
  return w > 980 ? 'desktop' : w > 767 ? 'tablet' : 'phone';
}

/** One slot's tokenised section: the band, NO padding of its own, one row, one column, one text with the unique text, the marker as the LAST key. */
function slotMarkup(slot) {
  const bg = slot.image
    ? `{"color":"${slot.band}","image":{"url":"${slot.image}"}}`
    : `{"color":"${slot.band}"}`;
  return `<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":${bg}}}}},${V},"aied":{"rhythm":"${slot.role}"}} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},${V}} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}},"decoration":{"layout":{"desktop":{"value":{"flexDirection":"column"}}}}},${V}} -->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>${slot.text}</p>"}}},${V}} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->`;
}

/** The four rhythm sections, tokenised, in slot order. */
function rhythmSheetMarkup() {
  return RHYTHM_SLOTS.map(slotMarkup).join('\n');
}

// ---- expectations from the RESOLVED markup ---------------------------------------------------------------------------
/** The section of each slot in the RESOLVED markup, found by the unique text of a text module below it: [{ slot, attrs }] in slot order; attrs is null when it is not there. */
function pageRhythmAttrs(markup, slots = RHYTHM_SLOTS) {
  const sections = [];
  const textOf = (node) => node.children.flatMap((c) => {
    const html = c.name === 'divi/text' ? dig(c.attrs, 'content', 'innerContent', 'desktop', 'value') : null;
    return [...(typeof html === 'string' ? [html.replace(/<[^>]*>/g, '').trim()] : []), ...textOf(c)];
  });
  const walk = (list) => { for (const n of list) { if (n.name === 'divi/section') sections.push({ node: n, texts: textOf(n) }); walk(n.children); } };
  walk(blockTree(markup));
  return slots.map((slot) => {
    const hit = sections.find((s) => s.texts.includes(slot.text));
    return { slot, attrs: hit ? hit.node.attrs : null };
  });
}

/** A `Npx` length (a non-negative number) as a number, or null for anything else. */
function pxOf(v) {
  return typeof v === 'string' && /^\d+(\.\d+)?px$/.test(v) ? parseFloat(v) : null;
}

/**
 * What the language says about one section, read ONLY from the resolved block's own spacing values:
 * { desktop: { top, bottom }, tablet: { ... }, phone: { ... } } in px. A breakpoint without a value, or a value that is not `Npx`, is a hole (null):
 * Divi would inherit it from the wider breakpoint, and the gate must not guess that.
 */
function expectRhythmFromAttrs(attrs) {
  const out = {};
  for (const bp of BREAKPOINTS) {
    const pad = dig(attrs, 'module', 'decoration', 'spacing', bp, 'value', 'padding');
    out[bp] = { top: pxOf(dig(pad, 'top')), bottom: pxOf(dig(pad, 'bottom')) };
  }
  return out;
}

/** The expectation fields that are holes, as `<bp>.<side>`: a non-empty list means the marker did not resolve (or a path drifted) and the slot must fail, not skip. */
function missingRhythmExpectations(exp) {
  const out = [];
  for (const bp of BREAKPOINTS) {
    for (const side of ['top', 'bottom']) {
      const v = exp && exp[bp] ? exp[bp][side] : null;
      if (v === null || v === undefined || !Number.isFinite(v)) out.push(`${bp}.${side}`);
    }
  }
  return out;
}

// ---- comparison ------------------------------------------------------------------------------------------------------
/** A computed length as px, NaN when it is not one. */
function lengthPx(v) {
  const s = String(v == null ? '' : v).trim();
  return /^-?\d+(\.\d+)?(px)?$/.test(s) ? parseFloat(s) : NaN;
}

/**
 * Problems of rhythm-padding for one measured slot at one width. `exp` = expectRhythmFromAttrs(...) (complete), `m` = { el: { paddingTop, paddingBottom } }.
 * A hole answers ONLY rhythm-expectation (nothing is compared against a hole). Every detail starts with the slot key.
 */
function comparePadding(exp, m, bp, key = '') {
  const lead = key ? key + ' ' : '';
  const holes = missingRhythmExpectations(exp);
  if (holes.length) return [['rhythm-expectation', `${lead}the resolved markup has no px ${holes.join(', ')}: the rhythm marker did not resolve or its path drifted`]];
  const out = [];
  for (const [side, got] of [['top', m.el.paddingTop], ['bottom', m.el.paddingBottom]]) {
    const want = exp[bp][side];
    const px = lengthPx(got);
    if (!Number.isFinite(px) || Math.abs(px - want) > PADDING_TOLERANCE) out.push(['rhythm-padding', `${lead}padding-${side} ${got} at ${bp}, expected ${want}px`]);
  }
  return out;
}

/**
 * Problems of rhythm-order from the MEASURED paddings: open > section > tight, strictly, top and bottom. `measured` = { section, open, tight } each { top, bottom }
 * in px (numbers). Equal values fail (a role that changes nothing is no rhythm); a NaN fails.
 */
function measuredOrderProblems(measured, bp) {
  const out = [];
  for (const side of ['top', 'bottom']) {
    const o = measured.open[side]; const s = measured.section[side]; const t = measured.tight[side];
    if (![o, s, t].every(Number.isFinite)) { out.push(['rhythm-order', `padding-${side} at ${bp}: open ${o}, section ${s}, tight ${t} are not all numbers`]); continue; }
    if (!(o > s && s > t)) out.push(['rhythm-order', `padding-${side} at ${bp}: open ${o}px, section ${s}px, tight ${t}px must be open > section > tight (measured)`]);
  }
  return out;
}

/** The same order on the expectations (a pure cross-check, one problem per breakpoint and side). `exps` = { section, open, tight } expectRhythmFromAttrs results. */
function expectedOrderProblems(exps) {
  const out = [];
  for (const bp of BREAKPOINTS) {
    for (const side of ['top', 'bottom']) {
      const o = exps.open[bp][side]; const s = exps.section[bp][side]; const t = exps.tight[bp][side];
      if (![o, s, t].every(Number.isFinite)) { out.push(['rhythm-order', `expected padding-${side} at ${bp}: open ${o}, section ${s}, tight ${t} are not all numbers`]); continue; }
      if (!(o > s && s > t)) out.push(['rhythm-order', `expected padding-${side} at ${bp}: open ${o}px, section ${s}px, tight ${t}px must be open > section > tight (the resolved markup)`]);
    }
  }
  return out;
}

/** Problems of rhythm-image: the photo slot must show a background image. */
function imageProblems(m, key = '') {
  const bi = String(m.el.backgroundImage == null ? '' : m.el.backgroundImage).trim();
  return bi === '' || bi === 'none' ? [['rhythm-image', `${key ? key + ' ' : ''}computed background-image is ${bi || '(empty)'}: the section lost its picture`]] : [];
}

/** Problems of the page-wide overflow (code overflow): the page wider than the window. */
function pageOverflowProblems(page) {
  return page.scrollWidth > page.innerWidth ? [['overflow', `scrollWidth ${page.scrollWidth} > innerWidth ${page.innerWidth}`]] : [];
}

/** Problems of the slot count (code rhythm): every slot exactly once on the page. An empty slot list is an error. */
function rhythmCountProblems(measured, slots = RHYTHM_SLOTS) {
  if (!slots.length) throw new Error('no rhythm slots: nothing would be checked');
  const bad = [];
  slots.forEach((slot, i) => {
    const mm = measured[i];
    if (!mm || !mm.found) { bad.push(`${slot.key} is not in the page`); return; }
    if (mm.count !== 1) bad.push(`${slot.key} is on the page ${mm.count} times, expected 1`);
  });
  if (measured.length > slots.length) bad.push(`${measured.length} slots measured for ${slots.length} slots`);
  if (!bad.length) return [];
  const found = slots.filter((s, i) => measured[i] && measured[i].found).length;
  return [['rhythm', `${found} of ${slots.length} rhythm slots rendered as the sheet says: ${bad.slice(0, 6).join('; ')}${bad.length > 6 ? ` and ${bad.length - 6} more` : ''}`]];
}

/** What a finished run must have checked: at least one rhythm slot (it fails the run with the code rhythm). */
function emptyRhythmRunProblems({ rhythmChecked }) {
  return rhythmChecked ? [] : ['no rhythm slot was checked: nothing was measured'];
}

// ---- in the page -----------------------------------------------------------------------------------------------------
/**
 * Runs IN THE PAGE (self-contained, sync). For each slot ({ key, text }) the rendered section: the `.et_pb_section` holding a `.et_pb_text` module whose
 * trimmed text is the slot's. Reads its computed padding-top, padding-bottom and background-image and its box.
 */
function measureRhythm(slots) {
  const root = document.querySelector('.et-l--post');
  const mods = root ? [...root.querySelectorAll('.et_pb_text')] : [];
  const box = (n) => { const r = n.getBoundingClientRect(); return { left: r.left + window.scrollX, right: r.right + window.scrollX, top: r.top + window.scrollY, bottom: r.bottom + window.scrollY, width: r.width, height: r.height }; };
  return {
    hasRoot: !!root,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    slots: slots.map((s) => {
      const hits = mods.filter((n) => (n.textContent || '').trim() === s.text);
      const sec = hits.length ? hits[0].closest('.et_pb_section') : null;
      if (!sec) return { key: s.key, found: false, count: hits.length };
      const cs = getComputedStyle(sec);
      return { key: s.key, found: true, count: hits.length, el: { paddingTop: cs.paddingTop, paddingBottom: cs.paddingBottom, backgroundImage: cs.backgroundImage }, rect: box(sec) };
    }),
  };
}

module.exports = {
  PADDING_TOLERANCE, BREAKPOINTS, RHYTHM_SLOTS, bpOf, slotMarkup, rhythmSheetMarkup, pageRhythmAttrs, pxOf, expectRhythmFromAttrs,
  missingRhythmExpectations, lengthPx, comparePadding, measuredOrderProblems, expectedOrderProblems, imageProblems, pageOverflowProblems,
  rhythmCountProblems, emptyRhythmRunProblems, measureRhythm,
};
