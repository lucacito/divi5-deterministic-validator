<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\DesignSwitch;
use AiEditorDivi5\Pro\Tests\Support\DesignDiffClassifier;
use AiEditorDivi5\Pro\Tests\Support\DesignSwitchMatrixOne;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/Support/DesignDiffClassifier.php';
require_once __DIR__ . '/Support/DesignSwitchMatrixOne.php';

/**
 * Design switch, Task 6: the kit layer (spec section 9.3; plan Task 6 Step 1). The full kit matrix (every recipe, 40 x 40
 * kits under editorial, 113,600 switches, about 5 minutes) is too slow for the suite, so it is sampled as the plan says:
 * three recipes with the most kit carriers under every kit pair, and the 14-recipe cover set (every one of the 25
 * (block, role, ground) kit markers and the media token, `DesignInventoryTest`) from every kit to 8 seeded target kits.
 * Only D2, D4 and D5 may appear on the kit layer; measured: none at all, every switch byte-identical.
 */
class DesignSwitchKitMatrixTest extends TestCase
{
    /** `mt_srand(7)`, then kits drawn with `mt_rand(0, 39)` until 8 distinct ones, pinned literally. */
    public const TARGET_KITS = [ 'underline-link', 'warm-pill-soft', 'glass-edge', 'soft-rounded', 'hairline-mono', 'flat-square', 'heavy-block', 'hairline-halo' ];

    public const COVER_SET = [
        'pricing-three-tier', 'features-bento', 'pricing-two-plan-highlight', 'icon-values', 'list-icon-dividers',
        'portfolio-featured-grid', 'blurb-grid', 'contact-form', 'testimonial', 'features-accordion-image',
        'proof-press-quotes', 'stats-counter-band', 'faq-accordion', 'faq-toggle-list',
    ];

    public function testFullKitMatrixOnThreeRecipes(): void
    {
        $kits = StyleKits::ids();
        $this->assertCount( 40, $kits );
        $r = self::matrix( [ 'pricing-three-tier', 'features-bento', 'contact-form' ], $kits, $kits );
        $this->assertSame( 4800, $r['switches'] );
        $this->assertSame( 4800, $r['identical'] );
        $this->assertSame( [], $r['other'], 'only D2, D4, D5 may appear on the kit layer' );
    }

    public function testCoverSetUnderSeededTargets(): void
    {
        $kits = StyleKits::ids();
        mt_srand( 7 );
        $drawn = [];
        while ( count( $drawn ) < 8 ) {
            $k = $kits[ mt_rand( 0, count( $kits ) - 1 ) ];
            if ( ! in_array( $k, $drawn, true ) ) {
                $drawn[] = $k;
            }
        }
        mt_srand();
        $this->assertSame( self::TARGET_KITS, $drawn, 'the draw is pinned literally' );

        $r = self::matrix( self::COVER_SET, $kits, self::TARGET_KITS );
        $this->assertSame( 14 * 40 * 8, $r['switches'] );
        $this->assertSame( 4480, $r['identical'] );
        $this->assertSame( [], $r['other'], 'only D2, D4, D5 may appear on the kit layer' );
    }

    /**
     * @param list<string> $recipes
     * @param list<string> $sources
     * @param list<string> $targets
     *
     * @return array{switches:int, identical:int, other:list<string>}
     */
    private static function matrix( array $recipes, array $sources, array $targets ): array
    {
        $designs = [];
        foreach ( array_unique( array_merge( $sources, $targets ) ) as $k ) {
            $designs[ $k ] = Design::make( 'editorial', $k );
        }
        $n     = 0;
        $same  = 0;
        $other = [];
        foreach ( $recipes as $recipe ) {
            $built = [];
            foreach ( $designs as $k => $d ) {
                $built[ $k ] = DesignSwitchMatrixOne::build( $recipe, $d );
            }
            foreach ( $sources as $a ) {
                foreach ( $targets as $b ) {
                    ++$n;
                    $classes = DesignDiffClassifier::classify( DesignSwitch::run( $built[ $a ], $designs[ $a ], $designs[ $b ] )->content, $built[ $b ] );
                    if ( [] === $classes ) {
                        ++$same;
                    } elseif ( [] !== array_diff( $classes, [ 'D2', 'D4', 'D5' ] ) ) {
                        $other[] = "$recipe $a>$b " . implode( '+', $classes );
                    }
                }
            }
        }

        return [ 'switches' => $n, 'identical' => $same, 'other' => $other ];
    }
}
