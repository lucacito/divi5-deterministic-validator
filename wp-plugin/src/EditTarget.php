<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one rule for "which post may the plugin read and edit": a page the current user can edit.
 * Anything else is reported like a missing page, so nothing leaks about other post types.
 */
final class EditTarget
{
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';

    /**
     * @return array{post: ?\WP_Post, kind: ?string, error: ?string} kind is 'page' or null
     */
    public static function resolve( int $id ): array
    {
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post || 'page' !== $post->post_type ) {
            return self::fail( self::NOT_FOUND );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return self::fail( self::FORBIDDEN );
        }

        return [ 'post' => $post, 'kind' => 'page', 'error' => null ];
    }

    /** @return array{post: null, kind: null, error: string} */
    private static function fail( string $error ): array
    {
        return [ 'post' => null, 'kind' => null, 'error' => $error ];
    }
}
