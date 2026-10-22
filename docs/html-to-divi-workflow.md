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
| CSS code (`<style>`, `style=""`, classes) | Same: CSS is read, never copied | With `styles=basic` (the default) the basic colors, fonts, sizes, alignment, padding and widths are carried where Divi can show them and the text stays readable; `report.styles` lists what was carried and what was left out. Finish the look with the style guide |
| Forms, iframes, embedded video/audio, SVG graphics | Divi needs its own module or a form plugin | Removed and listed; the assistant tells you what to add |
| Navigation menus | The site owner sets menus in WordPress | Listed in the report |
| Images | A third-party address never reaches the layout | Every image becomes a built-in placeholder; the report lists the original so it can be replaced with your own |

Not a fit: a pixel-accurate copy of a live web page (use the separate URL-to-Divi converter) or JavaScript-rendered pages.

## Limits

400,000 bytes of HTML per call, 5,000 elements, 50 levels deep (it says so and asks you to convert in parts). One page per call.
Verified on Divi 5.14: `make verify-html-to-divi` converts 13 sample pages, saves them as drafts and renders them with the real Divi.
