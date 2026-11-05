'use strict';
/*
 * Real-site gate for the Pro mega menu (make verify-pro-megamenu). Dev site only.
 *
 * For each of the 14 layouts, through the real MegaMenuService (scripts/verify-pro-megamenu.php does the PHP side, one step per call):
 *   dry run valid -> apply (saved once, second apply changed:false, Menu module hidden on desktop only) -> the front page in Chromium
 *   at 1280 (desktop, hover), 900 and 400 (touch: Menu module shown, mega wrapper hidden, no horizontal scroll) -> remove (blocks back
 *   to the baseline, second remove changed:false) -> undo of the remove batch (the applied header, byte for byte) -> undo of the apply
 *   batch (the pre-apply header, byte for byte).
 * Then the refusals, the history rows, and teardown (the header restored to the original bytes, every fixture removed).
 *
 * Output: one line per check, `PASS|FAIL  <layout> <width> <check>`, then per-width totals. Exit 1 on any FAIL.
 * Screenshots: dist/megamenu/<layout>.png (1280, the first dropdown open). `--layouts a,b` limits the run.
 */
const path = require('path');
const fs = require('fs');

const ROOT = path.resolve(__dirname, '..');
const SITE = 'http://localhost:8181';
const OUT = path.join(ROOT, 'dist', 'megamenu');
const { chromium } = require('/Users/Lucas/Documents/JHMG-Local/layoutlab/node_modules/playwright');
const { devSiteOnly, makeWp, loginCookies, watchConsole } = require(path.join(ROOT, 'scripts', 'lib', 'throwaway-pages.cjs'));

devSiteOnly(SITE);
const wp = makeWp(ROOT);
/** Layouts that list every sub-link in one flat row (filled from the PHP step `list`). */
const flatLayouts = new Set();
/** Layouts that take the owner's own headline, text, button and picture (filled from the PHP step `list`). */
const contentLayouts = new Set();
/** Layouts whose cards have an icon the owner can choose (filled from the PHP step `list`). */
const iconLayouts = new Set();

/** In the browser: the Font Awesome code (hex) of every Icon module in an element, and whether it is drawn with Font Awesome at weight 900. */
const iconCodes = (p) => [...p.querySelectorAll('.et-pb-icon')].map((e) => {
  const t = (e.textContent || '').trim();
  const b = getComputedStyle(e, '::before').content.replace(/^["']|["']$/g, '');
  const ch = t || b;
  const cs = getComputedStyle(e);
  return { code: ch ? ch.codePointAt(0).toString(16) : '', fa: /Font ?Awesome/i.test(cs.fontFamily), weight: cs.fontWeight, w: e.getBoundingClientRect().width };
});
const only = (process.argv.find((a) => a.startsWith('--layouts=')) || '').replace('--layouts=', '').split(',').filter(Boolean);

/** One PHP step of scripts/verify-pro-megamenu.php: its RESULT JSON, or throws with the ERROR message. */
function step(...args) {
  let out;
  try {
    out = wp('eval-file', '/scripts/verify-pro-megamenu.php', ...args);
  } catch (e) {
    out = String(e.stdout || '') + String(e.stderr || '');
  }
  const line = out.split('\n').reverse().find((l) => /^(RESULT|ERROR) /.test(l));
  if (!line) throw new Error('step ' + args.join(' ') + ' printed no result: ' + out.slice(-400));
  const data = JSON.parse(line.slice(line.indexOf(' ') + 1));
  if (line.startsWith('ERROR ')) throw new Error('step ' + args.join(' ') + ' failed: ' + data.message);
  return data;
}

const tally = { '1280': [0, 0], '900': [0, 0], '400': [0, 0], '-': [0, 0] };
const failures = [];
function check(layout, width, name, ok, detail) {
  const w = String(width);
  tally[w] = tally[w] || [0, 0];
  tally[w][ok ? 0 : 1]++;
  const line = `${ok ? 'PASS' : 'FAIL'}  ${layout} ${w} ${name}${detail !== undefined && detail !== '' ? '  ' + detail : ''}`;
  console.log(line);
  if (!ok) failures.push(line);
}

const SHOWN = (el) => {
  if (!el) return false;
  const cs = getComputedStyle(el);
  const r = el.getBoundingClientRect();
  return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0;
};

/** Tags the header's top-level links (data-aied-mm="top-i" on the <a>), their dropdown panels ("panel-i") and the mega wrapper ("outer"). */
function tagNav() {
  const tops = [...document.querySelectorAll('a.et_pb_link')].filter((a) => /_tb_header/.test(String(a.className)) && !a.closest('.et_pb_dropdown'));
  tops.forEach((a, i) => {
    a.setAttribute('data-aied-mm', 'top-' + i);
    const p = a.parentElement && [...a.parentElement.children].find((c) => c.classList.contains('et_pb_dropdown'));
    if (p) p.setAttribute('data-aied-mm', 'panel-' + i);
  });
  let outer = null;
  if (tops.length) {
    const nav = tops[0].parentElement;
    outer = nav && nav.parentElement;
    if (outer) outer.setAttribute('data-aied-mm', 'outer');
  }
  const menu = document.querySelector('.et_pb_menu[class*="_tb_header"]') || document.querySelector('.et_pb_menu');
  if (menu) menu.setAttribute('data-aied-mm', 'menu');
  return {
    labels: tops.map((a) => a.textContent.trim()),
    dropdowns: tops.map((a, i) => (document.querySelector('[data-aied-mm="panel-' + i + '"]') ? i : -1)).filter((i) => i >= 0),
    hasOuter: !!outer,
  };
}

/** Colour contrast of every link and paragraph inside the panel, against the painted backdrop (composited through translucent layers). */
function contrastInPanel(panelSel) {
  const parse = (c) => {
    const m = String(c).match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const v = m[1].split(/[\s,\/]+/).filter(Boolean).map(Number);
    return { r: v[0], g: v[1], b: v[2], a: v.length > 3 ? v[3] : 1 };
  };
  const over = (fg, bg) => ({ r: fg.r * fg.a + bg.r * (1 - fg.a), g: fg.g * fg.a + bg.g * (1 - fg.a), b: fg.b * fg.a + bg.b * (1 - fg.a), a: 1 });
  const backdrop = (el) => {
    const layers = [];
    let e = el;
    while (e && e.nodeType === 1) {
      const c = parse(getComputedStyle(e).backgroundColor);
      if (c && c.a > 0) {
        layers.push(c);
        if (c.a >= 1) break;
      }
      e = e.parentElement;
    }
    let base = { r: 255, g: 255, b: 255, a: 1 };
    for (let i = layers.length - 1; i >= 0; i--) base = over(layers[i], base);
    return base;
  };
  const lum = (c) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
  };
  const panel = document.querySelector(panelSel);
  if (!panel) return { count: 0, min: null, worst: null };
  let min = Infinity;
  let worst = null;
  let count = 0;
  for (const el of panel.querySelectorAll('a.et_pb_link, a.et_pb_button, .et_pb_text p, .et_pb_heading h1, .et_pb_heading h2, .et_pb_heading h3')) {
    // A Divi link keeps its text in nested spans and colours the inner one; everything else holds its text directly.
    const isLink = el.matches('a.et_pb_link');
    const own = isLink ? (el.textContent || '').trim() : [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent).join('').trim();
    const vis = getComputedStyle(el); const vr = el.getBoundingClientRect();
    if (!own || vis.display === 'none' || vis.visibility === 'hidden' || vr.width === 0 || vr.height === 0) continue;
    const fg0 = parse(getComputedStyle(isLink ? (el.querySelector('.et_pb_link_inner') || el) : el).color);
    if (!fg0) continue;
    const bg = backdrop(el);
    const fg = fg0.a < 1 ? over(fg0, bg) : fg0;
    const L1 = lum(fg);
    const L2 = lum(bg);
    const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    count++;
    if (ratio < min) {
      min = ratio;
      worst = own.slice(0, 40);
    }
  }
  return { count, min: count ? Math.round(min * 100) / 100 : null, worst };
}

/** Anything inside the panel that sticks out of it, or a clipped box. */
function clipInPanel(panelSel) {
  const panel = document.querySelector(panelSel);
  if (!panel) return ['no panel'];
  const pr = panel.getBoundingClientRect();
  const bad = [];
  for (const el of panel.querySelectorAll('*')) {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) continue;
    if ((el.textContent || '').trim() || el.tagName === 'IMG') {
      if (r.right > pr.right + 1 || r.left < pr.left - 1 || r.bottom > pr.bottom + 1) bad.push(el.tagName + ' ' + String(el.className).slice(0, 40));
    }
    if ((cs.overflowX === 'hidden' || cs.overflowX === 'clip') && el.scrollWidth > el.clientWidth + 1) bad.push('clipped ' + el.tagName);
  }
  const vw = window.innerWidth;
  if (pr.left < -0.5 || pr.right > vw + 0.5) bad.push(`panel outside the viewport (${Math.round(pr.left)}..${Math.round(pr.right)} of ${vw})`);

  return bad.slice(0, 6);
}

async function openPage(browser, width, height, touch) {
  const ctx = await browser.newContext({ viewport: { width, height }, hasTouch: touch, isMobile: touch && width < 600 });
  const page = await ctx.newPage();
  const errors = watchConsole(page, SITE);
  const resp = await page.goto(SITE + '/', { waitUntil: 'load' });
  await page.waitForTimeout(400);
  return { ctx, page, errors, status: resp ? resp.status() : 0 };
}

async function pageSanity(page, layout, width, status, errors) {
  const text = await page.evaluate(() => document.body.innerText.slice(0, 20000));
  check(layout, width, 'HTTP 200', status === 200, 'status ' + status);
  const bad = /critical error|fatal error|parse error|there has been a critical/i.test(text);
  check(layout, width, 'no PHP error text on the page', !bad);
  const horizontal = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
  check(layout, width, 'no horizontal scroll', horizontal);
  return text;
}

async function desktop(browser, layout, setup, ap) {
  const W = 1280;
  const H = 900;
  const { ctx, page, errors, status } = await openPage(browser, W, H, false);
  const expectDropdowns = setup.panels;
  try {
    await page.waitForFunction((n) => document.querySelectorAll('.et_pb_dropdown_initialized').length >= n, expectDropdowns, { timeout: 10000 }).catch(() => {});
    await pageSanity(page, layout, W, status, errors);
    const nav = await page.evaluate(tagNav);
    check(layout, W, 'one top-level link per menu item', JSON.stringify(nav.labels) === JSON.stringify(setup.top.map((t) => t.title)), JSON.stringify(nav.labels));
    check(layout, W, 'one dropdown per item with sub-items', nav.dropdowns.length === setup.panels, `${nav.dropdowns.length} of ${setup.panels}`);
    check(layout, W, 'mega wrapper is shown on desktop', nav.hasOuter && (await page.locator('[data-aied-mm="outer"]').evaluate(SHOWN)));
    check(layout, W, 'native Menu module is hidden on desktop', !(await page.locator('[data-aied-mm="menu"]').evaluate(SHOWN)));

    // Effects of the attribute paths still marked pending: the top-level label font and hover colour, the label padding, the logo.
    const labelInfo = await page.locator('[data-aied-mm="top-0"]').evaluate((a) => {
      const inner = a.querySelector('.et_pb_link_inner') || a;
      const cs = getComputedStyle(inner);
      const w = a.parentElement;
      return { size: cs.fontSize, weight: cs.fontWeight, color: cs.color, padTop: getComputedStyle(a).paddingTop, wrapPadTop: getComputedStyle(w).paddingTop, innerTag: inner.tagName + '.' + String(inner.className).slice(0, 30), text: a.textContent.trim() };
    });
    check(layout, W, 'effect divi/link font size 16px (label, inner text)', labelInfo.size === '16px', labelInfo.size + ' ' + labelInfo.innerTag);
    check(layout, W, 'effect divi/link font weight 600 (label, inner text)', labelInfo.weight === '600', labelInfo.weight);
    check(layout, W, 'effect divi/link font colour (label rest)', labelInfo.color === 'rgb(78, 106, 109)', labelInfo.color);
    const padOk = labelInfo.padTop === '10px' || labelInfo.wrapPadTop === '10px';
    check(layout, W, 'effect divi/link padding top 10px (label or wrapper)', padOk, `a ${labelInfo.padTop}, wrapper ${labelInfo.wrapPadTop}`);

    // Hover colour of the label (rest vs hover), measured on a real hover.
    const hoverLabel = page.locator('[data-aied-mm="top-0"]');
    await hoverLabel.hover();
    await page.waitForTimeout(250);
    const hoverColor = await hoverLabel.evaluate((a) => getComputedStyle(a.querySelector('.et_pb_link_inner') || a).color);
    check(layout, W, 'effect divi/link hover colour (label)', hoverColor !== labelInfo.color, `rest ${labelInfo.color}, hover ${hoverColor}`);

    // The logo copy: source, alt and the link.
    const logo = await page.locator('[data-aied-mm="outer"] img').first().evaluate((img) => ({ src: img.getAttribute('src'), alt: img.getAttribute('alt'), title: img.getAttribute('title'), href: img.closest('a') ? img.closest('a').getAttribute('href') : null, nw: img.naturalWidth })).catch(() => null);
    check(layout, W, 'effect divi/image logo copy (src, title, link)', !!logo && /icon-256x256/.test(logo.src || '') && logo.title === 'icon-256x256' && !!logo.href, logo ? `${logo.title} -> ${logo.href}` : 'no logo');

    // Each top-level item with a dropdown: opens on hover, placed in the viewport, on top, stays open on the way in, every
    // sub-link present with its href, contrast, clipping, images, the newest posts, closes on leave.
    // Entrance animation: when the panel opens its cards are animating (Divi's `et_animated` class, opacity below 1) and the animation has
    // ended and left them fully visible a second later; closing and opening again plays it again.
    if (ap.layout_cards && nav.dropdowns.length) {
      const i0 = nav.dropdowns[0];
      const probe = () => page.evaluate((sel) => {
        const p = document.querySelector(sel);
        const g = p ? [...p.querySelectorAll('div.et_pb_group')].filter((e) => getComputedStyle(e).boxShadow !== 'none' && parseFloat(getComputedStyle(e).borderTopLeftRadius) >= 14) : [];
        return { n: g.length, animating: g.filter((e) => /\bet_animated\b/.test(e.className) || getComputedStyle(e).animationName !== 'none').length, minOpacity: g.length ? Math.min(...g.map((e) => parseFloat(getComputedStyle(e).opacity))) : null };
      }, `[data-aied-mm="panel-${i0}"]`);
      for (const round of [1, 2]) {
        await page.mouse.move(5, 880);
        await page.waitForTimeout(500);
        await page.locator(`[data-aied-mm="top-${i0}"]`).hover();
        let seen = null;
        for (let k = 0; k < 8 && !(seen && seen.animating > 0); k++) { seen = await probe(); if (!(seen.animating > 0)) await page.waitForTimeout(25); }
        check(layout, W, `entrance animation plays when the panel opens (open ${round}: ${seen ? seen.animating : 0} of ${seen ? seen.n : 0} cards animating)`, !!seen && seen.n > 0 && seen.animating > 0);
        await page.waitForTimeout(1200);
        const done = await probe();
        check(layout, W, `entrance animation has ended and the cards are fully visible (open ${round})`, done.animating === 0 && done.minOpacity === 1, JSON.stringify(done));
      }
      await page.mouse.move(5, 880);
      await page.waitForTimeout(500);
    }
    let shotDone = false;
    for (const i of nav.dropdowns) {
      const title = setup.top[i].title;
      const ownHere = contentLayouts.has(layout) && title === 'Services';
      const iconsHere = iconLayouts.has(layout) && layout !== 'directory-counts' && title === 'Services';
      const directoryHere = layout === 'directory-counts' && title === 'Services';
      const label = page.locator(`[data-aied-mm="top-${i}"]`);
      const panel = page.locator(`[data-aied-mm="panel-${i}"]`);
      const panelSel = `[data-aied-mm="panel-${i}"]`;
      await page.mouse.move(5, 880);
      await page.waitForTimeout(350);
      check(layout, W, `"${title}" panel closed before hover`, !(await panel.evaluate(SHOWN)));
      await label.hover();
      await page.waitForTimeout(1100); // the cards' entrance animation (450ms plus up to 350ms of stagger) must have ended before anything is measured
      const open = await panel.evaluate(SHOWN);
      check(layout, W, `"${title}" opens on hover`, open);
      if (!open) continue;
      const box = await panel.boundingBox();
      check(layout, W, `"${title}" panel inside the viewport horizontally`, box.x >= -0.5 && box.x + box.width <= W + 0.5, `x ${Math.round(box.x)}..${Math.round(box.x + box.width)}`);
      const cx = box.x + box.width / 2;
      const cy = Math.min(box.y + box.height / 2, H - 2);
      const onTop = await panel.evaluate((p, o) => { const el = document.elementFromPoint(o.x, o.y); return !!el && p.contains(el); }, { x: cx, y: cy });
      check(layout, W, `"${title}" panel is on top at its centre`, onTop);
      // Travel from the label into the panel: it must stay open.
      const lb = await label.boundingBox();
      const tx = Math.min(Math.max(lb.x + lb.width / 2, box.x + 12), box.x + box.width - 12);
      await page.mouse.move(lb.x + lb.width / 2, lb.y + lb.height / 2);
      await page.mouse.move(tx, box.y + 12, { steps: 14 });
      await page.waitForTimeout(120);
      check(layout, W, `"${title}" stays open moving from the label into the panel`, await panel.evaluate(SHOWN));
      // Every sub-link of the item, with its href, inside the panel.
      // A second-level item that has a third level is a non-clickable label (bold text, no link), except in a flat posts panel.
      const flat = flatLayouts.has(layout);
      const leaves = setup.top[i].leaves.map((l) => ({ ...l, label: !!l.label && !flat }));
      const present = await panel.evaluate((p, want) => want.map((l) => {
        const link = [...p.querySelectorAll('a[href]')].some((a) => a.getAttribute('href') === l.url && a.textContent.trim() === l.title);
        if (!l.label) return link ? 'ok' : 'missing';
        const asLink = [...p.querySelectorAll('a')].some((a) => a.textContent.trim() === l.title);
        const text = [...p.querySelectorAll('p')].find((e) => e.textContent.trim() === l.title);
        if (asLink) return 'clickable';
        if (!text) return 'missing';
        const cs = getComputedStyle(text);
        return cs.fontWeight === '700' ? 'ok' : 'weight ' + cs.fontWeight;
      }), leaves);
      const missing = leaves.filter((l, k) => present[k] !== 'ok').map((l, k) => l.title);
      const bad = leaves.map((l, k) => [l, present[k]]).filter(([, r]) => r !== 'ok').map(([l, r]) => `${l.title} (${r})`);
      const labels = leaves.filter((l) => l.label).length;
      check(layout, W, `"${title}" every sub-link in the panel with its href (${leaves.length - labels})${labels ? ` and ${labels} non-clickable bold label${labels > 1 ? 's' : ''}` : ''}`, bad.length === 0, bad.slice(0, 4).join(', '));
      // Contrast of the text (4.5:1 against the painted backdrop).
      const cc = await page.evaluate(contrastInPanel, panelSel);
      check(layout, W, `"${title}" text contrast >= 4.5:1 (${cc.count} texts)`, cc.count === 0 || cc.min >= 4.5, cc.count ? `min ${cc.min} at "${cc.worst}"` : 'no text');
      // Clipping and overflow.
      const clip = await page.evaluate(clipInPanel, panelSel);
      check(layout, W, `"${title}" nothing clipped or outside the panel`, clip.length === 0, clip.join('; '));
      // Images: loaded and sized.
      const imgs = await panel.evaluate(async (p) => {
        const list = [...p.querySelectorAll('img')];
        await Promise.all(list.map((i) => (i.complete ? Promise.resolve() : new Promise((r) => { i.onload = r; i.onerror = r; setTimeout(r, 3000); }))));
        return list.map((i) => ({ src: i.getAttribute('src'), nw: i.naturalWidth, alt: i.getAttribute('alt') }));
      });
      if (imgs.length) {
        check(layout, W, `"${title}" panel images have naturalWidth > 0 (${imgs.length})`, imgs.every((im) => im.nw > 0), JSON.stringify(imgs.filter((im) => !(im.nw > 0)).slice(0, 2)));
        check(layout, W, `"${title}" panel images carry a pack or post-thumbnail src and no alt text (the owner's picture has its Media Library alt)`, imgs.every((im) => (/card-blue-1\.svg$/.test(im.src || '') || /\/uploads\//.test(im.src || '')) && (im.alt === '' || im.alt === null || (ownHere && im.alt === setup.own.alt))), JSON.stringify(imgs.slice(0, 1)));
      }
      // The owner's own content (set up for "Services" before the run) on the layouts that have a place for it.
      if (ownHere) {
        const o = await panel.evaluate((p) => ({
          headings: [...p.querySelectorAll('h1,h2,h3,h4')].map((e) => e.textContent.trim()),
          paras: [...p.querySelectorAll('p')].map((e) => e.textContent.trim()),
          links: [...p.querySelectorAll('a[href]')].map((a) => ({ text: a.textContent.trim(), href: a.getAttribute('href') })),
          imgs: [...p.querySelectorAll('img')].map((e) => ({ src: e.getAttribute('src') || '', alt: e.getAttribute('alt'), nw: e.naturalWidth })),
        }));
        const file = setup.own.imageUrl.split('/').pop();
        check(layout, W, `"${title}" own headline replaces the item title on the card or panel`, o.headings.includes(setup.own.title), o.headings.join(' | '));
        check(layout, W, `"${title}" own text is shown`, o.paras.includes(setup.own.text), o.paras.slice(0, 3).join(' | '));
        check(layout, W, `"${title}" own button label and link`, o.links.some((l) => l.text === setup.own.button && l.href === setup.own.url), JSON.stringify(o.links.filter((l) => /View all|Gate/.test(l.text))));
        check(layout, W, `"${title}" own picture is shown, loaded, with the Media Library alt text`, o.imgs.some((m) => m.src.endsWith(file) && m.nw > 0 && m.alt === setup.own.alt), JSON.stringify(o.imgs.slice(0, 2)));
        check(layout, W, `"${title}" the placeholder picture is gone when the owner has their own`, !o.imgs.some((m) => /card-blue-1\.svg/.test(m.src)));
      }
      // The icons the owner chose (a rocket for "Design" and "Grow", a house for "Build"), drawn with Font Awesome in the chips; the footer arrow stays.
      if (iconsHere) {
        const ic = await panel.evaluate(iconCodes);
        const want = ['f135', 'f015', 'f135'].concat(layout === 'icons-cards' ? ['f061'] : []);
        check(layout, W, `"${title}" the cards show the chosen icons (rocket for "Design", house for "Build", rocket for "Grow") and the footer arrow stays`, JSON.stringify(ic.map((x) => x.code)) === JSON.stringify(want), JSON.stringify(ic.map((x) => x.code)));
        check(layout, W, `"${title}" every icon is drawn with Font Awesome at weight 900 and has a width`, ic.length > 0 && ic.every((x) => x.fa && x.weight === '900' && x.w > 0), JSON.stringify(ic.filter((x) => !(x.fa && x.weight === '900' && x.w > 0))));
      }
      // The grouped list: the owner's star on the first row and the arrow on the others, the three group names as small capitalised labels, no pill (custom links have no count).
      if (directoryHere) {
        const dh = await panel.evaluate((p) => ({
          icons: [...p.querySelectorAll('.et-pb-icon')].map((e) => (e.textContent || '').trim().codePointAt(0)),
          heads: ['Design', 'Build', 'Grow'].map((t) => { const e = [...p.querySelectorAll('p')].find((x) => x.textContent.trim() === t); const cs = e && getComputedStyle(e); return e ? { t, up: cs.textTransform, ls: cs.letterSpacing, fs: cs.fontSize } : { t, up: 'missing' }; }),
          rowLinks: [...p.querySelectorAll('a[href]')].length,
        }));
        check(layout, W, `"${title}" ten rows: the star the owner chose for "Branding" first, the arrow on the other nine`, dh.icons.length === 10 && dh.icons[0] === 0xf005 && dh.icons.slice(1).every((c) => c === 0xf061), JSON.stringify(dh.icons));
        check(layout, W, `"${title}" the group names are small capitalised labels with letter spacing`, dh.heads.every((h) => h.up === 'uppercase' && h.ls === '1px' && h.fs === '12px'), JSON.stringify(dh.heads));
      }
      // The newest posts: the "latest" layouts show the newest posts' permalinks.
      if (ap.layout_posts) {
        const want = setup.post_urls.slice(0, ap.layout_posts);
        const has = await panel.evaluate((p, urls) => urls.map((u) => [...p.querySelectorAll('a[href]')].some((a) => a.getAttribute('href') === u)), want);
        check(layout, W, `"${title}" the ${ap.layout_posts} newest posts are in the panel`, has.every(Boolean), `${has.filter(Boolean).length} of ${want.length}`);
      }
      // The "View all" button: text, link and font.
      const btn = (await panel.locator('a.et_pb_button').count()) === 0 ? null : await panel.locator('a.et_pb_button').first().evaluate((a) => ({ text: a.textContent.trim(), href: a.getAttribute('href'), size: getComputedStyle(a).fontSize, weight: getComputedStyle(a).fontWeight })).catch(() => null);
      if (btn) {
        check(layout, W, `"${title}" button text and link (divi/button)`, btn.text === (ownHere ? setup.own.button : 'View all') && btn.href === (ownHere ? setup.own.url : setup.top[i].url), `${btn.text} -> ${btn.href}`);
        check(layout, W, `"${title}" button font size and weight (divi/button)`, btn.size === '14px' && btn.weight === '600', `${btn.size} ${btn.weight}`);
      }
      // The look: a gradient is drawn, the panel has its accent top edge, the cards rise, glow and (tiles) take the accent frame on hover,
      // and the promo/banner button is a filled pill.
      if (!shotDone) {
        const look = await panel.evaluate((p) => {
          const grads = [p, ...p.querySelectorAll('div')].filter((e) => /linear-gradient/.test(getComputedStyle(e).backgroundImage));
          const edge = [p, ...p.querySelectorAll(':scope > div')].map((e) => getComputedStyle(e)).find((cs) => parseFloat(cs.borderTopWidth) >= 3);
          const cards = [...p.querySelectorAll('div.et_pb_group')].filter((e) => getComputedStyle(e).boxShadow !== 'none' && parseFloat(getComputedStyle(e).borderTopLeftRadius) >= 14);
          const pill = [...p.querySelectorAll('a.et_pb_button')].map((a) => getComputedStyle(a)).find(() => true);
          return { gradients: grads.length, edge: edge ? edge.borderTopWidth + ' ' + edge.borderTopColor : null, cards: cards.length, pill: pill ? { bg: pill.backgroundColor, radius: pill.borderTopLeftRadius, border: pill.borderTopWidth } : null };
        });
        check(layout, W, `"${title}" a gradient is drawn in the panel (${look.gradients})`, look.gradients >= 1);
        check(layout, W, `"${title}" the panel has a 3px accent top edge`, look.edge !== null, String(look.edge));
        if (ap.layout_cards) {
          check(layout, W, `"${title}" cards with a radius and a resting shadow are present (${look.cards})`, look.cards >= 1);
          const lift = await panel.evaluate(async (p) => {
            const all = [...p.querySelectorAll('div.et_pb_group')].filter((e) => getComputedStyle(e).boxShadow !== 'none' && parseFloat(getComputedStyle(e).borderTopLeftRadius) >= 14 && e.getBoundingClientRect().height > 40);
            const card = all.find((e) => parseFloat(getComputedStyle(e).borderTopWidth) >= 1) || all[0];
            if (!card) return null;
            card.setAttribute('data-aied-card', '1');
            const r = card.getBoundingClientRect();
            return { x: r.x + r.width / 2, y: r.y + r.height / 2, top: r.top, shadow: getComputedStyle(card).boxShadow, frame: getComputedStyle(card).borderTopColor, framed: parseFloat(getComputedStyle(card).borderTopWidth) >= 1 };
          });
          if (lift) {
            await page.mouse.move(lift.x, lift.y, { steps: 6 });
            await page.waitForTimeout(550);
            const after = await panel.evaluate((p) => { const c = p.querySelector('[data-aied-card="1"]'); if (!c) return null; const cs = getComputedStyle(c); return { top: c.getBoundingClientRect().top, shadow: cs.boxShadow, frame: cs.borderTopColor, transform: cs.transform }; });
            check(layout, W, `"${title}" a card rises 4px on hover`, !!after && Math.abs((lift.top - after.top) - 4) <= 1.2, after ? `moved ${(lift.top - after.top).toFixed(1)}px, ${after.transform}` : 'card gone');
            check(layout, W, `"${title}" a card's shadow changes on hover`, !!after && after.shadow !== lift.shadow, after ? after.shadow.slice(0, 60) : '');
            if (lift.framed) check(layout, W, `"${title}" a tile's frame takes the accent on hover`, !!after && after.frame !== lift.frame, after ? `${lift.frame} -> ${after.frame}` : '');
            await label.hover();
            await page.waitForTimeout(250);
          }
        }
        if (ap.layout_pill && look.pill) {
          check(layout, W, `"${title}" the button is a filled pill (fill ${look.pill.bg}, radius ${look.pill.radius})`, parseFloat(look.pill.radius) >= 20 && look.pill.border === '0px' && /^rgb\((255, 255, 255|11, 16, 32)\)$/.test(look.pill.bg));
        }
      }
      // Panel link font (the rows), once.
      if (!shotDone) {
        await label.hover();
        await page.waitForTimeout(300);
        const f = await panel.evaluate((p) => [...p.querySelectorAll('a.et_pb_link')].map((a) => { const t = getComputedStyle(a.querySelector('.et_pb_link_inner') || a); return { size: t.fontSize, weight: t.fontWeight, color: t.color }; }));
        check(layout, W, 'effect divi/link font size in the panel (written sizes 12-16px, plus 17px for a showcase tile heading; 14 or 15 present)', f.every((x) => /^1[2-6]px$/.test(x.size) || (layout.startsWith('showcase-') && x.size === '17px')) && f.some((x) => x.size === '15px' || x.size === '14px'), [...new Set(f.map((x) => x.size))].join('/'));
        // The weights written on links (500/600/700) and on a label's text (700, a Text module); a panel whose only titled items are labels has no heavy link.
        const lw = await panel.evaluate((p) => [...p.querySelectorAll('div.et_pb_text p')].map((e) => getComputedStyle(e).fontWeight));
        check(layout, W, 'effect divi/link and divi/text font weight in the panel (links and labels)', f.concat(lw.map((w) => ({ weight: w }))).some((x) => ['500', '600', '700'].includes(x.weight)), [...new Set(f.map((x) => x.weight).concat(lw))].join('/'));
        const tone = (await page.evaluate(() => getComputedStyle(document.body).backgroundColor));
        check(layout, W, 'effect divi/link colour in the panel (palette text or muted)', f.some((x) => /rgb\((27|242), (33|244), (48|248)\)|rgb\((74|180), (84|188), (104|203)\)/.test(x.color)), [...new Set(f.map((x) => x.color))].slice(0, 3).join(' | ') + ' body ' + tone);
        // Hover colour of a panel link.
        const link = panel.locator('a.et_pb_link').first();
        const restC = await link.evaluate((a) => getComputedStyle(a.querySelector('.et_pb_link_inner') || a).color);
        await link.hover();
        await page.waitForTimeout(250);
        const hoverC = await link.evaluate((a) => getComputedStyle(a.querySelector('.et_pb_link_inner') || a).color);
        check(layout, W, 'effect divi/link hover colour in the panel', restC !== hoverC, `rest ${restC}, hover ${hoverC}`);
        await label.hover();
        await page.waitForTimeout(250);
      }
      // Screenshot with the first dropdown open.
      if (!shotDone) {
        fs.mkdirSync(OUT, { recursive: true });
        await page.screenshot({ path: path.join(OUT, layout + '.png'), clip: { x: 0, y: 0, width: W, height: Math.min(H, Math.ceil(box.y + box.height) + 20) } });
        shotDone = true;
      }
      // Leave: closes.
      await page.mouse.move(5, 880);
      await page.waitForTimeout(400);
      check(layout, W, `"${title}" closes when the pointer leaves`, !(await panel.evaluate(SHOWN)));
    }
    check(layout, W, 'no console error from the page', errors.length === 0, errors.slice(0, 2).join(' | '));
  } finally {
    await ctx.close();
  }
}

async function narrow(browser, layout, width, height, setup) {
  const { ctx, page, errors, status } = await openPage(browser, width, height, true);
  try {
    await pageSanity(page, layout, width, status, errors);
    await page.evaluate(tagNav);
    const state = await page.evaluate(() => {
      const menu = document.querySelector('.et_pb_menu[class*="_tb_header"]') || document.querySelector('.et_pb_menu');
      return { menuShown: !!menu && getComputedStyle(menu).display !== 'none' && menu.getBoundingClientRect().height > 0 };
    });
    const megaHidden = await page.evaluate(() => {
      const o = document.querySelector('[data-aied-mm="outer"]');
      return !o || getComputedStyle(o).display === 'none' || o.getBoundingClientRect().width === 0;
    });
    check(layout, width, 'Menu module is shown (the hamburger)', state.menuShown);
    check(layout, width, 'mega wrapper is hidden', megaHidden);
    check(layout, width, 'no console error from the page', errors.length === 0, errors.slice(0, 2).join(' | '));
  } finally {
    await ctx.close();
  }
}

const TAB = SITE + '/wp-admin/admin.php?page=ai-editor-divi5&tab=megamenu';

/** An administrator's logged-in session in its own browser context. */
async function adminSession(browser) {
  // Reduced motion: WordPress then skips its page transition, which in headless Chromium can stall rendering after a redirect.
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
  await ctx.addCookies(loginCookies(wp).map(([name, value]) => ({ name, value, url: SITE })));
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  return { ctx, page, errors };
}

/** The WordPress notice of the add-on on a plain admin screen. */
function readDash(page) {
  return page.evaluate(() => {
    const n = document.querySelector('.aied-mm-wpnotice');
    return { notice: !!n, text: n ? n.innerText.replace(/\s+/g, ' ') : '', button: !!(n && n.querySelector('button[type=submit]')), link: n && n.querySelector('a') ? n.querySelector('a').getAttribute('href') : null };
  });
}

/**
 * Presses a button of an admin form and waits until the add-on has redirected back to the tab with the given notice code (WordPress admin
 * uses page transitions, which `waitForNavigation` can miss; the notice in the address is also what the press was meant to cause).
 */
async function pressAndWait(page, selector, notice) {
  await Promise.all([
    page.waitForURL((u) => u.toString().includes('aied_pro_notice=' + notice), { timeout: 40000, waitUntil: 'load' }),
    page.click(selector),
  ]);
}

/** What the Mega menu tab shows right now; `overflow` counts elements that stick out of the box that holds them. */
function readTab(page) {
  return page.evaluate(() => {
    const root = document.querySelector('#megamenu');
    const txt = (e) => (e ? e.textContent.replace(/\s+/g, ' ').trim() : '');
    const val = (sel) => { const e = root.querySelector(sel); return e ? e.value : null; };
    const cur = [...root.querySelectorAll('.aied-mm-card.is-current')];
    const out = [...root.querySelectorAll('select, .aied-mm-card, .aied-mm-item, .aied-mm-badge, .aied-mm-srow')].filter((e) => e.getBoundingClientRect().right > e.parentElement.getBoundingClientRect().right + 1);
    const notice = [...root.querySelectorAll('.notice.inline')].find((n) => !n.classList.contains('aied-mm-drift') && !n.classList.contains('aied-mm-pending'));
    const pending = root.querySelector('.aied-mm-pending');
    const byText = (sel, t) => [...root.querySelectorAll(sel)].find((e) => txt(e) === t);
    return {
      wpNoticeOnTab: !!document.querySelector('.aied-mm-wpnotice'),
      tabNotice: !!root.querySelector('.aied-mm-drift'),
      pending: !!pending,
      pendingText: txt(pending),
      noticeText: txt(notice),
      status: txt(root.querySelector('.aied-mm-status')),
      items: [...root.querySelectorAll('a.aied-mm-item')].map((a) => ({ name: txt(a.querySelector('.aied-mm-iname')), layout: txt(a.querySelector('.aied-mm-isub')).replace(/(Customised|Edited in Divi|Out of date)/g, '').trim(), pills: [...a.querySelectorAll('.aied-mm-pill')].map(txt), selected: a.classList.contains('is-selected') })),
      panelTitle: txt(root.querySelector('.aied-mm-ehead h3')),
      current: cur.map((c) => ({ name: txt(c.querySelector('h4')), badge: txt(c.querySelector('.aied-mm-badge')) })),
      cards: root.querySelectorAll('.aied-mm-card').length,
      cardSelects: root.querySelectorAll('.aied-mm-card select').length,
      overflow: out.length,
      helpClosed: !!root.querySelector('details.aied-mm-help') && !root.querySelector('details.aied-mm-help').open,
      textLength: root.innerText.replace(/\s+/g, ' ').length,
      fields: { title: val('#aied-mm-title'), text: val('#aied-mm-text'), button: val('#aied-mm-button'), url: val('#aied-mm-url'), image: val('#aied-mm-image') },
      pickerSrc: (root.querySelector('#aied-mm-picked img') || {}).src || null,
      openHref: (byText('a.button', 'Open in Divi') || {}).href || null,
      builderPill: txt(root.querySelector('.aied-mm-srow .aied-mm-pill')),
      menuSelect: root.querySelector('#aied-mm-menu') ? [...root.querySelectorAll('#aied-mm-menu option')].map((o) => ({ value: o.value, label: txt(o), selected: o.selected })) : null,
      replaceButton: !!byText('button', 'Replace my version'),
    };
  });
}

/** The Dashboard and the Mega menu tab (`query`: extra arguments such as `&item=5&etab=layout`) in a fresh admin session. */
async function adminView(browser, opts = {}) {
  const s = await adminSession(browser);
  try {
    await s.page.goto(SITE + '/wp-admin/index.php', { waitUntil: 'load' });
    const dash = await readDash(s.page);
    await s.page.goto(TAB + (opts.query || ''), { waitUntil: 'load' });
    const tab = await readTab(s.page);
    return { dash, tab, errors: s.errors };
  } finally {
    await s.ctx.close();
  }
}

(async () => {
  const setup = step('setup');
  console.log(`setup: menu ${setup.menu_id}, ${setup.items} items, header ${setup.header_id}, baseline in place`);
  /** What the front end shows in the panels of the given top-level items, hovered in a real browser at 1280 px. */
  const frontPanels = async (browser, names) => {
    const { ctx, page, errors, status } = await openPage(browser, 1280, 900, false);
    try {
      await page.waitForFunction((n) => document.querySelectorAll('.et_pb_dropdown_initialized').length >= n, setup.panels, { timeout: 10000 }).catch(() => {});
      const nav = await page.evaluate(tagNav);
      const out = { errors, status, labels: nav.labels };
      for (const name of names) {
        const i = nav.labels.indexOf(name);
        await page.mouse.move(5, 880);
        await page.waitForTimeout(500);
        await page.locator(`[data-aied-mm="top-${i}"]`).hover();
        await page.waitForTimeout(1200);
        out[name] = await page.locator(`[data-aied-mm="panel-${i}"]`).evaluate((p) => ({
          text: p.innerText.replace(/\s+/g, ' '),
          links: [...p.querySelectorAll('a[href]')].map((a) => ({ text: a.textContent.trim(), href: a.getAttribute('href') })),
          headings: [...p.querySelectorAll('h1,h2,h3,h4')].map((e) => e.textContent.trim()),
          paras: [...p.querySelectorAll('p')].map((e) => e.textContent.trim()),
          imgs: [...p.querySelectorAll('img')].map((e) => ({ src: e.getAttribute('src') || '', alt: e.getAttribute('alt'), nw: e.naturalWidth })),
        }));
        out[name].icons = await page.locator(`[data-aied-mm="panel-${i}"]`).evaluate(iconCodes);
      }
      return out;
    } finally {
      await ctx.close();
    }
  };
  const listed = step('list');
  listed.flat.forEach((id) => flatLayouts.add(id));
  listed.content.forEach((id) => contentLayouts.add(id));
  listed.icons.forEach((id) => iconLayouts.add(id));
  const ids = listed.ids.filter((id) => only.length === 0 || only.includes(id));
  const ifont = step('icon_font');
  check('icons', '-', `every icon offered (${ifont.icons}) is a glyph, under the same name, of the Font Awesome file Divi ships (${ifont.font_glyphs} glyphs)`, ifont.missing.length === 0 && ifont.renamed.length === 0, JSON.stringify(ifont.missing.concat(ifont.renamed)));
  const browser = await chromium.launch();
  const summary = [];
  let fatal = null;
  try {
    for (const layout of ids) {
      const dry = step('dry', layout);
      check(layout, '-', 'dry run is valid', dry.valid === true, (dry.violations || []).join(','));
      check(layout, '-', 'dry run reports the menu and its panels', dry.panels === setup.panels, `${dry.panels} panels`);

      const ap = step('apply', layout);
      check(layout, '-', 'apply saves once and is undoable', ap.first_changed === true && typeof ap.batch === 'number' && ap.undoable === true);
      check(layout, '-', 'second apply reports changed:false', ap.second_changed === false);
      check(layout, '-', 'header carries one mega wrapper and the Menu module hides on desktop only', ap.applied === true && ap.wrappers === 1 && JSON.stringify(ap.menu_disabled_on) === JSON.stringify({ desktop: { value: 'on' }, tablet: { value: 'off' }, phone: { value: 'off' } }));
      const layoutDef = { posts: ({ 'latest-cards': 3, 'latest-links-cards': 2 })[layout] || 0 };
      ap.layout_posts = layoutDef.posts;
      ap.layout_cards = /^(showcase-tiles|showcase-intro-cards|showcase-tiles-dark|icons-|latest-|feature-)/.test(layout);
      ap.layout_pill = /^feature-/.test(layout);

      await desktop(browser, layout, setup, ap);
      await narrow(browser, layout, 900, 900, setup);
      await narrow(browser, layout, 400, 800, setup);

      const rm = step('remove');
      check(layout, '-', 'remove takes the wrapper out and restores the baseline blocks', rm.changed === true && rm.applied === false && rm.same_blocks === true && rm.same_bytes === true);
      check(layout, '-', 'second remove reports changed:false', rm.second_changed === false);
      const u1 = step('undo', String(rm.batch));
      check(layout, '-', 'undo of the remove restores the applied header byte for byte', u1.sha === ap.applied_sha && u1.result === 'restored', u1.result);
      const u2 = step('undo', String(ap.batch));
      check(layout, '-', 'undo of the apply restores the pre-apply header byte for byte', u2.sha === ap.pre_sha && u2.result === 'restored', u2.result);
      summary.push(layout);
      console.log(`done ${layout}`);
    }
    // One item only, and "Undo last change": the whole menu gets one layout, Blog gets another, the panels really differ in the browser,
    // and two undos walk back through the two changes, byte for byte.
    {
      const L = 'one item';
      const it = step('item_apply', 'columns-footer', 'showcase-tiles', 'Blog');
      check(L, '-', 'one item: Services keeps the whole-menu layout, Blog has its own', it.panels.Services === 'columns-footer' && it.panels.Blog === 'showcase-tiles', JSON.stringify(it.panels));
      check(L, '-', 'one item: the item change is saved, a second identical apply changes nothing', it.item_changed === true && it.item_again === false);
      check(L, '-', 'one item: still exactly one wrapper, and the panel label is on the dropdown', it.wrappers === 1 && it.item_label_ok === true);
      const { ctx, page, errors, status } = await openPage(browser, 1280, 900, false);
      try {
        await page.waitForFunction((n) => document.querySelectorAll('.et_pb_dropdown_initialized').length >= n, setup.panels, { timeout: 10000 }).catch(() => {});
        await pageSanity(page, L, 1280, status, errors);
        const nav = await page.evaluate(tagNav);
        const cardsIn = async (name) => {
          const i = nav.labels.indexOf(name);
          await page.mouse.move(5, 880);
          await page.waitForTimeout(500);
          await page.locator(`[data-aied-mm="top-${i}"]`).hover();
          await page.waitForTimeout(1200);
          const sel = `[data-aied-mm="panel-${i}"]`;
          const info = await page.locator(sel).evaluate((p) => ({ shown: getComputedStyle(p).display !== 'none', cards: [...p.querySelectorAll('div.et_pb_group')].filter((e) => getComputedStyle(e).boxShadow !== 'none' && parseFloat(getComputedStyle(e).borderTopLeftRadius) >= 14).length }));
          const cc = await page.evaluate(contrastInPanel, sel);
          const clip = await page.evaluate(clipInPanel, sel);
          return { ...info, contrast: cc, clip, i };
        };
        const a = await cardsIn('Services');
        const b = await cardsIn('Blog');
        check(L, 1280, 'one item: both panels open', a.shown && b.shown);
        check(L, 1280, `one item: Services keeps the plain columns (no cards: ${a.cards}) and Blog shows the tiles (${b.cards} cards)`, a.cards === 0 && b.cards >= 1);
        check(L, 1280, 'one item: both panels readable and nothing clipped', a.contrast.count > 0 && b.contrast.count > 0 && a.contrast.min >= 4.5 && b.contrast.min >= 4.5 && a.clip.length === 0 && b.clip.length === 0, `texts ${a.contrast.count}/${b.contrast.count}, min ${a.contrast.min}/${b.contrast.min} ${a.clip.concat(b.clip).join('; ')}`);
        check(L, 1280, 'one item: no console error from the page', errors.length === 0, errors.slice(0, 2).join(' | '));
      } finally {
        await ctx.close();
      }
      const u1 = step('undo', String(it.batch_item));
      check(L, '-', 'undoing the item change (as Recent Pro changes does) puts the header back to the whole-menu layout, byte for byte', u1.result === 'restored' && u1.sha === it.after_all_sha && u1.applied === true, String(u1.result));
      const u2 = step('undo', String(it.batch_all));
      check(L, '-', 'undoing the first apply: back to the header before any mega menu, byte for byte', u2.result === 'restored' && u2.sha === it.pre_sha && u2.applied === false, String(u2.result));
    }
    // The menu is edited after the mega menu was applied: the tab says so, the front end still shows the old panels, one update brings
    // the header up to date (every item keeps its layout), and undo puts the old header back, byte for byte.
    {
      const L = 'menu changed';
      const base = step('state');
      const appliedTiles = step('apply', 'showcase-tiles');
      const ed = step('drift_edit');
      const ds = step('drift_state');
      check(L, '-', 'after editing the menu the header is reported as not matching it', ds.drifted === true);
      check(L, '-', 'the new sub-item under the Design heading and the one under Blog are named, and the new top-level item', (ds.items.Services || {}).missing && ds.items.Services.missing.includes('Fresh leaf') && (ds.items.Blog || {}).missing && ds.items.Blog.missing.includes('Fresh blog link') && ds.bar_missing.includes('Contact'), JSON.stringify(ds.items) + ' ' + JSON.stringify(ds.bar_missing));
      check(L, '-', 'the Mega menu tab shows the notice and the Update button', ds.notice_shown === true && ds.button_shown === true);
      const probe = async () => {
        const { ctx, page, errors, status } = await openPage(browser, 1280, 900, false);
        try {
          await page.waitForFunction((n) => document.querySelectorAll('.et_pb_dropdown_initialized').length >= n, setup.panels, { timeout: 10000 }).catch(() => {});
          const nav = await page.evaluate(tagNav);
          const out = { status, errors };
          for (const [name, href] of [['Services', ed.fresh_leaf_url], ['Blog', ed.fresh_blog_url]]) {
            const i = nav.labels.indexOf(name);
            await page.mouse.move(5, 880);
            await page.waitForTimeout(500);
            await page.locator(`[data-aied-mm="top-${i}"]`).hover();
            await page.waitForTimeout(1200);
            out[name] = await page.locator(`[data-aied-mm="panel-${i}"]`).evaluate((p, h) => [...p.querySelectorAll('a[href]')].some((a) => a.getAttribute('href') === h), href);
          }
          out.contact = await page.evaluate((u) => [...document.querySelectorAll('[data-aied-mm="outer"] a')].some((a) => a.getAttribute('href') === u), ed.contact_url);
          out.labels = nav.labels;
          return out;
        } finally {
          await ctx.close();
        }
      };
      const adminBefore = await adminView(browser, { query: `&item=${setup.services_id}&etab=layout` });
      check(L, '-', 'admin: a WordPress notice with the Update button shows on the Dashboard, linking to the tab', adminBefore.dash.notice && adminBefore.dash.button && /tab=megamenu/.test(adminBefore.dash.link || '') && /out of date/.test(adminBefore.dash.text), adminBefore.dash.text.slice(0, 140));
      check(L, '-', 'admin: the tab itself shows its own notice, not a second WordPress one', adminBefore.tab.tabNotice === true && adminBefore.tab.wpNoticeOnTab === false);
      check(L, '-', 'admin: the item list has one entry per menu item with its layout and says which is out of date', adminBefore.tab.items.length === setup.panels && adminBefore.tab.items.every((x) => x.layout === 'Link tiles') && adminBefore.tab.items.find((x) => x.name === 'Services').pills.includes('Out of date'), JSON.stringify(adminBefore.tab.items));
      check(L, '-', 'admin: on the Layout tab the layout in use is framed on exactly one card, badge "Used here"', adminBefore.tab.current.length === 1 && adminBefore.tab.current[0].name === 'Link tiles' && adminBefore.tab.current[0].badge === 'Used here', JSON.stringify(adminBefore.tab.current));
      check(L, '-', 'admin: no dropdown on the layout cards', adminBefore.tab.cardSelects === 0 && adminBefore.tab.cards === 14, `${adminBefore.tab.cardSelects} selects, ${adminBefore.tab.cards} cards`);
      check(L, '-', 'admin: nothing sticks out of the box that holds it', adminBefore.tab.overflow === 0, String(adminBefore.tab.overflow));
      check(L, '-', 'admin: the help is folded away and the visible text stays short', adminBefore.tab.helpClosed === true && adminBefore.tab.textLength < 3600, String(adminBefore.tab.textLength));
      const before = await probe();
      check(L, 1280, 'before the update the front end still has the old panels (no new link, no new top-level item)', before.Services === false && before.Blog === false && before.contact === false, JSON.stringify({ s: before.Services, b: before.Blog, c: before.contact }));
      const rf = step('drift_refresh');
      check(L, '-', 'update: a dry run changes nothing and is valid', rf.dry_untouched === true && rf.dry_valid === true);
      check(L, '-', 'update: saved once, an immediate second update changes nothing, one wrapper', rf.changed === true && rf.again === false && rf.wrappers === 1);
      check(L, '-', 'update: afterwards nothing is out of date, and every panel kept the layout it had', rf.drift_after === false && Object.values(rf.panels).every((v) => v === 'showcase-tiles'), JSON.stringify(rf.panels));
      const adminAfter = await adminView(browser);
      check(L, '-', 'admin: after the update the Dashboard notice is gone and the tab shows no notice', adminAfter.dash.notice === false && adminAfter.tab.tabNotice === false);
      check(L, '-', 'admin: no script error on the admin screens', adminBefore.errors.concat(adminAfter.errors).length === 0, adminBefore.errors.concat(adminAfter.errors).slice(0, 2).join(' | '));
      const afterRefresh = await probe();
      check(L, 1280, 'after the update the new links are in the panels and the new top-level item is in the bar', afterRefresh.Services === true && afterRefresh.Blog === true && afterRefresh.contact === true, JSON.stringify({ s: afterRefresh.Services, b: afterRefresh.Blog, c: afterRefresh.contact }));
      check(L, 1280, 'no console error from the page before or after', before.errors.length === 0 && afterRefresh.errors.length === 0, before.errors.concat(afterRefresh.errors).slice(0, 2).join(' | '));
      const u1 = step('undo', String(rf.batch));
      check(L, '-', 'undoing the update (Recent Pro changes) puts the header back as it was before it, byte for byte', u1.result === 'restored' && u1.sha === rf.pre_sha && u1.applied === true, String(u1.result));
      const u2 = step('undo', String(appliedTiles.batch));
      check(L, '-', 'undoing the apply: back to the header before any mega menu, byte for byte', u2.result === 'restored' && u2.sha === base.sha && u2.applied === false, String(u2.result));
      const cl = step('drift_cleanup');
      check(L, '-', 'the three added menu items are gone', cl.items_left === cl.items_expected, `${cl.items_left} of ${cl.items_expected}`);
    }
    // The owner's own content through the real form: the fields hold what was saved, the Media Library window opens, a typed headline shows
    // in the small preview at once, "Save changes" puts it on the front end, "Reset to the defaults" takes it away.
    {
      const L = 'own content';
      step('apply', 'feature-right');
      const s = await adminSession(browser);
      try {
        await s.page.goto(`${TAB}&item=${setup.services_id}&etab=content`, { waitUntil: 'load' });
        let t = await readTab(s.page);
        check(L, '-', 'content tab: the fields hold what was saved and the picture is shown', t.fields.title === setup.own.title && t.fields.text === setup.own.text && t.fields.button === setup.own.button && t.fields.url === setup.own.url && String(t.fields.image) === String(setup.own.image) && !!t.pickerSrc, JSON.stringify(t.fields));
        check(L, '-', 'the item list opens Services and marks it "Customised"', t.items[0].name === 'Services' && t.items[0].selected && t.items[0].pills.includes('Customised') && t.panelTitle === 'Services panel', JSON.stringify(t.items[0]));
        await s.page.click('#aied-mm-pick');
        const modal = await s.page.waitForSelector('.media-modal', { state: 'visible', timeout: 20000 }).then(() => true).catch(() => false);
        check(L, '-', 'the Media Library window opens from the picture button', modal);
        if (modal) await s.page.keyboard.press('Escape');
        await s.page.fill('#aied-mm-title', 'Live preview words');
        check(L, '-', 'the small preview follows what is typed', (await s.page.textContent('#aied-mm-pv-title')) === 'Live preview words');
        await s.page.fill('#aied-mm-title', 'Edited by the form');
        await s.page.fill('#aied-mm-text', 'Text from the form.');
        await s.page.fill('#aied-mm-button', 'Go now');
        await pressAndWait(s.page, 'button:has-text("Save changes")', 'megamenu_content_saved');
        t = await readTab(s.page);
        check(L, '-', 'saving comes back to the same item and tab with the notice', t.noticeText.includes('Saved. The panel in your header is updated.') && new RegExp(`item=${setup.services_id}`).test(s.page.url()) && /etab=content/.test(s.page.url()), t.noticeText);
        check(L, '-', 'after saving nothing is out of date', t.tabNotice === false && t.items[0].pills.every((x) => x !== 'Out of date'));
        const f = await frontPanels(browser, ['Services']);
        check(L, 1280, 'the front end shows the saved headline, text and button label', f.Services.headings.includes('Edited by the form') && f.Services.paras.includes('Text from the form.') && f.Services.links.some((l) => l.text === 'Go now' && l.href === setup.own.url), JSON.stringify(f.Services.headings));
        check(L, 1280, 'the picture is still there and loads', f.Services.imgs.some((m) => m.src.endsWith(setup.own.imageUrl.split('/').pop()) && m.nw > 0 && m.alt === setup.own.alt), JSON.stringify(f.Services.imgs.slice(0, 2)));
        await pressAndWait(s.page, 'button:has-text("Reset to the defaults")', 'megamenu_content_reset');
        t = await readTab(s.page);
        check(L, '-', 'reset: the notice and empty fields (the icons chosen for the icon layouts stay)', t.noticeText.includes('Back to the defaults') && t.fields.title === '' && t.fields.text === '' && t.fields.image === '0', t.noticeText);
        const f2 = await frontPanels(browser, ['Services']);
        check(L, 1280, 'after reset the card shows the menu item\'s own title and no picture', f2.Services.headings.includes('Services') && !f2.Services.headings.includes('Edited by the form') && !f2.Services.imgs.some((m) => /uploads/.test(m.src)), JSON.stringify(f2.Services.headings));
        check(L, '-', 'no script error on the admin screens and the front end', s.errors.length === 0 && f.errors.length === 0 && f2.errors.length === 0, s.errors.concat(f.errors, f2.errors).slice(0, 2).join(' | '));
      } finally {
        await s.ctx.close();
      }
      const off = step('remove');
      check(L, '-', 'removed again for the next scenario', off.applied === false);
    }
    // The icons of the icon layouts through the real form: the tiles are drawn with Divi's own Font Awesome file, a chosen icon (one for the
    // panel, one for a single card) reaches the front end, and the reset goes back to the arrow.
    {
      const L = 'icon picker';
      step('apply', 'icons-cards');
      const s = await adminSession(browser);
      try {
        await s.page.goto(`${TAB}&item=${setup.services_id}&etab=content`, { waitUntil: 'load' });
        const tiles = await s.page.evaluate(async () => {
          await document.fonts.load("900 16px AiedFA").catch(() => {});
          const g = [...document.querySelectorAll('.aied-mm-cardicon .aied-mm-glyph')];
          return {
            grid: document.querySelectorAll('.aied-mm-icongrid, input[name="icon"]').length,
            previews: g.length,
            font: document.fonts.check('900 16px AiedFA'),
            drawn: g.every((e) => getComputedStyle(e).fontFamily.includes('AiedFA') && e.getBoundingClientRect().width > 0 && (e.textContent || '').trim().length === 1),
            cards: [...document.querySelectorAll('.aied-mm-cardicon select')].map((x) => ({ name: x.getAttribute('name'), value: x.value, options: x.options.length, first: x.options[0].textContent.trim() })),
          };
        });
        check(L, '-', 'there is no icon for the whole panel any more: only a choice per card, each offering the arrow as its default and every other icon', tiles.grid === 0 && tiles.cards.length === 3 && tiles.cards.every((c) => c.options === ifont.icons && c.first === 'Arrow (default)'), JSON.stringify({ grid: tiles.grid, cards: tiles.cards }));
        check(L, '-', 'each card shows its own icon beside the choice, drawn with the real font: rocket for "Design" and "Grow", house for "Build"', tiles.font === true && tiles.drawn === true && tiles.previews === 3 && tiles.cards.find((c) => c.name === `sub[${setup.design_id}]`).value === 'rocket' && tiles.cards.find((c) => c.name === `sub[${setup.build_id}]`).value === 'home' && tiles.cards.find((c) => c.name === `sub[${setup.grow_id}]`).value === 'rocket', JSON.stringify({ font: tiles.font, drawn: tiles.drawn, cards: tiles.cards }));
        await s.page.selectOption(`select[name="sub[${setup.design_id}]"]`, 'star');
        await s.page.selectOption(`select[name="sub[${setup.grow_id}]"]`, 'heart');
        const preview = await s.page.evaluate((n) => { const sel = document.querySelector(`select[name="${n}"]`); const g = document.querySelector(`.aied-mm-glyph[data-for="${sel.id}"]`); return g ? g.textContent.codePointAt(0).toString(16) : null; }, `sub[${setup.design_id}]`);
        check(L, '-', 'choosing an icon for a card shows it next to the choice at once', preview === 'f005', String(preview));
        await pressAndWait(s.page, 'button:has-text("Save icons")', 'megamenu_icons_saved');
        let t = await readTab(s.page);
        check(L, '-', 'saving comes back to the item with the notice and marks it "Customised"', t.noticeText.includes('Saved. The icons in your header are updated.') && t.items[0].pills.includes('Customised'), t.noticeText);
        const f = await frontPanels(browser, ['Services']);
        check(L, 1280, 'the front end shows a star on the first card, the house on "Build" and a heart on the last card', JSON.stringify(f.Services.icons.map((x) => x.code)) === JSON.stringify(['f005', 'f015', 'f004', 'f061']), JSON.stringify(f.Services.icons.map((x) => x.code)));
        check(L, 1280, 'every icon is drawn with Font Awesome at weight 900', f.Services.icons.every((x) => x.fa && x.weight === '900' && x.w > 0));
        await pressAndWait(s.page, 'button:has-text("Reset to the arrow")', 'megamenu_icons_saved');
        t = await readTab(s.page);
        const f2 = await frontPanels(browser, ['Services']);
        check(L, 1280, 'after the reset every card has the arrow again', f2.Services.icons.every((x) => x.code === 'f061') && f2.Services.icons.length === 4, JSON.stringify(f2.Services.icons.map((x) => x.code)));
        check(L, '-', 'after the reset the item is not marked "Customised" any more (its texts were reset earlier)', t.items[0].pills.every((x) => x !== 'Customised'), JSON.stringify(t.items[0].pills));
        check(L, '-', 'no script error on the admin screens and the front end', s.errors.length === 0 && f.errors.length === 0 && f2.errors.length === 0, s.errors.concat(f.errors).slice(0, 2).join(' | '));
      } finally {
        await s.ctx.close();
      }
      const off = step('remove');
      check(L, '-', 'removed again', off.applied === false);
    }
    // A panel edited in the Divi builder: seen, protected on Update (the tab asks), kept or replaced as the owner answers, through the real buttons.
    {
      const L = 'hand edit';
      step('apply', 'columns-footer');
      const he = step('hand_edit', 'Services');
      check(L, '-', 'a panel changed the way the builder does it is seen as edited, the other one is not', he.changed === true && he.edited.Services === true && he.edited.Blog === false, JSON.stringify(he.edited));
      const a1 = await adminView(browser, { query: `&item=${setup.services_id}&etab=builder` });
      const row = (name) => a1.tab.items.find((x) => x.name === name);
      check(L, '-', 'admin: the item list says "Edited in Divi" for Services only', row('Services').pills.includes('Edited in Divi') && !row('Blog').pills.includes('Edited in Divi'), JSON.stringify(a1.tab.items));
      check(L, '-', 'admin: the Builder tab says Edited in Divi and offers "Replace my version"', a1.tab.builderPill === 'Edited in Divi' && a1.tab.replaceButton === true, a1.tab.builderPill);
      const href = a1.tab.openHref || '';
      check(L, '-', 'admin: "Open in Divi" opens the header in the Divi Theme Builder screen (the layout\'s own builder address is the inside of a pop-up and a blank page on its own)', /page=et_theme_builder/.test(href) && new RegExp(`et_tb_layout_id=${setup.header_id}\\b`).test(href) && /et_tb_layout_type=header/.test(href) && !/app_window|et_fb=1/.test(href), href);
      {
        const sb = await adminSession(browser);
        try {
          await sb.page.goto(href, { waitUntil: 'load' });
          let drawn = false;
          let detail = 'no builder frame';
          for (let k = 0; k < 40 && !drawn; k++) {
            await sb.page.waitForTimeout(2000);
            // The toolbar (Exit, Save) is drawn by the Theme Builder screen itself; the header is drawn inside the pop-up's frame.
            const top = await sb.page.evaluate(() => (document.body ? document.body.innerText : '')).catch(() => '');
            const frame = sb.page.frames().find((f) => /et_fb=1/.test(f.url()) && /app_window=1/.test(f.url()));
            const inner = frame ? await frame.evaluate(() => (document.body ? document.body.innerText : '')).catch(() => '') : '';
            detail = frame ? `toolbar: ${/\bExit\b/.test(top) && /\bSave\b/.test(top)}, header text in the pop-up: ${inner.length} characters` : 'no builder frame';
            drawn = /\bExit\b/.test(top) && /\bSave\b/.test(top) && inner.includes('Services');
          }
          check(L, '-', 'that address really opens the builder: the toolbar (Exit, Save) is drawn and the pop-up shows the header\'s menu', drawn, detail);
        } finally {
          await sb.ctx.close();
        }
      }
      const f0 = await frontPanels(browser, ['Services']);
      check(L, 1280, 'the front end shows the hand edit', f0.Services.text.includes('(edited in Divi)'));
      const ed = step('drift_edit');
      const s = await adminSession(browser);
      try {
        const pre = step('state').sha;
        await s.page.goto(TAB, { waitUntil: 'load' });
        await pressAndWait(s.page, 'button:has-text("Update the mega menu")', 'megamenu_edited');
        let t = await readTab(s.page);
        check(L, '-', 'admin: Update stops and asks, naming the edited panel', t.pending === true && /the panel of "Services"/.test(t.pendingText), t.pendingText);
        check(L, '-', 'admin: nothing was changed while it asks', step('state').sha === pre);
        await pressAndWait(s.page, 'button:has-text("Keep my changes")', 'megamenu_updated');
        t = await readTab(s.page);
        check(L, '-', 'admin: after "Keep my changes" the update went through and the question is gone', t.noticeText.includes('The mega menu now matches your menu') && t.pending === false, t.noticeText);
        const f1 = await frontPanels(browser, ['Services', 'Blog']);
        check(L, 1280, 'the edited panel is untouched (no new link) and the other panel has its new link', f1.Services.text.includes('(edited in Divi)') && !f1.Services.links.some((l) => l.href === ed.fresh_leaf_url) && f1.Blog.links.some((l) => l.href === ed.fresh_blog_url), `Services new link: ${f1.Services.links.some((l) => l.href === ed.fresh_leaf_url)}, Blog new link: ${f1.Blog.links.some((l) => l.href === ed.fresh_blog_url)}`);
        check(L, '-', 'admin: Services still says Edited in Divi, and nothing is reported out of date', t.items.find((x) => x.name === 'Services').pills.includes('Edited in Divi') && t.tabNotice === false, JSON.stringify(t.items));
        await s.page.goto(`${TAB}&item=${setup.services_id}&etab=builder`, { waitUntil: 'load' });
        await pressAndWait(s.page, 'button:has-text("Replace my version")', 'megamenu_applied_item');
        t = await readTab(s.page);
        check(L, '-', 'admin: "Replace my version" rebuilds the panel and says so', /is on "Services" now/.test(t.noticeText) && t.items.find((x) => x.name === 'Services').pills.every((x) => x !== 'Edited in Divi'), t.noticeText);
        const f2 = await frontPanels(browser, ['Services']);
        check(L, 1280, 'the panel is the plugin\'s again and now has the new link', !f2.Services.text.includes('(edited in Divi)') && f2.Services.links.some((l) => l.href === ed.fresh_leaf_url));
        check(L, '-', 'no script error on the admin screens and the front end', s.errors.length === 0 && f0.errors.length === 0 && f1.errors.length === 0 && f2.errors.length === 0, s.errors.concat(f1.errors).slice(0, 2).join(' | '));
      } finally {
        await s.ctx.close();
      }
      // An item that says "keep my version" keeps it without being asked.
      step('hand_edit', 'Services');
      step('set_edits', 'Services', 'keep');
      const kept = step('edited_refresh', '-');
      check(L, '-', 'an item set to "Keep my version" is kept without asking', kept.asked.length === 0 && kept.has_edit === true, JSON.stringify(kept.asked));
      step('set_edits', 'Services', 'ask');
      const asked = step('edited_refresh', '-');
      check(L, '-', 'an item set to "Ask me" asks and changes nothing', asked.asked.length === 1 && asked.asked[0] === 'Services' && asked.sha === asked.pre_sha, JSON.stringify(asked.asked));
      const cl = step('drift_cleanup');
      check(L, '-', 'the added menu items are gone', cl.items_left === cl.items_expected);
      const off = step('remove');
      check(L, '-', 'removed again', off.applied === false);
    }
    // Which menu the header shows, chosen on the tab with a real dropdown: the mega menu is built again from the chosen menu and back again.
    {
      const L = 'menu picker';
      step('apply', 'columns-footer');
      const second = step('second_menu');
      const s = await adminSession(browser);
      try {
        await s.page.goto(TAB, { waitUntil: 'load' });
        let t = await readTab(s.page);
        check(L, '-', 'the tab lists the menus of the site and marks the one shown', !!t.menuSelect && t.menuSelect.length >= 2 && t.menuSelect.find((o) => o.selected).value === String(setup.menu_id) && t.menuSelect.some((o) => o.value === String(second.menu_id)), JSON.stringify(t.menuSelect));
        await s.page.selectOption('#aied-mm-menu', String(second.menu_id));
        await pressAndWait(s.page, 'button:has-text("Use this menu")', 'megamenu_menu_changed');
        t = await readTab(s.page);
        check(L, '-', 'saying so: the header now shows the chosen menu and the mega menu is built from it', t.noticeText.includes('The header now shows the menu "aied megamenu second"') && t.items.length === 1 && t.items[0].name === 'Docs', t.noticeText);
        const m1 = step('menu_state');
        check(L, '-', 'the header\'s Menu module points at the chosen menu and only its item with sub-items has a panel', m1.module_menu_id === second.menu_id && m1.status_menu_id === second.menu_id && JSON.stringify(m1.panel_ids) === JSON.stringify([second.docs_id]) && m1.layouts.length === 1 && m1.layouts[0] === 'columns-footer' && m1.drift === false, JSON.stringify(m1));
        const f = await frontPanels(browser, ['Docs']);
        check(L, 1280, 'the front end shows the chosen menu in the bar and the panel of "Docs"', f.labels.includes('Docs') && f.labels.includes('Contact') && !f.labels.includes('Services') && f.Docs.links.some((l) => l.href === second.guide_url), JSON.stringify(f.labels));
        await s.page.selectOption('#aied-mm-menu', String(setup.menu_id));
        await pressAndWait(s.page, 'button:has-text("Use this menu")', 'megamenu_menu_changed');
        const m2 = step('menu_state');
        check(L, '-', 'choosing the first menu again brings its panels back', m2.module_menu_id === setup.menu_id && m2.panel_ids.length === setup.panels && m2.drift === false, JSON.stringify(m2));
        check(L, '-', 'no script error on the admin screens', s.errors.length === 0, s.errors.slice(0, 2).join(' | '));
      } finally {
        await s.ctx.close();
      }
      const off = step('remove');
      check(L, '-', 'removed again', off.applied === false);
    }
    // The grouped list on a menu of its own: real categories with their real post counts, two loose links as cards.
    {
      const L = 'directory';
      const dm = step('directory_menu');
      const use = step('directory_use');
      check(L, '-', 'the header shows the directory menu and its one panel is the grouped list', use.status_menu_id === dm.menu_id && JSON.stringify(use.panel_ids) === JSON.stringify([dm.lib_id]) && use.layouts.length === 1 && use.layouts[0] === 'directory-counts', JSON.stringify(use));
      const names = Object.keys(dm.counts);
      check(L, '-', 'a category\'s count the menu reads is the number of its published posts (2 and 1)', names.every((n) => dm.counts[n].term === dm.counts[n].real) && JSON.stringify(names.map((n) => dm.counts[n].real)) === JSON.stringify([2, 1]), JSON.stringify(dm.counts));
      const { ctx, page, errors, status } = await openPage(browser, 1280, 900, false);
      try {
        await page.waitForFunction(() => document.querySelectorAll('.et_pb_dropdown_initialized').length >= 1, null, { timeout: 10000 }).catch(() => {});
        const nav = await page.evaluate(tagNav);
        const i = nav.labels.indexOf('Library');
        check(L, 1280, 'the bar shows the directory menu', i >= 0 && nav.labels.includes('Contact'), JSON.stringify(nav.labels));
        const panelSel = `[data-aied-mm="panel-${i}"]`;
        await page.mouse.move(5, 880);
        await page.waitForTimeout(400);
        await page.locator(`[data-aied-mm="top-${i}"]`).hover();
        await page.waitForTimeout(1300);
        const panel = page.locator(panelSel);
        check(L, 1280, 'the panel opens on hover', await panel.evaluate(SHOWN));
        const m = await panel.evaluate((p, o) => {
          const rowOf = (title) => { const a = [...p.querySelectorAll('a[href]')].find((x) => x.textContent.trim() === title); let e = a; while (e && e !== p) { const cs = getComputedStyle(e); if (e.classList.contains('et_pb_group') && cs.justifyContent === 'space-between') return { a, row: e }; e = e.parentElement; } return { a, row: null }; };
          const rows = {};
          for (const t of o.names.concat(o.plain)) {
            const { a, row } = rowOf(t);
            const cs = row ? getComputedStyle(row) : null;
            rows[t] = { link: !!a, row: !!row, bg: cs ? cs.backgroundColor : '', radius: cs ? cs.borderTopLeftRadius : '', text: row ? row.textContent.replace(/\s+/g, ' ').trim() : '', pill: row ? [...row.querySelectorAll('p')].map((x) => x.textContent.trim()).filter((x) => /^\d+$/.test(x)) : [] };
          }
          const heads = ['By topic', 'More'].map((t) => { const e = [...p.querySelectorAll('p')].find((x) => x.textContent.trim() === t); const cs = e && getComputedStyle(e); return { t, up: e ? cs.textTransform : 'missing', ls: e ? cs.letterSpacing : '', link: [...p.querySelectorAll('a')].some((a) => a.textContent.trim() === t) }; });
          const cards = Object.keys(o.cards).map((t) => { const a = [...p.querySelectorAll('a[href]')].find((x) => x.textContent.trim() === t); let e = a; while (e && e !== p && !(e.classList.contains('et_pb_group') && getComputedStyle(e).backgroundColor === 'rgb(243, 245, 249)')) e = e.parentElement; const r = e && e !== p ? e.getBoundingClientRect() : null; return { t, href: a ? a.getAttribute('href') : null, card: !!r, radius: r ? getComputedStyle(e).borderTopLeftRadius : '', top: r ? Math.round(r.top) : 0, left: r ? Math.round(r.left) : 0, width: r ? Math.round(r.width) : 0, text: e ? e.textContent.replace(/\s+/g, ' ').trim() : '', arrows: e ? [...e.querySelectorAll('.et-pb-icon')].map((x) => (x.textContent || '').trim().codePointAt(0)) : [] }; });
          return { rows, heads, cards };
        }, { names, plain: dm.rows_plain, cards: dm.cards });
        for (const n of names) {
          const r = m.rows[n];
          check(L, 1280, `row "${n}": a link inside a row, with the count ${dm.counts[n].real} at the end`, r.link && r.row && r.pill.length === 1 && r.pill[0] === String(dm.counts[n].real) && r.text.endsWith(String(dm.counts[n].real)), JSON.stringify(r));
        }
        for (const n of dm.rows_plain) check(L, 1280, `row "${n}" has no count`, m.rows[n].link && m.rows[n].row && m.rows[n].pill.length === 0, JSON.stringify(m.rows[n]));
        check(L, 1280, 'the rows are plain at rest (no fill) with rounded corners', Object.values(m.rows).every((r) => r.bg === 'rgba(0, 0, 0, 0)' && r.radius === '10px'), JSON.stringify(Object.values(m.rows).map((r) => [r.bg, r.radius])));
        check(L, 1280, 'the group names are capitalised labels, not links', m.heads.every((h) => h.up === 'uppercase' && h.ls === '1px' && !h.link), JSON.stringify(m.heads));
        const cardNames = Object.keys(dm.cards);
        check(L, 1280, 'the two loose links are cards with their description and an arrow, side by side', m.cards.every((c) => c.card && c.radius === '14px' && c.href === dm.cards[c.t] && /\S{3,}/.test(c.text) && c.arrows.length === 1 && c.arrows[0] === 0xf061) && m.cards[0].top === m.cards[1].top && m.cards[0].left !== m.cards[1].left && m.cards[0].width === m.cards[1].width, JSON.stringify(m.cards));
        check(L, 1280, 'the cards say what they are (the descriptions)', m.cards[0].text.includes('The full catalogue in one place.') && m.cards[1].text.includes('A real person answers within a day.'), JSON.stringify(m.cards.map((c) => c.text)));
        // A real hover on a row: the tint, and the link takes the accent.
        const first = names[0];
        const link = page.locator(`${panelSel} a[href]`, { hasText: first }).first();
        await link.hover();
        await page.waitForTimeout(700);
        const hv = await panel.evaluate((p, t) => {
          const a = [...p.querySelectorAll('a[href]')].find((x) => x.textContent.trim() === t);
          let e = a; while (e && !(e.classList.contains('et_pb_group') && getComputedStyle(e).justifyContent === 'space-between')) e = e.parentElement;
          const inner = a.querySelector('.et_pb_link_inner') || a;
          return { bg: getComputedStyle(e).backgroundColor, color: getComputedStyle(inner).color };
        }, first);
        check(L, 1280, 'with the pointer on a row it takes the soft tint and the link turns to the accent colour', hv.bg === 'rgb(238, 242, 255)' && hv.color === 'rgb(29, 78, 216)', JSON.stringify(hv));
        // A real hover on a card: it rises and takes the accent frame.
        const cardLink = page.locator(`${panelSel} a[href]`, { hasText: cardNames[0] }).first();
        await cardLink.hover();
        await page.waitForTimeout(700);
        const ch = await panel.evaluate((p, t) => {
          const a = [...p.querySelectorAll('a[href]')].find((x) => x.textContent.trim() === t);
          let e = a; while (e && !(e.classList.contains('et_pb_group') && getComputedStyle(e).backgroundColor === 'rgb(243, 245, 249)')) e = e.parentElement;
          const cs = getComputedStyle(e);
          return { transform: cs.transform, border: cs.borderTopColor };
        }, cardNames[0]);
        check(L, 1280, 'with the pointer on a card it rises 4px and takes the accent frame', /matrix\(1, 0, 0, 1, 0, -4\)/.test(ch.transform) && ch.border === 'rgb(29, 78, 216)', JSON.stringify(ch));
        await link.hover();
        await page.waitForTimeout(300);
        const cc = await page.evaluate(contrastInPanel, panelSel);
        check(L, 1280, `text contrast >= 4.5:1 (${cc.count} texts)`, cc.count > 0 && cc.min >= 4.5, `min ${cc.min} at "${cc.worst}"`);
        const clip = await page.evaluate(clipInPanel, panelSel);
        check(L, 1280, 'nothing clipped or outside the panel', clip.length === 0, clip.join('; '));
        const box = await panel.boundingBox();
        check(L, 1280, 'the panel is inside the viewport and as wide as its 760px content plus padding and frame (806px)', box.x >= -0.5 && box.x + box.width <= 1280.5 && Math.abs(box.width - 806) <= 1, JSON.stringify(box));
        fs.mkdirSync(OUT, { recursive: true });
        await page.screenshot({ path: path.join(OUT, 'directory-counts-menu.png') });
        await pageSanity(page, L, 1280, status, errors);
        check(L, 1280, 'no console error', errors.length === 0, errors.slice(0, 2).join(' | '));
      } finally {
        await ctx.close();
      }
      const back = step('directory_back');
      check(L, '-', 'the header points at the run\'s own menu again with no mega menu', back.module_menu_id === setup.menu_id && back.applied === false, JSON.stringify(back));
    }
    // Refusals (no global header, a copy without a Menu module, an editor without the Theme Builder permission).
    const neg = step('negative');
    check('negative', '-', 'no global header: refused with the message, nothing read', neg.no_header === 'This site has no global header. Create one in Divi > Theme Builder (a header on the Default Website Template), then come back.', neg.no_header);
    check('negative', '-', 'the global header is the live header', neg.default_header_id === setup.header_id, String(neg.default_header_id));
    check('negative', '-', 'a header without a Menu module is refused (dry run and apply)', neg.no_menu_is_no_menu_error === true && typeof neg.no_menu_real === 'string' && neg.no_menu_real.includes('no Menu module'), neg.no_menu);
    check('negative', '-', 'the header copy without a Menu module is untouched', neg.copy_untouched === true);
    check('negative', '-', 'a user without the Theme Builder permission is refused (dry run, apply, remove)', [neg.editor_dry, neg.editor_real, neg.editor_remove].every((m) => typeof m === 'string' && /Theme Builder|permission/i.test(m)), (neg.editor_real || '').slice(0, 120));
    check('negative', '-', 'the global header is unchanged after the refusals', neg.header_unchanged === true);
    check('negative', '-', 'the negative fixtures are gone', neg.fixtures_left === 0, String(neg.fixtures_left));
    const st = step('state');
    check('negative', '-', 'the header is back at the baseline after all layouts', st.sha === setup.baseline_sha || st.applied === false, st.sha);
  } catch (e) {
    fatal = e;
    console.log('FAIL  fatal: ' + e.message);
    failures.push('fatal: ' + e.message);
  } finally {
    await browser.close();
    const teardown = step('teardown');
    check('teardown', '-', 'the header is restored to its original bytes', teardown.same_bytes === true, teardown.fallback ? 'via the database' : '');
    check('teardown', '-', 'no menu, menu item, fixture or user is left', teardown.menu_left === 0 && teardown.extra_left === 0 && teardown.users_left === 0);
    check('teardown', '-', 'the undo batches that existed before the run are back, identical (the table trims to 20 and a run records more)', teardown.batches_back === true);
    check('teardown', '-', 'none of the undo batches this run recorded is left', teardown.batches_left === 0, String(teardown.batches_left));
    check('teardown', '-', 'the throwaway picture is deleted', teardown.picture_left === 0);
    check('teardown', '-', 'the throwaway categories and posts of the directory scenario are deleted', teardown.directory_left === 0, String(teardown.directory_left));
    check('teardown', '-', 'the revisions WordPress made of the header during the run are removed again (they pile up and fill PHP memory)', teardown.revisions_left === 0, String(teardown.revisions_left));
  }
  console.log('');
  console.log(`layouts run: ${summary.length} of ${ids.length}`);
  for (const [w, [ok, bad]] of Object.entries(tally)) console.log(`width ${w}: ${ok} PASS, ${bad} FAIL`);
  if (failures.length) {
    console.log('\nFAILURES:');
    failures.forEach((f) => console.log(f));
  }
  process.exit(fatal || failures.length ? 1 : 0);
})().catch((e) => {
  console.error('ERR', e);
  process.exit(1);
});
