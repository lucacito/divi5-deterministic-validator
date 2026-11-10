<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Lab\LabCompile;
use AiEditorDivi5\Pro\Lab\LabSupport;
use AiEditorDivi5\Pro\Tools\LabRestyle;
use AiEditorDivi5\Pro\Tools\SwitchDesign;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\PageHistory;
use AiEditorDivi5\WP\StudioStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/LabFixture.php';

/**
 * Pro Lab Task 4: `lab_restyle` arguments, plan and preview (spec 2026-11-10-pro-lab-design.md section A). No page is
 * written in a preview; the apply is Task 5.
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
}
