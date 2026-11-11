# Pro Lab: restyle Lab pages and build a site from briefs (design)

Date: 2026-11-10. Status: design, not built. Branch for the docs: `docs/pro-lab`. Plan: `docs/superpowers/plans/2026-11-10-pro-lab.md`.
Parent spec: `docs/superpowers/specs/2026-11-09-studio-design.md`, section "Later (not in version 1)", first bullet.

Marking: **owner** = decided by the owner (2026-11-10, "build what is not built", defaults given); **my call** = decided here
by the architect, open to the owner's veto; **owner decision left** = must be answered before release, not before building.

## Intent

The free plugin's Lab (internal name Studio) turns a short brief (title, goal, design language, optional style kit, ordered
recipe sections with words, links and pictures) into a valid Divi 5 draft, deterministically (`StudioCompiler::compile`).
Two Pro features build on it:

- **A. `lab_restyle`** (24th Pro tool): rebuild pages Lab made in another design language and/or style kit. It does not
  reverse-engineer the page: it changes the look in a copy of the page's stored brief and compiles it again, so the result
  is exactly what Lab would build today with the new look.
- **B. A whole site from briefs**: the existing `build_site` `add_page` accepts a Lab `brief` instead of `content`, every
  page of the build shares one look, and each page's brief is stored in the free Lab store so the page shows in the Lab
  screen and can be restyled later with `lab_restyle`.

## Goals

1. Both write only through the existing Pro safety machinery: `LayoutGate`, the free `HistoryService` (snapshot first),
   a dry run that returns a single-use `PreviewToken` (30 minutes, bound to the user, page and brief hashes), one recorded
   batch that `undo_batch` takes back, per-item capability re-checked at undo, and "changed since the preview" skips.
2. Exactness: a restyled page equals, byte for byte, a page saved from the direct compile of the restyled brief through
   the same WordPress save path. A brief page added by `build_site` equals, byte for byte, the same brief saved by the
   free `lab_build` (`create: true`).
3. Undo restores the page AND its brief row (language, kit, brief hash and the content hash the Lab rebuild rule reads).
4. A build never pushes a person's briefs out of the 50-per-user store.
5. Callers of `build_site` who pass `content` see no change at all (same refusals, same answers, same batch items).

## Non-goals

- No new Pro admin screen and nothing Pro in the free Lab screen (**owner**). The Pro tab's existing "Recent Pro changes"
  lists and undoes both features' batches; the restyled or built pages show in the free Lab screen like any Lab page.
- No new ChatGPT operation (**owner**): `lab_restyle` is in `ProOpenApi::MCP_REST_ONLY`. `build_site` stays one of the 14
  Pro operations; its request schema gains the `brief` property (see Decisions).
- No ability (companion) for either tool, no version bump, no release, no readme edits, no `Compat::MIN_FREE` change.
- Not restyling pages Lab did not build, or Lab pages edited since (that is `switch_design`'s job).
- No timing or token claims. None were measured; the docs must not say "built in minutes".

## Constraints

- Free/Pro boundary: nothing Pro, licence-gated or remote in `wp-plugin/`. The one free addition (below) is a read-only
  query any free screen could use; it names no Pro feature (`tests/WpOrgComplianceTest.php` stays untouched and green).
- `StudioStore` stays the only code that queries the Studio table; the Pro add-on calls its public static methods, as it
  already calls `HistoryService` and `LayoutGate`. A brief belongs to its user; every call passes that user's id.
- Lab classes are unreleased (free 4.19.0 at the earliest). Pro must keep working on a free plugin without them: only the
  Lab paths refuse, with a 400 "needs a newer version of the free plugin", exactly the `SwitchDesign::unsupported()` pattern.
- Tool definitions are pinned by `pro-addon/tests/ProGoldenTest.php`; every schema change is a deliberate re-freeze.

## Findings that change the plan

1. **`build_site` has no `post_content` argument; it is `content`.** Everything below says `content`.
2. **Published pages vs. "restyle later".** The owner's rule skips published pages (as `StudioService::rebuild` does).
   But `build_site launch` publishes every page, so with that rule alone a launched Lab site could never be restyled by
   `lab_restyle`, which defeats feature B's "restyle later". Smallest honest alternative (**my call**): keep the skip as the
   default and add one strict boolean `include_published` (default false). With it, a published (or private, pending,
   scheduled) Lab page is restyled through the same snapshot, token and undo path `switch_design` already uses on published
   pages; the hash rule (unedited only) still applies. **Owner decision left:** keep the flag, or drop it and accept that
   launched sites need `switch_design`.
3. **Undo order is reversed and so is undo-of-undo.** `BatchUndo` walks items last to first and records the inverses in
   walk order, so an undo of the undo walks them the other way. A brief restore that first checks the page would work in
   one direction and fail in the other. Decision (**my call**): the brief item never reads the page. It puts back the brief
   JSON (compare-and-set on the brief hash it left) and re-links the page with the content hash it RECORDED. If the page
   itself was not restored (edited since), the brief's hash then no longer matches the page, which Lab already treats as
   "edited": nothing is ever overwritten by a stale link. Order-independent and conservative.
4. **No free API lists a user's Lab pages.** `StudioStore` has `get`, `forPage`, `countFor`, `recentlyLinked`, but no list.
   `all_mine` needs one. Free-side addition (the only one): `StudioStore::linked( int $user, int $limit = self::MAX_PER_USER ): array`
   (the user's briefs with a page, newest first, same shape as `get`). Read-only, no new table or column, no Pro wording.
   Restoring a brief needs nothing new: `StudioStore::save( $user, $brief, $id, $expectedHash )` is already a
   compare-and-set and `StudioStore::link()` already writes the content hash.
5. **"Byte for byte" is about stored bytes.** Divi's `HtmlSecurity::sanitize_html_fields` re-serializes a page on save when
   a block holds `elementType`/`htmlBefore`/`htmlAfter` (found by the Lab screen gate). So the gates compare a page against
   a reference saved through the SAME core path (`HistoryService::write` for restyle, `wp_insert_post` for builds), never
   against raw compiler output. The brief's content hash is always the sha256 of the content as stored, re-read.
6. **`build_site` is a ChatGPT operation and `ProParityTest` requires the OpenAPI request schema to equal the MCP input
   schema.** So `brief` appears there too. `testNoProObjectSchemaIsBare` accepts an object with `additionalProperties`, so
   the brief's `slots` and `pictures` maps carry `additionalProperties: true` (as the `ProResult` schema does). Whether the
   legacy ChatGPT Action importer accepts the nested object is NOT proven (no paid account); it changes no operation count.

## A. `lab_restyle`

**Arguments** (strict, `Args` helpers, never cast): exactly one of `page_id` (int >= 1), `page_ids` (1-25 ints, sorted and
de-duplicated) or `all_mine: true`; `to_language` and/or `to_kit` (at least one; `to_kit: "default"` removes the brief's
`kit`, so the language's own kit is used; an omitted one keeps the brief's own, as in `switch_design`); `include_published`
(bool, default false; Finding 2); `dry_run` (bool); `token` (string). Capability `edit_pages`. `all_mine` takes the user's
linked briefs (`StudioStore::linked`); more than 25 is refused with the count and the instruction to pass `page_ids`
(**my call**: never silently a subset).

**Per page plan**, first match wins (reason codes are the API):

| reason | when |
|---|---|
| `not_found` / `forbidden` | `EditTarget::resolve` says so (nothing else about the page is reported) |
| `not_lab_page` | not a `page`, or the acting user has no brief linked to it (`StudioStore::forPage`) |
| `trashed` | status `trash` |
| `published` | status not `draft` and `include_published` is false |
| `edited` | sha256 of the stored content differs from the brief's `content_hash`; detail names `switch_design` |
| `recipe_removed` | a section's recipe is no longer in the library (`StudioBrief::resolveRecipe`); detail names the section |
| `same_design` | normalised new look equals the brief's (look = language + (kit ?? language's own kit)) |
| `invalid_result` | the compile of the new brief is not ok (brief problems or gate violations, codes only, at most 10) |
| `too_large_for_undo` | stored or new content over `PageHistory::MAX_BYTES` |

Otherwise the plan holds the new brief (a copy with only `language`/`kit` changed; words, links, pictures untouched),
the new markup (`StudioCompiler::compile` with the site's page-exists check, as `StudioService` does), and the hash
triple `stored16:new16:brief16`. `LayoutGate::check( new, stored )` runs as well (the Pro rule for every write).

**lab_restyle refuses what it cannot do exactly** (**my call**): it never falls back to `switch_design`. When to use which:
Lab page untouched since Lab built it -> `lab_restyle` (exact, words kept, Lab can keep rebuilding it). Any other page, or
a Lab page edited in the Divi builder -> `switch_design` (restyles in place, named difference classes D1-D5, leaves the
brief alone; after it the Lab link reads "edited", as it must).

**Preview** (`dry_run: true`): per page id, title, from/to look, reason or diff summary + advisories
(`LayoutDiff::compare`, `StyleReferences::attach`), unfilled-slot count; a token over `Canon::hash` of the arguments
(`all_mine` hashed as the flag, not the ids) and the id => triple map; no token when nothing would change.

**Apply** (token required, spent once): re-plan; skip `not_in_preview` and `changed_since_preview` (page content, new
markup or brief hash moved: an edit in the Lab screen between preview and run is caught here). For each page:
`HistoryService::write( id, new, 'lab_restyle' )` (failure: `save_failed`, nothing else touched); then
`StudioStore::save( user, newBrief, briefId, oldBriefHash )` (compare-and-set; failure leaves the page restyled and the
brief old, reported `brief_not_updated`: the hash no longer matches, so Lab reads "edited", which is honest); then
`StudioStore::link( user, briefId, id, sha256(re-read content) )`. Items per page, in this order: a `lab_brief` item, then
the `post_content` item (`PostContentKind::itemFor`). One batch: "Restyled N Lab page(s) to <language> + <kit>".

**`lab_brief` item kind** (`Kinds\LabBriefKind`, registered in `Batches::kinds()`): `ref` = brief id; `before` =
`{user, page_id, brief, brief_hash, content_hash}` of the old row; `after_hash` = `Canon::hash` of
`{brief_hash, content_hash, page_id}` after the write. `current()` reads that triple through `StudioStore::get( before.user, ref )`
(null when the brief is gone: changed since). `allowed()`: (acting user is `before.user` or has `manage_options`, the
`UndoBatch` rule) and `edit_post` on the page. `restore()`: read the current row (for the inverse), `save` the old brief
with the current brief hash as expected hash, `link` the old content hash (Finding 3); inverse = a `lab_brief` item that
puts the restyled row back. Undo-of-undo therefore restores the new look on both page and brief.

## B. `build_site` with a brief

**Schema** (**my call**, least surface): `add_page` gains one property `brief` (object, the same brief `lab_build`
takes). Exactly one of `content` / `brief`. With `brief`, `title` must be absent (the brief's title is the page title);
`slug`, `menu_label`, `in_menu`, `is_front`, `build_id` work as before. The tool description stays unchanged (<= 300
characters, pinned); the property descriptions say it. No new action, no new tool.

**Shared look** (**my call**): no new argument and no change to the batch row. The build's look is derived from its
brief pages: for each `page_created` item with `meta.brief_id`, the look of that brief as stored now
(`StudioStore::get`), else the item's `meta.look`. One distinct look -> it is the build's look; a brief may then omit
`language` (and `kit`), and the tool fills them before compiling; a brief that names a different look is refused
(`LOOK_MISMATCH`, message: restyle the build's pages with `lab_restyle` first, or start another build). No look yet ->
the brief must name its language (the first brief page sets the look). Several looks (only after a partial restyle) ->
every brief must name its look and none is enforced. `status` adds `look` (`{language, kit}` or `"mixed"`) to the build
summary only when the build has a brief page, so content-only builds answer byte for byte as before.

**Compile and save**: guard (Lab classes present) -> look fill -> `StudioCompiler::compile` (not ok: refused with the
problem codes or the violation lines, the build continues, as an invalid `content` page is) -> brief cap check
(`StudioStore::countFor( user ) >= 50`: refused `TOO_MANY_BRIEFS` before anything is created; **my call**, the same rule
`lab_build` uses, so a 10-page build by a person with 45 briefs stops at the 6th page and prunes nothing) -> the existing
lock, slug and insert path with the compiled markup as content -> `StudioStore::save( user, cleanBrief )` and `link` with
the sha256 of the stored content -> item `meta` gains `brief_id` and `look`. A store failure keeps the page in the build
and reports `lab: {stored: false}` (the page simply is not in the Lab screen). If the item cannot be appended, the page is
deleted (existing rule) and the brief row too (`StudioStore::delete`). Answer: the existing keys plus `lab` =
`{brief_id, stored, unfilled, report}` (report = problems, violations, unfilled, refused, warnings, advice).

**Undo of a build** trashes its draft pages as today. The briefs stay (**my call**): they hold the person's words, the
Lab screen shows them as linked to a trashed page and its rebuild makes a new draft (`trashed`). Deleting them would be
destructive and not re-doable by an undo of the undo. **Owner decision left:** whether undo should also delete them.
Two consequences of keeping them (found in review, both expected):
(a) the briefs of an undone build keep counting toward the 50 briefs per person, so "build 30 pages, undo, build again"
is refused with `TOO_MANY_BRIEFS` at the 21st brief page of the new build; the refusal says that briefs of earlier and
undone builds count and that briefs can be deleted in the Lab screen to make room (pinned by
`testBriefsOfAnUndoneBuildStillCountAndTheRefusalSaysSo`);
(b) a Lab rebuild in place of an untouched build draft changes that page's content, so a later `undo_batch` of the build
may find the page "changed since" and skip trashing it. That is the normal changed-since rule protecting the newer
content, not a defect.

## Release guard

`Lab\LabSupport::unsupported( string $tool, ?\Closure $classExists = null, ?\Closure $methodExists = null ): ?string` checks `StudioCompiler`,
`StudioBrief`, `StudioStore`, `StudioTable`, `PageHistory` and the methods `StudioStore::linked`, `forPage`, `save`,
`link`, `get`, `countFor`, `delete`, `StudioBrief::check`, `resolveRecipe`. None of them goes into `Compat::REQUIRED_CLASSES`.
`lab_restyle` refuses entirely; `build_site` refuses only a call that passes `brief`. Message: `<tool> needs a newer
version of the free plugin "JHMG AI Editor for Divi 5" (one with Divi 5 Lab). Update the free plugin; the other Pro tools
keep working.` `FreeApiCompatTest` pins every free signature both features call.

## Goldens and counts that change (deliberate)

`pro-tools-list.json` (24 tools: `lab_restyle` appended, `build_site`'s schema gains `brief` and two descriptions);
new `lab-restyle-tool.json`; `pro-openapi-operations.json` unchanged (14). Tool-count assertions 23 -> 24 in
`ProGoldenTest`, `CompanionAbilitiesTest`, `DesignInventoryTest`, `ExpiryKeepsAllToolsTest`, `ManageMegaMenuTest`;
`ProOpenApiTest` asserts `/pro/lab_restyle` is absent. Abilities golden unchanged (9).

## Proof

Pure (`make test-pro`, the real free `StudioStore` on the free `tests/FakeWpdb.php`, `InMemoryBatchRepository`): every
skip reason; token single-use, bound and order-independent; a brief edited between preview and run skipped; restyled
markup equals `StudioCompiler::compile` of the restyled brief; undo and undo-of-undo restore page and brief in both item
orders; a non-owner administrator's undo restores the brief under the owner's id; build brief pages equal the direct
compile; look fill and `LOOK_MISMATCH`; the brief cap refusal prunes nothing; hostile words stay literal (no token, kit
marker, `$variable(` or block delimiter formed); content-only `build_site` answers equal a recorded baseline. Free
(`make test`): `StudioStore::linked`. Both suites green.

Live, Docker dev site, Divi 5.15.1, self-cleaning, one gate at a time, a throwaway administrator owns every brief:
- `make verify-pro-lab-restyle` (`scripts/verify-pro-lab-restyle.php` stages + `.cjs` orchestrator, pure tests first, the
  render comparison of `verify-pro-switch-design.cjs` reused): Lab pages made by the real `lab_build`; REST and MCP give the
  same preview and saved bytes; the restyled page equals a reference saved from the direct compile through
  `HistoryService::write`, byte for byte; the brief row has the new look and the sha256 of the stored content, and the free
  `StudioService::rebuild` afterwards overwrites in place (Lab still owns the page); a page edited and a brief edited
  between preview and run are skipped; the token is single-use; an editor without `edit_post` gets a bare `forbidden`; a
  published page is skipped without `include_published` and restyled with it; one restyle as a user whose
  `unfiltered_html` is removed by a temporary filter stores no visible code; undo restores page bytes and the brief row
  exactly, and Lab's rebuild still overwrites in place; real Divi renders the restyled and the reference page the same at
  1280 and 400 px (the run fails when nothing was compared).
- `make verify-pro-lab-site` (`scripts/verify-pro-lab-site.php`): a 10-page build from briefs where only the first names
  the language; every page equals the same brief saved by `lab_build` byte for byte; 10 briefs stored and linked, all in
  the builder's look; `LOOK_MISMATCH`; with 45 briefs already stored the 6th brief page is refused and the count stays 50
  with the same ids; hostile words render literally; `lab_restyle` over the build's drafts, then launch, then a restyle
  with `include_published`; undo of the restyle, then undo of the build (pages trashed, briefs kept and reported).
  `make verify-pro-build-site` must still pass unchanged.

## Left to the builder

Exact message wording (plain, no brief text echoed except recipe ids); file-level helpers; the order of checks inside a
plan where the table leaves it free; how the gate scripts share code with the switch-design gate.

## Owner decisions left

1. `include_published` (Finding 2): keep, or drop and send launched sites to `switch_design`.
2. Undo of a build: keep the briefs (built) or delete them too.
3. Release order: free release with Lab first, then the Pro release (the guard makes either order safe); readme and
   divi5lab.com wording at release, without "in minutes".

## Risks

- The legacy ChatGPT Action importer may reject the nested `brief` object on `build_site` (Finding 6, unproven). Fallback:
  strip `brief` from the OpenAPI request schema and amend `ProParityTest` with a named exception list.
- A free-plugin change to `StudioCompiler` output between preview and run makes every planned page `changed_since_preview`
  (correct, but surprising after an update); the message says to preview again.
- A brief whose row is pruned by the Lab screen (50 cap, oldest unlinked first: linked rows go last) loses its undo point
  for the brief item (`changed_since`); the page still restores.
- Divi save filters differ between `HistoryService::write` and `wp_insert_post` only if Divi hooks them differently; the
  gates compare within the same path and would show it.
- `lab_restyle` on a page whose words contain a link to a page that no longer exists still saves (warnings only, as Lab).
