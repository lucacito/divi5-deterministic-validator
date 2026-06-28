<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for structures confirmed on a real production Divi 5 site
 * (supportmy.website — 25 pages). These patterns previously failed validation;
 * they must stay valid. Negative cases guard against over-loosening.
 */
class LiveModulesTest extends TestCase
{
    private function valid(string $postContent): bool
    {
        return (new Validator())->validateContent($postContent)->isValid();
    }

    private function bv(string $name, string $inner = ''): string
    {
        return $inner === ''
            ? "<!-- wp:$name {\"builderVersion\":\"5.8.0\"} /-->"
            : "<!-- wp:$name {\"builderVersion\":\"5.8.0\"} -->$inner<!-- /wp:$name -->";
    }

    /** section > row > column wrapping the given module markup. */
    private function inColumn(string $modules): string
    {
        return $this->bv('divi/section', $this->bv('divi/row', $this->bv('divi/column', $modules)));
    }

    public function testTopLevelSectionsWithoutPlaceholderWrapper(): void
    {
        // Real pages place sections directly at the top — no divi/placeholder.
        $heading = '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Hi"}}},"builderVersion":"5.8.0"} /-->';
        $this->assertTrue($this->valid($this->inColumn($heading)));
    }

    public function testGlobalLayoutAtTopLevelWithoutBuilderVersion(): void
    {
        // Theme Builder global reference: top-level, no builderVersion.
        $pc = '<!-- wp:divi/global-layout {"globalModule":"123","blockName":"divi/section","localAttrs":{}} /-->';
        $this->assertTrue($this->valid($pc));
    }

    public function testGroupCarouselWithGroups(): void
    {
        $img   = '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"https://picsum.photos/seed/x/800/600"}}}},"builderVersion":"5.8.0"} /-->';
        $group = $this->bv('divi/group', $img);
        $this->assertTrue($this->valid($this->inColumn($this->bv('divi/group-carousel', $group))));
    }

    public function testGroupActsLikeColumnContainer(): void
    {
        $heading = '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"In a group"}}},"builderVersion":"5.8.0"} /-->';
        $this->assertTrue($this->valid($this->inColumn($this->bv('divi/group', $heading))));
    }

    public function testCodeSidebarTestimonialInColumn(): void
    {
        $this->assertTrue($this->valid($this->inColumn($this->bv('divi/code'))));
        $this->assertTrue($this->valid($this->inColumn($this->bv('divi/sidebar'))));
        $this->assertTrue($this->valid($this->inColumn($this->bv('divi/testimonial'))));
    }

    public function testTextCanWrapNestedText(): void
    {
        $inner = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>a</p>"}}},"builderVersion":"5.8.0"} /-->';
        $outer = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>b</p>"}}},"builderVersion":"5.8.0"} -->' . $inner . '<!-- /wp:divi/text -->';
        $this->assertTrue($this->valid($this->inColumn($outer)));
    }

    private function h(string $text, string $level): string
    {
        return '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $text . '"}},"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"' . $level . '"}}}}}},"builderVersion":"5.8.0"} /-->';
    }

    public function testSideBySideButtonsValidate(): void
    {
        // Hero CTA pattern: a nested row > flex-row column wrapping two buttons.
        $b = fn(string $t) => '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"' . $t . '"}}}},"builderVersion":"5.8.0"} /-->';
        $flexCol = '<!-- wp:divi/column {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"flex","flexDirection":"row"}}}}},"builderVersion":"5.8.0"} -->' . $b('A') . $b('B') . '<!-- /wp:divi/column -->';
        $nestedRow = '<!-- wp:divi/row {"builderVersion":"5.8.0"} -->' . $flexCol . '<!-- /wp:divi/row -->';
        $this->assertTrue($this->valid($this->inColumn($nestedRow)));
    }

    public function testNumberCounterValidates(): void
    {
        $nc = '<!-- wp:divi/number-counter {"title":{"innerContent":{"desktop":{"value":"Tasks Automated"}}},"number":{"innerContent":{"desktop":{"value":"250000"}}},"builderVersion":"5.8.0"} /-->';
        $this->assertTrue($this->valid($this->inColumn($nc)));
    }

    public function testSingleH1IsAllowed(): void
    {
        $this->assertTrue($this->valid($this->inColumn($this->h('The One Headline', 'h1') . $this->h('A subheading', 'h2'))));
    }

    public function testMultipleH1IsRejected(): void
    {
        $result = (new Validator())->validateContent($this->inColumn($this->h('First', 'h1') . $this->h('Second', 'h1')));
        $this->assertFalse($result->isValid());
        $codes = array_map(fn($v) => $v->toArray()['code'], $result->violations());
        $this->assertContains(Validator::E_MULTIPLE_H1, $codes);
    }

    // --- negatives: we did not over-loosen -------------------------------

    public function testOtherLeafWithChildrenStillRejected(): void
    {
        // divi/image is still a strict leaf — children are invalid.
        $img = '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"x"}}}},"builderVersion":"5.8.0"} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>x</p>"}}},"builderVersion":"5.8.0"} /--><!-- /wp:divi/image -->';
        $this->assertFalse($this->valid($this->inColumn($img)));
    }

    public function testUnknownTopLevelBlockStillRejected(): void
    {
        $this->assertFalse($this->valid('<!-- wp:divi/totally-made-up {"builderVersion":"5.8.0"} /-->'));
    }
}
