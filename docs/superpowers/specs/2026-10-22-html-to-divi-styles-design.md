# html_to_divi phase 2: carry colours, fonts and spacing from the source HTML

Status: APPROVED BY DELEGATION (owner 2026-10-21/22: "do #2 and #3, go autonomously"; every call below is a Ruling the owner may
overrule). Branch `feat/dogfood-and-html-styles` from main 7c1a679. Follows `2026-10-14-html-to-divi-design.md` (phase 1 shipped in 4.10.0:
structure, copy, links, images; its design already named `HtmlStyles` and the `styles` argument). No release, version bump or zip rebuild.
The validator (`src/`, `wp-plugin/validator/`) is untouched and deterministic; no new MCP tool, REST route or OpenAPI operation; no custom CSS
or code ever leaves the converter.

## Why

`html_to_divi` turns supplied HTML into a valid Divi 5 draft, but the result is unstyled: the plugin's own note says "colors, fonts and
spacing are not converted yet". The readme leads with "rebuild a page from a screenshot, the address of a page or its HTML", so the
converted draft is what a visitor to the plugin sees first, and today it looks like a bare theme page. The assistant can restyle it (that
is the intended path for an AI), but a deterministic style subset makes the mechanical part free and token-cheap: the colour of the headline,
the dark hero background, the button colours, the font, the padding that gives a section air.

## Goals, non-goals, anti-goals

Goals: with `styles=basic` (already the default) the converter carries a small, proven subset of the source's CSS into the layout: text
colour, background colour, font family, font size, font weight, text alignment, padding, max width, and a button's text colour, fill and corner
radius, resolved from inline `style=""` and simple `<style>` rules; the result still validates; `styles=none` output stays byte-identical to
today; the report says exactly what was carried and what was ignored and why; and **a converted page is never less readable than the source**.

Non-goals: gradients, background images, `calc()`/`var()`/`clamp()`, `@media`, `@font-face`/`@import`, hover or other pseudo states, flex or
grid reproduction, borders and shadows, line-height and letter-spacing, `text-transform`, floats and positioning, animations. All of them are
counted in the report as "ignored" with a reason. Pixel fidelity stays with `url-to-divi`.
Anti-goals: any custom CSS/JS/PHP in the output (a test scans every result with `CodePolicy`), any attribute path not proven ON its module,
a colour that makes text unreadable, a non-deterministic result, a weakened phase-1 test.

## Design

### 1. `HtmlStyles` (new, pure, `wp-plugin/src/`): a tiny deterministic CSS reader
- Input: the normalised tree of `HtmlSource` (each node keeps `class`, `id`, `style`) and the collected `<style>` text (never emitted).
- Rules: selectors limited to `tag`, `.class`, `#id` and comma lists of those; anything with a combinator, attribute selector, pseudo-class or
  pseudo-element, `@media`/`@supports`/`@font-face`/`@import`, `var()`, `calc()`, `clamp()`, `min()`/`max()`, `!important` handled (wins over
  normal), is skipped and counted. Specificity (id > class > tag), then source order; inline `style=""` wins over rules.
- Properties read: `color`, `background-color` (and `background` only when its whole value is one colour), `font-family`, `font-size`,
  `font-weight`, `text-align`, `padding` and `padding-top|right|bottom|left`, `max-width`, `border-radius` (buttons only).
- Inheritance: `color`, `font-family`, `font-size`, `font-weight`, `text-align` inherit down the tree (`font-size` with `em`/`%` resolves
  against the inherited size, default 16px; `rem` = 16px); `background-color`, `padding`, `max-width` do not.
- Values: colours `#rgb`, `#rrggbb`, `rgb()` without alpha, and the CSS named colours, normalised to lowercase `#rrggbb`; a colour with alpha
  (`rgba()`, `#rrggbbaa`, `transparent`) is NOT carried (counted "alpha ignored"; `transparent` means "no background"); lengths `px`, `rem`, `em`,
  `%` of the parent size only for font-size; sizes clamped (font 8-200 px, padding 0-200 px, max-width 200-2400 px).
- Output: a per-node resolved style record (only carried properties) plus the ignored counters. Deterministic, no randomness, no clock.

### 2. Effective background and the readability rule (Ruling 1)
- Each node has an effective background: its own carried background, else the nearest ancestor's, else the page default white. A background
  that is not a carried colour (image, gradient, alpha, unparsed) makes the effective background UNKNOWN below it.
- A text colour is carried only when the effective background is known and the contrast ratio is at least 4.5:1 (3:1 for large text: font
  size at least 24 px, or at least 19 px and bold). Otherwise the text colour is dropped (the theme colour applies), counted and listed in the
  report ("text colour dropped: unreadable on the carried background" / "on an unknown background"). The same check judges a button's text
  against its own fill.
- A background colour is carried on the block that maps to its element; if a page is dark (the body/html or a wrapper carries a dark
  background) every section inherits that background as its own carried background so the source's light text stays readable.
- The same rule is the property test's oracle: for 200+ seeded random styled trees, every carried text colour meets the floor against its
  effective background.

### 3. Where each property lands (every path must be proven ON the module)
Written only by `DiviMarkup` (the ONLY place that writes blocks), shapes taken from the style guide's proven topics, the shipped recipes and
the shape registry (`fixtures/shapes/verified-shapes.json`); anything the registry lacks or that is path-only for another module gets a probe
(`node scripts/verify-shapes.cjs`, candidates `fixtures/shapes/candidates/html-styles.json`, ids `hs-<decision>`) ON the module first. Targets:
heading (`divi/heading`: text colour, size, weight, align, family), text (`divi/text`: the same through its body font), button (`divi/button`:
text colour, fill, corner radius), section/row/column (background colour, padding, max width), image (none). Per-device: a font size above
32 px also writes a tablet value (75%) and a phone value (55%, at least 20 px) so a large headline does not overflow a phone (the per-device
shape is already proven by `make verify-guide-shapes`); every other property is desktop only. The exact paths are decided by the plan from
the proven topics, never invented.

### 4. Fonts (Ruling 3)
A `font-family` list is read left to right; the first family that is (a) in Divi's Google font list (`fixtures/shapes/google-fonts-names.txt`,
1,945 names, shipped as `wp-plugin/data/google-fonts-names.txt` and kept equal by a test) or (b) a web-safe family (Arial, Helvetica, Georgia,
Times New Roman, Verdana, Tahoma, Trebuchet MS, Courier New) is carried; a source family Divi cannot load is ignored and listed
("font Foo not available in Divi: kept the theme font"). (As built, see the Outcome: a generic or system keyword such as `sans-serif`,
`serif`, `monospace` or `system-ui` ENDS the search, since a browser always matches it, and nothing is carried (`generic_font`); a CSS-wide
keyword such as `inherit` is unresolved, never a family.)

### 5. Wiring
`HtmlToDivi` (styles branch) runs `HtmlStyles` after the mapper has the module plan and passes each module's resolved record to
`DiviMarkup`. `HtmlToDiviService`, `McpHandler` and `RestController` keep their schema; the `styles` description changes from "not converted
yet" to what is converted; the report gains a `styles` object: `{carried:{color,background,font,size,weight,align,padding,max_width,
radius}, ignored:{<reason>:count}, dropped_unreadable:[...] (capped), fonts:[{source,action}]}`; `notes` loses the "not converted yet" sentence.
`styles=none` remains the exact phase-1 output (pinned by the existing goldens). The default stays `basic`, so the goldens of the styled
default are regenerated deliberately (`AIED_UPDATE_GOLDEN=1`) and reviewed.

## Testing
- Unit: `HtmlStylesTest` (every property, specificity, inheritance, skipped constructs counted, colour forms, clamps, determinism).
- `HtmlStylesReadabilityTest`: the contrast rule against known pairs, the dark-page case (a dark body with light text keeps the text colour
  AND carries the background into every section), unknown backgrounds, large text threshold, button vs its fill.
- Fixtures `fixtures/html/14-styled-*.html` (inline styles, a `<style>` block with classes, a dark theme page, a page with gradients and
  background images, a page with web fonts and system fonts, a page with `!important`/`var()`/`calc()`); each: output validates, deterministic,
  `text_check.equal`, links preserved, no custom code (CodePolicy scan), golden markup, readability oracle.
- The property test (seeded generator over tags AND random styles) extends `HtmlToDiviTest::random*`: validates or refuses, never throws, no
  unreadable carried text.
- Grounding: every path `DiviMarkup` writes for styles is in the shape registry (and probed ON its module); `HtmlToDiviGroundingTest` extended.
- Phase-1 tests and `HtmlToDiviLockstepTest` stay green; `styles=none` goldens byte-identical.
- Live gate `make verify-html-to-divi` extended: every styled fixture is saved as a throwaway draft through the real handlers, validated and
  rendered by the real Divi 5.15.1; the rendered computed text colour, background colour, font size, weight and alignment of marked elements
  equal the resolved source style (after the readability rule); no horizontal overflow at 400 px; a screenshot pair (source HTML vs Divi
  render) of the dark fixture is written for review.

## Rulings (made by delegation; cost if wrong is a table entry)
1. *Readability first.* A colour is never carried if it would make text unreadable; the theme colour wins. Cost if wrong: a source colour
   scheme is not reproduced on an unknown background (reported, never silent).
2. *Desktop values only, plus the large-font tablet/phone steps.* Cost: a source that styles breakpoints is flattened (media queries counted).
3. *Fonts only if Divi can load them or they are web-safe.* Cost: a brand font is not carried (listed; the assistant can set it by hand).
4. *`styles=basic` stays the default.* Cost if wrong: a caller expecting bare output passes `styles=none`.
5. *Opaque colours only.* Cost: translucent overlays are not reproduced (counted).
6. *Alpha-free `background` shorthand only when the whole value is one colour.* Cost: shorthand with image/gradient is ignored (counted).
7. *The report grows a `styles` object, never a new tool or route.* Cost: none.

## Done means
Free suite green (phase 1 + new); `styles=none` goldens byte-identical; styled goldens reviewed; `make verify-html-to-divi` green with the
styled fixtures; `make verify-shapes` unchanged green; the plugin zip is NOT rebuilt; the Outcome records numbers; no CLAUDE.md claim stays
that "styles are not converted yet".

## Outcome (2026-10-22, written by Task 12)

Built on `feat/dogfood-and-html-styles` with the subagent-driven plan `docs/superpowers/plans/2026-10-22-html-to-divi-styles.md`; NOT released, no version bump, no zip rebuild, `wp-plugin/readme.txt` and the plugin header untouched, `src/` and `wp-plugin/validator/` untouched, no new tool, route or OpenAPI operation (`HtmlToDiviLockstepTest` pins the schema). The text above is the approved design; where it differs from this Outcome, THIS SECTION WINS (the plan's "Controller rulings" section recorded the same changes before the build).

### What was built

Five new pure units in `wp-plugin/src/` and two seams in phase-1 code, as the plan said:

- `HtmlCss` reads CSS text and values; everything it cannot evaluate becomes a poison marker (presence only), never a guess.
- `HtmlStyles` is the cascade and inheritance. Soundness: a carried value always equals what a browser computes at desktop width FOR THE CSS IN THE SUPPLIED HTML (external stylesheets, `<link>` and `@import`, are counted and reported but not read, so a rule in them can override); any construct that might change a property (combinators, compound selectors, `@media`/`@supports`/`@layer`/`@scope`, `var()`/`calc()`/`clamp()`, the `font` shorthand, effects such as `opacity`/`filter`/`transform`, presentational attributes, repaired markup) makes it unresolved. Checked against a real Chromium: 3,248 cases, 56,491 claimed values, all equal (residual non-blocking differences were MathML and `ruby` and were fixed in the last round).
- `HtmlFonts` picks a family only from Divi's Google list (`wp-plugin/data/google-fonts-names.txt`, 1,945 names, byte-equal to `fixtures/shapes/google-fonts-names.txt`) or the proven web-safe list: the first loadable family of the stack, read left to right, where a generic or system keyword (`sans-serif`, `system-ui`, `-apple-system` ...) ENDS the search (a browser always matches it, so a later family is never shown: nothing carried, reason `generic_font`; final-review fix) and a CSS-wide keyword (`inherit`, `initial`, `unset`, `revert`, `revert-layer`) makes the choice unresolved.
- `HtmlStyleBlocks` builds the per-block candidates (anchors, padding, max width, sizes, per-device steps, clamps) and `HtmlReadability` decides colours under one rule.
- Seams: `HtmlSource` hands over `css`, `inline`, `presentational`, `style_flags`, `external_css`, doctype/quirks and repair flags without keeping `style` in the DOM; `HtmlMapper::map($body, true)` records source paths (`from`, `ctx`, `column_src`). `false`, the default and the `styles=none` path, returns today's plan.
- `DiviMarkup` stays the only writer: an optional trailing `$style` array per block and `STYLE_PROVEN` (13 keys, all PASS), pinned to `fixtures/shapes/html-styles-vocabulary.md` ("Adapter decisions"). `report.styles` has the keys `carried`, `ignored`, `dropped_unreadable` (at most 25, `dropped_total` counts all), `fonts`, `clamped`, `notes`, `external`; it exists only with `styles=basic`.

### Decisions D1-D10 (as built)

- D1 Paint and ink travel together: a heading or text module gets a colour only on a block the converter paints, and every module on a painted block gets one (its declared colour, or the CSS initial `#000000`); a module that cannot be inked readably drops the paint of every block that contains it; a button carries fill and label as a pair or not at all, and an unpaired button on paint becomes a ghost (transparent fill; it keeps its own label when it has one, else the parent's ink).
- D2 Canvas paint: a section whose modules declare a text colour and that has no background on its anchor chain is painted `#ffffff`, the CSS initial canvas. It is counted `carried.canvas`, never `carried.background` (it is the converter's own assumption, not a source background; final-review fix). With an external stylesheet the canvas white may not be what the browser shows; readability still holds because paint and ink travel together.
- D3 Any carried wrapper, `body` or `html` background propagates to the sections (light or dark), so the readability check judges what is actually painted.
- D4 Soundness / poison as above; user-action states and pseudo-elements never poison; an unparsable selector is dropped as a browser drops it.
- D5 Headings take size and weight only from their own rules (they do inherit colour); links and buttons take colour only from their own rules (the browser's own sheet sets them; a button also its background, size, weight, family and alignment); `pre code kbd samp tt` take a family only from their own rules; `center` contributes `text-align:center` at the lowest priority.
- D6 Max width is written on rows only (no real export writes it on a section or a column).
- D7 Text alignment on `divi/text` is `module.advanced.text.text.{bp}.value.orientation` for left/center/right (`left` is never written) and the body-font `textAlign` for justify.
- D8 Values: colours lowercase `#rrggbb`; sizes, padding, max width and radius integer `"<n>px"`; weight `"100"`-`"900"`; clamps font size 8-120 px, padding 0-200, max width 200-2400, radius 0-999.
- D9 No new note: `report.notes` lost the "colors and fonts" sentence; `report.styles.notes` carries the style notes (degrade reasons, "N external stylesheet(s) not read", and `HtmlToDivi::NOTE_STYLES_FAILED` when a self-check of the style path throws `InvalidArgumentException`: the page is then converted exactly as with `styles=none`, never a 500; final-review fix). Properties an effect (`opacity`, `filter` ...) made unresolved are counted `effect` in `report.styles.ignored` (once per block and property; final-review fix).
- D10 Class names `HtmlCss`, `HtmlStyles`, `HtmlFonts`, `HtmlStyleBlocks`, `HtmlReadability`; test helper `tests/Support/StyledLayoutOracle.php`; probe candidates `hs-*`.

One change to the text of the design: the font size ceiling is **120 px**, not 200. The first live run found the hostile fixture (a 900 px source heading) overflowing a phone at 400 px (456 px wide: written 200/150/110 px). Ruling: ceiling 200 -> 120, phone step 66 px (`DiviMarkup`, `HtmlStyleBlocks`, the oracle and the gate agree; one golden regenerated).

### Task 1 verdicts (the probe, Divi 5.15.1, 1280/900/400)

20 candidates, 19 PASS, 1 DEAD; all 13 `STYLE_PROVEN` decisions PASS. The one DEAD is a finding: `orientation: justified` does nothing on `divi/text` (left, center and right work), while the body-font `textAlign` = `justify` works, so justify uses that path. `title...textAlign` = `justify` works on `divi/heading`; `Georgia` and `Courier New` work as families on heading and text; list/quote ink (`ul`, `ol`, `blockquote` in a `divi/text`) works; the row max width centres the row; row and column background, column and section padding PASS; a ghost button (transparent fill) works. The first run had one wrong control (`orientation: left` is not a no-op: Divi writes an explicit `text-align: left`), corrected, nothing dropped.

### Spec issues found by the plan and how the controller ruled (all eleven accepted as proposed)

1. The font list trips `WpOrgComplianceTest` (`\bPro\b`: "Maven Pro", "Crimson Pro"): two narrow, justified entries (a `COMMERCIAL_EXCEPTIONS` row scoped to that one file and " Pro", and a `LOCAL_READS` row) plus `HtmlFontsTest` pinning the file to the fixture and its only hits to family names. Nothing loosened, nothing evaded.
2. The phase-1 seam did not exist: added as the seams above (no rewrite of the mapper, byte identity of all phase-1 fixtures in both modes).
3. `HtmlToDiviGroundingTest` created (registry plus per-block proof for every style path).
4. "The theme colour wins" can print unreadable text: replaced by D1 and D2 (no assumption about the theme).
5. The dark-page rule generalised to D3.
6. A property an `@media` rule sets is not carried (the live gate compares at 1280 px): mobile-first CSS carries little.
7. Max width on rows only (D6).
8. Both text-align paths probed, orientation first (D7).
9. Compound selectors (`p.lead`, `a.btn`) stay poison only.
10. Headings and links do not inherit size, weight or colour (D5).
11. Radius clamped 0-999 px.

### Numbers

- Free suite: 2,798 tests after Task 1 (2,782 on main at the last release draft) to **5,273 OK** (1 skipped by design; 5,270 before this task's three docs tests); most of the growth is data-provider datasets (the cascade's 320 pinned browser datasets, 104 head-content cases, the 200-case property test). Property seeds 1-40 pass (`--filter RandomStyled`), seeds 1-4 for `--filter Random`. Pro suite untouched.
- Final-review fixes (branch `feat/hs-final-fix`): free suite **5,328 OK** (1 skipped by design); seeds 1-40 `--filter RandomStyled` and 1-4 `--filter Random` pass; no golden changed (the `*.none.layout.txt` goldens byte-identical, no styled golden changed by the font rule).
- Live gate on Divi 5.15.1: `make verify-html-to-divi` styled half **391 PASS, 0 FAIL** (first run 390 PASS, 1 FAIL: the 456 px overflow above; rerun after the ceiling change 34 s). Final run with all 34 sample pages (the 15 parser-repair fixtures `15-*` to `18-*` included; the styled half measures the six `14-styled-*` pages): **517 PASS, 0 FAIL**, at the I7 head 4e057aa; the final-review fixes after it change no golden layout (only report counters and the font choice for stacks no fixture has). The dark fixture's screenshot pair (`dist/html-to-divi/14-styled-dark.{source,divi}.png`) was reviewed: dark sections stay dark with readable text, buttons carried, the 2.48:1 fine print left unpainted on the white canvas, headings lighter than the browser's default bold (a weight is written only when the source sets one).
- Tool definitions (`php scripts/measure-tokens.php`, `docs/token-usage.md`): 21,601 -> **21,883 bytes** (+282; 5,401 -> 5,471 estimated tokens). The `html_to_divi` description itself went 1,256 -> 1,368 bytes (+112) and the `styles` description to 285 characters (bound 300). The same re-measure refreshed the `kit-rules` row (7,302 -> 8,997 bytes, a change of the rules and chips polish, not of this work).
- Mutation checks (Task 10): nine planted faults (contrast floor 1.0 and 3.0, ink without paint, paint without ink, half a button pair, large-text edge at the desktop size, styles on in `none`, no size clamp, a ghost never checked) were all caught by the oracle, the property test or the fixture tests.

### Phase-1 bugs found and fixed on this branch (shipped in 4.10.0-4.13.0; TELL THE OWNER, they go into the release notes)

Found by the styles work (the property test and the new fixtures); in every case `text_check` still said "equal" because the lost content was never counted as lost.

- I4: HTML5 elements (`section`, `header`, `main`...) that follow head content (`<link>`, `<meta>`, `<style>`) were kept by libxml inside `<head>`, so the page was refused as "The HTML has no content" or the first section was silently dropped. Fix: `HtmlSource::relocateHeadContent` (fixtures `15-head-*`).
- I5: content after `</html>` (a second `<html>` node, or a section between `</body>` and `</html>`) was silently lost. Fix: moved to the end of the body, as a browser does (fixtures `16-after-html-*`). Styles stay off on such a page (the repair note), by ruling: fail closed.
- I6: a fragment that starts with head content (or with loose text) followed by loose text made libxml open an implied `<p>` that swallowed the next section (its heading became bold text, three sections became two). Fix: `HtmlSource::bodyStart` writes an explicit `<body>` where a browser starts it (fixtures `17-leading-*`).
- I8 (FIXED, branch feat/hs-i8): an unclosed `<p>` right before a block swallowed it (`<p>Intro<section><h2>Head</h2><p>Body</p></section>` gave one text module: the heading became bold text, `text_check` could not see it). A browser closes a p at the start tag of a block whenever a p is in button scope; libxml2 2.9 never does for the HTML5 blocks it does not know (section, article, nav, header, footer, aside, main, figure, figcaption, details, summary, hgroup, search: measured element by element), and for the blocks it knows (div, h1-h6, ul, ol, blockquote, pre, hr ...) only when the p is the current node, not through an open span or b. Fix: `HtmlSource::paragraphEnds()`, a pre-parse step in `browserBody()`: the candidate start tags are found in markup context (`markupWalk()`), a comment is placed before each and libxml2 parses the page; a comment's ancestors are libxml2's open elements at that tag (libxml2 never moves a node), so a `</p>` is written where only plain phrasing elements stand between the tag and a p that libxml2 kept open; a second parse checks the open elements at every candidate, and at a difference (a page `</p>` that now matches nothing) the earlier ones are kept and the rest is decided again (`P_WORK` bounds it). Order and text are preserved by construction (a `</p>` adds and moves no text). 540 Chromium 149 trees pinned (`tests/Support/html-paragraph-close-cases.php`, 27 blocks x 5 shapes x 4 contexts), a seeded property test (`testRandomOpenParagraphsNeverHoldABlock`, seeds 1-40) and family pages added after the 200 cases of both random generators; 62 cases of the style corpus re-recorded (488 newly claimed values equal to Chromium's computed style). Fixtures `19-unclosed-p-*` (4, new goldens only).
- I9 (FIXED, branch feat/hs-i8): a `</body>`, `</html>` or `</div>` inside a script's text (`<script>var s="</body>";</script>`) ended the script early in libxml2 (it ends script and style text at the first `</` and a letter, a browser only at `</script`): the rest of the script appeared as page text and the next heading as bold text. Fix: `HtmlSource::inertRawText()` writes `</` as `<\/` inside script and style text before parsing (the same value in a JavaScript or CSS string; one forward pass in markup context, fail closed on what it does not model, and on a script holding `<!--`). The script is dropped and reported as before; the scan still reports `rawtext` (styles off). Fixtures `20-script-end-tag-*` (3, new goldens only).
- I7 (FIXED, commit c6756f6, merged; live gate green with its fixtures): loose text after `</body>` or `</html>` (`<body><section>A</section></body>Loose<section>B</section>`) had the same implied-`<p>` swallow. `HtmlSource::strayEndTags()` removes the real `</body>` and `</html>` end tags that content follows before parsing (one forward pass that tells markup from text; anything it does not model leaves the HTML as given), after `bodyStart()` has put the explicit `<body>`; Chromium's DOMParser builds the same tree with or without those end tags. Fixtures `18-after-body-*` (5, new goldens only). The `after` repair note is unchanged, so styles stay off for content after `</html>`; a page with content after `</body>` but nothing after `</html>` never had that note and now follows the browser's tree. Head elements of a second document now sit in the body, as in a browser.

### Known limits (all reported by the converter, never silent)

- Links inside text keep the theme's link colour; so do code, `mark`, `pre`, tables and definition lists in text (the text is not inked under paint, so its paint is dropped). Merged paragraphs with different colours likewise drop the section's background (paint and ink travel together).
- A carried value equals what a browser computes for the CSS IN THE SUPPLIED HTML only: an external stylesheet is counted but not read and can override it, and with one the D2 canvas white may not be what the browser shows (readability holds: paint and ink travel together).
- CSS over 100 KB or 2,000 rules turns ALL styles off for the page (`css_cap`, one note); WordPress theme exports with large inline global styles hit it.
- A font stack whose first loadable family comes after a generic or system keyword carries no family (`generic_font`): the Bootstrap stack `system-ui, -apple-system, "Segoe UI", Roboto, sans-serif` carries nothing.
- External stylesheets (`<link rel="stylesheet">`, `@import`) are not fetched. They are counted and the report says "N external stylesheet(s) not read"; inline and `<style>` CSS is still carried (switch `HtmlStyleBlocks::DEGRADE_ON_EXTERNAL`, default false, by ruling).
- Compound and combinator selectors (`p.lead`, `a.btn`, `.hero h1`) are poison only: a property they might set is not carried. A mobile-first stylesheet carries little because its desktop values live in media queries.
- Column and section max width are not written (rows only); a button's family, size and padding are not carried; a card grid's row padding goes on the first row (top) and the last row (bottom) when it is split.
- No hover colours are written for buttons or ghost buttons (M5, ruled): a hover can differ from the source and could be less readable than the rest state.
- A heading carries a weight only when the source sets it; a `rem` size resolves against the root font size the page sets (16 px by default), an `em` size against the inherited size.
- Content after the end of the document keeps styles off (repair note, fail closed); a second `<body>` or `<html>` start tag loses its attributes.
- Only properties the converter carries are measured live (the gate does not judge what it does not write); alpha and opacity on ancestors are not modelled by `hs-contrast`.
- After I8: a page `</p>` that matches nothing once its paragraph was closed at a block is ignored (libxml2), where a browser makes an empty `<p>` of it; a formatting element (b, em, a ...) left open around the block is opened again inside it by a browser and not here (repair note `reopen`, styles off). A paragraph whose block sits behind an element that is not plain phrasing (a button, object, select, table cell ...) is left as libxml2 builds it, and so is a page `paragraphEnds()` does not model (an odd comment, CDATA, a script holding `<!--`, xmp ...).
- After I9: a style element whose text holds `</` stays unread (the scan's `rawtext` note), and textarea and title text with an end tag in it is left as given.

### The owner's release-time readme changes (NOT applied)

`wp-plugin/readme.txt` still says "colors and fonts are not converted yet" on lines 82, 246 and 302; `tests/HtmlToDiviDocsTest.php` excludes the readme on purpose and checks that `docs/release-4.14.0-draft.md` names each such line. The proposed replacement sentences and the changelog lines (styles and the parser fixes, in user-facing wording) are in that draft.
