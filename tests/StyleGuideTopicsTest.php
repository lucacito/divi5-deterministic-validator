<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CodePolicy;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
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
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'CustomFields', 'StyleReferences', 'LayoutDiff' ] as $topicsTestClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $topicsTestClass . '.php';
}
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';

/** On-demand style guide topics: the default text barely grows, the rest is served by topic, in MCP, REST and OpenAPI alike. */
class StyleGuideTopicsTest extends TestCase
{
    /** Bytes of StyleGuide::markdown() measured on commit c777962, before the topics existed. */
    private const ORIGINAL_LENGTH = 27908;
    private const MAX_GROWTH = 1200;

    /** The languages and the style kits each collapse to one placeholder entry. */
    private static function collapse( string $topic ): string
    {
        if ( str_starts_with( $topic, 'design-language:' ) ) {
            return 'design-language:<id>';
        }

        return str_starts_with( $topic, 'style-kit:' ) ? 'style-kit:<id>' : $topic;
    }

    /** Ruling 19: the default guide's last line names EVERY topic; the languages collapse to one placeholder. */
    private static function expectedTopicLine(): string
    {
        $names = [];
        foreach ( StyleGuide::topics() as $topic ) {
            $names[] = self::collapse( $topic );
        }

        return 'Topics: call get_style_guide with topic=<name>: ' . implode( ', ', array_values( array_unique( $names ) ) ) . '.';
    }

    /** @return array{0:?int,1:string} */
    private function mcp( array $args ): array
    {
        $req  = new \WP_REST_Request( [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [ 'name' => 'get_style_guide', 'arguments' => $args ],
        ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], (string) $data['error']['message'] ];
        }

        return [ null, (string) $data['result']['content'][0]['text'] ];
    }

    private function rest( array $params ): \WP_REST_Response
    {
        return ( new RestController() )->style_guide( new \WP_REST_Request( [], $params ) );
    }

    public function testDefaultGuideOnlyGrowsByTheTopicLine(): void
    {
        $text = StyleGuide::markdown();
        // Ruling 5: growth over the original guide is capped at MAX_GROWTH (1200) across Task 7 (topic line)
        // and Task 10 (the rewritten design paragraph with composition rules and the advisory instruction).
        $this->assertLessThanOrEqual( self::MAX_GROWTH, strlen( $text ) - self::ORIGINAL_LENGTH );
        $this->assertGreaterThan( 0, strlen( $text ) - self::ORIGINAL_LENGTH );
        $this->assertStringEndsWith( "\n\n" . self::expectedTopicLine(), $text );
        $this->assertSame( 1, substr_count( $text, 'Topics: call get_style_guide' ) );
        $this->assertSame( $text, StyleGuide::markdown( null ) );
        $this->assertSame( $text, StyleGuide::markdown( '' ), 'an empty topic means none' );
    }

    public function testTheTopicLineNamesEveryTopicAndEachNamedTopicIsServed(): void
    {
        $line = self::expectedTopicLine();
        $this->assertStringNotContainsString( "\n", $line, 'one line' );
        $this->assertStringStartsWith( 'Topics: call get_style_guide with topic=<name>: design-languages, design-language:<id>, backgrounds, ', $line );
        $text = StyleGuide::markdown();
        $this->assertSame( 1, substr_count( $text, 'design-language:<id>' ), 'the language ids are one placeholder, not ten entries' );
        $last = substr( $text, (int) strrpos( $text, "\n\n" ) + 2 );
        $this->assertSame( $line, $last, 'the final line of the default guide is exactly the generated line' );
        $named = explode( ', ', substr( $line, strlen( 'Topics: call get_style_guide with topic=<name>: ' ), -1 ) );
        foreach ( StyleGuide::topics() as $topic ) {
            $key = self::collapse( $topic );
            $this->assertContains( $key, $named, $topic . ' is announced in the default guide' );
            $reply = StyleGuide::markdown( $topic );
            $this->assertNotSame( '', trim( $reply ) );
            $this->assertStringNotContainsString( 'Unknown topic', $reply, $topic . ' is served' );
        }
        foreach ( $named as $key ) {
            if ( in_array( $key, [ 'design-language:<id>', 'style-kit:<id>' ], true ) ) {
                continue;
            }
            $this->assertContains( $key, StyleGuide::topics(), $key . ' named in the line is a real topic' );
        }
        $this->assertContains( 'typography', $named );
        $this->assertContains( 'imagery', $named );
        $this->assertContains( 'controls', $named );
    }

    /** Measured on Divi 5.15.1 (docs/shape-evidence-5.15.1.md, ty-h-style-uppercase, ty-b-font-style-uppercase): a font `style` list with "uppercase" prints nothing; `capitalization` does the work. */
    public function testTheDefaultGuideTeachesCapitalizationNotTheDeadStyleUppercase(): void
    {
        $text = StyleGuide::markdown();
        $this->assertStringNotContainsString( '["uppercase"]', $text, 'the dead shape is not taught' );
        $this->assertStringContainsString( 'title.decoration.font.font.{bp}.value.capitalization = "uppercase"', $text );
        $this->assertStringContainsString( '…font.{bp}.value.style          = ["italic"]', $text );
        $this->assertLessThanOrEqual( 29108, strlen( $text ), 'Ruling 5 ceiling' );
    }

    public function testTopicsListIsTheIndexPlusEveryLanguageAndTheFamilyTopics(): void
    {
        $topics = StyleGuide::topics();
        $this->assertSame( 'design-languages', $topics[0] );
        foreach ( DesignLanguages::ids() as $id ) {
            $this->assertContains( 'design-language:' . $id, $topics );
        }
        $this->assertContains( 'backgrounds', $topics );
        $this->assertContains( 'dividers', $topics );
        $this->assertContains( 'motion', $topics );
        $this->assertContains( 'hover-states', $topics );
        $this->assertContains( 'layout-advanced', $topics );
        $this->assertContains( 'layout-sizing', $topics );
        $this->assertContains( 'typography', $topics );
        $this->assertContains( 'imagery', $topics );
        $this->assertContains( 'controls', $topics );
        $this->assertSame( $topics, array_values( array_unique( $topics ) ), 'no topic is listed twice' );
        foreach ( $topics as $topic ) {
            $this->assertStringNotContainsString( 'Unknown topic', StyleGuide::markdown( $topic ), $topic . ' must be served' );
        }
    }

    public function testDesignLanguagesTopicListsAllIds(): void
    {
        $text = StyleGuide::markdown( 'design-languages' );
        $this->assertSame( DesignLanguages::markdown(), $text );
        foreach ( DesignLanguages::ids() as $id ) {
            $this->assertStringContainsString( $id, $text );
        }
    }

    public function testLanguageTopicReturnsTheBrief(): void
    {
        foreach ( DesignLanguages::ids() as $id ) {
            $this->assertSame( DesignLanguages::markdown( $id ), StyleGuide::markdown( 'design-language:' . $id ) );
        }
    }

    public function testUnknownTopicListsTopics(): void
    {
        foreach ( [ 'nope', 'design-language:nope', 'design-language:' ] as $bad ) {
            $text = StyleGuide::markdown( $bad );
            $this->assertStringContainsString( 'Unknown topic', $text );
            foreach ( StyleGuide::topics() as $topic ) {
                $this->assertStringContainsString( $topic, $text );
            }
            $this->assertStringNotContainsString( 'Divi 5 Layout Authoring Guide', $text );
        }
    }

    // ---- transports ----

    public function testMcpWithoutTopicIsTheDefaultGuide(): void
    {
        [ $code, $text ] = $this->mcp( [] );
        $this->assertNull( $code );
        $this->assertSame( StyleGuide::markdown(), $text );
        [ , $nulls ] = $this->mcp( [ 'topic' => null ] );
        $this->assertSame( StyleGuide::markdown(), $nulls );
    }

    public function testAnEmptyStringTopicMeansNoTopicOnBothTransports(): void
    {
        [ $code, $text ] = $this->mcp( [ 'topic' => '' ] );
        $this->assertNull( $code );
        $this->assertSame( StyleGuide::markdown(), $text );
        $r = $this->rest( [ 'topic' => '' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( [ 'guide' => StyleGuide::markdown() ], $r->get_data() );
    }

    public function testMcpTopicIsServed(): void
    {
        $id = DesignLanguages::ids()[0];
        [ $code, $text ] = $this->mcp( [ 'topic' => 'design-language:' . $id ] );
        $this->assertNull( $code );
        $this->assertSame( DesignLanguages::markdown( $id ), $text );
        [ $code2, $unknown ] = $this->mcp( [ 'topic' => 'nope' ] );
        $this->assertNull( $code2, 'an unknown topic is a normal answer' );
        $this->assertStringContainsString( 'Unknown topic', $unknown );
    }

    public static function nonStringTopics(): array
    {
        return [
            'array'  => [ [ 'topic' => [] ] ],
            'list'   => [ [ 'topic' => [ 'x' ] ] ],
            'object' => [ [ 'topic' => [ 'a' => 'b' ] ] ],
            'number' => [ [ 'topic' => 5 ] ],
            'bool'   => [ [ 'topic' => true ] ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'nonStringTopics' )]
    public function testMcpRejectsNonStringTopic( array $args ): void
    {
        [ $code, $text ] = $this->mcp( $args );
        $this->assertSame( -32602, $code );
        $this->assertStringContainsString( 'must be a string', $text );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'nonStringTopics' )]
    public function testRestRejectsNonStringTopic( array $params ): void
    {
        $r = $this->rest( $params );
        $this->assertSame( 400, $r->get_status() );
        $this->assertStringContainsString( 'must be a string', (string) $r->get_data()['error'] );
    }

    public function testRestWithoutTopicKeepsTheOldShape(): void
    {
        $r = $this->rest( [] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( [ 'guide' => StyleGuide::markdown() ], $r->get_data() );
    }

    public function testRestTopicIsServedAsGuide(): void
    {
        $r = $this->rest( [ 'topic' => 'design-languages' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( [ 'guide' => DesignLanguages::markdown() ], $r->get_data() );
        $u = $this->rest( [ 'topic' => 'nope' ] );
        $this->assertSame( 200, $u->get_status() );
        $this->assertStringContainsString( 'Unknown topic', $u->get_data()['guide'] );
    }

    public function testOpenApiListsTheTopicParameter(): void
    {
        $spec   = OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $get    = $spec['paths']['/style-guide']['get'];
        $params = $get['parameters'];
        $this->assertCount( 1, $params );
        $this->assertSame( 'topic', $params[0]['name'] );
        $this->assertSame( 'query', $params[0]['in'] );
        $this->assertFalse( $params[0]['required'] );
        $this->assertSame( 'string', $params[0]['schema']['type'] );
        $this->assertStringContainsString( 'design-languages', $params[0]['schema']['description'] );
        $this->assertLessThanOrEqual( 300, strlen( (string) $get['description'] ) );
    }

    /** The ChatGPT importer may cap parameter descriptions too: every parameter description of the spec stays within 300 characters. */
    public function testEveryOpenApiParameterDescriptionIsWithin300Characters(): void
    {
        $spec = OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $seen = 0;
        foreach ( $spec['paths'] as $path => $methods ) {
            foreach ( $methods as $method => $op ) {
                foreach ( (array) ( $op['parameters'] ?? [] ) as $param ) {
                    $desc = (string) ( $param['description'] ?? '' ) . (string) ( $param['schema']['description'] ?? '' );
                    $this->assertLessThanOrEqual( 300, strlen( (string) ( $param['schema']['description'] ?? '' ) ), "$method $path ({$param['name']}) schema description" );
                    $this->assertLessThanOrEqual( 300, strlen( (string) ( $param['description'] ?? '' ) ), "$method $path ({$param['name']}) description" );
                    $seen += '' === $desc ? 0 : 1;
                }
            }
        }
        $this->assertGreaterThan( 5, $seen, 'the spec has parameter descriptions to check' );
        $topic = $spec['paths']['/style-guide']['get']['parameters'][0]['schema']['description'];
        $this->assertSame( StyleGuide::topicParameterDescription(), $topic, 'the generated description is the one served' );
        foreach ( StyleGuide::topics() as $name ) {
            $key = self::collapse( $name );
            $this->assertStringContainsString( $key, $topic, $name . ' is named in the parameter description' );
        }
    }

    public function testMcpSchemaDeclaresTheStringTopic(): void
    {
        $req   = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ] );
        $tools = (array) ( new McpHandler() )->handle( $req )->get_data()['result']['tools'];
        $tool  = null;
        foreach ( $tools as $t ) {
            if ( 'get_style_guide' === $t['name'] ) {
                $tool = $t;
            }
        }
        $this->assertNotNull( $tool );
        $this->assertSame( 'string', $tool['inputSchema']['properties']['topic']['type'] );
        $this->assertSame( StyleGuide::topicParameterDescription(), $tool['inputSchema']['properties']['topic']['description'] );
        $this->assertLessThanOrEqual( 300, strlen( $tool['inputSchema']['properties']['topic']['description'] ) );
        $this->assertSame( [], $tool['inputSchema']['required'] );
    }

    public function testNoNewToolOrOperationWasAdded(): void
    {
        $spec = OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $this->assertCount( 1, array_filter( array_keys( $spec['paths'] ), static fn ( string $p ): bool => '/style-guide' === $p ) );
    }

    // ---- the backgrounds topic ----

    private const TOPIC_BUDGET = 10000;

    /** @return list<string> the complete worked examples of a topic: fenced blocks that start with a Divi section */
    private function examples( string $topic ): array
    {
        preg_match_all( '/```\n(<!-- wp:divi\/section .*?)\n```/s', StyleGuide::markdown( $topic ), $m );

        return $m[1];
    }

    public function testBackgroundsTopicIsServedWithinBudget(): void
    {
        $text = StyleGuide::markdown( 'backgrounds' );
        $this->assertStringStartsWith( '# Backgrounds', $text );
        $this->assertLessThanOrEqual( self::TOPIC_BUDGET, strlen( $text ) );
        $this->assertStringNotContainsString( '{{aied:', $text, 'image tokens are described in words, never written out' );
        $this->assertStringNotContainsString( 'http', $text, 'no remote host in a topic' );
        $this->assertDoesNotMatchRegularExpression( '/freeForm|mainElement|"css"/', $text, 'no custom CSS is ever taught' );
        $this->assertSame( $text, ( $this->mcp( [ 'topic' => 'backgrounds' ] ) )[1], 'MCP serves the same text' );
        $this->assertSame( [ 'guide' => $text ], $this->rest( [ 'topic' => 'backgrounds' ] )->get_data(), 'REST serves the same text' );
    }

    /** @return array{pattern:list<string>,mask:list<string>} the styles whose candidate passed the probe (rendered, no phone overflow, no error), exact names, sorted */
    private function provenStyles(): array
    {
        $dir        = __DIR__ . '/../fixtures/shapes/';
        $candidates = json_decode( (string) file_get_contents( $dir . 'candidates/backgrounds.json' ), true );
        $results    = json_decode( (string) file_get_contents( $dir . 'probe-results-backgrounds.json' ), true );
        $this->assertIsArray( $candidates );
        $this->assertIsArray( $results );
        $passed = [];
        foreach ( $results as $r ) {
            if ( ! empty( $r['effect'] ) && empty( $r['phoneOverflow'] ) && empty( $r['error'] ) ) {
                $passed[ $r['id'] ] = true;
            }
        }
        $styles = [ 'pattern' => [], 'mask' => [] ];
        foreach ( $candidates as $c ) {
            if ( ! isset( $passed[ $c['id'] ] ) ) {
                continue;
            }
            foreach ( $c['set'] as $path => $value ) {
                if ( preg_match( '/\.(pattern|mask)\.style$/', (string) $path, $m ) ) {
                    $styles[ $m[1] ][ (string) $value ] = true;
                }
            }
        }
        $out = [];
        foreach ( $styles as $kind => $set ) {
            $names = array_keys( $set );
            sort( $names );
            $out[ $kind ] = $names;
        }

        return $out;
    }

    /** @return array{pattern:list<string>,mask:list<string>} the topic's own `style` lists as exact tokens, sorted */
    private function taughtStyles( string $text ): array
    {
        $out = [];
        foreach ( [ 'pattern' => '## Pattern', 'mask' => '## Mask' ] as $kind => $heading ) {
            $start = strpos( $text, "\n" . $heading );
            $this->assertNotFalse( $start, $heading . ' section exists' );
            $end     = strpos( $text, "\n## ", $start + 1 );
            $section = substr( $text, $start, false === $end ? null : $end - $start );
            $this->assertSame( 1, preg_match( '/^- `style`, (\d+) proven: ([a-z0-9, -]+)\.$/m', $section, $m ), $heading . ' has one exact style line' );
            $names = array_map( 'trim', explode( ',', $m[2] ) );
            $this->assertCount( (int) $m[1], $names, $heading . ': the stated count matches the list' );
            $this->assertSame( $names, array_values( array_unique( $names ) ), $heading . ': no name twice' );
            sort( $names );
            $out[ $kind ] = $names;
        }

        return $out;
    }

    public function testBackgroundsTopicNamesEveryProvenStyleAndNoOther(): void
    {
        $proven = $this->provenStyles();
        $this->assertGreaterThanOrEqual( 4, count( $proven['pattern'] ), 'at least 4 pattern styles' );
        $this->assertGreaterThanOrEqual( 4, count( $proven['mask'] ), 'at least 4 mask styles' );
        $taught = $this->taughtStyles( StyleGuide::markdown( 'backgrounds' ) );
        // Exact set equality: a style deleted from the topic, or a name nobody proved, fails.
        $this->assertSame( $proven['pattern'], $taught['pattern'], 'pattern styles: taught == proven' );
        $this->assertSame( $proven['mask'], $taught['mask'], 'mask styles: taught == proven' );
    }

    public function testBackgroundsWorkedExamplesValidate(): void
    {
        $examples = $this->examples( 'backgrounds' );
        $this->assertGreaterThanOrEqual( 4, count( $examples ), 'one example per shape group: gradient, image, pattern, mask' );
        $registry = ShapeRegistry::load();
        $kinds    = [];
        foreach ( $examples as $i => $example ) {
            $page   = '<!-- wp:divi/placeholder -->' . $example . '<!-- /wp:divi/placeholder -->';
            $result = ( new Validator() )->validateContent( $page );
            $this->assertTrue( $result->isValid(), "example $i: " . json_encode( array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), $result->violations() ) ) );
            $this->assertTrue( LayoutGate::check( $page )->isValid(), "example $i passes the plugin gate (custom-code policy included)" );
            // Every attribute path the example uses is proven (export or probe), nothing invented.
            preg_match_all( '/<!-- wp:divi\/[a-z-]+ (\{.*?\}) \/?-->/', $example, $blocks );
            foreach ( $blocks[1] as $json ) {
                $attrs = json_decode( $json, true );
                $this->assertIsArray( $attrs, 'example ' . $i . ' block attributes decode' );
                foreach ( ShapePaths::fromBlockAttrs( $attrs ) as $path ) {
                    $this->assertTrue( $registry->covers( $path ), "example $i uses an unregistered path $path" );
                }
            }
            foreach ( [ 'gradient', 'image', 'pattern', 'mask' ] as $kind ) {
                if ( str_contains( $example, '"' . $kind . '":{' ) ) {
                    $kinds[ $kind ] = true;
                }
            }
        }
        $this->assertSame( [ 'gradient', 'image', 'mask', 'pattern' ], $this->sorted( array_keys( $kinds ) ) );
    }

    // ---- the dividers topic ----

    /** @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,mixed>>} candidates and probe results, both keyed by id */
    private function dividerEvidence(): array
    {
        $dir        = __DIR__ . '/../fixtures/shapes/';
        $candidates = json_decode( (string) file_get_contents( $dir . 'candidates/dividers.json' ), true );
        $results    = json_decode( (string) file_get_contents( $dir . 'probe-results-dividers.json' ), true );
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
        $this->assertSame( array_keys( $c ), array_keys( $r ), 'every candidate has exactly one probe result' );

        return [ $c, $r ];
    }

    private static function dividerPassed( array $r ): bool
    {
        return ! empty( $r['effect'] ) && empty( $r['phoneOverflow'] ) && empty( $r['error'] );
    }

    /** @return list<string> the divider styles that rendered as BOTH a top and a bottom divider (candidate set value of `.dividers.<placement>.desktop.value.style`), sorted */
    private function provenDividerStyles(): array
    {
        [ $cands, $results ] = $this->dividerEvidence();
        $by = [ 'top' => [], 'bottom' => [] ];
        foreach ( $cands as $id => $c ) {
            if ( ! self::dividerPassed( $results[ $id ] ) ) {
                continue;
            }
            foreach ( $c['set'] as $path => $value ) {
                if ( 1 === preg_match( '/\.dividers\.(top|bottom)\.desktop\.value\.style$/', (string) $path, $m ) && 'none' !== $value && 1 === count( $c['set'] ) ) {
                    $by[ $m[1] ][ (string) $value ] = true;
                }
            }
        }
        $both = array_keys( array_intersect_key( $by['top'], $by['bottom'] ) );
        sort( $both );

        return $both;
    }

    /** @return list<string> the styles whose `repeat` candidate rendered nothing (the base style of every DEAD candidate that sets `.repeat`), sorted */
    private function deadRepeatStyles(): array
    {
        [ $cands, $results ] = $this->dividerEvidence();
        $dead = [];
        foreach ( $cands as $id => $c ) {
            $paths = array_keys( $c['set'] );
            if ( 1 === count( $paths ) && str_ends_with( (string) $paths[0], '.repeat' ) && empty( $results[ $id ]['effect'] ) ) {
                $dead[] = $c['base']['module']['advanced']['dividers']['bottom']['desktop']['value']['style'];
            }
        }
        sort( $dead );

        return $dead;
    }

    /** @return array{styles:list<string>,count:int,repeat:list<string>,repeatCount:int} the topic's own lists as exact tokens, sorted */
    private function taughtDividerLists( string $text ): array
    {
        $this->assertSame( 1, preg_match( '/^- `style`: .*?(\d+) proven \(each rendered as a top divider and as a bottom divider\): ([a-z0-9, ]+)\.$/m', $text, $m ), 'one exact style line' );
        $styles = array_map( 'trim', explode( ',', $m[2] ) );
        $this->assertSame( $styles, array_values( array_unique( $styles ) ), 'no style named twice' );
        $count = (int) $m[1];
        $this->assertSame( 1, preg_match( '/`repeat` has no effect on (\d+) styles: ([a-z0-9, ]+)\s*\(/', $text, $n ), 'one exact repeat-exception line' );
        $repeat = array_map( 'trim', explode( ',', $n[2] ) );
        sort( $styles );
        sort( $repeat );

        return [ 'styles' => $styles, 'count' => $count, 'repeat' => $repeat, 'repeatCount' => (int) $n[1] ];
    }

    public function testDividersTopicIsServedWithinBudget(): void
    {
        $text = StyleGuide::markdown( 'dividers' );
        $this->assertStringStartsWith( '# Section dividers', $text );
        $this->assertLessThanOrEqual( self::TOPIC_BUDGET, strlen( $text ) );
        $this->assertStringNotContainsString( '{{aied:', $text );
        $this->assertStringNotContainsString( 'http', $text, 'no remote host in a topic' );
        $this->assertDoesNotMatchRegularExpression( '/freeForm|mainElement|"css"/', $text, 'no custom CSS is ever taught' );
        $this->assertStringContainsString( 'rendered on the real Divi 5.15.1', preg_replace( '/\s+/', ' ', $text ) ?? '' );
        $this->assertSame( $text, ( $this->mcp( [ 'topic' => 'dividers' ] ) )[1], 'MCP serves the same text' );
        $this->assertSame( [ 'guide' => $text ], $this->rest( [ 'topic' => 'dividers' ] )->get_data(), 'REST serves the same text' );
    }

    public function testDividersTopicNamesEveryProvenStyleAndNoOther(): void
    {
        $proven = $this->provenDividerStyles();
        $this->assertGreaterThanOrEqual( 4, count( $proven ), 'several styles are proven' );
        $taught = $this->taughtDividerLists( StyleGuide::markdown( 'dividers' ) );
        $this->assertCount( $taught['count'], $taught['styles'], 'the stated count matches the list' );
        $this->assertSame( $proven, $taught['styles'], 'divider styles: taught == proven (exact set, both placements)' );
    }

    public function testDividersTopicRepeatExceptionsAreExactlyTheMeasuredDeadStyles(): void
    {
        $dead = $this->deadRepeatStyles();
        $this->assertNotSame( [], $dead, 'the precondition was probed' );
        $taught = $this->taughtDividerLists( StyleGuide::markdown( 'dividers' ) );
        $this->assertCount( $taught['repeatCount'], $taught['repeat'] );
        $this->assertSame( $dead, $taught['repeat'], 'the styles repeat does nothing for: taught == measured dead' );
        // ... and the repeat that is taught (3x on a repeatable style, both placements) did render.
        [ , $results ] = $this->dividerEvidence();
        foreach ( [ 'dv-bottom-repeat-3x', 'dv-top-repeat-3x' ] as $id ) {
            $this->assertTrue( self::dividerPassed( $results[ $id ] ), $id );
        }
    }

    public function testDividersResponsiveAndOtherClaimsRestOnMeasurements(): void
    {
        [ $cands, $results ] = $this->dividerEvidence();
        $diffs = static fn ( string $id ): array => [
            1280 => (int) $results[ $id ]['detail']['1280']['computedDiffs'],
            900  => (int) $results[ $id ]['detail']['900']['computedDiffs'],
            400  => (int) $results[ $id ]['detail']['400']['computedDiffs'],
        ];
        // The topic says a tablet override changed 900 and 400 only, a phone override only 400.
        $tablet = $diffs( 'dv-bottom-height-tablet' );
        $this->assertSame( 0, $tablet[1280] );
        $this->assertGreaterThan( 0, $tablet[900] );
        $this->assertGreaterThan( 0, $tablet[400] );
        foreach ( [ 'dv-bottom-height-phone', 'dv-bottom-style-none-phone' ] as $id ) {
            $d = $diffs( $id );
            $this->assertSame( 0, $d[1280], $id );
            $this->assertSame( 0, $d[900], $id );
            $this->assertGreaterThan( 0, $d[400], $id );
        }
        // No divider style overflowed a phone, and every style candidate exists for both placements.
        foreach ( $results as $id => $r ) {
            $this->assertFalse( (bool) $r['phoneOverflow'], $id . ' overflows a phone' );
        }
        $proven = $this->provenDividerStyles();
        $this->assertCount( 26, $proven, 'Divi ships 26 divider styles and all of them rendered' );
        foreach ( $proven as $style ) {
            $this->assertArrayHasKey( 'dv-top-style-' . $style, $cands );
            $this->assertArrayHasKey( 'dv-bottom-style-' . $style, $cands );
        }
        // Inner shadow is dead (and the topic says not to use it); a flex row ignores the gutter, a block row does not.
        $this->assertEmpty( $results['dv-inner-shadow-on']['effect'] );
        $this->assertEmpty( $results['dv-row-gutter-flex']['effect'] );
        $this->assertTrue( self::dividerPassed( $results['dv-row-gutter-block-width-4'] ) );
        $this->assertTrue( self::dividerPassed( $results['dv-row-gutter-block-width-1'] ) );
        $text = StyleGuide::markdown( 'dividers' );
        $this->assertStringContainsString( 'Do not use `module.advanced.innerShadow`', $text );
        $flat = preg_replace( '/\s+/', ' ', $text ) ?? '';
        $this->assertStringContainsString( 'the front end draws no inner shadow for it (rendered: dead); the Visual Builder preview was not checked', $flat, 'the inner shadow claim is limited to the front end' );
        $this->assertStringNotContainsString( 'draws nothing', $flat );
        $this->assertStringContainsString( 'all three rendered on a bottom divider; on a top divider only ["vertical"] was rendered', $flat );
        $this->assertStringNotContainsString( 'they always stretch', $flat );
        $this->assertStringNotContainsString( '(rendered: a default-padding', $flat );
        $this->assertStringContainsString( '("1" or "4", both rendered)', $text );
        $this->assertStringContainsString( '"3x" was rendered', $text );
        // The values the topic names (height, colour, flip, arrangement) are the values that passed.
        foreach ( [ '"200px"' => 'dv-bottom-height-200', '"50%"' => 'dv-bottom-height-percent', '"above"' => 'dv-bottom-arrangement-above' ] as $word => $id ) {
            $this->assertTrue( self::dividerPassed( $results[ $id ] ), $id );
            $this->assertStringContainsString( $word, $text );
        }
        foreach ( [ 'dv-bottom-flip-horizontal', 'dv-bottom-flip-vertical', 'dv-bottom-flip-both', 'dv-bottom-color', 'dv-top-color' ] as $id ) {
            $this->assertTrue( self::dividerPassed( $results[ $id ] ), $id );
        }
    }

    public function testDividersWorkedExamplesValidate(): void
    {
        $examples = $this->examples( 'dividers' );
        $this->assertGreaterThanOrEqual( 2, count( $examples ), 'a bottom divider example and a top-and-bottom example' );
        $registry = ShapeRegistry::load();
        $proven   = $this->provenDividerStyles();
        $used     = [];
        foreach ( $examples as $i => $example ) {
            $page   = '<!-- wp:divi/placeholder -->' . $example . '<!-- /wp:divi/placeholder -->';
            $result = ( new Validator() )->validateContent( $page );
            $this->assertTrue( $result->isValid(), "example $i: " . json_encode( array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), $result->violations() ) ) );
            $this->assertTrue( LayoutGate::check( $page )->isValid(), "example $i passes the plugin gate" );
            $this->assertStringNotContainsString( 'http', $example );
            preg_match_all( '/<!-- wp:divi\/[a-z-]+ (\{.*?\}) \/?-->/', $example, $blocks );
            foreach ( $blocks[1] as $json ) {
                $attrs = json_decode( $json, true );
                $this->assertIsArray( $attrs );
                foreach ( ShapePaths::fromBlockAttrs( $attrs ) as $path ) {
                    $this->assertTrue( $registry->covers( $path ), "example $i uses an unregistered path $path" );
                }
            }
            preg_match_all( '/"style":"([a-z0-9]+)"/', $example, $styles );
            foreach ( $styles[1] as $style ) {
                $this->assertContains( $style, $proven, "example $i uses the divider style $style, which is not proven" );
                $used[ $style ] = true;
            }
            // The neighbour is visible: a divider example has more than one section.
            $this->assertGreaterThanOrEqual( 2, substr_count( $example, '<!-- wp:divi/section ' ), "example $i has a neighbour section" );
        }
        $this->assertGreaterThanOrEqual( 2, count( $used ) );
    }

    /** @param list<string> $a @return list<string> */
    private function sorted( array $a ): array
    {
        sort( $a );

        return $a;
    }
}
