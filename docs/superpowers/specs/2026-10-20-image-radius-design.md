# Image radius: a style kit also rounds images

Status: APPROVED (owner 2026-10-20: "go for the image radius"; the recommended decisions below are mine and are listed in the
Outcome). Branch `feat/image-radius` from main 4fb6456. Follows `2026-10-19-forms-adapter-design.md` and
`2026-10-18-style-kits-design.md`. NO release, version bump or zip rebuild.

## Intent

A kit records three radii: `control`, `card` and `media`. Buttons, fields, cards and items take the first two; `media` is
recorded but no module takes it ("recorded in the kit but no module takes it yet", the generated `kit-rules` text), so a
recipe's photos keep their baked corners while everything around them follows the kit.

## What the audit found

46 `divi/image` blocks in 19 recipes; 25 carry a radius, always at `image.decoration.border.{bp}.value.radius.{topLeft,topRight,
bottomRight,bottomLeft}` (and `sync`). Classes: **kit-like (11 blocks)**: portfolio-featured-grid x5 (16px), hero-product-glow
(16px), testimonials-avatar-grid x3 (4px), hero-split-left-overlap (24px), card-grid-3 #1 (3vw); **circles and pills (4)**:
quote-single-large and founder-note and contact-location-form (999px), split-image-text (500px); **flush (10)**: team-grid x4
and gallery-masonry-style x6 (0px, a tiled grid that must stay square). The other 21 images have no radius.

## What Divi allows (already proven)

The path is proven ON `divi/image`: candidate `ty-i-radius-16` (PASS, four corners 16px, `fixtures/shapes/probe-results-
typography.json`) and registered in `verified-shapes.json`. The whole-value token `{{aied:kit:radius-media}}` already exists
(`StyleKits::VALUE_TOKENS`, `valueToken()` = the same four-corner object) and the resolver replaces it anywhere in a block.
So there is NO new marker, role, adapter or probe: the 11 kit-like images take the token in place of their literal corners.

## Decisions (recommended, made by me)

1. The 11 kit-like images take `"radius":"{{aied:kit:radius-media}}"` (the `sync` key stays out). Circles, pills and flush
   images keep their literals: they are shape decisions, not corner decisions (a 999px avatar is a circle in every kit; a
   masonry tile is square). `testimonials-avatar-grid`'s three 4px avatars follow the kit (a kit with a large media radius
   makes them round).
2. A classification test pins every image radius in the library: token (the 11, by recipe and index), circle (>= 500px),
   flush (0px) or nothing; anything else fails; the shrink-only `BESPOKE_IMAGES` list is empty with cap 0.
3. Guide: the generated `kit-rules` gets an "Images" paragraph from a new const `KitAdapters::MEDIA_RADIUS_BLOCKS =
   ['divi/image']`; the "recorded in the kit but no module takes it yet" sentence is replaced (generated: it appears only when
   the const is empty). Budget: kit-rules <= 4,301 + 400 B; default guide 28,975 B unchanged; no kit topic grows; the `topic`
   parameter description unchanged (258).
4. Render gate: each `verify-kits` cell renders one `divi/image` carrying the token (light band) and checks the computed
   border radius of the image, no clipping of the picture, no overflow at 1280/900/400, code `media-radius`.
5. Kit with `media` 0px is a valid square image (21 of 40 kits): the token resolves to `0px`.

## Non-goals

Blurb and other modules' images, background-image corners, video, the sync flag, per-corner mixed radii, any new marker.

## Done means

All suites green; `make verify-shapes` ok; full `make verify-kits` passes with the `media-radius` code; the narrowed recipe
render of the migrated recipes plus the marker recipes passes; Outcome with real numbers; validator and zip untouched.
