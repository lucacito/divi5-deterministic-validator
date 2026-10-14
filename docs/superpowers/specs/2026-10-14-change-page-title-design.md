# Change an existing page's title (free plugin 4.11.0)

Date: 2026-10-14. Status: approved by the owner's delegation ("decide the open questions yourself with a
recommendation, since I am not available"). Every decision below is marked **Decision** with its reason.

## Problem

A user asked the AI to change a page title and nothing happened. Today no tool can change the title of an existing
page:

- `update_page_layout` and `edit_page_content` end in `HistoryService::write()`, which calls `wp_update_post` with only
  `ID` and `post_content`.
- Only `create_page` sets a title, and only on a new draft.
- The AI may also have edited the `h1` inside the layout and reported that as a title change. That leaves the real
  WordPress title (the one in the page list, usually the browser tab, and search results) unchanged.

## Goal and success criteria

1. The AI can change the WordPress title of an existing page or Divi Project, and nothing else, in one call.
2. It can change title and layout in one call.
3. The change goes through the same safety nets as every other write: permission re-check, strict argument types,
   `dry_run`, approval mode.
4. Every response says plainly what changed, what the old title was, and what can not be undone.
5. The AI is taught that the WordPress title and the layout's `h1` are different things.
6. The tool count, the MCP tool list and the ChatGPT importer's 30 operations stay exactly as they are.

## Scope

In: an optional `title` argument on `update_page_layout` (MCP) and `PUT /pages/{id}` (REST) with the matching OpenAPI
property; pages and Divi Projects (`EditTarget::ALLOWED_TYPES`); `dry_run`; approval mode; guides; docs.

Out:
- **Slugs.** Changing a slug changes the page's address. That affects links already shared, links typed into other
  pages' content, bookmarks and search results, and whether a redirect from the old address exists depends on the page
  being published and on core behaviour this release does not verify. Whether the plugin should ever change a slug,
  and what it must do about links, is a separate decision for the owner. `title` never touches `post_name`.
- Any Pro change. Pro tools that write through `HistoryService::write()` keep working: its signature only gains an
  optional last parameter.
- A new tool (importer budget) and any change to `edit_page_content` (see below).
- Blog posts and other post types (still refused by `EditTarget`).

## Decisions

### D1. `title` lives on `update_page_layout` / `PUT /pages/{id}`, not on `edit_page_content`

`edit_page_content` is an exact find/replace on stored layout text; `find` and `replace` are required and the tool's
whole contract is "change a spot inside the layout". A title is not inside the layout. Mixing the two would give a call
with two unrelated jobs and unclear error behaviour (what if `find` is not found but the title is valid?).
`update_page_layout` already means "write this page" and gains a second, optional thing to write. **Decision:** only
`update_page_layout` / `PUT /pages/{id}` take `title`. `edit_page_content` / `POST /pages/{id}/edit` do not. A
find/replace plus a rename is two calls.

### D2. A title-only call is allowed

The user asked for exactly this. `post_content` becomes optional when `title` is present:

| `post_content` | `title` | Meaning |
|---|---|---|
| given | absent | exactly today's behaviour |
| given | given | save layout and title together (one `wp_update_post`) |
| absent / null | given | title-only: the layout is not read, validated, snapshotted or written |
| absent / null | absent | error: 400 `missing_field` / -32602 "Give post_content, title, or both." |

An explicit `post_content: ""` is still refused as before (a mistyped empty layout must never be read as "title only").
MCP's `required` list drops `post_content` (keeps `page_id`); the descriptions say one of the two is needed. The
OpenAPI body drops `required`, and its description says at least one of `post_content` or `title` is required (OpenAPI
`anyOf` is not used: the ChatGPT importer's support for it is not something this project has verified).

**Layout gate.** `LayoutGate` guards layout content. A title-only call writes no layout, so the gate is not run (a page
whose existing layout is invalid, or a classic-editor page, can still be renamed). The title has its own gate, `PageTitle`
(D3). This is stated in the result: `layout_changed:false`.

### D3. Validation of the title (`PageTitle`, a new pure class)

Rules, applied in this order; every failure is an *input* error (400 `invalid_field` / -32602), never a validator verdict:

1. Not a string (number, bool, array, object): rejected, never cast. `null` means "not given".
2. Not valid UTF-8: rejected.
3. Trimmed (Unicode whitespace and NBSP at both ends). Empty after trimming: rejected ("The title must not be empty.").
4. Contains a control character (newline, tab, NUL, other C0/C1): rejected. A page title is one line.
5. Contains `<` or `>`: rejected ("The title is plain text: no HTML or markup."). Chosen over stripping, because
   silently dropping characters would make the saved title differ from what the AI reported.
6. Longer than **200 characters** (characters, not bytes): rejected.

**WordPress's own filtering still applies after this**, and is left alone: `wp_update_post` runs core's
`sanitize_post`. For a user without `unfiltered_html` (an Editor on a multisite, for example) core's `wp_filter_kses`
filters the title on save, which among other things normalises a bare `&` to `&amp;`. The tool never assumes the stored
title equals the submitted one: after the write it **re-reads the post** and reports the stored value as `title.after`.
Quotes and backslashes are passed through `wp_slash` like the content (a title with `"` or `\` round-trips).

### D4. Undo: the title is NOT restorable (option b), and the result says so

**Chosen: (b) a title change is not undoable through `restore_page_version`; every result says so, and carries the old
title so the AI can tell the owner and set it back with one more `title` call.**

Why not (a), storing the previous title in the snapshot:

- It needs a schema change to the history table (a `title` column) and a staged migration for installs that are
  already at `STAGE_MIGRATED` (the 4.1.0 table move is the precedent for how careful that must be), plus a Pro-visible
  semantic change to `HistoryService::restore`, which Pro's batch undo calls.
- The dedupe rule is "same content hash as the newest snapshot = duplicate", so a title-only change would store
  nothing; it would need a separate rule.
- A title-only change would have to snapshot the **entire layout** (up to 512 KB) just to remember a 40-character
  string. The per-page budget is 10 versions / 768 KB, so a handful of renames of a big page would push the owner's
  real layout history out.
- Restore would silently rename pages: if the owner renamed the page by hand after the AI's change and then restored an
  older layout version, the hand-made rename would be reverted without a word.

Why (b) is honest and enough: the title is short and fully visible in the response (`title.before`), so undoing a rename
is one more call, and no information is lost.

Behaviour that follows:

- `history` in a title-only save is `{stored:false, version_id:null, reason:"title_only"}`: there was no layout to
  snapshot (nothing about the layout changed). A save with layout **and** title snapshots the layout exactly as today.
- Every result that changed a title carries
  `title: {changed:true, before, after, restorable:false}` plus a `message` line:
  "The page title is not part of the saved versions: restore_page_version will not bring the old title back. The old title was \"X\"; to undo, call again with that title."
- `restore_page_version` / `POST /pages/{id}/restore` results gain `title_restored:false` and `title_note`:
  "Restoring brings back the page content only. The page title was left as it is: \"<current title>\"." This is
  added to **every** restore, so a restore never leaves the title in a state the AI has not been told about.
- `list_page_history` is unchanged.

### D5. `dry_run`

A dry run with `title` returns the existing preview plus:

```
"title": {"changed": true, "before": "Old", "after": "New", "restorable": false}
```

`after` is the normalised (trimmed) submitted title; the real result's `title.after` is the re-read stored value.
The layout diff keeps every existing cap (50 changes, 25 attributes per block, 200 characters per value). With no
`post_content`, the preview has `layout_changed:false`, `valid:true`, `violations:[]` and `diff:null`, since the layout
is not part of the change (`valid` describes the layout that would be saved; no layout, nothing to refuse).
Without `title`, the response is byte-identical to 4.10.0 (pinned by the existing tests). Nothing is written, no
history row is touched, `post_modified` is untouched.

An unchanged title (the submitted title equals the stored one) is a normal success: `title.changed:false`, nothing
saved (`saved:false`), message "The page title is already \"X\". Nothing was changed." It is not an error: the wished
state already holds.

### D6. Approval mode

The previewed result must include the title. `ApprovalMode::key()` hashes `(user, page, content, base)`. For a call with
a title, both `content` and `base` are extended with an unambiguous title suffix (`ApprovalMode::withTitle()`), so:

- the key is **unchanged** for calls without a title (existing previews, tests and behaviour stay as they are);
- a preview of "title A" cannot authorise saving "title B", nor a layout-only save, nor a layout-only preview a
  layout-plus-title save;
- if the page's title changed since the preview (the base includes the stored title), the preview is void;
- a title-only change is covered the same way: it needs a valid title-only preview first. A no-op (unchanged title)
  writes nothing and needs no preview.

The refusal text is unchanged. `create_page`, restore and the Pro tools stay exempt as before.

### D7. Permissions and targets

`EditTarget::resolve()` is called first, exactly as today (pages and Divi Projects; `edit_post` re-checked per call, so
a user who may not edit the page gets 403 / -32602 and nothing is read or written). The title is read and written only
after that. An editor cannot rename someone else's published page unless core's `edit_post` mapping allows it, because
`edit_post` is the gate.

### D8. `HistoryService` (backward compatible)

- `write(int $pageId, string $newContent, string $tool, ?string $newTitle = null)`: the new fourth parameter is optional;
  callers (Pro's `FindReplaceSite`, the free handlers) keep working unchanged. With a title it makes one
  `wp_update_post` call carrying both fields, after the snapshot. The result gains a `title` entry only when a title
  was passed.
- `writeTitle(int $pageId, string $title, string $tool)`: title-only. Passes only `ID` and `post_title` to
  `wp_update_post`, so `post_content` is never touched, and takes no snapshot.
- Both read the post fresh after the update and report the stored title.
- `restore()` keeps its signature; its result gains `title_restored:false` and `title_note`.

### D9. Slug and other fields are provably untouched

The updates pass only `ID` and the named fields. Core's `wp_update_post` merges them into the stored post, so
`post_name`, `post_status`, `post_date` and the rest are carried over. This is **checked live** (`make verify-page-title`:
a draft and a published throwaway page keep their slug and status after a rename) and pinned in unit tests by asserting
the exact array passed to `wp_update_post`.

### D10. Dynamic title modules

A Divi layout can show the page title dynamically (the render-verified `divi/post-title` module,
`divi/fullwidth-post-title`, or dynamic content) or contain the title typed in as plain text (a `divi/heading` with the
words). A static heading is just layout text and is **not** changed by a title change; the AI must edit it with
`edit_page_content` or `update_page_layout` if the owner wants both. What a dynamic module shows after a rename is
**verified on the real Divi** by `make verify-page-title` (it renders a throwaway page containing a `divi/post-title`
module before and after a rename and compares); the guide text says only what that run proved, and the result is
recorded in this spec's "Verified facts" section below. Nothing about rendering is claimed without that run.

### D11. Guides

`StyleGuide` gets a short "Page title vs headline" section. `update_page_layout`'s tool description, the MCP/OpenAPI
property text and the readme FAQ say the same:

- The **page title** is WordPress's title: what shows in the page list, (usually) the browser tab, and search. It
  is not claimed to show anywhere else (menu labels are stored per menu item and are not verified here). Change it with `update_page_layout` and `title` (title-only is fine).
- The **headline** is a heading module inside the layout (often the `h1`). Change it with `edit_page_content` or
  `update_page_layout`.
- They are separate. Changing one never changes the other; if the owner says "change the title" and the page has an
  `h1` that repeats the old title, say so and ask whether to change both. Never report a layout edit as a title change.
- Not supported (say so, do not improvise): the page's address (slug), menu labels (a menu may keep showing the old label: tell the
  owner to check their menu), and the browser-tab/SEO title from an SEO plugin.
- A title change cannot be undone with `restore_page_version`; tell the owner the old title.

Wording is checked against what is true (menu behaviour is NOT asserted: an earlier draft said a menu item that follows the page shows the new title; that was removed as unverified): the SEO-plugin and menu statements are phrased as "this tool changes only the
WordPress page title" and do not assert how other plugins behave.

### D12. Usage log, tool count, importer

No new endpoint name is needed: a title save logs as `update_layout` / `update_page` (title-only included), a preview
as `dry_run`. The tool list stays at 21, REST routes and OpenAPI operations are unchanged in number, so the 30-operation
importer budget is untouched. Lockstep: `McpHandler`, `RestController`, `OpenApiSpec` all gain `title`, pinned by a new
`PageTitleLockstepTest`.

## Components

| Unit | Role | Depends on |
|---|---|---|
| `PageTitle` (new, pure) | `parse(array $args, string $key = 'title')` returns `{ok, present, title, error}`; `MAX_LENGTH = 200` | nothing |
| `HistoryService` | `write(.., ?$newTitle)`, `writeTitle()`, `restore()` notes | WordPress `wp_update_post`, `get_post` |
| `DryRun` | `preview(..)` unchanged; new `previewTitle()` and an optional title block on `preview()` | `LayoutGate`, `LayoutDiff` |
| `ApprovalMode` | `withTitle()` helper | none |
| `McpHandler::toolUpdate`, `RestController::update_page` | parse, gate, route to the right write | all of the above |
| `OpenApiSpec` | `title` property, description, `SnapshotInfo.reason` gains `title_only` | none |
| `StyleGuide`, readme | page title vs headline | none |

## Error and edge behaviour

- `title` present but the page not found / not editable: the same 404 / 403 / -32602 as today, before anything else.
- Title-only call on a Divi Project: works (same post-type rule).
- `wp_update_post` failure: 500 / -32603 with core's message; the old title is still in place (checked by re-reading).
- Title with quotes, backslashes, emoji, accents: round-trips (unit and live tests).
- A page whose stored title is empty: `title.before` is `""`; the rename works.
- `dry_run` and a non-boolean `dry_run` keep their strict behaviour.
- Combined call where the layout is invalid: refused exactly as today (422 / `isError`), **and the title is not saved
  either** (all or nothing: validation precedes any write).

## Testing

- Unit: `PageTitle` (every rule, boundaries 200/201 characters, multibyte, invalid UTF-8, NBSP, control characters, `<`/`>`,
  non-strings); `ApprovalMode` key stability for no-title calls and the title-binding cases; `DryRun` title block;
  `HistoryService::write/writeTitle/restore` against the shims (exact `wp_update_post` arguments, no content touch,
  no snapshot on title-only, snapshot on combined, `title` reports the stored value, restore notes).
- Handler tests (real `McpHandler` / `RestController` on the fake database): title-only, combined, strict types,
  missing both, dry run writes nothing, approval mode, permission refusal, invalid layout saves no title, unchanged
  title, Project.
- Lockstep: MCP schema, REST handler source, OpenAPI property, descriptions, `edit_page_content` has no `title`.
- Compliance: `WpOrgComplianceTest` unchanged unless the readme claims need a matching assertion.
- Live: `make verify-page-title` (REST and MCP on the Docker dev site, throwaway pages, self-cleaning).

## Verified facts

(Filled in from `make verify-page-title` before release; see the plan's Task 9.)
