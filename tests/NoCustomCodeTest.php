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

    /**
     * Files that NAME a needle only to refuse or poison it, never to teach or write it (explicit, one needle each).
     * HtmlCss reads CSS from a page the user supplied and must recognise the backdrop-filter property to mark a colour as
     * unresolved; it never writes it anywhere (only normalised values and marker names leave the class; HtmlCssTest pins that).
     */
    private const NAMED_TO_BE_REFUSED = [ 'src/HtmlCss.php' => [ 'backdrop-filter' ] ];

    #[\PHPUnit\Framework\Attributes\DataProvider( 'shippedText' )]
    public function testNothingTeachesOrShipsCustomCss( string $rel ): void
    {
        $text = (string) file_get_contents( self::ROOT . '/' . $rel );
        foreach ( self::NAMED_TO_BE_REFUSED[ $rel ] ?? [] as $needle ) {
            $text = str_ireplace( $needle, '', $text );
        }
        $this->assertNoTeaching( $rel, $text );
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

    /** The teaching scan: the needles of TEACHING found in a text (case-insensitive). @return list<string> */
    private function teachingHits( string $text ): array
    {
        return array_values( array_filter( self::TEACHING, static fn ( string $needle ): bool => stripos( $text, $needle ) !== false ) );
    }

    private function assertNoTeaching( string $label, string $text ): void
    {
        $this->assertSame( [], $this->teachingHits( $text ), "{$label} teaches or ships custom CSS (a needle was found)" );
    }

    public function testEveryBundledRecipeIsFreeOfCustomCode(): void
    {
        $GLOBALS['__wp_filters'] = [];
        $names = SectionRecipes::names();
        $this->assertGreaterThanOrEqual( 17, count( $names ) );
        foreach ( $names as $name ) {
            // The markup the AI is actually handed: image tokens resolved to site URLs, like SectionRecipes::recipe() serves it.
            $markup = (string) SectionRecipes::recipe( $name );
            $this->assertNotSame( '', $markup, $name );
            $this->assertSame( [], array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), CodePolicy::violations( $markup ) ), $name );
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

        // And the teaching scan (the same helper the real scan uses): planted text is flagged, clean text is not.
        $this->assertNotEmpty( self::TEACHING );
        $this->assertContains( 'freeForm', $this->teachingHits( 'css.desktop.value.freeForm = "x"' ) );
        $this->assertContains( 'mainElement', $this->teachingHits( 'set MAINELEMENT to a selector' ), 'case-insensitive' );
        $this->assertContains( '@keyframes', $this->teachingHits( 'add @keyframes spin' ) );
        $this->assertSame( [], $this->teachingHits( 'Use the module\'s own style settings: colors, spacing and borders.' ) );
    }
}
