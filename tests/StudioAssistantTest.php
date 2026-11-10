<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StudioAssistant;
use AiEditorDivi5\WP\StudioBrief;
use AiEditorDivi5\WP\StudioSlots;
use AiEditorDivi5\WP\StudioStore;
use AiEditorDivi5\WP\StudioTable;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
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
        $GLOBALS['__wp_options']         = [ StudioTable::DB_VERSION_OPTION => StudioTable::DB_VERSION ];
        $GLOBALS['__wp_posts']           = [];
        $GLOBALS['__wp_inserts']         = [];
        $GLOBALS['__wp_current_user_id'] = self::ME;
        $GLOBALS['__wp_caps']            = [ 'edit_pages' => true ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']         = null;
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_posts']   = [];
        $GLOBALS['__wp_inserts'] = [];
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'] );
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

    public function testFillAndBuildAreValidActionsNotBuiltYet(): void
    {
        foreach ( [ 'fill', 'build' ] as $action ) {
            $r = StudioAssistant::run( [ 'action' => $action ] );
            $this->assertSame( 400, $r['status'] );
            $this->assertSame( 'This action is not built yet.', $r['body']['error'] );
        }
    }

    public function testArgumentTypesAreChecked(): void
    {
        $bad = [
            [ 'recipe' => 3 ], [ 'role' => [ 'hero' ] ], [ 'base_hash' => 5 ], [ 'create' => 'true' ], [ 'create' => 1 ],
            [ 'sections' => 'x' ], [ 'brief' => 'x' ],
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
}
