#!/usr/bin/env node
/*
 * Dev-only: the effect probe. A shape that passes the validator can still render nothing (Divi ignores a key it does
 * not know and emits no CSS). For every candidate this saves two throwaway draft pages that are identical except for
 * the candidate's `set` values, opens both with the real Divi in Chromium at 1280, 900 and 400 px and reports whether
 * the page changed: the emitted inline <style> text (post ids normalised) or the computed styles of the module's
 * element and its descendants differ. A candidate that changes nothing at any width is DEAD.
 *
 *   node scripts/verify-shapes.cjs <candidates.json> [--out <results.json>]    (needs `make up`; Playwright from ../layoutlab)
 *
 * candidates.json: [{ "id", "block", "base": {block attrs}, "set": {"dotted.path.with.desktop": value},
 *                     "parent": "<block>" (optional), "children": [<block spec>] (optional),
 *                     "expect": "PASS|DEAD|OVERFLOW" (optional), "note": "..." (optional) }]
 *   parent   wraps the candidate block in one block of that kind (e.g. a `group` holding a heading: block heading, parent group).
 *   children a list of block specs ({ block, base, children }) placed INSIDE the candidate block, replacing the default filler
 *            (e.g. block group, children [heading]). Only container blocks (section, row, column, group) take children; a child
 *            has no `set`. Supported blocks: section, row, column, group, heading, text, image, button, blurb, divider,
 *            number-counter, testimonial, icon, group-carousel, blog, contact-form, contact-field, shop, tabs, tab, accordion, accordion-item, circle-counter (the
 *            last four were added for the features recipes task: an empty `tabs` holds two plain `tab` children and an empty `accordion` two plain
 *            `accordion-item` children, in the shapes of the real export fixtures/valid/page-17-newpage.json; the five before them were added for the legacy recipe
 *            module settings task: a `group-carousel` holds `group` children and a `contact-form` holds `contact-field` children, which a
 *            candidate lists in `children`; `blog` renders the site's own posts, `shop` the site's own WooCommerce products; `circle-counter` was
 *            added for the proof and stats recipes task: a title and a number, the content attributes of Divi 5.15.1's circle-counter module.json;
 *            `toggle` was added for the pricing, team, FAQ and story recipes task: a stand-alone toggle with a title and a paragraph, the shape of the
 *            real export fixtures/valid/page-7-home.json); `countdown-timer` was added for the calls to action, gallery, contact and transitions task: a
 *            title and a past end date (Divi's own default), the shape of fixtures/valid/page-17-newpage.json, so the timer shows zeros on every load).
 *            Real-export content shapes are filled in for the content of each, `base` wins.
 *   neighbours  { "before": [<section spec>], "after": [<section spec>] }: plain sections placed before/after the page's top-level section (the candidate section itself, or the
 *            section the probe wraps around any other candidate; added in the sticky-limit task so that a sticky group can be scrolled past its section),
 *            identical in the A and B pages (e.g. a differently coloured section so that a divider is visible). The measured element is the
 *            candidate, never a neighbour. Absent = the page is unchanged.
 *   state    (optional) how the measured element is put in the state under test before it is measured. The same action is done on the
 *            A page (control, base only) and on the B page, so only the candidate's `set` can make them differ:
 *              "hover"   the pointer is moved onto the measured element (Playwright hover) and the page waits `settleMs` (default 1200) so a
 *                        CSS transition has finished; a `.hover.` value (hover background colour, hover box shadow ...) is applied only then.
 *              "scroll"  the page is scrolled so that the CENTRE of the measured element sits at `scrollAt` x the viewport height from the
 *                        top of the viewport (default 0.25; a negative number puts it above the viewport, which is how a sticky element is
 *                        proven: it must still be on screen), then waits `settleMs` (default 1200). The target is computed from the layout
 *                        (offsetTop chain), not from the transformed rectangle. With this state the measured element's bounding rectangle
 *                        (viewport top/left/width/height, rounded) is added to the compared properties as `rect:*`. Give the page height with
 *                        `neighbours` (tall plain sections) so that there is something to scroll. Scroll effects and sticky elements are driven
 *                        by Divi's script on scroll events, so this is the only way to see them.
 *              "click"   (added for the legacy recipe module settings task) the element matched by `clickSelector` (a CSS selector looked up INSIDE the
 *                        measured element, e.g. a carousel's next arrow) is clicked once and the page waits `settleMs` (default 1200); the same click is
 *                        done on the A page. For settings that only act when the visitor operates the module (how far a carousel moves per click).
 *            Absent = the page is measured as loaded.
 *   hoverSelector (optional, only with state "hover") a CSS selector looked up INSIDE the measured element: the pointer is moved onto
 *            that element instead of the measured element's centre (added for the forms adapter probe: the submit button of a contact
 *            form, whose centre is not the form's centre). The same hover is done on the A page.
 *   siblings (optional, not on a section candidate) { "before": [<block spec>], "after": [<block spec>] }: plain blocks placed before / after the
 *            candidate INSIDE ITS IMMEDIATE CONTAINER (the `parent` when given, otherwise the automatic wrapper: a column for a module, a row for a
 *            column ...), identical in the A and B pages. Needed for settings that act against a neighbour (a column `order`, an overlap, a
 *            z-index). The measured element is still the candidate: the probe picks the Nth element of its module class, where N is the number
 *            of `before` siblings of the SAME block name, plus one when the `parent` is of the same block name as the candidate (a group in a
 *            group: the parent comes first); nested descendants of that class are not counted, so do not use them.
 *   wrapperBase (optional) { "<block>": {block attrs} }: attrs merged into the automatic wrapper (or the `parent`) of that block kind, identical in
 *            A and B. e.g. { "row": { two-column structure }, "section": { overflow hidden } }. Applies to the wrappers the probe adds above the
 *            candidate (column, row, section) and to `parent`.
 *   geometry (optional, true) measure where the element sits: the viewport-independent rectangle (document coordinates) of the measured element,
 *            of its parent and of its previous and next element siblings, the area where the measured element overlaps a sibling and WHICH element
 *            is on top at the centre of that overlap (document.elementFromPoint: "measured" | "sibling" | "other"). The rectangle (top, left,
 *            width, height, rounded) is also added to the compared properties as `rect:*`, so a moved element counts as a computed difference.
 *            Result: detail[width].geometry = { control, candidate } (each: rect, parent, prev, next, overlap {area, with, top}, hitCentre:
 *            "measured" when the measured element itself is the one on top at its own centre, "other" when something covers it, "none" when it
 *            has no box or the centre is off the page; hitCentre was added in fix round 1 of the layout task, absent from older result files). Added in the
 *            layout task; absent = nothing changes for older candidate files.
 *   pseudo   (optional) a list of pseudo-elements (e.g. ["::before","::after"]): the computed styles of those pseudo-elements of the measured
 *            element and of every descendant are compared too, as `<index>:<tag>:<pseudo>:<property>` (changedProps shows `::before:color`).
 *            Needed where Divi draws the effect on a pseudo-element (the divider line is `.et_pb_divider:before`, a button icon is
 *            `.et_pb_button:after`): getComputedStyle(element) alone does not see it and the result would be a mere stylesheet difference.
 *            Added in the typography task; absent = nothing is compared beyond the elements, so older candidate files are unchanged.
 *   text     (optional, true) also compare the rendered text of the measured element (`innerText`) as `text:innerText`. For a setting that
 *            changes WHAT is printed rather than how it is styled (the number counter's percent sign is a character, not a style). A change
 *            of text alone is reported as a difference in `changedProps` (`text:innerText`). Added in the typography task; absent = text is not compared.
 *   canvas   (optional, true) also compare what the measured element's <canvas> descendants DRAW: for every canvas, its size and its three most
 *            frequent pixel colours (rgba of every pixel with alpha over 16, by count), as `canvas:<index>:size` and `canvas:<index>:colors`. Divi draws a circle
 *            counter's bar and track on a canvas from script data (barColor / trackColor / trackAlpha), so no computed style or stylesheet text
 *            shows them. Added in the proof and stats recipes task; absent = canvases are not read (older candidate files are unchanged).
 *   settleMs (optional) milliseconds to wait before measuring (default 600; 1200 with a `state`; 3000 for a number or circle counter).
 *   ignore   (optional) computed property names (e.g. ["opacity","transform"]) left out of the comparison on BOTH pages. Needed when the
 *            effect under test is running while the page is measured (an entrance animation changes opacity from frame to frame, so two
 *            loads of the same page never agree on it). The names are listed in the candidate and in its evidence section.
 *   attrs    (optional) attribute names (e.g. ["class","style"]) of the measured element whose values are compared too, as `attr:<name>`.
 *            For settings that Divi's script writes into the element (an entrance animation adds the classes `et_animated fade` and an
 *            inline `animation-duration`). Absent = attributes are not compared (so results of older candidate files are unchanged).
 * "@SITE@" inside a string is replaced by the dev site URL.
 * Diagnostics: with the environment variable AIED_DUMP_DIR=<dir> the rendered page content (.et-l--post) of every measured load is written to
 * <dir>/<candidate id>-<width>-<page id>.html; nothing else changes (added for the legacy recipe module settings task).
 * Results (default fixtures/shapes/probe-results-<candidates file name>.json, never the committed probe-results-5.15.1.json
 * unless asked with --out): [{ id, effect, widths: {"1280","900","400"}, phoneOverflow, detail, error? }]; detail[width] holds the number
 * of computed differences, a sample of six, `overflowPx` (how far the candidate page is wider than the viewport: scrollWidth - innerWidth, 0 when
 * it fits; added in the layout task), and `changedProps` (the distinct property names that differ, e.g. transform, opacity, attr:class,
 * rect:top; added in the motion task, absent from older result files).
 * One line per candidate: PASS | PASS(css-only) | DEAD | OVERFLOW (it changed the page but overflows a phone) | ERROR <id>: reason.
 * PASS needs a measured computed-style difference of the module's element (or its descendants) at some width; when only the emitted
 * stylesheet text differs at every width the result is PASS(css-only) (`cssOnly: true` in the results; the field is absent otherwise) and `expect: "PASS"` disagrees
 * unless the candidate says `"allowCssOnly": true` (promote-shapes.php then promotes it only with that flag, and a `note` should say why).
 * The measured element is the first match inside the page content (.et-l--post), never one of the Theme Builder header/footer.
 * A candidate that ERRORs does not stop the others. Exit 1 when any candidate ERRORs or an `expect` disagrees.
 *
 * Dev site only (localhost). RUN ONE PROBE AT A TIME. Every throwaway page is titled `aied shape probe <runToken> <label>`, where the
 * runToken (process id + 3 random bytes in hex) is made once per run; the run deletes ONLY pages whose title starts with
 * `aied shape probe <runToken> ` (after every candidate, at the end, and on SIGINT/SIGTERM/SIGHUP), so another probe running at the
 * same time, or the leftovers of a crashed run, are never touched. At startup the probe warns when pages with the bare prefix
 * `aied shape probe ` (another run's, or leftovers) exist. `node scripts/verify-shapes.cjs --clean-orphans` deletes ALL pages whose
 * title starts with the bare prefix (an exact prefix filter on the full page list, numeric ids only, never a search) and exits:
 * use it only when no other probe is running.
 */
'use strict';

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');

if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(SITE)) {
  console.error('Dev site only (localhost). Refusing ' + SITE);
  process.exit(2);
}
const USAGE = 'usage: node scripts/verify-shapes.cjs <candidates.json> [--out <results.json>]\n       node scripts/verify-shapes.cjs --clean-orphans';
let candFile = null;
let outArg = null;
let cleanOrphans = false;
{
  const args = process.argv.slice(2);
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--out') { outArg = args[++i]; if (!outArg) { console.error(USAGE); process.exit(2); } }
    else if (args[i] === '--clean-orphans') cleanOrphans = true;
    else if (args[i].startsWith('--') || candFile) { console.error(USAGE); process.exit(2); }
    else candFile = args[i];
  }
}
if (cleanOrphans ? (candFile || outArg) : !candFile) { console.error(USAGE); process.exit(2); }
const outFile = !candFile ? null : outArg ? path.resolve(outArg) : path.join(ROOT, 'fixtures', 'shapes', 'probe-results-' + path.basename(candFile).replace(/\.json$/i, '') + '.json');
const crypto = require('crypto');
const { chromium } = require(PW);

const WIDTHS = [1280, 900, 400];
const V = '5.15.1';
const BS = String.fromCharCode(92);
const wp = (...a) => execFileSync('docker', ['compose', 'exec', '-T', 'wpcli', 'php', '-d', 'memory_limit=1G', '/usr/local/bin/wp', ...a], { cwd: ROOT, encoding: 'utf8' }).trim();

// WordPress's block attribute serialisation: < > & -- as unicode escapes.
const esc = (j) => j.split('--').join(BS + 'u002d' + BS + 'u002d').split('<').join(BS + 'u003c').split('>').join(BS + 'u003e').split('&').join(BS + 'u0026');
const block = (name, attrs, inner) => { const j = esc(JSON.stringify(attrs)); return inner === undefined ? `<!-- wp:divi/${name} ${j} /-->` : `<!-- wp:divi/${name} ${j} -->${inner}<!-- /wp:divi/${name} -->`; };
const setp = (o, p, v) => { const k = p.split('.'); let d = o; for (const x of k.slice(0, -1)) { d = d[x] = d[x] || {}; } d[k[k.length - 1]] = v; };
const withSite = (v) => (typeof v === 'string' ? v.split('@SITE@').join(SITE) : Array.isArray(v) ? v.map(withSite) : v && typeof v === 'object' ? Object.fromEntries(Object.entries(v).map(([k, x]) => [k, withSite(x)])) : v);
const SUPPORTED = ['section', 'row', 'column', 'group', 'heading', 'text', 'image', 'button', 'blurb', 'divider', 'number-counter', 'testimonial', 'icon', 'group-carousel', 'blog', 'contact-form', 'contact-field', 'shop', 'tabs', 'tab', 'accordion', 'accordion-item', 'circle-counter', 'toggle', 'countdown-timer'];
const CONTAINERS = ['section', 'row', 'column', 'group', 'group-carousel', 'contact-form', 'tabs', 'accordion'];
// Content every block needs to render something; shapes are the ones real exports and recipes use. A candidate's `base` wins.
const IMG = '@SITE@/wp-content/plugins/jhmg-ai-editor-for-divi-5/assets/images/hero-blue-1.svg';
const DEFAULT_BASE = {
  section: {},
  row: { module: { advanced: { columnStructure: { desktop: { value: '4_4' } } } } },
  column: { module: { advanced: { type: { desktop: { value: '4_4' } } } } },
  group: {},
  heading: { title: { innerContent: { desktop: { value: 'probe heading' } } } },
  text: { content: { innerContent: { desktop: { value: '<p>probe text</p>' } } } },
  image: { image: { innerContent: { desktop: { value: { src: IMG, alt: 'probe' } } } } },
  button: { button: { innerContent: { desktop: { value: { text: 'Probe', id: 0 } } } } },
  blurb: { title: { innerContent: { desktop: { value: { text: 'probe blurb' } } } }, content: { innerContent: { desktop: { value: '<p>probe</p>' } } } },
  divider: {},
  'number-counter': { title: { innerContent: { desktop: { value: 'PROBE' } } }, number: { innerContent: { desktop: { value: '42' } } } },
  testimonial: { author: { innerContent: { desktop: { value: 'Probe Person' } } }, content: { innerContent: { desktop: { value: '<p>probe</p>' } } } },
  icon: { icon: { innerContent: { desktop: { value: { unicode: '&#xf4c4;', type: 'fa', weight: '900' } } } } },
  'group-carousel': {},
  blog: {},
  shop: {},
  'contact-form': {},
  'contact-field': { fieldItem: { advanced: { id: { desktop: { value: 'Name' } } }, innerContent: { desktop: { value: 'Name' } } } },
  // The shapes of the real export fixtures/valid/page-17-newpage.json (divi/tabs > divi/tab, divi/accordion > divi/accordion-item).
  tabs: {},
  tab: { title: { innerContent: { desktop: { value: 'Probe tab' } } }, content: { innerContent: { desktop: { value: '<p>probe tab text</p>' } } } },
  accordion: {},
  'accordion-item': { title: { innerContent: { desktop: { value: 'Probe toggle' } } }, content: { innerContent: { desktop: { value: '<p>probe toggle text</p>' } } } },
  // Divi 5.15.1 circle-counter module.json: title.innerContent and number.innerContent (a number; the bar is drawn on a canvas).
  'circle-counter': { title: { innerContent: { desktop: { value: 'PROBE' } } }, number: { innerContent: { desktop: { value: '50' } } } },
  // The shape of the real export fixtures/valid/page-7-home.json (divi/toggle: title.innerContent and content.innerContent; closed unless
  // module.advanced.open is on). Added for the pricing, team, FAQ and story recipes task.
  toggle: { title: { innerContent: { desktop: { value: 'Probe toggle' } } }, content: { innerContent: { desktop: { value: '<p>probe toggle text</p>' } } } },
  // The shape of the real export fixtures/valid/page-17-newpage.json (divi/countdown-timer: title.innerContent and content.advanced.dateTime).
  // The date is Divi 5.15.1's own default (module-default-render-attributes.json, in the past), so every unit shows 00 and the page is the
  // same on every load (a future date would tick between the two pages). Added for the calls to action, gallery, contact and transitions task.
  'countdown-timer': { title: { innerContent: { desktop: { value: 'Probe countdown' } } }, content: { advanced: { dateTime: { desktop: { value: '2020-03-23 01:30' } } } } },
};
const deepMerge = (a, b) => {
  const out = JSON.parse(JSON.stringify(a));
  for (const [k, v] of Object.entries(b || {})) out[k] = v && typeof v === 'object' && !Array.isArray(v) && out[k] && typeof out[k] === 'object' ? deepMerge(out[k], v) : v;
  return out;
};

/** A spec ({block, base, set?, children?}) as a tree node; `set` is applied only on the candidate itself (when `apply`). */
function nodeFromSpec(spec, top, apply) {
  if (!spec || typeof spec.block !== 'string' || !SUPPORTED.includes(spec.block)) {
    throw new Error('block "' + (spec && spec.block) + '" is not supported by the probe (supported: ' + SUPPORTED.join(', ') + ')');
  }
  if (!top && spec.set) throw new Error('a child block of "' + spec.block + '" has a `set`; only the candidate itself can');
  const kids = spec.children || [];
  if (kids.length && !CONTAINERS.includes(spec.block)) throw new Error('block "' + spec.block + '" cannot hold children (only ' + CONTAINERS.join(', ') + ')');
  const attrs = deepMerge(DEFAULT_BASE[spec.block] || {}, withSite(JSON.parse(JSON.stringify(spec.base || {}))));
  attrs.builderVersion = V;
  if (top && apply) for (const [p, v] of Object.entries(spec.set || {})) setp(attrs, p, withSite(v));
  return { block: spec.block, attrs, children: kids.map((k) => nodeFromSpec(k, false, apply)) };
}
const plain = (name) => ({ block: name, attrs: { ...JSON.parse(JSON.stringify(DEFAULT_BASE[name] || {})), builderVersion: V }, children: [] });
const fillerNode = () => ({ block: 'heading', attrs: { ...DEFAULT_BASE.heading, title: { innerContent: { desktop: { value: 'filler' } } }, builderVersion: V }, children: [] });

function render(n) {
  let kids = n.children;
  if (CONTAINERS.includes(n.block) && !kids.length) {
    kids = n.block === 'section' ? [{ ...plain('row'), children: [{ ...plain('column'), children: [fillerNode()] }] }]
      : n.block === 'row' ? [{ ...plain('column'), children: [fillerNode()] }]
      : n.block === 'tabs' ? [plain('tab'), plain('tab')]
      : n.block === 'accordion' ? [plain('accordion-item'), plain('accordion-item')]
      : [fillerNode()];
  }
  return CONTAINERS.includes(n.block) ? block(n.block, n.attrs, kids.map(render).join('')) : block(n.block, n.attrs);
}

/** The wrapper block of kind `name` (a plain one, with the candidate's `wrapperBase[name]` merged in when given). */
function wrapperNode(name, c) {
  const n = plain(name);
  const extra = c.wrapperBase && c.wrapperBase[name];
  if (extra) n.attrs = deepMerge(n.attrs, withSite(JSON.parse(JSON.stringify(extra))));
  return n;
}

/** The plain blocks next to a non-section candidate: { before: [node], after: [node] } (empty lists when the candidate has none). */
function siblingNodes(c) {
  const out = { before: [], after: [] };
  if (c.siblings === undefined || c.siblings === null) return out;
  if (c.block === 'section') throw new Error('`siblings` is not supported on a section candidate (use `neighbours`)');
  if (typeof c.siblings !== 'object' || Array.isArray(c.siblings)) throw new Error('`siblings` must be an object { before: [...], after: [...] }');
  for (const side of ['before', 'after']) {
    const list = c.siblings[side] || [];
    if (!Array.isArray(list)) throw new Error('`siblings.' + side + '` must be a list of block specs');
    for (const spec of list) out[side].push(nodeFromSpec(spec, false, false));
  }
  return out;
}

/** The page for a candidate: its block (with the set values applied when `apply`), inside `parent` when given, in the smallest valid wrapper. */
function pageFor(c, apply) {
  let node = nodeFromSpec(c, true, apply);
  const sib = siblingNodes(c);
  const withSiblings = (n) => [...sib.before, n, ...sib.after];
  let siblingsPlaced = false;
  if (c.parent !== undefined && c.parent !== null) {
    if (!CONTAINERS.includes(c.parent) || c.parent === 'section') throw new Error('parent "' + c.parent + '" is not supported (use row, column or group)');
    node = { ...wrapperNode(c.parent, c), children: withSiblings(node) };
    siblingsPlaced = true;
  }
  // Wrap from the node's level up to a section: group/module > column > row > section.
  const level = { section: 0, row: 1, column: 2 }[node.block] ?? 3;
  for (let l = level; l > 0; l--) {
    const w = l === 3 ? 'column' : l === 2 ? 'row' : 'section';
    node = { ...wrapperNode(w, c), children: siblingsPlaced ? [node] : withSiblings(node) };
    siblingsPlaced = true;
  }
  if (!siblingsPlaced && (sib.before.length || sib.after.length)) throw new Error('`siblings` needs a container: the candidate has none');
  const nb = neighbourNodes(c);
  return '<!-- wp:divi/placeholder -->' + nb.before.map(render).join('') + render(node) + nb.after.map(render).join('') + '<!-- /wp:divi/placeholder -->';
}

/** The plain sections around a section candidate: { before: [node], after: [node] } (empty lists when the candidate has none). */
function neighbourNodes(c) {
  const out = { before: [], after: [] };
  if (c.neighbours === undefined || c.neighbours === null) return out;
  if (typeof c.neighbours !== 'object' || Array.isArray(c.neighbours)) throw new Error('`neighbours` must be an object { before: [...], after: [...] }');
  for (const side of ['before', 'after']) {
    const list = c.neighbours[side] || [];
    if (!Array.isArray(list)) throw new Error('`neighbours.' + side + '` must be a list of section specs');
    for (const spec of list) {
      if (!spec || spec.block !== 'section') throw new Error('a neighbour must be a section spec');
      out[side].push(nodeFromSpec(spec, false, false));
    }
  }
  return out;
}

// Every throwaway page's title starts with RUN_PREFIX (the bare TITLE_PREFIX plus this run's token).
const TITLE_PREFIX = 'aied shape probe ';
// Made once per run: this run creates and deletes only pages titled with RUN_PREFIX.
const RUN_TOKEN = process.pid + '-' + crypto.randomBytes(3).toString('hex');
const RUN_PREFIX = TITLE_PREFIX + RUN_TOKEN + ' ';
const created = [];
let cleaning = false; // set by the signal handler: nothing new is created after it
function makePage(label, content) {
  if (cleaning) throw new Error('interrupted');
  // Title and content travel as one base64 JSON payload, so no candidate id can break out of the PHP string.
  const b64 = Buffer.from(JSON.stringify({ title: RUN_PREFIX + label, content })).toString('base64');
  const out = wp('eval', `$p=json_decode(base64_decode('${b64}'),true);$c=$p['content'];$g=AiEditorDivi5${BS}WP${BS}LayoutGate::check($c);$id=wp_insert_post(wp_slash(['post_type'=>'page','post_title'=>$p['title'],'post_content'=>$c,'post_status'=>'draft','meta_input'=>['_et_pb_use_divi_5'=>'on','_et_pb_use_builder'=>'on']]));echo $id.'|'.($g->isValid()?'valid':'INVALID '.json_encode(array_map(fn($v)=>$v->toArray(),$g->violations())));`);
  const [id, verdict] = out.split('|');
  created.push(id);
  return { id, valid: verdict === 'valid', verdict };
}

/** Every page's { ID, post_title } (a full list, never a content search). */
function allPages() {
  return JSON.parse(wp('post', 'list', '--post_type=page', '--post_status=any', '--fields=ID,post_title', '--format=json') || '[]');
}
const idsWithPrefix = (rows, prefix) => rows.filter((r) => typeof r.post_title === 'string' && r.post_title.startsWith(prefix)).map((r) => String(r.ID));

/**
 * Deletes this run's throwaway pages. makePage records an id only after its whole call returned, so a failure after
 * wp_insert_post would leak a page: also sweep by title. `wp post list --s` is a content search (it would match unrelated
 * pages), so list ALL pages with their titles and keep only rows whose post_title starts with the exact RUN_PREFIX (the probe
 * prefix plus this run's token). Never a search, never a page without that prefix, never another run's page. Safe to call twice.
 * Returns true when everything went well.
 */
function sweep() {
  let ok = true;
  let swept = [];
  try { swept = idsWithPrefix(allPages(), RUN_PREFIX); } catch (e) { console.log('WARNING could not list leftover probe pages'); ok = false; }
  for (const id of [...new Set([...created, ...swept])]) {
    if (!/^\d+$/.test(String(id))) { console.log('WARNING ignoring non-numeric page id ' + id); ok = false; continue; }
    try { wp('post', 'delete', String(id), '--force'); } catch (e) { console.log('WARNING could not delete page ' + id); ok = false; }
  }
  created.length = 0;
  return ok;
}

/** --clean-orphans: deletes ALL pages whose title starts with the bare probe prefix (only when no other probe is running). */
function removeOrphans() {
  let ok = true;
  let ids = [];
  try { ids = idsWithPrefix(allPages(), TITLE_PREFIX); } catch (e) { console.log('ERROR could not list pages'); return false; }
  for (const id of ids) {
    if (!/^\d+$/.test(id)) { console.log('WARNING ignoring non-numeric page id ' + id); ok = false; continue; }
    try { wp('post', 'delete', id, '--force'); console.log('deleted orphan probe page ' + id); } catch (e) { console.log('WARNING could not delete page ' + id); ok = false; }
  }
  console.log(`removed ${ids.length} orphan probe page(s)`);
  return ok;
}

const classOf = (blockName) => 'et_pb_' + blockName.replace(/-/g, '_');

/** Runs in the page: stylesheet text plus the computed styles of the module element and its descendants. */
function snapshotInPage({ cls, index, withRect, attrs, ignore, geometry, pseudo, text, canvas }) {
  // Divi and WordPress attach their <style> blocks in a load-dependent order, so the blocks are sorted before comparing.
  const styleText = [...document.querySelectorAll('style')].map((s) => s.textContent.trim()).filter(Boolean).sort().join('\n/*--*/\n');
  // The page content, never the Theme Builder header/footer (their first column/row/section would be measured otherwise).
  const inTb = (e) => /_tb_(header|footer|body)(\s|$)/.test(String(e.className)) || !!e.closest('.et-l--header, .et-l--footer');
  const inPost = [...document.querySelectorAll('.et-l--post .' + cls)];
  const el = inPost[index] || (index === 0 ? [...document.querySelectorAll('.' + cls)].find((e) => !inTb(e)) : null) || null;
  // Document-coordinate rectangle (rounded), so scrolling cannot change it.
  const docRect = (e) => {
    const r = e.getBoundingClientRect();
    return { top: Math.round(r.top + window.scrollY), left: Math.round(r.left + window.scrollX), width: Math.round(r.width), height: Math.round(r.height) };
  };
  const geometryOf = (m) => {
    const sibOf = (e) => (e && e.nodeType === 1 ? e : null);
    const prev = sibOf(m.previousElementSibling);
    const next = sibOf(m.nextElementSibling);
    const rm = docRect(m);
    const out = { rect: rm, parent: m.parentElement ? docRect(m.parentElement) : null, prev: prev ? docRect(prev) : null, next: next ? docRect(next) : null, overlap: { area: 0, with: null, top: null } };
    // Which element is on top at the centre of the measured element itself (is it hidden behind a background?).
    {
      const cxd = rm.left + rm.width / 2;
      const cyd = rm.top + rm.height / 2;
      const back0 = window.scrollY;
      let yv = cyd - window.scrollY;
      if (rm.width > 0 && rm.height > 0) {
        if (yv < 0 || yv >= window.innerHeight) { window.scrollTo(0, Math.max(0, cyd - window.innerHeight / 2)); yv = cyd - window.scrollY; }
        const xv = cxd - window.scrollX;
        const hit0 = xv >= 0 && xv < window.innerWidth && yv >= 0 && yv < window.innerHeight ? document.elementFromPoint(xv, yv) : null;
        out.hitCentre = !hit0 ? 'none' : m.contains(hit0) ? 'measured' : 'other';
        window.scrollTo(0, back0);
      } else {
        out.hitCentre = 'none';
      }
    }
    let best = null;
    for (const [name, e] of [['prev', prev], ['next', next]]) {
      if (!e) continue;
      const r = docRect(e);
      const w = Math.min(rm.left + rm.width, r.left + r.width) - Math.max(rm.left, r.left);
      const h = Math.min(rm.top + rm.height, r.top + r.height) - Math.max(rm.top, r.top);
      if (w > 0 && h > 0 && (!best || w * h > best.area)) best = { area: w * h, with: name, el: e, cx: Math.max(rm.left, r.left) + w / 2, cy: Math.max(rm.top, r.top) + h / 2 };
    }
    if (best) {
      out.overlap.area = best.area;
      out.overlap.with = best.with;
      // Bring the centre of the overlap into the viewport, ask which element is there, and scroll back.
      const x = best.cx - window.scrollX;
      let y = best.cy - window.scrollY;
      const back = window.scrollY;
      if (y < 0 || y >= window.innerHeight) { window.scrollTo(0, Math.max(0, best.cy - window.innerHeight / 2)); y = best.cy - window.scrollY; }
      const hit = x >= 0 && x < window.innerWidth && y >= 0 && y < window.innerHeight ? document.elementFromPoint(x, y) : null;
      out.overlap.top = !hit ? 'none' : m.contains(hit) ? 'measured' : best.el.contains(hit) ? 'sibling' : 'other';
      window.scrollTo(0, back);
    }
    return out;
  };
  const props = {};
  let elems = [];
  let found = false;
  let geo = null;
  if (el) {
    found = true;
    const all = [el, ...el.querySelectorAll('*')];
    elems = all.map((e) => e.tagName.toLowerCase() + (typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\s+/).slice(0, 3).join('.') : ''));
    all.forEach((e, i) => {
      const cs = getComputedStyle(e);
      for (let k = 0; k < cs.length; k++) {
        if (ignore.includes(cs[k])) continue;
        props[i + ':' + e.tagName.toLowerCase() + ':' + cs[k]] = cs.getPropertyValue(cs[k]);
      }
    });
    for (const ps of pseudo) {
      all.forEach((e, i) => {
        const cs = getComputedStyle(e, ps);
        for (let k = 0; k < cs.length; k++) {
          if (ignore.includes(cs[k])) continue;
          props[i + ':' + e.tagName.toLowerCase() + ':' + ps + ':' + cs[k]] = cs.getPropertyValue(cs[k]);
        }
      });
    }
    if (text) props['text:innerText'] = String(el.innerText);
    if (canvas) {
      [...el.querySelectorAll('canvas')].forEach((cv, i) => {
        props['canvas:' + i + ':size'] = cv.width + 'x' + cv.height;
        let colors = 'unreadable';
        try {
          const d = cv.getContext('2d').getImageData(0, 0, cv.width, cv.height).data;
          const count = new Map();
          for (let k = 0; k < d.length; k += 4) if (d[k + 3] > 16) { const key = 'rgba(' + d[k] + ',' + d[k + 1] + ',' + d[k + 2] + ',' + d[k + 3] + ')'; count.set(key, (count.get(key) || 0) + 1); }
          colors = [...count.entries()].sort((a, b) => b[1] - a[1]).slice(0, 3).map(([key]) => key).join(' ') || 'none';
        } catch (e) { /* tainted or no 2d context: stays 'unreadable' */ }
        props['canvas:' + i + ':colors'] = colors;
      });
    }
    for (const name of attrs) props['attr:' + name] = String(el.getAttribute(name));
    if (withRect) {
      const r = el.getBoundingClientRect();
      for (const key of ['top', 'left', 'width', 'height']) props['rect:' + key] = String(Math.round(r[key]));
    }
    if (geometry) geo = geometryOf(el);
  }
  return { styleText, props, elems, found, geo, overflow: document.documentElement.scrollWidth > window.innerWidth, overflowPx: Math.max(0, document.documentElement.scrollWidth - window.innerWidth) };
}

function diffProps(a, b) {
  const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
  const out = [];
  for (const k of keys) if (a[k] !== b[k]) out.push(`${k}: ${a[k]} -> ${b[k]}`);
  return out;
}

/** The distinct property names that differ (the element index and tag are dropped: `0:div:transform` -> `transform`), sorted, at most 40. */
function changedNames(a, b) {
  const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
  const names = new Set();
  for (const k of keys) if (a[k] !== b[k]) names.add(k.replace(/^\d+:[a-z0-9-]+:/, ''));
  return [...names].sort().slice(0, 40);
}

/** Which elements differ: [{ el: "tag.class.class", props: [distinct property names, at most 12] }] (at most 12 elements); added for the forms adapter probe (where a padding or a shadow lands). */
function changedElements(a, b, elems) {
  const byIndex = new Map();
  for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) {
    const m = /^(\d+):[a-z0-9-]+:(.*)$/.exec(k);
    if (!m || a[k] === b[k]) continue;
    if (!byIndex.has(m[1])) byIndex.set(m[1], new Set());
    byIndex.get(m[1]).add(m[2]);
  }
  return [...byIndex.entries()].slice(0, 12).map(([i, set]) => ({ el: elems[Number(i)] || ('#' + i), props: [...set].sort().slice(0, 12) }));
}

if (cleanOrphans) process.exit(removeOrphans() ? 0 : 1);

let browserRef = null;
// SIGINT / SIGTERM / SIGHUP: delete the throwaway pages the same way the normal end does, then leave.
for (const sig of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
  process.on(sig, async () => {
    if (cleaning) return;
    cleaning = true;
    console.log('\n' + sig + ': removing the throwaway pages');
    try { if (browserRef) await browserRef.close(); } catch (e) { /* leaving anyway */ }
    const ok = sweep();
    console.log(ok ? 'Cleaned up.' : 'WARNING cleanup was incomplete; run `wp post list --post_type=page --fields=ID,post_title` and look for "' + RUN_PREFIX + '"');
    process.exit(sig === 'SIGINT' ? 130 : sig === 'SIGHUP' ? 129 : 143);
  });
}

(async () => {
  let browser;
  let failed = false;
  const results = [];
  try {
    const candidates = JSON.parse(fs.readFileSync(path.resolve(candFile), 'utf8'));
    if (!Array.isArray(candidates)) throw new Error('the candidates file must be a JSON array');
    const cookies = JSON.parse(wp('eval', '$u=1;$e=time()+3600;$t=WP_Session_Tokens::get_instance($u)->create($e);echo json_encode([[AUTH_COOKIE,wp_generate_auth_cookie($u,$e,"auth",$t)],[LOGGED_IN_COOKIE,wp_generate_auth_cookie($u,$e,"logged_in",$t)]]);'));
    // Playwright would otherwise install its own SIGINT/SIGTERM handlers and exit before the page cleanup below finishes.
    // Nothing is ours yet, so every page with the bare prefix is another run's or a leftover; it is reported, never deleted.
    const foreign = idsWithPrefix(allPages(), TITLE_PREFIX).length;
    if (foreign > 0) console.log(`WARNING ${foreign} leftover or concurrent probe pages (not deleted; run with --clean-orphans when no other probe is running)`);
    browser = await chromium.launch({ handleSIGINT: false, handleSIGTERM: false, handleSIGHUP: false });
    browserRef = browser;
    const snap = async (id, width, cls, settle, index, c) => {
      const ctx = await browser.newContext({ viewport: { width, height: 900 } });
      try {
        await ctx.addCookies(cookies.map(([name, value]) => ({ name, value, url: SITE })));
        const p = await ctx.newPage();
        await p.goto(`${SITE}/?page_id=${id}&preview=true`, { waitUntil: 'load' });
        await p.waitForTimeout(settle);
        if (c.state === 'hover') {
          const hoverRoot = p.locator('.et-l--post .' + cls).nth(index);
          await (c.hoverSelector ? hoverRoot.locator(c.hoverSelector).first() : hoverRoot).hover({ timeout: 10000 });
          await p.waitForTimeout(settle);
        } else if (c.state === 'click') {
          await p.locator('.et-l--post .' + cls).nth(index).locator(c.clickSelector).first().click({ timeout: 10000 });
          await p.waitForTimeout(settle);
        } else if (c.state === 'scroll') {
          await p.evaluate(({ cls, index, at }) => {
            const el = [...document.querySelectorAll('.et-l--post .' + cls)][index];
            let top = 0;
            for (let e = el; e; e = e.offsetParent) top += e.offsetTop;
            window.scrollTo(0, Math.max(0, top + el.offsetHeight / 2 - at * window.innerHeight));
          }, { cls, index, at: typeof c.scrollAt === 'number' ? c.scrollAt : 0.25 });
          await p.waitForTimeout(settle);
        }
        if (process.env.AIED_DUMP_DIR) { // diagnostics only: the rendered page content, one file per page id and width
          fs.mkdirSync(process.env.AIED_DUMP_DIR, { recursive: true });
          fs.writeFileSync(path.join(process.env.AIED_DUMP_DIR, `${c.id}-${width}-${id}.html`), await p.evaluate(() => (document.querySelector('.et-l--post') || document.body).outerHTML));
        }
        const r = await p.evaluate(snapshotInPage, { cls, index, withRect: c.state === 'scroll' || c.geometry === true, attrs: c.attrs || [], ignore: c.ignore || [], geometry: c.geometry === true, pseudo: c.pseudo || [], text: c.text === true, canvas: c.canvas === true });
        // WordPress writes a random per-request placeholder comment into the block-library style.
        r.styleText = r.styleText.replace(/wp_block_styles_on_demand_placeholder:[0-9a-f]+/g, 'wp_block_styles_on_demand_placeholder:X');
        r.styleText = r.styleText.split(new RegExp('\\b' + id + '\\b', 'g')).join('PID');
        return r;
      } finally {
        await ctx.close();
      }
    };

    for (const c of candidates) {
      if (cleaning) break;
      const id = c && c.id !== undefined ? String(c.id) : '(no id)';
      const entry = { id, effect: false, widths: {}, phoneOverflow: false, detail: {} };
      let error = null;
      try {
        if (!c || typeof c.id !== 'string' || !c.id) throw new Error('a candidate needs a string `id`');
        if (!c.set || typeof c.set !== 'object' || !Object.keys(c.set).length) throw new Error('a candidate needs a non-empty `set`');
        if (c.state !== undefined && c.state !== 'hover' && c.state !== 'scroll' && c.state !== 'click') throw new Error('`state` must be "hover", "scroll" or "click"');
        if (c.hoverSelector !== undefined && !(c.state === 'hover' && typeof c.hoverSelector === 'string' && c.hoverSelector)) throw new Error('`hoverSelector` needs `state: "hover"` and a selector string');
        if (c.state === 'click' && !(typeof c.clickSelector === 'string' && c.clickSelector)) throw new Error('`state: "click"` needs a `clickSelector`');
        if (c.ignore !== undefined && !(Array.isArray(c.ignore) && c.ignore.every((x) => typeof x === 'string'))) throw new Error('`ignore` must be a list of computed property names');
        if (c.pseudo !== undefined && !(Array.isArray(c.pseudo) && c.pseudo.every((x) => typeof x === 'string' && /^::[a-z-]+$/.test(x)))) throw new Error('`pseudo` must be a list of pseudo-elements such as "::before"');
        if (c.text !== undefined && c.text !== true) throw new Error('`text` must be true');
        if (c.canvas !== undefined && c.canvas !== true) throw new Error('`canvas` must be true');
        if (c.attrs !== undefined && !(Array.isArray(c.attrs) && c.attrs.every((x) => typeof x === 'string'))) throw new Error('`attrs` must be a list of attribute names');
        const cls = classOf(c.block);
        // The candidate comes after its `before` neighbours (sections) and after its `before` siblings of the same block name.
        const index = (c.block === 'section' ? neighbourNodes(c).before.length : 0) + siblingNodes(c).before.filter((n) => n.block === c.block).length + (c.parent === c.block ? 1 : 0);
        if (c.geometry !== undefined && c.geometry !== true) throw new Error('`geometry` must be true');
        // The number counter counts up after load: let it finish, or two loads of the same page never agree.
        const settle = typeof c.settleMs === 'number' ? c.settleMs : c.block === 'number-counter' || c.block === 'circle-counter' ? 3000 : c.state ? 1200 : 600;
        const a = makePage(c.id + ' A', pageFor(c, false));
        const b = makePage(c.id + ' B', pageFor(c, true));
        if (!a.valid || !b.valid) {
          entry.detail.validator = !a.valid ? 'A ' + a.verdict : 'B ' + b.verdict;
          throw new Error('the layout does not pass the validator - ' + entry.detail.validator);
        }
        for (const w of WIDTHS) {
          const sa = await snap(a.id, w, cls, settle, index, c);
          const sa2 = await snap(a.id, w, cls, settle, index, c);
          const sb = await snap(b.id, w, cls, settle, index, c);
          if (!sa.found || !sb.found) { entry.detail[w] = { error: 'module element .' + cls + ' not found' }; throw new Error('.' + cls + ' not found at ' + w + 'px'); }
          const noise = sa.styleText !== sa2.styleText || diffProps(sa.props, sa2.props).length > 0;
          const styleDiff = sa.styleText !== sb.styleText;
          const computed = diffProps(sa.props, sb.props);
          entry.widths[w] = styleDiff || computed.length > 0;
          entry.detail[w] = { stylesheetDiffers: styleDiff, computedDiffs: computed.length, sample: computed.slice(0, 6), changedProps: changedNames(sa.props, sb.props), changedElements: changedElements(sa.props, sb.props, sb.elems), controlNoise: noise, overflowPx: sb.overflowPx };
          if (c.geometry === true) entry.detail[w].geometry = { control: sa.geo, candidate: sb.geo };
          if (noise) { entry.detail[w].warning = 'two loads of the same page differ: the result at this width is not reliable'; throw new Error('page A differs from itself at ' + w + 'px'); }
          if (w === 400) entry.phoneOverflow = sb.overflow;
        }
        entry.effect = Object.values(entry.widths).some(Boolean);
        // A PASS must rest on a measured computed-style difference; a changed stylesheet text alone is labelled, never silent.
        if (entry.effect && WIDTHS.every((w) => entry.detail[w].computedDiffs === 0)) entry.cssOnly = true; // the field exists only when true
      } catch (e) {
        error = e && e.message ? e.message : String(e);
      } finally {
        // Free the pages of this candidate right away (a later crash or signal then has less to clean up).
        if (!sweep()) failed = true;
      }
      if (cleaning) break;
      if (error !== null) {
        entry.error = error;
        results.push(entry);
        failed = true;
        console.log(`ERROR ${id}: ${error}`);
        continue;
      }
      results.push(entry);
      const verdict = entry.effect ? (entry.phoneOverflow ? 'OVERFLOW' : entry.cssOnly ? 'PASS(css-only)' : 'PASS') : 'DEAD';
      const w = entry.widths;
      console.log(`${verdict}  ${id}  1280=${w[1280]} 900=${w[900]} 400=${w[400]} phoneOverflow=${entry.phoneOverflow}`);
      if (c.expect && c.expect !== verdict && !(c.allowCssOnly === true && c.expect === 'PASS' && verdict === 'PASS(css-only)')) { console.log(`MISMATCH ${id}: expected ${c.expect}, measured ${verdict}`); failed = true; }
    }
    if (cleaning) return;
    fs.mkdirSync(path.dirname(outFile), { recursive: true });
    fs.writeFileSync(outFile, JSON.stringify(results, null, 2) + '\n');
    console.log('wrote ' + path.relative(ROOT, outFile));
  } catch (e) {
    console.log('ERROR script: ' + (e && e.message ? e.message : e));
    failed = true;
  } finally {
    if (!cleaning) {
      if (!sweep()) failed = true;
      if (browser) await browser.close();
    }
  }
  if (cleaning) return;
  console.log(failed ? '\nFAILED' : '\nProbe agrees with every expectation.');
  process.exit(failed ? 1 : 0);
})();
