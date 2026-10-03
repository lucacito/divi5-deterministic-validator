<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Site;

use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use AiEditorDivi5\Pro\ThemeBuilder\ThemeLayouts;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Which pages and Theme Builder layouts a site-wide tool may look at FOR THE CURRENT USER: Divi 5 pages the user can
 * edit, and (only with `edit_theme_options` and Divi's Theme Builder bar) the live header and footer layouts. One
 * definition, so `find_replace_site` and `audit_site` can never disagree about what "the site" is.
 */
final class SiteItems
{
    /** More Divi 5 pages than this and the tool asks for a narrower scope instead of silently looking at some. */
    public const PAGE_LIMIT = 1000;

    public const SCOPES = [ 'pages', 'layouts', 'all' ];

    /** Statuses a write may touch (trash and auto-drafts never). */
    public const EDIT_STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    /** Statuses an audit reads (a scheduled page is still a page). */
    public const AUDIT_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

    public const LAYOUTS_SKIPPED = 'Header and footer layouts were skipped: they need the edit_theme_options capability and Divi\'s Theme Builder permission.';

    /**
     * @param list<string> $statuses
     * @param list<int>|null $ids restrict to these post ids (pages and layouts alike); null = no restriction
     * @return array{items: list<array{kind:string, id:int, title:string, status:string}>, truncated: bool, layouts_note: ?string}
     */
    public static function collect( string $scope, array $statuses, ?array $ids ): array
    {
        $items     = [];
        $truncated = false;
        $note      = null;

        if ( 'layouts' !== $scope ) {
            $query = [
                'post_type'      => 'page',
                'post_status'    => $statuses,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'posts_per_page' => self::PAGE_LIMIT + 1,
                'no_found_rows'  => true,
                'meta_query'     => [ [ 'key' => '_et_pb_use_divi_5', 'value' => 'on' ] ],
            ];
            if ( null !== $ids ) {
                $query['post__in'] = [] === $ids ? [ 0 ] : $ids;
            }
            $found = array_map( 'intval', (array) get_posts( $query ) );
            if ( count( $found ) > self::PAGE_LIMIT ) {
                $truncated = true;
                $found     = array_slice( $found, 0, self::PAGE_LIMIT );
            }
            foreach ( $found as $id ) {
                $post = get_post( $id );
                if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
                    continue;
                }
                $items[] = [ 'kind' => 'page', 'id' => $id, 'title' => (string) $post->post_title, 'status' => (string) $post->post_status ];
            }
        }

        if ( 'pages' !== $scope ) {
            if ( ThemeLayouts::userMay() && current_user_can( 'edit_theme_options' ) ) {
                $layouts = ThemeLayouts::liveLayoutKinds();
                ksort( $layouts );
                foreach ( $layouts as $id => $kind ) {
                    if ( null !== $ids && ! in_array( $id, $ids, true ) ) {
                        continue;
                    }
                    $target = LayoutTarget::resolve( $id );
                    if ( null !== $target['error'] || null === $target['post'] ) {
                        continue;
                    }
                    $items[] = [ 'kind' => $kind, 'id' => $id, 'title' => (string) $target['post']->post_title, 'status' => (string) $target['post']->post_status ];
                }
            } else {
                $note = self::LAYOUTS_SKIPPED;
            }
        }

        return [ 'items' => $items, 'truncated' => $truncated, 'layouts_note' => $note ];
    }

    public static function content( int $id ): string
    {
        $post = get_post( $id );

        return $post ? (string) $post->post_content : '';
    }
}
