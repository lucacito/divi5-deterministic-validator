<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

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
     * Shape: {id, title, summary, colors, fonts, radius, section_padding, shadow, motion,
     * rhythm, favors, avoids, hero, cta}. `colors` always has bg, surface, text, muted,
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
            '',
            '## Shape, space and depth',
            '- Radius: ' . (string) $lang['radius'],
            '- Section padding: ' . (string) $lang['section_padding'],
            '- Shadow and borders: ' . (string) $lang['shadow'],
            '- Motion: ' . (string) $lang['motion'],
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
     * kind; colours are #rrggbb (`surface_glass`, optional, may instead be rgba(r,g,b,a) with channels
     * 0 to 255 and 0 < a < 1; `overlay` is required and must be rgba(r,g,b,a) with 0.35 <= a <= 0.8); font values are comma separated stacks of plain family names (letters,
     * digits and spaces, at most 100 characters each) and, when $knownFonts is given, each family is in that list or in
     * WEB_SAFE_FONTS; the id is kebab-case; `favors` and `avoids` are non-empty, only
     * SectionRecipes::LAYOUTS values and do not overlap; no text contains `<`, `>`, a backtick or
     * `{{`. Uniqueness of ids is a property of the whole set, so it is checked over ids(), not here.
     * The class never reads a font list itself: the caller passes the names (null skips the check).
     *
     * @param array<string,mixed> $language
     * @param ?list<string>       $knownFonts
     * @return list<string>
     */
    public static function valid( array $language, ?array $knownFonts = null ): array
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
