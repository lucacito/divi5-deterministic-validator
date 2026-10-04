<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verify no price literals (dollars amounts) appear in the Pro add-on plugin code or readme.
 * All prices come from the layoutlab config/pricing.json, never hardcoded here.
 */
class NoPriceLiteralsTest extends TestCase
{
    public function testNoPriceLiteralsInSrcFiles(): void
    {
        $srcDir = __DIR__ . '/../src';
        $pricePatterns = [
            '\\$\\s?4900',  // Personal: $49
            '\\$\\s?9900',  // Freelancer: $99
            '\\$\\s?14900', // Agency: $149
            '\\$\\s?44900', // Lifetime: $449
            '\\b49\\b',     // 49 dollar amount (without $ prefix, can be price)
            '\\b99\\b',     // 99
            '\\b149\\b',    // 149
            '\\b449\\b',    // 449
        ];

        $foundMatches = [];

        // Scan all PHP files in src/
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $srcDir, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $files as $file ) {
            if ( $file->getExtension() !== 'php' ) {
                continue;
            }

            $content = file_get_contents( $file->getRealPath() );
            $relPath = str_replace( $srcDir, '', $file->getRealPath() );

            foreach ( $pricePatterns as $pattern ) {
                if ( preg_match( "/$pattern/", $content ) ) {
                    $foundMatches[] = "File: $relPath - Pattern: $pattern";
                }
            }
        }

        $this->assertEmpty( $foundMatches, "Found price literals in pro-addon/src:\n" . implode( "\n", $foundMatches ) );
    }

    public function testNoPriceLiteralsInReadme(): void
    {
        $readmeFile = __DIR__ . '/../readme.txt';
        $this->assertFileExists( $readmeFile );

        $content = file_get_contents( $readmeFile );

        $pricePatterns = [
            '\\$\\s?4900',
            '\\$\\s?9900',
            '\\$\\s?14900',
            '\\$\\s?44900',
        ];

        $foundMatches = [];

        foreach ( $pricePatterns as $pattern ) {
            if ( preg_match( "/$pattern/", $content ) ) {
                $foundMatches[] = "Pattern: $pattern";
            }
        }

        $this->assertEmpty( $foundMatches, "Found price literals in pro-addon/readme.txt:\n" . implode( "\n", $foundMatches ) );
    }
}
