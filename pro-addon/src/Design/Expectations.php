<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Design;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AiEditorDivi5\WP\BlockAttrs;
use AiEditorDivi5\WP\SectionRecipes;

/**
 * What the free pipeline writes for ONE marker on ONE block under one design (spec 2026-10-25-pro-design-switch-design.md
 * section 5.2; controller Ruling 2: the engine never builds a patch itself).
 *
 * The expectation of a marker is the attribute object of `SectionRecipes::resolveMarkup()` applied to a one-block,
 * self-closing markup that carries only that marker, exactly as the dev scorer's `tests/Support/Dogfood/Expected.php`
 * builds its expectations (there through `KitResolver` + `RecipeTokens`; here through the whole serve-time pipeline, so a
 * type or rhythm marker resolves too). Null when the pipeline refuses the marker (leftovers), when a token survives, or
 * when the result is not that one block with an attribute object. Memoised per instance; pure and deterministic.
 */
final class Expectations
{
    /** @var array<string,array{0:string,1:\stdClass}|null> marker key => [the resolved JSON text, its decoded object] */
    private array $memo = [];

    public function __construct( public readonly Design $design )
    {
    }

    /**
     * The resolved attributes of `<!-- wp:$block {"aied":$marker} /-->` under this design, or null. The object is SHARED
     * by every caller (memoised): read it, never change it (decode `json()` for a copy to change).
     *
     * @param array<string,string> $marker a kit marker (`kit`, `on`, optional `ground`) or a language marker (`type` / `rhythm`)
     */
    public function attrs( string $block, array $marker ): ?\stdClass
    {
        return $this->entry( $block, $marker )[1] ?? null;
    }

    /**
     * The resolved attribute JSON text of the marker under this design, byte for byte as the pipeline wrote it, or null.
     *
     * @param array<string,string> $marker
     */
    public function json( string $block, array $marker ): ?string
    {
        return $this->entry( $block, $marker )[0] ?? null;
    }

    /**
     * @param array<string,string> $marker
     *
     * @return array{0:string,1:\stdClass}|null
     */
    private function entry( string $block, array $marker ): ?array
    {
        $key = $block . ' ' . (string) json_encode( $marker, JSON_UNESCAPED_SLASHES );
        if ( ! array_key_exists( $key, $this->memo ) ) {
            $this->memo[ $key ] = $this->resolve( $block, $marker );
        }

        return $this->memo[ $key ];
    }

    /**
     * @param array<string,string> $marker
     *
     * @return array{0:string,1:\stdClass}|null
     */
    private function resolve( string $block, array $marker ): ?array
    {
        $prefix   = '<!-- wp:' . $block . ' ';
        $suffix   = ' /-->';
        $resolved = SectionRecipes::resolveMarkup( $prefix . json_encode( [ 'aied' => $marker ], JSON_UNESCAPED_SLASHES ) . $suffix, $this->design->language, $this->design->kit );
        if ( null === $resolved || str_contains( $resolved, '{{aied:' ) ) {
            return null;
        }
        if ( strlen( $resolved ) <= strlen( $prefix . $suffix ) || ! str_starts_with( $resolved, $prefix ) || ! str_ends_with( $resolved, $suffix ) ) {
            return null;
        }
        $json  = substr( $resolved, strlen( $prefix ), - strlen( $suffix ) );
        $attrs = BlockAttrs::decode( $json );
        if ( null === $attrs || property_exists( $attrs, 'aied' ) ) {
            return null;
        }

        return [ $json, $attrs ];
    }
}
