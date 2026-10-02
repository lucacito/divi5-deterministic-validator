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
  never in the WordPress.org zip). Skeleton 0.1.0: compat guard, licensing with the updater, a licence-gated tool
  registry (no tools yet), the Pro admin tab, its own suite (`make test-pro`) and zip (`make build-pro`). Not tested
  by `make test` (see `pro-addon/README.md`).

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

**16 MCP tools, all free:** `list_divi_pages`, `get_page_layout`,
`list_global_styles` (read-only site presets and colors, `GlobalStyles`), `validate_layout`, `update_page_layout`, `edit_page_content` (surgical find-and-replace), `create_page`
(always a draft), `list_page_history`, `get_page_history_entry`,
`restore_page_version`, `list_media_images` (read-only Media Library listing,
`MediaLibrary` + `MediaService`, needs `upload_files` + per-item `read_post`) and the
five guide tools above. Write tools run the validator and reject anything invalid
with exact violation codes so the AI self-corrects.

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

Theme layouts (Pro add-on): editing the existing LIVE Theme Builder header and footer (`et_header_layout` /
`et_footer_layout`) was built as 4.3.0 and then MOVED to the Pro add-on before it was ever uploaded (2026-10-02;
`docs/superpowers/specs/2026-10-02-pro-addon-design.md`). The free plugin has NO header/footer or Theme Builder code
(`tests/NoThemeLayoutsInFreeTest.php` enforces it); `EditTarget::resolve()` is page-only. The source is parked in
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

## WordPress.org compliance rule

**Nothing licence-gated, remote-fetching or custom-code-saving may enter
`wp-plugin/`.** No licence checks or "upgrade"/"premium" gating, no
`wp_remote_*`/cURL/remote `file_get_contents`, no third-party image hosts, no tools
that save CSS/PHP or change site settings (front page, menus, users, plugins). The
only Pro mention allowed in the plugin UI is the one dismissible Dashboard card in
`AdminPage::proCard()`. `tests/WpOrgComplianceTest.php` enforces all of this (plus
the URL-host allowlist, the 16-tool list and the readme's claims, including the
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
| `make verify-pro-skeleton` | Real-site check of the Pro add-on skeleton (Docker site; the free and Pro plugins must be active) |
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

- **Pro add-on skeleton 0.1.0** (merged to LOCAL main, 2d62447; NOT released, NOT pushed): compat guard,
  licensing with the updater, licence-gated `ToolRegistry` (no tools yet), Pro admin tab, safety test, zip,
  `make verify-pro-skeleton` passing on the dev site. Next: Plan 3 (licence hardening, batch store, undo, the five settings tools), then Plan 4 (Theme Builder
  layout tools + templates), Plan 5 (find/replace, audit, REST/OpenAPI parity, live verification).
  Release blockers before the first Pro sale (final review): (1) a non-explicit server answer (HTML 403/404, empty
  or non-JSON body) is stored by the canonical client as `invalid` and locks the tools, contradicting 'only an
  explicit verdict locks' — fix in the adapter; (2) the `admin_init` licence refresh retries on every admin/ajax
  request during a licence-server outage — skip `wp_doing_ajax()`/`wp_doing_cron()` and add a backoff; also guard
  or document `AIED_PRO_API_BASE`, add a readme.txt that discloses the remote calls, make the plugin Description
  match the shipped features, distinguish activation failure reasons in the admin notice, and confirm the product
  id `ai-editor-divi5-pro` and product URL with the divi5lab server. These are the first task of Plan 3.
- **WordPress.org review answered 2026-10-02** (issue: custom CSS/JS/PHP through layouts, `css.freeForm`/`mainElement`):
  fixed on branch `fix/no-custom-code` (`CodePolicy` + `LayoutGate`, every write/preview path, guides/recipes cleaned,
  `NoCustomCodeTest`); the owner uploads the 4.4.0 zip, tests on Playground and sends `docs/wporg-review-reply-2-draft.md`.
  Rule: the free plugin never saves custom CSS, JavaScript or PHP, and any future layout write path (including every Pro
  write tool) must go through `LayoutGate::check()`.
  Style-value guard scope is a named list (decoration/advanced + listed families), not a proof of completeness; add new
  CSS-bearing families when found.
- **4.4.0 = global styles release PLUS the free re-cut** (merged to LOCAL main, 026a571; NOT uploaded): read-only
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
- Validator: knows 88 Divi 5 block types (`SchemaRules`), including the
  render-verified Divi 5.14 modules (docs/module-verification-5.14.json); modules
  that could not be verified stay rejected and are listed in docs/SCHEMA.md.
- The old standalone `mcp-server/` (Node) was removed in 3.3.0: the plugin's
  built-in HTTP MCP endpoint is the only supported connection path.
- Roadmap after approval: the Pro add-on (own spec/plan), then WooCommerce template modules via a real export or class map.
- Header/footer are the active theme's (nav menu drives the header).
