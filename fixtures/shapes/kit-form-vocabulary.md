# Form kit vocabulary (contact form; probe outcome, Divi 5.15.1)

What a style kit may use on a contact form, decided by the effect probe (`node scripts/verify-shapes.cjs
fixtures/shapes/candidates/kit-form.json`, results `fixtures/shapes/probe-results-kit-form.json`, evidence
`docs/shape-evidence-5.15.1.md#kf-*`, registry `fixtures/shapes/verified-shapes.json`). The carrier is `divi/contact-form`
with two `divi/contact-field` children (Name, Email). Every decision was measured ON `divi/contact-form`, because the
registry is path-only and the button work showed a path can be dead on one module and live on another. Each candidate id is
`kf-<decision>`. The form is measured as the `<form>` element and all its descendants (the submit `button`, its wrappers,
the field wrappers, the `input`s and, with the captcha on, `p.clearfix` with its label and question). What was built from
this vocabulary, its proofs and known limits: the Outcome section of `docs/superpowers/specs/2026-10-19-forms-adapter-design.md`.

Rendered on the Docker dev site (Divi 5.15.1) at 1280, 900 and 400 px, 2026-10-19: 50 candidates, 44 PASS, 0 DEAD,
6 css-only (all six are the SAME finding: a `module.decoration.*` shadow or lift, see below), no phone overflow, no control
noise. Hover candidates move the real pointer onto the submit button (`hoverSelector: ".et_pb_contact_submit"`, a probe
extension of this task; the last three shadow candidates, `kf-button-shadow-element-edge`, `-glow` and `-hover-soft`, were
run in a second, separate run after the first 44 and merged into the results file; the two submit-underline candidates
`kf-button-underline` and `kf-button-underline-hover` in a third, and `kf-button-border-width-0px` in a fourth, merged the same way) and the same hover is done on the control page. The basic captcha is switched off in every
candidate except the captcha one (its question is random on every load; that candidate ignores the size and position
properties a different question changes, the list `rc-captcha-color` uses).

- **v1**: proven on the form and a candidate for the form adapter.
- **later**: proven, not in the v1 vocabulary.
- **not taught**: DEAD or css-only on the thing the decision is about, or measured to land somewhere else.

## The big finding: the form's `module.decoration.*` is the form, not the button

On `divi/button` the shadow, the lift and the padding live on `module.decoration.*` and the button's own `button.decoration.*`
copies are DEAD. On `divi/contact-form` it is the OTHER way round. A scratch render (red hard shadow, 14px 28px padding and a
hover lift written at `module.decoration.*` of a form; checked ancestor by ancestor) shows all three land on
`div.et_pb_contact_form_container`, the outer element of the whole form:

| element | rest | pointer on the submit button |
|---|---|---|
| submit `button.et_pb_button` | `box-shadow: none`, padding `6px 20px`, `transform: none` | unchanged |
| `div.et_pb_contact_form_container` | `rgb(255, 0, 0) 6px 6px 0px 0px`, padding `14px 28px` | same, plus `transform: matrix(1, 0, 0, 1, 0, -4)` |

The probe agrees: `kf-button-shadow-module-*` and `kf-button-lift-module` changed the emitted stylesheet only (css-only: nothing
inside the `<form>` changed, because the container is outside it), and `kf-button-padding-module` narrowed the form
(`0:form:width: 1024px -> 968px`, 28px per side) instead of padding the button. The paths that reach the submit button are the
button's own: `button.decoration.boxShadow` (`10:button:box-shadow: none -> rgba(0, 0, 0, 0.18) 0px 8px 24px 0px`),
`button.decoration.transform` (`10:button:transform: none -> matrix(1, 0, 0, 1, 0, -4)` with the pointer on the button) and
`button.decoration.spacing` (the button's `padding-*` change and the button, its wrappers and the form grow: form height
182px -> 198px for 14px 28px over Divi's 6px 20px). Consequence for the adapter: the kit writes NO `module.decoration` on
the form (the form's frame, radius, maxWidth and alignment stay the recipe's), and the shadow, lift and padding of the
submit button are written at `button.decoration.*`. `module.decoration.boxShadow`/`transform` on a form still style the
whole form container; the probe did not test them for any other purpose.

Divi's own values, measured on the control pages (the adapter must write what it relies on; merge trap): submit padding
`6px 20px`, submit radius `3px`, submit frame 3px in the base of the border candidates (Divi's default was not read; a 3px to
2px change moved the form height by 2px), field fill `rgb(238, 238, 238)`, field ink `rgb(153, 153, 153)`, field radius `0px`,
placeholder `rgb(117, 117, 117)`, captcha question `rgb(102, 102, 102)`.

| Decision | Value forms that PASSED | Registered path(s) | Status | Where it lands (measured) |
|---|---|---|---|---|
| submit fill | `#f59e0b` over a transparent default (kf-button-fill) | `button.decoration.background.{bp}.value.color` | v1 | the submit button (`background-color: rgba(0, 0, 0, 0) -> rgb(245, 158, 11)`) |
| submit frame width | `1px`, `2px`, `4px` over a 3px solid frame (kf-button-border-width-*) | `button.decoration.border.{bp}.value.styles.all.width` | v1 | the button's four border widths; the button, its wrapper and the form grow or shrink by the difference (form 184 -> 180 / 182 / 186 px) |
| submit frame width 0px | `styles.all` width `0px` + style `solid` + a colour over a 2px solid frame (kf-button-border-width-0px), the form KitAdapters::button() writes for the solid/filled and ghost kits. Scratch read-back of the computed button: border widths `0px 0px 0px 0px` (top right bottom left) | `button.decoration.border.{bp}.value.styles.all.{width,style,color}` | v1 | the button (no frame; no separate decision row) |
| submit frame style | `dashed`, `dotted`, `double` over a 4px solid frame (kf-button-border-style-*) | `...styles.all.style` | v1 | the button |
| submit frame colour | `#f59e0b` over a 2px solid frame (kf-button-border-colour) | `...styles.all.color` | v1 | the button |
| submit radius | four corners `16px`, `36px`, `999px` (kf-button-radius-*): the resolved form of `{{aied:kit:radius-control}}` | `button.decoration.border.{bp}.value.radius.{topLeft,topRight,bottomRight,bottomLeft}` | v1 | the button (Divi's `3px` -> the value, 8 differences = 4 corners x 2 logical names) |
| submit label colour | `#f59e0b` over `#ffffff` (kf-button-label-colour) | `button.decoration.font.font.{bp}.value.color` | v1 | the button (`color` and the derived text-fill / caret colours) |
| submit capitalization | `uppercase` (kf-button-text-capitalization) | `button.decoration.font.font.{bp}.value.capitalization` | v1 | the button (`text-transform`); the button's wrapper and its offset change with the new width |
| submit letter spacing | `2px` (kf-button-text-letterspacing) | `...font.font.{bp}.value.letterSpacing` | v1 | the button (`letter-spacing`), same width effect |
| submit weight | `700` over `400` (kf-button-text-weight) | `...font.font.{bp}.value.weight` | v1 | the button (`font-weight`) |
| hover fill | `#1e3a8a` over a transparent rest, WITHOUT `enableColor` (the form KitAdapters writes) and with `enableColor: "on"` (the form the contact-form recipe uses): both PASS (kf-button-hover-fill, kf-button-hover-fill-enable) | `button.decoration.background.{bp}.hover.color` | v1 | the button, with the pointer on it (`background-color: rgba(255, 255, 255, 0) -> rgb(30, 58, 138)`, `transition-duration` 0.2s -> 0.3s) |
| hover label | `#f59e0b` (kf-button-hover-label) | `button.decoration.font.font.{bp}.hover.color` | v1 | the button |
| hover frame | `#f59e0b` over a 2px solid frame (kf-button-hover-border) | `button.decoration.border.{bp}.hover.styles.all.color` | v1 | the button |
| hover fill + frame + label together | the outline kits' fill swap (kf-button-hover-trio) | the three paths above | v1 | the button (19 differences) |
| submit underline | the exact `KitAdapters::button()` form for `border.sides: bottom`: `styles.all` width `0px` + style + colour, `styles.bottom` width `3px` + style + colour, hover `styles.all.color` (kf-button-underline over Divi's default frame). Scratch read-back of the computed button: border widths `0px 0px 3px 0px` (top right bottom left), bottom colour/style as written. Hover: `styles.all.color` alone (kf-button-underline-hover, a real hover) recolours only the top/left/right borders (7 differences: the six colour names plus `transition-duration`), which are 0px wide, so the visible bottom edge does NOT change on hover (scratch read-back: top `rgb(16, 185, 129)`, bottom still `rgb(245, 158, 11)`) | `button.decoration.border.{bp}.value.styles.{all,bottom}.{width,style,color}`; `button.decoration.border.{bp}.hover.styles.all.color` | v1 for the rest underline; the underline's hover colour is NOT visible (a hover that changes the bottom edge was not probed) | the submit button only (`border-bottom-width` 3px, the other sides 0px; button and form shrink by 1px against Divi's default frame) |
| submit padding | `14px` top/bottom, `28px` left/right at `button.decoration.spacing` (kf-button-padding-element); at `module.decoration.spacing` it pads the whole form instead (kf-button-padding-module) | `button.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}` | v1 at the button path ONLY | the button's `padding-*`; the form height grows 182 -> 198 px |
| submit shadow | at `button.decoration.boxShadow`: soft (`preset1`, vertical `8px`, blur `24px`, `rgba(0,0,0,0.18)`), hard (`preset4`, `6px`, `6px`, `0px`, `#111827`), edge (`preset4`, `0px`, `4px`, `0px`, `#111827`), glow (`preset1`, `0px`, `16px` blur, `rgba(245,158,11,0.45)`) and a hover-only soft (`preset1`, vertical `12px`, blur `24px`, `rgba(0,0,0,0.25)`) all PASS (kf-button-shadow-element-*): `rgba(0, 0, 0, 0.18) 0px 8px 24px 0px`, `rgb(17, 24, 39) 6px 6px 0px 0px`, `rgb(17, 24, 39) 0px 4px 0px 0px`, `rgba(245, 158, 11, 0.45) 0px 0px 16px 0px`, `rgba(0, 0, 0, 0.25) 0px 12px 24px 0px` on hover. The same values at `module.decoration.boxShadow` are css-only (they style the form container) | `button.decoration.boxShadow.{bp}.{value,hover}.{style,horizontal,vertical,blur,color}` | v1 | the button only (1 difference, `box-shadow`) |
| submit lift | `translate.y -4px` on hover at `button.decoration.transform` (kf-button-lift-element); at `module.decoration.transform` the whole form container lifts (css-only) | `button.decoration.transform.{bp}.hover.translate.y` | v1 at the button path ONLY | the button (`transform: none -> matrix(1, 0, 0, 1, 0, -4)`) |
| field fill | `#f1f5f9` over Divi's `rgb(238, 238, 238)` (kf-field-fill; control, proven on the form before) | `field.decoration.background.{bp}.value.color` | v1 | each `input` |
| field ink | `#c2410c` (kf-field-ink; control) | `field.decoration.font.font.{bp}.value.color` | v1 | each `input` |
| placeholder colour | `#c2410c`, measured as `getComputedStyle(input, "::placeholder").color`, Chrome's default `rgb(117, 117, 117)` before (kf-placeholder-colour; control) | `field.decoration.placeholderFont.font.{bp}.value.color` | v1 (the field ink does not reach it) | each input's `::placeholder` |
| field frame | width `1px` -> `2px`, `4px`; style `dashed` over solid; colour over a 2px solid frame (kf-field-frame-*; the field frame was proven on the form before) | `field.decoration.border.{bp}.value.styles.all.{width,style,color}` | v1 | each `input`; a wider frame grows the field and the form |
| field radius | four corners `16px`, `36px` over a 1px solid frame (kf-field-radius-*): the resolved form of `{{aied:kit:radius-control}}` | `field.decoration.border.{bp}.value.radius.{topLeft,topRight,bottomRight,bottomLeft}` | v1 | each `input` (Divi's `0px` -> the value) |
| field underline | `styles.all` width `0px`, style, colour + `styles.bottom` width `3px`, style, colour (kf-field-underline): over no frame (Divi draws none) only the bottom width appears (`border-bottom-width` 0 -> 3px); the same set written over a 1px all-sides frame (kf-field-underline-over-frame) drops the other three sides to 0 and the bottom edge shows | `field.decoration.border.{bp}.value.styles.{all,bottom}.{width,style,color}` | v1 | each `input` (`border-*-width` only; the field grows 51 -> 54 px in kf-field-underline) |
| field padding | `24px` on all four sides (kf-field-padding) | `field.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}` | v1 | each `input` (`padding-*`; field 51 -> 67 px, form 182 -> 214 px) |
| captcha ink | `#c2410c` over `rgb(102, 102, 102)` with the basic captcha ON (kf-captcha-ink; control, proven on the form before) | `captcha.decoration.font.font.{bp}.value.color` | v1 | the captcha row (`p.clearfix`, its label and `span.et_pb_contact_captcha_question`) |

## NOT probed, by ruling

The focus ring and a field's hover or focus state (the spec: not proven), a field shadow, labels, required marks, field
types, success and error messages, field-to-field spacing, `divi/signup`. No `field.decoration.*.hover` path was
measured, so none may be written. Combined states (frame, radius, shadow, fills, padding at once) were not probed
as one page; the render gate covers them.

## Adapter decisions

T2's const and tests read this table: a decision is written by the adapter only when its verdict is PASS, and only at the
path in the second column (the registry is path-only, so the path must also be in `verified-shapes.json`, which it is).
The six rows below are exactly the six keys of `KitAdapters::FORM_PROVEN`, in this order (`KitFormAdapterTest` compares them).

| Decision | Verdict | Path the adapter writes | Evidence |
|---|---|---|---|
| button-padding | PASS | `button.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}` (NOT `module.decoration.spacing`: it pads the whole form) | kf-button-padding-element PASS; kf-button-padding-module pads the form container |
| button-shadow | PASS | `button.decoration.boxShadow.{bp}.value.{style,horizontal,vertical,blur,color}` for soft, hard, edge, glow and the hover-only soft (NOT `module.decoration.boxShadow`: it shadows the whole form container, css-only) | kf-button-shadow-element-soft, -hard, -edge, -glow, -hover-soft PASS; kf-button-shadow-module-* css-only |
| button-lift | PASS | `button.decoration.transform.{bp}.hover.translate.y` (NOT `module.decoration.transform`: it lifts the whole form container, css-only) | kf-button-lift-element PASS; kf-button-lift-module css-only |
| field-underline | PASS | `field.decoration.border.{bp}.value.styles.{all,bottom}.{width,style,color}` | kf-field-underline PASS, kf-field-underline-over-frame PASS |
| field-padding | PASS | `field.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}` | kf-field-padding PASS |
| button-underline | PASS | `button.decoration.border.{bp}.value.styles.{all,bottom}.{width,style,color}` with `all` width `0px` and `bottom` width = the kit width (the `KitAdapters::button()` underline form); hover `styles.all.color` writes but only recolours the 0px sides, so it is invisible on an underline | kf-button-underline PASS (computed widths `0px 0px 3px 0px`); the all-sides `0px` case also PASSes (kf-button-border-width-0px, computed widths `0px 0px 0px 0px`, no extra decision row); kf-button-underline-hover PASS (7 differences, none on the bottom edge) |
