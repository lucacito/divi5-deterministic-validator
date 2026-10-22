<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * html_to_divi: supplied HTML in, a Divi 5 layout and an honest report out. Deterministic (the same HTML always gives
 * the same bytes), no network, no code from the source ever reaches the result. The result is then checked by the
 * same gate as every other write (LayoutGate); this class only builds it and measures what it kept.
 */
final class HtmlToDivi
{
    public const STYLES_NONE  = 'none';
    public const STYLES_BASIC = 'basic';

    /** report.styles lists at most this many fonts and cuts every string at MAX_STRING characters. */
    public const MAX_FONTS  = 20;
    public const MAX_STRING = 200;

    /** report.styles.notes: the linked stylesheets and @imports that were not read (the converter never fetches CSS). */
    public const NOTE_EXTERNAL = '%d external stylesheet(s) not read: the converter never fetches CSS, so colours and fonts may differ.';

    /** report.styles.notes when a self-check of the style path refused a value: the page is converted as with styles=none. */
    public const NOTE_STYLES_FAILED = 'The styles of this page could not be written safely: the page was converted without styles.';

    /**
     * @param array{image_mode?:string, styles?:string} $options
     * @param ?array<string,mixed>                      $manifest    the image pack manifest (ImagePack::manifest())
     * @param ?callable(string):bool                    $isSiteImage whether a URL is an image of this site's Media Library
     * @param ?callable(string):string                  $resolve     turns {{aied:image:token}} into a real URL (identity in tests)
     * @return array<string,mixed> { ok, error?, layout, report }
     */
    public static function convert( string $html, array $options = [], ?array $manifest = null, ?callable $isSiteImage = null, ?callable $resolve = null ): array
    {
        $mode = self::imageMode( (string) ( $options['image_mode'] ?? HtmlImages::MODE_PLACEHOLDER ) );
        if ( self::STYLES_NONE === ( $options['styles'] ?? self::STYLES_BASIC ) ) {
            return self::run( $html, $mode, false, $manifest, $isSiteImage, $resolve );
        }
        try {
            return self::run( $html, $mode, true, $manifest, $isSiteImage, $resolve );
        } catch ( \InvalidArgumentException ) {
            // A self-check of the converter or the writer refused a value. Styles are an enhancement: the page is converted exactly as with
            // styles=none and the report says so in one fixed sentence (the exception's message is never handed over).
            $result = self::run( $html, $mode, false, $manifest, $isSiteImage, $resolve );
            if ( true === ( $result['ok'] ?? false ) ) {
                $result['report']['styles'] = [
                    'carried'            => [],
                    'ignored'            => [],
                    'dropped_unreadable' => [],
                    'dropped_total'      => 0,
                    'fonts'              => [],
                    'clamped'            => 0,
                    'notes'              => [ self::NOTE_STYLES_FAILED ],
                    'external'           => 0,
                ];
            }

            return $result;
        }
    }

    /** One conversion: $basic = styles=basic. @return array<string,mixed> */
    private static function run( string $html, string $mode, bool $basic, ?array $manifest, ?callable $isSiteImage, ?callable $resolve ): array
    {
        $source = HtmlSource::parse( $html );
        if ( null !== $source['error'] || ! $source['body'] instanceof \DOMElement ) {
            return [ 'ok' => false, 'error' => $source['error'] ?? 'The HTML has no content.', 'layout' => '', 'report' => self::emptyReport( $source ) ];
        }
        /** @var \DOMElement $body */
        $body = $source['body'];

        $plan = HtmlMapper::map( $body, $basic );
        if ( array() === $plan['sections'] ) {
            return [ 'ok' => false, 'error' => 'Nothing to convert: the page has no visible text, images or links.', 'layout' => '', 'report' => self::emptyReport( $source ) + [ 'flagged' => $plan['flagged'] ] ];
        }

        $decided     = null;
        $styleReport = null;
        if ( $basic ) {
            $computed    = HtmlStyles::resolve( $body, (string) $source['css'], (array) $source['inline'], (array) $source['presentational'], HtmlStyles::document( $source ) );
            $blocks      = HtmlStyleBlocks::build( $plan, $computed );
            $decision    = HtmlReadability::decide( $blocks );
            $decided     = $decision['sections'];
            $styleReport = self::styleReport( $computed, $blocks, $decision['report'] );
        }

        $images = new HtmlImages( $manifest ?? [ 'fallback' => '', 'images' => [] ], $isSiteImage ?? static fn( string $u ): bool => false );
        $imageReport = [];
        $sections    = [];
        $rowCount    = 0;
        $colCount    = 0;
        $modules     = [];

        foreach ( $plan['sections'] as $si => $section ) {
            $imageIndex = 0;
            $rows       = [];
            $sStyle     = $decided[ $si ] ?? null;
            foreach ( $section['chunks'] as $ci => $chunk ) {
                $rStyle = $sStyle['rows'][ $ci ] ?? null;
                if ( 'cards' === $chunk['kind'] ) {
                    // Filter indices, not values, so the column styles (one per plan column) stay aligned with the columns that survive.
                    $keep   = array_keys( array_filter( $chunk['columns'], static fn( array $c ): bool => array() !== $c ) );
                    $widths = HtmlMapper::rowWidths( count( $keep ) );
                    $at     = 0;
                    foreach ( $widths as $wi => $width ) {
                        $slice     = array_slice( $keep, $at, $width );
                        $at       += $width;
                        $markup    = [];
                        $colStyles = [];
                        foreach ( $slice as $k ) {
                            $cStyle      = $rStyle['columns'][ $k ] ?? null;
                            $markup[]    = self::columnMarkup( $chunk['columns'][ $k ], $cStyle['modules'] ?? [], $modules, $imageReport, $imageIndex, $images, $mode, $si );
                            $colStyles[] = $cStyle['style'] ?? [];
                        }
                        $rows[] = DiviMarkup::row( $markup, self::splitRowStyle( $rStyle['style'] ?? [], 0 === $wi, count( $widths ) - 1 === $wi ), null === $rStyle ? [] : $colStyles );
                        ++$rowCount;
                        $colCount += count( $slice );
                    }
                    continue;
                }
                $cStyle = $rStyle['columns'][0] ?? null;
                $rows[] = DiviMarkup::row(
                    [ self::columnMarkup( $chunk['modules'], $cStyle['modules'] ?? [], $modules, $imageReport, $imageIndex, $images, $mode, $si ) ],
                    $rStyle['style'] ?? [],
                    null === $cStyle ? [] : [ $cStyle['style'] ?? [] ]
                );
                ++$rowCount;
                ++$colCount;
            }
            $sections[] = DiviMarkup::section( $section['label'], implode( "\n", $rows ), $sStyle['style'] ?? [] );
        }

        $layout = DiviMarkup::page( $sections );
        if ( null !== $resolve ) {
            $layout = $resolve( $layout );
        }

        $notes   = $plan['notes'];
        $flagged = array_merge( $basic ? self::withStyleFlags( $source['flagged'], (array) ( $source['style_flags'] ?? [] ) ) : $source['flagged'], $plan['flagged'] );

        $report = [
            'suggested_title' => $source['title'],
            'elements'        => [
                'sections' => count( $sections ),
                'rows'     => $rowCount,
                'columns'  => $colCount,
            ] + $plan['counts'],
            'flagged'         => array_slice( $flagged, 0, 100 ),
            'flagged_count'   => count( $flagged ),
            'notes'           => $notes,
            'text_check'      => self::textCheck( $body, $modules ),
            'links'           => self::linkCheck( $body, $modules ),
            'images'          => array_slice( $imageReport, 0, 50 ),
        ];
        if ( null !== $styleReport ) {
            $report['styles'] = $styleReport;
        }

        return [ 'ok' => true, 'layout' => $layout, 'report' => $report ];
    }

    /**
     * report.styles (styles=basic only): what was carried and what was left out. `ignored` is the sum of the counters of HtmlCss and
     * HtmlStyles (HtmlStyles::resolve) and of HtmlStyleBlocks and HtmlReadability (HtmlReadability::decide), keys sorted; `passes`
     * (internal) is not handed over. Every string is cut at 200 characters and made valid UTF-8 (a font name comes from the source CSS).
     *
     * @param array<string,mixed> $computed HtmlStyles::resolve()
     * @param array<string,mixed> $blocks   HtmlStyleBlocks::build()
     * @param array<string,mixed> $decided  HtmlReadability::decide()['report']
     * @return array{carried:array<string,int>, ignored:array<string,int>, dropped_unreadable:list<array<string,mixed>>, dropped_total:int, fonts:list<array{source:string,action:string}>, clamped:int, notes:list<string>, external:int}
     */
    private static function styleReport( array $computed, array $blocks, array $decided ): array
    {
        $ignored = [];
        foreach ( [ $computed['ignored'] ?? [], $decided['ignored'] ?? [] ] as $counters ) {
            foreach ( is_array( $counters ) ? $counters : [] as $k => $n ) {
                if ( is_string( $k ) && is_int( $n ) ) {
                    $ignored[ $k ] = ( $ignored[ $k ] ?? 0 ) + $n;
                }
            }
        }
        ksort( $ignored );

        $fonts = [];
        foreach ( is_array( $blocks['fonts'] ?? null ) ? $blocks['fonts'] : [] as $f ) {
            $src = self::cap( (string) ( $f['source'] ?? '' ) );
            if ( '' === $src || isset( $fonts[ $src ] ) ) {
                continue;
            }
            $fonts[ $src ] = [ 'source' => $src, 'action' => self::cap( (string) ( $f['action'] ?? '' ) ) ];
            if ( count( $fonts ) >= self::MAX_FONTS ) {
                break;
            }
        }

        $dropped = [];
        foreach ( is_array( $decided['dropped_unreadable'] ?? null ) ? $decided['dropped_unreadable'] : [] as $d ) {
            $dropped[] = array_map( static fn( mixed $v ): mixed => is_string( $v ) ? self::cap( $v ) : $v, is_array( $d ) ? $d : [] );
        }

        $external = is_int( $decided['external'] ?? null ) ? max( 0, $decided['external'] ) : 0;
        $notes    = [];
        foreach ( is_array( $decided['notes'] ?? null ) ? $decided['notes'] : [] as $n ) {
            if ( is_string( $n ) ) {
                $notes[] = self::cap( $n );
            }
        }
        if ( $external > 0 ) {
            $notes[] = sprintf( self::NOTE_EXTERNAL, $external );
        }

        return [
            'carried'            => is_array( $decided['carried'] ?? null ) ? $decided['carried'] : [],
            'ignored'            => $ignored,
            'dropped_unreadable' => $dropped,
            'dropped_total'      => is_int( $decided['dropped_total'] ?? null ) ? $decided['dropped_total'] : count( $dropped ),
            'fonts'              => array_values( $fonts ),
            'clamped'            => is_int( $decided['clamped'] ?? null ) ? $decided['clamped'] : 0,
            'notes'              => $notes,
            'external'           => $external,
        ];
    }

    /**
     * styles=basic: HtmlSource's style_flags join the flagged list, and they replace the generic "CSS blocks are never converted as code"
     * entries of the <style> elements in the body. Those generic entries cannot be matched to a style flag by path (HtmlSource writes
     * their path while removing the elements one after the other, so the sibling index shifts), and in this mode a <style> is either read
     * (report.styles says what was carried and what was ignored) or listed by its style flag with the reason it was not read. Either way
     * every <style> is accounted for exactly once; head <style> elements never had a generic entry.
     *
     * @param list<array<string,string>> $flagged
     * @param list<array<string,string>> $styleFlags
     * @return list<array<string,string>>
     */
    private static function withStyleFlags( array $flagged, array $styleFlags ): array
    {
        $out = array_values( array_filter( $flagged, static fn( mixed $f ): bool => ! is_array( $f ) || 'style' !== ( $f['type'] ?? null ) ) );
        foreach ( $styleFlags as $f ) {
            if ( is_array( $f ) ) {
                $out[] = [ 'type' => self::cap( (string) ( $f['type'] ?? '' ) ), 'reason' => self::cap( (string) ( $f['reason'] ?? '' ) ), 'path' => self::cap( (string) ( $f['path'] ?? '' ) ) ];
            }
        }

        return $out;
    }

    /**
     * M4: a cards chunk written as several Divi rows. Background, max width and the left and right padding repeat on every row; the
     * top padding goes on the first row only and the bottom padding on the last only (the writer takes padding per side).
     *
     * @param array<string,mixed> $style
     * @return array<string,mixed>
     */
    private static function splitRowStyle( array $style, bool $first, bool $last ): array
    {
        if ( ! isset( $style['padding'] ) || ! is_array( $style['padding'] ) ) {
            return $style;
        }
        $padding = $style['padding'];
        if ( ! $first ) {
            unset( $padding['top'] );
        }
        if ( ! $last ) {
            unset( $padding['bottom'] );
        }
        if ( array() === $padding ) {
            unset( $style['padding'] );
        } else {
            $style['padding'] = $padding;
        }

        return $style;
    }

    /** At most 200 characters of valid UTF-8 (a string from the source may be invalid UTF-8 and must never break the JSON report). */
    private static function cap( string $s ): string
    {
        if ( ! mb_check_encoding( $s, 'UTF-8' ) ) {
            $s = (string) mb_scrub( $s, 'UTF-8' );
        }

        return mb_strlen( $s, 'UTF-8' ) > self::MAX_STRING ? mb_substr( $s, 0, self::MAX_STRING, 'UTF-8' ) : $s;
    }

    /** @return array{source_chars:int,result_chars:int,equal:bool,first_difference?:string} */
    private static function textCheck( \DOMElement $body, array $modules ): array
    {
        $clone = $body->cloneNode( true );
        if ( $clone instanceof \DOMElement ) {
            foreach ( iterator_to_array( $clone->getElementsByTagName( 'nav' ) ) as $nav ) {
                $nav->parentNode?->removeChild( $nav );
            }
            $source = HtmlSource::collapse( $clone->textContent );
        } else {
            $source = '';
        }
        $parts = [];
        foreach ( $modules as $m ) {
            $parts[] = match ( $m['type'] ) {
                'heading', 'button' => (string) $m['text'],
                'text'              => HtmlProse::plain( (string) $m['html'] ),
                default             => '',
            };
        }
        $result = HtmlSource::collapse( implode( ' ', $parts ) );

        $a = (string) preg_replace( '/\s+/u', '', $source );
        $b = (string) preg_replace( '/\s+/u', '', $result );
        $out = [ 'source_chars' => mb_strlen( $a ), 'result_chars' => mb_strlen( $b ), 'equal' => $a === $b ];
        if ( $a !== $b ) {
            $i = 0;
            $max = min( mb_strlen( $a ), mb_strlen( $b ) );
            while ( $i < $max && mb_substr( $a, $i, 1 ) === mb_substr( $b, $i, 1 ) ) {
                ++$i;
            }
            $out['first_difference'] = 'source: “' . mb_substr( $a, max( 0, $i - 10 ), 40 ) . '” result: “' . mb_substr( $b, max( 0, $i - 10 ), 40 ) . '”';
        }

        return $out;
    }

    /** @return array{in_source:int,kept:int,lost:list<string>} */
    private static function linkCheck( \DOMElement $body, array $modules ): array
    {
        $clone = $body->cloneNode( true );
        $source = [];
        if ( $clone instanceof \DOMElement ) {
            foreach ( iterator_to_array( $clone->getElementsByTagName( 'nav' ) ) as $nav ) {
                $nav->parentNode?->removeChild( $nav );
            }
            foreach ( $clone->getElementsByTagName( 'a' ) as $a ) {
                if ( $a instanceof \DOMElement && $a->hasAttribute( 'href' ) ) {
                    $source[] = $a->getAttribute( 'href' );
                }
            }
        }
        $kept = [];
        foreach ( $modules as $m ) {
            if ( 'text' === $m['type'] && preg_match_all( '/<a href="([^"]*)"/', (string) $m['html'], $mm ) ) {
                foreach ( $mm[1] as $h ) {
                    $kept[] = html_entity_decode( $h, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                }
            } elseif ( in_array( $m['type'], [ 'button', 'image' ], true ) && null !== ( $m['href'] ?? null ) ) {
                $kept[] = (string) $m['href'];
            }
        }
        $lost = [];
        $pool = array_count_values( $kept );
        foreach ( $source as $href ) {
            if ( ( $pool[ $href ] ?? 0 ) > 0 ) {
                --$pool[ $href ];
            } else {
                $lost[] = $href;
            }
        }

        return [ 'in_source' => count( $source ), 'kept' => count( $source ) - count( $lost ), 'lost' => array_slice( $lost, 0, 50 ) ];
    }

    /**
     * @param list<array<string,mixed>> $column
     * @param list<array<string,mixed>> $styles   the decided module styles, one per module of $column, in order ([] = none: styles=none)
     * @param list<array<string,mixed>> $modules  receives every module, in order (used for the text and link checks)
     * @param list<array<string,mixed>> $imageReport
     */
    private static function columnMarkup( array $column, array $styles, array &$modules, array &$imageReport, int &$imageIndex, HtmlImages $images, string $mode, int $sectionIndex ): string
    {
        $out = [];
        $at  = 0; // by position: the decided styles are a list in module order
        foreach ( $column as $m ) {
            $style = is_array( $styles[ $at ] ?? null ) ? $styles[ $at ] : [];
            ++$at;
            switch ( $m['type'] ) {
                case 'heading':
                    $out[] = DiviMarkup::heading( (string) $m['text'], (int) $m['level'], $style );
                    break;
                case 'text':
                    $out[] = DiviMarkup::text( (string) $m['html'], $style );
                    break;
                case 'button':
                    $out[] = DiviMarkup::button( (string) $m['text'], $m['href'] ?? null, (bool) $m['newTab'], $style );
                    break;
                case 'divider':
                    $out[] = DiviMarkup::divider();
                    break;
                case 'image':
                    $r = $images->resolve( $m, $mode, $sectionIndex, $imageIndex );
                    ++$imageIndex;
                    $out[] = DiviMarkup::image( $r['src'], (string) $m['alt'], $m['href'] ?? null );
                    $imageReport[] = [ 'src' => (string) $m['src'], 'alt' => (string) $m['alt'], 'action' => $r['action'], 'token' => $r['token'], 'path' => (string) $m['path'] ];
                    break;
            }
            $modules[] = $m;
        }

        return implode( "\n", $out );
    }

    /** @return array<string,mixed> */
    private static function emptyReport( array $source ): array
    {
        return [
            'suggested_title' => (string) ( $source['title'] ?? '' ),
            'elements'        => [],
            'flagged'         => array_slice( (array) ( $source['flagged'] ?? [] ), 0, 100 ),
            'flagged_count'   => count( (array) ( $source['flagged'] ?? [] ) ),
            'notes'           => [],
            'text_check'      => [ 'source_chars' => 0, 'result_chars' => 0, 'equal' => true ],
            'links'           => [ 'in_source' => 0, 'kept' => 0, 'lost' => [] ],
            'images'          => [],
        ];
    }

    private static function imageMode( string $mode ): string
    {
        return HtmlImages::MODE_KEEP === $mode ? HtmlImages::MODE_KEEP : HtmlImages::MODE_PLACEHOLDER;
    }
}
