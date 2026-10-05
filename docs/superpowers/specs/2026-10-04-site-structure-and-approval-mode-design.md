# Site structure tool, approval mode and protocol fixes (free 4.6.0)

Date: 2026-10-04. Source: `docs/feature-requests-2026-10-04.md` (items 2, 3, 5, 6 and the Gemini review notes).
Status: design, awaiting owner review. Nothing here is built yet.

## Goal

Let an AI assistant plan layouts that fit the site it is editing (its custom post types, taxonomies, image
sizes and ACF fields), give cautious owners a switch that forces a preview before every save, publish honest
token numbers, and fix two small MCP protocol deviations. Everything stays read-only or opt-in, and every
change respects the WordPress.org compliance rule (no remote calls, no licence gating, no code or settings
written by the AI).

Out of scope: Pro items (#1, #7, #8, #10), third-party blocks (#9, parked until there is a real export),
binding Divi dynamic content to ACF fields (needs a real export first), reading `functions.php` or any
file source, and listing menus.

Release: the unreleased 4.5.1 setup-docs work and this work ship together as **4.6.0** (a new tool).
The owner uploads it; the version is bumped from 4.5.1 to 4.6.0 and the changelog entries are merged.

## 1. `get_site_structure` (17th free tool, read-only)

**What it returns.** One JSON object, each section optional via `include` (a list of any of
`post_types`, `taxonomies`, `image_sizes`, `acf`; default all):

- `post_types`: public post types, each `{name, label, supports[], taxonomies[], has_archive, hierarchical}`.
- `taxonomies`: public taxonomies, each `{name, label, object_types[], hierarchical}`.
- `image_sizes`: `{name, width, height, crop}` for every registered size.
- `acf`: `{active: bool, groups: [{key, title, location_summary?, fields: [{name, label, type}]}]}`.
  Field `choices` are included for select-like types (they are structure, not content). No post values.
  If ACF is not active: `{active: false, groups: []}` plus a note.

**How it reads.** Only WordPress and ACF public read functions (`get_post_types`, `get_taxonomies`,
`wp_get_registered_image_subsizes`, `acf_get_field_groups`, `acf_get_fields`), each behind
`function_exists` guards, the same pattern as `GlobalStyles`. A missing or throwing reader gives an empty
section plus a note, never an error. Lists are capped (100 items per section, field `choices` capped at 50)
with a `truncated` flag.

**New class** `wp-plugin/src/SiteStructure.php` (pure functions over injected readers where possible, so it
is unit-testable with the existing WP shims).

**Authorisation.** `PageAccess::CAP` (`edit_pages`), as `list_global_styles`. `include` is type-checked
(non-array or non-string entries: -32602 / 400, never cast).

**Wiring.** MCP tool + dispatch (`McpHandler`), REST `GET /site-structure` (`RestController`,
`require_edit_posts`), logged to `UsageTracker` as a read. Guides: `StyleGuide` gets one sentence telling the
AI to call it before building anything that lists custom post types or uses images at a given size.

**ChatGPT exception (owner decision 2026-10-04).** The importer allows 30 operations and the free (16) plus
Pro (14) tools already use all 30, so this tool is deliberately **not** in `OpenApiSpec`. The lockstep test for
this tool asserts MCP and REST parity and asserts its absence from OpenAPI with the reason named. The
CLAUDE.md "MCP, REST and OpenAPI in lockstep" rule is updated to record the exception.

**Compliance.** No remote calls, nothing written. `WpOrgComplianceTest`: the pinned tool list gains
`get_site_structure` (17 tools), the readme "(16 tools)" count becomes 17, and the readme and the Features tab
describe it as read-only. No rule of the test is loosened.

## 2. Approval mode (setting "Require a preview before every save")

**Setting.** Option `ai_editor_divi5_require_preview` (boolean, default off, autoload off), toggled on the
Settings tab by a nonce-protected `admin_post` handler (`manage_options`), shown on the Features tab as
"Approval mode". Removed by `uninstall.php`.

**Behaviour when on.** `update_page_layout`, `edit_page_content`, `PUT /pages/{id}` and
`POST /pages/{id}/edit` refuse a real save (not a dry run) unless the exact resulting content was previewed
successfully by the same user for the same page in the last 30 minutes. A refusal is a normal tool error that
tells the AI to call again with `dry_run: true` first (code `PREVIEW_REQUIRED`).

**Mechanism.** A successful, valid `dry_run` stores a transient keyed by
`sha256(user_id | page_id | sha256(resulting_content))`. A save recomputes the key, requires the transient,
and deletes it (single use). Resulting content is what the gate already computes, so `edit_page_content`
works without a second find-and-replace.

**Not covered, on purpose.** `create_page` (always a draft), `restore_page_version` (it is the undo), and the
Pro add-on's own write tools (they carry their own preview tokens and batch undo). The check lives in one
helper, `ApprovalMode::require()`, called from the four free entry points; it is not placed in
`HistoryService::write()`, because the Pro add-on writes through that and would break silently.

**Dry run itself is unchanged**: it still never touches the page, its history or `post_modified`.

## 3. Token numbers (#6)

A dev-only script `scripts/measure-tokens.php` (not in the plugin zip) measures, against the validator and
handlers with fixtures: the `tools/list` payload, a typical page-edit round trip (`get_page_layout`,
`edit_page_content`) and a typical refusal (violation response size). It reports bytes and an approximate
token count (bytes / 4, labelled as an estimate), writes `docs/token-usage.md`, and the readme FAQ states only
what was measured ("the tool list is about N thousand tokens"). It changes no plugin behaviour. If a violation
response is found to be long, shortening it is a separate small fix, noted in the doc.

## 4. MCP protocol fixes

- `ping` returns `{}` (an empty JSON object, via `new \stdClass()`), not `[]`.
- `notifications/initialized` returns HTTP 202 instead of 204.

Existing tests that pin 204 are updated, with a new test for each.

## Testing

- `SiteStructureTest`: each section with synthetic readers, ACF absent/present/throwing, caps and truncation,
  type checks on `include`.
- Lockstep test (MCP + REST present, OpenAPI absent by design).
- `ApprovalModeTest`: off = no change; on = refusal without preview, success after a valid preview,
  single use, wrong user / page / content refused, invalid preview does not unlock, `create_page` and
  restore exempt, dry run still side-effect free.
- Protocol tests for `ping` and the 202.
- `make test`, then a live check on the Docker site (a throwaway verify script in the style of
  `verify-global-styles`, self-cleaning) for `get_site_structure` and approval mode over both transports,
  then Plugin Check on the dev copy and the extracted zip.

## Risks and decisions to confirm

- Approval mode adds state (a transient per preview). Transients can be evicted by an object cache, which only
  makes the AI preview again; it never lets an unpreviewed save through.
- ACF field `choices` are structure, but a site could put sensitive wording in them; the 50-item cap and the
  `edit_pages` requirement are the guard. Say so if you want choices left out entirely.
- Menus are left out of the structure tool (the readme rule bans the word outside one FAQ sentence, and
  reading them adds review risk for little value).
