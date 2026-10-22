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
    /**
     * The collected <style> text is handed over cut at MAX_CSS_BYTES + 1 bytes (plain substr, possibly inside a character): one byte MORE than
     * the cap HtmlCss reads (HtmlCss::MAX_CSS_BYTES, kept equal by a test), so the CSS reader sees a text longer than its cap, counts `css_cap`
     * and poisons what the cut-off tail might have set, instead of reading a last rule that the cut shortened (#123456 -> #123).
     */
    public const MAX_CSS_BYTES = 100000;

    /**
     * Attributes that browsers still map to colour, background, font or alignment (HTML 3/4 leftovers): bgcolor and background (body, table,
     * tr, td, th), text, link, vlink and alink (body colours), color, face and size (font), align and valign (blocks, tables, cells ...).
     * Width, height, border, cellpadding and the like are NOT here: they restyle nothing a colour, font or alignment reader looks at. They are
     * never kept in the DOM; their NAMES are handed over per element, in this order, so the style reader knows a value there might be set by
     * something it cannot read.
     */
    private const PRESENTATIONAL = [ 'bgcolor', 'background', 'color', 'text', 'link', 'vlink', 'alink', 'face', 'size', 'align', 'valign' ];

    /** A <style> (or <link>) inside one of these is inert in a browser (template, scripting-on noscript, text-only elements): it is not read. */
    private const INERT_ANCESTORS = [ 'template', 'noscript', 'textarea', 'title', 'iframe', 'noembed', 'noframes', 'xmp', 'plaintext' ];

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
     * Besides the cleaned body, these keys hand over what the cleaning removed so a style reader can use it (the `style` attribute and the
     * presentational attributes are NEVER left in the DOM):
     *   css            the text of the <style> elements of the whole document (head and body) that apply in a screen browser, in document
     *                  order, joined with "\n", read before the elements are removed, cut at MAX_CSS_BYTES + 1 bytes (see MAX_CSS_BYTES).
     *                  A <style> is SKIPPED (and written down in style_flags) when it sits inside template, noscript, textarea, title, iframe,
     *                  noembed, noframes, xmp or plaintext, has a media attribute other than empty, "all" or "screen", a type other than
     *                  empty or "text/css", or the disabled attribute. This is conservative: it can cost a style, never carry a false one.
     *                  A <style> inside svg, form, object, video or canvas fallback content is kept (it applies).
     *   inline         DOMNode::getNodePath() => the text of the style attribute, for <html>, <body> and every kept element
     *   presentational DOMNode::getNodePath() => the names (see PRESENTATIONAL) of the presentational attributes it had
     *   external_css   int, the number of <link rel=stylesheet> elements (any href; "alternate" ones and inert ones do not count): the converter
     *                  never fetches them, so colours and fonts may differ
     *   style_flags    list of flagged entries (type, reason, path) about CSS that was not read: the skipped <style> elements and, when
     *                  external_css > 0, one entry for the linked stylesheets. Kept apart from `flagged` so the styles=none report
     *                  stays byte-identical to phase 1; the styles=basic report merges them.
     *   css_media      the text of the <style> elements skipped ONLY because of a media attribute that may match a screen (anything but a
     *                  list of print/speech types, for example "(min-width:768px)"), same order, joining and cut as `css`. They apply at some
     *                  window widths, so a reader must treat every rule in them as a poison of unknown rank, never as a value.
     *   doctype        true only when the HTML starts (after whitespace and an optional byte order mark) with <!DOCTYPE html>: the page is
     *                  then in standards mode. Without it a browser may be in quirks mode, where class and id selectors match ASCII
     *                  case-insensitively and tables do not inherit fonts. Any other start (a legacy doctype, a comment first) is false,
     *                  which is the conservative answer.
     *   color_scheme   true when a <meta name="color-scheme"> asks for anything but light: the default canvas and text colours are then
     *                  the visitor's choice, not white and black.
     *   cut_attrs      getNodePath() => ['class' => whole value, 'id' => whole value] for the elements whose class or id the DOM keeps cut
     *                  at 300 characters (phase 1 behaviour, unchanged), so a selector reader can match the classes beyond the cut.
     * The paths are computed after every removal and move, so they are the paths of the returned DOM. An element that was removed (or
     * sits inside a removed one) hands over nothing.
     *
     * Limit for a selector reader: the DOM keeps only class, id, role and a few per-tag attributes, and dropped elements (script, form, svg ...)
     * are removed, so attribute selectors and structural selectors (:nth-child, :first-child, sibling combinators) would be evaluated on a pruned
     * tree. A reader must treat them as unreadable (poison), not evaluate them.
     *
     * @return array{error:?string, body:?\DOMElement, flagged:list<array{type:string,reason:string,path:string}>, title:string, h1s:list<string>, stats:array<string,int>, css:string, inline:array<string,string>, presentational:array<string,list<string>>, external_css:int, style_flags:list<array{type:string,reason:string,path:string}>, css_media:string, doctype:bool, color_scheme:bool, cut_attrs:array<string,array<string,string>>}
     */
    public static function parse( string $html ): array
    {
        $out = [ 'error' => null, 'body' => null, 'flagged' => [], 'title' => '', 'h1s' => [], 'stats' => [ 'elements' => 0, 'removed' => 0, 'urls_dropped' => 0 ], 'css' => '', 'inline' => [], 'presentational' => [], 'external_css' => 0, 'style_flags' => [], 'css_media' => '', 'doctype' => false, 'color_scheme' => false, 'cut_attrs' => [] ];

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
        // Standards mode: <!DOCTYPE html> first. Nothing else is trusted (a comment first can hide text that a browser reads first).
        $standards = 1 === preg_match( '/\A(?:\xEF\xBB\xBF)?[\t\n\f\r ]*<!doctype[\t\n\f\r ]+html[\t\n\f\r ]*>/i', $html );

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

        self::collectCss( $doc, $out );
        $out['doctype']      = $standards;
        $out['color_scheme'] = self::darkCapable( $doc );

        // Style and presentational attributes are read from the node BEFORE they are removed; the paths are taken at the end.
        $handOver = [];
        $cut      = [];
        $root     = $doc->documentElement;
        if ( $root instanceof \DOMElement && $root !== $body ) {
            self::takeStyleAttributes( $root, $handOver );
        }
        self::takeStyleAttributes( $body, $handOver );
        self::cleanAttributes( $body, $out, $cut );
        self::clean( $body, $out, $handOver, $cut );
        foreach ( $cut as [ $el, $name, $whole ] ) {
            $path = ( $el === $body || self::isInside( $el, $body ) ) ? $el->getNodePath() : null;
            if ( null !== $path ) {
                $out['cut_attrs'][ $path ][ $name ] = $whole;
            }
        }
        foreach ( $handOver as [ $el, $style, $presentational ] ) {
            if ( $el !== $root && $el !== $body && ! self::isInside( $el, $body ) ) {
                continue;
            }
            $path = $el->getNodePath();
            if ( null === $path ) {
                continue;
            }
            if ( '' !== $style ) {
                $out['inline'][ $path ] = $style;
            }
            if ( array() !== $presentational ) {
                $out['presentational'][ $path ] = $presentational;
            }
        }
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

    /**
     * Fills $out['css'], $out['external_css'] and $out['style_flags'] (see parse()).
     *
     * @param array<string,mixed> $out
     */
    private static function collectCss( \DOMDocument $doc, array &$out ): void
    {
        $parts = [];
        $bytes = 0;
        $media = [];
        $mediaBytes = 0;
        foreach ( iterator_to_array( $doc->getElementsByTagName( 'style' ) ) as $style ) {
            $why = self::whyStyleIsSkipped( $style );
            if ( null !== $why ) {
                if ( count( $out['style_flags'] ) < 100 ) {
                    $out['style_flags'][] = [ 'type' => 'style', 'reason' => 'A CSS block was not read: ' . $why . '.', 'path' => self::path( $style ) ];
                }
                $text = trim( $style->textContent );
                if ( self::forSomeScreens( $style ) && '' !== $text && $mediaBytes <= self::MAX_CSS_BYTES ) {
                    $media[]     = $text;
                    $mediaBytes += strlen( $text ) + 1;
                }
                continue;
            }
            if ( $bytes > self::MAX_CSS_BYTES ) {
                continue; // already over the cap: keep scanning only so every skipped block is still written down
            }
            $text = trim( $style->textContent );
            if ( '' === $text ) {
                continue;
            }
            $parts[] = $text;
            $bytes  += strlen( $text ) + 1;
        }
        $css = implode( "\n", $parts );
        $out['css'] = strlen( $css ) > self::MAX_CSS_BYTES + 1 ? substr( $css, 0, self::MAX_CSS_BYTES + 1 ) : $css;
        $css = implode( "\n", $media );
        $out['css_media'] = strlen( $css ) > self::MAX_CSS_BYTES + 1 ? substr( $css, 0, self::MAX_CSS_BYTES + 1 ) : $css;

        $links = 0;
        $first = null;
        foreach ( iterator_to_array( $doc->getElementsByTagName( 'link' ) ) as $link ) {
            $rel = preg_split( '/\s+/', strtolower( trim( $link->getAttribute( 'rel' ) ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
            if ( in_array( 'stylesheet', $rel, true ) && ! in_array( 'alternate', $rel, true ) && null === self::inertAncestor( $link ) ) {
                ++$links;
                $first ??= $link;
            }
        }
        $out['external_css'] = $links;
        if ( $links > 0 && $first instanceof \DOMElement ) {
            $out['style_flags'][] = [
                'type'   => 'link',
                'reason' => 1 === $links ? '1 linked stylesheet was not read: colours and fonts may differ.' : $links . ' linked stylesheets were not read: colours and fonts may differ.',
                'path'   => self::path( $first ),
            ];
        }
    }

    /**
     * A <style> that is skipped only because of its media attribute, and whose media list may match a screen: every item that is not
     * exactly a print or speech type (with an optional "only") might, so it is handed over as css_media.
     */
    private static function forSomeScreens( \DOMElement $style ): bool
    {
        if ( null !== self::inertAncestor( $style ) || $style->hasAttribute( 'disabled' ) ) {
            return false;
        }
        $type = strtolower( trim( $style->getAttribute( 'type' ) ) );
        if ( '' !== $type && 'text/css' !== $type ) {
            return false;
        }
        foreach ( explode( ',', strtolower( $style->getAttribute( 'media' ) ) ) as $item ) {
            if ( 1 !== preg_match( '/^[\t\n\f\r ]*(?:only[\t\n\f\r ]+)?(?:print|speech)[\t\n\f\r ]*$/', $item ) ) {
                return true;
            }
        }

        return false;
    }

    /** A <meta name="color-scheme"> (anywhere that is not inert) that allows anything but light. */
    private static function darkCapable( \DOMDocument $doc ): bool
    {
        foreach ( iterator_to_array( $doc->getElementsByTagName( 'meta' ) ) as $meta ) {
            if ( null !== self::inertAncestor( $meta ) || 'color-scheme' !== strtolower( trim( $meta->getAttribute( 'name' ) ) ) ) {
                continue;
            }
            $content = strtolower( trim( (string) preg_replace( '/[\t\n\f\r ]+/', ' ', $meta->getAttribute( 'content' ) ) ) );
            if ( ! in_array( $content, [ '', 'normal', 'light', 'only light' ], true ) ) {
                return true;
            }
        }

        return false;
    }

    /** Plain-English reason a <style> element is not read, or null when it is. */
    private static function whyStyleIsSkipped( \DOMElement $style ): ?string
    {
        $inert = self::inertAncestor( $style );
        if ( null !== $inert ) {
            return 'it sits inside <' . $inert . '>, which a browser does not apply';
        }
        $media = strtolower( trim( $style->getAttribute( 'media' ) ) );
        if ( ! in_array( $media, [ '', 'all', 'screen' ], true ) ) {
            return 'its media attribute limits it to some devices or window sizes';
        }
        $type = strtolower( trim( $style->getAttribute( 'type' ) ) );
        if ( '' !== $type && 'text/css' !== $type ) {
            return 'it is not plain CSS';
        }
        if ( $style->hasAttribute( 'disabled' ) ) {
            return 'it is disabled';
        }

        return null;
    }

    /** The tag name of the nearest ancestor that makes a <style>/<link> inert, or null. */
    private static function inertAncestor( \DOMNode $node ): ?string
    {
        for ( $p = $node->parentNode; $p instanceof \DOMElement; $p = $p->parentNode ) {
            $tag = strtolower( $p->tagName );
            if ( in_array( $tag, self::INERT_ANCESTORS, true ) ) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * Writes down an element's style attribute text and presentational attribute names, in place of removing them unseen.
     *
     * @param list<array{0:\DOMElement,1:string,2:list<string>}> $handOver
     */
    private static function takeStyleAttributes( \DOMElement $el, array &$handOver ): void
    {
        $style = $el->hasAttribute( 'style' ) ? trim( $el->getAttribute( 'style' ) ) : '';
        $names = [];
        foreach ( self::PRESENTATIONAL as $name ) {
            if ( $el->hasAttribute( $name ) ) {
                $names[] = $name;
            }
        }
        if ( '' !== $style || array() !== $names ) {
            $handOver[] = [ $el, $style, $names ];
        }
    }

    private static function isInside( \DOMNode $node, \DOMElement $root ): bool
    {
        for ( $p = $node->parentNode; null !== $p; $p = $p->parentNode ) {
            if ( $p === $root ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed>                                 $out
     * @param list<array{0:\DOMElement,1:string,2:list<string>}> $handOver
     * @param list<array{0:\DOMElement,1:string,2:string}>        $cut
     */
    private static function clean( \DOMElement $body, array &$out, array &$handOver, array &$cut ): void
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
            self::takeStyleAttributes( $el, $handOver );
            self::cleanAttributes( $el, $out, $cut );
        }
    }

    /**
     * The style attributes must have been read (takeStyleAttributes) before this removes them.
     *
     * @param array<string,mixed>                          $out
     * @param list<array{0:\DOMElement,1:string,2:string}> $cut receives the whole class or id value before it is cut
     */
    private static function cleanAttributes( \DOMElement $el, array &$out, array &$cut ): void
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
                if ( 'role' !== $meta ) {
                    $cut[] = [ $el, $meta, $el->getAttribute( $meta ) ];
                }
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
