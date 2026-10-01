<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AdminPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';
require_once __DIR__ . '/../wp-plugin/src/ThemeLayouts.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/AdminPage.php';

/** The Dashboard "Restore" button uses the same "which post may the plugin touch" rule as the tools. */
class AdminRestoreGateTest extends TestCase
{
    /** @param array<string,mixed> $meta */
    private function post( int $id, string $type, string $status = 'publish', array $meta = [] ): void
    {
        $p              = new \WP_Post();
        $p->ID          = $id;
        $p->post_type   = $type;
        $p->post_status = $status;
        $p->post_title  = "Post {$id}";
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    protected function setUp(): void
    {
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [ 'tb_id' => 100, 'draft_id' => 300, 'template_ids' => [ 200 ], 'draft_template_ids' => [ 301 ] ];
        $GLOBALS['__wp_caps']     = [ 'edit_post' => true, 'edit_others_posts' => true ];

        $this->post( 100, 'et_theme_builder' );
        $this->post( 200, 'et_template', 'publish', [ '_et_header_layout_id' => 201, '_et_footer_layout_id' => 202, '_et_body_layout_id' => 203 ] );
        $this->post( 201, 'et_header_layout' );
        $this->post( 202, 'et_footer_layout' );
        $this->post( 203, 'et_body_layout' );
        $this->post( 204, 'et_header_layout' );
        $this->post( 300, 'et_theme_builder', 'auto-draft' );
        $this->post( 301, 'et_template', 'publish', [ '_et_header_layout_id' => 302 ] );
        $this->post( 302, 'et_header_layout' );
        $this->post( 5, 'page' );
        $this->post( 6, 'post' );
        $this->post( 7, 'attachment' );
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_role_allowed'] );
        $GLOBALS['__wp_can']      = true;
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [];
    }

    public function testAPageAndALiveHeaderAndFooterMayBeRestored(): void
    {
        $this->assertTrue( AdminPage::mayRestore( 5 ) );
        $this->assertTrue( AdminPage::mayRestore( 201 ) );
        $this->assertTrue( AdminPage::mayRestore( 202 ) );
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

    public function testALayoutNeedsEditOthersPostsToBeRestored(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => false ];
        $this->assertFalse( AdminPage::mayRestore( 201 ) );
        $this->assertTrue( AdminPage::mayRestore( 5 ), 'a page never needed edit_others_posts' );
    }

    public function testHistoryRowTitleCarriesTheHeaderAndFooterPrefixOnly(): void
    {
        $this->assertSame( 'Header: Main', AdminPage::historyRowTitle( [ 'title' => 'Main', 'kind' => 'header' ] ) );
        $this->assertSame( 'Footer: Main', AdminPage::historyRowTitle( [ 'title' => 'Main', 'kind' => 'footer' ] ) );
        $this->assertSame( 'About', AdminPage::historyRowTitle( [ 'title' => 'About', 'kind' => 'page' ] ) );
        $this->assertSame( '(no title)', AdminPage::historyRowTitle( [ 'title' => '', 'kind' => 'page' ] ) );
        $this->assertSame( 'Header: (no title)', AdminPage::historyRowTitle( [ 'title' => '', 'kind' => 'header' ] ) );
    }
}
