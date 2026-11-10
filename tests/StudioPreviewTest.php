<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryCapture;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\StudioPreview;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/WpInsertPostShims.php';
require_once __DIR__ . '/GetPostsShim.php';
require_once __DIR__ . '/WpPreviewShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/DraftPage.php';
require_once __DIR__ . '/../wp-plugin/src/StudioPreview.php';

/**
 * Divi 5 Lab (Studio), plan 2 task 5: the live preview. Mode "draft" (decision in docs/studio-preview-evidence.md) is
 * ONE private draft per user, updated again and again; mode "approximation" is the labelled fallback.
 */
class StudioPreviewTest extends TestCase
{
    private const A = 5;
    private const B = 6;

    private const MARKUP_1 = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.8.0"} --><!-- wp:divi/text {"builderVersion":"5.8.0","content":{"innerContent":{"desktop":{"value":"First"}}}} /--><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    private const MARKUP_2 = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.8.0"} --><!-- wp:divi/text {"builderVersion":"5.8.0","content":{"innerContent":{"desktop":{"value":"Second"}}}} /--><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']     = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_filters']     = [];
        $GLOBALS['__wp_posts']       = [];
        $GLOBALS['__wp_postmeta']    = [];
        $GLOBALS['__wp_inserts']     = [];
        $GLOBALS['__wp_deleted']     = [];
        $GLOBALS['__wp_update_fail'] = false;
        $GLOBALS['__get_posts_calls'] = [];
        $GLOBALS['__get_posts_handler'] = 'aied_preview_shim_get_posts';
        unset( $GLOBALS['__wp_update_calls'], $GLOBALS['__wp_insert_fail'], $GLOBALS['__wp_update_hook'], $GLOBALS['__get_posts_loose'] );
        $GLOBALS['__wp_current_user_id'] = self::A;
        HistoryCapture::reset();
        HistoryCapture::register(); // the real pre_post_update listener, so an unwrapped update WOULD write history
        add_action( 'delete_post', [ HistoryStore::class, 'deleteForPage' ] ); // as HistoryTable::register does in WordPress
    }

    protected function tearDown(): void
    {
        HistoryCapture::reset();
        $GLOBALS['wpdb']                = null;
        $GLOBALS['__wp_options']        = [];
        $GLOBALS['__wp_filters']        = [];
        $GLOBALS['__wp_posts']          = [];
        $GLOBALS['__wp_postmeta']       = [];
        $GLOBALS['__wp_inserts']        = [];
        $GLOBALS['__wp_deleted']        = [];
        $GLOBALS['__get_posts_handler'] = null;
        unset( $GLOBALS['__wp_update_calls'], $GLOBALS['__wp_insert_fail'], $GLOBALS['__wp_update_hook'], $GLOBALS['__get_posts_loose'], $GLOBALS['__wp_current_user_id'] );
        $GLOBALS['__wp_update_fail'] = false;
    }

    private function as( int $user ): void
    {
        $GLOBALS['__wp_current_user_id'] = $user;
    }

    private static function title( int $user ): string
    {
        return 'Divi 5 Lab preview (temporary) ' . $user;
    }

    /** Puts a post straight into the fake site (not through StudioPreview). */
    private function seed( string $title, int $author, string $status = 'draft', string $type = 'page', string $modified = '', string $content = self::MARKUP_1 ): int
    {
        $id                    = max( array_merge( [ 200 ], array_keys( $GLOBALS['__wp_posts'] ) ) ) + 1;
        $p                     = new \WP_Post();
        $p->ID                 = $id;
        $p->post_type          = $type;
        $p->post_status        = $status;
        $p->post_title         = $title;
        $p->post_author        = $author;
        $p->post_content       = $content;
        $p->post_modified_gmt  = '' === $modified ? gmdate( 'Y-m-d H:i:s' ) : $modified;
        $GLOBALS['__wp_posts'][ $id ] = $p;

        return $id;
    }

    private static function old(): string
    {
        return gmdate( 'Y-m-d H:i:s', time() - 25 * 3600 );
    }

    private function historyRows(): int
    {
        return (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . HistoryTable::name() );
    }

    /** @return list<int> */
    private function previewIds(): array
    {
        return array_keys( array_filter( $GLOBALS['__wp_posts'], static fn ( $p ) => str_starts_with( $p->post_title, 'Divi 5 Lab preview (temporary) ' ) ) );
    }

    // ---- draft mode ----------------------------------------------------------------------

    public function testTheDecidedModeIsDraft(): void
    {
        $this->assertSame( 'draft', StudioPreview::MODE );
    }

    public function testReusesTheSameDraftPerUser(): void
    {
        $first = StudioPreview::url( self::A, self::MARKUP_1 );
        $this->assertSame( 'draft', $first['mode'] );
        $this->assertNull( $first['html'] );
        $this->assertCount( 1, $this->previewIds() );
        $id = $this->previewIds()[0];
        $this->assertSame( 'https://example.com/?page_id=' . $id . '&preview=true', $first['url'] );
        $post = $GLOBALS['__wp_posts'][ $id ];
        $this->assertSame( self::title( self::A ), $post->post_title );
        $this->assertSame( 'page', $post->post_type );
        $this->assertSame( self::A, $post->post_author );
        $this->assertSame( self::MARKUP_1, $post->post_content );
        $this->assertSame( 'on', $GLOBALS['__wp_postmeta'][ $id ]['_et_pb_use_divi_5'], 'made by DraftPage::insert' );

        $second = StudioPreview::url( self::A, self::MARKUP_2 );
        $this->assertSame( $first['url'], $second['url'], 'the same draft, the same address' );
        $this->assertCount( 1, $this->previewIds(), 'no second draft' );
        $this->assertCount( 1, $GLOBALS['__wp_inserts'] );
        $this->assertSame( self::MARKUP_2, $GLOBALS['__wp_posts'][ $id ]->post_content, 'the content was updated in place' );

        // Another user gets a draft of their own; neither touches the other's.
        $this->as( self::B );
        $other = StudioPreview::url( self::B, self::MARKUP_1 );
        $this->assertNotSame( $first['url'], $other['url'] );
        $this->assertCount( 2, $this->previewIds() );
        $this->assertSame( self::MARKUP_2, $GLOBALS['__wp_posts'][ $id ]->post_content );
    }

    public function testASecondCallWithTheSameMarkupWritesNothing(): void
    {
        StudioPreview::url( self::A, self::MARKUP_1 );
        StudioPreview::url( self::A, self::MARKUP_1 );
        $this->assertArrayNotHasKey( '__wp_update_calls', $GLOBALS );
    }

    public function testNeverPublishesAndNeverGivesASlug(): void
    {
        StudioPreview::url( self::A, self::MARKUP_1 );
        StudioPreview::url( self::A, self::MARKUP_2 );
        $post = $GLOBALS['__wp_posts'][ $this->previewIds()[0] ];
        $this->assertSame( 'draft', $post->post_status );
        $this->assertSame( '', $post->post_name );
    }

    public function testAPublishedPageWithTheSameTitleIsNeverReusedOrTouched(): void
    {
        $published = $this->seed( self::title( self::A ), self::A, 'publish' );
        $trashed   = $this->seed( self::title( self::A ), self::A, 'trash' );
        $result    = StudioPreview::url( self::A, self::MARKUP_2 );
        $this->assertSame( 'draft', $result['mode'] );
        $this->assertSame( self::MARKUP_1, $GLOBALS['__wp_posts'][ $published ]->post_content );
        $this->assertSame( self::MARKUP_1, $GLOBALS['__wp_posts'][ $trashed ]->post_content );
        $this->assertCount( 1, $GLOBALS['__wp_inserts'], 'a new draft was made' );
    }

    public function testAnotherAuthorsDraftWithThePreviewTitleIsNotReused(): void
    {
        $theirs = $this->seed( self::title( self::A ), self::B );
        StudioPreview::url( self::A, self::MARKUP_2 );
        $this->assertSame( self::MARKUP_1, $GLOBALS['__wp_posts'][ $theirs ]->post_content );
        $this->assertCount( 1, $GLOBALS['__wp_inserts'] );
    }

    public function testExtraDuplicatesOfTheUsersOwnPreviewAreRemoved(): void
    {
        $a = $this->seed( self::title( self::A ), self::A );
        $b = $this->seed( self::title( self::A ), self::A );
        StudioPreview::url( self::A, self::MARKUP_2 );
        $this->assertSame( [ $a ], $this->previewIds() );
        $this->assertSame( self::MARKUP_2, $GLOBALS['__wp_posts'][ $a ]->post_content );
        $this->assertSame( [ [ $b, true ] ], $GLOBALS['__wp_deleted'] );
    }

    public function testTheUpdateLeavesNoHistoryRows(): void
    {
        StudioPreview::url( self::A, self::MARKUP_1 );
        $id = $this->previewIds()[0];

        // Control: the real capture listener is registered, so a plain save of this page DOES write a row.
        wp_update_post( wp_slash( [ 'ID' => $id, 'post_content' => self::MARKUP_2 ] ), true );
        $this->assertSame( 1, $this->historyRows(), 'control: the capture hook is live in this test' );
        wp_update_post( wp_slash( [ 'ID' => $id, 'post_content' => self::MARKUP_1 ] ), true );
        $rows = $this->historyRows();
        $this->assertSame( 2, $rows );

        // The preview's own update writes none.
        $suspendedDuringSave = null;
        $GLOBALS['__wp_update_hook'] = static function () use ( &$suspendedDuringSave ): void {
            $suspendedDuringSave = HistoryCapture::isSuspended();
        };
        StudioPreview::url( self::A, self::MARKUP_2 );
        $this->assertSame( self::MARKUP_2, $GLOBALS['__wp_posts'][ $id ]->post_content );
        $this->assertTrue( $suspendedDuringSave, 'the save ran with capture suspended' );
        $this->assertSame( $rows, $this->historyRows(), 'no history row for a preview update' );
        $this->assertFalse( HistoryCapture::isSuspended(), 'capture is back on afterwards' );
    }

    public function testTheUpdateWritesNoRevisions(): void
    {
        StudioPreview::url( self::A, self::MARKUP_1 );
        $seen = null;
        $GLOBALS['__wp_update_hook'] = static function () use ( &$seen ): void {
            $seen = apply_filters( 'wp_revisions_to_keep', -1, null );
        };
        StudioPreview::url( self::A, self::MARKUP_2 );
        $this->assertSame( 0, $seen, 'core saves no revision while the preview updates its draft' );
        $this->assertSame( -1, apply_filters( 'wp_revisions_to_keep', -1, null ), 'the filter is removed afterwards' );
    }

    public function testFailuresFallBackToTheApproximationAndNeverThrow(): void
    {
        $GLOBALS['__wp_insert_fail'] = true;
        $r = StudioPreview::url( self::A, self::MARKUP_1 );
        $this->assertSame( 'approximation', $r['mode'] );
        $this->assertNull( $r['url'] );
        $this->assertStringContainsString( 'Approximate preview', (string) $r['html'] );
        $this->assertSame( [], $this->previewIds() );

        unset( $GLOBALS['__wp_insert_fail'] );
        StudioPreview::url( self::A, self::MARKUP_1 );
        $GLOBALS['__wp_update_fail'] = true;
        $r = StudioPreview::url( self::A, self::MARKUP_2 );
        $this->assertSame( 'approximation', $r['mode'] );
        $this->assertFalse( HistoryCapture::isSuspended(), 'a failed save still switches capture back on' );
    }

    public function testASavingFailureDoesNotLeaveTheRevisionFilterOn(): void
    {
        StudioPreview::url( self::A, self::MARKUP_1 );
        $GLOBALS['__wp_update_fail'] = true;
        StudioPreview::url( self::A, self::MARKUP_2 );
        $this->assertSame( -1, apply_filters( 'wp_revisions_to_keep', -1, null ) );
    }

    public function testRefusesEmptyMarkupAndAWrongUser(): void
    {
        foreach ( [ '', "  \n\t" ] as $empty ) {
            $r = StudioPreview::url( self::A, $empty );
            $this->assertSame( 'approximation', $r['mode'] );
            $this->assertNull( $r['url'] );
            $this->assertStringContainsString( 'Nothing to preview', (string) $r['html'] );
        }
        $this->assertSame( [], $GLOBALS['__wp_inserts'], 'empty markup never reaches the site' );

        $r = StudioPreview::url( 0, self::MARKUP_1 );
        $this->assertSame( 'approximation', $r['mode'] );
        $r = StudioPreview::url( self::B, self::MARKUP_1 ); // the current user is A: a draft would be authored by A
        $this->assertSame( 'approximation', $r['mode'] );
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
    }

    // ---- approximation -------------------------------------------------------------------

    public function testApproximationIsLabelled(): void
    {
        $r = StudioPreview::url( self::A, self::MARKUP_1, StudioPreview::MODE_APPROXIMATION );
        $this->assertSame( 'approximation', $r['mode'] );
        $this->assertNull( $r['url'] );
        $this->assertStringContainsString( 'Approximate preview', (string) $r['html'] );
        $this->assertStringContainsString( 'First', (string) $r['html'], 'the compiled markup is shown' );
        $this->assertSame( [], $GLOBALS['__wp_inserts'], 'this mode writes nothing' );
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
    }

    public function testApproximationMarkupIsInert(): void
    {
        $hostile = '<!-- wp:divi/text {"x":"</pre><script>alert(1)</script><img src=x onerror=alert(2)>"} /-->';
        $html    = (string) StudioPreview::url( self::A, $hostile, StudioPreview::MODE_APPROXIMATION )['html'];
        $this->assertStringNotContainsString( '<script', $html );
        $this->assertStringNotContainsString( '<img', $html );
        $this->assertStringContainsString( '&lt;script&gt;', $html );
        $this->assertSame( 1, preg_match_all( '/<pre\b/', $html ), 'one pre block, and the markup cannot close it' );
        $this->assertSame( 1, preg_match_all( '#</pre>#', $html ) );
    }

    public function testApproximationIsBounded(): void
    {
        $html = (string) StudioPreview::url( self::A, str_repeat( 'x', 200000 ), StudioPreview::MODE_APPROXIMATION )['html'];
        $this->assertLessThan( 40000, strlen( $html ) );
        $this->assertStringContainsString( 'cut', $html );
    }

    /** Final fix pass, S1: the cut never splits a character, or esc_html would return '' and the text would vanish. */
    public function testApproximationCutKeepsMultibyteTextIntact(): void
    {
        // 'x' then 'é' (2 bytes) repeated: a byte cut at the limit lands inside a character.
        $markup = 'x' . str_repeat( 'é', StudioPreview::APPROXIMATION_LIMIT );
        $html   = (string) StudioPreview::url( self::A, $markup, StudioPreview::MODE_APPROXIMATION )['html'];
        preg_match( '#<pre[^>]*>(.*)</pre>#s', $html, $m );
        $this->assertNotEmpty( $m[1] ?? '', 'the cut text is still shown' );
        $this->assertSame( 1, preg_match( '//u', $m[1] ), 'valid UTF-8' );
        $this->assertLessThanOrEqual( StudioPreview::APPROXIMATION_LIMIT, strlen( html_entity_decode( $m[1] ) ) );
    }

    /** Final fix pass, S4: the words of the approximation are translatable. */
    public function testApproximationWordsAreTranslatable(): void
    {
        $src = (string) file_get_contents( __DIR__ . '/../wp-plugin/src/StudioPreview.php' );
        $this->assertDoesNotMatchRegularExpression( "/esc_html\(\s*'/", $src, 'no esc_html of a literal: use esc_html__' );
        foreach ( [ 'Approximate preview', 'Nothing to preview yet.', 'The text above was cut short.' ] as $words ) {
            $this->assertMatchesRegularExpression( '/(?:esc_html__|__)\(\s*\'' . preg_quote( $words, '/' ) . "',\s*'jhmg-ai-editor-for-divi-5'/", $src, $words );
        }
    }

    // ---- cleanup -------------------------------------------------------------------------

    public function testCleanupDeletesIt(): void
    {
        StudioPreview::url( self::A, self::MARKUP_1 );
        $id = $this->previewIds()[0];
        // The page has history from earlier saves by other tools; deleting the page removes it too.
        wp_update_post( wp_slash( [ 'ID' => $id, 'post_content' => self::MARKUP_2 ] ), true );
        $this->assertSame( 1, $this->historyRows() );

        $this->as( self::B );
        StudioPreview::url( self::B, self::MARKUP_1 );
        $other = array_values( array_diff( $this->previewIds(), [ $id ] ) )[0];

        StudioPreview::cleanup( self::A );
        $this->assertSame( [ $other ], $this->previewIds(), 'only the user\'s own preview went' );
        $this->assertSame( [ [ $id, true ] ], $GLOBALS['__wp_deleted'], 'permanent delete' );
        $this->assertSame( 0, $this->historyRows(), 'its history went with it' );

        StudioPreview::cleanup( self::A ); // nothing left: no error
        $this->assertSame( [ $other ], $this->previewIds() );
    }

    public function testCleanupIsScopedByExactTitleAuthorTypeAndStatus(): void
    {
        $mine        = $this->seed( self::title( self::A ), self::A );
        $otherAuthor = $this->seed( self::title( self::A ), self::B );
        $published   = $this->seed( self::title( self::A ), self::A, 'publish' );
        $isPost      = $this->seed( self::title( self::A ), self::A, 'draft', 'post' );
        $lookalike   = $this->seed( self::title( self::A ) . ' extra', self::A );
        $prefixOnly  = $this->seed( 'Divi 5 Lab preview (temporary) ', self::A );
        $ordinary    = $this->seed( 'About us', self::A );
        $hisTitle    = $this->seed( self::title( self::B ), self::B );

        StudioPreview::cleanup( self::A );
        $this->assertArrayNotHasKey( $mine, $GLOBALS['__wp_posts'] );
        foreach ( [ $otherAuthor, $published, $isPost, $lookalike, $prefixOnly, $ordinary, $hisTitle ] as $kept ) {
            $this->assertArrayHasKey( $kept, $GLOBALS['__wp_posts'], 'post ' . $kept . ' must stay' );
        }
        $this->assertSame( [ [ $mine, true ] ], $GLOBALS['__wp_deleted'] );
    }

    public function testCleanupChecksEveryRowAgainEvenWhenTheQueryReturnsEverything(): void
    {
        $mine = $this->seed( self::title( self::A ), self::A );
        $keep = [
            $this->seed( 'About us', self::A ),
            $this->seed( self::title( self::A ), self::B ),
            $this->seed( self::title( self::A ), self::A, 'publish' ),
            $this->seed( self::title( self::A ), self::A, 'draft', 'post' ),
        ];
        $GLOBALS['__get_posts_loose'] = true; // the query ignores every filter
        StudioPreview::cleanup( self::A );
        $this->assertArrayNotHasKey( $mine, $GLOBALS['__wp_posts'] );
        foreach ( $keep as $id ) {
            $this->assertArrayHasKey( $id, $GLOBALS['__wp_posts'] );
        }
        $this->assertCount( 1, $GLOBALS['__wp_deleted'] );
    }

    public function testCleanupOfAnInvalidUserDoesNothing(): void
    {
        $id = $this->seed( 'Divi 5 Lab preview (temporary) 0', 0 );
        StudioPreview::cleanup( 0 );
        StudioPreview::cleanup( -3 );
        $this->assertArrayHasKey( $id, $GLOBALS['__wp_posts'] );
        $this->assertSame( [], $GLOBALS['__wp_deleted'] );
    }

    public function testStaleOnesAreRemoved(): void
    {
        $stale     = $this->seed( self::title( self::A ), self::A, 'draft', 'page', self::old() );
        $staleB    = $this->seed( self::title( self::B ), self::B, 'draft', 'page', self::old() );
        $fresh     = $this->seed( self::title( 7 ), 7 );
        $justUnder = $this->seed( self::title( 8 ), 8, 'draft', 'page', gmdate( 'Y-m-d H:i:s', time() - 23 * 3600 ) );

        // Look-alikes that are old but must stay.
        $someoneElses = $this->seed( self::title( self::A ), self::B, 'draft', 'page', self::old() ); // title names user 5, author is 6
        $published    = $this->seed( self::title( 9 ), 9, 'publish', 'page', self::old() );
        $isPost       = $this->seed( self::title( 10 ), 10, 'draft', 'post', self::old() );
        $withSuffix   = $this->seed( self::title( 11 ) . ' copy', 11, 'draft', 'page', self::old() );
        $notNumeric   = $this->seed( 'Divi 5 Lab preview (temporary) abc', 12, 'draft', 'page', self::old() );
        $noId         = $this->seed( 'Divi 5 Lab preview (temporary) ', 13, 'draft', 'page', self::old() );
        $zero         = $this->seed( 'Divi 5 Lab preview (temporary) 0', 0, 'draft', 'page', self::old() );
        $ordinary     = $this->seed( 'Old draft about us', 14, 'draft', 'page', self::old() );

        $this->assertSame( 2, StudioPreview::cleanupStale() );
        $this->assertArrayNotHasKey( $stale, $GLOBALS['__wp_posts'] );
        $this->assertArrayNotHasKey( $staleB, $GLOBALS['__wp_posts'] );
        foreach ( [ $fresh, $justUnder, $someoneElses, $published, $isPost, $withSuffix, $notNumeric, $noId, $zero, $ordinary ] as $kept ) {
            $this->assertArrayHasKey( $kept, $GLOBALS['__wp_posts'], 'post ' . $kept . ' must stay' );
        }
        $this->assertSame( [ [ $stale, true ], [ $staleB, true ] ], $GLOBALS['__wp_deleted'] );
    }

    /**
     * Found by the live gate (make verify-studio): WordPress stores post_modified_gmt as 0000-00-00 00:00:00 for a draft
     * that was inserted and never updated (a draft has no GMT date yet), so a preview draft made by its first preview
     * looked older than any cutoff and the sweep deleted it while the person was still using it. Such a row is judged by
     * its local post_modified instead.
     */
    public function testANeverUpdatedDraftIsJudgedByItsLocalModifiedTime(): void
    {
        $fresh = $this->seed( self::title( self::A ), self::A, 'draft', 'page', '0000-00-00 00:00:00' );
        $GLOBALS['__wp_posts'][ $fresh ]->post_modified = gmdate( 'Y-m-d H:i:s' ); // the shim's site runs on UTC
        $old = $this->seed( self::title( self::B ), self::B, 'draft', 'page', '0000-00-00 00:00:00' );
        $GLOBALS['__wp_posts'][ $old ]->post_modified = self::old();
        $unknown = $this->seed( self::title( 7 ), 7, 'draft', 'page', '0000-00-00 00:00:00' ); // no local time at all: kept

        $this->assertSame( 1, StudioPreview::cleanupStale() );
        $this->assertArrayHasKey( $fresh, $GLOBALS['__wp_posts'], 'a preview draft made a moment ago must stay' );
        $this->assertArrayNotHasKey( $old, $GLOBALS['__wp_posts'] );
        $this->assertArrayHasKey( $unknown, $GLOBALS['__wp_posts'] );
    }

    public function testStaleSweepChecksEveryRowAgainEvenWhenTheQueryReturnsEverything(): void
    {
        $stale = $this->seed( self::title( self::A ), self::A, 'draft', 'page', self::old() );
        $keep  = [
            $this->seed( self::title( 7 ), 7 ), // fresh
            $this->seed( self::title( self::A ), self::B, 'draft', 'page', self::old() ),
            $this->seed( 'About', 3, 'draft', 'page', self::old() ),
            $this->seed( self::title( 9 ), 9, 'publish', 'page', self::old() ),
        ];
        $GLOBALS['__get_posts_loose'] = true;
        $this->assertSame( 1, StudioPreview::cleanupStale() );
        $this->assertArrayNotHasKey( $stale, $GLOBALS['__wp_posts'] );
        foreach ( $keep as $id ) {
            $this->assertArrayHasKey( $id, $GLOBALS['__wp_posts'] );
        }
    }

    public function testStaleSweepRemovesHistoryAndNeverThrows(): void
    {
        $stale = $this->seed( self::title( self::A ), self::A, 'draft', 'page', self::old() );
        HistoryService::capture( $stale, self::MARKUP_1, 'unknown', null );
        $this->assertSame( 1, $this->historyRows() );
        StudioPreview::cleanupStale();
        $this->assertSame( 0, $this->historyRows() );

        $GLOBALS['__get_posts_handler'] = static function (): never {
            throw new \RuntimeException( 'database gone' );
        };
        $this->assertSame( 0, StudioPreview::cleanupStale() );
        StudioPreview::cleanup( self::A );
        StudioPreview::cleanupAll();
        $r = StudioPreview::url( self::A, self::MARKUP_1 );
        $this->assertSame( 'approximation', $r['mode'] );
    }

    public function testCleanupAllRemovesEveryUsersPreviewAndNothingElse(): void
    {
        $a = $this->seed( self::title( self::A ), self::A );
        $b = $this->seed( self::title( self::B ), self::B, 'draft', 'page', self::old() );
        $keep = [
            $this->seed( self::title( self::A ), self::B ), // another author
            $this->seed( self::title( 7 ), 7, 'publish' ),
            $this->seed( self::title( 8 ), 8, 'draft', 'post' ),
            $this->seed( self::title( 9 ) . 'x', 9 ),
            $this->seed( 'Contact', 1 ),
        ];
        StudioPreview::cleanupAll();
        $this->assertArrayNotHasKey( $a, $GLOBALS['__wp_posts'] );
        $this->assertArrayNotHasKey( $b, $GLOBALS['__wp_posts'] );
        foreach ( $keep as $id ) {
            $this->assertArrayHasKey( $id, $GLOBALS['__wp_posts'] );
        }
        foreach ( $GLOBALS['__wp_deleted'] as $d ) {
            $this->assertTrue( $d[1], 'always a permanent delete' );
        }
    }

    public function testCleanupAllPagesThroughMoreThanOneBatch(): void
    {
        for ( $u = 1; $u <= 130; $u++ ) {
            $this->seed( self::title( $u ), $u );
        }
        StudioPreview::cleanupAll();
        $this->assertSame( [], $this->previewIds() );
    }

    public function testUninstallRunsCleanupAll(): void
    {
        $source = (string) file_get_contents( __DIR__ . '/../wp-plugin/uninstall.php' );
        $this->assertStringContainsString( 'StudioPreview::cleanupAll()', $source );
        $this->assertLessThan( strpos( $source, 'HistoryStore::deleteAll()' ), strpos( $source, 'StudioPreview::cleanupAll()' ), 'before the history table goes' );
    }

    /** Final fix pass, S7: the once-an-hour guard of the stale sweep goes too. */
    public function testUninstallDeletesTheSweepGuard(): void
    {
        $source = (string) file_get_contents( __DIR__ . '/../wp-plugin/uninstall.php' );
        $this->assertStringContainsString( 'delete_transient( AiEditorDivi5\\WP\\StudioAjax::SWEEP_TRANSIENT )', $source );
    }

    public function testTheClassIsLoadedByTheAutoloader(): void
    {
        $this->assertStringContainsString( "StudioPreview.php", (string) file_get_contents( __DIR__ . '/../wp-plugin/src/autoload.php' ) );
    }
}
