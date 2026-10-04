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

    /** @return array<string,array{0:string}> */
    public static function files(): array
    {
        $out = [ 'readme.txt' => [ __DIR__ . '/../readme.txt' ], 'README.md' => [ __DIR__ . '/../README.md' ] ];
        $it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( __DIR__ . '/../src' ) );
        foreach ( $it as $f ) {
            if ( $f->isFile() && 'php' === $f->getExtension() ) {
                $out[ 'src/' . substr( $f->getPathname(), strlen( realpath( __DIR__ . '/../src' ) ) + 1 ) ] = [ $f->getPathname() ];
            }
        }

        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'files' )]
    public function testNoPriceIsTypedAnywhereInTheAddOn( string $file ): void
    {
        $content = (string) file_get_contents( $file );
        // A dollar sign followed by a 2+ digit amount ($49, $ 149, $4900), or one of the price numbers next to a price word.
        $this->assertSame( 0, preg_match( '/\$\s?\d{2,}/', $content ), "{$file} types a dollar price" );
        $this->assertSame( 0, preg_match( '/\b(49|99|149|449)\s?(\/\s?(yr|year)|per year|USD|a year)/i', $content ), "{$file} types a yearly price" );
    }

    public function testTheMatcherCatchesWhatItIsMeantTo(): void
    {
        $this->assertSame( 1, preg_match( '/\$\s?\d{2,}/', 'Personal is $49/year' ) );
        $this->assertSame( 1, preg_match( '/\b(49|99|149|449)\s?(\/\s?(yr|year)|per year|USD|a year)/i', 'only 149 per year' ) );
        $this->assertSame( 0, preg_match( '/\$\s?\d{2,}/', "preg_replace( '/x/', '\$1', \$s ) and \$wpdb" ) );
    }
}
