<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Lab;

use AiEditorDivi5\WP\StudioStore;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The few Lab store calls `build_site` makes for its brief pages (`StudioStore` stays the only code that queries the table).
 * Kept here so the build tool itself references no free class the compat check does not list; every call is behind the
 * Lab guard (a brief page exists only when the guard passed), and `get()` fails soft for a free plugin without Lab.
 */
final class LabBriefs
{
    /** At most this many briefs per person (the free store's own cap). */
    public static function max(): int
    {
        return StudioStore::MAX_PER_USER;
    }

    public static function count( int $user ): int
    {
        return StudioStore::countFor( $user );
    }

    /**
     * Stores the brief and links it to the page with the sha256 of the page content AS STORED. An unlinked row is removed
     * again (it would only take a place under the cap and show in Lab without a page).
     *
     * @param array<string,mixed> $brief the clean brief
     * @return array{brief_id:?int,stored:bool}
     */
    public static function storeAndLink( int $user, array $brief, int $pageId, string $storedContent ): array
    {
        $saved = StudioStore::save( $user, $brief );
        if ( ! $saved['ok'] ) {
            return [ 'brief_id' => null, 'stored' => false ];
        }
        $id = (int) $saved['id'];
        if ( ! StudioStore::link( $user, $id, $pageId, hash( 'sha256', $storedContent ) ) ) {
            StudioStore::delete( $user, $id );

            return [ 'brief_id' => null, 'stored' => false ];
        }

        return [ 'brief_id' => $id, 'stored' => true ];
    }

    /** @return array<string,mixed>|null the brief as stored now, or null (gone, or no Lab in the free plugin) */
    public static function brief( int $user, int $id ): ?array
    {
        if ( ! class_exists( StudioStore::class ) || ! method_exists( StudioStore::class, 'get' ) ) {
            return null;
        }
        $row = StudioStore::get( $user, $id );

        return null === $row ? null : (array) $row['brief'];
    }

    public static function delete( int $user, int $id ): bool
    {
        return StudioStore::delete( $user, $id );
    }
}
