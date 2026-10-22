<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/**
 * html_to_divi phase 2 carries colors, fonts and spacing, so no place that describes the tool may still say styles are not converted.
 *
 * `wp-plugin/readme.txt` is checked separately: since the 4.14.0 release commit its feature list and FAQ no longer say so, and the one
 * remaining mention is the historical 4.10.0 changelog entry, which was true when written and stays as history.
 */
final class HtmlToDiviDocsTest extends TestCase
{
    private const OLD_CLAIMS = [ 'not converted yet', 'Styles (colors, fonts) are NOT converted', 'styles are not converted' ];

    private const FILES = [
        'CLAUDE.md',
        'docs/html-to-divi-workflow.md',
        'wp-plugin/src/McpHandler.php',
        'wp-plugin/src/SiteGuide.php',
        'wp-plugin/src/HtmlToDivi.php',
    ];

    public function testNoClaimSaysStylesAreNotConverted(): void
    {
        foreach ( self::FILES as $file ) {
            $text = file_get_contents( __DIR__ . '/../' . $file );
            $this->assertIsString( $text, $file );
            foreach ( self::OLD_CLAIMS as $claim ) {
                $this->assertStringNotContainsStringIgnoringCase( $claim, $text, $file . ' still says: ' . $claim );
            }
        }
    }

    public function testTheListedFilesExist(): void
    {
        foreach ( self::FILES as $file ) {
            $this->assertFileExists( __DIR__ . '/../' . $file );
        }
    }

    /** The readme says styles are not converted only inside the 4.10.0 changelog entry (history), nowhere else. */
    public function testTheReadmeOnlyKeepsTheClaimInTheTenZeroHistory(): void
    {
        $readme  = file( __DIR__ . '/../wp-plugin/readme.txt' ) ?: [];
        $version = '';
        $stale   = 0;
        foreach ( $readme as $line ) {
            if ( 1 === preg_match( '/^= (\d+\.\d+\.\d+) =/', $line, $m ) ) {
                $version = $m[1];
            }
            if ( false !== stripos( $line, 'not converted yet' ) ) {
                ++$stale;
                $this->assertSame( '4.10.0', $version, 'readme.txt says styles are not converted outside the 4.10.0 history entry: ' . trim( $line ) );
            }
        }
        $this->assertLessThanOrEqual( 1, $stale );
    }
}
