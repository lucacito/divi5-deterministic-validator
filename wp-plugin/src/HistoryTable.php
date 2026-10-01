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
