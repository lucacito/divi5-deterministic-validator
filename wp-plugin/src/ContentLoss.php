<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/CodePolicy.php';

/**
 * Notices a save that removes most of a page. A layout can be perfectly valid and still have lost the page's text, images or
 * modules (an AI that rewrites a page from a half-read copy does exactly that), and the validator cannot know that is not what the
 * person wanted. This compares the stored page with the new content by COUNTING (visible text characters, images, modules): no
 * inference, the same two inputs always give the same answer. It is an advisory only: it never changes a verdict, blocks a save or
 * alters content, because a deliberate rewrite is legitimate; the person already sees the save in the preview and can undo it.
 *
 * "Most" means more than half. A page must have enough to lose before it counts, so tiny pages do not raise it.
 */
final class ContentLoss
{
    /** A kind is only judged when the stored page had at least this much of it. */
    public const MIN_TEXT    = 200;
    public const MIN_IMAGES  = 2;
    public const MIN_MODULES = 4;

    /** The new content must keep at least this share of the old to be left alone (more than half lost = advisory). */
    public const KEEP_SHARE = 0.5;

    public const MESSAGE = "This save removes more than half of what the page had (text, images or modules; see lost). If that is not what the person asked for, read the page again with get_page_layout and put back what was dropped. Undo is available: list_page_history, then restore_page_version.";

    private const IMAGE_URL = '/\.(?:png|jpe?g|gif|webp|svg|avif)(?:[?#]|$)/i';

    private const STRUCTURE = [ 'divi/placeholder', 'divi/section', 'divi/row', 'divi/column', 'divi/row-inner', 'divi/column-inner', 'divi/group', 'divi/global-layout', 'divi/global-slot' ];

    /**
     * @return ?array{lost: list<string>, text: array{before:int,after:int}, images: array{before:int,after:int}, modules: array{before:int,after:int}, message: string}
     */
    public static function assess( string $before, string $after ): ?array
    {
        $b = self::measure( $before );
        $a = self::measure( $after );
        if ( null === $b || null === $a ) {
            return null;
        }
        $lost = [];
        foreach ( [ 'text' => self::MIN_TEXT, 'images' => self::MIN_IMAGES, 'modules' => self::MIN_MODULES ] as $kind => $min ) {
            if ( $b[ $kind ] >= $min && $a[ $kind ] < $b[ $kind ] * self::KEEP_SHARE ) {
                $lost[] = $kind;
            }
        }
        if ( [] === $lost ) {
            return null;
        }

        return [
            'lost'    => $lost,
            'text'    => [ 'before' => $b['text'], 'after' => $a['text'] ],
            'images'  => [ 'before' => $b['images'], 'after' => $a['images'] ],
            'modules' => [ 'before' => $b['modules'], 'after' => $a['modules'] ],
            'message' => self::MESSAGE,
        ];
    }

    /**
     * What a page has: visible text characters (the `innerContent` values of its modules, tags stripped, whitespace collapsed),
     * image references and modules (every block that is not layout structure). Null when the content cannot be walked safely.
     *
     * @return ?array{text:int,images:int,modules:int}
     */
    public static function measure( string $content ): ?array
    {
        if ( CodePolicy::tooLarge( $content ) || CodePolicy::tooDeep( $content ) ) {
            return null;
        }
        $parsed = ( new BlockParser() )->parse( $content );
        if ( ! $parsed->isOk() ) {
            return null;
        }
        $text    = '';
        $images  = 0;
        $modules = 0;
        $parsed->root()->walk( static function ( Block $b ) use ( &$text, &$images, &$modules ): void {
            if ( '__root__' === $b->name() ) {
                return;
            }
            if ( ! in_array( $b->name(), self::STRUCTURE, true ) ) {
                ++$modules;
            }
            self::scan( $b->attrs(), false, $text, $images );
        } );
        $plain = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

        return [ 'text' => mb_strlen( $plain ), 'images' => $images, 'modules' => $modules ];
    }

    /** @param array<mixed> $node */
    private static function scan( array $node, bool $inContent, string &$text, int &$images ): void
    {
        foreach ( $node as $key => $value ) {
            $content = $inContent || 'innerContent' === $key;
            if ( is_array( $value ) ) {
                self::scan( $value, $content, $text, $images );
            } elseif ( is_string( $value ) ) {
                if ( 1 === preg_match( self::IMAGE_URL, $value ) || str_starts_with( $value, '{{aied:image:' ) ) {
                    ++$images;
                } elseif ( $content ) {
                    $text .= ' ' . $value;
                }
            }
        }
    }
}
