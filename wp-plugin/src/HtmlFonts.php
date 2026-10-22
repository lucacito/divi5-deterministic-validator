<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Which font family html_to_divi may carry. Divi can load its own Google font list (bundled copy of the names, one per line) and,
 * when the vocabulary proves it (DiviMarkup::STYLE_PROVEN['websafe-family']), the web-safe families. A family Divi cannot load is
 * never written: it is listed in the report and the theme font stays. Pure and deterministic; no network, no AI.
 */
final class HtmlFonts
{
    public const DATA = __DIR__ . '/../data/google-fonts-names.txt';

    /** Web-safe families, canonical case. Used only when DiviMarkup::STYLE_PROVEN['websafe-family'] is true. */
    public const WEB_SAFE = [ 'Arial', 'Helvetica', 'Georgia', 'Times New Roman', 'Verdana', 'Tahoma', 'Trebuchet MS', 'Courier New' ];

    /** Generic keywords (lowercase): never carried and never listed. */
    public const GENERIC = [
        'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif',
        'ui-monospace', 'ui-rounded', 'emoji', 'math', 'fangsong', '-apple-system', 'blinkmacsystemfont', 'inherit', 'initial', 'unset', 'revert',
    ];

    /** @var array<string,string>|null */
    private static ?array $google = null;

    /**
     * Lowercase name => canonical name of every Divi Google font (memoised). Empty when the bundled file cannot be read.
     *
     * @return array<string,string>
     */
    public static function google(): array
    {
        return self::$google ??= self::parse( @file_get_contents( self::DATA ) );
    }

    /** Whether $canonical is, spelled exactly like this (case included), one of Divi's Google font names. */
    public static function isKnown( string $canonical ): bool
    {
        return ( self::google()[ self::fold( $canonical ) ] ?? null ) === $canonical;
    }

    /**
     * Parses the text of a font list (one family per line). Never throws: anything that is not text is an empty list.
     *
     * @return array<string,string>
     */
    public static function parse( string|false $text ): array
    {
        if ( ! is_string( $text ) ) {
            return [];
        }
        $out = [];
        foreach ( explode( "\n", str_replace( "\r", '', $text ) ) as $line ) {
            $line = trim( $line );
            if ( $line !== '' ) {
                $out[ self::fold( $line ) ] = $line;
            }
        }

        return $out;
    }

    /**
     * The first family of $families (left to right) that Divi can load: a Google font or (when proven) a web-safe one, matched
     * case-insensitively, returned in canonical case. Generic keywords are skipped; every other family before the chosen one is listed.
     *
     * @param list<string> $families
     * @param ?bool        $webSafe  whether web-safe families may be carried (null = the proven decision in DiviMarkup::STYLE_PROVEN, false while it does not exist)
     * @param ?array<string,string> $googleList a different Google list, as parse() returns it (tests only)
     * @return array{family:?string, fonts:list<array{source:string, action:string}>}
     */
    public static function choose( array $families, ?bool $webSafe = null, ?array $googleList = null ): array
    {
        $google = $googleList ?? self::google();
        $web    = [];
        if ( $webSafe ?? self::webSafeProven() ) {
            foreach ( self::WEB_SAFE as $name ) {
                $web[ self::fold( $name ) ] = $name;
            }
        }

        $skipped = [];
        foreach ( $families as $family ) {
            $family = trim( (string) $family );
            $key    = self::fold( $family );
            if ( $family === '' || in_array( $key, self::GENERIC, true ) ) {
                continue;
            }
            $canonical = $google[ $key ] ?? $web[ $key ] ?? null;
            if ( $canonical === null ) {
                $skipped[] = $family;
                continue;
            }
            $fonts = [];
            foreach ( $skipped as $source ) {
                $fonts[] = [ 'source' => $source, 'action' => 'not available in Divi: used ' . $canonical . ', the next family in the list' ];
            }
            $fonts[] = [ 'source' => $canonical, 'action' => 'carried' ];

            return [ 'family' => $canonical, 'fonts' => $fonts ];
        }

        $fonts = [];
        foreach ( $skipped as $source ) {
            $fonts[] = [ 'source' => $source, 'action' => 'not available in Divi: kept the theme font' ];
        }

        return [ 'family' => null, 'fonts' => $fonts ];
    }

    private static function webSafeProven(): bool
    {
        $const = __NAMESPACE__ . '\DiviMarkup::STYLE_PROVEN';
        if ( ! defined( $const ) ) {
            return false;
        }
        $proven = constant( $const );

        return is_array( $proven ) && ( $proven['websafe-family'] ?? false ) === true;
    }

    private static function fold( string $name ): string
    {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
    }
}
