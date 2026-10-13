<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ProductGateway;
use AiEditorDivi5\WP\ProductService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ProductReader.php';
require_once __DIR__ . '/../wp-plugin/src/ProductQuery.php';
require_once __DIR__ . '/../wp-plugin/src/ProductGateway.php';
require_once __DIR__ . '/../wp-plugin/src/ProductService.php';

/** A scripted store: no WooCommerce at all. */
final class FakeGateway implements ProductGateway
{
    public bool $active = true;
    public bool $throw  = false;
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public bool $truncated = false;
    /** @var list<array<string,mixed>> */
    public array $queries = [];

    public function isActive(): bool { return $this->active; }
    public function info(): array { return [ 'version' => '9.9.9', 'currency' => 'EUR' ]; }
    public function isProduct( int $id ): bool { return isset( $this->rows[ $id ] ) && 'product' === ( $this->rows[ $id ]['post_type'] ?? 'product' ) && 'trash' !== ( $this->rows[ $id ]['status'] ?? '' ); }
    public function query( array $args ): array
    {
        $this->guard();
        $this->queries[] = $args;
        $ids = array_keys( array_filter( $this->rows, fn ( $r ) => 'product' === ( $r['post_type'] ?? 'product' ) ) );

        return [ 'ids' => array_slice( $ids, ( $args['page'] - 1 ) * $args['per_page'], $args['per_page'] ), 'total' => count( $ids ), 'truncated' => $this->truncated ];
    }
    public function statusCounts(): array { $this->guard(); return [ 'publish' => 3, 'draft' => 1, 'pending' => 0, 'private' => 0, 'future' => 0 ]; }
    public function publishedTypeCounts(): array { return [ 'simple' => 3 ]; }
    public function row( int $id ): ?array { $this->guard(); return $this->rows[ $id ] ?? null; }
    public function detail( int $id ): ?array { $this->guard(); return $this->rows[ $id ] ?? null; }
    private function guard(): void { if ( $this->throw ) { throw new \RuntimeException( 'internal /var/www/secret path' ); } }
}

class ProductServiceTest extends TestCase
{
    private FakeGateway $gw;

    protected function setUp(): void
    {
        $this->gw = new FakeGateway();
        foreach ( [ 1, 2, 3, 4 ] as $id ) {
            $this->gw->rows[ $id ] = [ 'id' => $id, 'name' => "P{$id}", 'type' => 'simple', 'status' => 'publish', 'price' => '5', 'url' => "https://s.test/{$id}" ];
        }
        $this->gw->rows[9] = [ 'id' => 9, 'name' => 'Variation', 'type' => 'variation', 'post_type' => 'product_variation' ];
    }

    private function can( array $deny = [] ): callable
    {
        return static function ( string $cap, ...$args ) use ( $deny ): bool {
            return ! in_array( $cap . ( isset( $args[0] ) ? ':' . $args[0] : '' ), $deny, true ) && ! in_array( $cap, $deny, true );
        };
    }

    public function testATrashedProductAnswersNotFoundLikeAMissingOne(): void
    {
        $this->gw->rows[5] = [ 'id' => 5, 'name' => 'Binned', 'type' => 'simple', 'status' => 'trash' ];
        $trashed = ProductService::get( 5, $this->gw, $this->can() );
        $missing = ProductService::get( 999, $this->gw, $this->can() );
        $this->assertFalse( $trashed['ok'] );
        $this->assertSame( 'not_found', $trashed['kind'] );
        $this->assertSame( 'Product 5 not found.', $trashed['message'] );
        $this->assertSame( str_replace( '999', '5', $missing['message'] ), $trashed['message'] );
    }

    public function testListReturnsTotalsRowsAndPaging(): void
    {
        $r = ProductService::list( [ 'per_page' => 3 ], false, $this->gw, $this->can() );
        $this->assertTrue( $r['ok'] );
        $d = $r['data'];
        $this->assertSame( [ 'active' => true, 'version' => '9.9.9', 'currency' => 'EUR' ], $d['woocommerce'] );
        $this->assertSame( 4, $d['totals']['all'] );
        $this->assertCount( 3, $d['products'] );
        $this->assertSame( 'P1', $d['products'][0]['name'] );
        $this->assertSame( [ 1, 3, 4, 2 ], [ $d['page'], $d['per_page'], $d['total_matching'], $d['total_pages'] ] );
        $this->assertArrayNotHasKey( 'notes', $d );
    }

    public function testAPageBeyondTheLastIsANormalEmptyListWithTheTotals(): void
    {
        $r = ProductService::list( [ 'page' => 9 ], false, $this->gw, $this->can() );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [], $r['data']['products'] );
        $this->assertSame( 4, $r['data']['total_matching'] );
        $this->assertSame( 4, $r['data']['totals']['all'] );
    }

    public function testNoMatchesGivesZeroPages(): void
    {
        $this->gw->rows = [];
        $r = ProductService::list( [], false, $this->gw, $this->can() );
        $this->assertSame( 0, $r['data']['total_matching'] );
        $this->assertSame( 0, $r['data']['total_pages'] );
    }

    public function testBadArgumentsAreInvalid(): void
    {
        $r = ProductService::list( [ 'per_page' => 51 ], false, $this->gw, $this->can() );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'invalid', $r['kind'] );
        $this->assertSame( [], $this->gw->queries, 'nothing is queried for bad input' );
    }

    public function testAUserWithoutTheProductPermissionsIsForbiddenAndToldWhich(): void
    {
        foreach ( [ 'edit_products', 'edit_others_products' ] as $missing ) {
            $r = ProductService::list( [], false, $this->gw, $this->can( [ $missing ] ) );
            $this->assertFalse( $r['ok'] );
            $this->assertSame( 'forbidden', $r['kind'] );
            $this->assertStringContainsString( 'edit_products', $r['message'] );
            $this->assertStringContainsString( 'edit_others_products', $r['message'] );
        }
    }

    public function testRowsTheUserCannotEditAreLeftOut(): void
    {
        $r = ProductService::list( [], false, $this->gw, $this->can( [ 'edit_post:2' ] ) );
        $this->assertSame( [ 1, 3, 4 ], array_column( $r['data']['products'], 'id' ) );
    }

    public function testAnInactiveStoreIsANormalAnswerNotAnError(): void
    {
        $this->gw->active = false;
        $r = ProductService::list( [], false, $this->gw, $this->can() );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [ 'active' => false ], $r['data']['woocommerce'] );
        $this->assertSame( [], $r['data']['products'] );
        $this->assertSame( ProductService::MSG_INACTIVE, $r['data']['message'] );
        $g = ProductService::get( 1, $this->gw, $this->can() );
        $this->assertTrue( $g['ok'] );
        $this->assertNull( $g['data']['product'] );
        $this->assertSame( [ 'active' => false ], $g['data']['woocommerce'] );
    }

    public function testAThrowingStoreAnswersCleanlyWithoutLeakingTheMessage(): void
    {
        $this->gw->throw = true;
        foreach ( [ ProductService::list( [], false, $this->gw, $this->can() ), ProductService::get( 1, $this->gw, $this->can() ) ] as $r ) {
            $this->assertFalse( $r['ok'] );
            $this->assertSame( 'server', $r['kind'] );
            $this->assertSame( 'WooCommerce could not be read.', $r['message'] );
            $this->assertStringNotContainsString( '/var/www', $r['message'] );
        }
    }

    public function testATruncatedOnSaleScanSaysSo(): void
    {
        $this->gw->truncated = true;
        $r = ProductService::list( [ 'on_sale' => true ], false, $this->gw, $this->can() );
        $this->assertStringContainsString( '500', $r['data']['notes'][0] );
    }

    public function testGetReturnsTheDetail(): void
    {
        $r = ProductService::get( 2, $this->gw, $this->can() );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'P2', $r['data']['product']['name'] );
        $this->assertSame( 'EUR', $r['data']['woocommerce']['currency'] );
        $this->assertArrayHasKey( 'description', $r['data']['product'] );
    }

    public function testMissingVariationAndNotEditableAllAnswerTheSameNotFound(): void
    {
        $messages = [];
        foreach ( [ [ 999, [] ], [ 9, [] ], [ 2, [ 'edit_post:2' ] ], [ 0, [] ], [ -4, [] ] ] as [ $id, $deny ] ) {
            $r = ProductService::get( $id, $this->gw, $this->can( $deny ) );
            $this->assertFalse( $r['ok'], "id {$id}" );
            $this->assertSame( 'not_found', $r['kind'], "id {$id}" );
            $messages[] = str_replace( (string) $id, 'N', $r['message'] );
        }
        $this->assertCount( 1, array_unique( $messages ) );
        $this->assertSame( 'Product 999 not found.', ProductService::get( 999, $this->gw, $this->can() )['message'] );
    }
}
