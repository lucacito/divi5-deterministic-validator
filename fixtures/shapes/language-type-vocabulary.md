# Language type vocabulary (divi/heading and divi/text; probe outcome, Divi 5.15.1)

What a design language may decide about headline type, measured by the effect probe (`node scripts/verify-shapes.cjs
fixtures/shapes/candidates/language-type.json`, results `fixtures/shapes/probe-results-language-type.json`, evidence
`docs/shape-evidence-5.15.1.md#lt-*`, registry `fixtures/shapes/verified-shapes.json`). Every decision was measured ON its
carrier (`divi/heading` at levels h1 to h4 and inside a card group, `divi/text`) with a real variable web font (Inter, 100-900)
in the base, per-device sizes present, at 1280, 900 and 400 px on the Docker dev site (Divi 5.15.1), 2026-10-24:
**41 candidates, 37 PASS, 2 DEAD, 2 css-only, no phone overflow, no control noise.**

- The 2 DEAD are `lt-heading-caps-none` and `lt-text-caps-none` (see "Not a Divi value" below).
- The 2 css-only are `lt-heading-dead-weight-500` and `lt-text-dead-weight-500`: weight 500 IS Divi's default for both
  modules, so Divi emits the CSS but the computed style does not change (`computedDiffs` 0 at every width). They are
  default-value checks, NOT dead paths, so `dead-on-module.txt` gained no line. Their `expect` was written as `PASS(css-only)`
  after the first run measured it (the first run said "expected DEAD").
- Defaults measured: a heading's weight is 500, its letter spacing `normal`, its text-transform `none`; a text body's weight is
  500, spacing `normal`, text-transform `none`. Every positive control was chosen to differ from these (weight 500 and
  spacing 0px are measured from a base of 300 and 2px).

## What was measured

The computed style of the heading element (`h1`..`h4`) or of the text module's `div` and its `p`: `font-weight` for 300, 400,
500 (from 300), 600, 700, 800 and 900; `letter-spacing` for -3px, -2px, -1px, 0px (from 2px), 1px, 2px, 3px and 4px on a heading
and 0px to 4px on a text; `text-transform` uppercase. A value written at `desktop` only reached the computed style at 900 and
400 px as well (every candidate is PASS at all three widths; the 400 px sample shows the new value), so Divi cascades a desktop
font value down. The three keys together (the real patch) worked on every heading level and inside a card.

## Decisions

| Decision | Measured | Registered path | Status | Changes |
|---|---|---|---|---|
| heading weight | 300, 400, 500, 600, 700, 800, 900 (lt-heading-control, lt-heading-weight-*) | `title.decoration.font.font.{bp}.value.weight` | v1 | `font-weight` |
| heading tracking | -3px to 4px (lt-heading-tracking-*) | `title.decoration.font.font.{bp}.value.letterSpacing` | v1 | `letter-spacing` |
| heading caps | uppercase (lt-heading-caps-uppercase) | `title.decoration.font.font.{bp}.value.capitalization` | v1 | `text-transform` |
| heading levels | the three keys on h1, h3, h4 (lt-heading-level-*) | same | v1 | all three |
| heading in a card | the three keys on an h3 in a card group (lt-heading-in-card) | same | v1 | all three |
| heading cascade | desktop-only keys reach 900 and 400 px (lt-heading-cascade) | same, `desktop` | v1 | all three at every width |
| text weight | 300 to 900 (lt-text-control, lt-text-weight-*) | `content.decoration.bodyFont.body.font.{bp}.value.weight` | v1 | `font-weight` on the module and its p |
| text tracking | 0px to 4px (lt-text-tracking-*) | `content.decoration.bodyFont.body.font.{bp}.value.letterSpacing` | v1 | `letter-spacing` |
| text caps | uppercase (lt-text-caps-uppercase) | `content.decoration.bodyFont.body.font.{bp}.value.capitalization` | v1 | `text-transform` |
| text cascade | desktop-only keys reach 900 and 400 px (lt-text-cascade) | same, `desktop` | v1 | all three at every width |

## Not a Divi value: capitalisation `none`

Divi 5.15.1 has NO capitalization value `none`: `includes/builder-5/server/Packages/StyleLibrary/Declarations/Font/Font.php`
lines 543-561 emit `text-transform` only for `uppercase`, `lowercase` and `capitalize` (and `none` only for an empty `''` over
an inherited uppercase), and `includes/builder-5/server/Migration/FontCapitalizationMigration.php` strips the key from blocks
with a `builderVersion` below 5.8.1. The probe agrees: `lt-heading-caps-none` and `lt-text-caps-none` are DEAD at every width.
So a role whose capitalisation is `none` writes NO capitalisation key; the migration drops any recipe literal so Divi's default
shows.

## NOT probed, by ruling

Size, line height, family, colour, alignment, italic and underline style (the recipes keep them); `lowercase` and `capitalize`;
hover and per-device overrides of weight, spacing and capitalisation; any module but heading and text; a text module's heading
fonts (`headingFont.h2..h4`) and link font.

## Adapter decisions

`LanguageAdapters::TYPE_PROVEN` and its tests read this table: a decision is written by the adapter only when its verdict is
PASS, and only at the path in the third column. The ten rows below are exactly its ten keys, in this order.

| Decision | Verdict | Path the adapter writes | Evidence |
|---|---|---|---|
| heading-weight | PASS | `title.decoration.font.font.desktop.value.weight` | lt-heading-control, lt-heading-weight-300/400/500/600/700/800/900 PASS |
| heading-tracking | PASS | `title.decoration.font.font.desktop.value.letterSpacing` | lt-heading-tracking--3px/-2px/-1px/0px/1px/2px/3px/4px PASS |
| heading-caps | PASS | `title.decoration.font.font.desktop.value.capitalization` = uppercase (never `none`) | lt-heading-caps-uppercase PASS; lt-heading-caps-none DEAD |
| heading-levels | PASS | the three heading keys on h1, h2, h3 and h4 | lt-heading-level-h1/h3/h4, lt-heading-cascade (h2) PASS |
| heading-in-card | PASS | the three heading keys on a heading inside a card group | lt-heading-in-card PASS |
| heading-cascade | PASS | a `desktop` value only (it reaches tablet and phone) | lt-heading-cascade PASS at 1280, 900 and 400 |
| text-weight | PASS | `content.decoration.bodyFont.body.font.desktop.value.weight` | lt-text-control, lt-text-weight-300/400/500/600/700/800/900 PASS |
| text-tracking | PASS | `content.decoration.bodyFont.body.font.desktop.value.letterSpacing` | lt-text-tracking-0px/1px/2px/3px/4px PASS |
| text-caps | PASS | `content.decoration.bodyFont.body.font.desktop.value.capitalization` = uppercase (never `none`) | lt-text-caps-uppercase PASS; lt-text-caps-none DEAD |
| text-cascade | PASS | a `desktop` value only (it reaches tablet and phone) | lt-text-cascade PASS at 1280, 900 and 400 |
