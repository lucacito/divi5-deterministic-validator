<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Kinds\PostStatusKind;
use AiEditorDivi5\Pro\Settings\MenuBuilder;
use AiEditorDivi5\Pro\Site\BuildLock;
use AiEditorDivi5\Pro\Tools\BuildSite;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../tests/WpInsertPostShims.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpSiteShims.php';
require_once __DIR__ . '/WpSettingsShims.php';
require_once __DIR__ . '/WpBuildShims.php';

/**
 * A page that went live in a launch must not go back to draft while the live menu or the front page still points at it:
 * visitors would get a dead link. The launch marks its status items, and their undo then says `in_use` and leaves the page alone.
 */
class BuildUndoGuardTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private BuildSite $tool;
    private int $now = 1000000;

    private static function layout( string $word ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $word . '"}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->tool = new BuildSite( $this->log, new BuildLock( 0 ), fn (): int => $this->now );
        $GLOBALS['__wp_posts']            = [];
        $GLOBALS['__wp_postmeta']         = [];
        $GLOBALS['__wp_options']          = [ 'show_on_front' => 'posts', 'page_on_front' => 0 ];
        $GLOBALS['__wp_transients']       = [];
        $GLOBALS['__wp_menus']            = [];
        $GLOBALS['__wp_menu_items']       = [];
        $GLOBALS['__wp_theme_mods']       = [];
        $GLOBALS['__wp_registered_menus'] = [ 'primary-menu' => 'Primary Menu' ];
        $GLOBALS['__wp_menu_item_fail_at'] = 0;
        $GLOBALS['__wp_update_fail']      = false;
        $GLOBALS['__wp_current_user_id']  = 7;
        $GLOBALS['__wp_caps']             = [ 'publish_pages' => true, 'edit_post' => true, 'delete_post' => true, 'edit_theme_options' => true, 'manage_options' => true ];
        unset( $GLOBALS['__wp_update_hook'] );
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__wp_update_hook'] );
    }

    /** Home (front page, in the menu) and About (in the menu), launched. @return array<string,int> */
    private function launched(): array
    {
        $ids = [];
        foreach ( [ [ 'Home', 'home', [ 'is_front' => true ] ], [ 'About', 'about', [] ] ] as [ $title, $slug, $extra ] ) {
            $r = $this->tool->run( [ 'action' => 'add_page', 'title' => $title, 'slug' => $slug, 'content' => self::layout( $title ) ] + $extra + ( [] === $ids ? [] : [ 'build_id' => 1 ] ) );
            $ids[ $slug ] = $r['page']['id'];
        }
        $p = $this->tool->run( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ] );
        $this->tool->run( [ 'action' => 'launch', 'build_id' => 1, 'token' => $p['token'] ] );

        return $ids;
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( Batches::kinds(), $this->log );
    }

    public function testEveryStatusItemOfALaunchIsMarkedAndAPlainPublishItemIsNot(): void
    {
        $this->launched();
        $statusItems = array_values( array_filter( $this->repo->rows[1]['items'], static fn ( array $i ): bool => 'post_status' === $i['kind'] ) );
        $this->assertCount( 2, $statusItems );
        foreach ( $statusItems as $i ) {
            $this->assertSame( [ 'guard_in_use' => true ], $i['meta'] );
        }
        $plain = Item::make( 'post_status', '9', 'x', 'draft', 'publish' );
        $this->assertArrayNotHasKey( 'meta', $plain, 'the publish_page tool records no guard: its undo is unchanged' );
    }

    public function testUndoStillPutsEverythingBackWhenNothingWasChangedAfterTheLaunch(): void
    {
        $ids = $this->launched();
        $u   = $this->undo()->undo( 1 );
        $this->assertTrue( $u['fully_undone'] );
        foreach ( $ids as $id ) {
            $this->assertSame( 'trash', $GLOBALS['__wp_posts'][ $id ]->post_status );
        }
    }

    public function testAPageStaysPublishedWhileTheLiveMenuStillPointsAtItAfterTheOwnerEditedTheMenu(): void
    {
        $ids = $this->launched();
        $menu = wp_get_nav_menu_object( MenuBuilder::MENU_NAME );
        wp_update_nav_menu_item( (int) $menu->term_id, 0, [ 'menu-item-title' => 'Owner link', 'menu-item-type' => 'custom', 'menu-item-url' => '/owner/' ] );   // the owner changes the live menu after the launch
        $u = $this->undo()->undo( 1 );
        $this->assertFalse( $u['fully_undone'] );
        $results = array_column( $u['items'], 'result', 'kind' );
        $this->assertSame( 'changed_since', $results['menu'], 'the menu is not overwritten' );
        foreach ( $ids as $id ) {
            $this->assertSame( 'publish', $GLOBALS['__wp_posts'][ $id ]->post_status, 'a page the live menu still links to is not unpublished: visitors would get a dead link' );
        }
        $this->assertContains( 'in_use', array_column( $u['items'], 'reason' ) );
    }

    public function testThePageThatIsStillTheFrontPageStaysPublishedAndTheOthersGoBack(): void
    {
        $ids = $this->launched();
        $GLOBALS['__wp_caps']['manage_options'] = false;   // the front page cannot be restored by this user: it still points at Home
        $u = $this->undo()->undo( 1 );
        $this->assertFalse( $u['fully_undone'] );
        $this->assertSame( 'publish', $GLOBALS['__wp_posts'][ $ids['home'] ]->post_status, 'the live front page is not unpublished' );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][ $ids['about'] ]->post_status, 'a page nothing points at any more is taken away as usual' );
    }

    public function testTheGuardOnlyLooksAtThePageItsItemNames(): void
    {
        $GLOBALS['__wp_posts'][5] = (object) [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Five', 'post_content' => 'x', 'post_name' => 'five' ];
        $GLOBALS['__wp_posts'][6] = (object) [ 'ID' => 6, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Six', 'post_content' => 'x', 'post_name' => 'six' ];
        $GLOBALS['__wp_options'] = [ 'show_on_front' => 'page', 'page_on_front' => 6 ];
        $guarded = Item::make( 'post_status', '5', 'Status of Five', 'draft', 'publish' ) + [ 'meta' => [ 'guard_in_use' => true ] ];
        $r = ( new PostStatusKind() )->restore( $guarded );
        $this->assertTrue( $r['ok'], 'page 6 is the front page, page 5 is not' );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][5]->post_status );
        $onFront = Item::make( 'post_status', '6', 'Status of Six', 'draft', 'publish' ) + [ 'meta' => [ 'guard_in_use' => true ] ];
        $this->assertSame( [ 'ok' => false, 'reason' => 'in_use' ], ( new PostStatusKind() )->restore( $onFront ) );
        $this->assertSame( 'publish', $GLOBALS['__wp_posts'][6]->post_status );
        $unguarded = Item::make( 'post_status', '6', 'Status of Six', 'draft', 'publish' );
        $this->assertTrue( ( new PostStatusKind() )->restore( $unguarded )['ok'], 'without the guard (publish_page) the old behaviour is unchanged' );
    }

    public function testAPublishedLineCarriesTheRealStatusAndANoteWhenCoreDidNotPublish(): void
    {
        foreach ( [ [ 'Home', 'home' ], [ 'About', 'about' ] ] as $n => [ $title, $slug ] ) {
            $this->tool->run( [ 'action' => 'add_page', 'title' => $title, 'slug' => $slug, 'content' => self::layout( $title ) ] + ( 0 === $n ? [] : [ 'build_id' => 1 ] ) );
        }
        $p = $this->tool->run( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ] );
        $GLOBALS['__wp_update_hook'] = static function ( int $id, array $arr ): void {
            if ( 'about' === $GLOBALS['__wp_posts'][ $id ]->post_name ) {
                $GLOBALS['__wp_posts'][ $id ]->post_status = 'pending';   // core did not publish it
            }
        };
        $r = $this->tool->run( [ 'action' => 'launch', 'build_id' => 1, 'token' => $p['token'] ] );
        $this->assertSame( [ 'publish', 'pending' ], array_column( $r['published'], 'status' ) );
        $this->assertArrayNotHasKey( 'note', $r['published'][0] );
        $this->assertStringContainsString( 'not live', $r['published'][1]['note'] );
        $menu = wp_get_nav_menu_object( MenuBuilder::MENU_NAME );
        $this->assertSame( [ 'Home' ], array_map( static fn ( $o ) => $o->title, array_values( wp_get_nav_menu_items( (int) $menu->term_id ) ) ), 'a page that is not published is not in the menu' );
    }

    public function testThePreviewSaysLatestPostsWhenTheSiteDoesNotShowAStaticFrontPage(): void
    {
        $GLOBALS['__wp_options'] = [ 'show_on_front' => 'posts', 'page_on_front' => 55 ];   // a stale id left over from an earlier setting
        $this->tool->run( [ 'action' => 'add_page', 'title' => 'Home', 'slug' => 'home', 'content' => self::layout( 'Home' ), 'is_front' => true ] );
        $p = $this->tool->run( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ] );
        $this->assertSame( 0, $p['front_page']['from'], 'the site shows the latest posts: there is no current front page' );

        $GLOBALS['__wp_options'] = [ 'show_on_front' => 'page', 'page_on_front' => 55 ];
        $p = $this->tool->run( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ] );
        $this->assertSame( 55, $p['front_page']['from'] );
    }
}
