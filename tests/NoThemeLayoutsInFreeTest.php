<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Header/footer (Theme Builder) editing lives in the separate Pro add-on, never in the WordPress.org
 * plugin. This keeps it from quietly coming back: no file shipped in the free plugin may mention it.
 */
class NoThemeLayoutsInFreeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../wp-plugin';

    /** Matched case-insensitively. */
    private const FORBIDDEN = [
        'ThemeLayouts',
        'list_theme_layouts',
        'listThemeLayouts',
        'theme-layouts',
        'theme_layouts',
        'et_header_layout',
        'et_footer_layout',
        'clearLayoutCaches',
        'Theme Builder',
        'header and footer',
        'header/footer',
    ];

    /** @return array<string,array{string}> */
    public static function files(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::ROOT, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            $rel = substr( $file->getPathname(), strlen( self::ROOT ) + 1 );
            foreach ( [ 'validator/', 'assets/', 'languages/' ] as $skip ) {
                if ( str_starts_with( $rel, $skip ) ) {
                    continue 2;
                }
            }
            if ( ! in_array( $file->getExtension(), [ 'php', 'txt', 'json' ], true ) ) {
                continue;
            }
            $out[ $rel ] = [ $rel ];
        }
        ksort( $out );

        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'files' )]
    public function testFileHasNoThemeLayoutCode( string $rel ): void
    {
        $text = (string) file_get_contents( self::ROOT . '/' . $rel );
        foreach ( self::FORBIDDEN as $needle ) {
            $this->assertFalse( stripos( $text, $needle ) !== false, "{$rel} still mentions '{$needle}' (header/footer editing belongs to the Pro add-on)" );
        }
    }

    public function testTheGuardSeesTheSourceTree(): void
    {
        $this->assertContains( 'src/McpHandler.php', array_keys( self::files() ), 'the file scan must include the plugin source' );
    }
}
