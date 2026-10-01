# History storage: custom table instead of post meta

Status: DRAFT, awaiting owner review · Date: 2026-10-01 · Target release: 4.1.0 (not uploaded until WordPress.org has answered the 4.0.0 review)
Settles the open decision in `2026-10-01-ai-edit-history-undo-design.md` ("post meta vs a custom table"). Direction approved by the owner in conversation on 2026-10-01 (custom table + one-time migration); this written spec is what still needs approval.

## Goal

Move the undo history from the `_aied_history` post-meta JSON blob to a plugin-owned
database table, with no change to what a person or an AI sees: same 3 tools, same
REST routes, same OpenAPI spec, same retention (10 snapshots), same per-snapshot cap
(512 KB), same per-page budget (768 KB), same restore semantics.

## Why

`_aied_history` is post meta on the page itself, which has three real costs:

1. **Priming.** `WP_Query` loads all post meta for every page it returns, so the
   history (up to 768 KB per page) is read on front-end loads and on admin page lists.
   `HistoryService::recent()` already carries a `wp_cache_delete` workaround for it.
2. **Copying.** Page-duplicator and migration plugins copy post meta, so a cloned page
   inherits another page's undo history; core's exporter can also write it into export files.
3. **Rewrite cost.** Every snapshot rewrites the whole JSON blob, which is why the
   budget exists at all.

A table fixes all three and lets the dashboard list recent edits without reading
snapshot content. It is cheap now: the only code that touches the storage is
`HistoryStore` (the pure rules live in `PageHistory`), and the 4.0.0 zip is not yet
approved, so almost nothing is installed. Changing later, with real installs, costs more.

Rejected: **core revisions** (hosts can disable or cap them via `WP_POST_REVISIONS`, so undo
would silently vanish on some sites) and **staying on post meta**.

## Design

### Table `{prefix}ai_editor_divi5_history`

| column | type | notes |
|---|---|---|
| `id` | bigint unsigned AUTO_INCREMENT | primary key (row id only; never exposed) |
| `page_id` | bigint unsigned NOT NULL | the page |
| `version_id` | int unsigned NOT NULL | per-page sequence, the id the tools expose today |
| `saved_at` | varchar(20) NOT NULL | UTC ISO string, as now |
| `tool` | varchar(50) NOT NULL | |
| `actor` | bigint unsigned NOT NULL | WP user id |
| `bytes` | int unsigned NOT NULL | |
| `sha256` | char(64) NOT NULL | |
| `label` | varchar(255) NULL | |
| `content` | longtext NOT NULL | the page's pre-write `post_content`, stored byte-for-byte |

Keys: `UNIQUE (page_id, version_id)`. Created with `dbDelta`, version tracked in its own
option `ai_editor_divi5_history_db_version` (separate from the usage table's option so the
two cannot mask each other).

`version_id` stays per-page and monotonic so the ids AI clients already hold keep their
meaning. The next id is `MAX(version_id) + 1` for the page. This is stable because trimming
only ever removes the oldest snapshots and the newest is always kept. A migrated history
keeps its existing ids.

### Code boundaries

- `PageHistory` (pure) is unchanged in behaviour. Its `next` counter is fed by the store
  (`MAX + 1`) instead of being read from a blob. Only the comment on `MAX_TOTAL_BYTES` is
  reworded: the reason is bounding database growth and packet size, not meta priming.
- `HistoryStore` keeps its facade (`load`, `save`, `deleteAll`) and becomes the only
  code that talks to `$wpdb`. `save()` syncs the page's rows to the given history: insert
  rows not yet stored, delete rows that were trimmed. It returns false on any failure
  (so `HistoryService` reports `history.reason = store_failed`, as today) and a failure
  never deletes existing rows.
- New `HistoryStore::recent(int $limit)`: one query returning the newest row per page
  **without `content`**, newest first. `HistoryService::recent()` uses it, which removes
  the `get_posts( meta_key )` scan and the `wp_cache_delete` workaround.
- New `HistoryStore::summaries( $pageId )`: list rows without `content`, so
  `list_page_history` no longer loads every snapshot to show metadata.
- `get_page_history_entry` / restore load one row (with content) by `(page_id, version_id)`.
- Transports (`McpHandler`, `RestController`, `OpenApiSpec`) and `HistoryService`'s public
  results do not change.

### Lifecycle

- **Create/upgrade.** An `ai_editor_divi5_history_db_version` check on `plugins_loaded`
  (one cheap option read) creates the table when needed. It must run on update as well as
  activation: a WP.org auto-update does not re-run the activation hook. The activation hook
  also calls it.
- **Delete a page.** A `delete_post` hook deletes that page's rows (an indexed delete).
- **Delete a site (multisite).** The table is added to the `wpmu_drop_tables` filter. The
  existing usage table gets the same one-line treatment, since both would otherwise be orphaned.
- **Uninstall.** `HistoryStore::deleteAll()` drops the table, deletes the history db-version
  option, and also deletes any legacy `_aied_history` meta (covers a site that is uninstalled
  mid-migration). `uninstall.php` already calls `deleteAll()`.

### Migration from `_aied_history` (4.0.0 → 4.1.0)

The 4.0.0 zip is downloadable from divi5lab.com, so some sites may hold meta history.

- **Batch.** On the upgrade check, import up to 20 pages that still have `_aied_history`
  meta per request: for each, decode, insert its rows (skipping `(page_id, version_id)` that
  already exist, so a re-run is idempotent), and delete that page's meta **only if every row
  was inserted**. A page whose meta cannot be decoded is left in place and skipped.
  The history db-version is set to current only when no `_aied_history` meta remains.
- **Lazy.** `HistoryStore::load( $pageId )` for a page with no rows but with legacy meta
  imports that page first, so undo never appears empty in the window before the batch finishes.
- Peak memory is one page's history (at most 768 KB) at a time.
- **Downgrade is not supported:** reinstalling 4.0.0 after migration would not see the table.
  Say so in the changelog.

### Behaviour that must not change

- Restore bypasses the validator gate and reports the verdict; the restore snapshots the
  current content first.
- Snapshot skipped reasons: `too_large`, `invalid_encoding`, `store_failed`, `duplicate`.
- Undo is available only when `history.stored` is true.
- Content with backslashes and escaped HTML round-trips byte-for-byte. With `$wpdb->insert`
  the values are escaped by wpdb, not run through `wp_unslash`, so **no `wp_slash`**
  (which would double the backslashes). A test pins this; it is the same bug class as the
  `wp-slash-on-save` note, now in the opposite direction.
- `HistoryService::write()` still never blocks the page write because of a history failure.

### Failure modes (stated honestly)

- A host that forbids `CREATE TABLE` cannot store history. Snapshots then report
  `store_failed` and undo is unavailable, though page writes still work. This is the same
  assumption the usage table already makes; we do not add a meta fallback (YAGNI).
- A race between two writers to one page can produce the same `version_id`. The unique key
  rejects the second insert, which is reported as `store_failed`; no row is corrupted.

## Scope

In: the table, `HistoryStore` rewrite, `HistoryService::recent()`/list/entry using it,
upgrade check, migration, `delete_post` and `wpmu_drop_tables` cleanup, uninstall, tests,
`readme.txt` (privacy/data line: history is stored in a table in the site's own database;
changelog entry incl. no-downgrade note), version bump to 4.1.0, zip rebuild, Plugin Check.

Out: changing retention or limits, a meta fallback, undo-of-create, any new tool or route,
UI changes (the Dashboard panel keeps working through `HistoryService`).

## Testing

- **Pure rules** (`PageHistoryTest`): unchanged and must stay green.
- **Fake `$wpdb`** in `tests/bootstrap.php`, limited to the few statements `HistoryStore`
  issues, so `HistoryServiceTest`/`HistoryLockstepTest` run against the new store without WordPress.
  New tests: sync inserts/deletes after trim, `MAX + 1` ids survive trimming, `summaries()`
  and `recent()` return no `content`, backslash/escaped-HTML round-trip, insert failure →
  `store_failed` and existing rows untouched, `delete_post` cleanup, `deleteAll()`.
- **Migration tests:** legacy meta → rows with ids preserved; idempotent re-run; undecodable
  meta left in place; meta deleted only after all rows inserted; lazy import on `load()`;
  batch limit respected.
- **Real-database check** (the fake cannot prove SQL): a script run against the Docker site
  (`wp eval-file`, wired into a `make` target) that creates the table, migrates a seeded
  meta history, writes/trims/restores through `HistoryService`, and checks byte-for-byte
  content equality and that `delete_post` removes the rows. Required before the release.
- **Compliance:** `tests/WpOrgComplianceTest.php` is not weakened. DB calls carry the same
  narrowly-scoped `phpcs` justifications `UsageTracker` uses (no escaping ignores). Plugin Check
  must be clean on the dev copy and the extracted zip.

## Release

4.1.0 = version bump in the plugin header, `AI_EDITOR_DIVI5_VERSION` and `readme.txt`
(Stable tag + Changelog), zip rebuilt. Merged to local `main` only. **Not uploaded** until
WordPress.org has answered the 4.0.0 review, or folded into the upload if they ask for changes.
Dev sites A and B hold meta history: they migrate automatically on the first request after
the update.

## Decisions for the owner (defaults chosen if you just say "approved")

- Table name `ai_editor_divi5_history` (matches the existing usage table): **yes**.
- Limits unchanged (10 / 512 KB / 768 KB): **yes**.
- Version 4.1.0 (a storage change plus migration is a minor bump): **yes**.
- Also add the `wpmu_drop_tables` cleanup for the usage table: **yes**.
