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
 *            number-counter, testimonial, icon. Real-export content shapes are filled in for the content of each, `base` wins.
 *   neighbours  (section candidates only) { "before": [<section spec>], "after": [<section spec>] }: plain sections placed before/after the
 *            candidate section on the page, identical in the A and B pages (e.g. a differently coloured section so that a divider is
 *            visible). The measured element is then the candidate section, not a neighbour. Absent = the page is unchanged.
 * "@SITE@" inside a string is replaced by the dev site URL.
 * Results (default fixtures/shapes/probe-results-<candidates file name>.json, never the committed probe-results-5.15.1.json
 * unless asked with --out): [{ id, effect, widths: {"1280","900","400"}, phoneOverflow, detail, error? }].
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
const SUPPORTED = ['section', 'row', 'column', 'group', 'heading', 'text', 'image', 'button', 'blurb', 'divider', 'number-counter', 'testimonial', 'icon'];
const CONTAINERS = ['section', 'row', 'column', 'group'];
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
      : [fillerNode()];
  }
  return CONTAINERS.includes(n.block) ? block(n.block, n.attrs, kids.map(render).join('')) : block(n.block, n.attrs);
}

/** The page for a candidate: its block (with the set values applied when `apply`), inside `parent` when given, in the smallest valid wrapper. */
function pageFor(c, apply) {
  let node = nodeFromSpec(c, true, apply);
  if (c.parent !== undefined && c.parent !== null) {
    if (!CONTAINERS.includes(c.parent) || c.parent === 'section') throw new Error('parent "' + c.parent + '" is not supported (use row, column or group)');
    node = { ...plain(c.parent), children: [node] };
  }
  // Wrap from the node's level up to a section: group/module > column > row > section.
  const level = { section: 0, row: 1, column: 2 }[node.block] ?? 3;
  for (let l = level; l > 0; l--) {
    const w = l === 3 ? 'column' : l === 2 ? 'row' : 'section';
    node = { ...plain(w), children: [node] };
  }
  const nb = neighbourNodes(c);
  return '<!-- wp:divi/placeholder -->' + nb.before.map(render).join('') + render(node) + nb.after.map(render).join('') + '<!-- /wp:divi/placeholder -->';
}

/** The plain sections around a section candidate: { before: [node], after: [node] } (empty lists when the candidate has none). */
function neighbourNodes(c) {
  const out = { before: [], after: [] };
  if (c.neighbours === undefined || c.neighbours === null) return out;
  if (c.block !== 'section') throw new Error('`neighbours` is only supported on a section candidate');
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
function snapshotInPage({ cls, index }) {
  // Divi and WordPress attach their <style> blocks in a load-dependent order, so the blocks are sorted before comparing.
  const styleText = [...document.querySelectorAll('style')].map((s) => s.textContent.trim()).filter(Boolean).sort().join('\n/*--*/\n');
  // The page content, never the Theme Builder header/footer (their first column/row/section would be measured otherwise).
  const inTb = (e) => /_tb_(header|footer|body)(\s|$)/.test(String(e.className)) || !!e.closest('.et-l--header, .et-l--footer');
  const inPost = [...document.querySelectorAll('.et-l--post .' + cls)];
  const el = inPost[index] || (index === 0 ? [...document.querySelectorAll('.' + cls)].find((e) => !inTb(e)) : null) || null;
  const props = {};
  let found = false;
  if (el) {
    found = true;
    const all = [el, ...el.querySelectorAll('*')];
    all.forEach((e, i) => {
      const cs = getComputedStyle(e);
      for (let k = 0; k < cs.length; k++) props[i + ':' + e.tagName.toLowerCase() + ':' + cs[k]] = cs.getPropertyValue(cs[k]);
    });
  }
  return { styleText, props, found, overflow: document.documentElement.scrollWidth > window.innerWidth };
}

function diffProps(a, b) {
  const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
  const out = [];
  for (const k of keys) if (a[k] !== b[k]) out.push(`${k}: ${a[k]} -> ${b[k]}`);
  return out;
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
    const snap = async (id, width, cls, settle, index) => {
      const ctx = await browser.newContext({ viewport: { width, height: 900 } });
      try {
        await ctx.addCookies(cookies.map(([name, value]) => ({ name, value, url: SITE })));
        const p = await ctx.newPage();
        await p.goto(`${SITE}/?page_id=${id}&preview=true`, { waitUntil: 'load' });
        await p.waitForTimeout(settle);
        const r = await p.evaluate(snapshotInPage, { cls, index });
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
        const cls = classOf(c.block);
        const index = neighbourNodes(c).before.length; // the candidate comes after its `before` neighbours
        // The number counter counts up after load: let it finish, or two loads of the same page never agree.
        const settle = c.block === 'number-counter' ? 3000 : 600;
        const a = makePage(c.id + ' A', pageFor(c, false));
        const b = makePage(c.id + ' B', pageFor(c, true));
        if (!a.valid || !b.valid) {
          entry.detail.validator = !a.valid ? 'A ' + a.verdict : 'B ' + b.verdict;
          throw new Error('the layout does not pass the validator - ' + entry.detail.validator);
        }
        for (const w of WIDTHS) {
          const sa = await snap(a.id, w, cls, settle, index);
          const sa2 = await snap(a.id, w, cls, settle, index);
          const sb = await snap(b.id, w, cls, settle, index);
          if (!sa.found || !sb.found) { entry.detail[w] = { error: 'module element .' + cls + ' not found' }; throw new Error('.' + cls + ' not found at ' + w + 'px'); }
          const noise = sa.styleText !== sa2.styleText || diffProps(sa.props, sa2.props).length > 0;
          const styleDiff = sa.styleText !== sb.styleText;
          const computed = diffProps(sa.props, sb.props);
          entry.widths[w] = styleDiff || computed.length > 0;
          entry.detail[w] = { stylesheetDiffers: styleDiff, computedDiffs: computed.length, sample: computed.slice(0, 6), controlNoise: noise };
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
