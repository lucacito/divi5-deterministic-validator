<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryCapture;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistorySources;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\PageHistory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpAbilityStub.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';

/**
 * Undo for every Divi page change, whatever made it. The real save path is simulated by the bootstrap's wp_update_post, which
 * fires pre_post_update with the old content still stored, exactly as core does; the capture is registered as in the plugin.
 */
class HistoryCaptureTest extends TestCase
{
    private const OLD = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/text {"builderVersion":"5.14.0"} /--><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    private const NEW = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/text {"builderVersion":"5.14.0","x":1} /--><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']     = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_filters']     = [];
        $post                        = new \WP_Post();
        $post->ID                    = 5;
        $post->post_content          = self::OLD;
        $GLOBALS['__wp_posts']       = [ 5 => $post ];
        $GLOBALS['__wp_postmeta']    = [];
        $GLOBALS['__wp_update_fail'] = false;
        HistoryCapture::reset();
        HistoryCapture::register();
    }

    protected function tearDown(): void
    {
        HistoryCapture::reset();
        $GLOBALS['wpdb']         = null;
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_filters'] = [];
    }

    /** Another tool saves the page, the way core would. */
    private function foreignSave( string $content = self::NEW, int $id = 5 ): void
    {
        wp_update_post( wp_slash( [ 'ID' => $id, 'post_content' => $content ] ), true );
    }

    /** @return list<array<string,mixed>> */
    private function versions( int $id = 5 ): array
    {
        return HistoryService::listFor( $id );
    }

    private function rest( string $route ): void
    {
        $request = new class( $route ) {
            public function __construct( private string $route ) {}
            public function get_route(): string { return $this->route; }
        };
        HistoryCapture::onRestPreDispatch( null, null, $request );
    }

    // ---- sources -------------------------------------------------------------------------

    public function testASaveByAnUnknownSourceKeepsTheOldContentLabelledUnknown(): void
    {
        $this->foreignSave();
        $v = $this->versions();
        $this->assertCount( 1, $v );
        $this->assertSame( 'unknown', $v[0]['tool'] );
        $this->assertSame( self::OLD, HistoryService::entry( 5, $v[0]['id'] )['content'] );
        $this->assertSame( self::NEW, $GLOBALS['__wp_posts'][5]->post_content, 'the save itself went through untouched' );
    }

    public function testASimulatedRestWriteIsLabelledRest(): void
    {
        $this->rest( '/wp/v2/pages/5' );
        $this->foreignSave();
        $this->assertSame( 'rest', $this->versions()[0]['tool'] );
    }

    public function testASimulatedApplicationPasswordWriteKeepsOnlyTheNameOfThePassword(): void
    {
        HistoryCapture::onApplicationPassword( (object) [], [ 'name' => 'Cursor on laptop', 'uuid' => 'abc-123', 'password' => 'HASHED-SECRET', 'app_id' => 'z' ] );
        $this->rest( '/wp/v2/pages/5' );
        $this->foreignSave();
        $v = $this->versions();
        $this->assertSame( 'application_password', $v[0]['tool'] );
        $this->assertSame( 'Cursor on laptop', $v[0]['label'] );
        $dump = json_encode( $GLOBALS['wpdb']->get_results( 'SELECT * FROM wp_ai_editor_divi5_history' ) );
        $this->assertStringNotContainsString( 'HASHED-SECRET', (string) $dump, 'no credential is ever stored' );
        $this->assertStringNotContainsString( 'abc-123', (string) $dump );
    }

    public function testAnAgentCallingAnAbilityIsLabelledWithTheAbilityName(): void
    {
        ( new \WP_Ability( 'divi/update-page-content' ) )->execute( fn () => $this->foreignSave() );
        $v = $this->versions();
        $this->assertSame( 'ability', $v[0]['tool'] );
        $this->assertSame( 'divi/update-page-content', $v[0]['label'] );

        $GLOBALS['__wp_posts'][5]->post_content = self::OLD . '<!-- later -->';
        $this->foreignSave();
        $this->assertSame( 'unknown', $this->versions()[0]['tool'], 'once the ability has finished its name is forgotten' );
    }

    public function testAFailedAbilityDoesNotLeakItsNameOntoALaterSave(): void
    {
        // core never fires wp_after_execute_ability for a failed ability; the name must not outlive the call anyway
        ( new \WP_Ability( 'divi/will-fail' ) )->execute( static fn () => new \WP_Error( 'x', 'failed' ) );
        $this->foreignSave();
        $this->assertSame( 'unknown', $this->versions()[0]['tool'] );
    }

    public function testBuilderSavesAreNotCapturedByDefaultButAreWhenSwitchedOn(): void
    {
        $this->rest( '/divi/v1/sync-to-server' );
        $this->foreignSave();
        $this->assertSame( [], $this->versions(), 'WordPress revisions already cover the builder' );

        $GLOBALS['__wp_options'][ HistorySources::OPTION ] = [ 'builder' => true ];
        $GLOBALS['__wp_posts'][5]->post_content = self::OLD;
        $this->foreignSave();
        $this->assertSame( 'builder', $this->versions()[0]['tool'] );
    }

    public function testASwitchedOffSourceKeepsNothing(): void
    {
        $GLOBALS['__wp_options'][ HistorySources::OPTION ] = [ 'rest' => false ];
        $this->rest( '/wp/v2/pages/5' );
        $this->foreignSave();
        $this->assertSame( [], $this->versions() );
    }

    // ---- this plugin's own writes are not doubled ----------------------------------------

    public function testThePluginsOwnWriteIsSnapshottedOnceWithItsOwnToolName(): void
    {
        $r = HistoryService::write( 5, self::NEW, 'update_page_layout' );
        $this->assertTrue( $r['snapshot']['stored'] );
        $v = $this->versions();
        $this->assertCount( 1, $v, 'the universal capture stays out of a write that snapshots for itself' );
        $this->assertSame( 'update_page_layout', $v[0]['tool'] );
    }

    public function testTitleOnlyAndRestoreWritesAreNotDoubledEither(): void
    {
        $w = HistoryService::write( 5, self::NEW, 'update_page_layout' );
        $before = count( $this->versions() );
        HistoryService::writeTitle( 5, 'A new title', 'update_page_layout' );
        $this->assertCount( $before, $this->versions(), 'a title change takes no snapshot' );
        HistoryService::restore( 5, $w['snapshot']['version_id'] );
        $tools = array_column( $this->versions(), 'tool' );
        $this->assertSame( 1, count( array_keys( $tools, 'restore', true ) ), 'a restore adds exactly its own undo point' );
        $this->assertNotContains( 'unknown', $tools );
    }

    // ---- what is not captured ------------------------------------------------------------

    public function testUnchangedContentAndOtherPostsAreSkipped(): void
    {
        $this->foreignSave( self::OLD ); // status-only style update: same content
        $this->assertSame( [], $this->versions() );

        $GLOBALS['__wp_posts'][5]->post_content = 'plain <b>html</b>, no Divi';
        $this->foreignSave();
        $this->assertSame( [], $this->versions(), 'only Divi 5 content is kept' );

        $post = new \WP_Post();
        $post->ID = 9;
        $post->post_type = 'post';
        $post->post_content = self::OLD;
        $GLOBALS['__wp_posts'][9] = $post;
        $this->foreignSave( self::NEW, 9 );
        $this->assertSame( [], $this->versions( 9 ), 'a blog post is not a page or Divi Project' );

        $post = new \WP_Post();
        $post->ID = 10;
        $post->post_type = 'project';
        $post->post_content = self::OLD;
        $GLOBALS['__wp_posts'][10] = $post;
        $this->foreignSave( self::NEW, 10 );
        $this->assertCount( 1, $this->versions( 10 ), 'a Divi Project is kept' );
    }

    public function testASaveWithoutContentInItIsIgnored(): void
    {
        HistoryCapture::onPreUpdate( 5, [ 'post_title' => 'x' ] );
        HistoryCapture::onPreUpdate( 0, [ 'post_content' => 'x' ] );
        HistoryCapture::onPreUpdate( '5', [ 'post_content' => 'x' ] );
        HistoryCapture::onPreUpdate( 404, [ 'post_content' => 'x' ] );
        $this->assertSame( [], $this->versions() );
    }

    public function testSizeLimitsStillApplyAndTheSaveStillGoesThrough(): void
    {
        $big = self::OLD . str_repeat( ' ', PageHistory::MAX_BYTES + 10 );
        $GLOBALS['__wp_posts'][5]->post_content = $big;
        $this->foreignSave();
        $this->assertSame( [], $this->versions(), 'a version over 512 KB is not kept' );
        $this->assertSame( self::NEW, $GLOBALS['__wp_posts'][5]->post_content, 'and the save is never held up by it' );
    }

    public function testOnlyTheLastTenVersionsAreKept(): void
    {
        for ( $i = 0; $i < 13; ++$i ) {
            $GLOBALS['__wp_posts'][5]->post_content = self::OLD . "<!-- {$i} -->";
            $this->foreignSave( self::NEW . "<!-- {$i} -->" );
        }
        $this->assertCount( PageHistory::RETENTION, $this->versions() );
    }

    // ---- restore -------------------------------------------------------------------------

    public function testASnapshotFromAnySourceCanBeRestoredAndTheRestoreCanBeUndone(): void
    {
        foreach ( [ 'rest', 'application_password', 'ability', 'unknown' ] as $i => $source ) {
            HistoryCapture::reset();
            $GLOBALS['__wp_posts'][5]->post_content = self::OLD . "<!-- {$source} -->";
            match ( $source ) {
                'rest' => $this->rest( '/wp/v2/pages/5' ),
                'application_password' => HistoryCapture::onApplicationPassword( null, [ 'name' => 'App' ] ),
                default => null,
            };
            'ability' === $source
                ? ( new \WP_Ability( 'other/ability' ) )->execute( fn () => $this->foreignSave( self::NEW . "<!-- {$source} -->" ) )
                : $this->foreignSave( self::NEW . "<!-- {$source} -->" );
            HistoryCapture::reset();

            $entry = $this->versions()[0];
            $this->assertSame( $source, $entry['tool'] );
            $r = HistoryService::restore( 5, $entry['id'] );
            $this->assertTrue( $r['ok'], $source );
            $this->assertSame( self::OLD . "<!-- {$source} -->", $GLOBALS['__wp_posts'][5]->post_content, "{$source}: the old content is back" );
            $this->assertTrue( $r['snapshot']['stored'], "{$source}: the restore is itself undoable" );

            $undo = HistoryService::restore( 5, $r['snapshot']['version_id'] );
            $this->assertSame( self::NEW . "<!-- {$source} -->", $GLOBALS['__wp_posts'][5]->post_content, "{$source}: undoing the restore brings the foreign save back" );
            $this->assertTrue( $undo['ok'] );
        }
    }

    // ---- safety --------------------------------------------------------------------------

    public function testSuspendAlwaysSwitchesCaptureBackOn(): void
    {
        try {
            HistoryCapture::suspend( static function (): void {
                throw new \RuntimeException( 'boom' );
            } );
        } catch ( \RuntimeException $e ) {
            // expected
        }
        $this->foreignSave();
        $this->assertCount( 1, $this->versions(), 'an exception inside a suspended call must not leave capture off' );
    }

    public function testTheHooksReturnTheirValuesUntouched(): void
    {
        $this->assertSame( 'sentinel', HistoryCapture::onRestPreDispatch( 'sentinel', null, new \stdClass() ) );
        $this->assertNull( HistoryCapture::onRestPreDispatch( null ) );
    }
}
