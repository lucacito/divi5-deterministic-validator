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
 * Every path and value was rendered on the real Divi 5.15.1 ON its carrier (fixtures/shapes/language-type-vocabulary.md);
 * a decision the probe did not pass makes the carrier answer null (the marker stays and the recipe is refused), except
 * the cascade, whose fallback writes the same value at desktop, tablet and phone. Pure and deterministic: no site access.
 */
final class LanguageAdapters
{
    /** The marker kinds a design language answers (`"aied":{"<kind>":"<role>"}`). */
    public const KINDS = [ 'type' ];

    /** Kind => block => the roles it takes. StyleGuide::typographyLanguageSentence() and LanguageResolver read it. */
    public const APPLIED = [
        'type' => [
            'divi/heading' => [ 'display', 'title', 'eyebrow' ],
            'divi/text'    => [ 'eyebrow' ],
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
}
