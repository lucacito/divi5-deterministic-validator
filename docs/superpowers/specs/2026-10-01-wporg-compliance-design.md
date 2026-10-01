# 4.0.0 — WordPress.org compliance (free plugin + separate Pro add-on)

Status: DRAFT for owner approval · Date: 2026-10-01 · Release: **JHMG AI Editor for Divi 5 4.0.0**
Trigger: WordPress.org Plugin Review Team pre-review of 3.3.0 (review id AUTOPREREVIEW ❗TRM-LIC-RMT, 2026-10-01).
Owner decisions already given (2026-10-01): free plugin + separate Pro add-on; name **JHMG AI Editor for Divi 5**;
no remote image hosts (Media Library + bundled local placeholders).

## Goal

Ship a WordPress.org plugin that passes the review on the FIRST resubmission (the team warns a repeat of
the same kind of violation ends the review), without losing the product: everything an AI needs to read,
validate, edit, create (as drafts), and undo Divi 5 pages stays free; the paid features live in a separate
add-on plugin hosted by divi5lab.com.

## What the reviewers flagged → what we do

| # | Finding | Resolution |
|---|---|---|
| 1 | **Guideline 5, trialware**: create_page / set_front_page / set_primary_menu / set_custom_css / propose_php_snippet blocked by `Licensing::isPremium()`; upgrade cards, licence notice | **No licence code and no locked feature in the WP.org plugin.** `create_page` becomes free (content, drafts only). The other four tools leave the plugin and become the Pro add-on. Licensing, upgrade tab, lock cards, licence nag removed. |
| 2 | **Arbitrary custom CSS/PHP** (`set_custom_css`, `propose_php_snippet`) | Removed from the WP.org plugin (they ship only in the add-on). |
| 3 | **Remote administration** (front page, menu via API key) | `set_front_page` and `set_primary_menu` removed from the WP.org plugin. What remains is page *content* work only (list/get/validate/update/edit/create draft/history/restore/guides). The readme states plainly that the AI client the owner connects acts only through the owner's own key and only on page content. |
| 4 | **Calling files remotely** (Pravatar, randomuser.me, picsum, placehold.co, loremflickr in `ImageGuide`, `StyleGuide`, and ~16 `picsum.photos` URLs inside `data/section-recipes.json`) | No third-party image host anywhere in the plugin. Recipes/guides use a placeholder TOKEN that the plugin resolves to a file bundled in the plugin (`assets/placeholders/`) served from the site's own domain. `ImageGuide` rewritten: Media Library / owner-supplied images first, bundled placeholders otherwise. |
| 5 | **Undocumented external service** (divi5lab licence calls) | The calls disappear with the licence client. Readme says the plugin makes no external requests. |
| 6 | **Name/slug** "AI Editor for Divi 5" too generic | Display name `JHMG AI Editor for Divi 5`, slug **`jhmg-ai-editor-for-divi-5`** (must be requested explicitly in the reply to the review). |
| 7 | Contributors `jhmg` ≠ account | `Contributors: lucaslopvet`. |
| 8 | Guideline 11, dashboard hijack | No upgrade/promo cards, no licence nag. The Dashboard keeps only connection status, setup progress, results, the history panel, and a single quiet readme-style line pointing at the separate Pro add-on (no nag, dismissible not needed). Converter cross-promotion strip removed. |

## Scope (4.0.0 free plugin)

**Stays (free):** validator + gate; list_divi_pages; get_page_layout; validate_layout; update_page_layout;
edit_page_content; **create_page (draft)**; get_style_guide / get_landing_guide / get_site_guide /
get_image_guide / get_section_recipes; history/undo (3.4/3.5 work incl. the 24 Divi 5.14 modules and
`DiviCompat`); usage log; API key; Connect UI; OpenAPI action.
**Removed from the WP.org build:** `Licensing`, `Licensing/LicenseClient`, Upgrade tab, lock cards, licence notice,
`set_front_page`, `set_primary_menu` (+ `MenuBuilder`), `set_custom_css` (+ `CustomCss`),
`propose_php_snippet` (+ `PhpProposals` and its admin list), their REST routes and OpenAPI operations, and the
guide text that tells the AI to call them (SiteGuide/LandingGuide/StyleGuide must no longer reference tools that
do not exist in the free plugin; they may say "optional add-on tools, when installed, appear in the tool list").
**Moved to the add-on package (kept in the repo, not in the WP.org zip):** the code of the removed items plus
`LicenseClient` (an updater is fine there — it is not hosted on WordPress.org).

## Extension points (so the add-on can plug in without any locked code in the free plugin)

Generic, documented hooks only; the free plugin never checks a licence. Names are final API:

- `apply_filters( 'jhmg_aied_mcp_tools', array $tools )` — add MCP tool definitions to `tools/list`.
- `apply_filters( 'jhmg_aied_mcp_call', null, string $name, array $arguments, $rpc_id )` — a handler returns a
  `WP_REST_Response` to claim a tool call (first non-null wins); `null` falls through to "Unknown tool".
- `do_action( 'jhmg_aied_register_rest_routes', string $namespace )` — fired from `register_routes()`.
- `apply_filters( 'jhmg_aied_openapi_paths', array $paths, string $base )` and
  `apply_filters( 'jhmg_aied_openapi_schemas', array $schemas )` — extend the ChatGPT spec (same ≤300-char /
  no-bare-object rules apply to add-ons; documented).
- `apply_filters( 'jhmg_aied_admin_tabs', array $tabs )` — optional: lets the add-on contribute its own
  settings screen (licence key, proposals) so the free plugin has no licence UI at all.
Hooks are covered by tests (a registered filter changes the tool list/dispatch; no filter → unchanged).

## Pro add-on (separate deliverable, specified here only as a contract)

Package `jhmg-ai-editor-pro` built from `pro-addon/` into its own zip, distributed from divi5lab.com, requires the
free plugin, uses only the hooks above, owns the licence client + its own admin screen + the four moved tools.
Its own spec/plan follows; it does NOT block the WordPress.org resubmission. Until it exists the Pro tools are
simply unavailable (nobody has bought 3.x — never distributed).

## Release identity & rename mechanics

- Display name / readme title / header `Plugin Name`: `JHMG AI Editor for Divi 5`.
- Slug, folder, zip, **Text Domain**: `jhmg-ai-editor-for-divi-5`; main file renamed to
  `jhmg-ai-editor-for-divi-5.php`. All `__()`/`esc_html__()` etc. text-domain args (167+) updated; build script,
  Docker bind-mount path, tests and docs follow. Internal identifiers stay (`AI_EDITOR_DIVI5_*` constants,
  `AiEditorDivi5\WP` namespace, `ai_editor_divi5_*` option/nonce/action names, REST namespace
  `ai-editor-divi5/v1`, menu slug, MCP server name `ai-editor-divi5`) — never distributed, but these are
  stable API for AI-client configs and do not infringe anything.
- Version **4.0.0** (breaking: Pro tools and licence removed). `Tested up to` stays readme-only. Contributors:
  `lucaslopvet`.
- Graphics (icon/banner) must not imply Divi/Elegant Themes affiliation: they currently show only "AI Editor for
  Divi 5" text — regenerate with the new name; keep "for Divi 5" descriptive, no Divi logo.
- Readme: no "External services" section (none exists); a short "Works with Divi 5 (not affiliated with Elegant
  Themes)" line; Pro add-on mentioned once, factually, as a separate plugin (guideline 5 allows pointing to it).

## Images (replaces remote hosts)

- `assets/placeholders/`: a small set of neutral SVGs (hero 16:9, square, wide, avatar) < 2 KB each, GPL-compatible
  (original, generated by us).
- Recipes and guides use tokens `{{aied:image:hero}}`, `{{aied:image:square}}`, `{{aied:image:avatar}}`, …;
  `SectionRecipes::recipe()` and the guides resolve them with `plugins_url()` at serve time so the AI receives
  real site-local URLs. A test pins: no `http(s)://` host other than the site's own and `example.com` in the
  recipes/guides output; every recipe still validates (the validator ignores `src`).
- `ImageGuide` rewritten: prefer Media Library / owner-provided image URLs; else the bundled placeholders; never a
  third-party host. (A read-only `list_media` tool is a possible follow-up, not in 4.0.0.)

## Testing

- Compliance guards (PHPUnit, run in `make test`): no `Licensing`, `isPremium`, `divi5lab.com`, `set_custom_css`,
  `propose_php_snippet`, `set_front_page`, `set_primary_menu`, `update_plugins`/updater strings anywhere under
  `wp-plugin/`; no remote image/script host strings under `wp-plugin/` (allowlist: `example.com`, schema/doc URLs
  that are not fetched — enumerated in the test); `Tested up to` absent from the plugin header; text domain equals
  slug everywhere.
- Hook tests (tool list/dispatch/OpenAPI/admin tabs) incl. "no filter = unchanged".
- Existing suites stay green (validator, recipes, history, guides, OpenAPI lockstep — OpenAPI/MCP tool counts
  updated deliberately: MCP 18 → 14 tools in the free plugin).
- Plugin Check clean under the NEW slug (swap procedure), live smoke (create page draft, edit, history, restore),
  admin screenshots (no upsell/nag), readme validated.

## Reply to the review (drafted for the owner to send; not sent by us)

Short: slug request `jhmg-ai-editor-for-divi-5`; one line per resolved class (no locked features/licence code; custom
CSS/PHP removed; remote-admin tools removed; no remote assets; no external services; contributor fixed); one
clarification that the plugin connects only to the site owner's own AI client via their key and edits page
content. No change lists (they asked us not to).

## Risks / decisions

1. **Pro value shrinks** (create_page now free) — owner accepted by choosing this model; the add-on can later offer
   higher-value tools (whole-site builder, templates) that use the same hooks.
2. **Moving code to `pro-addon/`** — keeps history; a build script produces both zips so the two never drift.
3. **Residual review risk** — reviewers may still question the API-key model; the readme and reply explain it; the
   key is owner-generated, bearer-authenticated, and every action re-checks `edit_post`.
4. **Decision for you:** version 4.0.0 vs continuing 3.x numbering (default 4.0.0); and whether to keep the Dashboard's
   single quiet Pro-add-on line (default: **no admin mention at all**; readme only).
5. The 3.4.0/3.5.0 storage question (post meta vs custom table) is still open and still must be settled before
   upload; this release includes the 3.5.0 history as is unless you decide otherwise.
