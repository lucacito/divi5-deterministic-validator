<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one rule for "which post may the plugin read and edit": a page or a Divi Project the current user can edit.
 * Anything else is reported like a missing post, so nothing leaks about other post types.
 */
final class EditTarget
{
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';

    /** The only post types the plugin reads and edits layouts of. A fixed list on purpose. */
    public const ALLOWED_TYPES = [ 'page', 'project' ];

    /**
     * @return array{post: ?\WP_Post, kind: ?string, error: ?string} kind is the post type ('page' or 'project') or null
     */
    public static function resolve( int $id ): array
    {
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post || ! in_array( $post->post_type, self::ALLOWED_TYPES, true ) ) {
            return self::fail( self::NOT_FOUND );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return self::fail( self::FORBIDDEN );
        }

        return [ 'post' => $post, 'kind' => $post->post_type, 'error' => null ];
    }

    /** @return array{post: null, kind: null, error: string} */
    private static function fail( string $error ): array
    {
        return [ 'post' => null, 'kind' => null, 'error' => $error ];
    }
}
