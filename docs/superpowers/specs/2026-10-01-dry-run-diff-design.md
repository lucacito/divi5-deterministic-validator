# 4.2.0 — Preview before save (dry-run diff)

Status: DRAFT v2, awaiting owner approval · Date: 2026-10-01 · Target release: JHMG AI Editor for Divi 5 4.2.0
Supersedes the 3.6.0 draft written before the WordPress.org compliance release. Roadmap: undo (4.0.0) → history in its own table (4.1.0, merged locally) → **dry-run diff (4.2.0)** → Theme Builder header/footer → global presets. Each is its own spec. Nothing since 4.0.0 has been uploaded, so 4.1.0 and 4.2.0 would go out in one upload.

## Goal

Before an AI edit lands, the person (and the AI) can see exactly what would change —
which blocks, which attributes, which text — without saving anything. Undo makes a
bad edit reversible; preview makes most bad edits never happen, and gives the AI a cheap
way to check its own work ("did my find/replace touch only what I meant?").

## Why

Today the only dry run is `validate_layout`, which answers "valid or not" but not "what
would change". For a whole-layout `update_page_layout` the AI re-emits the entire page, so
a one-word request can silently alter unrelated blocks. A structured diff makes that
visible and lets a human-in-the-loop client (Claude Desktop, Cursor) show it for approval.

## Approach (recommended): a `dry_run` flag on the two existing write tools

No new tools: `update_page_layout` and `edit_page_content` (MCP) and `PUT /pages/{id}` and
`POST /pages/{id}/edit` (REST, and the ChatGPT OpenAPI spec) accept `dry_run: true` (boolean;
a non-boolean value is rejected like any other mistyped argument: -32602 / 400). With it set,
the request runs the SAME checks as a real write (page exists and is a page, `edit_post`
capability, find/replace applied in memory, validator run) and then, instead of calling
`HistoryService::write`, returns the diff and `saved: false`. The page, its history rows,
its meta and its `post_modified` are never touched.

The tool surface stays at 15 MCP tools / 15 OpenAPI operations (the reviewed list in
`WpOrgComplianceTest` does not change).

Response (additive; same shape on all three transports):

```
{ "saved": false, "dry_run": true, "valid": true|false, "violations": [...],
  "diff": { "summary": {"added":N,"removed":N,"changed":N,"unchanged":N},
            "changes": [ {"path":"divi/section[0]/divi/row[0]…","block":"divi/heading",
                          "kind":"added|removed|changed",
                          "attrs":[{"path":"title.innerContent.desktop.value","before":"…","after":"…"}]} ],
            "truncated": false },
  "message": "Preview only. Nothing was saved." }
```

Conventions:
- A dry run that computed a preview is a **successful call even when `valid` is false**: MCP
  `isError` is false and REST returns 200 (the verdict is in `valid`/`violations`, and the diff is
  still returned so the AI can see what its invalid layout would have done). Real errors (page not
  found 404 / -32602, forbidden 403, bad arguments 400, a find/replace that does not match exactly
  once or `expect_count` mismatch) keep exactly the responses of a real write.
- Usage log: a dry run is logged with a new endpoint name (`dry_run`) and result `read`
  (`UsageTracker::RESULT_READ`), so Recent activity shows previews without counting them as saves.
  Nothing else in the usage log changes.
- The tool descriptions and Style/Site guides gain one line: preview large or risky edits with
  `dry_run` first.

### `LayoutDiff` (new, pure, in `wp-plugin/src/LayoutDiff.php`)

`LayoutDiff::compare(string $before, string $after): array`. It lives in the plugin, not in
`src/`: the validator copies and `wp-plugin/validator/` stay untouched (no sync work) and the
validator stays a pure verdict engine. It uses the bundled `Divi5Validator\BlockParser` /
`Block` (`name()`, `attrs()`, `children()`) to parse both sides, walks the two trees in
parallel by child index under the same parent path, and reports:
- `added` / `removed` when a child index exists on one side only or the block type differs;
- `changed` when the type matches but the attrs differ — a flattened list of differing
  attribute dot-paths with before/after values truncated to 200 characters;
- `builderVersion` differences are ignored (the AI re-stamps layouts; it is not a change).
Reordering is reported as changed/added/removed at the affected indices (no move
detection — kept simple; noted as a limitation). Output is capped at 50 changes
(`truncated: true` plus the full counts in `summary`) so a rewrite of a huge page cannot flood
the AI's context; it never returns the whole content.

Unparseable input: if `before` or `after` fails to parse, `diff` is `null` with `diff_error`
explaining why; `valid`/`violations` are still returned (the validator's verdict is the
authority). The plugin never needs the diff to be right in order to stay safe: it is
information only and cannot affect what a real write does.

## Scope

In: `LayoutDiff` + tests; `dry_run` on the two MCP tools and two REST routes; OpenAPI request
property + response schema (descriptions ≤ 300 chars, no bare objects, as the existing spec
tests enforce); tool descriptions and Style/Site guides; usage-log entry; readme and CLAUDE.md
(including the readme's feature claims, which `WpOrgComplianceTest` checks); version bump to
4.2.0 and zip rebuild; Plugin Check clean on the dev copy and the extracted zip (slug
`jhmg-ai-editor-for-divi-5`). Free; no licence anything; no admin UI (the AI client is the UI).
Out: visual (rendered) before/after; diff for `create_page` (nothing to compare against);
previewing a restore (the AI can already compare with `get_page_history_entry`); move
detection; approving a previewed change by token (a later spec if wanted).

## Testing

- `LayoutDiff` unit tests on real fixture layouts (`fixtures/valid`): identical → all
  unchanged; one heading text changed → exactly one `changed` with the right attr path;
  block added at the end; block removed; type swapped at an index; `builderVersion`-only
  change → no changes; 50-change cap sets `truncated` while `summary` keeps the true counts;
  values truncated at 200 characters; garbage input → `diff: null` with `diff_error`.
- A structural source guard that every `dry_run` code path returns BEFORE `HistoryService::write`
  (like `HistoryLockstepTest`), plus a behavioural test with the fake `$wpdb`: a dry run on both
  tools leaves `post_content`, the history rows and the history table untouched.
- A real-site check (a small `wp eval-file` script run like `make verify-history`): after dry runs
  of both tools on a throwaway page, `post_content`, `post_modified` and the history rows are
  unchanged, and a real write afterwards still works and snapshots.
- Lockstep test across MCP/REST/OpenAPI as for the history tools; a non-boolean `dry_run` is
  rejected on every transport.

## Risks / things to decide

1. **Flag vs new tool (the one real fork).** A flag keeps one concept for the AI, keeps the
   reviewed 15-tool surface unchanged, and leaves the ChatGPT action at 15 operations (cap 30).
   The cost: a human-in-the-loop client asks for approval for a *write* tool even when the call is
   only a preview, because the tool is still `update_page_layout`. A separate read-only
   `preview_page_change` tool would be easier to auto-approve, but it duplicates the two tools'
   parameters, adds a 16th tool to a list WordPress.org reviewers have already seen, and needs
   the compliance test's pinned tool list changed.
2. **Diff noise.** Index-based alignment over-reports after an insertion in the middle of a
   list. Acceptable for a preview ("what changed around here"), documented as a limitation.
3. **Trust:** the preview describes what WOULD be written; a later real write re-runs the same
   code, so there is no preview/real divergence. A page edited in the Divi builder between the
   preview and the real write is not reflected — the same race as any edit today.

## Decisions I need from the owner (defaults if you just say "approved")

- Flag (`dry_run`) rather than a new read-only tool: **yes** (default).
- Change cap 50, value truncation 200 chars, `builderVersion` ignored: **yes** (default).
- A dry run with an invalid layout is a normal 200 / `isError: false` response carrying
  `valid: false`, the violations and the diff: **yes** (default).
- Logged as endpoint `dry_run`, result `read`: **yes** (default).
- Free; ships as 4.2.0 together with 4.1.0 in the next upload (after WordPress.org answers):
  **yes** (default).
