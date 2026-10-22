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
("font Foo not available in Divi: kept the theme font"). Generic keywords (`sans-serif`, `serif`, `monospace`, `inherit`) are ignored.

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
