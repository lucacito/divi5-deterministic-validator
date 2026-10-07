<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Which post's custom fields the plugin may READ: a post of a public post type (a page, a Divi Project, a blog post...) that
 * the current user can edit. Anything else (a missing post, a non-public type, a post the user cannot edit) is reported
 * as the same NOT_FOUND, so nothing leaks about which posts exist.
 * Separate from EditTarget on purpose: that one decides which posts may be EDITED and stays page-only.
 */
final class FieldTarget
{
    public const NOT_FOUND = 'not_found';

    /**
     * @return array{post: ?\WP_Post, error: ?string}
     */
    public static function resolve( int $id ): array
    {
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post ) {
            return [ 'post' => null, 'error' => self::NOT_FOUND ];
        }
        $type = get_post_type_object( (string) $post->post_type );
        if ( ! $type || empty( $type->public ) ) {
            return [ 'post' => null, 'error' => self::NOT_FOUND ];
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return [ 'post' => null, 'error' => self::NOT_FOUND ];
        }

        return [ 'post' => $post, 'error' => null ];
    }
}
