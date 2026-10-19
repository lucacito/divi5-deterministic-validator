<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\Colour;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/Support/Colour.php';

/**
 * The descendant ink walk (spec 2026-10-19-card-adapter-design.md section 3, point 2): for every recipe block that carries
 * a card or panel marker, the card's fill is the one the adapter writes for it (KitAdapters::patch() with the marker's
 * band and ground, read from the RESOLVED patch: `bg` on a `surface` band, else `surface`; `text` on an `inverted` ground,
 * `accent` on an `accent` ground; a marker the adapter refuses is a problem), and every colour token on a descendant must be readable on the ground it sits on, in all ten languages:
 * text and heading font colours (and colours inside innerContent) at least 4.5:1, icon, divider and graphic colours at
 * least 3.0:1. This is what catches a recipe whose children assume the wrong ground.
 *
 * Rules of the walk (fail closed: what it cannot judge is a problem, never a silent pass):
 * - A descendant `divi/button` is skipped (the kit gives it its own fill; KitAdaptersTest proves it), so are `button.*`
 *   groups inside other modules (a form's or slider's button: its own fill).
 * - A descendant's own `module.decoration.background` colour token becomes the ground of that descendant and its subtree
 *   (an alpha token composited over the ground under it). A gradient, image or literal background there cannot be judged
 *   and is reported. A nested marked card is walked as its own card and skipped in its parent's walk.
 * - A module that paints an element ground of its own (an element `background` other than `module`, e.g. a toggle head or
 *   a form field) has its font and icon colours NOT judged here: they sit on that element ground, which this walk does not
 *   pair with them (a known limit, stated in the Task 4 report).
 * - Borders and box shadows are decorative and not judged (the card frame is KitCardAdapterTest's proof). A divider in the
 *   `border` role is the same decorative hairline the frame proof exempts (spec section 3, point 3) and is not judged;
 *   a divider in any other colour carries meaning and needs 3.0:1. Shipped recipes use `border` dividers as separators
 *   inside cards (hero-spec-card: about 1.3:1), so judging them would refuse that recipe's migration for a rule line.
 * - Any other colour-token path is unclassified and reported.
 *
 * Where the walk fails OPEN (what it does not see; a passing walk is not a proof of these):
 * - A module with any element background (anything but `module`) is skipped entirely: none of its font or icon colours is
 *   judged, not only the ones on that element.
 * - Only `{{aied:color:*}}` tokens are judged. A literal hex or rgba colour is never seen (another test bans literal colours
 *   in tokenised recipes, but the walk itself would pass it).
 * - A colour a text inherits from OUTSIDE the card is not MODELLED, but the hole is guarded: walkRecipe fails when any
 *   ancestor of a marked card (section, row, column, group above it) carries a font colour token, saying the walk cannot
 *   model inherited colour. Only colours written on a descendant are judged.
 * - The marked block's own non-marker attributes (a card that carries a font colour or an icon of its own) are not walked;
 *   only its descendants are.
 * - An unresolvable background token becomes an empty ground instead of "cannot judge" (known, deferred).
 */
class CardInkWalkTest extends TestCase
{
    private const FILE = __DIR__ . '/../wp-plugin/data/section-recipes.json';

    /** Any divi block comment: (close slash)(name)(json)(self-closing slash). */
    private const COMMENT = '/<!--\s+(\/)?wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+(\/)?-->/s';

    private const TOKEN = '/\{\{aied:color:[a-z-]+(?::[1-9][0-9]?)?\}\}/';

    private const CARD_ROLES = [ 'card', 'panel' ];

    /**
     * The block tree of $markup: nodes {name, attrs, children}.
     *
     * @return list<array{name:string,attrs:array<string,mixed>,children:list<mixed>}>
     */
    private static function tree( string $markup ): array
    {
        preg_match_all( self::COMMENT, $markup, $all, PREG_SET_ORDER );
        $root  = [ 'name' => '', 'attrs' => [], 'children' => [] ];
        $stack = [ &$root ];
        foreach ( $all as $m ) {
            $parent = &$stack[ count( $stack ) - 1 ];
            if ( '/' === ( $m[1] ?? '' ) ) {
                if ( count( $stack ) > 1 ) {
                    array_pop( $stack );
                }
                unset( $parent );
                continue;
            }
            $attrs = isset( $m[3] ) && '' !== $m[3] ? json_decode( $m[3], true ) : [];
            $node  = [ 'name' => $m[2], 'attrs' => is_array( $attrs ) ? $attrs : [], 'children' => [] ];
            $parent['children'][] = $node;
            if ( '/' !== ( $m[4] ?? '' ) ) {
                $stack[] = &$parent['children'][ count( $parent['children'] ) - 1 ];
            }
            unset( $parent );
        }
        return $root['children'];
    }

    /** @return array{0:string,1:string,2:mixed}|null [role, on, ground (null when absent, else as written)] of a card marker on $node */
    private static function marker( array $node ): ?array
    {
        $m = $node['attrs']['aied'] ?? null;
        if ( ! is_array( $m ) || ! in_array( $m['kit'] ?? null, self::CARD_ROLES, true ) ) {
            return null;
        }
        return [ (string) $m['kit'], (string) ( $m['on'] ?? 'light' ), $m['ground'] ?? null ];
    }

    /**
     * The card's fill as an opaque hex in $lang: the background colour token of the adapter's RESOLVED patch for this
     * marker (with the language's own kit; the fill does not depend on the kit), composited over the language's bg. Null
     * when the adapter has no patch for the marker or the patch has no single colour fill.
     *
     * @param array<string,mixed> $lang
     */
    private static function resolvedFill( string $block, string $role, string $on, mixed $ground, array $lang ): ?string
    {
        $kit = StyleKits::get( (string) ( $lang['kit'] ?? '' ) );
        if ( ! is_array( $kit ) || ( null !== $ground && ! is_string( $ground ) ) ) {
            return null;
        }
        $patch = null === $ground ? KitAdapters::patch( $block, $role, $on, $kit ) : KitAdapters::patch( $block, $role, $on, $kit, $ground );
        $token = $patch['module']['decoration']['background']['desktop']['value']['color'] ?? null;
        if ( ! is_string( $token ) ) {
            return null;
        }
        return Colour::opaque( RecipeTokens::resolve( $token, $lang ), (string) $lang['colors']['bg'] );
    }

    /** Every [path, string] leaf of $node that holds a colour token. @return list<array{0:string,1:string}> */
    private static function tokenLeaves( mixed $node, string $path = '' ): array
    {
        $out = [];
        if ( is_array( $node ) ) {
            foreach ( $node as $k => $v ) {
                foreach ( self::tokenLeaves( $v, '' === $path ? (string) $k : "$path.$k" ) as $leaf ) {
                    $out[] = $leaf;
                }
            }
        } elseif ( is_string( $node ) && 1 === preg_match( self::TOKEN, $node ) ) {
            $out[] = [ $path, $node ];
        }
        return $out;
    }

    /** 'text' (4.5), 'graphic' (3.0), 'skip', 'ground' or 'unclassified' for a colour-token path of block $name. */
    private static function kind( string $name, string $path ): string
    {
        $seg = explode( '.', $path );
        if ( 'aied' === $seg[0] || 'button' === $seg[0] || in_array( 'border', $seg, true ) || in_array( 'boxShadow', $seg, true ) ) {
            return 'skip';
        }
        if ( 'module' === $seg[0] && 'decoration' === ( $seg[1] ?? '' ) && 'background' === ( $seg[2] ?? '' ) ) {
            return 'ground';
        }
        if ( in_array( 'background', $seg, true ) ) {
            return 'element-ground';
        }
        if ( str_contains( $path, 'innerContent' ) ) {
            return 'text';
        }
        foreach ( $seg as $s ) {
            if ( 'font' === $s || str_ends_with( $s, 'Font' ) ) {
                return 'text';
            }
        }
        foreach ( $seg as $s ) {
            if ( 'icon' === $s || str_ends_with( $s, 'Icon' ) || 'arrows' === $s ) {
                return 'graphic';
            }
        }
        if ( 'divi/divider' === $name && 'divider' === $seg[0] ) {
            return 'graphic';
        }
        if ( 'circle' === $seg[0] && 'advanced' === ( $seg[1] ?? '' ) ) {
            return 'graphic';
        }
        return 'unclassified';
    }

    /** Every colour token in $value resolved and composited over $ground, as [token, opaque hex|null]. @return list<array{0:string,1:?string}> */
    private static function inks( string $value, array $lang, string $ground ): array
    {
        preg_match_all( self::TOKEN, $value, $t );
        $out = [];
        foreach ( $t[0] as $token ) {
            $out[] = [ $token, Colour::opaque( RecipeTokens::resolve( $token, $lang ), $ground ) ];
        }
        return $out;
    }

    /**
     * Problems of one card's descendants in one language ([] = fine).
     *
     * @param list<array<string,mixed>> $children
     * @param array<string,mixed>       $lang
     * @return list<string>
     */
    private static function walk( array $children, array $lang, string $ground, string $where, string $recipe ): array
    {
        $out = [];
        foreach ( $children as $child ) {
            $name = (string) $child['name'];
            if ( 'divi/button' === $name || null !== self::marker( $child ) ) {
                continue;
            }
            $leaves = self::tokenLeaves( $child['attrs'] );
            $own    = $ground;
            $bg     = $child['attrs']['module']['decoration']['background'] ?? null;
            if ( null !== $bg ) {
                $colour = $bg['desktop']['value']['color'] ?? null;
                $other  = $bg;
                unset( $other['desktop']['value']['color'] );
                $plain  = [] === array_filter( (array) ( $other['desktop']['value'] ?? [] ) ) && [ 'desktop' ] === array_keys( $bg ) && [ 'value' ] === array_keys( (array) $bg['desktop'] );
                if ( ! $plain || ! is_string( $colour ) || 1 !== preg_match( '/^\{\{aied:color:[a-z-]+(?::[1-9][0-9]?)?\}\}$/', $colour ) ) {
                    $out[] = "$where: $name has a background the walk cannot judge (only one colour token is): " . json_encode( $bg, JSON_UNESCAPED_SLASHES );
                    continue;
                }
                $own = (string) self::inks( $colour, $lang, $ground )[0][1];
            }
            $element = [] !== array_filter( $leaves, static fn ( array $l ): bool => 'element-ground' === self::kind( $name, $l[0] ) );
            foreach ( $leaves as [ $path, $value ] ) {
                $kind = self::kind( $name, $path );
                if ( in_array( $kind, [ 'skip', 'ground', 'element-ground' ], true ) || ( $element && in_array( $kind, [ 'text', 'graphic' ], true ) ) ) {
                    continue;
                }
                if ( 'graphic' === $kind && 'divi/divider' === $name && '{{aied:color:border}}' === $value ) {
                    continue;
                }
                if ( 'unclassified' === $kind ) {
                    $out[] = "$where: $name $path holds a colour the walk does not classify ($value)";
                    continue;
                }
                $need = 'text' === $kind ? 4.5 : 3.0;
                foreach ( self::inks( $value, $lang, $own ) as [ $token, $ink ] ) {
                    $r = null === $ink ? 0.0 : Colour::contrast( $ink, $own );
                    if ( $r < $need ) {
                        $out[] = sprintf( '%s: %s %s %s (%s) on %s is %.2f:1, needs %.1f', $where, $name, $path, $token, (string) $ink, $own, $r, $need );
                    }
                }
            }
            foreach ( self::walk( $child['children'], $lang, $own, $where, $recipe ) as $p ) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * Ink problems of every marked card in $markup across all ten languages, and the number of cards walked.
     *
     * @return array{0:list<string>,1:int}
     */
    public static function walkRecipe( string $recipe, string $markup ): array
    {
        $problems = [];
        $cards    = 0;
        $visit    = static function ( array $nodes, array $ancestors ) use ( &$visit, &$problems, &$cards, $recipe ): void {
            foreach ( $nodes as $node ) {
                $marker = self::marker( $node );
                if ( null !== $marker ) {
                    [ $role, $on, $ground ] = $marker;
                    $label         = null === $ground ? "$role on $on" : "$role on $on, ground " . ( is_string( $ground ) ? $ground : json_encode( $ground ) );
                    $index         = $cards++;
                    // Guard (not a model): a font colour on an ancestor is inherited by a card child that sets none, on a ground
                    // this walk does not pair it with.
                    foreach ( $ancestors as $ancestor ) {
                        foreach ( self::tokenLeaves( $ancestor['attrs'] ) as [ $path, $value ] ) {
                            if ( 'text' === self::kind( (string) $ancestor['name'], $path ) ) {
                                $problems[] = "$recipe card#$index ({$node['name']} $label): ancestor {$ancestor['name']} carries the font colour $value at $path; the walk cannot model inherited colour, so give the card children their own colours or move the colour off the ancestor";
                            }
                        }
                    }
                    foreach ( DesignLanguages::ids() as $langId ) {
                        $lang   = (array) DesignLanguages::get( $langId );
                        $fill   = self::resolvedFill( (string) $node['name'], $role, $on, $ground, $lang );
                        if ( null === $fill ) {
                            $problems[] = "$recipe card#$index ({$node['name']} $label) $langId: the adapter has no fill for this marker, so its children cannot be judged";
                            continue;
                        }
                        foreach ( self::walk( $node['children'], $lang, $fill, "$recipe card#$index ({$node['name']} $label) $langId", $recipe ) as $p ) {
                            $problems[] = $p;
                        }
                    }
                }
                $visit( $node['children'], array_merge( $ancestors, [ $node ] ) );
            }
        };
        $visit( self::tree( $markup ), [] );
        return [ $problems, $cards ];
    }

    public function testEveryMarkedCardsDescendantsAreReadableOnItsFill(): void
    {
        $problems = [];
        $walked   = 0;
        $inMarkup = 0;
        foreach ( SectionRecipes::names() as $name ) {
            $markup = (string) SectionRecipes::rawMarkup( $name );
            [ $p, $n ] = self::walkRecipe( $name, $markup );
            $problems  = array_merge( $problems, $p );
            $walked   += $n;
            $inMarkup += preg_match_all( '/"aied":\{"kit":"(?:card|panel)"/', $markup );
        }
        // Every marker in the recipe file is walked (the file stores markup in JSON strings, so quotes may be escaped).
        $inFile = preg_match_all( '/\\\\?"aied\\\\?":\{\\\\?"kit\\\\?":\\\\?"(?:card|panel)\\\\?"/', (string) file_get_contents( self::FILE ) );
        $this->assertSame( $inFile, $inMarkup, 'every card marker in the file is in a recipe' );
        $this->assertSame( $inFile, $walked, 'every card marker in the file is walked' );
        $this->assertSame( [], $problems, "card children that assume the wrong ground (fix the recipe or its marker's band):\n" . implode( "\n", $problems ) );
    }

    /** A planted recipe: a section > row > column > group card on $on whose children carry $children. */
    private static function planted( string $on, string $children, ?string $ground = null ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:bg}}"}}}}},"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/row {"builderVersion":"5.15.1"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/group {"builderVersion":"5.15.1","aied":{"kit":"card","on":"' . $on . '"' . ( null === $ground ? '' : ',"ground":"' . $ground . '"' ) . '}} -->' . $children . '<!-- /wp:divi/group -->'
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    private static function heading( string $role ): string
    {
        return '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Plan"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"{{aied:color:' . $role . '}}"}}}}}},"builderVersion":"5.15.1"} /-->';
    }

    public function testTheWalkCatchesAChildThatAssumesTheWrongGround(): void
    {
        // A heading in `inverse` (light ink, meant for a photo) on a card in a light language fails; the same card's
        // `text` heading and a button with an inverse label (it carries its own fill) do not.
        $button = '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go"}}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"{{aied:color:inverse}}"}}}}}},"builderVersion":"5.15.1","aied":{"kit":"control","on":"dark"}} /-->';
        [ $problems, $cards ] = self::walkRecipe( 'planted', self::planted( 'light', self::heading( 'text' ) . self::heading( 'inverse' ) . $button ) );
        $this->assertSame( 1, $cards );
        $this->assertNotSame( [], $problems );
        $joined = implode( "\n", $problems );
        $this->assertStringContainsString( 'planted card#0 (divi/group card on light) editorial: divi/heading title.decoration.font.font.desktop.value.color {{aied:color:inverse}}', $joined );
        $this->assertStringNotContainsString( 'glass-dark', $joined, 'inverse is readable on a dark language surface' );
        $this->assertStringNotContainsString( 'divi/button', $joined, 'a descendant button is skipped' );
        $this->assertStringNotContainsString( '{{aied:color:text}}', $joined );

        // The same text-on-surface card is clean, also on a surface band (fill bg) and with muted text.
        $this->assertSame( [], self::walkRecipe( 'planted', self::planted( 'surface', self::heading( 'text' ) . self::heading( 'muted' ) ) )[0] );

        // A descendant with its own accent fill is the ground of its subtree: accent-text on it is fine, text on it is
        // not in every language.
        $pill = static fn ( string $ink ): string => '<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:accent}}"}}}}},"builderVersion":"5.15.1"} -->'
            . self::heading( $ink ) . '<!-- /wp:divi/group -->';
        $this->assertSame( [], self::walkRecipe( 'planted', self::planted( 'light', $pill( 'accent-text' ) ) )[0] );
        $this->assertNotSame( [], self::walkRecipe( 'planted', self::planted( 'light', $pill( 'text' ) ) )[0] );

        // An icon at 3.0: the `border` hairline colour is not enough for an icon.
        $icon = '<!-- wp:divi/icon {"icon":{"advanced":{"color":{"desktop":{"value":"{{aied:color:border}}"}}}},"builderVersion":"5.15.1"} /-->';
        $this->assertStringContainsString( 'needs 3.0', implode( "\n", self::walkRecipe( 'planted', self::planted( 'light', $icon ) )[0] ) );

        // A divider: the `border` hairline is decorative (exempt), an accent rule carries meaning and is judged at 3.0
        // (playful-pastel's accent is about 2.45:1 on its surface).
        $divider = static fn ( string $role ): string => '<!-- wp:divi/divider {"divider":{"advanced":{"line":{"desktop":{"value":{"color":"{{aied:color:' . $role . '}}"}}}}},"builderVersion":"5.15.1"} /-->';
        $this->assertSame( [], self::walkRecipe( 'planted', self::planted( 'light', $divider( 'border' ) ) )[0] );
        $accentRule = implode( "\n", self::walkRecipe( 'planted', self::planted( 'light', $divider( 'accent' ) ) )[0] );
        $this->assertStringContainsString( 'playful-pastel: divi/divider divider.advanced.line.desktop.value.color {{aied:color:accent}}', $accentRule );
        $this->assertStringContainsString( 'needs 3.0', $accentRule );

        // A colour inside innerContent is text.
        $inline = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p style=\"color:{{aied:color:inverse}}\">x</p>"}}},"builderVersion":"5.15.1"} /-->';
        $this->assertStringContainsString( 'needs 4.5', implode( "\n", self::walkRecipe( 'planted', self::planted( 'light', $inline ) )[0] ) );

        // Fail closed: a gradient ground and an unclassified colour path are reported.
        $gradient = '<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"gradient":{"stops":[{"color":"{{aied:color:accent}}"}]}}}}}},"builderVersion":"5.15.1"} -->' . self::heading( 'text' ) . '<!-- /wp:divi/group -->';
        $this->assertStringContainsString( 'cannot judge', implode( "\n", self::walkRecipe( 'planted', self::planted( 'light', $gradient ) )[0] ) );
        $odd = '<!-- wp:divi/text {"content":{"advanced":{"tint":{"desktop":{"value":"{{aied:color:text}}"}}}},"builderVersion":"5.15.1"} /-->';
        $this->assertStringContainsString( 'does not classify', implode( "\n", self::walkRecipe( 'planted', self::planted( 'light', $odd ) )[0] ) );

        // A nested marked card is walked once, as its own card.
        [ $p, $n ] = self::walkRecipe( 'planted', self::planted( 'light', '<!-- wp:divi/group {"builderVersion":"5.15.1","aied":{"kit":"panel","on":"surface"}} -->' . self::heading( 'inverse' ) . '<!-- /wp:divi/group -->' ) );
        $this->assertSame( 2, $n );
        $this->assertStringContainsString( 'card#1 (divi/group panel on surface) editorial', implode( "\n", $p ) );
        $this->assertStringNotContainsString( 'card#0', implode( "\n", $p ), 'the nested card is not judged on its parent\'s fill' );
    }

    public function testTheWalkReadsTheResolvedGroundFill(): void
    {
        foreach ( [ 'light', 'surface' ] as $on ) {
            // An inverted card: bg and surface children pass, a muted (or text) child fails.
            $this->assertSame( [], self::walkRecipe( 'planted', self::planted( $on, self::heading( 'bg' ) . self::heading( 'surface' ), 'inverted' ) )[0], "inverted on $on" );
            $muted = implode( "\n", self::walkRecipe( 'planted', self::planted( $on, self::heading( 'muted' ), 'inverted' ) )[0] );
            $this->assertStringContainsString( "planted card#0 (divi/group card on $on, ground inverted) glass-dark: divi/heading", $muted );
            $this->assertStringContainsString( '{{aied:color:muted}}', $muted );
            $this->assertNotSame( [], self::walkRecipe( 'planted', self::planted( $on, self::heading( 'text' ), 'inverted' ) )[0] );
            // An accent tile: accent-text passes, a text child fails.
            $this->assertSame( [], self::walkRecipe( 'planted', self::planted( $on, self::heading( 'accent-text' ), 'accent' ) )[0], "accent on $on" );
            $text = implode( "\n", self::walkRecipe( 'planted', self::planted( $on, self::heading( 'text' ), 'accent' ) )[0] );
            $this->assertStringContainsString( "(divi/group card on $on, ground accent)", $text );
            $this->assertStringContainsString( '{{aied:color:text}}', $text );
            // The plain card judges the same children the other way round.
            $this->assertNotSame( [], self::walkRecipe( 'planted', self::planted( $on, self::heading( 'bg' ) ) )[0] );
        }
        // A marker the adapter refuses (a ground on a dark band, an unknown ground) cannot be judged: reported, never passed.
        $refused = implode( "\n", self::walkRecipe( 'planted', self::planted( 'dark', self::heading( 'bg' ), 'inverted' ) )[0] );
        $this->assertStringContainsString( 'the adapter has no fill for this marker', $refused );
        $this->assertStringContainsString( 'the adapter has no fill for this marker', implode( "\n", self::walkRecipe( 'planted', self::planted( 'light', self::heading( 'bg' ), 'x' ) )[0] ) );
    }

    public function testAnInheritedFontColourAboveACardFailsTheWalk(): void
    {
        // A dark band whose body font is `inverse`, above a card whose child sets no colour of its own: the child would
        // inherit light ink onto the card's light fill. The walk cannot model that, so it refuses the recipe.
        $dark = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:text}}"}}}}},"content":{"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"{{aied:color:inverse}}"}}}}}}},"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/row {"builderVersion":"5.15.1"} --><!-- wp:divi/column {"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/group {"builderVersion":"5.15.1","aied":{"kit":"card","on":"dark"}} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>x</p>"}}},"builderVersion":"5.15.1"} /--><!-- /wp:divi/group -->'
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
        [ $problems, $cards ] = self::walkRecipe( 'planted-dark', $dark );
        $this->assertSame( 1, $cards );
        $joined = implode( "\n", $problems );
        $this->assertStringContainsString( 'planted-dark card#0', $joined );
        $this->assertStringContainsString( 'ancestor divi/section carries the font colour {{aied:color:inverse}}', $joined );
        $this->assertStringContainsString( 'cannot model inherited colour', $joined );

        // A section colour that is only a background is not a font colour: the existing planted recipes stay clean.
        $this->assertSame( [], self::walkRecipe( 'planted', self::planted( 'light', self::heading( 'text' ) ) )[0] );
        // An ancestor group (not only the section) is checked too.
        $inner = '<!-- wp:divi/group {"content":{"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"{{aied:color:accent}}"}}}}}}},"builderVersion":"5.15.1"} --><!-- wp:divi/group {"builderVersion":"5.15.1","aied":{"kit":"panel","on":"light"}} --><!-- /wp:divi/group --><!-- /wp:divi/group -->';
        $this->assertStringContainsString( 'ancestor divi/group carries the font colour', implode( "\n", self::walkRecipe( 'planted', self::planted( 'light', $inner ) )[0] ) );
    }

    public function testTheMarkerCountSeesEscapedMarkers(): void
    {
        $json = (string) json_encode( [ 'markup' => self::planted( 'light', self::heading( 'text' ) ) ] );
        $this->assertSame( 1, preg_match_all( '/\\\\?"aied\\\\?":\{\\\\?"kit\\\\?":\\\\?"(?:card|panel)\\\\?"/', $json ) );
    }
}
