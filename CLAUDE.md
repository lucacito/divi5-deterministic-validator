# AI Editor for Divi 5 — Claude Instructions

## What this project is

It started as a **deterministic, pure-PHP validator** for Divi 5 layout JSON —
proving the core bet: can we tell a valid Divi 5 layout from a broken one with
zero AI inference? That bet is proven, and the project has grown into the
product it was meant to power:

**AI Editor for Divi 5** — a WordPress plugin that lets an AI assistant (Claude,
Cursor, VS Code Copilot, ChatGPT) read, edit, and generate Divi 5 pages in plain
English over MCP and a REST API. The deterministic validator is the **gate**:
every write is validated before it touches the database, so the AI can be
creative but can never ship a broken layout.

Two halves, one repo:
- **The validator** (`src/`) — the deterministic core. Pure PHP, no dependencies,
  no AI. This is the sacred part.
- **The plugin** (`wp-plugin/`) — "AI Editor for Divi 5". Bundles a synced copy
  of the validator under `wp-plugin/validator/` and wraps it in the MCP server,
  REST API, AI-facing guides, licensing, and admin UI.

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
> project's scope** — those layers now exist by design in `wp-plugin/`. The
> constraint that survived is narrower and sharper: the *validator core* stays
> pure and deterministic. New scope is fine; compromising the gate is not.

## Architecture: how generation is steered

The plugin does not generate layouts in code — it **steers the AI** with guides
served over MCP/REST, then validates the result. The guides are pure, testable
PHP classes in `wp-plugin/src/`:

| Guide / tool | Teaches the AI… |
|---|---|
| `get_style_guide` (`StyleGuide`) | how to make a layout *valid + styled* (real attribute shapes) |
| `get_landing_guide` (`LandingGuide`) | how to make a single page *convert* (persuasion flow, copy, CTA strategy) |
| `get_site_guide` (`SiteGuide`) | how to build + wire a *multi-page site* |
| `get_section_recipes` (`SectionRecipes` + `data/section-recipes.json`) | proven, validated section markup to fill, each mapped to a persuasion stage |

Write tools (`validate_layout`, `update_page_layout`, `create_page`) run the
validator and reject anything invalid with exact violation codes so the AI
self-corrects. `create_page`, `set_front_page`, `set_primary_menu`,
`set_custom_css`, and `propose_php_snippet` are premium (offline license gate).
`propose_php_snippet` never executes PHP — it stores a proposal for human review.

Undo (v3.5.0): `HistoryService` is the single write path for AI page-content
writes (`update_page_layout`, `edit_page_content`, REST PUT/edit): it snapshots the
previous content (post meta `_aied_history`) before saving: up to the last 10 per
page, each <= 512 KB, trimmed oldest-first to a ~768 KB per-page budget
(`PageHistory::MAX_TOTAL_BYTES`; the newest is always kept). A snapshot can be
skipped (`history.reason`: `too_large` | `invalid_encoding` | `store_failed` |
`duplicate`), so undo is available only when `history.stored` is true. `list_page_history`, `get_page_history_entry` and
`restore_page_version` are **free** tools (all three surfaces). Restore bypasses the
validator gate by design (it brings back the person's own earlier content; the
result reports whether it passes) and snapshots the current content first, so a
restore is itself undoable when its own `history.stored` is true. Only plugin edits are snapshotted, not Divi-builder
edits; `create_page` has nothing to undo.

Same logic is exposed three ways, kept in lockstep: MCP (`McpHandler`), REST
(`RestController`), and the ChatGPT OpenAPI spec (`OpenApiSpec`). Add or change a
tool → update all three.

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

The installable plugin is `ai-editor-for-divi-5.zip` at the repo root (folder/slug/Text Domain = `ai-editor-for-divi-5`, the WP.org-assigned slug; the main file stays `ai-editor-divi5.php`) — a clean
archive of `wp-plugin/`'s contents under a top-level `ai-editor-for-divi-5/` folder (no macOS temp junk).
Rebuild it after changing anything under `wp-plugin/` so the distributable stays
current. Bump the version in `wp-plugin/ai-editor-divi5.php` (header +
`AI_EDITOR_DIVI5_VERSION`) and `wp-plugin/readme.txt` (Stable tag + Changelog)
on every release.

## The one manual blocker

`divi/Divi.zip` (commercial Divi 5 theme) must be placed by the user for the
Docker env and `make export-layouts`. Do not attempt to download it. If missing,
`make up` stops with exact instructions.

## Current state

- Validator MVP proven; plugin at v3.5.0 (freemium single build: free tier + Pro
  licence via divi5lab.com). 3.3.0 was submitted to WordPress.org and is under
  review (fixes applied after the first automated scan: Text Domain = slug
  `ai-editor-for-divi-5`, no `Tested up to` in the header); 3.4.0 is merged
  locally and not uploaded; 3.5.0 (undo for AI edits) is built on top of it.
  Nothing is uploaded to WordPress.org without the owner.
- Generation is guidance-driven (style, landing, image and site guides, 17
  section recipes), gated by the deterministic validator. `edit_page_content`
  (v3.2.0) does surgical find-and-replace edits.
- `wp-plugin/src/Licensing/LicenseClient.php` is a **WP.org variant** of the
  shared canonical client (layoutlab repo): the updater code is removed here
  because Plugin Check bans it. Don't re-sync the updater into this copy.
  Plugin Check (run in the Docker env) is clean at 3.5.0.
- `wporg-assets/` holds the WordPress.org directory art (icon, banner, 3 real
  screenshots, API key masked). It is NOT part of the plugin zip; it is uploaded
  to the WP.org SVN `assets/` folder at submission. Retake screenshots with the
  masking script approach (hide notices, mask key/URL) when the admin UI changes.
- The old standalone `mcp-server/` (Node) was removed in 3.3.0: the plugin's
  built-in HTTP MCP endpoint is the only supported connection path.
- 3.4.0: validator recognises the render-verified Divi 5.14 modules (see
  docs/module-verification-5.14.json); modules that could not be verified stay
  rejected and are listed in docs/SCHEMA.md (29 Divi 5.14 modules still rejected:
  28 harness-probed needs-real-export + 1 unprobed child, `divi/signup-custom-field`).
- Roadmap: 3.4.0 (render-verified Divi 5.14 module coverage) and 3.5.0 (undo for
  AI edits) are done. Remaining differentiators: preview-before-save, Theme
  Builder header/footer, global presets and WooCommerce template modules via a
  real export or class map.
- Header/footer are the active theme's (nav menu drives the header).
