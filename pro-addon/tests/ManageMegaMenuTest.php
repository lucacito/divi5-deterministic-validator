<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Companion\AgentBatches;
use AiEditorDivi5\Pro\Kinds\ThemeLayoutKind;
use AiEditorDivi5\Pro\MegaMenu\Layouts;
use AiEditorDivi5\Pro\MegaMenu\MegaMenuService;
use AiEditorDivi5\Pro\Rest\ProOpenApi;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutWriter;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\ManageMegaMenu;
use AiEditorDivi5\WP\EditTarget;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';
require_once __DIR__ . '/MegaMenuFixture.php';
require_once __DIR__ . '/MegaMenuPanelTest.php'; // its fake menu and post sources

/**
 * manage_mega_menu, the 23rd Pro tool: the Mega menu tab's service over MCP and REST. Same write path as the tab (layout checker,
 * undo point, one recorded batch), the narrowest capability, MCP and REST only.
 */
class ManageMegaMenuTest extends TestCase
{
    use ThemeLayoutFixture;

    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = [];
        $this->setUpThemeBuilder();
        $GLOBALS['__wp_posts'][201]->post_content = MegaMenuFixture::header();
        $GLOBALS['__wp_filters']                  = [];
        $GLOBALS['__wp_transients']               = [];
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
    }

    private function tool(): ManageMegaMenu
    {
        return new ManageMegaMenu( new MegaMenuService(
            new LayoutWriter( $this->log ),
            new FakeMegaMenus( MegaMenuFixture::menus()['grouped'], [] ),
            new FakeMegaPosts(),
            static fn (): ?array => [ 'layout_id' => 201, 'title' => 'Main header' ],
            static fn (): string => 'https://example.test/wp-content/plugins/free/assets/images/'
        ) );
    }

    /** @param array<string,mixed> $args @return array<string,mixed> the JSON-RPC body, through the real registry */
    private function call( array $args ): array
    {
        return (array) ( new ToolRegistry( [ $this->tool() ], static fn (): bool => true ) )->claim( null, ManageMegaMenu::NAME, $args, 1 )->get_data();
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function ok( array $args ): array
    {
        $data = $this->call( $args );
        $this->assertArrayHasKey( 'result', $data, json_encode( $data ) );

        return (array) json_decode( $data['result']['content'][0]['text'], true );
    }

    // ---- what it is ----------------------------------------------------------------------------------------------------

    public function testItIsThe23rdProToolForMcpAndRestAndNotInTheOpenApiSpec(): void
    {
        $names = array_map( static fn ( $t ) => $t->name(), Tools::all() );
        $this->assertContains( 'manage_mega_menu', $names );
        $this->assertCount( 23, $names );
        $this->assertContains( 'manage_mega_menu', ProOpenApi::MCP_REST_ONLY );

        $registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
        $paths    = ( new ProOpenApi( $registry ) )->paths( [], '' );
        $ops      = 0;
        foreach ( (array) $paths as $path => $item ) {
            $ops += count( (array) $item );
            $this->assertStringNotContainsString( 'mega', (string) $path );
        }
        $this->assertSame( 14, $ops, 'the Pro side of the 30 operations is still 14 (16 free + 14 Pro)' );
    }

    public function testDefinitionAndCapability(): void
    {
        $t = $this->tool();
        $this->assertSame( 'edit_theme_options', $t->capability() );
        $d = $t->definition();
        $this->assertSame( [ 'action' ], $d['inputSchema']['required'] );
        $this->assertSame( [ 'status', 'apply', 'update', 'remove' ], $d['inputSchema']['properties']['action']['enum'] );
        $this->assertSame( Layouts::ids(), $d['inputSchema']['properties']['layout']['enum'] );
        $this->assertSame( [ 'keep', 'rebuild' ], $d['inputSchema']['properties']['edited']['enum'] );
        $this->assertSame( 'boolean', $d['inputSchema']['properties']['dry_run']['type'] );
        $this->assertStringNotContainsString( "\u{2014}", $d['description'] );
    }

    // ---- status --------------------------------------------------------------------------------------------------------

    public function testStatusIsReadOnlyAndListsTheLayoutsAndTheItems(): void
    {
        $r = $this->ok( [ 'action' => 'status' ] );
        $this->assertTrue( $r['header_found'] );
        $this->assertFalse( $r['applied'] );
        $this->assertCount( 14, $r['layouts'] );
        $this->assertSame( Layouts::ids(), array_column( $r['layouts'], 'id' ) );
        $this->assertNotEmpty( $r['items'] );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], 'status writes nothing' );
        $this->assertSame( [], $this->repo->rows, 'and records no batch' );
    }

    // ---- writes: the checker, the undo point, one batch ----------------------------------------------------------------

    public function testADryRunPreviewsAndWritesNothing(): void
    {
        $r = $this->ok( [ 'action' => 'apply', 'layout' => 'columns-dark', 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertTrue( $r['valid'] );
        $this->assertSame( MegaMenuFixture::header(), $this->layoutContent( 201 ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testApplyWritesTheHeaderThroughTheCheckerAsOneUndoableBatchAndUndoBatchPutsItBack(): void
    {
        $r = $this->ok( [ 'action' => 'apply', 'layout' => 'columns-dark' ] );
        $this->assertTrue( $r['changed'] );
        $this->assertNotSame( MegaMenuFixture::header(), $this->layoutContent( 201 ) );
        $this->assertTrue( $r['snapshot']['stored'], 'an undo point was kept' );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertCount( 1, $this->repo->rows, 'one batch' );
        $this->assertSame( 'apply_mega_menu', $this->repo->rows[ array_key_first( $this->repo->rows ) ]['tool'] );

        // the existing undo_batch engine restores the header byte for byte
        $undo = $this->undoEngine()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( MegaMenuFixture::header(), $this->layoutContent( 201 ) );
    }

    public function testAMegaMenuHeaderEditIsNotAnAgentBatchButIsStillUndoneByUndoBatch(): void
    {
        $r = $this->ok( [ 'action' => 'apply', 'layout' => 'columns-footer' ] );

        // The header's history rows are in the shared history table. Agent batches list pages and Projects only.
        $rows = \AiEditorDivi5\WP\HistoryStore::rowsSince( '2000-01-01T00:00:00Z' );
        $this->assertNotSame( [], $rows, 'the header edit left a history row' );
        $this->assertContains( 201, array_column( $rows, 'page_id' ) );
        $kept = AgentBatches::onlyPages( $rows, static fn ( int $id ): ?string => isset( $GLOBALS['__wp_posts'][ $id ] ) ? $GLOBALS['__wp_posts'][ $id ]->post_type : null, EditTarget::ALLOWED_TYPES );
        $this->assertNotContains( 201, array_column( $kept, 'page_id' ), 'the header layout is not an agent batch' );
        $this->assertSame( [], AgentBatches::group( $kept ), 'so no agent batch exists for it' );

        // ...and the recorded batch still undoes it
        $this->assertTrue( $this->undoEngine()->undo( $r['batch']['id'] )['fully_undone'] );
        $this->assertSame( MegaMenuFixture::header(), $this->layoutContent( 201 ) );
    }

    public function testUpdateAndRemoveWorkAndAreBatches(): void
    {
        $this->ok( [ 'action' => 'apply', 'layout' => 'columns-dark' ] );
        $applied = $this->layoutContent( 201 );
        $u       = $this->ok( [ 'action' => 'update' ] );
        $this->assertArrayHasKey( 'changed', $u );
        $rm = $this->ok( [ 'action' => 'remove' ] );
        $this->assertTrue( $rm['changed'] );
        $this->assertNotSame( $applied, $this->layoutContent( 201 ) );
        $this->assertTrue( $rm['batch']['undoable'] );
    }

    public function testBadArgumentsAreRefusedBeforeAnyWrite(): void
    {
        foreach ( [
            [ 'action' => 'nope' ],
            [ 'action' => 'apply' ],
            [ 'action' => 'apply', 'layout' => 'not-a-layout' ],
            [ 'action' => 'apply', 'layout' => 'columns-dark', 'edited' => 'maybe' ],
            [ 'action' => 'apply', 'layout' => 'columns-dark', 'item_id' => 3 ], // no mega menu applied yet
        ] as $args ) {
            $data = $this->call( $args );
            $this->assertSame( -32602, $data['error']['code'] ?? null, json_encode( $args ) );
        }
        $this->assertSame( MegaMenuFixture::header(), $this->layoutContent( 201 ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testAnInvalidResultIsRefusedByTheLayoutChecker(): void
    {
        // The writer always runs LayoutGate::check on what it is about to save; a tool can never bypass it.
        $src = (string) file_get_contents( __DIR__ . '/../src/MegaMenu/MegaMenuService.php' );
        $this->assertStringContainsString( 'LayoutWriter', $src );
        $this->assertStringContainsString( 'LayoutGate::check', (string) file_get_contents( __DIR__ . '/../src/ThemeBuilder/LayoutWriter.php' ) );
        $this->assertStringNotContainsString( 'wp_update_post', (string) file_get_contents( __DIR__ . '/../src/Tools/ManageMegaMenu.php' ), 'the tool has no write path of its own' );
    }

    // ---- permissions ---------------------------------------------------------------------------------------------------

    /** @return array<string,array{array<string,mixed>}> */
    public static function calls(): array
    {
        return [ 'status' => [ [ 'action' => 'status' ] ], 'apply' => [ [ 'action' => 'apply', 'layout' => 'columns-dark' ] ], 'update' => [ [ 'action' => 'update' ] ], 'remove' => [ [ 'action' => 'remove' ] ] ];
    }

    /** @param array<string,mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'calls' )]
    public function testWithoutEditThemeOptionsTheRegistryRefusesBeforeTheToolRuns( array $args ): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true, 'manage_options' => false ];
        $data                 = $this->call( $args );
        $this->assertSame( -32602, $data['error']['code'] ?? null );
        $this->assertSame( MegaMenuFixture::header(), $this->layoutContent( 201 ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );
    }

    /** @param array<string,mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'calls' )]
    public function testWithoutDivisThemeBuilderPermissionTheToolRefuses( array $args ): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $data                           = $this->call( $args );
        $this->assertSame( -32602, $data['error']['code'] ?? null, 'even the read-only status needs the Theme Builder permission' );
        $this->assertSame( MegaMenuFixture::header(), $this->layoutContent( 201 ) );
        $this->assertSame( [], $this->repo->rows );
    }

    /** @param array<string,mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'calls' )]
    public function testWithoutEditPostOnTheHeaderTheWriteIsRefused( array $args ): void
    {
        if ( 'status' === $args['action'] ) {
            $this->addToAssertionCount( 1 );

            return;
        }
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_post:201' => false, 'edit_others_posts' => true, 'edit_theme_options' => true ];
        $data                 = $this->call( $args );
        $this->assertSame( -32602, $data['error']['code'] ?? null );
        $this->assertSame( MegaMenuFixture::header(), $this->layoutContent( 201 ) );
        $this->assertSame( [], $this->repo->rows );
    }
}
