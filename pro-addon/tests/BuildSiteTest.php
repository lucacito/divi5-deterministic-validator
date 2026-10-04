<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Site\BuildLock;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\BuildSite;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../tests/WpInsertPostShims.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpSiteShims.php';
require_once __DIR__ . '/WpBuildShims.php';

/** build_site: add_page (one validated draft per call, one growing batch) and status. */
class BuildSiteTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private BuildSite $tool;

    /** A valid layout whose heading says $word. */
    private static function layout( string $word = 'Hello' ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $word . '"}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->tool = new BuildSite( $this->log, new BuildLock( 0 ) );
        $GLOBALS['__wp_posts']        = [];
        $GLOBALS['__wp_postmeta']     = [];
        $GLOBALS['__wp_options']      = [];
        $GLOBALS['__wp_insert_fail']  = false;
        $GLOBALS['__wp_inserts']      = [];
        $GLOBALS['__wp_current_user_id'] = 7;
        $GLOBALS['__wp_caps']         = [ 'publish_pages' => true, 'edit_post' => true, 'delete_post' => true, 'edit_theme_options' => true, 'manage_options' => true ];
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'] );
        $GLOBALS['__wp_insert_fail'] = false;
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function page( string $title, string $slug, array $extra = [] ): array
    {
        return $extra + [ 'action' => 'add_page', 'title' => $title, 'slug' => $slug, 'content' => self::layout( $title ) ];
    }

    private function refused( array $args, string $message ): void
    {
        $posts = $GLOBALS['__wp_posts'];
        $rows  = $this->repo->rows;
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( $posts, $GLOBALS['__wp_posts'], 'a refused call creates and changes no page' );
        $this->assertSame( $rows, $this->repo->rows, 'and records nothing' );
    }

    public function testItIsAProToolForPublishersWithOneActionArgument(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'build_site', $d['name'] );
        $this->assertSame( 'publish_pages', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'add_page', 'status', 'launch' ], $d['inputSchema']['properties']['action']['enum'] );
        $this->assertSame( [ 'action' ], $d['inputSchema']['required'] );
        foreach ( [ 'title', 'slug', 'content', 'menu_label', 'in_menu', 'is_front', 'build_id' ] as $p ) {
            $this->assertArrayHasKey( $p, $d['inputSchema']['properties'], $p );
        }
        $this->assertContains( 'build_site', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
    }

    public function testTheFirstPageOpensABuildAndIsCreatedAsADraft(): void
    {
        $r = $this->tool->run( $this->page( 'Home', 'home', [ 'is_front' => true ] ) );
        $this->assertTrue( $r['created'] );
        $this->assertSame( [ 'id' => 1, 'state' => 'open', 'pages' => 1, 'in_menu' => 1, 'front_page' => $r['page']['id'] ], $r['build'] );
        $this->assertSame( [ 'Home', 'home', 'draft' ], [ $r['page']['title'], $r['page']['slug'], $r['page']['status'] ] );
        $this->assertNotSame( '', $r['page']['link'] );

        $post = $GLOBALS['__wp_posts'][ $r['page']['id'] ];
        $this->assertSame( [ 'page', 'draft', 'home', self::layout( 'Home' ) ], [ $post->post_type, $post->post_status, $post->post_name, $post->post_content ] );
        $this->assertSame( [ '_et_pb_use_divi_5' => 'on', '_et_pb_use_builder' => 'on' ], $GLOBALS['__wp_postmeta'][ $post->ID ], 'the Divi 5 builder flags, like the free create_page' );

        $row = $this->repo->rows[1];
        $this->assertSame( [ 'build_site', 'building', 7 ], [ $row['tool'], $row['status'], $row['user_id'] ] );
        $this->assertCount( 1, $row['items'] );
        $this->assertSame( [ 'page_created', (string) $post->ID ], [ $row['items'][0]['kind'], $row['items'][0]['ref'] ] );
        $this->assertSame( [ 'in_menu' => true, 'is_front' => true, 'menu_label' => 'Home' ], $row['items'][0]['meta'] );
    }

    public function testLaterPagesJoinTheSameBuildInOrder(): void
    {
        $a = $this->tool->run( $this->page( 'Home', 'home' ) );
        $b = $this->tool->run( $this->page( 'About us', 'about', [ 'build_id' => $a['build']['id'], 'menu_label' => 'About', 'in_menu' => false ] ) );
        $this->assertSame( [ 1, 2, 1 ], [ $b['build']['id'], $b['build']['pages'], $b['build']['in_menu'] ] );
        $items = $this->repo->rows[1]['items'];
        $this->assertSame( [ (string) $a['page']['id'], (string) $b['page']['id'] ], array_column( $items, 'ref' ), 'add order' );
        $this->assertSame( [ 'in_menu' => false, 'is_front' => false, 'menu_label' => 'About' ], $items[1]['meta'] );
        $this->assertCount( 1, $this->repo->rows, 'one batch for the whole build' );
    }

    public function testASecondCallWithoutABuildIdIsRefusedAndNamesTheOpenBuild(): void
    {
        $this->tool->run( $this->page( 'Home', 'home' ) );
        $this->refused( $this->page( 'About', 'about' ), 'build_id": 1' );
        $this->refused( $this->page( 'About', 'about' ), 'undo_batch' );
    }

    public function testAnInvalidPageIsRefusedWithTheViolationsAndTheBuildKeepsGoing(): void
    {
        $this->refused( $this->page( 'Bad', 'bad', [ 'content' => str_replace( 'wp:divi/heading', 'wp:divi/not-a-module', self::layout() ) ] ), 'The page was not created: it failed the checks' );
        $this->assertSame( [], $this->repo->rows, 'an invalid FIRST page does not even open a build' );

        $a = $this->tool->run( $this->page( 'Home', 'home' ) );
        $this->refused( $this->page( 'Bad', 'bad', [ 'build_id' => 1, 'content' => str_replace( 'wp:divi/heading', 'wp:divi/not-a-module', self::layout() ) ] ), 'not-a-module' );
        $c = $this->tool->run( $this->page( 'Contact', 'contact', [ 'build_id' => 1 ] ) );
        $this->assertSame( 2, $c['build']['pages'], 'the valid pages are kept' );
        $this->assertSame( 'building', $this->repo->rows[1]['status'] );
        $this->assertNotSame( $a['page']['id'], $c['page']['id'] );
    }

    public function testCustomCodeInAPageIsRefusedLikeEverywhereElse(): void
    {
        $css = str_replace( 'wp:divi/heading {', 'wp:divi/heading {"css":{"desktop":{"value":{"freeForm":"selector{color:red}"}}},', self::layout() );
        $this->refused( $this->page( 'Styled', 'styled', [ 'content' => $css ] ), 'CUSTOM_CSS_NOT_ALLOWED' );
    }

    public function testASlugAlreadyUsedByAnotherPageIsRefusedButATrashedOneIsFree(): void
    {
        $GLOBALS['__wp_posts'][300] = (object) [ 'ID' => 300, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Old About', 'post_content' => 'x', 'post_name' => 'about' ];
        $this->refused( $this->page( 'About', 'about' ), 'page #300' );
        $GLOBALS['__wp_posts'][300]->post_status = 'trash';
        $r = $this->tool->run( $this->page( 'About', 'about' ) );
        $this->assertTrue( $r['created'], 'a trashed page does not hold its slug' );
    }

    public function testTwoPagesOfTheSameBuildCannotShareASlug(): void
    {
        $a = $this->tool->run( $this->page( 'About', 'about' ) );
        $this->refused( $this->page( 'About again', 'about', [ 'build_id' => 1 ] ), 'page #' . $a['page']['id'] );
    }

    public function testTheSlugAndTitleAreCleanedAndMustSurviveCleaning(): void
    {
        $r = $this->tool->run( $this->page( '<b>Our</b> <script>alert(1)</script>Story', 'Our Story!!', [ 'content' => self::layout( 'Our Story' ) ] ) );
        $this->assertSame( [ 'Our Story', 'our-story' ], [ $r['page']['title'], $r['page']['slug'] ] );
        $this->refused( $this->page( '<b></b>', 'x', [ 'build_id' => 1 ] ), '"title" must contain text' );
        $this->refused( $this->page( 'Ok', '!!!', [ 'build_id' => 1 ] ), '"slug" must contain letters or digits' );
    }

    public function testAtMostThirtyPagesAndOneFrontPage(): void
    {
        $this->tool->run( $this->page( 'Home', 'home', [ 'is_front' => true ] ) );
        $this->refused( $this->page( 'Other home', 'other-home', [ 'build_id' => 1, 'is_front' => true ] ), 'already the front page' );
        for ( $i = 2; $i <= 30; $i++ ) {
            $this->tool->run( $this->page( "Page {$i}", "page-{$i}", [ 'build_id' => 1 ] ) );
        }
        $this->assertCount( 30, $this->repo->rows[1]['items'] );
        $this->refused( $this->page( 'One too many', 'one-too-many', [ 'build_id' => 1 ] ), 'at most 30 pages' );
    }

    public function testABuildOfAnotherUserOrAFinishedOrUnknownBuildCannotBeUsed(): void
    {
        $this->tool->run( $this->page( 'Home', 'home' ) );
        $GLOBALS['__wp_current_user_id'] = 8;
        $this->refused( $this->page( 'About', 'about', [ 'build_id' => 1 ] ), 'Build 1 was not found' );
        $GLOBALS['__wp_current_user_id'] = 7;
        $this->refused( $this->page( 'About', 'about', [ 'build_id' => 99 ] ), 'Build 99 was not found' );
        $this->log->close( 1, 'applied' );
        $this->refused( $this->page( 'About', 'about', [ 'build_id' => 1 ] ), 'finished (launched)' );
        $this->assertTrue( $this->tool->run( $this->page( 'About', 'about' ) )['created'], 'without a build_id a new build starts once none is open' );
        $this->assertSame( 2, $this->repo->rows[2]['id'] );
    }

    public function testAnotherAddPageStillRunningRefusesTheCallBeforeAnyPageIsCreated(): void
    {
        $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = (string) time();
        $this->refused( $this->page( 'Home', 'home' ), 'still running' );
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
    }

    public function testTheLockIsReleasedAfterASuccessAndAfterAFailure(): void
    {
        $this->tool->run( $this->page( 'Home', 'home' ) );
        $this->assertArrayNotHasKey( 'aied_pro_buildlock_7', $GLOBALS['__wp_options'] );
        try {
            $this->tool->run( $this->page( 'Home again', 'home', [ 'build_id' => 1 ] ) );
        } catch ( \InvalidArgumentException $e ) {
            // the slug is taken
        }
        $this->assertArrayNotHasKey( 'aied_pro_buildlock_7', $GLOBALS['__wp_options'] );
    }

    public function testAFailedInsertLeavesNoPageAndClosesABuildItJustOpened(): void
    {
        $GLOBALS['__wp_insert_fail'] = true;
        try {
            $this->tool->run( $this->page( 'Home', 'home' ) );
            $this->fail( 'expected an InvalidArgumentException' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'WordPress could not create the page', $e->getMessage() );
            $this->assertStringNotContainsString( 'simulated failure', $e->getMessage(), 'core error text stays out of the answer' );
        }
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
        $this->assertSame( 'undone', $this->repo->rows[1]['status'], 'the empty build is not left open' );
        $this->assertNull( $this->log->openBuild( 7 ) );

        $GLOBALS['__wp_insert_fail'] = false;
        $this->tool->run( $this->page( 'Home', 'home' ) );
        $GLOBALS['__wp_insert_fail'] = true;
        try {
            $this->tool->run( $this->page( 'About', 'about', [ 'build_id' => 2 ] ) );
        } catch ( \InvalidArgumentException $e ) {
        }
        $this->assertSame( 'building', $this->repo->rows[2]['status'], 'an existing build stays open when one page fails' );
        $this->assertCount( 1, $this->repo->rows[2]['items'] );
    }

    public function testAPageThatCannotBeRecordedIsRemovedAgainRatherThanLeftOutsideTheBuild(): void
    {
        $this->tool->run( $this->page( 'Home', 'home' ) );
        $this->repo->failAppend = true;
        try {
            $this->tool->run( $this->page( 'About', 'about', [ 'build_id' => 1 ] ) );
            $this->fail( 'expected an InvalidArgumentException' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'could not be recorded', $e->getMessage() );
        }
        $this->assertCount( 1, $GLOBALS['__wp_posts'], 'only the first page exists: the unrecorded one was deleted' );
    }

    public function testAUserWhoCannotEditMenusOrSettingsCannotAddPagesThatCouldNeverBeLaunched(): void
    {
        $GLOBALS['__wp_caps'] = [ 'publish_pages' => true, 'edit_post' => true, 'delete_post' => true ];
        $this->refused( $this->page( 'Home', 'home', [ 'in_menu' => true ] ), 'edit_theme_options' );
        $this->refused( $this->page( 'Home', 'home', [ 'is_front' => true, 'in_menu' => false ] ), 'manage_options' );

        $r = $this->tool->run( $this->page( 'Home', 'home' ) );
        $this->assertSame( 0, $r['build']['in_menu'], 'without the right to edit menus a page is not in the menu unless asked' );
        $this->assertSame( [ 'in_menu' => false, 'is_front' => false, 'menu_label' => 'Home' ], $this->repo->rows[1]['items'][0]['meta'] );
        $r = $this->tool->run( $this->page( 'About', 'about', [ 'build_id' => 1, 'in_menu' => false, 'is_front' => false ] ) );
        $this->assertSame( 2, $r['build']['pages'] );

        $GLOBALS['__wp_caps']['edit_theme_options'] = true;
        $GLOBALS['__wp_caps']['manage_options']     = true;
        $r = $this->tool->run( $this->page( 'Team', 'team', [ 'build_id' => 1 ] ) );
        $this->assertSame( 1, $r['build']['in_menu'], 'with the right, the default stays: in the menu' );
    }

    public function testBadArgumentsAreRejectedNotCast(): void
    {
        foreach ( [
            [ [ 'title' => 'x' ], '"action" is required.' ],
            [ [ 'action' => 'explode' ], '"action" must be one of: add_page, status, launch.' ],
            [ [ 'action' => 5 ], '"action" must be a string.' ],
            [ $this->page( 'x', 'x', [ 'title' => 5 ] ), '"title" must be a string.' ],
            [ $this->page( 'x', 'x', [ 'content' => '' ] ), '"content" must not be empty.' ],
            [ $this->page( 'x', 'x', [ 'in_menu' => 'yes' ] ), '"in_menu" must be true or false.' ],
            [ $this->page( 'x', 'x', [ 'is_front' => 1 ] ), '"is_front" must be true or false.' ],
            [ $this->page( 'x', 'x', [ 'build_id' => '1' ] ), '"build_id" must be an integer.' ],
            [ $this->page( 'x', 'x', [ 'menu_label' => str_repeat( 'a', 101 ) ] ), '"menu_label" must be at most 100 bytes.' ],
        ] as [ $args, $message ] ) {
            $this->refused( $args, $message );
        }
    }

    public function testTheMenuLabelDefaultsToTheTitleAndInMenuToTrue(): void
    {
        $this->tool->run( $this->page( 'Services', 'services' ) );
        $this->assertSame( [ 'in_menu' => true, 'is_front' => false, 'menu_label' => 'Services' ], $this->repo->rows[1]['items'][0]['meta'] );
    }

    public function testStatusListsTheBuildWithTheLiveStateOfEveryPage(): void
    {
        $a = $this->tool->run( $this->page( 'Home', 'home', [ 'is_front' => true ] ) );
        $b = $this->tool->run( $this->page( 'About', 'about', [ 'build_id' => 1, 'in_menu' => false ] ) );
        $GLOBALS['__wp_posts'][ $b['page']['id'] ]->post_status = 'trash';
        $s = $this->tool->run( [ 'action' => 'status' ] );
        $this->assertSame( [ 'id' => 1, 'state' => 'open', 'pages' => 2, 'in_menu' => 1, 'front_page' => $a['page']['id'] ], $s['build'] );
        $this->assertSame( [
            [ 'id' => $a['page']['id'], 'title' => 'Home', 'slug' => 'home', 'status' => 'draft', 'in_menu' => true, 'is_front' => true, 'menu_label' => 'Home' ],
            [ 'id' => $b['page']['id'], 'title' => 'About', 'slug' => 'about', 'status' => 'trash', 'in_menu' => false, 'is_front' => false, 'menu_label' => 'About' ],
        ], $s['pages'] );
        $this->assertSame( $s, $this->tool->run( [ 'action' => 'status', 'build_id' => 1 ] ) );
    }

    public function testStatusOfAFinishedBuildSaysSoAndNoOpenBuildIsAnError(): void
    {
        $this->assertSame( 'You have no open build. Call add_page to start one.', $this->expectRefusal( [ 'action' => 'status' ] ) );
        $this->tool->run( $this->page( 'Home', 'home' ) );
        $this->log->close( 1, 'applied' );
        $this->assertSame( 'launched', $this->tool->run( [ 'action' => 'status', 'build_id' => 1 ] )['build']['state'] );
        $this->log->close( 1, 'partly_undone' );
        $this->assertSame( 'undone', $this->tool->run( [ 'action' => 'status', 'build_id' => 1 ] )['build']['state'] );
        $GLOBALS['__wp_current_user_id'] = 8;
        $this->assertSame( 'Build 1 was not found.', $this->expectRefusal( [ 'action' => 'status', 'build_id' => 1 ] ) );
    }

    public function testASlugThatIsTooLongOnceCleanedIsRefusedBeforeAnythingIsCreated(): void
    {
        $GLOBALS['__wp_sanitize_title_utf8'] = true;
        try {
            $this->refused( $this->page( 'Long', str_repeat( 'é', 100 ) ), '"slug" is too long' );
        } finally {
            unset( $GLOBALS['__wp_sanitize_title_utf8'] );
        }
    }

    public function testTheSlugRefusalOnlyNamesAPageTheUserMayEdit(): void
    {
        $GLOBALS['__wp_posts'][300] = (object) [ 'ID' => 300, 'post_type' => 'page', 'post_status' => 'private', 'post_title' => 'Secret', 'post_content' => 'x', 'post_name' => 'about' ];
        $GLOBALS['__wp_caps']['edit_post:300'] = false;
        $this->refused( $this->page( 'About', 'about' ), 'already used by another page' );
        try {
            $this->tool->run( $this->page( 'About', 'about' ) );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringNotContainsString( '#300', $e->getMessage() );
        }
        $GLOBALS['__wp_caps']['edit_post:300'] = true;
        $this->refused( $this->page( 'About', 'about' ), 'page #300' );
    }

    private function expectRefusal( array $args ): string
    {
        try {
            $this->tool->run( $args );
        } catch ( \InvalidArgumentException $e ) {
            return $e->getMessage();
        }
        $this->fail( 'expected InvalidArgumentException' );
    }
}
