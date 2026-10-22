<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * html_to_divi (styles=basic), step two of the decision layer: decides every colour of the candidate blocks HtmlStyleBlocks::build()
 * returns, under one rule: A CONVERTED PAGE IS NEVER LESS READABLE THAN THE SOURCE. Pure and deterministic (no clock, no randomness, no
 * locale: ratios are formatted with %F). The contrast is the plugin's own WCAG 2.x formula, DesignLanguages::contrast().
 *
 * PAINT AND INK TRAVEL TOGETHER (plan D1). The theme's colours are unknown, so a carried text colour is printed only on a background the
 * converter writes itself, and every heading and text on such a background gets a colour the converter chose (its declared one, or the CSS
 * initial #000000 when nothing is declared up to html): the result never depends on the theme.
 *
 * THE CANVAS (plan D2). A section whose paint is none (no background on its anchor chain) but whose headings or texts declare a colour is
 * painted with HtmlStyleBlocks::CANVAS (#ffffff): the canvas a browser paints such a page on. The assumption is the plain white canvas of
 * the source; a page that may be shown dark (color-scheme) has its colours unresolved upstream, so it never reaches this rule with a claim.
 *
 * THE DECISION, per section, every pass judging all modules against the same kept set (a simultaneous update: the result does not depend
 * on the order of the modules), until a pass drops nothing:
 * 1. A level (column, row, section) is kept-painted while its paint is `set`, its background is proven on that block
 *    (DiviMarkup::STYLE_PROVEN row-background, column-background; sections always) and it was not dropped. The canvas is decided once.
 * 2. A heading or text module: L = the innermost kept-painted level containing it. No L: no colour (a `set` ink is listed:
 *    unknown_background when a containing level's paint is unknown, else paint_dropped). With L it FAILS when an uncarried background lies
 *    between it and L, or a level inside L has a paint that is not none, or its ink is unresolved or mixed, or it holds content the writer
 *    does not ink (a link, mark, pre, code, kbd, table, dl, or a list or quote whose ink decision is not proven), or the contrast of its ink
 *    on paint(L) is below NORMAL (LARGE for large text, HtmlStyleBlocks::isLarge).
 * 3. A button: a PAIR when its own fill and its own label are `set` and the label on the fill is at least NORMAL: fill and label are
 *    written. Else, with an L, a GHOST (fill DiviMarkup::TRANSPARENT, label = its parent's ink, #000000 for the initial one; but its OWN
 *    label when it has one and no fill of its own, since the source already draws that label on the backdrop) that must pass the checks
 *    of rule 2 against paint(L), or the button FAILS. Without L: no colours. The radius is written in every case.
 * 4. Every failing module drops the paint of every kept-painted level containing it (also the canvas, which is never re-made).
 * 5. After the fixpoint: the paints of the kept-painted levels, the inks, the pairs and the ghosts are written. Padding, max width, size,
 *    weight, alignment, family and radius do not change readability and are written as given.
 * Termination: each pass that changes anything drops at least one paint, so there are at most (number of paints + 1) passes.
 *
 * Every value handed to DiviMarkup is already in the writer's closed forms (#rrggbb, ints within the clamps, '<n>px' strings for padding
 * and max width, the nine weights, HtmlFonts' family names).
 */
final class HtmlReadability
{
    public const NORMAL = 4.5;
    public const LARGE  = 3.0;

    /** At most this many entries in dropped_unreadable (every one is still counted in ignored). */
    public const MAX_LISTED = 25;

    private const LEVELS = [ 'column', 'row', 'section' ];

    /**
     * Paint and ink travel together (D1, D2): decides every colour and returns the styles DiviMarkup writes plus the report parts.
     *
     * @param array<string,mixed> $blocks HtmlStyleBlocks::build()
     * @return array{sections: list<array{style:array, rows: list<array{style:array, columns: list<array{style:array, modules: list<array>}>}>}>,
     *               report: array{carried: array<string,int>, ignored: array<string,int>, dropped_unreadable: list<array>, clamped:int, notes: list<string>, passes:int}}
     *
     * Style records, exactly as DiviMarkup takes them: section {background?, padding?}; row {background?, padding?, max_width?};
     * column {background?, padding?}; heading and text {color?, size?, weight?, align?, family?}; button {color?, background?, radius?};
     * image and divider []. padding is side => '<n>px', max_width '<n>px'.
     * report.ignored = HtmlStyleBlocks' counters plus this class's (HtmlStyles' and HtmlCss' are added by the caller). report.notes are the
     * fixed sentences of a page whose styles were not carried at all; `passes` (the largest pass count of a section) is internal and the
     * facade strips it.
     * dropped_unreadable entry: {path, block, reason, color, background, ratio}: path the module's first source element, block heading|text|
     * button, reason one of unreadable, unknown_background, paint_dropped, unproven, button_ghosted, color the colour that was not written,
     * background the colour it was judged on (null when unknown), ratio that contrast rounded to 2 decimals as a string (null when unknown).
     */
    public static function decide( array $blocks ): array
    {
        $self     = new self();
        $sections = [];
        foreach ( is_array( $blocks['sections'] ?? null ) ? $blocks['sections'] : [] as $s ) {
            $sections[] = $self->section( is_array( $s ) ? $s : [] );
        }
        $ignored = is_array( $blocks['ignored'] ?? null ) ? $blocks['ignored'] : [];
        foreach ( $self->ignored as $k => $n ) {
            $ignored[ $k ] = ( $ignored[ $k ] ?? 0 ) + $n;
        }
        ksort( $ignored );
        ksort( $self->carried );

        return [
            'sections' => $sections,
            'report'   => [
                'carried'            => $self->carried,
                'ignored'            => $ignored,
                'dropped_unreadable' => $self->dropped,
                'clamped'            => is_int( $blocks['clamped'] ?? null ) ? $blocks['clamped'] : 0,
                'notes'              => array_values( array_filter( is_array( $blocks['notes'] ?? null ) ? $blocks['notes'] : [], 'is_string' ) ),
                'passes'             => $self->passes,
            ],
        ];
    }

    /** WCAG 2.x contrast of two #rrggbb colours (DesignLanguages::contrast, the one formula the plugin uses). */
    public static function contrast( string $fg, string $bg ): float
    {
        return DesignLanguages::contrast( $fg, $bg );
    }

    /** @var array<string,int> */
    private array $carried = [];

    /** @var array<string,int> */
    private array $ignored = [];

    /** @var list<array<string,mixed>> */
    private array $dropped = [];

    private int $passes = 0;

    // ---------------------------------------------------------------
    // One section
    // ---------------------------------------------------------------

    /** @param array<string,mixed> $s @return array<string,mixed> */
    private function section( array $s ): array
    {
        // Levels: id => {kind, paint (original), keep}. Modules: each with the ids of its containing levels, innermost first.
        $levels = [];
        $mods   = [];
        $rows   = is_array( $s['rows'] ?? null ) ? $s['rows'] : [];
        $levels['s'] = [ 'kind' => 'section', 'paint' => self::paint( $s['paint'] ?? null ), 'canvas' => false ];
        foreach ( $rows as $r => $row ) {
            $levels[ 'r' . $r ] = [ 'kind' => 'row', 'paint' => self::paint( $row['paint'] ?? null ), 'canvas' => false ];
            foreach ( is_array( $row['columns'] ?? null ) ? $row['columns'] : [] as $c => $col ) {
                $levels[ 'c' . $r . '.' . $c ] = [ 'kind' => 'column', 'paint' => self::paint( $col['paint'] ?? null ), 'canvas' => false ];
                foreach ( is_array( $col['modules'] ?? null ) ? $col['modules'] : [] as $i => $m ) {
                    $mods[ $r . '.' . $c . '.' . $i ] = [ 'm' => is_array( $m ) ? $m : [], 'chain' => [ 'column' => 'c' . $r . '.' . $c, 'row' => 'r' . $r, 'section' => 's' ] ];
                }
            }
        }

        // The canvas (D2), decided once.
        if ( 'none' === $levels['s']['paint']['state'] ) {
            foreach ( $mods as $x ) {
                if ( in_array( $x['m']['type'] ?? null, [ 'heading', 'text' ], true ) && 'set' === ( $x['m']['ink']['state'] ?? null ) ) {
                    $levels['s']['paint']  = [ 'state' => 'set', 'value' => HtmlStyleBlocks::CANVAS ];
                    $levels['s']['canvas'] = true;
                    break;
                }
            }
        }

        // Kept-painted: set and proven on that block.
        $keep = [];
        foreach ( $levels as $id => $l ) {
            $keep[ $id ] = 'set' === $l['paint']['state'] && self::backgroundProven( $l['kind'] );
            if ( 'set' === $l['paint']['state'] && ! self::backgroundProven( $l['kind'] ) ) {
                $levels[ $id ]['paint'] = [ 'state' => 'unknown', 'value' => null ]; // a background the writer cannot put there
                $this->bump( 'unproven' );
            }
        }

        $limit  = count( $levels ) + 2;
        $passes = 0;
        $failed = []; // module => the verdict of the pass it first failed in (its reason, for the report)
        do {
            ++$passes;
            $drop = [];
            foreach ( $mods as $key => $x ) {
                $v = self::judge( $x['m'], $x['chain'], $levels, $keep );
                if ( $v['fail'] ) {
                    $failed[ $key ] ??= $v;
                    foreach ( $x['chain'] as $id ) {
                        if ( $keep[ $id ] ) {
                            $drop[ $id ] = true;
                        }
                    }
                }
            }
            foreach ( $drop as $id => $_ ) {
                $keep[ $id ] = false;
            }
        } while ( array() !== $drop && $passes < $limit );
        $this->passes = max( $this->passes, $passes );

        foreach ( $levels as $id => $l ) {
            if ( ! $keep[ $id ] && 'set' === $l['paint']['state'] && ! $l['canvas'] ) {
                $this->bump( 'paint_dropped' ); // a source background left out so that every text on it stays readable
            }
        }

        // Write.
        $sectionStyle = $this->box( $keep['s'] ? $levels['s']['paint']['value'] : null, $s['padding'] ?? [], DiviMarkup::STYLE_PROVEN['section-padding'] ?? false );
        $outRows      = [];
        foreach ( $rows as $r => $row ) {
            $rowStyle = $this->box( $keep[ 'r' . $r ] ? $levels[ 'r' . $r ]['paint']['value'] : null, $row['padding'] ?? [], true );
            if ( is_int( $row['max_width'] ?? null ) ) {
                if ( DiviMarkup::STYLE_PROVEN['row-max-width'] ?? false ) {
                    $rowStyle['max_width'] = $row['max_width'] . 'px';
                    $this->count( 'max_width' );
                } else {
                    $this->bump( 'unproven' );
                }
            }
            $outCols = [];
            foreach ( is_array( $row['columns'] ?? null ) ? $row['columns'] : [] as $c => $col ) {
                $id       = 'c' . $r . '.' . $c;
                $colStyle = $this->box( $keep[ $id ] ? $levels[ $id ]['paint']['value'] : null, $col['padding'] ?? [], DiviMarkup::STYLE_PROVEN['column-padding'] ?? false );
                $outMods  = [];
                foreach ( is_array( $col['modules'] ?? null ) ? $col['modules'] : [] as $i => $m ) {
                    $key       = $r . '.' . $c . '.' . $i;
                    $x         = $mods[ $key ];
                    $outMods[] = $this->write( $x['m'], $failed[ $key ] ?? self::judge( $x['m'], $x['chain'], $levels, $keep ) );
                }
                $outCols[] = [ 'style' => $colStyle, 'modules' => $outMods ];
            }
            $outRows[] = [ 'style' => $rowStyle, 'columns' => $outCols ];
        }

        return [ 'style' => $sectionStyle, 'rows' => $outRows ];
    }

    /**
     * The verdict for one module against the kept set.
     *
     * @param array<string,mixed>                $m
     * @param array<string,string>               $chain  level => id, innermost first
     * @param array<string,array<string,mixed>>  $levels
     * @param array<string,bool>                 $keep
     * @return array{fail:bool, ink:?string, fill:?string, reason:?string, background:?string, ratio:?float}
     */
    private static function judge( array $m, array $chain, array $levels, array $keep ): array
    {
        $type = $m['type'] ?? '';
        $none = [ 'fail' => false, 'ink' => null, 'fill' => null, 'reason' => null, 'background' => null, 'ratio' => null ];
        if ( ! in_array( $type, [ 'heading', 'text', 'button' ], true ) ) {
            return $none;
        }
        $L = null;
        foreach ( self::LEVELS as $level ) {
            if ( $keep[ $chain[ $level ] ] ) {
                $L = $level;
                break;
            }
        }

        if ( 'button' === $type ) {
            $fill  = self::paint( $m['fill'] ?? null );
            $label = ( 'set' === ( $m['label']['state'] ?? null ) && self::isColour( $m['label']['value'] ?? null ) ) ? $m['label']['value'] : null;
            if ( 'set' === $fill['state'] && null !== $label ) {
                $ratio = self::contrast( $label, (string) $fill['value'] );
                if ( $ratio >= self::NORMAL ) {
                    return [ 'fail' => false, 'ink' => $label, 'fill' => $fill['value'], 'reason' => null, 'background' => null, 'ratio' => null ];
                }
            }
            if ( null === $L ) {
                return $none + self::lost( $chain, $levels );
            }
            // The ghost's label: its own colour when the source draws it on the backdrop already (no fill of its own), else the parent's ink.
            $parent = $m['parent_ink'] ?? [];
            if ( 'none' === $fill['state'] && null !== $label ) {
                $ink = $label;
            } elseif ( 'set' === ( $parent['state'] ?? null ) && self::isColour( $parent['value'] ?? null ) ) {
                $ink = $parent['value'];
            } else {
                $ink = 'initial' === ( $parent['state'] ?? null ) ? '#000000' : null;
            }
            $v = self::onPaint( $m, $ink, $chain, $L, $levels, false );
            if ( $v['fail'] ) {
                return $v;
            }
            if ( null === $ink ) {
                return [ 'fail' => true, 'ink' => null, 'fill' => null, 'reason' => 'unresolved', 'background' => null, 'ratio' => null ];
            }

            return [ 'fail' => false, 'ink' => $ink, 'fill' => DiviMarkup::TRANSPARENT, 'reason' => null, 'background' => null, 'ratio' => null ];
        }

        $inkState = $m['ink']['state'] ?? 'unresolved';
        if ( null === $L ) {
            return $none + self::lost( $chain, $levels );
        }
        if ( 'unresolved' === $inkState || 'mixed' === $inkState ) {
            // The structural checks first (they name the more useful reason), then the missing claim.
            $v = self::onPaint( $m, null, $chain, $L, $levels, true );

            return $v['fail'] ? $v : [ 'fail' => true, 'ink' => null, 'fill' => null, 'reason' => $inkState, 'background' => null, 'ratio' => null ];
        }
        $ink = 'set' === $inkState && self::isColour( $m['ink']['value'] ?? null ) ? $m['ink']['value'] : '#000000';

        return self::onPaint( $m, $ink, $chain, $L, $levels, true );
    }

    /**
     * Rule 2's checks for $ink printed on paint(L).
     *
     * @param array<string,mixed> $m
     * @param array<string,string> $chain
     * @param array<string,array<string,mixed>> $levels
     * @return array{fail:bool, ink:?string, fill:?string, reason:?string, background:?string, ratio:?float}
     */
    private static function onPaint( array $m, ?string $ink, array $chain, string $L, array $levels, bool $text ): array
    {
        $paint = (string) $levels[ $chain[ $L ] ]['paint']['value'];
        $fail  = static fn ( string $reason, ?string $bg, ?float $ratio ): array => [ 'fail' => true, 'ink' => null, 'fill' => null, 'reason' => $reason, 'background' => $bg, 'ratio' => $ratio ];
        if ( true === ( $m['between'][ $L ] ?? true ) ) {
            return $fail( 'unknown_background', null, null );
        }
        foreach ( self::LEVELS as $level ) {
            if ( $level === $L ) {
                break;
            }
            if ( 'none' !== $levels[ $chain[ $level ] ]['paint']['state'] ) {
                return $fail( 'unknown_background', null, null ); // a background inside L that is not written
            }
        }
        if ( null === $ink ) {
            return [ 'fail' => false, 'ink' => null, 'fill' => null, 'reason' => null, 'background' => null, 'ratio' => null ];
        }
        $ratio = self::contrast( $ink, $paint );
        if ( $text ) {
            if ( array() !== ( $m['uninkable'] ?? [] ) ) {
                return $fail( 'unproven', $paint, $ratio );
            }
            foreach ( is_array( $m['lists'] ?? null ) ? $m['lists'] : [] as $list ) {
                $decision = [ 'ul' => 'list-ink-ul', 'ol' => 'list-ink-ol', 'blockquote' => 'quote-ink' ][ $list ] ?? null;
                if ( null === $decision || true !== ( DiviMarkup::STYLE_PROVEN[ $decision ] ?? false ) ) {
                    return $fail( 'unproven', $paint, $ratio );
                }
            }
        }
        $floor = ( $text && true === ( $m['large'] ?? false ) ) ? self::LARGE : self::NORMAL;
        if ( $ratio < $floor ) {
            return $fail( 'unreadable', $paint, $ratio );
        }

        return [ 'fail' => false, 'ink' => $ink, 'fill' => null, 'reason' => null, 'background' => $paint, 'ratio' => $ratio ];
    }

    /**
     * Why a module without L has no colour: `unknown` when a containing level's paint is unknown (the text sits on something this converter
     * cannot know), else `lost` = the original paint (canvas included) of the innermost painted level that was dropped, null when it never
     * had one.
     *
     * @param array<string,string> $chain
     * @param array<string,array<string,mixed>> $levels
     * @return array{unknown:bool, lost:?string}
     */
    private static function lost( array $chain, array $levels ): array
    {
        $lost = null;
        foreach ( self::LEVELS as $level ) {
            $p = $levels[ $chain[ $level ] ]['paint'];
            if ( 'unknown' === $p['state'] ) {
                return [ 'unknown' => true, 'lost' => null ];
            }
            if ( 'set' === $p['state'] && null === $lost ) {
                $lost = $p['value'];
            }
        }

        return [ 'unknown' => false, 'lost' => $lost ];
    }

    // ---------------------------------------------------------------
    // Writing and the report
    // ---------------------------------------------------------------

    /**
     * The style record of one module from its verdict (the verdict carries the chain's facts through `reason`, `unknown`).
     *
     * @param array<string,mixed> $m
     * @param array<string,mixed> $v
     * @return array<string,mixed>
     */
    private function write( array $m, array $v ): array
    {
        $type  = $m['type'] ?? '';
        $style = [];
        if ( 'heading' === $type || 'text' === $type ) {
            if ( null !== $v['ink'] ) {
                $style['color'] = $v['ink'];
                $this->count( 'color' );
            } elseif ( 'set' === ( $m['ink']['state'] ?? null ) && self::isColour( $m['ink']['value'] ?? null ) ) {
                $this->listText( $m, $type, $v );
            } else {
                $this->cause( $v );
            }
            foreach ( [ 'size', 'weight', 'align', 'family' ] as $key ) {
                if ( null !== ( $m[ $key ] ?? null ) ) {
                    $style[ $key ] = $m[ $key ];
                    $this->count( 'family' === $key ? 'font' : $key );
                }
            }

            return $style;
        }
        if ( 'button' === $type ) {
            $label = 'set' === ( $m['label']['state'] ?? null ) ? ( $m['label']['value'] ?? null ) : null;
            if ( null !== $v['fill'] && null !== $v['ink'] ) {
                $style['color']      = $v['ink'];
                $style['background'] = $v['fill'];
                $this->count( 'color' );
                if ( DiviMarkup::TRANSPARENT === $v['fill'] ) {
                    $this->bump( 'button_ghosted' );
                    if ( null !== $label && $label !== $v['ink'] ) {
                        $this->listButton( $m, $v, true );
                    }
                } else {
                    $this->count( 'background' );
                }
            } else {
                if ( 'set' === ( $m['fill']['state'] ?? null ) && null === $label ) {
                    $this->bump( 'unproven' ); // a fill without its own label colour: half a pair is never written
                }
                if ( null !== $label ) {
                    $this->listButton( $m, $v, false );
                } else {
                    $this->cause( $v );
                }
            }
            if ( is_int( $m['radius'] ?? null ) ) {
                $style['radius'] = $m['radius'];
                $this->count( 'radius' );
            }

            return $style;
        }

        return [];
    }

    /**
     * A failing module without a colour of its own to list (its ink is the CSS initial one, or a ghost's label is its parent's): its reason
     * is still counted, so the report says why the paint around it was dropped. mixed and unresolved are counted where they arise.
     *
     * @param array<string,mixed> $v
     */
    private function cause( array $v ): void
    {
        if ( true === ( $v['fail'] ?? false ) && is_string( $v['reason'] ?? null ) && ! in_array( $v['reason'], [ 'mixed', 'unresolved' ], true ) ) {
            $this->bump( $v['reason'] );
        }
    }

    /** A heading or text whose `set` ink was not written. @param array<string,mixed> $m @param array<string,mixed> $v */
    private function listText( array $m, string $block, array $v ): void
    {
        $reason = $v['reason'] ?? null;
        $bg     = $v['background'] ?? null;
        $ratio  = $v['ratio'] ?? null;
        if ( null === $reason ) {
            [ $reason, $bg ] = ( true === ( $v['unknown'] ?? false ) || null === ( $v['lost'] ?? null ) ) ? [ 'unknown_background', null ] : [ 'paint_dropped', $v['lost'] ];
            $ratio           = null === $bg ? null : self::contrast( (string) $m['ink']['value'], (string) $bg );
        }
        $this->entry( $m, $block, (string) $m['ink']['value'], $reason, $bg, $ratio );
    }

    /**
     * A button whose own label colour was not written as it is: ghosted with another label, or no colour at all. It is described by its
     * own pair: the label on its own fill when it has one.
     *
     * @param array<string,mixed> $m
     * @param array<string,mixed> $v
     */
    private function listButton( array $m, array $v, bool $ghosted ): void
    {
        $label = (string) $m['label']['value'];
        $fill  = self::paint( $m['fill'] ?? null );
        if ( $ghosted ) {
            $reason = 'button_ghosted';
            $bg     = 'set' === $fill['state'] ? $fill['value'] : null;
        } elseif ( 'set' === $fill['state'] ) {
            $reason = 'unreadable'; // its pair is below the floor, and no ghost could stand in for it
            $bg     = $fill['value'];
        } elseif ( 'unknown' === $fill['state'] ) {
            $reason = 'unknown_background';
            $bg     = null;
        } else {
            $this->listText( [ 'ink' => [ 'value' => $label ] ] + $m, 'button', $v ); // drawn on the backdrop: judged like a text

            return;
        }
        $this->entry( $m, 'button', $label, $reason, $bg, null === $bg ? null : self::contrast( $label, (string) $bg ) );
    }

    /** One dropped_unreadable entry: counted always (button_ghosted is counted by the ghost itself), listed up to MAX_LISTED. @param array<string,mixed> $m */
    private function entry( array $m, string $block, string $color, string $reason, ?string $bg, ?float $ratio ): void
    {
        if ( ! self::isColour( $color ) ) {
            return;
        }
        if ( 'unknown_background' === $reason ) {
            $bg = null;
        }
        if ( 'button_ghosted' !== $reason ) {
            $this->bump( $reason );
        }
        if ( count( $this->dropped ) < self::MAX_LISTED ) {
            $this->dropped[] = [
                'path'       => is_string( $m['path'] ?? null ) ? $m['path'] : null,
                'block'      => $block,
                'reason'     => $reason,
                'color'      => $color,
                'background' => self::isColour( $bg ) ? $bg : null,
                'ratio'      => ( null === $ratio || ! self::isColour( $bg ) ) ? null : sprintf( '%.2F', $ratio ),
            ];
        }
    }

    /**
     * A section, row or column style: background (when kept) and padding.
     *
     * @param array<string,mixed> $padding side => int
     * @return array<string,mixed>
     */
    private function box( ?string $background, array $padding, bool $paddingProven ): array
    {
        $style = [];
        if ( null !== $background ) {
            $style['background'] = $background;
            $this->count( 'background' );
        }
        $pad = [];
        foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
            if ( is_int( $padding[ $side ] ?? null ) && $padding[ $side ] >= 0 && $padding[ $side ] <= HtmlStyleBlocks::PAD_MAX ) {
                $pad[ $side ] = $padding[ $side ] . 'px';
            }
        }
        if ( array() !== $pad ) {
            if ( $paddingProven ) {
                $style['padding'] = $pad;
                $this->count( 'padding' );
            } else {
                $this->bump( 'unproven' );
            }
        }

        return $style;
    }

    private static function backgroundProven( string $kind ): bool
    {
        if ( 'row' === $kind ) {
            return true === ( DiviMarkup::STYLE_PROVEN['row-background'] ?? false );
        }
        if ( 'column' === $kind ) {
            return true === ( DiviMarkup::STYLE_PROVEN['column-background'] ?? false );
        }

        return true;
    }

    /** @return array{state:string,value:?string} */
    private static function paint( mixed $p ): array
    {
        if ( ! is_array( $p ) ) {
            return [ 'state' => 'none', 'value' => null ];
        }
        $state = $p['state'] ?? 'unknown';
        if ( 'set' === $state ) {
            return self::isColour( $p['value'] ?? null ) ? [ 'state' => 'set', 'value' => $p['value'] ] : [ 'state' => 'unknown', 'value' => null ];
        }

        return 'none' === $state ? [ 'state' => 'none', 'value' => null ] : [ 'state' => 'unknown', 'value' => null ];
    }

    private static function isColour( mixed $v ): bool
    {
        return is_string( $v ) && 1 === preg_match( '/^#[0-9a-f]{6}\z/', $v );
    }

    private function count( string $property ): void
    {
        $this->carried[ $property ] = ( $this->carried[ $property ] ?? 0 ) + 1;
    }

    private function bump( string $reason ): void
    {
        if ( in_array( $reason, HtmlCss::REASONS, true ) ) {
            $this->ignored[ $reason ] = ( $this->ignored[ $reason ] ?? 0 ) + 1;
        }
    }
}
