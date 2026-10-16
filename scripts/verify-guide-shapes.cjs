#!/usr/bin/env node
/*
 * Dev-only: proves, on the real Divi of the Docker dev site and in a real browser, the attribute shapes that
 * get_style_guide and get_image_guide teach for per-device settings and for equal-size images. Each shape is
 * saved in a throwaway draft page (and must pass the validator), the page is opened at real viewport widths and the
 * computed styles / box sizes are measured. Every page it creates is deleted again. Dev site only (localhost).
 *
 *   node scripts/verify-guide-shapes.cjs        (needs `make up`; Playwright from ../layoutlab)
 */
'use strict';

const { execFileSync } = require('child_process');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PW = process.env.PLAYWRIGHT_PATH || path.resolve(ROOT, '..', 'layoutlab', 'node_modules', 'playwright');
const SITE = (process.env.AIED_SITE || 'http://localhost:8181').replace(/\/$/, '');
const { chromium } = require(PW);

if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(SITE)) {
  console.error('Dev site only (localhost). Refusing ' + SITE);
  process.exit(2);
}

const wp = (...a) => execFileSync('docker', ['compose', 'exec', '-T', 'wpcli', 'php', '-d', 'memory_limit=1G', '/usr/local/bin/wp', ...a], { cwd: ROOT, encoding: 'utf8' }).trim();
const fails = [];
const check = (ok, label) => { console.log((ok ? 'PASS  ' : 'FAIL  ') + label); if (!ok) fails.push(label); };

const V = '5.15.1';
const BS = String.fromCharCode(92);
// WordPress's block attribute serialisation: < > & -- as unicode escapes.
const esc = (j) => j.split('--').join(BS + 'u002d' + BS + 'u002d').split('<').join(BS + 'u003c').split('>').join(BS + 'u003e').split('&').join(BS + 'u0026');
const block = (name, attrs, inner) => { const j = esc(JSON.stringify(attrs)); return inner === undefined ? `<!-- wp:divi/${name} ${j} /-->` : `<!-- wp:divi/${name} ${j} -->${inner}<!-- /wp:divi/${name} -->`; };
const setp = (o, p, v) => { const k = p.split('.'); let d = o; for (const x of k.slice(0, -1)) { d = d[x] = d[x] || {}; } d[k[k.length - 1]] = v; };
const mod = (name, base, extra) => { const a = JSON.parse(JSON.stringify(base)); a.builderVersion = V; for (const [p, v] of Object.entries(extra)) setp(a, p, v); return block(name, a); };
const column = (type, inner) => block('column', { module: { advanced: { type: { desktop: { value: type } } } }, builderVersion: V }, inner);
const page = (rows) => '<!-- wp:divi/placeholder -->' + block('section', { builderVersion: V }, rows) + '<!-- /wp:divi/placeholder -->';
const oneColumn = (mods) => block('row', { module: { advanced: { columnStructure: { desktop: { value: '4_4' } } } }, builderVersion: V }, column('4_4', mods.join('')));
const twoColumns = (a, b) => block('row', { module: { advanced: { columnStructure: { desktop: { value: '1_2,1_2' } } } }, builderVersion: V }, column('1_2', a) + column('1_2', b));
const img = (file) => `${SITE}/wp-content/plugins/jhmg-ai-editor-for-divi-5/assets/images/${file}`;
const image = (file, extra) => mod('image', { image: { innerContent: { desktop: { value: { src: img(file), alt: 'x' } } } } }, extra);

const created = [];
function makePage(label, content) {
  const b64 = Buffer.from(content).toString('base64');
  const out = wp('eval', `$c=base64_decode('${b64}');$g=AiEditorDivi5${BS}WP${BS}LayoutGate::check($c);$id=wp_insert_post(wp_slash(['post_type'=>'page','post_title'=>'aied shapes ${label}','post_content'=>$c,'post_status'=>'draft','meta_input'=>['_et_pb_use_divi_5'=>'on','_et_pb_use_builder'=>'on']]));echo $id.'|'.($g->isValid()?'valid':'INVALID '.json_encode(array_map(fn($v)=>$v->toArray(),$g->violations())));`);
  const [id, verdict] = out.split('|');
  created.push(id);
  check(verdict === 'valid', `${label}: the layout passes the validator${verdict === 'valid' ? '' : ' - ' + verdict}`);
  return id;
}

(async () => {
  let browser;
  try {
    const cookies = JSON.parse(wp('eval', '$u=1;$e=time()+3600;$t=WP_Session_Tokens::get_instance($u)->create($e);echo json_encode([[AUTH_COOKIE,wp_generate_auth_cookie($u,$e,"auth",$t)],[LOGGED_IN_COOKIE,wp_generate_auth_cookie($u,$e,"logged_in",$t)]]);'));
    browser = await chromium.launch();
    const measure = async (id, width, fn, arg) => {
      const ctx = await browser.newContext({ viewport: { width, height: 900 } });
      await ctx.addCookies(cookies.map(([name, value]) => ({ name, value, url: SITE })));
      const p = await ctx.newPage();
      await p.goto(`${SITE}/?page_id=${id}&preview=true`, { waitUntil: 'load' });
      await p.waitForTimeout(500);
      const r = await p.evaluate(fn, arg);
      await ctx.close();
      return r;
    };
    const style = (needle, prop) => ([n, pr]) => { const el = [...document.querySelectorAll('.et_pb_module')].find((e) => e.textContent.includes(n)); if (!el) return 'not found'; const t = el.querySelector('h1,h2,h3,h4,.et_pb_text_inner') || el; return getComputedStyle(pr === 'padding-top' || pr === 'margin-bottom' ? el : t).getPropertyValue(pr); };

    // ---- 1. a smaller headline on phones
    let id = makePage('heading', page(oneColumn([mod('heading', { title: { innerContent: { desktop: { value: 'SIZEMARK' } } } }, {
      'title.decoration.font.font.desktop.value.size': '48px', 'title.decoration.font.font.tablet.value.size': '36px', 'title.decoration.font.font.phone.value.size': '24px' })])));
    const sizes = [];
    for (const w of [1280, 900, 400]) sizes.push(await measure(id, w, style(), ['SIZEMARK', 'font-size']));
    check(sizes.join(',') === '48px,36px,24px', `headline size per device: desktop/tablet/phone = ${sizes.join(' / ')} (expected 48px / 36px / 24px)`);

    // ---- 2. text size and alignment per device
    id = makePage('text', page(oneColumn([mod('text', { content: { innerContent: { desktop: { value: '<p>TEXTMARK</p>' } } } }, {
      'content.decoration.bodyFont.body.font.desktop.value.size': '20px', 'content.decoration.bodyFont.body.font.phone.value.size': '15px',
      'content.decoration.bodyFont.body.font.desktop.value.textAlign': 'center', 'content.decoration.bodyFont.body.font.phone.value.textAlign': 'left' })])));
    const t1 = await measure(id, 1280, style(), ['TEXTMARK', 'font-size']);
    const t2 = await measure(id, 400, style(), ['TEXTMARK', 'font-size']);
    check(t1 === '20px' && t2 === '15px', `text size per device: ${t1} -> ${t2} (expected 20px -> 15px)`);
    const a1 = await measure(id, 1280, style(), ['TEXTMARK', 'text-align']);
    const a2 = await measure(id, 400, style(), ['TEXTMARK', 'text-align']);
    check(a1 === 'center' && a2 === 'left', `text alignment per device: ${a1} -> ${a2} (expected center -> left)`);

    // ---- 3. spacing per device
    id = makePage('spacing', page(oneColumn([mod('heading', { title: { innerContent: { desktop: { value: 'SPACEMARK' } } } }, {
      'module.decoration.spacing.desktop.value.padding.top': '60px', 'module.decoration.spacing.phone.value.padding.top': '20px' })])));
    const p1 = await measure(id, 1280, style(), ['SPACEMARK', 'padding-top']);
    const p2 = await measure(id, 400, style(), ['SPACEMARK', 'padding-top']);
    check(p1 === '60px' && p2 === '20px', `padding per device: ${p1} -> ${p2} (expected 60px -> 20px)`);

    // ---- 4. columns stack on phones: the guide's rule for rows with more than one column
    const col = (t) => block('column', { module: { advanced: { type: { desktop: { value: '1_2' } } } }, builderVersion: V }, mod('heading', { title: { innerContent: { desktop: { value: t } } } }, {}));
    const stackedRow = (layoutPhone) => block('row', { module: { advanced: { columnStructure: { desktop: { value: '1_2,1_2' } } }, decoration: { layout: layoutPhone } }, builderVersion: V }, col('LEFTMARK') + col('RIGHTMARK'));
    const stack = (id_, w) => measure(id_, w, () => { const r = (n) => [...document.querySelectorAll('.et_pb_module')].find((e) => e.textContent.includes(n)).getBoundingClientRect(); const a = r('LEFTMARK'), b = r('RIGHTMARK'); return b.top > a.top + 5 ? 'stacked' : 'side by side'; });
    id = makePage('columns-guide-rule', page(stackedRow({ phone: { value: { display: 'flex', flexDirection: 'column', rowGap: '0px' } } })));
    const s1 = await stack(id, 1280), s2 = await stack(id, 400);
    check(s1 === 'side by side' && s2 === 'stacked', `row with the guide's phone layout (flexDirection column): ${s1} on desktop, ${s2} on phone`);
    id = makePage('columns-no-rule', page(stackedRow({})));
    const s3 = await stack(id, 400);
    console.log(`INFO  the same two columns with no phone rule are ${s3} on a phone`);

    // ---- 5. images of different shapes in the same size
    const sameSize = [];
    const boxes = (w, id_) => measure(id_, w, () => [...document.querySelectorAll('.et_pb_image img')].map((i) => { const b = i.getBoundingClientRect(); return [Math.round(b.width), Math.round(b.height)]; }));
    const fit = { 'module.advanced.sizing.desktop.value.aspectRatio': { width: '4', height: '3' }, 'image.decoration.fit.desktop.value.objectFit': 'cover' };
    id = makePage('images-equal', page(twoColumns(image('hero-blue-1.svg', fit), image('square-slate-1.svg', fit))));
    const eq = await boxes(1280, id);
    check(eq.length === 2 && eq[0][0] === eq[1][0] && eq[0][1] === eq[1][1], `aspectRatio 4:3 + objectFit cover: a 16:9 image and a 1:1 image are both ${JSON.stringify(eq)}`);
    id = makePage('images-plain', page(twoColumns(image('hero-blue-1.svg', {}), image('square-slate-1.svg', {}))));
    const plain = await boxes(1280, id);
    check(plain.length === 2 && (plain[0][1] !== plain[1][1]), `without it the same two images differ: ${JSON.stringify(plain)}`);
    id = makePage('images-height-only', page(twoColumns(image('hero-blue-1.svg', { 'module.advanced.sizing.desktop.value.height': '200px', 'image.decoration.fit.desktop.value.objectFit': 'cover' }), image('square-slate-1.svg', { 'module.advanced.sizing.desktop.value.height': '200px', 'image.decoration.fit.desktop.value.objectFit': 'cover' }))));
    const hOnly = await boxes(1280, id);
    check(hOnly.length === 2 && hOnly[0][0] !== hOnly[1][0], `a fixed height alone does NOT make them equal (the guide says so): ${JSON.stringify(hOnly)}`);

    // ---- 6. the converter's own rows: side by side on desktop, stacked on a phone (4 columns: two by two on a tablet)
    const cards = (n) => '<section><h2>Plans</h2>' + Array.from({ length: n }, (_, i) => `<div class="c"><h3>CARD${i + 1}</h3><p>text ${i + 1}</p></div>`).join('') + '</section>';
    for (const n of [2, 3, 4]) {
      const b64 = Buffer.from(cards(n)).toString('base64');
      const cid = wp('eval', `$h=base64_decode('${b64}');$q=new WP_REST_Request('POST','/ai-editor-divi5/v1/html-to-divi');$q->set_header('content-type','application/json');$q->set_body(wp_json_encode(['html'=>$h,'create'=>true,'title'=>'aied shapes converter ${n}']));$r=rest_do_request($q);$d=json_decode(wp_json_encode($r->get_data()),true);echo $d['page']['id'] ?? 'FAIL';`, '--user=1');
      created.push(cid);
      const arrange = (w) => measure(cid, w, () => { const tops = [...document.querySelectorAll('.et_pb_module')].filter((e) => /^CARD\d/.test(e.textContent.trim().split(/\s/)[0])).map((e) => Math.round(e.getBoundingClientRect().top)); return JSON.stringify([...new Set(tops)].length); });
      const rowsDesktop = await arrange(1280), rowsTablet = await arrange(900), rowsPhone = await arrange(400);
      check(rowsDesktop === '1' && rowsPhone === String(n), `converter, ${n} cards: ${rowsDesktop} row(s) on desktop, ${rowsTablet} on a tablet, ${rowsPhone} on a phone (expected 1 / ${n === 4 ? 2 : 1} / ${n})`);
      if (n === 4) check(rowsTablet === '2', 'converter, 4 cards: two by two on a tablet');
    }

    // ---- 7. a sticky section (real Divi 5.15.1 builder save: module.decoration.sticky.desktop.value.position = "top", offset.top = "20px")
    const sectionWith = (extra, inner) => block('section', Object.assign({ builderVersion: V }, extra), inner);
    const spacer = sectionWith({ module: { decoration: { spacing: { desktop: { value: { padding: { top: '500px', bottom: '500px', syncVertical: 'on' } } } } } } }, oneColumn([mod('text', { content: { innerContent: { desktop: { value: '<p>SPACEMARK</p>' } } } }, {})]));
    const tall = sectionWith({ module: { decoration: { spacing: { desktop: { value: { padding: { top: '2500px', bottom: '100px', syncVertical: 'off' } } } } } } }, oneColumn([mod('text', { content: { innerContent: { desktop: { value: '<p>TALLMARK</p>' } } } }, {})]));
    const stickySection = (sticky) => sectionWith(sticky ? { module: { decoration: { sticky: { desktop: { value: sticky } } } } } : {}, oneColumn([mod('heading', { title: { innerContent: { desktop: { value: 'STICKYMARK' } } } }, {})]));
    const stickyTops = (id_) => measure(id_, 1280, async () => {
      const el = [...document.querySelectorAll('.et_pb_module')].find((e) => e.textContent.includes('STICKYMARK'));
      const out = [];
      for (const y of [1800, 2400]) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 900)); out.push(Math.round(el.getBoundingClientRect().top)); }
      return out;
    });
    const wrap = (sticky) => '<!-- wp:divi/placeholder -->' + spacer + stickySection(sticky) + tall + '<!-- /wp:divi/placeholder -->';
    id = makePage('sticky-none', wrap(null));
    const topNone = await stickyTops(id);
    id = makePage('sticky-top', wrap({ position: 'top', offset: { top: '20px' } }));
    const topSticky = await stickyTops(id);
    check(topNone[0] - topNone[1] >= 500, `without sticky the section scrolls with the page: its top is ${topNone[0]}px then ${topNone[1]}px after scrolling 600px more`);
    check(topSticky[0] >= 0 && topSticky[0] <= 160 && Math.abs(topSticky[0] - topSticky[1]) <= 2, `position "top" with offset.top 20px keeps it fixed near the top of the screen: ${topSticky[0]}px then ${topSticky[1]}px after scrolling 600px more`);

    // ---- 8. a scroll effect (real Divi 5.15.1 builder save: module.decoration.scroll.desktop.value.verticalMotion.enable = "on"; the other effects use the same group, defined in Divi's own ScrollEffectsUtils::$scroll_effect_default_group_attr)
    const effectSection = (effects) => sectionWith(effects ? { module: { decoration: { scroll: { desktop: { value: effects } } } } } : {}, oneColumn([mod('heading', { title: { innerContent: { desktop: { value: 'STICKYMARK' } } } }, {})]));
    const effectProbe = (id_) => measure(id_, 1280, async () => {
      const sec = [...document.querySelectorAll('.et_pb_module')].find((e) => e.textContent.includes('STICKYMARK')).closest('.et_pb_section');
      const seen = [];
      for (const y of [300, 700, 1100, 1500]) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 700)); seen.push(getComputedStyle(sec).transform + '|' + getComputedStyle(sec).opacity); }
      return [...new Set(seen)].length;
    });
    const wrapEffect = (effects) => '<!-- wp:divi/placeholder -->' + spacer + effectSection(effects) + tall + '<!-- /wp:divi/placeholder -->';
    id = makePage('effect-none', wrapEffect(null));
    const stillNone = await effectProbe(id);
    id = makePage('effect-vertical', wrapEffect({ verticalMotion: { enable: 'on' } }));
    const moving = await effectProbe(id);
    check(stillNone === 1, `without a scroll effect the section's transform and opacity never change while scrolling (${stillNone} distinct state)`);
    check(moving > 1, `verticalMotion.enable "on" moves the section as the page scrolls (${moving} distinct states over four scroll positions)`);
    id = makePage('effect-fade', wrapEffect({ fade: { enable: 'on' } }));
    const fading = await effectProbe(id);
    check(fading > 1, `fade.enable "on" changes the section's opacity as the page scrolls (${fading} distinct states)`);
  } catch (e) {
    check(false, 'script error: ' + (e && e.message ? e.message : e));
  } finally {
    for (const id of created) { try { wp('post', 'delete', id, '--force'); } catch (e) { /* best effort */ } }
    if (browser) await browser.close();
  }
  console.log(fails.length ? `\n${fails.length} check(s) FAILED` : '\nAll shapes proven on this Divi.');
  process.exit(fails.length ? 1 : 0);
})();
