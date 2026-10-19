# Style kits: one style, many modules (free plugin, AI-facing side only)

Status: APPROVED by the owner 2026-10-18 (all five decisions below accepted as recommended; next adapter after buttons: card). Follows
`2026-10-17-design-richness-design.md` (Plans 1 and 2 are merged to local main, 71 recipes, 10 design languages).

## Intent and why

A design language today changes **colour and font only**. Radius, border, shadow and padding live in prose
("4px on cards and buttons"), and 124 radius values, 12 shadows and every padding are hard-coded inside the recipes.
So a recipe re-skinned from `editorial` to `brutalist-flat` keeps its corners, its shadows and its spacing: the final
review (M7) and the controller's own screenshots said the same thing, "re-skins, not different designs". It also
forces the mood tags to be strict: 15 recipes fit two languages or fewer, because their baked radius only suits those.

The owner's picture: an outlined button with a 2px dark-red border should have an equivalent accordion, tabs, card,
form field, testimonial and pricing card with the SAME border, radius, shadow and hover. That bundle is a **style kit**.

## Goals, non-goals, anti-goals

Goals
- A kit is a small named set of style decisions (below). One kit can be applied to a button, a card, an accordion item,
  a form field, a blurb and a testimonial, and they look like one family.
- A recipe asks for a kit; the same recipe served under a different kit looks structurally the same but visibly
  different in corners, borders, shadow, hover and text treatment (colour and font still come from the language).
- Each design language names a default kit; the AI can pick another (`style_kit`).
- Every attribute a kit writes is proven on the real Divi (shape registry, probe), like everything else we teach.
- The recipe file does not grow because of kits (see "Size").

Non-goals
- No new MCP tool, REST route or OpenAPI operation (the importer's 30-operation budget is used up). Only optional
  parameters on `get_section_recipes` and new `get_style_guide` topics.
- No custom CSS, JS or PHP, so notched corners, diagonal slashes, double offset frames, split two-tone, glow rings and
  striped textures stay out (about 20 to 30 of the owner's 125 buttons).
- The validator is untouched. Kits are resolved when a recipe or topic is SERVED; a saved page never contains a kit
  marker or token (the existing `unresolved_tokens` advisory covers a leftover).
- Existing pages are never edited.

Anti-goals (a failure even if it "works")
- 125 near-identical button recipes that differ only in colour (colour is the language's job).
- Kit data that quietly invents a Divi setting.
- A kit that makes text unreadable (every kit declares colour pairs, tested AA like recipes).

## Constraints (CLAUDE.md, unchanged)

No Divi setting from memory: every path a kit writes is in `fixtures/shapes/verified-shapes.json` or gets a probe
first (`scripts/verify-shapes.cjs`, `make verify-shapes`). No remote calls in `wp-plugin/`. Deterministic, pure PHP,
no AI. The tracked root zip stays the published 4.12.0 build. Max 3 parallel workers, one probe or render at a time
on the dev Docker site.

## Design

### 1. What a kit is

A kit is DATA (new `wp-plugin/data/style-kits.json`, read once and memoised like the languages): an id, a title, a
one-line summary, `avoids`/mood hints, `pairs` (colour pairs it uses, for the contrast test), and a flat set of
decisions. Proposed vocabulary for v1 (only what is already proven; see "Probe first"):

| Decision | Values |
|---|---|
| `fill` | `solid`, `outline`, `ghost` (text only) |
| `border` | width 0 to 4px, style `solid`, colour role (`accent`, `text`, `border`, `inverse`) |
| `radius` | three steps: `control` (buttons, fields), `card` (groups, items), `media` (images); px or pill (999px) |
| `shadow` | `none`, `soft`, `lifted`; plus the hover shadow |
| `hover` | `fill-swap`, `invert`, `darken`, `lift`, `none` (each maps to proven hover paths) |
| `text` | capitalization, letter spacing, weight |
| `space` | control padding step, card padding step |

Colours never appear in a kit as hex: a kit names a colour ROLE and the language supplies the hex (the existing
`{{aied:color:<role>}}`). So the 2px dark-red outline is `kit=outline-2` plus a language whose accent is dark red.

### 2. Adapters: written once per module, not once per kit

An **adapter** is a template that turns a kit's decisions into the attribute paths of one module:
`divi/button`, `divi/group` (the card: 358 of the recipes' blocks, and pricing cards are groups), `divi/accordion-item`
and `divi/toggle`, `divi/contact-field`, `divi/blurb`, `divi/testimonial`, `divi/image`. Kits are small tables of
decisions, so N kits times M modules does NOT mean N times M stored fragments: the structure lives in M templates,
proven once each; each kit adds about 15 values.

What an adapter cannot express is not a kit feature. Known examples: Tabs cannot be fully themed on dark looks
(Divi paints the bar `#f4f4f4`; no attribute reaches it), so the tabs adapter is limited and the guide says so.

### 3. How a recipe asks for a kit (two small mechanisms)

1. **Whole-value tokens** for a group of related values. Today's colour and font tokens only replace text INSIDE a JSON
   string, so a radius (four corners plus `sync`) cannot be one token yet. The kit resolver adds a second kind:
   when a whole attribute value is exactly one kit token (`"radius":"{{aied:kit:radius-card}}"`), it is replaced by
   the full object (`{"topLeft":"12px",...}`). That is about 36 bytes instead of about 90, and the same for
   padding sets. `RecipeTokens::resolve()` gains a kit input; `found()`/`advise()` already detect other token kinds.
   Only elements whose roundness should FOLLOW the language get a token. Pills (`999px` on badges, avatars),
   flush edges (`0px` on full-bleed images and sections) and deliberate partial corners stay baked; a phase-2
   audit classifies the 124 radius objects (17 distinct values) and lists what is tokenised and what is not.
2. **A module marker** where a kit changes structure (outline vs solid, shadow object, hover set): the recipe's block
   comment carries `"aied":{"kit":"control"}` (roles: `control`, `control-secondary`, `card`, `field`, `item`, ...).
   At serve time the resolver parses the block's attribute JSON, deep-merges the adapter's output (the kit wins on the
   paths it names, the recipe keeps text, links, content and anything else), removes the marker and re-encodes with the
   same escaping the tokens use. Only blocks that carry a marker are re-encoded; every other block stays byte-identical.
   A merge cannot delete a key, and an omitted key falls back to the site's module preset (recipes carry
   `modulePreset`), so an adapter writes EVERY path it controls explicitly (an outline button gets a transparent
   background, not an absent one); the migrated recipe simply omits those paths. Pure, deterministic, unit-tested; a marker the code does not know is left in and
   reported, never guessed.

Why not tokens only: an outline button and a solid button differ in which attributes exist, not only in values.

### 4. Size: kits must not duplicate markup (Ruling 22)

`section-recipes.json` is 702,311 bytes, 98% of the 700 KB bound. Kits shrink it instead of growing it:
- A migrated button loses its literal background, border and hover blocks (about 250 bytes each, measured) and gains
  a 25-byte marker: 28 buttons in 22 recipes save roughly 6 KB. The same for fields, items and cards as their
  adapters land.
- Each tokenised radius (a 90-byte four-corner object) becomes a whole-value token of about 36 bytes.
- The headroom today is about 14 KB (702,311 of 716,800 bytes). The pilot alone cannot be expected to free much, so
  the rule is: **no phase ends with the file larger than it started**, and the full card adapter (358 groups) is
  where the real saving is. If a phase cannot meet that, it stops and comes back to you; the bound is not raised
  silently.
- The bound stays at 700 KB and is NOT raised; the new file `style-kits.json` gets its own bound (proposal:
  at most 120 KB, at most 1.5 KB per kit) and a test that no kit stores markup, only decisions. If a later phase
  needs more recipes, the saving from migration pays for them first.

### 5. Languages and kits

Each language gains `"kit": "<id>"` (its default). The prose fields `radius` and `shadow` stay as guidance for the
AI but a test checks the prose does not contradict the kit's numbers (generated, not hand-typed, where possible).
The mood tags stay hand-edited tags (layout-driven reasons remain); as each adapter lands, the migration step
re-reads the moods of the recipes it touched and removes a language only excluded for its baked radius. The existing
moods-vs-avoids test and the shrink-only exemption list stay as the guard. Success is measured, not promised: the
number of recipes that fit at most two languages (15 today) is reported before and after each phase.
The shrink-only `mood-avoids-exemptions.txt` stays shrink-only.

### 6. Delivery to the AI

- `get_section_recipes` takes an optional `style_kit` next to `design_language` (default: the language's kit).
  `style_kit` without a language uses today's default language (`editorial`); an unknown kit is refused with the
  list of valid kit ids, like an unknown mood today. The same contract for `get_style_guide` topic `style-kit:<id>`.
- `get_style_guide` topics: `style-kits` (compact catalog, one line per kit) and `style-kit:<id>` (the resolved
  attribute snippets for each adapted module, so the AI can build a button or card in that kit WITHOUT a recipe).
  Each topic at most 10,000 bytes like the others; the default guide names them.
- Token cost is measured with `scripts/measure-tokens.php` and added to `docs/token-usage.md`.

### 7. Proof (how we know a kit is real)

- **Shape registry:** the lint that already covers guides, topics and recipes also lints every path an adapter can emit
  (a path not in the registry fails the test).
- **Probe first** for anything not yet proven; this decides what v1 vocabulary contains. Known candidates, from the
  owner's sheet: dashed and dotted borders, partial (one-side) borders on buttons, gradient fills, skew, hard
  (zero-blur) offset shadow, per-state hover on the card. A candidate that is DEAD or css-only is not taught.
- **Render gate:** `make verify-kits` renders one "kit sheet" page per kit (every adapted module, side by side) on the
  real Divi at 1280/900/400 and fails on PHP errors, overflow, text below the contrast bound, or a module that does
  not show the kit's border/radius/shadow in the computed style (the probe's measure, not a screenshot opinion).
  `make verify-recipes` then runs recipes under kit x language samples.
- **Any kit with any language.** Kits are not tied to a language, so contrast is a pure-function unit test over every
  kit x language pair (about 30 x 10; no render needed): text pairs AA 4.5, and a border or outline that carries
  meaning (an outlined button's border against its background) at 3:1. A kit may list `avoids` hints, which the guide
  shows, but they do not block.
- **Raw versus resolved markup.** Recipes in the file now hold tokens and markers. Tests that need real Divi markup
  (validator re-validation, `DesignVariety`, `ColorPairs`, the registry lint) run on the RESOLVED markup of each
  language x kit sample, which is what the AI receives; tests that inspect stored markup (token and marker
  hygiene, size bounds, blob guard) read the raw file. The marker is never expected to pass the validator.
- **Unit tests:** adapter output per kit is validated by the real validator; marker merge is deterministic; a kit never
  writes a colour literal; contrast pairs per kit in all 10 languages.
- **No-regression:** the default language with its default kit is compared with today's output; every difference is
  listed for review (it will not be byte-identical, because radius now follows the language: that is the point).

## Phasing

1. **Spec approved** (this document).
2. **Foundation:** `style-kits.json` schema and loader, scalar kit tokens, module marker resolver, tests, token
   budget. One adapter only (button), two kits, to prove the mechanism end to end.
3. **Probes** for the sheet's uncertain buttons (about 20), batched, on the dev site.
4. **Buttons pilot:** the button adapter and the kits that come out of the 125-button sheet (below), the `buttons`
   topic and button migration in the 22 recipes that have one.
5. **Next adapters, in this order of payoff:** card (`divi/group`), accordion/toggle, form fields, blurb,
   testimonial; then retire the remaining baked radius and spacing.
6. Later, unchanged: reproduce a layout from a screenshot or Figma export (needs this library).

## The button sheet in this picture

The sheet has 125 buttons; the plan was "60 to 80 buildable". Many of those differ only in colour, which is the
language's job, so they collapse into fewer **kits** (my estimate, unverified: 25 to 35 distinct decision sets).
What the owner gets is a button catalog: each kit shown as a named button look, and the same look available on
cards, fields and accordions. Designs are original variants inspired by the sheet, not copies of someone's CSS. The
classification (buildable now / needs a probe / needs custom CSS and skipped) is the first deliverable of phase 3.

## Decisions for you

1. **Kit count vs button count.** Is it right that ~70 buildable sheet buttons become roughly 25 to 35 named kits
   (a button look = a kit, colour from the language), rather than ~70 separate button styles? My recommendation: yes.
2. **Marker approach.** Recipes carry a stripped-at-serve marker plus scalar tokens (section 3). The alternative is
   tokens only, which cannot express outline-versus-solid. My recommendation: markers plus tokens.
3. **Default kit per language, AI may override.** Recommended; the alternative (kit chosen only by the AI) loses
   consistency when the AI does not choose.
4. **Order after buttons.** I would do the card (`divi/group`) next, because most recipe surfaces are groups, so it
   changes the look of far more of the library than accordion or forms. Say if you prefer accordion first.
5. **Existing recipes' baked radius.** Migrate the radii that should follow the language (17 distinct values exist; pills and flush edges stay baked) to kit tokens (recipes fit more languages, some
   looks shift) versus only where a module adapter lands. Recommended: migrate as each adapter lands, with the
   difference list reviewed each time.

## Left to the builder

Exact kit ids and names; the kit decision values (within the proven vocabulary); the marker spelling; the file
schema; the byte bounds beyond those proposed; how the kit sheet is laid out; the order of probe batches.

## Outcome (2026-10-19)

Built on branch `feat/style-kits` (nothing released, pushed or version-bumped; the tracked zip is still the published
4.12.0 build; the validator copies are untouched).

**Built against the phasing.** Phase 2 (foundation): `data/style-kits.json` with its strict schema and loader
(`StyleKits`), whole-value kit tokens, the stripped module marker and its resolver (`KitResolver`), the alpha colour
token, tests. Phase 3 (probes): 31 button candidates plus 5 later ones, rendered on the real Divi 5.15.1
(`fixtures/shapes/kit-button-vocabulary.md`). Phase 4 (buttons pilot): the `divi/button` adapter (`KitAdapters`), 26 of
the 28 recipe buttons migrated to markers, the guide topics `style-kits` and `style-kit:<id>`, the optional `style_kit`
parameter of `get_section_recipes` (MCP, REST, OpenAPI; no new tool, route or operation), 40 kits, and `make
verify-kits` (88 kit/language cells, 264 PASS at three widths). The classification of the owner's 125-button sheet is
in `docs/button-sheet-classification.md`.

**Deviations from the spec.**
- Padding, shadow and lift are written on `module.decoration.*`, not on the button: the probe showed the button's own
  spacing, box shadow and transform are DEAD on `divi/button`. The plan's "the adapter never touches `module`" was the
  plan's rule, not the spec's; a dead-on-module guard now stops a path proven on one module from being written on another.
- The role table (which colour a button takes on a light surface, an accent band or a photo) was amended after the
  contrast proof over every kit x language x role x surface x state found 58 failing cells; the proof now passes with
  zero exceptions and no baseline file.
- Whole-value tokens and the marker are as specced; the marker is `{"aied":{"kit":"control|control-secondary","on":...}}`.
- Gradient fill was NOT built: no pair of the allowed colour roles gives a readable label on both stops in all ten
  languages (best case about 2.45:1).
- Two shadow values were added to the specced soft/hard pair, `edge` and `glow`, and `border.sides: bottom` (an underline)
  and the lift hover, all after probes.
- 40 kits, not the estimated 25 to 35: the 19 sheet kits were added on top of the 21 language and extra kits. The owner's
  cap of 50 holds; the `style-kits` topic stays under 10,000 bytes at 50 kits (tested), and the near-duplicate rule
  (a visible-difference test with a shrink-only exemption list of 4 pairs) kept the sheet from adding look-alikes.

**Deferred.** The card adapter (`divi/group`) is next, then accordion/toggle, blurb and form fields; card and media
radius and card padding are recorded in the kits but not applied; image-to-layout later. Known limits: every outline
kit's secondary button is a solid block (the role table); the underline kit's edge is faint against the accent fill
(about 2.4:1); the soft shadow is faint on dark languages because its colour is the language overlay; a shadow is not
written for languages whose prose says none. Owner decisions left: merge and release, and the `docs/inspitarion`
folder name.
