# JHMG AI Editor for Divi 5 — Claude Instructions

## What this project is

It started as a **deterministic, pure-PHP validator** for Divi 5 layout JSON —
proving the core bet: can we tell a valid Divi 5 layout from a broken one with
zero AI inference? That bet is proven, and the project has grown into the
product it was meant to power:

**JHMG AI Editor for Divi 5** (WordPress.org slug `jhmg-ai-editor-for-divi-5`) — a
**free** WordPress.org plugin that lets an AI assistant (Claude, Cursor, VS Code
Copilot, ChatGPT) read, edit and create (as drafts) Divi 5 pages in plain English
over MCP and a REST API. The deterministic validator is the **gate**: every write is
validated before it touches the database, so the AI can be creative but can never
save a broken layout.

Paid features live in a **separate Pro add-on** (a different plugin, sold and hosted
at divi5lab.com, not on WordPress.org). Its spec is
`docs/superpowers/specs/2026-10-02-pro-addon-design.md`; the skeleton is built in `pro-addon/`
and the tool plans follow. The old freemium-in-one-build model (licence-gated tools inside
the WP.org plugin) was **rejected by WordPress.org Guideline 5** in the 2026-10-01
review — do not reintroduce it.

Three parts, one repo:
- **The validator** (`src/`) — the deterministic core. Pure PHP, no dependencies,
  no AI. This is the sacred part.
- **The plugin** (`wp-plugin/`) — bundles a synced copy of the validator under
  `wp-plugin/validator/` and wraps it in the MCP server, REST API, AI-facing guides,
  the built-in image pack and the admin UI. No licensing, no remote calls.
- **The Pro add-on** (`pro-addon/`) — a separate plugin ("JHMG AI Editor for Divi 5 Pro", sold at divi5lab.com,
  never in the WordPress.org zip). 0.12.0: compat guard, licensing with the updater, a licence-gated tool
  registry with 21 tools (settings, batches and undo, Theme Builder header/footer and template tools, site-wide find/replace and audit, whole-site build, global colors/variables/presets, custom field values (`set_custom_fields`), simple WooCommerce products as drafts (`create_product`), changing existing WooCommerce products (`update_products`), bulk regular-price changes over a filter (`bulk_adjust_prices`)), REST for all of them and ChatGPT parity for the first 14, the Pro admin tab, its own suite (`make test-pro`) and zip (`make build-pro`). Not tested
  by `make test` (see `pro-addon/README.md`).
  `set_custom_fields` (Pro 0.9.0): sets custom field values on one post; keys the site knows only; all-or-nothing, `dry_run`, undoable; MCP + REST only (`ProOpenApi::MCP_REST_ONLY`); the free plugin stays read-only for fields (`get_custom_fields`).

## Hard constraints — do not violate

These still hold and are non-negotiable:

- **The validator is deterministic. No AI/LLM calls in it, ever.** Same input
  always yields the same verdict. The whole value proposition is that the gate
  is provable, not probabilistic.
- **No Divi schema from memory.** Divi 4 used shortcodes; Divi 5 uses Gutenberg
  block JSON. All schema knowledge in `SchemaRules.php` must come from real
  exports (`make export-layouts`, documented in `docs/SCHEMA.md`) **or from the
  shipped Divi theme's own `module.json` definitions, in either case verified by
  a live render on the real Divi version** (`make verify-modules`; evidence in
  `docs/module-verification-*.json`). `src/VerifiedModules.php` is generated
  from that evidence — never hand-edit it. Never invent block types or
  attribute shapes. Rendering proves a module exists and renders, not that the
  builder allows a placement; only placements supported by real-export
  precedent (column) are accepted.
- **Section recipes and style/landing guides are grounded in real exports too.**
  The AI-facing guides (`StyleGuide`, `SectionRecipes`, `SiteGuide`,
  `LandingGuide`) may teach *strategy and composition* freely, but any concrete
  Divi markup or attribute shape they hand the AI must trace to a real export
  and stay valid — every recipe is re-validated in the test suite.
- **Keep the two validator copies in sync.** `src/` is canonical;
  `wp-plugin/validator/` is the bundled copy. A change to one must be mirrored.

> Note: the original brief said "no admin UI, no MCP server, no SaaS, no
> licensing." That was the validator-MVP boundary and is **no longer the
> project's scope** — the admin UI and MCP server now exist by design in
> `wp-plugin/`; licensing exists only in the separate Pro add-on, never in
> `wp-plugin/` (see "WordPress.org compliance rule" below). The constraint that
> survived is narrower and sharper: the *validator core* stays pure and
> deterministic. New scope is fine; compromising the gate is not.

## Architecture: how generation is steered

The plugin does not generate layouts in code — it **steers the AI** with guides
served over MCP/REST, then validates the result. The guides are pure, testable
PHP classes in `wp-plugin/src/`:

| Guide / tool | Teaches the AI… |
|---|---|
| `get_style_guide` (`StyleGuide`) | how to make a layout *valid + styled* (real attribute shapes) |
| `get_landing_guide` (`LandingGuide`) | how to make a single page *convert* (persuasion flow, copy, CTA strategy) |
| `get_site_guide` (`SiteGuide`) | how to plan a *multi-page site* with cross-links (the owner sets the menu/front page) |
| `get_image_guide` (`ImageGuide`) | which image fits each section: Media Library first, then the built-in pack |
| `get_section_recipes` (`SectionRecipes` + `data/section-recipes.json`) | 17 proven, validated section patterns, each mapped to a persuasion stage |

**21 MCP tools, all free:** `html_to_divi` (4.10.0, deterministic HTML to Divi 5 conversion, see below), `list_divi_pages`, `get_page_layout`,
`list_global_styles` (read-only site presets and colors, `GlobalStyles`), `get_site_structure` (read-only: post types, taxonomies, image sizes, ACF field groups, and a `custom_fields` section of the custom field keys the site's posts use, sampled, keys only; `SiteStructure`; MCP and REST only), `get_custom_fields` (read-only: one post's non-protected custom field values; any public post type the user can edit, Divi Projects included; `CustomFieldReader` + `FieldTarget`; MCP and REST only), `validate_layout`, `update_page_layout` (optional `title` since 4.11.0: renames a page or Project, see "Page title"), `edit_page_content` (surgical find-and-replace), `create_page`
(always a draft), `list_page_history`, `get_page_history_entry`,
`restore_page_version`, `list_media_images` (read-only Media Library listing,
`MediaLibrary` + `MediaService`, needs `upload_files` + per-item `read_post`), `list_products` and `get_product` (read-only WooCommerce catalogue, 4.9.0: `ProductService` + `ProductQuery` + `ProductReader` + `WooProductGateway`, the only class that calls WooCommerce; needs `edit_products` + `edit_others_products` for the list and `edit_post` per product; MCP and REST only, a normal "not active" answer without WooCommerce) and the
five guide tools above. Write tools run the validator and reject anything invalid
with exact violation codes so the AI self-corrects.

`html_to_divi` (4.10.0; spec `docs/superpowers/specs/2026-10-14-html-to-divi-design.md`; MCP + REST only, `HtmlToDiviLockstepTest`): supplied HTML in, a Divi 5 layout and an honest report out, no network and no AI. `HtmlSource` (DOMDocument LIBXML_NONET, drops script/style/forms/iframes/svg/handlers/unsafe URLs and reports them), `HtmlMapper` (sections, rows/columns for runs of similar cards, modules), `HtmlProse` (allow-listed text HTML), `DiviMarkup` (the ONLY place that writes blocks; every attribute path grounded in a real export, pinned by `DiviMarkupTest`; rows/columns use the shapes Divi's own builder writes so they stack on phones), `HtmlImages` (third-party images become built-in placeholders), `HtmlToDivi` + `HtmlToDiviService` (preview by default, `create=true` saves one DRAFT through `DraftPage`, the same inserter as `create_page`). Checks: `text_check` and `links` prove no copy or link was dropped; 8,000 random hostile pages over 40 seeds pass (`AIED_SEED=n vendor/bin/phpunit --filter Random`); goldens in `tests/golden/html-to-divi` (regenerate with `AIED_UPDATE_GOLDEN=1`). Styles (colors, fonts) are NOT converted yet (phase 2).

Test connection (4.10.0): the Settings screen's button probes the MCP address from the admin's browser (the plugin may not make HTTP requests) and `ConnectionDiagnosis` (pure) turns small facts into plain-English findings; "Connected" needs positive evidence from all three probes.

Divi Projects (v4.8.0, unreleased): `EditTarget::ALLOWED_TYPES = ['page','project']`, so every page tool and route also works on a Project's ID (`page_id` keeps its name); `list_divi_pages` / `GET /pages` take an optional `post_type` (page or project); `create_page` stays page-only; blog posts and every other type stay refused.

**OAuth sign-in for Claude on the web** (4.10.0, opt-in, option `ai_editor_divi5_oauth_enabled`, default off; spec `docs/superpowers/specs/2026-10-14-claude-connector-oauth-design.md`): the plugin is its own OAuth 2.1 authorization server so claude.ai custom connectors work without Node.js. Dynamic Client Registration only (CIMD is not advertised: it would need a remote fetch, banned in `wp-plugin/`), PKCE S256, single-use codes, rotating refresh tokens with family revocation on replay, audience-bound access tokens (`aied_at_` prefix), exact redirect URIs (claude.ai/claude.com callback, loopback for native clients; extra hosts only through `jhmg_aied_oauth_redirect_hosts`), a consent page naming the app and the redirect host. Units `OAuth*.php` (pure logic + `OAuthStore` the only queries + `OAuthRoutes` glue); `ApiKey::authenticateRequest()` has ONE extra branch for `aied_at_` tokens; an invalid OAuth-shaped token is a 401 with `WWW-Authenticate` (a 403 would stop Claude refreshing). Off = a complete no-op (no routes, no well-known, no header, no table). Verified live by a scripted Claude-like client (`make verify-oauth`); a real claude.ai run needs a public https site and is the owner's check.

**Authorisation** (`PageAccess`): an accepted API key still requires its user to have
`edit_pages` (a demoted/deleted key owner is refused with 403); `list_divi_pages`
requires `edit_pages` and only lists pages the user can `edit_post`; every page
read/write re-checks `edit_post`. String arguments are type-checked (non-strings → 400 /
-32602), never cast.

**Images:** `wp-plugin/assets/images/` is an original, generated pack of 44 SVGs
(`scripts/generate-image-pack.php`, byte-deterministic; `manifest.json`). Recipes
store images as `{{aied:image:<token>}}`; `ImageTokens` resolves them to site-local
URLs when served (`ImagePack` reads the manifest), so no token and no third-party
image host ever reaches the AI.

**Extension hooks** (`jhmg_aied_*`, guarded by `ExtensionGuard` so built-ins always
win): the only way the Pro add-on extends the plugin. Reference: `docs/EXTENDING.md`.

Undo (v3.5.0, table storage since 4.1.0): `HistoryService` is the single write path for AI page-content
writes (`update_page_layout`, `edit_page_content`, REST PUT/edit): it snapshots the
previous content before saving, one row per snapshot in the table
`{prefix}ai_editor_divi5_history` (`HistoryTable` = schema/hooks, `HistoryStore` = the only
queries, `HistoryMigration` = import of 4.0.0's `_aied_history` post meta; 4.1.0): up to the
last 10 per page, each <= 512 KB, trimmed oldest-first to a ~768 KB per-page budget
(`PageHistory::MAX_TOTAL_BYTES`; the newest is always kept). A snapshot can be
skipped (`history.reason`: `too_large` | `invalid_encoding` | `store_failed` |
`duplicate`), so undo is available only when `history.stored` is true. Restore
bypasses the validator gate by design (it brings back the person's own earlier
content; the result reports whether it passes) and snapshots the current content
first, so a restore is itself undoable when its own `history.stored` is true. Only
plugin edits are snapshotted, not Divi-builder edits; `create_page` has nothing to undo.

Dry run (v4.2.0): `update_page_layout`, `edit_page_content`, `PUT /pages/{id}` and
`POST /pages/{id}/edit` accept a strict boolean `dry_run` (a non-boolean is rejected 400 /
-32602, never read as false). It runs the same checks, then returns `DryRun::preview()`
(validator verdict + `LayoutDiff` diff: max 50 changes, 25 attribute diffs per block, values
cut to 200 characters, `builderVersion` ignored) BEFORE `HistoryService::write()` is reachable;
the page, its history and `post_modified` are never touched. A preview of an invalid layout
is a normal 200 / `isError: false` response carrying `valid: false`. Logged as endpoint
`dry_run`, result `read`. It is a flag, not a tool: the list stayed at 15 tools / 15 operations in 4.2.0 (4.4.0 adds the read-only `list_global_styles`: 16).

Approval mode (v4.6.0): option ai_editor_divi5_require_preview (default off, Settings card). When on, update_page_layout, edit_page_content, PUT /pages/{id} and POST /pages/{id}/edit refuse a save (MCP isError / REST 428, code PREVIEW_REQUIRED) unless the same resulting content was previewed validly by the same user for the same page in the last 30 minutes (ApprovalMode: single-use transient). create_page, restore and every Pro tool are exempt; the check sits in the four handlers, NOT in HistoryService (Pro writes through it).

Page title (v4.11.0; spec `docs/superpowers/specs/2026-10-14-change-page-title-design.md`, plan `docs/superpowers/plans/2026-10-14-change-page-title.md`): `update_page_layout` and `PUT /pages/{id}` take an optional strict-string `title` (`PageTitle`: trimmed, 1-200 characters, one line, no `<` `>`, no direction-control characters; a failure is 400 / -32602, never cast). `post_content` absent or null + `title` = title-only (the layout is not read, validated, snapshotted or written; `HistoryService::writeTitle` passes only `ID` and `post_title` and restores the stored content inside core's `wp_insert_post_data` so a user without `unfiltered_html` cannot have the layout re-filtered by a rename; result `layout_changed:false`, `history.reason:"title_only"`). `post_content: ""` is still refused; neither field is refused. `edit_page_content` / `POST /pages/{id}/edit` do NOT take a title (D1). `TitleChange::plan` is the one pure planner both transports use (changed/unchanged, report, approval subject/base, message). A title change is NOT undoable (design (b), spec D4): results carry `title:{changed,before,after,restorable:false}` plus the old title in `message`, `restore_page_version` results carry `title_restored:false` + `title_note`, and nobody stores titles in the history table (no migration). `dry_run` shows old and new title (`DryRun::previewTitle`); approval mode binds the preview to the title (`ApprovalMode::withTitle`, key unchanged for calls without a title). Slugs are out of scope (never touched; live-proven). Verified live on Divi 5.14: a `divi/post-title` module follows a rename; menu behaviour is NOT verified and the guide makes no menu claim. `title.after` is the re-read stored title (core stores `&` as `&amp;` for users without `unfiltered_html`). No new tool, route or OpenAPI operation (importer budget untouched); `HistoryService::write()` and `DryRun::preview()` only gained optional trailing parameters, and the Pro add-on's `FreeApiCompatTest` allows exactly that (and only for those two methods). Live gate `make verify-page-title`.

Theme layouts (Pro add-on): editing the existing LIVE Theme Builder header and footer (`et_header_layout` /
`et_footer_layout`) was built as 4.3.0 and then MOVED to the Pro add-on before it was ever uploaded (2026-10-02;
`docs/superpowers/specs/2026-10-02-pro-addon-design.md`). The free plugin has NO header/footer or Theme Builder code
(`tests/NoThemeLayoutsInFreeTest.php` enforces it); `EditTarget::resolve()` accepts pages and Divi Projects only (`EditTarget::ALLOWED_TYPES`). The source is parked in
`pro-addon/staged-4.3.0/`. Verified 2026-10-02 on Divi 5.14: a REAL builder-made header (menu, search, social follow),
footer (text, button, social follow, code) and body layout all pass the validator (`fixtures/valid/themebuilder-*.json`);
that proof stays here because it proves the validator.

Global styles (v4.4.0): `list_global_styles` (MCP / `GET /global-styles` / OpenAPI `listGlobalStyles`, read-only)
lists the site's own Divi 5 module presets, global colors and variables, read ONLY through Divi's own public
read functions (`GlobalPreset::get_data()`, `GlobalData::get_global_colors()` / `get_global_variables()`, each behind
`class_exists`/`is_callable` guards; a missing or throwing reader means an empty section plus a note) by `GlobalStyles`.
`StyleReferences` adds a non-blocking `advisories.style_references` to `validate_layout`, `update_page_layout`,
`edit_page_content`, `create_page` and every `dry_run` preview (and the REST equivalents) when a layout cites a preset id
or `gcid-` color id the site does not have: Divi silently falls back to the module's default preset for an unknown preset
and leaves an unknown color undefined (live-render proof, 2026-10-01). It never changes `valid`, never blocks a save and
never rewrites content; a refused save carries violations only. The free plugin NEVER writes presets, colors or
variables (`tests/WpOrgComplianceTest.php` bans Divi's save functions and the storage names); non-color variables are
listed but never taught (no real export uses them). The validator stays site-agnostic. Spec:
`docs/superpowers/specs/2026-10-01-global-styles-design.md`.

Same logic is exposed three ways, kept in lockstep: MCP (`McpHandler`), REST
(`RestController`), and the ChatGPT OpenAPI spec (`OpenApiSpec`). Add or change a
tool → update all three.
Exception (owner decision 2026-10-04): get_site_structure and get_custom_fields are MCP + REST only, because the ChatGPT importer allows 30 operations and the free (16) plus Pro (14) tools already use all 30; `SiteStructureLockstepTest` and `CustomFieldsLockstepTest` pin the absence on purpose.

## WordPress.org compliance rule

**Nothing licence-gated, remote-fetching or custom-code-saving may enter
`wp-plugin/`.** No licence checks or "upgrade"/"premium" gating, no
`wp_remote_*`/cURL/remote `file_get_contents`, no third-party image hosts, no tools
that save CSS/PHP or change site settings (front page, menus, users, plugins). The
only Pro mention allowed in the plugin UI is the one dismissible Dashboard card in
`AdminPage::proCard()`. `tests/WpOrgComplianceTest.php` enforces all of this (plus
the URL-host allowlist, the 18-tool list, a ban on meta-write functions in `wp-plugin/` and the readme's claims, including the
"more than N Divi 5 block types" number checked against `SchemaRules`). Never weaken
that test to make it pass — fix the plugin, or add a justified explicit exception.

## Entry points

Docker env + validator workflows go through `make`:

| Command | What it does |
|---|---|
| `make up` | Bootstrap WordPress + Divi env (idempotent) |
| `make test` | Run the PHPUnit suite — the green-light check |
| `make validate FILE=x` | Validate an arbitrary layout file |
| `make export-layouts` | Capture real Divi 5 JSON into `fixtures/valid/` |
| `make schema-gap` | List Divi modules (from `divi/Divi.zip`) the validator doesn't know |
| `make verify-modules` | Re-verify the already-promoted modules and probe the still-missing ones on the real Divi (Docker); writes evidence, but refuses to overwrite committed evidence with a result set that loses or demotes modules |
| `make verify-history` | Real-database check of the undo-history table on the Docker site (creates and deletes one throwaway draft page) |
| `make verify-dry-run` | Real-site check of `dry_run` on the REST and MCP handlers (creates and deletes one throwaway draft page) |
| `make verify-global-styles` | Real-site check of `list_global_styles` and the style-reference advisory on the REST and MCP handlers; proves presets, global colors and variables are byte-identical afterwards (creates and deletes one throwaway draft page) |
| `make test-pro` | Run the Pro add-on's own PHPUnit suite (`pro-addon/phpunit.xml`) |
| `make build-pro` | Build `dist/jhmg-ai-editor-for-divi-5-pro.zip` (sold from divi5lab.com; `dist/` is git-ignored) |
| `make verify-custom-fields` | Real-site check of `get_custom_fields`, the `custom_fields` discovery and the plain-meta render (self-cleaning; dev site only) |
| `make verify-site-structure` | Real-site check of `get_site_structure`, approval mode and the MCP protocol fixes on the REST and MCP handlers (creates and deletes throwaway draft pages; restores the approval-mode setting) |
| `make verify-project-layouts` | Real-site check of editing Divi Project layouts (self-cleaning; dev site only) |
| `make verify-page-title` | Real-site check of changing an existing page's title through `update_page_layout` / `PUT /pages/{id}` on the real REST and MCP handlers: title-only and combined saves, slug/status/layout untouched, the "title is not restorable" note on restore, strict inputs, dry run, approval mode, permissions, what core stores for a user without `unfiltered_html`, and what a dynamic `divi/post-title` module renders before and after a rename; creates throwaway pages, a Project and a user, always deletes them and restores approval mode and the API key's user; dev site only |
| `make verify-oauth` | Real-site check of the OAuth sign-in: `OAuthStore` against the reference behaviour on the real table, then the whole flow over HTTP as claude.ai does it (discovery at the real web-server root, DCR, consent with a logged-in session, PKCE, tokens, refresh, rotation, replay, permissions following the user, revocation, switch off); switches OAuth on and off and creates throwaway users; dev site only |
| `make verify-test-connection` | Real-site check of the Test connection diagnosis on the real MCP handler (healthy, wrong key, a key owner who cannot edit, REST closed by core's own gate, plain permalinks); everything restored |
| `node scripts/verify-settings-ui.cjs` | Real-browser check of the Settings screen: Test connection healthy / wrong key / with OAuth on, and turning the Claude sign-in on and off through the UI (leaves it off) |
| `make verify-html-to-divi` | Real-site check of `html_to_divi`: every fixture saved as a throwaway draft through the real handlers, read back, validated and rendered by the real Divi; MCP parity; permissions; everything deleted |
| `make verify-guide-shapes` | Proves on the real Divi, in a real browser, the per-device and equal-size-image shapes the guides teach and that the converter's rows stack on phones (evidence: `docs/guide-shapes-evidence-5.14.md`); every page deleted |
| `make verify-products` | Real-site check of `list_products` / `get_product` (totals, filters, sorting, the four product types, privacy sweep, MCP parity, an editor refused) on the dev site's WooCommerce products, then the same tools with WooCommerce deactivated (always reactivated); throwaway products and a user always deleted, the existing products byte-identical afterwards; dev site only |
| `make verify-pro-skeleton` | Real-site check of the Pro add-on skeleton (Docker site; the free and Pro plugins must be active) |
| `make verify-pro-settings` | Real-site check of the Pro settings tools and batch undo on the Docker site; restores every setting and deletes everything it created (the free and Pro plugins must be active) |
| `make verify-pro-theme-layouts` | Real-site check of the Pro header/footer tools through the real registry (self-cleaning; free + Pro plugins active; dev site only) |
| `make verify-pro-theme-templates` | Real-site evidence gate for manage_theme_template (self-cleaning; dev site only) |
| `make verify-pro-site-tools` | Real-site check of find_replace_site and audit_site through the real registry (self-cleaning; dev site only: it evicts old batch rows) |
| `make verify-pro-rest` | Real-site check of the Pro REST routes and the live OpenAPI spec (read-only) |
| `make verify-pro-custom-fields` | Real-site check of set_custom_fields (self-cleaning; dev site only; free + Pro active; ACF checks run when the free ACF plugin is active) |
| `make verify-pro-create-product` | Real-site check of create_product with the real WooProductWriter: read-back through the free get_product, refusals, rollback, a hook throwing inside the insert, undo, editor/shop-manager permissions; originals byte-identical (self-cleaning; dev site only; free + Pro + WooCommerce active; evicts old batch rows) |
| `make verify-pro-update-products` | Real-site check of update_products with the real WooProductWriter: preview/token, prices and sale window, stock, texts, terms, images, variation rows (parent price follows), skip of a product changed since the preview, rollback on a failing or lying writer, one undo (and undo of the undo, and a field edited since), shop-manager kses, refusals; originals byte-identical (self-cleaning; dev site only; free + Pro + WooCommerce active; evicts old batch rows) |
| `make verify-pro-bulk-adjust-prices` | Real-site check of bulk_adjust_prices with the real WooProductWriter and WooCommerce's own catalogue query: preview/token, percent and amount changes with the shop's rounding and round_to, the filter (category, draft status, on_sale, ids, the 100-product cap), skip reasons, variable products (parent price follows), a product changed since the preview, a new match left alone, rollback on a failing or lying writer, one undo (and undo refused with conflicts), editor/shop-manager permissions; originals byte-identical (self-cleaning; dev site only; free + Pro + WooCommerce active; evicts every batch row on the dev site) |
| `make verify-pro-build-site` | Real-site check of `build_site` (build, launch, anonymous menu render, undo, abandon; self-cleaning; dev site only: it evicts old batch rows) |
| `make verify-pro-licence-tiers` | Real-site check of the Pro tier display, site limit and expiry against a LOCAL layoutlab licence server (`STAGE=limit|agency|expired|cleanup LICENCE_BASE=http://host.docker.internal:3010`; self-cleaning; dev site only; see the script header) |
| `make clean` | Destroy volumes (prompts for confirmation) |

Tests run via PHPUnit (`phpunit.xml`) and cover both the validator
(`Divi5Validator\*`) and the plugin's pure classes (`AiEditorDivi5\WP\*`), the
latter against light WP shims in `tests/bootstrap.php` — no WordPress install
needed. `make test` exits 0 = everything is proven; non-zero = something is
broken or incomplete.

## The plugin build

The installable plugin is `jhmg-ai-editor-for-divi-5.zip` at the repo root, built by
`bash scripts/build-plugin-zip.sh`: a clean archive of `wp-plugin/`'s contents under
a top-level `jhmg-ai-editor-for-divi-5/` folder (folder = slug = Text Domain; main
file `wp-plugin/jhmg-ai-editor-for-divi-5.php`; no macOS temp junk). Rebuild it after
changing anything under `wp-plugin/` so the distributable stays current. Bump the
version in `wp-plugin/jhmg-ai-editor-for-divi-5.php` (header + `AI_EDITOR_DIVI5_VERSION`)
and `wp-plugin/readme.txt` (Stable tag + Changelog) on every release. `Tested up to`
belongs only in readme.txt. Run Plugin Check (Docker env) on the dev copy and on the
extracted zip before any upload; never `wp plugin delete` a test copy (it runs
uninstall.php against the shared dev DB) — remove its folder instead.

Rename facts: display name **JHMG AI Editor for Divi 5** everywhere the product is
named (the WP admin menu label stays the short "AI Editor"); Contributors:
`lucaslopvet`. Internal identifiers keep the old names on purpose: namespace
`AiEditorDivi5\WP`, `AI_EDITOR_DIVI5_*` constants, `ai_editor_divi5_*` options,
REST namespace/menu slug/MCP server name `ai-editor-divi5`.

`wporg-assets/` holds the WordPress.org directory art (icon, banner, 3 screenshots),
regenerated by the dev-only `scripts/build-wporg-art.cjs` (masks the API key and URL
in the DOM before capturing). It is not part of the plugin zip.

## The one manual blocker

`divi/Divi.zip` (commercial Divi 5 theme) must be placed by the user for the
Docker env and `make export-layouts`. Do not attempt to download it. If missing,
`make up` stops with exact instructions.

## Current state

- **Free 4.11.0 is PUBLISHED on WordPress.org (2026-10-14; SVN trunk r3747011, tag 4.11.0 r3747013; the API shows 4.11.0 and the downloaded zip is byte-identical to the tracked zip; merged to main c7696b3 and pushed)**: the AI can change the title of an existing page or Divi Project (`title` on `update_page_layout` / `PUT /pages/{id}`; see "Page title (v4.11.0)" above), alone or with the layout, with `dry_run`, approval mode and an honest "not undoable" report; the guides teach page title vs headline. 21 tools, 16 OpenAPI operations (unchanged). Free suite 1757, Pro suite 2165, `make verify-page-title` 75 PASS, Plugin Check clean on the dev copy and the extracted zip. The final whole-branch review caught two real issues before release (Pro `FreeApiCompatTest` pinned exact signatures; a title-only `wp_update_post` re-ran kses over the stored layout for users without `unfiltered_html`), both fixed and re-verified live. Not done and why: slugs (a separate owner decision: links, redirects), undo for titles (design (b), by choice, see spec D4), menu behaviour (not verified, no claim made), an `edit_page_content` title (D1), a Pro change (none needed). Owner-only: rotate the SVN password that was given in chat for this release (it is not stored anywhere).
- **Free 4.10.0 is PUBLISHED on WordPress.org (2026-10-14; SVN trunk r3745149, tag 4.10.0 r3745152; the API and the download show 4.10.0; merged to main 54f67d8 and pushed)**: OAuth sign-in for Claude on the web (opt-in), a Test connection button, `html_to_divi` (21st free tool), guide corrections proven on the real Divi (headline per device, equal-size images, columns that stack on phones), readme/FAQ updates. Free tools 20 -> 21; the ChatGPT importer budget is untouched (`html_to_divi` is MCP + REST only, `HtmlToDiviLockstepTest`). Pro add-on unchanged (0.12.0). Not done and why: Codex and Antigravity connection docs (the CLIs are not installed here, so nothing can be claimed tested), the Extra-theme answer (no verifiable source), a Pro structured-data (JSON-LD) tool (specced only: `docs/superpowers/specs/2026-10-14-structured-data-design.md`, it changes the front end of customer sites and needs the owner's decisions), `html_to_divi` styles (phase 2). Owner-only: the setup video, WordPress.org forum subscriptions, the divi5lab.com About-page line, a real claude.ai check of the connector on a public https site.
- **Released 2026-10-13: free plugin 4.9.0 on WordPress.org (SVN trunk r3745081, tag 4.9.0 r3745082) and Pro add-on 0.12.0 in the divi5lab.com store** (the store offers 0.12.0 to older versions; Pro `Compat::MIN_FREE` is now 4.9.0, released after the free version was live). They carry the four features below (free 20 tools, Pro 21; the divi5lab.com site copy was updated the same day). Release routine unchanged: sync SVN trunk from the tracked zip, commit, `svn copy trunk tags/<version>`, THEN the Pro release (`layoutlab/scripts/release-aied-pro.sh`, dry run first, own `CHANGELOG` env). The SVN password is never stored; ask the owner each time.
- **Pro 0.12.0 (released) bulk_adjust_prices** (sub-project B, third tool): raises or lowers the REGULAR prices of the products a filter matches (categories, tags, types, stock status, on sale, name search, ids; published only by default) by a percent or an amount with exact decimal arithmetic, the shop's rounding and an optional "end in" (round_to); preview with every price before/after and a single-use PreviewToken over the matched list and per-product state hashes (a product changed since is skipped, a new match is left alone); products that cannot be adjusted are skipped with a reason (a variable product as a whole); at most 100 products / 400 prices per call; the apply, read-back, rollback and batch code is the shared ProductUpdateRun that update_products also uses; undo is the existing product_fields kind; MCP + REST only (ProOpenApi::MCP_REST_ONLY); never touches sale prices, status or anything else; spec/plan docs/superpowers/specs|plans/2026-10-13-pro-bulk-adjust-prices*; live gate make verify-pro-bulk-adjust-prices.
- **Pro 0.11.0 (released in 0.12.0) update_products** (sub-project B, second tool): changes existing simple/external/variable WooCommerce products (prices and sale window, stock, texts, SKU, terms, images, size, variation rows) behind a before/after preview and a single-use PreviewToken (per-product state hash; a product changed since is skipped), read back, rolled back on any failure, one undoable batch (`product_fields` kind: previous values of only the changed fields, plus the whole stock group (quantity, status, backorders, low stock threshold) where manage_stock changes); MCP + REST only (ProOpenApi::MCP_REST_ONLY); never changes status/type/slug, never creates or deletes; spec/plan docs/superpowers/specs|plans/2026-10-13-pro-update-products*; live gate make verify-pro-update-products. bulk_adjust_prices is built (first bullet above).
- **Pro 0.10.0 (released in 0.12.0) create_product** (sub-project B, first tool): up to 25 simple WooCommerce products per call as drafts, all or nothing, read-back verified, undoable (product_created / product_trashed kinds); MCP + REST only (ProOpenApi::MCP_REST_ONLY); spec/plan docs/superpowers/specs|plans/2026-10-13-pro-create-product*; live gate make verify-pro-create-product. A failed save deletes only a post provably inserted by that save (`InsertedProduct`); honest residual: a foreign draft with exactly the same name, inserted by another hook during our save when our own insert never happens, is deleted and the tool says "Nothing was created." update_products is built (next bullet up); bulk_adjust_prices is built (first bullet above).
- Free 4.9.0 (released) read-only WooCommerce products (list_products, get_product, counts in get_site_structure); spec/plan docs/superpowers/specs|plans/2026-10-13-woocommerce-products-read*; Pro writes are sub-project B: create_product is built on branch feat/pro-create-product (see the bullet above; the merged state is updated by the owner at release); update_products is built on branch feat/pro-update-products; bulk_adjust_prices is built on branch feat/pro-bulk-adjust-prices (first bullet above).
- **Free plugin 4.8.0 is PUBLISHED on WordPress.org and Pro add-on 0.9.0 is RELEASED in the divi5lab.com store (2026-10-12)**: free SVN trunk r3741502, tag 4.8.0 r3741504 (4.7.0 went live 2026-10-07; 4.7.1 never existed, its fix was folded into 4.8.0). 4.8.0 = Divi Project layouts (`EditTarget::ALLOWED_TYPES = ['page','project']`, every page tool/route works on a Project; `list_divi_pages` takes `post_type`), read-only `get_custom_fields` (18th free tool, MCP+REST only) plus a keys-only `custom_fields` section in `get_site_structure`, and the Email Optin fix (`divi/signup-custom-field` accepted inside `divi/signup`: the harness now probes children of already-known parents, `SchemaGap::childCandidates`, evidence in `docs/module-verification-5.14.json`, generated `VerifiedModules::KNOWN_PARENT_CHILDREN`). Pro 0.9.0 = `set_custom_fields` (18th Pro tool, MCP+REST only; `ProOpenApi::MCP_REST_ONLY`), key `0` refused, requires free 4.8.0 (`Compat::MIN_FREE`). The ChatGPT importer budget (30 operations) stays used up by the first 14 Pro tools plus 16 free. Spec/plan: `docs/superpowers/specs|plans/2026-10-07-get-custom-fields*`, `2026-10-08-project-layouts*`, `2026-10-09-set-custom-fields*`. Release routine: sync trunk from the tracked zip, commit, `svn copy trunk tags/<version>`, THEN the Pro release (`layoutlab/scripts/release-aied-pro.sh`, dry run first, own `CHANGELOG` env) because the Pro compat guard needs the free version live. The SVN password is never stored; ask the owner each time.
- **Free plugin 4.7.0 (2026-10-07)**: number and text global variables taught in the style guide, ACF/custom-field binding, approval mode (4.6.0), `get_site_structure` (4.6.0, 17th tool at the time). #9 and #10 (modules from Divi Pixel/Plus and other add-on plugins) are CLOSED as won't-do (owner decision 2026-10-06): the limitation stays and is in the readme FAQ.
- **Free plugin 4.5.0 is PUBLISHED on WordPress.org** (2026-10-04; SVN `https://plugins.svn.wordpress.org/jhmg-ai-editor-for-divi-5`, user `lucaslopvet`, revisions r3727421..r3727426, tags 4.4.0 and 4.5.0; the directory page and API show 4.5.0, the icon, both banners and 3 screenshots). Release routine: sync trunk from the tracked zip, commit, `svn copy trunk tags/<version>`. The SVN password is never stored in the repo or in memory; ask the owner each time.
- **Pro pricing tiers (Pro add-on 0.6.0, branch `feat/pro-0-6-0-tiers`; spec/plan `docs/superpowers/plans/2026-10-04-pricing-tiers.md`)**: annual licences priced by site count (Personal / Freelancer / Agency, a founding offer for the first buyers, a capped Lifetime, prorated upgrades, renewal reminders 30 and 7 days ahead). EVERY price, tier name, site limit and cap lives in ONE file, `layoutlab/config/pricing.json` (the divi5lab.com store repo); the add-on carries NO price and shows only what the licence server returns (`LicenseExtras`: tier, sites used/allowed, founding, lifetime, upgrade and manage URLs). Enforcement is SERVER-side only (the add-on is GPL): the site cap is checked at activate and validate in the store (`lib/license-server`), the founding and lifetime caps at checkout (plus a Stripe coupon redemption limit), proration by Stripe. An expired, cancelled or past-due licence never disables any Pro tool (only `revoked`/`invalid` lock; `ExpiryKeepsAllToolsTest`); one dismissible `LapseNotice` replaces the shared client's notice. The no-card 45-day trial is Personal only and never takes the founding coupon. The shared licence client is untouched. Draft page copy: `docs/pricing-page.md` (price tokens, competitor facts dated October 2026, `TODO(owner)` markers).
- **Pro add-on 0.5.0** (whole-site generation; merged to LOCAL main 749dc86; NOT released, NOT pushed): the 14th and LAST Pro tool
  `build_site` (16 free + 14 Pro = 30 of the importer's 30 operations: the budget is used up). `add_page` creates ONE validated DRAFT
  page per call (invalid layout or taken slug: refused with the reasons, the build keeps its valid pages) and appends it to ONE growing
  batch (status `building`; one open build per user; `BatchRepository::append`/`openBuild`, `BatchLog::open`/`append`/`close`; an open build is
  never trimmed); `launch` (dry_run + `PreviewToken`) publishes, sets the flagged front page and rebuilds the live Main Menu, skipping
  any page that is gone, changed since the preview, or whose slug was taken; `undo_batch` takes the whole build away (item kinds
  `page_created` -> trash, inverse `page_trashed` -> untrash as a draft). Spike facts that shaped it (live, WP 7.1): a menu item
  pointing at a draft shows a DEAD link to visitors (so the menu is built only at launch), drafts get no unique slug (clash resolved at
  publish: refused at add_page), untrash returns a draft. Spec `docs/superpowers/specs/2026-10-03-build-site-design.md`; plan
  `docs/superpowers/plans/2026-10-03-build-site.md`; `make test-pro` 875 tests, `make test` 727; live gates `make verify-pro-build-site` (44 PASS, incl. the real-database build lock, an open build surviving the batch trim, and undo of an all-edited open build)
  and `make verify-pro-rest` (30 operations) passed on the Docker dev site. Whole-branch review fixes: a real `undo_batch` always closes an open build; `force` never trashes a page that is no longer a draft nor deletes an edited one when `EMPTY_TRASH_DAYS` is 0; `add_page` refuses `in_menu`/`is_front` the user could never launch (default `in_menu` follows `edit_theme_options`); the launch re-reads each page's status right before publishing. Known limits (README): `undo_batch` takes no build lock, an abandoned build never expires, the 15 s lock expiry can be exceeded by a very slow call.
- **Pro add-on 0.4.1** (hardening; merged to LOCAL main da340e3; NOT released, NOT pushed): preview tokens are bound to the user,
  single-use (a transient marks a spent nonce) and 30 minutes for BOTH `find_replace_site` and `manage_theme_template`
  (`PreviewToken` v2); `audit_site` pages by the id of the last audited item (pages and layouts in one id order), memoises link
  lookups and the site's known style ids per call and hides the status of a linked post the user cannot `read_post`; REST
  refuses a body WordPress did not parse as JSON (400); OpenAPI summaries are cut at a word, never inside brackets, and 401/500
  are documented; no-change layout dry runs carry `valid`/`violations`; a template write that changes nothing reports
  `changed:false`; the `verify-pro-*` scripts refuse non-local sites (`scripts/dev-guard.php`). Plan:
  `docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md`. `make test-pro` 766 tests, `make test` 726; live gates passed on the Docker dev site (Divi 5.14, Pro activated only for the runs): `make verify-pro-site-tools` 49, `make verify-pro-rest` 21, `make verify-pro-theme-templates` 60 PASS, 0 FAIL.
- **Pro add-on 0.4.0** (merged to LOCAL main 6ce824d; NOT released, NOT pushed): `find_replace_site` (literal;
  Divi 5 pages, the live header/footer only with scope and the Theme Builder permission; ONE batch of at most 50 items; a signed
  30-minute preview token carries the id => content-hash map so an item changed since the preview, or dropped out of it, is skipped and reported; every result
  through `LayoutGate::check($new, $stored)`, an invalid one skipped, never saved), `audit_site` (read-only: layout violations
  including stored custom code, unknown preset/color ids via the free advisory, internal links to nothing or to unpublished posts
  read from the content, 25 items per call with a cursor, no remote request; `scope=layouts` needs `edit_theme_options` and Divi's Theme Builder permission; an item with 200 or more links gets a LINKS_NOT_ALL_CHECKED finding; the link check reads links relative to the host
  root, so it works on subdirectory installs, and skips file and WordPress paths), `ToolRunner` (one call path for MCP and REST),
  REST (`POST /wp-json/ai-editor-divi5/v1/pro/<tool>`, auth = the free plugin's `require_edit_posts` plus the tool's own
  capability) and ChatGPT OpenAPI parity generated from the tool list (16 free + 13 Pro = 29 of the importer's 30 operations);
  the plan `docs/superpowers/plans/2026-10-03-pro-site-tools-and-rest.md`; verified by `make test-pro` (702 tests),
  `make test` (726), `make verify-pro-site-tools`, `make verify-pro-rest`; known limit: the audit's link check cannot tell a
  missing page from an archive or custom route (the finding says so).
- **Pro add-on 0.3.0** (merged to LOCAL main d20c22d; NOT released, NOT pushed): four Theme Builder tools
  (`list_theme_layouts`, `update_theme_layout`, `edit_theme_layout`, `manage_theme_template` = assign/enable/disable of an
  EXISTING live template; create/duplicate were cut because Divi maps templates onto the Theme Builder post only inside its own
  AJAX save, so they would need a raw meta write). Registry capability `edit_theme_options` plus Divi's bar (`edit_others_posts` +
  Role Editor `theme_builder`) plus `edit_post` on the target, re-checked by the item kinds at undo. Layout writes go through
  `LayoutGate::check($new, $stored)`, the free `HistoryService`, and a Divi cache clear; templates only through
  `et_theme_builder_store_template()` with the template's complete state; every template run needs a preview token. Plan:
  `docs/superpowers/plans/2026-10-03-pro-theme-builder-tools.md`. Verified: `make test-pro` (571), `make test` (726),
  `make verify-pro-theme-layouts`, `make verify-pro-theme-templates` (real Divi 5.14).
- **Pro add-on 0.2.0** (merged to LOCAL main dd929d6; NOT released, NOT pushed): licence hardening
  (only an explicit server verdict can lock; refresh skips ajax/cron and backs off 15 minutes after a fault; the API-base
  override only on local/dev sites; activation failures say why; `readme.txt` discloses the divi5lab.com calls), the batch
  store (`{prefix}aied_pro_batches`, last 20) with honest undo (`BatchUndo`: changed-since rule, per-item capability,
  "fully undone" only when every item restored) and 7 MCP tools: `set_front_page`, `set_primary_menu`, `publish_page`,
  `set_custom_css`, `propose_php_snippet`, `list_batches`, `undo_batch`, plus the "Recent Pro changes" and "Code proposals"
  panels on the Pro tab. MCP + admin only at that version (REST/OpenAPI came with 0.4.0). Plan:
  `docs/superpowers/plans/2026-10-02-pro-addon-settings-batches.md`. Verified: `make test-pro`, `make test`,
  `make verify-pro-settings`. Before the first Pro sale: an end-to-end activation and update check against the real
  divi5lab.com (product id `ai-editor-divi5-pro` and `/plugins/divi-5-ai-editor` were confirmed against the layoutlab repo on
  2026-10-02), and confirm the licence server's `validate` answers have the shapes `Licensing::isVerdict` expects.
- **WordPress.org review answered 2026-10-02** (issue: custom CSS/JS/PHP through layouts, `css.freeForm`/`mainElement`):
  fixed and merged to LOCAL main (9cb7a06; NOT pushed) (`CodePolicy` + `LayoutGate`, every write/preview path, guides/recipes cleaned,
  `NoCustomCodeTest`); the WordPress.org review was answered and the plugin is APPROVED and published (see the first bullet).
  Rule: the free plugin never saves custom CSS, JavaScript or PHP, and any future layout write path (including every Pro
  write tool) must go through `LayoutGate::check()`.
  Style-value guard scope is a named list (decoration/advanced + listed families), not a proof of completeness; add new
  CSS-bearing families when found.
- **4.5.0 = dashboard recent-edits fix** (published to WordPress.org 2026-10-04): `HistoryService::recent()` now keeps only rows
  `EditTarget::resolve()` accepts (the Restore handler's own rule), filtered BEFORE the limit (it scans the newest 200
  rows), so header/footer edits made by the Pro add-on and deleted or non-editable pages no longer show a Restore button
  that always fails. A "use the Pro tab" label was rejected: the only Pro mention allowed in the free UI is the one
  dismissible card. 727 tests. The tracked `jhmg-ai-editor-for-divi-5.zip` is now the 4.5.0 build; the uploaded 4.4.0 build is commit 583a54c.
- **4.4.0 = global styles release PLUS the free re-cut** (merged to LOCAL main, 026a571; published to WordPress.org 2026-10-04): read-only
  `list_global_styles` (16th tool) + non-blocking style-reference advisory + guide updates (spec
  `docs/superpowers/specs/2026-10-01-global-styles-design.md`). The free re-cut removed the unreleased 4.3.0
  header/footer code (it moves to the Pro add-on), so there are 16 tools. Ships after the first WordPress.org
  approval. Verified: 548 tests, `make verify-global-styles` 25/25, Plugin Check clean (dev copy and zip), final
  whole-branch review of the re-cut done. The Features screenshot in `wporg-assets/` was regenerated 2026-10-02 after
  the re-cut (9 cards, no header/footer card).
- **4.2.0 = preview-before-save release** (branch `feat/dry-run-diff`): `dry_run` on the two write
  tools/routes with a capped structured diff; spec `docs/superpowers/specs/2026-10-01-dry-run-diff-design.md`.
  Not uploaded; ships in one upload together with 4.1.0 after WordPress.org answers the 4.0.0 review.
- **4.1.0 = history storage release** (branch `feat/history-table`, merged to local main, not uploaded):
  undo history moved from post meta to its own table with automatic migration; spec
  `docs/superpowers/specs/2026-10-01-history-table-storage-design.md`. Upload only after
  WordPress.org answers the 4.0.0 review (or fold into a change-request upload).
- **4.0.0 = WordPress.org compliance release** (branch `feat/wporg-compliance`):
  renamed plugin, licensing and Pro-only tools removed (staged in `pro-addon/`),
  `create_page` + undo free, `jhmg_aied_*` hooks, built-in image pack + tokens,
  `list_media_images`, one dismissible Pro add-on card. Plugin Check clean on the
  dev copy and the zip. The owner replies in the WP.org review thread (draft:
  `docs/wporg-review-reply-draft.md`, includes the slug request) and uploads the
  zip — nothing is uploaded or sent without the owner.
- Validator: knows 89 Divi 5 block types (`SchemaRules`), including the
  render-verified Divi 5.14 modules (docs/module-verification-5.14.json); modules
  that could not be verified stay rejected and are listed in docs/SCHEMA.md.
- The old standalone `mcp-server/` (Node) was removed in 3.3.0: the plugin's
  built-in HTTP MCP endpoint is the only supported connection path.
- Roadmap after approval: the Pro add-on (own spec/plan), then WooCommerce template modules via a real export or class map.
- Header/footer are the active theme's (nav menu drives the header).
