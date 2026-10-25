<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\StyleGuide;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Block;
use Divi5Validator\BlockParser;
use Divi5Validator\Tests\Support\DeadOnModule;
use Divi5Validator\Tests\Support\RuleChipAudit;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/Support/Colour.php';
require_once __DIR__ . '/Support/DeadOnModule.php';
require_once __DIR__ . '/Support/RuleChipAudit.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';

/**
 * The facts the wording-gaps guide text will state, pinned BEFORE the text exists (plan 2026-10-25, Task 1; spec gaps 2, 3, 6,
 * 8, 9, 11a, 11b, 12 and the shapes of 1a and 1b). Each test reads the shipped recipes, the real Divi exports, the adapters or
 * the render gate's own markup, so a sentence in the guide can be traced to a line here. Pure: no site, no guide change.
 *
 * Three facts differ from the plan's prose and are pinned as measured: the recipes hold 10 gradient stop lists (3 with number
 * positions, 7 with string positions; the plan said "numbers (4)"); the Divi export that carries the escaped glyph code
 * `\u0026#xf0a9;` is `fixtures/valid/page-44-layout-2.json`, on a button icon (page-17-newpage.json carries `\u0026#x21;`); and the
 * recipes already use BOTH glyph spellings (75 plain, 10 escaped), not only the plain one.
 */
class WordingGapsEvidenceTest extends TestCase
{
    /** @return list<array{name:string,markup:string}> */
    private static function library(): array
    {
        $rows = json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        self::assertIsArray( $rows );

        return $rows;
    }

    private static function recipe( string $name ): string
    {
        foreach ( self::library() as $row ) {
            if ( $name === $row['name'] ) {
                return (string) $row['markup'];
            }
        }
        self::fail( "no recipe $name" );
    }

    /**
     * Every block of a markup in document order, numbered per block name as the audits number them ("divi/column#2" = the
     * third column), with its parent's position in the list (-1 = the root). The placeholder root is not listed.
     *
     * @return list<array{name:string,index:int,attrs:array<string,mixed>,parent:int,children:list<int>}>
     */
    private static function flat( string $markup ): array
    {
        $root = ( new BlockParser() )->parse( '<!-- wp:divi/placeholder -->' . $markup . '<!-- /wp:divi/placeholder -->' )->root();
        self::assertNotNull( $root );
        $list = [];
        $seen = [];
        $walk = static function ( Block $b, int $parent ) use ( &$walk, &$list, &$seen ): void {
            $name = $b->name();
            $at   = -1;
            if ( 'divi/placeholder' !== $name ) {
                $seen[ $name ] = ( $seen[ $name ] ?? -1 ) + 1;
                $list[]        = [ 'name' => $name, 'index' => $seen[ $name ], 'attrs' => $b->attrs(), 'parent' => $parent, 'children' => [] ];
                $at            = count( $list ) - 1;
                if ( $parent >= 0 ) {
                    $list[ $parent ]['children'][] = $at;
                }
            }
            foreach ( $b->children() as $child ) {
                $walk( $child, $at );
            }
        };
        $walk( $root, -1 );

        return $list;
    }

    /** The position in flat() of the block "$name#$index". */
    private static function at( array $flat, string $name, int $index ): int
    {
        foreach ( $flat as $i => $b ) {
            if ( $b['name'] === $name && $b['index'] === $index ) {
                return $i;
            }
        }
        self::fail( "no block $name#$index" );
    }

    /** @return list<int> positions of every descendant of $at, nearest first (document order). */
    private static function below( array $flat, int $at ): array
    {
        $out = [];
        foreach ( $flat[ $at ]['children'] as $child ) {
            $out[] = $child;
            foreach ( self::below( $flat, $child ) as $deeper ) {
                $out[] = $deeper;
            }
        }

        return $out;
    }

    /** @return array{0:string,1:string,2:?string}|null the card/rule/chip/button marker of a block as [kit, on, ground] */
    private static function marker( array $attrs ): ?array
    {
        $m = $attrs['aied'] ?? null;
        if ( ! is_array( $m ) || ! isset( $m['kit'] ) ) {
            return null;
        }

        return [ (string) $m['kit'], (string) ( $m['on'] ?? 'light' ), isset( $m['ground'] ) ? (string) $m['ground'] : null ];
    }

    /** @return list<string> the dotted leaves of a nested array (an empty array is a leaf) */
    private static function leaves( array $node, string $prefix = '' ): array
    {
        $out = [];
        foreach ( $node as $k => $v ) {
            $p = '' === $prefix ? (string) $k : $prefix . '.' . $k;
            if ( is_array( $v ) && [] !== $v ) {
                $out = array_merge( $out, self::leaves( $v, $p ) );
            } else {
                $out[] = $p;
            }
        }

        return $out;
    }

    /** @return mixed the value at a dotted path, or null */
    private static function dig( array $node, string $path ): mixed
    {
        foreach ( explode( '.', $path ) as $key ) {
            if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
                return null;
            }
            $node = $node[ $key ];
        }

        return $node;
    }

    /** @return list<string> every `unicode` value below $node (an icon module, a button icon ...) */
    private static function unicodes( array $node ): array
    {
        $out = [];
        foreach ( $node as $k => $v ) {
            if ( 'unicode' === $k && is_string( $v ) ) {
                $out[] = $v;
            }
            if ( is_array( $v ) ) {
                $out = array_merge( $out, self::unicodes( $v ) );
            }
        }

        return $out;
    }

    // Gap 9: the glyph code in block JSON ---------------------------------------------------------------------------

    public function testTheRecipesWriteThePlainGlyphAndTheExportTheEscapedOne(): void
    {
        // The recipes use BOTH spellings and both render (verify-recipes, 426 PASS): the plain `&#x...;` (75 glyphs in 13 recipes) and the
        // JSON escape `\u0026#x...;` that Divi's builder writes (10 glyphs in 3 recipes: blurb-grid, icon-features, slider). The plan
        // expected only the plain form; the measured library has both. Parsed, every glyph is the same plain string.
        $forms = [ 'plain' => 0, 'escaped' => 0 ];
        foreach ( self::library() as $row ) {
            $this->assertSame( preg_match_all( '/"unicode":"([^"]*)"/', $row['markup'], $m ), count( $m[1] ) );
            foreach ( $m[1] as $code ) {
                $this->assertMatchesRegularExpression( '/^(?:&|\\\\u0026)#x[0-9a-f]+;$/', $code, "{$row['name']}: a glyph code is `&#x...;` or its JSON escape" );
                ++$forms[ str_starts_with( $code, '&' ) ? 'plain' : 'escaped' ];
            }
            foreach ( self::flat( $row['markup'] ) as $b ) {
                if ( 'divi/icon' === $b['name'] ) {
                    $this->assertMatchesRegularExpression( '/^&#x[0-9a-f]+;$/', (string) self::dig( $b['attrs'], 'icon.innerContent.desktop.value.unicode' ), "{$row['name']}: parsed, the plain string" );
                }
            }
        }
        $this->assertSame( [ 'plain' => 75, 'escaped' => 10 ], $forms, 'glyph spellings in the recipes (measured at 895610e)' );

        // Divi's own builder export writes the ampersand as the JSON escape `\u0026` ... and both decode to the same string.
        $export = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/valid/page-44-layout-2.json' ), true );
        $this->assertIsArray( $export );
        $content = (string) $export['post_content'];
        $this->assertStringContainsString( '"unicode":"\u0026#xf0a9;"', $content, 'the builder writes the escaped ampersand' );
        $this->assertStringNotContainsString( '"unicode":"&#xf0a9;"', $content );
        $glyphs = [];
        foreach ( self::flat( $content ) as $b ) {
            $glyphs = array_merge( $glyphs, self::unicodes( $b['attrs'] ) );
        }
        $this->assertContains( '&#xf0a9;', $glyphs, 'the escaped form parses to the plain string' );
        $this->assertSame( '&#xf0a9;', json_decode( '"\u0026#xf0a9;"' ) );
        $this->assertSame( json_decode( '"&#xf0a9;"' ), json_decode( '"\u0026#xf0a9;"' ), 'both spellings are the same JSON string' );
    }

    // Gap 8: the divider -----------------------------------------------------------------------------------------------

    public function testTheDividerPatchWritesNoShowAndNoSpacing(): void
    {
        $wanted = [
            'divider.advanced.line.desktop.value.color',
            'divider.advanced.line.desktop.value.style',
            'divider.advanced.line.desktop.value.weight',
        ];
        $checked = 0;
        foreach ( StyleKits::ids() as $id ) {
            $kit = StyleKits::get( $id );
            $this->assertIsArray( $kit );
            foreach ( [ 'rule', 'rule-strong' ] as $role ) {
                foreach ( KitAdapters::RULE_BANDS as $on ) {
                    $patch = KitAdapters::patch( 'divi/divider', $role, $on, $kit );
                    $this->assertIsArray( $patch, "$id $role on $on" );
                    $leaves = self::leaves( $patch );
                    sort( $leaves );
                    $this->assertSame( $wanted, $leaves, "$id $role on $on writes the three line values and nothing else (no show, no spacing)" );
                    ++$checked;
                }
            }
        }
        $this->assertSame( 400, $checked, '40 kits x 2 roles x 5 bands' );

        // The accent mark is a divider too and writes the same three leaves on its two bands.
        foreach ( StyleKits::ids() as $id ) {
            foreach ( KitAdapters::ACCENT_RULE_BANDS as $on ) {
                $patch = KitAdapters::patch( 'divi/divider', 'rule-accent', $on, (array) StyleKits::get( $id ) );
                $this->assertIsArray( $patch );
                $leaves = self::leaves( $patch );
                sort( $leaves );
                $this->assertSame( $wanted, $leaves, "$id rule-accent on $on" );
            }
        }

        // The render gate's divider (264 of 264 cells PASS on Divi 5.15.1) is the marker alone: no `show` key, no spacing.
        $gate = (string) file_get_contents( __DIR__ . '/../scripts/lib/kit-checks.cjs' );
        $this->assertStringContainsString( "blockOf('divider', { builderVersion: '5.8.0', aied: marker }, null)", $gate, 'the gate renders a divider with nothing but the marker' );
        $this->assertStringNotContainsString( 'show:', substr( $gate, (int) strpos( $gate, 'const RULE_SLOTS' ), 3000 ), 'the gate writes no show key on its rule slots' );
    }

    // Gap 11a: the testimonial ------------------------------------------------------------------------------------------

    public function testTheTestimonialRecipeInks(): void
    {
        $flat = self::flat( self::recipe( 'testimonial' ) );
        $at   = self::at( $flat, 'divi/testimonial', 0 );
        $attr = $flat[ $at ]['attrs'];

        $ink = [
            'author.decoration.font.font.desktop.value.color'                         => '{{aied:color:text}}',
            'jobTitle.decoration.font.font.desktop.value.color'                       => '{{aied:color:muted}}',
            'company.decoration.font.font.desktop.value.color'                        => '{{aied:color:muted}}',
            'content.decoration.bodyFont.body.font.desktop.value.color'               => '{{aied:color:text}}',
        ];
        foreach ( $ink as $path => $value ) {
            $this->assertSame( $value, self::dig( $attr, $path ), $path );
        }

        $registry = ShapeRegistry::load();
        $paths    = ShapePaths::fromBlockAttrs( $attr );
        foreach ( array_keys( $ink ) as $path ) {
            $normal = implode( '.', array_map( static fn ( string $s ): string => 'desktop' === $s ? '{bp}' : $s, explode( '.', $path ) ) );
            $this->assertContains( $normal, $paths, "$normal is among the recipe's paths" );
            $this->assertTrue( $registry->coversLeaf( $normal ), "$normal is a registered leaf" );
        }
        $this->assertSame( [ 'kit' => 'panel', 'on' => 'light' ], $attr['aied'], 'the testimonial is a panel on the light band' );
    }


    // Gap 11b: gradient stops ----------------------------------------------------------------------------------------------

    /** @return list<array<int,mixed>> every `stops` value found below $node */
    private static function stopLists( array $node ): array
    {
        $out = [];
        foreach ( $node as $k => $v ) {
            if ( 'stops' === $k ) {
                $out[] = $v;
            }
            if ( is_array( $v ) ) {
                $out = array_merge( $out, self::stopLists( $v ) );
            }
        }

        return $out;
    }

    /**
     * Assert every list in $lists is a JSON list of {position, color} objects and say how many lists use number positions
     * and how many string positions.
     *
     * @param list<mixed> $lists
     * @return array{number:int,string:int}
     */
    private function classifyStops( array $lists, string $where ): array
    {
        $kinds = [ 'number' => 0, 'string' => 0 ];
        foreach ( $lists as $stops ) {
            $this->assertIsArray( $stops, $where );
            $this->assertTrue( array_is_list( $stops ), "$where: stops is a JSON list, not an object keyed 0, 1 ..." );
            $this->assertGreaterThanOrEqual( 2, count( $stops ), $where );
            $listKinds = [];
            foreach ( $stops as $stop ) {
                $this->assertIsArray( $stop, $where );
                $keys = array_keys( $stop );
                sort( $keys );
                $this->assertSame( [ 'color', 'position' ], $keys, "$where: a stop is exactly {position, color}" );
                $this->assertIsString( $stop['color'], $where );
                $listKinds[ is_string( $stop['position'] ) ? 'string' : 'number' ] = true;
            }
            $this->assertCount( 1, $listKinds, "$where: one list does not mix number and string positions" );
            ++$kinds[ array_key_first( $listKinds ) ];
        }

        return $kinds;
    }

    public function testGradientStopsAreAListInExportsAndRecipes(): void
    {
        // The recipes: 10 lists, 3 with number positions and 7 with string positions; all validate and render.
        $recipeLists = [];
        foreach ( self::library() as $row ) {
            foreach ( self::flat( $row['markup'] ) as $b ) {
                $recipeLists = array_merge( $recipeLists, self::stopLists( $b['attrs'] ) );
            }
        }
        $this->assertSame( [ 'number' => 3, 'string' => 7 ], $this->classifyStops( $recipeLists, 'recipes' ) );

        // The builder's exports: a LIST, with NUMBER positions.
        $exportLists = [];
        $files       = 0;
        foreach ( glob( __DIR__ . '/../fixtures/valid/*.json' ) ?: [] as $file ) {
            $export = json_decode( (string) file_get_contents( $file ), true );
            if ( ! is_array( $export ) || ! is_string( $export['post_content'] ?? null ) ) {
                continue;
            }
            foreach ( self::flat( $export['post_content'] ) as $b ) {
                $found = self::stopLists( $b['attrs'] );
                if ( [] !== $found ) {
                    $exportLists = array_merge( $exportLists, $found );
                    $files++;
                }
            }
        }
        $kinds = $this->classifyStops( $exportLists, 'exports' );
        $this->assertSame( 0, $kinds['string'], 'the builder never writes a string position' );
                $this->assertSame( 11, $kinds['number'], 'eleven lists in five real exports (page-23, -44, -45, -85, -86)' );
        $this->assertGreaterThan( 0, $files );
    }

    // Gap 12: the phone-stacking shape ------------------------------------------------------------------------------------------

    public function testNoRecipeRowUsesThePhoneFlexDirectionShape(): void
    {
        $rows = 0;
        $wrap = 0;
        foreach ( self::library() as $row ) {
            foreach ( self::flat( $row['markup'] ) as $b ) {
                if ( 'divi/row' !== $b['name'] ) {
                    continue;
                }
                ++$rows;
                $layout = $b['attrs']['module']['decoration']['layout'] ?? [];
                foreach ( [ 'phone', 'phoneWide' ] as $bp ) {
                    $this->assertArrayNotHasKey( 'flexDirection', $layout[ $bp ]['value'] ?? [], "{$row['name']}: a row stacks with flexWrap, never with flexDirection at $bp" );
                }
                if ( isset( $layout['phone']['value']['flexWrap'] ) ) {
                    $this->assertSame( 'wrap', $layout['phone']['value']['flexWrap'] );
                    ++$wrap;
                }
            }
        }
        $this->assertSame( 101, $rows, 'rows in the library (measured at 895610e)' );
        $this->assertGreaterThanOrEqual( 30, $wrap );
        $this->assertSame( 38, $wrap, 'rows that wrap at phone (measured: 38 rows in 30 recipes)' );

        // A flex GROUP stacks with its own phone flexDirection: used by the library (portfolio-featured-grid's header group).
        $groupStacks = 0;
        foreach ( self::library() as $row ) {
            foreach ( self::flat( $row['markup'] ) as $b ) {
                if ( 'divi/group' === $b['name'] && 'column' === ( $b['attrs']['module']['decoration']['layout']['phone']['value']['flexDirection'] ?? null ) ) {
                    ++$groupStacks;
                }
            }
        }
        $this->assertGreaterThan( 0, $groupStacks );
    }

    // Gaps 2 and 3: plan columns and the featured plan ---------------------------------------------------------------------------

    public function testTheFeaturedPlan(): void
    {
        $flat = self::flat( self::recipe( 'pricing-three-tier' ) );
        foreach ( $flat as $b ) {
            if ( 'divi/column' === $b['name'] ) {
                $m = self::marker( $b['attrs'] );
                $this->assertTrue( null === $m || ! in_array( $m[0], [ 'rule', 'rule-strong' ], true ), 'no column of the recipe carries a rule marker' );
            }
        }

        // The markers inside one column: [ kit, on ] of its rule group and its button.
        $inside = function ( int $column ) use ( $flat ): array {
            $found = [];
            foreach ( self::below( $flat, self::at( $flat, 'divi/column', $column ) ) as $i ) {
                $m = self::marker( $flat[ $i ]['attrs'] );
                if ( null !== $m ) {
                    $found[] = [ $flat[ $i ]['name'], $m[0], $m[1] ];
                }
            }

            return $found;
        };

        $featured = self::marker( $flat[ self::at( $flat, 'divi/column', 2 ) ]['attrs'] );
        $this->assertSame( [ 'card', 'light', 'accent' ], $featured, 'column #2 is an accent-ground card on the light band' );
        $this->assertSame( [ [ 'divi/group', 'rule', 'accent' ], [ 'divi/button', 'control', 'accent' ] ], $inside( 2 ), 'its features rule sits on accent and its button is a primary on accent' );

        foreach ( [ 1, 3 ] as $column ) {
            $this->assertSame( [ 'card', 'light', null ], self::marker( $flat[ self::at( $flat, 'divi/column', $column ) ]['attrs'] ), "column #$column is a plain card on light" );
            $this->assertSame( [ [ 'divi/group', 'rule', 'surface' ], [ 'divi/button', 'control-secondary', 'light' ] ], $inside( $column ), "column #$column: a rule on surface and a secondary button" );
        }
    }

    // Gaps 1a and 1b: the tag and the icon chip as the recipes write them ------------------------------------------------------------

    public function testTheTagAndIconChipShapes(): void
    {
        // Tags: portfolio-featured-grid. A tag is a group with 4px/10px padding and ONE text child, in a flex-row wrap group.
        $flat = self::flat( self::recipe( 'portfolio-featured-grid' ) );
        $tags = array_keys( array_filter( $flat, static fn ( array $b ): bool => 'divi/group' === $b['name'] && [ 'chip', 'light', null ] === self::marker( $b['attrs'] ) ) );
        $this->assertCount( 5, $tags );
        foreach ( $tags as $i ) {
            $b = $flat[ $i ];
            $this->assertSame(
                [ 'top' => '4px', 'bottom' => '4px', 'left' => '10px', 'right' => '10px' ],
                self::dig( $b['attrs'], 'module.decoration.spacing.desktop.value.padding' ),
                'a tag pads 4px 10px'
            );
            $this->assertCount( 1, $b['children'], 'a tag holds one child' );
            $this->assertSame( 'divi/text', $flat[ $b['children'][0] ]['name'], 'the child is the label' );
            $parent = $flat[ $b['parent'] ];
            $this->assertSame( 'divi/group', $parent['name'] );
            $layout = self::dig( $parent['attrs'], 'module.decoration.layout.desktop.value' );
            $this->assertIsArray( $layout );
            $this->assertSame( 'row', $layout['flexDirection'], 'the tag sits in a flex-row group' );
            $this->assertSame( 'wrap', $layout['flexWrap'], 'that wraps' );
            $this->assertArrayNotHasKey( 'sizing', $b['attrs']['module']['decoration'], 'a tag sets no width' );
        }

        // Icon chips: align left, padding on the module, no maxWidth (P1: the box hugs glyph plus padding).
        $counts = [ 'icon-values' => 3, 'features-carousel-strip' => 6 ];
        foreach ( $counts as $name => $expected ) {
            $chips = 0;
            foreach ( self::flat( self::recipe( $name ) ) as $b ) {
                if ( 'divi/icon' !== $b['name'] || null === self::marker( $b['attrs'] ) ) {
                    continue;
                }
                ++$chips;
                $this->assertSame( 'chip', self::marker( $b['attrs'] )[0], $name );
                $this->assertSame( 'left', self::dig( $b['attrs'], 'icon.advanced.align.desktop.value' ), "$name: the chip is aligned left" );
                $this->assertIsArray( self::dig( $b['attrs'], 'module.decoration.spacing.desktop.value.padding' ), "$name: the padding is on module.decoration.spacing" );
                $this->assertNull( self::dig( $b['attrs'], 'module.decoration.sizing' ), "$name: no sizing, so no maxWidth" );
                $this->assertIsString( self::dig( $b['attrs'], 'icon.advanced.size.desktop.value' ), "$name: the glyph size sets the chip size" );
            }
            $this->assertSame( $expected, $chips, "$name: icon chips" );
        }

        // The probe that decided it: the box already hugs, so a maxWidth is not taught (Ruling 9).
        $vocab = (string) file_get_contents( __DIR__ . '/../fixtures/shapes/kit-patterns-vocabulary.md' );
        $this->assertStringContainsString( '| icon-hugs-left | PASS |', $vocab );
        $this->assertStringContainsString( '| icon-hugs-center | PASS |', $vocab );
        $this->assertStringContainsString( 'NOT taught', $vocab );
    }

    // Gap 6: quiet and strong rules ---------------------------------------------------------------------------------------------

    public function testQuietAndStrongShareAColourInTwoLanguages(): void
    {
        $same  = [];
        $quiet = [];
        foreach ( DesignLanguages::ids() as $id ) {
            $language = DesignLanguages::get( $id );
            $this->assertIsArray( $language );
            if ( $language['colors']['border'] === $language['colors']['text'] ) {
                $same[] = $id;
                continue;
            }
            foreach ( [ 'light', 'surface' ] as $band ) {
                $quiet[ "$id|$band" ] = RuleChipAudit::ratio( 'border', $band, $language );
            }
        }
        sort( $same );
        $this->assertSame( [ 'bold-vibrant', 'brutalist-flat' ], $same, 'border equals text exactly in these two languages: quiet and strong differ in width only' );
        $this->assertCount( 16, $quiet, 'the other eight languages on light and surface' );
        $this->assertSame( 1.18, round( min( $quiet ), 2 ), 'the faintest quiet rule (soft-saas on light)' );
        $this->assertSame( 'soft-saas|light', array_search( min( $quiet ), $quiet, true ) );
        $this->assertSame( 1.58, round( max( $quiet ), 2 ), 'the strongest quiet rule (glass-dark on light)' );
        $this->assertSame( 'glass-dark|light', array_search( max( $quiet ), $quiet, true ) );
    }

    // Gap 14: the contact-form submit ---------------------------------------------------------------------------------------------

    public function testTheFormSubmitPathIsLiveWhereTheButtonModulePathIsDead(): void
    {
        // Both statements are true; they are about two different modules. divi/button: the element's own groups are dead.
        $dead    = DeadOnModule::load();
        $entries = array_values( array_filter( $dead->all(), static fn ( array $e ): bool => 'divi/button' === $e['module'] ) );
        $paths   = array_column( $entries, 'prefix' );
        $this->assertContains( 'button.decoration.spacing', $paths );
        $this->assertContains( 'button.decoration.boxShadow', $paths );
        $this->assertCount( 0, array_filter( $dead->all(), static fn ( array $e ): bool => 'divi/contact-form' === $e['module'] ), 'nothing is dead on the contact form' );

        // divi/contact-form: the submit is a `button` element and its decoration paths are the live ones (FORM_PROVEN, all true).
        foreach ( [ 'button-padding', 'button-shadow', 'button-lift' ] as $decision ) {
            $this->assertTrue( KitAdapters::FORM_PROVEN[ $decision ], $decision );
        }
        $written = [];
        foreach ( StyleKits::ids() as $id ) {
            foreach ( [ 'light', 'surface', 'accent', 'dark' ] as $on ) {
                $patch = KitAdapters::patch( 'divi/contact-form', 'form', $on, (array) StyleKits::get( $id ) );
                $this->assertIsArray( $patch );
                $this->assertArrayNotHasKey( 'module', $patch, "$id on $on: a form marker never writes module.decoration" );
                foreach ( self::leaves( $patch ) as $leaf ) {
                    if ( str_starts_with( $leaf, 'button.decoration.spacing' ) || str_starts_with( $leaf, 'button.decoration.boxShadow' ) || str_starts_with( $leaf, 'button.decoration.transform' ) ) {
                        $written[ preg_replace( '/^(button\.decoration\.[A-Za-z]+).*/', '$1', $leaf ) ] = true;
                    }
                }
            }
        }
        ksort( $written );
        $this->assertSame( [ 'button.decoration.boxShadow', 'button.decoration.spacing', 'button.decoration.transform' ], array_keys( $written ), 'the form adapter writes the submit padding, shadow and lift at button.decoration.*' );

        // The two served texts: `controls` says the paths are dead on a `divi/button`; kit-rules says they are live on a contact
        // form. Task 4 added the missing clause to `controls` (StyleGuide::controlsFormClause()), so the two no longer contradict.
        $controls = StyleGuide::markdown( 'controls' );
        $this->assertStringContainsString( 'changed nothing (rendered: dead); write them on the module.', $controls );
        $this->assertStringContainsString( '`button.decoration.spacing`, `button.decoration.boxShadow`', $controls );
        $this->assertStringContainsString( 'contact form', substr( $controls, (int) strpos( $controls, 'changed nothing (rendered: dead); write them on the module.' ), 300 ), 'since Task 4 the controls topic excepts the contact-form submit' );
        $this->assertStringContainsString( 'at `button.decoration.*`, not `module.decoration` (live on a contact form, the opposite of a `divi/button`)', StyleGuide::markdown( 'kit-rules' ) );
    }
}
