# Accordion adapter: a style kit also styles accordion and toggle items

Status: DRAFT for the owner, 2026-10-19 (nothing built). Follows `2026-10-19-card-adapter-design.md` (built on
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
