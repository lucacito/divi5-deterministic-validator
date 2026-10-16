<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A READ-ONLY view of the site's Divi 5.15 Components (global modules): which components exist, what kind each is, the ids of the
 * Slots it offers and how many pages or projects use it. A component is a Divi Library item (post type `et_pb_layout`) in the
 * `global` scope; Divi's own `GlobalLayout::is_global_layout_template()` says whether an id is one. Nothing here writes anything.
 * Titles and counts only: a component's content is read to find its Slot ids and, by ComponentAdvisory, to tell which of its
 * attributes an instance may change; it is never returned.
 */
final class ComponentReader
{
    public const MAX_COMPONENTS = 50;
    public const MAX_SLOTS      = 20;
    public const MAX_TEXT       = 200;

    private const GLOBAL_LAYOUT = 'ET\\Builder\\Packages\\GlobalLayout\\GlobalLayout';

    /** Whether Divi (5.15 or newer) has Components at all. */
    public static function available(): bool
    {
        return class_exists( self::GLOBAL_LAYOUT ) && is_callable( [ self::GLOBAL_LAYOUT, 'is_global_layout_template' ] );
    }

    /** True for a global component, false for anything else; null when this Divi cannot tell. */
    public static function isComponent( int $id ): ?bool
    {
        if ( $id <= 0 || ! self::available() ) {
            return null;
        }
        try {
            return (bool) call_user_func( [ self::GLOBAL_LAYOUT, 'is_global_layout_template' ], $id );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /** The saved blocks of a global component, or null when the id is not one (or cannot be read). */
    public static function templateContent( int $id ): ?string
    {
        if ( true !== self::isComponent( $id ) || ! function_exists( 'get_post_field' ) ) {
            return null;
        }
        $content = get_post_field( 'post_content', $id );

        return is_string( $content ) ? $content : null;
    }

    /** What `get_site_structure` lists, read from WordPress. @return array{components: list<array<string,mixed>>, truncated: bool}|null null when Divi has no Components */
    public static function discover(): ?array
    {
        if ( ! self::available() || ! function_exists( 'get_posts' ) || ! function_exists( 'post_type_exists' ) || ! post_type_exists( 'et_pb_layout' ) ) {
            return null;
        }
        global $wpdb;
        $posts = get_posts( [
            'post_type'      => 'et_pb_layout',
            'post_status'    => 'publish',
            'posts_per_page' => self::MAX_COMPONENTS + 1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'tax_query'      => [ [ 'taxonomy' => 'scope', 'field' => 'slug', 'terms' => [ 'global' ] ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
        ] );
        $rows = [];
        foreach ( (array) $posts as $p ) {
            if ( ! $p instanceof \WP_Post || ! current_user_can( 'edit_post', $p->ID ) || true !== self::isComponent( (int) $p->ID ) ) {
                continue;
            }
            $kind = function_exists( 'wp_get_post_terms' ) ? wp_get_post_terms( $p->ID, 'layout_type', [ 'fields' => 'slugs' ] ) : [];
            $used = isset( $wpdb ) ? (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('page','project') AND post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s",
                '%' . $wpdb->esc_like( '"globalModule":"' . $p->ID . '"' ) . '%'
            ) ) : 0;
            $rows[] = [
                'id'      => (int) $p->ID,
                'title'   => (string) $p->post_title,
                'kind'    => is_array( $kind ) && isset( $kind[0] ) && is_string( $kind[0] ) ? $kind[0] : '',
                'content' => (string) $p->post_content,
                'used_on' => $used,
            ];
        }

        return self::discoverWith( $rows );
    }

    /**
     * @param list<array{id:int,title:string,kind:string,content:string,used_on:int}> $rows
     * @return array{components: list<array{id:int,name:string,kind:string,slots:list<string>,used_on:int}>, truncated: bool}
     */
    public static function discoverWith( array $rows ): array
    {
        $truncated  = count( $rows ) > self::MAX_COMPONENTS;
        $components = [];
        foreach ( array_slice( $rows, 0, self::MAX_COMPONENTS ) as $r ) {
            $slots        = self::slotIds( (string) $r['content'] );
            $components[] = [
                'id'      => (int) $r['id'],
                'name'    => mb_substr( (string) $r['title'], 0, self::MAX_TEXT ),
                'kind'    => in_array( $r['kind'], [ 'section', 'row', 'module' ], true ) ? (string) $r['kind'] : '',
                'slots'   => array_slice( $slots, 0, self::MAX_SLOTS ),
                'used_on' => max( 0, (int) $r['used_on'] ),
            ];
        }

        return [ 'components' => $components, 'truncated' => $truncated ];
    }

    /** The slotId of every Slot block in saved content, in order, without repeats. @return list<string> */
    public static function slotIds( string $content ): array
    {
        if ( ! preg_match_all( '/<!--\s+wp:divi\/global-slot\s+(\{.*?\})\s*\/?-->/s', $content, $m ) ) {
            return [];
        }
        $ids = [];
        foreach ( $m[1] as $json ) {
            $attrs = json_decode( $json, true );
            if ( is_array( $attrs ) && is_string( $attrs['slotId'] ?? null ) && '' !== $attrs['slotId'] ) {
                $ids[ $attrs['slotId'] ] = true;
            }
        }

        return array_map( 'strval', array_keys( $ids ) );
    }
}
