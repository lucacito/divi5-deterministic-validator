# Carriers adapter: the existing kit roles reach four more carriers

Status: DRAFT FOR THE CONTROLLER (delegated decisions are marked "Ruling", each with its cost if wrong, and are listed again in
the Outcome). Base: local main 2f10970 plus the `feat/finish-leftovers` head 7165133 (type and rhythm adapters merged; free
4.13.0 published, 4.14.0 is the html_to_divi styles release). Template: `2026-10-21-rules-chips-adapter-design.md` (probe first,
a proven-decisions constant pinned to a vocabulary table, contrast proofs with zero exceptions, goldens, migration tests with
own ink / band walk / merge trap, render gate codes in `verify-kits`, generated guide wording). NO release, version bump, zip
rebuild or readme edit; the validator (`src/`, `wp-plugin/validator/`) is untouched.

## Intent and why

Every kit role that exists today stops at the carriers it was proven on. The library still has four places where a kit
visibly should decide and does not, because the block is a different module:

1. Two accent dividers (blog-feed, icon-values) were left literal by the rules adapter (Ruling 8 there: "a brand mark, not a
   separator; add a `rule-accent` role later"). Under a dashed or 4px kit they stay a Divi-default solid line.
2. The one `divi/testimonial` is a hand-made panel (surface fill, 10px radius, 1px `border` frame) next to kit cards that change
   radius, frame and shadow with the kit.
3. The four `divi/blurb` icons are hand-made icon chips (accent fill, accent-text glyph, 16px padding, square corners) next to
   kit icon chips that take the control radius.
4. Four `divi/number-counter` blocks draw a hand-made 1px top rule next to kit rules that change style and width.

The roles already exist (`rule`, `rule-strong`, `chip`, `card`, `panel`); only one role is new (`rule-accent`). The work is to
prove each path ON its carrier (the registry is path-only: the forms adapter found `module.decoration` hits the whole form
container, the opposite of `divi/button`), register the carriers, prove the colours, migrate 11 blocks and teach it in the
generated guide.

## What the audit found (script audit, a block parser over `section-recipes.json`, 71 recipes, 652,430 B)

Measured with a throwaway parser (block comments, index = order among blocks of the same name in the recipe):

| Carrier | Blocks | Recipes | Hand-written today | Band | Decision here |
|---|---|---|---|---|---|
| `divi/divider` accent mark | 2 | 2 (blog-feed #0, icon-values #0) | `divider.advanced.line.desktop.value.color` = `accent`, Divi-default weight and style, `modulePreset` `1ep0co506u`, blog-feed also `module.decoration.sizing.alignSelf` | bg (light) | role `rule-accent` |
| `divi/testimonial` | 1 | 1 (testimonial #0) | `module.decoration.background` `surface`; `border.radius` 10px x4 + `sync`; `styles.all` 1px `border`; `overflow` x/y visible; `layout.display` block; author `text`, jobTitle and company `muted`, content `text` | bg (light) | role `panel` (carrier also takes `card`) |
| `divi/blurb` icon | 4 | 1 (blurb-grid #0-#3) | `imageIcon.decoration.background` `accent`; `imageIcon.advanced.color` `accent-text`; `imageIcon.decoration.spacing.padding` 16px x4; `imageIcon.decoration.sizing` iconFontSize 32px, alignment left; no radius (square) | surface | role `chip` |
| `divi/number-counter` top rule | 4 of 8 | 1 of 2 (stats-counter-band #0-#3; stats-counter #0-#3 have no rule) | `module.decoration.border.styles.all` 0px `accent-text` solid + `styles.top.width` 1px; `spacing.padding.top` 20px; `sizing.width` 100% | accent | role `rule-strong` (by colour) |

Total: 11 blocks in 5 recipes (blog-feed, icon-values, testimonial, blurb-grid, stats-counter-band). Today 45 recipes carry a
kit marker; after the migration 49 (icon-values already has chips and cards).

Stays UNADAPTED, by ruling (no kit decision to automate), each with its reason:

| Module | Blocks / recipes | Why it is not adapted |
|---|---|---|
| counter numbers and titles (`divi/number-counter`) | 8 / 2 | font, size and colour are type and language decisions; the type adapter already declined counters, and a kit has no number decision |
| `divi/number-counter` without a rule (stats-counter) | 4 / 1 | nothing a kit owns is drawn |
| `divi/circle-counter` | 4 / 1 (stats-circle-counters) | the ring and track are colour roles of the language; a kit has no ring field |
| `divi/slider` + `divi/slide` | 2 + 6 / 2 (slider, testimonials-slider-quote) | testimonials-slider-quote's slider looks like a panel (surface, 24px, 1px frame) but each slide repeats the surface fill (`enableColor`): two blocks coupled, which one marker per block cannot express; arrows and dot nav sit on the edge and were never probed |
| `divi/countdown-timer` | 1 / 1 (cta-countdown) | digit boxes have no kit field |
| `divi/social-media-follow` | 1 / 1 (newsletter-social) | network brand colours, not kit colours |
| `divi/before-after-image` | 1 / 1 (before-after-compare) | handle and divider are the module's own control; image corners are a shape (the image-radius pass) |
| `divi/blog`, `divi/shop` | 1 + 1 / 2 (blog-feed, shop-grid) | Divi builds one item per post or product; `module.decoration` frames the whole feed, and the per-item paths were never probed |
| `divi/group-carousel` | 2 / 2 (features-carousel-strip, image-carousel) | the carousel is a track; its children are already ordinary groups and icons (features-carousel-strip's icon chips are kit-driven) |
| `divi/signup` | 0 / 0 | DECLINED: no library block to migrate or prove; a carrier with nothing to migrate would add a probe, a golden and a render slot for no shipped page |
| the quote icon of a testimonial (`quoteIcon.*`) | 1 / 1 | the hanging `::before` disc keeps Divi's own colours (#f5f5f5 in Divi's CSS); not a kit decision |

What the repo already knows: `divider.advanced.line.{bp}.value.{color,weight,style}` (rules adapter, probe kr-*),
`imageIcon.decoration.background.{bp}.value.color` (ty-bl-icon-background), `imageIcon.advanced.color.{bp}.value`
(ty-bl-icon-color), `imageIcon.decoration.spacing` padding (ty-bl-icon-padding) and every `module.decoration.*` path (from
other modules). NOT in the registry at all: `imageIcon.decoration.border.*` (Divi's `BlurbModule.php` applies it to
`.et_pb_main_blurb_image .et-pb-icon` and `.et_pb_only_image_mode_wrap`, the same element the icon fill paints). NOT proven ON the
carrier: any `module.decoration` path on `divi/testimonial` or `divi/number-counter`, a line weight or style over the divider
preset `1ep0co506u`, a radius on the blurb icon. The probe decides each.

Contrast facts measured for this spec (`RuleChipAudit::ratio()`, the accent colour drawn on each band, lowest of 10 languages):

| Band | accent vs band | language | verdict for `rule-accent` |
|---|---|---|---|
| light (bg) | 2.304 | playful-pastel | allowed (floor 2.3) |
| surface | 2.448 | playful-pastel | allowed |
| accent | 1.000 | every language | refused |
| dark (photo) | 1.401 | corporate-trust | refused |
| panel (text) | 1.103 | tech-mono | refused |

No band reaches 3:1 in all ten languages (playful-pastel's accent is a pastel; brutalist-flat is 2.81 on bg), so a structural
3:1 rule in the accent colour is impossible on every band. See Ruling 1.

## Goals, non-goals, anti-goals

Goals:
1. A new role `rule-accent` on `divi/divider`, bands `light` and `surface`: the accent colour, the strong rule width
   (`KitAdapters::ruleWidth('rule-strong', ...)`), the kit's style (solid where the divider's style decision is unproven).
2. Roles `card` and `panel` on `divi/testimonial` (the card patch, no ground); `chip` on `divi/blurb` (the icon chip: control
   radius, fill, glyph; the recipe keeps the padding and the size); `rule` and `rule-strong` on `divi/number-counter` (the top
   edge, as on a group).
3. Every path written is probe-proven ON its carrier, pinned in one constant `KitAdapters::CARRIER_PROVEN` to the "Adapter
   decisions" table of `fixtures/shapes/kit-carrier-vocabulary.md`.
4. All 11 blocks migrate (marker plus dropping the literal the kit now writes); `BESPOKE_CARRIERS` ends EMPTY (cap 0);
   `RuleChipAudit::SHAPE_EXEMPTIONS` loses the six entries this adapter takes (2 accent dividers, 4 counters).
5. Proof level of the earlier adapters: contrast cells with zero exceptions and no baseline file, a golden, distinct looks, the
   dead-on-module guard, a render gate in a real browser.
6. Guide wording generated from `KitAdapters::APPLIED` and `CARRIER_PROVEN`; no new topic name; the default guide does not grow.

Non-goals: everything in the "stays unadapted" table; a `rule-accent` on a group, a column or a counter (no library precedent);
any hover on a chip or a rule; the testimonial's quote icon, portrait or inner text colours; a ground on a testimonial; the blurb
title or body type; changing the counter's padding; the divider preset itself (the migration keeps `modulePreset`).

Anti-goals: a new MCP tool, REST route, OpenAPI operation or guide topic name; custom CSS or a hex in a recipe; a weakened test;
a baseline file for a contrast proof; a change to any of the eight frozen goldens; a new remote call in `wp-plugin/`.

## Design

### 1. Roles, carriers, bands

| Role | New carrier(s) | Bands | Patch |
|---|---|---|---|
| `rule-accent` (new) | `divi/divider` only | `light`, `surface` | `divider.advanced.line.desktop.value.{color,weight,style}`: colour `{{aied:color:accent}}`, weight `ruleWidth('rule-strong', ...)`, style the kit's (solid where `divider-<style>` is false) |
| `card`, `panel` | `divi/testimonial` | `light`, `surface`, `accent`, `dark` | `card()`'s patch (fill, frame, radius, padding for `card`, shadow, hover), each key only where its `testimonial-*` decision is PASS; NO ground |
| `chip` | `divi/blurb` | `light`, `surface`, `accent`, `dark` | `imageIcon.decoration.border.desktop.value.radius` = `{{aied:kit:radius-control}}`, `imageIcon.decoration.background.desktop.value.color` = `accent` (`surface` on an accent band), `imageIcon.advanced.color.desktop.value` = `accent-text` (`text` on an accent band); the icon chip's colour table, at the blurb's own paths |
| `rule`, `rule-strong` | `divi/number-counter` | `light`, `surface`, `accent`, `dark`, `panel` | the group top edge: `module.decoration.border.desktop.value.styles.{all,top}`, `all.width` 0px, `top` at the rule width; `RULE_COLOUR` as today |

Registration points (all three must agree; a test pins it): `KitAdapters::APPLIED` (block => roles), `KitResolver::ROLE_BLOCKS`
(role => blocks) and `KitResolver::ROLE_SURFACES` (`rule-accent` => `light`, `surface`). A ground is refused on a testimonial in
BOTH places (`GROUND_BLOCKS = [ 'divi/group', 'divi/column' ]`): today the ground check is by role only, so adding a card carrier
would otherwise admit `ground` on it (a spec issue found while reading `KitAdapters::patch()` and `KitResolver::marker()`).

### 2. Kit fields to attributes (no kit file change)

- `rule-accent`: `KitAdapters::ACCENT_RULE_COLOUR = 'accent'`, `ACCENT_RULE_BANDS = [ 'light', 'surface' ]`. `RULE_COLOUR` is pinned
  literally by `KitRuleAdapterTest` and is NOT changed. Width: `ruleWidth()` already gives the strong width for any role that is
  not `rule` (0px -> 2px, 1px -> 2px, 2px -> 3px, 3px/4px -> 4px, double at least 3px). The six distinct looks (2px dashed, 2px
  solid, 3px dotted, 3px solid, 4px double, 4px solid; 1 / 22 / 1 / 8 / 1 / 7 of the 40 kits) are pinned.
- Testimonial: the card table unchanged (fill `surface`, `bg` on a surface band; frame kit width with a 1px floor, `CARD_FRAME`
  colours; radius `{{aied:kit:radius-card}}`; `card` padding `{{aied:kit:space-card}}`; shadow; `card` hover lift / frame). With
  every `testimonial-*` decision PASS the testimonial patch is byte-identical to the group patch (tested), so the 3,200-cell card
  contrast proof covers it. The recipe keeps `overflow` (visible, so the hanging quote is not clipped) and `layout`.
- Blurb chip: the icon chip's table reused (`chipPatch()` gains a `divi/blurb` branch writing at `imageIcon.*`); the blurb's 16px
  padding and 32px glyph stay the recipe's (a chip's size is the recipe's, rules-chips table); on an accent band both fill and
  glyph must be proven or the chip is refused (the icon rule).
- Counter rule: `rulePatch()` learns the carrier prefix `counter-top` (`RULE_CARRIERS`), reading the `counter-top*` decisions from
  `CARRIER_PROVEN`; with all PASS the counter patch equals the group patch (tested), so the 4,000-cell rule proof covers it.

### 3. Proofs (pure PHP, zero exceptions, no baseline file)

- `rule-accent`: 40 kits x 10 languages x 2 bands = 800 cells, accent vs the band at least `ACCENT_RULE_FLOOR = 2.3`, the
  library's shipped minimum (2.304, playful-pastel, blog-feed and icon-values on bg; already recorded in
  `fixtures/shapes/rule-floor.md` row `accent | light`), rounded down to one decimal, frozen, never lowered (the rules adapter's
  Ruling 5 method). A second test proves the band list is maximal: each refused band is below the floor in at least one
  language (accent 1.000, dark 1.401, panel 1.103).
- Testimonial: identity with the group patch for 40 kits x 2 roles x 4 bands, plus an own-attribute ink walk (the card ink
  walk's known limit is that it never reads the marked block's own attributes): author, jobTitle, company and content colours
  of the migrated block on both fills (`surface`, `bg`) at 4.5:1 in all 10 languages (4 inks x 2 fills x 10 = 80 cells).
- Blurb chip: 40 kits x 10 languages x 4 bands = 1,600 cells, glyph on fill at 3:1 (non-text graphic, the icon chip rule) plus
  identity of the colour tokens with the icon chip's.
- Counter rule: identity with the group patch for 40 kits x 2 roles x 5 bands; the band walk of the migration test proves the
  four markers name the accent band they are drawn on.
- Golden: a NEW file `tests/golden/kit-carrier-patches.json` (10 default kits x 24 patches = 240), frozen in
  `KitGoldenFreezeTest` as a ninth entry; the eight frozen goldens stay byte-identical (their tests enumerate literal carriers,
  verified by reading `KitRuleGoldenTest`, `KitChipGoldenTest`, `KitCardGoldenTest`). See Ruling 6.
- Distinct looks instead of pair lists: blurb chip 8 looks (the 8 distinct control radii: 0, 2, 4, 6, 8, 10, 12, 999px), accent
  rule 6 looks; counter and testimonial inherit their twins' lists by identity.
- Dead-on-module guard over every resolved new-carrier block; a DEAD path found by the probe gets a line for its carrier.

### 4. Probe (`fixtures/shapes/candidates/kit-carrier.json`, ids `kx-<carrier>-<decision>`; no other probe uses `kx-`)

Measure Divi's default first and make every control differ from it (`.et_pb_testimonial` has `padding: 30px` and, in Divi's CSS,
a `#f5f5f5` quote disc; `.et-pb-icon` has `border: 0 solid #333`; a counter has no border). About 48 candidates, all rendered at
1280/900/400: testimonial 22 (fill, frame 1/2/4px, three styles, frame colour, underline, radius 16px and pill, padding 48px, four
shadows, lift, hover frame, hover shadow, the quote unclipped with a radius and the recipe's `overflow: visible`, a dead
default-padding control), blurb 9 (control, a dead 0px radius, radius 4/12/999px, radius and fill on the same element, fill,
glyph, 999px with the recipe padding inside its column at 400px), counter 11 (control, a dead `all` 0px, four styles, widths
1-4px, alpha colour), divider over the preset 6 (control, weights 2/3/4px, dashed, with `alignSelf`). The results become
`fixtures/shapes/kit-carrier-vocabulary.md` ending with an "Adapter decisions" table of 20 rows.

### 5. Render gate and recipes

`verify-kits` renders 8 more slots per kit/language cell: a testimonial `card` on bg (with a real hover) and a `panel` on surface;
a blurb chip on surface and on accent; a counter `rule-strong` on accent and `rule` on bg; a `rule-accent` divider on bg and on
surface. Codes `carrier` (a slot missing, twice, or a run that checked none), `testimonial-fill|frame|radius|padding|shadow|hover|
quote`, `blurb-chip-radius|fill|glyph|contrast`, `counter-rule-style|width|colour|contrast`, `accent-rule-style|width|colour|
contrast`, plus `overflow`. Expectations are read from the RESOLVED markup (a 0px kit is a real expectation of zeros); the
accent floor is read from the offline sheet JSON (exported by the PHP), never typed in JavaScript. The full run grows from about
3 h 11 min by an estimated 15 to 25 minutes.

Recipes: the 11 blocks migrate by a deterministic, idempotent script (never by hand); the four blurbs mix `&` (the glyph
code) with raw `<p>`, so neither `json_encode` nor `RecipeTokens::encodeJson` reproduces them: the script re-encodes with plain
`json_encode` and restores `&` only on blocks that spell no raw `&`, asserting a byte round trip of every target before it
edits (measured: all 11 round-trip). The prose "each under a thin rule" (stats-counter-band) breaks the rule prose check and is
reworded to "each under a rule".

### 6. Size and the guide

Measured at 7165133: default guide 28,975 B, `topic` description 258 characters, `kit-rules` 8,997 B (pin 9,000, real bound
10,000), catalog `style-kits` 8,444 B (50-kit projection 9,928 B, bound 9,950), `controls` 8,331 B, largest kit topic neo-brutal
9,599 B, largest topic `layout-advanced` 9,973 B, `section-recipes.json` 652,430 B (bound 716,800), `style-kits.json` 25,479 B.

The applied sentence (`StyleGuide::appliedSentence()`) appears in the catalog and in `kit-rules`, NOT in the default guide or any
kit topic (read in `StyleGuide.php`: lines 195 and 389 are its only callers). Simulated with the new map it grows 445 -> 524 B
(+79; about +49 once `rule-accent` is a "rules" noun), which would push the 50-kit catalog projection to about 9,978 B, over its
9,950 B bound. Rulings 7 and 8 handle it.

### 7. Rulings (made by delegation; each with the cost if wrong)

1. **`rule-accent` is a decorative brand mark, floor 2.3, light and surface bands only.** The controller asked for the bands where
   accent vs band is at least 3:1 in all 10 languages; the measurement says there are NONE (best: light 2.304). A 3:1 rule would
   delete the role. The accent ground already has this precedent ("accent 2.30:1 or more, judged decorative"), and the floor is
   the shipped library's own minimum, frozen. Cost if wrong: if the owner wants 3:1, the role is dropped and the two dividers go
   back to `SHAPE_EXEMPTIONS` (one constant, two markers).
2. **`rule-accent` lives on `divi/divider` only.** Both library instances are dividers; no recipe draws an accent top edge. Cost
   if wrong: add carriers later (additive).
3. **The library testimonial is a `panel`; the carrier also takes `card`.** It is a static quote with Divi's own 30px padding (no
   literal), so a panel keeps it; a freehand testimonial grid wants `card` (kit padding, hover), so the carrier registers both and
   the render gate measures both. Cost if wrong: swap one marker role.
4. **Counters take `rule-strong` (by colour).** The literal is `accent-text` at 1px; the library rule (rules adapter Spec issue 6)
   classifies a full-ink colour as strong and only `border` or an alpha as quiet. Under a 1px kit the line becomes 2px. Cost if
   wrong: four marker roles change to `rule` (the quiet `accent-text:40` line).
5. **The blurb chip keeps the recipe's padding and glyph size.** The rules-chips table says a chip's size is the recipe's; the
   kit gives corners and colours only. Cost if wrong: a later `space-control` token on the blurb padding (one more probe).
6. **A new golden file instead of re-freezing an old one.** The earlier golden tests enumerate literal carriers and roles, so
   their files cannot move; a ninth file keeps all eight hashes byte-identical, which is stronger than a documented re-freeze with
   an old-subset test. Cost if wrong: none (a test shape).
7. **The catalog prints a noun-only applied sentence**, generated from the same `APPLIED_NOUNS`: "Kit-driven today: buttons,
   cards, accordion and toggle items, forms, rules and chips (blocks and marker roles: topic=kit-rules)." `kit-rules` keeps the
   full sentence. Simulated: the two sentences go 445 -> 179 B, so the catalog goes 8,444 -> about 8,178 B and the 50-kit
   projection 9,928 -> about 9,662 B (bound 9,950). Cost if wrong: an AI
   reading only the catalog needs one more topic for the block names (the catalog already points there).
8. **`kit-rules` pin 9,000 -> 9,600 B** (real bound 10,000), for the full applied sentence (+about 49 B) and one generated
   paragraph `## More carriers` (at most 560 B). The existing Rules and chips paragraph and its sub-pins (525 B, 1,900 B) do not
   move. Cost if wrong: 400 B less headroom for the next adapter.
9. **The not-applied sentence agrees in number**: with one noun left it reads "Every other module keeps the recipe's own
   styling." (today's code would print "keep"). Cost if wrong: one character.
10. **`controls` grows only by what its generated sentence says** (the divider's marker list gains `rule-accent`, about +18 B,
   pinned exactly); no new clause there. Cost if wrong: a few bytes.
11. **`RuleMigrationTest`, `ChipMigrationTest` and `LanguageResolverTest`'s legacy-scanner proof are scoped to the carriers and
   roles they were written for**; the 11 new markers are pinned in one new `CarrierMigrationTest`. Cost if wrong: test plumbing.
12. **Not adapted**, by the table above; `divi/signup` declined (0 blocks). Cost if wrong: a later adapter.

### 8. Done means

All suites green (free, Pro untouched at 2169, `node scripts/test-verify-kits.cjs`, `node scripts/test-kit-card-run.cjs`);
`make verify-shapes` OK; the probe agrees with every expectation; the full `make verify-kits` passes every cell (88) with the new
codes; the narrowed recipe render of the 5 migrated recipes under all 10 languages plus seeded guards passes with
`recipe-baseline.txt` still empty; the Outcome records measured numbers; `git diff main -- src wp-plugin/validator
jhmg-ai-editor-for-divi-5.zip` is empty; the eight earlier goldens are byte-identical; the default guide is 28,975 B.

## Left to the builder

Helper and test names beyond those the plan fixes; the exact candidate list where a first run shows a control equals Divi's
default; the wording of the generated paragraph inside its byte budget; whether a STOP condition in the plan (a base decision
DEAD) is resolved by dropping the carrier or by an amended patch, which is the controller's call.

## Outcome

Built on branch `feat/carriers-adapter` (from the `feat/finish-leftovers` head 7165133, which holds the type and rhythm adapters), merged into `feat/finish-leftovers` at 3f2275e. NOT merged to main, NOT released; no version bump, zip rebuild or readme edit; the validator (`src/`, `wp-plugin/validator/`) is untouched. Plan `docs/superpowers/plans/2026-10-24-carriers-adapter.md`. Facts below are measured on 2026-10-24 and 2026-10-25; the live results are in the Outcome's Live gates paragraph.

**Probe** (`node scripts/verify-shapes.cjs fixtures/shapes/candidates/kit-carrier.json`, vocabulary `fixtures/shapes/kit-carrier-vocabulary.md`, evidence `docs/shape-evidence-5.15.1.md#kx-*`): 48 candidates ON their carriers (`divi/testimonial`, `divi/blurb`, `divi/number-counter`, `divi/divider` over the preset `1ep0co506u`) at 1280/900/400, 45 PASS, 1 css-only, 2 DEAD (`kx-counter-dead-all-0px` and `kx-divider-accent-weight-4px`, both default-value checks: the site preset renders 4px), no phone overflow, no control noise. The first run had three expectations that disagreed; they are amended in the vocabulary file, and one candidate was re-run with the `::before` disc checked.
- css-only: `kx-blurb-icon-dead-radius-0px` (0px is the icon's default; a default-value control).
- DEAD: `kx-counter-dead-all-0px` (`styles.all` 0px alone changes nothing: a default-value check, not a dead path). `kx-divider-accent-weight-4px` is also reported dead because the site preset renders the line at 4px already; weights 2px and 3px change it. `dead-on-module.txt` gained nothing.
- `kx-testimonial-dead-padding-30px` expected DEAD and measured a real change (157 -> 155 px; Divi's testimonial default is not 30px on every side): a normal PASS.
- The quote disc (`::before`) is not measured separately: a module radius leaves its computed style unchanged, and it is not clipped while the module keeps `overflow: visible`, which the adapter never overwrites (the merge trap pins it).
- `KitAdapters::CARRIER_PROVEN` (20 keys, all true) is pinned to the "Adapter decisions" table of the vocabulary file. No fallback was taken.

**The 11 blocks** (a deterministic, idempotent script, `scripts/migrate-carrier-markers.php`, with a per-block byte round trip; the four blurbs mix `&` and raw `<p>`, so they are re-encoded with plain `json_encode` and `&` restored): the 2 accent dividers (blog-feed 0, icon-values 0) `rule-accent` on light; the testimonial `panel` on light; the 4 blurb icons (blurb-grid 0-3) `chip` on surface; the 4 counters of stats-counter-band `rule-strong` on accent (their prose "each under a thin rule" became "each under a rule"). 11 blocks in 5 recipes; kit-marker recipes 45 -> 49. `RuleChipAudit::SHAPE_EXEMPTIONS` lost the six entries this adapter took (15 -> 9; step badges, timeline dots and the [Most popular] badge remain). `BESPOKE_CARRIERS` is EMPTY (cap 0). `section-recipes.json` 652,430 -> 651,134 B (bound 716,800).

**Proofs** (pure PHP, zero exceptions, no baseline file, no role-table change needed):
- `rule-accent`: 800 cells (40 kits x 10 languages x light/surface), the line at least `RuleChipAudit::ACCENT_RULE_FLOOR = 2.3` and at least a 2px mark; a second test proves the band list is maximal (accent, dark and panel each have a language below the floor). The floor is the shipped library's minimum 2.304 (accent on light, playful-pastel) rounded down, written once, never lowered.
- Blurb chip glyph: 1,600 cells (40 x 10 x 4 bands), 3:1 on the painted fill; the testimonial's own inks: 80 cells (author, job title, company and content on `surface` and `bg`, 10 languages, 4.5:1); identity of the testimonial and counter patches with the group card and rule patches.
- Distinct looks instead of pair lists: 8 blurb radii, 6 accent rule looks.
- Golden `tests/golden/kit-carrier-patches.json`: 240 patches (10 default kits x 24), sha256 `aabdd90285dd21d3264ef8d7ca0c477881cda856667b7af7ffe32ba325f10682`; `KitGoldenFreezeTest` freezes nine goldens and the eight earlier hashes are unchanged.
- `CarrierAudit` (finds the 11 blocks and counts the unadapted modules), `CarrierMigrationTest` (pinned markers, own ink, band walk, merge trap over 40 kits x 10 languages, served validity, prose, idempotency), `CarrierBudgetTest` (guide and recipe budgets), `KitCarrierAdapterTest`, `KitCarrierGoldenTest`.

**Guide sizes** (before at 7165133 -> now): default guide 28,975 B unchanged; `topic` description 258 characters unchanged; `kit-rules` 8,997 -> 9,598 B, its pin raised 9,000 -> 9,600 deliberately (real bound 10,000, so 2 B of pin headroom and 402 B of real headroom), of which the generated `carrierRules()` "More carriers" paragraph is 553 B; the catalog `style-kits` prints a noun-only applied sentence (`appliedNounsSentence()`) and went 8,444 -> 8,178 B (50-kit projection 9,928 -> about 9,662 B, bound 9,950); `controls` 8,331 -> 8,348 B (+17 B, the divider's marker list gained `rule-accent`); `notAppliedSentence()` agrees in number; largest kit topic neo-brutal 9,599 B unchanged. `docs/token-usage.md` regenerated.

**Render gate**: each `verify-kits` cell renders 8 carrier slots (testimonial `card` on light with a real hover and `panel` on surface, blurb chip on surface and on accent, counter `rule-strong` on accent and `rule` on light, `rule-accent` divider on light and on surface) with the codes `carrier`, `testimonial-fill|frame|radius|padding|shadow|hover|quote|contrast`, `blurb-chip-radius|fill|glyph|contrast`, `counter-rule-style|width|colour|contrast`, `accent-rule-style|width|colour|contrast` and the `*-expectation` codes; the accent floor is exported from PHP to the offline sheet, never typed in JavaScript; a missing slot or a run that checked no carrier fails. Pure tests 114 -> 127; `scripts/lib/kit-carrier-run.cjs` holds the synthetic departures.

**Tests.** Free suite 6,189, Pro suite 2,169 (untouched). `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` is empty.

**Live gates** (Divi 5.15.1, dev site, controller-run): `make verify-shapes` OK (every expectation of `known.json` agrees). Kit smoke raw-frame x brutalist-flat: 3 PASS 0 FAIL, with the `quote` check ("quote disc hangs") passing. Narrowed recipe render of the 6 carrier recipes (blog-feed, blurb-grid, icon-values, stats-counter, stats-counter-band, testimonial) under all 10 languages: 180 PASS 0 KNOWN-FAIL 0 FAIL. Full `make verify-kits` (88 kit/language cells, the first full run with the eight carrier slots per cell): 264 PASS 0 FAIL. Earlier in the same session the type and rhythm render gaps were closed (recipe renders of the 59 type- or rhythm-marked recipes under all 10 languages, 0 FAIL in every job).

**Rulings 1-12 restated** (all held as written in "Rulings"): 1 `rule-accent` is a decorative brand mark, floor 2.3, light and surface only (no band reaches 3:1 in all ten languages); 2 it lives on `divi/divider` only; 3 the library testimonial is a `panel`, the carrier also takes `card`; 4 counters take `rule-strong`; 5 the blurb chip keeps the recipe's padding and glyph size; 6 a new golden file, not a re-freeze; 7 the catalog prints a noun-only applied sentence; 8 `kit-rules` pin 9,000 -> 9,600; 9 the not-applied sentence agrees in number; 10 `controls` grows only by what its generated sentence says; 11 the old migration tests are scoped to their own carriers and the 11 markers are pinned in `CarrierMigrationTest`; 12 the unadapted table stays unadapted and `divi/signup` is declined.

**Declined, with the reason** (no kit decision to automate): `divi/signup` (0 blocks in the library); circle counter, slide and slider, countdown timer, social follow, before-after, blog, shop and group carousel (see the "stays unadapted" table); the counters' numbers and titles (type and language decisions); a per-language frame colour (no concrete decision, and it would change every kit golden).

**Deviations from the plan.** The probe's first run disagreed with three expectations, amended (above); `card()` became public so the planted fallback maps can be tested; the old tests were scoped as Ruling 11 lists (`RuleChipAudit` exemptions 15 -> 9 and its `kindOf()` ignoring carrier markers, `RuleMigrationTest` and `ChipMigrationTest` answering only on their own carriers, `RecipeLibraryTest` 45 -> 49, `LanguageResolverTest` comparing the 44 all-legacy recipes); and the dogfood scorer is not extended to carriers (it scores kit decisions on the older carriers, plus type and rhythm since 401ba70).

**Known limits.** An accent rule is a decorative mark at 2.3:1, not structural. The testimonial's quote disc keeps Divi's own `#f5f5f5`, so under a dark language a light disc sits on a dark card, as before; the gate proves the disc is not clipped, not its colours. The divider preset `1ep0co506u` is a dev-site preset id: on a customer site the accent mark is a full-width line and the kit's weight wins over the preset (probed). The blurb chip's 999px shape depends on the glyph box (a wide glyph gives an ellipse). Counters as `rule-strong` thicken the line from 1px to the strong width (2px for the 22 two-pixel-solid kits). Counters' numbers and titles, circle counters, sliders, countdown, social follow, before-after, blog, shop and carousels stay the recipe's; `divi/signup` is declined.

**Owner decisions left.** Merge and release; whether the slider pair becomes a coupled panel later; image-to-layout later.

**Review notes kept as known limits (opus review, no material defect).** A `chip` marker on a `divi/blurb` whose icon is an image (not `useIcon: on`) is not refused at resolve time; the effect was probed only on `span.et-pb-icon`, and the only blurbs that carry the marker are the four icon blurbs of blurb-grid. `StyleGuide::appliedSentence()` lists `rule-accent` in the same parenthesis as the group, column and counter rules; the resolver leaves and reports such a marker and the "More carriers" paragraph says divider only, so it is misleading wording, not a wrong look (kit-rules has 2 B of headroom under its 9,600 pin, so the sentence was not reshaped). The blurb chip contrast check proves the fill is not the band colour and the glyph is at least 3:1 on its fill, like the existing icon chip.
