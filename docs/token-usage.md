# Token usage of the AI Editor for Divi 5 (free plugin)

Measured 2026-10-25 by `scripts/measure-tokens.php`. Tokens are ESTIMATED as bytes / 4; the real count depends on the model.

Checking a layout runs in PHP on your server and costs **0 tokens**. What costs tokens is what the assistant has to read.

| What | Detail | Bytes | Approx. tokens |
| --- | --- | ---: | ---: |
| Tool definitions | 21 tools, loaded once per conversation (free plugin only) | 22,226 | 5,557 |
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
| The style guide (default) | get_style_guide with no topic, read once before building a layout | 28,975 | 7,244 |
| The style guide, topic design-languages | get_style_guide with topic "design-languages", only when asked for | 2,588 | 647 |
| The style guide, topic design-language:editorial | get_style_guide with topic "design-language:editorial", only when asked for | 2,532 | 633 |
| The style guide, topic design-language:glass-dark | get_style_guide with topic "design-language:glass-dark", only when asked for | 2,617 | 655 |
| The style guide, topic design-language:organic-warm | get_style_guide with topic "design-language:organic-warm", only when asked for | 2,861 | 716 |
| The style guide, topic design-language:tech-mono | get_style_guide with topic "design-language:tech-mono", only when asked for | 2,999 | 750 |
| The style guide, topic design-language:playful-pastel | get_style_guide with topic "design-language:playful-pastel", only when asked for | 3,148 | 787 |
| The style guide, topic design-language:corporate-trust | get_style_guide with topic "design-language:corporate-trust", only when asked for | 3,293 | 824 |
| The style guide, topic design-language:soft-saas | get_style_guide with topic "design-language:soft-saas", only when asked for | 3,972 | 993 |
| The style guide, topic design-language:bold-vibrant | get_style_guide with topic "design-language:bold-vibrant", only when asked for | 3,731 | 933 |
| The style guide, topic design-language:brutalist-flat | get_style_guide with topic "design-language:brutalist-flat", only when asked for | 3,188 | 797 |
| The style guide, topic design-language:luxe | get_style_guide with topic "design-language:luxe", only when asked for | 3,454 | 864 |
| The style guide, topic backgrounds | get_style_guide with topic "backgrounds", only when asked for | 8,894 | 2,224 |
| The style guide, topic dividers | get_style_guide with topic "dividers", only when asked for | 8,869 | 2,218 |
| The style guide, topic motion | get_style_guide with topic "motion", only when asked for | 9,800 | 2,450 |
| The style guide, topic hover-states | get_style_guide with topic "hover-states", only when asked for | 5,637 | 1,410 |
| The style guide, topic layout-advanced | get_style_guide with topic "layout-advanced", only when asked for | 9,973 | 2,494 |
| The style guide, topic layout-sizing | get_style_guide with topic "layout-sizing", only when asked for | 9,972 | 2,493 |
| The style guide, topic typography | get_style_guide with topic "typography", only when asked for | 7,943 | 1,986 |
| The style guide, topic imagery | get_style_guide with topic "imagery", only when asked for | 6,506 | 1,627 |
| The style guide, topic controls | get_style_guide with topic "controls", only when asked for | 9,355 | 2,339 |
| The style guide, topic toggles | get_style_guide with topic "toggles", only when asked for | 5,834 | 1,459 |
| The style guide, topic forms | get_style_guide with topic "forms", only when asked for | 6,474 | 1,619 |
| The style guide, topic composition | get_style_guide with topic "composition", only when asked for | 8,706 | 2,177 |
| The style guide, topic style-kits | get_style_guide with topic "style-kits", only when asked for | 8,178 | 2,045 |
| The style guide, topic kit-rules | get_style_guide with topic "kit-rules", only when asked for | 9,589 | 2,398 |
| The style guide, topic kit-patterns | get_style_guide with topic "kit-patterns", only when asked for | 5,431 | 1,358 |
| The style guide, topic style-kit:quiet-solid | get_style_guide with topic "style-kit:quiet-solid", only when asked for | 8,189 | 2,048 |
| The style guide, topic style-kit:glass-edge | get_style_guide with topic "style-kit:glass-edge", only when asked for | 8,146 | 2,037 |
| The style guide, topic style-kit:warm-pill | get_style_guide with topic "style-kit:warm-pill", only when asked for | 8,212 | 2,053 |
| The style guide, topic style-kit:hairline-mono | get_style_guide with topic "style-kit:hairline-mono", only when asked for | 8,073 | 2,019 |
| The style guide, topic style-kit:chunky-pill | get_style_guide with topic "style-kit:chunky-pill", only when asked for | 8,081 | 2,021 |
| The style guide, topic style-kit:steady-solid | get_style_guide with topic "style-kit:steady-solid", only when asked for | 8,200 | 2,050 |
| The style guide, topic style-kit:soft-rounded | get_style_guide with topic "style-kit:soft-rounded", only when asked for | 8,075 | 2,019 |
| The style guide, topic style-kit:heavy-block | get_style_guide with topic "style-kit:heavy-block", only when asked for | 8,183 | 2,046 |
| The style guide, topic style-kit:raw-frame | get_style_guide with topic "style-kit:raw-frame", only when asked for | 8,079 | 2,020 |
| The style guide, topic style-kit:gilt-hairline | get_style_guide with topic "style-kit:gilt-hairline", only when asked for | 8,010 | 2,003 |
| The style guide, topic style-kit:soft-solid | get_style_guide with topic "style-kit:soft-solid", only when asked for | 8,148 | 2,037 |
| The style guide, topic style-kit:outline-2 | get_style_guide with topic "style-kit:outline-2", only when asked for | 8,102 | 2,026 |
| The style guide, topic style-kit:warm-pill-soft | get_style_guide with topic "style-kit:warm-pill-soft", only when asked for | 9,176 | 2,294 |
| The style guide, topic style-kit:chunky-pill-offset | get_style_guide with topic "style-kit:chunky-pill-offset", only when asked for | 9,246 | 2,312 |
| The style guide, topic style-kit:hairline-dashed | get_style_guide with topic "style-kit:hairline-dashed", only when asked for | 8,056 | 2,014 |
| The style guide, topic style-kit:underline-link | get_style_guide with topic "style-kit:underline-link", only when asked for | 8,736 | 2,184 |
| The style guide, topic style-kit:neo-brutal | get_style_guide with topic "style-kit:neo-brutal", only when asked for | 9,556 | 2,389 |
| The style guide, topic style-kit:double-frame | get_style_guide with topic "style-kit:double-frame", only when asked for | 8,163 | 2,041 |
| The style guide, topic style-kit:soft-lift | get_style_guide with topic "style-kit:soft-lift", only when asked for | 9,172 | 2,293 |
| The style guide, topic style-kit:ghost-swap | get_style_guide with topic "style-kit:ghost-swap", only when asked for | 8,064 | 2,016 |
| The style guide, topic style-kit:dotted-pill | get_style_guide with topic "style-kit:dotted-pill", only when asked for | 8,164 | 2,041 |
| The style guide, topic style-kit:hairline-frame-caps | get_style_guide with topic "style-kit:hairline-frame-caps", only when asked for | 8,137 | 2,035 |
| The style guide, topic style-kit:offset-frame | get_style_guide with topic "style-kit:offset-frame", only when asked for | 9,277 | 2,320 |
| The style guide, topic style-kit:soft-hairline-caps | get_style_guide with topic "style-kit:soft-hairline-caps", only when asked for | 8,162 | 2,041 |
| The style guide, topic style-kit:thick-frame-caps | get_style_guide with topic "style-kit:thick-frame-caps", only when asked for | 8,146 | 2,037 |
| The style guide, topic style-kit:thick-rounded-caps | get_style_guide with topic "style-kit:thick-rounded-caps", only when asked for | 8,176 | 2,044 |
| The style guide, topic style-kit:rimmed-solid | get_style_guide with topic "style-kit:rimmed-solid", only when asked for | 7,982 | 1,996 |
| The style guide, topic style-kit:flat-square | get_style_guide with topic "style-kit:flat-square", only when asked for | 8,260 | 2,065 |
| The style guide, topic style-kit:block-caps | get_style_guide with topic "style-kit:block-caps", only when asked for | 8,254 | 2,064 |
| The style guide, topic style-kit:pill-caps | get_style_guide with topic "style-kit:pill-caps", only when asked for | 8,302 | 2,076 |
| The style guide, topic style-kit:outline-pill | get_style_guide with topic "style-kit:outline-pill", only when asked for | 8,131 | 2,033 |
| The style guide, topic style-kit:outline-soft | get_style_guide with topic "style-kit:outline-soft", only when asked for | 8,110 | 2,028 |
| The style guide, topic style-kit:thick-frame | get_style_guide with topic "style-kit:thick-frame", only when asked for | 8,085 | 2,022 |
| The style guide, topic style-kit:raised-square | get_style_guide with topic "style-kit:raised-square", only when asked for | 9,382 | 2,346 |
| The style guide, topic style-kit:offset-block-caps | get_style_guide with topic "style-kit:offset-block-caps", only when asked for | 9,547 | 2,387 |
| The style guide, topic style-kit:text-link | get_style_guide with topic "style-kit:text-link", only when asked for | 8,072 | 2,018 |
| The style guide, topic style-kit:text-link-caps | get_style_guide with topic "style-kit:text-link-caps", only when asked for | 8,105 | 2,027 |
| The style guide, topic style-kit:underlined-label | get_style_guide with topic "style-kit:underlined-label", only when asked for | 8,849 | 2,213 |
| The style guide, topic style-kit:pressable | get_style_guide with topic "style-kit:pressable", only when asked for | 9,532 | 2,383 |
| The style guide, topic style-kit:hairline-halo | get_style_guide with topic "style-kit:hairline-halo", only when asked for | 9,251 | 2,313 |
| The recipe catalog (default) | get_section_recipes with no filter: names under role headings, plus how to fetch one | 6,260 | 1,565 |
| The recipe catalog (filtered, role hero) | get_section_recipes with role "hero": the one-line description of each matching recipe | 3,895 | 974 |
| A served recipe (hero-cta, default look) | get_section_recipes with name "hero-cta" and no design_language | 3,450 | 863 |
| A served recipe (hero-cta, glass-dark) | the same recipe with design_language "glass-dark" (tokens filled in; the size is the markup alone) | 3,427 | 857 |

A refusal used to repeat the full list of allowed block types in every violation. Now the first violation about a parent keeps the list, the others point back to it, and at most 25 are listed (one entry counts the rest).

To read a page cheaply, ask get_page_layout for view "outline" (every block's path, name, size and the start of its text), then view "block" with a path for one block's exact markup.

Tokens the assistant WRITES also count: sending a whole layout to update_page_layout costs roughly the size of the page read. edit_page_content avoids that (and a dry_run of it sends only the find-and-replace pair).

The style guide topics, the recipe catalog and a filtered catalog are read only when asked for; the default catalog is names only, and one recipe is fetched by name (with an optional design_language that fills in its colors and fonts without changing its size much).

Each style-kit:<id> topic describes the kit's cards and panels, shows an inverted panel with a panel button and a toggle, and points to the generated kit-rules topic, which holds the explanation every kit shares. The largest kit topic is style-kit:neo-brutal at 9,556 bytes (the bound is 10,000, so 444 bytes of headroom; the shared explanation moved into the kit-rules topic, see its row above); the smallest is 7,982 bytes.

The Pro add-on adds its own tool definitions on top of the first row.
