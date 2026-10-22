<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/WpInsertPostShims.php';
foreach ( [ 'UsageTracker', 'PageHistory', 'HistoryTable', 'HistoryStore', 'EditTarget', 'DiviCompat', 'HistoryService', 'PageEditor', 'DraftPage', 'LayoutDiff', 'CodePolicy', 'LayoutGate', 'DryRun', 'GlobalStyles', 'CustomFields', 'StyleReferences', 'ApprovalMode', 'StyleGuide', 'ImagePack', 'ImageTokens', 'HtmlSource', 'HtmlProse', 'HtmlCss', 'HtmlMapper', 'DiviMarkup', 'HtmlImages', 'HtmlToDivi', 'HtmlToDiviService', 'OpenApiSpec', 'RestController', 'McpHandler' ] as $htmlLockstepClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $htmlLockstepClass . '.php';
}

/** html_to_divi: the same meaning in MCP and REST, preview by default, drafts only, strict arguments, and not in the ChatGPT spec on purpose. */
final class HtmlToDiviLockstepTest extends TestCase
{
    private const HTML = '<h1>Hello</h1><p>World <a href="/x">link</a></p><a class="btn" href="/go">Go</a>';

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']  = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__wp_inserts']  = [];
        $GLOBALS['__wp_can']      = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']         = null;
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_can']     = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    /** @return array{0:?int,1:array<string,mixed>,2:bool} JSON-RPC error code, payload, isError */
    private function mcp( array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'html_to_divi', 'arguments' => $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], [], true ];
        }

        return [ null, (array) json_decode( $data['result']['content'][0]['text'], true ), (bool) ( $data['result']['isError'] ?? false ) ];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( array $body ): array
    {
        $r = ( new RestController() )->html_to_divi( new \WP_REST_Request( $body ) );
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    public function testMcpDeclaresAndDispatchesTheTool(): void
    {
        $mcp = $this->src( 'McpHandler.php' );
        $this->assertStringContainsString( "'name'        => 'html_to_divi'", $mcp );
        $this->assertMatchesRegularExpression( "/'html_to_divi'\s*=>\s*\\\$this->toolHtmlToDivi/", $mcp );

        $req   = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ] );
        $tools = ( new McpHandler() )->handle( $req )->get_data()['result']['tools'];
        $tool  = current( array_filter( $tools, static fn( $t ) => 'html_to_divi' === $t['name'] ) );
        $this->assertSame( [ 'html' ], $tool['inputSchema']['required'] );
        $this->assertSame( [ 'html', 'create', 'title', 'slug', 'image_mode', 'styles' ], array_keys( $tool['inputSchema']['properties'] ) );
        $this->assertSame( 'boolean', $tool['inputSchema']['properties']['create']['type'] );
    }

    public function testRestRegistersAPostRoute(): void
    {
        $this->assertMatchesRegularExpression( "/'\/html-to-divi',\s*\[\s*'methods'\s*=>\s*WP_REST_Server::CREATABLE/", $this->src( 'RestController.php' ) );
    }

    public function testItIsNotInTheChatGptSpecOnPurpose(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $this->assertArrayNotHasKey( '/html-to-divi', $spec['paths'] );
        $this->assertStringNotContainsString( 'html-to-divi', (string) json_encode( $spec ) );
    }

    public function testBothSurfacesPreviewByDefaultAndSaveNothing(): void
    {
        [ $code, $m, $isError ] = $this->mcp( [ 'html' => self::HTML ] );
        $this->assertNull( $code );
        $this->assertFalse( $isError );
        $this->assertTrue( $m['converted'] && $m['valid'] );
        $this->assertFalse( $m['created'] );
        $this->assertStringContainsString( 'wp:divi/placeholder', $m['layout'] );
        $this->assertTrue( $m['report']['text_check']['equal'] );

        [ $status, $r ] = $this->rest( [ 'html' => self::HTML ] );
        $this->assertSame( 200, $status );
        $this->assertSame( $m['layout'], $r['layout'], 'MCP and REST convert identically' );
        $this->assertSame( $m['report'], $r['report'] );
        $this->assertSame( [], $GLOBALS['__wp_inserts'], 'a preview creates nothing' );
    }

    public function testCreateSavesOneDraftThroughTheSameGate(): void
    {
        [ , $m ] = $this->mcp( [ 'html' => self::HTML, 'create' => true, 'title' => 'My <b>converted</b> page', 'slug' => 'My Page' ] );
        $this->assertTrue( $m['created'] );
        $this->assertTrue( $m['layout_omitted'] );
        $this->assertArrayNotHasKey( 'layout', $m, 'the layout is not sent back when it was saved' );
        $this->assertSame( 'draft', $m['page']['status'] );

        [ $status, $r ] = $this->rest( [ 'html' => self::HTML, 'create' => true, 'title' => 'My <b>converted</b> page' ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $r['created'] );

        $this->assertCount( 2, $GLOBALS['__wp_inserts'] );
        foreach ( $GLOBALS['__wp_inserts'] as $id ) {
            $post = $GLOBALS['__wp_posts'][ $id ];
            $this->assertSame( 'My converted page', $post->post_title, 'the title is plain text' );
            $this->assertSame( 'draft', $post->post_status );
            $this->assertStringContainsString( 'wp:divi/heading', $post->post_content );
        }
    }

    public function testCreateNeedsATitleAndSuggestsOne(): void
    {
        [ $code ] = $this->mcp( [ 'html' => '<title>From the HTML</title><h1>Hi</h1>', 'create' => true ] );
        $this->assertSame( -32602, $code );
        [ $status, $r ] = $this->rest( [ 'html' => '<title>From the HTML</title><h1>Hi</h1>', 'create' => true ] );
        $this->assertSame( 400, $status );
        $this->assertStringContainsString( 'From the HTML', $r['error'] );
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function badArguments(): array
    {
        return [
            'html is an array'      => [ [ 'html' => [ '<p>x</p>' ] ] ],
            'html is a number'      => [ [ 'html' => 5 ] ],
            'html is missing'       => [ [] ],
            'html is empty'         => [ [ 'html' => '' ] ],
            'create is a string'    => [ [ 'html' => '<p>x</p>', 'create' => 'true', 'title' => 'T' ] ],
            'create is a number'    => [ [ 'html' => '<p>x</p>', 'create' => 1, 'title' => 'T' ] ],
            'title is an array'     => [ [ 'html' => '<p>x</p>', 'title' => [ 'T' ] ] ],
            'slug is a number'      => [ [ 'html' => '<p>x</p>', 'slug' => 5 ] ],
            'image_mode is unknown' => [ [ 'html' => '<p>x</p>', 'image_mode' => 'download' ] ],
            'image_mode wrong case' => [ [ 'html' => '<p>x</p>', 'image_mode' => 'Keep' ] ],
            'styles is unknown'     => [ [ 'html' => '<p>x</p>', 'styles' => 'full' ] ],
            'an unknown argument'   => [ [ 'html' => '<p>x</p>', 'url' => 'https://example.com' ] ],
            'a fetch-looking field' => [ [ 'html' => '<p>x</p>', 'fetch' => true ] ],
        ];
    }

    #[DataProvider('badArguments')]
    public function testWrongArgumentsAreRefusedNeverCast( array $args ): void
    {
        [ $code ] = $this->mcp( $args );
        $this->assertSame( -32602, $code );
        [ $status ] = $this->rest( $args );
        $this->assertSame( 400, $status );
        $this->assertSame( [], $GLOBALS['__wp_inserts'], 'nothing was created' );
    }

    public function testWithoutPermissionNothingHappens(): void
    {
        $GLOBALS['__wp_can'] = false;
        [ $code ] = $this->mcp( [ 'html' => self::HTML ] );
        $this->assertSame( -32602, $code );
        [ $status ] = $this->rest( [ 'html' => self::HTML ] );
        $this->assertSame( 403, $status );
    }

    public function testCreatingNeedsTheRightToPublishPages(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ]; // may edit, may not publish
        [ , , $isError ] = $this->mcp( [ 'html' => self::HTML ] );
        $this->assertFalse( $isError, 'a preview only needs edit_pages' );
        [ $code ] = $this->mcp( [ 'html' => self::HTML, 'create' => true, 'title' => 'T' ] );
        $this->assertSame( -32602, $code );
        [ $status ] = $this->rest( [ 'html' => self::HTML, 'create' => true, 'title' => 'T' ] );
        $this->assertSame( 403, $status );
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
    }

    public function testNothingToConvertIsAPlainError(): void
    {
        [ , $m, $isError ] = $this->mcp( [ 'html' => '<script>alert(1)</script>' ] );
        $this->assertTrue( $isError );
        $this->assertFalse( $m['converted'] );
        $this->assertNotSame( '', $m['error'], 'the reason is plain English' );
        [ $status ] = $this->rest( [ 'html' => '<script>alert(1)</script>' ] );
        $this->assertSame( 400, $status );
    }

    public function testTheToolNeverTouchesTheNetworkOrTheFiles(): void
    {
        $src = $this->src( 'HtmlToDiviService.php' ) . $this->src( 'HtmlToDivi.php' ) . $this->src( 'HtmlSource.php' ) . $this->src( 'HtmlMapper.php' ) . $this->src( 'HtmlProse.php' ) . $this->src( 'HtmlImages.php' ) . $this->src( 'DiviMarkup.php' );
        $this->assertDoesNotMatchRegularExpression( '/wp_remote_|wp_safe_remote_|curl_|fsockopen|stream_socket|file_get_contents|fopen\(|readfile|download_url|wp_handle_sideload|media_sideload_image|exec\(|shell_exec|system\(|passthru|eval\(/', $src );
    }

    public function testAnImageIsNeverFetchedOrSideloaded(): void
    {
        [ , $m ] = $this->mcp( [ 'html' => '<h1>T</h1><img src="https://evil.example/x.png" alt="x">' ] );
        $this->assertStringNotContainsString( 'evil.example', $m['layout'] );
        $this->assertStringContainsString( 'placeholder', $m['report']['images'][0]['action'] );
    }

    public function testEveryOutcomeIsLoggedUnderItsOwnEndpoint(): void
    {
        $src = $this->src( 'HtmlToDiviService.php' );
        $this->assertSame( "'html_to_divi'", "'" . \AiEditorDivi5\WP\HtmlToDiviService::ENDPOINT . "'" );
        foreach ( [ "'read'", "'valid'", "'invalid'", "'error'" ] as $result ) {
            $this->assertStringContainsString( 'UsageTracker::log( self::ENDPOINT', $src );
            $this->assertStringContainsString( $result, $src, 'the outcome ' . $result . ' is logged' );
        }
    }
}
