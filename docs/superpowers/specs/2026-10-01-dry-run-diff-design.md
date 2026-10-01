# 3.6.0 — Preview before save (dry-run diff)

Status: DRAFT for owner approval · Date: 2026-10-01 · Target release: AI Editor for Divi 5 3.6.0
Roadmap: 3.5 undo (done) → **3.6 dry-run diff** → 3.7 Theme Builder header/footer → 3.8 global presets.

## Goal

Before an AI edit lands, the person (and the AI) can see exactly what would change —
which blocks, which attributes, which text — without saving anything. Undo (3.5) makes a
bad edit reversible; preview makes most bad edits never happen, and gives the AI a cheap
way to check its own work ("did my find/replace touch only what I meant?").

## Why

Today the only dry run is `validate_layout`, which answers "valid or not" but not "what
would change". For a whole-layout `update_page_layout` the AI re-emits the entire page, so
a one-word request can silently alter unrelated blocks. A structured diff makes that
visible and lets a human-in-the-loop client (Claude Desktop/Cursor) show it for approval.

## Approach (recommended): a `dry_run` flag on the two existing write tools

No new tools: `update_page_layout` and `edit_page_content` (MCP) and `PUT /pages/{id}` and
`POST /pages/{id}/edit` (REST + ChatGPT OpenAPI) accept `dry_run: true`. With it set, the
request runs the SAME checks as a real write (page exists, capability, find/replace
applied in memory, validator run) and then, instead of calling `HistoryService::write`,
returns the diff and `saved: false`. The page, its history and its meta are never touched.

Response (additive; same shape on all three transports):

```
{ "saved": false, "dry_run": true, "valid": true|false, "violations": [...],
  "diff": { "summary": {"added":N,"removed":N,"changed":N,"unchanged":N},
            "changes": [ {"path":"divi/section[0]/divi/row[0]…","block":"divi/heading",
                          "kind":"added|removed|changed",
                          "attrs":[{"path":"title.innerContent.desktop.value","before":"…","after":"…"}]} ],
            "truncated": false } }
```

### `LayoutDiff` (pure, new)

`LayoutDiff::compare(string $before, string $after): array` — parses both with the
validator's `BlockParser` (already bundled), walks the two trees in parallel by child
index under the same parent path, and reports:
- `added` / `removed` when a child index exists on one side only or the block type differs;
- `changed` when the type matches but the attrs differ — a flattened list of differing
  attribute dot-paths with before/after values truncated to 200 characters;
- `builderVersion` differences are ignored (the AI re-stamps layouts; it is not a change).
Reordering is reported as changed/added/removed at the affected indices (no move
detection — kept simple; noted as a limitation). Output is capped at 50 changes
(`truncated: true` + counts) so a rewrite of a huge page cannot flood the AI's context; it
never returns the whole content.

Unparseable input: if `before` or `after` fails to parse, `diff` is `null` with
`diff_error` explaining why; `valid`/`violations` are still returned (the validator's
verdict is the authority).

## Scope

In: `LayoutDiff` + tests; `dry_run` on the two MCP tools and two REST routes; OpenAPI request
property + response schema (descriptions ≤ 300 chars, no bare objects); tool descriptions
and Style/Site guides ("preview large or risky edits with dry_run first"); readme/CLAUDE.md.
Free tier. No admin UI (the AI client is the UI).
Out: visual (rendered) before/after; diff for `create_page` (nothing to compare against);
move detection; approving a previewed change by token (a later spec if wanted).

## Testing

- `LayoutDiff` unit tests on real fixture layouts (`fixtures/valid`): identical → all
  unchanged; one heading text changed → exactly one `changed` with the right attr path;
  block added at the end; block removed; type swapped at an index; `builderVersion`-only
  change → no changes; 50-change cap sets `truncated`; garbage input → `diff: null`.
- A structural test that every `dry_run` code path returns BEFORE `HistoryService::write`
  (source guard, like `HistoryLockstepTest`) plus a live check that a dry run leaves
  `post_content`, `_aied_history` and `post_modified` untouched.
- Lockstep test across MCP/REST/OpenAPI as in 3.5. Plugin Check clean under slug
  `ai-editor-for-divi-5`.

## Risks / things to decide

1. **Flag vs new tool.** A flag keeps one concept for the AI and fewer tools in the
   ChatGPT action (it already has 16 operations; the cap is 30). A separate
   `preview_page_change` tool would be more discoverable but duplicates parameters.
2. **Diff noise.** Index-based alignment over-reports after an insertion in the middle of a
   list. Acceptable for a preview ("what changed around here"), documented as a limitation.
3. **Trust:** the preview describes what WOULD be written; a later real write re-runs the
   same code, so there is no preview/real divergence.

## Decisions I need from the owner (defaults if you just say "approved")

- Flag (`dry_run`) rather than a new tool: **yes** (default).
- Change cap 50, value truncation 200 chars, `builderVersion` ignored: **yes** (default).
- Free tier: **yes** (default).
- Held for upload with 3.4.0/3.5.0 until WordPress.org approves 3.3.0 and the storage
  decision in the 3.5 spec is settled: **yes** (default).
