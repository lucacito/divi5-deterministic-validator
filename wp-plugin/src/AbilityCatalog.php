<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/ToolCatalog.php';

/**
 * The abilities this plugin offers to other tools through the WordPress Abilities API (WordPress 6.9 and later), as plain data.
 *
 * Fourteen of them are the plugin's own MCP tools under a kebab-case name (the input schema is the tool's own, read from
 * ToolCatalog, so the two cannot drift). Three are new and have no MCP tool: get-design-languages, get-variety-advice and
 * check-design. Nothing here registers anything; Abilities does that, and only where the API exists.
 *
 * A definition is: name (without the namespace), tool (the MCP tool it runs, or null for a handler in AbilityHandlers),
 * label, description (when to use it, and that it works beside Divi's own tools), annotations, input (a JSON Schema
 * object), page_arg (the input key that holds a page id, checked with edit_post) and mode.
 */
final class AbilityCatalog
{
    public const NAMESPACE = 'jhmg-ai-editor';
    public const CATEGORY  = 'jhmg-ai-editor';

    /** Read-only: no side effects at all. */
    private const READ = [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ];
    /** Changes a page. Not destructive in the API's sense (every change is undoable) and not idempotent. */
    private const WRITE = [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ];

    /** @var ?list<array<string,mixed>> */
    private static ?array $cache = null;

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        if ( null === self::$cache ) {
            self::$cache = self::build();
        }

        return self::$cache;
    }

    public static function fullName( string $name ): string
    {
        return self::NAMESPACE . '/' . $name;
    }

    /** @return ?array<string,mixed> */
    public static function get( string $name ): ?array
    {
        foreach ( self::all() as $def ) {
            if ( $def['name'] === $name ) {
                return $def;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private static function build(): array
    {
        $defs = [
            self::mapped( 'get-style-guide', 'get_style_guide', 'Get the Divi 5 style guide', self::READ,
                'Call before styling or building a Divi 5 page to learn the attribute shapes the validator accepts, the design languages and the style kits. Works alongside Divi\'s own tools: read it first, then build with any of them.' ),
            self::mapped( 'get-landing-guide', 'get_landing_guide', 'Get the landing page guide', self::READ,
                'Call before writing a single marketing or landing page to get the persuasion flow, copy rules and call to action strategy. Then build the page with any Divi tool.' ),
            self::mapped( 'get-site-guide', 'get_site_guide', 'Get the multi-page site guide', self::READ,
                'Call before planning a multi-page site to get the page types, the cross-links and a build order. Then build the pages with any Divi tool; the site owner sets the menu and the front page.' ),
            self::mapped( 'get-image-guide', 'get_image_guide', 'Get the image guide', self::READ,
                'Call before choosing images for a Divi page: it says which image fits each section, Media Library first and then the built-in pack. It works with any tool that places images.' ),
            self::sectionRecipes(),
            self::mapped( 'validate-layout', 'validate_layout', 'Validate a Divi 5 layout', self::READ,
                'Call to check Divi 5 layout markup with the deterministic validator, before or after saving it with any tool. It returns exact violation codes and changes nothing, and it works on markup written by Divi\'s own tools too.' ),
            self::mapped( 'list-divi-pages', 'list_divi_pages', 'List editable Divi pages', self::READ,
                'Call to find the pages (or Divi Projects) you may edit, with their IDs. The IDs work with the other jhmg-ai-editor abilities and are the same IDs Divi\'s own tools use.' ),
            self::mapped( 'get-page-layout', 'get_page_layout', 'Read a page\'s Divi 5 layout', self::READ,
                'Call to read the stored Divi 5 layout of one page, as a full read, a short outline, or one block. Use the outline first on big pages; it works for pages built by any tool.' ),
            self::mapped( 'update-page-layout', 'update_page_layout', 'Save a Divi 5 layout (validated)', self::WRITE,
                'Call to replace a page\'s layout, or to rename it. The layout is checked by the deterministic validator and an invalid one is refused with exact reasons, so you can fix it and retry. The previous version is kept for undo. Use dry_run to preview.' ),
            self::mapped( 'edit-page-content', 'edit_page_content', 'Find and replace text in a page layout', self::WRITE,
                'Call for a small, surgical text change on a page: it replaces an exact string and leaves everything else untouched. The result is validated and the previous version is kept for undo. Use dry_run to preview.' ),
            self::mapped( 'create-page', 'create_page', 'Create a draft page from a Divi 5 layout', self::WRITE,
                'Call to create a new page from a validated Divi 5 layout. The page is always a draft for the site owner to review. You can also create pages with Divi\'s own tools; this one guarantees the validator ran.' ),
            self::mapped( 'list-page-history', 'list_page_history', 'List a page\'s saved versions', self::READ,
                'Call to see the versions the plugin can restore for one page, newest first, with when and by what each was saved. This works for changes made by any tool, not only this plugin\'s.' ),
            self::mapped( 'get-page-history-entry', 'get_page_history_entry', 'Read one saved version of a page', self::READ,
                'Call to read the layout of one saved version before deciding to restore it.' ),
            self::mapped( 'restore-page-version', 'restore_page_version', 'Restore an earlier version of a page', self::WRITE,
                'Call to undo a change: it puts a saved version back, and the version it replaces is saved first, so the restore can itself be undone. It works for changes made by any tool.' ),
            self::designLanguages(),
            self::varietyAdvice(),
            self::checkDesign(),
        ];

        return $defs;
    }

    /**
     * @param array<string,bool> $annotations
     * @return array<string,mixed>
     */
    private static function mapped( string $name, string $tool, string $label, array $annotations, string $description ): array
    {
        $tooldef = ToolCatalog::get( $tool );
        $schema  = null === $tooldef ? [ 'type' => 'object', 'properties' => [] ] : (array) $tooldef['inputSchema'];

        return [
            'name'        => $name,
            'tool'        => $tool,
            'label'       => $label,
            'description' => $description,
            'annotations' => $annotations,
            'input'       => self::objectSchema( $schema ),
            'page_arg'    => isset( ( (array) ( $schema['properties'] ?? [] ) )['page_id'] ) ? 'page_id' : null,
        ];
    }

    /**
     * get-section-recipes takes two more filters than the MCP tool (density and stage). The MCP tool is pinned byte for byte, so
     * the ability has its own handler for the list and falls back to the tool for one named recipe.
     *
     * @return array<string,mixed>
     */
    private static function sectionRecipes(): array
    {
        $tool   = (array) ToolCatalog::get( 'get_section_recipes' );
        $schema = (array) $tool['inputSchema'];
        $schema['properties'] = (array) $schema['properties'] + [
            'density' => [ 'type' => 'string', 'enum' => [ 'airy', 'balanced', 'dense' ], 'description' => 'Optional. Keep only recipes with this density.' ],
            'stage'   => [ 'type' => 'string', 'description' => 'Optional. Keep only recipes that serve this persuasion stage, e.g. Hero, Pricing, FAQ, Social proof, Final CTA.' ],
        ];

        return [
            'name'        => 'get-section-recipes',
            'tool'        => null,
            'label'       => 'Get proven Divi 5 section recipes',
            'description' => 'Call before building a Divi 5 page to browse proven, validated section patterns by role, layout, mood, density and persuasion stage, or to fetch one recipe\'s markup in a design language and style kit. Then assemble the page with any Divi tool.',
            'annotations' => self::READ,
            'input'       => self::objectSchema( $schema ),
            'page_arg'    => null,
        ];
    }

    /** @return array<string,mixed> */
    private static function designLanguages(): array
    {
        return [
            'name'        => 'get-design-languages',
            'tool'        => null,
            'label'       => 'Get the design languages',
            'description' => 'Call before building a Divi 5 page to choose one of the 10 design languages: each has colors, fonts, shape, spacing, motion, things to avoid and text and background pairings checked for contrast. Then build the page with any Divi tool in the language you chose.',
            'annotations' => self::READ,
            'input'       => self::objectSchema( [
                'type'       => 'object',
                'properties' => [ 'id' => [ 'type' => 'string', 'description' => 'Optional. One language id (for example "editorial"). Leave out to get all ten.' ] ],
            ] ),
            'page_arg'    => null,
        ];
    }

    /** @return array<string,mixed> */
    private static function varietyAdvice(): array
    {
        return [
            'name'        => 'get-variety-advice',
            'tool'        => null,
            'label'       => 'Check a page for repetition',
            'description' => 'Call after building a Divi 5 page, or on any existing page, to find sections that repeat the same skeleton, a missing focal point or flat backgrounds, with a few recipes to fetch for each. It is advice only and works on pages built by any tool. Pass post_content or a page_id.',
            'annotations' => self::READ,
            'input'       => self::objectSchema( [
                'type'       => 'object',
                'properties' => [
                    'post_content' => [ 'type' => 'string', 'description' => 'Divi 5 layout markup to look at.' ],
                    'page_id'      => [ 'type' => 'integer', 'description' => 'Or the ID of a page or Divi Project to read.' ],
                ],
            ] ),
            'page_arg'    => 'page_id',
        ];
    }

    /** @return array<string,mixed> */
    private static function checkDesign(): array
    {
        return [
            'name'        => 'check-design',
            'tool'        => null,
            'label'       => 'Check the design of a page',
            'description' => 'Call after building a page with any Divi tool to catch design problems the builder accepts: text that fails contrast, ids of presets or colors this site does not have, and repetitive sections. It is advice only, never blocks or changes a page, and lists what it could not check. Pass post_content or a page_id.',
            'annotations' => self::READ,
            'input'       => self::objectSchema( [
                'type'       => 'object',
                'properties' => [
                    'post_content' => [ 'type' => 'string', 'description' => 'Divi 5 layout markup to check.' ],
                    'page_id'      => [ 'type' => 'integer', 'description' => 'Or the ID of a page or Divi Project to check.' ],
                ],
            ] ),
            'page_arg'    => 'page_id',
        ];
    }

    /**
     * The Abilities API validates null input against the schema, so an all-optional ability needs an empty object as default.
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private static function objectSchema( array $schema ): array
    {
        $schema['type'] = 'object';
        // The MCP definitions use an empty stdClass for "no properties" (it must encode as {}); the Abilities API wants an array.
        if ( isset( $schema['properties'] ) && is_object( $schema['properties'] ) ) {
            $schema['properties'] = (array) $schema['properties'];
        }
        if ( ! isset( $schema['properties'] ) || [] === $schema['properties'] ) {
            $schema['properties'] = [];
        }
        if ( [] === ( $schema['required'] ?? [] ) ) {
            unset( $schema['required'] );
        }
        if ( ! isset( $schema['required'] ) ) {
            $schema['default'] = [];
        }

        return $schema;
    }
}
