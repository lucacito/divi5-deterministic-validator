# Turn HTML into a Divi 5 page: `html_to_divi`

For the owner. What it does, how to ask for it, and what it will not do.

## What it does

Your AI assistant (or you) has some HTML: a page the assistant wrote, markup exported from another tool, a static site. The
`html_to_divi` tool turns it into a Divi 5 page without the assistant retyping it as Divi code:

- sections for the page's landmarks (header, sections, footer);
- rows with 2 to 4 columns where the HTML has a group of similar cards (features, pricing plans, team members), stacking on phones;
- headings (one main heading per page: extra `h1`s become `h2`), text with its formatting and links, buttons, images, dividers, tables, lists;
- every word and every link, checked and reported (`text_check`, `links`).

It is deterministic: the same HTML gives the same page every time, there is no AI and no network call in it, and the result goes
through the same validator as every other save. It previews by default and saves a **draft** only when asked.

## Ask for it

Paste the HTML to Claude (or any connected assistant) and say, for example:

> Convert this HTML to a Divi 5 page with `html_to_divi`. Show me the report first. Then create it as a draft called "Our team",
> replace the placeholder images with images from my Media Library, and style it to match my site's colors.

The assistant previews, tells you what was not converted, creates the draft, swaps the images and styles it (the style guide and
your global colors do that part).

## What it will not do

| Not converted | Why | What happens |
|---|---|---|
| Scripts, `onclick` and other handlers, `javascript:` links | The plugin never saves custom code | Removed and listed in the report |
| CSS that Divi cannot show or that would make text hard to read | The converter carries only a proven subset | Gradients, images, media queries, hover, borders, shadows, line height and the rest are counted in `report.styles.ignored`; colours that would be unreadable are dropped and listed |
| Forms, iframes, embedded video/audio, SVG graphics | Divi needs its own module or a form plugin | Removed and listed; the assistant tells you what to add |
| Navigation menus | The site owner sets menus in WordPress | Listed in the report |
| Images | A third-party address never reaches the layout | Every image becomes a built-in placeholder; the report lists the original so it can be replaced with your own |

Not a fit: a pixel-accurate copy of a live web page (use the separate URL-to-Divi converter) or JavaScript-rendered pages.

## What it carries (`styles=basic`, the default)

CSS is read, never copied: no `<style>`, `style=""` or class ever reaches the page. From inline `style=""` and simple `<style>` rules
(a tag, a `.class`, an `#id`, or a comma list of those) the converter carries, where Divi can show it:

- text colour and font family, size, weight and alignment of headings and text (a size above 32 px also gets a tablet and a phone step, and no size is written above 120 px);
- background colour and padding of sections, rows and columns, and the maximum width of a row (centred);
- a button's label colour, fill colour and corner radius.

The readability rule, in two sentences: colour and background travel together, so a colour is carried only when the background it is
printed on is carried too and the pair reaches the usual reading contrast (4.5 to 1, or 3 to 1 for large text, judged at the smallest size
the page will show on a phone); otherwise the colour is dropped, the theme's colour applies, and the report lists it with its ratio. A button
carries its fill and label as a pair or not at all, and a button with no fill of its own on a painted background becomes an outline button.

The font rule: the first family in the font stack that Divi can load (its Google font list) or that is a web-safe system family (Arial,
Helvetica, Georgia, Times New Roman, Verdana, Tahoma, Trebuchet MS, Courier New) is carried; a brand font Divi cannot load is listed and the
theme font stays.

A property that a browser would not apply (a rule inside `@media`, a selector like `p.lead` or `.hero h1`, `var()`, `calc()`, `clamp()`, the `font`
shorthand) is never carried by guessing: it is counted and the property is left alone.

Things to know:

- Links inside text keep the theme's link colour.
- External stylesheets (`<link rel="stylesheet">`, `@import`) are not fetched. The report says "N external stylesheet(s) not read"; the CSS that is in the HTML is still carried.
- Content after the end of the document (after `</html>`) switches styles off for that page (a repair note says so), because a browser would style it differently.
- A mobile-first stylesheet carries little: its desktop values live in media queries, which are not carried.
- Maximum width is written on rows only, not on sections or columns; a button's font, size and padding are not carried.
- No hover colours are written for buttons, so a hover can look different from the source.
- A heading carries a weight only when the source sets one; the browser's default bold is not copied.
- Use `styles=none` for the plain structure and copy only.

## Limits

400,000 bytes of HTML per call, 5,000 elements, 50 levels deep (it says so and asks you to convert in parts). One page per call.
Verified on Divi 5.15.1: `make verify-html-to-divi` converts the sample pages (19 at the live run; 10 parser-repair pages were added after it), saves them as drafts and renders them with the real Divi, and compares the six styled sample pages (colours, sizes, weights, alignment, fonts, backgrounds, padding, widths, button radius, contrast) with their source in a real browser at 1280, 900 and 400 px (391 checks pass, no horizontal overflow). Only the properties the converter carries are measured.
