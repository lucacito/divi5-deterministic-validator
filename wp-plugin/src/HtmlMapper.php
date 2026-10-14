<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Step two of html_to_divi: the cleaned DOM becomes a plan of sections, each a list of chunks, each chunk either a
 * single column of modules or a group of "cards" side by side. Pure rules, named constants, no guessing from styles:
 * the same HTML always gives the same plan.
 *
 * Module shapes (arrays):
 *   heading { type, level:int, text }          text { type, html }          divider { type }
 *   image   { type, src, alt, width:?int, height:?int, path, hint }
 *   button  { type, text, href:?string, newTab:bool }
 *
 * @phpstan-type Module array<string,mixed>
 * @phpstan-type Chunk  array{kind:string, modules?:list<array<string,mixed>>, columns?:list<list<array<string,mixed>>>}
 */
final class HtmlMapper
{
    /** A group of siblings becomes columns when there are at least this many ... */
    public const CARDS_MIN = 2;
    /** ... and at most this many. */
    public const CARDS_MAX = 12;
    /** At most this many columns sit in one row (Divi rows: 1 to 4 equal columns). */
    public const COLUMNS_MAX = 4;
    /** At least this share of the siblings must have the same make-up (heading + text, image + text ...). */
    public const SIMILARITY = 0.6;

    private const HEADINGS = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];
    private const PROSE    = [ 'p', 'ul', 'ol', 'dl', 'blockquote', 'pre', 'table', 'address' ];
    private const INLINE   = [ 'a', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup', 'code', 'mark', 'del', 'ins', 'abbr', 'cite', 'q', 'label', 'font', 'time', 'kbd', 'var', 'samp', 'br', 'wbr', 'bdi', 'bdo', 'data' ];
    /** Tags that can be one card of a group. */
    private const CARD_TAGS = [ 'div', 'article', 'section', 'li', 'figure' ];
    /** Tags that are only wrappers around sections. */
    private const TRANSPARENT = [ 'div', 'main', 'article', 'center' ];

    /** @var list<array{type:string,reason:string,path:string}> */
    private array $flagged = [];

    /** @var list<string> */
    private array $notes = [];

    private bool $h1Seen = false;

    private int $demotedH1 = 0;

    /** @var array<string,int> */
    private array $counts = [];

    /**
     * @return array{sections:list<array{label:string,chunks:list<array<string,mixed>>}>, flagged:list<array{type:string,reason:string,path:string}>, notes:list<string>, counts:array<string,int>}
     */
    public static function map( \DOMElement $body ): array
    {
        $m = new self();
        $sections = $m->sections( $body );

        if ( $m->demotedH1 > 0 ) {
            $m->notes[] = sprintf( 'A page may have only one main heading: %d extra h1 heading(s) became h2.', $m->demotedH1 );
        }

        return [ 'sections' => $sections, 'flagged' => $m->flagged, 'notes' => array_values( array_unique( $m->notes ) ), 'counts' => $m->counts ];
    }

    /** The widths of the rows a group of $n cards is spread over: 2, 3 and 4 stay one row; more are split evenly. @return list<int> */
    public static function rowWidths( int $n ): array
    {
        if ( $n <= self::COLUMNS_MAX ) {
            return [ max( 1, $n ) ];
        }
        $rows  = (int) ceil( $n / self::COLUMNS_MAX );
        $base  = intdiv( $n, $rows );
        $extra = $n % $rows;
        $out   = [];
        for ( $i = 0; $i < $rows; $i++ ) {
            $out[] = $base + ( $i < $extra ? 1 : 0 );
        }

        return $out;
    }

    // ── sections ────────────────────────────────────────────────────────────

    /** @return list<array{label:string,chunks:list<array<string,mixed>>}> */
    private function sections( \DOMElement $body ): array
    {
        $nodes = $this->unwrap( $this->meaningful( $body ) );

        $sections = [];
        $loose    = [];
        $flush    = function () use ( &$loose, &$sections ): void {
            if ( array() === $loose ) {
                return;
            }
            $chunks = $this->chunks( $loose, true );
            if ( array() !== $chunks ) {
                $sections[] = [ 'label' => $this->labelFor( null, $chunks, count( $sections ) + 1 ), 'chunks' => $chunks ];
            }
            $loose = [];
        };

        foreach ( $nodes as $node ) {
            if ( $node instanceof \DOMElement && 'nav' === strtolower( $node->tagName ) ) {
                $flush();
                $this->flagNav( $node );
                continue;
            }
            if ( $node instanceof \DOMElement && $this->isSectionLike( $node ) ) {
                $flush();
                $chunks = $this->chunks( $this->meaningful( $node ), true );
                if ( array() !== $chunks ) {
                    $sections[] = [ 'label' => $this->labelFor( $node, $chunks, count( $sections ) + 1 ), 'chunks' => $chunks ];
                }
                continue;
            }
            $loose[] = $node;
        }
        $flush();

        return $sections;
    }

    /** A top-level element that is a section by itself (it holds other elements). */
    private function isSectionLike( \DOMElement $el ): bool
    {
        $tag = strtolower( $el->tagName );
        if ( ! in_array( $tag, [ 'header', 'section', 'article', 'footer', 'aside', 'main', 'div' ], true ) ) {
            return false;
        }
        foreach ( $el->childNodes as $c ) {
            if ( $c instanceof \DOMElement ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wrappers that hold nothing but sections are looked through: <body><div id="app"><main> ... </main></div></body>.
     *
     * @param list<\DOMNode> $nodes
     * @return list<\DOMNode>
     */
    private function unwrap( array $nodes ): array
    {
        for ( $guard = 0; $guard < 12; $guard++ ) {
            $changed = false;
            $out     = [];
            foreach ( $nodes as $node ) {
                if ( $node instanceof \DOMElement && 'main' === strtolower( $node->tagName ) ) {
                    array_push( $out, ...$this->meaningful( $node ) ); // <main> is only a landmark around the sections
                    $changed = true;
                    continue;
                }
                $out[] = $node;
            }
            $nodes = $out;
            if ( 1 === count( $nodes ) && $nodes[0] instanceof \DOMElement && in_array( strtolower( $nodes[0]->tagName ), self::TRANSPARENT, true ) ) {
                // A lone wrapper (div, article, center ...) around everything is only a wrapper: look inside it.
                $inner = $this->meaningful( $nodes[0] );
                if ( array() !== $inner ) {
                    $nodes   = $inner;
                    $changed = true;
                }
            }
            if ( ! $changed ) {
                break;
            }
        }

        return $nodes;
    }

    /** @param list<array<string,mixed>> $chunks */
    private function labelFor( ?\DOMElement $el, array $chunks, int $n ): string
    {
        $tag = null === $el ? '' : strtolower( $el->tagName );
        if ( 'header' === $tag ) {
            return 'Header';
        }
        if ( 'footer' === $tag ) {
            return 'Footer';
        }
        foreach ( $chunks as $chunk ) {
            foreach ( $chunk['modules'] ?? [] as $m ) {
                if ( 'heading' === $m['type'] ) {
                    $t = (string) $m['text'];

                    return function_exists( 'mb_substr' ) ? mb_substr( $t, 0, 40 ) : substr( $t, 0, 40 );
                }
            }
        }

        return 'Section ' . $n;
    }

    // ── chunks ──────────────────────────────────────────────────────────────

    /**
     * @param list<\DOMNode> $nodes
     * @return list<array<string,mixed>> chunks
     */
    private function chunks( array $nodes, bool $allowCards ): array
    {
        $chunks  = [];
        $current = [];
        $this->walk( $nodes, $allowCards, $chunks, $current );
        $this->closeSingle( $chunks, $current );

        return $chunks;
    }

    /**
     * @param list<\DOMNode>            $nodes
     * @param list<array<string,mixed>> $chunks
     * @param list<array<string,mixed>> $current the modules of the single-column chunk being built
     */
    private function walk( array $nodes, bool $allowCards, array &$chunks, array &$current ): void
    {
        $run = []; // consecutive inline nodes: they become one paragraph

        $flushRun = function () use ( &$run, &$current ): void {
            if ( array() === $run ) {
                return;
            }
            $html = '';
            foreach ( $run as $n ) {
                $html .= HtmlProse::html( $n, $this->notes );
            }
            $run = [];
            $html = trim( $html );
            if ( '' !== HtmlSource::collapse( html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
                $this->addProse( $current, '<p>' . $html . '</p>' );
            }
        };

        $groups = $allowCards ? $this->cardRuns( $nodes ) : [];
        $skipTo = 0;

        foreach ( $nodes as $i => $node ) {
            if ( $i < $skipTo ) {
                continue;
            }
            if ( isset( $groups[ $i ] ) ) {
                // A run of similar sibling elements next to other content: they become columns.
                $flushRun();
                $this->closeSingle( $chunks, $current );
                $chunks[] = $this->cardsChunk( array_slice( $nodes, $i, $groups[ $i ] ) );
                $skipTo   = $i + $groups[ $i ];
                continue;
            }
            if ( $node instanceof \DOMText ) {
                $run[] = $node;
                continue;
            }
            if ( ! $node instanceof \DOMElement ) {
                continue;
            }
            $tag = strtolower( $node->tagName );

            if ( 'a' === $tag ) {
                $img = $this->soleImage( $node );
                if ( null !== $img ) {
                    $flushRun();
                    $this->addImage( $current, $img, $node );
                    continue;
                }
                if ( $this->isButtonLike( $node, $nodes, (int) $i, $run ) ) {
                    $flushRun();
                    $this->addButton( $current, $this->visibleText( $node ), $node->getAttribute( 'href' ), '_blank' === strtolower( $node->getAttribute( 'target' ) ) );
                    continue;
                }
                $run[] = $node;
                continue;
            }
            if ( in_array( $tag, self::INLINE, true ) ) {
                $run[] = $node;
                continue;
            }

            $flushRun();

            if ( in_array( $tag, self::HEADINGS, true ) ) {
                $this->addHeading( $current, $node );
            } elseif ( 'img' === $tag ) {
                $this->addImage( $current, $node, null );
            } elseif ( 'picture' === $tag && '' === HtmlSource::collapse( $node->textContent ) && $node->getElementsByTagName( 'img' )->item( 0 ) instanceof \DOMElement ) {
                $this->addImage( $current, $node->getElementsByTagName( 'img' )->item( 0 ), null );
            } elseif ( 'hr' === $tag ) {
                $current[] = [ 'type' => 'divider' ];
                $this->count( 'divider' );
            } elseif ( 'button' === $tag ) {
                $text = $this->visibleText( $node );
                if ( '' !== $text ) {
                    $this->addButton( $current, $text, '', false );
                    $this->flag( 'button', 'This button had no link, so it was added without one. Set its link in Divi.', $node );
                }
            } elseif ( 'nav' === $tag ) {
                $this->flagNav( $node );
            } elseif ( 'ul' === $tag || 'ol' === $tag ) {
                $cards = $allowCards ? $this->cardGroup( $node ) : null;
                if ( null !== $cards ) {
                    $this->closeSingle( $chunks, $current );
                    $chunks[] = $this->cardsChunk( $cards );
                } else {
                    $this->addProse( $current, HtmlProse::html( $node, $this->notes ) );
                }
            } elseif ( 'p' === $tag && $this->isPlainParagraph( $node ) ) {
                $this->addProse( $current, HtmlProse::html( $node, $this->notes ) );
            } elseif ( in_array( $tag, self::PROSE, true ) && 'p' !== $tag ) {
                $this->addProse( $current, HtmlProse::html( $node, $this->notes ) );
            } else {
                // A container (or a paragraph that holds images or buttons): look inside. A group of similar
                // children is found by the run detection of the walk below.
                $this->walk( $this->meaningful( $node ), $allowCards, $chunks, $current );
            }
        }
        $flushRun();
    }

    /** @param list<array<string,mixed>> $chunks @param list<array<string,mixed>> $current */
    private function closeSingle( array &$chunks, array &$current ): void
    {
        if ( array() !== $current ) {
            $chunks[] = [ 'kind' => 'single', 'modules' => $current ];
            $current  = [];
        }
    }

    // ── cards (columns) ─────────────────────────────────────────────────────

    /**
     * Runs of similar sibling elements among $nodes (features, pricing plans, team members ...): start index => length.
     *
     * @param list<\DOMNode> $nodes
     * @return array<int,int>
     */
    private function cardRuns( array $nodes ): array
    {
        $runs = [];
        $n    = count( $nodes );
        $i    = 0;
        while ( $i < $n ) {
            $node = $nodes[ $i ];
            if ( ! $node instanceof \DOMElement || ! in_array( strtolower( $node->tagName ), self::CARD_TAGS, true ) ) {
                ++$i;
                continue;
            }
            $tag = strtolower( $node->tagName );
            $j   = $i;
            while ( $j < $n && $nodes[ $j ] instanceof \DOMElement && strtolower( $nodes[ $j ]->tagName ) === $tag ) {
                ++$j;
            }
            $length = $j - $i;
            $kids   = array_slice( $nodes, $i, $length );
            if ( $length >= self::CARDS_MIN && $length <= self::CARDS_MAX && $this->areCards( $kids ) ) {
                $runs[ $i ] = $length;
            }
            $i = max( $j, $i + 1 );
        }

        return $runs;
    }

    /**
     * The card elements when $el (a list) is a group of similar items; null when it is an ordinary list.
     *
     * @return ?list<\DOMElement>
     */
    private function cardGroup( \DOMElement $el ): ?array
    {
        $kids = [];
        foreach ( $el->childNodes as $c ) {
            if ( $c instanceof \DOMText && '' !== HtmlSource::collapse( $c->textContent ) ) {
                return null; // text sits directly in the container: it is prose, not a grid
            }
            if ( $c instanceof \DOMElement ) {
                $kids[] = $c;
            }
        }
        $n = count( $kids );
        if ( $n < self::CARDS_MIN || $n > self::CARDS_MAX || ! in_array( strtolower( $kids[0]->tagName ), self::CARD_TAGS, true ) ) {
            return null;
        }

        return $this->areCards( $kids ) ? $kids : null;
    }

    /**
     * Whether these sibling elements are cards: the same tag, each with content, most of them made of the same kinds
     * of things, and enough substance (two kinds of content, or a row of images).
     *
     * @param list<\DOMNode> $kids
     */
    private function areCards( array $kids ): bool
    {
        $n   = count( $kids );
        $tag = $kids[0] instanceof \DOMElement ? strtolower( $kids[0]->tagName ) : '';
        $signatures = [];
        foreach ( $kids as $k ) {
            if ( ! $k instanceof \DOMElement || strtolower( $k->tagName ) !== $tag ) {
                return false;
            }
            $sig = $this->signature( $k );
            if ( '' === $sig ) {
                return false; // an empty card: not a content grid
            }
            $signatures[] = $sig;
        }
        $counts = array_count_values( $signatures );
        arsort( $counts );
        $topSig = (string) array_key_first( $counts );
        if ( $counts[ $topSig ] < (int) ceil( self::SIMILARITY * $n ) ) {
            return false;
        }

        return count( explode( '+', $topSig ) ) >= 2 || 'image' === $topSig;
    }

    /** What a card is made of, as a sorted string such as "heading+prose" ('' when it holds nothing). */
    private function signature( \DOMElement $card ): string
    {
        $kinds = [];
        $stack = [ $card ];
        while ( array() !== $stack ) {
            $el = array_pop( $stack );
            foreach ( $el->childNodes as $c ) {
                if ( $c instanceof \DOMText ) {
                    if ( '' !== HtmlSource::collapse( $c->textContent ) ) {
                        $kinds['prose'] = true;
                    }
                    continue;
                }
                if ( ! $c instanceof \DOMElement ) {
                    continue;
                }
                $t = strtolower( $c->tagName );
                if ( in_array( $t, self::HEADINGS, true ) ) {
                    $kinds['heading'] = true;
                } elseif ( 'img' === $t || 'picture' === $t ) {
                    $kinds['image'] = true;
                } elseif ( 'button' === $t || ( 'a' === $t && $this->hasButtonClass( $c ) ) ) {
                    $kinds['button'] = true;
                } elseif ( in_array( $t, [ 'p', 'ul', 'ol', 'blockquote', 'table' ], true ) ) {
                    $kinds['prose'] = true;
                } else {
                    $stack[] = $c;
                }
            }
        }
        ksort( $kinds );

        return implode( '+', array_keys( $kinds ) );
    }

    /**
     * @param list<\DOMNode> $cards
     * @return array<string,mixed>
     */
    private function cardsChunk( array $cards ): array
    {
        $columns = [];
        foreach ( $cards as $card ) {
            $modules = [];
            $chunks  = [];
            // Inside a card everything is flattened into one column (no grids inside grids).
            $this->walk( $this->meaningful( $card ), false, $chunks, $modules );
            foreach ( $chunks as $c ) {
                array_push( $modules, ...( $c['modules'] ?? [] ) );
            }
            $columns[] = $modules;
        }
        $this->count( 'cards' );

        return [ 'kind' => 'cards', 'columns' => $columns ];
    }

    // ── modules ─────────────────────────────────────────────────────────────

    /** @param list<array<string,mixed>> $current */
    private function addHeading( array &$current, \DOMElement $h ): void
    {
        $text = $this->visibleText( $h );
        if ( '' === $text ) {
            return;
        }
        $level = (int) substr( strtolower( $h->tagName ), 1 );
        if ( 1 === $level ) {
            if ( $this->h1Seen ) {
                $level = 2;
                ++$this->demotedH1;
            }
            $this->h1Seen = true;
        }
        if ( $h->getElementsByTagName( 'a' )->length > 0 ) {
            $this->flag( 'link', 'A link inside a heading was kept as plain heading text. Add the link in Divi if you need it.', $h );
        }
        $current[] = [ 'type' => 'heading', 'level' => $level, 'text' => $text ];
        $this->count( 'heading' );
    }

    /** @param list<array<string,mixed>> $current */
    private function addProse( array &$current, string $html ): void
    {
        $html = trim( $html );
        if ( '' === HtmlProse::plain( $html ) ) {
            return;
        }
        $last = array() === $current ? null : $current[ count( $current ) - 1 ];
        if ( null !== $last && 'text' === $last['type'] ) {
            $current[ count( $current ) - 1 ]['html'] .= $html;

            return;
        }
        $current[] = [ 'type' => 'text', 'html' => $html ];
        $this->count( 'text' );
    }

    /** @param list<array<string,mixed>> $current */
    private function addImage( array &$current, \DOMElement $img, ?\DOMElement $link ): void
    {
        $w = $img->getAttribute( 'width' );
        $h = $img->getAttribute( 'height' );
        $current[] = [
            'type'   => 'image',
            'src'    => $img->getAttribute( 'src' ),
            'alt'    => HtmlSource::collapse( $img->getAttribute( 'alt' ) ),
            'width'  => 1 === preg_match( '/^\d{1,5}$/', $w ) ? (int) $w : null,
            'height' => 1 === preg_match( '/^\d{1,5}$/', $h ) ? (int) $h : null,
            'path'   => HtmlSource::path( $img ),
            'hint'   => strtolower( $img->getAttribute( 'class' ) . ' ' . $img->getAttribute( 'id' ) . ' ' . $img->getAttribute( 'src' ) ),
            'href'   => null !== $link && $link->hasAttribute( 'href' ) ? $link->getAttribute( 'href' ) : null,
        ];
        $this->count( 'image' );
    }

    /** @param list<array<string,mixed>> $current */
    private function addButton( array &$current, string $text, string $href, bool $newTab ): void
    {
        if ( '' === $text ) {
            return;
        }
        $current[] = [ 'type' => 'button', 'text' => $text, 'href' => '' === $href ? null : $href, 'newTab' => $newTab ];
        $this->count( 'button' );
    }

    // ── small predicates ────────────────────────────────────────────────────

    /** An <a> that is a call-to-action button: marked as one, or standing alone as the only thing in its block. */
    private function isButtonLike( \DOMElement $a, array $siblings, int $index, array $run ): bool
    {
        if ( '' === HtmlSource::collapse( $a->textContent ) || ! $a->hasAttribute( 'href' ) ) {
            return false;
        }
        if ( $this->hasButtonClass( $a ) ) {
            return true;
        }
        if ( array() !== $run || strlen( HtmlSource::collapse( $a->textContent ) ) > 40 ) {
            return false;
        }
        // Standing alone: no other text or element next to it in the same block.
        foreach ( $siblings as $j => $s ) {
            if ( $j === $index ) {
                continue;
            }
            if ( $s instanceof \DOMElement || ( $s instanceof \DOMText && '' !== HtmlSource::collapse( $s->textContent ) ) ) {
                return false;
            }
        }

        return true;
    }

    private function hasButtonClass( \DOMElement $a ): bool
    {
        return 1 === preg_match( '/(^|[\s_-])(btn|button|cta)([\s_-]|$)/i', $a->getAttribute( 'class' ) ) || 'button' === strtolower( $a->getAttribute( 'role' ) );
    }

    /** The only <img> inside an <a> that holds nothing else visible. */
    private function soleImage( \DOMElement $a ): ?\DOMElement
    {
        $imgs = $a->getElementsByTagName( 'img' );
        if ( 1 !== $imgs->length || '' !== HtmlSource::collapse( $a->textContent ) ) {
            return null;
        }
        $img = $imgs->item( 0 );

        return $img instanceof \DOMElement ? $img : null;
    }

    /** A <p> whose content is text and inline markup only (images and buttons inside make it a container). */
    private function isPlainParagraph( \DOMElement $p ): bool
    {
        foreach ( [ 'img', 'picture', 'button', 'hr', 'nav' ] as $t ) {
            if ( $p->getElementsByTagName( $t )->length > 0 ) {
                return false;
            }
        }

        return true;
    }

    /** @return list<\DOMNode> the children that are an element or text that is not only whitespace */
    private function meaningful( \DOMNode $node ): array
    {
        $out = [];
        foreach ( $node->childNodes as $c ) {
            if ( $c instanceof \DOMElement || ( $c instanceof \DOMText && '' !== HtmlSource::collapse( $c->textContent ) ) ) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /** The text of a node as the converter counts it: navigation menus are not converted, so their text is left out. */
    private function visibleText( \DOMNode $node ): string
    {
        $clone = $node->cloneNode( true );
        if ( $clone instanceof \DOMElement ) {
            foreach ( iterator_to_array( $clone->getElementsByTagName( 'nav' ) ) as $nav ) {
                $nav->parentNode?->removeChild( $nav );
            }
        }

        return HtmlSource::collapse( $clone->textContent );
    }

    private function flagNav( \DOMElement $nav ): void
    {
        $links = $nav->getElementsByTagName( 'a' )->length;
        $this->flag( 'nav', sprintf( 'Navigation menus are not converted (%d link(s)): the site owner sets the menu in WordPress.', $links ), $nav );
    }

    private function flag( string $type, string $reason, \DOMNode $at ): void
    {
        if ( count( $this->flagged ) < 100 ) {
            $this->flagged[] = [ 'type' => $type, 'reason' => $reason, 'path' => HtmlSource::path( $at ) ];
        }
    }

    private function count( string $what ): void
    {
        $this->counts[ $what ] = ( $this->counts[ $what ] ?? 0 ) + 1;
    }
}
