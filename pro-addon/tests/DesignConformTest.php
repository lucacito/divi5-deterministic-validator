<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\DesignSwitch;
use AiEditorDivi5\Pro\Design\Leaves;
use AiEditorDivi5\Pro\Design\PageScan;
use AiEditorDivi5\Pro\Design\SwitchResult;
use AiEditorDivi5\WP\BlockAttrs;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use Divi5Validator\Tests\Support\Dogfood\Scorer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../tests/Support/Dogfood/Page.php';
require_once __DIR__ . '/../../tests/Support/Dogfood/Expected.php';
require_once __DIR__ . '/../../tests/Support/Dogfood/Scorer.php';

/**
 * Design switch, Task 7: the opt-in `conform` mode (spec 2026-10-25-pro-design-switch-design.md section 5.6).
 *
 * A `drifted` block (no full match, its nearest kit candidate agrees on at least half its leaves) loses EVERY leaf at a
 * path that candidate's source patch writes and gets the marker back, so the target kit styles it; each removed leaf
 * whose value differed is reported under `overridden`. The oracle is the independent dev scorer
 * (`tests/Support/Dogfood/Scorer.php`, not modified): the 8 dogfood corpus pages switched to the ten default designs
 * (80 cells) are scored for the TARGET design in both modes; every figure is pinned. `leave` mode is pinned byte for byte
 * to the Task 5 output (hashes measured at 105406d before this task).
 */
class DesignConformTest extends TestCase
{
    /**
     * page => target language => [ leave passed, leave checked, conform passed, conform checked, conformed blocks ],
     * measured with the scorer for the target (language, default kit). A cell whose target is the page's own design is
     * the identity (nothing switched, nothing conformed).
     */
    private const CELLS = [
        'hairline-dashed' => [ 'editorial' => [ 134, 183, 158, 187, 6 ], 'glass-dark' => [ 135, 197, 159, 202, 6 ], 'organic-warm' => [ 141, 183, 165, 187, 6 ], 'tech-mono' => [ 166, 188, 166, 188, 0 ], 'playful-pastel' => [ 160, 197, 186, 202, 6 ], 'corporate-trust' => [ 144, 192, 168, 196, 6 ], 'soft-saas' => [ 135, 188, 159, 193, 6 ], 'bold-vibrant' => [ 123, 188, 149, 193, 6 ], 'brutalist-flat' => [ 130, 188, 156, 193, 6 ], 'luxe' => [ 153, 188, 170, 193, 6 ] ],
        'heavy-block' => [ 'editorial' => [ 113, 174, 122, 170, 7 ], 'glass-dark' => [ 115, 185, 131, 185, 7 ], 'organic-warm' => [ 113, 174, 122, 170, 7 ], 'tech-mono' => [ 104, 176, 128, 176, 7 ], 'playful-pastel' => [ 125, 185, 138, 185, 7 ], 'corporate-trust' => [ 123, 183, 132, 179, 7 ], 'soft-saas' => [ 115, 176, 128, 176, 7 ], 'bold-vibrant' => [ 153, 176, 153, 176, 0 ], 'brutalist-flat' => [ 112, 176, 128, 176, 7 ], 'luxe' => [ 119, 176, 135, 176, 7 ] ],
        'dotted-pill' => [ 'editorial' => [ 96, 133, 102, 132, 1 ], 'glass-dark' => [ 90, 135, 98, 135, 1 ], 'organic-warm' => [ 97, 133, 102, 132, 1 ], 'tech-mono' => [ 108, 135, 116, 135, 1 ], 'playful-pastel' => [ 123, 135, 126, 135, 1 ], 'corporate-trust' => [ 102, 133, 109, 132, 1 ], 'soft-saas' => [ 91, 135, 98, 135, 1 ], 'bold-vibrant' => [ 88, 135, 95, 135, 1 ], 'brutalist-flat' => [ 88, 135, 95, 135, 1 ], 'luxe' => [ 101, 135, 109, 135, 1 ] ],
        'underlined-label' => [ 'editorial' => [ 64, 134, 101, 138, 15 ], 'glass-dark' => [ 89, 134, 135, 144, 15 ], 'organic-warm' => [ 64, 134, 101, 138, 15 ], 'tech-mono' => [ 55, 134, 114, 144, 15 ], 'playful-pastel' => [ 92, 134, 135, 144, 15 ], 'corporate-trust' => [ 71, 134, 108, 138, 15 ], 'soft-saas' => [ 82, 134, 128, 144, 15 ], 'bold-vibrant' => [ 79, 134, 121, 144, 15 ], 'brutalist-flat' => [ 74, 134, 101, 144, 15 ], 'luxe' => [ 73, 134, 115, 144, 15 ] ],
        'soft-rounded' => [ 'editorial' => [ 123, 161, 133, 161, 5 ], 'glass-dark' => [ 127, 167, 132, 167, 5 ], 'organic-warm' => [ 144, 161, 154, 161, 5 ], 'tech-mono' => [ 122, 167, 137, 167, 5 ], 'playful-pastel' => [ 129, 167, 139, 167, 5 ], 'corporate-trust' => [ 144, 161, 154, 161, 5 ], 'soft-saas' => [ 127, 167, 132, 167, 5 ], 'bold-vibrant' => [ 113, 167, 123, 167, 5 ], 'brutalist-flat' => [ 113, 167, 123, 167, 5 ], 'luxe' => [ 134, 167, 144, 167, 5 ] ],
        'gilt-hairline' => [ 'editorial' => [ 135, 185, 135, 185, 1 ], 'glass-dark' => [ 140, 191, 140, 191, 1 ], 'organic-warm' => [ 128, 164, 128, 164, 1 ], 'tech-mono' => [ 139, 170, 140, 170, 1 ], 'playful-pastel' => [ 134, 152, 135, 152, 1 ], 'corporate-trust' => [ 135, 164, 135, 164, 1 ], 'soft-saas' => [ 134, 191, 134, 191, 1 ], 'bold-vibrant' => [ 133, 170, 134, 170, 1 ], 'brutalist-flat' => [ 120, 152, 121, 152, 1 ], 'luxe' => [ 179, 191, 179, 191, 0 ] ],
        'edit-glass-edge-base' => [ 'editorial' => [ 143, 143, 147, 148, 1 ], 'glass-dark' => [ 149, 149, 149, 149, 0 ], 'organic-warm' => [ 143, 143, 146, 148, 1 ], 'tech-mono' => [ 149, 149, 153, 154, 1 ], 'playful-pastel' => [ 134, 134, 138, 139, 1 ], 'corporate-trust' => [ 143, 143, 147, 148, 1 ], 'soft-saas' => [ 149, 149, 152, 154, 1 ], 'bold-vibrant' => [ 149, 149, 152, 154, 1 ], 'brutalist-flat' => [ 149, 149, 154, 154, 1 ], 'luxe' => [ 149, 149, 152, 154, 1 ] ],
        'edit-glass-edge' => [ 'editorial' => [ 128, 169, 137, 170, 5 ], 'glass-dark' => [ 145, 183, 145, 183, 0 ], 'organic-warm' => [ 133, 169, 141, 170, 5 ], 'tech-mono' => [ 132, 171, 146, 176, 5 ], 'playful-pastel' => [ 128, 165, 142, 170, 5 ], 'corporate-trust' => [ 148, 181, 157, 182, 5 ], 'soft-saas' => [ 145, 171, 148, 176, 5 ], 'bold-vibrant' => [ 126, 171, 139, 176, 5 ], 'brutalist-flat' => [ 115, 147, 130, 152, 5 ], 'luxe' => [ 135, 171, 143, 176, 5 ] ],
    ];

    /**
     * Shrink-only: "<page> <scorer block key> <engine role>><scorer role>" => cells, where the scorer's best role for a
     * conformed block is not the role the engine gave it. underlined-label's five groups: the engine's nearest candidate is
     * `panel`, the scorer's tie goes to `card` (its first framed role); every check passes. The edit page's group #10 is an
     * unmarked badge on the accent band that the engine conforms as a `chip` and the scorer judges as a `card`: the only
     * conformed block that fails scorer checks (frame colour, radius).
     */
    private const DISAGREEMENTS = [
        'underlined-label divi/group#5 panel>card' => 10,
        'underlined-label divi/group#6 panel>card' => 10,
        'underlined-label divi/group#7 panel>card' => 10,
        'underlined-label divi/group#8 panel>card' => 10,
        'underlined-label divi/group#9 panel>card' => 10,
        'edit-glass-edge-base divi/group#10 chip>card' => 9,
        'edit-glass-edge divi/group#10 chip>card' => 9,
    ];

    /** sha256 over every `leave` switch of the corpus (page, target, content), measured on Task 5's head 105406d. */
    private const LEAVE_CORPUS_CONTENT = '31dd40d2a68fe504cf29d9f1edea16ec5298456e15f5b67d3cff76fb2bdda39b';

    /** sha256 over the `toArray(1000)` reports of the same switches, Task 5's keys only (the new lists are empty). */
    private const LEAVE_CORPUS_REPORT = 'f0d4d31c0ffb2b43604359a8d1d370cbbc8c71a96a5a8c2b7baeee5fe1b82b68';

    /** sha256 over every recipe built under editorial and switched (`leave`) to the ten default designs (710 switches). */
    private const LEAVE_LIBRARY_CONTENT = 'c4fa6d7dd2ad66cda2185e44c2207f26663fee7a16c27466acabfa14e5efc9ad';

    /** @var list<array{name:string, from:Design, markup:string}>|null */
    private static ?array $corpus = null;

    /** @var array<string,array{leave:SwitchResult, conform:SwitchResult}> "<page> <target>" => both runs */
    private static array $runs = [];

    /** @return list<array{name:string, from:Design, markup:string}> */
    private static function corpus(): array
    {
        if ( null === self::$corpus ) {
            $dir  = __DIR__ . '/../../fixtures/dogfood/';
            $list = json_decode( (string) file_get_contents( $dir . 'corpus.json' ), true );
            self::$corpus = [];
            foreach ( $list as $e ) {
                self::$corpus[] = [ 'name' => $e['name'], 'from' => Design::make( $e['language'], $e['kit'] ), 'markup' => (string) file_get_contents( $dir . $e['page'] ) ];
            }
        }

        return self::$corpus;
    }

    private static function own( string $language ): Design
    {
        return Design::make( $language, (string) DesignLanguages::get( $language )['kit'] );
    }

    /** @return array{leave:SwitchResult, conform:SwitchResult} */
    private static function runs( array $e, string $language ): array
    {
        return self::$runs[ $e['name'] . ' ' . $language ] ??= [
            'leave'   => DesignSwitch::run( $e['markup'], $e['from'], self::own( $language ) ),
            'conform' => DesignSwitch::run( $e['markup'], $e['from'], self::own( $language ), 'conform' ),
        ];
    }

    /** PageScan index => the scorer's block key (`<name>#<ordinal>`, the dev `Page` numbering). */
    private static function keys( string $page, Design $d ): array
    {
        $seen = [];
        $keys = [];
        foreach ( PageScan::scan( $page, $d->language ) as $i => $b ) {
            $seen[ $b['name'] ] = ( $seen[ $b['name'] ] ?? -1 ) + 1;
            $keys[ $i ]         = $b['name'] . '#' . $seen[ $b['name'] ];
        }

        return $keys;
    }

    /** @param array<string,string> $marker */
    private static function label( array $marker ): string
    {
        return $marker['kit'] . ( isset( $marker['ground'] ) ? '+' . $marker['ground'] : '' );
    }

    private static function build( string $recipe, Design $d ): string
    {
        return (string) SectionRecipes::resolveMarkup( ImageTokens::resolve( (string) SectionRecipes::rawMarkup( $recipe ), '' ), $d->language, $d->kit );
    }

    public function testLeaveModeIsUnchanged(): void
    {
        $content = hash_init( 'sha256' );
        $report  = hash_init( 'sha256' );
        foreach ( self::corpus() as $e ) {
            foreach ( DesignLanguages::ids() as $l ) {
                $r = self::runs( $e, $l )['leave'];
                hash_update( $content, $e['name'] . $l . "\0" . $r->content . "\0" );
                $a = $r->toArray( 1000 );
                $this->assertSame( [], $r->conformed );
                $this->assertSame( [], $r->overridden );
                unset( $a['conformed'], $a['conformed_count'], $a['overridden'], $a['overridden_count'] );
                hash_update( $report, (string) json_encode( $a ) );
            }
        }
        $this->assertSame( self::LEAVE_CORPUS_CONTENT, hash_final( $content ) );
        $this->assertSame( self::LEAVE_CORPUS_REPORT, hash_final( $report ) );

        $library = hash_init( 'sha256' );
        $from    = self::own( 'editorial' );
        $n       = 0;
        foreach ( SectionRecipes::names() as $recipe ) {
            $page = self::build( $recipe, $from );
            foreach ( DesignLanguages::ids() as $l ) {
                $leave = DesignSwitch::run( $page, $from, self::own( $l ) );
                hash_update( $library, $recipe . $l . "\0" . $leave->content . "\0" );
                ++$n;
                // A recipe-built page has no drifted block: conform writes the same bytes.
                $this->assertSame( $leave->content, DesignSwitch::run( $page, $from, self::own( $l ), 'conform' )->content, "$recipe -> $l" );
            }
        }
        $this->assertSame( 710, $n );
        $this->assertSame( self::LEAVE_LIBRARY_CONTENT, hash_final( $library ) );
    }

    /** The pinned scorer figures per cell; conform never passes fewer checks than leave. */
    public function testTheCorpusScoresUnderTheTarget(): void
    {
        $this->assertSame( array_map( static fn ( array $e ): string => $e['name'], self::corpus() ), array_keys( self::CELLS ) );
        $sum = [ 0, 0, 0, 0, 0 ];
        foreach ( self::corpus() as $e ) {
            foreach ( DesignLanguages::ids() as $l ) {
                $runs   = self::runs( $e, $l );
                $scorer = new Scorer( self::own( $l )->kitId, $l );
                $leave  = $scorer->score( $runs['leave']->content )['overall'];
                $conf   = $scorer->score( $runs['conform']->content )['overall'];
                $got    = [ $leave['passed'], $leave['checked'], $conf['passed'], $conf['checked'], count( $runs['conform']->conformed ) ];
                $this->assertSame( self::CELLS[ $e['name'] ][ $l ], $got, $e['name'] . ' -> ' . $l );
                $this->assertGreaterThanOrEqual( $leave['passed'], $conf['passed'], $e['name'] . ' -> ' . $l );
                foreach ( $got as $i => $v ) {
                    $sum[ $i ] += $v;
                }
            }
        }
        $this->assertSame( [ 9785, 12894, 10832, 13081, 390 ], $sum );
    }

    /**
     * Every conformed block on which the scorer agrees with the engine's role passes all its scorer checks; the blocks
     * that fail a check are exactly the pinned disagreements that do.
     */
    public function testConformedBlocksScoreFullyUnderTheTarget(): void
    {
        $failing = [];
        foreach ( self::corpus() as $e ) {
            foreach ( DesignLanguages::ids() as $l ) {
                $to   = self::own( $l );
                $r    = self::runs( $e, $l )['conform'];
                $keys = self::keys( $r->content, $to );
                $by   = [];
                foreach ( ( new Scorer( $to->kitId, $l ) )->details( $r->content ) as $row ) {
                    $by[ $row['block'] ][] = $row;
                }
                foreach ( $r->conformed as $c ) {
                    $key  = $keys[ $c['block'] ];
                    $rows = $by[ $key ] ?? [];
                    $this->assertNotSame( [], $rows, "{$e['name']} -> $l $key is scored" );
                    $bad = array_filter( $rows, static fn ( array $row ): bool => ! $row['pass'] );
                    if ( [] === $bad ) {
                        continue;
                    }
                    $this->assertNotSame( self::label( $c['marker'] ), $rows[0]['role'], "{$e['name']} -> $l $key fails a check under the engine's own role" );
                    $failing[ $e['name'] . ' ' . $key ] = true;
                }
            }
        }
        ksort( $failing );
        $this->assertSame( [ 'edit-glass-edge divi/group#10', 'edit-glass-edge-base divi/group#10' ], array_keys( $failing ) );
    }

    public function testTheScorerAgreesWithTheEnginesRole(): void
    {
        $dis = [];
        foreach ( self::corpus() as $e ) {
            foreach ( DesignLanguages::ids() as $l ) {
                $to   = self::own( $l );
                $r    = self::runs( $e, $l )['conform'];
                $keys = self::keys( $r->content, $to );
                $by   = [];
                foreach ( ( new Scorer( $to->kitId, $l ) )->details( $r->content ) as $row ) {
                    $by[ $row['block'] ] ??= $row;
                }
                foreach ( $r->conformed as $c ) {
                    $key    = $keys[ $c['block'] ];
                    $scorer = $by[ $key ]['role'] ?? 'none';
                    $engine = self::label( $c['marker'] );
                    if ( $scorer !== $engine ) {
                        $k         = "{$e['name']} $key $engine>$scorer";
                        $dis[ $k ] = ( $dis[ $k ] ?? 0 ) + 1;
                    } else {
                        $band = $by[ $key ]['band'];
                        $this->assertTrue( $band === $c['marker']['on'] || ( 'surface' === $band && 'light' === $c['marker']['on'] ), "{$e['name']} -> $l $key band" );
                    }
                }
            }
        }
        foreach ( $dis as $k => $n ) {
            $this->assertArrayHasKey( $k, self::DISAGREEMENTS, 'a new disagreement (shrink-only list)' );
            $this->assertLessThanOrEqual( self::DISAGREEMENTS[ $k ], $n, $k );
        }
        $this->assertSame( self::DISAGREEMENTS, $dis, 'a disagreement went away: shrink the list' );
    }

    /** A planted drift: one leaf changed, one removed. The changed one is reported with its old value; the missing one is not. */
    public function testEveryOverriddenLeafIsReported(): void
    {
        $from   = self::own( 'editorial' );
        $to     = self::own( 'luxe' );
        $marker = '<!-- wp:divi/button {"aied":{"kit":"control","on":"light"},"button":{"innerContent":{"desktop":{"value":{"text":"Go"}}}}} /-->';
        $built  = (string) SectionRecipes::resolveMarkup( $marker, $from->language, $from->kit );
        $direct = (string) SectionRecipes::resolveMarkup( $marker, $to->language, $to->kit );
        preg_match( '/^<!-- wp:divi\/button (\{.*\}) \/-->$/s', $built, $m );
        $attrs  = BlockAttrs::decode( $m[1] );
        $patch  = DesignSwitch::expectations( $from )->attrs( 'divi/button', [ 'kit' => 'control', 'on' => 'light' ] );
        $leaves = Leaves::of( $patch );
        $this->assertGreaterThanOrEqual( 4, count( $leaves ) );
        [ $changePath ] = $leaves[0];
        [ $dropPath ]   = $leaves[1];
        self::setAt( $attrs, $changePath, 'planted' );
        self::unsetAt( $attrs, $dropPath );
        $page = '<!-- wp:divi/button ' . RecipeTokens::encodeJson( $attrs ) . ' /-->';

        $leave = DesignSwitch::run( $page, $from, $to );
        $this->assertCount( 1, $leave->left['drifted'], 'leave: drifted and listed' );
        $this->assertSame( [], $leave->overridden );

        $r = DesignSwitch::run( $page, $from, $to, 'conform' );
        $this->assertSame( [], $r->left['drifted'] );
        $this->assertCount( 1, $r->conformed );
        $this->assertSame( [ 'kit' => 'control', 'on' => 'light' ], $r->conformed[0]['marker'] );
        $this->assertSame( 1, $r->conformed[0]['overridden'] );
        $this->assertSame( [ [ 'block' => 0, 'name' => 'divi/button', 'path' => implode( '.', $changePath ), 'old' => 'planted' ] ], $r->overridden );
        $this->assertSame( 1, $r->switched['markers']['control'] ?? 0 );
        // The target kit button, as JSON (key order may differ: D5, a removed leaf is re-added at the end by the merge).
        $this->assertEquals( BlockAttrs::decode( self::attrsJson( $direct ) ), BlockAttrs::decode( self::attrsJson( $r->content ) ), 'a conformed button is the target kit button' );
        $a = $r->toArray( 0 );
        $this->assertSame( [], $a['overridden'] );
        $this->assertSame( 1, $a['overridden_count'] );
        $this->assertSame( 1, $a['conformed_count'] );

        // Over the corpus: every conformed block's count equals its overridden entries, and every entry names a path.
        foreach ( self::corpus() as $e ) {
            $r   = self::runs( $e, 'editorial' )['conform'];
            $per = [];
            foreach ( $r->overridden as $o ) {
                $per[ $o['block'] ] = ( $per[ $o['block'] ] ?? 0 ) + 1;
                $this->assertNotSame( '', $o['path'] );
            }
            foreach ( $r->conformed as $c ) {
                $this->assertSame( $c['overridden'], $per[ $c['block'] ] ?? 0, $e['name'] );
            }
            $this->assertSame( count( $r->overridden ), array_sum( array_column( $r->conformed, 'overridden' ) ) );
        }
    }

    /** Every conform output passes the gate, carries no marker or token, and the same input gives the same bytes. */
    public function testConformOutputIsValidAndDeterministic(): void
    {
        foreach ( self::corpus() as $e ) {
            foreach ( DesignLanguages::ids() as $l ) {
                $r = self::runs( $e, $l )['conform'];
                $this->assertNull( $r->error );
                $this->assertTrue( LayoutGate::check( $r->content, $e['markup'] )->isValid(), "{$e['name']} -> $l" );
                $this->assertSame( [], KitResolver::leftovers( $r->content ) );
                $this->assertNull( RecipeTokens::advise( $r->content ) );
                $again = DesignSwitch::run( $e['markup'], $e['from'], self::own( $l ), 'conform' );
                $this->assertSame( $r->content, $again->content );
                $this->assertSame( json_encode( $r->toArray( 1000 ) ), json_encode( $again->toArray( 1000 ) ) );
            }
        }
    }

    /**
     * Idempotence: a conformed page has no drifted block left, so conforming it again (to any design) does exactly what
     * `leave` does; measured back to the source on the 75 non-identity cells.
     */
    public function testConformingTwiceIsConformingOnce(): void
    {
        $n = 0;
        foreach ( self::corpus() as $e ) {
            foreach ( DesignLanguages::ids() as $l ) {
                $to = self::own( $l );
                if ( $to->equals( $e['from'] ) ) {
                    continue;
                }
                $once  = self::runs( $e, $l )['conform']->content;
                $twice = DesignSwitch::run( $once, $to, $e['from'], 'conform' );
                $this->assertSame( [], $twice->conformed, "{$e['name']} -> $l" );
                $this->assertSame( DesignSwitch::run( $once, $to, $e['from'] )->content, $twice->content, "{$e['name']} -> $l" );
                ++$n;
            }
        }
        $this->assertSame( 75, $n );
    }

    private static function attrsJson( string $block ): string
    {
        return 1 === preg_match( '/^<!-- wp:[a-z0-9\/-]+ (\{.*\}) \/-->$/s', $block, $m ) ? $m[1] : '';
    }

    /** @param list<string> $path */
    private static function setAt( \stdClass $o, array $path, mixed $value ): void
    {
        $last = array_pop( $path );
        foreach ( $path as $k ) {
            $o = $o->{$k};
        }
        $o->{$last} = $value;
    }

    /** @param list<string> $path */
    private static function unsetAt( \stdClass $o, array $path ): void
    {
        $last = array_pop( $path );
        foreach ( $path as $k ) {
            $o = $o->{$k};
        }
        unset( $o->{$last} );
    }
}
