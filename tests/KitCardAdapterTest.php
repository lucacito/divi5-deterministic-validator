<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LanguageResolver;
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
 * The card adapter: a kit's decisions become the `module.decoration` of a card (`divi/group` or `divi/column`, roles
 * `card` and `panel`, on a light, surface, accent or dark band): fill, frame, radius, padding, shadow and hover, every
 * path a registry path not dead on its carrier, the merged block passes the real gate, and no kit x language x role x band makes a card whose fill, text or meaningful frame cannot be read (Task 4).
 */
class KitCardAdapterTest extends TestCase
{
    private const CARRIERS = [ 'divi/group', 'divi/column' ];
    private const ROLES    = [ 'card', 'panel' ];
    private const SURFACES = [ 'light', 'surface', 'accent', 'dark' ];

    /** The grounds a card or panel may name, and the bands a ground may sit on (light and surface only). */
    private const GROUNDS      = [ 'inverted', 'accent' ];
    private const GROUND_BANDS = [ 'light', 'surface' ];

    /** The least contrast of text against the light and surface bands over the ten languages (organic-warm's bg, 11.978:1). */
    private const INVERTED_LEAST = 11.978;

    /** Ground => [the fill token, the child inks the recipe may use on it, the least contrast of the fill against the band]. */
    private const GROUND_RULES = [
        'inverted' => [ '{{aied:color:text}}', [ 'bg', 'surface' ], self::INVERTED_LEAST ],
        'accent'   => [ '{{aied:color:accent}}', [ 'accent-text' ], 2.0 ],
    ];

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
    private function patch( array $kit, string $role, string $on, string $carrier = 'divi/group', ?string $ground = null ): array
    {
        $patch = null === $ground ? KitAdapters::patch( $carrier, $role, $on, $kit ) : KitAdapters::patch( $carrier, $role, $on, $kit, $ground );
        $this->assertIsArray( $patch, "{$kit['id']} $role on $on ($carrier) ground " . ( $ground ?? '-' ) . ' must have a patch' );
        return $patch;
    }

    /** @param array<string,mixed> $kit */
    private function deco( array $kit, string $role, string $on, string $carrier = 'divi/group', ?string $ground = null ): array
    {
        $patch = $this->patch( $kit, $role, $on, $carrier, $ground );
        $this->assertSame( [ 'module' ], array_keys( $patch ) );
        $this->assertSame( [ 'decoration' ], array_keys( $patch['module'] ) );
        return $patch['module']['decoration'];
    }

    private function cardBlock( string $carrier, string $role, string $on, ?string $ground = null ): string
    {
        $name   = substr( $carrier, 5 );
        $layout = 'divi/column' === $carrier ? '"advanced":{"type":{"desktop":{"value":"4_4"}}},' : '';
        return '<!-- wp:' . $carrier . ' {"module":{' . $layout . '"decoration":{"layout":{"desktop":{"value":{"flexDirection":"column"}}}}},'
            . '"builderVersion":"5.15.1","aied":{"kit":"' . $role . '","on":"' . $on . '"' . ( null === $ground ? '' : ',"ground":"' . $ground . '"' ) . '}} -->'
            . '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Inside the ' . $name . '</p>"}}},"builderVersion":"5.15.1"} /-->'
            . '<!-- /wp:' . $carrier . ' -->';
    }

    private function kitMarkup( string $carrier, string $role, string $on, ?string $ground = null ): string
    {
        $card = $this->cardBlock( $carrier, $role, $on, $ground );
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

    public function testEveryEmittedGroundPathIsInTheRegistryAndNotDeadOnItsCarrier(): void
    {
        $registry = ShapeRegistry::load();
        $dead     = DeadOnModule::load();
        $missing  = [];
        $killed   = [];
        $checked  = 0;
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::CARRIERS as $carrier ) {
                foreach ( self::ROLES as $role ) {
                    foreach ( self::GROUND_BANDS as $on ) {
                        foreach ( self::GROUNDS as $ground ) {
                            $deco = $this->deco( $kit, $role, $on, $carrier, $ground );
                            foreach ( array_keys( $deco ) as $group ) {
                                $this->assertContains( $group, [ 'background', 'border', 'spacing', 'boxShadow', 'transform' ], "$label $role $ground on $on" );
                            }
                            $markup = KitResolver::resolve( $this->kitMarkup( $carrier, $role, $on, $ground ), $kit );
                            $this->assertSame( [], KitResolver::leftovers( $markup ), "$label $role $ground on $on ($carrier)" );
                            $this->assertStringNotContainsString( '"aied":{', $markup );
                            foreach ( ShapePaths::fromMarkup( $markup ) as $path ) {
                                if ( ! $registry->coversLeaf( $path ) ) {
                                    $missing[ $path ] = "$label $role $ground on $on ($carrier)";
                                }
                            }
                            foreach ( $dead->violations( $markup ) as $v ) {
                                $killed[ $v ] = $label;
                            }
                            $json = (string) json_encode( $deco );
                            foreach ( [ 'tablet', 'phone', 'tabletWide', 'phoneWide' ] as $bp ) {
                                $this->assertStringNotContainsString( "\"$bp\"", $json, 'only desktop is written' );
                            }
                            ++$checked;
                        }
                    }
                }
            }
        }
        $this->assertGreaterThan( 40 * 2 * 2 * 2 * 2, $checked );
        $this->assertSame( [], $missing, 'a ground patch emits paths the registry does not know' );
        $this->assertSame( [], array_keys( $killed ), 'a ground patch emits paths that are dead on their carrier' );
    }

    public function testGroundPatchValidatesInsideAGroupAndAColumnBlock(): void
    {
        // The language step runs first, as when a recipe is served (type adapter Task 5: recipe headings carry type markers).
        $raw = LanguageResolver::resolve( ImageTokens::resolve( (string) SectionRecipes::rawMarkup( 'hero-spec-card' ), 'https://example.test/images/' ), (array) RecipeTokens::default() );
        $checked = 0;
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::GROUND_BANDS as $on ) {
                    foreach ( self::GROUNDS as $ground ) {
                        $marked = self::markEveryCarrier( $raw, $role, $on, $ground );
                        $this->assertGreaterThan( 2, substr_count( $marked, '"ground":"' . $ground . '"' ) );
                        $markup = KitResolver::resolve( $marked, $kit );
                        $this->assertSame( [], KitResolver::leftovers( $markup ), "$id $role $ground on $on" );
                        $resolved = RecipeTokens::resolve( $markup, RecipeTokens::default() );
                        $this->assertSame( [], RecipeTokens::found( $resolved ), "$id $role $ground on $on" );
                        $result = LayoutGate::check( '<!-- wp:divi/placeholder -->' . $resolved . '<!-- /wp:divi/placeholder -->' );
                        $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                        $this->assertTrue( $result->isValid(), "$id $role $ground on $on must pass the gate, got: $codes" );
                        ++$checked;
                    }
                }
            }
        }
        $this->assertSame( 40 * 2 * 2 * 2, $checked );
    }

    /** $markup with a card marker added to every `divi/group` and `divi/column` block comment. */
    private static function markEveryCarrier( string $markup, string $role, string $on, ?string $ground = null ): string
    {
        return (string) preg_replace_callback(
            '/(<!--\s+wp:(divi\/(?:group|column))\s+)(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\})(\s+\/?-->)/s',
            static function ( array $m ) use ( $role, $on, $ground ): string {
                $attrs         = json_decode( $m[3], true );
                $attrs['aied'] = null === $ground ? [ 'kit' => $role, 'on' => $on ] : [ 'kit' => $role, 'on' => $on, 'ground' => $ground ];
                return $m[1] . RecipeTokens::encodeJson( $attrs ) . $m[4];
            },
            $markup
        );
    }

    public function testCardPatchValidatesInsideAGroupAndAColumnBlock(): void
    {
        // A real recipe: every group (the panel and its rows) and every column (two halves of a 1_2,1_2 row) carries a marker.
        // The language step runs first, as when a recipe is served (type adapter Task 5: recipe headings carry type markers).
        $raw = LanguageResolver::resolve( ImageTokens::resolve( (string) SectionRecipes::rawMarkup( 'hero-spec-card' ), 'https://example.test/images/' ), (array) RecipeTokens::default() );
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
                    foreach ( self::GROUND_BANDS as $on ) {
                        foreach ( self::GROUNDS as $ground ) {
                            $json = (string) json_encode( $this->patch( $kit, $role, $on, $carrier, $ground ), JSON_UNESCAPED_SLASHES );
                            $this->assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $json, "$label $role $ground on $on" );
                            $this->assertDoesNotMatchRegularExpression( '/rgba?\(/i', $json, "$label $role $ground on $on" );
                            preg_match_all( '/\{\{[^}]*\}\}/', $json, $t );
                            foreach ( $t[0] as $token ) {
                                $this->assertMatchesRegularExpression(
                                    '/^\{\{aied:(?:color:(?:' . implode( '|', self::COLOR_ROLES ) . ')|color:overlay:(?:18|25)|color:(?:accent|accent-text):45|kit:(?:radius-card|space-card))\}\}$/',
                                    $token,
                                    "$label $role $ground on $on"
                                );
                            }
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
        // An accent frame is a text frame (the contrast proof: accent is below 3:1 on the card fill in two languages).
        $this->assertSame( '{{aied:color:text}}', $this->deco( $rimmed, 'card', 'accent' )['border']['desktop']['value']['styles']['all']['color'] );
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
        // fill-swap and invert: a `border` hairline frame goes to text on hover (glass-edge, heavy-block, flat-square).
        foreach ( [ 'glass-edge' => 'invert', 'heavy-block' => 'invert', 'flat-square' => 'invert' ] as $id => $hover ) {
            $this->assertSame( $hover, $kits[ $id ]['hover'] );
            foreach ( self::SURFACES as $on ) {
                $deco = $this->deco( $kits[ $id ], 'card', $on );
                $this->assertSame( '{{aied:color:border}}', $deco['border']['desktop']['value']['styles']['all']['color'] );
                $this->assertSame( [ 'styles' => [ 'all' => [ 'color' => '{{aied:color:text}}' ] ] ], $deco['border']['desktop']['hover'], "$id on $on" );
                $this->assertArrayNotHasKey( 'transform', $deco );
            }
        }
        // A frame that already is text (a `text` frame, an `accent` frame made text) gets no redundant hover key.
        foreach ( [ 'outline-2' => 'fill-swap', 'chunky-pill' => 'invert', 'rimmed-solid' => 'invert' ] as $id => $hover ) {
            $this->assertSame( $hover, $kits[ $id ]['hover'] );
            foreach ( self::SURFACES as $on ) {
                $this->assertArrayNotHasKey( 'hover', $this->deco( $kits[ $id ], 'card', $on )['border']['desktop'], "$id on $on" );
            }
        }
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

    public function testAGroundTheAdapterDoesNotKnowIsNull(): void
    {
        $kit = $this->shippedKits()['soft-solid'];
        foreach ( self::CARRIERS as $carrier ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::GROUNDS as $ground ) {
                    // A ground sits on a light or surface band only.
                    $this->assertNull( KitAdapters::patch( $carrier, $role, 'accent', $kit, $ground ), "$carrier $role $ground on accent" );
                    $this->assertNull( KitAdapters::patch( $carrier, $role, 'dark', $kit, $ground ), "$carrier $role $ground on dark" );
                    $this->assertNull( KitAdapters::patch( $carrier, $role, 'panel', $kit, $ground ), "$carrier $role $ground on panel" );
                    $this->assertNull( KitAdapters::patch( $carrier, $role, 'light', [], $ground ), 'an invalid kit has no patch' );
                }
                foreach ( [ 'x', '', 'Inverted', 'text', 'dark', 'light' ] as $ground ) {
                    $this->assertNull( KitAdapters::patch( $carrier, $role, 'light', $kit, $ground ), "$carrier $role ground '$ground'" );
                }
                // `panel` is a button surface: never a card band.
                $this->assertNull( KitAdapters::patch( $carrier, $role, 'panel', $kit ), "$carrier $role on panel" );
            }
        }
        // A ground on a button or an image, or with a control role, is nothing.
        foreach ( self::GROUNDS as $ground ) {
            foreach ( [ 'control', 'control-secondary' ] as $role ) {
                foreach ( [ 'light', 'accent', 'dark', 'panel' ] as $on ) {
                    $this->assertNull( KitAdapters::patch( 'divi/button', $role, $on, $kit, $ground ), "button $role on $on $ground" );
                }
                $this->assertNull( KitAdapters::patch( 'divi/group', $role, 'light', $kit, $ground ) );
            }
            $this->assertNull( KitAdapters::patch( 'divi/image', 'card', 'light', $kit, $ground ) );
        }
        // An explicit null ground is the plain card (the fifth parameter is optional and backward compatible).
        foreach ( self::SURFACES as $on ) {
            $this->assertSame( KitAdapters::patch( 'divi/group', 'card', $on, $kit ), KitAdapters::patch( 'divi/group', 'card', $on, $kit, null ) );
        }
    }

    public function testGroundPatchIsDeterministicAndTheSameOnBothCarriers(): void
    {
        foreach ( $this->shippedKits() as $kit ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::GROUND_BANDS as $on ) {
                    foreach ( self::GROUNDS as $ground ) {
                        $group = KitAdapters::patch( 'divi/group', $role, $on, $kit, $ground );
                        $this->assertSame( json_encode( $group ), json_encode( KitAdapters::patch( 'divi/group', $role, $on, $kit, $ground ) ) );
                        $this->assertSame( $group, KitAdapters::patch( 'divi/column', $role, $on, $kit, $ground ) );
                    }
                }
            }
        }
    }

    /**
     * Problems of one ground patch in one state ([] = fine), keyed "$where|<check>", against the plain card patch of the same
     * kit, role and band. Each state checks its own facts, so every cell is a distinct check:
     * - rest: the fill is the ground's token; each child ink the recipe may use on it (bg and surface on inverted,
     *   accent-text on accent) at least 4.5 on the fill; the fill distinct from the band (inverted at least INVERTED_LEAST,
     *   the measured least; accent at least 2.0:1, decorative); every frame side's colour is the fill token; the frame's
     *   sides, widths and styles, the radius, the padding and the rest shadow are the plain card's.
     * - hover: no hover fill; no hover frame colour (it would be the fill); the hover transform (lift) and the hover shadow
     *   are the plain card's; a panel writes no hover key at all.
     *
     * @param array<string,mixed> $patch
     * @param array<string,mixed> $plain
     * @param array<string,mixed> $lang
     * @return array<string,string>
     */
    public static function groundProblems( array $patch, array $plain, array $lang, string $role, string $on, string $ground, string $state, string $where ): array
    {
        $out    = [];
        $colors = (array) $lang['colors'];
        $rule   = self::GROUND_RULES[ $ground ] ?? null;
        if ( null === $rule ) {
            return [ "$where|ground" => "$where: unknown ground '$ground'" ];
        }
        [ $token, $inks, $least ] = $rule;
        $deco      = (array) ( $patch['module']['decoration'] ?? [] );
        $plainDeco = (array) ( $plain['module']['decoration'] ?? [] );
        $border    = (array) ( $deco['border']['desktop'] ?? [] );

        if ( 'hover' === $state ) {
            if ( array_key_exists( 'hover', (array) ( $deco['background']['desktop'] ?? [] ) ) ) {
                $out[ "$where|hover-fill" ] = "$where: a hover fill on a ground";
            }
            if ( array_key_exists( 'hover', $border ) ) {
                $out[ "$where|hover-frame" ] = "$where: a hover frame colour on a ground (it would be the fill)";
            }
            foreach ( [ 'transform', 'boxShadow' ] as $group ) {
                if ( ( $deco[ $group ]['desktop']['hover'] ?? null ) !== ( $plainDeco[ $group ]['desktop']['hover'] ?? null ) ) {
                    $out[ "$where|hover-$group" ] = "$where: the hover $group differs from the plain card on the $on band";
                }
            }
            if ( 'panel' === $role && str_contains( (string) json_encode( $deco ), '"hover"' ) ) {
                $out[ "$where|panel-hover" ] = "$where: a panel writes a hover key";
            }
            return $out;
        }

        $found = (string) ( $deco['background']['desktop']['value']['color'] ?? '' );
        if ( $token !== $found ) {
            return [ "$where|fill" => "$where: the $ground fill is '$found', not $token" ];
        }
        $band = (string) Colour::opaque( (string) $colors[ 'surface' === $on ? 'surface' : 'bg' ], (string) $colors['bg'] );
        $fill = self::opaqueOn( $token, $lang, $band );
        if ( null === $fill ) {
            return [ "$where|fill" => "$where: the fill does not resolve" ];
        }
        foreach ( $inks as $ink ) {
            $c = self::opaqueOn( '{{aied:color:' . $ink . '}}', $lang, $fill );
            $r = null === $c ? 0.0 : Colour::contrast( $c, $fill );
            if ( $r < 4.5 ) {
                $out[ "$where|ink-$ink" ] = sprintf( '%s: child ink %s on the %s fill %s is %.2f:1, needs 4.5', $where, $ink, $ground, $fill, $r );
            }
        }
        $r = Colour::contrast( $fill, $band );
        if ( $r < $least ) {
            $out[ "$where|band" ] = sprintf( '%s: the %s fill %s on the %s band %s is %.3f:1, needs %.3f', $where, $ground, $fill, $on, $band, $r, $least );
        }
        $styles = (array) ( $border['value']['styles'] ?? [] );
        if ( [] === $styles ) {
            $out[ "$where|frame" ] = "$where: no frame";
        }
        foreach ( $styles as $side => $s ) {
            if ( $token !== (string) ( $s['color'] ?? '' ) ) {
                $out[ "$where|frame-$side" ] = "$where: the $side frame colour is '" . (string) ( $s['color'] ?? '' ) . "', not the fill $token";
            }
        }
        $shape      = static fn ( array $d ): array => array_map( static fn ( $s ) => [ $s['width'] ?? null, $s['style'] ?? null ], (array) ( $d['border']['desktop']['value']['styles'] ?? [] ) );
        if ( $shape( $plainDeco ) !== $shape( $deco ) ) {
            $out[ "$where|frame-size" ] = "$where: the frame's sides, width or style differ from the plain card (the box would change size)";
        }
        if ( ( $deco['border']['desktop']['value']['radius'] ?? null ) !== ( $plainDeco['border']['desktop']['value']['radius'] ?? null ) ) {
            $out[ "$where|radius" ] = "$where: the radius differs from the plain card";
        }
        if ( ( $deco['spacing'] ?? null ) !== ( $plainDeco['spacing'] ?? null ) ) {
            $out[ "$where|spacing" ] = "$where: the padding differs from the plain card on the $on band";
        }
        if ( ( $deco['boxShadow']['desktop']['value'] ?? null ) !== ( $plainDeco['boxShadow']['desktop']['value'] ?? null ) ) {
            $out[ "$where|boxShadow" ] = "$where: the rest shadow differs from the plain card on the $on band";
        }
        return $out;
    }

    public function testTheStatedGroundBoundsAreTheMeasuredLeast(): void
    {
        // The least contrast of each ground's fill against the light and surface bands over the ten languages: the stated
        // fact the proof uses, so a palette change shows here as a change of the fact (never a silent new bound).
        $least = [ 'inverted' => INF, 'accent' => INF ];
        $where = [];
        foreach ( DesignLanguages::ids() as $langId ) {
            $lang   = (array) DesignLanguages::get( $langId );
            $colors = (array) $lang['colors'];
            foreach ( self::GROUND_BANDS as $on ) {
                $band = (string) Colour::opaque( (string) $colors[ 'surface' === $on ? 'surface' : 'bg' ], (string) $colors['bg'] );
                foreach ( self::GROUND_RULES as $ground => [ $token ] ) {
                    $r = Colour::contrast( (string) self::opaqueOn( $token, $lang, $band ), $band );
                    if ( $r < $least[ $ground ] ) {
                        $least[ $ground ] = $r;
                        $where[ $ground ] = "$langId $on";
                    }
                }
            }
        }
        $this->assertSame( self::INVERTED_LEAST, round( $least['inverted'], 3 ), 'the inverted bound is the measured least' );
        $this->assertSame( 'organic-warm light', $where['inverted'] );
        $this->assertGreaterThanOrEqual( self::INVERTED_LEAST, $least['inverted'] );
        $this->assertSame( 2.30, round( $least['accent'], 2 ), 'the accent ground\'s measured least (the bound is 2.0, decorative)' );
        $this->assertSame( 'playful-pastel light', $where['accent'] );
    }

    public function testNoUnreadableGroundInAnyKitLanguageRoleGroundBand(): void
    {
        $problems = [];
        $checked  = 0;
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( DesignLanguages::ids() as $langId ) {
                $lang = DesignLanguages::get( $langId );
                $this->assertIsArray( $lang );
                foreach ( self::ROLES as $role ) {
                    foreach ( self::GROUNDS as $ground ) {
                        foreach ( self::GROUND_BANDS as $on ) {
                            $patch = $this->patch( $kit, $role, $on, 'divi/group', $ground );
                            $plain = $this->patch( $kit, $role, $on );
                            foreach ( [ 'rest', 'hover' ] as $state ) {
                                ++$checked;
                                $problems = array_merge( $problems, self::groundProblems( $patch, $plain, $lang, $role, $on, $ground, $state, "$id|$langId|$role|$ground|$on|$state" ) );
                            }
                        }
                    }
                }
            }
        }
        // 40 kits x 10 languages x 2 roles x 2 grounds x 2 bands x 2 states (rest facts and hover facts).
        $this->assertSame( 6400, $checked );
        $this->assertSame( 40 * 10 * 2 * 2 * 2 * 2, $checked );
        $this->assertSame( [], array_values( $problems ), "unreadable or misshapen grounds (fix the ground rules in KitAdapters::card(), never the bound; no exceptions):\n" . implode( "\n", $problems ) );
    }

    /**
     * The featured plan's prominence proof (kit-limits Task 6, spec section 3): problems of the accent-ground card $featured
     * against the plain card $plain of the same kit on the LIGHT band, in one language ([] = fine), keyed "$where|<check>".
     * The featured plan must read as the neighbour plus a filled body, never as less:
     * - prominence: the featured fill's contrast against the band is STRICTLY greater than the plain card's fill, and at least
     *   2.0:1 (so the accent body is visible against the page on its own);
     * - one family: frame sides, widths and styles, radius, padding and rest shadow are the plain card's, and so are the
     *   hover lift and the hover shadow (a smaller frame, a different corner or a missing shadow would make it less visible).
     *
     * @param array<string,mixed> $featured
     * @param array<string,mixed> $plain
     * @param array<string,mixed> $lang
     * @return array<string,string>
     */
    public static function featuredProblems( array $featured, array $plain, array $lang, string $where ): array
    {
        $out    = [];
        $colors = (array) $lang['colors'];
        $band   = (string) Colour::opaque( (string) $colors['bg'], (string) $colors['bg'] );
        $deco   = (array) ( $featured['module']['decoration'] ?? [] );
        $pdeco  = (array) ( $plain['module']['decoration'] ?? [] );
        $fillOf = static fn ( array $d ): ?string => is_string( $d['background']['desktop']['value']['color'] ?? null )
            ? self::opaqueOn( $d['background']['desktop']['value']['color'], $lang, $band )
            : null;
        $fF = $fillOf( $deco );
        $fP = $fillOf( $pdeco );
        if ( null === $fF || null === $fP ) {
            return [ "$where|fill" => "$where: the featured card or the plain card has no resolvable fill" ];
        }
        $rF = Colour::contrast( $fF, $band );
        $rP = Colour::contrast( $fP, $band );
        if ( $rF <= $rP ) {
            $out[ "$where|prominence" ] = sprintf( '%s: the featured fill %s is %.3f:1 on the band, not above the plain card fill %s at %.3f:1', $where, $fF, $rF, $fP, $rP );
        }
        if ( $rF < 2.0 ) {
            $out[ "$where|floor" ] = sprintf( '%s: the featured fill %s is %.3f:1 on the band, needs 2.0', $where, $fF, $rF );
        }
        $shape = static fn ( array $d ): array => array_map( static fn ( $s ) => [ $s['width'] ?? null, $s['style'] ?? null ], (array) ( $d['border']['desktop']['value']['styles'] ?? [] ) );
        if ( $shape( $deco ) !== $shape( $pdeco ) ) {
            $out[ "$where|frame-size" ] = "$where: the featured frame's sides, width or style differ from the plain card";
        }
        $same = [
            'radius'      => static fn ( array $d ) => $d['border']['desktop']['value']['radius'] ?? null,
            'spacing'     => static fn ( array $d ) => $d['spacing'] ?? null,
            'boxShadow'   => static fn ( array $d ) => $d['boxShadow']['desktop']['value'] ?? null,
            'hover-lift'  => static fn ( array $d ) => $d['transform']['desktop']['hover'] ?? null,
            'hover-shadow' => static fn ( array $d ) => $d['boxShadow']['desktop']['hover'] ?? null,
        ];
        foreach ( $same as $name => $read ) {
            if ( $read( $deco ) !== $read( $pdeco ) ) {
                $out[ "$where|$name" ] = "$where: the featured card's $name differs from the plain card";
            }
        }
        return $out;
    }

    public function testTheFeaturedPlanIsMoreProminentThanItsNeighbourInEveryKitAndLanguage(): void
    {
        $problems = [];
        $checked  = 0;
        $weakest  = [ INF, '' ];
        foreach ( $this->shippedKits() as $id => $kit ) {
            // The featured plan is a `divi/column` carrying {"kit":"card","on":"light","ground":"accent"}; its neighbours carry the
            // same marker without a ground.
            $featured = $this->patch( $kit, 'card', 'light', 'divi/column', 'accent' );
            $plain    = $this->patch( $kit, 'card', 'light', 'divi/column' );
            foreach ( DesignLanguages::ids() as $langId ) {
                $lang = (array) DesignLanguages::get( $langId );
                ++$checked;
                $problems = array_merge( $problems, self::featuredProblems( $featured, $plain, $lang, "$id|$langId" ) );
                $colors   = (array) $lang['colors'];
                $band     = (string) Colour::opaque( (string) $colors['bg'], (string) $colors['bg'] );
                $margin   = Colour::contrast( (string) self::opaqueOn( '{{aied:color:accent}}', $lang, $band ), $band )
                    - Colour::contrast( (string) self::opaqueOn( '{{aied:color:surface}}', $lang, $band ), $band );
                if ( $margin < $weakest[0] ) {
                    $weakest = [ $margin, "$id|$langId" ];
                }
            }
        }
        // 40 kits x 10 languages (the light band, the card role, the column carrier).
        $this->assertSame( 400, $checked );
        $this->assertSame( [], array_values( $problems ), "the featured plan must read as the neighbour plus a filled body (fix the ground rules in KitAdapters::card(), never the bound; no exceptions):\n" . implode( "\n", $problems ) );
        $this->assertGreaterThan( 0.0, $weakest[0], 'the weakest cell still has the featured fill above the plain fill: ' . $weakest[1] );
    }

    public function testTheFeaturedCheckCatchesWhatItShould(): void
    {
        $kits  = $this->shippedKits();
        $kit   = $kits['soft-solid'];
        $lang  = (array) DesignLanguages::get( 'editorial' );
        $plain = $this->patch( $kit, 'card', 'light', 'divi/column' );
        $good  = $this->patch( $kit, 'card', 'light', 'divi/column', 'accent' );
        $this->assertSame( [], self::featuredProblems( $good, $plain, $lang, 'x' ) );
        // No fill of its own (the plain card as the featured one): not more prominent than itself.
        $this->assertArrayHasKey( 'x|prominence', self::featuredProblems( $plain, $plain, $lang, 'x' ) );
        // A featured fill that is no stronger than the plain card's: the inverted ground is fine, a surface fill is not.
        $surface = $good;
        $surface['module']['decoration']['background']['desktop']['value']['color'] = '{{aied:color:surface}}';
        $this->assertArrayHasKey( 'x|prominence', self::featuredProblems( $surface, $plain, $lang, 'x' ) );
        // A fill below 2.0:1 on the band (bg on bg) fails the floor.
        $faint = $good;
        $faint['module']['decoration']['background']['desktop']['value']['color'] = '{{aied:color:bg}}';
        $this->assertArrayHasKey( 'x|floor', self::featuredProblems( $faint, $plain, $lang, 'x' ) );
        // A smaller frame, another corner, other padding, a missing shadow or a missing lift make it less visible than its neighbour.
        $thin = $good;
        foreach ( (array) $thin['module']['decoration']['border']['desktop']['value']['styles'] as $side => $_ ) {
            $thin['module']['decoration']['border']['desktop']['value']['styles'][ $side ]['width'] = '0px';
        }
        $this->assertArrayHasKey( 'x|frame-size', self::featuredProblems( $thin, $plain, $lang, 'x' ) );
        $round = $good;
        $round['module']['decoration']['border']['desktop']['value']['radius'] = '0px';
        $this->assertArrayHasKey( 'x|radius', self::featuredProblems( $round, $plain, $lang, 'x' ) );
        $padded = $good;
        $padded['module']['decoration']['spacing']['desktop']['value']['padding'] = [ 'top' => '1px' ];
        $this->assertArrayHasKey( 'x|spacing', self::featuredProblems( $padded, $plain, $lang, 'x' ) );
        $lifted = $this->patch( $kits['soft-lift'], 'card', 'light', 'divi/column' );
        $flat   = $this->patch( $kits['soft-lift'], 'card', 'light', 'divi/column', 'accent' );
        $this->assertSame( [], self::featuredProblems( $flat, $lifted, $lang, 'x' ), 'a lift kit: the featured card lifts like its neighbour' );
        unset( $flat['module']['decoration']['transform'] );
        $this->assertArrayHasKey( 'x|hover-lift', self::featuredProblems( $flat, $lifted, $lang, 'x' ) );
        // A kit with a rest shadow (neo-brutal: hard shadow and a lift): a featured card without it is less visible.
        $hard     = $this->patch( $kits['neo-brutal'], 'card', 'light', 'divi/column' );
        $shadowed = $this->patch( $kits['neo-brutal'], 'card', 'light', 'divi/column', 'accent' );
        $this->assertSame( [], self::featuredProblems( $shadowed, $hard, $lang, 'x' ) );
        unset( $shadowed['module']['decoration']['boxShadow'] );
        $this->assertArrayHasKey( 'x|boxShadow', self::featuredProblems( $shadowed, $hard, $lang, 'x' ) );
        // A card with no resolvable fill is reported, never passed.
        $this->assertArrayHasKey( 'x|fill', self::featuredProblems( [], $plain, $lang, 'x' ) );
    }

    public function testTheGroundCheckCatchesWhatItShould(): void
    {
        $kits   = $this->shippedKits();
        $pastel = DesignLanguages::get( 'playful-pastel' );
        $mono   = DesignLanguages::get( 'tech-mono' );
        $this->assertIsArray( $pastel );
        $this->assertIsArray( $mono );
        $kit   = $kits['glass-edge'];
        $plain = $this->patch( $kit, 'card', 'light' );
        $good  = $this->patch( $kit, 'card', 'light', 'divi/group', 'inverted' );
        $this->assertSame( [], self::groundProblems( $good, $plain, $mono, 'card', 'light', 'inverted', 'hover', 'x' ) );
        // A plain card patch is not a ground (wrong fill).
        $this->assertArrayHasKey( 'x|fill', self::groundProblems( $plain, $plain, $mono, 'card', 'light', 'inverted', 'rest', 'x' ) );
        // A hover fill is refused.
        $hoverFill = $good;
        $hoverFill['module']['decoration']['background']['desktop']['hover'] = [ 'color' => '{{aied:color:bg}}' ];
        $this->assertArrayHasKey( 'x|hover-fill', self::groundProblems( $hoverFill, $plain, $mono, 'card', 'light', 'inverted', 'hover', 'x' ) );
        $this->assertSame( [], self::groundProblems( $hoverFill, $plain, $mono, 'card', 'light', 'inverted', 'rest', 'x' ), 'a hover fact is checked in the hover state only' );
        // A frame in another colour than the fill, and a hover frame colour (the plain card of glass-edge has one).
        $frame = $good;
        $frame['module']['decoration']['border']['desktop']['value']['styles']['all']['color'] = '{{aied:color:border}}';
        $this->assertArrayHasKey( 'x|frame-all', self::groundProblems( $frame, $plain, $mono, 'card', 'light', 'inverted', 'rest', 'x' ) );
        $this->assertArrayHasKey( 'hover', $plain['module']['decoration']['border']['desktop'], 'glass-edge\'s plain card darkens its frame on hover' );
        $hover = $good;
        $hover['module']['decoration']['border']['desktop']['hover'] = $plain['module']['decoration']['border']['desktop']['hover'];
        $this->assertArrayHasKey( 'x|hover-frame', self::groundProblems( $hover, $plain, $mono, 'card', 'light', 'inverted', 'hover', 'x' ) );
        // A panel with a hover key, a padding or shadow that is not the plain card's, a frame of another width.
        $panelPlain = $this->patch( $kits['soft-lift'], 'panel', 'light' );
        $panel      = $this->patch( $kits['soft-lift'], 'panel', 'light', 'divi/group', 'accent' );
        $this->assertSame( [], self::groundProblems( $panel, $panelPlain, $pastel, 'panel', 'light', 'accent', 'hover', 'x' ) );
        $lifted = $panel;
        $lifted['module']['decoration']['transform'] = [ 'desktop' => [ 'hover' => [ 'translate' => [ 'y' => '-4px' ] ] ] ];
        $out = self::groundProblems( $lifted, $panelPlain, $pastel, 'panel', 'light', 'accent', 'hover', 'x' );
        $this->assertArrayHasKey( 'x|panel-hover', $out );
        $this->assertArrayHasKey( 'x|hover-transform', $out );
        // A card whose lift or hover shadow is not the plain card's is caught in the hover state.
        $liftPlain = $this->patch( $kits['neo-brutal'], 'card', 'light' );
        $noLift    = $this->patch( $kits['neo-brutal'], 'card', 'light', 'divi/group', 'inverted' );
        $this->assertSame( [], self::groundProblems( $noLift, $liftPlain, $mono, 'card', 'light', 'inverted', 'hover', 'x' ) );
        unset( $noLift['module']['decoration']['transform'] );
        $this->assertArrayHasKey( 'x|hover-transform', self::groundProblems( $noLift, $liftPlain, $mono, 'card', 'light', 'inverted', 'hover', 'x' ) );
        $hoverShadow = $this->patch( $kits['soft-lift'], 'card', 'light', 'divi/group', 'accent' );
        $hoverShadow['module']['decoration']['boxShadow'] = [ 'desktop' => [ 'hover' => [ 'style' => 'preset1' ] ] ];
        $this->assertArrayHasKey( 'x|hover-boxShadow', self::groundProblems( $hoverShadow, $this->patch( $kits['soft-lift'], 'card', 'light' ), $pastel, 'card', 'light', 'accent', 'hover', 'x' ) );
        $padded = $good;
        unset( $padded['module']['decoration']['spacing'] );
        $this->assertArrayHasKey( 'x|spacing', self::groundProblems( $padded, $plain, $mono, 'card', 'light', 'inverted', 'rest', 'x' ) );
        $wide = $good;
        $wide['module']['decoration']['border']['desktop']['value']['styles']['all']['width'] = '9px';
        $this->assertArrayHasKey( 'x|frame-size', self::groundProblems( $wide, $plain, $mono, 'card', 'light', 'inverted', 'rest', 'x' ) );
        // The inks: a language whose bg is too close to its text fails the inverted ground (and the band distinction);
        // an accent ground whose accent-text is close to the accent fails; an accent equal to the band fails.
        $grey                   = $mono;
        $grey['colors']['bg']   = $grey['colors']['text'];
        $out                    = self::groundProblems( $good, $plain, $grey, 'card', 'light', 'inverted', 'rest', 'x' );
        $this->assertArrayHasKey( 'x|ink-bg', $out );
        $this->assertArrayHasKey( 'x|band', $out );
        $accent = $this->patch( $kit, 'card', 'surface', 'divi/group', 'accent' );
        $flat                            = $pastel;
        $flat['colors']['accent_text']   = $flat['colors']['accent'];
        $this->assertArrayHasKey( 'x|ink-accent-text', self::groundProblems( $accent, $this->patch( $kit, 'card', 'surface' ), $flat, 'card', 'surface', 'accent', 'rest', 'x' ) );
        $same                       = $pastel;
        $same['colors']['surface']  = $same['colors']['accent'];
        $this->assertArrayHasKey( 'x|band', self::groundProblems( $accent, $this->patch( $kit, 'card', 'surface' ), $same, 'card', 'surface', 'accent', 'rest', 'x' ) );
        // An unknown ground is reported, never judged as one of the known ones.
        $this->assertArrayHasKey( 'x|ground', self::groundProblems( $good, $plain, $mono, 'card', 'light', 'x', 'rest', 'x' ) );
    }

    public function testGroundFillFrameAndHoverPins(): void
    {
        $kits = $this->shippedKits();
        // An inverted card of a border-hairline kit with fill-swap/invert: the frame is the fill, no hover frame.
        $deco = $this->deco( $kits['glass-edge'], 'card', 'light', 'divi/group', 'inverted' );
        $this->assertSame( [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:text}}' ] ] ], $deco['background'] );
        $this->assertSame( [ 'all' => [ 'width' => '1px', 'style' => 'solid', 'color' => '{{aied:color:text}}' ] ], $deco['border']['desktop']['value']['styles'] );
        $this->assertSame( [ 'value' ], array_keys( $deco['border']['desktop'] ) );
        // An accent tile on a surface band: the accent fill, an accent frame at the kit width (0px becomes 1px).
        $deco = $this->deco( $kits['quiet-solid'], 'card', 'surface', 'divi/column', 'accent' );
        $this->assertSame( [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:accent}}' ] ] ], $deco['background'] );
        $this->assertSame( [ 'all' => [ 'width' => '1px', 'style' => 'solid', 'color' => '{{aied:color:accent}}' ] ], $deco['border']['desktop']['value']['styles'] );
        $this->assertSame( [ 'desktop' => [ 'value' => [ 'padding' => '{{aied:kit:space-card}}' ] ] ], $deco['spacing'] );
        // An underline kit: the bottom side in the fill colour.
        $deco = $this->deco( $kits['underline-link'], 'panel', 'light', 'divi/group', 'inverted' );
        $this->assertSame(
            [ 'all' => [ 'width' => '0px', 'style' => 'solid', 'color' => '{{aied:color:text}}' ], 'bottom' => [ 'width' => '2px', 'style' => 'solid', 'color' => '{{aied:color:text}}' ] ],
            $deco['border']['desktop']['value']['styles']
        );
        // A lift kit keeps its lift on an inverted card; a panel never lifts.
        $this->assertSame( [ 'desktop' => [ 'hover' => [ 'translate' => [ 'y' => '-4px' ] ] ] ], $this->deco( $kits['neo-brutal'], 'card', 'light', 'divi/group', 'inverted' )['transform'] );
        $this->assertArrayNotHasKey( 'transform', $this->deco( $kits['neo-brutal'], 'panel', 'light', 'divi/group', 'inverted' ) );
    }

    /**
     * A colour value of a patch or recipe as an opaque hex on $ground: a colour token resolved by the real pipeline
     * (RecipeTokens, alpha forms included) and composited over $ground; null when it does not resolve to a colour.
     */
    public static function opaqueOn( string $value, array $lang, string $ground ): ?string
    {
        return Colour::opaque( RecipeTokens::resolve( $value, $lang ), $ground );
    }

    /**
     * Contrast problems of one card patch in one language and state ([] = fine), keyed "$where|<check>":
     * the fill is the `surface` or `bg` token (never transparent, never another role); `text` and `muted` on the fill
     * and `accent-text` on `accent` (the pairs card children use) at least 4.5; the frame (the bottom side of an
     * underline card, else `styles.all`; the hover colour when the patch writes one) at least 3.0 against the fill,
     * unless it is the `border` hairline (decorative, exempt). Every other frame role is judged, so a new one fails closed.
     *
     * @param array<string,mixed> $patch
     * @param array<string,mixed> $lang
     * @return array<string,string>
     */
    public static function cardProblems( array $patch, array $lang, string $state, string $where ): array
    {
        $out    = [];
        $colors = (array) $lang['colors'];
        $deco   = (array) ( $patch['module']['decoration'] ?? [] );
        $token  = (string) ( $deco['background']['desktop']['value']['color'] ?? '' );
        if ( ! in_array( $token, [ '{{aied:color:surface}}', '{{aied:color:bg}}' ], true ) ) {
            return [ "$where|fill" => "$where: the card fill is '$token', not the surface or bg token" ];
        }
        // `surface` is judged as painted over the language's bg (a translucent surface stays honest).
        $fill = self::opaqueOn( $token, $lang, (string) $colors['bg'] );
        if ( null === $fill ) {
            return [ "$where|fill" => "$where: the card fill does not resolve" ];
        }
        foreach ( [ 'text' => [ 'text', $fill ], 'muted' => [ 'muted', $fill ], 'accent-text' => [ 'accent_text', (string) $colors['accent'] ] ] as $name => [ $key, $ground ] ) {
            $r = Colour::contrast( (string) Colour::opaque( (string) $colors[ $key ], $ground ), $ground );
            if ( $r < 4.5 ) {
                $out[ "$where|$name" ] = sprintf( '%s: %s on %s is %.2f:1, needs 4.5', $where, $name, $ground, $r );
            }
        }
        $value = (array) ( $deco['border']['desktop']['value']['styles'] ?? [] );
        $side  = (array) ( $value['bottom'] ?? $value['all'] ?? [] );
        $frame = (string) ( $side['color'] ?? '' );
        if ( 'hover' === $state && isset( $deco['border']['desktop']['hover']['styles']['all']['color'] ) ) {
            $frame = (string) $deco['border']['desktop']['hover']['styles']['all']['color'];
        }
        if ( '{{aied:color:border}}' !== $frame ) {
            $edge = self::opaqueOn( $frame, $lang, $fill );
            $r    = null === $edge ? 0.0 : Colour::contrast( $edge, $fill );
            if ( $r < 3.0 ) {
                $out[ "$where|frame" ] = sprintf( '%s: frame %s (%s) on fill %s is %.2f:1, needs 3.0', $where, $frame, (string) $edge, $fill, $r );
            }
        }
        return $out;
    }

    public function testNoUnreadableCardInAnyKitLanguageRoleBand(): void
    {
        $problems = [];
        $checked  = 0;
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( DesignLanguages::ids() as $langId ) {
                $lang = DesignLanguages::get( $langId );
                $this->assertIsArray( $lang );
                foreach ( self::ROLES as $role ) {
                    foreach ( self::SURFACES as $on ) {
                        $patch = $this->patch( $kit, $role, $on );
                        foreach ( [ 'rest', 'hover' ] as $state ) {
                            ++$checked;
                            $problems = array_merge( $problems, self::cardProblems( $patch, $lang, $state, "$id|$langId|$role|$on|$state" ) );
                        }
                    }
                }
            }
        }
        $this->assertSame( 40 * 10 * 2 * 4 * 2, $checked );
        $this->assertSame( [], array_values( $problems ), "unreadable cards (fix the card role table in KitAdapters::card(), never the bound; no exceptions):\n" . implode( "\n", $problems ) );
    }

    public function testTheCardContrastCheckCatchesWhatItShould(): void
    {
        $pastel = DesignLanguages::get( 'playful-pastel' );
        $this->assertIsArray( $pastel );
        $good = $this->patch( $this->shippedKits()['quiet-solid'], 'card', 'light' );
        $this->assertSame( [], self::cardProblems( $good, $pastel, 'rest', 'x' ) );
        // A fill that is not surface or bg (an accent card) is refused outright, so is a transparent one.
        foreach ( [ '{{aied:color:accent}}', KitAdapters::TRANSPARENT ] as $fill ) {
            $bad = $good;
            $bad['module']['decoration']['background']['desktop']['value']['color'] = $fill;
            $this->assertArrayHasKey( 'x|fill', self::cardProblems( $bad, $pastel, 'rest', 'x' ) );
        }
        // An accent frame in a language whose accent is light (playful-pastel: about 2.45:1 on its surface) is caught,
        // at rest and as a hover colour; a `text` frame is fine; the `border` hairline is exempt even at about 1.3:1.
        $accent = $good;
        $accent['module']['decoration']['border']['desktop']['value']['styles']['all']['color'] = '{{aied:color:accent}}';
        $this->assertArrayHasKey( 'x|frame', self::cardProblems( $accent, $pastel, 'rest', 'x' ) );
        $hover = $good;
        $hover['module']['decoration']['border']['desktop']['hover'] = [ 'styles' => [ 'all' => [ 'color' => '{{aied:color:accent}}' ] ] ];
        $this->assertSame( [], self::cardProblems( $hover, $pastel, 'rest', 'x' ) );
        $this->assertArrayHasKey( 'x|frame', self::cardProblems( $hover, $pastel, 'hover', 'x' ) );
        $text = $good;
        $text['module']['decoration']['border']['desktop']['value']['styles']['all']['color'] = '{{aied:color:text}}';
        $this->assertSame( [], self::cardProblems( $text, $pastel, 'rest', 'x' ) );
        // The bottom side of an underline card is the one judged.
        $under = $text;
        $under['module']['decoration']['border']['desktop']['value']['styles']['bottom'] = [ 'width' => '2px', 'style' => 'solid', 'color' => '{{aied:color:accent}}' ];
        $this->assertArrayHasKey( 'x|frame', self::cardProblems( $under, $pastel, 'rest', 'x' ) );
        // A language whose muted text is unreadable on its surface is caught.
        $grey                      = $pastel;
        $grey['colors']['muted']   = $grey['colors']['surface'];
        $this->assertArrayHasKey( 'x|muted', self::cardProblems( $good, $grey, 'rest', 'x' ) );
        // An alpha token is composited over the ground before it is judged.
        $this->assertSame( '#808080', self::opaqueOn( '{{aied:color:text:50}}', [ 'colors' => [ 'text' => '#000000' ] ], '#ffffff' ) );
    }

    public function testTheDeadOnModuleGuardReportsAButtonPathAndACarrierScopedLine(): void
    {
        // The shipped list: a button using its element padding is reported; the same path on a group is not.
        $dead = DeadOnModule::load();
        $btn  = '<!-- wp:divi/button {"button":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"14px"}}}}}}} /-->';
        $this->assertSame( [ 'divi/button: button.decoration.spacing.{bp}.value.padding.top (dead: kb-padding-element)' ], $dead->violations( $btn ) );
        $this->assertSame( [], $dead->violations( str_replace( 'wp:divi/button', 'wp:divi/group', $btn ) ) );
        // A carrier-scoped line (a temporary list: the shipped one has none for cards) applies to that carrier only.
        $file = tempnam( sys_get_temp_dir(), 'dead' );
        $this->assertIsString( $file );
        file_put_contents( $file, "# test list\ndivi/group|module.decoration.boxShadow|planted-evidence|planted for the test\n" );
        try {
            $scoped = DeadOnModule::load( $file );
            $kit    = $this->shippedKits()['chunky-pill-offset'];
            $group  = KitResolver::resolve( $this->kitMarkup( 'divi/group', 'card', 'light' ), $kit );
            $column = KitResolver::resolve( $this->kitMarkup( 'divi/column', 'card', 'light' ), $kit );
            $this->assertStringContainsString( '"boxShadow"', $group );
            $found = $scoped->violations( $group );
            $this->assertNotSame( [], $found );
            foreach ( $found as $v ) {
                $this->assertStringStartsWith( 'divi/group: module.decoration.boxShadow.', $v );
                $this->assertStringEndsWith( '(dead: planted-evidence)', $v );
            }
            $this->assertSame( [], $scoped->violations( $column ), 'a divi/group line does not apply to a column card' );
        } finally {
            unlink( $file );
        }
    }

    public function testTheResolverRoleTableIsAppliedInverted(): void
    {
        $table = ( new \ReflectionClass( KitResolver::class ) )->getConstant( 'ROLE_BLOCKS' );
        $this->assertIsArray( $table );
        $inverted = [];
        foreach ( KitAdapters::APPLIED as $block => $roles ) {
            foreach ( $roles as $role ) {
                $inverted[ $role ][] = $block;
            }
        }
        ksort( $inverted );
        ksort( $table );
        $this->assertSame( $inverted, $table, 'KitResolver::ROLE_BLOCKS is KitAdapters::APPLIED by role' );
    }

    public function testAppliedNamesEveryBlockAndRoleThePatchAnswers(): void
    {
        $this->assertSame(
            [
                'divi/button'    => [ 'control', 'control-secondary' ],
                'divi/group'     => [ 'card', 'panel', 'rule', 'rule-strong', 'chip' ],
                'divi/column'    => [ 'card', 'panel', 'rule', 'rule-strong' ],
                'divi/accordion' => [ 'item', 'item-rule' ],
                'divi/toggle'    => [ 'item', 'item-rule' ],
                // Forms adapter T2: the map grows by one block and one role, on purpose (the pin follows the shipped adapters).
                'divi/contact-form' => [ 'form' ],
                // Rules and chips adapter T3 (plan 2026-10-21): the map grows on purpose, by the rule roles on group and column,
                // `chip` on group, and two new carriers (the divider and the icon).
                // carriers adapter: [ 'rule', 'rule-strong' ] -> [ 'rule', 'rule-strong', 'rule-accent' ] on the divider (the new accent mark).
                'divi/divider' => [ 'rule', 'rule-strong', 'rule-accent' ],
                'divi/icon'    => [ 'chip' ],
                // carriers adapter: the map grows by three carriers on purpose (plan 2026-10-24, Task 3).
                'divi/testimonial'    => [ 'card', 'panel' ],
                'divi/blurb'          => [ 'chip' ],
                'divi/number-counter' => [ 'rule', 'rule-strong' ],
            ],
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
