<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
use AiEditorDivi5\WP\RestController;
use AiEditorDivi5\WP\StyleGuide;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** On-demand style guide topics: the default text barely grows, the rest is served by topic, in MCP, REST and OpenAPI alike. */
class StyleGuideTopicsTest extends TestCase
{
    /** Bytes of StyleGuide::markdown() measured on commit c777962, before the topics existed. */
    private const ORIGINAL_LENGTH = 27908;
    private const MAX_GROWTH = 1200;

    private const TOPIC_LINE = 'Topics: call get_style_guide with topic=<name>: design-languages, design-language:<id>.';

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
        $this->assertStringEndsWith( self::TOPIC_LINE, $text );
        $this->assertSame( 1, substr_count( $text, 'Topics: call get_style_guide' ) );
        $this->assertSame( $text, StyleGuide::markdown( null ) );
        $this->assertSame( $text, StyleGuide::markdown( '' ), 'an empty topic means none' );
    }

    public function testTopicsListIsTheIndexPlusEveryLanguage(): void
    {
        $topics = StyleGuide::topics();
        $this->assertSame( 'design-languages', $topics[0] );
        $this->assertCount( 1 + count( DesignLanguages::ids() ), $topics );
        foreach ( DesignLanguages::ids() as $id ) {
            $this->assertContains( 'design-language:' . $id, $topics );
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
        $this->assertSame( [], $tool['inputSchema']['required'] );
    }

    public function testNoNewToolOrOperationWasAdded(): void
    {
        $spec = OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $this->assertCount( 1, array_filter( array_keys( $spec['paths'] ), static fn ( string $p ): bool => '/style-guide' === $p ) );
    }
}
