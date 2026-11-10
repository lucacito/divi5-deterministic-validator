<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The table behind the Studio screen: one row per saved brief (the small JSON Studio compiles into a page).
 * Name, schema and lifecycle live here; the queries on its rows live in StudioStore.
 *
 * It has its own table, not post meta, because the plugin writes no post meta (WpOrgComplianceTest).
 * Created lazily, like the OAuth table: ensureReady() is cheap once the version option is current, so a site
 * that updated from an older plugin version (an auto-update does not re-run the activation hook) still gets the table.
 */
final class StudioTable
{
    public const DB_VERSION_OPTION = 'ai_editor_divi5_studio_db_version';
    public const DB_VERSION        = 1;

    /** Set after a refused CREATE TABLE: no retry until it expires. */
    public const BACKOFF_TRANSIENT = 'ai_editor_divi5_studio_backoff';
    public const BACKOFF_SECONDS   = 300;

    public static function name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ai_editor_divi5_studio';
    }

    public static function schemaSql(): string
    {
        global $wpdb;

        $table   = self::name();
        $charset = $wpdb->get_charset_collate();

        // dbDelta needs two spaces after PRIMARY KEY and one definition per line.
        return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_by bigint(20) unsigned NOT NULL,
  page_id bigint(20) unsigned DEFAULT NULL,
  brief longtext NOT NULL,
  brief_hash char(64) NOT NULL,
  content_hash char(64) DEFAULT NULL,
  plugin_version varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_idx (created_by,updated_at),
  KEY page_idx (created_by,page_id)
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

    /**
     * Creates the table when it is missing or its schema version is old; true when it can be used. Cheap when nothing is to be done.
     * Called on activation (with $force) and at the start of every StudioStore call.
     */
    public static function ensureReady( bool $force = false ): bool
    {
        if ( ! $force && (int) get_option( self::DB_VERSION_OPTION, 0 ) >= self::DB_VERSION ) {
            return true;
        }
        if ( ! $force && false !== get_transient( self::BACKOFF_TRANSIENT ) ) {
            return false;
        }
        self::createTable();
        if ( ! self::exists() ) {
            set_transient( self::BACKOFF_TRANSIENT, 1, self::BACKOFF_SECONDS );

            return false;
        }
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );

        return true;
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
}
