<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Tools;

use Divi5Validator\Tools\EvidenceGuard;
use PHPUnit\Framework\TestCase;

class EvidenceGuardTest extends TestCase
{
    /** @param array<string,string> $statuses */
    private function doc(array $statuses): array
    {
        $results = [];
        foreach ($statuses as $name => $status) {
            $results[$name] = ['status' => $status];
        }

        return ['results' => $results];
    }

    public function testNothingLostWhenIdentical(): void
    {
        $d = $this->doc(['divi/a' => 'pass', 'divi/b' => 'needs-real-export']);
        $this->assertSame(['missing' => [], 'demoted' => []], EvidenceGuard::lostModules($d, $d));
    }

    public function testDroppedModuleIsListedAsMissingSorted(): void
    {
        $old = $this->doc(['divi/c' => 'pass', 'divi/a' => 'pass', 'divi/b' => 'pass']);
        $new = $this->doc(['divi/b' => 'pass']);
        $this->assertSame(['missing' => ['divi/a', 'divi/c'], 'demoted' => []], EvidenceGuard::lostModules($old, $new));
    }

    public function testPassToNeedsRealExportIsDemotedSorted(): void
    {
        $old = $this->doc(['divi/z' => 'pass', 'divi/a' => 'pass', 'divi/k' => 'pass']);
        $new = $this->doc(['divi/z' => 'needs-real-export', 'divi/a' => 'fail', 'divi/k' => 'pass']);
        $this->assertSame(['missing' => [], 'demoted' => ['divi/a', 'divi/z']], EvidenceGuard::lostModules($old, $new));
    }

    public function testNeedsRealExportToPassIsNotFlagged(): void
    {
        $old = $this->doc(['divi/a' => 'needs-real-export']);
        $new = $this->doc(['divi/a' => 'pass']);
        $this->assertSame(['missing' => [], 'demoted' => []], EvidenceGuard::lostModules($old, $new));
    }

    public function testNonPassModuleDroppedIsStillMissingButNeverDemoted(): void
    {
        $old = $this->doc(['divi/a' => 'needs-real-export']);
        $this->assertSame(['missing' => ['divi/a'], 'demoted' => []], EvidenceGuard::lostModules($old, $this->doc([])));
    }

    public function testNewModulesInNewAreFine(): void
    {
        $old = $this->doc(['divi/a' => 'pass']);
        $new = $this->doc(['divi/a' => 'pass', 'divi/b' => 'fail', 'divi/c' => 'pass']);
        $this->assertSame(['missing' => [], 'demoted' => []], EvidenceGuard::lostModules($old, $new));
    }

    public function testDocumentWithoutResultsIsTreatedAsEmpty(): void
    {
        $old = $this->doc(['divi/a' => 'pass']);
        $this->assertSame(['missing' => ['divi/a'], 'demoted' => []], EvidenceGuard::lostModules($old, []));
        $this->assertSame(['missing' => [], 'demoted' => []], EvidenceGuard::lostModules([], $old));
    }
}
