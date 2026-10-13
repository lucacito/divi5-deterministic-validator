<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** What ProductService needs from a store. The real implementation is WooProductGateway; tests use a fake. */
interface ProductGateway
{
    /** WooCommerce has no on-sale filter, so on_sale is decided in PHP over at most this many matches. */
    public const MAX_SCAN = 500;

    public function isActive(): bool;

    /** @return array{version: string, currency: string} */
    public function info(): array;

    /** True only for a post of the exact type `product` (a variation or any other id is false). */
    public function isProduct( int $id ): bool;

    /**
     * @param array<string,mixed> $args the parsed ProductQuery args
     * @return array{ids: list<int>, total: int, truncated: bool} the ids of the requested page, the number of ALL matches
     */
    public function query( array $args ): array;

    /** @return array<string,int> post status => number of products */
    public function statusCounts(): array;

    /** @return array<string,int> product type => number of PUBLISHED products */
    public function publishedTypeCounts(): array;

    /** @return ?array<string,mixed> the raw list-row array for ProductReader::row(), or null when there is no such product */
    public function row( int $id ): ?array;

    /** @return ?array<string,mixed> the raw array for ProductReader::detail(), or null when there is no such product */
    public function detail( int $id ): ?array;
}
