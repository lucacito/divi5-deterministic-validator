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
    /** The one line the default guide ends with: how to ask for a topic. */
    private const TOPIC_LINE = 'Topics: call get_style_guide with topic=<name>: design-languages, design-language:<id>.';

    /**
     * The topics served on request: `design-languages`, `design-language:<id>` for every design language, then the
     * vocabulary-family topics (each teaches only shapes proven on the real Divi; see docs/shape-evidence-5.15.1.md).
     *
     * @return list<string>
     */
    public static function topics(): array
    {
        $topics = [ 'design-languages' ];
        foreach ( DesignLanguages::ids() as $id ) {
            $topics[] = 'design-language:' . $id;
        }
        $topics[] = 'backgrounds';
        $topics[] = 'dividers';
        $topics[] = 'motion';
        $topics[] = 'hover-states';
        return $topics;
    }

    /**
     * The guide as Markdown. Pure — no WordPress calls — so it is trivially testable.
     * With no topic (null or ''), the default guide; with a topic, only that topic's text.
     * An unknown topic is a normal answer that lists the valid topics.
     */
    public static function markdown( ?string $topic = null ): string
    {
        if ( null === $topic || '' === $topic ) {
            return self::guide() . "\n\n" . self::TOPIC_LINE;
        }
        if ( 'design-languages' === $topic ) {
            return DesignLanguages::markdown();
        }
        if ( 'backgrounds' === $topic ) {
            return self::backgroundsTopic();
        }
        if ( 'dividers' === $topic ) {
            return self::dividersTopic();
        }
        if ( 'motion' === $topic ) {
            return self::motionTopic();
        }
        if ( 'hover-states' === $topic ) {
            return self::hoverStatesTopic();
        }
        if ( str_starts_with( $topic, 'design-language:' ) && in_array( $topic, self::topics(), true ) ) {
            return DesignLanguages::markdown( substr( $topic, strlen( 'design-language:' ) ) );
        }
        $lines = [ '# Unknown topic', '', sprintf( 'There is no topic "%s". Valid topics:', SectionRecipes::plain( $topic ) ), '' ];
        foreach ( self::topics() as $valid ) {
            $lines[] = '- `' . $valid . '`';
        }
        return implode( "\n", $lines ) . "\n";
    }

    /** Topic `backgrounds`: gradient, image, pattern and mask shapes, every one proven on the real Divi 5.15.1. At most 10,000 bytes. */
    private static function backgroundsTopic(): string
    {
        return <<<'MD'
# Backgrounds: gradients, images, patterns, masks

Every shape and style listed here was rendered on the real Divi 5.15.1 and changed the page; a rule marked (source) is read
from Divi's own code and was not rendered, and the Limits section says what was not measured. They work on a
`divi/section`, a `divi/row` and a `divi/column`. All paths below sit under
`module.decoration.background.desktop.value.` (for example `module.decoration.background.desktop.value.gradient.type`);
use `desktop`, Divi cascades it to tablet and phone. `color` is the plain fill under the rest.
Never use video (it needs a media file) and never write CSS.

## Gradient (`gradient.*`)
- `enabled` = "on" and `stops`: a list of `{"position":"0","color":"#hex"}` (position is a string, 0 to 100, in the unit of
  `length`). Two or more stops; three or more make a multi-stop blend.
- `type`: "linear" (default), "circular", "elliptical", "conic".
- `direction` (linear): an angle such as "135deg", or one of the eight keywords "to top", "to top right", "to right",
  "to bottom right", "to bottom", "to bottom left", "to left", "to top left". For "conic" it is the start angle ("90deg").
- `directionRadial` (circular, elliptical, conic): where the centre sits: one word (center, top, bottom, left, right) or a
  vertical-and-horizontal pair such as "top right".
- Repeating stripes: `repeat` = "on" together with a short `length` such as "25%".
- `overlaysImage` = "on" draws the gradient above the section's image (use a translucent gradient for text over a photo).
- A conic gradient has a hard seam at its start angle unless the first and last stop share a colour.

## Image (`image.*`)
- `url`: an image token from get_image_guide or a Media Library URL; never a remote or invented URL.
- `size`: "cover", "contain", or "custom" with `width` and `height` ("320px", "auto"); width and height only apply with "custom".
- `position`: a horizontal word plus a vertical word: "left top", "center top", "right top", "left center", "right center",
  "left bottom", "center bottom", "right bottom". `horizontalOffset` and `verticalOffset` ("40px") move the image away from
  the named edge, so they only act next to a named left or right (horizontal) and top or bottom (vertical) `position`; with
  the default centre they move nothing (source; measured as a stylesheet-only change). Set both together.
- `repeat`: "repeat", "repeat-x", "repeat-y", "space", "round" (default no-repeat); useful with a small custom size.
- `blend`: "multiply" or "soft-light" mix the image with the section's `color` (set a colour to blend with).
- `parallax.enabled` = "on" scrolls the image slower than the page; `parallax.method` = "off" selects Divi's other parallax method.

## Pattern (`pattern.*`)
A repeating texture. Set `enabled` = "on", a `style` and a `color` (use a low alpha such as rgba(255,255,255,0.08) so
text stays readable).
- `style`, 24 proven: 3d-diamonds, checkerboard, confetti, crosses, cubes, diagonal-stripes, diagonal-stripes-2, diamonds, honeycomb, inverted-chevrons, inverted-chevrons-2, ogees, pills, pinwheel, polka-dots, scallops, shippo, smiles, squares, triangles, tufted, waves, zig-zag, zig-zag-2.
- `size`: "cover", "contain", "stretch", or "custom" with `width` and `height` ("60px") as the tile size.
- `repeat`: "repeat-x", "repeat-y", "space", "round". `repeatOrigin`: "left top" or "right bottom" style words.
  `horizontalOffset` and `verticalOffset` shift the start, but only when `repeatOrigin` is also set, `repeat` is not "space"
  and `size` is not "stretch"; without those they change nothing (each missing case measured). Set both together.
  `repeat` and `repeatOrigin` also need a `size` other than "stretch" (source).
- `blend` = "multiply". `transform`: a list of "flipHorizontal", "flipVertical", "invert", "rotate"
  (rotate does nothing for crosses, pinwheel, polka-dots, shippo, tufted: their artwork is symmetric).

## Mask (`mask.*`)
A shape cut out of the section: the mask paints `color` over the section and leaves the section's own background visible
only inside the shape. Set `color` to the colour behind the section (the page or the neighbouring section), usually "#ffffff".
Set `enabled` = "on", a `style` and a `color`.
- `style`, 23 proven: arch, bean, blades, caret, chevrons, corner-blob, corner-lake, corner-paint, corner-pill, corner-square, diagonal, diagonal-bars, diagonal-bars-2, diagonal-pills, ellipse, floating-squares, honeycomb, layer-blob, paint, rock-stack, square-stripes, triangles, wave.
- `aspectRatio`: "landscape" (default), "square", "portrait".
- `size`: "cover", "contain", or "custom" with `width` and `height` ("60%", "auto"); `position` ("right top") applies
  only when `size` is not the default stretch (source), and `horizontalOffset` and `verticalOffset` ("40px") also need a
  `position` (without one they change nothing, measured); set both offsets together.
- `blend` = "multiply". `transform`: the same four words as a pattern; the rotate exception above is pattern-only (in Divi's
  files every one of the 23 mask styles has distinct rotated artwork; source).

## Worked examples (each validates)
Gradient:
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"gradient":{"enabled":"on","type":"linear","direction":"135deg","stops":[{"position":"0","color":"#1e3a8a"},{"position":"55","color":"#7c3aed"},{"position":"100","color":"#db2777"}]}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Plan, build, launch"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
Image under a translucent gradient (replace IMAGE_URL with the url you chose):
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"image":{"url":"IMAGE_URL","size":"cover","position":"left top"},"gradient":{"enabled":"on","overlaysImage":"on","stops":[{"position":"0","color":"rgba(10,10,40,0.85)"},{"position":"100","color":"rgba(10,10,40,0.35)"}]}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Words over a photo"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
Pattern:
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#1b2a4a","pattern":{"enabled":"on","style":"diagonal-stripes","color":"rgba(255,255,255,0.08)"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Calm texture behind text"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
Mask:
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#1b2a4a","mask":{"enabled":"on","style":"wave","color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"A wave edge"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
Text over a pattern, photo or conic gradient needs a contrast check; patterns and masks are decoration, never carry
meaning. A `tablet` or `phone` value replaces the desktop one at that width (rendered for the gradient `direction`;
other settings inherit the same way in Divi's code but were not rendered with overrides). Not measured, so do not rely on
them: hover and sticky states, blend modes beyond those named, units other than px and %, global colour references,
and combinations (a pattern with a mask, a mask with a gradient on one element).
MD;
    }

    /** Topic `dividers`: section top/bottom dividers, every one proven on the real Divi 5.15.1 (inner shadow is dead and says so). At most 10,000 bytes. */
    private static function dividersTopic(): string
    {
        return <<<'MD'
# Section dividers: wave, curve and slant edges between sections

Every shape and style listed here was rendered on the real Divi 5.15.1 and changed the page; a rule marked (source) is read
from Divi's own code and was not rendered, and the Limits section says what was not measured. Dividers exist on
`divi/section` only (a row or a column has none, source). A divider is a shape painted inside the section along its top or
bottom edge, over the section's own background.

All paths below sit under `module.advanced.dividers.top.desktop.value.` (top edge) or
`module.advanced.dividers.bottom.desktop.value.` (bottom edge), for example `module.advanced.dividers.bottom.desktop.value.style`.
Set the two edges independently. Never write CSS for a divider.

## Settings
- `style`: a name, default "none" ("none" set explicitly hides a divider). 26 proven (each rendered as a top divider and as a bottom divider): arrow, arrow2, arrow3, asymmetric, asymmetric2, asymmetric3, asymmetric4, clouds, clouds2, curve, curve2, graph, graph2, graph3, graph4, mountains, mountains2, ramp, ramp2, slant, slant2, triangle, wave, wave2, waves, waves2.
- `color`: a hex colour such as "#f8fafc". Without it the divider is filled with the plain background `color` of the
  NEIGHBOURING section: the previous section for a top divider, the next section for a bottom divider (rendered: a top divider
  took the colour of the section above, a bottom divider the colour of the section below). So the shape reads as the neighbour
  rising into this section. Give the neighbour a plain `module.decoration.background.desktop.value.color` that differs from this
  section's, or set `color` yourself (do this when the neighbour has only a gradient or image: its colour is not used, source).
  When both sections share one colour and `color` is unset, Divi paints the divider black (source).
- `height`: "100px" (default). "200px", "50px", "30px" and "50%" were rendered.
- `repeat`: "1x" (default) or "3x" tiles the shape horizontally; "3x" was rendered. `repeat` has no effect on 3 styles: clouds, clouds2, triangle (rendered: dead; Divi draws them as one stretched piece, source).
- `flip`: a list, ["horizontal"], ["vertical"] or ["horizontal","vertical"] (all three rendered on a bottom divider; on a top divider only ["vertical"] was rendered). Use it to point an
  asymmetric shape the other way; a vertical flip turns the shape upside down.
- `arrangement`: "below" (default) or "above". Rendered: "above" raises the divider's z-index from 1 to 10; Divi describes
  that as drawing it over rows and modules that overlap it (source). Leave the default unless content must tuck behind the shape.

## Using them well
- A divider is painted inside the section, so on a short section it crosses the content (observed on a screenshot, not in the
  evidence file: a default-padding section had its heading crossed by a 100px divider). Give that edge padding of at least the divider height, as in the examples.
- Dark section into a light neighbour (or the reverse) shows the shape best. Use one shape family on a page; pick the same
  `height` for every divider so the rhythm holds.
- No style overflowed a phone: all 26, top and bottom, rendered at 400 px with no horizontal scroll.

## Responsive
`tablet` and `phone` override the desktop value, for example `module.advanced.dividers.bottom.tablet.value.height` and
`module.advanced.dividers.bottom.phone.value.style`. Rendered: a tablet `height` of "50px" changed 900 and 400 px but not
1280; a phone `height` of "30px" changed only 400 px; a phone `style` of "none" hid the divider only at 400 px. Other
settings (`color`, `repeat`, `flip`, `arrangement`) were not rendered with overrides.

## Row gutter (block layout rows only)
`module.advanced.gutter.desktop.value.width` ("1" or "4", both rendered) sets the gap between a row's columns, but only on a
row whose layout `module.decoration.layout.desktop.value.display` is "block": "1" removed the gap, "4" widened it. On the
default flex row it does nothing (rendered: dead); use the layout's `columnGap` there.

## Inner shadow
Do not use `module.advanced.innerShadow`: on Divi 5.15.1 the front end draws no inner shadow for it (rendered: dead); the Visual Builder preview was not checked.

## Worked examples (each validates)
A wave from a dark hero into a light section (the next section's colour fills the wave):
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#1e3a8a"}}},"spacing":{"desktop":{"value":{"padding":{"top":"120px","bottom":"180px"}}}}},"advanced":{"dividers":{"bottom":{"desktop":{"value":{"style":"wave","height":"120px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"A wave into the next section"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#f1f5f9"}}},"spacing":{"desktop":{"value":{"padding":{"top":"80px","bottom":"80px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"The next section sets the divider colour"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#0f172a"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
A section with a top curve (smaller on phones), a flipped bottom slant with an explicit colour, between two plain sections:
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#fde68a"}}},"spacing":{"desktop":{"value":{"padding":{"top":"80px","bottom":"80px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Section above"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#1e293b"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#0f766e"}}},"spacing":{"desktop":{"value":{"padding":{"top":"140px","bottom":"140px"}}}}},"advanced":{"dividers":{"top":{"desktop":{"value":{"style":"curve","height":"90px"}},"phone":{"value":{"height":"40px"}}},"bottom":{"desktop":{"value":{"style":"slant","flip":["horizontal"],"color":"#f8fafc"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Top curve, flipped bottom slant, smaller on phones"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#f8fafc"}}},"spacing":{"desktop":{"value":{"padding":{"top":"80px","bottom":"80px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Section below"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#0f172a"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
Not measured, so do not rely on them: other height units, `repeat` values other than 3x, divider colours other than hex,
hover and sticky states, tablet and phone `color`, `repeat`, `flip` and `arrangement`, dividers on sections outside the page's own content,
the section gutter of a specialty section, and styles an add-on plugin registers.
MD;
    }

    /** Topic `motion`: entrance animation, scroll effects and sticky, every shape proven on the real Divi 5.15.1. At most 10,000 bytes. */
    private static function motionTopic(): string
    {
        return <<<'MD'
# Motion: entrance animation, scroll effects, sticky

Every shape and value listed here was rendered on the real Divi 5.15.1 (the front end of a draft page preview) and changed the
page; a rule marked (source) is read
from Divi's own code and was not rendered, and the Limits section says what was not measured. Motion was proven by what Divi
writes and computes (classes and inline settings on the element, computed transform, opacity and position, the element's
rectangle after scrolling), not by watching the motion play. Never claim how a motion looks, and never write CSS or script for
motion. Hover effects and transitions are in the topic `hover-states`.

## Entrance animation
Paths below sit under `module.decoration.animation.{bp}.value.`. Divi writes the animation onto the element (the classes
`et_animated` and the style name, and inline animation properties) and removes them when it ends (observed on a test page, not in the evidence file); the element starts at opacity 0
unless `startingOpacity` says otherwise (rendered, with the element at the top of the page; that it starts when the element scrolls
into view was not measured).
- `style`: one of the styles below; with no style there is no animation
- `direction`: only on styles that take one
- `duration`: "2000ms" rendered; the default is 1000ms (source)
- `delay`: "8000ms" rendered; the default is 0ms (source)
- `repeat`: "loop" rendered; "once" is the default (source)
- `speedCurve`: a hyphenated name
- `startingOpacity`: "40%" rendered; the default is 0% (source)
- `intensity.slide`: "20%" rendered; the default is 50% (source)
- `intensity.zoom`: "20%" rendered
- `intensity.flip`: "20%" rendered
- `intensity.fold`: "20%" rendered
- `intensity.roll`: "20%" rendered
- `button.decoration.animation.{bp}.value.style`: the same on the button element path
Styles: 7 proven: bounce, fade, flip, fold, roll, slide, zoom.
Styles that take a direction: 6 proven: bounce, flip, fold, roll, slide, zoom. `direction` on fade did nothing (rendered: dead).
Directions: 5 proven: bottom, center, left, right, top. center is the default (source).
Speed curves: 5 proven: ease, ease-in, ease-in-out, ease-out, linear. The default is ease-in-out (source).
Hosts: 8 proven: blurb, button, column, group, heading, image, row, section. The shape is the same on each.
Rules: an `intensity.slide` on fade did nothing (rendered: dead); fade and bounce have no intensity (source). A tablet `duration` of
"2500ms" changed the page at 900 px only; the phone width kept the desktop value. A phone `style` of "none" did not switch the
animation off at 400 px (rendered: dead), so do not rely on a per-device off switch.

## Scroll effects
Paths below sit under `module.decoration.scroll.{bp}.value.`. Divi's script applies them while the page scrolls. Proven on a
section only, by scrolling the section to a set position and comparing it with an identical section that had no effect.
- `verticalMotion.enable`: "on"; moves the section up or down (changed transform; its rectangle moved)
- `horizontalMotion.enable`: "on"; moves it sideways (changed transform; its rectangle moved)
- `fade.enable`: "on"; changed opacity
- `scaling.enable`: "on"; changed transform
- `rotating.enable`: "on"; changed transform
- `blur.enable`: "on"; changed the filter
- `verticalMotion.offset.start`: "20" rendered; the default is "4" (source); `offset.end` and `offset.mid` are the other two
- `verticalMotion.offset.end`: "-20" rendered; the default is "-4" (source)
- `verticalMotion.viewport.top`: "60" rendered; it changed the render at 900 px only, so it depends on where the section sits
- `fade.offset.start`: "50" rendered; the default is "0" (source)
- `motionTriggerStart`: "top" rendered; "middle" is the default and "bottom" exists (source)
Effects: 6 proven: blur, fade, horizontalMotion, rotating, scaling, verticalMotion.
Rules: an `offset` without its effect switched on did nothing (rendered: dead); `viewport` has the same switch (source). Two effects together (fade and verticalMotion)
rendered. A phone `verticalMotion.enable` of "off" removed the effect at 400 px only. Defaults for every `viewport` and `offset`
are Divi's own (source).

## Sticky
Paths below sit under `module.decoration.sticky.{bp}.value.`. Proven on a section only: scrolled past, the section became
`position: fixed` at the screen edge (computed at 1280 and 400 px).
- `position`: top, bottom or topBottom
- `offset.top`: "80px" rendered, with position top; the default is 0px (source)
- `offset.bottom`: "60px" rendered, with position bottom
Positions: 4 proven: bottom, none, top, topBottom. none was rendered as a phone override ("none" on phones turned a desktop sticky
section off at 400 px only).
An offset without a `position` did nothing (rendered: dead). Use one sticky bar per page.

## Using motion well (strategy, not measured)
One entrance style per page, applied to the hero and the main cards; stagger neighbours by 100 to 200ms of `delay`. Keep
`scale` on hover small (103 to 108%) and transitions short (200 to 400ms). Scroll effects: one or two gentle ones.

## Worked examples (each validates)
A heading that slides in from the left, then a paragraph that fades in after it:
```
<!-- wp:divi/section {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"80px","bottom":"80px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"module":{"decoration":{"animation":{"desktop":{"value":{"style":"slide","direction":"left","duration":"800ms","intensity":{"slide":"20%"}}}}}},"title":{"innerContent":{"desktop":{"value":"Slides in from the left"}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/text {"module":{"decoration":{"animation":{"desktop":{"value":{"style":"fade","duration":"800ms","delay":"200ms"}}}}},"content":{"innerContent":{"desktop":{"value":"<p>Fades in 200ms later.</p>"}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
A bar that sticks to the top of the screen, and a section that fades as it scrolls:
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#0f172a"}}},"sticky":{"desktop":{"value":{"position":"top","offset":{"top":"0px"}}}},"spacing":{"desktop":{"value":{"padding":{"top":"16px","bottom":"16px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Sticky bar"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
<!-- wp:divi/section {"module":{"decoration":{"scroll":{"desktop":{"value":{"fade":{"enable":"on"}}}},"spacing":{"desktop":{"value":{"padding":{"top":"200px","bottom":"200px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Fades while scrolling"}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
Not measured, so do not rely on them: `decoration.interactions` (not built, not taught), scroll effects and sticky on anything but
a section, sticky limits, offset surrounding and its own transition, other durations, delays and percentages, animation of
elements below the fold, and how any motion looks. Divi's animation, scroll and sticky code was searched for a reduced-motion
rule and none was found, so keep motion short and gentle; the plugin cannot add one.
MD;
    }

    /** Topic `hover-states`: hover values, transforms and transitions, every shape proven on the real Divi 5.15.1. At most 10,000 bytes. */
    private static function hoverStatesTopic(): string
    {
        return <<<'MD'
# Hover states, transforms and transitions

Every shape and value listed here was rendered on the real Divi 5.15.1 (the front end of a draft page preview) and changed the
page; a rule marked (source) is read
from Divi's own code and was not rendered, and the Limits section says what was not measured. Motion was proven by what Divi
writes and computes (the computed colour, shadow, transform and filter with the pointer on the element, the transition timing),
not by watching the motion play. Never claim how a hover or a transition looks, and never write CSS or script for them. Entrance
animation, scroll effects and sticky are in the topic `motion`.

## Hover and transform
A hover value sits next to the normal one under the key `hover` instead of `value`: `module.decoration.background.{bp}.hover.color`.
Measured on the desktop hover state only, with the pointer on the element. A hover colour worked without a normal colour set,
but set both so the starting colour is yours. Each setting is named with the element it was proven on.
- `module.decoration.background.{bp}.hover.color`: hex, on a group
- `module.decoration.border.{bp}.hover.styles.all.color`: hex, on a group with a normal border
- `module.decoration.boxShadow.{bp}.hover.style`: a preset name; on a group and on the button module
- `module.decoration.boxShadow.{bp}.hover.vertical`: "20px", on a group, together with `style`
- `module.decoration.boxShadow.{bp}.hover.blur`: "40px", on a group, together with `style`
- `module.decoration.boxShadow.{bp}.hover.color`: "rgba(0,0,0,0.35)", on a group, together with `style`
- `module.decoration.transform.{bp}.hover.scale.x`: "105%" on a group, "108%" on the button module; set `scale.y` too
- `module.decoration.transform.{bp}.hover.scale.y`: the same value as `scale.x`
- `module.decoration.transform.{bp}.hover.translate.y`: "-8px", on a group (lifts a card)
- `module.decoration.transform.{bp}.hover.rotate.z`: "3deg", on a group
- `module.decoration.filters.{bp}.hover.brightness`: "120%", on a group
- `module.decoration.filters.{bp}.hover.saturate`: "160%", on a group
- `title.decoration.font.font.{bp}.hover.color`: text colour, on a heading
- `button.decoration.background.{bp}.hover.color`: hex, on a button
- `button.decoration.background.{bp}.hover.enableColor`: "on" next to the colour, as the builder saves it
- `button.decoration.font.font.{bp}.hover.color`: text colour, on a button
- `button.decoration.border.{bp}.hover.styles.all.color`: hex, on a button with a normal border
- `module.decoration.transform.{bp}.value.scale.x`: "90%" (not hover: a resting scale), on a group; `scale.y` likewise
- `module.decoration.transform.{bp}.value.scale.y`: "90%"
- `module.decoration.transform.{bp}.value.skew.x`: "6deg", on a group
Shadow presets: 3 proven: preset1, preset3, preset6. preset3 is outer; preset6 is inner (inset). preset2, preset4, preset5 and
preset7 exist (source) but were not rendered. On a button, `button.decoration.boxShadow` and `button.decoration.transform` did
nothing (rendered: dead); use the module paths above.

## Transition
Paths below sit under `module.decoration.transition.{bp}.value.`. A transition is emitted only for a state the module has
(source; rendered: a duration on a group with no hover value did nothing), so give the element a hover value first.
- `duration`: "800ms" and "1500ms" rendered; the default is 300ms (source)
- `delay`: "200ms" rendered; the default is 0ms (source)
- `speedCurve`: a camelCase name
Speed curves: 5 proven: ease, easeIn, easeInOut, easeOut, linear. The default is ease (source); ease was shown against a linear base.
Responsive: a tablet `duration` of "1500ms" changed the computed duration at 900 and 400 px and not at 1280.

## Worked example (validates)
A card that lifts, gains a shadow and a border colour on hover, with a transition:
```
<!-- wp:divi/section {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"80px","bottom":"80px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"}}},"border":{"desktop":{"value":{"styles":{"all":{"width":"2px","color":"#e2e8f0"}}},"hover":{"styles":{"all":{"color":"#1e3a8a"}}}}},"boxShadow":{"desktop":{"hover":{"style":"preset3"}}},"transform":{"desktop":{"hover":{"translate":{"y":"-8px"}}}},"transition":{"desktop":{"value":{"duration":"300ms","speedCurve":"easeOut"}}},"spacing":{"desktop":{"value":{"padding":{"top":"32px","right":"32px","bottom":"32px","left":"32px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"A card that lifts on hover"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#0f172a"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/group -->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
## Limits
Not measured, so do not rely on them: hover on other elements, hover at tablet and phone, the other hover settings (spacing, font
size, text shadow, gradient or image background), transition on the sticky state, other durations, delays and percentages, and how
any hover looks. Touch screens have no hover: never put essential information behind one.
MD;
    }

    private static function guide(): string
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

**Sticky** (a header bar that stays on screen while the page scrolls), on a section, row or module:
`module.decoration.sticky.{bp}.value.position` = "top" (or "bottom", or "topBottom"), and optionally
`module.decoration.sticky.{bp}.value.offset.top` = "20px" (the gap from the top of the screen).
**Scroll effects** (a section that drifts, fades, scales, rotates or blurs as the page scrolls):
`module.decoration.scroll.{bp}.value.verticalMotion.enable` = "on". The same shape turns on
`horizontalMotion`, `fade`, `scaling`, `rotating` and `blur` (each also has `viewport` {top, start, end, bottom} and
`offset` {start, mid, end}; leave them out and Divi uses its own defaults, which are right for most pages).
Checked on Divi 5.15.1: `position` "top"/"bottom"/"topBottom" and `offset.top`, and `verticalMotion.enable`, are exactly what the
builder saves; a section with position "top" stays fixed near the top of the screen while the page scrolls, and with
`verticalMotion` or `fade` on it moves or fades as it scrolls (`make verify-guide-shapes`). Use them sparingly: one sticky bar,
one or two gentle effects. Do not guess other names under `sticky` or `scroll`.

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

## Per-device (responsive) settings
Most settings have one value per device under the same attribute, keyed `desktop`, `tablet` and `phone`
(`tabletWide` and `phoneWide` are the in-between devices). Set the desktop value, then add `tablet` and `phone` only where
they differ. All of these are verified on Divi 5.14, and again on 5.15.1, by measuring a real page at three screen widths (`make verify-guide-shapes`).

**Smaller headline on phones** (48px on desktop, 36px on a tablet, 24px on a phone):
`title.decoration.font.font.desktop.value.size` = "48px", `...tablet.value.size` = "36px", `...phone.value.size` = "24px".

**Body text size and alignment per device** (a text module): `content.decoration.bodyFont.body.font.{bp}.value.size`
and `.textAlign` (for example "center" on desktop, "left" on a phone). A heading's alignment is
`title.decoration.font.font.{bp}.value.textAlign`.

**Spacing per device**: `module.decoration.spacing.{bp}.value.padding.top` (also `.right`, `.bottom`, `.left`) and
`margin.bottom`, for example "60px" on desktop and "20px" on a phone.

**Columns stack on a phone** only when the row says so: a plain row of two columns stays side by side on a phone. These
are the exact shapes Divi's own builder writes (verified by measuring the result at desktop, tablet and phone widths).
For N equal columns, set on the `divi/row`:
`module.advanced.columnStructure.desktop.value` = "1_2,1_2" (N=2), "1_3,1_3,1_3" (N=3) or "1_4,1_4,1_4,1_4" (N=4);
`module.advanced.flexColumnStructure.desktop.value` = "equal-columns_N";
`module.decoration.layout.desktop.value.flexWrap` = "nowrap", and `flexWrap` = "wrap" for `phone` and `phoneWide`
(for N=4 use `tablet` and `tabletWide` instead, so four columns go two by two on a tablet).
On each `divi/column`: `module.advanced.type.desktop.value` = "1_2" / "1_3" / "1_4" and
`module.decoration.sizing.{bp}.value.flexType`: desktop "12_24" / "8_24" / "6_24", phone and phoneWide "24_24"
(N=4 also tablet and tabletWide "12_24").

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
Close every quote and bracket in a style value (`calc(10px + 2vw)`, `"Open Sans"`): Divi writes the value into the
page's stylesheet as it is, so an unclosed `'`, `"` or `(` makes the browser drop every rule after it and the page loses
all its custom styling. Such a value is rejected with `STYLE_VALUE_BREAKS_PAGE` (measured on Divi 5.15.1).

## Full-viewport height & background blend
```
module.decoration.sizing.{bp}.value.height    = "85vh"        # full-screen hero
module.decoration.background.{bp}.value.image.blend = "multiply"   # blend a bg image into the color/overlay
module.decoration.position.{bp}.value.mode    = "relative"     # also "absolute"
```

## Design guidance
Pick a design language first: call get_style_guide topic=design-languages, choose the one that fits the brief and state it before you build (don't default to the same dark theme). Then compose, don't repeat:
- never the same layout in adjacent sections; alternate background treatment (light, tinted, dark, image or gradient)
- at most one card grid per three sections
- give the first section a focal element (image, gradient or large type)
Use a dominant + accent color, generous vertical padding (5–8vw), round cards (12–20px), box-shadow and subtle transforms for depth, entrance animations sparingly. Check text contrast against the lightest part of its background (on gradients, the lightest stop).
If a save or validate result carries `advisories.design_variety`, act on it: if a finding's `try` has names, swap that section for one of them (get_section_recipes); if `try` is empty, follow the finding's `message`. Then save again. It is a hint only: it never blocks a save, and the page is saved as it is.

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

## Read a big page cheaply
A full `get_page_layout` read of a real page costs 8,000 to 16,000 tokens. Ask for `view: "outline"` first: it lists every
block with a `path` (such as `0.0.1.2`), its name, its size in bytes and the start of its text, for about a sixth of the cost.
Then `view: "block"` with a `path` returns that one block's exact markup, which you can use as an `edit_page_content` find
string or to rebuild just that part. Leave `view` out for the whole layout (needed before replacing the whole page).

## Page title vs headline
The **page title** is WordPress's own title for the page: what shows in the page list,
the browser tab (usually) and search. Change it with `update_page_layout` and `title`
(`title` alone is fine; the layout is then left untouched). The **headline** is a heading
module inside the layout, often the `h1`: change it with `edit_page_content` or
`update_page_layout` with `post_content`. They are separate: a layout edit never changes the page title, and a title change never
rewrites text typed into the layout.
A heading typed into the layout is layout text and does not change when the title changes.
A Divi post title module (`divi/post-title`) shows the WordPress page title, so it follows
a rename (checked on Divi 5.14); a heading module with the words typed in does not follow it.

- When the owner says "change the title" and the layout has an `h1` that repeats the old
  title, say so and ask whether to change both. Never report a layout edit as a title
  change, or the other way round.
- This tool changes only the WordPress page title, not menu labels (a menu may keep
  showing the old label: tell the owner to check their menu), the page address (the slug),
  or a browser-tab or SEO title set by an SEO plugin: say so, do not improvise a workaround.
- A title change cannot be undone with `restore_page_version` (a restore brings back the
  layout only). Tell the owner the old title; the result of the call contains it.

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
If a preview or a save would remove more than half of the page's text, images or modules,
the result carries `advisories.content_loss` (counts before and after, and which kinds
were lost). That is a warning, not a refusal: check it against what the person asked for,
read the page again with `get_page_layout` if you wrote from a partial copy, and put back
what was dropped.

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
has a value on the pages its ACF field group applies to: each group in `get_site_structure`
has a `location` list of alternatives (any one set applying is enough), each a list of rules
that must all match, such as `post_type == page`. Bind only fields whose group applies to the page you are editing.
`get_site_structure` also lists, in its `custom_fields` section, the custom field keys this
site's posts really use, ACF or not (a sample of recent posts; keys only). To see what a
field holds on one page or Project, call `get_custom_fields` with its id: it returns each
key with its value (never protected or secret-looking keys), so you can write copy that
fits. A key with no ACF field behind it binds the same way in a text module: put its name in place of
`FIELD_NAME`.

If the site sells products with WooCommerce, call `list_products` before you write a shop page or product copy: it returns the
real product names, prices, stock and categories (and how many products there are), and `get_product` gives one product in
full, including its variations. Use those values; never invent a product, a price or a category. These tools only read: you
cannot change a product from here.

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
## Components (Divi 5.15)
A **component** is a section, a row or a single module that the site saved once and reuses on many pages. On a page it
appears as a `divi/global-layout` block: it has `globalModule` (the component's id), `blockName` (`divi/section`, `divi/row`,
or the module, such as `divi/heading`), `localAttrs` and `localAttrOverridePaths`. A section or row instance wraps that
section's or row's own blocks; a module instance is one self-closing `divi/global-layout` inside a column. Blocks inside an
instance carry a `syncNodeId`.
- **Do not rewrite or restyle what a component owns.** Divi shows the component's own content on every page that uses it, so a
  change to a block with a `syncNodeId` (its text, colors, spacing) does not appear. The save and preview tools add
  `advisories.components.component_owned_edits` when that is what a change did. Change the component itself in the Divi
  builder (Edit Component), or leave it as is. Never turn an instance into plain blocks to "edit" it: that detaches it.
- **A Slot is the page's own place inside a component.** A `divi/global-slot` block (with its `slotId`) holds the modules this
  page puts there; modules inside a Slot carry no `syncNodeId` and are yours to change. Keep the `slotId`. Do not add a module
  in the same column next to a Slot: that edits the component itself, for every page.
- Call `get_site_structure` with `include=components` to see which components exist, their kind, their Slot ids and how many pages
  use each. A component id the site does not have (`advisories.components.unknown_components`) leaves a page that shows its own
  stored copy and is linked to nothing; use ids from that list. To create or change a component, use the Divi builder.
MD;
    }
}
