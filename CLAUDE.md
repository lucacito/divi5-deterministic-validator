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
  exports (`make export-layouts`), documented in `docs/SCHEMA.md`. Never invent
  block types or attribute shapes.
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
| `make clean` | Destroy volumes (prompts for confirmation) |

Tests run via PHPUnit (`phpunit.xml`) and cover both the validator
(`Divi5Validator\*`) and the plugin's pure classes (`AiEditorDivi5\WP\*`), the
latter against light WP shims in `tests/bootstrap.php` — no WordPress install
needed. `make test` exits 0 = everything is proven; non-zero = something is
broken or incomplete.

## The plugin build

The installable plugin is `ai-editor-divi5.zip` at the repo root — a clean
archive of `wp-plugin/`'s contents (files at the zip root, no macOS temp junk).
Rebuild it after changing anything under `wp-plugin/` so the distributable stays
current. Bump the version in `wp-plugin/ai-editor-divi5.php` (header +
`AI_EDITOR_DIVI5_VERSION`) and `wp-plugin/readme.txt` (Stable tag + Changelog)
on every release.

## The one manual blocker

`divi/Divi.zip` (commercial Divi 5 theme) must be placed by the user for the
Docker env and `make export-layouts`. Do not attempt to download it. If missing,
`make up` stops with exact instructions.

## Current state

- Validator MVP proven; plugin shipped through v2.14.0.
- Generation is guidance-driven (style + landing + site guides, section recipes),
  gated by the deterministic validator. v2.14.0 added the conversion-focused
  `get_landing_guide` and mapped every recipe to a persuasion stage.
- Header/footer are the active theme's (nav menu drives the header); global
  Theme Builder header/footer templates are a later phase.
