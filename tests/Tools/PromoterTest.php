<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Tools;

use Divi5Validator\Tools\Promoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PromoterTest extends TestCase
{
    private function proposals(): array
    {
        return [
            ['name' => 'divi/portfolio', 'category' => 'module', 'children' => [], 'isChild' => false],
            ['name' => 'divi/video-slider', 'category' => 'module', 'children' => ['divi/video-slider-item'], 'isChild' => false],
            ['name' => 'divi/video-slider-item', 'category' => 'child-module', 'children' => [], 'isChild' => true],
            ['name' => 'divi/gravity-forms', 'category' => 'module', 'children' => [], 'isChild' => false],
            ['name' => 'divi/broken', 'category' => 'module', 'children' => [], 'isChild' => false],
            ['name' => 'divi/fullwidth-image', 'category' => 'fullwidth-module', 'children' => [], 'isChild' => false],
        ];
    }

    private function results(): array
    {
        return [
            'divi/portfolio'       => ['status' => 'pass', 'placements_rendered' => ['column']],
            'divi/video-slider'    => ['status' => 'pass', 'placements_rendered' => ['column']],
            'divi/gravity-forms'   => ['status' => 'needs-real-export', 'placements_rendered' => []],
            'divi/broken'          => ['status' => 'fail', 'placements_rendered' => []],
            'divi/fullwidth-image' => ['status' => 'pass', 'placements_rendered' => ['column', 'section']],
        ];
    }

    public function testOnlyPassingModulesArePromoted(): void
    {
        $sets = Promoter::promote($this->proposals(), $this->results());
        $all  = array_merge($sets['leaf'], $sets['structural']);
        $this->assertNotContains('divi/gravity-forms', $all);
        $this->assertNotContains('divi/broken', $all);
        $this->assertContains('divi/portfolio', $sets['leaf']);
    }

    public function testParentChildRelationshipIsCarried(): void
    {
        $sets = Promoter::promote($this->proposals(), $this->results());
        $this->assertContains('divi/video-slider', $sets['structural']);
        $this->assertSame(['divi/video-slider-item'], $sets['children']['divi/video-slider']);
        $this->assertContains('divi/video-slider-item', $sets['leaf']);
    }

    public function testChildItemsAreNeverPlacedInColumnOrSection(): void
    {
        $sets = Promoter::promote($this->proposals(), $this->results());
        $this->assertNotContains('divi/video-slider-item', $sets['columnChildren']);
        $this->assertNotContains('divi/video-slider-item', $sets['sectionChildren']);
    }

    public function testPlacementsFollowTheEvidence(): void
    {
        $sets = Promoter::promote($this->proposals(), $this->results());
        // Rendering cannot discriminate placement (a module that renders in a column also
        // renders directly in a section), so only the column placement — the one real-export
        // precedent (shop, fullwidth-header) supports — is promoted. Section stays empty until a
        // real builder export proves it.
        $this->assertContains('divi/fullwidth-image', $sets['columnChildren']);
        $this->assertContains('divi/portfolio', $sets['columnChildren']);
        $this->assertSame([], $sets['sectionChildren']);
    }

    public function testOutputIsSortedAndStable(): void
    {
        $a = Promoter::render(Promoter::promote($this->proposals(), $this->results()), '5.14.0');
        $b = Promoter::render(Promoter::promote(array_reverse($this->proposals()), $this->results()), '5.14.0');
        $this->assertSame($a, $b);
    }

    public function testRenderedSourceIsValidPhp(): void
    {
        $src = Promoter::render(Promoter::promote($this->proposals(), $this->results()), '5.14.0');
        $tmp = tempnam(sys_get_temp_dir(), 'vm') . '.php';
        file_put_contents($tmp, $src);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        unlink($tmp);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertStringContainsString("const DIVI_VERSION = '5.14.0';", $src);
    }

    /** @return array<string, array{string}> */
    public static function badNames(): array
    {
        return ["quote" => ["divi/x'y"], 'traversal' => ['divi/../../x'], 'namespace' => ['other/thing'], 'trailing newline' => ["divi/x\n"]];
    }

    #[DataProvider('badNames')]
    public function testBadModuleNamesThrow(string $bad): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($bad);
        Promoter::promote([['name' => $bad, 'category' => 'module', 'children' => [], 'isChild' => false]], [$bad => ['status' => 'pass', 'placements_rendered' => ['column']]]);
    }

    #[DataProvider('badNames')]
    public function testBadChildNamesThrow(string $bad): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($bad);
        Promoter::promote([['name' => 'divi/ok', 'category' => 'module', 'children' => [$bad], 'isChild' => false]], ['divi/ok' => ['status' => 'pass', 'placements_rendered' => ['column']]]);
    }

    #[DataProvider('badNames')]
    public function testRenderRejectsBadNames(string $bad): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Promoter::render(['leaf' => [$bad], 'structural' => [], 'columnChildren' => [], 'sectionChildren' => [], 'children' => []], '5.14.0');
    }
}
