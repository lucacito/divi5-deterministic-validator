<?php

declare(strict_types=1);

/**
 * Vendor-side license key generator for AI Editor for Divi 5.
 *
 * Signs a license payload with the Ed25519 SECRET key so the plugin (which holds
 * only the matching public key) can verify it offline. The secret key must NEVER
 * be committed or shipped inside the plugin zip.
 *
 * Secret key source (first found wins):
 *   1. env  AIED_LICENSE_SECRET   (base64 of the 64-byte sodium secret key)
 *   2. file <repo-root>/license-signing-key.txt   (same base64)
 *
 * Usage:
 *   php scripts/make-license.php <email> [--days=365] [--domain=example.com]
 *
 * Examples:
 *   php scripts/make-license.php jane@acme.com                       # perpetual, any domain
 *   php scripts/make-license.php jane@acme.com --days=365            # 1-year, any domain
 *   php scripts/make-license.php jane@acme.com --domain=acme.com     # perpetual, bound to acme.com
 */

if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
    fwrite( STDERR, "Error: libsodium not available in this PHP build.\n" );
    exit( 1 );
}

$args  = array_slice( $argv, 1 );
$email = null;
$days  = 0;
$domain = '';

foreach ( $args as $arg ) {
    if ( str_starts_with( $arg, '--days=' ) ) {
        $days = (int) substr( $arg, 7 );
    } elseif ( str_starts_with( $arg, '--domain=' ) ) {
        $domain = trim( substr( $arg, 9 ) );
    } elseif ( ! str_starts_with( $arg, '--' ) && $email === null ) {
        $email = trim( $arg );
    }
}

if ( $email === null || $email === '' || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
    fwrite( STDERR, "Usage: php scripts/make-license.php <email> [--days=N] [--domain=host]\n" );
    exit( 1 );
}

// --- Load the secret key ---------------------------------------------------
$secretB64 = getenv( 'AIED_LICENSE_SECRET' ) ?: '';
if ( $secretB64 === '' ) {
    $keyFile = dirname( __DIR__ ) . '/license-signing-key.txt';
    if ( is_readable( $keyFile ) ) {
        $secretB64 = trim( (string) file_get_contents( $keyFile ) );
    }
}
if ( $secretB64 === '' ) {
    fwrite( STDERR, "Error: no secret key. Set AIED_LICENSE_SECRET or create license-signing-key.txt.\n" );
    exit( 1 );
}

$secret = base64_decode( $secretB64, true );
if ( $secret === false || strlen( $secret ) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ) {
    fwrite( STDERR, "Error: secret key is not a valid base64 Ed25519 secret key.\n" );
    exit( 1 );
}

// --- Build and sign the payload --------------------------------------------
$payload = [
    'email' => $email,
    'plan'  => 'premium',
    'iss'   => time(),
    'exp'   => $days > 0 ? time() + ( $days * 86400 ) : 0,
];
if ( $domain !== '' ) {
    $payload['domain'] = $domain;
}

$payloadJson = json_encode( $payload, JSON_UNESCAPED_SLASHES );
$signature   = sodium_crypto_sign_detached( $payloadJson, $secret );

$b64url  = static fn( string $b ): string => rtrim( strtr( base64_encode( $b ), '+/', '-_' ), '=' );
$license = $b64url( $payloadJson ) . '.' . $b64url( $signature );

// --- Output ----------------------------------------------------------------
fwrite( STDERR, "License for {$email}" );
fwrite( STDERR, $domain !== '' ? " (domain: {$domain})" : ' (any domain)' );
fwrite( STDERR, $days > 0 ? ", expires in {$days} days:\n" : ", perpetual:\n" );
echo $license . "\n";
