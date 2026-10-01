<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The plugin's built-in, locally bundled image pack (see assets/images/manifest.json). */
final class ImagePack
{
    private const DEFAULT_PATH = __DIR__ . '/../assets/images/manifest.json';

    /** @return array{fallback:string, images:list<array<string,mixed>>} */
    public static function manifest( ?string $path = null ): array
    {
        $raw  = is_readable( $path ?? self::DEFAULT_PATH ) ? (string) file_get_contents( $path ?? self::DEFAULT_PATH ) : '';
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || ! isset( $data['images'] ) || ! is_array( $data['images'] ) ) {
            return [ 'fallback' => '', 'images' => [] ];
        }

        return [ 'fallback' => (string) ( $data['fallback'] ?? '' ), 'images' => array_values( $data['images'] ) ];
    }

    /**
     * Markdown table of the bundled images. With an empty base URL it lists the
     * `{{aied:image:…}}` tokens (used by tests and recipes); with a base URL it
     * lists each image's real, copyable URL (what the AI must use).
     */
    public static function catalogMarkdown( string $baseUrl = '' ): string
    {
        $withUrl = '' !== $baseUrl;
        $base    = rtrim( $baseUrl, '/' );
        $lines   = $withUrl
            ? [ '| Token | URL | Role | Ratio | Palette | What it shows |', '|---|---|---|---|---|---|' ]
            : [ '| Token | Role | Ratio | Palette | What it shows |', '|---|---|---|---|---|' ];
        foreach ( self::manifest()['images'] as $i ) {
            if ( $withUrl ) {
                $lines[] = sprintf( '| `%s` | %s | %s | %s | %s | %s |', $i['token'], $base . '/' . $i['file'], $i['role'], $i['ratio'], $i['palette'], $i['alt'] );
            } else {
                $lines[] = sprintf( '| `{{aied:image:%s}}` | %s | %s | %s | %s |', $i['token'], $i['role'], $i['ratio'], $i['palette'], $i['alt'] );
            }
        }

        return implode( "\n", $lines );
    }
}
