<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Canon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class CanonTest extends TestCase
{
    public function testObjectKeyOrderDoesNotMatterButListOrderDoes(): void
    {
        $this->assertSame( Canon::hash( [ 'a' => 1, 'b' => [ 'x' => 1, 'y' => 2 ] ] ), Canon::hash( [ 'b' => [ 'y' => 2, 'x' => 1 ], 'a' => 1 ] ) );
        $this->assertNotSame( Canon::hash( [ 1, 2 ] ), Canon::hash( [ 2, 1 ] ) );
    }

    public function testDifferentValuesAndTypesDiffer(): void
    {
        $hashes = array_map( [ Canon::class, 'hash' ], [ null, '', '0', 0, false, [], 'a', 'A', [ 'a' ] ] );
        $this->assertSame( count( $hashes ), count( array_unique( $hashes ) ) );
    }

    public function testItIsStableForUnicodeAndSlashes(): void
    {
        $this->assertSame( hash( 'sha256', '"a/b — ü"' ), Canon::hash( 'a/b — ü' ) );
    }

    public function testAStringHashIsTheHashOfItsJson(): void
    {
        $this->assertSame( hash( 'sha256', '"abc"' ), Canon::hash( 'abc' ) );
        $this->assertSame( hash( 'sha256', 'null' ), Canon::hash( null ) );
    }

    public function testDifferentInvalidUtf8StringsDoNotCollide(): void
    {
        $this->assertNotSame( Canon::hash( "a\xff" ), Canon::hash( "b\xfe" ) );
        $this->assertNotSame( Canon::hash( [ 'k' => "\xff" ] ), Canon::hash( [ 'k' => "\xfe" ] ) );
        $this->assertSame( Canon::hash( "a\xff" ), Canon::hash( "a\xff" ), 'still deterministic' );
        $this->assertNotSame( Canon::hash( "\xff" ), Canon::hash( null ) );
    }

    public function testAValidStringsHashIsUnchanged(): void
    {
        $this->assertSame( hash( 'sha256', '"héllo"' ), Canon::hash( 'héllo' ) );
    }

    /** @return array<string, array{string}> */
    public static function invalidUtf8(): array
    {
        return [
            'lone continuation byte' => [ "\x80" ],
            'truncated 3-byte sequence' => [ "ab\xe2\x82" ],
            'overlong slash' => [ "\xc0\xaf" ],
            'utf-16 surrogate' => [ "\xed\xa0\x80" ],
            'above U+10FFFF' => [ "\xf4\x90\x80\x80" ],
            'invalid byte after valid text' => [ "héllo\xff" ],
        ];
    }

    #[DataProvider( 'invalidUtf8' )]
    public function testInvalidUtf8IsHashedAsItsBytesNotAsNull( string $bad ): void
    {
        $expected = hash( 'sha256', '{"__bytes":"' . base64_encode( $bad ) . '"}' );
        $this->assertSame( $expected, Canon::hash( $bad ) );
        $this->assertNotSame( Canon::hash( null ), Canon::hash( $bad ) );
    }

    public function testValidMultibyteAndEmptyStringsAreNotTreatedAsInvalid(): void
    {
        $this->assertSame( hash( 'sha256', '"日本語 😀"' ), Canon::hash( '日本語 😀' ) );
        $this->assertSame( hash( 'sha256', '""' ), Canon::hash( '' ) );
    }

    public function testItDoesNotNeedTheMbstringExtension(): void
    {
        // Hosts without ext-mbstring would fatal on mb_check_encoding(); the check must be PCRE-only.
        $source = (string) file_get_contents( __DIR__ . '/../src/Canon.php' );
        $this->assertDoesNotMatchRegularExpression( '/\bmb_[a-z_]+\s*\(/', $source );
    }
}
