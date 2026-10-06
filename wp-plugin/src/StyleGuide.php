<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AI-facing Divi 5 authoring guide.
 *
 * Returns the real block structure rules + styling attribute shapes (mined
 * verbatim from real Divi 5 exports — see docs/STYLE.md) so an AI assistant can
 * produce valid, *styled* layouts instead of plain ones. Served by the
 * get_style_guide MCP tool and the GET /style-guide REST endpoint.
 */
final class StyleGuide
{
    /** The guide as Markdown. Pure — no WordPress calls — so it is trivially testable. */
    public static function markdown(): string
    {
        return <<<'MD'
# Divi 5 Layout Authoring Guide

Build `post_content` as Gutenberg block comments. Every Divi block carries
`"builderVersion":"5.8.0"`. Attribute values are keyed by breakpoint — use
`desktop` (Divi cascades to tablet/phone). All shapes below are confirmed from
real Divi 5 exports; do not invent attribute keys.

## Structure (nesting)
```
divi/placeholder              (root wrapper, required)
  divi/section                (a section; may also hold a column directly)
    divi/row
      divi/column             (holds modules; may also hold a nested row)
        [modules]
        divi/row-inner        (specialty nested row)
          divi/column-inner   (same children as divi/column)
            [modules]
```
**A `divi/column` can NEVER directly contain another `divi/column`** (doing so is
rejected with UNEXPECTED_CHILD_TYPE). To nest columns inside a column, go through a
row: **column → `divi/row-inner` → `divi/column-inner`** (or a plain `divi/row` →
`divi/column`). That row-in-column pattern is the ONLY way to build a multi-column
grid inside a column. When you just need ONE styled card container inside a column
(not real columns), use a **`divi/group`** instead — it holds modules directly and
takes the same `module.decoration` styling (background, border, radius, padding,
flex layout) as a column.

Compound modules: `divi/accordion>divi/accordion-item`,
`divi/counters>divi/counter`, `divi/icon-list>divi/icon-list-item`,
`divi/tabs>divi/tab`, `divi/slider>divi/slide`,
`divi/social-media-follow>divi/social-media-follow-network`.

## Required content (or the layout is rejected)
- `divi/heading`: `title.innerContent.desktop.value` = "Text"
- `divi/text`: `content.innerContent.desktop.value` = "<p>HTML</p>"
- `divi/image`: `image.innerContent.desktop.value` = `{ "src": "URL" }`
- `divi/button`: `button.innerContent.desktop.value` = `{ "text": "Label" }`
Images: never leave a `src` blank and never invent or hotlink a URL. Call
**get_image_guide**: use the site's Media Library first (**list_media_images**),
and the built-in image pack (real site-local URLs, listed in that guide) only
when the library has nothing suitable.

## Heading levels — exactly one H1
Set a heading's level with `title.decoration.font.font.{bp}.value.headingLevel`
= "h1".."h6". Use **exactly ONE h1 per page** (the main hero headline); make
section titles h2 and card/sub titles h3–h4. More than one h1 is REJECTED
(MULTIPLE_H1).

## Animated stat counter (divi/number-counter)
For metrics/stats that count up on scroll, prefer divi/number-counter over a
plain heading number:
`{"number":{"innerContent":{"desktop":{"value":"250000"}}},"title":{"innerContent":{"desktop":{"value":"Tasks Automated"}}},"builderVersion":"5.8.0"}`

## Styling attribute shapes
Put these alongside `builderVersion` on the relevant block. `{bp}` = `desktop`.

**Backgrounds** (section/row/column `module`):
- solid: `module.decoration.background.{bp}.value.color` = "#0a0a0a"
- gradient: `module.decoration.background.{bp}.value.gradient.enabled`="on",
  `…gradient.stops.0.{position,color}` (0,"rgba(10,10,10,.5)"),
  `…gradient.stops.1.{position,color}` (100,"rgba(0,0,0,.6)"), `…gradient.length`="100%"

**Spacing** (units px/vw/%/em):
`module.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}` = "6vw";
`…spacing.{bp}.value.margin.bottom` = "24px"

**Heading typography** (`divi/heading`):
`title.decoration.font.font.{bp}.value.{size,color,textAlign,lineHeight}`
= "5vw" / "#FFFFFF" / "center" / "1em"

**Body typography** (`divi/text`):
`content.decoration.bodyFont.body.font.{bp}.value.{size,color}` = "20px" / "#c9c9c9";
`module.advanced.text.text.{bp}.value.orientation` = "center"

**Button** (`divi/button`):
`button.decoration.background.{bp}.value.color` = "#ff4d00";
`button.decoration.font.font.{bp}.value.color` = "#FFFFFF";
`button.decoration.border.{bp}.value.radius.{topLeft,topRight,bottomRight,bottomLeft}` = "8px" (set ALL four);
hover: `button.decoration.background.{bp}.hover.color` = "#bf6631"

**Border & radius** (cards): set ALL FOUR corners — `sync:"on"` does NOT
propagate a single corner to the others at render, so setting only `topLeft`
leaves the other three at 0 (one rounded corner, three square).
`module.decoration.border.{bp}.value.radius.topLeft` = "16px";
`module.decoration.border.{bp}.value.radius.topRight` = "16px";
`module.decoration.border.{bp}.value.radius.bottomRight` = "16px";
`module.decoration.border.{bp}.value.radius.bottomLeft` = "16px";
`module.decoration.border.{bp}.value.radius.sync` = "on";
`module.decoration.border.{bp}.value.styles.all.{width,color}` = "2px","#FFFFFF"

**Glass card (glassmorphism look):** combine a semi-transparent background with a
subtle light border and radius on a Group/column/row:
`module.decoration.background.{bp}.value.color` = "rgba(18,18,18,0.75)" (or
"rgba(255,255,255,0.06)" on dark);
`module.decoration.border.{bp}.value.styles.all.{width,color}` = "1px","rgba(255,255,255,0.12)";
`module.decoration.border.{bp}.value.radius.{topLeft,topRight,bottomRight,bottomLeft}` = "20px" (all four).
True frosted-glass *backdrop-blur* (blurring what's behind the card) is NOT a
standard Divi attribute and needs CSS code, which this plugin never saves. Use the
semi-transparent + border recipe above for the glass look.

**Box shadow:**
`module.decoration.boxShadow.{bp}.value.{style,horizontal,vertical,blur,color}`
= "preset4","6px","-2px","40px","rgba(0,0,0,.5)"

**Transform / z-index / overflow:**
`module.decoration.transform.{bp}.value.translate.{x,y}` = "5%","15%";
`module.decoration.zIndex.{bp}.value` = 10;
`module.decoration.overflow.{bp}.value.{x,y}` = "hidden"

**CSS filters** (incl. hover): `<key>.decoration.filters.{bp}.value.brightness` = "120%"
(also contrast, saturate, blur, opacity); `.hover.` for hover.

**Entrance animation:**
`<key>.decoration.animation.{bp}.value.{style,direction,duration}`
= "slide"/"top"/"600ms" (styles: fade, slide, bounce, zoom, flip, roll, fold)

**Divider** (`divi/divider`): `divider.advanced.line.{bp}.value.{color,weight}`

## Font family, weight, style (serif headings + sans body)
On any font element (`title.decoration.font.font`, `content.decoration.bodyFont.body.font`,
`button.decoration.font.font`):
```
…font.{bp}.value.family        = "Playfair Display"   # any Google/Divi font; e.g. serif heading + sans body
…font.{bp}.value.weight        = "700"
…font.{bp}.value.letterSpacing = "2px"                # great for kickers
…font.{bp}.value.style         = ["uppercase"]        # array: "uppercase","italic","underline"
```
Styled H1–H6 inside a Text module: `content.decoration.headingFont.h2.font.{bp}.value.{color,lineHeight,textAlign,style}`.

## Flex layout — reliable multi-column (preferred over column-count)
Put a flex layout on a container, size children with `flexType` (24-unit grid):
```
# container (section/row/column/group):
module.decoration.layout.{bp}.value.display        = "flex"
module.decoration.layout.{bp}.value.flexDirection  = "row"      # "column" to stack
module.decoration.layout.{bp}.value.justifyContent = "center"
module.decoration.layout.{bp}.value.alignItems     = "center"   # "stretch" for equal-height cards
module.decoration.layout.{bp}.value.columnGap      = "32px"
module.decoration.layout.{bp}.value.rowGap         = "24px"
module.decoration.layout.{bp}.value.flexWrap       = "wrap"
# each child:
module.decoration.sizing.{bp}.value.flexType = "8_24"   # 8_24=1/3, 12_24=1/2, 6_24=1/4, 24_24=full
```
To stack on mobile: set the container's `layout.phone.value.flexDirection = "column"` and
each child's `sizing.phone.value.flexType = "24_24"`.

## Mobile: collapse multi-column rows to one column
On every row that has more than one column, add a phone layout that stacks the
columns vertically so the page is one column on mobile:
```
module.decoration.layout.phone.value = {"display":"flex","flexDirection":"column","rowGap":"0px"}
```
(Set this on the divi/row alongside its desktop columnStructure.)

## Buttons side by side (horizontal CTAs)
Two buttons placed directly in a column STACK vertically. To put a pair of CTAs
on one line, wrap them in a NESTED row whose single column has a flex-row layout:
```
divi/row -> divi/column with
  module.decoration.layout.desktop.value = {"display":"flex","flexDirection":"row",
    "justifyContent":"center","alignItems":"center","columnGap":"16px","rowGap":"12px","flexWrap":"wrap"}
  module.decoration.layout.phone.value   = {"display":"flex","flexDirection":"column","rowGap":"12px"}
-> [divi/button, divi/button]
```
So the hero/CTA structure is: section > row > column > [heading, text,
row(nested) > column(flex-row) > button + button]. Never leave two bare buttons
in a column expecting them side by side.

## Sizing & centering
```
module.decoration.sizing.{bp}.value.maxWidth  = "720px"   # constrain content width
module.decoration.sizing.{bp}.value.minHeight = "100px"
module.decoration.sizing.{bp}.value.alignment = "center"
# center a max-width element (which otherwise aligns left):
module.decoration.spacing.{bp}.value.margin.left  = "auto"
module.decoration.spacing.{bp}.value.margin.right = "auto"
```

## Background image positioning & gradient direction
```
module.decoration.background.{bp}.value.image.size     = "cover"        # or "contain"
module.decoration.background.{bp}.value.image.position = "right center"
module.decoration.background.{bp}.value.gradient.direction = "90deg"    # linear angle
module.decoration.background.{bp}.value.gradient.type      = "linear"   # linear|circular|elliptical|conic
```

## Absolute positioning & z-index (advanced)
```
module.decoration.position.{bp}.value.mode            = "absolute"
module.decoration.position.{bp}.value.origin.absolute = "bottom center"
module.decoration.position.{bp}.value.offset.vertical = "20px"
module.decoration.zIndex.{bp}.value                   = 10
```

## No custom code
Never write CSS, JavaScript or PHP code, and never use a Divi Code module. This plugin refuses them:
a layout with a CSS field (anything under a module's `css` attribute), a `divi/code` or
`divi/fullwidth-code` module, `<script>`, `<style>`, `<iframe>`, `<object>` or `<embed>` markup, a
`javascript:` link, an `on…=` event attribute or a PHP tag is rejected with `CUSTOM_CSS_NOT_ALLOWED`,
`CODE_MODULE_NOT_ALLOWED` or `SCRIPT_CONTENT_NOT_ALLOWED` and the exact path. Style with the module's own
decoration attributes above instead. Code already on a page (the owner's own) is left untouched when
you edit that page; you cannot add or change it. If a design needs something the decoration attributes
cannot express, tell the owner it is not possible through this plugin instead of working around it.

## Full-viewport height & background blend
```
module.decoration.sizing.{bp}.value.height    = "85vh"        # full-screen hero
module.decoration.background.{bp}.value.image.blend = "multiply"   # blend a bg image into the color/overlay
module.decoration.position.{bp}.value.mode    = "relative"     # also "absolute"
```

## Design guidance
Commit to ONE clear aesthetic direction per page (dark/moody, light/airy,
editorial, glassmorphism, bold/vibrant) — don't default to the same dark theme
every time. Pick a dominant + accent color (not evenly distributed) and reuse it
(e.g. dark: bg #0a0a0a/#111114, card #17171c, accent #ff4d00, white headings,
#c9c9c9 body). Give sections generous vertical padding (5–8vw), round cards
(12–20px), keep one accent color. Use box-shadow + subtle transforms for depth;
entrance animations sparingly. Always check text contrast against the lightest
part of its background (on gradients, the lightest stop).

## Worked example — one styled section
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#0a0a0a"}}},"spacing":{"desktop":{"value":{"padding":{"top":"6vw","bottom":"6vw"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Building Digital Experiences"}},"decoration":{"font":{"font":{"desktop":{"value":{"size":"5vw","color":"#FFFFFF","textAlign":"center","headingLevel":"h1"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Start a Project"}}},"decoration":{"background":{"desktop":{"value":{"color":"#ff4d00"}}},"border":{"desktop":{"value":{"radius":{"topLeft":"8px","topRight":"8px","bottomRight":"8px","bottomLeft":"8px","sync":"on"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
Always run validate_layout (or rely on update_page_layout/create_page, which
validate) before trusting a layout.

## Undo
Every save through this plugin (update_page_layout / edit_page_content) keeps the
page's previous content as a snapshot when it can (the result's `history.stored`
says whether it did). Up to the last 10 previous versions are kept per page, fewer
for very large pages (a per-page budget of about 768 KB keeps the saved history
small). If an edit was wrong and a snapshot was kept, `list_page_history` then
`restore_page_version` undoes it; the restore is itself undoable when its own
`history.stored` is true. Prefer small `edit_page_content` edits over rewriting a
whole page.

## Preview before saving
update_page_layout and edit_page_content accept `dry_run: true`. The call then runs the
same checks as a real save but saves nothing: it returns whether the result would pass
the checker plus a diff of what would change (blocks added, removed or changed, with the
before and after value of each changed setting, at most 50 changes). Use it for a big
rewrite or any edit you are not sure about, confirm that only the intended blocks
changed, then repeat the same call without `dry_run` to save. A preview of an invalid
layout still returns normally, with `valid: false` and the violations; fix them before
saving. A replaced or new section counts as one removed/added block; its children are
not listed.

## Match the site's structure
Call `get_site_structure` before building a page that should list a custom post type or
taxonomy, use an image at a particular registered size, or refer to Advanced Custom Fields
field names. It is read-only and never returns field values; it only tells you what exists.

## Show a custom field's value (dynamic content)
A module can show a value Divi reads from the page it sits on, such as an Advanced Custom
Fields (ACF) field, instead of fixed text. Call `get_site_structure` first (it lists the ACF
field names, labels and types) and use only a name it returns. Never invent a field name.
The binding is one `$variable(...)$` string that takes the place of the fixed value; Divi
fills it in when the page is shown. Write it exactly like this (inside a block comment the
quotes are written as \u0022), with the field's name in place of `FIELD_NAME`:
`$variable({\u0022type\u0022:\u0022content\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022post_meta_key\u0022,\u0022settings\u0022:{\u0022before\u0022:\u0022\u0022,\u0022after\u0022:\u0022\u0022,\u0022select_meta_key\u0022:\u0022custom_meta_FIELD_NAME\u0022,\u0022meta_key\u0022:\u0022\u0022,\u0022date_format\u0022:\u0022default\u0022,\u0022custom_date_format\u0022:\u0022\u0022,\u0022enable_html\u0022:\u0022off\u0022}}})$`
The `custom_meta_` prefix is part of the value. `before` and `after` wrap the value, for
example `<h2>` and `</h2>`. It is verified in
a text module's content (`content.innerContent.desktop.value`) and
a blurb's title (`title.innerContent.desktop.value.text`). The same string also works as an image's
`image.innerContent.desktop.value.src` (an ACF image field, whatever return format it uses;
keep `id` and `alt` empty) and as a button's `button.innerContent.desktop.value.linkUrl` (an
ACF URL field). Do not use it anywhere else, and do not use ACF repeater or loop fields.
The checker cannot see whether a field exists: a name the site does not have
renders an empty module, with no error. The save, create and preview tools add an `unknown_custom_fields` note
(inside `advisories.style_references`) when a layout binds a field that is not an ACF field
here and that no page has; replace it with a name `get_site_structure` returned. A field only
has a value on the pages its ACF field group applies to.

## Match the site (colors and presets)
Call `list_global_styles` before styling. It lists the site's own global colors (ids like
`gcid-…`), module presets and variables. Reuse a site color instead of hard-coding a hex:
write it as a color variable, exactly like this (inside a block comment the quotes are
written as \u0022):
`"color":"$variable({\u0022type\u0022:\u0022color\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gcid-XXXX\u0022,\u0022settings\u0022:{}}})$"`
with a real id from the tool in place of `gcid-XXXX`. The five theme colors
(`gcid-primary-color`, `gcid-secondary-color`, `gcid-heading-color`, `gcid-body-color`,
`gcid-link-color`) exist on every Divi 5 site. Never invent an id: an id the site does not
have leaves the color undefined, so the text silently loses its color.
A module with no `modulePreset` (or `"modulePreset":["default"]`) already gets the site's
default preset for its type, so most modules need nothing. Use a named preset only when its
name and `controls` fit, as `"modulePreset":["<id from the tool>"]`; an unknown id silently
falls back to the default preset.

Numbers and strings variables (ids like `gvid-…`, listed by the same tool with their values)
can be reused too, but only in four places that are verified: a **numbers**
variable as a `padding` value or as a **heading's** font `size`; a
**strings** variable as a heading's title text (`title.innerContent`) or as a text module's
content (`content.innerContent`). For a strings variable
the whole `$variable(...)$` token is the entire value of `title.innerContent.desktop.value` or `content.innerContent.desktop.value`.
Write the value exactly like this (inside a block comment the quotes are written as \u0022):
`"size":"$variable({\u0022type\u0022:\u0022content\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gvid-XXXX\u0022,\u0022settings\u0022:{}}})$"`
with a real id from the tool in place of `gvid-XXXX`. Do not cite a variable anywhere else,
and do not reference fonts, images, links or gradients variables: those are
listed for information only. Never invent an id: Divi silently drops a value (a padding, a
size, a line of text) that cites an id the site does not have, and the checker cannot see
that.

The tool is read-only: presets, colors and variables are created in Divi's Visual Builder
(its Variables Manager). The save, create and preview tools add an
`advisories.style_references` note when a layout cites a preset, color or variable id the
site does not have (`unknown_presets`, `unknown_colors`, `unknown_variables`); replace those
ids with the site's own.
MD;
    }
}
