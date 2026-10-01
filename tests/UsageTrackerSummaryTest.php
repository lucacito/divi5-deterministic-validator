<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\UsageTracker;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';

/** "Changes saved" must count successful writes only; reads are logged as 'read'. */
class UsageTrackerSummaryTest extends TestCase
{
    private const WRITE_ENDPOINTS = ['update_page', 'update_layout', 'edit_page', 'edit_content', 'restore_version', 'create_page'];

    public function testSummaryKeepsSavedReadsAndTotalApart(): void
    {
        $s = UsageTracker::summaryFromCounts(['total' => '10', 'saved' => '2', 'reads' => '7', 'invalid' => '1', 'today' => '4'], [['client' => 'Cursor', 'cnt' => 3]]);
        $this->assertSame(10, $s['total']);
        $this->assertSame(2, $s['saved']);
        $this->assertSame(7, $s['reads']);
        $this->assertSame(1, $s['invalid']);
        $this->assertSame(4, $s['today']);
        $this->assertSame([['client' => 'Cursor', 'cnt' => 3]], $s['byClient']);
    }

    public function testEmptyTableIsAllZeros(): void
    {
        $s = UsageTracker::summaryFromCounts([]);
        $this->assertSame([ 'total' => 0, 'saved' => 0, 'reads' => 0, 'invalid' => 0, 'today' => 0, 'byClient' => [] ], $s);
    }

    public function testResultValuesFitTheColumn(): void
    {
        foreach ([UsageTracker::RESULT_SAVED, UsageTracker::RESULT_READ, UsageTracker::RESULT_INVALID, UsageTracker::RESULT_ERROR] as $r) {
            $this->assertLessThanOrEqual(10, strlen($r), 'result column is varchar(10)');
        }
    }

    public function testOnlyWriteEndpointsEverLogValid(): void
    {
        $seen = 0;
        foreach (['McpHandler.php', 'RestController.php', 'AdminPage.php'] as $file) {
            $src = (string) file_get_contents(__DIR__ . '/../wp-plugin/src/' . $file);
            preg_match_all("/UsageTracker::log\(\s*'([a-z_]+)'\s*,[^;]*?'valid'/", $src, $m);
            foreach ($m[1] as $endpoint) {
                $seen++;
                $this->assertContains($endpoint, self::WRITE_ENDPOINTS, "{$file}: read-only endpoint '{$endpoint}' must log 'read', not 'valid'");
            }
        }
        $this->assertGreaterThan(5, $seen, 'the write endpoints are still logged as valid');
    }

    public function testReadEndpointsLogRead(): void
    {
        foreach (['McpHandler.php' => ['list_pages', 'get_layout', 'validate', 'list_history', 'get_history', 'list_media'],
                  'RestController.php' => ['get_page', 'validate', 'list_history', 'get_history', 'list_media']] as $file => $endpoints) {
            $src = (string) file_get_contents(__DIR__ . '/../wp-plugin/src/' . $file);
            foreach ($endpoints as $e) {
                $this->assertMatchesRegularExpression("/UsageTracker::log\('{$e}',[^;]*'read'/", $src, "{$file}: {$e} logs 'read' on success");
            }
        }
    }

    public function testSqlAliasesAvoidMariaDbReservedWords(): void
    {
        // Found live: `AS reads` is a reserved word in MariaDB, the query failed and the Dashboard showed "No activity yet".
        $src = (string) file_get_contents(__DIR__ . '/../wp-plugin/src/UsageTracker.php');
        $this->assertDoesNotMatchRegularExpression('/\bAS\s+(reads|read|write|writes|rows|key)\b/i', $src);
        $s = UsageTracker::summaryFromCounts(['total' => 3, 'saved' => 1, 'read_calls' => 2]);
        $this->assertSame(2, $s['reads']);
    }
}
