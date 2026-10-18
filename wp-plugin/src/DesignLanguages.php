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
        $raw  = is_readable( self::DATA ) ? (string) file_get_contents( self::DATA ) : '';
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            return self::$cache = [];
        }
        $out = [];
        foreach ( $data as $lang ) {
            if ( is_array( $lang ) && isset( $lang['id'] ) && is_string( $lang['id'] ) ) {
                $out[] = $lang;
            }
        }
        return self::$cache = $out;
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
     * accent, accent_text, border (hex). It MAY also have `surface_glass`, an
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
