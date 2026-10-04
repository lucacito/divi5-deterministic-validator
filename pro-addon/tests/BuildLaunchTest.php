<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Settings\MenuBuilder;
use AiEditorDivi5\Pro\Site\BuildLock;
use AiEditorDivi5\Pro\Site\PreviewToken;
use AiEditorDivi5\Pro\Tools\BuildSite;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../tests/WpInsertPostShims.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpSiteShims.php';
require_once __DIR__ . '/WpSettingsShims.php';
require_once __DIR__ . '/WpBuildShims.php';

/** build_site launch: a previewed, single-use-token step that publishes, sets the front page and builds the menu, as one undoable batch. */
class BuildLaunchTest extends TestCase
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
        $GLOBALS['__wp_menu_item_calls']   = 0;
        $GLOBALS['__wp_update_fail']      = false;
        $GLOBALS['__wp_current_user_id']  = 7;
        $GLOBALS['__wp_caps']             = [ 'publish_pages' => true, 'edit_post' => true, 'delete_post' => true, 'edit_theme_options' => true, 'manage_options' => true ];
        unset( $GLOBALS['__wp_update_hook'], $GLOBALS['__wp_menu_create_fail'] );
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__wp_update_hook'], $GLOBALS['__wp_menu_create_fail'] );
        $GLOBALS['__wp_update_fail'] = false;
    }

    /** Home (front page), About (menu), Hidden (not in the menu): ids by slug. @return array<string,int> */
    private function build( bool $front = true ): array
    {
        $ids = [];
        foreach ( [ [ 'Home', 'home', [ 'is_front' => $front ] ], [ 'About', 'about', [] ], [ 'Hidden', 'hidden', [ 'in_menu' => false ] ] ] as [ $title, $slug, $extra ] ) {
            $r = $this->tool->run( [ 'action' => 'add_page', 'title' => $title, 'slug' => $slug, 'content' => self::layout( $title ) ] + $extra + ( [] === $ids ? [] : [ 'build_id' => 1 ] ) );
            $ids[ $slug ] = $r['page']['id'];
        }

        return $ids;
    }

    /** @return array<string,mixed> */
    private function preview( int $build = 1 ): array
    {
        return $this->tool->run( [ 'action' => 'launch', 'build_id' => $build, 'dry_run' => true ] );
    }

    /** @return array<string,mixed> */
    private function launch( array $preview, int $build = 1 ): array
    {
        return $this->tool->run( [ 'action' => 'launch', 'build_id' => $build, 'token' => $preview['token'] ] );
    }

    private function refused( array $args, string $message ): void
    {
        $posts   = $GLOBALS['__wp_posts'];
        $options = $GLOBALS['__wp_options'];
        $rows    = $this->repo->rows;
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( [ $posts, $options, $rows ], [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_options'], $this->repo->rows ], 'a refused launch changes nothing' );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( Batches::kinds(), $this->log );
    }

    /** @return list<string> the titles of the menu items now in "Main Menu" */
    private function menuTitles(): array
    {
        $menu = wp_get_nav_menu_object( MenuBuilder::MENU_NAME );

        return $menu ? array_map( static fn ( $o ) => (string) $o->title, array_values( (array) wp_get_nav_menu_items( (int) $menu->term_id ) ) ) : [];
    }

    public function testTheDefinitionOffersLaunchWithADryRunAndAToken(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( [ 'add_page', 'status', 'launch' ], $d['inputSchema']['properties']['action']['enum'] );
        $this->assertSame( 'boolean', $d['inputSchema']['properties']['dry_run']['type'] );
        $this->assertSame( 'string', $d['inputSchema']['properties']['token']['type'] );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertStringContainsString( 'launch', $d['description'] );
    }

    public function testADryRunPreviewsEverythingAndChangesNothing(): void
    {
        $ids = $this->build();
        $r   = $this->preview();
        $this->assertSame( [ false, true, 3, 0 ], [ $r['changed'], $r['dry_run'], $r['would_publish'], $r['would_skip'] ] );
        $this->assertSame( [ 'id' => 1, 'state' => 'open', 'pages' => 3, 'in_menu' => 2, 'front_page' => $ids['home'] ], $r['build'] );
        $this->assertSame( [ [ 'id' => $ids['home'], 'title' => 'Home', 'slug' => 'home', 'will_publish' => true ], [ 'id' => $ids['about'], 'title' => 'About', 'slug' => 'about', 'will_publish' => true ], [ 'id' => $ids['hidden'], 'title' => 'Hidden', 'slug' => 'hidden', 'will_publish' => true ] ], $r['pages'] );
        $this->assertSame( [ 'from' => 0, 'to' => $ids['home'] ], $r['front_page'] );
        $this->assertSame( [ 'location' => 'primary-menu', 'replaces_items' => 0, 'new_items' => 2 ], $r['menu'] );
        $this->assertNotSame( '', $r['token'] );
        foreach ( $ids as $id ) {
            $this->assertSame( 'draft', $GLOBALS['__wp_posts'][ $id ]->post_status );
        }
        $this->assertSame( [ 'posts', 0 ], [ $GLOBALS['__wp_options']['show_on_front'], $GLOBALS['__wp_options']['page_on_front'] ] );
        $this->assertSame( [], $this->menuTitles() );
        $this->assertSame( 'building', $this->repo->rows[1]['status'] );
        $this->assertCount( 3, $this->repo->rows[1]['items'] );
    }

    public function testARealRunNeedsTheTokenOfAPreviewOfThisBuild(): void
    {
        $this->build();
        $this->refused( [ 'action' => 'launch', 'build_id' => 1 ], 'needs the token' );
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => 'nope' ], 'needs the token' );
        $p = $this->preview();
        $this->now += PreviewToken::TTL + 1;
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => $p['token'] ], 'needs the token' );
    }

    public function testLaunchPublishesSetsTheFrontPageAndBuildsTheMenuAsOneBatch(): void
    {
        $ids = $this->build();
        $r   = $this->launch( $this->preview() );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ $ids['home'], $ids['about'], $ids['hidden'] ], array_column( $r['published'], 'id' ) );
        $this->assertSame( [], $r['skipped'] );
        $this->assertSame( [ 'home', 'about', 'hidden' ], array_column( $r['published'], 'slug' ) );
        $this->assertNotSame( '', $r['published'][0]['link'] );
        $this->assertSame( $ids['home'], $r['front_page'] );
        $this->assertSame( [ 'items' => 2, 'location' => 'primary-menu' ], $r['menu'] );
        $this->assertSame( 'launched', $r['build']['state'] );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( 1, $r['batch']['id'] );

        foreach ( $ids as $id ) {
            $this->assertSame( 'publish', $GLOBALS['__wp_posts'][ $id ]->post_status );
        }
        $this->assertSame( [ 'page', $ids['home'] ], [ $GLOBALS['__wp_options']['show_on_front'], $GLOBALS['__wp_options']['page_on_front'] ] );
        $this->assertSame( [ 'Home', 'About' ], $this->menuTitles(), 'the menu has the in_menu pages in add order' );
        $this->assertSame( 'applied', $this->repo->rows[1]['status'] );
        $this->assertSame( [ 'page_created', 'page_created', 'page_created', 'post_status', 'post_status', 'post_status', 'front_page', 'menu' ], array_column( $this->repo->rows[1]['items'], 'kind' ), 'creation, then statuses, then the front page, then the menu' );
        $this->assertCount( 1, $this->repo->rows, 'one batch for the whole build' );
        $this->refused( [ 'action' => 'add_page', 'title' => 'Late', 'slug' => 'late', 'content' => self::layout( 'Late' ), 'build_id' => 1 ], 'finished (launched)' );
    }

    public function testTheMenuTitleIsTheMenuLabelAndALaunchWithoutMenuPagesLeavesTheMenuAlone(): void
    {
        $this->tool->run( [ 'action' => 'add_page', 'title' => 'Our Services', 'slug' => 'services', 'content' => self::layout( 'S' ), 'menu_label' => 'Services' ] );
        $this->launch( $this->preview() );
        $this->assertSame( [ 'Services' ], $this->menuTitles() );

        $GLOBALS['__wp_menus'] = []; $GLOBALS['__wp_menu_items'] = [];
        $this->tool->run( [ 'action' => 'add_page', 'title' => 'Legal', 'slug' => 'legal', 'content' => self::layout( 'L' ), 'in_menu' => false ] );
        $r = $this->preview( 2 );
        $this->assertNull( $r['menu'] );
        $this->assertNull( $r['front_page'] );
        $this->launch( $r, 2 );
        $this->assertSame( [], $this->menuTitles(), 'no menu page, no menu change' );
        $this->assertSame( [ 'page_created', 'post_status' ], array_column( $this->repo->rows[2]['items'], 'kind' ), 'no front page and no menu item were recorded' );
    }

    public function testWithoutAThemeMenuLocationTheMenuIsBuiltUnassignedAndTheResultSaysSo(): void
    {
        $GLOBALS['__wp_registered_menus'] = [];
        $this->build();
        $r = $this->launch( $this->preview() );
        $this->assertSame( [ 'items' => 2, 'location' => null ], $r['menu'] );
        $this->assertStringContainsString( 'no menu location', $r['message'] );
    }

    public function testAPageThatIsGoneChangedOrWhoseSlugWasTakenIsSkippedNeverPublishedFlaggedOrInTheMenu(): void
    {
        $ids = $this->build();
        $p   = $this->preview();
        $GLOBALS['__wp_posts'][ $ids['home'] ]->post_status  = 'trash';                    // the front page is gone
        $GLOBALS['__wp_posts'][ $ids['about'] ]->post_content = 'edited in the builder';  // edited after the preview
        $GLOBALS['__wp_posts'][700] = (object) [ 'ID' => 700, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Hidden', 'post_content' => 'x', 'post_name' => 'hidden' ];
        $r = $this->launch( $p );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [], $r['published'] );
        $this->assertEqualsCanonicalizing( [ [ 'id' => $ids['home'], 'title' => 'Home', 'reason' => 'not_available' ], [ 'id' => $ids['about'], 'title' => 'About', 'reason' => 'changed_since_preview' ], [ 'id' => $ids['hidden'], 'title' => 'Hidden', 'reason' => 'slug_taken' ] ], $r['skipped'] );
        $this->assertSame( 'building', $this->repo->rows[1]['status'], 'nothing was published, so the build stays open' );
        $this->assertSame( 0, $GLOBALS['__wp_options']['page_on_front'] );
        $this->assertSame( [], $this->menuTitles() );
    }

    public function testTheValidPagesAreStillPublishedWhenOthersAreSkipped(): void
    {
        $ids = $this->build();
        $p   = $this->preview();
        $GLOBALS['__wp_posts'][ $ids['home'] ]->post_status = 'trash';
        $r = $this->launch( $p );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ $ids['about'], $ids['hidden'] ], array_column( $r['published'], 'id' ) );
        $this->assertSame( [ [ 'id' => $ids['home'], 'title' => 'Home', 'reason' => 'not_available' ] ], $r['skipped'] );
        $this->assertNull( $r['front_page'], 'a skipped page is never the front page' );
        $this->assertSame( 0, $GLOBALS['__wp_options']['page_on_front'] );
        $this->assertSame( [ 'About' ], $this->menuTitles() );
        $this->assertSame( 'launched', $r['build']['state'] );
    }

    public function testALaunchIsRefusedWhenTheLiveFrontPageOrMenuChangedSinceThePreview(): void
    {
        $this->build();
        $p = $this->preview();
        $GLOBALS['__wp_options']['page_on_front'] = 999;
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => $p['token'] ], 'changed since the preview' );
        $GLOBALS['__wp_options']['page_on_front'] = 0;

        $p = $this->preview();
        MenuBuilder::build( [ [ 'title' => 'Old', 'type' => 'custom', 'object' => 'custom', 'object_id' => 0, 'url' => '/old/', 'parent' => -1, 'classes' => '', 'target' => '', 'attr_title' => '', 'xfn' => '', 'description' => '' ] ], 'primary-menu' );
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => $p['token'] ], 'changed since the preview' );
    }

    public function testEachPartNeedsItsOwnCapabilityBeforeAnythingIsDone(): void
    {
        $this->build();
        $GLOBALS['__wp_caps']['edit_theme_options'] = false;
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ], 'edit_theme_options' );
        $GLOBALS['__wp_caps']['edit_theme_options'] = true;
        $GLOBALS['__wp_caps']['manage_options']     = false;
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ], 'manage_options' );
    }

    public function testNoMenuPagesAndNoFrontPageNeedNeitherMenuNorSettingsRights(): void
    {
        $this->tool->run( [ 'action' => 'add_page', 'title' => 'Legal', 'slug' => 'legal', 'content' => self::layout( 'L' ), 'in_menu' => false ] );
        $GLOBALS['__wp_caps'] = [ 'publish_pages' => true, 'edit_post' => true, 'delete_post' => true ];
        $r = $this->launch( $this->preview() );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 'publish', $GLOBALS['__wp_posts'][ $r['published'][0]['id'] ]->post_status );
    }

    public function testATokenIsSingleUseAndBoundToTheUserEvenAfterAnUndo(): void
    {
        $this->build();
        $p = $this->preview();
        $GLOBALS['__wp_current_user_id'] = 8;
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => $p['token'] ], 'Build 1 was not found' );
        $GLOBALS['__wp_current_user_id'] = 7;
        $this->launch( $p );
        $this->assertTrue( $this->undo()->undo( 1 )['fully_undone'] );
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => $p['token'] ], 'finished' );
    }

    public function testUndoOfALaunchedBuildPutsEverythingBack(): void
    {
        $GLOBALS['__wp_options']['show_on_front'] = 'page';
        $GLOBALS['__wp_options']['page_on_front'] = 42;
        MenuBuilder::build( [ [ 'title' => 'Old', 'type' => 'custom', 'object' => 'custom', 'object_id' => 0, 'url' => '/old/', 'parent' => -1, 'classes' => '', 'target' => '', 'attr_title' => '', 'xfn' => '', 'description' => '' ] ], 'primary-menu' );
        $ids = $this->build();
        $this->launch( $this->preview() );
        $this->assertSame( [ 'Home', 'About' ], $this->menuTitles() );

        $u = $this->undo()->undo( 1 );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( 8, $u['restored'], '3 created pages, 3 statuses, the front page and the menu' );
        $this->assertSame( [ 'Old' ], $this->menuTitles(), 'the menu that was there before is back' );
        $this->assertSame( [ 'page', 42 ], [ $GLOBALS['__wp_options']['show_on_front'], $GLOBALS['__wp_options']['page_on_front'] ] );
        foreach ( $ids as $id ) {
            $this->assertSame( 'trash', $GLOBALS['__wp_posts'][ $id ]->post_status, 'back to draft, then trashed' );
        }
        $this->assertSame( 'undone', $this->repo->rows[1]['status'] );
    }

    public function testAPageEditedAfterTheLaunchIsGoneBackToDraftButKeptNotTrashed(): void
    {
        $ids = $this->build();
        $this->launch( $this->preview() );
        $GLOBALS['__wp_posts'][ $ids['about'] ]->post_content = 'owner edit after launch';
        $u = $this->undo()->undo( 1 );
        $this->assertFalse( $u['fully_undone'] );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][ $ids['about'] ]->post_status );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][ $ids['home'] ]->post_status );
        $this->assertContains( 'changed_since', array_column( $u['items'], 'result' ) );
        $this->assertSame( 'partly_undone', $this->repo->rows[1]['status'] );
    }

    public function testAnOpenBuildCanBeAbandonedWithUndoBatch(): void
    {
        $ids = $this->build();
        $u   = $this->undo()->undo( 1 );
        $this->assertTrue( $u['fully_undone'] );
        foreach ( $ids as $id ) {
            $this->assertSame( 'trash', $GLOBALS['__wp_posts'][ $id ]->post_status );
        }
        $this->assertSame( 'undone', $this->repo->rows[1]['status'] );
        $this->assertNull( $this->log->openBuild( 7 ), 'the user can start a new build now' );
        $this->refused( [ 'action' => 'add_page', 'title' => 'More', 'slug' => 'more', 'content' => self::layout( 'M' ), 'build_id' => 1 ], 'finished (undone)' );
    }

    public function testASlugCoreChangesAtPublishTimeIsReportedAndNothingElseBreaks(): void
    {
        $ids = $this->build();
        $p   = $this->preview();
        $GLOBALS['__wp_update_hook'] = static function ( int $id, array $arr ): void {
            if ( 'publish' === ( $arr['post_status'] ?? '' ) && 'about' === $GLOBALS['__wp_posts'][ $id ]->post_name ) {
                $GLOBALS['__wp_posts'][ $id ]->post_name = 'about-2';
            }
        };
        $r = $this->launch( $p );
        $this->assertSame( [ 'planned' => 'about', 'real' => 'about-2' ], $r['published'][1]['slug_changed'] );
        $this->assertSame( 'about-2', $r['published'][1]['slug'] );
        $this->assertArrayNotHasKey( 'slug_changed', $r['published'][0] );
        $this->assertSame( [ 'Home', 'About' ], $this->menuTitles(), 'the menu points at page ids, not slugs' );
        $this->assertSame( $ids['about'], $r['published'][1]['id'] );
    }

    public function testAFailedPublishIsASkipNotAnAbort(): void
    {
        $this->build();
        $p = $this->preview();
        $GLOBALS['__wp_update_fail'] = true;
        $r = $this->launch( $p );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ 'publish_failed', 'publish_failed', 'publish_failed' ], array_column( $r['skipped'], 'reason' ) );
        $this->assertSame( 'building', $this->repo->rows[1]['status'] );
    }

    public function testLaunchArgumentsAndBuildsAreChecked(): void
    {
        $this->refused( [ 'action' => 'launch' ], '"build_id" is required.' );
        $this->refused( [ 'action' => 'launch', 'build_id' => '1' ], '"build_id" must be an integer.' );
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => 'yes' ], '"dry_run" must be true or false.' );
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => 5 ], '"token" must be a string.' );
        $this->refused( [ 'action' => 'launch', 'build_id' => 9, 'dry_run' => true ], 'Build 9 was not found.' );
        $this->build();
        $GLOBALS['__wp_current_user_id'] = 8;
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ], 'Build 1 was not found.' );
        $GLOBALS['__wp_current_user_id'] = 7;
        $this->launch( $this->preview() );
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ], 'finished (launched)' );
    }

    public function testABuildWithoutPagesCannotBeLaunched(): void
    {
        $this->log->open( 'build_site', 'Site build' );
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ], 'has no pages' );
    }

    public function testALaunchWhileAnotherBuildCallIsRunningIsRefusedAndTheLockIsReleasedAfterwards(): void
    {
        $this->build();
        $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = (string) time();
        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ], 'still running' );
        unset( $GLOBALS['__wp_options']['aied_pro_buildlock_7'] );
        $p = $this->preview();
        $this->assertArrayNotHasKey( 'aied_pro_buildlock_7', $GLOBALS['__wp_options'], 'released after a preview' );
        $this->launch( $p );
        $this->assertArrayNotHasKey( 'aied_pro_buildlock_7', $GLOBALS['__wp_options'], 'released after the real run' );
        try {
            $this->tool->run( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ] );
        } catch ( \InvalidArgumentException $e ) {
            // finished
        }
        $this->assertArrayNotHasKey( 'aied_pro_buildlock_7', $GLOBALS['__wp_options'], 'released after a refusal' );
    }

    public function testWhatWasPublishedIsRecordedEvenWhenALaterStepFailsSoItCanStillBeUndone(): void
    {
        $ids = $this->build();
        $p   = $this->preview();
        $GLOBALS['__wp_menu_create_fail'] = true;
        try {
            $this->launch( $p );
            $this->fail( 'expected the menu failure to surface' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'simulated menu failure', $e->getMessage() );
        }
        $GLOBALS['__wp_menu_create_fail'] = false;
        foreach ( $ids as $id ) {
            $this->assertSame( 'publish', $GLOBALS['__wp_posts'][ $id ]->post_status, 'the pages did go live before the menu step failed' );
        }
        $row = $this->repo->rows[1];
        $this->assertSame( 'building', $row['status'], 'the build is not marked launched' );
        $this->assertSame( [ 'page_created', 'page_created', 'page_created', 'post_status', 'post_status', 'post_status', 'front_page' ], array_column( $row['items'], 'kind' ), 'the publishes and the front page were recorded as they happened' );

        $u = $this->undo()->undo( 1 );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( [ 'posts', 0 ], [ $GLOBALS['__wp_options']['show_on_front'], $GLOBALS['__wp_options']['page_on_front'] ] );
        foreach ( $ids as $id ) {
            $this->assertSame( 'trash', $GLOBALS['__wp_posts'][ $id ]->post_status );
        }
    }
}
