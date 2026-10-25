<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
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
 * The carriers adapter (plan docs/superpowers/plans/2026-10-24-carriers-adapter.md, Task 3): the existing kit roles reach four
 * more carriers and one role is new. `card` / `panel` on `divi/testimonial` (the group card patch, no ground), `chip` on
 * `divi/blurb` (the icon chip at `imageIcon.*`), `rule` / `rule-strong` on `divi/number-counter` (the group top edge) and the new
 * `rule-accent` on `divi/divider` (the accent colour at the strong width, light and surface bands only). Only the paths the
 * effect probe passed ON each carrier are written (fixtures/shapes/kit-carrier-vocabulary.md, results
 * fixtures/shapes/probe-results-kit-carrier.json); the twenty decisions (KitAdapters::CARRIER_PROVEN) are pinned to that file's
 * "Adapter decisions" table.
 */
class KitCarrierAdapterTest extends TestCase
{
    private const CARD_BANDS = [ 'light', 'surface', 'accent', 'dark' ];
    private const CHIP_BANDS = [ 'light', 'surface', 'accent', 'dark' ];
    private const RULE_BANDS = [ 'light', 'surface', 'accent', 'dark', 'panel' ];
    private const ALL_BANDS  = [ 'light', 'surface', 'accent', 'dark', 'panel' ];

    /** Every new (block, role) => the bands it takes. */
    private const NEW = [
        'divi/testimonial|card'           => self::CARD_BANDS,
        'divi/testimonial|panel'          => self::CARD_BANDS,
        'divi/blurb|chip'                 => self::CHIP_BANDS,
        'divi/number-counter|rule'        => self::RULE_BANDS,
        'divi/number-counter|rule-strong' => self::RULE_BANDS,
        'divi/divider|rule-accent'        => [ 'light', 'surface' ],
    ];

    private const VOCABULARY = __DIR__ . '/../fixtures/shapes/kit-carrier-vocabulary.md';
    private const CANDIDATES = __DIR__ . '/../fixtures/shapes/candidates/kit-carrier.json';
    private const RESULTS    = __DIR__ . '/../fixtures/shapes/probe-results-kit-carrier.json';

    /**
     * sha256 of the JSON of KitAdapters::patch() over every OLD (block, role) of APPLIED x the five bands x no ground and both
     * grounds x the 40 kits, taken at the Task 3 base (feat/carriers-adapter 260e0b0) before any adapter edit: a new carrier
     * must not change one old patch.
     */
    private const OLD_PATCHES_SHA256 = 'e3bcb7653445fb2786182f7ced335521deb3b92e6b3f92c41a322680d14a39af';

    /** The APPLIED map before the carriers adapter (the enumeration of OLD_PATCHES_SHA256). */
    private const OLD_APPLIED = [
        'divi/button'       => [ 'control', 'control-secondary' ],
        'divi/group'        => [ 'card', 'panel', 'rule', 'rule-strong', 'chip' ],
        'divi/column'       => [ 'card', 'panel', 'rule', 'rule-strong' ],
        'divi/accordion'    => [ 'item', 'item-rule' ],
        'divi/toggle'       => [ 'item', 'item-rule' ],
        'divi/contact-form' => [ 'form' ],
        'divi/divider'      => [ 'rule', 'rule-strong' ],
        'divi/icon'         => [ 'chip' ],
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

    /** The first shipped kit $test accepts. @return array<string,mixed> */
    private function kitWhere( callable $test, string $what ): array
    {
        foreach ( $this->shippedKits() as $kit ) {
            if ( $test( $kit ) ) {
                return $kit;
            }
        }
        $this->fail( "no shipped kit has $what" );
    }

    /** @param array<string,mixed> $kit @return array<string,mixed> */
    private function patch( string $block, string $role, array $kit, string $on ): array
    {
        $patch = KitAdapters::patch( $block, $role, $on, $kit );
        $this->assertIsArray( $patch, "{$kit['id']} $role on $block / $on must have a patch" );
        $this->assertNotSame( [], $patch );
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

    /** A leaf map with the whole-value kit tokens replaced by the keys they resolve to for $kit. @param array<string,mixed> $leaves @param array<string,mixed> $kit @return array<string,mixed> */
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

    /** The block comment of a marked $block (the library's shapes: blurb-grid, testimonial, stats-counter-band, blog-feed). */
    private static function block( string $block, string $marker ): string
    {
        return match ( $block ) {
            'divi/testimonial'    => '<!-- wp:divi/testimonial {"author":{"innerContent":{"desktop":{"value":"Jordan Taylor"}}},"content":{"innerContent":{"desktop":{"value":"<p>Quote.</p>"}}},"module":{"decoration":{"overflow":{"desktop":{"value":{"x":"visible","y":"visible"}}},"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.15.1",' . $marker . '} /-->',
            'divi/blurb'          => '<!-- wp:divi/blurb {"title":{"innerContent":{"desktop":{"value":{"text":"Title"}}}},"imageIcon":{"innerContent":{"desktop":{"value":{"useIcon":"on","icon":{"unicode":"&#xf005;","type":"fa","weight":"900"}}}},"decoration":{"sizing":{"desktop":{"value":{"iconFontSize":"32px","alignment":"left"}}},"spacing":{"desktop":{"value":{"padding":{"top":"16px","right":"16px","bottom":"16px","left":"16px"}}}}}},"builderVersion":"5.15.1",' . $marker . '} /-->',
            'divi/number-counter' => '<!-- wp:divi/number-counter {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"20px"}}}},"sizing":{"desktop":{"value":{"width":"100%"}}}}},"title":{"innerContent":{"desktop":{"value":"Label"}}},"number":{"innerContent":{"desktop":{"value":"42"}}},"builderVersion":"5.15.1",' . $marker . '} /-->',
            default               => '<!-- wp:divi/divider {"builderVersion":"5.15.1","modulePreset":["1ep0co506u"],' . $marker . '} /-->',
        };
    }

    /** A real layout with a marked $block of $role on band $on. */
    private static function markup( string $block, string $role, string $on ): string
    {
        return self::WRAP_OPEN . self::block( $block, '"aied":{"kit":"' . $role . '","on":"' . $on . '"}' ) . self::WRAP_CLOSE;
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

    /**
     * Every path of a candidate that PASSED ON its carrier (a real change of the computed styles, not css-only), per carrier
     * ('testimonial', 'blurb', 'number-counter', 'divider'): the keys of its `set`, plus the library form (`styles.all.width` 0px)
     * the counter control rendered as its base. carrier => dotted path => true.
     *
     * @return array<string,array<string,true>>
     */
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
            if ( 'kx-counter-control' === $candidate['id'] ) {
                // The control's base is the library form the adapter writes: `all` 0px plus the `top` it sets.
                $this->assertSame( '0px', $candidate['base']['module']['decoration']['border']['desktop']['value']['styles']['all']['width'] ?? null );
                $paths['number-counter']['module.decoration.border.desktop.value.styles.all.width'] = true;
            }
        }
        return $paths;
    }

    /** [block, role, band] of every new carrier combination. @return list<array{0:string,1:string,2:string}> */
    private static function newCells(): array
    {
        $out = [];
        foreach ( self::NEW as $key => $bands ) {
            [ $block, $role ] = explode( '|', $key );
            foreach ( $bands as $on ) {
                $out[] = [ $block, $role, $on ];
            }
        }
        return $out;
    }

    public function testCarrierProvenMatchesTheVocabularyTable(): void
    {
        $table = $this->vocabularyDecisions();
        $this->assertSame(
            [
                'testimonial-fill', 'testimonial-frame', 'testimonial-underline', 'testimonial-radius', 'testimonial-quote',
                'testimonial-padding', 'testimonial-shadow', 'testimonial-hover-lift', 'testimonial-hover-frame', 'testimonial-hover-shadow',
                'blurb-icon-radius', 'blurb-icon-fill', 'blurb-icon-glyph',
                'counter-top', 'counter-top-dashed', 'counter-top-dotted', 'counter-top-double', 'counter-top-alpha',
                'divider-accent-over-preset', 'divider-accent-style',
            ],
            array_keys( $table ),
            'twenty decisions, in the table\'s order'
        );
        $this->assertSame( $table, KitAdapters::CARRIER_PROVEN, 'KitAdapters::CARRIER_PROVEN is exactly the Adapter decisions table of kit-carrier-vocabulary.md' );
        $this->assertSame( array_fill_keys( array_keys( $table ), true ), KitAdapters::CARRIER_PROVEN, 'all twenty passed on the real Divi 5.15.1' );
        $this->assertSame( [], array_intersect_key( KitAdapters::CARRIER_PROVEN, KitAdapters::RULE_PROVEN + KitAdapters::CHIP_PROVEN ), 'no carrier key shadows a rule or chip decision' );
    }

    public function testEveryLeafIsAProbedPathOnItsCarrier(): void
    {
        $probed = $this->probedPaths();
        foreach ( [ 'testimonial', 'blurb', 'number-counter', 'divider' ] as $carrier ) {
            $this->assertGreaterThanOrEqual( 3, count( $probed[ $carrier ] ?? [] ), "$carrier has probed paths" );
        }
        $unprobed = [];
        $checked  = 0;
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::newCells() as [ $block, $role, $on ] ) {
                $carrier = substr( $block, strlen( 'divi/' ) );
                foreach ( array_keys( self::expandTokens( self::leaves( $this->patch( $block, $role, $kit, $on ) ), $kit ) ) as $path ) {
                    if ( ! isset( $probed[ $carrier ][ $path ] ) ) {
                        $unprobed[ "$carrier $path" ] = "$label $role on $on";
                    }
                }
                ++$checked;
            }
        }
        $this->assertGreaterThan( 40 * count( self::newCells() ), $checked );
        $this->assertSame( [], $unprobed, 'every leaf the carriers adapter writes was measured to PASS on its own carrier (a group, icon or button result proves nothing here)' );

        // A planted leaf fails the check: a module padding on a blurb was never probed on the blurb.
        $kit   = $this->shippedKits()['soft-solid'];
        $patch = $this->patch( 'divi/blurb', 'chip', $kit, 'light' );
        $patch['module'] = [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => '{{aied:kit:space-control}}' ] ] ] ] ];
        $bad = array_values( array_filter( array_keys( self::expandTokens( self::leaves( $patch ), $kit ) ), static fn ( string $p ): bool => ! isset( $probed['blurb'][ $p ] ) ) );
        $this->assertNotSame( [], $bad );
        foreach ( $bad as $p ) {
            $this->assertStringStartsWith( 'module.decoration.spacing.desktop.value.padding.', $p );
        }
    }

    public function testEveryEmittedCarrierPathIsInTheRegistryAndNotDeadOnItsCarrier(): void
    {
        $registry = ShapeRegistry::load();
        $dead     = DeadOnModule::load();
        $missing  = [];
        $killed   = [];
        $checked  = 0;
        foreach ( $this->shippedKits() as $label => $kit ) {
            foreach ( self::newCells() as [ $block, $role, $on ] ) {
                $markup = KitResolver::resolve( self::markup( $block, $role, $on ), $kit );
                $this->assertSame( [], KitResolver::leftovers( $markup ), "$label $role on $block / $on" );
                $this->assertStringNotContainsString( '"aied":{', $markup );
                foreach ( ShapePaths::fromMarkup( $markup ) as $path ) {
                    if ( ! $registry->coversLeaf( $path ) ) {
                        $missing[ $path ] = "$label $role on $block / $on";
                    }
                }
                $json = (string) json_encode( $this->patch( $block, $role, $kit, $on ) );
                foreach ( [ 'tablet', 'phone', 'tabletWide', 'phoneWide', 'sticky' ] as $state ) {
                    $this->assertStringNotContainsString( "\"$state\"", $json, "only desktop values are written ($label $role on $block / $on)" );
                }
                foreach ( $dead->violations( $markup ) as $v ) {
                    $killed[ $v ] = $label;
                }
                ++$checked;
            }
        }
        $this->assertSame( 40 * count( self::newCells() ), $checked );
        $this->assertSame( [], $missing, 'the carriers adapter emits paths the registry does not know' );
        $this->assertSame( [], array_keys( $killed ), 'the carriers adapter emits paths that are dead on their carrier' );

        // A planted DEAD line for one carrier fails that carrier only.
        $file = tempnam( sys_get_temp_dir(), 'dead' );
        $this->assertIsString( $file );
        file_put_contents( $file, "# test list\ndivi/testimonial|module.decoration.boxShadow|planted-evidence|planted for the test\n" );
        try {
            $scoped = DeadOnModule::load( $file );
            $kit    = $this->shippedKits()['chunky-pill-offset'];
            $this->assertArrayHasKey( 'boxShadow', $this->patch( 'divi/testimonial', 'card', $kit, 'light' )['module']['decoration'] );
            $found = $scoped->violations( KitResolver::resolve( self::markup( 'divi/testimonial', 'card', 'light' ), $kit ) );
            $this->assertNotSame( [], $found );
            foreach ( $found as $v ) {
                $this->assertStringStartsWith( 'divi/testimonial: module.decoration.boxShadow.', $v );
            }
            $group = '<!-- wp:divi/group {"builderVersion":"5.15.1","aied":{"kit":"card","on":"light"}} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>x</p>"}}}} /--><!-- /wp:divi/group -->';
            $this->assertSame( [], $scoped->violations( KitResolver::resolve( $group, $kit ) ), 'a divi/testimonial line does not apply to a group card' );
        } finally {
            unlink( $file );
        }
    }

    public function testTheTestimonialPatchIsTheGroupCardPatch(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( [ 'card', 'panel' ] as $role ) {
                foreach ( self::CARD_BANDS as $on ) {
                    $this->assertSame( KitAdapters::patch( 'divi/group', $role, $on, $kit ), $this->patch( 'divi/testimonial', $role, $kit, $on ), "$label $role on $on: with every testimonial decision proven, the group card patch" );
                }
            }
        }
    }

    public function testTheCounterPatchIsTheGroupRulePatch(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( [ 'rule', 'rule-strong' ] as $role ) {
                foreach ( self::RULE_BANDS as $on ) {
                    $this->assertSame( KitAdapters::patch( 'divi/group', $role, $on, $kit ), $this->patch( 'divi/number-counter', $role, $kit, $on ), "$label $role on $on: with every counter decision proven, the group top edge" );
                }
            }
        }
    }

    public function testTheBlurbChipTakesTheIconChipColours(): void
    {
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::CHIP_BANDS as $on ) {
                $icon  = self::leaves( $this->patch( 'divi/icon', 'chip', $kit, $on ) );
                $blurb = self::leaves( $this->patch( 'divi/blurb', 'chip', $kit, $on ) );
                $this->assertSame(
                    [
                        'imageIcon.decoration.border.desktop.value.radius'     => $icon['module.decoration.border.desktop.value.radius'],
                        'imageIcon.decoration.background.desktop.value.color' => $icon['module.decoration.background.desktop.value.color'],
                        'imageIcon.advanced.color.desktop.value'              => $icon['icon.advanced.color.desktop.value'],
                    ],
                    $blurb,
                    "$id on $on: the icon chip's radius, fill and glyph at the blurb's own paths, and nothing else (the recipe keeps padding and size)"
                );
            }
        }
    }

    public function testTheAccentRuleIsTheAccentAtTheStrongWidth(): void
    {
        $this->assertSame( 'accent', KitAdapters::ACCENT_RULE_COLOUR );
        $p = 'divider.advanced.line.desktop.value.';
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( [ 'light', 'surface' ] as $on ) {
                $patch  = $this->patch( 'divi/divider', 'rule-accent', $kit, $on );
                $leaves = self::leaves( $patch );
                $keys   = array_keys( $leaves );
                sort( $keys );
                $this->assertSame( [ $p . 'color', $p . 'style', $p . 'weight' ], $keys, "$label on $on: the divider's line only" );
                $this->assertSame( '{{aied:color:accent}}', $leaves[ $p . 'color' ], "$label on $on" );
                $this->assertSame( $kit['border']['style'], $leaves[ $p . 'style' ], "$label on $on: the kit's style" );
                $this->assertSame( KitAdapters::ruleWidth( 'rule-strong', $kit['border'] ), $leaves[ $p . 'weight' ], "$label on $on: the strong width" );
                $strong = self::leaves( $this->patch( 'divi/divider', 'rule-strong', $kit, $on ) );
                $this->assertSame( $strong[ $p . 'weight' ], $leaves[ $p . 'weight' ] );
                $this->assertSame( $strong[ $p . 'style' ], $leaves[ $p . 'style' ], 'the same line as rule-strong, in the accent colour' );
            }
        }
        // The style fallback: an unproven divider style draws the accent mark solid, at the solid width.
        $dashed  = $this->shippedKits()['hairline-dashed'];
        $planted = array_merge( KitAdapters::RULE_PROVEN + KitAdapters::CARRIER_PROVEN, [ 'divider-dashed' => false ] );
        $off     = self::leaves( KitAdapters::rulePatch( 'divi/divider', 'rule-accent', 'light', $dashed, $planted ) );
        $this->assertSame( 'solid', $off[ $p . 'style' ] );
        $this->assertSame( '{{aied:color:accent}}', $off[ $p . 'color' ] );
        $double = $this->shippedKits()['double-frame'];
        $off    = self::leaves( KitAdapters::rulePatch( 'divi/divider', 'rule-accent', 'light', $double, array_merge( KitAdapters::RULE_PROVEN + KitAdapters::CARRIER_PROVEN, [ 'divider-double' => false ] ) ) );
        $this->assertSame( [ 'solid', KitAdapters::ruleWidth( 'rule-strong', array_merge( $double['border'], [ 'style' => 'solid' ] ) ) ], [ $off[ $p . 'style' ], $off[ $p . 'weight' ] ], 'the double floor follows the style actually written' );
        // rulePatch() keeps its default (RULE_PROVEN): the accent mark needs the carrier decisions patch() passes.
        $this->assertSame( [], KitAdapters::rulePatch( 'divi/divider', 'rule-accent', 'light', $dashed ) );
    }

    public function testTheAccentRuleTakesTwoBandsOnly(): void
    {
        $this->assertSame( [ 'light', 'surface' ], KitAdapters::ACCENT_RULE_BANDS );
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::ALL_BANDS as $on ) {
                $patch = KitAdapters::patch( 'divi/divider', 'rule-accent', $on, $kit );
                if ( in_array( $on, KitAdapters::ACCENT_RULE_BANDS, true ) ) {
                    $this->assertIsArray( $patch, "$id on $on" );
                } else {
                    $this->assertNull( $patch, "$id: rule-accent is refused on $on (the accent is below the floor there)" );
                }
            }
        }
    }

    public function testUnprovenDecisionsFallBackOrRefuse(): void
    {
        $all      = KitAdapters::CARRIER_PROVEN;
        $off      = static fn ( string $key ): array => array_merge( $all, [ $key => false ] );
        $rules    = static fn ( string $key ): array => array_merge( KitAdapters::RULE_PROVEN + $all, [ $key => false ] );
        $card     = static fn ( string $role, string $on, array $kit, array $proven ): array => KitAdapters::card( $role, $on, $kit, null, 'divi/testimonial', $proven );
        $blurb    = static fn ( string $on, array $kit, array $proven ): array => KitAdapters::chipPatch( 'divi/blurb', $on, $kit, KitAdapters::CHIP_PROVEN, KitAdapters::RULE_PROVEN, $proven );
        $soft     = $this->shippedKits()['soft-solid'];
        $covered  = [];

        // With everything proven, card() on a testimonial is the group card (the default proven map) and never empty.
        foreach ( [ 'card', 'panel' ] as $role ) {
            $this->assertSame( KitAdapters::card( $role, 'light', $soft ), $card( $role, 'light', $soft, $all ) );
        }

        // Base decisions of the testimonial: the carrier is refused.
        foreach ( [ 'testimonial-fill', 'testimonial-frame', 'testimonial-radius', 'testimonial-quote' ] as $key ) {
            foreach ( [ 'card', 'panel' ] as $role ) {
                foreach ( self::CARD_BANDS as $on ) {
                    $this->assertSame( [], $card( $role, $on, $soft, $off( $key ) ), "$key unproven: no testimonial $role on $on" );
                }
            }
            $this->assertNotSame( [], KitAdapters::card( 'card', 'light', $soft, null, 'divi/group', $off( $key ) ), "$key does not touch a group card" );
            $covered[] = $key;
        }

        // Underline: the full frame at 1px.
        $under = $this->kitWhere( static fn ( array $k ): bool => 'bottom' === ( $k['border']['sides'] ?? 'all' ), 'an underline' );
        $styles = $card( 'card', 'light', $under, $off( 'testimonial-underline' ) )['module']['decoration']['border']['desktop']['value']['styles'];
        $this->assertSame( [ 'all' ], array_keys( $styles ) );
        $this->assertSame( '1px', $styles['all']['width'] );
        $this->assertSame( $under['border']['style'], $styles['all']['style'] );
        $this->assertArrayHasKey( 'bottom', $card( 'card', 'light', $under, $all )['module']['decoration']['border']['desktop']['value']['styles'], 'proven: the underline form' );
        $covered[] = 'testimonial-underline';

        // Padding: a card writes no padding.
        $this->assertArrayHasKey( 'spacing', $card( 'card', 'light', $soft, $all )['module']['decoration'] );
        $this->assertArrayNotHasKey( 'spacing', $card( 'card', 'light', $soft, $off( 'testimonial-padding' ) )['module']['decoration'] );
        $covered[] = 'testimonial-padding';

        // Rest shadow and hover shadow, each by its own key.
        $rest = $this->kitWhere( static fn ( array $k ): bool => 'none' !== $k['shadow']['rest'], 'a rest shadow' );
        $this->assertArrayHasKey( 'value', $card( 'card', 'light', $rest, $all )['module']['decoration']['boxShadow']['desktop'] );
        $this->assertArrayNotHasKey( 'boxShadow', $card( 'card', 'light', $rest, $off( 'testimonial-shadow' ) )['module']['decoration'], 'no rest value and no hover value left: no shadow key' );
        $this->assertArrayNotHasKey( 'boxShadow', $card( 'panel', 'light', $rest, $off( 'testimonial-shadow' ) )['module']['decoration'] );
        $covered[] = 'testimonial-shadow';
        $hoverOnly = $this->kitWhere( static fn ( array $k ): bool => 'none' === $k['shadow']['rest'] && 'soft' === $k['shadow']['hover'], 'a hover-only shadow' );
        $this->assertArrayHasKey( 'hover', $card( 'card', 'light', $hoverOnly, $all )['module']['decoration']['boxShadow']['desktop'] );
        $this->assertArrayNotHasKey( 'boxShadow', $card( 'card', 'light', $hoverOnly, $off( 'testimonial-hover-shadow' ) )['module']['decoration'] );
        $this->assertSame( $card( 'card', 'light', $rest, $all ), $card( 'card', 'light', $rest, $off( 'testimonial-hover-shadow' ) ), 'a rest shadow pair keeps its rest value' );
        $covered[] = 'testimonial-hover-shadow';

        // Lift and hover frame.
        $lift = $this->kitWhere( static fn ( array $k ): bool => 'lift' === $k['hover'], 'a lift' );
        $this->assertArrayHasKey( 'transform', $card( 'card', 'light', $lift, $all )['module']['decoration'] );
        $this->assertArrayNotHasKey( 'transform', $card( 'card', 'light', $lift, $off( 'testimonial-hover-lift' ) )['module']['decoration'] );
        $covered[] = 'testimonial-hover-lift';
        $swap = $this->kitWhere( static fn ( array $k ): bool => in_array( $k['hover'], [ 'fill-swap', 'invert' ], true ) && in_array( $k['border']['color'], [ 'same', 'border' ], true ) && 'bottom' !== ( $k['border']['sides'] ?? 'all' ), 'a hover frame' );
        $this->assertArrayHasKey( 'hover', $card( 'card', 'light', $swap, $all )['module']['decoration']['border']['desktop'] );
        $this->assertArrayNotHasKey( 'hover', $card( 'card', 'light', $swap, $off( 'testimonial-hover-frame' ) )['module']['decoration']['border']['desktop'] );
        $covered[] = 'testimonial-hover-frame';

        // Blurb: the radius is the base; fill and glyph each by their key, both needed on an accent band.
        foreach ( self::CHIP_BANDS as $on ) {
            $this->assertSame( [], $blurb( $on, $soft, $off( 'blurb-icon-radius' ) ), "blurb-icon-radius unproven: no blurb chip on $on" );
        }
        $covered[] = 'blurb-icon-radius';
        foreach ( [ 'blurb-icon-fill' => 'imageIcon.decoration.background.desktop.value.color', 'blurb-icon-glyph' => 'imageIcon.advanced.color.desktop.value' ] as $key => $path ) {
            $this->assertSame( [], $blurb( 'accent', $soft, $off( $key ) ), "$key unproven: the accent band is refused" );
            foreach ( [ 'light', 'surface', 'dark' ] as $on ) {
                $leaves = self::leaves( $blurb( $on, $soft, $off( $key ) ) );
                $this->assertArrayNotHasKey( $path, $leaves, "$key unproven on $on: nothing written for it" );
                $this->assertArrayHasKey( 'imageIcon.decoration.border.desktop.value.radius', $leaves );
                $this->assertCount( 2, $leaves );
            }
            $covered[] = $key;
        }

        // Counter: the base refuses; a style falls back to solid on the counter only; alpha gives the strong colour.
        $this->assertSame( [], KitAdapters::rulePatch( 'divi/number-counter', 'rule', 'light', $soft, $rules( 'counter-top' ) ) );
        $this->assertNotSame( [], KitAdapters::rulePatch( 'divi/group', 'rule', 'light', $soft, $rules( 'counter-top' ) ) );
        $covered[] = 'counter-top';
        $top = 'module.decoration.border.desktop.value.styles.top.style';
        foreach ( [ 'dashed', 'dotted', 'double' ] as $style ) {
            $kit = $this->kitWhere( static fn ( array $k ): bool => $style === $k['border']['style'], "a $style border" );
            $this->assertSame( 'solid', self::leaves( KitAdapters::rulePatch( 'divi/number-counter', 'rule-strong', 'light', $kit, $rules( "counter-top-$style" ) ) )[ $top ] );
            $this->assertSame( $style, self::leaves( KitAdapters::rulePatch( 'divi/group', 'rule-strong', 'light', $kit, $rules( "counter-top-$style" ) ) )[ $top ], 'the group rule is untouched' );
            $covered[] = "counter-top-$style";
        }
        $colour = 'module.decoration.border.desktop.value.styles.top.color';
        foreach ( [ 'accent', 'dark', 'panel' ] as $on ) {
            $quiet = self::leaves( KitAdapters::rulePatch( 'divi/number-counter', 'rule', $on, $soft, $rules( 'counter-top-alpha' ) ) )[ $colour ];
            $this->assertSame( '{{aied:color:' . KitAdapters::RULE_COLOUR['rule-strong'][ $on ] . '}}', $quiet, "counter-top-alpha unproven: the strong colour on $on" );
        }
        $covered[] = 'counter-top-alpha';

        // The accent mark: no preset proof, no mark; no style proof, a solid mark (the plain divider rule keeps its style).
        foreach ( [ 'light', 'surface' ] as $on ) {
            $this->assertSame( [], KitAdapters::rulePatch( 'divi/divider', 'rule-accent', $on, $soft, $rules( 'divider-accent-over-preset' ) ) );
        }
        $this->assertNotSame( [], KitAdapters::rulePatch( 'divi/divider', 'rule', 'light', $soft, $rules( 'divider-accent-over-preset' ) ) );
        $covered[] = 'divider-accent-over-preset';
        $dashed = $this->shippedKits()['hairline-dashed'];
        $line   = 'divider.advanced.line.desktop.value.style';
        $this->assertSame( 'solid', self::leaves( KitAdapters::rulePatch( 'divi/divider', 'rule-accent', 'light', $dashed, $rules( 'divider-accent-style' ) ) )[ $line ] );
        $this->assertSame( 'dashed', self::leaves( KitAdapters::rulePatch( 'divi/divider', 'rule', 'light', $dashed, $rules( 'divider-accent-style' ) ) )[ $line ] );
        $covered[] = 'divider-accent-style';

        $this->assertEqualsCanonicalizing( array_keys( KitAdapters::CARRIER_PROVEN ), $covered, 'every one of the twenty decisions has its fallback proven' );
    }

    public function testUnknownCombinationsAreNull(): void
    {
        $kit = $this->shippedKits()['soft-solid'];
        foreach ( [ 'card', 'panel' ] as $role ) {
            foreach ( [ 'light', 'surface' ] as $on ) {
                foreach ( [ 'inverted', 'accent' ] as $ground ) {
                    $this->assertNull( KitAdapters::patch( 'divi/testimonial', $role, $on, $kit, $ground ), "no ground on a testimonial ($role, $on, $ground)" );
                    $this->assertSame( [], KitAdapters::card( $role, $on, $kit, $ground, 'divi/testimonial' ) );
                    $this->assertIsArray( KitAdapters::patch( 'divi/group', $role, $on, $kit, $ground ), 'a group still takes it' );
                }
            }
        }
        foreach ( [ 'divi/group', 'divi/column', 'divi/number-counter', 'divi/icon', 'divi/blurb', 'divi/testimonial', 'divi/text' ] as $block ) {
            foreach ( self::ALL_BANDS as $on ) {
                $this->assertNull( KitAdapters::patch( $block, 'rule-accent', $on, $kit ), "rule-accent on $block / $on" );
            }
        }
        foreach ( [ 'accent', 'dark', 'panel', 'photo', '' ] as $on ) {
            $this->assertNull( KitAdapters::patch( 'divi/divider', 'rule-accent', $on, $kit ), "rule-accent on '$on'" );
        }
        foreach ( [ 'inverted', 'accent' ] as $ground ) {
            $this->assertNull( KitAdapters::patch( 'divi/divider', 'rule-accent', 'light', $kit, $ground ), 'a rule takes no ground' );
            $this->assertNull( KitAdapters::patch( 'divi/blurb', 'chip', 'light', $kit, $ground ), 'a chip takes no ground' );
            $this->assertNull( KitAdapters::patch( 'divi/number-counter', 'rule', 'light', $kit, $ground ), 'a rule takes no ground' );
        }
        foreach ( [ 'chip', 'rule', 'rule-strong', 'control', 'item', 'form' ] as $role ) {
            $this->assertNull( KitAdapters::patch( 'divi/testimonial', $role, 'light', $kit ), "$role on a testimonial" );
        }
        foreach ( [ 'card', 'panel', 'rule', 'control', 'item' ] as $role ) {
            $this->assertNull( KitAdapters::patch( 'divi/blurb', $role, 'light', $kit ), "$role on a blurb" );
        }
        foreach ( [ 'card', 'panel', 'chip', 'rule-accent', 'item' ] as $role ) {
            $this->assertNull( KitAdapters::patch( 'divi/number-counter', $role, 'light', $kit ), "$role on a counter" );
        }
        foreach ( [ 'photo', 'panel', 'LIGHT', '' ] as $on ) {
            $this->assertNull( KitAdapters::patch( 'divi/testimonial', 'card', $on, $kit ), "a testimonial card on '$on'" );
            $this->assertNull( KitAdapters::patch( 'divi/blurb', 'chip', $on, $kit ), "a blurb chip on '$on'" );
        }
        $this->assertNull( KitAdapters::patch( 'divi/number-counter', 'rule', 'photo', $kit ) );
        foreach ( self::newCells() as [ $block, $role, $on ] ) {
            $this->assertNull( KitAdapters::patch( $block, $role, $on, [ 'id' => 'broken' ] ), "an invalid kit ($block $role $on)" );
            $this->assertNull( KitAdapters::patch( $block, $role, $on, array_merge( $kit, [ 'shadow' => 'x' ] ) ), "an invalid kit ($block $role $on)" );
        }
    }

    public function testTheGroupColumnDividerIconPatchesAreUnchanged(): void
    {
        $rows = [];
        foreach ( self::OLD_APPLIED as $block => $roles ) {
            foreach ( $roles as $role ) {
                foreach ( self::ALL_BANDS as $on ) {
                    foreach ( [ null, 'inverted', 'accent' ] as $ground ) {
                        foreach ( StyleKits::ids() as $id ) {
                            $rows[] = [ $block, $role, $on, $ground, $id, KitAdapters::patch( $block, $role, $on, (array) StyleKits::get( $id ), $ground ) ];
                        }
                    }
                }
            }
        }
        $this->assertCount( 11400, $rows );
        $this->assertSame( self::OLD_PATCHES_SHA256, hash( 'sha256', (string) json_encode( $rows ) ), 'every old (block, role, band, ground, kit) patch is byte-identical to the Task 3 base' );
        foreach ( self::OLD_APPLIED as $block => $roles ) {
            $this->assertSame( $roles, array_slice( KitAdapters::APPLIED[ $block ], 0, count( $roles ) ), "$block keeps its old roles, in order (new ones are appended)" );
        }
    }

    public function testTheMarkedTestimonialKeepsTheRecipesOverflowAndLayout(): void
    {
        // The hanging quote disc (`::before`, kx-testimonial-quote) stays unclipped only while the recipe's `overflow: visible`
        // survives: the adapter never writes overflow, and the merge keeps it under every kit, role and band.
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( [ 'card', 'panel' ] as $role ) {
                foreach ( self::CARD_BANDS as $on ) {
                    foreach ( array_keys( self::leaves( $this->patch( 'divi/testimonial', $role, $kit, $on ) ) ) as $leaf ) {
                        $this->assertStringNotContainsString( 'overflow', $leaf, "$id $role on $on" );
                        $this->assertStringNotContainsString( 'layout', $leaf, "$id $role on $on" );
                    }
                    $out = KitResolver::resolve( self::block( 'divi/testimonial', '"aied":{"kit":"' . $role . '","on":"' . $on . '"}' ), $kit );
                    $this->assertSame( 1, preg_match( '/^<!-- wp:divi\/testimonial (\{.*\}) \/-->$/s', $out, $m ) );
                    $attrs = json_decode( $m[1], true );
                    $this->assertSame( [ 'x' => 'visible', 'y' => 'visible' ], $attrs['module']['decoration']['overflow']['desktop']['value'] ?? null, "$id $role on $on: overflow x/y stay visible" );
                    $this->assertSame( 'block', $attrs['module']['decoration']['layout']['desktop']['value']['display'] ?? null );
                    $this->assertSame( 'Jordan Taylor', $attrs['author']['innerContent']['desktop']['value'] ?? null, 'the content is untouched' );
                    $this->assertArrayNotHasKey( 'aied', $attrs );
                }
            }
        }
    }

    public function testPatchValidatesInsideARealLayout(): void
    {
        $checked = 0;
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::newCells() as [ $block, $role, $on ] ) {
                $markup = KitResolver::resolve( self::markup( $block, $role, $on ), $kit );
                $this->assertSame( [], KitResolver::leftovers( $markup ), "$id $role $block $on" );
                $resolved = RecipeTokens::resolve( $markup, RecipeTokens::default() );
                $this->assertSame( [], RecipeTokens::found( $resolved ), "$id $role $block $on: every token resolved" );
                $result = LayoutGate::check( $resolved );
                $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                $this->assertTrue( $result->isValid(), "$id $role on $block / $on must pass the gate, got: $codes" );
                ++$checked;
            }
        }
        $this->assertSame( 40 * ( 4 + 4 + 4 + 5 + 5 + 2 ), $checked );
    }

    public function testThePatchIsDeterministic(): void
    {
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::newCells() as [ $block, $role, $on ] ) {
                $this->assertSame( json_encode( $this->patch( $block, $role, $kit, $on ) ), json_encode( $this->patch( $block, $role, $kit, $on ) ), "$id $role $block $on" );
            }
        }
    }

    public function testAppliedNamesTheCarriers(): void
    {
        $this->assertSame( [ 'card', 'panel' ], KitAdapters::APPLIED['divi/testimonial'] ?? null );
        $this->assertSame( [ 'chip' ], KitAdapters::APPLIED['divi/blurb'] ?? null );
        $this->assertSame( [ 'rule', 'rule-strong' ], KitAdapters::APPLIED['divi/number-counter'] ?? null );
        $this->assertSame( [ 'rule', 'rule-strong', 'rule-accent' ], KitAdapters::APPLIED['divi/divider'] ?? null );
        $this->assertSame( [ 'divi/group', 'divi/column' ], KitAdapters::GROUND_BLOCKS );
        foreach ( KitAdapters::APPLIED as $block => $roles ) {
            if ( 'divi/divider' !== $block ) {
                $this->assertNotContains( 'rule-accent', $roles, "$block takes no rule-accent (Ruling 2)" );
            }
        }
    }
}
