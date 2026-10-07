<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\SchemaRules;
use Divi5Validator\Tools\MarkupBuilder;
use Divi5Validator\Validator;
use Divi5Validator\VerifiedModules;
use PHPUnit\Framework\TestCase;

/** Every generated entry must behave exactly as its evidence claims. */
class VerifiedModulesTest extends TestCase
{
    public function testPromotedModulesValidateInTheirVerifiedPlacements(): void
    {
        $v = new Validator();
        foreach ([MarkupBuilder::PLACEMENT_COLUMN => VerifiedModules::COLUMN_CHILDREN, MarkupBuilder::PLACEMENT_SECTION => VerifiedModules::SECTION_CHILDREN] as $placement => $modules) {
            foreach ($modules as $m) {
                $child  = VerifiedModules::CHILDREN[$m][0] ?? null;
                $result = $v->validateContent(MarkupBuilder::page($m, $placement, $child, VerifiedModules::DIVI_VERSION));
                $this->assertTrue($result->isValid(), "$m in $placement: " . implode('; ', array_map(fn ($x) => $x->code(), $result->violations())));
            }
        }
        $this->addToAssertionCount(1); // valid even when nothing is promoted yet
    }

    public function testChildItemsAreRejectedAlone(): void
    {
        $v = new Validator();
        foreach (VerifiedModules::CHILDREN as $kids) {
            foreach ($kids as $kid) {
                $result = $v->validateContent(MarkupBuilder::page($kid, MarkupBuilder::PLACEMENT_COLUMN, null, VerifiedModules::DIVI_VERSION));
                $this->assertFalse($result->isValid(), "$kid must not be valid outside its parent");
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testParentsRejectAForeignChild(): void
    {
        $v = new Validator();
        foreach (array_keys(VerifiedModules::CHILDREN) as $parent) {
            $result = $v->validateContent(MarkupBuilder::page($parent, MarkupBuilder::PLACEMENT_COLUMN, 'divi/heading', VerifiedModules::DIVI_VERSION));
            $this->assertFalse($result->isValid(), "$parent must reject a divi/heading child");
        }
        $this->addToAssertionCount(1);
    }

    public function testStructuralBlocksHaveNoDuplicates(): void
    {
        $this->assertSame(count(SchemaRules::STRUCTURAL_BLOCKS), count(array_unique(SchemaRules::STRUCTURAL_BLOCKS)), 'STRUCTURAL_BLOCKS has duplicate entries');
    }

    public function testNoNameIsBothStructuralAndLeaf(): void
    {
        // LEAF_MODULES may hold harmless duplicates (e.g. divi/slide shared by two parents), so
        // only cross-category overlap is asserted.
        $both = array_values(array_intersect(SchemaRules::STRUCTURAL_BLOCKS, SchemaRules::LEAF_MODULES));
        $this->assertSame([], $both, 'Names classified as both structural and leaf: ' . implode(', ', $both));
    }

    public function testGeneratedParentsAreStructuralAndHaveChildRules(): void
    {
        foreach (array_keys(VerifiedModules::CHILDREN) as $parent) {
            $this->assertContains($parent, VerifiedModules::STRUCTURAL, "$parent has children but is not in VerifiedModules::STRUCTURAL");
        }
        foreach (VerifiedModules::STRUCTURAL as $parent) {
            $this->assertArrayHasKey($parent, SchemaRules::ALLOWED_CHILDREN, "$parent is structural but has no ALLOWED_CHILDREN entry");
        }
        $this->addToAssertionCount(1);
    }

    public function testGeneratedParentsNeverOverwriteHandWrittenKeys(): void
    {
        // 15 = hand-written parent keys in SchemaRules::ALLOWED_CHILDREN today. If you hand-add a
        // parent there, bump this number deliberately. This guard exists so a generated parent can
        // never silently overwrite a hand-written key (the ...VerifiedModules::CHILDREN spread).
        $this->assertSame(15 + count(VerifiedModules::CHILDREN) + count(VerifiedModules::KNOWN_PARENT_CHILDREN), count(SchemaRules::ALLOWED_CHILDREN));
    }

    public function testVerdictIsDeterministic(): void
    {
        $markup = MarkupBuilder::page('divi/shop', MarkupBuilder::PLACEMENT_COLUMN);
        $v = new Validator();
        $this->assertEquals($v->validateContent($markup)->violations(), $v->validateContent($markup)->violations());
    }

    public function testKnownParentChildrenValidateInsideTheirParentOnly(): void
    {
        $v = new Validator();
        $rules = new SchemaRules();
        foreach (VerifiedModules::KNOWN_PARENT_CHILDREN as $parent => $kids) {
            $this->assertArrayNotHasKey($parent, VerifiedModules::CHILDREN, "$parent is hand-written, not a generated parent");
            $this->assertTrue($rules->isKnownType($parent), "$parent is a known module");
            foreach ($kids as $kid) {
                $inside = $v->validateContent(MarkupBuilder::page($parent, MarkupBuilder::PLACEMENT_COLUMN, $kid, VerifiedModules::DIVI_VERSION));
                $this->assertTrue($inside->isValid(), "$kid inside $parent: " . implode('; ', array_map(fn ($x) => $x->code(), $inside->violations())));
                $alone = $v->validateContent(MarkupBuilder::page($kid, MarkupBuilder::PLACEMENT_COLUMN, null, VerifiedModules::DIVI_VERSION));
                $this->assertFalse($alone->isValid(), "$kid must not be valid outside its parent");
            }
            $foreign = $v->validateContent(MarkupBuilder::page($parent, MarkupBuilder::PLACEMENT_COLUMN, 'divi/heading', VerifiedModules::DIVI_VERSION));
            $this->assertFalse($foreign->isValid(), "$parent must reject a divi/heading child");
        }
        $this->addToAssertionCount(1);
    }

    public function testEmailOptinCustomFieldsAreAccepted(): void
    {
        // A real Divi 5.14 Email Optin with custom fields stores them as child blocks (a user's live homepage
        // was refused by the gate because of exactly this). Render evidence: docs/module-verification-5.14.json.
        $v = new Validator();
        $markup = MarkupBuilder::page('divi/signup', MarkupBuilder::PLACEMENT_COLUMN, 'divi/signup-custom-field', '5.14.0');
        $this->assertTrue($v->validateContent($markup)->isValid());
    }
}
