# Chip kit vocabulary (icon chip on divi/icon, tag chip on divi/group; probe outcome, Divi 5.15.1)

What a style kit may use on a chip, decided by the effect probe (`node scripts/verify-shapes.cjs
fixtures/shapes/candidates/kit-chip.json`, results `fixtures/shapes/probe-results-kit-chip.json`, evidence
`docs/shape-evidence-5.15.1.md#kch-*`, registry `fixtures/shapes/verified-shapes.json`). Every decision was measured ON its
carrier. Candidate ids are `kch-<decision>` (the card probe owns `kc-*`). Rendered on the Docker dev site (Divi 5.15.1) at
1280, 900 and 400 px, 2026-10-21: **20 candidates, 18 PASS, 2 DEAD, 0 css-only, no phone overflow, no control noise.** The 2
DEAD are default-value controls (`icon.advanced.align` `center`, which is Divi's default; `styles.all.width` `0px` alone on a
group), NOT dead paths, so `dead-on-module.txt` gained no line. (The first control, align `left`, was wrong: `left` is not the
default and PASSed; the probe caught the wrong assumption and the control was changed to `center`, which the typography probe
had already shown DEAD, ty-ic-align-center.)

## What was measured

- **Icon chip.** `module.decoration.border.{bp}.value.radius` rounds the MODULE box: `changedElements` of every
  `kch-icon-radius-*` is exactly `div.et_pb_icon.et_pb_module` (all eight `border-*-radius` properties; no inner element).
  `module.decoration.background.{bp}.value.color` fills the same box; `icon.advanced.color` colours the glyph
  (`span.et-pb-icon`).
- **Tag chip.** A small `divi/group` with `module.decoration.border` radius (four corners) and `styles.all` width, style
  (solid, dashed, dotted, double) and colour; padding and fill were not part of the decision.

## Adapter decisions

T3's constant `KitAdapters::CHIP_PROVEN` and its tests read this table: a decision is written only when its verdict is PASS,
at the path in the third column. The eight rows are exactly its eight keys, in this order.

| Decision | Verdict | Path the adapter writes | Evidence |
|---|---|---|---|
| icon-radius | PASS | `module.decoration.border.{bp}.value.radius.{topLeft,topRight,bottomRight,bottomLeft}`; it rounds the module box `.et_pb_icon.et_pb_module` | kch-icon-radius-0px/4px/12px/999px PASS, changedElements `div.et_pb_icon_0.et_pb_icon.et_pb_module` only |
| icon-fill | PASS | `module.decoration.background.{bp}.value.color` | kch-icon-control, kch-icon-fill PASS |
| icon-glyph | PASS | `icon.advanced.color.{bp}.value` | kch-icon-glyph PASS (`span.et-pb-icon`) |
| tag-radius | PASS | `module.decoration.border.{bp}.value.radius.{topLeft,topRight,bottomRight,bottomLeft}` | kch-tag-radius-0px/12px/999px PASS |
| tag-frame | PASS | `module.decoration.border.{bp}.value.styles.all.{width,style,color}` | kch-tag-control, kch-tag-frame-1px/2px/4px, kch-tag-frame-colour PASS |
| tag-dashed | PASS | `styles.all.style` = dashed | kch-tag-style-dashed PASS |
| tag-dotted | PASS | `styles.all.style` = dotted | kch-tag-style-dotted PASS |
| tag-double | PASS | `styles.all.style` = double | kch-tag-style-double PASS |
