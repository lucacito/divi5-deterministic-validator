<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StudioBrief;
use AiEditorDivi5\WP\StudioMerge;
use AiEditorDivi5\WP\StudioSlots;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/autoload.php';

/** Studio screen task 2: merging slot values into one section of a brief, and swapping a section's recipe. */
class StudioMergeTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function brief(): array
    {
        return [
            'version'  => 1,
            'title'    => 'Acme',
            'goal'     => 'book',
            'language' => 'editorial',
            'kit'      => 'quiet-solid',
            'sections' => [
                [ 'recipe' => 'hero-cta', 'slots' => [ 'heading.1' => 'Fix leaks fast', 'button.0.text' => 'Book now' ] ],
                [ 'recipe' => 'card-grid-3', 'slots' => [ 'text.0.body.0' => 'What we do' ] ],
                [ 'recipe' => 'faq-accordion' ],
            ],
        ];
    }

    public function testMergePartialWriteKeepsOtherValues(): void
    {
        $r = StudioMerge::slots( self::brief(), 0, [ 'button.0.link' => '/contact/' ] );
        $this->assertSame( [ 'button.0.link' ], $r['applied'] );
        $this->assertSame( [], $r['ignored'] );
        $this->assertSame( [ 'heading.1' => 'Fix leaks fast', 'button.0.text' => 'Book now', 'button.0.link' => '/contact/' ], $r['brief']['sections'][0]['slots'] );
        $again = StudioMerge::slots( $r['brief'], 0, [ 'heading.1' => 'Stop leaks' ] );
        $this->assertSame( 'Stop leaks', $again['brief']['sections'][0]['slots']['heading.1'] );
        $this->assertSame( 'Book now', $again['brief']['sections'][0]['slots']['button.0.text'] );
    }

    public function testMergeCanFillASectionWithNoSlotsYet(): void
    {
        $r = StudioMerge::slots( self::brief(), 2, [ 'heading.1' => 'Questions' ] );
        $this->assertSame( [ 'heading.1' => 'Questions' ], $r['brief']['sections'][2]['slots'] );
    }

    public function testMergeEmptyStringClears(): void
    {
        $r = StudioMerge::slots( self::brief(), 0, [ 'heading.1' => '' ] );
        $this->assertSame( [ 'heading.1' ], $r['applied'] );
        $this->assertArrayNotHasKey( 'heading.1', $r['brief']['sections'][0]['slots'] );
        $this->assertSame( 'Book now', $r['brief']['sections'][0]['slots']['button.0.text'] );
        $last = StudioMerge::slots( $r['brief'], 0, [ 'button.0.text' => '' ] );
        $this->assertArrayNotHasKey( 'slots', $last['brief']['sections'][0], 'an empty slots map is dropped' );
        $this->assertTrue( StudioBrief::check( $last['brief'] )['ok'] );
    }

    public function testMergeUnknownSlotIsIgnoredNotWritten(): void
    {
        $r = StudioMerge::slots( self::brief(), 0, [ 'nope.9' => 'x', 'heading.0' => 'Eyebrow' ] );
        $this->assertSame( [ 'heading.0' ], $r['applied'] );
        $this->assertSame( [ 'nope.9' ], $r['ignored'] );
        $this->assertArrayNotHasKey( 'nope.9', $r['brief']['sections'][0]['slots'] );
        $bad = StudioMerge::slots( self::brief(), 7, [ 'heading.0' => 'x' ] );
        $this->assertSame( self::brief(), $bad['brief'] );
        $this->assertSame( [ 'heading.0' ], $bad['ignored'] );
        $this->assertSame( [], $bad['applied'] );
    }

    public function testMergeNeverTouchesOtherSections(): void
    {
        $before = self::brief();
        $r      = StudioMerge::slots( $before, 1, [ 'text.0.body.0' => 'Services', 'text.1.body.0' => 'Eyebrow' ] );
        foreach ( [ 0, 2 ] as $i ) {
            $this->assertSame( $before['sections'][ $i ], $r['brief']['sections'][ $i ] );
        }
        foreach ( [ 'version', 'title', 'goal', 'language', 'kit' ] as $k ) {
            $this->assertSame( $before[ $k ], $r['brief'][ $k ] );
        }
        $this->assertSame( 'Services', $r['brief']['sections'][1]['slots']['text.0.body.0'] );
    }

    public function testMergeRoutesPicturesToTheirOwnMap(): void
    {
        $r = StudioMerge::slots( self::brief(), 0, [ 'section.0.background' => [ 'pack' => 'hero-blue-1' ], 'heading.0' => 'Hi' ] );
        $this->assertSame( [ 'pack' => 'hero-blue-1' ], $r['brief']['sections'][0]['pictures']['section.0.background'] );
        $this->assertArrayNotHasKey( 'section.0.background', $r['brief']['sections'][0]['slots'] );
        $this->assertTrue( StudioBrief::check( $r['brief'] )['ok'] );
        $cleared = StudioMerge::slots( $r['brief'], 0, [ 'section.0.background' => '' ] );
        $this->assertArrayNotHasKey( 'pictures', $cleared['brief']['sections'][0] );
        // A text slot given a non-string, and a picture slot given a string, are not written.
        $wrong = StudioMerge::slots( self::brief(), 0, [ 'heading.0' => [ 'pack' => 'x' ], 'section.0.background' => 'text' ] );
        $this->assertSame( [], $wrong['applied'] );
        $this->assertEqualsCanonicalizing( [ 'heading.0', 'section.0.background' ], $wrong['ignored'] );
    }

    public function testSwapRecipeKeepsMatchingSlotsAndReportsTheRest(): void
    {
        $brief = self::brief();
        $brief['sections'][0]['slots']['button.0.link'] = '/contact/';
        $brief['sections'][0]['pictures']                = [ 'section.0.background' => [ 'pack' => 'hero-blue-1' ] ];
        $new                                             = 'hero-pattern-centered';
        $newIds                                          = array_column( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $new ) ), 'id' );
        $r                                               = StudioMerge::swapRecipe( $brief, 0, $new );
        $this->assertSame( $new, $r['brief']['sections'][0]['recipe'] );
        $kept    = [];
        $dropped = [];
        foreach ( [ 'heading.1', 'button.0.text', 'button.0.link', 'section.0.background' ] as $id ) {
            if ( in_array( $id, $newIds, true ) ) {
                $kept[] = $id;
            } else {
                $dropped[] = $id;
            }
        }
        $this->assertSame( $kept, $r['kept'] );
        $this->assertSame( $dropped, $r['dropped'] );
        $this->assertNotSame( [], $r['dropped'], 'the test needs a recipe that loses something' );
        foreach ( $r['dropped'] as $id ) {
            $this->assertArrayNotHasKey( $id, $r['brief']['sections'][0]['slots'] ?? [] );
            $this->assertArrayNotHasKey( $id, $r['brief']['sections'][0]['pictures'] ?? [] );
        }
        $this->assertTrue( StudioBrief::check( $r['brief'] )['ok'], json_encode( StudioBrief::check( $r['brief'] )['problems'] ) );
        $this->assertSame( self::brief()['sections'][1], $r['brief']['sections'][1] );
    }

    public function testSwapToTheSameRecipeKeepsEverything(): void
    {
        $r = StudioMerge::swapRecipe( self::brief(), 0, 'hero-cta' );
        $this->assertSame( self::brief(), $r['brief'] );
        $this->assertSame( [ 'heading.1', 'button.0.text' ], $r['kept'] );
        $this->assertSame( [], $r['dropped'] );
    }

    public function testSwapToAnUnknownRecipeOrSectionChangesNothing(): void
    {
        foreach ( [ StudioMerge::swapRecipe( self::brief(), 0, 'no-such' ), StudioMerge::swapRecipe( self::brief(), 9, 'hero-cta' ) ] as $r ) {
            $this->assertSame( self::brief(), $r['brief'] );
            $this->assertSame( [], $r['kept'] );
            $this->assertSame( [], $r['dropped'] );
        }
    }
}
