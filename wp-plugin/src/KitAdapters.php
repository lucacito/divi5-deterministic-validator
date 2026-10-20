<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/StyleKits.php';

/**
 * Adapters: turn a style kit's decisions into the attributes of one module, for KitResolver to merge where a recipe
 * carries a marker. APPLIED lists what is known: `divi/button` in two roles (`control`, `control-secondary`) on four
 * surfaces (`light`: the section's bg or surface, `accent`: an accent band, `dark`: a photo or overlay, `panel`: inside an
 * inverted, `text`-filled panel), and the cards, `divi/group` and `divi/column` in two roles (`card`, `panel`) on four
 * bands (`light` a bg band, `surface` a surface band, `accent`, `dark`), optionally on a ground (`inverted` or `accent`,
 * on a light or surface band only), and the items, `divi/accordion` (one marker styles every item) and `divi/toggle`, in two
 * roles (`item` on four bands, `item-rule` on `light` and `surface` only), and the contact form, `divi/contact-form` in one
 * role (`form`) on four bands; see the card, ground, item and form tables at the end.
 *
 * The button patch writes only `button.decoration.{background,border,font}` and `module.decoration.spacing` at
 * `desktop`, plus `module.decoration.boxShadow` when the kit has a shadow and `module.decoration.transform` when its hover
 * is `lift` (never innerContent, nothing else under `module`: a recipe's `module.decoration.layout` and the like survive
 * the merge), and every colour, border and text path it controls is written explicitly, rest and hover: an absent key
 * would fall back to the site's module preset. A kit without a shadow or lift writes no shadow or transform at all, so
 * kits made before those decisions existed resolve exactly as before (tests/golden/kit-adapter-patches.json). Every path is in the shape registry (fixtures/shapes/verified-shapes.json) and none is in
 * fixtures/shapes/dead-on-module.txt. Colours are `{{aied:color:<role>}}` tokens (the language supplies the value), the
 * radius and padding whole-value kit tokens; the only literal is TRANSPARENT.
 *
 * Module rule (controller Ruling 10, effect probe 2026-10-18, fixtures/shapes/kit-button-vocabulary.md): on
 * `divi/button` Divi 5.15.1 drops every `button.decoration` group whose `<name>Group` is render:false (ButtonModule.php
 * :664-712, :760), so the button ELEMENT's spacing, boxShadow and transform are DEAD (kb-padding-element,
 * kb-shadow-element-hard, kb-skew-x-element). The control padding is therefore written at
 * `module.decoration.spacing.desktop.value.padding` (kb-padding-module PASS: Divi prints it on the button element).
 * The adapter MAY write `module.decoration.spacing`, `module.decoration.boxShadow` (kb-shadow-module-soft, -hard, -hover)
 * and `module.decoration.transform` (kb-lift-hover, kb-lift-hover-shadow), only in the value forms the probe passed.
 *
 * Role table (final, controller Ruling 6; proven by KitAdaptersTest's contrast proof over every shipped kit x language
 * x role x surface x state, with no exception):
 * - Colour c: the kit's `color` role, except that `control-secondary` on a `light` surface always uses `text` (the
 *   neutral secondary today's recipes ship: text ink and border on bg/surface), whatever the kit's colour.
 * - Ink of a transparent button (its label and border): light -> c (`text` when c is `inverse`), accent band -> `accent-text` (the language's
 *   guaranteed pair on accent), dark (photo or overlay) -> `inverse`, panel -> `bg` (bg on text is 11.978:1 or more in
 *   every language; muted and inverse fail on a text panel in glass-dark, tech-mono and luxe).
 * - Filled treatment: light and dark -> fill c, label on(c) (on(accent)=accent-text, on(text)=bg); accent band ->
 *   fill `surface`, label `text`; panel -> fill `bg`, label `text`, for every kit colour (an accent fill on a text panel
 *   is 1.10:1 in tech-mono, so the button would lose its shape; a text fill would vanish). `inverse` is an ink, never a
 *   fill: a filled `inverse` button (a kit whose colour is inverse) is `surface` with a `text` label (`bg` with a `text`
 *   label on a panel), because no role is readable on inverse in every language (text and inverse are both light in the
 *   dark languages).
 * - solid: the filled treatment, border the kit's (`same` = the fill colour) at the kit width. On a `panel` the border of a
 *   filled button is ALWAYS the fill colour (`bg`), at rest and on hover, whatever the kit's border colour (controller
 *   Ruling 2 of the kit-limits plan): a `text` or `accent` border would melt into the `text` panel at rest and reappear
 *   on hover, so the box would shrink beside its secondary and jump on hover; the box keeps its size, as a ground frame.
 * - outline: transparent, label and border in the ink at the kit width.
 * - ghost: as outline with a zero border.
 * - The secondary role uses the outline treatment for solid and ghost kits, and the ghost one for outline kits (kit limits
 *   Decision 4: transparent, no frame, in the surface's ink, so an outlined primary keeps the weight over a plain-text
 *   secondary; every pair is one the 3 ghost kits already pass in the contrast proof, on all four surfaces).
 * Hover:
 * - `none` repeats the rest values.
 * - `fill-swap` swaps the filled and transparent treatments: a transparent button fills (light: fill = its ink, label
 *   on(ink); panel: fill `bg`, label `text`; accent band and dark: fill `surface`, label `text`, the core pair readable
 *   in every language); a filled button goes transparent in the surface's ink (light: c, as above; accent band:
 *   `accent-text`; dark: `inverse`; panel: `bg`).
 * - `invert` swaps label and fill of a filled button (a `same` border follows the new fill). A transparent button has
 *   no fill colour to swap, so `invert` fills it exactly as `fill-swap` does. On a panel `invert` IS `fill-swap` (the
 *   swapped fill would be `text`, the panel's own colour, and the button would vanish).
 * - `lift` keeps the rest colours (as `none`) and moves the module up 4px on hover.
 * Underline (`border.sides` bottom): `styles.all.width` 0px plus `styles.bottom` at the kit width in the border colour the
 *   table above gives; its hover never changes colour (StyleKits allows only `none` or `lift`), so the hover frame colour
 *   written at `styles.all` equals the bottom side's.
 * Shadows: see shadow() (soft = the language's overlay at 18% / 25%, hard = the surface's ink, opaque; on a panel no soft
 *   shadow, hard and edge in `bg`, glow in `bg` at 45%).
 * Not written on purpose: `background.hover.enableColor` (every recipe button sets its hover colour without it and
 * renders; the registry knows the path but nothing proves it is needed), `border.value.radius.sync` (the radius token
 * is the four corners, as every recipe button writes it) and gradients (the form is proven, kb-gradient-stops, but no pair of
 * colour roles gives a label readable on both stops in every design language, so no kit could pass the contrast proof).
 *
 * Card table (roles `card` and `panel` on `divi/group` and `divi/column`; spec 2026-10-19-card-adapter-design.md section 2,
 * probe fixtures/shapes/kit-card-vocabulary.md, every decision PASSED on both carriers). The patch writes only
 * `module.decoration.{background,border,spacing,boxShadow,transform}` at `desktop` (never innerContent, never a child, never
 * `layout`/`sizing`/`advanced`: a recipe's own structure survives the merge). The same patch for both carriers.
 * - Fill: always filled, never transparent (a card's children carry colours chosen for a filled ground, so an outline or
 *   ghost kit keeps a filled card and shows its character in the frame): `surface`, or `bg` on a `surface` band. No hover fill.
 * - Radius: `{{aied:kit:radius-card}}`. A radius makes Divi print `overflow: hidden` on the card, so it clips an image child.
 * - Frame: `styles.all` at the kit width, `1px` when the kit's is `0px` (a quiet kit's card still separates from its band),
 *   in the kit style; colour role: `same` -> `border` (the decorative hairline), `inverse` -> `text`, `accent` -> `text`,
 *   `text`/`border` as given (the band never changes it: the frame sits on the card's own fill). Amended by the contrast
 *   proof (KitCardAdapterTest, Task 4): a frame that carries meaning must be >= 3:1 on the fill in every language and an
 *   `accent` frame is not (playful-pastel 2.30-2.45:1, brutalist-flat 2.81:1 on its bg), so it is `text`.
 * - Underline kits (`border.sides` bottom): the button's underline form, `styles.all.width` 0px plus `styles.bottom` (width as
 *   above, style, colour), and NO hover border colour at all (the hover frame colour on a bottom-only card is not proven).
 * - `card` only: padding `{{aied:kit:space-card}}` (four sides) on `module.decoration.spacing`; hover `lift` ->
 *   `transform.desktop.hover.translate.y` -4px; hover `fill-swap` / `invert` -> `border.desktop.hover.styles.all.color`
 *   `text` (a card cannot swap its fill: its children would lose contrast; `text` and not `accent`, amended by the same
 *   proof: an accent hover frame fails in playful-pastel and brutalist-flat), written only when the rest frame is not
 *   already `text`, so only a `border` hairline frame changes on hover; hover `none` -> nothing. The shadow is shadow()'s pair (rest and hover) for the band, with a `surface`
 *   band treated as `light` (a surface band is a light band): the same value the button gets.
 * - `panel` (a static surface: forms, spec sheets, overlap panels): no padding key (the recipe keeps its structural padding)
 *   and NO `hover` key anywhere (border, boxShadow, transform): only the rest shadow, nothing for a hover-only shadow pair.
 * - A marked card or panel carries no transform of its own but this kit hover (kit limits Task 1, `RecipeLibraryTest`'s merge
 *   rule): a recipe that tilts a card puts the rest transform on an UNMARKED wrapper `divi/group` that holds the card, so the
 *   wrapper keeps its tilt while the card lifts inside it (hover measured live: wrapper rotated, card 4px up).
 * - Not used by cards: the kit's `fill`, `color` and `text` decisions (button only).
 *
 * Ground table (marker field `ground`, roles `card` and `panel`, bands `light` and `surface` only; spec
 * 2026-10-19-kit-limits-design.md section 2; proven by KitCardAdapterTest's ground proof, 6,400 cells, no exception):
 * - Fill: `text` (`inverted`) or `accent` (`accent`), whatever the band; no hover fill. The children are NOT re-inked: the
 *   recipe gives them `bg` or `surface` on an inverted ground (11.978:1 or more in every language, the measured least, organic-warm; muted and inverse fail
 *   in glass-dark, tech-mono and luxe) and `accent-text` on an accent ground (5.75:1 or more; text on accent fails in 8
 *   languages). The fill against the band: inverted 11.978:1 or more (measured least); accent 2.30:1 or more, judged decorative (>= 2.0).
 * - Frame: the plain card's sides, width and style (the 1px floor and the underline form included), in the FILL colour,
 *   so a ground card keeps the size of its kit neighbours and its shape comes from the fill.
 * - Radius, padding (`card`), shadow (the band's ink, a `surface` band treated as light) and lift: exactly the plain
 *   card's on that band. No hover frame colour ever (on fill-swap/invert kits it would be the fill); a `panel` writes
 *   no hover key, as a plain panel.
 * - A button on a ground: inside an `inverted` ground it takes the `panel` surface (see the button role table); inside an
 *   `accent` ground it takes the `accent` surface (same colours as on an accent band). `ground` is refused (a leftover marker)
 *   on an accent or photo band and on any block other than group/column.
 * - Known looks, not contrast issues: a `hard` or `edge` shadow drawn in `text` ink next to an inverted fill merges with it
 *   and reads as a slightly larger block (chunky-pill-offset, raw-frame); the frame equals the fill, so on a framed kit an
 *   accent ground merges into its body (the shape comes from the fill).
 *
 * Item table (roles `item` and `item-rule` on `divi/accordion` and `divi/toggle`; spec 2026-10-19-accordion-adapter-design.md
 * sections 1 and 2 and its Outcome, probe fixtures/shapes/kit-item-vocabulary.md: 46 candidates, every decision PASSED on both
 * carriers, none dead, so fixtures/shapes/dead-on-module.txt gains nothing). `item` is a boxed item (an FAQ); `item-rule` is a
 * rule row. Bands (ITEM_BANDS): `item` light|surface|accent|dark; `item-rule` light|surface only (its fill IS the band, and no
 * `muted` body pair is proven on accent or a photo). A `ground` is never accepted on an item. Only desktop is written; never innerContent, a child
 * (`divi/accordion-item`), `title` (the recipe keeps family, size, weight, heading level), `advanced` (`open`) or `layout`.
 * Divi's defaults are NOT transparent (closed fill #f4f4f4, open #fff, closed title #666, open #333, icon #ccc, answer #666,
 * frame 1px #d9d9d9) and an omitted key falls back to them, so every state key below is written explicitly:
 * - Fill, open and closed (`openToggle` and `closedToggle` `.decoration.background`): `item` -> `surface` (`bg` on a surface
 *   band), as a card, so the inside never depends on the band; `item-rule` -> the band itself (`bg` on light, `surface` on
 *   surface). Both states the same: no open-state emphasis in v1 (spec Decision 3).
 * - Titles `text`, open and closed: accordion `openToggle` + `closedToggle` `.decoration.font.font`; toggle `openToggle` +
 *   `closedTitle` `.decoration.font.font` (a toggle's `closedToggle` has a background only). Body `muted`
 *   (`content.decoration.bodyFont.body.font`). Icons `text`: `closedToggleIcon` on both, `openToggleIcon` on a toggle only
 *   (an accordion item has no open icon: Divi hides it; never written, not a dead path).
 * - Frame (`module.decoration.border`, on an accordion it frames EACH item): the card's, kit width (`1px` floor), kit style,
 *   colour by CARD_FRAME; an underline kit takes the underline form (`styles.all` 0px + `styles.bottom`). `item-rule` always
 *   takes the underline form: a rule row.
 * - Radius: `item` -> `{{aied:kit:radius-card}}` (Divi prints `overflow: hidden` on each item; measured not to clip at the
 *   kits' insets); `item-rule` -> 0px on the four corners, written.
 * - Padding: `item` on a toggle only -> `{{aied:kit:space-card}}` (`module.decoration.spacing`). Never on an accordion: the
 *   parent's spacing pads the ANSWER, not the item (ki-padding-accordion), so Divi's 20px item padding stays. `item-rule`
 *   writes no padding (the recipe keeps its own, structural, like a panel).
 * - Shadow: `item` -> the kit's REST shadow from shadow() for the band (a surface band treated as light), as a panel: no
 *   hover value, so a hover-only pair writes nothing; on an accordion it lands on every item. `item-rule` -> none, and
 *   nothing is written for it: no `none` value form of `module.decoration.boxShadow` was probed (a probed form would be
 *   needed to override a recipe literal), so the recipe migration drops the recipe's own shadow from every migrated rule
 *   row instead (`ItemMigrationTest` fails a marked block that keeps one).
 * - Hover: none, on either role (spec Decision 4: an accordion's hover is the parent's and would recolour every item at
 *   once; no hover path was probed on either carrier).
 * - Not used by items: the kit's `fill`, `color` and `text` decisions (button only), `rowGap` (Divi's 10px stays).
 * - Clipping: a radius prints `overflow: hidden` on each item; measured not to clip title, icons or answer at Divi's default
 *   20px insets, so it holds for any `space-card` of 20px or more (every shipped kit is 24px or more).
 * - Proofs: KitItemAdapterTest (every leaf is a probe-PASS path of its carrier), KitItemGoldenTest (tests/golden/kit-item-patches.json,
 *   132 patches: 10 default kits x 12 plus the underline-link extra), the contrast proof (item 3,200 + item-rule 1,600 cells, each
 *   judging both modules, zero exceptions), the visible-difference rule (fixtures/shapes/item-near-duplicates.txt, shrink-only,
 *   cap 12), ItemMigrationTest (own ink, band walk, merge trap) and the verify-kits item sheet with a real click.
 *
 * Form table (role `form` on `divi/contact-form`; spec 2026-10-19-forms-adapter-design.md sections 1 and 2, probe
 * fixtures/shapes/kit-form-vocabulary.md: 47 candidates measured ON the contact form, 41 PASS, 6 css-only, none dead).
 * One marker styles the whole form: its fields (`field.*` styles every child field), its submit button (`button.*`) and its
 * captcha (`captcha.*`). Bands: `light` | `surface` | `accent` | `dark` (`on` = the band the form is printed on). No ground.
 * The form module's own `module.decoration` is NEVER written: on the contact form it is the whole form container (its
 * shadow, lift and padding land on `div.et_pb_contact_form_container`, css-only), so the form's own frame, radius, maxWidth and
 * alignment stay the recipe's. Only desktop is written, never innerContent (the submit label), a field child or a family.
 * - Field fill: `surface`, `bg` on a `surface` band (a field never matches its container). Ink `text`; placeholder `muted`
 *   (the field ink does not reach the placeholder, kf-placeholder-colour). No field shadow, no field hover or focus (Ruling 3).
 * - Field frame: the kit's width with a 1px floor, the kit's style, colour `text` when the kit's border colour is `text`,
 *   `accent` or `inverse` and `muted` otherwise (Ruling 1: a field's frame is its affordance, so it uses the strong roles; the
 *   card's decorative `border` hairline is not enough). An underline kit draws the bottom edge only (`styles.all` 0px plus
 *   `styles.bottom`) when FORM_PROVEN['field-underline']; otherwise the full frame at 1px (Ruling 2).
 * - Field radius `{{aied:kit:radius-control}}`; field padding `{{aied:kit:space-control}}` when FORM_PROVEN['field-padding'].
 * - Submit button: exactly the kit's `control` treatment of `divi/button` on the band (a `surface` band is a `light` band for
 *   it: it has no `surface` band), built by button() so no second table exists, and moved to the form's own
 *   `button.decoration.*`: fill, border, label and hover as button() writes them; the padding (`spacing`), the shadow
 *   (`boxShadow`, rest and hover-only) and the lift (`transform`) are the paths that reach the submit button ON THE FORM
 *   (`button.decoration.{spacing,boxShadow,transform}`; on `divi/button` they live on `module.decoration` and the button's own are
 *   dead, here it is the other way round), each written only when FORM_PROVEN says so (button-padding, button-shadow,
 *   button-lift). A bottom-only submit edge was probed on a field but never on the submit button, so an underline kit's submit
 *   takes the same edge as a full frame (the same width, style and colour as the bottom edge button() writes).
 * - Captcha ink: the band's ink, `text` on light and surface, `accent-text` on accent, `inverse` on dark. The captcha is the one
 *   element printed straight on the container; family and size stay the recipe's.
 *
 * Pure and deterministic: no site access.
 */
final class KitAdapters
{
    /**
     * The transparent background value (already shipped in a rendered recipe, hero-image-scrim). One constant so a
     * probe-proven value can replace it in one place.
     */
    public const TRANSPARENT = 'rgba(255,255,255,0)';

    /**
     * Block => the marker roles this adapter answers on it: the one list the resolver's roles and the guide's wording follow.
     *
     * @var array<string, list<string>>
     */
    public const APPLIED = [
        'divi/button'    => [ 'control', 'control-secondary' ],
        'divi/group'     => [ 'card', 'panel' ],
        'divi/column'    => [ 'card', 'panel' ],
        'divi/accordion' => [ 'item', 'item-rule' ],
        'divi/toggle'    => [ 'item', 'item-rule' ],
        'divi/contact-form' => [ 'form' ],
    ];

    /**
     * The five conditional form decisions => whether the effect probe proved them ON `divi/contact-form` (the "Adapter
     * decisions" table of fixtures/shapes/kit-form-vocabulary.md; a test pins this constant to that table). A decision is
     * written only when true and only at the path the probe measured; the generated guide wording follows the same constant.
     *
     * @var array<string,bool>
     */
    public const FORM_PROVEN = [
        'button-padding'  => true,
        'button-shadow'   => true,
        'button-lift'     => true,
        'field-underline' => true,
        'field-padding'   => true,
    ];

    /** The bands a button may sit on (`panel`: inside an inverted, `text`-filled panel). */
    private const SURFACES = [ 'light', 'accent', 'dark', 'panel' ];

    /** The card roles, and the bands a card may sit on (a `surface` band is for cards only). */
    private const CARD_ROLES    = [ 'card', 'panel' ];
    private const CARD_SURFACES = [ 'light', 'surface', 'accent', 'dark' ];

    /** The item roles (accordion and toggle) => the bands each may sit on (a rule row only on a light or surface band). */
    private const ITEM_BANDS = [
        'item'      => [ 'light', 'surface', 'accent', 'dark' ],
        'item-rule' => [ 'light', 'surface' ],
    ];

    /** The form bands: the band the form's container is printed on. */
    private const FORM_BANDS = [ 'light', 'surface', 'accent', 'dark' ];

    /** A rule row's radius, written explicitly (Divi's own item corners are square, but a recipe literal must not survive). */
    private const SQUARE = [ 'topLeft' => '0px', 'topRight' => '0px', 'bottomRight' => '0px', 'bottomLeft' => '0px' ];

    /** A card ground => its fill colour role, and the bands a ground may sit on. */
    private const GROUNDS       = [ 'inverted' => 'text', 'accent' => 'accent' ];
    private const GROUND_BANDS  = [ 'light', 'surface' ];

    /**
     * A kit border colour => the card's frame colour role. A frame that carries meaning must be >= 3:1 on the card fill in
     * every language, and an `accent` frame is not (2.30:1 on playful-pastel's bg, 2.81:1 on brutalist-flat's); `text` is the
     * role chosen and is >= 3:1 on every card fill (`muted` is also >= 5.47:1, but a muted frame would change meaning):
     * `accent` and `inverse` frames are `text`; `same` is the decorative `border` hairline; `text` and `border` as given.
     */
    private const CARD_FRAME = [ 'same' => 'border', 'inverse' => 'text', 'accent' => 'text' ];

    /** The card's hover frame colour role (fill-swap / invert): the role chosen (>= 3:1 on every card fill), see CARD_FRAME. */
    private const CARD_HOVER_FRAME = 'text';

    /** The label colour role on a fill of the given colour role (`inverse` is never a fill, see filled()). */
    private const ON = [ 'accent' => 'accent-text', 'text' => 'bg' ];

    /** The secondary treatment of each primary fill. */
    private const SECONDARY = [ 'solid' => 'outline', 'outline' => 'ghost', 'ghost' => 'outline' ];

    /** The fill of the secondary button of a kit whose primary has $fill (`solid`, `outline` or `ghost`): the role table's one source. */
    public static function secondaryFill( string $fill ): string
    {
        return self::SECONDARY[ $fill ] ?? $fill;
    }

    /**
     * The attribute subtree to merge into $blockName for a marker of $role on surface $on (and, for a card role, the
     * optional $ground), or null when this adapter does not know the combination (any other block, role, surface or
     * ground, a ground on a button, an item or an accent or dark band, a rule row on an accent or dark band, or an invalid kit). A null $ground is the plain card.
     *
     * @param array<string,mixed> $kit
     * @return array<string,mixed>|null
     */
    public static function patch( string $blockName, string $role, string $on, array $kit, ?string $ground = null ): ?array
    {
        if ( ! in_array( $role, self::APPLIED[ $blockName ] ?? [], true ) ) {
            return null;
        }
        $card = in_array( $role, self::CARD_ROLES, true );
        $item = isset( self::ITEM_BANDS[ $role ] );
        $form = 'form' === $role;
        if ( ! in_array( $on, $form ? self::FORM_BANDS : ( $item ? self::ITEM_BANDS[ $role ] : ( $card ? self::CARD_SURFACES : self::SURFACES ) ), true ) ) {
            return null;
        }
        if ( null !== $ground && ( ! $card || ! isset( self::GROUNDS[ $ground ] ) || ! in_array( $on, self::GROUND_BANDS, true ) ) ) {
            return null;
        }
        if ( [] !== StyleKits::problems( $kit ) ) {
            return null;
        }
        if ( $form ) {
            return self::formPatch( $on, $kit );
        }
        if ( $item ) {
            return self::item( $blockName, $role, $on, $kit );
        }
        return $card ? self::card( $role, $on, $kit, $ground ) : self::button( $role, $on, $kit );
    }

    /**
     * The card patch (roles `card` and `panel`, on `divi/group` and `divi/column`): only `module.decoration` at desktop.
     * See the class docblock's card table and ground table ($ground null = the plain card).
     *
     * @param array<string,mixed> $kit a valid kit
     * @return array<string,mixed>
     */
    private static function card( string $role, string $on, array $kit, ?string $ground = null ): array
    {
        $border = (array) $kit['border'];
        $fill   = null === $ground ? ( 'surface' === $on ? 'bg' : 'surface' ) : self::GROUNDS[ $ground ];
        // On a ground the frame is the fill colour (the shape comes from the fill; the frame keeps the box's size).
        $frame  = null !== $ground ? $fill : self::cardFrameRole( $border );
        $bottom = 'bottom' === ( $border['sides'] ?? 'all' );

        $frameAttrs = [ 'desktop' => [ 'value' => [ 'radius' => '{{aied:kit:radius-card}}', 'styles' => self::frameStyles( $border, $frame, $bottom ) ] ] ];

        $hover = (string) $kit['hover'];
        $isCard = 'card' === $role;
        if ( null === $ground && $isCard && ! $bottom && self::CARD_HOVER_FRAME !== $frame && ( 'fill-swap' === $hover || 'invert' === $hover ) ) {
            // kc-hover-frame (the hover colour path, proven with an accent value; the role is the colour token only). Never
            // on a bottom-only card (its hover frame colour is not proven) and never when the frame already is that colour
            // (a redundant key): only a `border` hairline frame darkens to `text` on hover. Never on a ground (it would be the fill).
            $frameAttrs['desktop']['hover'] = [ 'styles' => [ 'all' => [ 'color' => self::colour( self::CARD_HOVER_FRAME ) ] ] ];
        }

        $module = [
            'background' => [ 'desktop' => [ 'value' => [ 'color' => self::colour( $fill ) ] ] ],
            'border'     => $frameAttrs,
        ];
        if ( $isCard ) {
            $module['spacing'] = [ 'desktop' => [ 'value' => [ 'padding' => '{{aied:kit:space-card}}' ] ] ];
        }
        // A surface band is a light band for the shadow's ink; a panel takes the rest shadow only.
        $shadow = $isCard ? self::shadow( (array) $kit['shadow'], 'surface' === $on ? 'light' : $on ) : self::restShadow( $kit, $on );
        if ( null !== $shadow ) {
            $module['boxShadow'] = $shadow;
        }
        if ( $isCard && 'lift' === $hover ) {
            // kc-hover-lift.
            $module['transform'] = [ 'desktop' => [ 'hover' => [ 'translate' => [ 'y' => '-4px' ] ] ] ];
        }

        return [ 'module' => [ 'decoration' => $module ] ];
    }

    /**
     * The form patch (role `form`, on `divi/contact-form`): only `field`, `button` and `captcha` at desktop, never `module`. See
     * the class docblock's form table. Public only so a test can prove each conditional decision's branch with a planted
     * $proven map; callers go through patch().
     *
     * @param array<string,mixed>  $kit    a valid kit
     * @param array<string,bool>   $proven decision => proven on the form (defaults to FORM_PROVEN)
     * @return array<string,mixed>
     */
    public static function formPatch( string $on, array $kit, array $proven = self::FORM_PROVEN ): array
    {
        $border = (array) $kit['border'];
        $bottom = 'bottom' === ( $border['sides'] ?? 'all' );
        $is     = static fn ( string $decision ): bool => true === ( $proven[ $decision ] ?? false );

        // Field frame (Ruling 1: strong roles; Ruling 2: the underline only where proven, else the full frame at 1px).
        $role     = in_array( (string) $border['color'], [ 'text', 'accent', 'inverse' ], true ) ? 'text' : 'muted';
        $underline = $bottom && $is( 'field-underline' );
        $frame    = self::frameStyles( $bottom && ! $underline ? array_merge( $border, [ 'width' => '1px' ] ) : $border, $role, $underline );

        $field = [
            'background'      => [ 'desktop' => [ 'value' => [ 'color' => self::colour( 'surface' === $on ? 'bg' : 'surface' ) ] ] ],
            'font'            => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => self::colour( 'text' ) ] ] ] ],
            'placeholderFont' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => self::colour( 'muted' ) ] ] ] ],
            'border'          => [ 'desktop' => [ 'value' => [ 'radius' => '{{aied:kit:radius-control}}', 'styles' => $frame ] ] ],
        ];
        if ( $is( 'field-padding' ) ) {
            $field['spacing'] = [ 'desktop' => [ 'value' => [ 'padding' => '{{aied:kit:space-control}}' ] ] ];
        }

        // The submit is the kit's primary control on the band (a surface band is a light band for a button), built by
        // button() and moved from divi/button's paths to the form's own.
        $control = self::button( 'control', 'surface' === $on ? 'light' : $on, $kit );
        $submit  = $control['button']['decoration'];
        if ( $bottom ) {
            // The bottom-only edge was never probed on the form's submit button: the same edge as a full frame.
            $submit['border']['desktop']['value']['styles'] = [ 'all' => $submit['border']['desktop']['value']['styles']['bottom'] ];
        }
        $module = $control['module']['decoration'];
        foreach ( [ 'spacing' => 'button-padding', 'boxShadow' => 'button-shadow', 'transform' => 'button-lift' ] as $group => $decision ) {
            if ( $is( $decision ) && isset( $module[ $group ] ) ) {
                $submit[ $group ] = $module[ $group ];
            }
        }

        return [
            'field'   => [ 'decoration' => $field ],
            'button'  => [ 'decoration' => $submit ],
            'captcha' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => self::colour( self::ink( 'text', $on ) ) ] ] ] ] ] ],
        ];
    }

    /**
     * The item patch (roles `item` and `item-rule`, on `divi/accordion` and `divi/toggle`): see the class docblock's item
     * table. Every colour and every frame side the item shows is written, both states, so nothing falls back to Divi's
     * non-transparent defaults (the merge trap).
     *
     * @param array<string,mixed> $kit a valid kit
     * @return array<string,mixed>
     */
    private static function item( string $blockName, string $role, string $on, array $kit ): array
    {
        $toggle = 'divi/toggle' === $blockName;
        $rule   = 'item-rule' === $role;
        $border = (array) $kit['border'];
        // A rule row's fill IS the band; a boxed item is a card: `surface`, or `bg` on a surface band.
        $fill   = $rule ? ( 'surface' === $on ? 'surface' : 'bg' ) : ( 'surface' === $on ? 'bg' : 'surface' );
        // The card's frame (ki-frame-*, ki-edge-bottom); a rule row always takes the underline form.
        $styles = self::frameStyles( $border, self::cardFrameRole( $border ), $rule || 'bottom' === ( $border['sides'] ?? 'all' ) );

        $module = [ 'border' => [ 'desktop' => [ 'value' => [ 'radius' => $rule ? self::SQUARE : '{{aied:kit:radius-card}}', 'styles' => $styles ] ] ] ];
        if ( ! $rule && $toggle ) {
            // ki-padding-toggle. Never on an accordion: its parent padding pads the answer, not the item (ki-padding-accordion).
            $module['spacing'] = [ 'desktop' => [ 'value' => [ 'padding' => '{{aied:kit:space-card}}' ] ] ];
        }
        $shadow = $rule ? null : self::restShadow( $kit, $on );
        if ( null !== $shadow ) {
            // The rest shadow only, as a panel's (no hover on items).
            $module['boxShadow'] = $shadow;
        }

        $background = [ 'desktop' => [ 'value' => [ 'color' => self::colour( $fill ) ] ] ];
        $title      = [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => self::colour( 'text' ) ] ] ] ];
        $icon       = [ 'decoration' => [ 'icon' => [ 'desktop' => [ 'value' => [ 'color' => self::colour( 'text' ) ] ] ] ] ];

        $patch = [
            'module'     => [ 'decoration' => $module ],
            'openToggle' => [ 'decoration' => [ 'background' => $background, 'font' => $title ] ],
        ];
        if ( $toggle ) {
            // A toggle's closed title is its own group (ki-title-closed-toggle); its closedToggle has a background only.
            $patch['closedToggle']     = [ 'decoration' => [ 'background' => $background ] ];
            $patch['closedTitle']      = [ 'decoration' => [ 'font' => $title ] ];
            $patch['closedToggleIcon'] = $icon;
            $patch['openToggleIcon']   = $icon;
        } else {
            // An accordion has no open icon (Divi hides it on an open item): closedToggleIcon only.
            $patch['closedToggle']     = [ 'decoration' => [ 'background' => $background, 'font' => $title ] ];
            $patch['closedToggleIcon'] = $icon;
        }
        $patch['content'] = [ 'decoration' => [ 'bodyFont' => [ 'body' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => self::colour( 'muted' ) ] ] ] ] ] ] ];

        return $patch;
    }

    /** The frame colour role of a card (and an item) for a kit border: CARD_FRAME, else the kit's own role. @param array<string,mixed> $border */
    private static function cardFrameRole( array $border ): string
    {
        return self::CARD_FRAME[ (string) $border['color'] ] ?? (string) $border['color'];
    }

    /**
     * The `border.desktop.value.styles` of a card or an item: the kit width (`1px` when the kit's is `0px`: a quiet kit's card
     * still separates from its band) and style in the colour role $frameRole on all sides, or with $underline the button's
     * underline form (kc-edge-bottom, ki-edge-bottom): `all` 0px plus the `bottom` side.
     *
     * @param array<string,mixed> $border the kit's border
     * @return array<string,array<string,string>>
     */
    private static function frameStyles( array $border, string $frameRole, bool $underline ): array
    {
        $width  = '0px' === (string) $border['width'] ? '1px' : (string) $border['width'];
        $style  = (string) $border['style'];
        $colour = self::colour( $frameRole );

        return $underline
            ? [
                'all'    => [ 'width' => '0px', 'style' => $style, 'color' => $colour ],
                'bottom' => [ 'width' => $width, 'style' => $style, 'color' => $colour ],
            ]
            : [ 'all' => [ 'width' => $width, 'style' => $style, 'color' => $colour ] ];
    }

    /**
     * The REST shadow of a kit on band $on for a static surface (a panel, an item): shadow()'s pair for the band, with a
     * `surface` band treated as `light`, minus any hover value; null when nothing is left (none/none, or a hover-only pair).
     *
     * @param array<string,mixed> $kit
     * @return array<string,mixed>|null
     */
    private static function restShadow( array $kit, string $on ): ?array
    {
        $shadow = self::shadow( (array) $kit['shadow'], 'surface' === $on ? 'light' : $on );
        if ( null === $shadow ) {
            return null;
        }
        unset( $shadow['desktop']['hover'] );

        return [] === $shadow['desktop'] ? null : $shadow;
    }

    /**
     * @param array<string,mixed> $kit a valid kit
     * @return array<string,mixed>
     */
    private static function button( string $role, string $on, array $kit ): array
    {
        $fill   = (string) $kit['fill'];
        $fill   = 'control' === $role ? $fill : self::SECONDARY[ $fill ];
        $c      = 'control-secondary' === $role && 'light' === $on ? 'text' : (string) $kit['color'];
        $rest   = self::treatment( $fill, $c, $on, $kit );
        $hover  = self::hover( (string) $kit['hover'], $rest, (string) $kit['border']['color'], $c, $on );
        $text   = (array) $kit['text'];

        $style  = (string) $kit['border']['style'];
        $styles = [ 'all' => [ 'width' => $rest['width'], 'style' => $style, 'color' => self::colour( $rest['border'] ) ] ];
        if ( 'bottom' === ( $kit['border']['sides'] ?? 'all' ) ) {
            // An underline (kb-border-bottom-only): no frame, only the bottom side.
            $styles = [
                'all'    => [ 'width' => '0px', 'style' => $style, 'color' => self::colour( $rest['border'] ) ],
                'bottom' => [ 'width' => $rest['width'], 'style' => $style, 'color' => self::colour( $rest['border'] ) ],
            ];
        }

        $module = [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => '{{aied:kit:space-control}}' ] ] ] ];
        $shadow = self::shadow( (array) $kit['shadow'], $on );
        if ( null !== $shadow ) {
            $module['boxShadow'] = $shadow;
        }
        if ( 'lift' === $kit['hover'] ) {
            // kb-lift-hover (with a hover shadow kb-lift-hover-shadow).
            $module['transform'] = [ 'desktop' => [ 'hover' => [ 'translate' => [ 'y' => '-4px' ] ] ] ];
        }

        return [
            'button' => [
                'decoration' => [
                    'background' => [
                        'desktop' => [
                            'value' => [ 'color' => self::colour( $rest['fill'] ) ],
                            'hover' => [ 'color' => self::colour( $hover['fill'] ) ],
                        ],
                    ],
                    'border'     => [
                        'desktop' => [
                            'value' => [
                                'radius' => '{{aied:kit:radius-control}}',
                                'styles' => $styles,
                            ],
                            'hover' => [ 'styles' => [ 'all' => [ 'color' => self::colour( $hover['border'] ) ] ] ],
                        ],
                    ],
                    'font'       => [
                        'font' => [
                            'desktop' => [
                                'value' => [
                                    'color'         => self::colour( $rest['label'] ),
                                    'capitalization' => (string) $text['capitalization'],
                                    'letterSpacing' => (string) $text['letterSpacing'],
                                    'weight'        => (string) $text['weight'],
                                ],
                                'hover' => [ 'color' => self::colour( $hover['label'] ) ],
                            ],
                        ],
                    ],
                ],
            ],
            'module' => [ 'decoration' => $module ],
        ];
    }

    /**
     * The module box shadow of a kit's shadow pair on surface $on, in the exact forms the effect probe passed, or null for
     * none/none. `soft` (kb-shadow-module-soft): preset1, 8px down, 24px blur, the language's `overlay` at 18%; on hover only
     * (kb-shadow-module-hover): preset1, 12px down, 24px blur, `overlay` at 25%. `overlay` is the language's scrim colour, a
     * deep tint of its own hue in every language, so a soft shadow reads as a shadow (never a glow) on every surface.
     * `hard` (kb-shadow-module-hard): preset4, 6px right and down, no blur, opaque, in the surface's ink (`text` on a light
     * surface, `accent-text` on an accent band, `inverse` on a photo): the colour that contrasts with what is under it.
     * `edge` (kb-shadow-edge-down): preset4, 0px across, 4px down, no blur, opaque, in the same ink as `hard`: a hard edge
     * straight below. `glow` (kb-shadow-glow): preset1, 0px down, 16px blur, the language's `accent` at 45% (`accent-text`
     * at 45% on an accent band, where the accent would vanish): a halo around the button.
     * On a `panel` (a button inside a `text`-filled panel): `hard` and `edge` in `bg` (the panel's ink, 11.978:1 or more on
     * text in every language), `glow` in `bg` at 45% (2.91 to 4.27:1 against the panel; the accent at 45% is 1.08:1 in
     * tech-mono) and NO soft shadow, rest or hover: the soft shadow is a darker tint and nothing is darker than a text
     * panel in every language (the overlay at 25% is 1.00 to 1.05:1 on the light languages' text), so it is not written.
     * A rest shadow is written once (value) and the hover keeps it. A shadow never changes a label or border colour, so the
     * contrast bounds are unaffected.
     *
     * @param array<string,mixed> $shadow
     * @return array<string,mixed>|null
     */
    private static function shadow( array $shadow, string $on ): ?array
    {
        $rest  = (string) ( $shadow['rest'] ?? 'none' );
        $hover = (string) ( $shadow['hover'] ?? 'none' );
        if ( 'panel' === $on && ( 'soft' === $rest || 'none' === $rest ) ) {
            return null;
        }
        if ( 'none' === $rest ) {
            return 'soft' === $hover
                ? [ 'desktop' => [ 'hover' => [ 'style' => 'preset1', 'vertical' => '12px', 'blur' => '24px', 'color' => '{{aied:color:overlay:25}}' ] ] ]
                : null;
        }
        if ( 'soft' === $rest ) {
            return [ 'desktop' => [ 'value' => [ 'style' => 'preset1', 'vertical' => '8px', 'blur' => '24px', 'color' => '{{aied:color:overlay:18}}' ] ] ];
        }
        if ( 'glow' === $rest ) {
            return [ 'desktop' => [ 'value' => [ 'style' => 'preset1', 'vertical' => '0px', 'blur' => '16px', 'color' => '{{aied:color:' . ( [ 'accent' => 'accent-text', 'panel' => 'bg' ][ $on ] ?? 'accent' ) . ':45}}' ] ] ];
        }
        if ( 'edge' === $rest ) {
            return [ 'desktop' => [ 'value' => [ 'style' => 'preset4', 'horizontal' => '0px', 'vertical' => '4px', 'blur' => '0px', 'color' => self::colour( self::ink( 'text', $on ) ) ] ] ];
        }
        return [ 'desktop' => [ 'value' => [ 'style' => 'preset4', 'horizontal' => '6px', 'vertical' => '6px', 'blur' => '0px', 'color' => self::colour( self::ink( 'text', $on ) ) ] ] ];
    }

    /**
     * The rest state of a treatment as colour roles (null = transparent) and the border width.
     *
     * @param array<string,mixed> $kit
     * @return array{fill:?string,label:string,border:string,width:string}
     */
    private static function treatment( string $fill, string $c, string $on, array $kit ): array
    {
        $width       = (string) $kit['border']['width'];
        $borderColor = (string) $kit['border']['color'];

        if ( 'solid' === $fill ) {
            [ $bg, $label ] = self::filled( $c, $on );
            // On a panel the frame of a filled button is its fill (Ruling 2): the box keeps its size.
            return [ 'fill' => $bg, 'label' => $label, 'border' => 'same' === $borderColor || 'panel' === $on ? $bg : $borderColor, 'width' => $width ];
        }

        $ink = self::ink( $c, $on );
        return [ 'fill' => null, 'label' => $ink, 'border' => $ink, 'width' => 'ghost' === $fill ? '0px' : $width ];
    }

    /** The ink (label and border) of a transparent button of colour $c on surface $on (`inverse` is for dark surfaces: on light it is `text`). */
    private static function ink( string $c, string $on ): string
    {
        if ( 'panel' === $on ) {
            return 'bg';
        }
        if ( 'accent' === $on ) {
            return 'accent-text';
        }
        if ( 'dark' === $on ) {
            return 'inverse';
        }
        return 'inverse' === $c ? 'text' : $c;
    }

    /**
     * [fill, label] of a filled button of colour $c on surface $on.
     *
     * @return array{0:string,1:string}
     */
    private static function filled( string $c, string $on ): array
    {
        if ( 'panel' === $on ) {
            return [ 'bg', 'text' ];
        }
        if ( 'accent' === $on || ! isset( self::ON[ $c ] ) ) {
            return [ 'surface', 'text' ];
        }
        return [ $c, self::ON[ $c ] ];
    }

    /**
     * The hover state of a rest state.
     *
     * @param array{fill:?string,label:string,border:string,width:string} $rest
     * @return array{fill:?string,label:string,border:string}
     */
    private static function hover( string $hover, array $rest, string $kitBorderColor, string $c, string $on ): array
    {
        if ( 'none' === $hover || 'lift' === $hover ) {
            // lift moves the button (module transform) and keeps its colours.
            return [ 'fill' => $rest['fill'], 'label' => $rest['label'], 'border' => $rest['border'] ];
        }
        if ( null === $rest['fill'] ) {
            // Transparent at rest: fill-swap and invert both fill it.
            [ $bg, $label ] = 'light' === $on || 'panel' === $on ? self::filled( $rest['label'], $on ) : [ 'surface', 'text' ];
            return [ 'fill' => $bg, 'label' => $label, 'border' => $bg ];
        }
        if ( 'fill-swap' === $hover || 'panel' === $on ) {
            // Filled at rest: transparent in the surface's ink (on a panel `invert` too: its swapped fill would be the
            // panel's own `text`).
            $ink = self::ink( $c, $on );
            return [ 'fill' => null, 'label' => $ink, 'border' => $ink ];
        }
        // invert: label and fill swap; a `same` border follows the fill.
        return [ 'fill' => $rest['label'], 'label' => $rest['fill'], 'border' => 'same' === $kitBorderColor ? $rest['label'] : $rest['border'] ];
    }

    private static function colour( ?string $role ): string
    {
        return null === $role ? self::TRANSPARENT : '{{aied:color:' . $role . '}}';
    }
}
