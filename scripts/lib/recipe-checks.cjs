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
const BASELINABLE = ['php-error', 'empty', 'overflow', 'columns-side-by-side', 'console-error', 'gate'];
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
function measureInPage() {
  const root = document.querySelector('.et-l--post');
  const out = { hasRoot: !!root, overflow: document.documentElement.scrollWidth > window.innerWidth, scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth, visible: false, rows: 0, sideBySide: [] };
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
  return out;
}

const PHP_ERROR = [
  /(?:Fatal error|Parse error|Warning|Notice|Deprecated)(?:<\/b>)?:[^\n]{0,500}?\bon line\b/i,
  /(?:^|\n)\s*(?:<br \/>\s*)?(?:<b>)?(?:Fatal error|Parse error|Warning|Notice|Deprecated)(?:<\/b>)?: /,
  /\bUncaught (?:[\w\\]*)?(?:Error|Exception)\b/,
];

module.exports = { BASELINABLE, NEVER_BASELINED, WIDTHS, parseEntry, parseBaseline, cellKey, knownFailures, isKnownFail, measureInPage, PHP_ERROR };
