<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\LayoutGate;
use Divi5Validator\Tests\Support\Dogfood\Prepare;
use Divi5Validator\Tests\Support\Dogfood\Scorer;
use PHPUnit\Framework\TestCase;

/**
 * The dogfood regression corpus (plan 2026-10-22, Task 3): the eight pages fresh assistants built or edited on 2026-10-21 from
 * the served guide only, kept byte for byte in fixtures/dogfood/, with the scores today's scorer gives them pinned in
 * corpus.json. The freehand pages are NOT expected to be perfect (a 0px-control kit's tags, a rule on a panel...): the pins
 * document exactly where they stand, so a scorer or adapter change that moves a number has to say so. The pages are never
 * tidied. Dev tooling only: nothing here is shipped.
 */
class DogfoodCorpusTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/dogfood';

    /** sha256 of Prepare::editBase('glass-edge', 'glass-dark') (the same literal DogfoodPrepareTest pins). */
    private const EDIT_BASE_SHA256 = '31a20120028f79be6b5a71640b9e45bc9397a9822d024a6d2913016d2efee2bf';

    /** @return list<array<string,mixed>> */
    private static function corpus(): array
    {
        $json = json_decode( (string) file_get_contents( self::DIR . '/corpus.json' ), true );
        self::assertIsArray( $json );

        return $json;
    }

    private static function page( array $entry ): string
    {
        return (string) file_get_contents( self::DIR . '/' . $entry['page'] );
    }

    public function testTheCorpusListsTheEightPagesInOrder(): void
    {
        $this->assertSame(
            [ 'hairline-dashed', 'heavy-block', 'dotted-pill', 'underlined-label', 'soft-rounded', 'gilt-hairline', 'edit-glass-edge-base', 'edit-glass-edge' ],
            array_column( self::corpus(), 'name' )
        );
        foreach ( self::corpus() as $e ) {
            $keys = [ 'name', 'kit', 'language', 'task', 'page', 'sha256', 'provenance', 'scores', 'unclassified' ];
            if ( 'freehand' !== $e['task'] ) {
                $keys[] = 'note'; // the two unclassified blocks of the edit pair are explained
                $this->assertStringContainsString( 'divi/column#0', $e['note'] );
                $this->assertStringContainsString( 'divi/group#10', $e['note'] );
            }
            $this->assertSame( $keys, array_keys( $e ) );
            $this->assertContains( $e['task'], [ 'freehand', 'edit-base', 'edit' ] );
            $this->assertStringContainsString( '2026-10-21', $e['provenance'] );
            $this->assertSame( [ ...Scorer::CATEGORIES, 'overall' ], array_keys( $e['scores'] ), $e['name'] );
        }
    }

    public function testTheCorpusPagesArePinnedByteForByte(): void
    {
        foreach ( self::corpus() as $e ) {
            $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $e['sha256'], $e['name'] );
            $this->assertSame( $e['sha256'], hash( 'sha256', self::page( $e ) ), $e['name'] . ': the page was changed (a corpus page is never tidied)' );
        }
        $this->assertCount( 8, array_unique( array_column( self::corpus(), 'sha256' ) ), 'eight different pages' );
    }

    public function testTheCorpusScoresArePinned(): void
    {
        foreach ( self::corpus() as $e ) {
            $report = ( new Scorer( $e['kit'], $e['language'] ) )->score( self::page( $e ), $e['page'] );
            $got    = [];
            foreach ( $report['categories'] as $cat => $c ) {
                $got[ $cat ] = [ $c['checked'], $c['passed'] ];
            }
            $got['overall'] = [ $report['overall']['checked'], $report['overall']['passed'] ];
            $this->assertSame( $e['scores'], $got, $e['name'] . ': per-category [checked, passed]' );
            $this->assertSame( $e['unclassified'], count( $report['unclassified'] ), $e['name'] . ': unclassified' );
        }
    }

    public function testTheCorpusEditBaseIsPrepareOutput(): void
    {
        $page = (string) file_get_contents( self::DIR . '/edit-glass-edge/page.html' );
        $this->assertSame( Prepare::editBase( 'glass-edge', 'glass-dark' ), $page );
        $this->assertSame( self::EDIT_BASE_SHA256, hash( 'sha256', $page ) );
    }

    public function testTheEditBaseScoresFullMarks(): void
    {
        $base = null;
        foreach ( self::corpus() as $e ) {
            if ( 'edit-base' === $e['task'] ) {
                $base = $e;
            }
        }
        $this->assertNotNull( $base );
        $this->assertSame( 'edit-glass-edge/page.html', $base['page'] );
        foreach ( $base['scores'] as $cat => [ $checked, $passed ] ) {
            $this->assertSame( $checked, $passed, "edit base, $cat" );
        }
        $this->assertGreaterThan( 0, $base['scores']['overall'][0] );
    }

    public function testEveryCorpusPageIsValidAndCarriesNoKitMarker(): void
    {
        foreach ( self::corpus() as $e ) {
            $page   = self::page( $e );
            $result = LayoutGate::check( $page );
            $this->assertTrue( $result->isValid(), $e['name'] . ' ' . json_encode( array_map( static fn ( $v ) => $v->code(), $result->violations() ) ) );
            $this->assertStringNotContainsString( '"aied"', $page, $e['name'] );
            $this->assertStringNotContainsString( '{{aied:', $page, $e['name'] );
        }
    }

    public function testTheCorpusIsExactlyTheListedFiles(): void
    {
        $found = [];
        $bytes = 0;
        $it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::DIR, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            $found[] = substr( $file->getPathname(), strlen( self::DIR ) + 1 );
            $bytes  += $file->getSize();
        }
        $expected = array_merge( [ 'corpus.json' ], array_column( self::corpus(), 'page' ) );
        sort( $found );
        sort( $expected );
        $this->assertSame( $expected, $found );
        $this->assertCount( 9, $found );
        $this->assertLessThan( 400 * 1024, $bytes );
    }

    public function testNothingOfTheHarnessIsShipped(): void
    {
        $root = realpath( __DIR__ . '/../wp-plugin' );
        $this->assertIsString( $root );
        $hits = [];
        $it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }
            foreach ( file( $file->getPathname() ) ?: [] as $n => $line ) {
                if ( str_contains( $line, 'dogfood' ) || str_contains( $line, 'Dogfood' ) ) {
                    $hits[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ':' . ( $n + 1 ) . ':' . trim( $line );
                }
            }
        }
        // The one known exception: a docblock in StyleGuide.php says where round 3 of the guide polish came from (a comment, no code).
        $unexpected = array_values( array_filter( $hits, static fn ( string $h ): bool => ! preg_match( '#^src/StyleGuide\.php:\d+:\*#', $h ) ) );
        $this->assertSame( [], $unexpected, 'a shipped file mentions the dogfood harness' );
        $this->assertLessThanOrEqual( 1, count( $hits ), 'the StyleGuide.php docblock is the only allowed mention' );

        $autoload = (string) file_get_contents( $root . '/src/autoload.php' );
        foreach ( glob( __DIR__ . '/Support/Dogfood/*.php' ) ?: [] as $f ) {
            $class = basename( $f, '.php' );
            $this->assertStringNotContainsString( "/$class.php'", $autoload, "wp-plugin/src/autoload.php names $class" );
        }
        $this->assertStringNotContainsString( 'Support/Dogfood', $autoload );
    }
}
