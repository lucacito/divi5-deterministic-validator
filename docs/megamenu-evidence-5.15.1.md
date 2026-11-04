# Pro mega menu: live evidence on Divi 5.15.1

Gate: `make verify-pro-megamenu` (`scripts/verify-pro-megamenu.cjs` + `scripts/verify-pro-megamenu.php`). Docker dev site, Divi 5.15.1, real
Chromium (Playwright), the real `MegaMenuService` as user 1. Design record: `docs/superpowers/specs/2026-11-03-pro-megamenu-design.md`.

## What the gate does

For each of the 20 layouts it applies the layout to the global header through the real service (dry run first, then apply, then a second apply that
must report `changed:false`), renders the front page at 1280, 900 and 400 px (900 and 400 with touch emulation), and for every top-level item with
sub-items it:

- checks the panel is closed before hover, opens on hover (click on touch), stays open while the pointer travels from the label into the panel, is
  inside the viewport, is on top at its centre, holds every sub-link with its href, has text contrast of at least 4.5:1, has nothing clipped, and
  closes when the pointer leaves;
- measures the effect of the attribute paths it writes (font size, weight, colour, hover colour, padding, logo copy, panel text sizes and colours);
- checks no PHP error text, no horizontal scroll, the wrapper shown on desktop and the native Menu module hidden on desktop (and the reverse on
  tablet and phone).

Then: remove and undo restore the header byte for byte, the refusals (no global header, no Menu module, unknown layout, non-string input) answer as
specified, and the teardown leaves no menu, menu item, fixture or user behind. Screenshots: `dist/megamenu/<layout>.png` (20 files, git-ignored).

## Results

| Run | Width 400 | Width 900 | Width 1280 | Setup/negatives/teardown | Total |
|---|---|---|---|---|---|
| 1 | one expectation failed (below) | | | | not green |
| 2 | 120 PASS, 0 FAIL | 120 PASS, 0 FAIL | 760 PASS, 0 FAIL | 190 PASS, 0 FAIL | 1190 PASS, 0 FAIL, exit 0 |
| 3 | 120 PASS, 0 FAIL | 120 PASS, 0 FAIL | 760 PASS, 0 FAIL | 190 PASS, 0 FAIL | 1190 PASS, 0 FAIL, exit 0 |

20 of 20 layouts ran in runs 2 and 3. Two consecutive green runs on an unchanged tree.

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
