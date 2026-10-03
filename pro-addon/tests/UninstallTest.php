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

    public function testItWipesTheLocalStateDropsTheBatchTableAndNeverCallsTheServer(): void
    {
        require_once __DIR__ . '/BatchStubWpdb.php';
        $GLOBALS['wpdb']         = new BatchStubWpdb();
        $GLOBALS['__wp_options'] = [
            'aied_pro_license_key'      => 'KEY',
            'aied_pro_license_state'    => [ 'status' => 'active' ],
            'aied_pro_premium_unlocked' => 1,
            'aied_pro_update_blocked'   => '1',
            'aied_pro_batches_db_version' => 1,
            'unrelated_option'          => 'keep',
        ];
        $GLOBALS['__wp_transients'] = [ 'aied_pro_refresh_backoff' => 1, 'aied_pro_batches_backoff' => 1 ];
        $GLOBALS['__wp_http_log']   = [];
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', 'jhmg-ai-editor-for-divi-5-pro/jhmg-ai-editor-for-divi-5-pro.php' );
        }
        require dirname( AIED_PRO_FILE ) . '/uninstall.php';

        $this->assertSame( [ 'unrelated_option' => 'keep' ], $GLOBALS['__wp_options'] );
        $this->assertSame( [], $GLOBALS['__wp_transients'] );
        $this->assertSame( [], $GLOBALS['__wp_http_log'] );
        $this->assertContains( [ 'query', 'DROP TABLE IF EXISTS wp_aied_pro_batches' ], $GLOBALS['wpdb']->calls );
        $GLOBALS['wpdb'] = null;
    }
}
