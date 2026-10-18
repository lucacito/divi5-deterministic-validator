# Probe-proven attribute shapes (Divi 5.15.1)

Each section below is one candidate shape that `node scripts/verify-shapes.cjs` rendered on the real Divi of the Docker dev site: two throwaway draft pages identical except for the candidate's values, opened in Chromium at 1280, 900 and 400 px. A shape counts as proven when the emitted page styles or the computed styles of the module changed at some width and the page did not overflow a phone. `php scripts/promote-shapes.php` adds the proven paths to `fixtures/shapes/verified-shapes.json` (`how: probe`).

## heading-size-per-device

Block: `heading`. Set on top of the base block:

- `title.decoration.font.font.desktop.value.size` = `"48px"`
- `title.decoration.font.font.tablet.value.size` = `"36px"`
- `title.decoration.font.font.phone.value.size` = `"24px"`

Measured (page A = base, page B = base + the values above):

| Width | Changed | Computed-style differences | Stylesheet differs | Example |
|---|---|---|---|---|
| 1280 px | yes | 14 | yes | `0:div:block-size: 40px -> 58px` |
| 900 px | yes | 14 | yes | `0:div:block-size: 40px -> 46px` |
| 400 px | yes | 14 | yes | `0:div:block-size: 40px -> 34px` |

Phone overflow at 400 px: no.

Registered paths added: none (every path was already registered by a builder export, which stays the stronger evidence).

## image-aspect-ratio-4-3-cover

Block: `image`. Set on top of the base block:

- `module.advanced.sizing.desktop.value.aspectRatio` = `{"width":"4","height":"3"}`
- `image.decoration.fit.desktop.value.objectFit` = `"cover"`

Measured (page A = base, page B = base + the values above):

| Width | Changed | Computed-style differences | Stylesheet differs | Example |
|---|---|---|---|---|
| 1280 px | yes | 14 | yes | `0:div:block-size: 576px -> 768px` |
| 900 px | yes | 14 | yes | `0:div:block-size: 405px -> 540px` |
| 400 px | yes | 14 | yes | `0:div:block-size: 180px -> 240px` |

Phone overflow at 400 px: no.

Note: objectFit was measured only together with aspectRatio (see docs/guide-shapes-evidence-5.14.md) and is registered as part of the pair, not proven alone.

Registered paths added:

- `image.decoration.fit.{bp}.value.objectFit`
- `module.advanced.sizing.{bp}.value.aspectRatio.height`
- `module.advanced.sizing.{bp}.value.aspectRatio.width`
