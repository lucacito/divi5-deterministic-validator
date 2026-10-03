<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\WP\ExtensionGuard;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/ProTool.php';
require_once __DIR__ . '/../src/ToolRunner.php';
require_once __DIR__ . '/../src/ToolRegistry.php';
require_once __DIR__ . '/FakeTool.php';

/** The registry is the only place the add-on talks to the free plugin's tool hooks. */
class ToolRegistryTest extends TestCase
{
    private bool $unlocked = true;

    protected function setUp(): void
    {
        $GLOBALS['__wp_filters'] = [];
        $GLOBALS['__wp_can']     = true;
        unset( $GLOBALS['__wp_caps'] );
        $this->unlocked = true;
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_filters'] = [];
        $GLOBALS['__wp_can']     = true;
    }

    /** @param list<ProTool> $tools */
    private function registry( array $tools ): ToolRegistry
    {
        return new ToolRegistry( $tools, fn (): bool => $this->unlocked );
    }

    /** @return array<string,mixed> */
    private function data( mixed $response ): array
    {
        $this->assertInstanceOf( \WP_REST_Response::class, $response );

        return (array) $response->get_data();
    }

    public function testLockedMeansNoToolIsAdvertisedOrClaimed(): void
    {
        $this->unlocked = false;
        $tool           = new FakeTool();
        $r              = $this->registry( [ $tool ] );
        $this->assertSame( [], $r->definitions( [] ) );
        $this->assertNull( $r->claim( null, 'fake_pro_tool', [], 1 ) );
        $this->assertSame( 0, $tool->runs );
    }

    public function testUnlockedAppendsTheToolDefinitionsAfterTheBuiltIns(): void
    {
        $r    = $this->registry( [ new FakeTool() ] );
        $out  = $r->definitions( [ [ 'name' => 'get_page_layout' ] ] );
        $this->assertSame( [ 'get_page_layout', 'fake_pro_tool' ], array_column( $out, 'name' ) );
        $this->assertSame( [ 'fake_pro_tool' ], $r->names() );
    }

    public function testAnUnusableToolListIsReturnedUntouched(): void
    {
        $r = $this->registry( [ new FakeTool() ] );
        $this->assertSame( 'oops', $r->definitions( 'oops' ) );
        $this->assertNull( $r->definitions( null ) );
    }

    public function testABuiltInToolAlwaysWinsACollisionThroughTheFreePluginsGuard(): void
    {
        $builtIn = [ [ 'name' => 'get_page_layout', 'description' => 'built-in' ] ];
        $r       = $this->registry( [ new FakeTool( 'get_page_layout' ) ] );
        $merged  = ExtensionGuard::tools( $builtIn, $r->definitions( $builtIn ) );
        $this->assertCount( 1, $merged );
        $this->assertSame( 'built-in', $merged[0]['description'] );
    }

    public function testOnlyItsOwnToolNamesAreClaimed(): void
    {
        $tool = new FakeTool();
        $r    = $this->registry( [ $tool ] );
        $this->assertNull( $r->claim( null, 'some_other_tool', [], 1 ), 'foreign names fall through' );
        $other = new \WP_REST_Response( [ 'claimed' => 'by another add-on' ] );
        $this->assertSame( $other, $r->claim( $other, 'fake_pro_tool', [], 1 ), 'an earlier add-on keeps its claim' );
        $this->assertSame( 0, $tool->runs );
    }

    public function testASuccessfulCallReturnsTheBuiltInResponseShape(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', null, [ 'done' => 2 ] );
        $d    = $this->data( $this->registry( [ $tool ] )->claim( null, 'fake_pro_tool', [ 'x' => 1 ], 'req-9' ) );
        $this->assertSame( '2.0', $d['jsonrpc'] );
        $this->assertSame( 'req-9', $d['id'] );
        $this->assertSame( 'text', $d['result']['content'][0]['type'] );
        $this->assertSame( [ 'done' => 2, 'args' => [ 'x' => 1 ] ], json_decode( $d['result']['content'][0]['text'], true ) );
        $this->assertSame( 1, $tool->runs );
    }

    public function testAUserWithoutTheCapabilityIsRefusedAndTheToolNeverRuns(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];   // manage_options is not granted
        $tool                 = new FakeTool();
        $d                    = $this->data( $this->registry( [ $tool ] )->claim( null, 'fake_pro_tool', [], 3 ) );
        $this->assertSame( -32602, $d['error']['code'] );
        $this->assertStringContainsString( 'fake_pro_tool', $d['error']['message'] );
        $this->assertSame( 0, $tool->runs );
    }

    public function testABadArgumentIsAJsonRpcInvalidParamsErrorWithTheToolsOwnMessage(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', new \InvalidArgumentException( '"page_id" must be an integer.' ) );
        $d    = $this->data( $this->registry( [ $tool ] )->claim( null, 'fake_pro_tool', [], 4 ) );
        $this->assertSame( -32602, $d['error']['code'] );
        $this->assertSame( '"page_id" must be an integer.', $d['error']['message'] );
    }

    public function testAnUnexpectedFailureNeverLeaksItsMessage(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', new \RuntimeException( 'SECRET db password=hunter2 at /var/www/x.php' ) );
        $d    = $this->data( $this->registry( [ $tool ] )->claim( null, 'fake_pro_tool', [], 5 ) );
        $this->assertSame( -32603, $d['error']['code'] );
        $this->assertStringNotContainsString( 'SECRET', (string) json_encode( $d ) );
        $this->assertStringNotContainsString( 'hunter2', (string) json_encode( $d ) );
    }

    public function testALicenceRevokedBetweenListingAndCallingStopsTheCall(): void
    {
        $tool = new FakeTool();
        $r    = $this->registry( [ $tool ] );
        $this->assertSame( [ 'fake_pro_tool' ], array_column( $r->definitions( [] ), 'name' ) );
        $this->unlocked = false;
        $this->assertNull( $r->claim( null, 'fake_pro_tool', [], 6 ) );
        $this->assertSame( 0, $tool->runs );
    }

    public function testRegisterAttachesToTheFreePluginsToolHooks(): void
    {
        $tool = new FakeTool();
        $this->registry( [ $tool ] )->register();
        $list = apply_filters( 'jhmg_aied_mcp_tools', [] );
        $this->assertSame( [ 'fake_pro_tool' ], array_column( $list, 'name' ) );
        $res = apply_filters( 'jhmg_aied_mcp_call', null, 'fake_pro_tool', [], 7 );
        $this->assertInstanceOf( \WP_REST_Response::class, $res );
        $this->assertSame( 1, $tool->runs );
    }
}
