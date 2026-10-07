# Read custom fields: `get_custom_fields` + field discovery (design)

Date: 2026-10-07. Status: draft for owner review. Free plugin, read-only.

## Context

This is sub-project **A** of a request to give the AI access to Divi's Projects and to any custom field:

| # | Sub-project | Status |
|---|---|---|
| A | Discover and read custom fields (this spec) | specifying |
| B | Edit Divi layouts of Projects (`EditTarget` accepts the `project` post type) | later, own spec |
| C | Write custom field values (free vs Pro undecided; own safety design) | later, own spec |

Today the plugin never reads a custom field value, and `get_site_structure` lists ACF field names only. A layout can
already bind a module to a custom field (4.7.0), but the AI cannot see non-ACF keys or what a field holds on a given post.

## Goal

The AI can (1) see which custom field keys a site really uses, ACF or not, and (2) read the values on one post it is allowed
to edit, so it can write copy that fits and bind modules to keys that exist.

Success: for any editable post (page, Project or other public type) the AI gets its non-protected fields with values; a
protected, secret-looking or other-user's field never reaches it; nothing is written.

## Design

### 1. `get_custom_fields` (MCP + REST, read-only)

- Input: `id` (integer, required; a non-integer is rejected 400 / -32602, never cast), `keys` (optional list of strings that
  restricts the answer; a non-list or non-string entry is rejected the same way). MCP takes a list only and rejects a string
  `keys`; REST also accepts the comma separated query string.
- Target rule (new `FieldTarget`, kept apart from `EditTarget`, which stays page-only until B): the post exists, its type is a
  public post type, and the current user has `edit_post` on it. Anything else, including a post the user cannot edit, answers
  exactly like a missing post (`not_found`: 404 / -32602, same message), so nothing leaks about other posts.
- Output: `{ id, post_type, fields: [{ key, value, acf: { label, type } | null }], withheld: <int>, truncated: <bool> }`.
  `acf` is filled from ACF's own read functions behind `function_exists` guards (reusing the `CustomFields` ACF reader); a
  missing reader means `acf: null` everywhere, never an error.
- Values come from `get_post_meta()` only. Arrays and objects are returned as JSON.
- Authorisation as every other tool: accepted API key, its user needs `edit_pages`, then `edit_post` on the target.
- Logged by `UsageTracker` as endpoint `custom_fields`, result `read` (`error` for a refusal or failure).
- REST route: `GET /wp-json/ai-editor-divi5/v1/posts/{id}/custom-fields` (optional `keys` query parameter, comma separated).
- NOT in the ChatGPT OpenAPI spec: the importer's 30 operations are used up (same exception as `get_site_structure`;
  `SiteStructureLockstepTest` gets a matching pin).

### 2. Safety rules (one class, `CustomFieldReader`, pure and testable)

- Never returned: keys starting with `_`, and keys for which `is_protected_meta( $key, 'post' )` is true (ACF `_field`
  reference rows, `_edit_lock`, `_wp_*`).
- Withheld and counted in `withheld`: keys that look like secrets (case-insensitive match on `password`, `passwd`, `secret`,
  `pwd`, `token`, `api_key`/`apikey`, `private_key`, `access_key`, `auth_key`, `credential`, `license`/`licence`, and a standalone
  `pass` delimited by start/end/`_`/`-`, so `smtp_pass` is withheld but `passage`, `compass` and `bypass_url` are not), and every key
  ACF says is a `password`-type field, whatever its name (its value is never loaded; discovery does not list it). This is a heuristic; the real protection is the per-post `edit_post`
  check, and the AI acts as the key's user.
- A failure while reading (an unencodable value is treated as cut; anything thrown is caught) answers a clean -32603 / 500
  "Could not read the custom fields." and is logged as an error.
- Caps: 100 fields per call, each value cut at 500 characters (`truncated: true` when anything was cut or dropped).
- No writes, no remote calls, no raw SQL. The free plugin has no code that calls `update_post_meta`, `add_post_meta`,
  `update_field`, `update_metadata` or `add_metadata` (the compliance test pins this; the old history migration's
  `delete_post_meta` of its own legacy key is the only meta delete and stays).
- A key stored several times on a post returns its first value (`get_post_meta( $id, $key, true )`); WordPress itself
  unserializes it, the plugin never calls `unserialize`.

### 3. Discovery in `get_site_structure`

- New section `custom_fields` (added to `SiteStructure::SECTIONS` and the `include` enum): for each public post type except
  attachments (media meta is noise), the keys
  seen on the newest 20 posts of that type (via `get_posts` + `get_post_meta`, no `SELECT DISTINCT`), each as
  `{ key, posts: <count of the sample that have it>, acf: { label, type } | null }`, at most 100 keys per type.
- Keys only, never values. The same protected and secret-looking filters apply.
- Only posts the current user can `edit_post` are sampled.
- Why sampled: a whole-table distinct query is expensive on big sites and triggers Plugin Check warnings; a sample answers
  "what do posts of this type use" well enough, and the note in the output says it is a sample.

### 4. Guide

`StyleGuide` ACF section gets one paragraph: call `get_custom_fields` to read a post's values and to find non-ACF keys,
then bind with the same `custom_meta_<key>` form. **That the form renders for a plain (non-ACF) meta key is not yet
proven**; the paragraph ships only after `make verify-custom-fields` renders it live on Divi 5.14 (evidence file
`docs/custom-field-plain-meta-evidence-5.14.md`). If the render fails, the guide teaches binding for ACF fields only and
says so.

### 5. Counts and docs

Tool count 17 → 18 in `WpOrgComplianceTest` (tool list, readme claims), `wp-plugin/readme.txt`, `CLAUDE.md`, the admin
Features cards and `docs/`. The divi5lab.com copy (layoutlab repo) is updated at release, not here.

## Testing

- Unit (`tests/`, WP shims): protected and `_` keys dropped, secret-looking keys withheld and counted, 100-field and
  500-character caps, `keys` filter, JSON for arrays, ACF join with and without ACF, `FieldTarget` (missing post, non-public
  type, no `edit_post`), discovery sampling and its filters, input type checks (400 / -32602).
- MCP and REST handler tests for the new tool/route, plus the lockstep pin for the OpenAPI exception.
- `WpOrgComplianceTest`: 18 tools; no meta-write functions anywhere in `wp-plugin/`.
- `make verify-custom-fields` (new, Docker site, self-cleaning: one throwaway draft page with a plain meta key and a protected
  key): proves the read tool and discovery on the real database, and the plain-meta render for §4.
- `make test` stays green; Plugin Check on the dev copy and the extracted zip before any upload.

## Out of scope

Writing any field value (C); editing Project layouts (B); ACF Pro repeater/flexible-content values beyond their raw stored
rows; term meta and user meta; a whole-site field dump; showing values to ChatGPT.

## Open points

None blocking. The secret-key word list and the 20-post sample size are tunable constants.
