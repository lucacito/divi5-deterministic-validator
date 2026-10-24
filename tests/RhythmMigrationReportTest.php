<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Tests\Support\RhythmAudit;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/rhythm-migration-report.php';

/**
 * scripts/rhythm-migration-report.php (make rhythm-report) prints the audit of the section padding of the shipped
 * recipes (rhythm adapter, Task 4): one line per section and the totals the plan pins.
 */
class RhythmMigrationReportTest extends TestCase
{
    /** @return list<array{name:string,markup:string}> */
    private static function library(): array
    {
        $recipes = json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        self::assertIsArray( $recipes );

        return $recipes;
    }

    public function testTheTotalsLineIsThePlansCounts(): void
    {
        $out = \RhythmReport::render( RhythmAudit::sections( self::library() ) );
        $this->assertStringContainsString( "\nsection 38, open 7, tight 1, exempt 25\n", $out );
        $this->assertStringContainsString( "\nmarked 0 of 46\n", $out );
        $this->assertStringContainsString( "\nexempt by reason: 0px edge 3, asymmetric 6, desktop only 3, no padding 7, old alpha shape 3, out of band 1, percent or vw 2\n", $out );
    }

    public function testEverySectionHasALine(): void
    {
        $out = \RhythmReport::render( RhythmAudit::sections( self::library() ) );
        $this->assertSame( 71, substr_count( $out, "\n  " ), 'one indented line per section' );
        $this->assertStringContainsString( '  hero-pattern-centered | section#0 | 160px/112px/72px | open | - | -', $out );
        $this->assertStringContainsString( '  proof-logo-wall | section#0 | 80px/64px/48px | tight | - | -', $out );
        $this->assertStringContainsString( '  features-bento | section#0 | 112px/88px/64px | section | - | -', $out );
        $this->assertStringContainsString( '  hero-editorial-minimal | section#0 | 180px~140px/120px~96px/80px~64px | exempt | - | asymmetric:', $out );
        $this->assertStringContainsString( '  card-grid-3 | section#0 | -/-/- | exempt | - | no padding:', $out );
    }

    public function testAMarkedSectionIsShownMarked(): void
    {
        $markup = '<!-- wp:divi/section {"module":{},"aied":{"rhythm":"open"}} --><!-- /wp:divi/section -->';
        $out    = \RhythmReport::render( RhythmAudit::sections( [ [ 'name' => 'planted', 'markup' => $markup ] ] ) );
        $this->assertStringContainsString( '  planted | section#0 | -/-/- | open | MARKED | -', $out );
        $this->assertStringContainsString( "\nsection 0, open 1, tight 0, exempt 0\n", $out );
        $this->assertStringContainsString( "\nmarked 1 of 1\n", $out );
    }

    public function testAnUnclassifiedSectionIsFlagged(): void
    {
        $markup = '<!-- wp:divi/section {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"20px","bottom":"30px"}}}}}}} --><!-- /wp:divi/section -->';
        $out    = \RhythmReport::render( RhythmAudit::sections( [ [ 'name' => 'planted', 'markup' => $markup ] ] ) );
        $this->assertStringContainsString( '  planted | section#0 | 20px~30px/-/- | UNCLASSIFIED | - | -', $out );
        $this->assertStringContainsString( 'UNCLASSIFIED sections: 1', $out );
    }

    public function testTheScriptRunsAndExitsCleanly(): void
    {
        $out  = [];
        $code = 1;
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/../scripts/rhythm-migration-report.php' ) . ' 2>&1', $out, $code );
        $this->assertSame( 0, $code, implode( "\n", $out ) );
        $this->assertContains( 'section 38, open 7, tight 1, exempt 25', $out );
    }
}
