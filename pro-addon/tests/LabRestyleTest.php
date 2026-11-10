<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\LabBriefKind;
use AiEditorDivi5\Pro\Kinds\PostContentKind;
use AiEditorDivi5\Pro\Lab\LabCompile;
use AiEditorDivi5\Pro\Lab\LabSupport;
use AiEditorDivi5\Pro\Rest\ProRest;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools\LabRestyle;
use AiEditorDivi5\Pro\Tools\SwitchDesign;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\PageHistory;
use AiEditorDivi5\WP\StudioStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/LabFixture.php';

/**
 * Pro Lab Tasks 4 and 5: `lab_restyle` arguments, plan, preview, apply and undo (spec 2026-11-10-pro-lab-design.md
 * section A). No page is written in a preview; a real run writes the page and the brief as one undoable batch.
 */
final class LabRestyleTest extends TestCase
{
    use LabFixture;

    private LabRestyle $tool;
    private int $now = 1000000;

    protected function setUp(): void
    {
        $this->setUpLab();
        $this->tool = new LabRestyle( $this->log, null, fn (): int => $this->now );
        $GLOBALS['__wp_caps']['edit_posts'] = true;
    }

    protected function tearDown(): void
    {
        $this->tearDownLab();
    }

    /** Pages 21 and 22 (draft, untouched), 23 published, 24 draft edited after linking, 25 trash, 26 no brief, 27 a Project. */
    private function seven(): void
    {
        $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $this->labPage( 22, 'draft', $this->labBrief( 'editorial' ) );
        $this->labPage( 23, 'publish', $this->labBrief( 'editorial' ) );
        $this->labPage( 24, 'draft', $this->labBrief( 'editorial' ) );
        $GLOBALS['__wp_posts'][24]->post_content .= "\n<!-- edited by hand -->";
        $this->labPage( 25, 'trash', $this->labBrief( 'editorial' ) );
        $this->themePost( 26, 'page', 'draft', [ '_et_pb_use_divi_5' => 'on' ], '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->' );
        $this->labPage( 27, 'draft', $this->labBrief( 'editorial' ) );
        $GLOBALS['__wp_posts'][27]->post_type = 'project';
    }

    /** @return array<string,array<string,mixed>> id => row of a preview */
    private function rows( array $r ): array
    {
        $out = [];
        foreach ( $r['pages'] as $p ) {
            $out[ $p['id'] ] = $p;
        }

        return $out;
    }

    /** @param array<string,mixed> $args */
    private function refused( array $args, string $message = '' ): void
    {
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
    }

    /** @return array<string,mixed> the payload of a preview token */
    private static function payload( string $token ): array
    {
        $part = explode( '.', $token )[0];

        return json_decode( (string) base64_decode( strtr( $part, '-_', '+/' ) ), true );
    }

    public function testDefinitionAndCapability(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'lab_restyle', $d['name'] );
        $this->assertSame( 'lab_restyle', $this->tool->name() );
        $this->assertSame( 'edit_pages', $this->tool->capability() );
        $this->assertSame( 25, LabRestyle::MAX_PAGES );
        $this->assertSame(
            [ 'page_id', 'page_ids', 'all_mine', 'to_language', 'to_kit', 'include_published', 'dry_run', 'token' ],
            array_keys( $d['inputSchema']['properties'] )
        );
        $this->assertSame( 25, $d['inputSchema']['properties']['page_ids']['maxItems'] );
        $this->assertSame(
            'Rebuild pages made with Divi 5 Lab in another design language and/or style kit, exactly as Lab would build them now. Only unedited Lab pages; edited ones are skipped (use switch_design). Always dry_run=true first, then repeat with the token. Undoable.',
            $d['description']
        );
        $this->assertStringNotContainsString( 'Studio', json_encode( $d ) );
    }

    public function testArgumentsAreStrict(): void
    {
        $this->seven();
        $base = [ 'to_language' => 'luxe', 'dry_run' => true ];
        $this->refused( $base, 'exactly one of' );
        $this->refused( $base + [ 'page_id' => 21, 'page_ids' => [ 22 ] ], 'exactly one of' );
        $this->refused( $base + [ 'page_id' => 21, 'all_mine' => true ], 'exactly one of' );
        $this->refused( $base + [ 'page_ids' => [ 22 ], 'all_mine' => true ], 'exactly one of' );
        $this->refused( $base + [ 'all_mine' => false ], '"all_mine"' );
        $this->refused( $base + [ 'all_mine' => 'yes' ], '"all_mine"' );
        $this->refused( [ 'page_id' => 21, 'dry_run' => true ], 'Pass "to_language", "to_kit" or both' );
        $this->refused( [ 'page_id' => 21, 'to_language' => 'nope' ], 'Unknown design language "nope"' );
        $this->refused( [ 'page_id' => 21, 'to_kit' => 'nope' ], 'Unknown style kit "nope"' );
        $this->refused( [ 'page_id' => 21, 'to_language' => 'luxe', 'dry_run' => 'yes' ], '"dry_run" must be true or false' );
        $this->refused( $base + [ 'page_id' => '21' ], '"page_id" must be an integer' );
        $this->refused( $base + [ 'page_ids' => [ 21, '22' ] ], 'positive integers' );
        $this->refused( $base + [ 'page_ids' => range( 1, 26 ) ], 'at most 25' );
        $this->refused( $base + [ 'page_id' => 21, 'include_published' => 'yes' ], '"include_published"' );
        $this->refused( $base + [ 'page_id' => 21, 'token' => 5 ], '"token" must be a string' );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testEverySkipReasonIsReported(): void
    {
        $this->seven();
        $r    = $this->tool->run( [ 'page_ids' => [ 21, 22, 23, 24, 25, 26, 27 ], 'to_language' => 'luxe', 'dry_run' => true ] );
        $rows = $this->rows( $r );

        $this->assertSame( 2, $r['would_change'] );
        $this->assertSame( 5, $r['would_skip'] );
        $this->assertIsString( $r['token'] );
        $this->assertArrayNotHasKey( 'reason', $rows[21] );
        $this->assertArrayNotHasKey( 'reason', $rows[22] );
        $this->assertSame( 'published', $rows[23]['reason'] );
        $this->assertSame( 'edited', $rows[24]['reason'] );
        $this->assertStringContainsString( 'switch_design', $rows[24]['detail'] );
        $this->assertSame( 'trashed', $rows[25]['reason'] );
        $this->assertSame( 'not_lab_page', $rows[26]['reason'] );
        $this->assertSame( 'not_lab_page', $rows[27]['reason'] );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], 'a preview writes nothing' );
        $this->assertSame( [], $this->repo->rows );

        $r2 = $this->tool->run( [ 'page_ids' => [ 98, 99 ], 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertSame( [ 'not_found', 'not_found' ], array_column( $r2['pages'], 'reason' ) );
        $GLOBALS['__wp_caps']['edit_post'] = false;
        $r3 = $this->tool->run( [ 'page_id' => 21, 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertSame( 'forbidden', $r3['pages'][0]['reason'] );
        $this->assertArrayNotHasKey( 'title', $r3['pages'][0], 'nothing else about a page the user may not edit' );
    }

    public function testAnotherUsersBriefIsNotMine(): void
    {
        $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ), 2 );
        $r = $this->tool->run( [ 'page_id' => 21, 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertSame( 'not_lab_page', $r['pages'][0]['reason'] );
    }

    public function testIncludePublishedPlansAPublishedLabPage(): void
    {
        $this->seven();
        $r = $this->tool->run( [ 'page_id' => 23, 'to_language' => 'luxe', 'include_published' => true, 'dry_run' => true ] );
        $this->assertArrayNotHasKey( 'reason', $r['pages'][0] );
        $this->assertSame( 1, $r['would_change'] );
        // A trashed page stays refused, and so does a Project.
        $r = $this->tool->run( [ 'page_ids' => [ 25, 27 ], 'to_language' => 'luxe', 'include_published' => true, 'dry_run' => true ] );
        $this->assertSame( [ 'trashed', 'not_lab_page' ], array_column( $r['pages'], 'reason' ) );
        $this->assertNull( $r['token'] );
    }

    public function testAPageOverTheUndoLimitIsTooLargeForUndo(): void
    {
        $this->labPage( 28, 'draft', $this->labBrief( 'editorial' ) );
        $big = $GLOBALS['__wp_posts'][28]->post_content . '<!-- ' . str_repeat( 'x', PageHistory::MAX_BYTES ) . ' -->';
        $GLOBALS['__wp_posts'][28]->post_content = $big;
        $row = StudioStore::forPage( 1, 28 );
        $this->assertTrue( StudioStore::link( 1, $row['id'], 28, hash( 'sha256', $big ) ) );

        $r = $this->tool->run( [ 'page_id' => 28, 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertSame( 'too_large_for_undo', $r['pages'][0]['reason'] );
        $this->assertNull( $r['token'] );
    }

    public function testSameDesignAndRecipeRemoved(): void
    {
        $own = (string) DesignLanguages::get( 'editorial' )['kit'];
        $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $this->labPage( 22, 'draft', $this->labBrief( 'editorial', 'quiet-solid' ) );
        $r = $this->tool->run( [ 'page_ids' => [ 21, 22 ], 'to_language' => 'editorial', 'to_kit' => 'default', 'dry_run' => true ] );
        $this->assertSame( [ 'same_design', 'same_design' ], array_column( $r['pages'], 'reason' ) );
        $this->assertNull( $r['token'] );
        $this->assertSame( [ 'language' => 'editorial', 'kit' => $own ], $r['pages'][0]['to'] );

        // A brief whose recipe is renamed away.
        $brief                         = $this->labBrief( 'editorial' );
        $row                           = StudioStore::forPage( 1, 21 );
        $brief['sections'][1]['recipe'] = 'no-such-recipe-any-more';
        $this->assertTrue( StudioStore::save( 1, $brief, $row['id'], $row['brief_hash'] )['ok'] );
        $this->assertTrue( StudioStore::link( 1, $row['id'], 21, hash( 'sha256', $GLOBALS['__wp_posts'][21]->post_content ) ) );
        $r = $this->tool->run( [ 'page_id' => 21, 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertSame( 'recipe_removed', $r['pages'][0]['reason'] );
        $this->assertStringContainsString( 'no-such-recipe-any-more', $r['pages'][0]['detail'] );
        $this->assertStringContainsString( 'section 2', $r['pages'][0]['detail'] );
    }

    public function testAnInvalidNewBriefIsInvalidResult(): void
    {
        $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $row   = StudioStore::forPage( 1, 21 );
        $brief = $this->labBrief( 'editorial' );
        $brief['sections'][0]['slots'] = [ 'no.such.slot' => 'Hello' ];
        $this->assertTrue( StudioStore::save( 1, $brief, $row['id'], $row['brief_hash'] )['ok'] );
        $this->assertTrue( StudioStore::link( 1, $row['id'], 21, hash( 'sha256', $GLOBALS['__wp_posts'][21]->post_content ) ) );

        $r = $this->tool->run( [ 'page_id' => 21, 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertSame( 'invalid_result', $r['pages'][0]['reason'] );
        $this->assertFalse( $r['pages'][0]['valid'] );
        $this->assertNotSame( [], $r['pages'][0]['violations'] );
        $this->assertLessThanOrEqual( 10, count( $r['pages'][0]['violations'] ) );
        $this->assertNull( $r['token'] );
    }

    public function testThePreviewShowsADiffAndTheFromAndToLooks(): void
    {
        $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $r   = $this->tool->run( [ 'page_id' => 21, 'to_language' => 'luxe', 'dry_run' => true ] );
        $row = $r['pages'][0];

        $this->assertSame( [ 'language' => 'editorial', 'kit' => (string) DesignLanguages::get( 'editorial' )['kit'] ], $row['from'] );
        $this->assertSame( [ 'language' => 'luxe', 'kit' => (string) DesignLanguages::get( 'luxe' )['kit'] ], $row['to'] );
        $this->assertNotNull( $row['diff'] );
        $this->assertTrue( $row['valid'] );
        $this->assertSame( [], $row['violations'] );
        $this->assertSame( 'Post 21', $row['title'] );
        $this->assertSame( 'page', $row['kind'] );
        $this->assertIsInt( $row['brief_id'] );
        $this->assertIsInt( $row['unfilled_count'] );
        $this->assertStringNotContainsString( 'wp:divi/', (string) json_encode( $r ), 'the content is never returned' );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );

        // The new look is exactly what Lab compiles for the changed brief.
        $new = LabCompile::compile( $this->labBrief( 'luxe' ) );
        $this->assertTrue( $new['ok'] );
    }

    public function testAKitOnlyChangeKeepsTheBriefsLanguage(): void
    {
        $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $r = $this->tool->run( [ 'page_id' => 21, 'to_kit' => 'heavy-block', 'dry_run' => true ] );
        $this->assertSame( [ 'language' => 'editorial', 'kit' => 'heavy-block' ], $r['pages'][0]['to'] );
        $this->assertIsString( $r['token'] );
    }

    public function testAllMineTakesTheUsersLabPagesAndRefusesMoreThan25(): void
    {
        $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $this->labPage( 22, 'draft', $this->labBrief( 'editorial' ) );
        $this->labPage( 23, 'draft', $this->labBrief( 'editorial' ), 2 );

        $r = $this->tool->run( [ 'all_mine' => true, 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertSame( [ 21, 22 ], array_column( $r['pages'], 'id' ) );
        $this->assertSame( Canon::hash( [ 'tool' => 'lab_restyle', 'ids' => null, 'all_mine' => true, 'to_language' => 'luxe', 'to_kit' => null, 'include_published' => false ] ), self::payload( $r['token'] )['a'] );

        // The empty case is an honest preview, not an error.
        $GLOBALS['__wp_current_user_id'] = 9;
        $r = $this->tool->run( [ 'all_mine' => true, 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertSame( [], $r['pages'] );
        $this->assertNull( $r['token'] );
        $GLOBALS['__wp_current_user_id'] = 1;

        for ( $i = 0; $i < 24; $i++ ) {
            $saved = StudioStore::save( 1, $this->labBrief( 'editorial' ) );
            $this->assertTrue( StudioStore::link( 1, $saved['id'], 100 + $i, str_repeat( 'a', 64 ) ) );
        }
        $this->refused( [ 'all_mine' => true, 'to_language' => 'luxe', 'dry_run' => true ], '26' );
        $this->refused( [ 'all_mine' => true, 'to_language' => 'luxe', 'dry_run' => true ], 'page_ids' );
    }

    public function testPreviewGivesATokenOnlyWhenSomethingWouldChange(): void
    {
        $this->seven();
        $none = $this->tool->run( [ 'page_ids' => [ 23, 24, 25 ], 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertSame( 0, $none['would_change'] );
        $this->assertNull( $none['token'] );
        $some = $this->tool->run( [ 'page_ids' => [ 21, 24 ], 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertSame( 1, $some['would_change'] );
        $payload = self::payload( $some['token'] );
        $this->assertSame( [ '21' ], array_map( 'strval', array_keys( $payload['i'] ) ) );
        $this->assertSame( 1, $payload['u'] );
        $this->assertSame( $this->now, $payload['t'] );
        // stored16:new16:brief16
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{16}:[0-9a-f]{16}:[0-9a-f]{16}$/', $payload['i'][21] );
        $row = StudioStore::forPage( 1, 21 );
        $this->assertSame(
            substr( hash( 'sha256', $GLOBALS['__wp_posts'][21]->post_content ), 0, 16 ),
            explode( ':', $payload['i'][21] )[0]
        );
        $this->assertSame( substr( $row['brief_hash'], 0, 16 ), explode( ':', $payload['i'][21] )[2] );
    }

    public function testTheOrderOfPageIdsDoesNotChangeTheToken(): void
    {
        $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $this->labPage( 22, 'draft', $this->labBrief( 'editorial' ) );
        $a = self::payload( $this->tool->run( [ 'page_ids' => [ 22, 21, 22 ], 'to_language' => 'luxe', 'dry_run' => true ] )['token'] );
        $b = self::payload( $this->tool->run( [ 'page_ids' => [ 21, 22 ], 'to_language' => 'luxe', 'dry_run' => true ] )['token'] );
        $this->assertSame( $a['a'], $b['a'] );
        $this->assertSame( $a['i'], $b['i'] );
        // A different target is a different token.
        $c = self::payload( $this->tool->run( [ 'page_ids' => [ 21, 22 ], 'to_language' => 'glass-dark', 'dry_run' => true ] )['token'] );
        $this->assertNotSame( $a['a'], $c['a'] );
        $d = self::payload( $this->tool->run( [ 'page_ids' => [ 21, 22 ], 'to_language' => 'luxe', 'include_published' => true, 'dry_run' => true ] )['token'] );
        $this->assertNotSame( $a['a'], $d['a'] );
    }

    public function testTheGuardDisablesOnlyThisTool(): void
    {
        $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $noCompiler = static fn ( string $c ): bool => 'AiEditorDivi5\\WP\\StudioCompiler' !== $c && class_exists( $c );
        $guarded    = new LabRestyle( $this->log, null, null, \Closure::fromCallable( $noCompiler ) );
        $this->assertSame( LabSupport::message( 'lab_restyle' ), $guarded->unsupported() );
        try {
            $guarded->run( [ 'page_id' => 21, 'to_language' => 'luxe', 'dry_run' => true ] );
            $this->fail( 'the guard must refuse' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( LabSupport::message( 'lab_restyle' ), $e->getMessage() );
        }
        $this->assertNull( $this->tool->unsupported() );

        $noLinked = static fn ( string $c, string $m ): bool => ! ( 'linked' === $m ) && method_exists( $c, $m );
        $guarded2 = new LabRestyle( $this->log, null, null, null, \Closure::fromCallable( $noLinked ) );
        $this->assertSame( LabSupport::message( 'lab_restyle' ), $guarded2->unsupported() );

        // switch_design is unaffected: it has its own guard and still runs its preview.
        $this->themePost( 31, 'page', 'draft', [ '_et_pb_use_divi_5' => 'on' ], $GLOBALS['__wp_posts'][21]->post_content );
        $r = ( new SwitchDesign( $this->log ) )->run( [ 'page_id' => 31, 'from_language' => 'editorial', 'to_language' => 'luxe', 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
    }

    // ---- Task 5: apply and undo -------------------------------------------------------------------------------------

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function applyRun( array $args, ?LabRestyle $tool = null ): array
    {
        $tool = $tool ?? $this->tool;
        $p    = $tool->run( $args + [ 'dry_run' => true ] );
        $this->assertIsString( $p['token'] );

        return $tool->run( $args + [ 'token' => $p['token'] ] );
    }

    /** The compiled markup of a brief (what Lab would save). */
    private function compiled( array $brief ): string
    {
        $r = LabCompile::compile( $brief );
        $this->assertTrue( (bool) $r['ok'] );

        return (string) $r['markup'];
    }

    /** @return array<string,mixed> the brief row of page 21 as stored, without the update time */
    private function briefRow( int $briefId, int $user = 1 ): array
    {
        $row = StudioStore::get( $user, $briefId );
        $this->assertNotNull( $row );
        unset( $row['updated_at'] );

        return $row;
    }

    private function engine(): BatchUndo
    {
        return new BatchUndo( [ 'post_content' => new PostContentKind(), LabBriefKind::NAME => new LabBriefKind() ], $this->log );
    }

    /** @return array<string,mixed> */
    private function batchRow( array $r ): array
    {
        $this->assertIsArray( $r['batch'] ?? null );

        return $this->repo->rows[ $r['batch']['id'] ];
    }

    /** What must stay the same when a run is refused or skips everything. @return array<int,mixed> */
    private function state( int $briefId ): array
    {
        return [ $this->layoutContent( 21 ), $this->briefRow( $briefId ), $GLOBALS['__wp_update_calls'], count( $this->repo->rows ) ];
    }

    public function testARealRunSavesTheDirectCompileAndUpdatesTheBrief(): void
    {
        $briefId = $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $r       = $this->applyRun( [ 'page_id' => 21, 'to_language' => 'luxe' ] );
        $kit     = (string) DesignLanguages::get( 'luxe' )['kit'];

        $this->assertTrue( $r['changed'] );
        $this->assertFalse( $r['dry_run'] );
        $this->assertSame( 1, $r['restyled'] );
        $this->assertSame( [], $r['skipped'] );
        $this->assertSame(
            [ [ 'id' => 21, 'title' => 'Post 21', 'from' => [ 'language' => 'editorial', 'kit' => (string) DesignLanguages::get( 'editorial' )['kit'] ], 'to' => [ 'language' => 'luxe', 'kit' => $kit ], 'brief_id' => $briefId ] ],
            $r['pages']
        );

        $stored = $this->layoutContent( 21 );
        $this->assertSame( $this->compiled( $this->labBrief( 'luxe' ) ), $stored, 'the page is the direct compile of the restyled brief' );
        $row = $this->briefRow( $briefId );
        $this->assertSame( $this->labBrief( 'luxe' ), $row['brief'], 'only the language changed' );
        $this->assertSame( StudioStore::hashOf( $this->labBrief( 'luxe' ) ), $row['brief_hash'] );
        $this->assertSame( hash( 'sha256', $stored ), $row['content_hash'], 'linked with the content as stored' );
        $this->assertSame( 21, $row['page_id'] );

        $this->assertCount( 1, $this->repo->rows, 'ONE batch' );
        $batch = $this->batchRow( $r );
        $this->assertSame( 'lab_restyle', $batch['tool'] );
        $this->assertSame( "Restyled 1 Lab page(s) to luxe + {$kit}", $batch['summary'] );
        $this->assertSame( [ 'lab_brief', 'post_content' ], array_column( $batch['items'], 'kind' ) );
        $this->assertSame( [ (string) $briefId, '21' ], array_column( $batch['items'], 'ref' ) );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( 1, $this->historyRows( 21 ), 'snapshotted by the free history' );
        $this->assertStringNotContainsString( 'wp:divi/', (string) json_encode( $r ) );
        $this->assertStringNotContainsString( 'Studio', (string) json_encode( $r ) );

        // The Lab screen would now see an untouched page: a second restyle plans cleanly.
        $again = $this->tool->run( [ 'page_id' => 21, 'to_language' => 'glass-dark', 'dry_run' => true ] );
        $this->assertSame( 1, $again['would_change'] );
    }

    public function testTwoPagesToTheirNewLooksAreOneBatch(): void
    {
        $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $this->labPage( 22, 'draft', $this->labBrief( 'editorial', 'heavy-block' ) );
        $r = $this->applyRun( [ 'page_ids' => [ 22, 21 ], 'to_language' => 'luxe' ] );
        $this->assertSame( 2, $r['restyled'] );
        $batch = $this->batchRow( $r );
        $this->assertSame( 'Restyled 2 Lab page(s) to their new looks', $batch['summary'] );
        $this->assertSame( [ 'lab_brief', 'post_content', 'lab_brief', 'post_content' ], array_column( $batch['items'], 'kind' ) );
    }

    public function testATokenIsRequiredSingleUseAndBound(): void
    {
        $briefId = $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $args    = [ 'page_id' => 21, 'to_language' => 'luxe' ];
        $p       = $this->tool->run( $args + [ 'dry_run' => true ] );
        $before  = $this->state( $briefId );

        $this->refused( $args, 'needs the token' );
        $this->refused( $args + [ 'token' => 'made-up' ], 'needs the token' );
        $this->refused( $args + [ 'token' => $p['token'] . 'x' ], 'needs the token' );
        $this->refused( [ 'page_id' => 21, 'to_language' => 'glass-dark', 'token' => $p['token'] ], 'needs the token' );
        $GLOBALS['__wp_current_user_id'] = 2;
        $this->refused( $args + [ 'token' => $p['token'] ], 'needs the token' );
        $GLOBALS['__wp_current_user_id'] = 1;
        $this->now += 1801;
        $this->refused( $args + [ 'token' => $p['token'] ], 'needs the token' );
        $this->now -= 1801;
        $this->assertSame( $before, $this->state( $briefId ), 'nothing changed by a refused run' );

        $r = $this->tool->run( $args + [ 'token' => $p['token'] ] );
        $this->assertTrue( $r['changed'] );
        $after = $this->state( $briefId );
        $this->refused( $args + [ 'token' => $p['token'] ], 'needs the token' );
        $this->assertSame( $after, $this->state( $briefId ), 'a second use changes nothing' );
    }

    public function testAPageEditedSinceThePreviewIsSkipped(): void
    {
        $briefId = $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $args    = [ 'page_id' => 21, 'to_language' => 'luxe' ];

        // A hand edit: the page no longer matches its brief, so it is "edited" now.
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__wp_posts'][21]->post_content .= "\n<!-- edited by hand -->";
        $edited = $this->layoutContent( 21 );
        $r      = $this->tool->run( $args + [ 'token' => $p['token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 0, $r['restyled'] );
        $this->assertSame( 'edited', $r['skipped'][0]['reason'] );
        $this->assertSame( $edited, $this->layoutContent( 21 ), 'never overwritten' );
        $this->assertSame( [], $this->repo->rows );
        $this->assertArrayNotHasKey( 'batch', $r );

        // A page that changed AND was relinked (as a Lab rebuild does) since the preview: changed_since_preview.
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertNull( $p['token'], 'an edited page is not planned' );
        $this->assertTrue( StudioStore::link( 1, $briefId, 21, hash( 'sha256', $edited ) ) );
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertIsString( $p['token'] );
        $GLOBALS['__wp_posts'][21]->post_content .= "\n<!-- rebuilt -->";
        $this->assertTrue( StudioStore::link( 1, $briefId, 21, hash( 'sha256', $this->layoutContent( 21 ) ) ) );
        $rebuilt = $this->layoutContent( 21 );
        $r       = $this->tool->run( $args + [ 'token' => $p['token'] ] );
        $this->assertSame( [ [ 'id' => 21, 'title' => 'Post 21', 'kind' => 'page', 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
        $this->assertSame( $rebuilt, $this->layoutContent( 21 ) );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testABriefEditedSinceThePreviewIsSkipped(): void
    {
        $briefId = $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $args    = [ 'page_id' => 21, 'to_language' => 'luxe' ];
        $p       = $this->tool->run( $args + [ 'dry_run' => true ] );

        // An edit in the Lab screen between the preview and the run (the page itself is unchanged and still linked).
        $row             = StudioStore::get( 1, $briefId );
        $changed         = $this->labBrief( 'editorial' );
        $changed['title'] = 'A new title typed in Lab';
        $this->assertTrue( StudioStore::save( 1, $changed, $briefId, $row['brief_hash'] )['ok'] );
        $before = $this->state( $briefId );

        $r = $this->tool->run( $args + [ 'token' => $p['token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ [ 'id' => 21, 'title' => 'Post 21', 'kind' => 'page', 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
        $this->assertSame( $before, $this->state( $briefId ), 'page and brief bytes unchanged, no batch' );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testASaveFailureTouchesNothingElse(): void
    {
        $briefId = $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $tool    = new LabRestyle( $this->log, static fn ( int $id, string $c, string $t ): array => [ 'ok' => false, 'error' => 'update_failed' ], fn (): int => $this->now );
        $before  = $this->state( $briefId );

        $r = $this->applyRun( [ 'page_id' => 21, 'to_language' => 'luxe' ], $tool );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 0, $r['restyled'] );
        $this->assertSame( 'save_failed', $r['skipped'][0]['reason'] );
        $this->assertSame( $before, $this->state( $briefId ), 'brief unchanged, nothing recorded' );
    }

    public function testABriefThatCannotBeUpdatedIsReportedAndThePageStaysRestyled(): void
    {
        $briefId = $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $oldHash = (string) StudioStore::get( 1, $briefId )['content_hash'];
        $other   = $this->labBrief( 'editorial' );
        $other['title'] = 'Saved by someone else in the meantime';
        $write = function ( int $id, string $content, string $tool ) use ( $briefId, $other ): array {
            $r   = HistoryService::write( $id, $content, $tool );
            $row = StudioStore::get( 1, $briefId );
            $this->assertTrue( StudioStore::save( 1, $other, $briefId, $row['brief_hash'] )['ok'] );

            return $r;
        };
        $tool = new LabRestyle( $this->log, $write, fn (): int => $this->now );

        $r = $this->applyRun( [ 'page_id' => 21, 'to_language' => 'luxe' ], $tool );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 1, $r['restyled'] );
        $this->assertSame( 'brief_not_updated', $r['pages'][0]['warning'] );
        $this->assertStringContainsString( 'Lab', $r['pages'][0]['detail'] );
        $this->assertSame( $this->compiled( $this->labBrief( 'luxe' ) ), $this->layoutContent( 21 ), 'the page stays restyled' );
        $row = $this->briefRow( $briefId );
        $this->assertSame( $other, $row['brief'], 'the other save is kept' );
        $this->assertSame( $oldHash, $row['content_hash'], 'never linked to the restyled page, so Lab reads it as edited' );
        $this->assertNotSame( hash( 'sha256', $this->layoutContent( 21 ) ), $row['content_hash'] );
        $this->assertSame( [ 'post_content' ], array_column( $this->batchRow( $r )['items'], 'kind' ), 'no lab_brief item' );
    }

    public function testUndoRestoresPageAndBriefExactly(): void
    {
        $briefId = $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $page    = $this->layoutContent( 21 );
        $row     = $this->briefRow( $briefId );
        $r       = $this->applyRun( [ 'page_id' => 21, 'to_language' => 'luxe' ] );
        $this->assertNotSame( $page, $this->layoutContent( 21 ) );

        $u = $this->engine()->undo( $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'], (string) json_encode( $u ) );
        $this->assertSame( $page, $this->layoutContent( 21 ), 'page bytes back' );
        $this->assertSame( $row, $this->briefRow( $briefId ), 'brief JSON, brief hash and content hash back' );
        $this->assertSame( hash( 'sha256', $page ), $this->briefRow( $briefId )['content_hash'] );
    }

    public function testUndoOfTheUndoPutsTheNewLookBackOnPageAndBrief(): void
    {
        $briefId = $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $r       = $this->applyRun( [ 'page_id' => 21, 'to_language' => 'luxe' ] );
        $page    = $this->layoutContent( 21 );
        $row     = $this->briefRow( $briefId );

        $u = $this->engine()->undo( $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertIsArray( $u['undo_batch'] );
        $uu = $this->engine()->undo( $u['undo_batch']['id'] );
        $this->assertTrue( $uu['fully_undone'], (string) json_encode( $uu ) );
        $this->assertSame( $page, $this->layoutContent( 21 ) );
        $this->assertSame( $row, $this->briefRow( $briefId ) );
        $this->assertSame( hash( 'sha256', $page ), $this->briefRow( $briefId )['content_hash'] );
        $this->assertSame( 'luxe', $this->briefRow( $briefId )['brief']['language'] );
    }

    public function testAnUndoWhosePageWasEditedLeavesTheBriefUnmatched(): void
    {
        $briefId = $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $row     = $this->briefRow( $briefId );
        $r       = $this->applyRun( [ 'page_id' => 21, 'to_language' => 'luxe' ] );
        $GLOBALS['__wp_posts'][21]->post_content .= "\n<!-- edited after the restyle -->";
        $edited = $this->layoutContent( 21 );

        $u = $this->engine()->undo( $r['batch']['id'] );
        $this->assertFalse( $u['fully_undone'] );
        $results = array_column( $u['items'], 'result', 'kind' );
        $this->assertSame( 'changed_since', $results['post_content'] );
        $this->assertSame( 'restored', $results['lab_brief'] );
        $this->assertSame( $edited, $this->layoutContent( 21 ), 'the edit is never overwritten' );
        $this->assertSame( $row, $this->briefRow( $briefId ), 'the brief is back with the OLD content hash' );
        $this->assertNotSame( hash( 'sha256', $this->layoutContent( 21 ) ), $this->briefRow( $briefId )['content_hash'], 'so Lab reads the page as edited' );
    }

    public function testMcpAndRestGiveTheSamePayload(): void
    {
        $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ) );
        $registry = new ToolRegistry( [ $this->tool ], static fn (): bool => true );
        $args     = [ 'page_id' => 21, 'to_language' => 'luxe', 'dry_run' => true ];
        $mcp      = $registry->claim( null, 'lab_restyle', $args, 1 );
        $this->assertInstanceOf( \WP_REST_Response::class, $mcp );
        $fromMcp  = json_decode( (string) $mcp->get_data()['result']['content'][0]['text'], true );
        $rest     = ( new ProRest( $registry ) )->handle( 'lab_restyle', new \WP_REST_Request( $args ) );
        $this->assertInstanceOf( \WP_REST_Response::class, $rest );
        $fromRest = json_decode( (string) json_encode( $rest->get_data() ), true );
        $this->assertIsString( $fromMcp['token'] );
        $this->assertIsString( $fromRest['token'] );
        unset( $fromMcp['token'], $fromRest['token'] ); // each preview gets its own one-time nonce
        $this->assertSame( $fromRest, $fromMcp );

        // The real run gives the same shape through both doors.
        $p    = $this->tool->run( $args );
        $real = $registry->claim( null, 'lab_restyle', [ 'page_id' => 21, 'to_language' => 'luxe', 'token' => $p['token'] ], 3 );
        $out  = json_decode( (string) $real->get_data()['result']['content'][0]['text'], true );
        $this->assertTrue( $out['changed'] );
        $this->assertSame( 1, $out['restyled'] );

        $bad = $registry->claim( null, 'lab_restyle', [ 'page_id' => 21 ], 2 );
        $this->assertSame( -32602, $bad->get_data()['error']['code'] );
        $err = ( new ProRest( $registry ) )->handle( 'lab_restyle', new \WP_REST_Request( [ 'page_id' => 21 ] ) );
        $this->assertInstanceOf( \WP_Error::class, $err );
    }
}
