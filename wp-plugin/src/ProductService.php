<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * list_products and get_product, once: McpHandler and RestController are thin adapters that map the result to JSON-RPC or WP_Error.
 * It never touches WooCommerce itself (ProductGateway does) and never logs (the adapters do).
 *
 * Result: ['ok' => true, 'data' => array] or ['ok' => false, 'kind' => forbidden|invalid|not_found|server, 'message' => string].
 */
final class ProductService
{
    public const MSG_INACTIVE = 'WooCommerce is not active on this site.';
    public const MSG_SERVER   = 'WooCommerce could not be read.';

    /**
     * @param array<string,mixed> $input
     * @param callable(string, mixed...): bool $can current_user_can
     * @return array<string,mixed>
     */
    public static function list( array $input, bool $rest, ProductGateway $gw, callable $can ): array
    {
        if ( ! $can( 'edit_products' ) || ! $can( 'edit_others_products' ) ) {
            return self::fail( 'forbidden', 'Listing products needs the edit_products and edit_others_products permissions (shop managers and administrators have them).' );
        }
        $parsed = ProductQuery::parse( $input, $rest );
        if ( null === $parsed['args'] ) {
            return self::fail( 'invalid', (string) $parsed['error'] );
        }
        if ( ! $gw->isActive() ) {
            return self::ok( [ 'woocommerce' => [ 'active' => false ], 'products' => [], 'message' => self::MSG_INACTIVE ] );
        }

        try {
            $args  = $parsed['args'];
            $found = $gw->query( $args );
            $rows  = [];
            foreach ( $found['ids'] as $id ) {
                if ( ! $can( 'edit_post', $id ) ) {
                    continue;
                }
                $raw = $gw->row( (int) $id );
                if ( null !== $raw ) {
                    $rows[] = ProductReader::row( $raw );
                }
            }
            $perPage = (int) $args['per_page'];
            $total   = (int) $found['total'];
            $data    = [
                'woocommerce'    => [ 'active' => true ] + $gw->info(),
                'totals'         => ProductReader::totals( $gw->statusCounts(), $gw->publishedTypeCounts() ),
                'products'       => $rows,
                'page'           => (int) $args['page'],
                'per_page'       => $perPage,
                'total_matching' => $total,
                'total_pages'    => $total > 0 ? (int) ceil( $total / $perPage ) : 0,
            ];
            if ( ! empty( $found['truncated'] ) ) {
                $data['notes'] = [ 'The on_sale filter looked at the first ' . ProductGateway::MAX_SCAN . ' matching products only; narrow the search (category, type, search) to see the rest.' ];
            }

            return self::ok( $data );
        } catch ( \Throwable ) {
            return self::fail( 'server', self::MSG_SERVER );
        }
    }

    /**
     * @param callable(string, mixed...): bool $can current_user_can
     * @return array<string,mixed>
     */
    public static function get( int $id, ProductGateway $gw, callable $can ): array
    {
        if ( ! $gw->isActive() ) {
            return self::ok( [ 'woocommerce' => [ 'active' => false ], 'product' => null, 'message' => self::MSG_INACTIVE ] );
        }

        try {
            // A missing id, a variation, a post of another type and a product the user cannot edit all answer the same.
            if ( $id < 1 || ! $gw->isProduct( $id ) || ! $can( 'edit_post', $id ) ) {
                return self::fail( 'not_found', "Product {$id} not found." );
            }
            $raw = $gw->detail( $id );
            if ( null === $raw ) {
                return self::fail( 'not_found', "Product {$id} not found." );
            }

            return self::ok( [ 'woocommerce' => [ 'active' => true ] + $gw->info(), 'product' => ProductReader::detail( $raw ) ] );
        } catch ( \Throwable ) {
            return self::fail( 'server', self::MSG_SERVER );
        }
    }

    /** @param array<string,mixed> $data @return array{ok: true, data: array<string,mixed>} */
    private static function ok( array $data ): array
    {
        return [ 'ok' => true, 'data' => $data ];
    }

    /** @return array{ok: false, kind: string, message: string} */
    private static function fail( string $kind, string $message ): array
    {
        return [ 'ok' => false, 'kind' => $kind, 'message' => $message ];
    }
}
