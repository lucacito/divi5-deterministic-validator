<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\Expectations;
use AiEditorDivi5\Pro\Design\MarkerSpace;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\LanguageAdapters;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\Dogfood\Expected;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../tests/Support/Dogfood/Expected.php';

/**
 * Design switch, Task 3: an expectation is the free pipeline's own output for a one-block marker markup (Ruling 2), so it
 * must equal what the independent dev scorer (`Expected`) builds for the same marker, on every block, role, band and
 * ground the marker space can name, under the ten default designs.
 */
class ExpectationsTest extends TestCase
{
    private const GROUNDS = [ null, 'inverted', 'accent' ];

    public function testEqualsTheScorersExpectationForEveryKitMarker(): void
    {
        $compared = 0;
        $taken    = 0;
        foreach ( DesignLanguages::ids() as $id ) {
            $kitId    = (string) DesignLanguages::get( $id )['kit'];
            $ours     = new Expectations( Design::make( $id, $kitId ) );
            $theirs   = new Expected( (array) StyleKits::get( $kitId ), (array) DesignLanguages::get( $id ) );
            foreach ( array_keys( MarkerSpace::ROLE_BLOCKS ) as $role ) {
                foreach ( array_unique( array_merge( ...array_values( MarkerSpace::ROLE_BLOCKS ) ) ) as $block ) {
                    foreach ( Expected::BANDS as $band ) {
                        foreach ( self::GROUNDS as $ground ) {
                            $marker = [ 'kit' => $role, 'on' => $band ];
                            if ( null !== $ground ) {
                                $marker['ground'] = $ground;
                            }
                            $label = $id . ' ' . $block . ' ' . json_encode( $marker );
                            $want  = $theirs->attrs( $block, $role, $band, $ground );
                            $json  = $ours->json( $block, $marker );
                            ++$compared;
                            if ( null === $want ) {
                                $this->assertNull( $json, $label );
                                $this->assertNull( $ours->attrs( $block, $marker ), $label );
                                continue;
                            }
                            $this->assertIsString( $json, $label );
                            $this->assertSame( json_encode( $want ), json_encode( json_decode( $json, true ) ), $label );
                            $this->assertSame( json_decode( $json, true ), json_decode( (string) json_encode( $ours->attrs( $block, $marker ) ), true ), $label );
                            ++$taken;
                        }
                    }
                }
            }
        }
        $this->assertSame( 10 * 11 * 11 * 5 * 3, $compared, '10 designs x 11 roles x 11 carrier blocks x 5 bands x 3 grounds' );
        $this->assertGreaterThan( 1000, $taken, 'not vacuous' );
    }

    public function testEveryCandidateOfTheMarkerSpaceResolves(): void
    {
        foreach ( DesignLanguages::ids() as $id ) {
            $ours   = new Expectations( Design::make( $id, (string) DesignLanguages::get( $id )['kit'] ) );
            $blocks = array_unique( array_merge( ...array_values( MarkerSpace::ROLE_BLOCKS ), ...array_map( 'array_keys', array_values( LanguageAdapters::APPLIED ) ) ) );
            foreach ( $blocks as $block ) {
                foreach ( [ ...MarkerSpace::kitCandidates( $block ), ...MarkerSpace::languageCandidates( $block ) ] as $candidate ) {
                    $attrs = $ours->attrs( $block, $candidate );
                    $this->assertNotNull( $attrs, $id . ' ' . $block . ' ' . json_encode( $candidate ) );
                    $this->assertNotSame( [], get_object_vars( $attrs ), 'a patch writes something' );
                    $this->assertFalse( property_exists( $attrs, 'aied' ) );
                    $this->assertStringNotContainsString( '{{aied:', (string) $ours->json( $block, $candidate ) );
                }
            }
        }
    }

    public function testAMarkerThePipelineLeavesGivesNull(): void
    {
        $ours = new Expectations( Design::make( 'editorial', 'quiet-solid' ) );
        $this->assertNull( $ours->attrs( 'divi/button', [ 'kit' => 'card', 'on' => 'light' ] ), 'role not on this block' );
        $this->assertNull( $ours->attrs( 'divi/toggle', [ 'kit' => 'item-rule', 'on' => 'dark' ] ), 'band not taken' );
        $this->assertNull( $ours->attrs( 'divi/testimonial', [ 'kit' => 'card', 'on' => 'light', 'ground' => 'inverted' ] ), 'ground not on this block' );
        $this->assertNull( $ours->attrs( 'divi/button', [ 'kit' => 'bogus', 'on' => 'light' ] ), 'unknown role' );
        $this->assertNull( $ours->attrs( 'divi/text', [ 'type' => 'display' ] ), 'type role not on this block' );
        $this->assertNull( $ours->json( 'divi/section', [ 'rhythm' => 'bogus' ] ) );
    }

    public function testMemoisedAndDeterministic(): void
    {
        $a = new Expectations( Design::make( 'luxe', 'gilt-hairline' ) );
        $b = new Expectations( Design::make( 'luxe', 'gilt-hairline' ) );
        $m = [ 'kit' => 'control', 'on' => 'light' ];
        $this->assertSame( $a->attrs( 'divi/button', $m ), $a->attrs( 'divi/button', $m ), 'memoised: the same object' );
        $this->assertSame( $a->json( 'divi/button', $m ), $b->json( 'divi/button', $m ), 'same bytes from a fresh instance' );
        $this->assertNotSame( $a->json( 'divi/button', $m ), ( new Expectations( Design::make( 'editorial', 'quiet-solid' ) ) )->json( 'divi/button', $m ) );
    }
}
