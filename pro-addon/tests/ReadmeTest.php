<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Tools;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/** The add-on is not on WordPress.org, but it makes remote calls, so its own readme must say so, and say only true things. */
class ReadmeTest extends TestCase
{
    private function readme(): string
    {
        $path = dirname( AIED_PRO_FILE ) . '/readme.txt';
        $this->assertFileExists( $path );

        return (string) file_get_contents( $path );
    }

    public function testTheStableTagIsTheCurrentVersion(): void
    {
        $this->assertSame( 1, preg_match( '/^Stable tag:\s*(\S+)\s*$/m', $this->readme(), $m ) );
        $this->assertSame( AIED_PRO_VERSION, $m[1] );
    }

    public function testItDisclosesTheLicenceServerAndWhatIsSent(): void
    {
        $r = $this->readme();
        $this->assertStringContainsString( '== External services ==', $r );
        $this->assertStringContainsString( 'divi5lab.com', $r );
        foreach ( [ 'licence key', 'site URL', 'plugin version', 'update' ] as $what ) {
            $this->assertStringContainsString( $what, $r, "the disclosure must say it sends/uses: {$what}" );
        }
    }

    public function testItNamesEveryShippedTool(): void
    {
        $r = $this->readme();
        foreach ( Tools::all() as $tool ) {
            $this->assertStringContainsString( '`' . $tool->name() . '`', $r, $tool->name() );
        }
    }

    public function testTheHeaderDescriptionClaimsNothingThatIsNotShipped(): void
    {
        $this->assertSame( 1, preg_match( '/^\s*\*\s*Description:\s*(.+)$/m', (string) file_get_contents( AIED_PRO_FILE ), $m ) );
        foreach ( [ 'Theme Builder', 'site-wide', 'audit', 'find and replace', 'find/replace' ] as $unshipped ) {
            $this->assertStringNotContainsStringIgnoringCase( $unshipped, $m[1], "not shipped yet: {$unshipped}" );
        }
        $this->assertStringContainsString( 'free plugin', $m[1] );
    }

    public function testTheZipScriptShipsTheReadme(): void
    {
        $this->assertStringContainsString( 'readme.txt', (string) file_get_contents( dirname( AIED_PRO_FILE ) . '/../scripts/build-pro-zip.sh' ) );
    }
}
