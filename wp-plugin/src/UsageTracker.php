<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Logs every API call to a custom DB table.
 * Schema is created on plugin activation via createTable().
 */
final class UsageTracker
{
    private const DB_VERSION_OPTION = 'ai_editor_divi5_db_version';
    private const DB_VERSION        = 1;

    /**
     * Result values stored in the `result` column (varchar(10)).
     *   valid   a successful WRITE (update, edit, create or restore saved a page)
     *   read    a successful read-only call (list, get, validate, history, media)
     *   invalid a layout the checker refused
     *   error   a call that failed (not found, not allowed, could not save)
     */
    public const RESULT_SAVED   = 'valid';
    public const RESULT_READ    = 'read';
    public const RESULT_INVALID = 'invalid';
    public const RESULT_ERROR   = 'error';

    public static function tableName(): string
    {
        global $wpdb;
        return esc_sql( $wpdb->prefix . 'ai_editor_divi5_usage' );
    }

    public static function createTable(): void
    {
        global $wpdb;

        if ((int) get_option(self::DB_VERSION_OPTION, 0) >= self::DB_VERSION) {
            return;
        }

        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          bigint(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
            created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            endpoint    varchar(50)         NOT NULL,
            page_id     bigint(20) UNSIGNED     NULL DEFAULT NULL,
            result      varchar(10)         NOT NULL,
            violations  tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
            client      varchar(120)            NULL DEFAULT NULL,
            ip_hash     char(64)                NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_created (created_at),
            KEY idx_result  (result)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public static function dropTable(): void
    {
        global $wpdb;
        $table = self::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        delete_option( self::DB_VERSION_OPTION );
    }

    // ---------------------------------------------------------------
    // Logging
    // ---------------------------------------------------------------

    public static function log(string $endpoint, ?int $pageId, string $result, int $violations = 0): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            self::tableName(),
            [
                'endpoint'   => substr($endpoint, 0, 50),
                'page_id'    => $pageId,
                'result'     => substr($result, 0, 10),
                'violations' => $violations,
                'client'     => self::detectClient($ua),
                // Salted with the site's own secret (wp_hash), so the stored value cannot be reversed to the IP.
                'ip_hash'    => $ip ? wp_hash($ip) : null,
            ],
            ['%s', $pageId !== null ? '%d' : '%s', '%s', '%d', '%s', '%s']
        );
    }

    private static function detectClient(string $ua): string
    {
        $lower = strtolower($ua);
        $map   = [
            'claude'   => 'Claude Desktop',
            'cursor'   => 'Cursor',
            'windsurf' => 'Windsurf',
            'copilot'  => 'VS Code Copilot',
            'vscode'   => 'VS Code Copilot',
            'chatgpt'  => 'ChatGPT',
            'openai'   => 'ChatGPT',
            'gemini'   => 'Gemini',
            'google'   => 'Gemini',
        ];
        foreach ($map as $needle => $label) {
            if (str_contains($lower, $needle)) {
                return $label;
            }
        }
        return $ua !== '' ? substr($ua, 0, 80) : 'Unknown';
    }

    // ---------------------------------------------------------------
    // Queries for admin view
    // ---------------------------------------------------------------

    public static function getSummary(): array
    {
        global $wpdb;
        $table = self::tableName();

        // Table name from $wpdb->prefix (trusted); WHERE values are static string literals — no user input.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $counts = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM(result = 'valid') AS saved,
                SUM(result = 'read') AS read_calls,
                SUM(result = 'invalid') AS invalid,
                SUM(DATE(created_at) = CURDATE()) AS today
            FROM {$table}",
            ARRAY_A
        );

        $byClient = $wpdb->get_results(
            "SELECT client, COUNT(*) as cnt FROM {$table} GROUP BY client ORDER BY cnt DESC LIMIT 8",
            ARRAY_A
        ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return self::summaryFromCounts( is_array( $counts ) ? $counts : [], is_array( $byClient ) ? $byClient : [] );
    }

    /**
     * Pure shaping of the aggregate row. "saved" counts successful writes only,
     * "reads" successful read-only calls, "total" every logged call.
     *
     * @param array<string,mixed> $counts
     * @param list<array<string,mixed>> $byClient
     * @return array{total:int,saved:int,reads:int,invalid:int,today:int,byClient:list<array<string,mixed>>}
     */
    public static function summaryFromCounts(array $counts, array $byClient = []): array
    {
        return [
            'total'    => (int) ($counts['total']   ?? 0),
            'saved'    => (int) ($counts['saved']   ?? 0),
            'reads'    => (int) ($counts['read_calls'] ?? $counts['reads'] ?? 0),
            'invalid'  => (int) ($counts['invalid'] ?? 0),
            'today'    => (int) ($counts['today']   ?? 0),
            'byClient' => $byClient,
        ];
    }

    public static function getRecent(int $limit = 50): array
    {
        global $wpdb;
        $table = self::tableName();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit );
        return $wpdb->get_results( $sql, ARRAY_A ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    public static function clear(): void
    {
        global $wpdb;
        $table = self::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "TRUNCATE TABLE {$table}" );
    }
}
