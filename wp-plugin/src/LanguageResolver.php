<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/BlockAttrs.php';
require_once __DIR__ . '/RecipeTokens.php';
require_once __DIR__ . '/LanguageAdapters.php';

/**
 * Applies a design language to recipe markup when the recipe is SERVED (the language step, run BEFORE the kit step), only
 * inside the attribute JSON of a `<!-- wp:divi/<name> {json} -->` block comment (text and HTML are never parsed):
 *
 * A marker is a TOP-LEVEL attribute `"aied":{"<kind>":"<role>"}` with exactly one key, a kind in LanguageAdapters::KINDS
 * and a string role LanguageAdapters::APPLIED lists for the block (today `"aied":{"type":"display|title|eyebrow"}` on a
 * `divi/heading`, `"aied":{"type":"eyebrow"}` on a `divi/text`). The adapter's patch is deep-merged into the attributes
 * (BlockAttrs::merge: the language wins over a recipe literal at the same key; untouched keys keep their place), the marker
 * is removed and the block is re-encoded with RecipeTokens::encodeJson. Anything else (a style kit marker, an extra key,
 * an unknown kind, role or block, a marker below the top level, malformed JSON, a language the adapter refuses) leaves
 * the block byte-identical; every untouched block stays byte-identical. leftovers() reports the type markers left; the kit
 * step's KitResolver::leftovers() stays the catch-all for everything else. Pure and deterministic: no site access.
 */
final class LanguageResolver
{
    /** Kind => the fixed leftover description (never the content's own text). */
    public const LEFT = [ 'type' => '{"aied":{"type":...}}' ];

    /**
     * $markup with every language marker it can resolve resolved. $adapter has the signature of LanguageAdapters::patch()
     * (block, kind, role, language) and returns the attribute subtree to merge or null; LanguageAdapters::patch when null.
     * An `aied` key in a patch is dropped (a patch can never add a marker).
     *
     * @param array<string,mixed> $language
     */
    public static function resolve( string $markup, array $language, ?callable $adapter = null ): string
    {
        if ( ! str_contains( $markup, 'aied' ) && ! str_contains( $markup, '\\u' ) ) {
            return $markup;
        }
        $adapter ??= [ LanguageAdapters::class, 'patch' ];

        $out = preg_replace_callback(
            BlockAttrs::BLOCK,
            static function ( array $m ) use ( $language, $adapter ): string {
                if ( ! str_contains( $m[3], 'aied' ) && ! str_contains( $m[3], '\\u' ) ) {
                    return $m[0];
                }
                $attrs = BlockAttrs::decode( $m[3] );
                if ( null === $attrs || ! property_exists( $attrs, 'aied' ) ) {
                    return $m[0];
                }
                $marker = self::marker( $attrs->aied, $m[2] );
                if ( null === $marker ) {
                    return $m[0];
                }
                $patch = $adapter( $m[2], $marker[0], $marker[1], $language );
                if ( ! is_array( $patch ) ) {
                    return $m[0];
                }
                BlockAttrs::merge( $attrs, $patch );
                unset( $attrs->aied );
                $json = RecipeTokens::encodeJson( $attrs );

                return '' === $json ? $m[0] : $m[1] . $json . $m[4];
            },
            $markup
        );

        return is_string( $out ) ? $out : $markup;
    }

    /**
     * LEFT[kind] once for every top-level `aied` object that has a key in LanguageAdapters::KINDS (whatever else it has),
     * in order of first appearance; [] when there is none.
     *
     * @return list<string>
     */
    public static function leftovers( string $markup ): array
    {
        if ( ! str_contains( $markup, 'aied' ) && ! str_contains( $markup, '\\u' ) ) {
            return [];
        }
        $all = [];
        if ( false === preg_match_all( BlockAttrs::BLOCK, $markup, $all, PREG_SET_ORDER ) ) {
            return []; // KitResolver::leftovers() reports a scanner failure (LEFT_UNSCANNED).
        }
        $found = [];
        foreach ( $all as $m ) {
            if ( ! str_contains( $m[3], 'aied' ) && ! str_contains( $m[3], '\\u' ) ) {
                continue;
            }
            $attrs = BlockAttrs::decode( $m[3] );
            if ( null === $attrs || ! property_exists( $attrs, 'aied' ) || ! $attrs->aied instanceof \stdClass ) {
                continue;
            }
            foreach ( LanguageAdapters::KINDS as $kind ) {
                if ( property_exists( $attrs->aied, $kind ) ) {
                    $found[ self::LEFT[ $kind ] ] = true;
                }
            }
        }

        return array_keys( $found );
    }

    /**
     * [kind, role] for a well-formed language marker on $block, else null.
     *
     * @param mixed $marker
     * @return ?array{0:string,1:string}
     */
    private static function marker( $marker, string $block ): ?array
    {
        if ( ! $marker instanceof \stdClass ) {
            return null;
        }
        $vars = get_object_vars( $marker );
        if ( 1 !== count( $vars ) ) {
            return null;
        }
        $kind = (string) array_key_first( $vars );
        $role = $vars[ $kind ];
        if ( ! in_array( $kind, LanguageAdapters::KINDS, true ) || ! is_string( $role ) || ! in_array( $role, LanguageAdapters::APPLIED[ $kind ][ $block ] ?? [], true ) ) {
            return null;
        }

        return [ $kind, $role ];
    }
}
