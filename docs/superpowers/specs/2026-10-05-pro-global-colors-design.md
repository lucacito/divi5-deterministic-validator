# Pro: write the site's global colors (`manage_global_colors`, Pro add-on 0.7.0)

Status: design, APPROVED by the owner 2026-10-05 (undo of a create removes the entry). Nothing here is built yet. Date: 2026-10-05.
Source: `docs/feature-requests-2026-10-04.md` item 1 ("create and edit global colors, variables and presets"). Owner decisions taken in the brainstorm: **colors only** in this release (variables and presets are separate later specs); **MCP + REST only**, not in the ChatGPT Action (the importer's 30-operation budget is used up); the five Customizer colors are **refused unless an explicit flag is passed**.

## Goal

Let an AI assistant set the site's design system before it builds pages: create, recolor, rename and deactivate the site's **custom global colors**, so the pages it builds can reference them (`gcid-...`) and stay on-brand. It fits the `build_site` story: set the palette, then build the pages. The blast radius is the whole site (every page that cites a color), so every write is previewed, validated, bound to a signed token and undoable.

In the Pro add-on only. The free plugin stays read-only (`list_global_styles`); it is not changed.

## Evidence (Divi 5.14 on the Docker dev site, read-only; theme source `includes/builder-5/server/Packages/GlobalData/GlobalData.php`)

- Stored shape of an entry: `{ color, folder, label, lastUpdated, status, usedInPosts }` (dev site: 27 colors; custom ones often have an empty `label` and `folder`). `status` is `active | inactive | temporary`. Ids are `gcid-` + a UUID for custom colors.
- `GlobalData::get_global_colors()` returns the five **Customizer** colors (`gcid-primary-color`, `gcid-secondary-color`, `gcid-heading-color`, `gcid-body-color`, `gcid-link-color`; `folder: customizer`) merged with the custom ones. The Customizer entries are built fresh on every read, so their `lastUpdated` is "now" each time.
- `GlobalData::set_global_colors( array $data, $already_sanitized = false )` **replaces the whole custom map**. For each Customizer id present in `$data` it writes the value into the theme's Customizer option (`et_update_option`) and removes it from the map. It then stores the map and clears all page resource caches (`ET_Core_PageResource::remove_static_resources`). Its sanitising is only `sanitize_text_field` per value, so it does **not** stop CSS metacharacters in a color.
- Divi's own REST permission for colors is only `current_user_can( 'edit_posts' )` (`GlobalDataController`).
- References: layouts cite a color as `$variable({"type":"color","value":{"name":"gcid-...","settings":{}}})$`. A page citing an id that does not exist loses its color silently (live proof in `docs/superpowers/specs/2026-10-01-global-styles-design.md`). Hence: **no delete**.

## Scope

In: one new Pro tool `manage_global_colors`; its REST route; a new batch item kind `global_color`; a Divi-guarded writer class; tests; a live gate.

Out: deleting colors; variables, fonts and presets (separate specs); changing any page layout; teaching layouts (the free guides already teach the reference shape); the ChatGPT Action; any change to the free plugin or the validator.

## Design

### The tool

`manage_global_colors`, Pro capability `edit_theme_options` (Divi's own bar, `edit_posts`, is too low for a site-wide change), licence-gated by the existing registry like every Pro tool.

Arguments (all type-checked, a wrong type is rejected, never cast):

| Name | Type | Meaning |
|---|---|---|
| `colors` | list, 1 to 50 | The changes. Each entry below. |
| `dry_run` | boolean | Preview only; nothing is written. |
| `preview_token` | string | Required for a real run: the token from the matching dry run. |
| `allow_theme_colors` | boolean | Default false. Required (and bound into the token) for any entry whose `id` is one of the five Customizer ids. |

Entry: `{ id?: string, label?: string, color?: string, status?: "active"|"inactive" }`.
- **No `id` = create.** `label` and `color` are required; `status` defaults to `active`. The tool generates `gcid-` + `wp_generate_uuid4()`.
- **`id` = update** of an existing entry. At least one of `label`, `color`, `status` is required. Only the fields given change.
- An unknown `id`, an entry whose current `status` is `temporary`, a duplicate `id` in one call, or two creates with the same `label` (case-insensitive) in one call: **the whole call is refused** with a reason per entry (a palette is applied atomically or not at all).
- No delete. Deactivating (`status: "inactive"`) is the only way to retire a color.

### Validation (before anything is written)

- `color` must match one of: hex `#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`; or `rgb(...)`, `rgba(...)`, `hsl(...)`, `hsla(...)` containing only digits, `.`, `%`, `,`, spaces and `/`. Anything else (named colors, `var()`, `url()`, `calc()`, braces, semicolons, quotes, backslashes, angle brackets, newlines) is refused. The value ends up in the site's generated CSS, so this is a CSS-injection guard, in the spirit of `CodePolicy`.
- `label` is 1 to 60 characters, no control characters or angle brackets, passed through `sanitize_text_field`.
- `id` must match `^gcid-[A-Za-z0-9-]{3,64}$`.

### Writing safely (`GlobalColors`, a new class in the add-on)

1. Read the complete current state through `GlobalData::get_global_colors()` only (never the option names). If the class or either method (`get_global_colors`, `set_global_colors`) is missing or throws: refuse with a clear message and write nothing (fail closed).
2. Compute the changes against that state. Untouched entries, including their `usedInPosts`, `folder` and `lastUpdated`, are passed back **byte-for-byte unchanged**. A changed entry gets a new `lastUpdated` in Divi's own format (`Y-m-d\TH:i:s.v\Z`); `usedInPosts` is never recomputed or edited.
3. **Dry run** returns `{ dry_run: true, changes: [ { id, action: create|update|deactivate|reactivate, before, after } ], theme_colors_touched: bool, preview_token, expires_in }`, and writes nothing. `theme_colors_touched` is true only when `allow_theme_colors` was passed and a Customizer id is among the changes; the preview then states in words that this restyles the whole theme.
4. **Real run** requires the token (`PreviewToken`, 30 minutes, single use, bound to the user and to the arguments including `allow_theme_colors`). Per entry, the "changed since the preview" check hashes `color`, `label`, `status`, `folder` only, **not** `lastUpdated` (the Customizer entries get a fresh one on every read). An entry changed since the preview is skipped and reported, never overwritten.
5. Just before writing, re-read the state and compare with the one used to compute the write; if anything (other than `lastUpdated` on Customizer entries) differs, recompute once, and if entries are now stale, skip them. Then call `GlobalData::set_global_colors( $fullMap )` exactly once with the **full** map (replace-all is Divi's contract, so a partial map would erase colors). Divi clears the page caches itself.
6. After the write, re-read and report what is stored now (not what was sent).

### Batch and undo

One batch per call (`BatchLog::record`), one item per changed color, new item kind `global_color` (`pro-addon/src/Kinds/GlobalColorKind.php`, registered like the others): `ref` = the `gcid`, `before` = the previous entry (or null for a create), `after` = the stored entry. `undo_batch` restores the previous entry; for an entry **created** by the batch it removes that entry, but only if it is unchanged since our write. The existing "changed since" rule applies: a color that was changed after our write is skipped and reported, and a batch is "fully undone" only when every item was restored. `allowed()` re-checks `edit_theme_options` at undo.

### Wiring

- MCP: the tool is registered in `Tools::all()` (the 15th Pro tool). REST: `POST /wp-json/ai-editor-divi5/v1/pro/manage_global_colors` through the existing `ToolRunner`/`ProRest` (the free plugin's `require_edit_posts` plus the tool's own capability).
- **ChatGPT exception (owner decision 2026-10-05):** `ProOpenApi` skips this tool; `ProParityTest` and `ToolsTest` pin the exception with the reason (free 16 + Pro 14 = 30 operations; the 15th Pro tool is MCP + REST only). Without this the 30-operation assertion would fail.
- Add-on version 0.7.0: header, constant, readme changelog, `pro-addon/README.md`, `make build-pro`. The free plugin's readme and UI are not touched (the only Pro mention allowed there stays the one dashboard card).

## Testing

Unit (add-on suite, `make test-pro`; a Divi shim like the existing `DiviGlobalDataShims`):
- Argument typing for every field; the all-or-nothing refusals (unknown id, `temporary`, duplicate id, duplicate label, empty change list, more than 50).
- **CSS-injection table:** each forbidden form is refused (`red`, `var(--x)`, `#fff; }`, `url(x)`, `rgb(0,0,0);`, a newline, a 9-digit hex, `calc(1px)`).
- Customizer ids refused without `allow_theme_colors`; accepted with it; the flag is part of the token's argument hash (a token made without it does not open a run with it).
- Replace-all safety: after a create, every pre-existing entry (including `usedInPosts`, `folder`, `lastUpdated`) is byte-identical in the map passed to `set_global_colors`.
- Preview/real-run: no token, a token for other arguments, another user's token, an expired or spent token are all refused; an entry changed since the preview is skipped and reported.
- Fail-closed when Divi's class or either method is missing, and when `set_global_colors` throws.
- Undo: restores a recolor, removes a created entry, skips an entry changed since, re-checks the capability, and a batch is reported "fully undone" only when every item was.
- Parity: `ProParityTest`/`ToolsTest` updated for 15 Pro tools and the recorded ChatGPT exception; the licence lapse behaviour is unchanged (`ExpiryKeepsAllToolsTest`).

Live gate `make verify-pro-global-colors` (Docker site, self-cleaning, dev site only, in the style of `verify-pro-settings`): fingerprint the stored colors (including the five Customizer options) first; preview then create, recolor and deactivate a throwaway color; prove with a throwaway **published page that cites the new color** that Divi's generated CSS defines `--gcid-...` with the new value (the spike's render check); prove a Customizer id is refused and that a preview with the flag changes nothing; undo the batch; assert the stored colors and the Customizer options are **byte-identical** to the start; delete the throwaway page.

## Risks and decisions to confirm

- **Undo of a create removes the entry.** A page that began citing the new color after our write would lose it; the "unchanged since our write" rule does not see page usage (Divi's `usedInPosts` is not reliable). Alternative: undo of a create only deactivates. Recommended: remove, because deactivated leftovers would pile up; the preview and batch message say so.
- **`temporary` colors are left alone** (they belong to Divi's builder session); an entry in that state is refused with a reason.
- **Labels are optional in Divi** (many custom colors have none): updates may leave `label` unchanged and creates require one, so the AI always names what it creates.
- **A written color is not "applied" anywhere** until a layout cites it; the tool says so in its result, and points to `list_global_styles` and the style guide for the reference shape.
- **Known limit:** the preview token is best effort against replay if an object cache evicts the spent-nonce transient (same as the other Pro tools).
