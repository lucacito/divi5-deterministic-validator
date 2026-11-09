<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StudioBrief;
use AiEditorDivi5\WP\StudioSlots;
use PHPUnit\Framework\Attributes\DataProvider;
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
require_once __DIR__ . '/../wp-plugin/src/PageTitle.php';
require_once __DIR__ . '/../wp-plugin/src/StudioLinks.php';
require_once __DIR__ . '/../wp-plugin/src/StudioSlots.php';
require_once __DIR__ . '/../wp-plugin/src/StudioBrief.php';

/** Studio compiler, plan 1 task 5: a brief is checked strictly, with a stable code and a path for every problem. */
class StudioBriefTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function base(): array
    {
        return [
            'title'    => 'Acme plumbing',
            'goal'     => 'book',
            'language' => 'editorial',
            'sections' => [
                [
                    'recipe'   => 'hero-cta',
                    'slots'    => [ 'heading.1' => 'Fix leaks fast', 'button.0.text' => 'Book now', 'button.0.link' => 'https://example.com/book' ],
                    'pictures' => [ 'section.0.background' => [ 'pack' => 'hero-blue-1' ] ],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $brief */
    private static function codes( array $brief ): array
    {
        $r = StudioBrief::check( $brief );

        return array_map( static fn ( array $p ): string => $p['path'] . '=' . $p['code'], $r['problems'] );
    }

    public function testValidBriefIsNormalised(): void
    {
        $brief                                         = self::base();
        $brief['title']                                = '  Acme plumbing  ';
        $brief['sections'][0]['slots']['heading.1']    = '  Fix leaks fast ';
        $brief['sections'][0]['slots']['heading.0']    = '   ';
        $r = StudioBrief::check( $brief );
        $this->assertTrue( $r['ok'], json_encode( $r['problems'] ) );
        $this->assertSame( [], $r['problems'] );
        $this->assertSame( [ 'version' => 1 ] + self::base(), $r['brief'] );
    }

    public function testExplicitKitAndVersion(): void
    {
        $brief = self::base() + [ 'version' => 1, 'kit' => 'quiet-solid' ];
        $r     = StudioBrief::check( $brief );
        $this->assertTrue( $r['ok'], json_encode( $r['problems'] ) );
        $this->assertSame( 'quiet-solid', $r['brief']['kit'] );
    }

    /** @return array<string,array{0:mixed,1:string}> */
    public static function badBriefs(): array
    {
        $b = static fn ( callable $edit ): array => $edit( self::base() );

        return [
            'null'                => [ null, '=NOT_AN_OBJECT' ],
            'number'              => [ 7, '=NOT_AN_OBJECT' ],
            'string'              => [ 'x', '=NOT_AN_OBJECT' ],
            'list'                => [ [ 'a', 'b' ], '=NOT_AN_OBJECT' ],
            'unknown key'         => [ $b( fn ( $x ) => $x + [ 'extra' => 1 ] ), 'extra=UNKNOWN_KEY' ],
            'section extra key'   => [ $b( function ( $x ) { $x['sections'][0]['extra'] = 1; return $x; } ), 'sections.0.extra=UNKNOWN_KEY' ],
            'version'             => [ $b( fn ( $x ) => $x + [ 'version' => 2 ] ), 'version=UNSUPPORTED_VERSION' ],
            'missing title'       => [ $b( function ( $x ) { unset( $x['title'] ); return $x; } ), 'title=MISSING' ],
            'missing goal'        => [ $b( function ( $x ) { unset( $x['goal'] ); return $x; } ), 'goal=MISSING' ],
            'missing language'    => [ $b( function ( $x ) { unset( $x['language'] ); return $x; } ), 'language=MISSING' ],
            'missing sections'    => [ $b( function ( $x ) { unset( $x['sections'] ); return $x; } ), 'sections=MISSING' ],
            'missing recipe'      => [ $b( function ( $x ) { unset( $x['sections'][0]['recipe'] ); return $x; } ), 'sections.0.recipe=MISSING' ],
            'title number'        => [ $b( function ( $x ) { $x['title'] = 5; return $x; } ), 'title=WRONG_TYPE' ],
            'title html'          => [ $b( function ( $x ) { $x['title'] = '<b>x</b>'; return $x; } ), 'title=INVALID_TITLE' ],
            'goal array'          => [ $b( function ( $x ) { $x['goal'] = []; return $x; } ), 'goal=WRONG_TYPE' ],
            'sections string'     => [ $b( function ( $x ) { $x['sections'] = 'x'; return $x; } ), 'sections=WRONG_TYPE' ],
            'sections assoc'      => [ $b( function ( $x ) { $x['sections'] = [ 'a' => $x['sections'][0] ]; return $x; } ), 'sections=WRONG_TYPE' ],
            'section string'      => [ $b( function ( $x ) { $x['sections'][0] = 'x'; return $x; } ), 'sections.0=WRONG_TYPE' ],
            'slots string'        => [ $b( function ( $x ) { $x['sections'][0]['slots'] = 'x'; return $x; } ), 'sections.0.slots=WRONG_TYPE' ],
            'slot number'         => [ $b( function ( $x ) { $x['sections'][0]['slots']['heading.1'] = 5; return $x; } ), 'sections.0.slots.heading.1=WRONG_TYPE' ],
            'picture string'      => [ $b( function ( $x ) { $x['sections'][0]['pictures']['section.0.background'] = 'x'; return $x; } ), 'sections.0.pictures.section.0.background=WRONG_TYPE' ],
            'unknown goal'        => [ $b( function ( $x ) { $x['goal'] = 'win'; return $x; } ), 'goal=UNKNOWN_GOAL' ],
            'unknown language'    => [ $b( function ( $x ) { $x['language'] = 'nope'; return $x; } ), 'language=UNKNOWN_LANGUAGE' ],
            'unknown kit'         => [ $b( fn ( $x ) => $x + [ 'kit' => 'nope' ] ), 'kit=UNKNOWN_KIT' ],
            'unknown recipe'      => [ $b( function ( $x ) { $x['sections'][0]['recipe'] = 'nope'; return $x; } ), 'sections.0.recipe=UNKNOWN_RECIPE' ],
            'no sections'         => [ $b( function ( $x ) { $x['sections'] = []; return $x; } ), 'sections=NO_SECTIONS' ],
            'too many sections'   => [ $b( function ( $x ) { $x['sections'] = array_fill( 0, 21, $x['sections'][0] ); return $x; } ), 'sections=TOO_MANY_SECTIONS' ],
            'unknown slot'        => [ $b( function ( $x ) { $x['sections'][0]['slots']['heading.99'] = 'x'; return $x; } ), 'sections.0.slots.heading.99=UNKNOWN_SLOT' ],
            'picture in slots'    => [ $b( function ( $x ) { $x['sections'][0]['slots']['section.0.background'] = 'x'; return $x; } ), 'sections.0.slots.section.0.background=UNKNOWN_SLOT' ],
            'text in pictures'    => [ $b( function ( $x ) { $x['sections'][0]['pictures']['heading.1'] = [ 'pack' => 'hero-blue-1' ]; return $x; } ), 'sections.0.pictures.heading.1=UNKNOWN_SLOT' ],
            'text too long'       => [ $b( function ( $x ) { $x['sections'][0]['slots']['heading.1'] = str_repeat( 'a', 2001 ); return $x; } ), 'sections.0.slots.heading.1=TEXT_TOO_LONG' ],
            'invalid utf8'        => [ $b( function ( $x ) { $x['sections'][0]['slots']['heading.1'] = "bad \xff text"; return $x; } ), 'sections.0.slots.heading.1=INVALID_UTF8' ],
            'unknown pack image'  => [ $b( function ( $x ) { $x['sections'][0]['pictures']['section.0.background'] = [ 'pack' => 'nope-9' ]; return $x; } ), 'sections.0.pictures.section.0.background=UNKNOWN_PACK_IMAGE' ],
            'picture mailto'      => [ $b( function ( $x ) { $x['sections'][0]['pictures']['section.0.background'] = [ 'url' => 'mailto:a@b.co' ]; return $x; } ), 'sections.0.pictures.section.0.background=PICTURE_INVALID' ],
            'picture userinfo'    => [ $b( function ( $x ) { $x['sections'][0]['pictures']['section.0.background'] = [ 'url' => 'https://u@x.com/a.jpg' ]; return $x; } ), 'sections.0.pictures.section.0.background=LINK_MALFORMED' ],
            'picture both keys'   => [ $b( function ( $x ) { $x['sections'][0]['pictures']['section.0.background'] = [ 'pack' => 'hero-blue-1', 'url' => 'https://x.com/a.jpg' ]; return $x; } ), 'sections.0.pictures.section.0.background=PICTURE_INVALID' ],
            'bad link'            => [ $b( function ( $x ) { $x['sections'][0]['slots']['button.0.link'] = 'javascript:alert(1)'; return $x; } ), 'sections.0.slots.button.0.link=LINK_SCHEME_NOT_ALLOWED' ],
        ];
    }

    #[DataProvider( 'badBriefs' )]
    public function testEveryProblemHasItsCodeAndPath( mixed $brief, string $expected ): void
    {
        $r = StudioBrief::check( $brief );
        $this->assertFalse( $r['ok'] );
        $this->assertNull( $r['brief'] );
        $this->assertContains( $expected, array_map( static fn ( array $p ): string => $p['path'] . '=' . $p['code'], $r['problems'] ) );
        foreach ( $r['problems'] as $p ) {
            $this->assertSame( [ 'path', 'code', 'message' ], array_keys( $p ) );
            $this->assertIsString( $p['message'] );
            $this->assertNotSame( '', $p['message'] );
        }
    }

    public function testUnknownSlotMessageNamesTheRecipesThatHoldIt(): void
    {
        $this->assertSame( [], array_intersect( [ 'hero-cta' ], StudioSlots::recipesWithSlot( 'heading.3' ) ), 'hero-cta must lack heading.3 for this test' );
        $with = StudioSlots::recipesWithSlot( 'heading.3' );
        $this->assertNotSame( [], $with );
        $brief                                          = self::base();
        $brief['sections'][0]['slots']['heading.3']     = 'a fourth thing';
        $r                                              = StudioBrief::check( $brief );
        $msg                                            = $r['problems'][0]['message'];
        $this->assertStringContainsString( 'hero-cta', $msg );
        $this->assertStringContainsString( $with[0], $msg );
        $this->assertStringContainsString( 'heading.1', $msg, 'it lists the recipe\'s own slot ids' );
    }

    public function testUnknownLanguageAndKitMessagesListValidIds(): void
    {
        $brief             = self::base();
        $brief['language'] = 'nope';
        $brief['kit']      = 'nope';
        $msgs              = implode( "\n", array_column( StudioBrief::check( $brief )['problems'], 'message' ) );
        $this->assertStringContainsString( 'editorial', $msgs );
        $this->assertStringContainsString( 'quiet-solid', $msgs );
    }

    public function testMultibyteLengthCountsCharacters(): void
    {
        foreach ( [ str_repeat( 'é', 2000 ), str_repeat( '😀', 2000 ), str_repeat( 'مرحبا', 400 ) ] as $ok ) {
            $brief                                      = self::base();
            $brief['sections'][0]['slots']['heading.1'] = $ok;
            $this->assertTrue( StudioBrief::check( $brief )['ok'], mb_substr( $ok, 0, 5 ) );
        }
        $brief                                      = self::base();
        $brief['sections'][0]['slots']['heading.1'] = str_repeat( 'é', 2001 );
        $this->assertContains( 'sections.0.slots.heading.1=TEXT_TOO_LONG', self::codes( $brief ) );
    }

    /** The first slot with role "figure" of a block type: [recipe, slot id]. */
    private static function figureSlot( string $block ): array
    {
        foreach ( SectionRecipes::names() as $name ) {
            foreach ( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $name ) ) as $s ) {
                if ( 'figure' === $s['role'] && $block === $s['block'] ) {
                    return [ $name, $s['id'] ];
                }
            }
        }
        self::fail( "no $block figure in the library" );
    }

    public function testCounterFiguresMustBeNumbers(): void
    {
        [ $recipe, $id ] = self::figureSlot( 'divi/number-counter' );
        foreach ( [ '$2M', 'N/A', '98%', '1,2,x', '', ] as $bad ) {
            $r = StudioBrief::check( [ 'title' => 'T', 'goal' => 'sell', 'language' => 'editorial', 'sections' => [ [ 'recipe' => $recipe, 'slots' => [ $id => $bad ] ] ] ] );
            if ( '' === $bad ) {
                $this->assertTrue( $r['ok'], 'empty counts as not supplied' );
                continue;
            }
            $this->assertContains( "sections.0.slots.$id=FIGURE_NOT_NUMBER", array_map( static fn ( array $p ): string => $p['path'] . '=' . $p['code'], $r['problems'] ), $bad );
            $this->assertStringContainsString( '%', $r['problems'][0]['message'], 'the message says where the % sign comes from' );
        }
        foreach ( [ '1250', '1,250', '98.6', '0', ' 42 ' ] as $good ) {
            $r = StudioBrief::check( [ 'title' => 'T', 'goal' => 'sell', 'language' => 'editorial', 'sections' => [ [ 'recipe' => $recipe, 'slots' => [ $id => $good ] ] ] ] );
            $this->assertTrue( $r['ok'], $good . ' ' . json_encode( $r['problems'] ) );
        }
        [ $circle, $cid ] = self::figureSlot( 'divi/circle-counter' );
        foreach ( [ '101' => false, '100' => true, '50.5' => true, '-1' => false ] as $value => $ok ) {
            $r = StudioBrief::check( [ 'title' => 'T', 'goal' => 'sell', 'language' => 'editorial', 'sections' => [ [ 'recipe' => $circle, 'slots' => [ $cid => (string) $value ] ] ] ] );
            $this->assertSame( $ok, $r['ok'], "circle counter $value" );
        }
    }

    public function testControlCharactersRefusedButTabsAndLinesAllowed(): void
    {
        foreach ( [ "a b", "ab", "ab", "ab" ] as $bad ) {
            $brief                                      = self::base();
            $brief['sections'][0]['slots']['heading.1'] = $bad;
            $this->assertContains( 'sections.0.slots.heading.1=TEXT_INVALID', self::codes( $brief ), bin2hex( $bad ) );
        }
        $brief                                      = self::base();
        $brief['sections'][0]['slots']['heading.1'] = "two	parts
and a line";
        $this->assertTrue( StudioBrief::check( $brief )['ok'] );
    }

    public function testTrailingSpaceDoesNotTipTheLengthCheck(): void
    {
        $brief                                      = self::base();
        $brief['sections'][0]['slots']['heading.1'] = str_repeat( '😀', 2000 ) . ' ';
        $this->assertTrue( StudioBrief::check( $brief )['ok'] );
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function unsafeAddresses(): array
    {
        return [
            'quote path'      => [ '/x"onmouseover="a', 'LINK_MALFORMED' ],
            'tag in url'      => [ 'https://a.com/x"><script>alert(1)</script>', 'LINK_MALFORMED' ],
            'empty host label' => [ 'https://a..b/x', 'LINK_MALFORMED' ],
        ];
    }

    #[DataProvider( 'unsafeAddresses' )]
    public function testUnsafeAddressesRefusedAsLinksAndPictures( string $url, string $code ): void
    {
        $brief                                                = self::base();
        $brief['sections'][0]['slots']['button.0.link']       = $url;
        $brief['sections'][0]['pictures']['section.0.background'] = [ 'url' => $url ];
        $codes = self::codes( $brief );
        $this->assertContains( "sections.0.slots.button.0.link=$code", $codes );
        $this->assertContains( 'sections.0.pictures.section.0.background=' . $code, $codes );
    }

    public function testPictureAddressesAreStricterThanLinks(): void
    {
        foreach ( [ "https://a.com/it's.jpg", 'https://a.com/x).png', '/a);background:red', 'https://a.com/var(--x)', 'https://a.com/a b.jpg', 'https://a.com/é.jpg' ] as $url ) {
            $brief                                                    = self::base();
            $brief['sections'][0]['pictures']['section.0.background'] = [ 'url' => $url ];
            $this->assertNotSame( [], StudioBrief::check( $brief )['problems'], $url );
        }
        foreach ( [ 'https://a.com/wp-content/uploads/2026/11/a-b_c.jpg', '/wp-content/uploads/a%20b.jpg', 'https://cdn.example.com/a.jpg?w=800&h=600' ] as $url ) {
            $brief                                                    = self::base();
            $brief['sections'][0]['pictures']['section.0.background'] = [ 'url' => $url ];
            $this->assertTrue( StudioBrief::check( $brief )['ok'], $url );
        }
    }

    public function testInvalidUtf8TitleRefused(): void
    {
        $brief          = self::base();
        $brief['title'] = "bad \xff title";
        $this->assertContains( 'title=INVALID_TITLE', self::codes( $brief ) );
    }

    public function testProblemsAreCapped(): void
    {
        $brief = self::base();
        $bad   = [ 'recipe' => 'hero-cta', 'slots' => [] ];
        for ( $i = 0; $i < 200; ++$i ) {
            $bad['slots'][ 'heading.' . ( 100 + $i ) ] = 'x';
        }
        $brief['sections'] = array_fill( 0, 20, $bad );
        $this->assertLessThanOrEqual( StudioBrief::MAX_PROBLEMS, count( StudioBrief::check( $brief )['problems'] ) );
    }

    public function testGarbageNeverWarns(): void
    {
        $deep = 'x';
        for ( $i = 0; $i < 100; ++$i ) {
            $deep = [ $deep ];
        }
        $inputs = [ null, true, 1.5, '', 'x', [], [ [] ], [ 'title' => $deep ], [ 'sections' => $deep ], [ 'sections' => [ $deep ] ],
            [ 'sections' => [ [ 'recipe' => $deep, 'slots' => $deep, 'pictures' => $deep ] ] ], self::base() + [ 'kit' => $deep ], new \stdClass() ];
        foreach ( $inputs as $in ) {
            $r = StudioBrief::check( $in );
            $this->assertIsBool( $r['ok'] );
            $this->assertIsArray( $r['problems'] );
        }
    }

    public function testRecipeAliases(): void
    {
        foreach ( StudioBrief::RECIPE_ALIASES as $old => $new ) {
            $this->assertContains( $new, SectionRecipes::names(), "alias target $new" );
            $this->assertSame( $new, StudioBrief::resolveRecipe( $old ) );
        }
        $this->assertSame( 'hero-cta', StudioBrief::resolveRecipe( 'hero-cta' ) );
        $this->assertNull( StudioBrief::resolveRecipe( 'no-such-recipe' ) );
    }
}
