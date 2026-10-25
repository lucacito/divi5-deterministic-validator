# Wording gaps: what six blind assistants could not find in the served guide

Status: built (see Outcome; spec + plan written 2026-10-25). Branch `feat/wording-gaps` from local main 895610e, worktree
`/Users/Lucas/Documents/JHMG-Local/wt-wording`. No release, version bump, zip rebuild or readme edit; the validator (`src/`,
`wp-plugin/validator/`) is NOT touched. Plan: `docs/superpowers/plans/2026-10-25-wording-gaps.md`.

## Intent and why

The dogfood rounds (`docs/dogfood.md`; round 2 in CLAUDE.md "Dogfood round 2") give a fresh assistant ONLY the served guide (default
guide, `kit-rules`, `style-kit:<kit>`, `design-language:<language>`, `controls`, `forms`) and a brief, no recipes. Six such
assistants built pages that score about 100% (`make dogfood-score`) and render clean, so what is left is not wrong output but
**patterns the guide does not teach in a form a hand-writer can follow**, plus three places where two served texts disagree.
Their "unclear / missing / contradictory" paragraphs were compiled into 14 gaps. This spec measures each one against the guide
the assistants really saw (regenerated with `php scripts/dogfood-prepare.php glass-edge glass-dark` and `neo-brutal
brutalist-flat` into the scratchpad: byte sizes 28,975 / 9,598 / 8,230 and 9,599 / 2,613 and 3,012 / 9,105 / 6,474, equal to the
served topics) and against the real recipes, adapters, shape registry and render evidence, then decides the smallest grounded
change for each.

## Measured finding per gap

Verdicts: **confirmed** (the guide does not say it, or says it ambiguously), **answered** (the served guide already says it;
where), **contradiction** (two served texts disagree), **needs a probe** (no render evidence yet; nothing is taught until it exists).

| # | Gap | Verdict | What was measured (source) |
|---|---|---|---|
| 1a | A row of tags: layout, label text, frame width, "pill labels" vs the kit's control radius | confirmed | `kit-rules` says only "Tag: ... `styles.all` (width, style, colour; 1px at least). Its fill stays transparent; its padding and size are yours". The adapter writes the KIT'S border width (`frameStyles()`: 1px only when the kit's is 0px; `chipPatch()` raises a double to 3px), so "1px at least" reads as "1px" (round-1 dogfood: 4 misses, a 4px double kit). The real tag (`portfolio-featured-grid`, 5 tags): a `divi/group` with padding 4px/10px holding ONE `divi/text` label in the eyebrow style (12px, line height 1.3em, `muted`), placed in a flex-row group (`flexDirection` row, `flexWrap` wrap, `columnGap` 12px, `rowGap` 8px, `alignItems` center); the contact sheet (`dist/contact-sheet/portfolio-featured-grid-brutalist-flat-1280.png`) shows the tag hugging its label inside raw-frame's thick square frame (the kit's 4px width). A ROW of several tags is in no recipe (one tag per card) and was never rendered: **needs a probe** (P1). The radius is the kit's control radius (0px in raw-frame, 999px in warm-pill-soft): a language's "pill" wording yields to the kit (gap 5). |
| 1b | Icon chips: the chip is the module box, so must its width be capped (`sizing.maxWidth` 40px with auto margins)? | confirmed; partly needs a probe | No recipe caps an icon chip. The recipes' icon chips (`icon-values` 3, `features-carousel-strip` 6, kit sheet 2 per cell) set `icon.advanced.align` "left", padding 10-12px on `module.decoration.spacing`, glyph size 20-22px; the contact sheet (`icon-values-playful-pastel-1280.png`) shows each module box hugging glyph plus padding (a 40 px disc). The probe proved the radius and fill land on `div.et_pb_icon.et_pb_module` (`kit-chip-vocabulary.md`) but recorded no box width; the kit sheet's icons carry NO align (Divi's default `center`) and `chip-observations.json` records no width. Whether a centred icon also hugs, and whether a ROW of icon chips in one flex group hugs, is unmeasured: **needs a probe** (P1). Until then the guide teaches the recipe's shape (align left) and says nothing about `maxWidth`. |
| 2 | Separating side-by-side columns (pricing): only a top edge exists | answered (`kit-rules` Rules and chips: "A kit rule has no left or right edge, so side-by-side columns get no vertical rule: use the top edge or a divider line", and "to rule the top of a specific group or column (for example between plan columns), write the edge on that block itself"); pattern missing | The dogfood brief itself asks for "three plans in three columns separated by rules", so the assistants looked for a side rule. The real pricing pattern (`pricing-three-tier`) separates plans with the row's column gap and a `card` on each `divi/column` (frame, radius, padding), and rules the inside: the features group of each plan carries a quiet `rule` top edge (`on` surface; `on` accent in the featured plan). The guide never shows that pattern in one place. |
| 3 | The featured pricing column: rule on the column plus an accent-ground card; ground and panel-button colours in two places | confirmed | `pricing-three-tier` column #2 is `card` with ground `accent` on light (fill `accent`, frame in the fill colour, text in `accent-text`), its features group a quiet `rule` on `accent` (`accent-text` at 40%), its button a primary on `accent` (fill `surface`, label `text`). The column carries NO rule marker. `kit-rules` describes grounds (`groundSentence()`) and says only "A button on an inverted panel takes the `panel` treatment"; the accent-ground button is the "accent band" sentence of each kit topic. No text connects them. |
| 4 | Accordion: "the frame, radius and shadow land on EACH item when written on the parent" is ambiguous | confirmed | Served: "On the accordion write all of it on the parent; each item gets it". `fixtures/shapes/kit-item-vocabulary.md` (notes 4 and 48, ki-shadow-soft): a shadow or radius on the `divi/accordion` parent is drawn on EVERY `.et_pb_accordion_item` (module.json selector `{{selector}} .et_pb_toggle`), never around the accordion as a whole; a radius prints `overflow: hidden` on each item. The `divi/accordion-item` blocks carry none of it. |
| 5 | A language's own prose ("open every section with a 6px black line", "4px borders", "translucent cards") vs the kit | confirmed | "the kit wins" is said once, in `kit-rules` ("Reusing a kit topic's blocks"), which assistants read after the language. Measured conflicts in `design-languages.json` prose: brutalist-flat "Open every section with a 6px black line (the divider module)" (no kit can draw it: `ruleWidth()` caps a strong rule at 4px; raw-frame's is 4px); luxe "a hairline divider module (1px line in the accent colour)" twice (a kit accent mark `rule-accent` is the STRONG width, 2px for gilt-hairline; a quiet `rule` is the `border` colour); glass-dark "Cards use a semi-transparent background ... 1px border at about 12% white" (the kit card fill is the opaque `surface`, never transparent). The 4px borders of brutalist-flat equal raw-frame's width (no conflict under its own kit; a conflict under any other kit). |
| 6 | Quiet and strong rules look identical in some languages; the quiet colour is very faint | confirmed | `RULE_COLOUR`: quiet = `border` on light/surface, strong = `text`. In bold-vibrant and brutalist-flat `border` equals `text`, so quiet and strong differ only in width (quiet/strong 1.00:1). In the other eight languages the quiet line is 1.18:1 (soft-saas, light) to 1.58:1 (glass-dark, light) on its band (`RuleChipAudit::QUIET_RULE_FLOOR` 1.1 is measured from it): a decorative separator, not a boundary. Never said. |
| 7 | Hover fills not stated; a hovered primary's fill equals the page background (glass-edge on glass-dark) | confirmed, real defect in ONE default pairing | Pure audit over `KitAdapters::patch()` (40 kits x 10 languages x {light, accent, panel} x {primary, secondary} = 2,400 button cells; "box" = the larger of fill-vs-band and, with a frame wider than 0px, frame-vs-band): 60 cells have a box at rest (>= 1.5:1) and none on hover (< 1.1:1). 30 = the five `invert` kits whose frame colour is `same` (glass-edge, heavy-block framed; flat-square, block-caps, pill-caps with 0px) on the light band in the six languages whose `accent-text` is within 1.1:1 of `bg` (editorial 1.07, glass-dark 1.00, tech-mono 1.02, corporate-trust 1.00, soft-saas 1.08, luxe 1.02): `invert` makes the fill `accent-text` and a `same` frame follows the fill. 30 = the three 0px `invert` kits on a panel in all ten languages (by design, kit-limits Ruling 2: a filled button on a panel turns transparent on hover). The label stays readable in every cell (the existing contrast proofs). glass-edge is glass-dark's DEFAULT kit, so the language's own primary button vanishes on hover except its label: the report is right. Simulated fix (Ruling 7): `invert` with a `same` frame wider than 0px keeps the REST frame colour on hover: framed kits x 10 languages x {light, panel} x 2 roles, box at rest >= 1.5 => box on hover >= 1.5: 0 failures (glass-edge and heavy-block on light: minimum 4.82:1); on the accent band 4 tech-mono cells (chunky-pill, raw-frame, chunky-pill-offset, rimmed-solid: `text` and `accent` nearly equal) stay at 1.10:1 (a known limit, not touched). The hover colours themselves are never stated in the guide (`kitSurfaceSentence()` states rest colours only). |
| 8 | Divider: no statement that it needs no `show` key nor its default vertical spacing | confirmed | `controls` (Divider line): "The `show` switch could not be measured (source only)". Evidence that `show` is not needed: `KitAdapters::rulePatch()` writes `color`, `weight`, `style` only ("never `position`, `show` or any `module.decoration`"), and every `verify-kits` cell renders 8 dividers (`{"builderVersion":"5.8.0","aied":...}`, no `show`, no spacing) whose `::before` line is measured (codes `rule-style|width|colour`; 264 of 264 cells PASS on Divi 5.15.1). Divi's default space around the line was never measured: no number is taught. |
| 9 | The glyph code `&#xf00c;`: escape the ampersand in block JSON? | confirmed | Both forms are proven: the recipes write the plain `&#xf518;` (icon-values, features-carousel-strip; validated in the suite and rendered by `verify-recipes`, 426 PASS); the kit sheet writes `&#xf005;` (`kit-checks.cjs` `chipMarkup()`, rendered by `verify-kits`); Divi's own builder export writes `&#xf0a9;` (`fixtures/valid/page-17-newpage.json`). Both decode to the same JSON string. |
| 10 | Band names: "light" is the page colour even in a dark language, "surface" the card fill... | partly answered (`kit-rules` `bandSentence()`: "`light` is the language's own page colour (a dark language's too), `surface` its surface, `accent` the accent, `dark` a photo or overlay, `panel` the inside of an inverted card") | The sentence names no palette ROLE (an assistant writes colours by role), and "its surface" does not say it is the `surface` role. The one table that maps them exists only dev-side (`tests/Support/Dogfood/Page.php` PALETTE: `bg` light, `surface`/`surface-glass` surface, `accent` accent, `text` panel, `overlay`/`inverse` dark). |
| 11a | Testimonial: no text-colour paths taught | confirmed | Not in any served topic (`controls` teaches only the `company`/`portrait` object shapes). Probe-proven and registered: `author.decoration.font.font.{bp}.value.color` (rc-testimonial-author-color), `jobTitle...` (rc-testimonial-jobtitle-color), `company...` (rc-testimonial-company-color), `content.decoration.bodyFont.body.font.{bp}.value.color` (registry). The recipe `testimonial`: author `text`, job title and company `muted`, quote `text`. |
| 11b | Gradient stops: array? numbers or strings? | contradiction (default guide vs `backgrounds`) | Default guide: "`…gradient.stops.0.{position,color}` (0,"rgba(...)")" (reads as an object keyed "0"). `backgrounds` shows a list with string positions. Builder exports (`fixtures/valid/page-23-style-reference.json`) write a LIST with NUMBER positions; the recipes use numbers (4) and strings (7); all validate and render. |
| 12 | Two phone-stacking recipes (`flexWrap` vs `flexDirection: column`) | contradiction (default guide only) | "Columns stack on a phone" (the row's `flexWrap` wrap at phone/phoneWide plus each column's `flexType` 24_24) is measured (`docs/guide-shapes-evidence-5.14.md`, again on 5.15.1) and used by 38 recipe rows; "Mobile: collapse multi-column rows to one column" (`module.decoration.layout.phone.value` = flex/column on the ROW) is used by 0 of 71 recipes and has no measurement. A flex `divi/group` stacking with its own `layout.phone.value.flexDirection` "column" is used by the recipes (portfolio-featured-grid) and render-checked at 400 px by `verify-recipes`. |
| 13 | Kit topic samples resolved under editorial; `builderVersion` 5.0.0-public-beta.1 / 5.0.2 while the guide says 5.8.0 | confirmed; root cause found | `StyleGuide::styleKitTopic()` hard-codes `DesignLanguages::get( 'editorial' )` (line 798): the topic has no language input, so every kit topic's hex values and legend ("#ffffff = surface and accent-text") are editorial's. The snippet builders type their own `builderVersion` literals: `kitButton()` `5.0.0-public-beta.1` (line 1117), `kitCard()` group `5.0.2` and text child `5.0.0-public-beta.1` (980, 985), `kitInvertedPanel()` `5.0.2` (1012); only `kitToggle()` uses `5.8.0` (959). The values were copied from the old recipe blocks they were modelled on. |
| 14 | Form submit: `kit-rules` says padding/shadow/lift live at `button.decoration.*`, `controls` says those are dead on a button | contradiction (both true) | `controls` (Button): "Padding and box shadow written on the `button` element (`button.decoration.spacing`, `button.decoration.boxShadow`) changed nothing (rendered: dead); write them on the module." True of `divi/button`; on `divi/contact-form` the submit's `button.decoration.*` is live (`kit-form-vocabulary.md`). `kit-rules` says so; `controls`, where the contradiction is read, does not. Found in the guide-polish round (its item 3) and fixed only on the `kit-rules` side. |

## Goals, non-goals, anti-goals

Goals:
- Every confirmed gap is answered in the served guide, in words GENERATED from the constant, adapter output or data that decides
  them (never typed twice), with every concrete path in the shape registry (or a real recipe), every named recipe existing and
  showing the pattern.
- The two contradictions in the default guide (11b, 12) and the one between `controls` and `kit-rules` (14) are removed.
- The one real visual defect (7, glass-edge on glass-dark) is fixed by a minimal role-table change, proven with zero exceptions.
- Kit topic samples are resolved in the language asked for and carry the guide's `builderVersion` (13).
- A blind dogfood re-run shows the gaps closed.

Non-goals: new kit decisions or roles; a fix for the 0px `invert` kits' hover (no frame to keep: Ruling 7) or the tech-mono accent
band hover (4 cells); teaching any number for Divi's default divider spacing; a `maxWidth` rule for icon chips unless P1 proves
it is needed; recipe changes; render-gate changes beyond the controller's narrowed runs.

Anti-goals (hard): no validator change; no new MCP tool, REST route or OpenAPI operation; no remote call or custom code; no
schema from memory; no weakened test; no baseline file; the default guide stays EXACTLY 28,975 B.

## Design

1. **New topic `kit-patterns`** (served after `kit-rules`; at most 6,000 B, pinned; real bound 10,000 with 500 B headroom):
   "how to hand-write the recurring kit patterns", generated by `StyleGuide::kitPatterns()` from `KitAdapters` (patches, `APPLIED`,
   `RULE_COLOUR`, `ruleWidth()`), `DesignLanguages` (palettes) and the recipes it names. Sections: tags in a row (1a), icon chips
   (1b), plan columns and the featured plan (2, 3), quiet and strong rules and the divider (6, 8), hover colours (7: per hover kind,
   on a panel, on an accent ground, the form submit), testimonial text (11a), glyph codes in block JSON (9), Limits. Each section
   names its recipe(s) ("get_section_recipes with `name` and `style_kit` shows it resolved under any kit"). The topic does not
   repeat what `kit-rules` says; it points there. Not backticked: the topic's own name (GuideRecipePointersTest reads backticked
   hyphenated words as recipe names).
2. **Design-language topics:** a generated third line (`DesignLanguages::GENERATED_PLACE` gains `'kit' => 'style_kit'`, printed after
   "- Style kit: <id>", at most `GENERATED_LINE_MAX` 260 B): the kit decides the borders, corners, shadow and hover of the kit-driven
   modules (nouns from `KitAdapters::APPLIED`), where the prose differs the kit wins, and a line that opens a section is the kit's
   strong rule (its width from `ruleWidth('rule-strong', $kit['border'])`). Prose that no kit can follow is reworded in
   `design-languages.json`: brutalist-flat's "6px black line" and luxe's two "1px line in the accent colour" (Ruling 5).
3. **`kit-rules` (pin stays 9,600 unless Ruling 8's fallback):** the accordion sentence says the parent's frame, radius and shadow are
   drawn around each item, not the whole accordion, and the items carry none (4); `bandSentence()` names the palette role of each band
   from one new public table `KitAdapters::BAND_FILL` (10); the tag clause says the frame is the kit's border width (1a); the
   "Reusing" paragraph shrinks once the kit topic is served in the asked language (13). Trims are listed in the plan (Task 4).
4. **`controls`:** one generated clause after the button's dead padding/shadow sentence, said only while `FORM_PROVEN`
   `button-padding` and `button-shadow` are true (14); +about 90 B (9,105 -> about 9,195, within 9,500).
5. **Default guide (exactly 28,975 B):** the topic line gains ", kit-patterns" (+14 B), paid for by replacing "Mobile: collapse
   multi-column rows to one column" (360 B) with "Mobile: rows and flex groups" (rows: the measured row shape above; a flex group:
   `layout.phone.value.flexDirection` "column") (12) and the gradient line with a list form (11b); padded to the exact byte.
6. **Kit topic samples (13):** one constant `StyleGuide::BUILDER_VERSION = '5.8.0'` used by every generated snippet; `get_style_guide`
   takes an optional strict-string `design_language` (MCP schema, REST query arg, OpenAPI query parameter, as `get_section_recipes`
   does: a non-string is 400 / -32602, an unknown id answers the valid ids) used by `style-kit:<id>` only; without it the topic is
   editorial's as today and its legend header says the values are editorial's (Ruling 6).
7. **Hover fix (7):** in `KitAdapters::hover()`, `invert` with kit border colour `same` and a frame wider than 0px keeps the REST frame
   colour on hover (one condition). Changes only glass-edge and heavy-block (button patches on light, accent and dark; the form
   submit, which relocates the primary). Goldens `kit-adapter-patches.json` and `kit-form-patches.json` are re-frozen deliberately
   (`KitGoldenFreezeTest`: two hashes change, the other seven do not).
8. **Dogfood harness:** `Prepare::GUIDE_FILES` gains `07-kit-patterns.md`; `03-style-kit.md` is served with the run's language;
   `TASK.md` says "02..07". Not shipped.

## Rulings (by delegation; each with the cost if wrong)

1. **One new topic, not more text in `kit-rules`.** `kit-rules` has 2 B left under its 9,600 pin and the patterns need about 4-5 KB.
   Cost if wrong: one more topic name for assistants to discover (mitigated: the language Kit line, `kit-rules` and the default
   guide's topic line name it); +14 B in the default guide and +14 characters in the `topic` description.
2. **`topic` description pin 260 -> 290** (258 -> 272 characters; schema bound 300). Cost if wrong: 18 characters left for any later
   topic name; the next name needs a reword of the fixed text (" <id>: one language or kit.").
3. **Band roles live in `kit-rules`, not `kit-patterns`** (`on` is defined there; one definition). Deviation from the brief's topic
   list, recorded. Cost if wrong: none beyond where it is read.
4. **The "kit wins" statement is moved EARLY by a generated line in each design-language topic** (read before `kit-rules`), not
   by a default-guide sentence (no bytes there). Cost if wrong: a reader who skips the language topic still meets it in `kit-rules`.
5. **Language prose that no kit can follow is reworded** (two languages, three phrases); prose that equals the default kit (raw-frame's
   4px) stays. Cost if wrong: two language topics change by a few bytes (`LanguageTypeBudgetTest` and `LanguageRhythmBudgetTest` pin
   exact growth: re-pinned deliberately).
6. **`design_language` on `get_style_guide`** (optional, `style-kit:<id>` only). It removes the error class instead of explaining
   it, mirrors `get_section_recipes`, and adds no operation (the ChatGPT importer budget is untouched). Cost if wrong: a schema change
   in a free tool and three lockstep surfaces. Fallback the controller may pick instead (no API change): keep editorial, state in the
   two legend headers "these hex values are editorial's: in another language write each colour by its role", and still fix
   `builderVersion`.
7. **Hover: fix only the framed `invert` + `same` kits; accept the rest.** The 30 panel cells are kit-limits Ruling 2 (a filled button
   on a panel turns transparent on hover); the 18 light cells of the three 0px `invert` kits and the 4 tech-mono accent cells have no
   frame or no colour a role table could keep without changing the kit's shape or meaning; they are stated as limits in
   `kit-patterns` (generated: the kit list from the data). Proof: framed kits x 10 languages x {light, panel} x 2 roles, rest box
   >= 1.5:1 => hover box >= 1.5:1, zero exceptions, no baseline. Cost if wrong: two kits' hover frame stays accent instead of following
   the swapped fill (an outlined inverted button), two goldens re-frozen.
8. **`kit-rules` budget:** first fit inside 9,600 by the trims of plan Task 4; only if that fails, raise the pin to 9,700 (real bound
   10,000) with the measured size in the commit. Cost if wrong: 300 B left for the next clause.
9. **No icon `maxWidth` rule and no "row of tags/icons" claim without P1.** P1 (controller, live, one `verify-shapes` candidate file
   `kp-*` or one dogfood-render page) measures: an icon chip's module-box width with align left and with the default centre; a
   flex-row group of four icon chips and of three tags at 1280 and 400 px (one row, wrap, each box hugging). The topic states only
   what P1 proves; anything else falls back to "copy the recipe's shape".
10. **Divider:** say "no `show` key and no spacing are needed: the kit's dividers carry neither and render" (the verify-kits evidence);
   no number for Divi's default spacing.
11. **Glyph codes:** say both forms decode to the same string, the builder writes `&#x...;`, the recipes write `&#x...;`.

## Done means

- Each of the 14 gaps has its row in the Outcome with the text that answers it (topic and generated function) or the reason it does
  not (needs a probe and P1 failed; accepted limit).
- `kit-patterns` <= 6,000 B (pinned), every backticked path in it is covered by the shape registry (`ShapePaths::fromGuideText()`,
  `ShapeRegistry::covers()`), every named recipe exists and shows the pattern (a test reads the recipe and finds the marker it
  claims, e.g. `pricing-three-tier` column #2 carries `card` + ground `accent`), every number in it equals the adapter's output.
- Default guide exactly 28,975 B; `topic` description 272 characters (pin <= 290); `kit-rules` <= its pin; `controls` <= 9,500 B;
  every `style-kit:<id>` <= 10,000 B for every language; language topics within their caps.
- The hover proof has zero exceptions; seven goldens byte-identical, two re-frozen with the reason in the freeze docblock.
- `make test`, `make test-pro` green; `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` empty.
- Live (controller, serial): P1; narrowed `verify-kits` for glass-edge and heavy-block cells 0 FAIL; `make verify-shapes` OK.
- Blind dogfood re-run (two pairs at least: glass-edge x glass-dark, raw-frame x brutalist-flat): scores >= 95%, renders clean, and
  no assistant reports any of gaps 1-14 as unanswered (a re-report is traced and either fixed or recorded as a limit).

## Outcome

Status at the time of writing: built on `feat/wording-gaps` (merged to local main faf8a4e) plus the fix wave `feat/wording-fix`
(head 652e2a6: third blind round fixes, review fixes). NO release, version bump, zip rebuild or readme edit; the validator
(`src/`, `wp-plugin/validator/`) and the tracked zip are untouched. Numbers below are measured (`StyleGuide::markdown()`,
`scripts/measure-tokens.php`, the pinned tests); the live results are recorded below.

### Per gap: the text that answers it

| # | Answer |
|---|---|
| 1a | Generated topic `kit-patterns`, section "Tags" (`StyleGuide::kitPatterns()`): the flex-row wrap group (`flexDirection` row, `flexWrap` wrap, `columnGap` 12px, `rowGap` 8px, `alignItems` center; P1: one line wide, wrapping on a phone, rendered), the tag group padding 4px/10px holding ONE `divi/text` label in the eyebrow style, the frame at the kit's width and style ("at least 1px; a double at least 3px", `chipPatch()` numbers, reworded in the review fix), corners the control radius (a square kit gives square tags even where a language says pill). `kit-rules` Rules and chips carries the same width clause. Recipe `portfolio-featured-grid`. |
| 1b | `kit-patterns`, "Icon chips": the module box hugs glyph plus padding, so set no width (P1: align left and the default centre both hug; a row of icon chips in the flex-row group hugs each box); a `maxWidth` is NOT taught; set a glyph size or Divi's large default makes a big chip. Recipes `icon-values`, `features-carousel-strip`. |
| 2 | `kit-patterns`, "Plan columns and the featured plan": the row's column gap separates plans (a kit rule has no side edge), a `card` on each `divi/column`, a quiet `rule` top edge on each plan's features group. Recipe `pricing-three-tier`. |
| 3 | Same section: the featured plan is a `card` on an `accent` ground (fill accent, frame accent, text accent-text), its features group a quiet rule on accent, its button the primary on accent; a button inside an inverted panel takes `panel`. Colours generated from the adapters. |
| 4 | `kit-rules` accordion sentence: the parent's frame, radius and shadow are drawn around EACH item, not the accordion as a whole, and the `divi/accordion-item` blocks carry none (`kit-item-vocabulary.md`). |
| 5 | Two parts. (a) A generated "Kit:" line in every design-language topic (`DesignLanguages::GENERATED_PLACE` gained `kit`; at most 260 B) says the kit decides borders, corners, shadow and hover of the kit-driven modules, the kit wins where the prose differs, and a line that opens a section is the kit's strong rule at its width (`ruleWidth()`). (b) Prose no kit can follow is reworded in `design-languages.json` (Ruling 5): the brutalist-flat opener (a "6px black line" is now a "strong black line") and luxe's two "1px line in the accent colour", then, from the review rewords, the hover-colour and button wording and glass-dark's card border; the glass-dark card-fill claim ("semi-transparent") was removed because no recipe uses `surface-glass` and the kit card fill is the opaque `surface`. |
| 6 | `kit-patterns`, "Quiet and strong rules, dividers": a quiet rule is a decorative separator, 1.18:1 to 1.58:1 on light and surface; where the hairline is the text colour (bold-vibrant, brutalist-flat) quiet and strong differ only by width (numbers generated from `RULE_COLOUR`, `ruleWidth()` and the palettes). |
| 7 | Code fix plus text. `KitAdapters::hover()`: a `same` frame of non-zero width on an `invert` kit keeps its rest colour on hover (glass-edge, heavy-block). Text: `kit-patterns`, "Hover colours" (rest to hover per kind, on light, accent and panel, and the form submit) and "Limits" (generated: the 0px invert kits and the tech-mono accent cells). Audit and goldens below. |
| 8 | `kit-patterns`: "A divider needs no `show` key and no spacing: the kit's dividers carry neither and render"; P2: its module box is 23px high at line weights 1/2/4px, so the weight does not change the layout. No number for Divi's default spacing is taught beyond that measured box. |
| 9 | `kit-patterns`, "Glyph codes in block JSON": `&#xf00c;` and `\u0026#xf00c;` are the same value; either validates and renders. |
| 10 | `kit-rules` `bandSentence()` now names the palette role of each band from the new `KitAdapters::BAND_FILL` (light bg, surface surface, accent accent, dark overlay, panel text); Ruling 3: it lives in `kit-rules` where `on` is defined. |
| 11a | `kit-patterns`, "Testimonial text": the four text-colour paths (author and quote in text, job title and company in muted). |
| 11b | Default guide: the gradient line now shows a list with number positions (the builder export form); no contradiction with `backgrounds`. |
| 12 | Default guide: "Mobile: rows and flex groups" replaces the unmeasured "collapse multi-column rows" line: a row stacks by its `flexWrap` wrap plus each column's `flexType` 24_24, a flex group by its own `layout.phone.value.flexDirection` "column". Default guide still exactly 28,975 B. |
| 13 | `StyleGuide::styleKitTopic()` resolves its samples in the language asked for; one `StyleGuide::BUILDER_VERSION` ('5.8.0') in every generated snippet (the old 5.0.0-public-beta.1 and 5.0.2 had been copied from recipe attributes); `get_style_guide` takes an optional strict-string `design_language` (see "API" below). |
| 14 | `controls` (Button): one generated clause (`controlsFormClause()`, only while `FORM_PROVEN` `button-padding` and `button-shadow` are true): this is a `divi/button`; a contact form's submit is the opposite (topic=kit-rules). |

### P1 and P2 (live, controller, Divi 5.15.1)

- P1 (`fixtures/shapes/kit-patterns-vocabulary.md`, probe `kp-*`, rendered page): an icon chip's module box hugs its glyph plus
  padding (a `maxWidth` is not needed and is not taught); a flex-row wrap group holds rows of tags and of icon chips (one line wide,
  wrapping narrow).
- P2 (`kit-patterns-divider.json`, results `probe-results-kit-patterns-divider.json`): a divider's box is 23px high at line weights
  1px, 2px and 4px.

### The hover audit (gap 7)

Pure audit over `KitAdapters::patch()`: 40 kits x 10 languages x {light, accent, panel} x 2 roles = 2,400 button cells. Cells whose
box vanishes on hover (box >= 1.5:1 at rest, < 1.1:1 on hover): **60 -> 48**. The 12 fixed are glass-edge and heavy-block on light.
The 48 left are all 0px `invert` kits with no frame (flat-square, block-caps, pill-caps): 18 on the light band in the six languages
whose `accent-text` is within 1.1:1 of `bg` and 30 on a panel (kit-limits Ruling 2, by design); they are stated as limits in
`kit-patterns`. 920 framed cells in the proof scope (framed kits x 10 languages x {light, panel} x 2 roles) have zero failures,
no baseline file. The 4 tech-mono accent cells (text and accent within 1.5:1) are untouched and stated. Goldens:
`kit-adapter-patches.json` and `kit-form-patches.json` were re-frozen deliberately (14 changed patches, hover frame colour only);
the other seven frozen hashes (`KitGoldenFreezeTest`) are unchanged.

### API

`get_style_guide` takes an optional strict-string `design_language` (MCP schema, REST query argument, OpenAPI query parameter, as
`get_section_recipes` does; a non-string is 400 / -32602; an unknown id is refused with the valid ids on ANY topic, not only
`style-kit:<id>`). It is used for the `style-kit:<id>` samples; the default is the kit's owner language, else editorial. No new tool,
route or OpenAPI operation (the importer budget is untouched). The echo of the refusal is covered by `EchoedInputIsPlainTest` (MCP
and REST). The `topic` description went 258 -> 272 characters (", kit-patterns"), its pin 260 -> 290 (schema bound 300, 28 characters
left for the next topic name).

### Sizes (bytes)

| Text | Before (895610e) | After | Bound |
|---|---:|---:|---|
| Default guide | 28,975 | 28,975 | exactly 28,975 |
| `kit-rules` | 9,598 | 9,589 | pin 9,600 (real 10,000) |
| `controls` | 9,105 (9,271 with the module sweep) | 9,355 | <= 9,500 |
| `kit-patterns` (new, served after `kit-rules`) | - | 5,431 | pin 6,000 |
| `forms`, `style-kits` catalog | 6,474 / 8,178 | 6,474 / 8,178 | unchanged |
| largest `style-kit:<id>` | 9,599 | 9,556 (neo-brutal) | 10,000 |
| `topic` description (characters) | 258 | 272 | pin 290 |

The largest kit topic shrank because the samples now follow the kit's own language (smallest kit topic 7,982 B). The design-language
topics grew by the Kit line and the rewords (glass-dark +4, brutalist-flat +176, luxe +235, editorial +251; each at most 260 B above
its earlier size): editorial 2,281 -> 2,532, glass-dark 2,613 -> 2,617, organic-warm 2,607 -> 2,861, tech-mono 2,744 -> 2,999,
playful-pastel 2,890 -> 3,148, corporate-trust 3,041 -> 3,293, soft-saas 3,720 -> 3,972, bold-vibrant 3,480 -> 3,731,
brutalist-flat 3,012 -> 3,188, luxe 3,219 -> 3,454. `docs/token-usage.md` was regenerated.

### Dogfood harness

`Prepare::GUIDE_FILES` gained `07-kit-patterns.md`; `03-style-kit.md` is served with the run's language; TASK.md says "02..07"
(`docs/dogfood.md` already describes it).

### Blind dogfood

| Round | Pair | Score | Render |
|---|---|---|---|
| 2 (before) | neo-brutal x brutalist-flat / warm-pill-soft x organic-warm / glass-edge x glass-dark | 95.3% / 100% / 99.5% | two clean, one HTTP 500 (since fixed in the validator) |
| 3 (after) | raw-frame x brutalist-flat | 251/251 | clean |
| 3 (after) | glass-edge x glass-dark with the carriers brief | 209/209 | clean |

### Live ledger

- Full `make verify-kits`: 264 PASS 0 FAIL over 88 kit/language cells (run on main faf8a4e, 2026-10-25, about 3 hours: the first full run after the hover fix; the fix wave after it changed only guide text, language prose and tests, no kit code).
- P1 and P2 probes: recorded above. Plugin Check, `make verify-shapes` and the narrowed recipe render were not re-run by this docs task.
- Suites: free 6365, Pro 2169.

### Deviations and decisions

- Band roles live in `kit-rules` (Ruling 3), not in `kit-patterns`.
- The "kit wins" line is generated into every design-language topic (Ruling 4), not into the default guide (no bytes there).
- Ruling 6 was taken in full (the `design_language` parameter), not the fallback.
- Ruling 8 held: `kit-rules` fit its 9,600 pin (9,589), the pin was not raised.
- Review rewords beyond the plan: glass-dark's card-fill claim removed, the tag width wording ("a double at least 3px"), the
  hover-colour and button wording in languages.

### Known limits and gaps still open

- The 0px `invert` kits (flat-square, block-caps, pill-caps) keep no box on hover (48 cells, stated in `kit-patterns`); 4 tech-mono
  accent-band cells are faint.
- Not fixed: the accordion's gap between items, a preference between a nested row and a flex group for hero buttons, which `on` a card on
  a surface band takes, blurb chip sizing against the icon module, and accent-band hover values, which each kit topic describes only in prose.
- The recipes' old `builderVersion` values in untouched exports stay as they are.

### Owner decisions left

Merge and release; whether the 0px `invert` kits should get a hover rule; the gaps listed above.

