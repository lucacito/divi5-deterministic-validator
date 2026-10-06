<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Tools\AuditSite;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';
require_once __DIR__ . '/WpSiteShims.php';

class AuditSiteTest extends TestCase
{
    use ThemeLayoutFixture;

    private AuditSite $tool;

    /** A valid layout with one button linking to $url (empty: no button). */
    private static function page( string $url = '' ): string
    {
        $button = '' === $url ? '' : '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go","linkUrl":"' . $url . '"}}}},"builderVersion":"5.14.0"} /-->';

        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} -->' . $button . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $this->tool = new AuditSite();
        $GLOBALS['__wp_caps']['edit_posts'] = true;
        $GLOBALS['__wp_caps']['read_post']  = true;
        $GLOBALS['__wp_posts'][5]->post_content = '';
        // link targets (plain objects: the WP_Post shim has no post_name property)
        $GLOBALS['__wp_posts'][90] = (object) [ 'ID' => 90, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_content' => '', 'post_name' => 'about' ];
        $GLOBALS['__wp_posts'][91] = (object) [ 'ID' => 91, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Draft', 'post_content' => '', 'post_name' => 'draft-page' ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_home_url'] = 'https://example.com';
        $this->tearDownThemeBuilder();
    }

    private function divi( int $id, string $content, string $status = 'publish' ): void
    {
        $this->themePost( $id, 'page', $status, [ '_et_pb_use_divi_5' => 'on' ], $content );
    }

    /** @param array<string,mixed> $r @return array<string,list<array<string,mixed>>> findings of one item by code */
    private static function byItem( array $r, int $id ): array
    {
        $out = [];
        foreach ( $r['findings'] as $f ) {
            if ( $f['id'] === $id ) {
                $out[ $f['code'] ][] = $f;
            }
        }

        return $out;
    }

    public function testACleanSiteHasNoFindings(): void
    {
        $this->divi( 11, self::page() );
        $this->divi( 12, self::page( '/about/' ) );
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $this->assertSame( 2, $r['total_items'] );
        $this->assertSame( 2, $r['scanned'] );
        $this->assertSame( [], $r['findings'] );
        $this->assertSame( [], $r['totals_by_code'] );
        $this->assertSame( 0, $r['items_with_findings'] );
        $this->assertNull( $r['next_cursor'] );
        $this->assertFalse( $r['findings_truncated'] );
    }

    public function testLayoutCheckerViolationsAreReportedWithTheirCodes(): void
    {
        $this->divi( 11, str_replace( 'wp:divi/button', 'wp:divi/not-a-module', self::page( '/about/' ) ) );
        $this->divi( 12, str_replace( 'wp:divi/button {', 'wp:divi/button {"css":{"desktop":{"value":{"freeForm":"selector{color:red}"}}},', self::page( '/about/' ) ) );
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $a = self::byItem( $r, 11 );
        $this->assertNotEmpty( $a, 'the unknown module is a finding' );
        $this->assertStringContainsString( 'not-a-module', json_encode( $a ) );
        $b = self::byItem( $r, 12 );
        $this->assertArrayHasKey( 'CUSTOM_CSS_NOT_ALLOWED', $b, 'custom code already stored is reported' );
        $this->assertGreaterThan( 0, $r['totals_by_code']['CUSTOM_CSS_NOT_ALLOWED'] );
    }

    public function testUnknownPresetAndColorIdsComeFromTheFreePluginsAdvisory(): void
    {
        $advisor = static fn ( string $content ): ?array => str_contains( $content, 'MARK' ) ? [
            'unknown_presets' => [ [ 'id' => 'preset-x', 'count' => 2 ], [ 'id' => 'preset-y', 'count' => 1 ] ],
            'unknown_colors'  => [ [ 'id' => 'gcid-abc', 'count' => 1 ] ],
        ] : null;
        $tool = new AuditSite( $advisor );
        $this->divi( 11, self::page( '/about/' ) . '<!-- MARK -->' );
        $this->divi( 12, self::page() );
        $r = $tool->run( [ 'scope' => 'pages' ] );
        $a = self::byItem( $r, 11 );
        $this->assertSame( 2, $a['UNKNOWN_PRESET_ID'][0]['count'] );
        $this->assertSame( 'preset-x, preset-y', $a['UNKNOWN_PRESET_ID'][0]['detail'] );
        $this->assertSame( 'gcid-abc', $a['UNKNOWN_COLOR_ID'][0]['detail'] );
        $this->assertSame( [], self::byItem( $r, 12 ) );
    }

    public function testUnknownVariableIdsComeFromTheFreePluginsAdvisory(): void
    {
        $advisor = static fn ( string $content ): ?array => str_contains( $content, 'MARK' ) ? [
            'unknown_variables' => [ [ 'id' => 'gvid-aaa', 'count' => 3 ], [ 'id' => 'gvid-bbb', 'count' => 1 ] ],
        ] : null;
        $tool = new AuditSite( $advisor );
        $this->divi( 11, self::page( '/about/' ) . '<!-- MARK -->' );
        $this->divi( 12, self::page() );
        $r = $tool->run( [ 'scope' => 'pages' ] );
        $a = self::byItem( $r, 11 );
        $this->assertSame( 2, $a['UNKNOWN_VARIABLE_ID'][0]['count'] );
        $this->assertSame( 'gvid-aaa, gvid-bbb', $a['UNKNOWN_VARIABLE_ID'][0]['detail'] );
        $this->assertSame( [], self::byItem( $r, 12 ) );
        $this->assertSame( 1, $r['totals_by_code']['UNKNOWN_VARIABLE_ID'] );
    }

    /** A page whose only button text cites $id (a string attribute the free advisory reads). */
    private static function pageCiting( string $id ): string
    {
        return str_replace( '"text":"Go"', '"text":"' . $id . '"', self::page( '/about/' ) );
    }

    public function testACitedVariableTheSiteDoesNotHaveIsFlaggedThroughTheRealAdvisory(): void
    {
        $known = static fn (): array => [ 'presets' => [], 'colors' => [], 'variables' => [ 'gvid-have' => true ] ];
        $tool  = new AuditSite( null, AuditSite::MAX_FINDINGS, $known );
        $this->divi( 11, self::pageCiting( 'gvid-gone' ) );
        $this->divi( 12, self::pageCiting( 'gvid-have' ) );
        $r = $tool->run( [ 'scope' => 'pages' ] );
        $this->assertSame( 'gvid-gone', self::byItem( $r, 11 )['UNKNOWN_VARIABLE_ID'][0]['detail'] );
        $this->assertArrayNotHasKey( 'UNKNOWN_VARIABLE_ID', self::byItem( $r, 12 ), 'a variable the site has is not a finding' );
    }

    public function testNothingIsReportedWhenTheSitesVariablesCannotBeRead(): void
    {
        $known = static fn (): array => [ 'presets' => [], 'colors' => [], 'variables' => null ];
        $tool  = new AuditSite( null, AuditSite::MAX_FINDINGS, $known );
        $this->divi( 11, self::pageCiting( 'gvid-gone' ) );
        $r = $tool->run( [ 'scope' => 'pages' ] );
        $this->assertArrayNotHasKey( 'UNKNOWN_VARIABLE_ID', self::byItem( $r, 11 ) );
    }

    public function testInternalLinksToNothingAndToUnpublishedPagesAreFound(): void
    {
        $content = self::page( '/missing/' ) . self::page( '/draft-page/' ) . self::page( 'https://example.com/about/' ) . self::page( 'https://other.com/x/' ) . self::page( '#top' ) . self::page( 'mailto:a@b.co' ) . self::page( '/wp-content/uploads/a.pdf' ) . self::page( '/' );
        $this->divi( 11, $content );
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $a = self::byItem( $r, 11 );
        $this->assertSame( 1, $a['LINK_NO_POST'][0]['count'] );
        $this->assertStringContainsString( '/missing/', $a['LINK_NO_POST'][0]['detail'] );
        $this->assertSame( 1, $a['LINK_NOT_PUBLISHED'][0]['count'] );
        $this->assertStringContainsString( '/draft-page/ (draft)', $a['LINK_NOT_PUBLISHED'][0]['detail'] );
        $codes = array_keys( $a );
        sort( $codes );
        $this->assertSame( [ 'LINK_NOT_PUBLISHED', 'LINK_NO_POST' ], $codes );
    }

    public function testASubdirectoryInstallResolvesLinksRelativeToTheHostRoot(): void
    {
        $GLOBALS['__wp_home_url'] = 'https://example.com/blog';
        $this->divi( 11, self::page( '/blog/about/' ) );
        $this->divi( 12, self::page( '/blog/missing/' ) );
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $this->assertSame( [], self::byItem( $r, 11 ) );
        $b = self::byItem( $r, 12 );
        $this->assertSame( 1, $b['LINK_NO_POST'][0]['count'] );
        $this->assertStringContainsString( '/blog/missing/', $b['LINK_NO_POST'][0]['detail'] );
    }

    public function testItIsPaginatedTwentyFivePerCallWithTheLastAuditedIdAsTheCursor(): void
    {
        for ( $i = 100; $i < 160; $i++ ) {
            $this->divi( $i, self::page() );
        }
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $this->assertSame( [ 60, 25, 124 ], [ $r['total_items'], $r['scanned'], $r['next_cursor'] ] );
        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 124 ] );
        $this->assertSame( [ 25, 149 ], [ $r['scanned'], $r['next_cursor'] ] );
        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 149 ] );
        $this->assertSame( [ 10, null ], [ $r['scanned'], $r['next_cursor'] ] );
        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 500 ] );
        $this->assertSame( [ 0, null ], [ $r['scanned'], $r['next_cursor'] ] );
    }

    public function testPagesCreatedOrDeletedBetweenCallsCannotShiftTheWindow(): void
    {
        for ( $i = 100; $i < 160; $i++ ) {
            $this->divi( $i, self::page() );
        }
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $this->assertSame( 124, $r['next_cursor'] );
        // Between the calls a page with a LOWER id appears and an already audited page is deleted: an index offset
        // would now skip or repeat pages, the id cursor continues exactly after page 124.
        $this->divi( 50, self::page( '/gone/' ) );
        unset( $GLOBALS['__wp_posts'][100] );
        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 124 ] );
        $this->assertSame( [ 25, 149 ], [ $r['scanned'], $r['next_cursor'] ] );
        $this->assertSame( [], $r['findings'] );
        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 149 ] );
        $this->assertSame( [ 10, null ], [ $r['scanned'], $r['next_cursor'] ] );
    }

    public function testPagesAndLayoutsShareOneIdOrder(): void
    {
        $GLOBALS['__wp_posts'][201]->post_content = str_replace( 'wp:divi/shop', 'wp:divi/not-a-module', self::header() );
        $this->divi( 11, self::page() );
        for ( $i = 301; $i <= 330; $i++ ) {
            $this->divi( $i, self::page() );
        }
        $r = $this->tool->run( [] );
        $this->assertSame( [ 33, 25, 322 ], [ $r['total_items'], $r['scanned'], $r['next_cursor'] ], 'ids 11, 201, 202 then 301..322' );
        $this->assertNotEmpty( self::byItem( $r, 201 ), 'the layout with an id below the pages is in the first window' );
        $r = $this->tool->run( [ 'cursor' => 322 ] );
        $this->assertSame( [ 8, null ], [ $r['scanned'], $r['next_cursor'] ] );
    }

    public function testLinkLookupsAreMemoisedWithinOneCallButNotAcrossCalls(): void
    {
        $this->divi( 11, self::page( '/about/' ) . self::page( '/missing/' ) );
        $this->divi( 12, self::page( '/about/' ) . self::page( '/missing/' ) );
        $this->divi( 13, self::page( '/about/' ) );
        $GLOBALS['__url_to_postid_calls'] = 0;
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $this->assertSame( 2, $GLOBALS['__url_to_postid_calls'], 'one lookup per distinct link, not per item' );
        $this->assertSame( 1, self::byItem( $r, 11 )['LINK_NO_POST'][0]['count'] );
        $this->assertSame( 1, self::byItem( $r, 12 )['LINK_NO_POST'][0]['count'] );
        $this->assertSame( [], self::byItem( $r, 13 ) );
        $this->tool->run( [ 'scope' => 'pages' ] );
        $this->assertSame( 4, $GLOBALS['__url_to_postid_calls'], 'a second call looks again: the site may have changed' );
    }

    public function testTheSitesKnownStyleIdsAreReadOncePerCall(): void
    {
        $reads = 0;
        $known = static function () use ( &$reads ): array {
            $reads++;

            return [ 'presets' => [ 'preset-ok' => true ], 'colors' => [ 'gcid-ok' => true ] ];
        };
        $tool    = new AuditSite( null, AuditSite::MAX_FINDINGS, $known );
        $content = str_replace( 'wp:divi/button {', 'wp:divi/button {"modulePreset":["preset-x"],', self::page( '/about/' ) );
        foreach ( [ 11, 12, 13 ] as $id ) {
            $this->divi( $id, $content );
        }
        $r = $tool->run( [ 'scope' => 'pages' ] );
        $this->assertSame( 1, $reads, 'one read of the site\'s presets and colors for all three items' );
        foreach ( [ 11, 12, 13 ] as $id ) {
            $this->assertSame( 'preset-x', self::byItem( $r, $id )['UNKNOWN_PRESET_ID'][0]['detail'] );
        }
        $tool->run( [ 'scope' => 'pages' ] );
        $this->assertSame( 2, $reads );
    }

    public function testTheStatusOfALinkedPostTheUserCannotReadIsNotRevealed(): void
    {
        $this->divi( 11, self::page( '/draft-page/' ) );
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $this->assertStringContainsString( '/draft-page/ (draft)', self::byItem( $r, 11 )['LINK_NOT_PUBLISHED'][0]['detail'] );
        $GLOBALS['__wp_caps']['read_post:91'] = false;
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $a = self::byItem( $r, 11 );
        $this->assertSame( 1, $a['LINK_NOT_PUBLISHED'][0]['count'], 'it is still a link that does not work for visitors' );
        $this->assertStringContainsString( '/draft-page/ (not visible to you)', $a['LINK_NOT_PUBLISHED'][0]['detail'] );
        $this->assertStringNotContainsString( '(draft)', $a['LINK_NOT_PUBLISHED'][0]['detail'] );
    }

    public function testFindingsAreCappedButTheTotalsStayComplete(): void
    {
        $tool = new AuditSite( null, 3 );
        for ( $i = 100; $i < 105; $i++ ) {
            $this->divi( $i, self::page( "/gone-{$i}/" ) );
        }
        $r = $tool->run( [ 'scope' => 'pages' ] );
        $this->assertCount( 3, $r['findings'] );
        $this->assertTrue( $r['findings_truncated'] );
        $this->assertSame( 5, $r['totals_by_code']['LINK_NO_POST'] );
        $this->assertSame( 5, $r['items_with_findings'] );
    }

    public function testItNeverChangesAnything(): void
    {
        $this->divi( 11, self::page( '/missing/' ) );
        $this->divi( 12, str_replace( 'wp:divi/button', 'wp:divi/not-a-module', self::page() ) );
        $before = [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'], $GLOBALS['__wp_options'] ];
        $this->tool->run( [] );
        $this->tool->run( [ 'scope' => 'layouts' ] );
        $this->assertEquals( $before, [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'], $GLOBALS['__wp_options'] ] );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testItContainsNoWriteOrRemoteCall(): void
    {
        $source = (string) file_get_contents( __DIR__ . '/../src/Tools/AuditSite.php' );
        $this->assertDoesNotMatchRegularExpression( '/wp_update_post|wp_insert_post|update_post_meta|add_post_meta|delete_post_meta|update_option|HistoryService|wp_remote_|curl_|file_get_contents\s*\(/', $source );
    }

    public function testOnlyDivi5PagesTheUserCanEditAndNotTrashedAreAudited(): void
    {
        $this->divi( 11, self::page() );
        $this->divi( 12, self::page(), 'draft' );
        $this->divi( 13, self::page(), 'future' );
        $this->divi( 14, self::page(), 'trash' );
        $this->themePost( 15, 'page', 'publish', [], self::page() );
        $this->divi( 16, self::page() );
        $GLOBALS['__wp_caps']['edit_post:16'] = false;
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $this->assertSame( 3, $r['total_items'], 'publish, draft and future; not trash, not classic, not a page the user cannot edit' );
    }

    public function testLayoutsNeedTheThemeBuilderPermission(): void
    {
        $GLOBALS['__wp_posts'][201]->post_content = str_replace( 'wp:divi/shop', 'wp:divi/not-a-module', self::header() );
        $r = $this->tool->run( [ 'scope' => 'layouts' ] );
        $this->assertSame( 2, $r['total_items'] );
        $this->assertNotEmpty( self::byItem( $r, 201 ) );
        $this->assertSame( [], self::byItem( $r, 202 ) );

        $this->divi( 11, self::page() );
        $GLOBALS['__divi_role_allowed'] = false;
        $r = $this->tool->run( [] );
        $this->assertSame( 1, $r['total_items'], 'layouts are left out without Divi\'s bar' );
        $this->assertStringContainsString( 'skipped', $r['layouts_note'] );
        unset( $GLOBALS['__divi_role_allowed'] );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true ];
        $r = $this->tool->run( [] );
        $this->assertSame( 1, $r['total_items'] );
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'permission' );
        $this->tool->run( [ 'scope' => 'layouts' ] );
    }

    public function testLayoutsAreRefusedWithoutDivisThemeBuilderPermission(): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        try {
            try {
                $this->tool->run( [ 'scope' => 'layouts' ] );
                $this->fail( 'scope layouts must be refused' );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( 'permission', $e->getMessage() );
            }
            $this->divi( 11, self::page() );
            $r = $this->tool->run( [ 'scope' => 'all' ] );
            $this->assertSame( 1, $r['total_items'] );
            $this->assertStringContainsString( 'skipped', $r['layouts_note'] );
        } finally {
            unset( $GLOBALS['__divi_role_allowed'] );
        }
    }

    public function testAnItemWithMoreLinksThanTheScannerReadsSaysSo(): void
    {
        $many = '';
        for ( $i = 0; $i < 250; $i++ ) {
            $many .= self::page( "/about/?n={$i}" );
        }
        $this->divi( 11, $many );
        $this->divi( 12, self::page( '/about/' ) . self::page( '/about/?x=1' ) );
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $a = self::byItem( $r, 11 );
        $this->assertCount( 1, $a['LINKS_NOT_ALL_CHECKED'] );
        $this->assertSame( \AiEditorDivi5\Pro\Site\LinkScanner::MAX_LINKS, $a['LINKS_NOT_ALL_CHECKED'][0]['count'] );
        $this->assertSame( 'Only the first ' . \AiEditorDivi5\Pro\Site\LinkScanner::MAX_LINKS . ' links of this item were checked.', $a['LINKS_NOT_ALL_CHECKED'][0]['detail'] );
        $this->assertArrayNotHasKey( 'LINKS_NOT_ALL_CHECKED', self::byItem( $r, 12 ) );
        $this->assertSame( 1, $r['totals_by_code']['LINKS_NOT_ALL_CHECKED'] );
    }

    public function testBadArgumentsAreRejectedNotCast(): void
    {
        foreach ( [
            [ [ 'scope' => 'everything' ], '"scope" must be one of: pages, layouts, all.' ],
            [ [ 'scope' => 5 ], '"scope" must be a string.' ],
            [ [ 'cursor' => '25' ], '"cursor" must be an integer.' ],
            [ [ 'cursor' => -1 ], '"cursor" must be at least 0.' ],
            [ [ 'cursor' => 1.5 ], '"cursor" must be an integer.' ],
        ] as [ $args, $message ] ) {
            try {
                $this->tool->run( $args );
                $this->fail( $message );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertSame( $message, $e->getMessage() );
            }
        }
    }

    public function testItIsRegisteredAndNeedsEditPages(): void
    {
        $this->assertContains( 'audit_site', array_map( static fn ( $t ) => $t->name(), \AiEditorDivi5\Pro\Tools::all() ) );
        $this->assertSame( 'edit_pages', $this->tool->capability() );
        $d = $this->tool->definition();
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [], $d['inputSchema']['required'] );
        $this->assertStringContainsString( 'id of the last item', $d['inputSchema']['properties']['cursor']['description'] );
    }
}
