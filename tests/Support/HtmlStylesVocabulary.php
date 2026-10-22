<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

/**
 * Reads the html_to_divi styles vocabulary (fixtures/shapes/html-styles-vocabulary.md "Adapter decisions"), its probe candidates and
 * the probe results (dev/test-side only). The one place the grounding tests and the STYLE_PROVEN pin look a verdict up.
 */
final class HtmlStylesVocabulary
{
    public const VOCABULARY = __DIR__ . '/../../fixtures/shapes/html-styles-vocabulary.md';
    public const CANDIDATES = __DIR__ . '/../../fixtures/shapes/candidates/html-styles.json';
    public const RESULTS    = __DIR__ . '/../../fixtures/shapes/probe-results-html-styles.json';

    /** @return array<string,array{verdict:string,path:string,evidence:string}> decision => row, in table order */
    public static function rows(): array
    {
        $text = (string) file_get_contents( self::VOCABULARY );
        $at   = strpos( $text, '## Adapter decisions' );
        if ( false === $at ) {
            return [];
        }
        preg_match_all( '/^\|\s*([A-Za-z][A-Za-z-]+)\s*\|\s*([A-Za-z-]+)\s*\|([^|\n]*)\|([^|\n]*)\|\s*$/m', substr( $text, $at ), $m, PREG_SET_ORDER );
        $rows = [];
        foreach ( $m as $row ) {
            if ( 'Decision' !== $row[1] ) {
                $rows[ $row[1] ] = [ 'verdict' => $row[2], 'path' => trim( $row[3] ), 'evidence' => trim( $row[4] ) ];
            }
        }

        return $rows;
    }

    /** @return array<string,array{id:string,block:string,set:array<string,mixed>}> id => candidate */
    public static function candidates(): array
    {
        $out = [];
        foreach ( (array) json_decode( (string) file_get_contents( self::CANDIDATES ), true ) as $c ) {
            $out[ (string) $c['id'] ] = $c;
        }

        return $out;
    }

    /** @return array<string,bool> candidate id => the probe measured an effect on the real Divi */
    public static function effects(): array
    {
        $out = [];
        foreach ( (array) json_decode( (string) file_get_contents( self::RESULTS ), true ) as $r ) {
            $out[ (string) $r['id'] ] = true === ( $r['effect'] ?? false );
        }

        return $out;
    }

    /**
     * block name => raw dotted paths (containers included) that a PASS decision's measured candidates prove ON that block. A candidate
     * the probe read DEAD proves nothing. The padding candidates set one side; the decision's table path names all four sides.
     *
     * @param array<string,bool> $proven decision => written
     * @return array<string,array<string,true>>
     */
    public static function provenPaths( array $proven ): array
    {
        $rows       = self::rows();
        $candidates = self::candidates();
        $effects    = self::effects();
        $out        = [];
        foreach ( $proven as $decision => $on ) {
            if ( ! $on || 'PASS' !== ( $rows[ $decision ]['verdict'] ?? '' ) ) {
                continue;
            }
            preg_match_all( '/hs-[a-z0-9-]+/', $rows[ $decision ]['evidence'], $ids );
            foreach ( array_unique( $ids[0] ) as $id ) {
                if ( ! isset( $candidates[ $id ] ) || true !== ( $effects[ $id ] ?? false ) ) {
                    continue;
                }
                $block = $candidates[ $id ]['block'];
                foreach ( array_keys( $candidates[ $id ]['set'] ) as $path ) {
                    $paths = [ $path ];
                    if ( 1 === preg_match( '/^(.*\.padding)\.(top|right|bottom|left)$/', $path, $pm ) ) {
                        $paths = array_map( static fn ( string $side ): string => $pm[1] . '.' . $side, [ 'top', 'right', 'bottom', 'left' ] );
                    }
                    foreach ( $paths as $p ) {
                        $segments = explode( '.', $p );
                        while ( [] !== $segments ) {
                            $out[ $block ][ implode( '.', $segments ) ] = true;
                            array_pop( $segments );
                        }
                    }
                }
            }
        }

        return $out;
    }
}
