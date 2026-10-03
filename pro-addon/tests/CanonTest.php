<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Canon;
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
}
