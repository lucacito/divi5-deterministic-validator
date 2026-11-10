<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\WP\StudioStore;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A Lab brief row (the words and look a Lab page was built from). The item stores the OLD row's brief and the content hash it
 * was linked with; undo puts both back by compare-and-set and never reads the page (batches are undone newest item first, and
 * undo of the undo reverses again, so the page may be anywhere in its history: the page has its own `post_content` item).
 * The brief belongs to its user: the owner's id travels in the item and every store call uses it, so an administrator who runs
 * the undo restores the owner's row and never creates a copy of their own.
 */
final class LabBriefKind implements ItemKind
{
    public const NAME = 'lab_brief';

    /** @param (\Closure(int,int,int,string):bool)|null $linker test seam: fn(user, id, pageId, contentHash): `StudioStore::link()`'s result */
    public function __construct( private ?\Closure $linker = null )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * The state that is hashed for "changed since": the brief, the content it is linked to and the page.
     *
     * @param array<string,mixed>|null $row a `StudioStore::get()` row
     * @return array{brief_hash:string, content_hash:?string, page_id:?int}|null
     */
    public static function state( ?array $row ): ?array
    {
        if ( null === $row ) {
            return null;
        }

        return [
            'brief_hash'   => (string) $row['brief_hash'],
            'content_hash' => isset( $row['content_hash'] ) ? (string) $row['content_hash'] : null,
            'page_id'      => isset( $row['page_id'] ) ? (int) $row['page_id'] : null,
        ];
    }

    /**
     * The batch item for a brief row that went from $oldRow to $newRow (both `StudioStore::get()` rows of the same brief).
     *
     * @param array<string,mixed> $oldRow
     * @param array<string,mixed> $newRow
     * @return array<string,mixed>
     */
    public static function itemFor( int $user, array $oldRow, array $newRow ): array
    {
        $pageId = isset( $oldRow['page_id'] ) ? (int) $oldRow['page_id'] : 0;

        return Item::make(
            self::NAME,
            (string) $oldRow['id'],
            "Lab brief of page #{$pageId}",
            [
                'user'         => $user,
                'page_id'      => $pageId,
                'brief'        => $oldRow['brief'],
                'brief_hash'   => (string) $oldRow['brief_hash'],
                'content_hash' => isset( $oldRow['content_hash'] ) ? (string) $oldRow['content_hash'] : null,
            ],
            self::state( $newRow )
        );
    }

    public function current( array $item ): mixed
    {
        return self::state( StudioStore::get( (int) ( $item['before']['user'] ?? 0 ), (int) $item['ref'] ) );
    }

    public function allowed( array $item ): bool
    {
        $owner = (int) ( $item['before']['user'] ?? 0 );

        return ( $owner > 0 && ( get_current_user_id() === $owner || current_user_can( 'manage_options' ) ) )
            && current_user_can( 'edit_post', (int) ( $item['before']['page_id'] ?? 0 ) );
    }

    public function restore( array $item ): array
    {
        $before = is_array( $item['before'] ?? null ) ? $item['before'] : [];
        $user   = (int) ( $before['user'] ?? 0 );
        $ref    = (int) $item['ref'];

        $current = StudioStore::get( $user, $ref );
        if ( null === $current ) {
            return [ 'ok' => false, 'reason' => 'missing' ];
        }
        if ( ! is_array( $before['brief'] ?? null ) ) {
            return [ 'ok' => false, 'reason' => 'invalid_item' ];
        }
        // Check the link BEFORE writing anything: an old row that was never linked cannot be restored as it was, and the brief
        // must not change in that case.
        $pageId      = (int) ( $before['page_id'] ?? 0 );
        $contentHash = (string) ( $before['content_hash'] ?? '' );
        if ( $pageId <= 0 || 1 !== preg_match( '/^[0-9a-f]{64}$/', $contentHash ) ) {
            return [ 'ok' => false, 'reason' => 'link_failed' ];
        }
        $saved = StudioStore::save( $user, $before['brief'], $ref, $current['brief_hash'] );
        if ( empty( $saved['ok'] ) ) {
            return [ 'ok' => false, 'reason' => 'conflict' ];
        }
        $link = $this->linker ?? static fn ( int $u, int $i, int $p, string $h ): bool => StudioStore::link( $u, $i, $p, $h );
        if ( ! $link( $user, $ref, $pageId, $contentHash ) ) {
            // Put the pre-restore brief back, but only if the row still holds what we just wrote.
            $written = StudioStore::get( $user, $ref );
            if ( null !== $written ) {
                StudioStore::save( $user, $current['brief'], $ref, $written['brief_hash'] );
            }

            return [ 'ok' => false, 'reason' => 'link_failed' ];
        }
        $after = StudioStore::get( $user, $ref );
        if ( null === $after ) {
            return [ 'ok' => false, 'reason' => 'missing' ];
        }

        return [ 'ok' => true, 'inverse' => self::itemFor( $user, $current, $after ) ];
    }
}
