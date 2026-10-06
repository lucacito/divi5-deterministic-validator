<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\McpHandler;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** Two spec points a strict MCP client checks: ping answers {} and a notification is accepted with 202. */
class McpProtocolTest extends TestCase
{
    private function call( array $body ): \WP_REST_Response
    {
        return ( new McpHandler() )->handle( new \WP_REST_Request( $body ) );
    }

    public function testPingAnswersWithAnEmptyJsonObject(): void
    {
        $r = $this->call( [ 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( '{"jsonrpc":"2.0","id":5,"result":{}}', json_encode( $r->get_data() ) );
    }

    public function testANotificationIsAcceptedWith202AndNoBody(): void
    {
        $r = $this->call( [ 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ] );
        $this->assertSame( 202, $r->get_status() );
        $this->assertNull( $r->get_data() );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'otherNotifications' )]
    public function testAnyOtherNotificationIsAcceptedToo( string $method ): void
    {
        $r = $this->call( [ 'jsonrpc' => '2.0', 'method' => $method ] );
        $this->assertSame( 202, $r->get_status() );
        $this->assertNull( $r->get_data() );
    }

    /** @return list<array{string}> */
    public static function otherNotifications(): array
    {
        return [ [ 'notifications/cancelled' ], [ 'notifications/roots/list_changed' ], [ 'notifications/progress' ] ];
    }

    public function testAnUnknownRequestIsStillMethodNotFound(): void
    {
        $r = $this->call( [ 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'bogus/method' ] );
        $this->assertSame( -32601, $r->get_data()['error']['code'] );
    }
}
