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

    /** Set after a refused CREATE TABLE or a migration batch with a failed page: no retry until it expires. */
    public const BACKOFF_TRANSIENT = 'ai_editor_divi5_history_backoff';
    public const BACKOFF_SECONDS   = 300;
    /** Pages whose legacy meta is imported per request. */
    private const MIGRATION_BATCH = 20;

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
                set_transient( self::BACKOFF_TRANSIENT, 1, self::BACKOFF_SECONDS );

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

    public static function drop(): void
    {
        global $wpdb;

        $table = self::name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        delete_option( self::DB_VERSION_OPTION );
        delete_transient( self::BACKOFF_TRANSIENT );
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
