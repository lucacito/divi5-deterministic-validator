#!/usr/bin/env node
/*
 * Dev-only SPIKE for the Lab (internal name Studio) screen, Task 1: can a LIVE PREVIEW be one private draft that is
 * updated again and again and shown in an iframe? Everything is measured on the real Divi (Docker dev site) in a real browser.
 *
 *   node scripts/spike-studio-preview.cjs        (needs `make up`; Playwright from ../layoutlab; dev site only)
 *
 * It compiles three different briefs (three design languages) with StudioCompiler, saves them into ONE draft titled
 * `Lab preview (temporary) 1` (DraftPage::insert, then wp_update_post twice), and for every version opens the preview
 * address at 1280 and 400 px. A FRESH throwaway page with the same markup (first render, so no stale CSS is possible) is the control:
 * the updated draft must show the same computed styles. It also checks the escaping of & < $ " in a heading, a button label, a
 * counter title and a text, an iframe inside a real admin page, and that the draft is invisible to anonymous visitors.
 * Every draft is deleted at the end (and the titles are queried afterwards). Writes the raw numbers to
 * dist/studio-preview/results.json; the conclusions are in docs/studio-preview-evidence.md.
 */
'use strict';

const path = require('path');
const fs = require('fs');

const ROOT = path.resolve(__dirname, '..');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');
const { chromium } = require(PW);
const { devSiteOnly, makeWp, loginCookies } = require(path.join(ROOT, 'scripts', 'lib', 'throwaway-pages.cjs'));

devSiteOnly(SITE);
const wp = makeWp(ROOT);
const fails = [];
const results = {};
const check = (ok, label, detail) => {
  console.log((ok ? 'PASS  ' : 'FAIL  ') + label + (detail !== undefined ? '  ' + JSON.stringify(detail) : ''));
  if (!ok) fails.push(label);
};

const PREVIEW_TITLE = 'Lab preview (temporary) 1';
const FRESH_PREFIX = 'aied studio fresh ';

// The text under test: & < $ and " in one string (the counter NUMBER has to be a plain number, StudioBrief refuses anything else).
const nasty = (v) => `V${v} Fish & Chips <b>bold</b> costs $5 "now"`;
const LANGS = ['editorial', 'brutalist-flat', 'glass-dark'];
const brief = (v) => ({
  version: 1,
  title: `Spike ${v}`,
  goal: 'book',
  language: LANGS[v - 1],
  sections: [
    { recipe: 'hero-cta', slots: { 'heading.1': nasty(v), 'button.0.text': `Buy & save <i>$5</i> "now" ${v}`, 'button.0.link': '/contact/' } },
    { recipe: 'stats-counter-band', slots: { 'heading.1': `Numbers ${v}`, 'number-counter.0.number': '1,250', 'number-counter.0.title': nasty(v) + ' counter', 'text.0.body.0': `Body & <u>text</u> $9 "q" ${v}` } },
    { recipe: 'cta-band', slots: { 'heading.0': `Call ${v}: ask & get $1 "free"`, 'button.0.text': `Go ${v}` } },
  ],
});

const phpStr = (s) => JSON.stringify(s); // titles hold no `$`, so a JSON string is a valid PHP double-quoted string
const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64');
/** Compile a brief and save it: id 0 = insert a new draft (DraftPage::insert), else wp_update_post on that id. */
function save(v, id, title) {
  const out = wp('eval', `
    $b = json_decode(base64_decode('${b64(brief(v))}'), true);
    $r = \\AiEditorDivi5\\WP\\StudioCompiler::compile($b, null, home_url());
    if (!$r['ok']) { echo json_encode(['ok'=>false,'problems'=>$r['problems'],'violations'=>$r['violations']]); return; }
    $id = ${id};
    if ($id === 0) { $id = \\AiEditorDivi5\\WP\\DraftPage::insert(${phpStr(title)}, $r['markup'], ''); }
    else { $id = wp_update_post(wp_slash(['ID'=>$id,'post_content'=>$r['markup']]), true); }
    if (is_wp_error($id)) { echo json_encode(['ok'=>false,'error'=>$id->get_error_message()]); return; }
    echo json_encode(['ok'=>true,'id'=>(int)$id,'preview'=>get_preview_post_link($id),'len'=>strlen($r['markup']),'status'=>get_post_status($id),'unfilled'=>count($r['unfilled']),'refused'=>count($r['refused'])]);`, '--user=1');
  return JSON.parse(out);
}
const countTitles = () => Number(wp('eval', `global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'Lab preview%' OR post_title LIKE 'aied studio%'");`));
const deleteIds = (ids) => { if (ids.length) wp('eval', `foreach([${ids.join(',')}] as $i) wp_delete_post($i,true);`, '--user=1'); };

/** Everything the checks need, measured in the page. `marker` = the version's text to find the headline. */
async function measure(p, v) {
  await p.evaluate(() => document.fonts && document.fonts.ready);
  await p.waitForTimeout(2500); // the counter animates
  return p.evaluate((v) => {
    const cs = (el, props) => { const c = getComputedStyle(el); const o = {}; props.forEach((k) => { o[k] = c[k]; }); return o; };
    const main = document.querySelector('#et-main-area, #main-content') || document.body;
    const heads = Array.from(main.querySelectorAll('.et_pb_heading h1, .et_pb_heading h2, .et_pb_heading h3, .et_pb_heading h4, .et_pb_module_heading'));
    const hero = heads.find((h) => h.textContent.includes('V' + v + ' Fish'));
    const btn = Array.from(main.querySelectorAll('a.et_pb_button')).find((a) => a.textContent.includes('Buy &'));
    const counterTitle = Array.from(main.querySelectorAll('.et_pb_number_counter .title, .et_pb_number_counter h3')).find((e) => e.textContent.includes('counter'));
    const num = main.querySelector('.et_pb_number_counter .percent-value, .et_pb_number_counter .percent');
    const body = Array.from(main.querySelectorAll('.et_pb_text')).find((e) => e.textContent.includes('Body &'));
    const cta = heads.find((h) => h.textContent.includes('Call ' + v));
    const sections = Array.from(main.querySelectorAll('.et_pb_section'));
    const header = document.querySelector('header#main-header, #main-header, .et-l--header');
    const footer = document.querySelector('footer#main-footer, #main-footer, .et-l--footer');
    const styleOf = (el) => (el ? cs(el, ['fontFamily', 'fontWeight', 'letterSpacing', 'textTransform', 'color', 'fontSize']) : null);
    return {
      header: header ? header.tagName + '#' + header.id + '.' + header.className.split(' ')[0] : null,
      footer: footer ? footer.tagName + '#' + footer.id + '.' + footer.className.split(' ')[0] : null,
      heroText: hero ? hero.textContent : null,
      heroStyle: styleOf(hero),
      buttonText: btn ? btn.textContent.trim() : null,
      buttonStyle: btn ? cs(btn, ['backgroundColor', 'color', 'borderRadius', 'borderTopWidth', 'borderTopColor', 'paddingTop', 'fontFamily', 'textTransform']) : null,
      counterTitle: counterTitle ? counterTitle.textContent : null,
      counterNumber: num ? num.textContent.trim() : null,
      counterHtml: num ? num.outerHTML.slice(0, 160) : null,
      bodyText: body ? body.textContent.trim() : null,
      ctaText: cta ? cta.textContent : null,
      sectionStyles: sections.slice(0, 3).map((s) => cs(s, ['backgroundColor', 'paddingTop', 'backgroundImage'])),
      sectionCount: sections.length,
      overflowX: document.documentElement.scrollWidth - window.innerWidth,
      innerWidth: window.innerWidth,
    };
  }, v);
}

(async () => {
  const preCount = countTitles();
  console.log('Spike drafts already present before the run: ' + preCount);
  results.preExisting = preCount;
  const made = [];
  const cookies = loginCookies(wp).map(([name, value]) => ({ name, value, url: SITE }));
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.addCookies(cookies);
  const p = await ctx.newPage();
  const consoleErrors = [];
  p.on('pageerror', (e) => consoleErrors.push(String(e.message).slice(0, 160)));
  let draftId = 0;
  const seen = {};
  try {
    for (const v of [1, 2, 3]) {
      console.log(`\n--- version ${v} (${LANGS[v - 1]}) ---`);
      const s = save(v, draftId, PREVIEW_TITLE);
      check(s.ok === true, `v${v}: compiled by StudioCompiler and saved (${draftId ? 'wp_update_post' : 'DraftPage::insert'})`, { id: s.id, len: s.len, unfilled: s.unfilled, refused: s.refused });
      if (!s.ok) { console.log(JSON.stringify(s)); throw new Error('compile or save failed'); }
      if (!draftId) { draftId = s.id; made.push(draftId); }
      check(s.id === draftId && s.status === 'draft', `v${v}: still ONE draft, status draft`, { id: s.id, status: s.status });
      if (v === 1) { results.previewUrl = s.preview; }
      check(/^http:\/\/localhost:8181\/\?(page_id|p)=\d+&preview=true$/.test(s.preview), `v${v}: get_preview_post_link has the expected form`, s.preview);

      // The control: the same markup on a page that has never been rendered.
      const f = save(v, 0, FRESH_PREFIX + v);
      made.push(f.id);

      results['v' + v] = {};
      for (const [w, h] of [[1280, 900], [400, 800]]) {
        await p.setViewportSize({ width: w, height: h });
        const resp = await p.goto(s.preview, { waitUntil: 'networkidle' });
        const m = await measure(p, v);
        await p.goto(f.preview, { waitUntil: 'networkidle' });
        const fresh = await measure(p, v);
        results['v' + v][w] = { preview: m, fresh, status: resp.status() };
        check(resp.status() === 200, `v${v}@${w}: preview returns 200`, resp.status());
        check(!!m.header && !!m.footer, `v${v}@${w}: the real theme header and footer are present`, { header: m.header, footer: m.footer });
        check(m.sectionCount >= 3, `v${v}@${w}: all three sections are drawn`, m.sectionCount);
        const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
        const stylesOk = same(m.heroStyle, fresh.heroStyle) && same(m.buttonStyle, fresh.buttonStyle) && same(m.sectionStyles, fresh.sectionStyles);
        check(stylesOk, `v${v}@${w}: the UPDATED draft's computed styles equal a never-rendered page with the same markup (CSS was regenerated)`, stylesOk ? undefined : { updated: [m.heroStyle, m.buttonStyle, m.sectionStyles], fresh: [fresh.heroStyle, fresh.buttonStyle, fresh.sectionStyles] });
        check(m.overflowX <= 0, `v${v}@${w}: no horizontal overflow`, m.overflowX);
        if (w === 1280) {
          // literal text: the heading, button label, counter title, text and CTA heading show the typed characters, not entities
          check(m.heroText === nasty(v), `v${v}: heading shows & < $ " literally`, m.heroText);
          check(m.buttonText === `Buy & save <i>$5</i> "now" ${v}`, `v${v}: button label shows & < $ " literally`, m.buttonText);
          check(m.counterTitle === nasty(v) + ' counter', `v${v}: counter TITLE shows & < $ " literally`, m.counterTitle);
          check(m.bodyText === `Body & <u>text</u> $9 "q" ${v}`, `v${v}: text body shows & < $ " literally`, m.bodyText);
          check(m.ctaText === `Call ${v}: ask & get $1 "free"`, `v${v}: second heading shows & $ " literally`, m.ctaText);
          check(m.counterNumber === '1,250' || m.counterNumber === '1250', `v${v}: counter number "1,250" displays (legal number)`, { shown: m.counterNumber, html: m.counterHtml });
          seen['v' + v] = JSON.stringify([m.heroStyle, m.buttonStyle, m.sectionStyles]);
        }
      }
    }
    const distinct = new Set(Object.values(seen)).size;
    check(distinct === 3, 'the three versions look different from each other (no stale first version)', distinct);

    // How the CSS reaches the page (for Task 5): inline <style> or a cached file?
    await p.setViewportSize({ width: 1280, height: 900 });
    const html = await (await p.goto(results.previewUrl, { waitUntil: 'networkidle' })).text();
    results.css = {
      etCacheLinks: (html.match(/et-cache\/[^"']+\.css/g) || []).slice(0, 5),
      inlineStyleTags: (html.match(/<style[^>]*>/g) || []).length,
      hasSectionRuleInline: /et_pb_section_0/.test(html),
      robotsMeta: (html.match(/<meta name='robots'[^>]*>|<meta name="robots"[^>]*>/) || [null])[0],
    };
    console.log('CSS delivery: ' + JSON.stringify(results.css));

    // Headers of the preview response and an iframe inside a real admin page.
    const r = await ctx.request.get(results.previewUrl);
    const hdr = r.headers();
    results.previewHeaders = { 'x-frame-options': hdr['x-frame-options'] || null, 'content-security-policy': hdr['content-security-policy'] || null, 'x-robots-tag': hdr['x-robots-tag'] || null, 'cache-control': hdr['cache-control'] || null };
    check(!hdr['x-frame-options'] && !/frame-ancestors/.test(hdr['content-security-policy'] || ''), 'preview response has no frame-blocking header', results.previewHeaders);

    await p.setViewportSize({ width: 1280, height: 900 });
    await p.goto(SITE + '/wp-admin/index.php', { waitUntil: 'domcontentloaded' });
    check(/wp-admin/.test(p.url()) && !/wp-login/.test(p.url()), 'a real administrator session reaches wp-admin', p.url());
    const frames = {};
    for (const w of [900, 400]) {
      const info = await p.evaluate(async ({ src, w }) => {
        const old = document.getElementById('spike-frame'); if (old) old.remove();
        const f = document.createElement('iframe');
        f.id = 'spike-frame'; f.style.cssText = `position:fixed;left:0;top:0;width:${w}px;height:700px;z-index:999999;background:#fff`;
        const loaded = new Promise((res) => { f.onload = () => res(true); setTimeout(() => res(false), 20000); });
        f.src = src; document.body.appendChild(f);
        const ok = await loaded;
        await new Promise((r) => setTimeout(r, 2500));
        let d = null; try { d = f.contentDocument; } catch (e) { return { ok, error: String(e) }; }
        return { ok, url: d && d.location.href, header: !!(d && d.querySelector('#main-header, .et-l--header')), footer: !!(d && d.querySelector('#main-footer, .et-l--footer')), sections: d ? d.querySelectorAll('.et_pb_section').length : 0, width: d ? d.documentElement.clientWidth : 0, overflowX: d ? d.documentElement.scrollWidth - d.documentElement.clientWidth : null, h1: d && d.querySelector('.et_pb_heading h1, .et_pb_heading h2') ? d.querySelector('.et_pb_heading h1, .et_pb_heading h2').textContent : null };
      }, { src: results.previewUrl, w });
      frames[w] = info;
      check(info.ok && info.header && info.footer && info.sections >= 3 && info.url.includes('preview=true'), `iframe ${w}px inside wp-admin loads the preview (header, footer, sections)`, info);
    }
    check(frames[400].overflowX <= 0 && frames[400].width <= 400, 'iframe at 400px has the phone layout without horizontal overflow', { w: frames[400].width, overflow: frames[400].overflowX });
    results.iframe = frames;

    // Visibility to anonymous visitors (node fetch sends no cookies).
    const anon = async (u) => { const r2 = await fetch(SITE + u, { redirect: 'manual' }); return { status: r2.status, body: r2.status === 200 ? await r2.text() : '' }; };
    const term = 'Lab preview';
    const probes = ['/', '/?s=preview+temporary', '/?s=Divi+5+Lab', '/feed/', '/wp-sitemap.xml', '/wp-sitemap-posts-page-1.xml', '/?post_type=page', '/wp-json/wp/v2/pages?per_page=5', '/wp-json/wp/v2/pages?search=preview&per_page=5', '/wp-json/wp/v2/search?search=preview&per_page=5', '/wp-json/wp/v2/pages?status=draft&per_page=5', `/?page_id=${draftId}`, `/?page_id=${draftId}&preview=true`, `/wp-json/wp/v2/pages/${draftId}`];
    const vis = {};
    for (const u of probes) {
      const a = await anon(u);
      const leaks = a.body.includes(term) || a.body.includes('page_id=' + draftId) || a.body.includes('"id":' + draftId + ',') || a.body.includes('preview-temporary');
      vis[u] = { status: a.status, leaks };
    }
    results.visibility = vis;
    const listing = probes.slice(0, 10);
    check(listing.every((u) => !vis[u].leaks && (vis[u].status === 200 || u.includes('status=draft'))), 'draft is not in the front-end, search, feed, sitemap or the public REST lists', listing.map((u) => `${u} ${vis[u].status}${vis[u].leaks ? ' LEAK' : ''}`));
    check(vis[`/?page_id=${draftId}`].status !== 200 && vis[`/?page_id=${draftId}&preview=true`].status !== 200, 'anonymous request to the draft or its preview address is not served', { plain: vis[`/?page_id=${draftId}`].status, preview: vis[`/?page_id=${draftId}&preview=true`].status });
    check(vis[`/wp-json/wp/v2/pages/${draftId}`].status !== 200, 'anonymous REST read of the draft is refused', vis[`/wp-json/wp/v2/pages/${draftId}`].status);

    // Divi's static CSS cache for the draft: none is written for a preview (the CSS is inline).
    results.etCacheDirs = wp('eval', `echo json_encode(array_map('basename', glob(WP_CONTENT_DIR.'/et-cache/${draftId}*') ?: []));`, '--user=1');
    console.log('et-cache entries for the draft: ' + results.etCacheDirs);

    // list_divi_pages, as the plugin's own tool shows it, and the history rows the updates left.
    const listed = JSON.parse(wp('eval', `
      $res = (new \\AiEditorDivi5\\WP\\McpHandler())->callTool('list_divi_pages', ['per_page'=>100]);
      echo json_encode($res);`, '--user=1'));
    const text = JSON.stringify(listed);
    const mm = text.match(/\{[^{}]*Lab preview[^{}]*\}/);
    results.listDiviPages = mm ? mm[0].replace(/\\"/g, '"').slice(0, 300) : null;
    check(text.includes(PREVIEW_TITLE) || text.includes('Lab preview'), 'the draft appears in list_divi_pages with its title', results.listDiviPages);
    results.historyRows = Number(wp('eval', `echo count(\\AiEditorDivi5\\WP\\HistoryStore::summaries(${draftId}));`, '--user=1'));
    console.log('History snapshots the two wp_update_post calls left on the preview draft: ' + results.historyRows);
    check(consoleErrors.length === 0, 'no uncaught page errors while previewing', consoleErrors);
  } catch (e) {
    check(false, 'spike aborted: ' + (e && e.message));
  } finally {
    await browser.close();
    deleteIds(made);
    // sweep any other draft this spike titled, by exact prefix only
    wp('eval', `foreach (get_posts(['post_type'=>'any','post_status'=>'any','numberposts'=>-1,'s'=>'']) as $x) { if (strpos($x->post_title, ${phpStr(FRESH_PREFIX)}) === 0 || $x->post_title === ${phpStr(PREVIEW_TITLE)}) wp_delete_post($x->ID, true); }`, '--user=1');
  }
  const left = countTitles();
  check(left === preCount, 'every draft the spike created was deleted (titles "Lab preview%" or "aied studio%" back to the starting count)', { before: preCount, after: left });
  fs.mkdirSync(path.join(ROOT, 'dist', 'studio-preview'), { recursive: true });
  fs.writeFileSync(path.join(ROOT, 'dist', 'studio-preview', 'results.json'), JSON.stringify(results, null, 2));
  if (fails.length) { console.log(`\n${fails.length} check(s) failed:\n - ` + fails.join('\n - ')); process.exit(1); }
  console.log('\nAll preview-spike checks passed.');
})();
