<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\PageOutline;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/ApprovalMode.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/PageOutline.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** The cheap read: every number here is measured on the real page fixtures. */
class PageOutlineTest extends TestCase
{
    private function page( string $name ): string
    {
        return (string) json_decode( (string) file_get_contents( dirname( __DIR__ ) . "/fixtures/valid/{$name}.json" ), true )['post_content'];
    }

    private const SMALL = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"builderVersion":"5.14.0"} -->'
        . '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Hello &amp; welcome"}}},"builderVersion":"5.14.0"} /-->'
        . '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Body copy</p>"}}},"builderVersion":"5.14.0"} /-->'
        . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    public function testPathsFollowSiblingIndexesAndTextComesFromTheModules(): void
    {
        $o = PageOutline::outline( self::SMALL );
        $this->assertSame( 6, $o['total'] );
        $byPath = [];
        foreach ( $o['blocks'] as $b ) {
            $byPath[ $b['path'] ] = $b;
        }
        $this->assertSame( 'divi/placeholder', $byPath['0']['name'] );
        $this->assertSame( 'divi/column', $byPath['0.0.0.0']['name'] );
        $this->assertSame( [ 'divi/heading', 'Hello & welcome' ], [ $byPath['0.0.0.0.0']['name'], $byPath['0.0.0.0.0']['text'] ] );
        $this->assertSame( [ 'divi/text', 'Body copy' ], [ $byPath['0.0.0.0.1']['name'], $byPath['0.0.0.0.1']['text'] ] );
        $this->assertArrayNotHasKey( 'text', $byPath['0.0.0'], 'structure has no text' );
    }

    public function testABlockIsTheExactSliceOfTheSavedContent(): void
    {
        $b = PageOutline::block( self::SMALL, '0.0.0.0.1' );
        $this->assertSame( 'divi/text', $b['name'] );
        $this->assertSame( '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Body copy</p>"}}},"builderVersion":"5.14.0"} /-->', $b['content'] );
        $this->assertStringContainsString( $b['content'], self::SMALL, 'usable as an edit_page_content find string' );
        $col = PageOutline::block( self::SMALL, '0.0.0.0' );
        $this->assertStringStartsWith( '<!-- wp:divi/column', $col['content'] );
        $this->assertStringEndsWith( '<!-- /wp:divi/column -->', $col['content'] );
        $this->assertNull( PageOutline::block( self::SMALL, '0.0.0.0.9' ) );
        $this->assertNull( PageOutline::block( self::SMALL, '3' ) );
    }

    public function testEveryBlockOfEveryRealPageSlicesBackToBalancedMarkup(): void
    {
        foreach ( glob( dirname( __DIR__ ) . '/fixtures/valid/page-*.json' ) ?: [] as $f ) {
            $c     = (string) json_decode( (string) file_get_contents( $f ), true )['post_content'];
            $nodes = PageOutline::scan( $c );
            $this->assertNotNull( $nodes, basename( $f ) );
            foreach ( $nodes as $n ) {
                $slice = substr( $c, $n['start'], $n['end'] - $n['start'] );
                $this->assertNotNull( PageOutline::scan( $slice ), basename( $f ) . ' ' . $n['path'] . ' is balanced on its own' );
                $this->assertSame( 1, count( PageOutline::scan( $slice ) ?? [] ) - count( array_filter( PageOutline::scan( $slice ) ?? [], static fn ( $x ) => str_contains( $x['path'], '.' ) ) ) );
            }
        }
    }

    public function testUnbalancedContentCannotBeOutlined(): void
    {
        $this->assertNull( PageOutline::outline( '<!-- wp:divi/section {"a":1} --><!-- wp:divi/row {"a":1} --><!-- /wp:divi/section -->' ) );
        $this->assertNull( PageOutline::outline( '<!-- wp:divi/section {"a":1} -->' ) );
        $this->assertSame( [ 'blocks' => [], 'total' => 0, 'truncated' => false ], PageOutline::outline( 'no blocks here' ) );
    }

    public function testTheOutlineIsALotCheaperThanTheFullReadOnRealPages(): void
    {
        foreach ( [ 'page-43-layout-1' => 6, 'page-85-layout-4' => 6, 'page-17-newpage' => 4, 'page-7-home' => 4 ] as $name => $factor ) {
            $c    = $this->page( $name );
            $o    = PageOutline::outline( $c );
            $size = strlen( (string) json_encode( $o ) );
            $this->assertLessThan( strlen( $c ) / $factor, $size, "{$name}: full " . strlen( $c ) . ", outline {$size}" );
        }
    }

    public function testViewArgumentsAreStrict(): void
    {
        $this->assertSame( [ 'error' => null, 'view' => 'full', 'path' => null ], PageOutline::parseView( null, null ) );
        $this->assertSame( 'outline', PageOutline::parseView( 'outline', null )['view'] );
        $this->assertSame( '0.1', PageOutline::parseView( 'block', '0.1' )['path'] );
        foreach ( [ [ 'bogus', null ], [ 7, null ], [ true, null ], [ 'outline', 5 ], [ 'block', null ], [ 'block', 'a.b' ], [ 'block', '0..1' ], [ 'outline', '0.1' ], [ 'full', '0' ] ] as [ $v, $p ] ) {
            $this->assertNotNull( PageOutline::parseView( $v, $p )['error'], json_encode( [ $v, $p ] ) );
        }
    }

    // ---- through the real handlers ------------------------------------------------------------

    private function post(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['__wp_options']         = [];
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_current_user_id'] = 7;
        $GLOBALS['__wp_can']             = true;
        $p               = new \WP_Post();
        $p->ID           = 5;
        $p->post_type    = 'page';
        $p->post_title   = 'Five';
        $p->post_content = self::SMALL;
        $GLOBALS['__wp_posts']    = [ 5 => $p ];
        $GLOBALS['__wp_postmeta'] = [ 5 => [] ];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( array $params ): array
    {
        $r = ( new RestController() )->get_page( new \WP_REST_Request( [], [ 'id' => 5 ] + $params ) );
        if ( $r instanceof \WP_Error ) {
            return [ (int) $r->data['status'], [ 'error' => $r->code, 'message' => $r->message ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array{0:?int,1:array<string,mixed>} */
    private function mcp( array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'get_page_layout', 'arguments' => [ 'page_id' => 5 ] + $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], [ 'message' => $data['error']['message'] ] ];
        }

        return [ null, (array) json_decode( $data['result']['content'][0]['text'], true ) ];
    }

    public function testRestAndMcpGiveTheSameOutlineBlockAndFullRead(): void
    {
        $this->post();
        [ $rs, $r ] = $this->rest( [ 'view' => 'outline' ] );
        [ $mc, $m ] = $this->mcp( [ 'view' => 'outline' ] );
        $this->assertSame( 200, $rs );
        $this->assertNull( $mc );
        foreach ( [ $r, $m ] as $d ) {
            $this->assertSame( 'outline', $d['view'] );
            $this->assertSame( 6, $d['total_blocks'] );
            $this->assertFalse( $d['truncated'] );
            $this->assertSame( strlen( self::SMALL ), $d['bytes_full'] );
            $this->assertArrayNotHasKey( 'post_content', $d, 'an outline never carries the whole page' );
            $this->assertSame( 5, $d['post_id'] );
        }
        $this->assertSame( $r['blocks'], $m['blocks'] );

        [ , $rb ] = $this->rest( [ 'view' => 'block', 'path' => '0.0.0.0.0' ] );
        [ , $mb ] = $this->mcp( [ 'view' => 'block', 'path' => '0.0.0.0.0' ] );
        $this->assertSame( $rb['block_content'], $mb['block_content'] );
        $this->assertStringStartsWith( '<!-- wp:divi/heading', $rb['block_content'] );

        [ , $rf ] = $this->rest( [] );
        [ , $mf ] = $this->mcp( [] );
        $this->assertSame( self::SMALL, $rf['post_content'], 'the default is unchanged: the full layout' );
        $this->assertSame( self::SMALL, $mf['post_content'] );
    }

    public function testBadViewArgumentsAndMissingBlocksAreRefusedClearly(): void
    {
        $this->post();
        $this->assertSame( 400, $this->rest( [ 'view' => 'bogus' ] )[0] );
        $this->assertSame( 400, $this->rest( [ 'view' => 'block' ] )[0] );
        $this->assertSame( 404, $this->rest( [ 'view' => 'block', 'path' => '9' ] )[0] );
        $this->assertSame( -32602, $this->mcp( [ 'view' => 7 ] )[0] );
        $this->assertSame( -32602, $this->mcp( [ 'view' => 'block', 'path' => 4 ] )[0] );
        $this->assertSame( -32602, $this->mcp( [ 'view' => 'block', 'path' => '9' ] )[0] );
    }
}
