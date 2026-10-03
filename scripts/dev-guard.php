<?php
/**
 * Shared by the verify-pro-*.php scripts. They create, change and delete real content (and evict old batch rows),
 * so they only run on a local development site: host localhost, 127.0.0.1, ::1, *.local, *.test or *.localhost.
 * Pure functions apart from the final check, so tests/DevGuardTest.php can load this file without WordPress.
 */

if ( ! function_exists( 'aied_is_dev_host' ) ) {
    function aied_is_dev_host( string $host ): bool
    {
        $host = strtolower( trim( $host ) );

        return in_array( $host, [ 'localhost', '127.0.0.1', '::1', '[::1]' ], true )
            || 1 === preg_match( '/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:local|test|localhost)$/', $host );
    }
}

if ( ! function_exists( 'aied_require_dev_site' ) ) {
    function aied_require_dev_site(): void
    {
        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! aied_is_dev_host( $host ) ) {
            WP_CLI::error( "This script creates, changes and deletes content, so it only runs on a local development site (host localhost, 127.0.0.1, *.local, *.test). This site's host is '{$host}'." );
        }
    }
}
