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
 * The rule adapter (rules and chips plan Task 3): a kit's decisions become the attributes of a thin line. Roles `rule` (quiet)
 * and `rule-strong` on `divi/divider` (the divider's own line: colour, weight, style), `divi/group` and `divi/column` (the top
 * edge: `module.decoration.border.styles.{all,top}`), on five bands. Only the paths the effect probe passed ON each carrier are
 * written (fixtures/shapes/kit-rule-vocabulary.md, results fixtures/shapes/probe-results-kit-rule.json); the sixteen
 * conditional decisions (KitAdapters::RULE_PROVEN) are pinned to that file's "Adapter decisions" table.
 */
class KitRuleAdapterTest extends TestCase
{
    private const BANDS    = [ 'light', 'surface', 'accent', 'dark', 'panel' ];
    private const ROLES    = [ 'rule', 'rule-strong' ];
    private const CARRIERS = [ 'divi/divider', 'divi/group', 'divi/column' ];

    private const COLOR_ROLES = [ 'bg', 'surface', 'text', 'muted', 'accent', 'accent-text', 'border', 'inverse', 'overlay' ];

    private const VOCABULARY = __DIR__ . '/../fixtures/shapes/kit-rule-vocabulary.md';
    private const CANDIDATES = __DIR__ . '/../fixtures/shapes/candidates/kit-rule.json';
    private const RESULTS    = __DIR__ . '/../fixtures/shapes/probe-results-kit-rule.json';

    /** The pinned colour table (Task 3 brief; Task 4 proves every cell): role => band => colour role[:alpha]. */
    private const COLOUR_TABLE = [
        'rule'        => [ 'light' => 'border', 'surface' => 'border', 'accent' => 'accent-text:40', 'dark' => 'inverse:40', 'panel' => 'bg:40' ],
        'rule-strong' => [ 'light' => 'text', 'surface' => 'text', 'accent' => 'accent-text', 'dark' => 'inverse', 'panel' => 'bg' ],
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
     * Every shipped kit plus variants of a full-frame and an underline kit over every border style x width x colour the
     * schema allows, so every branch of the width table and the style fallback runs.
     *
     * @return array<string,array<string,mixed>>
     */
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
    private function patch( string $block, string $role, array $kit, string $on ): array
    {
        $patch = KitAdapters::patch( $block, $role, $on, $kit );
        $this->assertIsArray( $patch, "{$kit['id']} $role on $block / $on must have a patch" );
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

    private const WRAP_OPEN  = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.15.1"} --><!-- wp:divi/row {"builderVersion":"5.15.1"} -->';
    private const WRAP_CLOSE = '<!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private const TEXT = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Text</p>"}}},"builderVersion":"5.15.1"} /-->';

    private function marker( string $role, string $on ): string
    {
        return '"aied":{"kit":"' . $role . '","on":"' . $on . '"}';
    }

    /** A real layout with a marked $block of $role on band $on. */
    private function markup( string $block, string $role, string $on ): string
    {
        $marker = $this->marker( $role, $on );
        $column = '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1"} -->';
        if ( 'divi/divider' === $block ) {
            $body = $column . '<!-- wp:divi/divider {"builderVersion":"5.15.1",' . $marker . '} /-->' . '<!-- /wp:divi/column -->';
        } elseif ( 'divi/group' === $block ) {
            $body = $column . '<!-- wp:divi/group {"module":{"decoration":{"layout":{"desktop":{"value":{"flexDirection":"column","rowGap":"10px"}}}}},"builderVersion":"5.15.1",' . $marker . '} -->' . self::TEXT . '<!-- /wp:divi/group --><!-- /wp:divi/column -->';
        } else {
            $body = '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1",' . $marker . '} -->' . self::TEXT . '<!-- /wp:divi/column -->';
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

    /**
     * Every path of a candidate that PASSED (it changed the carrier's computed styles; not css-only), per carrier ('divider',
     * 'group', 'column'): the keys of its `set`, plus the library form (`styles.all.width` 0px) the two control candidates
     * rendered as their base. carrier => dotted path => true.
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
            if ( in_array( $candidate['id'], [ 'kr-group-control', 'kr-column-control' ], true ) ) {
                // The control's base is the library form the adapter writes: `all` 0px plus the `top` width it sets.
                $paths[ (string) $candidate['block'] ]['module.decoration.border.desktop.value.styles.all.width'] = true;
            }
        }
        return $paths;
    }

    private static function carrierKey( string $block ): string
    {
        return substr( $block, strlen( 'divi/' ) );
    }

    public function testEveryEmittedRulePathIsInTheRegistryAndNotDeadOnItsCarrier(): void
    {
        $registry = ShapeRegistry::load();
        $dead     = DeadOnModule::load();
        $missing  = [];
        $killed   = [];
        $checked  = 0;
        foreach ( $this->shippedKits() as $label => $kit ) {
            foreach ( self::CARRIERS as $block ) {
                foreach ( self::ROLES as $role ) {
                    foreach ( self::BANDS as $on ) {
                        $markup = KitResolver::resolve( $this->markup( $block, $role, $on ), $kit );
                        $this->assertSame( [], KitResolver::leftovers( $markup ), "$label $role on $block / $on" );
                        $this->assertStringNotContainsString( '"aied":{', $markup );
                        foreach ( ShapePaths::fromMarkup( $markup ) as $path ) {
                            if ( ! $registry->coversLeaf( $path ) ) {
                                $missing[ $path ] = "$label $role on $block / $on";
                            }
                        }
                        foreach ( $dead->violations( $markup ) as $v ) {
                            $killed[ $v ] = $label;
                        }
                        $json = (string) json_encode( $this->patch( $block, $role, $kit, $on ) );
                        foreach ( [ 'tablet', 'phone', 'tabletWide', 'phoneWide', 'sticky', 'hover' ] as $state ) {
                            $this->assertStringNotContainsString( "\"$state\"", $json, "only rest desktop values are written ($label $role on $block / $on)" );
                        }
                        ++$checked;
                    }
                }
            }
        }
        $this->assertSame( 40 * 5 * 2 * 3, $checked );
        $this->assertSame( [], $missing, 'the rule adapter emits paths the registry does not know' );
        $this->assertSame( [], array_keys( $killed ), 'the rule adapter emits paths that are dead on their carrier' );
    }

    public function testEveryLeafIsAProbedPathOnItsCarrier(): void
    {
        $probed = $this->probedPaths();
        foreach ( [ 'divider', 'group', 'column' ] as $carrier ) {
            $this->assertGreaterThanOrEqual( 3, count( $probed[ $carrier ] ?? [] ), "$carrier has probed paths" );
        }
        $unprobed = [];
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::CARRIERS as $block ) {
                foreach ( self::ROLES as $role ) {
                    foreach ( self::BANDS as $on ) {
                        foreach ( array_keys( self::leaves( $this->patch( $block, $role, $kit, $on ) ) ) as $path ) {
                            if ( ! isset( $probed[ self::carrierKey( $block ) ][ $path ] ) ) {
                                $unprobed[ self::carrierKey( $block ) . ' ' . $path ] = "$label $role on $on";
                            }
                        }
                    }
                }
            }
        }
        $this->assertSame( [], $unprobed, 'every leaf the rule adapter writes was measured to PASS on its own carrier (a divi/button or card result proves nothing here)' );

        // A planted leaf fails the check: a padding on a divider, a radius on a group were never probed on the rule carriers.
        $kit    = $this->shippedKits()['soft-solid'];
        $patch  = $this->patch( 'divi/divider', 'rule', $kit, 'light' );
        $patch['module'] = [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => '{{aied:kit:space-card}}' ] ] ] ] ];
        $bad = array_filter( array_keys( self::leaves( $patch ) ), static fn ( string $p ): bool => ! isset( $probed['divider'][ $p ] ) );
        $this->assertSame( [ 'module.decoration.spacing.desktop.value.padding' ], array_values( $bad ) );
    }

    public function testRuleProvenMatchesTheVocabularyTable(): void
    {
        $table = $this->vocabularyDecisions();
        $this->assertSame(
            [
                'divider-line', 'divider-dashed', 'divider-dotted', 'divider-double', 'divider-alpha',
                'group-top', 'group-top-dashed', 'group-top-dotted', 'group-top-double', 'group-top-alpha', 'group-top-in-card',
                'column-top', 'column-top-dashed', 'column-top-dotted', 'column-top-double', 'column-top-alpha',
            ],
            array_keys( $table ),
            'sixteen decisions, in the table\'s order'
        );
        $this->assertSame( $table, KitAdapters::RULE_PROVEN, 'KitAdapters::RULE_PROVEN is exactly the Adapter decisions table of kit-rule-vocabulary.md' );
        $this->assertSame( array_fill_keys( array_keys( $table ), true ), KitAdapters::RULE_PROVEN, 'all sixteen passed on the real Divi 5.15.1' );
    }

    public function testUnprovenStylesFallBackToSolidOnThatCarrierOnly(): void
    {
        $kit = $this->shippedKits()['hairline-dashed'];
        $this->assertSame( 'dashed', $kit['border']['style'] );
        $planted = array_merge( KitAdapters::RULE_PROVEN, [ 'divider-dashed' => false ] );
        $divider = self::leaves( KitAdapters::rulePatch( 'divi/divider', 'rule', 'light', $kit, $planted ) );
        $this->assertSame( 'solid', $divider['divider.advanced.line.desktop.value.style'], 'the divider cannot draw dashed: solid' );
        $group = self::leaves( KitAdapters::rulePatch( 'divi/group', 'rule', 'light', $kit, $planted ) );
        $this->assertSame( 'dashed', $group['module.decoration.border.desktop.value.styles.top.style'], 'the group rule is still dashed' );
        $this->assertSame( 'dashed', self::leaves( KitAdapters::rulePatch( 'divi/column', 'rule', 'light', $kit, $planted ) )['module.decoration.border.desktop.value.styles.top.style'] );
        // Proven, every style is written as the kit has it; the width does not depend on the fallback for a dashed 1px kit.
        $this->assertSame( 'dashed', self::leaves( KitAdapters::rulePatch( 'divi/divider', 'rule', 'light', $kit ) )['divider.advanced.line.desktop.value.style'] );

        foreach ( [ 'dotted' => 'dotted-pill', 'double' => 'double-frame' ] as $style => $id ) {
            $k = $this->shippedKits()[ $id ];
            $this->assertSame( $style, $k['border']['style'] );
            foreach ( [ 'divi/divider' => 'divider', 'divi/group' => 'group-top', 'divi/column' => 'column-top' ] as $block => $prefix ) {
                $off  = self::leaves( KitAdapters::rulePatch( $block, 'rule-strong', 'light', $k, array_merge( KitAdapters::RULE_PROVEN, [ "$prefix-$style" => false ] ) ) );
                $path = 'divi/divider' === $block ? 'divider.advanced.line.desktop.value.style' : 'module.decoration.border.desktop.value.styles.top.style';
                $this->assertSame( 'solid', $off[ $path ], "$block: $style unproven falls back to solid" );
                $this->assertSame( $style, self::leaves( KitAdapters::rulePatch( $block, 'rule-strong', 'light', $k ) )[ $path ], "$block: $style proven is written" );
            }
        }
        // The double floor follows the style that is actually written: a solid fallback is not forced to 3px.
        $double = $this->shippedKits()['double-frame'];
        $solid  = self::leaves( KitAdapters::rulePatch( 'divi/divider', 'rule', 'light', $double, array_merge( KitAdapters::RULE_PROVEN, [ 'divider-double' => false ] ) ) );
        $this->assertSame( '2px', $solid['divider.advanced.line.desktop.value.weight'], 'solid fallback of a 4px double kit: the quiet cap (2px)' );
        $this->assertSame( '3px', self::leaves( KitAdapters::rulePatch( 'divi/divider', 'rule', 'light', $double ) )['divider.advanced.line.desktop.value.weight'], 'double proven: at least 3px' );
    }

    public function testUnprovenAlphaGivesTheStrongColour(): void
    {
        $kit = $this->shippedKits()['soft-solid'];
        foreach ( [ 'divi/divider' => 'divider', 'divi/group' => 'group-top', 'divi/column' => 'column-top' ] as $block => $prefix ) {
            $planted = array_merge( KitAdapters::RULE_PROVEN, [ "$prefix-alpha" => false ] );
            foreach ( self::BANDS as $on ) {
                $quiet = self::leaves( KitAdapters::rulePatch( $block, 'rule', $on, $kit, $planted ) );
                $colourPath = 'divi/divider' === $block ? 'divider.advanced.line.desktop.value.color' : 'module.decoration.border.desktop.value.styles.top.color';
                $expected = in_array( $on, [ 'accent', 'dark', 'panel' ], true ) ? self::COLOUR_TABLE['rule-strong'][ $on ] : self::COLOUR_TABLE['rule'][ $on ];
                $this->assertSame( '{{aied:color:' . $expected . '}}', $quiet[ $colourPath ], "$block quiet on $on with alpha unproven" );
                $this->assertDoesNotMatchRegularExpression( '/:\d\d\}\}$/', (string) $quiet[ $colourPath ] );
            }
            // Strong never uses alpha, so it is unchanged.
            $this->assertSame(
                KitAdapters::rulePatch( $block, 'rule-strong', 'dark', $kit ),
                KitAdapters::rulePatch( $block, 'rule-strong', 'dark', $kit, $planted )
            );
            // And another carrier's alpha decision does not leak.
            $this->assertStringContainsString( ':40}}', (string) json_encode( KitAdapters::rulePatch( $block, 'rule', 'dark', $kit ) ), 'proven: the 40% form' );
        }
        $dividerOff = array_merge( KitAdapters::RULE_PROVEN, [ 'divider-alpha' => false ] );
        $this->assertStringContainsString( ':40}}', (string) json_encode( KitAdapters::rulePatch( 'divi/group', 'rule', 'panel', $kit, $dividerOff ) ), 'divider-alpha does not touch a group' );
    }

    public function testAnUnprovenBaseWritesNothingAndPatchAnswersNull(): void
    {
        $kit = $this->shippedKits()['soft-solid'];
        foreach ( [ 'divi/divider' => 'divider-line', 'divi/group' => 'group-top', 'divi/column' => 'column-top' ] as $block => $decision ) {
            $this->assertSame( [], KitAdapters::rulePatch( $block, 'rule', 'light', $kit, array_merge( KitAdapters::RULE_PROVEN, [ $decision => false ] ) ), "$block: the base is not proven, nothing is written" );
        }
    }

    public function testTheRuleWidthTableIsRulingsOneAndTwo(): void
    {
        // Rulings 1 and 2 (plan Task 3): rule = max(1, min(2, w)); rule-strong = max(2, min(4, w + 1)); a double is at least 3px.
        $expected = [
            '0px' => [ '1px', '2px' ],
            '1px' => [ '1px', '2px' ],
            '2px' => [ '2px', '3px' ],
            '3px' => [ '2px', '4px' ],
            '4px' => [ '2px', '4px' ],
        ];
        foreach ( $expected as $width => [ $rule, $strong ] ) {
            $border = [ 'width' => $width, 'style' => 'solid', 'color' => 'text' ];
            $this->assertSame( $rule, KitAdapters::ruleWidth( 'rule', $border ), "rule at $width" );
            $this->assertSame( $strong, KitAdapters::ruleWidth( 'rule-strong', $border ), "rule-strong at $width" );
        }
        $double = [ 'width' => '4px', 'style' => 'double', 'color' => 'text' ];
        $this->assertSame( '3px', KitAdapters::ruleWidth( 'rule', $double ), 'a double rule is never narrower than 3px (the double floor wins over the quiet cap)' );
        $this->assertSame( '4px', KitAdapters::ruleWidth( 'rule-strong', $double ) );
        $this->assertSame( '3px', KitAdapters::ruleWidth( 'rule', [ 'width' => '1px', 'style' => 'double', 'color' => 'text' ] ) );
        $this->assertSame( '3px', KitAdapters::ruleWidth( 'rule-strong', [ 'width' => '0px', 'style' => 'double', 'color' => 'text' ] ) );

        // The shipped kits, through patch(): every carrier writes the width the table gives.
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::ROLES as $role ) {
                $border = $kit['border'];
                $w      = (int) $border['width'];
                $want   = 'rule' === $role ? max( 1, min( 2, $w ) ) : max( 2, min( 4, $w + 1 ) );
                if ( 'double' === $border['style'] ) {
                    $want = max( 3, $want );
                }
                $this->assertSame( $want . 'px', self::leaves( $this->patch( 'divi/divider', $role, $kit, 'light' ) )['divider.advanced.line.desktop.value.weight'], "$label $role divider" );
                foreach ( [ 'divi/group', 'divi/column' ] as $block ) {
                    $this->assertSame( $want . 'px', self::leaves( $this->patch( $block, $role, $kit, 'surface' ) )['module.decoration.border.desktop.value.styles.top.width'], "$label $role $block" );
                }
            }
        }
        // The underline (sides bottom) does not change a rule.
        $under = $this->shippedKits()['underline-link'];
        $this->assertSame( 'bottom', $under['border']['sides'] ?? 'all' );
        $this->assertArrayNotHasKey( 'module.decoration.border.desktop.value.styles.bottom.width', self::leaves( $this->patch( 'divi/group', 'rule', $under, 'light' ) ) );
    }

    public function testTheDividerPatchWritesTheLineOnly(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::BANDS as $on ) {
                    $patch = $this->patch( 'divi/divider', $role, $kit, $on );
                    $keys  = array_keys( self::leaves( $patch ) );
                    sort( $keys );
                    $this->assertSame(
                        [ 'divider.advanced.line.desktop.value.color', 'divider.advanced.line.desktop.value.style', 'divider.advanced.line.desktop.value.weight' ],
                        $keys,
                        "$label $role on $on: colour, weight and style only (never position, show or any module.decoration)"
                    );
                    $this->assertSame( [ 'divider' ], array_keys( $patch ) );
                }
            }
        }
    }

    public function testTheTopRulePatchWritesTheTopEdgeOnly(): void
    {
        $base = 'module.decoration.border.desktop.value.styles.';
        $want = [ $base . 'all.color', $base . 'all.style', $base . 'all.width', $base . 'top.color', $base . 'top.style', $base . 'top.width' ];
        sort( $want );
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::BANDS as $on ) {
                    $group  = $this->patch( 'divi/group', $role, $kit, $on );
                    $column = $this->patch( 'divi/column', $role, $kit, $on );
                    $keys   = array_keys( self::leaves( $group ) );
                    sort( $keys );
                    $this->assertSame( $want, $keys, "$label $role on $on: the top edge only (no radius, padding, layout or sizing)" );
                    $this->assertSame( $group, $column, "$label $role on $on: group and column are the same patch" );
                    $leaves = self::leaves( $group );
                    $this->assertSame( '0px', $leaves[ $base . 'all.width' ], 'the other three sides stay zero' );
                    $this->assertSame( $leaves[ $base . 'all.style' ], $leaves[ $base . 'top.style' ] );
                    $this->assertSame( $leaves[ $base . 'all.color' ], $leaves[ $base . 'top.color' ] );
                    $this->assertArrayNotHasKey( 'bottom', $group['module']['decoration']['border']['desktop']['value']['styles'], 'an underline kit does not change a rule (always the top edge, Ruling 3)' );
                }
            }
        }
    }

    public function testTheRuleColourIsTheRoleTable(): void
    {
        $this->assertSame( self::COLOUR_TABLE, KitAdapters::RULE_COLOUR, 'the table is pinned literally' );
        foreach ( $this->shippedKits() as $label => $kit ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::BANDS as $on ) {
                    $token = '{{aied:color:' . self::COLOUR_TABLE[ $role ][ $on ] . '}}';
                    $this->assertSame( $token, self::leaves( $this->patch( 'divi/divider', $role, $kit, $on ) )['divider.advanced.line.desktop.value.color'], "$label $role divider on $on" );
                    foreach ( [ 'divi/group', 'divi/column' ] as $block ) {
                        $this->assertSame( $token, self::leaves( $this->patch( $block, $role, $kit, $on ) )['module.decoration.border.desktop.value.styles.top.color'], "$label $role $block on $on" );
                        $this->assertSame( $token, self::leaves( $this->patch( $block, $role, $kit, $on ) )['module.decoration.border.desktop.value.styles.all.color'], 'the same colour on all, so no side shows another' );
                    }
                }
            }
        }
    }

    public function testNoHexAndNoUnresolvedTokensBeyondColour(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::CARRIERS as $block ) {
                foreach ( self::ROLES as $role ) {
                    foreach ( self::BANDS as $on ) {
                        $json = (string) json_encode( $this->patch( $block, $role, $kit, $on ), JSON_UNESCAPED_SLASHES );
                        $this->assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $json, "$label $role $block $on" );
                        $this->assertDoesNotMatchRegularExpression( '/rgba?\(/i', $json, 'a rule has no literal colour' );
                        preg_match_all( '/\{\{[^}]*\}\}/', $json, $t );
                        $this->assertNotSame( [], $t[0] );
                        foreach ( $t[0] as $token ) {
                            $this->assertMatchesRegularExpression( '/^\{\{aied:color:(?:' . implode( '|', self::COLOR_ROLES ) . ')(?::40)?\}\}$/', $token, "$label $role $block $on" );
                        }
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
                foreach ( self::ROLES as $role ) {
                    foreach ( self::BANDS as $on ) {
                        $markup = KitResolver::resolve( $this->markup( $block, $role, $on ), $kit );
                        $this->assertSame( [], KitResolver::leftovers( $markup ), "$id $role $block $on" );
                        $resolved = RecipeTokens::resolve( $markup, RecipeTokens::default() );
                        $this->assertSame( [], RecipeTokens::found( $resolved ), "$id $role $block $on: every token resolved" );
                        $result = LayoutGate::check( $resolved );
                        $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                        $this->assertTrue( $result->isValid(), "$id $role on $block / $on must pass the gate, got: $codes" );
                        ++$checked;
                    }
                }
            }
        }
        $this->assertSame( 40 * 3 * 2 * 5, $checked );
    }

    public function testUnknownCombinationsAreNull(): void
    {
        $kit = $this->shippedKits()['soft-solid'];
        // A chip is not a rule: never on a divider or a column; a rule is not a chip.
        $this->assertNull( KitAdapters::patch( 'divi/divider', 'chip', 'light', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/column', 'chip', 'light', $kit ) );
        foreach ( [ 'inverted', 'accent' ] as $ground ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::CARRIERS as $block ) {
                    $this->assertNull( KitAdapters::patch( $block, $role, 'light', $kit, $ground ), "$role takes no ground ($block, $ground)" );
                }
            }
        }
        foreach ( [ 'photo', 'dark ', '', 'LIGHT', 'inverted' ] as $on ) {
            $this->assertNull( KitAdapters::patch( 'divi/divider', 'rule', $on, $kit ), "a rule on '$on'" );
        }
        foreach ( [ 'divi/text', 'divi/row', 'divi/section', 'divi/button', 'divi/image', 'divi/accordion', 'divi/toggle', 'divi/contact-form', 'divi/icon', 'divi/accordion-item' ] as $block ) {
            foreach ( self::ROLES as $role ) {
                $this->assertNull( KitAdapters::patch( $block, $role, 'light', $kit ), "$role on $block" );
            }
        }
        foreach ( [ 'rules', 'rule-row', 'divider', '' ] as $role ) {
            $this->assertNull( KitAdapters::patch( 'divi/divider', $role, 'light', $kit ), "unknown role '$role'" );
        }
        $this->assertNull( KitAdapters::patch( 'divi/divider', 'rule', 'light', [ 'id' => 'broken' ] ), 'an invalid kit' );
        $this->assertNull( KitAdapters::patch( 'divi/group', 'rule-strong', 'light', array_merge( $kit, [ 'shadow' => 'x' ] ) ), 'an invalid kit' );
        // Every combination the adapter does answer is non-empty (patch() never returns an empty patch).
        foreach ( self::CARRIERS as $block ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::BANDS as $on ) {
                    $this->assertNotSame( [], KitAdapters::patch( $block, $role, $on, $kit ) );
                }
            }
        }
    }

    public function testThePatchIsDeterministic(): void
    {
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::CARRIERS as $block ) {
                foreach ( self::ROLES as $role ) {
                    foreach ( self::BANDS as $on ) {
                        $this->assertSame( json_encode( $this->patch( $block, $role, $kit, $on ) ), json_encode( $this->patch( $block, $role, $kit, $on ) ), "$id $role $block $on" );
                    }
                }
            }
        }
    }

    public function testAppliedNamesTheRuleCarriers(): void
    {
        // carriers adapter: [ 'rule', 'rule-strong' ] -> + 'rule-accent' (the accent mark, KitCarrierAdapterTest).
        $this->assertSame( [ 'rule', 'rule-strong', 'rule-accent' ], KitAdapters::APPLIED['divi/divider'] ?? null );
        $this->assertSame( [ 'card', 'panel', 'rule', 'rule-strong', 'chip' ], KitAdapters::APPLIED['divi/group'] ?? null );
        $this->assertSame( [ 'card', 'panel', 'rule', 'rule-strong' ], KitAdapters::APPLIED['divi/column'] ?? null );
        foreach ( [ 'divi/row', 'divi/section', 'divi/text', 'divi/image' ] as $block ) {
            $this->assertArrayNotHasKey( $block, KitAdapters::APPLIED, "$block is not a rule carrier" );
        }
        $this->assertSame( [ 'light', 'surface', 'accent', 'dark', 'panel' ], KitAdapters::RULE_BANDS );
    }

    /**
     * The `dark` band is modelled exactly as the button and form proofs model it: the language's `overlay` composited over a
     * mid grey. KitAdaptersTest::PHOTO_GREY is private, so its value is asserted equal here and in RuleChipAudit.
     */
    private const PHOTO_GREY = '#808080';

    public function testThePhotoModelIsTheButtonProofs(): void
    {
        $this->assertSame( ( new \ReflectionClassConstant( KitAdaptersTest::class, 'PHOTO_GREY' ) )->getValue(), self::PHOTO_GREY );
        $this->assertSame( self::PHOTO_GREY, RuleChipAudit::PHOTO_GREY );
    }

    /** A colour token or value resolved by the real pipeline (alpha forms included) as an opaque hex over $ground. @param array<string,mixed> $lang */
    private static function opaqueOn( string $value, array $lang, string $ground ): ?string
    {
        return Colour::opaque( RecipeTokens::resolve( $value, $lang ), $ground );
    }

    /** The opaque colour of band $on in $lang: what a rule on that band is drawn against. @param array<string,mixed> $lang */
    public static function bandOf( string $on, array $lang ): string
    {
        $c = (array) $lang['colors'];
        return match ( $on ) {
            'light'   => (string) Colour::opaque( (string) $c['bg'], '#ffffff' ),
            'surface' => (string) Colour::opaque( (string) $c['surface'], (string) $c['bg'] ),
            'accent'  => (string) Colour::opaque( (string) $c['accent'], (string) $c['bg'] ),
            'panel'   => (string) Colour::opaque( (string) $c['text'], (string) $c['bg'] ),
            default   => (string) Colour::opaque( (string) $c['overlay'], self::PHOTO_GREY ),
        };
    }

    /** Dotted path of the colour, weight and style of the line a carrier draws. @return array{0:string,1:string,2:string} */
    private static function linePaths( string $blockName ): array
    {
        if ( 'divi/divider' === $blockName ) {
            $p = 'divider.advanced.line.desktop.value.';
            return [ $p . 'color', $p . 'weight', $p . 'style' ];
        }
        $p = 'module.decoration.border.desktop.value.styles.top.';
        return [ $p . 'color', $p . 'width', $p . 'style' ];
    }

    /**
     * Contrast problems of one rule patch of $role on band $on in one language ([] = fine), keyed "$where|<check>":
     * - colour: the line colour (alpha composited, as painted) against the band: `rule-strong` at least 3.0 (WCAG 1.4.11
     *   reading), `rule` at least RuleChipAudit::QUIET_RULE_FLOOR; an unresolved colour is 0:1;
     * - width: the drawn width at least 1px, and a `double` at least 3px (thinner paints as solid);
     * - style: one of the border styles a kit may carry;
     * - sides: on a group or column the other three sides are 0px (`all` 0px, no side key beyond `top`).
     *
     * @param array<string,mixed> $patch
     * @param array<string,mixed> $lang
     * @return array<string,string>
     */
    public static function ruleContrastProblems( array $patch, string $blockName, string $role, string $on, array $lang, string $where ): array
    {
        $leaves = self::leaves( $patch );
        [ $colourPath, $widthPath, $stylePath ] = self::linePaths( $blockName );
        $out    = [];
        $band   = self::bandOf( $on, $lang );
        $colour = isset( $leaves[ $colourPath ] ) ? (string) $leaves[ $colourPath ] : null;
        $ink    = null === $colour ? null : self::opaqueOn( $colour, $lang, $band );
        $ratio  = null === $ink ? 0.0 : Colour::contrast( $ink, $band );
        $need   = 'rule-strong' === $role ? 3.0 : RuleChipAudit::QUIET_RULE_FLOOR;
        if ( $ratio < $need ) {
            $out[ "$where|colour" ] = sprintf( '%s: the %s colour (%s) on %s is %.3f:1, needs %.1f', $where, $role, (string) $colour, $band, $ratio, $need );
        }
        $width = isset( $leaves[ $widthPath ] ) ? (string) $leaves[ $widthPath ] : '';
        $style = isset( $leaves[ $stylePath ] ) ? (string) $leaves[ $stylePath ] : '';
        $px    = 1 === preg_match( '/^(\d+)px$/', $width, $m ) ? (int) $m[1] : 0;
        if ( $px < 1 ) {
            $out[ "$where|width" ] = "$where: the drawn width '$width' is under 1px";
        } elseif ( 'double' === $style && $px < 3 ) {
            $out[ "$where|width" ] = "$where: a double of $width paints as solid (needs 3px)";
        }
        if ( ! in_array( $style, StyleKits::BORDER_STYLES, true ) ) {
            $out[ "$where|style" ] = "$where: the style '$style' is not a kit border style";
        }
        if ( 'divi/divider' !== $blockName ) {
            $p = 'module.decoration.border.desktop.value.styles.';
            if ( ( $leaves[ $p . 'all.width' ] ?? null ) !== '0px' ) {
                $out[ "$where|sides" ] = "$where: the other three sides are not 0px";
            }
            foreach ( [ 'bottom', 'left', 'right' ] as $side ) {
                if ( isset( $leaves[ $p . $side . '.width' ] ) && '0px' !== $leaves[ $p . $side . '.width' ] ) {
                    $out[ "$where|sides" ] = "$where: the $side side is drawn";
                }
            }
        }
        return $out;
    }

    public function testNoInvisibleRuleInAnyKitLanguageBandRole(): void
    {
        $problems = [];
        $checked  = 0;
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( DesignLanguages::ids() as $langId ) {
                $lang = DesignLanguages::get( $langId );
                $this->assertIsArray( $lang );
                foreach ( self::BANDS as $on ) {
                    foreach ( self::ROLES as $role ) {
                        foreach ( self::CARRIERS as $block ) {
                            $problems = array_merge( $problems, self::ruleContrastProblems( $this->patch( $block, $role, $kit, $on ), $block, $role, $on, $lang, "$id|$langId|$on|$role|$block" ) );
                        }
                        ++$checked; // one cell = kit x language x band x role, judging the divider, group and column patch
                    }
                }
            }
        }
        $this->assertSame( 40 * 10 * 5 * 2, $checked );
        $this->assertSame( 4000, $checked );
        $this->assertSame( [], array_values( $problems ), "invisible rules (fix KitAdapters::RULE_COLOUR or the width table, never the floor; no exceptions):\n" . implode( "\n", array_slice( $problems, 0, 60 ) ) . "\n(" . count( $problems ) . ' problems)' );
    }

    public function testTheRuleContrastCheckCatchesWhatItShould(): void
    {
        $lang = DesignLanguages::get( 'organic-warm' );
        $this->assertIsArray( $lang );
        $kit   = $this->shippedKits()['steady-solid'];
        $judge = static fn ( array $p, string $block, string $role, string $on ): array => self::ruleContrastProblems( $p, $block, $role, $on, $lang, 'x' );
        $c     = 'module.decoration.border.desktop.value.styles.';

        foreach ( self::CARRIERS as $block ) {
            foreach ( self::ROLES as $role ) {
                foreach ( self::BANDS as $on ) {
                    $this->assertSame( [], $judge( $this->patch( $block, $role, $kit, $on ), $block, $role, $on ), "steady-solid $role $block on $on is clean in organic-warm" );
                }
            }
        }

        // A quiet rule in the band's own colour is invisible (1:1 under the 1.1 floor).
        $bad = $this->patch( 'divi/divider', 'rule', $kit, 'light' );
        self::setLeaf( $bad, 'divider.advanced.line.desktop.value.color', '{{aied:color:bg}}' );
        $this->assertArrayHasKey( 'x|colour', $judge( $bad, 'divi/divider', 'rule', 'light' ), 'a quiet rule in bg on a light band' );
        // A strong rule in `border` does not reach 3:1.
        $bad = $this->patch( 'divi/divider', 'rule-strong', $kit, 'light' );
        self::setLeaf( $bad, 'divider.advanced.line.desktop.value.color', '{{aied:color:border}}' );
        $this->assertArrayHasKey( 'x|colour', $judge( $bad, 'divi/divider', 'rule-strong', 'light' ), 'a strong rule in border' );
        // A strong rule at 5% alpha of text is nearly invisible; and a token the pipeline cannot resolve is 0:1.
        $bad = $this->patch( 'divi/group', 'rule-strong', $kit, 'light' );
        self::setLeaf( $bad, $c . 'top.color', '{{aied:color:text:05}}' );
        $this->assertArrayHasKey( 'x|colour', $judge( $bad, 'divi/group', 'rule-strong', 'light' ), 'text at 5% on light' );
        self::setLeaf( $bad, $c . 'top.color', '{{aied:color:text:5}}' );
        $this->assertArrayHasKey( 'x|colour', $judge( $bad, 'divi/group', 'rule-strong', 'light' ), 'an unresolved colour' );
        // A top rule with no top side: the colour and width are missing.
        $bad = $this->patch( 'divi/column', 'rule', $kit, 'surface' );
        self::unsetLeaf( $bad, $c . 'top' );
        $found = $judge( $bad, 'divi/column', 'rule', 'surface' );
        $this->assertArrayHasKey( 'x|colour', $found );
        $this->assertArrayHasKey( 'x|width', $found );
        // A divider with weight 0px, and a double thinner than 3px.
        $bad = $this->patch( 'divi/divider', 'rule', $kit, 'light' );
        self::setLeaf( $bad, 'divider.advanced.line.desktop.value.weight', '0px' );
        $this->assertArrayHasKey( 'x|width', $judge( $bad, 'divi/divider', 'rule', 'light' ) );
        self::setLeaf( $bad, 'divider.advanced.line.desktop.value.weight', '2px' );
        self::setLeaf( $bad, 'divider.advanced.line.desktop.value.style', 'double' );
        $this->assertArrayHasKey( 'x|width', $judge( $bad, 'divi/divider', 'rule', 'light' ), 'a 2px double paints as solid' );
        // A side other than the top drawn, and `all` not 0px.
        $bad = $this->patch( 'divi/group', 'rule', $kit, 'light' );
        self::setLeaf( $bad, $c . 'bottom.width', '2px' );
        $this->assertArrayHasKey( 'x|sides', $judge( $bad, 'divi/group', 'rule', 'light' ) );
        $bad = $this->patch( 'divi/group', 'rule', $kit, 'light' );
        self::setLeaf( $bad, $c . 'all.width', '1px' );
        $this->assertArrayHasKey( 'x|sides', $judge( $bad, 'divi/group', 'rule', 'light' ) );
        // The dark and panel bands are judged against their own ground: a text rule on a text panel vanishes.
        $bad = $this->patch( 'divi/divider', 'rule-strong', $kit, 'panel' );
        self::setLeaf( $bad, 'divider.advanced.line.desktop.value.color', '{{aied:color:text}}' );
        $this->assertArrayHasKey( 'x|colour', $judge( $bad, 'divi/divider', 'rule-strong', 'panel' ), 'text on a text panel' );
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
