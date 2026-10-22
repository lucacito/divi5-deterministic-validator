# Free 4.14.0 release draft (NOT applied: no version bump, no zip rebuild, nothing pushed)

Prepared 2026-10-21 from main after the rules and chips adapter, its guide polish and the small follow-ups. Applying it is the
owner's call (CLAUDE.md: no version bump, zip rebuild, push or release without the owner's say-so).

## readme.txt changelog entry (paste above `= 4.13.0 =`)

```
= 4.14.0 =
* New: style kits now also set the look of divider lines, the rules between list items and price columns, small tag labels and icon badges (line style, width and corners), so a dashed, dotted or heavy kit reads as one design all the way down.
* Improved: the guide now tells your assistant exactly how to write those by hand (widths, which settings to use, and that the kit wins when a design language says something different). Found by having fresh assistants build pages from the guide alone.
* Improved: in the built-in section patterns the thin rules and the icon badges follow the chosen kit. With the default look some lines are a little heavier and icon badges are squarer.
* New: `html_to_divi` now carries the HTML's basic colors, fonts, sizes, alignment, backgrounds and spacing (and a button's colors and corners) where Divi can show them, and drops any color that would make text hard to read. The report lists what was carried and what was left out.
* Fixed: `html_to_divi` could lose content or structure in a few HTML shapes: HTML5 sections placed after a stylesheet link, `<meta>` or `<style>` (the page was refused as empty or the first section was dropped), content after the closing `</html>` tag, a section following loose text at the start of a fragment, a section following loose text after the closing `</body>` tag, a section, header, footer or other block right after a paragraph that was never closed, and a script whose code contains `</body>`, `</html>` or another closing tag (its code showed up as page text). In the last four a heading could turn into bold text. The word and link checks now see all of it.
* Improved: verified on Divi 5.15.1.
```

## readme.txt upgrade notice (paste above `= 4.13.0 =` in `== Upgrade Notice ==`)

```
= 4.14.0 =
Style kits now also style divider lines, rules, tag labels and icon badges, so a page keeps one look. In the default look a few lines are slightly heavier and icon badges squarer. Nothing changes unless your assistant uses them.
```

## What a visible change looks like (so the changelog is honest)

Under editorial / quiet-solid, 18 one-pixel `text` or `inverse` rules become 2px (faq-two-column 6, values-list 4, before-after-compare 2,
pricing-two-plan-highlight 2, hero-image-scrim 1, quote-over-image 1, card-grid-4 2), three steps-horizontal dividers go from 2px to a 1px
quiet rule, and all nine icon chips take the kit's control radius (icon-values 5px to 4px; the round 999px badges of features-carousel-strip
become 4px squares). The two accent dividers (blog-feed, icon-values), step badges and timeline dots are unchanged.

## Already checked on main (2026-10-21)

Free suite 2782, Pro suite 2169, pure render-gate tests 114; Plugin Check on the dev copy: no errors; all 71 recipes rendered under two looks: 426 PASS; full `make verify-kits` 263/264 (one hover-timing miss, hardened afterwards; smoke 30 PASS and 18 PASS); two dogfood rounds (six freehand pages and one edit) validated, rendered and followed their kit. Not done: Plugin Check on the extracted zip (needs the version bump and the zip build).

## Checklist (owner-run steps, in the order of the earlier releases)

1. Version: `wp-plugin/jhmg-ai-editor-for-divi-5.php` (header + `AI_EDITOR_DIVI5_VERSION`) and `wp-plugin/readme.txt` (Stable tag, the two entries above) to 4.14.0. `Tested up to` only in readme.txt.
2. Re-read the readme's claims against `tests/WpOrgComplianceTest.php` (it pins the tool list, the block-type number and the allowed hosts); this release adds no tool, route or host.
3. `make test` and `make test-pro`; `bash scripts/build-plugin-zip.sh` (the tracked root zip is the 4.13.0 build until then).
4. Plugin Check (Docker env) on the dev copy and on the extracted zip; never `wp plugin delete` a test copy.
5. SVN: sync trunk from the tracked zip, commit, `svn copy trunk tags/4.14.0`. The SVN password is never stored: the owner types it.
6. Pro add-on: unchanged (0.12.1 works with the free plugin; no `Compat::MIN_FREE` change needed).
7. Optional: the divi5lab.com copy that lists what style kits cover (buttons, cards, accordions, toggles, forms, image corners) can add rules, dividers and chips.

## Not in this release

Blurb, testimonial and counter adapters; a `rule-accent` role; heading and text typography per kit; table-cell rules; `divi/signup`.

## html_to_divi styles (phase 2) and the six parser fixes: what the owner has to do (NOT applied)

Built on `feat/dogfood-and-html-styles`; spec and Outcome `docs/superpowers/specs/2026-10-22-html-to-divi-styles-design.md`; owner-facing description `docs/html-to-divi-workflow.md`.
The two changelog lines above (New: styles, Fixed: parser) are the user-facing wording. Telling the owner plainly: the fixes cover bugs that were already published in 4.10.0 to 4.13.0
(HTML5 elements after head content, content after `</html>`, loose text at the start of a fragment, loose text after `</body>`, an unclosed
`<p>` before a block (I8) and a closing tag inside a script's code (I9); all six are fixed). The word check used to say "equal" in these cases
because the lost content was never counted or the structure was lost with every word kept.

### Fixed late (I8 and I9, branch feat/hs-i8)

- I8 and I9 add the fixtures `19-unclosed-p-*` (4) and `20-script-end-tag-*` (3). The gate with them at 757b00a: `make verify-html-to-divi` 615 PASS, 0 FAIL on Divi 5.15.1 at 757b00a (the I8/I9 merge, all 41 sample pages including the 22 parser-repair fixtures `15-*` to `20-*`); the review round after it is re-run by the controller at release.
- Still differing from a browser (reported): a formatting tag left open around such a block (styles off, repair note), a `<style>` whose
  CSS holds `</` and a letter, and a script whose `<!--` is followed by `<script` (styles off, repair note). Not reported: a list item or a
  table behind an open `<span>` in a paragraph, an unusual tag such as `<ruby>` in between, and pages the pre-parse step does not model
  (a duplicate attribute, a character reference in class or style, `@click`-style attribute names, nesting past 255 levels): those keep the old
  reading (spec Outcome, known limits).

### Lines of `wp-plugin/readme.txt` that still say colors and fonts are not converted (change them in the release commit)

`tests/HtmlToDiviDocsTest.php` excludes `readme.txt` on purpose and fails if one of these lines still says it and is not listed here. After applying, add the readme to that test.

- line 82 (the tool bullet in the feature list). Proposed: "`html_to_divi`: turn HTML you already have (a page your assistant or another tool wrote) into a Divi 5 layout: the structure, all the copy, the links and the images, plus the basic colors, fonts and spacing where Divi can show them and the text stays readable. It previews by default and saves a new draft only when asked. Scripts, forms, embeds and site navigation are never converted and are listed in a report (available over MCP and the REST API, not in the ChatGPT Action)."
- line 246 (FAQ "Can it turn my HTML page into a Divi page?"). Replace "Scripts, forms, embedded videos, site navigation and CSS are never converted, colors and fonts are not converted yet, and images become placeholders" with "Scripts, forms, embedded videos, site navigation and CSS code are never converted; it carries the basic colors, fonts and spacing of the HTML where Divi can show them, and never a color that would make text hard to read; images become placeholders". Optionally add: "Linked stylesheets are not fetched, so a page that keeps its styling in an external file comes out plainer."
- line 302 (the 4.10.0 changelog entry): it was true when written. Leave the history as it is (preferred), or drop its last sentence "Colors and fonts are not converted yet." if you want no stale claim anywhere; the 4.14.0 entry above says what changed.

### Before you release

1. The gate: `make verify-html-to-divi` 615 PASS, 0 FAIL on Divi 5.15.1 at 757b00a (the I8/I9 merge, all 41 sample pages including the 22 parser-repair fixtures `15-*` to `20-*`); the review round after it is re-run by the controller at release.
2. `HtmlToDiviLockstepTest` and `WpOrgComplianceTest` pin the descriptions, the schema and the font-list entries; the readme claims are re-read against them (checklist item 2 above).
3. The font list `wp-plugin/data/google-fonts-names.txt` ships in the zip (1,945 names, a data file read locally); nothing remote, no new host.
4. Known limits to keep out of any promise: links inside text keep the theme's link color, no hover colors for buttons, linked stylesheets are not read (a rule in them can override a carried value), a mobile-first stylesheet carries little, CSS over 100 KB or 2,000 rules turns all styles off for the page (WordPress theme exports with big inline global styles hit it), and a font stack with a generic or system keyword before the first loadable family carries no font (the Bootstrap stack).
