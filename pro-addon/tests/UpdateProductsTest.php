<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\ProductFieldsKind;
use AiEditorDivi5\Pro\Products\ProductSaveFailed;
use AiEditorDivi5\Pro\Tools\UpdateProducts;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpKsesShim.php';
require_once __DIR__ . '/WpBuildShims.php';
require_once __DIR__ . '/FakeProductWriter.php';
require_once __DIR__ . '/WcDataExceptionShim.php';

class UpdateProductsTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private FakeProductWriter $writer;
    private UpdateProducts $tool;
    private int $now;

    protected function setUp(): void
    {
        $this->now    = (int) strtotime( '2026-10-13 12:00:00 UTC' );
        $this->repo   = new InMemoryBatchRepository();
        $this->log    = new BatchLog( $this->repo );
        $this->writer = new FakeProductWriter();
        $this->tool   = new UpdateProducts( $this->log, $this->writer, fn (): int => $this->now );
        $this->writer->products[50] = $this->simple();
        $this->writer->products[51] = $this->simple( [ 'name' => 'Plain Mug', 'sku' => 'MUG-1', 'regular_price' => '', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'outofstock', 'category_ids' => [] ] );
        $this->writer->products[60] = $this->variable();
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_posts']           = [];
        $GLOBALS['__wp_current_user_id'] = 7;
        $GLOBALS['__wp_caps']            = [ 'edit_products' => true, 'edit_post' => true, 'assign_product_terms' => true ];
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'] );
    }

    /** @return array<string,mixed> a published simple product with managed stock */
    private function simple( array $over = [] ): array
    {
        return $over + [
            'type' => 'simple', 'status' => 'publish', 'name' => 'Blue Hat', 'description' => '<p>Warm</p>', 'short_description' => 'Warm', 'sku' => 'HAT-1',
            'regular_price' => '20', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => true, 'stock_quantity' => 12, 'stock_status' => 'instock',
            'backorders' => 'no', 'category_ids' => [ 3 ], 'tag_ids' => [], 'image_id' => 0, 'gallery_image_ids' => [], 'weight' => '', 'length' => '', 'width' => '',
            'height' => '', 'tax_status' => 'taxable', 'variations' => [],
        ];
    }

    /** @return array<string,mixed> a variable product with three variation rows */
    private function variable( int $rows = 3, string $name = 'Tee', string $sku = 'TEE' ): array
    {
        $variations = [
            61 => [ 'regular_price' => '10', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => true, 'stock_quantity' => 5, 'stock_status' => 'instock', 'backorders' => 'no' ],
            62 => [ 'regular_price' => '12', 'sale_price' => '9', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock', 'backorders' => 'no' ],
            63 => [ 'regular_price' => '15', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => true, 'stock_quantity' => 0, 'stock_status' => 'outofstock', 'backorders' => 'no' ],
        ];
        for ( $i = 4; $i <= $rows; $i++ ) {
            $variations[ 1000 + $i ] = $variations[61];
        }

        return $this->simple( [ 'type' => 'variable', 'name' => $name, 'sku' => $sku, 'regular_price' => '', 'manage_stock' => false, 'stock_quantity' => null, 'variations' => $variations ] );
    }

    /** @return array<string,mixed> */
    private function dry( array $updates ): array
    {
        return $this->tool->run( [ 'updates' => $updates, 'dry_run' => true ] );
    }

    /** Preview, then run with the token. @return array<string,mixed> */
    private function go( array $updates ): array
    {
        $p = $this->dry( $updates );
        $this->assertArrayHasKey( 'token', $p, 'the preview made a token' );

        return $this->tool->run( [ 'updates' => $updates, 'token' => $p['token'] ] );
    }

    private function refused( array $args, string $message, bool $noWrites = true ): void
    {
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        if ( $noWrites ) {
            $this->assertSame( 0, $this->writer->applyCalls, 'nothing was written' );
            $this->assertSame( [], $this->repo->rows, 'no batch was recorded' );
        }
    }

    // ---- definition -----------------------------------------------------------------------------

    public function testTheDefinition(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'update_products', $d['name'] );
        $this->assertSame( 'edit_posts', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'updates' ], $d['inputSchema']['required'] );
        $this->assertSame( 25, $d['inputSchema']['properties']['updates']['maxItems'] );
        $this->assertSame( 'boolean', $d['inputSchema']['properties']['dry_run']['type'] );
        $this->assertSame( 'string', $d['inputSchema']['properties']['token']['type'] );
        $item = $d['inputSchema']['properties']['updates']['items'];
        $this->assertSame( [ 'id' ], $item['required'] );
        $this->assertSame( [ 'id' ], $item['properties']['variations']['items']['required'] );
        foreach ( [ 'regular_price', 'sale_price', 'sale_from', 'sale_to', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'name', 'description', 'short_description', 'sku', 'categories', 'tags', 'image_id', 'gallery_image_ids', 'weight', 'dimensions', 'tax_status', 'variations' ] as $field ) {
            $this->assertArrayHasKey( $field, $item['properties'], $field );
        }
        foreach ( [ 'status', 'type', 'slug', 'virtual' ] as $never ) {
            $this->assertArrayNotHasKey( $never, $item['properties'], "{$never} is not offered" );
        }
    }

    // ---- the preview ----------------------------------------------------------------------------

    public function testADryRunShowsBeforeAndAfterOfOnlyTheChangedFieldsMakesOneTokenAndWritesNothing(): void
    {
        $r = $this->dry( [ [ 'id' => 50, 'regular_price' => '25', 'name' => 'Blue Hat', 'stock_quantity' => 12 ], [ 'id' => 51, 'stock_status' => 'instock' ] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertSame( 2, $r['would_change'] );
        $this->assertSame( [ 50, 51 ], array_column( $r['products'], 'id' ) );
        $this->assertSame( [ 'Blue Hat', 'Plain Mug' ], array_column( $r['products'], 'name' ) );
        $this->assertSame( 'simple', $r['products'][0]['type'] );
        $this->assertSame( [ 'regular_price' => [ 'before' => '20', 'after' => '25' ] ], $r['products'][0]['changes'] );
        $this->assertSame( [ 'stock_status' => [ 'before' => 'outofstock', 'after' => 'instock' ] ], $r['products'][1]['changes'] );
        $this->assertIsString( $r['token'] );
        $this->assertNotSame( '', $r['token'] );
        $this->assertSame( [], $r['unchanged'] );
        $this->assertSame( 0, $this->writer->applyCalls );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( '20', $this->writer->products[50]['regular_price'] );
    }

    public function testAProductWithNothingToChangeIsListedAsUnchangedAndAPreviewOfNothingHasNoToken(): void
    {
        $r = $this->dry( [ [ 'id' => 50, 'regular_price' => '20.00', 'name' => 'Blue Hat' ], [ 'id' => 51, 'stock_status' => 'instock' ] ] );
        $this->assertSame( [ 50 ], $r['unchanged'] );
        $this->assertSame( [ 51 ], array_column( $r['products'], 'id' ) );
        $this->assertArrayHasKey( 'token', $r );

        $none = $this->dry( [ [ 'id' => 50, 'regular_price' => '20' ] ] );
        $this->assertSame( [ 'changed' => false, 'dry_run' => true, 'would_change' => 0, 'products' => [], 'unchanged' => [ 50 ] ], array_diff_key( $none, [ 'message' => 1 ] ) );
        $this->assertArrayNotHasKey( 'token', $none );
    }

    public function testAFutureSaleCarriesANoteAndAPastOneDoesNot(): void
    {
        $future = $this->dry( [ [ 'id' => 50, 'sale_price' => '15', 'sale_from' => '2026-11-01' ] ] );
        $this->assertStringContainsString( '2026-11-01', $future['products'][0]['notes'][0] );
        $this->assertStringContainsString( 'regular price', $future['products'][0]['notes'][0] );
        $past = $this->dry( [ [ 'id' => 50, 'sale_price' => '15', 'sale_from' => '2026-10-01' ] ] );
        $this->assertArrayNotHasKey( 'notes', $past['products'][0] );
        $viaRow = $this->dry( [ [ 'id' => 60, 'variations' => [ [ 'id' => 61, 'sale_price' => '8', 'sale_from' => '2027-01-01' ] ] ] ] );
        $this->assertStringContainsString( '2027-01-01', $viaRow['products'][0]['notes'][0] );
    }

    public function testADryRunStillRunsEveryCheckAndReportsEveryReasonWithItsPosition(): void
    {
        $args = [ 'updates' => [
            [ 'id' => 50, 'sale_price' => '30' ],
            [ 'id' => 999, 'name' => 'x' ],
            [ 'id' => 70, 'name' => 'x' ],
            [ 'id' => 60, 'regular_price' => '5' ],
            [ 'id' => 50, 'name' => 'dup' ],
            [ 'id' => 51, 'status' => 'publish' ],
        ], 'dry_run' => true ];
        $this->writer->products[70] = $this->simple();
        $this->writer->types[70]    = 'grouped';
        try {
            $this->tool->run( $args );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $m = $e->getMessage();
            $this->assertStringStartsWith( 'Nothing was changed. 6 problem(s): ', $m );
            $this->assertStringContainsString( 'Product 1 (#50 "Blue Hat"): the resulting "sale_price" must be lower than the "regular_price"', $m );
            $this->assertStringContainsString( 'Product 2 (#999): product not found', $m );
            $this->assertStringContainsString( 'Product 3 (#70): grouped products are not supported', $m );
            $this->assertStringContainsString( 'Product 4 (#60 "Tee"): "regular_price" is not available on a variable product', $m );
            $this->assertStringContainsString( 'Product 5 (#50): the product appears more than once', $m );
            $this->assertStringContainsString( 'Product 6 (#51 "Plain Mug"): "status" is not a supported field (this tool never changes', $m );
        }
        $this->assertSame( 0, $this->writer->applyCalls );
    }

    public function testALongRefusalIsCutAtSixThousandCharacters(): void
    {
        $updates = [];
        for ( $i = 0; $i < 25; $i++ ) {
            $this->writer->products[ 100 + $i ] = $this->simple( [ 'name' => "P{$i}", 'sku' => "S{$i}" ] );
            $updates[] = [ 'id' => 100 + $i, str_repeat( 'k', 80 ) . '1' => 1, str_repeat( 'k', 80 ) . '2' => 1, str_repeat( 'k', 80 ) . '3' => 1, str_repeat( 'k', 80 ) . '4' => 1 ];
        }
        try {
            $this->tool->run( [ 'updates' => $updates, 'dry_run' => true ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( 6000, mb_strlen( $e->getMessage() ) );
            $this->assertStringEndsWith( '...', $e->getMessage() );
        }
    }

    // ---- arguments and permissions --------------------------------------------------------------

    public function testTheArgumentsAreCheckedStrictly(): void
    {
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'name' => 'x' ] ], 'dry_run' => 'yes' ], 'must be true or false' );
        $this->refused( [ 'dry_run' => true ], '"updates" is required' );
        $this->refused( [ 'updates' => [], 'dry_run' => true ], 'must not be empty' );
        $this->refused( [ 'updates' => array_fill( 0, 26, [ 'id' => 50 ] ), 'dry_run' => true ], 'at most 25 items' );
        $this->refused( [ 'updates' => [ [ 'id' => 50 ] ], 'token' => 5 ], 'must be a string' );
        $this->refused( [ 'updates' => [ 5 ], 'dry_run' => true ], 'Product 1: must be an object' );
        $this->refused( [ 'updates' => [ [ 'name' => 'x' ] ], 'dry_run' => true ], 'Product 1: "id" must be a whole product id' );
        $this->refused( [ 'updates' => [ [ 'id' => '50', 'name' => 'x' ] ], 'dry_run' => true ], '"id" must be a whole product id' );
        $this->refused( [ 'updates' => [ [ 'id' => 0 ] ], 'dry_run' => true ], '"id" must be a whole product id' );
    }

    public function testWooCommerceMustBeActiveAndTheUserMayEditProducts(): void
    {
        $this->writer->active = false;
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'name' => 'x' ] ], 'dry_run' => true ], 'WooCommerce is not active' );
        $this->writer->active = true;
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true ];
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'name' => 'x' ] ], 'dry_run' => true ], 'edit_products permission' );
    }

    public function testProductsThatAreMissingTrashedVariationsOtherTypesOrNotEditableAllAnswerAlike(): void
    {
        $this->writer->products[80]    = $this->simple();
        $GLOBALS['__wp_posts'][80]     = (object) [ 'ID' => 80, 'post_status' => 'trash' ];
        $this->writer->products[72]    = $this->simple();
        $this->writer->types[72]       = 'subscription';
        foreach ( [ 999, 80, 61 ] as $id ) { // a missing id, a trashed product, a variation id
            $this->refused( [ 'updates' => [ [ 'id' => $id, 'name' => 'x' ] ], 'dry_run' => true ], "Product 1 (#{$id}): product not found" );
        }
        $this->refused( [ 'updates' => [ [ 'id' => 72, 'name' => 'x' ] ], 'dry_run' => true ], 'subscription products are not supported' );
        $GLOBALS['__wp_caps'] = [ 'edit_products' => true ];
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'name' => 'x' ] ], 'dry_run' => true ], 'Product 1 (#50): product not found' );
    }

    public function testTermsNeedTheTermPermissionWheneverTheyAreGiven(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_products' => true, 'edit_post' => true ];
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'categories' => [ 'hats' ] ] ], 'dry_run' => true ], 'assign_product_terms permission' );
    }

    // ---- the token ------------------------------------------------------------------------------

    public function testARealRunNeedsAGenuineUnusedTokenForTheSameArgumentsByTheSameUserInTime(): void
    {
        $updates = [ [ 'id' => 50, 'regular_price' => '25' ] ];
        $needs   = 'A real run needs the token';
        $this->refused( [ 'updates' => $updates ], $needs );
        $this->refused( [ 'updates' => $updates, 'token' => 'garbage' ], $needs );
        $this->refused( [ 'updates' => $updates, 'token' => '' ], $needs );

        $token = $this->dry( $updates )['token'];
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'regular_price' => '26' ] ], 'token' => $token ], $needs ); // other arguments
        $GLOBALS['__wp_current_user_id'] = 8;
        $this->refused( [ 'updates' => $updates, 'token' => $token ], $needs ); // another user
        $GLOBALS['__wp_current_user_id'] = 7;
        $this->now += 1801;
        $this->refused( [ 'updates' => $updates, 'token' => $token ], $needs ); // expired
        $this->now -= 1801;

        $this->assertTrue( $this->tool->run( [ 'updates' => $updates, 'token' => $token ] )['changed'] );
        $this->refused( [ 'updates' => $updates, 'token' => $token ], $needs, false ); // spent
        $this->assertSame( 1, $this->writer->applyCalls, 'only the one good run wrote' );
    }

    public function testATokenSurvivesARefusedRunAndIsSpentOnlyWhenTheRunStarts(): void
    {
        $updates = [ [ 'id' => 50, 'sku' => 'NEW-SKU' ] ];
        $token   = $this->dry( $updates )['token'];
        $this->writer->skus['NEW-SKU'] = true; // someone took the SKU after the preview
        $this->refused( [ 'updates' => $updates, 'token' => $token ], 'already used by another product' );
        unset( $this->writer->skus['NEW-SKU'] );
        $this->assertTrue( $this->tool->run( [ 'updates' => $updates, 'token' => $token ] )['changed'], 'the same token still works' );
    }

    // ---- a real run -----------------------------------------------------------------------------

    public function testARealRunAppliesExactlyWhatWasPreviewedAndRecordsOneBatch(): void
    {
        $r = $this->go( [ [ 'id' => 50, 'regular_price' => '25', 'sale_price' => '18', 'stock_quantity' => 7, 'name' => 'Red Hat' ], [ 'id' => 51, 'stock_status' => 'instock', 'weight' => '2' ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertFalse( $r['dry_run'] );
        $this->assertSame( [ 50, 51 ], array_column( $r['updated'], 'id' ) );
        $this->assertSame( [ 'Red Hat', 'Plain Mug' ], array_column( $r['updated'], 'name' ) );
        $this->assertSame( [ 'name', 'regular_price', 'sale_price', 'stock_quantity' ], $this->sortedKeys( $r['updated'][0]['changes'] ) );
        $this->assertSame( [], $r['skipped'] );
        $this->assertSame( [], $r['unchanged'] );
        $this->assertTrue( $r['batch']['undoable'] );
        $p = $this->writer->products[50];
        $this->assertSame( [ '25', '18', 7, 'Red Hat' ], [ $p['regular_price'], $p['sale_price'], $p['stock_quantity'], $p['name'] ] );
        $this->assertSame( [ 'instock', '2' ], [ $this->writer->products[51]['stock_status'], $this->writer->products[51]['weight'] ] );

        $this->assertCount( 1, $this->repo->rows, 'one batch for the whole call' );
        $batch = array_values( $this->repo->rows )[0];
        $this->assertSame( 'update_products', $batch['tool'] );
        $this->assertSame( [ 'product_fields', 'product_fields' ], array_column( $batch['items'], 'kind' ) );
        $this->assertSame( [ '50', '51' ], array_column( $batch['items'], 'ref' ) );
        $this->assertSame( [ 'fields' => [ 'name' => 'Blue Hat', 'regular_price' => '20', 'sale_price' => '', 'stock_quantity' => 12 ], 'variations' => [] ], $batch['items'][0]['before'] );
    }

    /** @param array<string,mixed> $a @return list<string> */
    private function sortedKeys( array $a ): array
    {
        $k = array_keys( $a );
        sort( $k );

        return $k;
    }

    public function testOnlyTheChangedFieldsAreWritten(): void
    {
        $this->go( [ [ 'id' => 50, 'regular_price' => '25', 'name' => 'Blue Hat', 'description' => '<p>Warm</p>' ] ] );
        $this->assertSame( [ [ 50, [ 'regular_price' => '25' ] ] ], $this->writer->applied );
    }

    public function testSwitchingManagedStockOffWritesTheDerivedValuesAndTheUndoPutsThemBack(): void
    {
        $this->writer->products[50]['backorders'] = 'notify';
        $r = $this->go( [ [ 'id' => 50, 'manage_stock' => false ] ] );
        $this->assertSame( [ 'backorders', 'manage_stock', 'stock_quantity' ], $this->sortedKeys( $r['updated'][0]['changes'] ) );
        $this->assertSame( [ false, null, 'no' ], [ $this->writer->products[50]['manage_stock'], $this->writer->products[50]['stock_quantity'], $this->writer->products[50]['backorders'] ] );
        $this->undoBatch( (int) $r['batch']['id'] );
        $p = $this->writer->products[50];
        $this->assertSame( [ true, 12, 'instock', 'notify' ], [ $p['manage_stock'], $p['stock_quantity'], $p['stock_status'], $p['backorders'] ] );
    }

    public function testTextsAreComparedTheWayCoreStoresThemForAUserWithoutUnfilteredHtml(): void
    {
        $this->writer->filteredTitle = true; // core rewrites a bare "&" to "&amp;"
        $r = $this->go( [ [ 'id' => 50, 'name' => 'Salt & Pepper', 'description' => 'Fish & chips' ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 'Salt &amp; Pepper', $this->writer->products[50]['name'] );
        $again = $this->dry( [ [ 'id' => 50, 'name' => 'Salt & Pepper' ] ] );
        $this->assertSame( [ 50 ], $again['unchanged'], 'the stored "&amp;" is the same name' );
    }

    public function testTheSkuMayBeKeptChangedInCaseButNeverTakenFromAnotherProduct(): void
    {
        $this->writer->products[52] = $this->simple( [ 'name' => 'Other', 'sku' => 'TAKEN' ] );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'sku' => 'TAKEN' ] ], 'dry_run' => true ], 'sku "TAKEN" is already used by another product' );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'sku' => 'taken' ] ], 'dry_run' => true ], 'already used by another product' );
        $this->assertTrue( $this->go( [ [ 'id' => 50, 'sku' => 'hat-1' ] ] )['changed'], 'the product owns its SKU: only the case changes' );
        $this->assertTrue( $this->go( [ [ 'id' => 51, 'sku' => '' ] ] )['changed'], '"" clears the SKU' );
        $this->assertSame( '', $this->writer->products[51]['sku'] );
    }

    public function testCategoriesTagsAndImages(): void
    {
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'categories' => [ 'nope' ] ] ], 'dry_run' => true ], 'category "nope" does not exist' );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'tags' => [ 'nope' ] ] ], 'dry_run' => true ], 'tag "nope" does not exist' );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'image_id' => 5 ] ], 'dry_run' => true ], 'image_id 5 is not an image in the Media Library' );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'gallery_image_ids' => [ 90, 5 ] ] ], 'dry_run' => true ], 'gallery_image_ids 5 is not an image' );

        $same = $this->dry( [ [ 'id' => 50, 'categories' => [ 'shirts' ] ] ] );
        $this->assertSame( [ 50 ], $same['unchanged'], 'the same category (by id) is no change' );

        $r = $this->go( [ [ 'id' => 50, 'categories' => [ 'hats' ], 'tags' => [ 'sale' ], 'image_id' => 90, 'gallery_image_ids' => [ 91, 90 ] ] ] );
        $changes = $r['updated'][0]['changes'];
        ksort( $changes );
        $this->assertSame( [ 'categories' => [ 'before' => [ 'shirts' ], 'after' => [ 'hats' ] ], 'gallery_image_ids' => [ 'before' => [], 'after' => [ 91, 90 ] ], 'image_id' => [ 'before' => 0, 'after' => 90 ], 'tags' => [ 'before' => [], 'after' => [ 'sale' ] ] ], $changes );
        $this->assertSame( [ [ 4 ], [ 7 ], 90, [ 91, 90 ] ], [ $this->writer->products[50]['category_ids'], $this->writer->products[50]['tag_ids'], $this->writer->products[50]['image_id'], $this->writer->products[50]['gallery_image_ids'] ] );

        // images already on the product need no check (the user may lack upload_files); an empty category list clears
        $this->writer->images = [];
        $kept = $this->go( [ [ 'id' => 50, 'gallery_image_ids' => [ 90, 91 ], 'categories' => [] ] ] );
        $this->assertTrue( $kept['changed'] );
        $this->assertSame( [ [], [ 90, 91 ] ], [ $this->writer->products[50]['category_ids'], $this->writer->products[50]['gallery_image_ids'] ] );
    }

    // ---- inputs a person is likely to send ------------------------------------------------------

    public function testTheSameCategoriesInAnotherOrderAreNoChange(): void
    {
        $this->writer->products[50]['category_ids'] = [ 3, 4 ];
        $r = $this->dry( [ [ 'id' => 50, 'categories' => [ 'hats', 'shirts' ] ] ] );
        $this->assertSame( [ 50 ], $r['unchanged'] );
        $this->assertSame( [], $r['products'] );
    }

    public function testAnEntryWithOnlyAnIdChangesNothing(): void
    {
        $r = $this->dry( [ [ 'id' => 50 ] ] );
        $this->assertSame( [ 50 ], $r['unchanged'] );
        $this->assertArrayNotHasKey( 'token', $r );
    }

    public function testTheTokenDoesNotCareInWhatOrderTheKeysAreSent(): void
    {
        $token = $this->dry( [ [ 'id' => 50, 'regular_price' => '25', 'name' => 'Red Hat' ] ] )['token'];
        $r     = $this->tool->run( [ 'token' => $token, 'updates' => [ [ 'name' => 'Red Hat', 'regular_price' => '25', 'id' => 50 ] ] ] );
        $this->assertTrue( $r['changed'] );
    }

    public function testALongMultiByteNameNeverBreaksTheUtf8OfAMessage(): void
    {
        $name = str_repeat( 'é', 59 ) . '🙂🙂🙂';
        $this->writer->products[53] = $this->simple( [ 'name' => $name, 'sku' => 'U53' ] );
        try {
            $this->tool->run( [ 'updates' => [ [ 'id' => 53, 'sale_price' => '99' ] ], 'dry_run' => true ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( 1, preg_match( '//u', $e->getMessage() ), 'the message is valid UTF-8' );
            $this->assertStringContainsString( str_repeat( 'é', 59 ) . '🙂...', $e->getMessage() );
        }
    }

    // ---- skipped products -----------------------------------------------------------------------

    public function testAProductChangedSinceThePreviewIsSkippedAndTheOthersProceed(): void
    {
        $updates = [ [ 'id' => 50, 'regular_price' => '25' ], [ 'id' => 51, 'stock_status' => 'instock' ] ];
        $token   = $this->dry( $updates )['token'];
        $this->writer->products[50]['regular_price'] = '22'; // the owner
        $r = $this->tool->run( [ 'updates' => $updates, 'token' => $token ] );
        $this->assertSame( [ 51 ], array_column( $r['updated'], 'id' ) );
        $this->assertSame( [ [ 'id' => 50, 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
        $this->assertSame( '22', $this->writer->products[50]['regular_price'], 'never overwritten' );
        $this->assertCount( 1, array_values( $this->repo->rows )[0]['items'] );
    }

    public function testAChangeToAnyOtherFieldOrAVariationRowCountsAsChangedSinceThePreview(): void
    {
        $updates = [ [ 'id' => 50, 'regular_price' => '25' ], [ 'id' => 60, 'variations' => [ [ 'id' => 61, 'regular_price' => '11' ] ] ] ];
        $token   = $this->dry( $updates )['token'];
        $this->writer->products[50]['description'] = '<p>Edited</p>';
        $this->writer->products[60]['variations'][62]['sale_price'] = '7'; // a row the update does not name
        $r = $this->tool->run( [ 'updates' => $updates, 'token' => $token ] );
        $this->assertSame( [], $r['updated'] );
        $this->assertSame( [ 50, 60 ], array_column( $r['skipped'], 'id' ) );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 0, $this->writer->applyCalls );
        $this->assertSame( [], $this->repo->rows, 'nothing changed, nothing recorded' );
    }

    public function testAProductTrashedSinceThePreviewIsSkipped(): void
    {
        $updates = [ [ 'id' => 50, 'regular_price' => '25' ], [ 'id' => 51, 'stock_status' => 'instock' ] ];
        $token   = $this->dry( $updates )['token'];
        $GLOBALS['__wp_posts'][51] = (object) [ 'ID' => 51, 'post_status' => 'trash' ];
        $r = $this->tool->run( [ 'updates' => $updates, 'token' => $token ] );
        $this->assertSame( [ 50 ], array_column( $r['updated'], 'id' ) );
        $this->assertSame( [ [ 'id' => 51, 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
    }

    public function testAProductTheOwnerChangedSoThatItNowNeedsAChangeIsSkippedNotChanged(): void
    {
        $updates = [ [ 'id' => 50, 'name' => 'Blue Hat' ], [ 'id' => 51, 'stock_status' => 'instock' ] ];
        $preview = $this->dry( $updates );
        $this->assertSame( [ 50 ], $preview['unchanged'] );
        $this->writer->products[50]['name'] = 'Renamed by the owner'; // the update would now rename it back
        $r = $this->tool->run( [ 'updates' => $updates, 'token' => $preview['token'] ] );
        $this->assertSame( [ [ 'id' => 50, 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
        $this->assertSame( 'Renamed by the owner', $this->writer->products[50]['name'] );

        $again = [ [ 'id' => 50, 'name' => 'Renamed by the owner' ], [ 'id' => 51, 'weight' => '3' ] ];
        $p2    = $this->dry( $again );
        $r2    = $this->tool->run( [ 'updates' => $again, 'token' => $p2['token'] ] );
        $this->assertSame( [ 50 ], $r2['unchanged'] );
        $this->assertSame( [], $r2['skipped'] );
    }

    public function testARealRunWhoseEveryProductWasSkippedReportsItAndSpendsTheToken(): void
    {
        $updates = [ [ 'id' => 50, 'regular_price' => '25' ] ];
        $token   = $this->dry( $updates )['token'];
        $this->writer->products[50]['regular_price'] = '22';
        $r = $this->tool->run( [ 'updates' => $updates, 'token' => $token ] );
        $this->assertSame( [ 'changed' => false, 'dry_run' => false, 'updated' => [], 'skipped' => [ [ 'id' => 50, 'reason' => 'changed_since_preview' ] ], 'unchanged' => [], 'message' => 'Nothing was changed.' ], $r );
        $this->refused( [ 'updates' => $updates, 'token' => $token ], 'A real run needs the token', false );
    }

    // ---- variation rows -------------------------------------------------------------------------

    public function testVariationRowsAreChangedAndRecordedPerRow(): void
    {
        $r = $this->go( [ [ 'id' => 60, 'variations' => [ [ 'id' => 61, 'regular_price' => '11', 'stock_quantity' => 9 ], [ 'id' => 62, 'sale_price' => '8' ], [ 'id' => 63, 'stock_quantity' => 0 ] ] ] ] );
        $this->assertSame( [], $r['updated'][0]['changes'] );
        $this->assertSame( [ 61, 62 ], array_column( $r['updated'][0]['variations'], 'id' ), 'row 63 already has quantity 0: unchanged' );
        $this->assertSame( [ 'regular_price' => [ 'before' => '10', 'after' => '11' ], 'stock_quantity' => [ 'before' => 5, 'after' => 9 ] ], $r['updated'][0]['variations'][0]['changes'] );
        $v = $this->writer->products[60]['variations'];
        $this->assertSame( [ '11', 9, '8' ], [ $v[61]['regular_price'], $v[61]['stock_quantity'], $v[62]['sale_price'] ] );
        $item = array_values( $this->repo->rows )[0]['items'][0];
        $this->assertSame( [ 61 => [ 'regular_price' => '10', 'stock_quantity' => 5 ], 62 => [ 'sale_price' => '9' ] ], $item['before']['variations'] );
    }

    public function testVariationProblemsAreRefusedByRow(): void
    {
        $this->refused( [ 'updates' => [ [ 'id' => 60, 'variations' => [ [ 'id' => 999, 'regular_price' => '1' ] ] ] ], 'dry_run' => true ], 'variation 999 does not belong to this product' );
        $this->refused( [ 'updates' => [ [ 'id' => 60, 'variations' => [ [ 'id' => 61, 'sale_price' => '99' ] ] ] ], 'dry_run' => true ], 'variation 61: the resulting "sale_price" must be lower' );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'variations' => [ [ 'id' => 61, 'regular_price' => '1' ] ] ] ], 'dry_run' => true ], '"variations" is only for variable products' );
    }

    public function testAtMostTwoHundredVariationRowsPerCall(): void
    {
        foreach ( [ 90, 91, 92 ] as $id ) {
            $this->writer->products[ $id ] = $this->variable( 100, "Big {$id}", "BIG-{$id}" );
        }
        $rows = static function ( array $product ): array {
            $out = [];
            foreach ( array_keys( $product['variations'] ) as $vid ) {
                $out[] = [ 'id' => $vid, 'regular_price' => '99.5' ];
            }

            return $out;
        };
        $two   = [ [ 'id' => 90, 'variations' => $rows( $this->writer->products[90] ) ], [ 'id' => 91, 'variations' => $rows( $this->writer->products[91] ) ] ];
        $this->assertSame( 2, $this->dry( $two )['would_change'], '100 + 100 rows is allowed' );
        $three = array_merge( $two, [ [ 'id' => 92, 'variations' => $rows( $this->writer->products[92] ) ] ] );
        $this->refused( [ 'updates' => $three, 'dry_run' => true ], 'change 300 variation rows; the limit is 200 per call' );
    }

    // ---- what is stored for undo ----------------------------------------------------------------

    public function testATextTooLargeOrNotValidUtf8ToKeepForUndoIsNotChanged(): void
    {
        $this->writer->products[50]['description'] = str_repeat( 'x', 524289 );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'description' => 'short' ] ], 'dry_run' => true ], 'larger than 512 KB' );
        $this->assertSame( 1, $this->dry( [ [ 'id' => 50, 'regular_price' => '25' ] ] )['would_change'], 'a text the update does not touch is not kept, so its size does not matter' );

        $this->writer->products[50]['description'] = "caf\xE9 broken";
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'description' => 'fixed' ] ], 'dry_run' => true ], 'not valid UTF-8' );
    }

    public function testThePreviousTextsOfOneCallMayNotExceedOneMegabyte(): void
    {
        foreach ( [ 50, 51, 52 ] as $id ) {
            $this->writer->products[ $id ] = $this->simple( [ 'name' => "P{$id}", 'sku' => "S{$id}", 'description' => str_repeat( 'y', 400000 ) ] );
        }
        $updates = [ [ 'id' => 50, 'description' => 'a' ], [ 'id' => 51, 'description' => 'b' ], [ 'id' => 52, 'description' => 'c' ] ];
        $this->refused( [ 'updates' => $updates, 'dry_run' => true ], 'more than 1 MB; split the call' );
        $this->assertSame( 2, $this->dry( array_slice( $updates, 0, 2 ) )['would_change'] );
    }

    // ---- failure paths --------------------------------------------------------------------------

    private function threeUpdates(): array
    {
        return [ [ 'id' => 50, 'regular_price' => '25' ], [ 'id' => 51, 'stock_status' => 'instock', 'weight' => '2' ], [ 'id' => 60, 'variations' => [ [ 'id' => 61, 'regular_price' => '11' ] ] ] ];
    }

    /** @return array<int,array<string,mixed>> every stored product, to compare before and after */
    private function stored(): array
    {
        return $this->writer->products;
    }

    public function testAFailedSaveRestoresEveryProductThisRunChangedAndRecordsNoBatch(): void
    {
        $before = $this->stored();
        $this->writer->failApplyCalls = [ 3 ]; // the third product fails; 50 and 51 were already changed
        try {
            $p = $this->dry( $this->threeUpdates() );
            $this->tool->run( [ 'updates' => $this->threeUpdates(), 'token' => $p['token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $m = $e->getMessage();
            $this->assertStringContainsString( 'Could not update Product 3 (#60 "Tee"): WooCommerce could not save it.', $m );
            $this->assertStringContainsString( 'nothing was changed', $m );
            $this->assertStringNotContainsString( 'boom', $m );
            $this->assertStringNotContainsString( '/var/www', $m );
        }
        $this->assertEquals( $before, $this->stored(), 'every product is back exactly as it was' );
        $this->assertSame( [], $this->repo->rows, 'no batch' );
        $this->assertSame( 6, $this->writer->applyCalls, 'three saves (the third fails), then the three products are put back, newest first' );
    }

    public function testARestoreThatCannotFinishNamesTheProduct(): void
    {
        $this->writer->failApplyCalls = [ 2, 3 ]; // the second save fails, and the restore of the second (call 3) fails too
        $p = $this->dry( $this->threeUpdates() );
        try {
            $this->tool->run( [ 'updates' => $this->threeUpdates(), 'token' => $p['token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Could not update Product 2 (#51 "Plain Mug")', $e->getMessage() );
            $this->assertStringContainsString( 'may not be fully restored and should be checked: #51.', $e->getMessage() );
        }
        $this->assertSame( '20', $this->writer->products[50]['regular_price'], 'the first product was still put back' );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testASaveThatReadsBackDifferentlyIsRolledBack(): void
    {
        $before = $this->stored();
        $this->writer->afterApply = function ( int $id, array &$stored, int $call ): void {
            if ( 2 === $call ) {
                $stored['weight'] = '99'; // the store saved something else
            }
        };
        $p = $this->dry( $this->threeUpdates() );
        try {
            $this->tool->run( [ 'updates' => $this->threeUpdates(), 'token' => $p['token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Could not update Product 2 (#51 "Plain Mug"): it was saved differently than asked (weight).', $e->getMessage() );
        }
        $this->assertEquals( $before, $this->stored() );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testALoneBackordersChangeOnAManagedProductIsVerifiedOnReadBack(): void
    {
        $before = $this->stored();
        $this->writer->afterApply = function ( int $id, array &$stored, int $call ): void {
            if ( 1 === $call ) {
                $stored['backorders'] = 'no'; // the store did not keep it
            }
        };
        $updates = [ [ 'id' => 50, 'backorders' => 'notify' ] ];
        $p       = $this->dry( $updates );
        try {
            $this->tool->run( [ 'updates' => $updates, 'token' => $p['token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'saved differently than asked (backorders)', $e->getMessage() );
        }
        $this->assertEquals( $before, $this->stored() );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testALoneBackordersChangeOnAManagedVariationRowIsVerifiedOnReadBack(): void
    {
        $before = $this->stored();
        $this->writer->afterApply = function ( int $id, array &$stored, int $call ): void {
            if ( 1 === $call ) {
                $stored['variations'][61]['backorders'] = 'no';
            }
        };
        $updates = [ [ 'id' => 60, 'variations' => [ [ 'id' => 61, 'backorders' => 'notify' ] ] ] ];
        $p       = $this->dry( $updates );
        try {
            $this->tool->run( [ 'updates' => $updates, 'token' => $p['token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'saved differently than asked (variation 61 backorders)', $e->getMessage() );
        }
        $this->assertEquals( $before, $this->stored() );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testARollbackOfALoneBackordersChangeThatDoesNotStickNamesTheProduct(): void
    {
        $updates = [ [ 'id' => 50, 'backorders' => 'notify' ], [ 'id' => 51, 'stock_status' => 'instock' ] ];
        $this->writer->failApplyCalls = [ 2 ]; // the second save fails
        $this->writer->afterApply     = function ( int $id, array &$stored, int $call ): void {
            if ( 4 === $call ) { // the restore of product 50 (call 3 restores 51): the store keeps the new value
                $stored['backorders'] = 'notify';
            }
        };
        $p = $this->dry( $updates );
        try {
            $this->tool->run( [ 'updates' => $updates, 'token' => $p['token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Could not update Product 2 (#51 "Plain Mug")', $e->getMessage() );
            $this->assertStringContainsString( 'may not be fully restored and should be checked: #50.', $e->getMessage() );
        }
        $this->assertSame( [], $this->repo->rows );
    }

    public function testTheTermPermissionReasonIsReportedOnceWhenCategoriesAndTagsAreBothGiven(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_products' => true, 'edit_post' => true ];
        try {
            $this->dry( [ [ 'id' => 50, 'categories' => [ 'hats' ], 'tags' => [ 'sale' ] ] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( 1, substr_count( $e->getMessage(), 'assign_product_terms permission' ) );
            $this->assertStringStartsWith( 'Nothing was changed. 1 problem(s): ', $e->getMessage() );
        }
    }

    public function testAProductThatCannotBeReadBackIsReportedAndTheRunIsRolledBack(): void
    {
        $this->writer->afterApply = function ( int $id, array &$stored, int $call ): void {
            if ( 1 === $call ) {
                $this->writer->types[ $id ] = 'grouped'; // gone from the tool's point of view
            }
        };
        $p = $this->dry( [ [ 'id' => 50, 'regular_price' => '25' ] ] );
        try {
            $this->tool->run( [ 'updates' => [ [ 'id' => 50, 'regular_price' => '25' ] ], 'token' => $p['token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'could not be read back', $e->getMessage() );
            $this->assertStringContainsString( 'should be checked: #50', $e->getMessage(), 'the restore cannot be verified either' );
        }
        $this->assertSame( [], $this->repo->rows );
    }

    public function testOnlyOurOwnMessagesAndWooCommerceDataExceptionsReachTheClient(): void
    {
        $cases = [
            'data exception' => [ static fn () => new \WC_Data_Exception( 'product_invalid_sku', 'Invalid or duplicated SKU.' ), 'Invalid or duplicated SKU.' ],
            'our own'        => [ static fn () => new ProductSaveFailed( 'the saved product could not be read back' ), 'the saved product could not be read back' ],
            'foreign'        => [ static fn () => new \RuntimeException( 'SQL error in /var/www/html/wp-includes/x.php' ), 'WooCommerce could not save it' ],
        ];
        foreach ( $cases as $label => [ $make, $expected ] ) {
            $this->setUp();
            $this->writer->failApplyCalls     = [ 1 ];
            $this->writer->failApplyException = $make;
            $p = $this->dry( [ [ 'id' => 50, 'regular_price' => '25' ] ] );
            try {
                $this->tool->run( [ 'updates' => [ [ 'id' => 50, 'regular_price' => '25' ] ], 'token' => $p['token'] ] );
                $this->fail( "{$label}: expected a refusal" );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( $expected, $e->getMessage(), $label );
                $this->assertStringNotContainsString( '/var/www', $e->getMessage(), $label );
            }
        }
    }

    // ---- undo, end to end -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function undoBatch( int $id, bool $force = false ): array
    {
        return ( new BatchUndo( [ 'product_fields' => new ProductFieldsKind( $this->writer ) ], $this->log ) )->undo( $id, $force );
    }

    public function testOneUndoPutsEveryPreviousValueBackAndAFieldEditedSinceIsSkipped(): void
    {
        $before = $this->stored();
        $r      = $this->go( $this->threeUpdates() );
        $this->assertNotEquals( $before, $this->stored() );
        $this->writer->products[51]['weight'] = '5'; // the owner edits a field the run changed
        $u = $this->undoBatch( (int) $r['batch']['id'] );
        $this->assertSame( 2, $u['restored'] );
        $this->assertSame( [ 'restored', 'changed_since', 'restored' ], array_column( $u['items'], 'result' ) );
        $this->assertSame( '20', $this->writer->products[50]['regular_price'] );
        $this->assertSame( '10', $this->writer->products[60]['variations'][61]['regular_price'] );
        $this->assertSame( '5', $this->writer->products[51]['weight'], 'the owner\'s value stays' );
        $this->assertFalse( $u['fully_undone'] );

        $forced = $this->undoBatch( (int) $r['batch']['id'], true );
        $this->assertSame( 1, $forced['restored'] );
        $this->assertSame( '', $this->writer->products[51]['weight'] );
    }
}
