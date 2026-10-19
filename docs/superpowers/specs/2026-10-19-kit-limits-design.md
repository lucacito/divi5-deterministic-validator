# Kit limits: tilted cards, inverted and accent grounds, a featured plan that stays featured

Status: DRAFT for the owner's approval (2026-10-19). Nothing is built. Follows `2026-10-19-card-adapter-design.md` (its Outcome
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
