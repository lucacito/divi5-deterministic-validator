<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only door to the OAuth rows. The logic classes depend on this interface, so every rule is tested
 * against OAuthStoreMemory and OAuthStore (the table) must behave the same (checked live by make verify-oauth).
 *
 * A row is { kind, hash, client_id, user_id, family, data, expires_at, used_at, created_at }.
 * `hash` is the SHA-256 of the secret (or of the client id): secrets themselves are never stored.
 *
 * @phpstan-type Row array{kind:string,hash:string,client_id:string,user_id:int,family:string,data:array<string,mixed>,expires_at:int,used_at:?int,created_at:int}
 */
interface OAuthStoreInterface
{
    public const CLIENT  = 'client';
    public const CODE    = 'code';
    public const ACCESS  = 'access';
    public const REFRESH = 'refresh';

    /**
     * Inserts one row. $fields may hold client_id (string), user_id (int), family (string) and data (array).
     * Returns false when the row could not be stored (a duplicate hash, a database error).
     *
     * @param array<string,mixed> $fields
     */
    public function insert( string $kind, string $hash, array $fields, int $expiresAt, int $now ): bool;

    /**
     * The row, whether it is used or expired (the caller decides what that means), or null.
     *
     * @return ?array<string,mixed>
     */
    public function find( string $kind, string $hash ): ?array;

    /**
     * Atomically marks a row used. True for exactly ONE caller: only when the row exists, is not used yet and
     * has not expired. Two simultaneous exchanges of the same code or refresh token can never both succeed.
     */
    public function consume( string $kind, string $hash, int $now ): bool;

    /** Deletes every row of a grant family (codes, access and refresh tokens). Returns the number deleted. */
    public function revokeFamily( string $family ): int;

    /** Deletes a client and every row that belongs to it. */
    public function revokeClient( string $clientId ): int;

    /** Deletes every grant row of a user (clients stay). */
    public function revokeUser( int $userId ): int;

    /** Deletes everything: clients, codes and tokens. */
    public function revokeAll(): void;

    public function purgeExpired( int $now ): void;

    /** Number of unexpired rows of a kind. */
    public function countLive( string $kind, int $now ): int;

    /**
     * Client rows, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function clientsOldestFirst( int $limit ): array;

    /**
     * Ids of clients that have at least one unexpired code, access or refresh row.
     *
     * @return list<string>
     */
    public function clientIdsWithLiveGrants( int $now ): array;

    /**
     * One entry per live grant (a refresh token that is neither used nor expired), newest first.
     *
     * @return list<array{family:string,client_id:string,user_id:int,created_at:int}>
     */
    public function liveGrants( int $now, int $limit ): array;
}
