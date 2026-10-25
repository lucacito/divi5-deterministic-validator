# Render-fatal sweep (Divi 5.15.1, 2026-10-25)

Which content values make Divi 5 throw while it renders a page? On the front end such a throw is an
HTTP 500 for the WHOLE page. Evidence: `fixtures/shapes/render-fatals.json`. Scripts:
`scripts/sweep-module-keys.php` (the key shapes) and `scripts/sweep-render-fatals.php` (the live sweep).
Nothing in `src/`, `wp-plugin/` or `tests/` was changed.

## Method

1. **Key shapes come from Divi's own files.** `sweep-module-keys.php` reads, per module folder of
   the theme's `includes/builder-5`, three generated files: `module.json` (`attrName: "<key>.innerContent"`
   items: a `subName` = object with that sub key; a `group` component = object; a field component
   without `subName` = the whole value), `conversion-outline.json` (`<key>.innerContent.*` = scalar,
   `.*.<sub>` = object; paths whose Divi 4 value goes through a `valueExpansionFunctionMap` function
   are skipped) and `module-default-render-attributes.json` (the default value's type). A field
   component counts as writing an object when some key it edits is called an object by the other
   files and none is called a scalar (derived: `divi/icon-picker`, `divi/map`). Verdicts: object,
   scalar, mixed (sources disagree: none left after the rules above), unclassified (no source says).
   Run on the host: `php scripts/sweep-module-keys.php <copy of builder-5> [--json]`; it also prints
   which object keys `SchemaRules::contentKeyRules()` already guards.
2. **Baselines are real blocks.** Every `divi/*` block in `fixtures/valid/*.json` (real builder
   exports) and in the served recipes (`SectionRecipes::recipe`) is collected with its ancestors; the
   baseline is that block with its own children intact, wrapped in its real ancestors only (siblings
   dropped). The candidate carrying the most content keys is tried first (up to 6). It must render
   without a throw and with output; its module class (`et_pb_<slug>`) must be in the HTML
   (`marker_seen`). No block anywhere = `no-baseline`; nothing was invented.
3. **Mutations.** For every content key of the module (from step 1, also keys the baseline lacks),
   `<key>.innerContent.desktop.value` is set to `"sweep-x"`, `{"text":"sweep-x"}`, `null` and `[]`.
   Directions: `scalar-where-object` (string on an object key), `object-where-scalar` (object on a
   scalar key), the same-type controls, `null-value`, `array-value`, and for unclassified keys both
   `string-` and `object-where-unclassified`.
4. **In-process render.** `wp eval-file` creates ONE throwaway draft (`aied sweep <token> render host`,
   deleted in a `finally`, plus a sweep of every `aied sweep ` page before and after) and renders each
   candidate through `apply_filters('the_content', ...)` inside that post's context. A `Throwable` =
   `fatal` (class + first 200 characters); a PHP diagnostic not seen in the baseline render =
   `php-notice/warning`; otherwise `ok`. After every fatal the baseline is rendered again
   (`baseline_after: clean` on all 92 fatals; a dirty state would abort the run).
5. **Calibration before trusting anything.** The run aborts unless the real testimonial renders clean,
   the same block with `company` = "Corner Table Cafe" throws `ArrayUtility::get_value()` TypeError,
   and the clean block renders clean again. **HTTP check** (published throwaway pages, `curl` from the
   host, then deleted): the in-process verdict matched the front end in all 9 cases: testimonial clean
   200, company string 500, author object 500; heading title object 500, text content object 500,
   fullwidth-header buttonOne string 500; button string 200, blurb title string 200, code content
   object (warning only) 200. Each clean baseline 200.

The run takes about 7 seconds; two runs give byte-identical entries (only the header `date` moves).

## Counts

- 115 modules defined; 62 have innerContent keys, 133 keys: 60 object, 66 scalar, 7 unclassified.
- Baselines: 57 of 62 render clean (53 from real exports, 4 from recipes); 70 of the 128 swept keys
  were present in their baseline, the rest were added by the mutation. `no-baseline`: gravity-forms,
  imagely-gallery, payment-button, sidebar, woocommerce-cart-totals. post-filter-item renders only its
  parent's guidance (no filter target), so its 4 x 2 results say little (`marker_seen: false`).

| Direction | fatal | warning | ok |
|---|---|---|---|
| scalar-where-object | 21 | 1 | 34 |
| object-where-scalar | 59 | 1 | 6 |
| array-value (`[]`) | 12 | 2 | 114 |
| null-value | 0 | 1 | 127 |
| controls (same type) | 0 | 0 | 122 |
| unclassified, both | 0 | 0 | 12 |

## Fatal: string where Divi needs an object (21)

All throw `TypeError: ArrayUtility::get_value(): Argument #1 ($array) must be of type array, string given`.

Guarded by `contentKeyRules()` already (9): before-after-image afterImage, beforeImage; contact-form
button; cta button; image image; pricing-table currencyFrequency; slide button; testimonial company,
portrait.

**NOT guarded (12):** fullwidth-header buttonOne, buttonTwo, logo; fullwidth-image image;
fullwidth-menu logo; fullwidth-post-slider button; link content; menu logo; post-slider button;
pricing-table button; signup button; slide image.

Guarded but NOT fatal (a string renders without a throw; the guard still stops a broken module, e.g.
a string `button` on divi/button renders no button at all): blurb imageIcon, title; breadcrumbs home,
separator; button button; icon-list-item icon; video video; team-member image (a PHP warning).

## Fatal: object where Divi needs a string (59), NONE guarded

The validator has no check for this direction (`mustBeObject = false` means "not checked").
`UnexpectedValueException: Expected a string value, but a array value was given` (plain text keys)
or `TypeError: trim()/ltrim(): Argument #1 ($string) must be of type string, array given` (rich text
and URL keys), and three module methods typed `string`:

accordion-item content (AccordionItemModule::is_content_empty), title; audio albumName, artistName,
audio (ltrim), title; before-after-image afterLabel, beforeLabel; blurb content; circle-counter title;
contact-field fieldItem; contact-form title; countdown-timer title; counter barProgress (trim), title;
cta content, title; fullwidth-code content; fullwidth-header content, subhead, title;
fullwidth-portfolio title; heading title; icon-list-item content; login content
(LoginModule::render_logged_in_as_content), title; map-pin content, title; number-counter title;
post-filter-item label; pricing-table content (PricingTablesItemModule::render_pricing_list), price,
subtitle, title; search searchPlaceholder; signup content, footerContent, title; signup-custom-field
fieldItem; slide content, title, video (ltrim); tab content, title; table-of-contents emptyState,
title; team-member content, name, position; testimonial author, content, jobTitle; text content;
timeline-item content, date, title; toggle content, title; tooltip content.

Not fatal: circle-counter number, number-counter number, contact-field and signup-custom-field
conditionalLogic, contact-form email and redirect (ok); code content (warning, page still 200).

## Fatal: an empty array `[]` (12), NONE guarded

Scalar keys only (an `is_array` guard would let `[]` through): accordion-item content; audio audio;
before-after-image afterLabel, beforeLabel; contact-field fieldItem; counter barProgress; map-pin
title; post-filter-item label; pricing-table content; search searchPlaceholder; signup-custom-field
fieldItem; slide video. Warnings only: table-of-contents emptyState, title. `null` never throws
(team-member image gives a warning).

## Surprises

- The object-where-scalar direction is the bigger risk: 59 fatals, nothing guards it, and it is the
  natural mistake of an assistant that learned `{"text": ...}` from object keys (heading title,
  text content).
- Several guarded object keys do not throw at all (button, blurb title/imageIcon, breadcrumbs,
  icon-list-item icon, video video): they fail silently instead.
- module.json alone is not enough: blurb title and toggle title have `innerContent: {}`; the
  conversion outline and the default attributes fill the gap.

## Limits

- In process, not a browser: the render path is the_content in a post context (HTTP matched in all
  9 checked cases), but a throw only in wp_head/footer style output, or only in the Visual Builder,
  is not seen; a fatal that kills PHP (memory, recursion) would stop the run instead of being listed.
- Only `<key>.innerContent.desktop.value`: not tablet/phone/hover/sticky values, not
  `decoration`/`advanced` attributes, not presets, global colours/variables or dynamic content
  (`$variable(...)`), not inner sub keys (e.g. `button.innerContent.desktop.value.text` as an object).
- One baseline per module; 58 keys were absent from theirs (the mutation adds them).
- Modules without a real block (above), modules with no innerContent key (sliders, galleries,
  WooCommerce modules, structure) and the 7 unclassified keys (all rendered ok both ways).
- `ok` means "no throw", not "renders correctly".

## Re-run

```
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/sweep-render-fatals.php --user=1
docker compose exec -T wpcli php /usr/local/bin/wp eval-file /scripts/sweep-render-fatals.php http-case=divi/heading:title:object --user=1
docker compose exec -T wpcli php /usr/local/bin/wp eval-file /scripts/sweep-render-fatals.php cleanup --user=1
```

Dev site only. Leftover check: `docker compose exec -T wpcli wp post list --post_type=page --s="aied sweep" --post_status=any --format=ids --allow-root` (empty after this run).
