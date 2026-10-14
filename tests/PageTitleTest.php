<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\PageTitle;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/PageTitle.php';

class PageTitleTest extends TestCase
{
    public function testAbsentAndNullMeanNotGiven(): void
    {
        $this->assertSame( [ 'ok' => true, 'present' => false, 'title' => null, 'error' => null ], PageTitle::parse( [] ) );
        $this->assertSame( [ 'ok' => true, 'present' => false, 'title' => null, 'error' => null ], PageTitle::parse( [ 'title' => null ] ) );
    }

    public function testTrimsAndAccepts(): void
    {
        $r = PageTitle::parse( [ 'title' => "  About us \u{00A0}" ] );
        $this->assertTrue( $r['ok'] );
        $this->assertTrue( $r['present'] );
        $this->assertSame( 'About us', $r['title'] );
    }

    public function testQuotesBackslashesAmpersandEmojiAccentsAreKept(): void
    {
        foreach ( [ 'Tom & "Jerry" \\ Café', 'Sale 🎉 now', "O'Neil’s" ] as $t ) {
            $this->assertSame( $t, PageTitle::parse( [ 'title' => $t ] )['title'] );
        }
    }

    /** @return array<string,array{mixed}> */
    public static function nonStrings(): array
    {
        return [ 'int' => [ 5 ], 'float' => [ 1.5 ], 'true' => [ true ], 'false' => [ false ], 'array' => [ [ 'a' ] ], 'empty array' => [ [] ], 'object' => [ new \stdClass() ] ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'nonStrings' )]
    public function testNonStringsAreRejectedNeverCast( mixed $v ): void
    {
        $r = PageTitle::parse( [ 'title' => $v ] );
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'string', (string) $r['error'] );
    }

    public function testEmptyAndWhitespaceOnlyAreRejected(): void
    {
        foreach ( [ '', '   ', "\u{00A0}\u{00A0}", " \t " ] as $t ) {
            $r = PageTitle::parse( [ 'title' => $t ] );
            $this->assertFalse( $r['ok'], json_encode( $t ) );
            $this->assertStringContainsString( 'empty', (string) $r['error'] );
        }
    }

    public function testMarkupIsRejected(): void
    {
        foreach ( [ '<b>Hi</b>', 'a < b', 'a > b', '<script>x</script>' ] as $t ) {
            $r = PageTitle::parse( [ 'title' => $t ] );
            $this->assertFalse( $r['ok'], $t );
            $this->assertStringContainsString( 'plain text', (string) $r['error'] );
        }
    }

    public function testControlCharactersAreRejected(): void
    {
        foreach ( [ "Line one\nLine two", "a\tb", "a\0b", "a\x7Fb", "a\u{0085}b", "a\u{2028}b", "a\u{2029}b" ] as $t ) {
            $r = PageTitle::parse( [ 'title' => $t ] );
            $this->assertFalse( $r['ok'], json_encode( $t ) );
            $this->assertStringContainsString( 'one line', (string) $r['error'] );
        }
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $r = PageTitle::parse( [ 'title' => "bad \xC3\x28 bytes" ] );
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'UTF-8', (string) $r['error'] );
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        $this->assertTrue( PageTitle::parse( [ 'title' => str_repeat( 'a', 200 ) ] )['ok'] );
        $this->assertFalse( PageTitle::parse( [ 'title' => str_repeat( 'a', 201 ) ] )['ok'] );
        $this->assertTrue( PageTitle::parse( [ 'title' => str_repeat( 'é', 200 ) ] )['ok'], '200 two-byte characters are fine' );
        $this->assertFalse( PageTitle::parse( [ 'title' => str_repeat( 'é', 201 ) ] )['ok'] );
        $this->assertStringContainsString( '200', (string) PageTitle::parse( [ 'title' => str_repeat( 'a', 201 ) ] )['error'] );
    }

    public function testTrimHappensBeforeTheLengthCount(): void
    {
        $this->assertTrue( PageTitle::parse( [ 'title' => ' ' . str_repeat( 'a', 200 ) . ' ' ] )['ok'] );
    }

    public function testReportAndMessages(): void
    {
        $this->assertSame( [ 'changed' => true, 'before' => 'Old', 'after' => 'New', 'restorable' => false ], PageTitle::report( 'Old', 'New', true ) );
        $this->assertSame( [ 'changed' => false, 'before' => 'Same', 'after' => 'Same' ], PageTitle::report( 'Same', 'Same', false ) );
        $this->assertStringContainsString( '"Old"', PageTitle::notRestorableMessage( 'Old' ) );
        $this->assertStringContainsString( 'restore_page_version will not bring the old title back', PageTitle::notRestorableMessage( 'Old' ) );
        $this->assertStringContainsString( 'already "Same"', PageTitle::unchangedMessage( 'Same' ) );
        $this->assertStringContainsString( 'left as it is: "Now"', PageTitle::restoreNote( 'Now' ) );
    }
}
