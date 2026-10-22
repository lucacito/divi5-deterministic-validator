<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A small, pure, deterministic CSS reader for html_to_divi (styles=basic). It reads CSS text and CSS values; it never
 * evaluates, fetches or writes anything, and it never throws: hostile input only produces counted "ignored" reasons.
 *
 * What it promises (HtmlStyles builds the cascade on these):
 * - Nothing leaves this class except NORMALISED values: colours as lowercase #rrggbb, a background as #rrggbb or "none",
 *   lengths as a canonical number plus px, rem, em or % (for example "24px", "1.5rem", "0.5em", "150%"), a font weight as
 *   "100".."900", an alignment as left|right|center|justify, a font family list as plain names joined by ", " (no quotes,
 *   no control characters), "none" for a max-width, and "inherit" (see below). Every other value is a POISON: the property
 *   is set to something this reader cannot evaluate, so a browser may compute any value. A poison carries a reason from
 *   REASONS and a null value. A soundness-minded caller treats a poison as "unresolved" instead of falling back to a lower rule.
 * - Parsing rules: comments are removed first (strings are respected); "<!--" and "-->" are dropped; strings, parentheses,
 *   square brackets and braces are respected when splitting on ";", "," and "{}" (a closer that does not match the open
 *   block is an ordinary character, as in CSS); @import, @charset, @namespace statements are counted (external for @import,
 *   at_rule otherwise); @font-face, the animation rule, @page, @property, @counter-style, @font-feature-values and any unknown block
 *   at-rule are skipped and counted (at_rule); @media, @supports, @container, @layer, @scope, @document, @starting-style
 *   blocks: the inner rules are parsed with poison_only = true and counted "media" per declaration; a block that is not
 *   closed at the end of the text is read to the end (as a browser does) and counted "selector"; a stray "}" is part of the
 *   next prelude (as in CSS), so that rule is not understood and poisons (see below); custom properties (--x) are counted
 *   "property" and not returned; nesting deeper than 8 levels is dropped (counted).
 * - Selectors: a comma list is split per item. An item that is exactly one tag, .class or #id is SIMPLE. Everything else is
 *   understood only as far as its subject (the rightmost compound: tag, ids, classes, and "other" for attribute or
 *   non-state pseudo-class parts) and is poison_only. The user-action pseudo-classes (hover, focus, active, visited,
 *   focus-visible, focus-within) and pseudo-elements never produce a rule (counted pseudo_state). A list in which any item
 *   cannot be understood (or is longer than MAX_SELECTOR characters, or has a vendor-prefixed pseudo with other items beside
 *   it) yields ONE universal poison-only rule (subject tag null, other true) carrying the list's declarations, because a
 *   browser may have dropped the whole list or applied it, and this reader cannot tell which. Rules nested inside a style
 *   rule (CSS nesting) are poison_only: their subject is their own rightmost compound, or universal when it uses "&" or is
 *   an at-rule. Specificity is computed per selector (:where counts zero, :is/:not/:has count their most specific argument).
 * - Declarations: only the properties HtmlStyles reads are returned (READ); shorthands are expanded (padding to 4 sides,
 *   border-radius to 4 corners, background to background-color, font to three poisons) and everything else is counted
 *   "property". Logical and vendor-prefixed spellings of a read property poison it (reason property). Properties that
 *   change how a colour looks (opacity, filter, mix-blend-mode, -webkit-text-fill-color, background-clip: text) poison
 *   "color" with reason effect; a background image poisons "background-color" with reason image_or_gradient; "all" poisons
 *   every read property. A var()/env()/attr() anywhere in a shorthand poisons all its longhands (reason function).
 *   "initial", "unset", "revert", "revert-layer" are poison keyword; "inherit" on a NON-inherited property is poison
 *   keyword; "inherit" on an inherited property (color, font-family, font-size, font-weight, text-align) is returned as the
 *   value "inherit" with no poison: it overrides lower rules by taking the parent's computed value, so a caller must not drop it.
 * - Colours: #rgb and #rrggbb in any case, rgb(r,g,b) and rgb(r g b) with integers 0-255, the CSS named colours. #rgba,
 *   #rrggbbaa, rgba(), hsla(), rgb() with an alpha and "transparent" are alpha (a background of "transparent" or "none" is the
 *   value "none"); hsl() and the other colour functions, percentages, currentcolor and system colours are colour_form;
 *   other functions are function.
 * - Caps (hard; none can be exceeded by any input): MAX_CSS_BYTES of input (the rest is not read, css_cap), MAX_RULES rules
 *   (the rest is not read, css_cap), MAX_DECLARATIONS declarations per block (the rest is replaced by a poison of every read
 *   property with important = true, css_cap), MAX_SELECTOR characters per selector, MAX_VALUE characters per value.
 *   css_cap > 0 therefore means "later rules or declarations were NOT seen": a caller that needs soundness treats every
 *   property as unresolved.
 * - No clock, no randomness, no locale-dependent formatting, no network or filesystem access.
 */
final class HtmlCss
{
    public const MAX_CSS_BYTES    = 100000;
    public const MAX_RULES        = 2000;
    public const MAX_DECLARATIONS = 100;
    public const MAX_SELECTOR     = 200;
    public const MAX_VALUE        = 1000;

    /** Closed list of `ignored` reasons (report keys). */
    public const REASONS = [ 'selector', 'at_rule', 'media', 'function', 'keyword', 'alpha', 'colour_form', 'unit', 'image_or_gradient',
        'property', 'pseudo_state', 'shorthand', 'presentational', 'effect', 'external', 'css_cap', 'not_on_block', 'mixed', 'unproven',
        'font_unavailable', 'generic_font', 'unknown_background', 'unreadable', 'unresolved', 'paint_dropped', 'button_ghosted' ];

    /** The longhand properties declarations() returns (a shorthand is expanded into these). */
    public const READ = [ 'color', 'background-color', 'font-family', 'font-size', 'font-weight', 'text-align',
        'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'max-width',
        'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius' ];

    /** The 148 CSS named colours (CSS Color 4), lowercase name => #rrggbb ('transparent' is not in it). */
    public const NAMED = [
        'aliceblue'              => '#f0f8ff',
        'antiquewhite'           => '#faebd7',
        'aqua'                   => '#00ffff',
        'aquamarine'             => '#7fffd4',
        'azure'                  => '#f0ffff',
        'beige'                  => '#f5f5dc',
        'bisque'                 => '#ffe4c4',
        'black'                  => '#000000',
        'blanchedalmond'         => '#ffebcd',
        'blue'                   => '#0000ff',
        'blueviolet'             => '#8a2be2',
        'brown'                  => '#a52a2a',
        'burlywood'              => '#deb887',
        'cadetblue'              => '#5f9ea0',
        'chartreuse'             => '#7fff00',
        'chocolate'              => '#d2691e',
        'coral'                  => '#ff7f50',
        'cornflowerblue'         => '#6495ed',
        'cornsilk'               => '#fff8dc',
        'crimson'                => '#dc143c',
        'cyan'                   => '#00ffff',
        'darkblue'               => '#00008b',
        'darkcyan'               => '#008b8b',
        'darkgoldenrod'          => '#b8860b',
        'darkgray'               => '#a9a9a9',
        'darkgreen'              => '#006400',
        'darkgrey'               => '#a9a9a9',
        'darkkhaki'              => '#bdb76b',
        'darkmagenta'            => '#8b008b',
        'darkolivegreen'         => '#556b2f',
        'darkorange'             => '#ff8c00',
        'darkorchid'             => '#9932cc',
        'darkred'                => '#8b0000',
        'darksalmon'             => '#e9967a',
        'darkseagreen'           => '#8fbc8f',
        'darkslateblue'          => '#483d8b',
        'darkslategray'          => '#2f4f4f',
        'darkslategrey'          => '#2f4f4f',
        'darkturquoise'          => '#00ced1',
        'darkviolet'             => '#9400d3',
        'deeppink'               => '#ff1493',
        'deepskyblue'            => '#00bfff',
        'dimgray'                => '#696969',
        'dimgrey'                => '#696969',
        'dodgerblue'             => '#1e90ff',
        'firebrick'              => '#b22222',
        'floralwhite'            => '#fffaf0',
        'forestgreen'            => '#228b22',
        'fuchsia'                => '#ff00ff',
        'gainsboro'              => '#dcdcdc',
        'ghostwhite'             => '#f8f8ff',
        'gold'                   => '#ffd700',
        'goldenrod'              => '#daa520',
        'gray'                   => '#808080',
        'green'                  => '#008000',
        'greenyellow'            => '#adff2f',
        'grey'                   => '#808080',
        'honeydew'               => '#f0fff0',
        'hotpink'                => '#ff69b4',
        'indianred'              => '#cd5c5c',
        'indigo'                 => '#4b0082',
        'ivory'                  => '#fffff0',
        'khaki'                  => '#f0e68c',
        'lavender'               => '#e6e6fa',
        'lavenderblush'          => '#fff0f5',
        'lawngreen'              => '#7cfc00',
        'lemonchiffon'           => '#fffacd',
        'lightblue'              => '#add8e6',
        'lightcoral'             => '#f08080',
        'lightcyan'              => '#e0ffff',
        'lightgoldenrodyellow'   => '#fafad2',
        'lightgray'              => '#d3d3d3',
        'lightgreen'             => '#90ee90',
        'lightgrey'              => '#d3d3d3',
        'lightpink'              => '#ffb6c1',
        'lightsalmon'            => '#ffa07a',
        'lightseagreen'          => '#20b2aa',
        'lightskyblue'           => '#87cefa',
        'lightslategray'         => '#778899',
        'lightslategrey'         => '#778899',
        'lightsteelblue'         => '#b0c4de',
        'lightyellow'            => '#ffffe0',
        'lime'                   => '#00ff00',
        'limegreen'              => '#32cd32',
        'linen'                  => '#faf0e6',
        'magenta'                => '#ff00ff',
        'maroon'                 => '#800000',
        'mediumaquamarine'       => '#66cdaa',
        'mediumblue'             => '#0000cd',
        'mediumorchid'           => '#ba55d3',
        'mediumpurple'           => '#9370db',
        'mediumseagreen'         => '#3cb371',
        'mediumslateblue'        => '#7b68ee',
        'mediumspringgreen'      => '#00fa9a',
        'mediumturquoise'        => '#48d1cc',
        'mediumvioletred'        => '#c71585',
        'midnightblue'           => '#191970',
        'mintcream'              => '#f5fffa',
        'mistyrose'              => '#ffe4e1',
        'moccasin'               => '#ffe4b5',
        'navajowhite'            => '#ffdead',
        'navy'                   => '#000080',
        'oldlace'                => '#fdf5e6',
        'olive'                  => '#808000',
        'olivedrab'              => '#6b8e23',
        'orange'                 => '#ffa500',
        'orangered'              => '#ff4500',
        'orchid'                 => '#da70d6',
        'palegoldenrod'          => '#eee8aa',
        'palegreen'              => '#98fb98',
        'paleturquoise'          => '#afeeee',
        'palevioletred'          => '#db7093',
        'papayawhip'             => '#ffefd5',
        'peachpuff'              => '#ffdab9',
        'peru'                   => '#cd853f',
        'pink'                   => '#ffc0cb',
        'plum'                   => '#dda0dd',
        'powderblue'             => '#b0e0e6',
        'purple'                 => '#800080',
        'rebeccapurple'          => '#663399',
        'red'                    => '#ff0000',
        'rosybrown'              => '#bc8f8f',
        'royalblue'              => '#4169e1',
        'saddlebrown'            => '#8b4513',
        'salmon'                 => '#fa8072',
        'sandybrown'             => '#f4a460',
        'seagreen'               => '#2e8b57',
        'seashell'               => '#fff5ee',
        'sienna'                 => '#a0522d',
        'silver'                 => '#c0c0c0',
        'skyblue'                => '#87ceeb',
        'slateblue'              => '#6a5acd',
        'slategray'              => '#708090',
        'slategrey'              => '#708090',
        'snow'                   => '#fffafa',
        'springgreen'            => '#00ff7f',
        'steelblue'              => '#4682b4',
        'tan'                    => '#d2b48c',
        'teal'                   => '#008080',
        'thistle'                => '#d8bfd8',
        'tomato'                 => '#ff6347',
        'turquoise'              => '#40e0d0',
        'violet'                 => '#ee82ee',
        'wheat'                  => '#f5deb3',
        'white'                  => '#ffffff',
        'whitesmoke'             => '#f5f5f5',
        'yellow'                 => '#ffff00',
        'yellowgreen'            => '#9acd32',
    ];

    private const MAX_DEPTH    = 8;
    private const MAX_FAMILIES = 10;

    /** CSS-wide keywords. */
    private const WIDE = [ 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ];

    /** Read properties that inherit. */
    private const INHERITED = [ 'color', 'font-family', 'font-size', 'font-weight', 'text-align' ];

    private const SIDES   = [ 'padding-top', 'padding-right', 'padding-bottom', 'padding-left' ];
    private const CORNERS = [ 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius' ];

    /** Block at-rules whose inner rules are read as poison-only. */
    private const CONDITIONAL = [ 'media', 'supports', 'container', 'layer', 'scope', 'document', '-moz-document', 'starting-style' ];

    /** Pseudo-classes that never poison (a user-action state). */
    private const USER_ACTION = [ 'hover', 'focus', 'active', 'visited', 'focus-visible', 'focus-within' ];

    /** Single-colon spellings of pseudo-elements. */
    private const LEGACY_ELEMENTS = [ 'before', 'after', 'first-line', 'first-letter' ];

    /** Pseudo-class names a browser knows (a vendor-prefixed name is accepted separately). */
    private const PSEUDO_CLASSES = [ 'active', 'any-link', 'autofill', 'blank', 'buffering', 'checked', 'closed', 'current', 'default', 'defined',
        'dir', 'disabled', 'empty', 'enabled', 'first', 'first-child', 'first-of-type', 'focus', 'focus-visible', 'focus-within', 'fullscreen',
        'future', 'has', 'has-slotted', 'host', 'host-context', 'hover', 'in-range', 'indeterminate', 'invalid', 'is', 'lang', 'last-child',
        'last-of-type', 'left', 'link', 'local-link', 'matches', 'modal', 'muted', 'not', 'nth-child', 'nth-last-child', 'nth-last-of-type',
        'nth-of-type', 'only-child', 'only-of-type', 'open', 'optional', 'out-of-range', 'past', 'paused', 'picture-in-picture',
        'placeholder-shown', 'playing', 'popover-open', 'read-only', 'read-write', 'required', 'right', 'root', 'scope', 'seeking', 'stalled',
        'state', 'target', 'target-within', 'user-invalid', 'user-valid', 'valid', 'visited', 'volume-locked', 'where' ];

    private const SYSTEM_COLOURS = [ 'canvas', 'canvastext', 'linktext', 'visitedtext', 'activetext', 'buttonface', 'buttontext', 'buttonborder',
        'field', 'fieldtext', 'highlight', 'highlighttext', 'selecteditem', 'selecteditemtext', 'mark', 'marktext', 'graytext', 'accentcolor',
        'accentcolortext', 'activeborder', 'activecaption', 'appworkspace', 'background', 'buttonhighlight', 'buttonshadow', 'captiontext',
        'inactiveborder', 'inactivecaption', 'inactivecaptiontext', 'infobackground', 'infotext', 'menu', 'menutext', 'scrollbar',
        'threeddarkshadow', 'threedface', 'threedhighlight', 'threedlightshadow', 'threedshadow', 'window', 'windowframe', 'windowtext' ];

    /** Properties that poison a read property by their very existence (logical, vendor, effects): name => read properties. */
    private const AFFECTS = [
        'padding-inline'        => [ 'padding-left', 'padding-right' ],
        'padding-inline-start'  => [ 'padding-left', 'padding-right' ],
        'padding-inline-end'    => [ 'padding-left', 'padding-right' ],
        '-webkit-padding-start' => [ 'padding-left', 'padding-right' ],
        '-webkit-padding-end'   => [ 'padding-left', 'padding-right' ],
        '-moz-padding-start'    => [ 'padding-left', 'padding-right' ],
        '-moz-padding-end'      => [ 'padding-left', 'padding-right' ],
        'padding-block'         => [ 'padding-top', 'padding-bottom' ],
        'padding-block-start'   => [ 'padding-top', 'padding-bottom' ],
        'padding-block-end'     => [ 'padding-top', 'padding-bottom' ],
        'max-inline-size'       => [ 'max-width' ],
        'border-start-start-radius' => self::CORNERS,
        'border-start-end-radius'   => self::CORNERS,
        'border-end-start-radius'   => self::CORNERS,
        'border-end-end-radius'     => self::CORNERS,
        '-webkit-border-radius'     => self::CORNERS,
        '-moz-border-radius'        => self::CORNERS,
    ];

    /** Properties that change how a colour looks: name => read properties they poison (reason effect). */
    private const EFFECTS = [
        'opacity'                 => [ 'color' ],
        'filter'                  => [ 'color' ],
        'mix-blend-mode'          => [ 'color' ],
        '-webkit-text-fill-color' => [ 'color' ],
        'background-clip'         => [ 'color', 'background-color' ],
        '-webkit-background-clip' => [ 'color', 'background-color' ],
    ];

    // ---------------------------------------------------------------
    // Stylesheet
    // ---------------------------------------------------------------

    /**
     * @return array{
     *   rules: list<array{subject: array{tag:?string, ids:list<string>, classes:list<string>, other:bool},
     *                     simple: bool, specificity: array{0:int,1:int,2:int}, poison_only: bool, order: int,
     *                     declarations: list<array{property:string, value:?string, important:bool, poison:?string}>}>,
     *   ignored: array<string,int>
     * }
     * simple       = the selector is exactly one `tag`, `.class` or `#id`: it APPLIES (unless poison_only too).
     * poison_only  = not simple, or inside a block at-rule or a nested rule: it never applies, but marks its properties
     *                unresolved on every element its subject could match. A selector whose subject has a pseudo-element or
     *                ONLY user-action pseudo-classes yields no rule at all (`pseudo_state`). Rules without a read
     *                declaration are not kept.
     */
    public static function stylesheet( string $css ): array
    {
        $ctx = [ 'rules' => [], 'ignored' => [], 'full' => false ];
        if ( strlen( $css ) > self::MAX_CSS_BYTES ) {
            $css = substr( $css, 0, self::MAX_CSS_BYTES );
            self::bump( $ctx['ignored'], 'css_cap' );
        }
        self::ruleList( self::clean( $css ), $ctx, 0, false );
        ksort( $ctx['ignored'] );

        return [ 'rules' => $ctx['rules'], 'ignored' => $ctx['ignored'] ];
    }

    /**
     * One declaration block (`style=""` text or a rule body). Shorthands expanded: `padding` -> 4 sides, `border-radius` -> 4
     * corners, `background` -> `background-color` (or a poison of it), `font` -> poison of size, weight and family.
     * A rule nested inside the block (CSS nesting) cannot be represented here: every property it sets is returned as a poison
     * (reason unresolved) on this element instead.
     *
     * @param array<string,int> $ignored receives the counted reasons
     * @return list<array{property:string, value:?string, important:bool, poison:?string}>  poison = the reason, value null
     */
    public static function declarations( string $block, array &$ignored ): array
    {
        if ( strlen( $block ) > self::MAX_CSS_BYTES ) {
            $block = substr( $block, 0, self::MAX_CSS_BYTES );
            self::bump( $ignored, 'css_cap' );
        }
        $r   = self::readBody( self::clean( $block ), $ignored );
        $out = $r['decls'];
        $seen = [];
        foreach ( $r['nested'] as $nested ) {
            self::bump( $ignored, 'selector' );
            foreach ( self::nestedPoison( $nested[1], $ignored, 1 ) as $d ) {
                $key = $d['property'] . '|' . ( $d['important'] ? '1' : '0' );
                if ( ! isset( $seen[ $key ] ) ) {
                    $seen[ $key ] = true;
                    $out[]        = $d;
                }
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------
    // Values
    // ---------------------------------------------------------------

    /** @return array{kind:'colour',hex:string}|array{kind:'none'}|array{kind:'poison',reason:string} */
    public static function colour( string $value ): array
    {
        $v  = trim( $value );
        $lv = strtolower( $v );
        if ( '' === $v || strlen( $v ) > self::MAX_VALUE ) {
            return self::poisonKind( 'colour_form' );
        }
        if ( in_array( $lv, self::WIDE, true ) ) {
            return self::poisonKind( 'keyword' );
        }
        if ( self::substitution( $lv ) ) {
            return self::poisonKind( 'function' );
        }
        if ( '#' === $v[0] ) {
            $hex = substr( $v, 1 );
            if ( ! ctype_xdigit( $hex ) ) {
                return self::poisonKind( 'colour_form' );
            }
            $hex = strtolower( $hex );
            switch ( strlen( $hex ) ) {
                case 3:
                    return [ 'kind' => 'colour', 'hex' => '#' . $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] ];
                case 6:
                    return [ 'kind' => 'colour', 'hex' => '#' . $hex ];
                case 4:
                case 8:
                    return self::poisonKind( 'alpha' );
                default:
                    return self::poisonKind( 'colour_form' );
            }
        }
        if ( false !== strpos( $v, '(' ) ) {
            return self::colourFunction( $v );
        }
        if ( 'transparent' === $lv ) {
            return self::poisonKind( 'alpha' );
        }
        if ( isset( self::NAMED[ $lv ] ) ) {
            return [ 'kind' => 'colour', 'hex' => self::NAMED[ $lv ] ];
        }

        return self::poisonKind( 'colour_form' );
    }

    /** background shorthand: one colour token -> colour; `none`/`transparent` -> none; anything else -> poison image_or_gradient */
    public static function background( string $value ): array
    {
        $v  = trim( $value );
        $lv = strtolower( $v );
        if ( '' === $v || strlen( $v ) > self::MAX_VALUE ) {
            return self::poisonKind( 'unreadable' );
        }
        if ( 'none' === $lv || 'transparent' === $lv ) {
            return [ 'kind' => 'none' ];
        }
        if ( in_array( $lv, self::WIDE, true ) ) {
            return self::poisonKind( 'keyword' );
        }
        if ( self::substitution( $lv ) ) {
            return self::poisonKind( 'function' );
        }
        if ( 1 === preg_match( '/(?:url|image|image-set|element|paint|cross-fade|[a-z-]*gradient)\(/', $lv ) ) {
            return self::poisonKind( 'image_or_gradient' );
        }
        if ( count( self::words( $v ) ) > 1 ) {
            return self::poisonKind( 'image_or_gradient' );
        }

        return self::colour( $v );
    }

    /** px for px/rem (16)/em (x $em)/% (only when $percentOfEm, x $em/100); null = unsupported (unit) */
    public static function length( string $value, float $em, bool $percentOfEm ): ?float
    {
        $token = self::lengthToken( trim( $value ), true );
        if ( null === $token || ! is_finite( $em ) ) {
            return null;
        }
        $unit   = 'px';
        $number = $token;
        foreach ( [ 'rem', 'em', 'px', '%' ] as $u ) {
            if ( str_ends_with( $token, $u ) ) {
                $unit   = $u;
                $number = substr( $token, 0, -strlen( $u ) );
                break;
            }
        }
        $n = (float) $number;
        switch ( $unit ) {
            case 'px':
                return $n;
            case 'rem':
                return $n * 16.0;
            case 'em':
                return $n * $em;
            default:
                return $percentOfEm ? $n * $em / 100.0 : null;
        }
    }

    /** @return list<string> family names, quotes removed, original case kept ([] when any item cannot be read) */
    public static function families( string $value ): array
    {
        return self::parseFamilies( $value ) ?? [];
    }

    /** 100..900 step 100, normal = 400, bold = 700; bolder/lighter and anything else -> null */
    public static function weight( string $value ): ?int
    {
        $v = strtolower( trim( $value ) );
        if ( 'normal' === $v ) {
            return 400;
        }
        if ( 'bold' === $v ) {
            return 700;
        }
        if ( 1 === preg_match( '/^[1-9]00$/', $v ) ) {
            return (int) $v;
        }

        return null;
    }

    /** left|right|center|justify; start/end/-webkit-* and anything else -> null */
    public static function align( string $value ): ?string
    {
        $v = strtolower( trim( $value ) );

        return in_array( $v, [ 'left', 'right', 'center', 'justify' ], true ) ? $v : null;
    }

    // ---------------------------------------------------------------
    // Rule lists
    // ---------------------------------------------------------------

    /** @param array<string,mixed> $ctx */
    private static function ruleList( string $s, array &$ctx, int $depth, bool $cond ): void
    {
        $n = strlen( $s );
        $i = 0;
        while ( $i < $n && ! $ctx['full'] ) {
            $i += strspn( $s, " \t\n\r\f", $i );
            if ( $i >= $n ) {
                break;
            }
            if ( '@' === $s[ $i ] ) {
                $i = self::atRule( $s, $i, $ctx, $depth );
                continue;
            }
            $open = self::scanTo( $s, $i, '{' );
            if ( $open >= $n ) {
                if ( '' !== trim( substr( $s, $i ) ) ) {
                    self::bump( $ctx['ignored'], 'selector' );
                }
                break;
            }
            $close = self::findClose( $s, $open );
            if ( $close >= $n ) {
                self::bump( $ctx['ignored'], 'selector' );
            }
            self::styleRule( trim( substr( $s, $i, $open - $i ) ), substr( $s, $open + 1, $close - $open - 1 ), $ctx, $depth, $cond );
            $i = $close + 1;
        }
    }

    /** @param array<string,mixed> $ctx @return int the index after the at-rule */
    private static function atRule( string $s, int $i, array &$ctx, int $depth ): int
    {
        $n    = strlen( $s );
        $j    = $i + 1;
        $j   += strspn( $s, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_', $j );
        $name = strtolower( substr( $s, $i + 1, $j - $i - 1 ) );
        $end  = self::scanTo( $s, $j, ';{' );
        if ( $end >= $n || ';' === $s[ $end ] ) {
            self::bump( $ctx['ignored'], 'import' === $name ? 'external' : 'at_rule' );

            return $end + 1;
        }
        $close = self::findClose( $s, $end );
        if ( ! in_array( $name, self::CONDITIONAL, true ) || $depth >= self::MAX_DEPTH ) {
            self::bump( $ctx['ignored'], 'at_rule' );

            return $close + 1;
        }
        if ( $close >= $n ) {
            self::bump( $ctx['ignored'], 'selector' );
        }
        self::ruleList( substr( $s, $end + 1, $close - $end - 1 ), $ctx, $depth + 1, true );

        return $close + 1;
    }

    /** @param array<string,mixed> $ctx */
    private static function styleRule( string $prelude, string $body, array &$ctx, int $depth, bool $cond ): void
    {
        $r = self::readBody( $body, $ctx['ignored'] );
        if ( $cond ) {
            self::bump( $ctx['ignored'], 'media', count( $r['decls'] ) );
        }
        if ( array() !== $r['decls'] ) {
            self::selectorRules( $prelude, $r['decls'], $ctx, $cond );
        }
        foreach ( $r['nested'] as $nested ) {
            if ( $ctx['full'] ) {
                return;
            }
            self::nestedRule( $nested[0], $nested[1], $ctx, $depth + 1 );
        }
    }

    /** @param array<string,mixed> $ctx @param list<array<string,mixed>> $decls */
    private static function selectorRules( string $prelude, array $decls, array &$ctx, bool $cond ): void
    {
        $parsed = [];
        $bad    = false;
        foreach ( self::splitTop( $prelude, ',' ) as $item ) {
            $item = trim( $item );
            if ( '' === $item || strlen( $item ) > self::MAX_SELECTOR ) {
                $bad = true;
                break;
            }
            $p = self::parseSelector( $item, false, 0 );
            if ( null === $p ) {
                $bad = true;
                break;
            }
            $parsed[] = $p;
        }
        if ( ! $bad && count( $parsed ) > 1 ) {
            foreach ( $parsed as $p ) {
                if ( $p['vendor'] ) {
                    $bad = true;
                }
            }
        }
        if ( $bad ) {
            self::bump( $ctx['ignored'], 'selector' );
            self::emit( $ctx, self::universal(), false, [ 0, 0, 0 ], true, $decls );

            return;
        }
        foreach ( $parsed as $p ) {
            if ( $ctx['full'] ) {
                return;
            }
            if ( $p['pseudo_element'] || $p['user_only'] ) {
                self::bump( $ctx['ignored'], 'pseudo_state' );
                continue;
            }
            if ( ! $p['simple'] ) {
                self::bump( $ctx['ignored'], 'selector' );
            }
            self::emit( $ctx, $p['subject'], $p['simple'], $p['spec'], $cond || ! $p['simple'], $decls );
        }
    }

    /** @param array<string,mixed> $ctx */
    private static function nestedRule( string $prelude, string $body, array &$ctx, int $depth ): void
    {
        if ( $depth > self::MAX_DEPTH ) {
            self::bump( $ctx['ignored'], 'css_cap' );
            self::emit( $ctx, self::universal(), false, [ 0, 0, 0 ], true, self::allUnresolved() );

            return;
        }
        $r        = self::readBody( $body, $ctx['ignored'] );
        $prelude  = trim( $prelude );
        $subjects = [];
        if ( '' !== $prelude && '@' === $prelude[0] ) {
            $name = 1 === preg_match( '/^@([A-Za-z-]+)/', $prelude, $m ) ? strtolower( $m[1] ) : '';
            if ( ! in_array( $name, self::CONDITIONAL, true ) ) {
                self::bump( $ctx['ignored'], 'at_rule' );

                return;
            }
            self::bump( $ctx['ignored'], 'media', count( $r['decls'] ) );
            $subjects[] = [ self::universal(), [ 0, 0, 0 ] ];
        } else {
            $items = self::splitTop( $prelude, ',' );
            self::bump( $ctx['ignored'], 'selector', count( $items ) );
            $bad = false;
            foreach ( $items as $item ) {
                $item = trim( $item );
                $p    = ( '' === $item || strlen( $item ) > self::MAX_SELECTOR ) ? null : self::parseSelector( $item, true, 0 );
                if ( null === $p || $p['amp'] ) {
                    $bad = true;
                    break;
                }
                if ( $p['pseudo_element'] || $p['user_only'] ) {
                    self::bump( $ctx['ignored'], 'pseudo_state' );
                    continue;
                }
                $subjects[] = [ $p['subject'], $p['spec'] ];
            }
            if ( $bad ) {
                $subjects = [ [ self::universal(), [ 0, 0, 0 ] ] ];
            }
        }
        if ( array() !== $r['decls'] ) {
            foreach ( $subjects as $s ) {
                self::emit( $ctx, $s[0], false, $s[1], true, $r['decls'] );
            }
        }
        foreach ( $r['nested'] as $nested ) {
            if ( $ctx['full'] ) {
                return;
            }
            self::nestedRule( $nested[0], $nested[1], $ctx, $depth + 1 );
        }
    }

    /** @param array<string,mixed> $ctx @param array{tag:?string,ids:list<string>,classes:list<string>,other:bool} $subject @param list<int> $spec @param list<array<string,mixed>> $decls */
    private static function emit( array &$ctx, array $subject, bool $simple, array $spec, bool $poison, array $decls ): void
    {
        if ( count( $ctx['rules'] ) >= self::MAX_RULES ) {
            if ( ! $ctx['full'] ) {
                $ctx['full'] = true;
                self::bump( $ctx['ignored'], 'css_cap' );
            }

            return;
        }
        $ctx['rules'][] = [
            'subject'      => $subject,
            'simple'       => $simple,
            'specificity'  => $spec,
            'poison_only'  => $poison,
            'order'        => count( $ctx['rules'] ),
            'declarations' => $decls,
        ];
    }

    /** @return array{tag:?string,ids:list<string>,classes:list<string>,other:bool} */
    private static function universal(): array
    {
        return [ 'tag' => null, 'ids' => [], 'classes' => [], 'other' => true ];
    }

    /** @return list<array{property:string, value:?string, important:bool, poison:?string}> */
    private static function allUnresolved(): array
    {
        $out = [];
        foreach ( self::READ as $p ) {
            $out[] = self::entry( $p, null, true, 'unresolved' );
        }

        return $out;
    }

    /**
     * The declarations and the nested rules of a block body.
     *
     * @param array<string,int> $ignored
     * @return array{decls: list<array{property:string, value:?string, important:bool, poison:?string}>, nested: list<array{0:string,1:string}>}
     */
    private static function readBody( string $body, array &$ignored ): array
    {
        $overflow = false;
        $decls    = [];
        $nested   = [];
        foreach ( self::splitBody( $body, $overflow ) as $item ) {
            if ( 'decl' === $item[0] ) {
                foreach ( self::declaration( $item[1], $ignored ) as $d ) {
                    $decls[] = $d;
                }
            } else {
                if ( ! $item[3] ) {
                    self::bump( $ignored, 'selector' );
                }
                $nested[] = [ $item[1], $item[2] ];
            }
        }
        if ( $overflow ) {
            self::bump( $ignored, 'css_cap' );
            foreach ( self::allUnresolved() as $d ) {
                $decls[] = $d;
            }
        }

        return [ 'decls' => $decls, 'nested' => $nested ];
    }

    /**
     * Every property a nested rule sets, as a poison, one entry per property and importance.
     *
     * @param array<string,int> $ignored
     * @return list<array{property:string, value:?string, important:bool, poison:?string}>
     */
    private static function nestedPoison( string $body, array &$ignored, int $depth ): array
    {
        if ( $depth > self::MAX_DEPTH ) {
            self::bump( $ignored, 'css_cap' );

            return self::allUnresolved();
        }
        $r   = self::readBody( $body, $ignored );
        $out = [];
        foreach ( $r['decls'] as $d ) {
            $out[] = self::entry( $d['property'], null, $d['important'], 'unresolved' );
        }
        foreach ( $r['nested'] as $nested ) {
            foreach ( self::nestedPoison( $nested[1], $ignored, $depth + 1 ) as $d ) {
                $out[] = $d;
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------
    // Text scanning (comments, strings, brackets)
    // ---------------------------------------------------------------

    /** Comments, control characters and the HTML comment tokens removed; strings kept as they are. */
    private static function clean( string $s ): string
    {
        $s   = (string) preg_replace( '/[\x00-\x08\x0B\x0E-\x1F\x7F]/', ' ', $s );
        $n   = strlen( $s );
        $out = '';
        $i   = 0;
        while ( $i < $n ) {
            $c = $s[ $i ];
            if ( '"' === $c || "'" === $c ) {
                $j    = self::stringEnd( $s, $i );
                $out .= substr( $s, $i, $j - $i );
                $i    = $j;
            } elseif ( '\\' === $c ) {
                $out .= substr( $s, $i, 2 );
                $i   += 2;
            } elseif ( '/' === $c && $i + 1 < $n && '*' === $s[ $i + 1 ] ) {
                $e    = strpos( $s, '*/', $i + 2 );
                $i    = false === $e ? $n : $e + 2;
                $out .= ' ';
            } elseif ( '<' === $c && '<!--' === substr( $s, $i, 4 ) ) {
                $out .= '    ';
                $i   += 4;
            } elseif ( '-' === $c && '-->' === substr( $s, $i, 3 ) ) {
                $out .= '   ';
                $i   += 3;
            } else {
                $out .= $c;
                ++$i;
            }
        }

        return $out;
    }

    /** The index after the string that starts at $i (an unescaped line break ends it early, as in CSS). */
    private static function stringEnd( string $s, int $i ): int
    {
        $n = strlen( $s );
        $q = $s[ $i ];
        $j = $i + 1;
        while ( $j < $n ) {
            $c = $s[ $j ];
            if ( '\\' === $c ) {
                $j += 2;
            } elseif ( $c === $q ) {
                return $j + 1;
            } elseif ( "\n" === $c || "\r" === $c || "\f" === $c ) {
                return $j;
            } else {
                ++$j;
            }
        }

        return $n;
    }

    /** @param list<string> $stack */
    private static function track( array &$stack, string $c ): void
    {
        if ( '(' === $c ) {
            $stack[] = ')';
        } elseif ( '[' === $c ) {
            $stack[] = ']';
        } elseif ( '{' === $c ) {
            $stack[] = '}';
        } elseif ( ( ')' === $c || ']' === $c || '}' === $c ) && array() !== $stack && end( $stack ) === $c ) {
            array_pop( $stack );
        }
    }

    /** The index of the first of $stops outside strings and brackets, or the length. */
    private static function scanTo( string $s, int $i, string $stops ): int
    {
        $n     = strlen( $s );
        $stack = [];
        while ( $i < $n ) {
            $c = $s[ $i ];
            if ( '"' === $c || "'" === $c ) {
                $i = self::stringEnd( $s, $i );
                continue;
            }
            if ( '\\' === $c ) {
                $i += 2;
                continue;
            }
            if ( array() === $stack && false !== strpos( $stops, $c ) ) {
                return $i;
            }
            self::track( $stack, $c );
            ++$i;
        }

        return $n;
    }

    /** The index of the "}" that closes the "{" at $open, or the length when it never closes. */
    private static function findClose( string $s, int $open ): int
    {
        $n     = strlen( $s );
        $stack = [ '}' ];
        $i     = $open + 1;
        while ( $i < $n ) {
            $c = $s[ $i ];
            if ( '"' === $c || "'" === $c ) {
                $i = self::stringEnd( $s, $i );
                continue;
            }
            if ( '\\' === $c ) {
                $i += 2;
                continue;
            }
            self::track( $stack, $c );
            if ( array() === $stack ) {
                return $i;
            }
            ++$i;
        }

        return $n;
    }

    /** @return list<string> the pieces of $s split at any char of $delims outside strings and brackets (empty pieces kept) */
    private static function splitTop( string $s, string $delims ): array
    {
        $out   = [];
        $n     = strlen( $s );
        $stack = [];
        $start = 0;
        $i     = 0;
        while ( $i < $n ) {
            $c = $s[ $i ];
            if ( '"' === $c || "'" === $c ) {
                $i = self::stringEnd( $s, $i );
                continue;
            }
            if ( '\\' === $c ) {
                $i += 2;
                continue;
            }
            if ( array() === $stack && false !== strpos( $delims, $c ) ) {
                $out[] = substr( $s, $start, $i - $start );
                $start = $i + 1;
                ++$i;
                continue;
            }
            self::track( $stack, $c );
            ++$i;
        }
        $out[] = substr( $s, $start );

        return $out;
    }

    /** @return list<string> whitespace separated top-level words */
    private static function words( string $s ): array
    {
        return array_values( array_filter( self::splitTop( $s, " \t\n\r\f" ), static fn( string $w ): bool => '' !== $w ) );
    }

    /**
     * A block body split into declarations and nested rules, stopping at the caps.
     *
     * @return list<array{0:string,1:string,2?:string,3?:bool}>  ['decl', text] or ['rule', prelude, body, closed]
     */
    private static function splitBody( string $body, bool &$overflow ): array
    {
        $items  = [];
        $n      = strlen( $body );
        $i      = 0;
        $start  = 0;
        $stack  = [];
        $decls  = 0;
        $nested = 0;
        while ( $i < $n ) {
            $c = $body[ $i ];
            if ( '"' === $c || "'" === $c ) {
                $i = self::stringEnd( $body, $i );
                continue;
            }
            if ( '\\' === $c ) {
                $i += 2;
                continue;
            }
            if ( array() === $stack ) {
                if ( ';' === $c ) {
                    $t = substr( $body, $start, $i - $start );
                    if ( '' !== trim( $t ) ) {
                        if ( ++$decls > self::MAX_DECLARATIONS ) {
                            $overflow = true;

                            return $items;
                        }
                        $items[] = [ 'decl', $t ];
                    }
                    $start = $i + 1;
                    ++$i;
                    continue;
                }
                if ( '{' === $c ) {
                    if ( ++$nested > self::MAX_RULES ) {
                        $overflow = true;

                        return $items;
                    }
                    $close   = self::findClose( $body, $i );
                    $items[] = [ 'rule', substr( $body, $start, $i - $start ), substr( $body, $i + 1, $close - $i - 1 ), $close < $n ];
                    $start   = $close + 1;
                    $i       = $close + 1;
                    continue;
                }
            }
            self::track( $stack, $c );
            ++$i;
        }
        $t = substr( $body, min( $start, $n ) );
        if ( '' !== trim( $t ) ) {
            if ( ++$decls > self::MAX_DECLARATIONS ) {
                $overflow = true;

                return $items;
            }
            $items[] = [ 'decl', $t ];
        }

        return $items;
    }

    // ---------------------------------------------------------------
    // Selectors
    // ---------------------------------------------------------------

    /**
     * @return ?array{subject: array{tag:?string,ids:list<string>,classes:list<string>,other:bool}, spec: list<int>, simple: bool,
     *                pseudo_element: bool, user_only: bool, vendor: bool, amp: bool}  null = not understood
     */
    private static function parseSelector( string $s, bool $nested, int $depth ): ?array
    {
        $s = trim( $s );
        $n = strlen( $s );
        if ( 0 === $n ) {
            return null;
        }
        $compounds = [];
        $cur       = null;
        $need      = false;
        $i         = 0;
        while ( true ) {
            $ws = strspn( $s, " \t\n\r\f", $i );
            $i += $ws;
            if ( $i >= $n ) {
                break;
            }
            $c = $s[ $i ];
            if ( '>' === $c || '+' === $c || '~' === $c ) {
                if ( $need ) {
                    return null;
                }
                if ( null === $cur ) {
                    if ( ! $nested || array() !== $compounds ) {
                        return null;
                    }
                } else {
                    $compounds[] = $cur;
                    $cur         = null;
                }
                $need = true;
                ++$i;
                continue;
            }
            if ( null !== $cur && $ws > 0 ) {
                $compounds[] = $cur;
                $cur         = null;
            }
            if ( null === $cur ) {
                $cur = [ 'tag' => null, 'ids' => [], 'classes' => [], 'universal' => false, 'attrs' => 0, 'user' => 0, 'nonuser' => 0,
                    'pseudo_element' => false, 'amp' => false, 'vendor' => false, 'count' => 0, 'spec' => [ 0, 0, 0 ] ];
            }
            if ( ! self::component( $s, $i, $cur, $nested, $depth ) ) {
                return null;
            }
            $need = false;
        }
        if ( $need ) {
            return null;
        }
        if ( null !== $cur ) {
            $compounds[] = $cur;
        }
        if ( array() === $compounds ) {
            return null;
        }
        $spec   = [ 0, 0, 0 ];
        $vendor = false;
        foreach ( $compounds as $comp ) {
            $spec[0] += $comp['spec'][0];
            $spec[1] += $comp['spec'][1];
            $spec[2] += $comp['spec'][2];
            $vendor   = $vendor || $comp['vendor'];
        }
        $last   = $compounds[ count( $compounds ) - 1 ];
        $simple = 1 === count( $compounds ) && 1 === $last['count'] && ! $last['universal'] && 0 === $last['attrs'] && 0 === $last['user'] + $last['nonuser']
            && ! $last['pseudo_element'] && ! $last['amp'];

        return [
            'subject'        => [ 'tag' => $last['tag'], 'ids' => $last['ids'], 'classes' => $last['classes'], 'other' => $last['attrs'] > 0 || $last['nonuser'] > 0 ],
            'spec'           => $spec,
            'simple'         => $simple,
            'pseudo_element' => $last['pseudo_element'],
            'user_only'      => $last['user'] > 0 && 0 === $last['nonuser'] && 0 === $last['attrs'],
            'vendor'         => $vendor,
            'amp'            => $last['amp'],
        ];
    }

    /** One simple-selector component at $i; false = not understood. @param array<string,mixed> $cur */
    private static function component( string $s, int &$i, array &$cur, bool $nested, int $depth ): bool
    {
        $n = strlen( $s );
        $c = $s[ $i ];
        if ( '*' === $c ) {
            if ( 0 !== $cur['count'] ) {
                return false;
            }
            $cur['universal'] = true;
            ++$cur['count'];
            ++$i;

            return true;
        }
        if ( '#' === $c || '.' === $c ) {
            ++$i;
            $name = self::ident( $s, $i );
            if ( null === $name ) {
                return false;
            }
            if ( '#' === $c ) {
                $cur['ids'][] = $name;
                ++$cur['spec'][0];
            } else {
                $cur['classes'][] = $name;
                ++$cur['spec'][1];
            }
            ++$cur['count'];

            return true;
        }
        if ( '[' === $c ) {
            $e = self::scanTo( $s, $i + 1, ']' );
            if ( $e >= $n || '' === trim( substr( $s, $i + 1, $e - $i - 1 ) ) ) {
                return false;
            }
            ++$cur['attrs'];
            ++$cur['spec'][1];
            ++$cur['count'];
            $i = $e + 1;

            return true;
        }
        if ( '&' === $c ) {
            if ( ! $nested ) {
                return false;
            }
            $cur['amp'] = true;
            ++$cur['spec'][1];
            ++$cur['count'];
            ++$i;

            return true;
        }
        if ( ':' === $c ) {
            return self::pseudo( $s, $i, $cur, $depth );
        }
        if ( 0 !== $cur['count'] ) {
            return false;
        }
        $name = self::ident( $s, $i );
        if ( null === $name ) {
            return false;
        }
        $cur['tag'] = strtolower( $name );
        ++$cur['spec'][2];
        ++$cur['count'];

        return true;
    }

    /** @param array<string,mixed> $cur */
    private static function pseudo( string $s, int &$i, array &$cur, int $depth ): bool
    {
        $n      = strlen( $s );
        $double = $i + 1 < $n && ':' === $s[ $i + 1 ];
        $i     += $double ? 2 : 1;
        $name   = self::ident( $s, $i );
        if ( null === $name ) {
            return false;
        }
        $name = strtolower( $name );
        $args = null;
        if ( $i < $n && '(' === $s[ $i ] ) {
            $e = self::scanTo( $s, $i + 1, ')' );
            if ( $e >= $n ) {
                return false;
            }
            $args = substr( $s, $i + 1, $e - $i - 1 );
            $i    = $e + 1;
        }
        $vendor = '-' === $name[0];
        if ( $vendor ) {
            $cur['vendor'] = true;
        }
        ++$cur['count'];
        if ( $double || in_array( $name, self::LEGACY_ELEMENTS, true ) ) {
            $cur['pseudo_element'] = true;
            ++$cur['spec'][2];

            return true;
        }
        if ( ! $vendor && ! in_array( $name, self::PSEUDO_CLASSES, true ) ) {
            return false;
        }
        if ( in_array( $name, self::USER_ACTION, true ) ) {
            ++$cur['user'];
        } else {
            ++$cur['nonuser'];
        }
        if ( null !== $args && in_array( $name, [ 'is', 'not', 'has', 'matches', 'where' ], true ) ) {
            if ( 'where' === $name ) {
                return true;
            }
            $best = [ 0, 0, 0 ];
            foreach ( self::splitTop( $args, ',' ) as $arg ) {
                $p = ( $depth >= 2 || '' === trim( $arg ) ) ? null : self::parseSelector( $arg, true, $depth + 1 );
                if ( null === $p ) {
                    if ( 'is' === $name || 'matches' === $name || '' === trim( $arg ) ) {
                        if ( $depth >= 2 ) {
                            $best = self::maxSpec( $best, [ 0, 1, 0 ] );
                        }
                        continue;
                    }
                    if ( $depth >= 2 ) {
                        $best = self::maxSpec( $best, [ 0, 1, 0 ] );
                        continue;
                    }

                    return false;
                }
                $best = self::maxSpec( $best, $p['spec'] );
            }
            $cur['spec'][0] += $best[0];
            $cur['spec'][1] += $best[1];
            $cur['spec'][2] += $best[2];

            return true;
        }
        ++$cur['spec'][1];

        return true;
    }

    /** @param list<int> $a @param list<int> $b @return list<int> */
    private static function maxSpec( array $a, array $b ): array
    {
        for ( $k = 0; $k < 3; $k++ ) {
            if ( $a[ $k ] !== $b[ $k ] ) {
                return $a[ $k ] > $b[ $k ] ? $a : $b;
            }
        }

        return $a;
    }

    /** A CSS identifier at $i (escapes decoded), or null when none starts there. */
    private static function ident( string $s, int &$i ): ?string
    {
        $n = strlen( $s );
        if ( $i >= $n ) {
            return null;
        }
        $c0 = $s[ $i ];
        if ( '-' === $c0 ) {
            $c1 = $i + 1 < $n ? $s[ $i + 1 ] : '';
            if ( '' === $c1 || ! ( ctype_alpha( $c1 ) || '_' === $c1 || '-' === $c1 || '\\' === $c1 || ord( $c1 ) >= 128 ) ) {
                return null;
            }
        } elseif ( ! ( ctype_alpha( $c0 ) || '_' === $c0 || '\\' === $c0 || ord( $c0 ) >= 128 ) ) {
            return null;
        }
        $out = '';
        while ( $i < $n ) {
            $c = $s[ $i ];
            if ( '\\' === $c ) {
                if ( $i + 1 >= $n ) {
                    return null;
                }
                $d = $s[ $i + 1 ];
                if ( ctype_xdigit( $d ) ) {
                    $j = $i + 1;
                    $h = '';
                    while ( $j < $n && strlen( $h ) < 6 && ctype_xdigit( $s[ $j ] ) ) {
                        $h .= $s[ $j ];
                        ++$j;
                    }
                    if ( $j < $n && false !== strpos( " \t\n\r\f", $s[ $j ] ) ) {
                        if ( "\r" === $s[ $j ] && $j + 1 < $n && "\n" === $s[ $j + 1 ] ) {
                            ++$j;
                        }
                        ++$j;
                    }
                    $out .= self::utf8( (int) hexdec( $h ) );
                    $i    = $j;
                } elseif ( "\n" === $d || "\r" === $d || "\f" === $d ) {
                    return null;
                } else {
                    $out .= $d;
                    $i   += 2;
                }
            } elseif ( ctype_alnum( $c ) || '_' === $c || '-' === $c || ord( $c ) >= 128 ) {
                $out .= $c;
                ++$i;
            } else {
                break;
            }
            if ( strlen( $out ) > self::MAX_SELECTOR ) {
                return null;
            }
        }

        return $out;
    }

    private static function utf8( int $cp ): string
    {
        if ( 0 === $cp || $cp > 0x10FFFF || ( $cp >= 0xD800 && $cp <= 0xDFFF ) ) {
            return "\xEF\xBF\xBD";
        }
        if ( $cp < 0x80 ) {
            return chr( $cp );
        }
        if ( $cp < 0x800 ) {
            return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
        }
        if ( $cp < 0x10000 ) {
            return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
        }

        return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
    }

    // ---------------------------------------------------------------
    // Declarations
    // ---------------------------------------------------------------

    /** @param array<string,int> $ignored @return list<array{property:string, value:?string, important:bool, poison:?string}> */
    private static function declaration( string $text, array &$ignored ): array
    {
        $text  = trim( $text );
        $colon = self::scanTo( $text, 0, ':' );
        if ( $colon >= strlen( $text ) ) {
            self::bump( $ignored, 'property' );

            return [];
        }
        $name  = trim( substr( $text, 0, $colon ) );
        $value = trim( substr( $text, $colon + 1 ) );
        if ( 1 !== preg_match( '/^-{0,2}[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $name ) || str_starts_with( $name, '--' ) ) {
            self::bump( $ignored, 'property' );

            return [];
        }
        $name = strtolower( $name );
        if ( strlen( $value ) > self::MAX_VALUE ) {
            $props = self::propertiesOf( $name );
            if ( array() === $props ) {
                self::bump( $ignored, 'property' );
            }

            return self::poisons( $props, 'unreadable', true );
        }
        $important = false;
        if ( 'important' === strtolower( substr( $value, -9 ) ) ) {
            $before = rtrim( substr( $value, 0, -9 ) );
            if ( '' !== $before && '!' === substr( $before, -1 ) ) {
                $important = true;
                $value     = rtrim( substr( $before, 0, -1 ) );
            }
        }
        if ( '' === $value ) {
            self::bump( $ignored, 'property' );

            return [];
        }
        $lv = strtolower( $value );

        // Logical and vendor spellings of a read property: poison it, whatever the value.
        $logical = self::AFFECTS[ $name ] ?? null;
        if ( null === $logical && 1 === preg_match( '/^-(?:webkit|moz)-(?:border-[a-z-]*radius|border-radius-[a-z-]+)$/', $name ) ) {
            $logical = self::CORNERS;
        }
        if ( null === $logical && 1 === preg_match( '/^-(?:webkit|moz)-padding/', $name ) ) {
            $logical = self::SIDES;
        }
        if ( null !== $logical ) {
            return self::poisons( $logical, 'property', $important );
        }

        switch ( $name ) {
            case 'all':
                return self::poisons( self::READ, 'unresolved', $important );
            case 'font':
                return self::poisons( [ 'font-size', 'font-weight', 'font-family' ], 'shorthand', $important );
            case 'opacity':
                return in_array( $lv, [ '1', '1.0', '1.00', '100%' ], true ) ? [] : [ self::entry( 'color', null, $important, 'effect' ) ];
            case 'filter':
                return 'none' === $lv ? [] : [ self::entry( 'color', null, $important, 'effect' ) ];
            case 'mix-blend-mode':
                return 'normal' === $lv ? [] : [ self::entry( 'color', null, $important, 'effect' ) ];
            case '-webkit-text-fill-color':
                return 'currentcolor' === $lv ? [] : [ self::entry( 'color', null, $important, 'effect' ) ];
            case 'background-clip':
            case '-webkit-background-clip':
                return false === strpos( $lv, 'text' ) ? [] : self::poisons( [ 'color', 'background-color' ], 'effect', $important );
            case 'background-image':
                if ( 'none' === $lv || 'initial' === $lv ) {
                    return [];
                }

                return self::poisons( [ 'background-color' ], self::substitution( $lv ) ? 'function' : 'image_or_gradient', $important );
        }

        $props = self::propertiesOf( $name );
        if ( array() === $props ) {
            self::bump( $ignored, 'property' );

            return [];
        }
        if ( in_array( $lv, self::WIDE, true ) ) {
            if ( 'inherit' === $lv && 1 === count( $props ) && in_array( $props[0], self::INHERITED, true ) ) {
                return [ self::entry( $props[0], 'inherit', $important, null ) ];
            }

            return self::poisons( $props, 'keyword', $important );
        }
        if ( self::substitution( $lv ) ) {
            return self::poisons( $props, 'function', $important );
        }

        switch ( $name ) {
            case 'color':
                return [ self::colourEntry( 'color', $value, $important, false ) ];
            case 'background-color':
                return [ self::colourEntry( 'background-color', $value, $important, true ) ];
            case 'background':
                $b = self::background( $value );
                if ( 'colour' === $b['kind'] ) {
                    return [ self::entry( 'background-color', $b['hex'], $important, null ) ];
                }

                return [ 'none' === $b['kind'] ? self::entry( 'background-color', 'none', $important, null ) : self::entry( 'background-color', null, $important, $b['reason'] ) ];
            case 'font-family':
                $fams = self::parseFamilies( $value );
                if ( null === $fams ) {
                    return self::poisons( $props, 1 === preg_match( '/[A-Za-z_-]\(/', $value ) ? 'function' : 'unreadable', $important );
                }

                return [ self::entry( 'font-family', implode( ', ', $fams ), $important, null ) ];
            case 'font-size':
                return [ self::lengthEntry( 'font-size', $value, true, $important ) ];
            case 'font-weight':
                $w = self::weight( $value );
                if ( null !== $w ) {
                    return [ self::entry( 'font-weight', (string) $w, $important, null ) ];
                }
                $reason = in_array( $lv, [ 'bolder', 'lighter' ], true ) ? 'keyword' : ( false !== strpos( $value, '(' ) ? 'function' : 'unit' );

                return self::poisons( $props, $reason, $important );
            case 'text-align':
                $a = self::align( $value );
                if ( null !== $a ) {
                    return [ self::entry( 'text-align', $a, $important, null ) ];
                }

                return self::poisons( $props, false !== strpos( $value, '(' ) ? 'function' : 'keyword', $important );
            case 'max-width':
                if ( 'none' === $lv ) {
                    return [ self::entry( 'max-width', 'none', $important, null ) ];
                }

                return [ self::lengthEntry( 'max-width', $value, false, $important ) ];
            case 'padding':
                return self::boxEntries( $value, $important, self::SIDES );
            case 'border-radius':
                if ( count( self::splitTop( $value, '/' ) ) > 1 ) {
                    return self::poisons( self::CORNERS, 'shorthand', $important );
                }

                return self::boxEntries( $value, $important, self::CORNERS );
            default:
                // padding-top ... and the corner longhands: exactly one length.
                return [ self::lengthEntry( $props[0], $value, false, $important ) ];
        }
    }

    /** The read properties a property name sets (longhands expanded); [] when it sets none. @return list<string> */
    private static function propertiesOf( string $name ): array
    {
        switch ( $name ) {
            case 'padding':
                return self::SIDES;
            case 'border-radius':
                return self::CORNERS;
            case 'background':
            case 'background-image':
                return [ 'background-color' ];
            case 'font':
                return [ 'font-size', 'font-weight', 'font-family' ];
            case 'all':
                return self::READ;
        }
        if ( in_array( $name, self::READ, true ) ) {
            return [ $name ];
        }

        return self::AFFECTS[ $name ] ?? self::EFFECTS[ $name ] ?? [];
    }

    /** @param list<string> $props @return list<array{property:string, value:?string, important:bool, poison:?string}> */
    private static function poisons( array $props, string $reason, bool $important ): array
    {
        $out = [];
        foreach ( $props as $p ) {
            $out[] = self::entry( $p, null, $important, $reason );
        }

        return $out;
    }

    /** @return array{property:string, value:?string, important:bool, poison:?string} */
    private static function entry( string $property, ?string $value, bool $important, ?string $poison ): array
    {
        return [ 'property' => $property, 'value' => $value, 'important' => $important, 'poison' => $poison ];
    }

    /** @return array{property:string, value:?string, important:bool, poison:?string} */
    private static function colourEntry( string $property, string $value, bool $important, bool $background ): array
    {
        if ( $background && 'transparent' === strtolower( trim( $value ) ) ) {
            return self::entry( $property, 'none', $important, null );
        }
        $c = self::colour( $value );

        return 'colour' === $c['kind'] ? self::entry( $property, $c['hex'], $important, null ) : self::entry( $property, null, $important, $c['reason'] ?? 'colour_form' );
    }

    /** @return array{property:string, value:?string, important:bool, poison:?string} */
    private static function lengthEntry( string $property, string $value, bool $allowPercent, bool $important ): array
    {
        $token = self::lengthToken( trim( $value ), $allowPercent );
        if ( null !== $token ) {
            return self::entry( $property, $token, $important, null );
        }

        return self::entry( $property, null, $important, false !== strpos( $value, '(' ) ? 'function' : 'unit' );
    }

    /**
     * 1 to 4 lengths expanded over four properties (top/right/bottom/left or the four corners).
     *
     * @param list<string> $props
     * @return list<array{property:string, value:?string, important:bool, poison:?string}>
     */
    private static function boxEntries( string $value, bool $important, array $props ): array
    {
        $words = self::words( $value );
        $count = count( $words );
        if ( $count < 1 || $count > 4 ) {
            return self::poisons( $props, 'unreadable', $important );
        }
        $map = [ 1 => [ 0, 0, 0, 0 ], 2 => [ 0, 1, 0, 1 ], 3 => [ 0, 1, 2, 1 ], 4 => [ 0, 1, 2, 3 ] ][ $count ];
        $out = [];
        foreach ( $props as $k => $prop ) {
            $out[] = self::lengthEntry( $prop, $words[ $map[ $k ] ], false, $important );
        }

        return $out;
    }

    /** A canonical "<number><unit>" (unit px, rem, em or %), or null: signs, exponents and other units are not read. */
    private static function lengthToken( string $v, bool $allowPercent ): ?string
    {
        if ( '' === $v || strlen( $v ) > 40 || 1 !== preg_match( '/^([+-]?)(\d*)(?:\.(\d+))?(px|rem|em|%)?$/i', $v, $m ) ) {
            return null;
        }
        $int  = ltrim( $m[2], '0' );
        $frac = rtrim( substr( $m[3] ?? '', 0, 6 ), '0' );
        if ( '' === $m[2] && '' === ( $m[3] ?? '' ) ) {
            return null;
        }
        if ( strlen( $int ) > 7 ) {
            return null;
        }
        $zero = '' === $int && '' === $frac;
        $unit = strtolower( $m[4] ?? '' );
        if ( '-' === $m[1] && ! $zero ) {
            return null;
        }
        if ( '' === $unit ) {
            return $zero ? '0px' : null;
        }
        if ( '%' === $unit && ! $allowPercent ) {
            return null;
        }

        return ( '' === $int ? '0' : $int ) . ( '' !== $frac ? '.' . $frac : '' ) . $unit;
    }

    /** @return ?list<string> null when any item cannot be read as a plain family name */
    private static function parseFamilies( string $value ): ?array
    {
        $value = trim( $value );
        if ( '' === $value || strlen( $value ) > self::MAX_VALUE ) {
            return null;
        }
        $out = [];
        foreach ( self::splitTop( $value, ',' ) as $part ) {
            $part = trim( $part );
            if ( '' === $part ) {
                return null;
            }
            if ( '"' === $part[0] || "'" === $part[0] ) {
                $inner = substr( $part, 1, -1 );
                if ( strlen( $part ) < 2 || substr( $part, -1 ) !== $part[0] || 1 !== preg_match( '/^[\p{L}\p{N}][\p{L}\p{N} _.+&-]{0,99}$/u', $inner ) ) {
                    return null;
                }
                $name = (string) preg_replace( '/ {2,}/', ' ', $inner );
            } else {
                $name = '';
                foreach ( self::words( $part ) as $word ) {
                    if ( 1 !== preg_match( '/^-?[\p{L}_][\p{L}\p{N}_-]{0,99}$/u', $word ) ) {
                        return null;
                    }
                    $name .= ( '' === $name ? '' : ' ' ) . $word;
                }
                if ( '' === $name || strlen( $name ) > 100 ) {
                    return null;
                }
            }
            $out[] = $name;
            if ( count( $out ) >= self::MAX_FAMILIES ) {
                break;
            }
        }

        return $out;
    }

    private static function substitution( string $lowerValue ): bool
    {
        return false !== strpos( $lowerValue, 'var(' ) || false !== strpos( $lowerValue, 'env(' ) || false !== strpos( $lowerValue, 'attr(' );
    }

    /** @return array{kind:'colour',hex:string}|array{kind:'poison',reason:string} */
    private static function colourFunction( string $v ): array
    {
        if ( 1 !== preg_match( '/^([A-Za-z-]+)\((.*)\)$/s', $v, $m ) ) {
            return self::poisonKind( 1 === preg_match( '/^(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix)\(/i', $v ) ? 'colour_form' : 'function' );
        }
        $fn    = strtolower( $m[1] );
        $inner = trim( $m[2] );
        if ( 'rgba' === $fn || 'hsla' === $fn ) {
            return self::poisonKind( 'alpha' );
        }
        if ( 'rgb' === $fn ) {
            $int = '(\d{1,3})';
            if ( 1 === preg_match( '/^' . $int . '\s*,\s*' . $int . '\s*,\s*' . $int . '$/', $inner, $c ) || 1 === preg_match( '/^' . $int . '\s+' . $int . '\s+' . $int . '$/', $inner, $c ) ) {
                if ( (int) $c[1] > 255 || (int) $c[2] > 255 || (int) $c[3] > 255 ) {
                    return self::poisonKind( 'colour_form' );
                }

                return [ 'kind' => 'colour', 'hex' => sprintf( '#%02x%02x%02x', (int) $c[1], (int) $c[2], (int) $c[3] ) ];
            }
            if ( 1 === preg_match( '/^' . $int . '\s*,\s*' . $int . '\s*,\s*' . $int . '\s*,\s*[\d.]+%?$/', $inner, $c )
                || 1 === preg_match( '/^' . $int . '\s+' . $int . '\s+' . $int . '\s*\/\s*[\d.]+%?$/', $inner, $c ) ) {
                return self::poisonKind( (int) $c[1] > 255 || (int) $c[2] > 255 || (int) $c[3] > 255 ? 'colour_form' : 'alpha' );
            }

            return self::poisonKind( 'colour_form' );
        }
        if ( in_array( $fn, [ 'hsl', 'hwb', 'lab', 'lch', 'oklab', 'oklch', 'color', 'color-mix' ], true ) ) {
            return self::poisonKind( 'colour_form' );
        }

        return self::poisonKind( 'function' );
    }

    /** @return array{kind:'poison',reason:string} */
    private static function poisonKind( string $reason ): array
    {
        return [ 'kind' => 'poison', 'reason' => $reason ];
    }

    /** @param array<string,int> $ignored */
    private static function bump( array &$ignored, string $reason, int $n = 1 ): void
    {
        if ( $n > 0 && in_array( $reason, self::REASONS, true ) ) {
            $ignored[ $reason ] = ( $ignored[ $reason ] ?? 0 ) + $n;
        }
    }
}
