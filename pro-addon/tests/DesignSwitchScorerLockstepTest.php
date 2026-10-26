<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\DesignSwitch;
use AiEditorDivi5\Pro\Design\PageScan;
use AiEditorDivi5\Pro\Tests\Support\DesignDiffClassifier;
use AiEditorDivi5\Pro\Tests\Support\DesignSwitchMatrixOne;
use AiEditorDivi5\WP\BlockAttrs;
use AiEditorDivi5\WP\SectionRecipes;
use Divi5Validator\Tests\Support\Dogfood\Scorer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../tests/Support/Dogfood/Page.php';
require_once __DIR__ . '/../../tests/Support/Dogfood/Expected.php';
require_once __DIR__ . '/../../tests/Support/Dogfood/Scorer.php';
require_once __DIR__ . '/Support/DesignDiffClassifier.php';
require_once __DIR__ . '/Support/DesignSwitchMatrixOne.php';

/**
 * Design switch, Task 6 Step 3: the dogfood scorer (`tests/Support/Dogfood/Scorer.php`, dev tooling, an independent
 * judge written for another purpose) as an oracle of the switch (spec sections 4 and 9.4).
 */
class DesignSwitchScorerLockstepTest extends TestCase
{
    private const CORPUS = __DIR__ . '/../../fixtures/dogfood';

    /**
     * Corpus blocks the switch marks whose scorer checks fail under a target, as page block check => targets failing.
     * Measured 2026-10-26: 66 checks on 8 blocks. Two causes, both named:
     * - the block already fails the same check under its OWN design (the scorer judges a role the hand-written block does
     *   not follow, and the switch keeps the author's choice): heavy-block's dividers carry the accent-band rule colour on
     *   a panel band (rule.colour fails under bold-vibrant / heavy-block too); dotted-pill column#8 and underlined-label
     *   column#7 are rule-strong top rules the scorer reads as cards (framed.frame fails under the source too; the radius
     *   is the author's literal, kept by the switch because it is not part of the rule patch);
     * - edit-glass-edge columns #5-#7 are kit cards that ALSO carry a hand-written top rule (2px solid): the card patch is
     *   switched, the author's top rule is not part of it and stays solid, so under the one target whose kit draws dashed
     *   lines (tech-mono / hairline-dashed) the scorer's top-rule check fails (#6 also fails its frame colour there).
     * Exact, so it can neither grow nor shrink unseen.
     */
    private const CORPUS_FAILURES = [
        'dotted-pill divi/column#8 framed.frame'           => 10,
        'dotted-pill divi/column#8 framed.radius'          => 10,
        'edit-glass-edge divi/column#5 framed.top-rule'    => 1,
        'edit-glass-edge divi/column#6 framed.frame-colour' => 1,
        'edit-glass-edge divi/column#6 framed.top-rule'    => 1,
        'edit-glass-edge divi/column#7 framed.top-rule'    => 1,
        'heavy-block divi/divider#3 rule.colour'           => 8,
        'heavy-block divi/divider#4 rule.colour'           => 8,
        'heavy-block divi/divider#5 rule.colour'           => 8,
        'underlined-label divi/column#7 framed.frame'      => 10,
        'underlined-label divi/column#7 framed.radius'     => 8,
    ];

    /** The blocks of CORPUS_FAILURES that already fail the same check under their own design. */
    private const FAIL_UNDER_SOURCE = [
        'dotted-pill divi/column#8 framed.frame',
        'heavy-block divi/divider#3 rule.colour',
        'heavy-block divi/divider#4 rule.colour',
        'heavy-block divi/divider#5 rule.colour',
        'underlined-label divi/column#7 framed.frame',
    ];

    /**
     * Every recipe, source editorial / quiet-solid, the ten default targets (710 pairs): the scorer's report of the
     * switched page equals the direct build's check for check, except checks on blocks the classifier named (measured:
     * none, the named blocks are untyped texts and sliders the scorer does not judge).
     */
    public function testTheScorerSeesTheDirectBuild(): void
    {
        $source  = Design::make( 'editorial', 'quiet-solid' );
        $designs = DesignSwitchMatrixOne::defaultDesigns();
        $scorers = [];
        foreach ( $designs as $l => $d ) {
            $scorers[ $l ] = new Scorer( $d->kitId, $l );
        }
        $pairs    = 0;
        $excluded = 0;
        $checks   = 0;
        foreach ( SectionRecipes::names() as $recipe ) {
            $page = DesignSwitchMatrixOne::build( $recipe, $source );
            foreach ( $designs as $l => $to ) {
                ++$pairs;
                $direct = DesignSwitchMatrixOne::build( $recipe, $to );
                $out    = DesignSwitch::run( $page, $source, $to )->content;
                $named  = [];
                foreach ( DesignDiffClassifier::details( $out, $direct ) as $d ) {
                    $named[ $d['key'] ] = true;
                }
                $keep = static fn ( array $c ): bool => ! isset( $named[ $c['block'] ] );
                $x    = $scorers[ $l ]->details( $out );
                $y    = $scorers[ $l ]->details( $direct );
                $fx   = array_values( array_filter( $x, $keep ) );
                $excluded += count( $x ) - count( $fx );
                $checks   += count( $fx );
                $this->assertSame( array_values( array_filter( $y, $keep ) ), $fx, "$recipe editorial>$l" );
            }
        }
        $this->assertSame( 710, $pairs );
        $this->assertSame( 0, $excluded );
        $this->assertGreaterThan( 10000, $checks );
    }

    /**
     * The 8 corpus pages (hand-written and recipe-built) switched from their own design to the ten default targets: the
     * scorer runs on every result without throwing, and every block the switch marked (a top-level `aied` in the
     * unresolved page) passes all its checks under the target, except CORPUS_FAILURES.
     */
    public function testCorpusSwitchedBlocksPassUnderTheTarget(): void
    {
        $corpus = json_decode( (string) file_get_contents( self::CORPUS . '/corpus.json' ), true );
        $this->assertCount( 8, $corpus );
        $designs  = DesignSwitchMatrixOne::defaultDesigns();
        $cells    = 0;
        $checked  = 0;
        $failures = [];
        foreach ( $corpus as $e ) {
            $page = (string) file_get_contents( self::CORPUS . '/' . $e['page'] );
            $from = Design::make( $e['language'], $e['kit'] );
            foreach ( $designs as $l => $to ) {
                ++$cells;
                $r = DesignSwitch::run( $page, $from, $to );
                $this->assertNull( $r->error, $e['name'] . ' > ' . $l );
                $marked = self::markedKeys( DesignSwitch::unresolve( $page, $from, $to ) );
                foreach ( ( new Scorer( $to->kitId, $l ) )->details( $r->content ) as $c ) {
                    if ( ! isset( $marked[ $c['block'] ] ) ) {
                        continue;
                    }
                    ++$checked;
                    if ( ! $c['pass'] ) {
                        $key              = $e['name'] . ' ' . $c['block'] . ' ' . $c['check'];
                        $failures[ $key ] = ( $failures[ $key ] ?? 0 ) + 1;
                    }
                }
            }
        }
        ksort( $failures, SORT_STRING );
        $this->assertSame( 80, $cells );
        $this->assertSame( 6585, $checked );
        $this->assertSame( self::CORPUS_FAILURES, $failures );

        // The first cause, checked: those blocks fail the same check under their own design.
        $under = [];
        foreach ( $corpus as $e ) {
            $page = (string) file_get_contents( self::CORPUS . '/' . $e['page'] );
            foreach ( ( new Scorer( $e['kit'], $e['language'] ) )->details( $page ) as $c ) {
                $key = $e['name'] . ' ' . $c['block'] . ' ' . $c['check'];
                if ( ! $c['pass'] && isset( self::CORPUS_FAILURES[ $key ] ) ) {
                    $under[] = $key;
                }
            }
        }
        sort( $under, SORT_STRING );
        $this->assertSame( self::FAIL_UNDER_SOURCE, $under );
    }

    /** @return array<string,true> scorer keys (`name#n`) of the blocks carrying a top-level `aied` marker */
    private static function markedKeys( string $unresolved ): array
    {
        $keys   = [];
        $counts = [];
        foreach ( PageScan::scan( $unresolved, [] ) as $b ) {
            $name = $b['name'];
            if ( null === $b['parent'] && 'divi/placeholder' === $name ) {
                continue;
            }
            $counts[ $name ] = ( $counts[ $name ] ?? -1 ) + 1;
            if ( null === $b['json'] ) {
                continue;
            }
            $attrs = BlockAttrs::decode( $b['json'] );
            if ( null !== $attrs && property_exists( $attrs, 'aied' ) ) {
                $keys[ $name . '#' . $counts[ $name ] ] = true;
            }
        }

        return $keys;
    }
}
