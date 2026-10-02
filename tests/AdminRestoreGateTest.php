<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AdminPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/AdminPage.php';

/** The Dashboard "Restore" button uses the same "which post may the plugin touch" rule as the tools: pages only. */
class AdminRestoreGateTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__wp_caps']     = [ 'edit_post' => true ];
        foreach ( [ 5 => 'page', 6 => 'post', 201 => 'et_header_layout', 202 => 'et_footer_layout' ] as $id => $type ) {
            $p              = new \WP_Post();
            $p->ID          = $id;
            $p->post_type   = $type;
            $p->post_status = 'publish';
            $GLOBALS['__wp_posts'][ $id ] = $p;
        }
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can']   = true;
        $GLOBALS['__wp_posts'] = [];
    }

    public function testAPageMayBeRestored(): void
    {
        $this->assertTrue( AdminPage::mayRestore( 5 ) );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'neverEditable' )]
    public function testNothingElseMayBeRestored( int $id, string $why ): void
    {
        $this->assertFalse( AdminPage::mayRestore( $id ), $why );
    }

    /** @return list<array{int,string}> */
    public static function neverEditable(): array
    {
        return EditTargetTest::neverEditable();
    }

    public function testAPageTheUserCannotEditMayNotBeRestored(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => false ];
        $this->assertFalse( AdminPage::mayRestore( 5 ) );
    }
}
