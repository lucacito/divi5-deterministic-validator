<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Licensing;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/autoload.php';

/**
 * Verify that all 14 Pro tools are callable in lapsed states: expired, canceled, past_due, and lifetime.
 * This test ensures that expiry and lapses never disable the Pro tools.
 */
class ExpiryKeepsAllToolsTest extends TestCase
{
    private const KEY = 'JHMG-TEST-TEST-TEST-TEST';

    protected function setUp(): void
    {
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        $GLOBALS['__wp_http_queue'] = [];
        $GLOBALS['__wp_http_log']   = [];
        $GLOBALS['__wp_filters']    = [];
        $GLOBALS['__wp_can']        = true;
        unset( $GLOBALS['__wp_caps'] );
        Licensing::resetForTests();
    }

    private function queue( int $code, array|string $body ): void
    {
        $GLOBALS['__wp_http_queue'][] = [ 'code' => $code, 'body' => $body ];
    }

    private function ageState(): void
    {
        $state = $GLOBALS['__wp_options']['aied_pro_license_state'];
        $state['checked_at'] = time() - 2 * DAY_IN_SECONDS;
        $GLOBALS['__wp_options']['aied_pro_license_state'] = $state;
    }

    private function activatePremium(): void
    {
        $this->queue( 200, [ 'status' => 'active', 'product' => 'ai-editor-divi5-pro', 'expires' => '2027-07-12T00:00:00.000Z' ] );
        $res = Licensing::activate( self::KEY );
        $this->assertTrue( $res['ok'] );
        $this->assertTrue( Licensing::isPremium() );
    }

    /** @return list<string> */
    private static function allToolNames(): array
    {
        return array_map( static fn ( $t ): string => $t->name(), Tools::all() );
    }

    private function registry(): ToolRegistry
    {
        return new ToolRegistry( Tools::all(), static fn (): bool => Licensing::isPremium() );
    }

    /** @return array<string,array{0:string}> */
    public static function lapsedStatuses(): array
    {
        return [ 'expired' => [ 'expired' ], 'canceled' => [ 'canceled' ], 'past_due' => [ 'past_due' ] ];
    }

    #[DataProvider( 'lapsedStatuses' )]
    public function testALapsedLicenceKeepsEveryProToolAdvertisedAndClaimable( string $status ): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 403, [ 'error' => 'license_not_usable', 'status' => $status ] );
        Licensing::refresh();

        $this->assertSame( $status, Licensing::status()['status'], 'the add-on really holds the lapsed status' );
        $this->assertTrue( Licensing::isPremium(), 'a lapse never locks' );
        $this->assertKeepsAllTools();
    }

    public function testALifetimeLicenceKeepsEveryProTool(): void
    {
        $this->queue( 200, [ 'status' => 'active', 'product' => 'ai-editor-divi5-pro', 'expires' => null, 'lifetime' => true ] );
        $this->assertTrue( Licensing::activate( self::KEY )['ok'] );
        $this->assertTrue( Licensing::extras()['lifetime'] );
        $this->assertKeepsAllTools();
    }

    public function testASiteLimitAnswerOnRefreshKeepsEveryProTool(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 403, [ 'error' => 'site_limit_reached', 'tier' => 'personal', 'tier_label' => 'Personal', 'sites_used' => 1, 'sites_allowed' => 1 ] );
        Licensing::refresh();
        $this->assertSame( 'active', Licensing::status()['status'] );
        $this->assertKeepsAllTools();
    }

    public function testARevokedLicenceDoesLockEveryProTool(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 200, [ 'status' => 'revoked' ] );
        Licensing::refresh();

        $this->assertFalse( Licensing::isPremium() );
        $this->assertSame( [], $this->advertised( [] ), 'nothing is advertised' );
        $this->assertNull( $this->registry()->claim( null, 'propose_php_snippet', [], 1 ), 'nothing is claimed' );
    }

    /** @param list<array<string,mixed>> $builtIn @return list<string> */
    private function advertised( array $builtIn ): array
    {
        $out = $this->registry()->definitions( $builtIn );

        return array_values( array_map( static fn ( array $d ): string => (string) $d['name'], $out ) );
    }

    private function assertKeepsAllTools(): void
    {
        $names = self::allToolNames();
        $this->assertCount( 14, $names );
        $listed = $this->advertised( [] );
        sort( $listed );
        $sorted = $names;
        sort( $sorted );
        $this->assertSame( $sorted, $listed, 'all 14 Pro tools are advertised' );

        $response = $this->registry()->claim( null, 'propose_php_snippet', [], 1 );
        $this->assertNotNull( $response, 'a Pro tool call is claimed (not passed through) while unlocked' );
    }
}
