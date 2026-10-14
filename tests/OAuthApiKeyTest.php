<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ApiKey;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
foreach ( [ 'OAuthConfig', 'OAuthStoreInterface', 'OAuthStore', 'OAuthTable', 'OAuthSettings', 'OAuthTokens', 'OAuthRuntime', 'ApiKey', 'PageAccess', 'UsageTracker', 'PageHistory', 'HistoryTable', 'HistoryStore', 'EditTarget', 'HistoryService', 'PageEditor', 'LayoutDiff', 'CodePolicy', 'LayoutGate', 'DryRun', 'ApprovalMode', 'StyleGuide', 'GlobalStyles', 'CustomFields', 'StyleReferences', 'RestController', 'McpHandler' ] as $f ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $f . '.php';
}

/**
 * ApiKey::authenticateRequest() gained an OAuth branch. The published API-key behaviour must not move, and an
 * OAuth-shaped token that is wrong must be a 401 (a 403 would stop Claude from refreshing its token).
 */
final class OAuthApiKeyTest extends TestCase
{
    private const KEY = 'abcdef0123456789abcdef0123456789';

    protected function setUp(): void
    {
        $GLOBALS['wpdb']                 = new FakeWpdb();
        $GLOBALS['__wp_options']         = [ 'ai_editor_divi5_api_key' => self::KEY, 'ai_editor_divi5_api_user_id' => 7 ];
        $GLOBALS['__wp_current_user_id'] = 0;
        $GLOBALS['__wp_can']             = true;
        unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
    }

    protected function tearDown(): void
    {
        unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function headers(): array
    {
        return [
            'plain'              => [ 'Bearer abc', 'abc' ],
            'lower case scheme'  => [ 'bearer abc', 'abc' ],
            'extra spaces'       => [ 'Bearer    abc  ', 'abc' ],
            'basic auth'         => [ 'Basic dXNlcjpwYXNz', '' ],
            'no token'           => [ 'Bearer ', '' ],
            'empty'              => [ '', '' ],
            'token only'         => [ 'abc', '' ],
        ];
    }

    #[DataProvider('headers')]
    public function testBearerParsing( string $header, string $expected ): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = $header;
        $this->assertSame( $expected, ApiKey::bearer() );
    }

    public function testRedirectHttpAuthorizationIsHonouredToo(): void
    {
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer viaredirect';
        $this->assertSame( 'viaredirect', ApiKey::bearer() );
    }

    public function testTheApiKeyStillAuthenticatesAsItsOwner(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::KEY;
        $this->assertTrue( ApiKey::authenticateRequest() );
        $this->assertSame( 7, $GLOBALS['__wp_current_user_id'] );
    }

    public function testAWrongOrMissingKeyStillFails(): void
    {
        $this->assertFalse( ApiKey::authenticateRequest(), 'no header' );
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong';
        $this->assertFalse( ApiKey::authenticateRequest() );
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';
        $this->assertFalse( ApiKey::authenticateRequest() );
        $this->assertSame( 0, $GLOBALS['__wp_current_user_id'], 'nobody is signed in by a failed attempt' );
    }

    public function testAnOAuthShapedTokenNeverBecomesTheKeyOwnerWhileOAuthIsOff(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer aied_at_' . str_repeat( 'a', 64 );
        $this->assertFalse( ApiKey::authenticateRequest() );
        $this->assertSame( 0, $GLOBALS['__wp_current_user_id'] );
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function shapes(): array
    {
        return [
            'access'  => [ 'Bearer aied_at_' . 'a', true ],
            'refresh' => [ 'Bearer aied_rt_' . 'a', true ],
            'code'    => [ 'Bearer aied_ac_' . 'a', true ],
            'the key' => [ 'Bearer ' . self::KEY, false ],
            'other'   => [ 'Bearer aied_zz_a', false ],
            'none'    => [ '', false ],
        ];
    }

    #[DataProvider('shapes')]
    public function testPresentsOAuthToken( string $header, bool $expected ): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = $header;
        $this->assertSame( $expected, ApiKey::presentsOAuthToken() );
    }

    public function testMcpAnswersAWrongOAuthTokenWith401AndAWrongKeyWithItsOld403(): void
    {
        $mcp = new McpHandler();

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer aied_at_' . str_repeat( '0', 64 );
        $r = $mcp->authenticate();
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 'invalid_token', $r->code );
        $this->assertSame( 401, $r->data['status'] );

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer aied_rt_' . str_repeat( '0', 64 );
        $this->assertSame( 401, $mcp->authenticate()->data['status'], 'a refresh token used as an access token' );

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-the-key';
        $r = $mcp->authenticate();
        $this->assertSame( 'forbidden', $r->code, 'published behaviour for a wrong API key' );
        $this->assertSame( 403, $r->data['status'] );

        unset( $_SERVER['HTTP_AUTHORIZATION'] );
        $this->assertSame( 401, $mcp->authenticate()->data['status'] );

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::KEY;
        $this->assertTrue( $mcp->authenticate() );
    }

    public function testADemotedKeyOwnerIsStillRefused(): void
    {
        $GLOBALS['__wp_can']           = false;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::KEY;
        $r = ( new McpHandler() )->authenticate();
        $this->assertSame( 403, $r->data['status'] );
        $this->assertStringContainsString( 'API key', $r->message );
    }

    public function testRestUsesTheSameKeyPath(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::KEY;
        $this->assertTrue( ( new RestController() )->require_edit_posts() );
    }
}
