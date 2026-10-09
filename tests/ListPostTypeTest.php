<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/WpInsertPostShims.php';
require_once __DIR__ . '/GetPostsShim.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** list_divi_pages / GET /pages: the optional post_type (page by default, or project), validated, on MCP, REST and in the OpenAPI spec. */
class ListPostTypeTest extends TestCase
{
    private function post( int $id, string $type ): \WP_Post
    {
        $p             = new \WP_Post();
        $p->ID         = $id;
        $p->post_type  = $type;
        $p->post_title = "{$type} {$id}";
        $GLOBALS['__wp_postmeta'][ $id ] = [ '_et_pb_use_divi_5' => 'on', '_et_pb_use_builder' => 'on' ];

        return $p;
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__wp_caps']     = [ 'edit_pages' => true, 'edit_post' => true, 'edit_post:21' => false ];
        $GLOBALS['__cfd_posts']   = [
            'page'    => [ $this->post( 11, 'page' ) ],
            'project' => [ $this->post( 20, 'project' ), $this->post( 21, 'project' ) ],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        unset( $GLOBALS['__cfd_posts'], $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can'] = true;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( array $params ): array
    {
        $r = ( new RestController() )->list_pages( new \WP_REST_Request( [], $params ) );
        if ( $r instanceof \WP_Error ) {
            return [ (int) $r->data['status'], [ 'error' => $r->code ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array{0:?int,1:array<string,mixed>} */
    private function mcp( array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'list_divi_pages', 'arguments' => $args ?: new \stdClass() ] ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], [] ];
        }

        return [ null, (array) json_decode( $data['result']['content'][0]['text'], true ) ];
    }

    public function testTheDefaultIsPagesAndEveryItemReportsItsPostType(): void
    {
        foreach ( [ $this->rest( [] ), [ 200, $this->mcp( [] )[1] ] ] as [ $status, $d ] ) {
            $this->assertSame( 200, $status );
            $this->assertSame( [ 11 ], array_column( $d['pages'], 'id' ) );
            $this->assertSame( [ 'page' ], array_column( $d['pages'], 'post_type' ) );
            $this->assertSame( 1, $d['count'] );
        }
    }

    public function testProjectsAreListedWhenAskedForAndOnlyThoseTheUserCanEdit(): void
    {
        [ $status, $d ] = $this->rest( [ 'post_type' => 'project' ] );
        $this->assertSame( 200, $status );
        $this->assertSame( [ 20 ], array_column( $d['pages'], 'id' ), 'project 21 is not editable by this user' );
        $this->assertSame( [ 'project' ], array_column( $d['pages'], 'post_type' ) );

        [ $code, $m ] = $this->mcp( [ 'post_type' => 'project' ] );
        $this->assertNull( $code );
        $this->assertSame( [ 20 ], array_column( $m['pages'], 'id' ) );
        $this->assertSame( [ 'project' ], array_column( $m['pages'], 'post_type' ) );
    }

    public function testAnExplicitPageAndAnEmptyValueMeanPages(): void
    {
        $this->assertSame( [ 11 ], array_column( $this->rest( [ 'post_type' => 'page' ] )[1]['pages'], 'id' ) );
        $this->assertSame( [ 11 ], array_column( $this->rest( [ 'post_type' => '' ] )[1]['pages'], 'id' ) );
        $this->assertSame( [ 11 ], array_column( $this->mcp( [ 'post_type' => 'page' ] )[1]['pages'], 'id' ) );
    }

    public function testAnythingElseIsRejectedNeverCast(): void
    {
        foreach ( [ 'post', 'PROJECT', 'attachment', 'et_header_layout', 7, true, [ 'project' ] ] as $bad ) {
            [ $status, $d ] = $this->rest( [ 'post_type' => $bad ] );
            $this->assertSame( 400, $status, json_encode( $bad ) );
            $this->assertSame( 'invalid_field', $d['error'] );
            [ $code ] = $this->mcp( [ 'post_type' => $bad ] );
            $this->assertSame( -32602, $code, json_encode( $bad ) );
        }
    }

    public function testTheCapabilityCheckStillComesFirst(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true ];
        [ $status ] = $this->rest( [ 'post_type' => 'project' ] );
        $this->assertSame( 403, $status );
        $this->assertSame( -32602, $this->mcp( [ 'post_type' => 'project' ] )[0] );
        [ $status ] = $this->rest( [ 'post_type' => 'nonsense' ] );
        $this->assertSame( 403, $status, 'a user without edit_pages learns nothing about the argument' );
    }

    public function testToolsListDeclaresAGeminiSafePostTypeEnum(): void
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();
        $tool = null;
        foreach ( $data['result']['tools'] as $t ) {
            if ( 'list_divi_pages' === $t['name'] ) {
                $tool = $t;
            }
        }
        $this->assertNotNull( $tool );
        $prop = $tool['inputSchema']['properties']['post_type'];
        $this->assertSame( 'string', $prop['type'] );
        $this->assertSame( [ 'page', 'project' ], $prop['enum'] );
        $this->assertSame( [], $tool['inputSchema']['required'] );
        $json = (string) json_encode( $tool['inputSchema'] );
        foreach ( [ 'additionalProperties', '$ref', 'oneOf' ] as $banned ) {
            $this->assertStringNotContainsString( $banned, $json );
        }
    }

    public function testTheOpenApiSpecDocumentsThePostTypeParameter(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $op   = $spec['paths']['/pages']['get'];
        $this->assertSame( 'listDiviPages', $op['operationId'] );
        $param = null;
        foreach ( $op['parameters'] ?? [] as $p ) {
            if ( 'post_type' === ( $p['name'] ?? '' ) ) {
                $param = $p;
            }
        }
        $this->assertNotNull( $param, 'listDiviPages documents post_type' );
        $this->assertSame( 'query', $param['in'] );
        $this->assertFalse( $param['required'] );
        $this->assertSame( [ 'page', 'project' ], $param['schema']['enum'] );
        $this->assertSame( 'page', $param['schema']['default'] );
        $this->assertArrayHasKey( 'post_type', $spec['components']['schemas']['PageSummary']['properties'] );
    }
}
