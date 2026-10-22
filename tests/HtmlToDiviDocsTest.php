<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/**
 * html_to_divi phase 2 carries colors, fonts and spacing, so no place that describes the tool may still say styles are not converted.
 *
 * `wp-plugin/readme.txt` is EXCLUDED on purpose: it still says so on lines 82, 246 and 302 until the owner applies the release-time
 * wording proposed in `docs/release-4.14.0-draft.md` (the readme, the plugin header and the zip are changed only at a release, never by
 * a feature branch). When the owner has applied it, add `wp-plugin/readme.txt` to this list.
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

    public function testTheListedFilesExistAndTheReadmeIsLeftOutOnPurpose(): void
    {
        $this->assertNotContains( 'wp-plugin/readme.txt', self::FILES );
        foreach ( self::FILES as $file ) {
            $this->assertFileExists( __DIR__ . '/../' . $file );
        }
    }

    /** The release draft names the three readme lines the owner has to change, so they cannot be forgotten. */
    public function testTheReleaseDraftListsTheReadmeLinesStillToChange(): void
    {
        $draft  = (string) file_get_contents( __DIR__ . '/../docs/release-4.14.0-draft.md' );
        $readme = file( __DIR__ . '/../wp-plugin/readme.txt' ) ?: [];
        $stale  = [];
        foreach ( $readme as $i => $line ) {
            if ( false !== stripos( $line, 'not converted yet' ) ) {
                $stale[] = $i + 1;
            }
        }
        // Either the readme is already updated (nothing stale) or every stale line number is named in the draft.
        foreach ( $stale as $n ) {
            $this->assertStringContainsString( 'line ' . $n, $draft, 'readme.txt line ' . $n . ' says styles are not converted and the release draft does not list it' );
        }
    }
}
