# Pro add-on 0.4.1: hardening of the 0.4.0 site tools (design)

Status: outline approved by the owner on 2026-10-03 ("go"); this file records it with the decisions made while the code was
built and tested in a scratch worktree. Plan: `docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md`.

## Goal

Close the deferred review items of Plans 4 and 5 (`docs/superpowers/plans/2026-10-03-pro-theme-builder-tools.md` and
`...-pro-site-tools-and-rest.md`, "As built") without adding a tool, a REST route or an OpenAPI operation (16 free + 13 Pro = 29
of the importer's 30 stay as they are) and without touching the free plugin or the validator.

## What changes

1. **`audit_site` windowing and cost.**
   - The cursor is the id of the last item audited. Pages and live layouts are put in ONE post-id order, and a call audits the next
     25 items with an id above the cursor, so a page created or deleted between two calls cannot shift the window. `cursor` stays
     an integer (0 = from the start), `next_cursor` is null at the end.
   - A link's target is looked up once per distinct path per call (menus and footers repeat the same links on every page); the
     site's known preset and colour ids are read once per call (`GlobalStyles::knownIds()`, then `StyleReferences::advise()` per
     item). Nothing is remembered between calls: the site may have changed.
   - A link to a post the user cannot `read_post` is still reported as `LINK_NOT_PUBLISHED` (visitors cannot open it either) but its
     status is shown as "not visible to you".
2. **Preview tokens (`find_replace_site`, `manage_theme_template`).** One signed token format (`PreviewToken` v2) for both: bound to the
   arguments, the user who previewed, the state it saw (item content hashes, or the template's state hash), 30 minutes, and a one-time
   nonce. The first real run spends the nonce (a transient); a token cannot be replayed, for example after `undo_batch` put the
   previewed content back. A refusal for another user does not spend it. Best effort: an object cache that evicts the transient can
   make a spent token usable again inside its 30 minutes (same user, same arguments); a content change since the preview is still caught by the changed-since check, a replay after an undo that restored the previewed content is not (every capability check and LayoutGate still run).
3. **REST.** A body that WordPress did not parse as JSON (sent as a form or as text/plain) is refused with 400 instead of running
   the tool with its defaults (broader than "tools with no required argument"). Malformed JSON under `application/json` is already
   refused by WordPress core.
4. **OpenAPI.** The operation summary is the first sentence (a full stop outside brackets, an ellipsis is not one), cut at a word
   boundary at 120 characters, never leaving an unclosed bracket or quote; 401 and 500 are documented on every operation.
5. **Small minors.** A no-change dry run of a layout write carries `valid` and `violations`; a Theme Builder template write that
   Divi accepts without changing the template is `changed:false` with no batch; the Compat guard test also catches a whole-namespace
   import, alias or group use of the free plugin; the six `verify-pro-*` scripts refuse a site whose host is not localhost,
   127.0.0.1, `*.local`, `*.test` or `*.localhost` (the dev site reports the environment type `production`, so the host is used).
6. **Release housekeeping.** Version 0.4.1 (main file, constant, `readme.txt`, test bootstrap), changelog, README sections ("Preview
   tokens", "Known limits"), the REST/OpenAPI parity rule and the 30-operation budget in `docs/EXTENDING.md`, `CLAUDE.md` Current state
   (the 0.4.0 bullet said "NOT merged"; it is merged at 6ce824d).

## Dropped (with the reason)

- The "vacuous checks" of the live scripts (Plan 5 review ledger): the ledger no longer exists, so the checks cannot be identified;
  the environment guard covers the real risk of those scripts.

## Not in scope

The free plugin (its dashboard fix is 4.5.0, separate), the validator, new tools, regex find/replace, an audit UI, remote link
checking, anything licence related.

## Verification

`make test-pro` (702 to 766 tests), `make test` (726, unchanged), and on the Docker dev site with the Pro add-on activated only for
the runs: `make verify-pro-site-tools`, `make verify-pro-rest`, `make verify-pro-theme-templates`; `make build-pro` produces a zip that
contains no tests or scripts. Opus reviews the token task and the whole branch.
