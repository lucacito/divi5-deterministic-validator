# Divi 5 Styling Vocabulary

Real decoration/advanced attribute shapes, **mined verbatim from real exports**
(`fixtures/valid/page-23-style-reference.json`). Never invent shapes — only use
paths confirmed here or in another captured export. All paths sit on a block's
attribute object alongside `builderVersion`. `{bp}` is a breakpoint key:
`desktop` (also `tablet`, `phone`, `tabletWide`, `phoneWide`).

Colors observed as raw hex (`#2f5d58`, `#ff4d00`, `#FFFFFF`) and `rgba(...)`.
The lightly-styled exports instead used Divi global color variables
(`$variable({"type":"color",...})`) — both are valid.

## Background — solid color
```
module.decoration.background.{bp}.value.color        = "#0a0a0a"
```

## Background — gradient (with optional image overlay)
```
module.decoration.background.{bp}.value.gradient.enabled        = "on"
module.decoration.background.{bp}.value.gradient.stops.0.position = 0
module.decoration.background.{bp}.value.gradient.stops.0.color    = "rgba(10,10,10,0.5)"
module.decoration.background.{bp}.value.gradient.stops.1.position = 100
module.decoration.background.{bp}.value.gradient.stops.1.color    = "rgba(0,0,0,0.6)"
module.decoration.background.{bp}.value.gradient.length           = "100%"
module.decoration.background.{bp}.value.gradient.overlaysImage    = "on"
module.decoration.background.{bp}.value.enableColor               = "off"
module.decoration.background.{bp}.value.image.url                 = "https://…"
module.decoration.background.{bp}.value.image.parallax.enabled    = "on"
```

## Spacing — padding & margin (units: vw, px, %, em)
```
module.decoration.spacing.{bp}.value.padding.top            = "10vw"
module.decoration.spacing.{bp}.value.padding.{right,bottom,left}
module.decoration.spacing.{bp}.value.padding.syncVertical   = "on"
module.decoration.spacing.{bp}.value.padding.syncHorizontal = "off"
module.decoration.spacing.{bp}.value.margin.bottom          = "10px"
```

## Typography — heading (divi/heading `title`)
```
title.decoration.font.font.{bp}.value.size       = "5vw"
title.decoration.font.font.{bp}.value.color      = "#FFFFFF"
title.decoration.font.font.{bp}.value.textAlign  = "center"
title.decoration.font.font.{bp}.value.lineHeight = "1em"
title.decoration.font.textShadow.{bp}.value.style = "preset4"   # preset1..preset5
```

## Typography — body / link / quote (divi/text `content`)
```
content.decoration.bodyFont.body.font.{bp}.value.size  = "20px"
content.decoration.bodyFont.body.font.{bp}.value.color = "#2f5d58"
content.decoration.bodyFont.link.font.{bp}.value.color = "#ff4d00"
content.decoration.bodyFont.link.font.{bp}.hover.color = "#ff4d00"
content.decoration.bodyFont.quote.font.{bp}.value.color           = "#2f5d58"
content.decoration.bodyFont.quote.border.{bp}.value.styles.left.color = "#ff4d00"
module.advanced.text.text.{bp}.value.orientation = "center"
```

## Border & radius
```
module.decoration.border.{bp}.value.radius.sync       = "on"
module.decoration.border.{bp}.value.radius.topLeft    = "10vw"   # +topRight,bottomRight,bottomLeft
module.decoration.border.{bp}.value.styles.all.width  = "2px"
module.decoration.border.{bp}.value.styles.all.color  = "#FFFFFF"
```

## Button (divi/button)
```
button.decoration.background.{bp}.value.color        = "#2f5d58"
button.decoration.font.font.{bp}.value.color         = "#ff4d00"
button.decoration.border.{bp}.value.styles.all.color = "#ff4d00"
button.decoration.border.{bp}.value.styles.all.width = "0px"
button.decoration.border.{bp}.value.radius.topLeft   = "0px"   # +sync, other corners
```

## Text shadow (presets)
```
title.decoration.font.textShadow.{bp}.value.style = "preset4"
module.advanced.text.textShadow.{bp}.value.style  = "preset3"
module.advanced.text.textShadow.{bp}.value.color  = "RGBA(255,255,255,0)"
```

## Box shadow (mined from layouts 1–3)
```
module.decoration.boxShadow.{bp}.value.style      = "preset4"   # preset1..preset5
module.decoration.boxShadow.{bp}.value.horizontal = "6px"
module.decoration.boxShadow.{bp}.value.vertical   = "-2px"
module.decoration.boxShadow.{bp}.value.blur       = "40px"
module.decoration.boxShadow.{bp}.value.color      = "rgba(12,245,126,0.7)"
```

## Transform
```
module.decoration.transform.{bp}.value.translate.x      = "5%"
module.decoration.transform.{bp}.value.translate.y      = "15%"
module.decoration.transform.{bp}.value.translate.linked = "off"
```

## Hover states (`.hover.` replaces `.value.` on the same path)
```
button.decoration.background.{bp}.hover.color           = "#bf6631"
button.decoration.border.{bp}.hover.styles.all.color    = "#333333"
module.decoration.border.{bp}.hover.styles.all.color    = "#FFFFFF"
```

## z-index & overflow
```
module.decoration.zIndex.{bp}.value     = 10
module.decoration.overflow.{bp}.value.x = "hidden"
module.decoration.overflow.{bp}.value.y = "hidden"
```

## Scroll effects (fade on scroll)
```
module.decoration.scroll.{bp}.value.fade.enable           = "on"
module.decoration.scroll.{bp}.value.fade.viewport.{top,bottom,start,end} = 100 / 0 / 40 / 40
module.decoration.scroll.{bp}.value.fade.offset.{start,mid,end} = "100%" / 100 / "30%"
```

## Divider module (`divi/divider`)
```
divider.advanced.line.{bp}.value.color  = "rgba(255,255,255,0.25)"
divider.advanced.line.{bp}.value.weight = "2px"
```

## CSS filters (mined from layouts 4–5) — incl. hover
```
<element>.decoration.filters.{bp}.value.brightness = "200%"   # also contrast, saturate, blur, opacity, hue
<element>.decoration.filters.{bp}.hover.brightness = "200%"   # .hover. for hover state
```
Observed on a blurb's `imageIcon`; the `<element>.decoration.filters` path also
applies to `module` and other styled keys.

## Entrance animation
```
<element>.decoration.animation.{bp}.value.style           = "slide"   # fade, slide, bounce, zoom, flip, roll, fold
<element>.decoration.animation.{bp}.value.direction        = "top"
<element>.decoration.animation.{bp}.value.duration         = "600ms"
<element>.decoration.animation.{bp}.value.intensity.slide  = 2
```

## Nested rows — two real forms
- `divi/column → divi/row → divi/column → …` (page-23)
- `divi/column → divi/row-inner → divi/column-inner → modules` (layout-4, the
  specialty-section form). A `divi/section` may also hold a `divi/column` directly.

---
**Validator note:** none of these are required or type-checked by the validator —
they pass through untouched. They affect *rendering only*. The validator still
guarantees structure, known block types, and render-critical content keys.
