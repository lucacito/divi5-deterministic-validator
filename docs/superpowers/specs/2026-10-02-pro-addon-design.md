# JHMG AI Editor for Divi 5 Pro — v1 design

Status: DRAFT for owner review · Date: 2026-10-02 · Target: Pro add-on 1.0.0 (separate plugin, sold and hosted at divi5lab.com).
Prerequisite: the free-plugin re-cut in "Phase 0" below (header/footer editing leaves the free plugin).

## Goal

Give people a reason to pay. The free plugin edits one page at a time and never touches site settings. The Pro add-on takes the jobs that cost agencies hours per site and owners effort on their own site:

- **Site control** the free plugin may never do (front page, menu, custom CSS, publishing, Theme Builder templates).
- **Scale with safety:** change something across the whole site in one previewed, undoable step.
- **Proof, not opinion:** a deterministic site audit built on the same validator that gates every free write.

Buyers: freelancers/agencies building many Divi 5 sites AND site owners maintaining their own (owner decision, 2026-10-02: "both equally"). v1 is package "C" of the brainstorm: core site control + site-wide find/replace with batch undo + audit.

## Hard constraints (inherited, non-negotiable)

- **The validator stays deterministic and untouched.** The add-on adds no schema knowledge and no AI call. Every write that saves Divi layout content is validated first.
- **No Divi schema from memory.** Anything that depends on a Divi attribute shape or Divi storage must trace to a real export or a live check on the real Divi (see Verification).
- **Extension contract.** The add-on attaches to the free plugin through the `jhmg_aied_*` hooks only (`docs/EXTENDING.md`). It cannot override, remove or edit a built-in tool (`ExtensionGuard`). It checks capabilities itself.
- **No PHP execution, ever.** `propose_php_snippet` stores a proposal for a human to review; nothing runs it. The add-on never writes users, plugins or Divi presets/colors/variables (the latter is a later spec).
- **Nothing licence-gated, remote-fetching or custom-code-saving may enter `wp-plugin/`.** That rule is about the WordPress.org plugin. The add-on is a different product; its licence gate and updater live only in the add-on.

## Decisions taken in brainstorming (2026-10-02)

| Decision | Choice |
|---|---|
| Buyer | Both agencies and owners |
| v1 scope | C: core site control + find/replace with batch undo + audit |
| How the add-on saves content | Calls the free plugin's own classes (`HistoryService`, `EditTarget`, `Validator`, `PageEditor`, `DryRun`, `StyleReferences`) behind a minimum-version check and a compatibility test; one shared undo history. Rejected: a separate write path and history table (splits undo, third validator copy). |
| Free features moved to Pro | Header/footer editing (4.3.0, never uploaded) only. Kept free: undo, `create_page`, guides/recipes/images, `dry_run`, `list_global_styles` + advisory. |
| Template delete | Left out of v1 (stays in Divi's UI) |
| Alt-text audit check | Left out of v1 (no real-export evidence, see Audit) |
| Usage quotas in the free plugin (e.g. "15 pages a month") | Rejected. WordPress.org Guideline 5 forbids trialware and quota-based restriction (it rejected the old single-build model on 2026-10-01), and `WpOrgComplianceTest` bans it. The free plugin stays unrestricted; value comes from what only Pro can do. |

## Architecture

A separate plugin, **JHMG AI Editor for Divi 5 Pro**: slug `jhmg-ai-editor-for-divi-5-pro`, namespace `AiEditorDivi5\Pro`, constants `AIED_PRO_*`, option and table prefix `aied_pro`. Source lives in `pro-addon/` (the staged files become the starting point and are re-tested and re-wired). Own `phpunit.xml`, bootstrap, `make test-pro` and `scripts/build-pro-zip.sh`; `make test` for the free plugin is unaffected.

It loads only when the free plugin is active and at or above the declared minimum version (the Phase 0 release). Otherwise it shows one admin notice and registers nothing.

Hooks used: `jhmg_aied_mcp_tools` + `jhmg_aied_mcp_call` (tools), `jhmg_aied_register_rest_routes` (REST), `jhmg_aied_openapi_paths` + `jhmg_aied_openapi_schemas` (ChatGPT spec), `jhmg_aied_admin_tabs` + `jhmg_aied_render_admin_tab` (the Pro tab). MCP, REST and OpenAPI stay in lockstep.

Every tool: checks its own capability (the free plugin only authenticates), type-checks arguments (non-strings and non-booleans are rejected, never cast), and returns the built-in response shapes (`docs/EXTENDING.md`).

## v1 tools (13)

The ChatGPT OpenAPI importer caps a spec at 30 operations. The free plugin has 16 tools after Phase 0, so Pro has 13 (29 of 30); a 14th Pro operation needs an explicit decision about the ChatGPT surface.

| # | Tool | What it does | Needs |
|---|---|---|---|
| 1 | `set_front_page` | Set the static front page (published page only) | `manage_options` |
| 2 | `set_primary_menu` | Build and assign the primary menu from pages (staged `MenuBuilder`) | `edit_theme_options` |
| 3 | `publish_page` | Publish or schedule a draft; `dry_run` | `publish_pages` + `edit_post` |
| 4 | `set_custom_css` | Managed Additional CSS block; never touches the person's own CSS (staged `CustomCss`) | `edit_css` |
| 5 | `propose_php_snippet` | Store PHP for human review; never executed (staged `PhpProposals`) | `edit_pages` |
| 6 | `list_theme_layouts` | Moved from free. With `layout_id`, also returns that layout's content | `edit_theme_options` + Divi's Theme Builder bar + `edit_post` |
| 7 | `update_theme_layout` | Replace a header/footer; validated, snapshotted; `dry_run` | `edit_theme_options` + Divi's Theme Builder bar + `edit_post` |
| 8 | `edit_theme_layout` | Find/replace inside a header/footer; `dry_run` | `edit_theme_options` + Divi's Theme Builder bar + `edit_post` |
| 9 | `manage_theme_template` | `assign` (where it applies), `enable`, `disable` an EXISTING live Theme Builder template; every real run needs a preview token (`create`/`duplicate` cut: Divi maps templates onto the Theme Builder post only inside its own save request, so they would need a raw meta write) | `edit_theme_options` + Divi's Theme Builder bar + `edit_post` |
| 10 | `find_replace_site` | Find/replace across pages and layouts; preview token required | `edit_pages` + `edit_post` per item |
| 11 | `audit_site` | Read-only deterministic scan | `edit_pages` |
| 12 | `list_batches` | Recent Pro changes | `edit_pages` |
| 13 | `undo_batch` | Undo one batch | as the original change |

"Theme Builder permission" is the rule the 4.3.0 code already implements: `edit_post` + `edit_others_posts` + Divi's Role Editor Theme Builder permission, failing closed. That code (`ThemeLayouts`, the layout half of `EditTarget`) moves into the add-on. Layout writes go through `HistoryService::write()` and the add-on then calls `DiviCompat::clearLayoutCaches()` (also moved).

## Batches and undo

- **Every Pro write is a batch** (a single layout edit is a batch of one). Storage: the add-on's own table `{prefix}aied_pro_batches`, created on activation and dropped on uninstall. A row: user, tool, time, summary, items. An item: the post or setting changed, the free history `snapshot` result (`stored`, `version_id`, `reason`; a write whose snapshot is not stored is recorded as having no undo point), the previous value for settings, and a hash of what the batch wrote. Last 20 batches kept; oldest trimmed first.
- **`undo_batch` is honest.** The free history keeps 10 snapshots per page, so a snapshot can be gone. Undo restores every item it can and reports the rest (`missing`, `no_undo_point`, `changed_since`). It claims "fully undone" only when every item restored.
- **Changed-since rule.** An item is restored only if its current content/value still matches the hash the batch wrote. A page edited afterwards is skipped and reported, never overwritten. A strict boolean `force` overrides this per call.
- **Undo is itself a batch.** The free `restore` snapshots the current content first, so an undo can be undone.
- **Settings tools** (1-4) store the previous value; undo restores it under the same changed-since rule. `publish_page` undo returns the page to its previous status.
- **Template tools:** `assign`, `enable` and `disable` record the template's previous full state and undo restores it under the changed-since rule. Written only through Divi's own `et_theme_builder_store_template()` with the template's complete current state (that function resets every field it is not given). Hard delete, create and duplicate are not offered in v1.

## `find_replace_site`

- Literal, case-sensitive, no regex. Reuses the free `PageEditor::apply()` exact-replace semantics per item so Pro cannot behave differently on escaped Divi content; saves go through `HistoryService::write()` (which already `wp_slash`es).
- Scope: `pages`, `layouts` or `all`; optionally `page_ids`. Statuses: publish, draft, pending, private; trash excluded. Layouts only when `EditTarget`-equivalent rules pass (live Theme Builder, permission).
- `dry_run` returns the per-item diff (via `DryRun`/`LayoutDiff`) and a token binding the arguments and each item's content hash. A real run requires that token; an item whose content changed since the preview is skipped and reported. Without a token the call is refused.
- An item whose result would fail the validator is skipped and reported; it never aborts the others and is never saved.
- Cap: 50 items per batch; a larger match set is refused with the count, not truncated.

## `audit_site`

Read-only; deterministic; no AI.

- Validator violations per page and layout (codes included).
- Unknown preset and color ids (the free `StyleReferences`).
- Internal links that point at no published post, or at a draft. Link strings are read from the content; **no remote request is ever made**.
- Pagination: 25 items per call with a cursor; findings capped at 200 per call, grouped by code.
- **Missing image alt text is NOT in v1.** The only `alt` shape in the real fixtures is the Menu module's logo; there is no Image-module evidence. It can be added once a real Image-module export is captured.

## Licensing and distribution

- Reuses the staged `Licensing` adapter and the canonical `LicenseClient` **including its updater** (allowed: the add-on is distributed from divi5lab.com, not WordPress.org). Server contract unchanged: `/api/license/{activate,validate,deactivate}` and the update check.
- Unlock: first successful activation unlocks Pro persistently. Only an explicit server verdict of `revoked` or `invalid` re-locks. Lapsed, expired or offline never locks; lapsed customers lose only updates and support.
- Locked: no Pro tool is registered; only the Pro admin tab (licence entry) appears.
- Remote calls: divi5lab.com only, for licence and updates (key, site URL, product); daily cache, 3-day grace; disclosed in the add-on's own readme.
- Admin: one "Pro" tab with Licence, the list of Pro tools, and Recent batches with an Undo button (nonce + capability, same changed-since rule).
- The free plugin keeps its one dismissible Dashboard card as the only mention of Pro.
- Out of scope: pricing, the divi5lab.com product page, the licence server.

## Phase 0 — free-plugin re-cut (its own small plan, ships before the add-on)

Header/footer editing leaves `wp-plugin/`. 4.3.0 was never uploaded, so this removes unreleased code only.

- Remove: `ThemeLayouts`; the layout half of `EditTarget` (page-only again); the `list_theme_layouts` MCP tool, `/theme-layouts` route and OpenAPI entry; Dashboard header/footer labels and the layout Restore button; `DiviCompat::clearLayoutCaches()` and its call in `HistoryService::afterWrite()`; the Features card; every readme mention (description, FAQ, changelog 4.3.0).
- Tests: `WpOrgComplianceTest` pinned tool list 17 → 16 (a justified edit of the list, no rule loosened; stated in the commit). The theme-layout unit tests and `make verify-theme-layouts` move to `pro-addon/`. `fixtures/valid/themebuilder-*.json` stay (they prove the validator, not the feature).
- Version: ships as 4.4.0 (4.3.0 never published, number skipped). Rebuild the zip, re-run Plugin Check on the dev copy and the zip, regenerate the Features screenshot, update CLAUDE.md (16 tools).

## Build order

0. Free-plugin re-cut. 1. Add-on skeleton (main file, min-version guard, licence, admin tab, harness, zip script). 2. Batch store + undo + settings tools (1-5). 3. Theme layout tools (6-8). 4. `manage_theme_template` (9). 5. `find_replace_site` + `audit_site` (10-11). 6. REST/OpenAPI parity, docs, live verification, review. The plan may split these into more than one implementation plan.

## Verification

Live, on the Docker dev site, as self-cleaning scripts in the style of the free plugin's `verify-*` (real handlers, Divi's own functions, remove exactly what they created):

- Settings tools: front page, menu, CSS, publish; each restored afterwards (menu creation is unproven today).
- Templates: assign, enable, disable and undo on a throwaway template, with Divi's own resolver proving the effect (`make verify-pro-theme-templates`, run 2026-10-03: passed).
- Batches: find/replace across throwaway pages and a layout, then undo, including a changed-since case.
- Audit over the real dev pages and the fixtures.

Unit tests for the pure logic (batch store, token binding, replace planner, audit checks, licensing); contract tests for the hooks (no built-in tool shadowed) and for the free-class compatibility; a `ProSafetyTest` that bans `eval`/exec-family functions, PHP execution of stored snippets and writes to users/presets, and allowlists remote hosts to divi5lab.com.

## Risks

1. Menu creation is unproven on a real site; a failed live check cuts that tool from v1 rather than shipping a guess. (Template create/duplicate were cut on source evidence; assign/enable/disable were proven live.)
2. The add-on depends on the free plugin's internal classes; the minimum-version check and compatibility test are the mitigation, not a guarantee.
3. 29 of 30 OpenAPI operations leaves one spare.
4. Free history keeps 10 snapshots per page, so a very old batch may be only partly undoable; the product says so.

## Out of scope (later specs)

Re-brand / global style writing, whole-site generation from a brief, WooCommerce template modules, hard-deleting templates, regex find/replace, an audit UI, remote link checking, image alt-text audit, pricing and the divi5lab.com product page.
