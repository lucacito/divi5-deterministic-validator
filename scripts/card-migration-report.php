<?php
/**
 * Dev-only: which blocks of the shipped section recipes are cards, panels or something else (card adapter, plan
 * 2026-10-19, Task 2). The later migration tasks put a card/panel marker only on the blocks this report calls
 * `card` or `panel`.
 *
 *   php scripts/card-migration-report.php [--all]        (make card-report)
 *
 * Reads wp-plugin/data/section-recipes.json raw (no plugin classes, no site, no network), walks the block comments of
 * every recipe and classifies every `divi/group` and every `divi/column` that carries a frame, radius or box shadow:
 *
 *   wrapper         no decoration beyond layout (layout, sizing, position, margin, disabledOn ...)
 *   padded-wrapper  padding only
 *   rule            no fill, a 0px frame and a border on one side only (a top rule: team, FAQ, timeline, price rows)
 *   grid-cell       a flat fill with no frame, radius or shadow inside a group filled with the `border` colour (the
 *                   hairline grid trick: logo wall, stat cells, comparison table), the `border`-filled outer box
 *                   itself, and a flat `border` line
 *   chip            a small mark: an accent fill with a radius (step circle, price badge), an accent fill with no
 *                   padding and no radius (a timeline dot), or an outlined tag (a frame and a radius, no fill)
 *   inverted        filled with the `text` colour, or an accent tile with padding and no radius (not a kit surface in v1)
 *   card | panel    filled with `surface` or `bg` and not a grid cell (a frame, a radius, a shadow, or just standing
 *                   alone). Several of them in one recipe (same block name) are `card`s, a single one is a `panel`;
 *                   PANEL_OVERRIDES names the one block where that rule would be wrong.
 *
 * `index` is the block's order among ALL `divi/group` (or all `divi/column`) blocks of the recipe, in document
 * order, not the spec's "#0/#1" naming of a recipe's cards: the migration tasks must follow this numbering.
 * Two fill-only surface columns (hero-cta column#0, split-image-text column#0: a `surface` fill and padding, no border,
 * radius or shadow) are deliberately NOT listed: they have no frame for a kit to restyle.
 *
 * `marked` is `card`, `panel`, `rule`, `rule-strong` or `chip` when the block already carries an
 * `"aied":{"kit":"card|panel|rule|rule-strong|chip"}` marker (read from the top-level attributes; any other marker, such as
 * the button roles, gives null). The marker replaces the decoration, so a marked block is still classified by whatever
 * decoration it has left; compare `marked` before and after a migration. A migrated top rule (a group or a column) is
 * listed as `MARKED=rule` / `MARKED=rule-strong`, a migrated outline tag as `MARKED=chip`.
 *
 * Anything that fits none of these throws, so a new recipe shape cannot slip through unclassified. An existing
 * `"aied"` marker (the buttons' kit marker) is just another attribute: the classifier looks at module.decoration only.
 */

declare(strict_types=1);

final class CardReport
{
    /** Recipe => [block name => [index => role]]: card-like blocks that are panels although their recipe holds several. */
    private const PANEL_OVERRIDES = [
        // The framed headline box next to three framed cells: one box, not one of the three tiles.
        'hero-bordered-cells' => [ 'divi/group' => [ 0 => 'panel' ] ],
    ];

    /** Recipe => [block name => [index => true]]: blocks the fill rules read as card-like that are small marks (chips). */
    private const CHIP_OVERRIDES = [
        // The "[Most popular]" badge on the accent-ground featured plan: a pill filled with `surface` (an accent pill would vanish
        // on the accent body), 12px corners and 6/12px padding. A surface fill with a radius reads as a card to the rules above.
        'pricing-three-tier' => [ 'divi/group' => [ 10 => true ] ],
    ];

    /** The kit markers the report lists as MARKED: the card roles, the rule roles and the chip role. */
    private const MARKERS = [ 'card', 'panel', 'rule', 'rule-strong', 'chip' ];

    private const FILL_FLAT   = [ 'bg', 'surface', 'inverse' ];
    private const BLOCK_REGEX = '/<!--\s+(\/?)wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+(\/?)-->/s';

    /**
     * @param list<array{name:string,markup:string}> $recipes the rows of section-recipes.json
     *
     * @return list<array{recipe:string,block:string,index:int,class:string,role:?string,marked:?string,bytes:int}>
     */
    public static function classify( array $recipes ): array
    {
        $rows = [];
        foreach ( $recipes as $recipe ) {
            $name = (string) $recipe['name'];
            $own  = [];
            foreach ( self::walk( $name, (string) $recipe['markup'] ) as $row ) {
                $own[] = $row;
            }
            foreach ( $own as &$row ) {
                if ( 'card-like' === $row['class'] && isset( self::CHIP_OVERRIDES[ $name ][ $row['block'] ][ $row['index'] ] ) ) {
                    $row['class'] = 'chip';
                }
            }
            unset( $row );
            // card or panel: several card-like blocks of one block name in a recipe are cards, a single one is a panel.
            $cardLike = [];
            foreach ( $own as $row ) {
                if ( 'card-like' === $row['class'] ) {
                    $cardLike[ $row['block'] ] = ( $cardLike[ $row['block'] ] ?? 0 ) + 1;
                }
            }
            foreach ( $own as $row ) {
                if ( 'card-like' === $row['class'] ) {
                    $role = self::PANEL_OVERRIDES[ $name ][ $row['block'] ][ $row['index'] ] ?? ( $cardLike[ $row['block'] ] > 1 ? 'card' : 'panel' );
                    $row['class'] = $role;
                    $row['role']  = $role;
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<array{recipe:string,block:string,index:int,class:string,role:?string,marked:?string,bytes:int}>
     */
    private static function walk( string $recipe, string $markup ): array
    {
        preg_match_all( self::BLOCK_REGEX, $markup, $found, PREG_SET_ORDER );
        $rows  = [];
        $stack = []; // open blocks: [name, fill role]
        $seen  = [];
        foreach ( $found as $b ) {
            $name = $b[2];
            if ( '/' === $b[1] ) {
                array_pop( $stack );
                continue;
            }
            $self         = '/' === ( $b[4] ?? '' );
            $seen[ $name ] = ( $seen[ $name ] ?? -1 ) + 1;
            $attrs        = null === ( $b[3] ?? null ) ? [] : json_decode( $b[3], true );
            if ( ! is_array( $attrs ) ) {
                throw new \RuntimeException( "$recipe: the attributes of a $name block are not valid JSON." );
            }
            $dec = $attrs['module']['decoration'] ?? [];
            $dec = is_array( $dec ) ? $dec : [];
            $f   = self::features( $dec );
            if ( 'divi/group' === $name || ( 'divi/column' === $name && ( isset( $dec['border'] ) || isset( $dec['boxShadow'] ) || null !== self::marker( $attrs ) ) ) ) {
                $inGrid = false;
                foreach ( $stack as $open ) {
                    if ( 'divi/group' === $open[0] && 'border' === $open[1] ) {
                        $inGrid = true;
                    }
                }
                $rows[] = [
                    'recipe' => $recipe,
                    'block'  => $name,
                    'index'  => $seen[ $name ],
                    'class'  => self::decide( "$recipe $name#{$seen[$name]}", $f, $inGrid ),
                    'role'   => null,
                    'marked' => self::marker( $attrs ),
                    'bytes'  => self::bytes( $dec ),
                ];
            }
            if ( ! $self ) {
                $stack[] = [ $name, $f['fill'] ];
            }
        }

        return $rows;
    }

    /**
     * What the classifier looks at, from module.decoration.
     *
     * @param array<string,mixed> $dec
     *
     * @return array{fill:string,frame:float,sides:bool,radius:float,shadow:bool,padding:bool}
     */
    private static function features( array $dec ): array
    {
        $bg   = $dec['background']['desktop']['value'] ?? [];
        $bg   = is_array( $bg ) ? $bg : [];
        $fill = 'none';
        if ( 'on' === ( $bg['gradient']['enabled'] ?? null ) ) {
            $fill = 'gradient';
        } elseif ( isset( $bg['color'] ) && is_string( $bg['color'] ) && '' !== $bg['color'] ) {
            $fill = 1 === preg_match( '/^\{\{aied:color:([a-z-]+)\}\}$/', $bg['color'], $m ) ? $m[1] : 'literal';
        } elseif ( isset( $bg['image'] ) ) {
            $fill = 'image';
        }
        $border = $dec['border']['desktop']['value'] ?? [];
        $border = is_array( $border ) ? $border : [];
        $styles = is_array( $border['styles'] ?? null ) ? $border['styles'] : [];
        $px     = static fn ( $v ): float => is_string( $v ) || is_numeric( $v ) ? (float) $v : 0.0;
        $sides  = false;
        foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
            if ( $px( $styles[ $side ]['width'] ?? 0 ) > 0 ) {
                $sides = true;
            }
        }
        $radius = 0.0;
        foreach ( [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ] as $corner ) {
            $radius = max( $radius, $px( $border['radius'][ $corner ] ?? 0 ) );
        }

        return [
            'fill'    => $fill,
            'frame'   => $px( $styles['all']['width'] ?? 0 ),
            'sides'   => $sides,
            'radius'  => $radius,
            'shadow'  => isset( $dec['boxShadow'] ),
            'padding' => isset( $dec['spacing']['desktop']['value']['padding'] ),
        ];
    }

    /**
     * @param array{fill:string,frame:float,sides:bool,radius:float,shadow:bool,padding:bool} $f
     */
    private static function decide( string $where, array $f, bool $inGrid ): string
    {
        $framed = $f['frame'] > 0;
        $flat   = ! $framed && ! $f['sides'] && 0.0 === $f['radius'] && ! $f['shadow'];
        switch ( $f['fill'] ) {
            case 'none':
                if ( $f['sides'] && ! $framed && 0.0 === $f['radius'] && ! $f['shadow'] ) {
                    return 'rule';
                }
                if ( $flat ) {
                    return $f['padding'] ? 'padded-wrapper' : 'wrapper';
                }
                if ( $framed && $f['radius'] > 0 && ! $f['shadow'] ) {
                    return 'chip'; // an outlined tag
                }
                break;
            case 'border':
                return 'grid-cell';
            case 'accent':
                if ( ! $framed && ! $f['sides'] && ! $f['shadow'] ) {
                    if ( $f['radius'] > 0 ) {
                        return 'chip';
                    }
                    return $f['padding'] ? 'inverted' : 'chip';
                }
                break;
            case 'text':
                return 'inverted';
            case 'surface':
            case 'bg':
            case 'inverse':
                if ( $flat && $inGrid ) {
                    return 'grid-cell';
                }
                if ( 'inverse' !== $f['fill'] ) {
                    return 'card-like';
                }
                break;
        }

        throw new \RuntimeException( "Cannot classify $where: fill {$f['fill']}, frame {$f['frame']}px" . ( $f['sides'] ? ' + a side border' : '' ) . ", radius {$f['radius']}px" . ( $f['shadow'] ? ', shadow' : '' ) . ( $f['padding'] ? ', padding' : '' ) . ( $inGrid ? ', inside a hairline grid' : '' ) . '.' );
    }

    /**
     * The marker a block already carries: `aied.kit` when it is `card`, `panel`, `rule`, `rule-strong` or `chip`, else null
     * (the button roles control / control-secondary are not reported here).
     *
     * @param array<string,mixed> $attrs
     */
    private static function marker( array $attrs ): ?string
    {
        $kit = is_array( $attrs['aied'] ?? null ) ? ( $attrs['aied']['kit'] ?? null ) : null;

        return in_array( $kit, self::MARKERS, true ) ? $kit : null;
    }

    /**
     * Bytes of the literals a card marker would own: the border, box shadow and spacing objects of the block.
     *
     * @param array<string,mixed> $dec
     */
    private static function bytes( array $dec ): int
    {
        $n = 0;
        foreach ( [ 'border', 'boxShadow', 'spacing' ] as $key ) {
            if ( isset( $dec[ $key ] ) ) {
                $n += strlen( '"' . $key . '":' . json_encode( $dec[ $key ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) + 1;
            }
        }

        return $n;
    }

    /**
     * The text report.
     *
     * @param list<array{recipe:string,block:string,index:int,class:string,role:?string,marked:?string,bytes:int}> $rows
     */
    public static function render( array $rows, bool $all ): string
    {
        $classes = [ 'wrapper', 'padded-wrapper', 'rule', 'grid-cell', 'chip', 'inverted', 'card', 'panel' ];
        $by      = [];
        $totals  = [];
        foreach ( $rows as $r ) {
            $by[ $r['recipe'] ][] = $r;
            $totals[ $r['block'] ][ $r['class'] ] = ( $totals[ $r['block'] ][ $r['class'] ] ?? 0 ) + 1;
        }
        $out = "Card classification of the shipped recipes (wp-plugin/data/section-recipes.json)\n\n";
        $out .= "Per recipe: the blocks that are not plain wrappers" . ( $all ? '' : ' (--all lists every one)' ) . "\n";
        foreach ( $by as $recipe => $list ) {
            $show = array_filter( $list, static fn ( array $r ): bool => $all || null !== $r['marked'] || ! in_array( $r['class'], [ 'wrapper', 'padded-wrapper' ], true ) );
            if ( [] === $show ) {
                continue;
            }
            $out .= "\n$recipe\n";
            foreach ( $show as $r ) {
                $out .= sprintf( "  %-12s #%-3d %-14s %s%s%s\n", $r['block'], $r['index'], $r['class'], null === $r['role'] ? '' : 'role=' . $r['role'] . ' ', null === $r['marked'] ? '' : 'MARKED=' . $r['marked'] . ' ', $r['bytes'] . ' B of border/shadow/spacing' );
            }
        }
        $out .= "\nTotals\n";
        foreach ( $totals as $block => $counts ) {
            $parts = [];
            foreach ( $classes as $c ) {
                if ( isset( $counts[ $c ] ) ) {
                    $parts[] = $c . ' ' . $counts[ $c ];
                }
            }
            $out .= sprintf( "  %s: %d blocks: %s\n", $block, array_sum( $counts ), implode( ', ', $parts ) );
        }
        $marked = array_filter( $rows, static fn ( array $r ): bool => null !== $r['role'] );
        $out .= sprintf( "  marked (carry an aied card/panel marker): %d blocks\n", count( array_filter( $rows, static fn ( array $r ): bool => in_array( $r['marked'], [ 'card', 'panel' ], true ) ) ) );
        $out .= sprintf( "  marked as a rule or a chip (rule, rule-strong, chip): %d blocks\n", count( array_filter( $rows, static fn ( array $r ): bool => in_array( $r['marked'], [ 'rule', 'rule-strong', 'chip' ], true ) ) ) );
        $out .= sprintf( "  card/panel literals a marker replaces: %d blocks, %s B\n", count( $marked ), number_format( array_sum( array_column( $marked, 'bytes' ) ) ) );

        return $out;
    }
}

if ( PHP_SAPI === 'cli' && isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
    $file    = dirname( __DIR__ ) . '/wp-plugin/data/section-recipes.json';
    $recipes = json_decode( (string) file_get_contents( $file ), true );
    if ( ! is_array( $recipes ) ) {
        fwrite( STDERR, "Could not read $file.\n" );
        exit( 1 );
    }
    try {
        echo CardReport::render( CardReport::classify( $recipes ), in_array( '--all', $argv ?? [], true ) );
    } catch ( \RuntimeException $e ) {
        fwrite( STDERR, $e->getMessage() . "\n" );
        exit( 1 );
    }
}
