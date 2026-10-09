# Edit Divi Project layouts (design)

Date: 2026-10-08. Status: draft for owner review. Free plugin.

## Context

Sub-project **B** of the request to give the AI access to Divi's Projects and to custom fields (A, `get_custom_fields`, is merged on local main; C, writing field values, comes later).

Today the AI can only read and edit **pages**. `EditTarget::resolve()` (`wp-plugin/src/EditTarget.php`) is the single rule behind
`get_page_layout`, `update_page_layout`, `edit_page_content`, the three history tools, the matching REST routes, the admin Restore gate and `HistoryService::recent()`, and it accepts only `post_type == page`. `list_divi_pages` and `GET /pages` query `post_type => 'page'` only, so Divi Projects are invisible and untouchable.

## Goal

The AI can list, read, validate, preview (dry run), save, undo and restore the layout of a **Divi Project** exactly as it does for a page, behind the same validator gate (`LayoutGate`), history snapshot, dry run and approval mode, and nothing else.

Success: for a Project the user can edit, every page tool and route works on its ID; a blog post, an attachment or any other type is still refused; a user who may not edit the Project is refused like for a page; nothing about the gate is weakened.

## Decisions (owner, 2026-10-08)

- Types: **page and project only**, a fixed allowlist. Not "every type Divi's builder is enabled on", not blog posts, no filter for other types yet.
- No new tool. `create_page` stays page-only (Projects are not created by the AI).

## Design

### 1. `EditTarget` allowlist

- New `public const ALLOWED_TYPES = [ 'page', 'project' ];`
- `resolve( int $id ): array{post: ?\WP_Post, kind: ?string, error: ?string}` keeps its signature and the constants `NOT_FOUND = 'not_found'`, `FORBIDDEN = 'forbidden'` (the Pro add-on's `FreeApiCompatTest` pins both).
- A post whose type is in `ALLOWED_TYPES` and that the user can `edit_post` resolves; `kind` is the post type (`'page'` or `'project'`). Any other type is `NOT_FOUND` (checked before the capability, so nothing leaks about other types). No `edit_post` is `FORBIDDEN`, as today.
- A Project whose post type Divi has switched off is unregistered; WordPress then denies `edit_post`, so it is refused. No special code.
- Docblocks and the class comment say "page or Divi Project", not "page".

### 2. Handlers

No logic change in `McpHandler` / `RestController` for get, update, edit, history list/entry and restore (`validate_layout` takes content, not an ID, and is unaffected): they all call `EditTarget::resolve()` and use the post ID, `HistoryService` (keyed by post ID, no type check) and `LayoutGate`. Changes are wording only:
- `page_id` and the `/pages/{id}` routes keep their names (compatibility); tool descriptions, parameter descriptions and not-found/forbidden messages say "page or Divi Project".
- `get_page_layout` and the layout envelope already report `post_type`.
- `HistoryService::recent()` and the admin Recent-edits panel include Projects automatically (they filter through `EditTarget::resolve()`).

### 3. Discovery

- `list_divi_pages` (MCP), `GET /pages` (REST) and `listDiviPages` (OpenAPI) get an optional `post_type`: `page` (default) or `project`. Anything else (a non-string, any other string) is rejected: REST 400 `invalid_field`, MCP -32602, never cast; the allowed values come from `EditTarget::ALLOWED_TYPES`.
- The query is the same as today with `post_type` set from the argument (still `_et_pb_use_divi_5 == on`, at most 100, only posts the user can `edit_post`).
- Each listed item gains `post_type`. The response key stays `pages` and `count` (compatibility).
- It is a parameter on an existing operation: the tool count stays 18 and the ChatGPT importer's 30-operation budget is untouched. The three surfaces stay in step (`McpHandler`, `RestController`, `OpenApiSpec`).

### 4. Unchanged on purpose

`create_page` (pages only, always a draft); the validator (`src/` and `wp-plugin/validator/`); the Pro add-on's own page tools and its code; `FieldTarget` (already covers Projects for custom fields); approval mode, dry run and history storage (they key on the post ID).

### 5. Safety and compliance

Every Project write still passes `LayoutGate::check()`, takes a history snapshot first and honours dry run and approval mode (`PREVIEW_REQUIRED`). A user without `edit_pages` is still refused entirely. No settings writes, no remote calls, no custom-code saving; `WpOrgComplianceTest` is not weakened and `NoThemeLayoutsInFreeTest` still holds (no Theme Builder types are added).

### 6. Docs and wording

Tool descriptions and the readme say "page or Divi Project" where they say "page" about reading, editing and undo; `CLAUDE.md` (the `EditTarget` paragraphs: "page-only") is updated. No version bump, changelog or zip (release is the owner's step).

## Testing

- `EditTargetTest`: `project` resolves (and reports `kind: 'project'`); `post`, `attachment`, Theme Builder types, unknown types and ids 0/negative/missing stay `NOT_FOUND`; no `edit_post` on a Project is `FORBIDDEN`; the allowlist constant is exactly `['page','project']`.
- Handler tests (MCP and REST, like `DryRunHandlersTest` / `ApprovalModeHandlersTest` / `HistoryLockstepTest`): a Project goes through get, update (invalid layout refused with violations), `edit_page_content`, `dry_run` (page untouched), approval mode (`PREVIEW_REQUIRED` then success after a valid preview), history snapshot and restore; a blog post id gives not found on every one of them.
- `list_divi_pages` / `GET /pages`: default is pages; `post_type=project` lists Projects the user can edit; bad values rejected (400 / -32602, including non-strings); items carry `post_type`; the OpenAPI spec documents the parameter and its enum (`OpenApiSpecTest`).
- Pro compat: `make test-pro` stays green (`FreeApiCompatTest` unchanged).
- Live gate `make verify-project-layouts` (Docker site, which has the `project` type; self-cleaning; dev site only): one throwaway draft Project; list with `post_type=project`; get; dry run leaves it untouched; save through the real handlers; history snapshot exists; restore brings the old content back; an invalid layout is refused; a throwaway blog post is refused by every tool; everything is deleted afterwards.
- `make test` and `make test-pro` exit 0.

## Out of scope

Creating Projects; blog posts or any other type; the Divi "enabled post types" setting; Pro tools for Projects (`find_replace_site`, `audit_site`); renaming `page_id` or `/pages`; Project taxonomies (categories, tags); the `jhmg_aied_*` filter for extra types (can be added later without changing this design).

## Open points

None blocking.
