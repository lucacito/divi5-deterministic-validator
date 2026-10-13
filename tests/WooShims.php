<?php

declare(strict_types=1);

/**
 * A minimal fake of the WooCommerce and WordPress read functions WooProductGateway calls, driven by $GLOBALS:
 *   __wc_products : id => FakeWcProduct
 *   __wc_terms    : taxonomy => id => ['name' => ..., 'slug' => ..., 'count' => ...]
 *   __wc_counts   : post status => count (wp_count_posts)
 *   __wc_attachments : id => url
 * Required only by the WooCommerce tests. WC_VERSION is defined here.
 */

if ( ! defined( 'WC_VERSION' ) ) {
    define( 'WC_VERSION', '11.1.2' );
}

if ( ! class_exists( 'FakeWcProduct' ) ) {
    /** Getter bag: get_x() / is_x() read the key x. Deliberately also answers the PRIVATE getters (downloads, purchase note, meta) so tests can prove the gateway never calls them. */
    class FakeWcProduct
    {
        /** @param array<string,mixed> $d */
        public function __construct( public array $d ) {}

        public function __call( string $name, array $args )
        {
            if ( 'is_type' === $name ) {
                return ( $this->d['type'] ?? '' ) === $args[0];
            }
            $key = preg_replace( '/^(get|is)_/', '', $name );
            if ( 'get_dimensions' === $name ) {
                return $this->d['dimensions'] ?? [ 'length' => '', 'width' => '', 'height' => '' ];
            }

            return $this->d[ $key ] ?? null;
        }
    }

    class FakeWcAttribute
    {
        /** @param array<string,mixed> $d */
        public function __construct( private array $d ) {}
        public function get_name() { return $this->d['name']; }
        public function is_taxonomy() { return (bool) ( $this->d['taxonomy'] ?? false ); }
        public function get_options() { return $this->d['options'] ?? []; }
        public function get_terms() { return array_map( fn ( $n ) => (object) [ 'name' => $n ], $this->d['terms'] ?? [] ); }
        public function get_variation() { return (bool) ( $this->d['variation'] ?? false ); }
        public function get_visible() { return (bool) ( $this->d['visible'] ?? true ); }
    }
}

if ( ! function_exists( 'wc_get_product' ) ) {
    function wc_get_product( $id ) { return $GLOBALS['__wc_products'][ (int) $id ] ?? false; }
}
if ( ! function_exists( 'wc_get_products' ) ) {
    function wc_get_products( array $args ) {
        $all = array_values( array_filter( $GLOBALS['__wc_products'] ?? [], fn ( $p ) => 'product_variation' !== ( $p->d['post_type'] ?? 'product' ) ) );
        $status = (array) ( $args['status'] ?? [ 'publish' ] );
        $all = array_values( array_filter( $all, fn ( $p ) => in_array( $p->d['status'], $status, true ) ) );
        if ( isset( $args['type'] ) ) {
            $all = array_values( array_filter( $all, fn ( $p ) => $p->d['type'] === $args['type'] ) );
        }
        if ( isset( $args['stock_status'] ) ) {
            $all = array_values( array_filter( $all, fn ( $p ) => $p->d['stock_status'] === $args['stock_status'] ) );
        }
        if ( isset( $args['category'] ) ) {
            $slugs = (array) $args['category'];
            $all   = array_values( array_filter( $all, function ( $p ) use ( $slugs ) {
                foreach ( (array) ( $p->d['category_ids'] ?? [] ) as $cid ) {
                    if ( in_array( $GLOBALS['__wc_terms']['product_cat'][ $cid ]['slug'] ?? '', $slugs, true ) ) {
                        return true;
                    }
                }
                return false;
            } ) );
        }
        if ( isset( $args['s'] ) ) {
            $all = array_values( array_filter( $all, fn ( $p ) => false !== stripos( (string) $p->d['name'], (string) $args['s'] ) ) );
        }
        $GLOBALS['__wc_last_args'] = $args;
        // Like the real WooCommerce: `orderby` goes to WP_Query, which knows title, date and ID but not "price" (an unknown
        // key falls back to the post date). The posts_clauses filters run as in WP_Query; the one ORDER BY this shim
        // understands is the _price-meta order WooProductGateway adds (the live gate, make verify-products, proves the SQL).
        $query   = new class( $args ) {
            /** @param array<string,mixed> $v */
            public function __construct( private array $v ) {}
            public function get( string $key ) { return $this->v[ $key ] ?? ''; }
        };
        $clauses = apply_filters( 'posts_clauses', [ 'where' => '', 'groupby' => '', 'join' => '', 'orderby' => 'wp_posts.post_date ' . ( $args['order'] ?? 'ASC' ), 'distinct' => '', 'fields' => 'wp_posts.ID', 'limits' => '' ], $query );
        $by      = $args['orderby'] ?? 'date';
        $date    = fn ( $a, $b ) => strcmp( (string) ( $a->d['date_created'] ?? '' ), (string) ( $b->d['date_created'] ?? '' ) ) ?: $a->d['id'] <=> $b->d['id'];
        $desc    = 'DESC' === ( $args['order'] ?? 'ASC' );
        if ( 1 === preg_match( '/aied_price\.price (ASC|DESC), \S+\.ID (ASC|DESC)$/', (string) $clauses['orderby'], $m ) ) {
            $desc = 'DESC' === $m[1];
            usort( $all, fn ( $a, $b ) => (float) $a->d['price'] <=> (float) $b->d['price'] ?: $a->d['id'] <=> $b->d['id'] );
        } elseif ( 'title' === $by ) {
            usort( $all, fn ( $a, $b ) => strcasecmp( (string) $a->d['name'], (string) $b->d['name'] ) );
        } else {
            usort( $all, $date );
        }
        if ( $desc ) {
            $all = array_reverse( $all );
        }
        $limit = (int) ( $args['limit'] ?? -1 );
        $paged = ! empty( $args['paginate'] );
        $total = count( $all );
        if ( $limit > 0 ) {
            $page = max( 1, (int) ( $args['page'] ?? 1 ) );
            $all  = array_slice( $all, ( $page - 1 ) * $limit, $limit );
        }
        $ids = array_map( fn ( $p ) => (int) $p->d['id'], $all );
        $out = 'ids' === ( $args['return'] ?? 'objects' ) ? $ids : $all;
        if ( $paged ) {
            return (object) [ 'products' => $out, 'total' => $total, 'max_num_pages' => $limit > 0 ? (int) ceil( $total / $limit ) : 1 ];
        }

        return $out;
    }
}
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
    function get_woocommerce_currency() { return 'USD'; }
}
if ( ! function_exists( 'wc_attribute_label' ) ) {
    // Like WooCommerce: with the parent product, a custom (non-taxonomy) attribute comes back as written ("Fit Type"); without it the
    // name is the sanitised key ("fit-type"), so only a crude label is possible.
    function wc_attribute_label( $name, $product = false )
    {
        if ( is_object( $product ) ) {
            foreach ( (array) $product->get_attributes() as $a ) {
                if ( is_object( $a ) && ! $a->is_taxonomy() && strtolower( str_replace( ' ', '-', (string) $a->get_name() ) ) === (string) $name ) {
                    return $a->get_name();
                }
            }
        }

        return ucfirst( preg_replace( '/^pa_/', '', (string) $name ) );
    }
}
if ( ! function_exists( 'taxonomy_exists' ) ) {
    function taxonomy_exists( $taxonomy ) { return isset( $GLOBALS['__wc_terms'][ (string) $taxonomy ] ); }
}
if ( ! function_exists( 'get_term_by' ) ) {
    function get_term_by( $field, $value, $taxonomy = '' )
    {
        foreach ( $GLOBALS['__wc_terms'][ (string) $taxonomy ] ?? [] as $id => $t ) {
            if ( 'slug' === $field && $t['slug'] === (string) $value ) {
                return (object) [ 'term_id' => $id, 'name' => $t['name'], 'slug' => $t['slug'] ];
            }
        }

        return false;
    }
}
if ( ! function_exists( 'get_post_status' ) ) {
    function get_post_status( $id )
    {
        $p = $GLOBALS['__wc_products'][ (int) $id ] ?? null;

        return $p ? ( $p->d['status'] ?? false ) : ( $GLOBALS['__wp_posts'][ (int) $id ]->post_status ?? false );
    }
}
if ( ! function_exists( 'get_terms' ) ) {
    function get_terms( $args ) {
        $out = [];
        foreach ( $GLOBALS['__wc_terms'][ $args['taxonomy'] ] ?? [] as $id => $t ) {
            if ( isset( $args['include'] ) && ! in_array( $id, (array) $args['include'], true ) ) {
                continue;
            }
            $out[] = (object) [ 'term_id' => $id, 'name' => $t['name'], 'slug' => $t['slug'], 'count' => $t['count'] ?? 0 ];
        }

        return $out;
    }
}
if ( ! function_exists( 'wp_count_posts' ) ) {
    function wp_count_posts( $type = 'post' ) { return (object) ( $GLOBALS['__ss_counts'][ $type ] ?? $GLOBALS['__wc_counts'] ?? [] ); }
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    function wp_get_attachment_url( $id ) { return $GLOBALS['__wc_attachments'][ (int) $id ] ?? false; }
}
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $id ) { return 'https://shop.test/?p=' . (int) $id; }
}
if ( ! function_exists( 'get_post_type' ) ) {
    function get_post_type( $id ) {
        $p = $GLOBALS['__wc_products'][ (int) $id ] ?? null;
        if ( $p ) {
            return $p->d['post_type'] ?? 'product';
        }

        return $GLOBALS['__wp_posts'][ (int) $id ]->post_type ?? false;
    }
}
