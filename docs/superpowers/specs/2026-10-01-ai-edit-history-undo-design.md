# 3.5.0 — Undo for AI edits (page history)

Status: APPROVED by owner 2026-10-01 · Date: 2026-10-01 · Target release: AI Editor for Divi 5 3.5.0
Roadmap context: 3.5 history/undo → 3.6 dry-run diff (preview before save) → 3.7 Theme Builder header/footer → 3.8 global presets/variables. Each is its own spec.

## Goal

Let a person (and their AI assistant) undo any AI edit to a Divi 5 page, safely and
predictably, so that "the AI changed my page" is never a one-way door. This is the
single biggest trust feature the plugin can add: it turns "validated before it
saves" into "validated before it saves, and reversible after".

## Why

Every write path (`update_page_layout`, `edit_page_content`, and `create_page`) ends in
`wp_update_post` / `wp_insert_post`. Today a bad-but-valid edit ("changed the wrong
heading", "replaced the wrong phone number") can only be undone through WordPress's
own revision screen, which a Divi user rarely knows about, and which an AI cannot
drive. WordPress core does keep revisions (this site: 65), but their behaviour is
host-configurable (`WP_POST_REVISIONS` can be `false` or capped, some hosts/plugins
purge them), and nothing marks which revision came from an AI.

## Approach (recommended): plugin-owned snapshots, independent of core revisions

Before every AI write the plugin stores the page's PRE-write `post_content` as a
snapshot, then writes. Snapshots are deterministic and do not depend on the host's
revision settings. WordPress core revisions continue to be created as usual (we do not
touch them), so a human still has the native safety net.

- **Storage:** post meta `_aied_history` on the page: a JSON list (newest first) of
  `{id, saved_at (UTC ISO), tool, actor (user id), bytes, sha256, label, content}`.
  `content` stored with `wp_slash` discipline on write (see `wp-slash-on-save` note).
- **Retention:** keep the newest 10 snapshots per page and cap each snapshot at 512 KB;
  a page whose content exceeds the cap is saved WITHOUT a snapshot and the tool result
  says so explicitly (the write is not blocked, but the person is told undo is
  unavailable for that edit). Both numbers are constants, covered by tests.
- **Dedup:** if the pre-write content is identical (sha256) to the newest snapshot, no
  new snapshot is added.
- **No silent growth:** pages deleted → meta deleted with the post (WordPress default);
  uninstall removes all `_aied_history` meta (extend `uninstall.php`).

### New tools (free tier — a trust feature must not be paywalled)

| Tool | Purpose | Notes |
|---|---|---|
| `list_page_history(page_id)` | Returns the snapshots newest-first: id, saved_at, tool, actor, bytes, label (no content) | read-only |
| `restore_page_version(page_id, version_id)` | Restores the chosen snapshot as the page content | itself snapshots the CURRENT content first, so a restore is also undoable |
| `get_page_history_entry(page_id, version_id)` | Returns one snapshot's content (so the AI can show/compare) | read-only |

Exposed three ways in lockstep, per the project rule: MCP (`McpHandler`), REST
(`RestController`: `GET /pages/{id}/history`, `GET /pages/{id}/history/{vid}`,
`POST /pages/{id}/restore`), and the ChatGPT OpenAPI spec (`OpenApiSpec`).

### Restore does NOT go through the validator gate

A snapshot is the person's own previous content, which may contain modules or shapes
the validator does not (yet) know — restoring it must never be refused for that
reason, or undo would fail exactly when the person needs it. Restore therefore skips
the "reject invalid" gate. It still: checks `edit_post` capability, checks the page
exists and is a Divi 5 page, runs the validator, and RETURNS the verdict as
information (`validator: {valid, violations[]}`) so the AI/person knows what they
restored. The determinism of the validator is unchanged — restore is not a validated
write, and the result says so.

### Records

Every snapshot/restore is also logged through the existing `UsageTracker` (new
endpoints `restore`, `history`) so the admin "Recent activity" table shows undo events.

## Admin UI

A small "History" panel on the existing Dashboard tab: last 5 AI edits across pages
(page title, time, tool) with a "Restore previous version" button per page that calls
the same restore function (nonce + `edit_post`). No new tab. Keep it simple: the AI is
the primary UI; this is the human escape hatch.

## Scope

In: snapshot-before-write for `update_page_layout`, `edit_page_content`, restore's own
pre-snapshot; the three tools in MCP + REST + OpenAPI; admin panel; uninstall cleanup;
tests; guides updated so the AI knows it can undo (StyleGuide/SiteGuide one-liner each).

Out (own specs): dry-run/diff preview (3.6), Theme Builder templates (3.7), global
presets (3.8), multi-step "undo the last N edits across the site", `create_page`
snapshots (a created page has no prior state; undo of a create is delete, which this
plugin deliberately never exposes).

## Testing

- Pure class `PageHistory` (no WordPress calls): add/trim/dedup/cap logic, list, find,
  restore-selection — fully unit-tested against the shims in `tests/bootstrap.php`.
- Tests pin: retention=10, per-snapshot cap=512 KB with the explicit "no snapshot" result,
  dedup by sha256, restore creates a pre-restore snapshot, restore of content the
  validator rejects still succeeds and reports `validator.valid=false`, content with
  backslashes/escaped HTML round-trips byte-for-byte (the `wp_slash` bug class).
- Lockstep test: the three transports expose identical tool names/params (existing
  `OpenApiSpecTest` pattern).
- Plugin Check must stay clean under slug `ai-editor-for-divi-5`; text domain unchanged;
  `Tested up to` stays readme-only.

## Risks / things to decide

1. **Free vs Pro:** recommended FREE. (A paywalled undo would be a poor trust signal and
   a likely WordPress.org reviewer question; Pro stays "build whole sites".)
2. **Snapshot size:** `post_meta` row size is fine for 10 × ≤512 KB, but a site with
   thousands of AI-edited pages stores up to ~5 MB per page. Acceptable; the cap and N
   are constants so they can be tuned.
3. **Concurrent edits:** two writes racing could each snapshot the same pre-state;
   dedup by sha256 makes this harmless, and the last writer wins as today (no new locking).
4. **Privacy:** snapshots live in the site's own database only, like the existing usage
   log; nothing leaves the site. `readme.txt` Privacy section gets one sentence.
5. **A person edits in the Divi builder between AI edits:** the next AI write snapshots
   that human state, so history interleaves correctly (snapshot is of whatever was
   there before the AI wrote).

## Decisions I need from the owner (defaults chosen if you just say "approved")

- Free tier: **yes** (default).
- Retention 10 snapshots / 512 KB each: **yes** (default).
- Restore bypasses the reject-invalid gate but reports the verdict: **yes** (default).
- Ship as 3.5.0 after WordPress.org has approved 3.3.0 (do not upload 3.4.0/3.5.0 while
  the first review is pending): **yes** (default).

## Implementation notes (post-review)

- **Per-page total budget: 768 KB** (`PageHistory::MAX_TOTAL_BYTES = 786432`). Oldest snapshots are trimmed while the stored total exceeds it; the newest is always kept. Reason: WP_Query primes all post meta for every queried page, so an unbounded history would be loaded on every page query and could exceed `max_allowed_packet`. This narrows "10 snapshots" to "up to 10 within the budget".
- UTF-8 validation uses `preg_match('//u', ...)` instead of `mb_check_encoding` because WordPress does not polyfill `mb_*` and mbstring may be absent.
- Notices and AI-facing wording are honest: undo is available only when a snapshot was kept (`history.stored`); the Dashboard shows `version_restored_no_undo` when the replaced version could not be kept; dashboard restores are logged as `restore_version`.
- **Still open (owner decision):** post meta vs a custom table for history storage must be settled BEFORE any 3.5.0 upload, because changing storage after users hold data needs a migration.
