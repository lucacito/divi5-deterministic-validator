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
        $this->assertFalse( Plugin::bootWith( '4.4.0', static fn ( string $c ): bool => false ) );
    }

    public function testACompatibleFreePluginBoots(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.4.0', static fn (): bool => true ) );
    }
}
