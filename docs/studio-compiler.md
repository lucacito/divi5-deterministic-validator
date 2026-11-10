# Studio compiler (dev note, unreleased)

Plan 1 of 3 for Studio (spec `docs/superpowers/specs/2026-11-09-studio-design.md`, plan `docs/superpowers/plans/2026-11-09-studio-compiler.md`). Pure PHP in `wp-plugin/src/`. Plan 2 (the admin screen people see as **Lab**, with storage and a live preview) is built on branch `feat/studio-screen`: see "Lab screen" below. Plan 3 (the assistant tool and its guide) is not written yet. Product names: people read "Lab"; "Studio" is only the internal code name.

## What it does

A **brief** (small JSON) goes in; valid Divi 5 markup and a report come out. Each section of a brief is one of the 75 recipes with its slots filled, resolved through the normal serve pipeline for the chosen design language and kit, joined, and checked by `LayoutGate`.

```json
{
  "title": "Acme plumbing", "goal": "book", "language": "editorial", "kit": "quiet-solid",
  "sections": [
    { "recipe": "hero-cta",
      "slots": { "heading.1": "Fix leaks fast", "button.0.text": "Book now", "button.0.link": "/contact/" },
      "pictures": { "section.0.background": { "pack": "hero-blue-1" } } }
  ]
}
```

`version` (1) and `kit` are optional. `goal` is one of sell, book, signup, explain, showcase.

## Classes

| Class | Job |
|---|---|
| `StudioSlots` | `extract()` the slots of a raw recipe, `fill()` them, `recipesWithSlot()`; `EXEMPT_PATHS`, `isDynamic()` |
| `StudioBrief` | `check()` a brief strictly: stable code + path + message for every problem, a normalised copy when ok |
| `StudioLinks` | the allowed link forms: https, mailto, tel, `#anchor`, `/site-path` |
| `StudioCompiler` | `compile()`: brief to markup, `unfilled`, `refused`, `warnings`, `advice` (design variety, loops) |
| `SectionRecipes::resolveRaw()` | the serve pipeline for markup that is not a stored recipe |

## Slot ids

`<block>.<index>[.<field>[.<k>]]`, `index` = order among all blocks of that name in the recipe. Examples: `heading.1`, `text.0.body.2` (third paragraph or heading of a text module), `button.0.text`, `button.0.link`, `image.0` and `image.0.alt`, `section.0.background`, `testimonial.0.portrait`, `contact-form.0.button`. Roles: headline, subhead, eyebrow, body, item_title, figure, field_label, attribution, button_label, link, picture, alt. The table is pinned by `tests/golden/studio-slots.json` (regenerate with `AIED_UPDATE_GOLDEN=1 vendor/bin/phpunit --filter StudioSlotsTest` and read the diff).

## Rules worth knowing

- Supplied text is data. It is trimmed, HTML-escaped, and `{` and `$` are written as entities, so no colour token, kit marker or Divi dynamic-content token can be built from it (the serve pipeline runs after the fill).
- An empty or whitespace-only value is not supplied: the recipe's sample copy stays and the slot is listed in `unfilled`. Placeholders never block a draft.
- A loop's own values (`$variable(` tokens: titles, links, pictures, alts) are never slots.
- Every `divi/button` has a `.link` slot; most recipe buttons carry no `linkUrl`, so filling adds the key beside `text` (the shape real exports write).
- A filled block is re-encoded with `RecipeTokens::encodeJson` (same value, different escape style from some stored recipes); blocks with nothing filled keep their exact bytes.
- Whole-field slot: an HTML field with no paragraph or heading element (loose text) is one slot, `element = StudioSlots::WHOLE`.
- A `<div>` that holds only copy (no paragraph, heading or other div inside) is a slot like a paragraph; a wrapper div is not. `testNoCopyIsLeftInsideAnHtmlField` fills every element slot and fails if visible text remains in a field.
- Counter figures (`number-counter`, `circle-counter` `number`) must be plain numbers (`1250`, `1,250`, `98.6`; a circle counter 0 to 100): Divi reads them with `parseFloat`, so "$2M" would show NaN. Refused with `FIGURE_NOT_NUMBER`; the %, + or $ sign comes from the counter's own settings.
- Text may not hold control characters (NUL and the like, `TEXT_INVALID`); tabs and line breaks are fine.
- Link addresses refuse `" < > \` { } | ^` and the backslash; picture addresses allow only `A-Za-z0-9 - . _ ~ : / ? # @ ! & * + , = %` (percent-encode anything else), because a background address is written into a CSS `url()`.
- `StudioLinks::sitePath` strips the install's own path for an https address on a site in a subdirectory; a `/path` link is read from the host root, as a browser reads it.
- Picture slots: `{"pack": token}` or `{"url": https address or /site-path}`; a loop's picture is not a slot.

## Proofs

`StudioLibraryProofTest`: every recipe under every design language with a unique marker in every text, alt and link slot compiles valid, each marker appears exactly once, nothing but pictures is unfilled; and 200 seeded hostile briefs (`AIED_SEED=n`) never break a page, form a token, marker, block comment or disallowed link. The whole-library test found a real defect while this was built (filling a loop image's alt overwrote the loop's dynamic token); `StudioSlotsTest::testNoSlotCarriesALoopsDynamicContent` pins the fix.

## Open items of plan 1, answered by the live gate

- **Do plain fields show `&`, `<`, `$` and `"` literally?** Yes. `make verify-studio` posts a slashed JSON brief through the real admin-ajax (double quotes, backslashes, `&`, `<b>`, `$5` and a line break in one slot) and Divi 5.15.1 shows it byte for byte in a heading, a button label, a counter title and a text body; no `<b>` element is made, no `&amp;` or `&#36;` is visible. A counter NUMBER cannot hold these characters (`FIGURE_NOT_NUMBER`); the legal `1,250` shows as `1,250` once Divi's count-up has run (it starts when the counter scrolls into view). Also for a user without `unfiltered_html` (see kses below).
- Filling a rich slot drops its inline markup (links, bold) by design in version 1 (unchanged).

## Lab screen (plan 2, branch `feat/studio-screen`, unreleased)

A tab of the plugin's admin page, **Lab** (`admin.php?page=ai-editor-divi5&tab=studio`): five steps on the left (goal and business, look, sections, words and pictures, check and create), a live preview on the right. The plan is `docs/superpowers/plans/2026-11-10-studio-screen.md`; the preview spike is `docs/studio-preview-evidence.md`.

### Units

| Unit | Job |
|---|---|
| `StudioStack` | the default stack of sections per goal and density (a table of recipe ROLES, never ids; per-goal preferences and exclusions, pinned per goal x density), item counts, same-role variants |
| `StudioMerge` | merge slot values into one section, swap a section's recipe (a word is kept only when the slot id exists in the new recipe with the same role and kind); no production caller yet: the pinned server twin of the screen's merge/swap, for plan 3's tool |
| `StudioTable` | the table `{prefix}ai_editor_divi5_studio` (one row per saved brief; created lazily through dbDelta, version option `ai_editor_divi5_studio_db_version`) |
| `StudioStore` | the only queries on it: save (at most 200 KB of JSON), get, link a brief to the page it built (with the sha256 of the content as stored), forPage, recentlyLinked (duplicate guard), delete, prune to 50 per user; another user's id is exactly a missing one |
| `StudioService` | the only code that saves a Lab page: preview, create (always a DRAFT), rebuild, hand-off instruction (names the assistant tool `StudioService::TOOL = 'lab_build'`, which plan 3 must create; until `ToolCatalog` holds it, `StudioScreen::assistantReady()` is false and the screen shows no hand-off, no instruction box, no "Get my assistant's words" and never polls) |
| `StudioPreview` | one private draft per user, titled `Lab preview (temporary) <user id>`, updated in place, shown in an iframe; no history rows, no revisions; deleted on Create (the frame is then cleared), by a stale sweep of drafts older than a day that runs only when someone makes a preview (at most once an hour), and by `cleanupAll()` on uninstall (which also deletes the sweep's transient) |
| `StudioAjax` | the admin-ajax handlers (below) |
| `StudioScreen` + `assets/studio.js`, `studio-core.js` (pure, node-tested), `studio.css` | the screen; every text goes in with `textContent` / `value` |

### Ajax actions

All are `wp_ajax_aied_studio_<action>` (signed-in only), need the nonce `ai_editor_divi5_studio` in the field `nonce` and `manage_options`; preview, create and rebuild also need `edit_pages` (each writes a page). The user is always the signed-in user. `stack` (goal, density -> the suggested sections), `preview` (brief -> report and the preview address; writes only the preview draft), `save` (brief, optional id), `create` (brief, optional id -> a new draft), `rebuild` (id -> the page built again or a new draft with a reason), `handoff` (id -> the instruction for an assistant), `brief` (id -> the stored brief). Statuses: 400 bad input, 403 no right or an expired nonce, 404 a missing or foreign brief id, 413 a brief over 200 KB, 422 an invalid brief or layout or a removed recipe, 500 a failure inside.

### Rebuild

A rebuild overwrites the linked page only when it is still a draft, its stored content still has the hash the service recorded, the user may edit it, and its current content was first kept in the page history (snapshot first; over `PageHistory::MAX_BYTES` or invalid UTF-8 means no overwrite). Otherwise it makes a new draft and says why: `edited`, `published` (also scheduled, private, pending), `trashed`, `missing`, `no_access`, `no_undo`. An overwrite keeps the page title (the screen says so) and writes exactly one history row (tool `studio`, shown as "Lab" on the Dashboard). A stored brief whose recipe left the library stops with `RECIPE_REMOVED`; the screen marks the section and offers replacement layouts.

### Rulings of the plan (what each decided)

1. The hand-off names the tool through one constant, `lab_build`. 2. Uninstall deletes every preview draft (`StudioPreview::cleanupAll()`) and drops the table. 3. Live work is dev-site only. 4. The preview update is suspended from the universal history capture, pinned by a test. 5. Preview drafts are deleted only by exact title AND author AND type and status. 6. A swap keeps a word only for the same slot id with the same role and kind. 7. Default stacks are goal-suitable and pinned per goal x density. 8. The table is proven on the real MariaDB (gate A). 9. History rows of the Lab read "Lab" on the Dashboard (gates C and H). 10. A rebuild overwrites only with a kept undo point, else `no_undo`. 11. The service itself checks `edit_post` before an overwrite, else `no_access`. 12. A rebuild writes exactly one history row and the screen says the title is kept (gate C and G). 13. kses for a user without `unfiltered_html` is measured live (gate D, below). 14. The stale sweep runs at most once an hour (a transient), and Create removes the user's preview draft. 15. `preview.html` (the approximation) is for an iframe `srcdoc` only. 16. Step 1 maps onto the unchanged brief: business name = title and hero headline, tagline = the line under it, the action = the hero button; "who it is for" is not stored. 17. A real slashed JSON brief through admin-ajax is compared with what is stored (gate E). 18. The ordinary word "studio" in three design-language summaries stays. 19. Before any write that carries a brief id, the screen reads the stored brief and keeps the words an assistant wrote unless the person changed that field. 20. (Plan 3, task 2) Every save and create of a stored brief carries `base_hash`, the `brief_hash` the screen last saw (the config's `hash`, then every save, create and brief answer). A stale one is HTTP 409 `CONFLICT` with the current `brief` and `hash` and nothing is written (`StudioStore::save` compares atomically for a save; the create handler compares before the service saves). `studio-core.js` `resolveConflict` turns a words-only difference into a merge and ONE retry, and a changed goal, language, kit, title or layout list/order into a reload sentence with nothing sent; the same structure check runs before every send and on the poll.

### Reopening a brief

After a successful save or create the screen rewrites its own address to `...&tab=studio&brief=<id>` (`history.replaceState`, no reload), so a reload, a bookmark or a later visit reopens the brief; a brief that built a page reopens with Rebuild and Open in Divi instead of Create (`StudioScreen::config()['brief']['edit_url']`, only while the page exists and the user may edit it). A leave warning (`beforeunload`, the text `leaveWarning`) is armed only while the answers differ from the brief last saved or loaded (`core.isDirty`).

### Live gate: `make verify-studio`

`scripts/verify-studio.cjs` (browser and HTTP) with `scripts/verify-studio-server.php` (the server half, `wp eval-file`, guarded by `scripts/dev-guard.php`); the pure expectation builders are `scripts/lib/studio-gate.cjs`, tested by `scripts/test-verify-studio.cjs`, which the target runs first. About 100 seconds. Checks: A the table on MariaDB (dbDelta, columns and indexes, save/get/link/forPage/prune at 50/delete, a foreign id = a missing one, a corrupt row reads as missing, uninstall's Studio steps through their public methods, never `uninstall.php` itself); B the preview draft (strict match against four look-alikes, two updates on the same id with no history row and no revision while a control save is captured, delete removes its history, the stale sweep, `cleanupAll`); C a rebuild's single history row and a byte-for-byte undo; D kses; E the slashed brief and its literal render; F every rebuild reason through the real handlers, another user's id in five actions answered exactly like a missing id, a removed recipe; G the screen (two goals, one by keyboard only through the stepper and the Next buttons with the focus on each heading, the status region, widths 1600/1280/1100/900/782/400, the preview at 1280/768/390 CSS px, the Media Library picker, the clipboard fallback from steps 4 and 5, Create, Rebuild, a double click, the Pages list, no console error, no PHP message); H the Dashboard label. A temporary mu-plugin logs every PHP warning of the run (WP_DEBUG is off on the dev site; a self-test notice proves it is live). Everything is removed at the end, on a failure and on Ctrl-C; options and user 1's plugin user meta are recorded and put back; `make verify-studio ARGS=--clean-orphans` removes leftovers, and the gate refuses to start while any exist. Throwaway users `aied_studio_<run>_<a|b|n|e|r>`: two administrators, an administrator and an Editor without `unfiltered_html`, an administrator without `edit_others_pages`.

The summary reads "N PASS, S SKIP, F FAIL"; a SKIP is a check that could not run here (for example `cleanupAll()` while a real user's preview draft exists on the site, since it deletes every user's) and never counts as a PASS; only a FAIL fails the run. The cleanup deletes a post only when its title starts with `aied studio <run>`, it is exactly a gate user's preview title, or its author is a gate user (`aied_may_delete`, self-tested at setup); any other id it was handed is refused and fails the run.

Result after the final fix pass (Divi 5.15.1, WordPress 7.1.2, MariaDB 11): 176 PASS, 1 SKIP, 0 FAIL; the SKIP is the clipboard fallback of the hand-off, hidden until `lab_build` exists; `cleanupAll()` ran live (no real user's preview draft existed). Before that pass: 172 PASS, 0 FAIL, in four of five runs of the script; the fifth had one 404 for a preview-frame load (`/?page_id=<preview>&aied_v=2`, without `preview=true`) that did not come back in the next four runs. The gate now logs every failed request with its frame and redirect, so a repeat names its source.

### What the gate found (and what changed)

- **kses broke the page for users without `unfiltered_html`.** 62 of the 75 recipes keep raw HTML (`"<p>...</p>"`) inside block attribute JSON. For such a user WordPress runs kses on save, and `wp_pre_kses_less_than` turned every block comment holding a `<` before its first `>` into visible text (`&lt;!-- wp:divi/text {&quot;content...`): the module vanished and the page showed its code. The compiler now escapes `<`, `>`, `&` and `--` in block attributes as `\u` escapes, the way WordPress's `serialize_block_attributes()` escapes those four (a block whose decoded attributes would change is left alone; if the rewrite itself fails, for example on a PCRE limit, the compile fails with `COMPILE_FAILED` rather than hand out unescaped markup). This is approximate: core also writes `\"` as `\u0022`, which the helper does not, so a later re-serialization by core or Divi still changes bytes (never values): `StudioCompiler::serializeAttributes()`, pinned by `StudioCompilerTest::testBlockAttributesAreWrittenAsWordPressSerializesThem` over all 75 recipes. Measured live after the fix, as an Editor and as an administrator without `unfiltered_html`, for one brief of three recipes (hero-cta, stats-counter-band, cta-band): the stored preview draft and the created page are the same blocks with the same values as the compiled layout (the bytes differ: kses writes a backslash as `\u005c` and `&#36;` as `&#036;`), the layout check passes, the render shows every typed text literally, and a rebuild still sees its own page as untouched. The same raw-HTML attributes reach every other write path of the plugin (an assistant saving a recipe through `create_page` as such a user); that is outside this plan and is reported, not changed.
- **The stale sweep deleted fresh preview drafts.** WordPress keeps `post_modified_gmt` at `0000-00-00 00:00:00` for a draft that was inserted and never updated, so every new preview draft looked older than any cutoff. `StudioPreview` now judges such a row by its local `post_modified` (`testANeverUpdatedDraftIsJudgedByItsLocalModifiedTime`).
- **The tab bar scrolled the admin page sideways at 400 px** (75 px past the edge on every tab of the plugin, with the add-on's tabs). `.aied-nav` now wraps (`admin.css`, `StudioScreenTest::testTheTabBarWrapsOnANarrowScreen`).
- **Divi re-serializes some pages on save.** When any block of a page holds `elementType`, `htmlBefore` or `htmlAfter`, Divi 5.15.1's `HtmlSecurity::sanitize_html_fields` (a `wp_insert_post_data` filter) re-writes the whole page with core's `serialize_blocks()`, which turns `\"` into `\u0022`. A Lab page is then the same blocks and values as compiled but not the same bytes. Nothing depends on byte equality (the brief records the hash of the content as stored), so this was not changed; the gate accepts "byte equal" or "same blocks and values" and says which.
- The Ruling 9 label, Ruling 12 message, the preview's true device widths (the desktop view of this dev site shows the Pro mega menu's links and no menu button; the phone view shows the menu button) and the Pages-list note all held.

### Known limits

- The hand-off to an assistant is hidden until the tool `lab_build` exists (plan 3).
- The live kses check covers one brief of three recipes (hero-cta, stats-counter-band, cta-band); `StudioCompilerTest` covers the attribute values of all 75 recipes (two independent scans), not a live save of each.
- Text typed with `&`, `<`, `$` or `"` is proven literal on the front end only; how the Divi Visual Builder shows and re-saves that escaped text is NOT verified (the review item "Visual Builder input" is open).
- The screen keeps an assistant's words (Ruling 19), but its own goal, language, kit and layout choices overwrite an assistant's change to those, and a word the assistant deleted comes back; plan 3 task 2 closed this: a structural change by an assistant is a conflict and the screen asks for a reload instead of overwriting it (a word the assistant deleted still comes back, as words are merged only when added or changed).
- `hero-bordered-cells` is the compact hero for every goal and language (a loud mood on calm briefs).
- The service's error messages (NOT_FOUND, SAVE_FAILED, STORE_FAILED, INTERNAL_ERROR) are English and not translatable; the screen shows its own translated texts where it has one.
- "Open in Divi" is checked to be the page's own edit address on this site, not proven to open the builder.
- Created pages can differ in bytes from the compiled layout (Divi's re-serialization, kses); the values are the same.

### Owner decisions left

- The tab is named just "Lab" (owner decision 2026-11-10, shortened from "Divi 5 Lab"). It no longer carries "Divi", but it still echoes the commercial store divi5lab.com; a WordPress.org reviewer might still read it as promoting the paid product, so keep it out of any text that names the store.
- The kses breakage from raw HTML in recipe block JSON still affects the plugin's other write paths for users without `unfiltered_html`.
- Merge and release; plan 3 (the `lab_build` tool and its guide).

### Not covered

A plain Divi header without the mega menu (the desktop check reads the visible header links and the hidden menu button), a multisite install, a security plugin that blocks framing, the assistant route and its tool (plan 3), a real assistant writing words, reduced motion off, browsers other than Chromium, screen readers (the gate checks focus and the live region, not announcements).
