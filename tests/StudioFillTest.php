<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\BlockAttrs;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
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
require_once __DIR__ . '/../wp-plugin/src/StudioLinks.php';
require_once __DIR__ . '/../wp-plugin/src/StudioSlots.php';

/**
 * Studio compiler, plan 1 task 4: filling slots. A supplied value is data: escaped, never a token, a marker or markup;
 * an untouched block keeps its exact bytes.
 */
class StudioFillTest extends TestCase
{
    private static function raw( string $recipe = 'hero-cta' ): string
    {
        return (string) SectionRecipes::rawMarkup( $recipe );
    }

    /** The slot's current sample after a fill (extract reads the filled markup back). */
    private static function sample( string $markup, string $id ): ?string
    {
        foreach ( StudioSlots::extract( $markup ) as $s ) {
            if ( $s['id'] === $id ) {
                return $s['sample'];
            }
        }

        return null;
    }

    /**
     * The recipes store equivalent JSON in two escape styles (`<p>` raw or `<p>`, `\"` or `"`), so a
     * re-encoded block is not byte-identical to its stored form (426 of them differ). What fill relies on: the decoded value
     * is identical, the re-encoded text is still read back as one block, and it holds no raw `<`, `>`, `&` or `--`.
     * Blocks fill does not touch are never re-encoded (testFillNothingIsByteIdentical).
     */
    public function testReEncodingKeepsEveryRecipeBlockSemantically(): void
    {
        $bad = [];
        foreach ( SectionRecipes::names() as $name ) {
            preg_match_all( BlockAttrs::BLOCK, self::raw( $name ), $m, PREG_SET_ORDER );
            foreach ( $m as $i => $block ) {
                $attrs   = BlockAttrs::decode( $block[3] );
                $encoded = null === $attrs ? '' : RecipeTokens::encodeJson( $attrs );
                $again   = '' === $encoded ? null : BlockAttrs::decode( $encoded );
                $reread  = 1 === preg_match( BlockAttrs::BLOCK, $block[1] . $encoded . $block[4], $one ) && $one[0] === $block[1] . $encoded . $block[4];
                if ( null === $attrs || null === $again || $again != $attrs || ! $reread || 1 === preg_match( '/[<>&]|--/', $encoded ) ) {
                    $bad[] = "$name block $i {$block[2]}";
                }
            }
        }
        $this->assertSame( [], array_slice( $bad, 0, 10 ), count( $bad ) . ' blocks change meaning when re-encoded' );
    }

    public function testFillNothingIsByteIdentical(): void
    {
        foreach ( [ 'hero-cta', 'pricing-three-tier', 'faq-accordion', 'newsletter-social' ] as $name ) {
            $raw = self::raw( $name );
            $out = StudioSlots::fill( $raw, [] );
            $this->assertSame( $raw, $out['markup'], $name );
            $this->assertSame( [], $out['filled'] );
            $this->assertSame( array_column( StudioSlots::extract( $raw ), 'id' ), $out['unfilled'] );
        }
    }

    public function testTextIsEscaped(): void
    {
        $out = StudioSlots::fill( self::raw(), [ 'heading.1' => '<b>x</b> & "q"' ] );
        $this->assertSame( [ 'heading.1' ], $out['filled'] );
        $this->assertSame( '&lt;b&gt;x&lt;/b&gt; &amp; &quot;q&quot;', self::sample( $out['markup'], 'heading.1' ) );
    }

    public function testTokensAndMarkersAreInert(): void
    {
        $hostile = [ '{{aied:color:bg}}', '{{aied:image:hero-blue-1}}', '{"aied":{"kit":"control"}}', '$variable({})$' ];
        foreach ( $hostile as $value ) {
            $out      = StudioSlots::fill( self::raw(), [ 'heading.1' => $value, 'button.0.text' => $value ] );
            $resolved = SectionRecipes::resolveRaw( $out['markup'], 'editorial' );
            $this->assertNotNull( $resolved, $value );
            $this->assertSame( [], KitResolver::leftovers( $resolved ), $value );
            $expected = str_replace( [ '{', '$' ], [ '&#123;', '&#36;' ], htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
            $this->assertSame( $expected, self::sample( $resolved, 'heading.1' ), $value );
            $this->assertSame( $expected, self::sample( $resolved, 'button.0.text' ), $value );
        }
    }

    public function testEmptyAndWhitespaceCountAsUnfilled(): void
    {
        $raw = self::raw();
        foreach ( [ '', '   ', "\n\t", "\u{00A0}" ] as $value ) {
            $out = StudioSlots::fill( $raw, [ 'heading.1' => $value ] );
            $this->assertSame( $raw, $out['markup'] );
            $this->assertSame( [], $out['filled'] );
            $this->assertContains( 'heading.1', $out['unfilled'] );
        }
    }

    public function testUnknownSlotReported(): void
    {
        $out = StudioSlots::fill( self::raw(), [ 'heading.99' => 'x', '7' => 'y' ] );
        $this->assertSame( [ 'heading.99', '7' ], $out['unknown'] );
        $this->assertSame( self::raw(), $out['markup'] );
    }

    public function testLinkAddedAndRefusedLinkKeepsSample(): void
    {
        $ok = StudioSlots::fill( self::raw(), [ 'button.0.link' => ' https://example.com/a ' ] );
        $this->assertSame( [ 'button.0.link' ], $ok['filled'] );
        $this->assertSame( 'https://example.com/a', self::sample( $ok['markup'], 'button.0.link' ) );

        $bad = StudioSlots::fill( self::raw(), [ 'button.0.link' => 'javascript:alert(1)' ] );
        $this->assertSame( [ 'button.0.link' => 'LINK_SCHEME_NOT_ALLOWED' ], $bad['refused'] );
        $this->assertSame( [], $bad['filled'] );
        $this->assertContains( 'button.0.link', $bad['unfilled'] );
        $this->assertSame( self::raw(), $bad['markup'] );
    }

    public function testPictureByPackAndByUrl(): void
    {
        $pack = StudioSlots::fill( self::raw(), [ 'section.0.background' => [ 'pack' => 'card-blue-1' ] ] );
        $this->assertSame( '{{aied:image:card-blue-1}}', self::sample( $pack['markup'], 'section.0.background' ) );

        // A literal address is no image token, so the filled markup has no picture slot left to read back: check the value.
        $url = StudioSlots::fill( self::raw(), [ 'section.0.background' => [ 'url' => 'https://example.com/a.jpg' ] ] );
        $this->assertSame( [ 'section.0.background' ], $url['filled'] );
        $this->assertStringContainsString( '"url":"https://example.com/a.jpg"', $url['markup'] );
        $this->assertStringNotContainsString( '{{aied:image:hero-blue-1}}', $url['markup'] );

        $path = StudioSlots::fill( self::raw(), [ 'section.0.background' => [ 'url' => '/wp-content/uploads/a.jpg' ] ] );
        $this->assertStringContainsString( '"url":"/wp-content/uploads/a.jpg"', $path['markup'] );

        foreach ( [
            [ 'url' => 'javascript:x' ], [ 'url' => 'mailto:a@b.co' ], [ 'url' => '#top' ], [ 'pack' => 'Bad Token!' ], [ 'pack' => '' ], 'plain string', [ 'x' => 'y' ],
        ] as $bad ) {
            $out = StudioSlots::fill( self::raw(), [ 'section.0.background' => $bad ] );
            $this->assertArrayHasKey( 'section.0.background', $out['refused'], json_encode( $bad ) );
            $this->assertSame( self::raw(), $out['markup'] );
        }
    }

    public function testControlCharactersAndUnsafePictureAddressesAreRefusedAtFill(): void
    {
        $out = StudioSlots::fill( self::raw(), [ 'heading.1' => "a b" ] );
        $this->assertSame( [ 'heading.1' => 'TEXT_INVALID' ], $out['refused'] );
        $this->assertSame( self::raw(), $out['markup'] );
        $pic = StudioSlots::fill( self::raw(), [ 'section.0.background' => [ 'url' => 'https://a.com/x).png' ] ] );
        $this->assertSame( [ 'section.0.background' => 'PICTURE_INVALID' ], $pic['refused'] );
    }

    public function testNonStringTextIsRefused(): void
    {
        $out = StudioSlots::fill( self::raw(), [ 'heading.1' => [ 'x' ] ] );
        $this->assertSame( [ 'heading.1' => 'WRONG_TYPE' ], $out['refused'] );
    }

    public function testFilledRichSlotDropsInlineMarkup(): void
    {
        foreach ( SectionRecipes::names() as $name ) {
            foreach ( StudioSlots::extract( self::raw( $name ) ) as $s ) {
                if ( $s['rich'] ) {
                    $out = StudioSlots::fill( self::raw( $name ), [ $s['id'] => 'Plain words' ] );
                    $now = array_values( array_filter( StudioSlots::extract( $out['markup'] ), static fn ( array $x ): bool => $x['id'] === $s['id'] ) );
                    $this->assertSame( 'Plain words', $now[0]['sample'], "$name {$s['id']}" );
                    $this->assertFalse( $now[0]['rich'], "$name {$s['id']}" );

                    return;
                }
            }
        }
        $this->fail( 'no rich slot found in the library' );
    }

    public function testOnlyTheSuppliedElementChanges(): void
    {
        foreach ( SectionRecipes::names() as $name ) {
            $slots = StudioSlots::extract( self::raw( $name ) );
            foreach ( $slots as $s ) {
                if ( 'text.' === substr( $s['id'], 0, 5 ) && 1 === $s['element'] && ! $s['rich'] ) {
                    $before = array_column( $slots, 'sample', 'id' );
                    $out    = StudioSlots::fill( self::raw( $name ), [ $s['id'] => 'Changed paragraph' ] );
                    $after  = array_column( StudioSlots::extract( $out['markup'] ), 'sample', 'id' );
                    $before[ $s['id'] ] = 'Changed paragraph';
                    $this->assertSame( $before, $after, "$name {$s['id']}" );
                    $this->assertSame(
                        array_column( $slots, 'role', 'id' ),
                        array_column( StudioSlots::extract( $out['markup'] ), 'role', 'id' ),
                        'the element keeps its tag'
                    );
                    preg_match_all( BlockAttrs::BLOCK, self::raw( $name ), $a );
                    preg_match_all( BlockAttrs::BLOCK, $out['markup'], $b );
                    $this->assertCount( 1, array_diff_assoc( $a[0], $b[0] ), 'exactly one block changed' );

                    return;
                }
            }
        }
        $this->fail( 'no two-paragraph text found in the library' );
    }

    public function testWholeFieldSlotIsReplaced(): void
    {
        foreach ( StudioSlots::extract( self::raw( 'card-grid-3' ) ) as $s ) {
            if ( StudioSlots::WHOLE === $s['element'] ) {
                $out = StudioSlots::fill( self::raw( 'card-grid-3' ), [ $s['id'] => 'Short & sweet' ] );
                $this->assertSame( 'Short & sweet', self::sample( $out['markup'], $s['id'] ) );

                return;
            }
        }
        $this->fail( 'card-grid-3 has no whole-field slot' );
    }
}
