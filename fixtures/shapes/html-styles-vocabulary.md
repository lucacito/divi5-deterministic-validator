# html_to_divi styles vocabulary (heading, text, row, column, section and button; probe outcome, Divi 5.15.1)

What `DiviMarkup` may write when `html_to_divi` carries a source's CSS (`styles=basic`), decided by the effect probe
(`node scripts/verify-shapes.cjs fixtures/shapes/candidates/html-styles.json`, results
`fixtures/shapes/probe-results-html-styles.json`, evidence `docs/shape-evidence-5.15.1.md#hs-*`, registry
`fixtures/shapes/verified-shapes.json`). Every decision is measured ON its block, because the registry is path-only (a path proven
on one module proves nothing on another). Candidate ids are `hs-<decision>`.

**STATUS: SKELETON.** The worker wrote the candidates and this file; every verdict below is `PASS` until the controller
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
| heading colour (reference) | hs-heading-color-control | `title.decoration.font.font.{bp}.value.color` | PASS | heading `color` |
| text alignment (orientation) | hs-text-orientation-center, hs-text-orientation-right, hs-text-orientation-justified, hs-text-orientation-left (the last is the default-value check, expected DEAD) | `module.advanced.text.text.{bp}.value.orientation` | PASS | text `text-align` |
| text alignment (body font) | hs-text-bodyfont-align-center, hs-text-bodyfont-align-justify | `content.decoration.bodyFont.body.font.{bp}.value.textAlign` | PASS | text `text-align` |
| heading justify | hs-heading-align-justify | `title.decoration.font.font.{bp}.value.textAlign` | PASS | heading `text-align` |
| web-safe family | hs-heading-family-georgia, hs-heading-family-courier, hs-text-family-georgia | `title.decoration.font.font.{bp}.value.family`, `content.decoration.bodyFont.body.font.{bp}.value.family` | PASS | `font-family` |
| list ink | hs-text-ink-ul, hs-text-ink-ol | `content.decoration.bodyFont.body.font.{bp}.value.color` | PASS | the `li` colour |
| quote ink | hs-text-ink-quote | same | PASS | the `blockquote` colour |
| row max width | hs-row-max-width (geometry) | `module.decoration.sizing.{bp}.value.maxWidth` | PASS | row width and centring |
| row / column background | hs-row-background, hs-column-background | `module.decoration.background.{bp}.value.color` | PASS | `background-color` |
| column / section padding | hs-column-padding, hs-section-padding | `module.decoration.spacing.{bp}.value.padding.{top,left}` | PASS | `padding-*` |
| ghost button | hs-button-ghost | `button.decoration.background.{bp}.value.color`, `button.decoration.font.font.{bp}.value.color` | PASS | transparent fill and label colour (no adapter decision) |

## Probe outcome (2026-10-22, Divi 5.15.1, 1280/900/400 px)

20 candidates, 19 PASS, 1 DEAD, no phone overflow, every expectation agreed on the second run (the first run had one wrong CONTROL assumption:
`orientation: left` on `divi/text` is not a default-value no-op, Divi writes an explicit `text-align: left` where the default computes as `start`;
the control was corrected, nothing dropped). The one DEAD is a finding: **`module.advanced.text.text.{bp}.value.orientation` = `justified` does
nothing on `divi/text`** (`left`, `center` and `right` work), while `content.decoration.bodyFont.body.font.{bp}.value.textAlign` = `justify` works
(also `center`), and `title.decoration.font.font.{bp}.value.textAlign` = `justify` works on `divi/heading`. So the converter writes `orientation`
for left/center/right and the body-font `textAlign` for justify. The row max width centres the row (candidate centre = control centre at
1280 and 900 px: 640 and 450); at 400 px the row is narrower than the cap and unchanged.

## Adapter decisions

Task 7's constant `DiviMarkup::STYLE_PROVEN` and its tests read this table: a decision is written by the converter only when its
verdict is PASS, and only at the path in the third column. The thirteen rows below are exactly its thirteen keys, in this order.
`text-justify` is decided by `hs-text-orientation-justified` when `text-align-orientation` is PASS, else by
`hs-text-bodyfont-align-justify`. `row-max-width` is PASS only when `hs-row-max-width` PASSED AND its geometry shows the row centred
within 1 px of its parent.

| Decision | Verdict | Path the converter writes | Evidence |
|---|---|---|---|
| text-align-orientation | PASS | `module.advanced.text.text.{bp}.value.orientation` on `divi/text` | hs-text-orientation-center AND hs-text-orientation-right |
| text-align-bodyfont | PASS | `content.decoration.bodyFont.body.font.{bp}.value.textAlign` on `divi/text` | hs-text-bodyfont-align-center |
| text-justify | PASS | the body font `textAlign` = justify on `divi/text` (`orientation` = justified is DEAD on the text module, measured) | hs-text-orientation-justified DEAD, so hs-text-bodyfont-align-justify PASS |
| heading-justify | PASS | `title.decoration.font.font.{bp}.value.textAlign` = justify on `divi/heading` | hs-heading-align-justify |
| websafe-family | PASS | `title.decoration.font.font.{bp}.value.family` and `content.decoration.bodyFont.body.font.{bp}.value.family` = a web-safe family | hs-heading-family-georgia, hs-heading-family-courier, hs-text-family-georgia |
| row-max-width | PASS | `module.decoration.sizing.{bp}.value.maxWidth` on `divi/row` | hs-row-max-width (effect and geometry) |
| list-ink-ul | PASS | `content.decoration.bodyFont.body.font.{bp}.value.color` on a `divi/text` holding only a `ul` | hs-text-ink-ul |
| list-ink-ol | PASS | the same on a `divi/text` holding only an `ol` | hs-text-ink-ol |
| quote-ink | PASS | the same on a `divi/text` holding only a `blockquote` | hs-text-ink-quote |
| row-background | PASS | `module.decoration.background.{bp}.value.color` on `divi/row` | hs-row-background |
| column-background | PASS | `module.decoration.background.{bp}.value.color` on `divi/column` | hs-column-background |
| column-padding | PASS | `module.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}` on `divi/column` | hs-column-padding |
| section-padding | PASS | `module.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}` on `divi/section` | hs-section-padding |
