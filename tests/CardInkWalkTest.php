<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use Divi5Validator\Tests\Support\Colour;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/Support/Colour.php';

/**
 * The descendant ink walk (spec 2026-10-19-card-adapter-design.md section 3, point 2): for every recipe block that carries
 * a card or panel marker, the card's fill is the one the adapter writes for its band (`bg` on a `surface` band, else
 * `surface`), and every colour token on a descendant must be readable on the ground it sits on, in all ten languages:
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

    /** @return array{0:string,1:string}|null [role, on] of a card marker on $node */
    private static function marker( array $node ): ?array
    {
        $m = $node['attrs']['aied'] ?? null;
        if ( ! is_array( $m ) || ! in_array( $m['kit'] ?? null, self::CARD_ROLES, true ) ) {
            return null;
        }
        return [ (string) $m['kit'], (string) ( $m['on'] ?? 'light' ) ];
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
        $visit    = static function ( array $nodes ) use ( &$visit, &$problems, &$cards, $recipe ): void {
            foreach ( $nodes as $node ) {
                $marker = self::marker( $node );
                if ( null !== $marker ) {
                    [ $role, $on ] = $marker;
                    $index         = $cards++;
                    foreach ( DesignLanguages::ids() as $langId ) {
                        $lang   = (array) DesignLanguages::get( $langId );
                        $colors = (array) $lang['colors'];
                        $fill   = (string) Colour::opaque( (string) $colors[ 'surface' === $on ? 'bg' : 'surface' ], (string) $colors['bg'] );
                        foreach ( self::walk( $node['children'], $lang, $fill, "$recipe card#$index ({$node['name']} $role on $on) $langId", $recipe ) as $p ) {
                            $problems[] = $p;
                        }
                    }
                }
                $visit( $node['children'] );
            }
        };
        $visit( self::tree( $markup ) );
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
    private static function planted( string $on, string $children ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:bg}}"}}}}},"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/row {"builderVersion":"5.15.1"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/group {"builderVersion":"5.15.1","aied":{"kit":"card","on":"' . $on . '"}} -->' . $children . '<!-- /wp:divi/group -->'
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

    public function testTheMarkerCountSeesEscapedMarkers(): void
    {
        $json = (string) json_encode( [ 'markup' => self::planted( 'light', self::heading( 'text' ) ) ] );
        $this->assertSame( 1, preg_match_all( '/\\\\?"aied\\\\?":\{\\\\?"kit\\\\?":\\\\?"(?:card|panel)\\\\?"/', $json ) );
    }
}
