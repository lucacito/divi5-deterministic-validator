<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Lab;

use AiEditorDivi5\WP\StudioBrief;
use AiEditorDivi5\WP\StudioCompiler;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Compiles a Lab brief the way the free Lab screen does (`StudioService::compile()`, which is private): the same link-existence
 * check and home address, so the Pro tools and the screen give the same markup for the same brief.
 */
final class LabCompile
{
    /**
     * @param array<string,mixed> $brief
     * @return array<string,mixed> `StudioCompiler::compile()`'s result
     */
    public static function compile( array $brief ): array
    {
        $exists = function_exists( 'url_to_postid' ) && function_exists( 'home_url' )
            ? static fn ( string $path ): bool => url_to_postid( home_url( $path ) ) > 0
            : null;

        return StudioCompiler::compile( $brief, $exists, function_exists( 'home_url' ) ? (string) home_url() : '' );
    }

    /**
     * The brief as Lab normalises it (`StudioBrief::check()`), or null when it does not pass.
     *
     * @param array<string,mixed> $brief
     * @return array<string,mixed>|null
     */
    public static function clean( array $brief ): ?array
    {
        $check = StudioBrief::check( $brief );

        return $check['ok'] ? $check['brief'] : null;
    }
}
