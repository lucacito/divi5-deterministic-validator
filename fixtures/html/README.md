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
