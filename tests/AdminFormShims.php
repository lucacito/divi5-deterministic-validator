<?php

declare(strict_types=1);

/** The few admin form helpers the approval card needs. Guarded so they never clash with another shim. */

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) { return 'https://acme.example/wp-admin/' . $path; }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( $action = -1 ) { echo '<input type="hidden" name="_wpnonce" value="nonce-for-' . $action . '">'; }
}
if ( ! function_exists( 'checked' ) ) {
    function checked( $checked, $current = true, $display = true ) { $out = $checked == $current ? " checked='checked'" : ''; if ( $display ) { echo $out; } return $out; }
}
