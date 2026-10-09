<?php

declare(strict_types=1);

/**
 * A stand-in for core's wp_kses_post() for the Pro unit tests (no namespace: the code under test calls the global function).
 * It keeps the usual post-content tags and drops others. The real function runs in `make verify-pro-custom-fields`.
 */
if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $s ) { return (string) preg_replace( '#</?(?!(?:p|br|strong|em|b|i|a|ul|ol|li|blockquote|div|span|h[1-6])\b)[a-z][^>]*>#i', '', (string) $s ); }
}
