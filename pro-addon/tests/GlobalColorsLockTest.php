<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalColorsLock;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/WpBuildShims.php';
require_once __DIR__ . '/OptionsStubWpdb.php';

/** One global-colors write at a time on the whole site: two requests must not both write a full-map snapshot. */
class GlobalColorsLockTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['wpdb']         = null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
    }

    public function testItIsHeldUntilReleased(): void
    {
        $a = new GlobalColorsLock( 0 );
        $b = new GlobalColorsLock( 0 );
        $this->assertTrue( $a->acquire() );
        $this->assertFalse( $b->acquire(), 'a second request cannot take it' );
        $a->release();
        $this->assertTrue( $b->acquire() );
        $b->release();
        $this->assertSame( [], $GLOBALS['__wp_options'], 'release leaves no row behind' );
    }

    public function testItIsOneSiteWideLockWhoseRowUninstallRemoves(): void
    {
        $lock = new GlobalColorsLock( 0 );
        $lock->acquire();
        $this->assertCount( 1, $GLOBALS['__wp_options'] );
        $this->assertStringStartsWith( 'aied_pro_buildlock_', (string) array_key_first( $GLOBALS['__wp_options'] ), 'the Pro uninstall deletes every option with this prefix' );
    }

    public function testALockLeftByACrashedRequestIsTakenOverAfterItsExpiry(): void
    {
        $lock = new GlobalColorsLock( 0, 30 );
        $lock->acquire();
        $key = (string) array_key_first( $GLOBALS['__wp_options'] );
        $lock->release();
        $GLOBALS['__wp_options'][ $key ] = ( time() - 40 ) . ':dead';
        $this->assertTrue( ( new GlobalColorsLock( 0, 30 ) )->acquire(), 'older than 30 seconds: taken over' );
        $GLOBALS['__wp_options'][ $key ] = ( time() - 5 ) . ':live';
        $this->assertFalse( ( new GlobalColorsLock( 0, 30 ) )->acquire(), 'a fresh lock is respected' );
    }

    public function testOnARealDatabaseItIsTheAtomicInsertIgnore(): void
    {
        $GLOBALS['wpdb'] = new OptionsStubWpdb();
        $a = new GlobalColorsLock( 0 );
        $b = new GlobalColorsLock( 0 );
        $this->assertTrue( $a->acquire() );
        $this->assertFalse( $b->acquire() );
        $this->assertStringStartsWith( 'INSERT IGNORE INTO wp_options', $GLOBALS['wpdb']->statements[0] );
    }

    public function testReleasingALockThatIsNotHeldIsHarmless(): void
    {
        ( new GlobalColorsLock( 0 ) )->release();
        $this->assertSame( [], $GLOBALS['__wp_options'] );
    }
}
