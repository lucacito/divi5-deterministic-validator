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
        foreach ( $manifest['images'] as $i ) {
            $files[ (string) $i['token'] ] = (string) $i['file'];
        }
        $base = rtrim( $baseUrl, '/' );

        return (string) preg_replace_callback(
            self::PATTERN,
            static function ( array $m ) use ( $files, $manifest, $base, $override ): string {
                $token = $m[1];
                $file  = $files[ $token ] ?? ( $files[ $manifest['fallback'] ] ?? '' );
                $url   = $file === '' ? null : $base . '/' . $file;
                if ( null !== $override ) {
                    $custom = $override( $url, $token );
                    if ( is_string( $custom ) && '' !== $custom ) {
                        return $custom;
                    }
                }

                return (string) $url;
            },
            $text
        );
    }
}
