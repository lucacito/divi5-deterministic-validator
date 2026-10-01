# History Table Storage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the AI-edit undo history from the `_aied_history` post-meta blob to a plugin-owned table, migrate existing meta history automatically, and ship it as 4.1.0 (local merge only).

**Architecture:** `HistoryStore` stays the only code that touches storage but now talks to `$wpdb` (one row per snapshot, per-page `version_id`). `HistoryTable` owns schema, stage tracking, lifecycle hooks and cleanup; `HistoryMigration` imports legacy meta (batched on `plugins_loaded`, plus lazily per page). `PageHistory` (pure rules) and every transport/tool contract are unchanged. Unit tests run against a SQLite-backed fake `$wpdb` that executes the real SQL; a real-database script on the Docker site proves the MySQL side.

**Tech Stack:** PHP 8.1+ (dev box 8.5), WordPress `$wpdb`/`dbDelta`, PHPUnit, PDO SQLite (tests only), Docker WP + wp-cli.

**Spec:** `docs/superpowers/specs/2026-10-01-history-table-storage-design.md` (approved by the owner 2026-10-01). Companion: `docs/superpowers/specs/2026-10-01-ai-edit-history-undo-design.md` (behaviour that must not change).

## Spec adjustment found while planning (tell the owner at handoff)

The spec says an undecodable legacy meta value is "left in place and skipped". That would keep the batch migration from ever finishing (the stage would never reach "migrated"). This plan instead **deletes a legacy meta value only when it yields zero valid snapshots** (junk, or `[]`), which matches 4.0.0's own behaviour (its `load()` treated such values as an empty history and its next save overwrote them). A value with at least one valid snapshot is imported and then deleted; a value whose rows fail to insert is left in place and retried.

## Global Constraints

- PHP `>= 8.1`, WordPress `>= 6.0` (so no `%i` placeholder in `$wpdb->prepare`); `declare(strict_types=1)` and the `ABSPATH` guard in every new plugin file.
- **Behaviour must not change:** retention 10, `PageHistory::MAX_BYTES = 524288` (512 KB), `PageHistory::MAX_TOTAL_BYTES = 786432` (768 KB); skip reasons `too_large | invalid_encoding | store_failed | duplicate`; undo available only when `history.stored` is true; restore bypasses the validator gate and reports the verdict; `HistoryService::write()` never blocks the page write because of a history failure.
- Table name `{prefix}ai_editor_divi5_history`; its own option `ai_editor_divi5_history_db_version` (stage 1 = table exists, stage 2 = legacy meta migrated).
- Table writes use `$wpdb->insert` / `$wpdb->delete` (wpdb escapes) → **never `wp_slash`** content going into the table. Content must round-trip byte-for-byte.
- No `mb_*` functions (WordPress does not polyfill them).
- `tests/WpOrgComplianceTest.php` is **never weakened**. The only edits allowed are two explicit, justified exceptions (Task 1: the `upgrade.php` include; Task 3: the new option writer), exactly as `UsageTracker` has them.
- Words that the compliance scanner rejects anywhere in `wp-plugin/` text files: `upgrade`, `premium`, `Pro`, `licence key` (case-insensitive) and the substrings `update_plugins`, `Licensing`, `PREMIUM`. **Do not use them in any comment, string or method name** (use "update", "migrate", "ensure", "stage"). The one allowed hit is the line containing `wp-admin/includes/upgrade.php`.
- DB lint ignores use only the DB rule names `UsageTracker` already uses (`WordPress.DB.PreparedSQL.InterpolatedNotPrepared`, `WordPress.DB.DirectDatabaseQuery.DirectQuery`, `WordPress.DB.DirectDatabaseQuery.NoCaching`, `WordPress.DB.DirectDatabaseQuery.SchemaChange`, `PluginCheck.Security.DirectDB.UnescapedDBParameter`, and `WordPress.DB.PreparedSQL.NotPrepared` if Plugin Check asks). Never an escaping ignore.
- Work on branch `feat/history-table` **in the main checkout** (not a worktree: the Docker site mounts `./wp-plugin` from this checkout, and Tasks 4–5 need it). Merge to local `main` only. **Never push, upload or send anything.**
- Every commit message ends with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Run the suite with `vendor/bin/phpunit` (the project uses `failOnWarning`/`failOnRisky`); `make test` runs the same.
- `src/` and `wp-plugin/validator/` are untouched by this plan (no validator change), so they stay in sync.

## Review Focus

Inputs/conditions the spec implies that a person is most likely to hit; each has a pinning test in the task named.

1. **Garbage legacy meta** (`not json`, `{"items":"nope"}`, `[1,2,3]`, an empty string) never blocks a write, an undo, or the migration finishing. (Task 3)
2. **A site that was updated but not yet migrated:** `list_page_history` / `restore_page_version` on a page still holding 4.0.0 meta must show and restore that history (lazy import), and the next snapshot id must continue after the imported ids. (Task 3)
3. **Host forbids `CREATE TABLE`:** no fatal, page writes still succeed with `history.reason = store_failed`, no repeated `dbDelta` on every request (backoff), activation retries immediately. (Task 3)
4. **Two writers racing on one page** must not corrupt or silently mis-attribute a snapshot: a clash on `(page_id, version_id)` with different content is reported as `store_failed`. (Task 2)
5. **Content fidelity:** backslashes, quotes, newlines, `\u003C`-style escapes, 4-byte emoji, and a ~400 KB snapshot round-trip byte-for-byte on the real MySQL/MariaDB table, and permanently deleting a page removes its rows (trashing keeps them). (Task 2 on the fake, Task 4 on the real database)

---

## File Structure

| File | Responsibility |
|---|---|
| `wp-plugin/src/HistoryTable.php` (new) | Table name, `dbDelta` schema, existence check, stage option, `ensureReady()`, `register()` hooks (`plugins_loaded`, `delete_post`, `wpmu_drop_tables`), `drop()` |
| `wp-plugin/src/HistoryMigration.php` (new) | Import `_aied_history` meta into the table: `importPage()` (one page) and `run()` (one batch) |
| `wp-plugin/src/HistoryStore.php` (rewrite) | The only code that queries the history table: `load`, `save`, `insertMissing`, `summaries`, `entry`, `recent`, `deleteForPage`, `deleteAll`, lazy legacy import |
| `wp-plugin/src/HistoryService.php` (edit) | `listFor`/`entry`/`recent` use the new store queries; drop the meta scan and cache workaround |
| `wp-plugin/src/PageHistory.php` (edit) | Reword the budget comment; remove the now-dead `recent()` |
| `wp-plugin/src/autoload.php`, `wp-plugin/jhmg-ai-editor-for-divi-5.php`, `wp-plugin/uninstall.php` (no change needed) | Require the new classes; register hooks; call `ensureReady(true)` on activation |
| `tests/FakeWpdb.php` (new) | SQLite-backed `$wpdb` stand-in (runs the real SQL) |
| `tests/bootstrap.php` (edit) | `ARRAY_A`, `esc_sql`, `delete_post_meta`, `dbDelta` shims |
| `tests/HistoryTableTest.php`, `tests/HistoryStoreTest.php`, `tests/HistoryMigrationTest.php` (new), `tests/HistoryServiceTest.php`, `tests/PageHistoryTest.php` (edit) | Unit tests |
| `tests/WpOrgComplianceTest.php` (edit) | Two justified exceptions only |
| `scripts/verify-history-table.php` (new), `Makefile` (edit) | Real-database check, `make verify-history` |
| `wp-plugin/readme.txt`, `jhmg-ai-editor-for-divi-5.php`, `CLAUDE.md`, `jhmg-ai-editor-for-divi-5.zip` | 4.1.0 release |

---

### Task 1: Test infrastructure and the `HistoryTable` core

**Files:**
- Create: `tests/FakeWpdb.php`, `wp-plugin/src/HistoryTable.php`, `tests/HistoryTableTest.php`
- Modify: `tests/bootstrap.php` (append), `wp-plugin/src/autoload.php`, `tests/WpOrgComplianceTest.php:75-77`

**Interfaces:**
- Produces: `FakeWpdb` (`Divi5Validator\Tests\FakeWpdb`) with public `$prefix`, `$postmeta`, `$insert_id`, `$failInserts`, `$denyCreate`, `$queries`; methods `createHistoryTable()`, `prepare()`, `get_results()`, `get_col()`, `get_var()`, `insert()`, `delete()`, `query()`, `suppress_errors()`, `get_charset_collate()`.
- Produces: `HistoryTable::DB_VERSION_OPTION` (`'ai_editor_divi5_history_db_version'`), `STAGE_TABLE = 1`, `STAGE_MIGRATED = 2`; `name(): string`, `schemaSql(): string`, `createTable(): void`, `exists(): bool`, `hasTable(): bool` (stage >= 1), `migrationDone(): bool` (stage >= 2), `drop(): void`, `dropTablesFilter(mixed $tables): array`.
- Test setup recipe used by every later test class: `$GLOBALS['wpdb'] = new FakeWpdb(); $GLOBALS['wpdb']->createHistoryTable(); $GLOBALS['__wp_options'] = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];`

- [ ] **Step 1: Create the branch**

```bash
cd "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator"
git checkout -b feat/history-table
```

- [ ] **Step 2: Create `tests/FakeWpdb.php`**

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

/**
 * In-memory SQLite stand-in for the few $wpdb methods the history code uses.
 * It executes the plugin's real SQL (plain ANSI SELECT / INSERT / DELETE), so a broken
 * query fails a unit test. MySQL-only behaviour (charsets, strict mode, dbDelta) is NOT
 * proven here: scripts/verify-history-table.php covers that on the real database.
 */
final class FakeWpdb
{
    public string $prefix   = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public int $insert_id   = 0;

    /** Simulate a failing INSERT (db error / disk full). */
    public bool $failInserts = false;
    /** Simulate a host that forbids CREATE TABLE. */
    public bool $denyCreate = false;
    /** @var list<string> every SQL string executed, for assertions */
    public array $queries = [];

    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = new \PDO( 'sqlite::memory:' );
        $this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
        $this->pdo->setAttribute( \PDO::ATTR_STRINGIFY_FETCHES, true ); // wpdb returns strings
    }

    /** Stand-in for what dbDelta() does on a real site. */
    public function createHistoryTable(): void
    {
        if ( $this->denyCreate ) {
            return;
        }
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->prefix}ai_editor_divi5_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id INTEGER NOT NULL,
                version_id INTEGER NOT NULL,
                saved_at TEXT NOT NULL,
                tool TEXT NOT NULL,
                actor INTEGER NOT NULL,
                bytes INTEGER NOT NULL,
                sha256 TEXT NOT NULL,
                label TEXT NULL,
                content TEXT NOT NULL,
                UNIQUE (page_id, version_id)
            )"
        );
    }

    public function get_charset_collate(): string
    {
        return '';
    }

    public function suppress_errors( bool $suppress = true ): bool
    {
        return false;
    }

    /** Supports %d and %s, with the values as a list or as one array argument. */
    public function prepare( string $query, mixed ...$args ): string
    {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        $i = 0;

        return (string) preg_replace_callback(
            '/%[ds]/',
            function ( array $m ) use ( &$i, $args ): string {
                $v = $args[ $i++ ];
                return '%d' === $m[0] ? (string) (int) $v : $this->pdo->quote( (string) $v );
            },
            $query
        );
    }

    /** @return list<array<string,mixed>> */
    public function get_results( string $sql, mixed $output = null ): array
    {
        $this->queries[] = $sql;
        try {
            return $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_ASSOC );
        } catch ( \PDOException $e ) {
            return [];
        }
    }

    /** @return list<string> */
    public function get_col( string $sql ): array
    {
        $this->queries[] = $sql;
        if ( str_contains( $sql, $this->postmeta ) ) {
            return $this->legacyMetaPageIds( $sql );
        }
        try {
            return $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_COLUMN );
        } catch ( \PDOException $e ) {
            return [];
        }
    }

    public function get_var( string $sql ): ?string
    {
        $this->queries[] = $sql;
        try {
            $v = $this->pdo->query( $sql )->fetchColumn();
            return false === $v ? null : (string) $v;
        } catch ( \PDOException $e ) {
            return null; // like wpdb with errors suppressed: a missing table yields null
        }
    }

    /** @param array<string,mixed> $data @param list<string> $format */
    public function insert( string $table, array $data, array $format = [] ): int|false
    {
        $this->queries[] = "INSERT INTO {$table} (" . implode( ', ', array_keys( $data ) ) . ')';
        if ( $this->failInserts ) {
            return false;
        }
        try {
            $cols = implode( ', ', array_keys( $data ) );
            $ph   = implode( ', ', array_map( static fn ( string $c ): string => ':' . $c, array_keys( $data ) ) );
            $stmt = $this->pdo->prepare( "INSERT INTO {$table} ({$cols}) VALUES ({$ph})" );
            $stmt->execute( $data );
            $this->insert_id = (int) $this->pdo->lastInsertId();
            return 1;
        } catch ( \PDOException $e ) {
            return false; // e.g. UNIQUE (page_id, version_id) clash
        }
    }

    /** @param array<string,mixed> $where @param list<string> $format */
    public function delete( string $table, array $where, array $format = [] ): int|false
    {
        $this->queries[] = "DELETE FROM {$table} WHERE " . implode( ' AND ', array_keys( $where ) );
        try {
            $clause = implode( ' AND ', array_map( static fn ( string $c ): string => "{$c} = :{$c}", array_keys( $where ) ) );
            $stmt   = $this->pdo->prepare( "DELETE FROM {$table} WHERE {$clause}" );
            $stmt->execute( $where );
            return $stmt->rowCount();
        } catch ( \PDOException $e ) {
            return false;
        }
    }

    public function query( string $sql ): int|false
    {
        $this->queries[] = $sql;
        try {
            return (int) $this->pdo->exec( $sql );
        } catch ( \PDOException $e ) {
            return false;
        }
    }

    /**
     * The legacy post-meta lives in the bootstrap's $GLOBALS['__wp_postmeta'] shim, not in SQLite,
     * so the one query that targets postmeta is answered from there.
     *
     * @return list<string>
     */
    private function legacyMetaPageIds( string $sql ): array
    {
        preg_match( "/meta_key = '([^']+)'/", $sql, $k );
        preg_match( '/LIMIT (\d+)/', $sql, $l );
        $ids = [];
        foreach ( $GLOBALS['__wp_postmeta'] as $pageId => $meta ) {
            if ( isset( $k[1] ) && isset( $meta[ $k[1] ] ) ) {
                $ids[] = (string) $pageId;
            }
        }
        sort( $ids, SORT_NUMERIC );

        return array_slice( $ids, 0, (int) ( $l[1] ?? 0 ) );
    }
}
```

- [ ] **Step 3: Append the shims to `tests/bootstrap.php`**

Append at the very end of the file:

```php

// ---- $wpdb / DB shims for the history-table tests ---------------------------------------
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'esc_sql' ) ) {
    function esc_sql( $s ) { return $s; }
}
if ( ! function_exists( 'delete_post_meta' ) ) {
    function delete_post_meta( $id, $key, $value = '' ) { unset( $GLOBALS['__wp_postmeta'][ (int) $id ][ $key ] ); return true; }
}
if ( ! function_exists( 'dbDelta' ) ) {
    // Records the SQL and asks the fake DB to create the history table (unless the test forbids it).
    function dbDelta( $sql ) {
        $GLOBALS['__wp_dbdelta_sql'][] = $sql;
        if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'createHistoryTable' ) && str_contains( (string) $sql, 'ai_editor_divi5_history' ) ) {
            $GLOBALS['wpdb']->createHistoryTable();
        }
        return [];
    }
}
```

- [ ] **Step 4: Write the failing test `tests/HistoryTableTest.php`**

```php
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
```

- [ ] **Step 5: Run it to see it fail**

Run: `vendor/bin/phpunit tests/HistoryTableTest.php`
Expected: errors — `Class "AiEditorDivi5\WP\HistoryTable" not found` / missing file.

- [ ] **Step 6: Create `wp-plugin/src/HistoryTable.php`**

(`ensureReady()` and `register()` come in Task 3, once `HistoryMigration` exists.)

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The undo-history table: name, schema, stage tracking and cleanup.
 * The queries on its rows live in HistoryStore.
 */
final class HistoryTable
{
    public const DB_VERSION_OPTION = 'ai_editor_divi5_history_db_version';

    /** The table exists (a lazy per-page import of old post-meta history already works). */
    public const STAGE_TABLE = 1;
    /** No legacy `_aied_history` post meta is left to import. */
    public const STAGE_MIGRATED = 2;

    public static function name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ai_editor_divi5_history';
    }

    /** One row per snapshot; version_id is the per-page id the tools expose. */
    public static function schemaSql(): string
    {
        global $wpdb;

        $table   = self::name();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            page_id     bigint(20) UNSIGNED NOT NULL,
            version_id  int(10) UNSIGNED    NOT NULL,
            saved_at    varchar(20)         NOT NULL,
            tool        varchar(50)         NOT NULL,
            actor       bigint(20) UNSIGNED NOT NULL,
            bytes       int(10) UNSIGNED    NOT NULL,
            sha256      char(64)            NOT NULL,
            label       varchar(255)            NULL DEFAULT NULL,
            content     longtext            NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY page_version (page_id, version_id)
        ) {$charset};";
    }

    public static function createTable(): void
    {
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta( self::schemaSql() );
    }

    /** True when the table can be queried (a missing table yields null even for COUNT(*)). */
    public static function exists(): bool
    {
        global $wpdb;

        $table = self::name();
        $prev  = $wpdb->suppress_errors( true );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->suppress_errors( $prev );

        return null !== $count;
    }

    public static function hasTable(): bool
    {
        return (int) get_option( self::DB_VERSION_OPTION, 0 ) >= self::STAGE_TABLE;
    }

    public static function migrationDone(): bool
    {
        return (int) get_option( self::DB_VERSION_OPTION, 0 ) >= self::STAGE_MIGRATED;
    }

    public static function drop(): void
    {
        global $wpdb;

        $table = self::name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        delete_option( self::DB_VERSION_OPTION );
    }

    /**
     * `wpmu_drop_tables` filter: when a multisite site is deleted, drop this plugin's tables too.
     *
     * @param mixed $tables the site's table names (with that site's prefix)
     * @return list<string>
     */
    public static function dropTablesFilter( mixed $tables ): array
    {
        $tables   = is_array( $tables ) ? array_values( $tables ) : [];
        $tables[] = self::name();
        $tables[] = UsageTracker::tableName();

        return array_values( array_unique( $tables ) );
    }
}
```

- [ ] **Step 7: Require it in `wp-plugin/src/autoload.php`**

Add this line directly after `require_once __DIR__ . '/PageHistory.php';`:

```php
require_once __DIR__ . '/HistoryTable.php';
```

- [ ] **Step 8: Add the one justified compliance exception**

In `tests/WpOrgComplianceTest.php`, extend `COMMERCIAL_EXCEPTIONS` (currently lines 75-77) to:

```php
    private const COMMERCIAL_EXCEPTIONS = [
        ['src/UsageTracker.php', "wp-admin/includes/upgrade.php", 'WordPress core include that provides dbDelta() for the usage-log table'],
        ['src/HistoryTable.php', "wp-admin/includes/upgrade.php", 'WordPress core include that provides dbDelta() for the history table'],
    ];
```

- [ ] **Step 9: Run the new test and the whole suite**

Run: `vendor/bin/phpunit tests/HistoryTableTest.php` → Expected: PASS (6 tests).
Run: `vendor/bin/phpunit` → Expected: all green (no existing test changed yet).

- [ ] **Step 10: Commit**

```bash
git add tests/FakeWpdb.php tests/bootstrap.php tests/HistoryTableTest.php tests/WpOrgComplianceTest.php wp-plugin/src/HistoryTable.php wp-plugin/src/autoload.php
git commit -m "feat: history table schema, stage helpers and a SQLite-backed fake wpdb

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `HistoryStore` on the table, `HistoryService` queries, tests moved over

**Files:**
- Modify: `wp-plugin/src/HistoryStore.php` (full rewrite), `wp-plugin/src/HistoryService.php`, `wp-plugin/src/PageHistory.php`, `tests/HistoryServiceTest.php` (full rewrite), `tests/PageHistoryTest.php`
- Create: `tests/HistoryStoreTest.php`

**Interfaces:**
- Consumes: Task 1's `HistoryTable::name()/hasTable()/drop()`, `FakeWpdb`, the setup recipe.
- Produces (all `public static` on `AiEditorDivi5\WP\HistoryStore`):
  - `const LEGACY_META_KEY = '_aied_history'`
  - `load(int $pageId): array{next:int, items:list<array>}` — items newest-first, each `{id:int, saved_at:string, tool:string, actor:int, bytes:int, sha256:string, label:?string, content:string}`
  - `save(int $pageId, array $history): bool` — inserts missing rows, deletes trimmed rows; false on insert failure/clash; never deletes on failure
  - `insertMissing(int $pageId, array $items): bool` — insert items not yet stored; an existing id with a different sha256 is a clash → false
  - `summaries(int $pageId): list<array{id,saved_at,tool,actor,bytes,label}>` (no content, no sha256)
  - `entry(int $pageId, int $versionId): ?array` (full item incl. content and sha256)
  - `recent(int $limit): list<array{page_id:int, version_id:int, saved_at:string, tool:string}>`
  - `deleteForPage(int $pageId): void`, `deleteAll(): void`
- `HistoryService::recent(int $limit = 5): list<array{page_id, version_id, saved_at, tool, title}>` (the `?array $pageIds` parameter is removed).

- [ ] **Step 1: Write `tests/HistoryStoreTest.php` (failing)**

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\PageHistory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';

class HistoryStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']  = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_postmeta'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']          = null;
        $GLOBALS['__wp_options']  = [];
        $GLOBALS['__wp_postmeta'] = [];
    }

    /** @return array<string,mixed> */
    private function item( int $id, string $content, string $at = '2026-10-01T00:00:00Z', ?string $label = null ): array
    {
        return [ 'id' => $id, 'saved_at' => $at, 'tool' => 'update_page_layout', 'actor' => 7, 'bytes' => strlen( $content ), 'sha256' => hash( 'sha256', $content ), 'label' => $label, 'content' => $content ];
    }

    private function countQueries( string $prefix ): int
    {
        return count( array_filter( $GLOBALS['wpdb']->queries, static fn ( string $q ): bool => str_starts_with( $q, $prefix ) ) );
    }

    public function testSaveThenLoadRoundTripsEveryFieldNewestFirst(): void
    {
        $items = [ $this->item( 2, 'two', '2026-10-01T02:00:00Z', 'before restore of #1' ), $this->item( 1, 'one' ) ];
        $this->assertTrue( HistoryStore::save( 5, [ 'next' => 3, 'items' => $items ] ) );
        $h = HistoryStore::load( 5 );
        $this->assertSame( $items, $h['items'] );
        $this->assertSame( 3, $h['next'] );
    }

    public function testContentRoundTripsBackslashesQuotesNewlinesAndEmoji(): void
    {
        $tricky = "<p class=\"a\">C:\\path \\\\ \\u003Cp\\u003E \n\t \u{1F600} \u{e9}</p>";
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, $tricky ) ] ] );
        $this->assertSame( $tricky, HistoryStore::entry( 5, 1 )['content'] );
        $this->assertSame( $tricky, HistoryStore::load( 5 )['items'][0]['content'] );
    }

    public function testSaveInsertsOnlyNewRowsAndDeletesTrimmedOnes(): void
    {
        HistoryStore::save( 5, [ 'next' => 4, 'items' => [ $this->item( 3, 'c' ), $this->item( 2, 'b' ), $this->item( 1, 'a' ) ] ] );
        $this->assertSame( 3, $this->countQueries( 'INSERT' ) );

        HistoryStore::save( 5, [ 'next' => 5, 'items' => [ $this->item( 4, 'd' ), $this->item( 3, 'c' ), $this->item( 2, 'b' ) ] ] );
        $this->assertSame( 4, $this->countQueries( 'INSERT' ), 'only version 4 is new' );
        $this->assertSame( [ 4, 3, 2 ], array_column( HistoryStore::summaries( 5 ), 'id' ), 'version 1 was trimmed' );
    }

    public function testNextIdSurvivesTrimmingThroughThirteenSnapshots(): void
    {
        for ( $i = 1; $i <= 13; $i++ ) {
            $r = PageHistory::record( HistoryStore::load( 5 ), "V{$i}", 'update_page_layout', 7, '2026-10-01T00:00:00Z' );
            $this->assertTrue( HistoryStore::save( 5, $r['history'] ) );
            $this->assertSame( $i, $r['version_id'] );
        }
        $h = HistoryStore::load( 5 );
        $this->assertSame( [ 13, 12, 11, 10, 9, 8, 7, 6, 5, 4 ], array_column( $h['items'], 'id' ) );
        $this->assertSame( 14, $h['next'] );
    }

    public function testSaveReturnsFalseOnInsertFailureAndKeepsExistingRows(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'a' ) ] ] );
        $GLOBALS['wpdb']->failInserts = true;
        $this->assertFalse( HistoryStore::save( 5, [ 'next' => 3, 'items' => [ $this->item( 2, 'b' ) ] ] ), 'would also trim version 1' );
        $GLOBALS['wpdb']->failInserts = false;
        $this->assertSame( [ 1 ], array_column( HistoryStore::summaries( 5 ), 'id' ), 'a failed save never deletes' );
    }

    public function testRacingWriterWithDifferentContentIsAClashNotASilentOverwrite(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'writer B state' ) ] ] );
        $this->assertFalse( HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'writer A state' ) ] ] ) );
        $this->assertSame( 'writer B state', HistoryStore::entry( 5, 1 )['content'] );
    }

    public function testRacingWriterWithTheSameContentIsHarmless(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'same' ) ] ] );
        $this->assertTrue( HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'same' ) ] ] ) );
        $this->assertCount( 1, HistoryStore::summaries( 5 ) );
    }

    public function testSummariesHaveNoContentOrShaAndEntryHasEverything(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'secret page body', '2026-10-01T00:00:00Z', 'lbl' ) ] ] );
        $GLOBALS['wpdb']->queries = [];
        $s = HistoryStore::summaries( 5 );
        $this->assertSame( [ [ 'id' => 1, 'saved_at' => '2026-10-01T00:00:00Z', 'tool' => 'update_page_layout', 'actor' => 7, 'bytes' => 16, 'label' => 'lbl' ] ], $s );
        foreach ( $GLOBALS['wpdb']->queries as $q ) {
            $this->assertStringNotContainsString( 'content', $q );
        }
        $e = HistoryStore::entry( 5, 1 );
        $this->assertSame( [ 'id', 'saved_at', 'tool', 'actor', 'bytes', 'sha256', 'label', 'content' ], array_keys( $e ) );
    }

    public function testEntryIsScopedToItsPage(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'page five' ) ] ] );
        HistoryStore::save( 6, [ 'next' => 2, 'items' => [ $this->item( 1, 'page six' ) ] ] );
        $this->assertSame( 'page six', HistoryStore::entry( 6, 1 )['content'] );
        $this->assertNull( HistoryStore::entry( 5, 99 ) );
        $this->assertNull( HistoryStore::entry( 7, 1 ) );
    }

    public function testRecentTakesTheNewestPerPageOrdersNewestFirstAndHonoursTheLimit(): void
    {
        HistoryStore::save( 5, [ 'next' => 3, 'items' => [ $this->item( 2, 'a2', '2026-01-02T00:00:00Z' ), $this->item( 1, 'a1', '2026-01-01T00:00:00Z' ) ] ] );
        HistoryStore::save( 6, [ 'next' => 2, 'items' => [ $this->item( 1, 'b1', '2026-03-01T00:00:00Z' ) ] ] );
        HistoryStore::save( 7, [ 'next' => 2, 'items' => [ $this->item( 1, 'c1', '2026-02-01T00:00:00Z' ) ] ] );

        $rows = HistoryStore::recent( 5 );
        $this->assertSame( [ 6, 7, 5 ], array_column( $rows, 'page_id' ) );
        $this->assertSame( 2, $rows[2]['version_id'], 'page 5 contributes only its newest version' );
        $this->assertSame( [ 'page_id', 'version_id', 'saved_at', 'tool' ], array_keys( $rows[0] ) );
        $this->assertSame( [ 6, 7 ], array_column( HistoryStore::recent( 2 ), 'page_id' ) );
        $this->assertSame( [], HistoryStore::recent( 0 ) );
    }

    public function testDeleteForPageRemovesOnlyThatPage(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'a' ) ] ] );
        HistoryStore::save( 6, [ 'next' => 2, 'items' => [ $this->item( 1, 'b' ) ] ] );
        HistoryStore::deleteForPage( 5 );
        $this->assertSame( [], HistoryStore::summaries( 5 ) );
        $this->assertCount( 1, HistoryStore::summaries( 6 ) );
    }

    public function testNothingTouchesTheDatabaseWhenThereIsNoTable(): void
    {
        $GLOBALS['wpdb']         = new FakeWpdb(); // no table
        $GLOBALS['__wp_options'] = [];
        $this->assertSame( [ 'next' => 1, 'items' => [] ], HistoryStore::load( 5 ) );
        $this->assertFalse( HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'a' ) ] ] ) );
        $this->assertSame( [], HistoryStore::summaries( 5 ) );
        $this->assertNull( HistoryStore::entry( 5, 1 ) );
        $this->assertSame( [], HistoryStore::recent( 5 ) );
        HistoryStore::deleteForPage( 5 );
        $this->assertSame( [], $GLOBALS['wpdb']->queries );
    }

    public function testDeleteAllDropsTheTableTheOptionAndAnyLegacyMeta(): void
    {
        $GLOBALS['__wp_postmeta'][5][ HistoryStore::LEGACY_META_KEY ] = '{"items":[]}';
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'a' ) ] ] );
        HistoryStore::deleteAll();
        $this->assertFalse( HistoryTable::exists() );
        $this->assertArrayNotHasKey( HistoryTable::DB_VERSION_OPTION, $GLOBALS['__wp_options'] );
        $this->assertArrayNotHasKey( HistoryStore::LEGACY_META_KEY, $GLOBALS['__wp_postmeta'][5] ?? [] );
    }
}
```

- [ ] **Step 2: Run to see it fail**

Run: `vendor/bin/phpunit tests/HistoryStoreTest.php`
Expected: errors (`HistoryStore::LEGACY_META_KEY` undefined / methods missing).

- [ ] **Step 3: Rewrite `wp-plugin/src/HistoryStore.php`**

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only code that queries the history table (see HistoryTable for the schema).
 * A history is the array PageHistory works on: ['next' => int, 'items' => list<item>], newest first.
 */
final class HistoryStore
{
    /** Where 4.0.0 kept the history; read only to migrate it (HistoryMigration) and removed on uninstall. */
    public const LEGACY_META_KEY = '_aied_history';

    /** @return array{next:int, items:list<array<string,mixed>>} */
    public static function load( int $pageId ): array
    {
        if ( ! HistoryTable::hasTable() ) {
            return PageHistory::empty();
        }
        $items = array_map( [ self::class, 'toItem' ], self::fetch( $pageId, null, true ) );

        // normalize() derives `next` as max(id) + 1: ids only grow and the newest row is never trimmed.
        return PageHistory::normalize( [ 'next' => 1, 'items' => $items ] );
    }

    /**
     * Makes the stored rows for the page match $history: inserts rows that are not stored yet,
     * then deletes rows that PageHistory trimmed. Returns false (deleting nothing) when an insert
     * fails or clashes with a stored row holding different content.
     *
     * @param array{next:int, items:list<array<string,mixed>>} $history
     */
    public static function save( int $pageId, array $history ): bool
    {
        global $wpdb;

        if ( ! HistoryTable::hasTable() ) {
            return false;
        }
        $history = PageHistory::normalize( $history );
        if ( ! self::insertMissing( $pageId, $history['items'] ) ) {
            return false;
        }

        $keep = array_map( static fn ( array $item ): int => (int) $item['id'], $history['items'] );
        foreach ( array_keys( self::storedHashes( $pageId ) ) as $stored ) {
            if ( ! in_array( $stored, $keep, true ) ) {
                // A failed trim leaves an extra old row; the next save recomputes and removes it.
                $wpdb->delete( HistoryTable::name(), [ 'page_id' => $pageId, 'version_id' => $stored ], [ '%d', '%d' ] );
            }
        }

        return true;
    }

    /**
     * Inserts the items whose version id is not stored yet. A stored id with a different sha256
     * means another writer took that id with other content: reported as a failure, never overwritten.
     *
     * @param list<array<string,mixed>> $items
     */
    public static function insertMissing( int $pageId, array $items ): bool
    {
        $stored = self::storedHashes( $pageId );
        foreach ( $items as $item ) {
            $id   = (int) $item['id'];
            $sha  = (string) ( $item['sha256'] ?? hash( 'sha256', (string) $item['content'] ) );
            if ( isset( $stored[ $id ] ) ) {
                if ( $stored[ $id ] !== $sha ) {
                    return false;
                }
                continue;
            }
            if ( ! self::insertRow( $pageId, $item, $sha ) ) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{id:int, saved_at:string, tool:string, actor:int, bytes:int, label:?string}> */
    public static function summaries( int $pageId ): array
    {
        if ( ! HistoryTable::hasTable() ) {
            return [];
        }
        $out = [];
        foreach ( self::fetch( $pageId, null, false ) as $row ) {
            $item  = self::toItem( $row );
            $out[] = [
                'id'       => $item['id'],
                'saved_at' => $item['saved_at'],
                'tool'     => $item['tool'],
                'actor'    => $item['actor'],
                'bytes'    => $item['bytes'],
                'label'    => $item['label'],
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function entry( int $pageId, int $versionId ): ?array
    {
        if ( ! HistoryTable::hasTable() ) {
            return null;
        }
        $rows = self::fetch( $pageId, $versionId, true );

        return [] === $rows ? null : self::toItem( $rows[0] );
    }

    /**
     * The newest snapshot of every page, newest first, without any snapshot content.
     *
     * @return list<array{page_id:int, version_id:int, saved_at:string, tool:string}>
     */
    public static function recent( int $limit ): array
    {
        global $wpdb;

        if ( ! HistoryTable::hasTable() ) {
            return [];
        }
        $table = HistoryTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT h.page_id, h.version_id, h.saved_at, h.tool
                 FROM {$table} h
                 INNER JOIN ( SELECT page_id, MAX(version_id) AS newest FROM {$table} GROUP BY page_id ) n
                         ON n.page_id = h.page_id AND n.newest = h.version_id
                 ORDER BY h.saved_at DESC, h.page_id DESC
                 LIMIT %d",
                max( 0, $limit )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[] = [
                'page_id'    => (int) $row['page_id'],
                'version_id' => (int) $row['version_id'],
                'saved_at'   => (string) $row['saved_at'],
                'tool'       => (string) $row['tool'],
            ];
        }

        return $out;
    }

    /** `delete_post` action: permanently deleting a page removes its history (trashing does not). */
    public static function deleteForPage( int $pageId ): void
    {
        global $wpdb;

        if ( ! HistoryTable::hasTable() ) {
            return;
        }
        $wpdb->delete( HistoryTable::name(), [ 'page_id' => $pageId ], [ '%d' ] );
    }

    /** Uninstall: the table, its option, and any history still sitting in post meta. */
    public static function deleteAll(): void
    {
        HistoryTable::drop();
        delete_post_meta_by_key( self::LEGACY_META_KEY );
    }

    /**
     * @param array<string,mixed> $item
     */
    private static function insertRow( int $pageId, array $item, string $sha ): bool
    {
        global $wpdb;

        $content = (string) $item['content'];
        // No wp_slash here: $wpdb->insert escapes the values itself, slashing would double the backslashes.
        $ok = $wpdb->insert(
            HistoryTable::name(),
            [
                'page_id'    => $pageId,
                'version_id' => (int) $item['id'],
                'saved_at'   => (string) ( $item['saved_at'] ?? '' ),
                'tool'       => (string) ( $item['tool'] ?? '' ),
                'actor'      => (int) ( $item['actor'] ?? 0 ),
                'bytes'      => (int) ( $item['bytes'] ?? strlen( $content ) ),
                'sha256'     => $sha,
                'label'      => isset( $item['label'] ) ? (string) $item['label'] : null,
                'content'    => $content,
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
        );

        return false !== $ok;
    }

    /** @return array<int,string> stored version id => sha256 */
    private static function storedHashes( int $pageId ): array
    {
        global $wpdb;

        $table = HistoryTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT version_id, sha256 FROM {$table} WHERE page_id = %d", $pageId ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) $row['version_id'] ] = (string) $row['sha256'];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> rows newest first; `content` is selected only when asked for */
    private static function fetch( int $pageId, ?int $versionId, bool $withContent ): array
    {
        global $wpdb;

        $table = HistoryTable::name();
        $cols  = 'version_id, saved_at, tool, actor, bytes, sha256, label' . ( $withContent ? ', content' : '' );
        $sql   = "SELECT {$cols} FROM {$table} WHERE page_id = %d";
        $args  = [ $pageId ];
        if ( null !== $versionId ) {
            $sql   .= ' AND version_id = %d';
            $args[] = $versionId;
        }
        $sql .= ' ORDER BY version_id DESC';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function toItem( array $row ): array
    {
        $item = [
            'id'       => (int) $row['version_id'],
            'saved_at' => (string) $row['saved_at'],
            'tool'     => (string) $row['tool'],
            'actor'    => (int) $row['actor'],
            'bytes'    => (int) $row['bytes'],
            'sha256'   => (string) $row['sha256'],
            'label'    => null === $row['label'] ? null : (string) $row['label'],
        ];
        if ( array_key_exists( 'content', $row ) ) {
            $item['content'] = (string) $row['content'];
        }

        return $item;
    }
}
```

- [ ] **Step 4: Run the store tests**

Run: `vendor/bin/phpunit tests/HistoryStoreTest.php`
Expected: PASS (13 tests). If `testSummariesHaveNoContent…` reports the `entry` key order differs, the failure is in `toItem` (key order must be `id, saved_at, tool, actor, bytes, sha256, label, content`).

- [ ] **Step 5: Replace `tests/HistoryServiceTest.php` entirely**

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\PageHistory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';

class HistoryServiceTest extends TestCase
{
    private const VALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']     = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_posts']       = [ 5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_content' => 'ORIGINAL' ] ];
        $GLOBALS['__wp_postmeta']    = [];
        $GLOBALS['__wp_update_fail'] = false;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']          = null;
        $GLOBALS['__wp_options']  = [];
        $GLOBALS['__wp_postmeta'] = [];
    }

    public function testWriteSnapshotsPreviousContentThenSavesNew(): void
    {
        $r = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertTrue( $r['snapshot']['stored'] );
        $this->assertSame( 'NEW', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 'ORIGINAL', HistoryService::entry( 5, $r['snapshot']['version_id'] )['content'] );
    }

    public function testBackslashesAndEscapedHtmlRoundTripByteForByte(): void
    {
        $tricky = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Hi \"there\" \\\\ back</p>"}}}} /-->';
        $GLOBALS['__wp_posts'][5]->post_content = $tricky;
        $w = HistoryService::write( 5, 'SOMETHING ELSE', 'edit_page_content' );
        $this->assertSame( $tricky, HistoryService::entry( 5, $w['snapshot']['version_id'] )['content'] );

        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( $tricky, $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testOversizedPageIsStillWrittenAndResultSaysNoSnapshot(): void
    {
        $GLOBALS['__wp_posts'][5]->post_content = str_repeat( 'x', PageHistory::MAX_BYTES + 1 );
        $r = HistoryService::write( 5, 'SMALL', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertFalse( $r['snapshot']['stored'] );
        $this->assertSame( 'too_large', $r['snapshot']['reason'] );
        $this->assertNull( $r['snapshot']['version_id'] );
        $this->assertSame( 'SMALL', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testFailedSaveReportsErrorAndKeepsContent(): void
    {
        $GLOBALS['__wp_update_fail'] = true;
        $r = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'update_failed', $r['error'] );
        $this->assertSame( 'simulated failure', $r['message'] );
        $this->assertSame( 'ORIGINAL', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testRestoreSnapshotsCurrentContentFirstSoRestoreIsUndoable(): void
    {
        $w = HistoryService::write( 5, 'AI VERSION', 'update_page_layout' );          // snapshot #1 = ORIGINAL
        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );              // back to ORIGINAL
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'ORIGINAL', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 1, $r['restored_version'] );
        $this->assertTrue( $r['snapshot']['stored'] );
        $this->assertSame( 'AI VERSION', HistoryService::entry( 5, $r['snapshot']['version_id'] )['content'] );
    }

    public function testRestoreOfContentTheValidatorRejectsStillSucceedsAndReportsVerdict(): void
    {
        $GLOBALS['__wp_posts'][5]->post_content = '<!-- wp:divi/not-a-module /-->';
        $w = HistoryService::write( 5, self::VALID, 'update_page_layout' );
        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( '<!-- wp:divi/not-a-module /-->', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertFalse( $r['validator']['valid'] );
        $this->assertNotEmpty( $r['validator']['violations'] );
    }

    public function testRestoreOfValidContentReportsValid(): void
    {
        $GLOBALS['__wp_posts'][5]->post_content = self::VALID;
        $w = HistoryService::write( 5, 'junk', 'update_page_layout' );
        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );
        $this->assertTrue( $r['validator']['valid'] );
        $this->assertSame( [], $r['validator']['violations'] );
    }

    public function testUnknownVersionIsACleanErrorAndChangesNothing(): void
    {
        $r = HistoryService::restore( 5, 99 );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'version_not_found', $r['error'] );
        $this->assertSame( 'ORIGINAL', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( [], HistoryService::listFor( 5 ) );
    }

    public function testDuplicateWriteDoesNotAddASnapshot(): void
    {
        HistoryService::write( 5, 'A', 'update_page_layout' );              // snapshot ORIGINAL
        $r = HistoryService::write( 5, 'A', 'update_page_layout' );          // pre-write content is now 'A'
        $this->assertTrue( $r['ok'] );
        $this->assertTrue( $r['snapshot']['stored'] );                        // 'A' is new vs ORIGINAL
        $r2 = HistoryService::write( 5, 'A', 'update_page_layout' );          // pre-write 'A' == newest snapshot 'A'
        $this->assertFalse( $r2['snapshot']['stored'] );
        $this->assertSame( 'duplicate', $r2['snapshot']['reason'] );
        $this->assertNotNull( $r2['snapshot']['version_id'] );
        $this->assertSame( 2, $r2['snapshot']['version_id'] );
        $this->assertCount( 2, HistoryService::listFor( 5 ) );
    }

    public function testListForReturnsSummariesWithoutContent(): void
    {
        HistoryService::write( 5, 'A', 'update_page_layout' );
        $list = HistoryService::listFor( 5 );
        $this->assertCount( 1, $list );
        $this->assertArrayNotHasKey( 'content', $list[0] );
        $this->assertSame( 'update_page_layout', $list[0]['tool'] );
    }

    public function testInsertFailureIsReportedAsStoreFailedAndWriteStillSucceeds(): void
    {
        HistoryService::write( 5, 'B', 'update_page_layout' );               // snapshot #1 = ORIGINAL
        $GLOBALS['wpdb']->failInserts = true;
        $r = HistoryService::write( 5, 'C', 'update_page_layout' );
        $GLOBALS['wpdb']->failInserts = false;
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'C', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertFalse( $r['snapshot']['stored'] );
        $this->assertNull( $r['snapshot']['version_id'] );
        $this->assertSame( 'store_failed', $r['snapshot']['reason'] );
        $this->assertCount( 1, HistoryService::listFor( 5 ) );
    }

    public function testInvalidUtf8PreWriteContentKeepsExistingHistoryAndIds(): void
    {
        HistoryService::write( 5, 'B', 'update_page_layout' );               // #1 = ORIGINAL
        HistoryService::write( 5, 'C', 'update_page_layout' );               // #2 = B
        $GLOBALS['__wp_posts'][5]->post_content = "bad \xC3\x28 bytes";
        $r = HistoryService::write( 5, 'D', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'D', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertFalse( $r['snapshot']['stored'] );
        $this->assertSame( 'invalid_encoding', $r['snapshot']['reason'] );
        $this->assertSame( [ 2, 1 ], array_column( HistoryService::listFor( 5 ), 'id' ) );
        $this->assertSame( 'B', HistoryService::entry( 5, 2 )['content'] );

        $next = HistoryService::write( 5, 'E', 'update_page_layout' );       // pre-write 'D' -> id 3, not reused
        $this->assertSame( 3, $next['snapshot']['version_id'] );
    }

    public function testWriteToMissingPageFailsWithoutStoringAnything(): void
    {
        $r = HistoryService::write( 999, 'X', 'update_page_layout' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'update_failed', $r['error'] );
        $this->assertSame( 'Page 999 not found.', $r['message'] );
        $this->assertSame( [ 'stored' => false, 'version_id' => null, 'reason' => null ], $r['snapshot'] );
        $this->assertSame( [], HistoryService::listFor( 999 ) );
    }

    public function testRestoreFailureShapesCarryASnapshotKey(): void
    {
        $nf = HistoryService::restore( 5, 99 );
        $this->assertSame( [ 'stored' => false, 'version_id' => null, 'reason' => null ], $nf['snapshot'] );

        $w = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $GLOBALS['__wp_update_fail'] = true;
        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'update_failed', $r['error'] );
        $this->assertArrayHasKey( 'snapshot', $r );
        $this->assertSame( 'NEW', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testThirteenWritesKeepNewestTenWithMonotonicIds(): void
    {
        for ( $i = 1; $i <= 13; $i++ ) {
            $r = HistoryService::write( 5, "V{$i}", 'update_page_layout' );
            $this->assertSame( $i, $r['snapshot']['version_id'] );
        }
        $this->assertSame( [ 13, 12, 11, 10, 9, 8, 7, 6, 5, 4 ], array_column( HistoryService::listFor( 5 ), 'id' ) );
        $this->assertSame( 14, HistoryService::write( 5, 'V14', 'update_page_layout' )['snapshot']['version_id'] );
    }

    public function testRestoringTheOldestVersionWhenHistoryIsFullSucceeds(): void
    {
        for ( $i = 1; $i <= 10; $i++ ) {
            HistoryService::write( 5, "V{$i}", 'update_page_layout' );       // ids 1..10, #1 = ORIGINAL
        }
        $this->assertCount( PageHistory::RETENTION, HistoryService::listFor( 5 ) );
        $oldest = HistoryService::entry( 5, 1 );
        $this->assertSame( 'ORIGINAL', $oldest['content'] );

        $r = HistoryService::restore( 5, 1 );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'ORIGINAL', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertTrue( $r['snapshot']['stored'] );
    }

    public function testRecentListsNewestSnapshotPerPageWithTitles(): void
    {
        $GLOBALS['__wp_posts'][6] = (object) [ 'ID' => 6, 'post_type' => 'page', 'post_title' => 'About', 'post_content' => 'B0' ];
        $GLOBALS['__wp_posts'][5]->post_title = 'Home';
        HistoryService::write( 5, 'A1', 'update_page_layout' );
        HistoryService::write( 6, 'B1', 'edit_page_content' );
        $rows = HistoryService::recent( 5 );
        $this->assertCount( 2, $rows );
        $titles = array_column( $rows, 'title' );
        sort( $titles );
        $this->assertSame( [ 'About', 'Home' ], $titles );
        $this->assertSame( [ 1 ], array_unique( array_column( $rows, 'version_id' ) ) );
    }

    private function seedHistory( int $pageId, string $savedAt, string $content = 'C', int $id = 1 ): void
    {
        HistoryStore::save( $pageId, [
            'next'  => $id + 1,
            'items' => [ [ 'id' => $id, 'saved_at' => $savedAt, 'tool' => 'update_page', 'actor' => 1, 'bytes' => strlen( $content ), 'sha256' => hash( 'sha256', $content ), 'label' => null, 'content' => $content ] ],
        ] );
    }

    public function testRecentHonoursLimitAndOrdersNewestFirst(): void
    {
        foreach ( [ 6, 7 ] as $id ) {
            $GLOBALS['__wp_posts'][ $id ] = (object) [ 'ID' => $id, 'post_type' => 'page', 'post_title' => "P{$id}", 'post_content' => 'x' ];
        }
        $this->seedHistory( 5, '2026-01-01T00:00:00Z' );
        $this->seedHistory( 6, '2026-03-01T00:00:00Z' );
        $this->seedHistory( 7, '2026-02-01T00:00:00Z' );
        $rows = HistoryService::recent( 2 );
        $this->assertCount( 2, $rows );
        $this->assertSame( [ 6, 7 ], array_column( $rows, 'page_id' ) );
    }

    public function testRecentMissingPostYieldsEmptyTitleWithoutWarning(): void
    {
        $this->seedHistory( 99, '2026-01-01T00:00:00Z' );
        $rows = HistoryService::recent( 5 );
        $this->assertCount( 1, $rows );
        $this->assertSame( '', $rows[0]['title'] );
        $this->assertSame( 99, $rows[0]['page_id'] );
    }

    public function testRecentOnAnEmptyTableIsEmpty(): void
    {
        $this->assertSame( [], HistoryService::recent( 5 ) );
    }

    public function testRecentRowsNeverCarryContentEvenWhenLarge(): void
    {
        $big = str_repeat( 'A', 400 * 1024 );
        $GLOBALS['__wp_posts'][5]->post_title = 'Big';
        $this->seedHistory( 5, '2026-01-01T00:00:00Z', $big );
        $GLOBALS['wpdb']->queries = [];
        $rows = HistoryService::recent( 5 );
        $this->assertCount( 1, $rows );
        $this->assertArrayNotHasKey( 'content', $rows[0] );
        $this->assertLessThan( 1000, strlen( serialize( $rows ) ) );
        foreach ( $GLOBALS['wpdb']->queries as $q ) {
            $this->assertStringNotContainsString( 'content', $q, 'the dashboard query must not read snapshot content' );
        }
        // The stored history itself is untouched.
        $this->assertSame( $big, HistoryService::entry( 5, 1 )['content'] );
    }
}
```

- [ ] **Step 6: Run it to see the failures**

Run: `vendor/bin/phpunit tests/HistoryServiceTest.php`
Expected: failures in `listFor`/`entry`/`recent` tests (the service still reads through `PageHistory` and the old `recent()` signature).

- [ ] **Step 7: Edit `wp-plugin/src/HistoryService.php`**

(a) Delete the `RECENT_SCAN` constant and its docblock (lines 18-19).

(b) Replace the bodies of `listFor` and `entry`:

```php
    /** @return list<array<string,mixed>> */
    public static function listFor( int $pageId ): array
    {
        return HistoryStore::summaries( $pageId );
    }

    /** @return array<string,mixed>|null */
    public static function entry( int $pageId, int $versionId ): ?array
    {
        return HistoryStore::entry( $pageId, $versionId );
    }
```

(c) Replace the whole `recent()` method (docblock included) with:

```php
    /**
     * @return list<array{page_id:int, title:string, version_id:int, saved_at:mixed, tool:mixed}>
     */
    public static function recent( int $limit = 5 ): array
    {
        $rows = [];
        foreach ( HistoryStore::recent( $limit ) as $row ) {
            $post   = get_post( $row['page_id'] );
            $rows[] = $row + [ 'title' => $post ? (string) ( $post->post_title ?? '' ) : '' ];
        }

        return $rows;
    }
```

Leave `write`, `restore`, `noSnapshot`, `snapshot` untouched.

- [ ] **Step 8: Edit `wp-plugin/src/PageHistory.php`**

(a) Replace the two-line comment above `MAX_TOTAL_BYTES` (currently "Per-page budget for all stored snapshot content. WP_Query primes ALL post meta ...") with:

```php
    // Per-page budget for all stored snapshot content: bounds how much one page can add to the
    // database and how large any single history query result can get.
```

(b) Delete the whole `recent()` method (the `@param array<int, array> $byPage` docblock and the function, the last method in the class) — nothing calls it any more.

- [ ] **Step 9: Edit `tests/PageHistoryTest.php`**

Delete the `testRecentTakesNewestPerPageAcrossPages` method (the last test in the file, `PageHistory::recent` no longer exists). Do not touch the other tests (they pin the constants and the pure rules).

- [ ] **Step 10: Run everything**

Run: `vendor/bin/phpunit`
Expected: all green. If `WpOrgComplianceTest` fails on `src/HistoryStore.php` or `src/PageHistory.php` for a scanned word, rephrase the comment (see Global Constraints) — do not edit the test.

- [ ] **Step 11: Commit**

```bash
git add wp-plugin/src tests
git commit -m "feat: history rows in a custom table (HistoryStore rewrite, set-based recent/list)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Migration, lifecycle hooks and wiring

**Files:**
- Create: `wp-plugin/src/HistoryMigration.php`, `tests/HistoryMigrationTest.php`
- Modify: `wp-plugin/src/HistoryTable.php` (add `ensureReady`, `register`), `wp-plugin/src/HistoryStore.php` (lazy import), `wp-plugin/src/autoload.php`, `wp-plugin/jhmg-ai-editor-for-divi-5.php`, `tests/WpOrgComplianceTest.php:88-91`

**Interfaces:**
- Consumes: Task 1/2 interfaces.
- Produces: `HistoryMigration::importPage(int $pageId): bool` (true = nothing left to import for that page), `HistoryMigration::run(int $limit): bool` (true = no legacy meta left); `HistoryTable::ensureReady(bool $force = false): void`; `HistoryTable::register(): void`.

- [ ] **Step 1: Write `tests/HistoryMigrationTest.php` (failing)**

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryMigration;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryMigration.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';

class HistoryMigrationTest extends TestCase
{
    private const TRICKY = "<p class=\"a\">C:\\path \\\\ \\u003Cp\\u003E \n\u{1F600}</p>";

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']     = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_TABLE ];
        $GLOBALS['__wp_posts']       = [ 5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_content' => 'ORIGINAL' ] ];
        $GLOBALS['__wp_postmeta']    = [];
        $GLOBALS['__wp_update_fail'] = false;
        $GLOBALS['__wp_transients']  = [];
        $GLOBALS['__wp_dbdelta_sql'] = [];
        $GLOBALS['__wp_filters']     = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']          = null;
        $GLOBALS['__wp_options']  = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__wp_filters']  = [];
    }

    /** The 4.0.0 on-disk format: a JSON blob, newest item first. */
    private function legacyJson( int ...$ids ): string
    {
        $items = [];
        foreach ( $ids as $id ) {
            $content = 2 === $id ? self::TRICKY : "content {$id}";
            $items[] = [ 'id' => $id, 'saved_at' => "2026-10-01T0{$id}:00:00Z", 'tool' => 'update_page_layout', 'actor' => 7, 'bytes' => strlen( $content ), 'sha256' => hash( 'sha256', $content ), 'label' => null, 'content' => $content ];
        }

        return (string) json_encode( [ 'next' => max( $ids ) + 1, 'items' => array_reverse( $items ) ] );
    }

    private function seedLegacy( int $pageId, string $json ): void
    {
        $GLOBALS['__wp_postmeta'][ $pageId ][ HistoryStore::LEGACY_META_KEY ] = $json;
    }

    private function hasLegacy( int $pageId ): bool
    {
        return isset( $GLOBALS['__wp_postmeta'][ $pageId ][ HistoryStore::LEGACY_META_KEY ] );
    }

    public function testImportPageCopiesRowsKeepsIdsAndBytesAndRemovesTheMeta(): void
    {
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        $this->assertTrue( HistoryMigration::importPage( 5 ) );
        $this->assertSame( [ 2, 1 ], array_column( HistoryStore::summaries( 5 ), 'id' ) );
        $this->assertSame( self::TRICKY, HistoryStore::entry( 5, 2 )['content'] );
        $this->assertSame( 'content 1', HistoryStore::entry( 5, 1 )['content'] );
        $this->assertFalse( $this->hasLegacy( 5 ) );
    }

    public function testImportIsIdempotentAndNothingToImportIsFine(): void
    {
        $this->assertTrue( HistoryMigration::importPage( 5 ), 'no meta at all' );
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        HistoryMigration::importPage( 5 );
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) ); // same blob appears again
        $this->assertTrue( HistoryMigration::importPage( 5 ) );
        $this->assertCount( 2, HistoryStore::summaries( 5 ) );
        $this->assertFalse( $this->hasLegacy( 5 ) );
    }

    public function testPartiallyImportedPageOnlyAddsTheMissingRows(): void
    {
        HistoryStore::save( 5, [ 'next' => 3, 'items' => [ [ 'id' => 2, 'saved_at' => '2026-10-02T00:00:00Z', 'tool' => 'update_page_layout', 'actor' => 7, 'bytes' => strlen( self::TRICKY ), 'sha256' => hash( 'sha256', self::TRICKY ), 'label' => null, 'content' => self::TRICKY ] ] ] );
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        $this->assertTrue( HistoryMigration::importPage( 5 ) );
        $this->assertSame( [ 2, 1 ], array_column( HistoryStore::summaries( 5 ), 'id' ) );
    }

    public function testRowThatClashesWithDifferentContentKeepsTheMetaForARetry(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ [ 'id' => 1, 'saved_at' => 't', 'tool' => 't', 'actor' => 1, 'bytes' => 5, 'sha256' => hash( 'sha256', 'other' ), 'label' => null, 'content' => 'other' ] ] ] );
        $this->seedLegacy( 5, $this->legacyJson( 1 ) );
        $this->assertFalse( HistoryMigration::importPage( 5 ) );
        $this->assertTrue( $this->hasLegacy( 5 ) );
        $this->assertSame( 'other', HistoryStore::entry( 5, 1 )['content'] );
    }

    public function testInsertFailureKeepsTheMeta(): void
    {
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        $GLOBALS['wpdb']->failInserts = true;
        $this->assertFalse( HistoryMigration::importPage( 5 ) );
        $this->assertTrue( $this->hasLegacy( 5 ) );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'garbage' )]
    public function testGarbageLegacyMetaIsDroppedNotKeptAndNeverBlocks( string $junk ): void
    {
        $this->seedLegacy( 5, $junk );
        $this->assertTrue( HistoryMigration::importPage( 5 ) );
        $this->assertFalse( $this->hasLegacy( 5 ), 'a value with no valid snapshot would block the migration from ever finishing' );
        $this->assertSame( [], HistoryStore::summaries( 5 ) );
        $r = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertTrue( $r['snapshot']['stored'] );
    }

    /** @return list<array{string}> */
    public static function garbage(): array
    {
        return [ [ 'not json at all' ], [ '{"items":"nope"}' ], [ '[1,2,3]' ], [ '{"items":[]}' ], [ '12345' ] ];
    }

    public function testRunRespectsTheBatchLimitAndReportsWhenDone(): void
    {
        foreach ( [ 11, 12, 13, 14, 15 ] as $id ) {
            $this->seedLegacy( $id, $this->legacyJson( 1 ) );
        }
        $this->assertFalse( HistoryMigration::run( 2 ) );  // 2 imported, a full batch: maybe more
        $this->assertFalse( HistoryMigration::run( 2 ) );  // 2 more
        $this->assertTrue( HistoryMigration::run( 2 ) );   // the last one, short batch: done
        foreach ( [ 11, 12, 13, 14, 15 ] as $id ) {
            $this->assertCount( 1, HistoryStore::summaries( $id ) );
            $this->assertFalse( $this->hasLegacy( $id ) );
        }
        $this->assertTrue( HistoryMigration::run( 2 ), 'a finished migration stays finished' );
    }

    public function testLazyImportShowsAndRestoresOldHistoryBeforeTheBatchRuns(): void
    {
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        $this->assertSame( [ 2, 1 ], array_column( HistoryService::listFor( 5 ), 'id' ) );
        $r = HistoryService::restore( 5, 2 );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( self::TRICKY, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 3, $r['snapshot']['version_id'], 'the restore snapshots the current content: its id continues after the imported 1 and 2' );
    }

    public function testNextSnapshotIdContinuesAfterTheImportedIds(): void
    {
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        $w = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertSame( 3, $w['snapshot']['version_id'] );
        $this->assertCount( 3, HistoryService::listFor( 5 ) );
    }

    public function testNoLazyImportOnceTheMigrationIsDone(): void
    {
        $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ] = HistoryTable::STAGE_MIGRATED;
        $this->seedLegacy( 5, $this->legacyJson( 1 ) );
        $this->assertSame( [], HistoryService::listFor( 5 ) );
        $this->assertTrue( $this->hasLegacy( 5 ), 'after the migration stage the legacy meta is no longer consulted' );
    }

    public function testEnsureReadyCreatesTheTableThenMigratesInBatches(): void
    {
        $GLOBALS['wpdb']         = new FakeWpdb(); // no table yet
        $GLOBALS['__wp_options'] = [];
        for ( $id = 100; $id < 125; $id++ ) {      // 25 pages > one batch of 20
            $this->seedLegacy( $id, $this->legacyJson( 1 ) );
        }

        HistoryTable::ensureReady();
        $this->assertTrue( HistoryTable::exists() );
        $this->assertSame( HistoryTable::STAGE_TABLE, (int) $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ], '25 pages need a second batch' );

        HistoryTable::ensureReady();
        $this->assertSame( HistoryTable::STAGE_MIGRATED, (int) $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ] );
        $this->assertCount( 1, HistoryStore::summaries( 124 ) );
        $this->assertCount( 1, $GLOBALS['__wp_dbdelta_sql'], 'the table is created once, not per request' );
    }

    public function testEnsureReadyDoesNothingOnceMigrated(): void
    {
        $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ] = HistoryTable::STAGE_MIGRATED;
        $GLOBALS['wpdb']->queries = [];
        HistoryTable::ensureReady();
        $this->assertSame( [], $GLOBALS['wpdb']->queries );
        $this->assertSame( [], $GLOBALS['__wp_dbdelta_sql'] );
    }

    public function testHostThatForbidsCreateTableBacksOffAndNeverFatals(): void
    {
        $GLOBALS['wpdb']              = new FakeWpdb();
        $GLOBALS['wpdb']->denyCreate  = true;
        $GLOBALS['__wp_options']      = [];

        HistoryTable::ensureReady();
        $this->assertCount( 1, $GLOBALS['__wp_dbdelta_sql'] );
        $this->assertArrayNotHasKey( HistoryTable::DB_VERSION_OPTION, $GLOBALS['__wp_options'] );

        HistoryTable::ensureReady();
        HistoryTable::ensureReady();
        $this->assertCount( 1, $GLOBALS['__wp_dbdelta_sql'], 'backed off: no dbDelta on every request' );

        HistoryTable::ensureReady( true );
        $this->assertCount( 2, $GLOBALS['__wp_dbdelta_sql'], 'activation retries immediately' );

        // A page write still succeeds and honestly says undo is unavailable.
        $r = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'store_failed', $r['snapshot']['reason'] );
        $this->assertSame( 'NEW', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testRegisterWiresUpgradeCleanupAndMultisiteDropHooks(): void
    {
        HistoryTable::register();

        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ [ 'id' => 1, 'saved_at' => 't', 'tool' => 't', 'actor' => 1, 'bytes' => 1, 'sha256' => hash( 'sha256', 'a' ), 'label' => null, 'content' => 'a' ] ] ] );
        do_action( 'delete_post', 5 );
        $this->assertSame( [], HistoryStore::summaries( 5 ), 'permanently deleting a page removes its history' );

        $this->assertContains( 'wp_ai_editor_divi5_history', apply_filters( 'wpmu_drop_tables', [] ) );

        $this->seedLegacy( 6, $this->legacyJson( 1 ) );
        do_action( 'plugins_loaded' );
        $this->assertCount( 1, HistoryStore::summaries( 6 ) );
        $this->assertSame( HistoryTable::STAGE_MIGRATED, (int) $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ] );
    }
}
```

- [ ] **Step 2: Run to see it fail**

Run: `vendor/bin/phpunit tests/HistoryMigrationTest.php`
Expected: errors — `HistoryMigration` not found / `ensureReady` undefined.

- [ ] **Step 3: Create `wp-plugin/src/HistoryMigration.php`**

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moves 4.0.0's post-meta history (`_aied_history`, one JSON blob per page) into the history table.
 * Safe to run any number of times: rows that are already stored are skipped, and a page's meta is
 * deleted only after every one of its snapshots is stored.
 */
final class HistoryMigration
{
    /**
     * Imports one page. True means nothing is left to import for it (it was imported, held no
     * valid snapshot, or had no legacy meta); false means rows could not be stored and the meta
     * stays for a retry.
     */
    public static function importPage( int $pageId ): bool
    {
        $raw = get_post_meta( $pageId, HistoryStore::LEGACY_META_KEY, true );
        if ( ! is_string( $raw ) || '' === $raw ) {
            if ( false !== $raw && '' !== $raw ) {
                delete_post_meta( $pageId, HistoryStore::LEGACY_META_KEY ); // not a string: unusable
            }

            return true;
        }

        $decoded = json_decode( $raw, true );
        $items   = PageHistory::normalize( $decoded )['items'];
        // A value with no valid snapshot is junk (4.0.0 treated it as an empty history and the next
        // save overwrote it). It must go, or the migration could never be declared finished.
        if ( [] !== $items && ! HistoryStore::insertMissing( $pageId, $items ) ) {
            return false;
        }
        delete_post_meta( $pageId, HistoryStore::LEGACY_META_KEY );

        return true;
    }

    /**
     * Imports up to $limit pages that still hold legacy meta.
     *
     * @return bool true when no legacy meta is left (a short batch finishes the migration)
     */
    public static function run( int $limit ): bool
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id LIMIT %d",
                HistoryStore::LEGACY_META_KEY,
                $limit
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $ids     = is_array( $ids ) ? $ids : [];
        $allDone = true;
        foreach ( $ids as $id ) {
            if ( ! self::importPage( (int) $id ) ) {
                $allDone = false;
            }
        }

        return $allDone && count( $ids ) < $limit;
    }
}
```

- [ ] **Step 4: Add `ensureReady()` and `register()` to `HistoryTable`**

In `wp-plugin/src/HistoryTable.php`, add these two constants right after `STAGE_MIGRATED` (inside the class):

```php
    private const BACKOFF_TRANSIENT = 'ai_editor_divi5_history_backoff';
    /** Pages whose legacy meta is imported per request. */
    private const MIGRATION_BATCH = 20;
```

and add these two methods directly after `migrationDone()`:

```php
    /**
     * Creates the table and migrates old post-meta history, a little per request until done.
     * Runs on `plugins_loaded` (an auto-update does not re-run the activation hook) and on
     * activation (with $force, which ignores the backoff).
     */
    public static function ensureReady( bool $force = false ): void
    {
        $stage = (int) get_option( self::DB_VERSION_OPTION, 0 );
        if ( $stage >= self::STAGE_MIGRATED ) {
            return;
        }
        if ( ! $force && false !== get_transient( self::BACKOFF_TRANSIENT ) ) {
            return;
        }

        if ( $stage < self::STAGE_TABLE ) {
            self::createTable();
            if ( ! self::exists() ) {
                // The host refused CREATE TABLE (or it failed): wait instead of retrying every request.
                set_transient( self::BACKOFF_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

                return;
            }
            update_option( self::DB_VERSION_OPTION, self::STAGE_TABLE, true );
        }

        if ( HistoryMigration::run( self::MIGRATION_BATCH ) ) {
            update_option( self::DB_VERSION_OPTION, self::STAGE_MIGRATED, true );
        }
    }

    /** Hooks: schema/migration on load, history cleanup on page delete, drop on multisite site delete. */
    public static function register(): void
    {
        add_action( 'plugins_loaded', static function (): void {
            self::ensureReady();
        } );
        add_action( 'delete_post', [ HistoryStore::class, 'deleteForPage' ] );
        add_filter( 'wpmu_drop_tables', [ self::class, 'dropTablesFilter' ] );
    }
```

Also make `drop()` clear the backoff: add `delete_transient( self::BACKOFF_TRANSIENT );` as the last line of `drop()`.

- [ ] **Step 5: Add the lazy import to `HistoryStore`**

In `wp-plugin/src/HistoryStore.php`:

(a) Add this private method (after `deleteAll()`):

```php
    /** Until the batch migration has finished, a page's old post-meta history is imported when first read. */
    private static function importLegacy( int $pageId ): void
    {
        if ( ! HistoryTable::migrationDone() ) {
            HistoryMigration::importPage( $pageId );
        }
    }
```

(b) Call `self::importLegacy( $pageId );` as the first statement after the `hasTable()` guard in `load()`, `summaries()` and `entry()` (three places; not in `save()`, `recent()` or `deleteForPage()`). For example `load()` becomes:

```php
        if ( ! HistoryTable::hasTable() ) {
            return PageHistory::empty();
        }
        self::importLegacy( $pageId );
        $items = array_map( [ self::class, 'toItem' ], self::fetch( $pageId, null, true ) );
```

- [ ] **Step 6: Require the class and wire the plugin**

In `wp-plugin/src/autoload.php`, add directly after the `HistoryTable.php` line:

```php
require_once __DIR__ . '/HistoryMigration.php';
```

In `wp-plugin/jhmg-ai-editor-for-divi-5.php`:

(a) In the activation hook, directly after `AiEditorDivi5\WP\UsageTracker::createTable();` add:

```php
    AiEditorDivi5\WP\HistoryTable::ensureReady(true);
```

(b) Directly after the runtime `require_once __DIR__ . '/src/autoload.php';` (the one under "Load all plugin classes", not the one inside the activation hook) add:

```php

AiEditorDivi5\WP\HistoryTable::register();
```

- [ ] **Step 7: Add the one justified option-writer exception**

In `tests/WpOrgComplianceTest.php`, extend `OPTION_WRITERS` to:

```php
    private const OPTION_WRITERS = [
        'src/ApiKey.php'       => 'stores the plugin API key and its user (ai_editor_divi5_api_key / _api_user_id)',
        'src/UsageTracker.php' => 'stores the usage-log table schema version (ai_editor_divi5_db_version)',
        'src/HistoryTable.php' => 'stores the history table stage: created / old history migrated (ai_editor_divi5_history_db_version)',
    ];
```

- [ ] **Step 8: Run everything**

Run: `vendor/bin/phpunit tests/HistoryMigrationTest.php` → Expected: PASS.
Run: `vendor/bin/phpunit` → Expected: all green. (A compliance failure naming `HistoryMigration.php`/`HistoryTable.php` means a forbidden word slipped into a comment or string: reword it.)

- [ ] **Step 9: Commit**

```bash
git add wp-plugin tests
git commit -m "feat: migrate post-meta history into the table (batched + lazy), lifecycle hooks, activation wiring

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Real-database check on the Docker site

**Files:**
- Create: `scripts/verify-history-table.php`
- Modify: `Makefile`

**Interfaces:**
- Consumes: the finished plugin code (mounted into the Docker site from this checkout).
- Produces: `make verify-history` (exit 0 = every check passed).

- [ ] **Step 1: Create `scripts/verify-history-table.php`**

```php
<?php
/**
 * Real-database check for the undo-history table. The unit tests use a SQLite fake; this proves
 * the MySQL/MariaDB side: dbDelta schema, utf8mb4 content, unique key, migration, delete cleanup.
 *
 * Run: make verify-history   (needs `make up` and the plugin active on the dev site)
 * It creates ONE throwaway draft page, exercises everything on it, and deletes it again.
 */

use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\PageHistory;

if ( ! class_exists( HistoryStore::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 on this site first.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$rowCount = static function ( int $pageId ): int {
    global $wpdb;
    $table = HistoryTable::name();
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE page_id = %d", $pageId ) ); // phpcs:ignore
};

// Make sure the table exists (this is the real dbDelta path on a fresh site).
HistoryTable::ensureReady( true );
$check( HistoryTable::exists(), 'table exists after ensureReady' );

$pageId = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied history check', 'post_content' => 'ORIGINAL' ], true );
if ( is_wp_error( $pageId ) ) {
    WP_CLI::error( $pageId->get_error_message() );
}

// ---- 1. Migration from the 4.0.0 post-meta format -----------------------------------------
$tricky = "<p class=\"a\">C:\\path \\\\ \\u003Cp\\u003E \n\t \u{1F600} \u{e9}</p>";
$legacy = [ 'next' => 3, 'items' => [
    [ 'id' => 2, 'saved_at' => '2026-10-01T02:00:00Z', 'tool' => 'update_page_layout', 'actor' => 1, 'bytes' => strlen( $tricky ), 'sha256' => hash( 'sha256', $tricky ), 'label' => null, 'content' => $tricky ],
    [ 'id' => 1, 'saved_at' => '2026-10-01T01:00:00Z', 'tool' => 'update_page_layout', 'actor' => 1, 'bytes' => 9, 'sha256' => hash( 'sha256', 'legacy one' ), 'label' => null, 'content' => 'legacy one' ],
] ];
update_post_meta( $pageId, HistoryStore::LEGACY_META_KEY, wp_slash( wp_json_encode( $legacy ) ) );
update_option( HistoryTable::DB_VERSION_OPTION, HistoryTable::STAGE_TABLE, true );
for ( $i = 0; $i < 50 && ! HistoryTable::migrationDone(); $i++ ) {
    HistoryTable::ensureReady( true );
}
$check( HistoryTable::migrationDone(), 'migration reaches the done stage' );
$check( '' === get_post_meta( $pageId, HistoryStore::LEGACY_META_KEY, true ), 'legacy meta removed after import' );
$check( [ 2, 1 ] === array_column( HistoryService::listFor( $pageId ), 'id' ), 'imported ids kept (2, 1)' );
$check( ( HistoryService::entry( $pageId, 2 )['content'] ?? null ) === $tricky, 'migrated content identical byte-for-byte (backslashes, newline, emoji)' );

// ---- 2. Writes: retention, ids, content fidelity ------------------------------------------
$ok = true;
for ( $i = 1; $i <= 13; $i++ ) {
    $new = "V{$i} \\ \" \u{1F600}";
    $w   = HistoryService::write( $pageId, $new, 'update_page_layout' );
    $ok  = $ok && $w['ok'] && get_post( $pageId )->post_content === $new;
}
$check( $ok, '13 writes succeed and the page holds exactly the written content' );
$ids = array_column( HistoryService::listFor( $pageId ), 'id' );
$check( $ids === range( 15, 6 ), 'retention keeps the newest 10, ids continue after the imported ones (got ' . implode( ',', $ids ) . ')' );

$big = str_repeat( "<p>\u{1F600} \\ \" \u{e9} 0123456789</p>\n", 12000 ); // ~ 400 KB
$check( strlen( $big ) > 350000 && strlen( $big ) < PageHistory::MAX_BYTES, 'big fixture is large but under the per-snapshot cap (' . strlen( $big ) . ' bytes)' );
HistoryService::write( $pageId, $big, 'update_page_layout' );
$w = HistoryService::write( $pageId, 'after big', 'update_page_layout' );
$check( true === $w['snapshot']['stored'], 'the big content is snapshotted before being replaced' );
$check( ( HistoryService::entry( $pageId, (int) $w['snapshot']['version_id'] )['content'] ?? null ) === $big, 'big snapshot round-trips byte-for-byte on the real table' );
$total = 0;
foreach ( HistoryService::listFor( $pageId ) as $row ) {
    $total += (int) $row['bytes'];
}
$check( $total <= PageHistory::MAX_TOTAL_BYTES, "per-page budget respected ({$total} <= " . PageHistory::MAX_TOTAL_BYTES . ')' );

// ---- 3. Restore ---------------------------------------------------------------------------
$list   = HistoryService::listFor( $pageId );
$oldest = (int) end( $list )['id'];
$want   = HistoryService::entry( $pageId, $oldest )['content'];
$r      = HistoryService::restore( $pageId, $oldest );
$check( $r['ok'] && get_post( $pageId )->post_content === $want, 'restore of the oldest kept version puts back its exact content' );
$check( true === $r['snapshot']['stored'], 'the restore snapshots the content it replaces' );

// ---- 4. Unique key: a racing writer with different content is a clash ---------------------
$newest = (int) HistoryService::listFor( $pageId )[0]['id'];
$clash  = [ 'next' => $newest + 1, 'items' => [ [ 'id' => $newest, 'saved_at' => 't', 'tool' => 't', 'actor' => 0, 'bytes' => 5, 'sha256' => hash( 'sha256', 'other' ), 'label' => null, 'content' => 'other' ] ] ];
$check( false === HistoryStore::save( $pageId, $clash ), 'same (page, version) with different content is refused' );
$check( 'other' !== ( HistoryService::entry( $pageId, $newest )['content'] ?? null ), 'the stored snapshot was not overwritten' );

// ---- 5. Dashboard query ---------------------------------------------------------------------
$recent = HistoryStore::recent( 50 );
$mine   = array_values( array_filter( $recent, static fn ( array $row ): bool => $row['page_id'] === $pageId ) );
$check( 1 === count( $mine ) && $mine[0]['version_id'] === (int) HistoryService::listFor( $pageId )[0]['id'], 'recent() lists the page once, with its newest version' );

// ---- 6. Trash keeps history, permanent delete removes it ----------------------------------
$before = $rowCount( $pageId );
wp_trash_post( $pageId );
$check( $rowCount( $pageId ) === $before && $before > 0, 'trashing a page keeps its history' );
wp_delete_post( $pageId, true );
$check( 0 === $rowCount( $pageId ), 'permanently deleting a page removes its history rows (delete_post hook)' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'History table verified on the real database.' );
```

- [ ] **Step 2: Add the Makefile target**

In `Makefile`: add `verify-history` to the `.PHONY` list on line 1, and append after the `verify-modules` target:

```make

# ---------------------------------------------------------------
# verify-history — real-database check of the undo-history table (Docker site)
# ---------------------------------------------------------------
verify-history:
	@docker compose exec -T wpcli wp eval-file /scripts/verify-history-table.php --user=1
```

- [ ] **Step 3: Run it on the real site**

Run: `make verify-history`
Expected: every line `PASS`, ending `Success: History table verified on the real database.` Exit 0.

If a check fails, that is a real defect: use superpowers:systematic-debugging, fix the plugin (and add a unit test where the fake can reproduce it), rerun. Typical causes: a column too short (`tool`/`label`/`saved_at` lengths), `bytes` vs multi-byte, `utf8mb4` conversion of the table, `dbDelta` not creating `UNIQUE KEY`. If `--user=1` does not exist on the dev site, use the id of any administrator (`docker compose exec -T wpcli wp user list --role=administrator --field=ID`) — the user needs `unfiltered_html` so the page content is not altered by kses.

Also note for the report: the first run migrates the dev site's own real `_aied_history` meta into the table (intended). Confirm afterwards: `docker compose exec -T wpcli wp db query "SELECT page_id, COUNT(*) c FROM wp_ai_editor_divi5_history GROUP BY page_id"` (adjust the prefix if it is not `wp_`).

- [ ] **Step 4: Re-run to prove idempotence, then commit**

Run `make verify-history` a second time → Expected: PASS again.

```bash
git add scripts/verify-history-table.php Makefile
git commit -m "test: real-database verification of the history table (make verify-history)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: 4.1.0 release prep (docs, version, zip, Plugin Check, local merge)

**Files:**
- Modify: `wp-plugin/jhmg-ai-editor-for-divi-5.php` (header + constant), `wp-plugin/readme.txt`, `CLAUDE.md`
- Regenerate: `jhmg-ai-editor-for-divi-5.zip`

**Interfaces:** Consumes everything above. Produces the merged local `main` at 4.1.0.

- [ ] **Step 1: Version bump**

In `wp-plugin/jhmg-ai-editor-for-divi-5.php`: header ` * Version:           4.0.0` → ` * Version:           4.1.0`, and `define('AI_EDITOR_DIVI5_VERSION', '4.0.0');` → `define('AI_EDITOR_DIVI5_VERSION', '4.1.0');`.

In `wp-plugin/readme.txt`: `Stable tag:        4.0.0` → `Stable tag:        4.1.0`.

- [ ] **Step 2: readme changelog, upgrade notice and privacy wording**

In `wp-plugin/readme.txt` insert directly under `== Changelog ==` (before `= 4.0.0 =`):

```
= 4.1.0 =
* Undo history is now kept in its own table in your database instead of in each page's custom fields. Pages and the front end no longer load it, and copying or exporting a page no longer carries its history along.
* Existing undo history is moved over automatically. Nothing changes in how undo works or in what your AI assistant sees.
* Going back to version 4.0.0 afterwards is not supported: it would not find the moved history.
* Deleting a page now also deletes its undo history.
```

Under `== Upgrade Notice ==`, insert before `= 4.0.0 =`:

```
= 4.1.0 =
Undo history moves to its own database table. Your existing history is moved over automatically the first time the site loads after the update.
```

In the `= Privacy =` list, replace the line beginning `* Page history for undo:` with:

```
* Page history for undo: up to the last 10 previous versions of each page the AI changes (fewer for very large pages, about 768 KB per page at most). It is kept in a table in your own database.
```

Then run `vendor/bin/phpunit tests/WpOrgComplianceTest.php` — the readme checks reject words such as `unlock`, `locked`, `premium`, `upgrade to`, `license key`; the text above avoids them, keep it that way if you reword.

- [ ] **Step 3: Update `CLAUDE.md`**

(a) Replace the sentence in the "Undo (v3.5.0)" paragraph `previous content (post meta `_aied_history`) before saving: up to the last 10 per page, each <= 512 KB, trimmed oldest-first to a ~768 KB per-page budget` with:

```
previous content before saving, one row per snapshot in the table
`{prefix}ai_editor_divi5_history` (`HistoryTable` = schema/hooks, `HistoryStore` = the only
queries, `HistoryMigration` = import of 4.0.0's `_aied_history` post meta; 4.1.0): up to the
last 10 per page, each <= 512 KB, trimmed oldest-first to a ~768 KB per-page budget
```

keeping the rest of the paragraph (`(`PageHistory::MAX_TOTAL_BYTES`; the newest is always kept)` ...) intact, and change the paragraph's heading text `Undo (v3.5.0)` to `Undo (v3.5.0, table storage since 4.1.0)`.

(b) In the "Entry points" table add a row after `make verify-modules`:

```
| `make verify-history` | Real-database check of the undo-history table on the Docker site (creates and deletes one throwaway draft page) |
```

(c) In "Current state" add a bullet at the top:

```
- **4.1.0 = history storage release** (branch `feat/history-table`, merged to local main, not uploaded):
  undo history moved from post meta to its own table with automatic migration; spec
  `docs/superpowers/specs/2026-10-01-history-table-storage-design.md`. Upload only after
  WordPress.org answers the 4.0.0 review (or fold into a change-request upload).
```

- [ ] **Step 4: Full test run**

Run: `vendor/bin/phpunit`
Expected: all green, no warnings or risky tests.

- [ ] **Step 5: Rebuild the zip**

Run: `bash scripts/build-plugin-zip.sh`
Expected: `[build] Packaging jhmg-ai-editor-for-divi-5 version 4.1.0` and the zip rewritten at the repo root. Verify contents: `unzip -l jhmg-ai-editor-for-divi-5.zip | grep -E "HistoryTable|HistoryMigration|HistoryStore"` lists the three classes, and `unzip -l ... | grep -c "\.DS_Store"` is 0.

- [ ] **Step 6: Plugin Check — dev copy**

The dev site mounts `./wp-plugin` as `jhmg-ai-editor-for-divi-5`, so:

Run: `docker compose exec -T wpcli wp plugin check jhmg-ai-editor-for-divi-5 --format=table`
Expected: `Success: Checks complete. No errors found.` Warnings about the DB calls are expected to be absent; if Plugin Check reports any error or warning on `HistoryStore.php`, `HistoryTable.php` or `HistoryMigration.php`, fix it with the narrowly-scoped DB `phpcs` rule names from Global Constraints (or fix the code), re-run until clean, and re-run `vendor/bin/phpunit`.

- [ ] **Step 7: Plugin Check — extracted zip**

```bash
SCRATCH="/private/tmp/claude-501/-Users-Lucas-Documents-JHMG-Local-Divi-5-Deterministic-Validator/7b64f78d-b27d-45c7-bebc-c50841ece09a/scratchpad"
rm -rf "$SCRATCH/zipcheck" && mkdir -p "$SCRATCH/zipcheck"
unzip -q jhmg-ai-editor-for-divi-5.zip -d "$SCRATCH/zipcheck"
docker compose cp "$SCRATCH/zipcheck/jhmg-ai-editor-for-divi-5" wordpress:/var/www/html/wp-content/plugins/jhmg-ai-editor-for-divi-5-zipcheck
docker compose exec -T wpcli wp plugin check jhmg-ai-editor-for-divi-5-zipcheck --slug=jhmg-ai-editor-for-divi-5 --format=table
```

Expected: `Success: Checks complete. No errors found.` Do NOT activate the copy (two copies of the plugin cannot both be active) and do NOT `wp plugin delete` it (that runs `uninstall.php` against the shared dev database and drops the history table). Remove it with:

```bash
docker compose exec -T -u root wordpress rm -rf /var/www/html/wp-content/plugins/jhmg-ai-editor-for-divi-5-zipcheck
```

- [ ] **Step 8: Re-run the real-database check against the final code**

Run: `make verify-history` → Expected: all PASS.

- [ ] **Step 9: Commit and merge locally**

```bash
git add wp-plugin/jhmg-ai-editor-for-divi-5.php wp-plugin/readme.txt CLAUDE.md jhmg-ai-editor-for-divi-5.zip
git commit -m "release: 4.1.0 — undo history in its own table, with automatic migration

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
git checkout main
git merge --no-ff feat/history-table -m "Merge feat/history-table: 4.1.0 history table storage

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
vendor/bin/phpunit
```

Expected: merge succeeds, suite green on `main`. **Do not push, upload or send anything.** Leave the branch in place.

---

## Self-Review

**1. Spec coverage**

| Spec requirement | Task |
|---|---|
| Table + columns + `UNIQUE (page_id, version_id)`, own option | 1 |
| `version_id` per-page monotonic, next = MAX+1, migrated ids kept | 2 (`load` via `normalize`), 3 |
| `PageHistory` unchanged in behaviour, comment reworded | 2 (dead `recent()` removed, flagged below) |
| `HistoryStore` facade; `save` syncs inserts/deletes, false on failure, never deletes on failure | 2 |
| `HistoryStore::recent` (no content) and `summaries` | 2 |
| Entry loads one row by `(page_id, version_id)` | 2 |
| Transports and `HistoryService` public results unchanged | 2 (no transport file touched) |
| Create/update on `plugins_loaded` AND activation | 3 |
| `delete_post` cleanup | 3 (+ real DB in 4) |
| `wpmu_drop_tables` for both tables | 1 (filter), 3 (registered) |
| Uninstall: drop table, option, legacy meta | 2 (`deleteAll`; `uninstall.php` already calls it) |
| Migration: batch of 20, idempotent, delete meta only after all rows inserted, lazy on `load()` | 3 (lazy also on `summaries`/`entry`, added because a list/restore on an unmigrated page would otherwise look empty) |
| Behaviour that must not change (skip reasons, restore, no `wp_slash`, write never blocked) | 2 (tests), 4 |
| Failure modes: no CREATE TABLE, race | 3, 2 |
| Real-database check wired to `make` | 4 |
| Compliance test not weakened, DB ignores narrow | 1, 3 (two explicit exceptions), 5 |
| readme privacy + changelog incl. no-downgrade, version 4.1.0, zip, Plugin Check, local merge | 5 |

Gaps: none. Deviations from the spec, to report to the owner: (a) undecodable legacy meta is **deleted** rather than left in place (spec text would prevent the migration from ever finishing — see top); (b) `PageHistory::recent()` removed as dead code after the dashboard query moved to SQL; (c) lazy import also covers `summaries()` and `entry()`, not only `load()`; (d) a backoff transient (5 min) prevents a `dbDelta` attempt on every request when the host forbids CREATE TABLE.

**2. Placeholder scan:** no TBD/TODO; every code step has code; commands have expected output.

**3. Type consistency:** `HistoryStore::LEGACY_META_KEY` (Task 2) is used by `HistoryMigration`, tests, `deleteAll`; `HistoryTable::STAGE_TABLE/STAGE_MIGRATED/DB_VERSION_OPTION/hasTable()/migrationDone()/name()/exists()/drop()/dropTablesFilter()` (Task 1) match their uses in Tasks 2–4; `HistoryStore::insertMissing(int, array): bool` (Task 2) is the call used by `HistoryMigration::importPage` (Task 3); `ensureReady(bool $force = false)`/`register()` (Task 3) match the main-file calls; `HistoryService::recent(int $limit = 5)` matches `AdminPage::historySection()` (`HistoryService::recent( 5 )`, no change needed).

**4. Review Focus:** items 1 (garbage meta), 2 (lazy + id continuation), 3 (CREATE TABLE denied) are pinned in `HistoryMigrationTest`; 4 (race/clash) in `HistoryStoreTest`/`HistoryServiceTest` plus the real unique-key check in Task 4; 5 (fidelity, trash vs delete) in `HistoryStoreTest` and `scripts/verify-history-table.php`.
