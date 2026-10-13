<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ONLY class that calls WooCommerce, and only its public READ functions. It copies an explicit list of fields into a plain
 * array; it never calls a getter for downloads, the purchase note or meta, so none of them can reach the AI.
 */
final class WooProductGateway implements ProductGateway
{
    private const LIST_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

    public function isActive(): bool
    {
        return function_exists( 'wc_get_products' ) && function_exists( 'wc_get_product' );
    }

    public function info(): array
    {
        return [
            'version'  => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
            'currency' => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
        ];
    }

    public function isProduct( int $id ): bool
    {
        return $id > 0 && 'product' === get_post_type( $id );
    }

    public function query( array $args ): array
    {
        $q = [
            'return'  => 'ids',
            'status'  => 'any' === $args['status'] ? self::LIST_STATUSES : $args['status'],
            'orderby' => $args['orderby'],
            'order'   => strtoupper( (string) $args['order'] ),
        ];
        foreach ( [ 'type', 'stock_status' ] as $key ) {
            if ( null !== $args[ $key ] ) {
                $q[ $key ] = $args[ $key ];
            }
        }
        if ( null !== $args['category'] ) {
            $q['category'] = [ $args['category'] ];
        }
        if ( null !== $args['search'] ) {
            $q['s'] = $args['search'];
        }
        $perPage = (int) $args['per_page'];
        $page    = (int) $args['page'];

        if ( null === $args['on_sale'] ) {
            $r = wc_get_products( $q + [ 'limit' => $perPage, 'page' => $page, 'paginate' => true ] );

            return [ 'ids' => array_map( 'intval', (array) $r->products ), 'total' => (int) $r->total, 'truncated' => false ];
        }

        // wc_get_products() silently ignores an `on_sale` argument (checked on WooCommerce 11.1.2), so decide it here.
        $ids       = array_map( 'intval', (array) wc_get_products( $q + [ 'limit' => self::MAX_SCAN + 1, 'paginate' => false ] ) );
        $truncated = count( $ids ) > self::MAX_SCAN;
        $hits      = [];
        foreach ( array_slice( $ids, 0, self::MAX_SCAN ) as $id ) {
            $p = wc_get_product( $id );
            if ( $p && (bool) $p->is_on_sale() === $args['on_sale'] ) {
                $hits[] = $id;
            }
        }

        return [ 'ids' => array_slice( $hits, ( $page - 1 ) * $perPage, $perPage ), 'total' => count( $hits ), 'truncated' => $truncated ];
    }

    public function statusCounts(): array
    {
        $counts = (array) wp_count_posts( 'product' );
        $out    = [];
        foreach ( self::LIST_STATUSES as $status ) {
            $out[ $status ] = (int) ( $counts[ $status ] ?? 0 );
        }

        return $out;
    }

    public function publishedTypeCounts(): array
    {
        $terms = get_terms( [ 'taxonomy' => 'product_type', 'hide_empty' => false ] );
        if ( ! is_array( $terms ) ) {
            return [];
        }
        $out = [];
        foreach ( $terms as $term ) {
            $out[ (string) $term->slug ] = (int) $term->count;
        }

        return $out;
    }

    public function row( int $id ): ?array
    {
        $p = wc_get_product( $id );

        return $p ? $this->base( $p ) : null;
    }

    public function detail( int $id ): ?array
    {
        $p = wc_get_product( $id );
        if ( ! $p ) {
            return null;
        }
        $raw  = $this->base( $p );
        $type = (string) $p->get_type();
        $img  = (int) $p->get_image_id();

        $raw['description']        = (string) $p->get_description();
        $raw['short_description']  = (string) $p->get_short_description();
        $raw['password_protected'] = '' !== (string) $p->get_post_password();
        $raw['tax_status']         = (string) $p->get_tax_status();
        $raw['tax_class']          = (string) $p->get_tax_class();
        $raw['weight']             = (string) $p->get_weight();
        $raw['dimensions']         = (array) $p->get_dimensions( false );
        $raw['shipping_class']     = (string) $p->get_shipping_class();
        $raw['sold_individually']  = (bool) $p->get_sold_individually();
        $raw['backorders']         = (string) $p->get_backorders();
        $raw['low_stock_amount']   = $p->get_low_stock_amount();
        $raw['virtual']            = (bool) $p->get_virtual();
        $raw['downloadable']       = (bool) $p->get_downloadable(); // the flag only: never get_downloads()
        $raw['featured']           = (bool) $p->get_featured();
        $raw['catalog_visibility'] = (string) $p->get_catalog_visibility();
        $raw['attributes']         = $this->attributes( (array) $p->get_attributes() );
        $raw['images']             = [
            'featured' => $img > 0 ? $this->url( $img ) : null,
            'gallery'  => array_values( array_filter( array_map( [ $this, 'url' ], array_slice( array_map( 'intval', (array) $p->get_gallery_image_ids() ), 0, ProductReader::MAX_IMAGES ) ) ) ),
        ];
        $raw['tags']               = $this->terms( 'product_tag', (array) $p->get_tag_ids() );
        $raw['upsell_ids']         = array_map( 'intval', (array) $p->get_upsell_ids() );
        $raw['cross_sell_ids']     = array_map( 'intval', (array) $p->get_cross_sell_ids() );
        $created                   = $p->get_date_created();
        $modified                  = $p->get_date_modified();
        $raw['date_created']       = $created ? (string) $created->date( 'c' ) : null;
        $raw['date_modified']      = $modified ? (string) $modified->date( 'c' ) : null;
        $raw['average_rating']     = (string) $p->get_average_rating();
        $raw['review_count']       = (int) $p->get_review_count();

        if ( 'external' === $type ) {
            $raw['external'] = [ 'product_url' => (string) $p->get_product_url(), 'button_text' => (string) $p->get_button_text() ];
        }
        if ( 'grouped' === $type ) {
            $raw['children'] = array_map( 'intval', (array) $p->get_children() );
        }
        if ( 'variable' === $type ) {
            $children                 = array_map( 'intval', (array) $p->get_children() );
            $raw['variations_total']  = count( $children );
            $raw['variations']        = [];
            foreach ( array_slice( $children, 0, ProductReader::MAX_VARIATIONS ) as $vid ) {
                $v = wc_get_product( $vid );
                if ( ! $v ) {
                    continue;
                }
                $attrs = [];
                foreach ( (array) $v->get_attributes() as $name => $value ) {
                    $attrs[ (string) wc_attribute_label( (string) $name ) ] = (string) $value;
                }
                $vimg                = (int) $v->get_image_id();
                $raw['variations'][] = [
                    'id' => (int) $v->get_id(), 'sku' => (string) $v->get_sku(), 'status' => (string) $v->get_status(),
                    'price' => (string) $v->get_price(), 'regular_price' => (string) $v->get_regular_price(), 'sale_price' => (string) $v->get_sale_price(),
                    'on_sale' => (bool) $v->is_on_sale(), 'stock_status' => (string) $v->get_stock_status(),
                    'manage_stock' => (bool) $v->get_manage_stock(), 'stock_quantity' => $v->get_stock_quantity(),
                    'attributes' => $attrs, 'image' => $vimg > 0 ? $this->url( $vimg ) : null,
                ];
            }
        }

        return $raw;
    }

    /** @return array<string,mixed> */
    private function base( object $p ): array
    {
        $id     = (int) $p->get_id();
        $manage = (bool) $p->get_manage_stock();
        $type   = (string) $p->get_type();
        $row    = [
            'id' => $id, 'name' => (string) $p->get_name(), 'type' => $type, 'status' => (string) $p->get_status(),
            'sku' => (string) $p->get_sku(), 'price' => (string) $p->get_price(), 'regular_price' => (string) $p->get_regular_price(),
            'sale_price' => (string) $p->get_sale_price(), 'on_sale' => (bool) $p->is_on_sale(),
            'stock_status' => (string) $p->get_stock_status(), 'manage_stock' => $manage, 'stock_quantity' => $manage ? $p->get_stock_quantity() : null,
            'categories' => $this->terms( 'product_cat', (array) $p->get_category_ids() ),
            'url' => (string) get_permalink( $id ),
        ];
        if ( 'variable' === $type ) {
            $row['variations_count'] = count( (array) $p->get_children() );
        }

        return $row;
    }

    /** @param list<mixed> $ids @return list<array{id:int,name:string,slug:string}> */
    private function terms( string $taxonomy, array $ids ): array
    {
        if ( [] === $ids ) {
            return [];
        }
        $terms = get_terms( [ 'taxonomy' => $taxonomy, 'include' => array_map( 'intval', $ids ), 'hide_empty' => false ] );
        if ( ! is_array( $terms ) ) {
            return [];
        }
        $out = [];
        foreach ( $terms as $t ) {
            $out[] = [ 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug ];
        }

        return $out;
    }

    /** @param array<mixed> $attributes @return list<array<string,mixed>> */
    private function attributes( array $attributes ): array
    {
        $out = [];
        foreach ( $attributes as $a ) {
            if ( ! is_object( $a ) ) {
                continue;
            }
            $taxonomy = (bool) $a->is_taxonomy();
            $options  = [];
            if ( $taxonomy ) { // WooCommerce stores term ids; the AI needs the names
                foreach ( (array) $a->get_terms() as $t ) {
                    if ( is_object( $t ) ) {
                        $options[] = (string) $t->name;
                    }
                }
            } else {
                foreach ( (array) $a->get_options() as $o ) {
                    $options[] = (string) $o;
                }
            }
            $out[] = [
                'name' => (string) wc_attribute_label( (string) $a->get_name() ), 'options' => $options,
                'variation' => (bool) $a->get_variation(), 'visible' => (bool) $a->get_visible(), 'taxonomy' => $taxonomy,
            ];
        }

        return $out;
    }

    private function url( int $attachmentId ): ?string
    {
        $url = wp_get_attachment_url( $attachmentId );

        return is_string( $url ) && '' !== $url ? $url : null;
    }
}
