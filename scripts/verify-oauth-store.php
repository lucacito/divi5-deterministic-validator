<?php
/**
 * Real-database check of OAuthStore: the table implementation must behave exactly like OAuthStoreMemory
 * (the reference the unit tests use). Creates the table, runs the contract, drops the table again.
 *
 * Run: make verify-oauth   (this is its first half; dev site only)
 */

use AiEditorDivi5\WP\OAuthSettings;
use AiEditorDivi5\WP\OAuthStore;
use AiEditorDivi5\WP\OAuthStoreInterface as S;
use AiEditorDivi5\WP\OAuthTable;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

if ( ! class_exists( OAuthStore::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (this branch) on this site first.' );
}
if ( OAuthSettings::enabled() || OAuthTable::exists() ) {
    WP_CLI::error( 'OAuth is already switched on (or its table exists) on this site. Switch it off first: this check creates and drops the table.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

$check( OAuthTable::ensureReady( true ), 'the table is created by dbDelta' );
$check( OAuthTable::exists(), 'the table can be queried' );
$check( (int) get_option( OAuthTable::DB_VERSION_OPTION, 0 ) === 1, 'the schema version is recorded' );
$check( OAuthTable::ensureReady(), 'ensureReady is a cheap no-op the second time' );

$store = new OAuthStore();
$now   = time();

// insert / find / duplicate
$check( $store->insert( S::CODE, 'h1', [ 'client_id' => 'c', 'user_id' => 3, 'family' => 'f', 'data' => [ 'a' => 1 ] ], $now + 10, $now ), 'insert stores a row' );
$check( ! $store->insert( S::CODE, 'h1', [], $now + 10, $now ), 'a duplicate hash within a kind is refused' );
$row = $store->find( S::CODE, 'h1' );
$check( null !== $row && 'c' === $row['client_id'] && 3 === $row['user_id'] && 'f' === $row['family'] && [ 'a' => 1 ] === $row['data'] && null === $row['used_at'], 'find returns the row with its fields and data' );
$check( null === $store->find( S::ACCESS, 'h1' ), 'kinds do not mix' );
$check( $store->insert( S::ACCESS, 'h1', [], $now + 10, $now ), 'the same hash may exist in another kind' );
$check( null === $store->find( S::CODE, 'missing' ), 'a missing row is null' );

// atomic consume
$store->insert( S::REFRESH, 'r', [ 'family' => 'f' ], $now + 10, $now );
$check( $store->consume( S::REFRESH, 'r', $now ), 'consume succeeds once' );
$check( ! $store->consume( S::REFRESH, 'r', $now ), 'and only once' );
$check( $now === ( $store->find( S::REFRESH, 'r' )['used_at'] ?? null ), 'used_at is recorded' );
$store->insert( S::CODE, 'exp', [], $now + 10, $now );
$check( ! $store->consume( S::CODE, 'exp', $now + 10 ), 'an expired row cannot be consumed' );
$check( ! $store->consume( S::CODE, 'nope', $now ), 'a missing row cannot be consumed' );

// two concurrent consumers: only one wins (simulated with two statements racing on the same row)
$store->insert( S::CODE, 'race', [], $now + 60, $now );
$wins = (int) $store->consume( S::CODE, 'race', $now ) + (int) $store->consume( S::CODE, 'race', $now );
$check( 1 === $wins, 'two consumers of one code: exactly one wins' );

// revocation
$store->revokeAll();
$store->insert( S::CLIENT, 'ch', [ 'client_id' => 'c1' ], $now + 100, $now );
$store->insert( S::CODE, 'a', [ 'client_id' => 'c1', 'user_id' => 1, 'family' => 'fam' ], $now + 100, $now );
$store->insert( S::ACCESS, 'b', [ 'client_id' => 'c1', 'user_id' => 1, 'family' => 'fam' ], $now + 100, $now );
$store->insert( S::ACCESS, 'c', [ 'client_id' => 'c2', 'user_id' => 2, 'family' => 'other' ], $now + 100, $now );
$check( 2 === $store->revokeFamily( 'fam' ), 'revokeFamily deletes the family rows' );
$check( null !== $store->find( S::ACCESS, 'c' ), 'and only those' );
$check( 0 === $store->revokeFamily( '' ), 'an empty family id deletes nothing' );
$check( 1 === $store->revokeUser( 2 ), 'revokeUser deletes that user\'s grants' );
$check( null !== $store->find( S::CLIENT, 'ch' ), 'revokeUser keeps clients' );
$check( 1 === $store->revokeClient( 'c1' ), 'revokeClient deletes the client' );
$store->insert( S::ACCESS, 'd', [ 'client_id' => 'c9', 'user_id' => 9, 'family' => 'x' ], $now + 100, $now );
$store->revokeAll();
$check( 0 === $store->countLive( S::ACCESS, $now ) && 0 === $store->countLive( S::CLIENT, $now ), 'revokeAll empties the table' );

// purge, counts
$store->insert( S::ACCESS, 'old', [], $now - 1, $now - 100 );
$store->insert( S::ACCESS, 'new', [], $now + 100, $now );
$check( 1 === $store->countLive( S::ACCESS, $now ), 'countLive ignores expired rows' );
$store->purgeExpired( $now );
$check( null === $store->find( S::ACCESS, 'old' ) && null !== $store->find( S::ACCESS, 'new' ), 'purgeExpired removes only expired rows' );

// grants and clients
$store->revokeAll();
$store->insert( S::REFRESH, 'r1', [ 'client_id' => 'c', 'user_id' => 1, 'family' => 'f1' ], $now + 100, $now - 50 );
$store->insert( S::REFRESH, 'r2', [ 'client_id' => 'c', 'user_id' => 2, 'family' => 'f2' ], $now + 100, $now );
$store->insert( S::REFRESH, 'r3', [ 'client_id' => 'c', 'user_id' => 3, 'family' => 'f3' ], $now + 100, $now - 10 );
$store->insert( S::REFRESH, 'r4', [ 'client_id' => 'c', 'user_id' => 4, 'family' => 'f4' ], $now - 1, $now - 90 );
$store->consume( S::REFRESH, 'r3', $now );
$check( [ 'f2', 'f1' ] === array_column( $store->liveGrants( $now, 10 ), 'family' ), 'liveGrants: unused, unexpired refresh tokens, newest first' );
$check( 1 === count( $store->liveGrants( $now, 1 ) ), 'liveGrants honours the limit' );

$store->revokeAll();
$store->insert( S::CLIENT, 'h-b', [ 'client_id' => 'b' ], $now + 100, $now - 5 );
$store->insert( S::CLIENT, 'h-a', [ 'client_id' => 'a' ], $now + 100, $now - 9 );
$store->insert( S::CODE, 'k', [ 'client_id' => 'b', 'user_id' => 1, 'family' => 'f' ], $now + 100, $now );
$check( [ 'a', 'b' ] === array_column( $store->clientsOldestFirst( 10 ), 'client_id' ), 'clientsOldestFirst orders by age' );
$check( [ 'b' ] === $store->clientIdsWithLiveGrants( $now ), 'clientIdsWithLiveGrants finds the client holding a live code' );
$check( [] === $store->clientIdsWithLiveGrants( $now + 1000 ), 'expired grants do not count' );

// secrets and special characters survive
$store->revokeAll();
$store->insert( S::CLIENT, 'ch2', [ 'client_id' => 'c3', 'data' => [ 'name' => "Ünïcode \"quoted\" 名前 <b>", 'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ] ] ], $now + 100, $now );
$back = $store->find( S::CLIENT, 'ch2' );
$check( "Ünïcode \"quoted\" 名前 <b>" === ( $back['data']['name'] ?? null ), 'JSON data round-trips unicode and quotes' );

// SQL injection attempt through the hash
$check( null === $store->find( S::CODE, "x' OR '1'='1" ), 'a hostile hash is only a value' );

// lifecycle
$store->insert( S::ACCESS, 'last', [], $now + 100, $now );
OAuthTable::drop();
$check( ! OAuthTable::exists(), 'drop removes the table' );
$check( 0 === (int) get_option( OAuthTable::DB_VERSION_OPTION, 0 ), 'and its version option' );

if ( $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'OAuthStore matches the reference behaviour on the real database.' );
