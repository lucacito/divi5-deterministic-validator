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

    private function child(string $name, string $category = 'child-module'): array
    {
        return ['name' => $name, 'category' => $category, 'isChild' => true, 'children' => []];
    }

    private function parent(string $name, array $children): array
    {
        return ['name' => $name, 'category' => 'module', 'isChild' => false, 'children' => $children];
    }

    public function testUnknownChildOfAKnownParentIsAChildCandidateCarryingItsParent(): void
    {
        // divi/signup is a hand-written known module; the child name is made up so it stays unknown.
        $out = SchemaGap::childCandidates(
            [$this->parent('divi/signup', ['divi/signup-zzz-xyz']), $this->child('divi/signup-zzz-xyz')],
            new SchemaRules()
        );
        $this->assertSame(['divi/signup-zzz-xyz'], $this->names($out));
        $this->assertSame('divi/signup', $out[0]['parent']);
        $this->assertSame([0], array_keys($out));
    }

    public function testChildOfAnUnknownParentIsNotAChildCandidate(): void
    {
        // An unknown parent is probed as a candidate itself, together with its first child.
        $out = SchemaGap::childCandidates(
            [$this->parent('divi/not-a-real-parent-xyz', ['divi/not-a-real-kid-xyz']), $this->child('divi/not-a-real-kid-xyz')],
            new SchemaRules()
        );
        $this->assertSame([], $out);
    }

    public function testHandWrittenKnownChildIsNotAChildCandidate(): void
    {
        $out = SchemaGap::childCandidates(
            [$this->parent('divi/accordion', ['divi/accordion-item']), $this->child('divi/accordion-item')],
            new SchemaRules()
        );
        $this->assertSame([], $out);
    }

    public function testChildOfAGeneratedStructuralParentIsNotAChildCandidate(): void
    {
        // Those children are already proven by their parent's own probe (parent + first child).
        foreach (VerifiedModules::CHILDREN as $parent => $kids) {
            $out = SchemaGap::childCandidates(
                [$this->parent($parent, [$kids[0], 'divi/zzz-extra-xyz']), $this->child('divi/zzz-extra-xyz')],
                new SchemaRules()
            );
            $this->assertSame([], $out, "$parent is probed with its first child already");
        }
        $this->addToAssertionCount(1);
    }

    public function testNonChildItemsAndOtherCategoriesAreNotChildCandidates(): void
    {
        $rules = new SchemaRules();
        $proposals = [
            $this->parent('divi/signup', ['divi/signup-zzz-xyz', 'divi/signup-yyy-xyz']),
            ['name' => 'divi/signup-zzz-xyz', 'category' => 'module', 'isChild' => false, 'children' => []],
            ['name' => 'divi/signup-yyy-xyz', 'category' => 'structure', 'isChild' => true, 'children' => []],
        ];
        $this->assertSame([], SchemaGap::childCandidates($proposals, $rules));
    }

    public function testCurrentlyPromotedChildrenOfKnownParentsAreReverified(): void
    {
        $rules = new SchemaRules();
        foreach (VerifiedModules::KNOWN_PARENT_CHILDREN as $parent => $kids) {
            $proposals = [$this->parent($parent, $kids)];
            foreach ($kids as $kid) {
                $proposals[] = $this->child($kid);
            }
            $this->assertSame($kids, $this->names(SchemaGap::childCandidates($proposals, $rules)), "$parent children are re-verified");
        }
        $this->addToAssertionCount(1);
    }
}
