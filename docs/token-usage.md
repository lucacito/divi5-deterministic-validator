# Token usage of the AI Editor for Divi 5 (free plugin)

Measured 2026-10-13 by `scripts/measure-tokens.php`. Tokens are ESTIMATED as bytes / 4; the real count depends on the model.

Checking a layout runs in PHP on your server and costs **0 tokens**. What costs tokens is what the assistant has to read.

| What | Detail | Bytes | Approx. tokens |
| --- | --- | ---: | ---: |
| Tool definitions | 20 tools, loaded once per conversation (free plugin only) | 15,900 | 3,975 |
| Reading a page (smallest) | the page's layout content (get_page_layout adds a little wrapper), from page-17-variable-heading.json | 1,152 | 288 |
| Reading a page (median) | the page's layout content (get_page_layout adds a little wrapper), from page-45-layout-3.json | 49,842 | 12,461 |
| Reading a page (largest) | the page's layout content (get_page_layout adds a little wrapper), from page-85-layout-4.json | 66,095 | 16,524 |
| A refusal: one unknown block | 2 violations (the second lists the allowed child types) | 1,701 | 426 |
| A refusal: every block wrong (worst case) | 96 violations on the median page | 21,791 | 5,448 |
| A preview (dry run) of a one-line text change | verdict plus a capped diff, on the median page | 394 | 99 |

A refusal can be long because it lists the allowed block types; shortening it would be a separate small fix.

Tokens the assistant WRITES also count: sending a whole layout to update_page_layout costs roughly the size of the page read. edit_page_content avoids that (and a dry_run of it sends only the find-and-replace pair).

The Pro add-on adds its own tool definitions on top of the first row.
