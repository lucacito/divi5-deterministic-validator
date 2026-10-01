<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Tools;

use Divi5Validator\SchemaRules;
use Divi5Validator\Tools\SchemaGap;
use Divi5Validator\VerifiedModules;
use PHPUnit\Framework\TestCase;

class SchemaGapTest extends TestCase
{
    private function proposal(string $name, ?string $category = 'module', bool $isChild = false): array
    {
        return ['name' => $name, 'category' => $category, 'isChild' => $isChild, 'children' => []];
    }

    private function names(array $proposals): array
    {
        return array_column($proposals, 'name');
    }

    public function testUnknownPlaceableModuleIsACandidate(): void
    {
        $out = SchemaGap::candidates([$this->proposal('divi/not-a-real-module-xyz')], new SchemaRules());
        $this->assertSame(['divi/not-a-real-module-xyz'], $this->names($out));
    }

    public function testFullwidthModuleCategoryIsACandidate(): void
    {
        $out = SchemaGap::candidates([$this->proposal('divi/fullwidth-not-real-xyz', 'fullwidth-module')], new SchemaRules());
        $this->assertCount(1, $out);
    }

    public function testCurrentlyPromotedModulesAreStillCandidatesSoTheyAreReverified(): void
    {
        $promoted = array_values(array_unique(array_merge(
            VerifiedModules::COLUMN_CHILDREN,
            VerifiedModules::SECTION_CHILDREN
        )));
        $rules = new SchemaRules();
        // Registers an assertion even if the generated constants are ever empty.
        $this->assertSame(
            $promoted,
            $this->names(SchemaGap::candidates(array_map(fn (string $n): array => $this->proposal($n), $promoted), $rules))
        );
        if ($promoted !== []) {
            $this->assertTrue($rules->isKnownType($promoted[0]), 'promoted modules are known to the validator');
        }
    }

    public function testHandWrittenKnownModuleIsNotACandidate(): void
    {
        $out = SchemaGap::candidates([$this->proposal('divi/heading')], new SchemaRules());
        $this->assertSame([], $out);
    }

    public function testChildItemIsNotACandidate(): void
    {
        $out = SchemaGap::candidates([$this->proposal('divi/not-a-real-item-xyz', 'child-module', true)], new SchemaRules());
        $this->assertSame([], $out);
        $out = SchemaGap::candidates([$this->proposal('divi/not-a-real-item-xyz', 'module', true)], new SchemaRules());
        $this->assertSame([], $out);
    }

    public function testStructureOrNullCategoryIsNotACandidate(): void
    {
        $rules = new SchemaRules();
        $this->assertSame([], SchemaGap::candidates([$this->proposal('divi/not-real-a-xyz', 'structure')], $rules));
        $this->assertSame([], SchemaGap::candidates([$this->proposal('divi/not-real-b-xyz', null)], $rules));
    }

    public function testOutputIsReindexedAndKeepsOrder(): void
    {
        $out = SchemaGap::candidates([
            $this->proposal('divi/heading'),
            $this->proposal('divi/zzz-new-xyz'),
            $this->proposal('divi/aaa-new-xyz'),
        ], new SchemaRules());
        $this->assertSame(['divi/zzz-new-xyz', 'divi/aaa-new-xyz'], $this->names($out));
        $this->assertSame([0, 1], array_keys($out));
    }
}
