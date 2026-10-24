# Language rhythm vocabulary (divi/section vertical padding; probe outcome, Divi 5.15.1)

What a design language may decide about the air between sections, measured by the effect probe (`node scripts/verify-shapes.cjs
fixtures/shapes/candidates/language-rhythm.json`, results `fixtures/shapes/probe-results-language-rhythm.json`, evidence
`docs/shape-evidence-5.15.1.md#lr-*`, registry `fixtures/shapes/verified-shapes.json`). Every decision was measured ON
`divi/section` at 1280, 900 and 400 px on the Docker dev site (Divi 5.15.1), 2026-10-24:
**37 candidates, 35 PASS, 1 css-only, 1 DEAD, no phone overflow, no control noise.**

- The css-only is `lr-section-default-56`: 56px is the `divi/section` default at every width, so Divi emits the CSS and the
  computed style does not change (a default-value check, NOT a dead path).
- The DEAD is `lr-section-sync-alone`: `syncVertical` written alone changes nothing (the toggle is inert; it only matters beside
  a `top` and `bottom` value). Not a dead path in use: the adapter writes it beside the values, as every library section does.
  `dead-on-module.txt` gained no line.
- Every language candidate starts from a base of 24px top and bottom (24 is no written value; the default 56 would read
  css-only), without `syncVertical`.

## What was measured

All 30 `lr-<language>-<role>` candidates (10 languages x section, open, tight; the values are `DesignLanguages::sectionSpace()`
of each language) changed the computed `padding-top` and `padding-bottom` from 24px to exactly the desktop value at 1280, the
tablet value at 900 and the phone value at 400, with the bottom equal to the top (the toggle never moved a side). A section with
a background image kept its image (`background-image` is not among the changed properties at any width). The first and a later
section of a page both took the padding. A section that overlaps the one above (`margin.top` -80px) kept the same overlap area
(102400 px² before and after), so the 0px-edge and overlap recipes are unaffected by the padding of their neighbours.

## Decisions

| Decision | Measured | Registered path | Status | Changes |
|---|---|---|---|---|
| section padding | 120px control, 30 language candidates at three widths (lr-section-control, lr-<language>-<role>) | `module.decoration.spacing.{bp}.value.padding.{top,bottom}` | v1 | `padding-top`, `padding-bottom`, height |
| sync toggle | written beside the values by all 30; alone it is inert (lr-section-sync-alone DEAD) | `module.decoration.spacing.{bp}.value.padding.syncVertical` | v1 | nothing by itself |
| section with image | editorial open on an image section (lr-section-image) | same | v1 | padding only, image kept |
| first section | editorial section keys on the first section (lr-section-first) | same | v1 | padding |
| later section | editorial tight keys on a later section (lr-section-later) | same | v1 | padding |
| overlap section | editorial section keys on a section with margin.top -80px (lr-section-overlap) | same | v1 | padding; overlap area unchanged |

## Not a value: `syncVertical`

`syncVertical: "on"` alone changes no computed style (lr-section-sync-alone DEAD at every width). The adapter writes it only
beside `top` and `bottom`, where it is what the library's sections already carry and the registry proves.

## NOT probed, by ruling

Left and right padding, margin, rows and columns, a section with `%` or `vw` padding, a section with a different top and bottom,
and padding hover or scroll states. Those library sections stay literal and are named exemptions.

## Adapter decisions

`LanguageAdapters::RHYTHM_PROVEN` and its tests read this table: a decision is written by the adapter only when its verdict is
PASS, and only at the path in the third column. The six rows below are exactly its six keys, in this order.

| Decision | Verdict | Path the adapter writes | Evidence |
|---|---|---|---|
| section-padding | PASS | `module.decoration.spacing.{desktop,tablet,phone}.value.padding.{top,bottom}` | lr-section-control, lr-<language>-section/open/tight (30) PASS |
| section-sync | PASS | `module.decoration.spacing.{desktop,tablet,phone}.value.padding.syncVertical` = on, beside top and bottom | the 30 language candidates carry it and computed exactly their values; lr-section-sync-alone DEAD |
| section-image | PASS | the same on a section with a background image | lr-section-image PASS, no background change |
| section-first | PASS | the same on the first section of a page | lr-section-first PASS |
| section-later | PASS | the same on a later section | lr-section-later PASS |
| section-overlap | PASS | the same on a section with a negative top margin | lr-section-overlap PASS, overlap area 102400 before and after |
