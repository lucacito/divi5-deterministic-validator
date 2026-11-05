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

### Round 6 (2026-11-04): the menu changed after the apply, and a third level

**What the owner saw.** The dev site's header showed "My account" and "Checkout" under the first item and "newpage" and "Cart" under the second, while the
menu now had six and three sub-items. The header stored exactly those two links per panel: the panels are built once, at apply time, and nothing
connects them to the menu afterwards.

**Out-of-date check.** `MenuDrift::signature` reads every Link, Text and Heading of the stored wrapper, grouped by the panel it sits in;
`MegaMenuService::drift` builds the wrapper again from the current menu (same code, each item keeps the layout its panel has) and compares the two.
Post cards (admin label `JHMG post`) are skipped. `status()['drift']` names the items and links; the tab shows a warning and an
"Update the mega menu" button (`refresh()`), one undoable batch of the same tool. Pure tests: no drift straight after applying any of the 13
layouts on three menus; a new link, a removed link, a new top-level item, an item that gains sub-items, an order change alone; a posts layout is not
out of date because newer posts exist.

**Third level.** A second-level item that has a third level is a non-clickable label (a Text module, weight 700, the body font of the links) in the
columns, the showcase tiles (with the short gradient bar the arrow chip used to be) and the icon cards. Third-level items with their own children
stay flat links, as before. The flat footer row of "Three latest posts" keeps every sub-link a link. Path added to the proven list:
`divi/text:...bodyFont.body.font.{bp}.value.weight` (`shape`, measured by this gate on the labels).

Gate additions: labels are found in the panel as bold text and never as a link (every layout, 1280); a "menu changed" scenario adds a link under a
heading, a link under Blog and a top-level item, checks the notice and the button, checks that the front end still shows the old panels, runs a dry
update and the update, checks the new links in the panels and in the bar, a second update changing nothing, and two undos back to the header
before any mega menu, byte for byte. Defects of my own the gate caught on the way: the gate's `listed` variable was out of scope; the "plain" row
label had the weight of a link (500), so it did not look like a label (now 700); the showcase tiles lost their only gradient with the arrow chip
(a bar now); an old check read the first link in the icon panel, which is a sub-link now. A finding about the gate itself: when the site's own header
already carried a mega menu (the owner trying the tab), the gate's baseline contained it and every "back to the baseline" check failed; the setup
now takes any mega menu out for the run and teardown still restores the original bytes.

| Run | Width 400 | Width 900 | Width 1280 | Setup/scenarios/negatives/teardown | Total |
|---|---|---|---|---|---|
| A | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 612 PASS, 0 FAIL | 142 PASS, 0 FAIL | 910 PASS, 0 FAIL |
| B | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 612 PASS, 0 FAIL | 142 PASS, 0 FAIL | 910 PASS, 0 FAIL |

Limits: the notice, the Update button and the lines under it were tested through the handlers and the rendered HTML, not looked at in a browser; the
menu-changed scenario runs on one layout (`showcase-tiles`); a label's own page is no longer reachable from the panel (the owner's choice); the
comparison sees link text, addresses, labels and descriptions, not a change that alters only markup.

### Round 7 (2026-11-04): the admin screen, and a notice on every admin page

Owner feedback after using the tab: highlight the layout in use; "only on this item" gave no visible way to change an item; the per-card
dropdown was wider than its card; the out-of-date notice must be a WordPress notice, not only inside our tab; too much text.

- **In use.** A card whose layout is on the header gets an accent frame and a badge: "In use" when every item with a panel uses it, "In use on: Blog"
  otherwise (read back from the panel labels).
- **One item.** The per-card "Apply to" dropdown is gone. Once a mega menu is on and there is more than one item with a panel, "Your menu items" lists
  each item with its layout and its own layout choice and an Apply button. The cards say "Use for all items".
- **Overflow.** No dropdown on the cards any more; the row dropdowns are capped to their cell (`min-width:0; max-width:100%`).
- **WordPress notice.** `admin_notices` shows "Your mega menu is out of date" with the first change and an "Update the mega menu" button on every
  admin screen (not on the Mega menu tab, which shows it in full), to a user who may change the header and only while the licence is unlocked. The
  comparison is cached for an hour in one transient and forgotten when a menu, a menu item, the header or any post is saved or deleted, and after
  apply, update, remove and undo.
- **Less text.** One status line instead of three paragraphs; the help is folded into "How it works"; each card has one short "Best for" line (a `hint`
  per layout) instead of a sentence.

Gate additions (a real administrator session in Chromium): the notice and its button on the Dashboard before the update and not after; the tab shows
its own notice and no second WordPress one; exactly one highlighted card named "In use" with a frame; one row and one select per item and none on
the cards; nothing sticking out of the box that holds it; the help folded and the visible text under 3,200 characters (2,649 measured);
no script error. Two defects of the gate itself, both mine, found on the way: it never deleted the undo batches it recorded, so the tab offered a gate
batch as "Undo last change"; and the batch table keeps only the newest 20 rows, so a run evicted the batches that were there before it. The gate now
saves every existing row at setup, deletes what it recorded and puts the saved rows back (checked, byte for byte). An earlier cleanup of mine deleted
three batches that were the owner's own changes (they were after my last run ended, and I had assumed they were mine); they were rebuilt from the
header's undo history (the content before each write is stored there, with the same hashes) and the newest is offered by "Undo last change" again.

| Run | Width 400 | Width 900 | Width 1280 | Setup/scenarios/negatives/teardown | Total |
|---|---|---|---|---|---|
| A | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 612 PASS, 0 FAIL | 152 PASS, 0 FAIL | 920 PASS, 0 FAIL |
| B | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 612 PASS, 0 FAIL | 152 PASS, 0 FAIL | 920 PASS, 0 FAIL |

Limits: the notice runs a menu comparison once an hour and after any save, not on every page load; it needs the licence unlocked and the
`edit_theme_options` right; the tab and the notice were looked at in a browser on one header (1280 px wide), not at tablet or phone widths of wp-admin.

### Round 8 (2026-11-04): own content, hand edits, a menu choice, the redesigned screen

Spec `docs/superpowers/specs/2026-11-04-megamenu-content-and-editing-design.md`, built from a mockup the owner approved. What was added and what the
live gate (real Divi 5.15.1, a real browser, an administrator session) measured:

- **Own content.** Headline, text, button label and link, and a picture from the Media Library, per menu item, on the five layouts that have a card,
  banner or intro panel. Per layout and width: the headline replaces the item title, the text and the button label and link show, the picture loads
  (`naturalWidth > 0`) with the Media Library's alt text, and the banner's placeholder picture is gone. Through the real form: the fields hold what was
  saved, the Media Library window opens, the small preview follows what is typed, "Save and update the header" puts it on the front end,
  "Reset to the defaults" takes it away.
- **Hand edits.** A panel changed the way the builder does it (a text edited, the header written with `wp_update_post`) is seen as edited and the
  item list says so. Through the real buttons: Update stops and asks naming the panel and changes nothing, "Keep my changes" updates the rest and
  leaves the edited panel byte for byte, "Replace my version" rebuilds it; an item set to "Keep my version" is kept without asking, one set to "Ask me"
  asks. The comparison treats a re-save with another key order or builder version, or a renamed layer, as no edit (unit tests).
- **A design flaw the gate found.** The first design kept each panel's fingerprint in an option. After the gate restored an earlier header through
  Recent Pro changes the option no longer matched the header, and the next update refused to proceed ("you changed the panels of Services and Blog")
  although nobody had. The fingerprint now lives in the panel's own label, so a restore brings it back with the panel.
- **The menu choice.** A dropdown on the tab lists the site's menus; choosing one sets the header's Menu module and builds the mega menu from it, and
  choosing the first menu again brings its panels back (real browser: the select, the notice, the Menu module's setting, the panels and the front end).
  Unit tests: undo gives the old menu back byte for byte, a menu without sub-items is refused while a mega menu is on, the content for the old
  menu's items stays stored.
- **Removed:** the "Undo last change" button (the batch is still recorded for Recent Pro changes).
- **The screen.** Gallery before a mega menu is on; once on, a list of items with one editor (Content, Layout, Builder); a WordPress notice on every
  admin screen when the menu changed; "Open in Divi" is the builder address Divi's own Theme Builder builds (`et_tb=1`, `app_window=1`).

Defects of my own the gate caught on the way: a script that looked for `wp.media` before WordPress had printed it (the picture button was disabled);
the admin page transition that stalls headless Chromium after a redirect (the gate's admin sessions use reduced motion); a gate step list I cut by
mistake. A mistake on the dev site, now guarded against: a cleanup of mine once deleted three batch rows that were the owner's own changes (they were
rebuilt from the header's undo history), and a gate run is now careful to save and restore the rows that were there. While the gate runs it
temporarily points the real header at its throwaway menu; anything saved on the dev site in that time is overwritten when it puts the header back.

| Run | Width 400 | Width 900 | Width 1280 | Setup/scenarios/negatives/teardown | Total |
|---|---|---|---|---|---|
| A | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 652 PASS, 0 FAIL | 182 PASS, 0 FAIL | 990 PASS, 0 FAIL |
| B | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 652 PASS, 0 FAIL | 182 PASS, 0 FAIL | 990 PASS, 0 FAIL |

Limits: pictures only on those five layouts (none per sub-item); "Open in Divi" opens the header, not the panel, and headless Chromium did not
finish painting the builder in 12 seconds, so only that the builder container loads is proven; the live preview beside the form is a rough card; a
header applied before the fingerprints existed has none, so its hand edits are not seen until it is applied once more; only the first Menu module of
the header and the menu it shows are followed.

### Round 9 (2026-11-04): visual polish of the tab

Done from screenshots of every state of the tab (first visit, Content, Layout and Builder tabs, the question about an edited panel, the out-of-date
notice, the Dashboard notice, 820 px wide) taken before and after, on the gate's throwaway menu. Functionality unchanged. What changed:
one toolbar instead of four stacked rows (title and On/Off, then Header and the menu dropdown, quiet "View on site" and "Remove mega menu" links on
the right; "Use this menu" appears only after another menu is chosen); underline tabs instead of WordPress's grey boxes; neutral outline buttons on
the cards instead of a wall of solid blue (one accent action per screen); the "In use" frame is a 1 px accent line, and the "This item" and "Picture"
tags sit below the preview instead of over it; the selected item has a thin accent bar; the amber notices are lighter and tighter; helper text is
shorter and smaller; the content form shows live character counts; one small spacing and colour scale throughout. Two fixes found in the screenshots:
WordPress moved another plugin's notice into the toolbar (the page now carries a `.wp-header-end` marker so foreign notices sit above the toolbar), and a
leftover "you changed a panel" question could show with no mega menu on (it now shows only while one is on).
The gate and the unit tests are unchanged apart from one new test; two consecutive full runs 990 PASS, 0 FAIL, Pro suite 2,814 tests.

### Round 10 (2026-11-04): a choice of icons for the icon layouts

The owner asked to choose the icon on the layouts that have icons ("Icon cards" and "Icon cards, dark"; every card had the same neutral arrow).

- **What it does.** On the Content tab of those two layouts: one icon for every card of the panel (a grid of 49 tiles) and, if wanted, another for single
  cards (a dropdown per card, with the glyph shown beside it at once). The tiles are the real glyphs, drawn with the Font Awesome file Divi ships
  (`core/admin/fonts/fontawesome/fa-solid-900.woff2`, loaded by the tab's own `@font-face`; without that file the tiles fall back to text labels). The choice is
  stored per menu item (`MegaMenuContent` field `icon`; on a top-level item it is the panel's icon, on a sub-item that card's), survives Update and
  "Reset to the defaults" of the texts, and "Reset to the arrow" clears it. Choosing the arrow for the panel clears the choice; for one card it is a choice.
  A choice made while another layout is on is kept and shows when an icon layout is used. Layouts without icons ignore it.
- **The set.** `MegaIcons`: 49 general-purpose Font Awesome 5 solid icons (Arrow, Star, Heart, Home, Team, Cart, Email, Phone, Location, Code, Settings,
  Launch, Growth, Design and more). Proven by the gate against Divi's own font file: every one is a glyph of that file under the same name (1,001 glyphs
  in it). The icon is written the same way the arrow always was (`divi/icon`, `type: fa`, `weight: 900`), so no new attribute path.
- **Measured live on the throwaway menu.** On both icon layouts the Services panel shows a rocket on two cards and a house on "Build" (chosen), the footer
  arrow stays, every icon is drawn with FontAwesome at weight 900 and has a width. Through the real form: the picker shows every tile drawn with the real font
  and the rocket as chosen; choosing a heart for the panel and a star for one card shows the star next to the choice at once, and "Save icons and update the
  header" puts star, house and heart on the cards; "Reset to the arrow" brings the arrow back on every card.
- **A defect the gate found in the dev site, not in the code.** A save request ran out of PHP memory (128 MB) on the second full run of the day. The plugin's
  own use was about 1.6 MB; the header layout had 2,258 saved WordPress revisions, almost all made by earlier gate runs (every write of the header makes one,
  and WordPress loads a post's revisions into memory when it saves another). Those revisions were deleted (the four older ones, the owner's, stay) and the
  gate now removes the revisions it makes. A real site would need thousands of writes of its header to see this.

| Run | Width 400 | Width 900 | Width 1280 | Setup/scenarios/negatives/teardown | Total |
|---|---|---|---|---|---|
| A | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 659 PASS, 0 FAIL | 191 PASS, 0 FAIL | 1,006 PASS, 0 FAIL |
| B | 78 PASS, 0 FAIL | 78 PASS, 0 FAIL | 659 PASS, 0 FAIL | 191 PASS, 0 FAIL | 1,006 PASS, 0 FAIL |

Pro suite 2,833 tests. Limits: 49 icons only (no search, no custom icon); the tiles need Divi's font file on the site (without it they show labels); an
icon is per panel or per card, not per layout.

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

## Round 11: the grouped list with counts (2026-11-05)

A 14th layout, `directory-counts`, built from a screenshot of a catalogue menu the owner showed (three titled columns of icon rows with a count pill, and two linked cards along the bottom).

- Run: `make verify-pro-megamenu`, one full run on Divi 5.15.1: 14 of 14 layouts, 1,090 PASS 0 FAIL (width 400: 84, 900: 84, 1280: 718, other: 204). Pro suite 2,917 tests.
- New in the gate: per-layout checks for `directory-counts` on "Services" (the panel's rocket on all ten rows, the three group names computed `text-transform: uppercase`, `letter-spacing: 1px`, `12px`), and a scenario `directory` on a menu of its own. The scenario makes two throwaway categories with two and one throwaway published posts (deleted in teardown, which checks that none is left), points the header at a menu holding them, applies the layout and measures in a real browser: the pill text equals the term count and the number of published posts (2 and 1), a plain link has no pill, the two group names are not links, the two loose links are cards of the same size on one line with their descriptions and an arrow (U+F061), a real hover gives the row `rgb(238, 242, 255)` and the link `rgb(29, 78, 216)`, a real hover on a card gives `translateY(-4px)` and the accent frame, text contrast at least 4.5:1 (12 texts, minimum 5.99), nothing clipped, the panel is 806 px (760 content plus padding and frame), no console error, and the header goes back to the run's own menu with no mega menu.
- New attribute paths (proven-paths file): a group's `module.decoration.background.{bp}.hover.color` (`shape`, probe `mo-hv-*`, effect measured by the gate) and a Text module's `letterSpacing` and `capitalization` (`shape`, probe `lt-text-*` of the free plugin's language-type vocabulary, effect measured by the gate).
- Defect found by the gate on the first run: with Divi's `flexGrow` (`flex: 1 0 auto`, which never shrinks) on the cluster of icon and link, a long post title stayed on one line, the row grew past its column and the group (overflow hidden) cut the title off; the Blog panel failed "nothing clipped". The cluster no longer has `flexGrow` (the row's `space-between` places the pill), and the titles wrap. The same trap was removed from the card's text group.
- Another gate finding, not a plugin defect: the dev site has no category with a published post, so the scenario makes its own instead of reading the site's.
- Limits: only the link text is clickable (Divi's Group module has a link option, `module.advanced.link`, but its attribute shape was not proven here), the icon does not take the hover colour, counts are a snapshot taken at apply time, and the cards need second-level links with nothing under them.

## Round 12: icons per link or card only (2026-11-05)

At the owner's request the Icons tab no longer offers an icon for the whole panel (the grid of 49 tiles); every link or card has its own choice, defaulting to the arrow. One full run of `make verify-pro-megamenu` on Divi 5.15.1: 14 of 14 layouts, 1,090 PASS 0 FAIL (width 400: 84, 900: 84, 1280: 718, other: 204), Pro suite 2,919 tests. The gate's setup now stores per-card icons instead of a panel icon (rocket for "Design" and "Grow", house for "Build") and a star for the link "Branding"; the icon scenario checks that the tab has no panel-wide control, that each card's choice starts from the stored icon with the real glyph drawn beside it, the live preview, and the front end after a save and after a reset. A value an earlier build stored on a top-level item is cleared on the next save of the Icons tab and is not drawn.

## Round 13: "Open in Divi" was a blank page (2026-11-05)

The owner clicked "Open in Divi" and got a blank page at `/et_header_layout/theme-builder-layout/?et_fb=1&PageSpeed=off&et_tb=1&app_window=1`. Cause: that is the address of the layout's builder as seen INSIDE the Theme Builder's pop-up (Divi's `theme-builder/api.php` says `app_window` marks the "app window" page, and the Theme Builder script opens it as the target of `MODAL_TYPE_VISUAL_BUILDER`). On its own it is blank: measured on the dev site with the owner's header, a copy of it without the mega menu, the header as it was before the add-on touched it and a header with one line of text (all blank, no console error, no failed request), while a normal page's builder with `et_fb=1` draws. The first build only checked that the builder container loaded.

Fix: the button opens `admin.php?page=et_theme_builder&et_tb_layout_id=<header id>&et_tb_layout_type=header&et_tb_layout_global=1`, the address the Theme Builder screen shows itself after a click on the header's pencil (measured: the screen opens the pop-up, the pop-up's frame draws the header and the toolbar shows Main Canvas, Exit, Save; the layout id and type are enough, the template id is not needed). Gate: the link must be that address and, opened in a real administrator session, the toolbar (Exit, Save) must be drawn and the pop-up's frame must show the header's menu; the Pro suite forbids `app_window` and `et_fb=1` in the tab. Runs: the full gate before the final check change 1,090 PASS with this one new check FAIL (my check looked for the toolbar in the pop-up's frame; it is in the screen), then the narrowed run for the grouped list and every scenario 179 PASS 0 FAIL.

## Round 14: a gentler screen (2026-11-05)

Wording and structure only, for a user who is not technical (owner's request). The Builder tab is "Edit in Divi" with three numbered steps and a large "Open in Divi" button; the choice for later Updates is three radio cards with a sentence each (the stored values `ask`, `keep` and `rebuild` are unchanged); "Custom content" is "Customised"; the layout cards say "Used here", "Used by another item", "Your picture" and "Your icons"; the screen says "Your header", "Your menu", "Your menu items"; buttons say "Save changes" and "Save icons"; the Content and Edit-in-Divi tabs open with one sentence and close with "Nothing here is permanent..." pointing at Recent Pro changes. One full run of `make verify-pro-megamenu` on Divi 5.15.1: 14 of 14 layouts, 1,091 PASS 0 FAIL. Pro suite 2,919 tests. Checked by eye in a real browser on the dev site (screenshots of the Content and Edit-in-Divi tabs).

