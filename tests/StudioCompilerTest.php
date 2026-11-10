<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\StudioCompiler;
use AiEditorDivi5\WP\StudioSlots;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/BlockAttrs.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageResolver.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DesignVariety.php';
require_once __DIR__ . '/../wp-plugin/src/LoopAdvisory.php';
require_once __DIR__ . '/../wp-plugin/src/PageTitle.php';
require_once __DIR__ . '/../wp-plugin/src/StudioLinks.php';
require_once __DIR__ . '/../wp-plugin/src/StudioSlots.php';
require_once __DIR__ . '/../wp-plugin/src/StudioBrief.php';
require_once __DIR__ . '/../wp-plugin/src/StudioCompiler.php';

/** Studio compiler, plan 1 task 6: brief in, valid Divi markup and an honest report out. */
class StudioCompilerTest extends TestCase
{
    /** The id of a recipe's first headline slot. */
    private static function headline( string $recipe ): string
    {
        foreach ( StudioSlots::extract( (string) \AiEditorDivi5\WP\SectionRecipes::rawMarkup( $recipe ) ) as $s ) {
            if ( 'headline' === $s['role'] ) {
                return $s['id'];
            }
        }
        self::fail( "$recipe has no headline slot" );
    }

    /** @return array<string,mixed> */
    private static function brief( string $language = 'editorial' ): array
    {
        return [
            'title'    => 'Acme plumbing',
            'goal'     => 'book',
            'language' => $language,
            'sections' => [
                [
                    'recipe'   => 'hero-cta',
                    'slots'    => [ 'heading.1' => 'Fix leaks fast', 'button.0.text' => 'Book now', 'button.0.link' => '/contact/' ],
                    'pictures' => [ 'section.0.background' => [ 'pack' => 'hero-blue-1' ] ],
                ],
                [ 'recipe' => 'card-grid-3', 'slots' => [ self::headline( 'card-grid-3' ) => 'What we do' ] ],
                [ 'recipe' => 'faq-accordion', 'slots' => [ self::headline( 'faq-accordion' ) => 'Questions' ] ],
                [ 'recipe' => 'pricing-three-tier', 'slots' => [ self::headline( 'pricing-three-tier' ) => 'Prices' ] ],
                [ 'recipe' => 'testimonials-avatar-grid', 'slots' => [ self::headline( 'testimonials-avatar-grid' ) => 'Kind words' ] ],
                [ 'recipe' => 'cta-split-form', 'slots' => [ self::headline( 'cta-split-form' ) => 'Talk to us' ] ],
            ],
        ];
    }

    public function testSameBriefSameBytes(): void
    {
        $a = StudioCompiler::compile( self::brief() );
        $b = StudioCompiler::compile( self::brief() );
        $this->assertTrue( $a['ok'], json_encode( [ $a['problems'], $a['violations'] ] ) );
        $this->assertSame( $a, $b );
        $this->assertStringContainsString( 'Fix leaks fast', (string) $a['markup'] );
    }

    /**
     * Found by the live gate (make verify-studio, Ruling 13): 62 of the 75 recipes keep raw HTML ("<p>...</p>") inside block
     * attribute JSON, and for a user without unfiltered_html WordPress runs kses over the saved content, whose
     * wp_pre_kses_less_than turns a block comment that holds a "<" before its first ">" into visible text
     * ("&lt;!-- wp:divi/text {&quot;content..."): the module is gone and the page shows its code. The compiler therefore
     * writes block attributes the way WordPress's own serializer does (serialize_block_attributes: "<", ">", "&" and "--"
     * as \u escapes), which changes the bytes and never the values.
     */
    public function testBlockAttributesAreWrittenAsWordPressSerializesThem(): void
    {
        $opener = '/<!--\s+wp:[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*\s+(\{(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+\})\s+\/?-->/s';
        foreach ( \AiEditorDivi5\WP\SectionRecipes::names() as $recipe ) {
            $c = StudioCompiler::compile( [ 'title' => 'T', 'goal' => 'book', 'language' => 'editorial', 'sections' => [ [ 'recipe' => $recipe ] ] ] );
            $this->assertTrue( $c['ok'], $recipe . ' ' . json_encode( [ $c['problems'], $c['violations'] ] ) );
            $raw = (string) \AiEditorDivi5\WP\SectionRecipes::recipe( $recipe, 'editorial' );
            preg_match_all( $opener, (string) $c['markup'], $got );
            preg_match_all( $opener, $raw, $want );
            $this->assertNotEmpty( $got[1], $recipe );
            $this->assertCount( count( $want[1] ), $got[1], $recipe . ': every block is still there' );
            foreach ( $got[1] as $i => $json ) {
                $this->assertDoesNotMatchRegularExpression( '/[<>&]|--/', $json, $recipe . ' block ' . $i . ' carries a raw < > & or -- in its attributes' );
                $this->assertSame( json_decode( $want[1][ $i ], true ), json_decode( $json, true ), $recipe . ' block ' . $i . ': the values are unchanged' );
            }
        }
    }

    public function testLanguageChangesTheLook(): void
    {
        $a = StudioCompiler::compile( self::brief( 'editorial' ) );
        $b = StudioCompiler::compile( self::brief( 'bold-vibrant' ) );
        $this->assertTrue( $a['ok'] && $b['ok'] );
        $this->assertNotSame( $a['markup'], $b['markup'] );
    }

    public function testBriefIsMuchSmallerThanMarkup(): void
    {
        $brief  = self::brief();
        $r      = StudioCompiler::compile( $brief );
        $ratio  = strlen( (string) $r['markup'] ) / strlen( (string) json_encode( $brief ) );
        $this->assertGreaterThanOrEqual( 10, $ratio, sprintf( 'brief %d B, markup %d B, ratio %.1f', strlen( (string) json_encode( $brief ) ), strlen( (string) $r['markup'] ), $ratio ) );
    }

    public function testUnfilledIsListedAndDoesNotBlock(): void
    {
        $r = StudioCompiler::compile( self::brief() );
        $this->assertTrue( $r['ok'] );
        $this->assertNotSame( [], $r['unfilled'] );
        $keys = array_map( static fn ( array $u ): string => $u['section'] . ':' . $u['slot'], $r['unfilled'] );
        $this->assertNotContains( '0:heading.1', $keys, 'a filled slot is not unfilled' );
        $this->assertContains( '0:heading.0', $keys );
        foreach ( $r['unfilled'] as $u ) {
            $this->assertSame( [ 'section', 'recipe', 'slot', 'role' ], array_keys( $u ) );
            $this->assertNotSame( '', $u['role'] );
        }
    }

    public function testMissingPageWarning(): void
    {
        $seen = [];
        $r    = StudioCompiler::compile( self::brief(), static function ( string $path ) use ( &$seen ): bool {
            $seen[] = $path;

            return false;
        }, 'https://example.com' );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [ '/contact/' ], $seen );
        $this->assertSame( [ [ 'section' => 0, 'slot' => 'button.0.link', 'code' => 'LINK_PAGE_MISSING' ] ], array_map( static fn ( array $w ): array => array_diff_key( $w, [ 'message' => 1 ] ), $r['warnings'] ) );
        $found = StudioCompiler::compile( self::brief(), static fn ( string $path ): bool => true, 'https://example.com' );
        $this->assertSame( [], $found['warnings'] );
        $none = StudioCompiler::compile( self::brief() );
        $this->assertSame( [], $none['warnings'], 'without a page lookup nothing is checked' );
    }

    public function testInvalidBriefReturnsProblemsAndNoMarkup(): void
    {
        $brief                          = self::brief();
        $brief['sections'][0]['recipe'] = 'nope';
        $r                              = StudioCompiler::compile( $brief );
        $this->assertFalse( $r['ok'] );
        $this->assertNull( $r['markup'] );
        $this->assertSame( 'UNKNOWN_RECIPE', $r['problems'][0]['code'] );
        $this->assertSame( [], $r['violations'] );
    }

    public function testKitDefaultsToLanguageKitAndExplicitKitWins(): void
    {
        $language = DesignLanguages::get( 'editorial' );
        $this->assertIsString( $language['kit'] );
        $default  = StudioCompiler::compile( self::brief() );
        $explicit = StudioCompiler::compile( [ 'kit' => $language['kit'] ] + self::brief() );
        $this->assertSame( $default['markup'], $explicit['markup'] );
        $other = StudioCompiler::compile( [ 'kit' => 'heavy-block' ] + self::brief() );
        $this->assertTrue( $other['ok'] );
        $this->assertNotSame( $default['markup'], $other['markup'] );
    }

    public function testSameLoopRecipeTwiceReportsDuplicateLoopId(): void
    {
        $brief             = self::brief();
        $brief['sections'] = [ [ 'recipe' => 'loop-posts-cards' ], [ 'recipe' => 'loop-posts-cards' ] ];
        $r                 = StudioCompiler::compile( $brief );
        $this->assertTrue( $r['ok'], json_encode( [ $r['problems'], $r['violations'] ] ) );
        $this->assertArrayHasKey( 'loops', $r['advice'] );
        $this->assertNotSame( [], $r['advice']['loops']['duplicate_loop_id'] );
        $once = StudioCompiler::compile( [ 'sections' => [ [ 'recipe' => 'loop-posts-cards' ] ] ] + $brief );
        $this->assertSame( [], $once['advice']['loops']['duplicate_loop_id'] ?? [] );
    }

    public function testBackToBackSameLayoutGivesVarietyAdvice(): void
    {
        $brief             = self::brief();
        $brief['sections'] = [ [ 'recipe' => 'card-grid-3' ], [ 'recipe' => 'card-grid-3' ], [ 'recipe' => 'card-grid-3' ] ];
        $r                 = StudioCompiler::compile( $brief );
        $this->assertTrue( $r['ok'] );
        $this->assertArrayHasKey( 'design_variety', $r['advice'] );
        $varied = StudioCompiler::compile( self::brief() );
        $this->assertArrayNotHasKey( 'design_variety', $varied['advice'] );
    }

    public function testAnythingButAnObjectIsAProblemNotAnError(): void
    {
        foreach ( [ 'x', null, 7, [ 'a', 'b' ], new \stdClass() ] as $in ) {
            $r = StudioCompiler::compile( $in );
            $this->assertFalse( $r['ok'] );
            $this->assertNull( $r['markup'] );
            $this->assertSame( 'NOT_AN_OBJECT', $r['problems'][0]['code'] );
        }
    }

    public function testResultShape(): void
    {
        $r = StudioCompiler::compile( self::brief() );
        $this->assertSame( [ 'ok', 'markup', 'problems', 'violations', 'unfilled', 'refused', 'warnings', 'advice' ], array_keys( $r ) );
        $this->assertSame( [], $r['refused'] );
        $this->assertSame( [], $r['violations'] );
        $slotIds = array_column( StudioSlots::extract( (string) \AiEditorDivi5\WP\SectionRecipes::rawMarkup( 'hero-cta' ) ), 'id' );
        $this->assertContains( 'heading.1', $slotIds );
    }
}
