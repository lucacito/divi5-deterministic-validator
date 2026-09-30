<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Tools;

use Divi5Validator\Tools\MarkupBuilder;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

class MarkupBuilderTest extends TestCase
{
    public function testColumnPlacementProducesValidMarkupForAKnownLeaf(): void
    {
        $result = (new Validator())->validateContent(MarkupBuilder::page('divi/shop', MarkupBuilder::PLACEMENT_COLUMN));
        $this->assertTrue($result->isValid(), $this->dump($result));
    }

    public function testParentWithChildIsValidForKnownCompoundModules(): void
    {
        foreach ([['divi/accordion', 'divi/accordion-item'], ['divi/tabs', 'divi/tab']] as [$parent, $child]) {
            $result = (new Validator())->validateContent(MarkupBuilder::page($parent, MarkupBuilder::PLACEMENT_COLUMN, $child));
            $this->assertTrue($result->isValid(), "$parent: " . $this->dump($result));
        }
    }

    public function testSectionPlacementPutsTheModuleDirectlyInTheSection(): void
    {
        $markup = MarkupBuilder::page('divi/shop', MarkupBuilder::PLACEMENT_SECTION);
        $this->assertStringNotContainsString('wp:divi/column', $markup);
        $this->assertStringContainsString('wp:divi/shop', $markup);
        // The builder is placement-neutral: the validator (not the builder) rejects this today.
        $this->assertFalse((new Validator())->validateContent($markup)->isValid());
    }

    public function testVersionIsStampedOnEveryBlock(): void
    {
        $markup = MarkupBuilder::page('divi/shop', MarkupBuilder::PLACEMENT_COLUMN, null, '9.9.9');
        $this->assertSame(0, preg_match('/5\.14\.0/', $markup));
        $this->assertGreaterThanOrEqual(4, substr_count($markup, '"builderVersion":"9.9.9"'));
    }

    public function testUnknownPlacementThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MarkupBuilder::page('divi/shop', 'footer');
    }

    private function dump(\Divi5Validator\ValidationResult $r): string
    {
        return implode('; ', array_map(fn ($v) => $v->code() . ': ' . $v->message(), $r->violations()));
    }
}
