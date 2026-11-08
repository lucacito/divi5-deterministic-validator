# Loop Builder: what the real Divi 5.15.1 does with a looped module

Evidence for the validator rules `INVALID_LOOP_SETTING` and `MALFORMED_DYNAMIC_TOKEN`, for the guide topic `loops` and for the
fixture `fixtures/valid/loop-builder-5.3.json`. Made on the Docker dev site (Divi 5.15.1, WooCommerce active, 2026-11-07).
Live gate: `make verify-loop-builder` (38 checks). The 79-case sweep that found the shapes below is `scripts/sweep-loop-builder.php`
(one case per process: `... eval-file /scripts/sweep-loop-builder.php <case|list>`).

## Sources

- **A real builder export** from a Divi 5.3 site (a looped image inside a grid group, the posts of a custom post type `manufacturer`
  filtered by a term of the taxonomy `state`, with the logo read from a custom field). Trimmed and kept as
  `fixtures/valid/loop-builder-5.3.json`. It shows what the builder itself writes:
  `module.advanced.loop.desktop.value` with `enable` "on", `loopId` "loop-<10 letters or digits>", `subTypes`
  `[{"value","label"}]`, `includePostWithSpecificTerms` `[{"categoryId","categoryName","selectedOptions":[{"value","label"}]}]`, `""` (an empty
  string) for an empty `excludePostWithSpecificTerms`, `includeSpecificPosts` and `excludeSpecificPosts`, `postPerPage` "99" (a string), `orderBy`
  "name", `order` "ascending". A loop switched off is `"enable":"off","loopId":""`. The container group carries loop settings WITHOUT `enable`
  (a leftover; it does not loop) and a grid layout (`display` "grid", `gridColumnCount`). Tokens: `$variable({"type":"content","value":{"name":
  "loop_post_meta_key_manual_custom_field","settings":{"select_loop_meta_key":"loop_post_meta_key_logo",...}}})$` for a custom field and
  `loop_post_link` for the address, in `image.innerContent.desktop.value.src` and `.linkUrl`.
- **Divi's own source** (`includes/builder-5/server/Packages/Module/Options/Loop/`): `LoopUtils::get_query_args_from_attrs` (how each
  setting is read), `QueryOrderByController` (the `orderBy` keys per query type), `QueryTypeController`; the variable names come from
  `DynamicContentOptions::get_options()` on the live site (`fixtures/shapes/loop-variables-5.15.1.txt`, 51 names). Divi's own AI agent
  (`ai-agent.js`) documents the same field shapes.

## What a render showed (79 cases, one draft page each)

Wrong shapes that answer a **PHP TypeError for the whole page** (HTTP 500):
`metaQuery` as a string, even `""` (`build_meta_query()` takes an array); `order` as an array (`_extract_order_by_value(string)`); `loopId` as an array.

Wrong shapes that **silently switch the loop off** (one empty item, no message): `enable` true, "yes", an array or missing; `loop` as a string,
`{"enable":"on"}` without `desktop.value`, `desktop.value` as a string; `queryType` unknown (the item renders with empty text).

Wrong shapes that **silently change what is listed**:
`subTypes` as a string list `["post"]`, an object, a value that is not a string, a missing list, or a slug that does not exist: the loop reads
EVERY post type (media files came first on the dev site); `postPerPage` "abc", "0" or 5000 loads all items, "-1" loads one; `order` "desc" is read as
ascending; `orderBy` unknown falls back to the default; `search` filters the title.

Shapes that **render correctly although the builder does not write them**: `postPerPage` as an integer, `loopId` missing or an integer, `""` or an
object for `includeSpecificPosts` and the term lists, `orderBy` with an unknown key. Only the ones that break the page or the result are refused.

Right shapes (rendered, with the real data): posts, pages, WooCommerce products (`loop_product_title`), terms (`post_taxonomies`), users (`user_roles`),
a custom post type filtered by a term and ordered by name, a custom field as the image source, the permalink as the image link and as a button link, a
text module holding `<h3>` and `<p>` tokens, the loop on a group, on a row, on a column and on an image; offset and sticky/current-post flags; a `metaQuery`
list with `EXISTS`; two loops with the same `loopId` (both rendered, but the id must be unique for the builder); a loop inside a loop (items multiply).

Tokens: a `post_title` token inside a loop shows the PAGE's title in every item; a `loop_` token outside a loop renders nothing; an unknown `loop_` name
renders empty; a token without its closing `)$` prints the raw `$variable(...` text.

## What the checker does about it

`Validator::passLoops` (src/ and wp-plugin/validator/): `INVALID_LOOP_SETTING` for the shapes above that break the page, switch the loop off or
change the list; `MALFORMED_DYNAMIC_TOKEN` for a token without both ends. Not refused: a loop value without `enable` (the builder writes that on a
container), shapes that render correctly. `tests/LoopRulesTest.php` pins 31 cases; every valid fixture and every recipe still passes.

## Not covered

ACF repeater loops with a real ACF Pro field (ACF Pro is commercial and not on the dev site), loops inside Theme Builder templates,
WooCommerce archive add-to-cart in a loop, the Visual Builder's own display of a looped page, whether a hand-written `loopId` survives the
builder's duplicate-id repair on save (`LoopIdDeduplicationUtils`, which runs on a builder save). The guide says to use a unique id per loop.
Meta Box is not a loop source: Divi 5.15.1 has no Meta Box code in its loop or dynamic-content classes.

## Pagination, menus, current_page and repeaters (probe 2026-11-08)

`scripts/probe-loop-sources.php` (one case per process, throwaway draft deleted afterwards; 6 published posts, one 15-item menu, Divi 5.15.1):

- **Pagination.** The Post Navigation module (`divi/post-nav`) connects to a loop with `module.advanced.targetLoop.desktop.value` = the loop's
  `loopId`. The page comes from the URL parameter named by the loop id (`?loop-spike0001=2`), read in `LoopUtils` (a page below 1 is page 1).
  With `postPerPage` 2 pages 1, 2 and 3 show items 1-2, 3-4 and 5-6; the module prints "Next" on page 1, "Previous" and "Next" on page 2,
  "Previous" on page 3, each a link to `?loopId=N`. `postOffset` 1 shifts every page by one item. Page 99 lists nothing ("No Results Found.")
  and still links "Previous" to page 98. A target id that matches no loop prints no links and no error. Without the Post Navigation module the
  loop simply shows page 1.
- **Page-fatal.** A `targetLoop` value that is an array or an object throws a TypeError for the whole page (`LoopQueryRegistry::get_query()`
  wants a string). An integer, `true`, `""` and a `targetLoop` without `desktop` do not throw (no links, or the module falls back to the
  previous/next post). The validator accepts the fatal shapes today.
- **Menus.** `queryType: "menus"`, `subTypes: [{"value":"<menu term id as a string>","label":"..."}]`, tokens `loop_menu_text`, `loop_menu_link`
  (and the other `loop_menu_*` names): one item per menu item, the whole tree flattened (children included), the link token gives the item's URL
  in a button. No `subTypes` or an unknown menu id lists nothing. An integer `value` lists nothing (the validator already refuses it).
  `loop_post_*` tokens inside a menu loop render empty. `orderBy: title` sorts alphabetically. **The order is inverted for `menu_order`:**
  `order: "descending"` (the default) shows the menu in its own order (positions 1, 2, 3 ...), `order: "ascending"` shows it backwards
  (15, 14, 13 ...), because Divi reverses a list it wrongly assumes is descending (`QueryResultsController` 1394-1411).
- **current_page.** `queryType: "current_page"` lists the main query: on a category archive three posts newest first; on an ordinary page
  "No Results Found." `subTypes` (a post type) changes nothing there.
- **Repeaters.** Divi treats any `queryType` starting with `repeater_` as an ACF repeater named after the prefix
  (`DynamicContentACFUtils::is_repeater_query`). Without ACF the loop lists nothing and nothing throws. The validator refuses `repeater_*`
  today (`queryType` must be one of ...). Not proven with a real repeater.
- **`queryType: "terms"`.** Divi accepts it as an alias of `post_taxonomies` (live: the category list renders); the validator now accepts it.

### Second probe round (2026-11-08)

- **Nested loops.** A terms loop inside a posts loop renders: every outer post lists the inner terms, but the inner loop is NOT scoped to the outer
  item (each post shows the same "Uncategorized"). An outer `loop_post_title` inside the inner loop renders empty. Two nested loops with the same
  id render the inner list from the outer item (wrong).
- **Duplicate loop ids.** Two separate loops with one `loopId` each render with their own settings, but `?loopId=2` pages BOTH. The builder repairs
  duplicate ids on save (not probed). `LoopAdvisory` reports `duplicate_loop_id`.
- **Unknown post type.** `subTypes` naming a post type the site does not have: with no other valid type Divi lists EVERY post type (media files
  included); next to a valid type it is ignored.
- **Filters on a missing term.** `includePostWithSpecificTerms` / `excludePostWithSpecificTerms` with a term id that does not exist, a taxonomy that
  does not exist, or a slug instead of an id: the filter is ignored (the loop lists everything), not "nothing".
- **Terms loops** list only terms that have posts (`hide_empty` true in `QueryResultsController` 1069 / `LoopUtils` 3069).
- **Post Filter module (`divi/post-filter`).** Not built: its filter items need a real builder export for their field and source settings, and the
  repo has none. It targets a loop with the same `module.advanced.targetLoop` path and uses `?loop_filter-<loopId>[field]=` parameters.
- **Theme Builder templates.** A `current_page` loop in a body layout was not probed in a real template (the render path is assumed to be the same as an
  archive page's).

`LoopPreview` (`advisories.loop_results`, `make verify-loop-builder`): for every enabled loop, whether its post types, taxonomies, term ids, menus and
roles exist on this site, and for a plain post or menu loop how many items match and the first titles; the live gate compares these with Divi's own
render (post loops by date and title, ascending and descending; menu loops in both orders; term counts; an unknown post type, menu id and taxonomy;
a missing term id). Filters, sticky posts, `current_page`, users and attachments are not counted and the row says so.

## Recipes and the token advisory (2026-11-07)

`make verify-loop-builder` also renders the two shipped loop recipes (`loop-posts-cards`, `loop-products-grid`) as served by
`SectionRecipes::recipe` under the default look and brutalist-flat, on this site's own newest posts and products: one card per item,
newest first, each with its own title, picture and link (price on the products); the featured-image and product tokens
(`loop_post_featured_image`, `loop_product_post_featured_image`, `loop_product_title` as alt text, `loop_product_price_current`,
`loop_product_post_link_url`) are proven here by that render. 70 checks, 0 FAIL. `node scripts/verify-recipes.cjs --names
loop-posts-cards,loop-products-grid --languages default,editorial,glass-dark,organic-warm,tech-mono,playful-pastel,corporate-trust,soft-saas,bold-vibrant,brutalist-flat,luxe`
renders both at 1280/900/400 with contrast measured: 66 PASS, 0 FAIL.

`LoopAdvisory` states only what section "Tokens" above proves: a `loop_` token outside a switched-on loop renders nothing, and
`post_title` inside a loop shows the page's title. Other `post_` tokens are flagged by the same mechanism (they read the current
page), not by their own render.
