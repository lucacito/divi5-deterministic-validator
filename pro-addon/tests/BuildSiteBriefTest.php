<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Lab\LabCompile;
use AiEditorDivi5\Pro\Lab\LabLook;
use AiEditorDivi5\Pro\Lab\LabSupport;
use AiEditorDivi5\Pro\Site\BuildLock;
use AiEditorDivi5\Pro\Tools\BuildSite;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StudioSlots;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../tests/WpInsertPostShims.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpSiteShims.php';
require_once __DIR__ . '/WpBuildShims.php';

/** build_site add_page with a Divi 5 Lab brief: the page is the direct compile, and one build has one look. */
final class BuildSiteBriefTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private BuildSite $tool;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->tool = new BuildSite( $this->log, new BuildLock( 0 ) );
        $GLOBALS['__wp_posts']           = [];
        $GLOBALS['__wp_postmeta']        = [];
        $GLOBALS['__wp_options']         = [];
        $GLOBALS['__wp_insert_fail']     = false;
        $GLOBALS['__wp_inserts']         = [];
        $GLOBALS['__wp_current_user_id'] = 7;
        $GLOBALS['__wp_caps']            = [ 'publish_pages' => true, 'edit_post' => true, 'delete_post' => true, 'edit_theme_options' => true, 'manage_options' => true ];
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'] );
        $GLOBALS['__wp_insert_fail'] = false;
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
        $this->assertSame( [ 'in_menu' => true, 'is_front' => false, 'menu_label' => 'Home', 'look' => [ 'language' => 'editorial', 'kit' => self::editorialKit() ] ], $items[0]['meta'] );

        $this->assertSame( [ 'brief_id' => null, 'stored' => false, 'unfilled' => count( $expected['unfilled'] ), 'report' => [
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
        $row['items'][]                        = [ 'kind' => 'page_created', 'ref' => '999', 'meta' => [ 'look' => $look ] ];
        $this->assertSame( 'mixed', BuildSite::buildLook( $row ) );
    }

    public function testAMixedBuildEnforcesNoLookButEveryBriefNamesItsLanguage(): void
    {
        $this->tool->run( self::page( self::brief( 'Home', 'luxe' ), 'home' ) );
        $this->repo->rows[1]['items'][0]['meta']['look'] = [ 'language' => 'editorial', 'kit' => 'raw-frame' ];
        $this->tool->run( self::page( self::brief( 'About', 'editorial', 'raw-frame' ), 'about', [ 'build_id' => 1 ] ) );
        $this->repo->rows[1]['items'][1]['meta']['look'] = [ 'language' => 'luxe', 'kit' => 'raw-frame' ];
        $this->assertSame( 'mixed', BuildSite::buildLook( $this->repo->rows[1] ) );

        $this->refused( self::page( self::brief( 'Team', null ), 'team', [ 'build_id' => 1 ] ), '"language"' );
        $r = $this->tool->run( self::page( self::brief( 'Team', 'tech-mono' ), 'team', [ 'build_id' => 1 ] ) );
        $this->assertSame( 'mixed', $r['build']['look'] );
    }
}
