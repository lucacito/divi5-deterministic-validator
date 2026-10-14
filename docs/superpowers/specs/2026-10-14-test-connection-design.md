# Test connection (free plugin, Settings screen)

Date: 2026-10-14. Status: approved by the owner's delegation ("write the specs, approve them").
Source: Facebook-thread backlog item "Test connection button in Settings that checks the endpoint, API key and
permissions and shows plain-English errors (also catches security plugins blocking the REST API)".

## Goal

A "Test connection" button on the Settings screen that tells a non-technical site owner, in plain English, whether
an AI assistant can reach this site and what to do if not. Today the only signal is the passive line "Last request
from an AI assistant: X ago", which proves a request arrived but cannot say why none has.

Success: for each of the failure causes below, the owner sees one specific sentence and one specific fix, never a raw
HTTP code alone.

## Constraints

- `wp-plugin/` may not make HTTP requests (`wp_remote_*`, cURL, `file_get_contents` on URLs are banned by
  `WpOrgComplianceTest`; never weaken that test). So the probes run **from the admin's browser** with `fetch()`
  against the site's own REST URL, and the plain-English verdict is computed in a **pure PHP class** (testable
  without WordPress), reached through one `admin-ajax` action.
- Read-only: the test never writes to the site. It calls only `ping` and `tools/list` on the MCP endpoint (neither is
  logged by `UsageTracker`, so the test does not fake an "AI assistant request").
- The API key is already rendered in the page (the Show/Copy buttons); the probe reuses it from the DOM. The key is
  **never** sent to the diagnosis action, only status codes and short response facts.
- Honest scope: the probe proves the site works for the admin's browser. It cannot prove that the AI vendor's network
  path is open (a CDN rule that blocks non-browser clients, an IP allow-list). The result says so in one line when
  every check passes.

## Design

### Probes (browser, `assets/admin.js`)

Run in order; stop at the first blocking failure but always send what was observed.

1. `unauth`: `POST {mcpUrl}` with a JSON-RPC `ping`, **no** Authorization header. A healthy site answers 401 with a
   JSON body `{"code":"unauthorized",...}`. Anything else says the request never reached the plugin.
2. `auth`: the same `POST` with `Authorization: Bearer <key>`. Healthy: 200 and a JSON-RPC result.
3. `tools`: `tools/list` with the key. Healthy: 200 and `result.tools` is a non-empty array. Records the tool count.

For each probe the browser records only: `status` (number, 0 when `fetch` threw), `contentType` (lower-cased main
type), `jsonCode` (the `code` string of a WP REST error body or null), `jsonRpc` (true when the body is a JSON-RPC
envelope), `toolCount` (int|null), `headerHint` (true when the response body is HTML). Nothing else.

### Diagnosis (PHP, `ConnectionDiagnosis`, pure)

`ConnectionDiagnosis::explain(array $probes, array $site): list<array{level,code,title,fix}>` where `$site` holds
server-side facts gathered by the AJAX handler: `permalinks_plain` (bool), `is_local` (bool), `scheme` (`http|https`),
`key_owner_ok` (bool, `PageAccess::keyOwnerCanUse`), `divi` (`DiviCompat::installedVersion()`), `rest_disabled_filter`
(bool, a `rest_authentication_errors` filter is attached that returns an error to anonymous requests).

Findings, first match wins per probe, `level` is `error|warning|ok`:

| Code | When | Plain-English title | Fix |
|---|---|---|---|
| `NETWORK` | `unauth.status` 0 | The test could not reach your site's address. | Check the site URL under Settings → General matches the address in your browser (www vs non-www, http vs https). |
| `PERMALINKS_PLAIN` | permalinks_plain, or unauth 404 | The REST address does not exist: your permalinks are set to "Plain". | Settings → Permalinks → choose any option except Plain, Save. |
| `NOT_FOUND` | unauth 404, permalinks fine | The plugin's address returned "not found". | Resave permalinks; check for a rewrite-rule or redirect plugin. |
| `REST_BLOCKED_JSON` | unauth/auth 401 or 403 with `jsonCode` in `rest_login_required`, `rest_cannot_access`, `rest_forbidden`, `rest_disabled` (and not our `unauthorized`/`forbidden`) | A plugin or setting blocks the REST API for visitors who are not logged in. | Allow `/wp-json/ai-editor-divi5/` in the security or "disable REST API" plugin. |
| `REST_BLOCKED_HTML` | status 403/406/429/503 and `headerHint` (HTML body) | A firewall or security plugin answered instead of WordPress (an HTML page, not a plugin reply). | Allow the path `/wp-json/ai-editor-divi5/` and POST requests with an Authorization header in the firewall (Wordfence, Cloudflare, Sucuri, host WAF). |
| `REDIRECTED` | `fetch` followed a redirect (`redirected` true) to another origin or scheme | Your site redirects the address (http to https or www to non-www) and assistants may drop the key on the redirect. | Put the exact final address in your assistant's setup. |
| `KEY_REJECTED` | `unauth` ok, `auth.status` 401/403 with our `jsonCode` `unauthorized`/`forbidden` and `key_owner_ok` true | The site received the key but refused it, or the host removed the Authorization header before WordPress saw it. | Press Regenerate and paste the new key; if it still fails, add the Authorization-header rule for Apache/CGI to `.htaccess` (the result shows the two lines). |
| `KEY_OWNER_CANNOT_EDIT` | `key_owner_ok` false, or `auth` 403 `forbidden` with message "cannot edit pages" | The user the key belongs to cannot edit pages. | Press Regenerate while logged in as an administrator. |
| `SERVER_ERROR` | any 5xx with our JSON | The plugin answered with an error. | Check the PHP error log; report it in the support forum. |
| `BAD_REPLY` | `auth` 200 but not JSON-RPC | The address answered with something that is not the plugin (a caching or maintenance page). | Exclude `/wp-json/` from page caching; turn off maintenance mode. |
| `NO_TOOLS` | `tools` 200, `toolCount` 0 | Connected, but no tools were listed. | Deactivate and reactivate the plugin. |
| `DIVI_MISSING` (warning) | `divi` null | Connected, but Divi 5 was not found, so editing tools will report nothing. | Activate the Divi 5 theme. |
| `DIVI_NEWER` (warning) | `DiviCompat::status` level `newer` | (existing message) | Informational. |
| `LOCAL_SITE` (warning) | is_local | This is a local site: only assistants on your own computer can reach it. | Link to the local-site note. |
| `HTTP_ONLY` (warning) | scheme http and not is_local | The site is not on HTTPS; ChatGPT needs HTTPS. | Install an SSL certificate. |
| `ALL_OK` (ok) | no error | Connected: the endpoint, the key and the permissions all work (N tools available). | One line: this test ran from your browser; if your assistant still cannot connect, a firewall may block its address. |

Errors suppress the warnings of lower stages; warnings never suppress `ALL_OK`.

### Wiring

- `AdminPage`: a "Test connection" button and a result region (`role="status"`, `aria-live="polite"`) inside the
  Connect card, below the last-request line. A localized config object carries `mcpUrl`, `ajaxUrl`, `nonce` and
  strings ("Testing…", "Run the test again"). Works without the page reloading; with JavaScript off the button is
  hidden.
- `admin-ajax` action `ai_editor_divi5_diagnose`: requires `manage_options` and a nonce; accepts the probe object as
  JSON in `probes`; validates every field's type and range (unknown keys dropped, never trusted); returns the findings.
  Output is escaped by the JS (text nodes only, no `innerHTML`).
- No changes to the REST/MCP/OpenAPI lockstep (this is an admin feature, not a tool; tool count stays 20).
- Readme: one sentence in the Settings description and a changelog line (version bump is done at release, not here).

## Testing

- `ConnectionDiagnosisTest` (PHPUnit): one case per code in the table, precedence (errors beat warnings, first match
  wins), unknown/garbled probe input degrades to `BAD_REPLY` or `NETWORK` without exceptions, translation-safe
  (every string passes through `__()`), no string contains the key or a URL with credentials.
- `DiagnoseAjaxTest`: input validation (types, huge strings truncated, missing nonce/capability rejected via the
  handler's pure `sanitizeProbes()`).
- `WpOrgComplianceTest` stays green unchanged (no remote functions added).
- Live gate `make verify-test-connection` (Docker dev site): runs the real probes' equivalent server-side against the
  REST handlers: healthy site -> ALL_OK; wrong key -> KEY_REJECTED shape; key owner demoted -> KEY_OWNER_CANNOT_EDIT;
  a `rest_authentication_errors` filter returning `rest_login_required` -> REST_BLOCKED_JSON; plain permalinks ->
  PERMALINKS_PLAIN. Self-cleaning (restores permalinks, key owner, filter).
- Browser check on the Docker site (built-in browser): click the button on a healthy site and with a deliberately
  wrong key in the DOM; screenshot both.

## Out of scope

Probing the AI vendor's network path; testing OAuth discovery (added in the connector spec, which extends the
probe list); automatic fixing of anything.
