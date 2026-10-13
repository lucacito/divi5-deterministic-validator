<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/SiteStructureShims.php';
require_once __DIR__ . '/CustomFieldsReadShims.php';
require_once __DIR__ . '/WooShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/FieldTarget.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFieldReader.php';
require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/SiteStructure.php';
require_once __DIR__ . '/../wp-plugin/src/ProductReader.php';
require_once __DIR__ . '/../wp-plugin/src/ProductQuery.php';
require_once __DIR__ . '/../wp-plugin/src/ProductGateway.php';
require_once __DIR__ . '/../wp-plugin/src/WooProductGateway.php';
require_once __DIR__ . '/../wp-plugin/src/ProductService.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** list_products / get_product: same meaning in MCP and REST, read-only, and deliberately NOT in the ChatGPT OpenAPI spec. */
class ProductsLockstepTest extends TestCase
{
    private function product( int $id, array $over = [] ): \FakeWcProduct
    {
        return new \FakeWcProduct( $over + [
            'id' => $id, 'name' => "Product {$id}", 'type' => 'simple', 'status' => 'publish', 'sku' => "SKU{$id}", 'price' => (string) ( $id * 10 ),
            'regular_price' => (string) ( $id * 10 ), 'sale_price' => '', 'on_sale' => false, 'stock_status' => 'instock', 'manage_stock' => false,
            'stock_quantity' => null, 'category_ids' => [ 3 ], 'tag_ids' => [], 'image_id' => 0, 'gallery_image_ids' => [], 'attributes' => [],
            'children' => [], 'upsell_ids' => [], 'cross_sell_ids' => [], 'weight' => '', 'shipping_class' => '', 'virtual' => false,
            'downloadable' => false, 'catalog_visibility' => 'visible', 'average_rating' => '0', 'review_count' => 0, 'tax_status' => 'taxable',
            'tax_class' => '', 'sold_individually' => false, 'backorders' => 'no', 'low_stock_amount' => null, 'description' => 'Desc', 'short_description' => 'Short',
            'featured' => false, 'post_password' => '', 'date_created' => null, 'date_modified' => null,
            'downloads' => [ [ 'file' => 'https://shop.test/SECRET-DOWNLOAD.zip' ] ], 'purchase_note' => 'SECRET-PURCHASE-NOTE',
        ] );
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wc_terms']    = [ 'product_cat' => [ 3 => [ 'name' => 'Clothing', 'slug' => 'clothing' ] ], 'product_type' => [ 1 => [ 'name' => 'simple', 'slug' => 'simple', 'count' => 3 ] ], 'product_tag' => [] ];
        $GLOBALS['__wc_counts']   = [ 'publish' => 3, 'draft' => 1 ];
        $GLOBALS['__wc_products'] = [ 1 => $this->product( 1 ), 2 => $this->product( 2, [ 'name' => 'Locked', 'post_password' => 'pw', 'description' => 'SECRET-DESC' ] ), 3 => $this->product( 3 ), 4 => $this->product( 4, [ 'status' => 'draft' ] ) ];
        $GLOBALS['__wp_caps']     = [ 'edit_pages' => true, 'edit_products' => true, 'edit_others_products' => true, 'edit_post' => true ];
        $GLOBALS['__wp_posts']    = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wc_products'], $GLOBALS['__wc_terms'], $GLOBALS['__wc_counts'] );
        $GLOBALS['__wp_can'] = true;
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    private function mcpCall( string $tool, array $args ): array
    {
        $req = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ?: new \stdClass() ] ] );

        return (array) ( new McpHandler() )->handle( $req )->get_data();
    }

    private function restList( array $params )
    {
        return ( new RestController() )->list_products( new \WP_REST_Request( [], $params ) );
    }

    private function restGet( array $params )
    {
        return ( new RestController() )->get_product( new \WP_REST_Request( [], $params ) );
    }

    public function testMcpDeclaresAndDispatchesBothTools(): void
    {
        $mcp = $this->src( 'McpHandler.php' );
        foreach ( [ 'list_products' => 'toolListProducts', 'get_product' => 'toolGetProduct' ] as $tool => $method ) {
            $this->assertStringContainsString( "'name'        => '{$tool}'", $mcp );
            $this->assertMatchesRegularExpression( "/'{$tool}'\s*=>\s*\\\$this->{$method}/", $mcp );
        }
    }

    public function testRestRegistersBothRoutesAsReadOnly(): void
    {
        $rest = $this->src( 'RestController.php' );
        $this->assertMatchesRegularExpression( "#'/products',\s*\[\s*'methods'\s*=>\s*WP_REST_Server::READABLE#", $rest );
        $this->assertMatchesRegularExpression( '#/products/\(\?P<id>\\\\d\+\)\',\s*\[\s*\'methods\'\s*=>\s*WP_REST_Server::READABLE#', $rest );
    }

    public function testNeitherToolIsInTheChatGptSpecOnPurpose(): void
    {
        // The importer allows 30 operations and the free (16) plus Pro (14) tools already use all 30 (owner decision 2026-10-04).
        $json = json_encode( OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' ) );
        foreach ( [ 'list_products', 'get_product', '/products' ] as $needle ) {
            $this->assertStringNotContainsString( $needle, $json );
        }
    }

    public function testRestAndMcpReturnTheSameListAndTheSameProduct(): void
    {
        $rest = $this->restList( [ 'per_page' => '2' ] );
        $this->assertSame( 200, $rest->get_status() );
        $mcp = $this->mcpCall( 'list_products', [ 'per_page' => 2 ] );
        $this->assertArrayNotHasKey( 'error', $mcp );
        $this->assertSame( (array) $rest->get_data(), json_decode( $mcp['result']['content'][0]['text'], true ) );
        $d = (array) $rest->get_data();
        $this->assertSame( 2, count( $d['products'] ) );
        $this->assertSame( 4, $d['total_matching'] );
        $this->assertSame( 2, $d['total_pages'] );
        $this->assertSame( 4, $d['totals']['all'] );

        $restOne = $this->restGet( [ 'id' => '1' ] );
        $this->assertSame( 200, $restOne->get_status() );
        $mcpOne = $this->mcpCall( 'get_product', [ 'id' => 1 ] );
        $this->assertSame( (array) $restOne->get_data(), json_decode( $mcpOne['result']['content'][0]['text'], true ) );
    }

    public function testNothingPrivateReachesEitherSurface(): void
    {
        $all = json_encode( [
            $this->restList( [ 'per_page' => '50' ] )->get_data(), $this->restGet( [ 'id' => '1' ] )->get_data(), $this->restGet( [ 'id' => '2' ] )->get_data(),
            $this->mcpCall( 'get_product', [ 'id' => 2 ] ),
        ] );
        foreach ( [ 'SECRET-DOWNLOAD', 'SECRET-PURCHASE-NOTE', 'SECRET-DESC' ] as $needle ) {
            $this->assertStringNotContainsString( $needle, $all );
        }
    }

    public function testBadInputIsRejectedNeverCastOrClamped(): void
    {
        foreach ( [ [ 'per_page' => '0' ], [ 'per_page' => '51' ], [ 'page' => 'abc' ], [ 'on_sale' => 'yes' ], [ 'status' => 'trash' ] ] as $bad ) {
            $r = $this->restList( $bad );
            $this->assertInstanceOf( \WP_Error::class, $r );
            $this->assertSame( 400, $r->data['status'], json_encode( $bad ) );
        }
        foreach ( [ [ 'per_page' => 51 ], [ 'on_sale' => 'true' ], [ 'page' => '2' ], [ 'status' => 'trash' ] ] as $bad ) {
            $e = $this->mcpCall( 'list_products', $bad );
            $this->assertSame( -32602, $e['error']['code'], json_encode( $bad ) );
        }
        foreach ( [ '5', 5.5, true, [ 5 ], 0, -1, null ] as $badId ) {
            $e = $this->mcpCall( 'get_product', [ 'id' => $badId ] );
            $this->assertSame( -32602, $e['error']['code'], 'id ' . json_encode( $badId ) );
        }
    }

    public function testAnEditorWithoutProductPermissionsGetsAClearRefusal(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'edit_post' => true, 'edit_products' => false, 'edit_others_products' => false ];
        $r = $this->restList( [] );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 403, $r->data['status'] );
        $this->assertStringContainsString( 'edit_others_products', $r->get_error_message() );
        $e = $this->mcpCall( 'list_products', [] );
        $this->assertSame( -32602, $e['error']['code'] );
        $this->assertStringContainsString( 'edit_others_products', $e['error']['message'] );
    }

    public function testMissingVariationAndNotEditableAllAnswerAsNotFound(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'edit_products' => true, 'edit_others_products' => true, 'edit_post' => true, 'edit_post:3' => false ];
        $GLOBALS['__wc_products'][9] = $this->product( 9, [ 'post_type' => 'product_variation', 'type' => 'variation' ] );
        $messages = [];
        foreach ( [ '999', '9', '3' ] as $id ) {
            $r = $this->restGet( [ 'id' => $id ] );
            $this->assertInstanceOf( \WP_Error::class, $r );
            $this->assertSame( 404, $r->data['status'], "product {$id}" );
            $e = $this->mcpCall( 'get_product', [ 'id' => (int) $id ] );
            $this->assertSame( -32602, $e['error']['code'] );
            $this->assertSame( "Product {$id} not found.", $e['error']['message'] );
            $messages[] = str_replace( $id, 'N', $r->get_error_message() );
        }
        $this->assertCount( 1, array_unique( $messages ) );
    }

    public function testKeyUserWithoutEditPagesIsRefusedBeforeAnythingElse(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => false, 'edit_products' => true, 'edit_others_products' => true ];
        $this->assertSame( 403, $this->restList( [] )->data['status'] );
        $this->assertSame( 403, $this->restGet( [ 'id' => '1' ] )->data['status'] );
        $this->assertSame( -32602, $this->mcpCall( 'list_products', [] )['error']['code'] );
    }
}
