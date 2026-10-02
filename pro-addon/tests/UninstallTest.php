<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

class UninstallTest extends TestCase
{
    public function testItExitsUnlessWordPressIsUninstallingThePlugin(): void
    {
        $this->assertStringContainsString( "if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {", (string) file_get_contents( dirname( AIED_PRO_FILE ) . '/uninstall.php' ) );
    }

    public function testItWipesTheLocalLicenceStateAndNeverCallsTheServer(): void
    {
        $GLOBALS['__wp_options'] = [
            'aied_pro_license_key'      => 'KEY',
            'aied_pro_license_state'    => [ 'status' => 'active' ],
            'aied_pro_premium_unlocked' => 1,
            'aied_pro_update_blocked'   => '1',
            'unrelated_option'          => 'keep',
        ];
        $GLOBALS['__wp_http_log'] = [];
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', 'jhmg-ai-editor-for-divi-5-pro/jhmg-ai-editor-for-divi-5-pro.php' );
        }
        require dirname( AIED_PRO_FILE ) . '/uninstall.php';

        $this->assertSame( [ 'unrelated_option' => 'keep' ], $GLOBALS['__wp_options'] );
        $this->assertSame( [], $GLOBALS['__wp_http_log'] );
    }
}
