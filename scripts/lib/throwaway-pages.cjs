'use strict';
/*
 * Shared by the render gates (scripts/verify-recipes.cjs, scripts/verify-kits.cjs): the dev-site guard, WP-CLI in the Docker container,
 * a logged-in session for previews, the page's own console errors, and the throwaway pages of ONE run (titled `<prefix><runToken> ...`,
 * deleted by that exact run prefix, never by a search).
 */
const { execFileSync } = require('child_process');
const crypto = require('crypto');

/** Exits (code 2) unless the site is a local dev site. */
function devSiteOnly(site) {
  if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(site)) {
    console.error('Dev site only (localhost). Refusing ' + site);
    process.exit(2);
  }
}

/** WP-CLI in the wpcli container (cwd = the repo root). */
const makeWp = (root) => (...a) => execFileSync('docker', ['compose', 'exec', '-T', 'wpcli', 'php', '-d', 'memory_limit=1G', '/usr/local/bin/wp', ...a], { cwd: root, encoding: 'utf8' }).trim();

/** [[name, value]] auth cookies of user 1, valid one hour. */
const loginCookies = (wp) => JSON.parse(wp('eval', '$u=1;$e=time()+3600;$t=WP_Session_Tokens::get_instance($u)->create($e);echo json_encode([[AUTH_COOKIE,wp_generate_auth_cookie($u,$e,"auth",$t)],[LOGGED_IN_COOKIE,wp_generate_auth_cookie($u,$e,"logged_in",$t)]]);'));

/** Collects the page's own console errors and uncaught exceptions (its origin, or no URL; never favicon or third-party) into the returned array. */
function watchConsole(p, site) {
  const errors = [];
  p.on('pageerror', (e) => errors.push('uncaught: ' + String(e && e.message ? e.message : e).slice(0, 200)));
  p.on('console', (m) => {
    if (m.type() !== 'error') return;
    const url = (m.location() && m.location().url) || '';
    if (/favicon\.ico/.test(url)) return;
    // Only errors from the page itself: its own origin, or a script error with no URL. A third-party request is not ours.
    if (url && !url.startsWith(site)) return;
    errors.push(('console: ' + m.text()).slice(0, 200) + (url ? ' (' + url.slice(site.length) + ')' : ''));
  });
  return errors;
}

/**
 * The throwaway pages of one run. `titlePrefix` is the bare prefix (`aied recipe `), the run prefix adds this run's token (process id +
 * 3 random bytes). `noun` names the pages in messages.
 */
function throwawayPages(wp, titlePrefix, noun) {
  const runToken = process.pid + '-' + crypto.randomBytes(3).toString('hex');
  const runPrefix = titlePrefix + runToken + ' ';
  const created = [];
  /** Every page's { ID, post_title } (a full list, never a content search). */
  const allPages = () => JSON.parse(wp('post', 'list', '--post_type=page', '--post_status=any', '--fields=ID,post_title', '--format=json') || '[]');
  const idsWithPrefix = (rows, prefix) => rows.filter((r) => typeof r.post_title === 'string' && r.post_title.startsWith(prefix)).map((r) => String(r.ID));

  /**
   * Deletes this run's throwaway pages: the recorded ids plus every page whose title starts with the exact run prefix (a page could have
   * been inserted by a call that then failed before returning its id). Never a search, never a page without that prefix, never another
   * run's page, numeric ids only. Safe to call twice. Returns true when everything went well.
   */
  function sweep() {
    let ok = true;
    let swept = [];
    try { swept = idsWithPrefix(allPages(), runPrefix); } catch (e) { console.log(`WARNING could not list leftover ${noun} pages`); ok = false; }
    for (const id of [...new Set([...created, ...swept])]) {
      if (!/^\d+$/.test(String(id))) { console.log('WARNING ignoring non-numeric page id ' + id); ok = false; continue; }
      try { wp('post', 'delete', String(id), '--force'); } catch (e) { console.log('WARNING could not delete page ' + id); ok = false; }
    }
    created.length = 0;
    return ok;
  }

  /** Deletes one page this run created (recorded id, numeric only); the final sweep catches anything this misses. */
  function dropPage(id) {
    if (!/^\d+$/.test(String(id))) return;
    try { wp('post', 'delete', String(id), '--force'); } catch (e) { console.log('WARNING could not delete page ' + id + ' (the final sweep retries)'); return; }
    const at = created.indexOf(String(id));
    if (at >= 0) created.splice(at, 1);
  }

  /** Deletes ALL pages whose title starts with the bare prefix (only when no other run is going). */
  function removeOrphans() {
    let ok = true;
    let ids = [];
    try { ids = idsWithPrefix(allPages(), titlePrefix); } catch (e) { console.log('ERROR could not list pages'); return false; }
    for (const id of ids) {
      if (!/^\d+$/.test(id)) { console.log('WARNING ignoring non-numeric page id ' + id); ok = false; continue; }
      try { wp('post', 'delete', id, '--force'); console.log(`deleted orphan ${noun} page ${id}`); } catch (e) { console.log('WARNING could not delete page ' + id); ok = false; }
    }
    console.log(`removed ${ids.length} orphan ${noun} page(s)`);
    return ok;
  }

  /** Pages with the bare prefix (another run's, or leftovers): counted, never deleted. */
  const foreignCount = () => idsWithPrefix(allPages(), titlePrefix).length;

  return { runToken, runPrefix, created, allPages, idsWithPrefix, sweep, dropPage, removeOrphans, foreignCount };
}

/**
 * On SIGINT/SIGTERM/SIGHUP: sets run.cleaning (nothing new is created after it), closes run.browser, sweeps this run's pages and exits.
 * `run` is { cleaning: false, browser: null }, shared with the caller.
 */
function cleanupOnSignals(run, sweep, runPrefix) {
  for (const sig of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
    process.on(sig, async () => {
      if (run.cleaning) return;
      run.cleaning = true;
      console.log('\n' + sig + ': removing the throwaway pages');
      try { if (run.browser) await run.browser.close(); } catch (e) { /* leaving anyway */ }
      const ok = sweep();
      console.log(ok ? 'Cleaned up.' : 'WARNING cleanup was incomplete; run `wp post list --post_type=page --fields=ID,post_title` and look for "' + runPrefix + '"');
      process.exit(sig === 'SIGINT' ? 130 : sig === 'SIGHUP' ? 129 : 143);
    });
  }
}

module.exports = { devSiteOnly, makeWp, loginCookies, watchConsole, throwawayPages, cleanupOnSignals };
