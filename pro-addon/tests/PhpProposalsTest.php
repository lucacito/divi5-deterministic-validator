<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\PhpProposals;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/PhpProposals.php';

/**
 * Code proposals are stored inert for human review. This guards the store/list/
 * delete behaviour. (Execution is intentionally never part of this class.)
 */
class PhpProposalsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = [];
    }

    public function testAddStoresAndReturnsId(): void
    {
        $id = PhpProposals::add('My CPT', 'Registers a post type', '<?php // code');
        $this->assertNotEmpty($id);
        $this->assertSame(1, PhpProposals::count());
        $all = PhpProposals::all();
        $this->assertSame('My CPT', $all[0]['title']);
        $this->assertSame('<?php // code', $all[0]['code']);
    }

    public function testNewestFirst(): void
    {
        PhpProposals::add('First', '', 'a');
        PhpProposals::add('Second', '', 'b');
        $this->assertSame('Second', PhpProposals::all()[0]['title']);
    }

    public function testDeleteRemovesOne(): void
    {
        $a = PhpProposals::add('A', '', 'a');
        PhpProposals::add('B', '', 'b');
        PhpProposals::delete($a);
        $titles = array_column(PhpProposals::all(), 'title');
        $this->assertSame(['B'], $titles);
    }

    public function testEmptyTitleFallsBack(): void
    {
        PhpProposals::add('', '', 'code');
        $this->assertSame('Untitled snippet', PhpProposals::all()[0]['title']);
    }
}
