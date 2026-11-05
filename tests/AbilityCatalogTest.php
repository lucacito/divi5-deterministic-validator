<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AbilityCatalog;
use AiEditorDivi5\WP\ToolCatalog;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/ToolCatalog.php';
require_once __DIR__ . '/../wp-plugin/src/AbilityCatalog.php';

/** What the plugin offers as WordPress abilities, and that no name can collide with Divi's or WordPress's own. */
class AbilityCatalogTest extends TestCase
{
    /** Namespaces that belong to someone else: Divi (Elegant Themes), WordPress core and the official MCP adapter. */
    private const RESERVED = [ 'divi', 'divi-ai', 'divi-mcp', 'et', 'et-builder', 'elegant-themes', 'elegantthemes', 'core', 'wp', 'mcp-adapter' ];

    public function testThereAreSeventeenAbilitiesInOneCategory(): void
    {
        $names = array_column( AbilityCatalog::all(), 'name' );
        $this->assertCount( 17, $names );
        $this->assertSame( $names, array_values( array_unique( $names ) ), 'names are unique' );
        $this->assertSame( 'jhmg-ai-editor', AbilityCatalog::CATEGORY );
        $this->assertMatchesRegularExpression( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', AbilityCatalog::CATEGORY, 'a category slug has no slash' );
    }

    public function testTheRequiredCompanionAbilitiesExist(): void
    {
        $names = array_column( AbilityCatalog::all(), 'name' );
        foreach ( [
            'get-style-guide', 'get-landing-guide', 'get-site-guide', 'get-image-guide', 'get-section-recipes', 'get-design-languages',
            'get-variety-advice', 'validate-layout', 'check-design', 'list-page-history', 'get-page-history-entry', 'restore-page-version',
            'update-page-layout', 'edit-page-content', 'create-page', 'list-divi-pages', 'get-page-layout',
        ] as $required ) {
            $this->assertContains( $required, $names );
        }
    }

    public function testEveryNameIsValidForCoreAndOutsideEveryReservedNamespace(): void
    {
        foreach ( AbilityCatalog::all() as $def ) {
            $full = AbilityCatalog::fullName( $def['name'] );
            // The exact rule WP_Abilities_Registry::register() applies (wp-includes/abilities-api/class-wp-abilities-registry.php).
            $this->assertMatchesRegularExpression( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $full );
            $namespace = explode( '/', $full )[0];
            $this->assertSame( 'jhmg-ai-editor', $namespace );
            foreach ( self::RESERVED as $reserved ) {
                $this->assertStringStartsNotWith( $reserved . '/', $full );
                $this->assertNotSame( $reserved, $namespace );
            }
        }
    }

    public function testEveryAbilityTellsAnAgentWhenToUseItAndIsCleanText(): void
    {
        foreach ( AbilityCatalog::all() as $def ) {
            $this->assertStringStartsWith( 'Call ', $def['description'], $def['name'] );
            $this->assertLessThanOrEqual( 420, strlen( $def['description'] ), $def['name'] . ' description is long' );
            foreach ( [ 'label', 'description' ] as $field ) {
                $this->assertStringNotContainsString( "\u{2014}", $def[ $field ], 'no em dash in ' . $def['name'] );
                $this->assertNotSame( '', trim( $def[ $field ] ) );
            }
        }
        $alongside = 0;
        foreach ( AbilityCatalog::all() as $def ) {
            $alongside += false !== stripos( $def['description'], 'any Divi tool' ) || false !== stripos( $def['description'], 'Divi\'s own' ) || false !== stripos( $def['description'], 'any tool' ) ? 1 : 0;
        }
        $this->assertGreaterThanOrEqual( 12, $alongside, 'most abilities say they work beside Divi\'s own tools' );
    }

    public function testMappedAbilitiesUseTheirToolsOwnSchema(): void
    {
        $mapped = 0;
        foreach ( AbilityCatalog::all() as $def ) {
            if ( ! is_string( $def['tool'] ) ) {
                continue;
            }
            ++$mapped;
            $tool = ToolCatalog::get( $def['tool'] );
            $this->assertNotNull( $tool, $def['tool'] . ' is a real tool' );
            $props = (array) ( $tool['inputSchema']['properties'] ?? [] );
            $this->assertSame( array_keys( $props ), array_keys( (array) $def['input']['properties'] ), $def['name'] . ' takes the arguments of ' . $def['tool'] );
            $this->assertSame( $tool['inputSchema']['required'] ?? [], $def['input']['required'] ?? [], $def['name'] . ' requires what the tool requires' );
        }
        $this->assertSame( 13, $mapped, '13 abilities run an MCP tool as it is (get-section-recipes has its own handler for two extra filters)' );
    }

    public function testInputSchemasAreObjectsWithADefaultWhenNothingIsRequired(): void
    {
        foreach ( AbilityCatalog::all() as $def ) {
            $schema = $def['input'];
            $this->assertSame( 'object', $schema['type'] );
            $this->assertIsArray( $schema['properties'], 'core wants an array, not an object' );
            if ( empty( $schema['required'] ) ) {
                $this->assertSame( [], $schema['default'], $def['name'] . ': core rejects null input for an object schema without a default' );
            } else {
                $this->assertArrayNotHasKey( 'default', $schema );
            }
        }
    }

    public function testReadOnlyAbilitiesAreAnnotatedAndWritersAreNot(): void
    {
        $writers = [ 'update-page-layout', 'edit-page-content', 'create-page', 'restore-page-version' ];
        foreach ( AbilityCatalog::all() as $def ) {
            $isWriter = in_array( $def['name'], $writers, true );
            $this->assertSame( ! $isWriter, $def['annotations']['readonly'], $def['name'] );
            $this->assertFalse( $def['annotations']['destructive'], 'every change is undoable, none is destructive' );
        }
    }

    public function testAbilitiesThatNameAPageDeclareWhichArgumentHoldsIt(): void
    {
        foreach ( [ 'get-page-layout', 'update-page-layout', 'edit-page-content', 'list-page-history', 'get-page-history-entry', 'restore-page-version', 'check-design', 'get-variety-advice' ] as $name ) {
            $this->assertSame( 'page_id', AbilityCatalog::get( $name )['page_arg'], $name );
        }
        foreach ( [ 'get-style-guide', 'create-page', 'get-design-languages', 'list-divi-pages' ] as $name ) {
            $this->assertNull( AbilityCatalog::get( $name )['page_arg'], $name );
        }
    }
}
