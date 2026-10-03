<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Compat.php';

/**
 * Source-level guards for the Theme Builder tools: nothing writes Divi's template or layout meta directly (only Divi's
 * own store function and the free plugin's history write path do), and every free-plugin class the add-on references is
 * one the compat check requires, so a missing class turns the Pro tools off instead of failing at runtime.
 */
class ThemeBuilderGuardTest extends TestCase
{
    private static function root(): string
    {
        return dirname( __DIR__ ) . '/src';
    }

    private static function code( string $php ): string
    {
        $out = '';
        foreach ( token_get_all( $php ) as $tok ) {
            if ( is_array( $tok ) ) {
                if ( in_array( $tok[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
                    continue;
                }
                $out .= $tok[1];
            } else {
                $out .= $tok;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function themeBuilderFiles(): array
    {
        $files = glob( self::root() . '/ThemeBuilder/*.php' ) ?: [];
        foreach ( [ 'Kinds/ThemeLayoutKind.php', 'Kinds/ThemeTemplateKind.php', 'Tools/ListThemeLayouts.php', 'Tools/UpdateThemeLayout.php', 'Tools/EditThemeLayout.php', 'Tools/ManageThemeTemplate.php' ] as $f ) {
            $files[] = self::root() . '/' . $f;
        }

        return $files;
    }

    public function testTheThemeBuilderFilesAreFound(): void
    {
        $files = self::themeBuilderFiles();
        $this->assertGreaterThanOrEqual( 10, count( $files ) );
        foreach ( $files as $f ) {
            $this->assertFileExists( $f );
        }
    }

    public function testNoThemeBuilderCodeWritesPostMetaDirectly(): void
    {
        foreach ( self::themeBuilderFiles() as $file ) {
            $code = self::code( (string) file_get_contents( $file ) );
            $this->assertSame( 0, preg_match( '/\b(?:add_post_meta|update_post_meta|delete_post_meta)\s*\(/i', $code ), basename( $file ) . ' writes post meta directly; only Divi\'s own store function may write a template' );
        }
    }

    public function testEveryFreePluginClassTheAddOnReferencesIsRequiredByTheCompatCheck(): void
    {
        $found = [];
        foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root(), \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
            if ( 'php' !== $file->getExtension() ) {
                continue;
            }
            $code = self::code( (string) file_get_contents( $file->getPathname() ) );
            if ( preg_match_all( '/AiEditorDivi5\\\\WP\\\\([A-Za-z0-9_]+)/', $code, $m ) ) {
                foreach ( $m[1] as $name ) {
                    $found[ 'AiEditorDivi5\\WP\\' . $name ] = $file->getFilename();
                }
            }
        }
        $this->assertNotSame( [], $found, 'the scan found no references at all, so it is broken' );
        foreach ( $found as $class => $where ) {
            $this->assertContains( $class, \AiEditorDivi5\Pro\Compat::REQUIRED_CLASSES, "{$where} references {$class}, which Compat::REQUIRED_CLASSES does not list" );
        }
    }
}
