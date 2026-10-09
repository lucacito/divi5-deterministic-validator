# Write custom field values: Pro tool `set_custom_fields` (design)

Date: 2026-10-09. Status: draft for owner review. **Pro add-on** (`pro-addon/`), not the free plugin.

## Context

Sub-project **C** of the request to give the AI access to Divi's Projects and to custom fields. A (`get_custom_fields`, read-only) and B (Project layouts) are merged on local main.

The free plugin must not write post meta: `tests/WpOrgComplianceTest.php::testTheFreePluginNeverWritesPostMeta` bans `update_post_meta`, `add_post_meta`, `update_field`, `update_metadata` and `add_metadata` in `wp-plugin/`, and WordPress.org rejected "custom code through layouts" on 2026-10-02 (a field value can carry HTML/JS that a theme or Divi dynamic content outputs). So writes live in the separate Pro add-on, which already has the machinery for safe writes: `ProTool`, `ItemKind`/`ValueKind`, `BatchLog`, dry run and `undo_batch` (see `SetFrontPage`, `SetCustomCss`).

## Decisions (owner, 2026-10-09)

- Lives in the **Pro add-on**; the free plugin stays read-only for fields and its meta-write ban test is untouched.
- Writable keys: **keys the site knows** (an ACF field, or a key at least one post already has; the rule behind the 4.7.0 unknown-custom-field advisory). The AI cannot invent keys.
- Target posts: **any public post type the user can edit** (the same reach as `get_custom_fields`: pages, Divi Projects, blog posts, WooCommerce products and other custom types).

## Goal

The AI can set custom field values on one post it may edit, safely: previewed with `dry_run`, validated, never on protected or secret-looking keys, never with custom code, and undoable in one step.

Success: a valid call writes exactly the listed keys and records one batch that `undo_batch` reverses; any refused field means nothing is written and every reason is reported; undo refuses a value that was changed since; the free plugin is unchanged in behavior.

## Design

### 1. Tool `set_custom_fields` (Pro, MCP + REST only)

- Class `AiEditorDivi5\Pro\Tools\SetCustomFields implements ProTool`; `NAME = 'set_custom_fields'`; registry capability `edit_posts` (the free plugin additionally requires `edit_pages` for the API key's user, and the per-key and per-post checks below decide the rest).
- Input: `id` (integer, the post), `fields` (a non-empty list of `{ key: string, value: string }` objects, at most 25; no duplicate keys), `dry_run` (boolean). A free-form object is not used (strict tool schemas dislike free keys). Every argument is type-checked, never cast.
- Output: `{ changed: bool, dry_run: bool, post: { id, title, post_type }, fields: [ { key, before, after, status } ], message }` plus the batch attachment (`BatchLog::attach`) when written. `before`/`after` are cut at 200 characters in the answer.
- MCP + REST only: add `'set_custom_fields'` to `ProOpenApi::MCP_REST_ONLY` (the 16 free + 14 Pro operations already use all 30 the ChatGPT importer accepts).

### 2. Validation, all-or-nothing

If any field is refused, nothing is written and every refusal is reported with its key and reason.
1. **Target:** the free `FieldTarget::resolve( $id )` (any public type the user can `edit_post`); missing, non-public or not editable all answer `Post N not found.` (no leak).
2. **Key** (per field):
   - spelling: printable ASCII only (bytes 0x20-0x7E), at most 191 characters, no leading or trailing whitespace and no control characters. This closes accent and case aliasing, because WordPress's default collations match `meta_key = %s` case- and accent-insensitively, so a look-alike key could otherwise reach another key's row or slip past the secret-looking check;
   - shown: `CustomFieldReader::classify( $key ) === CustomFieldReader::SHOWN` (so never `_`-prefixed, WordPress-protected, or secret-looking);
   - known: the free `CustomFields::lookup()` closure returns true (an ACF field, or at least one post has the key);
   - allowed by WordPress: `current_user_can( 'edit_post_meta', $id, $key )` (honours `register_meta` `auth_callback`s);
   - single-valued on this post: a key stored more than once on the post is refused;
   - exact case: a key that differs only by letter case from a key the post already has, from an ACF field name or from an ACF prefix is refused ("use the exact key"). Accepted residual: a mis-cased NEW key on a post that lacks the base key can still be created.
3. **Value** (strings only):
   - at most 10,000 characters;
   - no custom code: new Pro class `FieldValuePolicy` refuses a value containing a `<script`, `<style`, `<iframe`, `<object`, `<embed` or `<?` sequence, a `javascript:`, `vbscript:` or `data:text/html` URL, or an HTML event-handler attribute (`on[a-z]{3,}=`); these are the same kinds of code the free plugin refuses in layouts, applied to plain text (the free `CodePolicy` itself scans Divi layout markup and is not reused);
   - no silent sanitizing: the field is refused if `wp_kses_post( $value )` differs from `$value`, with a bare `&` normalised to `&amp;` on both sides before comparing (core's kses rewrites a bare ampersand, which is not unsafe markup, so `Terms & Conditions` is accepted). The stored value is always exactly what was sent, never the kses output;
   - ACF types, when the key is a known ACF field (`CustomFieldReader::acfInfo()` gives the type; choices come from ACF's field definition): `number`/`range` must be numeric; `select` (single value only), `radio` and `button_group` must be one of the field's choices; `true_false` must be `0` or `1`; the plain-string types are `text`, `textarea`, `wysiwyg`, `url`, `email`, `date_picker`, `date_time_picker`, `time_picker` and `color_picker`; any other ACF type is refused with "edit this field in the ACF editor": `password` (a secret), `checkbox` and multi-value `select` (they store lists), and repeater, group, flexible content, relationship, post object, image, file, gallery, taxonomy, user, map and the like. A key that is not an ACF field is a plain string.

### 3. Write

- When ACF is active and the key is an ACF field, write with ACF's `update_field( $key, $value, $id )` (so ACF's `_key` reference row is kept); otherwise `update_post_meta( $id, $key, $value )`. Both calls exist only in the Pro add-on.
- The value is slashed before it is handed to WordPress on BOTH paths: `update_post_meta` unslashes what it is given, and ACF's `update_field` unslashes like core (verified live on ACF 6.8.10), so a backslash or quote would otherwise be corrupted.
- After each write read the raw value back; a mismatch (or a write that returns false) is reported as a failed field. The failing field is rolled back too (a refused write may still have stored something), together with the already-written fields of the call, with the same restore code undo uses, so a call is atomic. Every restore result is checked: if a restore fails, the error says which fields "may not have been restored". Any throwable raised inside a write is rolled back the same way.
- A batch that cannot be recorded after successful writes leaves the writes in place and reports no batch (`BatchLog::attach` adds the batch to the answer only when one was recorded).
- `dry_run: true` runs every check and returns the per-field before/after preview; nothing is written and no batch is recorded. No preview token is required (one post, at most 25 fields; `dry_run` plus batch undo is the control, as for `set_custom_css`).

### 4. Undo

- New item kind `PostMetaKind` (`name() === 'post_meta'`, registered with the other kinds in `Batches`): `ref` is `"{postId}:{key}"`; `before` is `{ exists: bool, value: ?string }`; `current()` is the raw meta value (null when absent); undo restores the old value (ACF-aware, same write path) or deletes the key when the call created it; `allowed()` re-checks `edit_post_meta` and `edit_post` at undo time.
- The existing `after_hash` rule refuses undo when the value changed since (`undo_batch` reports it, as for other kinds); "fully undone" only when every item restored.

### 5. Free-plugin and Pro compatibility

- Free plugin behavior is unchanged.
- Pro now calls these free classes: `FieldTarget`, `CustomFieldReader`, `CustomFields`. Add them to `Compat::REQUIRED_CLASSES` and pin their signatures in `pro-addon/tests/FreeApiCompatTest.php`. `Compat::REQUIRED_CLASSES` (the runtime guard: the Pro tools stay off if a class is missing) gains the three classes. `Compat::MIN_FREE` is NOT changed in this branch (the dev site still reports the pre-release free version, which would switch the Pro tools off); the owner raises it, to the first free version that contains these classes, when versioning the free release.
- Docs: `pro-addon/README.md` and `pro-addon/readme.txt` list the new tool; `CLAUDE.md` mentions it under the Pro add-on. No Pro version bump, changelog, zip or licence-server change (release is the owner's step).

## Testing

- Unit (Pro suite, `make test-pro`): `FieldValuePolicy` (each refused pattern, clean text, entities, long value, UTF-8), `SetCustomFields` (every refusal in §2 with the exact reason; all-or-nothing: one bad field writes none; dry run writes nothing and records no batch; a valid call writes exactly the listed keys and records one batch; duplicate keys; 26 fields; non-list `fields`; non-string value; non-integer id; a key stored twice; ACF type checks with a fake ACF reader; the ACF write path vs plain path via injected writers; rollback when a read-back mismatches), `PostMetaKind` (restore an existing value, delete a key the call created, refuse after a change, `allowed()` re-check), registry/REST parity (`ProOpenApi::MCP_REST_ONLY` contains the tool and the OpenAPI spec does not).
- Each new refusal/atomicity test is shown failing first (or reasoned through if it cannot run without the code).
- `make test` stays green (free suite) and `FreeApiCompatTest` pins the new free signatures.
- Live gate `make verify-pro-custom-fields` (Docker site; free + Pro active; dev site only; self-cleaning): throwaway page with a known plain key; dry run leaves it untouched; a valid write changes exactly that key; `get_custom_fields` (free) reads the new value; refusals (unknown key, `_`-key, secret-looking key, script value, kses-changing markup, 26 fields, duplicate key, a post the user cannot edit) change nothing; undo restores; undo after an outside change is refused; a call that creates a key is undone by deleting it. When the free ACF plugin is active (installed for the run, removed after, as in `verify-acf-custom-fields`), also: an ACF text field, a select with an invalid choice (refused), and a repeater (refused). Without ACF these are SKIP lines.

## Out of scope

Deleting a field (setting it to an empty string is allowed); arrays, repeaters and other complex ACF types; term and user meta; creating new keys the site does not know; a free-plugin write tool; ChatGPT parity; a preview token; bulk writes across posts.

## Open points

None blocking. The 25-field and 10,000-character limits and the `FieldValuePolicy` pattern list are tunable constants.
