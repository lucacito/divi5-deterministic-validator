<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Plugin Check reports every array_is_list() call because WordPress itself only added a polyfill in 6.5. The function is built into
 * PHP 8.1, and Pro does not load any of its code below PHP 8.1, so it cannot fatal on WordPress 6.0 to 6.8. This pins both halves.
 */
class PhpFloorTest extends TestCase
{
    private function read( string $file ): string
    {
        return (string) file_get_contents( dirname( AIED_PRO_FILE ) . '/' . $file );
    }

    public function testProDeclaresPhp81AndStopsBeforeLoadingAnythingBelowIt(): void
    {
        $main = $this->read( 'jhmg-ai-editor-for-divi-5-pro.php' );
        $this->assertMatchesRegularExpression( '/^ \* Requires PHP:\s+8\.1\s*$/m', $main );
        $this->assertMatchesRegularExpression( '/^Requires PHP:\s+8\.1\s*$/m', $this->read( 'readme.txt' ) );
        $this->assertStringContainsString( "define( 'AIED_PRO_MIN_PHP', '8.1' );", $main );
        $gate = strpos( $main, 'version_compare( PHP_VERSION, AIED_PRO_MIN_PHP' );
        $load = strpos( $main, "require_once __DIR__ . '/src/autoload.php'" );
        $this->assertNotFalse( $gate );
        $this->assertNotFalse( $load );
        $this->assertLessThan( $load, $gate, 'the PHP check comes before any code is loaded' );
        $between = substr( $main, $gate, $load - $gate );
        $this->assertStringContainsString( 'return;', $between, 'and it stops the file' );
    }

    public function testEveryNewerThanWordPress60FunctionTheScanFoundIsBuiltIntoPhp81(): void
    {
        $this->assertTrue( function_exists( 'array_is_list' ), 'PHP 8.1 has it natively' );
        $this->assertTrue( version_compare( PHP_VERSION, '8.1', '>=' ) );
        $uses = 0;
        $it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( AIED_PRO_FILE ) . '/src', \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( 'php' === $file->getExtension() ) {
                $uses += substr_count( (string) file_get_contents( $file->getPathname() ), 'array_is_list(' );
            }
        }
        $this->assertGreaterThan( 0, $uses, 'the scan still finds the calls this test explains' );
    }
}
