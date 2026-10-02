<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/WpInsertPostShims.php';
require_once __DIR__ . '/DiviGlobalDataShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/**
 * The style-reference advisory through the REAL REST and MCP handlers: present on validate, update,
 * edit, create and every dry-run preview exactly when a layout cites an id the site does not have;
 * absent (response unchanged) otherwise; and never able to change a verdict or a save.
 */
class StyleAdvisoryHandlersTest extends TestCase
{
    private const KNOWN   = 'b5bdfe4f-ac6a-4338-974b-680f73f018f9';
    private const UNKNOWN = '11111111-2222-4333-8444-555555555555';
    private const COLOR   = 'gcid-3e93bdc5-146d-448e-a761-9f1b4dd51113';
    private const NO_COLOR = 'gcid-00000000-0000-4000-8000-000000000000';

    private function layout( string $text, array $extra = [] ): string
    {
        $attrs = $extra + [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $text ] ] ], 'builderVersion' => '5.14.0' ];

        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading '
            . json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP )
            . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    private function clean(): string
    {
        return $this->layout( 'Hello', [ 'modulePreset' => [ self::KNOWN ] ] );
    }

    private function dirty( string $text = 'Hello' ): string
    {
        return $this->layout( $text, [ 'modulePreset' => [ self::UNKNOWN ] ] );
    }

    private function invalid(): string
    {
        return str_replace( 'wp:divi/heading', 'wp:divi/not-a-module', $this->dirty() );
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']      = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $post                         = new \WP_Post();
        $post->ID                     = 5;
        $post->post_content           = $this->dirty( 'Hi' );
        $GLOBALS['__wp_posts']        = [ 5 => $post ];
        $GLOBALS['__wp_postmeta']     = [];
        $GLOBALS['__wp_update_fail']  = false;
        $GLOBALS['__wp_update_calls'] = 0;
        $GLOBALS['__wp_inserts']      = [];
        $GLOBALS['__wp_caps']         = [ 'edit_post' => true, 'edit_pages' => true, 'publish_pages' => true ];
        $GLOBALS['__divi_presets']    = [ 'module' => [ 'divi/heading' => [ 'default' => '', 'items' => [ self::KNOWN => [ 'id' => self::KNOWN, 'name' => 'Heading 3' ] ] ] ] ];
        $GLOBALS['__divi_colors']     = [ self::COLOR => [ 'color' => '#ff4d00', 'status' => 'active' ] ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        $GLOBALS['__wp_options'] = [];
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_presets'], $GLOBALS['__divi_colors'] );
        $GLOBALS['__wp_can'] = true;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( string $method, array $body, array $params = [] ): array
    {
        $r = ( new RestController() )->$method( new \WP_REST_Request( $body, $params ) );
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array<string,mixed> */
    private function mcp( string $tool, array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        $this->assertArrayNotHasKey( 'error', $data );

        return (array) json_decode( $data['result']['content'][0]['text'], true );
    }

    private function assertAdvisory( array $payload, string $id ): void
    {
        $this->assertArrayHasKey( 'advisories', $payload );
        $this->assertSame( $id, $payload['advisories']['style_references']['unknown_presets'][0]['id'] );
        $this->assertSame( \AiEditorDivi5\WP\StyleReferences::MESSAGE, $payload['advisories']['style_references']['message'] );
    }

    public function testValidateAddsTheAdvisoryOnlyForUnknownIdsAndKeepsTheVerdict(): void
    {
        [ $st, $rest ] = $this->rest( 'validate', [ 'post_content' => $this->dirty() ] );
        $this->assertSame( 200, $st );
        $this->assertTrue( $rest['valid'] );
        $this->assertAdvisory( $rest, self::UNKNOWN );
        $mcp = $this->mcp( 'validate_layout', [ 'post_content' => $this->dirty() ] );
        $this->assertTrue( $mcp['valid'] );
        $this->assertAdvisory( $mcp, self::UNKNOWN );

        [ , $clean ] = $this->rest( 'validate', [ 'post_content' => $this->clean() ] );
        $this->assertArrayNotHasKey( 'advisories', $clean );
        $this->assertArrayNotHasKey( 'advisories', $this->mcp( 'validate_layout', [ 'post_content' => $this->clean() ] ) );
    }

    public function testAnUnknownColorIsReportedToo(): void
    {
        $content = $this->layout( 'Hello', [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Hello' ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => '$variable({"type":"color","value":{"name":"' . self::NO_COLOR . '","settings":{}}})$' ] ] ] ] ] ] ] );
        $mcp = $this->mcp( 'validate_layout', [ 'post_content' => $content ] );
        $this->assertTrue( $mcp['valid'] );
        $this->assertSame( self::NO_COLOR, $mcp['advisories']['style_references']['unknown_colors'][0]['id'] );
    }

    public function testDryRunPreviewsCarryTheAdvisoryAndSaveNothing(): void
    {
        $before = $GLOBALS['__wp_posts'][5]->post_content;
        [ , $rest ] = $this->rest( 'update_page', [ 'post_content' => $this->dirty( 'New' ), 'dry_run' => true ], [ 'id' => 5 ] );
        $this->assertTrue( $rest['dry_run'] );
        $this->assertAdvisory( $rest, self::UNKNOWN );
        $mcp = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $this->dirty( 'New' ), 'dry_run' => true ] );
        $this->assertAdvisory( $mcp, self::UNKNOWN );

        [ , $edit ] = $this->rest( 'edit_page', [ 'find' => 'Hi', 'replace' => 'Yo', 'dry_run' => true ], [ 'id' => 5 ] );
        $this->assertAdvisory( $edit, self::UNKNOWN );
        $mcpEdit = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => 'Hi', 'replace' => 'Yo', 'dry_run' => true ] );
        $this->assertAdvisory( $mcpEdit, self::UNKNOWN );

        $this->assertSame( $before, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testAnAdvisoryNeverBlocksOrChangesARealSave(): void
    {
        [ $st, $rest ] = $this->rest( 'update_page', [ 'post_content' => $this->dirty( 'Saved via REST' ) ], [ 'id' => 5 ] );
        $this->assertSame( 200, $st );
        $this->assertTrue( $rest['saved'] );
        $this->assertTrue( $rest['valid'] );
        $this->assertAdvisory( $rest, self::UNKNOWN );
        $this->assertStringContainsString( 'Saved via REST', $GLOBALS['__wp_posts'][5]->post_content );

        $mcp = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $this->dirty( 'Saved via MCP' ) ] );
        $this->assertTrue( $mcp['saved'] );
        $this->assertAdvisory( $mcp, self::UNKNOWN );
        $this->assertStringContainsString( 'Saved via MCP', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testEditsThatSaveCarryTheAdvisory(): void
    {
        [ , $rest ] = $this->rest( 'edit_page', [ 'find' => 'Hi', 'replace' => 'Yo' ], [ 'id' => 5 ] );
        $this->assertTrue( $rest['saved'] );
        $this->assertAdvisory( $rest, self::UNKNOWN );
        $mcp = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => 'Yo', 'replace' => 'Ha' ] );
        $this->assertTrue( $mcp['saved'] );
        $this->assertAdvisory( $mcp, self::UNKNOWN );
    }

    public function testARefusedSaveReportsViolationsOnlyNoAdvisory(): void
    {
        [ $st, $rest ] = $this->rest( 'update_page', [ 'post_content' => $this->invalid() ], [ 'id' => 5 ] );
        $this->assertSame( 422, $st );
        $this->assertFalse( $rest['saved'] );
        $this->assertArrayNotHasKey( 'advisories', $rest );
        $mcp = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $this->invalid() ] );
        $this->assertFalse( $mcp['saved'] );
        $this->assertArrayNotHasKey( 'advisories', $mcp );
    }

    public function testCreatePageCarriesTheAdvisoryOnlyWhenNeeded(): void
    {
        [ $st, $rest ] = $this->rest( 'create_page', [ 'title' => 'T', 'post_content' => $this->dirty() ] );
        $this->assertSame( 201, $st );
        $this->assertAdvisory( $rest, self::UNKNOWN );
        $mcp = $this->mcp( 'create_page', [ 'title' => 'T2', 'post_content' => $this->dirty() ] );
        $this->assertTrue( $mcp['created'] );
        $this->assertAdvisory( $mcp, self::UNKNOWN );

        [ , $clean ] = $this->rest( 'create_page', [ 'title' => 'T3', 'post_content' => $this->clean() ] );
        $this->assertArrayNotHasKey( 'advisories', $clean );
        $this->assertArrayNotHasKey( 'advisories', $this->mcp( 'create_page', [ 'title' => 'T4', 'post_content' => $this->clean() ] ) );
    }

    public function testWithoutDiviDataEverythingWorksAndNothingIsFlagged(): void
    {
        $GLOBALS['__divi_presets'] = null;
        $GLOBALS['__divi_colors']  = null;
        $mcp = $this->mcp( 'validate_layout', [ 'post_content' => $this->dirty() ] );
        $this->assertTrue( $mcp['valid'] );
        $this->assertArrayNotHasKey( 'advisories', $mcp );
        $save = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $this->dirty( 'Still saves' ) ] );
        $this->assertTrue( $save['saved'] );
        $this->assertArrayNotHasKey( 'advisories', $save );
    }
}
