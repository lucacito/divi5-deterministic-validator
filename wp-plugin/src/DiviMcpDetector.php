<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Notices when another tool has registered WordPress abilities in a Divi namespace (Divi's own MCP exposes its tools as
 * abilities). It only READS the list of registered ability names; it never wraps, filters, unregisters or calls another
 * plugin's ability.
 *
 * Divi's MCP had not shipped when this was written and Divi 5.15.1 registers no abilities, so its real ability names are not
 * known. The namespace list is therefore a filterable guess (`jhmg_aied_divi_mcp_namespaces`); when it is wrong the only
 * effect is that the Dashboard does not show the "works alongside" line.
 */
final class DiviMcpDetector
{
    /** Ability namespaces (the part before the slash) that mean Divi's own tools. */
    public const NAMESPACES = [ 'divi', 'divi-ai', 'divi-mcp', 'et', 'et-builder', 'elegant-themes' ];

    /**
     * @param list<string> $abilityNames every registered ability name
     * @param list<string> $namespaces
     * @return array{present:bool, count:int, examples:list<string>}
     */
    public static function detect( array $abilityNames, array $namespaces = self::NAMESPACES ): array
    {
        $found = [];
        foreach ( $abilityNames as $name ) {
            if ( ! is_string( $name ) || str_starts_with( $name, AbilityCatalog::NAMESPACE . '/' ) ) {
                continue;
            }
            $ns = strstr( $name, '/', true );
            if ( false !== $ns && in_array( $ns, $namespaces, true ) ) {
                $found[] = $name;
            }
        }
        sort( $found );

        return [ 'present' => [] !== $found, 'count' => count( $found ), 'examples' => array_slice( $found, 0, 3 ) ];
    }

    /** What this site has right now. Never throws; without the Abilities API it reports nothing found. @return array{present:bool, count:int, examples:list<string>} */
    public static function current(): array
    {
        if ( ! function_exists( 'wp_get_abilities' ) ) {
            return self::detect( [] );
        }
        $namespaces = apply_filters( 'jhmg_aied_divi_mcp_namespaces', self::NAMESPACES );
        $namespaces = is_array( $namespaces ) ? array_values( array_filter( $namespaces, 'is_string' ) ) : self::NAMESPACES;
        try {
            $names = array_map( static fn ( $ability ): string => $ability->get_name(), wp_get_abilities() );
        } catch ( \Throwable $e ) {
            return self::detect( [] );
        }

        return self::detect( $names, $namespaces );
    }
}
