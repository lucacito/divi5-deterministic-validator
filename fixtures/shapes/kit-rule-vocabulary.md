# Rule kit vocabulary (divider module, group and column top rules; probe outcome, Divi 5.15.1)

What a style kit may use on a rule, decided by the effect probe (`node scripts/verify-shapes.cjs
fixtures/shapes/candidates/kit-rule.json`, results `fixtures/shapes/probe-results-kit-rule.json`, evidence
`docs/shape-evidence-5.15.1.md#kr-*`, registry `fixtures/shapes/verified-shapes.json`). Every decision was measured ON its
carrier (`divi/divider`, `divi/group`, `divi/column`), because the registry is path-only. Candidate ids are
`kr-<decision>[-<carrier>]`. Rendered on the Docker dev site (Divi 5.15.1) at 1280, 900 and 400 px, 2026-10-21:
**36 candidates, 33 PASS, 3 DEAD, 0 css-only, no phone overflow, no control noise.** The 3 DEAD are the three `*-dead-*`
controls (a value equal to Divi's default: position `top` on a divider, `styles.all.width` `0px` alone on a group and on a
column); they are default-value checks, NOT dead paths, so `dead-on-module.txt` gained no line.

- **v1**: proven on the carrier and written by the adapter.
- **not taught**: nothing in this probe fell here.

## What was measured

A divider's line is drawn on `.et_pb_divider::before` (`border-top-style`, `border-top-width`, `border-top-color` were compared
there, `pseudo: ["::before"]`). A group or column rule is `module.decoration.border` on the block itself: `styles.all` at
`0px` plus `styles.top`, as the library already wrote it. Writing `styles.all.style` / `.color` together with `styles.top.*`
(what the adapter does) changes the computed style of all four sides, but only the top side has a width, so only the top edge is
painted; the other three stay `0px` (kr-group-other-sides-zero, kr-column-top-*).

## Decisions

| Decision | Measured | Registered path | Status | Changes |
|---|---|---|---|---|
| divider line | colour and weight over a base; weights 1px, 2px, 3px, 4px (kr-divider-control, kr-divider-weight-*) | `divider.advanced.line.{bp}.value.{color,weight}` | v1 | `::before` colour and width |
| divider style | solid over dashed; dashed, dotted, double over solid at 3px (kr-divider-style-*) | `divider.advanced.line.{bp}.value.style` | v1 | `::before` style |
| divider alpha colour | `rgba(17, 24, 39, 0.4)` over `rgb(17, 24, 39)` (kr-divider-colour-alpha) | `divider.advanced.line.{bp}.value.color` | v1 | `::before` colour |
| divider double width | double at 1px (computed style double, width 1px) and at 3px (kr-divider-double-1px, -3px): the PAINTED look is NOT measured, CSS paints a double below 3px as solid | same | v1 | computed only |
| divider in a panel | line colour inside a text-filled group (kr-divider-in-panel) | same | v1 | `::before` colour |
| group top rule | `styles.all` 0px + `styles.top.width` 2px, widths 1px to 4px (kr-group-control, kr-group-top-width-*) | `module.decoration.border.{bp}.value.styles.{all,top}.{width,style,color}` | v1 | `border-top-width` |
| group top style | dashed, dotted, double over solid, written on `all` and `top` together (kr-group-top-*) | same | v1 | `border-*-style` |
| group top alpha | the alpha form on `all.color` and `top.color` (kr-group-top-colour-alpha) | same | v1 | `border-*-color` |
| group top in a card | the rule on a group nested in a card group (kr-group-top-in-card) | same | v1 | inner group `border-top-width` and height |
| column top rule / style / alpha | the same three on a column (kr-column-control, kr-column-top-{solid,dashed,dotted,double}, -width-1px, -4px, -colour-alpha) | same | v1 | column `border-top-*` |

## NOT probed, by ruling

Hover on a rule, a bottom, left or right rule, a rule on a row or section, `divider.advanced.line.position` and `show` (never
written), a rule's own spacing or length, a painted double line (the render gate checks the computed width, not the pixels).

## Adapter decisions

T3's constant `KitAdapters::RULE_PROVEN` and its tests read this table: a decision is written by the adapter only when its
verdict is PASS, and only at the path in the third column. The sixteen rows below are exactly its sixteen keys, in this order.

| Decision | Verdict | Path the adapter writes | Evidence |
|---|---|---|---|
| divider-line | PASS | `divider.advanced.line.{bp}.value.{color,weight}` | kr-divider-control, kr-divider-weight-1px/2px/3px/4px PASS |
| divider-dashed | PASS | `divider.advanced.line.{bp}.value.style` = dashed | kr-divider-style-dashed PASS |
| divider-dotted | PASS | `divider.advanced.line.{bp}.value.style` = dotted | kr-divider-style-dotted PASS |
| divider-double | PASS | `divider.advanced.line.{bp}.value.style` = double (painted look at 1px not measured; the adapter never writes it narrower than 3px) | kr-divider-style-double, kr-divider-double-3px PASS |
| divider-alpha | PASS | `divider.advanced.line.{bp}.value.color` as `rgba(r, g, b, a)` | kr-divider-colour-alpha PASS |
| group-top | PASS | `module.decoration.border.{bp}.value.styles.{all,top}.{width,style,color}`, `all.width` 0px | kr-group-control, kr-group-top-width-1px/2px/3px/4px, kr-group-top-solid PASS |
| group-top-dashed | PASS | the same with style dashed on `all` and `top` | kr-group-top-dashed PASS |
| group-top-dotted | PASS | the same with style dotted | kr-group-top-dotted PASS |
| group-top-double | PASS | the same with style double | kr-group-top-double PASS |
| group-top-alpha | PASS | the same with the alpha colour on `all` and `top` | kr-group-top-colour-alpha PASS |
| group-top-in-card | PASS | the same on a group inside a card | kr-group-top-in-card PASS |
| column-top | PASS | the group form on `divi/column` | kr-column-control, kr-column-top-width-1px/4px, kr-column-top-solid PASS |
| column-top-dashed | PASS | the same with style dashed | kr-column-top-dashed PASS |
| column-top-dotted | PASS | the same with style dotted | kr-column-top-dotted PASS |
| column-top-double | PASS | the same with style double | kr-column-top-double PASS |
| column-top-alpha | PASS | the same with the alpha colour | kr-column-top-colour-alpha PASS |
