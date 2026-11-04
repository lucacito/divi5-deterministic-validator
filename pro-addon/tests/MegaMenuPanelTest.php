<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Admin\MegaMenuPanel;
use AiEditorDivi5\Pro\MegaMenu\EditedPanelsException;
use AiEditorDivi5\Pro\MegaMenu\HeaderEdit;
use AiEditorDivi5\Pro\MegaMenu\Layouts;
use AiEditorDivi5\Pro\MegaMenu\MegaMenuService;
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
    /** @param array<int,array{name:string,tree:array}> $others more menus of the site, by menu id (the main one is 35) */
    public function __construct( private ?array $tree, private array $others = [] )
    {
    }

    public function read( ?string $preferredId ): ?array
    {
        if ( null !== $preferredId && isset( $this->others[ (int) $preferredId ] ) ) {
            $m = $this->others[ (int) $preferredId ];

            return [ 'name' => $m['name'], 'menuId' => (int) $preferredId, 'tree' => $m['tree'], 'how' => 'the menu chosen in the header\'s Menu module' ];
        }

        return null === $this->tree ? null : [ 'name' => 'Main menu', 'menuId' => 35, 'tree' => $this->tree, 'how' => 'the menu chosen in the header\'s Menu module' ];
    }

    public function all(): array
    {
        $out = null === $this->tree ? [] : [ [ 'id' => 35, 'name' => 'Main menu', 'items' => count( $this->tree ) ] ];
        foreach ( $this->others as $id => $m ) {
            $out[] = [ 'id' => (int) $id, 'name' => $m['name'], 'items' => count( $m['tree'] ) ];
        }

        return $out;
    }

    public function exists( int $menuId ): bool
    {
        return ( 35 === $menuId && null !== $this->tree ) || isset( $this->others[ $menuId ] );
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
        $GLOBALS['__wp_options'] = []; // the fingerprints and the owner's content live in options
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

    private function service( ?array $tree = null, bool $withHeader = true, array $others = [] ): MegaMenuService
    {
        $tree ??= MegaMenuFixture::menus()['grouped'];

        return new MegaMenuService(
            new LayoutWriter( $this->log ),
            new FakeMegaMenus( $tree, $others ),
            new FakeMegaPosts(),
            fn (): ?array => $withHeader ? [ 'layout_id' => 201, 'title' => 'Main header' ] : null,
            static fn (): string => 'https://example.test/wp-content/plugins/free/assets/images/'
        );
    }

    private function panel( ?MegaMenuService $service = null ): MegaMenuPanel
    {
        return new MegaMenuPanel( fn (): bool => $this->unlocked, $service ?? $this->service(), static function (): void {} );
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
        $this->assertSame( 13, substr_count( $html, '>Use for the whole menu</button>' ) );
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
        $this->assertStringContainsString( 'Main menu (3 items, 1 with sub-items)', $html );
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
        // an older version wrote no fingerprint, so nothing counts as edited by hand
        $GLOBALS['__wp_posts'][201]->post_content = (string) preg_replace( '/(JHMG panel \d+ [a-z0-9-]+) [0-9a-f]{10}/', '$1', $this->headerContent() );
        $service->apply( 'icons-cards', false, 2 );
        $this->assertSame( [ 2 => 'icons-cards', 20 => 'icons-cards' ], HeaderEdit::panels( $this->headerContent() ) );
    }

    private function withoutStyle( string $html ): string
    {
        return (string) preg_replace( '#<style>.*?</style>#s', '', $html );
    }

    // ---- the workspace: items on the left, one editor on the right ----------------------------------------------------

    private function renderAt( MegaMenuService $service, array $get = [] ): string
    {
        $_GET = $get;

        return $this->withoutStyle( $this->render( $service ) );
    }

    /** Applied to the two-item menu with a layout that has a content slot, so every tab of the editor has something to show. */
    private function appliedWithASlot(): MegaMenuService
    {
        $service = $this->service( $this->twoPanelMenu() );
        $service->apply( 'feature-right' );
        $service->apply( 'columns-footer', false, 20 );

        return $service;
    }

    private function redirectArgs(): array
    {
        parse_str( (string) parse_url( (string) end( $GLOBALS['__wp_redirects'] ), PHP_URL_QUERY ), $q );

        return $q;
    }

    public function testBeforeAMegaMenuTheTabIsAGalleryWithAFilterAndNoItemList(): void
    {
        $html = $this->renderAt( $this->service( $this->twoPanelMenu() ) );
        $this->assertStringContainsString( 'Pick a starting look for your menu', $html );
        $this->assertSame( 13, substr_count( $html, 'Use for the whole menu</button>' ) );
        $this->assertStringNotContainsString( 'aied-mm-cols', $html );
        $this->assertStringNotContainsString( 'is-current', $html );
        $this->assertSame( 5, substr_count( $html, 'class="aied-mm-pic"' ), 'the five layouts with a content slot say Picture' );
        $this->assertStringNotContainsString( 'Remove mega menu', $html );

        $filtered = $this->renderAt( $this->service( $this->twoPanelMenu() ), [ 'fam' => 'showcase' ] );
        $this->assertSame( 4, substr_count( $filtered, 'Use for the whole menu</button>' ) );
        $this->assertStringContainsString( 'aied-mm-chip is-on', $filtered );
    }

    public function testOnceItIsOnTheTabListsTheItemsAndEditsTheFirstOne(): void
    {
        $service = $this->appliedWithASlot();
        $html    = $this->renderAt( $service );
        $this->assertStringContainsString( 'aied-mm-cols', $html );
        $this->assertSame( 2, preg_match_all( '/class="aied-mm-item( is-selected)?"/', $html ), 'one entry per item that has a panel' );
        $this->assertMatchesRegularExpression( '#aied-mm-iname">Services</span>\s*<span class="aied-mm-isub">\s*Columns and a card on the right#', $html );
        $this->assertMatchesRegularExpression( '#aied-mm-iname">Blog</span>\s*<span class="aied-mm-isub">\s*Columns with a footer link#', $html );
        $this->assertStringContainsString( 'Services panel', $html, 'the first item is open' );
        $this->assertStringContainsString( 'Remove mega menu', $html );
        $this->assertStringNotContainsString( 'Undo last change', $html );
        $this->assertStringContainsString( 'Content</a>', $html );
        $this->assertStringContainsString( 'Layout</a>', $html );
        $this->assertStringContainsString( 'Builder</a>', $html );

        $blog = $this->renderAt( $service, [ 'item' => '20', 'etab' => 'content' ] );
        $this->assertStringContainsString( 'Blog panel', $blog );
        $this->assertStringContainsString( 'This layout shows your links only.', $blog, 'a layout without a slot says so and offers one with a picture' );
        $this->assertStringContainsString( 'Choose a layout with a picture', $blog );
        $this->assertStringContainsString( 'Services panel', $this->renderAt( $service, [ 'item' => 'abc' ] ), 'junk in the address falls back to the first item' );
    }

    public function testTheItemListShowsCustomContentEditedAndOutOfDate(): void
    {
        $service = $this->appliedWithASlot();
        $service->content()->save( 2, [ 'title' => 'Our services' ] );
        $this->assertStringContainsString( '>Out of date</span>', $this->renderAt( $service ), 'saved but not yet in the header' );
        $service->refresh();
        $html = $this->renderAt( $service );
        $this->assertStringContainsString( '>Custom content</span>', $html );
        $this->assertStringNotContainsString( '>Edited in Divi</span>', $html );
        $this->assertStringNotContainsString( '>Out of date</span>', $html );

        $this->editPanelByHand( 20 );
        $this->assertStringContainsString( '>Edited in Divi</span>', $this->renderAt( $service ) );

        // A menu change inside the edited item is not "out of date" (it is theirs now); one inside the other item is.
        $edited = $this->service( $this->editedMenu() );
        $this->assertStringContainsString( '>Out of date</span>', $this->renderAt( $edited ), 'Services gained a link' );
    }

    public function testTheContentTabHasTheFieldsPrefilledAndTheMediaButton(): void
    {
        $GLOBALS['__wp_attachments'] = [ 7 => [ 'url' => 'https://example.test/uploads/team.jpg' ] ];
        $service = $this->appliedWithASlot();
        $service->content()->save( 2, [ 'title' => 'Our "services"', 'text' => 'We do things.', 'button' => 'See', 'url' => 'https://example.test/s/', 'image' => 7 ] );
        $html = $this->renderAt( $service, [ 'item' => '2', 'etab' => 'content' ] );
        $this->assertStringContainsString( 'value="' . MegaMenuPanel::ACTION_CONTENT . '"', $html );
        $this->assertStringContainsString( 'name="item" value="2"', $html );
        $this->assertStringContainsString( 'value="Our &quot;services&quot;"', $html );
        $this->assertStringContainsString( '>We do things.</textarea>', $html );
        $this->assertStringContainsString( 'value="See"', $html );
        $this->assertStringContainsString( 'value="https://example.test/s/"', $html );
        $this->assertStringContainsString( 'name="image" id="aied-mm-image" value="7"', $html );
        $this->assertStringContainsString( 'src="https://example.test/uploads/team.jpg"', $html );
        $this->assertStringContainsString( 'id="aied-mm-pick"', $html );
        $this->assertStringContainsString( 'Reset to the defaults', $html );
        $this->assertStringContainsString( 'Save and update the header', $html );
        $this->assertStringContainsString( 'wp.media', $html, 'the Media Library script is on the page' );
        $this->assertStringNotContainsString( 'wp.media', $this->renderAt( $service, [ 'item' => '2', 'etab' => 'layout' ] ), 'and only on this tab' );
    }

    public function testTheLayoutTabFramesTheItemsLayoutAndOffersItPerItemOrForAll(): void
    {
        $html = $this->renderAt( $this->appliedWithASlot(), [ 'item' => '2', 'etab' => 'layout' ] );
        $this->assertSame( 2, substr_count( $html, 'aied-mm-card is-current' ), 'this item\'s layout and the other item\'s' );
        $this->assertStringContainsString( '>This item</span>', $html );
        $this->assertStringContainsString( '>Another item</span>', $html );
        $this->assertStringContainsString( 'Rebuild this item</button>', $html );
        $this->assertSame( 12, substr_count( $html, 'Use for this item</button>' ) );
        $this->assertSame( 13, substr_count( $html, 'name="item" value="2"' ), 'every card posts the item' );
        $this->assertStringContainsString( 'Use &quot;Columns and a card on the right&quot; for all items', $html );
    }

    public function testTheBuilderTabShowsAutomaticOrEditedAndTheChoice(): void
    {
        $service = $this->appliedWithASlot();
        $html    = $this->renderAt( $service, [ 'item' => '2', 'etab' => 'builder' ] );
        $this->assertStringContainsString( '>Automatic</span>', $html );
        $this->assertStringNotContainsString( 'Replace my version', $html );
        $this->assertStringContainsString( 'Open in Divi</a>', $html );
        $this->assertStringContainsString( 'page=et_theme_builder', $html, 'without Divi\'s builder address the Theme Builder screen is the fallback' );
        $this->assertMatchesRegularExpression( '#<option value="ask" selected=\'selected\'>Ask me#', $html );

        $service->content()->setEdits( 2, 'keep' );
        $this->editPanelByHand( 2 );
        $html = $this->renderAt( $service, [ 'item' => '2', 'etab' => 'builder' ] );
        $this->assertStringContainsString( '>Edited in Divi</span>', $html );
        $this->assertStringContainsString( 'Replace my version', $html );
        $this->assertMatchesRegularExpression( '#<option value="keep" selected=\'selected\'>Keep my version#', $html );
        $this->assertStringNotContainsString( '>Automatic</span>', $html );
    }

    // ---- saving content ----------------------------------------------------------------------------------------------

    public function testSavingContentStoresItUpdatesTheHeaderAndComesBackToTheItem(): void
    {
        $GLOBALS['__wp_attachments'] = [ 7 => [ 'url' => 'https://example.test/uploads/team.jpg' ] ];
        $GLOBALS['__wp_postmeta'][7]['_wp_attachment_image_alt'] = 'The team';
        $service = $this->appliedWithASlot();
        $this->postAs( MegaMenuPanel::ACTION_CONTENT, [ 'item' => '2', 'title' => 'Our services', 'text' => 'What we do.', 'button' => 'See them', 'url' => 'https://example.test/s/', 'image' => '7' ] );
        $this->panel( $service )->handleContent();
        $this->assertSame( 'megamenu_content_saved', $this->redirectNotice() );
        $this->assertSame( '2', $this->redirectArgs()['item'] );
        $this->assertSame( 'content', $this->redirectArgs()['etab'] );
        $panel = HeaderEdit::panelBlocks( $this->headerContent() )[2];
        $this->assertStringContainsString( 'Our services', $panel );
        $this->assertStringContainsString( 'https://example.test/uploads/team.jpg', $panel );
        $this->assertStringContainsString( 'The team', $panel );
        $this->assertNull( $service->status()['drift'] );
        $this->assertSame( 7, $service->content()->get( 2 )['image'] );
    }

    public function testContentForALayoutWithoutASlotIsSavedAndTheHeaderIsLeftAlone(): void
    {
        $service = $this->appliedWithASlot();
        $before  = $this->headerContent();
        $this->postAs( MegaMenuPanel::ACTION_CONTENT, [ 'item' => '20', 'title' => 'Blog words' ] );
        $this->panel( $service )->handleContent();
        $this->assertSame( 'megamenu_content_noslot', $this->redirectNotice() );
        $this->assertSame( $before, $this->headerContent() );
        $this->assertSame( 'Blog words', $service->content()->get( 20 )['title'] );
    }

    public function testContentForAnItemWithoutAPanelIsRefused(): void
    {
        $service = $this->appliedWithASlot();
        $this->postAs( MegaMenuPanel::ACTION_CONTENT, [ 'item' => '1', 'title' => 'x' ] );
        $this->panel( $service )->handleContent();
        $this->assertSame( 'megamenu_error', $this->redirectNotice() );
        $this->assertSame( [], $service->content()->all(), 'nothing stored' );
        $this->postAs( MegaMenuPanel::ACTION_CONTENT, [ 'title' => 'x' ] );
        $this->panel( $service )->handleContent();
        $this->assertSame( 'megamenu_error', $this->redirectNotice() );
    }

    public function testNonStringContentFieldsAreEmptyNotCast(): void
    {
        $service = $this->appliedWithASlot();
        $this->postAs( MegaMenuPanel::ACTION_CONTENT, [ 'item' => '2', 'title' => [ 'a' ], 'text' => 5, 'url' => 'javascript:alert(1)' ] );
        $this->panel( $service )->handleContent();
        $this->assertSame( '', $service->content()->get( 2 )['title'] . $service->content()->get( 2 )['text'] . $service->content()->get( 2 )['url'] );
    }

    public function testResetGoesBackToTheDefaultsAndKeepsTheChoiceForEditedPanels(): void
    {
        $service = $this->appliedWithASlot();
        $service->content()->save( 2, [ 'title' => 'Mine' ] );
        $service->content()->setEdits( 2, 'keep' );
        $service->refresh();
        $this->assertStringContainsString( 'Mine', $this->headerContent() );
        $this->postAs( MegaMenuPanel::ACTION_CONTENT_RESET, [ 'item' => '2' ] );
        $this->panel( $service )->handleContentReset();
        $this->assertSame( 'megamenu_content_reset', $this->redirectNotice() );
        $this->assertStringNotContainsString( 'Mine', $this->headerContent() );
        $this->assertSame( 'keep', $service->content()->get( 2 )['edits'] );
    }

    public function testTheEditsChoiceIsSavedAndAnUnknownOneIsRefused(): void
    {
        $service = $this->appliedWithASlot();
        $this->postAs( MegaMenuPanel::ACTION_EDITS, [ 'item' => '2', 'edits' => 'keep' ] );
        $this->panel( $service )->handleEdits();
        $this->assertSame( 'megamenu_edits_saved', $this->redirectNotice() );
        $this->assertSame( 'keep', $service->content()->get( 2 )['edits'] );
        foreach ( [ [ 'item' => '2', 'edits' => 'delete' ], [ 'item' => '2', 'edits' => [ 'keep' ] ], [ 'edits' => 'keep' ] ] as $bad ) {
            $this->postAs( MegaMenuPanel::ACTION_EDITS, $bad );
            $this->panel( $service )->handleEdits();
            $this->assertSame( 'megamenu_error', $this->redirectNotice() );
        }
        $this->assertSame( 'keep', $service->content()->get( 2 )['edits'] );
    }

    // ---- a panel edited in the builder: the question and the answers ------------------------------------------------------

    public function testUpdatingWithAnEditedPanelAsksOnTheTabAndChangesNothingUntilAnswered(): void
    {
        $this->appliedTwoPanels();
        $this->editPanelByHand( 2 );
        $before  = $this->headerContent();
        $service = $this->service( $this->editedMenu() );
        $this->postAs( MegaMenuPanel::ACTION_UPDATE, [] );
        $this->panel( $service )->handleUpdate();
        $this->assertSame( 'megamenu_edited', $this->redirectNotice() );
        $this->assertSame( $before, $this->headerContent() );

        $html = $this->renderAt( $service );
        $this->assertStringContainsString( 'You changed the panel of &quot;Services&quot; in the Divi builder.', $html );
        $this->assertStringContainsString( 'Keep my changes</button>', $html );
        $this->assertStringContainsString( 'Replace them</button>', $html );
        $this->assertSame( 3, substr_count( $html, 'value="' . MegaMenuPanel::ACTION_RESOLVE . '"' ) );
    }

    public function testKeepingMyChangesUpdatesTheRestAndLeavesMyPanelExactlyAsItWas(): void
    {
        $this->appliedTwoPanels();
        $this->editPanelByHand( 2 );
        $mine    = HeaderEdit::panelBlocks( $this->headerContent() )[2];
        $service = $this->service( $this->editedMenu() );
        $this->postAs( MegaMenuPanel::ACTION_UPDATE, [] );
        $this->panel( $service )->handleUpdate();
        $this->postAs( MegaMenuPanel::ACTION_RESOLVE, [ 'choice' => 'keep' ] );
        $this->panel( $service )->handleResolve();
        $this->assertSame( 'megamenu_updated', $this->redirectNotice() );
        $this->assertSame( $mine, HeaderEdit::panelBlocks( $this->headerContent() )[2] );
        $this->assertStringNotContainsString( 'Guides', HeaderEdit::panelBlocks( $this->headerContent() )[20], 'the other panel was rebuilt from the edited menu' );
        $this->assertStringNotContainsString( 'You changed', $this->renderAt( $service ), 'the question is answered' );
    }

    public function testReplacingThemRebuildsMyPanel(): void
    {
        $this->appliedTwoPanels();
        $this->editPanelByHand( 2 );
        $service = $this->service( $this->twoPanelMenu() );
        $this->postAs( MegaMenuPanel::ACTION_UPDATE, [] );
        $this->panel( $service )->handleUpdate();
        $this->postAs( MegaMenuPanel::ACTION_RESOLVE, [ 'choice' => 'rebuild' ] );
        $this->panel( $service )->handleResolve();
        $this->assertStringNotContainsString( '(edited)', $this->headerContent() );
    }

    public function testCancellingChangesNothing(): void
    {
        $this->appliedTwoPanels();
        $this->editPanelByHand( 2 );
        $before  = $this->headerContent();
        $service = $this->service( $this->editedMenu() );
        $this->postAs( MegaMenuPanel::ACTION_UPDATE, [] );
        $this->panel( $service )->handleUpdate();
        $this->postAs( MegaMenuPanel::ACTION_RESOLVE, [ 'choice' => 'cancel' ] );
        $this->panel( $service )->handleResolve();
        $this->assertSame( 'megamenu_cancelled', $this->redirectNotice() );
        $this->assertSame( $before, $this->headerContent() );
        $this->assertStringNotContainsString( 'You changed', $this->renderAt( $service ) );
    }

    public function testAnAnswerWithNothingPendingOrAnUnknownAnswerChangesNothing(): void
    {
        $this->appliedTwoPanels();
        $this->editPanelByHand( 2 );
        $before  = $this->headerContent();
        $service = $this->service( $this->editedMenu() );
        $this->postAs( MegaMenuPanel::ACTION_RESOLVE, [ 'choice' => 'rebuild' ] );
        $this->panel( $service )->handleResolve();
        $this->assertSame( 'megamenu_cancelled', $this->redirectNotice() );
        $this->postAs( MegaMenuPanel::ACTION_UPDATE, [] );
        $this->panel( $service )->handleUpdate();
        $this->postAs( MegaMenuPanel::ACTION_RESOLVE, [ 'choice' => 'drop-everything' ] );
        $this->panel( $service )->handleResolve();
        $this->assertSame( 'megamenu_cancelled', $this->redirectNotice() );
        $this->assertSame( $before, $this->headerContent() );
    }

    public function testApplyingALayoutToAllAsksWhenAPanelWasEditedAndTheAnswerCompletesIt(): void
    {
        $this->appliedTwoPanels();
        $this->editPanelByHand( 20 );
        $service = $this->service( $this->twoPanelMenu() );
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'icons-cards' ] );
        $this->panel( $service )->handleApply();
        $this->assertSame( 'megamenu_edited', $this->redirectNotice() );
        $this->assertSame( [ 2 => 'columns-footer', 20 => 'columns-footer' ], HeaderEdit::panels( $this->headerContent() ), 'nothing changed yet' );
        $this->postAs( MegaMenuPanel::ACTION_RESOLVE, [ 'choice' => 'rebuild' ] );
        $this->panel( $service )->handleResolve();
        $this->assertSame( 'megamenu_applied', $this->redirectNotice() );
        $this->assertSame( [ 2 => 'icons-cards', 20 => 'icons-cards' ], HeaderEdit::panels( $this->headerContent() ) );
    }

    public function testTheNewHandlersNeedTheLicenceThePermissionAndAValidNonce(): void
    {
        $service = $this->appliedWithASlot();
        $before  = $this->headerContent();
        $stored  = $service->content()->all();
        $cases   = [
            [ MegaMenuPanel::ACTION_CONTENT, 'handleContent', [ 'item' => '2', 'title' => 'x' ] ],
            [ MegaMenuPanel::ACTION_CONTENT_RESET, 'handleContentReset', [ 'item' => '2' ] ],
            [ MegaMenuPanel::ACTION_EDITS, 'handleEdits', [ 'item' => '2', 'edits' => 'keep' ] ],
            [ MegaMenuPanel::ACTION_RESOLVE, 'handleResolve', [ 'choice' => 'keep' ] ],
        ];
        foreach ( $cases as [ $action, $method, $fields ] ) {
            $this->unlocked = false;
            $this->postAs( $action, $fields );
            $this->panel( $service )->$method();
            $this->assertSame( 'megamenu_error', $this->redirectNotice(), $action . ' without the licence' );
            $this->unlocked = true;

            $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
            $this->postAs( $action, $fields );
            try {
                $this->panel( $service )->$method();
                $this->fail( $action . ' must stop without the permission' );
            } catch ( \RuntimeException $e ) {
                $this->assertStringContainsString( 'Unauthorized', $e->getMessage() );
            }
            unset( $GLOBALS['__wp_caps'] );
            $GLOBALS['__wp_can'] = true;

            $_POST    = $fields;
            $_REQUEST = [ '_wpnonce' => 'wrong' ];
            try {
                $this->panel( $service )->$method();
                $this->fail( $action . ' must refuse a bad nonce' );
            } catch ( \RuntimeException $e ) {
                $this->assertStringContainsString( 'check_admin_referer', $e->getMessage() );
            }
        }
        $this->assertSame( $before, $this->headerContent() );
        $this->assertSame( $stored, $service->content()->all() );
    }

    public function testEveryCardHasAShortHintAndTheTabKeepsItsTextShort(): void
    {
        foreach ( Layouts::all() as $layout ) {
            $this->assertMatchesRegularExpression( '/^Best for: .{10,70}$/', (string) $layout['hint'], $layout['id'] );
        }
        $html = $this->render( $this->service( $this->twoPanelMenu() ) );
        $this->assertSame( 13, substr_count( $html, 'Best for: ' ) );
        $this->assertStringContainsString( '<details class="aied-mm-help">', $html );
        $text = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) preg_replace( '#<(style|svg).*?</\1>#s', '', $html ) ) ) );
        $this->assertLessThan( 2400, strlen( $text ), 'the visible text of the tab stays short (' . strlen( $text ) . ' characters)' );
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

        // One step back puts the header exactly as it was (the batch is the one Recent Pro changes lists).
        $r = $this->undoEngine()->undo( (int) $result['batch']['id'] );
        $this->assertTrue( $r['fully_undone'] );
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

    // ---- the notice on every admin screen ----------------------------------------------------------------------------------

    private function noticeHtml( ?MegaMenuService $service = null ): string
    {
        ob_start();
        $this->panel( $service )->adminNotice();

        return (string) ob_get_clean();
    }

    public function testAnOutOfDateMegaMenuShowsAWordPressNoticeWithTheUpdateButtonOnEveryScreen(): void
    {
        $GLOBALS['__wp_is_admin'] = true;
        $this->service( $this->twoPanelMenu() )->apply( 'columns-footer' );
        $html = $this->noticeHtml( $this->service( $this->editedMenu() ) );
        $this->assertStringContainsString( 'class="notice notice-warning aied-mm-wpnotice"', $html );
        $this->assertStringContainsString( 'Your mega menu is out of date.', $html );
        $this->assertStringContainsString( 'in your menu but not in the header yet', $html );
        $this->assertStringContainsString( 'more change', $html );
        $this->assertStringContainsString( 'value="' . MegaMenuPanel::ACTION_UPDATE . '"', $html );
        $this->assertStringContainsString( 'Update the mega menu', $html );
        $this->assertStringContainsString( 'tab=megamenu', $html );
    }

    public function testNoNoticeWhenTheHeaderMatchesOrNoneIsApplied(): void
    {
        $GLOBALS['__wp_is_admin'] = true;
        $this->assertSame( '', $this->noticeHtml( $this->service( $this->editedMenu() ) ), 'no mega menu on the header: nothing to be out of date' );
        $GLOBALS['__wp_transients'] = [];
        $this->service( $this->twoPanelMenu() )->apply( 'columns-footer' );
        $this->assertSame( '', $this->noticeHtml( $this->service( $this->twoPanelMenu() ) ) );
    }

    public function testNoNoticeOnTheMegaMenuTabItself(): void
    {
        $GLOBALS['__wp_is_admin'] = true;
        $this->service( $this->twoPanelMenu() )->apply( 'columns-footer' );
        $_GET = [ 'page' => 'ai-editor-divi5', 'tab' => 'megamenu' ];
        $this->assertSame( '', $this->noticeHtml( $this->service( $this->editedMenu() ) ) );
        $_GET = [ 'page' => 'ai-editor-divi5', 'tab' => 'pro' ];
        $this->assertNotSame( '', $this->noticeHtml( $this->service( $this->editedMenu() ) ), 'another tab of the plugin still shows it' );
    }

    public function testNoNoticeWithoutTheLicenceOrThePermission(): void
    {
        $GLOBALS['__wp_is_admin'] = true;
        $this->service( $this->twoPanelMenu() )->apply( 'columns-footer' );
        $service = $this->service( $this->editedMenu() );

        $this->unlocked = false;
        $this->assertSame( '', $this->noticeHtml( $service ) );
        $this->unlocked = true;

        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->assertSame( '', $this->noticeHtml( $service ) );
    }

    public function testTheAnswerIsCachedAndForgottenWhenTheMenuOrTheHeaderIsSaved(): void
    {
        $GLOBALS['__wp_is_admin'] = true;
        $this->service( $this->twoPanelMenu() )->apply( 'columns-footer' );
        $this->assertNotSame( '', $this->noticeHtml( $this->service( $this->editedMenu() ) ) );
        $this->assertArrayHasKey( 'aied_pro_megamenu_drift', $GLOBALS['__wp_transients'] );

        // Served from the cache: a service that would say "no drift" is not asked.
        $this->assertNotSame( '', $this->noticeHtml( $this->service( $this->twoPanelMenu() ) ) );

        // The hooks that throw it away are registered, and calling one does.
        $panel = $this->panel();
        $panel->register();
        foreach ( [ 'wp_update_nav_menu', 'wp_update_nav_menu_item', 'wp_delete_nav_menu', 'save_post', 'deleted_post', 'admin_notices' ] as $tag ) {
            $this->assertArrayHasKey( $tag, $GLOBALS['__wp_filters'], $tag );
        }
        $panel->forgetDrift( 5, [] );
        $this->assertArrayNotHasKey( 'aied_pro_megamenu_drift', $GLOBALS['__wp_transients'] );
        $this->assertSame( '', $this->noticeHtml( $this->service( $this->twoPanelMenu() ) ) );
    }

    public function testUpdatingThroughTheNoticeClearsTheCachedAnswer(): void
    {
        $GLOBALS['__wp_is_admin'] = true;
        $this->service( $this->twoPanelMenu() )->apply( 'columns-footer' );
        $service = $this->service( $this->editedMenu() );
        $this->assertNotSame( '', $this->noticeHtml( $service ) );
        $this->postAs( MegaMenuPanel::ACTION_UPDATE, [] );
        $this->panel( $service )->handleUpdate();
        $this->assertSame( '', $this->noticeHtml( $service ), 'after the update the notice is gone' );
    }

    // ---- panels edited in the Divi builder, and the owner's own content ----------------------------------------------------

    /** Changes one panel the way an edit in the builder would: a text under the panel changes. */
    private function editPanelByHand( int $itemId ): void
    {
        $content = $this->headerContent();
        $panel   = HeaderEdit::panelBlocks( $content )[ $itemId ];
        $edited  = preg_replace( '/"text":"([^"]+)"/', '"text":"$1 (edited)"', $panel, 1 );
        $this->assertNotSame( $panel, $edited, 'the panel has a link to edit' );
        $GLOBALS['__wp_posts'][201]->post_content = str_replace( $panel, (string) $edited, $content );
    }

    private function appliedTwoPanels( string $layout = 'columns-footer' ): MegaMenuService
    {
        $service = $this->service( $this->twoPanelMenu() );
        $service->apply( $layout );

        return $service;
    }

    private function statusOf( MegaMenuService $service, int $itemId ): array
    {
        foreach ( $service->status()['items'] as $it ) {
            if ( $it['id'] === $itemId ) {
                return $it;
            }
        }
        $this->fail( 'no such item ' . $itemId );
    }

    public function testNothingIsEditedRightAfterAnApplyAndAReSaveIsNotAnEdit(): void
    {
        $service = $this->appliedTwoPanels();
        $this->assertFalse( $this->statusOf( $service, 2 )['edited'] );
        // The builder writes the same header with other key order and version: still not an edit.
        $content = $this->headerContent();
        foreach ( HeaderEdit::panelBlocks( $content ) as $panel ) {
            $re = (string) preg_replace_callback( '/(<!--\s+wp:divi\/[a-z0-9-]+\s+)(\{.*?\})(\s*\/?-->)/s', static function ( array $m ): string {
                $a = json_decode( $m[2], true );
                $a = array_reverse( $a, true );
                $a['builderVersion'] = '5.99.0';

                return $m[1] . json_encode( $a, JSON_UNESCAPED_SLASHES ) . $m[3];
            }, $panel );
            $content = str_replace( $panel, $re, $content );
        }
        $GLOBALS['__wp_posts'][201]->post_content = $content;
        $this->assertFalse( $this->statusOf( $service, 2 )['edited'] );
        $this->assertFalse( $this->statusOf( $service, 20 )['edited'] );
        $this->assertNull( $service->status()['drift'] );
    }

    public function testAPanelChangedByHandIsSeenAndIsNotCalledOutOfDate(): void
    {
        $service = $this->appliedTwoPanels();
        $this->editPanelByHand( 2 );
        $this->assertTrue( $this->statusOf( $service, 2 )['edited'] );
        $this->assertFalse( $this->statusOf( $service, 20 )['edited'] );
        $this->assertNull( $service->status()['drift'], 'an edited panel differs on purpose' );
    }

    public function testUpdatingWithAnEditedPanelAndNoAnswerAsksAndChangesNothing(): void
    {
        $service = $this->appliedTwoPanels();
        $this->editPanelByHand( 2 );
        $before = $this->headerContent();
        try {
            $this->service( $this->editedMenu() )->refresh();
            $this->fail( 'must ask' );
        } catch ( EditedPanelsException $e ) {
            $this->assertSame( [ 2 => 'Services' ], $e->panels );
            $this->assertStringContainsString( 'the panel of "Services"', $e->getMessage() );
        }
        $this->assertSame( $before, $this->headerContent() );
    }

    public function testKeepingLeavesTheEditedPanelAsItIsAndUpdatesTheRest(): void
    {
        $this->appliedTwoPanels();
        $this->editPanelByHand( 2 );
        $editedPanel = HeaderEdit::panelBlocks( $this->headerContent() )[2];
        $service     = $this->service( $this->editedMenu() );
        $result      = $service->refresh( false, 'keep' );
        $this->assertTrue( $result['changed'] );
        $this->assertSame( $editedPanel, HeaderEdit::panelBlocks( $this->headerContent() )[2], 'the owner\'s panel is byte for byte as they left it' );
        $this->assertSame( [ 2 ], $result['mega_menu']['edited_kept'] );
        // Blog was rebuilt from the edited menu (it lost "Guides"); Services still shows as edited, and a menu change inside it is not reported.
        $this->assertStringNotContainsString( 'Guides', HeaderEdit::panelBlocks( $this->headerContent() )[20] );
        $this->assertTrue( $this->statusOf( $service, 2 )['edited'] );
        $this->assertNull( $service->status()['drift'] );
        $this->assertSame( [ 2 => 'columns-footer', 20 => 'columns-footer' ], HeaderEdit::panels( $this->headerContent() ) );
    }

    public function testReplacingRebuildsTheEditedPanelAndItIsOursAgain(): void
    {
        $this->appliedTwoPanels();
        $this->editPanelByHand( 2 );
        $service = $this->service( $this->twoPanelMenu() );
        $result  = $service->refresh( false, 'rebuild' );
        $this->assertTrue( $result['changed'] );
        $this->assertSame( [ 2 ], $result['mega_menu']['edited_replaced'] );
        $this->assertStringNotContainsString( '(edited)', $this->headerContent() );
        $this->assertFalse( $this->statusOf( $service, 2 )['edited'] );
    }

    public function testEachItemsOwnChoiceBeatsTheQuestion(): void
    {
        $service = $this->appliedTwoPanels();
        $this->editPanelByHand( 2 );
        $service->content()->setEdits( 2, 'keep' );
        $this->assertTrue( $this->service( $this->editedMenu() )->refresh()['changed'], 'no answer needed: the item says keep' );
        $this->assertStringContainsString( '(edited)', $this->headerContent() );

        $service->content()->setEdits( 2, 'rebuild' );
        $this->service( $this->editedMenu() )->refresh();
        $this->assertStringNotContainsString( '(edited)', $this->headerContent() );
    }

    public function testGivingOneItemANewLayoutRebuildsThatItemWithoutAskingButStillProtectsTheOther(): void
    {
        $service = $this->appliedTwoPanels();
        $this->editPanelByHand( 2 );
        $this->editPanelByHand( 20 );
        // Item 2 is the one being changed on purpose; item 20 was edited too and has no answer yet.
        try {
            $service->apply( 'icons-cards', false, 2 );
            $this->fail( 'Blog\'s edit must be protected' );
        } catch ( EditedPanelsException $e ) {
            $this->assertSame( [ 20 => 'Blog' ], $e->panels );
        }
        $service->apply( 'icons-cards', false, 2, 'keep' );
        $this->assertSame( [ 2 => 'icons-cards', 20 => 'columns-footer' ], HeaderEdit::panels( $this->headerContent() ) );
        $this->assertStringContainsString( '(edited)', HeaderEdit::panelBlocks( $this->headerContent() )[20] );
        $this->assertStringNotContainsString( '(edited)', HeaderEdit::panelBlocks( $this->headerContent() )[2] );
    }

    public function testUseForAllItemsAsksWhenAPanelWasEdited(): void
    {
        $service = $this->appliedTwoPanels();
        $this->editPanelByHand( 20 );
        $this->expectException( EditedPanelsException::class );
        try {
            $service->apply( 'feature-right' );
        } finally {
            $this->assertStringContainsString( '(edited)', $this->headerContent() );
        }
    }

    public function testAPreviewNeverAsksAndNeverWrites(): void
    {
        $service = $this->appliedTwoPanels();
        $this->editPanelByHand( 2 );
        $before = $this->headerContent();
        $dry    = $this->service( $this->editedMenu() )->refresh( true );
        $this->assertFalse( $dry['changed'] ?? false );
        $this->assertSame( [ 2 ], $dry['mega_menu']['edited_replaced'] );
        $this->assertSame( $before, $this->headerContent() );
    }

    public function testRemovingTheMegaMenuTakesTheEditedPanelAwayAndTheNextApplyStartsClean(): void
    {
        $service = $this->appliedTwoPanels();
        $this->editPanelByHand( 2 );
        $service->remove();
        $service->apply( 'columns-footer' );
        $this->assertFalse( $this->statusOf( $service, 2 )['edited'] );
    }

    public function testAHeaderAppliedBeforeRecordsExistIsNeverCalledEdited(): void
    {
        $service = $this->appliedTwoPanels();
        $GLOBALS['__wp_posts'][201]->post_content = (string) preg_replace( '/(JHMG panel \d+ [a-z0-9-]+) [0-9a-f]{10}/', '$1', $this->headerContent() );
        $this->editPanelByHand( 2 );
        $this->assertFalse( $this->statusOf( $service, 2 )['edited'] );
        // Nothing to protect as far as we can tell, so an update does not ask and writes our version.
        $this->assertTrue( $service->refresh( false )['changed'] );
        $this->assertStringNotContainsString( '(edited)', $this->headerContent() );
    }

    public function testTheOwnersContentGoesIntoThePanelAndStaysThroughAnUpdate(): void
    {
        $service = $this->service( $this->twoPanelMenu() );
        $service->apply( 'feature-right' );
        $this->assertFalse( $this->statusOf( $service, 2 )['custom'] );
        $this->assertTrue( $this->statusOf( $service, 2 )['content'], 'the layout has a content slot' );

        $service->content()->save( 2, [ 'title' => 'Our services', 'text' => 'Everything we do.', 'button' => 'See them' ] );
        $this->assertTrue( $this->statusOf( $service, 2 )['custom'] );
        $this->assertNotNull( $service->status()['drift'], 'the header does not show the saved words yet' );
        $service->refresh();
        $panel = HeaderEdit::panelBlocks( $this->headerContent() )[2];
        $this->assertStringContainsString( 'Our services', $panel );
        $this->assertStringContainsString( 'Everything we do.', $panel );
        $this->assertStringContainsString( 'See them', $panel );
        $this->assertNull( $service->status()['drift'] );

        // The menu changes and the update runs again: the words are still there.
        $this->service( $this->editedMenu() )->refresh();
        $this->assertStringContainsString( 'Our services', HeaderEdit::panelBlocks( $this->headerContent() )[2] );
        // Another layout without a slot shows the links only.
        $service->apply( 'columns-footer', false, 2 );
        $this->assertStringNotContainsString( 'Our services', HeaderEdit::panelBlocks( $this->headerContent() )[2] );
        $this->assertTrue( $this->statusOf( $service, 2 )['custom'], 'the words are kept for when the layout has a slot again' );
    }

    public function testRestoringAnEarlierHeaderNeverMakesAPanelLookEdited(): void
    {
        $service = $this->service( $this->twoPanelMenu() );
        $service->apply( 'columns-footer' );
        $first = $this->headerContent();
        $service->apply( 'icons-cards' );
        $second = $this->headerContent();
        $this->assertNotSame( $first, $second );
        // "Recent Pro changes" puts the earlier content back byte for byte; the panels must still be ours, and the other way round too.
        foreach ( [ $first, $second, $first ] as $content ) {
            $GLOBALS['__wp_posts'][201]->post_content = $content;
            $this->assertFalse( $this->statusOf( $service, 2 )['edited'] );
            $this->assertFalse( $this->statusOf( $service, 20 )['edited'] );
            $service->refresh(); // an update must not ask: nothing here was edited by hand
        }
        $service->apply( 'feature-right' );
        $this->assertSame( [ 2 => 'feature-right', 20 => 'feature-right' ], HeaderEdit::panels( $this->headerContent() ) );
    }

    // ---- which menu the header shows -----------------------------------------------------------------------------------

    /** A second menu of the site: Shop has two sub-items, Pricing none. */
    private function shopMenu(): array
    {
        return [ 36 => [ 'name' => 'Shop menu', 'tree' => MenuTree::build( [
            self::item( 100, 0, 'Shop' ), self::item( 101, 100, 'Mugs' ), self::item( 102, 100, 'Shirts' ),
            self::item( 110, 0, 'Pricing' ),
        ] ) ] ];
    }

    private function menuModuleId(): string
    {
        preg_match( '/"menuId":\{"desktop":\{"value":"(\d*)"/', $this->headerContent(), $m );

        return $m[1] ?? '';
    }

    public function testTheMenuChoiceListsTheSitesMenusAndMarksTheOneShown(): void
    {
        $service = $this->service( $this->twoPanelMenu(), true, $this->shopMenu() );
        $status  = $service->status();
        $this->assertSame( [ [ 'id' => 35, 'name' => 'Main menu', 'items' => 3 ], [ 'id' => 36, 'name' => 'Shop menu', 'items' => 2 ] ], $status['menus'] );
        $this->assertSame( 35, $status['menu']['id'] );

        $html = $this->renderAt( $service );
        $this->assertStringContainsString( 'id="aied-mm-menu"', $html );
        $this->assertStringContainsString( '<option value="35" selected=\'selected\'>Main menu (3 items)</option>', $html );
        $this->assertStringContainsString( '<option value="36">Shop menu (2 items)</option>', $html );
        $this->assertStringContainsString( 'value="' . MegaMenuPanel::ACTION_MENU . '"', $html );
        $this->assertStringContainsString( 'Use this menu</button>', $html );
        // With one menu there is nothing to choose.
        $this->assertStringNotContainsString( 'id="aied-mm-menu"', $this->renderAt( $this->service( $this->twoPanelMenu() ) ) );
    }

    public function testUsingAnotherMenuChangesTheMenuModuleAndRebuildsThePanelsFromIt(): void
    {
        $service = $this->service( $this->twoPanelMenu(), true, $this->shopMenu() );
        $service->apply( 'icons-cards' );
        $this->assertSame( '35', $this->menuModuleId() );
        $result = $service->useMenu( 36 );
        $this->assertTrue( $result['changed'] );
        $this->assertSame( '36', $this->menuModuleId() );
        $this->assertSame( [ 100 => 'icons-cards' ], HeaderEdit::panels( $this->headerContent() ), 'only Shop has sub-items; it takes the layout the panels had' );
        $this->assertStringContainsString( 'Mugs', $this->headerContent() );
        $this->assertStringNotContainsString( 'Guides', $this->headerContent(), 'nothing of the old menu is left' );
        $this->assertSame( 36, $service->status()['menu']['id'] );
        $this->assertNull( $service->status()['drift'] );
        $this->assertFalse( $service->useMenu( 36 )['changed'], 'already the menu shown' );

        // It is one undoable step: the old menu and its panels come back byte for byte.
        $r = $this->undoEngine()->undo( (int) $result['batch']['id'] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( '35', $this->menuModuleId() );
        $this->assertSame( [ 2 => 'icons-cards', 20 => 'icons-cards' ], HeaderEdit::panels( $this->headerContent() ) );
    }

    public function testTheMenuCanBeChangedBeforeAnyMegaMenuIsOn(): void
    {
        $service = $this->service( $this->twoPanelMenu(), true, $this->shopMenu() );
        $before  = $this->headerContent();
        $result  = $service->useMenu( 36 );
        $this->assertTrue( $result['changed'] );
        $this->assertSame( '36', $this->menuModuleId() );
        $this->assertSame( $before, str_replace( '"value":"36"', '"value":"35"', $this->headerContent() ), 'only the menu option changed' );
        $this->assertFalse( $service->status()['applied'] );
    }

    public function testAMenuWithoutSubItemsIsRefusedWhileAMegaMenuIsOnAndNothingChanges(): void
    {
        $none    = [ 37 => [ 'name' => 'Flat menu', 'tree' => MenuTree::build( [ self::item( 200, 0, 'One' ), self::item( 201, 0, 'Two' ) ] ) ] ];
        $service = $this->service( $this->twoPanelMenu(), true, $none );
        $service->apply( 'columns-footer' );
        $before = $this->headerContent();
        try {
            $service->useMenu( 37 );
            $this->fail( 'must refuse' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( MegaMenuService::NO_SUBITEMS, $e->getMessage() );
        }
        $this->assertSame( $before, $this->headerContent() );
    }

    public function testAnUnknownMenuIsRefused(): void
    {
        $service = $this->service( $this->twoPanelMenu(), true, $this->shopMenu() );
        $before  = $this->headerContent();
        foreach ( [ 999, 0, -4 ] as $bad ) {
            try {
                $service->useMenu( $bad );
                $this->fail( 'must refuse ' . $bad );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertSame( MegaMenuService::NO_SUCH_MENU, $e->getMessage() );
            }
        }
        $this->assertSame( $before, $this->headerContent() );
    }

    public function testTheOwnersContentStaysStoredForTheOldMenusItems(): void
    {
        $service = $this->service( $this->twoPanelMenu(), true, $this->shopMenu() );
        $service->apply( 'feature-right' );
        $service->content()->save( 2, [ 'title' => 'Our services' ] );
        $service->refresh();
        $service->useMenu( 36 );
        $this->assertStringNotContainsString( 'Our services', $this->headerContent() );
        $this->assertSame( 'Our services', $service->content()->get( 2 )['title'], 'kept for when the old menu comes back' );
        $service->useMenu( 35 );
        $this->assertStringContainsString( 'Our services', $this->headerContent() );
    }

    public function testTheMenuHandlerChangesTheMenuAndTellsWhichOne(): void
    {
        $service = $this->service( $this->twoPanelMenu(), true, $this->shopMenu() );
        $service->apply( 'columns-footer' );
        $this->postAs( MegaMenuPanel::ACTION_MENU, [ 'menu' => '36' ] );
        $this->panel( $service )->handleMenu();
        $this->assertSame( 'megamenu_menu_changed', $this->redirectNotice() );
        $this->assertSame( '36', $this->menuModuleId() );
        $_GET['aied_pro_notice'] = 'megamenu_menu_changed';
        $this->assertStringContainsString( 'The header now shows the menu &quot;Shop menu&quot;, and the mega menu is built from it.', $this->render( $service ) );

        $plain = $this->service( $this->twoPanelMenu(), true, $this->shopMenu() );
        $GLOBALS['__wp_posts'][201]->post_content = MegaMenuFixture::header();
        $this->postAs( MegaMenuPanel::ACTION_MENU, [ 'menu' => '36' ] );
        $this->panel( $plain )->handleMenu();
        $this->assertSame( 'megamenu_menu_set', $this->redirectNotice(), 'no mega menu on: only the menu changes' );
    }

    public function testTheMenuHandlerRefusesJunkAndNeedsLicencePermissionAndNonce(): void
    {
        $service = $this->service( $this->twoPanelMenu(), true, $this->shopMenu() );
        $before  = $this->headerContent();
        foreach ( [ [], [ 'menu' => 'abc' ], [ 'menu' => [ '36' ] ], [ 'menu' => '0' ], [ 'menu' => '999' ] ] as $bad ) {
            $this->postAs( MegaMenuPanel::ACTION_MENU, $bad );
            $this->panel( $service )->handleMenu();
            $this->assertSame( 'megamenu_error', $this->redirectNotice() );
        }
        $this->unlocked = false;
        $this->postAs( MegaMenuPanel::ACTION_MENU, [ 'menu' => '36' ] );
        $this->panel( $service )->handleMenu();
        $this->assertSame( 'megamenu_error', $this->redirectNotice() );
        $this->unlocked = true;
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->postAs( MegaMenuPanel::ACTION_MENU, [ 'menu' => '36' ] );
        try {
            $this->panel( $service )->handleMenu();
            $this->fail( 'permission' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'Unauthorized', $e->getMessage() );
        }
        unset( $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can'] = true;
        $_POST    = [ 'menu' => '36' ];
        $_REQUEST = [ '_wpnonce' => 'wrong' ];
        try {
            $this->panel( $service )->handleMenu();
            $this->fail( 'nonce' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'check_admin_referer', $e->getMessage() );
        }
        $this->assertSame( $before, $this->headerContent() );
    }
}
