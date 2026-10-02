<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ProSafetyScanner.php';

/**
 * The add-on is not on WordPress.org, but its promises are the same: it never runs code it did not ship,
 * never writes users, plugins or Divi presets, and only talks to the licence host, from one file.
 */
class ProSafetyTest extends TestCase
{
    private const ROOT          = __DIR__ . '/..';
    private const ALLOWED_HOSTS = [ 'divi5lab.com' ];
    private const REMOTE_FILE   = 'src/Licensing/LicenseClient.php';

    /** @return array<string,array{string}> */
    public static function files(): array
    {
        $out = [
            'jhmg-ai-editor-for-divi-5-pro.php' => [ 'jhmg-ai-editor-for-divi-5-pro.php' ],
            'uninstall.php'                     => [ 'uninstall.php' ],
        ];
        $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::ROOT . '/src', \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( 'php' === $file->getExtension() ) {
                $rel         = 'src/' . substr( $file->getPathname(), strlen( self::ROOT . '/src/' ) );
                $out[ $rel ] = [ $rel ];
            }
        }
        ksort( $out );

        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'files' )]
    public function testNoShippedFileUsesABannedConstruct( string $rel ): void
    {
        $this->assertSame( [], \AiEditorDivi5\Pro\Tests\ProSafetyScanner::violations( (string) file_get_contents( self::ROOT . '/' . $rel ) ), $rel );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'files' )]
    public function testNoShippedFileMentionsAnotherHost( string $rel ): void
    {
        $hosts = ProSafetyScanner::hosts( (string) file_get_contents( self::ROOT . '/' . $rel ) );
        $this->assertSame( [], array_values( array_diff( $hosts, self::ALLOWED_HOSTS ) ), $rel );
    }

    public function testOnlyTheLicenceClientCanCallOut(): void
    {
        $callers = [];
        foreach ( array_keys( self::files() ) as $rel ) {
            if ( ProSafetyScanner::usesRemoteHttp( (string) file_get_contents( self::ROOT . '/' . $rel ) ) ) {
                $callers[] = $rel;
            }
        }
        $this->assertSame( [ self::REMOTE_FILE ], $callers );
    }

    public function testTheScannerCatchesPlantedViolations(): void
    {
        $this->assertSame( [ 'eval' ], ProSafetyScanner::violations( '<?php eval( $x );' ) );
        $this->assertContains( 'exec family', ProSafetyScanner::violations( '<?php shell_exec( "ls" );' ) );
        $this->assertContains( 'exec family', ProSafetyScanner::violations( '<?php system ( $c );' ) );
        $this->assertContains( 'unserialize', ProSafetyScanner::violations( '<?php unserialize( $d );' ) );
        $this->assertContains( 'write users', ProSafetyScanner::violations( '<?php wp_insert_user( [] );' ) );
        $this->assertContains( 'manage plugins', ProSafetyScanner::violations( '<?php deactivate_plugins( "x" );' ) );
        $this->assertContains( 'divi presets', ProSafetyScanner::violations( "<?php update_option( 'et_divi_builder_global_presets_d5', [] );" ) );
        $this->assertContains( 'divi presets', ProSafetyScanner::violations( '<?php GlobalPreset::save( 1 );' ) );
    }

    public function testTheScannerIgnoresCommentsAndCleanCode(): void
    {
        $this->assertSame( [], ProSafetyScanner::violations( "<?php\n// never eval( anything )\n/** Not exec( this ) either */\n\$a = 1;" ) );
        $this->assertSame( [], ProSafetyScanner::violations( '<?php register_activation_hook( __FILE__, "x" ); $this->evaluate( 1 );' ) );
    }

    public function testTheHostScannerFindsLiteralsOnly(): void
    {
        $this->assertSame( [ 'evil.example' ], ProSafetyScanner::hosts( "<?php \$u = 'https://evil.example/x'; // https://ignored.example" ) );
        $this->assertSame( [ 'divi5lab.com' ], ProSafetyScanner::hosts( '<?php $u = "https://divi5lab.com/api";' ) );
        $this->assertTrue( ProSafetyScanner::usesRemoteHttp( '<?php wp_remote_get( $u );' ) );
        $this->assertTrue( ProSafetyScanner::usesRemoteHttp( '<?php curl_exec( $h );' ) );
        $this->assertFalse( ProSafetyScanner::usesRemoteHttp( '<?php // wp_remote_get( $u );' ) );
    }
}
