<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OpenApiSpec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';

/** The page title argument must exist, with the same meaning, in MCP, REST and OpenAPI, and only on update_page_layout / PUT. */
class PageTitleLockstepTest extends TestCase
{
    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    /** The source of one method: from its declaration to the next method declaration. */
    private function body( string $src, string $method ): string
    {
        $start = strpos( $src, "function {$method}(" );
        $this->assertNotFalse( $start, "{$method} not found" );
        $rest = substr( $src, $start + 10 );
        $end  = preg_match( '/\n    (?:public |private |protected )?(?:static )?function /', $rest, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] : strlen( $rest );

        return substr( $rest, 0, $end );
    }

    /** @return array<string,mixed> */
    private function spec(): array
    {
        return OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
    }

    /** @return array<string,array<string,mixed>> tool name => definition */
    private function mcpTools(): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ] );
        $data = (array) ( new \AiEditorDivi5\WP\McpHandler() )->handle( $req )->get_data();
        $out  = [];
        foreach ( $data['result']['tools'] as $t ) {
            $out[ $t['name'] ] = $t;
        }

        return $out;
    }

    public function testMcpUpdateToolDeclaresTitleAndEditContentDoesNot(): void
    {
        $tools  = $this->mcpTools();
        $update = $tools['update_page_layout'];
        $this->assertSame( 'string', $update['inputSchema']['properties']['title']['type'] );
        $this->assertSame( [ 'page_id' ], $update['inputSchema']['required'] );
        foreach ( [ 'title', 'h1', 'restore_page_version' ] as $word ) {
            $this->assertStringContainsString( $word, $update['description'] );
        }
        $this->assertArrayNotHasKey( 'title', $tools['edit_page_content']['inputSchema']['properties'] );
    }

    public function testOpenApiPutDeclaresTitleAndTheEditRouteDoesNot(): void
    {
        $paths = $this->spec()['paths'];
        $put   = $paths['/pages/{id}']['put'];
        $schema = $put['requestBody']['content']['application/json']['schema'];
        $this->assertSame( 'string', $schema['properties']['title']['type'] );
        $this->assertArrayNotHasKey( 'required', $schema, 'post_content is optional when title is given' );
        $this->assertStringContainsString( 'title', $put['description'] );
        $this->assertLessThanOrEqual( 300, mb_strlen( $put['description'] ) );
        $this->assertSame( 'Missing post_content and title, or an invalid title', $put['responses']['400']['description'] );

        $edit = $paths['/pages/{id}/edit']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertArrayNotHasKey( 'title', $edit['properties'] );

        // Only PUT takes a title; validateLayout (POST /validate) shares the body helper and must keep requiring post_content.
        $validate = $paths['/validate']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertArrayNotHasKey( 'title', $validate['properties'] );
        $this->assertSame( [ 'post_content' ], $validate['required'] );
    }

    public function testOpenApiResponsesDescribeTheTitleReport(): void
    {
        $spec = $this->spec();
        $ok   = $spec['paths']['/pages/{id}']['put']['responses']['200']['content']['application/json']['schema']['properties'];
        $this->assertSame( 'boolean', $ok['layout_changed']['type'] );
        $this->assertSame( 'object', $ok['title']['type'] );
        foreach ( [ 'changed', 'before', 'after', 'restorable' ] as $key ) {
            $this->assertArrayHasKey( $key, $ok['title']['properties'] );
        }
        $this->assertStringContainsString( 'only included when the title changed', $ok['title']['properties']['restorable']['description'] );
        $this->assertStringContainsString( 'title_only', $spec['components']['schemas']['SnapshotInfo']['properties']['reason']['description'] );

        $restore = null;
        foreach ( $spec['paths'] as $methods ) {
            foreach ( $methods as $op ) {
                if ( ( $op['operationId'] ?? '' ) === 'restorePageVersion' ) {
                    $restore = $op['responses']['200']['content']['application/json']['schema']['properties'];
                }
            }
        }
        $this->assertNotNull( $restore );
        $this->assertSame( 'boolean', $restore['title_restored']['type'] );
        $this->assertSame( 'string', $restore['title_note']['type'] );
    }

    public function testTheUpdateHandlersParseThePlanAndWriteTheTitleAndTheEditHandlersNeverDo(): void
    {
        $mcp  = $this->src( 'McpHandler.php' );
        $rest = $this->src( 'RestController.php' );

        $updates = [
            [ $this->body( $mcp, 'toolUpdate' ), $this->body( $mcp, 'toolUpdateTitleOnly' ) ],
            [ $this->body( $rest, 'update_page' ), $this->body( $rest, 'update_page_title_only' ) ],
        ];
        foreach ( $updates as [ $main, $titleOnly ] ) {
            $this->assertStringContainsString( 'PageTitle::parse(', $main );
            $this->assertStringContainsString( 'TitleChange::plan(', $main );
            $this->assertStringContainsString( "HistoryService::write(", $main );
            $this->assertStringContainsString( "\$plan['writeTitle']", $main, 'a combined save passes the planned title as the fourth argument' );
            $this->assertStringContainsString( 'HistoryService::writeTitle(', $titleOnly );
        }
        // The approval subject is bound to the title inside the shared planner.
        $this->assertStringContainsString( 'ApprovalMode::withTitle(', $this->src( 'TitleChange.php' ) );

        foreach ( [ $this->body( $mcp, 'toolEditContent' ), $this->body( $rest, 'edit_page' ) ] as $edit ) {
            foreach ( [ 'PageTitle', 'TitleChange', 'writeTitle', 'withTitle' ] as $needle ) {
                $this->assertStringNotContainsString( $needle, $edit );
            }
        }
    }

    public function testNoRouteRegistersATitleArgument(): void
    {
        $this->assertStringNotContainsString( 'title', $this->body( $this->src( 'RestController.php' ), 'register_routes' ) );
    }

    public function testTheOperationAndToolCountsAreUnchanged(): void
    {
        $paths = $this->spec()['paths'];
        $ops   = 0;
        foreach ( $paths as $methods ) {
            $ops += count( $methods );
        }
        // The numbers pinned by OpenApiSpecTest (16 operations) and WpOrgComplianceTest (21 tools); this test never moves them.
        $this->assertSame( 16, $ops );
        $this->assertCount( 21, $this->mcpTools() );
    }
}
