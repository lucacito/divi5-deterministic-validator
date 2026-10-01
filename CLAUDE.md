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
at divi5lab.com, not on WordPress.org). Its source material is staged in `pro-addon/`
(licence client, front page/menu, custom CSS, PHP proposals); its own spec and plan
are still pending. The old freemium-in-one-build model (licence-gated tools inside
the WP.org plugin) was **rejected by WordPress.org Guideline 5** in the 2026-10-01
review — do not reintroduce it.

Three parts, one repo:
- **The validator** (`src/`) — the deterministic core. Pure PHP, no dependencies,
  no AI. This is the sacred part.
- **The plugin** (`wp-plugin/`) — bundles a synced copy of the validator under
  `wp-plugin/validator/` and wraps it in the MCP server, REST API, AI-facing guides,
  the built-in image pack and the admin UI. No licensing, no remote calls.
- **The Pro add-on staging area** (`pro-addon/`) — not shipped, not built, not tested
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

**15 MCP tools, all free:** `list_divi_pages`, `get_page_layout`, `validate_layout`,
`update_page_layout`, `edit_page_content` (surgical find-and-replace), `create_page`
(always a draft), `list_page_history`, `get_page_history_entry`,
`restore_page_version`, `list_media_images` (read-only Media Library listing,
`MediaLibrary` + `MediaService`, needs `upload_files` + per-item `read_post`) and the
five guide tools above. Write tools run the validator and reject anything invalid
with exact violation codes so the AI self-corrects.

**Images:** `wp-plugin/assets/images/` is an original, generated pack of 44 SVGs
(`scripts/generate-image-pack.php`, byte-deterministic; `manifest.json`). Recipes
store images as `{{aied:image:<token>}}`; `ImageTokens` resolves them to site-local
URLs when served (`ImagePack` reads the manifest), so no token and no third-party
image host ever reaches the AI.

**Extension hooks** (`jhmg_aied_*`, guarded by `ExtensionGuard` so built-ins always
win): the only way the Pro add-on extends the plugin. Reference: `docs/EXTENDING.md`.

Undo (v3.5.0): `HistoryService` is the single write path for AI page-content
writes (`update_page_layout`, `edit_page_content`, REST PUT/edit): it snapshots the
previous content (post meta `_aied_history`) before saving: up to the last 10 per
page, each <= 512 KB, trimmed oldest-first to a ~768 KB per-page budget
(`PageHistory::MAX_TOTAL_BYTES`; the newest is always kept). A snapshot can be
skipped (`history.reason`: `too_large` | `invalid_encoding` | `store_failed` |
`duplicate`), so undo is available only when `history.stored` is true. Restore
bypasses the validator gate by design (it brings back the person's own earlier
content; the result reports whether it passes) and snapshots the current content
first, so a restore is itself undoable when its own `history.stored` is true. Only
plugin edits are snapshotted, not Divi-builder edits; `create_page` has nothing to undo.

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
the URL-host allowlist, the 15-tool list and the readme's claims, including the
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
- Roadmap after approval: the Pro add-on (own spec/plan), then preview-before-save,
  Theme Builder header/footer, global presets and WooCommerce template modules via
  a real export or class map.
- Header/footer are the active theme's (nav menu drives the header).
