<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\PageHistory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';

class PageHistoryTest extends TestCase
{
    private function rec(array $h, string $content, string $at = '2026-10-01T00:00:00Z', string $tool = 'update_page_layout'): array
    {
        return PageHistory::record($h, $content, $tool, 7, $at, null);
    }

    public function testConstantsArePinned(): void
    {
        $this->assertSame(10, PageHistory::RETENTION);
        $this->assertSame(524288, PageHistory::MAX_BYTES);
        $this->assertSame(786432, PageHistory::MAX_TOTAL_BYTES);
    }

    public function testSourceDoesNotDependOnMbstring(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../wp-plugin/src/PageHistory.php');
        $this->assertSame(0, preg_match('/\bmb_[a-z_]+\s*\(/', $src), 'WordPress does not polyfill mb_* (mbstring may be absent).');
    }

    public function testInvalidUtf8LeadingByteSequenceIsRejected(): void
    {
        $r = $this->rec(PageHistory::empty(), "\xC3\x28");
        $this->assertFalse($r['stored']);
        $this->assertSame('invalid_encoding', $r['reason']);
    }

    public function testEmptyStringAndLargeMultibyteAreStored(): void
    {
        $e = $this->rec(PageHistory::empty(), '');
        $this->assertTrue($e['stored']);
        $big = str_repeat("\u{e9}", 262144); // exactly 512 KB (2 bytes each)
        $this->assertSame(PageHistory::MAX_BYTES, strlen($big));
        $r = $this->rec(PageHistory::empty(), $big);
        $this->assertTrue($r['stored']);
        $this->assertNull($r['reason']);
    }

    public function testTotalBudgetTrimsOldestSnapshotsAndKeepsIdsMonotonic(): void
    {
        $h = PageHistory::empty();
        for ($i = 1; $i <= 6; $i++) {
            $h = $this->rec($h, str_repeat((string) $i, 200000))['history']; // 200,000 bytes each
        }
        // 3 x 200,000 = 600,000 fits in 786,432; 4 x = 800,000 does not.
        $this->assertCount(3, $h['items']);
        $this->assertSame([6, 5, 4], array_column($h['items'], 'id'));
        $this->assertLessThanOrEqual(PageHistory::MAX_TOTAL_BYTES, array_sum(array_column($h['items'], 'bytes')));
        $this->assertSame(7, $h['next']);
        $this->assertSame(7, $this->rec($h, 'seven')['version_id']);
    }

    public function testNewestIsAlwaysKeptEvenWhenItAloneNearsTheBudget(): void
    {
        $h = $this->rec(PageHistory::empty(), str_repeat('a', PageHistory::MAX_BYTES))['history'];
        $r = $this->rec($h, str_repeat('b', PageHistory::MAX_BYTES));
        $this->assertTrue($r['stored']);
        $this->assertCount(1, $r['history']['items']);
        $this->assertSame(2, $r['history']['items'][0]['id']);
        $this->assertSame(PageHistory::MAX_BYTES, $r['history']['items'][0]['bytes']);
    }

    public function testSmallItemsStillKeepTenUnderTheBudget(): void
    {
        $h = PageHistory::empty();
        for ($i = 1; $i <= 12; $i++) {
            $h = $this->rec($h, "small {$i}")['history'];
        }
        $this->assertCount(PageHistory::RETENTION, $h['items']);
    }

    public function testRecordStoresNewestFirstWithMetadata(): void
    {
        $r = $this->rec(PageHistory::empty(), 'A');
        $this->assertTrue($r['stored']);
        $this->assertSame(1, $r['version_id']);
        $this->assertNull($r['reason']);
        $r2 = $this->rec($r['history'], 'B', '2026-10-01T01:00:00Z');
        $this->assertSame(2, $r2['version_id']);
        $items = $r2['history']['items'];
        $this->assertSame(['B', 'A'], array_column($items, 'content'));
        $this->assertSame(hash('sha256', 'B'), $items[0]['sha256']);
        $this->assertSame(1, $items[0]['bytes']);
        $this->assertSame(7, $items[0]['actor']);
        $this->assertSame('update_page_layout', $items[0]['tool']);
    }

    public function testDuplicateOfNewestIsNotStoredButPointsAtIt(): void
    {
        $r = $this->rec(PageHistory::empty(), 'same');
        $d = $this->rec($r['history'], 'same');
        $this->assertFalse($d['stored']);
        $this->assertSame('duplicate', $d['reason']);
        $this->assertSame(1, $d['version_id']);
        $this->assertCount(1, $d['history']['items']);
    }

    public function testNonAdjacentRepeatIsStoredAgain(): void
    {
        $h = $this->rec(PageHistory::empty(), 'A')['history'];
        $h = $this->rec($h, 'B')['history'];
        $r = $this->rec($h, 'A');
        $this->assertTrue($r['stored']);
        $this->assertCount(3, $r['history']['items']);
    }

    public function testOversizedContentIsNotStored(): void
    {
        $big = str_repeat('x', PageHistory::MAX_BYTES + 1);
        $r = $this->rec(PageHistory::empty(), $big);
        $this->assertFalse($r['stored']);
        $this->assertNull($r['version_id']);
        $this->assertSame('too_large', $r['reason']);
        $this->assertSame([], $r['history']['items']);
    }

    public function testInvalidUtf8ContentIsNotStoredAndHistoryIsUnchanged(): void
    {
        $h = $this->rec(PageHistory::empty(), 'A')['history'];
        $r = $this->rec($h, "bad \xC3\x28 bytes");
        $this->assertFalse($r['stored']);
        $this->assertNull($r['version_id']);
        $this->assertSame('invalid_encoding', $r['reason']);
        $this->assertSame($h, $r['history']);
    }

    public function testValidMultibyteUtf8IsStillStored(): void
    {
        $r = $this->rec(PageHistory::empty(), "h\u{e9}llo \u{1F600}");
        $this->assertTrue($r['stored']);
        $this->assertSame("h\u{e9}llo \u{1F600}", $r['history']['items'][0]['content']);
    }

    public function testContentExactlyAtTheCapIsStored(): void
    {
        $r = $this->rec(PageHistory::empty(), str_repeat('x', PageHistory::MAX_BYTES));
        $this->assertTrue($r['stored']);
    }

    public function testRetentionTrimsOldestAndIdsAreNeverReused(): void
    {
        $h = PageHistory::empty();
        for ($i = 1; $i <= PageHistory::RETENTION + 3; $i++) {
            $h = $this->rec($h, "v{$i}")['history'];
        }
        $this->assertCount(PageHistory::RETENTION, $h['items']);
        $this->assertSame(PageHistory::RETENTION + 3, $h['items'][0]['id']);
        $this->assertSame('v4', end($h['items'])['content']);
        $next = $this->rec($h, 'later');
        $this->assertSame(PageHistory::RETENTION + 4, $next['version_id']);
    }

    public function testSummariesOmitContentAndKeepOrder(): void
    {
        $h = $this->rec($this->rec(PageHistory::empty(), 'A')['history'], 'B')['history'];
        $s = PageHistory::summaries($h);
        $this->assertSame([2, 1], array_column($s, 'id'));
        foreach ($s as $row) {
            $this->assertArrayNotHasKey('content', $row);
            $this->assertSame(['id', 'saved_at', 'tool', 'actor', 'bytes', 'label'], array_keys($row));
        }
    }

    public function testFind(): void
    {
        $h = $this->rec(PageHistory::empty(), 'A')['history'];
        $this->assertSame('A', PageHistory::find($h, 1)['content']);
        $this->assertNull(PageHistory::find($h, 99));
    }

    /** @param mixed $raw */
    #[\PHPUnit\Framework\Attributes\DataProvider('garbage')]
    public function testNormalizeNeverThrowsAndReturnsAValidHistory(mixed $raw): void
    {
        $h = PageHistory::normalize($raw);
        $this->assertSame(['next', 'items'], array_keys($h));
        $this->assertGreaterThanOrEqual(1, $h['next']);
        $this->assertIsArray($h['items']);
    }

    public static function garbage(): array
    {
        return [[null], [''], ['text'], [42], [[]], [['items' => 'no']], [['items' => [1, 'x', null]]], [['items' => [['id' => 'a', 'content' => 1]]]]];
    }

    public function testNormalizeKeepsGoodItemsDropsBadAndRepairsNext(): void
    {
        $h = PageHistory::normalize(['next' => 1, 'items' => [
            ['id' => 5, 'content' => 'ok', 'sha256' => 'x', 'saved_at' => 't', 'tool' => 't', 'actor' => 1, 'bytes' => 2, 'label' => null],
            ['id' => 'bad', 'content' => 'no'],
        ]]);
        $this->assertCount(1, $h['items']);
        $this->assertSame(6, $h['next']);
    }

    public function testRecentTakesNewestPerPageAcrossPages(): void
    {
        $a = $this->rec(PageHistory::empty(), 'a1', '2026-10-01T01:00:00Z')['history'];
        $a = $this->rec($a, 'a2', '2026-10-01T05:00:00Z')['history'];
        $b = $this->rec(PageHistory::empty(), 'b1', '2026-10-01T03:00:00Z', 'edit_page_content')['history'];
        $rows = PageHistory::recent([10 => $a, 20 => $b, 30 => PageHistory::empty()], 5);
        $this->assertSame([10, 20], array_column($rows, 'page_id'));
        $this->assertSame(2, $rows[0]['version_id']);
        $this->assertSame('edit_page_content', $rows[1]['tool']);
        $this->assertCount(1, PageHistory::recent([10 => $a, 20 => $b], 1));
    }
}
