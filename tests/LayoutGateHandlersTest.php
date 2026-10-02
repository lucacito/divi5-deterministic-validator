<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/WpInsertPostShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** Every write and preview path refuses new custom code and agrees with the others. */
class LayoutGateHandlersTest extends TestCase
{
    private const CSS = ',"css":{"desktop":{"value":{"freeForm":"selector{color:red}"}}}';

    private static function layout( string $text = 'Hello', string $extra = '' ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} -->'
            . '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} -->'
            . '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $text . '"}}},"builderVersion":"5.14.0"' . $extra . '} /-->'
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    private function page( string $content ): void
    {
        $p               = new \WP_Post();
        $p->ID           = 5;
        $p->post_type    = 'page';
        $p->post_status  = 'publish';
        $p->post_title   = 'Page 5';
        $p->post_content = $content;
        $GLOBALS['__wp_posts'][5] = $p;
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']      = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_posts']        = [];
        $GLOBALS['__wp_postmeta']     = [];
        $GLOBALS['__wp_update_fail']  = false;
        $GLOBALS['__wp_update_calls'] = 0;
        $GLOBALS['__wp_inserts']      = [];
        $GLOBALS['__wp_can']          = true;
        unset( $GLOBALS['__wp_caps'] );
        $this->page( self::layout() );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']         = null;
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_can']     = true;
    }

    /** @return array{0:?int,1:array<string,mixed>} JSON-RPC error code (or null), decoded payload */
    private function mcp( string $tool, array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], [] ];
        }

        return [ null, (array) json_decode( $data['result']['content'][0]['text'], true ) ];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( string $method, array $body, array $params = [ 'id' => 5 ] ): array
    {
        $r = ( new RestController() )->$method( new \WP_REST_Request( $body, $params ) );
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private static function codes( array $payload ): array
    {
        return array_map( static fn ( $v ) => (string) $v['code'], (array) ( $payload['violations'] ?? [] ) );
    }

    private function content(): string
    {
        return $GLOBALS['__wp_posts'][5]->post_content;
    }

    private function historyRows(): int
    {
        return count( HistoryStore::summaries( 5 ) );
    }

    public function testTheStructureValidatorAloneWouldAcceptTheCss(): void
    {
        $this->assertTrue( ( new Validator() )->validateContent( self::layout( 'Hello', self::CSS ) )->isValid(), 'proves the refusals below come from the code policy' );
    }

    public function testValidateLayoutReportsTheViolationOverMcpAndRest(): void
    {
        [ , $m ] = $this->mcp( 'validate_layout', [ 'post_content' => self::layout( 'Hello', self::CSS ) ] );
        $this->assertFalse( $m['valid'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $m ) );

        [ $status, $r ] = $this->rest( 'validate', [ 'post_content' => self::layout( 'Hello', self::CSS ) ], [] );
        $this->assertSame( 422, $status );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $r ) );

        [ , $again ] = $this->rest( 'validate', [ 'post_content' => self::layout( 'Hello', self::CSS ), 'extra' => 'x' ], [] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $again ), 'the same content gives the same violations' );
    }

    public function testTheEnvelopeBranchOfRestValidateIsTheOneTakenWithoutPostContent(): void
    {
        // RestController::validate takes its envelope branch only when the body has `layout` and no
        // `post_content`; json_encode of that body has no post_content, so the structure validator
        // answers MISSING_POST_CONTENT (a full envelope with a string post_content always takes the
        // first branch). Proven here so the branch is not silently dropped or rewired.
        [ $status, $r ] = $this->rest( 'validate', [ 'layout' => [ 'source' => 'x' ] ], [] );
        $this->assertSame( 422, $status );
        $this->assertSame( [ 'MISSING_POST_CONTENT' ], self::codes( $r ) );
    }

    public function testCheckJsonRefusesTheCssInAFullEnvelope(): void
    {
        $envelope = (string) json_encode( [ 'source' => 'x', 'format' => 'gutenberg-blocks', 'post_content' => self::layout( 'Hello', self::CSS ) ] );
        $result   = \AiEditorDivi5\WP\LayoutGate::checkJson( $envelope );
        $this->assertFalse( $result->isValid() );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], array_map( static fn ( $v ) => $v->code(), $result->violations() ) );
    }

    public function testUpdateRefusesNewCssOverMcpAndRestAndSavesNothing(): void
    {
        $before = $this->content();
        [ , $m ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::layout( 'Hello', self::CSS ) ] );
        $this->assertFalse( $m['saved'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $m ) );

        [ $status, $r ] = $this->rest( 'update_page', [ 'post_content' => self::layout( 'Hello', self::CSS ) ] );
        $this->assertSame( 422, $status );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $r ) );

        $this->assertSame( $before, $this->content() );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( 0, $this->historyRows() );
    }

    public function testEditContentRefusesToInsertCss(): void
    {
        $find    = '"value":"Hello"}}},"builderVersion":"5.14.0"} /-->';
        $replace = '"value":"Hello"}}},"builderVersion":"5.14.0"' . self::CSS . '} /-->';
        [ , $m ] = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => $find, 'replace' => $replace ] );
        $this->assertFalse( $m['saved'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $m ) );

        [ $status, $r ] = $this->rest( 'edit_page', [ 'find' => $find, 'replace' => $replace ] );
        $this->assertSame( 422, $status );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $r ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testCreatePageRefusesCodeAndDoesNotCreate(): void
    {
        [ , $m ] = $this->mcp( 'create_page', [ 'title' => 'New', 'post_content' => self::layout( 'Hello', self::CSS ) ] );
        $this->assertFalse( $m['created'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $m ) );

        [ $status, $r ] = $this->rest( 'create_page', [ 'title' => 'New', 'post_content' => self::layout( 'Hello', self::CSS ) ], [] );
        $this->assertSame( 422, $status );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $r ) );

        $this->assertSame( [], $GLOBALS['__wp_inserts'], 'wp_insert_post was never called' );
        $this->assertSame( [ 5 ], array_keys( $GLOBALS['__wp_posts'] ), 'no post was created' );
    }

    public function testDryRunPreviewReportsTheViolationAndNeverWrites(): void
    {
        [ , $m ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::layout( 'Hello', self::CSS ), 'dry_run' => true ] );
        $this->assertTrue( $m['dry_run'] );
        $this->assertFalse( $m['valid'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $m ) );

        [ , $r ] = $this->rest( 'update_page', [ 'post_content' => self::layout( 'Hello', self::CSS ), 'dry_run' => true ] );
        $this->assertFalse( $r['valid'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $r ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testAScriptInTheTextIsRefused(): void
    {
        $text = '<script>alert(1)</script>';
        $bad  = self::layout( $text );
        $before = $this->content();
        [ , $m ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $bad ] );
        $this->assertFalse( $m['saved'] );
        $this->assertSame( [ 'SCRIPT_CONTENT_NOT_ALLOWED' ], self::codes( $m ) );
        $this->assertSame( $before, $this->content() );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    // ---- a page that already has custom CSS stays editable ------------------------------------

    public function testAPageThatAlreadyHasCssCanBeEditedWhenTheCssIsLeftUntouched(): void
    {
        $this->page( self::layout( 'Hello', self::CSS ) );
        [ , $m ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::layout( 'Hello again', self::CSS ) ] );
        $this->assertTrue( $m['saved'], 'the owner\'s existing CSS is preserved, not counted' );
        $this->assertStringContainsString( 'Hello again', $this->content() );
        $this->assertStringContainsString( 'selector{color:red}', $this->content() );
    }

    public function testAnEditOfSuchAPageCannotChangeTheCss(): void
    {
        $this->page( self::layout( 'Hello', self::CSS ) );
        $changed = str_replace( 'color:red', 'color:blue', self::layout( 'Hello', self::CSS ) );
        [ , $m ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $changed ] );
        $this->assertFalse( $m['saved'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $m ) );
        $this->assertStringContainsString( 'color:red', $this->content() );
    }

    public function testACleanSaveStillWorksAndIsSnapshotted(): void
    {
        [ , $m ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::layout( 'Brand new text' ) ] );
        $this->assertTrue( $m['saved'] );
        $this->assertStringContainsString( 'Brand new text', $this->content() );
        $this->assertSame( 1, $this->historyRows() );
    }

    // ---- the baseline reaches every write and preview path -------------------------------------

    private function cssPage(): void
    {
        $this->page( self::layout( 'Hello', self::CSS ) );
    }

    private function noWriteHappened( int $historyBefore = 0 ): void
    {
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( $historyBefore, $this->historyRows() );
    }

    public function testRestUpdateOnAPageWithCssSavesUntouchedCssAndRefusesChangedCss(): void
    {
        $this->cssPage();
        [ $status ] = $this->rest( 'update_page', [ 'post_content' => self::layout( 'Hello again', self::CSS ) ] );
        $this->assertSame( 200, $status );
        $this->assertStringContainsString( 'Hello again', $this->content() );
        $this->assertStringContainsString( 'selector{color:red}', $this->content() );

        $this->cssPage();
        $calls = $GLOBALS['__wp_update_calls'];
        $bad   = str_replace( 'color:red', 'color:blue', self::layout( 'Hello', self::CSS ) );
        [ $status, $r ] = $this->rest( 'update_page', [ 'post_content' => $bad ] );
        $this->assertSame( 422, $status );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $r ) );
        $this->assertSame( self::layout( 'Hello', self::CSS ), $this->content() );
        $this->assertSame( $calls, $GLOBALS['__wp_update_calls'], 'no wp_update_post call for the refused write' );
    }

    public function testEditContentOnAPageWithCssSavesATextEditAndRefusesACssEdit(): void
    {
        // MCP
        $this->cssPage();
        [ , $m ] = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => 'Hello', 'replace' => 'Goodbye' ] );
        $this->assertTrue( $m['saved'] );
        $this->assertStringContainsString( 'Goodbye', $this->content() );
        $this->assertStringContainsString( 'selector{color:red}', $this->content() );

        $this->cssPage();
        $calls = $GLOBALS['__wp_update_calls'];
        [ , $m ] = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => 'color:red', 'replace' => 'color:blue' ] );
        $this->assertFalse( $m['saved'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $m ) );
        $this->assertStringContainsString( 'color:red', $this->content() );
        $this->assertSame( $calls, $GLOBALS['__wp_update_calls'] );

        // REST
        $this->cssPage();
        [ $status ] = $this->rest( 'edit_page', [ 'find' => 'Hello', 'replace' => 'Goodbye' ] );
        $this->assertSame( 200, $status );
        $this->assertStringContainsString( 'Goodbye', $this->content() );
        $this->assertStringContainsString( 'selector{color:red}', $this->content() );

        $this->cssPage();
        $calls = $GLOBALS['__wp_update_calls'];
        [ $status, $r ] = $this->rest( 'edit_page', [ 'find' => 'color:red', 'replace' => 'color:blue' ] );
        $this->assertSame( 422, $status );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $r ) );
        $this->assertStringContainsString( 'color:red', $this->content() );
        $this->assertSame( $calls, $GLOBALS['__wp_update_calls'] );
    }

    /** @return array<string,array{0:string}> */
    public static function dryRunPaths(): array
    {
        return [ 'mcp update' => [ 'mcp_update' ], 'mcp edit' => [ 'mcp_edit' ], 'rest update' => [ 'rest_update' ] ];
    }

    /**
     * One call on one path. $css false = leave the CSS untouched (text edit), true = change the CSS value.
     *
     * @return array<string,mixed> the decoded payload (MCP) or response data (REST)
     */
    private function callPath( string $path, bool $changeCss, bool $dry ): array
    {
        $flag = $dry ? [ 'dry_run' => true ] : [];
        switch ( $path ) {
            case 'mcp_update':
                $content = $changeCss ? str_replace( 'color:red', 'color:blue', self::layout( 'Hello', self::CSS ) ) : self::layout( 'Hello again', self::CSS );
                return $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $content ] + $flag )[1];
            case 'mcp_edit':
                $args = $changeCss ? [ 'find' => 'color:red', 'replace' => 'color:blue' ] : [ 'find' => 'Hello', 'replace' => 'Hello again' ];
                return $this->mcp( 'edit_page_content', [ 'page_id' => 5 ] + $args + $flag )[1];
            default:
                $content = $changeCss ? str_replace( 'color:red', 'color:blue', self::layout( 'Hello', self::CSS ) ) : self::layout( 'Hello again', self::CSS );
                return $this->rest( 'update_page', [ 'post_content' => $content ] + $flag )[1];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'dryRunPaths' )]
    public function testDryRunOnAPageWithCssAgreesWithTheRealWrite( string $path ): void
    {
        // Untouched CSS: the preview is valid with no CSS violation, writes nothing, and the real write is saved.
        $this->cssPage();
        $preview = $this->callPath( $path, false, true );
        $this->assertTrue( $preview['valid'], $path );
        $this->assertSame( [], self::codes( $preview ), $path );
        $this->noWriteHappened();
        $this->assertSame( self::layout( 'Hello', self::CSS ), $this->content() );

        $real = $this->callPath( $path, false, false );
        $this->assertTrue( $real['saved'] ?? false, $path . ': the real write agrees with the preview' );

        // Changed CSS: the preview reports the CSS violation, writes nothing, and the real write is refused with the same codes.
        $this->cssPage();
        $GLOBALS['__wp_update_calls'] = 0;
        $history = $this->historyRows();
        $preview = $this->callPath( $path, true, true );
        $this->assertFalse( $preview['valid'], $path );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $preview ), $path );
        $this->noWriteHappened( $history );
        $this->assertSame( self::layout( 'Hello', self::CSS ), $this->content() );

        $real = $this->callPath( $path, true, false );
        $this->assertFalse( $real['saved'] ?? true, $path );
        $this->assertSame( self::codes( $preview ), self::codes( $real ), $path . ': preview and real write give the same violations' );
        $this->noWriteHappened( $history );
    }
}
