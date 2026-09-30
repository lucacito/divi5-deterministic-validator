<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

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

    public function testVerdictIsDeterministic(): void
    {
        $markup = MarkupBuilder::page('divi/shop', MarkupBuilder::PLACEMENT_COLUMN);
        $v = new Validator();
        $this->assertEquals($v->validateContent($markup)->violations(), $v->validateContent($markup)->violations());
    }
}
