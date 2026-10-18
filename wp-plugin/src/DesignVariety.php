<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/CodePolicy.php';
require_once __DIR__ . '/SectionRecipes.php';

/**
 * Notices a page that looks repetitive: the same skeleton section after section, card grids everywhere, one flat background, no
 * focal point at the top, no depth. A layout can be perfectly valid and still be the same three-column card grid five times; the
 * validator cannot call that wrong, but the person asked for a good-looking page. This COUNTS structure (column structure, module
 * order, background kind, a few style keys): no inference, the same input always gives the same answer. It is an advisory only,
 * attached like `ContentLoss` and `StyleReferences`: it never changes a verdict, blocks a save or alters content.
 *
 * A section's fingerprint (see fingerprints()):
 *  - layout:     the column structures of its rows ("1_3,1_3,1_3"; a row without a columnStructure uses its columns' types, else
 *                one "col" per column), rows joined with " / ", then "|", then the module names (no "divi/") of the FIRST row's
 *                columns in order, consecutive repeats of one module collapsed ("text>image>text"). A section with no row uses
 *                the modules of its own columns, so nothing crashes on a column placed directly in a section.
 *  - cards:      some row has at least 3 columns, each holding at least one module and all holding the same module sequence.
 *  - background: the section's own `module.decoration.background.desktop.value`: gradient (gradient.enabled is "on"), else image
 *                (image.url non-empty), else solid (color non-empty), else none.
 *  - focal:      the FIRST section only matters, but it is computed for every section: a gradient or image background on the
 *                section, a row or a column in it, an image module, or a heading of at least 48px / 3em / 3rem / 4vw / 6vh
 *                (see headingIsLarge()).
 *  - depth:      some block has boxShadow, transform, position, animation or scroll under a `decoration` key (the module's or
 *                an element's).
 */
final class DesignVariety
{
    /** Pages with fewer sections are never judged (an edit of a short page is not a design). */
    public const MIN_SECTIONS = 3;

    /** The same layout fingerprint this many times on a page (not only next to each other) is a repeat. */
    public const REPEAT_LIMIT = 3;

    /** Card-grid sections must be MORE than this share of the sections to be flagged. */
    public const CARD_SHARE = 0.34;

    /** Depth and motion are only asked of pages with at least this many sections (a soft hint). */
    public const MOTION_MIN_SECTIONS = 5;

    /** A heading counts as a hero-sized focal element from these sizes. vh: 6vh is 48px on an 800px-tall screen. */
    private const FOCAL_PX  = 48.0;
    private const FOCAL_EM  = 3.0;
    private const FOCAL_VW  = 4.0;
    private const FOCAL_VH  = 6.0;

    private const DEPTH_KEYS = [ 'boxShadow', 'transform', 'position', 'animation', 'scroll' ];

    private const STRUCTURE = [ 'divi/placeholder', 'divi/section', 'divi/row', 'divi/column', 'divi/row-inner', 'divi/column-inner', 'divi/group', 'divi/global-layout', 'divi/global-slot' ];

    private const SCAN_DEPTH = 40;

    public const MESSAGE = "This page may look repetitive. It is valid and saved as is; this is only a hint. See findings: each names the sections and lists a few recipes (get_section_recipes with the name) built on a different layout. Vary the skeletons, backgrounds and the first screen, then read the page again.";

    /**
     * @return ?array{findings: list<array{code:string,sections:list<int>,message:string,try:list<string>}>, message: string}
     */
    public static function advise( string $content, ?string $previous = null ): ?array
    {
        $now = self::fingerprints( $content );
        if ( null === $now || count( $now ) < self::MIN_SECTIONS ) {
            return null;
        }
        if ( null !== $previous ) {
            $before = self::fingerprints( $previous );
            if ( null !== $before && count( $before ) === count( $now ) ) {
                $changed = 0;
                foreach ( $now as $i => $fp ) {
                    if ( $fp !== $before[ $i ] ) {
                        ++$changed;
                    }
                }
                if ( $changed < 2 ) {
                    return null;
                }
            }
        }

        $findings = [];
        $n        = count( $now );

        // REPEATED_LAYOUT: adjacent equal skeletons, or one skeleton REPEAT_LIMIT times or more anywhere on the page.
        $byLayout = [];
        foreach ( $now as $i => $fp ) {
            $byLayout[ $fp['layout'] ][] = $i;
        }
        $repeated = [];
        foreach ( $now as $i => $fp ) {
            if ( $i > 0 && $now[ $i - 1 ]['layout'] === $fp['layout'] ) {
                $repeated[ $i - 1 ] = true;
                $repeated[ $i ]     = true;
            }
        }
        foreach ( $byLayout as $indexes ) {
            if ( count( $indexes ) >= self::REPEAT_LIMIT ) {
                foreach ( $indexes as $i ) {
                    $repeated[ $i ] = true;
                }
            }
        }
        if ( $repeated ) {
            $sections   = array_keys( $repeated );
            sort( $sections );
            $findings[] = self::finding(
                'REPEATED_LAYOUT',
                $sections,
                sprintf( 'The skeleton of %s repeats (the same columns holding the same modules, next to each other or %d or more times on the page). A page built from one skeleton feels templated; give some of them a different layout.', self::name( $sections ), self::REPEAT_LIMIT ),
                $now,
                $sections
            );
        }

        // CARD_GRID_OVERUSE: more than CARD_SHARE of the sections are 3+ equal cards.
        $cards = [];
        foreach ( $now as $i => $fp ) {
            if ( $fp['cards'] ) {
                $cards[] = $i;
            }
        }
        if ( count( $cards ) / $n > self::CARD_SHARE ) {
            $findings[] = self::finding(
                'CARD_GRID_OVERUSE',
                $cards,
                sprintf( '%s of %d sections are grids of three or more identical cards (%s). Keep one or two card grids and present the rest differently (a split with an image, a band of numbers, a quote, a timeline).', count( $cards ), $n, self::name( $cards ) ),
                $now,
                $cards
            );
        }

        // FLAT_BACKGROUNDS: no gradient and no image in any section.
        $flat = true;
        foreach ( $now as $fp ) {
            if ( 'gradient' === $fp['background'] || 'image' === $fp['background'] ) {
                $flat = false;
                break;
            }
        }
        if ( $flat ) {
            $findings[] = self::finding(
                'FLAT_BACKGROUNDS',
                range( 0, $n - 1 ),
                'Every section has a plain or no background, so the page reads as one flat sheet. Give at least one section a gradient or an image, or alternate two clearly different colors.',
                $now,
                range( 0, $n - 1 )
            );
        }

        // NO_FOCAL_ELEMENT: the first screen has nothing to look at first.
        if ( ! $now[0]['focal'] ) {
            $findings[] = self::finding(
                'NO_FOCAL_ELEMENT',
                [ 0 ],
                'The first section (section 1) has no background image or gradient, no image and no large heading (48px, 3em or 4vw and up), so nothing on the first screen leads the eye. Open with a hero: a big headline over an image or a gradient.',
                $now,
                [ 0 ]
            );
        }

        // NO_MOTION_OR_DEPTH: a soft hint, only for longer pages.
        if ( $n >= self::MOTION_MIN_SECTIONS ) {
            $deep = false;
            foreach ( $now as $fp ) {
                $deep = $deep || $fp['depth'];
            }
            if ( ! $deep ) {
                $findings[] = self::finding(
                    'NO_MOTION_OR_DEPTH',
                    range( 0, $n - 1 ),
                    sprintf( 'Nothing on these %d sections has a shadow, a transform, a position, an animation or a scroll effect, so the page is completely flat. A soft shadow on cards or a gentle entrance animation adds depth.', $n ),
                    $now,
                    range( 0, $n - 1 )
                );
            }
        }

        return [] === $findings ? null : [ 'findings' => $findings, 'message' => self::MESSAGE ];
    }

    /**
     * One entry per `divi/section`, in page order. Null when the content cannot be walked safely (too large, too deep, not
     * parseable); never throws.
     *
     * @return ?list<array{layout:string,cards:bool,background:string,focal:bool,depth:bool}>
     */
    public static function fingerprints( string $content ): ?array
    {
        if ( CodePolicy::tooLarge( $content ) || CodePolicy::tooDeep( $content ) ) {
            return null;
        }
        $parsed = ( new BlockParser() )->parse( $content );
        if ( ! $parsed->isOk() ) {
            return null;
        }
        $sections = [];
        $parsed->root()->walk( static function ( Block $b ) use ( &$sections ): void {
            if ( 'divi/section' === $b->name() ) {
                $sections[] = $b;
            }
        } );

        return array_map( [ self::class, 'fingerprint' ], $sections );
    }

    /**
     * The recipe TAG layout (a value of SectionRecipes::LAYOUTS) a fingerprint corresponds to: a card grid is 'grid'; otherwise
     * the widest row decides: 1 column 'centered', 2 'split', 3 or more 'grid'; a section with no row is 'stacked'.
     *
     * @param array{layout:string,cards:bool,background:string,focal:bool,depth:bool} $fingerprint
     */
    public static function tagLayout( array $fingerprint ): string
    {
        if ( ! empty( $fingerprint['cards'] ) ) {
            return 'grid';
        }
        $structures = explode( '|', (string) ( $fingerprint['layout'] ?? '' ), 2 )[0];
        $widest     = 0;
        foreach ( explode( ' / ', $structures ) as $row ) {
            if ( '' !== $row ) {
                $widest = max( $widest, count( explode( ',', $row ) ) );
            }
        }

        return match ( true ) {
            0 === $widest => 'stacked',
            1 === $widest => 'centered',
            2 === $widest => 'split',
            default       => 'grid',
        };
    }

    /**
     * @param list<int>                                                                                    $sections 0-based
     * @param list<array{layout:string,cards:bool,background:string,focal:bool,depth:bool}>                $all
     * @param list<int>                                                                                    $avoid    indexes whose tag layouts the suggestions must not use
     * @return array{code:string,sections:list<int>,message:string,try:list<string>}
     */
    private static function finding( string $code, array $sections, string $message, array $all, array $avoid ): array
    {
        $layouts = [];
        foreach ( $avoid as $i ) {
            $layouts[ self::tagLayout( $all[ $i ] ) ] = true;
        }

        return [
            'code'     => $code,
            'sections' => array_values( $sections ),
            'message'  => $message,
            'try'      => SectionRecipes::suggest( array_keys( $layouts ), [], 3 ),
        ];
    }

    /** "section 2", "sections 1 and 3", "sections 1, 2 and 4" from 0-based indexes. */
    private static function name( array $sections ): string
    {
        $nums = array_map( static fn ( int $i ): string => (string) ( $i + 1 ), $sections );
        if ( 1 === count( $nums ) ) {
            return 'section ' . $nums[0];
        }
        $last = array_pop( $nums );

        return 'sections ' . implode( ', ', $nums ) . ' and ' . $last;
    }

    /** @return array{layout:string,cards:bool,background:string,focal:bool,depth:bool} */
    private static function fingerprint( Block $section ): array
    {
        $rows = [];
        foreach ( $section->children() as $child ) {
            if ( 'divi/row' === $child->name() ) {
                $rows[] = $child;
            }
        }

        $structures = [];
        $cards      = false;
        foreach ( $rows as $row ) {
            $columns = self::columnsOf( $row );
            $s       = self::structure( $row, $columns );
            if ( '' !== $s ) {
                $structures[] = $s;
            }
            if ( count( $columns ) >= 3 ) {
                $first = self::modules( $columns[0] );
                $same  = [] !== $first;
                foreach ( $columns as $c ) {
                    $same = $same && self::modules( $c ) === $first;
                }
                $cards = $cards || $same;
            }
        }

        if ( $rows ) {
            $sequence = [];
            foreach ( self::columnsOf( $rows[0] ) as $c ) {
                array_push( $sequence, ...self::modules( $c ) );
            }
        } else {
            $sequence = self::modules( $section );
        }

        return [
            'layout'     => implode( ' / ', $structures ) . '|' . implode( '>', self::collapse( $sequence ) ),
            'cards'      => $cards,
            'background' => self::backgroundKind( $section ),
            'focal'      => self::isFocal( $section ),
            'depth'      => self::hasDepth( $section ),
        ];
    }

    /** @return list<Block> */
    private static function columnsOf( Block $row ): array
    {
        $out = [];
        foreach ( $row->children() as $c ) {
            if ( 'divi/column' === $c->name() ) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /** @param list<Block> $columns */
    private static function structure( Block $row, array $columns ): string
    {
        $declared = $row->attr( 'module.advanced.columnStructure.desktop.value' );
        if ( is_string( $declared ) && '' !== trim( $declared ) ) {
            return trim( $declared );
        }
        $types = [];
        foreach ( $columns as $c ) {
            $t = $c->attr( 'module.advanced.type.desktop.value' );
            if ( ! is_string( $t ) || '' === $t ) {
                return implode( ',', array_fill( 0, count( $columns ), 'col' ) );
            }
            $types[] = $t;
        }

        return implode( ',', $types );
    }

    /**
     * The module names under a block, in document order (structure blocks are not modules).
     *
     * @return list<string>
     */
    private static function modules( Block $block ): array
    {
        $out = [];
        foreach ( $block->children() as $child ) {
            if ( ! in_array( $child->name(), self::STRUCTURE, true ) ) {
                $out[] = preg_replace( '#^divi/#', '', $child->name() );
            }
            array_push( $out, ...self::modules( $child ) );
        }

        return $out;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function collapse( array $names ): array
    {
        $out = [];
        foreach ( $names as $n ) {
            if ( [] === $out || end( $out ) !== $n ) {
                $out[] = $n;
            }
        }

        return $out;
    }

    private static function backgroundKind( Block $block ): string
    {
        $v = $block->attr( 'module.decoration.background.desktop.value' );
        if ( ! is_array( $v ) ) {
            return 'none';
        }

        return self::bgKind( $v );
    }

    /** @param array<mixed> $v */
    private static function bgKind( array $v ): string
    {
        if ( is_array( $v['gradient'] ?? null ) && 'on' === ( $v['gradient']['enabled'] ?? null ) ) {
            return 'gradient';
        }
        if ( is_array( $v['image'] ?? null ) && is_string( $v['image']['url'] ?? null ) && '' !== trim( $v['image']['url'] ) ) {
            return 'image';
        }
        if ( is_string( $v['color'] ?? null ) && '' !== trim( $v['color'] ) ) {
            return 'solid';
        }

        return 'none';
    }

    /** A hero-level element somewhere in the section (its own, a row's or a column's background count too). */
    private static function isFocal( Block $section ): bool
    {
        $focal = false;
        $section->walk( static function ( Block $b ) use ( &$focal ): void {
            if ( $focal ) {
                return;
            }
            if ( 'divi/image' === $b->name() ) {
                $focal = true;

                return;
            }
            $bg = $b->attr( 'module.decoration.background.desktop.value' );
            if ( is_array( $bg ) && in_array( self::bgKind( $bg ), [ 'gradient', 'image' ], true ) ) {
                $focal = true;

                return;
            }
            $sizes = [ $b->attr( 'title.decoration.font.font.desktop.value.size' ) ];
            $hf    = $b->attr( 'content.decoration.headingFont' );
            if ( is_array( $hf ) ) {
                foreach ( $hf as $heading ) {
                    $sizes[] = is_array( $heading ) && is_array( $heading['font'] ?? null ) ? ( $heading['font']['desktop']['value']['size'] ?? null ) : null;
                }
            }
            foreach ( $sizes as $size ) {
                if ( is_string( $size ) && self::headingIsLarge( $size ) ) {
                    $focal = true;

                    return;
                }
            }
        } );

        return $focal;
    }

    /**
     * 48px, 3em, 3rem, 4vw or 6vh and up. A bare number is px. clamp(min, preferred, max) is judged by its largest argument
     * (the max). Anything else (calc(), var(), words) is not guessed at and is not large.
     */
    private static function headingIsLarge( string $size ): bool
    {
        $size = strtolower( trim( $size ) );
        if ( 1 === preg_match( '/^clamp\(\s*[^,]+,\s*[^,]+,\s*([^,)]+)\)$/', $size, $m ) ) {
            $size = trim( $m[1] );
        }
        if ( 1 !== preg_match( '/^(\d*\.?\d+)\s*(px|em|rem|vw|vh)?$/', $size, $m ) ) {
            return false;
        }
        $n = (float) $m[1];

        return match ( $m[2] ?? '' ) {
            '', 'px'    => $n >= self::FOCAL_PX,
            'em', 'rem' => $n >= self::FOCAL_EM,
            'vw'        => $n >= self::FOCAL_VW,
            'vh'        => $n >= self::FOCAL_VH,
            default     => false,
        };
    }

    private static function hasDepth( Block $section ): bool
    {
        $depth = false;
        $section->walk( static function ( Block $b ) use ( &$depth ): void {
            $depth = $depth || self::scanDepth( $b->attrs(), 0 );
        } );

        return $depth;
    }

    /** @param array<mixed> $node */
    private static function scanDepth( array $node, int $level ): bool
    {
        if ( $level > self::SCAN_DEPTH ) {
            return false;
        }
        foreach ( $node as $key => $value ) {
            if ( ! is_array( $value ) ) {
                continue;
            }
            if ( 'decoration' === $key ) {
                foreach ( self::DEPTH_KEYS as $k ) {
                    if ( ! empty( $value[ $k ] ) ) {
                        return true;
                    }
                }
            }
            if ( self::scanDepth( $value, $level + 1 ) ) {
                return true;
            }
        }

        return false;
    }
}
