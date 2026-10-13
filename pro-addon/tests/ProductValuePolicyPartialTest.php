<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Products\ProductValuePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/** The update mode of the product policy: only the given fields, judged against the product's current state. */
class ProductValuePolicyPartialTest extends TestCase
{
    private \Closure $kses;
    private \Closure $strip;

    protected function setUp(): void
    {
        $this->kses  = static fn ( string $s ): string => (string) preg_replace( '/&(?!#?\w+;)/', '&amp;', (string) preg_replace( '#<\s*(?:script|form)\b.*?>.*?<\s*/\s*(?:script|form)\s*>#is', '', $s ) );
        $this->strip = static fn ( string $s ): string => strip_tags( $s );
    }

    /** @return array<string,mixed> a published simple product with managed stock */
    private function current( array $over = [] ): array
    {
        return $over + [
            'type' => 'simple', 'status' => 'publish', 'name' => 'Blue Hat', 'description' => '<p>Warm</p>', 'short_description' => 'Warm', 'sku' => 'HAT-1',
            'regular_price' => '20', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => true, 'stock_quantity' => 12, 'stock_status' => 'instock',
            'backorders' => 'no', 'category_ids' => [ 3 ], 'category_slugs' => [ 'shirts' ], 'tag_ids' => [], 'tag_slugs' => [], 'image_id' => 0, 'gallery_image_ids' => [],
            'weight' => '', 'length' => '', 'width' => '', 'height' => '', 'tax_status' => 'taxable', 'variations' => [],
        ];
    }

    /** @return array<string,mixed> a variable product with two variation rows (11 managed, 12 not) */
    private function variable(): array
    {
        return $this->current( [
            'type' => 'variable', 'regular_price' => '', 'manage_stock' => false, 'stock_quantity' => null,
            'variations' => [
                11 => [ 'regular_price' => '10', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => true, 'stock_quantity' => 5, 'stock_status' => 'instock', 'backorders' => 'no' ],
                12 => [ 'regular_price' => '12', 'sale_price' => '9', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'outofstock', 'backorders' => 'no' ],
            ],
        ] );
    }

    /** @return array{changes: ?array<string,mixed>, reasons: list<string>} */
    private function check( array $update, ?array $current = null ): array
    {
        return ProductValuePolicy::checkPartial( $update, $current ?? $this->current(), $this->kses, $this->strip );
    }

    /** @return array<string,mixed> */
    private function ok( array $update, ?array $current = null ): array
    {
        $r = $this->check( $update, $current );
        $this->assertSame( [], $r['reasons'], json_encode( $r['reasons'] ) );
        $this->assertIsArray( $r['changes'] );

        return $r['changes'];
    }

    private function refused( array $update, string $needle, ?array $current = null ): void
    {
        $r = $this->check( $update, $current );
        $this->assertNull( $r['changes'], 'a refused update has no changes' );
        $this->assertNotSame( [], $r['reasons'] );
        $this->assertStringContainsString( $needle, implode( ' | ', $r['reasons'] ) );
    }

    // ---- keys and unchanged ---------------------------------------------------------------------

    public function testAnEmptyUpdateAndAnUpdateThatChangesNothingHaveNoChanges(): void
    {
        $this->assertSame( [], $this->ok( [] ) );
        $same = [ 'name' => 'Blue Hat', 'description' => '<p>Warm</p>', 'sku' => 'HAT-1', 'regular_price' => '20.00', 'manage_stock' => true, 'stock_quantity' => 12, 'tax_status' => 'taxable', 'image_id' => 0, 'gallery_image_ids' => [], 'weight' => '' ];
        $this->assertSame( [], $this->ok( $same ), 'every value equals the current one ("20.00" equals "20")' );
        $this->assertSame( [], $this->ok( [ 'name' => null, 'regular_price' => null ] ), 'null means omitted' );
    }

    public function testOnlyTheFieldsThatDifferAreReturned(): void
    {
        $this->assertSame( [ 'name' => 'Red Hat', 'regular_price' => '25' ], $this->ok( [ 'name' => 'Red Hat', 'regular_price' => '25', 'sku' => 'HAT-1' ] ) );
    }

    public function testUnknownKeysAreRefusedByNameAndStatusTypeSlugSayWhy(): void
    {
        $this->refused( [ 'colour' => 'red' ], '"colour" is not a supported field' );
        $this->refused( [ 'status' => 'publish' ], 'never changes a product\'s status, type or slug' );
        $this->refused( [ 'type' => 'variable' ], '"type" is not a supported field' );
        $this->refused( [ 'slug' => 'x' ], '"slug" is not a supported field' );
        $this->refused( [ 'virtual' => true ], '"virtual" is not a supported field' );
        $this->refused( [ 'id' => 5 ], '"id" is not a supported field' ); // the tool takes the id out before it asks the policy
        $this->refused( [ 0 => 'x' ], '"0" is not a supported field' );
    }

    // ---- name, texts, sku -----------------------------------------------------------------------

    /** @return array<string,array{mixed,string}> */
    public static function badNames(): array
    {
        return [
            'empty'       => [ '', '"name" must be a non-empty string' ],
            'blank'       => [ '   ', '"name" must be a non-empty string' ],
            'not string'  => [ 5, '"name" must be a non-empty string' ],
            'angle'       => [ 'A <b>hat</b>', '< or >' ],
            'backslash'   => [ 'A\\B', 'backslash' ],
            'padded'      => [ ' Hat', 'spaces' ],
            'control'     => [ "A\nB", 'control characters' ],
            'too long'    => [ str_repeat( 'x', 201 ), '200 characters' ],
        ];
    }

    #[DataProvider( 'badNames' )]
    public function testNameRulesAreTheOnesCreateUses( mixed $name, string $needle ): void
    {
        $this->refused( [ 'name' => $name ], $needle );
    }

    public function testAnAmpersandInTheNameIsTheSameNameAsTheStoredEncodedOne(): void
    {
        $this->assertSame( [], $this->ok( [ 'name' => 'Salt & Pepper' ], $this->current( [ 'name' => 'Salt &amp; Pepper' ] ) ) );
        $this->assertSame( [ 'name' => 'Salt & Pepper' ], $this->ok( [ 'name' => 'Salt & Pepper' ] ) );
    }

    public function testTextsCanBeClearedAndAreCheckedLikeCreate(): void
    {
        $this->assertSame( [ 'description' => '', 'short_description' => '' ], $this->ok( [ 'description' => '', 'short_description' => '' ] ) );
        $this->refused( [ 'description' => '<script>x</script>' ], 'script' );
        $this->refused( [ 'description' => '<form>x</form>' ], 'markup WordPress does not allow' );
        $this->refused( [ 'description' => 'a\\b' ], 'backslash' );
        $this->refused( [ 'short_description' => str_repeat( 'x', 1001 ) ], '1,000 characters' );
        $this->refused( [ 'description' => 5 ], '"description" must be a string' );
        $this->assertSame( [], $this->ok( [ 'description' => '<p>Warm</p>' ] ), 'the same text is no change' );
        $this->assertSame( [], $this->ok( [ 'description' => 'Fish & chips' ], $this->current( [ 'description' => 'Fish &amp; chips' ] ) ) );
    }

    public function testTheSku(): void
    {
        $this->assertSame( [ 'sku' => '' ], $this->ok( [ 'sku' => '' ] ), '"" clears it' );
        $this->assertSame( [ 'sku' => 'HAT-2' ], $this->ok( [ 'sku' => 'HAT-2' ] ) );
        $this->assertSame( [], $this->ok( [ 'sku' => 'HAT-1' ] ) );
        $this->assertSame( [ 'sku' => 'hat-1' ], $this->ok( [ 'sku' => 'hat-1' ] ), 'a change of case is a change (the tool allows it: the product owns the SKU)' );
        $this->refused( [ 'sku' => 'A<b' ], '< or >' );
        $this->refused( [ 'sku' => ' A' ], 'printable ASCII' );
        $this->refused( [ 'sku' => str_repeat( 'A', 101 ) ], 'printable ASCII' );
        $this->refused( [ 'sku' => 12 ], 'printable ASCII' );
    }

    // ---- prices and the sale window --------------------------------------------------------------

    public function testPrices(): void
    {
        $this->assertSame( [ 'regular_price' => '25.50' ], $this->ok( [ 'regular_price' => '25.50' ] ) );
        $this->assertSame( [ 'regular_price' => '', 'sale_price' => '' ], $this->ok( [ 'regular_price' => '', 'sale_price' => '' ], $this->current( [ 'sale_price' => '15' ] ) ), 'both cleared together' );
        $this->refused( [ 'regular_price' => '$20' ], 'decimal string' );
        $this->refused( [ 'regular_price' => 20 ], 'decimal string' );
        $this->refused( [ 'regular_price' => '1,000' ], 'decimal string' );
    }

    public function testASalePriceIsJudgedAgainstTheResultingRegularPrice(): void
    {
        $this->assertSame( [ 'sale_price' => '15' ], $this->ok( [ 'sale_price' => '15' ] ) );
        $this->refused( [ 'sale_price' => '20' ], 'lower than the "regular_price"' );
        $this->refused( [ 'sale_price' => '25' ], 'lower than the "regular_price"' );
        $this->refused( [ 'regular_price' => '10' ], 'lower than the "regular_price"', $this->current( [ 'sale_price' => '15' ] ) ); // lowering the regular price under an existing sale
        $this->assertSame( [ 'regular_price' => '30', 'sale_price' => '25' ], $this->ok( [ 'regular_price' => '30', 'sale_price' => '25' ], $this->current( [ 'sale_price' => '15' ] ) ) );
        $this->refused( [ 'sale_price' => '5' ], 'needs a "regular_price"', $this->current( [ 'regular_price' => '' ] ) );
        $this->refused( [ 'regular_price' => '' ], 'needs a "regular_price"', $this->current( [ 'sale_price' => '15' ] ) );
    }

    public function testAnUntouchedInconsistentPriceStateNeverBlocksAnUnrelatedChange(): void
    {
        $legacy = $this->current( [ 'regular_price' => '10', 'sale_price' => '15', 'sale_from' => '2026-01-01' ] ); // a sale above the regular price, from an old import
        $this->assertSame( [ 'name' => 'New' ], $this->ok( [ 'name' => 'New' ], $legacy ) );
        $this->assertSame( [ 'weight' => '2' ], $this->ok( [ 'weight' => '2' ], $legacy ) );
        $dates = $this->current( [ 'sale_from' => '2026-01-01', 'sale_to' => '2025-01-01' ] ); // sale dates without a sale price, the end before the start
        $this->assertSame( [ 'regular_price' => '25' ], $this->ok( [ 'regular_price' => '25' ], $dates ), 'a price change does not judge the sale window it did not touch' );
        $this->assertSame( [ 'manage_stock' => false, 'stock_quantity' => null ], $this->ok( [ 'manage_stock' => false ], $dates ) );
    }

    public function testTheSaleWindow(): void
    {
        $sale = $this->current( [ 'sale_price' => '15' ] );
        $this->assertSame( [ 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-30' ], $this->ok( [ 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-30' ], $sale ) );
        $this->assertSame( [ 'sale_price' => '15', 'sale_from' => '2026-11-01' ], $this->ok( [ 'sale_price' => '15', 'sale_from' => '2026-11-01' ] ) );
        $this->assertSame( [], $this->ok( [ 'sale_from' => '' ], $sale ), 'clearing an empty date is no change' );
        $this->assertSame( [ 'sale_from' => '' ], $this->ok( [ 'sale_from' => '' ], $this->current( [ 'sale_price' => '15', 'sale_from' => '2026-11-01' ] ) ) );
        $this->refused( [ 'sale_from' => '2026-11-01' ], 'sale dates need a "sale_price"' );
        $this->refused( [ 'sale_price' => '' ], 'sale dates need a "sale_price"', $this->current( [ 'sale_price' => '15', 'sale_to' => '2026-12-31' ] ) );
        $this->assertSame( [ 'sale_price' => '', 'sale_from' => '', 'sale_to' => '' ], $this->ok( [ 'sale_price' => '', 'sale_from' => '', 'sale_to' => '' ], $this->current( [ 'sale_price' => '15', 'sale_from' => '2026-11-01', 'sale_to' => '2026-12-31' ] ) ), 'a sale ends by clearing all three' );
        $this->refused( [ 'sale_from' => '2026-12-01', 'sale_to' => '2026-11-01' ], '"sale_to" must not be before "sale_from"', $sale );
        $this->refused( [ 'sale_to' => '2026-10-01' ], '"sale_to" must not be before "sale_from"', $this->current( [ 'sale_price' => '15', 'sale_from' => '2026-11-01' ] ) );
        $this->assertSame( [ 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-01' ], $this->ok( [ 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-01' ], $sale ), 'a one-day sale' );
    }

    #[DataProvider( 'badDates' )]
    public function testBadDatesAreRefused( mixed $date ): void
    {
        $this->refused( [ 'sale_from' => $date ], 'YYYY-MM-DD', $this->current( [ 'sale_price' => '15' ] ) );
    }

    /** @return array<string,array{mixed}> */
    public static function badDates(): array
    {
        return [ 'not a date' => [ 'soon' ], 'month 13' => [ '2026-13-01' ], 'feb 30' => [ '2026-02-30' ], 'with time' => [ '2026-11-01 10:00' ], 'a number' => [ 20261101 ], 'slashes' => [ '2026/11/01' ] ];
    }

    public function testAVariableProductHasNoPriceOfItsOwn(): void
    {
        foreach ( [ 'regular_price' => '5', 'sale_price' => '4', 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-02' ] as $key => $value ) {
            $this->refused( [ $key => $value ], "\"{$key}\" is not available on a variable product", $this->variable() );
        }
        $this->assertSame( [ 'name' => 'X' ], $this->ok( [ 'name' => 'X' ], $this->variable() ) );
    }

    // ---- stock ----------------------------------------------------------------------------------

    public function testManagedStock(): void
    {
        $this->assertSame( [ 'stock_quantity' => 3 ], $this->ok( [ 'stock_quantity' => 3 ] ) );
        $this->assertSame( [], $this->ok( [ 'stock_quantity' => 12 ] ) );
        $this->assertSame( [ 'stock_quantity' => 0 ], $this->ok( [ 'stock_quantity' => 0 ] ) );
        $this->assertSame( [ 'backorders' => 'notify' ], $this->ok( [ 'backorders' => 'notify' ] ) );
        $this->refused( [ 'stock_quantity' => -1 ], 'whole number from 0 to 1,000,000' );
        $this->refused( [ 'stock_quantity' => 1000001 ], 'whole number from 0 to 1,000,000' );
        $this->refused( [ 'stock_quantity' => '5' ], 'whole number from 0 to 1,000,000' );
        $this->refused( [ 'stock_quantity' => 2.5 ], 'whole number from 0 to 1,000,000' );
        $this->refused( [ 'stock_status' => 'outofstock' ], 'cannot be set while stock is managed' );
        $this->refused( [ 'backorders' => 'maybe' ], 'must be one of: no, notify, yes' );
        $this->refused( [ 'manage_stock' => 'yes' ], '"manage_stock" must be true or false' );
    }

    public function testUnmanagedStock(): void
    {
        $plain = $this->current( [ 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock' ] );
        $this->assertSame( [ 'stock_status' => 'outofstock' ], $this->ok( [ 'stock_status' => 'outofstock' ], $plain ) );
        $this->assertSame( [], $this->ok( [ 'stock_status' => 'instock', 'manage_stock' => false ], $plain ) );
        $this->refused( [ 'stock_quantity' => 3 ], 'needs managed stock', $plain );
        $this->refused( [ 'backorders' => 'yes' ], 'needs managed stock', $plain );
        $this->refused( [ 'stock_status' => 'sold' ], 'must be one of: instock, outofstock, onbackorder', $plain );
    }

    public function testSwitchingManagedStockOnNeedsAQuantityAndOffListsWhatWooCommerceClears(): void
    {
        $plain = $this->current( [ 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'outofstock' ] );
        $this->assertSame( [ 'manage_stock' => true, 'stock_quantity' => 4 ], $this->ok( [ 'manage_stock' => true, 'stock_quantity' => 4 ], $plain ) );
        $this->refused( [ 'manage_stock' => true ], 'managed stock needs a "stock_quantity"', $plain );

        $managed = $this->current( [ 'backorders' => 'notify' ] );
        $this->assertSame( [ 'manage_stock' => false, 'stock_quantity' => null, 'backorders' => 'no' ], $this->ok( [ 'manage_stock' => false ], $managed ) );
        $this->assertSame( [ 'manage_stock' => false, 'stock_quantity' => null, 'backorders' => 'no', 'stock_status' => 'outofstock' ], $this->ok( [ 'manage_stock' => false, 'stock_status' => 'outofstock' ], $managed ) );
        $this->assertSame( [ 'manage_stock' => false, 'stock_quantity' => null ], $this->ok( [ 'manage_stock' => false ], $this->current() ), 'backorders already "no": not listed' );
    }

    public function testExternalProductsHaveNoStockFields(): void
    {
        $external = $this->current( [ 'type' => 'external', 'manage_stock' => false, 'stock_quantity' => null ] );
        $this->refused( [ 'stock_status' => 'outofstock' ], '"stock_status" is not available on an external product', $external );
        $this->refused( [ 'manage_stock' => true, 'stock_quantity' => 1 ], 'not available on an external product', $external );
        $this->assertSame( [ 'regular_price' => '5' ], $this->ok( [ 'regular_price' => '5' ], $external ), 'prices are fine' );
    }

    // ---- catalogue data -------------------------------------------------------------------------

    public function testWeightAndDimensionsMergeAxisByAxis(): void
    {
        $boxed = $this->current( [ 'weight' => '2', 'length' => '10', 'width' => '5', 'height' => '2.5' ] );
        $this->assertSame( [ 'weight' => '' ], $this->ok( [ 'weight' => '' ], $boxed ) );
        $this->assertSame( [ 'length' => '12' ], $this->ok( [ 'dimensions' => [ 'length' => '12', 'width' => '5.00' ] ], $boxed ), 'only the axis that differs; "5.00" equals "5"' );
        $this->assertSame( [ 'height' => '' ], $this->ok( [ 'dimensions' => [ 'height' => '' ] ], $boxed ) );
        $this->refused( [ 'dimensions' => [ 'depth' => '1' ] ], 'only "length", "width" and "height"' );
        $this->refused( [ 'dimensions' => [ 1, 2 ] ], 'only "length", "width" and "height"' );
        $this->refused( [ 'dimensions' => [ 'length' => 'wide' ] ], 'decimal string' );
        $this->refused( [ 'weight' => 'heavy' ], 'decimal string' );
    }

    public function testTaxStatusAndImages(): void
    {
        $this->assertSame( [ 'tax_status' => 'none' ], $this->ok( [ 'tax_status' => 'none' ] ) );
        $this->refused( [ 'tax_status' => 'free' ], 'must be one of: taxable, shipping, none' );
        $this->assertSame( [ 'image_id' => 90, 'gallery_image_ids' => [ 91, 92 ] ], $this->ok( [ 'image_id' => 90, 'gallery_image_ids' => [ 91, 92 ] ] ) );
        $this->assertSame( [ 'image_id' => 0 ], $this->ok( [ 'image_id' => 0 ], $this->current( [ 'image_id' => 90 ] ) ), '0 removes the image' );
        $this->assertSame( [], $this->ok( [ 'image_id' => 90, 'gallery_image_ids' => [ 91 ] ], $this->current( [ 'image_id' => 90, 'gallery_image_ids' => [ 91 ] ] ) ) );
        $this->assertSame( [ 'gallery_image_ids' => [] ], $this->ok( [ 'gallery_image_ids' => [] ], $this->current( [ 'gallery_image_ids' => [ 91 ] ] ) ) );
        $this->refused( [ 'image_id' => -1 ], 'id of an image in the Media Library' );
        $this->refused( [ 'image_id' => '90' ], 'id of an image in the Media Library' );
        $this->refused( [ 'gallery_image_ids' => [ 1, 1 ] ], 'at most 10 different image ids' );
        $this->refused( [ 'gallery_image_ids' => [ 0 ] ], 'whole image ids from 1' );
        $this->refused( [ 'gallery_image_ids' => range( 1, 11 ) ], 'at most 10 different image ids' );
    }

    public function testCategoryAndTagSlugsArePassedOnForTheToolToResolve(): void
    {
        $this->assertSame( [ 'category_slugs' => [ 'hats', 'shirts' ], 'tag_slugs' => [ 'sale' ] ], $this->ok( [ 'categories' => [ 'hats', 'shirts' ], 'tags' => [ 'sale' ] ] ) );
        $this->assertSame( [ 'category_slugs' => [] ], $this->ok( [ 'categories' => [] ] ), 'an empty list is a request to clear (the tool compares ids)' );
        $this->refused( [ 'categories' => 'hats' ], 'list of at most 10 different slugs' );
        $this->refused( [ 'tags' => [ 'a b' ] ], 'only slugs' );
        $this->refused( [ 'categories' => [ 'a', 'a' ] ], 'different slugs' );
        $this->refused( [ 'tags' => array_map( static fn ( int $i ): string => "t{$i}", range( 1, 21 ) ) ], 'at most 20 different slugs' );
    }

    public function testEveryReasonIsReportedAtOnce(): void
    {
        $r = $this->check( [ 'name' => '', 'sale_price' => '99', 'stock_status' => 'outofstock', 'colour' => 1 ] );
        $this->assertNull( $r['changes'] );
        $this->assertGreaterThanOrEqual( 4, count( $r['reasons'] ) );
    }

    // ---- variation rows -------------------------------------------------------------------------

    public function testVariationRowsReturnOnlyWhatChanges(): void
    {
        $changes = $this->ok( [ 'variations' => [
            [ 'id' => 11, 'regular_price' => '11', 'stock_quantity' => 5 ],
            [ 'id' => 12, 'sale_price' => '8', 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-30' ],
        ] ], $this->variable() );
        $this->assertSame( [ 11 => [ 'regular_price' => '11' ], 12 => [ 'sale_price' => '8', 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-30' ] ], $changes['variations'] );
    }

    public function testAVariationRowThatChangesNothingIsDroppedAndSoIsTheKey(): void
    {
        $this->assertSame( [], $this->ok( [ 'variations' => [ [ 'id' => 11, 'regular_price' => '10.00' ] ] ], $this->variable() ) );
    }

    public function testVariationRowsFollowTheSameRelativeRulesPerRowAndNameTheRow(): void
    {
        $this->refused( [ 'variations' => [ [ 'id' => 11, 'sale_price' => '10' ] ] ], 'variation 11: the resulting "sale_price" must be lower than the "regular_price"', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => 12, 'stock_quantity' => 3 ] ] ], 'variation 12: "stock_quantity" needs managed stock', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => 11, 'stock_status' => 'outofstock' ] ] ], 'variation 11: "stock_status" cannot be set while stock is managed', $this->variable() );
        $this->assertSame( [ 12 => [ 'manage_stock' => true, 'stock_quantity' => 9 ] ], $this->ok( [ 'variations' => [ [ 'id' => 12, 'manage_stock' => true, 'stock_quantity' => 9 ] ] ], $this->variable() )['variations'] );
        $this->assertSame( [ 11 => [ 'manage_stock' => false, 'stock_quantity' => null ] ], $this->ok( [ 'variations' => [ [ 'id' => 11, 'manage_stock' => false ] ] ], $this->variable() )['variations'] );
    }

    public function testVariationRowsMustBelongToTheProductAndBeWellFormed(): void
    {
        $this->refused( [ 'variations' => [ [ 'id' => 99, 'regular_price' => '1' ] ] ], 'variation 99 does not belong to this product', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => 11, 'regular_price' => '1' ], [ 'id' => 11, 'regular_price' => '2' ] ] ], 'variation 11 appears more than once', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'regular_price' => '1' ] ] ], '"id" must be a whole variation id', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => '11' ] ] ], '"id" must be a whole variation id', $this->variable() );
        $this->refused( [ 'variations' => [ 5 ] ], 'must be an object', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => 11, 'sku' => 'X' ] ] ], 'variation 11: "sku" is not a supported field', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => 11, 'status' => 'private' ] ] ], 'variation 11: "status" is not a supported field', $this->variable() );
        $this->refused( [ 'variations' => [] ], '1 to 100 objects', $this->variable() );
        $this->refused( [ 'variations' => [ 'id' => 11 ] ], '1 to 100 objects', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => 11, 'regular_price' => '1' ] ] ], 'only for variable products' );
    }

    public function testAtMostOneHundredVariationRowsPerProduct(): void
    {
        $many = $this->variable();
        for ( $i = 100; $i < 201; $i++ ) {
            $many['variations'][ $i ] = $many['variations'][11];
        }
        $rows = [];
        for ( $i = 100; $i < 201; $i++ ) {
            $rows[] = [ 'id' => $i, 'regular_price' => '11' ];
        }
        $this->refused( [ 'variations' => $rows ], '1 to 100 objects', $many );
        $this->assertCount( 100, $this->ok( [ 'variations' => array_slice( $rows, 0, 100 ) ], $many )['variations'] );
    }
}
