<?php
/**
 * Real-site check of the Pro add-on skeleton: loaded, compatible with the free plugin, tab registered,
 * no Pro tools while locked, no remote call without a key, and the only host it would ever contact is
 * divi5lab.com. Read-only: it blocks every outgoing HTTP request for the duration of the check.
 *
 * Run: make verify-pro-skeleton   (needs `make up`, the free plugin and the Pro add-on active)
 */

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro on this site first.' );
}

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

$blocked = [];
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$blocked ) {
    $blocked[] = (string) $url;

    return new WP_Error( 'blocked', 'blocked by verify-pro-skeleton' );
}, 10, 3 );

$check( defined( 'AIED_PRO_VERSION' ), 'the add-on is loaded (' . ( defined( 'AIED_PRO_VERSION' ) ? AIED_PRO_VERSION : '?' ) . ')' );

$free   = defined( 'AI_EDITOR_DIVI5_VERSION' ) ? (string) AI_EDITOR_DIVI5_VERSION : null;
$compat = \AiEditorDivi5\Pro\Compat::check( $free, 'class_exists' );
$check( $compat['ok'], 'compatible with the free plugin ' . ( $free ?? '(missing)' ) . ' (minimum ' . \AiEditorDivi5\Pro\Compat::MIN_FREE . ')' );

$tabs = apply_filters( 'jhmg_aied_admin_tabs', [] );
$check( isset( $tabs['pro'] ), 'the Pro admin tab is registered through jhmg_aied_admin_tabs' );

$check( \AiEditorDivi5\Pro\Licensing::isPremium() === false, 'locked on this site (no licence activated)' );
$check( \AiEditorDivi5\Pro\Licensing::status()['reason'] === 'no_key', 'licence status is no_key' );

$builtIn = [ [ 'name' => 'get_page_layout' ] ];
$check( apply_filters( 'jhmg_aied_mcp_tools', $builtIn ) === $builtIn, 'no Pro tool is advertised while locked' );
$check( null === apply_filters( 'jhmg_aied_mcp_call', null, 'any_pro_tool', [], 1 ), 'no Pro tool is callable while locked' );

\AiEditorDivi5\Pro\Licensing::refresh( true );
$check( [] === $blocked, 'no remote call is made without a licence key' );

\AiEditorDivi5\Pro\Licensing::client()->inject_update( (object) [ 'response' => [] ] );
$hosts = array_unique( array_map( static fn ( string $u ): string => (string) wp_parse_url( $u, PHP_URL_HOST ), $blocked ) );
$check( [] !== $blocked, 'the updater tried to contact the update server (blocked here)' );
$check( [ 'divi5lab.com' ] === array_values( $hosts ), 'the only host contacted is divi5lab.com (' . implode( ', ', $hosts ) . ')' );

if ( $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'The Pro add-on skeleton is verified on the real site.' );
