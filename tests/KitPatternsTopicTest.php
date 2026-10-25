<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleGuide;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\RuleChipAudit;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'StyleKits', 'KitAdapters', 'KitResolver', 'RecipeTokens' ] as $kitPatternsTestClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $kitPatternsTestClass . '.php';
}
require_once __DIR__ . '/Support/Colour.php';
require_once __DIR__ . '/Support/RuleChipAudit.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';
require_once __DIR__ . '/Support/Dogfood/Page.php';
require_once __DIR__ . '/WordingGapsEvidenceTest.php';

/**
 * Wording gaps Task 4 (plan docs/superpowers/plans/2026-10-25-wording-gaps.md, Step 1): the generated `kit-patterns` topic,
 * the generated Kit line of every design-language topic, the reworded `kit-rules` sentences, the `controls` clause and the
 * default-guide swap. Every number the topic prints is recomputed here from the adapters (never typed), every path is in the
 * shape registry, every named recipe shows the pattern it is named for, and the layout claims follow probe P1.
 */
class KitPatternsTopicTest extends TestCase
{
    private static function topic(): string
    {
        return StyleGuide::markdown( 'kit-patterns' );
    }

    /** "fill" / "label" / "frame" colour role of a button patch, rest or hover, in the topic's words ("transparent" for none). */
    private static function role( ?string $value ): string
    {
        if ( null === $value ) {
            return '?';
        }

        return 1 === preg_match( '/^\{\{aied:color:([a-z-]+)\}\}$/', $value, $m ) ? $m[1] : ( KitAdapters::TRANSPARENT === $value ? 'transparent' : '?' );
    }

    /** @return array{fill:array{0:string,1:string},label:array{0:string,1:string},frame:array{0:string,1:string}} */
    private static function colours( array $patch ): array
    {
        $d    = $patch['button']['decoration'];
        $rest = $d['border']['desktop']['value']['styles']['bottom']['color'] ?? $d['border']['desktop']['value']['styles']['all']['color'];

        return [
            'fill'  => [ self::role( $d['background']['desktop']['value']['color'] ), self::role( $d['background']['desktop']['hover']['color'] ?? null ) ],
            'label' => [ self::role( $d['font']['font']['desktop']['value']['color'] ), self::role( $d['font']['font']['desktop']['hover']['color'] ?? null ) ],
            'frame' => [ self::role( $rest ), self::role( $d['border']['desktop']['hover']['styles']['all']['color'] ?? $rest ) ],
        ];
    }

    public function testTheTopicIsListedServedAndWithinBudget(): void
    {
        $topics = StyleGuide::topics();
        $at     = array_search( 'kit-rules', $topics, true );
        $this->assertIsInt( $at );
        $this->assertSame( 'kit-patterns', $topics[ $at + 1 ], 'served right after kit-rules (Ruling 1)' );

        $text = self::topic();
        $this->assertStringStartsWith( '# Kit patterns', $text );
        $this->assertLessThanOrEqual( 6000, strlen( $text ), 'Ruling 1: at most 6,000 B' );
        $this->assertStringNotContainsString( 'aied', $text, 'never the marker' );
        $this->assertStringNotContainsString( '{{aied:', $text );
        $this->assertStringNotContainsString( 'http', $text );
        $this->assertStringNotContainsStringIgnoringCase( 'video', $text );
        $this->assertStringNotContainsString( '`kit-patterns`', $text, 'its own name is never backticked' );

        // The same text on MCP and REST.
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'get_style_guide', 'arguments' => [ 'topic' => 'kit-patterns' ] ] ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();
        $this->assertSame( $text, (string) $data['result']['content'][0]['text'] );
        $rest = ( new RestController() )->style_guide( new \WP_REST_Request( [], [ 'topic' => 'kit-patterns' ] ) );
        $this->assertSame( 200, $rest->get_status() );
        $this->assertSame( $text, (string) $rest->get_data()['guide'] );

        // Named by the default guide's topic line and the parameter description (258 -> 272 characters, Ruling 2).
        $this->assertStringContainsString( ', kit-rules, kit-patterns, style-kit:<id>.', StyleGuide::markdown() );
        $this->assertStringContainsString( 'kit-patterns', StyleGuide::topicParameterDescription() );
        $this->assertSame( 272, strlen( StyleGuide::topicParameterDescription() ) );
        $this->assertStringContainsString( 'topic=kit-patterns', StyleGuide::markdown( 'kit-rules' ), 'kit-rules points to it' );
    }

    public function testEveryPathIsRegistered(): void
    {
        $registry = ShapeRegistry::load();
        $paths    = ShapePaths::fromGuideText( self::topic() );
        $this->assertGreaterThanOrEqual( 8, count( $paths ) );
        foreach ( $paths as $path ) {
            $this->assertTrue( $registry->covers( $path ), "$path is in the shape registry" );
        }
        // The new Mobile section of the default guide too (full paths only: no relative path is taught there).
        $guide  = StyleGuide::markdown();
        $mobile = substr( $guide, (int) strpos( $guide, '## Mobile: rows and flex groups' ) );
        $mobile = substr( $mobile, 0, (int) strpos( $mobile, "\n## ", 4 ) );
        $this->assertStringContainsString( '`module.decoration.layout.phone.value.flexDirection`', $mobile );
        foreach ( ShapePaths::fromGuideText( $mobile ) as $path ) {
            $this->assertTrue( $registry->covers( $path ), "$path is in the shape registry" );
        }
    }

    public function testEveryNumberIsTheAdaptersOwn(): void
    {
        $text = self::topic();

        // The tag frame: the kit's border width, 1px for a 0px kit, 3px for a double under 3px (KitAdapters::chipPatch()).
        $this->assertStringContainsString( "the kit's border width and style (1px for a 0px kit, 3px for a double under 3px)", $text );
        foreach ( StyleKits::ids() as $id ) {
            $kit    = (array) StyleKits::get( $id );
            $w      = (int) $kit['border']['width'];
            $expect = 0 === $w ? 1 : ( 'double' === $kit['border']['style'] && $w < 3 ? 3 : $w );
            $patch  = KitAdapters::patch( 'divi/group', 'chip', 'light', $kit );
            $this->assertSame( $expect . 'px', $patch['module']['decoration']['border']['desktop']['value']['styles']['all']['width'], "$id: the printed tag rule" );
        }
        foreach ( [ [ '0px', 'solid', '1px' ], [ '1px', 'double', '3px' ], [ '2px', 'double', '3px' ], [ '4px', 'double', '4px' ], [ '3px', 'dashed', '3px' ] ] as [ $w, $style, $out ] ) {
            $kit           = (array) StyleKits::get( 'quiet-solid' );
            $kit['border'] = array_merge( $kit['border'], [ 'width' => $w, 'style' => $style ] );
            $this->assertSame( $out, KitAdapters::chipPatch( 'divi/group', 'light', $kit )['module']['decoration']['border']['desktop']['value']['styles']['all']['width'], "$w $style" );
        }

        // The quiet and strong widths of the two languages whose hairline is the text colour: KitAdapters::ruleWidth().
        foreach ( [ 'bold-vibrant', 'brutalist-flat' ] as $id ) {
            $kitId  = (string) DesignLanguages::get( $id )['kit'];
            $border = (array) StyleKits::get( $kitId )['border'];
            $this->assertStringContainsString( sprintf( '%s (%s: quiet %s, strong %s)', $id, $kitId, KitAdapters::ruleWidth( 'rule', $border ), KitAdapters::ruleWidth( 'rule-strong', $border ) ), $text );
        }

        // Hover: one kit of each hover kind, primary, on light, accent and panel, read from patch().
        $kinds = [];
        foreach ( StyleKits::ids() as $id ) {
            $kinds[ (string) StyleKits::get( $id )['hover'] ] ??= $id;
        }
        $this->assertSame( [ 'none', 'invert', 'fill-swap', 'lift' ], array_keys( $kinds ), 'the hover kinds present in style-kits.json, in order of first use' );
        foreach ( $kinds as $kind => $id ) {
            $kit     = (array) StyleKits::get( $id );
            $changes = false;
            $parts   = [];
            foreach ( [ 'light', 'accent', 'panel' ] as $on ) {
                $c = self::colours( (array) KitAdapters::patch( 'divi/button', 'control', $on, $kit ) );
                foreach ( $c as $pair ) {
                    $changes = $changes || $pair[0] !== $pair[1];
                }
                $parts[] = sprintf( 'on %s fill %s to %s, label %s to %s, frame %s to %s', $on, $c['fill'][0], $c['fill'][1], $c['label'][0], $c['label'][1], $c['frame'][0], $c['frame'][1] );
            }
            if ( $changes ) {
                $this->assertStringContainsString( 'Hover ' . $kind . ' (e.g. ' . $id . '): ' . implode( '; ', $parts ) . '.', $text, $kind );
            } else {
                $this->assertMatchesRegularExpression( '/Hover [a-z ,-]*\b' . preg_quote( $kind, '/' ) . '\b[a-z ,-]* keeps? every colour/', $text, "$kind keeps its colours" );
            }
        }

        // The submit: the primary's colours for its band (a surface band is light), for every kit and band.
        $this->assertStringContainsString( "A contact-form submit takes the kit's primary colours for its band", $text );
        foreach ( StyleKits::ids() as $id ) {
            $kit = (array) StyleKits::get( $id );
            foreach ( [ 'light', 'surface', 'accent', 'dark' ] as $on ) {
                $submit = KitAdapters::formPatch( $on, $kit )['button']['decoration'];
                $button = KitAdapters::patch( 'divi/button', 'control', 'surface' === $on ? 'light' : $on, $kit )['button']['decoration'];
                $this->assertSame( $button['background'], $submit['background'], "$id on $on: fill" );
                $this->assertSame( $button['font']['font']['desktop']['value']['color'], $submit['font']['font']['desktop']['value']['color'], "$id on $on: label" );
                $this->assertSame( $button['font']['font']['desktop']['hover']['color'], $submit['font']['font']['desktop']['hover']['color'], "$id on $on: hover label" );
            }
        }

        // The featured plan: the accent ground (fill and frame accent, text accent-text), its rule and its button.
        $ground = KitAdapters::patch( 'divi/column', 'card', 'light', (array) StyleKits::get( 'quiet-solid' ), 'accent' )['module']['decoration'];
        $this->assertSame( '{{aied:color:accent}}', $ground['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:accent}}', $ground['border']['desktop']['value']['styles']['all']['color'] );
        $rule = KitAdapters::patch( 'divi/group', 'rule', 'accent', (array) StyleKits::get( 'quiet-solid' ) )['module']['decoration']['border']['desktop']['value']['styles']['top']['color'];
        $this->assertSame( '{{aied:color:accent-text:40}}', $rule );
        $this->assertStringContainsString( 'fill accent, frame accent, text accent-text', $text );
        $this->assertStringContainsString( 'quiet rule on accent (accent-text at 40%)', $text );
        $this->assertStringContainsString( 'a filled kit\'s button there has surface fill and text label', $text );
        foreach ( StyleKits::ids() as $id ) {
            $kit = (array) StyleKits::get( $id );
            if ( 'solid' === $kit['fill'] ) {
                $c = self::colours( (array) KitAdapters::patch( 'divi/button', 'control', 'accent', $kit ) );
                $this->assertSame( [ 'surface', 'text' ], [ $c['fill'][0], $c['label'][0] ], "$id: filled primary on accent" );
                $p = self::colours( (array) KitAdapters::patch( 'divi/button', 'control', 'panel', $kit ) );
                $this->assertSame( [ 'bg', 'text' ], [ $p['fill'][0], $p['label'][0] ], "$id: filled primary on a panel" );
            }
        }
        $this->assertStringContainsString( 'a filled kit\'s has bg fill and text label', $text );
    }

    /** @return array<string,string> recipe => the evidence that proves the pattern it is named for */
    private const RECIPE_PATTERN = [
        'portfolio-featured-grid' => 'testTheTagAndIconChipShapes',
        'icon-values'             => 'testTheTagAndIconChipShapes',
        'features-carousel-strip' => 'testTheTagAndIconChipShapes',
        'pricing-three-tier'      => 'testTheFeaturedPlan',
        'testimonial'             => 'testTheTestimonialRecipeInks',
        'list-icon-dividers'      => 'dividerRules',
        'team-grid'               => 'groupRules',
    ];

    /** @return list<array{0:string,1:string,2:string}> [block, kit role, on] of every marked block of a recipe */
    private static function markers( string $recipe ): array
    {
        $rows = json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        foreach ( (array) $rows as $row ) {
            if ( $recipe === $row['name'] ) {
                preg_match_all( '/<!-- wp:(divi\/[a-z-]+) (\{.*?\}) \/?-->/', (string) $row['markup'], $m, PREG_SET_ORDER );
                $out = [];
                foreach ( $m as $hit ) {
                    $attrs = json_decode( $hit[2], true );
                    if ( is_array( $attrs['aied'] ?? null ) && isset( $attrs['aied']['kit'] ) ) {
                        $out[] = [ $hit[1], (string) $attrs['aied']['kit'], (string) ( $attrs['aied']['on'] ?? 'light' ) ];
                    }
                }
                return $out;
            }
        }
        self::fail( "no recipe $recipe" );
    }

    public function testEachNamedRecipeShowsItsPattern(): void
    {
        $text  = self::topic();
        $names = SectionRecipes::names();
        preg_match_all( '/`([a-z][a-z0-9-]*)`/', $text, $m );
        $named = array_values( array_unique( array_intersect( $m[1], $names ) ) );
        sort( $named );
        $map = array_keys( self::RECIPE_PATTERN );
        sort( $map );
        $this->assertSame( $map, $named, 'the topic names exactly the mapped recipes' );

        $evidence = new WordingGapsEvidenceTest( 'testTheFeaturedPlan' );
        foreach ( self::RECIPE_PATTERN as $recipe => $check ) {
            if ( 'dividerRules' === $check ) {
                $m = self::markers( $recipe );
                $this->assertContains( [ 'divi/divider', 'rule-strong', 'surface' ], $m, "$recipe: a strong divider opens the list" );
                $this->assertContains( [ 'divi/divider', 'rule', 'surface' ], $m, "$recipe: quiet dividers between the rows" );
                continue;
            }
            if ( 'groupRules' === $check ) {
                $this->assertContains( [ 'divi/group', 'rule-strong', 'light' ], self::markers( $recipe ), "$recipe: a strong top edge on a group" );
                continue;
            }
            $evidence->$check();
        }

        // The two values the topic prints from constants equal what the named recipes write.
        $rows    = (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        $attrsOf = static function ( string $recipe, string $block, string $kit ) use ( $rows ): array {
            foreach ( $rows as $row ) {
                if ( $recipe === $row['name'] && preg_match_all( '/<!-- wp:' . preg_quote( $block, '/' ) . ' (\{.*?\}) \/?-->/', (string) $row['markup'], $m ) ) {
                    foreach ( $m[1] as $json ) {
                        $attrs = (array) json_decode( $json, true );
                        if ( $kit === ( $attrs['aied']['kit'] ?? null ) ) {
                            return $attrs;
                        }
                    }
                }
            }
            return [];
        };
        $testimonial = $attrsOf( 'testimonial', 'divi/testimonial', 'panel' );
        foreach ( StyleGuide::TESTIMONIAL_INK as $path => $role ) {
            $node = $testimonial;
            foreach ( explode( '.', $path ) as $key ) {
                $node = is_array( $node ) ? ( $node[ $key ] ?? null ) : null;
            }
            $this->assertSame( '{{aied:color:' . $role . '}}', $node, $path );
            $this->assertStringContainsString( '`' . $path . '`', $text );
        }
        $tag = $attrsOf( 'portfolio-featured-grid', 'divi/group', 'chip' );
        $this->assertEquals( StyleGuide::PATTERN_TAG_PADDING, $tag['module']['decoration']['spacing']['desktop']['value']['padding'] ?? null, 'the tag padding printed is the recipe\'s' );
    }

    public function testTheLayoutClaimsFollowTheProbe(): void
    {
        // The plugin's verdicts equal the "Adapter decisions" table of the P1 vocabulary file.
        $vocab = (string) file_get_contents( __DIR__ . '/../fixtures/shapes/kit-patterns-vocabulary.md' );
        preg_match_all( '/^\| ([a-z-]+) \| (PASS|FAIL|DEAD|[A-Za-z -]+) \|/m', $vocab, $rows, PREG_SET_ORDER );
        $table = [];
        foreach ( $rows as $row ) {
            if ( 'Claim' !== $row[1] ) {
                $table[ $row[1] ] = 'PASS' === $row[2];
            }
        }
        $this->assertSame( $table, StyleGuide::PATTERN_PROVEN );

        // The probe json: the 48px cap changed max-width only, never the computed width (the box already hugs).
        $results = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/shapes/probe-results-kit-patterns.json' ), true );
        $this->assertIsArray( $results );
        foreach ( $results as $r ) {
            foreach ( (array) $r['detail'] as $width => $d ) {
                $this->assertNotContains( 'width', (array) $d['changedProps'], "{$r['id']} at $width: the computed width did not change" );
            }
        }

        // The row layout printed is the one the rendered P1 page used.
        $page = (string) file_get_contents( __DIR__ . '/../fixtures/shapes/kit-patterns-rows-page.html' );
        $this->assertStringContainsString( '"layout":{"desktop":{"value":' . json_encode( StyleGuide::PATTERN_ROW_LAYOUT ) . '}}', $page );

        $text = self::topic();
        $this->assertStringContainsString( json_encode( StyleGuide::PATTERN_ROW_LAYOUT ), $text );
        $this->assertStringNotContainsString( 'maxWidth', $text, 'P1: the box hugs, no maxWidth is taught' );
        $this->assertStringContainsString( 'A row of tags', $text );
        $this->assertStringContainsString( 'a row of icon chips', $text );
        $this->assertStringContainsString( "Divi's default centre", $text );

        // A claim P1 did not prove is never made (planted verdicts).
        $no = StyleGuide::kitPatterns( array_merge( StyleGuide::PATTERN_PROVEN, [ 'row-of-tags' => false ] ) );
        $this->assertStringNotContainsString( 'A row of tags', $no );
        $this->assertStringContainsString( "copy the recipe's shape", $no );
        $no = StyleGuide::kitPatterns( array_merge( StyleGuide::PATTERN_PROVEN, [ 'row-of-icons' => false ] ) );
        $this->assertStringNotContainsString( 'a row of icon chips', $no );
        $no = StyleGuide::kitPatterns( array_merge( StyleGuide::PATTERN_PROVEN, [ 'icon-hugs-center' => false ] ) );
        $this->assertStringNotContainsString( "Divi's default centre", $no );
        $no = StyleGuide::kitPatterns( array_merge( StyleGuide::PATTERN_PROVEN, [ 'icon-hugs-left' => false ] ) );
        $this->assertStringNotContainsString( 'hugs its glyph', $no );
    }

    public function testTheQuietStrongSentenceIsGenerated(): void
    {
        $text  = self::topic();
        $same  = [];
        $quiet = [];
        foreach ( DesignLanguages::ids() as $id ) {
            $language = (array) DesignLanguages::get( $id );
            if ( $language['colors']['border'] === $language['colors']['text'] ) {
                $same[] = $id;
                continue;
            }
            foreach ( [ 'light', 'surface' ] as $band ) {
                $quiet[] = RuleChipAudit::ratio( 'border', $band, $language );
            }
        }
        $this->assertSame( [ 'bold-vibrant', 'brutalist-flat' ], $same );
        $this->assertStringContainsString( sprintf( 'only %.2f:1 to %.2f:1 on light and surface', min( $quiet ), max( $quiet ) ), $text );
        preg_match( '/hairline is the text colour \(([^)]*\)[^)]*\))\)/', $text, $m );
        $this->assertNotEmpty( $m, 'the languages are listed' );
        foreach ( DesignLanguages::ids() as $id ) {
            $this->assertSame( in_array( $id, $same, true ), str_contains( $m[1], $id . ' (' ), $id );
        }
    }

    public function testTheLimitsNameTheKitsTheHoverFixCannotReach(): void
    {
        $zero = [];
        foreach ( StyleKits::ids() as $id ) {
            $kit = (array) StyleKits::get( $id );
            if ( 'invert' === $kit['hover'] && '0px' === $kit['border']['width'] ) {
                $zero[] = $id;
            }
        }
        $this->assertSame( [ 'flat-square', 'block-caps', 'pill-caps' ], $zero );
        $text   = self::topic();
        $limits = substr( $text, (int) strpos( $text, '## Limits' ) );
        $this->assertStringContainsString( 'The 0px invert kits (' . implode( ', ', $zero ) . ')', $limits );
        // The languages where accent-text melts into the page (KitHoverVisibilityTest::LIGHT_LANGUAGES) and the one where the
        // accent melts into the text colour (the four tech-mono accent cells), both computed.
        $melt = [];
        $faint = [];
        foreach ( DesignLanguages::ids() as $id ) {
            $c = (array) DesignLanguages::get( $id )['colors'];
            if ( DesignLanguages::contrast( (string) $c['accent_text'], (string) $c['bg'] ) < 1.1 ) {
                $melt[] = $id;
            }
            if ( DesignLanguages::contrast( (string) $c['text'], (string) $c['accent'] ) < 1.5 ) {
                $faint[] = $id;
            }
        }
        $this->assertSame( [ 'editorial', 'glass-dark', 'tech-mono', 'corporate-trust', 'soft-saas', 'luxe' ], $melt );
        $this->assertSame( [ 'tech-mono' ], $faint );
        $this->assertStringContainsString( '(' . implode( ', ', $melt ) . ')', $limits );
        $this->assertStringContainsString( 'in ' . implode( ', ', $faint ) . ' the hover of a kit with a text', $limits );
    }

    public function testTheKitLineFollowsTheMapAndTheStrongWidth(): void
    {
        $nouns = StyleGuide::appliedNouns();
        $this->assertSame( 'Kit-driven today: ' . ( count( $nouns ) > 1 ? implode( ', ', array_slice( $nouns, 0, -1 ) ) . ' and ' . end( $nouns ) : $nouns[0] ) . ' (blocks and marker roles: topic=kit-rules).', StyleGuide::appliedNounsSentence() );
        foreach ( DesignLanguages::ids() as $id ) {
            $language = (array) DesignLanguages::get( $id );
            $line     = DesignLanguages::kitLine( $language );
            $this->assertSame( $line, DesignLanguages::generatedLines( $language )['kit'] ?? null );
            $this->assertLessThanOrEqual( DesignLanguages::GENERATED_LINE_MAX, strlen( $line ), $id );
            $this->assertStringStartsWith( '- Kit: ' . $language['kit'] . ' ', $line );
            $this->assertStringContainsString( implode( ', ', array_slice( $nouns, 0, -1 ) ) . ' and ' . end( $nouns ), $line, "$id: the nouns of the map" );
            $this->assertStringContainsString( 'the kit wins', $line );
            $this->assertStringContainsString( 'strong rule: ' . KitAdapters::ruleWidth( 'rule-strong', (array) StyleKits::get( (string) $language['kit'] )['border'] ) . ')', $line );
            $this->assertStringContainsString( 'topic=kit-patterns', $line );
            foreach ( [ '{{', '<', '`' ] as $bad ) {
                $this->assertStringNotContainsString( $bad, $line, $id );
            }
            $lines = explode( "\n", StyleGuide::markdown( 'design-language:' . $id ) );
            $at    = array_search( $line, $lines, true );
            $this->assertIsInt( $at, "$id: the line is printed" );
            $this->assertSame( '- Style kit: ' . $language['kit'], $lines[ $at - 1 ], "$id: right after the Style kit line" );
        }
        // Ruling 5: the prose no kit can follow is reworded.
        $this->assertStringNotContainsString( '6px black line', StyleGuide::markdown( 'design-language:brutalist-flat' ) );
        $this->assertStringContainsString( 'Open every section with a strong black line (the divider module)', StyleGuide::markdown( 'design-language:brutalist-flat' ) );
        $this->assertStringNotContainsString( '1px line in the accent colour', StyleGuide::markdown( 'design-language:luxe' ) );
        $this->assertSame( 2, substr_count( StyleGuide::markdown( 'design-language:luxe' ), "(the kit's accent rule)" ) );
    }

    public function testTheBandSentenceNamesTheRoles(): void
    {
        $sentence = StyleGuide::bandSentence();
        $bands    = array_values( array_unique( array_merge( KitAdapters::RULE_BANDS, KitAdapters::CHIP_BANDS ) ) );
        $this->assertSame( $bands, array_keys( KitAdapters::BAND_FILL ), 'BAND_FILL covers exactly the bands the adapters accept (spec issue 10)' );
        foreach ( KitAdapters::BAND_FILL as $band => $role ) {
            $this->assertMatchesRegularExpression( '/`' . $band . '` ' . $role . '[ ,.]/', $sentence, $band );
        }
        // The dogfood scorer's palette, read in reverse (first role per band), is the same table.
        $palette = ( new \ReflectionClass( \Divi5Validator\Tests\Support\Dogfood\Page::class ) )->getConstant( 'PALETTE' );
        $reverse = [];
        foreach ( (array) $palette as $role => [ , $band ] ) {
            $reverse[ $band ] ??= $role;
        }
        foreach ( KitAdapters::BAND_FILL as $band => $role ) {
            $this->assertSame( $role, $reverse[ $band ] ?? null, $band );
        }
        $this->assertLessThanOrEqual( 212, strlen( $sentence ), 'no longer than the sentence it replaces (212 B)' );
    }

    public function testTheAccordionSentenceSaysEachItemNotTheWhole(): void
    {
        $text = StyleGuide::markdown( 'kit-rules' );
        $this->assertStringContainsString( 'On the accordion write all of it on the parent; Divi draws the frame, radius and shadow around each item, never around the whole accordion, and the items carry none:', $text );
        $this->assertStringNotContainsString( 'each item gets it', $text );
    }

    public function testTheTagClauseSaysTheKitWidth(): void
    {
        $text = StyleGuide::markdown( 'kit-rules' );
        $this->assertStringContainsString( "`styles.all` (width and style: the kit's, 1px at least, 3px for a double). Its fill stays transparent", $text );
        // The double width follows the tag's own decision (KitAdapters::CHIP_PROVEN `tag-double`), not the rule carriers'.
        $noTagDouble = StyleGuide::kitRules( null, null, null, null, array_merge( KitAdapters::CHIP_PROVEN, [ 'tag-double' => false ] ) );
        $this->assertStringContainsString( "`styles.all` (width and style: the kit's, 1px at least)", $noTagDouble );
        $this->assertStringNotContainsString( '3px for a double', $noTagDouble );
        $this->assertStringNotContainsString( '(width, style, colour; 1px at least)', $text );
    }

    public function testTheControlsButtonDeadSentenceNamesTheContactFormException(): void
    {
        $clause = ' This is a `divi/button`; a contact form\'s submit is the opposite (topic=kit-rules).';
        $this->assertStringContainsString( 'changed nothing (rendered: dead); write them on the module.' . $clause, StyleGuide::markdown( 'controls' ) );
        $this->assertSame( $clause, StyleGuide::controlsFormClause() );
        $this->assertSame( '', StyleGuide::controlsFormClause( array_merge( KitAdapters::FORM_PROVEN, [ 'button-padding' => false ] ) ) );
        $this->assertSame( '', StyleGuide::controlsFormClause( array_merge( KitAdapters::FORM_PROVEN, [ 'button-shadow' => false ] ) ) );
        $this->assertLessThanOrEqual( 9500, strlen( StyleGuide::markdown( 'controls' ) ) );
    }

    public function testTheDefaultGuideTeachesOneRowStackingShape(): void
    {
        $text = StyleGuide::markdown();
        $this->assertStringNotContainsString( 'Mobile: collapse', $text );
        $this->assertStringNotContainsString( '(Set this on the divi/row', $text );
        $this->assertStringNotContainsString( '{"display":"flex","flexDirection":"column","rowGap":"0px"}', $text );
        $this->assertStringContainsString( '## Mobile: rows and flex groups', $text );
        $this->assertStringNotContainsString( 'stops.0', $text );
        $this->assertStringNotContainsString( 'stops.1', $text );
        $this->assertStringContainsString( '`…gradient.stops` = a list: `[{"position":0,"color":"rgba(10,10,10,.5)"},{"position":100,"color":"rgba(0,0,0,.6)"}]`', $text );
        $this->assertSame( 28975, strlen( $text ), 'exactly the same size' );
    }
}
