<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Compat;
use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\DesignSwitch;
use AiEditorDivi5\Pro\Design\MarkerRecovery;
use AiEditorDivi5\Pro\Design\SwitchResult;
use AiEditorDivi5\Pro\Kinds\PostContentKind;
use AiEditorDivi5\Pro\Rest\ProOpenApi;
use AiEditorDivi5\Pro\Rest\ProRest;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools\SwitchDesign;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\PageHistory;
use AiEditorDivi5\WP\SectionRecipes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';
require_once __DIR__ . '/WpSiteShims.php';

/**
 * Design switch, Task 8: the `switch_design` tool around the engine (spec 2026-10-25-pro-design-switch-design.md
 * section 7): strict arguments, the free-version guard, per-page targets and skips, the preview token, the gate, one
 * batch of `post_content` items written through the free history, undo, and MCP/REST parity.
 */
class SwitchDesignTest extends TestCase
{
    use ThemeLayoutFixture;

    private const RECIPES = [ 'pricing-three-tier', 'faq-accordion' ];

    private SwitchDesign $tool;
    private int $now = 1000000;

    /** A page of the recipes as the free plugin serves them under a design (image tokens resolved as outside WordPress). */
    private static function page( string $language, string $kit, array $recipes = self::RECIPES ): string
    {
        $d    = Design::make( $language, $kit );
        $body = '';
        foreach ( $recipes as $r ) {
            $body .= ImageTokens::resolve( (string) SectionRecipes::rawMarkup( $r ), '' );
        }
        $page = SectionRecipes::resolveMarkup( '<!-- wp:divi/placeholder -->' . $body . '<!-- /wp:divi/placeholder -->', $d->language, $d->kit );
        self::assertIsString( $page );

        return $page;
    }

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $GLOBALS['__wp_transients'] = [];
        $this->tool = new SwitchDesign( $this->log, null, fn (): int => $this->now );
        $GLOBALS['__wp_caps']['edit_posts'] = true;
        $this->themePost( 11, 'page', 'publish', [ '_et_pb_use_divi_5' => 'on' ], self::page( 'editorial', 'quiet-solid' ) );
        $this->themePost( 12, 'page', 'draft', [ '_et_pb_use_divi_5' => 'on' ], self::page( 'editorial', 'quiet-solid' ) );
        $this->themePost( 13, 'project', 'publish', [ '_et_pb_use_divi_5' => 'on' ], self::page( 'editorial', 'quiet-solid' ) );
        $this->themePost( 18, 'post', 'publish', [ '_et_pb_use_divi_5' => 'on' ], self::page( 'editorial', 'quiet-solid' ) );
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_current_user_id'] );
        $this->tearDownThemeBuilder();
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function args( array $extra = [] ): array
    {
        return $extra + [ 'page_ids' => [ 11, 12 ], 'from_language' => 'editorial', 'from_kit' => 'quiet-solid', 'to_language' => 'luxe', 'to_kit' => 'gilt-hairline' ];
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function apply( array $args, ?SwitchDesign $tool = null ): array
    {
        $tool = $tool ?? $this->tool;
        $p    = $tool->run( $args + [ 'dry_run' => true ] );
        $this->assertIsString( $p['token'] );

        return $tool->run( $args + [ 'token' => $p['token'] ] );
    }

    /** @param array<string,mixed> $args */
    private function refused( array $args, string $message, ?SwitchDesign $tool = null ): void
    {
        $before = [ $this->layoutContent( 11 ), $this->layoutContent( 12 ), $GLOBALS['__wp_update_calls'], count( $this->repo->rows ) ];
        try {
            ( $tool ?? $this->tool )->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( $before, [ $this->layoutContent( 11 ), $this->layoutContent( 12 ), $GLOBALS['__wp_update_calls'], count( $this->repo->rows ) ] );
    }

    public function testDefinitionAndSchema(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'switch_design', $d['name'] );
        $this->assertSame( 'switch_design', $this->tool->name() );
        $this->assertSame( 'edit_pages', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertStringContainsString( 'dry_run=true first', $d['description'] );
        $this->assertSame(
            [ 'page_id', 'page_ids', 'to_language', 'to_kit', 'from_language', 'from_kit', 'mode', 'dry_run', 'token' ],
            array_keys( $d['inputSchema']['properties'] )
        );
        $this->assertSame( DesignSwitch::MODES, $d['inputSchema']['properties']['mode']['enum'] );
        $this->assertSame( SwitchDesign::MAX_PAGES, $d['inputSchema']['properties']['page_ids']['maxItems'] );
        $this->assertSame( 25, SwitchDesign::MAX_PAGES );
        $this->assertContains( 'switch_design', ProOpenApi::MCP_REST_ONLY, 'MCP + REST only: the ChatGPT budget is used up' );
    }

    public function testArgumentsAreStrict(): void
    {
        $base = [ 'to_language' => 'luxe' ];
        $this->refused( $base, 'exactly one of "page_id"' );
        $this->refused( $base + [ 'page_id' => 11, 'page_ids' => [ 12 ] ], 'exactly one of "page_id"' );
        $this->refused( $base + [ 'page_ids' => range( 1, 26 ) ], 'at most 25 items' );
        $this->refused( $base + [ 'page_ids' => [] ], 'must not be empty' );
        $this->refused( $base + [ 'page_ids' => [ 11, '12' ] ], 'list of positive integers' );
        $this->refused( $base + [ 'page_ids' => [ 0 ] ], 'list of positive integers' );
        $this->refused( $base + [ 'page_id' => '11' ], '"page_id" must be an integer' );
        $this->refused( $base + [ 'page_id' => 0 ], '"page_id" must be at least 1' );
        $this->refused( [ 'page_id' => 11 ], 'Pass "to_language", "to_kit" or both' );
        $this->refused( [ 'page_id' => 11, 'to_language' => 'nope' ], 'Unknown design language "nope" in "to_language". Valid ids: editorial' );
        $this->refused( [ 'page_id' => 11, 'to_kit' => 'nope' ], 'Unknown style kit "nope" in "to_kit". Valid ids: default, quiet-solid' );
        $this->refused( [ 'page_id' => 11, 'to_kit' => 'quiet-solid', 'from_language' => 'nope' ], 'in "from_language"' );
        $this->refused( [ 'page_id' => 11, 'to_kit' => 'quiet-solid', 'from_kit' => 'default' ], 'Unknown style kit "default" in "from_kit"' );
        $this->refused( [ 'page_id' => 11, 'to_kit' => 7 ], '"to_kit" must be a string' );
        $this->refused( $base + [ 'page_id' => 11, 'mode' => 'merge' ], '"mode" must be one of: leave, conform' );
        $this->refused( $base + [ 'page_id' => 11, 'dry_run' => 'yes' ], '"dry_run" must be true or false' );
        $this->refused( $base + [ 'page_id' => 11, 'token' => 5 ], '"token" must be a string' );
        $this->refused( $this->args(), 'needs the token', null );
    }

    public function testPreviewReportsAndReturnsAToken(): void
    {
        $r = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->assertFalse( $r['changed'] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertSame( 2, $r['would_change'] );
        $this->assertSame( 0, $r['would_skip'] );
        $this->assertIsString( $r['token'] );
        $this->assertSame( [ 11, 12 ], array_column( $r['pages'], 'id' ) );
        $page = $r['pages'][0];
        $this->assertSame( 'Post 11', $page['title'] );
        $this->assertSame( 'page', $page['kind'] );
        $this->assertSame( [ 'language' => 'luxe', 'kit' => 'gilt-hairline' ], $page['to'] );
        $this->assertSame( [ 'language' => 'given', 'kit' => 'given' ], $page['from']['source'] );
        $this->assertTrue( $page['valid'] );
        $this->assertSame( [], $page['violations'] );
        $this->assertNotNull( $page['diff'] );
        $this->assertGreaterThan( 0, array_sum( $page['report']['switched']['markers'] ) );
        $this->assertGreaterThan( 0, array_sum( $page['report']['switched']['colours'] ) );
        foreach ( [ 'assumed_count', 'larger_match_preferred', 'inspected' ] as $k ) {
            $this->assertIsInt( $page['report'][ $k ], $k );
        }
        foreach ( [ 'ambiguous_count', 'drifted_count', 'unclassified_count', 'unknown_colours_count' ] as $k ) {
            $this->assertIsInt( $page['report']['left'][ $k ], $k );
        }
        $json = (string) json_encode( $r );
        $this->assertStringNotContainsString( 'wp:divi/', $json, 'the content is never returned' );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testTheReportCountsLargerMatchesAndCapsEveryList(): void
    {
        $assumed = [];
        for ( $i = 0; $i < 30; $i++ ) {
            $assumed[] = [ 'block' => $i, 'name' => 'divi/group', 'kind' => 'marker', 'reason' => MarkerRecovery::ASSUMED_LARGER ];
            $assumed[] = [ 'block' => $i, 'name' => 'divi/text', 'kind' => 'font', 'reason' => 'library majority' ];
        }
        $left              = SwitchResult::emptyLeft();
        $left['ambiguous'] = array_fill( 0, 40, [ 'block' => 1, 'kind' => 'colour' ] );
        $report            = SwitchDesign::report( new SwitchResult( 'x', true, SwitchResult::emptySwitched(), $left, $assumed, 100 ) );
        $this->assertSame( 30, $report['larger_match_preferred'], 'counted' );
        $this->assertSame( 30, $report['assumed_count'], 'only the other assumptions' );
        $this->assertCount( 25, $report['assumed'] );
        $this->assertSame( [ 'library majority' ], array_values( array_unique( array_column( $report['assumed'], 'reason' ) ) ), 'never one line per larger-match card' );
        $this->assertCount( 25, $report['left']['ambiguous'] );
        $this->assertSame( 40, $report['left']['ambiguous_count'] );
        $this->assertArrayNotHasKey( 'content', $report );
    }

    public function testARealRunEqualsTheDirectBuildAsOneBatchOfPostContentItems(): void
    {
        $r = $this->apply( $this->args() );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 2, $r['switched'] );
        $this->assertSame( [], $r['skipped'] );
        $direct = self::page( 'luxe', 'gilt-hairline' );
        $this->assertSame( $direct, $this->layoutContent( 11 ), 'the switched page is the direct build' );
        $this->assertSame( $direct, $this->layoutContent( 12 ) );
        $this->assertCount( 1, $this->repo->rows, 'ONE batch for the whole call' );
        $row = $this->repo->rows[ $r['batch']['id'] ];
        $this->assertSame( 'switch_design', $row['tool'] );
        $this->assertSame( 'Switched 2 page(s) to luxe + gilt-hairline', $row['summary'] );
        $this->assertSame( [ 'post_content', 'post_content' ], array_column( $row['items'], 'kind' ) );
        $this->assertSame( [ '11', '12' ], array_column( $row['items'], 'ref' ) );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( 1, $this->historyRows( 11 ), 'snapshotted by the free history' );
        $this->assertSame( 1, $this->historyRows( 12 ) );
    }

    public function testUndoBatchRestoresEveryPage(): void
    {
        $before = [ $this->layoutContent( 11 ), $this->layoutContent( 12 ), $this->layoutContent( 13 ) ];
        $r      = $this->apply( $this->args( [ 'page_ids' => [ 13, 11, 12 ] ] ) );
        $this->assertSame( 3, $r['switched'] );
        $this->assertNotSame( $before[0], $this->layoutContent( 11 ) );
        $u = ( new BatchUndo( [ 'post_content' => new PostContentKind() ], $this->log ) )->undo( $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( $before, [ $this->layoutContent( 11 ), $this->layoutContent( 12 ), $this->layoutContent( 13 ) ], 'every byte back' );
    }

    public function testAProjectIsSwitchedAndOtherTypesAreNotFound(): void
    {
        $GLOBALS['__wp_caps']['edit_post:12'] = false;
        $r = $this->tool->run( $this->args( [ 'page_ids' => [ 13, 18, 201, 999, 12 ], 'dry_run' => true ] ) );
        $rows = array_column( $r['pages'], null, 'id' );
        $this->assertSame( 'project', $rows[13]['kind'] );
        $this->assertTrue( $rows[13]['valid'] );
        $this->assertSame( 1, $r['would_change'] );
        $this->assertSame( [ 'id' => 18, 'reason' => 'not_found' ], $rows[18], 'a blog post is not a page' );
        $this->assertSame( [ 'id' => 201, 'reason' => 'not_found' ], $rows[201], 'Theme Builder layouts are out of scope' );
        $this->assertSame( [ 'id' => 999, 'reason' => 'not_found' ], $rows[999] );
    }

    public function testForbiddenPagesAreSkippedWithoutLeaking(): void
    {
        $GLOBALS['__wp_caps']['edit_post:12'] = false;
        $r = $this->apply( $this->args() );
        $this->assertSame( 1, $r['switched'] );
        $this->assertSame( [ [ 'id' => 12, 'reason' => 'forbidden' ] ], $r['skipped'], 'only the id and the reason' );
        $this->assertSame( self::page( 'editorial', 'quiet-solid' ), $this->layoutContent( 12 ) );
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->assertStringNotContainsString( 'Post 12', (string) json_encode( $p ) );
    }

    public function testAnInvalidResultIsNeverSaved(): void
    {
        $bad  = static fn ( string $page, Design $from, Design $to, string $mode ): SwitchResult => new SwitchResult(
            str_replace( 'wp:divi/heading', 'wp:divi/not-a-module', DesignSwitch::run( $page, $from, $to, $mode )->content ), true,
            [ 'markers' => [ 'control' => 1 ], 'type' => [], 'rhythm' => [], 'colours' => [], 'fonts' => [], 'images' => 0 ], SwitchResult::emptyLeft(), [], 1 );
        $tool = new SwitchDesign( $this->log, null, fn (): int => $this->now, $bad );
        $p    = $tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->assertSame( 0, $p['would_change'] );
        $this->assertNull( $p['token'], 'nothing to approve' );
        $this->assertSame( 'invalid_result', $p['pages'][0]['reason'] );
        $this->assertFalse( $p['pages'][0]['valid'] );
        $this->assertNotSame( [], $p['pages'][0]['violations'] );
        $this->refused( $this->args( [ 'token' => 'forged' ] ), 'needs the token', $tool );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testTooLargeForUndoIsRefused(): void
    {
        $GLOBALS['__wp_posts'][11]->post_content .= '<!-- ' . str_repeat( 'x', PageHistory::MAX_BYTES ) . ' -->';
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->assertSame( 'too_large_for_undo', $p['pages'][0]['reason'] );
        $this->assertArrayNotHasKey( 'report', $p['pages'][0], 'not even switched' );
        $this->assertSame( 1, $p['would_change'] );

        // The new content over the limit is refused too.
        $big  = static fn ( string $page, Design $from, Design $to, string $mode ): SwitchResult => new SwitchResult(
            $page . '<!-- ' . str_repeat( 'y', PageHistory::MAX_BYTES ) . ' -->', true,
            [ 'markers' => [ 'control' => 1 ], 'type' => [], 'rhythm' => [], 'colours' => [], 'fonts' => [], 'images' => 0 ], SwitchResult::emptyLeft(), [], 1 );
        $q = ( new SwitchDesign( $this->log, null, fn (): int => $this->now, $big ) )->run( $this->args( [ 'page_id' => 12, 'page_ids' => null, 'dry_run' => true ] ) );
        $this->assertSame( 'too_large_for_undo', $q['pages'][0]['reason'] );
    }

    public function testTokenIsSingleUseAndBound(): void
    {
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->refused( $this->args( [ 'token' => $p['token'], 'to_kit' => 'quiet-solid' ] ), 'needs the token' );
        $this->refused( $this->args( [ 'token' => $p['token'], 'page_ids' => [ 11 ] ] ), 'needs the token' );
        $this->refused( $this->args( [ 'token' => $p['token'] . 'x' ] ), 'needs the token' );
        $GLOBALS['__wp_current_user_id'] = 8;
        $this->refused( $this->args( [ 'token' => $p['token'] ] ), 'needs the token' );
        unset( $GLOBALS['__wp_current_user_id'] );
        $this->now += 1801;
        $this->refused( $this->args( [ 'token' => $p['token'] ] ), 'needs the token' );
        $this->now -= 1801;

        $r = $this->tool->run( $this->args( [ 'token' => $p['token'] ] ) );
        $this->assertTrue( $r['changed'] );
        $this->refused( $this->args( [ 'token' => $p['token'] ] ), 'needs the token' );
    }

    public function testTheOrderOfPageIdsDoesNotChangeTheToken(): void
    {
        $p = $this->tool->run( $this->args( [ 'page_ids' => [ 12, 11, 12 ], 'dry_run' => true ] ) );
        $r = $this->tool->run( $this->args( [ 'page_ids' => [ 11, 12 ], 'token' => $p['token'] ] ) );
        $this->assertSame( 2, $r['switched'] );
    }

    public function testChangedSincePreviewIsSkipped(): void
    {
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $edited = self::page( 'editorial', 'quiet-solid', [ 'faq-accordion' ] );
        $GLOBALS['__wp_posts'][12]->post_content = $edited;
        $r = $this->tool->run( $this->args( [ 'token' => $p['token'] ] ) );
        $this->assertSame( 1, $r['switched'] );
        $this->assertSame( [ [ 'id' => 12, 'title' => 'Post 12', 'kind' => 'page', 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
        $this->assertSame( $edited, $this->layoutContent( 12 ), 'never overwritten' );
    }

    public function testAPlannedOutputThatChangedIsSkipped(): void
    {
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        // The same stored pages, but the engine now plans other bytes (as after a free-plugin update that
        // changed the recipes the context table is built from).
        $other = static function ( string $page, Design $from, Design $to, string $mode ): SwitchResult {
            $r = DesignSwitch::run( $page, $from, $to, $mode );

            return new SwitchResult( $r->content . '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->', true, $r->switched, $r->left, $r->assumed, $r->inspected );
        };
        $tool = new SwitchDesign( $this->log, null, fn (): int => $this->now, $other );
        $r    = $tool->run( $this->args( [ 'token' => $p['token'] ] ) );
        $this->assertSame( 0, $r['switched'] );
        $this->assertSame( [ 'changed_since_preview', 'changed_since_preview' ], array_column( $r['skipped'], 'reason' ) );
        $this->assertSame( self::page( 'editorial', 'quiet-solid' ), $this->layoutContent( 11 ) );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testNotInPreviewIsSkipped(): void
    {
        $GLOBALS['__wp_caps']['edit_post:12'] = false;
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        unset( $GLOBALS['__wp_caps']['edit_post:12'] );
        $r = $this->tool->run( $this->args( [ 'token' => $p['token'] ] ) );
        $this->assertSame( 1, $r['switched'] );
        $this->assertSame( [ [ 'id' => 12, 'title' => 'Post 12', 'kind' => 'page', 'reason' => 'not_in_preview' ] ], $r['skipped'] );
    }

    public function testIdentityTargetIsChangedFalse(): void
    {
        $p = $this->tool->run( $this->args( [ 'page_id' => 11, 'page_ids' => null, 'to_language' => 'editorial', 'to_kit' => 'quiet-solid', 'dry_run' => true ] ) );
        $this->assertFalse( $p['changed'] );
        $this->assertSame( 0, $p['would_change'] );
        $this->assertNull( $p['token'] );
        $this->assertSame( 'same_design', $p['pages'][0]['reason'] );
        $this->assertSame( 'The page already has this design; nothing to change.', $p['pages'][0]['detail'] );
        $c = $this->tool->run( $this->args( [ 'page_id' => 11, 'page_ids' => null, 'to_language' => 'editorial', 'to_kit' => 'quiet-solid', 'mode' => 'conform', 'dry_run' => true ] ) );
        $this->assertSame( 'same_design', $c['pages'][0]['reason'], 'conform keeps the reason' );
        $this->assertSame( 'The page already has this design. conform does not tidy a page within its own design: nothing was changed.', $c['pages'][0]['detail'] );
        $this->assertSame( 0, $c['would_change'] );
    }

    public function testDefaultKitAndAnOmittedTargetKeepTheSources(): void
    {
        $p = $this->tool->run( [ 'page_id' => 11, 'from_language' => 'editorial', 'from_kit' => 'quiet-solid', 'to_language' => 'luxe', 'to_kit' => 'default', 'dry_run' => true ] );
        $this->assertSame( [ 'language' => 'luxe', 'kit' => 'gilt-hairline' ], $p['pages'][0]['to'], 'luxe\'s own kit' );
        $q = $this->tool->run( [ 'page_id' => 11, 'from_language' => 'editorial', 'from_kit' => 'quiet-solid', 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertSame( [ 'language' => 'luxe', 'kit' => 'quiet-solid' ], $q['pages'][0]['to'], 'the source kit is kept' );
        $s = $this->tool->run( [ 'page_id' => 11, 'from_language' => 'editorial', 'from_kit' => 'quiet-solid', 'to_kit' => 'gilt-hairline', 'dry_run' => true ] );
        $this->assertSame( [ 'language' => 'editorial', 'kit' => 'gilt-hairline' ], $s['pages'][0]['to'], 'the source language is kept' );
    }

    public function testTheSourceIsDetectedWhenNotGiven(): void
    {
        $p = $this->tool->run( [ 'page_id' => 11, 'to_language' => 'luxe', 'to_kit' => 'gilt-hairline', 'dry_run' => true ] );
        $page = $p['pages'][0];
        $this->assertSame( 'editorial', $page['from']['language'] );
        $this->assertSame( 'quiet-solid', $page['from']['kit'] );
        $this->assertSame( [ 'language' => 'detected', 'kit' => 'detected' ], $page['from']['source'] );
        $this->assertTrue( $page['valid'] );
    }

    public function testNothingToSwitchIsReported(): void
    {
        $GLOBALS['__wp_posts'][11]->post_content = self::header();
        $p = $this->tool->run( $this->args( [ 'page_id' => 11, 'page_ids' => null, 'dry_run' => true ] ) );
        $this->assertSame( 'nothing_to_switch', $p['pages'][0]['reason'] );
        $this->assertSame( 0, $p['would_change'] );
    }

    public function testADetectionThatIsRefusedIsSkipped(): void
    {
        $GLOBALS['__wp_posts'][11]->post_content = self::header();
        $p = $this->tool->run( [ 'page_id' => 11, 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertSame( 'detection_ambiguous', $p['pages'][0]['reason'] );
        $this->assertStringContainsString( 'Pass from_language', $p['pages'][0]['detail'] );
    }

    public function testThinDetectionEvidenceIsSkippedLowConfidenceUnlessTheLanguageIsGiven(): void
    {
        // The review's scenario: one editorial `bg` text colour on a dark #123456 section.
        $GLOBALS['__wp_posts'][11]->post_content = '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#123456"}}}}}} --><!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/text {"content":{"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"#faf7f2"}}}}}}}} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
        $p = $this->tool->run( [ 'page_id' => 11, 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertSame( 'low_confidence', $p['pages'][0]['reason'] );
        $this->assertStringContainsString( 'Pass from_language', $p['pages'][0]['detail'] );
        $this->assertSame( 0, $p['would_change'] );
        $q = $this->tool->run( [ 'page_id' => 11, 'from_language' => 'editorial', 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertNotSame( 'low_confidence', $q['pages'][0]['reason'] ?? null, 'the caller vouches for a given language' );
        $this->assertSame( 'given', $q['pages'][0]['from']['source']['language'] );
    }

    public function testConformIsEitherBuiltOrRefusedPlainly(): void
    {
        try {
            $r = $this->tool->run( $this->args( [ 'mode' => 'conform', 'dry_run' => true ] ) );
            $this->assertTrue( $r['dry_run'], 'conform is built (plan Task 7 merged)' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'The "conform" mode is not available', $e->getMessage() );
        }
    }

    public function testTheGuardDisablesOnlyThisToolOnAnOlderFreePlugin(): void
    {
        $missing = new SwitchDesign( $this->log, null, null, null, static fn ( string $c ): bool => 'AiEditorDivi5\\WP\\LanguageResolver' !== $c && class_exists( $c ) );
        $this->assertSame( SwitchDesign::MESSAGE_FREE_TOO_OLD, $missing->unsupported() );
        $this->refused( $this->args( [ 'dry_run' => true ] ), 'needs a newer version of the free plugin', $missing );

        // The published free 4.14.0's kit adapters: no carrier roles on testimonial, blurb, number counter, no rule-accent.
        $old = KitAdapters::APPLIED;
        unset( $old['divi/testimonial'], $old['divi/blurb'], $old['divi/number-counter'] );
        $old['divi/divider'] = [ 'rule', 'rule-strong' ];
        $noCarriers = new SwitchDesign( $this->log, null, null, null, null, static fn (): array => $old );
        $this->refused( $this->args( [ 'dry_run' => true ] ), 'needs a newer version of the free plugin', $noCarriers );
        $this->assertSame( SwitchDesign::MESSAGE_FREE_TOO_OLD, ( new SwitchDesign( $this->log, null, null, null, null, static fn (): ?array => null ) )->unsupported() );

        // A newer free plugin with an extra role is fine (the role is simply not recovered).
        $newer                  = KitAdapters::APPLIED;
        $newer['divi/button'][] = 'control-tertiary';
        $this->assertNull( ( new SwitchDesign( $this->log, null, null, null, null, static fn (): array => $newer ) )->unsupported() );
        $this->assertNull( $this->tool->unsupported(), 'the installed free plugin has everything' );

        // The other Pro tools do not depend on these classes: they are not in Compat's list (that would switch all off).
        foreach ( SwitchDesign::REQUIRED_FREE as $class ) {
            $this->assertNotContains( $class, Compat::REQUIRED_CLASSES, $class );
        }
    }

    public function testMcpAndRestGiveTheSamePayload(): void
    {
        $registry = new ToolRegistry( [ $this->tool ], static fn (): bool => true );
        $args     = $this->args( [ 'dry_run' => true ] );
        $mcp      = $registry->claim( null, 'switch_design', $args, 1 );
        $this->assertInstanceOf( \WP_REST_Response::class, $mcp );
        $fromMcp  = json_decode( (string) $mcp->get_data()['result']['content'][0]['text'], true );
        $rest     = ( new ProRest( $registry ) )->handle( 'switch_design', new \WP_REST_Request( $args ) );
        $this->assertInstanceOf( \WP_REST_Response::class, $rest );
        $fromRest = json_decode( (string) json_encode( $rest->get_data() ), true );
        $this->assertIsString( $fromMcp['token'] );
        $this->assertIsString( $fromRest['token'] );
        unset( $fromMcp['token'], $fromRest['token'] ); // each preview gets its own one-time nonce
        $this->assertSame( $fromRest, $fromMcp );

        $bad = $registry->claim( null, 'switch_design', [ 'page_id' => 11 ], 2 );
        $this->assertSame( -32602, $bad->get_data()['error']['code'] );
        $err = ( new ProRest( $registry ) )->handle( 'switch_design', new \WP_REST_Request( [ 'page_id' => 11 ] ) );
        $this->assertInstanceOf( \WP_Error::class, $err );
    }
}
