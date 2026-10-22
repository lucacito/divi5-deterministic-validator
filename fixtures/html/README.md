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

The `14-styled-*` goldens: `tests/golden/html-to-divi/14-styled-*.none.layout.txt` were recorded with `styles=none` on the converter before any style code existed (`AIED_UPDATE_NONE_GOLDEN=1`); they must never change.
