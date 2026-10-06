<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/CodePolicy.php';

/**
 * Finds the preset ids, global color ids and global variable ids a layout cites that do not exist on this site. Divi
 * renders such a layout anyway (an unknown preset falls back to the module's default preset; an
 * unknown color is left undefined), so this is an advisory only: it never changes a verdict, blocks
 * a save or alters content. The bundled validator is used read-only and stays site-agnostic.
 */
final class StyleReferences
{
    public const MAX_IDS = 20;

    public const MESSAGE = "These ids do not exist on this site; Divi falls back to the module's default preset (presets), leaves the color undefined (colors), or silently drops the value that cites the variable (variables). Call list_global_styles and use this site's own ids.";

    private const COLOR_TOKEN = '/gcid-[A-Za-z0-9_-]+/';

    private const VARIABLE_TOKEN = '/gvid-[A-Za-z0-9_-]+/';

    /**
     * @param array{presets: ?array<string,true>, colors: ?array<string,true>, variables?: ?array<string,true>} $known ids that exist on the site; null (or a missing key) = cannot tell
     * @return ?array{unknown_presets: list<array{id:string,count:int}>, unknown_colors: list<array{id:string,count:int}>, unknown_variables: list<array{id:string,count:int}>, message: string}
     */
    public static function advise( string $content, array $known ): ?array
    {
        $knownVariables = $known['variables'] ?? null;
        if ( null === $known['presets'] && null === $known['colors'] && null === $knownVariables ) {
            return null;
        }
        // Content too large or too deeply nested to walk safely gets no advisory (the policy refuses it anyway).
        if ( CodePolicy::tooLarge( $content ) || CodePolicy::tooDeep( $content ) ) {
            return null;
        }
        $parsed = ( new BlockParser() )->parse( $content );
        if ( ! $parsed->isOk() ) {
            return null;
        }

        $presets   = [];
        $colors    = [];
        $variables = [];
        $parsed->root()->walk( static function ( Block $block ) use ( &$presets, &$colors, &$variables, $known, $knownVariables ): void {
            $attrs = $block->attrs();
            if ( null !== $known['presets'] ) {
                foreach ( (array) ( $attrs['modulePreset'] ?? [] ) as $id ) {
                    if ( is_string( $id ) && '' !== $id && 'default' !== $id && ! isset( $known['presets'][ $id ] ) ) {
                        $presets[ $id ] = ( $presets[ $id ] ?? 0 ) + 1;
                    }
                }
            }
            if ( null !== $known['colors'] ) {
                foreach ( self::tokens( $attrs, self::COLOR_TOKEN ) as $id ) {
                    if ( ! isset( $known['colors'][ $id ] ) ) {
                        $colors[ $id ] = ( $colors[ $id ] ?? 0 ) + 1;
                    }
                }
            }
            if ( null !== $knownVariables ) {
                foreach ( self::tokens( $attrs, self::VARIABLE_TOKEN ) as $id ) {
                    if ( ! isset( $knownVariables[ $id ] ) ) {
                        $variables[ $id ] = ( $variables[ $id ] ?? 0 ) + 1;
                    }
                }
            }
        } );

        if ( [] === $presets && [] === $colors && [] === $variables ) {
            return null;
        }

        return [
            'unknown_presets'   => self::rows( $presets ),
            'unknown_colors'    => self::rows( $colors ),
            'unknown_variables' => self::rows( $variables ),
            'message'           => self::MESSAGE,
        ];
    }

    /**
     * Adds `advisories.style_references` to a response payload, only when the layout cites ids this
     * site does not have. Otherwise the payload is returned unchanged, byte for byte.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function attach( array $payload, string $content ): array
    {
        $advice = self::advise( $content, GlobalStyles::knownIds() );

        return null === $advice ? $payload : $payload + [ 'advisories' => [ 'style_references' => $advice ] ];
    }

    /** Every token matching $pattern in any string value of the attributes, in order, repeats included. @param array<mixed> $node @return list<string> */
    private static function tokens( array $node, string $pattern ): array
    {
        $out = [];
        foreach ( $node as $value ) {
            if ( is_array( $value ) ) {
                array_push( $out, ...self::tokens( $value, $pattern ) );
            } elseif ( is_string( $value ) && preg_match_all( $pattern, $value, $m ) ) {
                array_push( $out, ...$m[0] );
            }
        }

        return $out;
    }

    /** @param array<string,int> $counts @return list<array{id:string,count:int}> */
    private static function rows( array $counts ): array
    {
        $rows = [];
        foreach ( array_slice( $counts, 0, self::MAX_IDS, true ) as $id => $count ) {
            $rows[] = [ 'id' => self::shown( (string) $id ), 'count' => $count ];
        }

        return $rows;
    }

    /** An id as shown back to the AI: cut to 100 characters, and never invalid UTF-8. */
    private static function shown( string $id ): string
    {
        return 1 === preg_match( '/^.{0,100}/su', $id, $m ) ? $m[0] : '[invalid id]';
    }
}
