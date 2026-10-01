<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one rule for "which post may the plugin read and edit": a page (exactly as before) or a
 * header/footer layout that a template of the LIVE Divi Theme Builder uses. Anything else is
 * reported like a missing page, so nothing leaks about other post types.
 */
final class EditTarget
{
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';

    /**
     * @return array{post: ?\WP_Post, kind: ?string, error: ?string} kind is 'page', 'header' or 'footer'
     */
    public static function resolve( int $id ): array
    {
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post ) {
            return self::fail( self::NOT_FOUND );
        }

        if ( 'page' === $post->post_type ) {
            return current_user_can( 'edit_post', $id ) ? self::ok( $post, 'page' ) : self::fail( self::FORBIDDEN );
        }

        $kind = null === ThemeLayouts::kindOf( (string) $post->post_type ) ? null : ( ThemeLayouts::liveLayoutKinds()[ $id ] ?? null );
        if ( null === $kind || 'publish' !== $post->post_status || ThemeLayouts::kindOf( (string) $post->post_type ) !== $kind ) {
            return self::fail( self::NOT_FOUND );
        }
        // Divi's own Theme Builder needs edit_others_posts; the header/footer shows on every page.
        if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'edit_others_posts' ) ) {
            return self::fail( self::FORBIDDEN );
        }

        return self::ok( $post, $kind );
    }

    /** @return array{post: \WP_Post, kind: string, error: null} */
    private static function ok( \WP_Post $post, string $kind ): array
    {
        return [ 'post' => $post, 'kind' => $kind, 'error' => null ];
    }

    /** @return array{post: null, kind: null, error: string} */
    private static function fail( string $error ): array
    {
        return [ 'post' => null, 'kind' => null, 'error' => $error ];
    }
}
