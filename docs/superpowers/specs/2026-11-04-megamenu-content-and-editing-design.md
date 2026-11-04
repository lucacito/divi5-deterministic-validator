# Mega menu: your own content, hand edits, and a new admin screen (Pro, unreleased)

Owner decisions 2026-11-04, after the mockup `Mega menu workspace` (private artifact). Branch `feat/pro-megamenu`.

## Intent

The mega menu panels were generated only from the menu. The owner wants (1) pictures and texts of their own in the panels, (2) a way to edit a panel,
by form or in the Divi builder, without the next Update destroying the edit, and (3) an admin screen organised by menu item instead of by layout.

## Decisions (the owner's)

- Both edit paths, per menu item: a **Content** tab (headline, text, button label and link, picture from the Media Library) and a **Builder** path
  (edit the panel in Divi).
- No "Undo last change" button on the tab (the batch is still recorded, so Recent Pro changes on the Pro tab can undo it).
- Status words: **Automatic** (the plugin builds the panel and keeps it in step with the menu) and **Edited in Divi**; the item list says
  **Custom content** where the owner entered texts or a picture.

## Design

- **Content store** `MegaMenuContent`: one option `aied_pro_megamenu_content`, `{ menu item id => {title, text, button, url, image, edits} }`. Strings are
  trimmed and cut (title 120, text 280, button 40), the link must be http(s) or site-relative, the picture must be an image attachment. Keyed by menu item
  id, so several menus never collide; entries of items that are not in the current menu are kept and ignored.
- **Which layouts take content:** the five that have a card, banner or intro panel (`feature-right`, `feature-left`, `feature-banner`,
  `showcase-intro-cards`, `showcase-intro-links`); `Layouts` marks them `content`. Headline and text replace the item title and description, the button
  label and link replace "View all" and the item link, the picture sits at the top of the card or intro panel, and replaces the placeholder of the banner.
- **Several menus:** the mega menu follows the menu the header's Menu module shows (`MenuSource`), nothing else. Pointing the module at another menu
  makes every panel out of date (the existing drift notice), Update rebuilds from the new menu, and the content entered for the old menu's items stays
  stored and unused.
- **Hand edits:** every panel the add-on writes carries a structural fingerprint of itself as the last word of its admin label
  (`JHMG panel <item> <layout> <fingerprint>`; `PanelFingerprint`: block names and attributes in canonical key order, `builderVersion` and the label
  itself ignored, so a re-save in the builder with no change, or a renamed layer, is not an edit). A panel whose fingerprint no longer fits is
  "Edited in Divi"; a panel with no fingerprint (an older header) never is. The first design kept the fingerprints in an option; a live run showed
  that went stale whenever the header was restored (Recent Pro changes), so it moved into the panel, where a restore brings it back too. Per item the owner chooses *Ask me* (default), *Keep my version* or *Rebuild from settings*. Update and "use for all items" with an
  edited panel whose choice is *Ask me* stop and name the panels; the tab then offers "Keep my edits" and "Replace with the plugin's version". Choosing a
  new layout for one item is an explicit rebuild of that item only. The out-of-date comparison skips edited panels.
- **Admin screen:** the mockup: a status bar, a notice when the menu changed, a list of the menu items on the left, one editor on the right with the tabs
  Content, Layout and Builder; the gallery is the first-visit screen and the Layout tab. "Open in Divi" opens the header in the Theme Builder (the owner
  then selects the group "JHMG Mega Menu"; the link cannot select a panel).
- The site-wide WordPress notice stays.
- **Menu choice** (asked for after the owner saw the gate's test menu and could not switch): a dropdown on the tab lists the site's menus and sets the
  header's Menu module (so the phone menu changes too), then builds the mega menu again from it (`MegaMenuService::useMenu`, one undoable step).
  A menu without sub-items is refused while a mega menu is on.

## Not in scope

Pictures per sub-item (icon cards), several headers, several Menu modules, a live preview of the real panel.

## Outcome (2026-11-04)

Built as specified, plus the menu choice. Pro suite 2,813 tests; `make verify-pro-megamenu` two consecutive runs of 990 PASS, 0 FAIL on Divi 5.15.1
(`docs/megamenu-evidence-5.15.1.md`, round 8). Calls made while building: the fingerprint moved from an option into the panel's label after the
live gate showed the option going stale on a restore; "keep my version" puts the stored panel back byte for byte and leaves its fingerprint, so it
stays "Edited in Divi" until replaced; a header without fingerprints (older versions) is never called edited; content entered for the items of
another menu stays stored; the removed "Undo last change" button leaves undo to Recent Pro changes. Not done: pictures per sub-item, a link that
opens the builder with the panel selected (Divi has none), a live preview of the real panel.
