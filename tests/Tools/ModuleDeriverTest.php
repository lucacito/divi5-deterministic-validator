<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Tools;

use Divi5Validator\SchemaRules;
use Divi5Validator\Tools\ModuleDeriver;
use Divi5Validator\Tools\SchemaGap;
use PHPUnit\Framework\TestCase;

class ModuleDeriverTest extends TestCase
{
    public function testDerivesNameCategoryAndChildren(): void
    {
        $proposals = ModuleDeriver::derive([
            'b/module.json' => ['name' => 'divi/video-slider', 'category' => 'module',
                'childModuleName' => 'divi/video-slider-item', 'childrenName' => ['divi/video-slider-item']],
            'a/module.json' => ['name' => 'divi/video-slider-item', 'category' => 'child-module'],
            'c/module.json' => ['name' => 'divi/portfolio', 'category' => 'module', 'childrenName' => []],
        ]);

        $this->assertSame(['divi/portfolio', 'divi/video-slider', 'divi/video-slider-item'], array_column($proposals, 'name'));
        $byName = array_column($proposals, null, 'name');
        $this->assertSame(['divi/video-slider-item'], $byName['divi/video-slider']['children']);
        $this->assertFalse($byName['divi/video-slider']['isChild']);
        $this->assertTrue($byName['divi/video-slider-item']['isChild']);
        $this->assertSame([], $byName['divi/portfolio']['children']);
    }

    public function testMissingCategoryIsNullNotAnError(): void
    {
        $proposals = ModuleDeriver::derive(['x/module.json' => ['name' => 'divi/thing']]);
        $this->assertNull($proposals[0]['category']);
    }

    public function testEntryWithoutValidNameAbortsLoudly(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('bad/module.json');
        ModuleDeriver::derive(['bad/module.json' => ['title' => 'no name here']]);
    }

    public function testNonDiviNameAbortsLoudly(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ModuleDeriver::derive(['bad/module.json' => ['name' => 'other/heading']]);
    }

    public function testGapSplitsKnownFromMissing(): void
    {
        $proposals = ModuleDeriver::derive([
            'a/module.json' => ['name' => 'divi/heading', 'category' => 'module'],
            'b/module.json' => ['name' => 'divi/zzz-brand-new', 'category' => 'module'],
        ]);
        $missing = SchemaGap::missing($proposals, new SchemaRules());
        $this->assertSame(['divi/zzz-brand-new'], array_column($missing, 'name'));
    }

    public function testUnmatchedListsKnownTypesWithNoDefinition(): void
    {
        $proposals = ModuleDeriver::derive(['a/module.json' => ['name' => 'divi/heading', 'category' => 'module']]);
        $unmatched = SchemaGap::unmatched($proposals, new SchemaRules());
        $this->assertContains('divi/placeholder', $unmatched);
        $this->assertNotContains('divi/heading', $unmatched);
    }
}
