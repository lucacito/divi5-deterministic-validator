# Token usage of the AI Editor for Divi 5 (free plugin)

Measured 2026-10-04 by `scripts/measure-tokens.php`. Tokens are ESTIMATED as bytes / 4; the real count depends on the model.

Checking a layout runs in PHP on your server and costs **0 tokens**. What costs tokens is what the assistant has to read.

| What | Detail | Bytes | Approx. tokens |
| --- | --- | ---: | ---: |
| Tool definitions | 17 tools, loaded once per conversation (free plugin only) | 11,548 | 2,887 |
| Reading a page (smallest) | the layout get_page_layout returns, from page-7-homepage.json | 2,214 | 554 |
| Reading a page (median) | the layout get_page_layout returns, from page-86-layout-5.json | 51,612 | 12,903 |
| Reading a page (largest) | the layout get_page_layout returns, from page-85-layout-4.json | 66,095 | 16,524 |
| A refusal: one mistake | 2 violation, e.g. an unknown block type | 1,701 | 426 |
| A refusal: every block wrong (worst case) | 67 violations on the median page | 14,993 | 3,749 |
| A preview (dry run) of a one-line text change | verdict plus a capped diff, on the median page | 394 | 99 |

The Pro add-on adds its own tool definitions on top of the first row.
