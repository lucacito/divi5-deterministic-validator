<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Resolves {{aied:image:<token>}} placeholders to site-local URLs of the bundled image pack. */
final class ImageTokens
{
    public const PATTERN = '/\{\{aied:image:([a-z0-9-]+)\}\}/';

    /**
     * @param array{fallback:string, images:list<array<string,mixed>>}|null $manifest
     * @param (callable(?string, string): ?string)|null $override add-on hook: return a URL to replace the bundled one
     */
    public static function resolve( string $text, string $baseUrl, ?array $manifest = null, ?callable $override = null ): string
    {
        $manifest ??= ImagePack::manifest();
        $files    = [];
        foreach ( (array) ( $manifest['images'] ?? [] ) as $i ) {
            $token = (string) ( $i['token'] ?? '' );
            $file  = (string) ( $i['file'] ?? '' );
            if ( '' !== $token && '' !== $file ) {
                $files[ $token ] = $file;
            }
        }
        $fallback = (string) ( $manifest['fallback'] ?? '' );
        $base     = rtrim( $baseUrl, '/' );

        return (string) preg_replace_callback(
            self::PATTERN,
            static function ( array $m ) use ( $files, $fallback, $base, $override ): string {
                $token = $m[1];
                $file  = $files[ $token ] ?? ( $files[ $fallback ] ?? '' );
                $url   = $file === '' ? null : $base . '/' . $file;
                if ( null !== $override ) {
                    $custom = $override( $url, $token );
                    if ( is_string( $custom ) && '' !== $custom ) {
                        return $custom;
                    }
                }

                // An empty result only happens when the manifest has no usable file at all
                // (a broken pack; ImagePackTest guards against shipping that). Never leak the token.
                return (string) $url;
            },
            $text
        );
    }

    /** True when any {{aied:…}} placeholder text remains in $text. */
    public static function hasUnresolved( string $text ): bool
    {
        return str_contains( $text, '{{aied:' );
    }
}
