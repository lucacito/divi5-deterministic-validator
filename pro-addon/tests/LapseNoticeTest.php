<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Admin\LapseNotice;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/Admin/LapseNotice.php';

class LapseNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_filters']       = [];
        $GLOBALS['__wp_options']       = [];
        $GLOBALS['__wp_user_meta']     = [];
        $GLOBALS['__wp_can']           = true;
        $GLOBALS['__wp_is_admin']      = true;
        $GLOBALS['__wp_current_user']  = [ 'id' => 1 ];
        $GLOBALS['__wp_current_user_id'] = 1;
        unset( $GLOBALS['__wp_caps'] );
        $_GET  = [];
        $_POST = [];
    }

    private function capture( LapseNotice $notice ): string
    {
        \ob_start();
        $notice->display();

        return (string) \ob_get_clean();
    }

    public function testNoticeIsNotShownWhenStatusIsActive(): void
    {
        $notice = new LapseNotice(
            static function (): array {
                return [ 'valid' => true, 'status' => 'active', 'expires' => null, 'reason' => 'active' ];
            }
        );
        $notice->register();
        $output = $this->capture( $notice );
        $this->assertStringNotContainsString( 'Your licence has ended', $output );
    }

    public function testNoticeIsNotShownWhenThereIsNoStatus(): void
    {
        $notice = new LapseNotice(
            static function (): array {
                return [ 'valid' => false, 'status' => null, 'expires' => null, 'reason' => 'no_key' ];
            }
        );
        $notice->register();
        $output = $this->capture( $notice );
        $this->assertStringNotContainsString( 'Your licence has ended', $output );
    }

    public function testNoticeIsShownForExpiredStatus(): void
    {
        $notice = new LapseNotice(
            static function (): array {
                return [ 'valid' => true, 'status' => 'expired', 'expires' => 1234567890, 'reason' => 'expired' ];
            }
        );
        $notice->register();
        $output = $this->capture( $notice );
        $this->assertStringContainsString( 'Your licence has ended', $output );
        $this->assertStringContainsString( 'keeps working', $output );
    }

    public function testNoticeIsShownForCanceledStatus(): void
    {
        $notice = new LapseNotice(
            static function (): array {
                return [ 'valid' => true, 'status' => 'canceled', 'expires' => 1234567890, 'reason' => 'canceled' ];
            }
        );
        $notice->register();
        $output = $this->capture( $notice );
        $this->assertStringContainsString( 'Your licence has ended', $output );
    }

    public function testNoticeIsShownForPastDueStatus(): void
    {
        $notice = new LapseNotice(
            static function (): array {
                return [ 'valid' => true, 'status' => 'past_due', 'expires' => 1234567890, 'reason' => 'past_due' ];
            }
        );
        $notice->register();
        $output = $this->capture( $notice );
        $this->assertStringContainsString( 'Your licence has ended', $output );
    }

    public function testNoticeContainsRenewLink(): void
    {
        $notice = new LapseNotice(
            static function (): array {
                return [ 'valid' => true, 'status' => 'expired', 'expires' => 1234567890, 'reason' => 'expired' ];
            }
        );
        $notice->register();
        $output = $this->capture( $notice );
        $this->assertStringContainsString( 'Renew', $output );
        $this->assertStringContainsString( 'href=', $output );
    }

    public function testNoticeCanBeDismissed(): void
    {
        $notice = new LapseNotice(
            static function (): array {
                return [ 'valid' => true, 'status' => 'expired', 'expires' => 1234567890, 'reason' => 'expired' ];
            }
        );
        $notice->register();
        $output = $this->capture( $notice );
        $this->assertStringContainsString( 'Dismiss', $output );
        $this->assertStringContainsString( 'notice-warning', $output );
        $this->assertStringContainsString( 'action=aied_pro_dismiss_lapse_notice', $output );
        $this->assertStringContainsString( 'lapse_key=expired%7C1234567890', $output );
    }

    public function testDismissalTokenIsIncluded(): void
    {
        $notice = new LapseNotice(
            static function (): array {
                return [ 'valid' => true, 'status' => 'expired', 'expires' => 1234567890, 'reason' => 'expired' ];
            }
        );
        $notice->register();
        $output = $this->capture( $notice );
        // The output should contain a form or link to dismiss, with a nonce
        $this->assertStringContainsString( 'nonce', $output );
    }

    public function testNoticeIsNotShownWhenAlreadyDismissed(): void
    {
        $GLOBALS['__wp_user_meta']['aied_pro_lapse_dismissed'] = [ 1 => [ 'expired|1234567890' => 1 ] ];
        $notice = new LapseNotice(
            static function (): array {
                return [ 'valid' => true, 'status' => 'expired', 'expires' => 1234567890, 'reason' => 'expired' ];
            }
        );
        $notice->register();
        $output = $this->capture( $notice );
        $this->assertStringNotContainsString( 'Your licence has ended', $output );
    }

    public function testNoticeIsShownAgainForDifferentLapseKey(): void
    {
        $GLOBALS['__wp_user_meta']['aied_pro_lapse_dismissed'] = [ 1 => [ 'expired|1234567890' => 1 ] ];
        $notice = new LapseNotice(
            static function (): array {
                return [ 'valid' => true, 'status' => 'expired', 'expires' => 9999999999, 'reason' => 'expired' ];
            }
        );
        $notice->register();
        $output = $this->capture( $notice );
        $this->assertStringContainsString( 'Your licence has ended', $output );
    }

    public function testNoticeIsNotShownOnNonAdminPages(): void
    {
        $GLOBALS['__wp_is_admin'] = false;
        $notice = new LapseNotice(
            static function (): array {
                return [ 'valid' => true, 'status' => 'expired', 'expires' => 1234567890, 'reason' => 'expired' ];
            }
        );
        $notice->register();
        $output = $this->capture( $notice );
        $this->assertStringNotContainsString( 'Your licence has ended', $output );
    }

    public function testNoticeIsNotShownForUsersWithoutManageOptions(): void
    {
        $GLOBALS['__wp_can'] = false;
        $notice = new LapseNotice(
            static function (): array {
                return [ 'valid' => true, 'status' => 'expired', 'expires' => 1234567890, 'reason' => 'expired' ];
            }
        );
        $notice->register();
        $output = $this->capture( $notice );
        $this->assertStringNotContainsString( 'Your licence has ended', $output );
    }

    private function expiredNotice(): LapseNotice
    {
        return new LapseNotice(
            static fn (): array => [ 'valid' => true, 'status' => 'expired', 'expires' => 1234567890, 'reason' => 'expired' ],
            static function (): void {
            }
        );
    }

    public function testFollowingTheDismissLinkHidesThisLapseForThatUserOnly(): void
    {
        $notice = $this->expiredNotice();
        $this->assertStringContainsString( 'Your licence has ended', $this->capture( $notice ) );

        $_GET     = [ 'action' => 'aied_pro_dismiss_lapse_notice', 'lapse_key' => 'expired|1234567890', '_wpnonce' => 'nonce-for-aied_pro_dismiss_lapse_notice' ];
        $_REQUEST = $_GET;
        $notice->handleDismiss();

        $this->assertStringNotContainsString( 'Your licence has ended', $this->capture( $notice ) );
        $this->assertStringContainsString( 'tab=pro', $GLOBALS['__wp_redirects'][0] );

        // another user still sees it
        $GLOBALS['__wp_current_user_id'] = 2;
        $this->assertStringContainsString( 'Your licence has ended', $this->capture( $notice ) );
    }

    public function testADifferentLapseShowsTheNoticeAgainAfterADismissal(): void
    {
        $_GET     = [ 'lapse_key' => 'expired|1234567890', '_wpnonce' => 'nonce-for-aied_pro_dismiss_lapse_notice' ];
        $_REQUEST = $_GET;
        $this->expiredNotice()->handleDismiss();

        $later = new LapseNotice( static fn (): array => [ 'valid' => true, 'status' => 'expired', 'expires' => 1999999999, 'reason' => 'expired' ] );
        $this->assertStringContainsString( 'Your licence has ended', $this->capture( $later ) );
    }

    public function testDismissalNeedsTheNonceAndTheCapability(): void
    {
        $_GET     = [ 'lapse_key' => 'expired|1234567890', '_wpnonce' => 'forged' ];
        $_REQUEST = $_GET;
        try {
            $this->expiredNotice()->handleDismiss();
            $this->fail( 'a forged nonce must be refused' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'check_admin_referer', $e->getMessage() );
        }

        $GLOBALS['__wp_can'] = false;
        $this->expectException( \RuntimeException::class );
        $this->expiredNotice()->handleDismiss();
    }

    public function testTheRenewLinkNeverPointsAtAMalformedAddress(): void
    {
        $output = $this->capture( $this->expiredNotice() );
        $this->assertStringContainsString( 'href="https://divi5lab.com/account/licenses"', $output );
    }
}
