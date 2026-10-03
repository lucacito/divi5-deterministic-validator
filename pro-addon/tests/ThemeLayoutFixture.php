<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\ThemeLayoutKind;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../tests/FakeWpdb.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

/**
 * A fake live Theme Builder (template 200 with header 201, footer 202 and body 203), an orphan header 204, a page 5,
 * the real free history on a fake wpdb, and an in-memory batch log. Use in a TestCase's setUp()/tearDown().
 */
trait ThemeLayoutFixture
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;

    private static function header(): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    /** A valid layout that differs from header(): a search module instead of a shop. */
    private static function otherHeader(): string
    {
        return str_replace( 'wp:divi/shop {"builderVersion":"5.14.0"} /', 'wp:divi/search {"builderVersion":"5.14.0"} /', self::header() );
    }

    private static function invalid(): string
    {
        return str_replace( 'wp:divi/shop', 'wp:divi/not-a-module', self::header() );
    }

    /** header() with a custom-CSS attribute on the shop module. */
    private static function withCss( string $css ): string
    {
        return str_replace( '{"builderVersion":"5.14.0"} /--><!-- /wp:divi/column', '{"builderVersion":"5.14.0","css":{"desktop":{"value":{"freeForm":"' . $css . '"}}}} /--><!-- /wp:divi/column', self::header() );
    }

    /** @param array<string,mixed> $meta */
    private function themePost( int $id, string $type, string $status = 'publish', array $meta = [], string $content = '' ): void
    {
        $p               = new \WP_Post();
        $p->ID           = $id;
        $p->post_type    = $type;
        $p->post_status  = $status;
        $p->post_title   = "Post {$id}";
        $p->post_content = $content;
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    private function setUpThemeBuilder(): void
    {
        $GLOBALS['wpdb'] = new \Divi5Validator\Tests\FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']        = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_posts']          = [];
        $GLOBALS['__wp_postmeta']       = [];
        $GLOBALS['__wp_update_fail']    = false;
        $GLOBALS['__wp_update_calls']   = 0;
        $GLOBALS['__divi_cache_clears'] = [];
        $GLOBALS['__divi_static_clears'] = [];
        $GLOBALS['__divi_tb']           = [ 'tb_id' => 100, 'draft_id' => 0, 'template_ids' => [ 200 ], 'draft_template_ids' => [] ];
        $GLOBALS['__divi_tb_calls']     = [];
        $GLOBALS['__wp_caps']           = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true, 'edit_theme_options' => true ];
        unset( $GLOBALS['__divi_role_allowed'] );

        $this->themePost( 100, 'et_theme_builder' );
        $this->themePost( 200, 'et_template', 'publish', [ '_et_default' => '0', '_et_enabled' => '1', '_et_header_layout_id' => 201, '_et_footer_layout_id' => 202, '_et_body_layout_id' => 203 ] );
        $this->themePost( 201, 'et_header_layout', 'publish', [], self::header() );
        $this->themePost( 202, 'et_footer_layout', 'publish', [], self::header() );
        $this->themePost( 203, 'et_body_layout', 'publish', [], self::header() );
        $this->themePost( 204, 'et_header_layout', 'publish', [], self::header() );   // orphan: no live template uses it
        $this->themePost( 5, 'page', 'publish', [], self::header() );

        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
    }

    private function tearDownThemeBuilder(): void
    {
        $GLOBALS['wpdb']         = null;
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__divi_tb']    = [];
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_role_allowed'] );
        $GLOBALS['__wp_can'] = true;
    }

    private function undoEngine(): BatchUndo
    {
        return new BatchUndo( [ ThemeLayoutKind::NAME => new ThemeLayoutKind() ], $this->log );
    }

    private function layoutContent( int $id ): string
    {
        return $GLOBALS['__wp_posts'][ $id ]->post_content;
    }

    private function historyRows( int $id ): int
    {
        return count( HistoryStore::summaries( $id ) );
    }
}
