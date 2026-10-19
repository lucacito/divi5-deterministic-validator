<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\Colour;
use Divi5Validator\Tests\Support\DeadOnModule;
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
require_once __DIR__ . '/Support/DeadOnModule.php';

/**
 * The button adapter: a kit's decisions become `divi/button` attributes (colour roles as tokens, radius and padding as
 * whole-value kit tokens), every emitted path is a registry path, the merged block passes the real gate, and no kit x
 * language x role x surface combination makes a button whose label or outline cannot be seen.
 */
class KitAdaptersTest extends TestCase
{
    private const ROLES    = [ 'control', 'control-secondary' ];
    private const SURFACES = [ 'light', 'accent', 'dark' ];

    /** Every button surface: the three bands plus `panel` (a button inside an inverted, `text`-filled panel). */
    private const ALL_SURFACES = [ 'light', 'accent', 'dark', 'panel' ];

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
                            if ( 'bottom' === ( $kit['border']['sides'] ?? 'all' ) && [] !== StyleKits::problems( $v ) ) {
                                continue; // an underline allows only hover none or lift and no ghost fill (StyleKitsTest)
                            }
                            $this->assertSame( [], StyleKits::problems( $v ) );
                            $out[ "{$kit['id']}/$fill/$color/$hover/$borderColor" ] = $v;
                        }
                    }
                }
            }
            // The effects: every proven shadow pair x every hover x both border sides (where the schema allows the pair).
            foreach ( StyleKits::SHADOW_PAIRS as $pair ) {
                [ $rest, $hoverShadow ] = explode( '/', $pair );
                foreach ( StyleKits::HOVERS as $hover ) {
                    foreach ( StyleKits::BORDER_SIDES as $sides ) {
                        foreach ( [ 'solid', 'outline' ] as $fill ) {
                            $v                    = $kit;
                            $v['fill']            = $fill;
                            $v['hover']           = $hover;
                            $v['shadow']          = [ 'rest' => $rest, 'hover' => $hoverShadow ];
                            $v['border']['sides'] = $sides;
                            if ( 'bottom' === $sides && '0px' === $v['border']['width'] ) {
                                $v['border']['width'] = '2px';
                            }
                            if ( [] === StyleKits::problems( $v ) ) {
                                $out[ "{$kit['id']}/$fill/fx/$pair/$hover/$sides" ] = $v;
                            }
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
        $dead     = DeadOnModule::load();
        $this->assertNotSame( [], $dead->all(), 'the dead-on-module list must load' );
        $missing  = [];
        $killed   = [];
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::ALL_SURFACES as $on ) {
                    $patch = $this->patch( $kit, $role, $on );
                    // Exactly three decoration groups of the button element; on the module its spacing, plus a box shadow
                    // exactly when the kit has one and a transform exactly when it lifts, nothing else.
                    $this->assertSame( [ 'button', 'module' ], array_keys( $patch ) );
                    $this->assertSame( [ 'decoration' ], array_keys( $patch['button'] ) );
                    $this->assertEqualsCanonicalizing( [ 'background', 'border', 'font' ], array_keys( $patch['button']['decoration'] ) );
                    $this->assertSame( [ 'decoration' ], array_keys( $patch['module'] ) );
                    $expected = [ 'spacing' ];
                    $pair     = $kit['shadow']['rest'] . '/' . $kit['shadow']['hover'];
                    // On a panel a soft shadow (rest or hover) is not written: no ink is darker than a `text` panel in
                    // every language (KitAdapters::shadow()).
                    if ( 'none/none' !== $pair && ! ( 'panel' === $on && in_array( $pair, [ 'soft/soft', 'none/soft' ], true ) ) ) {
                        $expected[] = 'boxShadow';
                    }
                    if ( 'lift' === $kit['hover'] ) {
                        $expected[] = 'transform';
                    }
                    $this->assertSame( $expected, array_keys( $patch['module']['decoration'] ), "$label $role on $on" );
                    $this->assertSame( [ 'desktop' => [ 'value' => [ 'padding' => '{{aied:kit:space-control}}' ] ] ], $patch['module']['decoration']['spacing'] );
                    // The resolved block (kit tokens expanded) is what reaches the AI: lint its paths.
                    $markup = KitResolver::resolve( $this->buttonBlock( $role, $on ), $kit );
                    $this->assertSame( [], KitResolver::leftovers( $markup ), "$label $role on $on" );
                    foreach ( ShapePaths::fromMarkup( $markup ) as $path ) {
                        if ( ! $registry->coversLeaf( $path ) ) {
                            $missing[ $path ] = $label;
                        }
                    }
                    // The registry is path-only; a path it knows from another module can still be dead on a button.
                    foreach ( $dead->violations( $markup ) as $v ) {
                        $killed[ $v ] = $label;
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
        $this->assertSame( [], array_keys( $killed ), 'the button adapter emits paths that are dead on divi/button' );
    }

    public function testPatchValidatesInsideAButtonBlock(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::ALL_SURFACES as $on ) {
                    $markup = KitResolver::resolve( $this->buttonBlock( $role, $on ), $kit );
                    $this->assertStringNotContainsString( '"aied"', $markup, "$label $role on $on keeps its marker" );
                    $this->assertStringContainsString( '"text":"Start here"', $markup, 'innerContent is never touched' );
                    $this->assertStringContainsString( '"display":"block"', $markup, 'a recipe\'s module.decoration.layout survives the merge' );
                    $this->assertStringContainsString( '"family":"{{aied:font:body}}"', $markup, 'an untouched recipe value survives the merge' );
                    $this->assertSame( $markup, KitResolver::resolve( $markup, $kit ), "$label $role on $on: resolving twice changes nothing" );
                    foreach ( [ null, 'brutalist-flat' ] as $langId ) {
                        $lang     = null === $langId ? RecipeTokens::default() : DesignLanguages::get( $langId );
                        $this->assertIsArray( $lang );
                        $resolved = RecipeTokens::resolve( $markup, $lang );
                        $this->assertSame( [], RecipeTokens::found( $resolved ) );
                        $this->assertSame( $resolved, RecipeTokens::resolve( $resolved, $lang ) );
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
                foreach ( self::ALL_SURFACES as $on ) {
                    $json = (string) json_encode( $this->patch( $kit, $role, $on ), JSON_UNESCAPED_SLASHES );
                    $this->assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $json, "$label $role on $on has a hex colour" );
                    preg_match_all( '/rgba?\([^)]*\)/i', $json, $m );
                    foreach ( $m[0] as $literal ) {
                        $this->assertSame( KitAdapters::TRANSPARENT, $literal, 'the only literal colour is the named transparent value' );
                    }
                    preg_match_all( '/\{\{[^}]*\}\}/', $json, $t );
                    foreach ( $t[0] as $token ) {
                        // A colour role, the soft shadow's overlay at the two proven opacities, the glow's accent (accent-text on
                        // an accent band, bg on a panel) at 45%, or a kit token.
                        $this->assertMatchesRegularExpression(
                            '/^\{\{aied:(?:color:(?:' . implode( '|', array_map( 'preg_quote', array_keys( self::ROLE_KEYS ) ) ) . ')|color:overlay:(?:18|25)|color:(?:accent|accent-text' . ( 'panel' === $on ? '|bg' : '' ) . '):45|kit:(?:radius-control|space-control))\}\}$/',
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

    /** A valid copy of a shipped kit with some decisions changed. */
    private function kitWith( string $id, array $changes ): array
    {
        $kit = StyleKits::get( $id );
        $this->assertIsArray( $kit );
        $kit = array_replace_recursive( $kit, $changes );
        $this->assertSame( [], StyleKits::problems( $kit ) );
        return $kit;
    }

    public function testShadowsAreWrittenOnTheModuleInTheProbeProvenForms(): void
    {
        $soft = $this->kitWith( 'soft-solid', [ 'shadow' => [ 'rest' => 'soft', 'hover' => 'soft' ] ] );
        foreach ( self::SURFACES as $on ) {
            // kb-shadow-module-soft; the hover keeps the rest shadow (nothing written on hover).
            $this->assertSame(
                [ 'desktop' => [ 'value' => [ 'style' => 'preset1', 'vertical' => '8px', 'blur' => '24px', 'color' => '{{aied:color:overlay:18}}' ] ] ],
                $this->patch( $soft, 'control', $on )['module']['decoration']['boxShadow'],
                "soft on $on"
            );
        }

        // kb-shadow-module-hover: a soft shadow on hover only.
        $hoverOnly = $this->kitWith( 'soft-solid', [ 'shadow' => [ 'rest' => 'none', 'hover' => 'soft' ] ] );
        $this->assertSame(
            [ 'desktop' => [ 'hover' => [ 'style' => 'preset1', 'vertical' => '12px', 'blur' => '24px', 'color' => '{{aied:color:overlay:25}}' ] ] ],
            $this->patch( $hoverOnly, 'control', 'light' )['module']['decoration']['boxShadow']
        );

        // kb-shadow-module-hard: an offset solid shadow in the ink of the surface (text, accent-text on an accent band,
        // inverse on a photo), opaque.
        $hard = $this->kitWith( 'soft-solid', [ 'shadow' => [ 'rest' => 'hard', 'hover' => 'hard' ] ] );
        foreach ( [ 'light' => 'text', 'accent' => 'accent-text', 'dark' => 'inverse' ] as $on => $ink ) {
            $this->assertSame(
                [ 'desktop' => [ 'value' => [ 'style' => 'preset4', 'horizontal' => '6px', 'vertical' => '6px', 'blur' => '0px', 'color' => '{{aied:color:' . $ink . '}}' ] ] ],
                $this->patch( $hard, 'control-secondary', $on )['module']['decoration']['boxShadow'],
                "hard on $on"
            );
        }

        // kb-shadow-edge-down: a hard edge straight down (preset4, 0px across, 4px down, no blur), opaque, in the surface's ink.
        $edge = $this->kitWith( 'soft-solid', [ 'shadow' => [ 'rest' => 'edge', 'hover' => 'edge' ] ] );
        foreach ( [ 'light' => 'text', 'accent' => 'accent-text', 'dark' => 'inverse' ] as $on => $ink ) {
            foreach ( self::ROLES as $role ) {
                $this->assertSame(
                    [ 'desktop' => [ 'value' => [ 'style' => 'preset4', 'horizontal' => '0px', 'vertical' => '4px', 'blur' => '0px', 'color' => '{{aied:color:' . $ink . '}}' ] ] ],
                    $this->patch( $edge, $role, $on )['module']['decoration']['boxShadow'],
                    "edge $role on $on"
                );
            }
        }

        // kb-shadow-glow: a glow with no offset (preset1, 0px down, 16px blur) in the accent at 45%; on an accent band the
        // accent would vanish, so accent-text at 45% there.
        $glow = $this->kitWith( 'outline-2', [ 'shadow' => [ 'rest' => 'glow', 'hover' => 'glow' ] ] );
        foreach ( [ 'light' => 'accent', 'accent' => 'accent-text', 'dark' => 'accent' ] as $on => $role ) {
            $this->assertSame(
                [ 'desktop' => [ 'value' => [ 'style' => 'preset1', 'vertical' => '0px', 'blur' => '16px', 'color' => '{{aied:color:' . $role . ':45}}' ] ] ],
                $this->patch( $glow, 'control', $on )['module']['decoration']['boxShadow'],
                "glow on $on"
            );
        }

        // Never on the button element (dead there), and no shadow at all for a kit without one.
        foreach ( [ $soft, $hoverOnly, $hard, $edge, $glow ] as $kit ) {
            $this->assertArrayNotHasKey( 'boxShadow', $this->patch( $kit, 'control', 'light' )['button']['decoration'] );
        }
        $this->assertArrayNotHasKey( 'boxShadow', $this->patch( (array) StyleKits::get( 'soft-solid' ), 'control', 'light' )['module']['decoration'] );
    }

    public function testLiftWritesTheProvenModuleTransformAndKeepsTheRestColours(): void
    {
        $lift = $this->kitWith( 'soft-solid', [ 'hover' => 'lift', 'shadow' => [ 'rest' => 'none', 'hover' => 'soft' ] ] );
        foreach ( self::ROLES as $role ) {
            foreach ( self::SURFACES as $on ) {
                $patch = $this->patch( $lift, $role, $on );
                // kb-lift-hover-shadow: translate y -4px on hover together with the soft hover shadow.
                $this->assertSame( [ 'desktop' => [ 'hover' => [ 'translate' => [ 'y' => '-4px' ] ] ] ], $patch['module']['decoration']['transform'] );
                $this->assertArrayNotHasKey( 'transform', $patch['button']['decoration'] );
                $d = $patch['button']['decoration'];
                $this->assertSame( $d['background']['desktop']['value']['color'], $d['background']['desktop']['hover']['color'], "$role on $on" );
                $this->assertSame( $d['font']['font']['desktop']['value']['color'], $d['font']['font']['desktop']['hover']['color'], "$role on $on" );
                $this->assertSame( $d['border']['desktop']['value']['styles']['all']['color'], $d['border']['desktop']['hover']['styles']['all']['color'], "$role on $on" );
            }
        }
        // The colours of a lift kit are exactly those of the same kit with hover none.
        $none = $this->kitWith( 'soft-solid', [ 'shadow' => [ 'rest' => 'none', 'hover' => 'soft' ] ] );
        $this->assertSame( $this->patch( $none, 'control', 'light' )['button'], $this->patch( $lift, 'control', 'light' )['button'] );
    }

    public function testAnUnderlineWritesAZeroWidthFrameAndTheBottomSide(): void
    {
        $kit = $this->kitWith( 'outline-2', [ 'hover' => 'none', 'border' => [ 'sides' => 'bottom', 'width' => '2px', 'style' => 'solid' ] ] );
        $b   = $this->patch( $kit, 'control', 'light' )['button']['decoration']['border']['desktop'];
        // kb-border-bottom-only: styles.all.width 0px plus styles.bottom width, style and colour.
        $this->assertSame( '0px', $b['value']['styles']['all']['width'] );
        $this->assertSame( [ 'width' => '2px', 'style' => 'solid', 'color' => '{{aied:color:text}}' ], $b['value']['styles']['bottom'] );
        $this->assertSame( '{{aied:kit:radius-control}}', $b['value']['radius'] );
        $this->assertSame( KitAdapters::TRANSPARENT, $this->patch( $kit, 'control', 'light' )['button']['decoration']['background']['desktop']['value']['color'] );
        // The hover colour of the frame is the bottom side's colour (an underline never changes colour).
        $this->assertSame( '{{aied:color:text}}', $b['hover']['styles']['all']['color'] );
        $this->assertArrayNotHasKey( 'bottom', $b['hover']['styles'] );
        // On an accent band and on a photo the underline takes the surface's ink.
        $this->assertSame( '{{aied:color:accent-text}}', $this->patch( $kit, 'control', 'accent' )['button']['decoration']['border']['desktop']['value']['styles']['bottom']['color'] );
        $this->assertSame( '{{aied:color:inverse}}', $this->patch( $kit, 'control', 'dark' )['button']['decoration']['border']['desktop']['value']['styles']['bottom']['color'] );
        // A kit with sides "all" (or none given) writes no side.
        $this->assertArrayNotHasKey( 'bottom', $this->patch( $this->kitWith( 'outline-2', [ 'border' => [ 'sides' => 'all' ] ] ), 'control', 'light' )['button']['decoration']['border']['desktop']['value']['styles'] );
    }

    public function testTheUnderlineKitIsAnAccentBlockWithABottomEdgeAndAnUnderlinedSecondary(): void
    {
        $kit = StyleKits::get( 'underline-link' );
        $this->assertIsArray( $kit );
        $this->assertSame( [ 'solid', 'accent', 'text', 'bottom' ], [ $kit['fill'], $kit['color'], $kit['border']['color'], $kit['border']['sides'] ] );
        $primary = $this->patch( $kit, 'control', 'light' )['button']['decoration'];
        $this->assertSame( '{{aied:color:accent}}', $primary['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:text}}', $primary['border']['desktop']['value']['styles']['bottom']['color'], 'a visible text-colour bottom edge' );
        $secondary = $this->patch( $kit, 'control-secondary', 'light' )['button']['decoration'];
        $this->assertSame( KitAdapters::TRANSPARENT, $secondary['background']['desktop']['value']['color'], 'the secondary is the quiet one' );
        $this->assertSame( '{{aied:color:text}}', $secondary['border']['desktop']['value']['styles']['bottom']['color'] );
        $this->assertSame( '{{aied:color:text}}', $secondary['font']['font']['desktop']['value']['color'] );
    }

    public function testUnknownCombinationsAreNull(): void
    {
        $kit = $this->shippedKits()[0];
        $this->assertNull( KitAdapters::patch( 'divi/text', 'control', 'light', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/group', 'control', 'light', $kit ), 'a group answers the card roles only (KitCardAdapterTest)' );
        $this->assertNull( KitAdapters::patch( 'divi/button', 'control', 'surface', $kit ), 'surface is a card band only' );
        $this->assertNull( KitAdapters::patch( 'divi/button', 'card', 'light', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/button', 'Control', 'light', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/button', 'control', 'photo', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/button', 'control', '', $kit ) );
        // `panel` is a button surface only; a ground is a card field only.
        foreach ( [ 'divi/group', 'divi/column' ] as $carrier ) {
            foreach ( [ 'card', 'panel' ] as $role ) {
                $this->assertNull( KitAdapters::patch( $carrier, $role, 'panel', $kit ), "$carrier $role on panel" );
            }
        }
        foreach ( self::ALL_SURFACES as $on ) {
            $this->assertNull( KitAdapters::patch( 'divi/button', 'control', $on, $kit, 'inverted' ), "a ground on a button ($on)" );
        }
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

    public function testARealRecipeButtonKeepsItsModuleLayoutAndGetsModulePadding(): void
    {
        $markup = SectionRecipes::rawMarkup( 'hero-cta' );
        $this->assertIsString( $markup );
        $this->assertSame( 1, preg_match( '/<!-- wp:divi\/button (\{.*?"aied":\{"kit":"control"\}\}) \/-->/', $markup, $m ), 'hero-cta carries a control marker' );
        $before = json_decode( $m[1], true );
        $this->assertSame( [ 'layout' ], array_keys( $before['module']['decoration'] ) );

        $kit      = StyleKits::get( 'soft-solid' );
        $this->assertIsArray( $kit );
        $resolved = KitResolver::resolve( '<!-- wp:divi/button ' . $m[1] . ' /-->', $kit );
        $this->assertSame( 1, preg_match( '/^<!-- wp:divi\/button (\{.*\}) \/-->$/s', $resolved, $r ) );
        $after = json_decode( $r[1], true );
        $this->assertSame( $before['module']['decoration']['layout'], $after['module']['decoration']['layout'], 'the recipe\'s module layout is kept' );
        $this->assertSame( [ 'layout', 'spacing' ], array_keys( $after['module']['decoration'] ) );
        $this->assertSame(
            [ 'top' => $kit['space']['control']['vertical'], 'right' => $kit['space']['control']['horizontal'], 'bottom' => $kit['space']['control']['vertical'], 'left' => $kit['space']['control']['horizontal'] ],
            $after['module']['decoration']['spacing']['desktop']['value']['padding']
        );
        $this->assertArrayNotHasKey( 'spacing', $after['button']['decoration'] );
        $this->assertSame( $before['button']['innerContent'], $after['button']['innerContent'] );
        $this->assertSame( $before['modulePreset'], $after['modulePreset'] );
        $this->assertSame( [], DeadOnModule::load()->violations( $resolved ) );
    }

    public function testTheDeadOnModuleGuardCatchesWhatItShould(): void
    {
        $dead = DeadOnModule::load();
        $prefixes = array_map( static fn ( array $e ): string => $e['module'] . '|' . $e['prefix'], $dead->all() );
        foreach ( [ 'divi/button|button.decoration.spacing', 'divi/button|button.decoration.boxShadow', 'divi/button|button.decoration.transform' ] as $expected ) {
            $this->assertContains( $expected, $prefixes );
        }
        $bad = '<!-- wp:divi/button {"button":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"14px"}}}},"boxShadow":{"desktop":{"value":{"style":"preset1"}}}}}} /-->';
        $this->assertSame(
            [ 'divi/button: button.decoration.boxShadow.{bp}.value.style (dead: kb-shadow-element-hard)', 'divi/button: button.decoration.spacing.{bp}.value.padding.top (dead: kb-padding-element)' ],
            $dead->violations( "Some guide text\n```\n$bad\n```\n" )
        );
        // The same paths on another module (a slider's button group) and the module-level padding are fine.
        $this->assertSame( [], $dead->violations( str_replace( 'wp:divi/button', 'wp:divi/slide', $bad ) ) );
        $this->assertSame( [], $dead->violations( '<!-- wp:divi/button {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"14px"}}}}}}} /-->' ) );
        // A prefix does not match a longer sibling name.
        $this->assertSame( [], $dead->violations( '<!-- wp:divi/button {"button":{"decoration":{"spacingX":{"a":"b"}}}} /-->' ) );
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
                        // An underline carries the shape with its bottom side alone: judge that side.
                        $side   = $d['border']['desktop']['value']['styles']['bottom'] ?? $d['border']['desktop']['value']['styles']['all'];
                        $width  = (string) $side['width'];
                        $states = [
                            'rest'  => [ $d['background']['desktop']['value']['color'], $d['font']['font']['desktop']['value']['color'], $side['color'] ],
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

    /**
     * Problems of one state of one button on a `panel` (a `text`-filled panel, opaque over bg): the label and outline checks
     * of stateProblems() against the panel, plus the fill is never the panel's own colour (the button would vanish).
     *
     * @return array<string,string>
     */
    private static function panelProblems( string $fill, string $label, string $border, string $width, string $panel, string $where ): array
    {
        $out     = self::stateProblems( $fill, $label, $border, $width, $panel, $where );
        $painted = Colour::opaque( $fill, $panel );
        $alpha   = Colour::rgba( $fill );
        if ( null !== $painted && null !== $alpha && $alpha[3] >= 1.0 && Colour::contrast( $painted, $panel ) < 3.0 ) {
            $out[ "$where|fill" ] = sprintf( '%s: fill %s on the panel %s is %.2f:1: the button loses its shape', $where, $painted, $panel, Colour::contrast( $painted, $panel ) );
        }
        return $out;
    }

    /**
     * Box problems of one button on a `panel` ([] = fine; Ruling 2): a border wider than 0 is never painted in the panel's
     * own colour (it would melt into the panel and the box would look smaller), the hover writes no other width than the
     * rest (the painted box keeps its size), and a filled button's border is its fill colour role at rest and on hover.
     * Arguments are colour tokens or literals as the patch writes them; $colors resolves them.
     *
     * @param array<string,mixed> $colors
     * @return array<string,string>
     */
    private static function panelBoxProblems( string $restFill, string $restBorder, string $hoverFill, string $hoverBorder, string $width, ?string $hoverWidth, array $colors, string $panel, string $where ): array
    {
        $out = [];
        if ( null !== $hoverWidth && $hoverWidth !== $width ) {
            $out[ "$where|hover-width" ] = "$where: the hover border width $hoverWidth is not the rest width $width";
        }
        foreach ( [ 'rest' => [ $restFill, $restBorder ], 'hover' => [ $hoverFill, $hoverBorder ] ] as $state => [ $fill, $border ] ) {
            if ( '0px' !== $width ) {
                $edge = Colour::opaque( self::colourOf( $border, $colors ), $panel );
                if ( null === $edge || strtolower( $edge ) === strtolower( $panel ) ) {
                    $out[ "$where|$state|border-melts" ] = "$where: the $state border $border is the panel's own colour";
                }
            }
            if ( KitAdapters::TRANSPARENT !== $fill && $border !== $fill ) {
                $out[ "$where|$state|border-not-fill" ] = "$where: the $state border $border of a filled button is not its fill $fill";
            }
        }
        return $out;
    }

    public function testNoInvisibleButtonOnAPanelInAnyKitLanguageRole(): void
    {
        $problems = [];
        $checked  = 0;
        foreach ( $this->shippedKits() as $kit ) {
            foreach ( DesignLanguages::ids() as $langId ) {
                $lang   = DesignLanguages::get( $langId );
                $this->assertIsArray( $lang );
                $colors = (array) $lang['colors'];
                $panel  = (string) Colour::opaque( (string) $colors['text'], (string) $colors['bg'] );
                foreach ( self::ROLES as $role ) {
                    $d    = $this->patch( $kit, $role, 'panel' )['button']['decoration'];
                    $side = $d['border']['desktop']['value']['styles']['bottom'] ?? $d['border']['desktop']['value']['styles']['all'];
                    $states = [
                        'rest'  => [ $d['background']['desktop']['value']['color'], $d['font']['font']['desktop']['value']['color'], $side['color'] ],
                        'hover' => [ $d['background']['desktop']['hover']['color'], $d['font']['font']['desktop']['hover']['color'], $d['border']['desktop']['hover']['styles']['all']['color'] ],
                    ];
                    $problems = array_merge( $problems, self::panelBoxProblems(
                        (string) $states['rest'][0],
                        (string) $states['rest'][2],
                        (string) $states['hover'][0],
                        (string) $states['hover'][2],
                        (string) $side['width'],
                        isset( $d['border']['desktop']['hover']['styles']['all']['width'] ) ? (string) $d['border']['desktop']['hover']['styles']['all']['width'] : null,
                        $colors,
                        $panel,
                        "{$kit['id']}|$langId|$role|panel"
                    ) );
                    foreach ( $states as $state => [ $fill, $label, $border ] ) {
                        ++$checked;
                        $where = "{$kit['id']}|$langId|$role|panel|$state";
                        if ( '{{aied:color:text}}' === $fill ) {
                            $problems[ "$where|text-fill" ] = "$where: a text fill on a text panel";
                        }
                        $problems = array_merge( $problems, self::panelProblems(
                            self::colourOf( (string) $fill, $colors ),
                            self::colourOf( (string) $label, $colors ),
                            self::colourOf( (string) $border, $colors ),
                            (string) $side['width'],
                            $panel,
                            $where
                        ) );
                    }
                }
            }
        }
        $this->assertSame( 1600, $checked );
        $this->assertSame( 40 * 10 * 2 * 2, $checked );
        $this->assertSame( [], array_values( $problems ), "invisible or unreadable buttons on a panel (fix the panel row of the role table, never the bound; no exceptions):\n" . implode( "\n", $problems ) );
    }

    public function testThePanelCheckCatchesWhatItShould(): void
    {
        // A text fill on a text panel: no shape, and the label check alone would pass with a bg label.
        $this->assertArrayHasKey( 'x|fill', self::panelProblems( '#111111', '#ffffff', '#111111', '2px', '#111111', 'x' ) );
        // An accent fill close to the panel (tech-mono's accent on its text is 1.10:1).
        $mono = (array) DesignLanguages::get( 'tech-mono' )['colors'];
        $panel = (string) Colour::opaque( (string) $mono['text'], (string) $mono['bg'] );
        $this->assertArrayHasKey( 'x|fill', self::panelProblems( (string) $mono['accent'], (string) $mono['accent_text'], (string) $mono['accent'], '0px', $panel, 'x' ) );
        // A transparent button in `text` ink on a text panel: neither label nor outline can be seen.
        $out = self::panelProblems( KitAdapters::TRANSPARENT, (string) $mono['text'], (string) $mono['text'], '2px', $panel, 'x' );
        $this->assertArrayHasKey( 'x|label', $out );
        $this->assertArrayHasKey( 'x|outline', $out );
        // Ruling 2: a `text` border on a filled button on a text panel melts into the panel and is not the fill.
        $mc  = (array) $mono;
        $box = self::panelBoxProblems( '{{aied:color:bg}}', '{{aied:color:text}}', '{{aied:color:bg}}', '{{aied:color:text}}', '4px', null, $mc, $panel, 'x' );
        $this->assertArrayHasKey( 'x|rest|border-melts', $box );
        $this->assertArrayHasKey( 'x|rest|border-not-fill', $box );
        // A border that only appears on hover, and a hover width change, are caught; the panel row is fine.
        $this->assertArrayHasKey( 'x|rest|border-not-fill', self::panelBoxProblems( '{{aied:color:bg}}', '{{aied:color:text}}', KitAdapters::TRANSPARENT, '{{aied:color:bg}}', '2px', null, $mc, $panel, 'x' ) );
        $this->assertArrayHasKey( 'x|hover-width', self::panelBoxProblems( '{{aied:color:bg}}', '{{aied:color:bg}}', '{{aied:color:bg}}', '{{aied:color:bg}}', '2px', '4px', $mc, $panel, 'x' ) );
        $this->assertSame( [], self::panelBoxProblems( '{{aied:color:bg}}', '{{aied:color:bg}}', KitAdapters::TRANSPARENT, '{{aied:color:bg}}', '2px', null, $mc, $panel, 'x' ) );
        $this->assertSame( [], self::panelBoxProblems( KitAdapters::TRANSPARENT, '{{aied:color:bg}}', '{{aied:color:bg}}', '{{aied:color:bg}}', '0px', null, $mc, $panel, 'x' ) );
        // The panel row is fine: bg fill with a text label, and a transparent bg-ink button.
        $this->assertSame( [], self::panelProblems( (string) $mono['bg'], (string) $mono['text'], (string) $mono['bg'], '2px', $panel, 'x' ) );
        $this->assertSame( [], self::panelProblems( KitAdapters::TRANSPARENT, (string) $mono['bg'], (string) $mono['bg'], '2px', $panel, 'x' ) );
    }

    public function testThePanelRowFollowsTheDesign(): void
    {
        // Filled: fill bg, label text, whatever the kit's colour (soft-solid is accent, heavy-block text).
        foreach ( [ 'soft-solid', 'heavy-block', 'quiet-solid' ] as $id ) {
            $kit = (array) StyleKits::get( $id );
            $this->assertSame( 'solid', $kit['fill'] );
            $d = $this->patch( $kit, 'control', 'panel' )['button']['decoration'];
            $this->assertSame( '{{aied:color:bg}}', $d['background']['desktop']['value']['color'], $id );
            $this->assertSame( '{{aied:color:text}}', $d['font']['font']['desktop']['value']['color'], $id );
            if ( 'same' === $kit['border']['color'] ) {
                $this->assertSame( '{{aied:color:bg}}', $d['border']['desktop']['value']['styles']['all']['color'], "$id: a same border follows the fill" );
            }
            // The secondary of a solid kit is transparent in the panel's ink.
            $s = $this->patch( $kit, 'control-secondary', 'panel' )['button']['decoration'];
            $this->assertSame( KitAdapters::TRANSPARENT, $s['background']['desktop']['value']['color'], $id );
            $this->assertSame( '{{aied:color:bg}}', $s['font']['font']['desktop']['value']['color'], $id );
            $this->assertSame( '{{aied:color:bg}}', $s['border']['desktop']['value']['styles']['all']['color'], $id );
        }
        // Ruling 2: a filled button's border on a panel is its fill, whatever the kit's border colour (raw-frame: text).
        $raw = (array) StyleKits::get( 'raw-frame' );
        $this->assertSame( 'text', $raw['border']['color'] );
        $d = $this->patch( $raw, 'control', 'panel' )['button']['decoration'];
        $this->assertSame( '{{aied:color:bg}}', $d['border']['desktop']['value']['styles']['all']['color'] );
        $this->assertSame( '{{aied:color:bg}}', $d['border']['desktop']['hover']['styles']['all']['color'] );
        $this->assertSame( '{{aied:color:text}}', $this->patch( $raw, 'control', 'light' )['button']['decoration']['border']['desktop']['value']['styles']['all']['color'], 'no change on light' );
        // An inverse kit colour is never a fill on a panel either.
        $inv = $this->kitWith( 'soft-solid', [ 'color' => 'inverse' ] );
        $this->assertSame( '{{aied:color:bg}}', $this->patch( $inv, 'control', 'panel' )['button']['decoration']['background']['desktop']['value']['color'] );
        // Outline: transparent, label and border bg; fill-swap fills it bg with a text label.
        $outline = (array) StyleKits::get( 'outline-2' );
        $this->assertSame( [ 'outline', 'fill-swap' ], [ $outline['fill'], $outline['hover'] ] );
        $d = $this->patch( $outline, 'control', 'panel' )['button']['decoration'];
        $this->assertSame( KitAdapters::TRANSPARENT, $d['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:bg}}', $d['font']['font']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:bg}}', $d['border']['desktop']['value']['styles']['all']['color'] );
        $this->assertSame( '{{aied:color:bg}}', $d['background']['desktop']['hover']['color'] );
        $this->assertSame( '{{aied:color:text}}', $d['font']['font']['desktop']['hover']['color'] );
        $this->assertSame( '{{aied:color:bg}}', $d['border']['desktop']['hover']['styles']['all']['color'] );
        // A filled button with fill-swap goes transparent in bg.
        $swap = $this->kitWith( 'soft-solid', [ 'hover' => 'fill-swap' ] );
        $d    = $this->patch( $swap, 'control', 'panel' )['button']['decoration'];
        $this->assertSame( KitAdapters::TRANSPARENT, $d['background']['desktop']['hover']['color'] );
        $this->assertSame( '{{aied:color:bg}}', $d['font']['font']['desktop']['hover']['color'] );
        // invert behaves as fill-swap on a panel (a text fill on a text panel would make the button vanish), both roles.
        foreach ( [ 'soft-solid', 'outline-2', 'heavy-block' ] as $id ) {
            foreach ( self::ROLES as $role ) {
                $invert = $this->kitWith( $id, [ 'hover' => 'invert' ] );
                $swapped = $this->kitWith( $id, [ 'hover' => 'fill-swap' ] );
                $this->assertSame( $this->patch( $swapped, $role, 'panel' )['button'], $this->patch( $invert, $role, 'panel' )['button'], "$id $role: invert = fill-swap on a panel" );
            }
        }
        // lift keeps the rest colours and lifts.
        $lift = $this->kitWith( 'soft-solid', [ 'hover' => 'lift' ] );
        $p    = $this->patch( $lift, 'control', 'panel' );
        $this->assertSame( [ 'desktop' => [ 'hover' => [ 'translate' => [ 'y' => '-4px' ] ] ] ], $p['module']['decoration']['transform'] );
        $this->assertSame( $p['button']['decoration']['background']['desktop']['value']['color'], $p['button']['decoration']['background']['desktop']['hover']['color'] );
        // Shadows on a panel: hard and edge in bg (opaque), glow in bg at 45%, no soft shadow (rest or hover-only).
        foreach ( [ 'hard' => [ 'style' => 'preset4', 'horizontal' => '6px', 'vertical' => '6px', 'blur' => '0px', 'color' => '{{aied:color:bg}}' ],
            'edge' => [ 'style' => 'preset4', 'horizontal' => '0px', 'vertical' => '4px', 'blur' => '0px', 'color' => '{{aied:color:bg}}' ],
            'glow' => [ 'style' => 'preset1', 'vertical' => '0px', 'blur' => '16px', 'color' => '{{aied:color:bg:45}}' ] ] as $shadow => $value ) {
            $kit = $this->kitWith( 'outline-2', [ 'shadow' => [ 'rest' => $shadow, 'hover' => $shadow ] ] );
            $this->assertSame( [ 'desktop' => [ 'value' => $value ] ], $this->patch( $kit, 'control', 'panel' )['module']['decoration']['boxShadow'], $shadow );
        }
        foreach ( [ [ 'soft', 'soft' ], [ 'none', 'soft' ] ] as [ $rest, $hover ] ) {
            $kit = $this->kitWith( 'soft-solid', [ 'shadow' => [ 'rest' => $rest, 'hover' => $hover ] ] );
            $this->assertArrayNotHasKey( 'boxShadow', $this->patch( $kit, 'control', 'panel' )['module']['decoration'], "$rest/$hover on a panel" );
            $this->assertArrayHasKey( 'boxShadow', $this->patch( $kit, 'control', 'light' )['module']['decoration'], "$rest/$hover on light is unchanged" );
        }
        // An underline kit on a panel: the bottom side in the panel's ink.
        $under = (array) StyleKits::get( 'underline-link' );
        $this->assertSame( '{{aied:color:bg}}', $this->patch( $under, 'control-secondary', 'panel' )['button']['decoration']['border']['desktop']['value']['styles']['bottom']['color'] );
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
