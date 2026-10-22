# HTML Conversion Test Fixtures

Test fixtures for `html_to_divi` converter. Each tests a specific scenario:

| File | Scenario | Expected behavior |
|------|----------|-------------------|
| 01-landing.html | Landing page with hero, features, CTA | Converts to sections/rows/text/button modules |
| 02-blog-article.html | Blog post with heading hierarchy, formatting | Heading levels preserved, text formatting kept |
| 03-pricing-cards.html | 3 pricing cards (repeated structure) | Cards become one row with 3 columns |
| 04-feature-grid.html | Feature grid (6 items) | Chunked into 2 rows of 3 columns |
| 05-faq.html | FAQ sections | Each section becomes a text block |
| 06-footer-fragment.html | Footer only (no body wrap) | Converts as-is, treated as content |
| 07-nested-divs.html | Deep div nesting, unnecessary wrappers | Unwraps to actual content depth |
| 08-forbidden-content.html | Script, form, onclick, iframe, data: URLs | Stripped; violations in report |
| 09-table.html | HTML table | Table markup preserved in text module |
| 10-unicode-rtl.html | UTF-8, emoji, RTL text, entities | All text preserved exactly |
| 11-empty.html | No content | Refused; plain reason in error |
| 12-large.html | 395KB HTML (near 400KB cap) | Converts successfully |
| 13-oversized.html (if needed) | Over 400KB | Refused; exact size reason |
| 13-images.html | Hero, logo, team photos (external, relative, data: URI), a linked image, a `<picture>`, an inline SVG | Every image becomes a built-in placeholder (third-party addresses never reach the layout); alt text and links kept; data: URI and SVG reported |
| 14-styled-inline.html | A coffee shop page styled only with `style=""` (dark hero, white heading, pill button; a centred 720px container) | The `styles=none` output ignores every style; with `basic` the inline colours, sizes, padding and button are the source of truth |
| 14-styled-classes.html | One `<style>` block: tag, `.class`, `#id` and comma-list rules, specificity battles, `body` font and `2em` headings, a three-card grid on a grey `.features` section, a `.btn` | Cascade and inheritance (`basic`); `none` ignores the block |
| 14-styled-dark.html | A dark theme page (`body` background and light text), one `#1e293b` panel, accent buttons and a last section whose text is `#475569` on `#0b1120` (2.49:1) | The invisible-text hazard: the unreadable colour is dropped and listed (`basic`) |
| 14-styled-backgrounds.html | A gradient section, a `background-image`, a carried `#123456`, an `rgba()` background, and a black paragraph inside a light section | Gradients and images are not carried; the readability rule decides what paint travels with which ink |
| 14-styled-fonts.html | `@import`, `@font-face`, Playfair Display headings, `"Brand Sans", Inter` body, Georgia, `system-ui`, and a `pre` | Only fonts Divi can load are carried; the rest are listed |
| 14-styled-hostile.html | `!important`, `var()`, `calc()`, `clamp()`, `@media`, `a:hover`, `.hero h1`, `[data-x]`, an unclosed `{`, `900px`, negative padding, the `font` shorthand, `bgcolor`, `<center>`, upper-case hex, `RebeccaPurple`, `opacity` | Anything not readable with certainty is left out and reported, never guessed |
| 15-head-style-section.html | A `<style>` block, then an HTML5 `<section>`, then a `<div>` (no html/head/body tags) | libxml2 keeps the `<section>` inside `<head>`; it is moved into the body, so no text is lost |
| 15-head-link-section.html | A `<link rel=stylesheet>` then a `<section>` | Was refused as "no content"; the section is moved into the body |
| 15-head-meta-header.html | A `<meta charset>` then `<header>`, `<main>`, `<footer>` | Was refused as "no content"; all three move into the body in order |
| 16-after-html-section.html | A complete page, then a `<section>` after `</html>` | libxml2 makes a second `<html>` node nothing read; the section is moved to the end of the body, as a browser does |
| 16-after-html-body-close.html | A `<section>` between `</body>` and `</html>` | libxml2 leaves it as a sibling of `<body>`; it is moved to the end of the body |
| 16-after-html-second-document.html | Two full `<html>` documents, then loose text | The second document's body content and the loose text are appended to the first body; the second `<title>` is not used |
| 17-leading-style-loose.html | A `<style>` block first, then a `<section>`, loose text and a second `<section>` (no html/head/body tags) | libxml2 was still in head mode and wrapped the loose text in an implied `<p>` that swallowed the second section (its heading became bold text); an explicit `<body>` is now written where a browser starts it |
| 17-leading-meta-inline.html | `<meta>`, `<link>` and `<title>` first, then `<header>`, text with `<b>` and `<a>`, `<main>`, a character reference and `<footer>` | Same mechanism with inline elements and a reference between the blocks: every heading stays a heading |
| 17-leading-comment-text.html | A comment first, then loose text before an `<article>`, a comment and loose text before an `<aside>` | A comment (or nothing at all) before loose text also left libxml2 without a body: the text no longer swallows the article |
| 17-leading-script-template.html | `<script>`, `<noscript>`, `<template>` and `<base>` first, then sections with a `<span>` and loose text between | A guard: libxml2 already opened the body at the `<noscript>`; the extra `<body>` must change nothing |
| 18-after-body-loose-section.html | A full page, then loose text and a `<section>` after `</body>` (before `</html>`) | libxml2 closed the body and wrapped the loose text in an implied `<p>` that swallowed the section (its heading became bold text); the `</body>` and `</html>` end tags content follows are now removed before parsing, as a browser ignores them |
| 18-after-body-html-text-section.html | Loose text and a `<section>` after `</html>` | Same mechanism after `</html>` (the scan's `after` note stays, styles stay off) |
| 18-after-body-inline.html | Text with `<b>` and `<a>` after `</body>`, then a `<section>` | Inline elements between the loose text and the section: every heading stays a heading |
| 18-after-body-two-bodies.html | A second `<body>` after `</body>`, starting with loose text | The second body's content follows the first one's, as in a browser |
| 18-after-body-lookalikes.html | `</body></html>` inside an attribute value and a comment, then a real `</body>` with loose text and a section after it | Only the real end tag is removed; the look-alikes stay text |

`14-styled-inline.html`'s second section is the DELIBERATE mixed-style run: two consecutive `<p>` (one unstyled, one with its own colour and size) that the mapper merges into one text module (`14-styled-hostile`'s table plus `<center><p>` is another). Later tasks must carry only the style properties that are equal across every merged element, otherwise nothing for that property and a counted note "mixed"; `testTheMixedStyleRunStaysInTheInlineFixture` keeps the case from being fixed away.

The `14-styled-*` goldens: `tests/golden/html-to-divi/14-styled-*.none.layout.txt` were recorded with `styles=none` on the converter before any style code existed (`AIED_UPDATE_NONE_GOLDEN=1`); they must never change.
