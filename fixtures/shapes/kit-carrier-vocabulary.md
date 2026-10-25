# Carrier vocabulary (testimonial card, blurb icon chip, number-counter top rule, accent divider; probe outcome, Divi 5.15.1)

What a style kit may write on four more carriers, decided by the effect probe (`node scripts/verify-shapes.cjs
fixtures/shapes/candidates/kit-carrier.json`, results `fixtures/shapes/probe-results-kit-carrier.json`, evidence
`docs/shape-evidence-5.15.1.md#kx-*`, registry `fixtures/shapes/verified-shapes.json`). Every decision was measured ON its
carrier (`divi/testimonial`, `divi/blurb`, `divi/number-counter`, `divi/divider` over the site preset `1ep0co506u`) at 1280, 900
and 400 px on the Docker dev site (Divi 5.15.1), 2026-10-24: **48 candidates, 45 PASS, 1 css-only, 2 DEAD (both default-value checks), no phone
overflow, no control noise.** (First run: 3 expectations disagreed, amended below.)

- `kx-blurb-icon-dead-radius-0px` is `PASS(css-only)`: radius 0px is the `.et-pb-icon` default (default-value check, NOT a dead path).
- `kx-counter-dead-all-0px` is DEAD (a default-value check: `styles.all` 0px alone changes nothing).
- `kx-divider-accent-weight-4px` is DEAD: the site preset `1ep0co506u` renders the line at 4px on the dev site, so writing 4px changes
  nothing (default-value check, NOT a dead path; weights 2px and 3px changed 4px -> 2px/3px). The preset exists only on the dev
  site; the adapter writes the weight explicitly, so a site without the preset gets the kit's weight too.
- `kx-testimonial-dead-padding-30px` expected DEAD and measured a real change (height 157 -> 155 px): Divi's testimonial default is
  not 30px on every side, so the padding path is live; the candidate is a normal PASS.
- `dead-on-module.txt` gained no line.

## What was measured

The testimonial carries the card decisions on `module.decoration.*` of the block itself (`div.et_pb_testimonial`): fill, a frame of
1/2/4px in three styles and a colour, a bottom edge, radius 16px and 999px, padding 48px, the four shadow forms, and the hover lift,
hover frame colour and hover shadow (each changed only in the hover state, with a transition). The quote disc (`::before`) is not
measured separately: the module radius candidate changes only the module's own corners and the `::before` computed style did not
change; the disc is not clipped as long as the module keeps `overflow: visible`, which the adapter never writes over (the merge
trap test pins it). A blurb's icon is `span.et-pb-icon` (the same element paints the fill and takes the radius):
`imageIcon.decoration.border.*` radius 4px, 12px, 999px, `imageIcon.decoration.background` colour and `imageIcon.advanced.color`
glyph. The number-counter's top rule is `module.decoration.border` on the block: top width 1-4px, four styles (the base of the solid
and 1px candidates was moved first so they differ from it), the alpha colour. A divider over the preset takes the accent colour, the
weights 2px and 3px, and `dashed`.

## Decisions

| Decision | Measured | Registered path | Status | Changes |
|---|---|---|---|---|
| testimonial fill | kx-testimonial-control, -fill | `module.decoration.background.{bp}.value.color` | v1 | `background-color` |
| testimonial frame | widths 1/2/4px, styles dashed/dotted/double, colour | `module.decoration.border.{bp}.value.styles.all.{width,style,color}` | v1 | border |
| testimonial underline | `all` 0px + `bottom` edge | `...styles.all` + `...styles.bottom.*` | v1 | bottom edge only |
| testimonial radius | 16px, 999px | `module.decoration.border.{bp}.value.radius.*` | v1 | corners |
| testimonial quote | radius with overflow visible: `::before` unchanged | same | v1 | nothing on the disc |
| testimonial padding | 48px (default is not 30px on every side) | `module.decoration.spacing.{bp}.value.padding.*` | v1 | padding |
| testimonial shadow | soft, hard, edge, glow | `module.decoration.boxShadow.{bp}.value.*` | v1 | `box-shadow` |
| testimonial hover lift | translate.y -4px on hover | `module.decoration.transform.{bp}.hover.translate.y` | v1 | transform |
| testimonial hover frame | frame colour on hover | `module.decoration.border.{bp}.hover.styles.all.color` | v1 | border colour |
| testimonial hover shadow | box shadow on hover | `module.decoration.boxShadow.{bp}.hover.*` | v1 | `box-shadow` |
| blurb icon radius | 4px, 12px, 999px; same element as the fill; pill in a column at 400 px | `imageIcon.decoration.border.{bp}.value.radius.*` | v1 | `span.et-pb-icon` corners |
| blurb icon fill | kx-blurb-icon-control, -fill | `imageIcon.decoration.background.{bp}.value.color` | v1 | `background-color` |
| blurb icon glyph | kx-blurb-icon-glyph | `imageIcon.advanced.color.{bp}.value` | v1 | glyph colour |
| counter top | control, widths 1-4px, solid | `module.decoration.border.{bp}.value.styles.{all,top}.*` | v1 | top border |
| counter top dashed / dotted / double / alpha | the style forms and the alpha colour | same | v1 | top border |
| divider accent over preset | accent colour, weights 2px/3px (4px is the preset default) | `divider.advanced.line.{bp}.value.{color,weight}` | v1 | `::before` colour and width |
| divider accent style | dashed | `divider.advanced.line.{bp}.value.style` | v1 | `::before` style |

## NOT probed, by ruling

Testimonial portrait, quote icon colour, author and company fonts; blurb title and body fonts; a counter's number and title fonts;
`rule-accent` on the dark or accent bands; every other module (circle counter, slides, countdown, social follow, before-after, blog,
shop, group carousel).

## Adapter decisions

`KitAdapters::CARRIER_PROVEN` and its tests read this table: a decision is written by the adapter only when its verdict is PASS, and
only at the path in the third column. The twenty rows below are exactly its twenty keys, in this order.

| Decision | Verdict | Path the adapter writes | Evidence |
|---|---|---|---|
| testimonial-fill | PASS | `module.decoration.background.desktop.value.color` | kx-testimonial-control, kx-testimonial-fill PASS |
| testimonial-frame | PASS | `module.decoration.border.desktop.value.styles.all.{width,style,color}` | kx-testimonial-frame-width-1px/2px/4px, kx-testimonial-style-dashed/dotted/double, kx-testimonial-frame-colour PASS |
| testimonial-underline | PASS | `...styles.all` 0px + `...styles.bottom.{width,style,color}` | kx-testimonial-edge-bottom PASS |
| testimonial-radius | PASS | `module.decoration.border.desktop.value.radius.*` | kx-testimonial-radius-16, kx-testimonial-radius-pill PASS |
| testimonial-quote | PASS | none (overflow stays the recipe's `visible`) | kx-testimonial-quote PASS, `::before` unchanged |
| testimonial-padding | PASS | `module.decoration.spacing.desktop.value.padding.*` | kx-testimonial-padding-48, kx-testimonial-dead-padding-30px PASS |
| testimonial-shadow | PASS | `module.decoration.boxShadow.desktop.value.*` | kx-testimonial-shadow-soft/hard/edge/glow PASS |
| testimonial-hover-lift | PASS | `module.decoration.transform.desktop.hover.translate.y` | kx-testimonial-hover-lift PASS |
| testimonial-hover-frame | PASS | `module.decoration.border.desktop.hover.styles.all.color` | kx-testimonial-hover-frame PASS |
| testimonial-hover-shadow | PASS | `module.decoration.boxShadow.desktop.hover.*` | kx-testimonial-hover-shadow PASS |
| blurb-icon-radius | PASS | `imageIcon.decoration.border.desktop.value.radius.*` | kx-blurb-icon-radius-4px/12px/999px, kx-blurb-icon-radius-on-fill, kx-blurb-icon-pill-in-column PASS |
| blurb-icon-fill | PASS | `imageIcon.decoration.background.desktop.value.color` | kx-blurb-icon-control, kx-blurb-icon-fill PASS |
| blurb-icon-glyph | PASS | `imageIcon.advanced.color.desktop.value` | kx-blurb-icon-glyph PASS |
| counter-top | PASS | `module.decoration.border.desktop.value.styles.{all,top}.{width,style,color}`, `all.width` 0px | kx-counter-control, kx-counter-top-width-1px/2px/3px/4px, kx-counter-top-solid PASS |
| counter-top-dashed | PASS | the same with style dashed | kx-counter-top-dashed PASS |
| counter-top-dotted | PASS | the same with style dotted | kx-counter-top-dotted PASS |
| counter-top-double | PASS | the same with style double | kx-counter-top-double PASS |
| counter-top-alpha | PASS | the same with the alpha colour | kx-counter-top-colour-alpha PASS |
| divider-accent-over-preset | PASS | `divider.advanced.line.desktop.value.{color,weight}` | kx-divider-accent-control, kx-divider-accent-weight-2px/3px, kx-divider-accent-alignself PASS; weight 4px DEAD (preset default) |
| divider-accent-style | PASS | `divider.advanced.line.desktop.value.style` = dashed | kx-divider-accent-style-dashed PASS |
