# Kit patterns vocabulary (a row of tags, a row of icon chips; probe P1, Divi 5.15.1, 2026-10-25)

What the wording-gaps work may teach about laying out the tag and icon-chip patterns, measured on the Docker dev site. Two
measurements: the effect probe (`node scripts/verify-shapes.cjs fixtures/shapes/candidates/kit-patterns.json`, results
`fixtures/shapes/probe-results-kit-patterns.json`, ids `kp-*`) and ONE rendered page (`fixtures/shapes/kit-patterns-rows-page.html`,
saved as a throwaway draft by `make dogfood-render`, screenshots `docs/kit-patterns-rows-1280.png` and `-400.png`; the page was built
from the kit adapters' own `chip` markers under quiet-solid and editorial, resolved by `SectionRecipes::resolveMarkup`).

## What was measured

- **The icon module's box hugs its glyph plus padding; it is not the column width.** `kp-icon-left-maxwidth-48`,
  `kp-icon-center-maxwidth-48` and `kp-icon-left-maxwidth-48-in-row` (a kit icon chip: 20px glyph, 10px padding, with a left align,
  with Divi's default centre align, and inside a flex-row wrap group) each changed only `max-width` (none -> 48px): the computed
  `width` of the module did not change at 1280, 900 or 400 px, so the box was already no wider than 48px. A `maxWidth` on a chip is
  therefore not needed to keep it from stretching, and it is NOT taught.
- **A chip's size is its glyph size plus its padding.** On the rendered page the chips carried no `icon.advanced.size`, so Divi's
  default glyph (about 96px) made 108px boxes: set the glyph size (`icon.advanced.size.desktop.value`, e.g. 20px as every recipe chip
  does) to size a chip. Align left or the default centre did not change the box.
- **A flex-row wrap group holds a row of chips or tags.** `display: flex`, `flexDirection: row`, `flexWrap: wrap`, `columnGap: 12px`,
  `rowGap: 8px`, `alignItems: center` on the parent `divi/group`: four chips and six tags each sat in one row at 1280 px and wrapped
  onto a second line at 400 px with no horizontal overflow (render.json overflow 0 at both widths). A tag is a `divi/group` with the
  kit's `chip` patch holding one `divi/text` label; it hugs the label (no width set).

## The divider's box (probe P2, Divi 5.15.1, 2026-10-25)

`node scripts/verify-shapes.cjs fixtures/shapes/candidates/kit-patterns-divider.json`, results
`fixtures/shapes/probe-results-kit-patterns-divider.json`, ids `kd-divider-weight-*`, with geometry: a `divi/divider` with a solid
2px line (the control) set to a 1px, 2px and 4px line weight. At 1280, 900 and 400 px the module rect of the control AND of every
candidate is 23px high (only its width follows the column): the line is drawn inside the box (`.et_pb_divider::before`), so the
line weight does not change the layout. The 1px and 4px candidates changed the stylesheet only (css-only), the 2px one is the
control itself (no effect). Divi's default space around a divider line is therefore that 23px box.

## Adapter decisions

| Claim | Verdict | Evidence |
|---|---|---|
| icon-hugs-left | PASS | kp-icon-left-maxwidth-48: width unchanged by a 48px cap |
| icon-hugs-center | PASS | kp-icon-center-maxwidth-48: width unchanged by a 48px cap |
| row-of-icons | PASS | rendered page: four chips in one row at 1280, two lines at 400, overflow 0 |
| row-of-tags | PASS | rendered page: six tags in one row at 1280, wrapping at 400, overflow 0 |
| divider-box-23 | PASS | kd-divider-weight-1px/2px/4px: the module rect is 23px high at 1280, 900 and 400 px for every weight |
