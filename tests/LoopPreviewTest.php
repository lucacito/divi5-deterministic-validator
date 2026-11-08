<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\LoopPreview;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/LoopPreview.php';

/**
 * The readers are stood in for here; `make verify-loop-builder` compares the counts and titles with what the real Divi renders.
 */
class LoopPreviewTest extends TestCase
{
    /** @return array<string,callable> */
    private function readers( array $over = [] ): array
    {
        return $over + [
            'post_type_exists' => static fn ( string $s ): bool => in_array( $s, [ 'post', 'page', 'product' ], true ),
            'taxonomy_exists'  => static fn ( string $s ): bool => in_array( $s, [ 'category', 'state' ], true ),
            'term_in'          => static fn ( int $id, string $t ): bool => 'category' === $t && in_array( $id, [ 1, 4 ], true ),
            'terms_count'      => static fn ( string $t ): int => 'category' === $t ? 3 : 0,
            'role_exists'      => static fn ( string $r ): bool => 'editor' === $r,
            'acf'              => static fn (): bool => false,
            'has_stickies'     => static fn (): bool => false,
            'menu'             => static fn ( int $id ): ?array => 35 === $id ? [ 'Home', 'Shop', 'About', 'Contact' ] : null,
            'posts'            => static fn ( array $a ): ?array => [ 'found' => 6, 'titles' => [ 'Newest', 'Second', 'Third', 'Fourth' ] ],
        ];
    }

    private function page( array $loop ): string
    {
        $attrs = [ 'module' => [ 'advanced' => [ 'loop' => [ 'desktop' => [ 'value' => $loop ] ] ] ] ];

        return '<!-- wp:divi/group ' . json_encode( $attrs ) . " -->\n<!-- /wp:divi/group -->";
    }

    private function row( array $loop, array $readers = [] ): array
    {
        $a = LoopPreview::advise( $this->page( $loop ), $this->readers( $readers ) );
        $this->assertNotNull( $a );

        return $a['loops'][0];
    }

    private function posts( array $over = [] ): array
    {
        return $over + [ 'enable' => 'on', 'loopId' => 'loop-a', 'queryType' => 'post_types', 'subTypes' => [ [ 'value' => 'post', 'label' => 'Posts' ] ], 'orderBy' => 'date', 'order' => 'descending', 'postPerPage' => '3', 'postOffset' => '0' ];
    }

    public function testPagesWithoutAnEnabledLoopAreLeftAlone(): void
    {
        $this->assertNull( LoopPreview::advise( '<!-- wp:divi/section {} /-->', $this->readers() ) );
        $this->assertNull( LoopPreview::advise( $this->page( $this->posts( [ 'enable' => 'off' ] ) ), $this->readers() ) );
        $this->assertNull( LoopPreview::advise( 'not blocks', $this->readers() ) );
    }

    public function testAPlainPostLoopReportsHowManyMatchAndTheFirstTitles(): void
    {
        $row = $this->row( $this->posts() );
        $this->assertSame( [], $row['problems'] );
        $this->assertSame( 6, $row['found'] );
        $this->assertSame( 3, $row['listed'] );
        $this->assertSame( [ 'Newest', 'Second', 'Third' ], $row['first'] );
        $this->assertSame( 'loop-a', $row['loop_id'] );
        // page size larger than what is left after the offset
        $row = $this->row( $this->posts( [ 'postPerPage' => '5', 'postOffset' => '4' ] ) );
        $this->assertSame( 2, $row['listed'] );
    }

    public function testAnUnknownPostTypeIsAProblemAndCountsNothing(): void
    {
        $row = $this->row( $this->posts( [ 'subTypes' => [ [ 'value' => 'manufacturer', 'label' => 'M' ] ] ] ) );
        $this->assertStringContainsString( '"manufacturer" does not exist', $row['problems'][0] );
        $this->assertStringContainsString( 'EVERY post type', $row['problems'][0] );
        $this->assertNull( $row['found'] );
    }

    public function testAnEmptyResultIsAProblem(): void
    {
        $row = $this->row( $this->posts(), [ 'posts' => static fn ( array $a ): array => [ 'found' => 0, 'titles' => [] ] ] );
        $this->assertSame( 0, $row['found'] );
        $this->assertStringContainsString( 'No Results Found', $row['problems'][0] );
    }

    public function testFiltersAreNotCountedButTheirTermIdsAreChecked(): void
    {
        $filter = [ [ 'categoryId' => 'category', 'categoryName' => 'C', 'selectedOptions' => [ [ 'value' => '4', 'label' => 'News' ], [ 'value' => '99', 'label' => 'Gone' ] ] ] ];
        $row    = $this->row( $this->posts( [ 'includePostWithSpecificTerms' => $filter ] ) );
        $this->assertNull( $row['found'] );
        $this->assertCount( 1, $row['problems'] );
        $this->assertStringContainsString( 'term id 99', $row['problems'][0] );
        $this->assertStringContainsString( 'ignores that filter', $row['problems'][0] );
        $this->assertStringContainsString( 'not counted', $row['note'] );
        $bad = $this->row( $this->posts( [ 'excludePostWithSpecificTerms' => [ [ 'categoryId' => 'nope', 'selectedOptions' => [] ] ] ] ) );
        $this->assertStringContainsString( 'taxonomy "nope"', $bad['problems'][0] );
        $this->assertNull( $this->row( $this->posts( [ 'search' => 'x' ] ) )['found'] );
        $this->assertNull( $this->row( $this->posts( [ 'excludeCurrentPost' => 'on' ] ) )['found'] );
    }

    public function testStickyPostsAndUnreproducedOrdersKeepTheCountButNotTheTitles(): void
    {
        $row = $this->row( $this->posts(), [ 'has_stickies' => static fn (): bool => true ] );
        $this->assertSame( 6, $row['found'] );
        $this->assertSame( [], $row['first'] );
        $this->assertStringContainsString( 'sticky', $row['note'] );
        $this->assertSame( [], $this->row( $this->posts( [ 'orderBy' => 'rand' ] ) )['first'] );
        $this->assertSame( [ 'Newest', 'Second', 'Third' ], $this->row( $this->posts( [ 'ignoreStickysPost' => 'on' ] ), [ 'has_stickies' => static fn (): bool => true ] )['first'] );
    }

    public function testMenuLoopsCheckTheIdAndFollowTheInvertedOrder(): void
    {
        $menu = static fn ( array $o = [] ): array => $o + [ 'enable' => 'on', 'queryType' => 'menus', 'subTypes' => [ [ 'value' => '35', 'label' => 'Main' ] ], 'orderBy' => 'menu_order', 'order' => 'descending', 'postPerPage' => '3', 'postOffset' => '0' ];
        $row  = $this->row( $menu() );
        $this->assertSame( [ 'Home', 'Shop', 'About' ], $row['first'] );
        $this->assertSame( [ 4, 3 ], [ $row['found'], $row['listed'] ] );
        $this->assertSame( [ 'Contact', 'About', 'Shop' ], $this->row( $menu( [ 'order' => 'ascending' ] ) )['first'] );
        $this->assertStringContainsString( 'id "99"', $this->row( $menu( [ 'subTypes' => [ [ 'value' => '99', 'label' => 'x' ] ] ] ) )['problems'][0] );
        $this->assertStringContainsString( 'No menu is named', $this->row( $menu( [ 'subTypes' => [] ] ) )['problems'][0] );
    }

    public function testTermsRolesRepeatersAndTheCurrentPage(): void
    {
        $terms = $this->row( [ 'enable' => 'on', 'queryType' => 'post_taxonomies', 'subTypes' => [ [ 'value' => 'category', 'label' => 'C' ] ], 'postPerPage' => '2' ] );
        $this->assertSame( [ 3, 2 ], [ $terms['found'], $terms['listed'] ] );
        $this->assertStringContainsString( 'does not exist', $this->row( [ 'enable' => 'on', 'queryType' => 'terms', 'subTypes' => [ [ 'value' => 'genre', 'label' => 'G' ] ] ] )['problems'][0] );
        $this->assertSame( [], $this->row( [ 'enable' => 'on', 'queryType' => 'user_roles', 'subTypes' => [ [ 'value' => 'editor', 'label' => 'E' ] ] ] )['problems'] );
        $this->assertStringContainsString( 'role "ghost"', $this->row( [ 'enable' => 'on', 'queryType' => 'user_roles', 'subTypes' => [ [ 'value' => 'ghost', 'label' => 'G' ] ] ] )['problems'][0] );
        $this->assertStringContainsString( 'not active', $this->row( [ 'enable' => 'on', 'queryType' => 'repeater_team' ] )['problems'][0] );
        $this->assertSame( [], $this->row( [ 'enable' => 'on', 'queryType' => 'current_page' ] )['problems'] );
    }

    public function testTheListIsCapped(): void
    {
        $inner = str_repeat( $this->page( $this->posts() ), LoopPreview::MAX_ROWS + 5 );
        $a     = LoopPreview::advise( $inner, $this->readers() );
        $this->assertCount( LoopPreview::MAX_ROWS, $a['loops'] );
    }
}
