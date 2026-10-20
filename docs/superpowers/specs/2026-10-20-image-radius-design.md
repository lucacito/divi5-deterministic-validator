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

## Outcome (2026-10-20)

Built on branch `feat/image-radius` (spec and plan from main 4fb6456) in two implementation tasks that ran in parallel
worktrees and were merged back, plus a follow-up rewording and this docs task. NO release, version bump or zip rebuild: the
tracked root zip is still the published 4.12.0 build; the validator (`src/`, `wp-plugin/validator/`) is untouched. No new
marker, role, adapter, probe, guide topic, MCP tool, REST route or OpenAPI operation. The spec was approved by the owner; the
Rulings below are the calls the owner might have made differently.

**What was built, per task.**
- **T1 Recipes, classification test, guide paragraph** (a2bfaac). 11 kit-like images now carry the whole-value token
  `"radius":"{{aied:kit:radius-media}}"` at the proven path `image.decoration.border.desktop.value.radius`; nothing else in
  their blocks changed. `KitAdapters::MEDIA_RADIUS_BLOCKS = ['divi/image']` (not a marker role, so not in `APPLIED`) drives
  one generated "Images" paragraph in `kit-rules`; the "recorded in the kit but no module takes it yet" clause (inside the
  Cards paragraph) now appears only when the const is empty, and "image radius" leaves the generated not-applied sentence
  while a block takes the media radius (`StyleGuide::kitRules($applied, $proven, $media)`, `notAppliedSentence($applied,
  $media)`). New `tests/ImageRadiusMigrationTest.php` (5 tests) and one new test in `StyleGuideKitRulesTest`.
- **T2 Render gate** (a0c7af5). Each `verify-kits` cell renders one unmarked `divi/image` on the light band (an image-pack
  token, width 50% and a 2:1 ratio as the recipes do) carrying the token. The expectation is read from the RESOLVED markup
  (four corners). New codes `media` (image missing or present twice; a run that checked no image fails with `FAIL media: no
  image was checked`), `media-radius` and `media-clip`; `overflow` now also covers the picture, its wrapper and the module box at
  1280/900/400. New `scripts/lib/kit-media-run.cjs`.
- **Follow-up** (1f6ecf8). Three recipe descriptions that promised rounded images (hero-split-left-overlap, hero-product-glow,
  portfolio-featured-grid) no longer do: the corners follow the kit and a 0px kit makes them square.
- **T3 Docs.** This Outcome, CLAUDE.md, `docs/token-usage.md` (`php scripts/measure-tokens.php`).

**The audit (script over `section-recipes.json`).** 46 `divi/image` blocks in 19 recipes; 25 carry a radius, all at
`image.decoration.border.desktop.value.radius` (no tablet or phone radius, none at another path). Classes: **11 token**,
**10 flush** (team-grid #0-#3, gallery-masonry-style #0-#5, all 0px), **4 shaped**: quote-single-large#0 (999px) and
split-image-text#0 (500px) are circles and pills, and founder-note#0 and contact-location-form#0 are ARCHES, 999px top and
32px bottom. That is a correction to the spec, which called the founder-note and contact-form images "circles": they are not
four equal corners. The other 21 images have no radius.

**The 11 migrated images, by recipe** (index among the recipe's `divi/image` blocks): portfolio-featured-grid #0-#4 (was
16px), hero-product-glow #0 (16px), testimonials-avatar-grid #0-#2 (4px), hero-split-left-overlap #0 (24px), card-grid-3 #1
(3vw). card-grid-3#1 and split-image-text#0 carried `sync:"on"`; the migrated image lost it with the whole radius object (the
token replaces the whole value), split-image-text keeps it. Kept literal: the four shaped and ten flush images above.

**Measured sizes and counts.** (`wc -c` and `php scripts/measure-tokens.php` at the end of Task 3.)
- `section-recipes.json` **671,687 -> 670,981 B** (-706 B; the first step was 670,983, the rewording of the three descriptions
  made it 2 B smaller; bound 716,800).
- Guide, before -> after (`docs/token-usage.md`): topic `kit-rules` **4,301 -> 4,607 B** (+306; budget +400); topic
  `style-kits` (the catalog) **8,453 -> 8,439 B** (-14, because "image radius" left the generated not-applied sentence);
  default guide **28,975 B, unchanged** (133 B under its 29,108 B bound); every `style-kit:<id>` topic unchanged in size (largest
  neo-brutal 9,599 B, 401 B under the 10,000 bound); the `topic` parameter description 258 characters, unchanged; the filtered
  recipe catalog (role hero) 3,914 -> 3,920 B (the reworded descriptions).
- Gate: pure tests `node scripts/test-verify-kits.cjs` **85 -> 91** (6 new: the sheet and token, expectations incl. a square
  kit and holes, media-radius, media-clip, overflow, count and empty run; the all-kits x all-languages offline test now also
  asserts, per cell, the image is present, valid, its four corners equal that kit's `radius.media` and its picture is a
  site-local pack URL). The one old assertion that changed: the card test counted `wp:divi/image` blocks in the sheet (2) and
  now counts 3 (the two card images plus the media image), with a message saying so. Synthetic Chromium
  (`scripts/test-kit-card-run.cjs`): a matching page passes at 1280/900/400, a square kit counts as checked, radius on the
  picture alone passes, and **11 deliberate departures** are caught with the intended code: wrong wrapper and picture corner,
  one wrong picture corner, no radius anywhere, a baked radius on a square kit, a rounded module box (all `media-radius`); a
  wrapper radius without clipping, a rounded picture cut by a smaller square wrapper, a picture sticking out of a
  non-clipping wrapper (`media-clip`); an image wider than the phone (`overflow`); the image missing and the image twice
  (`media`). Removing any of four key branches of `compareMedia` makes both suites fail.
- Suites: free **2666** tests (`make test`; 2658 at the forms adapter), Pro **2169** (`make test-pro`, untouched).
  Goldens (button, card, item, form) byte-identical.
- Live smoke (already run): 5 kits (quiet-solid, warm-pill, chunky-pill, underline-link, neo-brutal) x editorial and
  glass-dark: **30 PASS, 0 FAIL**. The real Divi 5.15.1 rounds BOTH `.et_pb_image_wrap` and the `img` at the kit's value (for
  example 4px under quiet-solid and 32px under warm-pill), with `overflow: hidden` on the wrapper and the module box square
  (0px).

**Rulings made** (in plain words).
1. *Kit-like images follow the kit.* The 11 images (photos with a decorative corner, including the three 4px avatars of
   testimonials-avatar-grid) take the kit's media radius. A kit with a large media radius makes those avatars round; that is
   the point of a kit, and they are not circles by intent.
2. *Shapes stay.* Circles, pills, arches and flush tiles are shape decisions, not corner decisions: a 999px avatar is a
   circle in every kit, an arch is an arch, a masonry or team tile is square. The classification test pins every one of the 25
   radii (11 token, 10 flush, 4 shaped with their exact corners); `BESPOKE_IMAGES` is empty, cap 0.
3. *Token only, no marker or adapter.* The path is already proven on `divi/image` (`ty-i-radius-16`) and the token already
   exists; the token is the whole radius value, so no corner key and no `sync` key can survive beside it (pinned by a test).
4. *The guide describes the path in words.* The "Images" paragraph says to write the four corners at the kit's media radius
   at `image.decoration.border.desktop.value.radius` and never names the `aied` token (kit-rules must not contain `aied`).
5. *A media radius of 0px is a valid square image.* 21 of 40 kits have it; the token resolves to `0px`, and the gate treats
   four zeros as a real expectation (a baked radius on a square kit fails).
6. *Pins moved on purpose, none weakened.* In `StyleGuideKitRulesTest` the "recorded ... no module takes it yet" entry of
   `MOVED` became the Images sentence, and the old sentence is still tested as appearing only for a planted empty const; the
   catalog size 8,453 became 8,439 and the catalog no longer has to contain "image radius"; the form-adapter bound is applied to
   `kitRules(null, null, [])` (its meaning is the form paragraph) next to a new `<= 4,301 + 400` bound on the shipped text.

**Known limits.**
- For the 21 kits whose media radius is 0px the 11 images are square, and for those the gate cannot show the token was applied
  (a square picture is what an unapplied token would also give); the other 19 kits do show it.
- Out of scope, as the spec said: images of blurbs and other modules, background-image corners, video, the sync flag, per-corner
  mixed radii (an arch keeps its own literal corners), and any new marker.
- Gate notes from the review: a descender gap under the picture inside the wrapper is not detected; the picture is found by its
  unique alt text, so if Divi rewrote the alt the run would fail loudly with `media ... not in the page`, never silently; the
  sheet's image is 50% wide with a 2:1 ratio, which is not every recipe's layout (the smoke showed no false `media-clip`), and the
  `hasRoot` skip is weak; the media-clip check is
  asked only of rounded kits.

**Guide headroom left.** Default guide 133 B (bound 29,108), largest kit topic 401 B (neo-brutal, bound 10,000), `kit-rules`
4,607 B (well under the 10,000 B topic bound), the `topic` parameter description 258 characters (pinned `<= 260`), the
`section-recipes.json` file 45,819 B under the 716,800 B bound.

**Owner decisions left.** Push the branch and merge; release (nothing is versioned or zipped); the blurb and testimonial
adapters (blurb images and testimonial portraits are the next module kinds that could take the media radius); image-to-layout later.

**Live gates** (Divi 5.15.1, 2026-10-20, head 1f6ecf8 for the code under test; the later commits only changed docs and one guide sentence, see below; one run at a time on the dev site). (1) Smoke before the chain: 5 kits (quiet-solid, warm-pill, chunky-pill, underline-link, neo-brutal) x editorial, glass-dark: 30 PASS 0 FAIL, with the image measured (e.g. 4px quiet-solid, 32px warm-pill, wrapper and picture both rounded, overflow hidden on the wrapper). (2) The full `make verify-kits`: **264 PASS, 0 FAIL** over 88 kit/language cells with the one image per cell (88 `NOTE media` lines), about 3 h 01 min (08:15 to 11:16). (3) The narrowed recipe render: the 33 recipes that carry a kit marker (the five migrated image recipes among them) plus 10 unmarked guard recipes chosen with a fixed seed, in three chunks of 15, 15 and 13: **258 PASS** (90 + 90 + 78), 0 KNOWN-FAIL, 0 FAIL, 0 low-contrast warnings, about 20 min; the full default `verify-recipes` was not run, by the narrowing the owner approved at the kit-limits branch. (4) `make verify-shapes`: the probe agrees with every expectation (2 of 2; the stray untracked `probe-results-known.json` was removed). After the chain the final review's wording follow-up was applied (the Images sentence now reads 'a photo that the recipe rounds follows it', kit-rules 4,592 -> 4,607 B, pinned) and the CLAUDE.md Card paragraph was corrected; neither touches what the render gate measures.
