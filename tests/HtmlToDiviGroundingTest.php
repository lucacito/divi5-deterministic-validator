<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DiviMarkup;
use Divi5Validator\Tests\Support\ProvenOverride;
use Divi5Validator\Tests\Support\DeadOnModule;
use Divi5Validator\Tests\Support\HtmlStylesVocabulary;
use Divi5Validator\Tests\Support\KnownBlockPaths;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;

require_once __DIR__ . '/Support/KnownBlockPaths.php';
require_once __DIR__ . '/Support/HtmlStylesVocabulary.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';
require_once __DIR__ . '/Support/DeadOnModule.php';
require_once __DIR__ . '/Support/ProvenOverride.php';

/**
 * Every attribute path the style writer can emit is (a) in the verified shape registry, (b) proven ON the block that carries it
 * (a real export or served recipe on that block name, or a STYLE_PROVEN decision whose probe said PASS on that block), and (c) not
 * DEAD on that module. A false decision is never written. The checks are functions so a planted ungrounded path proves they bite.
 */
final class HtmlToDiviGroundingTest extends HtmlTestCase
{
    /** Every style the writer can emit, for every block, under the real decisions. @return list<string> */
    private static function styledSamples(): array
    {
        $all   = DiviMarkup::STYLE_PROVEN;
        $bodyfont = array_merge( $all, [ 'text-align-orientation' => false ] ); // the other alignment path of a text module
        $pad   = [ 'top' => '64px', 'right' => '24px', 'bottom' => '64px', 'left' => '24px' ];
        $font  = [ 'color' => '#334155', 'size' => 56, 'weight' => '700', 'family' => 'Playfair Display' ];
        $out   = [ DiviMarkupStyleTest::fullyStyledPage() ];
        foreach ( [ 'center', 'right', 'justify' ] as $align ) {
            $out[] = DiviMarkup::heading( 'H', 2, $font + [ 'align' => $align ] );
            $out[] = DiviMarkup::text( '<p>T</p>', $font + [ 'align' => $align ] );
            $out[] = ProvenOverride::run( $bodyfont, static fn () => DiviMarkup::text( '<p>T</p>', $font + [ 'align' => $align ] ) );
        }
        foreach ( DiviMarkup::WEB_SAFE_FAMILIES as $family ) {
            $out[] = DiviMarkup::heading( 'H', 3, [ 'family' => $family ] );
            $out[] = DiviMarkup::text( '<p>T</p>', [ 'family' => $family ] );
        }
        foreach ( [ '<ul><li>a</li></ul>', '<ol><li>a</li></ol>', '<blockquote><p>a</p></blockquote>' ] as $html ) {
            $out[] = DiviMarkup::text( $html, [ 'color' => '#334155' ] );
        }
        foreach ( [ [ '#ffffff', 0 ], [ DiviMarkup::TRANSPARENT, 999 ] ] as [ $fill, $radius ] ) {
            $out[] = DiviMarkup::button( 'B', '/x', true, [ 'color' => '#0f172a', 'background' => $fill, 'radius' => $radius ] );
        }
        $out[] = DiviMarkup::section( 'S', DiviMarkup::row( [ 'a', 'b' ], [ 'background' => '#f8fafc', 'padding' => $pad, 'max_width' => '1080px' ], [
            [ 'background' => '#e2e8f0', 'padding' => $pad ], [ 'padding' => [ 'left' => '8px' ] ],
        ] ), [ 'background' => '#0f172a', 'padding' => $pad ] );

        return $out;
    }

    /** @return list<string> "<block> <path>" for every path of a markup string that the registry does not cover */
    private static function unregistered( string $markup ): array
    {
        $registry = ShapeRegistry::load();
        $bad      = [];
        foreach ( ShapePaths::fromMarkup( $markup ) as $path ) {
            if ( ! $registry->coversLeaf( $path ) && ! $registry->covers( $path ) ) {
                $bad[] = $path;
            }
        }

        return $bad;
    }

    /**
     * "<block> <path>" for every attribute path of a block that is neither seen on that block name in a real export or served recipe nor
     * the measured path of a PASS decision (in $proven) on that block.
     *
     * @param array<string,bool> $proven
     * @return list<string>
     */
    private static function ungrounded( string $markup, array $proven ): array
    {
        $known    = KnownBlockPaths::all();
        $measured = HtmlStylesVocabulary::provenPaths( $proven );
        $bad      = [];
        preg_match_all( '/<!-- wp:divi\/([a-z0-9-]+) (\{.*?\}) \/?-->/s', $markup, $blocks, PREG_SET_ORDER );
        foreach ( $blocks as $b ) {
            $attrs = json_decode( $b[2], true );
            foreach ( KnownBlockPaths::paths( (array) $attrs ) as $path ) {
                $head = explode( '.', $path )[0];
                if ( 'builderVersion' === $head || isset( $known[ $b[1] ][ $path ] ) || isset( $measured[ $b[1] ][ $path ] ) ) {
                    continue;
                }
                $bad[ $b[1] . ' ' . $path ] = true;
            }
        }

        return array_keys( $bad );
    }

    public function testNoStyledSampleWritesTheDeadOrientationPath(): void
    {
        foreach ( self::styledSamples() as $i => $markup ) {
            $this->assertStringNotContainsString( '"orientation":"justified"', $markup, 'sample ' . $i );
            $this->assertStringNotContainsString( 'justified', $markup, 'sample ' . $i );
        }
    }

    public function testTheSamplesAreWhatTheWriterCanEmit(): void
    {
        $joined = implode( "\n", self::styledSamples() );
        foreach ( [ 'orientation', 'textAlign', 'family', 'maxWidth', 'spacing', 'radius', 'tablet', 'phone', 'background', 'wp:divi/column' ] as $needle ) {
            $this->assertStringContainsString( $needle, $joined, 'the samples exercise ' . $needle );
        }
        foreach ( [ 'section', 'row', 'column', 'heading', 'text', 'button' ] as $block ) {
            $this->assertStringContainsString( '<!-- wp:divi/' . $block . ' {', $joined, 'a styled ' . $block );
        }
    }

    public function testEveryStylePathIsInTheRegistry(): void
    {
        foreach ( self::styledSamples() as $i => $markup ) {
            $this->assertSame( [], self::unregistered( $markup ), 'sample ' . $i . ': every path is in fixtures/shapes/verified-shapes.json' );
        }
    }

    public function testEveryStylePathIsGroundedOnItsOwnBlock(): void
    {
        foreach ( self::styledSamples() as $i => $markup ) {
            $this->assertSame( [], self::ungrounded( $markup, DiviMarkup::STYLE_PROVEN ), 'sample ' . $i . ': every path is proven ON its own block' );
        }
    }

    public function testNoStylePathIsDeadOnItsModule(): void
    {
        $dead = DeadOnModule::load();
        foreach ( self::styledSamples() as $i => $markup ) {
            $this->assertSame( [], $dead->violations( $markup ), 'sample ' . $i );
        }
    }

    public function testTheChecksBiteOnAPlantedUngroundedPath(): void
    {
        // A path proven on one module proves nothing on another: a max width on a section, a body-font textAlign on a heading, the
        // button's own padding (DEAD on a button), and a path nobody ever exported.
        $planted = [
            'section maxWidth'    => '<!-- wp:divi/section ' . DiviMarkup::attributes( [ 'module' => [ 'decoration' => [ 'sizing' => [ 'desktop' => [ 'value' => [ 'maxWidth' => '700px' ] ] ] ] ] ] ) . ' /-->',
            'heading bodyFont'    => '<!-- wp:divi/heading ' . DiviMarkup::attributes( [ 'content' => [ 'decoration' => [ 'bodyFont' => [ 'body' => [ 'font' => [ 'desktop' => [ 'value' => [ 'textAlign' => 'center' ] ] ] ] ] ] ] ] ) . ' /-->',
            'text title font'     => '<!-- wp:divi/text ' . DiviMarkup::attributes( [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => '#112233' ] ] ] ] ] ] ] ) . ' /-->',
            'invented'            => '<!-- wp:divi/row ' . DiviMarkup::attributes( [ 'module' => [ 'decoration' => [ 'glow' => [ 'desktop' => [ 'value' => [ 'amount' => '3px' ] ] ] ] ] ] ) . ' /-->',
        ];
        foreach ( $planted as $name => $markup ) {
            $this->assertNotSame( [], self::ungrounded( $markup, DiviMarkup::STYLE_PROVEN ), $name . ' is not proven on its block' );
        }
        $this->assertNotSame( [], self::unregistered( $planted['invented'] ), 'the registry refuses a path nobody proved' );
        $button = '<!-- wp:divi/button ' . DiviMarkup::attributes( [ 'button' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => '8px' ] ] ] ] ] ] ] ) . ' /-->';
        $this->assertNotSame( [], DeadOnModule::load()->violations( $button ), 'the dead-on-module guard refuses a button\'s own padding' );

        // A decision that is false proves nothing: with it off, the path it measured is ungrounded.
        $orientation = DiviMarkup::text( '<p>x</p>', [ 'align' => 'center' ] );
        $this->assertSame( [], self::ungrounded( $orientation, DiviMarkup::STYLE_PROVEN ) );
        $off = array_merge( DiviMarkup::STYLE_PROVEN, [ 'text-align-orientation' => false ] );
        $textAlign = '<!-- wp:divi/text ' . DiviMarkup::attributes( [ 'content' => [ 'decoration' => [ 'bodyFont' => [ 'body' => [ 'font' => [ 'desktop' => [ 'value' => [ 'textAlign' => 'justify' ] ] ] ] ] ] ] ] ) . ' /-->';
        $this->assertSame( [], self::ungrounded( $textAlign, DiviMarkup::STYLE_PROVEN ) );
        $this->assertNotSame( [], self::ungrounded( $textAlign, array_merge( $off, [ 'text-align-bodyfont' => false, 'text-justify' => false ] ) ) );
    }

    /** @return array<string,array{0:\Closure,1:\Closure,2?:array<string,bool>}> decision => [asks for it, whether its path shows in the markup, decisions to switch off first (the path it competes with)] */
    private static function asks(): array
    {
        $has = static fn ( string $needle ): \Closure => static fn ( string $m ): bool => str_contains( $m, $needle );
        $any = static fn ( array $needles ): \Closure => static fn ( string $m ): bool => array_filter( $needles, static fn ( string $n ): bool => str_contains( $m, $n ) ) !== [];

        return [
            'text-align-orientation' => [ static fn () => DiviMarkup::text( '<p>x</p>', [ 'align' => 'center' ] ), $has( '"orientation"' ) ],
            'text-align-bodyfont'    => [ static fn () => DiviMarkup::text( '<p>x</p>', [ 'align' => 'center' ] ), $has( '"textAlign"' ), [ 'text-align-orientation' => false ] ],
            'text-justify'           => [ static fn () => DiviMarkup::text( '<p>x</p>', [ 'align' => 'justify' ] ), $has( '"textAlign":"justify"' ) ],
            'heading-justify'        => [ static fn () => DiviMarkup::heading( 'x', 2, [ 'align' => 'justify' ] ), $has( '"textAlign":"justify"' ) ],
            'websafe-family'         => [ static fn () => DiviMarkup::heading( 'x', 2, [ 'family' => 'Georgia' ] ) . DiviMarkup::text( 'x', [ 'family' => 'Courier New' ] ), $any( [ '"family":"Georgia"', '"family":"Courier New"' ] ) ],
            'row-max-width'          => [ static fn () => DiviMarkup::row( [ '' ], [ 'max_width' => '700px' ] ), $has( '"maxWidth"' ) ],
            'list-ink-ul'            => [ static fn () => DiviMarkup::text( '<ul><li>x</li></ul>', [ 'color' => '#aabbcc' ] ), $has( '#aabbcc' ) ],
            'list-ink-ol'            => [ static fn () => DiviMarkup::text( '<ol><li>x</li></ol>', [ 'color' => '#aabbcc' ] ), $has( '#aabbcc' ) ],
            'quote-ink'              => [ static fn () => DiviMarkup::text( '<blockquote><p>x</p></blockquote>', [ 'color' => '#aabbcc' ] ), $has( '#aabbcc' ) ],
            'row-background'         => [ static fn () => DiviMarkup::row( [ '' ], [ 'background' => '#aabbcc' ] ), $has( '#aabbcc' ) ],
            'column-background'      => [ static fn () => DiviMarkup::row( [ '' ], [], [ [ 'background' => '#aabbcc' ] ] ), $has( '#aabbcc' ) ],
            'column-padding'         => [ static fn () => DiviMarkup::row( [ '' ], [], [ [ 'padding' => [ 'top' => '8px' ] ] ] ), $has( '"spacing"' ) ],
            'section-padding'        => [ static fn () => DiviMarkup::section( '', '', [ 'padding' => [ 'top' => '8px' ] ] ), $has( '"spacing"' ) ],
        ];
    }

    public function testADecisionThatIsFalseIsNeverWritten(): void
    {
        $asks = self::asks();
        $this->assertSame( array_keys( DiviMarkup::STYLE_PROVEN ), array_keys( $asks ), 'every decision has a planted ask' );
        foreach ( $asks as $decision => $entry ) {
            [ $ask, $shows ] = $entry;
            $base = array_merge( DiviMarkup::STYLE_PROVEN, $entry[2] ?? [] );
            $on   = ProvenOverride::run( $base, $ask );
            $this->assertTrue( $shows( $on ), $decision . ': with the decision true the ask is written (the check is not vacuous)' );
            try {
                $off = ProvenOverride::run( array_merge( $base, [ $decision => false ] ), $ask );
            } catch ( \InvalidArgumentException ) {
                $off = ''; // refused outright (a web-safe family while the decision is false): nothing written either
            }
            $this->assertFalse( $shows( $off ), $decision . ': with the decision false its path is not written even though the style asks for it' );
        }
        // Whatever the real table says: a decision that is false in STYLE_PROVEN is absent from every styled sample (the three that share
        // a textAlign path with another decision are judged by the planted asks above).
        $samples = implode( "\n", self::styledSamples() );
        foreach ( array_diff( array_keys( array_filter( DiviMarkup::STYLE_PROVEN, static fn ( bool $on ): bool => ! $on ) ), [ 'text-align-bodyfont', 'text-justify', 'heading-justify' ] ) as $decision ) {
            $this->assertFalse( $asks[ $decision ][1]( $samples ), $decision . ' is false in STYLE_PROVEN and shows in a styled sample' );
        }
    }

    public function testEveryDecisionIsGroundedByAMeasuredCandidateOnItsBlock(): void
    {
        $rows       = HtmlStylesVocabulary::rows();
        $candidates = HtmlStylesVocabulary::candidates();
        $effects    = HtmlStylesVocabulary::effects();
        foreach ( DiviMarkup::STYLE_PROVEN as $decision => $on ) {
            if ( ! $on ) {
                continue;
            }
            $this->assertSame( 'PASS', $rows[ $decision ]['verdict'], $decision );
            preg_match_all( '/hs-[a-z0-9-]+/', $rows[ $decision ]['evidence'], $ids );
            $measured = array_filter( array_unique( $ids[0] ), static fn ( string $id ): bool => true === ( $effects[ $id ] ?? false ) );
            $this->assertNotSame( [], $measured, $decision . ' has at least one evidence candidate the probe measured an effect for' );
            foreach ( $measured as $id ) {
                $this->assertArrayHasKey( $id, $candidates );
            }
        }
        $proven = HtmlStylesVocabulary::provenPaths( DiviMarkup::STYLE_PROVEN );
        $this->assertArrayHasKey( 'module.decoration.sizing.desktop.value.maxWidth', $proven['row'] );
        $this->assertArrayNotHasKey( 'module.decoration.sizing.desktop.value.maxWidth', $proven['section'] ?? [], 'a max width is proven on a row only' );
    }
}
