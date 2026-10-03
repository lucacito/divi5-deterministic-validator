<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Site;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The preview token of a site-wide write. It is self-contained and signed (`wp_hash`): it carries a hash of the
 * arguments, the id => content-hash of every item the preview would change, and the time it was made. A real run
 * must present it; items whose content changed since the preview are then skipped, never overwritten.
 */
final class PreviewToken
{
    public const TTL = 1800;

    /** @param array<int,string> $items item id => content hash */
    public static function make( string $argsHash, array $items, int $now ): string
    {
        $payload = rtrim( strtr( base64_encode( (string) json_encode( [ 'v' => 1, 'a' => $argsHash, 'i' => $items, 't' => $now ] ) ), '+/', '-_' ), '=' );

        return $payload . '.' . wp_hash( 'aied-site|' . $payload );
    }

    /**
     * @return array<int,string>|null the item map when the token is genuine, for these arguments and not expired
     */
    public static function open( string $token, string $argsHash, int $now ): ?array
    {
        $parts = explode( '.', $token );
        if ( 2 !== count( $parts ) || ! hash_equals( wp_hash( 'aied-site|' . $parts[0] ), $parts[1] ) ) {
            return null;
        }
        $data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
        if ( ! is_array( $data ) || 1 !== ( $data['v'] ?? null ) || ! hash_equals( $argsHash, (string) ( $data['a'] ?? '' ) ) ) {
            return null;
        }
        $t = $data['t'] ?? null;
        if ( ! is_int( $t ) || $now < $t || $now - $t > self::TTL || ! is_array( $data['i'] ?? null ) ) {
            return null;
        }
        $items = [];
        foreach ( $data['i'] as $id => $hash ) {
            $items[ (int) $id ] = (string) $hash;
        }

        return $items;
    }
}
