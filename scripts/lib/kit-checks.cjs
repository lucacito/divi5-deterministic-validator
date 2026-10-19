'use strict';
/*
 * Pure pieces of scripts/verify-kits.cjs, kept apart so they can be tested without the dev site (scripts/test-verify-kits.cjs):
 *  - the kit sheet template (tokenised markup with kit markers; the script resolves it in PHP through the REAL pipeline),
 *  - the run-title format and the cell selection,
 *  - reading the EXPECTED button values from the resolved markup (the adapter output the page was built from),
 *  - comparing them with the computed style the browser measured (rest and hover), contrast on the painted surface,
 *  - a small PNG decoder (the surface under each button is read from a real screenshot, so a photo band is measured, not guessed),
 *  - the in-page measurement (runs in the browser; it must stay self-contained: Playwright serialises it),
 *  - the same for cards: the card/panel part of the sheet (CARD_SLOTS), expectations read from the resolved block tree (a card is found by
 *    the heading it holds, because the resolver strips the marker), the comparison with the computed style at rest and on a real hover,
 *    the clip, the contrast on the measured card fill, overflow and the three-column row.
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
/** The band a card marker names for each sheet section (`on`): a surface section is a card band of its own (the card turns `bg`). */
const CARD_ON = { bg: 'light', surface: 'surface', accent: 'accent', dark: 'dark' };

const V = '"builderVersion":"5.8.0"';
const jsonAttrs = (o) => JSON.stringify(o);
const blockOf = (name, attrs, children) => (children === null ? `<!-- wp:divi/${name} ${jsonAttrs(attrs)} /-->` : `<!-- wp:divi/${name} ${jsonAttrs(attrs)} -->\n${children}\n<!-- /wp:divi/${name} -->`);
const capital = (w) => w.charAt(0).toUpperCase() + w.slice(1);

/**
 * The cards of the sheet, in document order. Every one holds a heading (its UNIQUE text finds it again, in the markup and in the page)
 * and a paragraph; `image` puts a placeholder image first; `padding` is the panel's literal padding in px (a panel keeps its recipe
 * padding: no kit key), `flush` = a zero-padding panel whose image touches the card's corners (the clip is only exercised there);
 * `carrier` is the block that carries the marker: `group`, or `column` for the three cards of a 3-column row.
 */
const CARD_SLOTS = [];
for (const s of SURFACES) {
  for (const role of ['card', 'panel']) {
    CARD_SLOTS.push({ key: `${role}-${s.key}`, heading: `${capital(role)} on ${s.key}`, band: s.key, on: CARD_ON[s.key], role, carrier: 'group', image: role === 'card' && s.key === 'bg', flush: false, padding: role === 'panel' ? 40 : null, row: false });
  }
  if (s.key === 'bg') CARD_SLOTS.push({ key: 'panel-clip', heading: 'Panel clip on bg', band: 'bg', on: 'light', role: 'panel', carrier: 'group', image: true, flush: true, padding: 0, row: false });
}
for (const i of [1, 2, 3]) CARD_SLOTS.push({ key: `column-${i}`, heading: `Column card ${i}`, band: 'bg', on: 'light', role: 'card', carrier: 'column', image: false, flush: false, padding: null, row: true });

/** One card slot as markup: the marked carrier with an optional image, a heading and a paragraph (colours from the language, as recipes write them). */
function cardMarkup(slot) {
  const marker = slot.on === 'light' ? { kit: slot.role } : { kit: slot.role, on: slot.on };
  const px4 = (n) => ({ top: `${n}px`, right: `${n}px`, bottom: `${n}px`, left: `${n}px` });
  const inset = slot.flush ? { module: { decoration: { spacing: { desktop: { value: { padding: { top: '16px', right: '24px', bottom: '16px', left: '24px' } } } } } } } : {};
  const image = blockOf('image', { module: { advanced: { sizing: { desktop: { value: { width: '100%', aspectRatio: { width: '16', height: '9' } } } } } }, image: { innerContent: { desktop: { value: { src: '{{aied:image:card-blue-1}}', alt: 'Placeholder photograph: replace with your own' } } }, decoration: { fit: { desktop: { value: { objectFit: 'cover' } } } } }, builderVersion: '5.8.0' }, null);
  const heading = blockOf('heading', Object.assign({ title: { innerContent: { desktop: { value: slot.heading } }, decoration: { font: { font: { desktop: { value: { headingLevel: 'h3', color: '{{aied:color:text}}', family: '{{aied:font:display}}', size: '24px' } } } } } }, builderVersion: '5.8.0' }, inset), null);
  const para = blockOf('text', Object.assign({ content: { innerContent: { desktop: { value: '<p>Placeholder copy for a card: one short sentence that the surrounding colours must keep readable.</p>' } }, decoration: { bodyFont: { body: { font: { desktop: { value: { color: '{{aied:color:muted}}', family: '{{aied:font:body}}', size: '16px', lineHeight: '1.5em' } } } } } } }, builderVersion: '5.8.0' }, inset), null);
  const layout = slot.carrier === 'column' ? { flexDirection: 'column', rowGap: '16px' } : { flexDirection: 'column', rowGap: '12px' };
  const decoration = { layout: { desktop: { value: layout } } };
  if (slot.padding !== null) decoration.spacing = { desktop: { value: { padding: px4(slot.padding) } } };
  const body = [slot.image ? image : null, heading, para].filter(Boolean).join('\n');
  if (slot.carrier === 'column') {
    decoration.sizing = { desktop: { value: { flexType: '8_24' } }, phone: { value: { flexType: '24_24' } } };
    return blockOf('column', { module: { advanced: { type: { desktop: { value: '1_3' } } }, decoration }, builderVersion: '5.8.0', aied: marker }, body);
  }
  return blockOf('group', { module: { decoration }, builderVersion: '5.8.0', aied: marker }, body);
}

/** The tokenised sheet markup: 8 buttons (SURFACES x ROLES) and 12 cards (CARD_SLOTS), in document order. */
function sheetMarkup() {
  const v = V;
  const sections = SURFACES.map((s) => {
    const marker = (role) => (s.on === 'light' ? `{"kit":"${role}"}` : `{"kit":"${role}","on":"${s.on}"}`);
    const buttons = ROLES.map((r) => `<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"${r.text}"}}},"decoration":{"font":{"font":{"desktop":{"value":{"family":"{{aied:font:body}}"}}}}}},${v},"aied":${marker(r.role)}} /-->`).join('\n');
    const cards = CARD_SLOTS.filter((c) => c.band === s.key && !c.row).map(cardMarkup).join('\n');
    return `<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":${s.bg}}},"spacing":{"desktop":{"value":{"padding":{"top":"64px","bottom":"64px"}}}}}},${v}} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},${v}} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},${v}} -->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>${s.label}</p>"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"{{aied:color:${s.ink}}}","family":"{{aied:font:body}}"}}}}}}},${v}} /-->
${buttons}
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},${v}} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}},"decoration":{"layout":{"desktop":{"value":{"flexDirection":"column","rowGap":"24px"}}}}},${v}} -->
${cards}
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->`;
  });
  // The multi-column check: a row of three columns that ARE the cards (the real proof-press-quotes row shape), on the bg band.
  const bg = SURFACES.find((s) => s.key === 'bg');
  sections.push(`<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":${bg.bg}}},"spacing":{"desktop":{"value":{"padding":{"top":"64px","bottom":"64px"}}}}}},${v}} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"1_3,1_3,1_3"}},"flexColumnStructure":{"desktop":{"value":"equal-columns_3"}}},"decoration":{"layout":{"desktop":{"value":{"flexWrap":"nowrap","columnGap":"24px"}},"phone":{"value":{"flexWrap":"wrap"}}}}},${v}} -->
${CARD_SLOTS.filter((c) => c.row).map(cardMarkup).join('\n')}
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->`);
  return sections.join('\n');
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
    const badSheet = sheetLanguages.filter((l) => !langIds.includes(l));
    if (badSheet.length) throw new Error('Sheet language(s) not in the design languages: ' + badSheet.join(', '));
    for (const l of sheetLanguages) for (const k of kitIds) cells.push([k, l]);
    for (const l of languages) {
      if (!l.kit) continue;
      if (!kitIds.includes(l.kit)) throw new Error(`Language ${l.id} names an unknown default kit: ${l.kit}`);
      cells.push([l.kit, l.id]);
    }
  }
  const seen = new Set();
  const out = cells.filter(([k, l]) => { const key = k + '/' + l; if (seen.has(key)) return false; seen.add(key); return true; });
  if (!out.length) { const e = new Error('No kit/language cell selected (no kits or no languages in the data): nothing would be checked'); e.empty = true; throw e; }
  return out;
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
  const side = (k) => { const s = Object.assign({}, all, styles[k] || {}); return { width: s.width !== undefined && s.width !== '' ? parseFloat(s.width) : null, style: s.style || null, color: s.color || null }; };
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

/**
 * The expectation fields that are missing (null or not a number) although KitAdapters::button() ALWAYS writes them, rest and hover
 * (KitAdapters.php: background value/hover colour, border radius (four corners), styles.all width/style/colour (plus styles.bottom for an
 * underline), hover styles.all colour, font colour/capitalization/letterSpacing/weight and hover colour, module spacing padding). A
 * non-empty list means an attribute path drifted: the cell is a `gate` failure instead of a check that silently stops checking.
 * Shadow and lift stay optional (written only when the kit has them).
 */
function missingExpectations(exp) {
  const out = [];
  const num = (v) => typeof v === 'number' && !Number.isNaN(v);
  const r = exp.rest;
  for (const k of ['fill', 'label', 'capitalization', 'weight']) if (!r[k]) out.push('rest.' + k);
  if (!num(r.letterSpacing)) out.push('rest.letterSpacing');
  if (!Array.isArray(r.padding) || r.padding.length !== 4 || !r.padding.every(num)) out.push('rest.padding');
  if (!Array.isArray(r.radius) || r.radius.length !== 4 || !r.radius.every(num)) out.push('rest.radius');
  for (const side of ['top', 'bottom']) {
    if (!num(r[side].width)) out.push(`rest.${side}.width`);
    if (!r[side].style) out.push(`rest.${side}.style`);
    if (!r[side].color) out.push(`rest.${side}.color`);
  }
  for (const k of ['fill', 'label', 'border']) if (!exp.hover[k]) out.push('hover.' + k);
  return out;
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
/** Border widths: Chrome reports whole-pixel widths at DPR 1, so a quarter pixel is enough room and a 1px vs 1.5px drift fails. */
const BORDER_TOLERANCE = 0.25;

/** Problems ([code, detail]) of one computed shadow state against the expectation; also says where the shadow landed. */
function shadowProblems(exp, el, wrap, state, names = ['button', 'wrapper']) {
  const onEl = parseShadows(el.boxShadow).filter((s) => !s.inset);
  const onWrap = parseShadows(wrap.boxShadow).filter((s) => !s.inset);
  const where = onEl.length ? names[0] : onWrap.length ? names[1] : null;
  const got = onEl[0] || onWrap[0] || null;
  if (!exp) return { where, problems: got ? [['shadow', `${state}: expected no shadow, got "${onEl.length ? el.boxShadow : wrap.boxShadow}" on the ${where}`]] : [] };
  if (!got) return { where, problems: [['shadow', `${state}: expected a shadow (blur ${exp.blur}px), got none on the ${names[0]} or its ${names[1]}`]] };
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
    if (!near(w, e.width, BORDER_TOLERANCE)) out.push(['border', `rest: border-${side}-width ${el[`border${cap}Width`]}, expected ${e.width}px`]);
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

// ---- cards -----------------------------------------------------------------------------------------------------------
const SIDES = [['top', 'Top'], ['right', 'Right'], ['bottom', 'Bottom'], ['left', 'Left']];
const CORNERS = ['topLeft', 'topRight', 'bottomRight', 'bottomLeft'];
const CORNER_PROPS = ['borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius'];

/** The block tree of a markup: [{ name, attrs, children }] (closing comments pair up; a self-closing block has no children). */
function blockTree(markup) {
  const re = /<!--\s+(\/)?wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]|-(?!->)|\}(?!\s+\/?-->))*\}))?\s+(\/)?-->/g;
  const root = { name: '', attrs: {}, children: [] };
  const stack = [root];
  let m;
  while ((m = re.exec(markup))) {
    const top = stack[stack.length - 1];
    if (m[1]) { if (stack.length > 1) stack.pop(); continue; }
    const node = { name: m[2], attrs: m[3] ? JSON.parse(m[3]) : {}, children: [] };
    top.children.push(node);
    if (!m[4]) stack.push(node);
  }
  return root.children;
}
const headingText = (node) => dig(node.attrs, 'title', 'innerContent', 'desktop', 'value');
/** The first heading text below a node, depth first, or null. */
function firstHeading(node) {
  for (const c of node.children) {
    if (c.name === 'divi/heading' && typeof headingText(c) === 'string') return headingText(c);
    const h = firstHeading(c);
    if (h !== null) return h;
  }
  return null;
}
/**
 * The attribute object of each sheet card in the RESOLVED markup (the marker is gone, so a card is found by the carrier block that holds
 * its unique heading text): [{ slot, attrs }] in CARD_SLOTS order; attrs is null when the card is not in the markup.
 */
function cardAttrs(markup, slots = CARD_SLOTS) {
  const found = new Map();
  const walk = (nodes) => {
    for (const n of nodes) {
      if (n.name === 'divi/group' || n.name === 'divi/column') {
        const h = firstHeading(n);
        if (h !== null && !found.has(n.name + '|' + h)) found.set(n.name + '|' + h, n.attrs);
      }
      walk(n.children);
    }
  };
  walk(blockTree(markup));
  return slots.map((slot) => ({ slot, attrs: found.get(`divi/${slot.carrier}|${slot.heading}`) || null }));
}

/**
 * What the kit says about one card, read ONLY from its resolved attributes (`module.decoration`): { rest: { fill, top|right|bottom|left:
 * {width, style, color}, radius:[4], padding:[4]|null }, shadow: {rest, hover}, hoverBorder: colour|null, lift: -4|null, hoverKeys: [..] }.
 * `hoverKeys` lists the hover keys the markup carries (a panel must have none).
 */
function expectCardFromAttrs(attrs) {
  const md = dig(attrs, 'module', 'decoration') || {};
  const border = dig(md, 'border', 'desktop', 'value') || {};
  const styles = border.styles || {};
  const all = styles.all || {};
  const side = (k) => { const e = Object.assign({}, all, styles[k] || {}); return { width: e.width !== undefined && e.width !== '' ? parseFloat(e.width) : null, style: e.style || null, color: e.color || null }; };
  const radius = border.radius || {};
  const pad = dig(md, 'spacing', 'desktop', 'value', 'padding') || null;
  const shadowRest = shadowExpect(dig(md, 'boxShadow', 'desktop', 'value'));
  const shadowHoverOnly = shadowExpect(dig(md, 'boxShadow', 'desktop', 'hover'));
  const ty = dig(md, 'transform', 'desktop', 'hover', 'translate', 'y');
  const hoverBorder = dig(md, 'border', 'desktop', 'hover', 'styles', 'all', 'color') || null;
  const hoverKeys = [];
  if (hoverBorder !== null || dig(md, 'border', 'desktop', 'hover') !== undefined) hoverKeys.push('border.hover');
  if (shadowHoverOnly) hoverKeys.push('boxShadow.hover');
  if (ty !== undefined || dig(md, 'transform') !== undefined) hoverKeys.push('transform');
  if (dig(md, 'background', 'desktop', 'hover') !== undefined) hoverKeys.push('background.hover');
  return {
    rest: {
      fill: dig(md, 'background', 'desktop', 'value', 'color') || null,
      top: side('top'), right: side('right'), bottom: side('bottom'), left: side('left'),
      radius: CORNERS.map((k) => (radius[k] !== undefined ? parseFloat(radius[k]) : null)),
      padding: pad ? ['top', 'right', 'bottom', 'left'].map((k) => (pad[k] !== undefined && pad[k] !== '' ? parseFloat(pad[k]) : null)) : null,
    },
    shadow: { rest: shadowRest, hover: shadowHoverOnly || shadowRest },
    hoverBorder,
    lift: ty !== undefined ? parseFloat(ty) : null,
    hoverKeys,
  };
}

/**
 * The expectation fields that are missing although KitAdapters::card() ALWAYS writes them (fill, the four radii, the frame width/style/colour
 * on every side, and for a CARD the padding; a panel's padding is the sheet's literal, so the sheet must give it too), plus a panel that
 * carries any hover key (a panel is static: that is an adapter fault, not an expectation). A non-empty list is a `gate` failure and
 * compareCard refuses to compare: a null expectation must never silently skip a check.
 */
function missingCardExpectations(exp, role) {
  const out = [];
  const num = (v) => typeof v === 'number' && !Number.isNaN(v);
  const r = exp.rest;
  if (!r.fill) out.push('rest.fill');
  if (!Array.isArray(r.radius) || r.radius.length !== 4 || !r.radius.every(num)) out.push('rest.radius');
  if (!Array.isArray(r.padding) || r.padding.length !== 4 || !r.padding.every(num)) out.push('rest.padding');
  for (const [k] of SIDES) {
    if (!num(r[k].width)) out.push(`rest.${k}.width`);
    if (!r[k].style) out.push(`rest.${k}.style`);
    if (!r[k].color) out.push(`rest.${k}.color`);
  }
  if (role === 'panel') for (const k of exp.hoverKeys) out.push('panel carries ' + k);
  return out;
}

/**
 * Every disagreement ([code, detail]) between a card's expectation and what the browser computed: codes card-border (the four sides' width,
 * style, colour), card-radius, card-fill, card-padding, card-shadow (it must sit on the card element itself) and card-hover (hover frame
 * colour only when the markup has one, the hover shadow, translateY of the lift, and that nothing else moved or changed; a PANEL must show
 * no change at all). `computed` = { rest: { el }, hover: { el } } with the fields measureCards collects; the hover state is REQUIRED.
 */
function compareCard(exp, role, computed) {
  const miss = missingCardExpectations(exp, role);
  if (miss.length) return { problems: [['card-expectation', 'expectation missing (an adapter attribute path drifted): ' + miss.join(', ')]], shadowWhere: null };
  const out = [];
  const el = computed.rest.el;
  const r = exp.rest;
  for (const [side, cap] of SIDES) {
    const e = r[side];
    if (!near(px(el[`border${cap}Width`]), e.width, BORDER_TOLERANCE)) out.push(['card-border', `rest: border-${side}-width ${el[`border${cap}Width`]}, expected ${e.width}px`]);
    if (e.width > 0) {
      if (el[`border${cap}Style`] !== e.style) out.push(['card-border', `rest: border-${side}-style ${el[`border${cap}Style`]}, expected ${e.style}`]);
      if (!sameColour(e.color, el[`border${cap}Color`])) out.push(['card-border', `rest: border-${side}-color ${el[`border${cap}Color`]}, expected ${e.color}`]);
    }
  }
  CORNER_PROPS.forEach((k, i) => { if (!near(px(el[k]), r.radius[i])) out.push(['card-radius', `rest: ${k} ${el[k]}, expected ${r.radius[i]}px`]); });
  if (!sameColour(r.fill, el.backgroundColor)) out.push(['card-fill', `rest: background-color ${el.backgroundColor}, expected ${r.fill}`]);
  ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'].forEach((k, i) => { if (!near(px(el[k]), r.padding[i])) out.push(['card-padding', `rest: ${k} ${el[k]}, expected ${r.padding[i]}px`]); });
  const none = { boxShadow: 'none' };
  const restShadow = shadowProblems(exp.shadow.rest, el, none, 'rest', ['card', 'card']);
  for (const [, d] of restShadow.problems) out.push(['card-shadow', d]);
  if (Math.abs(translateY(el.transform)) > 0.1) out.push(['card-hover', `rest: transform ${el.transform}, expected none (a card does not move at rest)`]);

  const h = computed.hover;
  if (!h || !h.el) { out.push(['card-hover', 'the hover state was not measured']); return { problems: out, shadowWhere: { rest: restShadow.where, hover: null } }; }
  const he = h.el;
  if (!sameColour(el.backgroundColor, he.backgroundColor)) out.push(['card-hover', `hover: background-color ${he.backgroundColor}, rest ${el.backgroundColor}: a card never changes its fill`]);
  for (const [side, cap] of SIDES) {
    const restColour = el[`border${cap}Color`]; const hovColour = he[`border${cap}Color`];
    if (!near(px(el[`border${cap}Width`]), px(he[`border${cap}Width`]), 0.01)) out.push(['card-hover', `hover: border-${side}-width ${he[`border${cap}Width`]}, rest ${el[`border${cap}Width`]}`]);
    if (r[side].width > 0) {
      const want = role === 'card' && exp.hoverBorder ? exp.hoverBorder : restColour;
      if (!sameColour(want, hovColour)) out.push(['card-hover', `hover: border-${side}-color ${hovColour}, expected ${role === 'card' && exp.hoverBorder ? exp.hoverBorder : 'the rest colour ' + restColour}`]);
    }
  }
  const hoverShadow = shadowProblems(role === 'card' ? exp.shadow.hover : exp.shadow.rest, he, none, 'hover', ['card', 'card']);
  for (const [, d] of hoverShadow.problems) out.push(['card-hover', d]);
  const ty = translateY(he.transform);
  if (role === 'card' && exp.lift !== null) {
    if (!near(ty, exp.lift, 0.3)) out.push(['card-hover', `hover: translateY ${ty}px, expected ${exp.lift}px`]);
  } else if (!(Math.abs(ty) <= 0.1)) {
    out.push(['card-hover', `hover: the ${role} moved (transform ${he.transform})${role === 'panel' ? ': a panel is static' : ' but the kit has no lift'}`]);
  }
  return { problems: out, shadowWhere: { rest: restShadow.where, hover: hoverShadow.where } };
}

/**
 * The clip of a card with an image child. A radius makes Divi print overflow:hidden on the card (kit-card-vocabulary.md), so the image is
 * cut to the rounded corner: this is a computed-style + geometry check, not a pixel one. Fails (card-clip) when a radius is set and the
 * card does not clip (overflow not hidden/clip), when the image spills outside the card's box (1px), and, for a `flush` slot (a
 * zero-padding card whose image touches its corners), when the image does NOT touch them (the clip would not be exercised at all).
 * c = { el, rect, image: { rect } | null }; returns { problems, info: { flush, clipped } } (clipped = the image's corners lie outside the
 * rounded shape, i.e. the radius really cuts them).
 */
function cardClipProblems(slot, exp, c) {
  const out = [];
  const radius = Math.max(...exp.rest.radius);
  const clips = (v) => v === 'hidden' || v === 'clip';
  const info = { flush: false, clipped: false };
  if (radius > 0 && !(clips(c.el.overflowX) && clips(c.el.overflowY))) out.push(['card-clip', `radius ${radius}px but overflow is ${c.el.overflowX}/${c.el.overflowY}: a child image would not be clipped to the corner`]);
  if (!c.image) {
    if (slot.image) out.push(['card-clip', 'the card should hold an image child and none was found in the page']);
    return { problems: out, info };
  }
  const a = c.rect; const i = c.image.rect;
  if (i.left < a.left - 1 || i.right > a.right + 1 || i.top < a.top - 1 || i.bottom > a.bottom + 1) out.push(['card-clip', `the image (${Math.round(i.left)},${Math.round(i.top)}..${Math.round(i.right)},${Math.round(i.bottom)}) spills outside the card (${Math.round(a.left)},${Math.round(a.top)}..${Math.round(a.right)},${Math.round(a.bottom)})`]);
  const bw = Math.max(0, px(c.el.borderTopWidth) || 0, px(c.el.borderLeftWidth) || 0);
  info.flush = Math.abs(i.left - a.left) <= bw + 1.5 && Math.abs(i.top - a.top) <= bw + 1.5 && Math.abs(i.right - a.right) <= bw + 1.5;
  if (slot.flush && !info.flush) out.push(['card-clip', 'the image of the zero-padding panel does not touch its corners: the radius clip is not exercised (image vs card box differs by more than the frame)']);
  if (info.flush && radius > 0) {
    // The image's top-left corner, measured from the card's: outside the quarter circle of radius r means the radius cuts it.
    const dx = i.left - a.left; const dy = i.top - a.top;
    const cx = radius - dx; const cy = radius - dy;
    info.clipped = cx > 0 && cy > 0 && Math.hypot(cx, cy) > radius;
  }
  return { problems: out, info };
}

/**
 * Contrast on the MEASURED card fill ({r,g,b}, the mean of a screenshot of the card's inner box with its children hidden): the heading and
 * the paragraph colours against it >= 4.5 (code card-contrast), and every drawn frame side >= 3.0 against it, except the decorative
 * `border` hairline (the colour of the language's `border` role, `hairline`), which is reported but not judged. An unreadable colour or a
 * missing text is a failure, never a skip. c = { el, heading: { color }|null, paragraph: { color }|null }.
 */
function cardContrastProblems(c, fill, hairline = null) {
  const out = []; const info = {};
  const base = { r: fill.r, g: fill.g, b: fill.b, a: 1 };
  for (const [name, t] of [['heading', c.heading], ['paragraph', c.paragraph]]) {
    const col = t ? colour(t.color) : null;
    if (!col) { out.push(['card-contrast', `${name}: no readable text colour found (${t ? t.color : 'no ' + name + ' in the card'})`]); continue; }
    const r = round2(ratioOf(over(col, base), base));
    info[name] = r;
    if (r < CONTRAST_LABEL) out.push(['card-contrast', `${name} colour ${hex(over(col, base))} on the card fill ${hex(base)} is ${r}:1, needs ${CONTRAST_LABEL}`]);
  }
  for (const [side, cap] of SIDES) {
    if (!(px(c.el[`border${cap}Width`]) > 0)) continue;
    const raw = c.el[`border${cap}Color`];
    const bc = colour(raw);
    if (!bc) { out.push(['card-contrast', `border-${side}: unreadable colour ${raw}`]); continue; }
    const r = round2(ratioOf(over(bc, base), base));
    info[side] = r;
    if (hairline && sameColour(hairline, raw)) { info.hairline = true; continue; }
    if (r < CONTRAST_SHAPE) out.push(['card-contrast', `frame ${side} ${hex(over(bc, base))} on the card fill ${hex(base)} is ${r}:1, needs ${CONTRAST_SHAPE}`]);
  }
  return { problems: out, info };
}

/** The painted fill (screenshot mean) against the computed one: card-fill when an opaque computed fill differs by more than 4 per channel. */
function paintedFillProblems(el, fill) {
  const c = colour(el.backgroundColor);
  if (!c || c.a < 0.999) return [];
  const d = Math.max(Math.abs(c.r - fill.r), Math.abs(c.g - fill.g), Math.abs(c.b - fill.b));
  return d > 4 ? [['card-fill', `painted fill ${hex(fill)} differs from the computed ${el.backgroundColor} by ${Math.round(d)} (something is drawn over the card)`]] : [];
}

/** Overflow of one measured card: beyond its container (1px) or the window, or the page wider than the window. */
function cardOverflowProblems(c, state, page) {
  const out = [];
  const r = c.rect; const p = c.parentRect;
  if (r.left < p.left - 1 || r.right > p.right + 1) out.push(['overflow', `${state}: card ${Math.round(r.left)}..${Math.round(r.right)} outside its container ${Math.round(p.left)}..${Math.round(p.right)}`]);
  if (r.right > page.innerWidth + 1 || r.left < -1) out.push(['overflow', `${state}: card ${Math.round(r.left)}..${Math.round(r.right)} outside the window (${page.innerWidth})`]);
  if (page.scrollWidth > page.innerWidth) out.push(['overflow', `${state}: scrollWidth ${page.scrollWidth} > innerWidth ${page.innerWidth}`]);
  return out;
}

/**
 * The three column cards: side by side from 1000 px up (equal widths within 3px, tops within 4px), stacked at 480 px and below (each below
 * the previous, same left within 2px); in between nothing is demanded (the answer is reported). rects = the three cards' rects, in order.
 */
function columnRowProblems(rects, width) {
  const out = [];
  if (rects.length !== 3) return { problems: [['cards', `the column row has ${rects.length} cards, expected 3`]], layout: null };
  const side = rects.every((r, i) => i === 0 || (r.left > rects[i - 1].right - 1 && Math.abs(r.top - rects[0].top) <= 4));
  const stacked = rects.every((r, i) => i === 0 || (r.top >= rects[i - 1].bottom - 1 && Math.abs(r.left - rects[0].left) <= 2));
  const layout = side ? 'side-by-side' : stacked ? 'stacked' : 'mixed';
  if (width >= 1000) {
    if (!side) out.push(['overflow', `the three column cards are not side by side at ${width}px (${layout})`]);
    else if (Math.max(...rects.map((r) => r.right - r.left)) - Math.min(...rects.map((r) => r.right - r.left)) > 3) out.push(['overflow', `the three column cards differ in width by more than 3px at ${width}px`]);
  } else if (width <= 480 && !stacked) out.push(['overflow', `the three column cards do not stack at ${width}px (${layout})`]);
  return { problems: out, layout };
}

/** Problems of the card count: every sheet card must be in the page (code `cards`), and an empty selection is an error. */
function cardCountProblems(measured, slots = CARD_SLOTS) {
  if (!slots.length) throw new Error('no card slots: nothing would be checked');
  const rendered = measured.filter((c) => c && c.found);
  if (rendered.length === slots.length && measured.length === slots.length) return [];
  const missing = slots.filter((s, i) => !(measured[i] && measured[i].found)).map((s) => s.key);
  return [['cards', `${rendered.length} cards rendered, expected ${slots.length}${missing.length ? ' (missing: ' + missing.join(', ') + ')' : ''}`]];
}

/** The central box of a card to sample for its fill: inside the frame, the corners and (a little) the padding. */
function cardSampleClip(rect, el) {
  const w = rect.width; const h = rect.height;
  const bw = Math.max(...SIDES.map(([, cap]) => px(el[`border${cap}Width`]) || 0));
  const rad = Math.max(...CORNER_PROPS.map((k) => px(el[k]) || 0));
  const inset = Math.max(1, Math.min(bw + Math.min(rad, Math.min(w, h) / 4) + 2, Math.min(w, h) / 2 - 2));
  return { x: Math.max(0, Math.round(rect.left + inset)), y: Math.max(0, Math.round(rect.top + inset)), width: Math.max(1, Math.round(w - 2 * inset)), height: Math.max(1, Math.round(h - 2 * inset)) };
}

/** What a finished run must have checked: at least one cell/width and at least one card (an empty card selection fails the run). */
function emptyRunProblems({ checked, cardsChecked }) {
  const out = [];
  if (!checked) out.push('no cell/width was checked: nothing was measured');
  if (!cardsChecked) out.push('no card was checked: the card selection was empty');
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

/**
 * Runs IN THE PAGE (self-contained). For each slot ({ key, heading, carrier }) the rendered card: the carrier element (`.et_pb_group`, or
 * `.et_pb_column` for a column card) that holds the heading with that exact text, tagged `data-aied-card="<key>"` so the script can hover
 * it, with the computed fields compareCard/cardContrastProblems read, its rect and its container's, the heading and paragraph colours and
 * the rect of its first image. A card that is not in the page is { key, found: false }.
 */
function measureCards(slots) {
  const root = document.querySelector('.et-l--post');
  const F = ['borderTopWidth', 'borderTopStyle', 'borderTopColor', 'borderRightWidth', 'borderRightStyle', 'borderRightColor', 'borderBottomWidth', 'borderBottomStyle', 'borderBottomColor', 'borderLeftWidth', 'borderLeftStyle', 'borderLeftColor', 'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius', 'backgroundColor', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'boxShadow', 'transform', 'overflowX', 'overflowY', 'transitionDuration', 'transitionDelay'];
  const pick = (node) => { const cs = getComputedStyle(node); const o = {}; for (const k of F) o[k] = cs[k]; return o; };
  const box = (n) => { const r = n.getBoundingClientRect(); return { left: r.left + window.scrollX, right: r.right + window.scrollX, top: r.top + window.scrollY, bottom: r.bottom + window.scrollY, width: r.width, height: r.height }; };
  const headings = root ? [...root.querySelectorAll('.et_pb_heading')] : [];
  return {
    hasRoot: !!root,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    cards: slots.map((s) => {
      const h = headings.find((n) => (n.textContent || '').trim() === s.heading);
      const card = h ? h.closest(s.carrier === 'column' ? '.et_pb_column' : '.et_pb_group') : null;
      if (!card) return { key: s.key, found: false };
      card.setAttribute('data-aied-card', s.key);
      const tag = h.querySelector('h1,h2,h3,h4,h5,h6');
      const p = card.querySelector('.et_pb_text p');
      const img = card.querySelector('.et_pb_image img');
      const container = card.closest(s.carrier === 'column' ? '.et_pb_row' : '.et_pb_column') || card.parentElement;
      return {
        key: s.key, found: true, el: pick(card), parentShadow: getComputedStyle(card.parentElement).boxShadow, rect: box(card), parentRect: box(container),
        heading: tag ? { color: getComputedStyle(tag).color } : null,
        paragraph: p ? { color: getComputedStyle(p).color } : null,
        image: img ? { rect: box(img) } : null,
      };
    }),
  };
}

module.exports = {
  SURFACES, ROLES, SLOTS, sheetMarkup, TITLE_PREFIX, runTitle, parseRunTitle, selectCells, buttonAttrs, expectFromAttrs, missingExpectations, BORDER_TOLERANCE,
  colour, sameColour, parseShadows, translateY, shadowProblems, compareButton, contrastProblems, overflowProblems,
  CONTRAST_LABEL, CONTRAST_SHAPE, decodePng, pixelStats, measureButtons, hex, round2,
  CARD_ON, CARD_SLOTS, cardMarkup, blockTree, cardAttrs, expectCardFromAttrs, missingCardExpectations, compareCard, cardClipProblems, cardContrastProblems,
  paintedFillProblems, cardOverflowProblems, columnRowProblems, cardCountProblems, cardSampleClip, emptyRunProblems, measureCards,
};
