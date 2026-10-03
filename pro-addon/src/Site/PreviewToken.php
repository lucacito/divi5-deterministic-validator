<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Site;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The preview token of a site-wide write. It is self-contained and signed (`wp_hash`): it carries a hash of the
 * arguments, the id => content-hash of every item the preview would change, the time it was made, the user who made
 * it and a one-time nonce. A real run must present it: it only opens for that user, for those arguments, within
 * `TTL` seconds and before it was spent, and items whose content changed since the preview are skipped, never
 * overwritten. `spend()` records the nonce in a transient, so a token cannot be replayed (for example after an
 * `undo_batch` put the previewed content back). The record is best effort: an object cache that evicts it can make
 * a spent token usable again inside its 30 minutes (same user, same arguments); the changed-since check still catches
 * a content change since the preview, but not a replay after an undo that restored the previewed content.
 */
final class PreviewToken
{
    public const TTL = 1800;

    private const SPENT = 'aied_pro_tok_';

    /** @param array<int,string> $items item id => content hash */
    public static function make( string $argsHash, array $items, int $now, int $userId ): string
    {
        $payload = rtrim( strtr( base64_encode( (string) json_encode( [ 'v' => 2, 'a' => $argsHash, 'i' => $items, 't' => $now, 'u' => $userId, 'n' => bin2hex( random_bytes( 8 ) ) ] ) ), '+/', '-_' ), '=' );

        return $payload . '.' . wp_hash( 'aied-site|' . $payload );
    }

    /**
     * @return array<int,string>|null the item map when the token is genuine, for these arguments and this user, not expired and not spent
     */
    public static function open( string $token, string $argsHash, int $now, int $userId ): ?array
    {
        $data = self::decode( $token );
        if ( null === $data || ! hash_equals( $argsHash, (string) ( $data['a'] ?? '' ) ) || $userId !== ( $data['u'] ?? null ) ) {
            return null;
        }
        $t = $data['t'] ?? null;
        if ( ! is_int( $t ) || $now < $t || $now - $t > self::TTL || ! is_array( $data['i'] ?? null ) ) {
            return null;
        }
        if ( false !== get_transient( self::key( $data['n'] ) ) ) {
            return null;
        }
        $items = [];
        foreach ( $data['i'] as $id => $hash ) {
            $items[ (int) $id ] = (string) $hash;
        }

        return $items;
    }

    /** Marks a genuine token as used; anything else is ignored. */
    public static function spend( string $token ): void
    {
        $data = self::decode( $token );
        if ( null !== $data ) {
            set_transient( self::key( $data['n'] ), 1, self::TTL );
        }
    }

    /** @return array<string,mixed>|null the payload of a genuinely signed version 2 token with a user and a nonce */
    private static function decode( string $token ): ?array
    {
        $parts = explode( '.', $token );
        if ( 2 !== count( $parts ) || ! hash_equals( wp_hash( 'aied-site|' . $parts[0] ), $parts[1] ) ) {
            return null;
        }
        $data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
        if ( ! is_array( $data ) || 2 !== ( $data['v'] ?? null ) || ! is_int( $data['u'] ?? null ) || ! is_string( $data['n'] ?? null ) || '' === $data['n'] ) {
            return null;
        }

        return $data;
    }

    private static function key( string $nonce ): string
    {
        return self::SPENT . substr( hash( 'sha256', $nonce ), 0, 40 );
    }
}
