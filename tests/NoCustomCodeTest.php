<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CodePolicy;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';

/**
 * WordPress.org review (2026-10): the plugin must not let users or an AI save custom CSS, JavaScript or PHP.
 * Nothing the plugin teaches the AI, and nothing it ships, may use or recommend custom code.
 */
class NoCustomCodeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../wp-plugin';

    /** Fields and phrases that teach or use custom CSS in a layout (case-insensitive). */
    private const TEACHING = [ 'freeForm', 'mainElement', 'css.{bp}', 'css.desktop', 'backdrop-filter', '@keyframes' ];

    /** @return array<string,array{string}> */
    public static function shippedText(): array
    {
        $out = [];
        foreach ( glob( self::ROOT . '/src/*.php' ) ?: [] as $f ) {
            if ( in_array( basename( $f ), [ 'CodePolicy.php' ], true ) ) {
                continue; // the policy has to name the fields it refuses
            }
            $out[ 'src/' . basename( $f ) ] = [ 'src/' . basename( $f ) ];
        }
        foreach ( [ 'readme.txt', 'data/section-recipes.json', '../docs/STYLE.md' ] as $f ) {
            $out[ $f ] = [ $f ];
        }

        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'shippedText' )]
    public function testNothingTeachesOrShipsCustomCss( string $rel ): void
    {
        $text = (string) file_get_contents( self::ROOT . '/' . $rel );
        foreach ( self::TEACHING as $needle ) {
            $this->assertFalse( stripos( $text, $needle ) !== false, "{$rel} mentions '{$needle}' (custom CSS must not be taught or shipped)" );
        }
    }

    public function testEveryBundledRecipeIsFreeOfCustomCode(): void
    {
        $recipes = json_decode( (string) file_get_contents( self::ROOT . '/data/section-recipes.json' ), true );
        $this->assertIsArray( $recipes );
        $this->assertNotEmpty( $recipes );
        foreach ( $recipes as $recipe ) {
            $this->assertSame( [], array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), CodePolicy::violations( (string) $recipe['markup'] ) ), (string) $recipe['name'] );
        }
    }

    public function testTheGuideTellsTheAiNotToWriteCustomCode(): void
    {
        $guide = (string) file_get_contents( self::ROOT . '/src/StyleGuide.php' );
        $this->assertStringContainsString( 'Never write CSS, JavaScript or PHP code', $guide );
        $this->assertStringContainsString( 'CUSTOM_CSS_NOT_ALLOWED', $guide );
    }

    public function testNoWritePathTouchesCssOrPhpFilesOrOptions(): void
    {
        $banned = '/\b(?:wp_update_custom_css_post|wp_get_custom_css_post|file_put_contents|fwrite|fputs|eval|assert|create_function|shell_exec|passthru|proc_open|popen)\s*\(|[\'"]custom_css[\'"]/i';
        foreach ( glob( self::ROOT . '/src/*.php' ) ?: [] as $f ) {
            $this->assertSame( 0, preg_match( $banned, (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents( $f ) ) ), basename( $f ) );
        }
    }

    public function testThePlantedViolationsAreCaughtByTheScans(): void
    {
        $this->assertTrue( stripos( 'css.desktop.value.freeForm = "x"', 'freeForm' ) !== false );
        $this->assertSame( 1, preg_match( '/\bfile_put_contents\s*\(/i', 'file_put_contents($a, $b);' ) );
    }
}
