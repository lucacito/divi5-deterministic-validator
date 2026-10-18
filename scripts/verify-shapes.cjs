#!/usr/bin/env node
/*
 * Dev-only: the effect probe. A shape that passes the validator can still render nothing (Divi ignores a key it does
 * not know and emits no CSS). For every candidate this saves two throwaway draft pages that are identical except for
 * the candidate's `set` values, opens both with the real Divi in Chromium at 1280, 900 and 400 px and reports whether
 * the page changed: the emitted inline <style> text (post ids normalised) or the computed styles of the module's
 * element and its descendants differ. A candidate that changes nothing at any width is DEAD.
 *
 *   node scripts/verify-shapes.cjs <candidates.json> [results.json]    (needs `make up`; Playwright from ../layoutlab)
 *
 * candidates.json: [{ "id", "block", "base": {block attrs}, "set": {"dotted.path.with.desktop": value}, "expect": "PASS|DEAD" (optional) }]
 * "@SITE@" inside a string is replaced by the dev site URL. Results (default fixtures/shapes/probe-results-5.15.1.json):
 * [{ id, effect, widths: {"1280","900","400"}, phoneOverflow, detail }]. Exit 1 when an `expect` disagrees (or on any error).
 * Dev site only (localhost); every page it creates is deleted again, also when it fails.
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
const candFile = process.argv[2];
if (!candFile) {
  console.error('usage: node scripts/verify-shapes.cjs <candidates.json> [results.json]');
  process.exit(2);
}
const outFile = process.argv[3] || path.join(ROOT, 'fixtures', 'shapes', 'probe-results-5.15.1.json');
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
const column = (inner) => block('column', { module: { advanced: { type: { desktop: { value: '4_4' } } } }, builderVersion: V }, inner);
const row = (inner) => block('row', { module: { advanced: { columnStructure: { desktop: { value: '4_4' } } } }, builderVersion: V }, inner);
const filler = () => block('heading', { title: { innerContent: { desktop: { value: 'filler' } } }, builderVersion: V });

/** The page for a candidate: its block (with the set values applied when `apply`) in the smallest valid wrapper. */
function pageFor(c, apply) {
  const attrs = withSite(JSON.parse(JSON.stringify(c.base || {})));
  attrs.builderVersion = V;
  if (apply) for (const [p, v] of Object.entries(c.set)) setp(attrs, p, withSite(v));
  let body;
  switch (c.block) {
    case 'section': body = block('section', attrs, row(column(filler()))); break;
    case 'row': body = block('section', { builderVersion: V }, block('row', attrs, column(filler()))); break;
    case 'column': body = block('section', { builderVersion: V }, block('row', { module: { advanced: { columnStructure: { desktop: { value: '4_4' } } } }, builderVersion: V }, block('column', attrs, filler()))); break;
    case 'group': throw new Error('block "group" is not supported by the probe yet');
    default: body = block('section', { builderVersion: V }, row(column(block(c.block, attrs))));
  }
  return '<!-- wp:divi/placeholder -->' + body + '<!-- /wp:divi/placeholder -->';
}

const created = [];
function makePage(label, content) {
  const b64 = Buffer.from(content).toString('base64');
  const out = wp('eval', `$c=base64_decode('${b64}');$g=AiEditorDivi5${BS}WP${BS}LayoutGate::check($c);$id=wp_insert_post(wp_slash(['post_type'=>'page','post_title'=>'aied shape probe ${label}','post_content'=>$c,'post_status'=>'draft','meta_input'=>['_et_pb_use_divi_5'=>'on','_et_pb_use_builder'=>'on']]));echo $id.'|'.($g->isValid()?'valid':'INVALID '.json_encode(array_map(fn($v)=>$v->toArray(),$g->violations())));`);
  const [id, verdict] = out.split('|');
  created.push(id);
  return { id, valid: verdict === 'valid', verdict };
}

const CLASS_OF = { section: 'et_pb_section', row: 'et_pb_row', column: 'et_pb_column' };

/** Runs in the page: stylesheet text plus the computed styles of the module element and its descendants. */
function snapshotInPage(cls) {
  // Divi and WordPress attach their <style> blocks in a load-dependent order, so the blocks are sorted before comparing.
  const styleText = [...document.querySelectorAll('style')].map((s) => s.textContent.trim()).filter(Boolean).sort().join('\n/*--*/\n');
  const el = document.querySelector('#page-container .' + cls + ', .et-l--post .' + cls + ', .' + cls);
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

(async () => {
  let browser;
  let failed = false;
  const results = [];
  try {
    const candidates = JSON.parse(fs.readFileSync(path.resolve(candFile), 'utf8'));
    const cookies = JSON.parse(wp('eval', '$u=1;$e=time()+3600;$t=WP_Session_Tokens::get_instance($u)->create($e);echo json_encode([[AUTH_COOKIE,wp_generate_auth_cookie($u,$e,"auth",$t)],[LOGGED_IN_COOKIE,wp_generate_auth_cookie($u,$e,"logged_in",$t)]]);'));
    browser = await chromium.launch();
    const snap = async (id, width, cls) => {
      const ctx = await browser.newContext({ viewport: { width, height: 900 } });
      await ctx.addCookies(cookies.map(([name, value]) => ({ name, value, url: SITE })));
      const p = await ctx.newPage();
      await p.goto(`${SITE}/?page_id=${id}&preview=true`, { waitUntil: 'load' });
      await p.waitForTimeout(600);
      const r = await p.evaluate(snapshotInPage, cls);
      await ctx.close();
      // WordPress writes a random per-request placeholder comment into the block-library style.
      r.styleText = r.styleText.replace(/wp_block_styles_on_demand_placeholder:[0-9a-f]+/g, 'wp_block_styles_on_demand_placeholder:X');
      r.styleText = r.styleText.split(new RegExp('\\b' + id + '\\b', 'g')).join('PID');
      return r;
    };

    for (const c of candidates) {
      const cls = CLASS_OF[c.block] || 'et_pb_' + c.block;
      const entry = { id: c.id, effect: false, widths: {}, phoneOverflow: false, detail: {} };
      try {
        const a = makePage(c.id + ' A', pageFor(c, false));
        const b = makePage(c.id + ' B', pageFor(c, true));
        if (!a.valid || !b.valid) {
          entry.detail.validator = !a.valid ? 'A ' + a.verdict : 'B ' + b.verdict;
          console.log(`ERROR ${c.id}: the layout does not pass the validator - ${entry.detail.validator}`);
          failed = true;
        } else {
          for (const w of WIDTHS) {
            const sa = await snap(a.id, w, cls);
            const sa2 = await snap(a.id, w, cls);
            const sb = await snap(b.id, w, cls);
            if (!sa.found || !sb.found) { entry.detail[w] = { error: 'module element .' + cls + ' not found' }; failed = true; console.log(`ERROR ${c.id}: .${cls} not found at ${w}px`); continue; }
            const noise = sa.styleText !== sa2.styleText || diffProps(sa.props, sa2.props).length > 0;
            const styleDiff = sa.styleText !== sb.styleText;
            const computed = diffProps(sa.props, sb.props);
            entry.widths[w] = styleDiff || computed.length > 0;
            entry.detail[w] = { stylesheetDiffers: styleDiff, computedDiffs: computed.length, sample: computed.slice(0, 6), controlNoise: noise };
            if (noise) { entry.detail[w].warning = 'two loads of the same page differ: the result at this width is not reliable'; failed = true; console.log(`ERROR ${c.id}: page A differs from itself at ${w}px`); }
            if (w === 400) entry.phoneOverflow = sb.overflow;
          }
          entry.effect = Object.values(entry.widths).some(Boolean);
        }
      } finally {
        results.push(entry);
      }
      const verdict = entry.effect ? 'PASS' : 'DEAD';
      const w = entry.widths;
      console.log(`${verdict}  ${c.id}  1280=${w[1280]} 900=${w[900]} 400=${w[400]} phoneOverflow=${entry.phoneOverflow}`);
      if (c.expect && c.expect !== verdict) { console.log(`MISMATCH ${c.id}: expected ${c.expect}, measured ${verdict}`); failed = true; }
    }
    fs.writeFileSync(outFile, JSON.stringify(results, null, 2) + '\n');
    console.log('wrote ' + path.relative(ROOT, outFile));
  } catch (e) {
    console.log('ERROR script: ' + (e && e.message ? e.message : e));
    failed = true;
  } finally {
    for (const id of created) { try { wp('post', 'delete', id, '--force'); } catch (e) { console.log('WARNING could not delete page ' + id); failed = true; } }
    if (browser) await browser.close();
  }
  console.log(failed ? '\nFAILED' : '\nProbe agrees with every expectation.');
  process.exit(failed ? 1 : 0);
})();
