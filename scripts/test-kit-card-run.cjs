#!/usr/bin/env node
'use strict';
/* Live proof (a real Chromium, SYNTHETIC pages, NOT the dev site) of the card and item halves of scripts/verify-kits.cjs: scripts/lib/kit-card-run.cjs
 * measuring a page, hovering with the real pointer, sampling the painted fill and judging it with scripts/lib/kit-checks.cjs, and
 * scripts/lib/kit-item-run.cjs measuring the accordions and toggles, clicking an item's title with the real pointer and judging the result.
 * The pages carry the class names Divi 5.15.1 renders (`et_pb_row` > `et_pb_column` > `et_pb_group`, `et_pb_heading`, `et_pb_text`,
 * `et_pb_image`) and a stylesheet GENERATED from the expectations the real PHP pipeline gives for soft-lift under editorial, so a page that
 * agrees with the kit passes, and each deliberate departure (a wrong radius, a panel that lifts, a missing card, pale text, an unclipped
 * image, columns that do not stack) is caught with the right code. The item pages carry the class names Divi 5.15.1's module.json selectors
 * name (`et_pb_accordion`, `et_pb_toggle` + `et_pb_accordion_item`, `et_pb_toggle_open|close`, `et_pb_toggle_title` with a ::before icon,
 * `et_pb_toggle_content`) and a few lines of script that open and close them on a click; each item code has its own departure, including a
 * click that opens nothing and a state fill that stays closed after the click. The form pages carry the class names Divi 5.15.1 renders for a contact
 * form (`et_pb_contact_form_container` > `form` > `p.et_pb_contact_field` > `input`, `.et_pb_contact_captcha_question`, `button.et_pb_contact_submit`) and a
 * stylesheet generated from the form expectations (soft-lift and underline-link under editorial); each form code has its own departure, including a
 * placeholder colour, a painted-only overlay on a field, a hover that moves the container, a submit that cannot be hovered and a fixed element over a field.
 * The image page carries the class names Divi 5.15.1 renders for `divi/image` (`et_pb_module et_pb_image` > `span.et_pb_image_wrap` > `img`, the radius and overflow:hidden on the wrapper and
 * the img inheriting it, ImageModule.php); each image code has its own departure (a wrong or a missing corner, a stray radius on a square kit, a wrapper that does not clip,
 * a rounded picture cut square by a smaller wrapper, a picture sticking out, a module box that is rounded, an image wider than the phone, a missing or doubled image).
 * The rule and chip pages carry the class names Divi 5.15.1 renders (`et_pb_group` > `et_pb_heading` + `et_pb_divider` whose line is the `::before`, a group or column's
 * own `border-top`, `et_pb_text` in a tag group, `et_pb_icon` > `span.et-pb-icon`) and a stylesheet generated from the rule and chip expectations (soft-lift and
 * underline-link under editorial); each rule and chip code has its own departure (a dashed line where the kit says solid, a 1px line where it says 2px, a wrong
 * colour, a stray side, a faint quiet line, a strong line at 2.5:1, a rounded chip on a 0px kit, a missing tag frame, a filled tag, an icon fill equal to the
 * band, a glyph equal to its fill, a fill painted over, a slot that is not on the page, a rule or chip wider than the phone).
 * Needs php (the offline sheet) and Playwright (../layoutlab).
 * It cannot prove what Divi itself prints: that is `node scripts/verify-kits.cjs`. */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const K = require('./lib/kit-checks.cjs');
const { checkCards } = require('./lib/kit-card-run.cjs');
const { checkItems } = require('./lib/kit-item-run.cjs');
const { checkForms } = require('./lib/kit-form-run.cjs');
const { checkMedia } = require('./lib/kit-media-run.cjs');
const { checkRules, checkChips } = require('./lib/kit-rule-run.cjs');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(__dirname, '..', '..', 'layoutlab', 'node_modules', 'playwright');
const { chromium } = require(PW);

const php = spawnSync('php', [path.join(__dirname, 'lib', 'kit-sheet-offline.php'), '--kit', 'soft-lift', '--language', 'editorial'], { input: K.sheetMarkup(), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
assert.strictEqual(php.status, 0, 'php failed: ' + (php.error ? php.error.message : php.stderr));
const row = JSON.parse(php.stdout)[0];
const sheet = K.cardAttrs(row.markup);
const expects = sheet.map((c) => K.expectCardFromAttrs(c.attrs));
const buttonExpects = sheet.map((c) => c.buttons.map(K.expectFromAttrs));
/** Development switch: AIED_ITEMS_ONLY=1 runs only the item half (the card half alone takes about four minutes). The full run (no variable) is the one that counts. */
const ITEMS_ONLY = process.env.AIED_ITEMS_ONLY === '1';
/** Development switch: AIED_MEDIA_ONLY=1 runs only the image half (seconds, not minutes). The full run (no variable) is the one that counts. */
const MEDIA_ONLY = process.env.AIED_MEDIA_ONLY === '1';
const N = K.CARD_SLOTS.length;
const itemSheet = K.itemAttrs(row.markup);
const clone = (o) => JSON.parse(JSON.stringify(o));
/** The expectations the item pages are built from: the real ones, but with an open state that differs from the closed one (fill, title, toggle icon), so a state that does not switch is visible. */
const itemExpects = itemSheet.map((h) => { const e = K.expectItemFromAttrs(h.attrs, h.slot.carrier); e.open.fill = '#fff3d6'; e.open.title = '#3a1a00'; if (e.open.icon !== null) e.open.icon = '#7a3b00'; return e; });
const itemLists = itemSheet.map((h) => h.items);
const NI = K.ITEM_SLOTS.length;
const offline = (kit) => {
  const r = spawnSync('php', [path.join(__dirname, 'lib', 'kit-sheet-offline.php'), '--kit', kit, '--language', 'editorial'], { input: K.sheetMarkup(), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  assert.strictEqual(r.status, 0, 'php failed: ' + (r.error ? r.error.message : r.stderr));
  return JSON.parse(r.stdout)[0];
};
const EDITORIAL = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'wp-plugin', 'data', 'design-languages.json'), 'utf8')).find((l) => l.id === 'editorial').colors;
const formSheet = K.formAttrs(row.markup);
const formExpects = formSheet.map((f) => K.expectFormFromAttrs(f.attrs));
const formCounts = formSheet.map((f) => f.fields);
const underSheet = K.formAttrs(offline('underline-link').markup);
const underExpects = underSheet.map((f) => K.expectFormFromAttrs(f.attrs));
const NF = K.FORM_SLOTS.length;
const mediaSheet = K.mediaAttrs(row.markup);
const mediaExpects = mediaSheet.map((m) => K.expectMediaFromAttrs(m.attrs));
const squareExpects = K.mediaAttrs(offline('underline-link').markup).map((m) => K.expectMediaFromAttrs(m.attrs));
const NM = K.MEDIA_SLOTS.length;
/** Development switch: AIED_RULES_ONLY=1 runs only the rule and chip half (seconds, not minutes). The full run (no variable) is the one that counts. */
const RULES_ONLY = process.env.AIED_RULES_ONLY === '1';
const ruleSheet = K.ruleAttrs(row.markup);
const ruleExpects = ruleSheet.map((r) => K.expectRuleFromAttrs(r.attrs, r.slot.carrier));
const chipSheet = K.chipAttrs(row.markup);
const chipExpects = chipSheet.map((c) => K.expectChipFromAttrs(c.attrs, c.slot.carrier));
const squareChipExpects = K.chipAttrs(offline('underline-link').markup).map((c) => K.expectChipFromAttrs(c.attrs, c.slot.carrier));
const NR = K.RULE_SLOTS.length;
const NC = K.CHIP_SLOTS.length;
const HAIRLINE = '#e3dccf';
const IMG = "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='9'><rect width='16' height='9' fill='%23336699'/></svg>";

const shadowCss = (s) => (s ? `${s.color} ${s.x || 0}px ${s.y || 0}px ${s.blur}px 0px` : 'none');
/** The stylesheet that makes a synthetic card agree with its expectation (what Divi would print for the resolved markup). */
function cssFor(slot, e) {
  const sel = `[data-k="${slot.key}"]`;
  const sides = ['top', 'right', 'bottom', 'left'].map((k) => `border-${k}:${e.rest[k].width}px ${e.rest[k].style} ${e.rest[k].color};`).join('');
  const r = e.rest.radius.map((v) => v + 'px').join(' ');
  let css = `${sel}{box-sizing:border-box;background:${e.rest.fill};${sides}border-radius:${r};padding:${e.rest.padding.map((v) => v + 'px').join(' ')};box-shadow:${shadowCss(e.shadow.rest)};${Math.max(...e.rest.radius) > 0 ? 'overflow:hidden;' : ''}transition:none}`;
  if (slot.role === 'card') {
    const hov = [];
    if (e.lift !== null) hov.push(`transform:translateY(${e.lift}px)`);
    if (e.shadow.hover && e.shadow.hover !== e.shadow.rest) hov.push(`box-shadow:${shadowCss(e.shadow.hover)}`);
    if (e.hoverBorder) hov.push(`border-color:${e.hoverBorder}`);
    if (hov.length) css += `${sel}:hover{${hov.join(';')}}`;
  }
  return css;
}

/** The stylesheet that makes a synthetic button agree with its expectation (soft-lift under editorial, on the inverted panel). */
function buttonCssFor(key, e) {
  const sel = `[data-kb="${key}"]`;
  const side = (k) => `${e.rest[k].width}px ${e.rest[k].style} ${e.rest[k].color}`;
  const r = e.rest.radius.map((v) => v + 'px').join(' ');
  let css = `${sel}{display:inline-block;box-sizing:border-box;background:${e.rest.fill};color:${e.rest.label};border-top:${side('top')};border-bottom:${side('bottom')};border-left:${side('top')};border-right:${side('top')};border-radius:${r};padding:${e.rest.padding.map((v) => v + 'px').join(' ')};text-transform:${e.rest.capitalization};letter-spacing:${e.rest.letterSpacing}px;font-weight:${e.rest.weight};box-shadow:${shadowCss(e.shadow.rest)};transition:none;text-decoration:none}`;
  const hov = [`background:${e.hover.fill}`, `color:${e.hover.label}`, `border-color:${e.hover.border}`];
  if (e.lift !== null) hov.push(`transform:translateY(${e.lift}px)`);
  if (e.shadow.hover && e.shadow.hover !== e.shadow.rest) hov.push(`box-shadow:${shadowCss(e.shadow.hover)}`);
  return css + `${sel}:hover{${hov.join(';')}}`;
}

/** The page: five bands as Divi nests them; `tweak(slot, html)` / `extraCss` apply a deliberate departure. */
function pageHtml({ extraCss = '', tweak = (s, h) => h, drop = [], textColor = null, paraColor = null } = {}) {
  const card = (slot) => {
    const idx = K.CARD_SLOTS.indexOf(slot);
    const e = expects[idx];
    const inks = sheet[idx].inks;
    const heading = textColor || inks.heading; const para = paraColor || inks.paragraph;
    const buttons = buttonExpects[idx].map((be, j) => `<div class="et_pb_module et_pb_button_module_wrapper"><a class="et_pb_button" data-kb="${slot.key}:${j}" href="#">Action ${j}</a></div>`).join('');
    const body = `${slot.image ? `<div class="et_pb_module et_pb_image"><span class="et_pb_image_wrap"><img src="${IMG}" style="display:block;width:100%;aspect-ratio:16/9"></span></div>` : ''}<div class="et_pb_module et_pb_heading"><div class="et_pb_heading_container"><h3 style="margin:0;color:${heading}">${slot.heading}</h3></div></div><div class="et_pb_module et_pb_text"><div class="et_pb_text_inner"><p style="margin:0;color:${para}">Placeholder copy for a card.</p></div></div>${buttons}`;
    const cls = slot.carrier === 'column' ? 'et_pb_column' : 'et_pb_module et_pb_group';
    return { css: cssFor(slot, e) + buttonExpects[idx].map((be, j) => buttonCssFor(`${slot.key}:${j}`, be)).join(''), html: tweak(slot, `<div class="${cls}" data-k="${slot.key}" style="display:block">${body}</div>`) };
  };
  const live = K.CARD_SLOTS.filter((s) => !drop.includes(s.key));
  const cards = live.map(card);
  const inRow = (s) => cards[live.indexOf(s)].html;
  const groups = live.filter((s) => !s.row).map((s) => `<div class="et_pb_row"><div class="et_pb_column" style="display:block;margin-bottom:24px">${inRow(s)}</div></div>`).join('');
  const cols = live.filter((s) => s.row).map(inRow).join('');
  return `<style>body{margin:0;background:#faf7f2}.et_pb_row{width:90%;margin:0 auto;padding:16px 0}.et-l--post .three{display:flex;gap:24px}.three>[data-k]{flex:1 1 0;min-width:0}@media (max-width:600px){.et-l--post .three{display:block}.three>[data-k]{margin-bottom:24px}}${cards.map((c) => c.css).join('')}${extraCss}</style>`
    + `<div class="et-l--post"><div class="et_pb_section">${groups}<div class="et_pb_row"><div class="three">${cols}</div></div></div></div>`;
}

/** The stylesheet that makes a synthetic item block agree with its expectation (what Divi would print for the resolved markup). */
function itemCssFor(slot, e) {
  const key = `[data-s="${slot.key}"]`;
  const sel = slot.carrier === 'accordion' ? `${key} .et_pb_toggle` : key;
  const sides = ['top', 'right', 'bottom', 'left'].map((k) => `border-${k}:${e.rest[k].width}px ${e.rest[k].style} ${e.rest[k].color};`).join('');
  const pad = e.rest.padding || [20, 20, 20, 20];
  let css = `${sel}{box-sizing:border-box;${sides}border-radius:${e.rest.radius.map((v) => v + 'px').join(' ')};padding:${pad.map((v) => v + 'px').join(' ')};box-shadow:${shadowCss(e.shadow)};overflow:hidden;margin-bottom:10px;transition:none}`;
  css += `${sel}.et_pb_toggle_open{background:${e.open.fill}}${sel}.et_pb_toggle_close{background:${e.closed.fill}}`;
  css += `${sel} > .et_pb_toggle_title{position:relative;margin:0;padding:0 40px 0 0;cursor:pointer;font-size:20px}`;
  css += `${sel}.et_pb_toggle_open > .et_pb_toggle_title{color:${e.open.title}}${sel}.et_pb_toggle_close > .et_pb_toggle_title{color:${e.closed.title}}`;
  css += `${sel} > .et_pb_toggle_title::before{position:absolute;right:0;top:0}`;
  css += `${sel}.et_pb_toggle_close > .et_pb_toggle_title::before{content:"+";color:${e.closed.icon}}`;
  css += e.open.icon ? `${sel}.et_pb_toggle_open > .et_pb_toggle_title::before{content:"-";color:${e.open.icon}}` : `${sel}.et_pb_toggle_open > .et_pb_toggle_title::before{display:none}`;
  css += `${sel} .et_pb_toggle_content p{margin:0;padding-top:12px;color:${e.open.body}}${sel}.et_pb_toggle_close .et_pb_toggle_content{display:none}`;
  return css;
}

/**
 * The item page: every ITEM_SLOT as Divi nests it, with a few lines of script that open and close the items on a click (an accordion opens
 * the clicked item and closes the others, a toggle flips). `exps` are the expectations the stylesheet is generated from; `noClick` = slot
 * keys whose click does nothing, `noClose` = accordions that do not close the previously open item, `stuck` = slot keys whose open state
 * keeps the closed fill, `slowMs` delays every class switch, `drop` leaves a block out, `shortAccordion` removes an accordion's last item.
 */
function itemPageHtml({ extraCss = '', exps = itemExpects, noClick = [], noClose = [], stuck = [], slowMs = 0, drop = [], shortAccordion = null } = {}) {
  const live = K.ITEM_SLOTS.filter((s) => !drop.includes(s.key));
  const blocks = live.map((slot) => {
    const items = slot.items.slice(0, shortAccordion === slot.key ? slot.items.length - 1 : slot.items.length);
    const itemHtml = (it, accordion) => `<div class="et_pb_toggle ${accordion ? 'et_pb_accordion_item ' : 'et_pb_module '}${it.open ? 'et_pb_toggle_open' : 'et_pb_toggle_close'}"${accordion ? '' : ` data-s="${slot.key}"`}><h3 class="et_pb_toggle_title">${it.title}</h3><div class="et_pb_toggle_content"><p>Placeholder answer.</p></div></div>`;
    return slot.carrier === 'accordion'
      ? `<div class="et_pb_module et_pb_accordion" data-s="${slot.key}">${items.map((it) => itemHtml(it, true)).join('')}</div>`
      : itemHtml(items[0], false);
  });
  const css = live.map((slot) => itemCssFor(slot, exps[K.ITEM_SLOTS.indexOf(slot)])).join('') + stuck.map((k) => `[data-s="${k}"].stuck,[data-s="${k}"].stuck .et_pb_toggle{background:${exps[K.ITEM_SLOTS.findIndex((x) => x.key === k)].closed.fill} !important}`).join('');
  const script = `(${function (cfg) {
    document.querySelectorAll('.et_pb_toggle_title').forEach((title) => {
      title.addEventListener('click', () => {
        const item = title.closest('.et_pb_toggle');
        const root = item.closest('.et_pb_accordion') || item;
        const key = root.getAttribute('data-s');
        if (cfg.noClick.includes(key)) return;
        const apply = () => {
          const open = item.classList.contains('et_pb_toggle_open');
          if (item.classList.contains('et_pb_accordion_item')) {
            if (!cfg.noClose.includes(key)) root.querySelectorAll('.et_pb_toggle_open').forEach((o) => { o.classList.remove('et_pb_toggle_open'); o.classList.add('et_pb_toggle_close'); });
            item.classList.remove('et_pb_toggle_close'); item.classList.add('et_pb_toggle_open');
          } else {
            item.classList.toggle('et_pb_toggle_open', !open); item.classList.toggle('et_pb_toggle_close', open);
            if (cfg.stuck.includes(key)) item.classList.add('stuck');
          }
        };
        if (cfg.slowMs) setTimeout(apply, cfg.slowMs); else apply();
      });
    });
  }})(${JSON.stringify({ noClick, noClose, stuck, slowMs })})`;
  return `<style>body{margin:0;background:#faf7f2}.et_pb_row{width:90%;margin:0 auto;padding:16px 0}${css}${extraCss}</style>`
    + `<div class="et-l--post"><div class="et_pb_section"><div class="et_pb_row"><div class="et_pb_column" style="display:block">${blocks.join('')}</div></div></div></div><script>${script}</script>`;
}

/** The stylesheet that makes a synthetic contact form agree with its expectation (what Divi would print for the resolved markup). */
function formCssFor(key, e) {
  const sel = `[data-f="${key}"]`;
  const f = e.field; const b = e.button;
  const fsides = ['top', 'right', 'bottom', 'left'].map((k) => `border-${k}:${f[k].width}px ${f[k].style} ${f[k].color};`).join('');
  let css = `${sel} input.input:not(.et_pb_contact_captcha){box-sizing:border-box;display:block;width:100%;margin:0 0 12px;background:${f.fill};color:${f.ink};${fsides}border-radius:${f.radius.map((v) => v + 'px').join(' ')};padding:${f.padding.map((v) => v + 'px').join(' ')};font:16px sans-serif;transition:none}`;
  css += `${sel} input.input::placeholder{color:${f.placeholder};opacity:1}`;
  css += `${sel} p.clearfix,${sel} .et_pb_contact_captcha_question{color:${e.captcha.ink};margin:0;font:16px sans-serif}`;
  const sd = (k) => `${b.rest[k].width}px ${b.rest[k].style} ${b.rest[k].color}`;
  const bw = `${b.rest.top.width}px solid ${b.rest.top.color}`;
  css += `${sel} .et_pb_contact_submit{display:inline-block;box-sizing:border-box;background:${b.rest.fill};color:${b.rest.label};border-top:${sd('top')};border-bottom:${sd('bottom')};border-left:${bw};border-right:${bw};border-radius:${b.rest.radius.map((v) => v + 'px').join(' ')};padding:${b.rest.padding.map((v) => v + 'px').join(' ')};text-transform:${b.rest.capitalization};letter-spacing:${b.rest.letterSpacing}px;font:${b.rest.weight} 16px sans-serif;box-shadow:${shadowCss(b.shadow.rest)};transition:none;cursor:pointer}`;
  const hov = [`background:${b.hover.fill}`, `color:${b.hover.label}`];
  if (b.hover.border) hov.push(`border-color:${b.hover.border}`);
  if (b.lift !== null) hov.push(`transform:translateY(${b.lift}px)`);
  if (b.shadow.hover && b.shadow.hover !== b.shadow.rest) hov.push(`box-shadow:${shadowCss(b.shadow.hover)}`);
  return css + `${sel} .et_pb_contact_submit:hover{${hov.join(';')}}`;
}

/**
 * The form page: every FORM_SLOT as Divi nests it (a section in the band's colour, the container, the form, a `p.et_pb_contact_field` per field, a hidden
 * input, the captcha row and the submit in the bottom container). `exps` are the expectations the stylesheet is generated from; `drop` leaves a form out,
 * `dropField` removes the last field of one form, `noCaptcha` leaves the captcha out of one form.
 */
function formPageHtml({ extraCss = '', exps = formExpects, drop = [], dropField = null, noCaptcha = [] } = {}) {
  const live = K.FORM_SLOTS.filter((s) => !drop.includes(s.key));
  const sections = live.map((slot) => {
    const band = slot.on === 'surface' ? EDITORIAL.surface : EDITORIAL.bg;
    const names = ['Name', 'Email Address'].slice(0, dropField === slot.key ? 1 : 2);
    const fields = names.map((n) => `<p class="et_pb_contact_field et_pb_contact_field_half"><label class="et_pb_contact_form_label" for="${slot.key}-${n}" style="display:none">${n}</label><input type="text" id="${slot.key}-${n}" class="input" placeholder="${n}"></p>`).join('');
    const captcha = noCaptcha.includes(slot.key) ? '' : '<div class="et_pb_contact_right"><p class="clearfix"><label><span class="et_pb_contact_captcha_question">4 + 7</span> =</label> <input type="text" size="2" class="input et_pb_contact_captcha" value=""></p></div>';
    return `<div class="et_pb_section" style="background:${band};padding:40px 0"><div class="et_pb_row"><div class="et_pb_column" style="display:block"><div class="et_pb_module et_pb_contact_form_container" data-f="${slot.key}" style="display:block"><div class="et_pb_contact"><form class="et_pb_contact_form clearfix">${fields}<input type="hidden" name="et_pb_contact_form_submit" value="x"><div class="et_contact_bottom_container" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">${captcha}<button type="submit" class="et_pb_contact_submit et_pb_button">${slot.submit}</button></div></form></div></div></div></div></div>`;
  });
  const css = live.map((slot) => formCssFor(slot.key, exps[K.FORM_SLOTS.indexOf(slot)])).join('');
  return `<style>body{margin:0;background:#faf7f2}.et_pb_row{width:90%;margin:0 auto}${css}${extraCss}</style><div class="et-l--post">${sections.join('')}</div>`;
}

/**
 * The image page: the module Divi nests (`et_pb_image` > `et_pb_image_wrap` > `img`) in a column on the bg band, the radius and overflow:hidden on the
 * wrapper and the img inheriting it (what Divi 5.15.1 prints), generated from the expectation. `drop` removes the image, `dup` renders it twice.
 */
function mediaPageHtml({ exps = mediaExpects, extraCss = '', drop = false, dup = false } = {}) {
  const e = exps[0];
  const r = e.radius.map((v) => v + 'px').join(' ');
  const one = () => `<div class="et_pb_module et_pb_image" data-m="media-bg"><span class="et_pb_image_wrap"><img alt="${K.MEDIA_SLOTS[0].alt}" src="${IMG}"></span></div>`;
  const css = `.et_pb_image{width:50%}.et_pb_image_wrap{display:block;box-sizing:border-box;${Math.max(...e.radius) > 0 ? `overflow:hidden;border-radius:${r};` : ''}}.et_pb_image_wrap img{display:block;width:100%;aspect-ratio:2/1;border-radius:inherit}`;
  return `<style>body{margin:0;background:#faf7f2}.et_pb_row{width:90%;margin:0 auto;padding:16px 0}${css}${extraCss}</style>`
    + `<div class="et-l--post"><div class="et_pb_section"><div class="et_pb_row"><div class="et_pb_column" style="display:block">${drop ? '<p>nothing here</p>' : one() + (dup ? one() : '')}</div></div></div></div>`;
}


/**
 * The rule page: every RULE_SLOT as Divi nests it, on the bg, surface, accent and a dark band (a panel rule inside a `text`-filled wrapper on the bg band), generated from the expectations. A divider is an `.et_pb_divider` inside
 * a wrapper group whose line is the `::before`; a group rule carries its own `border-top`; the column rule is a `.et_pb_column` of its own row. `drop` leaves
 * a slot out, `dup` renders a slot's heading twice, `extra` is HTML added to the page.
 */
function rulePageHtml({ exps = ruleExpects, extraCss = '', drop = [], dup = [], extra = '' } = {}) {
  const colourOf = (c) => c;
  let css = '';
  const piece = (slot, e) => {
    const heading = `<div class="et_pb_module et_pb_heading"><div class="et_pb_heading_container"><h3 style="margin:0;color:#1c1a17">${slot.heading}</h3></div></div>`;
    const line = `${e.top.width}px ${e.top.style} ${colourOf(e.top.color)}`;
    if (slot.carrier === 'divider') {
      css += `[data-r="${slot.key}"]{display:block;height:6px;position:relative}[data-r="${slot.key}"]::before{content:"";display:block;position:absolute;left:0;right:0;top:0;border-top:${line}}`;
      return `<div class="et_pb_module et_pb_group" data-rg="${slot.key}" style="display:block;padding:12px 0">${heading}${dup.includes(slot.key) ? heading : ''}<div class="et_pb_module et_pb_divider" data-r="${slot.key}"></div></div>`;
    }
    css += `[data-r="${slot.key}"]{box-sizing:border-box;display:block;padding:16px 0;border-top:${line}}`;
    const body = `${heading}${dup.includes(slot.key) ? heading : ''}<div class="et_pb_module et_pb_text"><div class="et_pb_text_inner"><p style="margin:0">Placeholder</p></div></div>`;
    return slot.carrier === 'column'
      ? `<div class="et_pb_row"><div class="et_pb_column" data-r="${slot.key}">${body}</div></div>`
      : `<div class="et_pb_module et_pb_group" data-r="${slot.key}">${body}</div>`;
  };
  const live = K.RULE_SLOTS.filter((s) => !drop.includes(s.key));
  const band = (b, bg) => `<div class="et_pb_section" style="background:${bg};padding:24px 0"><div class="et_pb_row"><div class="et_pb_column" style="display:block">${live.filter((s) => s.band === b && s.carrier !== 'column' && !s.panel).map((s) => piece(s, exps[K.RULE_SLOTS.indexOf(s)])).join('')}${live.filter((s) => s.band === b && s.panel).map((s) => `<div style="background:#1c1a17;padding:24px">${piece(s, exps[K.RULE_SLOTS.indexOf(s)])}</div>`).join('')}</div></div>${live.filter((s) => s.band === b && s.carrier === 'column').map((s) => piece(s, exps[K.RULE_SLOTS.indexOf(s)])).join('')}</div>`;
  const body = `${band('bg', EDITORIAL.bg)}${band('surface', EDITORIAL.surface)}${band('accent', EDITORIAL.accent)}${band('dark', '#26211d')}`; // rendered first: building the pieces fills `css`
  return `<style>body{margin:0;background:#faf7f2}.et_pb_row{width:90%;margin:0 auto}${css}${extraCss}</style><div class="et-l--post">${body}</div>${extra}`;
}

/**
 * The chip page (one section per band: bg, surface, accent, dark): a tag (an `.et_pb_group` holding an `.et_pb_text`) and an icon chip (an `.et_pb_icon` module box holding `span.et-pb-icon`, under a wrapper
 * group with its heading), generated from the expectations. `drop` leaves a chip out, `dup` renders it twice.
 */
function chipPageHtml({ exps = chipExpects, extraCss = '', drop = [], dup = [], extra = '' } = {}) {
  let css = '';
  const piece = (slot, e) => {
    const radius = e.radius.map((v) => v + 'px').join(' ');
    if (slot.carrier === 'group') {
      const fr = ['top', 'right', 'bottom', 'left'].map((k) => `border-${k}:${e.frame.width}px ${e.frame.style} ${e.frame.color};`).join('');
      css += `[data-c="${slot.key}"]{box-sizing:border-box;display:inline-block;padding:4px 10px;background:none;border-radius:${radius};${fr}}`;
      const one = `<div class="et_pb_module et_pb_group" data-c="${slot.key}"><div class="et_pb_module et_pb_text"><div class="et_pb_text_inner"><p style="margin:0">${slot.text}</p></div></div></div>`;
      return one + (dup.includes(slot.key) ? one : '');
    }
    css += `[data-c="${slot.key}"]{box-sizing:border-box;display:inline-block;padding:10px;background:${e.fill};border-radius:${radius}}[data-c="${slot.key}"] .et-pb-icon{display:block;width:20px;height:20px;line-height:20px;font-size:16px;color:${e.glyph};background:none}`;
    const one = `<div class="et_pb_module et_pb_icon" data-c="${slot.key}"><div class="et_pb_icon_wrap"><span class="et-pb-icon">&#9733;</span></div></div>`;
    // A doubled icon chip is its whole wrapper (heading and icon) twice: the heading is what the gate counts.
    const wrapper = `<div class="et_pb_module et_pb_group" style="display:block;padding:12px 0"><div class="et_pb_module et_pb_heading"><div class="et_pb_heading_container"><h3 style="margin:0">${slot.heading}</h3></div></div>${one}</div>`;
    return wrapper + (dup.includes(slot.key) ? wrapper : '');
  };
  const live = K.CHIP_SLOTS.filter((s) => !drop.includes(s.key));
  const BANDS = { bg: EDITORIAL.bg, surface: EDITORIAL.surface, accent: EDITORIAL.accent, dark: '#26211d' };
  const bodies = Object.keys(BANDS).map((b) => `<div class="et_pb_section" style="background:${BANDS[b]};padding:24px 0"><div class="et_pb_row"><div class="et_pb_column" style="display:block">${live.filter((s) => s.band === b).map((s) => piece(s, exps[K.CHIP_SLOTS.indexOf(s)])).join('')}</div></div></div>`); // rendered first: building the pieces fills `css`
  return `<style>body{margin:0;background:#faf7f2}.et_pb_row{width:90%;margin:0 auto}${css}${extraCss}</style><div class="et-l--post">${bodies.join('')}</div>${extra}`;
}

(async () => {
  const browser = await chromium.launch();
  const run = async (html, width = 1280, before = null) => {
    const p = await browser.newPage({ viewport: { width, height: 900 } });
    await p.setContent(html);
    if (before) await before(p);
    const r = await checkCards(p, { width, expects, buttonExpects, hairline: HAIRLINE });
    await p.close();
    return r;
  };
  const codes = (r) => r.problems.map((x) => x[0]);
  try {

    // ---- the rules and the chips: the line as Divi draws it, the painted band, the corners, the frame, the fill and the glyph ----
    const runRules = async (html, { width = 1280, exps = ruleExpects, before = null } = {}) => {
      const p = await browser.newPage({ viewport: { width, height: 900 } });
      await p.setContent(html);
      if (before) await before(p);
      const r = await checkRules(p, { width, expects: exps });
      await p.close();
      return r;
    };
    const runChips = async (html, { width = 1280, exps = chipExpects, before = null } = {}) => {
      const p = await browser.newPage({ viewport: { width, height: 900 } });
      await p.setContent(html);
      if (before) await before(p);
      const r = await checkChips(p, { width, expects: exps, hairline: HAIRLINE });
      await p.close();
      return r;
    };
    const rc = (r) => [...new Set(r.problems.map((x) => x[0]))];
    const rokay = await runRules(rulePageHtml());
    assert.deepStrictEqual(rokay.problems, [], JSON.stringify(rokay.problems));
    assert.strictEqual(rokay.rulesChecked, NR);
    assert.strictEqual(rokay.observations.length, NR);
    assert.ok(rokay.observations.every((o) => o.band && o.contrast.line > 1), 'the painted band was sampled for every rule: ' + JSON.stringify(rokay.observations.slice(0, 2)));
    assert.deepStrictEqual(rokay.observations.find((o) => o.key === 'rule-strong-group-bg').got, { borderTopWidth: '2px', borderTopStyle: 'solid', borderTopColor: 'rgb(28, 26, 23)' });
    for (const w of [900, 400]) { const r = await runRules(rulePageHtml(), { width: w }); assert.deepStrictEqual(r.problems, [], w + ': ' + JSON.stringify(r.problems)); }
    const DIV = '[data-r="rule-strong-divider-bg"]'; const GRP = '[data-r="rule-strong-group-bg"]';
    // rule-style: a dashed line where the kit says solid (on a group, on a divider's ::before).
    const rsGroup = await runRules(rulePageHtml({ extraCss: `${GRP}{border-top-style:dashed}` }));
    assert.deepStrictEqual(rc(rsGroup), ['rule-style']);
    assert.match(rsGroup.problems[0][1], /^rule-strong-group-bg rest: top line is dashed, expected solid/);
    const rsDiv = await runRules(rulePageHtml({ extraCss: `${DIV}::before{border-top-style:dotted}` }));
    assert.deepStrictEqual(rc(rsDiv), ['rule-style']);
    assert.match(rsDiv.problems[0][1], /^rule-strong-divider-bg rest: top line is dotted, expected solid/);
    // rule-width: a 1px line where the kit says 2px, on a group and on the divider's ::before; a stray side.
    const rwGroup = await runRules(rulePageHtml({ extraCss: `${GRP}{border-top-width:1px}` }));
    assert.deepStrictEqual(rc(rwGroup), ['rule-width']);
    assert.match(rwGroup.problems[0][1], /^rule-strong-group-bg rest: top line is 1px, expected 2px/);
    const rwDiv = await runRules(rulePageHtml({ extraCss: `${DIV}::before{border-top-width:1px}` }));
    assert.deepStrictEqual(rc(rwDiv), ['rule-width']);
    const rwSide = await runRules(rulePageHtml({ extraCss: `[data-r="rule-column-bg"]{border-bottom:2px solid #1c1a17}` }));
    assert.deepStrictEqual(rc(rwSide), ['rule-width']);
    assert.match(rwSide.problems[0][1], /^rule-column-bg rest: bottom side is 2px, expected 0px/);
    // rule-colour: a wrong colour, and the alpha that should have been there.
    const rcCol = await runRules(rulePageHtml({ extraCss: `${DIV}::before{border-top-color:#dc2626}` }));
    assert.deepStrictEqual(rc(rcCol), ['rule-colour']);
    assert.match(rcCol.problems[0][1], /^rule-strong-divider-bg rest: top line colour rgb\(220, 38, 38\), expected #1c1a17/);
    const rcAlpha = await runRules(rulePageHtml({ extraCss: '[data-r="rule-group-dark"]{border-top-color:rgb(251, 246, 236)}' }));
    assert.deepStrictEqual(rc(rcAlpha), ['rule-colour']);
    // rule-contrast alone: the page agrees with its (faint) expectations, so only the painted-band rule can object. A quiet line under 1.1:1, a strong line at 2.5:1.
    const faintQuiet = clone(ruleExpects); faintQuiet[0].top.color = '#f6f3ee';
    const rcFaint = await runRules(rulePageHtml({ exps: faintQuiet }), { exps: faintQuiet });
    assert.deepStrictEqual(rc(rcFaint), ['rule-contrast']);
    assert.match(rcFaint.problems[0][1], /^rule-divider-bg rule line #f6f3ee on the band #faf7f2 is 1\.0[0-9]*:1, needs 1\.1/);
    const weakStrong = clone(ruleExpects); weakStrong[3].top.color = '#a8a8a8';
    const rcWeak = await runRules(rulePageHtml({ exps: weakStrong }), { exps: weakStrong });
    assert.deepStrictEqual(rc(rcWeak), ['rule-contrast']);
    assert.match(rcWeak.problems[0][1], /^rule-strong-group-bg rule-strong line #a8a8a8 on the band #faf7f2 is 2\.[0-9]+:1, needs 3/);
    const weakDark = clone(ruleExpects); weakDark[9].top.color = '#3c352f';
    const rcDark = await runRules(rulePageHtml({ exps: weakDark }), { exps: weakDark });
    assert.deepStrictEqual(rc(rcDark), ['rule-contrast'], 'the dark band is sampled too: a strong line the colour of the band');
    assert.match(rcDark.problems[0][1], /^rule-strong-group-dark rule-strong line /);
    // A fixed bar scrolled over the first rule (the admin bar) must not disturb the painted sample, and is put back.
    const rBar = await runRules(rulePageHtml({ extraCss: '#bar{position:fixed;top:0;left:0;right:0;height:900px;background:#cc0000;z-index:9;opacity:.999}' }), { before: (page) => page.evaluate(() => { const b = document.createElement('div'); b.id = 'bar'; document.body.appendChild(b); }) });
    assert.deepStrictEqual(rBar.problems, [], 'a fixed element over the page is hidden for the samples: ' + JSON.stringify(rBar.problems.slice(0, 3)));
    {
      const pg = await browser.newPage({ viewport: { width: 1280, height: 900 } });
      await pg.setContent(rulePageHtml({ extraCss: '#bar{position:fixed;top:0;left:0;right:0;height:900px;background:#cc0000;z-index:9}' }));
      await pg.evaluate(() => { const b = document.createElement('div'); b.id = 'bar'; document.body.appendChild(b); });
      await checkRules(pg, { width: 1280, expects: ruleExpects });
      const left = await pg.evaluate(() => ({ bar: getComputedStyle(document.getElementById('bar')).visibility, hidden: document.querySelectorAll('[data-aied-xv],[data-aied-xf],[data-aied-xt]').length, rules: [...document.querySelectorAll('[data-aied-rule]')].filter((n) => getComputedStyle(n).visibility !== 'visible').length }));
      assert.deepStrictEqual(left, { bar: 'visible', hidden: 0, rules: 0 }, 'everything hidden for a sample is restored');
      await pg.close();
    }
    // rule: a slot that is not on the page, one on it twice, and the early return that checks nothing else.
    const rLost = await runRules(rulePageHtml({ drop: ['rule-column-bg'] }));
    assert.deepStrictEqual(rc(rLost), ['rule']);
    assert.match(rLost.problems[0][1], /^15 of 16 rules rendered as the sheet says: rule-column-bg is not in the page/);
    assert.strictEqual(rLost.rulesChecked, 0, 'a page with a rule missing checks nothing else');
    assert.deepStrictEqual(K.emptyRuleRunProblems({ rulesChecked: rLost.rulesChecked }), ['no rule was checked: the rule selection was empty']);
    const rTwice = await runRules(rulePageHtml({ dup: ['rule-group-bg'] }));
    assert.deepStrictEqual(rc(rTwice), ['rule']);
    assert.match(rTwice.problems[0][1], /rule-group-bg is on the page 2 times, expected 1/);
    // A divider with no line element under its heading is a missing rule, not a pass.
    const rNoLine = await runRules(rulePageHtml({ extraCss: '' }).replace('<div class="et_pb_module et_pb_divider" data-r="rule-divider-bg"></div>', ''));
    assert.deepStrictEqual(rc(rNoLine), ['rule']);
    assert.match(rNoLine.problems[0][1], /rule-divider-bg is not in the page/);
    // A null expectation never skips: an expectation with a hole is a finding even though the page is fine.
    const holes = clone(ruleExpects); holes[1].top.width = null;
    const rHole = await runRules(rulePageHtml(), { exps: holes });
    assert.ok(rc(rHole).includes('rule-expectation'), rc(rHole).join());
    // Overflow: a rule wider than the phone.
    const rWide = await runRules(rulePageHtml({ extraCss: `${GRP}{width:900px}` }), { width: 400 });
    assert.ok(rc(rWide).includes('overflow'), 'a rule wider than the phone: ' + rc(rWide).join());

    const cokay = await runChips(chipPageHtml());
    assert.deepStrictEqual(cokay.problems, [], JSON.stringify(cokay.problems));
    assert.strictEqual(cokay.chipsChecked, NC);
    assert.ok(cokay.observations.every((o) => o.band) && cokay.observations.find((o) => o.carrier === 'icon').fill === '#9a3b1f', 'the painted fill of the icon chip and the band were sampled: ' + JSON.stringify(cokay.observations));
    for (const w of [900, 400]) { const r = await runChips(chipPageHtml(), { width: w }); assert.deepStrictEqual(r.problems, [], w + ': ' + JSON.stringify(r.problems)); }
    // A 0px kit is checked too (four zeros are an expectation), and passes when the chips are square.
    assert.deepStrictEqual(squareChipExpects[0].radius, [0, 0, 0, 0]);
    const csq = await runChips(chipPageHtml({ exps: squareChipExpects }), { exps: squareChipExpects });
    assert.deepStrictEqual(csq.problems, [], JSON.stringify(csq.problems));
    assert.strictEqual(csq.chipsChecked, NC, 'a square chip counts as checked');
    const TAG = '[data-c="chip-tag-bg"]'; const ICON = '[data-c="chip-icon-bg"]';
    // chip-radius: a rounded chip on a 0px kit, and a square chip on a rounded kit.
    const crRound = await runChips(chipPageHtml({ exps: squareChipExpects, extraCss: `${TAG},${ICON}{border-radius:12px}` }), { exps: squareChipExpects });
    assert.deepStrictEqual(rc(crRound), ['chip-radius']);
    assert.strictEqual(crRound.problems.length, 8, 'four corners of each chip');
    assert.match(crRound.problems[0][1], /^chip-tag-bg rest: borderTopLeftRadius 12px, expected 0px/);
    const crSquare = await runChips(chipPageHtml({ extraCss: `${ICON}{border-radius:0}` }));
    assert.deepStrictEqual(rc(crSquare), ['chip-radius']);
    // chip-frame: a missing tag frame, a dashed one where the kit says solid, a side of the wrong colour.
    const cfNone = await runChips(chipPageHtml({ extraCss: `${TAG}{border:0 none}` }));
    assert.deepStrictEqual(rc(cfNone), ['chip-frame']);
    assert.match(cfNone.problems[0][1], /^chip-tag-bg rest: border-top width 0px, expected 1px/);
    const cfDash = await runChips(chipPageHtml({ extraCss: `${TAG}{border-style:dashed}` }));
    assert.deepStrictEqual(rc(cfDash), ['chip-frame']);
    assert.match(cfDash.problems[0][1], /border-top style dashed, expected solid/);
    const cfCol = await runChips(chipPageHtml({ extraCss: `${TAG}{border-left-color:#dc2626}` }));
    assert.deepStrictEqual(rc(cfCol), ['chip-frame']);
    // chip-fill: a filled tag, an icon fill equal to the band, a wrong glyph, a fill painted over a matching computed one.
    const cfFilled = await runChips(chipPageHtml({ extraCss: `${TAG}{background:#9a3b1f}` }));
    assert.deepStrictEqual(rc(cfFilled), ['chip-fill']);
    assert.match(cfFilled.problems[0][1], /^chip-tag-bg rest: a tag has no fill/);
    const cfBand = await runChips(chipPageHtml({ extraCss: `${ICON}{background:#faf7f2}` }));
    assert.ok(rc(cfBand).includes('chip-fill'), rc(cfBand).join());
    assert.match(cfBand.problems.find(([c]) => c === 'chip-fill')[1], /^chip-icon-bg rest: fill rgb\(250, 247, 242\), expected #9a3b1f/);
    const cfGlyph = await runChips(chipPageHtml({ extraCss: `${ICON} .et-pb-icon{color:#000000}` }));
    assert.deepStrictEqual(rc(cfGlyph), ['chip-fill']);
    assert.match(cfGlyph.problems[0][1], /^chip-icon-bg rest: glyph colour rgb\(0, 0, 0\), expected #ffffff/);
    const cfOver = await runChips(chipPageHtml({ extraCss: `${ICON}{position:relative}${ICON}::after{content:"";position:absolute;left:0;top:0;right:0;bottom:0;background:#336699;border-radius:inherit}` }));
    assert.ok(rc(cfOver).includes('chip-fill'), 'a colour painted over the chip: ' + rc(cfOver).join());
    assert.ok(cfOver.problems.some(([c, d]) => c === 'chip-fill' && /^chip-icon-bg painted fill #336699 differs from the computed rgb\(154, 59, 31\)/.test(d)), JSON.stringify(cfOver.problems));
    // chip-contrast alone: the page agrees with its (unreadable) expectations. A glyph equal to its fill; an icon fill the colour of the band; a faint strong tag frame.
    const sameGlyph = clone(chipExpects); sameGlyph[1].glyph = sameGlyph[1].fill;
    const ccGlyph = await runChips(chipPageHtml({ exps: sameGlyph }), { exps: sameGlyph });
    assert.deepStrictEqual(rc(ccGlyph), ['chip-contrast']);
    assert.match(ccGlyph.problems[0][1], /^chip-icon-bg glyph #9a3b1f on the chip fill #9a3b1f is 1:1, needs 3/);
    const bandIcon = clone(chipExpects); bandIcon[1].fill = '#faf7f2'; bandIcon[1].glyph = '#1c1a17';
    const ccBand = await runChips(chipPageHtml({ exps: bandIcon }), { exps: bandIcon });
    assert.deepStrictEqual(rc(ccBand), ['chip-contrast']);
    assert.match(ccBand.problems[0][1], /^chip-icon-bg the chip fill #faf7f2 is the colour of the band #faf7f2/);
    const faintTag = clone(chipExpects); faintTag[0].frame.color = '#f1ece2';
    const ccTag = await runChips(chipPageHtml({ exps: faintTag }), { exps: faintTag });
    assert.deepStrictEqual(rc(ccTag), ['chip-contrast'], 'a frame that is neither the hairline nor 3:1');
    assert.match(ccTag.problems[0][1], /^chip-tag-bg frame top #f1ece2 on the band #faf7f2 is 1\.[0-9]+:1, needs 3/);
    // chip: a chip that is not on the page or on it twice, an icon without its glyph; nothing else is then checked.
    const cLost = await runChips(chipPageHtml({ drop: ['chip-icon-bg'] }));
    assert.deepStrictEqual(rc(cLost), ['chip']);
    assert.match(cLost.problems[0][1], /^4 of 5 chips rendered as the sheet says: chip-icon-bg is not in the page/);
    assert.strictEqual(cLost.chipsChecked, 0);
    assert.deepStrictEqual(K.emptyChipRunProblems({ chipsChecked: cLost.chipsChecked }), ['no chip was checked: the chip selection was empty']);
    const cTwice = await runChips(chipPageHtml({ dup: ['chip-tag-bg'] }));
    assert.deepStrictEqual(rc(cTwice), ['chip']);
    assert.match(cTwice.problems[0][1], /chip-tag-bg is on the page 2 times, expected 1/);
    const cNoGlyph = await runChips(chipPageHtml().replace('<span class="et-pb-icon">&#9733;</span>', ''));
    assert.deepStrictEqual(rc(cNoGlyph), ['chip']);
    assert.match(cNoGlyph.problems[0][1], /chip-icon-bg has no glyph/);
    const cHole = clone(chipExpects); cHole[0].radius = [4, 4, 4];
    assert.ok(rc(await runChips(chipPageHtml(), { exps: cHole })).includes('chip-expectation'), 'a null corner is a finding, never a skip');
    const cWide = await runChips(chipPageHtml({ extraCss: `${TAG}{width:900px}` }), { width: 400 });
    assert.ok(rc(cWide).includes('overflow'), 'a chip wider than the phone: ' + rc(cWide).join());
    {
      const pg = await browser.newPage({ viewport: { width: 1280, height: 900 } });
      await pg.setContent(chipPageHtml({ extraCss: '#bar{position:fixed;top:0;left:0;right:0;height:900px;background:#cc0000;z-index:9}' }));
      await pg.evaluate(() => { const b = document.createElement('div'); b.id = 'bar'; document.body.appendChild(b); });
      const r = await checkChips(pg, { width: 1280, expects: chipExpects, hairline: HAIRLINE });
      assert.deepStrictEqual(r.problems, [], 'a fixed element over the page is hidden for the samples: ' + JSON.stringify(r.problems.slice(0, 3)));
      const left = await pg.evaluate(() => ({ bar: getComputedStyle(document.getElementById('bar')).visibility, leftovers: document.querySelectorAll('[data-aied-xv],[data-aied-xf],[data-aied-xt],[data-aied-xc]').length, glyph: getComputedStyle(document.querySelector('.et-pb-icon')).visibility, chips: [...document.querySelectorAll('[data-aied-chip]')].filter((n) => getComputedStyle(n).visibility !== 'visible').length }));
      assert.deepStrictEqual(left, { bar: 'visible', leftovers: 0, glyph: 'visible', chips: 0 }, 'everything hidden for a sample is restored');
      await pg.close();
    }
    // ---- the branches the first gate never rendered (guide polish Task 2): dividers on the surface and accent bands, a top-rule group in an inverted panel,
    // a tag on the surface and photo bands, an icon chip on the accent band. Each new slot has its own departures, each caught with the right code. ----
    const NEWR = ['rule-divider-surface', 'rule-strong-divider-surface', 'rule-divider-accent', 'rule-strong-divider-accent', 'rule-group-panel-bg', 'rule-strong-group-panel-bg'];
    const NEWC = ['chip-tag-surface', 'chip-tag-dark', 'chip-icon-accent'];
    const rexpOf = (key) => ruleExpects[K.RULE_SLOTS.findIndex((x) => x.key === key)];
    const selOf = (key) => `[data-r="${key}"]` + (K.RULE_SLOTS.find((x) => x.key === key).carrier === 'divider' ? '::before' : '');
    const withRule = (key, mut) => { const e = clone(ruleExpects); mut(e[K.RULE_SLOTS.findIndex((x) => x.key === key)]); return e; };
    for (const w of [900, 400]) { const r = await runRules(rulePageHtml(), { width: w }); for (const [c] of r.problems) assert.notStrictEqual(c, 'rule', w); }
    for (const key of NEWR) {
      const e = rexpOf(key);
      const o = rokay.observations.find((x) => x.key === key);
      assert.ok(o && o.band && o.contrast.line > 1, `${key}: the new rule was measured and its painted band sampled: ${JSON.stringify(o)}`);
      assert.strictEqual(o.got.borderTopWidth, e.top.width + 'px', `${key}: the computed line is the expected width`);
      // rule-style: a different style on the line (the divider's ::before or the group's own top edge).
      const style = e.top.style === 'dashed' ? 'dotted' : 'dashed';
      const rs = await runRules(rulePageHtml({ extraCss: `${selOf(key)}{border-top-style:${style}}` }));
      assert.deepStrictEqual(rc(rs), ['rule-style'], `${key}: ${rc(rs).join()}`);
      assert.match(rs.problems[0][1], new RegExp(`^${key} rest: top line is ${style}, expected ${e.top.style}`));
      // rule-width: a thicker line.
      const rw = await runRules(rulePageHtml({ extraCss: `${selOf(key)}{border-top-width:${e.top.width + 2}px}` }));
      assert.deepStrictEqual(rc(rw), ['rule-width'], `${key}: ${rc(rw).join()}`);
      assert.match(rw.problems[0][1], new RegExp(`^${key} rest: top line is ${e.top.width + 2}px, expected ${e.top.width}px`));
      // rule-colour: red where the kit says something else (the alpha is part of the colour: an opaque line fails where a 40% one is expected).
      const wrongCol = /accent/.test(key) ? 'rgb(255, 230, 200)' : /panel/.test(key) ? 'rgb(255, 255, 255)' : 'rgb(0, 0, 0)'; // never the expected colour, always readable on the band (only rule-colour fires)
      const rcol = await runRules(rulePageHtml({ extraCss: `${selOf(key)}{border-top-color:${wrongCol}}` }));
      assert.deepStrictEqual(rc(rcol), ['rule-colour'], `${key}: ${rc(rcol).join()}`);
      if (K.colour(e.top.color).a < 1) {
        const solid = K.colour(e.top.color);
        const ra = await runRules(rulePageHtml({ extraCss: `${selOf(key)}{border-top-color:rgb(${solid.r}, ${solid.g}, ${solid.b})}` }));
        assert.deepStrictEqual(rc(ra), ['rule-colour'], `${key}: the 40% alpha is part of the colour: ${rc(ra).join()}`);
      }
      // rule: a slot that is not on the page, and one on it twice; nothing else is then checked.
      const lost = await runRules(rulePageHtml({ drop: [key] }));
      assert.deepStrictEqual(rc(lost), ['rule'], key);
      assert.match(lost.problems[0][1], new RegExp(`^15 of 16 rules rendered as the sheet says: ${key} is not in the page`));
      assert.strictEqual(lost.rulesChecked, 0, `${key}: a page with a rule missing checks nothing else`);
      const twice = await runRules(rulePageHtml({ dup: [key] }));
      assert.deepStrictEqual(rc(twice), ['rule'], key);
      assert.match(twice.problems[0][1], new RegExp(`${key} is on the page 2 times, expected 1`));
      // A null expectation is a finding, never a skip.
      const hole = withRule(key, (x) => { x.top.width = null; });
      assert.ok(rc(await runRules(rulePageHtml(), { exps: hole })).includes('rule-expectation'), `${key}: a hole in the expectation`);
    }
    // A stray side on the panel group rule (only the top edge is drawn).
    const stray = await runRules(rulePageHtml({ extraCss: '[data-r="rule-strong-group-panel-bg"]{border-bottom:2px solid #faf7f2}' }));
    assert.deepStrictEqual(rc(stray), ['rule-width']);
    assert.match(stray.problems[0][1], /^rule-strong-group-panel-bg rest: bottom side is 2px, expected 0px/);
    // rule-contrast alone, on the new bands: the page agrees with its own (faint) expectations, so only the painted-band rule can object.
    const faintPanel = withRule('rule-group-panel-bg', (x) => { x.top.color = 'rgba(28,26,23,0.05)'; });
    const rcFaintPanel = await runRules(rulePageHtml({ exps: faintPanel }), { exps: faintPanel });
    assert.deepStrictEqual(rc(rcFaintPanel), ['rule-contrast'], 'a faint quiet line on the panel: ' + JSON.stringify(rcFaintPanel.problems));
    assert.match(rcFaintPanel.problems[0][1], /^rule-group-panel-bg rule line #[0-9a-f]{6} on the band #1c1a17 is 1(\.0[0-9]*)?:1, needs 1\.1/);
    const weakAccent = withRule('rule-strong-divider-accent', (x) => { x.top.color = '#a8462a'; });
    const rcWeakAccent = await runRules(rulePageHtml({ exps: weakAccent }), { exps: weakAccent });
    assert.deepStrictEqual(rc(rcWeakAccent), ['rule-contrast'], 'a strong line almost the colour of the accent band');
    assert.match(rcWeakAccent.problems[0][1], /^rule-strong-divider-accent rule-strong line #a8462a on the band #9a3b1f is 1\.[0-9]+:1, needs 3/);
    const sameAccent = withRule('rule-divider-accent', (x) => { x.top.color = EDITORIAL.accent; });
    assert.deepStrictEqual(rc(await runRules(rulePageHtml({ exps: sameAccent }), { exps: sameAccent })), ['rule-contrast'], 'a quiet line the colour of the accent band');
    const weakSurface = withRule('rule-strong-divider-surface', (x) => { x.top.color = '#d0d0d0'; });
    const rcWeakSurface = await runRules(rulePageHtml({ exps: weakSurface }), { exps: weakSurface });
    assert.deepStrictEqual(rc(rcWeakSurface), ['rule-contrast'], 'a strong line at about 1.5:1 on the surface band');
    assert.match(rcWeakSurface.problems[0][1], /^rule-strong-divider-surface rule-strong line #d0d0d0 on the band #ffffff/);
    // The new rules are hidden for the sample and put back, even on the panel.
    {
      const pg = await browser.newPage({ viewport: { width: 1280, height: 900 } });
      await pg.setContent(rulePageHtml({ extraCss: '#bar{position:fixed;top:0;left:0;right:0;height:900px;background:#cc0000;z-index:9}' }));
      await pg.evaluate(() => { const b = document.createElement('div'); b.id = 'bar'; document.body.appendChild(b); });
      const r = await checkRules(pg, { width: 1280, expects: ruleExpects });
      assert.deepStrictEqual(r.problems, [], 'a fixed bar over the page is hidden for the new samples too: ' + JSON.stringify(r.problems.slice(0, 3)));
      assert.ok(r.observations.filter((o) => NEWR.includes(o.key)).every((o) => o.band !== '#cc0000'), 'no new rule was sampled over the bar');
      await pg.close();
    }
    // A run that checked no rule fails (the empty selection), whatever the new slots say.
    assert.deepStrictEqual(K.emptyRuleRunProblems({ rulesChecked: (await runRules(rulePageHtml({ drop: NEWR }))).rulesChecked }), ['no rule was checked: the rule selection was empty']);

    const cexpOf = (key) => chipExpects[K.CHIP_SLOTS.findIndex((x) => x.key === key)];
    const withChip = (key, mut) => { const e = clone(chipExpects); mut(e[K.CHIP_SLOTS.findIndex((x) => x.key === key)]); return e; };
    for (const key of NEWC) {
      const e = cexpOf(key);
      const o = cokay.observations.find((x) => x.key === key);
      assert.ok(o && o.band, `${key}: the new chip was measured and its painted band sampled: ${JSON.stringify(o)}`);
      // chip-radius: one rounder corner set than the kit's.
      const rr = await runChips(chipPageHtml({ extraCss: `[data-c="${key}"]{border-radius:37px}` }));
      assert.deepStrictEqual(rc(rr), ['chip-radius'], `${key}: ${rc(rr).join()}`);
      // chip: not on the page, or on it twice; nothing else is then checked.
      const lost = await runChips(chipPageHtml({ drop: [key] }));
      assert.deepStrictEqual(rc(lost), ['chip'], key);
      assert.match(lost.problems[0][1], new RegExp(`^4 of 5 chips rendered as the sheet says: ${key} is not in the page`));
      assert.strictEqual(lost.chipsChecked, 0, `${key}: a page with a chip missing checks nothing else`);
      const twice = await runChips(chipPageHtml({ dup: [key] }));
      assert.deepStrictEqual(rc(twice), ['chip'], key);
      assert.match(twice.problems[0][1], new RegExp(`${key} is on the page 2 times, expected 1`));
      // A null corner is a finding, never a skip.
      const hole = withChip(key, (x) => { x.radius = [4, 4, 4]; });
      assert.ok(rc(await runChips(chipPageHtml(), { exps: hole })).includes('chip-expectation'), `${key}: a hole in the expectation`);
    }
    // chip-frame on the new tags: a dashed frame where the kit says solid; on the photo band an OPAQUE frame where the kit says 40%.
    const cfSurf = await runChips(chipPageHtml({ extraCss: '[data-c="chip-tag-surface"]{border-style:dashed}' }));
    assert.deepStrictEqual(rc(cfSurf), ['chip-frame']);
    assert.match(cfSurf.problems[0][1], /^chip-tag-surface rest: border-top style dashed, expected solid/);
    const darkFrame = cexpOf('chip-tag-dark').frame.color; const dk = K.colour(darkFrame);
    assert.ok(dk.a < 1, 'the photo-band frame of this kit is the 40% alpha form: ' + darkFrame);
    const cfDark = await runChips(chipPageHtml({ extraCss: `[data-c="chip-tag-dark"]{border-color:rgb(${dk.r}, ${dk.g}, ${dk.b})}` }));
    assert.deepStrictEqual(rc(cfDark), ['chip-frame'], 'the 40% alpha is part of the frame colour: ' + rc(cfDark).join());
    // chip-fill on the accent chip: the accent fill the bg-band chip has instead of `surface`; a glyph in the wrong colour; a colour painted over it.
    const ICON_A = '[data-c="chip-icon-accent"]';
    const cfAcc = await runChips(chipPageHtml({ extraCss: `${ICON_A}{background:${EDITORIAL.accent}}` }));
    assert.ok(rc(cfAcc).includes('chip-fill'), 'the bg-band fill on the accent chip: ' + rc(cfAcc).join());
    assert.match(cfAcc.problems.find(([c]) => c === 'chip-fill')[1], /^chip-icon-accent rest: fill rgb\(154, 59, 31\), expected #ffffff/);
    const cfAccGlyph = await runChips(chipPageHtml({ extraCss: `${ICON_A} .et-pb-icon{color:#ffffff}` }));
    assert.deepStrictEqual(rc(cfAccGlyph), ['chip-fill', 'chip-contrast'], 'a white glyph on the white accent-band chip: ' + rc(cfAccGlyph).join());
    assert.match(cfAccGlyph.problems.find(([c]) => c === 'chip-fill')[1], /^chip-icon-accent rest: glyph colour rgb\(255, 255, 255\), expected #1c1a17/);
    const cfAccOver = await runChips(chipPageHtml({ extraCss: `${ICON_A}{position:relative}${ICON_A}::after{content:"";position:absolute;left:0;top:0;right:0;bottom:0;background:#336699;border-radius:inherit}` }));
    assert.ok(cfAccOver.problems.some(([c, d]) => c === 'chip-fill' && /^chip-icon-accent painted fill #336699 differs from the computed rgb\(255, 255, 255\)/.test(d)), JSON.stringify(cfAccOver.problems));
    // chip-contrast alone, on the new bands: the page agrees with its (unreadable) expectations.
    const fillIsBand = withChip('chip-icon-accent', (x) => { x.fill = EDITORIAL.accent; x.glyph = '#ffffff'; });
    const ccFillBand = await runChips(chipPageHtml({ exps: fillIsBand }), { exps: fillIsBand });
    assert.deepStrictEqual(rc(ccFillBand), ['chip-contrast'], 'an accent-band icon chip whose fill equals the band: ' + JSON.stringify(ccFillBand.problems));
    assert.match(ccFillBand.problems[0][1], /^chip-icon-accent the chip fill #9a3b1f is the colour of the band #9a3b1f/);
    const glyphIsFill = withChip('chip-icon-accent', (x) => { x.glyph = x.fill; });
    const ccAccGlyph = await runChips(chipPageHtml({ exps: glyphIsFill }), { exps: glyphIsFill });
    assert.deepStrictEqual(rc(ccAccGlyph), ['chip-contrast']);
    assert.match(ccAccGlyph.problems[0][1], /^chip-icon-accent glyph #ffffff on the chip fill #ffffff is 1:1, needs 3/);
    const faintDark = withChip('chip-tag-dark', (x) => { x.frame.color = 'rgba(38,33,29,0.05)'; });
    const ccFaintDark = await runChips(chipPageHtml({ exps: faintDark }), { exps: faintDark });
    assert.deepStrictEqual(rc(ccFaintDark), ['chip-contrast'], 'a quiet frame the colour of the photo band: ' + JSON.stringify(ccFaintDark.problems));
    assert.match(ccFaintDark.problems[0][1], /^chip-tag-dark frame top #[0-9a-f]{6} on the band #26211d is 1(\.0[0-9]*)?:1, needs 1\.1/);
    const faintOpaqueDark = withChip('chip-tag-dark', (x) => { x.frame.color = '#4a4540'; });
    const ccOpaqueDark = await runChips(chipPageHtml({ exps: faintOpaqueDark }), { exps: faintOpaqueDark });
    assert.deepStrictEqual(rc(ccOpaqueDark), ['chip-contrast'], 'an OPAQUE frame is a strong frame: it needs 3.0 on the photo band');
    assert.match(ccOpaqueDark.problems[0][1], /^chip-tag-dark frame top #4a4540 on the band #26211d is 1\.[0-9]+:1, needs 3/);
    const faintSurface = withChip('chip-tag-surface', (x) => { x.frame.color = '#fafafa'; });
    assert.deepStrictEqual(rc(await runChips(chipPageHtml({ exps: faintSurface }), { exps: faintSurface })), ['chip-contrast'], 'a frame nearly the colour of the surface band');
    // The 0px kit is checked on the new chips too (four zeros are expectations).
    const sq = await runChips(chipPageHtml({ exps: squareChipExpects }), { exps: squareChipExpects });
    assert.deepStrictEqual(sq.problems, [], JSON.stringify(sq.problems));
    assert.deepStrictEqual(squareChipExpects.map((x) => x.radius.join()), K.CHIP_SLOTS.map(() => '0,0,0,0'));
    assert.deepStrictEqual(K.emptyChipRunProblems({ chipsChecked: (await runChips(chipPageHtml({ drop: NEWC }))).chipsChecked }), ['no chip was checked: the chip selection was empty']);

    if (RULES_ONLY) { console.log('OK kit-rule-run in a real Chromium on synthetic pages (AIED_RULES_ONLY): a matching page passes at 1280, 900 and 400, a 0px chip kit is checked, every rule and chip departure is caught with its code'); return; }

    // ---- the image: the corners of the img, its wrapper and the module box, the clip, overflow and the count ----
    const runMedia = async (html, { width = 1280, exps = mediaExpects } = {}) => {
      const p = await browser.newPage({ viewport: { width, height: 900 } });
      await p.setContent(html);
      const r = await checkMedia(p, { width, expects: exps });
      await p.close();
      return r;
    };
    const mc = (r) => [...new Set(r.problems.map((x) => x[0]))];
    const WRAP = '.et_pb_image_wrap';
    const mokay = await runMedia(mediaPageHtml());
    assert.deepStrictEqual(mokay.problems, [], JSON.stringify(mokay.problems));
    assert.strictEqual(mokay.mediaChecked, NM);
    assert.strictEqual(mokay.observations.length, NM);
    assert.deepStrictEqual(mokay.observations[0].expected, mediaExpects[0].radius);
    assert.ok(mokay.observations[0].wrapper.every((v) => v === '16px') && mokay.observations[0].img.every((v) => v === '16px') && mokay.observations[0].wrapperOverflow === 'hidden/hidden', 'the corners were measured on the img and the wrapper: ' + JSON.stringify(mokay.observations[0]));
    for (const w of [900, 400]) { const r = await runMedia(mediaPageHtml(), { width: w }); assert.deepStrictEqual(r.problems, [], w + ': ' + JSON.stringify(r.problems)); }
    // A square kit (media 0px) is checked too, and passes when the image is square.
    assert.deepStrictEqual(squareExpects[0].radius, [0, 0, 0, 0]);
    const msquare = await runMedia(mediaPageHtml({ exps: squareExpects }), { exps: squareExpects });
    assert.deepStrictEqual(msquare.problems, [], JSON.stringify(msquare.problems));
    assert.strictEqual(msquare.mediaChecked, NM, 'a square image counts as checked');
    // The radius on the img alone (a wrapper that is square and does not clip) passes: whichever element Divi paints it on.
    const mimgOnly = await runMedia(mediaPageHtml({ extraCss: `${WRAP}{border-radius:0 !important;overflow:visible !important}${WRAP} img{border-radius:16px !important}` }));
    assert.deepStrictEqual(mimgOnly.problems, [], 'the radius on the img only: ' + JSON.stringify(mimgOnly.problems));

    // Departures, each caught with its own code.
    const mWrong = await runMedia(mediaPageHtml({ extraCss: `${WRAP}{border-top-left-radius:2px}` }));
    assert.ok(mc(mWrong).includes('media-radius'), mc(mWrong).join());
    assert.match(mWrong.problems[0][1], /^media-bg rest: img borderTopLeftRadius 2px, expected 16px/);
    assert.ok(mWrong.problems.some(([, d]) => /^media-bg rest: wrapper borderTopLeftRadius 2px, expected 16px/.test(d)), 'the wrapper is named too');
    const mCorner = await runMedia(mediaPageHtml({ extraCss: `${WRAP} img{border-bottom-right-radius:9px}` }));
    assert.deepStrictEqual(mc(mCorner), ['media-radius']);
    assert.match(mCorner.problems[0][1], /^media-bg rest: img borderBottomRightRadius 9px, expected 16px/);
    const mNone = await runMedia(mediaPageHtml({ extraCss: `${WRAP}{border-radius:0 !important}${WRAP} img{border-radius:0 !important}` }));
    assert.deepStrictEqual(mc(mNone), ['media-radius']);
    assert.match(mNone.problems[0][1], /^media-bg rest: no element of the image carries the expected radius 16\/16\/16\/16px/);
    const mStray = await runMedia(mediaPageHtml({ exps: squareExpects, extraCss: `${WRAP}{overflow:hidden;border-radius:16px}` }), { exps: squareExpects });
    assert.ok(mc(mStray).includes('media-radius'), 'a baked radius on a square kit: ' + mc(mStray).join());
    assert.match(mStray.problems[0][1], /^media-bg rest: img borderTopLeftRadius 16px, expected 0px/);
    const mModule = await runMedia(mediaPageHtml({ extraCss: '.et_pb_image{border-radius:30px}' }));
    assert.deepStrictEqual(mc(mModule), ['media-radius']);
    assert.match(mModule.problems[0][1], /^media-bg rest: module borderTopLeftRadius 30px, expected 16px/);
    // The clip.
    const mNoClip = await runMedia(mediaPageHtml({ extraCss: `${WRAP}{overflow:visible !important}${WRAP} img{border-radius:0 !important}` }));
    assert.deepStrictEqual(mc(mNoClip), ['media-clip']);
    assert.match(mNoClip.problems[0][1], /^media-bg rest: the wrapper carries the radius 16px but is overflow visible\/visible/);
    const mCut = await runMedia(mediaPageHtml({ extraCss: `${WRAP}{border-radius:0 !important;width:80%;overflow:hidden !important}${WRAP} img{border-radius:16px !important;width:125%}` }));
    assert.deepStrictEqual(mc(mCut), ['media-clip']);
    assert.match(mCut.problems[0][1], /is cut by its wrapper .*, so its rounded corners are not visible/);
    const mOut = await runMedia(mediaPageHtml({ extraCss: `${WRAP}{border-radius:0 !important;width:80%;overflow:visible !important}${WRAP} img{border-radius:16px !important;width:125%}` }));
    assert.deepStrictEqual(mc(mOut), ['media-clip']);
    assert.match(mOut.problems[0][1], /sticks out of its wrapper/);
    // Overflow.
    const mWide = await runMedia(mediaPageHtml({ extraCss: '.et_pb_image{width:900px}' }), { width: 400 });
    assert.ok(mc(mWide).includes('overflow'), 'an image wider than the phone: ' + mc(mWide).join());
    // The count: the image missing or twice, nothing else checked; a page with no image at all fails the run.
    const mLost = await runMedia(mediaPageHtml({ drop: true }));
    assert.deepStrictEqual(mc(mLost), ['media']);
    assert.match(mLost.problems[0][1], /^0 of 1 images rendered as the sheet says: media-bg is not in the page/);
    assert.strictEqual(mLost.mediaChecked, 0, 'a page with the image missing checks nothing else');
    assert.deepStrictEqual(K.emptyMediaRunProblems({ mediaChecked: mLost.mediaChecked }), ['no image was checked: the image selection was empty']);
    const mTwice = await runMedia(mediaPageHtml({ dup: true }));
    assert.deepStrictEqual(mc(mTwice), ['media']);
    assert.match(mTwice.problems[0][1], /media-bg is on the page 2 times, expected 1/);
    if (MEDIA_ONLY) { console.log('OK kit-media-run in a real Chromium on synthetic pages (AIED_MEDIA_ONLY): a matching page passes at 1280, 900 and 400, a square kit is checked, every departure is caught with its code'); return; }

    if (!ITEMS_ONLY) {
      // A page that agrees with the kit passes, at desktop (columns side by side) and at 400 px (stacked).
      const ok = await run(pageHtml());
      assert.deepStrictEqual(ok.problems, [], JSON.stringify(ok.problems));
      assert.strictEqual(ok.cardsChecked, N);
      assert.strictEqual(ok.layout, 'side-by-side');
      assert.strictEqual(ok.observations.length, N);
      const clip = ok.observations.find((o) => o.key === 'panel-clip');
      assert.strictEqual(clip.clip.flush, true);
      assert.strictEqual(clip.clip.clipped, true, 'the 16px radius cuts the flush image corner');
      assert.ok(ok.observations.every((o, i) => o.fill === K.hex(K.colour(expects[i].rest.fill))), 'the painted fill was measured with the children hidden and is the resolved fill: ' + ok.observations.map((o) => o.fill).join());
      assert.strictEqual(ok.observations.filter((o) => o.ground).length, 9, 'eight grounds on the two light bands and the accent middle card are reported as grounds');
      const feat = ok.observations.find((o) => o.key === 'column-2').featured;
      assert.ok(feat && feat.featured > feat.neighbours[0] * 1.5 && feat.neighbours.length === 2 && feat.ratio >= 1.5, 'the featured middle card is measured against its neighbours: ' + JSON.stringify(feat));
      assert.strictEqual(ok.observations.filter((o) => o.buttons).length, 2, 'the two button panels report their buttons');
      assert.ok(ok.observations.every((o) => o.band !== null), 'a strip of the band above every card was sampled');
      const phone = await run(pageHtml(), 400);
      assert.deepStrictEqual(phone.problems, [], JSON.stringify(phone.problems));
      assert.strictEqual(phone.layout, 'stacked');

      // Departures, each caught with its own code.
      const wrongRadius = await run(pageHtml({ extraCss: '[data-k="card-surface"]{border-top-left-radius:2px}' }));
      assert.deepStrictEqual(codes(wrongRadius), ['card-radius']);
      assert.match(wrongRadius.problems[0][1], /^card-surface rest: borderTopLeftRadius 2px, expected 16px/);
      const wrongBorder = await run(pageHtml({ extraCss: '[data-k="panel-dark"]{border-left-width:3px}' }));
      assert.ok(codes(wrongBorder).includes('card-border'), codes(wrongBorder).join());
      const wrongPad = await run(pageHtml({ extraCss: '[data-k="card-accent"]{padding-left:10px}' }));
      assert.deepStrictEqual(codes(wrongPad), ['card-padding']);
      const wrongFill = await run(pageHtml({ extraCss: '[data-k="card-dark"]{background:#000}' }));
      assert.ok(codes(wrongFill).includes('card-fill'), codes(wrongFill).join());
      assert.ok(codes(wrongFill).includes('card-contrast'), 'dark text on a black card: ' + codes(wrongFill).join());
      const panelLifts = await run(pageHtml({ extraCss: '[data-k="panel-accent"]:hover{transform:translateY(-4px)}' }));
      assert.deepStrictEqual(codes(panelLifts), ['card-hover']);
      assert.match(panelLifts.problems[0][1], /^panel-accent hover: the panel moved/);
      const panelScales = await run(pageHtml({ extraCss: '[data-k="panel-surface"]:hover{transform:scale(1.03)}' }));
      assert.ok(codes(panelScales).includes('card-hover'), codes(panelScales).join());
      assert.match(panelScales.problems.find((x) => x[0] === 'card-hover')[1], /^panel-surface hover: the panel moved/);
      const panelShadow = await run(pageHtml({ extraCss: '[data-k="panel-bg"]:hover{box-shadow:0 8px 24px rgba(0,0,0,.3)}' }));
      assert.deepStrictEqual(codes(panelShadow), ['card-hover']);
      const noLift = await run(pageHtml({ extraCss: '[data-k="card-bg"]:hover{transform:none}' }));
      assert.deepStrictEqual(codes(noLift), ['card-hover']);
      const noShadow = await run(pageHtml({ extraCss: '[data-k="column-2"]:hover{box-shadow:none}' }));
      assert.deepStrictEqual(codes(noShadow), ['card-hover']);
      const restShadow = await run(pageHtml({ extraCss: '[data-k="card-dark"]{box-shadow:0 4px 8px rgba(0,0,0,.3) !important}' }));
      assert.ok(codes(restShadow).includes('card-shadow'), codes(restShadow).join());
      const pale = await run(pageHtml({ paraColor: '#cccccc' }));
      assert.ok(codes(pale).length >= 12 && codes(pale).every((c) => c === 'card-contrast'), 'pale paragraph text on every card: ' + codes(pale).join());
      const unclipped = await run(pageHtml({ extraCss: '[data-k="panel-clip"]{overflow:visible}' }));
      assert.deepStrictEqual(codes(unclipped), ['card-clip']);

      // Grounds, button panels and the featured card: each new code has its own departure.
      const wrongGround = await run(pageHtml({ extraCss: '[data-k="inverted-card-bg"]{background:#8a8a8a}' }));
      assert.ok(codes(wrongGround).includes('ground-fill') && codes(wrongGround).includes('card-fill'), 'a wrong ground fill (computed and painted): ' + codes(wrongGround).join());
      assert.match(wrongGround.problems.find((x) => x[0] === 'ground-fill')[1], /^inverted-card-bg painted fill #8a8a8a differs from the resolved ground fill #1c1a17/);
      // Something drawn over a correct ground (the computed style stays right): only the painted fill gives it away.
      const overlaid = await run(pageHtml({ extraCss: '[data-k="accent-tile-surface"]{position:relative}[data-k="accent-tile-surface"]::after{content:"";position:absolute;inset:0;background:#f4f4f4}' }));
      assert.ok(codes(overlaid).includes('ground-fill'), 'a ground covered by something else: ' + codes(overlaid).join());
      assert.ok(!codes(overlaid).includes('card-fill'), 'the computed fill is still right, so only ground-fill fires');
      const unreadable = await run(pageHtml({ extraCss: '[data-kb="panel-buttons-bg:0"]{color:#eeeeee}' }));
      assert.ok(unreadable.problems.some(([c, d]) => c === 'panel-button' && /^panel-buttons-bg:0 .*label/.test(d)), 'a label that is unreadable on the inverted panel: ' + JSON.stringify(unreadable.problems));
      const hoverColours = await run(pageHtml({ extraCss: '[data-kb="panel-buttons-surface:1"]:hover{background:#c00000}' }));
      assert.ok(hoverColours.problems.some(([c, d]) => c === 'panel-button' && /hover: background-color/.test(d)), 'hover colours that are not the resolved ones: ' + JSON.stringify(hoverColours.problems));
      // The one bounded hover retry (a timing miss must not fail a cell, a persistent defect must). lateHover moves the page's own :hover rule
      // of one element into a script that applies the same declarations 800 ms after the pointer enters, while a 400 ms opacity transition makes
      // the runner wait 550 ms the first time and 1100 ms the second: the first measurement misses, the retry lands.
      const lateHover = (attr, key, ms) => (page) => page.evaluate(([a, k, d]) => {
        const sel = `[${a}="${k}"]:hover`; const decl = [];
        for (const sheet of document.styleSheets) for (let i = sheet.cssRules.length - 1; i >= 0; i--) {
          const r = sheet.cssRules[i];
          if (r.selectorText === sel) { for (const prop of r.style) decl.push([prop, r.style.getPropertyValue(prop)]); sheet.deleteRule(i); }
        }
        if (!decl.length) throw new Error('no :hover rule for ' + sel);
        const el = document.querySelector(`[${a}="${k}"]`); let timer = null;
        el.addEventListener('mouseenter', () => { clearTimeout(timer); timer = setTimeout(() => decl.forEach(([pr, v]) => el.style.setProperty(pr, v)), d); });
        el.addEventListener('mouseleave', () => { clearTimeout(timer); decl.forEach(([pr]) => el.style.removeProperty(pr)); });
      }, [attr, key, ms]);
      const OPACITY = (attr, key) => `[${attr}="${key}"]{transition:opacity 400ms}`;
      const lateButton = await run(pageHtml({ extraCss: OPACITY('data-kb', 'panel-buttons-surface:1') }), 1280, lateHover('data-kb', 'panel-buttons-surface:1', 800));
      assert.deepStrictEqual(lateButton.problems, [], 'a panel button whose hover lands after the first wait passes after the retry: ' + JSON.stringify(lateButton.problems));
      const lateCard = await run(pageHtml({ extraCss: OPACITY('data-k', 'card-bg') }), 1280, lateHover('data-k', 'card-bg', 800));
      assert.deepStrictEqual(lateCard.problems, [], 'a card whose hover lands after the first wait passes after the retry: ' + JSON.stringify(lateCard.problems));
      // A hover that never lands still fails, with the same code and both observations in the text.
      const neverButton = await run(pageHtml({ extraCss: OPACITY('data-kb', 'panel-buttons-surface:1') }), 1280, lateHover('data-kb', 'panel-buttons-surface:1', 60000));
      assert.ok(neverButton.problems.length > 0 && neverButton.problems.every(([c]) => c === 'panel-button' || c === 'contrast' || c === 'lift' || c === 'shadow'), 'a button that never changes on hover: ' + JSON.stringify(neverButton.problems));
      assert.ok(neverButton.problems.some(([c, d]) => /^panel-buttons-surface:1 hover: .*\(retried once: first hover: .*, second hover: .*\)$/.test(d)), 'both attempts are named: ' + JSON.stringify(neverButton.problems));
      const neverCard = await run(pageHtml({ extraCss: OPACITY('data-k', 'card-bg') }), 1280, lateHover('data-k', 'card-bg', 60000));
      assert.ok(neverCard.problems.some(([c, d]) => c === 'card-hover' && /^card-bg hover: translateY .*\(retried once: first hover: translateY .*, second hover: translateY /.test(d)), 'a card that never lifts: ' + JSON.stringify(neverCard.problems));
      // An overflow that shows only on the FIRST (missed) hover attempt is not hidden by a clean retry: the comparison is forgiven, the overflow is reported once.
      const firstOnlyOverflow = await run(pageHtml({ extraCss: OPACITY('data-k', 'card-bg') }), 1280, async (page) => {
        await lateHover('data-k', 'card-bg', 800)(page);
        await page.evaluate(() => { const el = document.querySelector('[data-k="card-bg"]'); let used = false; el.addEventListener('mouseenter', () => { if (!used) { used = true; el.style.marginLeft = '-60px'; } }); el.addEventListener('mouseleave', () => { el.style.marginLeft = ''; }); });
      });
      const overflows = firstOnlyOverflow.problems.filter(([c]) => c === 'overflow');
      assert.strictEqual(overflows.length, 1, 'the first attempt\'s overflow is reported once: ' + JSON.stringify(firstOnlyOverflow.problems));
      assert.match(overflows[0][1], /^card-bg hover: card .* outside its container/);
      assert.strictEqual(firstOnlyOverflow.problems.length, 1, 'the hover comparison itself is forgiven by the retry: ' + JSON.stringify(firstOnlyOverflow.problems));
      // A wrong hover colour (the page's own :hover rule, unlike the removed ones above) fails the same way, retried once.
      assert.ok(hoverColours.problems.some(([c, d]) => c === 'panel-button' && /hover: background-color .*\(retried once: first hover: .*, second hover: /.test(d)), 'a wrong hover colour names both attempts: ' + JSON.stringify(hoverColours.problems));
      const invisibleBorder = await run(pageHtml({ extraCss: '[data-kb="panel-buttons-bg:1"]{border-width:2px;border-color:#1c1a17}' }));
      assert.ok(invisibleBorder.problems.some(([c, d]) => c === 'panel-button' && /^panel-buttons-bg:1 rest: border-top .* is 1:1, needs 3/.test(d)), 'a transparent button whose drawn border is the panel colour: ' + JSON.stringify(invisibleBorder.problems));
      const flatFeatured = await run(pageHtml({ extraCss: '[data-k="column-2"]{background:#ffffff}' }));
      assert.ok(codes(flatFeatured).includes('featured'), 'a featured card no more prominent than its neighbours: ' + codes(flatFeatured).join());
      assert.match(flatFeatured.problems.find((x) => x[0] === 'featured')[1], /^column-2 prominence .*needs 1\.5x/);
      const featuredPhone = await run(pageHtml({ extraCss: '[data-k="column-2"]{background:#ffffff}' }), 400);
      assert.ok(codes(featuredPhone).includes('featured'), 'the featured check also runs at 400px');
      // A fixed bar (the logged-in preview's WordPress admin bar) scrolled over a card must not disturb the painted-fill sample.
      const bar = await run(pageHtml({ extraCss: '#bar{position:fixed;top:0;left:0;right:0;height:32px;background:#23282d;z-index:9}' }), 1280, (page) => page.evaluate(() => { const b = document.createElement('div'); b.id = 'bar'; document.body.appendChild(b); const h = [...document.querySelectorAll('.et_pb_heading')].find((n) => n.textContent.trim() === 'Card on accent'); window.scrollTo(0, h.closest('.et_pb_group').getBoundingClientRect().top + window.scrollY - 5); }));
      assert.deepStrictEqual(bar.problems, [], JSON.stringify(bar.problems));
      // A fixed element INSIDE a card (a sticky badge, an admin-bar widget) is hidden once for the fill sample and restored to what it was:
      // it is not tagged twice, so it never stays hidden (and one that was hidden before stays hidden).
      for (const [initial, expected] of [['', 'visible'], ['hidden', 'hidden']]) {
        const pg = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        await pg.setContent(pageHtml());
        await pg.evaluate((v) => { const c = document.querySelector('[data-aied-card="card-bg"]') || document.querySelector('[data-k="card-bg"]'); const f = document.createElement('div'); f.id = 'fx'; f.textContent = 'badge'; f.style.cssText = 'position:fixed;right:4px;bottom:4px;width:20px;height:20px;background:#c00'; if (v) f.style.visibility = v; c.appendChild(f); }, initial);
        const r = await checkCards(pg, { width: 1280, expects, buttonExpects, hairline: HAIRLINE });
        assert.deepStrictEqual(r.problems, [], JSON.stringify(r.problems));
        const after = await pg.evaluate(() => { const f = document.getElementById('fx'); return { vis: getComputedStyle(f).visibility, tagged: Object.keys(f.dataset) }; });
        assert.strictEqual(after.vis, expected, 'a fixed element inside a card is restored (was ' + JSON.stringify(initial) + ')');
        assert.deepStrictEqual(after.tagged, [], 'no leftover data attributes');
        await pg.close();
      }
      const lost = await run(pageHtml({ drop: ['panel-surface'] }));
      assert.deepStrictEqual(codes(lost), ['cards']);
      assert.match(lost.problems[0][1], /19 cards rendered, expected 20 \(missing: panel-surface\)/);
      assert.strictEqual(lost.cardsChecked, 0, 'a page with a card missing checks nothing else');
      const notStacked = await run(pageHtml({ extraCss: '@media (max-width:600px){.et-l--post .three{display:flex}}' }), 400);
      assert.ok(codes(notStacked).includes('overflow'), codes(notStacked).join());
      const wide = await run(pageHtml({ extraCss: '[data-k="card-bg"]{width:900px}' }), 400);
      assert.ok(codes(wide).includes('overflow'), 'a card wider than the phone: ' + codes(wide).join());

    }

    // ---- items: a real click on an item's title ----
    const FAST = 60; // the synthetic page switches at once; Divi's own slide (700 ms) is the production default of checkItems
    const runItems = async (html, { width = 1280, exps = itemExpects, lists = itemLists, before = null } = {}) => {
      const p = await browser.newPage({ viewport: { width, height: 900 } });
      await p.setContent(html);
      if (before) await before(p);
      const r = await checkItems(p, { width, expects: exps, lists, hairline: HAIRLINE, slideFloorMs: FAST });
      await p.close();
      return r;
    };
    const iokay = await runItems(itemPageHtml());
    assert.deepStrictEqual(iokay.problems, [], JSON.stringify(iokay.problems));
    assert.strictEqual(iokay.itemsChecked, NI);
    assert.strictEqual(iokay.observations.length, NI);
    assert.ok(iokay.observations.every((o) => o.click && o.click.after), 'every block was clicked');
    const acc = iokay.observations.find((o) => o.key === 'accordion-bg');
    assert.deepStrictEqual([acc.click.index, acc.click.before, acc.click.after], [1, [true, false, false], [false, true, false]], 'the first closed item opens and the open one closes');
    assert.deepStrictEqual(iokay.observations.find((o) => o.key === 'toggle-open-bg').click.after, [false], 'an open toggle closes');
    assert.deepStrictEqual(iokay.observations.find((o) => o.key === 'toggle-closed-bg').click.after, [true], 'a closed toggle opens');
    assert.ok(acc.rest[0].fill === '#fff3d6' || K.sameColour(acc.rest[0].fill, '#fff3d6'), 'the painted open fill was measured: ' + acc.rest[0].fill);
    assert.ok(K.sameColour(acc.rest[1].fill, itemExpects[0].closed.fill), 'and the painted closed fill');
    assert.ok(K.sameColour(acc.final[1].fill, '#fff3d6') && K.sameColour(acc.final[0].fill, itemExpects[0].closed.fill), 'after the click the states have traded places, and the painted fills with them');
    const iphone = await runItems(itemPageHtml(), { width: 400 });
    assert.deepStrictEqual(iphone.problems, [], JSON.stringify(iphone.problems));
    const slow = await runItems(itemPageHtml({ slowMs: 150 }));
    assert.deepStrictEqual(slow.problems, [], 'a class switch that comes 150 ms after the click is waited for: ' + JSON.stringify(slow.problems));

    // Departures, each caught with its own code.
    const ic = (r) => [...new Set(r.problems.map((x) => x[0]))]; // distinct codes: a departure that persists is reported at rest and again after the click
    const iLostBlock = await runItems(itemPageHtml({ drop: ['toggle-open-bg'] }));
    assert.deepStrictEqual(ic(iLostBlock), ['items']);
    assert.match(iLostBlock.problems[0][1], /^9 of 10 item blocks rendered as the sheet says: toggle-open-bg is not in the page/);
    assert.strictEqual(iLostBlock.itemsChecked, 0, 'a page with a block missing checks nothing else');
    const shortAcc = await runItems(itemPageHtml({ shortAccordion: 'accordion-surface' }));
    assert.match(shortAcc.problems[0][1], /accordion-surface renders 2 items, expected 3/);
    const iWrongBorder = await runItems(itemPageHtml({ extraCss: '[data-s="accordion-surface"] .et_pb_toggle{border-left-width:3px}' }));
    assert.ok(ic(iWrongBorder).includes('item-border') && ic(iWrongBorder).every((c) => ['item-border', 'item-contrast'].includes(c)), ic(iWrongBorder).join());
    assert.match(iWrongBorder.problems.find((x) => x[0] === 'item-border')[1], /^accordion-surface item 1 rest: border-left-width 3px, expected 1px/);
    const iWrongRadius = await runItems(itemPageHtml({ extraCss: '[data-s="toggle-closed-surface"]{border-bottom-left-radius:9px}' }));
    assert.deepStrictEqual(ic(iWrongRadius), ['item-radius']);
    const iWrongFill = await runItems(itemPageHtml({ extraCss: '[data-s="toggle-closed-bg"]{background:#e0e0e0 !important}' }));
    assert.ok(ic(iWrongFill).includes('item-fill'), ic(iWrongFill).join());
    assert.match(iWrongFill.problems.find((x) => x[0] === 'item-fill')[1], /^toggle-closed-bg item 1 rest: background-color rgb\(224, 224, 224\)/);
    const iWrongTitle = await runItems(itemPageHtml({ extraCss: '[data-s="accordion-accent"] .et_pb_toggle_close > .et_pb_toggle_title{color:#445566 !important}' }));
    assert.ok(ic(iWrongTitle).includes('item-title'), ic(iWrongTitle).join());
    const iWrongBody = await runItems(itemPageHtml({ extraCss: '[data-s="accordion-dark"] .et_pb_toggle_content p{color:#b0b0b0 !important}' }));
    assert.ok(ic(iWrongBody).includes('item-body') && ic(iWrongBody).includes('item-contrast'), 'a grey answer on the item fill: ' + ic(iWrongBody).join());
    const iNoIcon = await runItems(itemPageHtml({ extraCss: '[data-s="toggle-item-surface"].et_pb_toggle_close > .et_pb_toggle_title::before{content:none !important}' }));
    assert.deepStrictEqual(ic(iNoIcon), ['item-icon']);
    assert.match(iNoIcon.problems[0][1], /no icon is drawn on the closed item/);
    const iWrongIcon = await runItems(itemPageHtml({ extraCss: '[data-s="accordion-bg"] .et_pb_toggle_close > .et_pb_toggle_title::before{color:#ff0000 !important}' }));
    assert.ok(ic(iWrongIcon).includes('item-icon'), ic(iWrongIcon).join());
    const iShadowed = await runItems(itemPageHtml({ extraCss: '[data-s="toggle-open-surface"]{box-shadow:0 4px 8px rgba(0,0,0,.3) !important}' }));
    assert.ok(ic(iShadowed).includes('item-shadow'), 'a rule row with a shadow: ' + ic(iShadowed).join());
    const iWrongPad = await runItems(itemPageHtml({ extraCss: '[data-s="toggle-item-bg"]{padding-left:10px}' }));
    assert.deepStrictEqual(ic(iWrongPad), ['item-padding']);
    // Contrast on its own: the page agrees with its (pale) expectations, so only the painted-fill rule can object.
    const paleExps = clone(itemExpects); paleExps[0].closed.title = '#d8d8d8'; paleExps[0].open.title = '#d8d8d8';
    const paleTitle = await runItems(itemPageHtml({ exps: paleExps }), { exps: paleExps });
    assert.ok(ic(paleTitle).length > 0 && ic(paleTitle).every((c) => c === 'item-contrast'), 'pale titles that match the markup are still unreadable: ' + ic(paleTitle).join());
    assert.match(paleTitle.problems[0][1], /^accordion-bg item 1 title colour #d8d8d8 on the painted item fill/);
    assert.ok(paleTitle.problems.some(([, d]) => /\(after click\)/.test(d)), 'the contrast is judged again on the states after the click');

    // The click: nothing happens, the open item stays open, the answer stays hidden, the fill stays closed.
    const dead = await runItems(itemPageHtml({ noClick: ['toggle-closed-surface'] }));
    assert.deepStrictEqual(ic(dead), ['item-click']);
    assert.match(dead.problems[0][1], /^toggle-closed-surface item 1 \(Rule toggle closed on surface\) did not open after a click on its title/);
    const stays = await runItems(itemPageHtml({ noClose: ['accordion-dark'] }));
    assert.deepStrictEqual(ic(stays), ['item-click']);
    assert.match(stays.problems[0][1], /^accordion-dark item 1 \(previously open\) did not close when item 2 opened/);
    const hidden = await runItems(itemPageHtml({ extraCss: '[data-s="toggle-closed-bg"].et_pb_toggle_open .et_pb_toggle_content{display:none}' }));
    assert.ok(ic(hidden).includes('item-click') && hidden.problems.some(([, d]) => /is open but its answer is not shown after the click/.test(d)), 'an item that opens but whose answer stays hidden: ' + ic(hidden).join());
    const stuckFill = await runItems(itemPageHtml({ stuck: ['toggle-closed-bg'] }));
    assert.deepStrictEqual(ic(stuckFill).filter((c) => c !== 'item-title' && c !== 'item-fill'), [], ic(stuckFill).join());
    assert.ok(stuckFill.problems.some(([c, d]) => c === 'item-fill' && /^toggle-closed-bg item 1 after click: background-color .*expected #fff3d6 \(open item\)/.test(d)), 'a state fill that stays closed after the click: ' + JSON.stringify(stuckFill.problems));
    // Overflow covers the items; a fixed bar scrolled over an item does not disturb the painted-fill sample or the click.
    const wideItem = await runItems(itemPageHtml({ extraCss: '[data-s="accordion-bg"]{width:900px}' }), { width: 400 });
    assert.ok(ic(wideItem).includes('overflow'), 'an accordion wider than the phone: ' + ic(wideItem).join());
    const iBar = await runItems(itemPageHtml({ extraCss: '#bar{position:fixed;top:0;left:0;right:0;height:32px;background:#23282d;z-index:9}' }), { before: (page) => page.evaluate(() => { const b = document.createElement('div'); b.id = 'bar'; document.body.appendChild(b); const h = [...document.querySelectorAll('.et_pb_toggle_title')].find((n) => n.textContent.trim() === 'Accordion on accent one'); window.scrollTo(0, h.closest('.et_pb_toggle').getBoundingClientRect().top + window.scrollY - 5); }) });
    assert.deepStrictEqual(iBar.problems, [], JSON.stringify(iBar.problems));
    const emptyPage = await runItems(itemPageHtml({ drop: K.ITEM_SLOTS.map((s) => s.key) }));
    assert.deepStrictEqual(ic(emptyPage), ['items'], 'a page with no item at all fails');

    // ---- forms: the fields, the submit with a real hover, the captcha, the container ----
    const runForms = async (html, { width = 1280, exps = formExpects, counts = formCounts, before = null } = {}) => {
      const p = await browser.newPage({ viewport: { width, height: 900 } });
      await p.setContent(html);
      if (before) await before(p);
      const r = await checkForms(p, { width, expects: exps, counts });
      await p.close();
      return r;
    };
    const fc = (r) => [...new Set(r.problems.map((x) => x[0]))]; // distinct codes
    const fokay = await runForms(formPageHtml());
    assert.deepStrictEqual(fokay.problems, [], JSON.stringify(fokay.problems));
    assert.strictEqual(fokay.formsChecked, NF);
    assert.strictEqual(fokay.observations.length, NF);
    assert.ok(fokay.observations.every((o) => o.fieldFills.length === 2 && o.fieldFills.every((x, j) => K.sameColour(x, formExpects[0].field.fill) || K.sameColour(x, formExpects[1].field.fill))), 'the painted field fills were measured: ' + JSON.stringify(fokay.observations.map((o) => o.fieldFills)));
    assert.deepStrictEqual(fokay.observations.map((o) => K.sameColour(o.surface, o.key === 'form-bg' ? EDITORIAL.bg : EDITORIAL.surface)), [true, true], 'the surface behind the submit button is the band');
    assert.ok(fokay.observations.every((o) => o.contrast.captcha >= 4.5 && o.contrast.fields[0].ink >= 4.5 && o.contrast.submit.hover.label >= 4.5), 'the contrast was measured: ' + JSON.stringify(fokay.observations[0].contrast));
    const fphone = await runForms(formPageHtml(), { width: 400 });
    assert.deepStrictEqual(fphone.problems, [], JSON.stringify(fphone.problems));
    const ftablet = await runForms(formPageHtml(), { width: 900 });
    assert.deepStrictEqual(ftablet.problems, [], JSON.stringify(ftablet.problems));
    // An underline kit: bottom-only field frames and a bottom-only submit whose edge keeps its rest colour on hover.
    const funder = await runForms(formPageHtml({ exps: underExpects }), { exps: underExpects });
    assert.deepStrictEqual(funder.problems, [], JSON.stringify(funder.problems));
    const fdual = await runForms(formPageHtml({ extraCss: '[data-f="form-bg"] .et_pb_contact_submit:hover{border-bottom-color:#10b981}' , exps: underExpects }), { exps: underExpects });
    assert.deepStrictEqual(fc(fdual), ['form-button']);
    assert.match(fdual.problems[0][1], /^form-bg submit hover: the underline's bottom edge is rgb\(16, 185, 129\) on hover, expected its rest colour #1c1a17/);
    const ffull = await runForms(formPageHtml({ extraCss: '[data-f="form-surface"] input.input:not(.et_pb_contact_captcha){border-top:2px solid #1c1a17}', exps: underExpects }), { exps: underExpects });
    assert.ok(fc(ffull).includes('form-field-border'), 'a full frame where the underline kit draws one edge: ' + fc(ffull).join());

    // The count: a form, a field or the captcha missing, nothing else is then checked; a page with no form at all fails.
    const flost = await runForms(formPageHtml({ drop: ['form-surface'] }));
    assert.deepStrictEqual(fc(flost), ['form']);
    assert.match(flost.problems[0][1], /^1 of 2 forms rendered as the sheet says: form-surface is not in the page/);
    assert.strictEqual(flost.formsChecked, 0, 'a page with a form missing checks nothing else');
    const ffield = await runForms(formPageHtml({ dropField: 'form-surface' }));
    assert.match(ffield.problems[0][1], /form-surface renders 1 fields, expected 2/);
    const fnocap = await runForms(formPageHtml({ noCaptcha: ['form-bg'] }));
    assert.match(fnocap.problems[0][1], /form-bg shows no captcha question/);
    const fnone = await runForms(formPageHtml({ drop: K.FORM_SLOTS.map((s) => s.key) }));
    assert.deepStrictEqual(fc(fnone), ['form'], 'a page with no form at all fails');
    assert.deepStrictEqual(K.emptyFormRunProblems({ formsChecked: fnone.formsChecked }), ['no form was checked: the form selection was empty']);

    // The fields.
    const IN = 'input.input:not(.et_pb_contact_captcha)';
    const fFill = await runForms(formPageHtml({ extraCss: `[data-f="form-surface"] ${IN}{background:#e0e0e0 !important}` }));
    assert.ok(fc(fFill).includes('form-field-fill'), fc(fFill).join());
    assert.match(fFill.problems.find((x) => x[0] === 'form-field-fill')[1], /^form-surface field 1 rest: background-color rgb\(224, 224, 224\), expected #faf7f2/);
    // Something painted over a correct field (the computed style stays right): only the painted fill gives it away.
    const fOver = await runForms(formPageHtml({ extraCss: '[data-f="form-bg"] p.et_pb_contact_field{position:relative}[data-f="form-bg"] p.et_pb_contact_field::after{content:"";position:absolute;left:0;right:0;top:0;bottom:12px;background:#f4e6c8}' }));
    assert.ok(fc(fOver).includes('form-field-fill'), 'a field covered by something else: ' + fc(fOver).join());
    assert.ok(fOver.problems.some(([c, d]) => c === 'form-field-fill' && /painted fill #f4e6c8 differs from the computed/.test(d)), JSON.stringify(fOver.problems.slice(0, 3)));
    // A field the colour of its container (the form on the bg band with a bg field).
    const fSame = await runForms(formPageHtml({ extraCss: `[data-f="form-bg"] ${IN}{background:${EDITORIAL.bg} !important}` }));
    assert.ok(fSame.problems.some(([c, d]) => c === 'form-field-fill' && /never matches its container/.test(d)), 'a field that matches its container: ' + JSON.stringify(fSame.problems.slice(0, 4)));
    const fBorder = await runForms(formPageHtml({ extraCss: `[data-f="form-bg"] ${IN}{border-left-width:3px}` }));
    assert.ok(fc(fBorder).every((c) => ['form-field-border', 'form-contrast'].includes(c)) && fc(fBorder).includes('form-field-border'), fc(fBorder).join());
    assert.match(fBorder.problems.find((x) => x[0] === 'form-field-border')[1], /^form-bg field 1 rest: border-left-width 3px, expected 1px/);
    const fStyle = await runForms(formPageHtml({ extraCss: `[data-f="form-surface"] ${IN}{border-bottom-style:dashed}` }));
    assert.deepStrictEqual(fc(fStyle), ['form-field-border']);
    const fRadius = await runForms(formPageHtml({ extraCss: `[data-f="form-surface"] ${IN}{border-top-left-radius:2px}` }));
    assert.deepStrictEqual(fc(fRadius), ['form-field-radius']);
    assert.match(fRadius.problems[0][1], /^form-surface field 1 rest: borderTopLeftRadius 2px, expected 12px/);
    const fInk = await runForms(formPageHtml({ extraCss: `[data-f="form-bg"] ${IN}{color:#3355aa}` }));
    assert.deepStrictEqual(fc(fInk), ['form-field-ink']);
    const fPh = await runForms(formPageHtml({ extraCss: `[data-f="form-surface"] ${IN}::placeholder{color:#444444}` }));
    assert.deepStrictEqual(fc(fPh), ['form-field-placeholder']);
    assert.match(fPh.problems[0][1], /^form-surface field 1 rest: ::placeholder color rgb\(68, 68, 68\), expected #6b645a/);
    const fPad = await runForms(formPageHtml({ extraCss: `[data-f="form-bg"] ${IN}{padding-left:5px}` }));
    assert.deepStrictEqual(fc(fPad), ['form-field-padding']);
    const fShadow = await runForms(formPageHtml({ extraCss: `[data-f="form-bg"] ${IN}{box-shadow:0 4px 8px rgba(0,0,0,.3)}` }));
    assert.deepStrictEqual(fc(fShadow), ['form-field-border']);

    // The submit button, measured with a real hover.
    const SUB = '.et_pb_contact_submit';
    const fbFill = await runForms(formPageHtml({ extraCss: `[data-f="form-bg"] ${SUB}{background:#eeeeee !important}` }));
    assert.ok(fc(fbFill).includes('form-button'), fc(fbFill).join());
    const fbHover = await runForms(formPageHtml({ extraCss: `[data-f="form-surface"] ${SUB}:hover{background:#c00000 !important}` }));
    assert.deepStrictEqual(fc(fbHover), ['form-button']);
    assert.match(fbHover.problems[0][1], /^form-surface submit hover: background-color rgb\(192, 0, 0\), expected /);
    const fbLift = await runForms(formPageHtml({ extraCss: `[data-f="form-bg"] ${SUB}:hover{transform:none !important}` }));
    assert.deepStrictEqual(fc(fbLift), ['form-button']);
    assert.match(fbLift.problems[0][1], /^form-bg submit lift: hover: translateY button 0px/);
    const fbShadow = await runForms(formPageHtml({ extraCss: `[data-f="form-bg"] ${SUB}:hover{box-shadow:none !important}` }));
    assert.deepStrictEqual(fc(fbShadow), ['form-button']);
    const fbEdge = await runForms(formPageHtml({ extraCss: `[data-f="form-surface"] ${SUB}{border-left-width:3px}` }));
    assert.deepStrictEqual(fc(fbEdge), ['form-button']);
    const fbPad = await runForms(formPageHtml({ extraCss: `[data-f="form-bg"] ${SUB}{padding-right:3px}` }));
    assert.deepStrictEqual(fc(fbPad), ['form-button']);
    const fbDead = await runForms(formPageHtml({ extraCss: `[data-f="form-bg"] ${SUB}{pointer-events:none}` }));
    assert.ok(fbDead.problems.some(([c, d]) => c === 'form-button' && /^form-bg submit could not be hovered/.test(d)), 'a submit button that cannot be hovered is a failure, not a skip: ' + JSON.stringify(fbDead.problems.slice(0, 3)));

    // The captcha and the container.
    const fCap = await runForms(formPageHtml({ extraCss: '[data-f="form-surface"] .et_pb_contact_captcha_question{color:#666666}' }));
    assert.deepStrictEqual(fc(fCap), ['form-captcha']);
    assert.match(fCap.problems[0][1], /^form-surface rest: captcha question colour rgb\(102, 102, 102\), expected #1c1a17/);
    const fContShadow = await runForms(formPageHtml({ extraCss: '[data-f="form-bg"]{box-shadow:0 8px 24px rgba(0,0,0,.3)}' }));
    assert.deepStrictEqual(fc(fContShadow), ['form-container']);
    assert.match(fContShadow.problems[0][1], /^form-bg rest: the form container has a box-shadow/);
    const fContLift = await runForms(formPageHtml({ extraCss: '[data-f="form-surface"]:hover{transform:translateY(-4px)}' }));
    assert.deepStrictEqual(fc(fContLift), ['form-container']);
    assert.match(fContLift.problems[0][1], /^form-surface hover: the form container is transformed/);
    const fContFrame = await runForms(formPageHtml({ extraCss: '[data-f="form-bg"]{border:2px solid #1c1a17}' }));
    assert.ok(fc(fContFrame).includes('form-container'), fc(fContFrame).join());

    // Contrast on its own: the page agrees with its (pale) expectations, so only the painted-surface rule can object.
    const paleInk = clone(formExpects); paleInk[0].field.ink = '#d8d8d8';
    const fPale = await runForms(formPageHtml({ exps: paleInk }), { exps: paleInk });
    assert.ok(fc(fPale).length > 0 && fc(fPale).every((c) => c === 'form-contrast'), 'pale field text that matches the markup is still unreadable: ' + fc(fPale).join());
    assert.match(fPale.problems[0][1], /^form-bg field 1 ink #d8d8d8 on #ffffff is /);
    const paleCap = clone(formExpects); paleCap[1].captcha.ink = '#dddddd';
    const fPaleCap = await runForms(formPageHtml({ exps: paleCap }), { exps: paleCap });
    assert.ok(fc(fPaleCap).every((c) => c === 'form-contrast') && fPaleCap.problems.some(([, d]) => /^form-surface captcha ink /.test(d)), fc(fPaleCap).join());
    const faintFrame = clone(formExpects); for (const side of ['top', 'right', 'bottom', 'left']) faintFrame[0].field[side].color = '#f1ece2';
    const fFaint = await runForms(formPageHtml({ exps: faintFrame }), { exps: faintFrame });
    assert.ok(fc(fFaint).every((c) => c === 'form-contrast') && fFaint.problems.some(([, d]) => /frame top against the field fill/.test(d)), fc(fFaint).join());
    // Ruling 6: the boundary against the form behind is the better of the frame and the fill. A frame that is 3:1 on the white field but under 3:1 on the warm form,
    // with a fill that is no stronger, leaves NO edge that reaches 3:1 (boundary); a dark field with the same grey frame passes (the fill is the edge).
    const lowBoundary = clone(formExpects); for (const side of ['top', 'right', 'bottom', 'left']) lowBoundary[0].field[side].color = '#949494';
    const fLowB = await runForms(formPageHtml({ exps: lowBoundary }), { exps: lowBoundary });
    assert.deepStrictEqual(fc(fLowB), ['form-contrast']);
    assert.ok(fLowB.problems.some(([, d]) => /^form-bg field [12] boundary: neither the frame \(best 2\.84:1\) nor the fill #ffffff \(1\.07:1\)/.test(d)), JSON.stringify(fLowB.problems));
    const darkField = clone(formExpects);
    for (const side of ['top', 'right', 'bottom', 'left']) darkField[0].field[side].color = '#9a9a9a';
    darkField[0].field.fill = '#1c1a17'; darkField[0].field.ink = '#ffffff'; darkField[0].field.placeholder = '#ffffff';
    const fDark = await runForms(formPageHtml({ exps: darkField }), { exps: darkField });
    assert.deepStrictEqual(fDark.problems, [], 'the fill is 16:1 on the form, so a 2.6:1 frame still leaves a boundary: ' + JSON.stringify(fDark.problems));
    const paleLabel = clone(formExpects); paleLabel[0].button.rest.label = paleLabel[0].button.rest.fill;
    const fLabel = await runForms(formPageHtml({ exps: paleLabel }), { exps: paleLabel });
    assert.ok(fLabel.problems.some(([c, d]) => c === 'form-contrast' && /^form-bg submit rest: label /.test(d)), JSON.stringify(fLabel.problems.slice(0, 3)));

    // Clip and overflow.
    const fClip = await runForms(formPageHtml({ extraCss: `[data-f="form-bg"]{overflow:hidden}[data-f="form-bg"] p.et_pb_contact_field{width:130%}` }));
    assert.ok(fc(fClip).includes('form-clip'), 'a field cut by the clipping form container: ' + fc(fClip).join());
    const fWide = await runForms(formPageHtml({ extraCss: `[data-f="form-bg"] ${IN}{width:900px}` }), { width: 400 });
    assert.ok(fc(fWide).includes('overflow'), 'a field wider than the phone: ' + fc(fWide).join());
    const fCut = await runForms(formPageHtml({ extraCss: `[data-f="form-surface"] ${SUB}{width:40px;overflow:hidden;white-space:nowrap}` }));
    assert.ok(fc(fCut).includes('form-clip'), 'a submit label that is cut: ' + fc(fCut).join());
    // A fixed bar scrolled over the page, and a fixed element laid over a field, must not disturb the painted samples.
    const fBar = await runForms(formPageHtml({ extraCss: '#bar{position:fixed;top:0;left:0;right:0;height:32px;background:#23282d;z-index:9}' }), { before: (page) => page.evaluate(() => { const b = document.createElement('div'); b.id = 'bar'; document.body.appendChild(b); }) });
    assert.deepStrictEqual(fBar.problems, [], JSON.stringify(fBar.problems));
    for (const [initial, expected] of [['', 'visible'], ['hidden', 'hidden']]) {
      const pg = await browser.newPage({ viewport: { width: 1280, height: 900 } });
      await pg.setContent(formPageHtml());
      await pg.evaluate((v) => {
        const f = document.querySelector('[data-f="form-bg"] input.input');
        const r = f.getBoundingClientRect();
        const x = document.createElement('div'); x.id = 'fx'; x.style.cssText = `position:fixed;left:${r.left + 8}px;top:${r.top + 6}px;width:${Math.max(10, r.width - 16)}px;height:${Math.max(6, r.height - 12)}px;background:#cc0000;z-index:9;visibility:${v || 'visible'}`;
        document.body.appendChild(x);
      }, initial);
      const r = await checkForms(pg, { width: 1280, expects: formExpects, counts: formCounts });
      const after = await pg.evaluate(() => { const f = document.getElementById('fx'); return { vis: getComputedStyle(f).visibility, tagged: Object.keys(f.dataset), style: !!document.getElementById('aied-form-paint') }; });
      if (initial === '') assert.deepStrictEqual(r.problems, [], 'a fixed element over a field is hidden for the painted samples: ' + JSON.stringify(r.problems.slice(0, 3)));
      assert.strictEqual(after.vis, expected, 'a fixed element over a field is restored (was ' + JSON.stringify(initial) + ')');
      assert.deepStrictEqual(after.tagged, [], 'no leftover data attributes');
      assert.strictEqual(after.style, false, 'the injected placeholder style is removed');
      await pg.close();
    }

    console.log('OK kit-card-run, kit-item-run, kit-form-run, kit-media-run and kit-rule-run in a real Chromium on synthetic pages: a matching page passes at 1280 and 400 (items: a real click opens a closed item and closes the open one; forms: a real hover on the submit; images: the img and its wrapper read, a square kit checked), every departure is caught (and a fixed bar over a card, an item or a field is not mistaken for one) with its code');
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });
