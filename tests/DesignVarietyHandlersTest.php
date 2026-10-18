<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use AiEditorDivi5\WP\SectionRecipes;
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

/**
 * The design variety and unresolved-token advisories through the REAL REST and MCP handlers: present when a valid page
 * is repetitive or still carries an {{aied:...}} token, absent for a varied page and for a one-section edit, and never
 * able to change a verdict, block a save or rewrite the content that is stored.
 */
class DesignVarietyHandlersTest extends TestCase
{
    private function section( string $text, string $accent = '' ): string
    {
        $attrs = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $text ] ] ], 'builderVersion' => '5.14.0' ];
        if ( '' !== $accent ) {
            $attrs['title']['decoration'] = [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => $accent ] ] ] ] ];
        }

        return '<!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading '
            . json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP )
            . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
    }

    /** Four sections with one skeleton: valid, and repetitive. */
    private function repetitive( string $first = 'Section A' ): string
    {
        return '<!-- wp:divi/placeholder -->' . $this->section( $first ) . $this->section( 'Section B' ) . $this->section( 'Section C' ) . $this->section( 'Section D' ) . '<!-- /wp:divi/placeholder -->';
    }

    private function varied(): string
    {
        $out = '';
        foreach ( [ 'hero-cta', 'split-image-text', 'card-grid-3', 'stats-counter', 'newsletter-social' ] as $name ) {
            $out .= (string) SectionRecipes::recipe( $name );
        }

        return '<!-- wp:divi/placeholder -->' . $out . '<!-- /wp:divi/placeholder -->';
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']      = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $post                         = new \WP_Post();
        $post->ID                     = 5;
        $post->post_content           = $this->repetitive();
        $GLOBALS['__wp_posts']        = [ 5 => $post ];
        $GLOBALS['__wp_postmeta']     = [];
        $GLOBALS['__wp_update_fail']  = false;
        $GLOBALS['__wp_update_calls'] = 0;
        $GLOBALS['__wp_inserts']      = [];
        $GLOBALS['__wp_caps']         = [ 'edit_post' => true, 'edit_pages' => true, 'publish_pages' => true ];
        $GLOBALS['__divi_presets']    = [ 'module' => [] ];
        $GLOBALS['__divi_colors']     = [];
        $GLOBALS['__wp_meta_keys']    = [];
        $GLOBALS['__divi_variables']  = [ 'numbers' => [] ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        $GLOBALS['__wp_options'] = [];
        unset( $GLOBALS['__wp_meta_keys'], $GLOBALS['__wp_caps'], $GLOBALS['__divi_presets'], $GLOBALS['__divi_colors'], $GLOBALS['__divi_variables'] );
        $GLOBALS['__wp_can'] = true;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( string $method, array $body, array $params = [] ): array
    {
        $r = ( new RestController() )->$method( new \WP_REST_Request( $body, $params ) );
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array<string,mixed> */
    private function mcp( string $tool, array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        $this->assertArrayNotHasKey( 'error', $data );

        return (array) json_decode( $data['result']['content'][0]['text'], true );
    }

    private function assertVariety( array $payload ): void
    {
        $this->assertArrayHasKey( 'advisories', $payload );
        $this->assertArrayHasKey( 'design_variety', $payload['advisories'] );
        $codes = array_column( $payload['advisories']['design_variety']['findings'], 'code' );
        $this->assertContains( 'REPEATED_LAYOUT', $codes );
        $this->assertNotSame( '', $payload['advisories']['design_variety']['message'] );
    }

    public function testValidateReportsARepetitivePageAndKeepsTheVerdict(): void
    {
        [ $st, $rest ] = $this->rest( 'validate', [ 'post_content' => $this->repetitive() ] );
        $this->assertSame( 200, $st );
        $this->assertTrue( $rest['valid'] );
        $this->assertVariety( $rest );
        $this->assertArrayNotHasKey( 'unresolved_tokens', $rest['advisories'] );

        $mcp = $this->mcp( 'validate_layout', [ 'post_content' => $this->repetitive() ] );
        $this->assertTrue( $mcp['valid'] );
        $this->assertVariety( $mcp );
    }

    public function testDryRunAndSaveCarryItAndTheStoredContentIsByteIdentical(): void
    {
        $input = $this->repetitive( 'New first' );
        // The stored page has another skeleton, so the new one is a real structural change (a same-skeleton text edit is silent by design).
        $GLOBALS['__wp_posts'][5]->post_content = '<!-- wp:divi/placeholder -->' . $this->section( 'Only one' ) . '<!-- /wp:divi/placeholder -->';
        $before = $GLOBALS['__wp_posts'][5]->post_content;

        [ , $rest ] = $this->rest( 'update_page', [ 'post_content' => $input, 'dry_run' => true ], [ 'id' => 5 ] );
        $this->assertTrue( $rest['dry_run'] );
        $this->assertVariety( $rest );
        $mcp = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $input, 'dry_run' => true ] );
        $this->assertVariety( $mcp );
        $this->assertSame( $before, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );

        $saved = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $input ] );
        $this->assertTrue( $saved['saved'] );
        $this->assertTrue( $saved['valid'] );
        $this->assertVariety( $saved );
        $this->assertSame( $input, $GLOBALS['__wp_posts'][5]->post_content, 'the advisory never rewrites the content' );

        // A real REST save: saved, valid, byte-identical. (Its variety advisory is not asserted here: the REST handler reads the
        // stored content from the post object after the write, and the test shims hand every caller the SAME object, which real
        // WordPress does not. The REST dry run above shares the attach call and is asserted.)
        $GLOBALS['__wp_posts'][5]->post_content = '<!-- wp:divi/placeholder -->' . $this->section( 'Only one' ) . '<!-- /wp:divi/placeholder -->';
        [ $st, $restSaved ] = $this->rest( 'update_page', [ 'post_content' => $this->repetitive( 'Via REST' ) ], [ 'id' => 5 ] );
        $this->assertSame( 200, $st );
        $this->assertTrue( $restSaved['saved'] );
        $this->assertTrue( $restSaved['valid'] );
        $this->assertSame( $this->repetitive( 'Via REST' ), $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testCreatePageCarriesIt(): void
    {
        [ $st, $rest ] = $this->rest( 'create_page', [ 'title' => 'T', 'post_content' => $this->repetitive() ] );
        $this->assertSame( 201, $st );
        $this->assertVariety( $rest );
        $mcp = $this->mcp( 'create_page', [ 'title' => 'T2', 'post_content' => $this->repetitive() ] );
        $this->assertTrue( $mcp['created'] );
        $this->assertVariety( $mcp );
    }

    public function testAnEditThatTouchesOneSectionHasNoVarietyAdvisory(): void
    {
        $rest = $this->rest( 'edit_page', [ 'find' => 'Section A', 'replace' => 'Section Alpha' ], [ 'id' => 5 ] )[1];
        $this->assertTrue( $rest['saved'] );
        $this->assertTrue( ! isset( $rest['advisories'] ) || ! isset( $rest['advisories']['design_variety'] ) );
        $mcp = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => 'Section B', 'replace' => 'Section Beta' ] );
        $this->assertTrue( $mcp['saved'] );
        $this->assertTrue( ! isset( $mcp['advisories'] ) || ! isset( $mcp['advisories']['design_variety'] ) );
        $dry = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => 'Section C', 'replace' => 'Section Gamma', 'dry_run' => true ] );
        $this->assertTrue( ! isset( $dry['advisories'] ) || ! isset( $dry['advisories']['design_variety'] ) );
    }

    private function pageWithText( string ...$texts ): string
    {
        $sections = '';
        foreach ( $texts as $t ) {
            $sections .= $this->section( $t );
        }

        return '<!-- wp:divi/placeholder -->' . $sections . '<!-- /wp:divi/placeholder -->';
    }

    public function testATokenLeftInTheTextIsListedAndTheVerdictAndTheSaveAreUnchanged(): void
    {
        $page = $this->pageWithText( 'Hello {{aied:color:accent}}' );

        [ $st, $rest ] = $this->rest( 'validate', [ 'post_content' => $page ] );
        $this->assertSame( 200, $st );
        $this->assertTrue( $rest['valid'], json_encode( $rest ) );
        $this->assertSame( [ '{{aied:color:accent}}' ], $rest['advisories']['unresolved_tokens']['tokens'] );
        $this->assertStringContainsString( 'get_section_recipes', $rest['advisories']['unresolved_tokens']['message'] );
        $this->assertArrayNotHasKey( 'design_variety', $rest['advisories'], 'one section is never judged' );

        $mcp = $this->mcp( 'validate_layout', [ 'post_content' => $page ] );
        $this->assertTrue( $mcp['valid'] );
        $this->assertSame( [ '{{aied:color:accent}}' ], $mcp['advisories']['unresolved_tokens']['tokens'] );

        $dry = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $page, 'dry_run' => true ] );
        $this->assertSame( [ '{{aied:color:accent}}' ], $dry['advisories']['unresolved_tokens']['tokens'] );

        $saved = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $page ] );
        $this->assertTrue( $saved['saved'] );
        $this->assertSame( [ '{{aied:color:accent}}' ], $saved['advisories']['unresolved_tokens']['tokens'] );
        $this->assertSame( $page, $GLOBALS['__wp_posts'][5]->post_content, 'the advisory never rewrites the content' );

        [ , $restSaved ] = $this->rest( 'update_page', [ 'post_content' => $this->pageWithText( 'Again {{aied:font:body}}' ) ], [ 'id' => 5 ] );
        $this->assertTrue( $restSaved['saved'] );
        $this->assertSame( [ '{{aied:font:body}}' ], $restSaved['advisories']['unresolved_tokens']['tokens'] );
    }

    public function testImageFontAndColorTokensAreListedOnceEachInOrder(): void
    {
        $page = $this->pageWithText( 'Hello {{aied:image:something}} {{aied:color:accent}}', '{{aied:font:display}} {{aied:image:something}}' );
        $mcp  = $this->mcp( 'validate_layout', [ 'post_content' => $page ] );
        $this->assertTrue( $mcp['valid'], json_encode( $mcp ) );
        $this->assertSame( [ '{{aied:image:something}}', '{{aied:color:accent}}', '{{aied:font:display}}' ], $mcp['advisories']['unresolved_tokens']['tokens'] );
        $rest = $this->rest( 'validate', [ 'post_content' => $page ] )[1];
        $this->assertSame( $mcp['advisories']['unresolved_tokens'], $rest['advisories']['unresolved_tokens'] );
    }

    public function testATokenInsideAStyleValueIsRefusedByTheGateAndStillExplained(): void
    {
        // The gate rejects braces in a style value (CUSTOM_CSS_NOT_ALLOWED), so a color token there can never be saved;
        // validate still names the token, so the AI learns what to replace.
        $page = '<!-- wp:divi/placeholder -->' . $this->section( 'Hello', '{{aied:color:accent}}' ) . '<!-- /wp:divi/placeholder -->';
        [ , $rest ] = $this->rest( 'validate', [ 'post_content' => $page ] );
        $this->assertFalse( $rest['valid'] );
        $this->assertSame( [ '{{aied:color:accent}}' ], $rest['advisories']['unresolved_tokens']['tokens'] );
        [ $st, $save ] = $this->rest( 'update_page', [ 'post_content' => $page ], [ 'id' => 5 ] );
        $this->assertSame( 422, $st );
        $this->assertArrayNotHasKey( 'advisories', $save );
    }

    public function testAVariedPageBuiltFromRealRecipesCarriesNeitherAdvisory(): void
    {
        $page = $this->varied();
        // No Divi style data on this site, so the separate style-reference advisory has nothing to compare against.
        $GLOBALS['__divi_presets']   = null;
        $GLOBALS['__divi_colors']    = null;
        $GLOBALS['__divi_variables'] = null;
        $this->assertStringNotContainsString( '{{aied:', $page );

        [ , $rest ] = $this->rest( 'validate', [ 'post_content' => $page ] );
        $this->assertTrue( $rest['valid'], json_encode( $rest['violations'] ?? [] ) );
        $this->assertArrayNotHasKey( 'advisories', $rest, json_encode( $rest['advisories'] ?? [] ) );
        $mcp = $this->mcp( 'validate_layout', [ 'post_content' => $page ] );
        $this->assertTrue( $mcp['valid'] );
        $this->assertArrayNotHasKey( 'advisories', $mcp );
    }

    public function testGarbageContentStillGetsTheNormalRefusalWithoutAdvisories(): void
    {
        $mcp = $this->mcp( 'validate_layout', [ 'post_content' => '<!-- wp:divi/section {broken' ] );
        $this->assertFalse( $mcp['valid'] );
        $this->assertArrayNotHasKey( 'advisories', $mcp );
    }
}
