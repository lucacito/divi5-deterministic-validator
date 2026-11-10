<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\StudioStore;
use AiEditorDivi5\WP\StudioTable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/../wp-plugin/src/StudioTable.php';
require_once __DIR__ . '/../wp-plugin/src/StudioStore.php';

class StudioStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createStudioTable();
        $GLOBALS['__wp_options']    = [ StudioTable::DB_VERSION_OPTION => StudioTable::DB_VERSION ];
        $GLOBALS['__wp_transients'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']            = null;
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
    }

    /** @return array<string,mixed> */
    private function brief( string $title = 'A page' ): array
    {
        return [ 'title' => $title, 'sections' => [ [ 'recipe' => 'hero-split', 'copy' => [ 'h1' => "Caf\u{e9} \"quoted\" \\ slash" ] ] ] ];
    }

    private function rows(): int
    {
        return (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . StudioTable::name() );
    }

    public function testCountForCountsOnlyThatUsersBriefs(): void
    {
        $this->assertSame( 0, StudioStore::countFor( 5 ) );
        StudioStore::save( 5, $this->brief( 'One' ) );
        StudioStore::save( 5, $this->brief( 'Two' ) );
        StudioStore::save( 6, $this->brief( 'Theirs' ) );
        $this->assertSame( 2, StudioStore::countFor( 5 ) );
        $this->assertSame( 1, StudioStore::countFor( 6 ) );
        $this->assertSame( 0, StudioStore::countFor( 0 ) );
    }

    public function testSaveAndGetRoundTrip(): void
    {
        $brief = $this->brief();
        $r     = StudioStore::save( 5, $brief );
        $this->assertTrue( $r['ok'] );
        $this->assertNull( $r['code'] );
        $this->assertGreaterThan( 0, $r['id'] );

        $got = StudioStore::get( 5, $r['id'] );
        $this->assertSame( $brief, $got['brief'] );
        $this->assertSame( $r['id'], $got['id'] );
        $this->assertNull( $got['page_id'] );
        $this->assertNull( $got['content_hash'] );
        $this->assertSame( hash( 'sha256', (string) json_encode( $brief ) ), $got['brief_hash'] );
        $this->assertSame( AI_EDITOR_DIVI5_VERSION, $got['plugin_version'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $got['updated_at'] );

        // Saving again with the id updates the same row.
        $again = StudioStore::save( 5, $this->brief( 'Changed' ), $r['id'] );
        $this->assertTrue( $again['ok'] );
        $this->assertSame( $r['id'], $again['id'] );
        $this->assertSame( 'Changed', StudioStore::get( 5, $r['id'] )['brief']['title'] );
        $this->assertSame( 1, $this->rows() );
    }

    public function testAnotherUserGetsNotFound(): void
    {
        $r = StudioStore::save( 5, $this->brief() );
        $this->assertNull( StudioStore::get( 6, $r['id'] ) );
        $this->assertNull( StudioStore::get( 5, $r['id'] + 99 ) );
        $this->assertFalse( StudioStore::link( 6, $r['id'], 10, str_repeat( 'a', 64 ) ) );
        $this->assertFalse( StudioStore::delete( 6, $r['id'] ) );
        $this->assertNotNull( StudioStore::get( 5, $r['id'] ), 'the owner still has it' );
    }

    public function testSaveOverAnotherUsersIdIsNotFound(): void
    {
        $r       = StudioStore::save( 5, $this->brief( 'Mine' ) );
        $missing = StudioStore::save( 6, $this->brief( 'Theirs' ), $r['id'] + 99 );
        $other   = StudioStore::save( 6, $this->brief( 'Theirs' ), $r['id'] );

        $this->assertSame( [ 'ok' => false, 'id' => 0, 'code' => 'NOT_FOUND' ], $other );
        $this->assertSame( $missing, $other, 'same answer as an id that does not exist' );
        $this->assertSame( 'Mine', StudioStore::get( 5, $r['id'] )['brief']['title'] );
        $this->assertSame( 1, $this->rows() );
    }

    public function testOver200KbIsRefused(): void
    {
        $big = [ 'title' => str_repeat( 'x', 204800 ) ];
        $this->assertSame( [ 'ok' => false, 'id' => 0, 'code' => 'TOO_LARGE' ], StudioStore::save( 5, $big ) );
        $this->assertSame( 0, $this->rows() );

        $r = StudioStore::save( 5, $this->brief() );
        $this->assertSame( 'TOO_LARGE', StudioStore::save( 5, $big, $r['id'] )['code'] );
        $this->assertSame( 'A page', StudioStore::get( 5, $r['id'] )['brief']['title'], 'the stored brief is untouched' );

        // Just under the limit is accepted.
        $ok = StudioStore::save( 5, [ 'title' => str_repeat( 'x', 204800 - 12 ) ] );
        $this->assertTrue( $ok['ok'] );
    }

    public function testPrunesOldestUnlinkedFirst(): void
    {
        $ids = [];
        for ( $i = 1; $i <= 50; $i++ ) {
            $ids[] = StudioStore::save( 5, $this->brief( "b{$i}" ) )['id'];
        }
        // The three oldest are linked to pages; another user's briefs must not count.
        foreach ( [ 0, 1, 2 ] as $k ) {
            $this->assertTrue( StudioStore::link( 5, $ids[ $k ], 100 + $k, str_repeat( 'a', 64 ) ) );
        }
        StudioStore::save( 6, $this->brief( 'other user' ) );

        $new = StudioStore::save( 5, $this->brief( 'b51' ) );
        $this->assertTrue( $new['ok'] );

        foreach ( [ 0, 1, 2 ] as $k ) {
            $this->assertNotNull( StudioStore::get( 5, $ids[ $k ] ), 'linked brief kept' );
        }
        $this->assertNull( StudioStore::get( 5, $ids[3] ), 'oldest unlinked removed' );
        $this->assertNotNull( StudioStore::get( 5, $ids[4] ) );
        $this->assertNotNull( StudioStore::get( 5, $new['id'] ) );
        $this->assertSame( 50, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . StudioTable::name() . ' WHERE created_by = 5' ) );
        $this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . StudioTable::name() . ' WHERE created_by = 6' ) );
    }

    public function testPruneRemovesLinkedOnlyWhenNothingUnlinkedIsLeft(): void
    {
        $ids = [];
        for ( $i = 1; $i <= 50; $i++ ) {
            $ids[] = StudioStore::save( 5, $this->brief( "b{$i}" ) )['id'];
            StudioStore::link( 5, $ids[ $i - 1 ], 100 + $i, str_repeat( 'b', 64 ) );
        }
        $new = StudioStore::save( 5, $this->brief( 'b51' ) );
        $this->assertNull( StudioStore::get( 5, $ids[0] ), 'the oldest linked goes when all are linked' );
        $this->assertNotNull( StudioStore::get( 5, $new['id'] ), 'the brief just saved is never the victim' );
        $this->assertSame( 50, $this->rows() );
    }

    public function testLinkStoresPageAndHash(): void
    {
        $r    = StudioStore::save( 5, $this->brief() );
        $hash = hash( 'sha256', 'content' );
        $this->assertTrue( StudioStore::link( 5, $r['id'], 77, $hash ) );
        $got = StudioStore::get( 5, $r['id'] );
        $this->assertSame( 77, $got['page_id'] );
        $this->assertSame( $hash, $got['content_hash'] );

        $this->assertFalse( StudioStore::link( 5, $r['id'], 0, $hash ), 'no page' );
        $this->assertFalse( StudioStore::link( 5, $r['id'], 78, 'not-a-hash' ), 'bad hash' );
        $this->assertFalse( StudioStore::link( 5, $r['id'] + 9, 78, $hash ), 'missing brief' );
        $this->assertSame( 77, StudioStore::get( 5, $r['id'] )['page_id'] );
    }

    public function testForPageFindsTheBrief(): void
    {
        $a = StudioStore::save( 5, $this->brief( 'a' ) );
        $b = StudioStore::save( 5, $this->brief( 'b' ) );
        StudioStore::link( 5, $a['id'], 40, str_repeat( 'a', 64 ) );
        StudioStore::link( 5, $b['id'], 41, str_repeat( 'b', 64 ) );

        $this->assertSame( 'a', StudioStore::forPage( 5, 40 )['brief']['title'] );
        $this->assertSame( 'b', StudioStore::forPage( 5, 41 )['brief']['title'] );
        $this->assertNull( StudioStore::forPage( 5, 42 ) );
        $this->assertNull( StudioStore::forPage( 6, 40 ), 'only the owner finds it' );

        // Linking a second brief to the same page moves the page to it.
        StudioStore::link( 5, $b['id'], 40, str_repeat( 'c', 64 ) );
        $this->assertSame( 'b', StudioStore::forPage( 5, 40 )['brief']['title'] );
        $this->assertNull( StudioStore::get( 5, $a['id'] )['page_id'] );
    }

    public function testDeleteRemovesOnlyTheOwnersBrief(): void
    {
        $r = StudioStore::save( 5, $this->brief() );
        $this->assertTrue( StudioStore::delete( 5, $r['id'] ) );
        $this->assertNull( StudioStore::get( 5, $r['id'] ) );
        $this->assertFalse( StudioStore::delete( 5, $r['id'] ), 'already gone' );
    }

    public function testDeleteAllEmptiesTheTable(): void
    {
        StudioStore::save( 5, $this->brief() );
        StudioStore::save( 6, $this->brief() );
        $this->assertSame( 2, $this->rows() );
        StudioStore::deleteAll();
        $this->assertSame( 0, $this->rows() );
    }

    public function testACorruptRowReadsAsMissing(): void
    {
        $r = StudioStore::save( 5, $this->brief() );
        $GLOBALS['wpdb']->query( 'UPDATE ' . StudioTable::name() . " SET brief = '{not json' WHERE id = " . $r['id'] );
        $this->assertNull( StudioStore::get( 5, $r['id'] ) );
    }

    public function testTheTableIsCreatedLazilyWhenTheVersionOptionIsMissing(): void
    {
        $GLOBALS['wpdb']             = new FakeWpdb(); // an upgrade from an older plugin: no table, no option
        $GLOBALS['__wp_options']     = [];
        $GLOBALS['__wp_dbdelta_sql'] = [];
        $this->assertFalse( StudioTable::exists() );

        $r = StudioStore::save( 5, $this->brief() );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'CREATE TABLE wp_ai_editor_divi5_studio', substr( $GLOBALS['__wp_dbdelta_sql'][0], 0, 38 ) );
        $this->assertSame( StudioTable::DB_VERSION, $GLOBALS['__wp_options'][ StudioTable::DB_VERSION_OPTION ] );
        $this->assertNotNull( StudioStore::get( 5, $r['id'] ) );
    }

    public function testARefusedCreateBacksOffAndFailsCleanly(): void
    {
        $GLOBALS['wpdb']             = new FakeWpdb();
        $GLOBALS['wpdb']->denyCreate = true;
        $GLOBALS['__wp_options']     = [];
        $this->assertSame( 'STORE_FAILED', StudioStore::save( 5, $this->brief() )['code'] );
        $this->assertNotFalse( get_transient( StudioTable::BACKOFF_TRANSIENT ) );
        $this->assertNull( StudioStore::get( 5, 1 ) );
        $this->assertNull( StudioStore::forPage( 5, 1 ) );
        $this->assertFalse( StudioStore::delete( 5, 1 ) );
    }

    public function testRecentlyLinkedFindsOnlyTheUsersFreshLinkedBriefWithThatHash(): void
    {
        $brief = $this->brief();
        $hash  = (string) StudioStore::hashOf( $brief );
        $this->assertSame( hash( 'sha256', (string) json_encode( $brief ) ), $hash );
        $r = StudioStore::save( 5, $brief );
        $this->assertNull( StudioStore::recentlyLinked( 5, $hash, 10 ), 'not linked to a page yet' );
        $this->assertTrue( StudioStore::link( 5, $r['id'], 42, str_repeat( 'b', 64 ) ) );
        $found = StudioStore::recentlyLinked( 5, $hash, 10 );
        $this->assertSame( $r['id'], $found['id'] );
        $this->assertSame( 42, $found['page_id'] );
        $this->assertNull( StudioStore::recentlyLinked( 6, $hash, 10 ), 'another user' );
        $this->assertNull( StudioStore::recentlyLinked( 5, str_repeat( 'c', 64 ), 10 ), 'another brief' );
        $this->assertNull( StudioStore::recentlyLinked( 5, 'not-a-hash', 10 ) );
        $GLOBALS['wpdb']->query( 'UPDATE ' . StudioTable::name() . " SET updated_at = '2000-01-01 00:00:00'" );
        $this->assertNull( StudioStore::recentlyLinked( 5, $hash, 10 ), 'too old' );
    }

    public function testSaveWithTheCurrentHashSucceeds(): void
    {
        $r    = StudioStore::save( 5, $this->brief( 'v1' ) );
        $hash = StudioStore::get( 5, $r['id'] )['brief_hash'];
        $v2   = $this->brief( 'v2' );
        $s    = StudioStore::save( 5, $v2, $r['id'], $hash );
        $this->assertTrue( $s['ok'] );
        $this->assertSame( $r['id'], $s['id'] );
        $this->assertNull( $s['code'] );
        $this->assertSame( $v2, StudioStore::get( 5, $r['id'] )['brief'] );
    }

    public function testSaveWithAStaleHashIsAConflictAndChangesNothing(): void
    {
        $r    = StudioStore::save( 5, $this->brief( 'v1' ) );
        $hash = StudioStore::get( 5, $r['id'] )['brief_hash'];
        $v2   = $this->brief( 'v2' );
        $this->assertTrue( StudioStore::save( 5, $v2, $r['id'] )['ok'] );

        $s = StudioStore::save( 5, $this->brief( 'v3' ), $r['id'], $hash );
        $this->assertFalse( $s['ok'] );
        $this->assertSame( StudioStore::CONFLICT, $s['code'] );
        $this->assertSame( 0, $s['id'] );
        $this->assertSame( $v2, StudioStore::get( 5, $r['id'] )['brief'] );
    }

    public function testSaveWithTheCurrentHashAndIdenticalContentIsNotAConflict(): void
    {
        $brief = $this->brief( 'same' );
        $r     = StudioStore::save( 5, $brief );
        $hash  = StudioStore::get( 5, $r['id'] )['brief_hash'];
        $s     = StudioStore::save( 5, $brief, $r['id'], $hash );
        $this->assertTrue( $s['ok'] );
        $this->assertSame( $r['id'], $s['id'] );
        $this->assertSame( $brief, StudioStore::get( 5, $r['id'] )['brief'] );
    }

    public function testSavingTheSameContentUnderAStaleHashIsIdempotent(): void
    {
        // Another writer already stored exactly this content: the update changes nothing, and that is not a conflict.
        $r    = StudioStore::save( 5, $this->brief( 'v1' ) );
        $hash = StudioStore::get( 5, $r['id'] )['brief_hash'];
        $v2   = $this->brief( 'v2' );
        StudioStore::save( 5, $v2, $r['id'] );
        $s = StudioStore::save( 5, $v2, $r['id'], $hash );
        $this->assertTrue( $s['ok'] );
        $this->assertSame( $v2, StudioStore::get( 5, $r['id'] )['brief'] );
    }

    public function testSaveWithAHashForAnotherUsersBriefIsNotFound(): void
    {
        $r    = StudioStore::save( 5, $this->brief( 'mine' ) );
        $hash = StudioStore::get( 5, $r['id'] )['brief_hash'];
        $s    = StudioStore::save( 6, $this->brief( 'theirs' ), $r['id'], $hash );
        $this->assertFalse( $s['ok'] );
        $this->assertSame( StudioStore::NOT_FOUND, $s['code'] );
        $this->assertSame( $this->brief( 'mine' ), StudioStore::get( 5, $r['id'] )['brief'] );
    }

    public function testExpectedHashWithoutAnIdIsIgnored(): void
    {
        $s = StudioStore::save( 5, $this->brief( 'new' ), null, str_repeat( 'a', 64 ) );
        $this->assertTrue( $s['ok'] );
        $this->assertGreaterThan( 0, $s['id'] );
        $this->assertSame( 1, $this->rows() );
    }

    public function testSchemaHasNoPostMeta(): void
    {
        $sql = StudioTable::schemaSql();
        $this->assertStringNotContainsString( 'postmeta', $sql );
        foreach ( [ 'id', 'created_by', 'page_id', 'brief', 'brief_hash', 'content_hash', 'plugin_version', 'created_at', 'updated_at' ] as $col ) {
            $this->assertMatchesRegularExpression( '/\b' . $col . '\b/', $sql );
        }
        foreach ( [ 'StudioTable.php', 'StudioStore.php' ] as $file ) {
            $src = (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
            $this->assertDoesNotMatchRegularExpression( '/\b(?:add|update|delete|get)_(?:post|user|term)_meta\s*\(|\b(?:add|update|delete)_metadata\s*\(/', $src, $file );
            $this->assertStringNotContainsString( 'array_is_list', $src );
        }
    }
}
