#!/usr/bin/env node
/*
 * Dev-only: what Divi shows for modules nobody styled. It saves ONE throwaway draft page made of unstyled modules in the shapes
 * the builder itself writes (the content of the real export fixtures/valid/page-17-newpage.json), opens it in Chromium on the
 * real Divi and records the computed look of the stock button, the stock tab bar, and the stock image placeholder. The evidence
 * is committed (fixtures/shapes/divi-defaults-<version>.json) and is what src DefaultFallbacks is allowed to claim.
 *
 *   node scripts/probe-divi-defaults.cjs        (needs `make up`; Playwright from ../layoutlab; dev site only)
 */
const fs = require('fs');
const path = require('path');
const { devSiteOnly, makeWp, loginCookies, throwawayPages } = require('./lib/throwaway-pages.cjs');
const ROOT = path.resolve(__dirname, '..');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');
devSiteOnly(SITE);
const { chromium } = require(PW);
const wp = makeWp(ROOT);
const pages = throwawayPages(wp, 'aied defaults probe ', 'defaults');

(async () => {
  const fixture = JSON.parse(fs.readFileSync(path.join(ROOT, 'fixtures/valid/page-17-newpage.json'), 'utf8'));
  const content = fixture.post_content;
  const b64 = Buffer.from(content, 'utf8').toString('base64');
  const id = wp('eval', `$id = wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>${JSON.stringify(pages.runPrefix + 'unstyled')},'post_content'=>wp_slash(base64_decode('${b64}'))], true); echo is_wp_error($id) ? 'ERR' : $id;`);
  pages.created.push(id);
  const diviVersion = wp('eval', 'echo function_exists("et_get_theme_version") ? et_get_theme_version() : "unknown";');
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.addCookies(loginCookies(wp).map(([name, value]) => ({ name, value, url: SITE })));
  const page = await ctx.newPage();
  await page.goto(`${SITE}/?page_id=${id}&preview=true`, { waitUntil: 'networkidle' });
  const result = await page.evaluate(() => {
    const cs = (el, props) => { if (!el) return null; const s = getComputedStyle(el); const o = {}; for (const p of props) o[p] = s.getPropertyValue(p); return o; };
    const first = (sel) => document.querySelector(sel);
    const images = [...document.querySelectorAll('.et_pb_image img, .et_pb_main_blurb_image img')].map((i) => ({ src: (i.getAttribute('src') || '').slice(0, 40), natural: [i.naturalWidth, i.naturalHeight] }));
    return {
      button: cs(first('.et_pb_button'), ['color', 'background-color', 'border-top-width', 'border-top-color', 'border-top-style', 'font-size', 'padding-top']),
      tabControl: cs(first('.et_pb_tabs_controls li'), ['background-color', 'color']),
      tabControlActive: cs(first('.et_pb_tabs_controls li.et_pb_tab_active'), ['background-color', 'color']),
      tabsBar: cs(first('.et_pb_tabs_controls'), ['background-color']),
      accordionTitle: cs(first('.et_pb_toggle_title'), ['color', 'background-color']),
      accordionItem: cs(first('.et_pb_toggle'), ['background-color', 'border-top-color']),
      images,
      modulesSeen: [...new Set([...document.querySelectorAll('[class*="et_pb_"]')].flatMap((e) => [...e.classList].filter((c) => /^et_pb_(button|tabs|toggle|accordion|image|blurb)$/.test(c))))],
    };
  });
  await browser.close();
  const out = { divi: diviVersion, source: 'fixtures/valid/page-17-newpage.json (unstyled modules as the builder writes them)', measured: result };
  const file = path.join(ROOT, 'fixtures/shapes', `divi-defaults-${diviVersion}.json`);
  fs.writeFileSync(file, JSON.stringify(out, null, 2) + '\n');
  console.log(JSON.stringify(out, null, 2));
})().catch((e) => { console.error(e); process.exitCode = 1; }).finally(() => { pages.sweep(); });
