# Pro mega menu: live evidence on Divi 5.15.1

Gate: `make verify-pro-megamenu` (`scripts/verify-pro-megamenu.cjs` + `scripts/verify-pro-megamenu.php`). Docker dev site, Divi 5.15.1, real
Chromium (Playwright), the real `MegaMenuService` as user 1. Design record: `docs/superpowers/specs/2026-11-03-pro-megamenu-design.md`.

## What the gate does

For each of the 13 layouts it applies the layout to the global header through the real service (dry run first, then apply, then a second apply that
must report `changed:false`), renders the front page at 1280, 900 and 400 px (900 and 400 with touch emulation), and for every top-level item with
sub-items it:

- checks the panel is closed before hover, opens on hover (click on touch), stays open while the pointer travels from the label into the panel, is
  inside the viewport, is on top at its centre, holds every sub-link with its href, has text contrast of at least 4.5:1, has nothing clipped, and
  closes when the pointer leaves;
- measures the effect of the attribute paths it writes (font size, weight, colour, hover colour, padding, logo copy, panel text sizes and colours);
- checks no PHP error text, no horizontal scroll, the wrapper shown on desktop and the native Menu module hidden on desktop (and the reverse on
  tablet and phone).

Then: remove and undo restore the header byte for byte, the refusals (no global header, no Menu module, unknown layout, non-string input) answer as
specified, and the teardown leaves no menu, menu item, fixture or user behind. Screenshots: `dist/megamenu/<layout>.png` (13 files, git-ignored).

## Results

| Run | Width 400 | Width 900 | Width 1280 | Setup/negatives/teardown | Total |
|---|---|---|---|---|---|
| 1 | one expectation failed (below) | | | | not green |
| 2 | 120 PASS, 0 FAIL | 120 PASS, 0 FAIL | 760 PASS, 0 FAIL | 190 PASS, 0 FAIL | 1190 PASS, 0 FAIL, exit 0 |
| 3 | 120 PASS, 0 FAIL | 120 PASS, 0 FAIL | 760 PASS, 0 FAIL | 190 PASS, 0 FAIL | 1190 PASS, 0 FAIL, exit 0 |

20 of 20 layouts ran in runs 2 and 3. Two consecutive green runs on an unchanged tree.

### After the design review of 2026-11-04 (24 layouts)

A review of a competitor's free Divi 5 mega menu template (Divi Engine; only its layout JSON and instructions were read, nothing was run) and of our own screenshots led to a sixth family, **Showcase**, and four fixes to the first 20.

| Run | Width 400 | Width 900 | Width 1280 | Setup/negatives/teardown | Total |
|---|---|---|---|---|---|
| A | 144 PASS, 0 FAIL | 144 PASS, 0 FAIL | 904 PASS, 0 FAIL | 226 PASS, 0 FAIL | 1418 PASS, 0 FAIL, exit 0 |
| B | 144 PASS, 0 FAIL | 144 PASS, 0 FAIL | 904 PASS, 0 FAIL | 226 PASS, 0 FAIL | 1418 PASS, 0 FAIL, exit 0 |

24 of 24 layouts ran in both. Two consecutive green runs on an unchanged tree.

New: `showcase-intro-cards`, `showcase-tiles`, `showcase-intro-links`, `showcase-tiles-dark` (a coloured intro panel, opaque tiles with an arrow heading and links ruled by a hairline, a translucent backdrop; text only ever sits on an opaque tile, the intro panel or the panel, never on the translucent backdrop). No new attribute path was needed (`MegaMenuMarkupTest` still passes against the same proven-paths list).

Changes to the first 20, found by looking at the screenshots:

1. **Icon cards left an empty slot** (3 groups in a 2-column grid): the grid now picks a column count that fills the last row when one exists (`PanelBuilder::gridColumns`).
2. **Full-width panels did not line up with the logo**: their content is now 80% of the window, at most 1080 px, which is Divi's default row.
3. **Section headings were tiny and grey**: 13 px, accent colour.
4. **The first showcase build ran past the nav's right edge** (tiles ended at x=1208, the nav at x=1152) because each column had a fixed pixel width. Found in the screenshot, not by a check: the gate's clipping test only looks inside the panel. The showcase layouts are now one equal-width grid. Not fixed: the older flex-based multi-column layouts (`columns-3`, `feature-*`, the list and latest families) still use fixed column widths; they sit inside a panel whose width is fixed in pixels, so they do not overflow, but a full-width `columns-4-wide` and `columns-dark` now rely on the 1080 px maximum.

Gate changes: the font-size expectation accepts 17 px for a showcase tile heading; the intro panel's link now has a real hover colour (it was white on hover, so the hover check rightly failed).

Not borrowed: their cascading CSS animation (needs custom CSS, which this project does not write) and their JavaScript/CSS trigger setup. A native entrance animation on panel items is untested.

### Round 3 (2026-11-04): 13 layouts, a fancier look

The owner deleted the screenshots of 11 layouts; those were removed (list family, columns-3, columns-4-wide, feature-cta, icons-links-3, icons-2x2, latest-featured, latest-compact) and the 13 left were restyled: accent gradients (intro panel, promo card, banner, icon chips, a bar under column headings), a faint panel gradient with a 3px accent top edge, opaque cards that rise 4px, glow and take the accent frame on hover, and a filled pill button. No new attribute family: the paths are tagged `shape` (same path measured by the free plugin's effect probe, `fixtures/shapes/verified-shapes.json`).

New gate checks (desktop, first dropdown of each layout): a gradient is drawn in the panel, the panel has a 3px accent top edge, a card rises exactly 4px on a real hover and its shadow changes, a tile's frame turns to the accent, the button is a filled pill (fill, radius, no border), and button labels are in the contrast check. The `Menu`-style `a.et_pb_button` lookup no longer waits 30 s on a layout without a button.

| Run | Width 400 | Width 900 | Width 1280 | Setup/negatives/teardown | Total |
|---|---|---|---|---|---|
| A | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 562 PASS, 0 FAIL | 127 PASS, 0 FAIL | 845 PASS, 0 FAIL |
| B | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 562 PASS, 0 FAIL | 127 PASS, 0 FAIL | 845 PASS, 0 FAIL |

13 of 13 layouts, two consecutive runs. After them one cosmetic change (the intro panel's arrow chip stayed round by letting its row wrap) was re-run on the two intro layouts: 132 PASS, 0 FAIL.

Defects found by the first runs of this round and fixed: an empty block (the heading bar) was written as `<!-- wp:divi/group ... --><!-- /wp:divi/group -->` while WordPress saves it self-closing, so a second apply reported a change and the undo points broke (`Blocks::block` now writes an empty block self-closing); the banner had no hover lift (added); the weight check did not accept the bold card titles.

### Round 4 (2026-11-04): entrance animation

Spike first: a card with Divi's own entrance animation (`module.decoration.animation`, slide from the bottom) was sampled over three hover-opens in a row on Divi 5.15.1. Every open replayed it: opacity 0 to 1 over about 500 ms, the `et_animated slideBottom` class on during it and gone after, the card back at its resting place (and the hover lift still works afterwards). So a hidden Dropdown does NOT swallow the animation, which was the open question of round 3.

Built: every card (intro panel, promo card, banner, tiles, icon cards, post cards) slides up 8% while fading in, 450 ms, each card starting 70 ms after the one before it (at most 5 steps); no custom code, eight new group paths (`style`, `direction`, `duration`, `delay`, `startingOpacity`, `speedCurve`, `repeat`, `intensity.slide`), tagged `render:gate`.

Gate: the panel is measured only after the animation has ended (1100 ms after the hover), and a new check proves, on two opens in a row per card layout, that the cards animate when the panel opens and are fully visible with the animation gone about a second later.

| Run | Width 400 | Width 900 | Width 1280 | Setup/negatives/teardown | Total |
|---|---|---|---|---|---|
| A | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 602 PASS, 0 FAIL | 127 PASS, 0 FAIL | 885 PASS, 0 FAIL |
| B | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 602 PASS, 0 FAIL | 127 PASS, 0 FAIL | 885 PASS, 0 FAIL |

Limits: whether Divi honours `prefers-reduced-motion` for this animation was not tested; on touch (click to open) the animation was not measured; the older flex-based column layouts still use fixed column widths.

### Round 5 (2026-11-04): one item only, and Undo last change

**One item only.** Once a mega menu is on, every layout card on the Mega menu tab gets an "Apply to" choice: all menu items, or only one top-level item that has a panel. The other items keep the layout they have. Each panel is saved with an admin label `JHMG panel <menu item id> <layout id>` (`module.meta.adminLabel` on the Dropdown, tagged `render:gate`), and `HeaderEdit::panels()` reads those labels back, so what each item uses is always read from the header itself, never from a separate setting that could drift when the header is edited in the builder. The tab lists "Services: Link tiles" for every panel item. A panel built from a layout that no longer exists falls back to the layout just chosen. Refusals: one item before any mega menu exists ("put a layout on the whole menu first"), an item with no panel or no longer in the menu.

**Undo last change.** A button on the tab (`MegaMenuUndo`, the same `BatchUndo` as Recent Pro changes, so the same "changed since" rule): it undoes the newest undoable mega menu change, whether an apply, a one-item apply or a remove, one step at a time, and says what it undid. If the header was changed after that change (for example in the Divi builder) it refuses to overwrite anything and says so. The unit tests cover stepping back twice to the original, a header edited since, a missing licence and a missing permission.

Gate additions: the scenario applies `columns-footer` to the whole menu, then `showcase-tiles` to Blog only; checks the panel labels, a second identical apply changing nothing, one wrapper, and in the real browser that Services still has plain columns (0 cards) while Blog shows tiles (3 cards), both readable and unclipped; then three "Undo last change" calls: the whole-menu header back byte for byte, the original header back byte for byte, and "nothing to undo" changing nothing.

A gap in the older checks, found and fixed on the way: the text-contrast check only looked at paragraphs and headings, because Divi keeps link text in nested spans, so link text was never contrast-checked in any layout. It now reads links (colour of the inner span), and the full runs found no new failure.

| Run | Width 400 | Width 900 | Width 1280 | Setup/scenario/negatives/teardown | Total |
|---|---|---|---|---|---|
| A | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 609 PASS, 0 FAIL | 133 PASS, 0 FAIL | 898 PASS, 0 FAIL |
| B | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 609 PASS, 0 FAIL | 133 PASS, 0 FAIL | 898 PASS, 0 FAIL |

Limits: the admin tab's new select and button were tested through the handlers and the rendered HTML, not looked at in a browser; the one-item scenario is checked for one pair of layouts (the others use the same code path); a menu item added later has no label yet and takes the layout of the last whole-menu or one-item apply; layouts applied by an older build have no panel labels, so for them the first one-item apply treats the other items as "not recognised" and rebuilds them with the layout just chosen (the tab says so).

## Defects the gate found, and what was done

1. **Raw Dropdown settings fatal.** `module.advanced.dropdown` written as a raw object is a PHP fatal on Divi 5.15.1 (HTTP 500 for the whole
   page) and the validator accepts it. Fix: settings are always written under `desktop.value` (enforced in `Blocks::dropdown`, asserted for every
   layout by `MegaMenuMarkupTest`).
2. **Touch devices could not open a panel.** A hover-mode trigger navigates on tap. Fix: `showOn: click` per breakpoint for tablet and phone.
3. **Right-edge overflow.** An auto-width panel under the last nav item ran off the viewport. Fix: alignment `end` for auto-width panels (see limit 1).
4. **Long titles overflowed their column.** Fix: explicit column widths from the available width (`PanelBuilder`).
5. **Run 1 expectation.** The gate expected the featured post title at 16 px; the layout writes 18 px for the large post (`latest-featured`). The
   menu was right; the expectation was corrected and is documented in the check's label.
6. **Concurrent runs invalidate results.** Two gate runs at once shared one header and corrupted each other. Both were killed, the teardown was run,
   and runs 2 and 3 were serial. The gate must be run one at a time.

## Attribute paths

`pro-addon/tests/fixtures/megamenu-proven-paths.txt` lists every path the layouts write; `MegaMenuMarkupTest::testTheAttributePathsAreTheProvenOnes`
fails on a path that is not listed and on a listed path no layout uses. Evidence tags: `render:spike`, `recipe`, `render:gate` (13 paths measured
by this gate), `pending`. Pending (written and rendered without error, own effect not measured separately):

- `divi/image` `image.innerContent.{bp}.value.id`
- `divi/link` `module.decoration.spacing.{bp}.value.padding.{bottom,left,right,syncHorizontal,syncVertical}`

## Known limits (stated, not hidden)

1. `end` alignment assumes the navigation sits to the right of the logo. A header with the nav on the left may place an auto-width panel
   off-centre.
2. **Keyboard focus was not tested.** No claim is made that the panels open or can be traversed with the keyboard.
3. The hover-gap check moves the pointer in a straight line; a path that leaves the gap diagonally is not tested.
4. "Latest" layouts show a snapshot of the newest posts taken at apply time; apply again to refresh.
5. Strings the add-on writes ("View all") are English.
6. The icon layouts use one neutral glyph for every row.
7. Only the first Menu module of the default enabled Theme Builder template's global header is handled. The Menu module's search and cart icons
   are not duplicated in the desktop navigation (they stay in the hidden Menu module).
8. Tested on one header (the dev site's). The tablet and phone view keeps the native Menu module, so their behaviour is Divi's.
9. The feature image is the free plugin's bundled `assets/images/card-blue-1.svg` (a fixed placeholder, not chosen per site).
10. Not tested: RTL, a very long menu (more than about 8 top-level items), WPML/Polylang, and a header with several Menu modules.
