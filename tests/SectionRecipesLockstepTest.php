<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
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

        // A mood filter keeps the recipes tagged with that language plus the ones tagged "any"; filtering by "any" itself keeps
        // only the ones tagged "any" (since plan 2 task 11 a recipe may list only the languages it suits).
        [ , $both ] = $this->mcp( [ 'role' => 'features', 'layout' => 'split', 'mood' => 'organic-warm' ] );
        $this->assertStringContainsString( 'split-image-text', $both );
        $this->assertStringContainsString( 'card-grid-3', $both, 'card-grid-3 is a split (an intro row, then an image beside text) and lists organic-warm' );
        $this->assertStringNotContainsString( 'blurb-grid', $both );
        [ , $anyOnly ] = $this->mcp( [ 'role' => 'features', 'layout' => 'split', 'mood' => 'any' ] );
        $this->assertStringNotContainsString( 'blurb-grid', $anyOnly );
        // Both split recipes list their languages (rounded corners, pill images) instead of "any".
        $this->assertStringNotContainsString( 'split-image-text', $anyOnly );
        $this->assertStringNotContainsString( 'card-grid-3', $anyOnly );
    }

    public function testMcpUnknownFilterValueIsANormalAnswerListingTheValidValues(): void
    {
        [ $code, $text ] = $this->mcp( [ 'role' => 'nonsense' ] );
        $this->assertNull( $code );
        $this->assertStringContainsString( 'Unknown role "nonsense"', $text );
        $this->assertStringContainsString( 'hero', $text );
    }

    public function testMcpUnknownLayoutAndMoodEachAnswerWithTheValidValues(): void
    {
        [ $code, $text ] = $this->mcp( [ 'layout' => 'nonsense' ] );
        $this->assertNull( $code );
        $this->assertStringContainsString( 'Unknown layout "nonsense"', $text );
        $this->assertStringContainsString( 'centered', $text );

        [ $code, $text ] = $this->mcp( [ 'mood' => 'nonsense' ] );
        $this->assertNull( $code );
        $this->assertStringContainsString( 'Unknown mood "nonsense"', $text );
        $this->assertStringContainsString( 'any', $text );
        $this->assertStringContainsString( 'glass-dark', $text );
    }

    public function testRestUnknownLayoutAndMoodEachAre200WithTheMessageAndNoNames(): void
    {
        $r = $this->rest( [ 'layout' => 'nonsense' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertStringContainsString( 'Unknown layout "nonsense"', $r->get_data()['catalog'] );
        $this->assertSame( [], $r->get_data()['names'] );

        $r = $this->rest( [ 'mood' => 'nonsense' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertStringContainsString( 'Unknown mood "nonsense"', $r->get_data()['catalog'] );
        $this->assertSame( [], $r->get_data()['names'] );
    }

    public function testABadFilterIsRefusedEvenWhenANameIsGiven(): void
    {
        foreach ( [ [ 'role' => 5 ], [ 'layout' => [ 'x' ] ], [ 'mood' => true ] ] as $bad ) {
            [ $code, $text ] = $this->mcp( [ 'name' => 'contact-form' ] + $bad );
            $this->assertSame( -32602, $code, json_encode( $bad ) );
            $this->assertStringContainsString( 'must be a string', $text );

            $r = $this->rest( [ 'name' => 'contact-form' ] + $bad );
            $this->assertSame( 400, $r->get_status(), json_encode( $bad ) );
            $this->assertStringContainsString( 'must be a string', (string) $r->get_data()['error'] );
            $this->assertArrayNotHasKey( 'markup', $r->get_data() );
        }
    }

    public function testMcpWithoutFiltersIsTheUnchangedCompactCatalog(): void
    {
        [ $code, $text ] = $this->mcp( [] );
        $this->assertNull( $code );
        $this->assertSame( SectionRecipes::catalog(), $text );
        // Frozen: the unfiltered catalog is the compact one (a heading line, not the "(role=...)" filtered form).
        $this->assertStringStartsWith( "# Divi 5 Section Recipes\n\nWhole proven, validated sections.", $text );
        $this->assertStringNotContainsString( '(role=', $text );
        $this->assertStringNotContainsString( '(layout=', $text );
        [ , $nulls ] = $this->mcp( [ 'role' => null, 'layout' => '', 'mood' => null ] );
        $this->assertSame( $text, $nulls, 'null and empty string mean "no filter"' );
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
        $this->assertStringStartsWith( "# Divi 5 Section Recipes\n\nWhole proven, validated sections.", $r->get_data()['catalog'] );
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
        $this->assertSame( array_values( array_filter( SectionRecipes::names(), static fn ( string $n ): bool => in_array( 'any', (array) ( SectionRecipes::tags( $n )['mood'] ?? [] ), true ) ) ), SectionRecipes::matchingNames( null, null, 'any' ) );
        // A language mood selects the recipes tagged "any" plus the ones listing that language: never fewer than the "any" ones.
        $this->assertSame( [], array_diff( SectionRecipes::matchingNames( null, null, 'any' ), SectionRecipes::matchingNames( null, null, 'organic-warm' ) ) );
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
        foreach ( [ 'name', 'role', 'layout', 'mood', 'design_language' ] as $k ) {
            $this->assertArrayHasKey( $k, $params );
            $this->assertSame( 'query', $params[ $k ]['in'] );
            $this->assertFalse( $params[ $k ]['required'] );
            $this->assertSame( 'string', $params[ $k ]['schema']['type'] );
        }
        $this->assertCount( 5, $params );
        foreach ( SectionRecipes::ROLES as $r ) {
            $this->assertStringContainsString( $r, $params['role']['schema']['description'] );
        }
        foreach ( SectionRecipes::LAYOUTS as $l ) {
            $this->assertStringContainsString( $l, $params['layout']['schema']['description'] );
        }
    }

    /** MCP and OpenAPI describe the mood filter with the same words: mood values are design language ids, `any` suits every look. */
    public function testMoodDescriptionIsTheSameEverywhereAndExplainsTheValues(): void
    {
        $spec = OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $open = null;
        foreach ( $spec['paths']['/section-recipes']['get']['parameters'] as $p ) {
            if ( 'mood' === $p['name'] ) {
                $open = $p['schema']['description'];
            }
        }
        $req   = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ] );
        $mcp   = null;
        foreach ( (array) ( new McpHandler() )->handle( $req )->get_data()['result']['tools'] as $t ) {
            if ( 'get_section_recipes' === $t['name'] ) {
                $mcp = $t;
            }
        }
        $this->assertNotNull( $mcp );
        $this->assertSame( $open, $mcp['inputSchema']['properties']['mood']['description'] );
        $this->assertStringContainsString( SectionRecipes::MOOD_HELP, (string) $open );
        foreach ( SectionRecipes::moods() as $m ) {
            $this->assertStringContainsString( $m, (string) $open );
        }
        $this->assertStringNotContainsString( '{"mood":"any"}', $mcp['description'] );
        $this->assertStringContainsString( SectionRecipes::MOOD_HELP, $mcp['description'] );
    }

    public function testOpenApiDesignLanguageParameterNamesTheValidIds(): void
    {
        $spec = OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        foreach ( $spec['paths']['/section-recipes']['get']['parameters'] as $p ) {
            if ( 'design_language' === $p['name'] ) {
                foreach ( DesignLanguages::ids() as $id ) {
                    $this->assertStringContainsString( $id, $p['schema']['description'] );
                }
                return;
            }
        }
        $this->fail( 'design_language parameter missing' );
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
        foreach ( [ 'name', 'role', 'layout', 'mood', 'design_language' ] as $k ) {
            $this->assertSame( 'string', $tool['inputSchema']['properties'][ $k ]['type'] );
        }
        $this->assertSame( [], $tool['inputSchema']['required'] );
    }

    public function testNoNewToolOrOperationWasAdded(): void
    {
        $spec = OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $this->assertCount( 1, array_filter( array_keys( $spec['paths'] ), static fn ( string $p ): bool => '/section-recipes' === $p ) );
    }

    public function testMcpDesignLanguageResolvesTheRecipeAndLeavesNoToken(): void
    {
        $glass = DesignLanguages::get( 'glass-dark' );
        $this->assertNotNull( $glass );
        [ $code, $text ] = $this->mcp( [ 'name' => 'hero-cta', 'design_language' => 'glass-dark' ] );
        $this->assertNull( $code );
        $this->assertStringContainsString( $glass['colors']['accent'], $text );
        $this->assertStringNotContainsString( '{{aied:', $text );
        $this->assertSame( SectionRecipes::recipe( 'hero-cta', 'glass-dark' ), $text );

        [ , $plain ] = $this->mcp( [ 'name' => 'hero-cta' ] );
        $this->assertSame( SectionRecipes::recipe( 'hero-cta' ), $plain );
        $this->assertStringNotContainsString( $glass['colors']['accent'], $plain );
        [ , $empty ] = $this->mcp( [ 'name' => 'hero-cta', 'design_language' => '' ] );
        $this->assertSame( $plain, $empty, 'an empty design_language means none' );
    }

    public function testMcpDesignLanguageWithoutANameChangesNothing(): void
    {
        [ $code, $text ] = $this->mcp( [ 'design_language' => 'glass-dark' ] );
        $this->assertNull( $code );
        $this->assertSame( SectionRecipes::catalog(), $text );
    }

    public function testMcpRejectsANonStringDesignLanguage(): void
    {
        foreach ( [ 7, [ 'glass-dark' ], true ] as $bad ) {
            [ $code, $text ] = $this->mcp( [ 'name' => 'hero-cta', 'design_language' => $bad ] );
            $this->assertSame( -32602, $code );
            $this->assertStringContainsString( 'must be a string', $text );
        }
    }

    public function testMcpUnknownDesignLanguageAnswersWithTheValidIds(): void
    {
        [ $code, $text ] = $this->mcp( [ 'name' => 'hero-cta', 'design_language' => 'nope' ] );
        $this->assertNull( $code );
        $this->assertStringContainsString( 'Unknown design language "nope"', $text );
        foreach ( DesignLanguages::ids() as $id ) {
            $this->assertStringContainsString( $id, $text );
        }
        $this->assertStringNotContainsString( 'wp:divi/section', $text, 'no partially resolved markup' );
    }

    public function testRestDesignLanguageResolvesTheRecipe(): void
    {
        $glass = DesignLanguages::get( 'glass-dark' );
        $r = $this->rest( [ 'name' => 'hero-cta', 'design_language' => 'glass-dark' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( SectionRecipes::recipe( 'hero-cta', 'glass-dark' ), $r->get_data()['markup'] );
        $this->assertStringContainsString( $glass['colors']['accent'], $r->get_data()['markup'] );
        $this->assertStringNotContainsString( '{{aied:', $r->get_data()['markup'] );
    }

    public function testRestRejectsANonStringDesignLanguage(): void
    {
        foreach ( [ 7, [ 'x' ], true ] as $bad ) {
            $r = $this->rest( [ 'name' => 'hero-cta', 'design_language' => $bad ] );
            $this->assertSame( 400, $r->get_status() );
            $this->assertStringContainsString( 'must be a string', (string) $r->get_data()['error'] );
        }
    }

    public function testRestUnknownDesignLanguageIs404WithTheValidIds(): void
    {
        $r = $this->rest( [ 'name' => 'hero-cta', 'design_language' => 'nope' ] );
        $this->assertSame( 404, $r->get_status() );
        $this->assertSame( DesignLanguages::ids(), $r->get_data()['languages'] );
        $this->assertArrayNotHasKey( 'markup', $r->get_data() );
    }

    public function testRestDesignLanguageWithoutANameChangesNothing(): void
    {
        $r = $this->rest( [ 'design_language' => 'glass-dark' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( [ 'catalog' => SectionRecipes::catalog(), 'names' => SectionRecipes::names() ], $r->get_data() );
    }
}
