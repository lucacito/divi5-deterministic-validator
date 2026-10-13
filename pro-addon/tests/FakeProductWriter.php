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
    /** When set, the failing create() call (see failOnCreate) throws what this returns instead of the default \Exception. */
    public ?\Closure $failException = null;
    /** @var list<int> */
    public array $deleted = [];
    public bool $deleteFails = false;
    public int $createCalls = 0;
    /** Store the name and texts as core does for a user without unfiltered_html: a bare "&" becomes "&amp;". */
    public bool $filteredTitle = false;
    /** @var list<array<string,mixed>> */
    public array $created = [];

    // ---- update_products ---------------------------------------------------------------------------------------------------------
    public int $applyCalls = 0;
    /** @var list<int> the apply() calls (1-based, the restores of a rollback included) that throw */
    public array $failApplyCalls = [];
    /** When set, a failing apply() throws what this returns instead of the default \Exception. */
    public ?\Closure $failApplyException = null;
    /** @var list<array{int,array<string,mixed>}> every successful apply(): [id, changes] */
    public array $applied = [];
    /** Runs after a successful apply(): fn(int $id, array &$stored, int $call): void, to simulate a store that saved something else. */
    public ?\Closure $afterApply = null;
    /** @var array<int,string> id => type, to make a stored product 'grouped', 'subscription' and so on (or to give an id with no stored fields a type) */
    public array $types = [];

    public function isActive(): bool { return $this->active; }
    public function skuTaken( string $sku ): bool { return isset( $this->skus[ $sku ] ); }

    public function skuOwner( string $sku ): int
    {
        foreach ( $this->products as $id => $p ) {
            if ( '' !== (string) ( $p['sku'] ?? '' ) && strtolower( (string) $p['sku'] ) === strtolower( $sku ) ) {
                return (int) $id;
            }
        }

        return isset( $this->skus[ $sku ] ) ? 1 : 0;
    }

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
            throw null !== $this->failException ? ( $this->failException )() : new \Exception( 'boom: could not write /var/www/html/wp-content/secret.php' );
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
        if ( $this->filteredTitle ) {
            foreach ( [ 'name', 'description', 'short_description' ] as $f ) {
                $snap[ $f ] = (string) preg_replace( '/&(?!#?\w+;)/', '&amp;', (string) $snap[ $f ] );
            }
        }
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

    public function typeOf( int $id ): ?string
    {
        $post = $GLOBALS['__wp_posts'][ $id ] ?? null;
        if ( is_object( $post ) && in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
            return null;
        }
        if ( isset( $this->products[ $id ] ) || isset( $this->types[ $id ] ) ) {
            return $this->types[ $id ] ?? (string) ( $this->products[ $id ]['type'] ?? 'simple' );
        }

        return null;
    }

    public function product( int $id ): ?array
    {
        $type = $this->typeOf( $id );
        if ( ! in_array( $type, [ 'simple', 'external', 'variable' ], true ) || ! isset( $this->products[ $id ] ) ) {
            return null;
        }
        $s = $this->products[ $id ] + [ 'sale_from' => '', 'sale_to' => '', 'variations' => [], 'category_ids' => [], 'tag_ids' => [] ];
        $s['type'] = $type;
        if ( isset( $GLOBALS['__wp_posts'][ $id ] ) ) {
            $s['status'] = $GLOBALS['__wp_posts'][ $id ]->post_status;
        }
        $s['category_slugs'] = $this->slugsFor( 'product_cat', $s['category_ids'] );
        $s['tag_slugs']      = $this->slugsFor( 'product_tag', $s['tag_ids'] );
        ksort( $s['variations'] );

        return $s;
    }

    public function apply( int $id, array $changes ): void
    {
        ++$this->applyCalls;
        if ( in_array( $this->applyCalls, $this->failApplyCalls, true ) ) {
            throw null !== $this->failApplyException ? ( $this->failApplyException )() : new \Exception( 'boom: could not write /var/www/html/wp-content/secret.php' );
        }
        if ( ! isset( $this->products[ $id ] ) ) {
            throw new \Exception( 'no such product' );
        }
        $rows = (array) ( $changes['variations'] ?? [] );
        unset( $changes['variations'] );
        $stored = $this->products[ $id ];
        foreach ( $rows as $vid => $row ) {
            if ( ! isset( $stored['variations'][ $vid ] ) ) {
                throw new \Exception( 'no such variation' );
            }
            $stored['variations'][ $vid ] = $this->merge( $stored['variations'][ $vid ], $row );
        }
        $stored = $this->merge( $stored, $changes );
        if ( $this->filteredTitle ) {
            foreach ( [ 'name', 'description', 'short_description' ] as $f ) {
                if ( array_key_exists( $f, $changes ) ) {
                    $stored[ $f ] = (string) preg_replace( '/&(?!#?\w+;)/', '&amp;', (string) $stored[ $f ] );
                }
            }
        }
        $this->products[ $id ] = $stored;
        $this->applied[]       = [ $id, $changes + ( [] === $rows ? [] : [ 'variations' => $rows ] ) ];
        if ( null !== $this->afterApply ) {
            ( $this->afterApply )( $id, $this->products[ $id ], $this->applyCalls );
        }
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

    /** @param list<int> $ids @return list<string> */
    private function slugsFor( string $taxonomy, array $ids ): array
    {
        $out = [];
        foreach ( $ids as $id ) {
            $slug = array_search( $id, $this->terms[ $taxonomy ] ?? [], true );
            $out[] = false === $slug ? 'term-' . $id : (string) $slug;
        }

        return $out;
    }

    /**
     * Like WooCommerce on save: an unmanaged product has no quantity and no backorders; a managed one works its stock status out
     * (a stock_status that comes with managed stock is ignored); term id lists are kept sorted.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function merge( array $state, array $c ): array
    {
        foreach ( $c as $k => $v ) {
            $state[ $k ] = $v;
        }
        foreach ( [ 'category_ids', 'tag_ids' ] as $k ) {
            if ( isset( $state[ $k ] ) ) {
                $state[ $k ] = $this->sorted( $state[ $k ] );
            }
        }
        if ( array_key_exists( 'manage_stock', $state ) ) {
            if ( $state['manage_stock'] ) {
                $qty = (int) ( $state['stock_quantity'] ?? 0 );
                $state['stock_status'] = $qty > 0 ? 'instock' : ( 'no' === ( $state['backorders'] ?? 'no' ) ? 'outofstock' : 'onbackorder' );
            } else {
                $state['stock_quantity'] = null;
                $state['backorders']     = 'no';
            }
        }

        return $state;
    }
}
