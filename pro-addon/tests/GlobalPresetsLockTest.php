<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalColorsLock;
use AiEditorDivi5\Pro\Settings\GlobalPresetsLock;
use AiEditorDivi5\Pro\Settings\GlobalVariablesLock;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/WpBuildShims.php';
require_once __DIR__ . '/OptionsStubWpdb.php';

/** One global-presets write at a time on the whole site (Divi's save replaces the whole preset data). */
class GlobalPresetsLockTest extends TestCase
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
        $a = new GlobalPresetsLock( 0 );
        $b = new GlobalPresetsLock( 0 );
        $this->assertTrue( $a->acquire() );
        $this->assertFalse( $b->acquire(), 'a second request cannot take it' );
        $a->release();
        $this->assertTrue( $b->acquire() );
        $b->release();
        $this->assertSame( [], $GLOBALS['__wp_options'], 'release leaves no row behind' );
    }

    public function testItIsOneSiteWideLockWhoseRowUninstallRemoves(): void
    {
        ( new GlobalPresetsLock( 0 ) )->acquire();
        $this->assertCount( 1, $GLOBALS['__wp_options'] );
        $this->assertStringStartsWith( 'aied_pro_buildlock_gpresets_', (string) array_key_first( $GLOBALS['__wp_options'] ), 'the Pro uninstall deletes every option starting aied_pro_buildlock_' );
    }

    public function testItIsIndependentOfTheColorsAndTheVariablesLocks(): void
    {
        $colors  = new GlobalColorsLock( 0 );
        $vars    = new GlobalVariablesLock( 0 );
        $presets = new GlobalPresetsLock( 0 );
        $this->assertTrue( $colors->acquire() );
        $this->assertTrue( $vars->acquire(), 'variables and colors live in different options' );
        $this->assertTrue( $presets->acquire(), 'presets live in a third option' );
        $colors->release();
        $vars->release();
        $presets->release();
        $this->assertSame( [], $GLOBALS['__wp_options'] );
    }

    public function testALockLeftByACrashedRequestIsTakenOverAfterItsExpiry(): void
    {
        $lock = new GlobalPresetsLock( 0, 30 );
        $lock->acquire();
        $key = (string) array_key_first( $GLOBALS['__wp_options'] );
        $lock->release();
        $GLOBALS['__wp_options'][ $key ] = ( time() - 40 ) . ':dead';
        $this->assertTrue( ( new GlobalPresetsLock( 0, 30 ) )->acquire(), 'older than 30 seconds: taken over' );
        $GLOBALS['__wp_options'][ $key ] = ( time() - 5 ) . ':live';
        $this->assertFalse( ( new GlobalPresetsLock( 0, 30 ) )->acquire(), 'a fresh lock is respected' );
    }

    public function testOnARealDatabaseItIsTheAtomicInsertIgnore(): void
    {
        $GLOBALS['wpdb'] = new OptionsStubWpdb();
        $a = new GlobalPresetsLock( 0 );
        $b = new GlobalPresetsLock( 0 );
        $this->assertTrue( $a->acquire() );
        $this->assertFalse( $b->acquire() );
        $this->assertStringStartsWith( 'INSERT IGNORE INTO wp_options', $GLOBALS['wpdb']->statements[0] );
    }

    public function testReleasingALockThatIsNotHeldIsHarmless(): void
    {
        ( new GlobalPresetsLock( 0 ) )->release();
        $this->assertSame( [], $GLOBALS['__wp_options'] );
    }
}
