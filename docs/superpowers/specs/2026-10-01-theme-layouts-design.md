# 4.3.0 — Edit your existing header and footer (Theme Builder layouts)

Status: APPROVED by the owner on 2026-10-01 (all five decisions at the bottom, defaults) · Date: 2026-10-01 · Target release: JHMG AI Editor for Divi 5 4.3.0, **held as its own later update** (see Release).
Roadmap: undo (4.0.0) → history table (4.1.0) → dry-run diff (4.2.0) → **header/footer (4.3.0)** → global presets → Pro add-on spec. 4.1.0 and 4.2.0 are merged locally and ship in one upload after WordPress.org answers the 4.0.0 review.

## Goal

Let an AI assistant change the text, links and styling of the site's **existing** Theme Builder header and footer ("change the phone number in the footer") through the same validated, previewable (`dry_run`) and undoable path it already uses for pages. Today the header and footer are invisible to every tool, because their content lives in Theme Builder layout posts, not in pages.

## Why this is safe to build (evidence, 2026-10-01)

A throwaway spike on the Docker dev site (Divi 5.14), using Divi's own functions (`et_theme_builder_get_theme_builder_post_id`, `et_theme_builder_insert_layout`, `et_theme_builder_store_template`), established:

- A header/footer is an ordinary post of type `et_header_layout` / `et_footer_layout` whose `post_content` is plain Divi 5 block markup, flagged `_et_pb_use_divi_5 = on` and `_et_pb_use_builder = on`. A **template** (`et_template` post) points at its layouts through `_et_header_layout_id`, `_et_footer_layout_id`, `_et_body_layout_id` (plus `_et_*_layout_enabled`, `_et_default`, `_et_enabled`, `_et_use_on`, `_et_exclude_from`), and the live **Theme Builder** post (`et_theme_builder`, status `publish`) lists its templates in `_et_template` meta. A draft Theme Builder (status `auto-draft`) exists separately.
- A plain `wp_update_post( wp_slash( [ 'ID' => $layoutId, 'post_content' => … ] ) )` — exactly what `HistoryService::write` does — appeared on the live front end on the next request. A style edit (colour and font size) appeared in the page CSS immediately, with no cache clearing on that site.
- The stored layout content passed the existing validator with no violations.
- These post types are registered with `capability_type => 'post'` and `map_meta_cap => true`, so `current_user_can( 'edit_post', $id )` works per layout; Divi's own Theme Builder requires `edit_others_posts`.
- The spike was fully cleaned up; nothing of it is in the repository.

**Update 2026-10-02 — now proven for one real site:** a header (menu, search, social follow), footer (text, button, social follow, code) and body layout built in Divi 5.14's own Theme Builder on the dev site all pass the validator with zero violations (`fixtures/valid/themebuilder-*.json`), and `GET /theme-layouts`, `/validate` and `PUT … dry_run` agree through the real REST handlers (body layout correctly "not found"). This is still a single site, so the readme keeps its hedge.

**Was not proven at spec time (stated honestly):** the spike's content was a simple layout built for the test. Divi ships no real Divi 5 header/footer content, so it is **not verified that a real builder-made header** (menu, logo, search, social icons…) passes the validator. If a real header contains a module or placement the validator does not accept, an edit to it is *refused with exact violation codes* — safe, but less useful. The validator already knows `divi/menu`, `divi/search`, `divi/social-media-follow`, `divi/breadcrumbs`, `divi/global-layout` and the verified column placements; a real export from the owner's training site (or any real Divi 5 site) is the way to close this gap, and the readme must not claim more than the validator accepts.

## Scope

In:
- Read, `dry_run`-preview, edit (find/replace and whole-layout), history list/read and restore for **header and footer layout posts that are used by a template of the LIVE Theme Builder**.
- One new **read-only** tool, `list_theme_layouts`, so the AI can discover them.
- Dashboard "Recent AI edits" rows and the Restore button work for these layouts too (labelled Header / Footer).

Out — never in the free plugin (WordPress.org compliance; this is the line the reviewers drew for the front page and menu):
- creating, deleting, duplicating or publishing templates or the Theme Builder; assigning a template to anything (`_et_use_on`, `_et_exclude_from`, `_et_default`, `_et_enabled`, the `*_layout_enabled` flags); trashing/restoring Theme Builder posts. Those belong in the separate Pro add-on (own spec).
- **body** layouts (`et_body_layout`): they are dynamic-content templates with modules the validator has no precedent for. Listed read-only with `editable: false`; not editable.
- any change to Divi settings, options or the theme.

## Design

### One access rule for "which post may the plugin edit" (new `EditTarget`)

Today ten call sites (five MCP tool handlers, five REST handlers, plus the Dashboard restore) each hard-code `post_type === 'page'`. They are replaced by a single resolver so the rule cannot drift:

`EditTarget::resolve( int $id ): array{post: ?\WP_Post, kind: 'page'|'header'|'footer'|null, error: null|'not_found'|'forbidden'}`

| Post | Allowed when |
|---|---|
| `page` | exactly as today (`edit_post`; the plugin-wide `edit_pages` key rule is unchanged) |
| `et_header_layout` / `et_footer_layout` | status `publish` **and** the id is referenced by a template of the live Theme Builder **and** the user has `edit_post` on it **and** `edit_others_posts` **and** Divi's Role Editor allows them the Theme Builder (`et_pb_is_allowed( 'theme_builder' )`; Divi grants it to administrators only by default and an owner can switch it off), failing closed when that function is missing |
| anything else (posts, `et_template`, `et_theme_builder`, `et_body_layout`, attachments, orphaned or draft-only layouts) | never: reported exactly like a missing page (`Page N not found.`), so nothing leaks about other post types |

The "referenced by a live template" set is read by `ThemeLayouts` (read-only): `et_theme_builder_get_theme_builder_post_id( true, false )` (**`$create = false` is mandatory** — the default would create a Theme Builder post), then `et_theme_builder_get_theme_builder_template_ids( true, $tbId )`, then the verified `_et_*_layout_id` / `_et_default` / `_et_enabled` / `_et_use_on` / `_et_exclude_from` meta of each template. Each Divi function is guarded with `function_exists`; if Divi's Theme Builder is not present the set is empty and every header/footer id is simply "not found". Pure rule logic (is this id in the set? which kind?) is separated from the WordPress reads so it is unit-testable.

### Tools (MCP, REST, ChatGPT OpenAPI in lockstep)

- **New:** `list_theme_layouts` (MCP) / `GET /theme-layouts` (REST) / `listThemeLayouts` (OpenAPI). Requires the plugin-wide `edit_pages`, `edit_others_posts` and Divi's Theme Builder permission (`ThemeLayouts::userMay()`). Returns the live templates: `{ templates: [ { template_id, title, default, enabled, use_on[], exclude_from[], header: {layout_id, enabled, editable} | null, footer: {…} | null, body: {layout_id, enabled, editable: false} | null } ], count }` and says plainly when there is no live Theme Builder. Read-only; logged as `list_theme_layouts` / `read`.
- **Existing tools accept a header/footer layout id in their `page_id` parameter** (the name stays: renaming would break clients): `get_page_layout`, `validate_layout` (no id), `update_page_layout`, `edit_page_content`, `list_page_history`, `get_page_history_entry`, `restore_page_version`, and the REST equivalents. Descriptions say so. Everything they do for pages (validator gate, `dry_run`, snapshot-before-write, restore reports the verdict) applies unchanged. The layout envelope gains an additive `post_type` field.
- `list_divi_pages` and `create_page` are unchanged (pages only).
- Tool count 15 → **16**, OpenAPI operations 15 → **16** (cap 30). `WpOrgComplianceTest`'s pinned tool list and the readme's "(15 tools)" claim are updated with a justification: a new **read-only** tool, nothing licence-gated, nothing that writes settings.

### After a successful write to a layout

`HistoryService::write()` and `restore()` call `DiviCompat::clearLayoutCaches()` when the post is a header/footer layout: `et_theme_builder_clear_wp_cache( 'all' )` and `ET_Core_PageResource::remove_static_resources( 'all', 'all' )`, each guarded by `function_exists` / `class_exists`. The spike did not need it, but sites that use Divi's static CSS file cache may. It lives in the one shared write path, so MCP, REST and the Dashboard restore all get it. Pages are unaffected (Divi's own save hooks cover them).

### History and Dashboard

`HistoryStore` is keyed by post id and already works for any post. The Dashboard restore handler (`AdminPage::handleRestorePage`) switches to `EditTarget`; rows for layout edits show a "Header"/"Footer" label next to the title (Theme Builder layout titles are generic).

### Guides

`StyleGuide`/`SiteGuide`: one short section: header and footer can be previewed and edited like pages (find the layout with `list_theme_layouts`; prefer `edit_page_content` for small changes; always `dry_run` first because the header/footer appears on every page); the AI cannot create templates or choose where they apply — that is the owner's job in Divi's Theme Builder. The existing sentence "Global Divi Theme Builder header/footer templates are a later phase" is updated.

## Compliance

- No new storage, option, table or remote call. No licence wording. No call to any API that changes site settings. Divi functions are only *read* (`…_get_…`) or used to clear Divi's own caches.
- The readme lists exactly what the plugin can and cannot do here ("edit the content of your existing header and footer; it cannot create, delete, assign or publish templates") and keeps its permissions answer true ("page, header and footer content" instead of "page content"). `WpOrgComplianceTest` stays; only its pinned tool list and the readme-claim checks that mention "15" change, each with a justification.

## Testing

- Pure/unit: `EditTarget` rules against shims (every row of the table above, including: draft/orphan layout, body layout, `et_template`, a plain post, a user without `edit_others_posts`, Divi functions missing, `$create = false` is what is called); `ThemeLayouts` output shape; `list_theme_layouts` in all three transports (lockstep + OpenAPI schema rules: ≤ 300-char descriptions, no bare objects); handler-level tests like `DryRunHandlersTest` showing a header edit is refused for a non-live layout and allowed for a live one, `dry_run` never writes, history/restore work, and the cache-clear hook is invoked only for layouts.
- Real site (`make verify-theme-layouts`, Docker): builds a live Theme Builder header + footer + template with Divi's own functions (recording what it created, adding to an existing Theme Builder rather than replacing it, and removing only its own posts and links in a `finally`), then drives the REAL REST and MCP handlers: list, read, `dry_run`, a real edit, history and restore, the front-end render showing the edit, refusals for an orphan layout / a draft Theme Builder layout / a body layout / an ordinary post, and that nothing in the templates' assignment meta changed.
- Plugin Check clean on the dev copy and the extracted zip.

## Release

Built and merged locally as 4.3.0 but **not bundled with 4.1.0/4.2.0**: the first WordPress.org approval is still pending and header/footer editing has the widest blast radius of anything in the plugin (it appears on every page), so it should go out as a separate update after the first approval, with the readme wording above, rather than add review surface to the first approved version.

## Risks / things to decide

1. **Validator coverage of real headers: proven on one real site (2026-10-02), not on all.** Failure mode is a safe refusal. Mitigation: a real export before the readme claims anything; the claim stays "layouts the checker accepts".
2. **Blast radius.** A header/footer edit shows on every page. Mitigations: `dry_run` first (guides say so), snapshot-before-write with one-call restore, the stricter `edit_others_posts` bar, live layouts only, no template creation or assignment.
3. **Draft Theme Builder.** If the owner has unsaved Theme Builder changes open in Divi, publishing that draft could overwrite an API edit to the live layout. History makes it recoverable; the readme and guide say to publish or discard open Theme Builder changes first. (Divi's draft/live copy behaviour is to be confirmed on the real site test; it does not change the design.)
4. **WordPress.org perception.** Editing site-wide template content could be read as site administration. Mitigations: content-only, never assignment, documented plainly, separate release.
5. **Divi internals.** The Theme Builder functions are internal to Divi and may change. Every call is `function_exists`-guarded; if Divi's API is missing the tools say "no Theme Builder available" and header/footer ids are refused.

## Decisions I need from the owner (defaults if you just say "approved")

- Header and footer only; body layouts listed read-only and not editable: **yes** (default).
- Layouts of the live Theme Builder only; `edit_others_posts` and Divi's own Theme Builder permission (Role Editor; fail closed) required in addition to `edit_post`: **yes** (default; the Role Editor condition was added by the controller during execution after review, it only narrows access).
- One new read-only tool (`list_theme_layouts`, 16 tools); the existing tools take a layout id in `page_id`: **yes** (default).
- Built now, shipped later as its own update (4.3.0) after the first WordPress.org approval: **yes** (default).
- Real header/footer export from the training site (or any real Divi 5 site) requested before the readme claims real-world coverage: **yes** (default).
