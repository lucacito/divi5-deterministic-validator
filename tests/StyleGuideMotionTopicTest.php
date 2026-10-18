<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
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
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'CustomFields', 'StyleReferences', 'LayoutDiff' ] as $motionTestClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $motionTestClass . '.php';
}
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';

/**
 * The `motion` topic (transitions, hover, entrance animation, scroll effects, sticky): every list it teaches is compared, as an exact set,
 * with what the probe rendered on the real Divi 5.15.1 (fixtures/shapes/candidates/motion.json + probe-results-motion.json).
 */
class StyleGuideMotionTopicTest extends TestCase
{
    private const TOPIC_BUDGET = 10000;

    // ---- the evidence ----

    /** @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,mixed>>} candidates and probe results, both keyed by id */
    private function evidence(): array
    {
        $dir = __DIR__ . '/../fixtures/shapes/';
        $this->assertFileExists( $dir . 'candidates/motion.json', 'the candidates were mined' );
        $this->assertFileExists( $dir . 'probe-results-motion.json', 'the candidates were probed on the real Divi' );
        $candidates = json_decode( (string) file_get_contents( $dir . 'candidates/motion.json' ), true );
        $results    = json_decode( (string) file_get_contents( $dir . 'probe-results-motion.json' ), true );
        $this->assertIsArray( $candidates );
        $this->assertIsArray( $results );
        $c = [];
        foreach ( $candidates as $cand ) {
            $c[ $cand['id'] ] = $cand;
        }
        $r = [];
        foreach ( $results as $res ) {
            $r[ $res['id'] ] = $res;
        }
        $this->assertSame( array_keys( $c ), array_keys( $r ), 'every candidate has exactly one probe result, in order' );

        return [ $c, $r ];
    }

    /** A PASS: it changed the page by a measured computed-style or attribute difference, did not overflow a phone and did not error. */
    private static function passed( array $r ): bool
    {
        return ! empty( $r['effect'] ) && empty( $r['phoneOverflow'] ) && empty( $r['error'] ) && empty( $r['cssOnly'] );
    }

    /** @return list<string> ids of the passed candidates whose id starts with $prefix */
    private function passedIds( string $prefix ): array
    {
        [ , $results ] = $this->evidence();
        $ids = [];
        foreach ( $results as $id => $r ) {
            if ( str_starts_with( $id, $prefix ) && self::passed( $r ) ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private static function normalise( string $path ): string
    {
        return implode( '.', array_map( static fn ( string $s ): string => in_array( $s, ShapePaths::BREAKPOINTS, true ) ? '{bp}' : $s, explode( '.', $path ) ) );
    }

    /** @return list<string> the normalised `set` paths of the passed candidates whose id starts with one of the prefixes, sorted and unique */
    private function provenPaths( array $idPrefixes ): array
    {
        [ $cands ] = $this->evidence();
        $paths = [];
        foreach ( $idPrefixes as $prefix ) {
            foreach ( $this->passedIds( $prefix ) as $id ) {
                foreach ( array_keys( $cands[ $id ]['set'] ) as $p ) {
                    $paths[ self::normalise( (string) $p ) ] = true;
                }
            }
        }
        $out = array_keys( $paths );
        sort( $out );

        return $out;
    }

    /** @return list<string> the values the single-path passed candidates with the given id prefix set (`$leaf` = the last segments of the path), sorted and unique */
    private function provenValues( string $idPrefix, string $leaf ): array
    {
        [ $cands ] = $this->evidence();
        $values = [];
        foreach ( $this->passedIds( $idPrefix ) as $id ) {
            foreach ( $cands[ $id ]['set'] as $p => $v ) {
                if ( str_ends_with( (string) $p, '.' . $leaf ) && 1 === count( $cands[ $id ]['set'] ) ) {
                    $values[ (string) $v ] = true;
                }
            }
        }
        $out = array_keys( $values );
        sort( $out );

        return $out;
    }

    // ---- the topic text ----

    private function topic( string $name = 'motion' ): string
    {
        return StyleGuide::markdown( $name );
    }

    /** @return string one `## ` section (heading line included) of a topic */
    private function section( string $heading, string $topic = 'motion' ): string
    {
        $text  = $this->topic( $topic );
        $start = strpos( $text, "\n## " . $heading );
        $this->assertNotFalse( $start, '## ' . $heading . ' exists' );
        $end = strpos( $text, "\n## ", $start + 1 );

        return substr( $text, $start, false === $end ? null : $end - $start );
    }

    /**
     * The settings a section lists, as full normalised paths. A bullet "- `x`" names a path; one that contains `{bp}` is already a
     * full path, otherwise it is relative to the section's prefix: the first backticked string in the section that ends with a dot.
     *
     * @return list<string> sorted, unique
     */
    private function taughtPaths( string $section ): array
    {
        $prefix = '';
        if ( 1 === preg_match( '/`([^`]+\.)`/', $section, $m ) ) {
            $prefix = $m[1];
        }
        preg_match_all( '/^- `([^`]+)`/m', $section, $bullets );
        $paths = [];
        foreach ( $bullets[1] as $token ) {
            $paths[] = str_contains( $token, '{bp}' ) ? $token : $prefix . $token;
        }
        $this->assertSame( $paths, array_values( array_unique( $paths ) ), 'no setting listed twice' );
        sort( $paths );

        return $paths;
    }

    /** @return list<string> the exact tokens of "<label> N proven: a, b, c." in a section, sorted; N must match the list length */
    private function provenLine( string $section, string $label ): array
    {
        $this->assertSame( 1, preg_match( '/' . preg_quote( $label, '/' ) . ' (\d+) proven: ([A-Za-z0-9, -]+)\./', $section, $m ), 'one exact line "' . $label . ' N proven: ..."' );
        $tokens = array_map( 'trim', explode( ',', $m[2] ) );
        $this->assertCount( (int) $m[1], $tokens, $label . ': the stated count matches the list' );
        $this->assertSame( $tokens, array_values( array_unique( $tokens ) ), $label . ': no token twice' );
        sort( $tokens );

        return $tokens;
    }

    /** @return list<string> the complete worked examples: fenced blocks that start with a Divi section */
    private function examples( string $topic = 'motion' ): array
    {
        preg_match_all( '/```\n(<!-- wp:divi\/section .*?)\n```/s', $this->topic( $topic ), $m );

        return $m[1];
    }

    // ---- tests ----

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function topics(): array
    {
        return [
            'motion'       => [ 'motion', '# Motion', 'dividers' ],
            'hover-states' => [ 'hover-states', '# Hover states', 'motion' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'topics' )]
    public function testTopicIsListedAndServedWithinBudget( string $name, string $title, string $before ): void
    {
        $topics = StyleGuide::topics();
        $this->assertContains( $name, $topics );
        $this->assertSame( $before, $topics[ array_search( $name, $topics, true ) - 1 ], 'appended after ' . $before );
        $text = $this->topic( $name );
        $this->assertStringStartsWith( $title, $text );
        $this->assertLessThanOrEqual( self::TOPIC_BUDGET, strlen( $text ) );
        $this->assertStringNotContainsString( '{{aied:', $text );
        $this->assertStringNotContainsString( 'http', $text, 'no remote host in a topic' );
        $this->assertDoesNotMatchRegularExpression( '/freeForm|mainElement|"css"|<script|@keyframes|\bvideo\b/i', $text, 'no custom CSS, JS or video is ever taught' );
        $flat = preg_replace( '/\s+/', ' ', $text ) ?? '';
        $this->assertStringContainsString( 'rendered on the real Divi 5.15.1', $flat );
        $this->assertStringContainsString( 'not by watching the motion', $flat, 'it says how motion was proven' );
        $this->assertStringContainsString( '(source)', $text, 'source-only rules are labelled' );
        $this->assertSame( $text, $this->mcp( [ 'topic' => $name ] ), 'MCP serves the same text' );
        $rest = ( new RestController() )->style_guide( new \WP_REST_Request( [], [ 'topic' => $name ] ) );
        $this->assertSame( [ 'guide' => $text ], $rest->get_data(), 'REST serves the same text' );
    }

    private function mcp( array $args ): string
    {
        $req  = new \WP_REST_Request( [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [ 'name' => 'get_style_guide', 'arguments' => $args ],
        ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();

        return (string) ( $data['result']['content'][0]['text'] ?? '' );
    }

    public function testEverySettingTaughtIsAProvenPathAndNoProvenPathIsLeftOut(): void
    {
        $registry = ShapeRegistry::load();
        $groups   = [
            'Transition'         => [ 'hover-states', [ 'mo-tr-' ] ],
            'Hover and transform' => [ 'hover-states', [ 'mo-hv-', 'mo-tf-' ] ],
            'Entrance animation' => [ 'motion', [ 'mo-an-' ] ],
            'Scroll effects'     => [ 'motion', [ 'mo-sc-' ] ],
            'Sticky'             => [ 'motion', [ 'mo-st-' ] ],
        ];
        foreach ( $groups as $heading => [ $topic, $prefixes ] ) {
            $proven = $this->provenPaths( $prefixes );
            $this->assertNotSame( [], $proven, $heading . ' has proven paths' );
            $this->assertSame( $proven, $this->taughtPaths( $this->section( $heading, $topic ) ), $heading . ': taught settings == probe-proven paths (exact set)' );
            foreach ( $proven as $path ) {
                $this->assertTrue( $registry->covers( $path ), $path . ' is in the shape registry' );
            }
        }
    }

    public function testEveryEnumeratedValueListIsTheMeasuredSet(): void
    {
        $anim = $this->section( 'Entrance animation' );
        $this->assertSame( $this->provenValues( 'mo-an-style-', 'style' ), $this->provenLine( $anim, 'Styles:' ), 'entrance styles' );
        $this->assertSame( $this->provenValues( 'mo-an-curve-', 'speedCurve' ), $this->provenLine( $anim, 'Speed curves:' ), 'entrance speed curves' );

        // Directions: the styles that took a direction, and the directions that rendered (center is proven against a left base).
        [ $cands ] = $this->evidence();
        $styles = [];
        $dirs   = [];
        foreach ( $this->passedIds( 'mo-an-dir-' ) as $id ) {
            $this->assertSame( 1, preg_match( '/^mo-an-dir-([a-z]+)-([a-z]+)/', $id, $m ), $id );
            $styles[ $m[1] ] = true;
            $dirs[ (string) reset( $cands[ $id ]['set'] ) ] = true;
        }
        $styleList = array_keys( $styles );
        $dirList   = array_keys( $dirs );
        sort( $styleList );
        sort( $dirList );
        $this->assertSame( $styleList, $this->provenLine( $anim, 'Styles that take a direction:' ), 'styles with a direction' );
        $this->assertSame( $dirList, $this->provenLine( $anim, 'Directions:' ), 'directions' );

        $this->assertSame( $this->provenValues( 'mo-tr-curve-', 'speedCurve' ), $this->provenLine( $this->section( 'Transition', 'hover-states' ), 'Speed curves:' ), 'transition speed curves' );
        $this->assertSame( $this->provenValues( 'mo-st-', 'position' ), $this->provenLine( $this->section( 'Sticky' ), 'Positions:' ), 'sticky positions' );

        $effects = [];
        foreach ( $this->passedIds( 'mo-sc-' ) as $id ) {
            if ( 1 === preg_match( '/^mo-sc-([a-zA-Z]+)$/', $id, $m ) && in_array( $m[1], [ 'verticalMotion', 'horizontalMotion', 'fade', 'scaling', 'rotating', 'blur' ], true ) ) {
                $effects[] = $m[1];
            }
        }
        sort( $effects );
        $this->assertSame( $effects, $this->provenLine( $this->section( 'Scroll effects' ), 'Effects:' ), 'scroll effects' );
    }

    /** @return array<string,array{0:string,1:int,2:list<string>}> */
    public static function exampleTopics(): array
    {
        return [
            'motion'       => [ 'motion', 2, [ 'animation', 'sticky', 'scroll' ] ],
            'hover-states' => [ 'hover-states', 1, [ 'hover', 'transition' ] ],
        ];
    }

    /**
     * @param list<string> $kinds every kind of setting that the examples of the topic must together use
     */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'exampleTopics' )]
    public function testWorkedExamplesValidateAndUseOnlyProvenShapes( string $topic, int $minimum, array $kinds ): void
    {
        $examples = $this->examples( $topic );
        $this->assertGreaterThanOrEqual( $minimum, count( $examples ) );
        $registry = ShapeRegistry::load();
        $used     = [];
        foreach ( $examples as $i => $example ) {
            $page   = '<!-- wp:divi/placeholder -->' . $example . '<!-- /wp:divi/placeholder -->';
            $result = ( new Validator() )->validateContent( $page );
            $this->assertTrue( $result->isValid(), "example $i: " . json_encode( array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), $result->violations() ) ) );
            $this->assertTrue( LayoutGate::check( $page )->isValid(), "example $i passes the plugin gate" );
            $this->assertStringNotContainsString( 'http', $example );
            $this->assertStringNotContainsString( '{{aied:', $example );
            preg_match_all( '/<!-- wp:divi\/[a-z-]+ (\{.*?\}) \/?-->/', $example, $blocks );
            foreach ( $blocks[1] as $json ) {
                $attrs = json_decode( $json, true );
                $this->assertIsArray( $attrs );
                foreach ( ShapePaths::fromBlockAttrs( $attrs ) as $path ) {
                    $this->assertTrue( $registry->covers( $path ), "example $i uses an unregistered path $path" );
                }
            }
            foreach ( $kinds as $kind ) {
                if ( str_contains( $example, '"' . $kind . '":' ) ) {
                    $used[ $kind ] = true;
                }
            }
        }
        $this->assertSame( $this->sortedCopy( $kinds ), $this->sortedCopy( array_keys( $used ) ), 'the examples together use exactly the kinds ' . implode( ', ', $kinds ) );
    }

    /** @param list<string> $a @return list<string> */
    private function sortedCopy( array $a ): array
    {
        sort( $a );

        return $a;
    }
    // ---- claims about measurements ----

    private function diffs( string $id ): array
    {
        [ , $results ] = $this->evidence();
        $d = $results[ $id ]['detail'];

        return [ 1280 => (int) $d['1280']['computedDiffs'], 900 => (int) $d['900']['computedDiffs'], 400 => (int) $d['400']['computedDiffs'] ];
    }

    /** @return list<string> every property name that changed at any width */
    private function changedProps( string $id ): array
    {
        [ , $results ] = $this->evidence();
        $names = [];
        foreach ( [ '1280', '900', '400' ] as $w ) {
            foreach ( (array) ( $results[ $id ]['detail'][ $w ]['changedProps'] ?? [] ) as $n ) {
                $names[ (string) $n ] = true;
            }
        }

        return array_keys( $names );
    }

    public function testPreconditionsAndDeadShapesAreExactlyWhatTheTopicsSay(): void
    {
        [ , $results ] = $this->evidence();
        $dead = [ 'mo-tr-needs-a-state', 'mo-an-dir-fade-needs-no-direction', 'mo-an-intensity-fade-fixed', 'mo-an-phone-style-none', 'mo-sc-offset-needs-enable', 'mo-st-offset-needs-position', 'mo-hv-button-shadow', 'mo-hv-button-scale' ];
        $actual = [];
        foreach ( $results as $id => $r ) {
            if ( empty( $r['effect'] ) ) {
                $actual[] = $id;
            }
        }
        sort( $dead );
        sort( $actual );
        $this->assertSame( $dead, $actual, 'the DEAD candidates are exactly the ones the topics call dead' );
        // A hover colour needs no normal colour: both the pair and the lone hover value rendered.
        $this->assertTrue( self::passed( $results['mo-hv-group-bg-color-without-normal'] ) );
        $this->assertTrue( self::passed( $results['mo-hv-group-bg-color'] ) );
        $motion = preg_replace( '/\s+/', ' ', $this->topic( 'motion' ) ) ?? '';
        $hover  = preg_replace( '/\s+/', ' ', $this->topic( 'hover-states' ) ) ?? '';
        $this->assertStringContainsString( '`direction` on fade did nothing (rendered: dead)', $motion );
        $this->assertStringContainsString( 'A phone `style` of "none" did not switch the animation off at 400 px (rendered: dead)', $motion );
        $this->assertStringContainsString( 'an `offset` without its effect switched on did nothing (rendered: dead)', $motion );
        $this->assertStringContainsString( 'An offset without a `position` did nothing (rendered: dead)', $motion );
        $this->assertStringContainsString( 'rendered: a duration on a group with no hover value did nothing', $hover );
        $this->assertStringContainsString( '`button.decoration.boxShadow` and `button.decoration.transform` did nothing (rendered: dead)', $hover );
        $this->assertStringContainsString( 'A hover colour worked without a normal colour set', $hover );
    }

    public function testResponsiveClaimsAreMeasuredAtTheWidthsTheyNameAndNoWider(): void
    {
        // transition tablet duration: computed differences at 900 and 400, none at 1280.
        $d = $this->diffs( 'mo-tr-duration-tablet' );
        $this->assertSame( 0, $d[1280] );
        $this->assertGreaterThan( 0, $d[900] );
        $this->assertGreaterThan( 0, $d[400] );
        // entrance animation tablet duration: 900 only (the phone kept the desktop value).
        $d = $this->diffs( 'mo-an-tablet-duration' );
        $this->assertSame( [ 0, true, 0 ], [ $d[1280], $d[900] > 0, $d[400] ] );
        // scroll and sticky phone overrides: 400 only.
        foreach ( [ 'mo-sc-phone-off', 'mo-st-phone-none' ] as $id ) {
            $d = $this->diffs( $id );
            $this->assertSame( [ 0, 0, true ], [ $d[1280], $d[900], $d[400] > 0 ], $id );
        }
        // the viewport top change showed at 900 only.
        $d = $this->diffs( 'mo-sc-vertical-viewport-top' );
        $this->assertSame( [ 0, true, 0 ], [ $d[1280], $d[900] > 0, $d[400] ] );
        $motion = preg_replace( '/\s+/', ' ', $this->topic( 'motion' ) ) ?? '';
        $hover  = preg_replace( '/\s+/', ' ', $this->topic( 'hover-states' ) ) ?? '';
        $this->assertStringContainsString( 'changed the page at 900 px only; the phone width kept the desktop value', $motion );
        $this->assertStringContainsString( 'removed the effect at 400 px only', $motion );
        $this->assertStringContainsString( 'it changed the render at 900 px only', $motion );
        $this->assertStringContainsString( 'turned a desktop sticky section off at 400 px only', $motion );
        $this->assertStringContainsString( 'changed the computed duration at 900 and 400 px and not at 1280', $hover );
    }

    public function testStateAndHostEnumerationsAreTheMeasuredSets(): void
    {
        [ $cands, $results ] = $this->evidence();
        // Shadow presets: every preset name any passed hover box-shadow candidate set.
        $presets = [];
        foreach ( $this->passedIds( 'mo-hv-' ) as $id ) {
            foreach ( $cands[ $id ]['set'] as $path => $value ) {
                if ( 1 === preg_match( '/boxShadow\.[a-z]+\.hover\.style$/', (string) $path ) ) {
                    $presets[ (string) $value ] = true;
                }
            }
        }
        $presets = array_keys( $presets );
        sort( $presets );
        $this->assertSame( $presets, $this->provenLine( $this->section( 'Hover and transform', 'hover-states' ), 'Shadow presets:' ), 'shadow presets' );

        // Hosts of an entrance animation: the heading (all the settings) plus every host candidate that passed.
        $hosts = [ 'heading' ];
        foreach ( $this->passedIds( 'mo-an-host-' ) as $id ) {
            if ( 1 === preg_match( '/^mo-an-host-([a-z]+)$/', $id, $m ) ) {
                $hosts[] = $m[1];
            }
        }
        sort( $hosts );
        $this->assertSame( $hosts, $this->provenLine( $this->section( 'Entrance animation' ), 'Hosts:' ), 'animation hosts' );

        // Every hover candidate was measured with the pointer on the element; scroll and sticky candidates were measured scrolled.
        foreach ( $cands as $id => $c ) {
            $expected = str_starts_with( $id, 'mo-hv-' ) ? 'hover' : ( 1 === preg_match( '/^mo-(sc|st)-/', $id ) ? 'scroll' : null );
            $this->assertSame( $expected, $c['state'] ?? null, $id . ' state' );
            if ( 'scroll' === $expected ) {
                $this->assertSame( 'section', $c['block'], $id . ' is a section (the topic says so)' );
            }
        }
        $this->assertNotEmpty( $results );
    }

    public function testEachEffectChangedTheComputedPropertyTheTopicNames(): void
    {
        $expect = [
            'mo-sc-verticalMotion'   => [ 'transform', 'rect:top' ],
            'mo-sc-horizontalMotion' => [ 'transform', 'rect:left' ],
            'mo-sc-fade'             => [ 'opacity' ],
            'mo-sc-scaling'          => [ 'transform' ],
            'mo-sc-rotating'         => [ 'transform' ],
            'mo-sc-blur'             => [ 'filter' ],
            'mo-st-top'              => [ 'position' ],
            'mo-st-bottom'           => [ 'position' ],
            'mo-st-topBottom'        => [ 'position' ],
            'mo-hv-group-bg-color'   => [ 'background-color' ],
            'mo-hv-group-border-color' => [ 'border-top-color' ],
            'mo-hv-group-shadow-preset3' => [ 'box-shadow' ],
            'mo-hv-group-scale'      => [ 'transform' ],
            'mo-hv-group-translate-y' => [ 'transform' ],
            'mo-hv-group-rotate-z'   => [ 'transform' ],
            'mo-hv-group-filter-brightness' => [ 'filter' ],
            'mo-hv-group-filter-saturate' => [ 'filter' ],
            'mo-hv-heading-font-color' => [ 'color' ],
            'mo-hv-button-bg-color'  => [ 'background-color' ],
            'mo-hv-button-font-color' => [ 'color' ],
            'mo-hv-button-border-color' => [ 'border-top-color' ],
            'mo-hv-button-module-shadow' => [ 'box-shadow' ],
            'mo-hv-button-module-scale' => [ 'transform' ],
            'mo-tr-duration-800'     => [ 'transition-duration' ],
            'mo-tr-delay-200'        => [ 'transition-delay' ],
            'mo-an-repeat-loop'      => [ 'animation-iteration-count' ],
            'mo-an-curve-linear'     => [ 'animation-timing-function' ],
            'mo-an-duration-2000'    => [ 'animation-duration' ],
            'mo-an-delay-8000'       => [ 'animation-delay' ],
            'mo-an-starting-opacity-40' => [ 'opacity' ],
            'mo-an-intensity-zoom'   => [ 'transform' ],
            'mo-an-dir-slide-left'   => [ 'animation-name' ],
        ];
        foreach ( $expect as $id => $props ) {
            $changed = $this->changedProps( $id );
            foreach ( $props as $prop ) {
                $this->assertContains( $prop, $changed, $id . ' changed ' . $prop );
            }
        }
    }
}
