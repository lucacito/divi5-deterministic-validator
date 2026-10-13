<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only class that calls WooCommerce, through its own CRUD classes and public functions. It creates drafts only and never touches
 * an existing product. Proven on the real site by `make verify-pro-create-product` (the unit tests use FakeProductWriter).
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
     * inserted. The candidate is the product object's own id when WooCommerce set it, else the id the one-shot transition_post_status
     * listener recorded; the listener records only a product post turning into a DRAFT whose title is the name set here (so a product
     * another hook inserts during our save, with another title, is never taken for ours). Right before deleting, the candidate is
     * re-read and must still be a draft product with our name (InsertedProduct::matches); otherwise nothing is deleted and the caller
     * is told a draft may have been left behind. Residual: a foreign DRAFT product with exactly our name, inserted during our failing
     * save before our own insert, could still be mistaken for ours (extremely unlikely; documented in the gate).
     *
     * @throws ProductSaveFailed with a constant message (the id of a product it could not remove, or mayHaveLeftover)
     * @throws \WC_Data_Exception only when it is certain no post was inserted (WooCommerce validates before the insert), so its own text can be shown
     */
    public function create( array $values ): int
    {
        $p           = null;
        $seen        = 0;
        $saveStarted = false;
        $name        = (string) $values['name'];
        $listener    = static function ( $new, $old, $post ) use ( &$seen, $name ): void {
            if ( 0 === $seen && 'draft' === $new && InsertedProduct::matches( $post, $name ) ) {
                $seen = (int) $post->ID;
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
            $inserted = InsertedProduct::candidate( null !== $p ? (int) $p->get_id() : 0, $seen );
            if ( $inserted > 0 ) {
                if ( ! $this->stillOurs( $inserted, $name ) ) {
                    throw new ProductSaveFailed( 'WooCommerce could not save the product', null, true );
                }
                throw new ProductSaveFailed( 'WooCommerce could not save the product', $this->removeQuietly( $inserted ) ? null : $inserted );
            }
            if ( is_a( $e, 'WC_Data_Exception' ) ) {
                throw $e; // WooCommerce's own validation, which runs before anything is inserted
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

    public function delete( int $id ): bool
    {
        return (bool) wp_delete_post( $id, true );
    }

    public function editUrl( int $id ): string
    {
        return (string) admin_url( 'post.php?post=' . $id . '&action=edit' );
    }
}
