<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The owner's own texts and picture for a panel, and what to do when the panel was edited in the Divi builder, per top-level menu
 * item. One option holds them all, keyed by the menu item's id (an id belongs to one menu, so several menus never collide; entries of items
 * that are not in the current menu stay stored and are ignored).
 *
 *   title, text, button, url   replace the item's title, its description, "View all" and the item's own link on the card, banner or intro panel
 *   image                      an attachment id (an image the Media Library holds); resolved to a URL and alt text when the panel is built
 *   edits                      `ask` | `keep` | `rebuild`: what an update does with a panel that was changed in the builder
 *
 * Every value is cleaned on the way in; nothing here writes to the header.
 */
final class MegaMenuContent
{
    public const OPTION = 'aied_pro_megamenu_content';

    public const EDITS = [ 'ask', 'keep', 'rebuild' ];

    public const MAX_TITLE  = 120;
    public const MAX_TEXT   = 280;
    public const MAX_BUTTON = 40;
    public const MAX_URL    = 300;

    private \Closure $read;

    private \Closure $write;

    private \Closure $attachment;

    /**
     * @param ?\Closure $read       fn(): mixed, the stored option (default: get_option)
     * @param ?\Closure $write      fn(array): void, store the option (default: update_option, not autoloaded)
     * @param ?\Closure $attachment fn(int): ?array{url:string,alt:string}, an image attachment's URL and alt text (default: the Media Library)
     */
    public function __construct( ?\Closure $read = null, ?\Closure $write = null, ?\Closure $attachment = null )
    {
        $this->read       = $read ?? static fn (): mixed => get_option( self::OPTION, [] );
        $this->write      = $write ?? static function ( array $all ): void {
            update_option( self::OPTION, $all, false );
        };
        $this->attachment = $attachment ?? self::attachmentFromLibrary( ... );
    }

    /** @return array<int,array{title:string,text:string,button:string,url:string,image:int,edits:string}> every stored entry, item id => entry */
    public function all(): array
    {
        $raw = ( $this->read )();
        $out = [];
        if ( ! is_array( $raw ) ) {
            return $out;
        }
        foreach ( $raw as $id => $entry ) {
            if ( is_array( $entry ) && (int) $id > 0 ) {
                $out[ (int) $id ] = self::normalise( $entry );
            }
        }

        return $out;
    }

    /** @return array{title:string,text:string,button:string,url:string,image:int,edits:string} */
    public function get( int $itemId ): array
    {
        return $this->all()[ $itemId ] ?? self::normalise( [] );
    }

    /** Whether the owner has put any text or picture of their own on this item (the `edits` choice alone does not count). */
    public function hasContent( int $itemId ): bool
    {
        $e = $this->get( $itemId );

        return '' !== $e['title'] . $e['text'] . $e['button'] . $e['url'] || $e['image'] > 0;
    }

    /**
     * Stores the texts and the picture of one item (what is not given is emptied) and keeps its `edits` choice.
     *
     * @param array<string,mixed> $in title, text, button, url, image
     * @return array{title:string,text:string,button:string,url:string,image:int,edits:string} what was stored
     */
    public function save( int $itemId, array $in ): array
    {
        $now = $this->get( $itemId );
        $new = self::normalise( [
            'title'  => $in['title'] ?? '',
            'text'   => $in['text'] ?? '',
            'button' => $in['button'] ?? '',
            'url'    => $in['url'] ?? '',
            'image'  => $in['image'] ?? 0,
            'edits'  => $now['edits'],
        ] );
        if ( $new['image'] > 0 && null === ( $this->attachment )( $new['image'] ) ) {
            $new['image'] = 0; // not an image (or gone): never store a picture that cannot be shown
        }

        return $this->put( $itemId, $new );
    }

    /** Takes the owner's texts and picture of this item away (the `edits` choice stays). */
    public function reset( int $itemId ): void
    {
        $now = $this->get( $itemId );
        $this->put( $itemId, self::normalise( [ 'edits' => $now['edits'] ] ) );
    }

    /** What an update does with this item's panel when it was edited in the builder. An unknown value is refused. */
    public function setEdits( int $itemId, string $policy ): void
    {
        if ( ! in_array( $policy, self::EDITS, true ) ) {
            throw new \InvalidArgumentException( 'Unknown choice for an edited panel: "' . $policy . '".' );
        }
        $now          = $this->get( $itemId );
        $now['edits'] = $policy;
        $this->put( $itemId, $now );
    }

    /**
     * What the panel builder reads: for each item with something of its own, the non-empty texts, the link, and the picture as
     * {url, alt}. A picture that cannot be resolved any more is dropped.
     *
     * @return array<int,array{title:string,text:string,button:string,url:string,image:?array{url:string,alt:string}}>
     */
    public function forBuilder(): array
    {
        $out = [];
        foreach ( $this->all() as $id => $e ) {
            $image = $e['image'] > 0 ? ( $this->attachment )( $e['image'] ) : null;
            if ( '' === $e['title'] . $e['text'] . $e['button'] . $e['url'] && null === $image ) {
                continue;
            }
            $out[ $id ] = [ 'title' => $e['title'], 'text' => $e['text'], 'button' => $e['button'], 'url' => $e['url'], 'image' => $image ];
        }

        return $out;
    }

    // ---- cleaning ----------------------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $e
     * @return array{title:string,text:string,button:string,url:string,image:int,edits:string}
     */
    public static function normalise( array $e ): array
    {
        $edits = $e['edits'] ?? 'ask';
        $image = $e['image'] ?? 0;

        return [
            'title'  => self::line( $e['title'] ?? '', self::MAX_TITLE ),
            'text'   => self::line( $e['text'] ?? '', self::MAX_TEXT ),
            'button' => self::line( $e['button'] ?? '', self::MAX_BUTTON ),
            'url'    => self::url( $e['url'] ?? '' ),
            'image'  => is_numeric( $image ) && (int) $image > 0 ? (int) $image : 0,
            'edits'  => is_string( $edits ) && in_array( $edits, self::EDITS, true ) ? $edits : 'ask',
        ];
    }

    /** One line of plain text: no markup, runs of white space made one space, cut to `$max` characters. */
    private static function line( mixed $v, int $max ): string
    {
        if ( ! is_string( $v ) ) {
            return '';
        }
        $v = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $v ) ) );

        return mb_substr( $v, 0, $max );
    }

    /** An http(s) address or a path on this site; anything else (javascript:, data:, //host, mailto:) becomes empty. */
    private static function url( mixed $v ): string
    {
        if ( ! is_string( $v ) ) {
            return '';
        }
        $v = trim( $v );
        if ( '' === $v || mb_strlen( $v ) > self::MAX_URL || 1 === preg_match( '/[\x00-\x20<>"\'\\\\]/', $v ) ) {
            return '';
        }
        if ( 1 === preg_match( '#^/(?!/)#', $v ) ) {
            return $v;
        }
        $parts = parse_url( $v );

        return is_array( $parts ) && in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) && '' !== (string) ( $parts['host'] ?? '' ) ? $v : '';
    }

    /**
     * @param array{title:string,text:string,button:string,url:string,image:int,edits:string} $entry
     * @return array{title:string,text:string,button:string,url:string,image:int,edits:string}
     */
    private function put( int $itemId, array $entry ): array
    {
        $all = $this->all();
        if ( '' === $entry['title'] . $entry['text'] . $entry['button'] . $entry['url'] && 0 === $entry['image'] && 'ask' === $entry['edits'] ) {
            unset( $all[ $itemId ] );
        } else {
            $all[ $itemId ] = $entry;
        }
        ( $this->write )( $all );

        return $entry;
    }

    /** @return array{url:string,alt:string}|null */
    public static function attachmentFromLibrary( int $id ): ?array
    {
        if ( $id < 1 || ! function_exists( 'wp_attachment_is_image' ) || ! wp_attachment_is_image( $id ) ) {
            return null;
        }
        $src = wp_get_attachment_image_src( $id, 'large' );
        if ( ! is_array( $src ) || '' === (string) ( $src[0] ?? '' ) ) {
            return null;
        }

        return [ 'url' => (string) $src[0], 'alt' => trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ];
    }
}
