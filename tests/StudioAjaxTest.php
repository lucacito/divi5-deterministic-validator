<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryCapture;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\StudioAjax;
use AiEditorDivi5\WP\StudioStore;
use AiEditorDivi5\WP\StudioTable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/WpInsertPostShims.php';
require_once __DIR__ . '/GetPostsShim.php';
require_once __DIR__ . '/WpPreviewShims.php';
require_once __DIR__ . '/WpAjaxShims.php';
require_once __DIR__ . '/../wp-plugin/src/autoload.php';

/**
 * Divi 5 Lab (Studio), plan 2 task 6: the admin-ajax handlers. Every action needs the nonce and manage_options; create
 * and rebuild also edit_pages; the user is always the signed-in one; answers are data only.
 */
class StudioAjaxTest extends TestCase
{
    private const ME    = 5;
    private const OTHER = 6;

    /** every action and whether it also needs edit_pages */
    private const ACTIONS = [
        'stack'   => false,
        'preview' => false,
        'save'    => false,
        'create'  => true,
        'rebuild' => true,
        'handoff' => false,
        'brief'   => false,
    ];

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createStudioTable();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']      = [
            StudioTable::DB_VERSION_OPTION  => StudioTable::DB_VERSION,
            HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED,
        ];
        $GLOBALS['__wp_transients']   = [];
        $GLOBALS['__wp_filters']      = [];
        $GLOBALS['__wp_posts']        = [];
        $GLOBALS['__wp_postmeta']     = [];
        $GLOBALS['__wp_inserts']      = [];
        $GLOBALS['__wp_deleted']      = [];
        $GLOBALS['__wp_update_fail']  = false;
        $GLOBALS['__get_posts_calls'] = [];
        $GLOBALS['__get_posts_handler'] = 'aied_preview_shim_get_posts';
        $GLOBALS['__wp_current_user_id'] = self::ME;
        $GLOBALS['__wp_caps']         = [ 'manage_options' => true, 'edit_pages' => true ];
        $GLOBALS['__ajax_nonce_ok']   = true;
        unset( $GLOBALS['__wp_update_calls'], $GLOBALS['__wp_insert_fail'], $GLOBALS['__wp_user_caps'], $GLOBALS['__ajax_nonce_action'], $GLOBALS['__ajax_nonce_arg'], $GLOBALS['__get_posts_loose'] );
        $_POST = [];
        HistoryCapture::reset();
    }

    protected function tearDown(): void
    {
        HistoryCapture::reset();
        $_POST = [];
        $GLOBALS['wpdb']                = null;
        $GLOBALS['__wp_options']        = [];
        $GLOBALS['__wp_transients']     = [];
        $GLOBALS['__wp_posts']          = [];
        $GLOBALS['__wp_postmeta']       = [];
        $GLOBALS['__wp_inserts']        = [];
        $GLOBALS['__wp_deleted']        = [];
        $GLOBALS['__get_posts_handler'] = null;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__ajax_nonce_ok'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__wp_update_calls'], $GLOBALS['__wp_insert_fail'] );
    }

    /** @return array<string,mixed> */
    private static function brief( string $title = 'Acme plumbing' ): array
    {
        return [
            'title'    => $title,
            'goal'     => 'book',
            'language' => 'editorial',
            'sections' => [
                [ 'recipe' => 'hero-cta', 'slots' => [ 'heading.1' => 'Fix leaks fast', 'button.0.text' => 'Book now', 'button.0.link' => '/contact/' ] ],
                [ 'recipe' => 'card-grid-3' ],
            ],
        ];
    }

    /** Calls the handler of $action and returns what it sent. */
    private function call( string $action, array $post = [] ): \AiedAjaxResponse
    {
        $_POST = $post;
        try {
            call_user_func( [ StudioAjax::class, 'handle' . ucfirst( $action ) ] );
        } catch ( \AiedAjaxResponse $r ) {
            return $r;
        }
        $this->fail( "Handler $action sent nothing." );
    }

    /** A valid request for $action (a stored brief id for the ones that need it). */
    private function validPost( string $action, int $id = 0 ): array
    {
        return match ( $action ) {
            'stack'   => [ 'goal' => 'book', 'density' => 'balanced' ],
            'preview', 'save' => [ 'brief' => json_encode( self::brief() ) ],
            'create'  => [ 'brief' => json_encode( self::brief() ) ],
            'rebuild', 'handoff', 'brief' => [ 'id' => (string) $id ],
        };
    }

    private function storedBrief( int $user = self::ME ): int
    {
        $r = StudioStore::save( $user, self::brief() );
        $this->assertTrue( $r['ok'] );

        return $r['id'];
    }

    // ---- gates ----

    public function testRegistersOnlyLoggedInAjaxActions(): void
    {
        $GLOBALS['__wp_filters'] = [];
        StudioAjax::register();
        foreach ( array_keys( self::ACTIONS ) as $a ) {
            $this->assertSame( [ [ StudioAjax::class, 'handle' . ucfirst( $a ) ] ], $GLOBALS['__wp_filters'][ 'wp_ajax_aied_studio_' . $a ] ?? null, $a );
            $this->assertArrayNotHasKey( 'wp_ajax_nopriv_aied_studio_' . $a, $GLOBALS['__wp_filters'] );
        }
        $this->assertCount( count( self::ACTIONS ), $GLOBALS['__wp_filters'] );
    }

    public function testEveryActionRefusesAMissingNonce(): void
    {
        $id = $this->storedBrief();
        $GLOBALS['__ajax_nonce_ok'] = false;
        foreach ( array_keys( self::ACTIONS ) as $a ) {
            $r = $this->call( $a, $this->validPost( $a, $id ) );
            $this->assertFalse( $r->success, $a );
            $this->assertSame( 403, $r->status, $a );
            $this->assertSame( 'ai_editor_divi5_studio', $GLOBALS['__ajax_nonce_action'], $a );
        }
        $this->assertSame( [], $GLOBALS['__wp_inserts'], 'nothing was written' );
    }

    public function testEveryActionRefusesALowCapability(): void
    {
        $id = $this->storedBrief();
        foreach ( [ [], [ 'edit_pages' => true ] ] as $caps ) {
            $GLOBALS['__wp_caps'] = $caps;
            foreach ( array_keys( self::ACTIONS ) as $a ) {
                $r = $this->call( $a, $this->validPost( $a, $id ) );
                $this->assertFalse( $r->success, $a );
                $this->assertSame( 403, $r->status, $a );
            }
        }
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
    }

    public function testCreateNeedsEditPages(): void
    {
        $GLOBALS['__wp_caps'] = [ 'manage_options' => true ];
        $id = $this->storedBrief();
        foreach ( [ 'create', 'rebuild' ] as $a ) {
            $r = $this->call( $a, $this->validPost( $a, $id ) );
            $this->assertSame( 403, $r->status, $a );
        }
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
        // the others do not need it
        $this->assertTrue( $this->call( 'stack', $this->validPost( 'stack' ) )->success );
        $this->assertTrue( $this->call( 'save', $this->validPost( 'save' ) )->success );
    }

    // ---- strict input ----

    public function testNonStringInputIs400(): void
    {
        $id = $this->storedBrief();
        $cases = [
            [ 'stack',   [ 'goal' => [ 'book' ], 'density' => 'balanced' ] ],
            [ 'stack',   [ 'goal' => 'book', 'density' => [ 'dense' ] ] ],
            [ 'stack',   [ 'goal' => 5 ] ],
            [ 'stack',   [] ],
            [ 'preview', [ 'brief' => self::brief() ] ],
            [ 'preview', [] ],
            [ 'save',    [ 'brief' => [ 'x' ] ] ],
            [ 'save',    [ 'brief' => json_encode( self::brief() ), 'id' => [ '1' ] ] ],
            [ 'create',  [ 'brief' => 123 ] ],
            [ 'create',  [ 'brief' => json_encode( self::brief() ), 'id' => [ $id ] ] ],
            [ 'rebuild', [ 'id' => [ '1' ] ] ],
            [ 'rebuild', [ 'id' => '12abc' ] ],
            [ 'rebuild', [ 'id' => '-1' ] ],
            [ 'rebuild', [] ],
            [ 'handoff', [ 'id' => [ '1' ], 'site' => 'https://evil.example' ] ],
            [ 'brief',   [ 'id' => [ '1' ] ] ],
            [ 'brief',   [ 'id' => '' ] ],
        ];
        foreach ( $cases as [ $a, $post ] ) {
            $r = $this->call( $a, $post );
            $this->assertFalse( $r->success, $a . json_encode( $post ) );
            $this->assertSame( 400, $r->status, $a . json_encode( $post ) );
        }
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
    }

    public function testMalformedJsonIs400AndTooDeepJsonIs400(): void
    {
        foreach ( [ 'preview', 'save', 'create' ] as $a ) {
            foreach ( [ '{not json', '', '"a string"', '12', 'null', str_repeat( '[', 100 ) . str_repeat( ']', 100 ) ] as $bad ) {
                $r = $this->call( $a, [ 'brief' => $bad ] );
                $this->assertSame( 400, $r->status, $a . ' ' . $bad );
            }
        }
    }

    public function testBriefOverTwoHundredKilobytesIs413BeforeDecoding(): void
    {
        $big = '{"title":"' . str_repeat( 'a', StudioStore::MAX_BYTES ) . '"}';
        foreach ( [ 'preview', 'save', 'create' ] as $a ) {
            $r = $this->call( $a, [ 'brief' => $big ] );
            $this->assertSame( 413, $r->status, $a );
            $this->assertStringNotContainsString( 'aaaa', json_encode( $r->data ) );
        }
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
    }

    public function testInvalidBriefIs422WithProblemsAsPlainData(): void
    {
        $bad = self::brief();
        $bad['goal'] = 'nonsense';
        $bad['<img src=x onerror=1>'] = 'x';
        $r = $this->call( 'save', [ 'brief' => json_encode( $bad ) ] );
        $this->assertFalse( $r->success );
        $this->assertSame( 422, $r->status );
        $this->assertNotEmpty( $r->data['problems'] );
        $this->assertStringNotContainsString( '<', json_encode( $r->data['problems'] ) );
        $this->assertStringNotContainsString( '>', json_encode( $r->data['problems'] ) );
        $this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . StudioTable::name() ) );
    }

    // ---- ownership ----

    public function testBriefOfAnotherUserIs404(): void
    {
        $theirs = $this->storedBrief( self::OTHER );
        $missing = $theirs + 1000;
        $answers = [];
        foreach ( [ 'brief', 'rebuild', 'handoff' ] as $a ) {
            $one = $this->call( $a, [ 'id' => (string) $theirs ] );
            $two = $this->call( $a, [ 'id' => (string) $missing ] );
            $this->assertSame( 404, $one->status, $a );
            $this->assertSame( 404, $two->status, $a );
            $this->assertSame( json_encode( $one->data ), json_encode( $two->data ), $a . ': another user\'s id looks exactly like a missing one' );
        }
        // saving or creating over another user's brief id is the same 404
        foreach ( [ 'save', 'create' ] as $a ) {
            $r = $this->call( $a, [ 'brief' => json_encode( self::brief() ), 'id' => (string) $theirs ] );
            $this->assertSame( 404, $r->status, $a );
        }
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
    }

    public function testUserComesFromTheSessionNeverTheRequest(): void
    {
        $theirs = $this->storedBrief( self::OTHER );
        $r = $this->call( 'save', [ 'brief' => json_encode( self::brief() ), 'user' => (string) self::OTHER, 'user_id' => (string) self::OTHER, 'created_by' => (string) self::OTHER ] );
        $this->assertTrue( $r->success );
        $mine = StudioStore::get( self::ME, (int) $r->data['id'] );
        $this->assertNotNull( $mine );
        $this->assertNull( StudioStore::get( self::OTHER, (int) $r->data['id'] ) );
        $this->assertNotNull( StudioStore::get( self::OTHER, $theirs ) );
    }

    // ---- behaviour ----

    public function testStackReturnsStepsWithVariants(): void
    {
        $r = $this->call( 'stack', [ 'goal' => 'book', 'density' => 'airy' ] );
        $this->assertTrue( $r->success );
        $steps = $r->data['stack'];
        $this->assertNotEmpty( $steps );
        foreach ( $steps as $s ) {
            $this->assertIsString( $s['recipe'] );
            $this->assertIsString( $s['role'] );
            $this->assertNotEmpty( $s['variants'] );
            $this->assertContains( $s['recipe'], array_column( $s['variants'], 'recipe' ) );
        }
        $this->assertSame( 400, $this->call( 'stack', [ 'goal' => 'nope', 'density' => 'airy' ] )->status );
        $this->assertSame( 400, $this->call( 'stack', [ 'goal' => 'book', 'density' => 'wild' ] )->status );
        // density is optional
        $this->assertTrue( $this->call( 'stack', [ 'goal' => 'sell' ] )->success );
    }

    public function testPreviewReturnsReportAndPreviewAndWritesNoBrief(): void
    {
        $r = $this->call( 'preview', [ 'brief' => json_encode( self::brief() ) ] );
        $this->assertTrue( $r->success, json_encode( $r->data ) );
        $this->assertArrayHasKey( 'report', $r->data );
        $this->assertSame( 'draft', $r->data['preview']['mode'] );
        $this->assertIsString( $r->data['preview']['url'] );
        $this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . StudioTable::name() ) );
        $this->assertArrayNotHasKey( 'markup', $r->data, 'the markup itself is not sent' );
        // the preview draft belongs to the signed-in user
        $titles = array_map( static fn ( $p ) => $p->post_title, $GLOBALS['__wp_posts'] );
        $this->assertSame( [ 'Divi 5 Lab preview (temporary) ' . self::ME ], array_values( $titles ) );
    }

    public function testPreviewOfAnInvalidBriefIs422WithTheReportAndNoDraft(): void
    {
        $bad = self::brief();
        $bad['sections'][0]['recipe'] = 'no-such-recipe';
        $r = $this->call( 'preview', [ 'brief' => json_encode( $bad ) ] );
        $this->assertFalse( $r->success );
        $this->assertSame( 422, $r->status );
        $this->assertArrayHasKey( 'code', $r->data );
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
    }

    public function testPreviewApproximationHtmlIsEscapedInertText(): void
    {
        $b = self::brief( 'x' );
        $b['sections'][0]['slots']['heading.1'] = '<script>alert(1)</script>';
        $GLOBALS['__wp_insert_fail'] = true; // the draft cannot be made: the labelled fallback is used
        $r = $this->call( 'preview', [ 'brief' => json_encode( $b ) ] );
        $this->assertTrue( $r->success, json_encode( $r->data ) );
        $this->assertSame( 'approximation', $r->data['preview']['mode'] );
        $this->assertStringNotContainsString( '<script>', (string) $r->data['preview']['html'] );
    }

    public function testSaveStoresForTheCurrentUserAndCanUpdate(): void
    {
        $r = $this->call( 'save', [ 'brief' => json_encode( self::brief() ) ] );
        $this->assertTrue( $r->success );
        $id = (int) $r->data['id'];
        $this->assertGreaterThan( 0, $id );
        $again = $this->call( 'save', [ 'brief' => json_encode( self::brief( 'Renamed' ) ), 'id' => (string) $id ] );
        $this->assertTrue( $again->success );
        $this->assertSame( $id, (int) $again->data['id'] );
        $this->assertSame( 'Renamed', StudioStore::get( self::ME, $id )['brief']['title'] );
    }

    public function testCreateMakesADraftAndRemovesThePreviewDraft(): void
    {
        $this->call( 'preview', [ 'brief' => json_encode( self::brief() ) ] );
        $this->assertCount( 1, $GLOBALS['__wp_posts'] );
        $r = $this->call( 'create', [ 'brief' => json_encode( self::brief() ) ] );
        $this->assertTrue( $r->success, json_encode( $r->data ) );
        $this->assertGreaterThan( 0, (int) $r->data['page_id'] );
        $this->assertGreaterThan( 0, (int) $r->data['brief_id'] );
        $this->assertArrayHasKey( 'report', $r->data );
        $page = $GLOBALS['__wp_posts'][ (int) $r->data['page_id'] ] ?? null;
        $this->assertNotNull( $page );
        $this->assertSame( 'draft', $page->post_status );
        $titles = array_map( static fn ( $p ) => $p->post_title, $GLOBALS['__wp_posts'] );
        $this->assertNotContains( 'Divi 5 Lab preview (temporary) ' . self::ME, $titles, 'cleanup($user) ran after the create' );
    }

    public function testCreateFromAStoredBriefAndRebuild(): void
    {
        $id = $this->storedBrief();
        $c = $this->call( 'create', [ 'brief' => json_encode( self::brief() ), 'id' => (string) $id ] );
        $this->assertTrue( $c->success, json_encode( $c->data ) );
        $this->assertSame( $id, (int) $c->data['brief_id'] );
        $r = $this->call( 'rebuild', [ 'id' => (string) $id ] );
        $this->assertTrue( $r->success, json_encode( $r->data ) );
        $this->assertArrayHasKey( 'mode', $r->data );
        $this->assertArrayHasKey( 'report', $r->data );
    }

    public function testHandoffUsesTheSiteAddressNotTheRequest(): void
    {
        $id = $this->storedBrief();
        $r = $this->call( 'handoff', [ 'id' => (string) $id, 'site' => 'https://evil.example' ] );
        $this->assertTrue( $r->success );
        $this->assertStringContainsString( (string) $id, $r->data['instruction'] );
        $this->assertStringContainsString( home_url(), $r->data['instruction'] );
        $this->assertStringNotContainsString( 'evil.example', $r->data['instruction'] );
    }

    public function testBriefActionReturnsTheStoredBriefAsData(): void
    {
        $id = $this->storedBrief();
        $r = $this->call( 'brief', [ 'id' => (string) $id ] );
        $this->assertTrue( $r->success );
        $this->assertSame( $id, (int) $r->data['id'] );
        $this->assertSame( 'Acme plumbing', $r->data['brief']['title'] );
        $this->assertArrayHasKey( 'page_id', $r->data );
    }

    public function testResponsesNeverEchoUnescapedHtml(): void
    {
        $evil = '<script>alert(1)</script>';
        $b = self::brief();
        $b['sections'][0]['slots']['heading.1'] = $evil;
        $saved = $this->call( 'save', [ 'brief' => json_encode( $b ) ] );
        $this->assertTrue( $saved->success );
        // success answers carry ids and plain words only
        $this->assertStringNotContainsString( 'script', json_encode( $saved->data ) );
        // the stored brief comes back as JSON data (an array of values), not as markup
        $got = $this->call( 'brief', [ 'id' => (string) $saved->data['id'] ] );
        $this->assertIsArray( $got->data );
        $this->assertSame( $evil, $got->data['brief']['sections'][0]['slots']['heading.1'], 'data is passed through untouched, for textContent' );
        foreach ( [ 'message' ] as $k ) {
            $this->assertStringNotContainsString( '<', (string) ( $got->data[ $k ] ?? '' ) );
        }
        // errors and previews: no word of the brief is echoed in a message
        foreach ( [ 'preview', 'create', 'save' ] as $a ) {
            $x = $b;
            $x['goal'] = 'nope';
            $r = $this->call( $a, [ 'brief' => json_encode( $x ) ] );
            $this->assertFalse( $r->success, $a );
            $this->assertStringNotContainsString( 'script', (string) ( $r->data['message'] ?? '' ), $a );
        }
        $this->assertStringNotContainsString( 'script', (string) $this->call( 'preview', [ 'brief' => '{bad' . $evil ] )->data['message'] );
        // a problem list built from a hostile goal or key has its angle brackets removed
        $hostile = $b;
        $hostile['goal'] = $evil;
        $hostile[ $evil ] = 1;
        $r = $this->call( 'save', [ 'brief' => json_encode( $hostile ) ] );
        $this->assertSame( 422, $r->status );
        $this->assertStringNotContainsString( '<', json_encode( $r->data ) );
        $this->assertStringNotContainsString( '>', json_encode( $r->data ) );
        $this->assertStringNotContainsString( 'script', (string) $this->call( 'brief', [ 'id' => $evil ] )->data['message'] );
    }

    // ---- housekeeping ----

    public function testStaleSweepRunsAtMostOncePerHour(): void
    {
        $old = gmdate( 'Y-m-d H:i:s', time() - 30 * 3600 );
        $mk = static function ( int $id, int $author ) use ( $old ): void {
            $p = new \WP_Post();
            $p->ID = $id; $p->post_type = 'page'; $p->post_status = 'draft';
            $p->post_title = 'Divi 5 Lab preview (temporary) ' . $author; $p->post_author = $author;
            $p->post_content = ''; $p->post_modified_gmt = $old;
            $GLOBALS['__wp_posts'][ $id ] = $p;
        };
        $mk( 301, 9 );
        $this->call( 'preview', [ 'brief' => json_encode( self::brief() ) ] );
        $this->assertArrayNotHasKey( 301, $GLOBALS['__wp_posts'], 'the first preview swept a stale draft' );
        $this->assertNotFalse( get_transient( StudioAjax::SWEEP_TRANSIENT ) );

        $mk( 302, 9 );
        $this->call( 'preview', [ 'brief' => json_encode( self::brief() ) ] );
        $this->assertArrayHasKey( 302, $GLOBALS['__wp_posts'], 'a second preview within the hour does not sweep again' );

        unset( $GLOBALS['__wp_transients'][ StudioAjax::SWEEP_TRANSIENT ] );
        $this->call( 'preview', [ 'brief' => json_encode( self::brief() ) ] );
        $this->assertArrayNotHasKey( 302, $GLOBALS['__wp_posts'], 'after the guard expires it sweeps again' );
    }

    public function testOtherActionsNeverSweep(): void
    {
        $old = gmdate( 'Y-m-d H:i:s', time() - 30 * 3600 );
        $p = new \WP_Post();
        $p->ID = 301; $p->post_type = 'page'; $p->post_status = 'draft';
        $p->post_title = 'Divi 5 Lab preview (temporary) 9'; $p->post_author = 9;
        $p->post_content = ''; $p->post_modified_gmt = $old;
        $GLOBALS['__wp_posts'][301] = $p;
        $this->call( 'stack', [ 'goal' => 'book' ] );
        $this->call( 'save', [ 'brief' => json_encode( self::brief() ) ] );
        $this->assertArrayHasKey( 301, $GLOBALS['__wp_posts'] );
    }

    public function testSourceHasNoNoprivAndNoSiteFromTheRequest(): void
    {
        $src = (string) file_get_contents( __DIR__ . '/../wp-plugin/src/StudioAjax.php' );
        $this->assertStringNotContainsString( 'nopriv', $src );
        $this->assertStringNotContainsString( "\$_POST['user", $src );
        $this->assertStringNotContainsString( "\$_REQUEST", $src );
        $this->assertStringContainsString( 'home_url()', $src );
        $this->assertDoesNotMatchRegularExpression( '/__\(\s*\'[^\']*Studio/', $src, 'people-facing strings say Divi 5 Lab' );
    }
}
