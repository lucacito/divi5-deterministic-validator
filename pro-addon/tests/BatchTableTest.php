<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchTable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/BatchStubWpdb.php';

class BatchTableTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb']            = new BatchStubWpdb();
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        $GLOBALS['__wp_dbdelta_sql'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
    }

    public function testTheTableIsPrefixedAndTheSchemaHasEveryColumn(): void
    {
        $this->assertSame( 'wp_aied_pro_batches', BatchTable::name() );
        $sql = BatchTable::schemaSql();
        $this->assertStringContainsString( 'CREATE TABLE wp_aied_pro_batches', $sql );
        foreach ( [ 'id ', 'user_id', 'tool', 'created_at', 'summary', 'status', 'items', 'PRIMARY KEY', 'DEFAULT CHARSET=utf8mb4' ] as $part ) {
            $this->assertStringContainsString( $part, $sql, $part );
        }
    }

    public function testEnsureReadyCreatesTheTableOnceAndRemembersIt(): void
    {
        BatchTable::ensureReady();
        $this->assertCount( 1, $GLOBALS['__wp_dbdelta_sql'] );
        $this->assertSame( 1, $GLOBALS['__wp_options'][ BatchTable::DB_VERSION_OPTION ] );
        BatchTable::ensureReady();
        $this->assertCount( 1, $GLOBALS['__wp_dbdelta_sql'], 'already created: no second CREATE' );
    }

    public function testAHostThatRefusesCreateTableBacksOffInsteadOfRetryingEveryRequest(): void
    {
        $GLOBALS['wpdb']->count = null;   // the table still does not exist after dbDelta
        BatchTable::ensureReady();
        $this->assertArrayNotHasKey( BatchTable::DB_VERSION_OPTION, $GLOBALS['__wp_options'] );
        $this->assertArrayHasKey( BatchTable::BACKOFF_TRANSIENT, $GLOBALS['__wp_transients'] );
        BatchTable::ensureReady();
        $this->assertCount( 1, $GLOBALS['__wp_dbdelta_sql'], 'backed off' );
        BatchTable::ensureReady( true );
        $this->assertCount( 2, $GLOBALS['__wp_dbdelta_sql'], 'activation forces a retry' );
    }

    public function testDropRemovesTheTableAndItsState(): void
    {
        $GLOBALS['__wp_options'][ BatchTable::DB_VERSION_OPTION ] = 1;
        $GLOBALS['__wp_transients'][ BatchTable::BACKOFF_TRANSIENT ] = 1;
        BatchTable::drop();
        $this->assertContains( [ 'query', 'DROP TABLE IF EXISTS wp_aied_pro_batches' ], $GLOBALS['wpdb']->calls );
        $this->assertSame( [], $GLOBALS['__wp_options'] );
        $this->assertSame( [], $GLOBALS['__wp_transients'] );
    }

    public function testTheMultisiteDropFilterAddsOurTable(): void
    {
        $this->assertSame( [ 'wp_2_posts', 'wp_aied_pro_batches' ], BatchTable::dropTablesFilter( [ 'wp_2_posts' ] ) );
        $this->assertSame( [ 'wp_aied_pro_batches' ], BatchTable::dropTablesFilter( 'junk' ) );
    }
}
