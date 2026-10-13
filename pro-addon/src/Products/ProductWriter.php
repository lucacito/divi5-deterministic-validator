<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** What create_product and the product batch kinds need from a store. The real one is WooProductWriter; tests use a fake. */
interface ProductWriter
{
    public function isActive(): bool;

    public function skuTaken( string $sku ): bool;

    /**
     * @param list<string> $slugs
     * @return array{ids: list<int>, missing: list<string>}
     */
    public function termIds( string $taxonomy, array $slugs ): array;

    /** An existing Media Library image the current user may use. */
    public function imageUsable( int $id ): bool;

    /**
     * Create ONE simple product as a DRAFT and return its id; throw on any failure.
     *
     * @param array<string,mixed> $values the ProductValuePolicy values plus `category_ids` and `tag_ids` (lists of int)
     */
    public function create( array $values ): int;

    /**
     * @return ?array<string,mixed> null unless the id is a simple product; otherwise `status` (the post status) plus name, description,
     *         short_description, sku, regular_price, sale_price, manage_stock, stock_quantity, stock_status, backorders, category_ids (sorted),
     *         tag_ids (sorted), image_id, gallery_image_ids, weight, length, width, height, tax_status, virtual, sold_individually
     */
    public function snapshot( int $id ): ?array;

    /** Permanently delete (used only to roll back a product this same call just created). */
    public function delete( int $id ): bool;

    public function editUrl( int $id ): string;
}
