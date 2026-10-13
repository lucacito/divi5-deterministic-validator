<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Products\ProductWriter;

/** A scripted store: no WooCommerce at all. Post status is read from $GLOBALS['__wp_posts'] when the post is there (so wp_trash_post shows). */
final class FakeProductWriter implements ProductWriter
{
    public bool $active = true;
    /** @var array<string,bool> */
    public array $skus = [];
    /** @var array<string,array<string,int>> */
    public array $terms = [ 'product_cat' => [ 'shirts' => 3, 'hats' => 4 ], 'product_tag' => [ 'sale' => 7 ] ];
    /** @var array<int,bool> */
    public array $images = [ 90 => true, 91 => true ];
    /** @var array<int,array<string,mixed>> */
    public array $products = [];
    public int $next = 100;
    public ?int $failOnCreate = null;
    public ?\Closure $afterCreate = null;
    /** @var list<int> */
    public array $deleted = [];
    public bool $deleteFails = false;
    public int $createCalls = 0;
    /** @var list<array<string,mixed>> */
    public array $created = [];

    public function isActive(): bool { return $this->active; }
    public function skuTaken( string $sku ): bool { return isset( $this->skus[ $sku ] ); }

    public function termIds( string $taxonomy, array $slugs ): array
    {
        $ids = [];
        $missing = [];
        foreach ( $slugs as $slug ) {
            if ( isset( $this->terms[ $taxonomy ][ $slug ] ) ) {
                $ids[] = $this->terms[ $taxonomy ][ $slug ];
            } else {
                $missing[] = $slug;
            }
        }

        return [ 'ids' => $ids, 'missing' => $missing ];
    }

    public function imageUsable( int $id ): bool { return $this->images[ $id ] ?? false; }

    public function create( array $values ): int
    {
        ++$this->createCalls;
        if ( $this->failOnCreate === $this->createCalls ) {
            throw new \Exception( 'boom: could not write /var/www/html/wp-content/secret.php' );
        }
        $id   = $this->next++;
        $snap = [
            'status' => 'draft', 'name' => $values['name'], 'description' => $values['description'], 'short_description' => $values['short_description'],
            'sku' => $values['sku'], 'regular_price' => $values['regular_price'], 'sale_price' => $values['sale_price'],
            'manage_stock' => $values['manage_stock'], 'stock_quantity' => $values['stock_quantity'], 'stock_status' => $values['manage_stock'] ? ( ( $values['stock_quantity'] ?? 0 ) > 0 ? 'instock' : 'outofstock' ) : $values['stock_status'],
            'backorders' => $values['backorders'], 'category_ids' => $this->sorted( $values['category_ids'] ), 'tag_ids' => $this->sorted( $values['tag_ids'] ),
            'image_id' => $values['image_id'], 'gallery_image_ids' => $values['gallery_image_ids'], 'weight' => $values['weight'],
            'length' => $values['length'], 'width' => $values['width'], 'height' => $values['height'], 'tax_status' => $values['tax_status'],
            'virtual' => $values['virtual'], 'sold_individually' => $values['sold_individually'],
        ];
        if ( null !== $this->afterCreate ) {
            ( $this->afterCreate )( $snap, $this->createCalls );
        }
        $this->products[ $id ] = $snap;
        $this->created[]       = $values;

        return $id;
    }

    public function snapshot( int $id ): ?array
    {
        $s = $this->products[ $id ] ?? null;
        if ( null === $s ) {
            return null;
        }
        if ( isset( $GLOBALS['__wp_posts'][ $id ] ) ) {
            $s['status'] = $GLOBALS['__wp_posts'][ $id ]->post_status;
        }

        return $s;
    }

    public function delete( int $id ): bool
    {
        if ( $this->deleteFails ) {
            return false;
        }
        $this->deleted[] = $id;
        unset( $this->products[ $id ] );

        return true;
    }

    public function editUrl( int $id ): string { return 'EDIT-' . $id; }

    /** @param list<int> $ids @return list<int> */
    private function sorted( array $ids ): array
    {
        sort( $ids );

        return array_values( $ids );
    }
}
