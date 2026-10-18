<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\Colour;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';
require_once __DIR__ . '/Support/Colour.php';

/**
 * The button adapter: a kit's decisions become `divi/button` attributes (colour roles as tokens, radius and padding as
 * whole-value kit tokens), every emitted path is a registry path, the merged block passes the real gate, and no kit x
 * language x role x surface combination makes a button whose label or outline cannot be seen.
 */
class KitAdaptersTest extends TestCase
{
    private const ROLES    = [ 'control', 'control-secondary' ];
    private const SURFACES = [ 'light', 'accent', 'dark' ];

    /**
     * The `dark` surface (a button over a photo) is modelled as the language's `overlay` composited over a mid grey
     * (#808080): the photo under the scrim is unknown, mid grey is the neutral middle of what it can be.
     */
    private const PHOTO_GREY = '#808080';


    /** Token colour role => key in a language's `colors`. */
    private const ROLE_KEYS = [
        'bg' => 'bg', 'surface' => 'surface', 'text' => 'text', 'muted' => 'muted', 'accent' => 'accent',
        'accent-text' => 'accent_text', 'border' => 'border', 'inverse' => 'inverse', 'overlay' => 'overlay',
    ];

    /** @return list<array<string,mixed>> */
    private function shippedKits(): array
    {
        $kits = [];
        foreach ( StyleKits::ids() as $id ) {
            $kit = StyleKits::get( $id );
            $this->assertIsArray( $kit );
            $this->assertSame( [], StyleKits::problems( $kit ), "shipped kit $id must be valid" );
            $kits[] = $kit;
        }
        $this->assertNotSame( [], $kits );
        return $kits;
    }

    /**
     * Every shipped kit plus every fill x colour x hover variant of it (so every branch of the adapter is exercised).
     *
     * @return array<string,array<string,mixed>>
     */
    private function allKitVariants(): array
    {
        $out = [];
        foreach ( $this->shippedKits() as $kit ) {
            $out[ (string) $kit['id'] ] = $kit;
            foreach ( StyleKits::FILLS as $fill ) {
                foreach ( StyleKits::COLOR_ROLES as $color ) {
                    foreach ( StyleKits::HOVERS as $hover ) {
                        foreach ( StyleKits::BORDER_COLORS as $borderColor ) {
                            $v                    = $kit;
                            $v['fill']            = $fill;
                            $v['color']           = $color;
                            $v['hover']           = $hover;
                            $v['border']['color'] = $borderColor;
                            $this->assertSame( [], StyleKits::problems( $v ) );
                            $out[ "{$kit['id']}/$fill/$color/$hover/$borderColor" ] = $v;
                        }
                    }
                }
            }
        }
        return $out;
    }

    private function buttonBlock( string $role, string $on ): string
    {
        return '<!-- wp:divi/button {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},'
            . '"button":{"innerContent":{"desktop":{"value":{"text":"Start here"}}},'
            . '"decoration":{"font":{"font":{"desktop":{"value":{"family":"{{aied:font:body}}"}}}}}},'
            . '"builderVersion":"5.15.1","aied":{"kit":"' . $role . '","on":"' . $on . '"}} /-->';
    }

    private function page( string $module ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.15.1"} --><!-- wp:divi/row {"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1"} -->'
            . $module
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    /** @param array<string,mixed> $kit */
    private function patch( array $kit, string $role, string $on ): array
    {
        $patch = KitAdapters::patch( 'divi/button', $role, $on, $kit );
        $this->assertIsArray( $patch, "{$kit['id']} $role on $on must have a patch" );
        return $patch;
    }

    public function testEveryEmittedPathIsInTheRegistry(): void
    {
        $registry = ShapeRegistry::load();
        $missing  = [];
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::SURFACES as $on ) {
                    $patch = $this->patch( $kit, $role, $on );
                    // Exactly the four decoration groups of the button element, nothing else.
                    $this->assertSame( [ 'button' ], array_keys( $patch ) );
                    $this->assertSame( [ 'decoration' ], array_keys( $patch['button'] ) );
                    $this->assertEqualsCanonicalizing( [ 'background', 'border', 'font', 'spacing' ], array_keys( $patch['button']['decoration'] ) );
                    // The resolved block (kit tokens expanded) is what reaches the AI: lint its paths.
                    $markup = KitResolver::resolve( $this->buttonBlock( $role, $on ), $kit );
                    $this->assertSame( [], KitResolver::leftovers( $markup ), "$label $role on $on" );
                    foreach ( ShapePaths::fromMarkup( $markup ) as $path ) {
                        if ( ! $registry->covers( $path ) ) {
                            $missing[ $path ] = $label;
                        }
                    }
                    // Only the desktop breakpoint is written.
                    $json = (string) json_encode( $patch );
                    foreach ( [ 'tablet', 'phone', 'tabletWide', 'phoneWide' ] as $bp ) {
                        $this->assertStringNotContainsString( "\"$bp\"", $json );
                    }
                }
            }
        }
        $this->assertSame( [], $missing, 'the button adapter emits paths the registry does not know' );
    }

    public function testPatchValidatesInsideAButtonBlock(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::SURFACES as $on ) {
                    $markup = KitResolver::resolve( $this->buttonBlock( $role, $on ), $kit );
                    $this->assertStringNotContainsString( '"aied"', $markup, "$label $role on $on keeps its marker" );
                    $this->assertStringContainsString( '"text":"Start here"', $markup, 'innerContent is never touched' );
                    $this->assertStringContainsString( '"display":"block"', $markup, 'module is never touched' );
                    $this->assertStringContainsString( '"family":"{{aied:font:body}}"', $markup, 'an untouched recipe value survives the merge' );
                    foreach ( [ null, 'brutalist-flat' ] as $langId ) {
                        $lang     = null === $langId ? RecipeTokens::default() : DesignLanguages::get( $langId );
                        $this->assertIsArray( $lang );
                        $resolved = RecipeTokens::resolve( $markup, $lang );
                        $this->assertSame( [], RecipeTokens::found( $resolved ) );
                        $result = LayoutGate::check( $this->page( $resolved ) );
                        $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                        $this->assertTrue( $result->isValid(), "$label $role on $on must pass the gate, got: $codes" );
                    }
                }
            }
        }
    }

    public function testNoHexLiteralsAndNoUnresolvedTokensBeyondColourAndKit(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::SURFACES as $on ) {
                    $json = (string) json_encode( $this->patch( $kit, $role, $on ), JSON_UNESCAPED_SLASHES );
                    $this->assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $json, "$label $role on $on has a hex colour" );
                    preg_match_all( '/rgba?\([^)]*\)/i', $json, $m );
                    foreach ( $m[0] as $literal ) {
                        $this->assertSame( KitAdapters::TRANSPARENT, $literal, 'the only literal colour is the named transparent value' );
                    }
                    preg_match_all( '/\{\{[^}]*\}\}/', $json, $t );
                    foreach ( $t[0] as $token ) {
                        $this->assertMatchesRegularExpression(
                            '/^\{\{aied:(?:color:(?:' . implode( '|', array_map( 'preg_quote', array_keys( self::ROLE_KEYS ) ) ) . ')|kit:(?:radius-control|space-control))\}\}$/',
                            $token,
                            "$label $role on $on"
                        );
                    }
                    $this->assertStringContainsString( '"radius":"{{aied:kit:radius-control}}"', $json );
                    $this->assertStringContainsString( '"padding":"{{aied:kit:space-control}}"', $json );
                }
            }
        }
    }

    public function testUnknownCombinationsAreNull(): void
    {
        $kit = $this->shippedKits()[0];
        $this->assertNull( KitAdapters::patch( 'divi/text', 'control', 'light', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/group', 'card', 'light', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/button', 'card', 'light', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/button', 'Control', 'light', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/button', 'control', 'photo', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/button', 'control', '', $kit ) );
        $bad         = $kit;
        $bad['fill'] = 'gradient';
        $this->assertNull( KitAdapters::patch( 'divi/button', 'control', 'light', $bad ), 'an invalid kit has no patch' );
        $this->assertNull( KitAdapters::patch( 'divi/button', 'control', 'light', [] ) );
    }

    public function testOutlineWritesTheTransparentBackgroundExplicitly(): void
    {
        $outline = StyleKits::get( 'outline-2' );
        $this->assertIsArray( $outline );
        $this->assertSame( 'outline', $outline['fill'] );

        // On a photo: the transparent, inverse-outlined form today's hero-image-scrim recipe bakes.
        $p = $this->patch( $outline, 'control', 'dark' )['button']['decoration'];
        $this->assertSame( KitAdapters::TRANSPARENT, $p['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:inverse}}', $p['font']['font']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:inverse}}', $p['border']['desktop']['value']['styles']['all']['color'] );
        $this->assertSame( '2px', $p['border']['desktop']['value']['styles']['all']['width'] );
        $this->assertSame( 'solid', $p['border']['desktop']['value']['styles']['all']['style'] );

        // On a light section: transparent, label and border in the kit colour; fill-swap fills it on hover.
        $p = $this->patch( $outline, 'control', 'light' )['button']['decoration'];
        $this->assertSame( KitAdapters::TRANSPARENT, $p['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:text}}', $p['background']['desktop']['hover']['color'] );
        $this->assertSame( '{{aied:color:text}}', $p['font']['font']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:bg}}', $p['font']['font']['desktop']['hover']['color'] );
        $this->assertSame( '{{aied:color:text}}', $p['border']['desktop']['value']['styles']['all']['color'] );

        // The secondary of an outline kit is its solid treatment.
        $p = $this->patch( $outline, 'control-secondary', 'light' )['button']['decoration'];
        $this->assertSame( '{{aied:color:text}}', $p['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:bg}}', $p['font']['font']['desktop']['value']['color'] );

        // A ghost is transparent with a zero border, and the background is still written.
        $ghost         = $outline;
        $ghost['fill'] = 'ghost';
        $ghost['hover'] = 'none';
        $p = $this->patch( $ghost, 'control', 'light' )['button']['decoration'];
        $this->assertSame( KitAdapters::TRANSPARENT, $p['background']['desktop']['value']['color'] );
        $this->assertSame( KitAdapters::TRANSPARENT, $p['background']['desktop']['hover']['color'] );
        $this->assertSame( '0px', $p['border']['desktop']['value']['styles']['all']['width'] );

        // The secondary of a solid kit is its outline treatment; on a light surface in the neutral `text` colour,
        // whatever the kit's colour (the primary keeps the kit's colour).
        $solid = StyleKits::get( 'soft-solid' );
        $this->assertIsArray( $solid );
        $this->assertSame( 'accent', $solid['color'] );
        $p = $this->patch( $solid, 'control-secondary', 'light' )['button']['decoration'];
        $this->assertSame( KitAdapters::TRANSPARENT, $p['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:text}}', $p['font']['font']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:text}}', $p['border']['desktop']['value']['styles']['all']['color'] );
        $this->assertSame( '{{aied:color:accent}}', $this->patch( $solid, 'control', 'light' )['button']['decoration']['background']['desktop']['value']['color'] );
    }

    public function testSolidFollowsTheRoleTableAndTheTextDecisions(): void
    {
        $kit = StyleKits::get( 'soft-solid' );
        $this->assertIsArray( $kit );
        $light = $this->patch( $kit, 'control', 'light' )['button']['decoration'];
        $this->assertSame( '{{aied:color:accent}}', $light['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:accent-text}}', $light['font']['font']['desktop']['value']['color'] );
        $this->assertSame( $kit['border']['width'], $light['border']['desktop']['value']['styles']['all']['width'] );
        $this->assertSame( $kit['text']['weight'], $light['font']['font']['desktop']['value']['weight'] );
        $this->assertSame( $kit['text']['capitalization'], $light['font']['font']['desktop']['value']['capitalization'] );
        $this->assertSame( $kit['text']['letterSpacing'], $light['font']['font']['desktop']['value']['letterSpacing'] );
        // hover none repeats the rest values.
        $this->assertSame( $light['background']['desktop']['value']['color'], $light['background']['desktop']['hover']['color'] );
        $this->assertSame( $light['font']['font']['desktop']['value']['color'], $light['font']['font']['desktop']['hover']['color'] );

        $band = $this->patch( $kit, 'control', 'accent' )['button']['decoration'];
        $this->assertSame( '{{aied:color:surface}}', $band['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:text}}', $band['font']['font']['desktop']['value']['color'] );

        $invert          = $kit;
        $invert['hover'] = 'invert';
        $p = $this->patch( $invert, 'control', 'light' )['button']['decoration'];
        $this->assertSame( '{{aied:color:accent-text}}', $p['background']['desktop']['hover']['color'] );
        $this->assertSame( '{{aied:color:accent}}', $p['font']['font']['desktop']['hover']['color'] );
    }

    public function testInkAndFilledHoverFollowTheSurface(): void
    {
        $outline = StyleKits::get( 'outline-2' );
        $this->assertIsArray( $outline );
        $this->assertSame( 'fill-swap', $outline['hover'] );

        // Accent band: transparent ink is accent-text; the filled hover is surface with a text label.
        $p = $this->patch( $outline, 'control', 'accent' )['button']['decoration'];
        $this->assertSame( KitAdapters::TRANSPARENT, $p['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:accent-text}}', $p['font']['font']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:accent-text}}', $p['border']['desktop']['value']['styles']['all']['color'] );
        $this->assertSame( '{{aied:color:surface}}', $p['background']['desktop']['hover']['color'] );
        $this->assertSame( '{{aied:color:text}}', $p['font']['font']['desktop']['hover']['color'] );

        // Photo: inverse ink at rest, never filled with inverse on hover.
        $p = $this->patch( $outline, 'control', 'dark' )['button']['decoration'];
        $this->assertSame( '{{aied:color:surface}}', $p['background']['desktop']['hover']['color'] );
        $this->assertSame( '{{aied:color:text}}', $p['font']['font']['desktop']['hover']['color'] );

        // A filled button on a photo swaps to transparent in the photo's ink (inverse), not its fill colour.
        $p = $this->patch( $outline, 'control-secondary', 'dark' )['button']['decoration'];
        $this->assertSame( '{{aied:color:text}}', $p['background']['desktop']['value']['color'] );
        $this->assertSame( KitAdapters::TRANSPARENT, $p['background']['desktop']['hover']['color'] );
        $this->assertSame( '{{aied:color:inverse}}', $p['font']['font']['desktop']['hover']['color'] );
        $this->assertSame( '{{aied:color:inverse}}', $p['border']['desktop']['hover']['styles']['all']['color'] );

        // On an accent band the same swap uses accent-text.
        $p = $this->patch( $outline, 'control-secondary', 'accent' )['button']['decoration'];
        $this->assertSame( '{{aied:color:surface}}', $p['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:accent-text}}', $p['font']['font']['desktop']['hover']['color'] );

        // `inverse` is never a fill: a solid inverse kit is surface with a text label; on light its outline ink is text.
        $inv          = $outline;
        $inv['fill']  = 'solid';
        $inv['color'] = 'inverse';
        $p = $this->patch( $inv, 'control', 'dark' )['button']['decoration'];
        $this->assertSame( '{{aied:color:surface}}', $p['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:text}}', $p['font']['font']['desktop']['value']['color'] );
        $inv['fill'] = 'outline';
        $p = $this->patch( $inv, 'control', 'light' )['button']['decoration'];
        $this->assertSame( '{{aied:color:text}}', $p['font']['font']['desktop']['value']['color'] );
        $this->assertStringNotContainsString( '"color":"{{aied:color:inverse}}"', (string) json_encode( [ $p['background'] ] ) );
    }

    public function testThePatchIsDeterministic(): void
    {
        foreach ( $this->shippedKits() as $kit ) {
            $this->assertSame( json_encode( KitAdapters::patch( 'divi/button', 'control', 'light', $kit ) ), json_encode( KitAdapters::patch( 'divi/button', 'control', 'light', $kit ) ) );
        }
    }

    /** A colour value from a patch as a language colour (a role token resolved, a literal kept). */
    private static function colourOf( string $value, array $colors ): string
    {
        if ( 1 === preg_match( '/^\{\{aied:color:([a-z-]+)\}\}$/', $value, $m ) ) {
            return (string) ( $colors[ self::ROLE_KEYS[ $m[1] ] ] ?? '' );
        }
        return $value;
    }

    /**
     * Contrast problems of one state of one button on one opaque surface ([] = fine): the label against the fill as
     * painted (the fill composited on the surface) at least 4.5, and, where the border carries the shape (the fill is
     * transparent and the border is wider than 0), the border against the surface at least 3.0. Keyed
     * "$where|label" / "$where|outline", the value says the measured numbers.
     *
     * @return array<string,string>
     */
    private static function stateProblems( string $fill, string $label, string $border, string $width, string $surface, string $where ): array
    {
        $out     = [];
        $painted = Colour::opaque( $fill, $surface );
        $text    = null === $painted ? null : Colour::opaque( $label, $painted );
        if ( null === $painted || null === $text ) {
            return [ "$where|unreadable" => "$where: unreadable colour" ];
        }
        $ratio = Colour::contrast( $text, $painted );
        if ( $ratio < 4.5 ) {
            $out[ "$where|label" ] = sprintf( '%s: label %s on fill %s is %.2f:1, needs 4.5', $where, $text, $painted, $ratio );
        }
        $alpha = Colour::rgba( $fill );
        if ( null !== $alpha && $alpha[3] < 1.0 && '0px' !== $width ) {
            $edge = Colour::opaque( $border, $surface );
            $r    = null === $edge ? 0.0 : Colour::contrast( $edge, $surface );
            if ( $r < 3.0 ) {
                $out[ "$where|outline" ] = sprintf( '%s: outline %s on surface %s is %.2f:1, needs 3.0', $where, (string) $edge, $surface, $r );
            }
        }
        return $out;
    }

    public function testNoInvisibleButtonInAnyKitLanguageRoleSurface(): void
    {
        $problems = [];
        $checked  = 0;
        foreach ( $this->shippedKits() as $kit ) {
            foreach ( DesignLanguages::ids() as $langId ) {
                $lang   = DesignLanguages::get( $langId );
                $this->assertIsArray( $lang );
                $colors = (array) $lang['colors'];
                $surfaces = [
                    'light'  => [ 'bg' => (string) $colors['bg'], 'surface' => (string) $colors['surface'] ],
                    'accent' => [ 'accent' => (string) $colors['accent'] ],
                    'dark'   => [ 'photo' => (string) Colour::opaque( (string) $colors['overlay'], self::PHOTO_GREY ) ],
                ];
                foreach ( self::ROLES as $role ) {
                    foreach ( $surfaces as $on => $list ) {
                        $d      = $this->patch( $kit, $role, $on )['button']['decoration'];
                        $width  = (string) $d['border']['desktop']['value']['styles']['all']['width'];
                        $states = [
                            'rest'  => [ $d['background']['desktop']['value']['color'], $d['font']['font']['desktop']['value']['color'], $d['border']['desktop']['value']['styles']['all']['color'] ],
                            'hover' => [ $d['background']['desktop']['hover']['color'], $d['font']['font']['desktop']['hover']['color'], $d['border']['desktop']['hover']['styles']['all']['color'] ],
                        ];
                        foreach ( $list as $surfaceName => $surface ) {
                            foreach ( $states as $state => [ $fill, $label, $border ] ) {
                                $checked++;
                                $where    = "{$kit['id']}|$langId|$role|$on|$surfaceName|$state";
                                $problems = array_merge( $problems, self::stateProblems(
                                    self::colourOf( (string) $fill, $colors ),
                                    self::colourOf( (string) $label, $colors ),
                                    self::colourOf( (string) $border, $colors ),
                                    $width,
                                    $surface,
                                    $where
                                ) );
                            }
                        }
                    }
                }
            }
        }
        $this->assertGreaterThan( 0, $checked );
        $this->assertSame( [], array_values( $problems ), "invisible or unreadable buttons (fix the kit data or the adapter, never the bound; no exceptions):\n" . implode( "\n", $problems ) );
    }

    public function testTheContrastCheckCatchesWhatItShould(): void
    {
        // Same label and fill: unreadable.
        $this->assertNotSame( [], self::stateProblems( '#ffffff', '#ffffff', '#000000', '0px', '#000000', 'x' ) );
        // A transparent outline button whose border matches the surface: no visible shape.
        $this->assertNotSame( [], self::stateProblems( KitAdapters::TRANSPARENT, '#000000', '#ffffff', '2px', '#ffffff', 'x' ) );
        // The same with a visible border is fine; a zero-width border is not judged as a shape.
        $this->assertSame( [], self::stateProblems( KitAdapters::TRANSPARENT, '#000000', '#000000', '2px', '#ffffff', 'x' ) );
        $this->assertSame( [], self::stateProblems( KitAdapters::TRANSPARENT, '#000000', '#ffffff', '0px', '#ffffff', 'x' ) );
        // The photo surface assumption: a 70% black overlay on mid grey.
        $this->assertSame( '#262626', Colour::opaque( 'rgba(0,0,0,0.7)', self::PHOTO_GREY ) );
    }
}
