<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Divi 5.15 Components. Every accepted shape here comes from a REAL builder export on Divi 5.15.1
 * (fixtures/valid/component-*-5.15.json, docs/components-evidence-5.15.md); what the exports do not show stays refused.
 */
class ComponentsSchemaTest extends TestCase
{
    private const V = '5.15.1';

    private function content(string $name): string
    {
        $fx = json_decode((string) file_get_contents(dirname(__DIR__) . "/fixtures/valid/{$name}-5.15.json"), true);

        return (string) $fx['post_content'];
    }

    /** @return list<string> */
    private function codes(string $postContent): array
    {
        return array_map(static fn ($v) => $v->code(), (new Validator())->validateContent($postContent)->violations());
    }

    private function page(string $inColumn): string
    {
        $v = self::V;

        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"' . $v . '"} --><!-- wp:divi/row {"builderVersion":"' . $v . '"} -->'
            . '<!-- wp:divi/column {"builderVersion":"' . $v . '"} -->' . $inColumn . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    private function slot(string $attrs, string $inner = ''): string
    {
        return '<!-- wp:divi/global-slot ' . $attrs . ' -->' . $inner . '<!-- /wp:divi/global-slot -->';
    }

    private const SLOT_OK = '{"builderVersion":"5.15.1","slotId":"18e7a400-ee42-4cf5-808c-e8db870de7ce","localAttrOverridePaths":[]}';

    public function testEveryRealComponentExportIsValid(): void
    {
        foreach ([ 'component-instance-plain', 'component-instance-row', 'component-instance-module', 'component-instance-slot-empty', 'component-instance-slot-filled', 'component-nested' ] as $name) {
            $this->assertSame( [], $this->codes($this->content($name)), "{$name} passes" );
        }
    }

    public function testASlotNeedsASlotIdBecauseDiviIgnoresOneWithoutIt(): void
    {
        $this->assertSame([], $this->codes($this->page($this->slot(self::SLOT_OK))));
        $this->assertContains('MISSING_REQUIRED_FIELD', $this->codes($this->page($this->slot('{"builderVersion":"5.15.1"}'))));
        $this->assertContains('MISSING_REQUIRED_FIELD', $this->codes($this->page($this->slot('{"builderVersion":"5.15.1","slotId":""}'))));
        $this->assertContains('MISSING_REQUIRED_FIELD', $this->codes($this->page($this->slot('{"builderVersion":"5.15.1","slotId":7}'))));
    }

    public function testASlotHoldsModulesButNotRowsOrAnotherSlot(): void
    {
        $button = '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Hi"}}}},"builderVersion":"5.15.1"} /-->';
        $this->assertSame([], $this->codes($this->page($this->slot(self::SLOT_OK, $button))), 'a module (proven in the builder)');

        $row = '<!-- wp:divi/row {"builderVersion":"5.15.1"} --><!-- wp:divi/column {"builderVersion":"5.15.1"} --><!-- /wp:divi/column --><!-- /wp:divi/row -->';
        $this->assertContains('UNEXPECTED_CHILD_TYPE', $this->codes($this->page($this->slot(self::SLOT_OK, $row))), 'a row in a slot is not proven');

        $inner = $this->slot('{"builderVersion":"5.15.1","slotId":"other"}');
        $this->assertContains('UNEXPECTED_CHILD_TYPE', $this->codes($this->page($this->slot(self::SLOT_OK, $inner))), 'a slot in a slot is not proven');
    }

    public function testASlotBelongsInAColumnNotDirectlyInARowOrASection(): void
    {
        $v   = self::V;
        $row = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"' . $v . '"} --><!-- wp:divi/row {"builderVersion":"' . $v . '"} -->'
            . $this->slot(self::SLOT_OK) . '<!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
        $this->assertContains('UNEXPECTED_CHILD_TYPE', $this->codes($row));

        $section = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"' . $v . '"} -->' . $this->slot(self::SLOT_OK) . '<!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
        $this->assertContains('UNEXPECTED_CHILD_TYPE', $this->codes($section));
    }

    public function testAModuleComponentSitsInAColumnButNotDirectlyInARow(): void
    {
        $inst = '<!-- wp:divi/global-layout {"globalModule":"1","blockName":"divi/heading","builderVersion":"5.15.1","localAttrs":{},"localAttrOverridePaths":[]} /-->';
        $this->assertSame([], $this->codes($this->page($inst)));

        $v   = self::V;
        $row = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"' . $v . '"} --><!-- wp:divi/row {"builderVersion":"' . $v . '"} -->' . $inst . '<!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
        $this->assertContains('UNEXPECTED_CHILD_TYPE', $this->codes($row), 'a column component in a row is not proven');
    }
}
