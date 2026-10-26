<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\ContextTable;
use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\DesignSwitch;
use AiEditorDivi5\Pro\Design\JsonLeaves;
use AiEditorDivi5\Pro\Design\MarkerRecovery;
use AiEditorDivi5\Pro\Design\SwitchResult;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

/**
 * Design switch, Task 5: the switch of one page (spec 2026-10-25-pro-design-switch-design.md section 5). The matrices
 * over the whole library are Task 6; here one recipe end to end, the identity, the refusal of an unresolved result, the
 * report's arithmetic and determinism.
 */
class DesignSwitchTest extends TestCase
{
    /** The page as the free plugin serves the recipe under a design (image tokens resolved as outside WordPress). */
    private static function build( string $recipe, Design $d ): string
    {
        $page = SectionRecipes::resolveMarkup( ImageTokens::resolve( (string) SectionRecipes::rawMarkup( $recipe ), '' ), $d->language, $d->kit );
        self::assertIsString( $page, $recipe );

        return $page;
    }

    private static function own( string $language ): Design
    {
        return Design::make( $language, (string) DesignLanguages::get( $language )['kit'] );
    }

    public function testOneRecipeEndToEndEqualsTheDirectBuild(): void
    {
        $from = Design::make( 'editorial', 'quiet-solid' );
        $to   = Design::make( 'luxe', 'gilt-hairline' );
        $page = self::build( 'pricing-three-tier', $from );
        $r    = DesignSwitch::run( $page, $from, $to );

        $this->assertNull( $r->error );
        $this->assertTrue( $r->changed );
        $this->assertSame( self::build( 'pricing-three-tier', $to ), $r->content );
        $this->assertGreaterThan( 0, array_sum( $r->switched['markers'] ), 'kit markers recovered' );
        $this->assertGreaterThan( 0, array_sum( $r->switched['colours'] ), 'colour roles recovered' );
        $this->assertSame( [], $r->left['drifted'] );
        $this->assertSame( [], $r->left['ambiguous'] );
        foreach ( $r->left['unclassified'] as $x ) {
            $this->assertContains( $x['name'], [ 'divi/icon', 'divi/group' ], 'unmarked icons and groups with their own decoration stay as they are' );
        }
    }

    public function testASwitchedPageGoesBackToItsSource(): void
    {
        $a    = Design::make( 'editorial', 'quiet-solid' );
        $b    = Design::make( 'luxe', 'gilt-hairline' );
        $page = self::build( 'pricing-three-tier', $a );
        $back = DesignSwitch::run( DesignSwitch::run( $page, $a, $b )->content, $b, $a );
        $this->assertSame( $page, $back->content );
    }

    public function testIdentityReturnsThePageUnchanged(): void
    {
        $d    = self::own( 'glass-dark' );
        $page = self::build( 'faq-accordion', $d ) . "\n<!-- wp:divi/text {\"x\":\"\\u003cb\"} /-->";
        $r    = DesignSwitch::run( $page, $d, Design::make( 'glass-dark', $d->kitId ) );
        $this->assertSame( $page, $r->content );
        $this->assertFalse( $r->changed );
        $this->assertNull( $r->error );
        $this->assertSame( 0, $r->inspected );
        $this->assertSame( 0, $r->recovered() );
    }

    public function testAnUnresolvedResultReturnsTheOriginal(): void
    {
        $from = Design::make( 'editorial', 'quiet-solid' );
        $page = self::build( 'cta-band', $from ) . "\n" . '<!-- wp:divi/button {"aied":{"kit":"no-such-role","on":"light"}} /-->';
        $this->assertNotSame( [], KitResolver::leftovers( $page ), 'the planted marker is one the resolver refuses' );

        $r = DesignSwitch::run( $page, $from, Design::make( 'luxe', 'gilt-hairline' ) );
        $this->assertSame( SwitchResult::ERROR_UNRESOLVED, $r->error );
        $this->assertSame( $page, $r->content, 'the original page, never a partial one' );
        $this->assertFalse( $r->changed );
    }

    public function testModesAreChecked(): void
    {
        $d = self::own( 'editorial' );
        try {
            DesignSwitch::run( '', $d, self::own( 'luxe' ), 'repaint' );
            $this->fail( 'an unknown mode is refused' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'leave, conform', $e->getMessage() );
        }
        $this->assertSame( '', DesignSwitch::run( '', $d, self::own( 'luxe' ), 'conform' )->content, 'conform is a mode (Task 7)' );
    }

    /**
     * Every inspected leaf is counted exactly once, and `inspected` is the number of colour/font leaves the unresolved
     * page holds (counted here independently from the switch).
     */
    public function testTheReportCountsEveryDecision(): void
    {
        $cases = [
            [ 'pricing-three-tier', 'editorial', 'luxe' ],
            [ 'slider', 'glass-dark', 'editorial' ],
            [ 'team-grid', 'brutalist-flat', 'soft-saas' ],
            [ 'contact-location-form', 'luxe', 'editorial' ],
            [ 'proof-logo-wall', 'playful-pastel', 'tech-mono' ],
            [ 'stats-big-captions', 'corporate-trust', 'bold-vibrant' ],
        ];
        foreach ( $cases as [ $recipe, $a, $b ] ) {
            $from = self::own( $a );
            $page = self::build( $recipe, $from ) . "\n" . '<!-- wp:divi/text {"content":{"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"#123457","family":"No Such Font"}}}}}}},"module":{"decoration":{"background":{"desktop":{"value":{"color":"$variable({\"type\":\"color\",\"value\":{\"name\":\"gcid-abc\"}})$"}}}}}} /-->';
            $r    = DesignSwitch::run( $page, $from, self::own( $b ) );
            $this->assertNull( $r->error, $recipe );

            $sum = array_sum( $r->switched['colours'] ) + array_sum( $r->switched['fonts'] )
                + count( array_filter( $r->assumed, static fn ( array $x ): bool => in_array( $x['kind'], [ 'colour', 'font' ], true ) ) )
                + array_sum( $r->left['unknown_colours'] ) + array_sum( $r->left['unknown_fonts'] )
                + count( $r->left['ambiguous'] ) + $r->left['global_refs'];
            $this->assertSame( $r->inspected, $sum, $recipe . ': switched + assumed + left = inspected' );
            $this->assertSame( self::inspectable( DesignSwitch::unresolve( $page, $from, self::own( $b ) ) ), $r->inspected, $recipe );
            $this->assertSame( 1, $r->left['unknown_colours']['#123457'] ?? 0, $recipe );
            $this->assertSame( 1, $r->left['unknown_fonts']['No Such Font'] ?? 0, $recipe );
            $this->assertSame( 1, $r->left['global_refs'], $recipe );
            foreach ( $r->assumed as $x ) {
                $this->assertArrayHasKey( 'reason', $x );
                $this->assertArrayHasKey( 'block', $x );
            }
            foreach ( $r->left['ambiguous'] as $x ) {
                $this->assertGreaterThan( 1, count( $x['roles'] ), 'an ambiguous value names its roles' );
            }
        }
    }

    /** The leaves of an unresolved page the switch inspects: tokens it wrote back plus the values it left. */
    private static function inspectable( string $unresolved ): int
    {
        preg_match_all( '/<!--\s+wp:[a-z0-9\/-]+\s+(\{.*?\})\s+\/?-->/s', $unresolved, $m );
        $n = 0;
        foreach ( $m[1] as $json ) {
            foreach ( JsonLeaves::strings( $json ) as $leaf ) {
                if ( str_starts_with( $leaf['path'], '.aied.' ) || '' === $leaf['value'] ) {
                    continue;
                }
                if ( 'family' === $leaf['key'] || 'color' === $leaf['key'] ) {
                    ++$n;
                } elseif ( 'value' === $leaf['key'] && ( str_contains( $leaf['value'], 'gcid-' ) || str_starts_with( $leaf['value'], '{{aied:color:' ) || null !== \AiEditorDivi5\Pro\Design\Bands::colourKey( $leaf['value'] ) ) ) {
                    ++$n;
                }
            }
        }

        return $n;
    }

    public function testAmbiguousValuesAreLeftAndNamed(): void
    {
        // glass-dark uses one family for display, body and eyebrow; the library uses display and eyebrow equally for
        // the slider title (one each): no strict majority, so the family is left and reported.
        $from = self::own( 'glass-dark' );
        $r    = DesignSwitch::run( self::build( 'slider', $from ), $from, self::own( 'editorial' ) );
        $paths = array_map( static fn ( array $x ): string => $x['name'] . ' ' . $x['path'], $r->left['ambiguous'] );
        $this->assertContains( 'divi/slider .title.decoration.font.font.desktop.value.family', $paths );
        foreach ( $r->left['ambiguous'] as $x ) {
            $this->assertSame( 'font', $x['kind'] );
            $this->assertSame( [ 'display', 'body', 'eyebrow' ], $x['roles'] );
        }
    }

    public function testAFlushImageUnderAZeroRadiusKitIsAssumed(): void
    {
        $from = Design::make( 'editorial', 'hairline-mono' );
        $this->assertSame( '0px', StyleKits::get( 'hairline-mono' )['radius']['media'] );
        $r = DesignSwitch::run( self::build( 'team-grid', $from ), $from, Design::make( 'editorial', 'warm-pill' ) );
        $images = array_values( array_filter( $r->assumed, static fn ( array $x ): bool => 'image' === $x['kind'] ) );
        $this->assertCount( 4, $images, 'the four flush team photos (D2)' );
        $this->assertSame( 4, $r->switched['images'] );
        $this->assertSame( DesignSwitch::ASSUMED_FLUSH_IMAGE, $images[0]['reason'] );
    }

    public function testAKitImageIsSwitchedWithoutAnAssumption(): void
    {
        $from = Design::make( 'editorial', 'warm-pill' );
        $to   = Design::make( 'editorial', 'soft-solid' );
        $r    = DesignSwitch::run( self::build( 'portfolio-featured-grid', $from ), $from, $to );
        $this->assertSame( 5, $r->switched['images'] );
        $this->assertSame( [], array_filter( $r->assumed, static fn ( array $x ): bool => 'image' === $x['kind'] ) );
        $this->assertSame( self::build( 'portfolio-featured-grid', $to ), $r->content );
    }

    public function testTheMediaPathsComeFromTheLibrary(): void
    {
        $paths = DesignSwitch::mediaPaths();
        $this->assertSame( [ 'divi/image' ], array_keys( $paths ) );
        $this->assertCount( 1, $paths['divi/image'] );
        $raw = (string) SectionRecipes::rawMarkup( 'hero-product-glow' );
        $this->assertStringContainsString( '"' . end( $paths['divi/image'][0] ) . '":"' . DesignSwitch::MEDIA_TOKEN . '"', $raw );
    }

    /** A hand-written rgba() equal to one role's alpha form becomes the alpha token and follows the target. */
    public function testAnAlphaFormFollowsItsRole(): void
    {
        $from  = self::own( 'editorial' );
        $to    = self::own( 'glass-dark' );
        $alpha = RecipeTokens::resolve( '{{aied:color:text:40}}', $from->language );
        $page  = '<!-- wp:divi/text {"module":{"decoration":{"border":{"desktop":{"value":{"styles":{"all":{"color":"' . $alpha . '"}}}}}}}} /-->';
        $r     = DesignSwitch::run( $page, $from, $to );
        $this->assertSame( 1, $r->switched['colours']['text:40'] ?? 0 );
        $this->assertStringContainsString( '"color":"' . RecipeTokens::resolve( '{{aied:color:text:40}}', $to->language ) . '"', $r->content );
    }

    /** A block the switch does not decide to change keeps every byte, wherever it sits on the page. */
    public function testUntouchedBlocksKeepTheirBytes(): void
    {
        $from  = self::own( 'editorial' );
        $odd   = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\u003cp\u003eA \/ B -\u002d\u003e x\u003c\/p\u003e"}}},  "module":{"meta":{"adminLabel":{"desktop":{"value":"#zzz"}}}}} /-->';
        $page  = $odd . "\n" . self::build( 'cta-band', $from ) . "\n" . $odd;
        $r     = DesignSwitch::run( $page, $from, self::own( 'luxe' ) );
        $this->assertStringStartsWith( $odd . "\n", $r->content );
        $this->assertStringEndsWith( "\n" . $odd, $r->content );
    }

    public function testTheSameInputGivesTheSameBytes(): void
    {
        $from = self::own( 'playful-pastel' );
        $to   = Design::make( 'tech-mono', 'warm-pill' );
        $page = '';
        foreach ( [ 'hero-gradient-stats', 'features-bento', 'contact-form', 'faq-toggle-list', 'slider' ] as $recipe ) {
            $page .= self::build( $recipe, $from ) . "\n";
        }
        $one = DesignSwitch::run( $page, $from, $to );
        $two = DesignSwitch::run( $page, $from, $to, 'leave', ContextTable::current() );
        $this->assertSame( $one->content, $two->content );
        $this->assertSame( $one->toArray( 1000 ), $two->toArray( 1000 ) );
        $this->assertSame( json_encode( $one->toArray() ), json_encode( $two->toArray() ) );
    }

    public function testToArrayCutsListsAndCounts(): void
    {
        $from  = self::own( 'glass-dark' );
        $r     = DesignSwitch::run( self::build( 'slider', $from ), $from, self::own( 'editorial' ) );
        $total = count( $r->left['ambiguous'] );
        $this->assertGreaterThan( 1, $total );
        $a = $r->toArray( 1 );
        $this->assertCount( 1, $a['left']['ambiguous'] );
        $this->assertSame( $total, $a['left']['ambiguous_count'] );
        $this->assertSame( count( $r->assumed ), $a['assumed_count'] );
        $this->assertLessThanOrEqual( 1, count( $a['assumed'] ) );
        $this->assertArrayNotHasKey( 'content', $a );
        $this->assertSame( $r->inspected, $a['inspected'] );
    }

    /** Ruling 4: a full match that a smaller candidate with another target patch also fits is switched and reported. */
    public function testALargerMatchIsReported(): void
    {
        $from = Design::make( 'luxe', 'gilt-hairline' );
        $r    = DesignSwitch::run( self::build( 'contact-location-form', $from ), $from, self::own( 'editorial' ) );
        $larger = array_filter( $r->assumed, static fn ( array $x ): bool => 'marker' === $x['kind'] && str_contains( $x['reason'], MarkerRecovery::ASSUMED_LARGER ) );
        $this->assertNotSame( [], $larger );
    }
}
