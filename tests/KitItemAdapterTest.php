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
 * The item adapter: a kit's decisions become the attributes of an accordion (one marker styles every item) or a toggle,
 * roles `item` (a boxed item, four bands) and `item-rule` (a rule row, light and surface only). Only the paths the effect
 * probe passed on that carrier (fixtures/shapes/kit-item-vocabulary.md) are written, every state key Divi would otherwise
 * fill with its own non-transparent default is written for both states, and the merged block passes the real gate. The
 * contrast proof is a separate task (KitItemContrastTest).
 */
class KitItemAdapterTest extends TestCase
{
    private const CARRIERS = [ 'divi/accordion', 'divi/toggle' ];
    private const BANDS    = [
        'item'      => [ 'light', 'surface', 'accent', 'dark' ],
        'item-rule' => [ 'light', 'surface' ],
    ];

    private const COLOR_ROLES = [ 'bg', 'surface', 'text', 'muted', 'accent', 'accent-text', 'border', 'inverse', 'overlay' ];

    /** Frame leaves (the vocabulary's frame width/style/colour and underline rows). */
    private const FRAME_ALL    = [
        'module.decoration.border.desktop.value.styles.all.width',
        'module.decoration.border.desktop.value.styles.all.style',
        'module.decoration.border.desktop.value.styles.all.color',
    ];
    private const FRAME_BOTTOM = [
        'module.decoration.border.desktop.value.styles.bottom.width',
        'module.decoration.border.desktop.value.styles.bottom.style',
        'module.decoration.border.desktop.value.styles.bottom.color',
    ];

    /**
     * The state keys the adapter must write on each carrier, both states, so none falls back to Divi's defaults (closed fill
     * #f4f4f4, open #fff, closed title #666, open #333, icon #ccc, answer #666): the vocabulary's v1 rows, per module.
     */
    private const STATE_KEYS = [
        'divi/accordion' => [
            'openToggle.decoration.background.desktop.value.color',
            'closedToggle.decoration.background.desktop.value.color',
            'openToggle.decoration.font.font.desktop.value.color',
            'closedToggle.decoration.font.font.desktop.value.color',
            'closedToggleIcon.decoration.icon.desktop.value.color',
            'content.decoration.bodyFont.body.font.desktop.value.color',
        ],
        'divi/toggle'    => [
            'openToggle.decoration.background.desktop.value.color',
            'closedToggle.decoration.background.desktop.value.color',
            'openToggle.decoration.font.font.desktop.value.color',
            'closedTitle.decoration.font.font.desktop.value.color',
            'closedToggleIcon.decoration.icon.desktop.value.color',
            'openToggleIcon.decoration.icon.desktop.value.color',
            'content.decoration.bodyFont.body.font.desktop.value.color',
        ],
    ];

    /** The shadow leaves the probe passed (ki-shadow-*), rest value only. */
    private const SHADOW_LEAVES = [
        'module.decoration.boxShadow.desktop.value.style',
        'module.decoration.boxShadow.desktop.value.horizontal',
        'module.decoration.boxShadow.desktop.value.vertical',
        'module.decoration.boxShadow.desktop.value.blur',
        'module.decoration.boxShadow.desktop.value.color',
    ];

    private const RADIUS_CORNERS = [
        'module.decoration.border.desktop.value.radius.topLeft',
        'module.decoration.border.desktop.value.radius.topRight',
        'module.decoration.border.desktop.value.radius.bottomRight',
        'module.decoration.border.desktop.value.radius.bottomLeft',
    ];

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
     * Every shipped kit plus variants of a full-frame and an underline kit over every hover x border colour x shadow pair x
     * width the schema allows, so every branch of the item adapter runs.
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

    /** @param array<string,mixed> $kit @return array<string,mixed> */
    private function patch( array $kit, string $role, string $on, string $carrier ): array
    {
        $patch = KitAdapters::patch( $carrier, $role, $on, $kit );
        $this->assertIsArray( $patch, "{$kit['id']} $role on $on ($carrier) must have a patch" );
        return $patch;
    }

    /** Dotted path => value of every leaf of $node. @return array<string,mixed> */
    private static function leaves( array $node, string $prefix = '' ): array
    {
        $out = [];
        foreach ( $node as $k => $v ) {
            $path = '' === $prefix ? (string) $k : $prefix . '.' . $k;
            if ( is_array( $v ) && [] !== $v ) {
                $out += self::leaves( $v, $path );
            } else {
                $out[ $path ] = $v;
            }
        }
        return $out;
    }

    private function itemMarkup( string $carrier, string $role, string $on ): string
    {
        $marker = '"aied":{"kit":"' . $role . '","on":"' . $on . '"}';
        $item   = 'divi/accordion' === $carrier
            ? '<!-- wp:divi/accordion {"builderVersion":"5.15.1",' . $marker . '} -->'
                . '<!-- wp:divi/accordion-item {"title":{"innerContent":{"desktop":{"value":"Q one"}}},"content":{"innerContent":{"desktop":{"value":"<p>A one</p>"}}},"module":{"advanced":{"open":{"desktop":{"value":"on"}}}},"builderVersion":"5.15.1"} /-->'
                . '<!-- wp:divi/accordion-item {"title":{"innerContent":{"desktop":{"value":"Q two"}}},"content":{"innerContent":{"desktop":{"value":"<p>A two</p>"}}},"builderVersion":"5.15.1"} /-->'
                . '<!-- /wp:divi/accordion -->'
            : '<!-- wp:divi/toggle {"title":{"innerContent":{"desktop":{"value":"Q"}}},"content":{"innerContent":{"desktop":{"value":"<p>A</p>"}}},"builderVersion":"5.15.1",' . $marker . '} /-->';
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.15.1"} --><!-- wp:divi/row {"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1"} -->'
            . $item . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    /** $markup with an item marker added to every block of $carrier. */
    private static function markEvery( string $markup, string $carrier, string $role, string $on ): string
    {
        return (string) preg_replace_callback(
            '/(<!--\s+wp:(' . preg_quote( $carrier, '/' ) . ')\s+)(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\})(\s+\/?-->)/s',
            static function ( array $m ) use ( $role, $on ): string {
                $attrs         = json_decode( $m[3], true );
                $attrs['aied'] = [ 'kit' => $role, 'on' => $on ];
                return $m[1] . RecipeTokens::encodeJson( $attrs ) . $m[4];
            },
            $markup
        );
    }

    public function testEveryEmittedItemPathIsInTheRegistryAndNotDeadOnItsCarrier(): void
    {
        $registry = ShapeRegistry::load();
        $dead     = DeadOnModule::load();
        $missing  = [];
        $killed   = [];
        $checked  = 0;
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::CARRIERS as $carrier ) {
                foreach ( self::BANDS as $role => $bands ) {
                    foreach ( $bands as $on ) {
                        $markup = KitResolver::resolve( $this->itemMarkup( $carrier, $role, $on ), $kit );
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
                        $json = (string) json_encode( $this->patch( $kit, $role, $on, $carrier ) );
                        foreach ( [ 'tablet', 'phone', 'tabletWide', 'phoneWide', 'hover', 'sticky' ] as $state ) {
                            $this->assertStringNotContainsString( "\"$state\"", $json, "only desktop rest values are written ($label $role on $on)" );
                        }
                        ++$checked;
                    }
                }
            }
        }
        $this->assertGreaterThan( 40 * 2 * 6, $checked );
        $this->assertSame( [], $missing, 'the item adapter emits paths the registry does not know' );
        $this->assertSame( [], array_keys( $killed ), 'the item adapter emits paths that are dead on their carrier' );
    }

    public function testEveryLeafIsAProbedPathOnItsCarrier(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::CARRIERS as $carrier ) {
                $allowed = array_merge( self::STATE_KEYS[ $carrier ], self::FRAME_ALL, self::FRAME_BOTTOM, self::SHADOW_LEAVES, self::RADIUS_CORNERS, [ 'module.decoration.border.desktop.value.radius' ] );
                if ( 'divi/toggle' === $carrier ) {
                    $allowed[] = 'module.decoration.spacing.desktop.value.padding';
                }
                foreach ( self::BANDS as $role => $bands ) {
                    foreach ( $bands as $on ) {
                        $extra = array_diff( array_keys( self::leaves( $this->patch( $kit, $role, $on, $carrier ) ) ), $allowed );
                        $this->assertSame( [], array_values( $extra ), "$label $role on $on ($carrier): only probe-PASS paths" );
                    }
                }
            }
        }
    }

    /**
     * What an item patch must never lack or carry: a state key missing (it would fall back to Divi's grey default), a
     * shadow or padding on a rule row, padding or an open icon on an accordion.
     *
     * @param array<string,mixed> $patch
     * @return list<string>
     */
    private static function itemProblems( array $patch, string $carrier, string $role ): array
    {
        $leaves   = self::leaves( $patch );
        $problems = [];
        foreach ( array_merge( self::STATE_KEYS[ $carrier ], self::FRAME_ALL ) as $key ) {
            if ( ! array_key_exists( $key, $leaves ) ) {
                $problems[] = "missing $key";
            }
        }
        $deco = (array) ( $patch['module']['decoration'] ?? [] );
        if ( 'item-rule' === $role && isset( $deco['boxShadow'] ) ) {
            $problems[] = 'a rule row with a shadow';
        }
        if ( ( 'item-rule' === $role || 'divi/accordion' === $carrier ) && isset( $deco['spacing'] ) ) {
            $problems[] = 'padding where none is written';
        }
        if ( 'divi/accordion' === $carrier && isset( $patch['openToggleIcon'] ) ) {
            $problems[] = 'an open icon on an accordion';
        }
        return $problems;
    }

    public function testTheItemCheckCatchesAPlantedFault(): void
    {
        $kit  = $this->shippedKits()['chunky-pill-offset']; // a hard shadow, so its item has a boxShadow to plant
        $good = $this->patch( $kit, 'item', 'light', 'divi/toggle' );
        $this->assertSame( [], self::itemProblems( $good, 'divi/toggle', 'item' ) );
        $this->assertArrayHasKey( 'boxShadow', $good['module']['decoration'] );

        $missing = $good;
        unset( $missing['closedTitle'] );
        $this->assertSame( [ 'missing closedTitle.decoration.font.font.desktop.value.color' ], self::itemProblems( $missing, 'divi/toggle', 'item' ) );

        $rule                                   = $this->patch( $kit, 'item-rule', 'light', 'divi/toggle' );
        $rule['module']['decoration']['boxShadow'] = $good['module']['decoration']['boxShadow'];
        $this->assertSame( [ 'a rule row with a shadow' ], self::itemProblems( $rule, 'divi/toggle', 'item-rule' ) );

        $accordion                   = $this->patch( $kit, 'item', 'light', 'divi/accordion' );
        $accordion['openToggleIcon'] = $good['openToggleIcon'];
        $accordion['module']['decoration']['spacing'] = $good['module']['decoration']['spacing'];
        $this->assertSame( [ 'padding where none is written', 'an open icon on an accordion' ], self::itemProblems( $accordion, 'divi/accordion', 'item' ) );
    }

    public function testEveryStateKeyIsWrittenForBothStatesOnBothCarriers(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::CARRIERS as $carrier ) {
                foreach ( self::BANDS as $role => $bands ) {
                    foreach ( $bands as $on ) {
                        $patch  = $this->patch( $kit, $role, $on, $carrier );
                        $this->assertSame( [], self::itemProblems( $patch, $carrier, $role ), "$label $role on $on ($carrier)" );
                        $leaves = self::leaves( $patch );
                        foreach ( array_merge( self::STATE_KEYS[ $carrier ], self::FRAME_ALL ) as $key ) {
                            $this->assertArrayHasKey( $key, $leaves, "$label $role on $on ($carrier): $key must be written (no fallback to Divi's default)" );
                        }
                        $fill = $leaves['openToggle.decoration.background.desktop.value.color'];
                        $this->assertSame( $fill, $leaves['closedToggle.decoration.background.desktop.value.color'], 'both states share the fill (no open-state emphasis)' );
                        $titleClosed = 'divi/toggle' === $carrier ? 'closedTitle.decoration.font.font.desktop.value.color' : 'closedToggle.decoration.font.font.desktop.value.color';
                        $this->assertSame( '{{aied:color:text}}', $leaves['openToggle.decoration.font.font.desktop.value.color'] );
                        $this->assertSame( '{{aied:color:text}}', $leaves[ $titleClosed ] );
                        $this->assertSame( '{{aied:color:text}}', $leaves['closedToggleIcon.decoration.icon.desktop.value.color'] );
                        $this->assertSame( '{{aied:color:muted}}', $leaves['content.decoration.bodyFont.body.font.desktop.value.color'] );
                        if ( 'divi/toggle' === $carrier ) {
                            $this->assertSame( '{{aied:color:text}}', $leaves['openToggleIcon.decoration.icon.desktop.value.color'], 'a toggle writes both icon states' );
                            $this->assertArrayNotHasKey( 'closedToggle.decoration.font.font.desktop.value.color', $leaves, 'a toggle\'s closed title is closedTitle' );
                        } else {
                            $this->assertArrayNotHasKey( 'openToggleIcon.decoration.icon.desktop.value.color', $leaves, 'an accordion has no open icon' );
                            $this->assertArrayNotHasKey( 'closedTitle.decoration.font.font.desktop.value.color', $leaves, 'an accordion\'s closed title is closedToggle' );
                        }
                    }
                }
            }
        }
    }

    public function testFillIsSurfaceForAnItemAndTheBandForARuleRow(): void
    {
        $expect = [
            'item'      => [ 'light' => 'surface', 'surface' => 'bg', 'accent' => 'surface', 'dark' => 'surface' ],
            'item-rule' => [ 'light' => 'bg', 'surface' => 'surface' ],
        ];
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::CARRIERS as $carrier ) {
                foreach ( $expect as $role => $bands ) {
                    foreach ( $bands as $on => $fill ) {
                        $p = $this->patch( $kit, $role, $on, $carrier );
                        foreach ( [ 'openToggle', 'closedToggle' ] as $state ) {
                            $this->assertSame( [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:' . $fill . '}}' ] ] ], $p[ $state ]['decoration']['background'], "$id $role on $on ($carrier) $state" );
                        }
                        $this->assertArrayNotHasKey( 'background', $p['module']['decoration'], 'the module itself is never filled' );
                    }
                }
            }
        }
    }

    public function testFrameRadiusPaddingAndShadowRulesPerRole(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            $border = $kit['border'];
            $width  = '0px' === $border['width'] ? '1px' : $border['width'];
            $colour = '{{aied:color:' . ( [ 'same' => 'border', 'inverse' => 'text', 'accent' => 'text' ][ $border['color'] ] ?? $border['color'] ) . '}}';
            $under  = 'bottom' === ( $border['sides'] ?? 'all' );
            foreach ( self::CARRIERS as $carrier ) {
                foreach ( self::BANDS as $role => $bands ) {
                    foreach ( $bands as $on ) {
                        $deco = $this->patch( $kit, $role, $on, $carrier )['module']['decoration'];
                        $rule = 'item-rule' === $role;
                        $styles = $deco['border']['desktop']['value']['styles'];
                        if ( $rule || $under ) {
                            $this->assertSame(
                                [
                                    'all'    => [ 'width' => '0px', 'style' => $border['style'], 'color' => $colour ],
                                    'bottom' => [ 'width' => $width, 'style' => $border['style'], 'color' => $colour ],
                                ],
                                $styles,
                                "$label $role on $on ($carrier): the underline form"
                            );
                        } else {
                            $this->assertSame( [ 'all' => [ 'width' => $width, 'style' => $border['style'], 'color' => $colour ] ], $styles, "$label $role on $on ($carrier)" );
                        }
                        $this->assertSame(
                            $rule ? [ 'topLeft' => '0px', 'topRight' => '0px', 'bottomRight' => '0px', 'bottomLeft' => '0px' ] : '{{aied:kit:radius-card}}',
                            $deco['border']['desktop']['value']['radius'],
                            "$label $role on $on ($carrier): radius"
                        );
                        $this->assertSame( [ 'desktop' ], array_keys( $deco['border'] ), 'no hover frame on items' );
                        if ( ! $rule && 'divi/toggle' === $carrier ) {
                            $this->assertSame( [ 'desktop' => [ 'value' => [ 'padding' => '{{aied:kit:space-card}}' ] ] ], $deco['spacing'] );
                        } else {
                            $this->assertArrayNotHasKey( 'spacing', $deco, "$label $role on $on ($carrier): an accordion's padding would pad the answer; a rule row keeps the recipe's" );
                        }
                        $this->assertArrayNotHasKey( 'transform', $deco, 'no lift on items' );
                        $rest = (string) $kit['shadow']['rest'];
                        if ( $rule || 'none' === $rest ) {
                            $this->assertArrayNotHasKey( 'boxShadow', $deco, "$label $role on $on ($carrier): no shadow" );
                        } else {
                            $this->assertSame( [ 'desktop' ], array_keys( $deco['boxShadow'] ) );
                            $this->assertSame( [ 'value' ], array_keys( $deco['boxShadow']['desktop'] ), 'the rest shadow only, never a hover shadow' );
                            $this->assertSame( 'soft' === $rest || 'glow' === $rest ? 'preset1' : 'preset4', $deco['boxShadow']['desktop']['value']['style'] );
                        }
                    }
                }
            }
        }
    }

    public function testTheItemShadowIsTheCardPanelShadowOnTheSameBand(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( [ 'light', 'surface', 'accent', 'dark' ] as $on ) {
                $panel = KitAdapters::patch( 'divi/group', 'panel', $on, $kit );
                $this->assertIsArray( $panel );
                foreach ( self::CARRIERS as $carrier ) {
                    $item = $this->patch( $kit, 'item', $on, $carrier );
                    $this->assertSame( $panel['module']['decoration']['boxShadow'] ?? null, $item['module']['decoration']['boxShadow'] ?? null, "$label item on $on ($carrier): the panel's rest shadow" );
                    $this->assertSame( $panel['module']['decoration']['border'], $item['module']['decoration']['border'], "$label item on $on ($carrier): the panel's frame" );
                }
            }
        }
    }

    public function testNoHexAndNoUnresolvedTokensBeyondColourAndKit(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::CARRIERS as $carrier ) {
                foreach ( self::BANDS as $role => $bands ) {
                    foreach ( $bands as $on ) {
                        $json = (string) json_encode( $this->patch( $kit, $role, $on, $carrier ), JSON_UNESCAPED_SLASHES );
                        $this->assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $json, "$label $role on $on" );
                        $this->assertDoesNotMatchRegularExpression( '/rgba?\(/i', $json, 'an item is never transparent: no literal colour at all' );
                        preg_match_all( '/\{\{[^}]*\}\}/', $json, $t );
                        foreach ( $t[0] as $token ) {
                            $this->assertMatchesRegularExpression(
                                '/^\{\{aied:(?:color:(?:' . implode( '|', self::COLOR_ROLES ) . ')|color:overlay:18|color:(?:accent|accent-text):45|kit:(?:radius-card|space-card))\}\}$/',
                                $token,
                                "$label $role on $on ($carrier)"
                            );
                        }
                    }
                }
            }
        }
    }

    public function testPatchValidatesInsideARealAccordionAndToggle(): void
    {
        $recipes = [
            'divi/accordion' => [ 'faq-accordion', 'features-accordion-image' ],
            'divi/toggle'    => [ 'faq-toggle-list' ],
        ];
        $checked = 0;
        foreach ( $recipes as $carrier => $names ) {
            foreach ( $names as $name ) {
                $raw = ImageTokens::resolve( (string) SectionRecipes::rawMarkup( $name ), 'https://example.test/images/' );
                $this->assertStringContainsString( 'wp:' . $carrier . ' ', $raw, "$name has a $carrier" );
                foreach ( $this->shippedKits() as $id => $kit ) {
                    foreach ( self::BANDS as $role => $bands ) {
                        foreach ( $bands as $on ) {
                            $marked = self::markEvery( $raw, $carrier, $role, $on );
                            $this->assertGreaterThan( 0, substr_count( $marked, '"aied":{"kit":"' . $role . '"' ) );
                            $markup = KitResolver::resolve( $marked, $kit );
                            $this->assertSame( [], KitResolver::leftovers( $markup ), "$name $id $role on $on" );
                            foreach ( [ null, 'brutalist-flat' ] as $langId ) {
                                $lang = null === $langId ? RecipeTokens::default() : DesignLanguages::get( $langId );
                                $this->assertIsArray( $lang );
                                $resolved = RecipeTokens::resolve( $markup, $lang );
                                $this->assertSame( [], RecipeTokens::found( $resolved ), "$name $id $role on $on" );
                                $result = LayoutGate::check( '<!-- wp:divi/placeholder -->' . $resolved . '<!-- /wp:divi/placeholder -->' );
                                $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                                $this->assertTrue( $result->isValid(), "$name $id $role on $on must pass the gate, got: $codes" );
                                ++$checked;
                            }
                        }
                    }
                }
            }
        }
        $this->assertSame( 3 * 40 * 6 * 2, $checked );
        // The minimal blocks (an accordion with two items, a lone toggle) pass too.
        $kit = $this->shippedKits()['soft-solid'];
        foreach ( self::CARRIERS as $carrier ) {
            foreach ( self::BANDS as $role => $bands ) {
                foreach ( $bands as $on ) {
                    $resolved = RecipeTokens::resolve( KitResolver::resolve( $this->itemMarkup( $carrier, $role, $on ), $kit ), RecipeTokens::default() );
                    $this->assertTrue( LayoutGate::check( $resolved )->isValid(), "$carrier $role on $on" );
                }
            }
        }
    }

    public function testUnknownCombinationsAreNull(): void
    {
        $kit = $this->shippedKits()['soft-solid'];
        foreach ( self::CARRIERS as $carrier ) {
            foreach ( [ 'accent', 'dark', 'panel', 'photo', '' ] as $on ) {
                $this->assertNull( KitAdapters::patch( $carrier, 'item-rule', $on, $kit ), "item-rule on $on ($carrier)" );
            }
            foreach ( [ 'panel', 'photo' ] as $on ) {
                $this->assertNull( KitAdapters::patch( $carrier, 'item', $on, $kit ), "item on $on ($carrier)" );
            }
            foreach ( [ 'card', 'panel', 'control', 'control-secondary', 'items', '' ] as $role ) {
                $this->assertNull( KitAdapters::patch( $carrier, $role, 'light', $kit ), "$role on $carrier" );
            }
            foreach ( [ 'inverted', 'accent' ] as $ground ) {
                $this->assertNull( KitAdapters::patch( $carrier, 'item', 'light', $kit, $ground ), "an item takes no ground ($ground, $carrier)" );
            }
            $this->assertNull( KitAdapters::patch( $carrier, 'item', 'light', [ 'id' => 'broken' ] ), 'an invalid kit' );
            $this->assertNull( KitAdapters::patch( $carrier, 'item', 'light', array_merge( $kit, [ 'shadow' => 'x' ] ) ), 'an invalid kit' );
        }
        foreach ( [ 'divi/group', 'divi/column', 'divi/button', 'divi/accordion-item', 'divi/tabs', 'divi/blurb' ] as $block ) {
            foreach ( [ 'item', 'item-rule' ] as $role ) {
                $this->assertNull( KitAdapters::patch( $block, $role, 'light', $kit ), "$role on $block" );
            }
        }
    }

    public function testThePatchIsDeterministic(): void
    {
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::CARRIERS as $carrier ) {
                foreach ( self::BANDS as $role => $bands ) {
                    foreach ( $bands as $on ) {
                        $this->assertSame(
                            json_encode( KitAdapters::patch( $carrier, $role, $on, $kit ) ),
                            json_encode( KitAdapters::patch( $carrier, $role, $on, $kit ) ),
                            "$id $role on $on ($carrier)"
                        );
                    }
                }
            }
        }
    }

    public function testAppliedNamesTheItemCarriers(): void
    {
        $this->assertSame( [ 'item', 'item-rule' ], KitAdapters::APPLIED['divi/accordion'] ?? null );
        $this->assertSame( [ 'item', 'item-rule' ], KitAdapters::APPLIED['divi/toggle'] ?? null );
        $this->assertArrayNotHasKey( 'divi/accordion-item', KitAdapters::APPLIED, 'items are styled through their parent, never per item' );
    }
}
