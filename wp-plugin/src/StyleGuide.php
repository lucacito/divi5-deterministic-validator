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
Images: unless the user supplies a URL, use
`https://picsum.photos/seed/{keyword}/{w}/{h}`.

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
standard Divi attribute — it needs custom CSS via the top-level `css` key
(`css.desktop.value.mainElement` = "backdrop-filter:blur(12px);"). Use the
semi-transparent + border recipe for the glass look without custom CSS.

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

## Custom CSS — top-level `css` key (mined from a 2nd production site)
For anything decoration attributes can't express, use the TOP-LEVEL `css` key
(a sibling of `module`/`builderVersion`, NOT under `module`). Use the literal
token `selector` to target the module's main element:
```
css.{bp}.value.mainElement = "backdrop-filter: blur(12px);"
css.{bp}.value.freeForm    = "selector { backdrop-filter: blur(12px); } selector:after { content:''; display:block; }"
```
This is how to do TRUE frosted-glass (backdrop-filter — no native Divi attr),
keyframe `@keyframes` animations, and `::before`/`::after` pseudo-elements.
For reusable, SITE-WIDE CSS (a `.glass` class, global `@keyframes`), call the
premium `set_custom_css` tool instead of per-module css — it stores CSS in
WordPress Additional CSS (safe, no code execution).

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
MD;
    }
}
