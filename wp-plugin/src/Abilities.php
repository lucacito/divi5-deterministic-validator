<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/AbilityCatalog.php';

/**
 * Registers the plugin's abilities with the WordPress Abilities API (WordPress 6.9 and later) so that any agent, including one
 * working through Divi's upcoming MCP, can call the design guidance, the validator and the page history.
 *
 * Nothing here is required: on WordPress without the API the two hooks never fire and `function_exists` guards every call.
 * The existing MCP endpoint, REST routes and OpenAPI spec do not depend on this class and are unchanged.
 *
 * Security: no ability is open. Each one needs a logged-in user who can edit pages (the capability the MCP endpoint and the
 * REST routes already require), and one that names a page also needs edit_post on that page, exactly as the tools check it.
 * The tool behind it checks again.
 */
final class Abilities
{
    /** Same capability the MCP endpoint and REST routes require of the user. */
    public const CAPABILITY = 'edit_pages';

    public static function register(): void
    {
        add_action( 'wp_abilities_api_categories_init', [ self::class, 'registerCategory' ] );
        add_action( 'wp_abilities_api_init', [ self::class, 'registerAll' ] );
    }

    public static function registerCategory(): void
    {
        if ( ! function_exists( 'wp_register_ability_category' ) || ! (bool) apply_filters( 'jhmg_aied_register_abilities', true ) ) {
            return;
        }
        wp_register_ability_category(
            AbilityCatalog::CATEGORY,
            [
                'label'       => 'JHMG AI Editor for Divi 5',
                'description' => 'Design guidance, deterministic validation and undo for any AI working on Divi 5 pages, designed to work alongside Divi\'s upcoming MCP.',
            ]
        );
    }

    public static function registerAll(): void
    {
        if ( ! function_exists( 'wp_register_ability' ) || ! (bool) apply_filters( 'jhmg_aied_register_abilities', true ) ) {
            return;
        }
        foreach ( AbilityCatalog::all() as $def ) {
            wp_register_ability(
                AbilityCatalog::fullName( (string) $def['name'] ),
                self::args(
                    $def,
                    AbilityCatalog::CATEGORY,
                    static fn ( $input = null ) => self::permit( $def, $input ),
                    static fn ( $input = null ) => self::run( $def, $input )
                )
            );
        }
    }

    /**
     * The argument array for wp_register_ability(). Public so an add-on can build its abilities the same way.
     *
     * @param array<string,mixed> $def
     * @return array<string,mixed>
     */
    public static function args( array $def, string $category, callable $permission, callable $execute ): array
    {
        return [
            'label'               => (string) $def['label'],
            'description'         => (string) $def['description'],
            'category'            => $category,
            'input_schema'        => $def['input'],
            'output_schema'       => [ 'type' => 'object' ],
            'execute_callback'    => $execute,
            'permission_callback' => $permission,
            'meta'                => [
                'annotations'  => $def['annotations'],
                'public'       => true,
                'show_in_rest' => true,
                // The official WordPress MCP adapter reads meta.mcp.public (falling back to meta.public) and meta.mcp.type.
                'mcp'          => [ 'public' => true, 'type' => 'tool' ],
            ],
        ];
    }

    /**
     * May the current user call this ability with this input? A logged-in user who can edit pages, and edit_post on a page the
     * input names. A page that does not exist is not refused here: the tool answers "not found" and nothing is revealed.
     *
     * @param array<string,mixed> $def
     */
    public static function permit( array $def, mixed $input, string $capability = self::CAPABILITY ): bool
    {
        if ( ! is_user_logged_in() || ! current_user_can( $capability ) ) {
            return false;
        }
        $key = $def['page_arg'] ?? null;
        if ( is_string( $key ) && is_array( $input ) && isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) && (int) $input[ $key ] > 0 ) {
            $id = (int) $input[ $key ];
            if ( get_post( $id ) && ! current_user_can( 'edit_post', $id ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Runs the ability. An MCP-backed one runs its tool in-process; the others have a handler.
     *
     * @param array<string,mixed> $def
     * @return array<string,mixed>|WP_Error
     */
    public static function run( array $def, mixed $input ): array|WP_Error
    {
        $args = is_array( $input ) ? $input : [];
        if ( is_string( $def['tool'] ?? null ) ) {
            return self::fromToolResult( ( new McpHandler() )->callTool( $def['tool'], $args ) );
        }

        return AbilityHandlers::run( (string) $def['name'], $args );
    }

    /**
     * @param array{ok:bool, text:string, data:?array<mixed>, code:?int, message:string} $result
     * @return array<string,mixed>|WP_Error
     */
    public static function fromToolResult( array $result ): array|WP_Error
    {
        if ( ! $result['ok'] ) {
            if ( null !== $result['code'] ) {
                return new WP_Error( 'jhmg_invalid_params', $result['message'], [ 'status' => 400 ] );
            }

            // A refusal carries the exact violation codes the agent needs to fix the layout, so the whole answer is the message.
            return new WP_Error( 'jhmg_rejected', substr( $result['text'], 0, 8000 ), [ 'status' => 400, 'details' => $result['data'] ] );
        }
        $data = $result['data'];
        if ( null === $data ) {
            return [ 'content' => $result['text'] ];
        }

        return [] !== $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) ? [ 'items' => $data ] : $data;
    }
}
