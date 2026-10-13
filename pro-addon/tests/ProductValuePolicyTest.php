<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Products\ProductValuePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class ProductValuePolicyTest extends TestCase
{
    private \Closure $kses;
    private \Closure $strip;

    protected function setUp(): void
    {
        // A kses that drops <script> and <form> (<form> passes FieldValuePolicy, so it reaches the kses comparison) and rewrites a bare ampersand like core, and a strip-tags like core's.
        $this->kses  = static fn ( string $s ): string => (string) preg_replace( '/&(?!#?\w+;)/', '&amp;', (string) preg_replace( '#<\s*(?:script|form)\b.*?>.*?<\s*/\s*(?:script|form)\s*>#is', '', $s ) );
        $this->strip = static fn ( string $s ): string => strip_tags( $s );
    }

    /** @return array{values: ?array<string,mixed>, reasons: list<string>} */
    private function check( array $p ): array
    {
        return ProductValuePolicy::check( $p, $this->kses, $this->strip );
    }

    private function ok( array $p ): array
    {
        $r = $this->check( $p );
        $this->assertSame( [], $r['reasons'], json_encode( $r['reasons'] ) );
        $this->assertIsArray( $r['values'] );

        return $r['values'];
    }

    private function refusedWith( array $p, string $needle ): void
    {
        $r = $this->check( $p );
        $this->assertNull( $r['values'] );
        $this->assertStringContainsString( $needle, implode( ' | ', $r['reasons'] ) );
    }

    public function testAMinimalProductGetsDefaultsForEverythingElse(): void
    {
        $v = $this->ok( [ 'name' => 'Blue Hat' ] );
        $this->assertSame( [
            'name' => 'Blue Hat', 'description' => '', 'short_description' => '', 'sku' => '', 'regular_price' => '', 'sale_price' => '',
            'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock', 'backorders' => 'no',
            'category_slugs' => [], 'tag_slugs' => [], 'image_id' => 0, 'gallery_image_ids' => [],
            'weight' => '', 'length' => '', 'width' => '', 'height' => '', 'tax_status' => 'taxable', 'virtual' => false, 'sold_individually' => false,
        ], $v );
    }

    public function testAFullProductIsAccepted(): void
    {
        $v = $this->ok( [
            'name' => 'Blue Hat', 'description' => '<p>Warm &amp; soft</p>', 'short_description' => 'Warm', 'sku' => 'HAT-1',
            'regular_price' => '20.50', 'sale_price' => '15', 'manage_stock' => true, 'stock_quantity' => 12, 'backorders' => 'notify',
            'categories' => [ 'shirts' ], 'tags' => [ 'sale' ], 'image_id' => 90, 'gallery_image_ids' => [ 91, 92 ],
            'weight' => '0.4', 'dimensions' => [ 'length' => '10', 'width' => '5', 'height' => '2.5' ], 'tax_status' => 'none', 'virtual' => true, 'sold_individually' => true,
        ] );
        $this->assertSame( 12, $v['stock_quantity'] );
        $this->assertSame( 'notify', $v['backorders'] );
        $this->assertSame( [ 'shirts' ], $v['category_slugs'] );
        $this->assertSame( '2.5', $v['height'] );
        $this->assertSame( [ 91, 92 ], $v['gallery_image_ids'] );
    }

    public static function badProducts(): array
    {
        return [
            'no name'               => [ [], '"name" is required' ],
            'empty name'            => [ [ 'name' => '  ' ], '"name" is required' ],
            'name not a string'     => [ [ 'name' => 5 ], '"name"' ],
            'name with tags'        => [ [ 'name' => 'Hat <b>x</b>' ], '"name" must not contain < or >' ],
            'name with a lone <'    => [ [ 'name' => 'Size < 10 Hat' ], '"name" must not contain < or >' ],
            'name with a lone >'    => [ [ 'name' => 'Size > 10 Hat' ], '"name" must not contain < or >' ],
            'name padded left'      => [ [ 'name' => '  Hat' ], '"name" must not start or end with spaces' ],
            'name padded right'     => [ [ 'name' => 'Hat ' ], '"name" must not start or end with spaces' ],
            'name with a backslash' => [ [ 'name' => 'back\\slash Hat' ], '"name" must not contain a backslash' ],
            'description backslash' => [ [ 'name' => 'x', 'description' => 'C:\\dir' ], '"description" must not contain a backslash' ],
            'short backslash'       => [ [ 'name' => 'x', 'short_description' => 'a\\b' ], '"short_description" must not contain a backslash' ],
            'sku with a tag'        => [ [ 'name' => 'x', 'sku' => '<b>x</b>' ], '"sku" must not contain < or >' ],
            'sku with <'            => [ [ 'name' => 'x', 'sku' => 'A<1' ], '"sku" must not contain < or >' ],
            'sku with >'            => [ [ 'name' => 'x', 'sku' => 'A>1' ], '"sku" must not contain < or >' ],
            'name control char'     => [ [ 'name' => "Hat\x07" ], 'control characters' ],
            'name too long'         => [ [ 'name' => str_repeat( 'a', 201 ) ], '200 characters' ],
            'unknown key'           => [ [ 'name' => 'x', 'slug' => 'x' ], '"slug" is not a supported field' ],
            'status key'            => [ [ 'name' => 'x', 'status' => 'publish' ], '"status" is not a supported field' ],
            'script description'    => [ [ 'name' => 'x', 'description' => '<script>alert(1)</script>' ], 'description' ],
            'js url description'    => [ [ 'name' => 'x', 'description' => '<a href="javascript:alert(1)">x</a>' ], 'description' ],
            'kses would change'     => [ [ 'name' => 'x', 'description' => 'ok <script>x</script>' ], 'description' ],
            'kses drops a form'     => [ [ 'name' => 'x', 'description' => 'ok <form>x</form>' ], 'contains markup WordPress does not allow in post content' ],
            'kses drops a form short' => [ [ 'name' => 'x', 'short_description' => 'ok <form>x</form>' ], 'contains markup WordPress does not allow in post content' ],
            'regular price newline' => [ [ 'name' => 'x', 'regular_price' => "10\n" ], '"regular_price"' ],
            'sale price newline'    => [ [ 'name' => 'x', 'regular_price' => '20', 'sale_price' => "10\n" ], '"sale_price"' ],
            'weight newline'        => [ [ 'name' => 'x', 'weight' => "1\n" ], '"weight"' ],
            'dimension newline'     => [ [ 'name' => 'x', 'dimensions' => [ 'width' => "5\n" ] ], '"dimensions"' ],
            'category newline'      => [ [ 'name' => 'x', 'categories' => [ "shirts\n" ] ], '"categories"' ],
            'tag newline'           => [ [ 'name' => 'x', 'tags' => [ "sale\n" ] ], '"tags"' ],
            'sku newline'           => [ [ 'name' => 'x', 'sku' => "A1\n" ], '"sku"' ],
            'short too long'        => [ [ 'name' => 'x', 'short_description' => str_repeat( 'a', 1001 ) ], 'short_description' ],
            'description too long'  => [ [ 'name' => 'x', 'description' => str_repeat( 'a', 10001 ) ], 'description' ],
            'sku too long'          => [ [ 'name' => 'x', 'sku' => str_repeat( 'a', 101 ) ], '"sku"' ],
            'sku non ascii'         => [ [ 'name' => 'x', 'sku' => "h\u{e9}" ], '"sku"' ],
            'sku padded'            => [ [ 'name' => 'x', 'sku' => ' A1' ], '"sku"' ],
            'price symbol'          => [ [ 'name' => 'x', 'regular_price' => '$5' ], '"regular_price"' ],
            'price comma'           => [ [ 'name' => 'x', 'regular_price' => '1,000' ], '"regular_price"' ],
            'price number'          => [ [ 'name' => 'x', 'regular_price' => 5 ], '"regular_price"' ],
            'price negative'        => [ [ 'name' => 'x', 'regular_price' => '-5' ], '"regular_price"' ],
            'price too many digits' => [ [ 'name' => 'x', 'regular_price' => '12345678901' ], '"regular_price"' ],
            'sale without regular'  => [ [ 'name' => 'x', 'sale_price' => '5' ], 'regular_price' ],
            'sale equals regular'   => [ [ 'name' => 'x', 'regular_price' => '10', 'sale_price' => '10.0' ], 'lower' ],
            'sale above regular'    => [ [ 'name' => 'x', 'regular_price' => '10', 'sale_price' => '11' ], 'lower' ],
            'qty without manage'    => [ [ 'name' => 'x', 'stock_quantity' => 5 ], 'manage_stock' ],
            'manage without qty'    => [ [ 'name' => 'x', 'manage_stock' => true ], '"stock_quantity"' ],
            'qty float'             => [ [ 'name' => 'x', 'manage_stock' => true, 'stock_quantity' => 1.5 ], '"stock_quantity"' ],
            'qty negative'          => [ [ 'name' => 'x', 'manage_stock' => true, 'stock_quantity' => -1 ], '"stock_quantity"' ],
            'qty too big'           => [ [ 'name' => 'x', 'manage_stock' => true, 'stock_quantity' => 1000001 ], '"stock_quantity"' ],
            'manage as string'      => [ [ 'name' => 'x', 'manage_stock' => 'yes' ], '"manage_stock"' ],
            'status with manage'    => [ [ 'name' => 'x', 'manage_stock' => true, 'stock_quantity' => 1, 'stock_status' => 'instock' ], '"stock_status"' ],
            'bad status'            => [ [ 'name' => 'x', 'stock_status' => 'maybe' ], '"stock_status"' ],
            'backorders no manage'  => [ [ 'name' => 'x', 'backorders' => 'yes' ], '"backorders"' ],
            'bad backorders'        => [ [ 'name' => 'x', 'manage_stock' => true, 'stock_quantity' => 1, 'backorders' => 'sometimes' ], '"backorders"' ],
            'categories not list'   => [ [ 'name' => 'x', 'categories' => 'shirts' ], '"categories"' ],
            'category bad slug'     => [ [ 'name' => 'x', 'categories' => [ 'a b' ] ], '"categories"' ],
            'category duplicate'    => [ [ 'name' => 'x', 'categories' => [ 'a', 'a' ] ], '"categories"' ],
            'too many categories'   => [ [ 'name' => 'x', 'categories' => array_map( fn ( $i ) => "c{$i}", range( 1, 11 ) ) ], '"categories"' ],
            'too many tags'         => [ [ 'name' => 'x', 'tags' => array_map( fn ( $i ) => "t{$i}", range( 1, 21 ) ) ], '"tags"' ],
            'image as url'          => [ [ 'name' => 'x', 'image_id' => 'https://example.test/a.png' ], '"image_id"' ],
            'image zero'            => [ [ 'name' => 'x', 'image_id' => 0 ], '"image_id"' ],
            'gallery not ints'      => [ [ 'name' => 'x', 'gallery_image_ids' => [ 'a' ] ], '"gallery_image_ids"' ],
            'gallery too many'      => [ [ 'name' => 'x', 'gallery_image_ids' => range( 1, 11 ) ], '"gallery_image_ids"' ],
            'dimensions list'       => [ [ 'name' => 'x', 'dimensions' => [ '1', '2' ] ], '"dimensions"' ],
            'dimensions bad key'    => [ [ 'name' => 'x', 'dimensions' => [ 'depth' => '1' ] ], '"dimensions"' ],
            'dimension not decimal' => [ [ 'name' => 'x', 'dimensions' => [ 'length' => 'long' ] ], '"dimensions"' ],
            'weight bad'            => [ [ 'name' => 'x', 'weight' => '2kg' ], '"weight"' ],
            'tax status bad'        => [ [ 'name' => 'x', 'tax_status' => 'free' ], '"tax_status"' ],
            'virtual string'        => [ [ 'name' => 'x', 'virtual' => 'true' ], '"virtual"' ],
            'sold individually int' => [ [ 'name' => 'x', 'sold_individually' => 1 ], '"sold_individually"' ],
        ];
    }

    #[DataProvider( 'badProducts' )]
    public function testBadInputIsRefusedWithAReason( array $product, string $needle ): void
    {
        $this->refusedWith( $product, $needle );
    }

    public function testEveryReasonIsReportedNotJustTheFirst(): void
    {
        $r = $this->check( [ 'name' => '', 'regular_price' => 'x', 'sku' => str_repeat( 'a', 200 ), 'bogus' => 1 ] );
        $this->assertNull( $r['values'] );
        $this->assertGreaterThanOrEqual( 4, count( $r['reasons'] ) );
    }

    public function testNullMeansNotGiven(): void
    {
        $v = $this->ok( [ 'name' => 'x', 'sku' => null, 'regular_price' => null, 'manage_stock' => null, 'categories' => null ] );
        $this->assertSame( '', $v['sku'] );
        $this->assertFalse( $v['manage_stock'] );
    }

    public function testZeroPriceAndASaleBelowARegularPriceAreFine(): void
    {
        $this->assertSame( '0', $this->ok( [ 'name' => 'Free', 'regular_price' => '0' ] )['regular_price'] );
        $this->assertSame( '9.999999', $this->ok( [ 'name' => 'x', 'regular_price' => '10', 'sale_price' => '9.999999' ] )['sale_price'] );
    }

    public function testAmpersandsInDescriptionsAreAcceptedAndKeptUnchanged(): void
    {
        $v = $this->ok( [ 'name' => 'x', 'description' => 'Tom & Jerry', 'short_description' => 'Tom & Jerry' ] );
        $this->assertSame( 'Tom & Jerry', $v['description'] );
        $this->assertSame( 'Tom & Jerry', $v['short_description'] );

        $v = $this->ok( [ 'name' => 'x', 'description' => 'Tom &amp; Jerry', 'short_description' => 'Tom &amp; Jerry' ] );
        $this->assertSame( 'Tom &amp; Jerry', $v['description'] );
        $this->assertSame( 'Tom &amp; Jerry', $v['short_description'] );
    }

    public function testAPaddedNameIsNotCalledMarkup(): void
    {
        $r = $this->check( [ 'name' => '  Hat  ' ] );
        $this->assertSame( [ '"name" must not start or end with spaces' ], $r['reasons'] );
    }

    public function testAmpersandsAndOtherPunctuationInNamesAndSkusAreFine(): void
    {
        $this->assertSame( 'Tom & Jerry Hat', $this->ok( [ 'name' => 'Tom & Jerry Hat' ] )['name'] );
        $this->assertSame( 'A&B-1/2"#', $this->ok( [ 'name' => 'x', 'sku' => 'A&B-1/2"#' ] )['sku'] );
    }

    public function testSameTextUndoesOnlyCoresAmpersandRewrite(): void
    {
        $this->assertTrue( ProductValuePolicy::sameText( 'Tom & Jerry', 'Tom &amp; Jerry' ) );
        $this->assertTrue( ProductValuePolicy::sameText( 'Tom &amp; Jerry', 'Tom & Jerry' ) );
        $this->assertTrue( ProductValuePolicy::sameText( 'Hat', 'Hat' ) );
        $this->assertFalse( ProductValuePolicy::sameText( 'Hat', 'hat' ) );
        $this->assertFalse( ProductValuePolicy::sameText( 'Hat', 'Hat ' ) );
        $this->assertFalse( ProductValuePolicy::sameText( 'a > b', 'a &gt; b' ) );
    }

    public static function decimalPairs(): array
    {
        return [ [ '10', '10.0', 0 ], [ '10.50', '10.5', 0 ], [ '9', '10', -1 ], [ '10', '9', 1 ], [ '0.1', '0.01', 1 ], [ '0099', '99', 0 ], [ '1000000', '999999.999999', 1 ], [ '0', '0.000001', -1 ] ];
    }

    #[DataProvider( 'decimalPairs' )]
    public function testCompareDecimalsIsExactNotFloat( string $a, string $b, int $expected ): void
    {
        $this->assertSame( $expected, ProductValuePolicy::compareDecimals( $a, $b ) );
        $this->assertSame( -$expected, ProductValuePolicy::compareDecimals( $b, $a ) );
    }
}
