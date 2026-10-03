<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Site\PreviewToken;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class PreviewTokenTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_transients'] = [];
    }

    /** A token's payload (base64url JSON) re-signed as the site would sign it. @param array<string,mixed> $data */
    private static function signed( array $data ): string
    {
        $payload = rtrim( strtr( base64_encode( (string) json_encode( $data ) ), '+/', '-_' ), '=' );

        return $payload . '.' . wp_hash( 'aied-site|' . $payload );
    }

    public function testItRoundTripsTheItemMap(): void
    {
        $t = PreviewToken::make( 'args1', [ 11 => 'aaaa', 12 => 'bbbb' ], 1000, 7 );
        $this->assertSame( [ 11 => 'aaaa', 12 => 'bbbb' ], PreviewToken::open( $t, 'args1', 1000, 7 ) );
        $this->assertSame( [], PreviewToken::open( PreviewToken::make( 'args1', [], 1000, 7 ), 'args1', 1000, 7 ), 'an empty map is still a valid token' );
    }

    public function testItIsBoundToTheArguments(): void
    {
        $t = PreviewToken::make( 'args1', [ 11 => 'aaaa' ], 1000, 7 );
        $this->assertNull( PreviewToken::open( $t, 'args2', 1000, 7 ) );
    }

    public function testItIsBoundToTheUserWhoPreviewed(): void
    {
        $t = PreviewToken::make( 'a', [ 11 => 'aaaa' ], 1000, 7 );
        $this->assertNull( PreviewToken::open( $t, 'a', 1000, 8 ) );
        $this->assertNull( PreviewToken::open( $t, 'a', 1000, 0 ) );
        $this->assertNotNull( PreviewToken::open( $t, 'a', 1000, 7 ), 'refusing another user does not use the token up' );
    }

    public function testItExpiresAndRefusesATimeFromTheFuture(): void
    {
        $t = PreviewToken::make( 'a', [ 1 => 'x' ], 1000, 7 );
        $this->assertNotNull( PreviewToken::open( $t, 'a', 1000 + PreviewToken::TTL, 7 ) );
        $this->assertNull( PreviewToken::open( $t, 'a', 1000 + PreviewToken::TTL + 1, 7 ) );
        $this->assertNull( PreviewToken::open( $t, 'a', 999, 7 ), 'made in the future: refused' );
    }

    public function testASpentTokenIsRefusedAndOtherTokensAreNot(): void
    {
        $t     = PreviewToken::make( 'a', [ 1 => 'x' ], 1000, 7 );
        $other = PreviewToken::make( 'a', [ 1 => 'x' ], 1000, 7 );
        $this->assertNotSame( $t, $other, 'two previews of the same thing are two tokens' );
        $this->assertNotNull( PreviewToken::open( $t, 'a', 1000, 7 ) );
        PreviewToken::spend( $t );
        $this->assertNull( PreviewToken::open( $t, 'a', 1000, 7 ) );
        PreviewToken::spend( $t );
        $this->assertNull( PreviewToken::open( $t, 'a', 1000, 7 ), 'spending twice changes nothing' );
        $this->assertNotNull( PreviewToken::open( $other, 'a', 1000, 7 ) );
    }

    public function testTheSpentMarkLivesAsLongAsTheTokenCouldBeUsed(): void
    {
        $t = PreviewToken::make( 'a', [ 1 => 'x' ], 1000, 7 );
        PreviewToken::spend( $t );
        $this->assertCount( 1, $GLOBALS['__wp_transients'] );
    }

    public function testSpendingATamperedOrMalformedTokenRecordsNothing(): void
    {
        $t = PreviewToken::make( 'a', [ 1 => 'x' ], 1000, 7 );
        foreach ( [ '', 'x', $t . 'x', strrev( $t ), 'a.b' ] as $bad ) {
            PreviewToken::spend( $bad );
        }
        $this->assertSame( [], $GLOBALS['__wp_transients'] );
    }

    public function testATamperedOrMalformedTokenIsRefused(): void
    {
        $t                 = PreviewToken::make( 'a', [ 1 => 'x' ], 1000, 7 );
        [ $payload, $sig ] = explode( '.', $t );
        $forged            = rtrim( strtr( base64_encode( (string) json_encode( [ 'v' => 2, 'a' => 'a', 'i' => [ 1 => 'x', 2 => 'y' ], 't' => 1000, 'u' => 7, 'n' => 'abcd' ] ) ), '+/', '-_' ), '=' );
        foreach ( [ '', 'x', $payload, $payload . '.', '.' . $sig, $forged . '.' . $sig, $payload . '.' . $sig . '.extra', $payload . '.' . strrev( $sig ), "{$payload}x.{$sig}" ] as $bad ) {
            $this->assertNull( PreviewToken::open( $bad, 'a', 1000, 7 ), json_encode( $bad ) );
        }
    }

    public function testAValidSignatureOverAnUnexpectedPayloadIsRefused(): void
    {
        $good = [ 'v' => 2, 'a' => 'a', 'i' => [], 't' => 1000, 'u' => 7, 'n' => 'abcd' ];
        $this->assertSame( [], PreviewToken::open( self::signed( $good ), 'a', 1000, 7 ), 'the control: this shape is accepted' );
        $bad = [
            'version 1 (no user, no nonce)' => [ 'v' => 1, 'a' => 'a', 'i' => [], 't' => 1000 ],
            'unknown version'               => [ 'v' => 3 ] + $good,
            'items not a map'               => [ 'i' => 'x' ] + $good,
            'time not an integer'           => [ 't' => '1000' ] + $good,
            'user not an integer'           => [ 'u' => '7' ] + $good,
            'no nonce'                      => array_diff_key( $good, [ 'n' => 1 ] ),
            'empty nonce'                   => [ 'n' => '' ] + $good,
            'nonce not a string'            => [ 'n' => 5 ] + $good,
        ];
        foreach ( $bad as $name => $data ) {
            $this->assertNull( PreviewToken::open( self::signed( $data ), 'a', 1000, 7 ), $name );
        }
        $payload = rtrim( strtr( base64_encode( 'not json' ), '+/', '-_' ), '=' );
        $this->assertNull( PreviewToken::open( $payload . '.' . wp_hash( 'aied-site|' . $payload ), 'a', 1000, 7 ), 'not json' );
    }
}
