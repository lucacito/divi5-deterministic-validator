#!/usr/bin/env node
/*
 * Dev-only: regenerate the WordPress.org directory art in wporg-assets/
 * (icon, banner, screenshots). Not part of the plugin zip.
 *
 *   node scripts/build-wporg-art.cjs art            # icon-128/256 + banner-772x250/1544x500
 *   node scripts/build-wporg-art.cjs shots          # screenshot-1..3 from the running dev site
 *   node scripts/build-wporg-art.cjs shots 3        # only screenshot-3 (list numbers, e.g. "1,3")
 *   node scripts/build-wporg-art.cjs all
 *
 * Environment:
 *   PLAYWRIGHT_PATH   path to a Playwright install (default: ../layoutlab/node_modules/playwright)
 *   AIED_SITE         dev site origin (default: http://localhost:8181)
 *   AIED_COOKIES_FILE JSON file {"cookies":[{name,value,domain,path}, ...]} for a logged-in admin
 *                     session (shots only). Keep it OUTSIDE the repo and never commit it.
 *
 * Safety: before any screenshot the page DOM is masked. Every 64-hex-char string (the API key)
 * becomes YOUR_API_KEY and the site origin becomes https://your-site.com; the script refuses to
 * write a screenshot if either is still present. No credentials are stored by this script.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'wporg-assets');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');
const { chromium } = require(PW);

const NAME = 'JHMG AI Editor for Divi 5';
const SUBLINE = 'Edit Divi 5 pages in plain English. Every change is validated before it saves.';

// The "A✓" mark: a letter A, a sparkle and a check badge on an indigo→purple gradient tile.
function markSvg(size, rounded) {
  const r = rounded ? size * 0.22 : 0;
  return `
<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 256 256">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#4f46e5"/>
      <stop offset="1" stop-color="#6d28d9"/>
    </linearGradient>
  </defs>
  <rect width="256" height="256" rx="${(r / size) * 256}" fill="url(#g)"/>
  <text x="104" y="166" text-anchor="middle" font-family="Inter, Roboto, 'Helvetica Neue', Arial, sans-serif"
        font-size="128" font-weight="700" fill="#ffffff">A</text>
  <path d="M176 70 l6 16 l16 6 l-16 6 l-6 16 l-6 -16 l-16 -6 l16 -6 z" fill="#ffffff" fill-opacity="0.85"/>
  <circle cx="186" cy="160" r="37" fill="#ffffff" stroke="#5b3fe0" stroke-width="0"/>
  <path d="M168 161 l12 12 l24 -25" fill="none" stroke="#5b3fe0" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/>
</svg>`;
}

function iconHtml(size) {
  return `<!doctype html><html><head><meta charset="utf-8"><style>
    html,body{margin:0;padding:0;background:#fff}
    body{width:${size}px;height:${size}px;overflow:hidden}
    svg{display:block}
  </style></head><body>${markSvg(size, true)}</body></html>`;
}

function bannerHtml(w, h) {
  const s = w / 1544; // design at 1544×500, scale for 772×250
  return `<!doctype html><html><head><meta charset="utf-8">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;700;800&display=swap" rel="stylesheet">
  <style>
    html,body{margin:0;padding:0}
    body{width:${w}px;height:${h}px;overflow:hidden;font-family:Inter,Roboto,'Helvetica Neue',Arial,sans-serif;
      background:linear-gradient(115deg,#312e81 0%,#4338ca 38%,#4f46e5 60%,#6d28d9 100%);color:#fff;
      display:flex;align-items:center;box-sizing:border-box;padding:0 ${110 * s}px}
    .tile{flex:0 0 auto;width:${240 * s}px;height:${240 * s}px;border-radius:${52 * s}px;overflow:hidden;
      box-shadow:0 ${18 * s}px ${50 * s}px rgba(15,10,60,.35)}
    .tile svg{width:100%;height:100%;display:block}
    .txt{margin-left:${66 * s}px;flex:1 1 auto;min-width:0}
    h1{margin:0;font-weight:800;letter-spacing:-0.01em;line-height:1.05;white-space:nowrap;font-size:${78 * s}px}
    p{margin:${22 * s}px 0 0;font-weight:500;line-height:1.35;color:rgba(255,255,255,.88);font-size:${33 * s}px;max-width:${960 * s}px}
  </style></head><body>
    <div class="tile">${markSvg(240, false)}</div>
    <div class="txt"><h1 id="t">${NAME}</h1><p>${SUBLINE}</p></div>
  </body></html>`;
}

async function renderArt(browser) {
  const jobs = [
    ['icon-128x128.png', 128, 128, iconHtml(128)],
    ['icon-256x256.png', 256, 256, iconHtml(256)],
    ['banner-772x250.png', 772, 250, bannerHtml(772, 250)],
    ['banner-1544x500.png', 1544, 500, bannerHtml(1544, 500)],
  ];
  for (const [file, w, h, html] of jobs) {
    const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
    await page.setContent(html, { waitUntil: 'networkidle' });
    // Shrink the title until it fits on one line next to the tile.
    await page.evaluate(() => {
      const t = document.getElementById('t');
      if (!t) return;
      const box = t.parentElement;
      let size = parseFloat(getComputedStyle(t).fontSize);
      while (t.scrollWidth > box.clientWidth && size > 10) {
        size -= 1;
        t.style.fontSize = size + 'px';
      }
    });
    await page.screenshot({ path: path.join(OUT, file), clip: { x: 0, y: 0, width: w, height: h } });
    await page.close();
    console.log('[art] wrote', file);
  }
}

// Mask secrets and the dev URL in the DOM, tidy dev-only chrome, then verify nothing leaked.
async function maskAndTidy(page) {
  const origin = SITE;
  return page.evaluate((origin) => {
    const HEX = /[0-9a-f]{64}/gi;
    const fix = (s) => s.replace(HEX, 'YOUR_API_KEY').split(origin).join('https://your-site.com');
    const SKIP = new Set(['SCRIPT', 'STYLE', 'LINK', 'NOSCRIPT']);
    // Visible text in the body (snippets, spec URL, labels).
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      if (n.parentElement && SKIP.has(n.parentElement.tagName)) continue;
      const v = fix(n.nodeValue);
      if (v !== n.nodeValue) n.nodeValue = v;
    }
    // Attributes in the body (data-key, data-copy, hrefs, values). Resource URLs (src/srcset)
    // are left alone so images keep loading (avatar URLs carry a 64-hex email hash; the API key
    // never appears in a resource URL).
    for (const el of document.body.querySelectorAll('*')) {
      if (SKIP.has(el.tagName)) continue;
      for (const a of Array.from(el.attributes)) {
        if (a.name === 'src' || a.name === 'srcset') continue;
        const v = fix(a.value);
        if (v !== a.value) el.setAttribute(a.name, v);
      }
    }
    // Dev-environment chrome that is not part of the plugin: admin bar, other plugins' menus and notices.
    const keep = new Set(['menu-dashboard', 'menu-posts', 'menu-media', 'menu-pages', 'toplevel_page_ai-editor-divi5',
      'menu-appearance', 'menu-plugins', 'menu-users', 'menu-settings', 'collapse-menu']);
    document.querySelectorAll('#adminmenu > li').forEach((li) => {
      if (!keep.has(li.id) && !li.classList.contains('wp-menu-separator')) li.style.display = 'none';
    });
    document.querySelectorAll('#adminmenu .update-plugins, #adminmenu .awaiting-mod').forEach((e) => (e.style.display = 'none'));
    // WordPress moves every admin notice into the plugin's .wrap, so hide all of them except
    // .inline ones (the plugin's own Divi-compatibility notice). Screenshots are taken without
    // a ?notice= parameter, so no plugin success/error notice is on screen anyway.
    document.querySelectorAll('.notice:not(.inline), .update-nag, div.error, div.updated').forEach((n) => (n.style.display = 'none'));
    const upg = document.getElementById('footer-upgrade');
    if (upg) upg.style.display = 'none';
    const bar = document.getElementById('wpadminbar');
    if (bar) bar.style.display = 'none';
    document.documentElement.style.setProperty('padding-top', '0', 'important');
    const attrs = Array.from(document.body.querySelectorAll('*'))
      .filter((el) => !SKIP.has(el.tagName))
      .flatMap((el) => Array.from(el.attributes).filter((a) => a.name !== 'src' && a.name !== 'srcset').map((a) => a.value)).join('\n');
    const text = document.body.innerText;
    return { leakedKey: /[0-9a-f]{64}/i.test(text + attrs), leakedOrigin: text.includes(origin) };
  }, origin);
}

async function renderShots(browser) {
  const file = process.env.AIED_COOKIES_FILE;
  if (!file) throw new Error('AIED_COOKIES_FILE is required for screenshots');
  const { cookies } = JSON.parse(fs.readFileSync(file, 'utf8'));
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
  await ctx.addCookies(cookies);
  const shots = [
    // [file, tab, optional selector: crop just below this element]
    ['screenshot-1.png', 'dashboard', null],
    ['screenshot-2.png', 'settings', '.aied-view > .aied-card'], // the Connect card; not the dev site's activity log
    ['screenshot-3.png', 'features', null],
  ];
  const only = (process.argv[3] || '').split(',').filter(Boolean);
  for (const [out, tab, cropBelow] of shots) {
    if (only.length && !only.some((n) => out === `screenshot-${n}.png`)) continue;
    const page = await ctx.newPage();
    await page.goto(`${SITE}/wp-admin/admin.php?page=ai-editor-divi5&tab=${tab}`, { waitUntil: 'networkidle' });
    if (!(await page.locator('.aied-topbar').count())) throw new Error(`not logged in or plugin screen missing (${tab})`);
    const check = await maskAndTidy(page);
    if (check.leakedKey || check.leakedOrigin) throw new Error(`refusing to write ${out}: unmasked ${check.leakedKey ? 'key' : 'origin'}`);
    await page.addStyleTag({ content: '*{animation:none!important;transition:none!important;caret-color:transparent!important}' });
    const full = await page.evaluate((sel) => {
      if (sel) {
        const el = document.querySelector(sel);
        if (el) return Math.ceil(el.getBoundingClientRect().bottom + window.scrollY + 12);
      }
      return document.documentElement.scrollHeight;
    }, cropBelow);
    const height = Math.min(1400, full);
    await page.setViewportSize({ width: 1280, height });
    await page.screenshot({ path: path.join(OUT, out), clip: { x: 0, y: 0, width: 1280, height } });
    await page.close();
    console.log('[shots] wrote', out, `(1280x${height})`);
  }
  await ctx.close();
}

(async () => {
  const mode = process.argv[2] || 'all';
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  try {
    if (mode === 'art' || mode === 'all') await renderArt(browser);
    if (mode === 'shots' || mode === 'all') await renderShots(browser);
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e.message || e);
  process.exit(1);
});
