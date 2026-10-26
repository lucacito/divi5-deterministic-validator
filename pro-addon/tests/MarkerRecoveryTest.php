<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\Expectations;
use AiEditorDivi5\Pro\Design\Leaves;
use AiEditorDivi5\Pro\Design\MarkerRecovery;
use AiEditorDivi5\Pro\Design\PageScan;
use AiEditorDivi5\WP\BlockAttrs;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\LanguageAdapters;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

/**
 * Design switch, Task 3: marker recovery. Given a block of a page built under a SOURCE design, which marker produced its
 * values; proven on every marked block of the recipe library under the ten default designs, against every target.
 */
class MarkerRecoveryTest extends TestCase
{
    /**
     * Marked recipe blocks whose recovered marker is NOT equivalent to their own, or whose strip does not give back the
     * recipe's own literals (`recipe#block index@source language`, with the reason). SHRINK-ONLY: the test fails when an
     * entry no longer occurs (delete it) and when a new one appears (a STOP, not an edit).
     */
    private const EXCEPTIONS = [
        // D4 (spec section 6): under gilt-hairline the panel's own padding equals the kit's card padding, so the block
        // is indistinguishable from a card; the larger match wins and says so.
        'contact-location-form#20@luxe' => 'marker {"kit":"card","on":"light"} for {"kit":"panel","on":"light"} -> editorial; assumed: larger match preferred',
    ];

    /** @var array<string,Design> */
    private static array $designs = [];
    /** @var array<string,Expectations> */
    private static array $expectations = [];

    /** @return array<string,Design> the ten (language, default kit) designs */
    private static function defaults(): array
    {
        if ( [] === self::$designs ) {
            foreach ( DesignLanguages::ids() as $id ) {
                self::$designs[ $id ] = Design::make( $id, (string) DesignLanguages::get( $id )['kit'] );
            }
        }

        return self::$designs;
    }

    private static function exp( Design $d ): Expectations
    {
        $key = $d->languageId . '+' . $d->kitId;

        return self::$expectations[ $key ] ??= new Expectations( $d );
    }

    /** The raw recipe markup with image tokens resolved (as `SectionRecipes::recipe` does outside WordPress). */
    private static function raw( string $name ): string
    {
        return ImageTokens::resolve( (string) SectionRecipes::rawMarkup( $name ), '' );
    }

    /** The raw markup with every `aied` marker removed: resolved, it holds each block's own literals only. */
    private static function bare( string $raw ): string
    {
        return (string) preg_replace_callback( BlockAttrs::BLOCK, static function ( array $m ): string {
            $attrs = BlockAttrs::decode( $m[3] );
            if ( null === $attrs || ! property_exists( $attrs, 'aied' ) ) {
                return $m[0];
            }
            unset( $attrs->aied );

            return $m[1] . RecipeTokens::encodeJson( $attrs ) . $m[4];
        }, $raw );
    }

    private static function assoc( ?\stdClass $o ): mixed
    {
        return null === $o ? null : json_decode( (string) json_encode( $o ), true );
    }

    public function testEveryMarkedRecipeBlockIsRecovered(): void
    {
        $designs    = self::defaults();
        $found      = [];
        $checked    = 0;
        $statuses   = [];
        foreach ( $designs as $sid => $source ) {
            $src = self::exp( $source );
            foreach ( SectionRecipes::names() as $name ) {
                $raw      = self::raw( $name );
                $resolved = SectionRecipes::resolveMarkup( $raw, $source->language, $source->kit );
                $bare     = SectionRecipes::resolveMarkup( self::bare( $raw ), $source->language, $source->kit );
                $this->assertIsString( $resolved, $name );
                $this->assertIsString( $bare, $name );
                $rb = PageScan::scan( $raw, $source->language );
                $sb = PageScan::scan( $resolved, $source->language );
                $bb = PageScan::scan( $bare, $source->language );
                $this->assertCount( count( $rb ), $sb, $name );
                $this->assertCount( count( $rb ), $bb, $name );
                foreach ( $rb as $i => $b ) {
                    $own = null === $b['json'] ? null : BlockAttrs::decode( $b['json'] );
                    if ( null === $own || ! isset( $own->aied ) ) {
                        continue;
                    }
                    $marker = (array) $own->aied;
                    $label  = $name . '#' . $i . '@' . $sid;
                    $attrs  = BlockAttrs::decode( (string) $sb[ $i ]['json'] );
                    $this->assertNotNull( $attrs, $label );
                    $level = MarkerRecovery::headingLevel( $b['name'], $attrs );
                    $why   = null;
                    foreach ( $designs as $tid => $target ) {
                        $tgt = self::exp( $target );
                        $r   = MarkerRecovery::recover( $b['name'], $attrs, $sb[ $i ]['band'], $level, $src, $tgt );
                        $statuses[ $r['status'] ] = ( $statuses[ $r['status'] ] ?? 0 ) + 1;
                        ++$checked;
                        if ( ! in_array( $r['status'], [ 'kit', 'language' ], true ) ) {
                            $why = 'status ' . $r['status'];
                            break;
                        }
                        $this->assertSame( isset( $marker['kit'] ) ? 'kit' : 'language', $r['status'], $label );
                        if ( $src->json( $b['name'], $r['marker'] ) !== $src->json( $b['name'], $marker )
                            || $tgt->json( $b['name'], $r['marker'] ) !== $tgt->json( $b['name'], $marker ) ) {
                            $why = 'marker ' . json_encode( $r['marker'] ) . ' for ' . json_encode( $marker ) . ' -> ' . $tid . '; assumed: ' . $r['assumed'];
                            break;
                        }
                        $copy = BlockAttrs::decode( (string) $sb[ $i ]['json'] );
                        Leaves::strip( $copy, $r['patch'] );
                        if ( self::assoc( $copy ) != self::assoc( BlockAttrs::decode( (string) $bb[ $i ]['json'] ) ) ) {
                            $why = 'strip';
                            break;
                        }
                    }
                    if ( null !== $why ) {
                        $found[ $label ] = $why;
                    }
                }
            }
        }
        ksort( $found );
        $this->assertSame( self::EXCEPTIONS, $found, var_export( $found, true ) );
        $this->assertGreaterThan( 30000, $checked, 'every marked block against every target' );
    }

    private static function obj( string $json ): \stdClass
    {
        $o = BlockAttrs::decode( $json );
        self::assertNotNull( $o, $json );

        return $o;
    }

    /** A copy of the resolved expectation, to plant on a test block. */
    private static function planted( Expectations $e, string $block, array $marker ): \stdClass
    {
        return self::obj( (string) $e->json( $block, $marker ) );
    }

    public function testLeavesTreatListsAndEmptyObjectsAsOneLeaf(): void
    {
        $n = self::obj( '{"a":{"b":[1,{"c":2}],"e":{}},"f":"x","g":{"h":null}}' );
        $this->assertSame(
            [ 'a.b' => '[1,{"c":2}]', 'a.e' => '{}', 'f' => '"x"', 'g.h' => 'null' ],
            array_combine(
                array_map( static fn ( array $l ): string => implode( '.', $l[0] ), Leaves::of( $n ) ),
                array_map( static fn ( array $l ): string => Leaves::encode( $l[1] ), Leaves::of( $n ) )
            )
        );
        $this->assertSame( [], Leaves::of( new \stdClass() ) );
        $attrs = self::obj( '{"a":{"b":[1,{"c":2}],"e":{},"z":1},"f":"x"}' );
        $this->assertSame( 2, Leaves::count( $attrs, self::obj( '{"a":{"b":[1,{"c":2}],"e":{}}}' ) ) );
        $this->assertSame( -1, Leaves::count( $attrs, self::obj( '{"a":{"b":[1]}}' ) ), 'a list is compared whole' );
        $this->assertSame( -1, Leaves::count( $attrs, self::obj( '{"f":"x","q":"missing"}' ) ) );
        $this->assertSame( -1, Leaves::count( $attrs, self::obj( '{"a":{"z":"1"}}' ) ), 'a string is not a number' );
        $this->assertSame( -1, Leaves::count( $attrs, self::obj( '{"f":{"deeper":"x"}}' ) ), 'a scalar is not an object' );
        $this->assertSame( 0, Leaves::count( $attrs, new \stdClass() ) );
        $this->assertSame( 1, Leaves::agreeing( $attrs, self::obj( '{"f":"x","q":"missing","a":{"z":2}}' ) ) );
        $this->assertSame( [ 'q', 'a.z' ], Leaves::differing( $attrs, self::obj( '{"f":"x","q":"missing","a":{"z":2}}' ) ) );
        $this->assertTrue( Leaves::touches( $attrs, self::obj( '{"a":{"z":"anything"}}' ) ) );
        $this->assertFalse( Leaves::touches( $attrs, self::obj( '{"a":{"y":1},"g":{"h":1}}' ) ) );
    }

    public function testStripPrunesOnlyWhatItEmptied(): void
    {
        $attrs = self::obj( '{"a":{"b":{"c":1}},"keep":{},"s":{"t":{"u":1,"v":2}},"x":{"y":{}},"l":[1,2],"w":"stay"}' );
        Leaves::strip( $attrs, self::obj( '{"a":{"b":{"c":"any value"}},"s":{"t":{"u":1}},"x":{"y":{"z":1}},"l":[9],"missing":{"m":1}}' ) );
        $this->assertSame( '{"keep":{},"s":{"t":{"v":2}},"x":{"y":{}},"w":"stay"}', json_encode( $attrs ) );
    }

    public function testAPartialMatchIsDriftedAndLeft(): void
    {
        $d      = Design::make( 'editorial', 'quiet-solid' );
        $src    = self::exp( $d );
        $tgt    = self::exp( Design::make( 'luxe', 'gilt-hairline' ) );
        $marker = [ 'kit' => 'control', 'on' => 'light' ];
        $attrs  = self::planted( $src, 'divi/button', $marker );
        $paths  = array_values( array_filter(
            array_map( static fn ( array $l ): array => $l[0], Leaves::of( $attrs ) ),
            static fn ( array $p ): bool => str_contains( strtolower( implode( '.', $p ) ), 'radius' )
        ) );
        $this->assertNotSame( [], $paths, 'the control patch writes a radius' );
        $node = $attrs;
        foreach ( array_slice( $paths[0], 0, -1 ) as $k ) {
            $node = $node->{$k};
        }
        $node->{ end( $paths[0] ) } = '13px';
        $before = json_encode( $attrs );

        $r = MarkerRecovery::recover( 'divi/button', $attrs, 'light', null, $src, $tgt );
        $this->assertSame( 'drifted', $r['status'] );
        $this->assertSame( $marker, $r['marker'] );
        $this->assertSame( [ implode( '.', $paths[0] ) ], $r['differing'] );
        $this->assertSame( $before, json_encode( $attrs ), 'recovery never changes the block' );

        $exact = MarkerRecovery::recover( 'divi/button', self::planted( $src, 'divi/button', $marker ), 'light', null, $src, $tgt );
        $this->assertSame( 'kit', $exact['status'] );
        $this->assertSame( $marker, $exact['marker'] );
        $this->assertNull( $exact['assumed'] );
    }

    public function testFewAgreeingLeavesIsUnclassifiedAndNoDecorationIsNone(): void
    {
        $src   = self::exp( Design::make( 'editorial', 'quiet-solid' ) );
        $tgt   = self::exp( Design::make( 'luxe', 'gilt-hairline' ) );
        $patch = $src->attrs( 'divi/button', [ 'kit' => 'control', 'on' => 'light' ] );
        $this->assertGreaterThan( 2, count( Leaves::of( $patch ) ) );
        [ $path ] = Leaves::of( $patch )[0];
        $attrs = new \stdClass();
        $node  = $attrs;
        foreach ( array_slice( $path, 0, -1 ) as $k ) {
            $node = $node->{$k} = new \stdClass();
        }
        $node->{ end( $path ) } = 'a value no kit writes';
        $this->assertTrue( MarkerRecovery::isDecorated( 'divi/button', $attrs, $src ) );
        $this->assertSame( 'unclassified', MarkerRecovery::recover( 'divi/button', $attrs, 'light', null, $src, $tgt )['status'] );

        $plain = self::obj( '{"button":{"innerContent":{"desktop":{"value":{"text":"Go"}}}}}' );
        $this->assertFalse( MarkerRecovery::isDecorated( 'divi/button', $plain, $src ) );
        $this->assertSame( 'none', MarkerRecovery::recover( 'divi/button', $plain, 'light', null, $src, $tgt )['status'] );
        $this->assertSame( 'none', MarkerRecovery::recover( 'divi/image', $plain, 'light', null, $src, $tgt )['status'], 'no carrier' );
        $this->assertFalse( MarkerRecovery::isDecorated( 'divi/image', $plain, $src ) );
    }

    public function testHeadingLevelBreaksTheDisplayTitleTie(): void
    {
        $src = self::exp( Design::make( 'corporate-trust', 'steady-solid' ) );
        $tgt = self::exp( Design::make( 'editorial', 'quiet-solid' ) );
        $this->assertSame( $src->json( 'divi/heading', [ 'type' => 'display' ] ), $src->json( 'divi/heading', [ 'type' => 'title' ] ), 'corporate-trust: equal display and title triples' );
        $this->assertNotSame( $tgt->json( 'divi/heading', [ 'type' => 'display' ] ), $tgt->json( 'divi/heading', [ 'type' => 'title' ] ), 'they differ under the target' );
        foreach ( [ 'h1' => 'display', 'h2' => 'display', 'h3' => 'title' ] as $level => $role ) {
            $attrs = self::planted( $src, 'divi/heading', [ 'type' => $role ] );
            $attrs->title->decoration->font->font->desktop->value->headingLevel = $level;
            $this->assertSame( $level, MarkerRecovery::headingLevel( 'divi/heading', $attrs ) );
            $r = MarkerRecovery::recover( 'divi/heading', $attrs, 'light', MarkerRecovery::headingLevel( 'divi/heading', $attrs ), $src, $tgt );
            $this->assertSame( 'language', $r['status'], $level );
            $this->assertSame( [ 'type' => $role ], $r['marker'], $level );
            $this->assertNull( $r['assumed'], $level );
        }
        $r = MarkerRecovery::recover( 'divi/heading', self::planted( $src, 'divi/heading', [ 'type' => 'title' ] ), 'light', null, $src, $tgt );
        $this->assertSame( [ 'type' => 'display' ], $r['marker'], 'no level: the first in candidate order' );
        $this->assertSame( MarkerRecovery::ASSUMED_LEVEL_UNKNOWN, $r['assumed'] );
        $this->assertNull( MarkerRecovery::headingLevel( 'divi/heading', self::obj( '{"title":{}}' ) ) );
        $this->assertNull( MarkerRecovery::headingLevel( 'divi/button', self::obj( '{"title":{}}' ) ) );

        $same = MarkerRecovery::recover( 'divi/heading', self::planted( $src, 'divi/heading', [ 'type' => 'title' ] ), 'light', null, $src, self::exp( Design::make( 'corporate-trust', 'steady-solid' ) ) );
        $this->assertNull( $same['assumed'], 'a tie whose target patches agree is not a guess' );
    }

    public function testABandUnknownTieIsAssumed(): void
    {
        $src = self::exp( Design::make( 'editorial', 'quiet-solid' ) );
        $tgt = self::exp( Design::make( 'playful-pastel', 'chunky-pill-offset' ) );
        $light = [ 'kit' => 'control', 'on' => 'light' ];
        $dark  = [ 'kit' => 'control', 'on' => 'dark' ];
        $this->assertSame( $src->json( 'divi/button', $light ), $src->json( 'divi/button', $dark ), 'the same source patch' );
        $this->assertNotSame( $tgt->json( 'divi/button', $light ), $tgt->json( 'divi/button', $dark ), 'different target patches' );
        $cases = [
            'unknown' => [ $light, MarkerRecovery::ASSUMED_BAND_UNKNOWN ],
            'dark'    => [ $dark, null ],
            'light'   => [ $light, null ],
            'surface' => [ $light, null ], // a button reads a surface band as light
            'panel'   => [ $light, MarkerRecovery::ASSUMED_BAND_NOT_TAKEN ], // a known band neither tied candidate names
        ];
        foreach ( $cases as $band => [ $want, $assumed ] ) {
            $r = MarkerRecovery::recover( 'divi/button', self::planted( $src, 'divi/button', $light ), $band, null, $src, $tgt );
            $this->assertSame( 'kit', $r['status'], $band );
            $this->assertSame( $want, $r['marker'], $band );
            $this->assertSame( $assumed, $r['assumed'], $band );
        }
        $card = MarkerRecovery::recover( 'divi/group', self::planted( $src, 'divi/group', [ 'kit' => 'card', 'on' => 'light' ] ), 'panel', null, $src, $tgt );
        $this->assertSame( MarkerRecovery::ASSUMED_BAND_NOT_TAKEN . '; ' . MarkerRecovery::ASSUMED_LARGER, $card['assumed'], 'a known band no tied candidate names; the smaller panel match differs under the target' );
        $this->assertSame( [ 'kit' => 'card', 'on' => 'light' ], $card['marker'] );
    }

    public function testRecoveryIsDeterministic(): void
    {
        $src = self::exp( Design::make( 'bold-vibrant', 'heavy-block' ) );
        $tgt = self::exp( Design::make( 'tech-mono', 'hairline-dashed' ) );
        $raw = self::raw( 'pricing-three-tier' );
        $page = (string) SectionRecipes::resolveMarkup( $raw, $src->design->language, $src->design->kit );
        $runs = [];
        foreach ( [ 1, 2 ] as $run ) {
            $fresh = $run === 1 ? [ $src, $tgt ] : [ new Expectations( $src->design ), new Expectations( $tgt->design ) ];
            $out   = [];
            foreach ( PageScan::scan( $page, $src->design->language ) as $b ) {
                $attrs = null === $b['json'] ? null : BlockAttrs::decode( $b['json'] );
                if ( null !== $attrs ) {
                    $out[] = MarkerRecovery::recover( $b['name'], $attrs, $b['band'], MarkerRecovery::headingLevel( $b['name'], $attrs ), ...$fresh );
                }
            }
            $runs[] = json_encode( $out );
        }
        $this->assertSame( $runs[0], $runs[1] );
        $this->assertStringContainsString( '"status":"kit"', $runs[0] );
    }

    public function testTheFreeTypePathsArePinned(): void
    {
        $this->assertSame( 'title.decoration.font.font', LanguageAdapters::TYPE_PATHS['divi/heading'] ?? null );
        $this->assertArrayHasKey( 'divi/text', LanguageAdapters::TYPE_PATHS );
    }
}
