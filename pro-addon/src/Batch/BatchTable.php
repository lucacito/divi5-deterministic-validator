<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The batch table: name, schema, create-on-demand (with a backoff when the host refuses) and cleanup. */
final class BatchTable
{
    public const DB_VERSION_OPTION = 'aied_pro_batches_db_version';
    public const BACKOFF_TRANSIENT = 'aied_pro_batches_backoff';
    public const BACKOFF_SECONDS   = 300;
    private const SCHEMA_VERSION   = 1;

    public static function name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'aied_pro_batches';
    }

    public static function schemaSql(): string
    {
        global $wpdb;

        $table   = self::name();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     bigint(20) UNSIGNED NOT NULL,
            tool        varchar(50)         NOT NULL,
            created_at  datetime            NOT NULL,
            summary     varchar(255)        NOT NULL,
            status      varchar(20)         NOT NULL,
            items       longtext            NOT NULL,
            PRIMARY KEY  (id)
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
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $wpdb->suppress_errors( $prev );

        return null !== $count;
    }

    /**
     * Creates the table when it is missing. Runs on `init` (an auto-update does not re-run the activation hook)
     * and on activation (with $force, which ignores the backoff).
     */
    public static function ensureReady( bool $force = false ): void
    {
        if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
            return;
        }
        if ( ! $force && false !== get_transient( self::BACKOFF_TRANSIENT ) ) {
            return;
        }
        self::createTable();
        if ( ! self::exists() ) {
            // The host refused CREATE TABLE (or it failed): wait instead of retrying every request.
            set_transient( self::BACKOFF_TRANSIENT, 1, self::BACKOFF_SECONDS );

            return;
        }
        update_option( self::DB_VERSION_OPTION, self::SCHEMA_VERSION, true );
    }

    public static function drop(): void
    {
        global $wpdb;

        $table = self::name();
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        delete_option( self::DB_VERSION_OPTION );
        delete_transient( self::BACKOFF_TRANSIENT );
    }

    /**
     * `wpmu_drop_tables` filter: when a multisite site is deleted, drop this table too.
     *
     * @param mixed $tables the site's table names
     * @return list<string>
     */
    public static function dropTablesFilter( mixed $tables ): array
    {
        $tables   = is_array( $tables ) ? array_values( $tables ) : [];
        $tables[] = self::name();

        return array_values( array_unique( $tables ) );
    }
}
