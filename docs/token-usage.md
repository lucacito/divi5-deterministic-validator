# Token usage of the AI Editor for Divi 5 (free plugin)

Measured 2026-10-20 by `scripts/measure-tokens.php`. Tokens are ESTIMATED as bytes / 4; the real count depends on the model.

Checking a layout runs in PHP on your server and costs **0 tokens**. What costs tokens is what the assistant has to read.

| What | Detail | Bytes | Approx. tokens |
| --- | --- | ---: | ---: |
| Tool definitions | 21 tools, loaded once per conversation (free plugin only) | 21,601 | 5,401 |
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
| The style guide, topic design-languages | get_style_guide with topic "design-languages", only when asked for | 2,592 | 648 |
| The style guide, topic design-language:editorial | get_style_guide with topic "design-language:editorial", only when asked for | 1,818 | 455 |
| The style guide, topic design-language:glass-dark | get_style_guide with topic "design-language:glass-dark", only when asked for | 2,151 | 538 |
| The style guide, topic design-language:organic-warm | get_style_guide with topic "design-language:organic-warm", only when asked for | 2,146 | 537 |
| The style guide, topic design-language:tech-mono | get_style_guide with topic "design-language:tech-mono", only when asked for | 2,273 | 569 |
| The style guide, topic design-language:playful-pastel | get_style_guide with topic "design-language:playful-pastel", only when asked for | 2,477 | 620 |
| The style guide, topic design-language:corporate-trust | get_style_guide with topic "design-language:corporate-trust", only when asked for | 2,581 | 646 |
| The style guide, topic design-language:soft-saas | get_style_guide with topic "design-language:soft-saas", only when asked for | 3,258 | 815 |
| The style guide, topic design-language:bold-vibrant | get_style_guide with topic "design-language:bold-vibrant", only when asked for | 3,019 | 755 |
| The style guide, topic design-language:brutalist-flat | get_style_guide with topic "design-language:brutalist-flat", only when asked for | 2,550 | 638 |
| The style guide, topic design-language:luxe | get_style_guide with topic "design-language:luxe", only when asked for | 2,758 | 690 |
| The style guide, topic backgrounds | get_style_guide with topic "backgrounds", only when asked for | 8,894 | 2,224 |
| The style guide, topic dividers | get_style_guide with topic "dividers", only when asked for | 8,869 | 2,218 |
| The style guide, topic motion | get_style_guide with topic "motion", only when asked for | 9,800 | 2,450 |
| The style guide, topic hover-states | get_style_guide with topic "hover-states", only when asked for | 5,637 | 1,410 |
| The style guide, topic layout-advanced | get_style_guide with topic "layout-advanced", only when asked for | 9,973 | 2,494 |
| The style guide, topic layout-sizing | get_style_guide with topic "layout-sizing", only when asked for | 9,972 | 2,493 |
| The style guide, topic typography | get_style_guide with topic "typography", only when asked for | 7,496 | 1,874 |
| The style guide, topic imagery | get_style_guide with topic "imagery", only when asked for | 6,506 | 1,627 |
| The style guide, topic controls | get_style_guide with topic "controls", only when asked for | 8,123 | 2,031 |
| The style guide, topic toggles | get_style_guide with topic "toggles", only when asked for | 5,834 | 1,459 |
| The style guide, topic forms | get_style_guide with topic "forms", only when asked for | 6,474 | 1,619 |
| The style guide, topic composition | get_style_guide with topic "composition", only when asked for | 8,589 | 2,148 |
| The style guide, topic style-kits | get_style_guide with topic "style-kits", only when asked for | 8,453 | 2,114 |
| The style guide, topic kit-rules | get_style_guide with topic "kit-rules", only when asked for | 4,301 | 1,076 |
| The style guide, topic style-kit:quiet-solid | get_style_guide with topic "style-kit:quiet-solid", only when asked for | 8,232 | 2,058 |
| The style guide, topic style-kit:glass-edge | get_style_guide with topic "style-kit:glass-edge", only when asked for | 8,230 | 2,058 |
| The style guide, topic style-kit:warm-pill | get_style_guide with topic "style-kit:warm-pill", only when asked for | 8,255 | 2,064 |
| The style guide, topic style-kit:hairline-mono | get_style_guide with topic "style-kit:hairline-mono", only when asked for | 8,116 | 2,029 |
| The style guide, topic style-kit:chunky-pill | get_style_guide with topic "style-kit:chunky-pill", only when asked for | 8,124 | 2,031 |
| The style guide, topic style-kit:steady-solid | get_style_guide with topic "style-kit:steady-solid", only when asked for | 8,236 | 2,059 |
| The style guide, topic style-kit:soft-rounded | get_style_guide with topic "style-kit:soft-rounded", only when asked for | 8,136 | 2,034 |
| The style guide, topic style-kit:heavy-block | get_style_guide with topic "style-kit:heavy-block", only when asked for | 8,210 | 2,053 |
| The style guide, topic style-kit:raw-frame | get_style_guide with topic "style-kit:raw-frame", only when asked for | 8,109 | 2,028 |
| The style guide, topic style-kit:gilt-hairline | get_style_guide with topic "style-kit:gilt-hairline", only when asked for | 8,118 | 2,030 |
| The style guide, topic style-kit:soft-solid | get_style_guide with topic "style-kit:soft-solid", only when asked for | 8,191 | 2,048 |
| The style guide, topic style-kit:outline-2 | get_style_guide with topic "style-kit:outline-2", only when asked for | 8,145 | 2,037 |
| The style guide, topic style-kit:warm-pill-soft | get_style_guide with topic "style-kit:warm-pill-soft", only when asked for | 9,259 | 2,315 |
| The style guide, topic style-kit:chunky-pill-offset | get_style_guide with topic "style-kit:chunky-pill-offset", only when asked for | 9,307 | 2,327 |
| The style guide, topic style-kit:hairline-dashed | get_style_guide with topic "style-kit:hairline-dashed", only when asked for | 8,124 | 2,031 |
| The style guide, topic style-kit:underline-link | get_style_guide with topic "style-kit:underline-link", only when asked for | 8,779 | 2,195 |
| The style guide, topic style-kit:neo-brutal | get_style_guide with topic "style-kit:neo-brutal", only when asked for | 9,599 | 2,400 |
| The style guide, topic style-kit:double-frame | get_style_guide with topic "style-kit:double-frame", only when asked for | 8,206 | 2,052 |
| The style guide, topic style-kit:soft-lift | get_style_guide with topic "style-kit:soft-lift", only when asked for | 9,215 | 2,304 |
| The style guide, topic style-kit:ghost-swap | get_style_guide with topic "style-kit:ghost-swap", only when asked for | 8,107 | 2,027 |
| The style guide, topic style-kit:dotted-pill | get_style_guide with topic "style-kit:dotted-pill", only when asked for | 8,207 | 2,052 |
| The style guide, topic style-kit:hairline-frame-caps | get_style_guide with topic "style-kit:hairline-frame-caps", only when asked for | 8,180 | 2,045 |
| The style guide, topic style-kit:offset-frame | get_style_guide with topic "style-kit:offset-frame", only when asked for | 9,320 | 2,330 |
| The style guide, topic style-kit:soft-hairline-caps | get_style_guide with topic "style-kit:soft-hairline-caps", only when asked for | 8,205 | 2,052 |
| The style guide, topic style-kit:thick-frame-caps | get_style_guide with topic "style-kit:thick-frame-caps", only when asked for | 8,189 | 2,048 |
| The style guide, topic style-kit:thick-rounded-caps | get_style_guide with topic "style-kit:thick-rounded-caps", only when asked for | 8,219 | 2,055 |
| The style guide, topic style-kit:rimmed-solid | get_style_guide with topic "style-kit:rimmed-solid", only when asked for | 8,025 | 2,007 |
| The style guide, topic style-kit:flat-square | get_style_guide with topic "style-kit:flat-square", only when asked for | 8,303 | 2,076 |
| The style guide, topic style-kit:block-caps | get_style_guide with topic "style-kit:block-caps", only when asked for | 8,297 | 2,075 |
| The style guide, topic style-kit:pill-caps | get_style_guide with topic "style-kit:pill-caps", only when asked for | 8,345 | 2,087 |
| The style guide, topic style-kit:outline-pill | get_style_guide with topic "style-kit:outline-pill", only when asked for | 8,174 | 2,044 |
| The style guide, topic style-kit:outline-soft | get_style_guide with topic "style-kit:outline-soft", only when asked for | 8,153 | 2,039 |
| The style guide, topic style-kit:thick-frame | get_style_guide with topic "style-kit:thick-frame", only when asked for | 8,128 | 2,032 |
| The style guide, topic style-kit:raised-square | get_style_guide with topic "style-kit:raised-square", only when asked for | 9,425 | 2,357 |
| The style guide, topic style-kit:offset-block-caps | get_style_guide with topic "style-kit:offset-block-caps", only when asked for | 9,590 | 2,398 |
| The style guide, topic style-kit:text-link | get_style_guide with topic "style-kit:text-link", only when asked for | 8,115 | 2,029 |
| The style guide, topic style-kit:text-link-caps | get_style_guide with topic "style-kit:text-link-caps", only when asked for | 8,148 | 2,037 |
| The style guide, topic style-kit:underlined-label | get_style_guide with topic "style-kit:underlined-label", only when asked for | 8,892 | 2,223 |
| The style guide, topic style-kit:pressable | get_style_guide with topic "style-kit:pressable", only when asked for | 9,575 | 2,394 |
| The style guide, topic style-kit:hairline-halo | get_style_guide with topic "style-kit:hairline-halo", only when asked for | 9,294 | 2,324 |
| The recipe catalog (default) | get_section_recipes with no filter: names under role headings, plus how to fetch one | 6,258 | 1,565 |
| The recipe catalog (filtered, role hero) | get_section_recipes with role "hero": the one-line description of each matching recipe | 3,914 | 979 |
| A served recipe (hero-cta, default look) | get_section_recipes with name "hero-cta" and no design_language | 3,397 | 850 |
| A served recipe (hero-cta, glass-dark) | the same recipe with design_language "glass-dark" (tokens filled in; the size is the markup alone) | 3,374 | 844 |

A refusal used to repeat the full list of allowed block types in every violation. Now the first violation about a parent keeps the list, the others point back to it, and at most 25 are listed (one entry counts the rest).

To read a page cheaply, ask get_page_layout for view "outline" (every block's path, name, size and the start of its text), then view "block" with a path for one block's exact markup.

Tokens the assistant WRITES also count: sending a whole layout to update_page_layout costs roughly the size of the page read. edit_page_content avoids that (and a dry_run of it sends only the find-and-replace pair).

The style guide topics, the recipe catalog and a filtered catalog are read only when asked for; the default catalog is names only, and one recipe is fetched by name (with an optional design_language that fills in its colors and fonts without changing its size much).

Each style-kit:<id> topic describes the kit's cards and panels, shows an inverted panel with a panel button and a toggle, and points to the generated kit-rules topic, which holds the explanation every kit shares. The largest kit topic is style-kit:neo-brutal at 9,599 bytes (the bound is 10,000, so 401 bytes of headroom; the shared explanation moved into the kit-rules topic, see its row above); the smallest is 8,025 bytes.

The Pro add-on adds its own tool definitions on top of the first row.
