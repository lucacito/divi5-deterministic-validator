<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;
use Divi5Validator\Violation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The plugin never saves custom code through a layout: no custom CSS fields, no Divi Code modules, no
 * script/style/iframe/object/embed markup, no `javascript:` URLs, no event-handler attributes, no PHP tags.
 * Pure and deterministic like the validator: it only reports violations, it never changes content.
 *
 * Code that is ALREADY in the stored content of the page being edited (for example custom CSS the owner added
 * in Divi's Visual Builder) is the baseline: it is preserved untouched and never counted. Only new or changed
 * code is refused.
 */
final class CodePolicy
{
    public const E_CUSTOM_CSS  = 'CUSTOM_CSS_NOT_ALLOWED';
    public const E_CODE_MODULE = 'CODE_MODULE_NOT_ALLOWED';
    public const E_SCRIPT      = 'SCRIPT_CONTENT_NOT_ALLOWED';

    private const CODE_MODULES   = [ 'divi/code', 'divi/fullwidth-code' ];
    private const CSS_FIELDS     = [ 'freeForm', 'mainElement' ];
    private const MARKUP         = [
        '/<\s*(?:script|style|iframe|object|embed)\b/i',
        '/<\?(?:php|=)/i',
        '/\bjavascript\s*:/i',
        '/<[a-z][^>]*\son[a-z]+\s*=/i',
    ];
    private const EVENT_ATTR_NAME = '/^on[a-z]+$/i';

    /**
     * @return list<Violation>
     */
    public static function violations( string $content, ?string $baseline = null ): array
    {
        $found = self::findings( $content );
        if ( [] === $found ) {
            return [];
        }

        $allowed = [];
        if ( null !== $baseline && '' !== $baseline ) {
            foreach ( self::findings( $baseline ) as $f ) {
                $allowed[ $f['fingerprint'] ] = ( $allowed[ $f['fingerprint'] ] ?? 0 ) + 1;
            }
        }

        $out = [];
        foreach ( $found as $f ) {
            if ( ( $allowed[ $f['fingerprint'] ] ?? 0 ) > 0 ) {
                --$allowed[ $f['fingerprint'] ];
                continue;
            }
            $out[] = new Violation( $f['code'], $f['message'], $f['path'] );
        }

        return $out;
    }

    /**
     * @return list<array{code:string, fingerprint:string, path:string, message:string}>
     */
    private static function findings( string $content ): array
    {
        $out    = [];
        $parsed = ( new BlockParser() )->parse( $content );
        $root   = $parsed->root();
        if ( null !== $root ) {
            $root->walk( static function ( Block $block, string $path ) use ( &$out ): void {
                if ( in_array( $block->name(), self::CODE_MODULES, true ) ) {
                    $out[] = self::finding(
                        self::E_CODE_MODULE,
                        $block->name() . '|' . md5( (string) json_encode( $block->attrs() ) ),
                        $path,
                        sprintf( 'The %s module (raw HTML or JavaScript) is not allowed: this plugin never saves custom code.', $block->name() )
                    );
                }
                self::scan( $block->attrs(), $path, false, $out );
            } );
        }

        // Text between the block comments (the comments carry the JSON attributes, scanned above).
        $outside = (string) preg_replace( '/<!--.*?-->/s', '', $content );
        foreach ( self::markupHits( $outside ) as $hit ) {
            $out[] = self::finding( self::E_SCRIPT, 'raw|' . md5( $hit ), 'post_content', self::scriptMessage( $hit ) );
        }

        return $out;
    }

    /**
     * @param list<array{code:string, fingerprint:string, path:string, message:string}> $out
     */
    private static function scan( mixed $value, string $path, bool $inCss, array &$out ): void
    {
        if ( is_string( $value ) ) {
            if ( $inCss ) {
                if ( '' !== trim( $value ) ) {
                    $out[] = self::finding(
                        self::E_CUSTOM_CSS,
                        'css|' . md5( $path . '|' . $value ),
                        $path,
                        'Custom CSS is not allowed: this plugin never saves custom CSS. Use the module\'s own style settings instead.'
                    );
                }

                return;
            }
            foreach ( self::markupHits( $value ) as $hit ) {
                $out[] = self::finding( self::E_SCRIPT, 'str|' . md5( $hit ), $path, self::scriptMessage( $hit ) );
            }

            return;
        }
        if ( ! is_array( $value ) ) {
            return;
        }

        // A custom HTML attribute entry such as {"name":"onclick","value":"..."}.
        if ( isset( $value['name'] ) && is_string( $value['name'] ) && array_key_exists( 'value', $value ) && 1 === preg_match( self::EVENT_ATTR_NAME, $value['name'] ) ) {
            $out[] = self::finding(
                self::E_SCRIPT,
                'attr|' . strtolower( $value['name'] ) . '|' . md5( (string) json_encode( $value['value'] ) ),
                $path,
                sprintf( 'The event-handler attribute "%s" is not allowed: this plugin never saves custom code.', substr( $value['name'], 0, 40 ) )
            );
        }

        foreach ( $value as $key => $child ) {
            $css = $inCss || 'css' === $key || ( is_string( $key ) && in_array( $key, self::CSS_FIELDS, true ) );
            self::scan( $child, $path . '.' . $key, $css, $out );
        }
    }

    /** @return list<string> */
    private static function markupHits( string $text ): array
    {
        $hits = [];
        foreach ( self::MARKUP as $pattern ) {
            if ( false !== preg_match_all( $pattern, $text, $m ) ) {
                foreach ( $m[0] as $hit ) {
                    $hits[] = substr( trim( $hit ), 0, 40 );
                }
            }
        }

        return $hits;
    }

    private static function scriptMessage( string $hit ): string
    {
        return sprintf( 'Script or embed content (%s) is not allowed: this plugin never saves custom code.', $hit );
    }

    /** @return array{code:string, fingerprint:string, path:string, message:string} */
    private static function finding( string $code, string $fingerprint, string $path, string $message ): array
    {
        return [ 'code' => $code, 'fingerprint' => $code . '|' . $fingerprint, 'path' => $path, 'message' => $message ];
    }
}
