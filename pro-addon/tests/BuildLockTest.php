<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Site\BuildLock;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/WpBuildShims.php';

/** One add_page at a time per user: a parallel tool call must never create a page the build does not record. */
class BuildLockTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = [];
    }

    public function testItIsHeldUntilReleasedAndPerUser(): void
    {
        $lock = new BuildLock( 0 );
        $this->assertTrue( $lock->acquire( 7 ) );
        $this->assertFalse( $lock->acquire( 7 ), 'the same user cannot take it twice' );
        $this->assertTrue( $lock->acquire( 8 ), 'another user has their own lock' );
        $lock->release( 7 );
        $this->assertTrue( $lock->acquire( 7 ) );
    }

    public function testALockLeftByACrashedRequestIsTakenOverAfterItsExpiry(): void
    {
        $lock = new BuildLock( 0, 15 );
        $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = (string) ( time() - 20 );
        $this->assertTrue( $lock->acquire( 7 ), 'older than 15 seconds: taken over' );
        $lock->release( 7 );
        $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = (string) ( time() - 3 );
        $this->assertFalse( $lock->acquire( 7 ), 'a fresh lock is respected' );
    }

    public function testItWaitsForTheLockForAtMostTheGivenTime(): void
    {
        $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = (string) time();
        $start = microtime( true );
        $this->assertFalse( ( new BuildLock( 250, 15 ) )->acquire( 7 ) );
        $took = microtime( true ) - $start;
        $this->assertGreaterThanOrEqual( 0.2, $took );
        $this->assertLessThan( 2.0, $took );
    }

    public function testReleasingALockThatIsNotHeldIsHarmless(): void
    {
        ( new BuildLock( 0 ) )->release( 7 );
        $this->assertSame( [], $GLOBALS['__wp_options'] );
    }
}
