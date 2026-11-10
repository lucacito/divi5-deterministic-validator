<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
use AiEditorDivi5\WP\RestController;
use AiEditorDivi5\WP\StudioScreen;
use AiEditorDivi5\WP\StudioService;
use AiEditorDivi5\WP\StudioStore;
use AiEditorDivi5\WP\StudioTable;
use AiEditorDivi5\WP\ToolCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/WpInsertPostShims.php';
require_once __DIR__ . '/../wp-plugin/src/autoload.php';

/** lab_build: the same meaning in MCP and REST, strict arguments, drafts only, and not in the ChatGPT spec on purpose. */
final class LabBuildLockstepTest extends TestCase
{
    private const ME = 5;

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createStudioTable();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']         = [
            StudioTable::DB_VERSION_OPTION  => StudioTable::DB_VERSION,
            HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED,
        ];
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_posts']           = [];
        $GLOBALS['__wp_postmeta']        = [];
        $GLOBALS['__wp_inserts']         = [];
        $GLOBALS['__wp_can']             = true;
        $GLOBALS['__wp_current_user_id'] = self::ME;
        unset( $GLOBALS['__wp_caps'] );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']            = null;
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        $GLOBALS['__wp_can']        = true;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'] );
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    /** @return array{0:?int,1:array<string,mixed>,2:bool} JSON-RPC error code, payload, isError */
    private function mcp( array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'lab_build', 'arguments' => $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], [ 'error' => $data['error']['message'] ], true ];
        }

        return [ null, (array) json_decode( $data['result']['content'][0]['text'], true ), (bool) ( $data['result']['isError'] ?? false ) ];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( array $body ): array
    {
        $r = ( new RestController() )->lab_build( new \WP_REST_Request( $body ) );
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->get_error_message() ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array<string,mixed> */
    private static function brief(): array
    {
        return [
            'title'    => 'Acme plumbing',
            'goal'     => 'book',
            'language' => 'editorial',
            'sections' => [
                [ 'recipe' => 'hero-cta', 'slots' => [ 'heading.1' => 'Fix leaks fast', 'button.0.text' => 'Book now', 'button.0.link' => '/contact/' ] ],
                [ 'recipe' => 'card-grid-3' ],
            ],
        ];
    }

    public function testMcpDeclaresAndDispatchesTheTool(): void
    {
        $mcp = $this->src( 'McpHandler.php' ) . $this->src( 'ToolCatalog.php' );
        $this->assertStringContainsString( "'name'        => 'lab_build'", $mcp );
        $this->assertMatchesRegularExpression( "/'lab_build'\s*=>\s*\\\$this->toolLabBuild/", $mcp );

        $req   = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ] );
        $tools = ( new McpHandler() )->handle( $req )->get_data()['result']['tools'];
        $tool  = current( array_filter( $tools, static fn( $t ) => 'lab_build' === $t['name'] ) );
        $this->assertNotFalse( $tool );
        $this->assertSame( [ 'action' ], $tool['inputSchema']['required'] );
        $this->assertSame( [ 'action', 'recipe', 'role', 'brief_id', 'base_hash', 'sections', 'brief', 'create' ], array_keys( $tool['inputSchema']['properties'] ) );
        $this->assertSame( [ 'catalog', 'open', 'fill', 'build' ], $tool['inputSchema']['properties']['action']['enum'] );
        $this->assertSame( 'integer', $tool['inputSchema']['properties']['brief_id']['type'] );
        $this->assertSame( 1, $tool['inputSchema']['properties']['brief_id']['minimum'] );
        $this->assertSame( 'boolean', $tool['inputSchema']['properties']['create']['type'] );
        $this->assertLessThanOrEqual( 1400, strlen( (string) $tool['description'] ) );
        $this->assertStringNotContainsString( 'Studio', (string) $tool['description'] );
    }

    public function testRestRegistersAPostRoute(): void
    {
        $this->assertMatchesRegularExpression( "/'\/lab-build',\s*\[\s*'methods'\s*=>\s*WP_REST_Server::CREATABLE/", $this->src( 'RestController.php' ) );
    }

    public function testItIsNotInTheChatGptSpecOnPurpose(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $this->assertArrayNotHasKey( '/lab-build', $spec['paths'] );
        $this->assertStringNotContainsString( 'lab-build', (string) json_encode( $spec ) );
        $this->assertStringNotContainsString( 'lab_build', (string) json_encode( $spec ) );
        $ops = 0;
        foreach ( $spec['paths'] as $methods ) {
            $ops += count( $methods );
        }
        $this->assertSame( 16, $ops, 'the importer budget is unchanged' );
    }

    public function testTheToolIsInTheCatalogAndTheScreenHandOffIsNowOffered(): void
    {
        $this->assertNotNull( ToolCatalog::get( 'lab_build' ) );
        $this->assertSame( StudioService::TOOL, 'lab_build' );
        $this->assertTrue( StudioScreen::assistantReady() );
    }

    public function testACatalogCallMeansTheSameInBothSurfaces(): void
    {
        [ $code, $m, $isError ] = $this->mcp( [ 'action' => 'catalog' ] );
        $this->assertNull( $code );
        $this->assertFalse( $isError );
        [ $status, $r ] = $this->rest( [ 'action' => 'catalog' ] );
        $this->assertSame( 200, $status );
        $this->assertSame( $m, $r );
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function badArguments(): array
    {
        return [
            'action is missing'     => [ [] ],
            'action is unknown'     => [ [ 'action' => 'delete' ] ],
            'action is an array'    => [ [ 'action' => [ 'catalog' ] ] ],
            'brief_id is a string'  => [ [ 'action' => 'open', 'brief_id' => '3' ] ],
            'brief_id is zero'      => [ [ 'action' => 'open', 'brief_id' => 0 ] ],
            'create is a string'    => [ [ 'action' => 'build', 'create' => 'true', 'brief' => [ 'title' => 'T' ] ] ],
            'sections is a string'  => [ [ 'action' => 'fill', 'brief_id' => 1, 'sections' => 'x' ] ],
            'brief is a list'       => [ [ 'action' => 'build', 'brief' => [ 1, 2 ] ] ],
            'an unknown argument'   => [ [ 'action' => 'catalog', 'url' => 'https://example.com' ] ],
        ];
    }

    #[DataProvider('badArguments')]
    public function testWrongArgumentsAreRefusedNeverCast( array $args ): void
    {
        [ $code, $m ] = $this->mcp( $args );
        $this->assertSame( -32602, $code );
        $this->assertNotSame( '', $m['error'] );
        [ $status ] = $this->rest( $args );
        $this->assertSame( 400, $status );
        $this->assertSame( [], $GLOBALS['__wp_inserts'], 'nothing was created' );
    }

    public function testWithoutPermissionNothingHappens(): void
    {
        $GLOBALS['__wp_can'] = false;
        [ $code ] = $this->mcp( [ 'action' => 'catalog' ] );
        $this->assertSame( -32602, $code );
        [ $status ] = $this->rest( [ 'action' => 'catalog' ] );
        $this->assertSame( 403, $status );
    }

    public function testCreatingNeedsTheRightToPublishPages(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        [ , , $isError ] = $this->mcp( [ 'action' => 'catalog' ] );
        $this->assertFalse( $isError );
        [ $code ] = $this->mcp( [ 'action' => 'build', 'create' => true, 'brief' => self::brief() ] );
        $this->assertSame( -32602, $code );
        [ $status ] = $this->rest( [ 'action' => 'build', 'create' => true, 'brief' => self::brief() ] );
        $this->assertSame( 403, $status );
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
    }

    public function testAMissingBriefIsAPlainNotFoundResultNotAProtocolError(): void
    {
        [ $code, $m, $isError ] = $this->mcp( [ 'action' => 'open', 'brief_id' => 99 ] );
        $this->assertNull( $code );
        $this->assertTrue( $isError );
        $this->assertArrayHasKey( 'error', $m );
        [ $status ] = $this->rest( [ 'action' => 'open', 'brief_id' => 99 ] );
        $this->assertSame( 404, $status );
    }

    public function testAConflictIsAPlainResultInMcpAnd409InRest(): void
    {
        $saved = StudioStore::save( self::ME, self::brief() );
        $this->assertTrue( $saved['ok'] );
        $id = $saved['id'];

        [ , $open ] = $this->mcp( [ 'action' => 'open', 'brief_id' => $id ] );
        $stale = (string) $open['base_hash'];

        // The person saves in the Lab screen meanwhile.
        $changed             = self::brief();
        $changed['title']    = 'Acme plumbing, new title';
        $this->assertTrue( StudioStore::save( self::ME, $changed, $id )['ok'] );

        $args = [ 'action' => 'fill', 'brief_id' => $id, 'base_hash' => $stale, 'sections' => [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'Mine' ] ] ] ];
        [ $code, $m, $isError ] = $this->mcp( $args );
        $this->assertNull( $code );
        $this->assertTrue( $isError );
        $this->assertSame( 'CONFLICT', $m['code'] ?? null );
        [ $status, $r ] = $this->rest( $args );
        $this->assertSame( 409, $status );
        $this->assertSame( $m, $r );
        $this->assertSame( 'Acme plumbing, new title', StudioStore::get( self::ME, $id )['brief']['title'], 'nothing was written' );
    }

    public function testARefusedBriefIs422InRestAndAnErrorResultInMcp(): void
    {
        $bad = self::brief();
        $bad['language'] = 'nope';
        [ $code, $m, $isError ] = $this->mcp( [ 'action' => 'build', 'brief' => $bad ] );
        $this->assertNull( $code );
        $this->assertTrue( $isError );
        [ $status, $r ] = $this->rest( [ 'action' => 'build', 'brief' => $bad ] );
        $this->assertSame( 422, $status );
        $this->assertSame( $m, $r );
    }

    public function testBuildPreviewsByDefaultAndCreateSavesOneDraft(): void
    {
        [ $code, $m, $isError ] = $this->mcp( [ 'action' => 'build', 'brief' => self::brief() ] );
        $this->assertNull( $code );
        $this->assertFalse( $isError );
        $this->assertSame( [], $GLOBALS['__wp_inserts'], 'a preview creates nothing' );
        [ $status, $r ] = $this->rest( [ 'action' => 'build', 'brief' => self::brief() ] );
        $this->assertSame( 200, $status );
        $this->assertSame( $m, $r );

        [ , , $isError ] = $this->mcp( [ 'action' => 'build', 'brief' => self::brief(), 'create' => true ] );
        $this->assertFalse( $isError );
        $this->assertCount( 1, $GLOBALS['__wp_inserts'] );
        $post = $GLOBALS['__wp_posts'][ $GLOBALS['__wp_inserts'][0] ];
        $this->assertSame( 'draft', $post->post_status );
    }

    public function testTheMcpErrorMappingIsTheDocumentedOne(): void
    {
        $src = $this->src( 'McpHandler.php' );
        $this->assertStringContainsString( 'StudioAssistant::run', $src );
    }
}
