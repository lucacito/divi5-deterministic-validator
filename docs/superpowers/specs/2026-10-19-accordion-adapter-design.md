# Accordion adapter: a style kit also styles accordion and toggle items

Status: APPROVED (owner 2026-10-19, the recommended decisions; the image-radius token pass stays a separate small branch);
built on branch `feat/accordion-adapter`, see the Outcome at the end. Follows `2026-10-19-card-adapter-design.md` (built on
`feat/card-adapter`, see its Outcome) and `2026-10-18-style-kits-design.md`. The owner's order after cards:
accordion/toggle, then forms, blurb, testimonial.

## Intent and why

A kit now changes buttons and cards; an FAQ on the same page keeps the recipe's baked corners, frame and fills, so a
soft-saas page with 20px kit cards sits next to a 16px-framed accordion, and an editorial page's square cards next to the
same 16px items. The adapter makes accordion and toggle items follow the kit with the card's proof level. The second
payoff is outside the recipes: each `style-kit:<id>` topic will carry a resolved toggle, so an FAQ the AI builds without a
recipe matches the kit too.

## What the audit found (reach is small; read this before approving)

Audit by script over `wp-plugin/data/section-recipes.json` (683,182 B; a block parser, not a grep). Disclosure modules in
the library: **3 recipes, 17 blocks**. No recipe uses `divi/tabs` (features-tabs was dropped: the tab bar cannot be themed,
`fixtures/shapes/not-taught-recipe-features.json`).

| Recipe (moods) | Blocks | Where the decoration sits | Look |
|---|---|---|---|
| faq-accordion (soft-saas, glass-dark only) | 1 `divi/accordion` + 6 items | parent: 1px `border` frame, radius 16px, open/closed fill `surface`, closed icon `text`; items carry content only (one has `open`) | boxed items |
| features-accordion-image (6 moods) | 1 `divi/accordion` + 4 items | parent: `all` 0px + `bottom` 1px `border` rule, radius 0, fills `bg`, icon `text` | rule rows |
| faq-toggle-list (5 moods) | 5 `divi/toggle` | each toggle: 0px + bottom 1px rule, radius 0, padding 28/0 (phone 20/0), fills `bg`, open title colour, both icons `text` (~940 B of decoration each) | rule rows |

No block has a shadow or a hover. All three sit on a `bg` band. Titles and bodies use `text` / `muted` (the AA pairs the
recipes declare). So a kit marker would sit on **7 blocks** (2 accordion parents, 5 toggles) and restyle 15 rendered items
in 3 recipes, of which only **1 recipe (faq-accordion) is boxed**; the other 2 are rule lists, which the card spec ruled
"a divider, not a card" (its 26 top-rule groups stayed baked).

Compared with the other candidates (same script): forms = 4 `divi/contact-form` in 4 recipes (13 fields, each form also
has a submit button that is NOT kit-driven today, a visible mismatch next to kit buttons); image radius = 46 images in 19
recipes but only ~11 follow a kit (16px x6, 4px x3, 24px, 3vw; the 10 flush 0px, the pills and the 2 mixed 32px/999px stay),
in 5 recipes, and needs no adapter (the whole-value token `{{aied:kit:radius-media}}` already exists in
`StyleKits::VALUE_TOKENS`); blurb 4 blocks in 1 recipe; testimonial 1 in 1. **No module restyles far more of the library
than accordions**: forms reach 4 recipes against 3, media radius 5. The ranking by payoff per effort is media radius (cheap),
then forms (visible submit-button fix), then accordion (the owner's pick; also the FAQ-without-a-recipe case). See Decision 1.

## What Divi 5.15.1 allows (module.json + module classes read from `divi/Divi.zip`, version 5.15.1)

- `divi/accordion` (parent): `module` border selector is `{{selector}}.et_pb_accordion .et_pb_accordion_item` (and
  `AccordionModule.php:328` adds overflow-for-radius on each item), so **a parent border and radius frame every item**;
  `boxShadow` targets `{{selector}} .et_pb_toggle` (each item); **`spacing` padding targets `.et_pb_toggle_content`, the
  answer, not the item** (Divi's own `.et_pb_toggle_open/close{padding:20px}` stays). Groups: `title` (font),
  `closedToggleIcon` (icon), `content` (bodyFont), `openToggle` and `closedToggle` (background + font). **No open icon**
  (Divi hides it on an open accordion item). Default printed: `rowGap 10px` between items, 1px frame.
- `divi/accordion-item`: its own `module` background, border, boxShadow, spacing (on `.et_pb_toggle`, important), same
  state groups. Recipes keep items bare; the adapter does not write on items (see section 1).
- `divi/toggle`: `module` border, boxShadow, spacing on the toggle itself; `openToggleIcon` and `closedToggleIcon`;
  `title` and `closedTitle` (font); `openToggle` (background + font), `closedToggle` (background only); `content`.
- Divi's defaults the adapter must override explicitly (merge trap: an omitted key falls back to them): closed fill
  `#f4f4f4`, open `#fff`, frame `1px #d9d9d9`, closed title `#666`, open `#333`, icon `#ccc` (`style-static.min.css`).

Already proven ON these modules (registry + `docs/shape-evidence-5.15.1.md`): open/closed fill and closed icon on the
accordion (`rf-acc-*`); open/closed fill, open title (`openToggle` font), `closedTitle`, both icons, body colour on the toggle
(`rt-toggle-*`). NOT proven on them: every `module.decoration.border|boxShadow|spacing` path (registered from groups, and the
registry is path-only), the accordion's `openToggle`/`closedToggle` font colours.

## Goals, non-goals, anti-goals

Goals: a kit changes the corners, frame and shadow of accordion and toggle items the way it changes cards; every title,
body and icon stays readable in every kit x language x band x state; the recipes file ends smaller.
Non-goals: tabs (the bar and separators are painted `#f4f4f4`/`#d9d9d9` by Divi's stylesheet and only a custom CSS field
reaches them: no adapter, the guide keeps saying so); an open icon on an accordion (Divi has none); hover on items; turning
rule lists into boxes; per-item markers; the validator; any new tool, route or operation.
Anti-goals: an open item whose title keeps a colour chosen for another fill; a hover that recolours every item of an
accordion at once (the parent's hover would, see Decision 4).

## Design

### 1. Marker, roles, bands

`"aied":{"kit":"item|item-rule","on":"light|surface|accent|dark"}` on a `divi/accordion` (one marker styles all its items,
as the recipes already do) or a `divi/toggle`. `KitAdapters::APPLIED` gains both blocks with both roles.

- `item`: a boxed item (faq-accordion; any FAQ the AI builds). Bands light|surface|accent|dark, as cards: the fill is always
  `surface` (`bg` on a surface band), so the inside never depends on the band.
- `item-rule`: a rule row (features-accordion-image, faq-toggle-list). Bands light|surface only: the fill IS the band
  (`bg` / `surface`), and no `muted` body pair is proven on accent or a photo.

### 2. Kit fields to item attributes (no kit file changes)

| Property | `item` | `item-rule` |
|---|---|---|
| Fill, open and closed | `surface` (`bg` on surface band), both states written | band colour, both states written |
| Frame | kit width (floor 1px), kit style, colour by `CARD_FRAME` (same -> `border`, accent/inverse -> `text`); underline kits: bottom edge only | `all` 0px + `bottom` rule: kit width (floor 1px), kit style, colour by `CARD_FRAME` |
| Radius | `{{aied:kit:radius-card}}` | always 0 (written) |
| Padding | toggle: `{{aied:kit:space-card}}`; accordion: none (parent padding reaches the answer, not the item; Divi's 20px stays) | recipe keeps its own (structural, like a panel) |
| Shadow | kit rest shadow (per item on an accordion, if probed so) | none (written) |
| Hover | none (Decision 4) | none |
| Titles, body, icons | written by the adapter, because they live on the same block as the fill: closed and open title `text` (toggle: `closedTitle` + `openToggle` font; accordion: `closedToggle` + `openToggle` font), body `muted`, icons `text` (accordion: closed only) | same |

The recipe keeps title and body family, size, weight, line height, heading level, content and `open`. Text decisions
(capitalization, letter spacing) stay button-only. Golden: `tests/golden/kit-item-patches.json`; button and card goldens
stay byte-identical.

### 3. Contrast proof (pure PHP, zero exceptions, no baseline file)

For every kit x language x role x band x state (closed, open): closed title on closed fill and open title on open fill AA
4.5, body on the open fill AA 4.5, icon on its fill 3:1, a meaningful frame (`text`) 3:1 on the fill and, for `item` on a
band, the frame against the band is not judged (as cards). Cells: `item` 40 x 10 x 4 x 2 = 3,200, `item-rule` 40 x 10 x 2 x
2 = 1,600. Failures change the role table, never an exceptions file. Because the adapter writes the item's own ink, the card
ink walk's blind spot ("the marked block's own attributes are not walked") does not apply; a new test fails any migrated
item that still carries a colour, border, shadow or background of its own, and any `divi/accordion-item` under a marked
accordion that carries decoration (an item-level value would beat the parent's).

### 4. Probe list (`fixtures/shapes/candidates/kit-item.json`, ids `ki-<decision>-<carrier>`)

On BOTH `divi/accordion` (measured on its items) and `divi/toggle`, expect PASS unless noted: frame width 1/2/4px, style
solid/dashed/dotted/double, frame colour, underline rest form, radius (and whether it clips the open answer), shadow
soft/hard/edge/glow, open and closed fill under a frame, open and closed title colour (the accordion's `openToggle` /
`closedToggle` font are new). Toggle only: `module` padding. Accordion only: `module` padding expected to pad the ANSWER
(recorded as the effect, never written), and the 10px row gap under a frame. That is **about 36 candidates** (17 decisions x
2 carriers plus 2). A DEAD path goes to `fixtures/shapes/dead-on-module.txt`; results to `kit-item-vocabulary.md`.

### 5. Render gate and recipes

`verify-kits`: every cell adds an accordion (`item`, one open and two closed) and two toggles (`item-rule`, one open) on
the light and surface bands, plus an `item` accordion on accent and dark; expectations read from the resolved markup
(computed border, radius, fills per state, title/body/icon colours, shadow, contrast as painted). Interaction: a real click
on a closed item opens it (wait for Divi's slide), its fill and title colour switch to the open values, and the previously
open accordion item closes. `verify-recipes --names` for the 3 recipes under default + 7 languages x 3 widths (72 renders).

### 6. Migration (merge trap applies)

A marker's patch deep-merges and recipe keys it does not write survive, so a migrated block drops its own `module.border`,
`boxShadow`, `openToggle`/`closedToggle` background and font colour, `closedTitle` colour, the title's and body's `color`,
both icon colours (and, for `item`, its padding). `RecipeLibraryTest` gains: band walk (the marker's `on` matches the
section band), merge-trap test (above), `BESPOKE_ITEMS` shrink-only with max 0 (all 7 blocks migrate), size check. Recipe
prose that names the old look ("rounded", "bordered") is reworded under the card phrase test. Moods: faq-accordion fits
only soft-saas and glass-dark because of its baked 16px radius; after migration the moods are re-read and widened only
where a language's `avoids` does not exclude the layout (the count of recipes fitting at most two languages is reported
before and after).

### 7. Size

Recipes: the 7 blocks drop about 3 to 4 KB of literal decoration for ~45 B markers (toggle padding stays on the rule rows);
the file must end below 683,182 B (bound 716,800). Guide: the default guide is 28,964 B against a 29,108 B ceiling (144 B
headroom), the largest kit topic 8,124 B against 10,000, the catalog 9,919 B at 50 kits. A resolved toggle in every kit topic
(~1 KB) would push the largest near or over the bound. Plan: the constant prose every kit topic repeats (the ~750 B
"Cards and panels" explanation, plus the new item rules) moves into ONE new generated topic `kit-rules` (what each adapted
module takes from a kit, built from `KitAdapters::APPLIED` and the adapter constants); each kit topic keeps only its own
values and resolved snippets plus a one-line pointer. `kit-rules` is named from the catalog's existing pointer sentence and
the kit topics, NOT from the default guide (0 B there; if a test requires every topic to be named in it, ~30 B fit). All
topics stay under 10,000 B, tested at 50 kits.

### 8. Guide wording (generated)

The catalog line "Accordions, forms, blurbs, testimonials, image radius and every other module keep the recipe's own
styling" is built from `APPLIED` (`StyleGuide::appliedSentence()` gains the nouns `item`/`item-rule` -> "accordion and
toggle items"), so it can only claim what exists. The `toggles` topic gains one generated sentence pointing to the kit
topics; its tabs limit stays.

### 9. Done means

Probes PASS (or DEAD recorded and not written); contrast 4,800 cells x 4 checks, zero exceptions; goldens; registry lint and
dead-on-module guard cover both carriers; `verify-kits` full run PASS with the click; the 3 recipes re-rendered; recipes
file smaller; topics under bound; free and Pro suites green; validator copies and root zip unchanged vs main.

## Phased plan, order and cost

0. Probes (~36 candidates, one run like the 42-candidate card run) + vocabulary doc. 1 task.
1. Adapter, resolver roles, golden, contrast and own-ink tests. 2 tasks.
2. Guide: `kit-rules` topic, slimmer kit topics with a resolved toggle, generated wording, size tests. 1 task.
3. `verify-kits` sheet + click (pure tests first, then an 18-cell smoke, then the full run: today ~70 min, estimate
   ~85 min with the new modules and clicks). 1 task.
4. Migrate the 3 recipes (one chunk), mood re-read, `verify-recipes --names` (~10 min). 1 task.
5. Docs, token measurement, whole-branch review. 1 task.

Honest cost: **7 tasks, about two thirds of the card build**, about 2 hours of live dev-site time (probe run, smoke, full
`verify-kits`, recipe renders), plus review fixes. For 3 recipes this is the most expensive adapter per recipe so far; the
cost is mostly the proof machinery, which forms will reuse.

## Decisions for you

1. **Next adapter.** Recommended: accordion as you ordered (the FAQ-without-recipe case and faq-accordion's two-mood limit
   justify it), with the cheap media-radius token pass (11 images, 5 recipes, no adapter) as a separate small branch
   first or right after. Alternative: forms first (4 recipes, fixes the submit button mismatch).
2. **Rule rows.** Recommended: role `item-rule` (kit rule width/style/colour, state colours tokenised; 6 of 7 markers).
   Alternative: leave the 2 rule recipes bespoke; then the adapter reaches 1 recipe.
3. **Open-state emphasis.** Recommended: none in v1 (open and closed share fill and `text` title; Divi's icon and the
   answer show the state). An accent open fill or accent icon fails 3:1 or has no proven body pair in some languages.
4. **Hover.** Recommended: none on items in v1 (an accordion's hover is the parent's, so it would recolour every item at
   once; not probed). Alternative: a hover frame on toggles only (2 more probes).
5. **Guide sizes.** Recommended: the new `kit-rules` topic (section 7). Alternative: no resolved toggle in kit topics (the
   AI copies from the `toggles` topic and the recipes).

## Left to the builder

Exact marker spelling within section 1; probe ids; golden layout; how the sheet lays out items; whether the item rules are
read from the patch or from constants (never hand-typed per kit); the `kit-rules` topic name.

## Outcome (2026-10-19)

Built on branch `feat/accordion-adapter` (spec and plan commit 7766e85 from main ae0688d, rebased onto and later merged with
`feat/kit-limits`) in seven tasks; Task 2 and Task 4 were delivered as one (Ruling 1b below). NO release, version bump or
zip rebuild: the tracked root zip is still the published 4.12.0 build; the validator (`src/`, `wp-plugin/validator/`) is
untouched. Decisions 1 to 5 went the recommended way.

**What was built, per task.**
- **T1 Probes.** 46 candidates (`fixtures/shapes/candidates/kit-item.json`, ids `ki-<decision>-<carrier>`; the spec expected
  about 36) rendered on the real Divi 5.15.1 in a real browser at 1280/900/400: 46 PASS on BOTH `divi/accordion` and
  `divi/toggle`, 0 DEAD, 0 css-only, no phone overflow; promotion added 1 registry path (the accordion's closed title colour,
  `closedToggle.decoration.font.font.{bp}.value.color`), `dead-on-module.txt` gained nothing. Findings
  (`fixtures/shapes/kit-item-vocabulary.md`): an accordion parent's padding pads the ANSWER, not the item (recorded, never
  written); an accordion has no open icon (Divi hides it); a radius prints `overflow: hidden` on each item and clips nothing at
  Divi's default insets (pixel check: identical red-ink counts with 16px, 36px and 999px, 1179 on the accordion, 657 on the
  toggle); the 10px row gap stays under any frame; a shadow written on the accordion lands on EACH item; the title groups
  differ by module (accordion: `closedToggle` + `openToggle` font; toggle: `closedTitle` + `openToggle` font).
- **T2 + T4 Adapter, resolver and guide (one delivery, Ruling 1b).** `KitAdapters::item()` with the roles `item` (a boxed
  item: fill `surface`, `bg` on a surface band; the card's frame, radius and rest shadow; `space-card` padding on a toggle only)
  and `item-rule` (a rule row: the band itself as fill, an underline rule, square corners, no shadow, the recipe keeps its
  padding) on both modules, bands light|surface|accent|dark for `item` and light|surface for `item-rule`; both states of every
  colour are written explicitly (Divi's defaults are not transparent); the resolver roles; the item golden
  `tests/golden/kit-item-patches.json`; after the review the card and the item share `frameStyles()`, `restShadow()` and
  `cardFrameRole()`. The guide: a generated topic `kit-rules` (3,525 B) now holds the prose every kit topic used to repeat, the
  kit topics carry a resolved toggle and one pointer line, `appliedSentence()` gets the noun "accordion and toggle items" and
  `notAppliedSentence()` names only what no adapter covers, the `toggles` topic points to the kit topics. Sizes (commands in
  Measured below): largest kit topic (neo-brutal) 9,992 -> 9,599 B, catalog 8,464 -> 8,423 B (9,948 -> 9,907 B at 50 kits),
  default guide 28,964 -> 28,975 B (+11 B: a test requires the topic line to name every topic), topic parameter description
  298 of 300 characters (the next topic name overflows it).
- **T3 Proofs.** The contrast proof: `item` 3,200 + `item-rule` 1,600 cells (40 kits x 10 languages x bands x states), each
  judging both modules (9,600 judgements), ZERO exceptions, no role-table change and no baseline file; planted failures for
  every check. A visible-difference rule for items: 12 of 780 kit pairs give the same item, listed in
  `fixtures/shapes/item-near-duplicates.txt` (9 card pairs plus hairline-mono|flat-square, hairline-mono|block-caps,
  soft-rounded|soft-lift; shrink-only, cap 12). `tests/ItemMigrationTest.php`: the marked block's own ink, the accordion-item
  decoration, the band walk (`item-rule` on light or surface only) and the merge trap over every kit x language.
- **T5 Render gate.** `scripts/verify-kits.cjs`, `scripts/lib/kit-item-run.cjs`, `scripts/lib/kit-checks.cjs`: every kit/language
  cell renders 10 item blocks (on the bg and surface bands an `item` accordion, a boxed `item` toggle and an open and a closed
  `item-rule` toggle; on the accent and photo bands an `item` accordion) and measures them with the codes `items`,
  `item-border`, `item-radius`, `item-padding`, `item-shadow`, `item-fill`, `item-title`, `item-body`, `item-icon`,
  `item-contrast` and `item-click` (a real pointer click). The boxed item toggle is an addition beyond the brief, so that
  `item-padding` runs live. 70 pure tests (`scripts/test-verify-kits.cjs`, 18 new) and synthetic Chromium departures for every
  new code. The card-adapter carry-ins (compareCard ground, pointer parked at the right edge, stale header) were folded in.
- **T6 Recipes.** 7 markers: faq-accordion (1 accordion, `item`), features-accordion-image (1 accordion) and faq-toggle-list
  (5 toggles), both `item-rule`, all on `light`. `BESPOKE_ITEMS` is EMPTY (cap 0). `section-recipes.json` 681,274 -> 675,810 B
  (-5,464). faq-accordion's moods widened from 2 to 6 languages (only brutalist-flat avoids its layout); recipes fitting at
  most two languages 15 -> 14, no exemption added. `SectionRecipes::styleKitHelp()` now says "buttons, cards, accordions and
  toggles".
- **T7 Docs.** This Outcome, CLAUDE.md, `docs/token-usage.md`, the vocabulary final pass and the `KitAdapters` item-table
  docblock (comments only).

**Measured sizes and counts** (commands run in the worktree at the end of Task 7):
- `wc -c wp-plugin/data/section-recipes.json`: **675,810 B** (681,274 B at the kit-limits merge, 683,182 B at the branch point; bound 716,800 B and the
  700 KB rule hold). `style-kits.json`: 25,479 B (unchanged).
- `php scripts/measure-tokens.php` (`docs/token-usage.md`): default guide 28,975 B, catalog topic `style-kits` 8,423 B, topic
  `kit-rules` 3,525 B, topic `toggles` 5,602 -> 5,834 B (+232 B, the generated kit sentence), kit topics 8,025 B (rimmed-solid)
  to 9,599 B (neo-brutal), tool definitions 21,621 -> 21,641 B (+20 B, as measured).
- Suites: free **2614** tests (`php vendor/bin/phpunit -c phpunit.xml`; 2574 at the end of kit limits), Pro **2169**
  (`-c pro-addon/phpunit.xml`), both OK. `node scripts/test-verify-kits.cjs`: 70 tests OK.
- `fixtures/shapes/item-near-duplicates.txt`: 12 pairs. Item golden: 132 patches.

**Deviations from the spec.**
- Ruling 1b: Task 2 and Task 4 were one delivery, because the two new `APPLIED` entries lengthen `appliedSentence()` by 63 B
  and three kit topics (neo-brutal 10,055, offset-block-caps 10,046, pressable 10,031 B) and the 50-kit catalog (10,011 B)
  would have crossed 10,000 B; the adapter could not be green without the `kit-rules` topic.
- The default guide grew by 11 B (the spec planned 0 B): a test requires the topic line to name every topic, so it gains
  ", kit-rules", and the parameter description's tail was shortened to stay under 300 characters.
- 46 probe candidates, not about 36.
- The golden holds 132 patches, not 120: 10 default kits x 12 plus a deliberate extra kit, `underline-link` (not a language
  default), so the underline form of an item is pinned.
- `item-rule` writes no shadow value (no `none` form of `boxShadow` was probed), so the migration drops the recipe's own
  shadows instead of overriding them.
- A boxed `item` toggle was added to the render sheet beyond the brief, so that `item-padding` is measured live (an accordion
  item keeps Divi's padding and nothing is judged there).
- The shared `frameStyles()` / `restShadow()` helpers came from the review (the first pass copied the card's logic).
- The accordion `title` group colour of the old recipes is not written by the adapter; the migration dropped it instead.

**What changed on purpose (read as design changes).** faq-accordion now follows the kit: framed boxes with the kit's radius,
frame, shadow and answer colour in every language, no longer the baked 16px/soft-saas look; the rule lists (features-accordion-
image, faq-toggle-list) take the kit's rule width, style and colour instead of a fixed 1px solid rule; no open item is
emphasised.

**Known limits.**
- v1 has no open-state emphasis and no hover on items (Decisions 3 and 4): open and closed share fill, title and icon colour.
- Tabs stay unthemeable (the bar and separators are painted by Divi's stylesheet; the guide keeps saying so).
- An accordion's padding reaches only the answer, so an `item` accordion keeps Divi's 20px item padding (a toggle takes the
  kit's `space-card`).
- The render gate's click check proves the class flip, the answer's visibility, the single-open rule and the open values at
  rest and after the click, but NOT a colour switch: open and closed colours are equal in v1, so live data cannot show a state
  that fails to switch (only the synthetic Chromium pages prove that comparison).
- The boxed toggle snippet in each kit topic is validated and registry-checked but not rendered by the guide (the render gate
  covers the same patch on the sheet).
- faq-toggle-list's wrapping group keeps its own baked 2px top rule (it is unmarked).
- The clip claim rests on Divi's default 20px insets; it holds for `space-card` of 20px or more (every kit is 24px or more).
- A shadow on an accordion falls on each item with Divi's 10px gap, so a hard or edge shadow can sit close to the next item.
- The kit-topic headroom is now about 400 B (9,599 of 10,000) and the `topic` parameter description has 2 characters left: a
  new topic name or a new generated sentence needs the next size decision.
- The recipe-prose test's bare `\bhairline\b` phrase ban (`ItemMigrationTest`) may over-fire on prose that legitimately says it.
- Deferred minors: the item fill table exists three times in the tests (`KitItemAdapterTest`, `ItemMigrationTest::BANDS`);
  `kit-checks.cjs` is about 1,250 lines; the per-item checks are partly duplicated across the render phases.

**Live gates (Divi 5.15.1, 2026-10-19, head adcf7e8, one run at a time on the dev site).** (1) Smoke: 3 kits x editorial, glass-dark passed 18 of 18, with a real click changing the state of 10 of 10 item blocks per cell (11 min 45 s for 6 cells). (2) The three recipes (faq-accordion, features-accordion-image, faq-toggle-list) under 8 looks: 72 PASS, 0 KNOWN-FAIL, 0 FAIL. (3) The full `make verify-kits`: 264 PASS, 0 FAIL over 88 kit/language cells, in about 2 h 50 min (17:08 to 20:00). (4) The narrowed recipe render (the 32 recipes that carry a kit marker plus 10 unmarked guard recipes picked with a fixed seed, in two chunks of 21): 252 PASS (126 + 126), 0 KNOWN-FAIL, 0 FAIL, 0 low-contrast warnings; the full default `verify-recipes` was not run, by the narrowing the owner approved at the kit-limits branch. (5) `make verify-shapes`: the probe agrees with every expectation (2 of 2, `probe-results-known.json` unchanged). The checkout stayed on adcf7e8 for the whole chain.

**Owner decisions left.** Merge and release; forms next (4 recipes, and it fixes the contact-form submit button that does not
follow the kit); the image-radius token pass (a separate small branch: 11 images in 5 recipes, no adapter); blurb and
testimonial after that; image-to-layout later.
