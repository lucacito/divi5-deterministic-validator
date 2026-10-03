<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\MenuKind;
use AiEditorDivi5\Pro\Settings\MenuBuilder;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\SetPrimaryMenu;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class SetPrimaryMenuTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private SetPrimaryMenu $tool;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->tool = new SetPrimaryMenu( $this->log );
        $GLOBALS['__wp_menus']            = [];
        $GLOBALS['__wp_menu_items']       = [];
        $GLOBALS['__wp_theme_mods']       = [];
        $GLOBALS['__wp_menu_item_fail_at'] = 0;
        $GLOBALS['__wp_menu_item_calls']   = 0;
        $GLOBALS['__wp_registered_menus'] = [ 'primary-menu' => 'Primary Menu' ];
        $GLOBALS['__wp_posts']            = [
            5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => '' ],
            6 => (object) [ 'ID' => 6, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_content' => '' ],
            7 => (object) [ 'ID' => 7, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Soon', 'post_content' => '' ],
            8 => (object) [ 'ID' => 8, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Post', 'post_content' => '' ],
        ];
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ 'menu' => new MenuKind() ], $this->log );
    }

    private function fails( array $args, string $message ): void
    {
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( [], $GLOBALS['__wp_menus'], 'no menu was created' );
        $this->assertSame( [], $this->repo->rows );
    }

    private function items(): array
    {
        return [ [ 'title' => 'Home', 'page_id' => 5 ], [ 'title' => 'About', 'page_id' => 6 ], [ 'title' => 'Shop', 'url' => 'https://example.com/shop' ] ];
    }

    public function testItBuildsTheMenuAssignsTheLocationAndRecordsABatch(): void
    {
        $r = $this->tool->run( [ 'items' => $this->items() ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 3, $r['items'] );
        $this->assertSame( 'primary-menu', $r['location'] );
        $this->assertSame( [ 'Home', 'About', 'Shop' ], array_column( MenuBuilder::snapshot( 'primary-menu' )['items'], 'title' ) );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( 'primary-menu', $this->repo->rows[1]['items'][0]['ref'] );
    }

    public function testTitlesAreSanitisedAndAPageIdWinsOverAUrl(): void
    {
        $this->tool->run( [ 'items' => [ [ 'title' => '<b>Home</b><script>x()</script>', 'page_id' => 5, 'url' => 'https://ignored.example' ] ] ] );
        $item = MenuBuilder::snapshot( 'primary-menu' )['items'][0];
        $this->assertSame( 'Home', $item['title'] );
        $this->assertSame( 'post_type', $item['type'] );
    }

    public function testBadItemsAreRejectedWithTheItemNumberAndNothingIsCreated(): void
    {
        $this->fails( [], '"items" is required.' );
        $this->fails( [ 'items' => 'Home' ], '"items" must be a list.' );
        $this->fails( [ 'items' => [] ], '"items" must not be empty.' );
        $this->fails( [ 'items' => array_fill( 0, 31, [ 'title' => 'x', 'url' => '/x' ] ) ], '"items" must have at most 30 items.' );
        $this->fails( [ 'items' => [ 'Home' ] ], 'Item 1 must be an object.' );
        $this->fails( [ 'items' => [ [ 'url' => '/x' ] ] ], 'Item 1: "title"' );
        $this->fails( [ 'items' => [ [ 'title' => 5, 'url' => '/x' ] ] ], 'Item 1: "title"' );
        $this->fails( [ 'items' => [ [ 'title' => str_repeat( 'a', 101 ), 'url' => '/x' ] ] ], '"title" must be at most 100 bytes' );
        $this->fails( [ 'items' => [ [ 'title' => 'Ok', 'url' => '/ok' ], [ 'title' => 'No link' ] ] ], 'Item 2: give a "page_id" or a "url"' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'page_id' => '5' ] ] ], '"page_id" must be an integer' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'page_id' => 99 ] ] ], 'page 99 was not found or is not published' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'page_id' => 7 ] ] ], 'page 7 was not found or is not published' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'page_id' => 8 ] ] ], 'page 8 was not found or is not published' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'url' => 5 ] ] ], '"url" must be a string' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'url' => 'javascript:alert(1)' ] ] ], 'not a valid link' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'url' => '/x' ] ], 'dry_run' => 'yes' ], '"dry_run" must be true or false.' );
    }

    public function testADryRunBuildsNothingAndRecordsNothing(): void
    {
        $r = $this->tool->run( [ 'items' => $this->items(), 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ 'menu' => 'Main Menu', 'location' => 'primary-menu', 'replaces_existing_items' => 0, 'new_items' => 3 ], $r['would_change'] );
        $this->assertSame( [], $GLOBALS['__wp_menus'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testANewMenuBuiltOnATrulyEmptySiteIsDeletedByUndo(): void
    {
        $r    = $this->tool->run( [ 'items' => $this->items() ] );
        $undo = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( [], $GLOBALS['__wp_menus'] );
    }

    public function testUndoRestoresThePreviousMenuAndUndoingTheUndoBringsTheNewOneBack(): void
    {
        $id = wp_create_nav_menu( MenuBuilder::MENU_NAME );
        wp_update_nav_menu_item( $id, 0, [ 'menu-item-title' => 'Old', 'menu-item-type' => 'custom', 'menu-item-url' => 'https://old.example' ] );
        set_theme_mod( 'nav_menu_locations', [ 'primary-menu' => $id ] );
        $old = MenuBuilder::canonical( MenuBuilder::snapshot( 'primary-menu' ) );

        $r     = $this->tool->run( [ 'items' => $this->items() ] );
        $built = MenuBuilder::canonical( MenuBuilder::snapshot( 'primary-menu' ) );
        $this->assertNotSame( $old, $built );

        $first = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $first['fully_undone'] );
        $this->assertSame( $old, MenuBuilder::canonical( MenuBuilder::snapshot( 'primary-menu' ) ) );

        $second = $this->undo()->undo( $first['undo_batch']['id'] );
        $this->assertTrue( $second['fully_undone'], 'an undo is itself undoable' );
        $this->assertSame( $built, MenuBuilder::canonical( MenuBuilder::snapshot( 'primary-menu' ) ) );
    }

    public function testUndoOfAnUndoSucceedsWhenTheMenuHasCustomLinksWhoseObjectIdIsTheirOwnPostId(): void
    {
        $id = wp_create_nav_menu( MenuBuilder::MENU_NAME );
        wp_update_nav_menu_item( $id, 0, [ 'menu-item-title' => 'Old A', 'menu-item-type' => 'custom', 'menu-item-url' => 'https://old.example/a' ] );
        wp_update_nav_menu_item( $id, 0, [ 'menu-item-title' => 'Old B', 'menu-item-type' => 'custom', 'menu-item-url' => 'https://old.example/b' ] );
        set_theme_mod( 'nav_menu_locations', [ 'primary-menu' => $id ] );
        $old = MenuBuilder::canonical( MenuBuilder::snapshot( 'primary-menu' ) );
        $this->assertSame( [ 0, 0 ], array_column( $old['items'], 'object_id' ), 'a custom link carries no database id' );

        $r     = $this->tool->run( [ 'items' => [ [ 'title' => 'Shop', 'url' => 'https://example.com/shop' ], [ 'title' => 'Home', 'page_id' => 5 ] ] ] );
        $built = MenuBuilder::canonical( MenuBuilder::snapshot( 'primary-menu' ) );

        $first = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $first['fully_undone'] );
        $this->assertSame( $old, MenuBuilder::canonical( MenuBuilder::snapshot( 'primary-menu' ) ) );

        $second = $this->undo()->undo( $first['undo_batch']['id'] );
        $this->assertTrue( $second['fully_undone'], 'undoing the undo works although the recreated custom items have new ids' );
        $this->assertSame( $built, MenuBuilder::canonical( MenuBuilder::snapshot( 'primary-menu' ) ) );
    }

    public function testUndoThatCannotRestoreEveryItemIsReportedFailedNotFullyUndone(): void
    {
        $id = wp_create_nav_menu( MenuBuilder::MENU_NAME );
        wp_update_nav_menu_item( $id, 0, [ 'menu-item-title' => 'Old A', 'menu-item-type' => 'custom', 'menu-item-url' => 'https://old.example/a' ] );
        wp_update_nav_menu_item( $id, 0, [ 'menu-item-title' => 'Old B', 'menu-item-type' => 'custom', 'menu-item-url' => 'https://old.example/b' ] );
        set_theme_mod( 'nav_menu_locations', [ 'primary-menu' => $id ] );

        $r = $this->tool->run( [ 'items' => $this->items() ] );
        $GLOBALS['__wp_menu_item_calls']   = 0;
        $GLOBALS['__wp_menu_item_fail_at'] = 2; // the second item of the restore is refused
        $undo                              = $this->undo()->undo( $r['batch']['id'] );
        $GLOBALS['__wp_menu_item_fail_at'] = 0;

        $this->assertFalse( $undo['fully_undone'] );
        $this->assertSame( 'failed', $undo['items'][0]['result'] );
        $this->assertSame( 'menu_failed', $undo['items'][0]['reason'] );
    }

    public function testUndoSkipsAMenuTheOwnerEditedSince(): void
    {
        $r  = $this->tool->run( [ 'items' => $this->items() ] );
        $id = MenuBuilder::snapshot( 'primary-menu' )['menu_id'];
        wp_update_nav_menu_item( $id, 0, [ 'menu-item-title' => 'Added by the owner', 'menu-item-type' => 'custom', 'menu-item-url' => '/x' ] );
        $undo = $this->undo()->undo( $r['batch']['id'] );
        $this->assertSame( 'changed_since', $undo['items'][0]['result'] );
        $this->assertCount( 4, MenuBuilder::snapshot( 'primary-menu' )['items'] );
    }

    public function testAThemeWithNoMenuLocationSaysSo(): void
    {
        $GLOBALS['__wp_registered_menus'] = [];
        $r                                = $this->tool->run( [ 'items' => $this->items() ] );
        $this->assertNull( $r['location'] );
        $this->assertStringContainsString( 'Appearance', $r['message'] );
    }

    public function testItIsRegisteredAndNeedsEditThemeOptions(): void
    {
        $this->assertContains( 'set_primary_menu', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $d = $this->tool->definition();
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'items' ], $d['inputSchema']['required'] );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $registry             = new ToolRegistry( [ $this->tool ], static fn (): bool => true );
        $this->assertSame( -32602, ( (array) $registry->claim( null, 'set_primary_menu', [ 'items' => $this->items() ], 1 )->get_data() )['error']['code'] );
        $this->assertSame( [], $GLOBALS['__wp_menus'] );
    }
}
