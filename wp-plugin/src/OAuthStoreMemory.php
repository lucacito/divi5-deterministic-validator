<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** In-memory OAuthStoreInterface, the reference behaviour the table implementation must match. Used by the tests. */
final class OAuthStoreMemory implements OAuthStoreInterface
{
    /** @var array<string,array<string,mixed>> keyed by "kind:hash" */
    private array $rows = [];

    private int $seq = 0;

    public function insert( string $kind, string $hash, array $fields, int $expiresAt, int $now ): bool
    {
        $key = $kind . ':' . $hash;
        if ( isset( $this->rows[ $key ] ) ) {
            return false;
        }
        $this->rows[ $key ] = [
            'kind'       => $kind,
            'hash'       => $hash,
            'client_id'  => (string) ( $fields['client_id'] ?? '' ),
            'user_id'    => (int) ( $fields['user_id'] ?? 0 ),
            'family'     => (string) ( $fields['family'] ?? '' ),
            'data'       => is_array( $fields['data'] ?? null ) ? $fields['data'] : [],
            'expires_at' => $expiresAt,
            'used_at'    => null,
            'created_at' => $now,
            'seq'        => ++$this->seq,
        ];

        return true;
    }

    public function find( string $kind, string $hash ): ?array
    {
        return $this->rows[ $kind . ':' . $hash ] ?? null;
    }

    public function consume( string $kind, string $hash, int $now ): bool
    {
        $key = $kind . ':' . $hash;
        $row = $this->rows[ $key ] ?? null;
        if ( null === $row || null !== $row['used_at'] || $row['expires_at'] <= $now ) {
            return false;
        }
        $this->rows[ $key ]['used_at'] = $now;

        return true;
    }

    public function revokeFamily( string $family ): int
    {
        return $this->deleteWhere( static fn( array $r ): bool => '' !== $family && $r['family'] === $family );
    }

    public function revokeClient( string $clientId ): int
    {
        return $this->deleteWhere( static fn( array $r ): bool => $r['client_id'] === $clientId );
    }

    public function revokeUser( int $userId ): int
    {
        return $this->deleteWhere( static fn( array $r ): bool => self::CLIENT !== $r['kind'] && $r['user_id'] === $userId );
    }

    public function revokeAll(): void
    {
        $this->rows = [];
    }

    public function purgeExpired( int $now ): void
    {
        $this->deleteWhere( static fn( array $r ): bool => $r['expires_at'] <= $now );
    }

    public function countLive( string $kind, int $now ): int
    {
        $n = 0;
        foreach ( $this->rows as $r ) {
            if ( $r['kind'] === $kind && $r['expires_at'] > $now ) {
                ++$n;
            }
        }

        return $n;
    }

    public function clientsOldestFirst( int $limit ): array
    {
        $clients = array_values( array_filter( $this->rows, static fn( array $r ): bool => self::CLIENT === $r['kind'] ) );
        usort( $clients, static fn( array $a, array $b ): int => [ $a['created_at'], $a['seq'] ] <=> [ $b['created_at'], $b['seq'] ] );

        return array_slice( $clients, 0, $limit );
    }

    public function clientIdsWithLiveGrants( int $now ): array
    {
        $ids = [];
        foreach ( $this->rows as $r ) {
            if ( self::CLIENT !== $r['kind'] && $r['expires_at'] > $now && '' !== $r['client_id'] ) {
                $ids[ $r['client_id'] ] = true;
            }
        }

        return array_keys( $ids );
    }

    public function liveGrants( int $now, int $limit ): array
    {
        $grants = [];
        foreach ( $this->rows as $r ) {
            if ( self::REFRESH === $r['kind'] && null === $r['used_at'] && $r['expires_at'] > $now ) {
                $grants[] = [
                    'family'     => $r['family'],
                    'client_id'  => $r['client_id'],
                    'user_id'    => $r['user_id'],
                    'created_at' => $r['created_at'],
                    'seq'        => $r['seq'],
                ];
            }
        }
        usort( $grants, static fn( array $a, array $b ): int => [ $b['created_at'], $b['seq'] ] <=> [ $a['created_at'], $a['seq'] ] );

        return array_map(
            static fn( array $g ): array => [ 'family' => $g['family'], 'client_id' => $g['client_id'], 'user_id' => $g['user_id'], 'created_at' => $g['created_at'] ],
            array_slice( $grants, 0, $limit )
        );
    }

    /** @param callable(array<string,mixed>):bool $match */
    private function deleteWhere( callable $match ): int
    {
        $n = 0;
        foreach ( $this->rows as $key => $r ) {
            if ( $match( $r ) ) {
                unset( $this->rows[ $key ] );
                ++$n;
            }
        }

        return $n;
    }
}
