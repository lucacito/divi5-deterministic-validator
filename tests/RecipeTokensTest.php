<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';

/**
 * Recipe tokens are resolved when a recipe is SERVED, so the AI always gets hard values and a saved
 * page never contains a token.
 */
class RecipeTokensTest extends TestCase
{
    private function wrap( string $markup ): string
    {
        return '<!-- wp:divi/placeholder -->' . $markup . '<!-- /wp:divi/placeholder -->';
    }

    public function testResolvesColorAndFontTokens(): void
    {
        $lang = DesignLanguages::get( 'editorial' );
        $this->assertNotNull( $lang );
        $in = '{{aied:color:bg}}|{{aied:color:surface}}|{{aied:color:text}}|{{aied:color:muted}}|{{aied:color:accent}}|{{aied:color:accent-text}}|{{aied:color:border}}|{{aied:font:display}}|{{aied:font:body}}|{{aied:font:eyebrow}}';
        $c  = $lang['colors'];
        $f  = $lang['fonts'];
        $this->assertSame(
            implode( '|', [ $c['bg'], $c['surface'], $c['text'], $c['muted'], $c['accent'], $c['accent_text'], $c['border'], $f['display'], $f['body'], $f['eyebrow'] ] ),
            RecipeTokens::resolve( $in, $lang )
        );
        $this->assertSame( 'plain text and {{aied:image:x}} stay', RecipeTokens::resolve( 'plain text and {{aied:image:x}} stay', $lang ) );
    }

    public function testSurfaceGlassFallsBackToSurfaceWhenALanguageHasNone(): void
    {
        $editorial = DesignLanguages::get( 'editorial' );
        $glass     = DesignLanguages::get( 'glass-dark' );
        $this->assertNotNull( $editorial );
        $this->assertNotNull( $glass );
        $this->assertArrayNotHasKey( 'surface_glass', $editorial['colors'] );
        $this->assertSame( $editorial['colors']['surface'], RecipeTokens::resolve( '{{aied:color:surface-glass}}', $editorial ) );
        $this->assertArrayHasKey( 'surface_glass', $glass['colors'] );
        $this->assertSame( $glass['colors']['surface_glass'], RecipeTokens::resolve( '{{aied:color:surface-glass}}', $glass ) );
    }

    public function testUnknownRoleStaysDetectableViaFound(): void
    {
        $lang = DesignLanguages::get( 'editorial' );
        $out  = RecipeTokens::resolve( 'a {{aied:color:nonsense}} b {{aied:font:serif}} c {{aied:color:accent}}', $lang );
        $this->assertSame( [ '{{aied:color:nonsense}}', '{{aied:font:serif}}' ], RecipeTokens::found( $out ) );
        $this->assertSame(
            [ '{{aied:color:bg}}', '{{aied:font:body}}', '{{aied:color:bg}}' ],
            RecipeTokens::found( 'x {{aied:color:bg}} y {{aied:font:body}} {{aied:color:bg}} {{aied:image:hero-blue-1}} {{other}}' ),
            'in order, repeats included, image tokens not counted'
        );
        $this->assertSame( [], RecipeTokens::found( 'nothing here' ) );
    }

    public function testDefaultIsTheEditorialLanguage(): void
    {
        $editorial = DesignLanguages::get( 'editorial' );
        $this->assertNotNull( $editorial );
        $d = RecipeTokens::default();
        $this->assertSame( $editorial['colors'], $d['colors'] );
        $this->assertSame( $editorial['fonts'], $d['fonts'] );
    }

    public function testDefaultLanguageLeavesNoToken(): void
    {
        foreach ( SectionRecipes::names() as $name ) {
            $markup = (string) SectionRecipes::recipe( $name );
            $this->assertSame( [], RecipeTokens::found( $markup ), "$name leaks a token with no language" );
            $this->assertStringNotContainsString( '{{aied:', $markup, $name );
            $result = ( new Validator() )->validateContent( $this->wrap( $markup ) );
            $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
            $this->assertTrue( $result->isValid(), "$name must validate with no language, got: $codes" );
        }
    }

    public function testEveryRecipeValidatesUnderEveryLanguage(): void
    {
        $this->assertNotEmpty( DesignLanguages::ids() );
        foreach ( DesignLanguages::ids() as $lang ) {
            foreach ( SectionRecipes::names() as $name ) {
                $markup = SectionRecipes::recipe( $name, $lang );
                $this->assertNotNull( $markup, "$name under $lang" );
                $this->assertSame( [], RecipeTokens::found( $markup ), "$name under $lang leaks a token" );
                $this->assertStringNotContainsString( '{{aied:', $markup, "$name under $lang" );
                $result = ( new Validator() )->validateContent( $this->wrap( $markup ) );
                $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                $this->assertTrue( $result->isValid(), "$name under $lang must validate, got: $codes" );
            }
        }
    }

    public function testTheLanguageChangesThePilotRecipe(): void
    {
        $glass     = DesignLanguages::get( 'glass-dark' );
        $editorial = DesignLanguages::get( 'editorial' );
        $this->assertNotNull( $glass );
        $this->assertNotNull( $editorial );
        $g = (string) SectionRecipes::recipe( 'hero-cta', 'glass-dark' );
        $e = (string) SectionRecipes::recipe( 'hero-cta', 'editorial' );
        $this->assertStringContainsString( $glass['colors']['accent'], $g );
        $this->assertStringContainsString( $glass['fonts']['display'], $g );
        $this->assertStringContainsString( $editorial['colors']['accent'], $e );
        $this->assertStringNotContainsString( $glass['colors']['accent'], $e );
        $this->assertSame( $e, (string) SectionRecipes::recipe( 'hero-cta' ), 'no language = the default (editorial)' );
    }

    public function testAnUnknownLanguageReturnsNullNeverPartialMarkup(): void
    {
        $this->assertNull( SectionRecipes::recipe( 'hero-cta', 'no-such-language' ) );
        $this->assertNull( SectionRecipes::recipe( 'does-not-exist', 'editorial' ) );
    }

    public function testRawStoredMarkupOfThePilotCarriesTokensAndNoLanguageValues(): void
    {
        $raw = (string) SectionRecipes::rawMarkup( 'hero-cta' );
        $this->assertNotSame( [], RecipeTokens::found( $raw ) );
        foreach ( DesignLanguages::ids() as $id ) {
            $lang = DesignLanguages::get( $id );
            $this->assertStringNotContainsString( (string) $lang['colors']['accent'], $raw, 'a language value is hard-coded in hero-cta' );
        }
    }

    public function testHeroCtaKeepsReadableTextUnderEveryLanguage(): void
    {
        // The card's text colour and its background come from the same language, so each pair must pass WCAG AA.
        foreach ( DesignLanguages::ids() as $id ) {
            $c = (array) ( DesignLanguages::get( $id )['colors'] ?? [] );
            $this->assertGreaterThanOrEqual( 4.5, DesignLanguages::contrast( (string) $c['text'], (string) $c['surface'] ), "$id text on surface" );
            $this->assertGreaterThanOrEqual( 4.5, DesignLanguages::contrast( (string) $c['muted'], (string) $c['surface'] ), "$id muted on surface" );
            $this->assertGreaterThanOrEqual( 4.5, DesignLanguages::contrast( (string) $c['accent_text'], (string) $c['accent'] ), "$id text on accent" );
        }
    }
}
