# Card kit vocabulary (probe outcome, Divi 5.15.1)

What a style kit may use on a card, decided by the effect probe (`node scripts/verify-shapes.cjs
fixtures/shapes/candidates/kit-card.json`, results `fixtures/shapes/probe-results-kit-card.json`, evidence
`docs/shape-evidence-5.15.1.md#kc-*`, registry `fixtures/shapes/verified-shapes.json`). A card is a `divi/group` or a
`divi/column` (the two carriers); every decision was measured ON EACH carrier, because the registry is path-only and the
button work showed a path can be dead on one module and live on another. Each candidate id is `kc-<decision>-<carrier>`.
Rendered on the Docker dev site (Divi 5.15.1) at 1280, 900 and 400 px, 2026-10-19: 42 candidates (21 decisions x 2 carriers),
42 PASS, 0 DEAD, 0 css-only, no overflow at any width, no control noise. Every path is under `module.decoration.*`, the
card's own element (the first `div` of the measurement); no `<module>.decoration` group of a child is involved.

- **v1**: proven on the carrier AND the card adapter (Task 3 for the group, Task 8 for the column) may write it.
- **later**: proven, but not in the v1 vocabulary.
- **not taught**: DEAD or css-only. (None.)

| Decision | What PASSED (value forms) | Registered path(s) | group | column | Status |
|---|---|---|---|---|---|
| frame width | `1px`, `2px`, `4px` over a 3px solid base: each changes the four border widths and the box height (kc-frame-width-1px/2px/4px) | `module.decoration.border.{bp}.value.styles.all.width` | PASS | PASS | v1 |
| frame style | `solid`, `dashed`, `dotted`, `double` (4px frame; each changes the style on all four sides) (kc-style-*) | `module.decoration.border.{bp}.value.styles.all.style` | PASS | PASS | v1 |
| frame colour | any colour over a 2px solid frame (kc-frame-colour) | `module.decoration.border.{bp}.value.styles.all.color` | PASS | PASS | v1 |
| bottom-only edge | the exact underline form of `KitAdapters::button()`: `styles.all` width `0px` + style + colour, `styles.bottom` width `3px` + style + colour, hover `styles.all.color`. Rest result: only the bottom side is drawn (box height +3px, nothing else moves) (kc-edge-bottom). The hover half of this form was NOT measured in the hover state (the hover frame colour is proven on a full frame, kc-hover-frame) | `module.decoration.border.{bp}.value.styles.{all,bottom}.{width,style,color}`, `module.decoration.border.{bp}.hover.styles.all.color` | PASS | PASS | v1 |
| radius | four corners `16px` (kc-radius-16) and `999px` pill (kc-radius-pill) | `module.decoration.border.{bp}.value.radius.{topLeft,topRight,bottomRight,bottomLeft}` | PASS | PASS | v1 |
| radius clips a child | with a radius Divi also prints `overflow: hidden` on the card (computed `visible` -> `hidden`), so a full-width image touching the card's corner is cut to the rounded corner: the screenshot pixel 2px inside the top-left corner is white (page) with `24px`, the image's blue without it, on both carriers at 1280 and 400 px (kc-radius-clips-image, plus a separate pixel check in the task report). The image keeps `border-radius: 0px` itself: the clip is the card's | same radius paths | PASS | PASS | v1: a card with a radius clips its image child (an image-top card needs no radius on the image). Side effect to state: `overflow: hidden` also clips anything of a child that spills out of the card (a child shadow, a negative margin) |
| padding | `32px` on all four sides (computes 32px on each side; height grows by 64px) (kc-padding) | `module.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}` | PASS | PASS | v1 |
| background colour | any colour over a white fill (kc-background) | `module.decoration.background.{bp}.value.color` | PASS | PASS | v1 |
| shadow: soft | `preset1` + vertical `8px`, blur `24px`, colour `rgba(0,0,0,0.18)` computes `rgba(0, 0, 0, 0.18) 0px 8px 24px 0px` (kc-shadow-soft) | `module.decoration.boxShadow.{bp}.value.{style,vertical,blur,color}` | PASS | PASS | v1 |
| shadow: hard | `preset4` + horizontal `6px`, vertical `6px`, blur `0px`, colour computes `rgb(17, 24, 39) 6px 6px 0px 0px` (kc-shadow-hard) | `module.decoration.boxShadow.{bp}.value.{style,horizontal,vertical,blur,color}` | PASS | PASS | v1 |
| shadow: edge | `preset4` + horizontal `0px`, vertical `4px`, blur `0px`, colour computes `rgb(17, 24, 39) 0px 4px 0px 0px` (kc-shadow-edge) | same | PASS | PASS | v1 |
| shadow: glow | `preset1` + vertical `0px`, blur `16px`, colour `rgba(245,158,11,0.45)` (on a 1px frame) computes `rgba(245, 158, 11, 0.45) 0px 0px 16px 0px` (kc-shadow-glow) | `module.decoration.boxShadow.{bp}.value.{style,vertical,blur,color}` | PASS | PASS | v1 |
| hover: lift | `translate.y: "-4px"` computes `matrix(1, 0, 0, 1, 0, -4)` with the pointer on the card; Divi also adds `transition-property: transform, translate` and `0.3s` (kc-hover-lift) | `module.decoration.transform.{bp}.hover.translate.y` | PASS | PASS | v1 |
| hover: frame colour | `border.hover.styles.all.color` over a 2px solid frame changes all four border colours (kc-hover-frame) | `module.decoration.border.{bp}.hover.styles.all.color` | PASS | PASS | v1 |
| hover: shadow | `preset1` + vertical `12px`, blur `24px`, colour `rgba(0,0,0,0.25)` over no rest shadow computes `rgba(0, 0, 0, 0.25) 0px 12px 24px 0px` on hover (kc-hover-shadow) | `module.decoration.boxShadow.{bp}.hover.{style,vertical,blur,color}` | PASS | PASS | v1 |

## Findings the next tasks must act on

1. **Nothing is dead on either carrier.** No line was added to `fixtures/shapes/dead-on-module.txt`, and the column part of
   Task 8 is not cancelled. The registry gained no new path (`promote-shapes.php` reported 0 added: every path was
   already registered from the earlier group probes and exports), so the new evidence is the 42 `kc-*` sections in
   `docs/shape-evidence-5.15.1.md`, which pin the measurement ON the group and ON the column.
2. **A radius brings `overflow: hidden` with it, on both carriers.** That is what makes the card clip an image child (the
   bento recipe's image need not carry its own radius when its card has one). The adapter should say so in the guide and
   keep the existing bespoke radius only where the image is NOT inside the card.
3. **Measured on a plain card only.** The probe cards hold a text (and, for the clip, an image first). A column card sits in
   a row: its width comes from the row's column structure, and its border, padding and radius were measured at the
   default `4_4` structure (no overflow at 400 px). A column inside a multi-column row was not measured here.
4. **Hover on the underline form** is not measured in the hover state (see the bottom-only edge row); a kit whose card has a
   bottom-only edge should not rely on a hover colour change until it is.
