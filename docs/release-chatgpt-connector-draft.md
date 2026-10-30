# ChatGPT connector: release wording (2026-10-29)

Shipped as free 4.16.0 on 2026-10-29: the readme edits below were applied, version bumped, zip rebuilt. The owner-side tasks at the end still apply (the divi5lab.com guide was rewritten in the same session).

## Why

OpenAI is retiring Custom GPTs (reported: announced 2026-09-11, retirement 2026-12-11; the source is a developer's
relay, OpenAI's own help page returned 403 to us). A GPT can be migrated to a "plugin", but Actions do not carry over:
they must be rebuilt as a supported connector or a custom MCP server. Our ChatGPT route was the Action (OpenAPI), so
users are being told to "create a plugin". The replacement is a custom MCP connector, which per OpenAI's
[authentication docs](https://developers.openai.com/plugins/build/auth) signs in with OAuth (ChatGPT cannot send a
custom API key).

## What the branch does

- `OAuthRedirectPolicy::HOSTED` accepts ChatGPT's redirects: `https://chatgpt.com/connector_platform_oauth_redirect`
  and `https://chatgpt.com/connector/oauth/{id}` (id: letters, digits, `-`, `_`, 1-64). https, port 443 only. Without
  this ChatGPT's client registration was refused.
- We do not advertise RFC 9207 `authorization_response_iss_parameter_supported`, so ChatGPT uses the callback-id
  redirect (OpenAI: "Callback-ID-specific (otherwise)"). Both forms are accepted.
- Settings screen: the ChatGPT tab leads with the connector (turn on sign-in, Developer mode, paste the MCP address,
  OAuth, Allow); the Action steps stay in a collapsed "Older route" block. The sign-in card now says "Claude on the
  web, ChatGPT, and other apps that sign in" and its button "Turn on sign-in for Claude and ChatGPT".
- `make verify-oauth-chatgpt`: scripted client following OpenAI's documented flow, 31 checks, passes on Divi 5.15.1 /
  local Docker. `scripts/verify-settings-ui.cjs` updated for the button label and passes.

## What is NOT proven

Nothing here has been run against a real ChatGPT. That needs a paid ChatGPT plan with Developer mode and a public
https address; the owner has neither. Until someone confirms it, every public sentence says "not yet confirmed".

Also unverified: the exact ChatGPT menu names (the Settings text says they change), whether a plan below Plus can use
custom connectors, whether write tools work in Developer mode on a personal plan, and `private_key_jwt` client
authentication (not supported; we only support public clients with PKCE, which OpenAI lists as an option).

## `readme.txt` edits to make at release

1. Line "ChatGPT, through a custom GPT Action (OpenAPI)" becomes:
   `* ChatGPT, through a custom connector that signs in with your WordPress login (needs Developer mode on a paid ChatGPT plan; set up for OpenAI's published requirements but not yet confirmed by us), or, while OpenAI still allows it, a custom GPT Action (OpenAPI)`
2. Requirements line "For ChatGPT: your site must be reachable over public HTTPS, and creating a custom GPT needs a paid ChatGPT plan" becomes:
   `For ChatGPT: your site must be reachable over public HTTPS, you must turn on the sign-in in Settings, and custom connectors need a paid ChatGPT plan with Developer mode.`
3. The sentences "(available over MCP and the REST API, not in the ChatGPT Action)" stay true for the Action; add once
   in the description: `Through a connector, ChatGPT gets every tool; the older custom GPT Action offers fewer.`
4. Changelog line: `* Improved: ChatGPT can now connect as a custom connector, which signs in with your WordPress login. OpenAI is retiring custom GPTs, and their Actions do not carry over. The Settings screen explains the new steps and keeps the old ones for GPTs you already built. We have followed OpenAI's published requirements but have not yet been able to test with a ChatGPT account.`
5. Settings screenshot caption mentions "ChatGPT": fine as is.

## Owner-side tasks (not in the repo)

- divi5lab.com guide `connect-chatgpt-to-divi-5` (linked from the ChatGPT tab) still describes the GPT Action. Rewrite it
  for the connector, and mark it untested until someone confirms.
- Pro add-on: `pro-addon/readme.txt`, `pro-addon/README.md` and `ProOpenApi` talk about "ChatGPT parity" through the
  OpenAPI spec. Through the connector every Pro tool is reachable over MCP (the 30-operation importer limit no longer
  applies), so that wording needs a pass at the next Pro release. `docs/launch/*` and `docs/pricing-page.md` mention
  ChatGPT too.
- Ask for a tester: a member of the Facebook group with a paid ChatGPT plan can try the connector on a public https
  site and report back. A positive report is what lets us drop "not yet confirmed".
- Dec 2026: once custom GPTs are gone, remove the Action route (`OpenApiSpec`, `specUrl`, the "Older route" block, the
  ChatGPT parts of the Pro OpenAPI) in a later release; the 30-operation budget rule in CLAUDE.md goes with it.

## Open decisions

- Whether to also advertise RFC 9207 `iss` so ChatGPT uses the stable redirect. Not needed for correctness; skipped
  to keep the change small.
- CIMD (Client ID Metadata Documents) is OpenAI's preferred registration. We do not advertise it: it needs a remote
  fetch, which `wp-plugin/` may not make (WordPress.org compliance). DCR is documented as supported.
