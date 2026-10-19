# Card adapter: a style kit also styles the card surfaces

Status: DRAFT for the owner's approval (2026-10-19). Nothing is built. Follows
`2026-10-18-style-kits-design.md` (button pilot merged to local main, 71540da).

## Intent and why

Today a kit changes buttons only. The kits already record `radius.card`, `space.card`, shadow, border and hover, but
cards keep the recipe's hard-coded look, so switching kit does not change the surfaces a page is mostly made of. The
card adapter makes the card look follow the kit, with the same proof level as buttons (contrast over every kit x
language x surface, probe on the real Divi, registry lint, golden file).

## What the audit found (this changes the scope)

The brief said 358 recipe blocks are `divi/group` cards. The audit (a throwaway
script; the plan turns it into the committed `scripts/card-migration-report.php`) says **356 groups, of
which about 20 are cards**:

| Class of `divi/group` | Count | Kit-driven? |
|---|---|---|
| Plain wrapper (no decoration) | 212 | No. Layout only. |
| Wrapper with padding only | 41 | No. |
| Top-rule row (0px frame + `top` border 1-2px: team, FAQ, timeline, price rows) | 26 | No (v1). A divider, not a card. |
| Flat fill cell in a hairline grid (logo wall, stat cells, comparison table: outer box `border` colour, cells `bg`) | 46 | No. The grid trick is structure. |
| Chip / dot / step circle (999px, or a 12px accent chip) | 6 | No. Pills stay baked. |
| Inverted panel (filled with the `text` colour: compare-two-panels#1, pricing-two-plan-highlight#1, bento tile) or accent tile | 4 | No (v1), see "Inverted surfaces". |
| **Card or panel** (`surface`/`bg` fill + frame/radius/shadow) | **~20 in 12 recipes** | **Yes** |

And the library's other cards are **not groups**: 12 are `divi/column` blocks with border/radius/shadow/fill
(icon-values 3, proof-press-quotes 3, testimonials-avatar-grid 3, pricing-three-tier 3), 4 are `divi/blurb` with a fill
only (blurb-grid), 1 is `divi/testimonial`. `card-grid-3/4`, `icon-features`, `features-zigzag` and most of the 13
feature recipes have **no card frame at all**. So a group-only adapter restyles about 12 of 71 recipes; group plus
column restyles about 16. Making cards appear in recipes that have none is a design change, not a kit application, and
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

Classification of the ~20 is generated and reviewed in plan task 1; my reading: cards = hero-gradient-stats (3),
features-bento surface tiles (3), hero-bordered-cells inner cells (3); panels = hero-split-left-overlap,
hero-spec-card, hero-bordered-cells outer, compare-two-panels#0, pricing-two-plan-highlight#0, cta-split-form,
cta-card-over-image, contact-location-form, contact-details-form, transition-overlap-panel.

### 2. How kit fields map to a card (no shipped kit changes meaning)

No kit file changes. The adapter derives the card from fields that exist:

| Card property | Source | Rule |
|---|---|---|
| Fill | always filled | `surface`; `bg` when the band is `surface`. Never transparent: a card's children carry colours chosen for a surface fill, so `fill: outline/ghost` kits keep a filled card and show their character in the frame. |
| Radius | `radius.card` | `{{aied:kit:radius-card}}` (exists). |
| Padding (`card` only) | `space.card` | `{{aied:kit:space-card}}`, four sides (exists), on `module.decoration.spacing`. |
| Frame width / style | `border.width`, `border.style` | Kit width, **floor 1px** so a quiet kit's card is still separable from its band (recipes already use a 1px `border` hairline for that); style solid/dashed/dotted/double as the kit says. |
| Frame colour | `border.color` | `same` -> `border` role; `accent`/`text`/`border` as given; `inverse` -> `text` (the button's `ink()` rule). |
| Underline kits (`border.sides: bottom`) | `border.sides` | Card gets only a bottom edge (`styles.all.width 0px` + `styles.bottom`). |
| Shadow rest / hover | `shadow` | Same value forms as the button (`soft`, `hard`, `edge`, `glow`; ink by band) on `module.decoration.boxShadow`; hover shadow only on `card`. |
| Hover (`card` only) | `hover` | `lift` -> `translate.y -4px` on hover. `fill-swap` / `invert` -> the frame colour goes to `accent` on hover (a card cannot swap its fill: children would lose contrast). `none` -> nothing. |
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
3. The frame colour, when it carries meaning (`accent`, `text`), is >= 3:1 against the card fill (the button outline's
   bound); the `border` hairline is decorative and exempt, as today. Hover frame colour (`accent`) the same.

Rule changes are made by fixing the role table, never by a frozen exceptions file (lesson from the button table: the
first table had 50 failing cells).

### 4. Size rule

`section-recipes.json` ends **smaller** than 695,877 B (bound 716,800 unchanged). Measured on the current file: the
literal `border` + `boxShadow` + `spacing` objects on the 24 card-like groups total 9,969 B; a marker is about 35 B. Net
saving about 8 KB for groups, about 3-4 KB for the 12 column cards, minus the cost of any literal left bespoke. The
fill colour literal (`{{aied:color:surface}}`, 24 B) stays in the recipe (it is not kit-variable), so the marker owns
only border, radius, padding, shadow and hover. Each phase is checked: file size after <= before; if a phase cannot
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

Each probe PASS goes into the registry and `fixtures/shapes/kit-card-vocabulary.md`; DEAD/css-only is not taught.

### 6. Migration in phases (each ends with its render gate and a size check)

0. **Probes** (one at a time on the dev site) + vocabulary doc. Gate: probe results committed.
1. **Adapter + resolver + tests** (roles `card`, `panel`, surface `surface`, golden, contrast and descendant-walk
   proofs, lint) and **3 recipes**: hero-spec-card (panel), hero-gradient-stats (card), cta-split-form (panel). Gate:
   `make test`; `verify-recipes --names` for those 3 under default + 7 languages; `verify-kits` extended to render a card
   and a panel on the four bands in every kit/language cell (computed border, radius, fill, padding, shadow, hover
   lift/frame, child-image clipping), at 1280/900/400.
2. **Remaining group recipes** (9 more), in chunks of ~18 recipes for renders. Mood tags re-read for each touched
   recipe; `mood-avoids-exemptions.txt` stays shrink-only.
3. **Column cards** (4 recipes) if Decision 1 = yes; blurb/testimonial only on a separate go.
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

## Decisions for you

1. Carriers: group + column (recommended) or group only.
2. Frame floor of 1px for every card (recommended) or let quiet kits' cards go frameless when width is 0.
3. Card fill always `surface`/`bg` (recommended) or allow transparent for outline/ghost kits (needs children ink
   per band, i.e. the inverted-surface work).
4. Hover on `card` only, `panel` static (recommended).
5. Inverted surfaces: separate follow-up (recommended) or in this branch.
