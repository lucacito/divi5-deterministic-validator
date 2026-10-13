<?php
/**
 * Runs in a SECOND wp-cli process while WooCommerce is deactivated (see the Makefile target): the free plugin must answer
 * "WooCommerce is not active" as a normal success (checked over REST only), and must not fatal.
 */

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

if ( function_exists( 'wc_get_products' ) ) {
    WP_CLI::error( 'WooCommerce is still active in this process; the Makefile target deactivates it first.' );
}
wp_set_current_user( 1 );
$req = new WP_REST_Request( 'GET', '/ai-editor-divi5/v1/products' );
$res = rest_do_request( $req );
$d   = (array) $res->get_data();
$ok  = 200 === $res->get_status() && false === ( $d['woocommerce']['active'] ?? null ) && [] === ( $d['products'] ?? null ) && ! empty( $d['message'] );
WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . 'list_products answers "not active" as a normal success' );
$req = new WP_REST_Request( 'GET', '/ai-editor-divi5/v1/products/1' );
$res = rest_do_request( $req );
$d2  = (array) $res->get_data();
$ok2 = 200 === $res->get_status() && false === ( $d2['woocommerce']['active'] ?? null ) && array_key_exists( 'product', $d2 ) && null === $d2['product']; // not `?? 'x'`: `??` treats a present null as missing
WP_CLI::log( ( $ok2 ? 'PASS  ' : 'FAIL  ' ) . 'get_product answers "not active" as a normal success' );
if ( ! $ok || ! $ok2 ) {
    WP_CLI::error( 'inactive-WooCommerce behaviour NOT verified.' );
}
WP_CLI::success( 'The plugin is quiet and correct without WooCommerce.' );
