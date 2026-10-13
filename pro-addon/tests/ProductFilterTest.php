<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Products\ProductFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/** The arguments of bulk_adjust_prices: validated, normalised, every reason at once, nothing cast. */
class ProductFilterTest extends TestCase
{
    /** @return array<string,mixed> */
    private function ok( array $args ): array
    {
        $r = ProductFilter::parse( $args );
        $this->assertSame( [], $r['reasons'], json_encode( $r['reasons'] ) );
        $this->assertIsArray( $r['spec'] );

        return $r['spec'];
    }

    private function refused( array $args, string $needle ): void
    {
        $r = ProductFilter::parse( $args );
        $this->assertNull( $r['spec'] );
        $this->assertNotSame( [], $r['reasons'] );
        $this->assertStringContainsString( $needle, implode( ' | ', $r['reasons'] ) );
    }

    private function change( string $mode = 'percent', string $value = '10' ): array
    {
        return [ 'mode' => $mode, 'value' => $value ];
    }

    public function testAMinimalRequestIsNormalisedWithDefaults(): void
    {
        $spec = $this->ok( [ 'filter' => [ 'categories' => [ 'shirts' ] ], 'change' => $this->change() ] );
        $this->assertSame( [ 'shirts' ], $spec['filter']['categories'] );
        $this->assertSame( [ 'publish' ], $spec['filter']['status'], 'only published products unless asked' );
        $this->assertSame( [], $spec['filter']['types'] );
        $this->assertNull( $spec['filter']['stock_status'] );
        $this->assertFalse( $spec['filter']['all'] );
        $this->assertSame( [ 'mode' => 'percent', 'value' => '10' ], $spec['change'] );
        $this->assertNull( $spec['round_to'] );
    }

    public function testTheSameRequestWrittenAnotherWayNormalisesAlike(): void
    {
        $a = $this->ok( [ 'filter' => [ 'categories' => [ 'b', 'a', 'a' ], 'ids' => [ 9, 3 ], 'status' => [ 'private', 'publish' ] ], 'change' => $this->change( 'percent', '10.0' ), 'round_to' => '0.50' ] );
        $b = $this->ok( [ 'round_to' => '0.5', 'change' => $this->change( 'percent', '010' ), 'filter' => [ 'status' => [ 'publish', 'private' ], 'ids' => [ 3, 9 ], 'categories' => [ 'a', 'b' ] ] ] );
        $this->assertEquals( $a, $b );
        $this->assertSame( [ 'a', 'b' ], $a['filter']['categories'] );
        $this->assertSame( [ 3, 9 ], $a['filter']['ids'] );
        $this->assertSame( '10', $a['change']['value'] );
        $this->assertSame( '0.5', $a['round_to'] );
    }

    public function testEveryNarrowingKey(): void
    {
        $spec = $this->ok( [ 'filter' => [
            'categories' => [ 'c1' ], 'tags' => [ 't1' ], 'types' => [ 'variable', 'simple' ], 'stock_status' => 'instock', 'on_sale' => false,
            'search' => '  hat ', 'ids' => [ 5 ], 'exclude_ids' => [ 6 ], 'status' => [ 'draft' ],
        ], 'change' => $this->change( 'amount', '-5' ) ] );
        $f = $spec['filter'];
        $this->assertSame( [ [ 'simple', 'variable' ], 'instock', false, 'hat', [ 5 ], [ 6 ], [ 'draft' ] ], [ $f['types'], $f['stock_status'], $f['on_sale'], $f['search'], $f['ids'], $f['exclude_ids'], $f['status'] ] );
    }

    public function testAnyOneNarrowingKeyIsEnoughButStatusAndExcludeAloneAreNot(): void
    {
        foreach ( [ [ 'tags' => [ 't' ] ], [ 'types' => [ 'simple' ] ], [ 'stock_status' => 'outofstock' ], [ 'on_sale' => true ], [ 'on_sale' => false ], [ 'search' => 'x' ], [ 'ids' => [ 1 ] ] ] as $filter ) {
            $this->ok( [ 'filter' => $filter, 'change' => $this->change() ] );
        }
        $this->refused( [ 'filter' => [ 'status' => [ 'draft' ] ], 'change' => $this->change() ], 'must narrow the products' );
        $this->refused( [ 'filter' => [ 'exclude_ids' => [ 1 ] ], 'change' => $this->change() ], 'must narrow the products' );
        $this->refused( [ 'filter' => [], 'change' => $this->change() ], '"filter" is required' );
    }

    public function testAllMustBeAskedForAndStandsAlone(): void
    {
        $spec = $this->ok( [ 'filter' => [ 'all' => true ], 'change' => $this->change() ] );
        $this->assertTrue( $spec['filter']['all'] );
        $this->ok( [ 'filter' => [ 'all' => true, 'exclude_ids' => [ 4 ], 'status' => [ 'publish', 'private' ] ], 'change' => $this->change() ] );
        $this->refused( [ 'filter' => [ 'all' => true, 'categories' => [ 'x' ] ], 'change' => $this->change() ], 'cannot be combined' );
        $this->refused( [ 'filter' => [ 'all' => false ], 'change' => $this->change() ], 'must narrow the products' );
        $this->refused( [ 'filter' => [ 'all' => 'yes' ], 'change' => $this->change() ], '"filter.all" must be true or false' );
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function badFilters(): array
    {
        return [
            'not an object'       => [ [ 'filter' => 'shirts' ], '"filter" is required and must be an object' ],
            'a list'              => [ [ 'filter' => [ 'shirts' ] ], '"filter" is required and must be an object' ],
            'unknown key'         => [ [ 'filter' => [ 'colour' => 'red' ] ], '"filter.colour" is not a supported filter' ],
            'category string'     => [ [ 'filter' => [ 'categories' => 'shirts' ] ], '"filter.categories" must be a list of 1 to 10' ],
            'empty list'          => [ [ 'filter' => [ 'categories' => [] ] ], '"filter.categories" must be a list of 1 to 10' ],
            'too many categories' => [ [ 'filter' => [ 'categories' => [ 'a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8', 'a9', 'a10', 'a11' ] ] ], 'a list of 1 to 10' ],
            'bad slug'            => [ [ 'filter' => [ 'tags' => [ 'a b' ] ] ], 'only slugs' ],
            'slug number'         => [ [ 'filter' => [ 'tags' => [ 5 ] ] ], 'only slugs' ],
            'bad type'            => [ [ 'filter' => [ 'types' => [ 'grouped' ] ] ], '"filter.types" must be a list of: simple, variable, external' ],
            'bad stock'           => [ [ 'filter' => [ 'stock_status' => 'sold' ] ], '"filter.stock_status" must be one of' ],
            'on_sale string'      => [ [ 'filter' => [ 'on_sale' => 'true' ] ], '"filter.on_sale" must be true or false' ],
            'blank search'        => [ [ 'filter' => [ 'search' => '   ' ] ], '"filter.search" must be a non-empty string' ],
            'long search'         => [ [ 'filter' => [ 'search' => str_repeat( 'x', 101 ) ] ], 'at most 100 characters' ],
            'id as string'        => [ [ 'filter' => [ 'ids' => [ '5' ] ] ], 'whole product ids from 1' ],
            'id zero'             => [ [ 'filter' => [ 'ids' => [ 0 ] ] ], 'whole product ids from 1' ],
            'too many ids'        => [ [ 'filter' => [ 'ids' => range( 1, 101 ) ] ], '1 to 100 product ids' ],
            'bad status'          => [ [ 'filter' => [ 'categories' => [ 'a' ], 'status' => [ 'trash' ] ] ], '"filter.status" must be a list of: publish, private, draft, pending' ],
        ];
    }

    #[DataProvider( 'badFilters' )]
    public function testBadFiltersAreRefusedByName( array $args, string $needle ): void
    {
        $this->refused( $args + [ 'change' => $this->change() ], $needle );
    }

    /** @return array<string,array{mixed,string}> */
    public static function badChanges(): array
    {
        return [
            'missing'           => [ null, '"change" is required' ],
            'not an object'     => [ '10', '"change" is required' ],
            'unknown key'       => [ [ 'mode' => 'percent', 'value' => '10', 'round' => 1 ], '"change.round" is not supported' ],
            'bad mode'          => [ [ 'mode' => 'multiply', 'value' => '2' ], '"change.mode" must be "percent" or "amount"' ],
            'number'            => [ [ 'mode' => 'percent', 'value' => 10 ], 'not a number' ],
            'percent sign'      => [ [ 'mode' => 'percent', 'value' => '10%' ], 'no % sign' ],
            'three decimals'    => [ [ 'mode' => 'percent', 'value' => '1.255' ], 'at most 2 decimals' ],
            'zero'              => [ [ 'mode' => 'percent', 'value' => '0' ], 'must not be zero' ],
            'zero amount'       => [ [ 'mode' => 'amount', 'value' => '0.00' ], 'must not be zero' ],
            'percent too low'   => [ [ 'mode' => 'percent', 'value' => '-90.01' ], 'from -90 to 500' ],
            'percent too high'  => [ [ 'mode' => 'percent', 'value' => '500.01' ], 'from -90 to 500' ],
            'amount currency'   => [ [ 'mode' => 'amount', 'value' => '$5' ], 'no currency symbol' ],
            'amount thousands'  => [ [ 'mode' => 'amount', 'value' => '1,000' ], 'no currency symbol' ],
            'amount too big'    => [ [ 'mode' => 'amount', 'value' => '100000.5' ], 'at most 100000 either way' ],
            'amount plus sign'  => [ [ 'mode' => 'amount', 'value' => '+5' ], 'no currency symbol' ],
        ];
    }

    #[DataProvider( 'badChanges' )]
    public function testBadChangesAreRefused( mixed $change, string $needle ): void
    {
        $args = [ 'filter' => [ 'categories' => [ 'a' ] ] ];
        if ( null !== $change ) {
            $args['change'] = $change;
        }
        $this->refused( $args, $needle );
    }

    public function testTheLimitsOfChangeAreAccepted(): void
    {
        foreach ( [ [ 'percent', '-90' ], [ 'percent', '500' ], [ 'percent', '0.01' ], [ 'percent', '-12.5' ], [ 'amount', '100000' ], [ 'amount', '-100000' ], [ 'amount', '0.000001' ], [ 'amount', '2.50' ] ] as [ $mode, $value ] ) {
            $spec = $this->ok( [ 'filter' => [ 'ids' => [ 1 ] ], 'change' => $this->change( $mode, $value ) ] );
            $this->assertSame( $mode, $spec['change']['mode'] );
        }
        $this->assertSame( '2.5', $this->ok( [ 'filter' => [ 'ids' => [ 1 ] ], 'change' => $this->change( 'amount', '2.50' ) ] )['change']['value'] );
    }

    public function testRoundTo(): void
    {
        foreach ( [ '0.99' => '0.99', '0.95' => '0.95', '0.5' => '0.5', '0.50' => '0.5', '0' => '0', '1' => '0', '0.00' => '0' ] as $in => $out ) {
            $this->assertSame( $out, $this->ok( [ 'filter' => [ 'ids' => [ 1 ] ], 'change' => $this->change(), 'round_to' => (string) $in ] )['round_to'], (string) $in );
        }
        foreach ( [ 0.99, 99, '1.5', '2', '.99', '0.999', '-0.5', '' ] as $bad ) {
            $this->refused( [ 'filter' => [ 'ids' => [ 1 ] ], 'change' => $this->change(), 'round_to' => $bad ], '"round_to" must be a string' );
        }
    }

    public function testEveryReasonIsReportedAtOnce(): void
    {
        $r = ProductFilter::parse( [ 'filter' => [ 'colour' => 1 ], 'change' => [ 'mode' => 'percent', 'value' => 'ten' ], 'round_to' => 5 ] );
        $this->assertNull( $r['spec'] );
        $this->assertGreaterThanOrEqual( 3, count( $r['reasons'] ) );
    }
}
