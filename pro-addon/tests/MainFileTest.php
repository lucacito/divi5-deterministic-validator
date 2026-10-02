<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

class MainFileTest extends TestCase
{
    private function header(): string
    {
        return (string) file_get_contents( AIED_PRO_FILE );
    }

    public function testTheHeaderAndTheConstantAgreeOnTheVersion(): void
    {
        $h = $this->header();
        $this->assertSame( 1, preg_match( "/define\(\s*'AIED_PRO_VERSION',\s*'([^']+)'\s*\);/", $h, $m ) );
        $this->assertMatchesRegularExpression( '/^\s*\*\s*Version:\s+' . preg_quote( $m[1], '/' ) . '\s*$/m', $h );
    }

    public function testTheIdentityIsExactlyAsSpecified(): void
    {
        $h = $this->header();
        $this->assertMatchesRegularExpression( '/^\s*\*\s*Plugin Name:\s+JHMG AI Editor for Divi 5 Pro\s*$/m', $h );
        $this->assertMatchesRegularExpression( '/^\s*\*\s*Text Domain:\s+jhmg-ai-editor-for-divi-5-pro\s*$/m', $h );
        $this->assertMatchesRegularExpression( '/^\s*\*\s*Requires PHP:\s+8\.1\s*$/m', $h );
        $this->assertStringContainsString( "define( 'AIED_PRO_PRODUCT', 'ai-editor-divi5-pro' );", $h );
        $this->assertSame( 'jhmg-ai-editor-for-divi-5-pro.php', basename( AIED_PRO_FILE ) );
    }

    public function testItBootsAfterTheFreePluginHasLoaded(): void
    {
        $this->assertStringContainsString( "add_action( 'plugins_loaded', [ AiEditorDivi5\\Pro\\Plugin::class, 'boot' ], 20 );", $this->header() );
    }

    public function testItExitsWhenLoadedOutsideWordPress(): void
    {
        $this->assertStringContainsString( "if ( ! defined( 'ABSPATH' ) ) {", $this->header() );
    }
}
