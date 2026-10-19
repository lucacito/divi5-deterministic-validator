# Button kit vocabulary (probe outcome, Divi 5.15.1)

What a style kit may use on `divi/button`, decided by the effect probe (`node scripts/verify-shapes.cjs
fixtures/shapes/candidates/kit-button.json`, results `fixtures/shapes/probe-results-kit-button.json`, evidence
`docs/shape-evidence-5.15.1.md#kb-*`, registry `fixtures/shapes/verified-shapes.json`). Rendered on the Docker dev site
(Divi 5.15.1) at 1280, 900 and 400 px, 2026-10-18. Anything not PASS is not v1.

- **v1**: proven AND the button adapter (`KitAdapters`) can express it today (or does already).
- **later**: proven, but the adapter cannot express it yet (adapter work is a later task); the constant stays closed.
- **not taught**: DEAD or css-only.

| Decision | What PASSED (value forms) | Registered path(s) | Status |
|---|---|---|---|
| fill: solid | any colour over the default | `button.decoration.background.{bp}.value.color` | v1 |
| fill: transparent | `rgba(255,255,255,0)` (computes `rgba(255, 255, 255, 0)`) and `transparent` (computes `rgba(0, 0, 0, 0)`): both alpha 0, no fill (kb-fill-transparent-rgba, kb-fill-transparent-keyword) | `button.decoration.background.{bp}.value.color` | v1 (`KitAdapters::TRANSPARENT` is correct as is) |
| fill: must be written | the site's default button HAS a fill (`rgb(47, 93, 88)`, kb-fill-transparent-vs-default PASS), so an outline/ghost button must write the transparent value explicitly | same | v1 (the adapter already writes it) |
| fill: gradient | `gradient.enabled: "on"` + `direction: "90deg"` + `stops: [{position:"0",color},{position:"100",color}]` renders `linear-gradient(90deg, c1 0%, c2 100%)`; `enabled` + `direction` alone renders Divi's default stops (#2B87DA to #29C4A9) | `button.decoration.background.{bp}.value.gradient.{enabled,direction}` (export), `...gradient.stops.{n}.{color,position}` (probe, new) | later (Task 9a: no pair of the allowed colour roles (accent, text, inverse) gives a label readable on both stops in all ten languages, best worst case about 2.45:1 (accent to text under a surface label; text to muted passes but muted is not an allowed second colour), so no kit could pass the contrast proof; `fill: gradient` stays out of StyleKits) |
| border width | `1px`, `2px`, `4px` over a 3px base (each changes the computed width) | `button.decoration.border.{bp}.value.styles.all.width` | v1 |
| border style | `solid`, `dashed`, `dotted`, `double` (4px; each changes the computed style on all four sides) | `button.decoration.border.{bp}.value.styles.all.style` | v1 (`StyleKits::BORDER_STYLES` extended to these four) |
| border colour | any colour | `button.decoration.border.{bp}.value.styles.all.color` | v1 |
| partial border | `styles.all.width: "0px"` plus only `styles.bottom` (3px solid colour), or only `styles.left` (4px solid colour): only that side gets a border | `button.decoration.border.{bp}.value.styles.{bottom,left}.{width,style,color}` (probe, new) | v1 for `bottom` (Task 9a: `border.sides: bottom`, an underline); `left` stays later |
| radius | four corners, e.g. `999px` pill (ty-b-radius, earlier probe) | `button.decoration.border.{bp}.value.radius.{topLeft,topRight,bottomRight,bottomLeft}` | v1 (the adapter writes the radius kit token) |
| hover: fill-swap / invert (colours) | background, border colour and label colour change together on an outline button; WITHOUT `background.hover.enableColor` the result is identical to the run WITH it (kb-hover-outline-trio vs kb-hover-outline-trio-enable: same 19 computed differences) | `button.decoration.background.{bp}.hover.color`, `button.decoration.border.{bp}.hover.styles.all.color`, `button.decoration.font.font.{bp}.hover.color` | v1 (`enableColor` is not needed) |
| hover: darken | same paths as fill-swap with a darker colour | as above | later (needs a darker colour role the languages do not supply; not in `StyleKits::HOVERS`) |
| hover: lift | `module.decoration.transform.{bp}.hover.translate.y: "-4px"` (computes `matrix(1,0,0,1,0,-4)`), also together with a hover shadow | `module.decoration.transform.{bp}.hover.translate.y` | v1 (Task 9a: `hover: lift`) |
| shadow rest | `module.decoration.boxShadow`: `style: "preset1"`; soft (`preset1` + vertical `8px`, blur `24px`, colour `rgba(0,0,0,0.18)`); hard (`preset4` + horizontal `6px`, vertical `6px`, blur `0px`, colour) computes `rgb(17,24,39) 6px 6px 0px 0px` | `module.decoration.boxShadow.{bp}.value.{style,horizontal,vertical,blur,color}` | v1 (Task 9a: `shadow` soft and hard on `module.decoration.boxShadow`) |
| shadow hover | `module.decoration.boxShadow.{bp}.hover` `preset1` + vertical `12px`, blur `24px`, colour | `module.decoration.boxShadow.{bp}.hover.{style,vertical,blur,color}` | v1 (Task 9a: a soft shadow on hover over no rest shadow) |
| shadow on the button element | `button.decoration.boxShadow.*`: DEAD (kb-shadow-element-hard) | none | not taught |
| skew | `module.decoration.transform.{bp}.value.skew.x: "-10deg"` | `module.decoration.transform.{bp}.value.skew.x` | later (no transform decision in StyleKits) |
| transform on the button element | `button.decoration.transform.*`: DEAD (kb-skew-x-element) | none | not taught |
| text: capitalization | `uppercase` | `button.decoration.font.font.{bp}.value.capitalization` | v1 |
| text: letter spacing | `2px` (default is 1px) | `button.decoration.font.font.{bp}.value.letterSpacing` | v1 |
| text: weight | `700` over a `400` base (kb-text-weight-from-400 PASS); `700` over the default is css-only because the default button already computes 700 (kb-text-weight) | `button.decoration.font.font.{bp}.value.weight` | v1 (a kit's 700 is a no-op on the default look, not an error) |
| padding (module) | `14px` / `28px` on `module.decoration.spacing` (computes 18px to 14px vertical) | `module.decoration.spacing.{bp}.value.padding.{top,bottom,left,right}` | v1 ONLY on this path; the adapter does not write it yet (see below) |
| padding (button element) | `button.decoration.spacing.*`: DEAD (kb-padding-element, as ty-b-padding-element before) | registered from a SLIDER's button group export only | not taught on `divi/button` |

## Two findings the next tasks must act on

1. **The button adapter's padding is dead.** `KitAdapters` writes the control padding token to
   `button.decoration.spacing.desktop.value.padding`. On `divi/button` Divi 5.15.1 drops that group at render
   (`ButtonModule.php:664-712`, `:760`: `_sanitize_button_decoration_attrs` removes every `button.decoration` group whose
   `<name>Group` field is `render:false` in `module.json`; `spacingGroup` and `boxShadowGroup` are). Measured DEAD twice.
   Button padding must go to `module.decoration.spacing.{bp}.value.padding` (PASS). The registry lists the
   `button.decoration.spacing` paths because a slider's button exports them, so the path-only registry lint cannot
   catch this. KitAdapters.php was not edited in this task (out of scope).
2. **Shadows and transforms on a button are module-level only** (`module.decoration.boxShadow`,
   `module.decoration.transform`); the button element's own paths are dead for the same reason. An adapter that adds
   shadow or lift writes `module.*`, which today's adapter contract ("never `module`") excludes.
