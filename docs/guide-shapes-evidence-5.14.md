# Per-device settings and equal-size images: evidence (Divi 5.14)

Date: 2026-10-14. Reproduce with `make verify-guide-shapes` (Docker dev site, real Divi, headless Chromium; every page it creates is deleted).
Each shape is saved in a throwaway draft, must pass the validator, and is then measured in a real browser at real screen widths.

## "Make the headline smaller on mobile": works today

`title.decoration.font.font.{desktop,tablet,phone}.value.size` = 48px / 36px / 24px. Measured computed font size:
**1280px wide = 48px, 900px wide = 36px, 400px wide = 24px.** This is the shape `get_style_guide` already taught and the section recipes already carry.

Also measured (same method): body text size 20px -> 15px and alignment center -> left on a phone
(`content.decoration.bodyFont.body.font.{bp}.value.size|textAlign`); padding-top 60px -> 20px on a phone
(`module.decoration.spacing.{bp}.value.padding.top`).

## Columns do not stack on a phone by themselves

Measured: two plain columns (`columnStructure` only) are **side by side on a phone**. The shapes Divi's own builder writes
(`fixtures/valid/page-17-newpage.json`: `flexColumnStructure` `equal-columns_N`, `flexWrap` wrap at `phone`/`phoneWide`, each column's
`flexType` per device, 4 columns wrap at `tablet`/`tabletWide`) do stack: the converter writes these and was measured at three widths
(2 cards: 1 row on desktop, 2 on a phone; 3 cards: 1 / 3; 4 cards: 1 row on desktop, 2 on a tablet, 4 on a phone).

## "Make mismatched images the same size without custom CSS": works, with these two settings

On each `divi/image`:

- `module.advanced.sizing.desktop.value.aspectRatio` = `{"width":"4","height":"3"}` (an object; a string such as `"4/3"` is not applied)
- `image.decoration.fit.desktop.value.objectFit` = `"cover"` (crops to fill; `"contain"` shows the whole picture)

Measured: a 16:9 and a 1:1 source image side by side render at **484x363 and 484x363** (without the settings: 484x272 and 484x484).

Not enough on their own: a fixed `height` (heights match, widths do not: 356x200 vs 200x200).
Source of the key names: Divi's own `FitStyle` (`objectFit`, `objectPosition`) and `Sizing` (`aspectRatio.width|height`) classes, then proven by the render above.

## Corrections to the first draft of this guidance

An earlier draft taught `decoration.fit.{bp}.value.type`, `image.innerContent...forceFullwidth: true` and gallery `aspect-ratio` paths. Rendering them
emitted no CSS at all: they were wrong and are not in the guides. `forceFullwidth` is `module.advanced.sizing.forceFullwidth` = "on" (also measured), not needed here.

## Not claimed

Hiding a module on one device (`disabledOn`): no real export carries it and it was not tested.
