<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StudioAssistant;
use AiEditorDivi5\WP\StudioBrief;
use AiEditorDivi5\WP\StudioService;
use AiEditorDivi5\WP\StudioSlots;
use AiEditorDivi5\WP\StudioStore;
use AiEditorDivi5\WP\StudioTable;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/WpInsertPostShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageAccess.php';
require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/BlockAttrs.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageResolver.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StudioLinks.php';
require_once __DIR__ . '/../wp-plugin/src/StudioSlots.php';
require_once __DIR__ . '/../wp-plugin/src/StudioBrief.php';
require_once __DIR__ . '/../wp-plugin/src/StudioTable.php';
require_once __DIR__ . '/../wp-plugin/src/StudioStore.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DesignVariety.php';
require_once __DIR__ . '/../wp-plugin/src/LoopAdvisory.php';
require_once __DIR__ . '/../wp-plugin/src/PageTitle.php';
require_once __DIR__ . '/../wp-plugin/src/StudioCompiler.php';
require_once __DIR__ . '/../wp-plugin/src/StudioMerge.php';
require_once __DIR__ . '/../wp-plugin/src/DraftPage.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/StudioService.php';
require_once __DIR__ . '/../wp-plugin/src/StudioAssistant.php';

/** Lab (internal name Studio), plan 3 task 3: the lab_build tool's argument checks, catalog and open. */
class StudioAssistantTest extends TestCase
{
    private const ME    = 5;
    private const OTHER = 6;

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createStudioTable();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']         = [
            StudioTable::DB_VERSION_OPTION  => StudioTable::DB_VERSION,
            HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED,
        ];
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_posts']           = [];
        $GLOBALS['__wp_postmeta']        = [];
        $GLOBALS['__wp_inserts']         = [];
        unset( $GLOBALS['__wp_update_calls'], $GLOBALS['__wp_insert_fail'], $GLOBALS['__wp_user_caps'] );
        $GLOBALS['__wp_current_user_id'] = self::ME;
        $GLOBALS['__wp_caps']            = [ 'edit_pages' => true ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']         = null;
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        $GLOBALS['__wp_posts']      = [];
        $GLOBALS['__wp_postmeta']   = [];
        $GLOBALS['__wp_inserts']    = [];
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__wp_update_calls'], $GLOBALS['__wp_insert_fail'], $GLOBALS['__wp_user_caps'] );
    }

    /** @return array<string,mixed> */
    private static function brief(): array
    {
        return [
            'title'    => 'Acme plumbing',
            'goal'     => 'book',
            'language' => 'editorial',
            'sections' => [
                [ 'recipe' => 'hero-cta', 'slots' => [ 'heading.1' => 'Fix leaks fast', 'button.0.text' => 'Book now', 'button.0.link' => '/contact/' ] ],
                [ 'recipe' => 'card-grid-3' ],
            ],
        ];
    }

    private function stored( int $user = self::ME ): int
    {
        $r = StudioStore::save( $user, self::brief() );
        $this->assertTrue( $r['ok'] );

        return $r['id'];
    }

    private function rows(): array
    {
        return $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . StudioTable::name(), ARRAY_A );
    }

    public function testNeedsEditPages(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => false ];
        $r = StudioAssistant::run( [ 'action' => 'catalog' ] );
        $this->assertSame( 403, $r['status'] );
        $this->assertArrayHasKey( 'error', $r['body'] );
    }

    public function testUnknownArgumentIsNamed(): void
    {
        $r = StudioAssistant::run( [ 'action' => 'catalog', 'recpie' => 'x' ] );
        $this->assertSame( 400, $r['status'] );
        $this->assertStringContainsString( 'recpie', $r['body']['error'] );
    }

    public function testActionIsRequiredAndChecked(): void
    {
        foreach ( [ [], [ 'action' => '' ], [ 'action' => null ], [ 'action' => 'nope' ], [ 'action' => 3 ], [ 'action' => [ 'catalog' ] ], [ 'action' => true ] ] as $args ) {
            $r = StudioAssistant::run( $args );
            $this->assertSame( 400, $r['status'], (string) json_encode( $args ) );
            $this->assertArrayHasKey( 'error', $r['body'] );
        }
    }

    public function testArgumentTypesAreChecked(): void
    {
        $bad = [
            [ 'recipe' => 3 ], [ 'role' => [ 'hero' ] ], [ 'base_hash' => 5 ], [ 'create' => 'true' ], [ 'create' => 1 ],
            [ 'sections' => 'x' ], [ 'brief' => 'x' ], [ 'sections' => [ 'a' ] ], [ 'sections' => [ 1, 2 ] ], [ 'sections' => [ 'a' => [ 'section' => 0 ] ] ],
        ];
        foreach ( $bad as $extra ) {
            $r = StudioAssistant::run( array_merge( [ 'action' => 'catalog' ], $extra ) );
            $this->assertSame( 400, $r['status'], (string) json_encode( $extra ) );
        }
    }

    public function testBriefIdMustBeAPositiveInteger(): void
    {
        $id = $this->stored();
        foreach ( [ '3', 0, -1, 1.5, true, false, [], '' ] as $bad ) {
            $r = StudioAssistant::run( [ 'action' => 'open', 'brief_id' => $bad ] );
            $this->assertSame( 400, $r['status'], var_export( $bad, true ) );
        }
        $r = StudioAssistant::run( [ 'action' => 'open' ] );
        $this->assertSame( 400, $r['status'] );
        $this->assertSame( 200, StudioAssistant::run( [ 'action' => 'open', 'brief_id' => $id ] )['status'] );
    }

    public function testCatalogListsEveryRecipeByRole(): void
    {
        $r = StudioAssistant::run( [ 'action' => 'catalog' ] );
        $this->assertSame( 200, $r['status'] );
        $b = $r['body'];
        $this->assertSame( StudioBrief::GOALS, $b['goals'] );
        $this->assertSame( DesignLanguages::ids(), array_column( $b['languages'], 'id' ) );
        foreach ( $b['languages'] as $l ) {
            $this->assertNotSame( '', $l['name'] );
        }
        $this->assertSame( StyleKits::ids(), $b['kits'] );
        $listed = [];
        foreach ( $b['recipes'] as $role => $names ) {
            foreach ( $names as $name ) {
                $listed[] = $name;
                $this->assertSame( (string) ( SectionRecipes::tags( $name )['role'] ?? 'other' ), $role );
            }
        }
        $expected = SectionRecipes::names();
        sort( $listed );
        sort( $expected );
        $this->assertSame( $expected, $listed );
        $this->assertIsString( $b['how'] );
        $this->assertStringNotContainsString( 'Studio', $b['how'] );
        foreach ( [ 'catalog', 'open', 'fill', 'build' ] as $action ) {
            $this->assertStringContainsString( $action, $b['how'] );
        }
    }

    public function testCatalogIsSmall(): void
    {
        $r = StudioAssistant::run( [ 'action' => 'catalog' ] );
        $bytes = strlen( (string) json_encode( $r['body'] ) );
        $this->assertLessThanOrEqual( 9000, $bytes, 'the catalog is ' . $bytes . ' bytes' );
    }

    public function testCatalogRoleFilter(): void
    {
        $all  = StudioAssistant::run( [ 'action' => 'catalog' ] )['body']['recipes'];
        $role = (string) array_key_first( $all );
        $r    = StudioAssistant::run( [ 'action' => 'catalog', 'role' => $role ] );
        $this->assertSame( 200, $r['status'] );
        $this->assertSame( [ $role => $all[ $role ] ], $r['body']['recipes'] );
        $this->assertSame( 400, StudioAssistant::run( [ 'action' => 'catalog', 'role' => 'nonsense' ] )['status'] );
    }

    public function testCatalogWithARecipeReturnsItsSlots(): void
    {
        $r = StudioAssistant::run( [ 'action' => 'catalog', 'recipe' => 'hero-cta' ] );
        $this->assertSame( 200, $r['status'] );
        $this->assertSame( 'hero-cta', $r['body']['recipe'] );
        $this->assertSame( (string) SectionRecipes::tags( 'hero-cta' )['role'], $r['body']['role'] );
        $ids = array_column( StudioSlots::extract( (string) SectionRecipes::rawMarkup( 'hero-cta' ) ), 'id' );
        $this->assertSame( $ids, array_column( $r['body']['slots'], 'id' ) );
        foreach ( $r['body']['slots'] as $slot ) {
            $this->assertSame( [ 'id', 'kind', 'role', 'sample' ], array_keys( $slot ) );
        }
        $this->assertSame( 400, StudioAssistant::run( [ 'action' => 'catalog', 'recipe' => 'no-such-recipe' ] )['status'] );
    }

    public function testOpenReturnsTheStoredBriefAndWhatIsMissing(): void
    {
        $id     = $this->stored();
        $stored = StudioStore::get( self::ME, $id );
        $r      = StudioAssistant::run( [ 'action' => 'open', 'brief_id' => $id ] );
        $this->assertSame( 200, $r['status'] );
        $b = $r['body'];
        $this->assertSame( $id, $b['brief_id'] );
        $this->assertSame( $stored['brief_hash'], $b['base_hash'] );
        $this->assertSame( $stored['brief'], $b['brief'] );
        $this->assertNull( $b['page'] );
        $this->assertIsString( $b['rules'] );
        $this->assertCount( 2, $b['sections'] );
        $this->assertSame( [ 0, 1 ], array_column( $b['sections'], 'section' ) );
        $this->assertSame( [ 'hero-cta', 'card-grid-3' ], array_column( $b['sections'], 'recipe' ) );

        $unfilled = 0;
        foreach ( $b['sections'] as $section ) {
            $ids = array_column( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $section['recipe'] ) ), 'id' );
            $this->assertSame( $ids, array_column( $section['slots'], 'id' ) );
            foreach ( $section['slots'] as $slot ) {
                $this->assertSame( [ 'id', 'kind', 'role', 'sample', 'value', 'filled' ], array_keys( $slot ) );
                $this->assertSame( '' !== $slot['value'] && null !== $slot['value'], $slot['filled'] );
                $unfilled += $slot['filled'] ? 0 : 1;
            }
        }
        $hero = array_column( $b['sections'][0]['slots'], null, 'id' );
        $this->assertTrue( $hero['heading.1']['filled'] );
        $this->assertSame( 'Fix leaks fast', $hero['heading.1']['value'] );
        $this->assertTrue( $hero['button.0.link']['filled'] );
        $this->assertSame( $unfilled, $b['unfilled_count'] );
        $this->assertGreaterThan( 0, $b['unfilled_count'] );
    }

    public function testOpenShowsALinkedPage(): void
    {
        $id = $this->stored();
        $GLOBALS['__wp_posts'][77] = (object) [ 'ID' => 77, 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => 'x' ];
        $GLOBALS['__wp_caps']['edit_post'] = true;
        StudioStore::link( self::ME, $id, 77, hash( 'sha256', 'x' ) );
        $r = StudioAssistant::run( [ 'action' => 'open', 'brief_id' => $id ] );
        $this->assertSame( [ 'id' => 77, 'status' => 'draft' ], $r['body']['page'] );
    }

    public function testMissingAndForeignBriefsLookTheSame(): void
    {
        $theirs  = $this->stored( self::OTHER );
        $missing = StudioAssistant::run( [ 'action' => 'open', 'brief_id' => 9999 ] );
        $foreign = StudioAssistant::run( [ 'action' => 'open', 'brief_id' => $theirs ] );
        $this->assertSame( 404, $missing['status'] );
        $this->assertSame( $missing, $foreign );
    }

    public function testOpenWritesNothing(): void
    {
        $id     = $this->stored();
        $before = $this->rows();
        StudioAssistant::run( [ 'action' => 'open', 'brief_id' => $id ] );
        StudioAssistant::run( [ 'action' => 'catalog' ] );
        $this->assertSame( $before, $this->rows() );
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
    }

    // ---- Task 3 review items ------------------------------------------------------------------------------

    public function testOpenHidesAPageTheUserCannotEdit(): void
    {
        $id = $this->stored();
        $GLOBALS['__wp_posts'][77] = (object) [ 'ID' => 77, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'x', 'post_author' => self::OTHER ];
        StudioStore::link( self::ME, $id, 77, hash( 'sha256', 'x' ) );
        $GLOBALS['__wp_caps']['edit_post:77'] = false;
        $GLOBALS['wpdb']->inserted = [];
        $r = StudioAssistant::run( [ 'action' => 'open', 'brief_id' => $id ] );
        $this->assertSame( 200, $r['status'] );
        $this->assertNull( $r['body']['page'] );
        $this->assertStringNotContainsString( '77', (string) json_encode( $r['body']['page'] ) );
        foreach ( $GLOBALS['wpdb']->inserted as [ , $data ] ) {
            if ( 'lab_build' === ( $data['endpoint'] ?? '' ) ) {
                $this->assertNull( $data['page_id'], 'a page id the user cannot edit is not logged' );
            }
        }
    }

    public function testOpenLogsTheLinkedPageIdWhenTheUserCanEditIt(): void
    {
        $id = $this->stored();
        $GLOBALS['__wp_posts'][77] = (object) [ 'ID' => 77, 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => 'x' ];
        StudioStore::link( self::ME, $id, 77, hash( 'sha256', 'x' ) );
        $GLOBALS['__wp_caps']['edit_post'] = true;
        $GLOBALS['wpdb']->inserted = [];
        StudioAssistant::run( [ 'action' => 'open', 'brief_id' => $id ] );
        $logged = [];
        foreach ( $GLOBALS['wpdb']->inserted as [ , $data ] ) {
            if ( 'lab_build' === ( $data['endpoint'] ?? '' ) ) {
                $logged[] = $data['page_id'];
            }
        }
        $this->assertSame( [ 77 ], $logged );
    }

    public function testOpenFlagsASectionWhoseRecipeNoLongerExists(): void
    {
        $brief = self::brief();
        $brief['sections'][1]['recipe'] = 'gone-recipe';
        $json  = (string) json_encode( $brief );
        $GLOBALS['wpdb']->insert( \AiEditorDivi5\WP\StudioTable::name(), [
            'created_by' => self::ME, 'brief' => $json, 'brief_hash' => hash( 'sha256', $json ), 'plugin_version' => '4', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ] );
        $id = (int) $GLOBALS['wpdb']->insert_id;
        $r  = StudioAssistant::run( [ 'action' => 'open', 'brief_id' => $id ] );
        $this->assertSame( 200, $r['status'] );
        $this->assertCount( 2, $r['body']['sections'] );
        $this->assertSame( 'gone-recipe', $r['body']['sections'][1]['recipe'] );
        $this->assertTrue( $r['body']['sections'][1]['recipe_missing'] );
        $this->assertSame( [], $r['body']['sections'][1]['slots'] );
        $this->assertArrayNotHasKey( 'recipe_missing', $r['body']['sections'][0] );
    }

    public function testCatalogHowDescribesTheFillFlowAndTheConflict(): void
    {
        $how = (string) StudioAssistant::run( [ 'action' => 'catalog' ] )['body']['how'];
        foreach ( [ 'base_hash', 'CONFLICT', 'open again' ] as $needle ) {
            $this->assertStringContainsString( $needle, $how );
        }
        $this->assertLessThanOrEqual( 1400, strlen( $how ) );
    }

    // ---- fill ---------------------------------------------------------------------------------------------

    /** @return array{id:int,hash:string} */
    private function open(): array
    {
        $id = $this->stored();

        return [ 'id' => $id, 'hash' => (string) StudioStore::get( self::ME, $id )['brief_hash'] ];
    }

    private function fill( array $o, array $sections, ?string $hash = null ): array
    {
        return StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $o['id'], 'base_hash' => $hash ?? $o['hash'], 'sections' => $sections ] );
    }

    private function storedBrief( int $id ): array
    {
        return StudioStore::get( self::ME, $id )['brief'];
    }

    public function testFillWithTheCurrentHashSavesAndReportsExactlyTheAppliedSlots(): void
    {
        $o = $this->open();
        $r = $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'Leaks fixed in an hour', 'heading.0' => 'Plumbers' ], 'pictures' => [ 'section.0.background' => [ 'pack' => 'hero-blue-1' ] ] ] ] );
        $this->assertSame( 200, $r['status'], (string) json_encode( $r['body'] ) );
        $b = $r['body'];
        $this->assertSame( $o['id'], $b['brief_id'] );
        $this->assertNotSame( $o['hash'], $b['base_hash'] );
        $this->assertSame( StudioStore::get( self::ME, $o['id'] )['brief_hash'], $b['base_hash'] );
        $this->assertEqualsCanonicalizing(
            [ [ 'section' => 0, 'slot' => 'heading.1' ], [ 'section' => 0, 'slot' => 'heading.0' ], [ 'section' => 0, 'slot' => 'section.0.background' ] ],
            $b['applied']
        );
        $this->assertSame( [], $b['ignored'] );
        $this->assertIsInt( $b['unfilled_count'] );
        $this->assertTrue( $b['report']['unfilled'] === array_values( $b['report']['unfilled'] ) );
        $this->assertNull( $b['page'] );
        $this->assertIsString( $b['message'] );
        $this->assertStringNotContainsString( 'Studio', $b['message'] );
        $saved = $this->storedBrief( $o['id'] );
        $this->assertSame( 'Leaks fixed in an hour', $saved['sections'][0]['slots']['heading.1'] );
        $this->assertSame( [ 'pack' => 'hero-blue-1' ], $saved['sections'][0]['pictures']['section.0.background'] );
        $this->assertSame( 'Book now', $saved['sections'][0]['slots']['button.0.text'], 'words not sent stay' );
        $this->assertArrayNotHasKey( 'slots', $saved['sections'][1] );
    }

    public function testFillWithAStaleHashIs409AndChangesNothing(): void
    {
        $o = $this->open();
        $this->assertSame( 200, $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'Second writer first' ] ] ] )['status'] );
        $current = (string) StudioStore::get( self::ME, $o['id'] )['brief_hash'];
        $before  = $this->rows();
        $r       = $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'Stale words' ] ] ] );
        $this->assertSame( 409, $r['status'] );
        $this->assertSame( 'CONFLICT', $r['body']['code'] );
        $this->assertSame( $current, $r['body']['current_base_hash'] );
        $this->assertIsString( $r['body']['message'] );
        $this->assertArrayNotHasKey( 'brief', $r['body'] );
        $this->assertStringNotContainsString( 'Second writer first', (string) json_encode( $r['body'] ) );
        $this->assertSame( $before, $this->rows() );
    }

    public function testFillOnAnotherUsersBriefIs404(): void
    {
        $theirs = $this->stored( self::OTHER );
        $hash   = (string) StudioStore::get( self::OTHER, $theirs )['brief_hash'];
        $before = $this->rows();
        $r      = $this->fill( [ 'id' => $theirs, 'hash' => $hash ], [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'x' ] ] ] );
        $missing = $this->fill( [ 'id' => 9999, 'hash' => $hash ], [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'x' ] ] ] );
        $this->assertSame( 404, $r['status'] );
        $this->assertSame( $missing, $r );
        $this->assertSame( $before, $this->rows() );
    }

    public function testFillListsWhatItCouldNotWriteAndWritesNone(): void
    {
        $o = $this->open();
        $r = $this->fill( $o, [ [ 'section' => 0,
            'slots'    => [ 'no.such.slot' => 'x', 'section.0.background' => 'a string for a picture' ],
            'pictures' => [ 'heading.1' => [ 'pack' => 'hero-blue-1' ] ],
        ] ] );
        $this->assertSame( 200, $r['status'], (string) json_encode( $r['body'] ) );
        $this->assertSame( [], $r['body']['applied'] );
        $this->assertEqualsCanonicalizing(
            [ [ 'section' => 0, 'slot' => 'no.such.slot' ], [ 'section' => 0, 'slot' => 'section.0.background' ], [ 'section' => 0, 'slot' => 'heading.1' ] ],
            $r['body']['ignored']
        );
        $this->assertSame( self::brief(), $this->storedBrief( $o['id'] ) );
    }

    public function testAFillThatWritesNothingKeepsTheHashButStillRefusesAStaleOne(): void
    {
        $o = $this->open();
        $r = $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'no.such.slot' => 'x' ] ] ] );
        $this->assertSame( 200, $r['status'] );
        $this->assertSame( $o['hash'], $r['body']['base_hash'] );
        $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'Newer' ] ] ] );
        $stale = $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'no.such.slot' => 'x' ] ] ] );
        $this->assertSame( 409, $stale['status'] );
    }

    public function testFillRefusesBadShapesForTheWholeCallAndWritesNothing(): void
    {
        $o   = $this->open();
        $ok  = [ 'section' => 0, 'slots' => [ 'heading.1' => 'Good words' ] ];
        $bad = [
            'out of range'         => [ $ok, [ 'section' => 2, 'slots' => [ 'heading.1' => 'x' ] ] ],
            'negative'             => [ $ok, [ 'section' => -1 ] ],
            'duplicate'            => [ $ok, [ 'section' => 0, 'slots' => [ 'heading.0' => 'x' ] ] ],
            'empty list'           => [],
            'not an object entry'  => [ $ok, 'text' ],
            'entry is a list'      => [ $ok, [ 1, 2 ] ],
            'missing section'      => [ [ 'slots' => [ 'heading.1' => 'x' ] ] ],
            'section as string'    => [ [ 'section' => '0', 'slots' => [ 'heading.1' => 'x' ] ] ],
            'section as float'     => [ [ 'section' => 0.0, 'slots' => [ 'heading.1' => 'x' ] ] ],
            'unknown entry key'    => [ [ 'section' => 0, 'words' => [ 'heading.1' => 'x' ] ] ],
            'slots as a list'      => [ [ 'section' => 0, 'slots' => [ 'x', 'y' ] ] ],
            'slots as a string'    => [ [ 'section' => 0, 'slots' => 'x' ] ],
            'pictures as a string' => [ [ 'section' => 0, 'pictures' => 'x' ] ],
            'same id twice'        => [ [ 'section' => 0, 'slots' => [ 'section.0.background' => 'a' ], 'pictures' => [ 'section.0.background' => [ 'pack' => 'hero-blue-1' ] ] ] ],
            'nothing in it'        => [ [ 'section' => 0 ] ],
            'too many'             => array_fill( 0, 21, $ok ),
        ];
        foreach ( $bad as $label => $sections ) {
            $before = $this->rows();
            $r      = $this->fill( $o, $sections );
            $this->assertSame( 400, $r['status'], $label . ': ' . (string) json_encode( $r['body'] ) );
            $this->assertArrayHasKey( 'error', $r['body'], $label );
            $this->assertSame( $before, $this->rows(), $label );
        }
        foreach ( [ 'not a list' => 'x', 'object' => [ 'a' => $ok ], 'null' => null ] as $label => $sections ) {
            $r = StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $o['id'], 'base_hash' => $o['hash'], 'sections' => $sections ] );
            $this->assertSame( 400, $r['status'], $label );
        }
        $this->assertSame( 400, StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $o['id'], 'base_hash' => $o['hash'] ] )['status'], 'no sections' );
        $this->assertSame( self::brief(), $this->storedBrief( $o['id'] ) );
    }

    public function testFillNeedsABriefIdAndAWellFormedHash(): void
    {
        $o  = $this->open();
        $s  = [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'x' ] ] ];
        $this->assertSame( 400, StudioAssistant::run( [ 'action' => 'fill', 'base_hash' => $o['hash'], 'sections' => $s ] )['status'] );
        foreach ( [ null, '', 'abc', str_repeat( 'g', 64 ), str_repeat( 'a', 63 ), str_repeat( 'a', 65 ), $o['hash'] . "\n" ] as $hash ) {
            $args = [ 'action' => 'fill', 'brief_id' => $o['id'], 'sections' => $s ];
            if ( null !== $hash ) {
                $args['base_hash'] = $hash;
            }
            $this->assertSame( 400, StudioAssistant::run( $args )['status'], var_export( $hash, true ) );
        }
        $this->assertSame( self::brief(), $this->storedBrief( $o['id'] ) );
    }

    public function testAValueOverTheLimitIs422AndNothingIsSaved(): void
    {
        $o      = $this->open();
        $before = $this->rows();
        $r      = $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'heading.1' => str_repeat( 'a', 2001 ) ] ] ] );
        $this->assertSame( 422, $r['status'] );
        $this->assertSame( 'INVALID_BRIEF', $r['body']['code'] );
        $this->assertNotEmpty( $r['body']['problems'] );
        $this->assertSame( 'TEXT_TOO_LONG', $r['body']['problems'][0]['code'] );
        $this->assertStringNotContainsString( str_repeat( 'a', 300 ), (string) json_encode( $r['body'] ) );
        $this->assertSame( $before, $this->rows() );
    }

    public function testHostileWordsAreSavedAsGivenAndReachThePageOnlyEscaped(): void
    {
        $o     = $this->open();
        // Real slot ids, read from the recipes, so each word is really written (applied is asserted).
        $card  = (string) array_column( array_filter( StudioSlots::extract( (string) SectionRecipes::rawMarkup( 'card-grid-3' ) ), static fn ( array $s ): bool => 'text' === $s['kind'] ), 'id' )[1];
        $words = [
            [ 0, 'heading.1', '{{aied:image:x}}' ],
            [ 0, 'heading.0', '$variable(--x)$' ],
            [ 0, 'button.0.text', '<script>alert(1)</script>' ],
            [ 1, $card, '</p><p>' ],
        ];
        $hero  = [ 'heading.1' => $words[0][2], 'heading.0' => $words[1][2], 'button.0.text' => $words[2][2] ];
        $r     = $this->fill( $o, [ [ 'section' => 0, 'slots' => $hero ], [ 'section' => 1, 'slots' => [ $card => $words[3][2] ] ] ] );
        $this->assertSame( 200, $r['status'], (string) json_encode( $r['body'] ) );
        $this->assertSame( [], $r['body']['ignored'] );
        foreach ( $words as [ $section, $slot ] ) {
            $this->assertContains( [ 'section' => $section, 'slot' => $slot ], $r['body']['applied'], "$slot is applied" );
        }
        $saved = $this->storedBrief( $o['id'] );
        $this->assertSame( $words[0][2], $saved['sections'][0]['slots']['heading.1'] );
        $this->assertSame( $words[1][2], $saved['sections'][0]['slots']['heading.0'] );
        $this->assertSame( $words[2][2], $saved['sections'][0]['slots']['button.0.text'] );
        $this->assertSame( $words[3][2], $saved['sections'][1]['slots'][ $card ] );
        $this->assertTrue( StudioBrief::check( $saved )['ok'] );

        $preview = StudioService::preview( self::ME, $saved );
        $this->assertTrue( $preview['ok'], (string) json_encode( $preview['report'] ) );
        $markup = (string) $preview['markup'];
        // Present, but only in escaped form (the markup is block JSON, so entities appear as \u0026...).
        $this->assertStringContainsString( '\u0026#123;\u0026#123;aied:image:x}}', $markup );
        $this->assertStringContainsString( '\u0026#36;variable(', $markup );
        $this->assertStringContainsString( '\u0026lt;script\u0026gt;alert(1)\u0026lt;/script\u0026gt;', $markup );
        $this->assertStringContainsString( '\u0026lt;/p\u0026gt;\u0026lt;p\u0026gt;', $markup );
        // And never raw.
        $this->assertStringNotContainsString( '<script', $markup );
        $this->assertStringNotContainsString( '\u003cscript', $markup );
        $this->assertStringNotContainsString( '{{aied:image:x}}', $markup );
        $this->assertStringNotContainsString( '$variable(', $markup );
        $this->assertStringNotContainsString( '</p><p>', $markup );
        $this->assertStringNotContainsString( '\u003c/p\u003e\u003cp\u003e', $markup );
    }

    public function testMoreThan200SlotIdsInOneSectionIs400AndNothingIsWritten(): void
    {
        $o     = $this->open();
        $slots = [ 'heading.1' => 'Good words' ];
        for ( $i = 0; $i < 200; $i++ ) {
            $slots[ 'extra.' . $i ] = 'x';
        }
        $before = $this->rows();
        $r      = $this->fill( $o, [ [ 'section' => 0, 'slots' => $slots ] ] );
        $this->assertSame( 400, $r['status'] );
        $this->assertSame( $before, $this->rows() );
        // 200 in total (slots and pictures together) is still accepted.
        $pictures = [ 'section.0.background' => [ 'pack' => 'hero-blue-1' ] ];
        $ok       = array_slice( $slots, 0, 199, true );
        $r        = $this->fill( $o, [ [ 'section' => 0, 'slots' => $ok, 'pictures' => $pictures ] ] );
        $this->assertSame( 200, $r['status'], (string) json_encode( $r['body'] ) );
        $o['hash'] = $r['body']['base_hash'];
        $slots2    = array_slice( $slots, 0, 200, true );
        $r2        = $this->fill( $o, [ [ 'section' => 0, 'slots' => $slots2, 'pictures' => $pictures ] ] );
        $this->assertSame( 400, $r2['status'], 'slots and pictures are counted together' );
    }

    public function testARowThatVanishesDuringAConflictIs404NotAnEmptyConflict(): void
    {
        $o  = $this->open();
        $id = $o['id'];
        $this->assertSame( 200, $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'Newer' ] ] ] )['status'] );
        $db          = $GLOBALS['wpdb'];
        $db->queries = []; // only the UPDATE of the call under test may arm the hook
        $reads = 0;
        $armed = false;
        // After the stale compare-and-set UPDATE, let the store's own re-read see the row (so it reports CONFLICT) and
        // delete the row before the next read (the tool's lookup of the current hash).
        $db->beforeSelect = static function ( string $sql ) use ( $db, &$reads, &$armed, $id ): void {
            if ( ! $armed ) {
                foreach ( $db->queries as $q ) {
                    if ( str_starts_with( $q, 'UPDATE' ) && str_contains( $q, 'AND brief_hash' ) ) {
                        $armed = true;
                    }
                }
            }
            if ( $armed && str_contains( $sql, 'SELECT' ) && 2 === ++$reads ) {
                $db->query( 'DELETE FROM ' . StudioTable::name() . ' WHERE id = ' . $id );
            }
        };
        $r = $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'Stale' ] ] ] );
        $db->beforeSelect = null;
        $this->assertSame( 404, $r['status'], (string) json_encode( $r['body'] ) );
        $this->assertSame( [ 'error' => 'Brief not found.' ], $r['body'] );
        $this->assertTrue( $armed, 'the compare-and-set UPDATE ran, so the conflict branch was reached' );
    }

    public function testTwoFillsInARowWithTheNewHashBothSucceed(): void
    {
        $o  = $this->open();
        $r1 = $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'One' ] ] ] );
        $this->assertSame( 200, $r1['status'] );
        $second = (string) array_column( StudioSlots::extract( (string) SectionRecipes::rawMarkup( 'card-grid-3' ) ), 'id' )[0];
        $r2 = $this->fill( $o, [ [ 'section' => 1, 'slots' => [ $second => 'Two' ] ] ], $r1['body']['base_hash'] );
        $this->assertSame( 200, $r2['status'], (string) json_encode( $r2['body'] ) );
        $this->assertNotSame( $r1['body']['base_hash'], $r2['body']['base_hash'] );
        $saved = $this->storedBrief( $o['id'] );
        $this->assertSame( 'One', $saved['sections'][0]['slots']['heading.1'] );
        $this->assertSame( 'Two', $saved['sections'][1]['slots'][ $second ] );
    }

    public function testTheEmptyStringClearsASlotAndTheUnfilledCountRises(): void
    {
        $o       = $this->open();
        $opened  = StudioAssistant::run( [ 'action' => 'open', 'brief_id' => $o['id'] ] )['body'];
        $r       = $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'heading.1' => '' ] ] ] );
        $this->assertSame( 200, $r['status'] );
        $this->assertSame( [ [ 'section' => 0, 'slot' => 'heading.1' ] ], $r['body']['applied'] );
        $this->assertSame( $opened['unfilled_count'] + 1, $r['body']['unfilled_count'] );
        $this->assertArrayNotHasKey( 'heading.1', $this->storedBrief( $o['id'] )['sections'][0]['slots'] );
        $reopened = StudioAssistant::run( [ 'action' => 'open', 'brief_id' => $o['id'] ] )['body'];
        $this->assertSame( $r['body']['unfilled_count'], $reopened['unfilled_count'] );
        $this->assertSame( $r['body']['base_hash'], $reopened['base_hash'] );
    }

    public function testFillReportsTheLinkedPageOnlyWhenTheUserCanEditIt(): void
    {
        $o = $this->open();
        $GLOBALS['__wp_posts'][77] = (object) [ 'ID' => 77, 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => 'x' ];
        StudioStore::link( self::ME, $o['id'], 77, hash( 'sha256', 'x' ) );
        $o['hash'] = (string) StudioStore::get( self::ME, $o['id'] )['brief_hash'];
        $GLOBALS['__wp_caps']['edit_post:77'] = false;
        $this->assertNull( $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'a' ] ] ] )['body']['page'] );
        $o['hash'] = (string) StudioStore::get( self::ME, $o['id'] )['brief_hash'];
        $GLOBALS['__wp_caps']['edit_post:77'] = true;
        $this->assertSame( [ 'id' => 77, 'status' => 'draft' ], $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'b' ] ] ] )['body']['page'] );
        $this->assertCount( 1, $GLOBALS['__wp_posts'] );
        $this->assertSame( 'x', $GLOBALS['__wp_posts'][77]->post_content, 'fill never rebuilds the page' );
    }

    // ---- build and create (Task 5) ------------------------------------------------------------------------

    /** The user may also publish and edit pages (needed for create:true). */
    private function mayCreate(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'publish_pages' => true, 'edit_post' => true ];
    }

    private function postCount(): int
    {
        return count( $GLOBALS['__wp_posts'] );
    }

    private function build( array $brief, bool $create = false ): array
    {
        $args = [ 'action' => 'build', 'brief' => $brief ];
        if ( $create ) {
            $args['create'] = true;
        }

        return StudioAssistant::run( $args );
    }

    public function testBuildWithoutCreatePreviewsAndSavesNothing(): void
    {
        $r = $this->build( self::brief() );
        $this->assertSame( 200, $r['status'] );
        $b = $r['body'];
        $this->assertTrue( $b['valid'] );
        $this->assertFalse( $b['created'] );
        $this->assertTrue( $b['markup_omitted'] );
        $this->assertArrayNotHasKey( 'markup', $b );
        $this->assertStringNotContainsString( 'Fix leaks fast', (string) json_encode( $b ), 'no page text comes back' );
        foreach ( [ 'problems', 'violations', 'unfilled', 'refused', 'warnings', 'advice' ] as $key ) {
            $this->assertArrayHasKey( $key, $b['report'] );
        }
        $this->assertIsString( $b['message'] );
        $this->assertStringContainsString( 'create', $b['next'] );
        $this->assertStringNotContainsString( 'Studio', (string) json_encode( $b ) );
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
        $this->assertSame( [], $this->rows(), 'a preview stores no brief' );
        $this->assertSame( 200, StudioAssistant::run( [ 'action' => 'build', 'brief' => self::brief(), 'create' => false ] )['status'] );
    }

    public function testBuildNeedsABriefObject(): void
    {
        $this->assertSame( 400, StudioAssistant::run( [ 'action' => 'build' ] )['status'] );
        $this->assertSame( 400, StudioAssistant::run( [ 'action' => 'build', 'brief' => null ] )['status'] );
        $this->assertSame( 400, StudioAssistant::run( [ 'action' => 'build', 'brief' => 'x' ] )['status'] );
        $this->assertSame( 400, StudioAssistant::run( [ 'action' => 'build', 'brief' => [ 'a', 'b' ] ] )['status'] );
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
    }

    public function testBuildWithAnInvalidBriefIs422ListingTheProblemPaths(): void
    {
        $this->mayCreate();
        $unknownRecipe = self::brief();
        $unknownRecipe['sections'][1]['recipe'] = 'no-such-recipe';
        $unknownLanguage = self::brief();
        $unknownLanguage['language'] = 'no-such-language';
        foreach ( [ [ $unknownRecipe, 'sections' ], [ $unknownLanguage, 'language' ] ] as [ $brief, $path ] ) {
            foreach ( [ false, true ] as $create ) {
                $r = $this->build( $brief, $create );
                $this->assertSame( 422, $r['status'], $path . ( $create ? ' create' : ' preview' ) );
                $this->assertSame( StudioService::INVALID_BRIEF, $r['body']['code'] );
                $this->assertFalse( $r['body']['created'] );
                $paths = array_map( static fn ( array $p ): string => (string) $p['path'], $r['body']['report']['problems'] );
                $this->assertNotSame( [], $paths );
                $this->assertStringContainsString( $path, implode( ' ', $paths ) );
            }
        }
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
        $this->assertSame( [], $this->rows() );
    }

    public function testBuildCreateMakesExactlyOneDraftThatLinksTheBrief(): void
    {
        $this->mayCreate();
        $r = $this->build( self::brief(), true );
        $this->assertSame( 200, $r['status'], (string) json_encode( $r['body'] ) );
        $b = $r['body'];
        $this->assertTrue( $b['created'] );
        $this->assertFalse( $b['duplicate'] );
        $this->assertSame( 'draft', $b['page']['status'] );
        $this->assertArrayHasKey( 'link', $b['page'] );
        $this->assertArrayHasKey( 'report', $b );
        $this->assertCount( 1, $GLOBALS['__wp_posts'] );
        $pageId = (int) $b['page']['id'];
        $post   = $GLOBALS['__wp_posts'][ $pageId ];
        $this->assertSame( 'draft', $post->post_status );
        $this->assertStringContainsString( 'Fix leaks fast', (string) $post->post_content );

        $stored = StudioStore::get( self::ME, (int) $b['brief_id'] );
        $this->assertNotNull( $stored );
        $this->assertSame( $pageId, $stored['page_id'] );
        $this->assertSame( $stored['brief_hash'], $b['base_hash'] );
        $this->assertStringNotContainsString( 'Studio', (string) json_encode( $b ) );
    }

    public function testASecondIdenticalCreateIsADuplicateAndMakesNoSecondPage(): void
    {
        $this->mayCreate();
        $first  = $this->build( self::brief(), true );
        $second = $this->build( self::brief(), true );
        $this->assertSame( 200, $second['status'] );
        $this->assertTrue( $second['body']['created'] );
        $this->assertTrue( $second['body']['duplicate'] );
        $this->assertSame( $first['body']['page']['id'], $second['body']['page']['id'] );
        $this->assertSame( $first['body']['brief_id'], $second['body']['brief_id'] );
        $this->assertCount( 1, $GLOBALS['__wp_posts'] );
        $this->assertCount( 1, $this->rows() );
    }

    public function testCreateNeedsPublishPagesAndMakesNothingWithout(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'edit_post' => true ]; // no publish_pages
        $r = $this->build( self::brief(), true );
        $this->assertSame( 403, $r['status'] );
        $this->assertArrayHasKey( 'error', $r['body'] );
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
        $this->assertSame( [], $this->rows() );

        // A preview needs only edit_pages.
        $this->assertSame( 200, $this->build( self::brief() )['status'] );

        // And so does fill with create:true: refused BEFORE the words are written.
        $o = $this->open();
        $r = StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $o['id'], 'base_hash' => $o['hash'], 'create' => true, 'sections' => [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'Nope' ] ] ] ] );
        $this->assertSame( 403, $r['status'] );
        $this->assertSame( self::brief(), $this->storedBrief( $o['id'] ), 'the words were not written either' );
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
    }

    public function testCreateIsRefusedOnActionsThatCannotBuild(): void
    {
        $this->mayCreate();
        $id = $this->stored();
        $this->assertSame( 400, StudioAssistant::run( [ 'action' => 'catalog', 'create' => true ] )['status'] );
        $this->assertSame( 400, StudioAssistant::run( [ 'action' => 'open', 'brief_id' => $id, 'create' => true ] )['status'] );
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
    }

    public function testFillWithCreateOnABriefWithoutAPageMakesADraft(): void
    {
        $this->mayCreate();
        $o = $this->open();
        $r = StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $o['id'], 'base_hash' => $o['hash'], 'create' => true, 'sections' => [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'Plumbing, sorted' ] ] ] ] );
        $this->assertSame( 200, $r['status'], (string) json_encode( $r['body'] ) );
        $b = $r['body'];
        $this->assertSame( 'created', $b['build']['mode'] );
        $this->assertNull( $b['build']['reason'] );
        $this->assertSame( 'draft', $b['build']['page']['status'] );
        $this->assertCount( 1, $GLOBALS['__wp_posts'] );
        $pageId = (int) $b['build']['page']['id'];
        $this->assertStringContainsString( 'Plumbing, sorted', (string) $GLOBALS['__wp_posts'][ $pageId ]->post_content );
        $this->assertSame( $pageId, $b['page']['id'], 'page now reports the built page' );
        $this->assertSame( $pageId, StudioStore::get( self::ME, $o['id'] )['page_id'] );
        $this->assertSame( StudioStore::get( self::ME, $o['id'] )['brief_hash'], $b['base_hash'] );
        $this->assertStringNotContainsString( 'was not changed', $b['message'] );
    }

    public function testFillWithCreateOverwritesAnUntouchedPageAndKeepsOneHistoryRow(): void
    {
        $this->mayCreate();
        $o  = $this->open();
        $r1 = StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $o['id'], 'base_hash' => $o['hash'], 'create' => true, 'sections' => [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'First words' ] ] ] ] );
        $pageId = (int) $r1['body']['build']['page']['id'];
        $before = $GLOBALS['__wp_posts'][ $pageId ]->post_content;

        $r2 = StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $o['id'], 'base_hash' => $r1['body']['base_hash'], 'create' => true, 'sections' => [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'Second words' ] ] ] ] );
        $this->assertSame( 200, $r2['status'], (string) json_encode( $r2['body'] ) );
        $this->assertSame( 'overwritten', $r2['body']['build']['mode'] );
        $this->assertNull( $r2['body']['build']['reason'] );
        $this->assertSame( $pageId, $r2['body']['build']['page']['id'] );
        $this->assertCount( 1, $GLOBALS['__wp_posts'] );
        $this->assertStringContainsString( 'Second words', (string) $GLOBALS['__wp_posts'][ $pageId ]->post_content );
        $rows = HistoryService::listFor( $pageId );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'studio', $rows[0]['tool'] ?? null );
        $restored = HistoryService::restore( $pageId, (int) $rows[0]['id'] );
        $this->assertTrue( $restored['ok'] );
        $this->assertSame( $before, $GLOBALS['__wp_posts'][ $pageId ]->post_content );
    }

    public function testFillWithCreateOnAnEditedPageMakesANewDraftAndLeavesTheEditedOneAlone(): void
    {
        $this->mayCreate();
        $o  = $this->open();
        $r1 = StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $o['id'], 'base_hash' => $o['hash'], 'create' => true, 'sections' => [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'First words' ] ] ] ] );
        $pageId = (int) $r1['body']['build']['page']['id'];
        $GLOBALS['__wp_posts'][ $pageId ]->post_content .= "\n<!-- edited in Divi -->";
        $edited = $GLOBALS['__wp_posts'][ $pageId ]->post_content;
        unset( $GLOBALS['__wp_update_calls'] );

        $r2 = StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $o['id'], 'base_hash' => $r1['body']['base_hash'], 'create' => true, 'sections' => [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'Second words' ] ] ] ] );
        $this->assertSame( 200, $r2['status'] );
        $b = $r2['body']['build'];
        $this->assertSame( 'new_draft', $b['mode'] );
        $this->assertSame( 'edited', $b['reason'] );
        $this->assertNotSame( $pageId, $b['page']['id'] );
        $this->assertSame( 'draft', $b['page']['status'] );
        $this->assertSame( $edited, $GLOBALS['__wp_posts'][ $pageId ]->post_content, 'the edited page is byte-identical' );
        $this->assertArrayNotHasKey( '__wp_update_calls', $GLOBALS );
        $this->assertCount( 2, $GLOBALS['__wp_posts'] );
        $this->assertStringContainsString( 'edited', $r2['body']['message'] );
        $this->assertSame( (int) $b['page']['id'], StudioStore::get( self::ME, $o['id'] )['page_id'] );
    }

    public function testFillWithCreateThatAppliedNothingStillBuildsFromTheStoredBrief(): void
    {
        // Ruling: create:true is an explicit request for the page; the stored brief is current, so it is built, and
        // `ignored` tells the assistant its words did not land.
        $this->mayCreate();
        $o = $this->open();
        $r = StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $o['id'], 'base_hash' => $o['hash'], 'create' => true, 'sections' => [ [ 'section' => 0, 'slots' => [ 'no.such.slot' => 'x' ] ] ] ] );
        $this->assertSame( 200, $r['status'] );
        $this->assertSame( [], $r['body']['applied'] );
        $this->assertNotSame( [], $r['body']['ignored'] );
        $this->assertSame( 'created', $r['body']['build']['mode'] );
        $this->assertSame( $o['hash'], $r['body']['base_hash'], 'the brief row was not touched' );
        $this->assertCount( 1, $GLOBALS['__wp_posts'] );
    }

    public function testFillWithCreateAndAStaleHashBuildsNothing(): void
    {
        $this->mayCreate();
        $o = $this->open();
        $r = StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $o['id'], 'base_hash' => str_repeat( 'a', 64 ), 'create' => true, 'sections' => [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'x' ] ] ] ] );
        $this->assertSame( 409, $r['status'] );
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
        $this->assertSame( self::brief(), $this->storedBrief( $o['id'] ) );
    }

    public function testFillWithCreateOnARemovedRecipeIs422AndSaysWhetherTheWordsWereSaved(): void
    {
        $this->mayCreate();
        // A brief whose section 1 names a retired layout, already linked to a page. Fill itself refuses to SAVE such a
        // brief (the brief check names the layout), so only a fill that writes nothing reaches the rebuild.
        $brief = self::brief();
        $brief['sections'][1]['recipe'] = 'retired-layout';
        $json = (string) json_encode( $brief );
        $GLOBALS['wpdb']->insert( StudioTable::name(), [
            'created_by' => self::ME, 'brief' => $json, 'brief_hash' => hash( 'sha256', $json ), 'plugin_version' => '4', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ] );
        $id = (int) $GLOBALS['wpdb']->insert_id;
        $GLOBALS['__wp_posts'][77] = (object) [ 'ID' => 77, 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => 'x' ];
        StudioStore::link( self::ME, $id, 77, hash( 'sha256', 'x' ) );
        $hash = (string) StudioStore::get( self::ME, $id )['brief_hash'];

        $r = StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $id, 'base_hash' => $hash, 'create' => true, 'sections' => [ [ 'section' => 0, 'slots' => [ 'no.such.slot' => 'x' ] ] ] ] );
        $this->assertSame( 422, $r['status'], (string) json_encode( $r['body'] ) );
        $this->assertSame( StudioService::RECIPE_REMOVED, $r['body']['code'] );
        $this->assertArrayHasKey( 'message', $r['body'] );
        $this->assertFalse( $r['body']['words_saved'], 'nothing was written, so the words were not saved' );
        $this->assertStringNotContainsString( 'words were saved', $r['body']['message'] );
        $this->assertSame( 'x', $GLOBALS['__wp_posts'][77]->post_content );
        $this->assertCount( 1, $GLOBALS['__wp_posts'] );
    }

    public function testAFailedBuildAfterAFillThatSavedWordsSaysTheWordsAreSaved(): void
    {
        $this->mayCreate();
        $o = $this->open();
        $GLOBALS['__wp_insert_fail'] = true; // the draft cannot be inserted
        $r = StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $o['id'], 'base_hash' => $o['hash'], 'create' => true, 'sections' => [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'Kept words' ] ] ] ] );
        $this->assertSame( 500, $r['status'], (string) json_encode( $r['body'] ) );
        $this->assertSame( StudioService::SAVE_FAILED, $r['body']['code'] );
        $this->assertTrue( $r['body']['words_saved'] );
        $this->assertStringContainsString( 'words were saved', $r['body']['message'] );
        $this->assertSame( 'Kept words', $this->storedBrief( $o['id'] )['sections'][0]['slots']['heading.1'] );
        $this->assertSame( StudioStore::get( self::ME, $o['id'] )['brief_hash'], $r['body']['base_hash'] );
        $this->assertSame( [], $GLOBALS['__wp_posts'] );
    }

    public function testServiceRefusalsMapToStatuses(): void
    {
        $map = new \ReflectionMethod( StudioAssistant::class, 'serviceStatus' );
        foreach ( [ 'INVALID_BRIEF' => 422, 'LAYOUT_INVALID' => 422, 'RECIPE_REMOVED' => 422, 'NOT_FOUND' => 404, 'STORE_FAILED' => 500, 'SAVE_FAILED' => 500, 'INTERNAL_ERROR' => 500, 'SOMETHING_NEW' => 500 ] as $code => $status ) {
            $this->assertSame( $status, $map->invoke( null, $code ), $code );
        }
    }

    public function testNoPathEverMakesAnythingButADraft(): void
    {
        $this->mayCreate();
        $this->build( self::brief(), true );
        $o = $this->open();
        StudioAssistant::run( [ 'action' => 'fill', 'brief_id' => $o['id'], 'base_hash' => $o['hash'], 'create' => true, 'sections' => [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'Another' ] ] ] ] );
        $this->assertNotSame( [], $GLOBALS['__wp_posts'] );
        foreach ( $GLOBALS['__wp_posts'] as $post ) {
            $this->assertSame( 'draft', $post->post_status );
        }
        foreach ( $GLOBALS['__wp_inserts'] as $insert ) {
            $this->assertSame( 'draft', $insert['post_status'] ?? 'draft' );
        }
    }

    public function testCatalogHowSaysBuildPreviewsOrCreatesADraft(): void
    {
        $how = (string) StudioAssistant::run( [ 'action' => 'catalog' ] )['body']['how'];
        $this->assertStringContainsString( 'create', $how );
        $this->assertStringContainsString( 'true', $how );
        $this->assertStringContainsString( 'draft', $how );
        $this->assertLessThanOrEqual( 1500, strlen( $how ) );
    }

    public function testFillNeedsEditPages(): void
    {
        $o = $this->open();
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => false ];
        $this->assertSame( 403, $this->fill( $o, [ [ 'section' => 0, 'slots' => [ 'heading.1' => 'x' ] ] ] )['status'] );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->assertSame( self::brief(), $this->storedBrief( $o['id'] ) );
    }
}
