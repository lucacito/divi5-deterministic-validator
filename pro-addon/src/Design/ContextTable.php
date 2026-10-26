<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Design;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;

/**
 * Which colour or font ROLE the installed recipe library uses in which block context (spec
 * 2026-10-25-pro-design-switch-design.md section 5.3; plan Ruling 3). The role reverse (`RoleReverse`) reads it to decide a
 * value that several roles of the source palette share.
 *
 * Derived at run time, never stored: every raw recipe (`SectionRecipes::rawMarkup`) is resolved by the free pipeline
 * (`SectionRecipes::resolveMarkup`) under a SYNTHETIC language, in which every colour role has its own colour and every
 * font role its own family (type, section space and everything else are the editorial language's), and the kit
 * `quiet-solid`. Raw and resolved markup are scanned in parallel (`PageScan`, same block order): each whole-value colour
 * token of a raw block is recorded at (block name, JSON path, the band the block sits on in the RESOLVED page, which the
 * synthetic palette reads exactly), each font token at (block name, JSON path, the raw block's own type role or `-`).
 * Paths are `JsonLeaves` paths (`.a.b[].c`), the same the switch reads from a saved page.
 *
 * A recipe the pipeline does not resolve, or whose resolved block list differs from the raw one, adds nothing and is
 * listed by `skipped()` (the tests assert the list is empty for the shipped library). Pure and deterministic: the counts
 * depend only on the recipe texts, and every map is sorted by key.
 */
final class ContextTable
{
    /** The kit the table is built with (the editorial language's own). */
    public const KIT = 'quiet-solid';

    /** The language the synthetic one starts from. */
    public const BASE_LANGUAGE = 'editorial';

    /** A whole-value colour or font token without an alpha part. */
    private const TOKEN = '/^\{\{aied:(color|font):([a-z-]+)\}\}$/';

    /** One memoised table per request, keyed by the recipes' hash. */
    private static ?self $memo = null;

    /**
     * @param array<string,array<string,int>> $colour context key => role => count
     * @param array<string,array<string,int>> $font   context key => role => count
     * @param list<string>                    $skipped
     */
    private function __construct(
        private readonly array $colour,
        private readonly array $font,
        private readonly string $hash,
        private readonly array $skipped,
        private readonly int $colourTotal,
        private readonly int $fontTotal
    ) {
    }

    /** The table of the recipes the installed free plugin ships, memoised per request (rebuilt when they change). */
    public static function current(): self
    {
        $recipes = self::installedRecipes();
        $hash    = self::hashOf( $recipes );
        if ( null === self::$memo || self::$memo->hash !== $hash ) {
            self::$memo = self::build( $recipes );
        }

        return self::$memo;
    }

    /**
     * A table built from the given raw recipes (name => markup), in the given order.
     *
     * @param array<string,string> $recipes
     *
     * @throws \LogicException when the base language or the kit cannot be read (the free plugin is broken)
     */
    public static function build( array $recipes ): self
    {
        $language = self::syntheticLanguage();
        $kit      = StyleKits::get( self::KIT );
        if ( null === $kit ) {
            throw new \LogicException( 'The style kit ' . self::KIT . ' cannot be read.' );
        }
        $colour      = [];
        $font        = [];
        $skipped     = [];
        $colourTotal = 0;
        $fontTotal   = 0;
        foreach ( $recipes as $name => $raw ) {
            $resolved = SectionRecipes::resolveMarkup( $raw, $language, $kit );
            if ( null === $resolved ) {
                $skipped[] = (string) $name;
                continue;
            }
            $rawBlocks = PageScan::scan( $raw, $language );
            $resBlocks = PageScan::scan( $resolved, $language );
            if ( array_column( $rawBlocks, 'name' ) !== array_column( $resBlocks, 'name' ) ) {
                $skipped[] = (string) $name;
                continue;
            }
            foreach ( $rawBlocks as $i => $block ) {
                if ( null === $block['json'] ) {
                    continue;
                }
                $type = null;
                foreach ( JsonLeaves::strings( $block['json'] ) as $leaf ) {
                    if ( '.aied.type' === $leaf['path'] ) {
                        $type = $leaf['value'];
                    }
                }
                foreach ( JsonLeaves::strings( $block['json'] ) as $leaf ) {
                    if ( 1 !== preg_match( self::TOKEN, $leaf['value'], $m ) ) {
                        continue;
                    }
                    if ( 'color' === $m[1] ) {
                        $key = self::key( $block['name'], $leaf['path'], $resBlocks[ $i ]['band'] );
                        $colour[ $key ][ $m[2] ] = ( $colour[ $key ][ $m[2] ] ?? 0 ) + 1;
                        ++$colourTotal;
                    } else {
                        $key = self::key( $block['name'], $leaf['path'], $type ?? '-' );
                        $font[ $key ][ $m[2] ] = ( $font[ $key ][ $m[2] ] ?? 0 ) + 1;
                        ++$fontTotal;
                    }
                }
            }
        }
        foreach ( [ &$colour, &$font ] as &$map ) {
            ksort( $map, SORT_STRING );
            foreach ( $map as &$roles ) {
                ksort( $roles, SORT_STRING );
            }
            unset( $roles );
        }
        unset( $map );

        return new self( $colour, $font, self::hashOf( $recipes ), $skipped, $colourTotal, $fontTotal );
    }

    /**
     * The synthetic language: the editorial language with every colour role (surface-glass included) given its own
     * colour and every font role its own family. The colours are generated here (never a literal); the overlay keeps
     * the base language's own function and alpha with new channels, so it stays a valid overlay.
     *
     * @return array<string,mixed>
     *
     * @throws \LogicException when the base language cannot be read
     */
    public static function syntheticLanguage(): array
    {
        $language = DesignLanguages::get( self::BASE_LANGUAGE );
        if ( null === $language ) {
            throw new \LogicException( 'The design language ' . self::BASE_LANGUAGE . ' cannot be read.' );
        }
        $i = 0;
        foreach ( RoleReverse::COLOR_ROLES as $key ) {
            [ $r, $g, $b ] = [ 0x17 + 0x15 * $i, 0x9b - 0x0d * $i, 0x2f + 0x13 * $i ];
            if ( 'overlay' === $key ) {
                $language['colors'][ $key ] = (string) preg_replace( '/\d+\s*,\s*\d+\s*,\s*\d+/', sprintf( '%d,%d,%d', $r, $g, $b ), (string) $language['colors'][ $key ], 1 );
            } else {
                $language['colors'][ $key ] = sprintf( '#%02x%02x%02x', $r, $g, $b );
            }
            ++$i;
        }
        foreach ( RoleReverse::FONT_ROLES as $role => $key ) {
            $language['fonts'][ $key ] = 'Aied Context ' . ucfirst( $role );
        }

        return $language;
    }

    /**
     * Role => how often the library uses it for a colour token at this block, path and band (sorted by role).
     *
     * @return array<string,int>
     */
    public function colour( string $block, string $path, string $band ): array
    {
        return $this->colour[ self::key( $block, $path, $band ) ] ?? [];
    }

    /**
     * Role => how often the library uses it for a font token at this block, path and type role (`-` for none).
     *
     * @return array<string,int>
     */
    public function font( string $block, string $path, string $typeRole ): array
    {
        return $this->font[ self::key( $block, $path, $typeRole ) ] ?? [];
    }

    /** The sha256 of the recipes the table was built from (see `hashOf`). */
    public function hash(): string
    {
        return $this->hash;
    }

    /**
     * How many colour and font token occurrences the table records.
     *
     * @return array{colour:int, font:int}
     */
    public function totals(): array
    {
        return [ 'colour' => $this->colourTotal, 'font' => $this->fontTotal ];
    }

    /** @return list<string> recipes that added nothing (unresolved, or a block list that changed) */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * The installed library as name => raw markup, in `SectionRecipes::names()` order.
     *
     * @return array<string,string>
     */
    public static function installedRecipes(): array
    {
        $out = [];
        foreach ( SectionRecipes::names() as $name ) {
            $out[ $name ] = (string) SectionRecipes::rawMarkup( $name );
        }

        return $out;
    }

    /**
     * The sha256 of the recipes: each name and markup, in order, separated by NUL bytes (the free plugin's data path is
     * private, so the texts themselves are hashed).
     *
     * @param array<string,string> $recipes
     */
    public static function hashOf( array $recipes ): string
    {
        $ctx = hash_init( 'sha256' );
        foreach ( $recipes as $name => $markup ) {
            hash_update( $ctx, $name . "\0" . $markup . "\0" );
        }

        return hash_final( $ctx );
    }

    private static function key( string $block, string $path, string $context ): string
    {
        return $block . "\0" . $path . "\0" . $context;
    }
}
