<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OAuthStoreInterface as S;

/** The behaviour every store must have; the table implementation is held to the same list by make verify-oauth. */
final class OAuthStoreMemoryTest extends OAuthTestCase
{
    public function testInsertFindAndDuplicateHash(): void
    {
        $this->assertTrue( $this->store->insert( S::CODE, 'h1', [ 'client_id' => 'c', 'user_id' => 3, 'family' => 'f', 'data' => [ 'a' => 1 ] ], $this->now + 10, $this->now ) );
        $this->assertFalse( $this->store->insert( S::CODE, 'h1', [], $this->now + 10, $this->now ), 'a hash is unique within its kind' );
        $row = $this->store->find( S::CODE, 'h1' );
        $this->assertSame( [ 'c', 3, 'f', [ 'a' => 1 ], null ], [ $row['client_id'], $row['user_id'], $row['family'], $row['data'], $row['used_at'] ] );
        $this->assertNull( $this->store->find( S::ACCESS, 'h1' ), 'kinds do not mix' );
        $this->assertNull( $this->store->find( S::CODE, 'nope' ) );
    }

    public function testConsumeSucceedsForExactlyOneCaller(): void
    {
        $this->store->insert( S::REFRESH, 'h', [ 'family' => 'f' ], $this->now + 10, $this->now );
        $this->assertTrue( $this->store->consume( S::REFRESH, 'h', $this->now ) );
        $this->assertFalse( $this->store->consume( S::REFRESH, 'h', $this->now ), 'the second caller loses' );
        $this->assertSame( $this->now, $this->store->find( S::REFRESH, 'h' )['used_at'] );
    }

    public function testConsumeRefusesExpiredAndMissingRows(): void
    {
        $this->store->insert( S::CODE, 'h', [], $this->now + 10, $this->now );
        $this->assertFalse( $this->store->consume( S::CODE, 'h', $this->now + 10 ), 'expiry is exclusive' );
        $this->assertFalse( $this->store->consume( S::CODE, 'missing', $this->now ) );
    }

    public function testRevocation(): void
    {
        $this->store->insert( S::CLIENT, 'ch', [ 'client_id' => 'c1' ], $this->now + 100, $this->now );
        $this->store->insert( S::CODE, 'a', [ 'client_id' => 'c1', 'user_id' => 1, 'family' => 'fam' ], $this->now + 100, $this->now );
        $this->store->insert( S::ACCESS, 'b', [ 'client_id' => 'c1', 'user_id' => 1, 'family' => 'fam' ], $this->now + 100, $this->now );
        $this->store->insert( S::ACCESS, 'c', [ 'client_id' => 'c2', 'user_id' => 2, 'family' => 'other' ], $this->now + 100, $this->now );

        $this->assertSame( 2, $this->store->revokeFamily( 'fam' ) );
        $this->assertNotNull( $this->store->find( S::ACCESS, 'c' ) );
        $this->assertSame( 0, $this->store->revokeFamily( '' ), 'an empty family id deletes nothing' );

        $this->assertSame( 1, $this->store->revokeUser( 2 ) );
        $this->assertNotNull( $this->store->find( S::CLIENT, 'ch' ), 'revoking a user keeps clients' );

        $this->assertSame( 1, $this->store->revokeClient( 'c1' ) );
        $this->store->insert( S::ACCESS, 'd', [ 'client_id' => 'c9', 'user_id' => 9, 'family' => 'x' ], $this->now + 100, $this->now );
        $this->store->revokeAll();
        $this->assertSame( 0, $this->store->countLive( S::ACCESS, $this->now ) );
    }

    public function testPurgeAndCounts(): void
    {
        $this->store->insert( S::ACCESS, 'old', [], $this->now - 1, $this->now - 100 );
        $this->store->insert( S::ACCESS, 'new', [], $this->now + 100, $this->now );
        $this->assertSame( 1, $this->store->countLive( S::ACCESS, $this->now ) );
        $this->store->purgeExpired( $this->now );
        $this->assertNull( $this->store->find( S::ACCESS, 'old' ) );
        $this->assertNotNull( $this->store->find( S::ACCESS, 'new' ) );
    }

    public function testLiveGrantsAreUnusedUnexpiredRefreshTokensNewestFirst(): void
    {
        $this->store->insert( S::REFRESH, 'r1', [ 'client_id' => 'c', 'user_id' => 1, 'family' => 'f1' ], $this->now + 100, $this->now - 50 );
        $this->store->insert( S::REFRESH, 'r2', [ 'client_id' => 'c', 'user_id' => 2, 'family' => 'f2' ], $this->now + 100, $this->now );
        $this->store->insert( S::REFRESH, 'r3', [ 'client_id' => 'c', 'user_id' => 3, 'family' => 'f3' ], $this->now + 100, $this->now - 10 );
        $this->store->insert( S::REFRESH, 'r4', [ 'client_id' => 'c', 'user_id' => 4, 'family' => 'f4' ], $this->now - 1, $this->now - 90 );
        $this->store->consume( S::REFRESH, 'r3', $this->now );

        $grants = $this->store->liveGrants( $this->now, 10 );
        $this->assertSame( [ 'f2', 'f1' ], array_column( $grants, 'family' ) );
        $this->assertCount( 1, $this->store->liveGrants( $this->now, 1 ) );
    }

    public function testClientsOldestFirstAndGrantHolders(): void
    {
        $this->store->insert( S::CLIENT, 'h-b', [ 'client_id' => 'b' ], $this->now + 100, $this->now - 5 );
        $this->store->insert( S::CLIENT, 'h-a', [ 'client_id' => 'a' ], $this->now + 100, $this->now - 9 );
        $this->store->insert( S::CODE, 'k', [ 'client_id' => 'b', 'user_id' => 1, 'family' => 'f' ], $this->now + 100, $this->now );

        $this->assertSame( [ 'a', 'b' ], array_column( $this->store->clientsOldestFirst( 10 ), 'client_id' ) );
        $this->assertSame( [ 'b' ], $this->store->clientIdsWithLiveGrants( $this->now ) );
        $this->assertSame( [], $this->store->clientIdsWithLiveGrants( $this->now + 1000 ), 'expired grants do not count' );
    }
}
