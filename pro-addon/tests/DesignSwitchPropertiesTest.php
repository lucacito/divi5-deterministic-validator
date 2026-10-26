<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\DesignSwitch;
use AiEditorDivi5\Pro\Tests\Support\DesignDiffClassifier;
use AiEditorDivi5\Pro\Tests\Support\DesignSwitchMatrixOne;
use AiEditorDivi5\WP\SectionRecipes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/Support/DesignDiffClassifier.php';
require_once __DIR__ . '/Support/DesignSwitchMatrixOne.php';

/**
 * Design switch, Task 6 Step 2: the properties, on the matrix-1 set (71 recipes x the ten default designs; computed once
 * with the matrix, `Support/DesignSwitchMatrixOne`).
 *
 * Re-scoped pins (the identity shortcut of Task 5): `DesignSwitch::run()` returns the page unchanged when from == to, so
 * "switching to the own design" and "switching twice to the same target" through `run()` are no-ops BY CONSTRUCTION and
 * would prove nothing about the engine. Both are therefore proven twice: the shortcut on all 710 identity switches, AND
 * the engine path the shortcut skips (unresolve under the design, resolve under it again), where the plan's figures
 * hold exactly: identity 709 of 710, twice 6,381 of 6,390, the misses all `contact-location-form` to luxe, D5 (key order
 * only: a stripped panel padding equal to gilt-hairline's card padding is re-added at the end, spec D4/D5).
 */
class DesignSwitchPropertiesTest extends TestCase
{
    public function testIdentityIsANoOp(): void
    {
        $r = DesignSwitchMatrixOne::result();
        $this->assertSame( 710, $r['identity_shortcut'], 'run() with from == to returns every page byte for byte' );
    }

    public function testIdentityThroughTheEngine(): void
    {
        $r = DesignSwitchMatrixOne::result();
        $this->assertSame( 709, $r['identity_engine'] );
        $this->assertSame( [ 'contact-location-form luxe D5' ], $r['identity_engine_failures'] );
    }

    public function testSwitchingTwiceToTheSameTargetIsANoOp(): void
    {
        $r = DesignSwitchMatrixOne::result();
        $this->assertSame( 6390, $r['pairs'] );
        $this->assertSame( 6381, $r['twice'] );
        $named = [];
        foreach ( [ 'editorial', 'glass-dark', 'organic-warm', 'tech-mono', 'playful-pastel', 'corporate-trust', 'soft-saas', 'bold-vibrant', 'brutalist-flat' ] as $l ) {
            $named[] = "contact-location-form $l>luxe D5";
        }
        $this->assertSame( $named, $r['twice_failures'] );
    }

    /**
     * A -> B -> A returns the original wherever both directions are clean; every other round trip differs only in
     * D1-D5. Plan 5,879 (prototype): minus 23 slider round trips (the accepted tie, Task 5).
     */
    public function testRoundTripReturnsTheOriginal(): void
    {
        $r = DesignSwitchMatrixOne::result();
        $this->assertSame( 5856, $r['round_trip'] );
        $this->assertSame( [], $r['unclassified'], 'every difference of every property is one of D1-D5' );
        $tally = DesignDiffClassifier::tally( $r['round_trip_firsts'] );
        foreach ( [ 'D1' => 422, 'D2' => 48, 'D3' => 46, 'D4' => 9, 'D5' => 9 ] as $class => $cap ) {
            $this->assertLessThanOrEqual( $cap, $tally[ $class ], "round trip $class over its shrink-only cap" );
        }
        $this->assertSame( 0, $tally[ DesignDiffClassifier::STRUCTURE ] + $tally[ DesignDiffClassifier::UNCLASSIFIED ] );
        $this->assertSame( 23, $r['round_trip_recipes']['slider'] ?? 0, 'the slider tie' );
    }

    /** Every 7th output (in loop order, from the first): 913 of the 6,390 (the plan's 912 counted from the 7th). */
    public function testEveryNthOutputPassesTheGate(): void
    {
        $r = DesignSwitchMatrixOne::result();
        $this->assertSame( 913, $r['gated'] );
        $this->assertSame( 913, $r['gate_valid'], 'LayoutGate::check($out, $in) is valid on every sampled output' );
    }

    public function testNoOutputCarriesATokenOrMarker(): void
    {
        $this->assertSame( 0, DesignSwitchMatrixOne::result()['leftovers'] );
    }

    /** On the gated sample: every byte outside the attributes, and every block the switch did not decide to change, is kept. */
    public function testBytesOutsideChangedLeavesArePreserved(): void
    {
        $r = DesignSwitchMatrixOne::result();
        $this->assertSame( $r['gated'], $r['preserved'] );
    }

    /** Foreign blocks and text around a switched page keep every byte; a planted change is caught by the check itself. */
    public function testForeignBytesAroundAPageArePreserved(): void
    {
        $from    = Design::make( 'editorial', 'quiet-solid' );
        $to      = Design::make( 'luxe', 'gilt-hairline' );
        $foreign = "<p>Loose \u{00e9} text -->\n</p>" . '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<b>X</b>"}}},  "x" : 1 } /-->';
        $page    = $foreign . DesignSwitchMatrixOne::build( 'pricing-three-tier', $from ) . $foreign;
        $out     = DesignSwitch::run( $page, $from, $to )->content;
        $this->assertStringStartsWith( $foreign, $out );
        $this->assertStringEndsWith( $foreign, $out );
        $this->assertTrue( DesignSwitchMatrixOne::preserves( $page, DesignSwitch::unresolve( $page, $from, $to ), $out ) );
        $this->assertFalse( DesignSwitchMatrixOne::preserves( $page, DesignSwitch::unresolve( $page, $from, $to ), str_replace( '"x" : 1', '"x":1', $out ) ) );
    }

    /** The same input gives the same bytes and the same report (every 11th matrix-1 switch run twice in one process). */
    public function testDeterminism(): void
    {
        $designs = DesignSwitchMatrixOne::defaultDesigns();
        $k       = 0;
        $runs    = 0;
        foreach ( SectionRecipes::names() as $recipe ) {
            foreach ( $designs as $a => $from ) {
                foreach ( $designs as $b => $to ) {
                    if ( 0 !== ( $k++ ) % 11 ) {
                        continue;
                    }
                    $page = DesignSwitchMatrixOne::build( $recipe, $from );
                    $one  = DesignSwitch::run( $page, $from, $to );
                    $two  = DesignSwitch::run( $page, $from, $to );
                    $this->assertSame( $one->content, $two->content, "$recipe $a>$b" );
                    $this->assertSame( json_encode( $one->toArray( 1000 ) ), json_encode( $two->toArray( 1000 ) ), "$recipe $a>$b" );
                    ++$runs;
                }
            }
        }
        $this->assertSame( 646, $runs );
    }
}
