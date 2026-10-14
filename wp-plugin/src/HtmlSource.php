<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Step one of html_to_divi: turn a string of HTML into a SAFE, small DOM.
 *
 * The HTML is data. It is parsed (never fetched, never executed), everything that could carry code or that Divi
 * cannot reproduce is removed and written down in `flagged`, every attribute outside a short allow-list is dropped,
 * and every URL is checked. What comes back can only hold structure, text, links and images.
 */
final class HtmlSource
{
    public const MAX_BYTES    = 400000;
    public const MAX_ELEMENTS = 5000;
    public const MAX_DEPTH    = 50;

    /** Removed with their content. tag => why it is not converted (plain English, shown to the owner). */
    private const DROP = [
        'script'   => 'JavaScript is never converted.',
        'style'    => 'CSS blocks are never converted as code.',
        'noscript' => 'Script fallbacks are not converted.',
        'template' => 'Templates are not converted.',
        'iframe'   => 'Embedded pages and videos are not converted. Add a video or map module in Divi.',
        'object'   => 'Embedded objects are not converted.',
        'embed'    => 'Embedded content is not converted.',
        'applet'   => 'Embedded content is not converted.',
        'frame'    => 'Frames are not converted.',
        'frameset' => 'Frames are not converted.',
        'video'    => 'Video is not converted. Add a video module in Divi.',
        'audio'    => 'Audio is not converted. Add an audio module in Divi.',
        'canvas'   => 'Canvas drawings are not converted.',
        'map'      => 'Image maps are not converted.',
        'svg'      => 'Inline SVG graphics are not converted. Upload the image to the Media Library.',
        'form'     => 'Forms are not converted. Use Divi’s contact form module or a form plugin.',
        'input'    => 'Form fields are not converted.',
        'select'   => 'Form fields are not converted.',
        'textarea' => 'Form fields are not converted.',
        'dialog'   => 'Dialogs are not converted.',
        'link'     => '',
        'meta'     => '',
        'base'     => '',
        'head'     => '',
        'title'    => '',
    ];

    /**
     * Elements that are void in HTML5 but that libxml2's older parser may treat as containers, swallowing the
     * content that follows them. Their children are moved out before the element is dropped, so no copy is lost.
     */
    private const VOID_QUIRK = [ 'embed', 'input', 'link', 'meta', 'base' ];

    /** Attributes kept per tag; everything else is dropped. `class`, `id` and `role` are read by the mapper and never emitted. */
    private const ATTRS = [
        '*'     => [ 'class', 'id', 'role' ],
        'a'     => [ 'href', 'title', 'target' ],
        'img'   => [ 'src', 'alt', 'title', 'width', 'height' ],
        'td'    => [ 'colspan', 'rowspan' ],
        'th'    => [ 'colspan', 'rowspan' ],
        'ol'    => [ 'start' ],
        'li'    => [],
    ];

    /**
     * @return array{error:?string, body:?\DOMElement, flagged:list<array{type:string,reason:string,path:string}>, title:string, h1s:list<string>, stats:array<string,int>}
     */
    public static function parse( string $html ): array
    {
        $out = [ 'error' => null, 'body' => null, 'flagged' => [], 'title' => '', 'h1s' => [], 'stats' => [ 'elements' => 0, 'removed' => 0, 'urls_dropped' => 0 ] ];

        if ( '' === trim( $html ) ) {
            $out['error'] = 'The HTML is empty.';

            return $out;
        }
        if ( strlen( $html ) > self::MAX_BYTES ) {
            $out['error'] = 'The HTML is larger than ' . self::MAX_BYTES . ' bytes. Convert it in parts.';

            return $out;
        }
        if ( ! mb_check_encoding( $html, 'UTF-8' ) ) {
            $out['error'] = 'The HTML is not valid UTF-8. Save it as UTF-8 and try again.';

            return $out;
        }
        // NUL bytes and other control characters have no place in a page; drop them before parsing.
        $html = (string) preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $html );

        $doc  = new \DOMDocument();
        $prev = libxml_use_internal_errors( true );
        // The xml declaration makes libxml read the string as UTF-8. NONET: nothing is ever fetched.
        $loaded = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        if ( ! $loaded ) {
            $out['error'] = 'The HTML could not be read.';

            return $out;
        }

        $total = $doc->getElementsByTagName( '*' )->length;
        if ( $total > self::MAX_ELEMENTS ) {
            $out['error'] = 'The HTML has more than ' . self::MAX_ELEMENTS . ' elements. Convert it in parts.';

            return $out;
        }
        $body = $doc->getElementsByTagName( 'body' )->item( 0 );
        if ( ! $body instanceof \DOMElement ) {
            $out['error'] = 'The HTML has no content.';

            return $out;
        }
        if ( self::depth( $body ) > self::MAX_DEPTH ) {
            $out['error'] = 'The HTML is nested deeper than ' . self::MAX_DEPTH . ' levels, which is almost always a sign of generated markup. Simplify it or convert it in parts.';

            return $out;
        }

        // The suggested title: <title>, else the first <h1>. Only suggested, never silently used.
        $titleNode = $doc->getElementsByTagName( 'title' )->item( 0 );
        $out['title'] = $titleNode instanceof \DOMNode ? self::collapse( $titleNode->textContent ) : '';

        self::clean( $body, $out );
        foreach ( iterator_to_array( $body->getElementsByTagName( 'h1' ) ) as $h1 ) {
            $out['h1s'][] = self::collapse( $h1->textContent );
        }
        if ( '' === $out['title'] && array() !== $out['h1s'] ) {
            $out['title'] = $out['h1s'][0];
        }
        $out['title']             = function_exists( 'mb_substr' ) ? mb_substr( $out['title'], 0, 200 ) : substr( $out['title'], 0, 200 );
        $out['body']              = $body;
        $out['stats']['elements'] = $body->getElementsByTagName( '*' )->length;

        return $out;
    }

    /** The URL if it is safe to keep (http, https, mailto, tel, anchors, relative paths), else null. */
    public static function safeUrl( string $url ): ?string
    {
        $url = trim( (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $url ) );
        if ( '' === $url || strlen( $url ) > 2000 ) {
            return null;
        }
        if ( 1 === preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $url, $m ) ) {
            return in_array( strtolower( $m[1] ), [ 'http', 'https', 'mailto', 'tel' ], true ) ? $url : null;
        }
        // No scheme: a relative path, an anchor or a query. Protocol-relative and backslash forms are not kept.
        if ( str_starts_with( $url, '//' ) || str_contains( $url, '\\' ) ) {
            return null;
        }

        return $url;
    }

    /** Whitespace collapsed to single spaces, trimmed. */
    public static function collapse( string $s ): string
    {
        return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
    }

    /** A readable position such as "section[2] > div[1] > h3[1]" for the report. */
    public static function path( \DOMNode $node ): string
    {
        $parts = [];
        while ( $node instanceof \DOMElement && 'body' !== strtolower( $node->tagName ) ) {
            $index = 1;
            for ( $sib = $node->previousSibling; null !== $sib; $sib = $sib->previousSibling ) {
                if ( $sib instanceof \DOMElement && $sib->tagName === $node->tagName ) {
                    ++$index;
                }
            }
            array_unshift( $parts, strtolower( $node->tagName ) . '[' . $index . ']' );
            $node = $node->parentNode;
        }

        return array() === $parts ? 'body' : implode( ' > ', array_slice( $parts, -4 ) );
    }

    /** @param array<string,mixed> $out */
    private static function clean( \DOMElement $body, array &$out ): void
    {
        // Collect first, change after: removing nodes while walking skips siblings.
        $remove  = [];
        $elements = [];
        $stack   = [ $body ];
        while ( array() !== $stack ) {
            $node = array_pop( $stack );
            foreach ( iterator_to_array( $node->childNodes ) as $child ) {
                if ( $child instanceof \DOMElement ) {
                    $tag = strtolower( $child->tagName );
                    if ( isset( self::DROP[ $tag ] ) ) {
                        $remove[] = $child;
                        if ( in_array( $tag, self::VOID_QUIRK, true ) ) {
                            $stack[] = $child; // what it swallowed is moved out later, so it must be cleaned too
                        }
                        continue;
                    }
                    $elements[] = $child;
                    $stack[]    = $child;
                } elseif ( ! $child instanceof \DOMText ) {
                    $remove[] = $child; // comments, processing instructions, CDATA
                }
            }
        }

        foreach ( $remove as $node ) {
            if ( $node instanceof \DOMElement ) {
                $reason = self::DROP[ strtolower( $node->tagName ) ];
                if ( '' !== $reason && count( $out['flagged'] ) < 100 ) {
                    $out['flagged'][] = [ 'type' => strtolower( $node->tagName ), 'reason' => $reason, 'path' => self::path( $node ) ];
                }
                ++$out['stats']['removed'];
            }
            if ( null !== $node->parentNode ) {
                if ( $node instanceof \DOMElement && in_array( strtolower( $node->tagName ), self::VOID_QUIRK, true ) ) {
                    while ( null !== $node->firstChild ) {
                        $node->parentNode->insertBefore( $node->firstChild, $node );
                    }
                }
                $node->parentNode->removeChild( $node );
            }
        }

        foreach ( $elements as $el ) {
            if ( null === $el->parentNode ) {
                continue; // inside something that was removed
            }
            self::cleanAttributes( $el, $out );
        }
    }

    /** @param array<string,mixed> $out */
    private static function cleanAttributes( \DOMElement $el, array &$out ): void
    {
        $tag   = strtolower( $el->tagName );
        $keep  = array_merge( self::ATTRS['*'], self::ATTRS[ $tag ] ?? [] );
        $names = [];
        foreach ( iterator_to_array( $el->attributes ) as $attr ) {
            $names[] = $attr->name;
        }
        foreach ( $names as $name ) {
            if ( ! in_array( strtolower( $name ), $keep, true ) ) {
                $el->removeAttribute( $name );
            }
        }
        foreach ( [ 'href', 'src' ] as $urlAttr ) {
            if ( $el->hasAttribute( $urlAttr ) ) {
                $safe = self::safeUrl( $el->getAttribute( $urlAttr ) );
                if ( null === $safe ) {
                    $el->removeAttribute( $urlAttr );
                    ++$out['stats']['urls_dropped'];
                    if ( count( $out['flagged'] ) < 100 ) {
                        $out['flagged'][] = [ 'type' => $urlAttr, 'reason' => 'An unsafe or unsupported address (for example javascript: or data:) was removed.', 'path' => self::path( $el ) ];
                    }
                } else {
                    $el->setAttribute( $urlAttr, $safe );
                }
            }
        }
        foreach ( [ 'class', 'id', 'role' ] as $meta ) {
            if ( $el->hasAttribute( $meta ) && strlen( $el->getAttribute( $meta ) ) > 300 ) {
                $el->setAttribute( $meta, substr( $el->getAttribute( $meta ), 0, 300 ) );
            }
        }
    }

    private static function depth( \DOMElement $root ): int
    {
        $max   = 0;
        $stack = [ [ $root, 1 ] ];
        while ( array() !== $stack ) {
            [ $node, $d ] = array_pop( $stack );
            $max = max( $max, $d );
            if ( $max > self::MAX_DEPTH ) {
                return $max;
            }
            foreach ( $node->childNodes as $child ) {
                if ( $child instanceof \DOMElement ) {
                    $stack[] = [ $child, $d + 1 ];
                }
            }
        }

        return $max;
    }
}
