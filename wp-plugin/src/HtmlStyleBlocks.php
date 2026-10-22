<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * html_to_divi (styles=basic), step one of the decision layer: the mapper's plan (HtmlMapper::map($body, true)) plus the computed records
 * (HtmlStyles::resolve()) become CANDIDATE styles per block, before any colour decision (HtmlReadability::decide() makes those). Pure and
 * deterministic: no clock, no randomness, no locale, no network; paths are only compared as strings.
 *
 * ANCHORS (the source element whose own box a block stands for).
 * - Section: its own element (`from`), or for a run of loose nodes the run's parent (`ctx`). Its paint is the first background on
 *   [anchor, its ancestors up to html] whose state is not none (plan D3: a wrapper's, the body's or the html's background of any shade
 *   spreads to the sections it wraps): set -> that colour, unknown -> unknown, none everywhere -> none. Its padding is its OWN element's
 *   only (a loose section has none: the run's parent is shared by other content).
 * - Row: a cards chunk's `from` (the parent of the cards); a single chunk's lowest common ancestor of its modules' `container` paths. A row
 *   anchor that is the section anchor, or not strictly inside it, is no anchor (paint none, no padding). Its paint is its own background.
 *   A cards chunk's RowC applies to every Divi row the chunk becomes.
 * - Column: a card's own element (`column_src`), paint and padding its own. A single chunk has one ColumnC without anchor, paint or padding.
 * Images and dividers have no source paths (phase 2 styles neither): they are neutral modules.
 *
 * VALUES (only what the source computes for the element: state `set`; `initial` and every other state carry nothing except a text's CSS
 * initial ink #000000, which the readability rule needs to know).
 * - padding: per side an int clamped to 0..PAD_MAX; an `unresolved` side is not written (counted unresolved); null = no author value.
 * - row max_width (plan D6, rows only): the smallest max width on [row anchor, or else the section anchor, and its ancestors up to html],
 *   clamped to WIDTH_MIN..WIDTH_MAX; any `unresolved` max width on that chain means none is carried (it could be the smallest). A max width
 *   on a module's own element or on a card is not carried (not_on_block).
 * - size (headings and text): round half up, clamped to SIZE_MIN..SIZE_MAX; weight one of the nine values; align center|right, justify only
 *   when proven on that block (DiviMarkup::STYLE_PROVEN), left never (plan D7, Divi's default); family only an HtmlFonts::choose() result.
 *   A text module of several merged pieces keeps a property only when every piece has the same record (HtmlStyles::common(): `mixed`).
 * - large (the 3:1 floor): from the WRITTEN size at its smallest device step (DiviMarkup writes a tablet and a phone step above 32 px, the
 *   phone one max(20, round(0.55 x size))): that step >= 24, or >= 19 with a carried weight >= 700. Stricter than the desktop size alone,
 *   so a headline that is large on a desktop but not on a phone is judged at 4.5:1.
 * - button: its own fill (Paint), its own label colour, the ink of its parent (for a ghost) and a radius when all four corners are equal.
 *   A button's own background is its fill, so its `between` starts at its parent.
 * - between[level]: an uncarried background (state not none) on [the module's style element(s), inclusive, up to that level's anchor,
 *   exclusive]; true when the level has no anchor or the element is not inside it (the relation is unknown).
 * - uninkable (text only): the module holds a link, mark, pre, code, kbd, table or dl. Those keep the theme's link colour or a UA/theme
 *   style the writer does not ink (DiviMarkup's ink gate covers ul, ol and blockquote only), so such a module cannot be inked readably.
 * - Inline descendants of a text (span, strong ...) with another colour or their own background are not carried: counted mixed once per
 *   module (the result prints them in the module's ink, which the readability rule judges).
 *
 * FAIL CLOSED (the whole feature degrades to "no styles carried" with one fixed report sentence in `notes`, never silently): markup a
 * browser would repair differently (`repaired`), the work budget (`work_cap`), CSS not read: a linked stylesheet or an @import (`external`)
 * or the byte or rule cap (`css_cap`). Every block is then empty.
 *
 * The output never holds raw CSS or attribute text: states, #rrggbb colours, ints, closed keywords, family names from HtmlFonts' list, and
 * the XPaths HtmlSource/HtmlMapper already use.
 */
final class HtmlStyleBlocks
{
    public const SIZE_MIN   = 8;
    public const SIZE_MAX   = 200;
    public const PAD_MAX    = 200;
    public const WIDTH_MIN  = 200;
    public const WIDTH_MAX  = 2400;
    public const RADIUS_MAX = 999;

    /** The source canvas (plan D2): the CSS initial canvas a browser paints a page without any background on. */
    public const CANVAS = '#ffffff';

    /**
     * Whether CSS this converter cannot read (a linked stylesheet, an @import) turns the whole feature off (the review contract: such CSS
     * could set any colour, so nothing is claimed). One switch, so the controller can rule otherwise (HtmlCss counts @import only, so a page
     * that imports a web font would carry nothing; the 14-styled-fonts fixture is such a page).
     */
    public const DEGRADE_ON_EXTERNAL = true;

    /** Report sentences when nothing is carried (fixed text: never raw input). */
    public const NOTE_WORK_CAP = 'The page\'s CSS was too large to resolve in full: styles were not carried.';
    public const NOTE_EXTERNAL = 'The page loads CSS this converter does not read (a linked stylesheet or an @import): styles were not carried, because that CSS could change every colour.';
    public const NOTE_CSS_CAP  = 'The page\'s CSS was longer than the converter reads: styles were not carried.';

    /** Tags inside a text module whose colour or box the theme decides (the writer does not ink them): the module is not inkable. */
    public const UNINKABLE = [ 'a', 'mark', 'pre', 'code', 'kbd', 'table', 'dl' ];

    /** Tags that need a proven ink decision (DiviMarkup::STYLE_PROVEN): ul => list-ink-ul, ol => list-ink-ol, blockquote => quote-ink. */
    public const LISTS = [ 'ul', 'ol', 'blockquote' ];

    /** Elements a text or heading module is made of by itself (HtmlMapper's headings and prose blocks). */
    private const PIECES = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol', 'dl', 'blockquote', 'pre', 'table', 'address' ];

    private const WEIGHTS = [ 100, 200, 300, 400, 500, 600, 700, 800, 900 ];
    private const SIDES   = [ 'top', 'right', 'bottom', 'left' ];
    private const LEVELS  = [ 'column', 'row', 'section' ];

    /** @var array<string,array<string,mixed>> */
    private array $nodes;

    /** @var array<string,int> path => document position */
    private array $order;

    /** @var list<string> */
    private array $keys;

    /** @var array<string,int> */
    private array $ignored = [];

    private int $clamped = 0;

    /** @var array<string,array{source:string,action:string}> */
    private array $fonts = [];

    /**
     * The plan (map($body, true)) plus HtmlStyles::resolve() -> candidate styles per block, before any colour decision.
     *
     * @param array<string,mixed> $plan     HtmlMapper::map( $body, true )
     * @param array<string,mixed> $computed HtmlStyles::resolve( ... )
     * @return array{sections: list<array<string,mixed>>, clamped:int, ignored: array<string,int>, fonts: list<array{source:string,action:string}>, notes: list<string>}
     *
     * SectionC = {anchor:?string, paint: Paint, padding: array<side,int>, rows: list<RowC>}       one RowC per plan chunk
     * RowC     = {anchor:?string, paint: Paint, padding, max_width:?int, columns: list<ColumnC>}
     * ColumnC  = {anchor:?string, paint: Paint, padding, modules: list<ModuleC>}                   a single chunk: one ColumnC, no anchor
     * Paint    = {state:'none'|'set'|'unknown', value:?string}
     * ModuleC  = {type, path:?string, ink: {state:'set'|'initial'|'unresolved'|'mixed', value:?string} (initial = #000000),
     *             size:?int, weight:?string, align:?string, family:?string, large:bool, lists:list<'ul'|'ol'|'blockquote'>,
     *             uninkable:list<string>, between: array{column:bool,row:bool,section:bool},
     *             button only: fill: Paint, label: {state:'set'|'none', value:?string}, parent_ink: {state, value}, radius:?int}
     * `ignored` holds only this class's own counters (HtmlStyles' own, which include HtmlCss', are in $computed['ignored']).
     */
    public static function build( array $plan, array $computed ): array
    {
        $self  = new self( is_array( $computed['nodes'] ?? null ) ? $computed['nodes'] : [] );
        $notes = self::degradeNotes( $computed );
        $out   = [];
        foreach ( is_array( $plan['sections'] ?? null ) ? $plan['sections'] : [] as $section ) {
            $out[] = $self->section( $section, array() !== $notes );
        }
        ksort( $self->ignored );

        return [
            'sections' => $out,
            'clamped'  => $self->clamped,
            'ignored'  => $self->ignored,
            'fonts'    => array_values( $self->fonts ),
            'notes'    => $notes,
        ];
    }

    /** @param array<string,mixed> $computed @return list<string> */
    private static function degradeNotes( array $computed ): array
    {
        $notes    = [];
        $repaired = is_array( $computed['repaired'] ?? null ) ? $computed['repaired'] : [];
        if ( array() !== $repaired ) {
            // Only HtmlSource's own sentences reach the report.
            $known   = array_values( array_filter( $repaired, static fn ( mixed $r ): bool => is_string( $r ) && in_array( $r, HtmlSource::REPAIRS, true ) ) );
            $notes[] = HtmlStyles::repairedNote( array() !== $known ? $known : [ HtmlSource::REPAIRS['misnest'] ] );
        }
        if ( true === ( $computed['work_cap'] ?? false ) ) {
            $notes[] = self::NOTE_WORK_CAP;
        }
        $ignored = is_array( $computed['ignored'] ?? null ) ? $computed['ignored'] : [];
        if ( ( $ignored['external'] ?? 0 ) > 0 && self::DEGRADE_ON_EXTERNAL ) {
            $notes[] = self::NOTE_EXTERNAL;
        }
        if ( ( $ignored['css_cap'] ?? 0 ) > 0 && true !== ( $computed['work_cap'] ?? false ) ) {
            $notes[] = self::NOTE_CSS_CAP;
        }
        $onlyExternal = ( $ignored['external'] ?? 0 ) > 0 && ( $ignored['css_cap'] ?? 0 ) === 0 && true !== ( $computed['work_cap'] ?? false ) && array() === $repaired;
        if ( array() === $notes && true === ( $computed['unread'] ?? false ) && ! $onlyExternal ) {
            $notes[] = self::NOTE_CSS_CAP; // unread for a reason this class does not know: still fail closed
        }
        if ( ! is_array( $computed['nodes'] ?? null ) && array() === $notes ) {
            $notes[] = self::NOTE_CSS_CAP;
        }

        return $notes;
    }

    /** @param array<string,array<string,mixed>> $nodes */
    private function __construct( array $nodes )
    {
        $this->nodes = $nodes;
        $this->keys  = array_keys( $nodes );
        $this->order = array_flip( $this->keys );
    }

    // ---------------------------------------------------------------
    // Blocks
    // ---------------------------------------------------------------

    /** @param array<string,mixed> $section @return array<string,mixed> */
    private function section( array $section, bool $degraded ): array
    {
        $from   = self::pathOrNull( $section['from'] ?? null );
        $anchor = $degraded ? null : ( $from ?? self::pathOrNull( $section['ctx'] ?? null ) );
        $rows   = [];
        foreach ( is_array( $section['chunks'] ?? null ) ? $section['chunks'] : [] as $chunk ) {
            $rows[] = $this->row( is_array( $chunk ) ? $chunk : [], $anchor, $degraded );
        }

        return [
            'anchor'  => $anchor,
            'paint'   => null === $anchor ? self::none() : $this->chainPaint( $anchor ),
            'padding' => ( null === $from || $degraded ) ? [] : $this->padding( $from ),
            'rows'    => $rows,
        ];
    }

    /** @param array<string,mixed> $chunk @return array<string,mixed> */
    private function row( array $chunk, ?string $sectionAnchor, bool $degraded ): array
    {
        $cards = 'cards' === ( $chunk['kind'] ?? null );
        if ( $cards ) {
            $candidate = self::pathOrNull( $chunk['from'] ?? null );
        } else {
            $containers = [];
            foreach ( is_array( $chunk['modules'] ?? null ) ? $chunk['modules'] : [] as $m ) {
                foreach ( is_array( $m['container'] ?? null ) ? $m['container'] : [] as $c ) {
                    if ( is_string( $c ) && '' !== $c ) {
                        $containers[] = $c;
                    }
                }
            }
            $candidate = self::commonAncestor( $containers );
        }
        $anchor = ( ! $degraded && null !== $candidate && null !== $sectionAnchor && self::inside( $candidate, $sectionAnchor ) ) ? $candidate : null;

        $columns = [];
        if ( $cards ) {
            $srcs = is_array( $chunk['column_src'] ?? null ) ? $chunk['column_src'] : [];
            foreach ( is_array( $chunk['columns'] ?? null ) ? $chunk['columns'] : [] as $i => $mods ) {
                $col = $degraded ? null : self::pathOrNull( $srcs[ $i ] ?? null );
                if ( null !== $col && ! self::inside( $col, $anchor ?? (string) $sectionAnchor ) ) {
                    $col = null;
                }
                if ( null !== $col && null !== $this->value( $col, 'max_width' ) ) {
                    $this->bump( 'not_on_block' ); // plan D6: a max width lands on rows only
                }
                $anchors   = [ 'column' => $col, 'row' => $anchor, 'section' => $sectionAnchor ];
                $columns[] = [
                    'anchor'  => $col,
                    'paint'   => null === $col ? self::none() : $this->ownPaint( $col ),
                    'padding' => null === $col ? [] : $this->padding( $col ),
                    'modules' => $this->modules( is_array( $mods ) ? $mods : [], $anchors, $degraded ),
                ];
            }
        } else {
            $anchors   = [ 'column' => null, 'row' => $anchor, 'section' => $sectionAnchor ];
            $columns[] = [ 'anchor' => null, 'paint' => self::none(), 'padding' => [], 'modules' => $this->modules( is_array( $chunk['modules'] ?? null ) ? $chunk['modules'] : [], $anchors, $degraded ) ];
        }

        return [
            'anchor'    => $anchor,
            'paint'     => null === $anchor ? self::none() : $this->ownPaint( $anchor ),
            'padding'   => null === $anchor ? [] : $this->padding( $anchor ),
            'max_width' => ( $degraded || null === ( $anchor ?? $sectionAnchor ) ) ? null : $this->maxWidth( (string) ( $anchor ?? $sectionAnchor ) ),
            'columns'   => $columns,
        ];
    }

    /**
     * @param list<array<string,mixed>> $mods
     * @param array{column:?string,row:?string,section:?string} $anchors
     * @return list<array<string,mixed>>
     */
    private function modules( array $mods, array $anchors, bool $degraded ): array
    {
        $out = [];
        foreach ( $mods as $m ) {
            $out[] = $this->module( is_array( $m ) ? $m : [], $anchors, $degraded );
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $m
     * @param array{column:?string,row:?string,section:?string} $anchors
     * @return array<string,mixed>
     */
    private function module( array $m, array $anchors, bool $degraded ): array
    {
        $type = is_string( $m['type'] ?? null ) ? $m['type'] : '';
        $from = [];
        foreach ( is_array( $m['from'] ?? null ) ? $m['from'] : [] as $p ) {
            if ( is_string( $p ) && '' !== $p ) {
                $from[] = $p;
            }
        }
        $base = [
            'type'      => $type,
            'path'      => $from[0] ?? null,
            'ink'       => [ 'state' => 'unresolved', 'value' => null ],
            'size'      => null,
            'weight'    => null,
            'align'     => null,
            'family'    => null,
            'large'     => false,
            'lists'     => [],
            'uninkable' => [],
            'between'   => [ 'column' => true, 'row' => true, 'section' => true ],
        ];
        if ( 'button' === $type ) {
            $base += [ 'fill' => self::none(), 'label' => [ 'state' => 'none', 'value' => null ], 'parent_ink' => [ 'state' => 'unresolved', 'value' => null ], 'radius' => null ];
        }
        if ( $degraded || array() === $from || ! in_array( $type, [ 'heading', 'text', 'button' ], true ) ) {
            return $base;
        }

        $rec = HtmlStyles::common( $this->nodes, 'text' === $type ? $from : [ $from[0] ], $this->ignored );
        foreach ( $from as $p ) {
            if ( null !== $this->value( $p, 'max_width' ) ) {
                $this->bump( 'not_on_block' ); // plan D6
                break;
            }
        }

        if ( 'button' === $type ) {
            $parent = self::parentOf( $from[0] );
            foreach ( self::LEVELS as $level ) {
                $base['between'][ $level ] = $this->between( [ $parent ], $anchors[ $level ] );
            }
            $base['fill']       = $this->ownPaint( $from[0] );
            $label              = self::ink( $rec['color'] );
            $base['label']      = 'set' === $label['state'] ? $label : [ 'state' => 'none', 'value' => null ];
            $parentRec          = HtmlStyles::common( $this->nodes, [ $parent ], $this->ignored );
            $base['parent_ink'] = self::ink( $parentRec['color'] );
            $base['radius']     = $this->radius( $from[0] );

            return $base;
        }

        foreach ( self::LEVELS as $level ) {
            $base['between'][ $level ] = $this->between( $from, $anchors[ $level ] );
        }
        $base['ink']    = self::ink( $rec['color'] );
        $base['size']   = $this->size( $rec['size'] );
        $base['weight'] = self::weight( $rec['weight'] );
        $base['align']  = $this->align( $rec['align'], $type );
        $base['family'] = $this->family( $rec['family'] );
        $base['large']  = self::isLarge( $base['size'], $base['weight'] );
        if ( 'text' === $type ) {
            $html              = is_string( $m['html'] ?? null ) ? $m['html'] : '';
            $base['lists']     = self::tags( $html, self::LISTS );
            $base['uninkable'] = self::tags( $html, self::UNINKABLE );
        }
        if ( $this->descendantsDiffer( $from, $rec['color'] ) ) {
            $this->bump( 'mixed' );
        }

        return $base;
    }

    // ---------------------------------------------------------------
    // Values
    // ---------------------------------------------------------------

    /** @param array<string,mixed> $rec a colour record @return array{state:string,value:?string} */
    private static function ink( array $rec ): array
    {
        $state = $rec['state'] ?? 'unresolved';
        $value = $rec['value'] ?? null;
        if ( 'set' === $state && self::isColour( $value ) ) {
            return [ 'state' => 'set', 'value' => $value ];
        }
        if ( 'initial' === $state && '#000000' === $value ) {
            return [ 'state' => 'initial', 'value' => '#000000' ]; // the CSS initial colour: nothing declared up to html
        }
        if ( 'mixed' === $state ) {
            return [ 'state' => 'mixed', 'value' => null ];
        }

        return [ 'state' => 'unresolved', 'value' => null ]; // unresolved, or `initial` without a value (the UA sheet decides): no claim
    }

    /** @param array<string,mixed> $rec */
    private function size( array $rec ): ?int
    {
        if ( 'set' !== ( $rec['state'] ?? null ) || ( ! is_int( $rec['value'] ) && ! is_float( $rec['value'] ) ) ) {
            return null;
        }
        $px = (int) floor( (float) $rec['value'] + 0.5 );

        return $this->clamp( $px, self::SIZE_MIN, self::SIZE_MAX );
    }

    /** @param array<string,mixed> $rec */
    private static function weight( array $rec ): ?string
    {
        if ( 'set' !== ( $rec['state'] ?? null ) || ! is_int( $rec['value'] ) || ! in_array( $rec['value'], self::WEIGHTS, true ) ) {
            return null;
        }

        return (string) $rec['value'];
    }

    /** @param array<string,mixed> $rec */
    private function align( array $rec, string $type ): ?string
    {
        if ( 'set' !== ( $rec['state'] ?? null ) ) {
            return null;
        }
        $v = $rec['value'];
        if ( 'justify' === $v ) {
            $ok = DiviMarkup::STYLE_PROVEN[ 'heading' === $type ? 'heading-justify' : 'text-justify' ] ?? false;
        } elseif ( 'center' === $v || 'right' === $v ) {
            $ok = 'heading' === $type || ( DiviMarkup::STYLE_PROVEN['text-align-orientation'] ?? false ) || ( DiviMarkup::STYLE_PROVEN['text-align-bodyfont'] ?? false );
        } else {
            return null; // left: Divi's default (plan D7); anything else is not an alignment
        }
        if ( ! $ok ) {
            $this->bump( 'unproven' );

            return null;
        }

        return $v;
    }

    /** @param array<string,mixed> $rec */
    private function family( array $rec ): ?string
    {
        if ( 'set' !== ( $rec['state'] ?? null ) || ! is_array( $rec['value'] ) ) {
            return null;
        }
        $names = [];
        foreach ( $rec['value'] as $n ) {
            if ( is_string( $n ) ) {
                $names[] = $n;
            }
        }
        $choice = HtmlFonts::choose( $names );
        foreach ( $choice['fonts'] as $f ) {
            // The report names a family only in a plain form (letters, digits, spaces, dots, hyphens; at most 60): never raw CSS text.
            if ( 1 === preg_match( '/^[\p{L}\p{N}][\p{L}\p{N} .\-]{0,59}\z/u', $f['source'] ) ) {
                $this->fonts[ $f['source'] . "\0" . $f['action'] ] = $f;
            }
        }
        $family = $choice['family'];
        // The writer's own form (DiviMarkup::isFamily): a Google name exactly as listed or a proven web-safe one.
        if ( null !== $family && ( strlen( $family ) > 60 || 1 !== preg_match( '/^[A-Za-z0-9]+(?: [A-Za-z0-9]+)*\z/', $family ) ) ) {
            $family = null;
        }
        if ( null === $family ) {
            $this->bump( array() === $choice['fonts'] ? 'generic_font' : 'font_unavailable' );
        }

        return $family;
    }

    /** Large text for the 3:1 floor, judged at the smallest size the writer puts on any device. */
    public static function isLarge( ?int $size, ?string $weight ): bool
    {
        if ( null === $size ) {
            return false;
        }
        $smallest = $size > 32 ? max( 20, (int) round( 0.55 * $size ) ) : $size;

        return $smallest >= 24 || ( $smallest >= 19 && null !== $weight && (int) $weight >= 700 );
    }

    /** @return array<string,int> side => px (clamped); unresolved sides are left out and counted */
    private function padding( string $path ): array
    {
        $pad = $this->value( $path, 'padding' );
        $out = [];
        foreach ( self::SIDES as $side ) {
            $v = is_array( $pad ) ? ( $pad[ $side ] ?? null ) : 'unresolved';
            if ( is_int( $v ) ) {
                $out[ $side ] = $this->clamp( $v, 0, self::PAD_MAX );
            } elseif ( null !== $v ) {
                $this->bump( 'unresolved' );
            }
        }

        return $out;
    }

    private function maxWidth( string $start ): ?int
    {
        $best = null;
        for ( $p = $start; '' !== $p; $p = self::parentOf( $p ) ) {
            $v = $this->value( $p, 'max_width' );
            if ( null === $v ) {
                continue;
            }
            if ( ! is_int( $v ) ) {
                $this->bump( 'unresolved' );

                return null; // an unknown max width on the chain could be the smallest
            }
            $best = null === $best ? $v : min( $best, $v );
        }

        return null === $best ? null : $this->clamp( $best, self::WIDTH_MIN, self::WIDTH_MAX );
    }

    private function radius( string $path ): ?int
    {
        $r = $this->value( $path, 'radius' );
        if ( null === $r ) {
            return null;
        }
        if ( ! is_array( $r ) || 4 !== count( $r ) ) {
            $this->bump( 'unresolved' );

            return null;
        }
        $v = array_values( $r );
        if ( ! is_int( $v[0] ) || $v !== [ $v[0], $v[0], $v[0], $v[0] ] ) {
            $this->bump( 'mixed' ); // the writer sets one radius on all four corners

            return null;
        }

        return $this->clamp( $v[0], 0, self::RADIUS_MAX );
    }

    private function clamp( int $v, int $min, int $max ): int
    {
        $c = max( $min, min( $max, $v ) );
        if ( $c !== $v ) {
            ++$this->clamped;
        }

        return $c;
    }

    // ---------------------------------------------------------------
    // Backgrounds and the tree
    // ---------------------------------------------------------------

    /** @return array{state:string,value:?string} */
    private function ownPaint( string $path ): array
    {
        $bg = $this->value( $path, 'background' );
        if ( ! is_array( $bg ) ) {
            return [ 'state' => 'unknown', 'value' => null ]; // no record: nothing is known about it
        }
        $state = $bg['state'] ?? 'unknown';
        if ( 'none' === $state ) {
            return self::none();
        }
        if ( 'set' === $state && self::isColour( $bg['value'] ?? null ) ) {
            return [ 'state' => 'set', 'value' => $bg['value'] ];
        }

        return [ 'state' => 'unknown', 'value' => null ];
    }

    /** The first background that is not none on [path, its ancestors up to html] (plan D3). @return array{state:string,value:?string} */
    private function chainPaint( string $path ): array
    {
        for ( $p = $path; '' !== $p; $p = self::parentOf( $p ) ) {
            $paint = $this->ownPaint( $p );
            if ( 'none' !== $paint['state'] ) {
                return $paint;
            }
        }

        return self::none();
    }

    /** An uncarried background on [each start, inclusive, up to the anchor, exclusive]. @param list<string> $starts */
    private function between( array $starts, ?string $anchor ): bool
    {
        if ( null === $anchor ) {
            return true;
        }
        foreach ( $starts as $start ) {
            if ( $start === $anchor ) {
                continue;
            }
            if ( ! self::inside( $start, $anchor ) ) {
                return true;
            }
            for ( $p = $start; $p !== $anchor && '' !== $p; $p = self::parentOf( $p ) ) {
                if ( 'none' !== $this->ownPaint( $p )['state'] ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether an element inside a text piece has a colour other than the piece's or a background of its own (both are lost: the result
     * prints all of the module in one ink). Descendants follow their element in document order, so this reads one contiguous run.
     *
     * @param list<string>        $from
     * @param array<string,mixed> $color the module's colour record
     */
    private function descendantsDiffer( array $from, array $color ): bool
    {
        foreach ( $from as $p ) {
            $i = $this->order[ $p ] ?? null;
            // Only a piece that is its own element (a heading, p, list ...): a run's parent also holds the other modules of the block.
            if ( null === $i || ! in_array( self::tagOf( $p ), self::PIECES, true ) ) {
                continue;
            }
            $prefix = $p . '/';
            for ( $j = $i + 1, $n = count( $this->keys ); $j < $n && str_starts_with( $this->keys[ $j ], $prefix ); $j++ ) {
                $rec = $this->nodes[ $this->keys[ $j ] ];
                if ( ( $rec['color']['state'] ?? null ) !== ( $color['state'] ?? null ) || ( $rec['color']['value'] ?? null ) !== ( $color['value'] ?? null )
                    || 'none' !== ( $rec['background']['state'] ?? 'unknown' ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function value( string $path, string $key ): mixed
    {
        return $this->nodes[ $path ][ $key ] ?? null;
    }

    /** The closed tag names of $tags that occur in $html (as start tags), in $tags order. @param list<string> $tags @return list<string> */
    private static function tags( string $html, array $tags ): array
    {
        $out = [];
        foreach ( $tags as $t ) {
            if ( 1 === preg_match( '/<' . $t . '[\s>\/]/i', $html ) ) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** The deepest path that is an ancestor-or-self of every path ('' when they share only the root; null for none). @param list<string> $paths */
    private static function commonAncestor( array $paths ): ?string
    {
        if ( array() === $paths ) {
            return null;
        }
        $common = explode( '/', $paths[0] );
        foreach ( $paths as $p ) {
            $segs = explode( '/', $p );
            $k    = 0;
            while ( $k < count( $common ) && $k < count( $segs ) && $common[ $k ] === $segs[ $k ] ) {
                ++$k;
            }
            $common = array_slice( $common, 0, $k );
        }
        $out = implode( '/', $common );

        return '' === $out ? null : $out;
    }

    /** Whether $path is strictly inside $anchor. */
    private static function inside( string $path, string $anchor ): bool
    {
        return '' !== $anchor && str_starts_with( $path, $anchor . '/' );
    }

    /** The tag of the last step of a path ('p' for /html/body/p[2]). */
    private static function tagOf( string $path ): string
    {
        $last = substr( $path, (int) strrpos( $path, '/' ) + 1 );
        $cut  = strpos( $last, '[' );

        return strtolower( false === $cut ? $last : substr( $last, 0, $cut ) );
    }

    private static function parentOf( string $path ): string
    {
        $i = strrpos( $path, '/' );

        return false === $i || 0 === $i ? '' : substr( $path, 0, $i );
    }

    private static function pathOrNull( mixed $p ): ?string
    {
        return is_string( $p ) && '' !== $p && '/' === $p[0] ? $p : null;
    }

    private static function isColour( mixed $v ): bool
    {
        return is_string( $v ) && 1 === preg_match( '/^#[0-9a-f]{6}\z/', $v );
    }

    /** @return array{state:string,value:?string} */
    private static function none(): array
    {
        return [ 'state' => 'none', 'value' => null ];
    }

    private function bump( string $reason ): void
    {
        if ( in_array( $reason, HtmlCss::REASONS, true ) ) {
            $this->ignored[ $reason ] = ( $this->ignored[ $reason ] ?? 0 ) + 1;
        }
    }
}
