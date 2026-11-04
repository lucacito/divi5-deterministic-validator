# Pro mega menu: design

Status: built on branch `feat/pro-megamenu`, NOT released (no version bump, no zip). Owner asked on 2026-11-03: "a Megamenu section in the admin, a choice of 20 layouts, they click one and it appears on their header template, a notice that they need a global header; one layout like the divi5lab dropdown; build everything on the local Docker, I will check later with feedback."

## Intent

A Divi 5 site owner should get a real mega menu (a hover panel with columns, icons, a feature card, latest posts) without building one by hand and without a canvas. In the Pro add-on's admin they pick one of 20 layouts, click it, and the navigation part of their live global header becomes a mega menu. The logo, buttons and the rest of the header are untouched.

## Goals / non-goals / anti-goals

Goals: 20 ready layouts; one click; filled from the site's own WordPress menu; undoable; passes the validator (`LayoutGate`) before anything is saved; a clear notice when there is no global header.

Non-goals: building a whole header; a mobile mega menu (tablet and phone keep the native Menu module); live "latest posts" loops (see calls below); an MCP tool or REST route (the ChatGPT importer budget is full and the owner asked for an admin section; a tool can be added later through `ProTool`); custom CSS or JS (never).

Anti-goals: a layout that renders as a PHP fatal (a wrongly shaped Dropdown takes the whole page down and the validator does not catch it); deleting the user's logo; a change nobody can undo.

## Verified facts (Divi 5.15.1, live spike on the Docker site, 2026-11-03)

- `divi/dropdown` nests in `divi/link` (Link renders `child_modules_content`) and holds Groups, Links, Text, Images. It is `display:none` until hover, opens `offset` (20 px) under the label, and the pointer can travel label to panel without closing it.
- Its settings MUST be `module.advanced.dropdown.desktop.value = {showOn, direction, alignment, offset, position, horizontalMode}`. A raw object without `desktop.value` is a PHP fatal (HTTP 500). Per-breakpoint `showOn: "click"` works.
- `horizontalMode: "viewport"` gives a full-width panel (x 0, width 1280 at 1280).
- On touch, a hover-mode trigger link just navigates (the panel never opens); click mode per breakpoint opens it and does not navigate. Panels are about 326 px wide and overflow a 400 px phone, so the mega nav is desktop only.
- A Link's content is `content.innerContent.desktop.value = {text, linkUrl, linkTarget}`. `module.decoration.disabledOn.{bp}.value = "on"` hides a module on one breakpoint.
- The real header on the dev site is `section > row > column > divi/menu`; the logo, search and cart live INSIDE the Menu module (`logo.innerContent.desktop.value`).

## How it works

1. `MenuSource` finds the menu to show: the Menu module's `menuId` if that menu exists, else the menu on the first theme location, else the first menu.
2. `NavBuilder` + `PanelBuilder` (the only code that writes blocks, through `Blocks`) turn the chosen layout and the menu tree into one wrapper group: a copy of the logo plus the nav (a Link per top-level item, a Dropdown inside every item that has children).
3. `HeaderEdit` puts the wrapper after the Menu module in the same column, hides the Menu module on desktop and the wrapper on tablet and phone (`disabledOn`), so phones keep the native hamburger. The wrapper carries the admin label `JHMG Mega Menu`; applying again replaces it, "Remove" deletes it and shows the Menu module on desktop again.
4. `MegaMenuService` hands the edit to the existing `LayoutWriter::apply` (permissions: `edit_theme_options` + Divi Theme Builder, `LayoutGate`, `HistoryService` snapshot, Divi cache clear, one undoable batch). Dry run first; the admin shows the preview and asks for a confirmation.
5. `Admin/MegaMenuPanel` is a "Mega menu" tab of the free plugin's admin page (added by the Pro add-on through `jhmg_aied_admin_tabs`): the notice, the gallery of 20 layouts with generated SVG previews, Apply and Remove buttons.

## The 24 layouts

Six families times four variants, composed from data (`Layouts`), not hand-written markups. The first 20 (five families) were the original request; the sixth family, Showcase, was added on 2026-11-04 after a review of a competitor's free layout (see the evidence note).

| Family | Variants |
|---|---|
| List | plain list, list with one-line descriptions, list with icons, two-column list |
| Columns | 3 columns, 4 columns full width, 3 columns on dark, 2 columns plus a footer link bar |
| Feature | columns plus a card on the right, card on the left, banner card across the top, columns plus a CTA bar (the divi5lab "Free layouts" panel) |
| Icon grid | 2-column cards with chips and footer links (the divi5lab "Plugins" panel), 3 icon links, 2x2 icon plus description, dark icon grid |
| Latest | 3 post cards, 4 compact cards, one large plus three small, cards under a heading bar |
| Showcase | intro panel plus link cards on a tinted backdrop, link tiles on a pale backdrop, intro panel plus ruled link columns, dark link tiles (each tile: an arrow heading, then links ruled by a hairline; one equal-width grid, panel content as wide as Divi's own row so it lines up with the logo) |

## Calls I made (owner can override)

- The Menu module is kept for tablet and phone, not replaced (the logo lives inside it).
- "Latest" layouts are static cards filled from the newest posts when the layout is applied (stated in the admin). A live loop needs a real builder export of Divi's Loop option, which the repo does not have; no loop shape is invented.
- Colours, fonts and the link look are copied from the Menu module's own settings so the nav matches the header; panels use white or a dark tone, no site colour tokens that may not exist.
- No tool and no REST route yet.
- Showcase (2026-11-04): ideas only from the competitor's free layout (a coloured intro column, white cards on a translucent backdrop, an arrow heading, ruled links); none of its markup, copy, images, JavaScript or CSS is used, and its cascading CSS animation is not copied (no custom code). Full-width panels (`viewport` mode) now size their content to 80% of the window, at most 1080 px, which is Divi's default row, so they line up with the logo; a site whose header row is narrower or wider will not line up exactly.

## Proof

Pure tests (`make test-pro`): every layout builds, passes `LayoutGate`, uses only paths from the proven list, never emits a raw Dropdown; `HeaderEdit` keeps unrelated content byte for byte, replaces and removes cleanly. Live gate `make verify-pro-megamenu` on the Docker site: applies each layout to the real header through the real service, renders it with the real Divi at 1280/900/400, hovers every top-level item, checks the panel opens, stays open on the way in, is not clipped, the Menu module shows only below desktop, undo restores the header byte for byte.
