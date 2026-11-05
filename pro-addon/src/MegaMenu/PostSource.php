<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The newest published posts for the "latest posts" layouts. A menu item that points at a category, tag, other taxonomy
 * term or a post type archive shows that term's / type's posts; any other item shows the newest blog posts. Only public,
 * published, non-password posts; a snapshot taken when the layout is applied (it does not update by itself).
 */
class PostSource
{
    /**
     * @param array<string,mixed> $item a menu item (MenuTree)
     * @return list<array{title:string,url:string,image:string,date:string}>
     */
    public function latest( array $item, int $count = 4 ): array
    {
        $args = [ 'post_status' => 'publish', 'numberposts' => max(1, min( 6, $count )), 'orderby' => 'date', 'order' => 'DESC', 'has_password' => false, 'suppress_filters' => false ];
        if ( 'taxonomy' === $item['type'] && '' !== $item['object'] && $item['objectId'] > 0 && taxonomy_exists( $item['object'] ) ) {
            $tax               = get_taxonomy( $item['object'] );
            $args['post_type'] = $tax && ! empty( $tax->object_type ) ? array_values( $tax->object_type ) : 'post';
            $args['tax_query'] = [ [ 'taxonomy' => $item['object'], 'field' => 'term_id', 'terms' => [ $item['objectId'] ] ] ];
        } elseif ( 'post_type_archive' === $item['type'] && post_type_exists( $item['object'] ) && is_post_type_viewable( $item['object'] ) ) {
            $args['post_type'] = $item['object'];
        } else {
            $args['post_type'] = 'post';
        }

        $out = [];
        foreach ( get_posts( $args ) as $post ) {
            $link = get_permalink( $post );
            if ( ! is_string( $link ) ) {
                continue;
            }
            $thumb = get_the_post_thumbnail_url( $post, 'medium' );
            $out[] = [
                'title' => wp_strip_all_tags( get_the_title( $post ) ),
                'url'   => $link,
                'image' => is_string( $thumb ) ? $thumb : '',
                'date'  => (string) get_the_date( '', $post ),
            ];
        }

        return $out;
    }
}
