<?php

declare(strict_types=1);

namespace Divi5Validator\WP;

/**
 * Logs every validator API call to a custom DB table.
 * Schema is created on plugin activation via createTable().
 */
final class UsageTracker
{
    private const DB_VERSION_OPTION = 'divi5_validator_db_version';
    private const DB_VERSION        = 1;

    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'divi5_validator_usage';
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
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        delete_option(self::DB_VERSION_OPTION);
    }

    // ---------------------------------------------------------------
    // Logging
    // ---------------------------------------------------------------

    public static function log(string $endpoint, ?int $pageId, string $result, int $violations = 0): void
    {
        global $wpdb;

        $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip     = $_SERVER['REMOTE_ADDR']     ?? '';

        $wpdb->insert(
            self::tableName(),
            [
                'endpoint'   => substr($endpoint, 0, 50),
                'page_id'    => $pageId ?: null,
                'result'     => substr($result, 0, 10),
                'violations' => $violations,
                'client'     => self::detectClient($ua),
                'ip_hash'    => $ip ? hash('sha256', $ip) : null,
            ],
            ['%s', '%d', '%s', '%d', '%s', '%s']
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

        return [
            'total'    => (int) $wpdb->get_var("SELECT COUNT(*)         FROM {$table}"),
            'valid'    => (int) $wpdb->get_var("SELECT COUNT(*)         FROM {$table} WHERE result = 'valid'"),
            'invalid'  => (int) $wpdb->get_var("SELECT COUNT(*)         FROM {$table} WHERE result = 'invalid'"),
            'today'    => (int) $wpdb->get_var("SELECT COUNT(*)         FROM {$table} WHERE DATE(created_at) = CURDATE()"),
            'byClient' => $wpdb->get_results(
                "SELECT client, COUNT(*) as cnt FROM {$table} GROUP BY client ORDER BY cnt DESC LIMIT 8",
                ARRAY_A
            ) ?: [],
        ];
    }

    public static function getRecent(int $limit = 50): array
    {
        global $wpdb;
        $table = self::tableName();
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit),
            ARRAY_A
        ) ?: [];
    }

    public static function clear(): void
    {
        global $wpdb;
        $table = self::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("TRUNCATE TABLE {$table}");
    }
}
