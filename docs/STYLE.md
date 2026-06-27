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

---
**Validator note:** none of these are required or type-checked by the validator —
they pass through untouched. They affect *rendering only*. The validator still
guarantees structure, known block types, and render-critical content keys.
