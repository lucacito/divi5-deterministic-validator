<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sanitises what extension hooks hand back so a misbehaving add-on can never
 * remove, replace, duplicate or corrupt the plugin's own tools, paths or tabs.
 * Pure functions: built-ins always win; unusable add-on data is dropped.
 */
final class ExtensionGuard
{
    /**
     * @param array<int,array<string,mixed>> $builtIn
     * @return array<int,array<string,mixed>>
     */
    public static function tools( array $builtIn, mixed $filtered ): array
    {
        if ( ! is_array( $filtered ) ) {
            return $builtIn;
        }

        $taken = [];
        foreach ( $builtIn as $tool ) {
            $taken[ (string) ( $tool['name'] ?? '' ) ] = true;
        }

        $out = $builtIn;
        foreach ( $filtered as $tool ) {
            if ( ! is_array( $tool ) || ! isset( $tool['name'] ) || ! is_string( $tool['name'] ) || '' === $tool['name'] ) {
                continue;
            }
            if ( isset( $taken[ $tool['name'] ] ) ) {
                continue; // collides with a built-in or an earlier add-on tool
            }
            $taken[ $tool['name'] ] = true;
            $out[]                  = $tool;
        }

        return $out;
    }

    /**
     * Keyed maps (OpenAPI paths / schemas): built-in keys always win, new keys are appended.
     *
     * @param array<string,mixed> $builtIn
     * @return array<string,mixed>
     */
    public static function map( array $builtIn, mixed $filtered ): array
    {
        if ( ! is_array( $filtered ) ) {
            return $builtIn;
        }

        return $builtIn + array_diff_key( $filtered, $builtIn );
    }

    /**
     * Admin tabs (slug => label). Slugs are normalised like sanitize_key(); reserved or
     * empty slugs and non-scalar labels are skipped.
     *
     * @param list<string> $reserved
     * @return array<string,string>
     */
    public static function tabs( mixed $filtered, array $reserved ): array
    {
        if ( ! is_array( $filtered ) ) {
            return [];
        }

        $out = [];
        foreach ( $filtered as $slug => $label ) {
            if ( ! is_scalar( $label ) ) {
                continue;
            }
            $key = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $slug ) );
            if ( null === $key || '' === $key || in_array( $key, $reserved, true ) || isset( $out[ $key ] ) ) {
                continue;
            }
            $out[ $key ] = (string) $label;
        }

        return $out;
    }
}
