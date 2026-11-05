<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Admin\StrictModePanel;
use AiEditorDivi5\Pro\Companion\StrictMode;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/EditPostLinkShim.php';

/** The strict validation card: every form submission needs a valid nonce, the capability and a POST, or nothing changes. */
class StrictModePanelTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        $GLOBALS['__wp_redirects']  = [];
        $GLOBALS['__wp_can']        = true;
        unset( $GLOBALS['__wp_caps'] );
        $_GET = $_POST = $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        StrictMode::record( 5, 'Home', 'rest', null, [ 'X' ], '2026-11-05T10:00:00Z' );
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = $_REQUEST = [];
        unset( $_SERVER['REQUEST_METHOD'], $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can'] = true;
    }

    private function panel(): StrictModePanel
    {
        return new StrictModePanel( static fn (): bool => true, static function (): void {} );
    }

    private function post( string $action, array $fields, string $nonce ): void
    {
        $_POST    = $fields;
        $_REQUEST = $_POST + [ '_wpnonce' => $nonce ];
    }

    private function assertNothingChanged( string $why ): void
    {
        $this->assertFalse( StrictMode::enabled(), "{$why}: the switch is unchanged" );
        $this->assertCount( 1, StrictMode::flags(), "{$why}: the flagged list is unchanged" );
        $this->assertSame( [], $GLOBALS['__wp_redirects'], "{$why}: no redirect, so nothing was handled" );
        $this->assertSame( [], $GLOBALS['__wp_transients'], "{$why}: no message was left behind" );
    }

    public function testSavingWithAForgedOrMissingNonceChangesNothing(): void
    {
        foreach ( [ 'forged', '' ] as $nonce ) {
            $this->post( StrictModePanel::ACTION_SAVE, [ 'strict' => '1' ], $nonce );
            try {
                $this->panel()->handleSave();
                $this->fail( 'a bad nonce must be refused' );
            } catch ( \RuntimeException $e ) {
                $this->assertStringContainsString( 'check_admin_referer failed', $e->getMessage() );
            }
            $this->assertNothingChanged( "save with nonce '{$nonce}'" );
        }
        $_REQUEST = [ 'strict' => '1' ]; // no _wpnonce key at all
        $_POST    = [ 'strict' => '1' ];
        try {
            $this->panel()->handleSave();
            $this->fail( 'a missing nonce must be refused' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'check_admin_referer failed', $e->getMessage() );
        }
        $this->assertNothingChanged( 'save with no nonce' );
    }

    public function testClearingWithAForgedNonceChangesNothing(): void
    {
        $this->post( StrictModePanel::ACTION_CLEAR, [], 'forged' );
        try {
            $this->panel()->handleClear();
            $this->fail( 'a bad nonce must be refused' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'check_admin_referer failed', $e->getMessage() );
        }
        $this->assertNothingChanged( 'clear with a forged nonce' );
    }

    public function testAValidNonceWithoutTheCapabilityOrWithAGetStillChangesNothing(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'manage_options' => false ];
        $this->post( StrictModePanel::ACTION_SAVE, [ 'strict' => '1' ], 'nonce-for-' . StrictModePanel::ACTION_SAVE );
        try {
            $this->panel()->handleSave();
            $this->fail( 'manage_options is required' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'wp_die', $e->getMessage() );
        }
        $this->assertNothingChanged( 'valid nonce, no capability' );

        unset( $GLOBALS['__wp_caps'] );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            $this->panel()->handleClear();
            $this->fail( 'only POST is accepted' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'wp_die', $e->getMessage() );
        }
        $this->assertNothingChanged( 'a GET request' );
    }

    public function testAValidRequestDoesChangeThingsAndShowsItsMessageOnce(): void
    {
        $this->post( StrictModePanel::ACTION_SAVE, [ 'strict' => '1' ], 'nonce-for-' . StrictModePanel::ACTION_SAVE );
        $this->panel()->handleSave();
        $this->assertTrue( StrictMode::enabled() );
        $this->assertNotSame( [], $GLOBALS['__wp_redirects'] );

        ob_start();
        $this->panel()->render( 'pro' );
        $first = (string) ob_get_clean();
        $this->assertStringContainsString( 'Saved.', $first );
        ob_start();
        $this->panel()->render( 'pro' );
        $this->assertStringNotContainsString( 'Saved.', (string) ob_get_clean(), 'the message is shown once' );

        $this->post( StrictModePanel::ACTION_CLEAR, [], 'nonce-for-' . StrictModePanel::ACTION_CLEAR );
        $this->panel()->handleClear();
        $this->assertSame( [], StrictMode::flags() );
    }

    public function testBothFormsCarryANonceAndTheCardNeverReadsTheAddress(): void
    {
        StrictMode::set( true );
        ob_start();
        $this->panel()->render( 'pro' );
        $html = (string) ob_get_clean();
        $this->assertStringContainsString( 'nonce-for-' . StrictModePanel::ACTION_SAVE, $html );
        $this->assertStringContainsString( 'nonce-for-' . StrictModePanel::ACTION_CLEAR, $html );
        $src = (string) file_get_contents( __DIR__ . '/../src/Admin/StrictModePanel.php' );
        $this->assertStringNotContainsString( '$_GET', $src, 'a message is never taken from the address' );
        $this->assertStringContainsString( "guardPost( self::ACTION_SAVE, 'manage_options' )", $src );
        $this->assertStringContainsString( "guardPost( self::ACTION_CLEAR, 'manage_options' )", $src );
    }
}
