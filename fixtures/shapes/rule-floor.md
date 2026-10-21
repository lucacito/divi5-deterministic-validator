# Quiet-rule floor (measured)

Written by `php scripts/rule-chip-audit.php --floor 155ff75`; do not edit by hand.

Every rule the section recipes shipped at revision `155ff75` (59 rules, quiet and strong, the two accent dividers that Ruling 8 later exempted from the migration included: this is the measurement of what the library shipped) is drawn in each of the 10 design languages: the rule colour (alpha included) is composited over its band (light = `bg` over white, surface = `surface` over `bg`, accent = `accent` over `bg`, panel = `text` over `bg`, dark = `overlay` over `#808080`) and judged against that band (WCAG contrast). One row per (colour, band) cell: its lowest ratio and the language that gives it.

| Library rule colour | Band | Lowest ratio | Language | Rules in the cell | Weakest rule |
|---|---|---|---|---|---|
| `border` | light | 1.184 | soft-saas | 8 | hero-editorial-minimal divi/divider 0 |
| `border` | surface | 1.275 | soft-saas | 13 | hero-spec-card divi/divider 0 |
| `accent-text:40` | accent | 1.928 | playful-pastel | 1 | pricing-three-tier divi/group 12 |
| `accent` | light | 2.304 | playful-pastel | 2 | blog-feed divi/divider 0 |
| `inverse` | dark | 9.543 | playful-pastel | 8 | card-grid-4 divi/divider 0 |
| `bg` | panel | 11.978 | organic-warm | 9 | header-divider-intro divi/divider 0 |
| `text` | light | 11.978 | organic-warm | 6 | pricing-two-plan-highlight divi/group 2 |
| `text` | surface | 13.274 | organic-warm | 12 | list-icon-dividers divi/divider 0 |

Minimum: 1.184 (hero-editorial-minimal|divi/divider|0, `border` on light, soft-saas)

Floor: 1.1

The floor is the minimum rounded DOWN to one decimal. `RuleChipAudit::QUIET_RULE_FLOOR` is written once from it and never lowered; a kit x language x band cell that cannot meet it by a role change fails, it is not excused.
