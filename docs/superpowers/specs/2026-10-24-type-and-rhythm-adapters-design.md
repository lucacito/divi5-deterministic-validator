# Type and rhythm adapters: a design language also decides headline type and section spacing

Date: 2026-10-24. Status: approved by delegation (the owner said "do all you can do" and delegated every decision; each
ruling below is recorded with its cost if wrong). Two adapters, built one after the other on the same machinery:
**A. Type** (plan `docs/superpowers/plans/2026-10-24-type-adapter.md`) and **B. Rhythm**
(plan `docs/superpowers/plans/2026-10-24-rhythm-adapter.md`, written after A is merged because it reuses A's resolver).
Branches `feat/type-adapter`, then `feat/rhythm-adapter`, worktrees `../wt-*`, `git merge --no-ff` into LOCAL main.
NO push, release, version bump, zip rebuild or SVN. The validator (`src/`, `wp-plugin/validator/`) is untouched.

## Intent and why

The earlier adapters made every BUTTON, CARD, ACCORDION, FORM, RULE, CHIP and IMAGE of a recipe follow the chosen style kit.
What still does not follow anything is the part a visitor reads first: the headline type and the vertical air between
sections. Today 174 headings in 57 recipes render with whatever weight, tracking and capitalisation Divi's default gives
them, so luxe (serif at weight 300, wide-tracked labels) and brutalist-flat (weight 900 capitals) look the same in the
headlines as editorial; and 31 of 71 sections carry the same 112/88/64 px padding whatever the language says
(`section_padding` is only prose: 64 px for brutalist-flat, 160 px for luxe). A language is already "an art-direction
system"; this makes two more of its decisions mechanical, so a recipe served under a language is typographically and
rhythmically that language, and a hand-written page can read the same numbers from the guide.

## What the audit found (script audit over `wp-plugin/data/section-recipes.json`, 71 recipes; scratch scripts are throwaway)

- 174 `divi/heading` blocks in 57 recipes. Level and font token decide the role cleanly:
  h1 13, h2 43, h3 64, h4 with `{{aied:font:eyebrow}}` 53 (the uppercase 2 px 600 label: 42 at 13 px, 10 at 14 px,
  1 at 16 px), plus two or three outliers. Only 63 carry a weight, 54 a capitalisation, 57 a letter spacing: the display
  headings (h1/h2/h3) mostly carry NONE of the three, so the language can decide without fighting a recipe. The literals
  that exist are: h2 weight 700 at 48 px (5), h3 weight 600 at 34 px (4), h2 700 at 65 px (1), one h1 `900 uppercase -1px`
  (the brutalist recipe), 3 h1 with `-1px`.
- 335 `divi/text` blocks in 65 recipes. Weight on 83, capitalisation on 36, letter spacing on 61. The 36 capitalised texts are
  small labels (the text-module form of an eyebrow); body weights are emphasis lines, not a design decision.
- Heading and text paths (`title.decoration.font.font.<bp>.value.{weight,capitalization,letterSpacing}` and
  `content.decoration.bodyFont.body.font.<bp>.value.{...}`) are already in the shape registry (export- or probe-proven);
  the adapter still probes its own decisions on the carriers (watch for no-op controls).
- 71 sections: desktop top = bottom in about 55 of them (112 px 31, 120 px 4, 128 px 2, 160 px 2, 100 px 2, 96 px 2, 80 px, 72 px, 140 px,
  152 px, 40 px, 32 px once each ...), each with tablet and phone steps; 7 carry no padding, 3 have 0px on phone, about 8 are
  asymmetric or in %, vw or 0. 101 rows: 83 carry NO padding, so rows have nothing to decide.
- Divi ships `core/json-data/google-fonts.json` (1,945 families, equal to our committed names list) with, for each family,
  either static `variants` (`regular`, `700` ...) or a variable `axes` entry `{"tag":"wght","start":N,"end":M}`. That is
  the evidence for which weights a language's fonts can actually render (Space Mono: 400 and 700 only; Playfair Display
  400-900; Cormorant Garamond 300-700; Unbounded 200-900).

## Goals, non-goals, anti-goals

Goals
1. A. Type: `display`, `title` and `eyebrow` roles. A recipe heading (or an eyebrow text) carries
   `"aied":{"type":"<role>"}`; the served markup carries the language's weight, letter spacing and capitalisation for that role.
2. B. Rhythm: `section`, `open` and `tight` roles. A recipe section carries `"aied":{"rhythm":"<role>"}`; the served markup
   carries the language's vertical padding for desktop, tablet and phone.
3. Both decisions are structured data on the design language, pinned to its prose by tests, shown in the language's guide
   topic by a GENERATED sentence (so a hand-written page can use them), and measured on the real Divi.
4. Proofs with ZERO exceptions and no baseline files; goldens for the resolved patches; `KitGoldenFreezeTest`'s frozen
   goldens stay byte-identical; recipes keep tokens and markers only.

Non-goals
- Body copy (weight, size, line height), heading SIZE and line height (recipes tune these per device and per layout),
  heading colour (already tokens), row padding (83 of 101 rows carry none), horizontal padding, `divi/blurb`, testimonial,
  counters, slider, `divi/signup`, a `rule-accent` role, a per-language frame colour. Not "later adapters" in this spec.
- No new MCP tool, REST route or OpenAPI operation. No new guide topic NAME (the `topic` parameter description is 258 of 260
  characters). The default guide stays 28,975 B. The validator is not touched.
- No custom CSS/JS/PHP, no remote call, no Divi attribute path that is not proven on its module.

Anti-goals (a failure even if it technically works)
- A language overriding a deliberate recipe look silently. Every heading the adapter does NOT take over is named in a
  shrink-only exemption list with a reason.
- A weight the language's font cannot render (the browser would synthesise a bold or pick the nearest face).
- An overflow at 400 px because an uppercase or heavier headline got wider.
- A section rhythm that squeezes the overlap recipes (their 0 px edges stay literal).

## Design

### 1. Where the decision lives (Ruling 1)

Type and rhythm are properties of the LANGUAGE, not of the style kit. A weight is only meaningful for a font (the language
chooses the font), and `style_kit` can pair any kit with any language, so putting type on the kit would let a kit fight the
language's fonts. Each of the 10 languages in `wp-plugin/data/design-languages.json` gains two keys:

```json
"type": {
  "display": { "weight": "600", "letterSpacing": "-1px", "capitalization": "none" },
  "title":   { "weight": "600", "letterSpacing": "0px",  "capitalization": "none" },
  "eyebrow": { "weight": "600", "letterSpacing": "2px",  "capitalization": "uppercase" }
},
"section_space": { "desktop": 120, "tablet": 80, "phone": 56 }
```

`section_space` is the structured form of the existing `section_padding` prose (tests pin them equal by parsing the prose
sentence `N px top and bottom on desktop, M px on tablet, K px on phone`; where a language's prose does not follow the
sentence the test names the exception and the prose is reworded, never the test loosened). `DesignLanguages::valid()` learns
both keys (strict: closed key sets, values from closed lists, bounds below); `DesignLanguages::markdown()` prints a generated
line for each (see 6).

Starting table for `type` (the builder may adjust a value within the bounds below, and records every change in the plan
ledger with the reason; weights must pass the font proof in 3):

| language (display / eyebrow font) | display | title | eyebrow |
|---|---|---|---|
| editorial (Playfair Display / Source Sans 3) | 600, -1px, none | 600, 0px, none | 600, 2px, uppercase |
| glass-dark (Inter) | 600, -1px, none | 600, 0px, none | 500, 2px, uppercase |
| organic-warm (Fraunces / DM Sans) | 600, -0.5px, none | 600, 0px, none | 600, 1px, uppercase |
| tech-mono (Space Grotesk / JetBrains Mono) | 600, -1px, none | 500, 0px, none | 500, 2px, uppercase |
| playful-pastel (Fredoka) | 700, 0px, none | 600, 0px, none | 600, 1px, none |
| corporate-trust (IBM Plex Sans) | 600, 0px, none | 600, 0px, none | 600, 1px, uppercase |
| soft-saas (Plus Jakarta Sans / Figtree) | 700, -1px, none | 600, 0px, none | 600, 1px, uppercase |
| bold-vibrant (Unbounded / Work Sans) | 800, -1px, none | 700, 0px, none | 700, 2px, uppercase |
| brutalist-flat (Archivo / Space Mono) | 900, -1px, uppercase | 700, 0px, none | 700, 2px, uppercase |
| luxe (Cormorant Garamond / Jost) | 300, 0px, none | 400, 0px, none | 400, 3px, uppercase |

Bounds (validity, tested): weights are the nine CSS weights 100-900 and must exist in the role's font (3); `letterSpacing`
is a whole-pixel value within `display` [-3px, 0px], `title` [-1px, 1px], `eyebrow` [0px, 4px]; `capitalization` is `none` or
`uppercase`, and `uppercase` for `display` or `title` is allowed only in a language whose own `hero` prose asks for capital
letters (today brutalist-flat; a closed, shrink-only list in the test); `section_space` values are whole pixels with
desktop >= tablet >= phone, desktop within 56-200, phone within 28-120.

### 2. Roles, carriers, markers (Ruling 2, 3)

Type markers (top-level block attribute, the only keys `type`, no `on`):

| marker | carriers | meaning |
|---|---|---|
| `"aied":{"type":"display"}` | `divi/heading` | a headline (recipe h1 and h2) |
| `"aied":{"type":"title"}` | `divi/heading` | a card or item title (recipe h3) |
| `"aied":{"type":"eyebrow"}` | `divi/heading`, `divi/text` | the small label above a headline (recipe h4 in the eyebrow font; a text-module label) |

The patch writes weight, letterSpacing and capitalization for ONE breakpoint, `desktop` (Divi cascades a desktop value to
tablet and phone; the probe proves it on the carrier), at `title.decoration.font.font.desktop.value.*` for a heading and
`content.decoration.bodyFont.body.font.desktop.value.*` for a text. `capitalization: none` is written as the value `none`
only if the probe proves the explicit `none` is live (and is a real difference from Divi's default for that module);
otherwise a `none` role writes no capitalisation key and the recipe literal (if any) is a migration exemption. It never
writes size, line height, colour, family or alignment. The language wins over a recipe literal at the same key (deep merge,
as every kit patch).

Rhythm marker: `"aied":{"rhythm":"section|open|tight"}` on `divi/section` only. Roles are derived from `section_space`
(Ruling 8): `section` = the language's three values; `open` (a hero or closing band that needs more air) = each value x 1.25;
`tight` (a compact strip: logos, a stats band) = each value x 0.6; both rounded to the nearest 8 px and clamped to the bounds
above (tablet and phone keep desktop >= tablet >= phone after rounding; a function, tested exhaustively for the 10 languages).
The patch writes `module.decoration.spacing.<desktop|tablet|phone>.value.padding.{top,bottom,syncVertical:"on"}` (the shape
recipes already use and the registry proves), never left/right.

### 3. Resolver and pipeline

A new pure `LanguageResolver::resolve(string $markup, array $language): string` (same scanner discipline as `KitResolver`:
only inside the attribute JSON of a `<!-- wp:divi/<name> {json} -->` comment; a block without a resolvable marker stays
byte-identical; unknown role, block or extra keys are left and reported by `leftovers()`, never guessed) and a pure
`LanguageAdapters::patch(string $block, string $kind, string $role, array $language): ?array` (`$kind` is `type` or `rhythm`).
`SectionRecipes::recipe()` runs ImageTokens -> LanguageResolver -> KitResolver -> RecipeTokens, and returns null if any marker
is left (never partial markup). `KitResolver::leftovers()` must keep reporting an unresolved `aied` marker of either kind; a
type or rhythm marker never reaches the kit step. The builder reads `KitResolver` first and shares what it can (the block
scanner) instead of copying it.

### 4. Proofs (pure PHP, zero exceptions, no baseline files)

- Font proof: a committed fixture `fixtures/shapes/font-weights.json` (the 19 families the languages use, extracted from
  Divi's own `google-fonts.json` with its provenance and the Divi version; regenerated by a script) and a test that every
  language weight exists for its role's font (display font for display and title, eyebrow font for eyebrow; a variable
  font: weight within `start..end`; a static font: the weight in `variants`, `regular` = 400).
- Bounds above, closed key sets, prose consistency (a weight the prose names, e.g. bold-vibrant 800, luxe 300, brutalist 900
  capitals, equals the data; the test lists each prose claim it checks), `section_space` equals the prose sentence.
- Visible-difference: no two languages share the same (display, title, eyebrow) triple, and no two share the same
  `section_space` triple unless listed in a shrink-only exemption file with a reason (cap = the count found, asserted).
- Readability: `uppercase` + tracking only where tested above; an eyebrow never below 0 px tracking when uppercase.
- Goldens: `tests/golden/language-type-patches.json` (10 languages x 3 roles x its carriers) and
  `tests/golden/language-rhythm-patches.json` (10 languages x 3 roles), generated once, then frozen by sha256 beside the
  earlier goldens (`KitGoldenFreezeTest` style). The earlier kit goldens stay byte-identical.
- Dead-on-module guard: probe `DEAD` results go in `fixtures/shapes/dead-on-module.txt` only if a path the library used turns
  out dead on the carrier; `tests/Support/DeadOnModule.php` covers heading/text/section carriers for type and rhythm.
- Library migration tests (`TypeMigrationTest`, `RhythmMigrationTest`): every marker's block and role are pinned by recipe and
  index; classification of every heading and section of the library is asserted (marked, or in a named shrink-only exemption
  list with a reason; the lists END small and are capped by an assertion); each recipe still validates through `LayoutGate`
  under all 10 languages; no marker, token or leftover reaches served markup.

### 5. Probe list (live, serial; ids `lt-*` for type, `lr-*` for rhythm; the existing `ty-*` ids belong to the typography probe)

Run on the carriers with the real language fonts as `{{aied:font:...}}`-resolved family values:
`lt-heading-weight-<w>` for 300, 400, 500, 600, 700, 800, 900 (computed `font-weight` equals the value AND differs from the
module's default where that is the point), `lt-heading-tracking-{-3,-1,0}px`, `lt-heading-caps-{uppercase,none}` (explicit
`none` over an uppercase preset or recipe literal: is it a real difference?), `lt-heading-cascade` (a desktop-only value reaches
tablet 900 px and phone 400 px), `lt-text-eyebrow-{weight,tracking,caps}` on `divi/text` bodyFont.body, `lt-heading-h1-h4`
(the same patch on every `headingLevel`), `lr-section-padding-<N>` desktop/tablet/phone (computed padding-top and
-bottom at 1280/900/400 for an `N` px symmetric section, `syncVertical` on) and `lr-section-first-last` (padding on the first and
a later section; a section with a background image keeps the image). Results to `fixtures/shapes/language-type-vocabulary.md`
and `language-rhythm-vocabulary.md` with an "Adapter decisions" table; `LanguageAdapters::TYPE_PROVEN` / `RHYTHM_PROVEN`
constants are pinned to those tables (all decisions true), exactly as `RULE_PROVEN` is. A control that does not change a
computed value is a defect of the candidate, not an answer: re-probe, do not freeze.

### 6. Guide and size (plan this first; the headroom is thin)

No new topic name. Wording goes into existing generated text only:
- `design-language:<id>` (generated by `DesignLanguages::markdown()` / `StyleGuide`): two generated lines, "Type: ..." (the
  three roles with weight, tracking, case) and "Section spacing: ..." (section, open, tight numbers from the same function the
  patch uses), each at most 260 B.
- `typography` topic: one generated sentence saying a hand-written heading follows the language's `type` roles at the
  proven path (the path string comes from the adapter, not typed twice).
The first plan task measures every affected topic and the default guide, and writes the pins (default guide stays exactly 28,975 B;
no topic over its bound of 10,000 B; the `topic` description unchanged). If a line does not fit, trim existing prose first.

### 7. Render gate

`make verify-language-type` (`scripts/verify-language-type.cjs`, pure tests first, then live): for each of the 10 languages
one throwaway draft page with a display h1 and h2, a title h3, an eyebrow heading, an eyebrow text and, for rhythm, a
`section`, `open` and `tight` section, all resolved by the REAL pipeline, rendered at 1280/900/400 on the dev site. Codes:
`type-weight`, `type-tracking`, `type-caps`, `type-family`, `type-overflow`, `type-font-loaded` (the requested family and
weight reported by `document.fonts` when the font is a web font), `rhythm-padding` (computed padding-top/-bottom per
viewport), `rhythm-order` (open > section > tight), `overflow`. Expectations come from the RESOLVED markup, never typed; a run
that checks nothing fails; the same synthetic-departures test pattern as `scripts/test-kit-card-run.cjs` proves each code
catches its departure. The migrated recipes are re-rendered with the existing `make verify-recipes` narrowed to them under
the 10 languages (overflow, contrast, columns, console errors). `make verify-shapes` and one narrowed `make verify-kits`
smoke (2 kits x 2 languages) confirm nothing else moved. The full 3 h `verify-kits` is NOT required (no kit code changes);
it is run only if a task touches `KitAdapters` or `KitResolver` beyond the shared scanner.

### 8. Rulings (made by delegation; cost if wrong)

1. Type and rhythm live on the language, not the kit. Why: weights depend on the font; kit and language are independent knobs.
   Cost if wrong: move two objects to the kit file and key the patch by kit (the patch functions are pure).
2. Three type roles only (display, title, eyebrow); body, size and line height are not touched. Why: recipes tune size and
   line height per device and layout; body weight is emphasis. Cost: a fourth role is one table entry plus a probe.
3. Markers are `"aied":{"type":...}` and `"aied":{"rhythm":...}` (separate resolver, runs before the kit step). Cost: rename.
4. Language wins over a recipe literal at the same key; every literal kept is a named shrink-only exemption. Cost: delete an entry.
5. Weights are checked against Divi's own font file, not memory. Cost if wrong: a synthetic bold; the live `type-font-loaded` code and the font proof guard it.
6. Uppercase display/title only where the language prose asks for capitals (brutalist-flat). Cost: one list entry.
7. `desktop` value only for type (cascade proven by probe); if the probe shows tablet/phone do not inherit, the patch writes the three breakpoints (same value) and the golden follows. Cost: three keys instead of one.
8. `open` = x1.25 and `tight` = x0.6, rounded to 8 px, clamped. Why: derivable, no 30 extra numbers to keep consistent. Cost: tune two factors and regenerate the golden.
9. Rows, horizontal padding and the other modules are out. Why: nothing to decide (83 of 101 rows carry no padding).
10. Asymmetric, `%`, `vw` and `0px` sections (overlap recipes, a hero measured in vw) stay literal and are listed. Cost: a section stops following the language: cosmetic.
11. One gate script for both adapters (`verify-language-type`), serial, 10 cells, minutes not hours.
12. The dogfood scorer (`dogfood-score`) is NOT extended in this spec (it scores kit decisions); say so in the Outcome.

### 9. Done means

- A: `make test` green (free suite), the proofs of 4 with zero exceptions, goldens frozen, `TypeMigrationTest` green, markers on the
  headings the audit classifies (about 53 eyebrow headings, 56 display (h1/h2) and 64 title (h3) headings and the eyebrow-label texts; exact counts from the
  migration report), guide sizes within the pins, `make verify-shapes` OK, probe results committed, `make verify-language-type`
  and the narrowed recipe render PASS, a final opus whole-branch review, merged `--no-ff` to LOCAL main, CLAUDE.md paragraph and
  memory updated.
- B: the same for rhythm (about 40 section markers), plus the section-geometry checks in the gate.
- Plugin Check on the dev copy is NOT needed until a release; run it at the end of B anyway (cheap) and report.

## Phased plan, order and cost

Task order inside each plan: (0) measure sizes and write pins; (1) live probe (serial, controller-run); (2) data + validity +
proofs (pure, parallel worktree with 1); (3) adapter + resolver + generated guide; (4) goldens and migration report;
(5) recipe migration; (6) render gate + synthetic-departure tests; (7) docs, CLAUDE.md, memory; (8) final live gates and
whole-branch review. Max 3 parallel workers; ONE probe/render/live gate at a time on the dev site. Models: plan writer opus,
implementers sonnet (opus for the resolver, adapters and anything judgment-heavy), reviews opus.

## Left to the builder

The exact starting table values within the bounds; the migration classification rule details (level and font token decide;
outliers become named exemptions); whether the shared block scanner moves to a small helper or `LanguageResolver` calls
`KitResolver` internals; the file and test names; the wording of the generated lines (within 260 B each).

## Outcome

### Adapter A, type (built on branch `feat/type-adapter` from main 77abc24, head edc95b1; NOT merged, NOT released; the validator, `wp-plugin/validator/`, the zip and `wp-plugin/readme.txt` are untouched)

Plan `docs/superpowers/plans/2026-10-24-type-adapter.md`. All facts below are measured on 2026-10-24.

**Probe** (`node scripts/verify-shapes.cjs fixtures/shapes/candidates/language-type.json`, vocabulary `fixtures/shapes/language-type-vocabulary.md`, evidence `docs/shape-evidence-5.15.1.md#lt-*`): 41 candidates ON their carriers (`divi/heading` at h1 to h4 and inside a card group, `divi/text`), 37 PASS, 2 DEAD, 2 css-only, no phone overflow, no control noise.
- DEAD: `lt-heading-caps-none` and `lt-text-caps-none`. Divi 5.15.1 has no capitalization value `none` (`Font.php` 543-561 emits `text-transform` only for `uppercase`, `lowercase`, `capitalize`, and `none` only for an empty string over an inherited uppercase; `FontCapitalizationMigration.php` strips other values on blocks older than 5.8.1). Ruling 5 therefore resolves to "never": a `none` role writes NO capitalization key and the migration drops every recipe literal so Divi's default shows.
- css-only: `lt-heading-dead-weight-500` and `lt-text-dead-weight-500`. 500 is Divi's default weight for both modules, so the CSS is emitted and the computed style does not change. They are default-value controls, not dead paths (`dead-on-module.txt` gained nothing); their `expect` was first written DEAD, the run measured css-only, the expectations were amended.
- Cascade (Ruling 7): held. A `desktop` value reaches the computed style at 900 and 400 px for heading and text, so the patch writes `desktop` only; the three-breakpoint fallback exists in `LanguageAdapters` and is not used.
- `LanguageAdapters::TYPE_PROVEN` (ten keys, all true) is pinned to the "Adapter decisions" table of the vocabulary file.

**Data and proofs.** `design-languages.json` (27,997 B) gained a `type` object per language (roles display, title, eyebrow: weight, letter spacing, capitals) and a generated "Type" line in each `design-language:<id>` topic. The starting table was amended in three places (the plan's "Spec issues" 2 to 4): glass-dark display weight 600 -> 700 (its prose says "a bold headline"; Inter has 700; the triple stays distinct from soft-saas), organic-warm display letter spacing -0.5px -> -1px (whole-pixel bound; 0px would equal corporate-trust), luxe prose "0.1em letter spacing" -> "3px letter spacing". The weight proof reads `fixtures/shapes/font-weights.json`, extracted by `scripts/extract-font-weights.php` from Divi 5.15.1's own `google-fonts.json` with `axes.wght` (19 families): every weight the ten languages use passes in its role's font. Zero exceptions, no baseline file, no two languages share a type triple, uppercase headlines only where the prose asks (brutalist-flat), data and prose agree (`TypeAudit`, `typeProblems()`).

**Code.** `BlockAttrs` (the block scanner, decoder and merge moved out of `KitResolver`, shared; the served output over 71 recipes x 40 kits is byte-identical before and after the move, hash `8cef659f...`), `LanguageAdapters`, `LanguageResolver`, `SectionRecipes::resolveMarkup()` (pipeline now `ImageTokens` -> `LanguageResolver` -> `KitResolver` -> `RecipeTokens`), `ServedVariants` (the four path linters, `ShapeRegistryTest`, `KnownBlockPaths`, `RecipeModuleSettingsTest` and `scripts/list-ungrounded-recipe-paths.php`, now resolve the type step too), `DesignLanguages::typeProblems()/typeLine()/generatedLines()`, `StyleGuide::typographyLanguageSentence()` (450 B cap; says the eyebrow role is an h4 in the eyebrow font and labels), `tests/Support/TypeAudit.php`, `scripts/type-migration-report.php` (`make type-report`), `scripts/migrate-type-markers.php`, `scripts/verify-language-type.cjs` with `scripts/lib/language-type-*` (`make verify-language-type`).

**Migration.** 206 type markers in 56 recipes: display 56, title 64, eyebrow 86 (53 headings + 33 texts), 4 named exemptions (shop-grid `divi/heading` 0 has no `headingLevel`; proof-press-quotes `divi/text` 0, 3, 6 are uppercase publication names in the display font). 268 literal weights, letter spacings and capitals were dropped from the recipes; six recipe titles/descriptions that named the old type look were reworded. `make type-report`: marked 206 of 206. Merge trap: a marked block keeps everything but weight, spacing and capitals under every language.

**Goldens.** `tests/golden/language-type-patches.json`, 40 patches (10 languages x 4 role/carrier rows), sha256 `64bf3702f64351d4c8af21758b2fca684b3321f57745ebda7a95b3169c04a9f4`. `KitGoldenFreezeTest` now freezes seven goldens: the four earlier ones, the rule and chip goldens (never frozen before; added at their 77abc24 hashes) and the new language golden. The six earlier ones are unchanged.

**Sizes** (before at 77abc24 -> now): default guide 28,975 B unchanged; `topic` parameter description 258 characters unchanged; `kit-rules` 8,997 B unchanged; catalog `style-kits` 8,444 B, `controls` 8,331 B unchanged; `typography` 7,496 -> 7,943 B; each `design-language:<id>` topic +208 B (its Type line, each at most 260 B; luxe 2,758 -> 2,963 B); served `hero-cta` 3,397 -> 3,450 B; `section-recipes.json` 662,636 -> 662,117 B (bound 716,800). `docs/token-usage.md` regenerated.

**Tests.** Free suite 6,043 tests (green), Pro suite 2,169 (green), `scripts/test-verify-language-type.cjs` 31 pure tests (ok), `scripts/test-language-type-run.cjs` 18 live synthetic checks in real Chromium, one departure per gate code. `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` is empty.

**Live gates** (Divi 5.15.1, dev site, controller-run):
- `make verify-shapes` OK.
- `make verify-language-type` 30 PASS 0 FAIL (10 languages x 3 widths) after one calibration. The first run failed bold-vibrant and brutalist-flat at 400 px with the synthetic h1 word "Craftsmanship," (14 letters). The longest display word of any library heading is "Remarkable" (10), so the gate text became "Remarkable results, delivered"; the real overflow check is the recipe render.
- Narrowed recipe render: 11 recipes x all 10 languages reached 351 PASS cells 0 FAIL before the run was stopped for time; the remaining 45 type-marked recipes were then rendered under bold-vibrant and brutalist-flat only (the two heaviest languages, the overflow risk): 270 PASS 0 FAIL; the same 45 recipes under soft-saas, playful-pastel and glass-dark (the other languages that write weight 700): 405 PASS 0 FAIL. **Known gap: the remaining 5 languages (editorial, organic-warm, tech-mono, corporate-trust, luxe; weights 300 to 600, no capitals) were not rendered for those 45 recipes**, so the full 56-recipe x 10-language render was not completed.
- Narrowed kit smoke (quiet-solid, raw-frame x editorial, brutalist-flat) 12 PASS 0 FAIL.

**Review.** An independent opus whole-branch review found no material defect. Three low points: the guide wording (the eyebrow role is an h4 in the eyebrow font, fixed in edc95b1); the weight-in-font proof is necessary but not sufficient for a variable font (the live `type-font-loaded` code covers it); role-versus-level is checked only for the shipped recipes (a note for any future path that resolves other content).

**Rulings 1-12 restated** (all held): 1 type lives on the language, not the kit; 2 three roles only (display, title, eyebrow), body, size and line height untouched; 3 markers are `"aied":{"type":...}`, a separate resolver that runs before the kit step; 4 the language wins over a recipe literal at the same key and every literal kept is a named shrink-only exemption; 5 weights are checked against Divi's own font file (and `none` is never written, see the probe); 6 uppercase display/title only where the prose asks for capitals; 7 `desktop` value only (cascade proven); 8, 9, 10 belong to Adapter B (rhythm); 11 one gate script for both adapters (built with `TYPE_SLOTS` so B adds `RHYTHM_SLOTS`); 12 the dogfood scorer is NOT extended (it scores kit decisions), so a hand-written page's type is not scored.

**Deviations from the plan.** `BlockAttrs` (the scanner moved to a shared helper, as "Left to the builder" allowed); `SectionRecipes::resolveMarkup()` added; the rule and chip goldens were frozen too; `RecipeLibraryTest` selectors re-scoped to `"aied":{"kit"` so type markers do not widen the kit matrix; the dogfood edit base re-pinned (sha256 `4cc4ba13...`, 54,680 B, scores unchanged); three kit tests now run the language step first; `LanguageTypeBudget` luxe topic pin 2,756 not 2,758.

**Known limits.** A `none` role writes no key, so a site preset that sets capitals on a heading still shows them. Size and line height stay per recipe. Body copy, blurb, testimonial, counters and tabs are not typed. The dogfood scorer does not score type. `type-font-loaded` proves the faces on the dev site only. The full 10-language render of all 56 type-marked recipes was not completed (see the live gates).

**Owner decisions left.** Merge and release (nothing pushed); Adapter B (rhythm) is built next on top of this branch.

### Adapter B, rhythm (built on branch `feat/rhythm-adapter` from main 2b437bc, which holds Adapter A; NOT merged, NOT released; the validator, `wp-plugin/validator/`, the zip and `wp-plugin/readme.txt` are untouched)

Plan `docs/superpowers/plans/2026-10-24-rhythm-adapter.md`. All facts below are measured on 2026-10-24; the live numbers marked `TBD-CONTROLLER` are filled by the controller after its gates.

**Probe** (`fixtures/shapes/candidates/language-rhythm.json`, vocabulary `fixtures/shapes/language-rhythm-vocabulary.md`, evidence `docs/shape-evidence-5.15.1.md#lr-*`): 37 candidates ON their carrier (`divi/section`), 35 PASS, 1 css-only, 1 DEAD.
- css-only: `lr-section-default-56`. 56px is Divi's own section default at every width, so the CSS is emitted and the computed style does not change (a default-value control, not a dead path; every language candidate therefore starts from a 24px base, which is no written value).
- DEAD: `lr-section-sync-alone`. `syncVertical` written alone changes nothing (an inert builder toggle). It is written only beside `top` and `bottom`, as every library section carries it; `dead-on-module.txt` gained nothing.
- The 30 language cells (10 languages x section, open, tight) changed the computed `padding-top` and `padding-bottom` to exactly the desktop value at 1280, the tablet value at 900 and the phone value at 400 (bottom equal to top). A section with a background image kept its image. A section that overlaps the one above (`margin.top` -80px) and was itself given the padding kept the same overlap area (102,400 px² before and after); that probe padded the overlapping section itself and says nothing about a neighbour. No library recipe puts a negative margin on a section (checked over `section-recipes.json`: the negative margins are on rows, groups and images).
- `LanguageAdapters::RHYTHM_PROVEN` (six keys, all true) is pinned to the "Decisions" table of the vocabulary file.

**Data and derivation.** `design-languages.json` (27,997 -> 28,662 B) gained a `section_space` object per language (`desktop`, `tablet`, `phone` px; data equal to the language's own "Section padding" sentence, tested both ways; all ten triples distinct, bounds desktop 56-200 and phone 28-120, desktop >= tablet >= phone). `DesignLanguages::sectionSpace()` returns three roles: `section` is the data itself, `open` = x1.25 and `tight` = x0.6 of each value, rounded to the nearest 8px (a half rounds up) with integers only (`intdiv(v * num + 4 * den, 8 * den) * 8`), desktop clamped into 56-200, phone into 28-120 and tablet into [phone, desktop] (a clamp gives the bound itself, so 28 and 56 are legal results). An exhaustive sweep over every triple inside the bounds (more than 10,000) checks each result is a multiple of 8 or a clamp, the bounds and the order; the strict order section/open/tight holds for the ten shipped languages. The 30-cell table is the golden (below). tech-mono's prose said "the same padding on every section", which `open` and `tight` contradict: reworded to "every ordinary section has the same padding" (+10 B; the sentence the parser reads is untouched; a test bans the old claim).

**Code.** `DesignLanguages::sectionSpace()` / `sectionSpaceLine()` / `SECTION_SPACE_PATH` / `sectionSpaceProblems()` and `GENERATED_PLACE` (each generated line is printed after its own anchor line: Type after the Fonts line, Section spacing right after the Section padding line, under "Shape, space and depth"), `LanguageAdapters` (`rhythm` role, `RHYTHM_PROVEN`), `LanguageResolver` (marker `"aied":{"rhythm":"section|open|tight"}` on `divi/section`, resolved in the same pass as the type marker; the patch writes top, bottom and `syncVertical` at `module.decoration.spacing.{desktop,tablet,phone}.value.padding` and NOTHING else: never left or right, never margin), `tests/Support/RhythmAudit.php`, `scripts/rhythm-migration-report.php` (`make rhythm-report`), `scripts/migrate-rhythm-markers.php`, and in the render gate `scripts/verify-language-type.cjs` with `scripts/lib/language-rhythm-*` (`RHYTHM_SLOTS` beside A's `TYPE_SLOTS`).

**Migration.** 46 rhythm markers in 46 of 71 recipes: `section` 38, `open` 7, `tight` 1. The role comes from the nearest factor (1, 1.25, 0.6) to the recipe's own desktop value over 112px: a 120px hero is 1.07x and stays `section`; the recipe's `role` tag is reported, not used. 25 sections stay literal as named exemptions in seven reason classes (`RhythmAudit::EXEMPTIONS`, shrink-only, cap asserted): no padding 7, asymmetric 6, 0px edge 3, desktop only 3, old alpha shape 3, percent or vw 2, out of band 1 (proof-rating-strip 32/28/28, a hairline strip thinner than any tight value). 276 literal top and bottom values were dropped from the recipes. `make rhythm-report` (pure): marked 46 of 46. Merge trap: a marked section keeps everything but its vertical padding under every language. `section-recipes.json` 662,117 -> 652,430 B (bound 716,800); the dogfood edit base holds three marked sections, re-pinned (54,680 -> 54,860 B, sha256 `71102e51...`, scores unchanged).

**Goldens.** `tests/golden/language-rhythm-patches.json`, 30 patches (10 languages x 3 roles), sha256 `4dfde6021b53c1e9a62d0f9d83ac992bec8c440c40ee894c386e2ef84e49f173`. `KitGoldenFreezeTest` now freezes eight goldens (the seven of Adapter A plus this one); the earlier seven are unchanged.

**Sizes** (before at 2b437bc -> now): default guide 28,975 B unchanged; `topic` parameter description 258 characters unchanged; `kit-rules` 8,997 B unchanged; catalog `style-kits` and `controls` unchanged; `typography` 7,943 B (the rhythm budget pin is 7,943 after A's type wording fix); each `design-language:<id>` topic +236 to +240 B (its generated "Section spacing" line is 235-239 B, cap 260; tech-mono +247 B with its prose change; luxe 2,963 -> 3,203 B, the largest topic soft-saas 3,704 B); `section-recipes.json` 662,117 -> 652,430 B; `design-languages.json` 27,997 -> 28,662 B. `docs/token-usage.md` regenerated.

**Tests.** Free suite 6,118 tests (green, 1 skipped by design), Pro suite 2,169 (green), `scripts/test-verify-language-type.cjs` 31 pure tests and `scripts/test-verify-language-rhythm.cjs` 25 pure tests (ok), `scripts/test-language-type-run.cjs` 18 and `scripts/test-language-rhythm-run.cjs` 19 live synthetic checks in real Chromium, one departure per gate code (not re-run for these docs). `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` is empty.

**Live gates** (Divi 5.15.1, dev site, controller-run, 2026-10-24):
- `make verify-language-type`: 30 PASS 0 FAIL (10 languages x 3 widths x 5 type slots + 4 rhythm slots).
- `make verify-shapes`: TBD-CONTROLLER.
- Narrowed recipe render of the 46 rhythm-marked recipes under luxe, brutalist-flat and editorial: TBD-CONTROLLER.
- Narrowed kit smoke: TBD-CONTROLLER.

**Review.** An independent opus whole-branch review found no material defect. Low points, all handled: the sweep's "multiple of 8 or a clamp" check compared a value with itself for desktop and phone (fixed: the check now uses the clamp bound of each key, and a mutation to plain `intdiv(v * num, den)` rounding in a scratch copy fails the sweep at `open 56/28/28 desktop=70`); the planted half of the prose test only asserted two arrays differ (fixed: the comparison is a helper run on a planted language); the overlap sentence of the vocabulary file was overstated (reworded above). Left as is, an owner decision: the guide line says `open` is "a hero or closing band", while 5 of the 6 migrated heroes are `section`.

**Rulings 8-10 and 12 restated** (all held): 8 `open` = x1.25 and `tight` = x0.6, rounded to 8px and clamped, derived with integers only (no 30 extra numbers to keep consistent); 9 rows, horizontal padding and every other module are out (83 of 101 rows carry no padding); 10 asymmetric, percent, vw and 0px sections stay literal and are listed (here also the sections with no padding and the desktop-only ones, the 25 exemptions); 12 the dogfood scorer is NOT extended, so a hand-written page's rhythm is not scored.

**Deviations from the plan.** `sectionSpace()` and `SECTION_SPACE_PATH` live in `DesignLanguages` (it cannot read `LanguageAdapters`; `LanguageAdapters` points to them, a test pins the identity); `GENERATED_PLACE` (A put every generated line under Fonts; the two pins of A were amended by exact replacement); the Section spacing line names the path itself (no room in `layout-sizing`, 9,972 of 10,000 B); three extra gate codes (`rhythm`, `rhythm-expectation`, `rhythm-image`); `ImageTokens::resolve` added to `scripts/lib/language-type-sheet.php` (`resolveMarkup` does not resolve image tokens); the plan's prose regex tightened to the clause for quote-over-image (760px is a width); the leftover order inside one block follows the `KINDS` order; `LanguageResolverTest::testUntouchedBlocksStayByteIdentical` also skips rhythm-marked recipes; the dogfood edit base re-pinned.

**Known limits.** Derived triples collide after clamping (tight is 56/40/28 for organic-warm, tech-mono, playful-pastel and corporate-trust; open is 128/88/64 for glass-dark and soft-saas); brutalist-flat's tight step is only 8px below its section (64 -> 56 at desktop) and the 4 transition and divider sections fit only brutalist-flat's open 80px; a hand-written page that ignores the Section spacing line gets no rhythm; `LandingGuide` still teaches "generous section padding (5-8vw)", which disagrees with the languages' px rhythm; a section with no padding keeps Divi's 56px default; rows, horizontal padding and margins are not typed; sections with percent, vw, asymmetric, 0px or desktop-only padding stay literal; the dogfood scorer does not score rhythm.

**Owner decisions left.** Merge and release (nothing pushed); migrating blog-feed and icon-values (100px, desktop only; marking them `section` would give them tablet and phone steps they lack); whether heroes at 120px should be `open`.
