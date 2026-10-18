<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
use AiEditorDivi5\WP\RestController;
use AiEditorDivi5\WP\SectionRecipes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** The role, layout and mood filters of get_section_recipes must mean the same in MCP, REST and OpenAPI. */
class SectionRecipesLockstepTest extends TestCase
{
    /** @return array{0:?int,1:string} JSON-RPC error code (or null) and the text (result text or error message) */
    private function mcp( array $args ): array
    {
        $req  = new \WP_REST_Request( [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [ 'name' => 'get_section_recipes', 'arguments' => $args ],
        ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], (string) $data['error']['message'] ];
        }

        return [ null, (string) $data['result']['content'][0]['text'] ];
    }

    private function rest( array $params ): \WP_REST_Response
    {
        return ( new RestController() )->section_recipes( new \WP_REST_Request( [], $params ) );
    }

    public function testMcpAcceptsRoleFilter(): void
    {
        [ $code, $text ] = $this->mcp( [ 'role' => 'hero' ] );
        $this->assertNull( $code );
        $this->assertStringContainsString( 'hero-cta', $text );
        $this->assertStringNotContainsString( 'contact-form', $text );
    }

    public function testMcpLayoutAndMoodFiltersNarrowTheCatalog(): void
    {
        [ , $text ] = $this->mcp( [ 'layout' => 'form' ] );
        $this->assertStringContainsString( 'contact-form', $text );
        $this->assertStringNotContainsString( 'hero-cta', $text );

        [ , $both ] = $this->mcp( [ 'role' => 'features', 'layout' => 'split', 'mood' => 'any' ] );
        $this->assertStringContainsString( 'split-image-text', $both );
        $this->assertStringNotContainsString( 'card-grid-3', $both );
    }

    public function testMcpUnknownFilterValueIsANormalAnswerListingTheValidValues(): void
    {
        [ $code, $text ] = $this->mcp( [ 'role' => 'nonsense' ] );
        $this->assertNull( $code );
        $this->assertStringContainsString( 'Unknown role "nonsense"', $text );
        $this->assertStringContainsString( 'hero', $text );
    }

    public function testMcpWithoutFiltersIsTheUnchangedCompactCatalog(): void
    {
        [ $code, $text ] = $this->mcp( [] );
        $this->assertNull( $code );
        $this->assertSame( SectionRecipes::catalog(), $text );
        [ , $nulls ] = $this->mcp( [ 'role' => null, 'layout' => '', 'mood' => null ] );
        $this->assertSame( SectionRecipes::catalog(), $nulls, 'null and empty string mean "no filter"' );
    }

    public function testMcpNameWinsOverFilters(): void
    {
        [ $code, $text ] = $this->mcp( [ 'name' => 'contact-form', 'role' => 'hero' ] );
        $this->assertNull( $code );
        $this->assertSame( SectionRecipes::recipe( 'contact-form' ), $text );
    }

    public static function nonStringFilters(): array
    {
        return [
            'role number'   => [ [ 'role' => 5 ] ],
            'layout array'  => [ [ 'layout' => [ 'x' ] ] ],
            'mood bool'     => [ [ 'mood' => true ] ],
            'role object'   => [ [ 'role' => [ 'a' => 'b' ] ] ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'nonStringFilters' )]
    public function testMcpRejectsNonStringFilters( array $args ): void
    {
        [ $code, $text ] = $this->mcp( $args );
        $this->assertSame( -32602, $code );
        $this->assertStringContainsString( 'must be a string', $text );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'nonStringFilters' )]
    public function testRestRejectsNonStringFilters( array $params ): void
    {
        $r = $this->rest( $params );
        $this->assertSame( 400, $r->get_status() );
        $this->assertStringContainsString( 'must be a string', (string) $r->get_data()['error'] );
    }

    public function testRestWithoutFiltersKeepsTheOldShape(): void
    {
        $r = $this->rest( [] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( [ 'catalog' => SectionRecipes::catalog(), 'names' => SectionRecipes::names() ], $r->get_data() );
    }

    public function testRestRoleFilterReturnsTheFilteredCatalogAndTheMatchingNames(): void
    {
        $r = $this->rest( [ 'role' => 'hero' ] );
        $this->assertSame( 200, $r->get_status() );
        $d = $r->get_data();
        $this->assertSame( SectionRecipes::catalog( 'hero' ), $d['catalog'] );
        $this->assertContains( 'hero-cta', $d['names'] );
        $this->assertNotContains( 'contact-form', $d['names'] );
        $this->assertSame( SectionRecipes::matchingNames( 'hero' ), $d['names'] );
    }

    public function testRestUnknownFilterValueIs200WithTheMessageAndNoNames(): void
    {
        $r = $this->rest( [ 'role' => 'nonsense' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertStringContainsString( 'Unknown role "nonsense"', $r->get_data()['catalog'] );
        $this->assertSame( [], $r->get_data()['names'] );
    }

    public function testRestNameWinsOverFilters(): void
    {
        $r = $this->rest( [ 'name' => 'contact-form', 'role' => 'hero' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( 'contact-form', $r->get_data()['name'] );
        $this->assertArrayNotHasKey( 'catalog', $r->get_data() );
    }

    public function testMatchingNamesFollowsTheSameRulesAsTheCatalog(): void
    {
        $this->assertSame( [], SectionRecipes::matchingNames( 'nonsense' ) );
        $this->assertSame( SectionRecipes::names(), SectionRecipes::matchingNames( null, null, 'any' ) );
        foreach ( SectionRecipes::matchingNames( 'features', 'grid' ) as $n ) {
            $this->assertSame( 'features', SectionRecipes::tags( $n )['role'] );
            $this->assertSame( 'grid', SectionRecipes::tags( $n )['layout'] );
        }
    }

    public function testOpenApiListsTheThreeParameters(): void
    {
        $spec   = OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $get    = $spec['paths']['/section-recipes']['get'];
        $params = [];
        foreach ( $get['parameters'] as $p ) {
            $params[ $p['name'] ] = $p;
        }
        foreach ( [ 'name', 'role', 'layout', 'mood' ] as $k ) {
            $this->assertArrayHasKey( $k, $params );
            $this->assertSame( 'query', $params[ $k ]['in'] );
            $this->assertFalse( $params[ $k ]['required'] );
            $this->assertSame( 'string', $params[ $k ]['schema']['type'] );
        }
        $this->assertCount( 4, $params );
        foreach ( SectionRecipes::ROLES as $r ) {
            $this->assertStringContainsString( $r, $params['role']['schema']['description'] );
        }
        foreach ( SectionRecipes::LAYOUTS as $l ) {
            $this->assertStringContainsString( $l, $params['layout']['schema']['description'] );
        }
    }

    public function testMcpSchemaDeclaresTheThreeStringFilters(): void
    {
        $req   = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ] );
        $tools = (array) ( new McpHandler() )->handle( $req )->get_data()['result']['tools'];
        $tool  = null;
        foreach ( $tools as $t ) {
            if ( 'get_section_recipes' === $t['name'] ) {
                $tool = $t;
            }
        }
        $this->assertNotNull( $tool );
        foreach ( [ 'name', 'role', 'layout', 'mood' ] as $k ) {
            $this->assertSame( 'string', $tool['inputSchema']['properties'][ $k ]['type'] );
        }
        $this->assertSame( [], $tool['inputSchema']['required'] );
    }

    public function testNoNewToolOrOperationWasAdded(): void
    {
        $spec = OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $this->assertCount( 1, array_filter( array_keys( $spec['paths'] ), static fn ( string $p ): bool => '/section-recipes' === $p ) );
    }
}
