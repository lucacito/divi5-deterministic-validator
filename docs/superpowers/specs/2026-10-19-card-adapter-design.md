# Card adapter: a style kit also styles the card surfaces

Status: approved 2026-10-19 and BUILT on branch `feat/card-adapter` (nothing released; see the Outcome section at the end for what was
built, how it differs from this text and what is left). Follows `2026-10-18-style-kits-design.md` (button pilot merged to
local main, 71540da).

## Intent and why

Today a kit changes buttons only. The kits already record `radius.card`, `space.card`, shadow, border and hover, but
cards keep the recipe's hard-coded look, so switching kit does not change the surfaces a page is mostly made of. The
card adapter makes the card look follow the kit, with the same proof level as buttons (contrast over every kit x
language x surface, probe on the real Divi, registry lint, golden file).

## What the audit found (this changes the scope)

The brief said 358 recipe blocks are `divi/group` cards. The audit (a throwaway
script; the plan turned it into the committed `scripts/card-migration-report.php`, `make card-report`) says **356 groups, of
which 19 are cards or panels**:

| Class of `divi/group` | Count | Kit-driven? |
|---|---|---|
| Plain wrapper (no decoration) | 212 | No. Layout only. |
| Wrapper with padding only | 41 | No. |
| Top-rule row (0px frame + `top` border 1-2px: team, FAQ, timeline, price rows) | 26 | No (v1). A divider, not a card. |
| Flat fill cell in a hairline grid (logo wall, stat cells, comparison table: outer box `border` colour, cells `bg`) | 40 | No. The grid trick is structure. |
| Chip / dot / step circle / outlined category tag (999px, a 12px accent chip, a 1px-framed tag) | 14 | No. Pills stay baked. |
| Inverted panel (filled with the `text` colour: compare-two-panels#7, pricing-two-plan-highlight#8, a bento tile) or accent tile (a bento tile) | 4 | No (v1), see "Inverted surfaces". |
| **Card or panel** (`surface`/`bg` fill + frame/radius/shadow) | **19 in 12 recipes** (9 cards, 10 panels) | **Yes** |

And the library's other cards are **not groups**: 12 are `divi/column` blocks with border/radius/shadow, of which 9 are cards
(icon-values 3, testimonials-avatar-grid 3, pricing-three-tier 3) and 3 are rules, not cards (proof-press-quotes: no fill, a
2px top rule), 4 are `divi/blurb` with a fill
only (blurb-grid), 1 is `divi/testimonial`. `card-grid-3/4`, `icon-features`, `features-zigzag` and most of the 13
feature recipes have **no card frame at all**. So a group-only adapter restyles 12 of 71 recipes; group plus
column restyles 15 (the three column recipes are new). Making cards appear in recipes that have none is a design change, not a kit application, and
is out of scope.

**Decision 1 (yours):** carriers. Recommended: **`divi/group` and `divi/column`** (one patch on `module.decoration.*`,
each carrier probed separately; the 12 column cards are already render-verified in shipped recipes). Blurb and
testimonial later (they have their own inner decorations). Alternative: group only, as briefed.

## Goals, non-goals, anti-goals

Goals: a kit changes the corners, frame, shadow, padding and hover of the card surfaces the same way it changes its
buttons; the recipes file ends smaller; text on every card stays readable in every kit x language.
Non-goals: cards where recipes have none; accordion, forms, blurb, testimonial (later adapters); inverted surfaces;
gradients; the validator (untouched); any new tool, route or operation.
Anti-goals: a kit that makes card text unreadable; a card whose children (heading, text, icon) keep colours chosen for a
different fill; hover motion on a card that is not clickable.

## Design

### 1. Marker, roles, surfaces (extends KitResolver, buttons unchanged)

`"aied":{"kit":"card|panel","on":"light|surface|accent|dark"}` on a `divi/group` (and `divi/column` if Decision 1 is
yes). `on` is the band the card sits on: `light` a `bg` band, `surface` a `surface` band (new, card roles only; the
button roles keep light|accent|dark), `accent`, `dark` (photo/overlay).

- `card`: a grid tile. Kit padding (`space.card`), kit hover.
- `panel`: a static surface (forms, spec sheet, overlap panels, big CTA cards). The recipe keeps its own padding (they
  are structural, e.g. 56/48/56/152 on the overlap hero) and **no hover is written** (not clickable).

Classification of the 19 is generated (`make card-report`, plan task 2) and was reviewed; my reading: cards = hero-gradient-stats (3),
features-bento surface tiles (3), hero-bordered-cells inner cells (3); panels = hero-split-left-overlap,
hero-spec-card, hero-bordered-cells outer, compare-two-panels (group #2), pricing-two-plan-highlight (group #1), cta-split-form,
cta-card-over-image, contact-location-form, contact-details-form, transition-overlap-panel.

### 2. How kit fields map to a card (no shipped kit changes meaning)

No kit file changes. The adapter derives the card from fields that exist:

| Card property | Source | Rule |
|---|---|---|
| Fill | always filled | `surface`; `bg` when the band is `surface`. Never transparent: a card's children carry colours chosen for a surface fill, so `fill: outline/ghost` kits keep a filled card and show their character in the frame. |
| Radius | `radius.card` | `{{aied:kit:radius-card}}` (exists). |
| Padding (`card` only) | `space.card` | `{{aied:kit:space-card}}`, four sides (exists), on `module.decoration.spacing`. |
| Frame width / style | `border.width`, `border.style` | Kit width, **floor 1px** so a quiet kit's card is still separable from its band (recipes already use a 1px `border` hairline for that); style solid/dashed/dotted/double as the kit says. |
| Frame colour | `border.color` | `same` -> `border` role; `border` and `text` as given; `accent` -> `text` and `inverse` -> `text` (amended in Task 4: an accent frame is 2.30-2.81:1 on the card fill in playful-pastel and brutalist-flat, below the 3:1 a meaningful frame needs, so the role table is language-independent and uses `text`). |
| Underline kits (`border.sides: bottom`) | `border.sides` | Card gets only a bottom edge (`styles.all.width 0px` + `styles.bottom`). |
| Shadow rest / hover | `shadow` | Same value forms as the button (`soft`, `hard`, `edge`, `glow`; ink by band) on `module.decoration.boxShadow`; hover shadow only on `card`. |
| Hover (`card` only) | `hover` | `lift` -> `translate.y -4px` on hover. `fill-swap` / `invert` -> the frame colour goes to `text` on hover, written only when the rest frame is not already `text` (amended in Task 4; a card cannot swap its fill: children would lose contrast). Consequence: a kit with a `text` frame has no card hover change. `none` -> nothing. |
| Text decisions, `fill`, `color` | button only | Not used by cards. |

Frame colour is the one new judgement call; the contrast test pins it (below). The golden file
`tests/golden/kit-adapter-patches.json` keeps pinning the button output of every old kit byte for byte; card patches get
their own golden `tests/golden/kit-card-patches.json`. `KitAdapters::patch` branches on block and role; button code is
not touched.

### 3. Surfaces and contrast (extends the proof to text)

Because the fill is always `surface` or `bg`, card text readability reduces to pairs the recipes already depend on
(`text`/`muted` on `surface` and on `bg`, AA 4.5 in all 10 languages). The new proof does three things, all pure PHP:

1. For every kit x language x role x `on`: the resolved fill is `surface` or `bg`, and (text, fill), (muted, fill),
   (accent-text, accent) pairs used inside cards pass AA.
2. A **descendant ink walk** on the resolved markup of every marked card: each colour token on a descendant (heading,
   text, icon, button label) must be a role proven AA on the card's resolved fill. This is what catches a recipe whose
   children assume the wrong ground.
3. The frame colour, when it carries meaning (as built: `text`; an `accent` frame is drawn as `text`, see the Outcome), is >= 3:1 against the card fill (the button outline's
   bound); the `border` hairline is decorative and exempt, as today. Hover frame colour (`text`, see section 2) the same.

Rule changes are made by fixing the role table, never by a frozen exceptions file (lesson from the button table: the
first table had 50 failing cells).

### 4. Size rule

`section-recipes.json` ends **smaller** than 695,877 B (bound 716,800 unchanged). Estimated before building: the
literal `border` + `boxShadow` + `spacing` objects on the card-like groups total about 10 KB; a marker is about 35 B. Measured
after building: 695,877 -> 683,191 B (-12,686: -7,691 for the groups, -4,995 for the 8 column cards), see the Outcome. The
adapter also writes the fill (it depends on `on`: `surface`, or `bg` on a surface band), so a migrated card drops its own
background literal too (about 40 B each, not counted above); the marker owns fill, border, radius, padding, shadow and
hover. Each phase is checked: file size after <= before; if a phase cannot
meet it, it stops and comes back to you.

### 5. Probe list (registry is path-only: probe ON each carrier)

Already measured on `divi/group` (`docs/shape-evidence-5.15.1.md`): hover background, border colour, shadow, translate.y
(`mo-hv-group-*`). Rest-state border / radius / spacing / background / shadow are used by shipped, render-gated recipes
on groups (hero-gradient-stats carries all of them), but I will not rely on that. New candidate file
`fixtures/shapes/candidates/kit-card.json`, expectations PASS unless noted, each rendered on **group and column**:

- rest: `module.decoration.border.desktop.value.styles.all.{width,style,color}` for solid/dashed/dotted/double;
  `styles.bottom` only (underline form); `radius` four corners.
- rest: `module.decoration.spacing.desktop.value.padding` four sides; `module.decoration.background...color` with the
  alpha-free `surface` token resolved.
- shadow forms soft, hard, edge, glow on a group (offset, blur, colour computed).
- hover: lift (`translate.y -4px`), hover frame colour, hover shadow, on a group with a **child image** (does a card
  with radius clip its child image? `kc-radius-clips-image`; if not, the adapter must say so and the bento recipe keeps
  its image radius).
- the button lesson: expect any of border/shadow/spacing on **column** may be dead or behave differently; a dead path is
  added to `fixtures/shapes/dead-on-module.txt` (module-aware guard exists) and the adapter does not write it there.

Each probe PASS goes into the registry and `fixtures/shapes/kit-card-vocabulary.md`; DEAD/css-only is not taught. The hover half of the
underline form was not probed, by ruling during the build (see the Outcome): the adapter writes no hover frame colour on an underline card.

### 6. Migration in phases (each ends with its render gate and a size check)

0. **Probes** (one at a time on the dev site) + vocabulary doc. Gate: probe results committed.
1. **Adapter + resolver + tests** (roles `card`, `panel`, surface `surface`, golden, contrast and descendant-walk
   proofs, lint) and **3 recipes**: hero-spec-card (panel), hero-gradient-stats (card), cta-split-form (panel). Gate:
   `make test`; `verify-recipes --names` for those 3 under default + 7 languages; `verify-kits` extended to render a card
   and a panel on the four bands in every kit/language cell (computed border, radius, fill, padding, shadow, hover
   lift/frame, child-image clipping), at 1280/900/400.
2. **Remaining group recipes** (9 more), in chunks of ~18 recipes for renders. Mood tags re-read for each touched
   recipe; `mood-avoids-exemptions.txt` stays shrink-only.
3. **Column cards** (3 recipes: proof-press-quotes holds rules, not cards) if Decision 1 = yes; blurb/testimonial only on a separate go.
4. Docs, token measurement, final whole-branch review.

### 7. Guide wording (generated, never hand-typed per kit)

`StyleGuide` lines that say "Only buttons are kit-driven so far ... not applied yet" (the catalog help and each
`style-kit:<id>` topic) are replaced by sentences built from one constant, `KitAdapters::APPLIED` (block => roles), so
the text can only claim what an adapter exists for. `style-kit:<id>` shows, besides the resolved primary and secondary
button, a resolved card and a resolved panel produced by the real pipeline. Both topics stay under 10,000 B (tested at
50 kits). The `KitResolver` docblock and the `get_section_recipes` `style_kit` description say "buttons and cards".

### 8. Done means

- A visibly different card look per kit: the visible-difference rule gets a card clause (two kits that differ by less than
  a visible step on cards AND buttons are near-duplicates, same shrink-only exemption list), and `verify-kits` measures it.
- Contrast: zero exceptions across every shipped kit x language x role x band, plus the descendant walk on every marked
  recipe card; no baseline file.
- Size: recipes file smaller than 695,877 B; `style-kits.json` untouched or smaller.
- Golden: old button patches byte-identical; shape registry lint covers every path the card adapter emits;
  dead-on-module guard covers carriers.
- Live: probes PASS, `verify-kits` card cells PASS, the migrated recipes re-rendered; free suite and Pro suite green;
  validator and root zip diff vs main empty.

## Inverted surfaces (parked; my recommendation)

Cards and panels filled with the `text` colour (3 recipe blocks + 1 accent tile) are why 2 recipe buttons are bespoke and
why every outline kit's secondary is a solid block. A real fix needs a role for "inverted ground" whose children ink is
tokenised (`inverse`/`bg`), plus a button surface `on: "panel"`. It is a second adapter-sized change touching button
rules, so I recommend **not** bundling it: v1 leaves those 4 groups and 2 buttons bespoke (allow-listed, shrink-only),
and a follow-up spec adds `panel-inverted` once cards are proven.

## Left to the builder

Exact marker spelling beyond the above; generated report script name; probe ids; the byte layout of the card golden;
how verify-kits lays out a card on each band.

## Decisions for you (all five were taken as recommended)

1. Carriers: group + column (recommended) or group only.
2. Frame floor of 1px for every card (recommended) or let quiet kits' cards go frameless when width is 0.
3. Card fill always `surface`/`bg` (recommended) or allow transparent for outline/ghost kits (needs children ink
   per band, i.e. the inverted-surface work).
4. Hover on `card` only, `panel` static (recommended).
5. Inverted surfaces: separate follow-up (recommended) or in this branch.

## Outcome (2026-10-19)

Built on branch `feat/card-adapter` (base 71540da; nothing released, pushed or version-bumped; the tracked zip is still the
published 4.12.0 build; the validator copies are untouched). Numbers below come from `make card-report`, `wc -c`, `make test`
and the task reports of the build. Live gates on the real Divi 5.15.1 (2026-10-19): the full `make verify-kits` 264 PASS, 0 FAIL over 88
kit/language cells (cards, panels and a three-column row included; run on the code before the final-review fix wave, then the 18-cell
smoke again on the final code: 18 PASS); the default `verify-recipes` over all 71 recipes (default look and glass-dark) 426 PASS, 0 FAIL;
the 15 migrated recipes were also rendered under eight looks (default plus seven design languages), 360 PASS, 0 FAIL; `make
verify-shapes` agrees with every expectation. Three advisory low-contrast lines for shop-grid (unchanged by this branch) are not new.

**Built against the phasing.**
- Phase 0, probes (Task 1): 21 decisions on each carrier, 42 candidates (`fixtures/shapes/candidates/kit-card.json`), all PASS
  on `divi/group` and `divi/column` at 1280/900/400, 0 DEAD, 0 css-only, no overflow; the registry gained no path (every
  path was already registered), the evidence is the 42 `kc-*` sections of `docs/shape-evidence-5.15.1.md`; vocabulary in
  `fixtures/shapes/kit-card-vocabulary.md`. Finding: a radius makes Divi print `overflow: hidden`, so a card with a radius
  clips an image child (pixel-measured, both carriers). Not probed: the hover frame colour of an underline (bottom-only)
  card (see deviations).
- Classification (Task 2): `scripts/card-migration-report.php` (`make card-report`, `CardMigrationReportTest`). 356 groups:
  wrapper 212, padded-wrapper 41, rule 26, grid-cell 40, chip 14, inverted 4, card 9, panel 10; 12 columns with frame, radius
  or shadow: 9 cards, 3 rules. The report also lists which blocks already carry a marker.
- Adapter and resolver (Task 3): `KitAdapters::card()` and `KitAdapters::APPLIED`, roles `card` and `panel` on `divi/group`
  and `divi/column`, bands light|surface|accent|dark in `KitResolver`; the golden `tests/golden/kit-card-patches.json`
  (10 default kits, 80 patches).
- Proofs (Task 4): card contrast over 40 kits x 10 languages x {card, panel} x 4 bands x {rest, hover} = 3,200 cells, zero
  exceptions, no baseline file; `CardInkWalkTest` (every colour token on every descendant of every marked card, all 10
  languages, with planted failing cases); a visible-difference clause for cards; the dead-on-module guard covers carriers;
  `ROLE_BLOCKS` is tested against `APPLIED`.
- Guide wording (Task 5): the sentence "Kit-driven today: buttons ... and cards ..." is built from `APPLIED`
  (`StyleGuide::appliedSentence()`); each `style-kit:<id>` topic gained "Cards and panels in this kit" with a resolved card
  and panel from the real pipeline; the `style_kit` description says "buttons and cards". Catalog 8,480 -> 8,435 B (9,919 B
  at 50 kits, bound 10,000), largest kit topic neo-brutal 7,881 B; default guide unchanged at 28,739 B.
- `verify-kits` (Task 6): each cell now renders 12 cards (card and panel on four bands, a zero-padding clip panel, a
  three-column row of column cards) and measures computed border, radius, fill (computed and painted), padding, shadow, a
  real hover, clipping, overflow, contrast and stacking at three widths; 42 pure tests plus a real-Chromium test on
  synthetic pages (15 deliberate departures caught). The live smoke first FAILED on the accent band at 1280/900 (painted fill
  differed from the computed fill): the cause was the fixed WordPress admin bar and its opened menu painted over the card in
  the screenshot, a defect of the gate and not of Divi or the sheet. The gate now hides fixed/sticky elements while sampling
  and parks the pointer at the right edge; smoke after the fix: 3 kits x editorial, glass-dark, 18 PASS, 0 FAIL.
- Migrations (Tasks 7-9), each re-rendered live with `verify-recipes` (default look + 7 languages x 3 widths): 3 recipes /
  72 PASS (hero-gradient-stats, hero-spec-card, cta-split-form), 9 recipes / 216 PASS, 3 recipes / 72 PASS, 0 FAIL in all.
  19 groups carry a marker (9 cards, 10 panels in 12 recipes) and 8 columns (icon-values, testimonials-avatar-grid,
  pricing-three-tier #1 and #3). ~~5 blocks stay bespoke in the shrink-only `BESPOKE_CARDS` of `RecipeLibraryTest` (max 5):~~ **Fixed in kit-limits, see `2026-10-19-kit-limits-design.md` Outcome.** (the list ended empty, cap 0; it was:)
  compare-two-panels group#7, features-bento group#1 (accent tile) and #3 (text tile), pricing-two-plan-highlight group#8
  (inverted/accent) and the featured pricing column (pricing-three-tier column#2). Those five take the whole-value token
  `{{aied:kit:radius-card}}` as their radius so a pair of neighbours keeps matching corners. Size:
  `section-recipes.json` 695,877 -> 694,097 (T7, after the tilt was restored) -> 688,186 (T8, after a fix) -> 683,191 B (T9), -12,686 B in all.

**Deviations from the spec.**
- Both carriers were built (Decision 1 as recommended): the 42 probes passed, so the column part was not cancelled.
- Frame colour amended from the spec's table: a kit border colour `accent` gives a `text` frame, and the hover frame of a
  `fill-swap`/`invert` card is `text` (written only when the rest frame is not already `text`), not `accent`. Why: an accent
  frame is 2.30-2.45:1 on the card fill in playful-pastel and 2.81:1 on brutalist-flat's bg, below the 3:1 a meaningful frame
  needs; the first run of the contrast proof failed 125 cells (10 at rest, 115 on hover). The rule is language-independent;
  `text` is the role chosen (it is at least 3:1 on every fill; `muted` also, but it would change meaning). Consequences,
  to be read as design losses: a kit with a `text` frame has NO card hover change (a lift or shadow still applies), among
  them the defaults of playful-pastel (chunky-pill-offset) and brutalist-flat (raw-frame) and every outline kit;
  rimmed-solid lost its accent rim; only a kit with a `border` hairline frame darkens its frame on hover.
- An underline card (`border.sides: bottom`) writes NO hover frame colour: the hover half of the underline form was never
  measured (the candidate could not show a change), so it is NOT PROVEN and not written; such cards keep lift and shadow only.
- The adapter writes the fill (spec section 4 already assumed it): a migrated block drops its own background literal.
- ~~The featured pricing column (pricing-three-tier#2) stays bespoke: a kit would give all three plans the same frame and
  erase the highlight. It keeps its own accent frame, fill, shadow and padding and takes only the radius token.~~
  **Fixed in kit-limits, see `2026-10-19-kit-limits-design.md` Outcome.** It is now an accent ground card.
- The audit corrected the brief: not 358 groups that are cards but 19 cards or panels in 12 recipes plus 8 migrated column
  cards in 3 (9 column cards, one bespoke). proof-press-quotes' three columns are rules (a 2px top rule, no fill), not cards,
  and are not migrated.
- ~~A recipe's REST transform is recipe composition and is kept on the marked card~~ (kit-limits tightened this: a marked
  block now carries NO transform but the kit's hover; hero-gradient-stats keeps its -3/2/-1 degree tilt and its phone reset
  on three UNMARKED wrapper groups around the cards, see `2026-10-19-kit-limits-design.md` Outcome, Task 1); only hover
  transform, border and shadow of a migrated card go. The literal 40px shadows of
  hero-gradient-stats, hero-split-left-overlap and transition-overlap-panel were removed (the kit decides the shadow).
- Recipe prose that described the old look ("rounded", "hairline-bordered", "square-cornered", "raised") was reworded for
  every migrated recipe, enforced by a phrase test (`CARD_DECIDED_PHRASES`); moods were not changed (no language's `avoids`
  concerns corners or shadow, and widening a mood would be a guess). Pricing-three-tier's title says "highlighted" now.
- Size estimate: the spec guessed about 8 KB for groups and 3-4 KB for columns; measured -7,691 B and -4,995 B.
- The spec's "about 12 recipes" for groups held (12); group plus column is 15 recipes.
- The plan's bespoke list was first written as something each task extends; it is a shrink-only allow-list (listed blocks
  must carry no marker, the list can only get shorter), the same convention as the other exemption lists.

**Known limits.**
- The ink walk fails open: a module with any element background is skipped entirely (no font or icon colour of it is
  judged); only `{{aied:color:*}}` tokens are judged, so a literal hex is never seen; a colour inherited from outside the
  card (a section's font colour) is not modelled; the marked block's own non-marker attributes are not walked. An
  unresolvable background token is treated as an empty ground and not as "cannot judge".
- Card text readability rests on the fill being `surface` or `bg`: the pairs the recipes already declare. A freehand card
  in the guide's colour legend omits `surface` and the `border` hairline (the legend is built from button patches).
- 9 of 780 kit pairs give the same card (`fixtures/shapes/card-near-duplicates.txt`, shrink-only, cap 9): quiet-solid /
  steady-solid, steady-solid / soft-solid, raw-frame / thick-frame-caps, raw-frame / thick-frame, hairline-frame-caps /
  text-link, hairline-frame-caps / text-link-caps, thick-frame-caps / thick-frame, flat-square / block-caps, text-link /
  text-link-caps. They differ as buttons, which is what they were made for.
- A kit gives one padding for all devices (24-40px); the migrated cards used to have per-device steps (phone 20-24px), so a
  card is roomier on phones (features-bento, hero-bordered-cells, testimonials, pricing; accepted as not visible enough by the
  build's controller, Ruling 7). features-bento's bespoke tiles keep their own padding next to kit cards.
- Recipes that lost a literal shadow (hero-gradient-stats, hero-split-left-overlap, transition-overlap-panel) look flatter
  under kits whose shadow is none; their depth now comes from the frame, zIndex and overlap. hero-bordered-cells' "heavy
  border" character depends on the kit.
- ~~Under heavy-frame kits (hard/glow/edge shadow, thick frames) the two kit-driven pricing neighbours can look as prominent as
  the bespoke featured plan, or more so (seen under playful-pastel); the highlight no longer wins everywhere.~~
  **Fixed in kit-limits, see `2026-10-19-kit-limits-design.md` Outcome.** The featured plan is an accent ground card, proven more prominent than its neighbour in 400 of 400 kit x
  language cells (weakest: playful-pastel, 2.304:1 against 1.062:1).
- hero-gradient-stats still has the title "floating figures" although its deep literal shadow was removed (the depth that title
  described came from that shadow; the cards still overlap and tilt). cta-card-over-image's title was "a card floating on a
  photo", which a kit without a shadow does not give: it now reads "a card on a photo" (the final review).
- ~~Hover on a TILTED card, measured live (hero-gradient-stats, editorial, kit soft-lift): the hover transform REPLACES the rest
  transform, so each card goes from its tilt (-3, +2, -1 deg) to `matrix(1,0,0,1,0,-4)` (no rotation, 4px up) and tilts back when the
  pointer leaves; under quiet-solid (no lift) rest and hover are identical. Under a lift kit the tilted cards therefore straighten on
  hover. It reads as a settle, not a break, but it is not what the markup spells out (rest rotate + hover translate). If the tilt
  should survive, the hover state would have to carry the rotation too (`rotate.z` next to `translate.y`, not probed).~~
  **Fixed in kit-limits, see `2026-10-19-kit-limits-design.md` Outcome.** The tilt sits on an unmarked wrapper group and the card lifts inside it; measured live
  (soft-lift): the wrapper keeps its tilt, the card goes up exactly 4px.
- Card media radius and the images' radius are still not applied; text decisions (capitalization, weight, letter spacing) stay
  button-only; accordion, forms, blurb and testimonial modules keep the recipe's own styling.
- `verify-kits`: a fixed/sticky element INSIDE a card used to stay hidden after the fill sample (fixed in the final review:
  hidden first, never tagged twice, with a case in `scripts/test-kit-card-run.cjs`); the button
  path still parks the pointer at (0,0) and does not hide fixed elements (it passed 264 of 264); `kit-checks.cjs` is about 770
  lines doing two jobs; the offline PHP re-implements the sheet pipeline.
- Columns were probed in a single-column row only; the multi-column case is covered by the sheet's three-column row and by
  the live renders of the three column recipes, not by a probe.

**Deferred and owner decisions left.** Merge and release; the full `make verify-kits` run (about an hour) and the final
whole-branch review; next adapters (accordion/toggle, forms, blurb, testimonial); ~~the inverted-surface role (the 4 bespoke
groups and the 2 bespoke buttons, a second adapter-sized change)~~ (Fixed in kit-limits, see `2026-10-19-kit-limits-design.md` Outcome.); possibly a per-language frame colour instead of the
language-independent `text`, which would bring back an accent rim and card hover on playful-pastel and brutalist-flat;
image-to-layout later.
