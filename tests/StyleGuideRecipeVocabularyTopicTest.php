<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleGuide;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'CustomFields', 'StyleReferences', 'LayoutDiff' ] as $recipeVocabularyTestClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $recipeVocabularyTestClass . '.php';
}
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';

/**
 * What the recipe tasks proved, taught (Task 17a): the topics `toggles` (accordion, toggle, tabs), `forms` (contact form, captcha,
 * countdown, modules not to use) and `composition` (techniques the recipes use), the circle counter in `controls` and the flex
 * grow/shrink rule in `layout-sizing`. Every list is the exact set of paths the probe rendered (fixtures/shapes/candidates/recipe-*.json
 * and their probe results), every measured number is pinned to its result, and every source-only rule has a committed note.
 */
class StyleGuideRecipeVocabularyTopicTest extends TestCase
{
    private const TOPIC_BUDGET = 10000;
    private const MIN_HEADROOM = 500;

    /** @var array<string,array<string,mixed>> */
    private static array $cands = [];
    /** @var array<string,array<string,mixed>> */
    private static array $results = [];

    private function load(): void
    {
        if ( [] !== self::$cands ) {
            return;
        }
        foreach ( [ 'recipe-features', 'recipe-faq', 'recipe-proof', 'recipe-cta', 'recipe-cta-form', 'recipe-captcha', 'recipe-modules' ] as $family ) {
            $c = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/shapes/candidates/' . $family . '.json' ), true );
            $r = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/shapes/probe-results-' . $family . '.json' ), true );
            $this->assertIsArray( $c, $family );
            $this->assertIsArray( $r, $family );
            foreach ( $c as $cand ) {
                self::$cands[ $cand['id'] ] = $cand;
            }
            foreach ( $r as $res ) {
                self::$results[ $res['id'] ] = $res;
            }
        }
    }

    private function passed( string $id ): bool
    {
        $this->load();
        $this->assertArrayHasKey( $id, self::$results, $id . ' was probed' );
        $r = self::$results[ $id ];

        return ! empty( $r['effect'] ) && empty( $r['phoneOverflow'] ) && empty( $r['error'] ) && empty( $r['cssOnly'] );
    }

    private static function normalise( string $path ): string
    {
        return implode( '.', array_map( static fn ( string $s ): string => in_array( $s, ShapePaths::BREAKPOINTS, true ) ? '{bp}' : $s, explode( '.', $path ) ) );
    }

    /** @param list<string> $ids @return list<string> the normalised set paths of these candidates (each must have passed), sorted */
    private function setPaths( array $ids ): array
    {
        $this->load();
        $out = [];
        foreach ( $ids as $id ) {
            $this->assertTrue( $this->passed( $id ), $id . ' passed on the real Divi' );
            foreach ( array_keys( self::$cands[ $id ]['set'] ) as $p ) {
                $out[ self::normalise( (string) $p ) ] = true;
            }
        }
        $paths = array_keys( $out );
        sort( $paths );

        return $paths;
    }

    /** @return list<string> the first sample line at a width */
    private function sample( string $id, string $width = '1280' ): array
    {
        $this->load();

        return array_map( 'strval', (array) ( self::$results[ $id ]['detail'][ $width ]['sample'] ?? [] ) );
    }

    private function section( string $topic, string $heading ): string
    {
        $text  = StyleGuide::markdown( $topic );
        $start = strpos( $text, "\n## " . $heading . "\n" );
        $this->assertNotFalse( $start, $topic . ': ## ' . $heading );
        $end = strpos( $text, "\n## ", $start + 1 );

        return substr( $text, $start, false === $end ? null : $end - $start );
    }

    /** @return list<string> the alternatives of the first `{a,b}` group (not `{bp}`), recursively */
    private static function expand( string $token ): array
    {
        if ( 1 === preg_match( '/\{([A-Za-z0-9_]+(?:,[A-Za-z0-9_]+)+)\}/', $token, $g ) ) {
            $out = [];
            foreach ( explode( ',', $g[1] ) as $alt ) {
                $out = array_merge( $out, self::expand( (string) preg_replace( '/' . preg_quote( $g[0], '/' ) . '/', $alt, $token, 1 ) ) );
            }

            return $out;
        }

        return [ $token ];
    }

    /** @return list<string> the full paths a section's bullets teach ("- `path`", braces expanded), sorted, none twice */
    private function bulletPaths( string $section ): array
    {
        preg_match_all( '/^- `([A-Za-z][^`]*\.[^`]*)`/m', $section, $m );
        $paths = [];
        foreach ( $m[1] as $token ) {
            $paths = array_merge( $paths, self::expand( $token ) );
        }
        $this->assertSame( $paths, array_values( array_unique( $paths ) ), 'no setting listed twice' );
        sort( $paths );

        return $paths;
    }

    private static function flat( string $text ): string
    {
        return preg_replace( '/\s+/', ' ', $text ) ?? '';
    }

    // ---- served, budgeted, honest opener ----

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function newTopics(): array
    {
        return [
            'toggles'     => [ 'toggles', '# Toggles', 'controls' ],
            'forms'       => [ 'forms', '# Forms', 'toggles' ],
            'composition' => [ 'composition', '# Composition', 'forms' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'newTopics' )]
    public function testTopicIsListedAndServedWithinBudget( string $name, string $title, string $before ): void
    {
        $topics = StyleGuide::topics();
        $this->assertContains( $name, $topics );
        $this->assertSame( $before, $topics[ array_search( $name, $topics, true ) - 1 ], 'appended after ' . $before );
        $text = StyleGuide::markdown( $name );
        $this->assertStringStartsWith( $title, $text );
        $this->assertLessThanOrEqual( self::TOPIC_BUDGET - self::MIN_HEADROOM, strlen( $text ), 'at least 500 bytes of headroom' );
        $this->assertStringNotContainsString( '{{aied:', $text );
        $this->assertStringNotContainsString( 'http', $text, 'no remote host in a topic' );
        $this->assertDoesNotMatchRegularExpression( '/freeForm|mainElement|"css"|<script|@keyframes|\bvideo\b|backdrop/i', $text );
        $flat = self::flat( $text );
        $this->assertStringContainsString( 'rendered on the real Divi 5.15.1', $flat );
        $this->assertStringContainsString( 'front end', $flat );
        $this->assertStringContainsString( 'The Visual Builder was not checked.', $flat );
        $this->assertStringContainsString( '(source)', $text );
        $this->assertStringContainsString( '## Limits', $text );
        $this->assertStringContainsString( $name, StyleGuide::markdown(), 'the default guide announces it' );
        $this->assertStringContainsString( $name, StyleGuide::topicParameterDescription() );
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'get_style_guide', 'arguments' => [ 'topic' => $name ] ] ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();
        $this->assertSame( $text, (string) $data['result']['content'][0]['text'], 'MCP serves the same text' );
        $rest = ( new RestController() )->style_guide( new \WP_REST_Request( [], [ 'topic' => $name ] ) );
        $this->assertSame( [ 'guide' => $text ], $rest->get_data(), 'REST serves the same text' );
    }

    public function testTheGrownTopicsStayWithinTheirBudget(): void
    {
        $this->assertLessThanOrEqual( self::TOPIC_BUDGET - self::MIN_HEADROOM, strlen( StyleGuide::markdown( 'controls' ) ) );
        $this->assertLessThanOrEqual( self::TOPIC_BUDGET, strlen( StyleGuide::markdown( 'layout-sizing' ) ) );
        $this->assertLessThanOrEqual( self::TOPIC_BUDGET - self::MIN_HEADROOM, strlen( StyleGuide::markdown( 'dividers' ) ) );
    }

    // ---- every list is the exact set the probe rendered ----

    public function testEveryTaughtListIsExactlyTheRenderedSet(): void
    {
        $registry = ShapeRegistry::load();
        $groups   = [
            [ 'toggles', 'Accordion and toggle', [ 'rf-acc-open-bg', 'rf-acc-closed-bg', 'rf-acc-icon-color', 'rt-toggle-open-bg', 'rt-toggle-closed-bg', 'rt-toggle-closed-icon' ] ],
            [ 'toggles', 'Toggle only', [ 'rt-toggle-open-title-color', 'rt-toggle-open-title-color-h3', 'rt-toggle-closed-title-color', 'rt-toggle-open-icon', 'rt-toggle-title-color-open', 'rt-toggle-title-color-closed', 'rt-toggle-title-color-open-h3', 'rt-toggle-content-color' ] ],
            [ 'toggles', 'Tabs', [ 'rf-tabs-tab-bg', 'rf-tabs-tab-color', 'rf-tabs-tab-size', 'rf-tabs-tab-family', 'rf-tabs-active-bg', 'rf-tabs-active-color', 'rf-tabs-content-bg' ] ],
            [ 'forms', 'Contact form fields', [ 'rm-cf-field-bg', 'rm-cf-field-color', 'rm-cf-field-family', 'rc-cf-placeholder-color', 'rc-cf-field-radius', 'rc-cf-field-border' ] ],
            [ 'forms', 'Captcha', [ 'rc-captcha-color', 'rm-cf-captcha-family', 'rc-cf-captcha-box-border', 'rc-cf-captcha-box-background' ] ],
            [ 'forms', 'Countdown timer', [ 'rc-cd-number-color', 'rc-cd-number-lineheight', 'rc-cd-separator-color', 'rc-cd-separator-family', 'rc-cd-separator-size', 'rc-cd-separator-lineheight', 'rc-cd-label-color', 'rc-cd-label-family', 'rc-cd-label-size' ] ],
            [ 'controls', 'Circle counter', [ 'rs-circle-bar-color', 'rs-circle-track-color', 'rs-circle-track-opacity', 'rs-circle-percent-off', 'rs-circle-number-color', 'rs-circle-title-color' ] ],
        ];
        foreach ( $groups as [ $topic, $heading, $ids ] ) {
            $taught = $this->bulletPaths( $this->section( $topic, $heading ) );
            $this->assertSame( $this->setPaths( $ids ), $taught, "$topic / $heading: taught paths == the rendered set" );
            foreach ( $taught as $path ) {
                $this->assertTrue( $registry->coversLeaf( $path ), "$path is a registered leaf" );
            }
        }
        // The block each family was measured on is the one the topic names.
        foreach ( [ 'rf-acc-open-bg' => 'accordion', 'rt-toggle-open-bg' => 'toggle', 'rf-tabs-tab-bg' => 'tabs', 'rc-cf-placeholder-color' => 'contact-form', 'rc-cd-label-size' => 'countdown-timer', 'rs-circle-bar-color' => 'circle-counter' ] as $id => $block ) {
            $this->assertSame( $block, self::$cands[ $id ]['block'], $id );
        }
    }

    public function testCompositionTeachesOnlyRegisteredLeavesUsedByTheRecipesItNames(): void
    {
        $registry = ShapeRegistry::load();
        $text     = StyleGuide::markdown( 'composition' );
        foreach ( [ 'Hairline-ruled grid', 'One-side rule', 'Two-edged divider band', 'Full-width row band' ] as $heading ) {
            $paths = $this->bulletPaths( $this->section( 'composition', $heading ) );
            $this->assertNotSame( [], $paths, $heading );
            foreach ( $paths as $path ) {
                $this->assertTrue( $registry->coversLeaf( $path ) || $registry->coversLeaf( $path . '.{n}' ), "$heading: $path is a registered leaf (a list: its entries)" );
            }
        }
        // Each technique is really in the recipe it names.
        foreach ( [
            'proof-logo-wall'          => [ '"columnGap":"1px"', '"rowGap":"1px"', '"display":"grid"' ],
            'stats-big-captions'       => [ '"columnGap":"1px"' ],
            'pricing-comparison-table' => [ '"columnGap":"1px"' ],
            'proof-press-quotes'       => [ '"all":{"width":"0px"', '"top":{"width":"2px"}' ],
            'transition-wave-band'     => [ '"dividers":{"top":{"desktop":{"value":{"style":"wave","height":"80px","color":"{{aied:color:bg}}"}}', '"flip":["horizontal"]' ],
            'transition-overlap-panel' => [ '"sizing":{"desktop":{"value":{"width":"100%","maxWidth":"100%"}}}', '"margin":{"top":"-160px"}' ],
        ] as $recipe => $needles ) {
            $this->assertStringContainsString( '`' . $recipe . '`', $text );
            foreach ( $needles as $needle ) {
                $this->assertStringContainsString( $needle, (string) SectionRecipes::rawMarkup( $recipe ), "$recipe uses $needle" );
            }
        }
        $flat = self::flat( $text );
        $this->assertStringContainsString( 'The technique as a whole was not probed', $flat );
        $this->assertStringContainsString( 'on a row they are proven only by the render gate on that recipe, not by a probe of their own', $flat );
        $this->assertStringContainsString( 'with one shared colour and no `color` Divi paints the divider black (source)', $flat );
        $this->assertStringContainsString( 'When both sections share one colour and `color` is unset, Divi paints the divider black (source).', self::flat( StyleGuide::markdown( 'dividers' ) ), 'the rule the composition topic repeats is the dividers topic\'s own' );
        $this->assertStringContainsString( 'see the topic `composition`', StyleGuide::markdown( 'dividers' ) );
    }

    // ---- measured claims pinned to the results ----

    public function testMeasuredClaimsMatchTheProbeResults(): void
    {
        $this->load();
        $forms = self::flat( StyleGuide::markdown( 'forms' ) );
        // placeholder: Chrome's #757575 before, the field font colour does not reach it
        $this->assertStringContainsString( '3:input:::placeholder:border-block-end-color: rgb(117, 117, 117) ->', $this->sample( 'rc-cf-placeholder-color' )[0] );
        $this->assertSame( [ '::placeholder' ], self::$cands['rc-cf-placeholder-color']['pseudo'] );
        $this->assertStringContainsString( "from Chrome's #757575; the field font colour does NOT reach the placeholder, so set both", $forms );
        // field defaults: fill #eeeeee, text #999999; captcha question #666666
        $this->assertSame( '3:input:background-color: rgb(238, 238, 238) -> rgb(255, 243, 205)', $this->sample( 'rm-cf-field-bg' )[0] );
        $this->assertStringContainsString( '3:input:color: rgb(153, 153, 153) ->', implode( "\n", $this->sample( 'rm-cf-field-color' ) ) );
        $this->assertStringContainsString( 'the fill (Divi\'s #eeeeee by default), the typed text colour (#999999 by default)', $forms );
        $this->assertStringContainsString( 'rgb(102, 102, 102) ->', $this->sample( 'rc-captcha-color' )[0] );
        $this->assertStringContainsString( "its question prints Divi's #666666 (rendered)", $forms );
        // captcha border/background reach the paragraph, not the box
        $this->assertStringStartsWith( '13:p:', $this->sample( 'rc-cf-captcha-box-background' )[0] );
        $this->assertStringStartsWith( '13:p:', $this->sample( 'rc-cf-captcha-box-border' )[0] );
        $this->assertStringContainsString( 'styled the paragraph that holds the question and the answer box together (rendered), not the box', $forms );
        // frame and corners
        $this->assertSame( [ '1px', '#c2410c', 'solid' ], array_values( self::$cands['rc-cf-field-border']['set'] ) );
        $this->assertStringContainsString( '3:input:border-bottom-left-radius: 0px -> 12px', $this->sample( 'rc-cf-field-radius' )[0] );
        // countdown label 14px -> 18px
        $this->assertSame( '5:p:font-size: 14px -> 18px', $this->sample( 'rc-cd-label-size' )[0] );
        $this->assertStringContainsString( 'the label size went from 14px to 18px', $forms );
        // circle counter
        $controls = self::flat( StyleGuide::markdown( 'controls' ) );
        $this->assertStringContainsString( '50%', $this->sample( 'rs-circle-percent-off' )[0] );
        $this->assertStringContainsString( '-> 50', $this->sample( 'rs-circle-percent-off' )[0] );
        $this->assertSame( 'off', self::$cands['rs-circle-percent-off']['set']['number.advanced.percentSign.desktop.value'] );
        $this->assertStringContainsString( '`percentSign` "off" printed "50" instead of "50%"', $controls );
        $this->assertSame( '#ff3b00', self::$cands['rs-circle-track-opacity']['base']['circle']['advanced']['background']['desktop']['value']['color'], 'opacity was proven with a track colour' );
        $this->assertStringContainsString( 'a first run with no track colour in the base measured no change (DEAD)', (string) self::$cands['rs-circle-track-opacity']['note'] );
        $this->assertStringContainsString( 'a first run with no track colour in the base measured no change (DEAD)', (string) file_get_contents( __DIR__ . '/../docs/shape-evidence-5.15.1.md' ) );
        $this->assertStringContainsString( 'Set the two together: opacity with no track colour changed nothing in a first run (the candidate note in the evidence file).', $controls );
        $this->assertStringContainsString( 'Naming trap: the number counter\'s switch is `enablePercentSign`, the circle counter\'s `percentSign`', $controls );
        $this->assertStringContainsString( 'use a figure the owner can prove, or remove the counter', $controls );
        $this->assertStringContainsString( 'divider width and the bar counter were not.', $controls );
        // toggles: defaults and the h3 measurement
        $toggles = self::flat( StyleGuide::markdown( 'toggles' ) );
        $this->assertSame( '0:div:background-color: rgb(255, 255, 255) -> rgb(21, 28, 51)', $this->sample( 'rt-toggle-open-bg' )[0] );
        $this->assertSame( '0:div:background-color: rgb(244, 244, 244) -> rgb(21, 28, 51)', $this->sample( 'rt-toggle-closed-bg' )[0] );
        $this->assertStringContainsString( "the open item's background (white by default) and the closed items' (Divi's #f4f4f4)", $toggles );
        $this->assertSame( 'h3', self::$cands['rt-toggle-open-title-color-h3']['base']['title']['decoration']['font']['font']['desktop']['value']['headingLevel'] );
        $this->assertStringStartsWith( '1:h3:', $this->sample( 'rt-toggle-open-title-color-h3' )[0] );
        $this->assertStringStartsWith( '1:h5:', $this->sample( 'rt-toggle-open-title-color' )[0] );
        $this->assertStringContainsString( 'set the open title with the title at h5 and at h3', $toggles );
        // layout-sizing: the grow-only trap overflows, grow + shrink wraps
        $this->assertNotEmpty( self::$results['rf-size-grow-only-trap']['phoneOverflow'] );
        $this->assertSame( 600, (int) self::$results['rf-size-grow-only-trap']['detail']['400']['overflowPx'] );
        $this->assertSame( [ 'flexGrow' ], self::$cands['rf-size-grow-only-trap']['set']['module.decoration.sizing.desktop.value.size'] );
        $this->assertTrue( $this->passed( 'rf-size-grow-shrink' ) );
        $this->assertSame( 0, (int) self::$results['rf-size-grow-shrink']['detail']['400']['overflowPx'] );
        $this->assertContains( '0:div:flex-shrink: 0 -> 1', $this->sample( 'rf-size-grow-shrink', '400' ) );
        $this->assertSame( [ 'flexGrow', 'flexShrink' ], self::$cands['rf-size-grow-shrink']['set']['module.decoration.sizing.desktop.value.size'] );
        $this->assertStringContainsString( '["flexGrow"] alone also writes flex-shrink 0: text beside an icon in a nowrap row made a 400px page 600px too wide (not safe); ["flexGrow","flexShrink"] let it wrap (rendered).', self::flat( StyleGuide::markdown( 'layout-sizing' ) ) );
    }

    /** A rule read from Divi's source is labelled (source) in the topic and has a committed note naming the source file. */
    public function testSourceRulesHaveACommittedNote(): void
    {
        $notes = '';
        foreach ( [ 'not-taught-recipe-features.json', 'not-taught-recipe-cta.json' ] as $file ) {
            $rows = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/shapes/' . $file ), true );
            $this->assertIsArray( $rows, $file );
            foreach ( $rows as $row ) {
                $notes .= $row['item'] . ': ' . $row['reason'] . "\n";
            }
        }
        $evidence = (string) file_get_contents( __DIR__ . '/../docs/shape-evidence-5.15.1.md' );
        foreach ( [
            'toggles' => [
                [ 'Divi paints the tab bar #f4f4f4 and the separators #d9d9d9, and no setting reaches them (source', [ 'ul.et_pb_tabs_controls{background-color:#f4f4f4}', '.et_pb_tabs_controls li{border-right:1px solid #d9d9d9}', 'customCssFields.tabsControls' ] ],
                [ 'the outer frame was not tested', [ 'the outer frame (`.et_pb_tabs{border:1px solid #d9d9d9}`) is the module\'s own border and was not probed' ] ],
                [ 'The accordion draws no icon on its open item (source', [ '.et_pb_toggle_open>.et_pb_toggle_title:before{display:none}', 'no openToggleIcon' ] ],
            ],
            'forms'   => [
                [ 'Divi dims a unit that has reached zero (source: its stylesheet sets opacity .4 on it)', [ '.et_pb_countdown_timer .section.zero p{opacity:.4}' ] ],
                [ 'it needs a Google Maps API key and loads Google\'s script from a third-party host (source)', [ 'et_pb_get_google_api_key()', 'MapModule.php:183-207' ] ],
                [ 'it lists the site\'s own Divi Projects (source)', [ 'post_type project', 'FilterablePortfolioModule.php:104' ] ],
            ],
        ] as $topic => $rules ) {
            $text = self::flat( StyleGuide::markdown( $topic ) );
            foreach ( $rules as [ $sentence, $needles ] ) {
                $this->assertStringContainsString( $sentence, $text, $topic );
                foreach ( $needles as $needle ) {
                    $this->assertStringContainsString( $needle, $notes, 'the note cites ' . $needle );
                    $this->assertStringContainsString( $needle, $evidence, 'the evidence doc carries ' . $needle );
                }
            }
        }
    }

    /** A "Not taught" block lists measured candidates only when there are some: no empty lead line (scripts/promote-shapes.php). */
    public function testTheEvidenceDocHasNoEmptyNotTaughtLead(): void
    {
        $evidence = (string) file_get_contents( __DIR__ . '/../docs/shape-evidence-5.15.1.md' );
        $this->assertSame( 0, preg_match_all( '/that were measured and are not taught, with the reason:\n\n(?!- `)/', $evidence ), 'a lead line with no candidate under it' );
        $this->assertGreaterThan( 0, preg_match_all( '/that were measured and are not taught, with the reason:\n\n- `/', $evidence ), 'the check sees the real blocks' );
    }

    // ---- worked examples ----

    /** @return array<string,array{0:string,1:int}> */
    public static function exampleTopics(): array
    {
        return [ 'toggles' => [ 'toggles', 1 ], 'forms' => [ 'forms', 1 ], 'composition' => [ 'composition', 2 ] ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'exampleTopics' )]
    public function testWorkedExamplesValidateAndUseOnlyRegisteredShapes( string $topic, int $minimum ): void
    {
        preg_match_all( '/```\n(<!-- wp:divi\/section .*?)\n```/s', StyleGuide::markdown( $topic ), $m );
        $this->assertCount( $minimum, $m[1] );
        $registry = ShapeRegistry::load();
        foreach ( $m[1] as $i => $example ) {
            $page   = '<!-- wp:divi/placeholder -->' . $example . '<!-- /wp:divi/placeholder -->';
            $result = ( new Validator() )->validateContent( $page );
            $this->assertTrue( $result->isValid(), "$topic example $i: " . json_encode( array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), $result->violations() ) ) );
            $this->assertTrue( LayoutGate::check( $page )->isValid(), "$topic example $i passes the plugin gate" );
            $this->assertStringNotContainsString( '{{aied:', $example );
            preg_match_all( '/<!-- wp:divi\/[a-z-]+ (\{.*?\}) \/?-->/', $example, $blocks );
            foreach ( $blocks[1] as $json ) {
                $attrs = json_decode( $json, true );
                $this->assertIsArray( $attrs );
                foreach ( ShapePaths::fromBlockAttrs( $attrs ) as $path ) {
                    $this->assertTrue( $registry->covers( $path ), "$topic example $i uses an unregistered path $path" );
                }
            }
        }
    }
}
