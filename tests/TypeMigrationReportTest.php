<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Tests\Support\TypeAudit;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/type-migration-report.php';

/**
 * scripts/type-migration-report.php (make type-report) prints the audit of the headings and labels of the shipped
 * recipes (type adapter, Task 4): one line per classified block and the totals the plan pins.
 */
class TypeMigrationReportTest extends TestCase
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
        $out = \TypeReport::render( TypeAudit::blocks( self::library() ), false );
        $this->assertStringContainsString( "\ndisplay 56, title 64, eyebrow 86 (53 headings + 33 texts), exempt 4\n", $out );
        // Since Task 5 (scripts/migrate-type-markers.php) every classified block carries its marker.
        $this->assertStringContainsString( "\nmarked 206 of 206\n", $out );
    }

    public function testEveryClassifiedBlockHasALine(): void
    {
        $rows = TypeAudit::blocks( self::library() );
        $out  = \TypeReport::render( $rows, false );
        $this->assertSame( 206 + 4, substr_count( $out, "\n  " ) , 'one indented line per classified or exempt block' );
        $this->assertStringContainsString( '  proof-press-quotes | text#0 | - | display | exempt | - | a publication name', $out );
        $this->assertStringContainsString( '  shop-grid | heading#0 | - | display | exempt | - |', $out );
        $this->assertStringContainsString( '  hero-cta | heading#1 | h1 | display | display | MARKED |', $out );
        $this->assertStringContainsString( '  hero-image-scrim | text#1 | - | eyebrow | eyebrow | MARKED |', $out );
    }

    public function testAllAlsoListsTheUnclassifiedTexts(): void
    {
        $recipes = self::library();
        $plain   = \TypeReport::render( TypeAudit::blocks( $recipes ), false );
        $all     = \TypeReport::render( TypeAudit::blocks( $recipes, true ), true );
        $this->assertGreaterThan( substr_count( $plain, "\n  " ), substr_count( $all, "\n  " ) );
        $this->assertStringContainsString( 'unclassified texts', $all );
        $this->assertStringNotContainsString( 'unclassified texts', $plain );
    }

    public function testAMarkedBlockIsShownMarked(): void
    {
        $markup = '<!-- wp:divi/heading {"title":{"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"h2","family":"{{aied:font:display}}"}}}}}},"aied":{"type":"display"}} /-->';
        $out    = \TypeReport::render( TypeAudit::blocks( [ [ 'name' => 'planted', 'markup' => $markup ] ] ), false );
        $this->assertStringContainsString( '  planted | heading#0 | h2 | display | display | MARKED |', $out );
        $this->assertStringContainsString( "\nmarked 1 of 1\n", $out );
    }

    public function testTheScriptRunsAndExitsCleanly(): void
    {
        $out = [];
        $code = 1;
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/../scripts/type-migration-report.php' ) . ' 2>&1', $out, $code );
        $this->assertSame( 0, $code, implode( "\n", $out ) );
        $this->assertContains( 'display 56, title 64, eyebrow 86 (53 headings + 33 texts), exempt 4', $out );
    }
}
