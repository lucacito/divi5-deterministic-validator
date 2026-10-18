'use strict';
/*
 * Pure pieces of scripts/verify-recipes.cjs, kept apart so they can be tested without the dev site:
 *  - the render-failure baseline (parse + exact-cell matcher),
 *  - the in-page measurement (runs in the browser; it must stay self-contained: Playwright serialises it),
 *  - the PHP error patterns.
 * Tests: scripts/test-recipe-baseline-matcher.cjs (pure; run by tests/RecipeLibraryTest.php) and
 * scripts/test-recipe-measure.cjs (needs Playwright, not the site; run by `make verify-recipes`).
 */

/** Failure reasons a baseline entry may name. */
const BASELINABLE = ['php-error', 'empty', 'overflow', 'columns-side-by-side', 'console-error', 'gate', 'low-contrast'];
/** Raised by the script's own page handling (a timeout, a navigation crash, an exception): ALWAYS a FAIL, never baselined. */
const NEVER_BASELINED = ['render-error'];
const WIDTHS = ['1280', '900', '400'];

/**
 * One `# render-failures` line `recipe|language|width|code|explanation` (language: `default` or a design language id; width:
 * exactly 1280, 900 or 400, or `gate` for the code gate) as an entry, or throws with the reason. The explanation is
 * documentation only and never takes part in matching.
 */
function parseEntry(line) {
  const parts = line.split('|').map((s) => s.trim());
  if (parts.length < 5) throw new Error('"' + line + '" must be recipe|language|width|code|explanation');
  const [recipe, language, width, code] = parts;
  const explanation = parts.slice(4).join('|');
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(recipe)) throw new Error('bad recipe name in "' + line + '"');
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(language)) throw new Error('bad language in "' + line + '"');
  if (NEVER_BASELINED.includes(code)) throw new Error('"' + code + '" can never be baselined: ' + line);
  if (!BASELINABLE.includes(code)) throw new Error('unknown reason code "' + code + '" in "' + line + '"');
  if ((code === 'gate') !== (width === 'gate')) throw new Error('width "gate" goes with code gate, and only with it: ' + line);
  if (code !== 'gate' && !WIDTHS.includes(width)) throw new Error('width must be exactly 1280, 900 or 400 (no wildcard): ' + line);
  if (explanation === '') throw new Error('an entry needs a written explanation: ' + line);
  return { recipe, language, width, code, explanation };
}

/** The whole baseline file as { noPairs, duplicates, renderFailures: [entry] }; throws on a malformed render-failures line. */
function parseBaseline(text) {
  const out = { noPairs: [], duplicates: [], renderFailures: [] };
  let section = null;
  for (const raw of String(text).split('\n')) {
    const line = raw.trim();
    if (!line) continue;
    const h = /^# (no-pairs|duplicate-layouts|render-failures)$/.exec(line);
    if (h) { section = h[1]; continue; }
    if (line[0] === '#') continue;
    if (section === 'no-pairs') out.noPairs.push(line);
    else if (section === 'duplicate-layouts') out.duplicates.push(line);
    else if (section === 'render-failures') out.renderFailures.push(parseEntry(line));
    else throw new Error('line "' + line + '" is outside a section');
  }
  return out;
}

/** The exact-cell key of an entry or a failure. */
const cellKey = (recipe, language, width, code) => [recipe, language, String(width), code].join('|');

/** A Map of cell key => entry for the baseline's render-failures. */
function knownFailures(entries) {
  return new Map(entries.map((e) => [cellKey(e.recipe, e.language, e.width, e.code), e]));
}

/** True only when this exact recipe, language, width and code is listed. A never-baselined code is never known. */
function isKnownFail(known, recipe, language, width, code) {
  if (NEVER_BASELINED.includes(code)) return false;
  return known.has(cellKey(recipe, language, width, code));
}

/**
 * Runs IN THE PAGE (self-contained). Everything is measured inside the page content (.et-l--post), never the Theme Builder
 * header/footer. Rows: `.et_pb_row` (top-level and the nested `divi/row`, which also carries `et_pb_row_nested`) and
 * `.et_pb_row_inner` (`divi/row-inner`, which has NO `et_pb_row` class); their columns: `.et_pb_column` and
 * `.et_pb_column_inner`. Evidence: Divi 5.15.1, see the task 2 report.
 */
function measureInPage(opts) {
  const root = document.querySelector('.et-l--post');
  const out = { hasRoot: !!root, lowContrast: [], contrastChecked: 0, contrastSkipped: 0, contrastAll: [], overflow: document.documentElement.scrollWidth > window.innerWidth, scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth, visible: false, rows: 0, sideBySide: [], overlaps: [] };
  if (!root) return out;
  const sections = [...root.querySelectorAll('.et_pb_section')];
  const hasMedia = (el) => [...el.querySelectorAll('img, svg, video, canvas, iframe')].some((m) => { const r = m.getBoundingClientRect(); return r.width > 0 && r.height > 0; })
    || [...el.querySelectorAll('*')].some((m) => { const bg = getComputedStyle(m).backgroundImage; return bg && bg !== 'none' && m.getBoundingClientRect().height > 0; });
  out.visible = sections.some((s) => {
    const r = s.getBoundingClientRect();
    return r.height > 20 && r.width > 0 && ((s.innerText || '').trim().length > 0 || hasMedia(s));
  });
  const isColumn = (c) => c.classList.contains('et_pb_column') || c.classList.contains('et_pb_column_inner');
  const rows = [...root.querySelectorAll('.et_pb_row, .et_pb_row_inner')];
  out.rows = rows.length;
  rows.forEach((row, i) => {
    const cols = [...row.children].filter((c) => isColumn(c) && c.getBoundingClientRect().width > 0 && c.getBoundingClientRect().height > 0);
    if (cols.length < 2) return;
    const rects = cols.map((c) => c.getBoundingClientRect());
    const sameLeft = rects.every((r) => Math.abs(r.left - rects[0].left) <= 2);
    const stacked = rects.every((r, k) => k === 0 || r.top >= rects[k - 1].bottom - 2);
    if (!sameLeft && !stacked) out.sideBySide.push({ row: i, nested: row.classList.contains('et_pb_row_inner') || row.classList.contains('et_pb_row_nested'), columns: cols.length, lefts: rects.map((r) => Math.round(r.left)) });
  });
  // Text that is covered by (or covers) a picture: a text element's own text lines against every image box (an `.et_pb_image` module,
  // or an <img> outside one) that neither contains it nor sits inside it. More than 4 px in BOTH directions counts. Text with no
  // visible box, opacity 0 or visibility hidden is skipped. An intentional overlap recipe is exempted by name in
  // fixtures/shapes/recipe-render-allowances.json (verify-recipes.cjs), never here.
  const shown = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && parseFloat(cs.opacity) > 0; };
  const media = [...root.querySelectorAll('.et_pb_image, img')].filter((m) => (m.classList.contains('et_pb_image') || !m.closest('.et_pb_image')) && shown(m) && m.getBoundingClientRect().width >= 24 && m.getBoundingClientRect().height >= 24);
  const texts = [...root.querySelectorAll('h1, h2, h3, h4, h5, h6, p, li, .et_pb_button, .et_pb_module_header')].filter((t) => (t.textContent || '').trim() && shown(t));
  for (const t of texts) {
    const range = document.createRange();
    range.selectNodeContents(t);
    const lines = [...range.getClientRects()].filter((r) => r.width > 0 && r.height > 0);
    for (const m of media) {
      if (m.contains(t) || t.contains(m)) continue;
      const b = m.getBoundingClientRect();
      for (const l of lines) {
        const w = Math.min(l.right, b.right) - Math.max(l.left, b.left);
        const h = Math.min(l.bottom, b.bottom) - Math.max(l.top, b.top);
        if (w > 4 && h > 4) { out.overlaps.push({ text: (t.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 30), image: (m.className && m.className.toString ? m.className.toString() : '').split(' ')[0] || m.tagName.toLowerCase(), w: Math.round(w), h: Math.round(h) }); break; }
      }
    }
  }
  // Computed text contrast. For every visible text node: the element's computed colour (blended with the opacity of it and its ancestors)
  // against the effective background, found by walking the ancestors up the tree: translucent colours are stacked until a SOLID colour
  // (alpha 1) closes the stack. When an element on the way up (up to the root) carries a background image or gradient BEFORE the solid
  // colour is found, the background is unknown and the text is skipped (counted in contrastSkipped). Text that cannot be seen (no box,
  // visibility hidden, display none, opacity 0, font-size 0, a box under 2 px, text pushed off the page by a negative text-indent, or text clipped away
  // by an overflow hidden ancestor) is skipped as well. A ratio under CONTRAST_MIN (3.0, deliberately
  // forgiving: it catches the near-invisible default grey on a dark surface, not fine AA differences) lands in lowContrast. `opts.all` also
  // lists every measured element in contrastAll (the audit uses it). Nested widgets with their own scroll containers are not special-cased.
  const CONTRAST_MIN = 3.0;
  const parseColor = (str) => {
    const m = /^rgba?\(([^)]+)\)$/.exec(String(str).trim());
    if (!m) return null;
    const parts = m[1].split(/[\s,/]+/).filter(Boolean).map(Number);
    if (parts.length < 3 || parts.some((n) => Number.isNaN(n))) return null;
    return { r: parts[0], g: parts[1], b: parts[2], a: parts.length > 3 ? parts[3] : 1 };
  };
  const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
  const lum = (c) => 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
  const over = (top, base) => ({ r: top.r * top.a + base.r * (1 - top.a), g: top.g * top.a + base.g * (1 - top.a), b: top.b * top.a + base.b * (1 - top.a), a: 1 });
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  const seenEl = new Set();
  while (walker.nextNode()) {
    const node = walker.currentNode;
    const txt = (node.textContent || '').replace(/\s+/g, ' ').trim();
    if (!txt) continue;
    const el = node.parentElement;
    if (!el || seenEl.has(el)) continue;
    if (['SCRIPT', 'STYLE', 'NOSCRIPT', 'OPTION', 'TEXTAREA'].includes(el.tagName)) continue;
    seenEl.add(el);
    const cs = getComputedStyle(el);
    const range = document.createRange();
    range.selectNodeContents(node);
    const rects = [...range.getClientRects()].filter((r) => r.width >= 2 && r.height >= 2);
    if (!rects.length || cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.fontSize) === 0) { continue; }
    // Text pushed out of sight is not text a visitor reads: every line box either lies entirely off the page (a negative text-indent such as
    // the slider's dot numbers) or is clipped away completely by an ancestor with overflow hidden/clip/auto/scroll.
    const docW = document.documentElement.scrollWidth;
    const clipped = (r) => {
      if (r.right <= 0 || r.bottom <= -5000 || r.left >= docW + 1) return true;
      for (let a = el; a && a.nodeType === 1 && a !== root.parentElement; a = a.parentElement) {
        const as = getComputedStyle(a);
        if (as.overflowX === 'visible' && as.overflowY === 'visible') continue;
        const b = a.getBoundingClientRect();
        if (Math.min(r.right, b.right) - Math.max(r.left, b.left) < 1 || Math.min(r.bottom, b.bottom) - Math.max(r.top, b.top) < 1) return true;
      }
      return false;
    };
    if (rects.every(clipped)) continue;
    const fg = parseColor(cs.color);
    if (!fg) { out.contrastSkipped++; continue; }
    let opacity = 1;
    let unknown = false;
    const stack = [];
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
      const s = getComputedStyle(n);
      opacity *= parseFloat(s.opacity);
      if (!unknown && (stack.length === 0 || stack[stack.length - 1].a < 0.999)) {
        if (s.backgroundImage && s.backgroundImage !== 'none') { unknown = true; }
        else {
          const c = parseColor(s.backgroundColor);
          if (c && c.a > 0) stack.push(c);
        }
      }
    }
    if (opacity < 0.05) continue;
    if (unknown) { out.contrastSkipped++; continue; }
    let base = { r: 255, g: 255, b: 255, a: 1 };
    if (stack.length && stack[stack.length - 1].a >= 0.999) base = stack.pop();
    while (stack.length) base = over(stack.pop(), base);
    const text = over({ r: fg.r, g: fg.g, b: fg.b, a: fg.a * opacity }, base);
    const l1 = lum(text);
    const l2 = lum(base);
    const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    out.contrastChecked++;
    const rgb = (c) => 'rgb(' + Math.round(c.r) + ',' + Math.round(c.g) + ',' + Math.round(c.b) + ')';
    const item = { text: txt.slice(0, 30), color: rgb(text), bg: rgb(base), ratio: Math.round(ratio * 100) / 100, size: parseFloat(cs.fontSize), tag: el.tagName.toLowerCase(), cls: (el.className && el.className.toString ? el.className.toString() : '').split(' ').slice(0, 2).join(' ') };
    if (opts && opts.all) out.contrastAll.push(item);
    if (ratio < CONTRAST_MIN) out.lowContrast.push(item);
  }
  return out;
}

const PHP_ERROR = [
  /(?:Fatal error|Parse error|Warning|Notice|Deprecated)(?:<\/b>)?:[^\n]{0,500}?\bon line\b/i,
  /(?:^|\n)\s*(?:<br \/>\s*)?(?:<b>)?(?:Fatal error|Parse error|Warning|Notice|Deprecated)(?:<\/b>)?: /,
  /\bUncaught (?:[\w\\]*)?(?:Error|Exception)\b/,
];

module.exports = { BASELINABLE, NEVER_BASELINED, WIDTHS, parseEntry, parseBaseline, cellKey, knownFailures, isKnownFail, measureInPage, PHP_ERROR };
