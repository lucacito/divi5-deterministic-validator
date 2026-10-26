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
        $found  = [];
        $design = [];
        $switch = [];
        foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root(), \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
            if ( 'php' !== $file->getExtension() ) {
                continue;
            }
            $code = self::code( (string) file_get_contents( $file->getPathname() ) );
            if ( preg_match_all( '/AiEditorDivi5\\\\WP\\\\([A-Za-z0-9_]+)/', $code, $m ) ) {
                $isDesign = str_contains( str_replace( '\\', '/', $file->getPath() ), '/src/Design' );
                $isSwitch = 'SwitchDesign.php' === $file->getFilename();
                foreach ( $m[1] as $name ) {
                    if ( $isSwitch ) {
                        $switch[ 'AiEditorDivi5\\WP\\' . $name ] = $file->getFilename();
                    } elseif ( $isDesign ) {
                        $design[ 'AiEditorDivi5\\WP\\' . $name ] = $file->getFilename();
                    } else {
                        $found[ 'AiEditorDivi5\\WP\\' . $name ] = $file->getFilename();
                    }
                }
            }
        }
        $this->assertNotSame( [], $found, 'the scan found no references at all, so it is broken' );
        foreach ( $found as $class => $where ) {
            $this->assertContains( $class, \AiEditorDivi5\Pro\Compat::REQUIRED_CLASSES, "{$where} references {$class}, which Compat::REQUIRED_CLASSES does not list" );
        }

        // The design switch (src/Design) needs free classes that older free versions lack (the type and rhythm adapters are not in
        // every published free version), so they are NOT in REQUIRED_CLASSES (that would switch every Pro tool off on a free plugin
        // that still serves them); the switch tool refuses at run time instead (plan 2026-10-25-pro-design-switch, Task 8).
        // This list is deliberate: a new free class in Design\* needs an edit here and a row in FreeApiCompatTest.
        $allowed = [ 'AiEditorDivi5\\WP\\DesignLanguages', 'AiEditorDivi5\\WP\\LanguageAdapters', 'AiEditorDivi5\\WP\\StyleKits' ];
        // Tasks 3 and 4 (Expectations; ContextTable, RoleReverse): the one-block pipeline call, the free decoder and the colour/font token resolver, all pinned in FreeApiCompatTest.
        $allowed = [ ...$allowed, 'AiEditorDivi5\\WP\\BlockAttrs', 'AiEditorDivi5\\WP\\SectionRecipes', 'AiEditorDivi5\\WP\\RecipeTokens' ];
        foreach ( $design as $class => $where ) {
            $this->assertContains( $class, $allowed, "{$where} (Design) references {$class}: add it to this list and to FreeApiCompatTest, or use a class Compat::REQUIRED_CLASSES lists" );
        }

        // The switch_design tool (Task 8) is the run-time guard for those classes: every free class it or the engine uses is
        // either in Compat::REQUIRED_CLASSES (every Pro tool needs it) or in SwitchDesign::REQUIRED_FREE (only this tool needs it).
        $this->assertNotSame( [], $switch, 'the scan found the switch tool' );
        $guarded = [ ...\AiEditorDivi5\Pro\Compat::REQUIRED_CLASSES, ...\AiEditorDivi5\Pro\Tools\SwitchDesign::REQUIRED_FREE ];
        foreach ( [ ...$switch, ...$design ] as $class => $where ) {
            $this->assertContains( $class, $guarded, "{$where} references {$class}, which neither Compat::REQUIRED_CLASSES nor SwitchDesign::REQUIRED_FREE lists" );
        }
    }

    /** @return array<string,array{string,bool}> */
    public static function aliasForms(): array
    {
        return [
            'a namespace import'              => [ 'use AiEditorDivi5\\WP;', true ],
            'a leading backslash'             => [ 'use \\AiEditorDivi5\\WP;', true ],
            'an alias of the namespace'       => [ 'use AiEditorDivi5\\WP as Free;', true ],
            'a group use'                     => [ 'use AiEditorDivi5\\WP\\{HistoryService, EditTarget};', true ],
            'a normal class import'           => [ 'use AiEditorDivi5\\WP\\HistoryService;', false ],
            'the Pro namespace'               => [ 'use AiEditorDivi5\\Pro\\Compat;', false ],
            'a longer namespace name'         => [ 'use AiEditorDivi5\\WPX;', false ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'aliasForms' )]
    public function testTheAliasScanRecognisesTheFormsThatHideAFreeClassName( string $code, bool $hidden ): void
    {
        $this->assertSame( $hidden, self::hidesFreeClasses( $code ) );
    }

    /** The scan above sees only fully written names, so a namespace import, alias or group use would let a class slip past it. */
    public function testNoAddOnFileHidesAFreePluginClassBehindANamespaceImportAliasOrGroupUse(): void
    {
        $scanned = 0;
        foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root(), \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
            if ( 'php' !== $file->getExtension() ) {
                continue;
            }
            ++$scanned;
            $this->assertFalse( self::hidesFreeClasses( self::code( (string) file_get_contents( $file->getPathname() ) ) ), $file->getFilename() . ' imports the free plugin namespace as a whole; write each class with its full name so Compat::REQUIRED_CLASSES can see it' );
        }
        $this->assertGreaterThan( 20, $scanned );
    }

    private static function hidesFreeClasses( string $code ): bool
    {
        return 1 === preg_match( '/\buse\s+\\\\?AiEditorDivi5\\\\WP(?:\s*;|\s+as\b|\\\\\{)/i', $code );
    }
}
