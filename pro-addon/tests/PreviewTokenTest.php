<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Site\PreviewToken;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class PreviewTokenTest extends TestCase
{
    public function testItRoundTripsTheItemMap(): void
    {
        $t = PreviewToken::make( 'args1', [ 11 => 'aaaa', 12 => 'bbbb' ], 1000 );
        $this->assertSame( [ 11 => 'aaaa', 12 => 'bbbb' ], PreviewToken::open( $t, 'args1', 1000 ) );
        $this->assertSame( [], PreviewToken::open( PreviewToken::make( 'args1', [], 1000 ), 'args1', 1000 ), 'an empty map is still a valid token' );
    }

    public function testItIsBoundToTheArguments(): void
    {
        $t = PreviewToken::make( 'args1', [ 11 => 'aaaa' ], 1000 );
        $this->assertNull( PreviewToken::open( $t, 'args2', 1000 ) );
    }

    public function testItExpiresAndRefusesATimeFromTheFuture(): void
    {
        $t = PreviewToken::make( 'a', [ 1 => 'x' ], 1000 );
        $this->assertNotNull( PreviewToken::open( $t, 'a', 1000 + PreviewToken::TTL ) );
        $this->assertNull( PreviewToken::open( $t, 'a', 1000 + PreviewToken::TTL + 1 ) );
        $this->assertNull( PreviewToken::open( $t, 'a', 999 ), 'made in the future: refused' );
    }

    public function testATamperedOrMalformedTokenIsRefused(): void
    {
        $t               = PreviewToken::make( 'a', [ 1 => 'x' ], 1000 );
        [ $payload, $sig ] = explode( '.', $t );
        $forged          = rtrim( strtr( base64_encode( (string) json_encode( [ 'v' => 1, 'a' => 'a', 'i' => [ 1 => 'x', 2 => 'y' ], 't' => 1000 ] ) ), '+/', '-_' ), '=' );
        foreach ( [ '', 'x', $payload, $payload . '.', '.' . $sig, $forged . '.' . $sig, $payload . '.' . $sig . '.extra', $payload . '.' . strrev( $sig ), "{$payload}x.{$sig}" ] as $bad ) {
            $this->assertNull( PreviewToken::open( $bad, 'a', 1000 ), json_encode( $bad ) );
        }
    }

    public function testAValidSignatureOverAnUnexpectedPayloadIsRefused(): void
    {
        foreach ( [ 'not json', json_encode( [ 'v' => 2, 'a' => 'a', 'i' => [], 't' => 1000 ] ), json_encode( [ 'v' => 1, 'a' => 'a', 'i' => 'x', 't' => 1000 ] ), json_encode( [ 'v' => 1, 'a' => 'a', 'i' => [], 't' => '1000' ] ) ] as $json ) {
            $payload = rtrim( strtr( base64_encode( (string) $json ), '+/', '-_' ), '=' );
            $token   = $payload . '.' . wp_hash( 'aied-site|' . $payload );
            $this->assertNull( PreviewToken::open( $token, 'a', 1000 ), (string) $json );
        }
    }
}
