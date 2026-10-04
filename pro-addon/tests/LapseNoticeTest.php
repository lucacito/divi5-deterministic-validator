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
        $this->assertStringContainsString( 'is-dismissible', $output );
        $this->assertStringContainsString( 'notice-warning', $output );
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
}
