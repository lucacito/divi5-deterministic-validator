<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pure rules for a page's AI-edit snapshot history. No WordPress calls.
 * A history is ['next' => int, 'items' => list<item>] (newest item first).
 */
final class PageHistory
{
    public const RETENTION = 10;
    public const MAX_BYTES = 524288; // 512 KB per snapshot

    /** @return array{next:int, items:list<array<string,mixed>>} */
    public static function empty(): array
    {
        return [ 'next' => 1, 'items' => [] ];
    }

    /** @return array{next:int, items:list<array<string,mixed>>} */
    public static function normalize( mixed $raw ): array
    {
        if ( ! is_array( $raw ) || ! isset( $raw['items'] ) || ! is_array( $raw['items'] ) ) {
            return self::empty();
        }
        $items = [];
        $max   = 0;
        foreach ( $raw['items'] as $item ) {
            if ( is_array( $item ) && isset( $item['id'], $item['content'] ) && is_int( $item['id'] ) && is_string( $item['content'] ) ) {
                $items[] = $item;
                $max     = max( $max, $item['id'] );
            }
        }
        $next = max( 1, (int) ( $raw['next'] ?? 1 ), $max + 1 );

        return [ 'next' => $next, 'items' => $items ];
    }

    /**
     * @param array{next:int, items:list<array<string,mixed>>} $history
     * @return array{history:array{next:int, items:list<array<string,mixed>>}, stored:bool, version_id:?int, reason:?string}
     */
    public static function record( array $history, string $content, string $tool, int $actor, string $savedAt, ?string $label = null ): array
    {
        $history = self::normalize( $history );
        $bytes   = strlen( $content );

        if ( $bytes > self::MAX_BYTES ) {
            return [ 'history' => $history, 'stored' => false, 'version_id' => null, 'reason' => 'too_large' ];
        }

        $sha = hash( 'sha256', $content );
        if ( $history['items'] !== [] && ( $history['items'][0]['sha256'] ?? null ) === $sha ) {
            return [ 'history' => $history, 'stored' => false, 'version_id' => (int) $history['items'][0]['id'], 'reason' => 'duplicate' ];
        }

        $id = $history['next'];
        array_unshift( $history['items'], [
            'id'       => $id,
            'saved_at' => $savedAt,
            'tool'     => $tool,
            'actor'    => $actor,
            'bytes'    => $bytes,
            'sha256'   => $sha,
            'label'    => $label,
            'content'  => $content,
        ] );
        $history['items'] = array_slice( $history['items'], 0, self::RETENTION );
        $history['next']  = $id + 1;

        return [ 'history' => $history, 'stored' => true, 'version_id' => $id, 'reason' => null ];
    }

    /** @return list<array{id:int, saved_at:mixed, tool:mixed, actor:mixed, bytes:mixed, label:mixed}> */
    public static function summaries( array $history ): array
    {
        $out = [];
        foreach ( self::normalize( $history )['items'] as $item ) {
            $out[] = [
                'id'       => (int) $item['id'],
                'saved_at' => $item['saved_at'] ?? null,
                'tool'     => $item['tool'] ?? null,
                'actor'    => $item['actor'] ?? null,
                'bytes'    => $item['bytes'] ?? null,
                'label'    => $item['label'] ?? null,
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function find( array $history, int $id ): ?array
    {
        foreach ( self::normalize( $history )['items'] as $item ) {
            if ( (int) $item['id'] === $id ) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<int, array> $byPage page id => history
     * @return list<array{page_id:int, version_id:int, saved_at:mixed, tool:mixed}>
     */
    public static function recent( array $byPage, int $limit ): array
    {
        $rows = [];
        foreach ( $byPage as $pageId => $history ) {
            $newest = self::normalize( $history )['items'][0] ?? null;
            if ( $newest !== null ) {
                $rows[] = [
                    'page_id'    => (int) $pageId,
                    'version_id' => (int) $newest['id'],
                    'saved_at'   => $newest['saved_at'] ?? null,
                    'tool'       => $newest['tool'] ?? null,
                ];
            }
        }
        usort( $rows, static fn ( array $a, array $b ): int => strcmp( (string) $b['saved_at'], (string) $a['saved_at'] ) );

        return array_slice( $rows, 0, max( 0, $limit ) );
    }
}
