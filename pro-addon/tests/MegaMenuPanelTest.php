<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Admin\MegaMenuPanel;
use AiEditorDivi5\Pro\MegaMenu\HeaderEdit;
use AiEditorDivi5\Pro\MegaMenu\Layouts;
use AiEditorDivi5\Pro\MegaMenu\MegaMenuService;
use AiEditorDivi5\Pro\MegaMenu\MegaMenuUndo;
use AiEditorDivi5\Pro\MegaMenu\MenuSource;
use AiEditorDivi5\Pro\MegaMenu\MenuTree;
use AiEditorDivi5\Pro\MegaMenu\PostSource;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutWriter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/ThemeLayoutFixture.php';
require_once __DIR__ . '/MegaMenuFixture.php';

/** A menu source that returns the given tree (or no menu at all). */
final class FakeMegaMenus extends MenuSource
{
    public function __construct( private ?array $tree )
    {
    }

    public function read( ?string $preferredId ): ?array
    {
        return null === $this->tree ? null : [ 'name' => 'Main menu', 'menuId' => 35, 'tree' => $this->tree, 'how' => 'the menu chosen in the header\'s Menu module' ];
    }
}

final class FakeMegaPosts extends PostSource
{
    public function latest( array $item, int $count = 4 ): array
    {
        return MegaMenuFixture::posts();
    }
}

class MegaMenuPanelTest extends TestCase
{
    use ThemeLayoutFixture;

    private bool $unlocked = true;

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        // The live header now holds a real Menu module, so the menu checks pass unless a test changes it.
        $GLOBALS['__wp_posts'][201]->post_content = MegaMenuFixture::header();
        $GLOBALS['__wp_filters']                  = [];
        $GLOBALS['__wp_redirects']                = [];
        $GLOBALS['__wp_transients']               = [];
        $GLOBALS['__wp_can']                      = true;
        $_GET = $_POST = $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->unlocked            = true;
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
        $_GET = $_POST = $_REQUEST = [];
        unset( $_SERVER['REQUEST_METHOD'] );
    }

    private static function item( int $id, int $parent, string $title ): array
    {
        return [ 'id' => $id, 'parent' => $parent, 'order' => $id, 'title' => $title, 'url' => 'https://example.test/' . $id . '/', 'newTab' => false, 'description' => '', 'type' => 'custom', 'object' => '', 'objectId' => 0 ];
    }

    private function service( ?array $tree = null, bool $withHeader = true ): MegaMenuService
    {
        $tree ??= MegaMenuFixture::menus()['grouped'];

        return new MegaMenuService(
            new LayoutWriter( $this->log ),
            new FakeMegaMenus( $tree ),
            new FakeMegaPosts(),
            fn (): ?array => $withHeader ? [ 'layout_id' => 201, 'title' => 'Main header' ] : null,
            static fn (): string => 'https://example.test/wp-content/plugins/free/assets/images/'
        );
    }

    private function panel( ?MegaMenuService $service = null ): MegaMenuPanel
    {
        return new MegaMenuPanel( fn (): bool => $this->unlocked, $service ?? $this->service(), static function (): void {}, new MegaMenuUndo( $this->log, $this->undoEngine() ) );
    }

    private function render( ?MegaMenuService $service = null, string $tab = MegaMenuPanel::TAB ): string
    {
        ob_start();
        $this->panel( $service )->render( $tab );

        return (string) ob_get_clean();
    }

    private function postAs( string $action, array $fields ): void
    {
        $_POST    = $fields;
        $_REQUEST = $_POST + [ '_wpnonce' => 'nonce-for-' . $action ];
    }

    private function redirectNotice(): string
    {
        $url = (string) end( $GLOBALS['__wp_redirects'] );
        parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );

        return (string) ( $q['aied_pro_notice'] ?? '' );
    }

    private function headerContent(): string
    {
        return $GLOBALS['__wp_posts'][201]->post_content;
    }

    // ---- the tab -----------------------------------------------------------------------------------------------------

    public function testRegisterAddsTheTabOnlyWhileUnlocked(): void
    {
        $this->panel()->register();
        $this->assertArrayHasKey( 'jhmg_aied_admin_tabs', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'jhmg_aied_render_admin_tab', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'admin_post_' . MegaMenuPanel::ACTION_APPLY, $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'admin_post_' . MegaMenuPanel::ACTION_REMOVE, $GLOBALS['__wp_filters'] );

        $tabs = apply_filters( 'jhmg_aied_admin_tabs', [ 'dashboard' => 'Dashboard' ] );
        $this->assertSame( 'Mega menu', $tabs['megamenu'] );
        $this->assertSame( 'Dashboard', $tabs['dashboard'] );

        $this->unlocked = false;
        $this->assertSame( [ 'dashboard' => 'Dashboard' ], apply_filters( 'jhmg_aied_admin_tabs', [ 'dashboard' => 'Dashboard' ] ) );
        $this->assertSame( 'not a list', apply_filters( 'jhmg_aied_admin_tabs', 'not a list' ) );
    }

    public function testItRendersOnlyForTheTabWhileUnlockedForAnEditorOfTheTheme(): void
    {
        $this->assertSame( '', $this->render( null, 'pro' ) );
        $this->assertNotSame( '', $this->render() );

        $this->unlocked = false;
        $this->assertSame( '', $this->render() );
        $this->unlocked = true;

        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->assertSame( '', $this->render(), 'a user who cannot edit the theme sees nothing' );
    }

    public function testWithAUsableHeaderEveryLayoutHasAnEnabledUseButton(): void
    {
        $html = $this->render();
        $this->assertSame( 13, substr_count( $html, 'aied-mm-card"' ) );
        $this->assertSame( 13, substr_count( $html, '>Use this layout</button>' ) );
        $this->assertStringNotContainsString( ' disabled>', $html );
        $this->assertStringContainsString( 'Link columns', $html );
        $this->assertStringContainsString( 'Latest posts', $html );
        $this->assertStringContainsString( 'value="columns-footer"', $html );
        $this->assertStringContainsString( 'value="' . MegaMenuPanel::ACTION_APPLY . '"', $html );
        $this->assertStringContainsString( 'nonce-for-' . MegaMenuPanel::ACTION_APPLY, $html );
        $this->assertStringContainsString( 'Tablet and phone keep the normal menu', $html );
    }

    public function testWithoutAGlobalHeaderTheNoticeShowsAndEveryUseButtonIsDisabled(): void
    {
        $html = $this->render( $this->service( null, false ) );
        $this->assertStringContainsString( 'needs a global header', $html );
        $this->assertStringContainsString( 'page=et_theme_builder', $html );
        $this->assertSame( 13, substr_count( $html, ' disabled>' ) );
        $this->assertStringNotContainsString( 'Remove mega menu', $html );
    }

    public function testAHeaderWithoutAMenuModuleDisablesTheButtons(): void
    {
        $GLOBALS['__wp_posts'][201]->post_content = self::header();
        $html                                      = $this->render();
        $this->assertStringContainsString( 'no Menu module', $html );
        $this->assertSame( 13, substr_count( $html, ' disabled>' ) );
    }

    public function testAMenuWithoutSubItemsDisablesTheButtons(): void
    {
        $html = $this->render( $this->service( [ MenuTree::build( [ self::item( 1, 0, 'Home' ) ] )[0] ] ) );
        $this->assertStringContainsString( 'None of the menu', $html );
        $this->assertSame( 13, substr_count( $html, ' disabled>' ) );
    }

    public function testTheMenuNamesAndCountsAreShownEscaped(): void
    {
        $html = $this->render();
        $this->assertStringContainsString( 'Menu &quot;Main menu&quot;: 3 top-level items, 1 with sub-items', $html );
        $this->assertStringContainsString( 'Main header', $html );
        $this->assertStringNotContainsString( '<script', $html );
    }

    // ---- apply -------------------------------------------------------------------------------------------------------

    public function testApplyWithoutAValidNonceIsRefusedAndNothingChanges(): void
    {
        $before = $this->headerContent();
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'columns-footer' ] );
        $_REQUEST['_wpnonce'] = 'wrong';
        try {
            $this->panel()->handleApply();
            $this->fail( 'a bad nonce must stop the handler' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'check_admin_referer', $e->getMessage() );
        }
        $this->assertSame( $before, $this->headerContent() );
    }

    public function testApplyWithoutThePermissionIsRefused(): void
    {
        $before               = $this->headerContent();
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'columns-footer' ] );
        try {
            $this->panel()->handleApply();
            $this->fail( 'the capability check must stop the handler' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'Unauthorized', $e->getMessage() );
        }
        $this->assertSame( $before, $this->headerContent() );
    }

    public function testApplyOnlyAcceptsAPostRequest(): void
    {
        $before = $this->headerContent();
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'columns-footer' ] );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->expectException( \RuntimeException::class );
        try {
            $this->panel()->handleApply();
        } finally {
            $this->assertSame( $before, $this->headerContent() );
        }
    }

    public function testANonStringOrUnknownLayoutIsRefusedWithANotice(): void
    {
        $before = $this->headerContent();
        foreach ( [ [ 'columns-footer' ], 12, 'nope', '', '../../etc' ] as $bad ) {
            $GLOBALS['__wp_redirects'] = [];
            $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => $bad ] );
            $this->panel()->handleApply();
            $this->assertSame( 'megamenu_bad_layout', $this->redirectNotice(), var_export( $bad, true ) );
        }
        $this->assertSame( $before, $this->headerContent() );
    }

    public function testASuccessfulApplyChangesTheHeaderAndRedirectsWithTheName(): void
    {
        $before = $this->headerContent();
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'columns-footer' ] );
        $this->panel()->handleApply();

        $this->assertNotSame( $before, $this->headerContent() );
        $this->assertTrue( HeaderEdit::inspect( $this->headerContent() )['hasMega'] );
        $this->assertSame( 'megamenu_applied', $this->redirectNotice() );
        $this->assertStringContainsString( 'tab=' . MegaMenuPanel::TAB, end( $GLOBALS['__wp_redirects'] ) );

        $_GET['aied_pro_notice'] = 'megamenu_applied';
        $html                    = $this->render();
        $this->assertStringContainsString( 'The mega menu &quot;Columns with a footer link&quot; is on your header now', $html );
        $this->assertStringContainsString( 'Remove mega menu', $html );
    }

    public function testAChangedNothingApplyRedirectsAsUnchanged(): void
    {
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'columns-footer' ] );
        $this->panel()->handleApply();
        $GLOBALS['__wp_redirects'] = [];
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'columns-footer' ] );
        $this->panel()->handleApply();
        $this->assertSame( 'megamenu_unchanged', $this->redirectNotice() );
    }

    public function testAServiceExceptionShowsItsMessageEscaped(): void
    {
        $writer  = new LayoutWriter( $this->log, static fn (): array => [ 'ok' => false, 'message' => '<b>bold</b> & "quoted"' ] );
        $service = new MegaMenuService(
            $writer,
            new FakeMegaMenus( MegaMenuFixture::menus()['grouped'] ),
            new FakeMegaPosts(),
            fn (): ?array => [ 'layout_id' => 201, 'title' => 'Main header' ]
        );
        $before = $this->headerContent();
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'columns-footer' ] );
        $this->panel( $service )->handleApply();

        $this->assertSame( 'megamenu_error', $this->redirectNotice() );
        $this->assertSame( $before, $this->headerContent() );

        $_GET['aied_pro_notice'] = 'megamenu_error';
        $html                    = $this->render( $service );
        $this->assertStringContainsString( '&lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot;', $html );
        $this->assertStringNotContainsString( '<b>bold</b>', $html );
    }

    // ---- remove ------------------------------------------------------------------------------------------------------

    public function testRemoveTakesTheMegaMenuAwayAgain(): void
    {
        $original = $this->headerContent();
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'columns-footer' ] );
        $this->panel()->handleApply();
        $this->assertTrue( HeaderEdit::inspect( $this->headerContent() )['hasMega'] );

        $this->postAs( MegaMenuPanel::ACTION_REMOVE, [] );
        $this->panel()->handleRemove();
        $this->assertSame( 'megamenu_removed', $this->redirectNotice() );
        $this->assertSame( $original, $this->headerContent(), 'the header is back to what it was' );
    }

    public function testRemoveWithNothingAppliedSaysSo(): void
    {
        $this->postAs( MegaMenuPanel::ACTION_REMOVE, [] );
        $this->panel()->handleRemove();
        $this->assertSame( 'megamenu_unchanged', $this->redirectNotice() );
    }

    // ---- one item only -----------------------------------------------------------------------------------------------

    /** A menu with two top-level items that have a panel: Services (2) and Blog (20). */
    private function twoPanelMenu(): array
    {
        return MenuTree::build( [
            self::item( 1, 0, 'Home' ),
            self::item( 2, 0, 'Services' ), self::item( 3, 2, 'Design' ), self::item( 4, 2, 'Build' ),
            self::item( 20, 0, 'Blog' ), self::item( 21, 20, 'News' ), self::item( 22, 20, 'Guides' ),
        ] );
    }

    public function testALayoutCanBePutOnOneItemWhileTheOtherKeepsItsOwn(): void
    {
        $service = $this->service( $this->twoPanelMenu() );
        $service->apply( 'columns-footer' );
        $this->assertSame( [ 2 => 'columns-footer', 20 => 'columns-footer' ], HeaderEdit::panels( $this->headerContent() ) );

        $service->apply( 'icons-cards', false, 20 );
        $this->assertSame( [ 2 => 'columns-footer', 20 => 'icons-cards' ], HeaderEdit::panels( $this->headerContent() ), 'Services kept its layout' );
        $this->assertSame( 1, substr_count( $this->headerContent(), 'JHMG Mega Menu"' ), 'still one wrapper' );

        // The same layout again changes nothing; another item can be changed later without touching Blog.
        $this->assertFalse( $service->apply( 'icons-cards', false, 20 )['changed'] );
        $service->apply( 'feature-left', false, 2 );
        $this->assertSame( [ 2 => 'feature-left', 20 => 'icons-cards' ], HeaderEdit::panels( $this->headerContent() ) );

        // Applying to all items replaces everything again.
        $service->apply( 'columns-dark' );
        $this->assertSame( [ 2 => 'columns-dark', 20 => 'columns-dark' ], HeaderEdit::panels( $this->headerContent() ) );
    }

    public function testOneItemOnlyNeedsAMegaMenuAndAnItemWithAPanel(): void
    {
        $service = $this->service( $this->twoPanelMenu() );
        $before  = $this->headerContent();
        try {
            $service->apply( 'icons-cards', false, 20 );
            $this->fail( 'one item needs a mega menu first' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( MegaMenuService::NO_MEGA_YET, $e->getMessage() );
        }
        $this->assertSame( $before, $this->headerContent() );

        $service->apply( 'columns-footer' );
        $applied = $this->headerContent();
        foreach ( [ 1, 999, 3 ] as $bad ) { // Home has no sub-items, 999 is not in the menu, 3 is a sub-item
            try {
                $service->apply( 'icons-cards', false, $bad );
                $this->fail( 'item ' . $bad . ' has no panel' );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertSame( MegaMenuService::NO_SUCH_ITEM, $e->getMessage() );
            }
        }
        $this->assertSame( $applied, $this->headerContent() );
    }

    public function testAnUnknownEarlierLayoutOnAnotherItemFallsBackToTheChosenOne(): void
    {
        $service = $this->service( $this->twoPanelMenu() );
        $service->apply( 'columns-footer' );
        // A header made by an older version: Blog's panel was built from a layout that no longer exists.
        $GLOBALS['__wp_posts'][201]->post_content = str_replace( 'JHMG panel 20 columns-footer', 'JHMG panel 20 list-plain', $this->headerContent() );
        $service->apply( 'icons-cards', false, 2 );
        $this->assertSame( [ 2 => 'icons-cards', 20 => 'icons-cards' ], HeaderEdit::panels( $this->headerContent() ) );
    }

    public function testTheTabListsTheItemsAndOffersOnlyThisItemOnceAMegaMenuIsOn(): void
    {
        $service = $this->service( $this->twoPanelMenu() );
        $html    = $this->render( $service );
        $this->assertStringNotContainsString( 'name="item"', $html, 'nothing to limit before a mega menu exists' );

        $service->apply( 'columns-footer' );
        $service->apply( 'icons-cards', false, 20 );
        $html = $this->render( $service );
        $this->assertStringContainsString( '<strong>Services</strong>: Columns with a footer link', $html );
        $this->assertStringContainsString( '<strong>Blog</strong>: Icon cards', $html );
        $this->assertSame( 13, substr_count( $html, 'name="item"' ) );
        $this->assertStringContainsString( '<option value="20">Only &quot;Blog&quot;</option>', $html );
        $this->assertStringContainsString( 'All menu items', $html );
    }

    public function testThePostedItemPutsTheLayoutOnThatItemOnly(): void
    {
        $service = $this->service( $this->twoPanelMenu() );
        $service->apply( 'columns-footer' );
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'icons-cards', 'item' => '20' ] );
        $this->panel( $service )->handleApply();
        $this->assertSame( 'megamenu_applied_item', $this->redirectNotice() );
        $this->assertSame( [ 2 => 'columns-footer', 20 => 'icons-cards' ], HeaderEdit::panels( $this->headerContent() ) );

        $_GET['aied_pro_notice'] = 'megamenu_applied_item';
        $this->assertStringContainsString( 'The layout &quot;Icon cards&quot; is on &quot;Blog&quot; now. The other items kept theirs.', $this->render( $service ) );

        // "0", a non-string or junk means all items.
        foreach ( [ '0', 'x', [ '20' ], '-3' ] as $all ) {
            $GLOBALS['__wp_redirects'] = [];
            $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'columns-dark', 'item' => $all ] );
            $this->panel( $service )->handleApply();
            $this->assertSame( [ 2 => 'columns-dark', 20 => 'columns-dark' ], HeaderEdit::panels( $this->headerContent() ), var_export( $all, true ) );
            $service->apply( 'icons-cards', false, 20 );
        }
    }

    // ---- undo last change --------------------------------------------------------------------------------------------

    public function testUndoLastChangeStepsBackOneChangeAtATime(): void
    {
        $service  = $this->service( $this->twoPanelMenu() );
        $original = $this->headerContent();
        $service->apply( 'columns-footer' );
        $first = $this->headerContent();
        $service->apply( 'icons-cards', false, 20 );
        $this->assertNotSame( $first, $this->headerContent() );

        $this->postAs( MegaMenuPanel::ACTION_UNDO, [] );
        $this->panel( $service )->handleUndo();
        $this->assertSame( 'megamenu_undone', $this->redirectNotice() );
        $this->assertSame( $first, $this->headerContent(), 'back to the whole-menu layout, byte for byte' );

        $this->postAs( MegaMenuPanel::ACTION_UNDO, [] );
        $this->panel( $service )->handleUndo();
        $this->assertSame( $original, $this->headerContent(), 'and back to the header without a mega menu' );

        $GLOBALS['__wp_redirects'] = [];
        $this->postAs( MegaMenuPanel::ACTION_UNDO, [] );
        $this->panel( $service )->handleUndo();
        $this->assertSame( 'megamenu_undo_none', $this->redirectNotice() );
        $this->assertSame( $original, $this->headerContent() );
    }

    public function testUndoRefusesWhenTheHeaderWasChangedSinceAndTouchesNothing(): void
    {
        $service = $this->service( $this->twoPanelMenu() );
        $service->apply( 'columns-footer' );
        $edited = $this->headerContent() . '<!-- edited in the builder -->';
        $GLOBALS['__wp_posts'][201]->post_content = $edited;

        $this->postAs( MegaMenuPanel::ACTION_UNDO, [] );
        $this->panel( $service )->handleUndo();
        $this->assertContains( $this->redirectNotice(), [ 'megamenu_undo_partly' ] );
        $this->assertSame( $edited, $this->headerContent(), 'what changed afterwards is never overwritten' );
    }

    public function testTheUndoButtonShowsOnlyWhileThereIsAChangeToUndo(): void
    {
        $service = $this->service( $this->twoPanelMenu() );
        $this->assertStringNotContainsString( 'value="' . MegaMenuPanel::ACTION_UNDO . '"', $this->render( $service ) );
        $service->apply( 'columns-footer' );
        $html = $this->render( $service );
        $this->assertStringContainsString( '>Undo last change</button>', $html );
        $this->assertStringContainsString( 'value="' . MegaMenuPanel::ACTION_UNDO . '"', $html );
        $this->assertStringContainsString( 'Last change:', $html );
    }

    public function testUndoNeedsTheLicenceAndThePermission(): void
    {
        $service = $this->service( $this->twoPanelMenu() );
        $service->apply( 'columns-footer' );
        $applied = $this->headerContent();

        $this->unlocked = false;
        $this->postAs( MegaMenuPanel::ACTION_UNDO, [] );
        $this->panel( $service )->handleUndo();
        $this->assertSame( 'megamenu_error', $this->redirectNotice() );
        $this->assertSame( $applied, $this->headerContent() );
        $this->unlocked = true;

        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->postAs( MegaMenuPanel::ACTION_UNDO, [] );
        try {
            $this->panel( $service )->handleUndo();
            $this->fail( 'the capability check must stop the handler' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'Unauthorized', $e->getMessage() );
        }
        $this->assertSame( $applied, $this->headerContent() );
    }

    // ---- the menu changed after the mega menu was applied -------------------------------------------------------------

    /** The two-panel menu after the owner edited it: Services gained a link, Blog lost one, About is new, Home moved to the end. */
    private function editedMenu(): array
    {
        return MenuTree::build( [
            self::item( 2, 0, 'Services' ), self::item( 3, 2, 'Design' ), self::item( 4, 2, 'Build' ), self::item( 5, 2, 'Extra' ),
            self::item( 20, 0, 'Blog' ), self::item( 21, 20, 'News' ),
            self::item( 30, 0, 'About' ),
            self::item( 1, 0, 'Home' ),
        ] );
    }

    public function testRightAfterApplyingAnyLayoutThereIsNoDrift(): void
    {
        foreach ( Layouts::ids() as $id ) {
            foreach ( [ $this->twoPanelMenu(), MegaMenuFixture::menus()['deep'], MegaMenuFixture::menus()['grouped'] ] as $tree ) {
                $service = $this->service( $tree );
                $service->apply( $id );
                $this->assertNull( $service->status()['drift'], 'no drift straight after applying ' . $id );
            }
        }
    }

    public function testThePostsOfALatestLayoutAreASnapshotNotDrift(): void
    {
        $this->service( $this->twoPanelMenu() )->apply( 'latest-cards' );
        $newer = new class () extends PostSource {
            public function latest( array $item, int $count = 4 ): array
            {
                return [ [ 'title' => 'A post from tomorrow', 'url' => 'https://example.test/tomorrow/', 'image' => '', 'date' => '5 November 2026' ] ];
            }
        };
        $service = new MegaMenuService( new LayoutWriter( $this->log ), new FakeMegaMenus( $this->twoPanelMenu() ), $newer, fn (): array => [ 'layout_id' => 201, 'title' => 'Main header' ], static fn (): string => 'https://example.test/images/' );
        $this->assertNull( $service->status()['drift'] );
    }

    public function testAMenuEditedAfterTheApplyIsReportedPerItem(): void
    {
        $this->service( $this->twoPanelMenu() )->apply( 'columns-footer' );
        $drift = $this->service( $this->editedMenu() )->status()['drift'];
        $this->assertNotNull( $drift );

        $byTitle = [];
        foreach ( $drift['items'] as $it ) {
            $byTitle[ $it['title'] ] = $it;
        }
        $this->assertSame( [ 'Extra' ], $byTitle['Services']['missing'] );
        $this->assertSame( [], $byTitle['Services']['extra'] );
        $this->assertSame( [ 'Guides' ], $byTitle['Blog']['extra'] );
        $this->assertContains( 'About', $drift['bar']['missing'], 'a new top-level item is missing from the bar' );
        $this->assertSame( [], $drift['gone'] );
    }

    public function testAnItemThatGainsSubItemsNeedsAPanel(): void
    {
        $this->service( $this->twoPanelMenu() )->apply( 'columns-footer' );
        $tree = MenuTree::build( [ self::item( 2, 0, 'Services' ), self::item( 3, 2, 'Design' ), self::item( 4, 2, 'Build' ), self::item( 20, 0, 'Blog' ), self::item( 21, 20, 'News' ), self::item( 22, 20, 'Guides' ), self::item( 1, 0, 'Home' ), self::item( 40, 1, 'Welcome' ) ] );
        $drift = $this->service( $tree )->status()['drift'];
        $this->assertNotNull( $drift );
        $home = array_values( array_filter( $drift['items'], static fn ( array $i ): bool => 'Home' === $i['title'] ) );
        $this->assertNotSame( [], $home, 'Home now has sub-items and no panel' );
        $this->assertContains( 'Welcome', $home[0]['missing'] );
    }

    public function testAnOrderChangeAloneIsReported(): void
    {
        $this->service( $this->twoPanelMenu() )->apply( 'columns-footer' );
        // "Build" now comes before "Design" under Services (an explicit menu order; the fixture otherwise orders by id).
        $flat = [ self::item( 1, 0, 'Home' ), self::item( 2, 0, 'Services' ), self::item( 4, 2, 'Build' ), self::item( 3, 2, 'Design' ), self::item( 20, 0, 'Blog' ), self::item( 21, 20, 'News' ), self::item( 22, 20, 'Guides' ) ];
        foreach ( $flat as &$f ) {
            $f['order'] = 4 === $f['id'] ? 1 : ( 3 === $f['id'] ? 2 : $f['id'] + 10 );
        }
        unset( $f );
        $drift = $this->service( MenuTree::build( $flat ) )->status()['drift'];
        $this->assertNotNull( $drift );
        $services = array_values( array_filter( $drift['items'], static fn ( array $i ): bool => 'Services' === $i['title'] ) );
        $this->assertTrue( $services[0]['reordered'] );
        $this->assertSame( [], $services[0]['missing'] );
        $this->assertSame( [], $services[0]['extra'] );
    }

    public function testRefreshBuildsTheCurrentMenuAndKeepsEachItemsLayout(): void
    {
        $this->service( $this->twoPanelMenu() )->apply( 'columns-footer' );
        $this->service( $this->twoPanelMenu() )->apply( 'icons-cards', false, 20 );
        $before = $this->headerContent();

        $edited = $this->service( $this->editedMenu() );
        $result = $edited->refresh();
        $this->assertTrue( $result['changed'] );
        $this->assertSame( [ 2 => 'columns-footer', 20 => 'icons-cards' ], HeaderEdit::panels( $this->headerContent() ), 'every item kept its layout' );
        $this->assertStringContainsString( 'Extra', $this->headerContent(), 'the new link is in the header' );
        $this->assertNull( $edited->status()['drift'], 'nothing left to update' );
        $this->assertFalse( $edited->refresh()['changed'], 'a second update changes nothing' );

        // One step back puts the header exactly as it was.
        $undo = new MegaMenuUndo( $this->log, $this->undoEngine() );
        $this->assertSame( 'undone', $undo->undoLast( 1, true )['result'] );
        $this->assertSame( $before, $this->headerContent() );
    }

    public function testAnItemThatGainsAPanelTakesTheLayoutMostPanelsHave(): void
    {
        $this->service( $this->twoPanelMenu() )->apply( 'feature-left' );
        $tree = MenuTree::build( [ self::item( 2, 0, 'Services' ), self::item( 3, 2, 'Design' ), self::item( 20, 0, 'Blog' ), self::item( 21, 20, 'News' ), self::item( 1, 0, 'Home' ), self::item( 40, 1, 'Welcome' ) ] );
        $this->service( $tree )->refresh();
        $this->assertSame( [ 1 => 'feature-left', 2 => 'feature-left', 20 => 'feature-left' ], HeaderEdit::panels( $this->headerContent() ) );
    }

    public function testRefreshWithoutAMegaMenuIsRefused(): void
    {
        $before = $this->headerContent();
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( MegaMenuService::NOTHING_TO_UPDATE );
        try {
            $this->service( $this->twoPanelMenu() )->refresh();
        } finally {
            $this->assertSame( $before, $this->headerContent() );
        }
    }

    public function testTheTabShowsTheNoticeAndTheUpdateButtonOnlyWhenTheMenuChanged(): void
    {
        $this->service( $this->twoPanelMenu() )->apply( 'columns-footer' );
        $this->assertStringNotContainsString( 'no longer matches your menu', $this->render( $this->service( $this->twoPanelMenu() ) ) );
        $this->assertStringNotContainsString( 'value="' . MegaMenuPanel::ACTION_UPDATE . '"', $this->render( $this->service( $this->twoPanelMenu() ) ) );

        $html = $this->render( $this->service( $this->editedMenu() ) );
        $this->assertStringContainsString( 'no longer matches your menu', $html );
        $this->assertStringContainsString( 'value="' . MegaMenuPanel::ACTION_UPDATE . '"', $html );
        $this->assertStringContainsString( 'Update the mega menu', $html );
        $this->assertStringContainsString( 'Services: in your menu but not in the header yet: &quot;Extra&quot;.', $html );
        $this->assertStringContainsString( 'Blog: still in the header but no longer in your menu: &quot;Guides&quot;.', $html );
        $this->assertStringContainsString( 'Top-level items: in your menu but not in the header yet', $html );

        // Nothing applied: nothing to be out of date.
        $GLOBALS['__wp_posts'][201]->post_content = MegaMenuFixture::header();
        $this->assertStringNotContainsString( 'no longer matches your menu', $this->render( $this->service( $this->editedMenu() ) ) );
    }

    public function testTheUpdateHandlerBringsTheHeaderUpToDateOnce(): void
    {
        $this->service( $this->twoPanelMenu() )->apply( 'columns-footer' );
        $service = $this->service( $this->editedMenu() );
        $this->postAs( MegaMenuPanel::ACTION_UPDATE, [] );
        $this->panel( $service )->handleUpdate();
        $this->assertSame( 'megamenu_updated', $this->redirectNotice() );
        $this->assertNull( $service->status()['drift'] );

        $this->panel( $service )->handleUpdate();
        $this->assertSame( 'megamenu_unchanged', $this->redirectNotice() );

        $_GET['aied_pro_notice'] = 'megamenu_updated';
        $this->assertStringContainsString( 'The mega menu now matches your menu.', $this->render( $service ) );
    }

    public function testTheUpdateHandlerNeedsTheLicenceAndThePermission(): void
    {
        $this->service( $this->twoPanelMenu() )->apply( 'columns-footer' );
        $applied = $this->headerContent();
        $service = $this->service( $this->editedMenu() );

        $this->unlocked = false;
        $this->postAs( MegaMenuPanel::ACTION_UPDATE, [] );
        $this->panel( $service )->handleUpdate();
        $this->assertSame( 'megamenu_error', $this->redirectNotice() );
        $this->assertSame( $applied, $this->headerContent() );
        $this->unlocked = true;

        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->postAs( MegaMenuPanel::ACTION_UPDATE, [] );
        try {
            $this->panel( $service )->handleUpdate();
            $this->fail( 'the capability check must stop the handler' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'Unauthorized', $e->getMessage() );
        }
        $this->assertSame( $applied, $this->headerContent() );
    }

    public function testRegisterAlsoHooksTheUpdateAction(): void
    {
        $this->panel()->register();
        $this->assertArrayHasKey( 'admin_post_' . MegaMenuPanel::ACTION_UPDATE, $GLOBALS['__wp_filters'] );
    }
}
