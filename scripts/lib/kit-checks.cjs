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
 *    the clip, the contrast on the measured card fill, overflow and the three-column row,
 *  - and for the ITEMS (accordion and toggle, roles item / item-rule): ITEM_SLOTS, expectations read from the resolved block tree per state
 *    (open and closed), the comparison with the computed style, contrast on the painted fill, the count and the real click.
 *  - and for the FORMS (contact form, role form): FORM_SLOTS, expectations read from the resolved block tree (the form's field.*, button.* and captcha.* groups;
 *    the submit's padding, shadow and lift live at button.decoration.* on a form), the comparison of the fields, the submit (the button measures plus a real
 *    hover), the captcha and the form's own container, the painted fill and contrast, the clip, overflow and the count.
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
 * The cards of the sheet. Every one holds a heading (its UNIQUE text finds it again, in the markup and in the page) and a paragraph;
 * `image` puts a placeholder image first; `padding` is the panel's literal padding in px (a panel keeps its recipe padding: no kit key),
 * `flush` = a zero-padding panel whose image touches the card's corners (the clip is only exercised there); `carrier` is the block that
 * carries the marker: `group`, or `column` for the three cards of a 3-column row. `ground` is the marker's ground field (`inverted` |
 * `accent`, light and surface bands only): the card is filled with `text` or `accent` and its children use the allowed inks (`inkHeading`,
 * `inkPara`: `bg` on an inverted ground, `accent-text` on an accent one, `text`/`muted` on a plain card). `buttons` = how many kit buttons
 * (a primary `control`, a secondary `control-secondary`, both `on:"panel"`) the slot holds. `featured` marks the card whose prominence
 * against its two row neighbours is measured (the accent middle card of the three-column row).
 */
const CARD_SLOTS = [];
const GROUND_INK = { inverted: 'bg', accent: 'accent-text' };
const addSlot = (o) => {
  const ink = o.ground ? GROUND_INK[o.ground] : null;
  CARD_SLOTS.push(Object.assign({ carrier: 'group', image: false, flush: false, padding: null, row: false, ground: null, buttons: 0, featured: false, inkHeading: ink || 'text', inkPara: ink || 'muted' }, o));
};
for (const s of SURFACES) {
  for (const role of ['card', 'panel']) {
    addSlot({ key: `${role}-${s.key}`, heading: `${capital(role)} on ${s.key}`, band: s.key, on: CARD_ON[s.key], role, image: role === 'card' && s.key === 'bg', padding: role === 'panel' ? 40 : null });
  }
  if (s.key === 'bg') addSlot({ key: 'panel-clip', heading: 'Panel clip on bg', band: 'bg', on: 'light', role: 'panel', image: true, flush: true, padding: 0 });
  // Grounds (Task 2 of the kit-limits plan) exist on the two light bands only: an inverted card and panel, an accent tile, and an inverted
  // panel that holds a primary and a secondary kit button on the panel surface.
  if (s.key === 'bg' || s.key === 'surface') {
    const base = { band: s.key, on: CARD_ON[s.key] };
    addSlot(Object.assign({ key: `inverted-card-${s.key}`, heading: `Inverted card on ${s.key}`, role: 'card', ground: 'inverted' }, base));
    addSlot(Object.assign({ key: `inverted-panel-${s.key}`, heading: `Inverted panel on ${s.key}`, role: 'panel', ground: 'inverted', padding: 40 }, base));
    addSlot(Object.assign({ key: `accent-tile-${s.key}`, heading: `Accent tile on ${s.key}`, role: 'card', ground: 'accent' }, base));
    addSlot(Object.assign({ key: `panel-buttons-${s.key}`, heading: `Button panel on ${s.key}`, role: 'panel', ground: 'inverted', padding: 40, buttons: 2 }, base));
  }
}
// The three-column row: plain cards either side of an ACCENT-ground middle card (the featured plan).
for (const i of [1, 2, 3]) addSlot({ key: `column-${i}`, heading: `Column card ${i}`, band: 'bg', on: 'light', role: 'card', carrier: 'column', row: true, ground: i === 2 ? 'accent' : null, featured: i === 2 });

/**
 * The items of the sheet (accordion and toggle, Task 5 of the accordion-adapter plan). Every slot is ONE marked block (`item` on all four
 * bands, `item-rule` on the two light ones) whose UNIQUE first title finds it again in the markup and in the page: `carrier` accordion = a
 * `divi/accordion` of three items (the first open, two closed) that one marker styles, toggle = a stand-alone `divi/toggle` (a boxed
 * `item` one, closed, and two `item-rule` ones, one open and one closed). `items` = the titles and the opening state the sheet gives them.
 */
const ITEM_SLOTS = [];
for (const s of SURFACES) {
  const on = CARD_ON[s.key];
  ITEM_SLOTS.push({ key: `accordion-${s.key}`, carrier: 'accordion', role: 'item', band: s.key, on, items: [{ title: `Accordion on ${s.key} one`, open: true }, { title: `Accordion on ${s.key} two`, open: false }, { title: `Accordion on ${s.key} three`, open: false }] });
  if (s.key === 'bg' || s.key === 'surface') {
    // A boxed toggle (role item): the only block that carries the kit's card padding (space-card), which an accordion item never takes.
    ITEM_SLOTS.push({ key: `toggle-item-${s.key}`, carrier: 'toggle', role: 'item', band: s.key, on, items: [{ title: `Boxed toggle on ${s.key}`, open: false }] });
    ITEM_SLOTS.push({ key: `toggle-open-${s.key}`, carrier: 'toggle', role: 'item-rule', band: s.key, on, items: [{ title: `Rule toggle open on ${s.key}`, open: true }] });
    ITEM_SLOTS.push({ key: `toggle-closed-${s.key}`, carrier: 'toggle', role: 'item-rule', band: s.key, on, items: [{ title: `Rule toggle closed on ${s.key}`, open: false }] });
  }
}
const ITEM_ANSWER = '<p>Placeholder answer: one short sentence that the surrounding colours must keep readable.</p>';

/** One item slot as markup: the marked accordion (with its three items) or toggle. The recipe's own keys only (headingLevel, family, size, line height; a rule toggle's structural padding): the kit supplies every colour, frame, radius and shadow. */
function itemMarkup(slot) {
  const marker = slot.on === 'light' ? { kit: slot.role } : { kit: slot.role, on: slot.on };
  const titleFont = { decoration: { font: { font: { desktop: { value: { headingLevel: 'h3', family: '{{aied:font:display}}', size: '20px', lineHeight: '1.3em' } } } } } };
  const bodyFont = { decoration: { bodyFont: { body: { font: { desktop: { value: { family: '{{aied:font:body}}', size: '16px', lineHeight: '1.7em' } } } } } } };
  const open = (it) => (it.open ? { advanced: { open: { desktop: { value: 'on' } } } } : null);
  if (slot.carrier === 'accordion') {
    const kids = slot.items.map((it) => blockOf('accordion-item', Object.assign({ title: { innerContent: { desktop: { value: it.title } } }, content: { innerContent: { desktop: { value: ITEM_ANSWER } } } }, open(it) ? { module: open(it) } : {}, { builderVersion: '5.8.0' }), null)).join('\n');
    return blockOf('accordion', { title: titleFont, content: bodyFont, builderVersion: '5.8.0', aied: marker }, kids);
  }
  const it = slot.items[0];
  const decoration = slot.role === 'item-rule' ? { spacing: { desktop: { value: { padding: { top: '28px', right: '0px', bottom: '28px', left: '0px' } } } } } : null;
  const module = Object.assign({}, decoration ? { decoration } : {}, open(it) || {});
  return blockOf('toggle', Object.assign({ title: Object.assign({ innerContent: { desktop: { value: it.title } } }, titleFont), content: Object.assign({ innerContent: { desktop: { value: ITEM_ANSWER } } }, bodyFont) }, Object.keys(module).length ? { module } : {}, { builderVersion: '5.8.0', aied: marker }), null);
}

/**
 * The forms of the sheet (Task 5 of the forms-adapter plan): one `divi/contact-form` per light band (`bg`, marker `{"kit":"form"}`, and
 * `surface`, marker `{"kit":"form","on":"surface"}`), each with two fields, the basic captcha (Divi's default, so nothing is written for it)
 * and a submit button. A form is found again by the UNIQUE text of its submit button (the marker is stripped by the resolver), `fields` is the
 * number of `divi/contact-field` blocks it holds.
 */
const FORM_SLOTS = [
  { key: 'form-bg', band: 'bg', on: 'light', submit: 'Send form on bg', fields: 2 },
  { key: 'form-surface', band: 'surface', on: 'surface', submit: 'Send form on surface', fields: 2 },
];

/**
 * One form slot as markup: a marked `divi/contact-form` with two fields. The recipe's own keys only: the label, the "custom button" switch
 * (`button.decoration.button.enable`, icon off, as every recipe form writes it) and the body font token on the button, the fields and the
 * captcha. The kit supplies every colour, frame, radius, padding, shadow and lift, and writes NO `module.decoration`.
 */
function formMarkup(slot) {
  const marker = slot.on === 'light' ? { kit: 'form' } : { kit: 'form', on: slot.on };
  const family = { font: { font: { desktop: { value: { family: '{{aied:font:body}}' } } } } };
  const field = (id, label, type) => blockOf('contact-field', { fieldItem: { advanced: Object.assign({ id: { desktop: { value: id } } }, type ? { type: { desktop: { value: type } } } : {}), innerContent: { desktop: { value: label } } }, builderVersion: '5.8.0' }, null);
  const button = { innerContent: { desktop: { value: { text: slot.submit } } }, decoration: Object.assign({ button: { desktop: { value: { enable: 'on', icon: { enable: 'off' } } } } }, family) };
  return blockOf('contact-form', { button, field: { decoration: family }, captcha: { decoration: family }, builderVersion: '5.8.0', aied: marker }, [field('Name', 'Name', null), field('Email', 'Email Address', 'email')].join('\n'));
}

/** The kit buttons a button slot holds, in order: [{ role, text }]. */
const PANEL_BUTTONS = [{ role: 'control', text: 'Primary action' }, { role: 'control-secondary', text: 'Secondary action' }];
/** One button block of the sheet: the marker names the surface (`on`, omitted for light). */
const buttonBlock = (role, text, on) => `<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"${text}"}}},"decoration":{"font":{"font":{"desktop":{"value":{"family":"{{aied:font:body}}"}}}}}},${V},"aied":${JSON.stringify(on === 'light' ? { kit: role } : { kit: role, on })}} /-->`;

/** One card slot as markup: the marked carrier with an optional image, a heading and a paragraph (colours from the language, as recipes write them). */
function cardMarkup(slot) {
  const marker = slot.on === 'light' ? { kit: slot.role } : { kit: slot.role, on: slot.on };
  if (slot.ground) marker.ground = slot.ground;
  const px4 = (n) => ({ top: `${n}px`, right: `${n}px`, bottom: `${n}px`, left: `${n}px` });
  const inset = slot.flush ? { module: { decoration: { spacing: { desktop: { value: { padding: { top: '16px', right: '24px', bottom: '16px', left: '24px' } } } } } } } : {};
  const image = blockOf('image', { module: { advanced: { sizing: { desktop: { value: { width: '100%', aspectRatio: { width: '16', height: '9' } } } } } }, image: { innerContent: { desktop: { value: { src: '{{aied:image:card-blue-1}}', alt: 'Placeholder photograph: replace with your own' } } }, decoration: { fit: { desktop: { value: { objectFit: 'cover' } } } } }, builderVersion: '5.8.0' }, null);
  const heading = blockOf('heading', Object.assign({ title: { innerContent: { desktop: { value: slot.heading } }, decoration: { font: { font: { desktop: { value: { headingLevel: 'h3', color: `{{aied:color:${slot.inkHeading}}}`, family: '{{aied:font:display}}', size: '24px' } } } } } }, builderVersion: '5.8.0' }, inset), null);
  const para = blockOf('text', Object.assign({ content: { innerContent: { desktop: { value: '<p>Placeholder copy for a card: one short sentence that the surrounding colours must keep readable.</p>' } }, decoration: { bodyFont: { body: { font: { desktop: { value: { color: `{{aied:color:${slot.inkPara}}}`, family: '{{aied:font:body}}', size: '16px', lineHeight: '1.5em' } } } } } } }, builderVersion: '5.8.0' }, inset), null);
  const layout = slot.carrier === 'column' ? { flexDirection: 'column', rowGap: '16px' } : { flexDirection: 'column', rowGap: '12px' };
  const decoration = { layout: { desktop: { value: layout } } };
  if (slot.padding !== null) decoration.spacing = { desktop: { value: { padding: px4(slot.padding) } } };
  const buttons = slot.buttons ? PANEL_BUTTONS.slice(0, slot.buttons).map((b) => buttonBlock(b.role, b.text, 'panel')).join('\n') : null;
  const body = [slot.image ? image : null, heading, para, buttons].filter(Boolean).join('\n');
  if (slot.carrier === 'column') {
    decoration.sizing = { desktop: { value: { flexType: '8_24' } }, phone: { value: { flexType: '24_24' } } };
    return blockOf('column', { module: { advanced: { type: { desktop: { value: '1_3' } } }, decoration }, builderVersion: '5.8.0', aied: marker }, body);
  }
  return blockOf('group', { module: { decoration }, builderVersion: '5.8.0', aied: marker }, body);
}

/** The tokenised sheet markup: 8 page-level buttons (SURFACES x ROLES), 20 cards (CARD_SLOTS), inside the two button panels 4 more buttons, 10 item blocks (ITEM_SLOTS: 4 accordions, 6 toggles) and 2 forms (FORM_SLOTS: one on the bg band, one on the surface band). */
function sheetMarkup() {
  const v = V;
  const sections = SURFACES.map((s) => {
    const buttons = ROLES.map((r) => buttonBlock(r.role, r.text, s.on)).join('\n');
    const cards = [...CARD_SLOTS.filter((c) => c.band === s.key && !c.row).map(cardMarkup), ...ITEM_SLOTS.filter((c) => c.band === s.key).map(itemMarkup), ...FORM_SLOTS.filter((c) => c.band === s.key).map(formMarkup)].join('\n');
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
/** The attribute objects of the PAGE-LEVEL buttons (the 8 of SLOTS): every button that is not inside a group (the button panels hold the others). */
function pageButtonAttrs(markup) {
  const out = [];
  const walk = (nodes, inGroup) => { for (const n of nodes) { if (n.name === 'divi/button' && !inGroup) out.push(n.attrs); walk(n.children, inGroup || n.name === 'divi/group'); } };
  walk(blockTree(markup), false);
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
/** A computed transform as its 2D matrix [a, b, c, d, tx, ty] ('none' = the identity), or null when it is not a matrix. */
function matrix2d(str) {
  const t = String(str || 'none').trim();
  if (t === 'none') return [1, 0, 0, 1, 0, 0];
  let m = /^matrix\(([^)]+)\)$/.exec(t);
  if (m) { const v = m[1].split(',').map(parseFloat); return v.length === 6 && v.every((x) => !Number.isNaN(x)) ? v : null; }
  m = /^matrix3d\(([^)]+)\)$/.exec(t);
  if (m) { const v = m[1].split(',').map(parseFloat); return v.length === 16 && v.every((x) => !Number.isNaN(x)) ? [v[0], v[1], v[4], v[5], v[12], v[13]] : null; }
  return null;
}
/** Two computed transforms are the same matrix (scale and rotate terms within 0.001, the translation within 0.1px). An unreadable one is never the same. */
function sameTransform(a, b) {
  const x = matrix2d(a); const y = matrix2d(b);
  return !!x && !!y && x.every((v, i) => Math.abs(v - y[i]) <= (i < 4 ? 0.001 : 0.1));
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
/** Every descendant of a node, depth first. */
function descendants(node) { return node.children.flatMap((c) => [c, ...descendants(c)]); }
/**
 * The attribute object of each sheet card in the RESOLVED markup (the marker is gone, so a card is found by the carrier block that holds
 * its unique heading text): [{ slot, attrs, buttons, inks }] in CARD_SLOTS order; attrs is null when the card is not in the markup;
 * `buttons` = the attribute objects of the divi/button blocks inside it (document order), `inks` = { heading, paragraph }: the colour the
 * first heading and the first text inside it carry (a token in the sheet, a colour in the resolved markup).
 */
function cardAttrs(markup, slots = CARD_SLOTS) {
  const found = new Map();
  const walk = (nodes) => {
    for (const n of nodes) {
      if (n.name === 'divi/group' || n.name === 'divi/column') {
        const h = firstHeading(n);
        if (h !== null && !found.has(n.name + '|' + h)) found.set(n.name + '|' + h, n);
      }
      walk(n.children);
    }
  };
  walk(blockTree(markup));
  return slots.map((slot) => {
    const node = found.get(`divi/${slot.carrier}|${slot.heading}`) || null;
    const all = node ? descendants(node) : [];
    const heading = all.find((x) => x.name === 'divi/heading');
    const text = all.find((x) => x.name === 'divi/text');
    return {
      slot, attrs: node ? node.attrs : null, buttons: all.filter((x) => x.name === 'divi/button').map((x) => x.attrs),
      inks: { heading: heading ? dig(heading.attrs, 'title', 'decoration', 'font', 'font', 'desktop', 'value', 'color') || null : null, paragraph: text ? dig(text.attrs, 'content', 'decoration', 'bodyFont', 'body', 'font', 'desktop', 'value', 'color') || null : null },
    };
  });
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
 * carries any hover key (a panel is static: that is an adapter fault, not an expectation), and, for a `ground`, a fill that is not opaque, a
 * frame side that is not the fill, or a hover fill or frame. A non-empty list is a `gate` failure and
 * compareCard refuses to compare: a null expectation must never silently skip a check.
 */
function missingCardExpectations(exp, role, ground = null) {
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
  if (ground && r.fill) {
    // The ground contract (KitAdapters::card): an opaque fill, every frame side the colour of the fill, no hover fill and no hover frame.
    const f = colour(r.fill);
    if (!f || f.a < 0.999) out.push(`ground fill is not opaque (${r.fill})`);
    for (const [k] of SIDES) if (r[k].color && !sameColour(r[k].color, r.fill)) out.push(`ground frame ${k} is not the fill`);
    for (const k of ['border.hover', 'background.hover']) if (exp.hoverKeys.includes(k)) out.push('ground carries ' + k);
  }
  return out;
}

/**
 * Every disagreement ([code, detail]) between a card's expectation and what the browser computed: codes card-border (the four sides' width,
 * style, colour), card-radius, card-fill, card-padding, card-shadow (it must sit on the card element itself) and card-hover (hover frame
 * colour only when the markup has one, the hover shadow, translateY of the lift, and that nothing else moved or changed; a PANEL must show
 * no change at all). `computed` = { rest: { el }, hover: { el } } with the fields measureCards collects; the hover state is REQUIRED. `ground`
 * (the slot's, or null) holds the expectation to the ground contract of missingCardExpectations too.
 */
function compareCard(exp, role, computed, ground = null) {
  const miss = missingCardExpectations(exp, role, ground);
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
    // The lift is a pure translateY of exp.lift: a scale, a rotate or a sideways drift on top of it is a failure too.
    if (!near(ty, exp.lift, 0.3)) out.push(['card-hover', `hover: translateY ${ty}px, expected ${exp.lift}px`]);
    else if (!sameTransform(he.transform, `matrix(1, 0, 0, 1, 0, ${ty})`)) out.push(['card-hover', `hover: transform ${he.transform} is more than the ${exp.lift}px lift`]);
  } else if (!sameTransform(he.transform, el.transform)) {
    out.push(['card-hover', `hover: the ${role} moved (transform ${he.transform}, rest ${el.transform})${role === 'panel' ? ': a panel is static' : ' but the kit has no lift'}`]);
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
 * missing text is a failure, never a skip. A card with a `ground` also exempts a frame side that is the colour of its own fill (the frame
 * only keeps the box the size of its neighbours). c = { el, heading: { color }|null, paragraph: { color }|null }.
 */
function cardContrastProblems(c, fill, hairline = null, ground = null) {
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
    if (ground && sameColour(raw, c.el.backgroundColor)) { info.fillFrame = true; continue; } // a ground's frame IS its fill: it gives the box its size, the fill gives the shape
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

/**
 * Ground fill: the PAINTED fill (screenshot mean of the card's inner box, children hidden) must be the fill the resolved markup says
 * (`exp.rest.fill`, text or accent), within 4 per channel, whatever the computed style reports (something drawn over a ground shows here).
 * Code ground-fill. A hole in the expectation, a translucent expectation or a missing sample fails; nothing is skipped.
 */
function groundFillProblems(exp, fill) {
  const want = colour(exp && exp.rest ? exp.rest.fill : null);
  if (!want) return [['ground-fill', 'no resolved ground fill in the expectation (an adapter attribute path drifted)']];
  if (want.a < 0.999) return [['ground-fill', `the resolved ground fill ${exp.rest.fill} is not opaque: it cannot be judged against a screenshot`]];
  if (!fill) return [['ground-fill', 'the painted fill was not sampled']];
  const d = Math.max(Math.abs(want.r - fill.r), Math.abs(want.g - fill.g), Math.abs(want.b - fill.b));
  return d > 4 ? [['ground-fill', `painted fill ${hex(fill)} differs from the resolved ground fill ${exp.rest.fill} by ${Math.round(d)}`]] : [];
}

// ---- the featured plan -----------------------------------------------------------------------------------------------
/**
 * How much more prominent the featured (accent middle) card must be than the LARGER of its two row neighbours: its prominence (the mean
 * painted luminance difference between its box and the band behind it) at least FEATURED_RATIO times theirs. 1.5 is the spec's starting
 * value. CONFIRMED ON THE FIRST LIVE SMOKE and then FROZEN: if a live cell fails, the fix is in the kit, the adapter or the recipe, never
 * a lower number here.
 */
const FEATURED_RATIO = 1.5;
const lin = (v) => { const x = v / 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); };
const relLum = (c) => 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
/**
 * Prominence of a card: the mean, over every pixel of a screenshot of its box (children hidden, shadow off, so the fill and the frame are
 * all that is drawn), of |relative luminance of the pixel - relative luminance of the band|. Per pixel, not the difference of the means, so
 * a dark frame around a pale fill counts. img = decodePng(...), band = { r, g, b } (a strip of the band just above the card).
 */
function boxProminence(img, band) {
  const n = img.data.length / 4;
  if (!n) throw new Error('boxProminence: the box image is empty');
  const lb = relLum(band);
  let sum = 0;
  for (let i = 0; i < img.data.length; i += 4) sum += Math.abs(relLum({ r: img.data[i], g: img.data[i + 1], b: img.data[i + 2] }) - lb);
  return sum / n;
}
/**
 * Code `featured`: v = { featured, neighbours: [a, b] } (prominences). The featured card must be visible at all (> 0) and at least `ratio`
 * times the larger neighbour (the edge is inclusive; 1e-9 of float slack). Any missing or non-finite number fails: it is never skipped.
 */
function featuredProblems(v, ratio = FEATURED_RATIO) {
  const ok = (x) => typeof x === 'number' && Number.isFinite(x) && x >= 0;
  if (!v || !ok(v.featured) || !Array.isArray(v.neighbours) || v.neighbours.length !== 2 || !v.neighbours.every(ok)) {
    return [['featured', `prominence could not be measured (${JSON.stringify(v)}): the featured card and both neighbours are required`]];
  }
  const larger = Math.max(...v.neighbours);
  if (v.featured <= 0 || v.featured + 1e-9 < ratio * larger) {
    const times = larger > 0 ? `${(v.featured / larger).toFixed(2)}x` : 'a card that does not stand out at all';
    return [['featured', `prominence ${round3(v.featured)} is ${times} the larger neighbour's ${round3(larger)} (needs ${ratio}x)`]];
  }
  return [];
}
const round3 = (n) => Math.round(n * 1000) / 1000;

// ---- a kit button on the inverted panel ------------------------------------------------------------------------------
/**
 * Everything wrong with ONE kit button (`on:"panel"`) inside an inverted panel: exp = expectFromAttrs of the resolved button, computed =
 * { rest: { el, wrap }, hover: { el, wrap } } as for compareButton, panelFill = the painted panel ({r,g,b}, screenshot with the children
 * hidden). Code panel-button covers what the panel changes: the label against the fill as painted over the panel >= 4.5 and a transparent
 * button's border >= 3.0 against the panel (rest and hover: contrastProblems), and the hover colours (fill, label, border) as resolved.
 * Every other disagreement with the markup keeps its own button code (border, radius, fill, label, text, padding, shadow, lift). The hover
 * state is required; an expectation with a hole is refused, never skipped. Returns { problems, info: { rest, hover } }.
 */
function panelButtonProblems(exp, computed, panelFill) {
  const miss = missingExpectations(exp);
  if (miss.length) return { problems: [['panel-button', 'expectation missing (an adapter attribute path drifted): ' + miss.join(', ')]], info: {} };
  if (!panelFill) return { problems: [['panel-button', 'the painted panel under the button was not sampled']], info: {} };
  const out = [];
  const cmp = compareButton(exp, computed);
  for (const [code, d] of cmp.problems) out.push([code === 'hover' ? 'panel-button' : code, d]);
  const cr = contrastProblems(computed.rest.el, panelFill, 'rest');
  for (const [, d] of cr.problems) out.push(['panel-button', d]);
  let ch = { problems: [], info: {} };
  if (computed.hover && computed.hover.el) {
    ch = contrastProblems(computed.hover.el, panelFill, 'hover');
    for (const [, d] of ch.problems) out.push(['panel-button', d]);
  } else out.push(['panel-button', 'the hover state was not measured']);
  return { problems: out, info: { rest: cr.info, hover: ch.info } };
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

// ---- items (accordion and toggle) ------------------------------------------------------------------------------------
/** The item blocks of the RESOLVED markup (the marker is gone, so a block is found by its first title): [{ slot, attrs, items: [{ title, open }] }] in ITEM_SLOTS order; attrs and items are null when the block is not in the markup. */
function itemAttrs(markup, slots = ITEM_SLOTS) {
  const titleOf = (n) => dig(n.attrs, 'title', 'innerContent', 'desktop', 'value');
  const openOf = (n) => dig(n.attrs, 'module', 'advanced', 'open', 'desktop', 'value') === 'on';
  const nodes = [];
  const walk = (list) => { for (const n of list) { if (n.name === 'divi/accordion' || n.name === 'divi/toggle') nodes.push(n); walk(n.children); } };
  walk(blockTree(markup));
  const itemsOf = (n) => (n.name === 'divi/accordion' ? n.children.filter((c) => c.name === 'divi/accordion-item') : [n]).map((c) => ({ title: titleOf(c), open: openOf(c) }));
  return slots.map((slot) => {
    const name = slot.carrier === 'accordion' ? 'divi/accordion' : 'divi/toggle';
    const node = nodes.find((n) => n.name === name && (itemsOf(n)[0] || {}).title === slot.items[0].title) || null;
    return { slot, attrs: node ? node.attrs : null, items: node ? itemsOf(node) : null };
  });
}

/**
 * What the kit says about one item block, read ONLY from its resolved attributes: { carrier, rest: { top|right|bottom|left: {width, style,
 * color}, radius:[4], padding:[4]|null }, shadow: shadowExpect|null, hoverKeys, open: { fill, title, body, icon }, closed: { fill, title,
 * body, icon } }. Every colour lives on a state group of the block itself (KitAdapters::item): `openToggle` and `closedToggle` carry the
 * fill and, on an accordion, the title; a toggle's closed title is the `closedTitle` group; the answer is `content`; the icons are
 * `closedToggleIcon` and (toggle only) `openToggleIcon`. The frame, radius, padding and shadow are `module.decoration`, like a card.
 */
function expectItemFromAttrs(attrs, carrier) {
  const md = dig(attrs, 'module', 'decoration') || {};
  const border = dig(md, 'border', 'desktop', 'value') || {};
  const styles = border.styles || {};
  const all = styles.all || {};
  const side = (k) => { const e = Object.assign({}, all, styles[k] || {}); return { width: e.width !== undefined && e.width !== '' ? parseFloat(e.width) : null, style: e.style || null, color: e.color || null }; };
  const radius = border.radius || {};
  const pad = dig(md, 'spacing', 'desktop', 'value', 'padding') || null;
  const hoverKeys = [];
  if (dig(md, 'border', 'desktop', 'hover') !== undefined) hoverKeys.push('border.hover');
  if (dig(md, 'boxShadow', 'desktop', 'hover') !== undefined) hoverKeys.push('boxShadow.hover');
  if (dig(md, 'transform') !== undefined) hoverKeys.push('transform');
  if (dig(md, 'background', 'desktop', 'hover') !== undefined) hoverKeys.push('background.hover');
  const fill = (g) => dig(attrs, g, 'decoration', 'background', 'desktop', 'value', 'color') || null;
  const title = (g) => dig(attrs, g, 'decoration', 'font', 'font', 'desktop', 'value', 'color') || null;
  const icon = (g) => dig(attrs, g, 'decoration', 'icon', 'desktop', 'value', 'color') || null;
  const body = dig(attrs, 'content', 'decoration', 'bodyFont', 'body', 'font', 'desktop', 'value', 'color') || null;
  const toggle = carrier === 'toggle';
  return {
    carrier,
    rest: {
      top: side('top'), right: side('right'), bottom: side('bottom'), left: side('left'),
      radius: CORNERS.map((k) => (radius[k] !== undefined ? parseFloat(radius[k]) : null)),
      padding: pad ? ['top', 'right', 'bottom', 'left'].map((k) => (pad[k] !== undefined && pad[k] !== '' ? parseFloat(pad[k]) : null)) : null,
    },
    shadow: shadowExpect(dig(md, 'boxShadow', 'desktop', 'value')),
    hoverKeys,
    open: { fill: fill('openToggle'), title: title('openToggle'), body, icon: icon('openToggleIcon') },
    closed: { fill: fill('closedToggle'), title: toggle ? title('closedTitle') : title('closedToggle'), body, icon: icon('closedToggleIcon') },
  };
}

/**
 * The expectation fields that are missing although KitAdapters::item() ALWAYS writes them (the frame on every side, the four radii, both
 * states' fill and title, the answer colour, the closed icon and, on a toggle, the open icon and the padding), plus what the adapter must
 * NEVER write: an open icon or a padding on an accordion (Divi hides the first and pads the ANSWER with the second), any hover key, and a
 * shadow on a rule row. A non-empty list is a `gate` failure and compareItem refuses to compare: a null never silently skips a check.
 */
function missingItemExpectations(exp, role) {
  const out = [];
  const num = (v) => typeof v === 'number' && !Number.isNaN(v);
  const r = exp.rest;
  if (!Array.isArray(r.radius) || r.radius.length !== 4 || !r.radius.every(num)) out.push('rest.radius');
  for (const [k] of SIDES) {
    if (!num(r[k].width)) out.push(`rest.${k}.width`);
    if (!r[k].style) out.push(`rest.${k}.style`);
    if (!r[k].color) out.push(`rest.${k}.color`);
  }
  for (const [state, name] of [['open', 'open'], ['closed', 'closed']]) {
    if (!exp[state].fill) out.push(`${name}.fill`);
    if (!exp[state].title) out.push(`${name}.title`);
  }
  if (!exp.open.body) out.push('open.body');
  if (!exp.closed.icon) out.push('closed.icon');
  if (exp.carrier === 'toggle') {
    if (!exp.open.icon) out.push('open.icon');
    if (!Array.isArray(r.padding) || r.padding.length !== 4 || !r.padding.every(num)) out.push('rest.padding');
  } else {
    if (exp.open.icon) out.push('accordion carries an open icon (Divi hides it)');
    if (r.padding) out.push('accordion item padding is written (it would pad the answer)');
  }
  for (const k of exp.hoverKeys) out.push('item carries ' + k);
  if (role === 'item-rule' && exp.shadow) out.push('rule row carries a shadow');
  return out;
}

/**
 * Every disagreement ([code, detail]) between an item's expectation and the item as the browser measured it in `phase` ('rest', 'after
 * click', ...): m = { open, el, title: {color}, icon: {color, shown, content, display}, body: {color, visible} } (measureItems). State
 * independent: item-border (width, style, colour of each side), item-radius, item-padding (only where the markup writes one), item-shadow
 * (on the item element itself; a rule row shows none). Per STATE (open or closed, as the item's class says): item-fill (the item's
 * background), item-title, item-body (an open item only: a closed answer is hidden), item-icon (a drawn ::before in the colour of that
 * state; an accordion's open icon is hidden by Divi and not judged). An expectation with a hole is refused (item-expectation).
 */
function compareItem(exp, role, m, phase = 'rest') {
  const miss = missingItemExpectations(exp, role);
  if (miss.length) return { problems: [['item-expectation', 'expectation missing (an adapter attribute path drifted): ' + miss.join(', ')]] };
  const out = [];
  const el = m.el;
  const r = exp.rest;
  const at = (s) => `${phase}: ${s}`;
  for (const [side, cap] of SIDES) {
    const e = r[side];
    if (!near(px(el[`border${cap}Width`]), e.width, BORDER_TOLERANCE)) out.push(['item-border', at(`border-${side}-width ${el[`border${cap}Width`]}, expected ${e.width}px`)]);
    if (e.width > 0) {
      if (el[`border${cap}Style`] !== e.style) out.push(['item-border', at(`border-${side}-style ${el[`border${cap}Style`]}, expected ${e.style}`)]);
      if (!sameColour(e.color, el[`border${cap}Color`])) out.push(['item-border', at(`border-${side}-color ${el[`border${cap}Color`]}, expected ${e.color}`)]);
    }
  }
  CORNER_PROPS.forEach((k, i) => { if (!near(px(el[k]), r.radius[i])) out.push(['item-radius', at(`${k} ${el[k]}, expected ${r.radius[i]}px`)]); });
  if (r.padding) ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'].forEach((k, i) => { if (!near(px(el[k]), r.padding[i])) out.push(['item-padding', at(`${k} ${el[k]}, expected ${r.padding[i]}px`)]); });
  for (const [, d] of shadowProblems(exp.shadow, el, { boxShadow: 'none' }, phase, ['item', 'item']).problems) out.push(['item-shadow', d]);

  const s = m.open ? exp.open : exp.closed;
  const state = m.open ? 'open' : 'closed';
  if (!sameColour(s.fill, el.backgroundColor)) out.push(['item-fill', at(`background-color ${el.backgroundColor}, expected ${s.fill} (${state} item)`)]);
  if (!m.title || !sameColour(s.title, m.title.color)) out.push(['item-title', at(`title colour ${m.title ? m.title.color : 'not found'}, expected ${s.title} (${state} item)`)]);
  if (m.open && (!m.body || !sameColour(exp.open.body, m.body.color))) out.push(['item-body', at(`answer colour ${m.body ? m.body.color : 'not found'}, expected ${exp.open.body}`)]);
  if (s.icon) {
    if (!m.icon || !m.icon.shown) out.push(['item-icon', at(`no icon is drawn on the ${state} item (::before content ${m.icon ? m.icon.content : 'n/a'}, display ${m.icon ? m.icon.display : 'n/a'}), expected ${s.icon}`)]);
    else if (!sameColour(s.icon, m.icon.color)) out.push(['item-icon', at(`icon colour ${m.icon.color}, expected ${s.icon} (${state} item)`)]);
  }
  return { problems: out };
}

/**
 * Problems of the item count (code items): every sheet block rendered with the number of items and the opening state the resolved markup
 * gives it, an accordion inside an accordion and a toggle outside one, and every answer shown exactly when its item is open. measured =
 * measureItems(...).items in ITEM_SLOTS order; lists = for each slot the [{ title, open }] the resolved markup gives it. An empty slot
 * list is an error.
 */
function itemCountProblems(measured, lists, slots = ITEM_SLOTS) {
  if (!slots.length) throw new Error('no item slots: nothing would be checked');
  const bad = [];
  slots.forEach((slot, i) => {
    const mm = measured[i]; const want = lists[i] || [];
    if (!mm || !mm.found) { bad.push(`${slot.key} is not in the page`); return; }
    if (mm.accordion !== (slot.carrier === 'accordion')) { bad.push(mm.accordion ? `${slot.key} is inside an accordion, expected a stand-alone toggle` : `${slot.key} is not inside an accordion`); return; }
    if (mm.count !== want.length || mm.items.length !== want.length) { bad.push(`${slot.key} renders ${mm.count} items, expected ${want.length}`); return; }
    const openNow = mm.items.map((it, j) => (it.open ? j + 1 : null)).filter(Boolean);
    const openWant = want.map((it, j) => (it.open ? j + 1 : null)).filter(Boolean);
    if (openNow.join() !== openWant.join()) { bad.push(`${slot.key} opens items [${openNow.join(',')}] at rest, expected [${openWant.join(',')}]`); return; }
    mm.items.forEach((it, j) => { if (it.body.visible !== it.open) bad.push(`${slot.key} item ${j + 1} is ${it.open ? 'open but its answer is not shown' : 'closed but its answer is shown'}`); });
  });
  if (measured.length > slots.length) bad.push(`${measured.length} blocks measured for ${slots.length} slots`);
  if (!bad.length) return [];
  const found = slots.filter((s, i) => measured[i] && measured[i].found).length;
  return [['items', `${found} of ${slots.length} item blocks rendered as the sheet says: ${bad.slice(0, 6).join('; ')}${bad.length > 6 ? ` and ${bad.length - 6} more` : ''}`]];
}

/** The index of the item the gate clicks: an accordion's first CLOSED item, a toggle's only item; -1 when there is nothing to click on (the gate then fails). */
function clickTarget(slot, block) {
  if (!block || !block.found || !block.items.length) return -1;
  return slot.carrier === 'accordion' ? block.items.findIndex((it) => !it.open) : 0;
}

/**
 * Code item-click: after a real click on item `idx`'s title (and Divi's slide), an accordion has exactly that item open and every other
 * item closed (the previously open one closed), a toggle has flipped, and every answer is shown exactly when its item is open. before and
 * after = measureItems blocks of the slot.
 */
function itemClickProblems(slot, before, after, idx) {
  if (!after || !after.found) return [['item-click', `the block is gone after the click`]];
  const out = [];
  const name = (j) => `item ${j + 1} (${(after.items[j] && after.items[j].text) || 'no title'})`;
  after.items.forEach((it, j) => {
    let want;
    if (slot.carrier === 'accordion') want = j === idx;
    else want = !before.items[j].open;
    if (it.open !== want) {
      if (j === idx) out.push(['item-click', `${name(j)} did not ${want ? 'open' : 'close'} after a click on its title`]);
      else out.push(['item-click', `item ${j + 1} (previously open) did not close when item ${idx + 1} opened`]);
    }
    if (it.body.visible !== it.open) out.push(['item-click', `item ${j + 1} is ${it.open ? 'open but its answer is not shown' : 'closed but its answer is shown'} after the click`]);
  });
  return out;
}

/**
 * Contrast on the MEASURED item fill ({r,g,b}, the mean of a screenshot of the item's inner box with its children hidden), code
 * item-contrast: the title colour of the item's state against it >= 4.5, the answer colour >= 4.5 on an open item, a DRAWN icon >= 3.0, and
 * every drawn frame side >= 3.0 except the decorative `border` hairline (`hairline`), which is reported but not judged. An unreadable
 * colour, a missing title or a missing fill sample is a failure, never a skip. m = a measureItems item.
 */
function itemContrastProblems(m, fill, hairline = null) {
  if (!fill) return { problems: [['item-contrast', 'the painted item fill was not sampled']], info: {} };
  const out = []; const info = {};
  const base = { r: fill.r, g: fill.g, b: fill.b, a: 1 };
  const check = (name, raw, min) => {
    const col = raw ? colour(raw) : null;
    if (!col) { out.push(['item-contrast', `${name}: no readable colour found (${raw === undefined || raw === null ? 'none' : raw})`]); return; }
    const r = round2(ratioOf(over(col, base), base));
    info[name] = r;
    if (r < min) out.push(['item-contrast', `${name} colour ${hex(over(col, base))} on the painted item fill ${hex(base)} is ${r}:1, needs ${min}`]);
  };
  check('title', m.title ? m.title.color : null, CONTRAST_LABEL);
  if (m.open) check('answer', m.body ? m.body.color : null, CONTRAST_LABEL);
  if (m.icon && m.icon.shown) check('icon', m.icon.color, CONTRAST_SHAPE);
  for (const [side, cap] of SIDES) {
    if (!(px(m.el[`border${cap}Width`]) > 0)) continue;
    const raw = m.el[`border${cap}Color`];
    const bc = colour(raw);
    if (!bc) { out.push(['item-contrast', `border-${side}: unreadable colour ${raw}`]); continue; }
    const r = round2(ratioOf(over(bc, base), base));
    info[side] = r;
    if (hairline && sameColour(hairline, raw)) { info.hairline = true; continue; }
    if (r < CONTRAST_SHAPE) out.push(['item-contrast', `frame ${side} ${hex(over(bc, base))} on the painted item fill ${hex(base)} is ${r}:1, needs ${CONTRAST_SHAPE}`]);
  }
  return { problems: out, info };
}

/** The painted fill (screenshot mean) against the computed one: item-fill when an opaque computed fill differs by more than 4 per channel, or when nothing was sampled. */
function itemPaintedFillProblems(el, fill) {
  if (!fill) return [['item-fill', 'the painted item fill was not sampled']];
  return paintedFillProblems(el, fill).map(([, d]) => ['item-fill', d.replace('over the card', 'over the item')]);
}

/** Overflow of one measured item: beyond its column (1px) or the window, or the page wider than the window. */
function itemOverflowProblems(it, state, page) {
  return cardOverflowProblems(it, state, page).map(([c, d]) => [c, d.replace(/^([^:]+: )card /, '$1item ')]);
}

/** What a finished run must have checked: at least one cell/width, at least one card and at least one item block (an empty selection fails the run, and so does a caller that forgot to count). */
function emptyRunProblems({ checked, cardsChecked, itemsChecked }) {
  const out = [];
  if (!checked) out.push('no cell/width was checked: nothing was measured');
  if (!cardsChecked) out.push('no card was checked: the card selection was empty');
  if (!itemsChecked) out.push('no item was checked: the item selection was empty');
  return out;
}

// ---- forms (contact form, role form) ---------------------------------------------------------------------------------
/** The contact forms of the RESOLVED markup (the marker is gone, so a form is found by its unique submit text): [{ slot, attrs, fields }] in FORM_SLOTS order; attrs is null when the form is not in the markup, fields = the number of `divi/contact-field` blocks it holds. */
function formAttrs(markup, slots = FORM_SLOTS) {
  const forms = [];
  const walk = (list) => { for (const n of list) { if (n.name === 'divi/contact-form') forms.push(n); walk(n.children); } };
  walk(blockTree(markup));
  return slots.map((slot) => {
    const node = forms.find((n) => dig(n.attrs, 'button', 'innerContent', 'desktop', 'value', 'text') === slot.submit) || null;
    return { slot, attrs: node ? node.attrs : null, fields: node ? node.children.filter((c) => c.name === 'divi/contact-field').length : null };
  });
}

/** Whether a key named `key` occurs anywhere in a value (objects and arrays, depth first). */
function hasKey(o, key) {
  if (!o || typeof o !== 'object') return false;
  return Object.keys(o).some((k) => k === key || hasKey(o[k], key));
}

/**
 * What the kit says about one form, read ONLY from its resolved attributes (KitAdapters::formPatch merged into the sheet's form):
 * { field: { fill, ink, placeholder, radius:[4], padding:[4]|null, top|right|bottom|left: {width, style, color}, shadow, hover },
 *   button: expectFromAttrs of the submit (its padding, shadow and lift live at `button.decoration.*` on a form, mapped onto the module
 *   paths expectFromAttrs reads), captcha: { ink }, moduleKeys: [keys of module.decoration] (the kit must write none) }.
 */
function expectFormFromAttrs(attrs) {
  const fd = dig(attrs, 'field', 'decoration') || {};
  const border = dig(fd, 'border', 'desktop', 'value') || {};
  const styles = border.styles || {};
  const all = styles.all || {};
  const side = (k) => { const e = Object.assign({}, all, styles[k] || {}); return { width: e.width !== undefined && e.width !== '' ? parseFloat(e.width) : null, style: e.style || null, color: e.color || null }; };
  const radius = border.radius || {};
  const pad = dig(fd, 'spacing', 'desktop', 'value', 'padding') || null;
  const bd = dig(attrs, 'button', 'decoration') || {};
  const md = dig(attrs, 'module', 'decoration');
  return {
    field: {
      fill: dig(fd, 'background', 'desktop', 'value', 'color') || null,
      ink: dig(fd, 'font', 'font', 'desktop', 'value', 'color') || null,
      placeholder: dig(fd, 'placeholderFont', 'font', 'desktop', 'value', 'color') || null,
      top: side('top'), right: side('right'), bottom: side('bottom'), left: side('left'),
      radius: CORNERS.map((k) => (radius[k] !== undefined ? parseFloat(radius[k]) : null)),
      padding: pad ? ['top', 'right', 'bottom', 'left'].map((k) => (pad[k] !== undefined && pad[k] !== '' ? parseFloat(pad[k]) : null)) : null,
      shadow: dig(fd, 'boxShadow') !== undefined,
      hover: hasKey(fd, 'hover'),
    },
    button: expectFromAttrs({ button: { decoration: bd }, module: { decoration: { spacing: bd.spacing, boxShadow: bd.boxShadow, transform: bd.transform } } }),
    captcha: { ink: dig(attrs, 'captcha', 'decoration', 'font', 'font', 'desktop', 'value', 'color') || null },
    moduleKeys: md && typeof md === 'object' ? Object.keys(md) : [],
  };
}

/** Whether a submit expectation is the underline form (no top edge, a bottom edge). */
const underlineSubmit = (exp) => exp.rest.top.width === 0 && exp.rest.bottom.width > 0;

/**
 * The expectation fields that are missing although KitAdapters::formPatch() ALWAYS writes them (the field fill, ink, placeholder, four radii,
 * every frame side, the padding; the whole submit as button() writes it; the captcha ink), plus what the adapter must NEVER write: a
 * `module.decoration` on the form (on a contact form it is the whole form container), a field shadow or a field hover (Ruling 3), and, on an
 * underline submit, a hover border colour (it only recolours the 0px sides, so the edge keeps its rest colour on hover). A non-empty list is a
 * `gate` failure and compareForm refuses to compare: a null never silently skips a check.
 */
function missingFormExpectations(exp) {
  const out = [];
  const num = (v) => typeof v === 'number' && !Number.isNaN(v);
  const f = exp.field;
  for (const k of ['fill', 'ink', 'placeholder']) if (!f[k]) out.push('field.' + k);
  if (!Array.isArray(f.radius) || f.radius.length !== 4 || !f.radius.every(num)) out.push('field.radius');
  if (!Array.isArray(f.padding) || f.padding.length !== 4 || !f.padding.every(num)) out.push('field.padding');
  for (const [k] of SIDES) {
    if (!num(f[k].width)) out.push(`field.${k}.width`);
    if (!f[k].style) out.push(`field.${k}.style`);
    if (!f[k].color) out.push(`field.${k}.color`);
  }
  if (f.shadow) out.push('field carries a shadow (Ruling 3)');
  if (f.hover) out.push('field carries a hover or focus state (not proven)');
  const under = exp.button.rest && exp.button.rest.top && underlineSubmit(exp.button);
  for (const m of missingExpectations(exp.button)) if (!(under && m === 'hover.border')) out.push('button.' + m);
  if (under && exp.button.hover.border) out.push('button underline carries a hover border colour (the edge never changes on hover)');
  if (!exp.captcha.ink) out.push('captcha.ink');
  if (exp.moduleKeys.length) out.push('form carries module.decoration (' + exp.moduleKeys.join(',') + '): the kit must write none');
  return out;
}

/** Problems ([code, detail]) of the field at rest against the expectation: form-field-fill, -border (width, style, colour of each side, and no shadow), -radius, -ink, -placeholder, -padding. `name` is e.g. "field 1". */
function compareFormField(ef, f, name, phase = 'rest') {
  const out = [];
  const el = f.el;
  const at = (s) => `${name} ${phase}: ${s}`;
  if (!sameColour(ef.fill, el.backgroundColor)) out.push(['form-field-fill', at(`background-color ${el.backgroundColor}, expected ${ef.fill}`)]);
  for (const [side, cap] of SIDES) {
    const e = ef[side];
    if (!near(px(el[`border${cap}Width`]), e.width, BORDER_TOLERANCE)) out.push(['form-field-border', at(`border-${side}-width ${el[`border${cap}Width`]}, expected ${e.width}px`)]);
    if (e.width > 0) {
      if (el[`border${cap}Style`] !== e.style) out.push(['form-field-border', at(`border-${side}-style ${el[`border${cap}Style`]}, expected ${e.style}`)]);
      if (!sameColour(e.color, el[`border${cap}Color`])) out.push(['form-field-border', at(`border-${side}-color ${el[`border${cap}Color`]}, expected ${e.color}`)]);
    }
  }
  if (parseShadows(el.boxShadow).some((s) => !s.inset)) out.push(['form-field-border', at(`box-shadow ${el.boxShadow}, the kit writes no field shadow`)]);
  CORNER_PROPS.forEach((k, i) => { if (!near(px(el[k]), ef.radius[i])) out.push(['form-field-radius', at(`${k} ${el[k]}, expected ${ef.radius[i]}px`)]); });
  if (!sameColour(ef.ink, el.color)) out.push(['form-field-ink', at(`color ${el.color}, expected ${ef.ink}`)]);
  if (!f.placeholder || !sameColour(ef.placeholder, f.placeholder.color)) out.push(['form-field-placeholder', at(`::placeholder color ${f.placeholder ? f.placeholder.color : 'not measured'}, expected ${ef.placeholder}`)]);
  ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'].forEach((k, i) => { if (!near(px(el[k]), ef.padding[i])) out.push(['form-field-padding', at(`${k} ${el[k]}, expected ${ef.padding[i]}px`)]); });
  return out;
}

/** form-container: the form's own container carries nothing of the kit (the kit writes no module.decoration): no shadow, no movement, no frame. */
function formContainerProblems(c, state) {
  const out = [];
  const el = c.el;
  if (parseShadows(el.boxShadow).some((s) => !s.inset)) out.push(['form-container', `${state}: the form container has a box-shadow (${el.boxShadow}) but the kit writes none on a form`]);
  if (Math.abs(translateY(el.transform)) > 0.1 || !sameTransform(el.transform, 'none')) out.push(['form-container', `${state}: the form container is transformed (${el.transform}) but the kit writes no lift on a form`]);
  for (const [side, cap] of SIDES) if (px(el[`border${cap}Width`]) > 0) out.push(['form-container', `${state}: the form container has a ${el[`border${cap}Width`]} ${side} border but the kit writes no frame on a form`]);
  return out;
}

/** form-captcha: the captcha question (and its row, and a label in it) is printed in the band's ink the markup says. */
function formCaptchaProblems(exp, cap, phase = 'rest') {
  if (!cap || !cap.found) return [['form-captcha', `${phase}: the captcha question is not on the page`]];
  const out = [];
  const check = (name, got) => { if (!got || !sameColour(exp.captcha.ink, got.color)) out.push(['form-captcha', `${phase}: captcha ${name} colour ${got ? got.color : 'not measured'}, expected ${exp.captcha.ink}`]); };
  check('question', cap.question);
  check('row', cap.row);
  if (cap.label) check('label', cap.label);
  return out;
}

/** Every disagreement ([code, detail]) of one measured form at rest with its expectation, except the submit (formButtonProblems) and the painted values (formPaintedFillProblems, formContrastProblems): every field, the captcha and the container. */
function compareForm(exp, m, phase = 'rest') {
  const miss = missingFormExpectations(exp);
  if (miss.length) return [['form-expectation', 'expectation missing (an adapter attribute path drifted): ' + miss.join(', ')]];
  const out = [];
  m.fields.forEach((f, j) => out.push(...compareFormField(exp.field, f, `field ${j + 1}`, phase)));
  out.push(...formCaptchaProblems(exp, m.captcha, phase));
  out.push(...formContainerProblems(m.container, phase));
  return out;
}

/**
 * form-button: the submit button against the kit's primary control (compareButton: border top and bottom, radius, fill, label, text, padding,
 * shadow, lift, and on a real hover the fill, label, edge, shadow and lift), plus the left and right edges (the underline form has none), and, on an
 * underline submit, the bottom edge keeping its REST colour on hover. rest/hover = { el, wrap } (hover null = not measured: a failure).
 */
function formButtonProblems(exp, rest, hover) {
  const out = [];
  for (const [code, d] of compareButton(exp, { rest, hover }).problems) out.push(['form-button', code === 'hover' ? `submit ${d}` : `submit ${code}: ${d}`]);
  const w = exp.rest.top.width;
  for (const [side, cap] of [['left', 'Left'], ['right', 'Right']]) {
    if (rest.el[`border${cap}Width`] !== undefined && !near(px(rest.el[`border${cap}Width`]), w, BORDER_TOLERANCE)) out.push(['form-button', `submit border: rest: border-${side}-width ${rest.el[`border${cap}Width`]}, expected ${w}px`]);
  }
  if (!hover) out.push(['form-button', 'submit hover: the hover state was not measured']);
  else if (underlineSubmit(exp) && !sameColour(exp.rest.bottom.color, hover.el.borderBottomColor)) out.push(['form-button', `submit hover: the underline's bottom edge is ${hover.el.borderBottomColor} on hover, expected its rest colour ${exp.rest.bottom.color}`]);
  return out;
}

/** form-field-fill from the PAINTED fill (screenshot mean of the field's inner box, placeholder hidden): an opaque computed fill must be what is painted (within 4 per channel), and the field must not be the colour of what is under it (surfaces.fields[j]). A missing sample fails. */
function formPaintedFillProblems(m, fills, surfaces) {
  const out = [];
  m.fields.forEach((f, j) => {
    const name = `field ${j + 1}`;
    const fill = fills && fills[j];
    if (!fill) { out.push(['form-field-fill', `${name}: the painted field fill was not sampled`]); return; }
    for (const [, d] of paintedFillProblems(f.el, fill)) out.push(['form-field-fill', `${name}: ${d.replace('over the card', 'over the field')}`]);
    const surf = surfaces && surfaces.fields && surfaces.fields[j];
    if (surf && Math.max(Math.abs(surf.r - fill.r), Math.abs(surf.g - fill.g), Math.abs(surf.b - fill.b)) <= 4) out.push(['form-field-fill', `${name}: the field fill ${hex(fill)} is the colour of the form behind it ${hex(surf)} (a field never matches its container)`]);
  });
  return out;
}

/**
 * form-contrast, on the MEASURED surfaces (screenshots with the element hidden): each field's ink and placeholder against the painted field
 * fill >= 4.5, each drawn frame side >= 3.0 against the painted fill (strict, no exemption), the field's BOUNDARY against the form behind it >= 3.0 from the
 * BETTER of the frame (best drawn side) and the fill (Ruling 6, WCAG 1.4.11: either edge identifies the control; a strict "every side AND the fill" reading is
 * unsatisfiable, a white field on a dark band needs a frame between the two), the submit (contrastProblems: the label against
 * its fill >= 4.5, a transparent fill's drawn edges >= 3.0 against the surface) at rest and on hover, and the captcha question against the surface >= 4.5.
 * A missing sample, an unreadable colour or a missing hover is a failure, never a skip. fills = per field, surfaces = { fields, submit, captcha }.
 */
function formContrastProblems(exp, m, fills, surfaces, hoverEl) {
  const out = []; const info = { fields: [] };
  const ratio = (raw, bg) => { const c = colour(raw); return c ? round2(ratioOf(over(c, bg), bg)) : null; };
  const check = (name, raw, bg, min) => {
    const r = ratio(raw, bg);
    if (r === null) { out.push(['form-contrast', `${name}: no readable colour (${raw})`]); return null; }
    if (r < min) out.push(['form-contrast', `${name} ${hex(over(colour(raw), bg))} on ${hex(bg)} is ${r}:1, needs ${min}`]);
    return r;
  };
  m.fields.forEach((f, j) => {
    const name = `field ${j + 1}`;
    const fill = fills && fills[j]; const surf = surfaces && surfaces.fields && surfaces.fields[j];
    const row = {};
    if (!fill) out.push(['form-contrast', `${name}: the painted field fill was not sampled`]);
    else {
      const base = { r: fill.r, g: fill.g, b: fill.b, a: 1 };
      row.ink = check(`${name} ink`, f.el.color, base, CONTRAST_LABEL);
      row.placeholder = check(`${name} placeholder`, f.placeholder ? f.placeholder.color : null, base, CONTRAST_LABEL);
      const sb = surf ? { r: surf.r, g: surf.g, b: surf.b, a: 1 } : null;
      if (!sb) out.push(['form-contrast', `${name}: the form behind the field was not sampled`]);
      let bestEdge = 0;
      for (const [side, cap] of SIDES) {
        if (!(px(f.el[`border${cap}Width`]) > 0)) continue;
        const raw = f.el[`border${cap}Color`];
        row[side] = check(`${name} frame ${side} against the field fill`, raw, base, CONTRAST_SHAPE);
        if (sb) {
          const c = colour(raw);
          row[side + 'OnForm'] = c ? ratio(raw, sb) : null;
          if (c) bestEdge = Math.max(bestEdge, ratio(raw, sb));
        }
      }
      // The boundary against the form behind: the better of the frame and the fill (Ruling 6).
      if (sb) {
        row.fillOnForm = round2(ratioOf(base, sb));
        row.boundary = round2(Math.max(bestEdge, row.fillOnForm));
        if (row.boundary < CONTRAST_SHAPE) out.push(['form-contrast', `${name} boundary: neither the frame (best ${bestEdge > 0 ? bestEdge : 'none drawn'}:1) nor the fill ${hex(base)} (${row.fillOnForm}:1) reaches ${CONTRAST_SHAPE} on the form behind it ${hex(sb)}`]);
      }
    }
    info.fields.push(row);
  });
  if (!surfaces || !surfaces.submit) out.push(['form-contrast', 'the form behind the submit button was not sampled']);
  else {
    const rest = contrastProblems(m.submit.el, surfaces.submit, 'rest');
    for (const [, d] of rest.problems) out.push(['form-contrast', 'submit ' + d]);
    info.submit = { rest: rest.info };
    if (!hoverEl) out.push(['form-contrast', 'submit hover: the hover state was not measured']);
    else {
      const hov = contrastProblems(hoverEl, surfaces.submit, 'hover');
      for (const [, d] of hov.problems) out.push(['form-contrast', 'submit ' + d]);
      info.submit.hover = hov.info;
    }
  }
  if (!m.captcha || !m.captcha.found) out.push(['form-contrast', 'the captcha question is not on the page']);
  else if (!surfaces || !surfaces.captcha) out.push(['form-contrast', 'the form behind the captcha was not sampled']);
  else info.captcha = check('captcha ink', m.captcha.question.color, { r: surfaces.captcha.r, g: surfaces.captcha.g, b: surfaces.captcha.b, a: 1 }, CONTRAST_LABEL);
  return { problems: out, info };
}

/** form-clip: when the form container clips (overflow not visible), no field or submit may stick out of it; the submit's label must not be cut (scrollWidth within clientWidth). */
function formClipProblems(m, state = 'rest') {
  const out = [];
  const c = m.container;
  const clips = c.el.overflowX !== 'visible' || c.el.overflowY !== 'visible';
  const parts = [...m.fields.map((f, j) => [`field ${j + 1}`, f.rect]), ['submit', m.submit.rect]];
  if (clips) for (const [name, r] of parts) if (r.left < c.rect.left - 1 || r.right > c.rect.right + 1 || r.top < c.rect.top - 1 || r.bottom > c.rect.bottom + 1) out.push(['form-clip', `${state}: ${name} ${Math.round(r.left)}..${Math.round(r.right)} x ${Math.round(r.top)}..${Math.round(r.bottom)} is cut by the form container (overflow ${c.el.overflowX}/${c.el.overflowY})`]);
  if (m.submit.scrollWidth > m.submit.clientWidth + 1) out.push(['form-clip', `${state}: the submit label is cut (scrollWidth ${m.submit.scrollWidth} > clientWidth ${m.submit.clientWidth})`]);
  return out;
}

/** Overflow of one measured form: a field, the submit or the captcha beyond the form container (1px) or the window, or the page wider than the window. */
function formOverflowProblems(m, state, page) {
  const out = [];
  const c = m.container.rect;
  const parts = [...m.fields.map((f, j) => [`field ${j + 1}`, f.rect]), ['submit', m.submit.rect]];
  if (m.captcha && m.captcha.found && m.captcha.rect) parts.push(['captcha', m.captcha.rect]);
  for (const [name, r] of parts) {
    if (r.left < c.left - 1 || r.right > c.right + 1) out.push(['overflow', `${state}: ${name} ${Math.round(r.left)}..${Math.round(r.right)} outside its form ${Math.round(c.left)}..${Math.round(c.right)}`]);
    if (r.right > page.innerWidth + 1 || r.left < -1) out.push(['overflow', `${state}: ${name} ${Math.round(r.left)}..${Math.round(r.right)} outside the window (${page.innerWidth})`]);
  }
  if (page.scrollWidth > page.innerWidth) out.push(['overflow', `${state}: scrollWidth ${page.scrollWidth} > innerWidth ${page.innerWidth}`]);
  return out;
}

/**
 * Problems of the form count (code form): every sheet form on the page, with exactly the fields the resolved markup gives it, ONE submit
 * button and the captcha question. measured = measureForms(...).forms in FORM_SLOTS order; counts = the field counts of the resolved markup.
 * An empty slot list is an error.
 */
function formCountProblems(measured, counts, slots = FORM_SLOTS) {
  if (!slots.length) throw new Error('no form slots: nothing would be checked');
  const bad = [];
  slots.forEach((slot, i) => {
    const mm = measured[i];
    if (!mm || !mm.found) { bad.push(`${slot.key} is not in the page`); return; }
    if (mm.submitCount !== 1) bad.push(`${slot.key} has ${mm.submitCount} submit buttons with its label, expected 1`);
    if (mm.fields.length !== counts[i]) bad.push(`${slot.key} renders ${mm.fields.length} fields, expected ${counts[i]}`);
    if (!mm.captcha || !mm.captcha.found) bad.push(`${slot.key} shows no captcha question`);
  });
  if (measured.length > slots.length) bad.push(`${measured.length} forms measured for ${slots.length} slots`);
  if (!bad.length) return [];
  const found = slots.filter((s, i) => measured[i] && measured[i].found).length;
  return [['form', `${found} of ${slots.length} forms rendered as the sheet says: ${bad.slice(0, 6).join('; ')}${bad.length > 6 ? ` and ${bad.length - 6} more` : ''}`]];
}

/** What a finished run must have checked as to forms: at least one (an empty selection fails the run with the code form; the older emptyRunProblems keeps its own three checks). */
function emptyFormRunProblems({ formsChecked }) {
  return formsChecked ? [] : ['no form was checked: the form selection was empty'];
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
  // The page-level buttons only: the kit buttons inside a button panel (a group) are measured with their card (measureCards).
  const buttons = root ? [...root.querySelectorAll('a.et_pb_button')].filter((a) => !a.closest('.et_pb_group')) : [];
  buttons.forEach((a, i) => a.setAttribute('data-aied-btn', String(i)));
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
 * the rect of its first image, and `buttons` (the kit buttons inside it: computed fields of the button and its wrapper, its rect, the
 * card's rect). A card that is not in the page is { key, found: false }.
 */
function measureCards(slots) {
  const root = document.querySelector('.et-l--post');
  const F = ['borderTopWidth', 'borderTopStyle', 'borderTopColor', 'borderRightWidth', 'borderRightStyle', 'borderRightColor', 'borderBottomWidth', 'borderBottomStyle', 'borderBottomColor', 'borderLeftWidth', 'borderLeftStyle', 'borderLeftColor', 'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius', 'backgroundColor', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'boxShadow', 'transform', 'overflowX', 'overflowY', 'transitionDuration', 'transitionDelay'];
  const pick = (node) => { const cs = getComputedStyle(node); const o = {}; for (const k of F) o[k] = cs[k]; return o; };
  const box = (n) => { const r = n.getBoundingClientRect(); return { left: r.left + window.scrollX, right: r.right + window.scrollX, top: r.top + window.scrollY, bottom: r.bottom + window.scrollY, width: r.width, height: r.height }; };
  const BF = ['borderTopWidth', 'borderTopStyle', 'borderTopColor', 'borderBottomWidth', 'borderBottomStyle', 'borderBottomColor', 'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius', 'backgroundColor', 'color', 'textTransform', 'letterSpacing', 'fontWeight', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'boxShadow', 'transform', 'transitionDuration', 'transitionDelay'];
  const pickB = (node) => { const cs = getComputedStyle(node); const o = {}; for (const k of BF) o[k] = cs[k]; return o; };
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
        // The kit buttons inside the card (a button panel), tagged data-aied-pb="<key>:<n>" so the script can hover each one.
        buttons: [...card.querySelectorAll('a.et_pb_button')].map((a, j) => {
          a.setAttribute('data-aied-pb', s.key + ':' + j);
          const wrap = a.closest('.et_pb_button_module_wrapper') || a.parentElement;
          return { el: pickB(a), wrap: pickB(wrap), rect: box(a), parentRect: box(card) };
        }),
      };
    }),
  };
}

/**
 * Runs IN THE PAGE (self-contained). For each slot ({ key, carrier, titles }) the rendered item block: found by the `.et_pb_toggle_title`
 * that holds the slot's first title (an accordion = the `.et_pb_accordion` around it, its items the `.et_pb_toggle` children; a toggle = the
 * `.et_pb_toggle` itself), every item tagged `data-aied-item="<key>:<n>"` so the script can click it, with the computed fields compareItem
 * and itemContrastProblems read: open / closed (Divi's `et_pb_toggle_open` class), the item's own style, the title colour, the ::before
 * icon (colour, display, content) and the answer (the colour of its first paragraph, and whether it is shown).
 */
function measureItems(slots) {
  const root = document.querySelector('.et-l--post');
  const F = ['borderTopWidth', 'borderTopStyle', 'borderTopColor', 'borderRightWidth', 'borderRightStyle', 'borderRightColor', 'borderBottomWidth', 'borderBottomStyle', 'borderBottomColor', 'borderLeftWidth', 'borderLeftStyle', 'borderLeftColor', 'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius', 'backgroundColor', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'boxShadow', 'transform', 'overflowX', 'overflowY', 'transitionDuration', 'transitionDelay'];
  const pick = (node) => { const cs = getComputedStyle(node); const o = {}; for (const k of F) o[k] = cs[k]; return o; };
  const box = (n) => { const r = n.getBoundingClientRect(); return { left: r.left + window.scrollX, right: r.right + window.scrollX, top: r.top + window.scrollY, bottom: r.bottom + window.scrollY, width: r.width, height: r.height }; };
  const titles = root ? [...root.querySelectorAll('.et_pb_toggle_title')] : [];
  return {
    hasRoot: !!root,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    items: slots.map((s) => {
      const first = titles.find((n) => (n.textContent || '').trim() === s.titles[0]);
      const firstItem = first ? first.closest('.et_pb_toggle') : null;
      if (!firstItem) return { key: s.key, found: false };
      const acc = firstItem.closest('.et_pb_accordion');
      const els = acc ? [...acc.querySelectorAll('.et_pb_toggle')] : [firstItem];
      return {
        key: s.key, found: true, accordion: !!acc, count: els.length,
        items: els.map((it, j) => {
          it.setAttribute('data-aied-item', s.key + ':' + j);
          const title = it.querySelector('.et_pb_toggle_title');
          const content = it.querySelector('.et_pb_toggle_content');
          const p = content ? content.querySelector('p') : null;
          const before = title ? getComputedStyle(title, '::before') : null;
          const cs = content ? getComputedStyle(content) : null;
          const cr = content ? content.getBoundingClientRect() : null;
          const container = it.closest('.et_pb_column') || it.parentElement;
          return {
            open: it.classList.contains('et_pb_toggle_open'), text: title ? (title.textContent || '').trim() : '',
            el: pick(it), rect: box(it), parentRect: box(container),
            title: title ? { color: getComputedStyle(title).color } : null,
            icon: before ? { color: before.color, display: before.display, content: before.content, shown: before.display !== 'none' && before.content !== 'none' && before.content !== 'normal' } : null,
            body: content ? { color: getComputedStyle(p || content).color, contentColor: cs.color, visible: cs.display !== 'none' && cs.visibility !== 'hidden' && cr.height > 0 } : { color: null, contentColor: null, visible: false },
          };
        }),
      };
    }),
  };
}

/**
 * Runs IN THE PAGE (self-contained). For each slot ({ key, submit }) the rendered contact form: found by the `.et_pb_contact_submit` that holds
 * the slot's submit text (`submitCount` = how many buttons carry it), its `<form>` and its container (`.et_pb_contact_form_container`, the
 * element the form's module.decoration would land on). Fields = the visible text-like inputs of the form except the captcha's, each with its
 * computed fields and `getComputedStyle(el, '::placeholder').color`, tagged `data-aied-ff="<key>:<n>"`; the submit button (and its wrapper)
 * tagged `data-aied-fs="<key>"` so the script can hover it; the captcha question, its row and a label in it, the row tagged `data-aied-fc="<key>"`.
 */
function measureForms(slots) {
  const root = document.querySelector('.et-l--post');
  const FF = ['borderTopWidth', 'borderTopStyle', 'borderTopColor', 'borderRightWidth', 'borderRightStyle', 'borderRightColor', 'borderBottomWidth', 'borderBottomStyle', 'borderBottomColor', 'borderLeftWidth', 'borderLeftStyle', 'borderLeftColor', 'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius', 'backgroundColor', 'color', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'boxShadow', 'transform', 'overflowX', 'overflowY', 'transitionDuration', 'transitionDelay'];
  const BF = ['borderTopWidth', 'borderTopStyle', 'borderTopColor', 'borderBottomWidth', 'borderBottomStyle', 'borderBottomColor', 'borderLeftWidth', 'borderRightWidth', 'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius', 'backgroundColor', 'color', 'textTransform', 'letterSpacing', 'fontWeight', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'boxShadow', 'transform', 'transitionDuration', 'transitionDelay'];
  const pick = (node, list) => { const cs = getComputedStyle(node); const o = {}; for (const k of list) o[k] = cs[k]; return o; };
  const box = (n) => { const r = n.getBoundingClientRect(); return { left: r.left + window.scrollX, right: r.right + window.scrollX, top: r.top + window.scrollY, bottom: r.bottom + window.scrollY, width: r.width, height: r.height }; };
  const submits = root ? [...root.querySelectorAll('.et_pb_contact_submit')] : [];
  return {
    hasRoot: !!root,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    forms: slots.map((s) => {
      const hits = submits.filter((b) => (b.textContent || '').trim() === s.submit);
      const btn = hits[0];
      if (!btn) return { key: s.key, found: false, submitCount: 0 };
      const form = btn.closest('form');
      const container = btn.closest('.et_pb_contact_form_container') || (form && form.parentElement) || btn.parentElement;
      const wrap = btn.parentElement;
      const inputs = form ? [...form.querySelectorAll('input, textarea, select')].filter((n) => {
        const type = (n.getAttribute('type') || '').toLowerCase();
        if (['hidden', 'checkbox', 'radio', 'submit', 'button'].includes(type) || n.classList.contains('et_pb_contact_captcha')) return false;
        const r = n.getBoundingClientRect();
        return getComputedStyle(n).display !== 'none' && r.width > 0 && r.height > 0;
      }) : [];
      btn.setAttribute('data-aied-fs', s.key);
      const q = form ? form.querySelector('.et_pb_contact_captcha_question') : null;
      const row = q ? (q.closest('p') || q.parentElement) : null;
      const label = row ? row.querySelector('label') : null;
      if (row) row.setAttribute('data-aied-fc', s.key);
      return {
        key: s.key, found: true, submitCount: hits.length, rect: box(container),
        container: { el: pick(container, FF), rect: box(container) },
        fields: inputs.map((n, j) => {
          n.setAttribute('data-aied-ff', s.key + ':' + j);
          return { el: pick(n, FF), placeholder: { color: getComputedStyle(n, '::placeholder').color }, rect: box(n) };
        }),
        submit: { el: pick(btn, BF), wrap: pick(wrap, BF), rect: box(btn), parentRect: box(container), scrollWidth: btn.scrollWidth, clientWidth: btn.clientWidth },
        captcha: q ? { found: true, question: { color: getComputedStyle(q).color }, row: { color: getComputedStyle(row).color }, label: label ? { color: getComputedStyle(label).color } : null, rect: box(row) } : { found: false },
      };
    }),
  };
}

module.exports = {
  SURFACES, ROLES, SLOTS, sheetMarkup, TITLE_PREFIX, runTitle, parseRunTitle, selectCells, buttonAttrs, expectFromAttrs, missingExpectations, BORDER_TOLERANCE,
  colour, sameColour, parseShadows, translateY, matrix2d, sameTransform, shadowProblems, compareButton, contrastProblems, overflowProblems,
  CONTRAST_LABEL, CONTRAST_SHAPE, decodePng, pixelStats, measureButtons, hex, round2,
  CARD_ON, CARD_SLOTS, cardMarkup, blockTree, cardAttrs, expectCardFromAttrs, missingCardExpectations, compareCard, cardClipProblems, cardContrastProblems,
  paintedFillProblems, cardOverflowProblems, columnRowProblems, cardCountProblems, cardSampleClip, emptyRunProblems, measureCards,
  pageButtonAttrs, groundFillProblems, FEATURED_RATIO, boxProminence, featuredProblems, panelButtonProblems, PANEL_BUTTONS,
  ITEM_SLOTS, itemMarkup, itemAttrs, expectItemFromAttrs, missingItemExpectations, compareItem, itemCountProblems, clickTarget, itemClickProblems,
  itemContrastProblems, itemPaintedFillProblems, itemOverflowProblems, measureItems,
  FORM_SLOTS, formMarkup, formAttrs, expectFormFromAttrs, missingFormExpectations, compareFormField, compareForm, formContainerProblems, formCaptchaProblems,
  formButtonProblems, formPaintedFillProblems, formContrastProblems, formClipProblems, formOverflowProblems, formCountProblems, emptyFormRunProblems, measureForms,
};
