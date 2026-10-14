<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/** Booting against an unusable free plugin registers nothing and warns, in the admin only. */
class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_filters']  = [];
        $GLOBALS['__wp_is_admin'] = false;
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_filters']  = [];
        $GLOBALS['__wp_is_admin'] = false;
    }

    public function testAMissingFreePluginRegistersNothingOutsideTheAdmin(): void
    {
        $this->assertFalse( Plugin::bootWith( null, static fn (): bool => true ) );
        $this->assertSame( [], $GLOBALS['__wp_filters'] );
    }

    public function testAMissingFreePluginShowsOneEscapedNoticeInTheAdmin(): void
    {
        $GLOBALS['__wp_is_admin'] = true;
        $this->assertFalse( Plugin::bootWith( null, static fn (): bool => true ) );
        $this->assertCount( 1, $GLOBALS['__wp_filters']['admin_notices'] );
        $this->assertSame( [ 'admin_notices' ], array_keys( $GLOBALS['__wp_filters'] ), 'nothing else is registered' );

        ob_start();
        $GLOBALS['__wp_filters']['admin_notices'][0]();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString( 'notice-error', $html );
        $this->assertStringContainsString( 'JHMG AI Editor for Divi 5 Pro', $html );
        $this->assertStringContainsString( 'free plugin', $html );
    }

    public function testATooOldFreePluginDoesNotBoot(): void
    {
        $GLOBALS['__wp_is_admin'] = true;
        $this->assertFalse( Plugin::bootWith( '4.3.0', static fn (): bool => true ) );
        $this->assertSame( [ 'admin_notices' ], array_keys( $GLOBALS['__wp_filters'] ) );
    }

    public function testAFreePluginMissingAClassDoesNotBoot(): void
    {
        $this->assertFalse( Plugin::bootWith( '4.9.0', static fn ( string $c ): bool => false ) );
    }

    public function testACompatibleFreePluginBoots(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.9.0', static fn (): bool => true ) );
    }

    public function testACompatibleBootRegistersTheLicenceAndUpdateHooks(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.9.0', static fn (): bool => true ) );
        foreach ( [ 'pre_set_site_transient_update_plugins', 'admin_init', 'admin_notices' ] as $hook ) {
            $this->assertArrayHasKey( $hook, $GLOBALS['__wp_filters'], $hook );
        }
    }

    public function testTheAdminInitRefreshDoesNotCallTheServerWithoutAKey(): void
    {
        $GLOBALS['__wp_options']  = [];
        $GLOBALS['__wp_http_log'] = [];
        Plugin::bootWith( '4.9.0', static fn (): bool => true );
        foreach ( $GLOBALS['__wp_filters']['admin_init'] as $fn ) {
            $fn();
        }
        $this->assertSame( [], $GLOBALS['__wp_http_log'], 'no licence key means no remote call' );
    }

    public function testWhileLockedNoProToolIsAdvertised(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.9.0', static fn (): bool => true ) );
        $this->assertArrayHasKey( 'jhmg_aied_mcp_tools', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'jhmg_aied_mcp_call', $GLOBALS['__wp_filters'] );
        $this->assertSame( [ [ 'name' => 'get_page_layout' ] ], apply_filters( 'jhmg_aied_mcp_tools', [ [ 'name' => 'get_page_layout' ] ] ), 'no Pro tool while locked' );
    }

    public function testWhileUnlockedEveryProToolIsAdvertised(): void
    {
        $GLOBALS['__wp_options'] = [ 'aied_pro_premium_unlocked' => 1 ];
        \AiEditorDivi5\Pro\Licensing::resetForTests();
        $this->assertTrue( Plugin::bootWith( '4.9.0', static fn (): bool => true ) );
        $names = array_column( apply_filters( 'jhmg_aied_mcp_tools', [ [ 'name' => 'get_page_layout' ] ] ), 'name' );
        $this->assertSame( 'get_page_layout', $names[0], 'built-ins stay first' );
        $this->assertCount( 1 + count( \AiEditorDivi5\Pro\Tools::all() ), $names, 'the built-in plus every Pro tool' );
        $this->assertContains( 'undo_batch', $names );
        $GLOBALS['__wp_options'] = [];
        \AiEditorDivi5\Pro\Licensing::resetForTests();
    }

    public function testACompatibleBootRegistersTheCodeProposalsPanel(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.9.0', static fn (): bool => true ) );
        $this->assertArrayHasKey( 'admin_post_aied_pro_delete_proposal', $GLOBALS['__wp_filters'] );
    }

    public function testACompatibleBootRegistersTheProAdminTab(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.9.0', static fn (): bool => true ) );
        $this->assertSame( [ 'pro' => 'Pro' ], apply_filters( 'jhmg_aied_admin_tabs', [] ) );
        $this->assertArrayHasKey( 'admin_post_aied_pro_activate', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'admin_post_aied_pro_deactivate', $GLOBALS['__wp_filters'] );
    }

    public function testACompatibleBootCreatesTheBatchTableOnInitAndDropsItWithAMultisiteSite(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.9.0', static fn (): bool => true ) );
        $this->assertArrayHasKey( 'init', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'wpmu_drop_tables', $GLOBALS['__wp_filters'] );
    }

    public function testACompatibleBootRegistersTheRecentChangesPanel(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.9.0', static fn (): bool => true ) );
        $this->assertArrayHasKey( 'admin_post_aied_pro_undo_batch', $GLOBALS['__wp_filters'] );
    }
}
