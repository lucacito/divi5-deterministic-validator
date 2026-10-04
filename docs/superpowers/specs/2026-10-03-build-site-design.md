# Pro add-on 0.5.0: `build_site`, whole-site generation as one undoable build (design)

Status: design approved by the owner in conversation on 2026-10-03 (choices below); this file is the written spec for review.
Spike evidence: a throwaway, self-cleaning live script run on the Docker dev site (WordPress 7.1, Divi 5.14) on 2026-10-03; its findings are in "What the spike proved" (the script itself was not kept).

## Goal

Let an AI assistant build a whole multi-page Divi 5 site from a brief **without any page ever being saved invalid, and without
anything changing on the live site until the owner says launch**, and let the owner (or the AI) undo the whole build in one step.
The AI still composes every layout (the plugin steers it with guides and validates the result); the new tool is the safe, undoable
assembly: pages as drafts, then one previewed launch (publish, front page, menu).

## Decisions made with the owner

1. One Pro tool, **`build_site`**, with an `action`; it is the 30th and last ChatGPT operation (16 free + 14 Pro = 30 of 30).
2. Pages arrive **one per call** (`add_page`): a whole site in one call exceeds a model's output and ChatGPT-action limits.
3. **Partial creation**: a page that fails validation (or collides on its slug) is refused at its own call with the exact reasons; the
   build keeps every valid page created so far. (No all-or-nothing.)
4. **Nothing visible changes before launch.** All pages are drafts. Publishing, the front page and the live menu happen only in
   `launch`, previewed first. (Replaces the earlier "publish flag": see the spike.)

## What the spike proved (live, self-cleaning)

- A menu item pointing at a **draft** page is accepted and an **anonymous visitor sees it with a dead `?page_id=N` link**: a live menu
  must never be built before publishing. (`MenuBuilder::build` has no status check; only the `set_primary_menu` tool requires published pages.)
- **Draft pages get no unique slug**; two drafts may both be `about` and WordPress resolves it only at publish (the second becomes `about-2`),
  which would break internal links the layouts planned. `add_page` therefore refuses a slug that is already taken.
- `wp_trash_post` keeps content and Divi meta (slug gets `__trashed`, restored on untrash); `wp_untrash_post` returns the page as a **draft**.
  With `EMPTY_TRASH_DAYS = 0` trashing deletes permanently (the dev site uses 30).
- A 33-item batch is about 7 KB and the items column is `LONGTEXT`: no size problem.

## The tool

`build_site` (Pro, `Tools::all()`, MCP + `POST /pro/build_site` + OpenAPI, all generated from the one definition). Registry
capability **`publish_pages`** (the same as the free `create_page`); further capabilities are checked inside the actions.
Description at most 300 characters. Input: `action` (`add_page` | `status` | `launch`, required) and:

| Action | Arguments | Capability checked |
|---|---|---|
| `add_page` | `title`, `slug`, `content` (required strings); `build_id` (integer, optional); `menu_label` (string, at most 100 bytes, default the title); `in_menu` (boolean, default true); `is_front` (boolean, default false) | `publish_pages` |
| `status` | `build_id` (optional: the user's open build) | `publish_pages` |
| `launch` | `build_id`; `dry_run` (boolean); `token` (string) | `publish_pages` for the status change, `edit_theme_options` for the menu, `manage_options` only when a page is flagged `is_front`; `edit_post` on every page |

### `add_page`

1. Type-checked like every Pro tool (never cast). The title is `sanitize_text_field`; the slug `sanitize_title`; all three required and non-empty.
2. `LayoutGate::check( $content )` (no baseline: a new page). Invalid: `\InvalidArgumentException` with
   `"The page was not created: it failed the checks. CODE at path: message; ..."` (at most 10 violations, like `LayoutWriter`); the build is unchanged and stays open.
3. The slug must not be used by any other page in any status except trash (`post_name` query) nor by an earlier page of the build; otherwise
   refused with the id/title of the page that has it.
4. At most **30 pages** per build; at most one `is_front`; `build_id` must be the caller's own open build. Without `build_id`: if the user has an
   open build the call is refused and names it (pass its id, or `undo_batch` it); otherwise a new build is opened. A page for a closed
   build (launched or undone) is refused.
5. Creation: `wp_insert_post( wp_slash( ... ) )` as a **draft** with `_et_pb_use_divi_5` and `_et_pb_use_builder` = `on` (the free `create_page`'s
   rules; its code is inline in the free handlers, so the Pro tool carries its own copy). The creation is recorded in the build (below).
6. Result: `{ created: true, build: { id, pages, in_menu, front_page }, page: { id, title, slug, status: "draft", link } }` plus the free
   `StyleReferences::attach` advisory for unknown preset or colour ids.

A race guard: appending to a build takes a short lock (`add_option` on a build-specific name, 15 s expiry, retried for up to 5 s); if the lock is
not obtained the call is refused with "another add_page is running, retry" BEFORE a page is created (an MCP client may send tool calls in parallel).

### `status`

Read-only: the build's id, state (`open`, `launched`, `undone`), the pages (id, title, slug, status, in_menu, is_front) in add order.

### `launch`

For an open build with at least one page. A real run needs the token of a dry run of the same arguments (`PreviewToken` v2: user-bound,
single-use, 30 minutes). The map in the token (`PreviewToken` takes `array<int,string>`) holds, per page id, a hash of title + content + slug + status, and under the
reserved keys `-1` and `-2` a hash of the live front-page options and of the live "Main Menu" snapshot.

1. **Per page** (partial semantics): published unless skipped. A page is skipped, with a reason, when it changed since the preview
   (`changed_since_preview`), is no longer a draft of this build or editable by the user (`not_available`), or its slug is now taken by another
   page (`slug_taken`). Skipped pages are never published, never flagged front and never in the menu.
2. **Front page**: if a published page is flagged `is_front`, `show_on_front = page` and `page_on_front` are set.
3. **Menu**: the "Main Menu" is rebuilt from the published `in_menu` pages in add order (label = `menu_label`), assigned to the theme's primary location
   (`MenuBuilder::targetLocation()`; without a location it is built unassigned and the result says so).
4. If the live front page or the live menu changed since the preview, the whole real run is refused (a new preview is needed); site settings are
   not "partially" applied against a stale picture.
5. The dry run returns what would happen: pages with the slug each would have, `would_publish`, `would_skip` with reasons, the front-page change
   (current to new), the menu change (replaces N items with M), and the token. The real run returns the published pages with their REAL
   final slug and permalink, the skipped pages, the menu result, and the batch.
6. WordPress can still change a slug at publish time (reserved or newly taken slugs). The result reports every page whose final slug differs from
   the planned one (`slug_changed`: planned and real) so the AI can fix links that point at it; the page stays published and the menu uses the page id,
   not the slug.
7. Afterwards the build is closed (`launched`); `add_page` for it is refused.

## Builds and undo (storage)

A build is **one batch** (`tool` `build_site`, `{prefix}aied_pro_batches`) that grows. Changes to the batch layer:

- `BatchRepository::append( int $id, array $items ): bool` (Wpdb and in-memory implementations) and `BatchLog::open( tool, summary )`,
  `append( id, items )`, `close( id )`. A new batch status `building` (the column is `varchar(20)`). A launched build is a normal `applied` batch and undo sets `undone` or
  `partly_undone` as it does today; the `status` action reports these as `open`, `launched` and `undone`. `trim()` never removes an open build.
- Item kinds: the new **`page_created`** (`ref` = page id; `before` = null; `after_hash` = hash of title + content + slug read back after the
  insert; `current()` = the same hash, or null when the page is gone or trashed; `allowed()` = `edit_post` and `delete_post`; `restore()` =
  `wp_trash_post`) and the existing **`post_status`**, **`front_page`** and **`menu`**. Its inverse is **`page_trashed`** (`restore()` =
  `wp_untrash_post`; the page returns as a draft). If `EMPTY_TRASH_DAYS` is 0 the trash is a permanent delete, so the inverse is recorded as
  `undo.available = false` with reason `trash_disabled`.
- Order in the batch: the pages in add order; at launch, the status changes, then the front page, then the menu. `undo_batch` walks it in reverse:
  menu restored, front page restored, pages back to draft, then the created pages trashed. Because it uses the existing changed-since rule, a page the
  owner edited in Divi after creation is **skipped with `changed_since`** and the rest is still undone (never a silent overwrite or delete).
- `undo_batch` works on an OPEN build too (abandon it); the build is then closed. `list_batches` and the Pro tab's "Recent Pro changes" show
  `building` as a status.

## Surfaces and budgets

`Tools::all()` gains `BuildSite` (14 Pro tools); `ToolsTest`, `ProParityTest` and the OpenAPI budget tests move to 14 and 30 of 30 operations;
the tool description at most 300 characters; no new REST route (the generic `POST /pro/<tool>`); the Pro tab lists it. `docs/EXTENDING.md`
and `CLAUDE.md` record that the importer's 30 operations are now used up (a later Pro tool needs a decision, for example merging two).
Version 0.5.0, changelog, README sections. Nothing under `src/`, `wp-plugin/` or the validator changes; the free plugin stays at 4.5.0; no new
free class is required (`Compat::REQUIRED_CLASSES` already lists `LayoutGate`, `StyleReferences`, `EditTarget`, `HistoryService`).

## Constraints that bind the work

- The validator and `LayoutGate` stay untouched and deterministic; every page body goes through `LayoutGate::check()`; no custom CSS/JS/PHP is ever saved.
- Per-tool capabilities stay (WordPress.org round-3 note): registry capability `publish_pages`, the stronger ones checked in `launch` and at undo
  (`MenuKind::allowed`, `FrontPageKind::allowed`, `PostStatusKind::allowed`).
- No remote request, no licence changes, nothing pushed, uploaded or released.

## Verification

- Unit tests (`make test-pro`): each action's argument errors; invalid page refused and build intact; slug collision (existing page, earlier build page,
  trashed page not a collision); the 30-page and one-front caps; one open build per user and the refusal text; foreign `build_id`; closed build;
  lock refusal before creation; launch partial semantics (each skip reason), stale menu/front refusal, token rules (reusing the `PreviewToken` tests'
  patterns), `page_created` / `page_trashed` kinds including changed-since and `trash_disabled`, undo of a launched and of an open build, the batch
  `append`/`open`/`close`/`trim` rules, OpenAPI/REST parity at 14 tools and 30 operations.
- Live gate `make verify-pro-build-site` on the Docker dev site (dev-guard, Pro activated only for the run, self-cleaning, baseline of pages,
  menus, front page and locations proven afterwards): add a valid page, an invalid page, a slug collision; `status`; launch dry run, real run, token
  replay refused; anonymous menu render shows real permalinks after launch; undo restores menu, front page and trashes pages; a page edited
  before undo is skipped; abandon an open build; the live OpenAPI spec lists 30 operations and `build_site` documents 200/400/401/403/500.
- Opus reviews the launch and the undo kinds (they write site-wide state) and the whole branch.

## Not in v1

Page hierarchy (parent pages) and nested menus, images, SEO fields, regenerating or editing pages of an existing build, scheduled launch,
build expiry (an abandoned build stays until `undo_batch`), a Pro-tab UI for builds, and any free-plugin change.
