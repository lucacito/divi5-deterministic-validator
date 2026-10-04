<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Licensing;
use AiEditorDivi5\Pro\ProPage;
use AiEditorDivi5\Pro\ToolRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/FakeTool.php';

class ProPageTest extends TestCase
{
    private const KEY = 'JHMG-ABCD-EFGH-IJKL-MNOP';

    protected function setUp(): void
    {
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        $GLOBALS['__wp_http_queue'] = [];
        $GLOBALS['__wp_http_log']   = [];
        $GLOBALS['__wp_redirects']  = [];
        $GLOBALS['__wp_filters']    = [];
        $GLOBALS['__wp_can']        = true;
        unset( $GLOBALS['__wp_caps'] );
        $_GET                      = [];
        $_POST                     = [];
        $_REQUEST                  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Licensing::resetForTests();
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = $_REQUEST = [];
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    /** @param list<FakeTool> $tools */
    private function page( array $tools = [] ): ProPage
    {
        return new ProPage( new ToolRegistry( $tools, static fn (): bool => Licensing::isPremium() ), static function (): void {} );
    }

    private function render( ProPage $page, string $tab = 'pro' ): string
    {
        ob_start();
        $page->render( $tab );

        return (string) ob_get_clean();
    }

    private function activated(): void
    {
        update_option( 'aied_pro_license_key', self::KEY );
        update_option( 'aied_pro_license_state', [ 'status' => 'active', 'expires' => '2027-07-12T00:00:00.000Z', 'checked_at' => time() ] );
        update_option( 'aied_pro_premium_unlocked', 1 );
        Licensing::resetForTests();
    }

    private function asPost( string $action, array $post = [] ): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST                     = $post;
        $_REQUEST                  = $post + [ '_wpnonce' => 'nonce-for-' . $action ];
    }

    // ---- registration ----------------------------------------------------------------------

    public function testTheTabIsAddedAndOtherTabsAreKept(): void
    {
        $page = $this->page();
        $this->assertSame( [ 'x' => 'X', 'pro' => 'Pro' ], $page->tabs( [ 'x' => 'X' ] ) );
        $this->assertSame( 'junk', $page->tabs( 'junk' ) );
    }

    public function testRegisterAttachesTheFreePluginsTabHooksAndTheTwoAdminPostActions(): void
    {
        $this->page()->register();
        $this->assertSame( [ 'pro' => 'Pro' ], apply_filters( 'jhmg_aied_admin_tabs', [] ) );
        foreach ( [ 'jhmg_aied_render_admin_tab', 'admin_post_aied_pro_activate', 'admin_post_aied_pro_deactivate' ] as $hook ) {
            $this->assertArrayHasKey( $hook, $GLOBALS['__wp_filters'], $hook );
        }
    }

    // ---- rendering -------------------------------------------------------------------------

    public function testItRendersOnlyItsOwnTabAndOnlyForAdministrators(): void
    {
        $this->assertSame( '', $this->render( $this->page(), 'features' ) );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->assertSame( '', $this->render( $this->page() ), 'manage_options is required' );
    }

    public function testWithoutALicenceItOffersActivationAndShowsNoTools(): void
    {
        $html = $this->render( $this->page() );
        $this->assertStringContainsString( 'No licence activated', $html );
        $this->assertStringContainsString( 'name="license_key"', $html );
        $this->assertStringContainsString( 'nonce-for-aied_pro_activate', $html );
        $this->assertStringNotContainsString( 'nonce-for-aied_pro_deactivate', $html );
        $this->assertStringContainsString( 'Activate a licence to unlock the Pro tools', $html );
    }

    public function testWithALicenceItShowsStatusExpiryAndANeverFullKey(): void
    {
        $this->activated();
        $html = $this->render( $this->page() );
        $this->assertStringContainsString( 'Active', $html );
        $this->assertStringContainsString( '2027-07-12', $html );
        $this->assertStringContainsString( 'MNOP', $html );
        $this->assertStringNotContainsString( self::KEY, $html );
        $this->assertStringNotContainsString( 'JHMG-ABCD', $html );
        $this->assertStringContainsString( 'nonce-for-aied_pro_deactivate', $html );
        $this->assertStringNotContainsString( 'name="license_key"', $html );
    }

    public function testUnlockedItListsTheToolsOrSaysThereAreNone(): void
    {
        $this->activated();
        $this->assertStringContainsString( 'No Pro tools are included in this version yet', $this->render( $this->page() ) );
        $this->assertStringContainsString( '<code>fake_pro_tool</code>', $this->render( $this->page( [ new FakeTool() ] ) ) );
    }

    public function testALapsedLicenceIsHonestButKeepsTheToolsListed(): void
    {
        $this->activated();
        update_option( 'aied_pro_license_state', [ 'status' => 'expired', 'expires' => '2026-01-01T00:00:00.000Z', 'checked_at' => time() ] );
        Licensing::resetForTests();
        $html = $this->render( $this->page( [ new FakeTool() ] ) );
        $this->assertStringContainsString( 'Expired', $html );
        $this->assertStringContainsString( '<code>fake_pro_tool</code>', $html, 'a lapse never locks' );
    }

    public function testAPastDueLicenceIsLabelledHonestlyAndKeepsTheToolsListed(): void
    {
        $this->activated();
        update_option( 'aied_pro_license_state', [ 'status' => 'past_due', 'expires' => '2026-01-01T00:00:00.000Z', 'checked_at' => time() ] );
        Licensing::resetForTests();
        $html = $this->render( $this->page( [ new FakeTool() ] ) );
        $this->assertStringContainsString( 'Past due', $html );
        $this->assertStringContainsString( '<code>fake_pro_tool</code>', $html, 'a lapse never locks' );
    }

    public function testANoticeFromTheRedirectIsShownAndEscaped(): void
    {
        $_GET['aied_pro_notice'] = 'activated';
        $this->assertStringContainsString( 'Licence activated', $this->render( $this->page() ) );
        $_GET['aied_pro_notice'] = '<script>alert(1)</script>';
        $html                    = $this->render( $this->page() );
        $this->assertStringNotContainsString( '<script>', $html );
        $_GET['aied_pro_notice'] = 'not-a-notice';
        $this->assertStringNotContainsString( 'notice-success', $this->render( $this->page() ) );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'keys' )]
    public function testMaskKeyNeverRevealsMoreThanTheLastFourCharacters( string $key, string $masked ): void
    {
        $this->assertSame( $masked, ProPage::maskKey( $key ) );
    }

    /** @return list<array{string,string}> */
    public static function keys(): array
    {
        return [
            [ '', '' ],
            [ 'abc', '***' ],
            [ 'abcd', '****' ],
            [ 'abcde', '*bcde' ],
            [ 'JHMG-ABCD-EFGH-IJKL-MNOP', '********************MNOP' ],
        ];
    }

    // ---- handlers --------------------------------------------------------------------------

    public function testActivationRefusesWithoutManageOptions(): void
    {
        $this->asPost( 'aied_pro_activate', [ 'license_key' => self::KEY ] );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->expectExceptionMessage( 'wp_die' );
        $this->page()->handleActivate();
    }

    public function testActivationRefusesABadNonce(): void
    {
        $this->asPost( 'aied_pro_activate', [ 'license_key' => self::KEY, '_wpnonce' => 'forged' ] );
        $this->expectExceptionMessage( 'check_admin_referer failed' );
        $this->page()->handleActivate();
    }

    public function testActivationRefusesANonPostRequest(): void
    {
        $this->asPost( 'aied_pro_activate', [ 'license_key' => self::KEY ] );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->expectExceptionMessage( 'wp_die' );
        $this->page()->handleActivate();
    }

    public function testAnEmptyKeyRedirectsWithoutCallingTheServer(): void
    {
        $this->asPost( 'aied_pro_activate', [ 'license_key' => '   ' ] );
        $this->page()->handleActivate();
        $this->assertCount( 1, $GLOBALS['__wp_redirects'] );
        $this->assertStringContainsString( 'aied_pro_notice=empty_key', $GLOBALS['__wp_redirects'][0] );
        $this->assertSame( [], $GLOBALS['__wp_http_log'] );
        $this->assertFalse( Licensing::isPremium() );
    }

    public function testASuccessfulActivationUnlocksAndRedirectsToTheProTab(): void
    {
        $GLOBALS['__wp_http_queue'][] = [ 'code' => 200, 'body' => [ 'status' => 'active', 'product' => 'ai-editor-divi5-pro', 'expires' => null ] ];
        $this->asPost( 'aied_pro_activate', [ 'license_key' => '  ' . self::KEY . ' ' ] );
        $this->page()->handleActivate();
        $this->assertTrue( Licensing::isPremium() );
        $url = $GLOBALS['__wp_redirects'][0];
        $this->assertStringContainsString( 'page=ai-editor-divi5', $url );
        $this->assertStringContainsString( 'tab=pro', $url );
        $this->assertStringContainsString( 'aied_pro_notice=activated', $url );
        $this->assertSame( self::KEY, json_decode( (string) $GLOBALS['__wp_http_log'][0]['payload'], true )['key'], 'the key is trimmed' );
    }

    public function testAFailedActivationStaysLocked(): void
    {
        $GLOBALS['__wp_http_queue'][] = [ 'code' => 404, 'body' => [ 'error' => 'invalid_key' ] ];
        $this->asPost( 'aied_pro_activate', [ 'license_key' => self::KEY ] );
        $this->page()->handleActivate();
        $this->assertFalse( Licensing::isPremium() );
        $this->assertStringContainsString( 'aied_pro_notice=key_invalid', $GLOBALS['__wp_redirects'][0] );
    }

    /** @return array<string,array{?string,string}> */
    public static function activationErrors(): array
    {
        return [
            'invalid key'      => [ 'invalid_key', 'key_invalid' ],
            'wrong product'    => [ 'product_mismatch', 'key_wrong_product' ],
            'not usable'       => [ 'license_not_usable', 'key_not_usable' ],
            'site limit'       => [ 'site_limit_reached', 'site_limit' ],
            'rate limited'     => [ 'rate_limited', 'rate_limited' ],
            'offline'          => [ 'network_error', 'offline' ],
            'something else'   => [ 'http_500', 'activate_failed' ],
            'no error at all'  => [ null, 'activate_failed' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'activationErrors' )]
    public function testEachActivationFailureHasItsOwnNotice( ?string $error, string $notice ): void
    {
        $this->assertSame( $notice, ProPage::failureNotice( $error ) );
    }

    public function testTheOfflineAndRateLimitedFailuresReachTheRedirect(): void
    {
        $GLOBALS['__wp_http_queue'][] = 'network_error';
        $this->asPost( 'aied_pro_activate', [ 'license_key' => self::KEY ] );
        $this->page()->handleActivate();
        $this->assertStringContainsString( 'aied_pro_notice=offline', $GLOBALS['__wp_redirects'][0] );

        $GLOBALS['__wp_http_queue'][] = [ 'code' => 429, 'body' => [ 'error' => 'rate_limited' ] ];
        $this->asPost( 'aied_pro_activate', [ 'license_key' => self::KEY ] );
        $this->page()->handleActivate();
        $this->assertStringContainsString( 'aied_pro_notice=rate_limited', $GLOBALS['__wp_redirects'][1] );
    }

    public function testEveryFailureNoticeHasAnExplanatoryMessage(): void
    {
        foreach ( [ 'key_invalid' => 'not recognised', 'key_wrong_product' => 'different product', 'key_not_usable' => 'not active', 'rate_limited' => 'Too many', 'offline' => 'divi5lab.com' ] as $notice => $text ) {
            $_GET['aied_pro_notice'] = $notice;
            $html                    = $this->render( $this->page() );
            $this->assertStringContainsString( 'notice-error', $html, $notice );
            $this->assertStringContainsString( $text, $html, $notice );
        }
    }

    public function testDeactivationReleasesTheLicenceAndLocks(): void
    {
        $this->activated();
        $GLOBALS['__wp_http_queue'][] = [ 'code' => 200, 'body' => [ 'ok' => true ] ];
        $this->asPost( 'aied_pro_deactivate' );
        $this->page()->handleDeactivate();
        $this->assertFalse( Licensing::isPremium() );
        $this->assertStringContainsString( '/api/license/deactivate', $GLOBALS['__wp_http_log'][0]['url'] );
        $this->assertStringContainsString( 'aied_pro_notice=deactivated', $GLOBALS['__wp_redirects'][0] );
    }

    public function testDeactivationRefusesBadNonceAndMissingCapability(): void
    {
        $this->activated();
        $this->asPost( 'aied_pro_deactivate', [ '_wpnonce' => 'forged' ] );
        try {
            $this->page()->handleDeactivate();
            $this->fail( 'a forged nonce must be refused' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'check_admin_referer failed', $e->getMessage() );
        }
        $this->assertTrue( Licensing::isPremium(), 'nothing was changed' );

        $this->asPost( 'aied_pro_deactivate' );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        try {
            $this->page()->handleDeactivate();
            $this->fail( 'a user without manage_options must be refused' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'wp_die', $e->getMessage() );
        }
        $this->assertTrue( Licensing::isPremium() );
    }
}
