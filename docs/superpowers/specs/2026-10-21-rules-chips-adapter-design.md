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
4. `section-recipes.json` must not grow (now 670,983 B; markers replace more decoration than they add; bound 716,800 B).

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
