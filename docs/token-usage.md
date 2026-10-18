# Token usage of the AI Editor for Divi 5 (free plugin)

Measured 2026-10-18 by `scripts/measure-tokens.php`. Tokens are ESTIMATED as bytes / 4; the real count depends on the model.

Checking a layout runs in PHP on your server and costs **0 tokens**. What costs tokens is what the assistant has to read.

| What | Detail | Bytes | Approx. tokens |
| --- | --- | ---: | ---: |
| Tool definitions | 21 tools, loaded once per conversation (free plugin only) | 20,801 | 5,201 |
| Reading a page (smallest) | the page's layout content (get_page_layout adds a little wrapper), from page-17-variable-heading.json | 1,152 | 288 |
| Reading a page's outline (smallest) | get_page_layout with view "outline" on the same page: 5 blocks, each with path, name, size and the start of its text | 358 | 90 |
| Reading a page (median) | the page's layout content (get_page_layout adds a little wrapper), from page-45-layout-3.json | 49,842 | 12,461 |
| Reading a page's outline (median) | get_page_layout with view "outline" on the same page: 97 blocks, each with path, name, size and the start of its text | 8,112 | 2,028 |
| Reading a page (largest) | the page's layout content (get_page_layout adds a little wrapper), from page-85-layout-4.json | 66,095 | 16,524 |
| Reading a page's outline (largest) | get_page_layout with view "outline" on the same page: 117 blocks, each with path, name, size and the start of its text | 9,298 | 2,325 |
| A refusal: one unknown block | 2 violations (the second lists the allowed child types) | 1,741 | 436 |
| A refusal: every block wrong (worst case) | 96 violations on the median page, as sent now (the first 25, repeated allowed-type lists by reference) | 5,682 | 1,421 |
| A refusal: every block wrong (before the compact form) | the same 96 violations, each with its own full list (how it was sent until 4.12.0) | 21,791 | 5,448 |
| A refusal: 96 badly nested blocks | 96 violations, as sent now | 8,085 | 2,022 |
| A refusal: 96 badly nested blocks (before the compact form) | the same 96 violations, each with the full allowed list | 143,650 | 35,913 |
| A preview (dry run) of a one-line text change | verdict plus a capped diff, on the median page | 394 | 99 |
| The style guide (default) | get_style_guide with no topic, read once before building a layout | 28,404 | 7,101 |
| The style guide, topic design-languages | get_style_guide with topic "design-languages", only when asked for | 698 | 175 |
| The style guide, topic design-language:editorial | get_style_guide with topic "design-language:editorial", only when asked for | 1,669 | 418 |
| The style guide, topic design-language:glass-dark | get_style_guide with topic "design-language:glass-dark", only when asked for | 1,931 | 483 |
| The style guide, topic backgrounds | get_style_guide with topic "backgrounds", only when asked for | 8,881 | 2,221 |
| The style guide, topic dividers | get_style_guide with topic "dividers", only when asked for | 8,767 | 2,192 |
| The style guide, topic motion | get_style_guide with topic "motion", only when asked for | 8,690 | 2,173 |
| The style guide, topic hover-states | get_style_guide with topic "hover-states", only when asked for | 5,637 | 1,410 |
| The recipe catalog (default) | get_section_recipes with no filter: names under role headings, plus how to fetch one | 2,103 | 526 |
| The recipe catalog (filtered, role hero) | get_section_recipes with role "hero": the one-line description of each matching recipe | 254 | 64 |
| A served recipe (hero-cta, default look) | get_section_recipes with name "hero-cta" and no design_language | 2,835 | 709 |
| A served recipe (hero-cta, glass-dark) | the same recipe with design_language "glass-dark" (tokens filled in; the size is the markup alone) | 2,808 | 702 |

A refusal used to repeat the full list of allowed block types in every violation. Now the first violation about a parent keeps the list, the others point back to it, and at most 25 are listed (one entry counts the rest).

To read a page cheaply, ask get_page_layout for view "outline" (every block's path, name, size and the start of its text), then view "block" with a path for one block's exact markup.

Tokens the assistant WRITES also count: sending a whole layout to update_page_layout costs roughly the size of the page read. edit_page_content avoids that (and a dry_run of it sends only the find-and-replace pair).

The style guide topics, the recipe catalog and a filtered catalog are read only when asked for; the default catalog is names only, and one recipe is fetched by name (with an optional design_language that fills in its colors and fonts without changing its size much).

The Pro add-on adds its own tool definitions on top of the first row.
