#!/usr/bin/env node
/*
 * Dev-only: proves, on the real Divi of the Docker dev site and in a real browser, why the style-value guard refuses a value that
 * opens a quote or bracket it never closes. Each candidate padding-top goes into one of three sections of a throwaway published
 * page; the page is opened at 1200px and the three sections' computed padding-top is measured (expected: the candidate, 33px,
 * 44px). For a refused value the other two sections must be shown to LOSE their padding (that is the harm the guard prevents);
 * for an accepted value the layout must pass the checker and the other two sections must keep theirs. Every page it creates is
 * deleted again. Dev site only (localhost).
 *
 *   node scripts/verify-style-values.cjs        (needs `make up`; Playwright from ../layoutlab)
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
const esc = (j) => j.split('--').join('\\u002d\\u002d').split('<').join('\\u003c').split('>').join('\\u003e').split('&').join('\\u0026');
const section = (pad) => `<!-- wp:divi/section ${esc(JSON.stringify({ module: { decoration: { spacing: { desktop: { value: { padding: { top: pad, bottom: '20px', syncVertical: 'off' } } } } } }, builderVersion: V }))} --><!-- wp:divi/row {"builderVersion":"${V}"} --><!-- wp:divi/column {"builderVersion":"${V}"} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"x"}}},"builderVersion":"${V}"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->`;
const page = (pad) => '<!-- wp:divi/placeholder -->' + section(pad) + section('33px') + section('44px') + '<!-- /wp:divi/placeholder -->';
const accepts = (content) => wp('eval', `echo \\AiEditorDivi5\\WP\\LayoutGate::check(${JSON.stringify(content)})->isValid() ? 'yes' : 'no';`) === 'yes';

(async () => {
  const made = [];
  const b = await chromium.launch();
  const p = await (await b.newContext({ viewport: { width: 1200, height: 900 } })).newPage();
  try {
    const cases = [
      // [value, the guard must accept, expected computed padding-top of the first section when accepted]
      ['10px', true, '10px'],
      ['calc(10px + 2vw)', true, null],
      ['clamp(10px, calc(2vw + 4px), 40px)', true, null],
      ["10px'", false], ['10px"', false], ['calc(10px', false], ['calc(10px + (2vw)', false],
    ];
    for (const [value, accepted, expected] of cases) {
      const content = page(value);
      const ok = accepts(content);
      check(ok === accepted, `${JSON.stringify(value)}: the checker ${accepted ? 'accepts' : 'refuses'} it`);
      // Saved straight to the database on purpose: the point is what Divi does with the value, to show what the guard prevents.
      const id = wp('eval', `echo wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'aied verify style values','post_content'=>wp_slash(${JSON.stringify(content)}),'meta_input'=>['_et_pb_use_builder'=>'on','_et_pb_use_divi_5'=>'on']]);`, '--user=1');
      made.push(id);
      await p.goto(`${SITE}/?page_id=${id}`, { waitUntil: 'networkidle' });
      const pads = await p.evaluate(() => Array.from(document.querySelectorAll('.et_pb_section')).filter((s) => s.closest('#et-main-area, #main-content')).map((s) => getComputedStyle(s).paddingTop).slice(0, 3));
      if (accepted) {
        check(pads[1] === '33px' && pads[2] === '44px', `${JSON.stringify(value)}: the other sections keep their padding (${JSON.stringify(pads)})`);
        if (expected) check(pads[0] === expected, `${JSON.stringify(value)}: the section gets ${expected} (${pads[0]})`);
      } else {
        check(pads[1] !== '33px' && pads[2] !== '44px', `${JSON.stringify(value)}: it really breaks the page: the other sections LOSE their padding (${JSON.stringify(pads)})`);
      }
    }
  } finally {
    if (made.length) wp('eval', `foreach([${made.join(',')}] as $i) wp_delete_post($i,true);`, '--user=1');
    await b.close();
  }
  check(wp('eval', "global $wpdb; echo $wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title='aied verify style values'\");") === '0', 'every throwaway page was deleted');
  if (fails.length) { console.log(`\n${fails.length} check(s) failed`); process.exit(1); }
  console.log('\nAll style-value shapes proven on this Divi.');
})();
