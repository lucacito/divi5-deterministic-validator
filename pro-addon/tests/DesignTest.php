<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

class DesignTest extends TestCase
{
    public function testMakeHoldsTheFreeLanguageAndKit(): void
    {
        $d = Design::make( 'editorial', 'quiet-solid' );
        $this->assertSame( 'editorial', $d->languageId );
        $this->assertSame( 'quiet-solid', $d->kitId );
        $this->assertSame( DesignLanguages::get( 'editorial' ), $d->language );
        $this->assertSame( StyleKits::get( 'quiet-solid' ), $d->kit );
    }

    public function testEveryShippedLanguageAndKitCanBeMade(): void
    {
        foreach ( DesignLanguages::ids() as $language ) {
            foreach ( StyleKits::ids() as $kit ) {
                $this->assertSame( $kit, Design::make( $language, $kit )->kitId );
            }
        }
    }

    public function testAnUnknownLanguageIsRefusedWithTheValidIds(): void
    {
        try {
            Design::make( 'nope', 'quiet-solid' );
            $this->fail( 'expected InvalidArgumentException' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'nope', $e->getMessage() );
            foreach ( DesignLanguages::ids() as $id ) {
                $this->assertStringContainsString( $id, $e->getMessage() );
            }
        }
    }

    public function testAnUnknownKitIsRefusedWithTheValidIds(): void
    {
        try {
            Design::make( 'editorial', 'nope' );
            $this->fail( 'expected InvalidArgumentException' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'nope', $e->getMessage() );
            foreach ( StyleKits::ids() as $id ) {
                $this->assertStringContainsString( $id, $e->getMessage() );
            }
        }
    }

    public function testDefaultIsNotAcceptedHere(): void
    {
        foreach ( [ [ 'default', 'quiet-solid' ], [ 'editorial', 'default' ] ] as [ $l, $k ] ) {
            try {
                Design::make( $l, $k );
                $this->fail( 'expected InvalidArgumentException' );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( 'default', $e->getMessage() );
            }
        }
    }

    public function testEqualsComparesLanguageAndKit(): void
    {
        $a = Design::make( 'editorial', 'quiet-solid' );
        $this->assertTrue( $a->equals( Design::make( 'editorial', 'quiet-solid' ) ) );
        $this->assertFalse( $a->equals( Design::make( 'luxe', 'quiet-solid' ) ) );
        $this->assertFalse( $a->equals( Design::make( 'editorial', 'steady-solid' ) ) );
    }

    public function testTheFieldsAreReadonly(): void
    {
        $d = Design::make( 'editorial', 'quiet-solid' );
        $this->expectException( \Error::class );
        // @phpstan-ignore-next-line
        $d->kitId = 'x';
    }
}
