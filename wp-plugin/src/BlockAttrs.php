<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The block-comment scanner the serve-time resolvers share (KitResolver for style kits, LanguageResolver for design
 * languages): the pattern of an opening divi block comment with attributes, the bounded attribute decoder and the deep merge
 * of an adapter's patch. Pure and deterministic: no site access.
 */
final class BlockAttrs
{
    /**
     * An opening divi block comment with attributes: (prefix)(name)(json)(suffix). The JSON never spans a `-->` and
     * ends at the first `}` followed by the comment's end. Possessive throughout, so a block of any size is matched
     * without backtracking (a lazy tempered dot ran out of PCRE JIT stack on a 40 KB block).
     */
    public const BLOCK = '/(<!--\s+wp:(divi\/[a-z0-9-]+)\s+)(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\})(\s+\/?-->)/s';

    /** How deep attribute JSON is decoded (recipes are about 10 deep); deeper is treated as malformed. */
    public const DEPTH = 64;

    /** The decoded attribute object, or null when $json is not a JSON object within DEPTH. */
    public static function decode( string $json ): ?\stdClass
    {
        $attrs = json_decode( $json, false, self::DEPTH );

        return $attrs instanceof \stdClass ? $attrs : null;
    }

    /**
     * Deep-merge $patch into $node: the patch wins; an associative patch array merges into an existing object, anything
     * else (a list, a scalar, a new key) replaces or is appended. Existing keys keep their place.
     *
     * @param array<mixed> $patch
     */
    public static function merge( \stdClass $node, array $patch ): void
    {
        foreach ( $patch as $key => $value ) {
            $key = (string) $key;
            if ( is_array( $value ) && [] !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) && isset( $node->{$key} ) && $node->{$key} instanceof \stdClass ) {
                self::merge( $node->{$key}, $value );
            } else {
                $node->{$key} = $value;
            }
        }
    }
}
