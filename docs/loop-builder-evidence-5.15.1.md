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

Loop pagination (`loop_pagination_*`, the page parameter named by `loopId`), ACF repeater and Meta Box loop sources (`repeater_*` query types),
menu-item loops and `current_page` on a real archive, loops inside Theme Builder templates, WooCommerce archive add-to-cart in a loop, the
Visual Builder's own display of a looped page, whether a hand-written `loopId` survives the builder's duplicate-id repair on save
(`LoopIdDeduplicationUtils`, which runs on a builder save). The guide says to use a unique id per loop.


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
