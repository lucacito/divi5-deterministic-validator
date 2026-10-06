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
 * unknown color is left undefined; a missing variable makes Divi silently drop the value that cites it), so this is an advisory only: it never changes a verdict, blocks
 * a save or alters content. The bundled validator is used read-only and stays site-agnostic.
 */
final class StyleReferences
{
    public const MAX_IDS = 20;

    public const MESSAGE = "These ids do not exist on this site; Divi falls back to the module's default preset (presets), leaves the color undefined (colors), or silently drops the value that cites the variable (variables). A custom field (custom_fields) that is not an ACF field here and that no post has renders an empty module. Call list_global_styles and use this site's own ids; call get_site_structure for the site's ACF fields.";

    private const COLOR_TOKEN = '/gcid-[A-Za-z0-9_-]+/';

    private const VARIABLE_TOKEN = '/gvid-[A-Za-z0-9_-]+/';

    private const BINDING_TOKEN = '/\$variable\((\{.*?\})\)\$/s';

    private const META_PREFIX = 'custom_meta_';

    private const MANUAL_ENTRY = 'custom_meta_manual_custom_field_value';

    /**
     * @param array{presets: ?array<string,true>, colors: ?array<string,true>, variables?: ?array<string,true>, custom_fields?: ?callable(string):bool} $known ids that exist on the site; null (or a missing key) = cannot tell
     * @return ?array{unknown_presets: list<array{id:string,count:int}>, unknown_colors: list<array{id:string,count:int}>, unknown_variables: list<array{id:string,count:int}>, unknown_custom_fields: list<array{id:string,count:int}>, message: string}
     */
    public static function advise( string $content, array $known ): ?array
    {
        $knownVariables = $known['variables'] ?? null;
        $knownFields    = $known['custom_fields'] ?? null;
        if ( null === $known['presets'] && null === $known['colors'] && null === $knownVariables && null === $knownFields ) {
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
        $fields    = [];
        $parsed->root()->walk( static function ( Block $block ) use ( &$presets, &$colors, &$variables, &$fields, $known, $knownVariables, $knownFields ): void {
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
            if ( null !== $knownFields ) {
                foreach ( self::customFieldKeys( $attrs ) as $key ) {
                    if ( ! $knownFields( $key ) ) {
                        $fields[ $key ] = ( $fields[ $key ] ?? 0 ) + 1;
                    }
                }
            }
        } );

        if ( [] === $presets && [] === $colors && [] === $variables && [] === $fields ) {
            return null;
        }

        return [
            'unknown_presets'   => self::rows( $presets ),
            'unknown_colors'    => self::rows( $colors ),
            'unknown_variables' => self::rows( $variables ),
            'unknown_custom_fields' => self::rows( $fields ),
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
        $advice = self::advise( $content, self::knownIds() );

        return null === $advice ? $payload : $payload + [ 'advisories' => [ 'style_references' => $advice ] ];
    }

    /**
     * What exists on this site, for checking a layout: Divi's preset, color and variable ids plus a custom-field lookup.
     *
     * @return array{presets: ?array<string,true>, colors: ?array<string,true>, variables: ?array<string,true>, custom_fields: callable(string):bool}
     */
    public static function knownIds(): array
    {
        return GlobalStyles::knownIds() + [ 'custom_fields' => CustomFields::lookup() ];
    }

    /**
     * The custom-field keys the attributes bind dynamic content to, in order, repeats included. Two stored forms exist:
     * name "post_meta_key" with the field in settings.select_meta_key (what the builder wrote in a real export), and name
     * "custom_meta_<field>" (which Divi 5.14 also reads). Both prefix every field with "custom_meta_"; a manual entry
     * ("custom_meta_manual_custom_field_value") names the field in settings.meta_key instead. Built-in sources such as
     * post_title are not custom fields.
     *
     * @param array<mixed> $node
     * @return list<string>
     */
    private static function customFieldKeys( array $node ): array
    {
        $out = [];
        foreach ( self::tokens( $node, self::BINDING_TOKEN ) as $token ) {
            $json    = substr( $token, 10, -2 );
            $binding = json_decode( $json, true );
            if ( ! is_array( $binding ) || 'content' !== ( $binding['type'] ?? null ) || ! is_array( $binding['value'] ?? null ) ) {
                continue;
            }
            $name     = $binding['value']['name'] ?? null;
            $settings = is_array( $binding['value']['settings'] ?? null ) ? $binding['value']['settings'] : [];
            if ( 'post_meta_key' === $name ) {
                $name = $settings['select_meta_key'] ?? '';
            }
            if ( ! is_string( $name ) || ! str_starts_with( $name, self::META_PREFIX ) ) {
                continue;
            }
            $key = self::MANUAL_ENTRY === $name ? ( $settings['meta_key'] ?? '' ) : substr( $name, strlen( self::META_PREFIX ) );
            if ( is_string( $key ) && '' !== $key ) {
                $out[] = $key;
            }
        }

        return $out;
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
