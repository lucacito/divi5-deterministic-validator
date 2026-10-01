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
    /** Make the next N SELECT-type calls (get_results / get_col / get_var) fail, like a dropped connection. */
    public int $failSelects = 0;
    /** Runs once at the start of the next insert(): lets a test simulate another request writing first. */
    public ?\Closure $beforeInsert = null;
    /** Like wpdb::$last_error: '' after a successful query, a message after a failed one. */
    public string $last_error = '';
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
        $this->last_error = '';
        if ( $this->failSelects > 0 ) {
            --$this->failSelects;
            $this->last_error = 'simulated select failure';
            return [];
        }
        try {
            return $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_ASSOC );
        } catch ( \PDOException $e ) {
            $this->last_error = $e->getMessage();
            return [];
        }
    }

    /** @return list<string> */
    public function get_col( string $sql ): array
    {
        $this->queries[] = $sql;
        $this->last_error = '';
        if ( $this->failSelects > 0 ) {
            --$this->failSelects;
            $this->last_error = 'simulated select failure';
            return [];
        }
        if ( str_contains( $sql, $this->postmeta ) ) {
            return $this->legacyMetaPageIds( $sql );
        }
        try {
            return $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_COLUMN );
        } catch ( \PDOException $e ) {
            $this->last_error = $e->getMessage();
            return [];
        }
    }

    public function get_var( string $sql ): ?string
    {
        $this->queries[] = $sql;
        $this->last_error = '';
        if ( $this->failSelects > 0 ) {
            --$this->failSelects;
            $this->last_error = 'simulated select failure';
            return null;
        }
        try {
            $v = $this->pdo->query( $sql )->fetchColumn();
            return false === $v ? null : (string) $v;
        } catch ( \PDOException $e ) {
            $this->last_error = $e->getMessage();
            return null; // like wpdb with errors suppressed: a missing table yields null
        }
    }

    /** @param array<string,mixed> $data @param list<string> $format */
    public function insert( string $table, array $data, array $format = [] ): int|false
    {
        $this->queries[] = "INSERT INTO {$table} (" . implode( ', ', array_keys( $data ) ) . ')';
        $this->last_error = '';
        if ( null !== $this->beforeInsert ) {
            $hook               = $this->beforeInsert;
            $this->beforeInsert = null;
            $hook();
            $this->last_error = '';
        }
        if ( $this->failInserts ) {
            $this->last_error = 'simulated insert failure';
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
            $this->last_error = $e->getMessage();
            return false; // e.g. UNIQUE (page_id, version_id) clash
        }
    }

    /** @param array<string,mixed> $where @param list<string> $format */
    public function delete( string $table, array $where, array $format = [] ): int|false
    {
        $this->queries[] = "DELETE FROM {$table} WHERE " . implode( ' AND ', array_keys( $where ) );
        $this->last_error = '';
        try {
            $clause = implode( ' AND ', array_map( static fn ( string $c ): string => "{$c} = :{$c}", array_keys( $where ) ) );
            $stmt   = $this->pdo->prepare( "DELETE FROM {$table} WHERE {$clause}" );
            $stmt->execute( $where );
            return $stmt->rowCount();
        } catch ( \PDOException $e ) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function query( string $sql ): int|false
    {
        $this->queries[] = $sql;
        $this->last_error = '';
        try {
            return (int) $this->pdo->exec( $sql );
        } catch ( \PDOException $e ) {
            $this->last_error = $e->getMessage();
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
