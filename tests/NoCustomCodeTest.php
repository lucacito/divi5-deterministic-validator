<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CodePolicy;
use AiEditorDivi5\WP\ImageGuide;
use AiEditorDivi5\WP\LandingGuide;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\SiteGuide;
use AiEditorDivi5\WP\StyleGuide;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/SiteGuide.php';
require_once __DIR__ . '/../wp-plugin/src/LandingGuide.php';
require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/ImageGuide.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';

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
        $this->assertNoTeaching( $rel, (string) file_get_contents( self::ROOT . '/' . $rel ) );
    }

    /** What the AI is actually handed (guides and every recipe), not just the source text. */
    public function testTheServedGuidesAndRecipesTeachNoCustomCss(): void
    {
        $GLOBALS['__wp_filters'] = [];
        $served = [
            'StyleGuide::markdown()'   => StyleGuide::markdown(),
            'SiteGuide::markdown()'    => SiteGuide::markdown(),
            'LandingGuide::markdown()' => LandingGuide::markdown(),
            'ImageGuide::markdown()'   => ImageGuide::markdown(),
            'SectionRecipes::catalog()' => SectionRecipes::catalog(),
        ];
        foreach ( SectionRecipes::names() as $name ) {
            $served[ 'SectionRecipes::recipe(' . $name . ')' ] = (string) SectionRecipes::recipe( $name );
        }
        $this->assertGreaterThanOrEqual( 17, count( $served ) );
        foreach ( $served as $label => $text ) {
            $this->assertNotSame( '', $text, $label );
            $this->assertNoTeaching( $label, $text );
        }
    }

    private function assertNoTeaching( string $label, string $text ): void
    {
        foreach ( self::TEACHING as $needle ) {
            $this->assertFalse( stripos( $text, $needle ) !== false, "{$label} mentions '{$needle}' (custom CSS must not be taught or shipped)" );
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
        $source = (string) file_get_contents( self::ROOT . '/src/StyleGuide.php' );
        $served = StyleGuide::markdown();
        foreach ( [ 'source' => $source, 'served' => $served ] as $where => $text ) {
            $this->assertStringContainsString( 'Never write CSS, JavaScript or PHP code', $text, $where );
            $this->assertStringContainsString( 'CUSTOM_CSS_NOT_ALLOWED', $text, $where );
        }
        $this->assertStringContainsString( '## No custom code', $served );
        $this->assertStringNotContainsString( 'Per-module styling', $served );
        $this->assertStringNotContainsString( 'Additional CSS', $served );
    }

    /** Every PHP file the plugin ships outside the validator copy. @return list<string> */
    private function shippedPhpFiles(): array
    {
        return array_merge(
            glob( self::ROOT . '/src/*.php' ) ?: [],
            [ self::ROOT . '/jhmg-ai-editor-for-divi-5.php', self::ROOT . '/uninstall.php' ]
        );
    }

    /**
     * Banned code in PHP source: comments are dropped with the tokenizer (never with a regex, which a `//`
     * inside a string would fool), then the remaining code is scanned.
     *
     * @return list<string> the banned calls / names found
     */
    private function bannedWrites( string $php ): array
    {
        $code = '';
        foreach ( token_get_all( $php ) as $token ) {
            if ( is_array( $token ) ) {
                if ( $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT ) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }
        $banned = '/\b(?:wp_update_custom_css_post|wp_get_custom_css_post|file_put_contents|fwrite|fputs|eval|assert|create_function|shell_exec|passthru|proc_open|popen)\s*\(|[\'"]custom_css[\'"]/i';
        preg_match_all( $banned, $code, $m );

        return $m[0];
    }

    public function testNoWritePathTouchesCssOrPhpFilesOrOptions(): void
    {
        foreach ( $this->shippedPhpFiles() as $f ) {
            $this->assertFileExists( $f );
            $this->assertSame( [], $this->bannedWrites( (string) file_get_contents( $f ) ), basename( $f ) );
        }
    }

    public function testThePlantedViolationsAreCaughtByTheScans(): void
    {
        // The same helper the real scan uses, fed planted PHP source.
        $this->assertNotSame( [], $this->bannedWrites( "<?php \$u = 'http://x'; file_put_contents(\$a, \$b);" ), 'code after a // inside a string' );
        $this->assertNotSame( [], $this->bannedWrites( "<?php // fine\n eval(\$x);" ), 'code after a comment line' );
        $this->assertNotSame( [], $this->bannedWrites( "<?php update_option('custom_css', 1);" ), 'the custom_css name' );
        $this->assertSame( [], $this->bannedWrites( "<?php // file_put_contents(\$a);\n\$x = 1;" ), 'a commented-out call' );
        $this->assertSame( [], $this->bannedWrites( "<?php /** Never call eval( or file_put_contents( here. */ \$x = 1;" ), 'a docblock mention' );

        // And the teaching scan: planted text is flagged.
        $this->assertTrue( stripos( 'css.desktop.value.freeForm = "x"', 'freeForm' ) !== false );
    }
}
