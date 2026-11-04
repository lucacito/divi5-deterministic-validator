<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Admin\MegaMenuPanel;
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
        $this->assertSame( 20, substr_count( $html, 'aied-mm-card"' ) );
        $this->assertSame( 20, substr_count( $html, '>Use this layout</button>' ) );
        $this->assertStringNotContainsString( ' disabled>', $html );
        $this->assertStringContainsString( 'Simple lists', $html );
        $this->assertStringContainsString( 'Latest posts', $html );
        $this->assertStringContainsString( 'value="list-plain"', $html );
        $this->assertStringContainsString( 'value="' . MegaMenuPanel::ACTION_APPLY . '"', $html );
        $this->assertStringContainsString( 'nonce-for-' . MegaMenuPanel::ACTION_APPLY, $html );
        $this->assertStringContainsString( 'Tablet and phone keep the normal menu', $html );
    }

    public function testWithoutAGlobalHeaderTheNoticeShowsAndEveryUseButtonIsDisabled(): void
    {
        $html = $this->render( $this->service( null, false ) );
        $this->assertStringContainsString( 'needs a global header', $html );
        $this->assertStringContainsString( 'page=et_theme_builder', $html );
        $this->assertSame( 20, substr_count( $html, ' disabled>' ) );
        $this->assertStringNotContainsString( 'Remove mega menu', $html );
    }

    public function testAHeaderWithoutAMenuModuleDisablesTheButtons(): void
    {
        $GLOBALS['__wp_posts'][201]->post_content = self::header();
        $html                                      = $this->render();
        $this->assertStringContainsString( 'no Menu module', $html );
        $this->assertSame( 20, substr_count( $html, ' disabled>' ) );
    }

    public function testAMenuWithoutSubItemsDisablesTheButtons(): void
    {
        $html = $this->render( $this->service( [ MenuTree::build( [ self::item( 1, 0, 'Home' ) ] )[0] ] ) );
        $this->assertStringContainsString( 'None of the menu', $html );
        $this->assertSame( 20, substr_count( $html, ' disabled>' ) );
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
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'list-plain' ] );
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
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'list-plain' ] );
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
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'list-plain' ] );
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
        foreach ( [ [ 'list-plain' ], 12, 'nope', '', '../../etc' ] as $bad ) {
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
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'columns-3' ] );
        $this->panel()->handleApply();

        $this->assertNotSame( $before, $this->headerContent() );
        $this->assertTrue( HeaderEdit::inspect( $this->headerContent() )['hasMega'] );
        $this->assertSame( 'megamenu_applied', $this->redirectNotice() );
        $this->assertStringContainsString( 'tab=' . MegaMenuPanel::TAB, end( $GLOBALS['__wp_redirects'] ) );

        $_GET['aied_pro_notice'] = 'megamenu_applied';
        $html                    = $this->render();
        $this->assertStringContainsString( 'The mega menu &quot;Three columns&quot; is on your header now', $html );
        $this->assertStringContainsString( 'Remove mega menu', $html );
    }

    public function testAChangedNothingApplyRedirectsAsUnchanged(): void
    {
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'columns-3' ] );
        $this->panel()->handleApply();
        $GLOBALS['__wp_redirects'] = [];
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'columns-3' ] );
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
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'list-plain' ] );
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
        $this->postAs( MegaMenuPanel::ACTION_APPLY, [ 'layout' => 'list-plain' ] );
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
}
