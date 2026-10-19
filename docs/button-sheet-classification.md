# Button sheet: classification and kit selection

Source: `docs/inspitarion/button-sheet-125.jpg` (125 numbered buttons; the sheet's order runs 01-12, 17-20, 13-16, 21 onwards).
Read against `fixtures/shapes/kit-button-vocabulary.md` (what Divi 5.15.1 proved for `divi/button`) and the Task 9a brief
(shadow none/soft/hard, hover lift, underline, gradient fill being added to the kit schema now). Written 2026-10-18.

## 1. Summary

The 125 buttons collapse a lot once colour is ignored (a design language supplies colour, so a red outline and a blue
outline are the same kit). **26 buildable** (21 new looks plus 5 already covered by kits we ship), **5 need a probe
first**, **28 need custom CSS** (notches, slashes, stripes, offset double frames, corner marks, split fills), **5 are icon
buttons**, **16 are "later"** (one-side or two-side borders other than an underline, different corner sizes on one button,
skew), and **45 are duplicates** of a button I kept. **Selection: 26 new kits** (21 buildable now + 5 after a probe). That
is fewer than the 35 to 50 hoped for: the sheet really does repeat itself (about 20 thin outlines, a dozen flat solid
rectangles, five outline pills). I did not pad the list with looks that are not on the sheet.

Short words used below: **square** = 0px corners, **small** = 2-4px, **soft** = 6-12px, **pill** = fully round.
**Caps** = capital letters. Hover is never visible on the sheet: every hover is my proposal, marked "(proposed)".

## 2. All 125 buttons

Decision set = fill · border (width, style, sides) · corners · shadow rest/hover · hover · caps.

| # | Looks like | Class | Decision set | Kit / reason |
|---|---|---|---|---|
| 01 | thin outline, square, caps | BUILDABLE | outline · 1px solid all · square · none/none · fill-swap (proposed) · caps | hairline-frame-caps |
| 02 | thin outline, dark label, caps | DUPLICATE OF #01 | | colour only |
| 03 | thin outline, caps, a bit smaller | DUPLICATE OF #01 | | size only |
| 04 | thin outline, border colour differs from label | DUPLICATE OF #01 | | colour only |
| 05 | outline open on the right side | LATER | | three-side border (schema has all or bottom) |
| 06 | 2px outline, tiny corners, caps | BUILDABLE | outline · 2px solid all · square · none/none · fill-swap (proposed) · caps | frame-caps |
| 07 | slanted, italic, hard black shadow, arrow | LATER | | skew (and an arrow icon) |
| 08 | 2px outline, caps | DUPLICATE OF #06 | | colour only |
| 09 | outline with a coloured block behind the first letters, arrow | CUSTOM CSS, SKIP | | partial fill block (plus icon) |
| 10 | 2px outline, dark label, caps | DUPLICATE OF #06 | | colour only |
| 11 | thin outline, small corners, caps | DUPLICATE OF #01 | | 2px corner difference, not noticeable |
| 12 | outline with gaps and a second offset edge | CUSTOM CSS, SKIP | | offset double frame |
| 13 | thin outline, caps | DUPLICATE OF #01 | | colour only |
| 14 | 3px outline, caps | DUPLICATE OF #21 | | 3px vs 4px, not noticeable |
| 15 | thin pale outline, caps | DUPLICATE OF #01 | | colour only |
| 16 | thin outline, caps | DUPLICATE OF #01 | | colour only |
| 17 | outline with coloured corner triangles | CUSTOM CSS, SKIP | | corner shapes |
| 18 | 2px outline, caps, solid offset shadow bottom-right | BUILDABLE | outline · 2px solid all · square · hard/hard · fill-swap (proposed) · caps | offset-frame-caps |
| 19 | thin black outline, caps, tiny coloured corner | CUSTOM CSS, SKIP | | corner accent |
| 20 | thin grey outline, soft corners, caps | BUILDABLE | outline · 1px solid all · soft · none/none · fill-swap (proposed) · caps | soft-hairline-caps |
| 21 | 4px outline, dark label, caps | BUILDABLE | outline · 4px solid all · square · none/none · invert (proposed) · caps | thick-frame-caps |
| 22 | thin outline, caps | DUPLICATE OF #01 | | colour only |
| 23 | 4px outline, soft corners, caps | BUILDABLE | outline · 4px solid all · soft · none/none · fill-swap (proposed) · caps | thick-rounded-caps |
| 24 | a vertical line on the left, then the label | LATER | | left-only border (proven, adapter cannot write it) |
| 25 | 2px outline, caps | DUPLICATE OF #06 | | colour only |
| 26 | thin outline, caps | DUPLICATE OF #01 | | colour only |
| 27 | thin outline, caps | DUPLICATE OF #01 | | colour only |
| 28 | 2px black outline, square, sentence case | BUILDABLE | outline · 2px solid all · square · none/none · fill-swap · no caps | already shipped: outline-2 (4px corners; the difference is hard to see) |
| 29 | thin outline with extra lines above and below | CUSTOM CSS, SKIP | | extra lines (pseudo-elements) |
| 30 | dotted lines above and below only | LATER | | top + bottom border |
| 31 | thick pale lines above and below only | LATER | | top + bottom border |
| 32 | dark fill, thin coloured rim, caps | BUILDABLE | solid (text role) · 1px solid all, accent rim · square · none/none · invert (proposed) · caps | rimmed-solid-caps |
| 33 | gradient, square, chevron icon | ICON, SKIP | | icon (gradient square not elsewhere on the sheet) |
| 34 | solid, square, sentence case | BUILDABLE | solid · 0px · square · none/none · invert (proposed) · no caps | flat-square |
| 35 | 2px outline pill | BUILDABLE | outline · 2px solid all · pill · none/none · fill-swap (proposed) · no caps | outline-pill |
| 36 | solid black, square | DUPLICATE OF #34 | | colour only |
| 37 | solid pill | BUILDABLE | solid · 0px · pill · none/none · none · no caps | already shipped: warm-pill |
| 38 | solid, square, soft shadow | BUILDABLE | solid · 0px · square · soft/soft · lift (proposed) · no caps | raised-square |
| 39 | thin pale border, dark label, caps | DUPLICATE OF #01 | | colour only |
| 40 | solid pill, soft shadow | NEEDS A PROBE | solid · 0px · pill · soft/soft · lift (proposed) · no caps | raised-pill |
| 41 | solid, square, caps, grey hard shadow | BUILDABLE | solid · 0px · square · hard/hard · lift (proposed) · caps | offset-block-caps |
| 42 | light grey fill, caps | DUPLICATE OF #117 | | colour only |
| 43 | 2px dark outline, caps | DUPLICATE OF #06 | | colour only |
| 44 | solid pill, caps | BUILDABLE | solid · 0px · pill · none/none · invert (proposed) · caps | pill-caps |
| 45 | solid, soft corners | BUILDABLE | solid · 0px · soft · none/none · none · no caps | already shipped: soft-solid |
| 46 | dark pill with two coloured patches | CUSTOM CSS, SKIP | | split multi-tone fill |
| 47 | thin outline, small corners, sentence case | BUILDABLE | outline · 1px solid all · small · none/none · fill-swap (proposed) · no caps | hairline-frame |
| 48 | thin outline with a thick band on top | LATER | | different width per side |
| 49 | gradient pill | NEEDS A PROBE | gradient (accent to second role, 90deg) · 0px · pill · none/none · invert (proposed) · no caps | gradient-pill |
| 50 | 3px outline, arrow before the label | ICON, SKIP | | icon; otherwise like #28 |
| 51 | gradient pill inside a grey ring | CUSTOM CSS, SKIP | | glow ring |
| 52 | dark fill, thin coloured rim, sentence case | BUILDABLE | solid (text role) · 1px solid all, accent rim · square · none/none · invert (proposed) · no caps | rimmed-solid |
| 53 | lines above and below only, rounded ends | LATER | | top + bottom border |
| 54 | light grey fill, sentence case | DUPLICATE OF #34 | | colour only |
| 55 | dark fill, soft corners | BUILDABLE | solid · 0px · soft · none/none · none · no caps | already shipped: soft-rounded / glass-edge |
| 56 | solid square | DUPLICATE OF #34 | | colour only |
| 57 | dark fill, small corners left, round right; red label | LATER | | different corner per side (label contrast also poor) |
| 58 | solid black square | DUPLICATE OF #34 | | colour only |
| 59 | solid, one sharp corner, others rounded | LATER | | different corner per side |
| 60 | thin outline pill | BUILDABLE | outline · 1px solid all · pill · none/none · fill-swap (proposed) · no caps | hairline-pill |
| 61 | solid square | DUPLICATE OF #34 | | colour only |
| 62 | solid with a curved cut along the bottom | CUSTOM CSS, SKIP | | separate curved shape |
| 63 | solid square | DUPLICATE OF #34 | | colour only |
| 64 | 3px outline, two sharp and two round corners | LATER | | different corner per side |
| 65 | solid black, round icon before the label | ICON, SKIP | | icon; otherwise like #34 |
| 66 | light fill, one round corner | LATER | | different corner per side |
| 67 | two overlapping slanted shapes | CUSTOM CSS, SKIP | | separate shapes |
| 68 | diagonal stripes, caps | CUSTOM CSS, SKIP | | striped texture |
| 69 | solid with notched ends | CUSTOM CSS, SKIP | | notches |
| 70 | offset partial lines above and below | CUSTOM CSS, SKIP | | offset partial lines |
| 71 | dark circle with arrow, then a plain label | ICON, SKIP | | icon |
| 72 | two offset outline frames | CUSTOM CSS, SKIP | | double offset frame |
| 73 | 3px outline, square, sentence case | BUILDABLE | outline · 4px solid all · square · none/none · fill-swap (proposed) · no caps | thick-frame (unclear: the corner may hide a second thin line) |
| 74 | plain label with a plus sign | ICON, SKIP | | icon; otherwise like #78 |
| 75 | thin outline with a soft coloured glow | NEEDS A PROBE | outline · 1px solid all · square · soft/soft (accent, translucent) · fill-swap (proposed) · no caps | hairline-halo |
| 76 | solid split in two tones | CUSTOM CSS, SKIP | | split fill |
| 77 | outline, two sharp and two round corners | LATER | | different corner per side |
| 78 | plain text, no box | BUILDABLE | ghost · 0px · square · none/none · none · no caps | text-link |
| 79 | 2px outline pill | DUPLICATE OF #35 | | colour only |
| 80 | dark fill, coloured label, caps | DUPLICATE OF #117 | | colour only |
| 81 | 2px outline, sentence case | DUPLICATE OF #28 | | colour only (shipped outline-2) |
| 82 | 2px outline, sentence case | DUPLICATE OF #28 | | colour only (shipped outline-2) |
| 83 | outline with small marks on each side | CUSTOM CSS, SKIP | | side marks |
| 84 | solid square | DUPLICATE OF #34 | | colour only |
| 85 | thin outline, caps | DUPLICATE OF #01 | | colour only |
| 86 | solid square | DUPLICATE OF #34 | | colour only |
| 87 | solid square, soft shadow | DUPLICATE OF #38 | | colour only |
| 88 | lines above and below only, caps | LATER | | top + bottom border |
| 89 | solid with an offset outline frame behind | CUSTOM CSS, SKIP | | double offset frame |
| 90 | lines left and right only | LATER | | left + right border |
| 91 | plain text, caps | BUILDABLE | ghost · 0px · square · none/none · none · caps | text-link-caps |
| 92 | outline with a diagonal slash | CUSTOM CSS, SKIP | | diagonal slash |
| 93 | solid, small corners | BUILDABLE | solid · 0px · small · none/none · none · no caps | already shipped: quiet-solid |
| 94 | outline with lines sticking out left and right | CUSTOM CSS, SKIP | | extra lines |
| 95 | plain text with a corner bracket | CUSTOM CSS, SKIP | | corner bracket |
| 96 | solid pill, caps | DUPLICATE OF #44 | | colour only |
| 97 | dark fill, small corners, darker edge at the bottom (3D) | NEEDS A PROBE | solid · 0px · small · hard (straight down)/hard · lift (proposed) · no caps | pressable |
| 98 | two offset outline frames | CUSTOM CSS, SKIP | | double offset frame |
| 99 | solid with corner brackets | CUSTOM CSS, SKIP | | corner brackets |
| 100 | solid pill | DUPLICATE OF #37 | | colour only (shipped warm-pill) |
| 101 | gradient, soft corners, big soft shadow | NEEDS A PROBE | gradient (accent to second role, 135deg) · 0px · soft · soft/soft · lift (proposed) · no caps | gradient-raised |
| 102 | white pill, big shadow, pink marks above and below | CUSTOM CSS, SKIP | | marks; the base look is #40 |
| 103 | light grey square | DUPLICATE OF #34 | | colour only |
| 104 | 3px pale outline, square | DUPLICATE OF #73 | | colour only |
| 105 | dark pill with a glowing spot | CUSTOM CSS, SKIP | | glow |
| 106 | broken frame (corners open) | CUSTOM CSS, SKIP | | partial offset lines |
| 107 | solid square, coloured soft glow | DUPLICATE OF #38 | | shadow colour only |
| 108 | 2px outline pill | DUPLICATE OF #35 | | colour only |
| 109 | 2px outline pill | DUPLICATE OF #35 | | colour only |
| 110 | 2px outline pill, caps, blurred text shadow | CUSTOM CSS, SKIP | | text shadow is not in the vocabulary |
| 111 | 2px outline, soft corners, sentence case | BUILDABLE | outline · 2px solid all · soft · none/none · fill-swap (proposed) · no caps | outline-soft-2 |
| 112 | thin outline, small corners | DUPLICATE OF #47 | | colour only |
| 113 | 2px outline pill | DUPLICATE OF #35 | | colour only |
| 114 | a vertical line on the left, then the label | LATER | | same as #24 |
| 115 | 2px outline, dark label | DUPLICATE OF #28 | | colour only (shipped outline-2) |
| 116 | outline with a coloured highlight under the label | CUSTOM CSS, SKIP | | separate highlight shape |
| 117 | solid black, square, bold caps | BUILDABLE | solid · 0px · square · none/none · invert (proposed) · caps | block-caps |
| 118 | plain text with a thick line under it | BUILDABLE | ghost · 3px solid bottom · square · none/none · none (proposed) · no caps | underline |
| 119 | 2px outline, dark label, caps | DUPLICATE OF #06 | | colour only |
| 120 | short offset lines above and below | CUSTOM CSS, SKIP | | offset partial lines |
| 121 | a line above the label only | LATER | | top-only border |
| 122 | solid pill | DUPLICATE OF #37 | | colour only (shipped warm-pill) |
| 123 | solid with an inner offset white edge | CUSTOM CSS, SKIP | | inner offset frame |
| 124 | plain text, caps | DUPLICATE OF #91 | | colour only |
| 125 | thin outline, square, sentence case | DUPLICATE OF #47 | | 0px vs 2px corners |

## 3. Selection: 26 new kits

Already covered by shipped kits (not duplicated): `outline-2` covers 28, 81, 82, 115 (and 50 without its icon);
`warm-pill` covers 37, 100, 122; `soft-solid` covers 45; `soft-rounded` / `glass-edge` cover 55; `quiet-solid` covers 93.
The other shipped kits (`hairline-mono`, `chunky-pill`, `steady-solid`, `heavy-block`, `raw-frame`, `gilt-hairline`) have
no exact twin on the sheet. Task 9a may add more kits; whoever merges should drop any of mine it duplicates.

Schema notation: `fill`, `color` (role), `border` {width, style, color, sides}, `radius.control`, `shadow` {rest, hover},
`hover`, `text` {capitalization, letterSpacing, weight}. Padding stays at the language default.

| id | Title | One line | Decision set | Sheet | Suits |
|---|---|---|---|---|---|
| hairline-frame-caps | Hairline frame, caps | Thin square outline with capital letters. | fill outline, color accent; border 1px solid all accent; radius 0px; shadow none/none; hover fill-swap; caps, 1px, 600 | 01 (02-04, 11, 13, 15, 16, 22, 26, 27, 39, 85) | luxe |
| frame-caps | Frame, caps | Firm 2px square outline in capitals. | outline, accent; 2px solid all accent; 0px; none/none; fill-swap; caps, 1px, 700 | 06 (08, 10, 25, 43, 119) | corporate-trust |
| offset-frame-caps | Offset frame | 2px square outline with a solid offset shadow. | outline, accent; 2px solid all accent; 0px; hard/hard; fill-swap; caps, 1px, 700 | 18 | brutalist-flat |
| soft-hairline-caps | Soft hairline, caps | Thin outline, softly rounded, in capitals. | outline, text; 1px solid all border; 8px; none/none; fill-swap; caps, 1px, 500 | 20 | soft-saas |
| thick-frame-caps | Thick frame, caps | Heavy 4px square outline, capitals. | outline, text; 4px solid all accent; 0px; none/none; invert; caps, 1px, 700 | 21 (14) | brutalist-flat |
| thick-rounded-caps | Thick rounded, caps | Heavy 4px outline with soft corners. | outline, text; 4px solid all accent; 10px; none/none; fill-swap; caps, 0px, 700 | 23 | playful-pastel |
| rimmed-solid-caps | Rimmed solid, caps | Dark fill with a thin accent rim, capitals. | solid, text; 1px solid all accent; 0px; none/none; invert; caps, 1px, 600 | 32 | tech-mono |
| rimmed-solid | Rimmed solid | Dark fill with a thin accent rim. | solid, text; 1px solid all accent; 0px; none/none; invert; none, 0px, 600 | 52 | glass-dark |
| flat-square | Flat square | Plain solid rectangle, no border. | solid, accent; 0px solid all same; 0px; none/none; invert; none, 0px, 600 | 34 (36, 54, 56, 58, 61, 63, 84, 86, 103) | corporate-trust |
| block-caps | Block caps | Solid square with bold capitals. | solid, accent; 0px; 0px; none/none; invert; caps, 1px, 800 | 117 (42, 80) | tech-mono |
| pill-caps | Pill caps | Solid pill with bold capitals. | solid, accent; 0px; 999px; none/none; invert; caps, 1px, 700 | 44 (96) | bold-vibrant |
| outline-pill | Outline pill | 2px outline, fully round. | outline, accent; 2px solid all accent; 999px; none/none; fill-swap; none, 0px, 600 | 35 (79, 108, 109, 113) | organic-warm |
| hairline-pill | Hairline pill | Thin outline, fully round. | outline, accent; 1px solid all accent; 999px; none/none; fill-swap; none, 0px, 500 | 60 | luxe |
| hairline-frame | Hairline frame | Thin outline, small corners, normal case. | outline, accent; 1px solid all accent; 3px; none/none; fill-swap; none, 0px, 500 | 47 (112, 125) | editorial |
| outline-soft-2 | Soft outline | 2px outline with soft corners. | outline, accent; 2px solid all accent; 8px; none/none; fill-swap; none, 0px, 600 | 111 | soft-saas |
| thick-frame | Thick frame | Heavy square outline, normal case. | outline, accent; 4px solid all accent; 0px; none/none; fill-swap; none, 0px, 700 | 73 (104) | playful-pastel |
| raised-square | Raised square | Solid square resting on a soft shadow; lifts on hover. | solid, accent; 0px; 0px; soft/soft; lift; none, 0px, 600 | 38 (87, 107) | corporate-trust |
| offset-block-caps | Offset block | Solid square, capitals, solid offset shadow. | solid, accent; 0px; 0px; hard/hard; lift; caps, 1px, 700 | 41 | brutalist-flat |
| text-link | Text link | Just the label, no box. | ghost, accent; 0px; 0px; none/none; none; none, 0px, 600 | 78 (74 without its icon) | editorial |
| text-link-caps | Text link, caps | Just the label in spaced capitals. | ghost, accent; 0px; 0px; none/none; none; caps, 2px, 700 | 91 (124) | luxe |
| underline | Underline | Label with a thick line under it. | ghost, text; 3px solid bottom accent; 0px; none/none; none; none, 0px, 600 | 118 | editorial |
| raised-pill | Raised pill | Solid pill on a soft shadow; lifts on hover. | solid, accent; 0px; 999px; soft/soft; lift; none, 0px, 600 | 40 (base of 102) | soft-saas |
| gradient-pill | Gradient pill | Pill with a two-colour gradient. | gradient, accent + color2 text, 90deg; 0px; 999px; none/none; none; none, 0px, 600 | 49 | bold-vibrant |
| gradient-raised | Gradient raised | Gradient with soft corners on a soft shadow. | gradient, accent + color2 text, 135deg; 0px; 8px; soft/soft; lift; none, 0px, 600 | 101 | glass-dark |
| hairline-halo | Hairline halo | Thin outline with a soft coloured glow. | outline, accent; 1px solid all accent; 0px; soft/soft (accent, translucent); fill-swap; none, 0px, 500 | 75 | glass-dark |
| pressable | Pressable | Solid, small corners, a solid edge straight below. | solid, accent; 0px; 4px; hard (down only)/hard; lift; none, 0px, 600 | 97 | playful-pastel |

Variety check: fills solid 9, outline 12, ghost 3, gradient 2 (plus shipped); borders none, 1px, 2px, 4px, underline;
corners square 15, small 2, soft 4, pill 5; shadows none 19, soft 4, hard 3; caps 11 vs normal 15; hovers fill-swap,
invert, lift, none. **Gap:** the sheet has no all-round dashed, dotted or double border button (30 is dotted but only
top and bottom; 72 and 98 are two offset frames, not a CSS double border), so none of those styles is selected even
though the vocabulary allows them. If the owner wants a dashed or double kit, it has to be designed outside this sheet.

## 4. Kits that need a probe first

- **raised-pill**: soft shadow on a pill. The shadow sits on the module wrapper, the corners on the button; the probe
  only measured the default (near-square) button. Check the shadow follows the round ends and is not a rectangle.
- **gradient-pill**: gradient stops written from two language colour roles (the probe used literal colours), on a pill;
  and the label contrast must hold against both stops.
- **gradient-raised**: same gradient question, plus gradient and soft shadow together on 8px corners.
- **hairline-halo**: a soft shadow in the accent colour at partial opacity (the new alpha colour token) behind a
  transparent outline button.
- **pressable**: a hard shadow straight down (0px across, about 4px down); the probe only proved 6px/6px, and on a
  rounded button (same wrapper-versus-corners question as raised-pill).

Lower-risk notes, not blockers: `offset-frame-caps` puts the hard shadow behind a transparent outline (the probe used a
filled button); `raised-square`, `offset-block-caps` and the hard/soft kits rely on Task 9a landing shadows and lift.

## 5. Skipped, by reason

- **Custom CSS (28):** 09 partial fill block; 12, 72, 89, 98, 123 offset or double frames; 17 corner triangles; 19 corner
  accent; 29, 94 extra lines; 46, 76 split fills; 51 glow ring; 62 curved cut; 67 overlapping slanted shapes; 68 stripes;
  69 notches; 70, 106, 120 offset partial lines; 83 side marks; 92 diagonal slash; 95, 99 corner brackets; 102 marks
  above and below; 105 glow spot; 110 text shadow; 116 highlight block under the label.
- **Icon (5):** 33 chevron, 50 arrow, 65 round icon, 71 arrow in a circle, 74 plus sign.
- **Later (16):** 07 skew; 05 three-side border; 24, 114 left-only line; 121 top-only line; 30, 31, 53, 88 top and bottom
  lines; 90 left and right lines; 48 thicker top edge; 57, 59, 64, 66, 77 different corners on one button.
- **Duplicates (45):** listed in the table with the button they repeat.

## Notes on honesty

- Hover is never shown on the sheet; all hovers are proposals from the allowed list.
- #73 is `unclear`: its corner may show a second thin line; I read it as a plain thick outline.
- The vocabulary file marks shadow, lift, underline and gradient as "later" because the adapter could not write them
  when it was written; Task 9a is adding exactly those four now, so kits using them are in the selection. Everything
  still "later" after 9a (hover darken, skew, left-only or other one-side borders, per-corner radius) is left out.
- Corner and border sizes are read by eye from a compressed JPEG; within a step (for example 3px vs 4px) they may be off.

## What was built (Task 9, 2026-10-18)

19 new kits were added to `wp-plugin/data/style-kits.json`, so 40 kits ship in all (the cap is 50). Each kit takes its
colours from the design language, so a sheet button and its kit match in shape, border, corners, shadow, hover and
capitals, not in colour. A rule now keeps near-duplicates out: two kits must differ in at least one of fill, border
style, border sides, border width by 2px or more, corner family (square 0-4px, soft 6-16px, round 20px and up, pill),
shadow, hover or capitals (`tests/StyleKitsTest.php`). Because of that rule a few looks from section 3 were folded into
a close neighbour instead of getting their own kit; they are marked "near-duplicate" below.

Look up a sheet number here:

| Sheet numbers | Kit |
|---|---|
| 01, 02, 03, 04, 11, 13, 15, 16, 22, 26, 27, 39, 85 | `hairline-frame-caps` |
| 06, 08, 10, 25, 43, 119 (2px outline, capitals) | `hairline-frame-caps` (near-duplicate: a 2px outline vs 1px; `frame-caps` was not added) |
| 14, 21 | `thick-frame-caps` |
| 18 | `offset-frame` |
| 20 | `soft-hairline-caps` |
| 23 | `thick-rounded-caps` |
| 52 | `rimmed-solid` (a 2px rim instead of 1px, so it differs visibly from `flat-square`) |
| 32 | `rimmed-solid` (the same look in capitals; one rimmed kit is enough) |
| 34, 36, 54, 56, 58, 61, 63, 84, 86, 103 | `flat-square` |
| 117, 42, 80 | `block-caps` |
| 44, 96 | `pill-caps` |
| 35, 79, 108, 109, 113 | `outline-pill` |
| 60 (thin outline pill) | `outline-pill` (near-duplicate: 1px vs 2px; `hairline-pill` was not added) |
| 111 | `outline-soft` |
| 73, 104 | `thick-frame` |
| 38, 87, 107 | `raised-square` |
| 41 | `offset-block-caps` |
| 78, 74 (without its icon) | `text-link` |
| 91, 124 | `text-link-caps` |
| 118 | `underlined-label` |
| 97 | `pressable` (a new `edge` shadow: a hard edge straight below, proven on Divi 5.15.1) |
| 75 | `hairline-halo` (a new `glow` shadow in the accent colour, proven on Divi 5.15.1) |
| 28, 81, 82, 115, 50 (without its icon) | `outline-2` (shipped before) |
| 47, 112, 125 (thin outline, small corners) | `outline-2` (near-duplicate: 1px vs 2px; `hairline-frame` was not added) |
| 37, 100, 122 | `warm-pill` (shipped before) |
| 40 (solid pill, soft shadow) | `warm-pill-soft` (shipped before; the same look at rest, so `raised-pill` was not added; the probe proved the shadow follows the round ends) |
| 45 | `soft-solid` (shipped before) |
| 55 | `soft-rounded` / `glass-edge` (shipped before) |
| 93 | `quiet-solid` (shipped before) |
| 49, 101 (gradients) | not built: a kit has no gradient fill, because no pair of colour roles gives a label readable on both ends of the gradient in all ten design languages |

Every other sheet number keeps the reason given in section 5 (custom CSS, icon, later, or a duplicate of a button above).
