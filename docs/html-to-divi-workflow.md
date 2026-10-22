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

The font rule: the font stack is read left to right and the first family Divi can load (its Google font list) or that is a web-safe
system family (Arial, Helvetica, Georgia, Times New Roman, Verdana, Tahoma, Trebuchet MS, Courier New) is carried, so a brand font Divi
cannot load is listed and the next loadable family in the stack is used. A generic or system keyword (`sans-serif`, `serif`, `system-ui`,
`-apple-system` ...) ends the search: a browser always shows that one, so a family after it is never carried (`Inter, sans-serif` carries
Inter; `system-ui, Roboto` carries nothing, counted `generic_font`). When nothing is carried, the Divi page uses the theme's font.

A property set by a construct the converter cannot evaluate (a rule inside `@media`, a selector like `p.lead` or `.hero h1`, `var()`, `calc()`,
`clamp()`, the `font` shorthand, an effect such as `opacity` or `filter`) is never carried by guessing: it is counted and the property is left alone.

What "carried" promises: a carried value equals what a browser computes FOR THE CSS IN THE SUPPLIED HTML. External stylesheets
(`<link>`, `@import`) are counted and reported but not read, so a rule in them can override a carried value; with an external stylesheet the
white canvas a section gets may also not be what the browser shows. Readability still holds, because a colour is only ever written together
with the background it is printed on.

Things to know:

- Links inside text keep the theme's link colour. A text block that holds a link, `mark` or `code`, or that was merged from paragraphs with
  different colours, is not coloured, and the background of its section is dropped with it (colour and background travel together).
- Headings take their size and weight only from their own rules (they do inherit a colour); links and buttons take a colour only from their own rules.
- A `rem` size resolves against the root font size the page sets (16 px by default), an `em` size against the inherited size.
- CSS longer than 100 KB or with more than 2,000 rules turns ALL styles off for that page (the report says so). WordPress theme exports with
  large inline global styles hit this.
- External stylesheets (`<link rel="stylesheet">`, `@import`) are not fetched. The report says "N external stylesheet(s) not read"; the CSS that is in the HTML is still carried.
- Content after the end of the document (after `</html>`) switches styles off for that page (a repair note says so), because a browser would style it differently.
- A mobile-first stylesheet carries little: its desktop values live in media queries, which are not carried.
- Maximum width is written on rows only, not on sections or columns; a button's font, size and padding are not carried.
- No hover colours are written for buttons, so a hover can look different from the source.
- A heading carries a weight only when the source sets one; the browser's default bold is not copied.
- An unclosed `<p>` right before an HTML5 block (`section`, `article`, `nav`, `header`, `footer`, `aside`, `main`, `figure`) still swallows that
  block: the text is kept, but its heading becomes bold text and the section structure is lost, and the word check cannot see it (open issue
  I8; the parser follows the older HTML4 rules here; also in 4.10.0 to 4.13.0). Close the `<p>` to avoid it.
- A `</body>` or `</html>` written inside a script's text (for example in a JavaScript string) makes the rest of that script appear as page
  text and the next section's heading becomes bold text; the script itself is listed as removed, and with styles on a repair note fires and
  styles are off. Rare; remove the script first.
- Use `styles=none` for the plain structure and copy only.

## Limits

400,000 bytes of HTML per call, 5,000 elements, 50 levels deep (it says so and asks you to convert in parts). One page per call.
Verified on Divi 5.15.1: `make verify-html-to-divi` converts every sample page (including the 15 parser-repair pages `15-*` to `18-*`), saves them as drafts and renders them with the real Divi, and compares the six styled sample pages (colours, sizes, weights, alignment, fonts, backgrounds, padding, widths, button radius, contrast) with their source in a real browser at 1280, 900 and 400 px (final run: 517 PASS, 0 FAIL, no horizontal overflow). Only the properties the converter carries are measured.
