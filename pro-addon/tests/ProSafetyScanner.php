<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

/** Static checks over the add-on's PHP source (comments are ignored). */
final class ProSafetyScanner
{
    /** @var array<string,string> label => pattern */
    private const BANNED = [
        'eval'            => '/\beval\s*\(/i',
        'exec family'     => '/\b(?:shell_exec|exec|passthru|proc_open|popen|system|pcntl_exec)\s*\(/i',
        'create_function' => '/\bcreate_function\s*\(/i',
        'unserialize'     => '/\bunserialize\s*\(/i',
        'write users'     => '/\b(?:wp_insert_user|wp_create_user|wp_update_user|wp_delete_user)\s*\(/i',
        'manage plugins'  => '/\b(?:activate_plugin|activate_plugins|deactivate_plugins|delete_plugins|plugins_api)\b/i',
        'divi presets'    => '/(?:et_divi_builder_global_presets_d5|et_global_data|et_divi_global_variables|GlobalPreset::)/',
    ];

    /** @return list<string> labels of the banned constructs found */
    public static function violations( string $php ): array
    {
        $code  = self::withoutComments( $php );
        $found = [];
        foreach ( self::BANNED as $label => $pattern ) {
            if ( 1 === preg_match( $pattern, $code ) ) {
                $found[] = $label;
            }
        }

        return $found;
    }

    /** @return list<string> the host of every http(s) URL inside a PHP string literal */
    public static function hosts( string $php ): array
    {
        $hosts = [];
        foreach ( token_get_all( $php ) as $tok ) {
            if ( is_array( $tok ) && in_array( $tok[0], [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ], true ) ) {
                if ( preg_match_all( '#https?://([a-z0-9.-]+)#i', $tok[1], $m ) ) {
                    foreach ( $m[1] as $host ) {
                        $hosts[] = strtolower( $host );
                    }
                }
            }
        }

        return array_values( array_unique( $hosts ) );
    }

    public static function usesRemoteHttp( string $php ): bool
    {
        return 1 === preg_match( '/\b(?:wp_remote_(?:get|post|request|head)|wp_safe_remote_\w+|curl_init|curl_exec|fsockopen|stream_socket_client)\s*\(/i', self::withoutComments( $php ) );
    }

    private static function withoutComments( string $php ): string
    {
        $out = '';
        foreach ( token_get_all( $php ) as $tok ) {
            if ( is_array( $tok ) ) {
                if ( in_array( $tok[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
                    continue;
                }
                $out .= $tok[1];
            } else {
                $out .= $tok;
            }
        }

        return $out;
    }
}
