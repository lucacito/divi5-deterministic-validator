<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Compat;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Compat.php';

/** The add-on only runs against a free plugin it understands. */
class CompatTest extends TestCase
{
    private static function all(): \Closure
    {
        return static fn ( string $c ): bool => true;
    }

    public function testAMissingFreePluginIsReported(): void
    {
        foreach ( [ null, '' ] as $none ) {
            $r = Compat::check( $none, self::all() );
            $this->assertFalse( $r['ok'] );
            $this->assertSame( 'free_missing', $r['reason'] );
            $this->assertStringContainsString( 'JHMG AI Editor for Divi 5', $r['message'] );
        }
    }

    public function testAFreePluginBelowTheMinimumIsTooOld(): void
    {
        $r = Compat::check( '4.3.9', self::all() );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'free_too_old', $r['reason'] );
        $this->assertStringContainsString( Compat::MIN_FREE, $r['message'] );
        $this->assertStringContainsString( '4.3.9', $r['message'] );
    }

    public function testTheMinimumAndNewerAreAccepted(): void
    {
        foreach ( [ '4.9.0', '4.9.1', '4.10.0', '5.0.0' ] as $v ) {
            $r = Compat::check( $v, self::all() );
            $this->assertTrue( $r['ok'], $v );
            $this->assertNull( $r['reason'] );
        }
        $this->assertSame( '4.9.0', Compat::MIN_FREE );
    }

    public function testTheFreePluginJustBelowTheMinimumIsTooOld(): void
    {
        $r = Compat::check( '4.8.9', self::all() );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'free_too_old', $r['reason'] );
    }

    public function testAMissingFreeClassIsReportedByName(): void
    {
        $r = Compat::check( '4.9.0', static fn ( string $c ): bool => $c !== 'AiEditorDivi5\\WP\\HistoryService' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'class_missing', $r['reason'] );
        $this->assertStringContainsString( 'AiEditorDivi5\\WP\\HistoryService', $r['message'] );
    }

    public function testEveryRequiredClassIsChecked(): void
    {
        $asked = [];
        Compat::check( '4.9.0', static function ( string $c ) use ( &$asked ): bool {
            $asked[] = $c;

            return true;
        } );
        $this->assertSame( Compat::REQUIRED_CLASSES, $asked );
    }

    public function testTheVersionIsCheckedBeforeTheClasses(): void
    {
        $asked = 0;
        Compat::check( '4.0.0', static function () use ( &$asked ): bool {
            $asked++;

            return true;
        } );
        $this->assertSame( 0, $asked, 'an old free plugin is reported without touching its classes' );
    }
}
