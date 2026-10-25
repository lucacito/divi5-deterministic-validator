<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/StyleKits.php';

/**
 * Design languages: complete, original art-direction systems (palette roles, type
 * pairing, radius, spacing, shadow, motion, section rhythm, favoured and avoided recipe
 * layouts, hero and call-to-action character) the AI picks BEFORE choosing recipes, so
 * a whole page or site looks designed instead of assembled.
 *
 * Pure data + readers. The data file ships with the plugin; nothing is fetched and
 * nothing here writes to a site. Every palette must pass WCAG AA (see contrast()).
 */
final class DesignLanguages
{
    private const DATA = __DIR__ . '/../data/design-languages.json';

    /**
     * Font families that need no Google font: allowed in a language's font value besides the Google
     * font names (a value may be a comma separated stack, and each family in it must be one or the other).
     */
    public const WEB_SAFE_FONTS = [
        'Georgia', 'Arial', 'Helvetica', 'Times New Roman', 'Verdana', 'Tahoma', 'Trebuchet MS', 'Courier New',
        'system-ui', 'sans-serif', 'serif', 'monospace',
    ];

    private const COLOR_KEYS = [ 'bg', 'surface', 'text', 'muted', 'accent', 'accent_text', 'border', 'inverse' ];

    /** The scrim over photos: an rgba() colour whose alpha is neither so low it fails to darken nor so high it hides the photo. */
    private const OVERLAY_MIN_ALPHA = 0.35;
    private const OVERLAY_MAX_ALPHA = 0.8;

    /** The longest font family name valid() accepts. */
    private const MAX_FAMILY_LENGTH = 100;
    private const FONT_KEYS  = [ 'display', 'body', 'eyebrow' ];
    private const TEXT_KEYS  = [ 'title', 'summary', 'radius', 'section_padding', 'shadow', 'rhythm', 'hero', 'cta' ];

    /** The longest generated line (see generatedLines()) a language topic may gain: one line, so the topic stays inside its bound. */
    public const GENERATED_LINE_MAX = 260;

    /** The type roles a language decides (type adapter): recipe h1/h2, h3, and eyebrow labels. */
    public const TYPE_KEYS = [ 'display', 'title', 'eyebrow' ];

    /** The three decisions per role, in the order the data and the generated line write them. */
    public const TYPE_ROLE_KEYS = [ 'weight', 'letterSpacing', 'capitalization' ];

    /** @var list<string> the font weights a language may ask for (each must also exist in the role's font: typeProblems()) */
    public const TYPE_WEIGHTS = [ '100', '200', '300', '400', '500', '600', '700', '800', '900' ];

    /** @var array<string,array{0:int,1:int}> letter spacing bounds in whole pixels, per role */
    public const TYPE_TRACKING_BOUNDS = [ 'display' => [ -3, 0 ], 'title' => [ -1, 1 ], 'eyebrow' => [ 0, 4 ] ];

    /** The breakpoints a language's `section_space` has, in the order the data and the generated line write them. */
    public const SECTION_SPACE_KEYS = [ 'desktop', 'tablet', 'phone' ];

    /** @var array<string,array{0:int,1:int}> whole-pixel bounds of `section_space` (and of every derived role): desktop and phone; tablet stays between them */
    public const SECTION_SPACE_BOUNDS = [ 'desktop' => [ 56, 200 ], 'phone' => [ 28, 120 ] ];

    /** The attribute path (under the section's attrs) that carries the top and bottom padding per breakpoint; the one source LanguageAdapters points to. */
    public const SECTION_SPACE_PATH = 'module.decoration.spacing';

    /** The section rhythm roles: `section` is the language's own section_space, `open` and `tight` are derived (sectionSpace()). */
    public const RHYTHM_ROLES = [ 'section', 'open', 'tight' ];

    /** @var array<string,array{0:int,1:int}> role => [numerator, denominator] of the factor applied to section_space (no float anywhere) */
    public const RHYTHM_FACTORS = [ 'section' => [ 1, 1 ], 'open' => [ 5, 4 ], 'tight' => [ 3, 5 ] ];

    /** Where each generated line is printed in a language topic: after the named line (see markdown()). */
    private const GENERATED_PLACE = [ 'type' => 'fonts', 'section_space' => 'section_padding', 'kit' => 'style_kit' ];

    /** @var ?list<array<string,mixed>> the decoded languages, read once per request */
    private static ?array $cache = null;

    /** Forgets the memoised languages (for tests that change the data in-process). */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /** @return list<array<string,mixed>> */
    private static function all(): array
    {
        if ( null !== self::$cache ) {
            return self::$cache;
        }
        $raw = is_readable( self::DATA ) ? file_get_contents( self::DATA ) : false;
        return self::$cache = self::parse( false === $raw ? '' : $raw );
    }

    /**
     * The languages in a data file's text: every entry that is an array with a string `id`, in order.
     * Corrupt or empty text means no languages (never an error).
     *
     * @internal Reads the data file's text; the supported readers are all(), ids(), get() and markdown().
     *
     * @return list<array<string,mixed>>
     */
    public static function parse( string $raw ): array
    {
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            return [];
        }
        $out = [];
        foreach ( $data as $lang ) {
            if ( is_array( $lang ) && isset( $lang['id'] ) && is_string( $lang['id'] ) ) {
                $out[] = $lang;
            }
        }
        return $out;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map( static fn( array $l ): string => $l['id'], self::all() );
    }

    /**
     * One language, or null for an unknown id.
     *
     * Shape: {id, title, summary, colors, fonts, type, radius, section_padding, section_space, shadow, motion, kit,
     * rhythm, favors, avoids, hero, cta}. `section_space` is {desktop, tablet, phone}, the whole-pixel top and bottom
     * padding of an ordinary section, the structured form of the `section_padding` sentence (see sectionSpaceProblems()
     * and sectionSpace()). `type` has the roles display (recipe h1, h2), title (h3) and eyebrow, each
     * {weight, letterSpacing, capitalization} (see typeProblems()). `kit` is the id of the style kit (StyleKits) the language's
     * recipes are served in unless a caller names another. `colors` always has bg, surface, text, muted,
     * accent, accent_text, border, inverse (hex) and `overlay`, an `rgba(r,g,b,a)` scrim
     * (0.35 <= a <= 0.8). `inverse` is the text colour for dark bands and photos; `overlay`
     * is the scrim laid over a photo so `inverse` text stays readable. It MAY also have `surface_glass`, an
     * `rgba(r,g,b,a)` string (0 < a < 1): the translucent card colour of a glass language,
     * in which case `surface` is only its opaque equivalent (fallback for plain panels).
     * A consumer that wants "the card colour" uses `surface_glass` when set, else `surface`.
     *
     * @return array<string,mixed>|null
     */
    public static function get( string $id ): ?array
    {
        foreach ( self::all() as $lang ) {
            if ( $lang['id'] === $id ) {
                return $lang;
            }
        }
        return null;
    }

    /**
     * Markdown for the AI: the index of languages when $id is null, otherwise the full
     * brief for that one language (or a message listing the valid ids).
     */
    public static function markdown( ?string $id = null ): string
    {
        if ( null === $id ) {
            $lines = [
                '# Design languages',
                '',
                'Pick ONE design language before choosing section recipes and use it for the whole page or site. A language fixes the colours, fonts, corner radius, spacing, shadows, motion and which recipe layouts to favour.',
                '',
            ];
            foreach ( self::all() as $lang ) {
                $lines[] = sprintf( '- **%s** (`%s`): %s', (string) $lang['title'], (string) $lang['id'], (string) $lang['summary'] );
            }
            $lines[] = '';
            $lines[] = 'Ask for one language in full with its id.';
            return implode( "\n", $lines ) . "\n";
        }

        $lang = self::get( $id );
        if ( null === $lang ) {
            return sprintf( "Unknown design language \"%s\". Valid ids: %s.\n", SectionRecipes::plain( $id ), implode( ', ', self::ids() ) );
        }

        $c = (array) $lang['colors'];
        $f = (array) $lang['fonts'];
        $glass = isset( $c['surface_glass'] ) && is_string( $c['surface_glass'] ) && '' !== $c['surface_glass'];
        $cardLines = $glass
            ? [
                sprintf( '- background `%s`, border `%s`', (string) $c['bg'], (string) $c['border'] ),
                sprintf( '- card colour (use this for cards, translucent, set as the background of the card): `%s`', (string) $c['surface_glass'] ),
                sprintf( '- surface `%s` is the opaque equivalent, a fallback for plain panels only (do not use it for cards)', (string) $c['surface'] ),
            ]
            : [
                sprintf( '- background `%s`, surface (cards) `%s`, border `%s`', (string) $c['bg'], (string) $c['surface'], (string) $c['border'] ),
            ];
        $lines = [
            sprintf( '# Design language: %s (`%s`)', (string) $lang['title'], (string) $lang['id'] ),
            '',
            (string) $lang['summary'],
            '',
            '## Colours (use these exact values)',
            ...$cardLines,
            sprintf( '- text `%s`, muted text `%s`', (string) $c['text'], (string) $c['muted'] ),
            sprintf( '- accent `%s`, text on accent `%s`', (string) $c['accent'], (string) $c['accent_text'] ),
            sprintf( '- inverse `%s`, overlay `%s`', (string) $c['inverse'], (string) $c['overlay'] ),
            '- inverse: text on photos and dark bands; overlay: the scrim over photos',
            '',
            '## Fonts',
            sprintf( '- headings: %s; body: %s; eyebrow labels: %s', (string) $f['display'], (string) $f['body'], (string) $f['eyebrow'] ),
            ...self::generatedAt( $lang, 'fonts' ),
            '',
            '## Shape, space and depth',
            '- Radius: ' . (string) $lang['radius'],
            '- Section padding: ' . (string) $lang['section_padding'],
            ...self::generatedAt( $lang, 'section_padding' ),
            '- Shadow and borders: ' . (string) $lang['shadow'],
            '- Motion: ' . (string) $lang['motion'],
            '- Style kit: ' . (string) ( $lang['kit'] ?? '' ),
            ...self::generatedAt( $lang, 'style_kit' ),
            '',
            '## Section rhythm',
            (string) $lang['rhythm'],
            '',
            '## Recipe layouts',
            '- Favour: ' . implode( ', ', (array) $lang['favors'] ),
            '- Avoid: ' . implode( ', ', (array) $lang['avoids'] ),
            '',
            '## Hero',
            (string) $lang['hero'],
            '',
            '## Call to action',
            (string) $lang['cta'],
        ];
        return implode( "\n", $lines ) . "\n";
    }

    /**
     * What is wrong with a design language (empty list = valid). Used by the tests on every shipped
     * language, and available to anything that accepts one. Checks: every field present and the right
     * kind; `kit` names a shipped style kit (StyleKits::get()); colours are #rrggbb (`surface_glass`, optional, may instead be rgba(r,g,b,a) with channels
     * 0 to 255 and 0 < a < 1; `overlay` is required and must be rgba(r,g,b,a) with 0.35 <= a <= 0.8); font values are comma separated stacks of plain family names (letters,
     * digits and spaces, at most 100 characters each) and, when $knownFonts is given, each family is in that list or in
     * WEB_SAFE_FONTS; the id is kebab-case; `favors` and `avoids` are non-empty, only
     * SectionRecipes::LAYOUTS values and do not overlap; no text contains `<`, `>`, a backtick or
     * `{{`. Uniqueness of ids is a property of the whole set, so it is checked over ids(), not here.
     * The class never reads a font list itself: the caller passes the names (null skips the check).
     * `type` is required: typeProblems() lines are part of the result (already prefixed `type.`); with $fontWeights
     * (the `families` map of fixtures/shapes/font-weights.json) each weight must also exist in its role's font.
     * `section_space` is required too: sectionSpaceProblems() lines are part of the result (prefixed `section_space`).
     *
     * @param array<string,mixed>                $language
     * @param ?list<string>                      $knownFonts
     * @param ?array<string,array<string,mixed>> $fontWeights
     * @return list<string>
     */
    public static function valid( array $language, ?array $knownFonts = null, ?array $fontWeights = null ): array
    {
        $problems = [];

        $id = $language['id'] ?? null;
        if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/', $id ) ) {
            $problems[] = 'id must be kebab-case (lower-case letters, digits, single hyphens)';
        }

        foreach ( self::TEXT_KEYS as $key ) {
            $value = $language[ $key ] ?? null;
            if ( ! is_string( $value ) || '' === trim( $value ) ) {
                $problems[] = $key . ' must be a non-empty string';
            }
        }
        if ( ! in_array( $language['motion'] ?? null, [ 'none', 'subtle', 'lively' ], true ) ) {
            $problems[] = 'motion must be none, subtle or lively';
        }
        $kit = $language['kit'] ?? null;
        if ( ! is_string( $kit ) || null === StyleKits::get( $kit ) ) {
            $problems[] = 'kit must be the id of a shipped style kit (' . implode( ', ', StyleKits::ids() ) . ')';
        }

        $colors = $language['colors'] ?? null;
        if ( ! is_array( $colors ) ) {
            $problems[] = 'colors must be an object';
            $colors     = [];
        }
        foreach ( self::COLOR_KEYS as $key ) {
            $value = $colors[ $key ] ?? null;
            if ( ! is_string( $value ) || 1 !== preg_match( '/^#[0-9a-f]{6}\z/i', $value ) ) {
                $problems[] = 'colors.' . $key . ' must be a #rrggbb colour';
            }
        }
        if ( ! self::isOverlayColor( $colors['overlay'] ?? null ) ) {
            $problems[] = 'colors.overlay must be rgba(r,g,b,a) with channels 0-255 and 0.35 <= a <= 0.8';
        }
        if ( array_key_exists( 'surface_glass', $colors ) && ! self::isGlassColor( $colors['surface_glass'] ) ) {
            $problems[] = 'colors.surface_glass must be #rrggbb or rgba(r,g,b,a) with channels 0-255 and 0 < a < 1';
        }

        $fonts = $language['fonts'] ?? null;
        if ( ! is_array( $fonts ) ) {
            $problems[] = 'fonts must be an object';
            $fonts      = [];
        }
        foreach ( self::FONT_KEYS as $key ) {
            $value = $fonts[ $key ] ?? null;
            if ( ! is_string( $value ) ) {
                $problems[] = 'fonts.' . $key . ' must be a font name or a comma separated stack';
                continue;
            }
            foreach ( explode( ',', $value ) as $family ) {
                $family = trim( $family );
                if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9 -]*\z/', $family ) ) {
                    $problems[] = 'fonts.' . $key . ' has a family name with characters a font name does not have';
                } elseif ( strlen( $family ) > self::MAX_FAMILY_LENGTH ) {
                    $problems[] = 'fonts.' . $key . ' has a family name longer than ' . self::MAX_FAMILY_LENGTH . ' characters';
                } elseif ( null !== $knownFonts && ! in_array( $family, $knownFonts, true ) && ! in_array( $family, self::WEB_SAFE_FONTS, true ) ) {
                    $problems[] = 'fonts.' . $key . ' names "' . $family . '", which is not a known Google font or a web-safe family';
                }
            }
        }

        $problems = array_merge( $problems, self::typeProblems( $language, $fontWeights ), self::sectionSpaceProblems( $language ) );

        foreach ( [ 'favors', 'avoids' ] as $key ) {
            $list = $language[ $key ] ?? null;
            if ( ! is_array( $list ) || [] === $list ) {
                $problems[] = $key . ' must be a non-empty list of recipe layouts';
                continue;
            }
            foreach ( $list as $layout ) {
                if ( ! is_string( $layout ) || ! in_array( $layout, SectionRecipes::LAYOUTS, true ) ) {
                    $problems[] = $key . ' has a value that is not a recipe layout';
                    break;
                }
            }
        }
        if ( is_array( $language['favors'] ?? null ) && is_array( $language['avoids'] ?? null )
            && [] !== array_intersect( $language['favors'], $language['avoids'] ) ) {
            $problems[] = 'favors and avoids overlap';
        }

        foreach ( self::unsafeTextPaths( $language, '' ) as $path ) {
            $problems[] = $path . ' must not contain <, >, a backtick or {{';
        }

        return $problems;
    }

    /**
     * What is wrong with a language's `type` (empty list = fine; every line starts with the dotted path, `type...`).
     * `type` is an object with EXACTLY display, title and eyebrow; each is an object with EXACTLY weight (a string
     * of TYPE_WEIGHTS), letterSpacing (whole pixels, `0px` or `-1px`, never `-0px`, inside TYPE_TRACKING_BOUNDS for
     * the role) and capitalization (`none` or `uppercase`). With $fontWeights (the `families` map of
     * fixtures/shapes/font-weights.json: Divi's own font file) the weight must also exist in the role's font:
     * display and title use fonts.display, eyebrow uses fonts.eyebrow, each the FIRST family of its stack. A variable
     * family (an `axes.wght` range) takes any weight inside the range; a static one needs the weight among its
     * variants (`regular` is 400, an italic-only variant does not count). A family missing from the map is a problem.
     *
     * @param array<string,mixed>                $language
     * @param ?array<string,array<string,mixed>> $fontWeights
     * @return list<string>
     */
    public static function typeProblems( array $language, ?array $fontWeights = null ): array
    {
        $type = $language['type'] ?? null;
        if ( ! is_array( $type ) ) {
            return [ 'type must be an object with the roles ' . implode( ', ', self::TYPE_KEYS ) ];
        }
        $problems = [];
        foreach ( array_keys( $type ) as $role ) {
            if ( ! in_array( $role, self::TYPE_KEYS, true ) ) {
                $problems[] = 'type.' . $role . ' is not a type role (' . implode( ', ', self::TYPE_KEYS ) . ')';
            }
        }
        $fonts = is_array( $language['fonts'] ?? null ) ? $language['fonts'] : [];
        foreach ( self::TYPE_KEYS as $role ) {
            $spec = $type[ $role ] ?? null;
            if ( ! is_array( $spec ) ) {
                $problems[] = 'type.' . $role . ' must be an object with ' . implode( ', ', self::TYPE_ROLE_KEYS );
                continue;
            }
            foreach ( array_keys( $spec ) as $key ) {
                if ( ! in_array( $key, self::TYPE_ROLE_KEYS, true ) ) {
                    $problems[] = 'type.' . $role . '.' . $key . ' is not a type decision (' . implode( ', ', self::TYPE_ROLE_KEYS ) . ')';
                }
            }
            $weight = $spec['weight'] ?? null;
            if ( ! is_string( $weight ) || ! in_array( $weight, self::TYPE_WEIGHTS, true ) ) {
                $problems[] = 'type.' . $role . '.weight must be one of ' . implode( ', ', self::TYPE_WEIGHTS ) . ' as a string';
            } elseif ( null !== $fontWeights ) {
                $stack  = is_string( $fonts['eyebrow' === $role ? 'eyebrow' : 'display'] ?? null ) ? $fonts['eyebrow' === $role ? 'eyebrow' : 'display'] : '';
                $family = trim( explode( ',', $stack )[0] );
                $reason = self::weightMissing( $fontWeights[ $family ] ?? null, (int) $weight );
                if ( null !== $reason ) {
                    $problems[] = 'type.' . $role . '.weight ' . $weight . ' is not available in "' . $family . '": ' . $reason;
                }
            }
            $tracking = $spec['letterSpacing'] ?? null;
            if ( ! is_string( $tracking ) || 1 !== preg_match( '/^(0|-?[1-9][0-9]?)px\z/', $tracking ) ) {
                $problems[] = 'type.' . $role . '.letterSpacing must be whole pixels such as 0px or -1px';
            } else {
                [ $lo, $hi ] = self::TYPE_TRACKING_BOUNDS[ $role ];
                if ( (int) $tracking < $lo || (int) $tracking > $hi ) {
                    $problems[] = 'type.' . $role . '.letterSpacing must be between ' . $lo . 'px and ' . $hi . 'px';
                }
            }
            $caps = $spec['capitalization'] ?? null;
            if ( ! is_string( $caps ) || ! in_array( $caps, [ 'none', 'uppercase' ], true ) ) {
                $problems[] = 'type.' . $role . '.capitalization must be none or uppercase';
            } elseif ( 'eyebrow' === $role && 'uppercase' === $caps && is_string( $tracking ) && (int) $tracking < 0 ) {
                $problems[] = 'type.eyebrow.letterSpacing must not be negative on capital letters';
            }
        }
        return $problems;
    }

    /**
     * Why a font cannot render a weight, or null when it can.
     *
     * @param mixed $entry the family's entry in font-weights.json (`variants`, optional `axes.wght`)
     */
    private static function weightMissing( $entry, int $weight ): ?string
    {
        if ( ! is_array( $entry ) ) {
            return 'the family is not in the font facts';
        }
        $range = $entry['axes']['wght'] ?? null;
        if ( is_array( $range ) && 2 === count( $range ) ) {
            return $weight >= (int) $range[0] && $weight <= (int) $range[1] ? null : 'its weight range is ' . (int) $range[0] . ' to ' . (int) $range[1];
        }
        $variants = array_map( 'strval', (array) ( $entry['variants'] ?? [] ) );
        $name     = 400 === $weight ? 'regular' : (string) $weight;
        return in_array( $name, $variants, true ) ? null : 'its weights are ' . implode( ', ', $variants );
    }

    /**
     * The one line of a design language topic that states the language's headline type, generated from the data so it
     * can never disagree with it. Capital letters are named only for a role that asks for them. '' when the language
     * has no readable `type`.
     *
     * @param array<string,mixed> $language
     */
    public static function typeLine( array $language ): string
    {
        $type = $language['type'] ?? null;
        if ( ! is_array( $type ) ) {
            return '';
        }
        $names = [ 'display' => 'headlines (h1, h2)', 'title' => 'titles (h3)', 'eyebrow' => 'eyebrow labels' ];
        // A role that writes no capitals is said to be sentence case once the language has capitals somewhere, so a language
        // that asks for capital-letter headlines is not read as capitals for the titles too (a blind page-building run did exactly that).
        $anyCaps = false;
        foreach ( array_keys( $names ) as $role ) {
            $anyCaps = $anyCaps || 'uppercase' === ( is_array( $type[ $role ] ?? null ) ? ( $type[ $role ]['capitalization'] ?? '' ) : '' );
        }
        $parts = [];
        foreach ( $names as $role => $name ) {
            $spec = $type[ $role ] ?? null;
            if ( ! is_array( $spec ) ) {
                return '';
            }
            $parts[] = sprintf(
                '%s weight %s, letter spacing %s%s',
                $name,
                self::plainValue( $spec['weight'] ?? '' ),
                self::plainValue( $spec['letterSpacing'] ?? '' ),
                'uppercase' === ( $spec['capitalization'] ?? '' ) ? ', capital letters' : ( $anyCaps ? ', sentence case' : '' )
            );
        }
        return '- Type: ' . implode( '; ', $parts ) . ' (where to write them: topic=typography).';
    }

    /**
     * What is wrong with a language's `section_space` (empty list = fine; every line starts with `section_space`).
     * It is an object with EXACTLY desktop, tablet and phone; each is an int (a string, a float or a bool is not),
     * desktop within SECTION_SPACE_BOUNDS['desktop'], phone within SECTION_SPACE_BOUNDS['phone'], and desktop >= tablet >= phone.
     *
     * @param array<string,mixed> $language
     * @return list<string>
     */
    public static function sectionSpaceProblems( array $language ): array
    {
        $space = $language['section_space'] ?? null;
        if ( ! is_array( $space ) ) {
            return [ 'section_space must be an object with the keys ' . implode( ', ', self::SECTION_SPACE_KEYS ) ];
        }
        $problems = [];
        foreach ( array_keys( $space ) as $key ) {
            if ( ! in_array( $key, self::SECTION_SPACE_KEYS, true ) ) {
                $problems[] = 'section_space.' . $key . ' is not a breakpoint (' . implode( ', ', self::SECTION_SPACE_KEYS ) . ')';
            }
        }
        $whole = true;
        foreach ( self::SECTION_SPACE_KEYS as $key ) {
            $value = $space[ $key ] ?? null;
            if ( ! is_int( $value ) ) {
                $problems[] = 'section_space.' . $key . ' must be a whole number of pixels (an integer)';
                $whole      = false;
                continue;
            }
            if ( isset( self::SECTION_SPACE_BOUNDS[ $key ] ) ) {
                [ $lo, $hi ] = self::SECTION_SPACE_BOUNDS[ $key ];
                if ( $value < $lo || $value > $hi ) {
                    $problems[] = 'section_space.' . $key . ' must be between ' . $lo . ' and ' . $hi;
                }
            }
        }
        if ( $whole ) {
            if ( $space['tablet'] > $space['desktop'] ) {
                $problems[] = 'section_space.tablet must not be above desktop';
            }
            if ( $space['phone'] > $space['tablet'] ) {
                $problems[] = 'section_space.phone must not be above tablet';
            }
        }
        return $problems;
    }

    /**
     * The top and bottom padding of one rhythm role, in whole pixels per breakpoint, or null for a role outside
     * RHYTHM_ROLES or a language whose `section_space` has a problem. `section` is the data itself, never rounded.
     * `open` and `tight` are derived with integers only: for each breakpoint
     * `intdiv( v * num + 4 * den, 8 * den ) * 8` with the factor of RHYTHM_FACTORS (the nearest multiple of 8, a half
     * rounds up), then desktop is clamped into SECTION_SPACE_BOUNDS['desktop'], phone into ['phone'] and tablet into
     * [phone, desktop] (a clamp gives the bound itself, so 28 and 56 are legal results).
     *
     * @param array<string,mixed> $language
     * @return ?array{desktop:int,tablet:int,phone:int}
     */
    public static function sectionSpace( array $language, string $role ): ?array
    {
        if ( ! isset( self::RHYTHM_FACTORS[ $role ] ) || [] !== self::sectionSpaceProblems( $language ) ) {
            return null;
        }
        /** @var array{desktop:int,tablet:int,phone:int} $space */
        $space = $language['section_space'];
        if ( 'section' === $role ) {
            return [ 'desktop' => $space['desktop'], 'tablet' => $space['tablet'], 'phone' => $space['phone'] ];
        }
        [ $num, $den ] = self::RHYTHM_FACTORS[ $role ];
        $round         = static fn( int $v ): int => intdiv( $v * $num + 4 * $den, 8 * $den ) * 8;
        $desktop       = max( self::SECTION_SPACE_BOUNDS['desktop'][0], min( self::SECTION_SPACE_BOUNDS['desktop'][1], $round( $space['desktop'] ) ) );
        $phone         = max( self::SECTION_SPACE_BOUNDS['phone'][0], min( self::SECTION_SPACE_BOUNDS['phone'][1], $round( $space['phone'] ) ) );
        $tablet        = max( $phone, min( $desktop, $round( $space['tablet'] ) ) );
        return [ 'desktop' => $desktop, 'tablet' => $tablet, 'phone' => $phone ];
    }

    /**
     * The one line of a design language topic that gives a hand-written page the section padding of each rhythm role,
     * generated from the data (and from SECTION_SPACE_PATH) so it can never disagree with either. '' when the language
     * has a `section_space` problem.
     *
     * @param array<string,mixed> $language
     */
    public static function sectionSpaceLine( array $language ): string
    {
        $parts = [];
        foreach ( [ 'section' => 'sections', 'open' => 'open (a roomier band)', 'tight' => 'tight (a compact strip)' ] as $role => $name ) {
            $space = self::sectionSpace( $language, $role );
            if ( null === $space ) {
                return '';
            }
            $parts[] = sprintf( '%s %d/%d/%dpx', $name, $space['desktop'], $space['tablet'], $space['phone'] );
        }
        return sprintf(
            '- Section spacing: padding top and bottom at %s.{%s}.value.padding (never left or right); %s.',
            self::SECTION_SPACE_PATH,
            implode( ',', self::SECTION_SPACE_KEYS ),
            implode( ', ', $parts )
        );
    }

    /**
     * Every generated line a language topic carries, by name (markdown() prints each one where GENERATED_PLACE says).
     *
     * @param array<string,mixed> $language
     * @return array<string,string>
     */
    public static function generatedLines( array $language ): array
    {
        return [ 'type' => self::typeLine( $language ), 'section_space' => self::sectionSpaceLine( $language ), 'kit' => self::kitLine( $language ) ];
    }

    /**
     * "- Kit: raw-frame sets corners, borders, shadow and hover of buttons, cards, ... and chips; where this prose
     * differs, the kit wins (a section's opening line is its strong rule: 4px). Patterns: topic=kit-patterns."
     * (wording gaps, spec Design 2 / Ruling 4: "the kit wins" is read early, in the language topic itself). The nouns are
     * StyleGuide::appliedNouns() (the kit-driven modules of KitAdapters::APPLIED), the width is
     * KitAdapters::ruleWidth('rule-strong', ...) of the language's own kit; '' for a language without a known kit. At most
     * GENERATED_LINE_MAX bytes.
     *
     * @param array<string,mixed> $language
     */
    public static function kitLine( array $language ): string
    {
        require_once __DIR__ . '/StyleGuide.php';
        $id  = is_string( $language['kit'] ?? null ) ? $language['kit'] : '';
        $kit = '' === $id ? null : StyleKits::get( $id );
        if ( null === $kit ) {
            return '';
        }

        return sprintf(
            "- Kit: %s sets corners, borders, shadow and hover of %s; where this prose differs, the kit wins (a section's opening line is its strong rule: %s). Patterns: topic=kit-patterns.",
            $id,
            StyleGuide::joinAnd( StyleGuide::appliedNouns() ),
            KitAdapters::ruleWidth( 'rule-strong', (array) $kit['border'] )
        );
    }

    /**
     * The generated lines printed right after the named line of the topic (GENERATED_PLACE), in generatedLines() order;
     * an empty line (a language without the data) prints nothing.
     *
     * @param array<string,mixed> $language
     * @return list<string>
     */
    private static function generatedAt( array $language, string $after ): array
    {
        $out = [];
        foreach ( self::generatedLines( $language ) as $name => $line ) {
            if ( '' !== $line && ( self::GENERATED_PLACE[ $name ] ?? null ) === $after ) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /** @param mixed $value */
    private static function plainValue( $value ): string
    {
        return is_string( $value ) ? $value : '';
    }

    /**
     * Dotted paths of every string under $node that contains `<`, `>`, a backtick or `{{`.
     *
     * @param mixed $node
     * @return list<string>
     */
    private static function unsafeTextPaths( $node, string $path ): array
    {
        if ( is_string( $node ) ) {
            return ( false !== strpbrk( $node, '<>`' ) || str_contains( $node, '{{' ) ) ? [ $path ] : [];
        }
        if ( ! is_array( $node ) ) {
            return [];
        }
        $out = [];
        foreach ( $node as $key => $child ) {
            $out = array_merge( $out, self::unsafeTextPaths( $child, '' === $path ? (string) $key : $path . '.' . $key ) );
        }
        return $out;
    }

    /** @param mixed $value */
    private static function isGlassColor( $value ): bool
    {
        if ( ! is_string( $value ) ) {
            return false;
        }
        if ( 1 === preg_match( '/^#[0-9a-f]{6}\z/i', $value ) ) {
            return true;
        }
        $alpha = self::rgbaAlpha( $value );
        return null !== $alpha && $alpha > 0.0 && $alpha < 1.0;
    }

    /** @param mixed $value */
    private static function isOverlayColor( $value ): bool
    {
        if ( ! is_string( $value ) ) {
            return false;
        }
        $alpha = self::rgbaAlpha( $value );
        return null !== $alpha && $alpha >= self::OVERLAY_MIN_ALPHA && $alpha <= self::OVERLAY_MAX_ALPHA;
    }

    /** The alpha of an rgba(r,g,b,a) string whose channels are 0 to 255 and whose alpha is written 0.x or .x; null for anything else. */
    private static function rgbaAlpha( string $value ): ?float
    {
        if ( 1 !== preg_match( '/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(0?\.\d+)\s*\)\z/', $value, $m ) ) {
            return null;
        }
        if ( (int) $m[1] > 255 || (int) $m[2] > 255 || (int) $m[3] > 255 ) {
            return null;
        }
        return (float) $m[4];
    }

    /**
     * WCAG 2.x contrast ratio (1.0 to 21.0) between two hex colours (#rgb or #rrggbb).
     * An unreadable colour counts as black.
     */
    public static function contrast( string $fg, string $bg ): float
    {
        $a = self::luminance( $fg );
        $b = self::luminance( $bg );
        $hi = max( $a, $b );
        $lo = min( $a, $b );
        return ( $hi + 0.05 ) / ( $lo + 0.05 );
    }

    private static function luminance( string $hex ): float
    {
        $hex = ltrim( trim( $hex ), '#' );
        if ( 3 === strlen( $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( 1 !== preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
            return 0.0;
        }
        $lin = static function ( string $pair ): float {
            $v = hexdec( $pair ) / 255;
            return $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
        };
        return 0.2126 * $lin( substr( $hex, 0, 2 ) ) + 0.7152 * $lin( substr( $hex, 2, 2 ) ) + 0.0722 * $lin( substr( $hex, 4, 2 ) );
    }
}
