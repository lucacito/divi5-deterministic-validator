<?php
/**
 * Dev-only: what the style-kit button migration (plan 2026-10-18 style kits, Task 7) changed in the SERVED recipes.
 *
 *   php scripts/kit-migration-report.php [old-revision]     (default revision: 97f0774, the recipes before kits)
 *
 * For every `divi/button` in the recipes x every design language it compares the OLD served button (the recipe file at
 * the old revision, colour and font tokens resolved with the language) with the NEW one (the current file, served as
 * SectionRecipes::recipe() serves it: the language's own style kit, then the language's tokens): fill, border, radius,
 * label colour, padding and hover, plus the label's contrast at rest and on hover against what it sits on (its own fill,
 * or the nearest coloured ancestor when the fill is transparent). Writes dist/kit-migration-report.md (git-ignored) and
 * prints a short summary. Reads the old file with `git show` (local repository only). Pure PHP otherwise, no network.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' ); // the plugin classes refuse to load outside WordPress without it
}
$root = dirname( __DIR__ );
foreach ( [ 'DesignLanguages', 'RecipeTokens', 'StyleKits', 'KitAdapters', 'KitResolver' ] as $class ) {
    require_once $root . '/wp-plugin/src/' . $class . '.php';
}
require_once $root . '/tests/Support/Colour.php';

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\Colour;

$rev = $argv[1] ?? '97f0774';
if ( 1 !== preg_match( '/^[0-9a-f]{7,40}$/', $rev ) ) {
    fwrite( STDERR, "The revision must be a commit id.\n" );
    exit( 2 );
}
$file   = 'wp-plugin/data/section-recipes.json';
$oldRaw = (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' show ' . escapeshellarg( $rev . ':' . $file ) . ' 2>/dev/null' );
$newRaw = (string) file_get_contents( $root . '/' . $file );
$old    = json_decode( $oldRaw, true );
$new    = json_decode( $newRaw, true );
if ( ! is_array( $old ) || ! is_array( $new ) ) {
    fwrite( STDERR, "Could not read the recipes (old revision $rev or the current file).\n" );
    exit( 1 );
}
$byName = static function ( array $rows ): array {
    $out = [];
    foreach ( $rows as $r ) {
        $out[ (string) $r['name'] ] = (string) $r['markup'];
    }
    return $out;
};
$old = $byName( $old );
$new = $byName( $new );

/** Opening/closing divi block comments in order: [close?, name, json|null, selfClosing?]. */
function blocks( string $markup ): array
{
    preg_match_all( '/<!--\s+(\/?)wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+(\/?)-->/s', $markup, $m, PREG_SET_ORDER );
    return array_map( static fn ( array $b ): array => [ '/' === $b[1], $b[2], $b[3] ?? null, '/' === ( $b[4] ?? '' ) ], $m );
}

/** The colour a block paints behind its content, or null (gradients: the first stop, over an image or not). */
function paint( array $attrs ): ?string
{
    $bg = $attrs['module']['decoration']['background']['desktop']['value'] ?? null;
    if ( ! is_array( $bg ) ) {
        return null;
    }
    if ( 'on' === ( $bg['gradient']['enabled'] ?? null ) && isset( $bg['gradient']['stops'][0]['color'] ) ) {
        return (string) $bg['gradient']['stops'][0]['color'];
    }
    return isset( $bg['color'] ) && '' !== $bg['color'] ? (string) $bg['color'] : null;
}

/**
 * Every button of a served markup, in order, with what it sits on.
 *
 * @return list<array{attrs:array<string,mixed>,under:?string}>
 */
function buttons( string $markup ): array
{
    $stack = [];
    $out   = [];
    foreach ( blocks( $markup ) as [ $close, $name, $json, $self ] ) {
        if ( $close ) {
            array_pop( $stack );
            continue;
        }
        $attrs = null === $json ? [] : (array) json_decode( $json, true );
        if ( 'divi/button' === $name ) {
            $under = null;
            foreach ( array_reverse( $stack ) as $c ) {
                if ( null !== $c ) {
                    $under = $c;
                    break;
                }
            }
            $out[] = [ 'attrs' => $attrs, 'under' => $under ];
        }
        if ( ! $self ) {
            $stack[] = paint( $attrs );
        }
    }
    return $out;
}

/** The compared properties of one served button ('-' = not set: the module preset decides). */
function props( array $attrs ): array
{
    $d   = (array) ( $attrs['button']['decoration'] ?? [] );
    $get = static function ( array $a, string $path ) {
        foreach ( explode( '.', $path ) as $k ) {
            if ( ! is_array( $a ) || ! array_key_exists( $k, $a ) ) {
                return '-';
            }
            $a = $a[ $k ];
        }
        return is_array( $a ) ? json_encode( $a, JSON_UNESCAPED_SLASHES ) : (string) $a;
    };
    $radius = $get( $d, 'border.desktop.value.radius' );
    if ( '-' !== $radius ) {
        $r      = (array) json_decode( $radius, true );
        $corner = array_unique( array_intersect_key( $r, array_flip( [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ] ) ) );
        $radius = 1 === count( $corner ) ? (string) reset( $corner ) : $radius;
    }
    $pad = $get( $d, 'spacing.desktop.value.padding' );
    if ( '-' !== $pad ) {
        $p   = (array) json_decode( $pad, true );
        $pad = ( $p['top'] ?? '?' ) . ' ' . ( $p['right'] ?? '?' );
    }
    return [
        'fill'         => $get( $d, 'background.desktop.value.color' ),
        'border'       => $get( $d, 'border.desktop.value.styles.all.width' ) . ' ' . $get( $d, 'border.desktop.value.styles.all.style' ) . ' ' . $get( $d, 'border.desktop.value.styles.all.color' ),
        'radius'       => $radius,
        'label'        => $get( $d, 'font.font.desktop.value.color' ),
        'weight'       => $get( $d, 'font.font.desktop.value.weight' ) . ' ' . $get( $d, 'font.font.desktop.value.capitalization' ),
        'padding'      => $pad,
        'hover fill'   => $get( $d, 'background.desktop.hover.color' ),
        'hover label'  => $get( $d, 'font.font.desktop.hover.color' ),
        'hover border' => $get( $d, 'border.desktop.hover.styles.all.color' ),
    ];
}

/** Label contrast against its own fill, or what it sits on when the fill is transparent or unset. null = cannot tell. */
function contrast( string $label, string $fill, ?string $under, string $pageBg ): ?float
{
    if ( '-' === $label ) {
        return null;
    }
    $f = '-' === $fill ? null : Colour::rgba( strtolower( $fill ) );
    $behind = null !== $under ? Colour::opaque( $under, $pageBg ) : $pageBg;
    if ( null === $behind ) {
        return null;
    }
    $back = ( null === $f || $f[3] < 0.01 ) ? $behind : Colour::opaque( $fill, $behind );
    $fg   = Colour::opaque( $label, (string) $back );
    return null === $back || null === $fg ? null : Colour::contrast( $fg, $back );
}

$langs   = DesignLanguages::ids();
$changed = [];
$rows    = [];
$low     = [];
$buttons = 0;
foreach ( $new as $name => $markup ) {
    if ( ! str_contains( $markup, 'wp:divi/button ' ) || ! isset( $old[ $name ] ) ) {
        continue;
    }
    foreach ( $langs as $id ) {
        $lang  = (array) DesignLanguages::get( $id );
        $kitId = (string) ( $lang['kit'] ?? '' );
        $kit   = StyleKits::get( $kitId );
        if ( null === $kit ) {
            fwrite( STDERR, "$id names an unknown kit \"$kitId\".\n" );
            exit( 1 );
        }
        $before = buttons( RecipeTokens::resolve( $old[ $name ], $lang ) );
        $after  = buttons( RecipeTokens::resolve( KitResolver::resolve( $markup, $kit ), $lang ) );
        $pageBg = (string) $lang['colors']['bg'];
        foreach ( $after as $i => $b ) {
            if ( 0 === array_search( $id, $langs, true ) ) {
                ++$buttons;
            }
            $o    = props( $before[ $i ]['attrs'] ?? [] );
            $n    = props( $b['attrs'] );
            $diff = [];
            foreach ( $n as $k => $v ) {
                if ( $o[ $k ] !== $v ) {
                    $diff[] = "$k: {$o[$k]} -> $v";
                    $changed[ $k ][ "$name#$i" ] = true;
                }
            }
            $rest  = contrast( $n['label'], $n['fill'], $b['under'], $pageBg );
            $hover = contrast( '-' === $n['hover label'] ? $n['label'] : $n['hover label'], '-' === $n['hover fill'] ? $n['fill'] : $n['hover fill'], $b['under'], $pageBg );
            $was   = contrast( $o['label'], $o['fill'], $before[ $i ]['under'] ?? null, $pageBg );
            foreach ( [ 'rest' => $rest, 'hover' => $hover ] as $state => $ratio ) {
                if ( null !== $ratio && $ratio < 4.5 ) {
                    $low[] = sprintf( '%s#%d under %s (%s kit), %s: %.2f:1 (was %s)', $name, $i, $id, $kitId, $state, $ratio, null === $was ? '?' : sprintf( '%.2f:1', $was ) );
                }
            }
            $rows[] = sprintf(
                '| %s#%d | %s | %s | %s | %s | %s |',
                $name,
                $i,
                $id,
                $kitId,
                null === $rest ? '?' : sprintf( '%.2f', $rest ),
                null === $hover ? '?' : sprintf( '%.2f', $hover ),
                [] === $diff ? 'unchanged' : str_replace( '|', '\\|', implode( '; ', $diff ) )
            );
        }
    }
}

$sizeBefore = strlen( $oldRaw );
$sizeAfter  = strlen( $newRaw );
$summary    = [];
$summary[]  = sprintf( '%d buttons x %d languages = %d served buttons compared (old: %s, new: the working tree).', $buttons, count( $langs ), count( $rows ), $rev );
$summary[]  = sprintf( 'Recipe file: %s -> %s bytes (%+d).', number_format( $sizeBefore ), number_format( $sizeAfter ), $sizeAfter - $sizeBefore );
foreach ( $changed as $prop => $which ) {
    $summary[] = sprintf( '- %s changed on %d of %d buttons (in at least one language)', $prop, count( $which ), $buttons );
}
$summary[] = [] === $low ? 'Label contrast below 4.5:1 (rest or hover, against the fill or what a transparent button sits on): none.' : 'Label contrast below 4.5:1: ' . count( $low ) . ' case(s), listed below.';

$md  = "# Style-kit button migration report\n\n" . implode( "\n", $summary ) . "\n\n";
$md .= "Contrast columns: the label against its own fill, or against the nearest coloured ancestor when the button is transparent (gradients: the first stop; images are not measured, the overlay or card colour is). '-' in a value = not set (the module preset decides).\n\n";
if ( [] !== $low ) {
    $md .= "## Low contrast\n\n- " . implode( "\n- ", $low ) . "\n\n";
}
$md .= "## Every button x language\n\n| button | language | kit | rest | hover | differences (old -> new) |\n|---|---|---|---|---|---|\n" . implode( "\n", $rows ) . "\n";

$dist = $root . '/dist';
if ( ! is_dir( $dist ) && ! mkdir( $dist, 0777, true ) && ! is_dir( $dist ) ) {
    fwrite( STDERR, "Could not create dist/.\n" );
    exit( 1 );
}
file_put_contents( $dist . '/kit-migration-report.md', $md );
echo implode( "\n", $summary ) . "\n";
foreach ( $low as $l ) {
    echo "  $l\n";
}
echo "Wrote dist/kit-migration-report.md\n";
