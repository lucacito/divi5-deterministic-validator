<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only class that calls WooCommerce, through its own CRUD classes and public functions. create() makes drafts only; apply() changes
 * the fields it is given on an existing product and never its status, type or slug. Proven on the real site by
 * `make verify-pro-create-product` and `make verify-pro-update-products` (the unit tests use FakeProductWriter).
 */
final class WooProductWriter implements ProductWriter
{
    public function isActive(): bool
    {
        return class_exists( '\WC_Product_Simple' ) && function_exists( 'wc_get_product' ) && function_exists( 'wc_get_product_id_by_sku' );
    }

    public function skuTaken( string $sku ): bool
    {
        return (int) wc_get_product_id_by_sku( $sku ) > 0;
    }

    public function skuOwner( string $sku ): int
    {
        return (int) wc_get_product_id_by_sku( $sku );
    }

    public function termIds( string $taxonomy, array $slugs ): array
    {
        $ids     = [];
        $missing = [];
        foreach ( $slugs as $slug ) {
            $term = get_term_by( 'slug', $slug, $taxonomy );
            if ( is_object( $term ) && isset( $term->term_id ) ) {
                $ids[] = (int) $term->term_id;
            } else {
                $missing[] = $slug;
            }
        }

        return [ 'ids' => $ids, 'missing' => $missing ];
    }

    public function imageUsable( int $id ): bool
    {
        $post = get_post( $id );

        return is_object( $post )
            && 'attachment' === $post->post_type
            && str_starts_with( (string) get_post_mime_type( $id ), 'image/' )
            && current_user_can( 'upload_files' )
            && current_user_can( 'read_post', $id );
    }

    /**
     * Creates the draft. Cleanup rule: a failed save deletes at most ONE id, and only a post that is provably the draft this save
     * inserted. The candidate is the product object's own id when WooCommerce set it; otherwise the listener's record: the one-shot
     * transition_post_status listener records EVERY product post that turns into a DRAFT with exactly the name set here during the save
     * (a product another hook inserts with another title is never recorded). If more than one post matched, nothing is deleted
     * (InsertedProduct::ambiguous). Right before deleting, the candidate is re-read and must still be a draft product with our name;
     * otherwise nothing is deleted. Whenever nothing is deleted after the save started, the caller is told a draft may be left behind.
     * Residual: if another hook inserts a DRAFT product with exactly our name during our save and our own insert never happens (the
     * save fails before it), that foreign draft is the only match: it is deleted, and the tool says "Nothing was created." When both
     * inserts happen, the two matches make it ambiguous: neither is deleted and the tool says a draft may have been left behind.
     *
     * @throws ProductSaveFailed with a constant message (the id of a product it could not remove, or mayHaveLeftover)
     * @throws \WC_Data_Exception only from a setter, before save() started, so no post was inserted and its own text can be shown
     */
    public function create( array $values ): int
    {
        $p           = null;
        $seen        = [];
        $saveStarted = false;
        $name        = (string) $values['name'];
        $listener    = static function ( $new, $old, $post ) use ( &$seen, $name ): void {
            if ( 'draft' === $new && InsertedProduct::matches( $post, $name ) ) {
                $seen[] = (int) $post->ID;
            }
        };
        try {
            $p = new \WC_Product_Simple();
            $p->set_name( $name );
            $p->set_status( 'draft' );
            $p->set_description( (string) $values['description'] );
            $p->set_short_description( (string) $values['short_description'] );
            if ( '' !== $values['sku'] ) {
                $p->set_sku( (string) $values['sku'] );
            }
            $p->set_regular_price( (string) $values['regular_price'] );
            $p->set_sale_price( (string) $values['sale_price'] );
            $p->set_manage_stock( (bool) $values['manage_stock'] );
            if ( $values['manage_stock'] ) {
                $p->set_stock_quantity( (int) $values['stock_quantity'] );
                $p->set_backorders( (string) $values['backorders'] );
            } else {
                $p->set_stock_status( (string) $values['stock_status'] );
            }
            $p->set_category_ids( array_map( 'intval', $values['category_ids'] ) );
            $p->set_tag_ids( array_map( 'intval', $values['tag_ids'] ) );
            if ( (int) $values['image_id'] > 0 ) {
                $p->set_image_id( (int) $values['image_id'] );
            }
            $p->set_gallery_image_ids( array_map( 'intval', $values['gallery_image_ids'] ) );
            $p->set_weight( (string) $values['weight'] );
            $p->set_length( (string) $values['length'] );
            $p->set_width( (string) $values['width'] );
            $p->set_height( (string) $values['height'] );
            $p->set_tax_status( (string) $values['tax_status'] );
            $p->set_virtual( (bool) $values['virtual'] );
            $p->set_sold_individually( (bool) $values['sold_individually'] );

            add_action( 'transition_post_status', $listener, PHP_INT_MIN, 3 );
            $saveStarted = true;
            $id          = (int) $p->save();
        } catch ( \Throwable $e ) {
            $objectId = null !== $p ? (int) $p->get_id() : 0;
            if ( InsertedProduct::ambiguous( $objectId, $seen ) ) {
                throw new ProductSaveFailed( 'WooCommerce could not save the product', null, true );
            }
            $inserted = InsertedProduct::candidate( $objectId, $seen );
            if ( $inserted > 0 ) {
                if ( ! $this->stillOurs( $inserted, $name ) ) {
                    throw new ProductSaveFailed( 'WooCommerce could not save the product', null, true );
                }
                throw new ProductSaveFailed( 'WooCommerce could not save the product', $this->removeQuietly( $inserted ) ? null : $inserted );
            }
            if ( ! $saveStarted && is_a( $e, 'WC_Data_Exception' ) ) {
                throw $e; // a setter's own validation: save() never ran, so nothing was inserted
            }
            throw new ProductSaveFailed( 'WooCommerce could not save the product', null, $saveStarted );
        } finally {
            remove_action( 'transition_post_status', $listener, PHP_INT_MIN );
        }
        if ( $id <= 0 ) {
            throw new ProductSaveFailed( 'WooCommerce did not save the product' );
        }

        return $id;
    }

    /** Re-reads the candidate right before a cleanup delete: it must still be a draft product with our name. */
    private function stillOurs( int $id, string $name ): bool
    {
        try {
            clean_post_cache( $id );

            return InsertedProduct::matches( get_post( $id ), $name );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    private function removeQuietly( int $id ): bool
    {
        try {
            return $this->delete( $id );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    public function snapshot( int $id ): ?array
    {
        $p = wc_get_product( $id );
        if ( ! $p || 'simple' !== $p->get_type() || 'product' !== get_post_type( $id ) ) {
            return null;
        }
        $manage = (bool) $p->get_manage_stock();
        $dims   = (array) $p->get_dimensions( false );
        $cats   = array_map( 'intval', (array) $p->get_category_ids() );
        $tags   = array_map( 'intval', (array) $p->get_tag_ids() );
        sort( $cats );
        sort( $tags );
        $qty = $p->get_stock_quantity();

        return [
            'status'            => (string) $p->get_status(),
            'name'              => (string) $p->get_name(),
            'description'       => (string) $p->get_description(),
            'short_description' => (string) $p->get_short_description(),
            'sku'               => (string) $p->get_sku(),
            'regular_price'     => (string) $p->get_regular_price(),
            'sale_price'        => (string) $p->get_sale_price(),
            'manage_stock'      => $manage,
            'stock_quantity'    => $manage && null !== $qty ? (int) $qty : null,
            'stock_status'      => (string) $p->get_stock_status(),
            'backorders'        => (string) $p->get_backorders(),
            'category_ids'      => $cats,
            'tag_ids'           => $tags,
            'image_id'          => (int) $p->get_image_id(),
            'gallery_image_ids' => array_map( 'intval', (array) $p->get_gallery_image_ids() ),
            'weight'            => (string) $p->get_weight(),
            'length'            => (string) ( $dims['length'] ?? '' ),
            'width'             => (string) ( $dims['width'] ?? '' ),
            'height'            => (string) ( $dims['height'] ?? '' ),
            'tax_status'        => (string) $p->get_tax_status(),
            'virtual'           => (bool) $p->get_virtual(),
            'sold_individually' => (bool) $p->get_sold_individually(),
        ];
    }

    /** Candidates examined to decide `on_sale` and `search` (same cap as the free plugin's product list). */
    private const MAX_SCAN = 500;

    /**
     * The arguments for wc_get_products() (pure: no WooCommerce or WordPress call), or null when the criteria can match nothing.
     * Two WordPress facts shape it: WP_Query applies `post__not_in` only when `post__in` is not set, so with `ids` the exclusion is
     * subtracted here and `exclude` is never sent together with `include`; and `s` also searches the excerpt and the content (and a
     * leading minus means NOT), so it is sent only as a safe superset (letters, digits and spaces) and the name is decided in PHP.
     *
     * @param array<string,mixed> $criteria
     * @return ?array<string,mixed>
     */
    public static function findQuery( array $criteria, int $limit ): ?array
    {
        $needScan = null !== ( $criteria['on_sale'] ?? null ) || null !== ( $criteria['search'] ?? null );
        $q        = [
            'return'  => 'ids',
            'status'  => $criteria['status'] ?? [ 'publish' ],
            'type'    => [] === ( $criteria['types'] ?? [] ) ? [ 'simple', 'variable', 'external' ] : $criteria['types'],
            'orderby' => 'ID',
            'order'   => 'ASC',
        ];
        if ( [] !== ( $criteria['categories'] ?? [] ) ) {
            $q['category'] = $criteria['categories'];
        }
        if ( [] !== ( $criteria['tags'] ?? [] ) ) {
            $q['tag'] = $criteria['tags'];
        }
        if ( null !== ( $criteria['stock_status'] ?? null ) ) {
            $q['stock_status'] = $criteria['stock_status'];
        }
        $search = $criteria['search'] ?? null;
        if ( null !== $search && 1 === preg_match( '/^[\p{L}\p{N} ]+$/u', (string) $search ) ) {
            $q['s'] = $search;
        }
        $exclude = (array) ( $criteria['exclude_ids'] ?? [] );
        if ( [] !== ( $criteria['ids'] ?? [] ) ) {
            $include = array_values( array_diff( $criteria['ids'], $exclude ) );
            if ( [] === $include ) {
                return null;
            }
            $q['include'] = $include;
        } elseif ( [] !== $exclude ) {
            $q['exclude'] = $exclude;
        }

        return $q + [ 'limit' => ( $needScan ? self::MAX_SCAN : $limit ) + 1, 'paginate' => false ];
    }

    /** Whether a product name contains the whole phrase, ignoring case (pure: a phrase, not separate words). */
    public static function nameMatches( string $name, string $phrase ): bool
    {
        return false !== mb_stripos( $name, $phrase );
    }

    public function findProducts( array $criteria, int $limit ): array
    {
        $q = self::findQuery( $criteria, $limit );
        if ( null === $q ) {
            return [ 'ids' => [], 'truncated' => false ];
        }
        $onSale    = $criteria['on_sale'] ?? null;
        $search    = $criteria['search'] ?? null;
        $cap       = $q['limit'] - 1;
        $ids       = array_map( 'intval', (array) wc_get_products( $q ) );
        $truncated = count( $ids ) > $cap;
        $ids       = array_slice( $ids, 0, $cap );
        if ( null !== $onSale || null !== $search ) {
            $hits = [];
            foreach ( $ids as $id ) {
                $p = wc_get_product( $id );
                if ( ! $p ) {
                    continue;
                }
                if ( null !== $onSale && (bool) $p->is_on_sale() !== $onSale ) {
                    continue;
                }
                if ( null !== $search && ! self::nameMatches( (string) $p->get_name( 'edit' ), (string) $search ) ) {
                    continue;
                }
                $hits[] = $id;
            }
            $ids = $hits;
            if ( count( $ids ) > $limit ) {
                $truncated = true;
                $ids       = array_slice( $ids, 0, $limit );
            }
        }

        return [ 'ids' => $ids, 'truncated' => $truncated ];
    }

    public function priceDecimals(): int
    {
        return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
    }

    public function typeOf( int $id ): ?string
    {
        $post = get_post( $id );
        if ( ! is_object( $post ) || 'product' !== $post->post_type || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
            return null;
        }
        $p = wc_get_product( $id );

        return $p ? (string) $p->get_type() : null;
    }

    public function product( int $id ): ?array
    {
        $type = $this->typeOf( $id );
        if ( ! in_array( $type, [ 'simple', 'external', 'variable' ], true ) ) {
            return null;
        }
        $p = wc_get_product( $id );
        if ( ! $p ) {
            return null;
        }
        $cats = array_map( 'intval', (array) $p->get_category_ids( 'edit' ) );
        $tags = array_map( 'intval', (array) $p->get_tag_ids( 'edit' ) );
        sort( $cats );
        sort( $tags );
        $variations = [];
        if ( 'variable' === $type ) {
            foreach ( array_map( 'intval', (array) $p->get_children() ) as $vid ) {
                $v = wc_get_product( $vid );
                if ( $v && 'variation' === $v->get_type() ) {
                    $variations[ $vid ] = self::row( $v );
                }
            }
            ksort( $variations );
        }

        return [
            'type'              => $type,
            'status'            => (string) $p->get_status(),
            'name'              => (string) $p->get_name( 'edit' ),
            'description'       => (string) $p->get_description( 'edit' ),
            'short_description' => (string) $p->get_short_description( 'edit' ),
            'sku'               => (string) $p->get_sku( 'edit' ),
            'category_ids'      => $cats,
            'category_slugs'    => self::slugs( $cats, 'product_cat' ),
            'tag_ids'           => $tags,
            'tag_slugs'         => self::slugs( $tags, 'product_tag' ),
            'image_id'          => (int) $p->get_image_id( 'edit' ),
            'gallery_image_ids' => array_map( 'intval', (array) $p->get_gallery_image_ids( 'edit' ) ),
            'weight'            => (string) $p->get_weight( 'edit' ),
            'length'            => (string) $p->get_length( 'edit' ),
            'width'             => (string) $p->get_width( 'edit' ),
            'height'            => (string) $p->get_height( 'edit' ),
            'tax_status'        => (string) $p->get_tax_status( 'edit' ),
            'variations'        => $variations,
        ] + self::row( $p );
    }

    /**
     * Writes only the fields given. The sale window follows WooCommerce's own product screen: a sale starts at 00:00:00 of its first day and
     * ends at 23:59:59 of its last, in the site's time zone. The parent of a variable product is re-synced after its variations changed.
     *
     * @throws ProductSaveFailed with a constant message (a product or variation that cannot be loaded)
     * @throws \WC_Data_Exception from a setter or save (its own text is meant to be shown: a taken SKU, for example)
     */
    public function apply( int $id, array $changes ): void
    {
        $p = null === $this->typeOf( $id ) ? null : wc_get_product( $id );
        if ( ! $p ) {
            throw new ProductSaveFailed( 'the product could not be loaded' );
        }
        $variations = (array) ( $changes['variations'] ?? [] );
        unset( $changes['variations'] );
        $loaded = [];
        foreach ( $variations as $vid => $row ) {
            $v = wc_get_product( (int) $vid );
            if ( ! $v || 'variation' !== $v->get_type() || (int) $v->get_parent_id() !== $id ) {
                throw new ProductSaveFailed( 'a variation does not belong to this product' );
            }
            $loaded[ (int) $vid ] = $v;
        }
        if ( [] !== $changes ) {
            self::fill( $p, $changes );
            if ( array_key_exists( 'category_ids', $changes ) ) {
                $p->set_category_ids( array_map( 'intval', (array) $changes['category_ids'] ) );
            }
            if ( array_key_exists( 'tag_ids', $changes ) ) {
                $p->set_tag_ids( array_map( 'intval', (array) $changes['tag_ids'] ) );
            }
            if ( array_key_exists( 'image_id', $changes ) ) {
                $p->set_image_id( (int) $changes['image_id'] > 0 ? (int) $changes['image_id'] : '' );
            }
            if ( array_key_exists( 'gallery_image_ids', $changes ) ) {
                $p->set_gallery_image_ids( array_map( 'intval', (array) $changes['gallery_image_ids'] ) );
            }
            $p->save();
        }
        if ( [] === $variations ) {
            return;
        }
        foreach ( $variations as $vid => $row ) {
            $v = $loaded[ (int) $vid ];
            self::fill( $v, (array) $row );
            $v->save();
        }
        if ( class_exists( '\WC_Product_Variable' ) ) {
            \WC_Product_Variable::sync( $id );
            if ( is_callable( [ 'WC_Product_Variable', 'sync_stock_status' ] ) ) {
                \WC_Product_Variable::sync_stock_status( $id );
            }
        }
        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients( $id );
        }
    }

    /** The price, sale window and stock part, read the same way for a product and for a variation (edit context: no inheritance from a parent). */
    private static function row( object $p ): array
    {
        $manage = (bool) $p->get_manage_stock( 'edit' );
        $qty    = $p->get_stock_quantity( 'edit' );
        $from   = $p->get_date_on_sale_from( 'edit' );
        $to     = $p->get_date_on_sale_to( 'edit' );
        $low    = $p->get_low_stock_amount( 'edit' );

        return [
            'regular_price'  => (string) $p->get_regular_price( 'edit' ),
            'sale_price'     => (string) $p->get_sale_price( 'edit' ),
            'sale_from'      => $from ? (string) $from->date( 'Y-m-d' ) : '',
            'sale_to'        => $to ? (string) $to->date( 'Y-m-d' ) : '',
            'manage_stock'   => $manage,
            'stock_quantity' => $manage && null !== $qty ? (int) $qty : null,
            'stock_status'   => (string) $p->get_stock_status( 'edit' ),
            'backorders'     => (string) $p->get_backorders( 'edit' ),
            'low_stock_amount' => null === $low || '' === $low ? '' : (int) $low,
        ];
    }

    /** The setters both a product and a variation have, for the keys present. @param array<string,mixed> $c */
    private static function fill( object $p, array $c ): void
    {
        if ( array_key_exists( 'name', $c ) ) {
            $p->set_name( (string) $c['name'] );
        }
        if ( array_key_exists( 'description', $c ) ) {
            $p->set_description( (string) $c['description'] );
        }
        if ( array_key_exists( 'short_description', $c ) ) {
            $p->set_short_description( (string) $c['short_description'] );
        }
        if ( array_key_exists( 'sku', $c ) ) {
            $p->set_sku( (string) $c['sku'] );
        }
        if ( array_key_exists( 'regular_price', $c ) ) {
            $p->set_regular_price( (string) $c['regular_price'] );
        }
        if ( array_key_exists( 'sale_price', $c ) ) {
            $p->set_sale_price( (string) $c['sale_price'] );
        }
        if ( array_key_exists( 'sale_from', $c ) ) {
            $p->set_date_on_sale_from( '' === $c['sale_from'] ? null : $c['sale_from'] . ' 00:00:00' );
        }
        if ( array_key_exists( 'sale_to', $c ) ) {
            $p->set_date_on_sale_to( '' === $c['sale_to'] ? null : $c['sale_to'] . ' 23:59:59' );
        }
        if ( array_key_exists( 'weight', $c ) ) {
            $p->set_weight( (string) $c['weight'] );
        }
        if ( array_key_exists( 'length', $c ) ) {
            $p->set_length( (string) $c['length'] );
        }
        if ( array_key_exists( 'width', $c ) ) {
            $p->set_width( (string) $c['width'] );
        }
        if ( array_key_exists( 'height', $c ) ) {
            $p->set_height( (string) $c['height'] );
        }
        if ( array_key_exists( 'tax_status', $c ) ) {
            $p->set_tax_status( (string) $c['tax_status'] );
        }
        // Stock: the order matters. WooCommerce works the stock status out itself while stock is managed, and clears the quantity and the
        // backorders when it is not, so a quantity of null and a status for a managed product are not written.
        if ( array_key_exists( 'manage_stock', $c ) ) {
            $p->set_manage_stock( (bool) $c['manage_stock'] );
        }
        if ( array_key_exists( 'low_stock_amount', $c ) ) {
            $p->set_low_stock_amount( '' === $c['low_stock_amount'] || null === $c['low_stock_amount'] ? '' : (int) $c['low_stock_amount'] );
        }
        if ( array_key_exists( 'stock_quantity', $c ) && null !== $c['stock_quantity'] ) {
            $p->set_stock_quantity( (int) $c['stock_quantity'] );
        }
        if ( array_key_exists( 'backorders', $c ) ) {
            $p->set_backorders( (string) $c['backorders'] );
        }
        if ( array_key_exists( 'stock_status', $c ) && ! $p->get_manage_stock( 'edit' ) ) {
            $p->set_stock_status( (string) $c['stock_status'] );
        }
    }

    /** @param list<int> $ids @return list<string> */
    private static function slugs( array $ids, string $taxonomy ): array
    {
        $out = [];
        foreach ( $ids as $id ) {
            $term  = get_term( $id, $taxonomy );
            $out[] = is_object( $term ) && isset( $term->slug ) ? (string) $term->slug : 'term-' . $id;
        }

        return $out;
    }

    public function delete( int $id ): bool
    {
        return (bool) wp_delete_post( $id, true );
    }

    public function editUrl( int $id ): string
    {
        return (string) admin_url( 'post.php?post=' . $id . '&action=edit' );
    }
}
