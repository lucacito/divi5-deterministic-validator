# Claude web connector: OAuth sign-in for the MCP endpoint (free plugin)

Date: 2026-10-14. Status: approved by the owner's delegation ("write the specs, approve them").
Source: backlog item "Claude web connector (no desktop app). Find out what claude.ai custom connectors require
(likely OAuth) and support it. This removes the Claude Desktop, Node.js and old-Mac problems in one go."

## What claude.ai requires (verified 2026-10-14)

Sources: claude.com/docs/connectors/custom/remote-mcp, claude.com/docs/connectors/building/authentication,
modelcontextprotocol.io/specification/2025-11-25/basic/authorization.

- A custom connector is added by URL (Customize > Connectors > Add custom connector) on Free (one), Pro, Max, Team
  and Enterprise. The same OAuth client backs claude.ai, Claude Desktop, mobile and Cowork. Claude Code is a separate
  native client with its own loopback flow.
- Auth types Claude supports: OAuth with Dynamic Client Registration (DCR), OAuth with a Client ID Metadata Document
  (CIMD), none, and static request headers (**beta, limited organizations only**, so it cannot be our general answer).
- The server must answer an unauthenticated MCP request with **401** and `WWW-Authenticate: Bearer
  resource_metadata="<https url>"` (Claude ignores the header on a 200). `resource_metadata` may be ANY https URL.
- The protected-resource document's `resource` must equal the MCP URL exactly as typed; `authorization_servers[0]` is
  the only entry used.
- The authorization server must publish metadata at the RFC 8414 well-known path or the OpenID Connect path, and must
  be reachable from Anthropic's egress range (`160.79.104.0/21`). It must advertise `code_challenge_methods_supported:
  ["S256"]`. PKCE S256 is sent on every request. A `resource` parameter (RFC 8707) is sent on authorize and token.
- Hosted-app redirect URI: exactly `https://claude.ai/api/mcp/auth_callback`. Claude Code: loopback
  `http://localhost:<any port>/callback` and `http://127.0.0.1:<any port>/callback` (port-agnostic match).
- Token endpoint: must accept `application/x-www-form-urlencoded`; discovery, registration and token endpoints must
  answer within 10 s (refresh 30 s); refresh tokens must rotate for public clients and an invalid one must return
  `invalid_grant`. Claude appends `offline_access` when the metadata lists it in `scopes_supported`.
- CIMD is used only if metadata advertises `client_id_metadata_document_supported: true` and `none` in
  `token_endpoint_auth_methods_supported`. Otherwise Claude falls back to DCR.
- The consent screen must show the redirect URI's host clearly (MCP spec), with an extra warning when only loopback
  redirects are registered.

## Goal and non-goals

Goal: a site owner on any Claude plan pastes ONE URL into claude.ai (Customize > Connectors), signs in to their own
WordPress site, clicks Approve, and Claude can use the plugin's tools. No Node.js, no desktop config file, no API key
pasted into a third party. The existing Bearer API key keeps working unchanged for Claude Code, Cursor, Gemini, VS Code,
ChatGPT and the REST API.

Non-goals: CIMD (requires the server to fetch a URL: `wp_remote_*` is banned in `wp-plugin/`; `WpOrgComplianceTest` must
not be weakened; Claude falls back to DCR), `client_credentials`, SSO assertions, scopes beyond one, ChatGPT OAuth (not
verified), multisite network-wide tokens, subdirectory installs (see Limits).

## Design

**Opt-in.** Option `ai_editor_divi5_oauth_enabled` (default `0`). When off, the plugin behaves exactly as 4.9.0: no
well-known responses, no `WWW-Authenticate` header, no OAuth routes (they return 404). Enabled from the Claude panel on
the Settings screen with one button; disabled again with one button, which also revokes every OAuth grant.

The plugin is both the resource server (the MCP endpoint) and its own authorization server, same origin, **no new
dependency** (about 600 lines of PHP, no Composer library).

### Units (all in `wp-plugin/src/`, each pure where possible)

| Unit | Responsibility |
|---|---|
| `OAuthConfig` | Pure: canonical URLs (issuer = `untrailingslashit(home_url())`; resource = the MCP route URL exactly; authorize URL = `admin-post.php?action=aied_oauth_authorize`; token, register, resource-metadata REST URLs), TTLs (code 10 min, access 1 h, refresh 30 days idle, rotating), limits. |
| `OAuthMetadata` | Pure: builds the protected-resource document and the authorization-server document (`issuer`, `authorization_endpoint`, `token_endpoint`, `registration_endpoint`, `response_types_supported:["code"]`, `grant_types_supported:["authorization_code","refresh_token"]`, `code_challenge_methods_supported:["S256"]`, `token_endpoint_auth_methods_supported:["none"]`, `scopes_supported:["aied:edit","offline_access"]`, NO CIMD flag). |
| `OAuthWellKnown` | Serves the metadata at `/.well-known/oauth-authorization-server[/<path>]` and `/.well-known/openid-configuration[/<path>]` and `/.well-known/oauth-protected-resource[/<mcp path>]`. Hooks `parse_request` (so no rewrite flush is needed and it also works before rewrite rules load) and matches the request path relative to the site's home path, answering with JSON and `Cache-Control: no-store` for a root install; for a subdirectory install it also answers the path-appending OIDC form `<home>/.well-known/openid-configuration`. Pure `match(string $path, string $homePath): ?string` is unit-tested. |
| `OAuthClients` (store-backed) | RFC 7591 registration: validates `redirect_uris` (each exactly an allowed https URL or a loopback http URL with the port ignored when comparing; host allow-list defaults to `claude.ai` and `claude.com`, extendable only through the `jhmg_aied_oauth_redirect_hosts` filter, which is guarded by `ExtensionGuard` so the built-in hosts always stay), `client_name` (text, 80 chars), `token_endpoint_auth_method` forced to `none`, `grant_types`/`response_types` checked. Cap 100 registered clients; the oldest client with no live grant is pruned first; a registration that cannot fit returns `temporarily_unavailable`. Throttle: 20 registrations per hour per IP-less global counter (transient). |
| `OAuthAuthorize` | The consent step. `admin-post.php?action=aied_oauth_authorize` (both `admin_post_` and `admin_post_nopriv_`). Validates `response_type=code`, registered `client_id`, exact `redirect_uri`, `code_challenge` (S256 only, 43-128 chars), `resource` (if present, equals the MCP URL), `state` passed through untouched. Not logged in: redirect to `wp_login_url()` with `redirect_to` set to the same authorize URL. Logged in without `edit_pages`: a plain-English refusal page. Logged in with it: a minimal HTML consent page (no theme, no scripts) showing the client name, the redirect URI host in bold, a loopback warning when applicable, the signed-in user, and exactly what is granted ("read and edit your Divi 5 pages and ask for a preview first, as <user>"). Approve and Deny are POSTs with a nonce bound to a hash of the request. Approve issues a single-use code bound to client, user, redirect_uri, code_challenge, resource and time. Deny redirects with `error=access_denied` + `state`. Errors that cannot be safely redirected (bad client or redirect_uri) render the page and never redirect. |
| `OAuthTokenEndpoint` | REST `POST /oauth/token`, `permission_callback => __return_true` (public by design), accepts form-urlencoded (WordPress parses it) and JSON. `authorization_code`: verifies the code (unexpired, unused, same client, same redirect_uri, `S256(code_verifier) == code_challenge` with `hash_equals`, `resource` equal if sent), marks it used, issues an access token and a refresh token. Replaying a used code revokes every token issued from it. `refresh_token`: verifies, **rotates** (old refresh token invalid immediately, new pair returned); a replayed rotated token revokes the family; an invalid/expired one returns `invalid_grant`. Errors follow RFC 6749 (`invalid_request`, `invalid_client`, `invalid_grant`, `unsupported_grant_type`), JSON, `Cache-Control: no-store`. Per-client throttle on failures. |
| `OAuthStore` | The only queries. Table `{prefix}ai_editor_divi5_oauth` (created like `HistoryTable`, version-gated, `dbDelta`): `id`, `kind` (`client`,`code`,`access`,`refresh`), `token_hash` char(64) unique (SHA-256 of the secret; **secrets are never stored**), `client_id`, `user_id`, `family` (grant id), `data` (JSON: redirect_uris, name, challenge, resource…), `expires_at`, `used_at`, `created_at`. Expired rows are purged on write. Interface `OAuthStoreInterface` so the logic is tested against an in-memory fake. |
| `OAuthTokens` | Pure orchestration over the store: issue, verify, rotate, revoke by family/client/user/all, `authenticate(string $bearer): ?int` returning the user id. Tokens are 32 random bytes (`random_bytes`), base64url, prefixed `aied_at_` / `aied_rt_` / `aied_ac_` so they are recognisable and never confused with the API key. |
| `ApiKey::authenticateRequest()` (existing) | Extended in one place: a Bearer that starts with `aied_at_` is verified by `OAuthTokens` (sets the current user to the grant's user, and still requires `edit_pages` via `PageAccess::keyUserAllowed`); any other Bearer goes through the existing key check, unchanged. Both `McpHandler::authenticate()` and `RestController::require_edit_posts()` already call it, so MCP, REST and OpenAPI routes all accept OAuth tokens. |
| `OAuthChallenge` | Adds `WWW-Authenticate: Bearer resource_metadata="<resource-metadata url>", scope="aied:edit"` to every 401 response of the plugin's REST namespace when OAuth is enabled (`rest_post_dispatch` filter; a `WP_Error` 401 included). Without OAuth enabled nothing is added. |
| `OAuthAdmin` | The Claude panel (Settings): enable/disable, the URL to paste (the MCP URL, one Copy button), 4 plain steps for claude.ai, a list of connected apps (client name, user, connected, last used) each with Revoke, and a "Revoke all" button; handlers via `admin-post` with nonces and `manage_options`. |

### Security properties (each pinned by a test)

- PKCE S256 mandatory, `plain` rejected; the verifier compared with `hash_equals`.
- Codes are single use and expire in 10 minutes; reuse revokes the grant family.
- Redirect URIs are compared exactly (loopback ports ignored only for loopback hosts); no wildcard; fragments refused;
  `javascript:`/`data:` and non-https non-loopback refused at registration.
- An access token only works for the plugin's own routes (audience check: token `resource` is the plugin's MCP URL;
  tokens issued for another resource value are never accepted).
- The user behind a token is re-checked on every request (`edit_pages`, plus each tool's own checks), so demoting or
  deleting the user disables the grant immediately. Tokens never outlive the user.
- Secrets hashed at rest; nothing secret in logs, in the usage table or in the Test connection output.
- Approve is a nonce-protected POST by a logged-in user with a fresh session; no clickjacking: the consent page sends
  `X-Frame-Options: DENY` and `Content-Security-Policy: frame-ancestors 'none'`.
- Open-redirect safe: never redirects to a URI that was not registered for the client.
- Disabling OAuth deletes every client, code and token (and `uninstall.php` drops the table).
- Existing protections stay: write tools still run the validator gate, approval mode, history and undo.

### Test connection (extends the other spec)

`ConnectionDiagnosis` gains OAuth findings when OAuth is enabled: `OAUTH_WELLKNOWN_BLOCKED` (the browser fetch of the
well-known document returned HTML or a 4xx: a security plugin, an nginx dotfile rule or a subdirectory install),
`OAUTH_NO_HTTPS` (Claude can only reach an https site), `OAUTH_METADATA_MISMATCH` (issuer or resource differs from the
URL actually in use: www vs non-www), `OAUTH_OK`. The probe list gains: GET the authorization-server document, GET the
protected-resource document, unauthenticated MCP POST returns 401 **with** a `WWW-Authenticate` header carrying
`resource_metadata` (read through `Access-Control-Expose-Headers` is unnecessary because the probe is same-origin).

### Limits (documented, shown in the panel when detected)

- Needs a public HTTPS address (Claude calls from Anthropic's cloud). A local site cannot use the Claude web connector;
  Claude Code / Cursor / Gemini keep working locally with the key.
- Subdirectory installs: only the OIDC path-appending form is served; if Claude does not use it, the panel says OAuth may
  not work on this install. Verified only for root installs.
- nginx configs that deny `/.` paths, and security plugins that block unknown `.well-known` paths, must allow
  `/.well-known/oauth-*` and `/.well-known/openid-configuration` (the Test connection result says so).
- Claude's CIMD is not supported (Claude uses DCR instead); each connection registers a client, which the 100-client cap
  prunes.
- Real claude.ai cannot be driven from this repo's CI. Verification is (a) unit tests of every unit, (b) a scripted
  "Claude-like client" against the real Docker site that performs discovery, DCR, authorize, token, MCP call, refresh,
  rotation and revocation exactly in the order Claude documents, (c) the owner's final manual check on a public HTTPS
  site (steps in the readme and in the panel). The report states this plainly.

## WordPress.org compliance

No remote calls, no new dependency, no licence code. The readme documents the feature in plain English (a section on
what signing in grants, how to revoke, that it is off until enabled). `tests/WpOrgComplianceTest.php` stays unchanged;
new tests assert the OAuth classes contain no remote function. The privacy section states that Claude (Anthropic) sends
requests to the site once connected, as with any assistant.

## Testing plan

- Unit (PHPUnit, in-memory store): metadata documents (all fields, no CIMD flag); well-known path matching (root,
  subdirectory, trailing slashes, case, path-aware PRM, anything else ignored); registration validation (allowed and
  refused redirect URIs, caps, forced auth method); authorize validation matrix (every missing/wrong parameter);
  consent page escaping (XSS in `client_name`, `state`, redirect URI); PKCE (S256 pass, wrong verifier, plain refused,
  length bounds); code single use and replay revocation; refresh rotation, replay revocation, expiry,
  `invalid_grant`; audience mismatch; user demoted; `authenticate()` leaves the API key path untouched; `WWW-Authenticate`
  only when enabled; disable deletes everything; secrets never in storage or output; `ApiKey` regression tests.
- Lockstep: MCP/REST/OpenAPI tool lists unchanged (OAuth is auth, not a tool); the OpenAPI spec's security scheme stays
  the Bearer key.
- Live gate `make verify-oauth` (Docker site): HTTP-level run against the real web server: well-known documents reachable
  at the real root URL (proves the Apache path is not swallowed), 401 header on an unauthenticated MCP POST, DCR, a
  logged-in authorize with an Approve (cookie jar login via wp-cli generated session), token, `tools/list` with the
  token, a write blocked for a user without `edit_pages`, refresh + rotation + replay, revoke, disabled state is byte
  identical to today. Self-cleaning (option, table rows, user, cookies).
- Browser check of the consent page and the Claude panel in the built-in browser (screenshots).
- Plugin Check clean on the dev copy and the built zip.
