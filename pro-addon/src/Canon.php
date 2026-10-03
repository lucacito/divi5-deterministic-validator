<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Canonical JSON and sha256, so "has this changed since the batch wrote it?" is a stable string comparison. */
final class Canon
{
    public static function hash( mixed $value ): string
    {
        return hash( 'sha256', (string) json_encode( self::normalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) );
    }

    private static function normalize( mixed $value ): mixed
    {
        if ( is_string( $value ) && 1 !== preg_match( '//u', $value ) ) {
            // JSON_PARTIAL_OUTPUT_ON_ERROR would turn every invalid string into null, so different values would hash alike.
            return [ '__bytes' => base64_encode( $value ) ];
        }
        if ( ! is_array( $value ) ) {
            return $value;
        }
        if ( ! array_is_list( $value ) ) {
            ksort( $value );
        }

        return array_map( [ self::class, 'normalize' ], $value );
    }
}
