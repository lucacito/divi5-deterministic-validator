<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The only code that touches the _aied_history post meta. */
final class HistoryStore
{
    public const META_KEY = '_aied_history';

    /** @return array{next:int, items:list<array<string,mixed>>} */
    public static function load( int $pageId ): array
    {
        $raw     = get_post_meta( $pageId, self::META_KEY, true );
        $decoded = ( is_string( $raw ) && '' !== $raw ) ? json_decode( $raw, true ) : null;

        return PageHistory::normalize( $decoded );
    }

    /**
     * Returns false (and writes nothing) if the history cannot be encoded or the
     * meta write fails, so an existing history is never overwritten with junk.
     */
    public static function save( int $pageId, array $history ): bool
    {
        $json = wp_json_encode( $history );
        if ( ! is_string( $json ) || '' === $json ) {
            return false;
        }

        // wp_slash: update_post_meta runs wp_unslash on the value; without it the
        // backslashes in the JSON (and in escaped Divi HTML) would be stripped.
        // Note: update_post_meta returns false on DB failure (int|true on success).
        return false !== update_post_meta( $pageId, self::META_KEY, wp_slash( $json ) );
    }

    public static function deleteAll(): void
    {
        delete_post_meta_by_key( self::META_KEY );
    }
}
