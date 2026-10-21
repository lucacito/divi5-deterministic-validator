# Rules and chips adapter: a style kit also styles rules, dividers, tag chips and icon chips

Status: APPROVED BY DELEGATION (owner 2026-10-21: "do the next adapter", then "both" to the audit's recommendation of the
rules adapter AND the chips adapter on one branch; the owner delegated every decision, so each call below that the owner
might have made differently is marked "Ruling" and is listed again in the Outcome). Branch `feat/rules-chips-adapter` from
main c0eed44 (free 4.13.0 published). Follows `2026-10-19-forms-adapter-design.md` (a module whose live paths differ from
`divi/button`), `2026-10-19-accordion-adapter-design.md` (generated `kit-rules` topic), `2026-10-19-card-adapter-design.md`
and `2026-10-18-style-kits-design.md`. NO release, version bump or zip rebuild; the validator is untouched.

## Intent and why

A kit now changes buttons, cards, panels, accordions, toggles, forms and image corners. What is left in the library that a
kit visibly should own is the thin stuff between them: the 1px and 2px lines that separate list rows, price columns, FAQ
items and team members, the `divi/divider` modules between rows of copy, and the small labels (tag pills, icon chips).
Today every one of those is a hand-written SOLID line in a hand-picked colour, so a dashed kit (hairline-dashed), a double
kit (the 4px double) or a heavy kit (heavy-block, 4px) renders buttons and cards in its own language and then draws polite
solid hairlines between them. The second payoff is outside the recipes: the generated `kit-rules` topic tells an AI that
builds a page without a recipe how a rule and a chip follow the kit.

## What the audit found (script audit, a block parser over `section-recipes.json`, 71 recipes)

| Carrier | Blocks | Recipes | Hand-written today |
|---|---|---|---|
| `divi/group` / `divi/column` top rule (`border.styles.all` 0px + `styles.top` 1px or 2px) | 29 (26 + 3) | 9 | solid, colour `border` 1px or `text` / `inverse` 2px or `accent-text:40` 1px |
| `divi/divider` with a line colour | 30 (of 31) | 14 | solid, weight 1px or 2px, colour `border` / `bg` / `inverse` / `text` / `accent` |
| tag chip: a small `divi/group` with a 12px radius, 1px frame and 4px 10px padding | 5 | 1 (portfolio-featured-grid) | radius 12px, frame `border`, no fill |
| icon chip: a `divi/icon` with `accent` fill, `accent-text` glyph, padding and radius | 9 | 2 (icon-values 3 at 5px radius, features-carousel-strip 6 at 999px) | radius is a literal |

The first audit counted the step badges (steps-horizontal, four 48px circles) and the timeline dots (timeline-vertical, four
14px squares) as chips. They are SHAPE decisions (a circle, a square dot), like the avatars and arches of the image-radius
pass, so they stay literal and are listed in `BESPOKE_CHIPS`-style exemptions in the migration test. The real reach is
therefore 59 rule blocks in 23 recipes and 14 chip blocks in 3 recipes. The chip half is small on purpose: the owner asked
for both, and the cost of the chip half is one role and about 14 markers.

What the repo already knows: `divider.advanced.line.{bp}.value.{color,weight,style,position,show}` are in the shape registry
(path-only, from a real export; the style guide already teaches `"style":"dashed"` on a divider, `StyleGuide.php` line 1824)
and the recipes already render a top-only group border. NOT proven on the carriers (the registry is path-only, the forms
lesson): `style` dashed/dotted/double on a divider, `styles.top.style` dashed/dotted/double on a group and on a column, a
`double` line at 1px (CSS draws a solid line below 3px), a kit radius token on `divi/icon`'s `module.decoration.border`, and
whether `module.decoration.border` on `divi/icon` hits the module box or the glyph. The probe (Task 1) decides each; the
adapter writes nothing the probe did not prove ON the carrier.

## Goals, non-goals, anti-goals

Goals:
1. Marker roles `rule` (quiet) and `rule-strong` on `divi/divider`, `divi/group` and `divi/column`, and a role `chip` on
   `divi/group` (a tag) and `divi/icon` (an icon chip), all `"aied":{"kit":"<role>","on":"<band>"}`.
2. All 59 rule blocks and all 14 chip blocks of the library migrate (marker plus dropping the literal decoration the kit now
   writes); the shrink-only `BESPOKE_RULES` and `BESPOKE_CHIPS` end EMPTY (cap 0).
3. Proof level of the card: contrast over every kit x language x band x role, zero exceptions, no baseline file; a golden; a
   visible-difference rule; the dead-on-module guard; a render gate with computed styles in a real browser.
4. Guide wording generated from `KitAdapters::APPLIED`; no new topic; no growth of the default guide.

Non-goals: step badges and timeline dots (shape decisions); the section `dividers` topic (wave and curve edges between
sections, a different thing: `module.advanced.dividers`); a vertical rule between columns; a rule on a row or section;
gradient rules; an icon's glyph size or family; the chip's label font; hover on any of these; `divi/blurb`,
`divi/testimonial` and counters (13 blocks in 4 recipes, mostly font and colour, left for another day).
Anti-goals: a new guide topic, a new MCP tool, REST route or OpenAPI operation, any custom CSS, any hex in a recipe, a
weakened test, a frozen contrast failure, a change to a golden other than a documented addition.

## Design

### 1. Roles, carriers, bands

| Role | Carriers | Meaning | Bands |
|---|---|---|---|
| `rule` | `divi/divider`, `divi/group`, `divi/column` | a quiet separator: the kit's line style, the kit's width (1px floor), the decorative hairline colour | light, surface, accent, dark, panel |
| `rule-strong` | the same | a structural rule: the kit's style, a weight one step heavier, the strong colour | the same |
| `chip` | `divi/group`, `divi/icon` | a small label surface: the kit's control radius, a kit-width frame (group), fill and glyph colours (icon) | light, surface, accent, dark |

`on` = the band the carrier is printed on; `panel` is the inside of an inverted (`text`-filled) card or panel, where the ink
is `bg` (the 8 `bg`-coloured dividers of the library sit there); `dark` is a dark photo band (ink `inverse`). A group or
column rule is the TOP edge of the block (all 29 library rules are top rules; the edge is not a marker field: Ruling 3).
`KitResolver` learns the three roles through `KitAdapters::APPLIED` (`ROLE_BLOCKS`, `ROLE_SURFACES`), the strict marker
parser is unchanged. Whole-value tokens and the colour alpha form already exist.

### 2. Kit fields to attributes (no kit file change)

Rule:
- Style: the kit's border style (solid, dashed, dotted, double) as given.
- Width (Ruling 1): `rule` = the kit's border width with a 1px floor (a 0px kit still draws a 1px hairline) and a 2px cap;
  `rule-strong` = the kit's width + 1px with a 2px floor and a 4px cap. A `double` style is never narrower than 3px (CSS draws
  a double below 3px as solid; the library's one double kit is 4px; Ruling 2). Exact table in the plan, decided by the probe.
- Colour by band. `rule`: light and surface `border` (the decorative hairline, the card frame's `same` role); accent
  `{{aied:color:accent-text:40}}`; dark `{{aied:color:inverse:40}}`; panel `{{aied:color:bg:40}}`. `rule-strong`: light and
  surface `text`; accent `accent-text`; dark `inverse`; panel `bg`. The proof can change this table, never a floor.
- Carriers: on `divi/divider` the patch is `divider.advanced.line.desktop.value.{color,weight,style}` only (never `position`
  or `show`, never `module.decoration`); on `divi/group` and `divi/column` it is `module.decoration.border.desktop.value.styles`
  with `all` at 0px and `top` at the width, style and colour, and nothing else (padding, layout and sizing stay the recipe's:
  a rule's gap is a layout decision).
- A kit whose probe shows a style dead on a carrier falls back to `solid` on that carrier and the guide says so, the same
  mechanism as `FORM_PROVEN` (a proven-decisions constant pinned to the vocabulary table).

Chip:
- `divi/group` tag: radius `{{aied:kit:radius-control}}`, a frame of the kit width with a 1px floor in the card's frame role
  (`KitAdapters::cardFrameRole`: `border` for `same`, as the 5 library tags already use), the kit's style; the fill stays
  transparent (a tag is an outline pill) and the recipe's padding stays (a tag's padding is its size, not a kit decision).
- `divi/icon` chip: radius `{{aied:kit:radius-control}}` (Ruling 4: a chip is control-sized, so it follows the control
  radius and a 0px kit makes square chips and a 999px kit round ones; the recipes' literal 5px and 999px stop being a choice);
  fill `accent`, glyph `accent-text` stay recipe tokens only if the probe shows the kit need not write them, otherwise they are
  written (an icon chip's contrast is the glyph on `accent`, already proven for every language in the button proof).

### 3. Contrast and visibility proof (pure PHP, zero exceptions, no baseline file)

- Rule: 40 kits x 10 languages x 5 bands x 2 roles = 4,000 cells. `rule-strong` colour against the band: 3:1 (it carries
  structure, WCAG 1.4.11 reading). `rule` is decorative like the card's `same` hairline, but must not be invisible: its floor
  is measured at plan time from the library's shipped rules (the lowest ratio a rule has today in any language, rounded down to
  one decimal), written once as a constant with the table beside it and never lowered afterwards (Ruling 5; if the number
  cannot be met by a role change the cell fails, it is not excused).
- Chip: 40 kits x 10 languages x 4 bands = 1,600 cells: the group tag's frame against the band (decorative hairline, same
  floor as `rule`) and the icon chip's glyph on its `accent` fill at 3:1 (non-text; the library's glyphs are 20-22px graphics).
- Visible difference: a shrink-only near-duplicate list per role pair, as for cards (`rule-near-duplicates.txt`,
  `chip-near-duplicates.txt`), caps asserted.
- Golden: `tests/golden/kit-rule-patches.json` (10 default kits x 5 bands x 2 roles x 3 carriers) and
  `tests/golden/kit-chip-patches.json`; the button, card, item and form goldens stay byte-identical.

### 4. Probe list (`fixtures/shapes/candidates/kit-rule.json`, ids `kr-<decision>`; `kit-chip.json`, ids `kc-<decision>`)

All ON the carrier, against the known-good control, each rendered at 1280/900/400 and measured (`scripts/verify-shapes.cjs`):
divider line `style` solid/dashed/dotted/double, `weight` 1-4px, `color` as an alpha token, `double` at 1px and at 3px+;
group top edge `styles.top.{width,style,color}` for each style, the same on a column, a top edge on a group inside a card, and
the `module.decoration.border` of a group when `sides` other than top are 0px; icon `module.decoration.border.desktop.value.radius`
as a whole-value token (0px, 4px, 12px, 999px) and whether it rounds the module box, the glyph box or neither; a group tag's
radius token and frame. Results become `fixtures/shapes/kit-rule-vocabulary.md` and `kit-chip-vocabulary.md` with an "Adapter
decisions" table; DEAD paths go to `fixtures/shapes/dead-on-module.txt` for the carrier; css-only paths are listed and not used.

### 5. Render gate and recipes

`verify-kits` renders per cell: a divider and a top-rule group of each role on the light band (and one of each on the dark
band), a tag chip and an icon chip, and measures computed border-top width, style and colour (or the divider's computed
line), radius, fill, overflow and contrast with codes `rule`, `rule-style|width|colour|contrast`, `chip`, `chip-radius|
frame|fill|contrast`. The expectation is read from the RESOLVED markup (a 0px chip kit is a real expectation of zeros).
Recipes: 59 rule blocks (23 recipes) and 14 chip blocks (3 recipes) migrate; the merge trap (a marked block keeps none of the
literal decoration the kit writes) and an own-ink / band walk give `RuleMigrationTest` and `ChipMigrationTest`;
`BESPOKE_RULES` and `BESPOKE_CHIPS` empty; steps-horizontal badges and timeline dots are named exemptions with the reason.

### 6. Size and the guide (plan this first; the previous branch left thin headroom)

Current: default guide 28,975 B (ceiling 29,108), `kit-rules` 4,607 B (bound 10,000), largest kit topic neo-brutal 9,599 B
(bound 10,000, about 400 B headroom), the `topic` parameter description 258 characters (pinned <= 260). Rulings for space:
1. **No new topic name.** The rule and chip rules go in the generated `kit-rules` topic as one "Rules and chips" paragraph
   generated from `KitAdapters::APPLIED` and the proven-decisions constant (budget +650 B), and ONE generated sentence in the
   existing topic that teaches `divi/divider` (budget +250 B; the builder finds which topic it is).
2. **The default guide grows 0 B** (pinned 28,975).
3. **Kit topics get no rule or chip example**; `appliedSentence()` gains the nouns "rules" and "chips" (at most +40 B per kit
   topic, the largest stays at or below 9,700 B).
4. `section-recipes.json` must not grow (670,981 B at the base; markers replace more decoration than they add; bound 716,800 B).

### 7. Rulings (made by delegation; the cost if wrong is cheap because each is one entry of a role table)

1. *Rule weight.* Quiet = kit width, 1px floor, 2px cap; strong = kit width + 1px, 2px floor, 4px cap. Why: the library's
   rules are 1px (quiet) and 2px (strong); a 4px kit should read heavier, but a 4px hairline between FAQ rows is a bar, so the
   quiet rule stops at 2px. Cost if wrong: change two numbers in one table; the goldens move by a documented regeneration.
2. *Double style.* A `double` kit rule is at least 3px wide, because a narrower double is drawn solid and the kit's identity
   would be lost silently. Cost if wrong: one clause.
3. *The edge of a group or column rule is always the top.* The 29 library rules are all top rules; a bottom or left rule is
   not asked for by any recipe. Cost if wrong: add an `edge` marker field later (additive).
4. *Chip radius follows the control radius*, not the card radius: a chip is a small control-sized label. Cost if wrong: swap
   the token name.
5. *Quiet-rule floor is measured, then frozen.* The floor for the decorative hairline comes from the shipped library, not from
   a guessed number, and is never lowered afterwards. Cost if wrong: a failing cell changes the role table.
6. *Step badges and timeline dots stay literal* (shape decisions, like arches). Cost if wrong: a later role can adopt them.
7. *Blurb, testimonial and counters are out*: 13 blocks, 4 recipes, font and colour only.
8. *The two accent dividers stay literal* (blog-feed and icon-values: a short accent bar on the site's own preset `1ep0co506u`): a brand mark, not a separator, and a `rule-strong` would turn it into a text-coloured line. So 57 rule blocks in 22 recipes migrate, not 59 in 23. Cost if wrong: add a `rule-accent` role later.
9. *No pair list for rules*: a rule has only style and width, so the 40 kits give about 6 distinct looks; the proof pins that set of distinct looks instead of a 280-line near-duplicate list (the chips keep a pair list). Cost if wrong: none, it is a test shape.

### 8. Done means

All suites green (free, Pro untouched, `node scripts/test-verify-kits.cjs`); `make verify-shapes` ok; the full
`make verify-kits` passes every cell with the new codes; the narrowed recipe render (every marker recipe of the branch, the
earlier marker recipes, about 10 seeded guard recipes, in chunks) passes; the Outcome records real numbers; validator,
`src/`, `wp-plugin/validator/` and the root zip unchanged.

## Phased plan, order and cost

Task 1 probe (live, serial; carriers divider, group, column, icon) -> Task 2 adapter + resolver + generated guide (reclaim
guide space first if a test needs it) -> Tasks 3 (proofs and goldens), 4 (recipe migration), 5 (render gate), which can run in
parallel worktrees (max 3 workers) -> Task 6 docs -> Task 7 live gates (serial, controller: smoke, full `verify-kits` about
3 hours, narrowed recipe render about 20 minutes, `verify-shapes`). Estimated 5 to 6 hours elapsed.

## Left to the builder

Names of constants and test helpers; the exact probe candidate list beyond section 4 where the first runs show a path is
dead; the exact role-table colours if the proof moves them; the wording of the generated sentences within the byte budgets;
which topic teaches `divi/divider`; which of `frameStyles()`, `restShadow()`, `cardFrameRole()` are reused as they are.

## Outcome (2026-10-21)

Built on branch `feat/rules-chips-adapter` (spec 155ff75 and plan 1a37fcc from main c0eed44) in seven tasks; Tasks 4, 5 and 6
ran in parallel worktrees and were merged back, and Task 3 had one review fix round. NO release, version bump or zip rebuild:
the tracked root zip is the published 4.13.0 build; the validator (`src/`, `wp-plugin/validator/`) is untouched; no new
guide topic name, MCP tool, REST route or OpenAPI operation. The spec was approved by delegation, so the Rulings below are the
calls the owner might have made differently. Numbers are measured, not planned; where this differs from the sections above,
this Outcome wins (the corrected counts are in "Deviations").

**What was built, per task.**
- **T1 Probe** (871d8b2). 56 candidates rendered ON their carriers on the real Divi 5.15.1 in a real browser at 1280/900/400:
  `kit-rule.json` (ids `kr-*`) **36 candidates, 33 PASS, 3 DEAD, 0 css-only**, `kit-chip.json` (ids `kch-*`) **20 candidates,
  18 PASS, 2 DEAD, 0 css-only**; no phone overflow, no control noise. **No path is dead on any carrier**: the 5 DEAD are
  default-value controls (position `top` on a divider, `styles.all.width` `0px` alone on a group, on a column and on a tag,
  and `icon.advanced.align` `center`), so `fixtures/shapes/dead-on-module.txt` gained no line. One control was wrong and was
  corrected: the first icon control, align `left`, is not Divi's default and PASSED; it became `center`. A divider's line is
  drawn on `.et_pb_divider::before`; an icon's radius rounds the MODULE box, `div.et_pb_icon.et_pb_module` (the only changed
  element of every `kch-icon-radius-*`; no inner box); the fill goes on the same box and the glyph colour on
  `span.et-pb-icon`. Vocabularies: `fixtures/shapes/kit-rule-vocabulary.md` (16 decisions, all PASS) and
  `kit-chip-vocabulary.md` (8 decisions, all PASS); evidence in `docs/shape-evidence-5.15.1.md#kr-*` and `#kch-*`.
- **T2 Audit and the quiet-rule floor** (an extra task, see Deviations; 34e5d72). `tests/Support/RuleChipAudit.php`,
  `scripts/rule-chip-audit.php` (pure), `fixtures/shapes/rule-floor.md`, `RuleChipAuditTest`. It finds every rule and chip of
  the library by its decoration (unmigrated) or by its marker (migrated), so the lists are the same before and after the
  migration, and it measured the floor of Ruling 5: the lowest ratio any shipped quiet rule has, over the 10 languages, is
  **1.184** (`border` on light in soft-saas, hero-editorial-minimal's divider); the floor is that value rounded down to one
  decimal, **`QUIET_RULE_FLOOR = 1.1`**, pinned literally. The other measured cells: `border` on surface 1.275, `accent-text:40`
  on accent 1.928, `inverse` on dark 9.543, `bg` on a panel 11.978, `text` on light 11.978 and on surface 13.274.
- **T3 Adapter, resolver, generated guide** (a56e0be, review fix round included). `KitAdapters::APPLIED` gained `rule`,
  `rule-strong` on `divi/divider`, `divi/group`, `divi/column` and `chip` on `divi/group` (a tag) and `divi/icon` (an icon
  chip); `RULE_PROVEN` (16 keys) and `CHIP_PROVEN` (8 keys) are pinned to the "Adapter decisions" tables of the two vocabulary
  files, **all true**; `RULE_COLOUR`, public `RULE_BANDS` / `CHIP_BANDS`, `rulePatch()`, `chipPatch()`, `ruleWidth()`; one early
  branch in `patch()` (the button, card, item and form paths are untouched); `KitResolver` roles and surfaces. A divider
  writes `divider.advanced.line.desktop.value.{color,weight,style}` and nothing else (never `position`, `show` or a
  `module.decoration`); a group or column rule writes `module.decoration.border.desktop.value.styles.{all,top}` only, with
  `all.width` 0px. A tag writes the control radius token plus a full frame (kit width with a 1px floor, 3px for a double, kit
  style, never the underline form, no fill, no padding); an icon chip writes the control radius token, fill `accent` and glyph
  `accent-text` (on an accent band: fill `surface`, glyph `text`, the button's accent treatment). Guide, generated from the map
  and the proven maps, no new topic: a "Rules and chips" paragraph in `kit-rules`, one generated sentence in `controls`
  (`controlsKitSentence()`), `appliedSentence()` now groups roles by noun. A wording the proof does not back is never printed
  (the review fix round: "40% ink elsewhere", "3px+ for a double" and the icon clause are conditional on the decisions).
- **T4 Proofs** (a7bef4f). Rule contrast: 40 kits x 10 languages x 5 bands x 2 roles = **4,000 cells**, each judging the
  divider, group and column patch; chip contrast: 40 x 10 x 4 bands = **1,600 cells**, each judging the tag and the icon chip;
  **zero exceptions, no baseline file, and no role-table change was needed** (no cell failed). Measured minima against the
  band as painted: quiet `border/light` 1.184 and `border/surface` 1.275 (soft-saas), `accent-text:40/accent` 1.928
  (playful-pastel), `inverse:40/dark` 2.962 (playful-pastel), `bg:40/panel` 2.529 (luxe); strong `text/light` 11.978,
  `text/surface` 13.274, `accent-text/accent` 5.747 (playful-pastel), `inverse/dark` 9.543, `bg/panel` 11.978. Mutating the role
  table made the proofs report 180 rule and 1,200 chip problems, so they bite. Goldens: `tests/golden/kit-rule-patches.json`
  **300 patches** (10 default kits x 3 carriers x 2 roles x 5 bands) and `tests/golden/kit-chip-patches.json` **80 patches**
  (10 x 2 carriers x 4 bands); the four earlier goldens are frozen by sha256 (`KitGoldenFreezeTest`: adapter ff4ca3d5..., card
  1ffad3f0..., item 73605923..., form 47be308c...). Rule visible-difference (Ruling 9): the 40 kits give **6 distinct looks**
  (style, quiet width, strong width), pinned with their member kits: dashed 1px/2px (1 kit), dotted 2px/3px (1), double 3px/4px
  (1), solid 1px/2px (22), solid 2px/3px (8), solid 2px/4px (7). Chip near-duplicates: **46 of 780** kit pairs give the same
  chip (`fixtures/shapes/chip-near-duplicates.txt`, shrink-only, **cap 46**, the test also asserts the plan's stop line of 60).
  The dead-on-module guard now covers the four carriers (1,520 resolved blocks, planted dead lines caught and scoped to their
  carrier).
- **T5 Recipe migration** (a6ffb61). **57 rule blocks in 21 recipes** (28 `divi/divider`, 26 `divi/group`, 3 `divi/column`;
  22 `rule`, 35 `rule-strong`) and **14 chips** (5 tags on `divi/group`, 9 icon chips on `divi/icon`) in 3 recipes take kit
  markers `"aied":{"kit":"rule|rule-strong|chip","on":<band>}`, in **24 distinct recipes** (no recipe is both: icon-values' only
  rule is the exempt accent divider). Each lost exactly what the kit now writes. `RuleMigrationTest` (10 tests) and
  `ChipMigrationTest` (7): own-ink walk, band walk (each marker's `on` equals the walk, `other:text` = `panel`), merge trap over
  all 40 kits x 10 languages, the 57 and 14 markers pinned by recipe and index, `BESPOKE_RULES` and `BESPOKE_CHIPS` EMPTY (cap
  0), the named shape exemptions pinned. Nine recipe descriptions that promised a rule or chip look were reworded. Re-pinned on
  purpose: `CardMigrationReportTest`, `RecipeLibraryTest` (marker recipes 33 -> 45, the featured plan check), the composition
  topic test; `make card-report` also lists rule and chip markers. `section-recipes.json` **670,981 -> 662,636 B** (-8,345).
- **T6 Render gate** (21d82aa). `scripts/lib/kit-checks.cjs`, new `scripts/lib/kit-rule-run.cjs`, `scripts/verify-kits.cjs`:
  every kit/language cell renders **10 rule slots** (on the light band a `rule` and a `rule-strong` divider and top-rule group,
  a `rule` column rule, and a `rule-strong` divider inside an inverted panel; the same four divider and group slots on the
  photo band) and **2 chip slots** (a tag and an icon chip). Codes `rule`, `rule-style`, `rule-width`, `rule-colour`,
  `rule-contrast`, `chip`, `chip-radius`, `chip-frame`, `chip-fill`, `chip-contrast` and `overflow`, plus the fail-closed
  `rule-expectation` and `chip-expectation`; the expectation is read from the RESOLVED markup (a 0px chip kit is four zeros);
  a run that checked nothing fails; observations go to `rule-observations.json` and `chip-observations.json`. Pure tests
  `node scripts/test-verify-kits.cjs` **91 -> 100**; the Chromium suite on synthetic pages has one deliberate departure per code
  and passed. Mutation check: 5 of 5 pure and 4 of 4 Chromium mutations were killed (T6 wrote the implementation before the
  tests, so this stands in for a red run).
- **T7 Docs** (this task): this Outcome, the CLAUDE.md paragraph and `make verify-kits` row, `docs/token-usage.md`, the
  vocabulary and docblock final pass (comments only).

**The audit (script over `section-recipes.json` at 155ff75).** 59 rule-like blocks by the first classifier: 29 group/column
top rules, 30 dividers with a line colour. Two are the accent dividers of Ruling 8 and stay literal, so **57 migrate**. 14 chips:
5 outline tags (portfolio-featured-grid) and 9 icon chips (icon-values 3 at 5px, features-carousel-strip 6 at 999px). Named
**shape exemptions** that stay literal (15 keys in `RuleChipAudit::SHAPE_EXEMPTIONS`, each pinned to exist and to remain
literal): steps-horizontal's four step badges (48px circles), timeline-vertical's four dots (14px squares), pricing-three-tier's
filled [Most popular] badge, stats-counter-band's four counters (a top rule on a counter: counters are out) and the two accent
dividers (blog-feed 0, icon-values 0). Rule role was decided by colour: `border` or any alpha token = `rule`, else `rule-strong`.

**Measured sizes and counts.** (`wc -c` and `php scripts/measure-tokens.php` at the end of Task 7.)
- `section-recipes.json` **670,981 -> 662,636 B** (-8,345; bound 716,800 and the 700 KB rule hold); `style-kits.json` 25,479 B
  (unchanged); new goldens 274,560 B (rule) and 85,614 B (chip).
- Guide, before -> after (`docs/token-usage.md`): default guide **28,975 B, unchanged** (133 B under its 29,108 bound); topic
  `kit-rules` **4,607 -> 5,255 B** (+648, of which the generated "Rules and chips" body is about 507 B; it has a self-imposed pin
  at 5,257 B, so any wording change must be re-measured, but the real topic bound is 10,000 B); topic `controls` **8,123 ->
  8,331 B** (+208 for one generated sentence, under its 250 B budget and the 9,500 B bound); topic `style-kits` (the catalog)
  **8,439 -> 8,444 B** with 40 kits (Step 0 reclaimed 114 B of fixed prose first, 8,325 B, then the applied-roles tail added
  119 B) and a **9,928 B projection with 50 kits** (bound 9,950 B); every `style-kit:<id>` topic **byte-equal** to before
  (largest neo-brutal 9,599 B, 401 B under 10,000); the `topic` parameter description 258 characters, unchanged (pinned
  `<= 260`); the filtered recipe catalog (role hero) 3,920 -> 3,915 B.
- Suites: free **2758 (OK, 1 skipped by design: the floor-table recompute test skips once the rules are migrated)** (2678 at the 4.13.0 release, 2666 at image radius), Pro **2169** (untouched);
  `node scripts/test-verify-kits.cjs` **100** tests; `make test` is the gate, not run by Task 7 (it goes through Docker).
- Probe: 56 candidates (36 + 20), 0 dead paths, 5 default-value controls DEAD as designed, 1 wrong control corrected. Contrast
  cells: 4,000 + 1,600 with zero exceptions. Patches: 300 + 80. Near-duplicates: chips 46 (cap 46); rules 6 distinct looks (no
  pair list).

**Rulings made** (in plain words, each with its cost if wrong).
1. *Rule weight.* A quiet rule is the kit's border width with a 1px floor and a 2px cap; a strong rule is the kit's width plus
   1px with a 2px floor and a 4px cap. The library's rules are 1px (quiet) and 2px (strong); a 4px kit should read heavier, but a
   4px hairline between FAQ rows is a bar. Cost if wrong: change two numbers in one table (`ruleWidth()`); the goldens move by a
   documented regeneration.
2. *A double is never narrower than 3px.* CSS paints a double thinner than 3px as solid, and the painted look at 1px was never
   measured, so the double floor wins over the quiet cap (4px double kit: 3px quiet, 4px strong). Cost if wrong: one clause.
3. *The edge of a group or column rule is always the top.* All the library's rules are top rules; an underline kit's `sides`
   does not change a rule. Cost if wrong: add an `edge` marker field later (additive).
4. *A chip follows the control radius*, not the card radius: a chip is a small control-sized label, so a 0px kit makes square
   chips and a 999px kit round ones; the recipes' literal 5px and 999px stop being a choice. Cost if wrong: swap the token name.
5. *The quiet-rule floor is measured, then frozen.* 1.1 comes from the shipped library's minimum (1.184), not from a guess, and
   is never lowered. Cost if wrong: a failing cell would change the role table (none did).
6. *Step badges and timeline dots stay literal*: circles and squares are shape decisions, like the arches of the image-radius
   pass. The same reasoning keeps the filled [Most popular] badge literal. Cost if wrong: a later role adopts them.
7. *Blurb, testimonial and counters are out*: 13 blocks in 4 recipes, mostly font and colour. Cost if wrong: another adapter.
8. *The two accent dividers stay literal* (blog-feed 0, icon-values 0): a short accent bar on the site's own preset
   `1ep0co506u` is a brand mark, not a separator, and a `rule-strong` would turn it into a text-coloured line. Cost if wrong:
   add a `rule-accent` role later.
9. *No pair list for rules.* A rule has only a style and a width, so the 40 kits give about 6 looks; the proof pins that set of
   distinct looks and shows a planted twin collapsing into the same look, instead of a 280-line near-duplicate list. Cost if
   wrong: none, it is a test shape. The chips keep a pair list.

**Decisions of the plan's header** (where the spec said "left to the builder"): the topic that teaches `divi/divider` is
`controls` (one generated sentence); constants `RULE_PROVEN`, `CHIP_PROVEN`, `RULE_COLOUR`; the floor lives in
`RuleChipAudit::QUIET_RULE_FLOOR`; chip candidate ids use `kch-`; `cardFrameRole()`, `colour()` and `frameStyles()` are reused,
`restShadow()` and `shadow()` are not (rules and chips take no shadow); a rule's role is decided by its colour; the icon chip's
fill and glyph are always written by the kit (deterministic own-ink rule); the tag's frame colour follows the band (quiet
`border` hairline on light and surface, 40% ink on accent and dark when the alpha decision is proven, else the strong colour).

**Deviations from the spec.**
- **An extra audit task** (T2). The spec had no audit task; the plan added one so the floor (Ruling 5) is measured before any
  role table is frozen and the lists of rules and chips are one source for the proofs and the migration.
- **`kch-` prefix** for chip candidates, not `kc-`: the card probe already owns `kc-*` ids.
- **Noun-grouped `appliedSentence()`**: the guide's applied-roles sentence is regrouped by noun (rules, chips alongside buttons,
  cards, panels, items, forms); a role without a noun is named by id after the nouns.
- **Accent dividers exempt** (Ruling 8) and **no rule pair list** (Ruling 9), both added by the controller after the plan was
  written.
- **57 rules in 21 recipes, not 59 in 23.** The spec counted the two accent dividers (59), and the plan's first arithmetic after
  Ruling 8 said 22 recipes; the audit's real classifier finds 21 (blog-feed and icon-values, whose only rule is the accent
  divider, drop out). The union with the 3 chip recipes is 24 recipes. The spec's audit table counted 14 divider recipes and 9
  group/column recipes (23 in all); removing the two accent-divider-only recipes leaves 21.
- The chip near-duplicate count (46) is above the brief's estimate (about 30) and below the stop line (60): the pairs are
  driven by the 4px control-radius tolerance and the tag frame, since the icon chip's patch is the same for every kit except
  the radius.
- The brief's planted dead line `divi/divider|divider.advanced.line.desktop.value.style` can never match: the shape registry
  normalises the breakpoint key to `{bp}`. The test plants the `{bp}` form and asserts that the literal-`desktop` form matches
  nothing, as a trap for the next author.
- `RULE_BANDS` and `CHIP_BANDS` are public; `chipPatch()` takes an optional trailing proven map for `group-top-alpha` so a test
  can plant it false.

**Known limits.**
- The live render gate does not render every branch of the adapter: rules are rendered on the light band, the photo band and (a divider only) the panel; chips on the light band only. A `rule` / `rule-strong` on `surface` or `accent` (except where the narrowed recipe render covers it, e.g. pricing-three-tier's accent ground), a quiet rule or any group/column rule on `panel`, the icon chip on an accent band (fill `surface`, glyph `text`: no recipe uses it) and a tag on any band but light are proven by the pure PHP proofs only (spec section 5 asked for no more; more slots would lengthen the 3-hour run).
- The default look moved (final review, editorial / quiet-solid): "role by colour" makes 18 one-pixel `text` / `inverse` rules 2px `rule-strong` (faq-two-column 6, values-list 4, before-after-compare 2, pricing-two-plan-highlight 2, hero-image-scrim 1, quote-over-image 1, card-grid-4 2), 3 steps-horizontal dividers go from 2px `border` to a 1px quiet rule, and all 9 icon chips take the control radius (icon-values 5px to 4px; features-carousel-strip's round 999px badges become 4px squares, Ruling 4). Intended by the rulings, listed so the owner sees it.
- `controlsKitSentence()` prints its two halves from `APPLIED` alone, not from the proven-decision maps (true today; latent if a decision turned false). The "Rules and chips" paragraph does not say that the line style follows the kit's border style nor that a group or column rule zeroes the other three sides; kit-rules has 2 B of headroom under its self-imposed 5,257 B pin (real bound 10,000), so any rewording re-pins on purpose.
- **The painted double is not measured.** The probe records the computed `border-top-style` and width of a double; the render
  gate checks computed values, not pixels, so a double line is proven to be requested, never seen. The 3px floor of Ruling 2
  is the reason it should paint as a double.
- Rules have **no hover**, no padding and no shadow; chips have no hover, no padding and no shadow (a tag's padding is its
  size, the recipe's).
- **No `edge` field**: a group or column rule is always the top edge (Ruling 3); a bottom, left or right rule, a vertical rule
  between columns and a rule on a row or section are not adapted.
- Blurb, testimonial and counters are not adapted (Ruling 7); the step badges, timeline dots, [Most popular] badge, counters'
  top rules and the two accent dividers stay literal on purpose (the shape exemptions).
- **The guide's composition topic ("One-side rule") still teaches the literal top-rule shape** and names proof-press-quotes,
  whose rules are now kit markers. The shape it teaches is what the served recipe still contains (the test reads the served
  markup), so it is stale wording, not a false claim; a later guide pass can mention markers.
- `kit-rules` is 5,255 B with a self-imposed pin of 5,257 B (2 B of headroom); the real bound is 10,000 B.
- The contrast proof judges colour tokens only (alpha composited over the painted band), not how Divi renders a dashed or dotted
  line at 1px; a tag's frame and an icon chip are judged on a light-weight model of the photo band (`#808080` under the
  overlay), like the button and form proofs.
- Quiet rules on a photo band are judged against the mean of a thin strip; a marginal cell could flap at the 1.1 floor.
- Minors left from the reviews: the exemptions for filled shapes are documentation only; `themeBuilderArea` is unused in
  `RULE_KEEPS`; the chip's quiet/strong choice is decided by the colour token; `THIN_CARRIERS` band lists are not asserted
  against `RULE_BANDS`; the live selectors of the render gate (`.et_pb_group`, `.et_pb_divider::before`, `.et_pb_icon`) were
  taken from the probe and are confirmed or refuted only by the first live smoke.

**Guide headroom left.** Default guide 133 B (bound 29,108), largest kit topic 401 B (neo-brutal, bound 10,000), `kit-rules`
5,255 B (pin 5,257 B, topic bound 10,000), the catalog 9,928 B at 50 kits (bound 9,950), the `topic` parameter description 258
characters (pinned `<= 260`), `section-recipes.json` 54,164 B under the 716,800 B bound.

**Owner decisions left.** Push the branch and merge; release (nothing is versioned or zipped); the blurb and testimonial
adapters (13 blocks in 4 recipes, plus the counters, are the next module kinds a kit could own); a `rule-accent` role if the
accent bars should follow the kit; an `edge` field if a recipe ever needs a bottom or left rule; image-to-layout later.

**Live gates.** TBD-LIVE-GATES
