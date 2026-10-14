<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Which image an <img> becomes. A third-party address never reaches the layout: an image is either one of this
 * site's own (the Media Library), a token of the built-in pack, or a built-in pack image chosen as a PLACEHOLDER by
 * simple, fixed rules (the report lists the original so the owner or assistant can swap it for a real one).
 */
final class HtmlImages
{
    public const MODE_PLACEHOLDER = 'placeholder';
    public const MODE_KEEP        = 'keep';

    /**
     * @param callable(string):bool $isSiteImage whether this URL is an image of this site's Media Library
     */
    public function __construct( private readonly array $manifest, private readonly mixed $isSiteImage )
    {
    }

    /**
     * @param array<string,mixed> $img an image module from HtmlMapper
     * @return array{src:string, action:string, token:?string}
     */
    public function resolve( array $img, string $mode, int $sectionIndex, int $imageIndex ): array
    {
        $src = (string) ( $img['src'] ?? '' );
        if ( 1 === preg_match( '/^\{\{aied:image:[a-z0-9-]+\}\}$/', $src ) ) {
            return [ 'src' => $src, 'action' => 'kept (built-in image)', 'token' => null ];
        }
        if ( self::MODE_KEEP === $mode && '' !== $src && ( $this->isSiteImage )( $src ) ) {
            return [ 'src' => $src, 'action' => 'kept (this site\'s Media Library)', 'token' => null ];
        }
        $token = $this->pick( $img, $sectionIndex, $imageIndex );

        return [ 'src' => '{{aied:image:' . $token . '}}', 'action' => 'replaced by a placeholder from the built-in pack', 'token' => $token ];
    }

    /** The role the pack organises its images by: hero, card, avatar, square, logo, section-bg. */
    public function roleFor( array $img, int $sectionIndex, int $imageIndex ): string
    {
        $hint = strtolower( (string) ( $img['hint'] ?? '' ) . ' ' . (string) ( $img['alt'] ?? '' ) );
        if ( 1 === preg_match( '/avatar|headshot|portrait|team|person|profile|author|testimonial|face/', $hint ) ) {
            return 'avatar';
        }
        if ( 1 === preg_match( '/logo|brand|partner|client|badge/', $hint ) ) {
            return 'logo';
        }
        $w = (int) ( $img['width'] ?? 0 );
        $h = (int) ( $img['height'] ?? 0 );
        if ( $w > 0 && $h > 0 ) {
            $ratio = $w / $h;
            if ( $ratio >= 2.6 ) {
                return 'logo';
            }
            if ( $ratio >= 1.6 ) {
                return 0 === $sectionIndex && 0 === $imageIndex ? 'hero' : 'section-bg';
            }
            if ( $ratio >= 1.15 ) {
                return 'card';
            }

            return 'square';
        }
        if ( 1 === preg_match( '/hero|banner|cover|header/', $hint ) || ( 0 === $sectionIndex && 0 === $imageIndex ) ) {
            return 'hero';
        }

        return 'card';
    }

    private function pick( array $img, int $sectionIndex, int $imageIndex ): string
    {
        $role  = $this->roleFor( $img, $sectionIndex, $imageIndex );
        $pool  = [];
        foreach ( (array) ( $this->manifest['images'] ?? [] ) as $i ) {
            if ( ( $i['role'] ?? '' ) === $role && '' !== (string) ( $i['token'] ?? '' ) ) {
                $pool[] = (string) $i['token'];
            }
        }
        if ( array() === $pool ) {
            return (string) ( $this->manifest['fallback'] ?? 'square-slate-1' );
        }
        sort( $pool );

        // Stable and spread out: the same source gives the same placeholder, different sources differ.
        return $pool[ crc32( (string) ( $img['src'] ?? '' ) . '|' . (string) ( $img['alt'] ?? '' ) . '|' . $sectionIndex . '|' . $imageIndex ) % count( $pool ) ];
    }
}
