<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryTable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';

class HistoryTableTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb']             = new FakeWpdb();
        $GLOBALS['__wp_options']     = [];
        $GLOBALS['__wp_dbdelta_sql'] = [];
        $GLOBALS['__wp_transients']  = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']         = null;
        $GLOBALS['__wp_options'] = [];
    }

    public function testNameUsesTheSitePrefix(): void
    {
        $GLOBALS['wpdb']->prefix = 'wp7_';
        $this->assertSame( 'wp7_ai_editor_divi5_history', HistoryTable::name() );
    }

    public function testSchemaFollowsDbDeltaRulesAndHasTheSpecColumns(): void
    {
        $sql = HistoryTable::schemaSql();
        $this->assertStringContainsString( 'CREATE TABLE wp_ai_editor_divi5_history (', $sql );
        $this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql, 'dbDelta needs two spaces after PRIMARY KEY' );
        $this->assertStringContainsString( 'UNIQUE KEY page_version (page_id, version_id)', $sql );
        foreach ( [ 'page_id', 'version_id', 'saved_at', 'tool', 'actor', 'bytes', 'sha256', 'label', 'content' ] as $needle ) {
            $this->assertMatchesRegularExpression( '/^\s+' . preg_quote( $needle, '/' ) . '\s/m', $sql, "{$needle} must start its own line" );
        }
        $this->assertStringContainsString( 'longtext', $sql );
    }

    public function testCreateTableRunsDbDeltaAndTheTableThenExists(): void
    {
        $this->assertFalse( HistoryTable::exists() );
        HistoryTable::createTable();
        $this->assertTrue( HistoryTable::exists() );
        $this->assertCount( 1, $GLOBALS['__wp_dbdelta_sql'] );
    }

    public function testStageHelpersFollowTheOption(): void
    {
        $this->assertFalse( HistoryTable::hasTable() );
        $this->assertFalse( HistoryTable::migrationDone() );
        $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ] = HistoryTable::STAGE_TABLE;
        $this->assertTrue( HistoryTable::hasTable() );
        $this->assertFalse( HistoryTable::migrationDone() );
        $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ] = HistoryTable::STAGE_MIGRATED;
        $this->assertTrue( HistoryTable::hasTable() );
        $this->assertTrue( HistoryTable::migrationDone() );
    }

    public function testDropRemovesTheTableAndTheOption(): void
    {
        HistoryTable::createTable();
        $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ] = HistoryTable::STAGE_MIGRATED;
        HistoryTable::drop();
        $this->assertFalse( HistoryTable::exists() );
        $this->assertArrayNotHasKey( HistoryTable::DB_VERSION_OPTION, $GLOBALS['__wp_options'] );
    }

    public function testDropTablesFilterAddsBothPluginTablesForThatSitesPrefix(): void
    {
        $GLOBALS['wpdb']->prefix = 'wp9_';
        $out = HistoryTable::dropTablesFilter( [ 'wp9_options' ] );
        $this->assertSame( [ 'wp9_options', 'wp9_ai_editor_divi5_history', 'wp9_ai_editor_divi5_usage' ], $out );
        $this->assertSame( [ 'wp9_ai_editor_divi5_history', 'wp9_ai_editor_divi5_usage' ], HistoryTable::dropTablesFilter( null ) );
    }
}
