<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

/** The Pro suite runs on the main suite's WordPress shims plus a handful of its own. */
class HarnessTest extends TestCase
{
    public function testTheSharedWordPressShimsAreLoaded(): void
    {
        $this->assertTrue( function_exists( 'get_option' ) );
        $this->assertTrue( function_exists( 'apply_filters' ) );
        $this->assertTrue( class_exists( \WP_REST_Response::class ) );
        $this->assertTrue( function_exists( 'current_user_can' ) );
    }

    public function testTheProConstantsAreDefined(): void
    {
        $this->assertSame( 'ai-editor-divi5-pro', AIED_PRO_PRODUCT );
        $this->assertFileExists( dirname( AIED_PRO_FILE ) . '/phpunit.xml' );
        $this->assertNotSame( '', AIED_PRO_VERSION );
    }

    public function testTheProAdminShimsExist(): void
    {
        foreach ( [ 'is_admin', 'admin_url', 'add_query_arg', 'sanitize_key', 'wp_nonce_field', 'check_admin_referer', 'wp_die', 'wp_safe_redirect' ] as $fn ) {
            $this->assertTrue( function_exists( $fn ), $fn );
        }
        $this->assertSame( 'https://example.com/wp-admin/admin.php?page=x&tab=pro', add_query_arg( [ 'page' => 'x', 'tab' => 'pro' ], admin_url( 'admin.php' ) ) );
    }
}
