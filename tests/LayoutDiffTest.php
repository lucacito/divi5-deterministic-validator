<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\LayoutDiff;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';

class LayoutDiffTest extends TestCase
{
    private const SECTION_PATH = 'divi/placeholder[0]/divi/section[0]';

    private function page( string ...$inner ): string
    {
        return '<!-- wp:divi/placeholder -->' . implode( '', $inner ) . '<!-- /wp:divi/placeholder -->';
    }

    private function section( string ...$children ): string
    {
        return '<!-- wp:divi/section {"builderVersion":"5.14.0"} -->' . implode( '', $children ) . '<!-- /wp:divi/section -->';
    }

    /** @param array<string,mixed> $extra */
    private function heading( string $text, string $ver = '5.14.0', array $extra = [] ): string
    {
        return '<!-- wp:divi/heading ' . json_encode( [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $text ] ] ] ] + $extra + [ 'builderVersion' => $ver ] ) . ' /-->';
    }

    /** @return array<string,mixed> */
    private function diff( string $before, string $after ): array
    {
        $r = LayoutDiff::compare( $before, $after );
        $this->assertIsArray( $r['diff'], (string) ( $r['diff_error'] ?? '' ) );

        return $r['diff'];
    }

    public function testConstantsArePinned(): void
    {
        $this->assertSame( 50, LayoutDiff::MAX_CHANGES );
        $this->assertSame( 25, LayoutDiff::MAX_ATTRS_PER_BLOCK );
        $this->assertSame( 200, LayoutDiff::MAX_VALUE_CHARS );
    }

    public function testIdenticalLayoutsHaveNoChanges(): void
    {
        $a = $this->page( $this->section( $this->heading( 'A' ), $this->heading( 'B' ) ) );
        $d = $this->diff( $a, $a );
        $this->assertSame( [ 'added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 4 ], $d['summary'] );
        $this->assertSame( [], $d['changes'] );
        $this->assertFalse( $d['truncated'] );
    }

    public function testOneChangedHeadingIsExactlyOneChangeWithTheRightAttributePath(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A' ), $this->heading( 'B' ) ) ),
            $this->page( $this->section( $this->heading( 'A' ), $this->heading( 'C' ) ) )
        );
        $this->assertSame( [ 'added' => 0, 'removed' => 0, 'changed' => 1, 'unchanged' => 3 ], $d['summary'] );
        $this->assertSame( [ [
            'path'  => self::SECTION_PATH . '/divi/heading[1]',
            'block' => 'divi/heading',
            'kind'  => 'changed',
            'attrs' => [ [ 'path' => 'title.innerContent.desktop.value', 'before' => 'B', 'after' => 'C' ] ],
        ] ], $d['changes'] );
    }

    public function testBlockAddedAtTheEnd(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A' ) ) ),
            $this->page( $this->section( $this->heading( 'A' ), $this->heading( 'B' ) ) )
        );
        $this->assertSame( 1, $d['summary']['added'] );
        $this->assertSame( [ [ 'path' => self::SECTION_PATH . '/divi/heading[1]', 'block' => 'divi/heading', 'kind' => 'added' ] ], $d['changes'] );
    }

    public function testBlockRemoved(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A' ), $this->heading( 'B' ) ) ),
            $this->page( $this->section( $this->heading( 'A' ) ) )
        );
        $this->assertSame( 1, $d['summary']['removed'] );
        $this->assertSame( [ [ 'path' => self::SECTION_PATH . '/divi/heading[1]', 'block' => 'divi/heading', 'kind' => 'removed' ] ], $d['changes'] );
    }

    public function testBlockTypeSwappedAtAnIndexIsRemovedThenAdded(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A' ) ) ),
            $this->page( $this->section( '<!-- wp:divi/text {"builderVersion":"5.14.0"} /-->' ) )
        );
        $this->assertSame( [ 'removed', 'added' ], array_column( $d['changes'], 'kind' ) );
        $this->assertSame( [ self::SECTION_PATH . '/divi/heading[0]', self::SECTION_PATH . '/divi/text[0]' ], array_column( $d['changes'], 'path' ) );
    }

    public function testBuilderVersionOnlyChangeIsNotAChange(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A', '5.8.0' ) ) ),
            $this->page( $this->section( $this->heading( 'A', '5.14.0' ) ) )
        );
        $this->assertSame( 0, $d['summary']['changed'] );
        $this->assertSame( [], $d['changes'] );
    }

    public function testAnAttributeAddedOrRemovedShowsNullOnTheMissingSide(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A', '5.14.0', [ 'gone' => 'x' ] ) ) ),
            $this->page( $this->section( $this->heading( 'A', '5.14.0', [ 'fresh' => 'y' ] ) ) )
        );
        $this->assertSame( [
            [ 'path' => 'gone', 'before' => 'x', 'after' => null ],
            [ 'path' => 'fresh', 'before' => null, 'after' => 'y' ],
        ], $d['changes'][0]['attrs'] );
    }

    public function testChangesAreCappedAtFiftyWhileTheSummaryKeepsTheTrueCounts(): void
    {
        $mk = fn ( string $p ): string => $this->page( $this->section( ...array_map( fn ( int $i ): string => $this->heading( $p . $i ), range( 1, 3000 ) ) ) );
        $d  = $this->diff( $mk( 'A' ), $mk( 'B' ) );
        $this->assertSame( 3000, $d['summary']['changed'] );
        $this->assertCount( LayoutDiff::MAX_CHANGES, $d['changes'] );
        $this->assertTrue( $d['truncated'] );
        $this->assertLessThan( 30000, strlen( (string) json_encode( $d ) ), 'a huge rewrite must stay small' );
    }

    public function testExactlyFiftyChangesIsNotTruncated(): void
    {
        $mk = fn ( string $p ): string => $this->page( $this->section( ...array_map( fn ( int $i ): string => $this->heading( $p . $i ), range( 1, 50 ) ) ) );
        $d  = $this->diff( $mk( 'A' ), $mk( 'B' ) );
        $this->assertCount( 50, $d['changes'] );
        $this->assertFalse( $d['truncated'] );
    }

    public function testAttributeDifferencesPerBlockAreCappedAtTwentyFive(): void
    {
        $x = $y = [];
        for ( $i = 0; $i < 40; $i++ ) {
            $x[ "x{$i}" ] = "v{$i}";
            $y[ "x{$i}" ] = "w{$i}";
        }
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A', '5.14.0', $x ) ) ),
            $this->page( $this->section( $this->heading( 'A', '5.14.0', $y ) ) )
        );
        $c = $d['changes'][0];
        $this->assertCount( 25, $c['attrs'] );
        $this->assertSame( 15, $c['attrs_omitted'] );
        $this->assertSame( 'x0', $c['attrs'][0]['path'] );
    }

    public function testNoAttrsOmittedKeyWhenNothingWasDropped(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A' ) ) ),
            $this->page( $this->section( $this->heading( 'B' ) ) )
        );
        $this->assertArrayNotHasKey( 'attrs_omitted', $d['changes'][0] );
    }

    public function testLongValuesAreCutToTwoHundredCharactersAndMarked(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'a' ) ) ),
            $this->page( $this->section( $this->heading( str_repeat( 'x', 500 ) ) ) )
        );
        $this->assertSame( str_repeat( 'x', 200 ) . '…', $d['changes'][0]['attrs'][0]['after'] );

        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'a' ) ) ),
            $this->page( $this->section( $this->heading( str_repeat( 'é', 250 ) ) ) )
        );
        $this->assertSame( str_repeat( 'é', 200 ) . '…', $d['changes'][0]['attrs'][0]['after'], 'cut by characters, not bytes' );
    }

    public function testAValueOfExactlyTwoHundredCharactersIsKeptWhole(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'a' ) ) ),
            $this->page( $this->section( $this->heading( str_repeat( 'x', 200 ) ) ) )
        );
        $this->assertSame( str_repeat( 'x', 200 ), $d['changes'][0]['attrs'][0]['after'] );
    }

    public function testUnparseableCurrentContentGivesNullDiffAndAReadableError(): void
    {
        $r = LayoutDiff::compare( '<!-- wp:divi/section {"builderVersion"', $this->page( $this->section() ) );
        $this->assertNull( $r['diff'] );
        $this->assertStringContainsString( 'current page content could not be parsed', $r['diff_error'] );
    }

    public function testUnparseableNewLayoutGivesNullDiffAndAReadableError(): void
    {
        $r = LayoutDiff::compare( $this->page( $this->section() ), '<!-- wp:divi/section {"builderVersion"' );
        $this->assertNull( $r['diff'] );
        $this->assertStringContainsString( 'new layout could not be parsed', $r['diff_error'] );
    }

    public function testEmptyContentOnEitherSideDoesNotThrow(): void
    {
        $r = LayoutDiff::compare( '', $this->page( $this->section() ) );
        $this->assertArrayHasKey( 'diff', $r );
        $r = LayoutDiff::compare( $this->page( $this->section() ), '' );
        $this->assertArrayHasKey( 'diff', $r );
    }

    // ---- real exported layout -------------------------------------------------------------

    private function fixture(): string
    {
        $j = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/valid/page-7-home.json' ), true );

        return (string) $j['post_content'];
    }

    public function testRealFixtureAgainstItselfIsAllUnchanged(): void
    {
        $c = $this->fixture();
        $d = $this->diff( $c, $c );
        $this->assertSame( 0, $d['summary']['changed'] + $d['summary']['added'] + $d['summary']['removed'] );
        $this->assertGreaterThan( 20, $d['summary']['unchanged'] );
    }

    public function testRealFixtureRestampedWithAnotherBuilderVersionHasNoChanges(): void
    {
        $c = $this->fixture();
        $this->assertStringContainsString( '"builderVersion":"5.8.0"', $c );
        $d = $this->diff( $c, str_replace( '"builderVersion":"5.8.0"', '"builderVersion":"5.14.0"', $c ) );
        $this->assertSame( [], $d['changes'] );
    }

    public function testRealFixtureWithOneTitleEditedIsExactlyOneChange(): void
    {
        $c     = $this->fixture();
        $after = (string) preg_replace( '/Your Title Goes Here/', 'Brand new title', $c, 1 );
        $d     = $this->diff( $c, $after );
        $this->assertSame( 1, $d['summary']['changed'] );
        $this->assertSame( 0, $d['summary']['added'] + $d['summary']['removed'] );
        $this->assertSame( 'divi/slide', $d['changes'][0]['block'] );
        $this->assertSame( [ [ 'path' => 'title.innerContent.desktop.value', 'before' => 'Your Title Goes Here', 'after' => 'Brand new title' ] ], $d['changes'][0]['attrs'] );
    }
}
