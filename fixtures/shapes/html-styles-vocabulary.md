# html_to_divi styles vocabulary (heading, text, row, column, section and button; probe outcome, Divi 5.15.1)

What `DiviMarkup` may write when `html_to_divi` carries a source's CSS (`styles=basic`), decided by the effect probe
(`node scripts/verify-shapes.cjs fixtures/shapes/candidates/html-styles.json`, results
`fixtures/shapes/probe-results-html-styles.json`, evidence `docs/shape-evidence-5.15.1.md#hs-*`, registry
`fixtures/shapes/verified-shapes.json`). Every decision is measured ON its block, because the registry is path-only (a path proven
on one module proves nothing on another). Candidate ids are `hs-<decision>`.

**STATUS: SKELETON.** The worker wrote the candidates and this file; every verdict below is `PENDING-PROBE` until the controller
runs the live probe (plan Task 1, Steps 3 and 4) and replaces it with `PASS` or `FAIL`. Nothing here is a result yet.

- **PASS**: the candidate changed the block's computed styles at some width (`effect: true`), did not overflow at 400 px and is not
  css-only. A decision that needs a candidate expected to PASS is PASS only when that candidate PASSED.
- **FAIL**: the candidate read DEAD, css-only or OVERFLOW (or a geometry condition failed). The adapter never writes that path; a
  DEAD path also gets a line in `fixtures/shapes/dead-on-module.txt`.

## Already proven ON the block (no probe)

Evidence is an export or a served recipe on that block name (`DiviMarkupTest::known()`) plus a render gate; the live gates
(`make verify-kits`, 264 PASS) measured fill, label and radius on `divi/button`.

| Block | Path | Evidence |
|---|---|---|
| `divi/heading` | `title.decoration.font.font.{desktop,tablet,phone}.value.{color,size,weight,textAlign,family}` (Google family) | exports, served recipes, `make verify-guide-shapes` (per device) |
| `divi/text` | `content.decoration.bodyFont.body.font.{desktop,tablet,phone}.value.{color,size,weight,family}` (Google family) | exports, served recipes |
| `divi/button` | `button.decoration.background.desktop.value.color`, `button.decoration.font.font.desktop.value.color`, `button.decoration.border.desktop.value.radius.{topLeft,topRight,bottomRight,bottomLeft}` | served kit recipes, `make verify-kits` |
| `divi/section`, `divi/row`, `divi/column` | `module.decoration.background.desktop.value.color`, `module.decoration.spacing.desktop.value.padding.{top,right,bottom,left}` | exports, served recipes |
| `divi/row` | `module.decoration.sizing.desktop.value.maxWidth` (export only; effect and centring are probed below) | exports (10 rows); none on a section or a column |

## Probed decisions

| Decision | Candidates | Registered path | Status | Changes |
|---|---|---|---|---|
| heading colour (reference) | hs-heading-color-control | `title.decoration.font.font.{bp}.value.color` | PENDING-PROBE | heading `color` |
| text alignment (orientation) | hs-text-orientation-center, hs-text-orientation-right, hs-text-orientation-justified, hs-text-orientation-left (the last is the default-value check, expected DEAD) | `module.advanced.text.text.{bp}.value.orientation` | PENDING-PROBE | text `text-align` |
| text alignment (body font) | hs-text-bodyfont-align-center, hs-text-bodyfont-align-justify | `content.decoration.bodyFont.body.font.{bp}.value.textAlign` | PENDING-PROBE | text `text-align` |
| heading justify | hs-heading-align-justify | `title.decoration.font.font.{bp}.value.textAlign` | PENDING-PROBE | heading `text-align` |
| web-safe family | hs-heading-family-georgia, hs-heading-family-courier, hs-text-family-georgia | `title.decoration.font.font.{bp}.value.family`, `content.decoration.bodyFont.body.font.{bp}.value.family` | PENDING-PROBE | `font-family` |
| list ink | hs-text-ink-ul, hs-text-ink-ol | `content.decoration.bodyFont.body.font.{bp}.value.color` | PENDING-PROBE | the `li` colour |
| quote ink | hs-text-ink-quote | same | PENDING-PROBE | the `blockquote` colour |
| row max width | hs-row-max-width (geometry) | `module.decoration.sizing.{bp}.value.maxWidth` | PENDING-PROBE | row width and centring |
| row / column background | hs-row-background, hs-column-background | `module.decoration.background.{bp}.value.color` | PENDING-PROBE | `background-color` |
| column / section padding | hs-column-padding, hs-section-padding | `module.decoration.spacing.{bp}.value.padding.{top,left}` | PENDING-PROBE | `padding-*` |
| ghost button | hs-button-ghost | `button.decoration.background.{bp}.value.color`, `button.decoration.font.font.{bp}.value.color` | PENDING-PROBE | transparent fill and label colour (no adapter decision) |

## Adapter decisions

Task 7's constant `DiviMarkup::STYLE_PROVEN` and its tests read this table: a decision is written by the converter only when its
verdict is PASS, and only at the path in the third column. The thirteen rows below are exactly its thirteen keys, in this order.
`text-justify` is decided by `hs-text-orientation-justified` when `text-align-orientation` is PASS, else by
`hs-text-bodyfont-align-justify`. `row-max-width` is PASS only when `hs-row-max-width` PASSED AND its geometry shows the row centred
within 1 px of its parent.

| Decision | Verdict | Path the converter writes | Evidence |
|---|---|---|---|
| text-align-orientation | PENDING-PROBE | `module.advanced.text.text.{bp}.value.orientation` on `divi/text` | hs-text-orientation-center AND hs-text-orientation-right |
| text-align-bodyfont | PENDING-PROBE | `content.decoration.bodyFont.body.font.{bp}.value.textAlign` on `divi/text` | hs-text-bodyfont-align-center |
| text-justify | PENDING-PROBE | `orientation` = justified, or the body font `textAlign` = justify (see above) | hs-text-orientation-justified, else hs-text-bodyfont-align-justify |
| heading-justify | PENDING-PROBE | `title.decoration.font.font.{bp}.value.textAlign` = justify on `divi/heading` | hs-heading-align-justify |
| websafe-family | PENDING-PROBE | `title.decoration.font.font.{bp}.value.family` and `content.decoration.bodyFont.body.font.{bp}.value.family` = a web-safe family | hs-heading-family-georgia, hs-heading-family-courier, hs-text-family-georgia |
| row-max-width | PENDING-PROBE | `module.decoration.sizing.{bp}.value.maxWidth` on `divi/row` | hs-row-max-width (effect and geometry) |
| list-ink-ul | PENDING-PROBE | `content.decoration.bodyFont.body.font.{bp}.value.color` on a `divi/text` holding only a `ul` | hs-text-ink-ul |
| list-ink-ol | PENDING-PROBE | the same on a `divi/text` holding only an `ol` | hs-text-ink-ol |
| quote-ink | PENDING-PROBE | the same on a `divi/text` holding only a `blockquote` | hs-text-ink-quote |
| row-background | PENDING-PROBE | `module.decoration.background.{bp}.value.color` on `divi/row` | hs-row-background |
| column-background | PENDING-PROBE | `module.decoration.background.{bp}.value.color` on `divi/column` | hs-column-background |
| column-padding | PENDING-PROBE | `module.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}` on `divi/column` | hs-column-padding |
| section-padding | PENDING-PROBE | `module.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}` on `divi/section` | hs-section-padding |
