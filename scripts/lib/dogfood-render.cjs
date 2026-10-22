'use strict';
/*
 * Pure helpers of scripts/dogfood-render.cjs (no site, no browser, no child process): the argument parser, the output name, the run title and
 * the verdict. Tested by scripts/test-dogfood-render.cjs.
 *
 * Controller ruling 7 (2026-10-22): the render script takes NO --kit option; the kit is the scorer's business, not the renderer's. The page
 * title carries the run token and the output name only.
 */

/** Every page the render script creates starts with this; the run prefix adds this run's token (see throwaway-pages.cjs). */
const TITLE_PREFIX = 'aied dogfood ';
const USAGE = 'usage: node scripts/dogfood-render.cjs <page.html> [--name <label>] [--out <dir>]\n       node scripts/dogfood-render.cjs --clean-orphans';
const WIDTHS = [1280, 400];

/** The longest error text the script prints (an exception message can be tens of KB, e.g. a whole wp-cli output). */
const MAX_ERROR = 300;
/** One line, at most MAX_ERROR characters, with an ellipsis when cut. */
const shorten = (e) => {
  const t = String(e && e.message ? e.message : e).replace(/\s+/g, ' ').trim();
  return t.length > MAX_ERROR ? t.slice(0, MAX_ERROR) + '...' : t;
};

/** Only [a-z0-9-] kept (lower-cased), everything else becomes '-'. */
const clean = (s) => String(s).toLowerCase().replace(/[^a-z0-9-]/g, '-');

/**
 * argv (process.argv.slice(2)) -> { page, name|null, out|null, cleanOrphans:false } | { cleanOrphans:true } | { error: USAGE }.
 * Flags: --name <label> (lower-case [a-z0-9-]+, replaces the name taken from the path), --out <dir>, --clean-orphans (alone only).
 * Exactly one page path unless --clean-orphans. Any other flag, a flag without a value, a repeated flag: an error.
 */
function parseArgs(argv) {
  const fail = { error: USAGE };
  if (!Array.isArray(argv)) return fail;
  let page = null;
  let name = null;
  let out = null;
  let cleanOrphans = false;
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--clean-orphans') {
      if (cleanOrphans) return fail;
      cleanOrphans = true;
    } else if (a === '--name' || a === '--out') {
      const v = argv[++i];
      if (typeof v !== 'string' || v === '' || v.startsWith('--')) return fail;
      if (a === '--name') {
        if (name !== null || !/^[a-z0-9-]+$/.test(v)) return fail;
        name = v;
      } else {
        if (out !== null) return fail;
        out = v;
      }
    } else if (typeof a !== 'string' || a === '' || a.startsWith('--')) {
      return fail;
    } else {
      if (page !== null) return fail;
      page = a;
    }
  }
  if (cleanOrphans) return page === null && name === null && out === null ? { cleanOrphans: true } : fail;
  if (page === null) return fail;
  return { page, name, out, cleanOrphans: false };
}

/**
 * D9: the page's parent directory name, plus `-<stem>` when the file is not `page.html`:
 * 'edit-glass-edge/page-edited.html' -> 'edit-glass-edge-page-edited', '<dir>/page.html' -> '<dir>'. Only [a-z0-9-] kept, else '-'.
 */
function outName(pagePath) {
  const parts = String(pagePath).split(/[\\/]+/).filter((s) => s !== '' && s !== '.');
  const file = parts.length ? parts[parts.length - 1] : '';
  const stem = file.replace(/\.[^.]*$/, '');
  const dir = parts.length > 1 ? parts[parts.length - 2] : '';
  const name = stem === 'page' && dir !== '' ? dir : (dir !== '' ? dir + '-' + stem : stem);
  return clean(name === '' ? 'page' : name);
}

/** `${TITLE_PREFIX}${token} ${name}`: the token is the run's, so the run deletes exactly its own pages. */
function runTitle(token, name) {
  return `${TITLE_PREFIX}${token} ${name}`;
}

/**
 * results = { valid, verdict, widths: { 1280: { overflow, errors:[], phpError }, 400: {...} } (maybe partial), cleanupOk, error? } -> { ok, lines }.
 * An internal script error (results.error) is always a failure, whatever else was measured (its text is cut to MAX_ERROR characters).
 * ok only when the layout is valid, BOTH widths were measured, the overflow is 0 at both, there is no console/HTTP/empty error, no PHP error
 * text and the cleanup finished. A width that was not measured is a failure, never a pass.
 */
function summarize(results) {
  const r = results || {};
  const widths = r.widths || {};
  const lines = [];
  const why = [];
  if (!r.valid) {
    why.push('layout not valid' + (r.verdict ? ' (' + String(r.verdict).replace(/^INVALID\s*/, '') + ')' : ''));
  } else {
    lines.push('layout valid');
  }
  for (const w of WIDTHS) {
    const m = widths[w];
    if (!m || typeof m.overflow !== 'number') {
      why.push(`${w}px not measured`);
      continue;
    }
    const errors = Array.isArray(m.errors) ? m.errors : [];
    lines.push(`${w}px: overflow ${m.overflow}, errors ${errors.length ? errors.join(' | ') : 'none'}${m.phpError ? ', PHP error: ' + m.phpError : ''}`);
    if (m.overflow !== 0) why.push(`${w}px horizontal overflow ${m.overflow}`);
    if (errors.length) why.push(`${w}px ${errors.length} error(s)`);
    if (m.phpError) why.push(`${w}px PHP error text`);
  }
  if (r.cleanupOk !== true) why.push('cleanup incomplete');
  if (r.error) why.push('script error: ' + shorten(r.error));
  const ok = why.length === 0;
  for (const w of why) lines.push('FAIL ' + w);
  lines.push(ok ? 'OK' : 'FAILED');
  return { ok, lines };
}

module.exports = { TITLE_PREFIX, USAGE, WIDTHS, MAX_ERROR, shorten, parseArgs, outName, runTitle, summarize };
