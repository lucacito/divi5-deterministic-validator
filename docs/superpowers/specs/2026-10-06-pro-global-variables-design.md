# Pro: write the site's global variables (`manage_global_variables`, Pro add-on 0.8.0)

Status: design, approved in chat by the owner 2026-10-06 (parts 1 and 2); awaiting the owner's review of this written spec. Nothing here is built yet. Date: 2026-10-06.
Source: `docs/feature-requests-2026-10-04.md` item 1 ("create and edit global colors, variables and presets"). Colors shipped as Pro 0.7.0 (`docs/superpowers/specs/2026-10-05-pro-global-colors-design.md`; this spec reuses its pattern and its lessons). Owner decisions taken in the brainstorm: **variables only** in this spec (presets are a separate later spec that starts with a grounding spike); **types `numbers` and `strings` only** in v1; **archive, never delete**; a **separate tool**, not a widened `manage_global_colors`; **MCP + REST only** (the ChatGPT importer's 30-operation budget is used up); **teaching the AI to cite variables is a gated phase B**.

## Goal

Let an AI assistant set the site's design tokens (spacing, radii, sizes, short text snippets) before it builds pages: create, edit, rename and archive the site's **global variables** of type numbers and strings. It fits the `build_site` story: set the palette (`manage_global_colors`) and the tokens, then build the pages. The blast radius is the whole site, so every write is previewed, validated, bound to a signed token, locked and undoable.

In the Pro add-on only. The free plugin stays read-only (`list_global_styles`) and is not changed in phase A. The validator is not touched.

## Evidence (Divi 5.14, theme source `includes/builder-5/server/Packages/GlobalData/GlobalData.php`, read from `divi/Divi.zip`)

- Stored shape per entry: `{ label, value, order, status }`, grouped by type under the option `global_variables`: `numbers`, `strings`, `images`, `links`, `fonts`, `gradients`. Ids are `gvid-` + a UUID (or a short hex). `status` is `active | archived`. The doc examples show only numbers (`'12px'`) and strings (`'16px'`).
- `GlobalData::get_global_variables()` returns all seven groups (`colors` too) as objects, adds `allowedActions` to every entry, and merges the Customizer fonts into `fonts` (and removes any stored copy of them first). It caches the result for the rest of the request.
- `GlobalData::set_global_variables( array $data )` **returns silently without writing** unless `current_user_can( 'edit_theme_options' )` and `et_pb_is_allowed( 'variables_manager' )`. Otherwise it sanitises with `sanitize_global_variables_data`, **replaces** the stored option with the result, resets its cache and clears page resource caches.
- `sanitize_global_variables_data` keeps only the six types `numbers strings images links fonts gradients` (a `colors` group is dropped), drops any entry whose id is `undefined` or does not start `gvid-` or whose `label` is empty, skips `allowedActions`, keeps `strings` and `gradients` values character-identical (invalid UTF-8 and null bytes removed), runs `links` values through `esc_url_raw`, and runs every other value (and every other key's value, including `order`) through `sanitize_text_field`, so numbers become strings. It does **not** stop CSS metacharacters in a value.
- References in generated CSS use `var(--gvid-...)`; Divi also reads a `$variable(...)$` payload for some types. No fixture or real export in this repo cites a non-color variable (CLAUDE.md: non-color variables are listed but never taught). That is why citing is phase B.

## Scope

In: one new Pro tool `manage_global_variables` and its REST route; a Divi-guarded accessor class `GlobalVariables`; a site-wide `GlobalVariablesLock`; a batch item kind `global_variable`; tests; a live gate `make verify-pro-global-variables`; a live render spike for phase B.

Out: deleting variables; images, links, fonts and gradients variables (later, from a real builder export); color variables (`manage_global_colors`); reordering; presets (own spec); changing any page layout; the ChatGPT Action; any change to the free plugin or the validator in phase A.

## Design

### The tool

`manage_global_variables`, Pro capability `edit_theme_options`, plus Divi's `variables_manager` permission checked up front (`et_pb_is_allowed`, behind a guard: when the function is missing the tool refuses, it never assumes yes). Licence-gated by the existing registry like every Pro tool. The 16th Pro tool; MCP + REST only (`ProOpenApi::MCP_REST_ONLY`, the parity tests record the second exception; the ChatGPT spec stays at 16 free + 14 Pro = 30 operations).

Arguments (type-checked, a wrong type is rejected, never cast):

| Name | Type | Meaning |
|---|---|---|
| `variables` | list, 1 to 50 | The changes. Each entry below. |
| `dry_run` | boolean | Preview only; nothing is written. |
| `preview_token` | string | Required for a real run: the token from the matching dry run. |

Entry: `{ id?: string, type?: "numbers"|"strings", label?: string, value?: string, status?: "active"|"archived" }`.
- **No `id` = create.** `type`, `label` and `value` are required; `status` defaults to `active`. The tool generates `gvid-` + `wp_generate_uuid4()` and gives the entry `order` = the highest `order` in its type + 1.
- **`id` = update** of an existing entry of type numbers or strings. At least one of `label`, `value`, `status` is required; only the fields given change. `type` on an update is refused (a variable never changes type); `order` is never settable.
- Atomic: an unknown `id`, an `id` of another type group (images, links, fonts, gradients, colors) or a Customizer font, a duplicate `id` in one call, or two creates with the same `type` and `label` (case-insensitive) **refuse the whole call** with a reason per entry.
- No delete. Archiving (`status: "archived"`) is the only way to retire a variable.

### Validation (before anything is written)

The value ends up in the site's generated CSS, so these are injection guards in the spirit of `CodePolicy`:
- **`numbers`:** a number with an optional unit (`px rem em % vh vw vmin vmax ch svh dvh lvh`), or `calc(...)`, `clamp(...)`, `min(...)`, `max(...)` (nestable, depth-limited) made only of such numbers, `. , + - * /`, spaces and parentheses. Anything else (`var()`, `url()`, braces, semicolons, quotes, backslashes, angle brackets, newlines, other functions, `!important`) is refused. Maximum 100 characters.
- **`strings`:** at most 200 characters; no control characters, braces, semicolons, backslashes, angle brackets or quotes; not containing `url(`, `@import` or `expression(` (case-insensitive). The plan's first task is a live render that shows where Divi emits a string value; if it reaches a CSS value, the rule tightens to what that evidence allows (it may only loosen if a real export proves a form).
- **`label`:** 1 to 60 characters, no control characters or angle brackets, passed through `sanitize_text_field`.
- **`id`:** `^gvid-[A-Za-z0-9-]{3,64}$`.

### Writing safely (`GlobalVariables`, a new class in the add-on)

1. **Read** the complete state through `GlobalData::get_global_variables()` only (never the option name). If the class or either method (`get_global_variables`, `set_global_variables`) is missing or throws: refuse and write nothing (fail closed). The read is normalised: objects become arrays, `allowedActions` is removed from every entry, and the Customizer fonts (the entries Divi merges into `fonts`) are removed, because Divi must not store them.
2. **Compute** the changes against that state. Untouched entries, including every group the tool does not manage, go back exactly as Divi would store them (the sanitiser's own output form: values and `order` as strings). A changed entry changes only the fields given.
3. **Dry run** returns `{ dry_run: true, changes: [ { id, type, action: create|update|archive|restore, before, after } ], preview_token, expires_in }` and writes nothing. A created id shows as `null`; the real run reports it.
4. **Real run** requires the token (`PreviewToken`, 30 minutes, single use, bound to the user and the arguments). The "changed since the preview" check hashes `label`, `value`, `status` per entry; an entry that changed since is skipped and reported, never overwritten.
5. **Pre-flight (`GlobalVariables::unsafeEntries`)** on the exact map about to be sent, in the dry run, before every write and before every undo: it must contain only the six types, and no entry may have an empty label, an id that is `undefined` or does not start `gvid-`, or a non-scalar value. A stored `colors` group that holds entries (the sanitiser would erase them) refuses the call and names the cause. Any entry Divi's save would drop or damage refuses the call naming the ids, because such a loss could not be recorded in a batch.
6. **Write** exactly once with `GlobalData::set_global_variables( $fullMap )` (replace-all is Divi's contract, so a partial map would erase variables). Then **re-read** and report what is stored now, not what was sent. A change whose stored result differs from the intended one is reported as `unexpected_changes` and recorded with the stored state; if the permission check made Divi write nothing, the result says so and records nothing.

### Concurrency

Divi caches `get_global_variables()` for the rest of the request, and a save replaces the whole map, so two writers must not interleave. One site-wide `GlobalVariablesLock` (the build lock's atomic option row under its own prefix `aied_pro_buildlock_gvars_`, so `uninstall.php` already removes it; 30 s expiry), independent of the colors lock because the data lives in a different option:
- A real `manage_global_variables` run takes it **before the request's first read** and holds it until the read-back (busy: refused with "Another variable change is in progress", the token is not spent).
- `GlobalVariableKind` implements `SiteLockingKind`, so a real `undo_batch` takes it once per batch before its first read (the fix merged 2026-10-06; built in from the start here, not retrofitted).
- A dry run, including a dry-run undo, takes no lock. The lock cannot cover a save made in the Divi builder.

### Batch and undo

One batch per call (`BatchLog::record`), one item per changed variable, new kind `global_variable` (`pro-addon/src/Kinds/GlobalVariableKind.php`, registered like the others): `ref` = the `gvid`, `before` = the previous entry with its type (null for a create), `after` = the stored entry. `undo_batch` restores the previous entry; for a **created** entry it removes it, but only if it is exactly what the batch wrote (otherwise `changed_since`, even with `force`). The existing "changed since" rule applies, a batch is "fully undone" only when every item was restored, and `allowed()` re-checks `edit_theme_options` and `variables_manager` at undo time.

### Wiring and release

- MCP: registered in `Tools::all()`. REST: `POST /wp-json/ai-editor-divi5/v1/pro/manage_global_variables` through the existing `ToolRunner`/`ProRest`.
- `ProOpenApi::MCP_REST_ONLY` gets the second tool; `ProParityTest` and `ToolsTest` pin both exceptions with the reason.
- Add-on version 0.8.0: header, constant, `readme.txt` changelog, `pro-addon/README.md`, `make build-pro`. The free plugin's readme and UI are not touched in phase A.

### Phase B: teaching the AI to cite variables (gated)

Starts only after phase A is verified. Its first step is a live render spike on the Docker site: a throwaway published page citing a number variable and a string variable, to see (a) the form that renders, (b) what Divi emits, (c) what a cited id that does not exist does, (d) whether an archived variable still renders. The evidence is saved under `docs/` like the module-verification files. The style guides (`StyleGuide`, `SectionRecipes`, `LandingGuide`) then teach **only** the forms that passed, and ideally one real builder export the owner captures goes into `fixtures/valid/`. Any change to the guides ships in the free plugin as its own small release (own mini-spec) with every recipe re-validated. If a type cannot be cited reliably, the guides stay silent about it and the tool still ships.

## Testing

Unit (`make test-pro`, a Divi variables shim beside `DiviGlobalDataShims`):
- Argument typing for every field; the atomic refusals (unknown id, an id of another type group, a Customizer font id, duplicate id, duplicate type+label, empty list, more than 50, `type` on an update, `order` given).
- **Value-guard table per type:** numbers (`12px`, `1.5rem`, `clamp(1rem, 2vw + 1rem, 3rem)` accepted; `calc(1px; x)`, `url(x)`, `var(--x)`, braces, newline, `12px !important`, unknown unit, 101 characters refused) and strings (accepted text; each forbidden character class, `url(`, `@import`, 201 characters refused).
- Replace-all safety: after a create, every pre-existing entry in every group is identical in the map passed to `set_global_variables`, the Customizer fonts and `allowedActions` are not sent, and `order` is the type's highest + 1.
- Pre-flight: a stored non-empty `colors` group, an empty-label entry, a non-`gvid-` id and a non-scalar value each refuse with the ids named.
- Permission: a user without `variables_manager` (and a site where `et_pb_is_allowed` is missing) is refused up front; a Divi that silently writes nothing is reported, not claimed.
- Preview/real-run: no token, a token for other arguments, another user's token, an expired or spent token are refused; an entry changed since the preview is skipped and reported.
- Fail-closed when Divi's class or either method is missing, and when `set_global_variables` throws.
- Undo: restores an edit, removes a created entry, skips an entry changed since (even with `force` for a create), re-checks both permissions, and "fully undone" only when every item was restored.
- Lock: held before the first read of a real run and of a real undo, busy fails with no read or write, released after an exception, a dry run takes none.
- Parity: `ProParityTest`/`ToolsTest` updated for 16 Pro tools and both recorded ChatGPT exceptions; `ExpiryKeepsAllToolsTest` unchanged in behaviour.

Live gate `make verify-pro-global-variables` (Docker site, self-cleaning, dev site only, guarded by `scripts/dev-guard.php`): fingerprint all stored variables first; preview then create, edit and archive one number and one string variable; assert the stored values and sanitiser results are what the tool reported; assert a user without `variables_manager` is refused honestly; undo the batch; assert the stored variables are **byte-identical** to the start; leave nothing behind.

## Risks and decisions to confirm

- **Undo of a create removes the entry.** A page that began citing the variable after our write would lose it; the "unchanged since our write" rule does not see page usage. Same trade-off the owner accepted for colors; the preview and batch message say so.
- **A written variable is not "applied" anywhere** until a layout cites it; the result says so and points to `list_global_styles`. Citing is phase B.
- **String values are the least understood surface** (where Divi emits them is unproven); the guard starts conservative and the first plan task is the live render that decides it.
- **Unmanaged types are carried, not understood.** Images, links, fonts and gradients entries are passed back as Divi returned them; if their stored shape ever trips the pre-flight, the tool refuses instead of risking them.
- **Known limit:** the preview token is best effort against replay if an object cache evicts the spent-nonce transient (same as the other Pro tools).
