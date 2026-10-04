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

    private function queue( int $code, array|string $body ): void
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

    private const TIER_BODY = [
        'status' => 'active', 'product' => 'ai-editor-divi5-pro', 'expires' => '2027-07-12T00:00:00.000Z',
        'tier' => 'freelancer', 'tier_label' => 'Freelancer', 'sites_used' => 3, 'sites_allowed' => 10,
        'founding' => true, 'lifetime' => false,
        'upgrade_url' => 'https://divi5lab.com/pricing', 'manage_url' => 'https://divi5lab.com/account/licenses',
    ];

    public function testActivationStoresTheTierDetailsTheServerReturned(): void
    {
        $this->queue( 200, self::TIER_BODY );
        $this->assertTrue( Licensing::activate( self::KEY )['ok'] );
        $extras = Licensing::extras();
        $this->assertSame( 'Freelancer', $extras['tier_label'] );
        $this->assertSame( 3, $extras['sites_used'] );
        $this->assertSame( 10, $extras['sites_allowed'] );
        $this->assertTrue( $extras['founding'] );
        $this->assertSame( 'https://divi5lab.com/pricing', $extras['upgrade_url'] );
        $this->assertFalse( Licensing::siteLimitHit() );
    }

    public function testAnActivationRefusedForTheSiteLimitIsReportedAndStoresTheLimit(): void
    {
        $this->queue( 403, [ 'error' => 'site_limit_reached', 'tier' => 'personal', 'tier_label' => 'Personal', 'sites_used' => 1, 'sites_allowed' => 1, 'upgrade_url' => 'https://divi5lab.com/pricing', 'manage_url' => 'https://divi5lab.com/account/licenses' ] );
        $res = Licensing::activate( self::KEY );
        $this->assertFalse( $res['ok'] );
        $this->assertSame( 'site_limit_reached', $res['error'] );
        $this->assertFalse( Licensing::isPremium() );
        $this->assertTrue( Licensing::siteLimitHit() );
        $this->assertSame( 1, Licensing::extras()['sites_allowed'] );
    }

    public function testASiteLimitAnswerOnRefreshNeverLocksOrChangesTheStoredState(): void
    {
        $this->queue( 200, self::TIER_BODY );
        Licensing::activate( self::KEY );
        $before = $GLOBALS['__wp_options']['aied_pro_license_state'];
        $this->ageState();
        $before['checked_at'] = $GLOBALS['__wp_options']['aied_pro_license_state']['checked_at'];
        $this->queue( 403, [ 'error' => 'site_limit_reached', 'tier' => 'freelancer', 'tier_label' => 'Freelancer', 'sites_used' => 10, 'sites_allowed' => 10 ] );
        Licensing::refresh();
        $this->assertTrue( Licensing::isPremium() );
        $this->assertSame( $before, $GLOBALS['__wp_options']['aied_pro_license_state'] );
        $this->assertTrue( Licensing::siteLimitHit() );
    }

    public function testAGoodRefreshClearsTheSiteLimitFlag(): void
    {
        $this->queue( 200, self::TIER_BODY );
        Licensing::activate( self::KEY );
        update_option( 'aied_pro_site_limit', 1, false );
        $this->ageState();
        $this->queue( 200, self::TIER_BODY );
        Licensing::refresh();
        $this->assertFalse( Licensing::siteLimitHit() );
    }

    public function testGarbageOnRefreshLeavesTheStoredTierDetailsAlone(): void
    {
        $this->queue( 200, self::TIER_BODY );
        Licensing::activate( self::KEY );
        $this->ageState();
        $this->queue( 200, '<html>captive portal</html>' );
        Licensing::refresh();
        $this->assertSame( 'Freelancer', Licensing::extras()['tier_label'] );
        $this->assertTrue( Licensing::isPremium() );
    }

    public function testDeactivateAndClearForgetTheTierDetails(): void
    {
        $this->queue( 200, self::TIER_BODY );
        Licensing::activate( self::KEY );
        $this->queue( 200, [ 'ok' => true ] );
        Licensing::deactivate();
        $this->assertNull( Licensing::extras()['tier_label'] );
        update_option( 'aied_pro_license_extras', [ 'tier_label' => 'x' ], false );
        update_option( 'aied_pro_site_limit', 1, false );
        Licensing::clear();
        $this->assertFalse( Licensing::siteLimitHit() );
        $this->assertNull( Licensing::extras()['tier_label'] );
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

    /** @return array<string,array{int,array|string}> */
    public static function serverFaults(): array
    {
        return [
            'html 403 from a proxy'                      => [ 403, '<html><body>Forbidden</body></html>' ],
            'html 404 from a broken deploy'              => [ 404, '<h1>Not Found</h1>' ],
            'empty 404'                                  => [ 404, '' ],
            'empty json 404'                             => [ 404, [] ],
            'invalid_request is our fault, not a verdict' => [ 400, [ 'error' => 'invalid_request' ] ],
            '403 with an unknown error code'             => [ 403, [ 'error' => 'teapot' ] ],
            'empty 200'                                  => [ 200, '' ],
            'html 200 from a captive portal'             => [ 200, '<html>proxy page</html>' ],
            '401 json with some status'                  => [ 401, [ 'status' => 'x' ] ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'serverFaults' )]
    public function testAServerFaultNeverLocksAndLeavesTheStoredStateAlone( int $code, array|string $body ): void
    {
        $this->activatePremium();
        $this->ageState();
        $stored = $GLOBALS['__wp_options']['aied_pro_license_state'];
        $this->queue( $code, $body );
        Licensing::refresh();
        $this->assertTrue( Licensing::isPremium(), 'a fault is not a verdict' );
        $this->assertSame( $stored, $GLOBALS['__wp_options']['aied_pro_license_state'], 'the stored state is exactly what it was' );
    }

    public function testAnExplicit200RevokedVerdictLocks(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 200, [ 'status' => 'revoked', 'expires' => null ] );
        Licensing::refresh();
        $this->assertFalse( Licensing::isPremium() );
    }

    public function testProductMismatchIsAnExplicitVerdictAndLocks(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 403, [ 'error' => 'product_mismatch' ] );
        Licensing::refresh();
        $this->assertFalse( Licensing::isPremium() );
    }

    public function testAFaultDoesNotUndoAnEarlierExplicitRevocation(): void
    {
        $this->testRevokedVerdictLocksPremium();
        $this->ageState();
        $this->queue( 403, '<html>Forbidden</html>' );
        Licensing::refresh();
        $this->assertFalse( Licensing::isPremium(), 'a fault changes nothing, in either direction' );
    }

    public function testAnExplicitlyRevokedLicenceStaysLockedWhenALaterRefreshGetsAnHtml200(): void
    {
        $this->testAnExplicit200RevokedVerdictLocks();
        $this->ageState();
        $stored = $GLOBALS['__wp_options']['aied_pro_license_state'];
        $this->queue( 200, '<html>proxy page</html>' );
        Licensing::refresh();
        $this->assertFalse( Licensing::isPremium(), 'a fault must not un-revoke a licence' );
        $this->assertSame( $stored, $GLOBALS['__wp_options']['aied_pro_license_state'] );
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

    public function testNoKeyMeansNoRemoteCallEvenWhenForced(): void
    {
        Licensing::refresh( true );
        $this->assertSame( [], $GLOBALS['__wp_http_log'] );
    }

    public function testAFreshStateIsNotRefreshedAgain(): void
    {
        $this->activatePremium();
        $GLOBALS['__wp_http_log'] = [];
        Licensing::refresh();
        $this->assertSame( [], $GLOBALS['__wp_http_log'], 'the client cache window is one day' );
    }

    public function testAnAjaxOrCronRequestNeverRefreshes(): void
    {
        $this->activatePremium();
        $this->ageState();
        $GLOBALS['__wp_http_log'] = [];
        $GLOBALS['__wp_doing_ajax'] = true;
        Licensing::refresh();
        $GLOBALS['__wp_doing_ajax'] = false;
        $GLOBALS['__wp_doing_cron'] = true;
        Licensing::refresh();
        $GLOBALS['__wp_doing_cron'] = false;
        $this->assertSame( [], $GLOBALS['__wp_http_log'] );
    }

    public function testAFaultStartsABackoffSoTheNextLoadsDoNotRetry(): void
    {
        $this->activatePremium();
        $this->ageState();
        $GLOBALS['__wp_http_log'] = [];
        $this->queue( 503, [] );
        Licensing::refresh();
        $this->assertCount( 1, $GLOBALS['__wp_http_log'] );
        $this->assertArrayHasKey( Licensing::REFRESH_BACKOFF_TRANSIENT, $GLOBALS['__wp_transients'] );
        Licensing::refresh();
        Licensing::refresh();
        $this->assertCount( 1, $GLOBALS['__wp_http_log'], 'backed off: no further request' );
        $this->assertTrue( Licensing::isPremium() );
    }

    public function testANetworkErrorAlsoBacksOff(): void
    {
        $this->activatePremium();
        $this->ageState();
        $GLOBALS['__wp_http_log']     = [];
        $GLOBALS['__wp_http_queue'][] = 'network_error';
        Licensing::refresh();
        Licensing::refresh();
        $this->assertCount( 1, $GLOBALS['__wp_http_log'] );
    }

    public function testAnExpiredBackoffRetriesAndASuccessClearsIt(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 503, [] );
        Licensing::refresh();
        $GLOBALS['__wp_transients'] = [];   // the backoff has expired
        $GLOBALS['__wp_http_log']   = [];
        $this->queue( 200, [ 'status' => 'active', 'expires' => null ] );
        Licensing::refresh();
        $this->assertCount( 1, $GLOBALS['__wp_http_log'] );
        $this->assertArrayNotHasKey( Licensing::REFRESH_BACKOFF_TRANSIENT, $GLOBALS['__wp_transients'] );
    }

    public function testForceBypassesTheBackoffAndTheAgeCheck(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 503, [] );
        Licensing::refresh();
        $GLOBALS['__wp_http_log'] = [];
        $this->queue( 200, [ 'status' => 'active', 'expires' => null ] );
        Licensing::refresh( true );
        $this->assertCount( 1, $GLOBALS['__wp_http_log'] );
    }

    public function testAFaultThatIsRevertedAlsoBacksOff(): void
    {
        $this->activatePremium();
        $this->ageState();
        $GLOBALS['__wp_http_log'] = [];
        $this->queue( 403, '<html>Forbidden</html>' );
        Licensing::refresh();
        Licensing::refresh();
        $this->assertCount( 1, $GLOBALS['__wp_http_log'] );
    }

    public function testClearAlsoRemovesTheBackoff(): void
    {
        set_transient( Licensing::REFRESH_BACKOFF_TRANSIENT, 1, 900 );
        Licensing::clear();
        $this->assertArrayNotHasKey( Licensing::REFRESH_BACKOFF_TRANSIENT, $GLOBALS['__wp_transients'] );
    }

    public function testTheApiBaseOverrideIsHonouredOnlyOnALocalOrDevelopmentSite(): void
    {
        $this->assertSame( 'http://localhost:3000', Licensing::apiBaseFor( 'http://localhost:3000/', 'local' ) );
        $this->assertSame( 'http://localhost:3000', Licensing::apiBaseFor( 'http://localhost:3000', 'development' ) );
        foreach ( [ 'production', 'staging', '' ] as $env ) {
            $this->assertSame( 'https://divi5lab.com', Licensing::apiBaseFor( 'https://evil.example', $env ), "env: {$env}" );
        }
        $this->assertSame( 'https://divi5lab.com', Licensing::apiBaseFor( null, 'local' ) );
        $this->assertSame( 'https://divi5lab.com', Licensing::apiBaseFor( '', 'local' ) );
    }
}
