<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The table behind the OAuth sign-in (clients, codes, access and refresh tokens, one row each) and its
 * lifecycle. It exists only after the owner switches the sign-in on, and is dropped when it is switched off or
 * the plugin is uninstalled. The queries on its rows live in OAuthStore.
 */
final class OAuthTable
{
    public const DB_VERSION_OPTION = 'ai_editor_divi5_oauth_db_version';

    private const DB_VERSION = 1;

    public static function name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ai_editor_divi5_oauth';
    }

    public static function schemaSql(): string
    {
        global $wpdb;

        $table   = self::name();
        $charset = $wpdb->get_charset_collate();

        // dbDelta needs two spaces after PRIMARY KEY and one definition per line.
        return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(10) NOT NULL,
  token_hash char(64) NOT NULL,
  client_id varchar(64) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  family varchar(64) NOT NULL DEFAULT '',
  data longtext NOT NULL,
  expires_at bigint(20) unsigned NOT NULL,
  used_at bigint(20) unsigned DEFAULT NULL,
  created_at bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY kind_hash (kind,token_hash),
  KEY family_idx (family),
  KEY client_idx (client_id),
  KEY user_idx (user_id),
  KEY expires_idx (expires_at)
) {$charset};";
    }

    /** Creates or upgrades the table when it is missing or old. Cheap when nothing is to be done. */
    public static function ensureReady( bool $force = false ): bool
    {
        if ( ! $force && (int) get_option( self::DB_VERSION_OPTION, 0 ) >= self::DB_VERSION ) {
            return true;
        }
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta( self::schemaSql() );
        if ( ! self::exists() ) {
            return false;
        }
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );

        return true;
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

    public static function drop(): void
    {
        global $wpdb;

        $table = self::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
        delete_option( self::DB_VERSION_OPTION );
    }
}
