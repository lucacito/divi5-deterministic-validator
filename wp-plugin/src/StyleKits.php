<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/SectionRecipes.php';
require_once __DIR__ . '/KitAdapters.php';

/**
 * Style kits: small decision tables (fill, border, radius, shadow, hover, text, space) that say
 * how a family of controls and cards looks. A kit is DECISIONS ONLY: no markup, no colour value
 * (colours are roles), nothing that could be pasted into a page. Adapters elsewhere turn a kit
 * into real module attributes; this class only validates, loads and describes kits.
 *
 * Pure data + readers. The data file ships with the plugin; nothing is fetched and nothing
 * here writes to a site.
 */
final class StyleKits
{
    private const DATA = __DIR__ . '/../data/style-kits.json';

    /**
     * Border styles proven on the real Divi (extended only with probe-proven values): solid, dashed, dotted and double
     * each changed the computed border style of a divi/button (docs/shape-evidence-5.15.1.md#kb-border-style-*).
     */
    public const BORDER_STYLES = [ 'solid', 'dashed', 'dotted', 'double' ];

    /**
     * Hover behaviours a kit may choose (extended only with probe-proven values). `lift` keeps the rest colours and moves
     * the button up 4px (module transform, kb-lift-hover; with a hover shadow kb-lift-hover-shadow).
     */
    public const HOVERS = [ 'fill-swap', 'invert', 'none', 'lift' ];

    /**
     * Shadow values a kit may choose for rest and hover (extended only with probe-proven values): `soft` (box shadow preset1,
     * kb-shadow-module-soft / kb-shadow-module-hover) and `hard` (preset4, an offset solid shadow, kb-shadow-module-hard), both
     * on the button MODULE (the button element's own shadow is dead, kb-shadow-element-hard), `edge` (preset4 with 0px
     * across and 4px down: a hard edge straight below, kb-shadow-edge-down) and `glow` (preset1 with 0px down and a 16px
     * blur: a halo, kb-shadow-glow). The shadow is printed on the button element itself, so it follows its corners
     * (kb-shadow-soft-on-pill) and sits behind a transparent button too (kb-shadow-hard-on-outline). Proven pairs only:
     * the hover equals the rest (written once, the hover keeps it), or a soft hover over no rest shadow (SHADOW_PAIRS).
     */
    public const SHADOWS = [ 'none', 'soft', 'hard', 'edge', 'glow' ];

    /** The shadow pairs a kit may choose, "rest/hover" (see SHADOWS). */
    public const SHADOW_PAIRS = [ 'none/none', 'soft/soft', 'hard/hard', 'none/soft', 'edge/edge', 'glow/glow' ];

    /**
     * Which border sides are drawn (optional `border.sides`, default `all`): `bottom` is an underline (`styles.all.width` 0px
     * plus `styles.bottom`, kb-border-bottom-only). A per-side hover colour is not proven, so an underline kit's hover is
     * `none` or `lift` (its colours never change), and it needs a width of at least 1px and a fill that is not `ghost`.
     */
    public const BORDER_SIDES = [ 'all', 'bottom' ];

    public const FILLS         = [ 'solid', 'outline', 'ghost' ];
    public const COLOR_ROLES   = [ 'accent', 'text', 'inverse' ];
    public const BORDER_COLORS = [ 'accent', 'text', 'border', 'inverse', 'same' ];
    public const CAPITALIZE    = [ 'none', 'uppercase' ];

    /** The value tokens valueToken() answers. */
    public const VALUE_TOKENS = [ 'radius-control', 'radius-card', 'radius-media', 'space-control', 'space-card' ];

    private const MAX_STRING = 160;
    private const MAX_SPACE  = 120;

    private const TOP_KEYS = [ 'id', 'title', 'summary', 'avoids', 'fill', 'color', 'border', 'radius', 'shadow', 'hover', 'text', 'space' ];

    /** @var ?list<array<string,mixed>> the decoded kits, read once per request */
    private static ?array $cache = null;

    /** Forgets the memoised kits (for tests that change the data in-process). */
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
     * The kits in a data file's text: every entry that is an array with a string `id`, in order.
     * Corrupt or empty text means no kits (never an error).
     *
     * @internal Reads the data file's text; the supported readers are ids(), get() and markdown().
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
        foreach ( $data as $kit ) {
            if ( is_array( $kit ) && isset( $kit['id'] ) && is_string( $kit['id'] ) ) {
                $out[] = $kit;
            }
        }
        return $out;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map( static fn( array $k ): string => $k['id'], self::all() );
    }

    /** @return array<string,mixed>|null */
    public static function get( string $id ): ?array
    {
        foreach ( self::all() as $kit ) {
            if ( $kit['id'] === $id ) {
                return $kit;
            }
        }
        return null;
    }

    /**
     * What is wrong with a kit (empty list = valid). Strict: every key required except `avoids`,
     * no unknown keys, every value from its closed vocabulary or pattern, and no string value that
     * looks like markup, a token or a colour (a hex colour, `<`, `>`, `&`, `--`, a quote, a backtick, a
     * brace), carries a control character (a newline included: it could forge a heading in markdown())
     * or is longer than 160 characters.
     *
     * @param array<string,mixed> $kit
     * @return list<string>
     */
    public static function problems( array $kit ): array
    {
        $p = [];

        foreach ( array_keys( $kit ) as $key ) {
            if ( ! in_array( $key, self::TOP_KEYS, true ) ) {
                $p[] = 'unknown key ' . self::label( $key );
            }
        }

        $id = $kit['id'] ?? null;
        if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/', $id ) ) {
            $p[] = 'id must be kebab-case (lower-case letters, digits, single hyphens)';
        }
        foreach ( [ 'title', 'summary' ] as $key ) {
            $v = $kit[ $key ] ?? null;
            if ( ! is_string( $v ) || '' === trim( $v ) ) {
                $p[] = $key . ' must be a non-empty string';
            }
        }

        if ( array_key_exists( 'avoids', $kit ) ) {
            $avoids = $kit['avoids'];
            if ( ! is_array( $avoids ) || ! array_is_list( $avoids ) ) {
                $p[] = 'avoids must be a list of recipe layouts';
            } else {
                foreach ( $avoids as $layout ) {
                    if ( ! is_string( $layout ) || ! in_array( $layout, SectionRecipes::LAYOUTS, true ) ) {
                        $p[] = 'avoids has a value that is not a recipe layout';
                        break;
                    }
                }
            }
        }

        self::oneOf( $p, 'fill', $kit['fill'] ?? null, self::FILLS );
        self::oneOf( $p, 'color', $kit['color'] ?? null, self::COLOR_ROLES );
        self::oneOf( $p, 'hover', $kit['hover'] ?? null, self::HOVERS );

        $border = self::group( $p, 'border', $kit['border'] ?? null, [ 'width', 'style', 'color', 'sides' ] );
        if ( null !== $border ) {
            if ( ! is_string( $border['width'] ?? null ) || 1 !== preg_match( '/^[0-4]px\z/', $border['width'] ) ) {
                $p[] = 'border.width must be 0px to 4px';
            }
            self::oneOf( $p, 'border.style', $border['style'] ?? null, self::BORDER_STYLES );
            self::oneOf( $p, 'border.color', $border['color'] ?? null, self::BORDER_COLORS );
            if ( array_key_exists( 'sides', $border ) ) {
                self::oneOf( $p, 'border.sides', $border['sides'], self::BORDER_SIDES );
                if ( 'bottom' === $border['sides'] ) {
                    if ( ! in_array( $kit['hover'] ?? null, [ 'none', 'lift' ], true ) ) {
                        $p[] = 'border.sides bottom needs hover none or lift (a per-side hover colour is not proven)';
                    }
                    if ( '0px' === ( $border['width'] ?? null ) ) {
                        $p[] = 'border.sides bottom needs a border width of 1px or more';
                    }
                    if ( 'ghost' === ( $kit['fill'] ?? null ) ) {
                        $p[] = 'border.sides bottom cannot be used with a ghost fill (a ghost has no border)';
                    }
                }
            }
        }

        $radius = self::group( $p, 'radius', $kit['radius'] ?? null, [ 'control', 'card', 'media' ] );
        if ( null !== $radius ) {
            foreach ( [ 'control', 'card', 'media' ] as $key ) {
                if ( ! self::isRadius( $radius[ $key ] ?? null ) ) {
                    $p[] = 'radius.' . $key . ' must be 0px to 48px or 999px';
                }
            }
        }

        $shadow = self::group( $p, 'shadow', $kit['shadow'] ?? null, [ 'rest', 'hover' ] );
        if ( null !== $shadow ) {
            $rest  = $shadow['rest'] ?? null;
            $hover = $shadow['hover'] ?? null;
            self::oneOf( $p, 'shadow.rest', $rest, self::SHADOWS );
            self::oneOf( $p, 'shadow.hover', $hover, self::SHADOWS );
            if ( is_string( $rest ) && is_string( $hover ) && in_array( $rest, self::SHADOWS, true ) && in_array( $hover, self::SHADOWS, true )
                && ! in_array( $rest . '/' . $hover, self::SHADOW_PAIRS, true ) ) {
                $p[] = 'shadow rest/hover must be one of: ' . implode( ', ', self::SHADOW_PAIRS );
            }
        }

        $text = self::group( $p, 'text', $kit['text'] ?? null, [ 'capitalization', 'letterSpacing', 'weight' ] );
        if ( null !== $text ) {
            self::oneOf( $p, 'text.capitalization', $text['capitalization'] ?? null, self::CAPITALIZE );
            if ( ! is_string( $text['letterSpacing'] ?? null ) || 1 !== preg_match( '/^[0-4]px\z/', $text['letterSpacing'] ) ) {
                $p[] = 'text.letterSpacing must be 0px to 4px';
            }
            self::oneOf( $p, 'text.weight', $text['weight'] ?? null, [ '400', '500', '600', '700', '800' ] );
        }

        $space = self::group( $p, 'space', $kit['space'] ?? null, [ 'control', 'card' ] );
        if ( null !== $space ) {
            $control = self::group( $p, 'space.control', $space['control'] ?? null, [ 'vertical', 'horizontal' ] );
            if ( null !== $control ) {
                foreach ( [ 'vertical', 'horizontal' ] as $key ) {
                    if ( ! self::isSpace( $control[ $key ] ?? null ) ) {
                        $p[] = 'space.control.' . $key . ' must be 0px to ' . self::MAX_SPACE . 'px';
                    }
                }
            }
            if ( ! self::isSpace( $space['card'] ?? null ) ) {
                $p[] = 'space.card must be 0px to ' . self::MAX_SPACE . 'px';
            }
        }

        foreach ( self::unsafeStringPaths( $kit, '' ) as $path ) {
            $p[] = $path . ' must be plain text of at most ' . self::MAX_STRING . ' characters (no markup, colour, quote, &, --, brace, backtick or control character)';
        }

        return $p;
    }

    /**
     * A value a recipe can drop in for a `radius-*` or `space-*` token, in the shape Divi stores it, or
     * null for any other name. Radius: the four corners. `space-control`: four sides (top and bottom are
     * the vertical value, left and right the horizontal one), exactly the form the effect probe proved on a button's
     * `module.decoration.spacing` (kb-padding-module; the builder's sync flags are only toggles and are not written).
     * `space-card`: four sides.
     *
     * @param array<string,mixed> $kit
     * @return array<string,string>|null
     */
    public static function valueToken( array $kit, string $name ): ?array
    {
        switch ( $name ) {
            case 'radius-control':
            case 'radius-card':
            case 'radius-media':
                $radius = (array) ( $kit['radius'] ?? [] );
                $v      = (string) ( $radius[ substr( $name, 7 ) ] ?? '' );
                return [ 'topLeft' => $v, 'topRight' => $v, 'bottomRight' => $v, 'bottomLeft' => $v ];
            case 'space-control':
                $space      = (array) ( $kit['space'] ?? [] );
                $control    = (array) ( $space['control'] ?? [] );
                $vertical   = (string) ( $control['vertical'] ?? '' );
                $horizontal = (string) ( $control['horizontal'] ?? '' );
                return [ 'top' => $vertical, 'right' => $horizontal, 'bottom' => $vertical, 'left' => $horizontal ];
            case 'space-card':
                $space = (array) ( $kit['space'] ?? [] );
                $v     = (string) ( $space['card'] ?? '' );
                return [ 'top' => $v, 'right' => $v, 'bottom' => $v, 'left' => $v ];
        }
        return null;
    }

    /**
     * Markdown for the AI: one line per kit when $id is null, otherwise that kit's decisions
     * (or a message listing the valid ids).
     */
    public static function markdown( ?string $id = null ): string
    {
        if ( null === $id ) {
            $lines = [ '# Style kits', '' ];
            foreach ( self::all() as $kit ) {
                $lines[] = sprintf( '- **%s** (`%s`): %s', (string) ( $kit['title'] ?? '' ), (string) $kit['id'], self::describe( $kit ) );
            }
            $lines[] = '';
            $lines[] = 'Ask for one kit in full with its id.';
            return implode( "\n", $lines ) . "\n";
        }

        $kit = self::get( $id );
        if ( null === $kit ) {
            return sprintf( "Unknown style kit \"%s\". Valid ids: %s.\n", SectionRecipes::plain( $id ), implode( ', ', self::ids() ) );
        }

        $b = (array) ( $kit['border'] ?? [] );
        $r = (array) ( $kit['radius'] ?? [] );
        $s = (array) ( $kit['shadow'] ?? [] );
        $t = (array) ( $kit['text'] ?? [] );
        $sp = (array) ( $kit['space'] ?? [] );
        $c = (array) ( $sp['control'] ?? [] );
        $lines = [
            sprintf( '# Style kit: %s (`%s`)', (string) ( $kit['title'] ?? '' ), (string) $kit['id'] ),
            '',
            self::describe( $kit ),
            '',
            sprintf( '- Fill: %s, carried by the %s colour role', (string) ( $kit['fill'] ?? '' ), (string) ( $kit['color'] ?? '' ) ),
            sprintf( '- Border: %s %s, colour %s%s', (string) ( $b['width'] ?? '' ), (string) ( $b['style'] ?? '' ), (string) ( $b['color'] ?? '' ), 'bottom' === ( $b['sides'] ?? 'all' ) ? ', bottom side only (an underline)' : '' ),
            sprintf( '- Radius: controls %s, cards %s, media %s', (string) ( $r['control'] ?? '' ), (string) ( $r['card'] ?? '' ), (string) ( $r['media'] ?? '' ) ),
            sprintf( '- Shadow: rest %s, hover %s', (string) ( $s['rest'] ?? '' ), (string) ( $s['hover'] ?? '' ) ),
            '- Hover: ' . (string) ( $kit['hover'] ?? '' ),
            sprintf( '- Text: capitalization %s, letter spacing %s, weight %s', (string) ( $t['capitalization'] ?? '' ), (string) ( $t['letterSpacing'] ?? '' ), (string) ( $t['weight'] ?? '' ) ),
            sprintf( '- Space: controls %s vertical and %s horizontal, cards %s', (string) ( $c['vertical'] ?? '' ), (string) ( $c['horizontal'] ?? '' ), (string) ( $sp['card'] ?? '' ) ),
        ];
        $avoids = $kit['avoids'] ?? [];
        if ( is_array( $avoids ) && [] !== $avoids ) {
            $lines[] = '- Avoid with recipe layouts: ' . implode( ', ', array_map( 'strval', $avoids ) );
        }
        return implode( "\n", $lines ) . "\n";
    }

    /**
     * What the secondary button is, by the fill the adapter's role table gives it, when an AI could not guess it from the
     * primary: an outline kit's secondary is a ghost (a plain label, no fill, no frame). An outlined secondary (of a solid
     * or ghost kit) is what the AI expects and is not stated.
     */
    private const SECONDARY_SENTENCES = [
        'solid' => 'The secondary is a filled block.',
        'ghost' => 'The secondary is a plain text link.',
    ];

    /**
     * The kit's one-line summary, plus (derived from the adapter's role table, never typed by hand) what the SECONDARY button
     * is when its fill is one SECONDARY_SENTENCES names (today: the ghost secondary of an outline kit), so the AI reads it
     * where it chooses. A summary that already says something about the secondary is left alone.
     *
     * @param array<string,mixed> $kit
     */
    private static function describe( array $kit ): string
    {
        $summary   = (string) ( $kit['summary'] ?? '' );
        $fill      = (string) ( $kit['fill'] ?? '' );
        $secondary = KitAdapters::secondaryFill( $fill );
        if ( $secondary !== $fill && isset( self::SECONDARY_SENTENCES[ $secondary ] ) && false === stripos( $summary, 'secondary' ) ) {
            $summary .= ' ' . self::SECONDARY_SENTENCES[ $secondary ];
        }
        return $summary;
    }

    /**
     * @param list<string> $problems
     * @param mixed        $value
     * @param list<string> $allowed
     */
    private static function oneOf( array &$problems, string $name, $value, array $allowed ): void
    {
        if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
            $problems[] = $name . ' must be one of: ' . implode( ', ', $allowed );
        }
    }

    /**
     * An object with exactly the given keys (extra keys are reported, missing ones are caught by the
     * caller's value checks). Returns null (and a problem) when it is not an object.
     *
     * @param list<string> $problems
     * @param mixed        $value
     * @param list<string> $keys
     * @return array<string,mixed>|null
     */
    private static function group( array &$problems, string $name, $value, array $keys ): ?array
    {
        if ( ! is_array( $value ) ) {
            $problems[] = $name . ' must be an object';
            return null;
        }
        foreach ( array_keys( $value ) as $key ) {
            if ( ! in_array( $key, $keys, true ) ) {
                $problems[] = 'unknown key ' . $name . '.' . self::label( $key );
            }
        }
        return $value;
    }

    /** @param mixed $key */
    private static function label( $key ): string
    {
        return substr( preg_replace( '/[^A-Za-z0-9_.-]/', '?', (string) $key ) ?? '', 0, 40 );
    }

    /** @param mixed $value */
    private static function isRadius( $value ): bool
    {
        if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,2})px\z/', $value, $m ) ) {
            return false;
        }
        return (int) $m[1] <= 48 || 999 === (int) $m[1];
    }

    /** @param mixed $value */
    private static function isSpace( $value ): bool
    {
        return is_string( $value ) && 1 === preg_match( '/^(0|[1-9][0-9]{0,2})px\z/', $value, $m ) && (int) $m[1] <= self::MAX_SPACE;
    }

    /**
     * Dotted paths of every string under $node that is too long or contains a hex colour, `<`, `>`, `&`,
     * `--`, a quote, a backtick, a brace or a control character (0x00-0x1F, 0x7F).
     *
     * @param mixed $node
     * @return list<string>
     */
    private static function unsafeStringPaths( $node, string $path ): array
    {
        if ( is_string( $node ) ) {
            $bad = strlen( $node ) > self::MAX_STRING
                || false !== strpbrk( $node, "<>&\"'`{}" )
                || 1 === preg_match( '/[\x00-\x1F\x7F]/', $node )
                || str_contains( $node, '--' )
                || 1 === preg_match( '/#[0-9a-fA-F]{3,8}(?![0-9a-zA-Z])/', $node );
            return $bad ? [ '' === $path ? 'value' : $path ] : [];
        }
        if ( ! is_array( $node ) ) {
            return [];
        }
        $out = [];
        foreach ( $node as $key => $child ) {
            $out = array_merge( $out, self::unsafeStringPaths( $child, '' === $path ? self::label( $key ) : $path . '.' . self::label( $key ) ) );
        }
        return $out;
    }
}
