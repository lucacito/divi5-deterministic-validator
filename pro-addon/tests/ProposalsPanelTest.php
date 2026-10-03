<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Admin\ProposalsPanel;
use AiEditorDivi5\Pro\Settings\PhpProposals;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class ProposalsPanelTest extends TestCase
{
    private bool $unlocked = true;

    protected function setUp(): void
    {
        $GLOBALS['__wp_options']   = [];
        $GLOBALS['__wp_redirects'] = [];
        $GLOBALS['__wp_filters']   = [];
        $GLOBALS['__wp_can']       = true;
        unset( $GLOBALS['__wp_caps'] );
        $_GET = $_POST = $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->unlocked            = true;
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = $_REQUEST = [];
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function panel(): ProposalsPanel
    {
        return new ProposalsPanel( fn (): bool => $this->unlocked, static function (): void {} );
    }

    private function render( string $tab = 'pro' ): string
    {
        ob_start();
        $this->panel()->render( $tab );

        return (string) ob_get_clean();
    }

    public function testRegisterAttachesTheTabRenderAndTheDeleteAction(): void
    {
        $this->panel()->register();
        $this->assertArrayHasKey( 'jhmg_aied_render_admin_tab', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'admin_post_aied_pro_delete_proposal', $GLOBALS['__wp_filters'] );
    }

    public function testItRendersOnlyOnTheProTabForAdministratorsWhileUnlocked(): void
    {
        PhpProposals::add( 'T', '', 'code', 1 );
        $this->assertSame( '', $this->render( 'features' ) );
        $this->unlocked = false;
        $this->assertSame( '', $this->render() );
        $this->unlocked       = true;
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->assertSame( '', $this->render(), 'manage_options is required to see code proposals' );
    }

    public function testItSaysNothingHasBeenRunAndShowsAnEmptyState(): void
    {
        $html = $this->render();
        $this->assertStringContainsString( 'Code proposals', $html );
        $this->assertStringContainsString( 'No code proposals', $html );
        $this->assertStringContainsString( 'never run', $html );
    }

    public function testEveryProposedValueIsEscaped(): void
    {
        PhpProposals::add( '<img src=x onerror=alert(1)>', '"><script>alert(2)</script>', "<?php\n</code></pre><script>alert(3)</script>", 1 );
        $html = $this->render();
        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringNotContainsString( '<img src=x', $html );
        $this->assertStringContainsString( '&lt;/code&gt;&lt;/pre&gt;&lt;script&gt;alert(3)', $html );
        $this->assertStringContainsString( '&lt;?php', $html );
    }

    public function testEachProposalHasADeleteFormWithItsOwnNonce(): void
    {
        $id   = PhpProposals::add( 'T', '', 'code', 1 );
        $html = $this->render();
        $this->assertStringContainsString( 'name="proposal_id" value="' . $id . '"', $html );
        $this->assertStringContainsString( 'nonce-for-aied_pro_delete_proposal', $html );
    }

    public function testDeleteRemovesTheProposalAndRedirects(): void
    {
        $id                = PhpProposals::add( 'T', '', 'code', 1 );
        $keep              = PhpProposals::add( 'K', '', 'code', 1 );
        $_POST             = [ 'proposal_id' => $id ];
        $_REQUEST          = $_POST + [ '_wpnonce' => 'nonce-for-aied_pro_delete_proposal' ];
        $this->panel()->handleDelete();
        $this->assertSame( [ $keep ], array_column( PhpProposals::all(), 'id' ) );
        $this->assertStringContainsString( 'aied_pro_notice=proposal_deleted', $GLOBALS['__wp_redirects'][0] );
    }

    public function testDeleteRefusesABadNonceAMissingCapabilityAndANonPost(): void
    {
        $id       = PhpProposals::add( 'T', '', 'code', 1 );
        $_POST    = [ 'proposal_id' => $id ];
        $_REQUEST = $_POST + [ '_wpnonce' => 'forged' ];
        try {
            $this->panel()->handleDelete();
            $this->fail( 'a forged nonce must be refused' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'check_admin_referer failed', $e->getMessage() );
        }

        $_REQUEST             = $_POST + [ '_wpnonce' => 'nonce-for-aied_pro_delete_proposal' ];
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        try {
            $this->panel()->handleDelete();
            $this->fail( 'manage_options is required' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'wp_die', $e->getMessage() );
        }

        unset( $GLOBALS['__wp_caps'] );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            $this->panel()->handleDelete();
            $this->fail( 'POST only' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'wp_die', $e->getMessage() );
        }
        $this->assertSame( 1, PhpProposals::count(), 'nothing was deleted' );
    }

    public function testTheDeletedNoticeIsShown(): void
    {
        $_GET['aied_pro_notice'] = 'proposal_deleted';
        $this->assertStringContainsString( 'Proposal deleted', $this->render() );
    }
}
