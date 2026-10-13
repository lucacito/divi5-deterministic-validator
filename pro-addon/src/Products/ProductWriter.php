<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** What create_product, update_products and the product batch kinds need from a store. The real one is WooProductWriter; tests use a fake. */
interface ProductWriter
{
    public function isActive(): bool;

    public function skuTaken( string $sku ): bool;

    /** The id of the product or variation that owns this SKU, 0 when no one does (update_products allows a product to keep its own SKU). */
    public function skuOwner( string $sku ): int;

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

    /** The WooCommerce type ('simple', 'variable', 'grouped', ...) of a product post that is not in the trash; null for anything else (missing, trashed, a variation, another post type). */
    public function typeOf( int $id ): ?string;

    /**
     * The whole editable state of a simple, external or variable product (null for any other id, grouped products included):
     * type, status, name, description, short_description, sku, regular_price, sale_price, sale_from, sale_to (YYYY-MM-DD or ''),
     * manage_stock, stock_quantity (null unless managed), stock_status, backorders, low_stock_amount ('' or int), category_ids and tag_ids (sorted), category_slugs and
     * tag_slugs (display only, same order), image_id, gallery_image_ids, weight, length, width, height, tax_status, and `variations`:
     * variation id => row (regular_price, sale_price, sale_from, sale_to, manage_stock, stock_quantity, stock_status, backorders, low_stock_amount), sorted by id,
     * empty unless the product is variable.
     *
     * @return ?array<string,mixed>
     */
    public function product( int $id ): ?array;

    /**
     * Writes ONLY the given fields of an existing product through WooCommerce's CRUD and throws on any failure. Never touches status, type or
     * slug. Keys: the scalar fields of product(), `category_ids` and `tag_ids` (lists of int), and `variations` (variation id => the row
     * fields to write).
     *
     * @param array<string,mixed> $changes
     */
    public function apply( int $id, array $changes ): void;

    /**
     * The ids of the simple, variable and external products (never grouped, never variations, never the trash) that match the criteria of
     * a normalised bulk_adjust_prices filter (see ProductFilter): `status` (list), `types` (list, empty = all three), `categories` and
     * `tags` (slugs; a category also matches its children), `stock_status`, `on_sale` (decided in PHP: WooCommerce ignores that
     * query argument), `search` (words in the name), `ids`, `exclude_ids`. Oldest id first. At most `$limit` ids are returned;
     * `truncated` is true when there were more, or when too many candidates had to be scanned to decide `on_sale`.
     *
     * @param array<string,mixed> $criteria
     * @return array{ids: list<int>, truncated: bool}
     */
    public function findProducts( array $criteria, int $limit ): array;

    /** The number of decimals the shop shows prices with (WooCommerce's own setting). */
    public function priceDecimals(): int;

    /** Permanently delete (used only to roll back a product this same call just created). */
    public function delete( int $id ): bool;

    public function editUrl( int $id ): string;
}
