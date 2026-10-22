'use strict';
/*
 * Pure pieces of scripts/verify-html-to-divi-styles.cjs (the live gate of html_to_divi styles=basic), kept apart so they can be tested without
 * the dev site (scripts/test-verify-html-styles.cjs): colour and font helpers, the contrast on a painted background, what the converter WROTE
 * (writtenOf), the per-block comparisons of the written values with the source page and with the Divi page, the page-level evaluation and the
 * two "never skipped" rules (hs-mapping, hs-measured).
 *
 * The two in-page functions (sourceProbe, diviProbe) run in the browser: Playwright serialises them, so they must stay self-contained.
 *
 * CODES (a failure always names one): hs-color, hs-weight, hs-align, hs-size, hs-family, hs-bg, hs-padding, hs-max-width, hs-radius, hs-button,
 * hs-contrast, hs-overflow, hs-mapping, hs-measured.
 *
 * WHAT IS COMPARED, per block that has a WRITTEN value (a property the converter did not write is not judged here: it was left out for a reason
 * the report lists, and hs-contrast still judges the result):
 *   Divi computed (1280)  = written     for every code; at 900 / 400 a size is the written tablet / phone step (a missing step inherits from the
 *                                       larger one, as Divi does)
 *   source computed       = written     where the converter reads the source's own value: colour, weight, align, family (written is in the
 *                                       source's computed font-family list), size (the source value clamped to the converter's 8..120 px),
 *                                       padding (clamped to 0..200 px), a painted background (the first opaque background from the anchor up)
 * Divi's defaults are never compared with the source's: only what the converter wrote.
 */

const SIZE_MIN = 8;
const SIZE_MAX = 120;
const PAD_MAX = 200;
const CANVAS = '#ffffff';

const CODES = ['hs-color', 'hs-weight', 'hs-align', 'hs-size', 'hs-family', 'hs-bg', 'hs-padding', 'hs-max-width', 'hs-radius', 'hs-button', 'hs-contrast', 'hs-overflow', 'hs-mapping', 'hs-measured'];

// ---------------------------------------------------------------- colours

/** { r, g, b, a } for rgb()/rgba() (comma or space syntax), #rgb, #rrggbb, #rrggbbaa and `transparent`; null for anything else. */
function parseColor(input) {
  if (typeof input !== 'string') return null;
  const s = input.trim().toLowerCase();
  if (s === 'transparent') return { r: 0, g: 0, b: 0, a: 0 };
  let m = /^#([0-9a-f]{3})$/.exec(s);
  if (m) return { r: parseInt(m[1][0] + m[1][0], 16), g: parseInt(m[1][1] + m[1][1], 16), b: parseInt(m[1][2] + m[1][2], 16), a: 1 };
  m = /^#([0-9a-f]{6})([0-9a-f]{2})?$/.exec(s);
  if (m) return { r: parseInt(m[1].slice(0, 2), 16), g: parseInt(m[1].slice(2, 4), 16), b: parseInt(m[1].slice(4, 6), 16), a: m[2] ? parseInt(m[2], 16) / 255 : 1 };
  m = /^rgba?\(\s*([\d.]+)\s*[, ]\s*([\d.]+)\s*[, ]\s*([\d.]+)\s*(?:[,/]\s*([\d.]+%?)\s*)?\)$/.exec(s);
  if (!m) return null;
  let a = 1;
  if (m[4] !== undefined) a = m[4].endsWith('%') ? parseFloat(m[4]) / 100 : parseFloat(m[4]);
  const c = { r: Number(m[1]), g: Number(m[2]), b: Number(m[3]), a };
  if ([c.r, c.g, c.b].some((v) => !(v >= 0 && v <= 255)) || !(a >= 0 && a <= 1)) return null;
  return c;
}

const hex2 = (n) => Math.round(n).toString(16).padStart(2, '0');

/** '#rrggbb' (lower case) for an opaque colour, '#rrggbbaa' when it is translucent, null when it cannot be read. */
function rgbToHex(input) {
  const c = parseColor(input);
  if (!c) return null;
  const base = '#' + hex2(c.r) + hex2(c.g) + hex2(c.b);
  return c.a >= 1 ? base : base + hex2(c.a * 255);
}

/** `top` laid over the opaque `bottom` (both anything parseColor reads): { r, g, b, a: 1 }; null when either cannot be read. */
function compositeOver(top, bottom) {
  const t = parseColor(top);
  const b = parseColor(bottom);
  if (!t || !b) return null;
  const k = t.a + b.a * (1 - t.a);
  if (k <= 0) return { r: 0, g: 0, b: 0, a: 0 };
  const mix = (x, y) => (x * t.a + y * b.a * (1 - t.a)) / k;
  return { r: mix(t.r, b.r), g: mix(t.g, b.g), b: mix(t.b, b.b), a: k };
}

const lin = (v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4); };
const luminance = (c) => 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);

/** WCAG contrast ratio of a text colour on a background (a translucent text colour is laid over the background first); null when unreadable. */
function contrast(fg, bg) {
  const b = parseColor(bg);
  if (!b || parseColor(fg) === null) return null;
  const solid = b.a >= 1 ? b : compositeOver(bg, CANVAS);
  if (!solid) return null;
  const f = compositeOver(fg, '#' + hex2(solid.r) + hex2(solid.g) + hex2(solid.b));
  if (!f) return null;
  const l1 = luminance(f);
  const l2 = luminance(solid);
  return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}

/** WCAG large text: 24 px and up, or 18.66 px and up in weight 700 and up. */
function isLarge(sizePx, weight) {
  const s = parseFloat(sizePx);
  return s >= 24 || (s >= 18.66 && Number(weight) >= 700);
}

/** The ratio a text of that size and weight must reach. */
const requiredContrast = (sizePx, weight) => (isLarge(sizePx, weight) ? 3.0 : 4.5);

/**
 * The painted background behind an element. `tree` is the element's own computed background first, then each ancestor up to html:
 * [{ bg: 'rgba(...)', image: boolean }]. Walks up laying translucent colours over what is below, stops at the first opaque one, and falls to the
 * white canvas. A background IMAGE met before an opaque colour makes it unmeasurable (the text may sit on a photo or a gradient).
 * Returns { color: '#rrggbb', unmeasurable: false } or { color: null, unmeasurable: true, reason }.
 */
function paintedBackground(tree) {
  const layers = [];
  let base = CANVAS;
  for (const node of Array.isArray(tree) ? tree : []) {
    if (!node || typeof node !== 'object') return { color: null, unmeasurable: true, reason: 'a node of the tree is not readable' };
    if (node.image) return { color: null, unmeasurable: true, reason: 'a background image or gradient is on the path' };
    const c = parseColor(node.bg);
    if (c === null) return { color: null, unmeasurable: true, reason: 'unreadable background "' + node.bg + '"' };
    if (c.a <= 0) continue;
    if (c.a >= 1) { base = rgbToHex(node.bg); break; }
    layers.push(node.bg);
  }
  let acc = parseColor(base);
  for (let i = layers.length - 1; i >= 0; i--) acc = compositeOver(layers[i], '#' + hex2(acc.r) + hex2(acc.g) + hex2(acc.b));
  return { color: '#' + hex2(acc.r) + hex2(acc.g) + hex2(acc.b), unmeasurable: false };
}

// ---------------------------------------------------------------- fonts

const unquote = (s) => String(s).trim().replace(/^["']|["']$/g, '').trim();

/** Every family of a computed font-family list, unquoted, in order. */
function familyList(value) {
  if (typeof value !== 'string') return [];
  const out = [];
  let cur = '';
  let quote = '';
  for (const ch of value) {
    if (quote) { cur += ch; if (ch === quote) quote = ''; continue; }
    if (ch === '"' || ch === "'") { quote = ch; cur += ch; continue; }
    if (ch === ',') { if (cur.trim() !== '') out.push(unquote(cur)); cur = ''; continue; }
    cur += ch;
  }
  if (cur.trim() !== '') out.push(unquote(cur));
  return out;
}

/** The first family of a computed font-family list, unquoted ('' for nothing). */
const firstFamily = (value) => familyList(value)[0] || '';

const sameFamily = (a, b) => String(a).toLowerCase() === String(b).toLowerCase();

// ---------------------------------------------------------------- small normalisers

/** The device a viewport width belongs to in Divi 5: desktop above 980, tablet 768..980, phone below. */
const deviceFor = (width) => (width > 980 ? 'desktop' : width >= 768 ? 'tablet' : 'phone');

/** The written size step in force at a width: its own device step, else the next larger one that is written. null when none is. */
function stepFor(sizes, width) {
  const s = sizes || {};
  const order = deviceFor(width) === 'desktop' ? ['desktop'] : deviceFor(width) === 'tablet' ? ['tablet', 'desktop'] : ['phone', 'tablet', 'desktop'];
  for (const d of order) if (typeof s[d] === 'number') return s[d];
  return null;
}

const px = (v) => { const m = /^(-?[\d.]+)px$/.exec(String(v).trim()); return m ? parseFloat(m[1]) : null; };
const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
const normAlign = (v) => { const s = String(v || '').toLowerCase().replace('-webkit-', '').replace('-moz-', ''); return s === 'start' ? 'left' : s === 'end' ? 'right' : s; };
const normWeight = (v) => { const s = String(v || '').trim().toLowerCase(); return s === 'normal' ? '400' : s === 'bold' ? '700' : s; };
const sameColor = (a, b) => { const x = rgbToHex(a); const y = rgbToHex(b); return x !== null && x === y; };
const strip = (s) => String(s || '').replace(/\s+/g, '');

// ---------------------------------------------------------------- what the converter wrote

/** A px string ("56px") as an integer, else null. */
const pxInt = (v) => { const n = px(v); return n === null ? null : Math.round(n); };

/**
 * The style values a stored block carries, read out of its attributes at the paths DiviMarkup writes:
 *   heading  title.decoration.font.font.<device>.value.{size,color,weight,textAlign,family}
 *   text     content.decoration.bodyFont.body.font.<device>.value.{size,color,weight,family}; module.advanced.text.text.desktop.value.orientation
 *   button   button.innerContent.desktop.value.text; button.decoration.{background,font.font,border}.desktop.value
 *   section | row | column   module.decoration.{background,spacing.padding,sizing.maxWidth,border.radius}.desktop.value
 * Returns { type, ...values } with only the values that are written; type is heading|text|button|section|row|column|other.
 */
function writtenOf(blockName, attrs) {
  const a = attrs && typeof attrs === 'object' ? attrs : {};
  const at = (obj, path) => path.reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), obj);
  const name = String(blockName || '').replace(/^divi\//, '');
  const out = { type: 'other' };
  const set = (k, v) => { if (v !== undefined && v !== null && v !== '') out[k] = v; };

  const fontOf = (fontPath) => {
    const f = at(a, fontPath) || {};
    const val = (d) => (f[d] && f[d].value) || {};
    const sizes = {};
    for (const d of ['desktop', 'tablet', 'phone']) { const n = pxInt(val(d).size); if (n !== null) sizes[d] = n; }
    set('color', typeof val('desktop').color === 'string' ? val('desktop').color.toLowerCase() : undefined);
    set('weight', val('desktop').weight === undefined ? undefined : String(val('desktop').weight));
    set('family', typeof val('desktop').family === 'string' ? val('desktop').family : undefined);
    if (Object.keys(sizes).length) out.sizes = sizes;
    return val('desktop');
  };

  if (name === 'heading') {
    out.type = 'heading';
    const d = fontOf(['title', 'decoration', 'font', 'font']);
    set('align', d.textAlign);
    set('level', d.headingLevel);
  } else if (name === 'text') {
    out.type = 'text';
    fontOf(['content', 'decoration', 'bodyFont', 'body', 'font']);
    set('align', at(a, ['module', 'advanced', 'text', 'text', 'desktop', 'value', 'orientation']));
  } else if (name === 'button') {
    out.type = 'button';
    set('text', at(a, ['button', 'innerContent', 'desktop', 'value', 'text']));
    set('color', String(at(a, ['button', 'decoration', 'font', 'font', 'desktop', 'value', 'color']) || '').toLowerCase());
    set('bg', String(at(a, ['button', 'decoration', 'background', 'desktop', 'value', 'color']) || '').toLowerCase());
    const r = at(a, ['button', 'decoration', 'border', 'desktop', 'value', 'radius']);
    if (r && r.topLeft !== undefined) set('radius', pxInt(r.topLeft));
  } else if (name === 'section' || name === 'row' || name === 'column') {
    out.type = name;
    set('bg', String(at(a, ['module', 'decoration', 'background', 'desktop', 'value', 'color']) || '').toLowerCase());
    const pad = at(a, ['module', 'decoration', 'spacing', 'desktop', 'value', 'padding']);
    if (pad && typeof pad === 'object') {
      const p = {};
      for (const side of ['top', 'right', 'bottom', 'left']) { const n = pxInt(pad[side]); if (n !== null) p[side] = n; }
      if (Object.keys(p).length) out.padding = p;
    }
    const mw = pxInt(at(a, ['module', 'decoration', 'sizing', 'desktop', 'value', 'maxWidth']));
    if (mw !== null) out.maxWidth = mw;
    const r = at(a, ['module', 'decoration', 'border', 'desktop', 'value', 'radius']);
    if (r && r.topLeft !== undefined) set('radius', pxInt(r.topLeft));
  }
  return out;
}

// ---------------------------------------------------------------- comparisons (each returns a list of { code, message })

const fail = (code, message) => ({ code, message });

/**
 * heading and text: written vs Divi (1280, 900, 400) vs source (1280).
 *   divi: { 1280: leaf, 900: leaf, 400: leaf }  leaf = { color, size, weight, align, family }  (computed strings)
 *   src:  the source element's { color, size, weight, align, family } or null
 */
function textProblems(w, divi, src) {
  const out = [];
  const d = divi[1280] || {};
  if (w.color !== undefined) {
    if (!sameColor(d.color, w.color)) out.push(fail('hs-color', 'Divi shows ' + rgbToHex(d.color) + ', the converter wrote ' + w.color));
    if (src && !sameColor(src.color, w.color)) out.push(fail('hs-color', 'the source shows ' + rgbToHex(src.color) + ', the converter wrote ' + w.color));
  }
  if (w.weight !== undefined) {
    if (normWeight(d.weight) !== normWeight(w.weight)) out.push(fail('hs-weight', 'Divi shows weight ' + d.weight + ', the converter wrote ' + w.weight));
    if (src && normWeight(src.weight) !== normWeight(w.weight)) out.push(fail('hs-weight', 'the source shows weight ' + src.weight + ', the converter wrote ' + w.weight));
  }
  if (w.align !== undefined) {
    if (normAlign(d.align) !== normAlign(w.align)) out.push(fail('hs-align', 'Divi aligns ' + d.align + ', the converter wrote ' + w.align));
    if (src && normAlign(src.align) !== normAlign(w.align)) out.push(fail('hs-align', 'the source aligns ' + src.align + ', the converter wrote ' + w.align));
  }
  if (w.sizes) {
    for (const width of [1280, 900, 400]) {
      const want = stepFor(w.sizes, width);
      const got = px((divi[width] || {}).size);
      if (want === null) continue;
      if (got === null || Math.abs(got - want) > 0.5) out.push(fail('hs-size', 'Divi shows ' + ((divi[width] || {}).size) + ' at ' + width + ' px, the converter wrote ' + want + 'px'));
    }
    if (src && typeof w.sizes.desktop === 'number') {
      const s = px(src.size);
      if (s === null || Math.round(clamp(s, SIZE_MIN, SIZE_MAX)) !== w.sizes.desktop) out.push(fail('hs-size', 'the source shows ' + src.size + ' (read as ' + (s === null ? '?' : Math.round(clamp(s, SIZE_MIN, SIZE_MAX))) + 'px), the converter wrote ' + w.sizes.desktop + 'px'));
    }
  }
  if (w.family !== undefined) {
    if (!sameFamily(firstFamily(d.family), w.family)) out.push(fail('hs-family', 'Divi starts with "' + firstFamily(d.family) + '", the converter wrote "' + w.family + '"'));
    if (src && !familyList(src.family).some((f) => sameFamily(f, w.family))) out.push(fail('hs-family', 'the source family list [' + familyList(src.family).join(', ') + '] has no "' + w.family + '"'));
  }
  return out;
}

/**
 * button: label, fill, label colour, the contrast of the label on the fill, and the source's own fill and colour. Radius goes under hs-radius.
 *   leaf: { label, color, bg, radius (px string) }
 */
function buttonProblems(w, leaf, src) {
  const out = [];
  const l = leaf || {};
  if (w.text !== undefined && strip(l.label) !== strip(w.text)) out.push(fail('hs-button', 'Divi shows the label "' + String(l.label).trim() + '", the converter wrote "' + w.text + '"'));
  if (w.bg !== undefined) {
    if (!sameColor(l.bg, w.bg)) out.push(fail('hs-button', 'Divi fills the button ' + rgbToHex(l.bg) + ', the converter wrote ' + w.bg));
    if (src && !sameColor(src.bg, w.bg)) out.push(fail('hs-button', 'the source fills the button ' + rgbToHex(src.bg) + ', the converter wrote ' + w.bg));
  }
  if (w.color !== undefined) {
    if (!sameColor(l.color, w.color)) out.push(fail('hs-button', 'Divi colours the label ' + rgbToHex(l.color) + ', the converter wrote ' + w.color));
    if (src && !sameColor(src.color, w.color)) out.push(fail('hs-button', 'the source colours the label ' + rgbToHex(src.color) + ', the converter wrote ' + w.color));
  }
  if (w.bg !== undefined && w.color !== undefined) {
    const c = contrast(l.color, l.bg);
    if (c === null || c < 4.5) out.push(fail('hs-button', 'the label ' + rgbToHex(l.color) + ' on the fill ' + rgbToHex(l.bg) + ' is ' + (c === null ? 'unreadable' : c.toFixed(2)) + ':1, under 4.5:1'));
  }
  if (w.radius !== undefined) {
    const r = px(l.radius);
    if (r === null || Math.abs(r - w.radius) > 0.5) out.push(fail('hs-radius', 'Divi rounds the button ' + l.radius + ', the converter wrote ' + w.radius + 'px'));
  }
  return out;
}

/**
 * section, row, column: the box the converter styled.
 *   box:  Divi's { bg, padding: {top,right,bottom,left}, radius, maxWidth, boxSizing, width, gapLeft, gapRight } for the element
 *   src:  the source anchor's { tree, padding } or null
 */
function boxProblems(w, box, src) {
  const out = [];
  const b = box || {};
  if (w.bg !== undefined) {
    if (!sameColor(b.bg, w.bg)) out.push(fail('hs-bg', 'Divi paints the ' + w.type + ' ' + rgbToHex(b.bg) + ', the converter wrote ' + w.bg));
    if (src && src.tree) {
      const p = paintedBackground(src.tree);
      if (!p.unmeasurable && p.color !== w.bg) out.push(fail('hs-bg', 'the source paints the ' + w.type + ' ' + p.color + ' (first background from its anchor up), the converter wrote ' + w.bg));
    }
  }
  if (w.padding) {
    for (const side of Object.keys(w.padding)) {
      const got = px((b.padding || {})[side]);
      if (got === null || Math.abs(got - w.padding[side]) > 0.5) out.push(fail('hs-padding', 'Divi pads the ' + w.type + ' ' + side + ' ' + (b.padding || {})[side] + ', the converter wrote ' + w.padding[side] + 'px'));
      if (src && src.padding) {
        const s = px(src.padding[side]);
        if (s === null || Math.round(clamp(s, 0, PAD_MAX)) !== w.padding[side]) out.push(fail('hs-padding', 'the source pads the ' + w.type + ' ' + side + ' ' + src.padding[side] + ', the converter wrote ' + w.padding[side] + 'px'));
      }
    }
  }
  if (w.maxWidth !== undefined) {
    const pad = (k) => px((b.padding || {})[k]) || 0;
    const limit = b.boxSizing === 'border-box' ? w.maxWidth : w.maxWidth + pad('left') + pad('right');
    if (!(typeof b.width === 'number') || b.width > limit + 1) out.push(fail('hs-max-width', 'the row is ' + b.width + 'px wide, the converter wrote a maximum of ' + w.maxWidth + 'px (' + (b.boxSizing === 'border-box' ? 'border-box' : 'content-box plus padding ' + (limit - w.maxWidth) + 'px') + ')'));
    else if (typeof b.gapLeft !== 'number' || typeof b.gapRight !== 'number' || Math.abs(b.gapLeft - b.gapRight) > 1) out.push(fail('hs-max-width', 'the row is not centred: ' + b.gapLeft + 'px on the left, ' + b.gapRight + 'px on the right'));
  }
  if (w.radius !== undefined) {
    const r = px(b.radius);
    if (r === null || Math.abs(r - w.radius) > 0.5) out.push(fail('hs-radius', 'Divi rounds the ' + w.type + ' ' + b.radius + ', the converter wrote ' + w.radius + 'px'));
  }
  return out;
}

/**
 * hs-contrast for one text leaf (heading, text paragraph or list item, button label) at 1280: its computed colour against the painted
 * background. `underWrittenPaint`: some block around it (or the button itself) has a background the converter wrote. An unmeasurable
 * background (an image on the path) is a failure only under a written paint; otherwise it is reported as not judged ({ skipped: true }).
 */
function contrastProblem(leaf, underWrittenPaint) {
  const bg = paintedBackground(leaf.tree);
  if (bg.unmeasurable) {
    if (underWrittenPaint) return { problem: fail('hs-contrast', 'the background cannot be measured (' + bg.reason + ') and the converter painted a block around this text'), skipped: false };
    return { problem: null, skipped: true };
  }
  const need = requiredContrast(leaf.size, leaf.weight);
  const c = contrast(leaf.color, bg.color);
  if (c === null) return { problem: fail('hs-contrast', 'the colour ' + leaf.color + ' cannot be read'), skipped: false };
  if (c < need) return { problem: fail('hs-contrast', rgbToHex(leaf.color) + ' on ' + bg.color + ' is ' + c.toFixed(2) + ':1, under ' + need + ':1 (' + (need === 3 ? 'large' : 'normal') + ' text)'), skipped: false };
  return { problem: null, skipped: false, ratio: c };
}

// ---------------------------------------------------------------- the two "never skipped" rules

/**
 * hs-mapping. `results` = [{ page, block, snippet, srcPath, sourceFound, sourceText (whitespace stripped), diviFound, kind }]. A source element that
 * is missing, or whose text does not start with the snippet (a container: does not contain it; a module that merged several pieces: the snippet
 * may run on past the first piece, `pieces` > 1), and a module the Divi page does not show, FAIL; nothing is skipped. Returns a list of strings.
 */
function mappingProblems(results) {
  const out = [];
  for (const r of Array.isArray(results) ? results : []) {
    const need = strip(r.snippet);
    const where = (r.page || '?') + ' block ' + r.block + (r.srcPath ? ' (' + r.srcPath + ')' : '');
    if (!r.srcPath && r.kind !== 'container' && need !== '') out.push(where + ': the converter recorded no source element for this module');
    if (r.srcPath) {
      if (!r.sourceFound) out.push(where + ': the source element was not found in the browser');
      else if (need !== '') {
        const text = strip(r.sourceText);
        // A text module that merged several source pieces has the first piece as its source; its snippet may run on into the next piece.
        const merged = r.kind !== 'container' && r.pieces > 1 && text !== '' && need.startsWith(text);
        const ok = merged || (r.kind === 'container' ? text.includes(need) : text.startsWith(need));
        if (!ok) out.push(where + ': the source element\'s text does not ' + (r.kind === 'container' ? 'contain' : 'start with') + ' "' + r.snippet + '"');
      }
    }
    if (r.kind !== 'container' && need !== '' && r.diviFound === false) out.push(where + ': no Divi module on the page shows "' + r.snippet + '"');
  }
  return out;
}

/** hs-measured. `results` = [{ page, measured }]. A page with zero measured modules FAILS, and so does a run with no page at all. */
function emptyRunProblems(results) {
  const list = Array.isArray(results) ? results : [];
  if (list.length === 0) return ['the run measured no page at all'];
  return list.filter((r) => !(r.measured > 0)).map((r) => (r.page || '?') + ': no module was measured');
}

// ---------------------------------------------------------------- one page

const KIND_OF_BLOCK = { 'divi/heading': 'heading', 'divi/text': 'text', 'divi/button': 'button' };
const STRUCTURE = ['divi/section', 'divi/row', 'divi/column'];

/**
 * Evaluates one page. Input:
 *   page      { name, plan_ok, plan_problem, blocks: [{ i, block, parent, snippet, written, src }] }       (the PHP helper's output)
 *   source    { [src path]: probe }                         probe = sourceProbe's entry ({ found, text, color, size, weight, align, family, bg, tree, padding })
 *   leaves    { 1280: [leaf], 900: [leaf], 400: [leaf] }    diviProbe's leaves, the same order at every width
 *   overflow  the Divi page's scrollWidth at 400 px
 * Returns { checks: [{ code, ok, message, where }], mapping: [result], measured: n, skippedContrast: n }.
 * A leaf = { kind (heading|text|button), text (whitespace stripped), label, color, size, weight, align, family, bg, radius, tree,
 *            section|row|column: box or null }.
 */
function evaluatePage(page, source, leaves, overflow) {
  const checks = [];
  const add = (code, ok, message, where) => checks.push({ code, ok, message, where });
  const mapping = [];
  const blocks = page.blocks || [];
  const base = leaves[1280] || [];
  let measured = 0;
  let skippedContrast = 0;

  if (!page.plan_ok) add('hs-mapping', false, 'the stored layout and the converter\'s plan disagree: ' + page.plan_problem, page.name);
  for (const w of [900, 400]) {
    if ((leaves[w] || []).length !== base.length) add('hs-mapping', false, 'the Divi page shows ' + base.length + ' modules at 1280 px but ' + (leaves[w] || []).length + ' at ' + w + ' px', page.name);
  }

  // Which Divi leaf shows which block: in document order, kind and text must agree.
  const leafOf = new Map();
  let next = 0;
  for (const b of blocks) {
    const kind = KIND_OF_BLOCK[b.block];
    if (!kind || strip(b.snippet) === '') continue;
    const need = strip(b.snippet);
    let at = -1;
    for (let j = next; j < base.length; j++) {
      if (base[j].kind === kind && strip(base[j].text).includes(need)) { at = j; break; }
    }
    if (at >= 0) { leafOf.set(b.i, at); next = at + 1; }
    const probe = b.src ? source[b.src] : null;
    mapping.push({ page: page.name, block: b.i, snippet: b.snippet, srcPath: b.src, sourceFound: !!(probe && probe.found), sourceText: probe && probe.text, diviFound: at >= 0, kind: 'module', pieces: b.pieces || 1 });
  }
  const moduleLeaf = (idx) => (leafOf.has(idx) ? leafOf.get(idx) : null);

  // The first measured module inside a structure block gives its Divi element.
  const firstLeafIn = (idx) => {
    for (const b of blocks) {
      if (!leafOf.has(b.i)) continue;
      for (let p = b.parent; p >= 0; p = blocks[p].parent) if (p === idx) return leafOf.get(b.i);
    }
    return null;
  };

  const writtenPaintAbove = (idx) => {
    for (let p = blocks[idx].parent; p >= 0; p = blocks[p].parent) {
      const w = writtenOf(blocks[p].block, blocks[p].written);
      if (w.bg !== undefined) return true;
    }
    return false;
  };

  for (const b of blocks) {
    const w = writtenOf(b.block, b.written);
    const probe = b.src ? source[b.src] : null;
    const src = probe && probe.found ? probe : null;
    const where = page.name + ' block ' + b.i + ' ' + b.block.replace('divi/', '') + ' "' + b.snippet + '"';

    if (STRUCTURE.includes(b.block)) {
      if (b.src && strip(b.snippet) !== '') {
        mapping.push({ page: page.name, block: b.i, snippet: b.snippet, srcPath: b.src, sourceFound: !!src, sourceText: probe && probe.text, diviFound: true, kind: 'container' });
      }
      if (w.bg === undefined && w.padding === undefined && w.maxWidth === undefined && w.radius === undefined) continue;
      const at = firstLeafIn(b.i);
      if (at === null) { add('hs-mapping', false, 'no Divi module was found inside this ' + w.type + ', so its box could not be measured', where); continue; }
      const box = base[at][w.type] || null;
      if (!box) { add('hs-mapping', false, 'the Divi page has no ' + w.type + ' element around the module', where); continue; }
      measured++;
      const problems = boxProblems(w, box, src);
      for (const code of ['hs-bg', 'hs-padding', 'hs-max-width', 'hs-radius']) {
        const relevant = (code === 'hs-bg' && w.bg !== undefined) || (code === 'hs-padding' && w.padding) || (code === 'hs-max-width' && w.maxWidth !== undefined) || (code === 'hs-radius' && w.radius !== undefined);
        if (!relevant) continue;
        const mine = problems.filter((p) => p.code === code);
        if (mine.length === 0) add(code, true, w.type + ' ok', where);
        for (const p of mine) add(code, false, p.message, where);
      }
      continue;
    }

    const at = moduleLeaf(b.i);
    if (at === null) continue; // an image, a divider, or a module hs-mapping already failed
    if (w.type === 'heading' || w.type === 'text') {
      const divi = { 1280: base[at], 900: (leaves[900] || [])[at] || {}, 400: (leaves[400] || [])[at] || {} };
      measured++;
      const problems = textProblems(w, divi, src);
      const asked = { 'hs-color': w.color !== undefined, 'hs-weight': w.weight !== undefined, 'hs-align': w.align !== undefined, 'hs-size': !!w.sizes, 'hs-family': w.family !== undefined };
      for (const code of Object.keys(asked)) {
        if (!asked[code]) continue;
        const mine = problems.filter((p) => p.code === code);
        if (mine.length === 0) add(code, true, w.type + ' ok', where);
        for (const p of mine) add(code, false, p.message, where);
      }
    } else if (w.type === 'button') {
      measured++;
      const problems = buttonProblems(w, base[at], src);
      const rest = problems.filter((p) => p.code === 'hs-button');
      if (w.text !== undefined || w.bg !== undefined || w.color !== undefined) {
        if (rest.length === 0) add('hs-button', true, 'button ok', where);
        for (const p of rest) add('hs-button', false, p.message, where);
      }
      if (w.radius !== undefined) {
        const mine = problems.filter((p) => p.code === 'hs-radius');
        if (mine.length === 0) add('hs-radius', true, 'button ok', where);
        for (const p of mine) add('hs-radius', false, p.message, where);
      }
    }
  }

  // hs-contrast: every heading, text and button leaf of the page, written or not.
  const blockOfLeaf = new Map();
  for (const [bi, li] of leafOf) blockOfLeaf.set(li, bi);
  base.forEach((leaf, j) => {
    const bi = blockOfLeaf.get(j);
    let paint = false;
    if (bi !== undefined) {
      const w = writtenOf(blocks[bi].block, blocks[bi].written);
      paint = writtenPaintAbove(bi) || (w.type === 'button' && w.bg !== undefined);
    }
    const label = page.name + ' ' + leaf.kind + ' "' + String(leaf.label || leaf.text).trim().slice(0, 30) + '"';
    const r = contrastProblem(leaf, paint);
    if (r.skipped) { skippedContrast++; return; }
    add('hs-contrast', r.problem === null, r.problem ? r.problem.message : 'ratio ' + r.ratio.toFixed(2), label);
  });

  add('hs-overflow', typeof overflow === 'number' && overflow <= 400, 'the page is ' + overflow + 'px wide at 400 px', page.name);
  return { checks, mapping, measured, skippedContrast };
}

// ---------------------------------------------------------------- in-page probes (self-contained: Playwright serialises them)

/** In the SOURCE page (JavaScript off, every request aborted): the computed values of the element at each XPath. */
function sourceProbe(paths) {
  const stripWs = (s) => String(s || '').replace(/\s+/g, '');
  const treeOf = (el) => {
    const t = [];
    for (let e = el; e; e = e.parentElement) {
      const cs = getComputedStyle(e);
      t.push({ bg: cs.backgroundColor, image: !!cs.backgroundImage && cs.backgroundImage !== 'none' });
    }
    return t;
  };
  return paths.map((p) => {
    let el = null;
    try { el = document.evaluate(p, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue; } catch (e) { el = null; }
    if (!el || el.nodeType !== 1) return { found: false };
    const cs = getComputedStyle(el);
    return {
      found: true,
      tag: el.tagName.toLowerCase(),
      text: stripWs(el.textContent),
      color: cs.color,
      size: cs.fontSize,
      weight: cs.fontWeight,
      align: cs.textAlign,
      family: cs.fontFamily,
      bg: cs.backgroundColor,
      tree: treeOf(el),
      padding: { top: cs.paddingTop, right: cs.paddingRight, bottom: cs.paddingBottom, left: cs.paddingLeft },
    };
  });
}

/** In the DIVI page: every heading, text and button module (the innermost `.et_pb_module`s) with its computed values and its section, row and column. */
function diviProbe() {
  const stripWs = (s) => String(s || '').replace(/\s+/g, '');
  const treeOf = (el) => {
    const t = [];
    for (let e = el; e; e = e.parentElement) {
      const cs = getComputedStyle(e);
      t.push({ bg: cs.backgroundColor, image: !!cs.backgroundImage && cs.backgroundImage !== 'none' });
    }
    return t;
  };
  const box = (el) => {
    if (!el) return null;
    const cs = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    const parent = el.parentElement;
    let gapLeft = null;
    let gapRight = null;
    if (parent) {
      const pr = parent.getBoundingClientRect();
      const pcs = getComputedStyle(parent);
      gapLeft = Math.round((r.left - (pr.left + parseFloat(pcs.paddingLeft || '0'))) * 100) / 100;
      gapRight = Math.round(((pr.right - parseFloat(pcs.paddingRight || '0')) - r.right) * 100) / 100;
    }
    return {
      bg: cs.backgroundColor,
      padding: { top: cs.paddingTop, right: cs.paddingRight, bottom: cs.paddingBottom, left: cs.paddingLeft },
      radius: cs.borderTopLeftRadius,
      maxWidth: cs.maxWidth,
      boxSizing: cs.boxSizing,
      width: Math.round(r.width * 100) / 100,
      gapLeft,
      gapRight,
    };
  };
  const mods = [...document.querySelectorAll('.et_pb_module')].filter((m) => !m.querySelector('.et_pb_module') && !m.querySelector('.et_pb_section, .et_pb_row, .et_pb_column'));
  const out = [];
  for (const m of mods) {
    const cls = m.className || '';
    let kind = null;
    let leaf = null;
    if (/\bet_pb_heading\b/.test(cls)) { kind = 'heading'; leaf = m.querySelector('h1, h2, h3, h4, h5, h6'); }
    else if (/\bet_pb_text\b/.test(cls)) { kind = 'text'; leaf = m.querySelector('.et_pb_text_inner p, .et_pb_text_inner li') || m.querySelector('.et_pb_text_inner') || m; }
    else if (/\bet_pb_button\b/.test(cls) || m.querySelector('a.et_pb_button')) { kind = 'button'; leaf = m.matches('a') ? m : m.querySelector('a.et_pb_button, a'); }
    if (!kind || !leaf) continue;
    const cs = getComputedStyle(leaf);
    out.push({
      kind,
      text: stripWs(m.textContent),
      label: (leaf.textContent || '').trim(),
      color: cs.color,
      size: cs.fontSize,
      weight: cs.fontWeight,
      align: cs.textAlign,
      family: cs.fontFamily,
      bg: cs.backgroundColor,
      radius: cs.borderTopLeftRadius,
      tree: treeOf(leaf),
      section: box(m.closest('.et_pb_section')),
      row: box(m.closest('.et_pb_row')),
      column: box(m.closest('.et_pb_column')),
    });
  }
  return { leaves: out, scrollWidth: document.documentElement.scrollWidth };
}

module.exports = {
  CODES, SIZE_MIN, SIZE_MAX, PAD_MAX, CANVAS,
  parseColor, rgbToHex, compositeOver, contrast, isLarge, requiredContrast, paintedBackground,
  familyList, firstFamily, sameFamily, deviceFor, stepFor, strip,
  writtenOf, textProblems, buttonProblems, boxProblems, contrastProblem,
  mappingProblems, emptyRunProblems, evaluatePage, sourceProbe, diviProbe,
};
