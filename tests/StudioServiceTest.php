<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\StudioService;
use AiEditorDivi5\WP\StudioStore;
use AiEditorDivi5\WP\StudioTable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/WpInsertPostShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/BlockAttrs.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageResolver.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DesignVariety.php';
require_once __DIR__ . '/../wp-plugin/src/LoopAdvisory.php';
require_once __DIR__ . '/../wp-plugin/src/PageTitle.php';
require_once __DIR__ . '/../wp-plugin/src/StudioLinks.php';
require_once __DIR__ . '/../wp-plugin/src/StudioSlots.php';
require_once __DIR__ . '/../wp-plugin/src/StudioBrief.php';
require_once __DIR__ . '/../wp-plugin/src/StudioCompiler.php';
require_once __DIR__ . '/../wp-plugin/src/StudioTable.php';
require_once __DIR__ . '/../wp-plugin/src/StudioStore.php';
require_once __DIR__ . '/../wp-plugin/src/DraftPage.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/StudioService.php';

/** Lab (internal name Studio), plan 2 task 4: the only code that saves a Studio page. */
class StudioServiceTest extends TestCase
{
    private const OWNER = 5;
    private const OTHER = 6;

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createStudioTable();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']     = [
            StudioTable::DB_VERSION_OPTION  => StudioTable::DB_VERSION,
            HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED,
        ];
        $GLOBALS['__wp_transients']  = [];
        $GLOBALS['__wp_posts']       = [];
        $GLOBALS['__wp_postmeta']    = [];
        $GLOBALS['__wp_inserts']     = [];
        $GLOBALS['__wp_update_fail'] = false;
        unset( $GLOBALS['__wp_update_calls'], $GLOBALS['__wp_insert_fail'], $GLOBALS['__wp_user_caps'] );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']            = null;
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        $GLOBALS['__wp_posts']      = [];
        $GLOBALS['__wp_postmeta']   = [];
        $GLOBALS['__wp_inserts']    = [];
        unset( $GLOBALS['__wp_update_calls'], $GLOBALS['__wp_insert_fail'], $GLOBALS['__wp_user_caps'] );
    }

    /** @return array<string,mixed> */
    private static function brief( string $headline = 'Fix leaks fast' ): array
    {
        return [
            'title'    => 'Acme plumbing',
            'goal'     => 'book',
            'language' => 'editorial',
            'sections' => [
                [ 'recipe' => 'hero-cta', 'slots' => [ 'heading.1' => $headline, 'button.0.text' => 'Book now', 'button.0.link' => '/contact/' ] ],
                [ 'recipe' => 'card-grid-3' ],
            ],
        ];
    }

    private function briefRows(): int
    {
        return (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . StudioTable::name() );
    }

    /** @return array<string,mixed> a created page's result, asserted ok */
    private function created( int $user = self::OWNER, ?array $brief = null ): array
    {
        $r = StudioService::create( $user, $brief ?? self::brief() );
        $this->assertTrue( $r['ok'], (string) json_encode( $r ) );

        return $r;
    }

    public function testPreviewWritesNothing(): void
    {
        $r = StudioService::preview( self::OWNER, self::brief() );
        $this->assertTrue( $r['ok'] );
        $this->assertNull( $r['code'] );
        $this->assertIsString( $r['message'] );
        $this->assertStringContainsString( 'Fix leaks fast', (string) $r['markup'] );
        foreach ( [ 'problems', 'violations', 'unfilled', 'refused', 'warnings', 'advice' ] as $key ) {
            $this->assertArrayHasKey( $key, $r['report'] );
        }
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
        $this->assertArrayNotHasKey( '__wp_update_calls', $GLOBALS );
        $this->assertSame( 0, $this->briefRows() );

        $bad = StudioService::preview( self::OWNER, [ 'title' => 'x' ] );
        $this->assertFalse( $bad['ok'] );
        $this->assertSame( StudioService::INVALID_BRIEF, $bad['code'] );
        $this->assertNull( $bad['markup'] );
        $this->assertNotSame( [], $bad['report']['problems'] );
        $this->assertSame( 0, $this->briefRows() );
    }

    public function testCreateMakesADraftAndStoresTheBrief(): void
    {
        $r = $this->created();
        $this->assertNull( $r['code'] );
        $this->assertFalse( $r['duplicate'] );
        $id   = (int) $r['page_id'];
        $post = $GLOBALS['__wp_posts'][ $id ];
        $this->assertSame( 'draft', $post->post_status );
        $this->assertSame( 'page', $post->post_type );
        $this->assertSame( 'Acme plumbing', $post->post_title );
        $this->assertStringContainsString( 'Fix leaks fast', $post->post_content );
        $this->assertSame( 'on', $GLOBALS['__wp_postmeta'][ $id ]['_et_pb_use_divi_5'] );
        $this->assertSame( 'https://example.com/wp-admin/post.php?post=' . $id . '&action=edit', $r['edit_url'] );

        $stored = StudioStore::get( self::OWNER, (int) $r['brief_id'] );
        $this->assertNotNull( $stored );
        $this->assertSame( $id, $stored['page_id'] );
        $this->assertSame( hash( 'sha256', $post->post_content ), $stored['content_hash'], 'the hash is of what is stored' );
        $this->assertSame( 'Acme plumbing', $stored['brief']['title'] );
        $this->assertSame( 'Fix leaks fast', $stored['brief']['sections'][0]['slots']['heading.1'] );

        // An existing brief id is updated in place, never duplicated.
        $again = StudioService::create( self::OWNER, self::brief( 'New words' ), (int) $r['brief_id'] );
        $this->assertTrue( $again['ok'] );
        $this->assertSame( $r['brief_id'], $again['brief_id'] );
        $this->assertNotSame( $id, $again['page_id'] );
        $this->assertSame( 1, $this->briefRows() );
    }

    public function testCreateRefusesAnInvalidCompile(): void
    {
        $brief             = self::brief();
        $brief['language'] = 'no-such-language';
        $r                 = StudioService::create( self::OWNER, $brief );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( StudioService::INVALID_BRIEF, $r['code'] );
        $this->assertNull( $r['page_id'] );
        $this->assertNull( $r['brief_id'] );
        $this->assertNotSame( [], $r['report']['problems'] );
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
        $this->assertSame( 0, $this->briefRows() );

        $notArray = StudioService::create( self::OWNER, [ 1, 2 ] );
        $this->assertFalse( $notArray['ok'] );
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
    }

    public function testDoubleCreateWithinTenSecondsReturnsTheFirst(): void
    {
        $first  = $this->created();
        $second = StudioService::create( self::OWNER, self::brief() );
        $this->assertTrue( $second['ok'] );
        $this->assertTrue( $second['duplicate'] );
        $this->assertSame( $first['page_id'], $second['page_id'] );
        $this->assertSame( $first['brief_id'], $second['brief_id'] );
        $this->assertCount( 1, $GLOBALS['__wp_inserts'] );
        $this->assertSame( 1, $this->briefRows() );

        // Another user with the same brief is not a duplicate of the first user's page.
        $other = $this->created( self::OTHER );
        $this->assertFalse( $other['duplicate'] );
        $this->assertNotSame( $first['page_id'], $other['page_id'] );

        // A different brief is not a duplicate.
        $changed = $this->created( self::OWNER, self::brief( 'Other words' ) );
        $this->assertFalse( $changed['duplicate'] );

        // Older than ten seconds: a new page.
        $GLOBALS['wpdb']->query( 'UPDATE ' . StudioTable::name() . " SET updated_at = '2000-01-01 00:00:00'" );
        $later = $this->created();
        $this->assertFalse( $later['duplicate'] );
        $this->assertNotSame( $first['page_id'], $later['page_id'] );
    }

    public function testRebuildOverwritesAnUntouchedDraftAfterASnapshot(): void
    {
        $r      = $this->created();
        $pageId = (int) $r['page_id'];
        $before = $GLOBALS['__wp_posts'][ $pageId ]->post_content;
        $this->assertTrue( StudioStore::save( self::OWNER, self::brief( 'Leaks fixed today' ), (int) $r['brief_id'] )['ok'] );

        $re = StudioService::rebuild( self::OWNER, (int) $r['brief_id'] );
        $this->assertTrue( $re['ok'], (string) json_encode( $re ) );
        $this->assertSame( 'overwritten', $re['mode'] );
        $this->assertNull( $re['reason'] );
        $this->assertSame( $pageId, $re['page_id'] );
        $this->assertCount( 1, $GLOBALS['__wp_inserts'], 'no new page' );
        $after = $GLOBALS['__wp_posts'][ $pageId ]->post_content;
        $this->assertStringContainsString( 'Leaks fixed today', $after );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][ $pageId ]->post_status );

        // The old content is a restorable snapshot labelled as this service's.
        $this->assertTrue( $re['history']['stored'] );
        $entry = HistoryService::entry( $pageId, (int) $re['history']['version_id'] );
        $this->assertSame( $before, $entry['content'] );
        $this->assertSame( StudioService::HISTORY_TOOL, $entry['tool'] );

        // The stored hash follows the new content, so a second rebuild overwrites again.
        $this->assertSame( hash( 'sha256', $after ), StudioStore::get( self::OWNER, (int) $r['brief_id'] )['content_hash'] );
        $twice = StudioService::rebuild( self::OWNER, (int) $r['brief_id'] );
        $this->assertSame( 'overwritten', $twice['mode'] );
    }

    /** A created page whose stored content (and the brief's hash of it) is replaced by $content. @return array{0:int,1:int} brief id, page id */
    private function linkedPageWith( string $content ): array
    {
        $r      = $this->created();
        $pageId = (int) $r['page_id'];
        $GLOBALS['__wp_posts'][ $pageId ]->post_content = $content;
        $this->assertTrue( StudioStore::link( self::OWNER, (int) $r['brief_id'], $pageId, hash( 'sha256', $content ) ) );

        return [ (int) $r['brief_id'], $pageId ];
    }

    /** @param array<string,mixed> $re */
    private function assertNewDraftLeavingTheOldPage( array $re, string $reason, int $pageId, string $before ): void
    {
        $this->assertTrue( $re['ok'], (string) json_encode( $re ) );
        $this->assertSame( 'new_draft', $re['mode'] );
        $this->assertSame( $reason, $re['reason'] );
        $this->assertNotSame( $pageId, $re['page_id'] );
        $this->assertSame( $before, $GLOBALS['__wp_posts'][ $pageId ]->post_content, 'the old page is byte-identical' );
        $this->assertArrayNotHasKey( '__wp_update_calls', $GLOBALS, 'the old page was never saved' );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][ (int) $re['page_id'] ]->post_status );
    }

    public function testRebuildOverContentTooLargeForHistoryMakesANewDraft(): void
    {
        $big = str_repeat( 'a', \AiEditorDivi5\WP\PageHistory::MAX_BYTES + 1 );
        [ $briefId, $pageId ] = $this->linkedPageWith( $big );
        $re = StudioService::rebuild( self::OWNER, $briefId );
        $this->assertNewDraftLeavingTheOldPage( $re, StudioService::REASON_NO_UNDO, $pageId, $big );
    }

    public function testRebuildOverInvalidUtf8MakesANewDraft(): void
    {
        $bad = "<!-- wp:divi/placeholder -->\xC3\x28<!-- /wp:divi/placeholder -->";
        [ $briefId, $pageId ] = $this->linkedPageWith( $bad );
        $re = StudioService::rebuild( self::OWNER, $briefId );
        $this->assertNewDraftLeavingTheOldPage( $re, StudioService::REASON_NO_UNDO, $pageId, $bad );
    }

    public function testRebuildWhenTheSnapshotCannotBeStoredMakesANewDraft(): void
    {
        $r      = $this->created();
        $pageId = (int) $r['page_id'];
        $before = $GLOBALS['__wp_posts'][ $pageId ]->post_content;
        $GLOBALS['wpdb']->failInserts = true; // the history row cannot be written
        $re = StudioService::rebuild( self::OWNER, (int) $r['brief_id'] );
        $GLOBALS['wpdb']->failInserts = false;
        $this->assertNewDraftLeavingTheOldPage( $re, StudioService::REASON_NO_UNDO, $pageId, $before );
    }

    public function testRebuildSnapshotRestoresByteForByteWithOneHistoryRow(): void
    {
        $r      = $this->created();
        $pageId = (int) $r['page_id'];
        $before = $GLOBALS['__wp_posts'][ $pageId ]->post_content;
        StudioStore::save( self::OWNER, self::brief( 'Something new' ), (int) $r['brief_id'] );
        $re = StudioService::rebuild( self::OWNER, (int) $r['brief_id'] );
        $this->assertSame( 'overwritten', $re['mode'] );
        $this->assertCount( 1, HistoryService::listFor( $pageId ), 'exactly one snapshot, no double capture' );
        $this->assertSame( 1, $GLOBALS['__wp_update_calls'] );
        $restored = HistoryService::restore( $pageId, (int) $re['history']['version_id'] );
        $this->assertTrue( $restored['ok'] );
        $this->assertSame( $before, $GLOBALS['__wp_posts'][ $pageId ]->post_content );

        // A second rebuild over content already in the history (a duplicate snapshot) is still undoable: overwrite.
        $this->assertTrue( StudioStore::link( self::OWNER, (int) $r['brief_id'], $pageId, hash( 'sha256', $before ) ) );
        $GLOBALS['__wp_posts'][ $pageId ]->post_content = $before;
        $again = StudioService::rebuild( self::OWNER, (int) $r['brief_id'] );
        $this->assertSame( 'overwritten', $again['mode'] );
    }

    public function testRebuildWithoutEditRightsOnThePageMakesANewDraft(): void
    {
        $r      = $this->created();
        $pageId = (int) $r['page_id'];
        $before = $GLOBALS['__wp_posts'][ $pageId ]->post_content;
        $GLOBALS['__wp_user_caps'][ self::OWNER ] = [ 'edit_post:' . $pageId => false, 'edit_post' => true ];
        $re = StudioService::rebuild( self::OWNER, (int) $r['brief_id'] );
        $this->assertNewDraftLeavingTheOldPage( $re, StudioService::REASON_NO_ACCESS, $pageId, $before );

        // With rights on the (new) linked page, the rebuild overwrites as before.
        $GLOBALS['__wp_user_caps'][ self::OWNER ] = [ 'edit_post' => true ];
        $ok = StudioService::rebuild( self::OWNER, (int) $r['brief_id'] );
        $this->assertSame( 'overwritten', $ok['mode'] );
        $this->assertSame( $re['page_id'], $ok['page_id'] );
    }

    public function testRebuildNeverOverwritesPendingScheduledOrPrivatePages(): void
    {
        foreach ( [ 'pending', 'future', 'private' ] as $status ) {
            $r      = $this->created( self::OWNER, self::brief( 'Status ' . $status ) );
            $pageId = (int) $r['page_id'];
            $before = $GLOBALS['__wp_posts'][ $pageId ]->post_content;
            $GLOBALS['__wp_posts'][ $pageId ]->post_status = $status;
            $re = StudioService::rebuild( self::OWNER, (int) $r['brief_id'] );
            $this->assertNewDraftLeavingTheOldPage( $re, 'published', $pageId, $before );
            $this->assertSame( $status, $GLOBALS['__wp_posts'][ $pageId ]->post_status );
        }
    }

    public function testRebuildOfAnEditedPageMakesANewDraft(): void
    {
        $r      = $this->created();
        $pageId = (int) $r['page_id'];
        $GLOBALS['__wp_posts'][ $pageId ]->post_content .= "\n<!-- edited in Divi -->";
        $edited = $GLOBALS['__wp_posts'][ $pageId ]->post_content;

        $re = StudioService::rebuild( self::OWNER, (int) $r['brief_id'] );
        $this->assertTrue( $re['ok'] );
        $this->assertSame( 'new_draft', $re['mode'] );
        $this->assertSame( 'edited', $re['reason'] );
        $this->assertNotSame( $pageId, $re['page_id'] );
        $this->assertSame( $edited, $GLOBALS['__wp_posts'][ $pageId ]->post_content, 'the edited page is untouched' );
        $this->assertArrayNotHasKey( '__wp_update_calls', $GLOBALS );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][ (int) $re['page_id'] ]->post_status );
        $this->assertSame( (int) $re['page_id'], StudioStore::get( self::OWNER, (int) $r['brief_id'] )['page_id'], 'the brief follows the new draft' );
        $this->assertStringContainsString( 'edited', $re['message'] );
    }

    public function testRebuildOfAPublishedPageMakesANewDraft(): void
    {
        $r      = $this->created();
        $pageId = (int) $r['page_id'];
        $GLOBALS['__wp_posts'][ $pageId ]->post_status = 'publish';

        $re = StudioService::rebuild( self::OWNER, (int) $r['brief_id'] );
        $this->assertTrue( $re['ok'] );
        $this->assertSame( 'new_draft', $re['mode'] );
        $this->assertSame( 'published', $re['reason'] );
        $this->assertNotSame( $pageId, $re['page_id'] );
        $this->assertSame( 'publish', $GLOBALS['__wp_posts'][ $pageId ]->post_status );
        $this->assertArrayNotHasKey( '__wp_update_calls', $GLOBALS );
    }

    public function testRebuildOfATrashedOrMissingPageMakesANewDraft(): void
    {
        $r      = $this->created();
        $pageId = (int) $r['page_id'];
        $GLOBALS['__wp_posts'][ $pageId ]->post_status = 'trash';
        $re = StudioService::rebuild( self::OWNER, (int) $r['brief_id'] );
        $this->assertSame( 'new_draft', $re['mode'] );
        $this->assertSame( 'trashed', $re['reason'] );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][ $pageId ]->post_status );

        unset( $GLOBALS['__wp_posts'][ (int) $re['page_id'] ] );
        $gone = StudioService::rebuild( self::OWNER, (int) $r['brief_id'] );
        $this->assertTrue( $gone['ok'] );
        $this->assertSame( 'new_draft', $gone['mode'] );
        $this->assertSame( 'missing', $gone['reason'] );
        $this->assertArrayHasKey( (int) $gone['page_id'], $GLOBALS['__wp_posts'] );
        $this->assertArrayNotHasKey( '__wp_update_calls', $GLOBALS );
    }

    public function testRebuildNeverTouchesAnotherUsersBrief(): void
    {
        $r       = $this->created();
        $pageId  = (int) $r['page_id'];
        $content = $GLOBALS['__wp_posts'][ $pageId ]->post_content;

        $re = StudioService::rebuild( self::OTHER, (int) $r['brief_id'] );
        $this->assertFalse( $re['ok'] );
        $this->assertSame( StudioService::NOT_FOUND, $re['code'] );
        $this->assertNull( $re['page_id'] );
        $this->assertCount( 1, $GLOBALS['__wp_inserts'] );
        $this->assertSame( $content, $GLOBALS['__wp_posts'][ $pageId ]->post_content );
        $this->assertArrayNotHasKey( '__wp_update_calls', $GLOBALS );

        $create = StudioService::create( self::OTHER, self::brief( 'Hijack' ), (int) $r['brief_id'] );
        $this->assertFalse( $create['ok'] );
        $this->assertSame( StudioService::NOT_FOUND, $create['code'] );
        $this->assertCount( 1, $GLOBALS['__wp_inserts'] );
        $this->assertSame( 'Fix leaks fast', StudioStore::get( self::OWNER, (int) $r['brief_id'] )['brief']['sections'][0]['slots']['heading.1'] );

        $hand = StudioService::handoff( self::OTHER, (int) $r['brief_id'], 'https://example.com' );
        $this->assertFalse( $hand['ok'] );
        $this->assertSame( StudioService::NOT_FOUND, $hand['code'] );
        $this->assertSame( '', $hand['instruction'] );

        $this->assertSame( StudioService::NOT_FOUND, StudioService::rebuild( self::OWNER, 999 )['code'] );
    }

    public function testRemovedRecipeFailsAndKeepsTheOtherSections(): void
    {
        $brief = self::brief();
        $brief['sections'][1] = [ 'recipe' => 'retired-layout', 'slots' => [ 'heading.0' => 'Old words' ] ];
        $brief['sections'][]  = [ 'recipe' => 'faq-accordion', 'slots' => [ 'heading.0' => 'Kept words' ] ];
        $saved = StudioStore::save( self::OWNER, $brief );
        $this->assertTrue( $saved['ok'] );

        $re = StudioService::rebuild( self::OWNER, $saved['id'] );
        $this->assertFalse( $re['ok'] );
        $this->assertSame( StudioService::RECIPE_REMOVED, $re['code'] );
        $this->assertSame( 1, $re['section'] );
        $this->assertStringContainsString( 'retired-layout', $re['message'] );
        $this->assertStringContainsString( 'section 2', $re['message'] );
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
        $this->assertSame( $brief, StudioStore::get( self::OWNER, $saved['id'] )['brief'], 'every section\'s words are kept' );
    }

    public function testHandoffTextHasTheNoInventedFactsRule(): void
    {
        $saved = StudioStore::save( self::OWNER, self::brief() );
        $r     = StudioService::handoff( self::OWNER, $saved['id'], 'https://example.com' );
        $this->assertTrue( $r['ok'] );
        $text = $r['instruction'];
        $this->assertStringContainsString( 'do not invent facts, prices, names or numbers; leave a slot empty if unknown', strtolower( $text ) );
        $this->assertStringContainsString( StudioService::TOOL, $text );
        $this->assertSame( 'lab_build', StudioService::TOOL );
        $this->assertStringContainsString( '#' . $saved['id'], $text );
        $this->assertStringContainsString( 'https://example.com', $text );
        $this->assertStringNotContainsStringIgnoringCase( 'studio', $text );
        $this->assertStringNotContainsString( 'Acme plumbing', $text, 'no brief text is echoed' );

        // A site value that is not a plain address is left out, never echoed.
        $odd = StudioService::handoff( self::OWNER, $saved['id'], '<script>alert(1)</script>' );
        $this->assertTrue( $odd['ok'] );
        $this->assertStringNotContainsString( '<', $odd['instruction'] );
        $this->assertStringNotContainsString( 'alert', $odd['instruction'] );
    }

    public function testPlaceholdersNeverBlockACreate(): void
    {
        $brief = [ 'title' => 'Only placeholders', 'goal' => 'explain', 'language' => 'luxe', 'sections' => [ [ 'recipe' => 'hero-cta' ], [ 'recipe' => 'card-grid-3', 'slots' => [ 'text.0.body.0' => '   ' ] ] ] ];
        $r     = StudioService::create( self::OWNER, $brief );
        $this->assertTrue( $r['ok'], (string) json_encode( $r ) );
        $this->assertNotNull( $r['page_id'] );
        $this->assertNotSame( [], $r['report']['unfilled'] );
        $sections = array_unique( array_column( $r['report']['unfilled'], 'section' ) );
        sort( $sections );
        $this->assertSame( [ 0, 1 ], $sections );
    }
}
