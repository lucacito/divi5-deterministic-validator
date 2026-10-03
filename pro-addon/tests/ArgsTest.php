<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Args;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class ArgsTest extends TestCase
{
    private function fails( callable $fn, string $message ): void
    {
        try {
            $fn();
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( $message, $e->getMessage() );
        }
    }

    public function testIntAcceptsOnlyRealIntegers(): void
    {
        $this->assertSame( 5, Args::int( [ 'id' => 5 ], 'id' ) );
        foreach ( [ '5', 5.0, 5.5, true, [], 'abc' ] as $bad ) {
            $this->fails( static fn () => Args::int( [ 'id' => $bad ], 'id' ), '"id" must be an integer.' );
        }
    }

    public function testIntRequiredOptionalAndMinimum(): void
    {
        $this->fails( static fn () => Args::int( [], 'id' ), '"id" is required.' );
        $this->fails( static fn () => Args::int( [ 'id' => null ], 'id' ), '"id" is required.' );
        $this->assertNull( Args::int( [], 'id', false ) );
        $this->assertNull( Args::int( [ 'id' => null ], 'id', false ) );
        $this->fails( static fn () => Args::int( [ 'id' => 0 ], 'id' ), '"id" must be at least 1.' );
        $this->assertSame( 0, Args::int( [ 'id' => 0 ], 'id', true, 0 ) );
    }

    public function testStringAcceptsOnlyStrings(): void
    {
        $this->assertSame( 'hi', Args::string( [ 's' => 'hi' ], 's' ) );
        foreach ( [ 5, true, [], 1.5 ] as $bad ) {
            $this->fails( static fn () => Args::string( [ 's' => $bad ], 's' ), '"s" must be a string.' );
        }
    }

    public function testStringRequiredBlankOptionalAndLength(): void
    {
        $this->fails( static fn () => Args::string( [], 's' ), '"s" is required.' );
        $this->fails( static fn () => Args::string( [ 's' => "  \n" ], 's' ), '"s" must not be empty.' );
        $this->assertNull( Args::string( [], 's', false ) );
        $this->assertSame( '', Args::string( [ 's' => '' ], 's', false ), 'an optional string may be empty' );
        $this->fails( static fn () => Args::string( [ 's' => 'abcdef' ], 's', true, 5 ), '"s" must be at most 5 bytes.' );
        $this->assertSame( 'abcde', Args::string( [ 's' => 'abcde' ], 's', true, 5 ) );
    }

    public function testBoolIsStrict(): void
    {
        $this->assertFalse( Args::bool( [], 'b' ) );
        $this->assertFalse( Args::bool( [ 'b' => null ], 'b' ) );
        $this->assertTrue( Args::bool( [ 'b' => true ], 'b' ) );
        $this->assertFalse( Args::bool( [ 'b' => false ], 'b' ) );
        foreach ( [ 'true', 1, 0, 'false', [] ] as $bad ) {
            $this->fails( static fn () => Args::bool( [ 'b' => $bad ], 'b' ), '"b" must be true or false.' );
        }
    }

    public function testListOf(): void
    {
        $this->assertSame( [ 1, 2 ], Args::listOf( [ 'l' => [ 1, 2 ] ], 'l', 5 ) );
        $this->fails( static fn () => Args::listOf( [], 'l', 5 ), '"l" is required.' );
        $this->fails( static fn () => Args::listOf( [ 'l' => 'x' ], 'l', 5 ), '"l" must be a list.' );
        $this->fails( static fn () => Args::listOf( [ 'l' => [ 'a' => 1 ] ], 'l', 5 ), '"l" must be a list.' );
        $this->fails( static fn () => Args::listOf( [ 'l' => [] ], 'l', 5 ), '"l" must not be empty.' );
        $this->fails( static fn () => Args::listOf( [ 'l' => [ 1, 2, 3 ] ], 'l', 2 ), '"l" must have at most 2 items.' );
    }
}
