<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/card-migration-report.php';

/**
 * scripts/card-migration-report.php classifies every divi/group (and every column that carries a frame, radius or shadow)
 * of the shipped recipes. Later migration tasks put a card/panel marker only on the blocks this report calls card or
 * panel, so the counts over the shipped file are pinned here: a recipe edit that changes them has to be a conscious one.
 */
class CardMigrationReportTest extends TestCase
{
    /** @param array<string,mixed> $attrs */
    private function block( string $name, array $attrs, string $inner = '' ): string
    {
        $json = json_encode( [] === $attrs ? new \stdClass() : $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        return '' === $inner
            ? "<!-- wp:divi/{$name} {$json} /-->"
            : "<!-- wp:divi/{$name} {$json} -->{$inner}<!-- /wp:divi/{$name} -->";
    }

    /** @param array<string,mixed> $decoration module.decoration members */
    private function group( array $decoration, string $inner = '' ): string
    {
        return $this->block( 'group', [ 'module' => [ 'decoration' => $decoration ] ], $inner );
    }

    /** @return array<string,mixed> */
    private function fill( string $role ): array
    {
        return [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:' . $role . '}}' ] ] ] ];
    }

    /** @return array<string,mixed> */
    private function frame( string $width, string $role = 'border' ): array
    {
        return [ 'border' => [ 'desktop' => [ 'value' => [ 'styles' => [ 'all' => [ 'width' => $width, 'color' => '{{aied:color:' . $role . '}}', 'style' => 'solid' ] ] ] ] ] ];
    }

    /** @return array<string,mixed> */
    private function radius( string $px ): array
    {
        return [ 'border' => [ 'desktop' => [ 'value' => [ 'radius' => [ 'topLeft' => $px, 'topRight' => $px, 'bottomRight' => $px, 'bottomLeft' => $px ] ] ] ] ];
    }

    /** @return array<string,mixed> */
    private function padding(): array
    {
        return [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => '20px', 'right' => '20px', 'bottom' => '20px', 'left' => '20px' ] ] ] ] ];
    }

    /** @return array<string,mixed> */
    private function shadow(): array
    {
        return [ 'boxShadow' => [ 'desktop' => [ 'value' => [ 'style' => 'preset1', 'color' => 'rgba(0,0,0,0.18)', 'blur' => '40px' ] ] ] ];
    }

    /** @param list<string> $groups */
    private function recipe( string $name, array $groups ): array
    {
        return [ 'name' => $name, 'markup' => $this->block( 'section', [], $this->block( 'row', [], $this->block( 'column', [], implode( '', $groups ) ) ) ) ];
    }

    /** @return array<string,array{class:string,role:?string}> keyed "block#index" */
    private function byKey( array $rows ): array
    {
        $out = [];
        foreach ( $rows as $r ) {
            $out[ $r['block'] . '#' . $r['index'] ] = [ 'class' => $r['class'], 'role' => $r['role'] ];
        }

        return $out;
    }

    public function test_a_planted_recipe_is_classified_by_the_rules(): void
    {
        $tile = fn () => $this->group( array_replace_recursive( $this->fill( 'surface' ), $this->frame( '1px' ), $this->radius( '16px' ), $this->padding() ) );
        $rule = $this->group( array_replace_recursive( $this->frame( '0px', 'text' ), [ 'border' => [ 'desktop' => [ 'value' => [ 'styles' => [ 'top' => [ 'width' => '2px' ] ] ] ] ] ], $this->padding() ) );
        $chip = $this->group( array_replace_recursive( $this->fill( 'accent' ), $this->radius( '999px' ) ) );
        $dark = $this->group( array_replace_recursive( $this->fill( 'text' ), $this->padding() ), $this->block( 'text', [] ) );
        $plain = $this->group( [ 'layout' => [ 'desktop' => [ 'value' => [ 'display' => 'flex' ] ] ] ] );
        $padded = $this->group( $this->padding() );

        $rows = \CardReport::classify( [
            $this->recipe( 'planted-one', [ $plain, $padded, $rule, $chip, $dark ] ),
            $this->recipe( 'planted-two', [ $tile(), $tile() ] ),
            $this->recipe( 'planted-three', [ $tile() ] ),
        ] );
        $k = $this->byKey( array_values( array_filter( $rows, fn ( $r ) => 'planted-one' === $r['recipe'] ) ) );
        $this->assertSame( [ 'class' => 'wrapper', 'role' => null ], $k['divi/group#0'] );
        $this->assertSame( [ 'class' => 'padded-wrapper', 'role' => null ], $k['divi/group#1'] );
        $this->assertSame( [ 'class' => 'rule', 'role' => null ], $k['divi/group#2'] );
        $this->assertSame( [ 'class' => 'chip', 'role' => null ], $k['divi/group#3'] );
        $this->assertSame( [ 'class' => 'inverted', 'role' => null ], $k['divi/group#4'] );

        $two = $this->byKey( array_values( array_filter( $rows, fn ( $r ) => 'planted-two' === $r['recipe'] ) ) );
        $this->assertSame( [ 'class' => 'card', 'role' => 'card' ], $two['divi/group#0'], 'a repeated surface tile with a frame and a radius is a card' );
        $this->assertSame( [ 'class' => 'card', 'role' => 'card' ], $two['divi/group#1'] );
        $three = $this->byKey( array_values( array_filter( $rows, fn ( $r ) => 'planted-three' === $r['recipe'] ) ) );
        $this->assertSame( [ 'class' => 'panel', 'role' => 'panel' ], $three['divi/group#0'], 'the only card-like block of a recipe is a panel' );
        foreach ( $rows as $r ) {
            $this->assertGreaterThanOrEqual( 0, $r['bytes'] );
        }
    }

    public function test_a_flat_cell_inside_a_hairline_grid_is_a_grid_cell_not_a_card(): void
    {
        $cell = $this->group( array_replace_recursive( $this->fill( 'bg' ), $this->padding() ) );
        $grid = $this->group( array_replace_recursive( $this->fill( 'border' ), $this->frame( '1px' ) ), $cell . $cell );
        $rows = \CardReport::classify( [ $this->recipe( 'planted-grid', [ $grid ] ) ] );
        $this->assertSame( [ 'grid-cell', 'grid-cell', 'grid-cell' ], array_column( $rows, 'class' ) );
    }

    public function test_a_column_card_is_found_by_its_frame_radius_or_shadow_and_a_plain_column_is_not_listed(): void
    {
        $col = fn ( array $dec ) => $this->block( 'column', [ 'module' => [ 'decoration' => $dec ] ] );
        $markup = $this->block( 'section', [], $this->block( 'row', [],
            $col( array_replace_recursive( $this->fill( 'surface' ), $this->radius( '15px' ), $this->shadow(), $this->padding() ) )
            . $col( array_replace_recursive( $this->fill( 'surface' ), $this->radius( '15px' ), $this->shadow(), $this->padding() ) )
            . $col( $this->padding() )
        ) );
        $rows = \CardReport::classify( [ [ 'name' => 'planted-cols', 'markup' => $markup ] ] );
        $this->assertSame( [ 'divi/column#0', 'divi/column#1' ], array_map( fn ( $r ) => $r['block'] . '#' . $r['index'], $rows ) );
        $this->assertSame( [ 'card', 'card' ], array_column( $rows, 'class' ) );
        $this->assertGreaterThan( 0, $rows[0]['bytes'], 'a card reports the bytes of the literals a marker would replace' );
    }

    public function test_an_existing_aied_marker_is_ignored(): void
    {
        $btn = $this->block( 'button', [ 'aied' => [ 'kit' => 'control', 'on' => 'light' ], 'button' => [ 'innerContent' => [ 'desktop' => [ 'value' => [ 'text' => 'Go' ] ] ] ] ] );
        $rows = \CardReport::classify( [ $this->recipe( 'planted-marker', [ $this->group( [], $btn ) ] ) ] );
        $this->assertSame( [ 'wrapper' ], array_column( $rows, 'class' ) );
    }

    public function test_a_card_or_panel_marker_is_reported_and_other_markers_are_not(): void
    {
        $markedGroup  = $this->block( 'group', [ 'aied' => [ 'kit' => 'card', 'on' => 'light' ], 'module' => [ 'decoration' => [ 'layout' => [ 'desktop' => [ 'value' => [ 'display' => 'flex' ] ] ] ] ] ] );
        $markedColumn = $this->block( 'column', [ 'aied' => [ 'kit' => 'panel', 'on' => 'surface' ] ] );
        $buttonRole   = $this->block( 'group', [ 'aied' => [ 'kit' => 'control', 'on' => 'light' ] ] );
        $markup       = $this->block( 'section', [], $this->block( 'row', [], $markedColumn . $this->block( 'column', [], $markedGroup . $buttonRole ) ) );
        $rows         = \CardReport::classify( [ [ 'name' => 'planted-marked', 'markup' => $markup ] ] );

        $this->assertSame(
            [ 'divi/column#0' => 'panel', 'divi/group#0' => 'card', 'divi/group#1' => null ],
            array_column( array_map( fn ( $r ) => [ $r['block'] . '#' . $r['index'], $r['marked'] ], $rows ), 1, 0 ),
            'a marked block is reported with its marker (a marked column is listed even without a frame); a button-role marker is null'
        );
        $this->assertSame( [ 'wrapper', 'wrapper', 'wrapper' ], array_column( $rows, 'class' ), 'a marked block is still classified by the decoration it has left, no class is invented' );
        $this->assertStringContainsString( 'marked (carry an aied card/panel marker): 2 blocks', \CardReport::render( $rows, false ) );
        $this->assertStringContainsString( 'MARKED=card', \CardReport::render( $rows, false ) );
    }

    public function test_an_unclassifiable_group_fails_loudly(): void
    {
        $this->expectException( \RuntimeException::class );
        \CardReport::classify( [ $this->recipe( 'planted-odd', [ $this->group( array_replace_recursive( $this->fill( 'muted' ), $this->padding() ) ) ] ) ] );
    }

    /** @return list<array{recipe:string,block:string,index:int,class:string,role:?string,bytes:int}> */
    private function shipped(): array
    {
        $recipes = json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        $this->assertIsArray( $recipes );

        return \CardReport::classify( $recipes );
    }

    /** @return array<string,int> */
    private function counts( array $rows, string $block ): array
    {
        $out = [];
        foreach ( $rows as $r ) {
            if ( $block === $r['block'] ) {
                $out[ $r['class'] ] = ( $out[ $r['class'] ] ?? 0 ) + 1;
            }
        }
        ksort( $out );

        return $out;
    }

    public function test_the_shipped_recipes_have_these_group_classes(): void
    {
        $groups = $this->counts( $this->shipped(), 'divi/group' );
        $this->assertSame( 359, array_sum( $groups ), 'divi/group blocks in the shipped recipes' );
        $this->assertSame(
            // Tasks 7 and 8 marked every group card (9, now plain wrappers: the kit owns their padding) and panel (10, padded
            // wrappers: a panel keeps its own padding); a marked block is classified by the decoration it has left. Kit-limits
            // Task 1 added 3 unmarked wrapper groups (the tilt of hero-gradient-stats' three stat cards): 356 -> 359 groups,
            // 221 -> 224 wrappers, nothing else changed. Kit-limits Task 5 marked the four grounds (the inverted panels of
            // compare-two-panels and pricing-two-plan-highlight keep their padding: padded wrappers; the two bento tiles
            // lose theirs: wrappers), so no inverted group is left.
            [ 'chip' => 14, 'grid-cell' => 40, 'padded-wrapper' => 53, 'rule' => 26, 'wrapper' => 226 ],
            $groups
        );
    }

    public function test_the_shipped_recipes_have_these_column_cards(): void
    {
        $rows = array_values( array_filter( $this->shipped(), fn ( $r ) => 'divi/column' === $r['block'] ) );
        $this->assertCount( 12, $rows, 'columns that carry a frame, radius or shadow' );
        $byRecipe = [];
        foreach ( $rows as $r ) {
            $byRecipe[ $r['recipe'] ][] = $r['class'];
        }
        ksort( $byRecipe );
        $this->assertSame(
            [
                'icon-values'              => [ 'wrapper', 'wrapper', 'wrapper' ], // marked (Task 9): classified by the decoration they have left
                'pricing-three-tier'       => [ 'wrapper', 'panel', 'wrapper' ], // #2 is the featured plan: its frame and shadow stay, its radius is the kit's token; it is `panel` because it is now the recipe's only card-like column left (the report calls several card-like blocks of one name `card` and a single one `panel`), see scripts/card-migration-report.php
                'proof-press-quotes'       => [ 'rule', 'rule', 'rule' ], // a 2px top rule, no fill: a divider, like the group rules
                'testimonials-avatar-grid' => [ 'wrapper', 'wrapper', 'wrapper' ],
            ],
            $byRecipe
        );
    }

    public function test_the_marked_blocks_are_counted_and_fill_only_columns_are_unlisted(): void
    {
        $rows = $this->shipped();
        $this->assertSame( 31, count( array_filter( $rows, fn ( $r ) => null !== $r['marked'] ) ), 'the blocks migrated so far (card plan Tasks 7, 8 and 9, kit-limits Task 5)' );
        $this->assertStringContainsString( 'marked (carry an aied card/panel marker): 31 blocks', \CardReport::render( $rows, false ) );
        $listed = array_map( fn ( $r ) => $r['recipe'] . ' ' . $r['block'] . '#' . $r['index'], $rows );
        $this->assertNotContains( 'hero-cta divi/column#0', $listed, 'a surface fill with padding and no frame: nothing for a kit to restyle' );
        $this->assertNotContains( 'split-image-text divi/column#0', $listed );
    }

    public function test_the_shipped_cards_panels_and_inverted_surfaces_are_these_blocks(): void
    {
        $got = [];
        foreach ( $this->shipped() as $r ) {
            if ( null !== $r['marked'] ) {
                $got[] = $r['recipe'] . ' ' . $r['block'] . '#' . $r['index'] . ' marked ' . $r['marked'];
            } elseif ( in_array( $r['class'], [ 'card', 'panel', 'inverted' ], true ) ) {
                $got[] = $r['recipe'] . ' ' . $r['block'] . '#' . $r['index'] . ' ' . $r['class'];
            }
        }
        sort( $got );
        $want = [
            'compare-two-panels divi/group#2 marked panel',
            'compare-two-panels divi/group#7 marked panel',
            'contact-details-form divi/group#13 marked panel',
            'contact-location-form divi/group#6 marked panel',
            'cta-card-over-image divi/group#1 marked panel',
            'cta-split-form divi/group#4 marked panel',
            'features-bento divi/group#0 marked card',
            'features-bento divi/group#1 marked card',
            'features-bento divi/group#2 marked card',
            'features-bento divi/group#3 marked card',
            'features-bento divi/group#4 marked card',
            'hero-bordered-cells divi/group#0 marked panel',
            'hero-bordered-cells divi/group#2 marked card',
            'hero-bordered-cells divi/group#3 marked card',
            'hero-bordered-cells divi/group#4 marked card',
            'hero-gradient-stats divi/group#2 marked card',
            'hero-gradient-stats divi/group#4 marked card',
            'hero-gradient-stats divi/group#6 marked card',
            'hero-spec-card divi/group#1 marked panel',
            'hero-split-left-overlap divi/group#0 marked panel',
            'icon-values divi/column#1 marked card',
            'icon-values divi/column#2 marked card',
            'icon-values divi/column#3 marked card',
            'pricing-three-tier divi/column#1 marked card',
            'pricing-three-tier divi/column#2 panel',
            'pricing-three-tier divi/column#3 marked card',
            'pricing-two-plan-highlight divi/group#1 marked panel',
            'pricing-two-plan-highlight divi/group#8 marked panel',
            'testimonials-avatar-grid divi/column#1 marked card',
            'testimonials-avatar-grid divi/column#2 marked card',
            'testimonials-avatar-grid divi/column#3 marked card',
            'transition-overlap-panel divi/group#2 marked panel',
        ];
        sort( $want );
        $this->assertSame( $want, $got );
    }

    public function test_pricing_badge_is_a_chip_not_a_card(): void
    {
        $rows = $this->byKey( array_values( array_filter( $this->shipped(), fn ( $r ) => 'pricing-three-tier' === $r['recipe'] ) ) );
        $this->assertSame( 'chip', $rows['divi/group#10']['class'] );
    }
}
