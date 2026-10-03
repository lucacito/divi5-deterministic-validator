<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Tools\UpdateThemeLayout;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutWriter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';

class UpdateThemeLayoutTest extends TestCase
{
    use ThemeLayoutFixture;

    private UpdateThemeLayout $tool;

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $this->tool = new UpdateThemeLayout( $this->log );
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
    }

    private function refused( array $args, string $message ): void
    {
        $before = [ $this->layoutContent( 201 ), $GLOBALS['__wp_update_calls'], $this->historyRows( 201 ), count( $this->repo->rows ), $GLOBALS['__divi_cache_clears'] ];
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $after = [ $this->layoutContent( 201 ), $GLOBALS['__wp_update_calls'], $this->historyRows( 201 ), count( $this->repo->rows ), $GLOBALS['__divi_cache_clears'] ];
        $this->assertSame( $before, $after, 'a refused call changes, snapshots, records and clears nothing' );
    }

    public function testItSavesAValidLayoutSnapshotsClearsCachesAndRecordsAnUndoableBatch(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ 'layout_id' => 201, 'kind' => 'header', 'title' => 'Post 201' ], $r['layout'] );
        $this->assertSame( self::otherHeader(), $this->layoutContent( 201 ), 'stored byte for byte' );
        $this->assertTrue( $r['snapshot']['stored'] );
        $this->assertSame( 1, $this->historyRows( 201 ) );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'], 'Divi\'s Theme Builder cache cleared' );
        $this->assertSame( [ [ 'all', 'all' ] ], $GLOBALS['__divi_static_clears'], 'Divi\'s static resources cleared' );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( 'update_theme_layout', $this->repo->rows[ $r['batch']['id'] ]['tool'] );
        $this->assertSame( self::header(), $this->layoutContent( 202 ), 'other layouts are untouched' );
    }

    public function testADryRunOfContentThatIsAlreadyStoredStillReportsTheVerdict(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::header(), 'dry_run' => true ] );
        $this->assertFalse( $r['changed'] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertTrue( $r['valid'] );
        $this->assertSame( [], $r['violations'] );

        // A stored layout that already fails the checks says so in the same shape (the preview of a change does).
        $GLOBALS['__wp_posts'][201]->post_content = self::invalid();
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::invalid(), 'dry_run' => true ] );
        $this->assertFalse( $r['changed'] );
        $this->assertFalse( $r['valid'] );
        $this->assertNotEmpty( $r['violations'] );
        $this->assertArrayHasKey( 'code', $r['violations'][0] );
    }

    public function testARealRunOfContentThatIsAlreadyStoredHasNoVerdictFields(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::header() ] );
        $this->assertFalse( $r['changed'] );
        $this->assertArrayNotHasKey( 'valid', $r );
        $this->assertArrayNotHasKey( 'violations', $r );
    }

    public function testAFooterWorksToo(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 202, 'content' => self::otherHeader() ] );
        $this->assertSame( 'footer', $r['layout']['kind'] );
        $this->assertSame( self::otherHeader(), $this->layoutContent( 202 ) );
    }

    public function testAnInvalidLayoutIsRefusedWithTheViolationsAndNothingChanges(): void
    {
        $this->refused( [ 'layout_id' => 201, 'content' => self::invalid() ], 'The layout was not saved' );
    }

    public function testCustomCssIsRefusedByTheSharedGate(): void
    {
        $this->refused( [ 'layout_id' => 201, 'content' => self::withCss( 'selector{color:red}' ) ], 'CUSTOM_CSS_NOT_ALLOWED' );
    }

    public function testCodeAlreadyStoredIsPreservedNotNewlyForbidden(): void
    {
        // The baseline rule: a layout that already carries CSS can be edited as long as the edit adds no code.
        $css                                      = self::withCss( 'selector{color:red}' );
        $GLOBALS['__wp_posts'][201]->post_content = $css;
        $r                                        = $this->tool->run( [ 'layout_id' => 201, 'content' => str_replace( 'wp:divi/shop', 'wp:divi/search', $css ) ] );
        $this->assertTrue( $r['changed'] );
        $this->assertStringContainsString( 'freeForm', $this->layoutContent( 201 ), 'the CSS that was already there is kept' );
        $this->assertStringContainsString( 'wp:divi/search', $this->layoutContent( 201 ) );
    }

    public function testAnUnchangedLayoutChangesAndRecordsNothing(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::header() ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( 0, $this->historyRows( 201 ) );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testADryRunPreviewsAndTouchesNothing(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader(), 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['saved'] );
        $this->assertTrue( $r['valid'] );
        $this->assertSame( LayoutWriter::DRY_NOTE, $r['note'] );
        $this->assertNotNull( $r['diff'], 'the preview carries the structured diff' );
        $this->assertSame( self::header(), $this->layoutContent( 201 ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( 0, $this->historyRows( 201 ) );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testADryRunOfAnInvalidLayoutIsANormalPreviewWithValidFalse(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::invalid(), 'dry_run' => true ] );
        $this->assertFalse( $r['valid'] );
        $this->assertNotEmpty( $r['violations'] );
        $this->assertSame( self::header(), $this->layoutContent( 201 ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    /** @return list<array{int,string}> */
    public static function notEditable(): array
    {
        return [ [ 5, 'a page' ], [ 203, 'a body layout' ], [ 204, 'an orphaned header' ], [ 200, 'a template' ], [ 100, 'the Theme Builder post' ], [ 9999, 'a missing post' ] ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'notEditable' )]
    public function testOnlyLiveHeadersAndFootersCanBeWritten( int $id, string $why ): void
    {
        $before = $this->layoutContent( 5 );
        try {
            $this->tool->run( [ 'layout_id' => $id, 'content' => self::otherHeader() ] );
            $this->fail( "{$why} must be refused" );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'not an editable header or footer', $e->getMessage(), $why );
        }
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], $why );
        $this->assertSame( $before, $this->layoutContent( 5 ), $why );
    }

    public function testBadArgumentsAreRejectedNotCast(): void
    {
        $this->refused( [ 'content' => self::otherHeader() ], '"layout_id" is required.' );
        $this->refused( [ 'layout_id' => '201', 'content' => self::otherHeader() ], '"layout_id" must be an integer.' );
        $this->refused( [ 'layout_id' => 201 ], '"content" is required.' );
        $this->refused( [ 'layout_id' => 201, 'content' => 5 ], '"content" must be a string.' );
        $this->refused( [ 'layout_id' => 201, 'content' => '   ' ], '"content" must not be empty.' );
        $this->refused( [ 'layout_id' => 201, 'content' => self::otherHeader(), 'dry_run' => 'yes' ], '"dry_run" must be true or false.' );
        $this->refused( [ 'layout_id' => 201, 'content' => str_repeat( 'a', LayoutWriter::MAX_BYTES + 1 ) ], 'at most' );
    }

    public function testAFailedSaveIsAnErrorWithNoBatchAndNoCacheClear(): void
    {
        $GLOBALS['__wp_update_fail'] = true;
        try {
            $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );
            $this->fail( 'expected RuntimeException' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'simulated failure', $e->getMessage() );
        }
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
        $this->assertSame( self::header(), $this->layoutContent( 201 ) );
    }

    public function testAWriteWhoseSnapshotWasNotStoredSaysThereIsNoUndoPoint(): void
    {
        $write = static function ( int $id, string $content ): array {
            $GLOBALS['__wp_posts'][ $id ]->post_content = $content;

            return [ 'ok' => true, 'snapshot' => [ 'stored' => false, 'version_id' => null, 'reason' => 'too_large' ] ];
        };
        $tool = new UpdateThemeLayout( $this->log, new LayoutWriter( $this->log, $write ) );
        $r    = $tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );
        $this->assertTrue( $r['changed'] );
        $this->assertFalse( $r['snapshot']['stored'] );
        $undo = $this->undoEngine()->undo( $r['batch']['id'] );
        $this->assertSame( 'no_undo_point', $undo['items'][0]['result'] );
        $this->assertFalse( $undo['fully_undone'] );
        $this->assertSame( self::otherHeader(), $this->layoutContent( 201 ), 'nothing was invented' );
    }

    public function testABatchThatCannotBeRecordedStillReportsTheWriteHonestly(): void
    {
        $this->repo->failInsert = true;
        $r                      = $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );
        $this->assertTrue( $r['changed'] );
        $this->assertFalse( $r['batch']['undoable'] );
        $this->assertNull( $r['batch']['id'] );
        $this->assertSame( self::otherHeader(), $this->layoutContent( 201 ) );
    }

    public function testUndoRestoresTheOldContentClearsCachesAgainAndIsItselfUndoable(): void
    {
        $r                              = $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );
        $GLOBALS['__divi_cache_clears'] = [];
        $undo                           = $this->undoEngine()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( self::header(), $this->layoutContent( 201 ) );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'], 'undo clears Divi\'s caches too' );
        $this->assertTrue( $undo['undo_batch']['undoable'] );
        $again = $this->undoEngine()->undo( $undo['undo_batch']['id'] );
        $this->assertTrue( $again['fully_undone'] );
        $this->assertSame( self::otherHeader(), $this->layoutContent( 201 ) );
    }

    public function testUndoNeverOverwritesALayoutEditedInDiviLater(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );
        $GLOBALS['__wp_posts'][201]->post_content = 'EDITED IN THE BUILDER';
        $undo = $this->undoEngine()->undo( $r['batch']['id'] );
        $this->assertSame( 'changed_since', $undo['items'][0]['result'] );
        $this->assertSame( 'EDITED IN THE BUILDER', $this->layoutContent( 201 ) );
    }

    public function testUndoIsForbiddenOnceTheLayoutIsNoLongerLiveOrThePermissionIsGone(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );

        $GLOBALS['__divi_role_allowed'] = false;
        $this->assertSame( 'forbidden', $this->undoEngine()->undo( $r['batch']['id'] )['items'][0]['result'] );
        unset( $GLOBALS['__divi_role_allowed'] );

        $GLOBALS['__divi_tb']['template_ids'] = [];
        $this->assertSame( 'forbidden', $this->undoEngine()->undo( $r['batch']['id'] )['items'][0]['result'] );
        $GLOBALS['__divi_tb']['template_ids'] = [ 200 ];

        // undo_batch itself only needs edit_pages: the item must still demand the tools' own capability.
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true ];
        $this->assertSame( 'forbidden', $this->undoEngine()->undo( $r['batch']['id'] )['items'][0]['result'] );
        $this->assertSame( self::otherHeader(), $this->layoutContent( 201 ) );
    }

    public function testItIsRegisteredAndNeedsEditThemeOptions(): void
    {
        $this->assertContains( 'update_theme_layout', array_map( static fn ( $t ) => $t->name(), \AiEditorDivi5\Pro\Tools::all() ) );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $this->tool->definition()['description'] ) );
        $this->assertSame( [ 'layout_id', 'content' ], $this->tool->definition()['inputSchema']['required'] );
    }
}
