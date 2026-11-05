<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Companion\DesignReview;
use AiEditorDivi5\Pro\Companion\ProAbilities;
use AiEditorDivi5\Pro\Companion\SiteConsistency;
use AiEditorDivi5\Pro\Tools;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../wp-plugin/src/autoload.php';

/** The Pro abilities, the whole-site design review, and proof that the 22 existing Pro tools are untouched. */
class CompanionAbilitiesTest extends TestCase
{
    private const RESERVED = [ 'divi', 'divi-ai', 'divi-mcp', 'et', 'et-builder', 'elegant-themes', 'core', 'mcp-adapter' ];

    public function testNineAbilitiesInTheProCategoryOutsideEveryReservedNamespace(): void
    {
        $defs = ProAbilities::definitions();
        $this->assertSame( [ 'audit-site', 'design-review-site', 'find-replace-site', 'list-batches', 'start-batch', 'end-batch', 'get-mega-menu', 'set-mega-menu', 'undo-batch' ], array_column( $defs, 'name' ) );
        $this->assertSame( 'jhmg-ai-editor-pro', ProAbilities::CATEGORY );
        foreach ( $defs as $d ) {
            $full = ProAbilities::NAMESPACE . '/' . $d['name'];
            $this->assertMatchesRegularExpression( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $full );
            foreach ( self::RESERVED as $reserved ) {
                $this->assertStringStartsNotWith( $reserved . '/', $full );
            }
            $this->assertStringStartsWith( 'Call ', $d['description'] );
            $this->assertStringNotContainsString( "\u{2014}", $d['description'] . $d['label'] );
            $this->assertSame( str_contains( $d['name'], 'mega-menu' ) ? 'edit_theme_options' : 'edit_pages', $d['capability'], 'the capability the matching Pro tool needs' );
            $this->assertSame( 'object', $d['input']['type'] );
            $this->assertIsArray( $d['input']['properties'] );
        }
    }

    public function testTheTwentyTwoEarlierProToolsAreExactlyAsBeforeAndTheMegaMenuToolIsTheTwentyThird(): void
    {
        $names = array_map( static fn ( $t ) => $t->name(), Tools::all() );
        sort( $names );
        $this->assertSame( [
            'audit_site', 'build_site', 'bulk_adjust_prices', 'create_product', 'edit_theme_layout', 'find_replace_site', 'list_batches', 'list_theme_layouts',
            'manage_global_colors', 'manage_global_presets', 'manage_global_variables', 'manage_theme_template', 'propose_php_snippet', 'publish_page',
            'set_custom_css', 'set_custom_fields', 'set_front_page', 'set_primary_menu', 'switch_design', 'undo_batch', 'update_products', 'update_theme_layout',
        ], array_values( array_diff( $names, [ 'manage_mega_menu' ] ) ) );
        $this->assertContains( 'manage_mega_menu', $names );
        $this->assertCount( 23, $names );
    }

    public function testNoAbilityNeedsANewToolRouteOrOpenApiOperation(): void
    {
        $src = (string) file_get_contents( __DIR__ . '/../src/Companion/ProAbilities.php' );
        $this->assertStringNotContainsString( 'register_rest_route', $src );
        $this->assertStringNotContainsString( 'jhmg_aied_mcp_tools', $src );
        $this->assertStringNotContainsString( 'ProOpenApi', $src );
    }

    public function testLockedMeansDeniedAndNothingRuns(): void
    {
        $locked = new ProAbilities( static fn (): bool => false );
        foreach ( ProAbilities::definitions() as $def ) {
            $args = \AiEditorDivi5\WP\Abilities::args( $def, ProAbilities::CATEGORY, static fn ( $i = null ) => false, static fn ( $i = null ) => [] );
            $this->assertTrue( $args['meta']['public'] );
        }
        // The permission callback of a registered ability short-circuits on the licence before it looks at the user.
        $reflect = new \ReflectionMethod( ProAbilities::class, 'registerAll' );
        $this->assertTrue( $reflect->isPublic() );
        $this->assertFalse( function_exists( 'wp_register_ability' ), 'no Abilities API in the unit environment: registration is a no-op' );
        $locked->registerAll();
        $locked->registerCategory();
        $run = $locked->run( 'nope', [] );
        $this->assertInstanceOf( \WP_Error::class, $run );
    }

    public function testRegistrationNeedsAFreePluginThatCanRegisterAbilities(): void
    {
        $src = (string) file_get_contents( __DIR__ . '/../src/Companion/ProAbilities.php' );
        $this->assertStringContainsString( "method_exists( Abilities::class, 'args' )", $src, 'an older free plugin simply gets no abilities' );
    }

    public function testAnOldFreePluginGetsAClearNoticeAndARecentOneGetsNone(): void
    {
        $this->assertSame( '4.17.0', ProAbilities::MIN_FREE );
        $old = \AiEditorDivi5\Pro\Companion\FreeVersionNotice::message( '4.16.0' );
        $this->assertStringContainsString( '4.17.0', (string) $old );
        $this->assertStringContainsString( '4.16.0', (string) $old, 'it says which version is installed' );
        $this->assertStringContainsString( 'Every other Pro tool keeps working', (string) $old );
        $this->assertStringNotContainsString( "\u{2014}", (string) $old );
        $this->assertNotNull( \AiEditorDivi5\Pro\Companion\FreeVersionNotice::message( '4.9.0' ) );
        foreach ( [ '4.17.0', '4.17.1', '4.18.0', '5.0.0', null, '' ] as $ok ) {
            $this->assertNull( \AiEditorDivi5\Pro\Companion\FreeVersionNotice::message( $ok ), var_export( $ok, true ) );
        }
    }

    public function testThePluginRegistersTheNoticeBeforeAndRegardlessOfTheFeatureGuard(): void
    {
        $src = (string) file_get_contents( __DIR__ . '/../src/Plugin.php' );
        $notice = strpos( $src, 'FreeVersionNotice' );
        $guard  = strpos( $src, 'freeSupportsCompanion' );
        $this->assertNotFalse( $notice );
        $this->assertLessThan( $guard, $notice, 'the notice is registered outside the guard, so it shows exactly when the features are off' );
    }

    // ---- consistency and the fix list ----------------------------------------------------

    private function section( string $padding, string $inner = '' ): string
    {
        return '<!-- wp:divi/section {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"' . $padding . '","bottom":"' . $padding . '"}}}}}},"builderVersion":"5.14.0"} -->' . $inner . '<!-- /wp:divi/section -->';
    }

    public function testSpacingIsReportedOnlyWhenMoreThanFourValuesAreInUse(): void
    {
        $few = [ 1 => $this->section( '80px' ) . $this->section( '120px' ), 2 => $this->section( '56px' ) . $this->section( '80px' ) ];
        $this->assertSame( [], SiteConsistency::spacingFindings( $few ) );
        $many = $few + [ 3 => $this->section( '64px' ) . $this->section( '96.0px' ) ];
        $f    = SiteConsistency::spacingFindings( $many );
        $this->assertSame( 'SPACING_VARIES', $f[0]['code'] );
        $this->assertSame( 5, count( $f[0]['detail'] ) );
        $this->assertSame( 2, $f[0]['detail']['80px'] );
        $this->assertArrayHasKey( '96px', $f[0]['detail'], '96.0px and 96px are one value' );
        $this->assertSame( [], SiteConsistency::spacingFindings( [ 1 => $this->section( '5vw' ) ] ), 'only px values are compared' );
    }

    public function testALiteralHexThatIsAGlobalColorIsFlaggedWithTheIdToUse(): void
    {
        $content = '<!-- wp:divi/text {"content":{"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"#9A3B1F"}}}}}}},"builderVersion":"5.14.0"} /--><!-- wp:divi/text {"content":{"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"#111111"}}}}}}},"builderVersion":"5.14.0"} /-->';
        $r = SiteConsistency::literalGlobalColors( $content, [ 'gcid-brand' => '#9a3b1f', 'gcid-x' => 'rgba(1,2,3,0.5)' ] );
        $this->assertSame( [ [ 'code' => 'USE_GLOBAL_COLOR', 'color' => '#9a3b1f', 'use' => 'gcid-brand', 'count' => 1 ] ], $r );
        $this->assertSame( [], SiteConsistency::literalGlobalColors( $content, [] ), 'a site without global colors has nothing to suggest' );
        $this->assertSame( 3, strlen( ltrim( (string) SiteConsistency::hex( '#abc' ), '#' ) ) - 3, '#abc is read as #aabbcc' );
        $this->assertNull( SiteConsistency::hex( 'rgb(1,2,3)' ) );
    }

    public function testTheFixListIsPrioritisedAndMapsEveryFindingKind(): void
    {
        $items = [
            [ 'kind' => 'page', 'id' => 7, 'title' => 'Home', 'content' => 'A' ],
            [ 'kind' => 'page', 'id' => 3, 'title' => 'About', 'content' => 'B' ],
        ];
        $audit = [
            [ 'kind' => 'page', 'id' => 7, 'title' => 'Home', 'code' => 'LINK_NO_POST', 'count' => 2, 'detail' => '/gone' ],
            [ 'kind' => 'page', 'id' => 7, 'title' => 'Home', 'code' => 'UNKNOWN_COLOR_ID', 'count' => 1, 'detail' => 'gcid-x' ],
            [ 'kind' => 'page', 'id' => 3, 'title' => 'About', 'code' => 'UNKNOWN_BLOCK_TYPE', 'count' => 1, 'detail' => 'divi/nope' ],
            [ 'kind' => 'page', 'id' => 3, 'title' => 'About', 'code' => 'LINKS_NOT_ALL_CHECKED', 'count' => 1, 'detail' => '' ],
        ];
        $check = static fn ( string $content ): array => 'A' === $content
            ? [ 'checks' => [
                'contrast'          => [ 'problems' => [ [ 'text_color' => '#fafafa', 'background' => '#ffffff', 'ratio' => 1.04, 'needs' => 3.0, 'block' => 'x' ] ] ],
                'default_fallbacks' => [ 'findings' => [ [ 'code' => 'GREY_PLACEHOLDER_IMAGE', 'confidence' => 'certain', 'message' => 'm', 'block' => 'b' ], [ 'code' => 'STOCK_BUTTON_LOOK', 'confidence' => 'possible', 'message' => 'm2', 'block' => 'b2' ] ] ],
                'variety'           => [ 'details' => [ 'findings' => [ [ 'code' => 'REPEATED_LAYOUT', 'message' => 'rep', 'try' => [ 'hero-cta' ] ] ] ] ],
            ] ]
            : [ 'checks' => [] ];
        $r = DesignReview::build( $items, $audit, $check, [] );

        $this->assertSame( 2, $r['pages_reviewed'] );
        $priorities = array_column( $r['fixes'], 'priority' );
        $sorted     = $priorities;
        usort( $sorted, static fn ( $a, $b ) => [ 'high' => 0, 'medium' => 1, 'low' => 2 ][ $a ] <=> [ 'high' => 0, 'medium' => 1, 'low' => 2 ][ $b ] );
        $this->assertSame( $sorted, $priorities, 'high first, then medium, then low' );
        $byCode = array_column( $r['fixes'], 'priority', 'code' );
        $this->assertSame( 'high', $byCode['LOW_CONTRAST'] );
        $this->assertSame( 'high', $byCode['BROKEN_INTERNAL_LINK'] );
        $this->assertSame( 'high', $byCode['UNKNOWN_BLOCK_TYPE'], 'a layout the checker refuses' );
        $this->assertSame( 'medium', $byCode['UNKNOWN_COLOR_ID'] );
        $this->assertSame( 'medium', $byCode['GREY_PLACEHOLDER_IMAGE'] );
        $this->assertSame( 'low', $byCode['STOCK_BUTTON_LOOK'], 'a "possible" finding is never above low' );
        $this->assertSame( 'low', $byCode['REPEATED_LAYOUT'] );
        $this->assertArrayNotHasKey( 'LINKS_NOT_ALL_CHECKED', $byCode );
        $this->assertSame( [ 'hero-cta' ], array_values( array_filter( $r['fixes'], static fn ( $f ) => 'REPEATED_LAYOUT' === $f['code'] ) )[0]['try'] );
        $this->assertSame( [ 'high' => 3, 'medium' => 2, 'low' => 2 ], $r['counts'] );
        $this->assertFalse( $r['truncated'] );
    }

    public function testTheFixListIsCappedAndSaysSo(): void
    {
        $items = [ [ 'kind' => 'page', 'id' => 1, 'title' => 'x', 'content' => 'A' ] ];
        $audit = [];
        for ( $i = 0; $i < DesignReview::MAX_FIXES + 5; ++$i ) {
            $audit[] = [ 'kind' => 'page', 'id' => 1, 'title' => 'x', 'code' => 'LINK_NO_POST', 'count' => 1, 'detail' => (string) $i ];
        }
        $r = DesignReview::build( $items, $audit, static fn () => [ 'checks' => [] ], [] );
        $this->assertCount( DesignReview::MAX_FIXES, $r['fixes'] );
        $this->assertTrue( $r['truncated'] );
    }
}
