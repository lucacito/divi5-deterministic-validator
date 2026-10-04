<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\PageCreatedKind;
use AiEditorDivi5\Pro\Kinds\PageTrashedKind;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';

/** A page the build created, and how an undo takes it away again (and brings it back). */
class PageCreatedKindTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $GLOBALS['__wp_posts'] = [ 50 => (object) [ 'ID' => 50, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'About us', 'post_content' => 'C0', 'post_name' => 'about' ] ];
        $GLOBALS['__wp_caps']  = [ 'edit_post' => true, 'delete_post' => true ];
        $GLOBALS['__wp_trash_fail']   = false;
        $GLOBALS['__wp_untrash_fail'] = false;
        $GLOBALS['__wp_untrash_vanish'] = false;
        PageCreatedKind::$trashEnabled = null;
    }

    protected function tearDown(): void
    {
        PageCreatedKind::$trashEnabled = null;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_trash_fail'], $GLOBALS['__wp_untrash_fail'], $GLOBALS['__wp_untrash_vanish'] );
    }

    /** @return array<string,mixed> */
    private function item(): array
    {
        return PageCreatedKind::itemFor( $GLOBALS['__wp_posts'][50], [ 'in_menu' => true, 'is_front' => false, 'menu_label' => 'About' ] );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ 'page_created' => new PageCreatedKind(), 'page_trashed' => new PageTrashedKind() ], $this->log );
    }

    public function testTheItemRecordsThePageAndWhatTheMenuNeeds(): void
    {
        $i = $this->item();
        $this->assertSame( [ 'page_created', '50', null, 'applied' ], [ $i['kind'], $i['ref'], $i['before'], $i['state'] ] );
        $this->assertStringContainsString( 'About us', $i['label'] );
        $this->assertStringContainsString( '#50', $i['label'] );
        $this->assertSame( Canon::hash( [ 'title' => 'About us', 'content' => 'C0', 'slug' => 'about' ] ), $i['after_hash'] );
        $this->assertSame( [ 'available' => true ], $i['undo'] );
        $this->assertSame( [ 'in_menu' => true, 'is_front' => false, 'menu_label' => 'About' ], $i['meta'] );
    }

    public function testCurrentIsTheSnapshotOfADraftAndNullForAGoneOrTrashedPage(): void
    {
        $kind = new PageCreatedKind();
        $item = $this->item();
        $this->assertSame( [ 'title' => 'About us', 'content' => 'C0', 'slug' => 'about' ], $kind->current( $item ) );
        $this->assertSame( $item['after_hash'], Canon::hash( $kind->current( $item ) ) );
        $GLOBALS['__wp_posts'][50]->post_content = 'edited in Divi';
        $this->assertNotSame( $item['after_hash'], Canon::hash( $kind->current( $item ) ), 'an edit changes what the changed-since rule sees' );
        $GLOBALS['__wp_posts'][50]->post_status = 'trash';
        $this->assertNull( $kind->current( $item ) );
        unset( $GLOBALS['__wp_posts'][50] );
        $this->assertNull( $kind->current( $item ) );
    }

    public function testItNeedsTheRightToEditAndToDeleteThePage(): void
    {
        $kind = new PageCreatedKind();
        $this->assertTrue( $kind->allowed( $this->item() ) );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'delete_post' => false ];
        $this->assertFalse( $kind->allowed( $this->item() ) );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => false, 'delete_post' => true ];
        $this->assertFalse( $kind->allowed( $this->item() ) );
    }

    public function testRestoreTrashesThePageAndRecordsTheInverse(): void
    {
        $r = ( new PageCreatedKind() )->restore( $this->item() );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][50]->post_status );
        $this->assertSame( [ 'page_trashed', '50', 'trash' ], [ $r['inverse']['kind'], $r['inverse']['ref'], $r['inverse']['after_hash'] === Canon::hash( 'trash' ) ? 'trash' : 'other' ] );
        $this->assertSame( [ 'available' => true ], $r['inverse']['undo'] );
        $this->assertSame( [ 'in_menu' => true, 'is_front' => false, 'menu_label' => 'About' ], $r['inverse']['meta'], 'what the menu needs travels with the page' );
    }

    public function testRestoreRefusesAPageThatIsNoLongerADraftEvenWhenForced(): void
    {
        $item = $this->item();
        $GLOBALS['__wp_posts'][50]->post_status = 'publish';
        $this->assertSame( [ 'ok' => false, 'reason' => 'not_draft' ], ( new PageCreatedKind() )->restore( $item ) );
        $this->assertSame( 'publish', $GLOBALS['__wp_posts'][50]->post_status );
        $log = $this->log;
        $id  = $log->record( 'build_site', 'Site build', [ $item ] )['id'];
        $u   = $this->undo()->undo( (int) $id, true );
        $this->assertFalse( $u['fully_undone'], 'force overrides the changed-since rule only' );
        $this->assertSame( 'publish', $GLOBALS['__wp_posts'][50]->post_status, 'a published page is never trashed, forced or not' );
    }

    public function testWithTrashSwitchedOffAnEditedPageIsNeverDeletedEvenWhenForced(): void
    {
        PageCreatedKind::$trashEnabled = static fn (): bool => false;
        $item = $this->item();
        $GLOBALS['__wp_posts'][50]->post_content = 'owner edit';
        $this->assertSame( [ 'ok' => false, 'reason' => 'would_delete_permanently' ], ( new PageCreatedKind() )->restore( $item ) );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][50]->post_status, 'nothing was trashed (a trash is a permanent delete here)' );
        $GLOBALS['__wp_posts'][50]->post_content = 'C0';
        $this->assertTrue( ( new PageCreatedKind() )->restore( $item )['ok'], 'an untouched draft is still undone' );
    }

    public function testRestoreOfAMissingPageOrAFailedTrashIsReported(): void
    {
        $GLOBALS['__wp_trash_fail'] = true;
        $this->assertSame( [ 'ok' => false, 'reason' => 'trash_failed' ], ( new PageCreatedKind() )->restore( $this->item() ) );
        $GLOBALS['__wp_trash_fail'] = false;
        $item = $this->item();
        unset( $GLOBALS['__wp_posts'][50] );
        $this->assertSame( [ 'ok' => false, 'reason' => 'missing' ], ( new PageCreatedKind() )->restore( $item ) );
    }

    public function testWithTrashSwitchedOffTheUndoCannotBeUndoneAndSaysWhy(): void
    {
        PageCreatedKind::$trashEnabled = static fn (): bool => false;
        $r = ( new PageCreatedKind() )->restore( $this->item() );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [ 'available' => false, 'reason' => 'trash_disabled' ], $r['inverse']['undo'] );
    }

    public function testTheTrashSettingDefaultsToOnAndFollowsTheConstant(): void
    {
        $this->assertTrue( PageCreatedKind::trashEnabled(), 'EMPTY_TRASH_DAYS is not defined in the tests: core defaults to 30' );
    }

    public function testAnUndoThroughTheEngineTrashesAnUntouchedPageAndAnUndoOfThatBringsItBackAsADraft(): void
    {
        $batch = $this->log->record( 'build_site', 'Site build', [ $this->item() ] );
        $u     = $this->undo()->undo( $batch['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][50]->post_status );
        $this->assertSame( 'about__trashed', $GLOBALS['__wp_posts'][50]->post_name );

        $back = $this->undo()->undo( (int) $u['undo_batch']['id'] );
        $this->assertTrue( $back['fully_undone'] );
        $this->assertSame( [ 'draft', 'about', 'C0' ], [ $GLOBALS['__wp_posts'][50]->post_status, $GLOBALS['__wp_posts'][50]->post_name, $GLOBALS['__wp_posts'][50]->post_content ] );
        $again = $this->repo->rows[ (int) $back['undo_batch']['id'] ]['items'][0];
        $this->assertSame( 'page_created', $again['kind'], 'undoing the undo can be undone again' );
        $this->assertSame( $this->item()['after_hash'], $again['after_hash'] );
    }

    public function testAPageEditedAfterTheBuildIsSkippedNotTrashed(): void
    {
        $batch = $this->log->record( 'build_site', 'Site build', [ $this->item() ] );
        $GLOBALS['__wp_posts'][50]->post_content = 'edited in the Divi builder';
        $u = $this->undo()->undo( $batch['id'] );
        $this->assertFalse( $u['fully_undone'] );
        $this->assertSame( 'changed_since', $u['items'][0]['result'] );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][50]->post_status, 'never trashed behind the owner\'s back' );
    }

    public function testTheTrashedKindNeedsThePageToBeInTheTrashAndReportsFailures(): void
    {
        $kind = new PageTrashedKind();
        $item = Item::make( 'page_trashed', '50', 'Created page "About us" (#50)', null, 'trash' ) + [ 'meta' => [ 'in_menu' => true ] ];
        $this->assertSame( 'draft', $kind->current( $item ), 'a page that is not in the trash reads as its status' );
        $this->assertSame( [ 'ok' => false, 'reason' => 'untrash_failed' ], $kind->restore( $item ), 'a draft cannot be untrashed' );
        wp_trash_post( 50 );
        $this->assertSame( 'trash', $kind->current( $item ) );
        $GLOBALS['__wp_untrash_fail'] = true;
        $this->assertSame( [ 'ok' => false, 'reason' => 'untrash_failed' ], $kind->restore( $item ) );
        $GLOBALS['__wp_untrash_fail'] = false;
        $this->assertTrue( $kind->restore( $item )['ok'] );
        unset( $GLOBALS['__wp_posts'][50] );
        $this->assertNull( $kind->current( $item ) );
        $this->assertSame( [ 'ok' => false, 'reason' => 'missing' ], $kind->restore( $item ) );
    }

    public function testTheTrashedKindNeedsTheRightToEditAndDelete(): void
    {
        $kind = new PageTrashedKind();
        $item = Item::make( 'page_trashed', '50', 'x', null, 'trash' );
        $this->assertTrue( $kind->allowed( $item ) );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true ];
        $this->assertFalse( $kind->allowed( $item ) );
    }

    public function testAPagePublishedOrMadePrivateByHandIsSkippedNotTrashed(): void
    {
        foreach ( [ 'publish', 'private', 'pending', 'future' ] as $status ) {
            $GLOBALS['__wp_posts'][50]->post_status = 'draft';
            $batch = $this->log->record( 'build_site', 'Site build', [ $this->item() ] );
            $GLOBALS['__wp_posts'][50]->post_status = $status;
            $this->assertNull( ( new PageCreatedKind() )->current( $this->item() ), $status );
            $u = $this->undo()->undo( $batch['id'] );
            $this->assertFalse( $u['fully_undone'], $status );
            $this->assertSame( 'changed_since', $u['items'][0]['result'], $status );
            $this->assertSame( $status, $GLOBALS['__wp_posts'][50]->post_status, "a {$status} page is never trashed behind the owner's back" );
        }
    }

    public function testOnlyAPageIsEverTouched(): void
    {
        $GLOBALS['__wp_posts'][51] = (object) [ 'ID' => 51, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'A post', 'post_content' => 'P', 'post_name' => 'a-post' ];
        $created = PageCreatedKind::itemFor( $GLOBALS['__wp_posts'][51], [] );
        $this->assertNull( ( new PageCreatedKind() )->current( $created ), 'a post is not a page' );
        $this->assertSame( [ 'ok' => false, 'reason' => 'missing' ], ( new PageCreatedKind() )->restore( $created ), 'even with force a post is never trashed' );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][51]->post_status );

        $GLOBALS['__wp_posts'][51]->post_status = 'trash';
        $trashed = Item::make( 'page_trashed', '51', 'x', null, 'trash' );
        $this->assertNull( ( new PageTrashedKind() )->current( $trashed ) );
        $this->assertSame( [ 'ok' => false, 'reason' => 'missing' ], ( new PageTrashedKind() )->restore( $trashed ), 'and a post in the trash is never untrashed' );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][51]->post_status );
    }

    public function testAnUntrashedPageThatVanishesAtOnceStillGivesAnInverseThatSaysSo(): void
    {
        $item = Item::make( 'page_trashed', '50', 'Created page "About us" (#50)', null, 'trash' ) + [ 'meta' => [ 'in_menu' => true ] ];
        wp_trash_post( 50 );
        $GLOBALS['__wp_untrash_vanish'] = true;
        $r = ( new PageTrashedKind() )->restore( $item );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [ 'available' => false, 'reason' => 'missing' ], $r['inverse']['undo'] );
    }
}
