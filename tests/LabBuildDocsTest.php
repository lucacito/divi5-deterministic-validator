<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/lib/lab-build-measure.php';

/**
 * Lab assistant tool, plan 3 task 8: the release-notes draft exists and says what must change at release, and the
 * measured comparison in docs/token-usage.md still agrees with what scripts/lib/lab-build-measure.php measures now.
 *
 * The doc's numbers are checked with a stated tolerance (TOLERANCE of the measured bytes), not exactly: a recipe or a
 * guide edit moves them a little without making the reading wrong. A larger drift fails, and the fix is to run
 * `php scripts/measure-tokens.php` and commit the new doc.
 */
final class LabBuildDocsTest extends TestCase
{
    private const HEADING   = '## Building a page: a brief or Divi markup';
    private const TOLERANCE = 0.10;

    private static function path( string $rel ): string
    {
        return __DIR__ . '/../' . $rel;
    }

    private static function read( string $rel ): string
    {
        $text = file_get_contents( self::path( $rel ) );
        self::assertNotFalse( $text, $rel . ' must exist.' );

        return (string) $text;
    }

    /** @return array<string,array{0:int,1:int}> label => [bytes, tokens] from the table rows of a markdown text */
    private static function rows( string $md ): array
    {
        $out = [];
        foreach ( explode( "\n", $md ) as $line ) {
            if ( 1 === preg_match( '/^\| (.+?) \| .+ \| ([\d,]+) \| ([\d,]+) \|$/', $line, $m ) ) {
                $out[ $m[1] ] = [ (int) str_replace( ',', '', $m[2] ), (int) str_replace( ',', '', $m[3] ) ];
            }
        }

        return $out;
    }

    private static function section( string $doc ): string
    {
        $at = strpos( $doc, self::HEADING );
        self::assertNotFalse( $at, 'docs/token-usage.md must contain the heading "' . self::HEADING . '". Run php scripts/measure-tokens.php.' );

        return substr( $doc, (int) $at );
    }

    public function testReleaseNotesDraftNamesTheToolAndTheNewCount(): void
    {
        $draft = self::read( 'docs/lab-build-release-notes-draft.md' );
        $this->assertStringContainsString( 'lab_build', $draft );
        $this->assertMatchesRegularExpression( '/\b22\b/', $draft );
        $this->assertMatchesRegularExpression( '/\b21\b/', $draft, 'The draft must say what the 21 becomes 22 at.' );
        $this->assertStringContainsString( 'readme.txt', $draft );
    }

    public function testReleaseNotesDraftSaysWhatIsNotConfirmedAndWhatChatGPTGets(): void
    {
        $draft = self::read( 'docs/lab-build-release-notes-draft.md' );
        $this->assertStringContainsString( 'ChatGPT', $draft );
        $this->assertMatchesRegularExpression( '/not (yet )?confirmed/i', $draft );
        $this->assertStringContainsString( 'Action', $draft, 'The legacy Action cannot be offered lab_build; the draft must say so.' );
    }

    public function testTheChangelogWordingNeverUsesTheInternalName(): void
    {
        $draft = self::read( 'docs/lab-build-release-notes-draft.md' );
        $start = strpos( $draft, '## 1.' );
        $end   = strpos( $draft, '## 2.' );
        $this->assertNotFalse( $start );
        $this->assertNotFalse( $end );
        $wording = substr( $draft, (int) $start, (int) $end - (int) $start );
        $this->assertStringContainsString( 'Lab', $wording );
        $this->assertDoesNotMatchRegularExpression( '/studio/i', $wording, 'People-facing wording says Lab, not Studio.' );
    }

    public function testTokenDocHasTheSectionAndEveryMeasuredRow(): void
    {
        $section  = self::section( self::read( 'docs/token-usage.md' ) );
        $expected = self::rows( \LabBuildMeasure::markdown( \LabBuildMeasure::run() ) );
        $actual   = self::rows( $section );

        $this->assertNotSame( [], $expected );
        $this->assertSame( array_keys( $expected ), array_keys( $actual ), 'The doc rows must be the rows the measurement produces.' );
    }

    public function testTokenDocNumbersAgreeWithTheMeasurementWithinTolerance(): void
    {
        $section  = self::section( self::read( 'docs/token-usage.md' ) );
        $expected = self::rows( \LabBuildMeasure::markdown( \LabBuildMeasure::run() ) );
        $actual   = self::rows( $section );

        foreach ( $expected as $label => [ $bytes ] ) {
            $this->assertArrayHasKey( $label, $actual );
            $this->assertEqualsWithDelta( $bytes, $actual[ $label ][0], max( 1, $bytes * self::TOLERANCE ), $label . ': run php scripts/measure-tokens.php to refresh docs/token-usage.md.' );
            $this->assertSame( \LabBuildMeasure::tokens( $actual[ $label ][0] ), $actual[ $label ][1], $label . ': tokens must be bytes / 4, rounded up.' );
        }
    }

    public function testTheBriefRouteIsSmallerThanTheMarkupRouteOnTheSamePage(): void
    {
        $m = \LabBuildMeasure::run();
        $this->assertSame( 1, $m['a_created'], 'The brief route must really make the page.' );
        $this->assertSame( 1, $m['b_created'], 'The markup route must really make the page.' );
        $this->assertSame( 6, $m['sections'] );
        $this->assertLessThan( (int) $m['b_args'], (int) $m['a_create_args'], 'The doc reading ("the brief is smaller") rests on this.' );
    }

    public function testTokenDocSaysWhatTheComparisonDoesNotShow(): void
    {
        $section = self::section( self::read( 'docs/token-usage.md' ) );
        $this->assertStringContainsString( 'What it does not show', $section );
        $this->assertStringContainsString( 'freehand', $section );
        $this->assertStringContainsString( 'bytes / 4', $section );
    }
}
