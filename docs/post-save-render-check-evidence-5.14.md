# Post-save render check (backlog #8 / 2e): spike evidence, Divi 5.14.0

Run 2026-10-06 on the Docker dev site. Script: `scripts/spike-post-save-render-check.php` (self-cleaning, dev sites only). Nothing in the product changed.

**Question.** After a save, can a tool look at how the page *renders* and report problems the checker cannot see, without a screenshot, and without depending on a network request that hosts often block?

## Answers

1. **Rendering in-process works, for drafts too, with no network and no login.** `query_posts` + `the_post` + `apply_filters('the_content', $post->post_content)` returns the page's real module HTML (Divi 5's own renderer). A published page took 90 ms the first time and 6 ms after; a draft 6 ms. Output carried the expected text and no leaked `$variable(` token.
2. **The loopback alternative is weak.** Fetching the site's own URL (what a "screenshot" or HTTP check needs) failed here with `cURL error 7: Failed to connect to localhost port 8181` (a Docker artifact, but the same class as hosts that block loopback). Through the container's own address a published page answered **301** (canonical redirect, no body) and a **draft answered 404** without a login. Credentials would be needed for drafts; we should not create auth cookies for this.
3. **What a structural summary can see** (counts of sections and modules, modules or sections that rendered with nothing in them, PHP notices, an exception from Divi's renderer):

| Case | Gate says | Render summary sees |
|---|---|---|
| D1 dead custom-field binding | valid | **1 empty text module**, neighbour rendered |
| D2 empty section (no modules) | valid | **1 empty section** (Divi marks the row `et-vb-row--no-modules`) |
| D6 empty text module | valid | **1 empty text module** |
| D3 unknown module | invalid (`UNKNOWN_MODULE_TYPE`, `UNEXPECTED_CHILD_TYPE`) | silently **dropped**: the neighbour renders, nothing is flagged unless block count is compared with module count |
| D4 code module | invalid (`CODE_MODULE_NOT_ALLOWED`) | renders normally (so it executes) |
| D5 truncated markup | invalid (`BLOCK_PARSE_ERROR`, `SCRIPT_CONTENT_NOT_ALLOWED`) | **TypeError from Divi's `ColumnModule`** plus four PHP notices; catchable with try/catch in-process, no output |
| A good page | valid | clean |

4. **Real pages: false-positive classes exist.** An "empty module" heuristic over all 11 real Divi 5 pages on the site (1775 modules, 72 sections, 0 empty sections, 0 render errors) flagged: divider 17 (empty by design), post_nav 1, canvas_portal 1, map 1 (rendered by JavaScript), tab 1, and **image 993**. Of those images, 992 are on the imported page "newpage" and are in a Divi **loop** (`data-loop-item`): their dynamic image source resolves to nothing on this site, so they are real empties, not a heuristic error. A tool must ignore the by-design types (divider, map, post_nav, canvas_portal, and others to be found) and treat the rest as findings.
5. **Cost:** 11 pages rendered in 5.2 s in total; the largest (1073 modules) took 2.1 s. One page per call, with a time and size cap, would be needed.

## Risks of in-process rendering

- It executes whatever the page executes: shortcodes inside text modules, dynamic content, code modules already stored. The same happens whenever a visitor opens the page, but here it would run inside an MCP request as the API user.
- A PHP fatal error (not an exception) inside Divi's renderer cannot be caught and would end the request.
- It touches WordPress's global query state (`query_posts`, `the_post`); it must reset it.
- Renders only the post content, not the header, footer or Theme Builder layout wrapped around it.

## What it adds over what already exists

Everything in the table except D1, D2 and D6 is already refused or reported by the gate (unknown module, truncated markup, code module). D1 (dead binding) is now covered without rendering by the `unknown_custom_fields` advisory (4.7.0). What a render check would add that nothing else sees: **empty sections** (valid, usually harmless), **empty text modules** (harmless), **loop sources with no data**, **modules Divi silently drops**, and **exceptions from Divi's renderer** on markup the parser accepts.

## Recommendation

**Do not build a render-check tool now.** The incremental value over the gate plus the style-reference advisories is small, and the costs are real (it runs site code in an API request, uncatchable fatals, false-positive types to maintain). Revisit if users report pages that "saved fine but look wrong" in ways the checker and advisories do not catch. If it is ever built:

- Pro only, read-only, MCP and REST only (the ChatGPT importer's 30 operations are used up).
- `check_page_render(page_id)`: one page, in-process render, a time cap, `wp_reset_query()` afterwards, `Throwable` caught.
- Return counts, **block count versus rendered module count** (catches silent drops), empty modules by type excluding the by-design types, empty sections, notices and the exception message. No HTML, no screenshot.
- Never fetch the site's own URL, never create a login cookie.
- A live gate on the dev site like the others.

## Not probed

Pages inside a Theme Builder layout, WooCommerce templates, Divi's CSS output (render failures that only show as missing styles), JavaScript-rendered modules, and a real host's PHP memory and time limits.
