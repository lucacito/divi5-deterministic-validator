<?php
/**
 * Real-site check of the Pro add-on against a LOCAL copy of the divi5lab.com licence server (the layoutlab repo run with
 * `next dev`, seeded with the gate licences below). It makes real HTTP calls to that server and never to divi5lab.com.
 * It proves, end to end: a Personal licence activates and shows its tier and 1-of-1 site; a second site is refused with
 * the site-limit answer and is NOT unlocked; an Agency licence shows unlimited sites and unlocks all 14 tools; after the
 * server reports the licence EXPIRED, every Pro tool is still advertised and callable and the lapse notice is shown once
 * and can be dismissed. Self-cleaning: run the stages in order and finish with `cleanup`, which restores every aied_pro_* option
 * the dev site had before the first stage (a licence already activated there comes back).
 *
 * Run: make verify-pro-licence-tiers LICENCE_BASE=http://host.docker.internal:3010 STAGE=<limit|agency|expired>
 * (the stages need the server-side state described in docs/superpowers/plans/2026-10-04-pricing-tiers.md; the lead runs them in order).
 */

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro on this site first.' );
}

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$stage = (string) ( $args[0] ?? '' );
$base  = rtrim( (string) ( $args[1] ?? '' ), '/' );
if ( '' === $stage || '' === $base || ! preg_match( '#^http://(host\.docker\.internal|localhost|127\.0\.0\.1)(:\d+)?$#', $base ) ) {
    WP_CLI::error( 'Usage: wp eval-file verify-pro-licence-tiers.php <limit|agency|expired|cleanup> http://host.docker.internal:3010' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

// Point the add-on's licence client at the local server (the override constant is only honoured on local/dev sites by
// design; the script injects a client instead so it never depends on the site's environment type).
$ref = new ReflectionProperty( \AiEditorDivi5\Pro\Licensing::class, 'client' );
$ref->setAccessible( true );
$ref->setValue( null, new \AiEditorDivi5\Pro\Licensing\LicenseClient(
    AIED_PRO_PRODUCT, AIED_PRO_VERSION, $base, plugin_basename( AIED_PRO_FILE ), 'ai-editor-divi5',
    \AiEditorDivi5\Pro\Licensing::UPGRADE_URL, 'aied_pro'
) );

$keys = [
    'personal' => 'JHMG-GATE-PERS-ONAL-AAAA',
    'agency'   => 'JHMG-GATE-AGEN-CYYY-CCCC',
];
$wipe = static function (): void {
    foreach ( [ 'aied_pro_premium_unlocked', 'aied_pro_license_key', 'aied_pro_license_state', 'aied_pro_update_blocked', 'aied_pro_license_extras', 'aied_pro_site_limit' ] as $o ) {
        delete_option( $o );
    }
    delete_transient( \AiEditorDivi5\Pro\Licensing::REFRESH_BACKOFF_TRANSIENT );
};

// Remember what the dev site had before the first stage, so `cleanup` can put it back.
$optionNames = [ 'aied_pro_premium_unlocked', 'aied_pro_license_key', 'aied_pro_license_state', 'aied_pro_update_blocked', 'aied_pro_license_extras', 'aied_pro_site_limit' ];
if ( 'cleanup' !== $stage && false === get_option( 'aied_gate_saved', false ) ) {
    $saved = [];
    foreach ( $optionNames as $o ) {
        $v = get_option( $o, '__absent__' );
        if ( '__absent__' !== $v ) {
            $saved[ $o ] = $v;
        }
    }
    update_option( 'aied_gate_saved', [ 'options' => $saved ], false );
}

$registryCount = static function (): int {
    $tools = apply_filters( 'jhmg_aied_mcp_tools', [] );

    return is_array( $tools ) ? count( $tools ) : 0;
};
$expected = count( \AiEditorDivi5\Pro\Tools::all() );
$check( 14 === $expected, "the add-on defines {$expected} Pro tools (expected 14)" );

if ( 'limit' === $stage ) {
    // Server state: the Personal licence's single slot is already used by another site.
    $wipe();
    $res = \AiEditorDivi5\Pro\Licensing::activate( $keys['personal'] );
    $check( false === $res['ok'] && 'site_limit_reached' === $res['error'], 'a second site on a one-site licence is refused with site_limit_reached' );
    $check( false === \AiEditorDivi5\Pro\Licensing::isPremium(), 'the refused site is NOT unlocked' );
    $check( true === \AiEditorDivi5\Pro\Licensing::siteLimitHit(), 'the add-on remembers the site-limit answer' );
    $x = \AiEditorDivi5\Pro\Licensing::extras();
    $check( 'Personal' === $x['tier_label'] && 1 === $x['sites_allowed'] && 1 === $x['sites_used'], 'the refusal carries the tier and the 1-of-1 count' );
    $check( 'site_limit' === \AiEditorDivi5\Pro\ProPage::failureNotice( $res['error'] ), 'the admin notice for it is the site_limit message' );
    $check( 0 === $registryCount(), 'no Pro tool is advertised on the refused site' );
    $wipe();
}

if ( 'agency' === $stage ) {
    $wipe();
    $res = \AiEditorDivi5\Pro\Licensing::activate( $keys['agency'] );
    $check( true === $res['ok'], 'an Agency licence activates on this site' );
    $check( true === \AiEditorDivi5\Pro\Licensing::isPremium(), 'the site is unlocked' );
    $x = \AiEditorDivi5\Pro\Licensing::extras();
    $check( 'Agency' === $x['tier_label'] && null === $x['sites_allowed'] && $x['sites_used'] >= 1, 'it shows Agency with unlimited sites' );
    // The local server's origin is not divi5lab.com, so its links are (correctly) not trusted; the card falls back to the known pages.
    $check( null === $x['upgrade_url'] && null === $x['manage_url'], 'links on a host other than the licence host are ignored' );
    $page = new \AiEditorDivi5\Pro\ProPage( new \AiEditorDivi5\Pro\ToolRegistry( \AiEditorDivi5\Pro\Tools::all(), static fn (): bool => true ) );
    wp_set_current_user( 1 );
    ob_start();
    $page->render( 'pro' );
    $card = (string) ob_get_clean();
    $check( str_contains( $card, 'Agency' ) && str_contains( $card, 'unlimited' ), 'the Pro tab shows the Agency tier and unlimited sites' );
    $check( str_contains( $card, \AiEditorDivi5\Pro\Licensing::MANAGE_URL ) && str_contains( $card, \AiEditorDivi5\Pro\Licensing::UPGRADE_URL ), 'the Pro tab still offers Manage sites and Upgrade' );
    $check( $registryCount() === $expected, 'all 14 Pro tools are advertised' );
    // leave it activated for the expired stage
}

if ( 'expired' === $stage ) {
    // Server state: the Agency licence has just been marked expired in the store.
    \AiEditorDivi5\Pro\Licensing::refresh( true );
    $st = \AiEditorDivi5\Pro\Licensing::status();
    $check( 'expired' === $st['status'], 'the add-on now reports the licence as expired' );
    $check( true === \AiEditorDivi5\Pro\Licensing::isPremium(), 'an expired licence does NOT lock the site' );
    $check( $registryCount() === $expected, 'all 14 Pro tools are still advertised after expiry' );

    $notice = new \AiEditorDivi5\Pro\Admin\LapseNotice( static fn (): array => \AiEditorDivi5\Pro\Licensing::status(), static function (): void {
    } );
    wp_set_current_user( 1 );
    set_current_screen( 'dashboard' );
    ob_start();
    $notice->display();
    $html = (string) ob_get_clean();
    $check( str_contains( $html, 'Your licence has ended' ) && str_contains( $html, 'keeps working' ), 'one lapse notice is shown, promising everything keeps working' );
    $check( 1 === substr_count( $html, 'class="notice' ), 'exactly one notice' );
    preg_match( '/href="([^"]*aied_pro_dismiss_lapse_notice[^"]*)"/', $html, $m );
    $check( isset( $m[1] ), 'it carries a dismiss link' );
    if ( isset( $m[1] ) ) {
        parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $q );
        $_GET     = $q;
        $_REQUEST = $q;
        try {
            $notice->handleDismiss();
        } catch ( \Throwable $e ) {
            WP_CLI::log( 'note: ' . $e->getMessage() );
        }
        ob_start();
        $notice->display();
        $after = (string) ob_get_clean();
        $check( ! str_contains( $after, 'Your licence has ended' ), 'after dismissing, the notice stays gone for this lapse' );
    }
    delete_user_meta( 1, 'aied_pro_lapse_dismissed' );
    \AiEditorDivi5\Pro\Licensing::deactivate();
    $wipe();
}

if ( 'cleanup' === $stage ) {
    $wipe();
    $saved = get_option( 'aied_gate_saved', false );
    if ( is_array( $saved ) ) {
        foreach ( (array) ( $saved['options'] ?? [] ) as $name => $value ) {
            update_option( $name, $value, false );
        }
        delete_option( 'aied_gate_saved' );
    }
    \AiEditorDivi5\Pro\Licensing::resetForTests();
    $check( ! is_array( $saved ) || ( [] === ( $saved['options'] ?? [] ) ) === ( null === \AiEditorDivi5\Pro\Licensing::client()->get_key() ), 'cleaned: the dev site is back to what it had before (a licence it had is restored; none stays none)' );
}

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed.' );
}
WP_CLI::success( "stage '{$stage}' passed." );
