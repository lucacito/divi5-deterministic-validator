<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Lab\LabCompile;
use AiEditorDivi5\Pro\Lab\LabLook;
use AiEditorDivi5\Pro\Lab\LabSupport;
use AiEditorDivi5\Pro\Site\BuildLock;
use AiEditorDivi5\Pro\Tools\BuildSite;
use AiEditorDivi5\Pro\Tools\LabRestyle;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StudioService;
use AiEditorDivi5\WP\StudioSlots;
use AiEditorDivi5\WP\StudioStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../tests/WpInsertPostShims.php';
require_once __DIR__ . '/../../tests/WpRestShims.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpSiteShims.php';
require_once __DIR__ . '/WpBuildShims.php';
require_once __DIR__ . '/LabFixture.php';

/**
 * build_site add_page with a Divi 5 Lab brief: the page is the direct compile, one build has one look, and each brief
 * page's brief is stored and linked in the free Lab store (the real StudioStore on the free FakeWpdb).
 */
final class BuildSiteBriefTest extends TestCase
{
    use LabFixture;

    private BuildSite $tool;

    protected function setUp(): void
    {
        $this->setUpLab();
        $GLOBALS['wpdb'] = new BuildSiteBriefWpdb( $GLOBALS['wpdb'] );
        $this->tool = new BuildSite( $this->log, new BuildLock( 0 ) );
        $GLOBALS['__wp_posts']           = [];
        $GLOBALS['__wp_postmeta']        = [];
        $GLOBALS['__wp_insert_fail']     = false;
        $GLOBALS['__wp_inserts']         = [];
        $GLOBALS['__wp_current_user_id'] = 1;
        $GLOBALS['__wp_caps']            = [ 'publish_pages' => true, 'edit_post' => true, 'delete_post' => true, 'edit_theme_options' => true, 'manage_options' => true, 'edit_posts' => true, 'edit_pages' => true, 'edit_others_posts' => true ];
        unset( $GLOBALS['__wp_user_caps'] );
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_insert_fail'] = false;
        $this->tearDownLab();
    }

    /**
     * A valid two-section brief (hero-cta, cta-band).
     *
     * @param array<string,string> $words slot words for the hero
     * @return array<string,mixed>
     */
    private static function brief( string $title, ?string $language = 'editorial', ?string $kit = null, array $words = [] ): array
    {
        $hero = [ 'recipe' => 'hero-cta' ];
        if ( [] !== $words ) {
            $hero['slots'] = $words;
        }
        $brief = [ 'version' => 1, 'title' => $title, 'goal' => 'showcase' ];
        if ( null !== $language ) {
            $brief['language'] = $language;
        }
        if ( null !== $kit ) {
            $brief['kit'] = $kit;
        }
        $brief['sections'] = [ $hero, [ 'recipe' => 'cta-band' ] ];

        return $brief;
    }

    /** @param array<string,mixed> $brief @param array<string,mixed> $extra @return array<string,mixed> */
    private static function page( array $brief, string $slug, array $extra = [] ): array
    {
        return $extra + [ 'action' => 'add_page', 'slug' => $slug, 'brief' => $brief ];
    }

    private static function editorialKit(): string
    {
        return (string) DesignLanguages::get( 'editorial' )['kit'];
    }

    /** @param array<string,mixed> $args */
    private function refused( array $args, string ...$needles ): string
    {
        $posts = $GLOBALS['__wp_posts'];
        $rows  = $this->repo->rows;
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . implode( ' / ', $needles ) );
        } catch ( \InvalidArgumentException $e ) {
            foreach ( $needles as $needle ) {
                $this->assertStringContainsString( $needle, $e->getMessage() );
            }
        }
        $this->assertSame( $posts, $GLOBALS['__wp_posts'], 'a refused call creates and changes no page' );
        $this->assertSame( $rows, $this->repo->rows, 'and records nothing' );

        return $e->getMessage();
    }

    public function testABriefPageIsTheDirectCompile(): void
    {
        $brief    = self::brief( 'Home' );
        $expected = LabCompile::compile( $brief );
        $this->assertTrue( $expected['ok'] );

        $r    = $this->tool->run( self::page( $brief, 'home' ) );
        $post = $GLOBALS['__wp_posts'][ $r['page']['id'] ];
        $this->assertSame( $expected['markup'], $post->post_content, 'the stored page is exactly the compile of the brief' );
        $this->assertSame( [ 'Home', 'draft', 'home', 'page' ], [ $post->post_title, $post->post_status, $post->post_name, $post->post_type ] );
        $this->assertSame( [ 'Home', 'home', 'draft' ], [ $r['page']['title'], $r['page']['slug'], $r['page']['status'] ] );

        $items = $this->repo->rows[1]['items'];
        $this->assertCount( 1, $items );
        $this->assertSame( [ 'page_created', (string) $post->ID ], [ $items[0]['kind'], $items[0]['ref'] ] );
        $this->assertIsInt( $r['lab']['brief_id'] );
        $this->assertSame( [ 'in_menu' => true, 'is_front' => false, 'menu_label' => 'Home', 'look' => [ 'language' => 'editorial', 'kit' => self::editorialKit() ], 'brief_id' => $r['lab']['brief_id'] ], $items[0]['meta'] );

        $this->assertSame( [ 'brief_id' => $r['lab']['brief_id'], 'stored' => true, 'unfilled' => count( $expected['unfilled'] ), 'report' => [
            'problems'   => $expected['problems'],
            'violations' => $expected['violations'],
            'unfilled'   => $expected['unfilled'],
            'refused'    => $expected['refused'],
            'warnings'   => $expected['warnings'],
            'advice'     => $expected['advice'],
        ] ], $r['lab'] );
        $this->assertSame( [ 'language' => 'editorial', 'kit' => self::editorialKit() ], $r['build']['look'] );
    }

    public function testExactlyOneOfContentOrBriefAndNoTitleWithABrief(): void
    {
        $layout = LabCompile::compile( self::brief( 'X' ) )['markup'];
        $this->refused( self::page( self::brief( 'Home' ), 'home', [ 'content' => $layout ] ), 'either "content" or "brief"' );
        $this->refused( self::page( self::brief( 'Home' ), 'home', [ 'title' => 'Home' ] ), '"title"', 'brief' );
        $this->refused( self::page( self::brief( 'Home' ), 'home', [ 'brief' => 'not an object' ] ), '"brief" must be an object' );
        $this->refused( self::page( self::brief( 'Home' ), 'home', [ 'brief' => [ 1, 2 ] ] ), '"brief" must be an object' );
        $this->assertSame( [], $this->repo->rows );

        // brief: null is "not passed": the content path answers exactly as before.
        $r = $this->tool->run( [ 'action' => 'add_page', 'title' => 'Home', 'slug' => 'home', 'content' => $layout, 'brief' => null ] );
        $this->assertTrue( $r['created'] );
        $this->assertArrayNotHasKey( 'lab', $r );
        $this->assertArrayNotHasKey( 'look', $r['build'] );
        $this->assertSame( [ 'in_menu' => true, 'is_front' => false, 'menu_label' => 'Home' ], $this->repo->rows[1]['items'][0]['meta'] );
    }

    public function testLaterBriefPagesTakeTheBuildsLook(): void
    {
        $this->tool->run( self::page( self::brief( 'Home', 'luxe', 'raw-frame' ), 'home' ) );
        $later = self::brief( 'About', null );
        $r     = $this->tool->run( self::page( $later, 'about', [ 'build_id' => 1 ] ) );

        $filled = LabLook::with( $later, 'luxe', 'raw-frame' );
        $this->assertSame( LabCompile::compile( $filled )['markup'], $GLOBALS['__wp_posts'][ $r['page']['id'] ]->post_content );
        $this->assertSame( [ 'language' => 'luxe', 'kit' => 'raw-frame' ], $this->repo->rows[1]['items'][1]['meta']['look'] );
        $this->assertSame( 2, $r['build']['pages'] );

        // The same language without a kit also takes the build's kit.
        $same = $this->tool->run( self::page( self::brief( 'Team', 'luxe' ), 'team', [ 'build_id' => 1 ] ) );
        $this->assertSame( LabCompile::compile( self::brief( 'Team', 'luxe', 'raw-frame' ) )['markup'], $GLOBALS['__wp_posts'][ $same['page']['id'] ]->post_content );
        $this->assertSame( [ 'language' => 'luxe', 'kit' => 'raw-frame' ], $same['build']['look'] );
    }

    public function testADifferentLookIsRefusedAndTheBuildContinues(): void
    {
        $this->tool->run( self::page( self::brief( 'Home', 'editorial' ), 'home' ) );
        $this->refused( self::page( self::brief( 'About', 'luxe' ), 'about', [ 'build_id' => 1 ] ), 'LOOK_MISMATCH', 'lab_restyle' );
        $this->refused( self::page( self::brief( 'About', 'editorial', 'raw-frame' ), 'about', [ 'build_id' => 1 ] ), 'LOOK_MISMATCH' );
        $this->assertCount( 1, $this->repo->rows[1]['items'], 'page count unchanged' );

        $r = $this->tool->run( self::page( self::brief( 'About', 'editorial', self::editorialKit() ), 'about', [ 'build_id' => 1 ] ) );
        $this->assertSame( 2, $r['build']['pages'], 'the same look spelled out is accepted' );
    }

    public function testTheFirstBriefPageMustNameItsLanguage(): void
    {
        $this->refused( self::page( self::brief( 'Home', null ), 'home' ), '"language"' );
        $this->assertSame( [], $this->repo->rows );

        // A build started with content pages has no look yet: its first brief page names it.
        $this->tool->run( [ 'action' => 'add_page', 'title' => 'Plain', 'slug' => 'plain', 'content' => LabCompile::compile( self::brief( 'X' ) )['markup'] ] );
        $this->refused( self::page( self::brief( 'Home', null, 'raw-frame' ), 'home', [ 'build_id' => 1 ] ), '"language"' );
        $r = $this->tool->run( self::page( self::brief( 'Home', 'tech-mono' ), 'home', [ 'build_id' => 1 ] ) );
        $this->assertSame( [ 'language' => 'tech-mono', 'kit' => (string) DesignLanguages::get( 'tech-mono' )['kit'] ], $r['build']['look'] );
    }

    public function testAnInvalidBriefIsRefusedWithItsProblemCodes(): void
    {
        $bad             = self::brief( 'Home' );
        $bad['sections'] = [ [ 'recipe' => 'no-such-recipe' ] ];
        $bad['colour']   = 'zqx-secret-words';
        $message         = $this->refused( self::page( $bad, 'home' ), 'The page was not created', 'UNKNOWN_KEY', 'UNKNOWN_RECIPE' );
        $this->assertStringNotContainsString( 'zqx-secret-words', $message, 'no brief text is echoed but recipe ids' );
        $this->assertSame( [], $this->repo->rows, 'an invalid FIRST page does not open a build' );

        $this->refused( self::page( self::brief( 'Home', 'no-such-language' ), 'home' ), 'UNKNOWN_LANGUAGE' );

        $this->tool->run( self::page( self::brief( 'Home' ), 'home' ) );
        $this->refused( self::page( $bad, 'about', [ 'build_id' => 1 ] ), 'UNKNOWN_RECIPE' );
        $r = $this->tool->run( self::page( self::brief( 'About', null ), 'about', [ 'build_id' => 1 ] ) );
        $this->assertSame( 2, $r['build']['pages'], 'the build keeps going' );
    }

    public function testHostileWordsStayLiteralInABriefPage(): void
    {
        $ids = array_column( StudioSlots::extract( (string) SectionRecipes::rawMarkup( 'hero-cta' ) ), 'id' );
        $this->assertContains( 'heading.1', $ids );
        $this->assertContains( 'heading.0', $ids );

        $hostile = '{{aied:color:accent}} $variable({"type":"content"})$ <!-- /wp:divi/section --> " -- <script>x</script>';
        $words   = [ 'heading.1' => $hostile, 'heading.0' => '{{aied:kit:radius-control}} "aied":{"kit":"card"}' ];
        $neutral = [ 'heading.1' => 'A calm headline', 'heading.0' => 'An eyebrow' ];

        $r      = $this->tool->run( self::page( self::brief( 'Home', 'editorial', null, $words ), 'home' ) );
        $stored = $GLOBALS['__wp_posts'][ $r['page']['id'] ]->post_content;
        $plain  = (string) LabCompile::compile( self::brief( 'Home', 'editorial', null, $neutral ) )['markup'];

        $this->assertStringNotContainsString( '{{aied:', $stored );
        $this->assertStringNotContainsString( '$variable(', $stored );
        $this->assertStringNotContainsString( '"aied":', $stored, 'no kit marker formed' );
        $this->assertStringNotContainsString( '<script', $stored );
        $this->assertSame( substr_count( $plain, '<!-- /wp:divi/section -->' ), substr_count( $stored, '<!-- /wp:divi/section -->' ), 'no block delimiter formed' );
        $this->assertSame( substr_count( $plain, '<!-- wp:' ), substr_count( $stored, '<!-- wp:' ) );
        $this->assertTrue( LayoutGate::check( $stored )->isValid() );
    }

    public function testTheGuardRefusesOnlyTheBriefOption(): void
    {
        $noLab = static fn ( string $class ): bool => 'AiEditorDivi5\\WP\\StudioCompiler' !== $class && class_exists( $class );
        $tool  = new BuildSite( $this->log, new BuildLock( 0 ), null, $noLab );
        try {
            $tool->run( self::page( self::brief( 'Home' ), 'home' ) );
            $this->fail( 'expected the guard' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( LabSupport::message( "build_site's brief option" ), $e->getMessage() );
        }
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
        $this->assertSame( [], $this->repo->rows );

        $noLinked = static fn ( string $class, string $method ): bool => 'linked' !== $method && method_exists( $class, $method );
        $tool2    = new BuildSite( $this->log, new BuildLock( 0 ), null, null, $noLinked );
        try {
            $tool2->run( self::page( self::brief( 'Home' ), 'home' ) );
            $this->fail( 'expected the guard' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( LabSupport::message( "build_site's brief option" ), $e->getMessage() );
        }

        $layout = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Hi"}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
        $r      = $tool->run( [ 'action' => 'add_page', 'title' => 'Home', 'slug' => 'home', 'content' => $layout ] );
        $this->assertTrue( $r['created'], 'a content call still creates a page' );
        $this->assertSame( $layout, $GLOBALS['__wp_posts'][ $r['page']['id'] ]->post_content );
    }

    public function testStatusShowsTheLookOnlyForBuildsWithBriefPages(): void
    {
        $layout = LabCompile::compile( self::brief( 'X' ) )['markup'];
        $this->tool->run( [ 'action' => 'add_page', 'title' => 'Plain', 'slug' => 'plain', 'content' => $layout ] );
        $s = $this->tool->run( [ 'action' => 'status' ] );
        $this->assertSame( [ 'id', 'state', 'pages', 'in_menu', 'front_page' ], array_keys( $s['build'] ), 'a content-only build answers as before' );
        $this->assertNull( BuildSite::buildLook( $this->repo->rows[1] ) );

        $this->tool->run( self::page( self::brief( 'Home', 'luxe' ), 'home', [ 'build_id' => 1 ] ) );
        $look = [ 'language' => 'luxe', 'kit' => (string) DesignLanguages::get( 'luxe' )['kit'] ];
        $s    = $this->tool->run( [ 'action' => 'status' ] );
        $this->assertSame( $look, $s['build']['look'] );
        $this->assertSame( $look, BuildSite::buildLook( $this->repo->rows[1] ) );
        $this->assertSame( [ 'in_menu' => true, 'is_front' => false, 'menu_label' => 'Home' ], array_intersect_key( $s['pages'][1], [ 'in_menu' => 1, 'is_front' => 1, 'menu_label' => 1 ] ) );

        // Two looks (only after a partial restyle) are "mixed" and no look is enforced.
        $row                                   = $this->repo->rows[1];
        $row['items'][1]['meta']['look']['kit'] = 'raw-frame';
        unset( $row['items'][1]['meta']['brief_id'] ); // a page whose brief was not stored counts with meta.look
        $row['items'][]                        = [ 'kind' => 'page_created', 'ref' => '999', 'meta' => [ 'look' => $look ] ];
        $this->assertSame( 'mixed', BuildSite::buildLook( $row ) );
    }

    public function testAMixedBuildEnforcesNoLookButEveryBriefNamesItsLanguage(): void
    {
        $this->tool->run( self::page( self::brief( 'Home', 'luxe' ), 'home' ) );
        $this->relook( 0, 'editorial', 'raw-frame' );
        $this->tool->run( self::page( self::brief( 'About', 'editorial', 'raw-frame' ), 'about', [ 'build_id' => 1 ] ) );
        $this->relook( 1, 'luxe', 'raw-frame' );
        $this->assertSame( 'mixed', BuildSite::buildLook( $this->repo->rows[1] ) );

        $this->refused( self::page( self::brief( 'Team', null ), 'team', [ 'build_id' => 1 ] ), '"language"' );
        $r = $this->tool->run( self::page( self::brief( 'Team', 'tech-mono' ), 'team', [ 'build_id' => 1 ] ) );
        $this->assertSame( 'mixed', $r['build']['look'] );
    }

    // ---- Task 8: the brief of each brief page is stored and linked in the Lab store -----------------------------------

    public function testABriefPageIsStoredAndLinkedForTheLabScreen(): void
    {
        $brief  = self::brief( 'Home', 'luxe' );
        $r      = $this->tool->run( self::page( $brief, 'home' ) );
        $pageId = $r['page']['id'];
        $stored = $GLOBALS['__wp_posts'][ $pageId ]->post_content;

        $row = StudioStore::forPage( 1, $pageId );
        $this->assertNotNull( $row, 'Lab lists the page' );
        $this->assertSame( LabCompile::clean( $brief ), $row['brief'], 'the clean brief' );
        $this->assertSame( 'Home', $row['brief']['title'], 'the brief title is the page title' );
        $this->assertSame( hash( 'sha256', $stored ), $row['content_hash'], 'linked with the content as stored' );
        $this->assertSame( $row['id'], $r['lab']['brief_id'] );
        $this->assertTrue( $r['lab']['stored'] );
        $this->assertSame( $row['id'], $this->repo->rows[1]['items'][0]['meta']['brief_id'] );

        // A later brief page stores the brief with the build's look filled in.
        $b     = $this->tool->run( self::page( self::brief( 'About', null ), 'about', [ 'build_id' => 1 ] ) );
        $about = StudioStore::forPage( 1, $b['page']['id'] );
        $this->assertSame( LabCompile::clean( self::brief( 'About', 'luxe', (string) DesignLanguages::get( 'luxe' )['kit'] ) ), $about['brief'] );

        // Lab owns the page: its rebuild overwrites the untouched draft in place.
        $rebuild = StudioService::rebuild( 1, $row['id'] );
        $this->assertTrue( $rebuild['ok'], (string) json_encode( $rebuild ) );
        $this->assertSame( 'overwritten', $rebuild['mode'] );
        $this->assertSame( $pageId, $rebuild['page_id'] );
    }

    public function testAUserAtTheBriefCapIsRefusedBeforeAnythingIsCreatedAndNothingIsPruned(): void
    {
        for ( $i = 0; $i < 45; $i++ ) {
            $this->assertTrue( StudioStore::save( 1, self::brief( "Saved {$i}" ) )['ok'] );
        }
        $this->tool->run( self::page( self::brief( 'Page 1' ), 'page-1' ) );
        for ( $i = 2; $i <= 5; $i++ ) {
            $this->tool->run( self::page( self::brief( "Page {$i}", null ), "page-{$i}", [ 'build_id' => 1 ] ) );
        }
        $this->assertSame( 50, StudioStore::countFor( 1 ) );
        $ids = $this->briefIds( 1 );

        $inserts = count( $GLOBALS['__wp_inserts'] ?? [] );
        $this->refused( self::page( self::brief( 'Page 6', null ), 'page-6', [ 'build_id' => 1 ] ), 'TOO_MANY_BRIEFS', '50' );
        $this->assertSame( $inserts, count( $GLOBALS['__wp_inserts'] ?? [] ), 'no insert was tried' );
        $this->assertCount( 5, $this->repo->rows[1]['items'] );
        $this->assertSame( 50, StudioStore::countFor( 1 ) );
        $this->assertSame( $ids, $this->briefIds( 1 ), 'nothing was pruned' );
        $this->assertArrayNotHasKey( 'aied_pro_buildlock_1', $GLOBALS['__wp_options'] );

        // A content page is not a brief page: the cap does not apply to it.
        $r = $this->tool->run( [ 'action' => 'add_page', 'title' => 'Plain', 'slug' => 'plain', 'content' => (string) LabCompile::compile( self::brief( 'X' ) )['markup'], 'build_id' => 1 ] );
        $this->assertSame( 6, $r['build']['pages'] );
    }

    public function testAStoreFailureKeepsThePageAndSaysSo(): void
    {
        $GLOBALS['wpdb']->failInserts = true;
        $r = $this->tool->run( self::page( self::brief( 'Home' ), 'home' ) );
        $GLOBALS['wpdb']->failInserts = false;

        $this->assertTrue( $r['created'] );
        $this->assertFalse( $r['lab']['stored'] );
        $this->assertNull( $r['lab']['brief_id'] );
        $this->assertStringContainsString( 'Lab', $r['lab']['note'] );
        $this->assertArrayHasKey( $r['page']['id'], $GLOBALS['__wp_posts'], 'the page stays' );
        $this->assertCount( 1, $this->repo->rows[1]['items'], 'and is in the build' );
        $this->assertNull( $this->repo->rows[1]['items'][0]['meta']['brief_id'] );
        $this->assertSame( 0, StudioStore::countFor( 1 ) );
        $this->assertNull( StudioStore::forPage( 1, $r['page']['id'] ) );
        $this->assertSame( [ 'language' => 'editorial', 'kit' => self::editorialKit() ], BuildSite::buildLook( $this->repo->rows[1] ), 'the look still comes from meta.look' );
    }

    public function testAnUnrecordablePageRemovesItsBriefToo(): void
    {
        $this->tool->run( self::page( self::brief( 'Home' ), 'home' ) );
        $before = $this->briefIds( 1 );
        $this->repo->failAppend = true;
        try {
            $this->tool->run( self::page( self::brief( 'About', null ), 'about', [ 'build_id' => 1 ] ) );
            $this->fail( 'expected an InvalidArgumentException' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'could not be recorded', $e->getMessage() );
        }
        $this->assertCount( 1, $GLOBALS['__wp_posts'], 'the unrecorded page was deleted' );
        $this->assertSame( $before, $this->briefIds( 1 ), 'and its brief too' );
    }

    public function testARestyledBuildPageChangesTheBuildsLook(): void
    {
        $r       = $this->tool->run( self::page( self::brief( 'Home', 'editorial' ), 'home' ) );
        $restyle = new LabRestyle( $this->log );
        $p       = $restyle->run( [ 'page_id' => $r['page']['id'], 'to_language' => 'luxe', 'dry_run' => true ] );
        $done    = $restyle->run( [ 'page_id' => $r['page']['id'], 'to_language' => 'luxe', 'token' => $p['token'] ] );
        $this->assertSame( 1, $done['restyled'], (string) json_encode( $done ) );

        $luxe = [ 'language' => 'luxe', 'kit' => (string) DesignLanguages::get( 'luxe' )['kit'] ];
        $this->assertSame( $luxe, BuildSite::buildLook( $this->repo->rows[1] ), 'the live brief wins over meta.look' );
        $this->assertSame( $luxe, $this->tool->run( [ 'action' => 'status', 'build_id' => 1 ] )['build']['look'] );

        $later = self::brief( 'About', null );
        $b     = $this->tool->run( self::page( $later, 'about', [ 'build_id' => 1 ] ) );
        $this->assertSame( LabCompile::compile( LabLook::with( $later, 'luxe', $luxe['kit'] ) )['markup'], $GLOBALS['__wp_posts'][ $b['page']['id'] ]->post_content );
        $this->refused( self::page( self::brief( 'Team', 'editorial' ), 'team', [ 'build_id' => 1 ] ), 'LOOK_MISMATCH' );
    }

    public function testUndoOfTheBuildKeepsTheBriefs(): void
    {
        $a = $this->tool->run( self::page( self::brief( 'Home' ), 'home' ) );
        $b = $this->tool->run( self::page( self::brief( 'About', null ), 'about', [ 'build_id' => 1 ] ) );

        $u = ( new BatchUndo( Batches::kinds(), $this->log ) )->undo( 1 );
        $this->assertTrue( $u['fully_undone'], (string) json_encode( $u ) );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][ $a['page']['id'] ]->post_status );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][ $b['page']['id'] ]->post_status );
        $this->assertNotNull( StudioStore::get( 1, $a['lab']['brief_id'] ) );
        $this->assertNotNull( StudioStore::get( 1, $b['lab']['brief_id'] ) );
    }

    /** Gives the stored brief of the build's Nth page another look (as a partial restyle of the briefs would). */
    private function relook( int $index, string $language, string $kit ): void
    {
        $id  = (int) $this->repo->rows[1]['items'][ $index ]['meta']['brief_id'];
        $row = StudioStore::get( 1, $id );
        $this->assertNotNull( $row );
        $this->assertTrue( StudioStore::save( 1, LabLook::with( $row['brief'], $language, $kit ), $id )['ok'] );
    }

    /** @return list<int> the user's brief ids, sorted */
    private function briefIds( int $user ): array
    {
        $ids = array_map( 'intval', (array) $GLOBALS['wpdb']->get_col( 'SELECT id FROM wp_ai_editor_divi5_studio WHERE created_by = ' . $user ) );
        sort( $ids );

        return $ids;
    }
}

/**
 * The free FakeWpdb (SQLite) with an options table, so the real BuildLock (INSERT IGNORE on the options table) runs next to
 * the real StudioStore. Everything else is passed to the wrapped FakeWpdb.
 */
final class BuildSiteBriefWpdb
{
    public string $options = 'wp_options';

    public function __construct( private \Divi5Validator\Tests\FakeWpdb $inner )
    {
        $inner->query( 'CREATE TABLE IF NOT EXISTS wp_options (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)' );
    }

    public function __get( string $name ): mixed
    {
        return $this->inner->$name;
    }

    public function __set( string $name, mixed $value ): void
    {
        $this->inner->$name = $value;
    }

    public function __isset( string $name ): bool
    {
        return isset( $this->inner->$name );
    }

    /** @param list<mixed> $args */
    public function __call( string $name, array $args ): mixed
    {
        return $this->inner->$name( ...$args );
    }

    public function query( string $sql ): int|false
    {
        return $this->inner->query( str_replace( 'INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $sql ) );
    }
}
