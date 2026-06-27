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
`button.decoration.border.{bp}.value.radius.{sync,topLeft}` = "on","6px";
hover: `button.decoration.background.{bp}.hover.color` = "#bf6631"

**Border & radius** (cards):
`module.decoration.border.{bp}.value.radius.{sync,topLeft}` = "on","16px";
`module.decoration.border.{bp}.value.styles.all.{width,color}` = "2px","#FFFFFF"

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

## Design guidance
Pick one cohesive palette and reuse it (e.g. dark: bg #0a0a0a/#111114, card
#17171c, accent #ff4d00, white headings, #c9c9c9 body). Give sections generous
vertical padding (5–8vw), round cards (12–20px), keep one accent color. Use
box-shadow + subtle transforms for depth; entrance animations sparingly.

## Worked example — one styled section
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#0a0a0a"}}},"spacing":{"desktop":{"value":{"padding":{"top":"6vw","bottom":"6vw"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Building Digital Experiences"}},"decoration":{"font":{"font":{"desktop":{"value":{"size":"5vw","color":"#FFFFFF","textAlign":"center"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Start a Project"}}},"decoration":{"background":{"desktop":{"value":{"color":"#ff4d00"}}},"border":{"desktop":{"value":{"radius":{"sync":"on","topLeft":"6px"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
Always run validate_layout (or rely on update_page_layout/create_page, which
validate) before trusting a layout.
MD;
    }
}
