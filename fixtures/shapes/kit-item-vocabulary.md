# Item kit vocabulary (accordion and toggle; probe outcome, Divi 5.15.1)

What a style kit may use on an accordion or a toggle, decided by the effect probe (`node scripts/verify-shapes.cjs
fixtures/shapes/candidates/kit-item.json`, results `fixtures/shapes/probe-results-kit-item.json`, evidence
`docs/shape-evidence-5.15.1.md#ki-*`, registry `fixtures/shapes/verified-shapes.json`). The two carriers are `divi/accordion`
(one marker on the parent styles all its `divi/accordion-item` children; measured on the three items, the first open) and
`divi/toggle` (a stand-alone item, opened with `module.advanced.open` except for closed-state decisions). Every decision
was measured ON EACH carrier, because the registry is path-only and the button work showed a path can be dead on one
module and live on another. Each candidate id is `ki-<decision>-<carrier>`. What was built from this vocabulary, its proofs
and its known limits: the Outcome section of `docs/superpowers/specs/2026-10-19-accordion-adapter-design.md`.
Rendered on the Docker dev site (Divi 5.15.1) at 1280, 900 and 400 px, 2026-10-19: 46 candidates, 46 PASS, 0 DEAD,
0 css-only, no phone overflow, no control noise. Every module path is under `module.decoration.*` or the module's own state
groups (`openToggle`, `closedToggle`, `closedTitle`, `closedToggleIcon`, `openToggleIcon`, `content`).

The probe compares the computed styles of the module element and ALL its descendants, so the "where" of each PASS (which
element of the subtree changed) is the `changedProps` / sample of the result, summarised in the last column.

- **v1**: proven on the carrier and written by the item adapter (`KitAdapters::item()`, roles `item` and `item-rule`).
- **later**: proven, not in the v1 vocabulary.
- **not taught**: DEAD or css-only. (None.)
- **recorded, not written**: proven as an effect the adapter must NOT use.

| Decision | Value forms that PASSED | Registered path(s) | accordion | toggle | Status | Where it lands (measured) |
|---|---|---|---|---|---|---|
| frame width | `1px`, `2px`, `4px` over a 3px solid frame (ki-frame-width-*) | `module.decoration.border.{bp}.value.styles.all.width` | PASS | PASS | v1 | accordion: each of the 3 items (total height 249.8 -> 237.8 / 243.8 / 255.8 px = 3 items x 4 border sides); toggle: the toggle |
| frame style | `solid`, `dashed`, `dotted`, `double` on a 4px frame (ki-style-*) | `...styles.all.style` | PASS | PASS | v1 | each item |
| frame colour | any colour over a 2px solid frame (ki-frame-colour) | `...styles.all.color` | PASS | PASS | v1 | each item |
| underline (rest) | the `KitAdapters::card()` form: `styles.all` width `0px` + style + colour, `styles.bottom` width `3px` + style + colour (ki-edge-bottom). Read back: every item computes border widths `0px 0px 3px 0px` (top right bottom left), bottom colour/style as written, on both carriers | `...styles.{all,bottom}.{width,style,color}` | PASS | PASS | v1 | each accordion item / the toggle; the other three sides compute 0px (Divi's own default bottom edge, measured, is 1px `rgb(217, 217, 217)`) |
| radius | four corners `16px` and `36px` (36px = the largest card radius of a shipped kit) over a 1px solid frame (ki-radius-16, ki-radius-36) | `...radius.{topLeft,topRight,bottomRight,bottomLeft}` | PASS | PASS | v1 | each accordion item (36 differences = 3 items x 12) / the toggle (12); Divi also prints `overflow: hidden` on the item |
| radius clips content | NO clipping measured. Pixel check (scratch script, red title/answer/icon ink, with versus without the radius, whole module screenshot): the count of red pixels is identical with `16px`, `36px` and `999px` on both carriers at 1280 and 400 px (accordion 1179 = 1179, toggle 657 = 657), computed `overflow: hidden` and the radius on the item. Measured with Divi's default paddings (item 20px, answer 20px top) and the open answer, the closed icons and the toggle's open icon all in red | same radius paths | no clip | no clip | v1. The claim holds for `space-card` of 20px or more (the measurement used Divi's default 20px insets; every shipped kit has `space-card` of 24px or more); a larger padding moves text further from the corner, so it cannot clip more | (a radius that is larger than the content's inset from a corner is the only way to clip; not reached by any shipped kit value) |
| shadow: soft | `preset1` + vertical `8px`, blur `24px`, colour `rgba(0,0,0,0.18)` -> computes `rgba(0, 0, 0, 0.18) 0px 8px 24px 0px` (ki-shadow-soft) | `module.decoration.boxShadow.{bp}.value.{style,vertical,blur,color}` | PASS | PASS | v1 | accordion: EACH item gets the shadow (3 `box-shadow` differences, module.json boxShadow selector `{{selector}} .et_pb_toggle`); toggle: the toggle |
| shadow: hard | `preset4` + horizontal `6px`, vertical `6px`, blur `0px` -> `rgb(17, 24, 39) 6px 6px 0px 0px` (ki-shadow-hard) | `...boxShadow...{style,horizontal,vertical,blur,color}` | PASS | PASS | v1 | per item / the toggle |
| shadow: edge | `preset4` + horizontal `0px`, vertical `4px`, blur `0px` -> `rgb(17, 24, 39) 0px 4px 0px 0px` (ki-shadow-edge) | same | PASS | PASS | v1 | per item / the toggle |
| shadow: glow | `preset1` + vertical `0px`, blur `16px`, colour `rgba(245,158,11,0.45)` over a 1px frame -> `rgba(245, 158, 11, 0.45) 0px 0px 16px 0px` (ki-shadow-glow) | `...boxShadow...{style,vertical,blur,color}` | PASS | PASS | v1 | per item / the toggle |
| open fill | any colour over a 2px solid frame (ki-fill-open) | `openToggle.decoration.background.{bp}.value.color` | PASS | PASS | v1 | the open item (1 difference) |
| closed fill | any colour over a 2px solid frame (ki-fill-closed) | `closedToggle.decoration.background.{bp}.value.color` | PASS | PASS | v1 | every closed item (2 differences on the accordion's two closed items; 1 on the toggle); Divi's own closed fill is `#f4f4f4`, so it must be written explicitly |
| open title colour | `openToggle.decoration.font.font.{bp}.value.color` `#c0392b` (ki-title-open). NEW on the accordion (no earlier probe of its openToggle font group) | `openToggle.decoration.font.font.{bp}.value.color` | PASS | PASS | v1 | the open item's `h5` title (Divi default `#333`) |
| closed title colour | accordion: `closedToggle.decoration.font.font.{bp}.value.color` (ki-title-closed-accordion, NEW; registered by this run); toggle: `closedTitle.decoration.font.font.{bp}.value.color` (ki-title-closed-toggle) | accordion `closedToggle.decoration.font.font.{bp}.value.color`; toggle `closedTitle.decoration.font.font.{bp}.value.color` | PASS | PASS | v1 | the closed items' `h5` titles (Divi default `#666`); the two carriers use DIFFERENT group names |
| closed icon colour | `closedToggleIcon.decoration.icon.{bp}.value.color` (ki-icon-closed; drawn on the title's `::before`) | `closedToggleIcon.decoration.icon.{bp}.value.color` | PASS | PASS | v1 | the closed items' icon (Divi default `#ccc`) |
| open icon colour | `openToggleIcon.decoration.icon.{bp}.value.color` (ki-icon-open-toggle) | `openToggleIcon.decoration.icon.{bp}.value.color` | n/a: Divi has no open icon on an accordion item (hidden by its stylesheet; asserted from Divi's CSS, NOT probed, so it is not in `dead-on-module.txt`) | PASS | v1 (toggle only; the adapter never writes it on an accordion, a test asserts that) | the open toggle's icon |
| answer (body) colour | `content.decoration.bodyFont.body.font.{bp}.value.color` (ki-body-colour) | `content.decoration.bodyFont.body.font.{bp}.value.color` | PASS | PASS | v1 | the open answer text (Divi default `#666`) |
| toggle padding | `24px` on all four sides (ki-padding-toggle): the toggle's bottom padding goes 20px -> 24px, height +8px | `module.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}` | not used | PASS | v1 (toggle only) | the toggle element itself |
| accordion padding | `24px` on all four sides set on the PARENT (ki-padding-accordion). Measured: the item's padding stays `20px 20px 20px 20px`, the ANSWER (`.et_pb_toggle_content`) goes from `20px 0 0 0` to `24px` on all sides | `module.decoration.spacing.{bp}.value.padding.*` | PASS, but it pads the ANSWER | not used | recorded, NOT written (the item keeps Divi's 20px; module.json spacing `propertySelectors` -> `.et_pb_toggle_content`) | the open answer |
| row gap | `module.decoration.layout.{bp}.value.rowGap` `30px` over a 2px frame (ki-rowgap-accordion): the gap between items goes 10px -> 30px | `module.decoration.layout.{bp}.value.rowGap` | PASS | n/a | later (not needed: the default is enough; an `item-rule` accordion keeps the 10px gap, `rowGap` 0px was not probed) | between the accordion's items |

## What was measured about clipping, the row gap and padding

1. **Clipping.** A radius on the accordion parent or on a toggle prints `overflow: hidden` on each item (accordion:
   `AccordionModule.php` overflow-for-radius declaration on `.et_pb_accordion_item`; toggle: `ToggleModule.php`), and it
   does not clip anything the kits use. The check (scratch script, not committed) put red ink on the title, the closed icon,
   the open icon (toggle) and the answer, rendered the module with a 1px frame and a radius of 16px, 36px and 999px against
   the same module without a radius, took a screenshot of the whole module and counted red pixels: identical counts in all
   twelve cases (accordion 1179, toggle 657), at 1280 and 400 px. Review Focus 4 of the plan (a radius that clips the open
   answer or the icon) therefore did not occur at Divi's default insets (20px). Not measured: custom very large paddings
   (they only move content away from the corner) and an image inside an answer (the guides do not put one there).
2. **Row gap.** The accordion prints `display: flex` with `row-gap: 10px`, so every gap between items is 10px, and the
   item margin is 0. It stays exactly 10px (at 1280 and 400 px) with a 2px frame, with a frame and a 16px radius, and with
   the underline form: a frame does not collapse or merge, and no item touches the next. `module.decoration.layout.rowGap`
   (30px) changes it, so the adapter does not need to write a gap.
3. **Padding.** A toggle's `module.decoration.spacing` pads the toggle (written with `!important` by Divi). An accordion's
   parent padding pads the answer only (item padding stays 20px), as `module.json` says, so it is not a way to pad an
   accordion item and the adapter must not write it. The v1 item rule is therefore: `space-card` padding on a toggle only;
   an accordion keeps Divi's 20px.
4. **Shadow on an accordion lands on every item**, not on the accordion as a whole (3 `box-shadow` differences on the
   three items), because the module.json selector is `{{selector}} .et_pb_toggle`. Shadows are written once on the parent.
5. **Divi's defaults are measured, not assumed**: closed fill `rgb(244, 244, 244)`, open fill `rgb(255, 255, 255)`,
   closed title `rgb(102, 102, 102)`, open title `rgb(51, 51, 51)`, icon `rgb(204, 204, 204)`, answer `rgb(102, 102, 102)`,
   a toggle's default bottom border is 1px `rgb(217, 217, 217)`: the adapter must write both states of every colour and
   every side it relies on (merge trap).

## Combined states

The probe measured each decision alone. A block that carries the frame, radius, shadow, both fills and the title and icon
groups at once (what the adapter writes) was not probed as one page; the render gate covers it: `make verify-kits` renders
10 item blocks per kit/language cell (on the bg and surface bands: a boxed `item` accordion, a boxed `item` toggle and an
open and a closed `item-rule` toggle; on the accent and photo bands: an `item` accordion) and measures computed border, radius, padding, shadow, fills in both states, title, body and icon
colours and contrast as painted, plus a real click on each closed item (spec Outcome).

## NOT probed, by ruling

Hover (a frame colour, a lift, a shadow) on items: spec Decision 4 rules out hover on items in v1 (an accordion's `hover`
state belongs to the parent and would recolour every item at once). No `module.decoration.*.hover` path was measured on
either carrier, so none may be written. The underline's hover frame colour stays unproven (as on cards). Also not
measured: `item-rule` composites beyond the paths above (the recipes' own paddings), per-item markers
(`divi/accordion-item` decoration), `divi/tabs`.
