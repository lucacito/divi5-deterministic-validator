<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Companion;

use AiEditorDivi5\WP\HistoryCapture;
use AiEditorDivi5\WP\HistorySources;
use AiEditorDivi5\WP\LayoutGate;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Optional, off by default: "Strict validation for AI writes". When on, Divi page content saved by an ability, an application
 * password or the REST API is checked by the deterministic validator AFTER it has been saved, and a page that fails is flagged
 * for the site owner in the admin.
 *
 * It only flags. It never blocks, delays, changes or rolls back a save made by another plugin's tool, and it stores no page
 * content: a flag holds the page id, the source, the violation codes and the time. A later valid save of the same page clears the
 * flag. Saves this plugin's own tools make are skipped (they were validated before they were saved).
 */
final class StrictMode
{
    public const OPTION    = 'aied_pro_strict_validation';
    public const FLAGS     = 'aied_pro_strict_flags';
    public const MAX_FLAGS = 50;

    /** Sources whose saves are checked. Divi's own builder and unknown sources are left alone. */
    public const SOURCES = [ HistorySources::ABILITY, HistorySources::APPLICATION_PASSWORD, HistorySources::REST ];

    public function __construct( private \Closure $unlocked ) {}

    public function register(): void
    {
        add_action( 'wp_after_insert_post', [ $this, 'onSaved' ], 10, 4 );
    }

    public static function enabled(): bool
    {
        return '1' === (string) get_option( self::OPTION, '0' );
    }

    public static function set( bool $on ): void
    {
        update_option( self::OPTION, $on ? '1' : '0', false );
    }

    /** `wp_after_insert_post`: runs after the save is complete, so it can neither delay nor change it. */
    public function onSaved( mixed $postId = 0, mixed $post = null, mixed $update = false, mixed $before = null ): void
    {
        if ( ! is_int( $postId ) || ! is_object( $post ) || ! self::enabled() || ! ( $this->unlocked )() || HistoryCapture::isSuspended() ) {
            return;
        }
        if ( ! in_array( (string) ( $post->post_type ?? '' ), [ 'page', 'project' ], true ) ) {
            return;
        }
        $content = (string) ( $post->post_content ?? '' );
        if ( ! str_contains( $content, '<!-- wp:divi/' ) ) {
            return;
        }
        $source = HistoryCapture::currentSource();
        if ( ! in_array( $source['type'], self::SOURCES, true ) ) {
            return;
        }
        $codes = self::violationCodes( $content );
        if ( [] === $codes ) {
            self::clear( $postId );

            return;
        }
        self::record( $postId, (string) ( $post->post_title ?? '' ), $source['type'], $source['label'], $codes );
    }

    /** Distinct violation codes of a layout (at most 10), empty when it is valid. @return list<string> */
    public static function violationCodes( string $content ): array
    {
        $codes = [];
        foreach ( LayoutGate::check( $content )->violations() as $v ) {
            $codes[ $v->toArray()['code'] ] = true;
        }

        return array_slice( array_keys( $codes ), 0, 10 );
    }

    /** @param list<string> $codes */
    public static function record( int $postId, string $title, string $source, ?string $label, array $codes, ?string $now = null ): void
    {
        $flags = array_values( array_filter( self::flags(), static fn ( array $f ): bool => $f['post_id'] !== $postId ) );
        array_unshift( $flags, [
            'post_id' => $postId,
            'title'   => mb_substr( trim( (string) preg_replace( '/[\x00-\x1F\x7F<>]+/', ' ', $title ) ), 0, 120 ),
            'source'  => $source,
            'label'   => $label,
            'codes'   => array_slice( $codes, 0, 10 ),
            'at'      => $now ?? gmdate( 'Y-m-d\TH:i:s\Z' ),
        ] );
        update_option( self::FLAGS, array_slice( $flags, 0, self::MAX_FLAGS ), false );
    }

    public static function clear( ?int $postId = null ): void
    {
        $flags = self::flags();
        if ( null !== $postId ) {
            $kept = array_values( array_filter( $flags, static fn ( array $f ): bool => $f['post_id'] !== $postId ) );
            if ( count( $kept ) === count( $flags ) ) {
                return;
            }
            $flags = $kept;
        } else {
            $flags = [];
        }
        update_option( self::FLAGS, $flags, false );
    }

    /** @return list<array{post_id:int, title:string, source:string, label:?string, codes:list<string>, at:string}> newest first */
    public static function flags(): array
    {
        $raw = get_option( self::FLAGS, [] );
        $out = [];
        foreach ( is_array( $raw ) ? $raw : [] as $f ) {
            if ( is_array( $f ) && is_int( $f['post_id'] ?? null ) && is_string( $f['at'] ?? null ) ) {
                $out[] = [ 'post_id' => $f['post_id'], 'title' => (string) ( $f['title'] ?? '' ), 'source' => (string) ( $f['source'] ?? '' ), 'label' => isset( $f['label'] ) ? (string) $f['label'] : null, 'codes' => array_values( array_map( 'strval', (array) ( $f['codes'] ?? [] ) ) ), 'at' => $f['at'] ];
            }
        }

        return $out;
    }
}
