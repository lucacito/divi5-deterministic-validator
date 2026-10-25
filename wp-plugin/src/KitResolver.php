<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/BlockAttrs.php';
require_once __DIR__ . '/RecipeTokens.php';
require_once __DIR__ . '/StyleKits.php';

/**
 * Applies a style kit to recipe markup when the recipe is SERVED. Two mechanisms, both only inside the attribute JSON of
 * a `<!-- wp:divi/<name> {json} -->` block comment (text and HTML are never parsed, so text that merely CONTAINS the
 * spelling is never rewritten):
 *
 * - A marker: a TOP-LEVEL attribute `"aied":{"kit":"<role>"}`, `"aied":{"kit":"<role>","on":"<surface>"}` or, for a card
 *   role, `"aied":{"kit":"<role>","on":"<surface>","ground":"<ground>"}` (only those keys, in any order). Roles and where
 *   they may sit: `control` and `control-secondary` on `divi/button`, on `light`, `accent`, `dark` or `panel` (a button
 *   inside an inverted panel); `card` and `panel` on `divi/group` or `divi/column`, on `light`, `surface`, `accent` or
 *   `dark`; `item` (a boxed item) on `divi/accordion` or `divi/toggle`, on `light`, `surface`, `accent` or `dark`, and
 *   `item-rule` (a rule row) on the same blocks, on `light` or `surface` only, and `form` (a whole contact form: its fields,
 *   submit button and captcha) on `divi/contact-form`, on `light`, `surface`, `accent` or `dark` (`surface` is a card, item
 *   and form band only, `panel` a button and rule surface only), and `rule` / `rule-strong` (a thin line: the line of a
 *   `divi/divider` or the top edge of a `divi/group` or `divi/column`) on `light`, `surface`, `accent`, `dark` or `panel`, and
 *   `chip` (a tag on a `divi/group`, an icon chip on a `divi/icon` or on a `divi/blurb`'s icon) on `light`, `surface`, `accent`
 *   or `dark`. The carriers: `card` and `panel` also on a `divi/testimonial`, `rule` and `rule-strong` also on a
 *   `divi/number-counter` (its top edge), and `rule-accent` (the accent mark) on a `divi/divider`, on `light` or `surface` only.
 *   `on` defaults to `light`. `ground` (`inverted`
 *   or `accent`, a string) is accepted only for the card roles, only on a `divi/group` or `divi/column` (never a testimonial)
 *   and only on a `light` or `surface` band. The adapter's
 *   patch for (block, role, on, kit, ground) is deep-merged into the
 *   attributes (the patch wins over a recipe literal at the same key; lists are replaced, not appended; untouched keys
 *   keep their place) and the marker is removed. A role on a block it does not belong to, or a surface the role does not
 *   accept, is never guessed: the marker is left and reported.
 * - A whole-value token: an attribute value that is exactly `{{aied:kit:<name>}}` (a name StyleKits::valueToken()
 *   answers) becomes that object.
 *
 * Only a block that carries a marker or token it can resolve is re-encoded (with RecipeTokens::encodeJson, so no
 * value can break the comment); every other block comment stays byte-identical. Anything this code does not know
 * (unknown role, surface or block, a marker that is not top level or has extra keys, malformed JSON, a partial or
 * unknown token, an invalid kit) is left untouched and reported by leftovers(), never guessed, never a warning.
 * A top-level `"aied"` of ANY kind is reported as LEFT_MARKER, so a design-language marker the language step
 * (LanguageResolver, which runs first) left is still reported here. The block scanner, the decoder and the merge are
 * BlockAttrs', shared with LanguageResolver.
 * Pure and deterministic: no site access.
 */
final class KitResolver
{
    /** Marker role => the blocks it may sit on (KitAdapters::APPLIED, by role). */
    private const ROLE_BLOCKS = [
        'control'           => [ 'divi/button' ],
        'control-secondary' => [ 'divi/button' ],
        'card'              => [ 'divi/group', 'divi/column', 'divi/testimonial' ],
        'panel'             => [ 'divi/group', 'divi/column', 'divi/testimonial' ],
        'rule'              => [ 'divi/group', 'divi/column', 'divi/divider', 'divi/number-counter' ],
        'rule-strong'       => [ 'divi/group', 'divi/column', 'divi/divider', 'divi/number-counter' ],
        'chip'              => [ 'divi/group', 'divi/icon', 'divi/blurb' ],
        'item'              => [ 'divi/accordion', 'divi/toggle' ],
        'item-rule'         => [ 'divi/accordion', 'divi/toggle' ],
        'form'              => [ 'divi/contact-form' ],
        'rule-accent'       => [ 'divi/divider' ],
    ];

    /**
     * Marker role => the surfaces (bands) it may name; `light` when it names none. `surface` is for the card and item roles
     * only, `panel` for the button and rule roles only; a rule row (`item-rule`) sits on a light or surface band only; a form
     * (`form`) takes the four bands a card does; a rule (`rule`, `rule-strong`) takes five (the four and `panel`) and a chip
     * the four; the accent mark (`rule-accent`) a light or surface band only.
     */
    private const ROLE_SURFACES = [
        'control'           => [ 'light', 'accent', 'dark', 'panel' ],
        'control-secondary' => [ 'light', 'accent', 'dark', 'panel' ],
        'card'              => [ 'light', 'surface', 'accent', 'dark' ],
        'panel'             => [ 'light', 'surface', 'accent', 'dark' ],
        'item'              => [ 'light', 'surface', 'accent', 'dark' ],
        'item-rule'         => [ 'light', 'surface' ],
        'form'              => [ 'light', 'surface', 'accent', 'dark' ],
        'rule'              => [ 'light', 'surface', 'accent', 'dark', 'panel' ],
        'rule-strong'       => [ 'light', 'surface', 'accent', 'dark', 'panel' ],
        'chip'              => [ 'light', 'surface', 'accent', 'dark' ],
        'rule-accent'       => [ 'light', 'surface' ],
    ];

    /** Marker role => the grounds it may name, and the surfaces a ground may sit on. Only the card roles take a ground. */
    private const ROLE_GROUNDS   = [
        'card'  => [ 'inverted', 'accent' ],
        'panel' => [ 'inverted', 'accent' ],
    ];
    private const GROUND_SURFACES = [ 'light', 'surface' ];

    /** The blocks a ground may sit on (KitAdapters::GROUND_BLOCKS, pinned equal by a test): a testimonial takes no ground. */
    private const GROUND_BLOCKS = [ 'divi/group', 'divi/column' ];

    /** An opening divi block comment with attributes: (prefix)(name)(json)(suffix) (BlockAttrs::BLOCK). */
    private const BLOCK = BlockAttrs::BLOCK;

    /** A whole-value kit token. */
    private const WHOLE_TOKEN = '/^\{\{aied:kit:([a-z0-9-]+)\}\}\z/';

    /** How deep attribute JSON is decoded; deeper is treated as malformed (BlockAttrs::DEPTH). */
    private const DEPTH = BlockAttrs::DEPTH;

    /** Fixed leftover descriptions (never the content's own text). */
    public const LEFT_MARKER    = '{"aied":{"kit":...}}';
    public const LEFT_NESTED    = '"aied" below the top level of block attributes';
    public const LEFT_MALFORMED = '"aied" in block attributes that are not valid JSON';
    public const LEFT_TOKEN     = '{{aied:kit:...}}';
    public const LEFT_UNSCANNED = '"aied" in markup the block scanner could not read';

    /**
     * $markup with every marker and whole-value kit token it can resolve resolved. An invalid kit (any
     * StyleKits::problems()) returns $markup unchanged. $adapter has the signature of KitAdapters::patch(); when
     * null, KitAdapters::patch is used if that class exists, otherwise every marker is left (no adapter).
     * Adapter contract: it returns the attribute subtree to merge (or null). A PHP array is written as a JSON object
     * when associative and as a JSON list otherwise, so an EMPTY PHP array becomes `[]`: an adapter must use
     * `new \stdClass()` for an empty object. An `aied` key in a patch is dropped (a patch can never add a marker).
     *
     * @param array<string,mixed> $kit
     */
    public static function resolve( string $markup, array $kit, ?callable $adapter = null ): string
    {
        if ( [] !== StyleKits::problems( $kit ) || ( ! str_contains( $markup, 'aied' ) && ! str_contains( $markup, '\\u' ) ) ) {
            return $markup;
        }
        if ( null === $adapter ) {
            $adapter = class_exists( __NAMESPACE__ . '\\KitAdapters' ) ? [ __NAMESPACE__ . '\\KitAdapters', 'patch' ] : null;
        }

        $out = preg_replace_callback(
            self::BLOCK,
            static function ( array $m ) use ( $kit, $adapter ): string {
                $attrs = self::decode( $m[3] );
                if ( null === $attrs ) {
                    return $m[0];
                }
                $changed = false;
                if ( property_exists( $attrs, 'aied' ) ) {
                    $marker = self::marker( $attrs->aied, $m[2] );
                    if ( null === $marker || null === $adapter ) {
                        return $m[0];
                    }
                    $patch = null === $marker[2] ? $adapter( $m[2], $marker[0], $marker[1], $kit ) : $adapter( $m[2], $marker[0], $marker[1], $kit, $marker[2] );
                    if ( ! is_array( $patch ) ) {
                        return $m[0];
                    }
                    self::merge( $attrs, $patch );
                    unset( $attrs->aied );
                    $changed = true;
                }
                $attrs = self::tokens( $attrs, $kit, $changed );
                if ( ! $changed ) {
                    return $m[0];
                }
                $json = RecipeTokens::encodeJson( $attrs );

                return '' === $json ? $m[0] : $m[1] . $json . $m[4];
            },
            $markup
        );

        return is_string( $out ) ? $out : $markup;
    }

    /**
     * What is still unresolved in $markup, each fixed description once, in order of first appearance: markers
     * (LEFT_MARKER), "aied" keys below the top level (LEFT_NESTED), block attributes that mention "aied" but do not
     * decode (LEFT_MALFORMED), and any `{{aied:kit:...}}` token (LEFT_TOKEN), anywhere. When the block scanner fails,
     * LEFT_UNSCANNED stands for the blocks (if the markup contains `"aied"`) and the token check still runs. At most
     * five entries; nothing the content wrote is echoed.
     *
     * @return list<string>
     */
    public static function leftovers( string $markup ): array
    {
        if ( ! str_contains( $markup, 'aied' ) && ! str_contains( $markup, '\\u' ) ) {
            return [];
        }
        $found = [];
        $all   = [];
        if ( false === preg_match_all( self::BLOCK, $markup, $all, PREG_SET_ORDER ) ) {
            // The scanner failed: say so with a fixed entry when the markup spells a marker key, then still do the
            // substring check for kit tokens below.
            $all = [];
            if ( str_contains( $markup, '"aied"' ) ) {
                $found[ self::LEFT_UNSCANNED ] = true;
            }
        }
        foreach ( $all as $m ) {
            if ( ! str_contains( $m[3], 'aied' ) && ! str_contains( $m[3], '\\u' ) ) {
                continue;
            }
            $attrs = self::decode( $m[3] );
            if ( null === $attrs ) {
                if ( str_contains( $m[3], 'aied' ) ) {
                    $found[ self::LEFT_MALFORMED ] = true;
                }
                continue;
            }
            foreach ( get_object_vars( $attrs ) as $key => $value ) {
                if ( 'aied' === $key ) {
                    $found[ self::LEFT_MARKER ] = true;
                    continue;
                }
                self::scan( $value, $found );
            }
        }
        if ( str_contains( $markup, '{{aied:kit:' ) ) {
            $found[ self::LEFT_TOKEN ] = true;
        }

        return array_keys( $found );
    }

    /** The decoded attribute object, or null when $json is not a JSON object within DEPTH (BlockAttrs::decode). */
    private static function decode( string $json ): ?object
    {
        return BlockAttrs::decode( $json );
    }

    /**
     * [role, on, ground] for a well-formed marker this code knows on $block (ground null when the marker names none),
     * else null.
     *
     * @param mixed $marker
     * @return ?array{0:string,1:string,2:?string}
     */
    private static function marker( $marker, string $block ): ?array
    {
        if ( ! $marker instanceof \stdClass ) {
            return null;
        }
        $vars = get_object_vars( $marker );
        $keys = array_keys( $vars );
        sort( $keys );
        if ( [ 'kit' ] !== $keys && [ 'kit', 'on' ] !== $keys && [ 'ground', 'kit' ] !== $keys && [ 'ground', 'kit', 'on' ] !== $keys ) {
            return null;
        }
        $role = $vars['kit'];
        $on   = array_key_exists( 'on', $vars ) ? $vars['on'] : 'light';
        if ( ! is_string( $role ) || ! is_string( $on ) || ! in_array( $on, self::ROLE_SURFACES[ $role ] ?? [], true ) ) {
            return null;
        }
        if ( ! in_array( $block, self::ROLE_BLOCKS[ $role ] ?? [], true ) ) {
            return null;
        }
        $ground = null;
        if ( array_key_exists( 'ground', $vars ) ) {
            $ground = $vars['ground'];
            if ( ! is_string( $ground ) || ! in_array( $ground, self::ROLE_GROUNDS[ $role ] ?? [], true ) || ! in_array( $on, self::GROUND_SURFACES, true ) || ! in_array( $block, self::GROUND_BLOCKS, true ) ) {
                return null;
            }
        }

        return [ $role, $on, $ground ];
    }

    /**
     * Deep-merge $patch into $node (BlockAttrs::merge): the patch wins; an associative patch array merges into an existing
     * object, anything else (a list, a scalar, a new key) replaces or is appended. Existing keys keep their place.
     *
     * @param array<mixed> $patch
     */
    private static function merge( \stdClass $node, array $patch ): void
    {
        BlockAttrs::merge( $node, $patch );
    }

    /**
     * $node with every whole-value kit token replaced by its object; sets $changed when one was.
     *
     * @param mixed               $node
     * @param array<string,mixed> $kit
     * @return mixed
     */
    private static function tokens( $node, array $kit, bool &$changed )
    {
        if ( is_string( $node ) ) {
            if ( 1 === preg_match( self::WHOLE_TOKEN, $node, $t ) ) {
                $value = StyleKits::valueToken( $kit, $t[1] );
                if ( null !== $value ) {
                    $changed = true;
                    return $value;
                }
            }
            return $node;
        }
        if ( $node instanceof \stdClass ) {
            foreach ( get_object_vars( $node ) as $key => $child ) {
                $node->{$key} = self::tokens( $child, $kit, $changed );
            }
            return $node;
        }
        if ( is_array( $node ) ) {
            foreach ( $node as $key => $child ) {
                $node[ $key ] = self::tokens( $child, $kit, $changed );
            }
        }
        return $node;
    }

    /**
     * Records what a non-top-level attribute value still carries: an "aied" key, a kit token.
     *
     * @param mixed               $node
     * @param array<string,true>  $found
     */
    private static function scan( $node, array &$found ): void
    {
        if ( is_string( $node ) ) {
            if ( str_contains( $node, '{{aied:kit:' ) ) {
                $found[ self::LEFT_TOKEN ] = true;
            }
            return;
        }
        if ( $node instanceof \stdClass ) {
            $node = get_object_vars( $node );
            if ( array_key_exists( 'aied', $node ) ) {
                $found[ self::LEFT_NESTED ] = true;
            }
        }
        if ( is_array( $node ) ) {
            foreach ( $node as $child ) {
                self::scan( $child, $found );
            }
        }
    }
}
