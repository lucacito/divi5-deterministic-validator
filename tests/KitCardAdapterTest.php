<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
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
require_once __DIR__ . '/Support/DeadOnModule.php';

/**
 * The card adapter: a kit's decisions become the `module.decoration` of a card (`divi/group` or `divi/column`, roles
 * `card` and `panel`, on a light, surface, accent or dark band): fill, frame, radius, padding, shadow and hover, every
 * path a registry path not dead on its carrier, the merged block passes the real gate. Contrast proofs are Task 4.
 */
class KitCardAdapterTest extends TestCase
{
    private const CARRIERS = [ 'divi/group', 'divi/column' ];
    private const ROLES    = [ 'card', 'panel' ];
    private const SURFACES = [ 'light', 'surface', 'accent', 'dark' ];

    private const COLOR_ROLES = [ 'bg', 'surface', 'text', 'muted', 'accent', 'accent-text', 'border', 'inverse', 'overlay' ];

    /** @return array<string,array<string,mixed>> every shipped kit by id */
    private function shippedKits(): array
    {
        $kits = [];
        foreach ( StyleKits::ids() as $id ) {
            $kit = StyleKits::get( $id );
            $this->assertIsArray( $kit );
            $this->assertSame( [], StyleKits::problems( $kit ), "shipped kit $id must be valid" );
            $kits[ $id ] = $kit;
        }
        $this->assertCount( 40, $kits );
        return $kits;
    }

    /**
     * Every shipped kit plus variants of two kits (a full frame and an underline) over every hover x border colour x shadow
     * pair x width the schema allows, so every branch of the card adapter is exercised.
     *
     * @return array<string,array<string,mixed>>
     */
    private function allKitVariants(): array
    {
        $out = $this->shippedKits();
        foreach ( [ 'soft-solid', 'underline-link' ] as $base ) {
            $kit = $out[ $base ];
            foreach ( StyleKits::HOVERS as $hover ) {
                foreach ( StyleKits::BORDER_COLORS as $borderColor ) {
                    foreach ( StyleKits::SHADOW_PAIRS as $pair ) {
                        foreach ( [ '0px', '2px' ] as $width ) {
                            [ $rest, $hoverShadow ] = explode( '/', $pair );
                            $v                    = $kit;
                            $v['hover']           = $hover;
                            $v['border']['color'] = $borderColor;
                            $v['border']['width'] = $width;
                            $v['shadow']          = [ 'rest' => $rest, 'hover' => $hoverShadow ];
                            if ( [] === StyleKits::problems( $v ) ) {
                                $out[ "$base/$hover/$borderColor/$pair/$width" ] = $v;
                            }
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $kit */
    private function patch( array $kit, string $role, string $on, string $carrier = 'divi/group' ): array
    {
        $patch = KitAdapters::patch( $carrier, $role, $on, $kit );
        $this->assertIsArray( $patch, "{$kit['id']} $role on $on ($carrier) must have a patch" );
        return $patch;
    }

    /** @param array<string,mixed> $kit */
    private function deco( array $kit, string $role, string $on, string $carrier = 'divi/group' ): array
    {
        $patch = $this->patch( $kit, $role, $on, $carrier );
        $this->assertSame( [ 'module' ], array_keys( $patch ) );
        $this->assertSame( [ 'decoration' ], array_keys( $patch['module'] ) );
        return $patch['module']['decoration'];
    }

    private function cardBlock( string $carrier, string $role, string $on ): string
    {
        $name   = substr( $carrier, 5 );
        $layout = 'divi/column' === $carrier ? '"advanced":{"type":{"desktop":{"value":"4_4"}}},' : '';
        return '<!-- wp:' . $carrier . ' {"module":{' . $layout . '"decoration":{"layout":{"desktop":{"value":{"flexDirection":"column"}}}}},'
            . '"builderVersion":"5.15.1","aied":{"kit":"' . $role . '","on":"' . $on . '"}} -->'
            . '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Inside the ' . $name . '</p>"}}},"builderVersion":"5.15.1"} /-->'
            . '<!-- /wp:' . $carrier . ' -->';
    }

    private function kitMarkup( string $carrier, string $role, string $on ): string
    {
        $card = $this->cardBlock( $carrier, $role, $on );
        if ( 'divi/column' === $carrier ) {
            return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.15.1"} --><!-- wp:divi/row {"builderVersion":"5.15.1"} -->'
                . $card . '<!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
        }
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.15.1"} --><!-- wp:divi/row {"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1"} -->'
            . $card . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    /** Every key on the way to every leaf of $node. @return list<string> */
    private static function keys( mixed $node ): array
    {
        $out = [];
        if ( is_array( $node ) ) {
            foreach ( $node as $k => $v ) {
                $out[] = (string) $k;
                foreach ( self::keys( $v ) as $sub ) {
                    $out[] = $sub;
                }
            }
        }
        return $out;
    }

    public function testEveryEmittedCardPathIsInTheRegistryAndNotDeadOnItsCarrier(): void
    {
        $registry = ShapeRegistry::load();
        $dead     = DeadOnModule::load();
        $missing  = [];
        $killed   = [];
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::CARRIERS as $carrier ) {
                foreach ( self::ROLES as $role ) {
                    foreach ( self::SURFACES as $on ) {
                        $deco = $this->deco( $kit, $role, $on, $carrier );
                        foreach ( array_keys( $deco ) as $group ) {
                            $this->assertContains( $group, [ 'background', 'border', 'spacing', 'boxShadow', 'transform' ], "$label $role on $on" );
                        }
                        $markup = KitResolver::resolve( $this->kitMarkup( $carrier, $role, $on ), $kit );
                        $this->assertSame( [], KitResolver::leftovers( $markup ), "$label $role on $on ($carrier)" );
                        $this->assertStringNotContainsString( '"aied":{', $markup );
                        foreach ( ShapePaths::fromMarkup( $markup ) as $path ) {
                            if ( ! $registry->coversLeaf( $path ) ) {
                                $missing[ $path ] = "$label $role on $on ($carrier)";
                            }
                        }
                        foreach ( $dead->violations( $markup ) as $v ) {
                            $killed[ $v ] = $label;
                        }
                        $json = (string) json_encode( $deco );
                        foreach ( [ 'tablet', 'phone', 'tabletWide', 'phoneWide' ] as $bp ) {
                            $this->assertStringNotContainsString( "\"$bp\"", $json, 'only desktop is written' );
                        }
                    }
                }
            }
        }
        $this->assertSame( [], $missing, 'the card adapter emits paths the registry does not know' );
        $this->assertSame( [], array_keys( $killed ), 'the card adapter emits paths that are dead on their carrier' );
    }

    /** $markup with a card marker added to every `divi/group` and `divi/column` block comment. */
    private static function markEveryCarrier( string $markup, string $role, string $on ): string
    {
        return (string) preg_replace_callback(
            '/(<!--\s+wp:(divi\/(?:group|column))\s+)(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\})(\s+\/?-->)/s',
            static function ( array $m ) use ( $role, $on ): string {
                $attrs         = json_decode( $m[3], true );
                $attrs['aied'] = [ 'kit' => $role, 'on' => $on ];
                return $m[1] . RecipeTokens::encodeJson( $attrs ) . $m[4];
            },
            $markup
        );
    }

    public function testCardPatchValidatesInsideAGroupAndAColumnBlock(): void
    {
        // A real recipe: every group (the panel and its rows) and every column (two halves of a 1_2,1_2 row) carries a marker.
        $raw = ImageTokens::resolve( (string) SectionRecipes::rawMarkup( 'hero-spec-card' ), 'https://example.test/images/' );
        $this->assertStringContainsString( 'wp:divi/group', $raw );
        $this->assertStringContainsString( 'wp:divi/column', $raw );
        $checked = 0;
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::SURFACES as $on ) {
                    $marked = self::markEveryCarrier( $raw, $role, $on );
                    $this->assertGreaterThan( 2, substr_count( $marked, '"aied":{"kit":"' . $role . '"' ) );
                    $markup = KitResolver::resolve( $marked, $kit );
                    $this->assertSame( [], KitResolver::leftovers( $markup ), "$id $role on $on" );
                    foreach ( [ null, 'brutalist-flat' ] as $langId ) {
                        $lang = null === $langId ? RecipeTokens::default() : DesignLanguages::get( $langId );
                        $this->assertIsArray( $lang );
                        $resolved = RecipeTokens::resolve( $markup, $lang );
                        $this->assertSame( [], RecipeTokens::found( $resolved ), "$id $role on $on" );
                        $result = LayoutGate::check( '<!-- wp:divi/placeholder -->' . $resolved . '<!-- /wp:divi/placeholder -->' );
                        $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                        $this->assertTrue( $result->isValid(), "$id $role on $on must pass the gate, got: $codes" );
                        ++$checked;
                    }
                }
            }
        }
        $this->assertSame( 40 * 2 * 4 * 2, $checked );
    }

    public function testNoHexAndNoUnresolvedTokensBeyondColourAndKit(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::CARRIERS as $carrier ) {
                foreach ( self::ROLES as $role ) {
                    foreach ( self::SURFACES as $on ) {
                        $json = (string) json_encode( $this->patch( $kit, $role, $on, $carrier ), JSON_UNESCAPED_SLASHES );
                        $this->assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $json, "$label $role on $on" );
                        $this->assertDoesNotMatchRegularExpression( '/rgba?\(/i', $json, 'a card is never transparent: no literal colour at all' );
                        preg_match_all( '/\{\{[^}]*\}\}/', $json, $t );
                        foreach ( $t[0] as $token ) {
                            $this->assertMatchesRegularExpression(
                                '/^\{\{aied:(?:color:(?:' . implode( '|', self::COLOR_ROLES ) . ')|color:overlay:(?:18|25)|color:(?:accent|accent-text):45|kit:(?:radius-card|space-card))\}\}$/',
                                $token,
                                "$label $role on $on"
                            );
                        }
                        $this->assertStringContainsString( '"radius":"{{aied:kit:radius-card}}"', $json );
                        if ( 'card' === $role ) {
                            $this->assertStringContainsString( '"padding":"{{aied:kit:space-card}}"', $json );
                        }
                    }
                }
            }
        }
    }

    public function testFillIsSurfaceOrBgByBand(): void
    {
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::CARRIERS as $carrier ) {
                foreach ( self::ROLES as $role ) {
                    foreach ( self::SURFACES as $on ) {
                        $bg = $this->deco( $kit, $role, $on, $carrier )['background'];
                        $this->assertSame(
                            [ 'desktop' => [ 'value' => [ 'color' => 'surface' === $on ? '{{aied:color:bg}}' : '{{aied:color:surface}}' ] ] ],
                            $bg,
                            "$id $role on $on ($carrier): filled, no hover fill"
                        );
                    }
                }
            }
        }
    }

    public function testZeroWidthKitsGetAOnePxBorderRoleFrame(): void
    {
        $kit = StyleKits::get( 'quiet-solid' );
        $this->assertIsArray( $kit );
        $this->assertSame( [ '0px', 'same' ], [ $kit['border']['width'], $kit['border']['color'] ] );
        foreach ( self::ROLES as $role ) {
            foreach ( self::SURFACES as $on ) {
                $border = $this->deco( $kit, $role, $on )['border'];
                $this->assertSame(
                    [ 'desktop' => [ 'value' => [
                        'radius' => '{{aied:kit:radius-card}}',
                        'styles' => [ 'all' => [ 'width' => '1px', 'style' => 'solid', 'color' => '{{aied:color:border}}' ] ],
                    ] ] ],
                    $border,
                    "$role on $on: a 1px `border` hairline, no hover"
                );
            }
        }
        // The kit's own width and style when it has one; `text`, `accent` and `inverse` frame colours.
        $chunky = StyleKits::get( 'chunky-pill' );
        $this->assertIsArray( $chunky );
        $this->assertSame( [ 'width' => '2px', 'style' => 'solid', 'color' => '{{aied:color:text}}' ], $this->deco( $chunky, 'card', 'light' )['border']['desktop']['value']['styles']['all'] );
        $dashed = StyleKits::get( 'hairline-dashed' );
        $this->assertIsArray( $dashed );
        $this->assertSame( [ 'width' => '1px', 'style' => 'dashed', 'color' => '{{aied:color:border}}' ], $this->deco( $dashed, 'panel', 'dark' )['border']['desktop']['value']['styles']['all'] );
        $rimmed = StyleKits::get( 'rimmed-solid' );
        $this->assertIsArray( $rimmed );
        $this->assertSame( '{{aied:color:accent}}', $this->deco( $rimmed, 'card', 'accent' )['border']['desktop']['value']['styles']['all']['color'] );
        $inverse                    = $chunky;
        $inverse['border']['color'] = 'inverse';
        $this->assertSame( [], StyleKits::problems( $inverse ) );
        $this->assertSame( '{{aied:color:text}}', $this->deco( $inverse, 'card', 'dark' )['border']['desktop']['value']['styles']['all']['color'], 'inverse frames are text on a card fill' );
    }

    public function testUnderlineKitWritesOnlyTheBottomEdge(): void
    {
        foreach ( [ 'underline-link' => [ '2px', 'text' ], 'underlined-label' => [ '3px', 'text' ] ] as $id => [ $width, $colour ] ) {
            $kit = StyleKits::get( $id );
            $this->assertIsArray( $kit );
            $this->assertSame( 'bottom', $kit['border']['sides'] );
            foreach ( self::ROLES as $role ) {
                foreach ( self::SURFACES as $on ) {
                    $border = $this->deco( $kit, $role, $on )['border'];
                    $this->assertSame( [ 'desktop' ], array_keys( $border ) );
                    $this->assertSame( [ 'value' ], array_keys( $border['desktop'] ), "$id $role on $on: no hover border colour on a bottom-only card (not proven)" );
                    $this->assertSame(
                        [
                            'all'    => [ 'width' => '0px', 'style' => 'solid', 'color' => '{{aied:color:' . $colour . '}}' ],
                            'bottom' => [ 'width' => $width, 'style' => 'solid', 'color' => '{{aied:color:' . $colour . '}}' ],
                        ],
                        $border['desktop']['value']['styles'],
                        "$id $role on $on"
                    );
                }
            }
        }
        // Lift on an underline card is still written for `card`.
        $lift          = (array) StyleKits::get( 'underline-link' );
        $lift['hover'] = 'lift';
        $this->assertSame( [], StyleKits::problems( $lift ) );
        $deco = $this->deco( $lift, 'card', 'light' );
        $this->assertSame( [ 'desktop' => [ 'hover' => [ 'translate' => [ 'y' => '-4px' ] ] ] ], $deco['transform'] );
        $this->assertSame( [ 'value' ], array_keys( $deco['border']['desktop'] ) );
    }

    public function testPanelWritesNoPaddingAndNoHoverKeysAnywhere(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::CARRIERS as $carrier ) {
                foreach ( self::SURFACES as $on ) {
                    $deco = $this->deco( $kit, 'panel', $on, $carrier );
                    $this->assertArrayNotHasKey( 'spacing', $deco, "$label panel on $on: the recipe keeps its padding" );
                    $this->assertArrayNotHasKey( 'transform', $deco, "$label panel on $on" );
                    $this->assertNotContains( 'hover', self::keys( $deco ), "$label panel on $on ($carrier) writes a hover key" );
                    // A rest shadow survives on a panel (the card's rest value, hover dropped).
                    $card = $this->deco( $kit, 'card', $on, $carrier );
                    if ( isset( $card['boxShadow']['desktop']['value'] ) ) {
                        $this->assertSame( [ 'desktop' => [ 'value' => $card['boxShadow']['desktop']['value'] ] ], $deco['boxShadow'] );
                    } else {
                        $this->assertArrayNotHasKey( 'boxShadow', $deco, "$label panel on $on: a hover-only shadow is nothing on a panel" );
                    }
                }
            }
        }
    }

    public function testCardHoverFollowsTheKit(): void
    {
        $kits = $this->shippedKits();
        // lift: translate y -4px on hover, the frame keeps its colour.
        $deco = $this->deco( $kits['neo-brutal'], 'card', 'light' );
        $this->assertSame( [ 'desktop' => [ 'hover' => [ 'translate' => [ 'y' => '-4px' ] ] ] ], $deco['transform'] );
        $this->assertArrayNotHasKey( 'hover', $deco['border']['desktop'] );
        // fill-swap and invert: the frame goes to accent on hover (a `text` frame, a `border` frame).
        foreach ( [ 'outline-2' => 'fill-swap', 'chunky-pill' => 'invert', 'glass-edge' => 'invert' ] as $id => $hover ) {
            $this->assertSame( $hover, $kits[ $id ]['hover'] );
            foreach ( self::SURFACES as $on ) {
                $deco = $this->deco( $kits[ $id ], 'card', $on );
                $this->assertSame( [ 'styles' => [ 'all' => [ 'color' => '{{aied:color:accent}}' ] ] ], $deco['border']['desktop']['hover'], "$id on $on" );
                $this->assertArrayNotHasKey( 'transform', $deco );
            }
        }
        // An accent frame is already accent: no redundant hover key.
        $this->assertSame( 'invert', $kits['rimmed-solid']['hover'] );
        $this->assertArrayNotHasKey( 'hover', $this->deco( $kits['rimmed-solid'], 'card', 'light' )['border']['desktop'] );
        // none: nothing on hover at all.
        $deco = $this->deco( $kits['quiet-solid'], 'card', 'light' );
        $this->assertNotContains( 'hover', self::keys( $deco ) );
        $this->assertArrayNotHasKey( 'transform', $deco );
        // The card's padding is the kit's card space.
        $this->assertSame( [ 'desktop' => [ 'value' => [ 'padding' => '{{aied:kit:space-card}}' ] ] ], $deco['spacing'] );
    }

    public function testShadowsMatchTheButtonForms(): void
    {
        // The card writes exactly the module box shadow the button writes for the same kit and band (`surface` = `light`).
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::SURFACES as $on ) {
                $buttonOn = 'surface' === $on ? 'light' : $on;
                $button   = KitAdapters::patch( 'divi/button', 'control', $buttonOn, $kit );
                $this->assertIsArray( $button );
                $expected = $button['module']['decoration']['boxShadow'] ?? null;
                $deco     = $this->deco( $kit, 'card', $on );
                $this->assertSame( $expected, $deco['boxShadow'] ?? null, "$label card on $on" );
            }
        }
        $kits = $this->shippedKits();
        $this->assertSame(
            [ 'desktop' => [ 'value' => [ 'style' => 'preset4', 'horizontal' => '6px', 'vertical' => '6px', 'blur' => '0px', 'color' => '{{aied:color:accent-text}}' ] ] ],
            $this->deco( $kits['chunky-pill-offset'], 'card', 'accent' )['boxShadow']
        );
        $this->assertSame(
            [ 'desktop' => [ 'value' => [ 'style' => 'preset4', 'horizontal' => '0px', 'vertical' => '4px', 'blur' => '0px', 'color' => '{{aied:color:text}}' ] ] ],
            $this->deco( $kits['pressable'], 'panel', 'surface' )['boxShadow']
        );
        $this->assertSame(
            [ 'desktop' => [ 'hover' => [ 'style' => 'preset1', 'vertical' => '12px', 'blur' => '24px', 'color' => '{{aied:color:overlay:25}}' ] ] ],
            $this->deco( $kits['soft-lift'], 'card', 'dark' )['boxShadow']
        );
        $this->assertArrayNotHasKey( 'boxShadow', $this->deco( $kits['soft-lift'], 'panel', 'dark' ) );
    }

    public function testPatchIsDeterministic(): void
    {
        foreach ( $this->shippedKits() as $kit ) {
            foreach ( self::CARRIERS as $carrier ) {
                $this->assertSame(
                    json_encode( KitAdapters::patch( $carrier, 'card', 'surface', $kit ) ),
                    json_encode( KitAdapters::patch( $carrier, 'card', 'surface', $kit ) )
                );
            }
            // Both carriers get the same patch.
            $this->assertSame( KitAdapters::patch( 'divi/group', 'panel', 'accent', $kit ), KitAdapters::patch( 'divi/column', 'panel', 'accent', $kit ) );
        }
    }

    public function testUnknownCombinationsAreNull(): void
    {
        $kit = $this->shippedKits()['quiet-solid'];
        $this->assertNull( KitAdapters::patch( 'divi/button', 'card', 'light', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/button', 'panel', 'light', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/group', 'control', 'light', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/column', 'control-secondary', 'light', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/button', 'control', 'surface', $kit ), 'surface is a card band only' );
        $this->assertNull( KitAdapters::patch( 'divi/image', 'card', 'light', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/group', 'card', 'photo', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/group', 'Card', 'light', $kit ) );
        $bad         = $kit;
        $bad['fill'] = 'gradient';
        $this->assertNull( KitAdapters::patch( 'divi/group', 'card', 'light', $bad ), 'an invalid kit has no patch' );
        $this->assertNull( KitAdapters::patch( 'divi/column', 'panel', 'light', [] ) );
    }

    public function testAppliedNamesEveryBlockAndRoleThePatchAnswers(): void
    {
        $this->assertSame(
            [ 'divi/button' => [ 'control', 'control-secondary' ], 'divi/group' => [ 'card', 'panel' ], 'divi/column' => [ 'card', 'panel' ] ],
            KitAdapters::APPLIED
        );
        $kit = $this->shippedKits()['soft-solid'];
        foreach ( KitAdapters::APPLIED as $block => $roles ) {
            foreach ( $roles as $role ) {
                $this->assertIsArray( KitAdapters::patch( $block, $role, 'light', $kit ), "$block $role" );
            }
        }
    }
}
