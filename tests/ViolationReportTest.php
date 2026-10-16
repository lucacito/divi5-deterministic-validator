<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ViolationReport;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ViolationReport.php';

class ViolationReportTest extends TestCase
{
    /** A column holding $n columns: every one is refused with the column's long allowed list. */
    private function badPage( int $n ): string
    {
        $col = '<!-- wp:divi/column {"builderVersion":"5.14.0"} --><!-- /wp:divi/column -->';

        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"builderVersion":"5.14.0"} -->'
            . str_repeat( $col, $n ) . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    public function testTheFirstViolationKeepsItsAllowedListAndRepeatsPointBackToIt(): void
    {
        $all = ( new Validator() )->validateContent( $this->badPage( 4 ) )->violations();
        $this->assertCount( 4, $all );
        $rows = ViolationReport::compact( $all );
        $this->assertCount( 4, $rows );
        $this->assertStringContainsString( 'divi/heading', $rows[0]['message'], 'the first one has the full list' );
        for ( $i = 1; $i < 4; $i++ ) {
            $this->assertStringContainsString( "the same list as the first violation about 'divi/column'", $rows[ $i ]['message'] );
            $this->assertStringNotContainsString( 'divi/heading', $rows[ $i ]['message'] );
            $this->assertSame( $all[ $i ]->code(), $rows[ $i ]['code'] );
            $this->assertSame( $all[ $i ]->path(), $rows[ $i ]['path'], 'the path is never touched' );
        }
    }

    public function testAtMostTwentyFiveAreListedAndOneEntryCountsTheRest(): void
    {
        $all  = ( new Validator() )->validateContent( $this->badPage( 96 ) )->violations();
        $rows = ViolationReport::compact( $all );
        $this->assertCount( ViolationReport::MAX_SHOWN + 1, $rows );
        $last = $rows[ ViolationReport::MAX_SHOWN ];
        $this->assertSame( 'MORE_VIOLATIONS', $last['code'] );
        $this->assertStringContainsString( '71 more violations are not listed (71 UNEXPECTED_CHILD_TYPE)', $last['message'] );
    }

    public function testItIsMuchSmallerThanTheFullList(): void
    {
        $all  = ( new Validator() )->validateContent( $this->badPage( 96 ) )->violations();
        $full = strlen( (string) json_encode( array_map( static fn ( $v ) => $v->toArray(), $all ) ) );
        $now  = strlen( (string) json_encode( ViolationReport::compact( $all ) ) );
        $this->assertLessThan( $full / 10, $now, "full {$full} bytes, now {$now}" );
    }

    public function testFewAndShortViolationsAreReturnedUnchanged(): void
    {
        $one = ( new Validator() )->validateContent( '<!-- wp:divi/placeholder --><!-- wp:divi/bogus {"builderVersion":"5.14.0"} /--><!-- /wp:divi/placeholder -->' )->violations();
        $this->assertSame( array_map( static fn ( $v ) => $v->toArray(), $one ), ViolationReport::compact( $one ) );
        $this->assertSame( [], ViolationReport::compact( [] ) );
    }
}
