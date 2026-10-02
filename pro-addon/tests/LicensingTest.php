<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Licensing;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Licensing/LicenseClient.php';
require_once __DIR__ . '/../src/Licensing.php';

/**
 * Sticky-unlock enforcement matrix: activation persists premium; ONLY an
 * explicit server verdict of `revoked` or `invalid` re-locks; lapse and
 * transient failures never do.
 */
class LicensingTest extends TestCase
{
    private const KEY = 'JHMG-TEST-TEST-TEST-TEST';

    protected function setUp(): void
    {
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        $GLOBALS['__wp_http_queue'] = [];
        $GLOBALS['__wp_http_log']   = [];
        $GLOBALS['__wp_home_url']   = 'https://example.com';
        Licensing::resetForTests();
    }

    private function queue( int $code, array $body ): void
    {
        $GLOBALS['__wp_http_queue'][] = [ 'code' => $code, 'body' => $body ];
    }

    /** Age the cached state so refresh() actually hits the network. */
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

    public function testFreshInstallIsNotPremium(): void
    {
        $this->assertFalse( Licensing::isPremium() );
        $this->assertSame( 'no_key', Licensing::status()['reason'] );
    }

    public function testActivateSuccessUnlocksPremium(): void
    {
        $this->activatePremium();
        $this->assertSame( 'active', Licensing::status()['status'] );
        // activate posted to /api/license/activate with snake_case params
        $payload = json_decode( $GLOBALS['__wp_http_log'][0]['payload'], true );
        $this->assertSame( 'ai-editor-divi5-pro', $payload['product'] );
        $this->assertSame( self::KEY, $payload['key'] );
        $this->assertArrayHasKey( 'site_url', $payload );
    }

    public function testActivateInvalidKeyStaysLocked(): void
    {
        $this->queue( 404, [ 'error' => 'invalid_key' ] );
        $res = Licensing::activate( self::KEY );
        $this->assertFalse( $res['ok'] );
        $this->assertSame( 'invalid_key', $res['error'] );
        $this->assertFalse( Licensing::isPremium() );
    }

    public function testExpiredVerdictKeepsPremium(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 403, [ 'error' => 'license_not_usable', 'status' => 'expired' ] );
        Licensing::refresh();
        $this->assertTrue( Licensing::isPremium() );        // lapse never locks
        $this->assertSame( 'expired', Licensing::status()['status'] ); // but status is honest
    }

    public function testCanceledVerdictKeepsPremium(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 403, [ 'error' => 'license_not_usable', 'status' => 'canceled' ] );
        Licensing::refresh();
        $this->assertTrue( Licensing::isPremium() );
    }

    public function testRevokedVerdictLocksPremium(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 403, [ 'error' => 'license_not_usable', 'status' => 'revoked' ] );
        Licensing::refresh();
        $this->assertFalse( Licensing::isPremium() );
    }

    public function testInvalidKeyVerdictLocksPremium(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 404, [ 'error' => 'invalid_key' ] );
        Licensing::refresh();
        $this->assertFalse( Licensing::isPremium() );
    }

    public function testNetworkErrorKeepsPremium(): void
    {
        $this->activatePremium();
        $this->ageState();
        $GLOBALS['__wp_http_queue'][] = 'network_error';
        Licensing::refresh();
        $this->assertTrue( Licensing::isPremium() );
    }

    public function testRateLimitAnd5xxKeepPremium(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 429, [ 'error' => 'rate_limited' ] );
        Licensing::refresh();
        $this->assertTrue( Licensing::isPremium() );
        $this->ageState();
        $this->queue( 500, [] );
        Licensing::refresh();
        $this->assertTrue( Licensing::isPremium() );
    }

    public function testReactivationAfterRevokeUnlocksAgain(): void
    {
        $this->testRevokedVerdictLocksPremium();
        $this->queue( 200, [ 'status' => 'active', 'product' => 'ai-editor-divi5-pro', 'expires' => null ] );
        $res = Licensing::activate( 'JHMG-NEWK-NEWK-NEWK-NEWK' );
        $this->assertTrue( $res['ok'] );
        $this->assertTrue( Licensing::isPremium() );
    }

    public function testDeactivateClearsPremium(): void
    {
        $this->activatePremium();
        $this->queue( 200, [ 'ok' => true ] ); // server deactivate call
        Licensing::deactivate();
        $this->assertFalse( Licensing::isPremium() );
        $this->assertSame( 'no_key', Licensing::status()['reason'] );
        // deactivate() must release the activation server-side (not just wipe locally, like clear()).
        $this->assertStringContainsString( '/api/license/deactivate', $GLOBALS['__wp_http_log'][1]['url'] );
    }

    public function testStatusExposesUnixExpires(): void
    {
        $this->activatePremium();
        $this->assertSame( strtotime( '2027-07-12T00:00:00.000Z' ), Licensing::status()['expires'] );
    }

    public function testClearWipesLocalStateOnly(): void
    {
        $this->activatePremium();
        Licensing::clear(); // uninstall path: no HTTP queued, must not error
        $this->assertFalse( Licensing::isPremium() );
        $this->assertSame( [], array_filter( array_keys( $GLOBALS['__wp_options'] ), fn ( $k ) => str_starts_with( $k, 'aied_' ) ) );
    }

    public function testClientCarriesTheUpdater(): void
    {
        $this->assertTrue( method_exists( Licensing::client(), 'inject_update' ) );
    }

    public function testTheClientIsScopedToTheProAddOn(): void
    {
        $this->activatePremium();
        $this->assertArrayHasKey( 'aied_pro_license_key', $GLOBALS['__wp_options'] );
        $this->assertArrayNotHasKey( 'aied_license_key', $GLOBALS['__wp_options'], 'never collides with the old in-plugin licensing options' );
        $this->assertTrue( (bool) $GLOBALS['__wp_options']['aied_pro_premium_unlocked'] );
    }

    public function testTheUpdaterOnlyAcceptsAnHttpsPackageOnTheLicenceHost(): void
    {
        $this->activatePremium();
        $base = plugin_basename( AIED_PRO_FILE );
        foreach ( [ 'https://evil.example/pkg.zip', 'http://divi5lab.com/pkg.zip', 'https://divi5lab.com.evil.example/pkg.zip' ] as $package ) {
            $GLOBALS['__wp_transients'] = [];
            $this->queue( 200, [ 'update' => true, 'version' => '9.9.9', 'package' => $package ] );
            $t = Licensing::client()->inject_update( (object) [ 'response' => [] ] );
            $this->assertSame( [], (array) $t->response, $package );
        }
        $GLOBALS['__wp_transients'] = [];
        $this->queue( 200, [ 'update' => true, 'version' => '9.9.9', 'package' => 'https://divi5lab.com/downloads/pro.zip' ] );
        $t = Licensing::client()->inject_update( (object) [ 'response' => [] ] );
        $this->assertArrayHasKey( $base, (array) $t->response );
        $this->assertSame( '9.9.9', $t->response[ $base ]->new_version );
    }

    public function testTheUpdaterIgnoresAVersionThatIsNotNewer(): void
    {
        $this->activatePremium();
        $GLOBALS['__wp_transients'] = [];
        $this->queue( 200, [ 'update' => true, 'version' => AIED_PRO_VERSION, 'package' => 'https://divi5lab.com/downloads/pro.zip' ] );
        $t = Licensing::client()->inject_update( (object) [ 'response' => [] ] );
        $this->assertSame( [], (array) $t->response );
    }

    public function testTheUpdaterToleratesAReplyWithNoUpdate(): void
    {
        $GLOBALS['__wp_transients'] = [];
        $this->queue( 200, [] );
        $t = Licensing::client()->inject_update( (object) [ 'response' => [] ] );
        $this->assertIsObject( $t );
        $this->assertSame( [], (array) $t->response );
    }
}
