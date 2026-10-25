'use strict';
/*
 * Pure parts of the language-type render gate (scripts/verify-language-type.cjs; type adapter plan, Task 6). No site, no browser: the sheet
 * template, the expectations read from the RESOLVED markup, the comparisons against what a page measured, and the guards that make a
 * run that checked nothing fail. The in-page measurement (measureType) is self-contained so Playwright can serialise it.
 *
 * What the gate proves: on the real Divi, a heading or an eyebrow text that carries a type marker (`"aied":{"type":"<role>"}`) really
 * shows the weight, the letter spacing, the capitals and the font family its language says, at 1280, 900 and 400 px, and that the web
 * font is really loaded at that weight. The expectation is never typed here: it is read from the markup that the REAL pipeline
 * (SectionRecipes::resolveMarkup, with the language and its own kit) resolved, so it follows LanguageAdapters.
 *
 * Codes (each problem is [code, detail]):
 *   type              a slot missing from the page or on it twice, and a run that checked no slot
 *   type-expectation  the fail-closed guard: an expectation read from the resolved markup has a hole (null), the slot is never skipped
 *   type-weight       computed font-weight
 *   type-tracking     computed letter-spacing (0.05 px tolerance; `normal` is 0px)
 *   type-caps         computed text-transform (`none` when the resolved role has no capitalization key: Divi's default)
 *   type-family       the first family of the computed font-family
 *   type-font-loaded  after document.fonts.ready, a FontFace of that family, status loaded, whose weight descriptor contains the weight
 *   type-overflow     the heading or text wider than its box, its column or the window
 *   overflow          the page wider than the window
 */
const { blockTree } = require('./kit-checks.cjs');

const dig = (o, ...keys) => keys.reduce((a, k) => (a && typeof a === 'object' ? a[k] : undefined), o);

// ---- the sheet -------------------------------------------------------------------------------------------------------
const V = '"builderVersion":"5.8.0"';
const TRACKING_TOLERANCE = 0.05;
/** The gate's widths. */
const WIDTHS = [1280, 900, 400];

/**
 * The slots of the type sheet, in document order. `module` is the carrier block, `tag` the element measured (a heading's own h1..h4, a text's p),
 * `fontRole` the font token the block is set in (LanguageAdapters::TYPE_FONT: display and title use the display font, eyebrow its own),
 * `sizes` the recipe-like size per device (the language never writes size). `inGroup` puts the slot in an unmarked padded group like a card.
 * Every slot is found by its UNIQUE text.
 */
const TYPE_SLOTS = [
  { key: 'display-h1', module: 'heading', tag: 'h1', role: 'display', fontRole: 'display', text: 'Remarkable results, delivered', sizes: { desktop: '104px', tablet: '68px', phone: '40px' }, lineHeight: '1em', inGroup: false },
  { key: 'display-h2', module: 'heading', tag: 'h2', role: 'display', fontRole: 'display', text: 'Everything you need and nothing you do not', sizes: { desktop: '44px', tablet: '36px', phone: '30px' }, lineHeight: '1.2em', inGroup: false },
  { key: 'title-h3', module: 'heading', tag: 'h3', role: 'title', fontRole: 'display', text: 'Placeholder card title', sizes: { desktop: '22px' }, lineHeight: '1.3em', inGroup: true },
  { key: 'eyebrow-h4', module: 'heading', tag: 'h4', role: 'eyebrow', fontRole: 'eyebrow', text: 'Eyebrow label', sizes: { desktop: '13px' }, lineHeight: '1.4em', inGroup: false },
  { key: 'eyebrow-text', module: 'text', tag: 'p', role: 'eyebrow', fontRole: 'eyebrow', text: 'Category label', sizes: { desktop: '12px' }, lineHeight: '1.4em', inGroup: false },
];

/** One slot's tokenised block (a type marker, a font token, no weight, spacing or capitals of its own: the language decides those). */
function slotMarkup(slot) {
  const marker = `"aied":{"type":"${slot.role}"}`;
  const ink = slot.role === 'eyebrow' ? 'muted' : 'text';
  const base = `"color":"{{aied:color:${ink}}}","family":"{{aied:font:${slot.fontRole}}}"`;
  if (slot.module === 'text') {
    const value = `{${base},"size":"${slot.sizes.desktop}","lineHeight":"${slot.lineHeight}"}`;
    return `<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>${slot.text}</p>"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":${value}}}}}}},${V},${marker}} /-->`;
  }
  const devices = Object.entries(slot.sizes).map(([bp, size]) => (bp === 'desktop'
    ? `"desktop":{"value":{"headingLevel":"${slot.tag}",${base},"size":"${size}","lineHeight":"${slot.lineHeight}"}}`
    : `"${bp}":{"value":{"size":"${size}"}}`));
  return `<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"${slot.text}"}},"decoration":{"font":{"font":{${devices.join(',')}}}}},${V},${marker}} /-->`;
}

/** The tokenised sheet markup: ONE plain section on the bg band holding every slot (the title inside an unmarked padded group, like a card). */
function sheetMarkup() {
  const body = TYPE_SLOTS.map((s) => (s.inGroup
    ? `<!-- wp:divi/group {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"24px","right":"24px","bottom":"24px","left":"24px"}}}},"layout":{"desktop":{"value":{"flexDirection":"column","rowGap":"12px"}}}}},${V}} -->\n${slotMarkup(s)}\n<!-- /wp:divi/group -->`
    : slotMarkup(s))).join('\n');
  return `<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:bg}}"}}},"spacing":{"desktop":{"value":{"padding":{"top":"64px","bottom":"64px"}}}}}},${V}} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},${V}} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}},"decoration":{"layout":{"desktop":{"value":{"flexDirection":"column","rowGap":"24px"}}}}},${V}} -->
${body}
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->`;
}

// ---- run titles and selection ----------------------------------------------------------------------------------------
const TITLE_PREFIX = 'aied type ';
const runTitle = (token, language) => `${TITLE_PREFIX}${token} ${language}`;
/** { token, language } of a run title, or null for anything else. */
function parseRunTitle(title) {
  const m = /^aied type (\d+-[0-9a-f]{6}) ([a-z0-9]+(?:-[a-z0-9]+)*)$/.exec(String(title));
  return m ? { token: m[1], language: m[2] } : null;
}

/**
 * The languages to render: all of `ids`, or the named ones in the given order. An unknown id throws (a usage error); an empty selection throws
 * an error with `.empty === true` (a failure, never a silent pass).
 */
function selectLanguages(optLanguages, ids) {
  const bad = (optLanguages || []).filter((l) => !ids.includes(l));
  if (bad.length) throw new Error('Unknown language(s): ' + bad.join(', ') + '\nValid: ' + ids.join(', '));
  const list = optLanguages || ids;
  if (!list.length) { const e = new Error('No language selected: the run would check nothing'); e.empty = true; throw e; }
  return [...new Set(list)];
}

// ---- expectations from the RESOLVED markup ---------------------------------------------------------------------------
/** The first family of a CSS font-family list or of a bare family name, quotes stripped; '' when there is none. */
function firstFamily(list) {
  const s = String(list == null ? '' : list).trim();
  if (!s) return '';
  let out = ''; let quote = null;
  for (const ch of s) {
    if (quote) { if (ch === quote) quote = null; else out += ch; continue; }
    if (ch === '"' || ch === "'") { quote = ch; continue; }
    if (ch === ',') break;
    out += ch;
  }
  return out.trim();
}

/** The block of each slot in the RESOLVED markup, found by its unique text: [{ slot, attrs }] in TYPE_SLOTS order; attrs is null when the block is not there. */
function pageTypeAttrs(markup, slots = TYPE_SLOTS) {
  const blocks = [];
  const walk = (list) => { for (const n of list) { if (n.name === 'divi/heading' || n.name === 'divi/text') blocks.push(n); walk(n.children); } };
  walk(blockTree(markup));
  return slots.map((slot) => {
    const node = blocks.find((n) => {
      if (slot.module === 'heading') return n.name === 'divi/heading' && dig(n.attrs, 'title', 'innerContent', 'desktop', 'value') === slot.text && dig(n.attrs, 'title', 'decoration', 'font', 'font', 'desktop', 'value', 'headingLevel') === slot.tag;
      const html = dig(n.attrs, 'content', 'innerContent', 'desktop', 'value');
      return n.name === 'divi/text' && typeof html === 'string' && html.replace(/<[^>]*>/g, '').trim() === slot.text;
    }) || null;
    return { slot, attrs: node ? node.attrs : null };
  });
}

/**
 * What the language says about one slot, read ONLY from the resolved block's desktop font values:
 * { weight: number|null, tracking: px|null, caps: 'uppercase'|'none'|null, family: string|null }.
 * caps is `uppercase` when the resolved key says so, `none` when the value object exists and has no capitalization key (Divi has no `none`
 * value: a `none` role writes nothing and Divi's default shows), and null (a hole) for anything else. A null anywhere is a hole, never a pass.
 */
function expectTypeFromAttrs(attrs, slot) {
  const v = slot.module === 'text' ? dig(attrs, 'content', 'decoration', 'bodyFont', 'body', 'font', 'desktop', 'value') : dig(attrs, 'title', 'decoration', 'font', 'font', 'desktop', 'value');
  if (!v || typeof v !== 'object') return { weight: null, tracking: null, caps: null, family: null };
  const w = typeof v.weight === 'string' && /^[1-9]00$/.test(v.weight) ? parseInt(v.weight, 10) : null;
  const t = typeof v.letterSpacing === 'string' && /^-?\d+(\.\d+)?px$/.test(v.letterSpacing) ? parseFloat(v.letterSpacing) : null;
  let caps = null;
  if (v.capitalization === undefined) caps = 'none';
  else if (v.capitalization === 'uppercase') caps = 'uppercase';
  const family = typeof v.family === 'string' && firstFamily(v.family) ? firstFamily(v.family) : null;
  return { weight: w, tracking: t, caps, family };
}

/** The expectation fields that are null: a non-empty list means the marker did not resolve (or a path drifted) and the slot must fail, not skip. */
function missingTypeExpectations(exp) {
  if (!exp) return ['weight', 'tracking', 'caps', 'family'];
  return ['weight', 'tracking', 'caps', 'family'].filter((k) => exp[k] === null || exp[k] === undefined || (typeof exp[k] === 'number' && !Number.isFinite(exp[k])));
}

// ---- comparison ------------------------------------------------------------------------------------------------------
/** A computed letter-spacing as px (`normal` is 0). NaN when it is neither. */
function trackingPx(v) {
  const s = String(v == null ? '' : v).trim();
  return s === 'normal' ? 0 : /^-?\d+(\.\d+)?px$/.test(s) ? parseFloat(s) : NaN;
}

/** The range of a FontFace weight descriptor: '100 900' -> [100, 900], '600' -> [600, 600], normal -> [400, 400], bold -> [700, 700]; null if unreadable. */
function weightRange(desc) {
  const s = String(desc == null ? '' : desc).trim().toLowerCase();
  if (s === 'normal') return [400, 400];
  if (s === 'bold') return [700, 700];
  const m = /^(\d{1,4})(?:\s+(\d{1,4}))?$/.exec(s);
  if (!m) return null;
  const a = parseInt(m[1], 10); const b = m[2] === undefined ? a : parseInt(m[2], 10);
  return a <= b ? [a, b] : null;
}

const sameFamily = (a, b) => firstFamily(a).toLowerCase() === firstFamily(b).toLowerCase();

/**
 * Problems of type-font-loaded: is there a loaded FontFace of `family` whose weight descriptor contains `weight`? faces = [{ family, weight, style, status }]
 * as listed by document.fonts after document.fonts.ready. Returns [] or [['type-font-loaded', detail]].
 */
function fontLoadedProblems(family, weight, faces, key = '') {
  const own = (faces || []).filter((f) => sameFamily(f.family, family));
  const ok = own.some((f) => { const r = weightRange(f.weight); return f.status === 'loaded' && r !== null && r[0] <= weight && weight <= r[1]; });
  if (ok) return [];
  const seen = own.length ? own.map((f) => `${f.weight} ${f.status}`).join(', ') : 'no face of that family in document.fonts';
  return [['type-font-loaded', `${key ? key + ' ' : ''}family "${family}" at weight ${weight} has no loaded face (${seen})`.replace(/\s+/g, ' ')]];
}

/** Problems of type-overflow for one measured slot: the element wider than its box, past its column or past the window. */
function typeOverflowProblems(m, innerWidth, key = '') {
  const out = [];
  const lead = key ? key + ' ' : '';
  if (m.scrollWidth > m.clientWidth + 1) out.push(['type-overflow', `${lead}scrollWidth ${m.scrollWidth} > clientWidth ${m.clientWidth}`]);
  if (m.rect.right > innerWidth + 1 || m.rect.left < -1) out.push(['type-overflow', `${lead}${Math.round(m.rect.left)}..${Math.round(m.rect.right)} outside the window (${innerWidth})`]);
  if (m.parentRect && (m.rect.right > m.parentRect.right + 1 || m.rect.left < m.parentRect.left - 1)) out.push(['type-overflow', `${lead}${Math.round(m.rect.left)}..${Math.round(m.rect.right)} outside its column ${Math.round(m.parentRect.left)}..${Math.round(m.parentRect.right)}`]);
  return out;
}

/** Problems of the page-wide overflow (code overflow): the page wider than the window. */
function pageOverflowProblems(page) {
  return page.scrollWidth > page.innerWidth ? [['overflow', `scrollWidth ${page.scrollWidth} > innerWidth ${page.innerWidth}`]] : [];
}

/**
 * Compares one slot's expectation with what the page measured. `m` = { el: { fontWeight, letterSpacing, textTransform, fontFamily }, scrollWidth, clientWidth, rect, parentRect },
 * `faces` the document's font faces, `innerWidth` the window. An expectation with a hole answers ONLY type-expectation (nothing is compared against a hole).
 * Returns [[code, detail]] (every detail starts with the slot key).
 */
function compareType(exp, m, faces, innerWidth, key = '') {
  const lead = key ? key + ' ' : '';
  const holes = missingTypeExpectations(exp);
  if (holes.length) return [['type-expectation', `${lead}the resolved markup has no ${holes.join(', ')}: the type marker did not resolve or its path drifted`]];
  const out = [];
  const w = parseInt(m.el.fontWeight, 10);
  if (w !== exp.weight) out.push(['type-weight', `${lead}font-weight ${m.el.fontWeight}, expected ${exp.weight}`]);
  const t = trackingPx(m.el.letterSpacing);
  if (!Number.isFinite(t) || Math.abs(t - exp.tracking) > TRACKING_TOLERANCE) out.push(['type-tracking', `${lead}letter-spacing ${m.el.letterSpacing}, expected ${exp.tracking}px`]);
  if (m.el.textTransform !== exp.caps) out.push(['type-caps', `${lead}text-transform ${m.el.textTransform}, expected ${exp.caps}`]);
  if (firstFamily(m.el.fontFamily).toLowerCase() !== exp.family.toLowerCase()) out.push(['type-family', `${lead}font-family ${firstFamily(m.el.fontFamily) || '(none)'}, expected ${exp.family}`]);
  out.push(...fontLoadedProblems(exp.family, exp.weight, faces, key));
  out.push(...typeOverflowProblems(m, innerWidth, key));
  return out;
}

/**
 * Problems of the slot count (code type): every slot exactly once on the page. measured = measureType(...).slots in slot order.
 * An empty slot list is an error.
 */
function typeCountProblems(measured, slots = TYPE_SLOTS) {
  if (!slots.length) throw new Error('no type slots: nothing would be checked');
  const bad = [];
  slots.forEach((slot, i) => {
    const mm = measured[i];
    if (!mm || !mm.found) { bad.push(`${slot.key} is not in the page`); return; }
    if (mm.count !== 1) bad.push(`${slot.key} is on the page ${mm.count} times, expected 1`);
  });
  if (measured.length > slots.length) bad.push(`${measured.length} slots measured for ${slots.length} slots`);
  if (!bad.length) return [];
  const found = slots.filter((s, i) => measured[i] && measured[i].found).length;
  return [['type', `${found} of ${slots.length} type slots rendered as the sheet says: ${bad.slice(0, 6).join('; ')}${bad.length > 6 ? ` and ${bad.length - 6} more` : ''}`]];
}

/** What a finished run must have checked: at least one language page and at least one type slot (each fails the run with the code type). */
function emptyTypeRunProblems({ checked, typeChecked }) {
  const out = [];
  if (!checked) out.push('no language page was checked: the selection was empty');
  if (!typeChecked) out.push('no type slot was checked: nothing was measured');
  return out;
}

// ---- in the page -----------------------------------------------------------------------------------------------------
/**
 * Runs IN THE PAGE (self-contained, async). For each slot ({ key, module, tag, text }) the rendered element: the `.et_pb_heading` (or `.et_pb_text`) module
 * whose trimmed text is the slot's, and in it the `tag` (a heading's own h1..h4) or the `p` of a text. Every slot is scrolled into view, then
 * `document.fonts.ready` is awaited, then the computed style is read, so the faces the page really used are listed in `faces`
 * ({ family, weight, style, status } of every FontFace of document.fonts).
 */
async function measureType(slots) {
  const root = document.querySelector('.et-l--post');
  const mods = root ? [...root.querySelectorAll('.et_pb_heading, .et_pb_text')] : [];
  const box = (n) => { const r = n.getBoundingClientRect(); return { left: r.left + window.scrollX, right: r.right + window.scrollX, top: r.top + window.scrollY, bottom: r.bottom + window.scrollY, width: r.width, height: r.height }; };
  const found = slots.map((s) => {
    const cls = s.module === 'heading' ? 'et_pb_heading' : 'et_pb_text';
    const hits = mods.filter((n) => n.classList.contains(cls) && (n.textContent || '').trim() === s.text);
    const mod = hits[0];
    const el = mod ? mod.querySelector(s.tag) : null;
    return { s, hits: hits.length, el };
  });
  for (const f of found) { if (f.el) { try { f.el.scrollIntoView({ block: 'center' }); } catch (e) { /* the measurement below still runs */ } } }
  try { await document.fonts.ready; } catch (e) { /* faces are listed as they are */ }
  await new Promise((r) => setTimeout(r, 50));
  const faces = [...document.fonts].map((f) => ({ family: f.family, weight: f.weight, style: f.style, status: f.status }));
  return {
    hasRoot: !!root,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    faces,
    slots: found.map(({ s, hits, el }) => {
      if (!el) return { key: s.key, found: false, count: hits };
      const cs = getComputedStyle(el);
      const column = el.closest('.et_pb_column') || el.parentElement;
      return {
        key: s.key, found: true, count: hits,
        el: { fontWeight: cs.fontWeight, letterSpacing: cs.letterSpacing, textTransform: cs.textTransform, fontFamily: cs.fontFamily, fontSize: cs.fontSize },
        scrollWidth: el.scrollWidth, clientWidth: el.clientWidth, rect: box(el), parentRect: box(column),
      };
    }),
  };
}

module.exports = {
  WIDTHS, TRACKING_TOLERANCE, TYPE_SLOTS, slotMarkup, sheetMarkup, TITLE_PREFIX, runTitle, parseRunTitle, selectLanguages,
  firstFamily, pageTypeAttrs, expectTypeFromAttrs, missingTypeExpectations, trackingPx, weightRange, sameFamily,
  fontLoadedProblems, typeOverflowProblems, pageOverflowProblems, compareType, typeCountProblems, emptyTypeRunProblems, measureType,
};
