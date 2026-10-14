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

    /**
     * @param array{image_mode?:string, styles?:string} $options
     * @param ?array<string,mixed>                      $manifest    the image pack manifest (ImagePack::manifest())
     * @param ?callable(string):bool                    $isSiteImage whether a URL is an image of this site's Media Library
     * @param ?callable(string):string                  $resolve     turns {{aied:image:token}} into a real URL (identity in tests)
     * @return array<string,mixed> { ok, error?, layout, report }
     */
    public static function convert( string $html, array $options = [], ?array $manifest = null, ?callable $isSiteImage = null, ?callable $resolve = null ): array
    {
        $mode   = self::imageMode( (string) ( $options['image_mode'] ?? HtmlImages::MODE_PLACEHOLDER ) );
        $styles = self::STYLES_NONE === ( $options['styles'] ?? self::STYLES_BASIC ) ? self::STYLES_NONE : self::STYLES_BASIC;

        $source = HtmlSource::parse( $html );
        if ( null !== $source['error'] || ! $source['body'] instanceof \DOMElement ) {
            return [ 'ok' => false, 'error' => $source['error'] ?? 'The HTML has no content.', 'layout' => '', 'report' => self::emptyReport( $source ) ];
        }
        /** @var \DOMElement $body */
        $body = $source['body'];

        $plan = HtmlMapper::map( $body );
        if ( array() === $plan['sections'] ) {
            return [ 'ok' => false, 'error' => 'Nothing to convert: the page has no visible text, images or links.', 'layout' => '', 'report' => self::emptyReport( $source ) + [ 'flagged' => $plan['flagged'] ] ];
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
            foreach ( $section['chunks'] as $chunk ) {
                if ( 'cards' === $chunk['kind'] ) {
                    $cols = array_values( array_filter( $chunk['columns'], static fn( array $c ): bool => array() !== $c ) );
                    $at   = 0;
                    foreach ( HtmlMapper::rowWidths( count( $cols ) ) as $width ) {
                        $slice = array_slice( $cols, $at, $width );
                        $at   += $width;
                        $rows[] = DiviMarkup::row( array_map( function ( array $c ) use ( &$modules, &$imageReport, &$imageIndex, $images, $mode, $si ): string {
                            return self::columnMarkup( $c, $modules, $imageReport, $imageIndex, $images, $mode, $si );
                        }, $slice ) );
                        ++$rowCount;
                        $colCount += count( $slice );
                    }
                    continue;
                }
                $rows[] = DiviMarkup::row( [ self::columnMarkup( $chunk['modules'], $modules, $imageReport, $imageIndex, $images, $mode, $si ) ] );
                ++$rowCount;
                ++$colCount;
            }
            $sections[] = DiviMarkup::section( $section['label'], implode( "\n", $rows ) );
        }

        $layout = DiviMarkup::page( $sections );
        if ( null !== $resolve ) {
            $layout = $resolve( $layout );
        }

        $notes = $plan['notes'];
        if ( self::STYLES_BASIC === $styles ) {
            $notes[] = 'Colors, fonts and spacing from the HTML are not converted yet: the structure, copy, links and images are. Ask your assistant to style it with get_style_guide.';
        }
        $flagged = array_merge( $source['flagged'], $plan['flagged'] );

        return [
            'ok'     => true,
            'layout' => $layout,
            'report' => [
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
            ],
        ];
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
     * @param list<array<string,mixed>> $modules  receives every module, in order (used for the text and link checks)
     * @param list<array<string,mixed>> $imageReport
     */
    private static function columnMarkup( array $column, array &$modules, array &$imageReport, int &$imageIndex, HtmlImages $images, string $mode, int $sectionIndex ): string
    {
        $out = [];
        foreach ( $column as $m ) {
            switch ( $m['type'] ) {
                case 'heading':
                    $out[] = DiviMarkup::heading( (string) $m['text'], (int) $m['level'] );
                    break;
                case 'text':
                    $out[] = DiviMarkup::text( (string) $m['html'] );
                    break;
                case 'button':
                    $out[] = DiviMarkup::button( (string) $m['text'], $m['href'] ?? null, (bool) $m['newTab'] );
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
