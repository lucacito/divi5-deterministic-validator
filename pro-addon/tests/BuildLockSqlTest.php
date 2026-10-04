<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Site\BuildLock;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/OptionsStubWpdb.php';

/**
 * The lock on a real database is NOT `add_option` (core's add_option ends in INSERT ... ON DUPLICATE KEY UPDATE, so two requests
 * can both win it): it is one INSERT IGNORE whose affected-row count says who won, a compare-and-swap UPDATE for taking over a
 * stale lock, and a DELETE that only removes the value this request wrote.
 */
class BuildLockSqlTest extends TestCase
{
    private OptionsStubWpdb $db;

    protected function setUp(): void
    {
        $this->db                = new OptionsStubWpdb();
        $GLOBALS['wpdb']         = $this->db;
        $GLOBALS['__wp_options'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
    }

    public function testOnlyTheFirstOfTwoRequestsGetsTheLock(): void
    {
        $a = new BuildLock( 0 );
        $b = new BuildLock( 0 );
        $this->assertTrue( $a->acquire( 7 ) );
        $this->assertFalse( $b->acquire( 7 ), 'the second INSERT IGNORE inserts nothing, so it does not hold the lock' );
        $this->assertStringStartsWith( 'INSERT IGNORE INTO wp_options', $this->db->statements[0] );
        $this->assertStringContainsString( "'no'", $this->db->statements[0], 'autoload no' );
    }

    public function testAStaleLockIsTakenOverByExactlyOneRequest(): void
    {
        $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = ( time() - 30 ) . ':deadbeef';
        $a = new BuildLock( 0, 15 );
        $b = new BuildLock( 0, 15 );
        $this->assertTrue( $a->acquire( 7 ), 'older than 15 seconds: taken over' );
        $this->assertFalse( $b->acquire( 7 ), 'the fresh lock of the first taker is respected' );
        $swaps = array_filter( $this->db->statements, static fn ( string $s ): bool => str_starts_with( $s, 'UPDATE wp_options' ) );
        $this->assertCount( 1, $swaps );
        $this->assertStringContainsString( "AND option_value = '" . ( time() - 30 ) . ":deadbeef'", array_values( $swaps )[0], 'the swap only succeeds against the stale value it read' );
    }

    public function testASwapAgainstAValueThatChangedMeanwhileDoesNotWin(): void
    {
        $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = ( time() - 30 ) . ':old';
        $db = $this->db;
        // Between this request reading the stale lock and swapping it, another request takes it over.
        $GLOBALS['wpdb'] = new class( $db ) {
            public string $options = 'wp_options';

            public function __construct( private OptionsStubWpdb $inner )
            {
            }

            public function prepare( string $q, mixed ...$a ): string
            {
                return $this->inner->prepare( $q, ...$a );
            }

            public function get_var( string $q ): ?string
            {
                $v = $this->inner->get_var( $q );
                $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = time() . ':someone-else';   // the other request wins first

                return $v;
            }

            public function query( string $q ): int
            {
                return $this->inner->query( $q );
            }
        };
        $this->assertFalse( ( new BuildLock( 0, 15 ) )->acquire( 7 ), 'the compare-and-swap fails: it must not steal a lock that is no longer the stale one' );
        $this->assertStringEndsWith( ':someone-else', $GLOBALS['__wp_options']['aied_pro_buildlock_7'] );
    }

    public function testReleaseOnlyRemovesTheLockThisRequestWrote(): void
    {
        $a = new BuildLock( 0, 15 );
        $this->assertTrue( $a->acquire( 7 ) );
        // This request ran past the expiry and another request took the lock over; its finally must not delete the new owner's lock.
        $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = time() . ':the-new-owner';
        $a->release( 7 );
        $this->assertSame( true, isset( $GLOBALS['__wp_options']['aied_pro_buildlock_7'] ) );
        $this->assertStringEndsWith( ':the-new-owner', $GLOBALS['__wp_options']['aied_pro_buildlock_7'] );

        $b = new BuildLock( 0, 15 );
        unset( $GLOBALS['__wp_options']['aied_pro_buildlock_7'] );
        $this->assertTrue( $b->acquire( 7 ) );
        $b->release( 7 );
        $this->assertArrayNotHasKey( 'aied_pro_buildlock_7', $GLOBALS['__wp_options'], 'its own lock is removed' );
    }

    public function testEachUserHasTheirOwnLockAndAFreshLockIsRespectedWhileWaiting(): void
    {
        $this->assertTrue( ( new BuildLock( 0 ) )->acquire( 7 ) );
        $this->assertTrue( ( new BuildLock( 0 ) )->acquire( 8 ) );
        $start = microtime( true );
        $this->assertFalse( ( new BuildLock( 250, 15 ) )->acquire( 7 ) );
        $this->assertGreaterThanOrEqual( 0.2, microtime( true ) - $start );
    }
}
