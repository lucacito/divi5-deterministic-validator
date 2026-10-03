<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Rest\ProRest;
use AiEditorDivi5\Pro\ToolRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/FakeTool.php';
require_once __DIR__ . '/WpRestRouteShims.php';

class ProRestTest extends TestCase
{
    private bool $unlocked = true;

    protected function setUp(): void
    {
        $this->unlocked              = true;
        $GLOBALS['__wp_filters']     = [];
        $GLOBALS['__wp_rest_routes'] = [];
        $GLOBALS['__wp_logged_in']   = false;
        $GLOBALS['__wp_can']         = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_filters'] = [];
        $GLOBALS['__wp_can']     = true;
        $GLOBALS['__wp_logged_in'] = false;
        unset( $GLOBALS['__wp_caps'] );
    }

    /** @param list<FakeTool> $tools */
    private function rest( array $tools ): ProRest
    {
        return new ProRest( new ToolRegistry( $tools, fn (): bool => $this->unlocked ) );
    }

    /** @param array<string,mixed> $body */
    private function call( ProRest $rest, string $name, array $body = [] ): mixed
    {
        return $rest->handle( $name, new \WP_REST_Request( $body ) );
    }

    public function testItAddsOnePostRoutePerToolOnTheFreePluginsHook(): void
    {
        $rest = $this->rest( [ new FakeTool( 'fake_pro_tool' ), new FakeTool( 'other_tool' ) ] );
        $rest->register();
        $this->assertArrayHasKey( 'jhmg_aied_register_rest_routes', $GLOBALS['__wp_filters'] );
        do_action( 'jhmg_aied_register_rest_routes', 'ai-editor-divi5/v1' );
        $this->assertSame( [ '/pro/fake_pro_tool', '/pro/other_tool' ], array_column( $GLOBALS['__wp_rest_routes'], 1 ) );
        foreach ( $GLOBALS['__wp_rest_routes'] as [ $ns, , $args ] ) {
            $this->assertSame( 'ai-editor-divi5/v1', $ns );
            $this->assertSame( 'POST', $args['methods'] );
            $this->assertSame( [ $rest, 'authorize' ], $args['permission_callback'], 'the free plugin\'s authentication, never __return_true' );
        }
    }

    public function testWhileLockedNoRouteIsRegistered(): void
    {
        $this->unlocked = false;
        $rest           = $this->rest( [ new FakeTool() ] );
        $rest->routes( 'ai-editor-divi5/v1' );
        $this->assertSame( [], $GLOBALS['__wp_rest_routes'] );
    }

    public function testAnUnusableNamespaceRegistersNothing(): void
    {
        $this->rest( [ new FakeTool() ] )->routes( null );
        $this->assertSame( [], $GLOBALS['__wp_rest_routes'] );
    }

    public function testASuccessfulCallReturnsThePayloadWith200(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', null, [ 'done' => 2 ] );
        $r    = $this->call( $this->rest( [ $tool ] ), 'fake_pro_tool', [ 'x' => 1 ] );
        $this->assertInstanceOf( \WP_REST_Response::class, $r );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( [ 'done' => 2, 'args' => [ 'x' => 1 ] ], $r->get_data() );
        $this->assertSame( 1, $tool->runs );
    }

    public function testABadArgumentIsA400WithTheToolsMessage(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', new \InvalidArgumentException( '"page_id" must be an integer.' ) );
        $r    = $this->call( $this->rest( [ $tool ] ), 'fake_pro_tool' );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( [ 'invalid_argument', '"page_id" must be an integer.', 400 ], [ $r->code, $r->message, $r->data['status'] ] );
    }

    public function testAUserWithoutTheToolsCapabilityGets403AndTheToolNeverRuns(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $tool                 = new FakeTool();
        $r                    = $this->call( $this->rest( [ $tool ] ), 'fake_pro_tool' );
        $this->assertSame( [ 'forbidden', 403 ], [ $r->code, $r->data['status'] ] );
        $this->assertSame( 0, $tool->runs );
    }

    public function testAnUnexpectedFailureIsA500AndNeverLeaks(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', new \RuntimeException( 'SECRET hunter2 /var/www/x.php' ) );
        $r    = $this->call( $this->rest( [ $tool ] ), 'fake_pro_tool' );
        $this->assertSame( [ 'tool_failed', 500 ], [ $r->code, $r->data['status'] ] );
        $this->assertStringNotContainsString( 'hunter2', (string) json_encode( $r ) );
    }

    public function testALicenceRevokedAfterRegistrationStopsTheCall(): void
    {
        $tool           = new FakeTool();
        $rest           = $this->rest( [ $tool ] );
        $this->unlocked = false;
        $r              = $this->call( $rest, 'fake_pro_tool' );
        $this->assertSame( [ 'pro_locked', 403 ], [ $r->code, $r->data['status'] ] );
        $this->assertSame( 0, $tool->runs );
    }

    public function testAnUnknownToolIsRefusedLikeALockedOne(): void
    {
        $r = $this->call( $this->rest( [ new FakeTool() ] ), 'not_a_tool' );
        $this->assertSame( 403, $r->data['status'] );
    }

    public function testABodyThatIsNotAJsonObjectIsA400(): void
    {
        $tool = new FakeTool();
        $r    = $this->call( $this->rest( [ $tool ] ), 'fake_pro_tool', [ 1, 2, 3 ] );
        $this->assertSame( 400, $r->data['status'] );
        $this->assertSame( 0, $tool->runs );
    }

    public function testABodyThatWasNotParsedAsJsonIsA400EvenForAToolThatNeedsNoArguments(): void
    {
        $tool = new FakeTool();
        $rest = $this->rest( [ $tool ] );
        // Sent as a form or as text/plain: WordPress parses no JSON, so the tool would have run with its defaults.
        foreach ( [ 'scope=layouts&cursor=3', '{"scope":"layouts"}', 'null', 'x' ] as $raw ) {
            $r = $rest->handle( 'fake_pro_tool', new \WP_REST_Request( null, [], $raw ) );
            $this->assertSame( 400, $r->data['status'], $raw );
            $this->assertSame( 'invalid_argument', $r->code, $raw );
            $this->assertStringContainsString( 'application/json', $r->get_error_message(), $raw );
        }
        $this->assertSame( 0, $tool->runs );
    }

    public function testAnEmptyOrBlankBodyWithoutJsonIsNoArguments(): void
    {
        $tool = new FakeTool();
        foreach ( [ '', "  \n" ] as $raw ) {
            $r = $this->rest( [ $tool ] )->handle( 'fake_pro_tool', new \WP_REST_Request( null, [], $raw ) );
            $this->assertSame( 200, $r->get_status(), json_encode( $raw ) );
        }
        $this->assertSame( 2, $tool->runs );
    }

    public function testAnEmptyBodyIsNoArguments(): void
    {
        $tool = new FakeTool();
        $r    = $this->call( $this->rest( [ $tool ] ), 'fake_pro_tool', [] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( [], $r->get_data()['args'] );
    }

    public function testAuthorizeRefusesWhileLockedAndOtherwiseUsesTheFreePluginsRule(): void
    {
        $rest           = $this->rest( [ new FakeTool() ] );
        $this->unlocked = false;
        $locked         = $rest->authorize();
        $this->assertSame( [ 'pro_locked', 403 ], [ $locked->code, $locked->data['status'] ] );

        $this->unlocked = true;
        $r              = $rest->authorize();
        $this->assertSame( [ 'unauthorized', 401 ], [ $r->code, $r->data['status'] ], 'no key and not logged in' );

        $GLOBALS['__wp_logged_in'] = true;
        $GLOBALS['__wp_caps']      = [ 'edit_posts' => true ];
        $this->assertTrue( $rest->authorize() );

        $GLOBALS['__wp_caps'] = [ 'read' => true ];
        $r                    = $rest->authorize();
        $this->assertSame( [ 'forbidden', 403 ], [ $r->code, $r->data['status'] ] );
    }
}
