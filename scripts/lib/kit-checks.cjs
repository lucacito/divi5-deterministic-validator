'use strict';
/*
 * Pure pieces of scripts/verify-kits.cjs, kept apart so they can be tested without the dev site (scripts/test-verify-kits.cjs):
 *  - the kit sheet template (tokenised markup with kit markers; the script resolves it in PHP through the REAL pipeline),
 *  - the run-title format and the cell selection,
 *  - reading the EXPECTED button values from the resolved markup (the adapter output the page was built from),
 *  - comparing them with the computed style the browser measured (rest and hover), contrast on the painted surface,
 *  - a small PNG decoder (the surface under each button is read from a real screenshot, so a photo band is measured, not guessed),
 *  - the in-page measurement (runs in the browser; it must stay self-contained: Playwright serialises it).
 */
const zlib = require('zlib');
const { parseColor, over, ratioOf } = require('./recipe-checks.cjs');

/** The sheet: one section per surface, each with a primary (`control`) and a secondary (`control-secondary`) button, in this order. */
const SURFACES = [
  { key: 'bg', on: 'light', label: 'Light on bg', bg: '{"color":"{{aied:color:bg}}"}', ink: 'text' },
  { key: 'surface', on: 'light', label: 'Light on surface', bg: '{"color":"{{aied:color:surface}}"}', ink: 'text' },
  { key: 'accent', on: 'accent', label: 'Accent band', bg: '{"color":"{{aied:color:accent}}"}', ink: 'accent-text' },
  // The photo band's background is the shape the dark recipes ship (card-grid-4): a one-colour overlay gradient over a pack image.
  { key: 'dark', on: 'dark', label: 'Photo band', bg: '{"gradient":{"enabled":"on","stops":[{"position":0,"color":"{{aied:color:overlay}}"},{"position":100,"color":"{{aied:color:overlay}}"}],"length":"100%","overlaysImage":"on"},"image":{"url":"{{aied:image:section-bg-slate-1}}"},"color":"{{aied:color:bg}}"}', ink: 'inverse' },
];
const ROLES = [{ role: 'control', text: 'Primary action' }, { role: 'control-secondary', text: 'Secondary action' }];

/** The tokenised sheet markup (8 buttons: SURFACES x ROLES, in document order). */
function sheetMarkup() {
  const v = '"builderVersion":"5.8.0"';
  return SURFACES.map((s) => {
    const marker = (role) => (s.on === 'light' ? `{"kit":"${role}"}` : `{"kit":"${role}","on":"${s.on}"}`);
    const buttons = ROLES.map((r) => `<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"${r.text}"}}},"decoration":{"font":{"font":{"desktop":{"value":{"family":"{{aied:font:body}}"}}}}}},${v},"aied":${marker(r.role)}} /-->`).join('\n');
    return `<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":${s.bg}}},"spacing":{"desktop":{"value":{"padding":{"top":"64px","bottom":"64px"}}}}}},${v}} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},${v}} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},${v}} -->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>${s.label}</p>"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"{{aied:color:${s.ink}}}","family":"{{aied:font:body}}"}}}}}}},${v}} /-->
${buttons}
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->`;
  }).join('\n');
}

/** The slots of the sheet in document order: [{ surface, on, role }]. */
const SLOTS = SURFACES.flatMap((s) => ROLES.map((r) => ({ surface: s.key, on: s.on, role: r.role })));

// ---- run titles and selection ----------------------------------------------------------------------------------------
const TITLE_PREFIX = 'aied kit ';
const runTitle = (token, kit, language) => `${TITLE_PREFIX}${token} ${kit} ${language}`;
/** { token, kit, language } of a run title, or null for anything else. */
function parseRunTitle(title) {
  const m = /^aied kit (\d+-[0-9a-f]{6}) ([a-z0-9]+(?:-[a-z0-9]+)*) ([a-z0-9]+(?:-[a-z0-9]+)*)$/.exec(String(title));
  return m ? { token: m[1], kit: m[2], language: m[3] } : null;
}

/**
 * The (kit, language) cells. No flags: every kit under each of `sheetLanguages` (editorial, glass-dark), then every language under its own
 * default kit. --kits narrows the kits (under the two sheet languages), --languages narrows the languages (every kit), both = their product.
 * Unknown ids throw. `languages` is [{ id, kit }].
 */
function selectCells({ kits: optKits = null, languages: optLangs = null } = {}, kitIds, languages, sheetLanguages = ['editorial', 'glass-dark']) {
  const langIds = languages.map((l) => l.id);
  const badK = (optKits || []).filter((k) => !kitIds.includes(k));
  if (badK.length) throw new Error('Unknown kit(s): ' + badK.join(', ') + '\nValid: ' + kitIds.join(', '));
  const badL = (optLangs || []).filter((l) => !langIds.includes(l));
  if (badL.length) throw new Error('Unknown language(s): ' + badL.join(', ') + '\nValid: ' + langIds.join(', '));
  const cells = [];
  if (optKits || optLangs) {
    for (const k of optKits || kitIds) for (const l of optLangs || sheetLanguages) cells.push([k, l]);
  } else {
    for (const l of sheetLanguages) for (const k of kitIds) cells.push([k, l]);
    for (const l of languages) if (l.kit) cells.push([l.kit, l.id]);
  }
  const seen = new Set();
  return cells.filter(([k, l]) => { const key = k + '/' + l; if (seen.has(key)) return false; seen.add(key); return true; });
}

// ---- expectations from the resolved markup ---------------------------------------------------------------------------
/** The attribute objects of every `divi/button` block in the markup, in document order. */
function buttonAttrs(markup) {
  const out = [];
  const re = /<!--\s+wp:divi\/button\s+(\{(?:[^-}]|-(?!->)|\}(?!\s+\/?-->))*\})\s+\/?-->/g;
  let m;
  while ((m = re.exec(markup))) out.push(JSON.parse(m[1]));
  return out;
}
const dig = (o, ...keys) => keys.reduce((a, k) => (a && typeof a === 'object' ? a[k] : undefined), o);

/** A box-shadow from the markup ({style, horizontal, vertical, blur, color}) as the expectation, or null. */
function shadowExpect(s) {
  if (!s || typeof s !== 'object') return null;
  return { x: s.horizontal !== undefined ? parseFloat(s.horizontal) : null, y: s.vertical !== undefined ? parseFloat(s.vertical) : null, blur: parseFloat(s.blur), color: s.color || null, style: s.style || null };
}

/**
 * What the kit says about one button, read ONLY from its resolved attributes (the adapter's patch merged into the sheet's button):
 * { rest: {fill, label, top:{width,style,color}, bottom:{...}, radius, capitalization, letterSpacing, weight, padding}, hover: {fill, label, border},
 *   shadow: {rest, hover} (null = none), lift: -4 | null }.
 */
function expectFromAttrs(attrs) {
  const d = dig(attrs, 'button', 'decoration') || {};
  const styles = dig(d, 'border', 'desktop', 'value', 'styles') || {};
  const all = styles.all || {};
  const side = (k) => { const s = Object.assign({}, all, styles[k] || {}); return { width: parseFloat(s.width || '0'), style: s.style || null, color: s.color || null }; };
  const font = dig(d, 'font', 'font', 'desktop', 'value') || {};
  const radius = dig(d, 'border', 'desktop', 'value', 'radius') || {};
  const md = dig(attrs, 'module', 'decoration') || {};
  const pad = dig(md, 'spacing', 'desktop', 'value', 'padding') || null;
  const shadowRest = shadowExpect(dig(md, 'boxShadow', 'desktop', 'value'));
  const shadowHover = shadowExpect(dig(md, 'boxShadow', 'desktop', 'hover')) || shadowRest;
  const ty = dig(md, 'transform', 'desktop', 'hover', 'translate', 'y');
  return {
    rest: {
      fill: dig(d, 'background', 'desktop', 'value', 'color') || null,
      label: font.color || null,
      top: side('top'),
      bottom: side('bottom'),
      radius: ['topLeft', 'topRight', 'bottomRight', 'bottomLeft'].map((k) => (radius[k] !== undefined ? parseFloat(radius[k]) : null)),
      capitalization: font.capitalization || null,
      letterSpacing: font.letterSpacing !== undefined ? parseFloat(font.letterSpacing) : null,
      weight: font.weight || null,
      padding: pad ? ['top', 'right', 'bottom', 'left'].map((k) => (pad[k] !== undefined && pad[k] !== '' ? parseFloat(pad[k]) : null)) : null,
    },
    hover: {
      fill: dig(d, 'background', 'desktop', 'hover', 'color') || null,
      label: dig(d, 'font', 'font', 'desktop', 'hover', 'color') || null,
      border: dig(d, 'border', 'desktop', 'hover', 'styles', 'all', 'color') || null,
    },
    shadow: { rest: shadowRest, hover: shadowHover },
    lift: ty !== undefined ? parseFloat(ty) : null,
  };
}

// ---- computed-style parsing and comparison ---------------------------------------------------------------------------
/** A colour string (hex or rgb[a]) as {r,g,b,a}, or null. */
function colour(str) {
  const s = String(str || '').trim();
  let m = /^#([0-9a-f]{3})$/i.exec(s);
  if (m) return { r: parseInt(m[1][0] + m[1][0], 16), g: parseInt(m[1][1] + m[1][1], 16), b: parseInt(m[1][2] + m[1][2], 16), a: 1 };
  m = /^#([0-9a-f]{6})$/i.exec(s);
  if (m) return { r: parseInt(m[1].slice(0, 2), 16), g: parseInt(m[1].slice(2, 4), 16), b: parseInt(m[1].slice(4, 6), 16), a: 1 };
  if (s === 'transparent') return { r: 0, g: 0, b: 0, a: 0 };
  return parseColor(s);
}
/** Same colour within 1 per channel and 0.02 alpha; two fully transparent colours are equal whatever their channels. */
function sameColour(a, b) {
  const x = colour(a); const y = colour(b);
  if (!x || !y) return false;
  if (x.a < 0.005 && y.a < 0.005) return true;
  return Math.abs(x.r - y.r) <= 1 && Math.abs(x.g - y.g) <= 1 && Math.abs(x.b - y.b) <= 1 && Math.abs(x.a - y.a) <= 0.02;
}
/** A computed box-shadow as a list of {color, x, y, blur, spread, inset}; [] for none. */
function parseShadows(str) {
  const s = String(str || '').trim();
  if (!s || s === 'none') return [];
  const parts = []; let depth = 0; let cur = '';
  for (const ch of s) { if (ch === '(') depth++; if (ch === ')') depth--; if (ch === ',' && depth === 0) { parts.push(cur.trim()); cur = ''; } else cur += ch; }
  if (cur.trim()) parts.push(cur.trim());
  return parts.map((p) => {
    const c = /rgba?\([^)]*\)/.exec(p);
    const nums = p.replace(/rgba?\([^)]*\)/, '').replace('inset', '').trim().split(/\s+/).filter(Boolean).map(parseFloat);
    return { color: c ? c[0] : null, x: nums[0] || 0, y: nums[1] || 0, blur: nums[2] || 0, spread: nums[3] || 0, inset: /\binset\b/.test(p) };
  });
}
/** The translateY (px) of a computed transform ('none' = 0). */
function translateY(str) {
  const s = String(str || 'none').trim();
  if (s === 'none') return 0;
  let m = /^matrix\(([^)]+)\)$/.exec(s);
  if (m) return parseFloat(m[1].split(',')[5]);
  m = /^matrix3d\(([^)]+)\)$/.exec(s);
  if (m) return parseFloat(m[1].split(',')[13]);
  return NaN;
}
const px = (v) => (v === 'normal' ? 0 : parseFloat(v));
const near = (a, b, tol = 0.6) => Math.abs(a - b) <= tol;

/** Problems ([code, detail]) of one computed shadow state against the expectation; also says where the shadow landed. */
function shadowProblems(exp, el, wrap, state) {
  const onEl = parseShadows(el.boxShadow).filter((s) => !s.inset);
  const onWrap = parseShadows(wrap.boxShadow).filter((s) => !s.inset);
  const where = onEl.length ? 'button' : onWrap.length ? 'wrapper' : null;
  const got = onEl[0] || onWrap[0] || null;
  if (!exp) return { where, problems: got ? [['shadow', `${state}: expected no shadow, got "${onEl.length ? el.boxShadow : wrap.boxShadow}" on the ${where}`]] : [] };
  if (!got) return { where, problems: [['shadow', `${state}: expected a shadow (blur ${exp.blur}px), got none on the button or its wrapper`]] };
  const p = [];
  if (exp.blur === 0 && got.blur !== 0) p.push(['shadow', `${state}: expected a hard shadow (0 blur), got blur ${got.blur}px on the ${where}`]);
  if (exp.blur > 0 && got.blur === 0) p.push(['shadow', `${state}: expected a blurred shadow (${exp.blur}px), got 0 blur on the ${where}`]);
  if (exp.blur > 0 && got.blur > 0 && !near(exp.blur, got.blur)) p.push(['shadow', `${state}: blur ${got.blur}px, expected ${exp.blur}px (${where})`]);
  if (exp.x !== null && !near(exp.x, got.x)) p.push(['shadow', `${state}: x offset ${got.x}px, expected ${exp.x}px (${where})`]);
  if (exp.y !== null && !near(exp.y, got.y)) p.push(['shadow', `${state}: y offset ${got.y}px, expected ${exp.y}px (${where})`]);
  if (exp.color && !sameColour(exp.color, got.color)) p.push(['shadow', `${state}: colour ${got.color}, expected ${exp.color} (${where})`]);
  return { where, problems: p };
}

/**
 * Every disagreement ([code, detail]) between the expectation (expectFromAttrs) and the computed values measured at rest and on hover:
 * computed = { rest: {el, wrap}, hover: {el, wrap} } with el/wrap = the getComputedStyle fields the in-page measurement collects.
 * Also says which element carries the shadow in each state (shadowWhere: {rest, hover}: 'button' | 'wrapper' | null).
 */
function compareButton(exp, computed) {
  const out = [];
  const { el, wrap } = computed.rest;
  const r = exp.rest;
  for (const [side, cap] of [['top', 'Top'], ['bottom', 'Bottom']]) {
    const e = r[side];
    const w = px(el[`border${cap}Width`]);
    if (!near(w, e.width)) out.push(['border', `rest: border-${side}-width ${el[`border${cap}Width`]}, expected ${e.width}px`]);
    if (e.width > 0) {
      if (e.style && el[`border${cap}Style`] !== e.style) out.push(['border', `rest: border-${side}-style ${el[`border${cap}Style`]}, expected ${e.style}`]);
      if (e.color && !sameColour(e.color, el[`border${cap}Color`])) out.push(['border', `rest: border-${side}-color ${el[`border${cap}Color`]}, expected ${e.color}`]);
    }
  }
  ['borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius'].forEach((k, i) => {
    if (r.radius[i] !== null && !near(px(el[k]), r.radius[i])) out.push(['radius', `rest: ${k} ${el[k]}, expected ${r.radius[i]}px`]);
  });
  if (r.fill && !sameColour(r.fill, el.backgroundColor)) out.push(['fill', `rest: background-color ${el.backgroundColor}, expected ${r.fill}`]);
  if (r.label && !sameColour(r.label, el.color)) out.push(['label', `rest: color ${el.color}, expected ${r.label}`]);
  if (r.capitalization && el.textTransform !== r.capitalization) out.push(['text', `rest: text-transform ${el.textTransform}, expected ${r.capitalization}`]);
  if (r.letterSpacing !== null && !near(px(el.letterSpacing), r.letterSpacing, 0.05)) out.push(['text', `rest: letter-spacing ${el.letterSpacing}, expected ${r.letterSpacing}px`]);
  if (r.weight && String(el.fontWeight) !== String(r.weight)) out.push(['text', `rest: font-weight ${el.fontWeight}, expected ${r.weight}`]);
  if (r.padding) ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'].forEach((k, i) => {
    if (r.padding[i] !== null && !near(px(el[k]), r.padding[i])) out.push(['padding', `rest: ${k} ${el[k]}, expected ${r.padding[i]}px`]);
  });
  const restShadow = shadowProblems(exp.shadow.rest, el, wrap, 'rest');
  out.push(...restShadow.problems);
  // Lift: nothing moves at rest.
  if (exp.lift !== null && (Math.abs(translateY(el.transform)) > 0.1 || Math.abs(translateY(wrap.transform)) > 0.1)) out.push(['lift', `rest: transform ${el.transform} / wrapper ${wrap.transform}, expected none`]);

  const h = computed.hover;
  let hoverWhere = null;
  if (h) {
    const he = h.el;
    if (exp.hover.fill && !sameColour(exp.hover.fill, he.backgroundColor)) out.push(['hover', `hover: background-color ${he.backgroundColor}, expected ${exp.hover.fill}`]);
    if (exp.hover.label && !sameColour(exp.hover.label, he.color)) out.push(['hover', `hover: color ${he.color}, expected ${exp.hover.label}`]);
    for (const [side, cap] of [['top', 'Top'], ['bottom', 'Bottom']]) {
      if (exp.rest[side].width > 0 && exp.hover.border && !sameColour(exp.hover.border, he[`border${cap}Color`])) out.push(['hover', `hover: border-${side}-color ${he[`border${cap}Color`]}, expected ${exp.hover.border}`]);
    }
    const hs = shadowProblems(exp.shadow.hover, he, h.wrap, 'hover');
    hoverWhere = hs.where;
    out.push(...hs.problems);
    if (exp.lift !== null) {
      const ye = translateY(he.transform); const yw = translateY(h.wrap.transform);
      if (!near(ye, exp.lift, 0.3) && !near(yw, exp.lift, 0.3)) out.push(['lift', `hover: translateY button ${ye}px / wrapper ${yw}px, expected ${exp.lift}px`]);
    } else if (Math.abs(translateY(he.transform)) > 0.1 || Math.abs(translateY(h.wrap.transform)) > 0.1) {
      out.push(['lift', `hover: the button moved (transform ${he.transform} / wrapper ${h.wrap.transform}) but the kit has no lift`]);
    }
  }
  return { problems: out, shadowWhere: { rest: restShadow.where, hover: hoverWhere } };
}

// ---- contrast on the painted surface ---------------------------------------------------------------------------------
const CONTRAST_LABEL = 4.5;
const CONTRAST_SHAPE = 3.0;
const round2 = (n) => Math.round(n * 100) / 100;
const hex = (c) => '#' + [c.r, c.g, c.b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join('');

/**
 * Contrast of one state of one button (computed el = getComputedStyle fields) on the painted surface under it ({r,g,b}, read from a
 * screenshot with the button hidden): the label against the fill as painted over the surface >= 4.5; where the fill is not opaque, every
 * drawn border side against the surface >= 3.0 (the border carries the shape). Same rule as KitAdaptersTest::stateProblems.
 */
function contrastProblems(el, surface, state) {
  const out = []; const info = {};
  const base = { r: surface.r, g: surface.g, b: surface.b, a: 1 };
  const fill = colour(el.backgroundColor); const label = colour(el.color);
  if (!fill || !label) return { problems: [['contrast', `${state}: unreadable colour ${el.backgroundColor} / ${el.color}`]], info };
  const painted = over(fill, base);
  const text = over(label, painted);
  info.label = round2(ratioOf(text, painted));
  if (info.label < CONTRAST_LABEL) out.push(['contrast', `${state}: label ${hex(text)} on ${hex(painted)} is ${info.label}:1, needs ${CONTRAST_LABEL}`]);
  for (const [side, cap] of [['top', 'Top'], ['bottom', 'Bottom']]) {
    if (px(el[`border${cap}Width`]) <= 0) continue;
    const bc = colour(el[`border${cap}Color`]);
    if (!bc) continue;
    const r = round2(ratioOf(over(bc, base), base));
    info[side] = r;
    info[side + 'OnFill'] = round2(ratioOf(over(bc, painted), painted));
    if (fill.a < 0.999 && r < CONTRAST_SHAPE) out.push(['contrast', `${state}: border-${side} ${hex(over(bc, base))} on surface ${hex(base)} is ${r}:1, needs ${CONTRAST_SHAPE}`]);
  }
  return { problems: out, info };
}

/** Overflow problems of one measured button: its box beyond its column (1px tolerance) or the page wider than the window. */
function overflowProblems(b, state, page) {
  const out = [];
  const r = b.rect; const p = b.parentRect;
  if (r.left < p.left - 1 || r.right > p.right + 1) out.push(['overflow', `${state}: button ${Math.round(r.left)}..${Math.round(r.right)} outside its column ${Math.round(p.left)}..${Math.round(p.right)}`]);
  if (r.right > page.innerWidth + 1 || r.left < -1) out.push(['overflow', `${state}: button ${Math.round(r.left)}..${Math.round(r.right)} outside the window (${page.innerWidth})`]);
  if (page.scrollWidth > page.innerWidth) out.push(['overflow', `${state}: scrollWidth ${page.scrollWidth} > innerWidth ${page.innerWidth}`]);
  return out;
}

// ---- PNG (screenshots) ---------------------------------------------------------------------------------------------
/** Decodes an 8-bit, non-interlaced RGB or RGBA PNG (what Chromium writes) to { width, height, data: RGBA bytes }. */
function decodePng(buf) {
  if (buf.readUInt32BE(0) !== 0x89504e47) throw new Error('not a PNG');
  let off = 8; let width = 0; let height = 0; let depth = 0; let type = 0; let interlace = 0; const idat = [];
  while (off < buf.length) {
    const len = buf.readUInt32BE(off); const kind = buf.toString('ascii', off + 4, off + 8); const body = buf.subarray(off + 8, off + 8 + len);
    if (kind === 'IHDR') { width = body.readUInt32BE(0); height = body.readUInt32BE(4); depth = body[8]; type = body[9]; interlace = body[12]; }
    else if (kind === 'IDAT') idat.push(body);
    else if (kind === 'IEND') break;
    off += 12 + len;
  }
  if (depth !== 8 || (type !== 2 && type !== 6) || interlace !== 0) throw new Error(`unsupported PNG (depth ${depth}, type ${type}, interlace ${interlace})`);
  const bpp = type === 6 ? 4 : 3; const stride = width * bpp;
  const raw = zlib.inflateSync(Buffer.concat(idat));
  const px8 = Buffer.alloc(stride * height); const data = Buffer.alloc(width * height * 4);
  for (let y = 0; y < height; y++) {
    const f = raw[y * (stride + 1)]; const src = y * (stride + 1) + 1; const row = y * stride; const prev = row - stride;
    for (let i = 0; i < stride; i++) {
      const a = i >= bpp ? px8[row + i - bpp] : 0; const b = y > 0 ? px8[prev + i] : 0; const c = i >= bpp && y > 0 ? px8[prev + i - bpp] : 0;
      let v = raw[src + i];
      if (f === 1) v += a; else if (f === 2) v += b; else if (f === 3) v += (a + b) >> 1;
      else if (f === 4) { const p = a + b - c; const pa = Math.abs(p - a); const pb = Math.abs(p - b); const pc = Math.abs(p - c); v += pa <= pb && pa <= pc ? a : pb <= pc ? b : c; }
      px8[row + i] = v & 255;
    }
    for (let x = 0; x < width; x++) { const s = row + x * bpp; const d = (y * width + x) * 4; data[d] = px8[s]; data[d + 1] = px8[s + 1]; data[d + 2] = px8[s + 2]; data[d + 3] = bpp === 4 ? px8[s + 3] : 255; }
  }
  return { width, height, data };
}
/** Mean colour and the lightest / darkest pixel (by luminance) of a decoded image. */
function pixelStats(img) {
  let r = 0; let g = 0; let b = 0; let n = 0; let lo = null; let hi = null;
  const lum = (c) => 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;
  for (let i = 0; i < img.data.length; i += 4) {
    const c = { r: img.data[i], g: img.data[i + 1], b: img.data[i + 2] };
    r += c.r; g += c.g; b += c.b; n++;
    if (!lo || lum(c) < lum(lo)) lo = c; if (!hi || lum(c) > lum(hi)) hi = c;
  }
  return n ? { mean: { r: r / n, g: g / n, b: b / n }, darkest: lo, lightest: hi } : null;
}

// ---- in the page -----------------------------------------------------------------------------------------------------
/**
 * Runs IN THE PAGE (self-contained). Every rendered button of the page content (`.et-l--post a.et_pb_button`, document order) with the
 * computed fields compareButton/contrastProblems read, for the button element and for its module wrapper, its rect and its column's rect.
 */
function measureButtons() {
  const root = document.querySelector('.et-l--post');
  const F = ['borderTopWidth', 'borderTopStyle', 'borderTopColor', 'borderBottomWidth', 'borderBottomStyle', 'borderBottomColor', 'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius', 'backgroundColor', 'color', 'textTransform', 'letterSpacing', 'fontWeight', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'boxShadow', 'transform', 'transitionDuration', 'transitionDelay'];
  const pick = (node) => { const cs = getComputedStyle(node); const o = {}; for (const k of F) o[k] = cs[k]; return o; };
  const box = (n) => { const r = n.getBoundingClientRect(); return { left: r.left + window.scrollX, right: r.right + window.scrollX, top: r.top + window.scrollY, bottom: r.bottom + window.scrollY, width: r.width, height: r.height }; };
  const buttons = root ? [...root.querySelectorAll('a.et_pb_button')] : [];
  return {
    hasRoot: !!root,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    buttons: buttons.map((a) => {
      const wrap = a.closest('.et_pb_button_module_wrapper') || a.parentElement;
      const col = wrap.closest('.et_pb_column') || wrap.parentElement;
      return { cls: String(a.className), text: (a.textContent || '').trim(), el: pick(a), wrap: pick(wrap), rect: box(a), parentRect: box(col) };
    }),
  };
}

module.exports = {
  SURFACES, ROLES, SLOTS, sheetMarkup, TITLE_PREFIX, runTitle, parseRunTitle, selectCells, buttonAttrs, expectFromAttrs,
  colour, sameColour, parseShadows, translateY, shadowProblems, compareButton, contrastProblems, overflowProblems,
  CONTRAST_LABEL, CONTRAST_SHAPE, decodePng, pixelStats, measureButtons, hex, round2,
};
