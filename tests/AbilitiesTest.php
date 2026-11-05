<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\Abilities;
use AiEditorDivi5\WP\AbilityCatalog;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\McpHandler;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/WpInsertPostShims.php';
require_once __DIR__ . '/DiviGlobalDataShims.php';
require_once __DIR__ . '/GetPostsShim.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/DraftPage.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/ApprovalMode.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';
require_once __DIR__ . '/../wp-plugin/src/Abilities.php';

/**
 * The abilities: how they are registered, who may call them and what they return. The tool behind each ability is the very
 * tool the MCP endpoint runs, so these tests also pin that an ability can never do what the tool refuses.
 */
class AbilitiesTest extends TestCase
{
    private const ORIGINAL = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    private const NEW_VALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    private const NEW_INVALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/not-a-module {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']      = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $post                         = new \WP_Post();
        $post->ID                     = 5;
        $post->post_content           = self::ORIGINAL;
        $GLOBALS['__wp_posts']        = [ 5 => $post ];
        $GLOBALS['__wp_postmeta']     = [];
        $GLOBALS['__wp_update_fail']  = false;
        $GLOBALS['__wp_update_calls'] = 0;
        $GLOBALS['__wp_can']          = true;
        $GLOBALS['__wp_current_user_id'] = 7;
        unset( $GLOBALS['__wp_caps'] );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']                 = null;
        $GLOBALS['__wp_options']         = [];
        $GLOBALS['__wp_can']             = true;
        $GLOBALS['__wp_current_user_id'] = 7;
        unset( $GLOBALS['__wp_caps'] );
    }

    /** @return array<string,mixed> */
    private function def( string $name ): array
    {
        return (array) AbilityCatalog::get( $name );
    }

    // ---- registration arguments ----------------------------------------------------------

    public function testArgsHaveEverythingCoreRequiresAndExposeTheAbilityToRestAndMcp(): void
    {
        foreach ( AbilityCatalog::all() as $def ) {
            $args = Abilities::args( $def, AbilityCatalog::CATEGORY, static fn () => true, static fn () => [] );
            foreach ( [ 'label', 'description', 'category' ] as $field ) {
                $this->assertIsString( $args[ $field ] );
                $this->assertNotSame( '', $args[ $field ] );
            }
            $this->assertTrue( is_callable( $args['execute_callback'] ) );
            $this->assertTrue( is_callable( $args['permission_callback'] ), 'no ability is public: every one has a permission callback' );
            $this->assertSame( [ 'type' => 'object' ], $args['output_schema'] );
            $meta = $args['meta'];
            $this->assertTrue( $meta['public'] );
            $this->assertTrue( $meta['show_in_rest'], 'core exposes an ability over REST only with this flag' );
            // Read from the official WordPress MCP adapter (WordPress/mcp-adapter v0.7.1: McpAbilityExposure, McpAbilityHelperTrait).
            $this->assertSame( [ 'public' => true, 'type' => 'tool' ], $meta['mcp'] );
            $this->assertSame( $def['annotations'], $meta['annotations'] );
        }
    }

    public function testRegisterAllDoesNothingWhereTheAbilitiesApiDoesNotExist(): void
    {
        // This test file never defines wp_register_ability, so the guard must make both calls a no-op.
        $this->assertFalse( function_exists( 'wp_register_ability' ) );
        Abilities::registerCategory();
        Abilities::registerAll();
        $this->addToAssertionCount( 1 );
    }

    // ---- who may call an ability ---------------------------------------------------------

    public function testNobodyWhoIsNotLoggedInMayCallAnything(): void
    {
        $GLOBALS['__wp_current_user_id'] = 0;
        foreach ( AbilityCatalog::all() as $def ) {
            $this->assertFalse( Abilities::permit( $def, [] ), $def['name'] );
        }
    }

    public function testAUserWhoCannotEditPagesIsDeniedEverything(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_posts' => true, 'edit_post' => true ];
        foreach ( AbilityCatalog::all() as $def ) {
            $this->assertFalse( Abilities::permit( $def, [] ), $def['name'] );
        }
    }

    public function testAnEditorMayCallThemAll(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'edit_post' => true ];
        foreach ( AbilityCatalog::all() as $def ) {
            $this->assertTrue( Abilities::permit( $def, $def['page_arg'] ? [ 'page_id' => 5 ] : [] ), $def['name'] );
        }
    }

    public function testAnAbilityThatNamesAPageNeedsEditPostOnThatPage(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'edit_post' => true, 'edit_post:5' => false ];
        foreach ( [ 'get-page-layout', 'update-page-layout', 'edit-page-content', 'list-page-history', 'get-page-history-entry', 'restore-page-version', 'check-design', 'get-variety-advice' ] as $name ) {
            $this->assertFalse( Abilities::permit( $this->def( $name ), [ 'page_id' => 5 ] ), $name );
            $this->assertFalse( Abilities::permit( $this->def( $name ), [ 'page_id' => '5' ] ), $name . ' with the id as a string' );
        }
        $this->assertTrue( Abilities::permit( $this->def( 'get-style-guide' ), [] ), 'a guide names no page' );
    }

    // ---- what they return ----------------------------------------------------------------

    public function testAGuideAbilityReturnsTheGuideText(): void
    {
        $r = Abilities::run( $this->def( 'get-style-guide' ), [] );
        $this->assertArrayHasKey( 'content', $r );
        $this->assertStringContainsString( 'Divi', $r['content'] );
        $this->assertSame( ( new McpHandler() )->callTool( 'get_style_guide', [] )['text'], $r['content'], 'the same text the MCP tool serves' );
    }

    public function testValidateLayoutReturnsTheVerdictAsData(): void
    {
        $bad = Abilities::run( $this->def( 'validate-layout' ), [ 'post_content' => 'not divi' ] );
        $this->assertFalse( $bad['valid'] );
        $this->assertSame( 'NO_BLOCKS_FOUND', $bad['violations'][0]['code'] );
        $ok = Abilities::run( $this->def( 'validate-layout' ), [ 'post_content' => self::NEW_VALID ] );
        $this->assertTrue( $ok['valid'] );
    }

    public function testAnInvalidLayoutIsRefusedWithTheExactViolationsAndNothingIsWritten(): void
    {
        $r = Abilities::run( $this->def( 'update-page-layout' ), [ 'page_id' => 5, 'post_content' => self::NEW_INVALID ] );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 'jhmg_rejected', $r->code );
        $this->assertStringContainsString( 'not-a-module', $r->message );
        $this->assertSame( self::ORIGINAL, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testAValidSaveGoesThroughTheGateAndKeepsAnUndoPoint(): void
    {
        $r = Abilities::run( $this->def( 'update-page-layout' ), [ 'page_id' => 5, 'post_content' => self::NEW_VALID ] );
        $this->assertIsArray( $r );
        $this->assertTrue( $r['history']['stored'] );
        $this->assertSame( self::NEW_VALID, $GLOBALS['__wp_posts'][5]->post_content );

        $list = Abilities::run( $this->def( 'list-page-history' ), [ 'page_id' => 5 ] );
        $this->assertSame( 1, $list['count'] );
        $back = Abilities::run( $this->def( 'restore-page-version' ), [ 'page_id' => 5, 'version_id' => $list['versions'][0]['id'] ] );
        $this->assertIsArray( $back );
        $this->assertSame( self::ORIGINAL, $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testDryRunThroughAnAbilityNeverWrites(): void
    {
        $r = Abilities::run( $this->def( 'update-page-layout' ), [ 'page_id' => 5, 'post_content' => self::NEW_VALID, 'dry_run' => true ] );
        $this->assertIsArray( $r );
        $this->assertSame( self::ORIGINAL, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testThePermissionCheckOfTheToolStillAppliesBehindTheAbility(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'edit_post' => true, 'edit_post:5' => false ];
        $r = Abilities::run( $this->def( 'update-page-layout' ), [ 'page_id' => 5, 'post_content' => self::NEW_VALID ] );
        $this->assertInstanceOf( \WP_Error::class, $r, 'the ability layer is not the only gate' );
        $this->assertSame( self::ORIGINAL, $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testAMissingPageIsAnErrorNotACrash(): void
    {
        $r = Abilities::run( $this->def( 'get-page-layout' ), [ 'page_id' => 999 ] );
        $this->assertInstanceOf( \WP_Error::class, $r );
    }

    public function testOnlyTheCatalogToolsAreReachableThroughCallTool(): void
    {
        $r = ( new McpHandler() )->callTool( 'set_front_page', [] );
        $this->assertFalse( $r['ok'], 'a Pro tool is never run through the free abilities' );
        $this->assertSame( -32602, $r['code'] );
    }

    public function testAListResultBecomesAnObject(): void
    {
        $r = Abilities::fromToolResult( [ 'ok' => true, 'text' => '[1,2]', 'data' => [ 1, 2 ], 'code' => null, 'message' => '' ] );
        $this->assertSame( [ 'items' => [ 1, 2 ] ], $r );
    }
}
