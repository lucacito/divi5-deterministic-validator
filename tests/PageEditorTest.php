<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\PageEditor;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';

/**
 * PageEditor is the pure engine behind the surgical edit_page_content tool:
 * an exact find/replace that lets an AI change one thing on a page WITHOUT
 * re-emitting the whole Divi layout (which risks corrupting unrelated blocks).
 *
 * Semantics mirror a code editor's exact-string replace: `find` must identify a
 * unique spot unless the caller states, via expect_count, how many it expects.
 * Anything ambiguous or not-found leaves content untouched — fail safe.
 */
class PageEditorTest extends TestCase
{
    public function testReplacesAUniqueMatch(): void
    {
        $out = PageEditor::apply('Contact us at hello@old.com today.', 'hello@old.com', 'hello@new.com');

        $this->assertTrue($out['ok']);
        $this->assertSame('Contact us at hello@new.com today.', $out['content']);
        $this->assertSame(1, $out['count']);
        $this->assertSame('', $out['error']);
    }

    public function testLeavesContentUnchangedWhenNotFound(): void
    {
        $out = PageEditor::apply('The quick brown fox', 'cat', 'dog');

        $this->assertFalse($out['ok']);
        $this->assertSame('The quick brown fox', $out['content']);
        $this->assertSame(0, $out['count']);
        $this->assertNotSame('', $out['error']);
    }

    public function testRefusesAmbiguousMatchWithoutExpectCount(): void
    {
        // "info@x.com" appears twice — replacing blindly could hit the wrong one.
        $content = 'a info@x.com b info@x.com c';
        $out = PageEditor::apply($content, 'info@x.com', 'info@y.com');

        $this->assertFalse($out['ok']);
        $this->assertSame($content, $out['content'], 'ambiguous edit must not mutate content');
        $this->assertSame(2, $out['count']);
        $this->assertStringContainsString('2', $out['error']);
    }

    public function testReplacesAllWhenExpectCountMatches(): void
    {
        $out = PageEditor::apply('a info@x.com b info@x.com c', 'info@x.com', 'info@y.com', 2);

        $this->assertTrue($out['ok']);
        $this->assertSame('a info@y.com b info@y.com c', $out['content']);
        $this->assertSame(2, $out['count']);
    }

    public function testErrorsWhenExpectCountDoesNotMatchActual(): void
    {
        $out = PageEditor::apply('only one info@x.com here', 'info@x.com', 'info@y.com', 3);

        $this->assertFalse($out['ok']);
        $this->assertSame('only one info@x.com here', $out['content']);
        $this->assertSame(1, $out['count']);
    }

    public function testRejectsEmptyFind(): void
    {
        $out = PageEditor::apply('anything', '', 'x');

        $this->assertFalse($out['ok']);
        $this->assertSame('anything', $out['content']);
    }

    public function testRejectsIdenticalFindAndReplace(): void
    {
        $out = PageEditor::apply('no-op edit', 'no-op', 'no-op');

        $this->assertFalse($out['ok']);
        $this->assertSame('no-op edit', $out['content']);
    }

    public function testHandlesDiviBlockMarkupVerbatim(): void
    {
        // The engine must treat block markup as opaque text — no HTML awareness,
        // exact bytes only, so escaped Divi content round-trips untouched.
        $content = '<!-- wp:divi/text --><div>Call 555-0100 now</div><!-- /wp:divi/text -->';
        $out = PageEditor::apply($content, '555-0100', '555-0199');

        $this->assertTrue($out['ok']);
        $this->assertSame(
            '<!-- wp:divi/text --><div>Call 555-0199 now</div><!-- /wp:divi/text -->',
            $out['content']
        );
    }
}
