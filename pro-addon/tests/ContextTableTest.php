<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\Bands;
use AiEditorDivi5\Pro\Design\ContextTable;
use AiEditorDivi5\Pro\Design\JsonLeaves;
use AiEditorDivi5\Pro\Design\PageScan;
use AiEditorDivi5\Pro\Design\RoleReverse;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

/**
 * Design switch, Task 4: the library context table is derived at run time from the installed recipes, under a synthetic
 * palette in which every role has its own value, and records exactly the token inventory Task 0 pinned.
 */
class ContextTableTest extends TestCase
{
    public function testTheSyntheticLanguageIsAValidLanguageWithOneValuePerRole(): void
    {
        $synthetic = ContextTable::syntheticLanguage();
        // Every check of DesignLanguages::valid() runs; the font list check is skipped by valid() itself when no list is
        // given (the synthetic families are not Google fonts: they only need to be distinct, nothing renders them).
        $this->assertSame( [], DesignLanguages::valid( $synthetic ) );

        $editorial = DesignLanguages::get( 'editorial' );
        $this->assertSame( $editorial['type'], $synthetic['type'], 'type unchanged' );
        $this->assertSame( $editorial['section_space'], $synthetic['section_space'], 'section space unchanged' );
        $this->assertSame( $editorial['kit'], ContextTable::KIT );

        $keys = [];
        foreach ( RoleReverse::COLOR_ROLES as $role => $key ) {
            $this->assertIsString( $synthetic['colors'][ $key ] ?? null, $role );
            $ck = Bands::colourKey( $synthetic['colors'][ $key ] );
            $this->assertNotNull( $ck, $role );
            $keys[ $role ] = $ck;
        }
        $this->assertCount( 10, array_unique( $keys ), 'every colour role has its own colour' );
        $rgb = array_map( static fn ( string $k ): string => implode( ',', array_slice( explode( ',', $k ), 0, 3 ) ), $keys );
        $this->assertCount( 10, array_unique( $rgb ), 'and its own channels' );
        $fonts = [];
        foreach ( RoleReverse::FONT_ROLES as $key ) {
            $fonts[] = $synthetic['fonts'][ $key ];
        }
        $this->assertCount( 3, array_unique( $fonts ), 'every font role has its own family' );

        // No synthetic colour is a literal anywhere in the raw library (a literal equal to one would read as that role's
        // paint and move a band).
        $palette = array_flip( $keys );
        foreach ( ContextTable::installedRecipes() as $name => $raw ) {
            foreach ( PageScan::scan( $raw, $synthetic ) as $block ) {
                foreach ( null === $block['json'] ? [] : JsonLeaves::strings( $block['json'] ) as $leaf ) {
                    $ck = Bands::colourKey( $leaf['value'] );
                    $this->assertFalse( null !== $ck && isset( $palette[ $ck ] ), "{$name}: literal {$leaf['value']}" );
                }
            }
        }
    }

    public function testTheTotalsAreTheTokenInventory(): void
    {
        $table = ContextTable::current();
        $this->assertSame( [], $table->skipped(), 'every recipe resolves and keeps its block list' );
        $this->assertSame( [ 'colour' => 860, 'font' => 628 ], $table->totals(), 'DesignInventoryTest: 860 colour, 628 font tokens' );
    }

    public function testTheMeasuredLibraryContexts(): void
    {
        $t    = ContextTable::current();
        $body = '.content.decoration.bodyFont.body.font.desktop.value.family';
        // Spec section 6, D1: the untyped text body font.
        $this->assertSame( [ 'body' => 236, 'display' => 41, 'eyebrow' => 13 ], $t->font( 'divi/text', $body, '-' ) );
        $this->assertSame( [ 'eyebrow' => 33 ], $t->font( 'divi/text', $body, 'eyebrow' ) );
        $this->assertSame( [ 'display' => 3, 'eyebrow' => 1 ], $t->font( 'divi/text', '.content.decoration.headingFont.h4.font.desktop.value.family', '-' ) );
        $this->assertSame( [], $t->font( 'divi/text', $body, 'no-such-role' ) );
        $this->assertSame( [], $t->colour( 'divi/text', $body, 'light' ) );

        $sum = 0;
        foreach ( [ 'light', 'surface', 'accent', 'panel', 'dark', 'unknown' ] as $band ) {
            $sum += array_sum( $t->colour( 'divi/row', '.module.decoration.background.desktop.value.color', $band ) );
        }
        $this->assertGreaterThan( 0, $sum );
    }

    public function testTheTableIsDeterministic(): void
    {
        $a = ContextTable::build( ContextTable::installedRecipes() );
        $b = ContextTable::build( ContextTable::installedRecipes() );
        $this->assertEquals( $a, $b );
        $this->assertSame( serialize( $a ), serialize( $b ), 'same bytes' );
        $this->assertSame( ContextTable::current(), ContextTable::current(), 'memoised per request' );
        $this->assertEquals( $a, ContextTable::current() );
    }

    public function testTheHashFollowsTheRecipes(): void
    {
        $recipes = ContextTable::installedRecipes();
        $this->assertSame( ContextTable::hashOf( $recipes ), ContextTable::current()->hash() );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', ContextTable::current()->hash() );

        $first   = array_key_first( $recipes );
        $changed = $recipes;
        $changed[ $first ] .= ' ';
        $this->assertNotSame( ContextTable::hashOf( $recipes ), ContextTable::hashOf( $changed ), 'one byte of one recipe' );
        $this->assertNotSame( ContextTable::hashOf( $recipes ), ContextTable::hashOf( array_reverse( $recipes, true ) ), 'the order' );
        $renamed = [ 'x' . $first => $recipes[ $first ] ] + $recipes;
        unset( $renamed[ $first ] );
        $this->assertNotSame( ContextTable::hashOf( $recipes ), ContextTable::hashOf( $renamed ), 'a name' );
        $this->assertNotSame( ContextTable::hashOf( [ 'ab' => 'c' ] ), ContextTable::hashOf( [ 'a' => 'bc' ] ), 'no concatenation ambiguity' );

        $this->assertSame( ContextTable::hashOf( $changed ), ContextTable::build( $changed )->hash() );
    }

    public function testAPlantedRecipeIsRecordedAtItsBlockPathAndBand(): void
    {
        $markup = '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:surface}}"}}}}}} -->'
            . '<!-- wp:divi/text {"a":{"color":"{{aied:color:text}}","family":"{{aied:font:eyebrow}}"},"aied":{"type":"eyebrow"}} /-->'
            . '<!-- wp:divi/text {"a":{"color":"{{aied:color:muted}}","family":"{{aied:font:body}}"}} /-->'
            . '<!-- /wp:divi/section -->'
            . '<!-- wp:divi/text {"a":{"color":"{{aied:color:muted}}"},"b":["{{aied:color:accent}}"]} /-->';
        $t = ContextTable::build( [ 'planted' => $markup ] );
        $this->assertSame( [], $t->skipped() );
        $this->assertSame( [ 'colour' => 5, 'font' => 2 ], $t->totals() );
        $this->assertSame( [ 'surface' => 1 ], $t->colour( 'divi/section', '.module.decoration.background.desktop.value.color', 'light' ) );
        $this->assertSame( [ 'muted' => 1, 'text' => 1 ], $t->colour( 'divi/text', '.a.color', 'surface' ), 'on the band the section paints, sorted by role' );
        $this->assertSame( [ 'muted' => 1 ], $t->colour( 'divi/text', '.a.color', 'light' ) );
        $this->assertSame( [ 'accent' => 1 ], $t->colour( 'divi/text', '.b[]', 'light' ) );
        $this->assertSame( [ 'eyebrow' => 1 ], $t->font( 'divi/text', '.a.family', 'eyebrow' ), 'the raw block\'s type role' );
        $this->assertSame( [ 'body' => 1 ], $t->font( 'divi/text', '.a.family', '-' ) );
    }

    public function testAnUnresolvableRecipeIsSkippedAndAddsNothing(): void
    {
        $t = ContextTable::build( [
            'bogus' => '<!-- wp:divi/button {"aied":{"kit":"no-such-role","on":"light"},"a":{"color":"{{aied:color:text}}"}} /-->',
            'fine'  => '<!-- wp:divi/text {"a":{"color":"{{aied:color:text}}"}} /-->',
        ] );
        $this->assertSame( [ 'bogus' ], $t->skipped() );
        $this->assertSame( [ 'colour' => 1, 'font' => 0 ], $t->totals() );
        $this->assertSame( [], $t->colour( 'divi/button', '.a.color', 'light' ) );
    }

    public function testTheRecipeNamesAreTheInstalledOnes(): void
    {
        $this->assertSame( SectionRecipes::names(), array_keys( ContextTable::installedRecipes() ) );
    }
}
