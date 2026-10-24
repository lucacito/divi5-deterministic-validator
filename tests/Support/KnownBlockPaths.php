<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

require_once __DIR__ . '/ServedVariants.php';

/**
 * Per block, every attribute path (leaf or container, list indexes folded into []) that a real Divi 5 export
 * (fixtures/valid) or a served recipe (every language's and every style kit's markers resolved, ServedVariants) carries ON that block name. Dev/test-side
 * only. DiviMarkupTest and HtmlToDiviGroundingTest both read it, so "proven ON its own block" means one thing.
 */
final class KnownBlockPaths
{
    /** @var ?array<string,array<string,true>> */
    private static ?array $known = null;

    /** @return array<string,array<string,true>> block name (no "divi/" prefix) => path => true */
    public static function all(): array
    {
        if ( null !== self::$known ) {
            return self::$known;
        }
        $sources = [];
        foreach ( (array) glob( __DIR__ . '/../../fixtures/valid/*.json' ) as $f ) {
            $d = json_decode( (string) file_get_contents( $f ), true );
            if ( is_array( $d ) && is_string( $d['post_content'] ?? null ) ) {
                $sources[] = $d['post_content'];
            }
        }
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../../wp-plugin/data/section-recipes.json' ), true ) as $r ) {
            // As served: every language's and every style kit's markers resolved (a marker is not a Divi attribute and
            // grounds nothing).
            array_push( $sources, ...ServedVariants::of( (string) ( $r['markup'] ?? '' ) ) );
        }
        $known = [];
        foreach ( $sources as $content ) {
            if ( 0 < preg_match_all( '/<!-- wp:divi\/([a-z0-9-]+) (\{.*?\}) \/?-->/s', $content, $m, PREG_SET_ORDER ) ) {
                foreach ( $m as $block ) {
                    $attrs = json_decode( $block[2], true );
                    if ( is_array( $attrs ) ) {
                        foreach ( self::paths( $attrs ) as $path ) {
                            $known[ $block[1] ][ $path ] = true;
                        }
                    }
                }
            }
        }

        return self::$known = $known;
    }

    /** Dot paths of every key, leaf or not; list indexes are folded into []. @return list<string> */
    public static function paths( array $a, string $prefix = '' ): array
    {
        $out = [];
        foreach ( $a as $k => $v ) {
            $key   = is_int( $k ) ? '[]' : (string) $k;
            $path  = '' === $prefix ? $key : $prefix . '.' . $key;
            $out[] = $path;
            if ( is_array( $v ) ) {
                array_push( $out, ...self::paths( $v, $path ) );
            }
        }

        return $out;
    }
}
