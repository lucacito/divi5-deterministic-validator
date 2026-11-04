'use strict';
/*
 * Real-site gate for the Pro mega menu (make verify-pro-megamenu). Dev site only.
 *
 * For each of the 13 layouts, through the real MegaMenuService (scripts/verify-pro-megamenu.php does the PHP side, one step per call):
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
const { devSiteOnly, makeWp, watchConsole } = require(path.join(ROOT, 'scripts', 'lib', 'throwaway-pages.cjs'));

devSiteOnly(SITE);
const wp = makeWp(ROOT);
/** Layouts that list every sub-link in one flat row (filled from the PHP step `list`). */
const flatLayouts = new Set();
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
        check(layout, W, `"${title}" panel images carry a pack or post-thumbnail src and no alt text`, imgs.every((im) => (/card-blue-1\.svg$/.test(im.src || '') || /\/uploads\//.test(im.src || '')) && (im.alt === '' || im.alt === null)), JSON.stringify(imgs.slice(0, 1)));
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
        check(layout, W, `"${title}" button text and link (divi/button)`, btn.text === 'View all' && btn.href === setup.top[i].url, `${btn.text} -> ${btn.href}`);
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

(async () => {
  const setup = step('setup');
  console.log(`setup: menu ${setup.menu_id}, ${setup.items} items, header ${setup.header_id}, baseline in place`);
  const listed = step('list');
  listed.flat.forEach((id) => flatLayouts.add(id));
  const ids = listed.ids.filter((id) => only.length === 0 || only.includes(id));
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
      const u1 = step('undo_last');
      check(L, '-', 'undo last change: offers the item change and puts the header back to the whole-menu layout, byte for byte', u1.result === 'undone' && u1.sha === it.after_all_sha && u1.applied === true, `${u1.result} / ${u1.offered}`);
      const u2 = step('undo_last');
      check(L, '-', 'undo last change again: back to the header before any mega menu, byte for byte', u2.result === 'undone' && u2.sha === it.pre_sha && u2.applied === false, `${u2.result} / ${u2.offered}`);
      const u3 = step('undo_last');
      check(L, '-', 'undo last change with nothing left to undo says so and changes nothing', u3.result === 'none' && u3.sha === it.pre_sha, u3.result);
    }
    // The menu is edited after the mega menu was applied: the tab says so, the front end still shows the old panels, one update brings
    // the header up to date (every item keeps its layout), and undo puts the old header back, byte for byte.
    {
      const L = 'menu changed';
      const base = step('state');
      step('apply', 'showcase-tiles');
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
      const before = await probe();
      check(L, 1280, 'before the update the front end still has the old panels (no new link, no new top-level item)', before.Services === false && before.Blog === false && before.contact === false, JSON.stringify({ s: before.Services, b: before.Blog, c: before.contact }));
      const rf = step('drift_refresh');
      check(L, '-', 'update: a dry run changes nothing and is valid', rf.dry_untouched === true && rf.dry_valid === true);
      check(L, '-', 'update: saved once, an immediate second update changes nothing, one wrapper', rf.changed === true && rf.again === false && rf.wrappers === 1);
      check(L, '-', 'update: afterwards nothing is out of date, and every panel kept the layout it had', rf.drift_after === false && Object.values(rf.panels).every((v) => v === 'showcase-tiles'), JSON.stringify(rf.panels));
      const afterRefresh = await probe();
      check(L, 1280, 'after the update the new links are in the panels and the new top-level item is in the bar', afterRefresh.Services === true && afterRefresh.Blog === true && afterRefresh.contact === true, JSON.stringify({ s: afterRefresh.Services, b: afterRefresh.Blog, c: afterRefresh.contact }));
      check(L, 1280, 'no console error from the page before or after', before.errors.length === 0 && afterRefresh.errors.length === 0, before.errors.concat(afterRefresh.errors).slice(0, 2).join(' | '));
      const u1 = step('undo_last');
      check(L, '-', 'undo last change puts the header back as it was before the update, byte for byte', u1.result === 'undone' && u1.sha === rf.pre_sha && u1.applied === true, `${u1.result} / ${u1.offered}`);
      const u2 = step('undo_last');
      check(L, '-', 'undo again: back to the header before any mega menu, byte for byte', u2.result === 'undone' && u2.sha === base.sha && u2.applied === false, u2.result);
      const cl = step('drift_cleanup');
      check(L, '-', 'the three added menu items are gone', cl.items_left === cl.items_expected, `${cl.items_left} of ${cl.items_expected}`);
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
