<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns a piece of (already cleaned) HTML into the small, safe HTML a Divi text module holds. Only the tags in
 * ALLOWED come out; everything else is unwrapped (its text is kept), so no copy is ever lost and no markup can
 * smuggle anything in. Attributes are written by this class, never copied: a link keeps only a safe href, a title
 * and a target.
 */
final class HtmlProse
{
    /** Tags that are written out. */
    private const ALLOWED = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ins', 'mark', 'small', 'sub', 'sup', 'code', 'kbd', 'abbr', 'cite', 'q',
        'a', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'blockquote', 'pre',
        'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    ];

    private const HEADINGS = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];

    /** Elements that cannot live inside running text: images and rules carry no text, and navigation menus are never converted (their text is not counted). */
    private const DROPPED_INLINE = [ 'img', 'picture', 'hr', 'nav' ];

    /**
     * The sanitised HTML of one element (its own tag included when it is allowed).
     *
     * @param list<string> $notes receives a plain-English note for anything dropped from inside the text
     */
    public static function html( \DOMNode $node, array &$notes = [] ): string
    {
        return self::node( $node, $notes, false );
    }

    /** The sanitised HTML of everything inside an element. @param list<string> $notes */
    public static function inner( \DOMNode $node, array &$notes = [] ): string
    {
        return self::children( $node, $notes, false );
    }

    /** @param list<string> $notes */
    private static function children( \DOMNode $node, array &$notes, bool $pre ): string
    {
        $out = '';
        foreach ( $node->childNodes as $child ) {
            $out .= self::node( $child, $notes, $pre );
        }

        return $out;
    }

    /** The visible text of an HTML fragment, whitespace collapsed (used to compare source and result). */
    public static function plain( string $html ): string
    {
        $text = html_entity_decode( strip_tags( str_replace( [ '</p>', '</li>', '</tr>', '</td>', '</th>', '</dd>', '</dt>', '<br>', '</blockquote>' ], ' ', $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        return HtmlSource::collapse( $text );
    }

    /** @param list<string> $notes */
    private static function node( \DOMNode $node, array &$notes, bool $pre ): string
    {
        if ( $node instanceof \DOMText ) {
            // Whitespace between the items of a list or the cells of a table is layout, not text.
            $parent = $node->parentNode;
            if ( ! $pre && $parent instanceof \DOMElement && in_array( strtolower( $parent->tagName ), [ 'ul', 'ol', 'dl', 'table', 'thead', 'tbody', 'tfoot', 'tr' ], true ) && '' === trim( (string) $node->nodeValue ) ) {
                return '';
            }
            $text = $pre ? (string) $node->nodeValue : (string) preg_replace( '/[ \t\r\n\f]+/', ' ', $node->nodeValue ?? '' );

            return htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        }
        if ( ! $node instanceof \DOMElement ) {
            return '';
        }
        $tag = strtolower( $node->tagName );

        $isPictureWithImage = 'picture' === $tag && $node->getElementsByTagName( 'img' )->length > 0 && '' === HtmlSource::collapse( $node->textContent );
        if ( $isPictureWithImage || ( 'picture' !== $tag && in_array( $tag, self::DROPPED_INLINE, true ) ) ) {
            if ( 'img' === $tag || 'picture' === $tag ) {
                $notes[] = 'An image inside a line of text was not converted. Add it as its own image.';
            }

            return '';
        }
        $inner = self::children( $node, $notes, $pre || 'pre' === $tag );

        if ( in_array( $tag, self::HEADINGS, true ) ) {
            return '' === trim( strip_tags( $inner ) ) ? '' : '<p><strong>' . trim( $inner ) . '</strong></p>';
        }
        if ( ! in_array( $tag, self::ALLOWED, true ) ) {
            return $inner; // unwrap: div, span, section, font, label ...
        }

        switch ( $tag ) {
            case 'br':
                return '<br>';
            case 'a':
                return self::link( $node, $inner );
            case 'th':
            case 'td':
                return '<' . $tag . self::spans( $node ) . '>' . trim( $inner ) . '</' . $tag . '>';
            case 'ol':
                $start = $node->getAttribute( 'start' );

                return '' === trim( strip_tags( $inner ) ) ? '' : '<ol' . ( 1 === preg_match( '/^\d{1,4}$/', $start ) ? ' start="' . $start . '"' : '' ) . '>' . $inner . '</ol>';
            case 'p':
            case 'li':
            case 'dt':
            case 'dd':
            case 'blockquote':
            case 'pre':
            case 'caption':
                return '' === trim( strip_tags( $inner ) ) ? '' : '<' . $tag . '>' . ( 'pre' === $tag ? $inner : trim( $inner ) ) . '</' . $tag . '>';
            case 'ul':
            case 'dl':
            case 'table':
            case 'thead':
            case 'tbody':
            case 'tfoot':
            case 'tr':
                return '' === trim( strip_tags( $inner ) ) ? '' : '<' . $tag . '>' . $inner . '</' . $tag . '>';
            default:
                return '' === $inner ? '' : '<' . $tag . '>' . $inner . '</' . $tag . '>';
        }
    }

    private static function link( \DOMElement $a, string $inner ): string
    {
        if ( '' === trim( strip_tags( $inner ) ) ) {
            return '';
        }
        $href = $a->hasAttribute( 'href' ) ? $a->getAttribute( 'href' ) : '';
        if ( '' === $href ) {
            return $inner;
        }
        $out = '<a href="' . htmlspecialchars( $href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
        $title = HtmlSource::collapse( $a->getAttribute( 'title' ) );
        if ( '' !== $title ) {
            $out .= ' title="' . htmlspecialchars( $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
        }
        if ( '_blank' === strtolower( $a->getAttribute( 'target' ) ) ) {
            $out .= ' target="_blank" rel="noopener noreferrer"';
        }

        return $out . '>' . $inner . '</a>';
    }

    private static function spans( \DOMElement $cell ): string
    {
        $out = '';
        foreach ( [ 'colspan', 'rowspan' ] as $attr ) {
            $v = $cell->getAttribute( $attr );
            if ( 1 === preg_match( '/^\d{1,2}$/', $v ) && (int) $v > 1 ) {
                $out .= ' ' . $attr . '="' . (int) $v . '"';
            }
        }

        return $out;
    }
}
