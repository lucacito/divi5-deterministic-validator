# Kit limits: tilted cards, inverted and accent grounds, a featured plan that stays featured

Status: APPROVED (owner, 2026-10-19, all five recommendations) and BUILT on branch `feat/kit-limits` (nothing released; see the
Outcome section at the end). Follows `2026-10-19-card-adapter-design.md` (its Outcome
section records the three limits below as measured on Divi 5.15.1) and `2026-10-18-style-kits-design.md`.

## Intent and why

The card adapter left three visible flaws: tilted cards straighten on hover, five text- or accent-filled surfaces and two buttons
cannot follow a kit, and under heavy-frame kits the featured pricing plan looks weaker than its neighbours. This spec fixes all
three with the proof level the kits already have (pure contrast proofs with zero exceptions, registry lint, goldens, the live
render gate) and makes the recipes file smaller, not larger.

## What the audit found (numbers from `php scripts/card-migration-report.php --all` and a scan of the recipes file)

| Fact | Value |
|---|---|
| Marked card/panel blocks with a rest transform | **3**, all in hero-gradient-stats (group #1/#2/#3: `rotate.z` -3/2/-1 deg at desktop, phone `rotate.z 0deg` + `translate.y 0px`). No column card and no other recipe is tilted. |
| Bespoke grounds (`BESPOKE_CARDS`, 5 entries) | compare-two-panels group #7 (`text` fill, children `bg`), features-bento group #1 (`accent` fill, children `accent-text`) and #3 (`text`, children `bg`), pricing-two-plan-highlight group #8 (`text`, children `bg`), pricing-three-tier column #2 (featured: `surface` fill, 2px `accent` frame, shadow, an `accent` badge). Their literals: 354 + 354 + 354 + 433 + 457 = 1,952 B. |
| Bespoke buttons (`BESPOKE_BUTTONS`, 2) | compare-two-panels #0 (solid `accent` fill, `accent-text` label, on the `text` panel), pricing-two-plan-highlight #1 (`bg` fill, `text` label, on the `text` panel). |
| Bands of those recipes | all `bg` or `surface` sections (light). No ground sits on an accent or photo band. |
| Pairs on a `text` ground (10 languages) | `bg`/text 11.98-17.22, `surface`/text 13.27-21.00: AA everywhere. `muted`/text 1.84-2.97 and `inverse`/text 1.02-1.09 in glass-dark, tech-mono, luxe: **fail**. So the inverted ink is `bg` (or `surface`), never `inverse` or `muted`. |
| Pairs on an `accent` ground | `accent-text`/accent 5.75-14.48: AA. `text`/accent 1.10-5.88: fails in 8. accent vs the light band 2.30 (playful-pastel) to 14.83. |
| Outline kits | 13 of 40; their secondary is a solid `text` block on light sections (role table, `KitAdapters::SECONDARY`), so the secondary outweighs the outlined primary. 7 recipe secondaries exist. |

## Goals, non-goals

Goals: a tilted card keeps its tilt and still lifts; text- and accent-filled cards and panels and the buttons on them follow the
kit (corners, frame width, shadow, padding, hover) with readable children in every kit x language; the featured plan is the most
prominent card in its row in every kit x language, measured; `BESPOKE_CARDS` and `BESPOKE_BUTTONS` end empty.
Non-goals: new kit decisions or kit data changes (except one summary sentence, item 4); gradients; accordion/forms/blurb adapters;
any new MCP tool, REST route or OpenAPI operation; the validator (untouched); a ground on an accent or photo band (none exists).

## 1. Tilted cards keep their tilt on hover

Problem (measured live, editorial + soft-lift): Divi's hover transform REPLACES the rest transform, so the cards go from
`rotate(-3deg)` to `matrix(1,0,0,1,0,-4)` and tilt back on leave.

Options considered:
- (a) The adapter copies the block's rest rotate/scale/skew into `transform.desktop.hover` next to `translate.y -4px`. Generic,
  but it makes `KitAdapters::patch` read the block (today it is a pure function of kit, role and band), it needs a new probe of
  the COMBINATION rotate+translate on hover on both carriers, and it opens a breakpoint question nobody has measured: the recipe
  resets the tilt on phones (`phone.value.rotate.z 0deg`), and if Divi's desktop hover rule also applies at 400px a tapped card
  would tilt on a phone, so the adapter would have to write a `phone.hover` too (another probe).
- (b) The recipe writes the hover tilt itself. Rejected: it is a hover transform the kit does not write, which the merge trap
  (`RecipeLibraryTest::markedCardProblems`) forbids for good reason, and under a non-lift kit it is a stray state.
- **(c) Recommended: move the tilt to an unmarked wrapper.** Each tilted card becomes a plain `divi/group` that carries the rest
  transform (and the phone reset) and holds the marked card, which carries only what the kit writes. The two transforms sit on
  two elements, so they compose: the wrapper tilts, the card lifts inside it. Every path is already proven on `divi/group`
  (rest `rotate.z` in a shipped, render-gated recipe; hover `translate.y` in probe `kc-*` / `mo-hv-group-*`; nested groups in
  many recipes). The adapter, the resolver and the registry do not change.

Merge rule (tightened, not loosened): a marked card may carry NO transform except the kit's exact hover. The current allowance
for a rest transform on a marked card is removed, so the next tilted card cannot reintroduce the flaw.
Proofs: `RecipeLibraryTest` (the stricter rule; a planted case of a tilted marked card fails); the live check below.
Migration: hero-gradient-stats only; the three transforms move to three wrappers. Size: about +3 x 130 B for the wrapper
blocks, the transform bytes move rather than grow. Watch: the wrapper must keep the card's grid sizing and overlap (`zIndex`,
negative margin); the builder copies those that belong to the outer box to the wrapper.
Live: `verify-recipes --names hero-gradient-stats` under the default look and 7 languages at 1280/900/400 (about 5 minutes),
plus one scripted hover measurement (the same `getComputedStyle` read the Outcome used): wrapper keeps `rotate(-3deg)`, card goes
to `translate(0,-4px)` under a lift kit, nothing tilts at 400px. If (c) breaks the layout and the builder must fall back to (a),
that is a stop-and-ask point (it needs the probe pair on group and column, about 20 minutes).

Worth doing? It is 3 blocks in one recipe. It is cheap with (c) (one task, no new code path) and the stricter test protects
every future recipe, so yes. The alternative "drop the tilt" loses the recipe's one distinctive move; "accept the settle" is
what we have now.

## 2. Inverted and accent grounds (cards, panels, and the buttons on them)

Mechanism: one optional marker field, `ground`, on the existing card roles: `"aied":{"kit":"card|panel","on":"light|surface","ground":"inverted|accent"}`.
Not a new role: `KitAdapters::APPLIED` (block => roles) stays as is, so the generated guide sentence does not change. A `ground`
is refused (a leftover marker, reported) on `accent` and `dark` bands and on any block other than group/column.

| Property | `inverted` | `accent` |
|---|---|---|
| Fill | `text` | `accent` |
| Children ink the recipe must use (hard-coded tokens, as today) | `bg` or `surface` | `accent-text` |
| Frame | kit width and style, colour = the fill (the fill gives the shape: bg/text >= 11.98:1 against the band; the frame keeps the box the same size as kit neighbours) | same rule, colour `accent` |
| Radius, padding (`card`), shadow | the kit's, exactly as a plain card on that band | same |
| Hover (`card` only) | `lift` only; no hover frame colour (it would be the fill) | same |

Children are NOT re-inked at serve time: recipes already hard-code `bg` / `accent-text` on these children, and the existing
`CardInkWalkTest` judges every descendant token against the card's RESOLVED fill. Once the marker resolves the fill to `text` or
`accent`, the walk automatically demands `bg`/`surface` or `accent-text`; it gains planted failing cases (a `muted` child on an
inverted card, a `text` child on an accent tile). Its known fail-open (a module with its own background is skipped) matters for
item 3's badge, so that badge's pair also goes into the recipe's `tags.pairs` (AA in all 10 languages, already tested).

Buttons: a new button surface `on: "panel"` (a button inside an inverted panel); a button inside an accent tile uses the existing
`on: "accent"` (same colours as an accent band). The `panel` row of the role table:
- filled treatment: fill `bg`, label `text` for every kit colour (an `accent` fill on `text` is 1.10:1 in tech-mono, so the
  button would lose its shape; `inverse` is never a fill, as today);
- transparent ink (outline, ghost): `bg` (>= 11.98:1 on the panel; border and label);
- hover: `fill-swap` as on light with those colours; `invert` behaves as `fill-swap` (a `text` fill on a `text` panel would make
  the button vanish); `lift` and shadows as on light.
Honest cost: compare-two-panels' "after" CTA stops being accent-coloured; it becomes the kit's button in `bg`/`text`, the same
treatment pricing-two-plan-highlight already ships. The two buttons leave `BESPOKE_BUTTONS`.

Proofs (pure, zero exceptions, no baseline file):
- `KitCardAdapterTest`: 40 kits x 10 languages x {card, panel} x {inverted, accent} x {light, surface} x {rest, hover} = 3,200
  cells: ink pairs AA on the fill, fill distinct from the band (inverted >= 11.98:1; accent >= 2.0:1, decorative, today's minimum
  is 2.30), frame colour equals fill, no hover frame. Refusal cases for `ground` on accent/dark bands.
- `KitAdaptersTest`: the `panel` surface added to the button proof: 40 x 10 x 2 roles x 2 states = 1,600 cells (label >= 4.5:1
  on the fill, transparent border >= 3:1 on the panel).
- Goldens: `kit-adapter-patches.json` and `kit-card-patches.json` byte-identical for every existing entry; new entries for
  the grounds and the `panel` surface. Registry lint covers every emitted path; dead-on-module guard covers both carriers.
Probes: **none new.** Every path (background colour, border, radius, spacing, boxShadow, hover translate) was probe-proven on
`divi/group` and `divi/column` (42 `kc-*` candidates) and on `divi/button`; a ground changes values, not paths. The evidence for
the values is the render gate.
Render gate (`scripts/verify-kits.cjs`, `scripts/lib/kit-card-run.cjs`, `scripts/lib/kit-checks.cjs`): each kit/language cell
gains an inverted card and panel and an accent tile on the light and surface bands, and an inverted panel holding a primary and a
secondary `on:"panel"` button; measured as today (computed and painted fill, frame, radius, padding, shadow, real hover, overflow,
label and child-text contrast as painted, stacking). Pure tests of the new expectations in `scripts/test-verify-kits.cjs`.
Migration: the 4 grounds (compare-two-panels #7, features-bento #1 and #3, pricing-two-plan-highlight #8) and the 2 buttons get
markers and lose their literals (about -1,500 B for the groups, -400 to -600 B for the buttons).
Known look, not a contrast issue: a `hard`/`edge` shadow drawn in `text` ink next to an inverted fill merges with it and reads
as a slightly larger block (chunky-pill-offset, raw-frame). It is decorative; the contact sheet shows it; changing the shadow
ink would be a new rule and is left out.

### What this does NOT fix: the outline kits' solid secondary

The secondary buttons that look like a solid `text` block sit on LIGHT sections, not on panels; the `panel` surface does not touch
them. That limit comes from one entry of the role table (`SECONDARY['outline'] = 'solid'`). A separate, small fix (item 4,
Decision 4): make the outline kits' secondary a **ghost** (transparent, no frame, the surface's ink: `text` on light, `accent-text`
on an accent band, `inverse` on a photo). Every pair is one the proof already passes for the 3 ghost kits, every path is proven,
and it restores the hierarchy (outlined primary, plain-text secondary). Cost: the golden changes on purpose for the 13 outline
kits' secondaries; the `underlined-label` summary ("the secondary is a filled block") is rewritten; the button near-duplicate rule
(`NEAR_DUPLICATE_EXEMPTIONS`, shrink-only, 4) is re-run and must not grow; a ghost secondary on a photo band is quieter than
today's block (the render gate measures it).

## 3. The featured plan stays featured

Problem (live, playful-pastel / chunky-pill-offset): the two kit-driven neighbours get a 2px `text` frame and a 6px hard offset
shadow; the bespoke featured column keeps a 2px `accent` frame (2.45:1 on its fill there, below the 3:1 a meaningful frame needs)
and loses the comparison.

Rejected: a `card-featured` role whose frame is "one step heavier" than the kit's. The kit vocabulary stops at 4px, so the
thick-frame kits have no heavier step; a stronger shadow does not exist above `hard`; and an accent frame fails 3:1 in two
languages. A rule derived from the kit's frame cannot be proven more prominent in every kit.

**Recommended: the featured plan is an `accent` ground (item 2).** pricing-three-tier column #2 gets
`{"kit":"card","on":"light","ground":"accent"}`: the same corners, frame width, shadow, padding and hover as its neighbours (one
family), plus a filled body. Its children are re-inked in the recipe: heading and texts `accent-text` (the muted step is lost: no
muted pair exists on accent), its primary button `on:"accent"` (exists), the badge becomes a `surface` chip with a `text` label
(both pairs AA everywhere; added to `tags.pairs`). pricing-two-plan-highlight's plan is the same idea and is already an
`inverted` ground in item 2. So item 3 needs no new adapter code, only proofs.
Prominence proof, pure (`KitCardAdapterTest`): for every kit x language, the featured fill vs band contrast exceeds the plain
card's fill vs band contrast and is >= 2.0:1, while frame width, radius and shadow form equal the neighbour's (so the featured
card is the neighbour plus a filled body: never less visible). Render (`verify-kits`): the three-column row gets an accent middle
card; a new check, the mean luminance difference between each card's box and the band as painted, must be at least 1.5x the
larger neighbour's in every cell at every width (threshold confirmed on the first smoke run, never lowered after a failure).
Migration: pricing-three-tier column #2 (-457 B of literals, + marker and the re-inked tokens, net about -350 B).
Design cost to accept: the featured plan becomes an accent block instead of a framed white card; in playful-pastel the accent
body is 2.30:1 against the band (strong as a shape, not as a frame).

## 4. Size plan

`section-recipes.json` 683,182 B now, bound 716,800 B. Expected: item 1 about +400, item 2 about -2,000, item 3 about -350:
**ends about 681,200 B**, below today; each phase checks after <= before (+500 B allowed only in the tilt phase) and stops and
asks if it cannot. `style-kits.json` unchanged except one summary (about +-0). Default style guide (28,964 / 29,108 B,
144 B headroom): **not touched** (markers are served resolved, so the AI never needs the `ground` field). Catalog topic (8,435 B,
9,919 B at 50 kits): not touched. Each `style-kit:<id>` topic gains one resolved inverted panel with a `panel` button
(generated by the real pipeline, about 800 B; largest topic neo-brutal 7,881 -> about 8,700 B; the test pins <= 10,000 B at the
worst kit).

## Phases (order, gates, cost)

1. **Tilt** (1 task): wrapper migration + stricter merge rule. Gate: `make test`; the 5-minute recipe render + hover read.
2. **Grounds and the panel surface** (3 tasks): resolver/adapter `ground`, button `panel` row, golden entries, both contrast
   proofs, ink-walk planted cases (one task); the item 4 role-table change if Decision 4 is yes (one task, done here so one full
   render covers it); `verify-kits` extension + pure tests (one task). Gate: `make test`, `scripts/test-verify-kits.cjs`, a
   18-cell smoke (about 10 minutes).
3. **Migrations** (2 tasks): the 4 grounds + 2 buttons; then the featured plan + prominence proof and check. `BESPOKE_CARDS` and
   `BESPOKE_BUTTONS` go to 0 (the lists and their caps are removed or set to 0). Gate: `make test`; `verify-recipes --names` for
   the 4 recipes under default + 7 languages (about 20 minutes).
4. **Full live gates** (controller): the full `make verify-kits` (about 70 minutes today, about 85-90 with the new cards) and the
   full default `verify-recipes` (about 80 minutes); a failure means a fix round and a re-run of the affected cells.
5. **Docs and review** (1 task + review): the card-adapter Outcome limits struck through with a pointer here, CLAUDE.md
   paragraph, `KitAdapters` docblock role table, token measurement, whole-branch review.

Cost: **8 tasks** (7 builder + 1 docs) plus reviews, **0 probes** (1 probe pair, about 20 minutes, only if the tilt falls back
to option (a)), about **3.5 hours of live rendering** on the dev site (one at a time), roughly 1.5 working days end to end.
Item 1 alone is about 1 hour; item 4 alone about 2 hours plus a full verify-kits.

## Done means

- hero-gradient-stats: tilt kept at rest and on hover under every lift kit, measured; no marked card carries a rest transform.
- `BESPOKE_CARDS` and `BESPOKE_BUTTONS` empty; every ground and panel button kit-driven.
- Contrast: zero exceptions over the 3,200 ground cells and the 1,600 `panel` button cells; ink walk green with planted failures.
- Featured plan more prominent than its neighbours in every kit x language (pure proof and render check).
- Goldens: every existing entry byte-identical, except the outline secondaries if Decision 4 is yes (changed on purpose, listed).
- Size: recipes file below 683,182 B; default guide and catalog untouched; every kit topic <= 10,000 B.
- Live: `verify-kits` full run PASS, the touched recipes PASS under 8 looks, the full default recipe render PASS; free and Pro
  suites green; validator and root zip diff vs main empty.

## Decisions for you

1. Tilt: **wrapper group (recommended)**, or the adapter copying the rest transform into the hover (needs probes and a phone-hover
   rule), or drop the tilt.
2. Grounds as a `ground` field on the card roles (**recommended**: no new role, guide sentence unchanged) or new roles
   `card-inverted` / `panel-inverted` / `card-accent`.
3. Featured plan: **accent ground (recommended)**, or inverted ground (darker, strongest everywhere, but loses the brand colour),
   or keep it bespoke and accept the limit.
4. Outline kits' secondary: **make it a ghost (recommended)**, changing 13 kits' secondaries and one summary; or keep the solid block.
5. compare-two-panels' "after" CTA turns from accent to the kit's `bg`/`text` panel button: **accept (recommended)**, or keep that
   one button bespoke (the list then ends at 1, not 0).

## Left to the builder

Exact marker spelling; how the wrapper carries the overlap/zIndex of hero-gradient-stats; byte layout of the new golden entries;
the luminance-difference threshold (confirmed on the first smoke, then frozen); how verify-kits lays out the new cards; wording of
the regenerated kit-topic lines and of the `underlined-label` summary.

## Outcome (2026-10-19)

Built on branch `feat/kit-limits` from main ae0688d in eight tasks (Task 9, the full live gates, is the controller's). NO release,
version bump or zip rebuild: the tracked root zip is still the published 4.12.0 build; the validator (`src/`,
`wp-plugin/validator/`) is untouched. The three flaws of the card adapter and the outline secondary are fixed; the five
Decisions went the recommended way.

**What was built, per task.**
- **T1 Tilt wrapper (Decision 1).** hero-gradient-stats: the three tilts (-3, 2, -1 deg, with the phone reset) moved to three
  UNMARKED wrapper groups (#1, #3, #5) that hold the marked cards (#2, #4, #6). The wrapper carries the outer box (zIndex,
  width, maxWidth, alignment, margins) and the tilt; the card carries only `width: 100%` plus what the kit writes. The merge
  rule is tighter: a marked block (card, panel, group or column) may carry NO transform at all except the kit's exact hover
  (`RecipeLibraryTest::markedCardProblems`, planted cases for a rest rotate on a card, a panel and a column). Live hover
  measurement (soft-lift, editorial): the WRAPPER keeps its tilt (-3/2/-1 deg) while the CARD lifts exactly 4px inside it
  (rotation 0, translateY -4px, back on unhover); quiet-solid: no change; at 400px nothing is tilted at rest, the card still
  lifts 4px. Recipes file 683,182 -> 683,633 B (+451, cap +500). The fallback option (a) was not needed.
- **T2 Grounds and the button surface `panel` (Decision 2).** `KitAdapters::patch()` gained an optional fifth parameter
  `ground` (`inverted|accent`); the marker is `{"kit":"card|panel","on":"light|surface","ground":"inverted|accent"}`;
  `KitResolver` accepts it only on a group/column, a card role and a light or surface band, anything else is a reported
  leftover. `APPLIED` is unchanged, so the guide sentence did not change. Button surface `on:"panel"` (filled: fill `bg`,
  label `text`; transparent: `bg` ink; `invert` is `fill-swap`; hard/edge shadow in `bg`, glow in `bg` at 45%
  (`{{aied:color:bg:45}}`), no soft shadow on a panel). Proofs, zero exceptions, no baseline file: **6,400 ground cells**
  (40 kits x 10 languages x 2 roles x 2 grounds x 2 bands x 2 states; the spec said 3,200, but its own product is 6,400) and
  **1,600 panel button cells** (40 x 10 x 2 roles x 2 states). Goldens: additions only (card golden 80 -> 160 patches; button
  golden 72 -> 92 patches). **Ruling 2:** on a panel the border of a FILLED button is its fill (`bg`) at rest and on hover,
  whatever the kit's border colour; a `text` or `accent` border would melt into the `text` panel at rest and reappear on
  hover, so the box would shrink beside its secondary and jump (six kits were affected; only raw-frame and chunky-pill-offset
  have golden entries, and both are new keys). The inverted band contrast is stated as the measured least, 11.978:1
  (organic-warm; the spec's 11.98 was rounded up); the accent ground's measured least is 2.30:1 (playful-pastel).
  `CardInkWalkTest` now reads the fill from the adapter's resolved patch, so a ground makes the walk demand `bg`/`surface` or
  `accent-text` from every descendant (planted failures: `muted` on an inverted card, `text` on an accent tile).
- **T3 Outline secondary is a ghost (Decision 4).** One entry of the role table (`SECONDARY['outline'] = 'ghost'`); 13 outline
  kits; no new pair (every pair is one the 3 ghost kits already passed: 3,200 band cells and 1,600 panel cells, zero
  exceptions, no role-table amendment). The catalog wording is derived from the adapter ("The secondary is a plain text
  link."; no catalog line says "filled block"); `StyleGuide::kitTextOnlySentence()` also fixed an existing false claim
  ("this kit's border width is 0px" for the ghost kits whose border is 1px). One data string changed
  (`style-kits.json`, underlined-label: "the secondary is a plain label with no underline", 25,486 -> 25,479 B); no decision
  changed. Golden: exactly 3 keys (`outline-2|control-secondary|light|accent|dark`); the card golden untouched; the
  near-duplicate exemptions stay at 4.
- **T4 Render gate.** `scripts/verify-kits.cjs`, `scripts/lib/kit-card-run.cjs`, `scripts/lib/kit-checks.cjs`: each kit/language
  cell now renders **20 cards** (an inverted card, an inverted panel, an accent tile and a panel-button pair on the light and
  surface bands; the three-column row's middle card is an accent ground and the featured one) and measures them. New codes:
  `ground-fill` (painted fill vs the resolved ground fill), `panel-button` (label and border contrast on the painted panel,
  rest and hover) and `featured` (mean luminance difference of a card's box against the band, featured >= 1.5x the larger
  neighbour at every width). 52 pure tests (`scripts/test-verify-kits.cjs`) and 7 new synthetic Chromium departures
  (`scripts/test-kit-card-run.cjs`); an offline proof over 400 kit/language pairs through the real pipeline. Live smoke
  (3 kits x editorial, glass-dark): 18 PASS, 0 FAIL; the featured prominence ratios were 11.9x to 49x against the frozen 1.5
  threshold, at every width including 400px.
- **T5 Four bespoke grounds and two bespoke buttons (Decision 5).** compare-two-panels #7 (inverted panel), features-bento #1
  (accent card) and #3 (inverted card), pricing-two-plan-highlight #8 (inverted panel on a surface band), and the buttons
  compare-two-panels #0 and pricing-two-plan-highlight #1 (`control` on `panel`, the pricing one is the primary) now carry
  markers and lost their literals. `BESPOKE_BUTTONS` ended empty; `BESPOKE_CARDS` 5 -> 1. The ink walk passed with no child
  re-inking. Recipes 683,633 -> 681,727 B (-1,906). Live: 3 recipes x (default + 7 languages) x 3 widths = 72 PASS, 0 FAIL.
- **T6 The featured plan is an accent ground card (Decision 3).** pricing-three-tier column #2 is
  `{"kit":"card","on":"light","ground":"accent"}`: attribute-identical to its neighbours, children re-inked to `accent-text`,
  its primary button `on:"accent"`, the badge a `surface` chip with a `text` label. `BESPOKE_CARDS` 1 -> 0 (cap 0, asserted
  empty). Prominence proof over 400 cells, all pass: the weakest cell is playful-pastel, accent fill vs band 2.304:1 against
  the neighbour's plain card 1.062:1 (the 2.0 floor holds); the others range from brutalist-flat 2.814 to tech-mono 14.826.
  Recipes 681,727 -> 681,269 B (-458). One dev-side script change: `scripts/card-migration-report.php` got `CHIP_OVERRIDES`
  (13 lines, same mechanism as `PANEL_OVERRIDES`) so the surface-filled badge is not read as a bespoke card.
- **T7 Kit topics show the inverted panel.** Each `style-kit:<id>` topic gained an "Inverted panel" snippet (a panel with
  ground `inverted` holding one primary `on:"panel"` button, resolved by the real pipeline) and the ground sentences, all
  generated from the adapter's patches; the legend covers every hex. The largest topic (neo-brutal) went **8,124 -> 9,992 B**
  against the 10,000 B bound: **8 B of headroom**. The spec's estimate (about 800 B) was too low (+2,770 B measured for a
  heading, a paragraph and a button), so the snippet holds the button ONLY and three unpinned prose phrases were trimmed.
  Ruling 3: accepted for now; the 8 B headroom blocks any further kit-topic growth, and the accordion project's generated
  `kit-rules` topic (it moves the repeated card and panel explanation out of the 40 topics) is the planned way to free space.
  Catalog 8,464 B and default guide 28,964 B are unchanged by T7.

**Measured sizes and counts** (commands run in the worktree at the end of Task 8):
- `wc -c wp-plugin/data/section-recipes.json`: **681,269 B**, against 683,182 B at the start (-1,913 B; the spec expected
  about 681,200). `style-kits.json`: 25,479 B. Bound 716,800 B and the 700 KB rule hold.
- `php scripts/card-migration-report.php`: `BESPOKE_CARDS` 0, `BESPOKE_BUTTONS` 0, **32 marked card/panel blocks** (27 at the
  start: +4 grounds +1 featured column; 28 marked buttons, 26 at the start), 0 card/panel literals left to replace; groups
  359 (wrapper 226, padded-wrapper 53, rule 26, grid-cell 40, chip 14), columns 12 (wrapper 9, rule 3).
- `php scripts/measure-tokens.php` (`docs/token-usage.md`): default guide 28,964 B, catalog topic `style-kits` 8,464 B, kit
  topics 8,370 B (rimmed-solid) to 9,992 B (neo-brutal).
- Suites: free 2573 tests (`php vendor/bin/phpunit -c phpunit.xml`), Pro 2169 (`-c pro-addon/phpunit.xml`), both OK; at the
  start of the branch the card adapter's paragraph said 2535.

**Deviations from the spec.**
- 6,400 ground cells, not 3,200 (arithmetic of the spec's own product).
- Ruling 2 (a filled panel button's border is its fill) is not in the spec; Ruling 3 (a button-only inverted-panel snippet).
- The kit topic cost +2,770 B, not about 800 B; headroom 8 B instead of about 1,300 B.
- One kit-data string was edited (underlined-label), although the spec's non-goals say "no kit data changes except one summary".
  It is that summary.
- `scripts/card-migration-report.php` was touched (the chip override), a dev-side script.
- The hard/edge `bg` shadows and the `bg` at 45% glow on a panel, and no soft shadow on a panel, were decisions of T2 (the
  overlay at 18/25% is 1.00-1.05:1 on the light languages' `text`).

**What changed on purpose (read as design changes).** The 13 outline kits' secondary buttons are plain text links now
(hierarchy: outlined primary, plain secondary) on every surface. compare-two-panels' "after" CTA is no longer accent-coloured:
under every kit it is a `bg` fill with a `text` label on the text panel (the treatment pricing-two-plan-highlight already
shipped). The featured pricing plan is an accent block with the kit's frame, shadow, padding and corners instead of a framed
white card. The four recipe grounds take the kit's corners, frame, shadow and padding; features-bento's tiles take the kit's
padding in place of their fixed 36/28/24px.

**Not done.** A ground on an accent or photo band (none exists; the resolver refuses it). A `card-featured` role (rejected in
the spec). Gradient fills. An accent-ground snippet in the kit topics (described in words only). Media radius; the accordion,
forms, blurb and testimonial adapters (the accordion adapter is the next project). No new tool, route or OpenAPI operation.

**Known limits.**
- A `hard` or `edge` shadow drawn in `text` ink next to an inverted fill merges with it and reads as a slightly larger block
  (chunky-pill-offset, raw-frame). Decorative; changing the shadow ink would be a new rule.
- A text-bordered kit's panel button (raw-frame, chunky-pill-offset) has its border painted in the fill, so a primary reads as
  its fill only; there is no soft shadow on a panel at all (soft-lift and warm-pill-soft buttons on a panel have none).
- The ink walk's fail-open limits remain (a module with any element background is skipped, only `{{aied:color:*}}` tokens are
  judged, the marked block's own attributes are not walked); an ancestor font colour is guarded now (a ground makes the walk
  demand the right inks), but a literal hex is still never seen.
- The ghost secondary is quieter than the old solid block on a photo band; underlined-label's ghost secondary is a plain
  label with no underline (the topic says so).
- The divider rules inside the featured plan are `accent-text` at 40% alpha and the ink walk does not judge borders, so
  nothing proves their visibility except the live render. The featured plan's frame equals its accent fill, so on framed kits
  it merges into the body (its shape is the fill).
- The accent ground is 2.30:1 against the band in playful-pastel (strong as a shape, decorative by the 2.0 floor); the
  inverted band contrast has a measured least of 11.978:1 (organic-warm).
- The kit topics have 8 B of headroom (see T7).

**Live gates.** The full gates (full make verify-kits, the narrowed recipe render, verify-shapes) were pending when this
section was written; the controller records their results in the ledger and updates this paragraph. Results already recorded
in the ledger: the T1 hover measurement (above) and hero-gradient-stats 24 PASS, 0 FAIL (default + 7 languages x 3 widths);
the T4 smoke 18 PASS, 0 FAIL; the T5 render of three recipes 72 PASS, 0 FAIL. The T6 pricing-three-tier render (x 8 looks)
result is pending.

**Owner decisions left.** Merge and release; the accordion/toggle adapter next (its `kit-rules` topic frees the kit-topic
headroom), then forms and blurb; image-to-layout later.
