<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support\Dogfood;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\StyleKits;

/**
 * The dogfood scorer (plan 2026-10-22, Task 2): judges a Divi 5 page that an assistant wrote by hand against what the REAL
 * kit adapters would have written for the same blocks, one named check at a time. Static and deterministic: it reads the
 * markup, the kit and the language, nothing else (no site, no clock, no network, no model). Dev tooling: never shipped.
 *
 * - A block is classified structurally and conservatively (button, rule, chip, framed, item, form, image). A block the
 *   scorer cannot place is listed as unclassified and counted neither as passed nor as failed; an undecorated wrapper is
 *   only counted (`ignored`).
 * - Every expected value is read from Expected::attrs(), the resolved output of the adapters, for the CANDIDATE roles of the
 *   block's class on the band it sits on (plan D2: the best-matching role is the one with the most passing checks, a tie goes
 *   to the first role in KitAdapters::APPLIED order, grounds after the plain card roles). Colours are role-set membership
 *   (D4). When the band is `unknown`, or is a known band the role does not take (a tag on an inverted panel), the checks are
 *   judged against the union of the bands the role takes and pass if they pass against any; the report keeps the band the
 *   block really sits on and adds a `note`. A button on a `surface` band is judged on `light` (the adapter's own
 *   substitution) with no note.
 * - A check is only run when its subject exists, so one defect fails one check: a frame that is missing fails `frame`, and
 *   `frame-style` is simply not checked when there is no visible side.
 *
 * Lengths are compared numerically in px with tolerance 0 (`2px` equals `2.0px`); an absent value is "absent" and fails,
 * except where the check says otherwise: an absent border width is 0, an absent radius is 0px (a button's radius stays strict),
 * and a BUTTON's border (and a form submit's) with no width written is Divi's own 2px solid (`.et_pb_button{border:2px solid}`).
 * A button background that is a gradient is filled with a colour that cannot be judged (`gradient`).
 */
final class Scorer
{
    public const CATEGORIES = [ 'button', 'rule', 'chip', 'framed', 'item', 'form', 'image' ];

    public const CHECKS = [
        'button.radius', 'button.edge', 'button.edge-style', 'button.fill', 'button.fill-colour',
        'rule.width', 'rule.style', 'rule.colour',
        'chip.radius', 'chip.frame', 'chip.frame-style', 'chip.frame-colour', 'chip.fill-colour', 'chip.glyph-colour',
        'framed.radius', 'framed.frame', 'framed.frame-style', 'framed.frame-colour', 'framed.fill-colour', 'framed.top-rule',
        'item.radius', 'item.frame', 'item.frame-style', 'item.fill-colour',
        'form.field-radius', 'form.field-frame', 'form.field-frame-style', 'form.field-fill-colour',
        'form.button-radius', 'form.button-edge', 'form.button-edge-style', 'form.button-fill-colour',
        'image.radius',
    ];

    /** A radius at or above this is a shape (a circle, an arch), not a corner decision. */
    private const SHAPE_RADIUS = 999.0;

    private const SIDES = [ 'top', 'right', 'bottom', 'left' ];

    /** The longest observed/expected text a report carries. */
    private const MAX_TEXT = 90;

    /** class => the candidate roles: [marker role, ground or null, label], in KitAdapters::APPLIED order (grounds last). */
    private const CANDIDATES = [
        'button'      => [ [ 'control', null, 'control' ], [ 'control-secondary', null, 'control-secondary' ] ],
        'rule'        => [ [ 'rule', null, 'rule' ], [ 'rule-strong', null, 'rule-strong' ] ],
        'chip'        => [ [ 'chip', null, 'chip' ] ],
        'framed'      => [ [ 'card', null, 'card' ], [ 'panel', null, 'panel' ], [ 'card', 'inverted', 'card+inverted' ], [ 'card', 'accent', 'card+accent' ] ],
        'item'        => [ [ 'item', null, 'item' ], [ 'item-rule', null, 'item-rule' ] ],
        'form'        => [ [ 'form', null, 'form' ] ],
    ];

    /** The bands a card ground may sit on (KitAdapters::GROUND_BANDS). */
    private const GROUND_BANDS = [ 'light', 'surface' ];

    /** @var array<string,mixed> */
    private array $kit;

    /** @var array<string,mixed> */
    private array $language;

    private Expected $expected;

    /**
     * Colour key of the `surface` role => the keys also accepted for it in the FILL checks of cards, items and form fields: the
     * design language's own translucent card colour `surface_glass`, set only for glass languages and different from `surface`.
     *
     * @var array<string,list<string>>
     */
    private array $fillAliases = [];

    /**
     * @throws \InvalidArgumentException on an unknown kit or language id (the message lists the valid ones)
     */
    public function __construct( private readonly string $kitId, private readonly string $languageId )
    {
        $kit = StyleKits::get( $kitId );
        if ( null === $kit ) {
            throw new \InvalidArgumentException( sprintf( 'Unknown kit "%s". Valid kits: %s.', $kitId, implode( ', ', StyleKits::ids() ) ) );
        }
        $language = DesignLanguages::get( $languageId );
        if ( null === $language ) {
            throw new \InvalidArgumentException( sprintf( 'Unknown language "%s". Valid languages: %s.', $languageId, implode( ', ', DesignLanguages::ids() ) ) );
        }
        $this->kit      = $kit;
        $this->language = $language;
        $this->expected = new Expected( $kit, $language );
        $colors         = is_array( $language['colors'] ?? null ) ? $language['colors'] : [];
        $surface        = is_string( $colors['surface'] ?? null ) ? Page::colourKey( $colors['surface'] ) : null;
        $glass          = is_string( $colors['surface_glass'] ?? null ) ? Page::colourKey( $colors['surface_glass'] ) : null;
        if ( null !== $surface && null !== $glass && $surface !== $glass ) {
            $this->fillAliases[ $surface ] = [ $glass ];
        }
    }

    /** D6: the one language whose `kit` is the given kit, else `editorial`. */
    public static function defaultLanguage( string $kitId ): string
    {
        foreach ( DesignLanguages::ids() as $id ) {
            $language = DesignLanguages::get( $id );
            if ( is_array( $language ) && ( $language['kit'] ?? null ) === $kitId ) {
                return $id;
            }
        }

        return 'editorial';
    }

    /**
     * The report: page, kit, language, categories, overall, failures, unclassified, ignored.
     *
     * @param string $page the label the report carries (the script passes the path exactly as given)
     *
     * @return array<string,mixed>
     * @throws \InvalidArgumentException when the page does not parse
     */
    public function score( string $markup, string $page = '' ): array
    {
        $run   = $this->run( $markup );
        $count = [];
        foreach ( self::CATEGORIES as $cat ) {
            $count[ $cat ] = [ 'checked' => 0, 'passed' => 0 ];
        }
        $failures = [];
        foreach ( $run['results'] as $row ) {
            $cat = explode( '.', $row['check'] )[0];
            ++$count[ $cat ]['checked'];
            if ( $row['pass'] ) {
                ++$count[ $cat ]['passed'];
            } else {
                $failure = [
                    'check'    => $row['check'],
                    'block'    => $row['block'],
                    'role'     => $row['role'],
                    'band'     => $row['band'],
                    'expected' => $row['expected'],
                    'observed' => $row['observed'],
                ];
                if ( isset( $row['note'] ) ) {
                    $failure['note'] = $row['note']; // only when the colours were judged against the union of the role's bands
                }
                $failures[] = $failure;
            }
        }
        $categories = [];
        $checked    = 0;
        $passed     = 0;
        foreach ( $count as $cat => $c ) {
            $categories[ $cat ] = [ 'checked' => $c['checked'], 'passed' => $c['passed'], 'score' => self::pct( $c['passed'], $c['checked'] ) ];
            $checked           += $c['checked'];
            $passed            += $c['passed'];
        }

        return [
            'page'         => $page,
            'kit'          => $this->kitId,
            'language'     => $this->languageId,
            'categories'   => $categories,
            'overall'      => [ 'checked' => $checked, 'passed' => $passed, 'score' => self::pct( $passed, $checked ) ],
            'failures'     => $failures,
            'unclassified' => $run['unclassified'],
            'ignored'      => $run['ignored'],
        ];
    }

    /**
     * Every check that was run, passed or not, in document order then check order (what score() counts).
     *
     * @return list<array{check:string,block:string,role:string,band:string,pass:bool,expected:string,observed:string,note?:string}>
     * @throws \InvalidArgumentException when the page does not parse
     */
    public function details( string $markup ): array
    {
        return $this->run( $markup )['results'];
    }

    /** @param array<string,mixed> $report */
    public static function json( array $report ): string
    {
        $json = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );

        return ( is_string( $json ) ? $json : '{}' ) . "\n";
    }

    /** @param array<string,mixed> $report */
    public static function text( array $report ): string
    {
        $score = static fn ( mixed $s ): string => is_float( $s ) || is_int( $s ) ? number_format( (float) $s, 1, '.', '' ) . '%' : 'n/a';
        $lines = [ sprintf( 'dogfood score %s kit=%s language=%s', $report['page'], $report['kit'], $report['language'] ) ];
        foreach ( $report['categories'] as $cat => $c ) {
            $lines[] = sprintf( '%s %d/%d %s', $cat, $c['passed'], $c['checked'], $score( $c['score'] ) );
        }
        $o       = $report['overall'];
        $lines[] = sprintf( 'overall %d/%d %s', $o['passed'], $o['checked'], $score( $o['score'] ) );
        foreach ( $report['failures'] as $f ) {
            $lines[] = sprintf( 'FAIL %s %s (%s on %s): expected %s, observed %s', $f['check'], $f['block'], $f['role'], $f['band'], $f['expected'], $f['observed'] ) . ( isset( $f['note'] ) ? ' [' . $f['note'] . ']' : '' );
        }
        foreach ( $report['unclassified'] as $u ) {
            $lines[] = sprintf( 'UNCLASSIFIED %s: %s', $u['block'], $u['reason'] );
        }

        return implode( "\n", $lines ) . "\n";
    }

    // ------------------------------------------------------------------------------------------------------------
    // The run: classify every block, pick its best role, collect the checks.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @return array{results:list<array{check:string,block:string,role:string,band:string,pass:bool,expected:string,observed:string}>,unclassified:list<array{block:string,reason:string}>,ignored:int}
     */
    private function run( string $markup ): array
    {
        $page         = Page::parse( $markup );
        $results      = [];
        $unclassified = [];
        $ignored      = 0;
        foreach ( $page->blocks( $this->language ) as $b ) {
            $class = $this->classify( $b, $page );
            if ( null === $class ) {
                continue;
            }
            if ( 'ignored' === $class['class'] ) {
                ++$ignored;
                continue;
            }
            if ( 'unclassified' === $class['class'] ) {
                $unclassified[] = [ 'block' => $b['key'], 'reason' => $class['reason'] ];
                continue;
            }
            if ( 'image' === $class['class'] ) {
                foreach ( $this->image( $b['attrs'] ) as [ $check, $pass, $exp, $obs ] ) {
                    $results[] = [ 'check' => $check, 'block' => $b['key'], 'role' => 'media', 'band' => $b['band'], 'pass' => $pass, 'expected' => self::cut( $exp ), 'observed' => self::cut( $obs ) ];
                }
                continue;
            }
            $best = $this->best( $class['class'], $b );
            if ( null === $best ) {
                $unclassified[] = [ 'block' => $b['key'], 'reason' => 'no kit role takes this block on the ' . $b['band'] . ' band' ];
                continue;
            }
            foreach ( $best['rows'] as [ $check, $pass, $exp, $obs ] ) {
                $row = [ 'check' => $check, 'block' => $b['key'], 'role' => $best['role'], 'band' => $best['band'], 'pass' => $pass, 'expected' => self::cut( $exp ), 'observed' => self::cut( $obs ) ];
                if ( null !== $best['note'] ) {
                    $row['note'] = $best['note'];
                }
                $results[] = $row;
            }
        }

        return [ 'results' => $results, 'unclassified' => $unclassified, 'ignored' => $ignored ];
    }

    /**
     * The candidate role with the most passing checks (a tie goes to the first), its checks, and the band it was judged on.
     *
     * @param array{key:string,name:string,attrs:array<string,mixed>,band:string,parentBand:string,hasContent:bool} $b
     *
     * The band is the one the block really sits on. When the colour checks were judged against the UNION of the role's bands,
     * `note` says so: that happens when the band is `unknown` (a colour outside the palette) or when the role does not take
     * the band the block sits on (for example a chip on an inverted panel); a button on `surface` is NOT such a case (the
     * adapter's own substitution, `light`). Otherwise `note` is null.
     *
     * @return ?array{role:string,band:string,rows:list<array{0:string,1:bool,2:string,3:string}>,note:?string}
     */
    private function best( string $class, array $b ): ?array
    {
        $family = str_starts_with( $class, 'rule' ) ? 'rule' : ( str_starts_with( $class, 'chip' ) ? 'chip' : $class );
        $best   = null;
        $bestN  = -1;
        foreach ( self::CANDIDATES[ $family ] as [ $role, $ground, $label ] ) {
            $taken = $this->taken( $b['name'], $role, $ground );
            $band  = $b['band'];
            if ( 'unknown' !== $band && in_array( $band, $taken, true ) ) {
                $bands = [ $band ];
                $union = false;
            } elseif ( 'divi/button' === $b['name'] && 'surface' === $band && in_array( 'light', $taken, true ) ) {
                $bands = [ 'light' ]; // the adapter's own substitution: a button has no surface band
                $union = false;
            } elseif ( null !== $ground && 'unknown' !== $band ) {
                continue; // a ground only exists on a light or surface band
            } elseif ( [] === $taken ) {
                continue;
            } else {
                $bands = $taken;
                $union = true;
            }
            $patches = [];
            foreach ( $bands as $on ) {
                $patch = $this->expected->attrs( $b['name'], $role, $on, $ground );
                if ( null !== $patch ) {
                    $patches[] = $patch;
                }
            }
            if ( [] === $patches ) {
                continue;
            }
            $rows = $this->evaluate( $class, $b, $patches );
            $n    = 0;
            foreach ( $rows as $row ) {
                $n += $row[1] ? 1 : 0;
            }
            if ( $n > $bestN ) {
                $bestN = $n;
                $best  = [
                    'role' => $label,
                    'band' => $b['band'],
                    'rows' => $rows,
                    'note' => $union ? self::unionNote( $b['band'], $label, $bands ) : null,
                ];
            }
        }

        return $best;
    }

    /** @param list<string> $bands */
    private static function unionNote( string $band, string $role, array $bands ): string
    {
        return ( 'unknown' === $band ? 'the band is unknown' : "$role does not take the $band band" )
            . ': colours judged against the union of its bands (' . implode( ', ', $bands ) . ')';
    }

    /**
     * The bands a role (with an optional ground) takes on a block.
     *
     * @return list<string>
     */
    private function taken( string $block, string $role, ?string $ground ): array
    {
        if ( null === $ground ) {
            return $this->expected->bands( $block, $role );
        }
        $out = [];
        foreach ( self::GROUND_BANDS as $band ) {
            if ( null !== $this->expected->attrs( $block, $role, $band, $ground ) ) {
                $out[] = $band;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Classification
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param array{key:string,name:string,attrs:array<string,mixed>,band:string,parentBand:string,hasContent:bool} $b
     *
     * @return ?array{class:string,reason:string} null = a block this scorer does not look at
     */
    private function classify( array $b, Page $page ): ?array
    {
        $a    = $b['attrs'];
        $name = $b['name'];
        $is   = static fn ( string $class, string $reason = '' ): array => [ 'class' => $class, 'reason' => $reason ];
        switch ( $name ) {
            case 'divi/button':
                return $is( 'button' );
            case 'divi/divider':
                return 'off' === ( self::at( $a, 'divider.advanced.line.desktop.value.show' ) ) ? $is( 'ignored' ) : $is( 'rule-divider' );
            case 'divi/accordion':
            case 'divi/toggle':
                return $is( 'item' );
            case 'divi/contact-form':
                return $is( 'form' );
            case 'divi/image':
                $c = self::corners( self::at( $a, 'image.decoration.border.desktop.value.radius' ) );
                $c = array_map( static fn ( $v ) => $v ?? 0.0, $c );
                if ( self::cornersEqualBelowShape( $c ) ) {
                    return $is( 'image' );
                }

                return $is( 'unclassified', 'shaped image (corners differ or reach ' . (int) self::SHAPE_RADIUS . 'px): a shape decision, not a corner decision' );
            case 'divi/icon':
                if ( ! Page::isClear( self::at( $a, 'module.decoration.background.desktop.value.color' ) ) ) {
                    return $is( 'chip-icon' );
                }
                $why = self::decoration( $a );

                return [] === $why ? $is( 'ignored' ) : $is( 'unclassified', 'icon with ' . implode( ' and ', $why ) . ' but no background colour' );
            case 'divi/group':
            case 'divi/column':
                break;
            default:
                return null;
        }
        $border = self::arr( self::at( $a, 'module.decoration.border.desktop.value' ) );
        $sides  = self::sides( $border );
        $fill   = self::fills( $a );
        $frame  = self::anyVisible( $sides );
        $radius = self::anyRadius( $border );
        $others = ! self::visible( $sides['right'] ) && ! self::visible( $sides['bottom'] ) && ! self::visible( $sides['left'] );
        if ( self::visible( $sides['top'] ) && $others && ! $fill ) {
            return $is( 'rule-top' );
        }
        if ( 'divi/group' === $name && ! $fill && $frame ) {
            $inside = $page->descendants( $b['key'] );
            if ( [] === array_intersect( $inside, [ 'divi/heading', 'divi/button', 'divi/image' ] ) ) {
                return $is( 'chip-tag' );
            }
        }
        // framed: a frame with a ground or content, or a radius around content. A filled pill with a radius, no frame and
        // no heading or button (a badge) is a shape, not a card (the brief's table lists it as unclassified).
        if ( ( $frame && ( $fill || $b['hasContent'] ) ) || ( $radius && $b['hasContent'] ) ) {
            return $is( 'framed' );
        }
        $why = self::decoration( $a );
        if ( [] === $why ) {
            return $is( 'ignored' );
        }
        $content = $b['hasContent'] ? 'holds a heading or button' : 'holds no heading or button';

        return $is( 'unclassified', 'carries ' . implode( ' and ', $why ) . ' and ' . $content . ': no rule, tag, card or panel fits' );
    }

    /**
     * What a block's own decoration carries: a background, a border (a side wider than 0) and/or a radius.
     *
     * @param array<string,mixed> $a
     *
     * @return list<string>
     */
    private static function decoration( array $a ): array
    {
        $border = self::arr( self::at( $a, 'module.decoration.border.desktop.value' ) );
        $out    = [];
        if ( self::fills( $a ) ) {
            $out[] = 'a background';
        }
        if ( self::anyVisible( self::sides( $border ) ) ) {
            $out[] = 'a border';
        }
        if ( self::anyRadius( $border ) ) {
            $out[] = 'a radius';
        }

        return $out;
    }

    /**
     * RuleChipAudit::fills(): a background colour that is not fully transparent, an enabled gradient or an image.
     *
     * @param array<string,mixed> $a
     */
    private static function fills( array $a ): bool
    {
        $v = self::at( $a, 'module.decoration.background.desktop.value' );
        if ( ! is_array( $v ) ) {
            return false;
        }
        if ( ! Page::isClear( $v['color'] ?? null ) ) {
            return true;
        }
        if ( is_array( $v['gradient'] ?? null ) && 'on' === ( $v['gradient']['enabled'] ?? null ) ) {
            return true;
        }

        return is_array( $v['image'] ?? null ) && '' !== (string) ( $v['image']['url'] ?? '' );
    }

    // ------------------------------------------------------------------------------------------------------------
    // The checks. Each returns rows of [check name, pass, expected text, observed text], only for checks whose subject exists.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param array{key:string,name:string,attrs:array<string,mixed>,band:string,parentBand:string,hasContent:bool} $b
     * @param list<array<string,mixed>>                                                                              $patches
     *
     * @return list<array{0:string,1:bool,2:string,3:string}>
     */
    private function evaluate( string $class, array $b, array $patches ): array
    {
        $a = $b['attrs'];

        return match ( $class ) {
            'button'       => $this->button( $a, $patches ),
            'rule-divider' => $this->ruleDivider( $a, $patches ),
            'rule-top'     => $this->ruleTop( $a, $patches ),
            'chip-tag'     => $this->chipTag( $a, $patches ),
            'chip-icon'    => $this->chipIcon( $a, $patches ),
            'framed'       => $this->framed( $b, $patches ),
            'item'         => $this->item( $a, $patches ),
            'form'         => $this->form( $a, $patches ),
            default        => [],
        };
    }

    /** @return list<array{0:string,1:bool,2:string,3:string}> */
    private function button( array $a, array $patches ): array
    {
        $B   = 'button.decoration.border.desktop.value';
        $F   = 'button.decoration.background.desktop.value.color';
        $own = self::arr( self::at( $a, $B ) );
        $rows = [];
        $rows[] = self::radiusRow( 'button.radius', $own, $patches, $B, true );
        // Divi draws `.et_pb_button{border:2px solid}`: a side with no width written is 2px, with no style it is solid.
        $rows[] = self::edgeRow( 'button.edge', $own, $patches, $B, self::SIDES, true );
        if ( self::anyVisible( self::sides( self::buttonBorder( $own ) ) ) ) {
            $rows[] = self::styleRow( 'button.edge-style', $own, $patches, $B, self::SIDES, true );
        }
        $colour = self::buttonFill( $a, 'button.decoration.background.desktop.value' );
        $kind   = Page::isClear( $colour ) ? 'transparent' : 'filled';
        $rows[] = self::union( 'button.fill', $patches, static function ( array $p ) use ( $F, $kind ): array {
            $exp = Page::isClear( self::at( $p, $F ) ) ? 'transparent' : 'filled';
            return [ $exp === $kind, $exp, $kind ];
        } );
        // The colour is judged only when the role has a fill to compare it with: a filled button where the role draws none
        // already fails `button.fill`, and one defect fails one check.
        $roleFills = false;
        foreach ( $patches as $p ) {
            $roleFills = $roleFills || ! Page::isClear( self::at( $p, $F ) );
        }
        if ( 'filled' === $kind && $roleFills ) {
            $rows[] = self::colourRow( 'button.fill-colour', $colour, $patches, $F );
        }

        return $rows;
    }

    /** @return list<array{0:string,1:bool,2:string,3:string}> */
    private function ruleDivider( array $a, array $patches ): array
    {
        $L = 'divider.advanced.line.desktop.value';

        return [
            self::union( 'rule.width', $patches, static function ( array $p ) use ( $a, $L ): array {
                $o = self::len( self::at( $a, $L . '.weight' ) );
                $e = self::len( self::at( $p, $L . '.weight' ) );
                return [ self::lenEq( $o, $e ), self::fmtLen( $e ), self::fmtLen( $o ) ];
            } ),
            self::union( 'rule.style', $patches, static function ( array $p ) use ( $a, $L ): array {
                $o = self::str( self::at( $a, $L . '.style' ) );
                $e = self::str( self::at( $p, $L . '.style' ) );
                return [ $o === $e, $e ?? 'absent', $o ?? 'absent' ];
            } ),
            self::colourRow( 'rule.colour', self::at( $a, $L . '.color' ), $patches, $L . '.color' ),
        ];
    }

    /** @return list<array{0:string,1:bool,2:string,3:string}> */
    private function ruleTop( array $a, array $patches ): array
    {
        $M   = 'module.decoration.border.desktop.value';
        $own = self::topEdge( self::arr( self::at( $a, $M ) ) );

        return [
            self::union( 'rule.width', $patches, static function ( array $p ) use ( $own, $M ): array {
                $e = self::topEdge( self::arr( self::at( $p, $M ) ) );
                return [ self::lenEq( $own['width'], $e['width'] ), self::fmtLen( $e['width'] ), self::fmtLen( $own['width'] ) ];
            } ),
            self::union( 'rule.style', $patches, static function ( array $p ) use ( $own, $M ): array {
                $e = self::topEdge( self::arr( self::at( $p, $M ) ) );
                return [ $own['style'] === $e['style'], $e['style'] ?? 'absent', $own['style'] ?? 'absent' ];
            } ),
            self::union( 'rule.colour', $patches, static function ( array $p ) use ( $own, $M ): array {
                $e = self::topEdge( self::arr( self::at( $p, $M ) ) );
                return [ self::colourIn( $own['colour'], [ $e['colour'] ] ), $e['colour'] ?? 'absent', $own['colour'] ?? 'absent' ];
            } ),
        ];
    }

    /** @return list<array{0:string,1:bool,2:string,3:string}> */
    private function chipTag( array $a, array $patches ): array
    {
        $M    = 'module.decoration.border.desktop.value';
        $own  = self::arr( self::at( $a, $M ) );
        $rows = [
            self::radiusRow( 'chip.radius', $own, $patches, $M ),
            self::edgeRow( 'chip.frame', $own, $patches, $M ),
        ];
        if ( self::anyVisible( self::sides( $own ) ) ) {
            $rows[] = self::styleRow( 'chip.frame-style', $own, $patches, $M, self::SIDES );
        }
        $rows[] = self::frameColourRow( 'chip.frame-colour', $own, $patches, $M, self::SIDES );

        return $rows;
    }

    /** @return list<array{0:string,1:bool,2:string,3:string}> */
    private function chipIcon( array $a, array $patches ): array
    {
        $M = 'module.decoration.border.desktop.value';

        return [
            self::radiusRow( 'chip.radius', self::arr( self::at( $a, $M ) ), $patches, $M ),
            self::colourRow( 'chip.fill-colour', self::at( $a, 'module.decoration.background.desktop.value.color' ), $patches, 'module.decoration.background.desktop.value.color' ),
            self::colourRow( 'chip.glyph-colour', self::at( $a, 'icon.advanced.color.desktop.value' ), $patches, 'icon.advanced.color.desktop.value' ),
        ];
    }

    /**
     * @param array{key:string,name:string,attrs:array<string,mixed>,band:string,parentBand:string,hasContent:bool} $b
     *
     * @return list<array{0:string,1:bool,2:string,3:string}>
     */
    private function framed( array $b, array $patches ): array
    {
        $a      = $b['attrs'];
        $M      = 'module.decoration.border.desktop.value';
        $own    = self::arr( self::at( $a, $M ) );
        $styles = self::arr( $own['styles'] ?? null );
        $rule   = isset( $styles['top'] ) && is_array( $styles['top'] ) && self::isTopRule( $styles );
        $subset = $rule ? [ 'right', 'bottom', 'left' ] : self::SIDES;
        $sides  = self::sides( $own );
        $rows   = [
            self::radiusRow( 'framed.radius', $own, $patches, $M ),
            self::edgeRow( 'framed.frame', $own, $patches, $M, $subset ),
        ];
        $visible = false;
        foreach ( $subset as $s ) {
            $visible = $visible || self::visible( $sides[ $s ] );
        }
        if ( $visible ) {
            $rows[] = self::styleRow( 'framed.frame-style', $own, $patches, $M, $subset );
            $rows[] = self::frameColourRow( 'framed.frame-colour', $own, $patches, $M, $subset );
        }
        $F      = 'module.decoration.background.desktop.value.color';
        $rows[] = self::colourRow( 'framed.fill-colour', self::at( $a, $F ), $patches, $F, $this->fillAliases );
        if ( $rule ) {
            $width = self::len( self::eff( $styles, 'top', 'width' ) );
            $style = self::str( self::eff( $styles, 'top', 'style' ) );
            $pairs = [];
            foreach ( [ 'rule', 'rule-strong' ] as $role ) {
                $band  = in_array( $b['parentBand'], Expected::BANDS, true ) ? $b['parentBand'] : 'light';
                $patch = $this->expected->attrs( $b['name'], $role, $band );
                if ( null !== $patch ) {
                    $edge    = self::topEdge( self::arr( self::at( $patch, $M ) ) );
                    $pairs[] = [ $edge['width'], $edge['style'] ];
                }
            }
            $ok  = false;
            $exp = [];
            foreach ( $pairs as [ $w, $s ] ) {
                $ok                                   = $ok || ( self::lenEq( $width, $w ) && $style === $s );
                $exp[ self::fmtLen( $w ) . ' ' . ( $s ?? 'absent' ) ] = true;
            }
            $rows[] = [ 'framed.top-rule', $ok, [] === $exp ? 'a rule width' : implode( ' | ', array_keys( $exp ) ), self::fmtLen( $width ) . ' ' . ( $style ?? 'absent' ) ];
        }

        return $rows;
    }

    /** @return list<array{0:string,1:bool,2:string,3:string}> */
    private function item( array $a, array $patches ): array
    {
        $M    = 'module.decoration.border.desktop.value';
        $own  = self::arr( self::at( $a, $M ) );
        $rows = [
            self::radiusRow( 'item.radius', $own, $patches, $M ),
            self::edgeRow( 'item.frame', $own, $patches, $M ),
        ];
        if ( self::anyVisible( self::sides( $own ) ) ) {
            $rows[] = self::styleRow( 'item.frame-style', $own, $patches, $M, self::SIDES );
        }
        $open   = 'openToggle.decoration.background.desktop.value.color';
        $closed = 'closedToggle.decoration.background.desktop.value.color';
        $oo     = self::at( $a, $open );
        $oc     = self::at( $a, $closed );
        $alias  = $this->fillAliases;
        $rows[] = self::union( 'item.fill-colour', $patches, static function ( array $p ) use ( $open, $closed, $oo, $oc, $alias ): array {
            $eo = self::str( self::at( $p, $open ) );
            $ec = self::str( self::at( $p, $closed ) );
            return [
                self::colourIn( $oo, [ $eo ], $alias ) && self::colourIn( $oc, [ $ec ], $alias ),
                $eo === $ec ? ( $eo ?? 'absent' ) : 'open ' . ( $eo ?? 'absent' ) . ', closed ' . ( $ec ?? 'absent' ),
                'open ' . self::colourText( $oo ) . ', closed ' . self::colourText( $oc ),
            ];
        } );

        return $rows;
    }

    /** @return list<array{0:string,1:bool,2:string,3:string}> */
    private function form( array $a, array $patches ): array
    {
        $FB   = 'field.decoration.border.desktop.value';
        $BB   = 'button.decoration.border.desktop.value';
        $fo   = self::arr( self::at( $a, $FB ) );
        $bo   = self::arr( self::at( $a, $BB ) );
        $rows = [
            self::radiusRow( 'form.field-radius', $fo, $patches, $FB ),
            self::edgeRow( 'form.field-frame', $fo, $patches, $FB ),
        ];
        if ( self::anyVisible( self::sides( $fo ) ) ) {
            $rows[] = self::styleRow( 'form.field-frame-style', $fo, $patches, $FB, self::SIDES );
        }
        $rows[] = self::colourRow( 'form.field-fill-colour', self::at( $a, 'field.decoration.background.desktop.value.color' ), $patches, 'field.decoration.background.desktop.value.color', $this->fillAliases );
        $rows[] = self::radiusRow( 'form.button-radius', $bo, $patches, $BB, true );
        $rows[] = self::edgeRow( 'form.button-edge', $bo, $patches, $BB, self::SIDES, true );
        if ( self::anyVisible( self::sides( self::buttonBorder( $bo ) ) ) ) {
            $rows[] = self::styleRow( 'form.button-edge-style', $bo, $patches, $BB, self::SIDES, true );
        }
        $F     = 'button.decoration.background.desktop.value.color';
        $subColour = self::buttonFill( $a, 'button.decoration.background.desktop.value' );
        $fills     = ! Page::isClear( $subColour );
        foreach ( $patches as $p ) {
            $fills = $fills || ! Page::isClear( self::at( $p, $F ) ); // a transparent submit where the kit fills it fails here
        }
        if ( $fills ) {
            $rows[] = self::colourRow( 'form.button-fill-colour', $subColour, $patches, $F );
        }

        return $rows;
    }

    /**
     * image.radius: all four corners equal the kit's media radius OR all are 0px (a flush image, as the library ships).
     *
     * @param array<string,mixed> $a
     *
     * @return list<array{0:string,1:bool,2:string,3:string}>
     */
    private function image( array $a ): array
    {
        $media   = StyleKits::valueToken( $this->kit, 'radius-media' );
        $want    = self::len( is_array( $media ) ? ( $media['topLeft'] ?? null ) : null ) ?? 0.0;
        $corners = array_map( static fn ( $v ) => $v ?? 0.0, self::corners( self::at( $a, 'image.decoration.border.desktop.value.radius' ) ) );
        $ok      = false;
        foreach ( [ $want, 0.0 ] as $target ) {
            $same = true;
            foreach ( $corners as $c ) {
                $same = $same && self::lenEq( $c, $target );
            }
            $ok = $ok || $same;
        }
        $exp = self::fmtLen( $want );

        return [ [ 'image.radius', $ok, 0.0 === $want ? '0px' : $exp . ' or 0px', self::fmtCorners( $corners ) ] ];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Check building blocks
    // ------------------------------------------------------------------------------------------------------------

    /**
     * A check against several patches (the union of a role's bands): it passes when it passes against any one.
     *
     * @param list<array<string,mixed>>                       $patches
     * @param callable(array<string,mixed>):array{0:bool,1:string,2:string} $f
     *
     * @return array{0:string,1:bool,2:string,3:string}
     */
    private static function union( string $check, array $patches, callable $f ): array
    {
        $pass = false;
        $exps = [];
        $obs  = '';
        foreach ( $patches as $i => $p ) {
            [ $ok, $e, $o ] = $f( $p );
            $pass           = $pass || $ok;
            $exps[ $e ]     = true;
            if ( 0 === $i ) {
                $obs = $o;
            }
        }

        return [ $check, $pass, implode( ' | ', array_keys( $exps ) ), $obs ];
    }

    /**
     * @param array<string,mixed>       $own
     * @param list<array<string,mixed>> $patches
     *
     * @return array{0:string,1:bool,2:string,3:string}
     */
    private static function radiusRow( string $check, array $own, array $patches, string $path, bool $strict = false ): array
    {
        // An absent corner is 0px (Divi's square default) on everything but a button, which has a non-zero default of its own.
        $o = self::corners( $own['radius'] ?? null, ! $strict );

        return self::union( $check, $patches, static function ( array $p ) use ( $o, $path, $strict ): array {
            $e = self::corners( self::at( $p, $path . '.radius' ), ! $strict );
            $ok = true;
            foreach ( $e as $i => $v ) {
                $ok = $ok && self::lenEq( $o[ $i ], $v );
            }
            return [ $ok, self::fmtCorners( $e ), self::fmtCorners( $o ) ];
        } );
    }

    /**
     * The effective widths of the compared sides.
     *
     * @param array<string,mixed>       $own
     * @param list<array<string,mixed>> $patches
     * @param list<string>              $subset
     *
     * @return array{0:string,1:bool,2:string,3:string}
     */
    private static function edgeRow( string $check, array $own, array $patches, string $path, array $subset = self::SIDES, bool $button = false ): array
    {
        $o = self::sides( $button ? self::buttonBorder( $own ) : $own );

        return self::union( $check, $patches, static function ( array $p ) use ( $o, $path, $subset, $button ): array {
            $pb = self::arr( self::at( $p, $path ) );
            $e  = self::sides( $button ? self::buttonBorder( $pb ) : $pb );
            $ok = true;
            foreach ( $subset as $s ) {
                $ok = $ok && self::lenEq( $o[ $s ], $e[ $s ] );
            }
            return [ $ok, self::fmtSides( $e, $subset ), self::fmtSides( $o, $subset ) ];
        } );
    }

    /**
     * The style of the visible sides (when a patch has none, its `all` style).
     *
     * @param array<string,mixed>       $own
     * @param list<array<string,mixed>> $patches
     * @param list<string>              $subset
     *
     * @return array{0:string,1:bool,2:string,3:string}
     */
    private static function styleRow( string $check, array $own, array $patches, string $path, array $subset, bool $button = false ): array
    {
        $own = $button ? self::buttonBorder( $own ) : $own;
        $o   = self::visibleValues( $own, self::sides( $own ), $subset, 'style' );

        return self::union( $check, $patches, static function ( array $p ) use ( $o, $path, $subset, $button ): array {
            $pb = self::arr( self::at( $p, $path ) );
            $pb = $button ? self::buttonBorder( $pb ) : $pb;
            $e  = self::visibleValues( $pb, self::sides( $pb ), $subset, 'style' );
            if ( [] === $e ) {
                $e = [ self::str( self::eff( self::arr( $pb['styles'] ?? null ), 'all', 'style' ) ) ?? 'absent' ];
            }
            return [ $o === $e, implode( '/', $e ), implode( '/', $o ) ];
        } );
    }

    /**
     * The colour of the visible sides among $subset (a side's own colour, else `styles.all.color`): every one must be a colour
     * the role's patch draws on its visible sides (D4). One implementation for a tag's frame and a framed block's. With no
     * visible side nothing can pass (the frame width check fails first).
     *
     * @param array<string,mixed>       $own
     * @param list<array<string,mixed>> $patches
     * @param list<string>              $subset
     *
     * @return array{0:string,1:bool,2:string,3:string}
     */
    private static function frameColourRow( string $check, array $own, array $patches, string $path, array $subset ): array
    {
        $seen = self::visibleValues( $own, self::sides( $own ), $subset, 'colour' );

        return self::union( $check, $patches, static function ( array $p ) use ( $path, $seen, $subset ): array {
            $pb  = self::arr( self::at( $p, $path ) );
            $exp = self::visibleValues( $pb, self::sides( $pb ), $subset, 'colour' );
            $ok  = [] !== $seen;
            foreach ( $seen as $c ) {
                $ok = $ok && 'absent' !== $c && self::colourIn( $c, $exp );
            }
            return [ $ok, implode( '/', $exp ), [] === $seen ? 'no visible side' : implode( '/', $seen ) ];
        } );
    }

    /**
     * D4: the observed colour is one of the colours the role's patch writes at that path.
     *
     * @param list<array<string,mixed>> $patches
     *
     * @return array{0:string,1:bool,2:string,3:string}
     */
    private static function colourRow( string $check, mixed $observed, array $patches, string $path, array $aliases = [] ): array
    {
        return self::union( $check, $patches, static function ( array $p ) use ( $observed, $path, $aliases ): array {
            $e = self::str( self::at( $p, $path ) );
            return [ self::colourIn( $observed, [ $e ], $aliases ), $e ?? 'absent', self::colourText( $observed ) ];
        } );
    }

    /**
     * @param list<?string>             $expected
     * @param array<string,list<string>> $aliases expected colour key => other colour keys accepted for it
     */
    private static function colourIn( mixed $observed, array $expected, array $aliases = [] ): bool
    {
        if ( ! is_string( $observed ) ) {
            return false;
        }
        $k = Page::colourKey( $observed );
        if ( null === $k ) {
            return false;
        }
        foreach ( $expected as $e ) {
            $ek = null === $e ? null : Page::colourKey( $e );
            if ( null !== $ek && ( $ek === $k || in_array( $k, $aliases[ $ek ] ?? [], true ) ) ) {
                return true;
            }
        }

        return false;
    }

    private static function colourText( mixed $c ): string
    {
        return is_string( $c ) && '' !== $c ? $c : 'absent';
    }

    // ------------------------------------------------------------------------------------------------------------
    // Values
    // ------------------------------------------------------------------------------------------------------------

    /** @return mixed the value at a dot path, or null when any step is missing */
    private static function at( mixed $a, string $path ): mixed
    {
        foreach ( explode( '.', $path ) as $k ) {
            if ( ! is_array( $a ) || ! array_key_exists( $k, $a ) ) {
                return null;
            }
            $a = $a[ $k ];
        }

        return $a;
    }

    /** @return array<string,mixed> */
    private static function arr( mixed $v ): array
    {
        return is_array( $v ) ? $v : [];
    }

    private static function str( mixed $v ): ?string
    {
        return is_string( $v ) && '' !== $v ? $v : null;
    }

    /** A length in px (a float), a string for anything else (compared as written, lower-case), null when absent. */
    private static function len( mixed $v ): float|string|null
    {
        if ( is_int( $v ) || is_float( $v ) ) {
            return (float) $v;
        }
        if ( ! is_string( $v ) || '' === trim( $v ) ) {
            return null;
        }
        $t = trim( $v );
        if ( 1 === preg_match( '/^(-?(?:\d+(?:\.\d+)?|\.\d+))\s*(?:px)?$/i', $t, $m ) ) {
            return (float) $m[1];
        }

        return strtolower( $t );
    }

    private static function fmtLen( float|string|null $v ): string
    {
        if ( null === $v ) {
            return 'absent';
        }
        if ( is_string( $v ) ) {
            return $v;
        }

        return rtrim( rtrim( number_format( $v, 3, '.', '' ), '0' ), '.' ) . 'px';
    }

    private static function lenEq( float|string|null $a, float|string|null $b ): bool
    {
        if ( is_float( $a ) && is_float( $b ) ) {
            return abs( $a - $b ) < 1e-6;
        }

        return $a === $b;
    }

    /** @return list<float|string|null> topLeft, topRight, bottomRight, bottomLeft */
    private static function corners( mixed $radius, bool $absentIsZero = false ): array
    {
        $r   = self::arr( $radius );
        $out = [ self::len( $r['topLeft'] ?? null ), self::len( $r['topRight'] ?? null ), self::len( $r['bottomRight'] ?? null ), self::len( $r['bottomLeft'] ?? null ) ];

        return $absentIsZero ? array_map( static fn ( $v ) => $v ?? 0.0, $out ) : $out;
    }

    /** @param list<float|string|null> $c */
    private static function fmtCorners( array $c ): string
    {
        $t = array_map( [ self::class, 'fmtLen' ], $c );

        return 1 === count( array_unique( $t ) ) ? $t[0] : implode( '/', $t );
    }

    /** @param list<float|string> $c four corners, absent already read as 0 */
    private static function cornersEqualBelowShape( array $c ): bool
    {
        foreach ( $c as $v ) {
            if ( ! is_float( $v ) || ! self::lenEq( $v, $c[0] ) || $v >= self::SHAPE_RADIUS || $v < 0 ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $border */
    private static function anyRadius( array $border ): bool
    {
        foreach ( self::corners( $border['radius'] ?? null ) as $c ) {
            if ( is_string( $c ) || ( is_float( $c ) && $c > 0 ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is `styles.top` a rule on a framed block rather than one side of the frame? With `styles.all.width` present, a top that
     * differs from it is a rule. Without it (a frame written per side) the top is a rule only when it differs from BOTH the
     * right and the left side: a top equal to the other sides is the frame, and so is an underline's 0px top.
     *
     * @param array<string,mixed> $styles
     */
    private static function isTopRule( array $styles ): bool
    {
        $top = self::len( self::eff( $styles, 'top', 'width' ) ) ?? 0.0;
        if ( null !== self::len( is_array( $styles['all'] ?? null ) ? ( $styles['all']['width'] ?? null ) : null ) ) {
            return ! self::lenEq( $top, self::len( self::eff( $styles, 'all', 'width' ) ) ?? 0.0 );
        }
        $right = self::len( self::eff( $styles, 'right', 'width' ) ) ?? 0.0;
        $left  = self::len( self::eff( $styles, 'left', 'width' ) ) ?? 0.0;

        return ! self::lenEq( $top, $right ) && ! self::lenEq( $top, $left );
    }

    /** `styles.<side>.<key>` if present, else `styles.all.<key>`. */
    private static function eff( array $styles, string $side, string $key ): mixed
    {
        $v = is_array( $styles[ $side ] ?? null ) ? ( $styles[ $side ][ $key ] ?? null ) : null;
        if ( null === $v && 'all' !== $side ) {
            $v = is_array( $styles['all'] ?? null ) ? ( $styles['all'][ $key ] ?? null ) : null;
        }

        return $v;
    }

    /**
     * The effective width of each side (absent = 0).
     *
     * @param array<string,mixed> $border
     *
     * @return array<string,float|string>
     */
    private static function sides( array $border ): array
    {
        $styles = self::arr( $border['styles'] ?? null );
        $out    = [];
        foreach ( self::SIDES as $s ) {
            $out[ $s ] = self::len( self::eff( $styles, $s, 'width' ) ) ?? 0.0;
        }

        return $out;
    }

    /**
     * A button's border with Divi's own defaults filled in: every side with no width written is 2px and every side with no
     * style is solid (`.et_pb_button{border:2px solid}`, Divi 5.15.1; the contact form's submit is a `.et_pb_button` too).
     * The adapters write `width: 0px` explicitly for a kit with no border, so a kit-built button is unchanged.
     *
     * @param array<string,mixed> $border
     *
     * @return array<string,mixed>
     */
    private static function buttonBorder( array $border ): array
    {
        $styles = self::arr( $border['styles'] ?? null );
        foreach ( self::SIDES as $s ) {
            if ( null === self::len( self::eff( $styles, $s, 'width' ) ) ) {
                $styles[ $s ]          = self::arr( $styles[ $s ] ?? null );
                $styles[ $s ]['width'] = '2px';
            }
            if ( null === self::str( self::eff( $styles, $s, 'style' ) ) ) {
                $styles[ $s ]          = self::arr( $styles[ $s ] ?? null );
                $styles[ $s ]['style'] = 'solid';
            }
        }
        $border['styles'] = $styles;

        return $border;
    }

    /**
     * The fill colour of a button's background, or the word `gradient` when a gradient is enabled (it paints, but its colour
     * cannot be judged: the button is filled and `fill-colour` fails).
     *
     * @param array<string,mixed> $a
     */
    private static function buttonFill( array $a, string $path ): mixed
    {
        $grad = self::at( $a, $path . '.gradient' );
        if ( is_array( $grad ) && 'on' === ( $grad['enabled'] ?? null ) ) {
            return 'gradient';
        }

        return self::at( $a, $path . '.color' );
    }

    private static function visible( float|string $w ): bool
    {
        return is_string( $w ) || $w > 0;
    }

    /** @param array<string,float|string> $sides */
    private static function anyVisible( array $sides ): bool
    {
        foreach ( $sides as $w ) {
            if ( self::visible( $w ) ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,float|string> $sides @param list<string> $subset */
    private static function fmtSides( array $sides, array $subset ): string
    {
        $t = [];
        foreach ( $subset as $s ) {
            $t[ $s ] = self::fmtLen( $sides[ $s ] );
        }
        if ( 1 === count( array_unique( $t ) ) ) {
            return reset( $t );
        }
        $parts = [];
        foreach ( $t as $s => $v ) {
            $parts[] = $s . ' ' . $v;
        }

        return implode( ', ', $parts );
    }

    /**
     * The unique style (or colour) of the visible sides among $subset, in side order; an unset one is "absent".
     *
     * @param array<string,mixed>      $border
     * @param array<string,float|string> $sides
     * @param list<string>             $subset
     *
     * @return list<string>
     */
    private static function visibleValues( array $border, array $sides, array $subset, string $what ): array
    {
        $styles = self::arr( $border['styles'] ?? null );
        $out    = [];
        foreach ( $subset as $s ) {
            if ( ! self::visible( $sides[ $s ] ) ) {
                continue;
            }
            $v = self::str( self::eff( $styles, $s, 'style' === $what ? 'style' : 'color' ) ) ?? 'absent';
            if ( ! in_array( $v, $out, true ) ) {
                $out[] = $v;
            }
        }
        if ( 'style' === $what ) {
            sort( $out );
        }

        return $out;
    }

    /**
     * The top edge of a border: effective width, style and colour of the top side.
     *
     * @param array<string,mixed> $border
     *
     * @return array{width:float|string|null,style:?string,colour:?string}
     */
    private static function topEdge( array $border ): array
    {
        $styles = self::arr( $border['styles'] ?? null );

        return [
            'width'  => self::len( self::eff( $styles, 'top', 'width' ) ),
            'style'  => self::str( self::eff( $styles, 'top', 'style' ) ),
            'colour' => self::str( self::eff( $styles, 'top', 'color' ) ),
        ];
    }

    /** @return float|string 1 decimal, or "n/a" when nothing was checked */
    private static function pct( int $passed, int $checked ): float|string
    {
        return 0 === $checked ? 'n/a' : round( 100 * $passed / $checked, 1 );
    }

    private static function cut( string $s ): string
    {
        return strlen( $s ) > self::MAX_TEXT ? substr( $s, 0, self::MAX_TEXT - 3 ) . '...' : $s;
    }
}
