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
use Divi5Validator\Tests\Support\DeadOnModule;
use Divi5Validator\Tests\Support\RuleChipAudit;
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
require_once __DIR__ . '/Support/Colour.php';
require_once __DIR__ . '/Support/RuleChipAudit.php';

/**
 * The chip adapter (rules and chips plan Task 3): role `chip` on `divi/group` (a tag: control radius and an outline frame in
 * the kit's width and style, no fill, no padding) and on `divi/icon` (an icon chip: control radius on the module box, accent
 * fill and accent-text glyph), on four bands. Only the paths the effect probe passed ON each carrier are written
 * (fixtures/shapes/kit-chip-vocabulary.md, results fixtures/shapes/probe-results-kit-chip.json); the eight decisions
 * (KitAdapters::CHIP_PROVEN) are pinned to that file's "Adapter decisions" table.
 */
class KitChipAdapterTest extends TestCase
{
    private const BANDS    = [ 'light', 'surface', 'accent', 'dark' ];
    private const CARRIERS = [ 'divi/group', 'divi/icon' ];

    private const COLOR_ROLES = [ 'bg', 'surface', 'text', 'muted', 'accent', 'accent-text', 'border', 'inverse', 'overlay' ];

    private const VOCABULARY = __DIR__ . '/../fixtures/shapes/kit-chip-vocabulary.md';
    private const CANDIDATES = __DIR__ . '/../fixtures/shapes/candidates/kit-chip.json';
    private const RESULTS    = __DIR__ . '/../fixtures/shapes/probe-results-kit-chip.json';

    /** The rule colour table the tag frame reuses (KitAdapters::RULE_COLOUR, pinned in KitRuleAdapterTest). */
    private const QUIET  = [ 'light' => 'border', 'surface' => 'border', 'accent' => 'accent-text:40', 'dark' => 'inverse:40' ];
    private const STRONG = [ 'light' => 'text', 'surface' => 'text', 'accent' => 'accent-text', 'dark' => 'inverse' ];

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

    /** Every shipped kit plus variants over every border style x width x colour the schema allows. @return array<string,array<string,mixed>> */
    private function allKitVariants(): array
    {
        $out = $this->shippedKits();
        foreach ( [ 'soft-solid', 'underline-link' ] as $base ) {
            foreach ( StyleKits::BORDER_STYLES as $style ) {
                foreach ( [ '0px', '1px', '2px', '3px', '4px' ] as $width ) {
                    foreach ( StyleKits::BORDER_COLORS as $colour ) {
                        $v                    = $out[ $base ];
                        $v['border']['style'] = $style;
                        $v['border']['width'] = $width;
                        $v['border']['color'] = $colour;
                        if ( [] === StyleKits::problems( $v ) ) {
                            $out[ "$base/$style/$width/$colour" ] = $v;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $kit @return array<string,mixed> */
    private function patch( string $block, array $kit, string $on ): array
    {
        $patch = KitAdapters::patch( $block, 'chip', $on, $kit );
        $this->assertIsArray( $patch, "{$kit['id']} chip on $block / $on must have a patch" );
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

    /** A leaf map with the whole-value kit tokens replaced by the four corners they resolve to for $kit. @param array<string,mixed> $leaves @param array<string,mixed> $kit @return array<string,mixed> */
    private static function expandTokens( array $leaves, array $kit ): array
    {
        $out = [];
        foreach ( $leaves as $path => $value ) {
            if ( is_string( $value ) && preg_match( '/^\{\{aied:kit:([a-z-]+)\}\}$/', $value, $m ) ) {
                $object = StyleKits::valueToken( $kit, $m[1] );
                if ( is_array( $object ) ) {
                    foreach ( $object as $k => $v ) {
                        $out[ $path . '.' . $k ] = $v;
                    }
                    continue;
                }
            }
            $out[ $path ] = $value;
        }
        return $out;
    }

    private const WRAP_OPEN  = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.15.1"} --><!-- wp:divi/row {"builderVersion":"5.15.1"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1"} -->';
    private const WRAP_CLOSE = '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    /** A real layout with a marked chip on band $on. */
    private function markup( string $block, string $on ): string
    {
        $marker = '"aied":{"kit":"chip","on":"' . $on . '"}';
        if ( 'divi/icon' === $block ) {
            $body = '<!-- wp:divi/icon {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"10px","bottom":"10px","left":"10px","right":"10px"}}}}}},"icon":{"advanced":{"size":{"desktop":{"value":"20px"}}},"innerContent":{"desktop":{"value":{"unicode":"&#xf518;","type":"fa","weight":"900"}}}},"builderVersion":"5.15.1",' . $marker . '} /-->';
        } else {
            $body = '<!-- wp:divi/group {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"4px","bottom":"4px","left":"10px","right":"10px"}}}}}},"builderVersion":"5.15.1",' . $marker . '} -->'
                . '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Tag</p>"}}},"builderVersion":"5.15.1"} /-->'
                . '<!-- /wp:divi/group -->';
        }
        return self::WRAP_OPEN . $body . self::WRAP_CLOSE;
    }

    /** @return array<string,bool> decision => proven, from the "Adapter decisions" table of the vocabulary file */
    private function vocabularyDecisions(): array
    {
        $text = (string) file_get_contents( self::VOCABULARY );
        $at   = strpos( $text, '## Adapter decisions' );
        $this->assertNotFalse( $at, 'the vocabulary file has an Adapter decisions table' );
        preg_match_all( '/^\|\s*([a-z-]+)\s*\|\s*(PASS|DEAD)\s*\|/m', substr( $text, (int) $at ), $m, PREG_SET_ORDER );
        $out = [];
        foreach ( $m as $row ) {
            $out[ $row[1] ] = 'PASS' === $row[2];
        }
        return $out;
    }

    /** Every path of a candidate that PASSED, per carrier ('icon', 'group'): the keys of its `set`. @return array<string,array<string,true>> */
    private function probedPaths(): array
    {
        $candidates = json_decode( (string) file_get_contents( self::CANDIDATES ), true );
        $results    = [];
        foreach ( (array) json_decode( (string) file_get_contents( self::RESULTS ), true ) as $r ) {
            $results[ $r['id'] ] = $r;
        }
        $this->assertIsArray( $candidates );
        $paths = [];
        foreach ( $candidates as $candidate ) {
            $r = $results[ $candidate['id'] ] ?? null;
            $this->assertNotNull( $r, $candidate['id'] . ' has a result' );
            if ( true !== ( $r['effect'] ?? false ) || true === ( $r['cssOnly'] ?? false ) ) {
                continue;
            }
            foreach ( array_keys( (array) ( $candidate['set'] ?? [] ) ) as $key ) {
                $paths[ (string) $candidate['block'] ][ (string) $key ] = true;
            }
        }
        return $paths;
    }

    public function testEveryEmittedChipPathIsInTheRegistryAndNotDeadOnItsCarrier(): void
    {
        $registry = ShapeRegistry::load();
        $dead     = DeadOnModule::load();
        $missing  = [];
        $killed   = [];
        $checked  = 0;
        foreach ( $this->shippedKits() as $label => $kit ) {
            foreach ( self::CARRIERS as $block ) {
                foreach ( self::BANDS as $on ) {
                    $markup = KitResolver::resolve( $this->markup( $block, $on ), $kit );
                    $this->assertSame( [], KitResolver::leftovers( $markup ), "$label chip on $block / $on" );
                    $this->assertStringNotContainsString( '"aied":{', $markup );
                    foreach ( ShapePaths::fromMarkup( $markup ) as $path ) {
                        if ( ! $registry->coversLeaf( $path ) ) {
                            $missing[ $path ] = "$label chip on $block / $on";
                        }
                    }
                    foreach ( $dead->violations( $markup ) as $v ) {
                        $killed[ $v ] = $label;
                    }
                    $json = (string) json_encode( $this->patch( $block, $kit, $on ) );
                    foreach ( [ 'tablet', 'phone', 'tabletWide', 'phoneWide', 'sticky', 'hover' ] as $state ) {
                        $this->assertStringNotContainsString( "\"$state\"", $json, "only rest desktop values are written ($label chip on $block / $on)" );
                    }
                    ++$checked;
                }
            }
        }
        $this->assertSame( 40 * 4 * 2, $checked );
        $this->assertSame( [], $missing, 'the chip adapter emits paths the registry does not know' );
        $this->assertSame( [], array_keys( $killed ), 'the chip adapter emits paths that are dead on their carrier' );
    }

    public function testEveryLeafIsAProbedPathOnItsCarrier(): void
    {
        $probed = $this->probedPaths();
        foreach ( [ 'icon', 'group' ] as $carrier ) {
            $this->assertGreaterThan( 3, count( $probed[ $carrier ] ?? [] ), "$carrier has probed paths" );
        }
        $unprobed = [];
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::CARRIERS as $block ) {
                $carrier = substr( $block, strlen( 'divi/' ) );
                foreach ( self::BANDS as $on ) {
                    foreach ( array_keys( self::expandTokens( self::leaves( $this->patch( $block, $kit, $on ) ), $kit ) ) as $path ) {
                        if ( ! isset( $probed[ $carrier ][ $path ] ) ) {
                            $unprobed[ "$carrier $path" ] = "$label chip on $on";
                        }
                    }
                }
            }
        }
        $this->assertSame( [], $unprobed, 'every leaf the chip adapter writes was measured to PASS on its own carrier' );

        // A planted leaf fails the check: a padding on a tag or an icon was never part of the chip decisions.
        $kit   = $this->shippedKits()['soft-solid'];
        $patch = $this->patch( 'divi/group', $kit, 'light' );
        $patch['module']['decoration']['spacing'] = [ 'desktop' => [ 'value' => [ 'padding' => '{{aied:kit:space-control}}' ] ] ];
        $bad = array_filter( array_keys( self::expandTokens( self::leaves( $patch ), $kit ) ), static fn ( string $p ): bool => ! isset( $probed['group'][ $p ] ) );
        $this->assertNotSame( [], array_values( $bad ), 'a padding on a tag is not a probed chip path' );
    }

    public function testChipProvenMatchesTheVocabularyTable(): void
    {
        $table = $this->vocabularyDecisions();
        $this->assertSame(
            [ 'icon-radius', 'icon-fill', 'icon-glyph', 'tag-radius', 'tag-frame', 'tag-dashed', 'tag-dotted', 'tag-double' ],
            array_keys( $table ),
            'eight decisions, in the table\'s order'
        );
        $this->assertSame( $table, KitAdapters::CHIP_PROVEN, 'KitAdapters::CHIP_PROVEN is exactly the Adapter decisions table of kit-chip-vocabulary.md' );
        $this->assertSame( array_fill_keys( array_keys( $table ), true ), KitAdapters::CHIP_PROVEN, 'all eight passed on the real Divi 5.15.1' );
    }

    public function testTheChipRadiusIsTheWholeControlToken(): void
    {
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::CARRIERS as $block ) {
                $leaves = self::leaves( $this->patch( $block, $kit, 'light' ) );
                $this->assertSame( '{{aied:kit:radius-control}}', $leaves['module.decoration.border.desktop.value.radius'] ?? null, "$id $block: the radius is the whole-value token, so no corner key or sync stays beside it" );
                $this->assertArrayNotHasKey( 'module.decoration.border.desktop.value.radius.sync', $leaves );

                $out   = KitResolver::resolve( $this->markup( $block, 'light' ), $kit );
                $this->assertSame( 1, preg_match( '/<!-- wp:divi\/(?:group|icon) (\{.*?\})(?: -->| \/-->)/s', $out, $m ), "$id $block" );
                $attrs  = json_decode( $m[1], true );
                $radius = $attrs['module']['decoration']['border']['desktop']['value']['radius'] ?? null;
                $v      = (string) $kit['radius']['control'];
                $this->assertSame( [ 'topLeft' => $v, 'topRight' => $v, 'bottomRight' => $v, 'bottomLeft' => $v ], $radius, "$id $block: the four control corners, nothing else" );
            }
        }
    }

    public function testTheTagFrameFollowsTheKitAndTheBand(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            $border = $kit['border'];
            $width  = '0px' === $border['width'] ? '1px' : $border['width'];
            if ( 'double' === $border['style'] ) {
                $width = (int) $width < 3 ? '3px' : $width;
            }
            $quietFrame = in_array( $border['color'], [ 'same', 'border' ], true );
            foreach ( self::BANDS as $on ) {
                $patch  = $this->patch( 'divi/group', $kit, $on );
                $leaves = self::leaves( $patch );
                $p      = 'module.decoration.border.desktop.value.styles.';
                $role   = $quietFrame ? self::QUIET[ $on ] : self::STRONG[ $on ];
                $this->assertSame( $width, $leaves[ $p . 'all.width' ], "$label on $on: kit width, 1px floor, 3px for a double" );
                $this->assertSame( $border['style'], $leaves[ $p . 'all.style' ], "$label on $on: the kit's style" );
                $this->assertSame( '{{aied:color:' . $role . '}}', $leaves[ $p . 'all.color' ], "$label on $on: the band's rule colour (quiet for a hairline frame, else strong)" );
                $this->assertArrayNotHasKey( $p . 'bottom.width', $leaves, 'a tag is an outline pill, never the underline form' );
                $this->assertSame( [ 'module' ], array_keys( $patch ) );
                $this->assertSame( [ 'decoration' ], array_keys( $patch['module'] ) );
                $this->assertSame( [ 'border' ], array_keys( $patch['module']['decoration'] ), 'no fill and no padding: the recipe\'s padding is the chip\'s size' );
                $this->assertSame( [ 'radius', 'styles' ], array_keys( $patch['module']['decoration']['border']['desktop']['value'] ) );
            }
        }
        // The style fallbacks, one decision each.
        $dotted = $this->shippedKits()['dotted-pill'];
        $this->assertSame( 'dotted', $dotted['border']['style'] );
        $off = self::leaves( KitAdapters::chipPatch( 'divi/group', 'light', $dotted, array_merge( KitAdapters::CHIP_PROVEN, [ 'tag-dotted' => false ] ) ) );
        $this->assertSame( 'solid', $off['module.decoration.border.desktop.value.styles.all.style'] );
        $dashed = $this->shippedKits()['hairline-dashed'];
        $off    = self::leaves( KitAdapters::chipPatch( 'divi/group', 'light', $dashed, array_merge( KitAdapters::CHIP_PROVEN, [ 'tag-dashed' => false ] ) ) );
        $this->assertSame( 'solid', $off['module.decoration.border.desktop.value.styles.all.style'] );
        $double = $this->shippedKits()['double-frame'];
        $off    = self::leaves( KitAdapters::chipPatch( 'divi/group', 'light', $double, array_merge( KitAdapters::CHIP_PROVEN, [ 'tag-double' => false ] ) ) );
        $this->assertSame( 'solid', $off['module.decoration.border.desktop.value.styles.all.style'] );
        $this->assertSame( '4px', $off['module.decoration.border.desktop.value.styles.all.width'], 'solid fallback keeps the kit width (no double floor)' );
        // Unproven frame or radius: that decision writes nothing, the other stays.
        $kit = $this->shippedKits()['soft-solid'];
        $noFrame = self::leaves( KitAdapters::chipPatch( 'divi/group', 'light', $kit, array_merge( KitAdapters::CHIP_PROVEN, [ 'tag-frame' => false ] ) ) );
        $this->assertSame( [ 'module.decoration.border.desktop.value.radius' ], array_keys( $noFrame ) );
        $noRadius = self::leaves( KitAdapters::chipPatch( 'divi/group', 'light', $kit, array_merge( KitAdapters::CHIP_PROVEN, [ 'tag-radius' => false ] ) ) );
        $this->assertArrayNotHasKey( 'module.decoration.border.desktop.value.radius', $noRadius );
        $this->assertArrayHasKey( 'module.decoration.border.desktop.value.styles.all.width', $noRadius );
        $this->assertSame( [], KitAdapters::chipPatch( 'divi/group', 'light', $kit, array_merge( KitAdapters::CHIP_PROVEN, [ 'tag-frame' => false, 'tag-radius' => false ] ) ), 'nothing proven, nothing written' );
    }

    public function testTheIconChipFillAndGlyphAreWrittenOnEveryBand(): void
    {
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::BANDS as $on ) {
                $patch  = $this->patch( 'divi/icon', $kit, $on );
                $leaves = self::leaves( $patch );
                $fill   = 'accent' === $on ? 'surface' : 'accent';
                $glyph  = 'accent' === $on ? 'text' : 'accent-text';
                $this->assertSame( '{{aied:color:' . $fill . '}}', $leaves['module.decoration.background.desktop.value.color'] ?? null, "$id on $on: fill" );
                $this->assertSame( '{{aied:color:' . $glyph . '}}', $leaves['icon.advanced.color.desktop.value'] ?? null, "$id on $on: glyph" );
                $this->assertSame( '{{aied:kit:radius-control}}', $leaves['module.decoration.border.desktop.value.radius'] );
                $keys = array_keys( $leaves );
                sort( $keys );
                $this->assertSame( [ 'icon.advanced.color.desktop.value', 'module.decoration.background.desktop.value.color', 'module.decoration.border.desktop.value.radius' ], $keys, 'no padding, no hover, no frame: the recipe\'s padding is the chip\'s size' );
            }
        }
    }

    public function testUnprovenIconDecisionsWriteNothingAndRefuseTheAccentBand(): void
    {
        $kit = $this->shippedKits()['soft-solid'];
        foreach ( [ 'icon-fill', 'icon-glyph' ] as $decision ) {
            $planted = array_merge( KitAdapters::CHIP_PROVEN, [ $decision => false ] );
            $this->assertSame( [], KitAdapters::chipPatch( 'divi/icon', 'accent', $kit, $planted ), "$decision unproven: the icon chip on an accent band is refused" );
            foreach ( [ 'light', 'surface', 'dark' ] as $on ) {
                $leaves = self::leaves( KitAdapters::chipPatch( 'divi/icon', $on, $kit, $planted ) );
                $this->assertArrayHasKey( 'module.decoration.border.desktop.value.radius', $leaves );
                if ( 'icon-fill' === $decision ) {
                    $this->assertArrayNotHasKey( 'module.decoration.background.desktop.value.color', $leaves, "$on: no fill is written" );
                    $this->assertArrayHasKey( 'icon.advanced.color.desktop.value', $leaves );
                } else {
                    $this->assertArrayNotHasKey( 'icon.advanced.color.desktop.value', $leaves, "$on: no glyph colour is written" );
                    $this->assertArrayHasKey( 'module.decoration.background.desktop.value.color', $leaves );
                }
            }
        }
        $noRadius = self::leaves( KitAdapters::chipPatch( 'divi/icon', 'light', $kit, array_merge( KitAdapters::CHIP_PROVEN, [ 'icon-radius' => false ] ) ) );
        $this->assertArrayNotHasKey( 'module.decoration.border.desktop.value.radius', $noRadius );
        $this->assertSame( [], KitAdapters::chipPatch( 'divi/icon', 'light', $kit, array_map( static fn ( bool $v ): bool => false, KitAdapters::CHIP_PROVEN ) ) );
        // With everything proven the accent band is answered.
        $this->assertNotSame( [], KitAdapters::chipPatch( 'divi/icon', 'accent', $kit ) );
    }

    public function testNoHexAndNoUnresolvedTokensBeyondColourAndKit(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::CARRIERS as $block ) {
                foreach ( self::BANDS as $on ) {
                    $json = (string) json_encode( $this->patch( $block, $kit, $on ), JSON_UNESCAPED_SLASHES );
                    $this->assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $json, "$label chip $block $on" );
                    $this->assertDoesNotMatchRegularExpression( '/rgba?\(/i', $json, 'a chip has no literal colour' );
                    preg_match_all( '/\{\{[^}]*\}\}/', $json, $t );
                    $this->assertNotSame( [], $t[0] );
                    foreach ( $t[0] as $token ) {
                        $this->assertMatchesRegularExpression( '/^\{\{aied:(?:color:(?:' . implode( '|', self::COLOR_ROLES ) . ')(?::40)?|kit:radius-control)\}\}$/', $token, "$label chip $block $on" );
                    }
                }
            }
        }
    }

    public function testPatchValidatesInsideARealLayout(): void
    {
        $checked = 0;
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::CARRIERS as $block ) {
                foreach ( self::BANDS as $on ) {
                    $markup = KitResolver::resolve( $this->markup( $block, $on ), $kit );
                    $this->assertSame( [], KitResolver::leftovers( $markup ), "$id chip $block $on" );
                    $resolved = RecipeTokens::resolve( $markup, RecipeTokens::default() );
                    $this->assertSame( [], RecipeTokens::found( $resolved ), "$id chip $block $on: every token resolved" );
                    $result = LayoutGate::check( $resolved );
                    $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                    $this->assertTrue( $result->isValid(), "$id chip on $block / $on must pass the gate, got: $codes" );
                    ++$checked;
                }
            }
        }
        $this->assertSame( 40 * 2 * 4, $checked );
    }

    public function testUnknownCombinationsAreNull(): void
    {
        $kit = $this->shippedKits()['soft-solid'];
        foreach ( [ 'divi/divider', 'divi/column', 'divi/text', 'divi/button', 'divi/image', 'divi/blurb', 'divi/row', 'divi/accordion', 'divi/toggle', 'divi/contact-form' ] as $block ) {
            $this->assertNull( KitAdapters::patch( $block, 'chip', 'light', $kit ), "chip on $block" );
        }
        foreach ( [ 'panel', 'photo', 'dark ', '', 'LIGHT' ] as $on ) {
            foreach ( self::CARRIERS as $block ) {
                $this->assertNull( KitAdapters::patch( $block, 'chip', $on, $kit ), "a chip on '$on' ($block)" );
            }
        }
        foreach ( [ 'inverted', 'accent' ] as $ground ) {
            foreach ( self::CARRIERS as $block ) {
                $this->assertNull( KitAdapters::patch( $block, 'chip', 'light', $kit, $ground ), "a chip takes no ground ($block, $ground)" );
            }
        }
        foreach ( [ 'chips', 'tag', 'icon', '' ] as $role ) {
            $this->assertNull( KitAdapters::patch( 'divi/group', $role, 'light', $kit ), "unknown role '$role'" );
        }
        $this->assertNull( KitAdapters::patch( 'divi/icon', 'rule', 'light', $kit ), 'a rule is not an icon chip' );
        $this->assertNull( KitAdapters::patch( 'divi/icon', 'card', 'light', $kit ), 'a card is not an icon chip' );
        $this->assertNull( KitAdapters::patch( 'divi/group', 'chip', 'light', [ 'id' => 'broken' ] ), 'an invalid kit' );
        $this->assertNull( KitAdapters::patch( 'divi/icon', 'chip', 'light', array_merge( $kit, [ 'shadow' => 'x' ] ) ), 'an invalid kit' );
    }

    public function testThePatchIsDeterministic(): void
    {
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::CARRIERS as $block ) {
                foreach ( self::BANDS as $on ) {
                    $this->assertSame( json_encode( $this->patch( $block, $kit, $on ) ), json_encode( $this->patch( $block, $kit, $on ) ), "$id chip $block $on" );
                }
            }
        }
    }

    public function testAppliedNamesTheChipCarriers(): void
    {
        $this->assertSame( [ 'chip' ], KitAdapters::APPLIED['divi/icon'] ?? null );
        $this->assertContains( 'chip', KitAdapters::APPLIED['divi/group'] ?? [] );
        $this->assertNotContains( 'chip', KitAdapters::APPLIED['divi/column'] ?? [], 'a column is not a tag' );
        $this->assertSame( [ 'light', 'surface', 'accent', 'dark' ], KitAdapters::CHIP_BANDS );
    }

    /** The colour role of each band (the role the band is painted in), for the "chip never vanishes into its band" check. */
    private const BAND_ROLE = [ 'light' => 'bg', 'surface' => 'surface', 'accent' => 'accent', 'dark' => 'overlay' ];

    /** A colour token or value resolved by the real pipeline (alpha forms included) as an opaque hex over $ground. @param array<string,mixed> $lang */
    private static function opaqueOn( string $value, array $lang, string $ground ): ?string
    {
        return Colour::opaque( RecipeTokens::resolve( $value, $lang ), $ground );
    }

    /** Whether a colour token is a QUIET colour: the `border` hairline or any alpha form. */
    private static function isQuiet( string $token ): bool
    {
        return 1 === preg_match( '/^\{\{aied:color:(?:border|[a-z-]+:\d\d)\}\}$/', $token );
    }

    /**
     * Contrast problems of one chip patch on band $on in one language ([] = fine), keyed "$where|<check>":
     * - tag (`divi/group`): the frame colour against the band, at least RuleChipAudit::QUIET_RULE_FLOOR when it is a quiet
     *   colour (`border` or an alpha form), at least 3.0 when strong (WCAG 1.4.11); the frame at least 1px, a double at
     *   least 3px; no frame at all is a problem (a tag without a frame has no shape);
     * - icon chip (`divi/icon`): the glyph on its painted fill at least 3.0 (non-text, 20 to 22px graphics); the fill is
     *   never the band's own colour role and never paints the colour of the band (else the chip vanishes).
     *
     * @param array<string,mixed> $patch
     * @param array<string,mixed> $lang
     * @return array<string,string>
     */
    public static function chipContrastProblems( array $patch, string $blockName, string $on, array $lang, string $where ): array
    {
        $leaves = self::leaves( $patch );
        $band   = KitRuleAdapterTest::bandOf( $on, $lang );
        $out    = [];
        if ( 'divi/group' === $blockName ) {
            $p      = 'module.decoration.border.desktop.value.styles.all.';
            $colour = isset( $leaves[ $p . 'color' ] ) ? (string) $leaves[ $p . 'color' ] : null;
            if ( null === $colour ) {
                return [ "$where|frame" => "$where: the tag has no frame colour" ];
            }
            $ink    = self::opaqueOn( $colour, $lang, $band );
            $ratio  = null === $ink ? 0.0 : Colour::contrast( $ink, $band );
            $need   = self::isQuiet( $colour ) ? RuleChipAudit::QUIET_RULE_FLOOR : 3.0;
            if ( $ratio < $need ) {
                $out[ "$where|frame" ] = sprintf( '%s: the tag frame (%s) on %s is %.3f:1, needs %.1f', $where, $colour, $band, $ratio, $need );
            }
            $width = (string) ( $leaves[ $p . 'width' ] ?? '' );
            $px    = 1 === preg_match( '/^(\d+)px$/', $width, $m ) ? (int) $m[1] : 0;
            if ( $px < 1 || ( 'double' === ( $leaves[ $p . 'style' ] ?? '' ) && $px < 3 ) ) {
                $out[ "$where|width" ] = "$where: the tag frame width '$width' is not drawn (1px, a double 3px)";
            }
            return $out;
        }
        $fillToken  = isset( $leaves['module.decoration.background.desktop.value.color'] ) ? (string) $leaves['module.decoration.background.desktop.value.color'] : null;
        $glyphToken = isset( $leaves['icon.advanced.color.desktop.value'] ) ? (string) $leaves['icon.advanced.color.desktop.value'] : null;
        if ( null === $fillToken || null === $glyphToken ) {
            return [ "$where|glyph" => "$where: the icon chip has no fill or no glyph colour" ];
        }
        if ( '{{aied:color:' . self::BAND_ROLE[ $on ] . '}}' === $fillToken ) {
            $out[ "$where|fill" ] = "$where: the icon fill is the band's own colour role ($fillToken)";
        }
        $fill = self::opaqueOn( $fillToken, $lang, $band );
        if ( null === $fill ) {
            return $out + [ "$where|fill" => "$where: the icon fill $fillToken does not resolve" ];
        }
        if ( strtolower( $fill ) === strtolower( $band ) ) {
            $out[ "$where|fill" ] = "$where: the icon fill paints $fill, the colour of the band";
        }
        $glyph = self::opaqueOn( $glyphToken, $lang, $fill );
        $ratio = null === $glyph ? 0.0 : Colour::contrast( $glyph, $fill );
        if ( $ratio < 3.0 ) {
            $out[ "$where|glyph" ] = sprintf( '%s: the glyph (%s) on its fill %s is %.3f:1, needs 3.0', $where, $glyphToken, $fill, $ratio );
        }
        return $out;
    }

    public function testNoUnreadableChipInAnyKitLanguageBand(): void
    {
        $problems = [];
        $checked  = 0;
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( DesignLanguages::ids() as $langId ) {
                $lang = DesignLanguages::get( $langId );
                $this->assertIsArray( $lang );
                foreach ( self::BANDS as $on ) {
                    foreach ( self::CARRIERS as $block ) {
                        $problems = array_merge( $problems, self::chipContrastProblems( $this->patch( $block, $kit, $on ), $block, $on, $lang, "$id|$langId|$on|$block" ) );
                    }
                    ++$checked; // one cell = kit x language x band, judging the tag and the icon chip
                }
            }
        }
        $this->assertSame( 40 * 10 * 4, $checked );
        $this->assertSame( 1600, $checked );
        $this->assertSame( [], array_values( $problems ), "unreadable chips (fix the chip rules in KitAdapters::chipPatch() or RULE_COLOUR, never the floor; no exceptions):\n" . implode( "\n", array_slice( $problems, 0, 60 ) ) . "\n(" . count( $problems ) . ' problems)' );
    }

    public function testTheChipContrastCheckCatchesWhatItShould(): void
    {
        $organic = DesignLanguages::get( 'organic-warm' );
        $this->assertIsArray( $organic );
        $kit   = $this->shippedKits()['steady-solid'];
        $judge = static fn ( array $p, string $block, string $on, ?array $lang = null ): array => self::chipContrastProblems( $p, $block, $on, $lang ?? $organic, 'x' );
        foreach ( self::CARRIERS as $block ) {
            foreach ( self::BANDS as $on ) {
                $this->assertSame( [], $judge( $this->patch( $block, $kit, $on ), $block, $on ), "steady-solid chip on $block / $on is clean in organic-warm" );
            }
        }
        $frame = 'module.decoration.border.desktop.value.styles.all.';

        // A `text` tag frame on a dark band (a light language: the overlay is dark, so is text).
        $bad = $this->patch( 'divi/group', $kit, 'dark' );
        self::setLeaf( $bad, $frame . 'color', '{{aied:color:text}}' );
        $this->assertArrayHasKey( 'x|frame', $judge( $bad, 'divi/group', 'dark' ), 'a text frame on a dark band is under 3:1' );
        // A quiet frame in the band's own colour is under the quiet floor.
        $bad = $this->patch( 'divi/group', $kit, 'light' );
        self::setLeaf( $bad, $frame . 'color', '{{aied:color:bg}}' );
        $this->assertArrayHasKey( 'x|frame', $judge( $bad, 'divi/group', 'light' ) );
        // A strong frame must reach 3:1: border does not, once it is judged as strong ... and a quiet one passes its floor.
        $bad = $this->patch( 'divi/group', $kit, 'light' );
        self::setLeaf( $bad, $frame . 'color', '{{aied:color:muted:05}}' );
        $this->assertArrayHasKey( 'x|frame', $judge( $bad, 'divi/group', 'light' ), 'a 5% frame' );
        // A tag with no frame colour, and a frame that is not drawn.
        $bad = $this->patch( 'divi/group', $kit, 'light' );
        self::unsetLeaf( $bad, $frame . 'color' );
        $this->assertArrayHasKey( 'x|frame', $judge( $bad, 'divi/group', 'light' ) );
        $bad = $this->patch( 'divi/group', $kit, 'light' );
        self::setLeaf( $bad, $frame . 'width', '0px' );
        $this->assertArrayHasKey( 'x|width', $judge( $bad, 'divi/group', 'light' ) );

        // An accent fill on an accent band: the chip vanishes.
        $bad = $this->patch( 'divi/icon', $kit, 'accent' );
        self::setLeaf( $bad, 'module.decoration.background.desktop.value.color', '{{aied:color:accent}}' );
        $this->assertArrayHasKey( 'x|fill', $judge( $bad, 'divi/icon', 'accent' ) );
        // A surface fill on a surface band, and a fill that paints the band's colour through another role.
        $bad = $this->patch( 'divi/icon', $kit, 'surface' );
        self::setLeaf( $bad, 'module.decoration.background.desktop.value.color', '{{aied:color:surface}}' );
        $this->assertArrayHasKey( 'x|fill', $judge( $bad, 'divi/icon', 'surface' ) );
        // A glyph equal to its fill.
        $bad = $this->patch( 'divi/icon', $kit, 'light' );
        self::setLeaf( $bad, 'icon.advanced.color.desktop.value', '{{aied:color:accent}}' );
        $this->assertArrayHasKey( 'x|glyph', $judge( $bad, 'divi/icon', 'light' ) );
        // A missing glyph or fill.
        $bad = $this->patch( 'divi/icon', $kit, 'light' );
        self::unsetLeaf( $bad, 'icon' );
        $this->assertArrayHasKey( 'x|glyph', $judge( $bad, 'divi/icon', 'light' ) );
    }

    /** Set the dotted $path of $node to $value. @param array<string,mixed> $node */
    private static function setLeaf( array &$node, string $path, mixed $value ): void
    {
        $ref = &$node;
        foreach ( explode( '.', $path ) as $k ) {
            if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) {
                $ref[ $k ] = [];
            }
            $ref = &$ref[ $k ];
        }
        $ref = $value;
    }

    /** Remove the dotted $path of $node. @param array<string,mixed> $node */
    private static function unsetLeaf( array &$node, string $path ): void
    {
        $keys = explode( '.', $path );
        $last = array_pop( $keys );
        $ref  = &$node;
        foreach ( $keys as $k ) {
            if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) {
                return;
            }
            $ref = &$ref[ $k ];
        }
        unset( $ref[ $last ] );
    }
}
