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

    private const CODE_MODULES    = [ 'divi/code', 'divi/fullwidth-code' ];
    private const CSS_FIELDS      = [ 'freeForm', 'mainElement' ];
    private const EVENT_ATTR_NAME = '/^on[a-z]+$/i';
    private const URL_KEY         = '/url|link|href|src|action|poster/i';
    /** Only Divi / WordPress block delimiters are removed from the raw text; their JSON is scanned through the parsed block. */
    private const BLOCK_DELIMITER = '/<!--\s*\/?wp:[a-z0-9\/_-]+\b.*?-->/s';
    private const UNSCANNABLE     = 'unscannable';

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
                self::scan( $block->attrs(), $path, '', false, $out );
            } );
        }

        // Text between the block delimiters. Every other comment stays in the scanned text: HTML5 treats
        // `<!-->`, `<!--->` and `--!>` as comment ends, so a comment can never be trusted to hide its content.
        $outside = preg_replace( self::BLOCK_DELIMITER, '', $content );
        $units   = null === $outside ? [ [ self::UNSCANNABLE, $content ] ] : self::textUnits( $outside );
        foreach ( $units as [ $kind, $unit ] ) {
            $out[] = self::unitFinding( $kind, $unit, 'post_content' );
        }

        return $out;
    }

    /**
     * @param list<array{code:string, fingerprint:string, path:string, message:string}> $out
     */
    private static function scan( mixed $value, string $path, string $key, bool $inCss, array &$out ): void
    {
        if ( is_string( $value ) ) {
            if ( $inCss ) {
                if ( '' !== trim( $value ) ) {
                    $out[] = self::finding(
                        self::E_CUSTOM_CSS,
                        'css|' . $key . '|' . md5( $value ),
                        $path,
                        'Custom CSS is not allowed: this plugin never saves custom CSS. Use the module\'s own style settings instead.'
                    );
                }

                return;
            }
            foreach ( self::stringUnits( $value, $key ) as [ $kind, $unit ] ) {
                $out[] = self::unitFinding( $kind, $unit, $path );
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

        foreach ( $value as $k => $child ) {
            $css = $inCss || 'css' === $k || ( is_string( $k ) && in_array( $k, self::CSS_FIELDS, true ) );
            self::scan( $child, $path . '.' . $k, is_string( $k ) ? $k : $key, $css, $out );
        }
    }

    /**
     * Every unit of code in one attribute string: the markup units of the text, plus the value itself when it
     * is a `javascript:` URL.
     *
     * @return list<array{0:string, 1:string}> [ kind, unit ]
     */
    private static function stringUnits( string $value, string $key ): array
    {
        $text = self::normalise( $value );
        if ( null === $text ) {
            return [ [ self::UNSCANNABLE, $value ] ];
        }
        $units = self::textUnits( $value );
        if ( [ self::UNSCANNABLE ] === array_map( static fn ( array $u ): string => $u[0], $units ) ) {
            return $units;
        }

        // (a) The whole value is a script URL. Tabs, newlines and other control characters inside the scheme are
        // ignored by browsers; plain spaces are only tolerated in a URL-like field, so prose such as
        // "JavaScript: the good parts" is not mistaken for code.
        $lead = ltrim( $text, "\x00..\x20" );
        if ( 1 === preg_match( self::URL_KEY, $key ) ) {
            $compact = self::compact( $lead );
            if ( null === $compact ) {
                return [ [ self::UNSCANNABLE, $value ] ];
            }
            $isUrl = 1 === preg_match( '/^javascript:/i', $compact );
        } else {
            $strict = preg_replace( '/[\x00-\x1f]+/', '', $lead );
            if ( null === $strict ) {
                return [ [ self::UNSCANNABLE, $value ] ];
            }
            $isUrl = 1 === preg_match( '/^javascript\s*:(?!\s)/i', $strict );
        }
        if ( $isUrl ) {
            $units[] = [ 'jsurl', trim( $text ) ];
        }

        return $units;
    }

    /**
     * The units of code in a piece of text (an attribute string or the raw text between blocks): whole script,
     * style, iframe, object and embed elements, PHP tags, script URLs after a URL attribute, and whole opening
     * tags that carry an event handler. A unit is the entire offending text, so changing any part of it is a new
     * finding. Returns a single "unscannable" unit when a regex fails (fail closed).
     *
     * @return list<array{0:string, 1:string}> [ kind, unit ]
     */
    private static function textUnits( string $raw ): array
    {
        $text = self::normalise( $raw );
        if ( null === $text ) {
            return [ [ self::UNSCANNABLE, $raw ] ];
        }

        $fail  = [ [ self::UNSCANNABLE, $raw ] ];
        $units = [];

        foreach ( [
            'element' => '/<(script|style|iframe|object|embed)\b.*?(?:<\/\1\s*>|\z)/is',
            'php'     => '/<\?(?:php|=).*?(?:\?>|\z)/is',
        ] as $kind => $pattern ) {
            if ( false === preg_match_all( $pattern, $text, $m ) ) {
                return $fail;
            }
            foreach ( $m[0] as $unit ) {
                $units[] = [ $kind, $unit ];
            }
        }

        // A script URL right after a URL-like attribute (the value may hide the scheme with tabs or newlines).
        if ( false === preg_match_all( '/\b(?:href|src|action|formaction|data|poster|xlink:href)\s*=\s*["\']?([^"\'<>]*)/i', $text, $m ) ) {
            return $fail;
        }
        foreach ( $m[1] as $token ) {
            $compact = self::compact( $token );
            if ( null === $compact ) {
                return $fail;
            }
            if ( 1 === preg_match( '/^javascript:/i', $compact ) ) {
                $units[] = [ 'jsurl', $token ];
            }
        }

        // An event-handler attribute in an opening tag. Quoted values are opaque, so "online = easy" in a title is
        // not an attribute; `/`, quotes and whitespace all separate attributes.
        if ( false === preg_match_all( '/<[a-z][a-z0-9:-]*(?:"[^"]*+"|\'[^\']*+\'|[^>"\'])*+>?/i', $text, $m ) ) {
            return $fail;
        }
        foreach ( $m[0] as $tag ) {
            $blank = preg_replace( '/"[^"]*"|\'[^\']*\'/', '""', $tag );
            if ( null === $blank ) {
                return $fail;
            }
            $has = preg_match( '/[\s"\'\/]on[a-z]+\s*=/i', $blank );
            if ( false === $has ) {
                return $fail;
            }
            if ( 1 === $has ) {
                $units[] = [ 'handler', $tag ];
            }
        }

        return $units;
    }

    /**
     * Decodes HTML entities (up to three rounds, for double encoding) so `&#106;avascript:` and `jav&#x61;script:`
     * are seen as what a browser sees. The structural characters < > " ' stay encoded: an entity can never open a
     * tag or close an attribute, so "&lt;script&gt;" in prose is text, not code. Null if a regex failed.
     */
    private static function normalise( string $text ): ?string
    {
        for ( $i = 0; $i < 3; $i++ ) {
            $next = preg_replace_callback(
                '/&(?:#[0-9]{1,8}|#[xX][0-9a-fA-F]{1,6}|[a-zA-Z][a-zA-Z0-9]{1,31});/',
                static function ( array $m ): string {
                    $decoded = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

                    return in_array( $decoded, [ '<', '>', '"', "'" ], true ) ? $m[0] : $decoded;
                },
                $text
            );
            if ( null === $next ) {
                return null;
            }
            if ( $next === $text ) {
                break;
            }
            $text = $next;
        }

        return $text;
    }

    /** The text without any ASCII control character or space; null if the regex failed. */
    private static function compact( string $text ): ?string
    {
        return preg_replace( '/[\x00-\x20]+/', '', $text );
    }

    /** @return array{code:string, fingerprint:string, path:string, message:string} */
    private static function unitFinding( string $kind, string $unit, string $path ): array
    {
        $message = self::UNSCANNABLE === $kind
            ? 'Script content (content could not be scanned) is not allowed: this plugin refuses what it cannot check.'
            : sprintf( 'Script or embed content (%s) is not allowed: this plugin never saves custom code.', substr( trim( $unit ), 0, 40 ) );

        return self::finding( self::E_SCRIPT, $kind . '|' . md5( $unit ), $path, $message );
    }

    /** @return array{code:string, fingerprint:string, path:string, message:string} */
    private static function finding( string $code, string $fingerprint, string $path, string $message ): array
    {
        return [ 'code' => $code, 'fingerprint' => $code . '|' . $fingerprint, 'path' => $path, 'message' => $message ];
    }
}
