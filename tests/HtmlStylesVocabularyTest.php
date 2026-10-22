<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The html_to_divi styles vocabulary (plan 2026-10-22-html-to-divi-styles, Task 1): the probe candidates
 * (fixtures/shapes/candidates/html-styles.json) and the "Adapter decisions" table of fixtures/shapes/html-styles-vocabulary.md.
 *
 * Until the controller has run the live probe, every verdict in the table is the literal PENDING-PROBE. Once it has, each
 * verdict is PASS, FAIL or DEAD (a decision whose path read DEAD). Flip REQUIRE_ALL_DECIDED to true (Task 7 does, when the
 * converter starts to read the table) and a remaining PENDING-PROBE fails. Task 7 adds the test that pins DiviMarkup::STYLE_PROVEN
 * to this table to this class.
 */
final class HtmlStylesVocabularyTest extends TestCase
{
    /** True once the live probe has decided every row: a PENDING-PROBE verdict then fails. */
    private const REQUIRE_ALL_DECIDED = true;

    private const VOCABULARY = __DIR__ . '/../fixtures/shapes/html-styles-vocabulary.md';
    private const CANDIDATES = __DIR__ . '/../fixtures/shapes/candidates/html-styles.json';

    private const KEYS = [
        'text-align-orientation', 'text-align-bodyfont', 'text-justify', 'heading-justify', 'websafe-family',
        'row-max-width', 'list-ink-ul', 'list-ink-ol', 'quote-ink', 'row-background', 'column-background',
        'column-padding', 'section-padding',
    ];

    private const H = 'title.decoration.font.font.desktop.value.';
    private const B = 'content.decoration.bodyFont.body.font.desktop.value.';
    private const O = 'module.advanced.text.text.desktop.value.orientation';

    /** id => [block, expect, set]. The brief's table, verbatim. */
    private const CANDIDATE_TABLE = [
        'hs-heading-color-control'       => [ 'heading', 'PASS', [ self::H . 'color' => '#dc2626' ] ],
        'hs-text-orientation-center'     => [ 'text', 'PASS', [ self::O => 'center' ] ],
        'hs-text-orientation-right'      => [ 'text', 'PASS', [ self::O => 'right' ] ],
        'hs-text-orientation-justified'  => [ 'text', null, [ self::O => 'justified' ] ],
        'hs-text-orientation-left'       => [ 'text', 'DEAD', [ self::O => 'left' ] ],
        'hs-text-bodyfont-align-center'  => [ 'text', null, [ self::B . 'textAlign' => 'center' ] ],
        'hs-text-bodyfont-align-justify' => [ 'text', null, [ self::B . 'textAlign' => 'justify' ] ],
        'hs-heading-align-justify'       => [ 'heading', null, [ self::H . 'textAlign' => 'justify' ] ],
        'hs-heading-family-georgia'      => [ 'heading', null, [ self::H . 'family' => 'Georgia' ] ],
        'hs-heading-family-courier'      => [ 'heading', null, [ self::H . 'family' => 'Courier New' ] ],
        'hs-text-family-georgia'         => [ 'text', null, [ self::B . 'family' => 'Georgia' ] ],
        'hs-text-ink-ul'                 => [ 'text', 'PASS', [ self::B . 'color' => '#dc2626' ] ],
        'hs-text-ink-ol'                 => [ 'text', 'PASS', [ self::B . 'color' => '#dc2626' ] ],
        'hs-text-ink-quote'              => [ 'text', null, [ self::B . 'color' => '#dc2626' ] ],
        'hs-row-max-width'               => [ 'row', 'PASS', [ 'module.decoration.sizing.desktop.value.maxWidth' => '600px' ] ],
        'hs-row-background'              => [ 'row', 'PASS', [ 'module.decoration.background.desktop.value.color' => '#1e293b' ] ],
        'hs-column-background'           => [ 'column', 'PASS', [ 'module.decoration.background.desktop.value.color' => '#1e293b' ] ],
        'hs-column-padding'              => [ 'column', 'PASS', [ 'module.decoration.spacing.desktop.value.padding.top' => '64px' ] ],
        'hs-section-padding'             => [ 'section', 'PASS', [ 'module.decoration.spacing.desktop.value.padding.left' => '48px' ] ],
        'hs-button-ghost'                => [ 'button', 'PASS', [
            'button.decoration.background.desktop.value.color' => 'rgba(255,255,255,0)',
            'button.decoration.font.font.desktop.value.color'  => '#e2e8f0',
        ] ],
    ];

    /** @return list<array<string,mixed>> */
    private function candidates(): array
    {
        $candidates = json_decode( (string) file_get_contents( self::CANDIDATES ), true );
        $this->assertIsArray( $candidates );
        return $candidates;
    }

    /** @return array<string,array{verdict:string,path:string,evidence:string}> key => row, in table order */
    private function decisionRows(): array
    {
        $text = (string) file_get_contents( self::VOCABULARY );
        $at   = strpos( $text, '## Adapter decisions' );
        $this->assertNotFalse( $at, 'the vocabulary file has an Adapter decisions table' );
        preg_match_all( '/^\|\s*([A-Za-z][A-Za-z-]+)\s*\|\s*([A-Za-z-]+)\s*\|([^|\n]*)\|([^|\n]*)\|\s*$/m', substr( $text, (int) $at ), $m, PREG_SET_ORDER );
        $rows = [];
        foreach ( $m as $row ) {
            if ( 'Decision' === $row[1] ) {
                continue;
            }
            $rows[ $row[1] ] = [ 'verdict' => $row[2], 'path' => trim( $row[3] ), 'evidence' => trim( $row[4] ) ];
        }
        return $rows;
    }

    public function testTheCandidatesAreExactlyTheBriefsTwentyInOrder(): void
    {
        $candidates = $this->candidates();
        $this->assertSame( array_keys( self::CANDIDATE_TABLE ), array_column( $candidates, 'id' ), 'twenty candidates, the brief\'s ids, in its order' );
        foreach ( $candidates as $c ) {
            [ $block, $expect, $set ] = self::CANDIDATE_TABLE[ $c['id'] ];
            $this->assertSame( $block, $c['block'], $c['id'] . ': block' );
            $this->assertSame( $expect, $c['expect'] ?? null, $c['id'] . ': expect' );
            $this->assertSame( $set, $c['set'], $c['id'] . ': set' );
            $this->assertNotSame( '', trim( (string) ( $c['note'] ?? '' ) ), $c['id'] . ' says what it proves' );
        }
    }

    public function testEveryCandidateIsMeasuredFromABaseThatDiffers(): void
    {
        $byId = [];
        foreach ( $this->candidates() as $c ) {
            $byId[ $c['id'] ] = $c;
        }
        // The text candidates need content that shows the effect.
        foreach ( [ 'hs-text-orientation-center', 'hs-text-orientation-right', 'hs-text-orientation-justified', 'hs-text-orientation-left', 'hs-text-bodyfont-align-center', 'hs-text-bodyfont-align-justify' ] as $id ) {
            $html = (string) ( $byId[ $id ]['base']['content']['innerContent']['desktop']['value'] ?? '' );
            $this->assertGreaterThan( 300, strlen( strip_tags( $html ) ), "$id: a paragraph long enough for three lines" );
        }
        $this->assertSame( '<ul><li>One</li><li>Two</li></ul>', $byId['hs-text-ink-ul']['base']['content']['innerContent']['desktop']['value'] );
        $this->assertSame( '<ol><li>One</li><li>Two</li></ol>', $byId['hs-text-ink-ol']['base']['content']['innerContent']['desktop']['value'] );
        $this->assertSame( '<blockquote><p>Quoted</p></blockquote>', $byId['hs-text-ink-quote']['base']['content']['innerContent']['desktop']['value'] );
        $this->assertGreaterThan( 100, strlen( (string) $byId['hs-heading-align-justify']['base']['title']['innerContent']['desktop']['value'] ), 'a headline that wraps over three lines at 400 px' );
        $this->assertTrue( $byId['hs-row-max-width']['geometry'] ?? false, 'the row max width is measured with geometry' );
        // A transparent fill over Divi's default reads DEAD: the ghost starts from a solid fill and a different label colour.
        $ghost = $byId['hs-button-ghost']['base']['button']['decoration'];
        $this->assertNotSame( 'rgba(255,255,255,0)', $ghost['background']['desktop']['value']['color'] );
        $this->assertNotSame( '#e2e8f0', $ghost['font']['font']['desktop']['value']['color'] );
        // Only the default-value check expects DEAD, and it starts from no orientation at all.
        $dead = array_keys( array_filter( $byId, static fn ( array $c ): bool => 'DEAD' === ( $c['expect'] ?? null ) ) );
        $this->assertSame( [ 'hs-text-orientation-left' ], $dead );
        $this->assertArrayNotHasKey( 'module', $byId['hs-text-orientation-left']['base'], 'left is measured against no orientation' );
        // A control must PASS: the heading colour.
        $this->assertSame( 'PASS', $byId['hs-heading-color-control']['expect'] );
    }

    public function testTheAdapterDecisionsAreThirteenRowsInTheBriefsOrder(): void
    {
        $this->assertSame( self::KEYS, array_keys( $this->decisionRows() ), 'the thirteen DiviMarkup::STYLE_PROVEN keys, in this order' );
    }

    public function testEveryVerdictIsDecidedOrPending(): void
    {
        $pending = [];
        foreach ( $this->decisionRows() as $key => $row ) {
            $this->assertContains( $row['verdict'], [ 'PASS', 'FAIL', 'DEAD', 'PENDING-PROBE' ], "$key: PASS, FAIL, DEAD or PENDING-PROBE" );
            if ( 'PENDING-PROBE' === $row['verdict'] ) {
                $pending[] = $key;
            }
        }
        if ( self::REQUIRE_ALL_DECIDED ) {
            $this->assertSame( [], $pending, 'the live probe decided every row (replace PENDING-PROBE with PASS or FAIL)' );
        } else {
            $this->addToAssertionCount( 1 );
        }
    }

    public function testEveryRowNamesRealCandidatesAndAPath(): void
    {
        $ids = array_column( $this->candidates(), 'id' );
        foreach ( $this->decisionRows() as $key => $row ) {
            $this->assertNotSame( '', $row['path'], "$key has a path" );
            preg_match_all( '/hs-[a-z0-9-]+/', $row['evidence'], $m );
            $this->assertNotSame( [], $m[0], "$key names evidence candidates" );
            foreach ( $m[0] as $id ) {
                $this->assertContains( $id, $ids, "$key: evidence $id is a candidate" );
            }
        }
        // The table names the candidates the brief assigns to each decision.
        $rows = $this->decisionRows();
        $this->assertStringContainsString( 'hs-text-orientation-center', $rows['text-align-orientation']['evidence'] );
        $this->assertStringContainsString( 'hs-text-orientation-right', $rows['text-align-orientation']['evidence'] );
        $this->assertStringContainsString( 'hs-text-bodyfont-align-center', $rows['text-align-bodyfont']['evidence'] );
        $this->assertStringContainsString( 'hs-text-orientation-justified', $rows['text-justify']['evidence'] );
        $this->assertStringContainsString( 'hs-text-bodyfont-align-justify', $rows['text-justify']['evidence'] );
        $this->assertStringContainsString( 'hs-heading-align-justify', $rows['heading-justify']['evidence'] );
        foreach ( [ 'hs-heading-family-georgia', 'hs-heading-family-courier', 'hs-text-family-georgia' ] as $id ) {
            $this->assertStringContainsString( $id, $rows['websafe-family']['evidence'] );
        }
        $this->assertStringContainsString( 'hs-row-max-width', $rows['row-max-width']['evidence'] );
    }

    public function testNoCandidateIsLeftOutOfTheVocabulary(): void
    {
        $text = (string) file_get_contents( self::VOCABULARY );
        foreach ( $this->candidates() as $c ) {
            $this->assertStringContainsString( $c['id'], $text, $c['id'] . ' appears in the vocabulary' );
        }
    }
}
