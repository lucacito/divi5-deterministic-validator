<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Site\PreviewToken;
use AiEditorDivi5\Pro\Tools\FindReplaceSite;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';
require_once __DIR__ . '/WpSiteShims.php';

class FindReplaceSiteTest extends TestCase
{
    use ThemeLayoutFixture;

    private FindReplaceSite $tool;
    private int $now = 1000000;

    /** A valid Divi 5 layout whose heading says $word. */
    private static function text( string $word ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $word . '"}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $this->tool = new FindReplaceSite( $this->log, null, fn (): int => $this->now );
        $GLOBALS['__wp_caps']['edit_posts'] = true;
        foreach ( [ 11, 12, 13 ] as $id ) {
            $this->themePost( $id, 'page', 'publish', [ '_et_pb_use_divi_5' => 'on' ], self::text( 'Hello AIED-OLD world' ) );
        }
        $this->themePost( 14, 'page', 'draft', [ '_et_pb_use_divi_5' => 'on' ], self::text( 'Draft AIED-OLD' ) );
        $this->themePost( 15, 'page', 'publish', [ '_et_pb_use_divi_5' => 'on' ], self::text( 'No match here' ) );
        $this->themePost( 16, 'page', 'trash', [ '_et_pb_use_divi_5' => 'on' ], self::text( 'Trashed AIED-OLD' ) );
        $this->themePost( 17, 'page', 'publish', [], '<p>Classic AIED-OLD page, not Divi 5</p>' );
        $this->themePost( 18, 'post', 'publish', [ '_et_pb_use_divi_5' => 'on' ], self::text( 'A post AIED-OLD' ) );
        $GLOBALS['__wp_posts'][201]->post_content = self::text( 'Header AIED-OLD' );
        $GLOBALS['__wp_posts'][202]->post_content = self::text( 'Footer AIED-OLD' );
        $GLOBALS['__wp_posts'][5]->post_content   = '';   // the fixture page is not a Divi 5 page
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function args( array $extra = [] ): array
    {
        return $extra + [ 'find' => 'AIED-OLD', 'replace' => 'AIED-NEW' ];
    }

    /** Preview, then apply with the token. @param array<string,mixed> $extra @return array<string,mixed> */
    private function apply( array $extra = [] ): array
    {
        $p = $this->tool->run( $this->args( $extra ) + [ 'dry_run' => true ] );
        $this->assertArrayHasKey( 'token', $p );

        return $this->tool->run( $this->args( $extra ) + [ 'token' => $p['token'] ] );
    }

    private function refused( array $args, string $message ): void
    {
        $before = [ array_map( fn ( $i ) => $this->layoutContent( $i ), [ 11, 12, 13, 14, 201, 202 ] ), $GLOBALS['__wp_update_calls'], count( $this->repo->rows ) ];
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( $before, [ array_map( fn ( $i ) => $this->layoutContent( $i ), [ 11, 12, 13, 14, 201, 202 ] ), $GLOBALS['__wp_update_calls'], count( $this->repo->rows ) ] );
    }

    public function testADryRunListsTheMatchesAndATokenAndChangesNothing(): void
    {
        $r = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 4, $r['matched'], 'pages 11-13 and the draft 14; not 15 (no match), 16 (trash), 17 (not Divi 5), 18 (a post)' );
        $this->assertSame( [ 11, 12, 13, 14 ], array_column( $r['items'], 'id' ) );
        $this->assertSame( 4, $r['would_change'] );
        $this->assertSame( 0, $r['would_skip'] );
        $this->assertSame( 1, $r['items'][0]['occurrences'] );
        $this->assertNotNull( $r['items'][0]['diff'] );
        $this->assertNotSame( '', $r['token'] );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testARealRunReplacesEverywhereAsOneUndoableBatch(): void
    {
        $r = $this->apply();
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 4, $r['replaced_in'] );
        $this->assertSame( [], $r['skipped'] );
        $this->assertSame( self::text( 'Hello AIED-NEW world' ), $this->layoutContent( 11 ) );
        $this->assertSame( self::text( 'Draft AIED-NEW' ), $this->layoutContent( 14 ) );
        $this->assertSame( self::text( 'No match here' ), $this->layoutContent( 15 ) );
        $this->assertSame( self::text( 'Trashed AIED-OLD' ), $this->layoutContent( 16 ) );
        $this->assertSame( '<p>Classic AIED-OLD page, not Divi 5</p>', $this->layoutContent( 17 ) );
        $this->assertSame( self::text( 'A post AIED-OLD' ), $this->layoutContent( 18 ) );
        $this->assertSame( self::text( 'Header AIED-OLD' ), $this->layoutContent( 201 ), 'layouts only with scope layouts or all' );
        $this->assertCount( 1, $this->repo->rows, 'one batch for the whole run' );
        $this->assertCount( 4, $this->repo->rows[ $r['batch']['id'] ]['items'] );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( 1, $this->historyRows( 11 ) );

        $undo = new \AiEditorDivi5\Pro\Batch\BatchUndo( [ 'post_content' => new \AiEditorDivi5\Pro\Kinds\PostContentKind() ], $this->log );
        $u    = $undo->undo( $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( self::text( 'Hello AIED-OLD world' ), $this->layoutContent( 11 ) );
        $this->assertSame( self::text( 'Draft AIED-OLD' ), $this->layoutContent( 14 ) );
    }

    public function testEveryOccurrenceInAnItemIsReplaced(): void
    {
        $GLOBALS['__wp_posts'][11]->post_content = self::text( 'AIED-OLD and AIED-OLD again' );
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->assertSame( 2, $p['items'][0]['occurrences'] );
        $this->apply();
        $this->assertSame( self::text( 'AIED-NEW and AIED-NEW again' ), $this->layoutContent( 11 ) );
    }

    public function testARealRunWithoutAValidTokenIsRefusedAndChangesNothing(): void
    {
        $this->refused( $this->args(), 'needs the token' );
        $this->refused( $this->args( [ 'token' => 'nope' ] ), 'needs the token' );
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->refused( $this->args( [ 'token' => $p['token'], 'replace' => 'DIFFERENT' ] ), 'needs the token' );
        $this->refused( $this->args( [ 'token' => $p['token'], 'scope' => 'all' ] ), 'needs the token' );
        $this->refused( $this->args( [ 'token' => $p['token'], 'page_ids' => [ 11 ] ] ), 'needs the token' );
        $this->refused( $this->args( [ 'token' => $p['token'] . 'x' ] ), 'needs the token' );
    }

    public function testATokenExpires(): void
    {
        $p        = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->now += PreviewToken::TTL + 1;
        $this->refused( $this->args( [ 'token' => $p['token'] ] ), 'needs the token' );
    }

    public function testAnItemChangedSinceThePreviewIsSkippedAndReportedTheRestApply(): void
    {
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $GLOBALS['__wp_posts'][12]->post_content = self::text( 'Edited in the builder AIED-OLD' );
        $r = $this->tool->run( $this->args( [ 'token' => $p['token'] ] ) );
        $this->assertSame( 3, $r['replaced_in'] );
        $this->assertSame( [ [ 'kind' => 'page', 'id' => 12, 'title' => 'Post 12', 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
        $this->assertSame( self::text( 'Edited in the builder AIED-OLD' ), $this->layoutContent( 12 ), 'never overwritten' );
        $this->assertSame( self::text( 'Hello AIED-NEW world' ), $this->layoutContent( 11 ) );
    }

    public function testANewMatchAfterThePreviewIsNotTouched(): void
    {
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $GLOBALS['__wp_posts'][15]->post_content = self::text( 'Now AIED-OLD too' );
        $r = $this->tool->run( $this->args( [ 'token' => $p['token'] ] ) );
        $this->assertSame( 4, $r['replaced_in'] );
        $this->assertSame( 'not_in_preview', $r['skipped'][0]['reason'] );
        $this->assertSame( self::text( 'Now AIED-OLD too' ), $this->layoutContent( 15 ) );
    }

    public function testAnItemWhoseResultWouldFailTheChecksIsSkippedNeverSaved(): void
    {
        $args = [ 'find' => 'wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Draft', 'replace' => 'wp:divi/not-a-module {"title":{"innerContent":{"desktop":{"value":"Draft' ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertSame( 1, $p['matched'] );
        $this->assertFalse( $p['items'][0]['valid'] );
        $this->assertSame( 1, $p['would_skip'] );
        $this->assertNotEmpty( $p['items'][0]['violations'] );
        $r = $this->tool->run( $args + [ 'token' => $p['token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 'invalid_result', $r['skipped'][0]['reason'] );
        $this->assertSame( self::text( 'Draft AIED-OLD' ), $this->layoutContent( 14 ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testAnInvalidItemNeverStopsTheOthers(): void
    {
        // page 11 would become invalid; page 12 stays fine
        $GLOBALS['__wp_posts'][11]->post_content = str_replace( 'Hello AIED-OLD world', 'X AIED-OLD', self::text( 'x' ) );
        $args = [ 'find' => 'wp:divi/heading', 'replace' => 'wp:divi/not-a-module', 'page_ids' => [ 11 ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertSame( 1, $p['would_skip'] );
        $this->apply();
        $this->assertSame( self::text( 'Hello AIED-NEW world' ), $this->layoutContent( 12 ) );
    }

    public function testMoreThanFiftyItemsAreRefusedWithTheCountNotTruncated(): void
    {
        for ( $i = 100; $i < 151; $i++ ) {
            $this->themePost( $i, 'page', 'publish', [ '_et_pb_use_divi_5' => 'on' ], self::text( 'many AIED-OLD' ) );
        }
        $this->refused( $this->args( [ 'dry_run' => true ] ), '55 items contain that text; the limit is 50' );
        $r = $this->tool->run( $this->args( [ 'dry_run' => true, 'page_ids' => range( 100, 149 ) ] ) );
        $this->assertSame( 50, $r['matched'], 'exactly fifty is allowed' );
    }

    public function testPageIdsNarrowTheScope(): void
    {
        $r = $this->apply( [ 'page_ids' => [ 12, 13, 15, 17, 999 ] ] );
        $this->assertSame( 2, $r['replaced_in'] );
        $this->assertSame( self::text( 'Hello AIED-OLD world' ), $this->layoutContent( 11 ) );
        $this->assertSame( self::text( 'Hello AIED-NEW world' ), $this->layoutContent( 12 ) );
    }

    public function testAPageTheUserCannotEditIsNeverSeen(): void
    {
        $GLOBALS['__wp_caps']['edit_post:13'] = false;
        $r = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->assertSame( [ 11, 12, 14 ], array_column( $r['items'], 'id' ) );
    }

    public function testLayoutsAreIncludedOnlyWithScopeAndThemeBuilderPermission(): void
    {
        $r = $this->tool->run( $this->args( [ 'dry_run' => true, 'scope' => 'layouts' ] ) );
        $this->assertSame( [ 201, 202 ], array_column( $r['items'], 'id' ) );
        $this->assertSame( [ 'header', 'footer' ], array_column( $r['items'], 'kind' ) );

        $r = $this->tool->run( $this->args( [ 'dry_run' => true, 'scope' => 'all' ] ) );
        $this->assertSame( [ 11, 12, 13, 14, 201, 202 ], array_column( $r['items'], 'id' ) );

        $GLOBALS['__divi_role_allowed'] = false;
        $r = $this->tool->run( $this->args( [ 'dry_run' => true, 'scope' => 'all' ] ) );
        $this->assertSame( [ 11, 12, 13, 14 ], array_column( $r['items'], 'id' ), 'without Divi\'s bar the layouts are left out' );
        $this->assertStringContainsString( 'skipped', $r['layouts_note'] );
        unset( $GLOBALS['__divi_role_allowed'] );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true ];   // no edit_theme_options
        $r = $this->tool->run( $this->args( [ 'dry_run' => true, 'scope' => 'all' ] ) );
        $this->assertSame( [ 11, 12, 13, 14 ], array_column( $r['items'], 'id' ) );
        $this->refused( $this->args( [ 'scope' => 'layouts' ] ), 'permission' );
    }

    public function testALayoutIsWrittenThroughTheLayoutKindAndClearsDiviCaches(): void
    {
        $r = $this->apply( [ 'scope' => 'layouts' ] );
        $this->assertSame( 2, $r['replaced_in'] );
        $this->assertSame( self::text( 'Header AIED-NEW' ), $this->layoutContent( 201 ) );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'] );
        $kinds = array_column( $this->repo->rows[ $r['batch']['id'] ]['items'], 'kind' );
        $this->assertSame( [ 'theme_layout_content', 'theme_layout_content' ], $kinds );

        $u = $this->undoEngine()->undo( $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( self::text( 'Header AIED-OLD' ), $this->layoutContent( 201 ) );
    }

    public function testAMixedBatchUndoesPagesAndLayoutsTogether(): void
    {
        $r    = $this->apply( [ 'scope' => 'all' ] );
        $undo = new \AiEditorDivi5\Pro\Batch\BatchUndo(
            [ 'post_content' => new \AiEditorDivi5\Pro\Kinds\PostContentKind(), 'theme_layout_content' => new \AiEditorDivi5\Pro\Kinds\ThemeLayoutKind() ],
            $this->log
        );
        $u = $undo->undo( $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( self::text( 'Footer AIED-OLD' ), $this->layoutContent( 202 ) );
        $this->assertSame( self::text( 'Hello AIED-OLD world' ), $this->layoutContent( 11 ) );
    }

    public function testNoMatchChangesAndRecordsNothing(): void
    {
        $r = $this->tool->run( [ 'find' => 'NOT-ANYWHERE', 'replace' => 'x' ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 0, $r['matched'] );
        $this->assertArrayNotHasKey( 'token', $r );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testASaveFailureSkipsTheItemAndRecordsNothing(): void
    {
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $GLOBALS['__wp_update_fail'] = true;
        $r = $this->tool->run( $this->args( [ 'token' => $p['token'] ] ) );
        $this->assertFalse( $r['changed'] );
        $this->assertCount( 4, $r['skipped'] );
        $this->assertSame( 'save_failed', $r['skipped'][0]['reason'] );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testAWriteWithoutAStoredSnapshotHasNoUndoPoint(): void
    {
        $write = static function ( int $id, string $content ): array {
            $GLOBALS['__wp_posts'][ $id ]->post_content = $content;

            return [ 'ok' => true, 'snapshot' => [ 'stored' => false, 'version_id' => null, 'reason' => 'too_large' ] ];
        };
        $tool = new FindReplaceSite( $this->log, $write, fn (): int => $this->now );
        $p    = $tool->run( $this->args( [ 'dry_run' => true, 'page_ids' => [ 11 ] ] ) );
        $r    = $tool->run( $this->args( [ 'page_ids' => [ 11 ], 'token' => $p['token'] ] ) );
        $u    = ( new \AiEditorDivi5\Pro\Batch\BatchUndo( [ 'post_content' => new \AiEditorDivi5\Pro\Kinds\PostContentKind() ], $this->log ) )->undo( $r['batch']['id'] );
        $this->assertSame( 'no_undo_point', $u['items'][0]['result'] );
        $this->assertFalse( $u['fully_undone'] );
    }

    public function testABatchThatCannotBeRecordedStillReportsTheWriteHonestly(): void
    {
        $this->repo->failInsert = true;
        $r                      = $this->apply( [ 'page_ids' => [ 11 ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertFalse( $r['batch']['undoable'] );
        $this->assertSame( self::text( 'Hello AIED-NEW world' ), $this->layoutContent( 11 ) );
    }

    public function testUndoNeverOverwritesAPageEditedSince(): void
    {
        $r = $this->apply( [ 'page_ids' => [ 11, 12 ] ] );
        $GLOBALS['__wp_posts'][12]->post_content = 'EDITED LATER';
        $u = ( new \AiEditorDivi5\Pro\Batch\BatchUndo( [ 'post_content' => new \AiEditorDivi5\Pro\Kinds\PostContentKind() ], $this->log ) )->undo( $r['batch']['id'] );
        $this->assertFalse( $u['fully_undone'] );
        $this->assertSame( 'EDITED LATER', $this->layoutContent( 12 ) );
        $this->assertSame( self::text( 'Hello AIED-OLD world' ), $this->layoutContent( 11 ) );
    }

    public function testBadArgumentsAreRejectedNotCast(): void
    {
        $this->refused( [ 'replace' => 'x' ], '"find" is required.' );
        $this->refused( [ 'find' => 5, 'replace' => 'x' ], '"find" must be a string.' );
        $this->refused( [ 'find' => '  ', 'replace' => 'x' ], '"find" must not be empty.' );
        $this->refused( [ 'find' => 'a' ], '"replace" is required' );
        $this->refused( [ 'find' => 'a', 'replace' => 5 ], '"replace" must be a string.' );
        $this->refused( [ 'find' => 'a', 'replace' => 'a' ], 'identical' );
        $this->refused( $this->args( [ 'scope' => 'everything' ] ), '"scope" must be one of: pages, layouts, all.' );
        $this->refused( $this->args( [ 'scope' => 5 ] ), '"scope" must be a string.' );
        $this->refused( $this->args( [ 'page_ids' => 'x' ] ), '"page_ids" must be a list.' );
        $this->refused( $this->args( [ 'page_ids' => [] ] ), '"page_ids" must not be empty.' );
        $this->refused( $this->args( [ 'page_ids' => [ '1' ] ] ), 'positive integers' );
        $this->refused( $this->args( [ 'page_ids' => [ 0 ] ] ), 'positive integers' );
        $this->refused( $this->args( [ 'dry_run' => 'yes' ] ), '"dry_run" must be true or false.' );
        $this->refused( $this->args( [ 'token' => 5 ] ), '"token" must be a string.' );
        $this->refused( [ 'find' => str_repeat( 'a', FindReplaceSite::MAX_BYTES + 1 ), 'replace' => 'x' ], 'at most' );
    }

    public function testItIsRegisteredAndNeedsEditPages(): void
    {
        $this->assertContains( 'find_replace_site', array_map( static fn ( $t ) => $t->name(), \AiEditorDivi5\Pro\Tools::all() ) );
        $this->assertSame( 'edit_pages', $this->tool->capability() );
        $d = $this->tool->definition();
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'find', 'replace' ], $d['inputSchema']['required'] );
    }
}
