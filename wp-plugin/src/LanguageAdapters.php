<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/DesignLanguages.php';

/**
 * Turns a design language's decisions into the attribute patch of one recipe block (the language step, run by
 * LanguageResolver before the kit step). Keyed by LANGUAGE, not by style kit: a kit never decides these.
 *
 * Kind `type` (the headline type): the language's `type.<role>` weight and letter spacing, and `capitalization`
 * `uppercase` only when the role says `uppercase` (Divi 5.15.1 has no `none` value, so a `none` role writes no key and the
 * block shows Divi's default), at the block's desktop font path. Roles: `display` (h1, h2), `title` (h3) and `eyebrow` (an
 * h4 in the eyebrow font) on `divi/heading`, and `eyebrow` (an uppercase label) on `divi/text`. Nothing else is written:
 * no size, line height, colour, family, alignment, style or heading level.
 *
 * Kind `rhythm` (the vertical air between sections): the language's section padding for the role, top and bottom (equal)
 * plus `syncVertical` `on`, at desktop, tablet and phone of the section's spacing path (DesignLanguages::SECTION_SPACE_PATH).
 * Roles on `divi/section`: `section` (the language's `section_space`), `open` and `tight` (derived from it by
 * DesignLanguages::sectionSpace(), Ruling 8). Nothing else is written: no left or right padding, margin, state, background
 * or divider.
 *
 * Every path and value was rendered on the real Divi 5.15.1 ON its carrier (fixtures/shapes/language-type-vocabulary.md,
 * fixtures/shapes/language-rhythm-vocabulary.md); a decision the probe did not pass makes the carrier answer null (the
 * marker stays and the recipe is refused), except the type cascade, whose fallback writes the same value at desktop, tablet
 * and phone, and the section's `syncVertical`, which is then omitted. Pure and deterministic: no site access.
 */
final class LanguageAdapters
{
    /** The marker kinds a design language answers (`"aied":{"<kind>":"<role>"}`). */
    public const KINDS = [ 'type', 'rhythm' ];

    /** Kind => block => the roles it takes. StyleGuide::typographyLanguageSentence() and LanguageResolver read it. */
    public const APPLIED = [
        'type' => [
            'divi/heading' => [ 'display', 'title', 'eyebrow' ],
            'divi/text'    => [ 'eyebrow' ],
        ],
        'rhythm' => [
            'divi/section' => [ 'section', 'open', 'tight' ],
        ],
    ];

    /** The type roles, in the order of a language's `type` object. */
    public const TYPE_ROLES = [ 'display', 'title', 'eyebrow' ];

    /** Type role => the heading levels a recipe marks with it (Ruling 2; the guide sentence names them). */
    public const TYPE_HEADING_LEVELS = [
        'display' => [ 'h1', 'h2' ],
        'title'   => [ 'h3' ],
        'eyebrow' => [ 'h4' ],
    ];

    /** Type role => the font role (`fonts.<role>`) it is set in; the font-weight proof uses it. */
    public const TYPE_FONT = [ 'display' => 'display', 'title' => 'display', 'eyebrow' => 'eyebrow' ];

    /** Block => the font group the type patch writes below (`<path>.desktop.value`). */
    public const TYPE_PATHS = [
        'divi/heading' => 'title.decoration.font.font',
        'divi/text'    => 'content.decoration.bodyFont.body.font',
    ];

    /**
     * The probe's verdicts (fixtures/shapes/language-type-vocabulary.md, "Adapter decisions"; a test pins this to that
     * table): weight, letter spacing and uppercase on each carrier, every heading level, a heading in a card, and the
     * cascade of a desktop value to tablet and phone.
     */
    public const TYPE_PROVEN = [
        'heading-weight'   => true,
        'heading-tracking' => true,
        'heading-caps'     => true,
        'heading-levels'   => true,
        'heading-in-card'  => true,
        'heading-cascade'  => true,
        'text-weight'      => true,
        'text-tracking'    => true,
        'text-caps'        => true,
        'text-cascade'     => true,
    ];

    /** Block => the spacing group the rhythm patch writes below (`<path>.<breakpoint>.value.padding`): the language's own constant. */
    public const RHYTHM_PATHS = [ 'divi/section' => DesignLanguages::SECTION_SPACE_PATH ];

    /**
     * The probe's verdicts (fixtures/shapes/language-rhythm-vocabulary.md, "Adapter decisions"; a test pins this to that
     * table): top and bottom padding at the three breakpoints, the `syncVertical` toggle beside them, and the same padding on
     * a section with a background image, on the first and on a later section of a page, and on a section with a negative top
     * margin.
     */
    public const RHYTHM_PROVEN = [
        'section-padding' => true,
        'section-sync'    => true,
        'section-image'   => true,
        'section-first'   => true,
        'section-later'   => true,
        'section-overlap' => true,
    ];

    /** The rhythm decisions a patch cannot be written without (section-sync only adds the toggle). */
    private const RHYTHM_NEEDS = [ 'section-padding', 'section-image', 'section-first', 'section-later', 'section-overlap' ];

    /** Block => the carrier name the decisions use. */
    private const CARRIERS = [ 'divi/heading' => 'heading', 'divi/text' => 'text' ];

    /**
     * The attribute patch for $role of $kind on $block under $language, or null for an unknown kind, block or role, or a
     * language whose data has a problem.
     *
     * @param array<string,mixed> $language
     * @return ?array<string,mixed>
     */
    public static function patch( string $block, string $kind, string $role, array $language ): ?array
    {
        if ( 'type' === $kind ) {
            return self::typePatch( $block, $role, $language );
        }
        if ( 'rhythm' === $kind ) {
            return self::rhythmPatch( $block, $role, $language );
        }

        return null;
    }

    /**
     * The type patch: `[<path> => [desktop => [value => [weight, letterSpacing(, capitalization)]]]]` nested at
     * TYPE_PATHS[$block], plus the same value at tablet and phone when the carrier's cascade is not proven. Null unless
     * $role belongs on $block, the language's `type` is valid and every decision the patch relies on is proven in
     * $proven (public only so a test can plant $proven).
     *
     * @param array<string,mixed> $language
     * @param array<string,bool>  $proven
     * @return ?array<string,mixed>
     */
    public static function typePatch( string $block, string $role, array $language, array $proven = self::TYPE_PROVEN ): ?array
    {
        if ( ! in_array( $role, self::APPLIED['type'][ $block ] ?? [], true ) || [] !== DesignLanguages::typeProblems( $language ) ) {
            return null;
        }
        $carrier = self::CARRIERS[ $block ];
        $data    = $language['type'][ $role ];
        $needs   = [ $carrier . '-weight', $carrier . '-tracking' ];
        if ( 'divi/heading' === $block ) {
            $needs[] = 'heading-levels';
        }
        $value = [ 'weight' => $data['weight'], 'letterSpacing' => $data['letterSpacing'] ];
        if ( 'uppercase' === $data['capitalization'] ) {
            $needs[]                 = $carrier . '-caps';
            $value['capitalization'] = 'uppercase';
        }
        foreach ( $needs as $decision ) {
            if ( true !== ( $proven[ $decision ] ?? false ) ) {
                return null;
            }
        }
        $breakpoints = true === ( $proven[ $carrier . '-cascade' ] ?? false ) ? [ 'desktop' ] : [ 'desktop', 'tablet', 'phone' ];
        $node        = [];
        foreach ( $breakpoints as $bp ) {
            $node[ $bp ] = [ 'value' => $value ];
        }
        foreach ( array_reverse( explode( '.', self::TYPE_PATHS[ $block ] ) ) as $key ) {
            $node = [ $key => $node ];
        }

        return $node;
    }

    /**
     * The rhythm patch: `[module => [decoration => [spacing => [desktop|tablet|phone => [value => [padding => [top, bottom,
     * syncVertical]]]]]]]` nested at RHYTHM_PATHS[$block], top = bottom = DesignLanguages::sectionSpace($language, $role) in
     * px, `syncVertical` `on` only when `section-sync` is proven. Null unless $role belongs on $block, the language's
     * `section_space` is valid and every decision in RHYTHM_NEEDS is proven in $proven (public only so a test can plant
     * $proven).
     *
     * @param array<string,mixed> $language
     * @param array<string,bool>  $proven
     * @return ?array<string,mixed>
     */
    public static function rhythmPatch( string $block, string $role, array $language, array $proven = self::RHYTHM_PROVEN ): ?array
    {
        if ( ! in_array( $role, self::APPLIED['rhythm'][ $block ] ?? [], true ) ) {
            return null;
        }
        $space = DesignLanguages::sectionSpace( $language, $role );
        if ( null === $space ) {
            return null;
        }
        foreach ( self::RHYTHM_NEEDS as $decision ) {
            if ( true !== ( $proven[ $decision ] ?? false ) ) {
                return null;
            }
        }
        $sync = true === ( $proven['section-sync'] ?? false );
        $node = [];
        foreach ( DesignLanguages::SECTION_SPACE_KEYS as $bp ) {
            $padding = [ 'top' => $space[ $bp ] . 'px', 'bottom' => $space[ $bp ] . 'px' ];
            if ( $sync ) {
                $padding['syncVertical'] = 'on';
            }
            $node[ $bp ] = [ 'value' => [ 'padding' => $padding ] ];
        }
        foreach ( array_reverse( explode( '.', self::RHYTHM_PATHS[ $block ] ) ) as $key ) {
            $node = [ $key => $node ];
        }

        return $node;
    }
}
