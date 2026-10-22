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
 * CONTRACT FOR THE CASCADE (HtmlStyles must follow it; "no false carry": a value is carried only if a browser would use it)
 * - Candidates for an element and a property: the declarations of every rule whose subject could match the element (a rule
 *   with poison_only = false APPLIES; a poison_only rule never applies but may match, so its entries are POISON candidates
 *   whatever their value says). Rank = (important, then specificity, then order); inline declarations rank above rules of
 *   the same importance. A rule whose specificity equals MAX_SPECIFICITY (a sentinel [PHP_INT_MAX, 0, 0]) outranks EVERY
 *   other candidate of the same importance, inline ones included: it stands for a poison whose real rank cannot be known
 *   (an unreadable selector list, a nested rule, a conditional at-rule nested in a rule, a selector whose specificity this
 *   reader cannot compute, an !important declaration inside @layer, a declaration this reader cannot decode, a cap hit). If the
 *   winner is a poison the property is unresolved: never fall back to a lower candidate. A later declaration of the same
 *   rule and property beats an earlier one of the same importance.
 * - Declaration entries are {property, value, important, poison}. Normalised values only: lowercase #rrggbb; a background as
 *   #rrggbb or "none"; lengths as canonical tokens "<number><unit>" with unit px, rem, em or % (percent only for font-size),
 *   for example "24px", "1.5rem", "0.5em", "150%" (read them back with length()); a weight "100".."900"; an alignment
 *   left|right|center|justify; "none" for a max-width; a font family list as plain names joined by ", " (split on ", ", not
 *   with families()); or "inherit". "inherit" on an inherited property (color, font-family, font-size, font-weight,
 *   text-align) is a real candidate: it wins like any value and then takes the parent's computed value. Every other entry is
 *   a POISON: value null, poison = a REASONS entry.
 * - `scale` is a third PRESENCE marker (Task 6 review I3): any transform, rotate, translate, scale, perspective, zoom, font-size-adjust or
 *   text-size-adjust (and their -webkit- spellings), whatever the value (none included), and an animation: the text and boxes of the
 *   element and its subtree may render at another size than their computed values say.
 * - Two pseudo-properties are PRESENCE poisons (MARKERS), not values. `effect` (opacity, filter and its backdrop and -webkit spellings,
 *   mix-blend-mode, -webkit-text-fill-color, background-clip: text, color-scheme, animation and animation-name, the whole mask family,
 *   text-shadow and the text stroke properties; "none" or 0 emits nothing) means colours on the element AND ITS SUBTREE may look different
 *   from their computed values: the colour and background-colour paint there are unresolved. `background-image` (an image or
 *   gradient, or the background shorthand with one) means the element's background is not a flat colour. They are resolved
 *   separately from the value of color or background-color: they never compete with those entries in the cascade, so a more
 *   specific `color` rule does not hide them.
 * - `direction` poisons text-align (reason property). Logical and vendor spellings of a read property poison it (reason
 *   property). `all` poisons everything. A var()/env()/attr() in a shorthand poisons all its longhands (reason function).
 * - ignored['css_cap'] > 0 means rules or declarations were not seen (or a list fanned out too far) because of a cap, and a covering
 *   poison has ALREADY been appended, so a caller never has to special-case it: for the byte cap and the rule cap (MAX_RULES, also
 *   counting nested rules, even empty ones) a final rule of sentinel specificity with an important poison of every property;
 *   for the per-block declaration cap the same entries after the block's declarations; for a selector list that fans out past
 *   MAX_SUBJECTS (items x parents of a nested rule, or MAX_SUBJECTS_TOTAL over the stylesheet) one universal sentinel subject.
 *   The rules array therefore holds at most MAX_RULES + 1 rules. ignored['external'] (@import) means an imported sheet that is
 *   NOT in the given text could set anything; this reader only counts it (appending a poison would drop all styling from every
 *   page that imports a web font), so the soundness of a result is relative to the text it was given.
 *
 * TASK 6 CONTRACT (short):
 * - Markers (`effect`, `background-image`): importance, specificity and order are irrelevant. Their presence in ANY rule that may
 *   match the element (poison-only rules included) counts, and a later `none` does not clear one. `background-image` on an
 *   element also makes the effective backdrop of its DESCENDANTS with a transparent background unresolved. `effect` applies to
 *   the element and its subtree; rules on html, :root and body count as ancestors of the converted fragment. With color-scheme
 *   the default canvas and initial colours are unknown: assume no white and no black.
 * - How a subject matches an element: the tag is compared lowercase (null = any tag); ids and classes must be subsets of the element's
 *   ids and classes, case-sensitive; identifiers are already escape-decoded, so compare them to the raw attribute tokens; `other` and
 *   combinators are ignored, so a match means "could match".
 * - MAX_SPECIFICITY is compared lexicographically, never added to; any sentinel winner means unresolved.
 * - Concatenate every <style> block in document order into ONE stylesheet() call (one call per block restarts `order` and the caps).
 * - external > 0 means imported or linked sheets were not read: the caller must also count <link rel=stylesheet> under the same
 *   reason and warn.
 * - HTML-level inputs this class never sees are the caller's job: the dir attribute and <bdo> (text-align), <meta name=color-scheme>,
 *   presentational attributes (bgcolor, <font color>, align).
 * - Animations: an animation or animation-name other than none is an `effect` marker (the animation rule is skipped and counted
 *   at_rule, but it may change colours even over inline styles).
 * - Counted reasons for unknowable declarations: an escaped name or an escaped !important bumps `unresolved`.
 *
 * Parsing rules: comments are removed first (strings are respected); "<!--" and "-->" are dropped; strings, parentheses,
 * square brackets and braces are respected when splitting on ";", "," and "{}" (a closer that does not match the open block is
 * an ordinary character, as in CSS); @import, @charset, @namespace statements are counted (external for @import, at_rule
 * otherwise); @font-face, the animation rule, @page, @property, @counter-style, @font-feature-values and any unknown block
 * at-rule are skipped and counted (at_rule); @media, @supports, @container, @layer, @scope, @document, @starting-style blocks:
 * the inner rules are parsed with poison_only = true and counted "media" per declaration; every rule inside @scope has the sentinel
 * specificity (scoping proximity ranks between specificity and order, so its rank is unknown), and inside @layer an important
 * declaration gets the sentinel specificity (it beats an unlayered important one); a block that is not closed at the end of the
 * text is read to the end (as a browser does) and counted "selector"; a stray "}" is part of the next prelude (as in CSS), so
 * that rule is not understood and poisons; an at-rule name with an escape could spell @media: it poisons everything (sentinel);
 * custom properties (--x) are counted "property" and not returned; nesting deeper than 8 levels poisons everything (sentinel,
 * css_cap).
 *
 * Selectors: a comma list is split per item. An item that is exactly one tag, .class or #id is SIMPLE. Everything else is
 * understood only as far as its subject (the rightmost compound: tag, ids, classes, and "other" for attribute or non-state
 * pseudo-class parts) and is poison_only. The user-action pseudo-classes (hover, focus, active, visited, focus-visible,
 * focus-within) and pseudo-elements never produce a rule (counted pseudo_state), also inside nested rules and beside
 * vendor-prefixed ones. A list in which any item cannot be understood (or is longer than MAX_SELECTOR characters) yields ONE
 * universal poison-only rule (subject tag null, other true) with the sentinel specificity carrying the list's declarations. A
 * vendor-prefixed pseudo beside a real selector makes the real items poison-only (a browser that does not know it drops the
 * whole list). Rules nested inside a style rule (CSS nesting) are poison_only with the sentinel specificity; their subject is
 * their own rightmost compound, and "&" is the parent (an "&" compound is the parent's subject plus its own parts); a nested
 * conditional at-rule is the parent's subject. Specificity is computed per selector (:where counts zero, :is/:not/:has count
 * their most specific argument); where it cannot be computed (:is/:not/:has nested more than two deep, :nth-child(... of S))
 * the specificity is the sentinel.
 *
 * Declarations: only the properties HtmlStyles reads are returned (READ plus the MARKERS); shorthands are expanded (padding to
 * 4 sides, border-radius to 4 corners, background to background-color and, when an image may be present, background-image;
 * font to three poisons) and everything else is counted "property". "initial", "unset", "revert", "revert-layer" are poison
 * keyword; "inherit" on a NON-inherited property is poison keyword. A declaration with an escaped name or an escaped
 * !important (both could be anything) and any declaration beyond MAX_DECLARATIONS are covered by a sentinel poison rule
 * (important, every property it could set); declarations() appends the same entries instead.
 *
 * Colours: #rgb and #rrggbb in any case, rgb(r,g,b) and rgb(r g b) with integers 0-255, the CSS named colours. #rgba,
 * #rrggbbaa, rgba(), hsla(), rgb() with an alpha and "transparent" are alpha (a background of "transparent" or "none" is the
 * value "none"); hsl() and the other colour functions, percentages, currentcolor and system colours are colour_form;
 * other functions are function.
 *
 * Caps (hard; none can be exceeded by any input): MAX_CSS_BYTES of input (the rest is not read, css_cap; the rule that was cut
 * may have a wrong value, so a final sentinel poison rule of every property is appended), MAX_RULES rules (the rest is not read,
 * css_cap), MAX_DECLARATIONS declarations per block, MAX_SELECTOR characters per selector, MAX_VALUE characters per value
 * (longer = poison unreadable).
 *
 * No clock, no randomness, no locale-dependent formatting, no network or filesystem access.
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

    /** Properties whose mere presence emits the `scale` marker (rendered size may differ from the computed one). */
    private const SCALES = [ 'transform', '-webkit-transform', 'rotate', 'translate', 'scale', 'perspective', '-webkit-perspective', 'zoom',
        '-webkit-zoom', 'font-size-adjust', 'text-size-adjust', '-webkit-text-size-adjust' ];

    /** Generic family keywords: a browser computes them in lower case (an unquoted one is the keyword, a quoted one a family name). */
    private const GENERIC_FAMILIES = [ 'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif',
        'ui-monospace', 'ui-rounded', 'emoji', 'math', 'fangsong' ];

    /** Pseudo-properties: presence poisons (see the contract in the class docblock). */
    public const MARKERS = [ 'effect', 'background-image', 'scale' ];

    /** Every property name a declaration entry can carry. */
    public const ALL = [ ...self::READ, ...self::MARKERS ];

    /** The sentinel specificity of a poison whose real rank cannot be known. */
    public const MAX_SPECIFICITY = [ PHP_INT_MAX, 0, 0 ];

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

    /** Selector subjects one rule may fan out to, and the total over a stylesheet (a nested list multiplies its parents). */
    private const MAX_SUBJECTS       = 2000;
    private const MAX_SUBJECTS_TOTAL = 20000;

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
        'direction'                 => [ 'text-align' ],
    ];

    /** Properties that change how colours look on an element and its subtree: they are the `effect` marker. */
    private const EFFECTS = [ 'opacity', 'filter', '-webkit-filter', 'backdrop-filter', '-webkit-backdrop-filter', 'mix-blend-mode', '-webkit-text-fill-color',
        'background-clip', '-webkit-background-clip', 'color-scheme', 'animation', 'animation-name',
        'text-shadow', 'text-stroke', '-webkit-text-stroke', '-webkit-text-stroke-width', '-webkit-text-stroke-color' ];

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
        $ctx    = [ 'rules' => [], 'ignored' => [], 'full' => false, 'limit' => self::MAX_RULES, 'visits' => 0, 'subjects' => 0, 'covered' => false, 'scope' => 0 ];
        $capped = strlen( $css ) > self::MAX_CSS_BYTES;
        if ( $capped ) {
            // The last rule may be cut inside a value (#123456 read as #123): a final all-properties poison covers it and the unseen rest.
            $css = substr( $css, 0, self::MAX_CSS_BYTES );
            self::bump( $ctx['ignored'], 'css_cap' );
        }
        self::ruleList( self::clean( $css ), $ctx, 0, false, false );
        if ( $capped ) {
            self::cover( $ctx );
        }
        ksort( $ctx['ignored'] );

        return [ 'rules' => $ctx['rules'], 'ignored' => $ctx['ignored'] ];
    }

    /**
     * One declaration block (`style=""` text or a rule body). Shorthands expanded: `padding` -> 4 sides, `border-radius` -> 4
     * corners, `background` -> `background-color` (or a poison of it), `font` -> poison of size, weight and family.
     * A rule nested inside the block (CSS nesting) cannot be represented here: every property it sets is returned as a poison
     * (reason unresolved) on this element instead. When the text is cut at MAX_CSS_BYTES, has more than MAX_DECLARATIONS
     * declarations or holds a declaration with an escaped name or an escaped !important, entries for every property it might
     * have set are appended, as poisons (reason unresolved) with important = true.
     *
     * @param array<string,int> $ignored receives the counted reasons
     * @return list<array{property:string, value:?string, important:bool, poison:?string}>  poison = the reason, value null
     */
    public static function declarations( string $block, array &$ignored ): array
    {
        $capped = strlen( $block ) > self::MAX_CSS_BYTES;
        if ( $capped ) {
            $block = substr( $block, 0, self::MAX_CSS_BYTES );
            self::bump( $ignored, 'css_cap' );
        }
        $r      = self::readBody( self::clean( $block ), $ignored );
        $out    = $r['decls'];
        $unsure = $capped ? self::ALL : $r['unsure'];
        foreach ( self::poisons( $unsure, 'unresolved', true ) as $d ) {
            $out[] = $d;
        }
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
    private static function ruleList( string $s, array &$ctx, int $depth, bool $cond, bool $layer ): void
    {
        $n = strlen( $s );
        $i = 0;
        while ( $i < $n && ! $ctx['full'] ) {
            $i += strspn( $s, " \t\n\r\f", $i );
            if ( $i >= $n ) {
                break;
            }
            if ( '@' === $s[ $i ] ) {
                $i = self::atRule( $s, $i, $ctx, $depth, $layer );
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
            self::styleRule( trim( substr( $s, $i, $open - $i ) ), substr( $s, $open + 1, $close - $open - 1 ), $ctx, $depth, $cond, $layer );
            $i = $close + 1;
        }
    }

    /** @param array<string,mixed> $ctx @return int the index after the at-rule */
    private static function atRule( string $s, int $i, array &$ctx, int $depth, bool $layer ): int
    {
        $n       = strlen( $s );
        $j       = $i + 1;
        $j      += strspn( $s, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_', $j );
        $name    = strtolower( substr( $s, $i + 1, $j - $i - 1 ) );
        $escaped = $j < $n && '\\' === $s[ $j ];
        $end     = self::scanTo( $s, $j, ';{' );
        if ( $escaped ) {
            // A name with an escape could spell @media: whatever it holds is unknown, so everything is unresolved.
            self::bump( $ctx['ignored'], 'at_rule' );
            self::emit( $ctx, self::universal(), false, self::MAX_SPECIFICITY, true, self::allUnresolved() );
            $name = '';
        }
        if ( $end >= $n || ';' === $s[ $end ] ) {
            if ( ! $escaped ) {
                self::bump( $ctx['ignored'], 'import' === $name ? 'external' : 'at_rule' );
            }

            return $end + 1;
        }
        $close = self::findClose( $s, $end );
        if ( ! in_array( $name, self::CONDITIONAL, true ) ) {
            if ( ! $escaped ) {
                self::bump( $ctx['ignored'], 'at_rule' );
            }

            return $close + 1;
        }
        if ( $depth >= self::MAX_DEPTH ) {
            self::bump( $ctx['ignored'], 'css_cap' );
            self::emit( $ctx, self::universal(), false, self::MAX_SPECIFICITY, true, self::allUnresolved() );

            return $close + 1;
        }
        if ( $close >= $n ) {
            self::bump( $ctx['ignored'], 'selector' );
        }
        // Inside @scope the scoping proximity ranks between specificity and order: every rule in it has an unknown rank (sentinel).
        $ctx['scope'] += 'scope' === $name ? 1 : 0;
        self::ruleList( substr( $s, $end + 1, $close - $end - 1 ), $ctx, $depth + 1, true, $layer || 'layer' === $name );
        $ctx['scope'] -= 'scope' === $name ? 1 : 0;

        return $close + 1;
    }

    /** @param array<string,mixed> $ctx */
    private static function styleRule( string $prelude, string $body, array &$ctx, int $depth, bool $cond, bool $layer ): void
    {
        $r = self::readBody( $body, $ctx['ignored'] );
        if ( $cond ) {
            self::bump( $ctx['ignored'], 'media', count( $r['decls'] ) );
        }
        $has = array() !== $r['decls'];
        if ( ! $has && array() === $r['unsure'] && array() === $r['nested'] ) {
            return;
        }
        $sel = self::selectors( $prelude, false, [], $ctx );
        if ( $sel['bad'] && ( $has || array() !== $r['unsure'] ) ) {
            self::bump( $ctx['ignored'], 'selector' );
        }
        $parents = [];
        $split   = $layer && $has ? self::splitImportant( $r['decls'] ) : null;
        foreach ( $sel['entries'] as $e ) {
            if ( $ctx['full'] ) {
                return;
            }
            if ( $e['state'] ) {
                if ( $has ) {
                    self::bump( $ctx['ignored'], 'pseudo_state' );
                }
                continue;
            }
            if ( array() !== $r['nested'] ) {
                $parents[ self::subjectKey( $e['subject'] ) ] = $e['subject'];
            }
            if ( $has ) {
                if ( ! $sel['bad'] && ! $e['simple'] ) {
                    self::bump( $ctx['ignored'], 'selector' );
                }
                self::emitDecls( $ctx, $e, $r['decls'], $cond, $split );
            }
            self::emitUnsure( $ctx, $e['subject'], $r['unsure'] );
        }
        foreach ( $r['nested'] as $nested ) {
            if ( $ctx['full'] ) {
                return;
            }
            self::nestedRule( $nested[0], $nested[1], $ctx, $depth + 1, array_values( $parents ), $layer );
        }
    }

    /**
     * The entries of a selector list: what each item is, as far as this reader understands it.
     *
     * state  = the item's subject has a pseudo-element or only user-action pseudo-classes (no rule).
     * force  = the item may or may not apply (a list a browser could have dropped, a nested rule): poison only.
     * An unreadable list is ONE universal entry with the sentinel specificity.
     *
     * @param list<array{tag:?string,ids:list<string>,classes:list<string>,other:bool}> $parents the subjects of the enclosing rule(s), for nested rules
     * A list that fans out too far (items, or items x parents of a nested rule, past MAX_SUBJECTS or the stylesheet's total) is ONE
     * universal entry with the sentinel specificity, counted css_cap.
     *
     * @param array<string,mixed> $ctx
     * @return array{bad: bool, entries: list<array{subject: array<string,mixed>, spec: list<int>, simple: bool, state: bool, force: bool}>}
     */
    private static function selectors( string $prelude, bool $nested, array $parents, array &$ctx ): array
    {
        if ( $nested && array() === $parents ) {
            return [ 'bad' => false, 'entries' => [] ];
        }
        $items = self::splitTop( $prelude, ',' );
        if ( count( $items ) > self::MAX_SUBJECTS || $ctx['subjects'] > self::MAX_SUBJECTS_TOTAL ) {
            return self::fanOut( $ctx );
        }
        $parsed = [];
        foreach ( $items as $item ) {
            $item = trim( $item );
            $p    = ( '' === $item || strlen( $item ) > self::MAX_SELECTOR ) ? null : self::parseSelector( $item, $nested, 0 );
            if ( null === $p ) {
                return [ 'bad' => true, 'entries' => [ self::entryOf( self::universal(), self::MAX_SPECIFICITY, false, false, true ) ] ];
            }
            $parsed[] = $p;
        }
        $vendor = false;
        $real   = false;
        foreach ( $parsed as $p ) {
            $vendor = $vendor || $p['vendor'];
            $real   = $real || ! ( $p['pseudo_element'] || $p['user_only'] );
        }
        // A browser that does not know a vendor pseudo drops the whole list: the other items may or may not apply.
        $force   = $vendor && count( $parsed ) > 1 && $real;
        $entries = [];
        foreach ( $parsed as $p ) {
            if ( $p['pseudo_element'] || $p['user_only'] ) {
                $entries[] = self::entryOf( self::universal(), [ 0, 0, 0 ], false, true, false );
                continue;
            }
            if ( ! $nested ) {
                $entries[] = self::entryOf( $p['subject'], $p['spec'], $p['simple'], false, $force );
                continue;
            }
            $ctx['subjects'] += $p['amp'] ? count( $parents ) : 1; // work done, whatever it dedupes to
            if ( $ctx['subjects'] > self::MAX_SUBJECTS_TOTAL ) {
                return self::fanOut( $ctx );
            }
            $subjects = $p['amp'] ? self::mergeParents( $p['subject'], $parents ) : [ $p['subject'] ];
            foreach ( $subjects as $sub ) {
                $entries[ self::subjectKey( $sub ) ] = self::entryOf( $sub, self::MAX_SPECIFICITY, false, false, true );
                if ( count( $entries ) > self::MAX_SUBJECTS ) {
                    return self::fanOut( $ctx );
                }
            }
        }
        if ( ! $nested ) {
            $ctx['subjects'] += count( $entries );
        }

        return [ 'bad' => false, 'entries' => array_values( $entries ) ];
    }

    /** @param array<string,mixed> $ctx @return array{bad: bool, entries: list<array<string,mixed>>} */
    private static function fanOut( array &$ctx ): array
    {
        self::bump( $ctx['ignored'], 'css_cap' );

        return [ 'bad' => true, 'entries' => [ self::entryOf( self::universal(), self::MAX_SPECIFICITY, false, false, true ) ] ];
    }

    /** A collision-free key: serialize() keeps a null tag apart from '*' and an identifier that holds a comma or a bar apart from two. @param array<string,mixed> $subject */
    private static function subjectKey( array $subject ): string
    {
        return serialize( [ $subject['tag'], $subject['ids'], $subject['classes'], $subject['other'] ] );
    }

    /** @param array<string,mixed> $subject @param list<int> $spec @return array{subject: array<string,mixed>, spec: list<int>, simple: bool, state: bool, force: bool} */
    private static function entryOf( array $subject, array $spec, bool $simple, bool $state, bool $force ): array
    {
        return [ 'subject' => $subject, 'spec' => $spec, 'simple' => $simple, 'state' => $state, 'force' => $force ];
    }

    /**
     * An "&" compound is the enclosing element plus its own parts.
     *
     * @param array<string,mixed> $sub
     * @param list<array<string,mixed>> $parents
     * @return list<array{tag:?string,ids:list<string>,classes:list<string>,other:bool}>
     */
    private static function mergeParents( array $sub, array $parents ): array
    {
        $out = [];
        foreach ( $parents as $parent ) {
            $out[] = [
                'tag'     => $sub['tag'] ?? $parent['tag'],
                'ids'     => array_values( array_unique( array_merge( $parent['ids'], $sub['ids'] ) ) ),
                'classes' => array_values( array_unique( array_merge( $parent['classes'], $sub['classes'] ) ) ),
                'other'   => $sub['other'] || $parent['other'],
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array{subject: array<string,mixed>, spec: list<int>, simple: bool, state: bool, force: bool} $e
     * @param list<array<string,mixed>> $decls
     */
    private static function emitDecls( array &$ctx, array $e, array $decls, bool $cond, ?array $split ): void
    {
        if ( null === $split ) {
            self::emit( $ctx, $e['subject'], $e['simple'], $e['spec'], $cond || ! $e['simple'] || $e['force'], $decls );

            return;
        }
        // Inside @layer an !important declaration beats an unlayered !important one whatever the specificity.
        if ( array() !== $split['normal'] ) {
            self::emit( $ctx, $e['subject'], $e['simple'], $e['spec'], true, $split['normal'] );
        }
        if ( array() !== $split['important'] ) {
            self::emit( $ctx, $e['subject'], false, self::MAX_SPECIFICITY, true, $split['important'] );
        }
    }

    /** The declarations of a rule in an @layer, split once for all of its entries. @param list<array<string,mixed>> $decls @return array{normal: list<array<string,mixed>>, important: list<array<string,mixed>>} */
    private static function splitImportant( array $decls ): array
    {
        $normal    = [];
        $important = [];
        foreach ( $decls as $d ) {
            if ( $d['important'] ) {
                $important[] = $d;
            } else {
                $normal[] = $d;
            }
        }

        return [ 'normal' => $normal, 'important' => $important ];
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $subject @param list<string> $props */
    private static function emitUnsure( array &$ctx, array $subject, array $props ): void
    {
        if ( array() !== $props ) {
            self::emit( $ctx, $subject, false, self::MAX_SPECIFICITY, true, self::poisons( $props, 'unresolved', true ) );
        }
    }

    /**
     * @param array<string,mixed> $ctx
     * @param list<array<string,mixed>> $parents the subjects of the enclosing rule(s)
     */
    private static function nestedRule( string $prelude, string $body, array &$ctx, int $depth, array $parents, bool $layer ): void
    {
        if ( $depth > self::MAX_DEPTH ) {
            self::bump( $ctx['ignored'], 'css_cap' );
            self::emit( $ctx, self::universal(), false, self::MAX_SPECIFICITY, true, self::allUnresolved() );

            return;
        }
        if ( ++$ctx['visits'] > self::MAX_RULES ) {
            self::stop( $ctx ); // nested rules count against the rule budget even when they hold nothing

            return;
        }
        $r = self::readBody( $body, $ctx['ignored'] );
        if ( array() === $r['decls'] && array() === $r['unsure'] && array() === $r['nested'] ) {
            return;
        }
        $prelude = trim( $prelude );
        $bad     = false;
        if ( '' !== $prelude && '@' === $prelude[0] ) {
            $name = 1 === preg_match( '/^@([A-Za-z-]+)/', $prelude, $m ) ? strtolower( $m[1] ) : '';
            if ( ! in_array( $name, self::CONDITIONAL, true ) ) {
                self::bump( $ctx['ignored'], 'at_rule' );

                return;
            }
            self::bump( $ctx['ignored'], 'media', count( $r['decls'] ) );
            $ctx['subjects'] += count( $parents ); // the copies are work: they count against the stylesheet's budget
            if ( $ctx['subjects'] > self::MAX_SUBJECTS_TOTAL ) {
                $entries = self::fanOut( $ctx )['entries'];
            } else {
                $entries = [];
                foreach ( $parents as $parent ) {
                    $entries[] = self::entryOf( $parent, self::MAX_SPECIFICITY, false, false, true );
                }
            }
            $layer = $layer || 'layer' === $name;
        } else {
            $sel     = self::selectors( $prelude, true, $parents, $ctx );
            $bad     = $sel['bad'];
            $entries = $sel['entries'];
            if ( $bad ) {
                self::bump( $ctx['ignored'], 'selector' );
            }
        }
        $subjects = [];
        foreach ( $entries as $e ) {
            if ( $ctx['full'] ) {
                return;
            }
            if ( $e['state'] ) {
                self::bump( $ctx['ignored'], 'pseudo_state' );
                continue;
            }
            if ( ! $bad && '@' !== ( $prelude[0] ?? '' ) ) {
                self::bump( $ctx['ignored'], 'selector' );
            }
            $subjects[ self::subjectKey( $e['subject'] ) ] = $e['subject'];
            if ( array() !== $r['decls'] ) {
                // The real rank of a nested rule includes its parent's specificity: it ranks above every normal rule.
                self::emit( $ctx, $e['subject'], false, self::MAX_SPECIFICITY, true, $r['decls'] );
            }
            self::emitUnsure( $ctx, $e['subject'], $r['unsure'] );
        }
        foreach ( $r['nested'] as $nested ) {
            if ( $ctx['full'] ) {
                return;
            }
            self::nestedRule( $nested[0], $nested[1], $ctx, $depth + 1, array_values( $subjects ), $layer );
        }
    }

    /** @param array<string,mixed> $ctx @param array{tag:?string,ids:list<string>,classes:list<string>,other:bool} $subject @param list<int> $spec @param list<array<string,mixed>> $decls */
    private static function emit( array &$ctx, array $subject, bool $simple, array $spec, bool $poison, array $decls ): void
    {
        if ( count( $ctx['rules'] ) >= $ctx['limit'] ) {
            self::stop( $ctx );

            return;
        }
        if ( $ctx['scope'] > 0 ) {
            $spec   = self::MAX_SPECIFICITY;
            $poison = true;
        }
        self::push( $ctx, $subject, $simple, $spec, $poison, $decls );
    }

    /** The rule or work budget is spent: nothing more is read, and a sentinel poison covers what was not seen. @param array<string,mixed> $ctx */
    private static function stop( array &$ctx ): void
    {
        if ( ! $ctx['full'] ) {
            $ctx['full'] = true;
            self::bump( $ctx['ignored'], 'css_cap' );
            self::cover( $ctx );
        }
    }

    /** Appends (once) the rule that poisons every property at the sentinel specificity. @param array<string,mixed> $ctx */
    private static function cover( array &$ctx ): void
    {
        if ( ! $ctx['covered'] ) {
            $ctx['covered'] = true;
            self::push( $ctx, self::universal(), false, self::MAX_SPECIFICITY, true, self::allUnresolved() );
        }
    }

    /** @param array<string,mixed> $ctx @param list<int> $spec @param list<array<string,mixed>> $decls */
    private static function push( array &$ctx, array $subject, bool $simple, array $spec, bool $poison, array $decls ): void
    {
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
        return self::poisons( self::ALL, 'unresolved', true );
    }

    /**
     * The declarations and the nested rules of a block body.
     *
     * unsure = the properties a declaration this reader could not decode (an escaped name or an escaped !important) or could not
     * read at all (more than MAX_DECLARATIONS) might have set.
     *
     * @param array<string,int> $ignored
     * @return array{decls: list<array{property:string, value:?string, important:bool, poison:?string}>, nested: list<array{0:string,1:string}>, unsure: list<string>}
     */
    private static function readBody( string $body, array &$ignored ): array
    {
        $overflow = false;
        $decls    = [];
        $nested   = [];
        $unsure   = [];
        foreach ( self::splitBody( $body, $overflow ) as $item ) {
            if ( 'decl' === $item[0] ) {
                foreach ( self::declaration( $item[1], $ignored, $unsure ) as $d ) {
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
            $unsure = self::ALL;
        }

        return [ 'decls' => $decls, 'nested' => $nested, 'unsure' => array_values( array_intersect( self::ALL, $unsure ) ) ];
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
        foreach ( self::poisons( $r['unsure'], 'unresolved', true ) as $d ) {
            $out[] = $d;
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
            $run = strcspn( $s, "\"'\\/<-", $i );
            if ( $run > 0 ) {
                $out .= substr( $s, $i, $run );
                $i   += $run;
                if ( $i >= $n ) {
                    break;
                }
            }
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
            $j += strcspn( $s, $q . "\\\n\r\f", $j );
            if ( $j >= $n ) {
                break;
            }
            $c = $s[ $j ];
            if ( '\\' === $c ) {
                $j += 2;
            } elseif ( $c === $q ) {
                return $j + 1;
            } else {
                return $j;
            }
        }

        return $n;
    }

    /*
     * The three scanners below (scanTo, findClose, splitTop) and splitBody share one loop shape on purpose: skip plain text with
     * strcspn, hand strings and escapes to stringEnd, and keep a stack of expected closers in an array with a top counter (a closer
     * that does not match the innermost open block is an ordinary character, as in CSS). They are written out instead of being
     * funnelled through a callback because this is the hot path: 100 KB of hostile input must stay in the milliseconds.
     */

    /** The index of the first of $stops outside strings and brackets, or the length. */
    private static function scanTo( string $s, int $i, string $stops ): int
    {
        $n     = strlen( $s );
        $mask  = "\"'\\()[]{}" . $stops;
        $stack = [];
        $top   = 0;
        while ( $i < $n ) {
            $i += strcspn( $s, $mask, $i );
            if ( $i >= $n ) {
                break;
            }
            $c = $s[ $i ];
            if ( '"' === $c || "'" === $c ) {
                $i = self::stringEnd( $s, $i );
                continue;
            }
            if ( '\\' === $c ) {
                $i += 2;
                continue;
            }
            if ( 0 === $top && false !== strpos( $stops, $c ) ) {
                return $i;
            }
            if ( '(' === $c ) {
                $stack[ $top++ ] = ')';
            } elseif ( '[' === $c ) {
                $stack[ $top++ ] = ']';
            } elseif ( '{' === $c ) {
                $stack[ $top++ ] = '}';
            } elseif ( $top > 0 && $stack[ $top - 1 ] === $c ) {
                --$top;
            }
            ++$i;
        }

        return $n;
    }

    /** The index of the "}" that closes the "{" at $open, or the length when it never closes. */
    private static function findClose( string $s, int $open ): int
    {
        $n     = strlen( $s );
        $stack = [ '}' ];
        $top   = 1;
        $i     = $open + 1;
        while ( $i < $n ) {
            $i += strcspn( $s, "\"'\\()[]{}", $i );
            if ( $i >= $n ) {
                break;
            }
            $c = $s[ $i ];
            if ( '"' === $c || "'" === $c ) {
                $i = self::stringEnd( $s, $i );
                continue;
            }
            if ( '\\' === $c ) {
                $i += 2;
                continue;
            }
            if ( '(' === $c ) {
                $stack[ $top++ ] = ')';
            } elseif ( '[' === $c ) {
                $stack[ $top++ ] = ']';
            } elseif ( '{' === $c ) {
                $stack[ $top++ ] = '}';
            } elseif ( $stack[ $top - 1 ] === $c && 0 === --$top ) {
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
        $mask  = "\"'\\()[]{}" . $delims;
        $stack = [];
        $top   = 0;
        $start = 0;
        $i     = 0;
        while ( $i < $n ) {
            $i += strcspn( $s, $mask, $i );
            if ( $i >= $n ) {
                break;
            }
            $c = $s[ $i ];
            if ( '"' === $c || "'" === $c ) {
                $i = self::stringEnd( $s, $i );
                continue;
            }
            if ( '\\' === $c ) {
                $i += 2;
                continue;
            }
            if ( 0 === $top && false !== strpos( $delims, $c ) ) {
                $out[] = substr( $s, $start, $i - $start );
                $start = $i + 1;
                ++$i;
                continue;
            }
            if ( '(' === $c ) {
                $stack[ $top++ ] = ')';
            } elseif ( '[' === $c ) {
                $stack[ $top++ ] = ']';
            } elseif ( '{' === $c ) {
                $stack[ $top++ ] = '}';
            } elseif ( $top > 0 && $stack[ $top - 1 ] === $c ) {
                --$top;
            }
            ++$i;
        }
        $out[] = substr( $s, min( $start, $n ) );

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
        $top    = 0;
        $decls  = 0;
        $nested = 0;
        while ( $i < $n ) {
            $i += strcspn( $body, "\"'\\()[]{};", $i );
            if ( $i >= $n ) {
                break;
            }
            $c = $body[ $i ];
            if ( '"' === $c || "'" === $c ) {
                $i = self::stringEnd( $body, $i );
                continue;
            }
            if ( '\\' === $c ) {
                $i += 2;
                continue;
            }
            if ( 0 === $top ) {
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
            if ( '(' === $c ) {
                $stack[ $top++ ] = ')';
            } elseif ( '[' === $c ) {
                $stack[ $top++ ] = ']';
            } elseif ( '{' === $c ) {
                $stack[ $top++ ] = '}';
            } elseif ( $top > 0 && $stack[ $top - 1 ] === $c ) {
                --$top;
            }
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
     * @return ?array{subject: array{tag:?string,ids:list<string>,classes:list<string>,other:bool}, spec: list<int>, unknown: bool, simple: bool,
     *                pseudo_element: bool, user_only: bool, vendor: bool, amp: bool}  null = not understood; unknown = the specificity cannot be known (spec is MAX_SPECIFICITY)
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
                    'pseudo_element' => false, 'amp' => false, 'vendor' => false, 'unknown' => false, 'count' => 0, 'spec' => [ 0, 0, 0 ] ];
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
        $spec    = [ 0, 0, 0 ];
        $vendor  = false;
        $unknown = false;
        foreach ( $compounds as $comp ) {
            $unknown  = $unknown || $comp['unknown'];
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
            'spec'           => $unknown ? self::MAX_SPECIFICITY : $spec,
            'unknown'        => $unknown,
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
                if ( '' === trim( $arg ) ) {
                    continue;
                }
                if ( $depth >= 2 ) {
                    $cur['unknown'] = true;
                    continue;
                }
                $p = self::parseSelector( $arg, true, $depth + 1 );
                if ( null === $p ) {
                    if ( 'is' === $name || 'matches' === $name ) {
                        $cur['unknown'] = true; // a forgiving list skips an item it cannot read, but a valid one may still count: the specificity is unknown

                        continue;
                    }

                    return false;
                }
                $cur['unknown'] = $cur['unknown'] || $p['unknown'];
                $best           = self::maxSpec( $best, $p['spec'] );
            }
            $cur['spec'][0] += $best[0];
            $cur['spec'][1] += $best[1];
            $cur['spec'][2] += $best[2];

            return true;
        }
        if ( null !== $args && in_array( $name, [ 'nth-child', 'nth-last-child' ], true ) && 1 === preg_match( '/(?<![A-Za-z0-9_-])of(?![A-Za-z0-9_-])/i', $args ) ) {
            $cur['unknown'] = true; // :nth-child(... of S) adds the specificity of S
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

    /**
     * @param array<string,int> $ignored
     * @param list<string> $unsure receives the properties a declaration this reader cannot decode might have set
     * @return list<array{property:string, value:?string, important:bool, poison:?string}>
     */
    private static function declaration( string $text, array &$ignored, array &$unsure ): array
    {
        $text  = trim( $text );
        $colon = self::scanTo( $text, 0, ':' );
        if ( $colon >= strlen( $text ) ) {
            self::bump( $ignored, 'property' );

            return [];
        }
        $name  = trim( substr( $text, 0, $colon ) );
        $value = trim( substr( $text, $colon + 1 ) );
        if ( false !== strpos( $name, '\\' ) ) {
            // An escape can spell any property name (col\6f r is color): it could have set anything.
            self::bump( $ignored, 'unresolved' );
            foreach ( self::ALL as $p ) {
                $unsure[] = $p;
            }

            return [];
        }
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
        $bang = strrpos( $value, '!' );
        if ( false !== $bang && false !== strpos( substr( $value, $bang + 1 ), '\\' ) && 1 !== preg_match( '/["\'()]/', substr( $value, $bang + 1 ) ) ) {
            // "!imp\ortant" is !important (escapes are decoded in identifiers): its rank is unknown.
            $props = self::propertiesOf( $name );
            if ( array() === $props ) {
                self::bump( $ignored, 'property' );
            } else {
                self::bump( $ignored, 'unresolved' );
            }
            foreach ( $props as $p ) {
                $unsure[] = $p;
            }

            return [];
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

        if ( in_array( $name, self::SCALES, true ) ) {
            // Presence, whatever the value (none included): the text and boxes may render at another size than computed (review I3).
            return [ self::entry( 'scale', null, $important, 'effect' ) ];
        }
        if ( 1 === preg_match( '/^(?:-webkit-)?mask(?:-|$)/', $name ) || in_array( $name, [ 'text-shadow', 'text-stroke', '-webkit-text-stroke', '-webkit-text-stroke-width', '-webkit-text-stroke-color' ], true ) ) {
            // A mask can hide text, a text shadow or stroke can make light text unreadable: the colour is no longer what it looks like.
            return in_array( $lv, [ 'none', '0', '0px' ], true ) ? [] : [ self::entry( 'effect', null, $important, 'effect' ) ];
        }

        switch ( $name ) {
            case 'all':
                return self::poisons( self::ALL, 'unresolved', $important );
            case 'font':
                return self::poisons( [ 'font-size', 'font-weight', 'font-family' ], 'shorthand', $important );
            case 'opacity':
                return in_array( $lv, [ '1', '1.0', '1.00', '100%' ], true ) ? [] : [ self::entry( 'effect', null, $important, 'effect' ) ];
            case 'filter':
            case '-webkit-filter':
            case 'backdrop-filter':
            case '-webkit-backdrop-filter':
                return 'none' === $lv ? [] : [ self::entry( 'effect', null, $important, 'effect' ) ];
            case 'animation':
            case 'animation-name':
                // An animation may change colours and sizes (keyframes can scale or set font-size).
                return 'none' === $lv ? [] : [ self::entry( 'effect', null, $important, 'effect' ), self::entry( 'scale', null, $important, 'effect' ) ];
            case 'mix-blend-mode':
                return 'normal' === $lv ? [] : [ self::entry( 'effect', null, $important, 'effect' ) ];
            case '-webkit-text-fill-color':
                return 'currentcolor' === $lv ? [] : [ self::entry( 'effect', null, $important, 'effect' ) ];
            case 'color-scheme':
                return 'normal' === $lv ? [] : [ self::entry( 'effect', null, $important, 'effect' ) ];
            case 'background-clip':
            case '-webkit-background-clip':
                return false === strpos( $lv, 'text' ) ? [] : [ self::entry( 'effect', null, $important, 'effect' ) ];
            case 'background-image':
                if ( 'none' === $lv || 'initial' === $lv ) {
                    return [];
                }

                return [ self::entry( 'background-image', null, $important, self::substitution( $lv ) ? 'function' : 'image_or_gradient' ) ];
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
                if ( 'none' === $b['kind'] ) {
                    return [ self::entry( 'background-color', 'none', $important, null ) ];
                }
                $out = [ self::entry( 'background-color', null, $important, $b['reason'] ) ];
                if ( ! in_array( $b['reason'], [ 'alpha', 'colour_form' ], true ) ) {
                    $out[] = self::entry( 'background-image', null, $important, $b['reason'] ); // an image may be present
                }

                return $out;
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

    /** The mask family and the EFFECTS list. */
    private static function isEffect( string $name ): bool
    {
        return in_array( $name, self::EFFECTS, true ) || 1 === preg_match( '/^(?:-webkit-)?mask(?:-|$)/', $name );
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
                return [ 'background-color', 'background-image' ];
            case 'background-image':
                return [ 'background-image' ];
            case 'font':
                return [ 'font-size', 'font-weight', 'font-family' ];
            case 'all':
                return self::ALL;
            case 'animation':
            case 'animation-name':
                return [ 'effect', 'scale' ];
        }
        if ( in_array( $name, self::SCALES, true ) ) {
            return [ 'scale' ];
        }
        if ( in_array( $name, self::READ, true ) ) {
            return [ $name ];
        }
        if ( self::isEffect( $name ) ) {
            return [ 'effect' ];
        }

        return self::AFFECTS[ $name ] ?? [];
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
                $name = $inner; // a quoted name is kept as written: "Open  Sans" is not "Open Sans" for a browser
            } else {
                $name = '';
                foreach ( self::words( $part ) as $word ) {
                    if ( 1 !== preg_match( '/^-?[\p{L}_][\p{L}\p{N}_-]{0,99}$/u', $word ) ) {
                        return null;
                    }
                    $name .= ( '' === $name ? '' : ' ' ) . $word;
                }
                if ( '' === $name || strlen( $name ) > 100 || in_array( strtolower( $name ), [ 'inherit', 'initial', 'unset', 'revert', 'revert-layer', 'default' ], true ) ) {
                    return null;
                }
                if ( in_array( strtolower( $name ), self::GENERIC_FAMILIES, true ) ) {
                    $name = strtolower( $name );
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
