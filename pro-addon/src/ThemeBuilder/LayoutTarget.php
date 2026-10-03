<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\ThemeBuilder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one rule for "which post may a Pro layout tool read and edit": a header or footer layout that a template of
 * the LIVE Divi Theme Builder uses, and only for a user who passes Divi's own Theme Builder bar. Pages are the free
 * plugin's job, so a page id is reported like any other post: not found. Nothing leaks about other post types.
 */
final class LayoutTarget
{
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';

    public const NO_THEME_BUILDER_PERMISSION = 'You need Divi\'s Theme Builder permission (and the right to edit other people\'s posts) to use this tool.';

    /**
     * @return array{post: ?\WP_Post, kind: ?string, error: ?string} kind is 'header' or 'footer'
     */
    public static function resolve( int $id ): array
    {
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post ) {
            return self::fail( self::NOT_FOUND );
        }
        $postKind = ThemeLayouts::kindOf( (string) $post->post_type );
        $kind     = null === $postKind ? null : ( ThemeLayouts::liveLayoutKinds()[ $id ] ?? null );
        if ( null === $kind || $postKind !== $kind || 'publish' !== $post->post_status ) {
            return self::fail( self::NOT_FOUND );
        }
        // Divi's own bar (edit_others_posts and the Role Editor's 'theme_builder'); a header or footer shows on every page.
        if ( ! current_user_can( 'edit_post', $id ) || ! ThemeLayouts::userMay() ) {
            return self::fail( self::FORBIDDEN );
        }

        return [ 'post' => $post, 'kind' => $kind, 'error' => null ];
    }

    /**
     * Like resolve(), but throws a message that is safe to show the client.
     *
     * @return array{post: \WP_Post, kind: string}
     * @throws \InvalidArgumentException
     */
    public static function mustResolve( int $id ): array
    {
        self::assertMay();
        $r = self::resolve( $id );
        if ( self::NOT_FOUND === $r['error'] ) {
            throw new \InvalidArgumentException( "Layout {$id} is not an editable header or footer of the live Theme Builder. Call list_theme_layouts to see the layout ids." );
        }
        if ( null !== $r['error'] || null === $r['post'] || null === $r['kind'] ) {
            throw new \InvalidArgumentException( "You do not have permission to edit layout {$id}." );
        }

        return [ 'post' => $r['post'], 'kind' => $r['kind'] ];
    }

    /** The tool-level gate, in addition to the registry's WordPress capability. */
    public static function assertMay(): void
    {
        if ( ! ThemeLayouts::userMay() ) {
            throw new \InvalidArgumentException( self::NO_THEME_BUILDER_PERMISSION );
        }
    }

    /** @return array{post: null, kind: null, error: string} */
    private static function fail( string $error ): array
    {
        return [ 'post' => null, 'kind' => null, 'error' => $error ];
    }
}
