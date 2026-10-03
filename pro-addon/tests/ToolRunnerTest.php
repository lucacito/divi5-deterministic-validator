<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\ToolRunner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/FakeTool.php';

/** The one call path shared by the MCP registry and the REST routes. */
class ToolRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    public function testASuccessfulCallReturnsThePayload(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', null, [ 'done' => 1 ] );
        $r    = ToolRunner::run( $tool, [ 'x' => 2 ] );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [ 'done' => 1, 'args' => [ 'x' => 2 ] ], $r['payload'] );
        $this->assertSame( 1, $tool->runs );
    }

    public function testAUserWithoutTheCapabilityIsRefusedBeforeTheToolRuns(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $tool                 = new FakeTool();
        $r                    = ToolRunner::run( $tool, [] );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( [ 403, 'forbidden' ], [ $r['status'], $r['code'] ] );
        $this->assertStringContainsString( 'fake_pro_tool', $r['message'] );
        $this->assertSame( 0, $tool->runs );
    }

    public function testABadArgumentIsA400WithTheToolsOwnMessage(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', new \InvalidArgumentException( '"page_id" must be an integer.' ) );
        $r    = ToolRunner::run( $tool, [] );
        $this->assertSame( [ false, 400, 'invalid_argument', '"page_id" must be an integer.' ], [ $r['ok'], $r['status'], $r['code'], $r['message'] ] );
    }

    public function testAnUnexpectedFailureIsA500AndNeverLeaksItsMessage(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', new \RuntimeException( 'SECRET db password=hunter2' ) );
        $r    = ToolRunner::run( $tool, [] );
        $this->assertSame( [ false, 500, 'tool_failed' ], [ $r['ok'], $r['status'], $r['code'] ] );
        $this->assertStringNotContainsString( 'hunter2', (string) json_encode( $r ) );
    }

    public function testTheRegistryExposesItsToolsAndTheLicenceStateForOtherTransports(): void
    {
        $tool     = new FakeTool();
        $unlocked = true;
        $reg      = new ToolRegistry( [ $tool ], static function () use ( &$unlocked ): bool {
            return $unlocked;
        } );
        $this->assertSame( $tool, $reg->get( 'fake_pro_tool' ) );
        $this->assertNull( $reg->get( 'nope' ) );
        $this->assertSame( [ $tool ], $reg->tools() );
        $this->assertTrue( $reg->isUnlocked() );
        $unlocked = false;
        $this->assertFalse( $reg->isUnlocked() );
    }
}
