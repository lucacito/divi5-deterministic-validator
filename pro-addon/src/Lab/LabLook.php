<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Lab;

use AiEditorDivi5\WP\DesignLanguages;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A Lab brief's look (design language + style kit), normalised: a brief without a kit uses its language's own kit.
 */
final class LabLook
{
    /** @param array<string,mixed> $brief @return array{language:string,kit:string}|null null for an unknown language */
    public static function of( array $brief ): ?array
    {
        $language = $brief['language'] ?? null;
        if ( ! is_string( $language ) ) {
            return null;
        }
        $def = DesignLanguages::get( $language );
        if ( $def === null ) {
            return null;
        }
        $kit = $brief['kit'] ?? null;
        if ( ! is_string( $kit ) || $kit === '' ) {
            $kit = (string) ( $def['kit'] ?? '' );
        }

        return [ 'language' => $language, 'kit' => $kit ];
    }

    /**
     * The brief with a new look. null keeps the current value; `$kit === 'default'` removes the brief's `kit` key (the language's
     * own kit applies); any other value sets it. No other key is touched.
     *
     * @param array<string,mixed> $brief
     * @return array<string,mixed>
     */
    public static function with( array $brief, ?string $language, ?string $kit ): array
    {
        if ( $language !== null ) {
            $brief['language'] = $language;
        }
        if ( $kit === 'default' ) {
            unset( $brief['kit'] );
        } elseif ( $kit !== null ) {
            $brief['kit'] = $kit;
        }

        return $brief;
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    public static function same( array $a, array $b ): bool
    {
        $la = self::of( $a );
        $lb = self::of( $b );

        return $la !== null && $lb !== null && $la === $lb;
    }
}
