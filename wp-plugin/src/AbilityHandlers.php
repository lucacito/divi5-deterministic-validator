<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/DesignCheck.php';
require_once __DIR__ . '/DesignLanguages.php';
require_once __DIR__ . '/SectionRecipes.php';

/**
 * The abilities that are not a plain MCP tool: get-section-recipes (two extra filters), get-design-languages,
 * get-variety-advice and check-design. All read-only; none writes anything.
 */
final class AbilityHandlers
{
    /** Layout markup above this size is refused (the same ceiling the other design checks use). */
    public const MAX_CONTENT_BYTES = 524288;

    /**
     * @param array<string,mixed> $args already validated against the ability's input schema
     * @return array<string,mixed>|WP_Error
     */
    public static function run( string $name, array $args ): array|WP_Error
    {
        return match ( $name ) {
            'get-section-recipes' => self::sectionRecipes( $args ),
            'get-design-languages' => self::designLanguages( $args ),
            'get-variety-advice'  => self::varietyAdvice( $args ),
            'check-design'        => self::checkDesign( $args ),
            default               => new WP_Error( 'jhmg_unknown_ability', 'Unknown ability.', [ 'status' => 404 ] ),
        };
    }

    /** @param array<string,mixed> $args @return array<string,mixed>|WP_Error */
    public static function sectionRecipes( array $args ): array|WP_Error
    {
        $str = static fn ( string $key ): string => isset( $args[ $key ] ) && is_string( $args[ $key ] ) ? $args[ $key ] : '';
        if ( '' !== $str( 'name' ) ) {
            $call = [ 'name' => $str( 'name' ) ];
            foreach ( [ 'design_language', 'style_kit' ] as $key ) {
                $call[ $key ] = $str( $key );
            }
            // The tool needs every filter present as a string; a named recipe ignores role, layout and mood.
            $call += [ 'role' => '', 'layout' => '', 'mood' => '' ];

            return Abilities::fromToolResult( ( new McpHandler() )->callTool( 'get_section_recipes', $call ) );
        }
        $problems = [];
        $rows     = SectionRecipes::summaries( $str( 'role' ), $str( 'layout' ), $str( 'mood' ), $str( 'density' ), $str( 'stage' ), $problems );
        if ( null === $rows ) {
            return new WP_Error( 'jhmg_invalid_filter', implode( ' ', $problems ), [ 'status' => 400 ] );
        }

        return [
            'count'   => count( $rows ),
            'recipes' => $rows,
            'next'    => 'Pass name to get one recipe\'s Divi 5 markup, with design_language and style_kit to have it resolved to a look.',
        ];
    }

    /** @param array<string,mixed> $args @return array<string,mixed>|WP_Error */
    public static function designLanguages( array $args ): array|WP_Error
    {
        $id = isset( $args['id'] ) && is_string( $args['id'] ) ? trim( $args['id'] ) : '';
        if ( '' !== $id ) {
            $one = DesignLanguages::get( $id );
            if ( null === $one ) {
                return new WP_Error( 'jhmg_unknown_language', 'Unknown design language "' . SectionRecipes::plain( $id ) . '". Valid ids: ' . implode( ', ', DesignLanguages::ids() ) . '.', [ 'status' => 400 ] );
            }

            return [ 'count' => 1, 'languages' => [ self::languageRow( $one ) ] ];
        }
        $rows = array_map( static fn ( string $lid ): array => self::languageRow( (array) DesignLanguages::get( $lid ) ), DesignLanguages::ids() );

        return [ 'count' => count( $rows ), 'languages' => $rows ];
    }

    /**
     * One language with its contrast-checked pairings computed from its own colors.
     *
     * @param array<string,mixed> $language
     * @return array<string,mixed>
     */
    public static function languageRow( array $language ): array
    {
        $colors  = (array) $language['colors'];
        $pairs   = [ [ 'text', 'bg' ], [ 'text', 'surface' ], [ 'muted', 'bg' ], [ 'muted', 'surface' ], [ 'accent_text', 'accent' ], [ 'inverse', 'text' ] ];
        $pairing = [];
        foreach ( $pairs as [ $fg, $bg ] ) {
            if ( isset( $colors[ $fg ], $colors[ $bg ] ) ) {
                $ratio     = DesignLanguages::contrast( (string) $colors[ $fg ], (string) $colors[ $bg ] );
                $pairing[] = [ 'text' => $fg, 'on' => $bg, 'ratio' => round( $ratio, 2 ), 'passes_aa' => $ratio >= 4.5 ];
            }
        }
        $language['pairings'] = $pairing;
        $language['avoid']    = $language['avoids'] ?? [];

        return $language;
    }

    /** @param array<string,mixed> $args @return array<string,mixed>|WP_Error */
    public static function varietyAdvice( array $args ): array|WP_Error
    {
        $content = self::content( $args );
        if ( $content instanceof WP_Error ) {
            return $content;
        }
        $advice = DesignVariety::advise( $content );
        if ( null === $advice ) {
            return [
                'applicable' => false,
                'findings'   => [],
                'message'    => 'No repetition found, or the page has fewer than ' . DesignVariety::MIN_SECTIONS . ' sections and is not judged.',
            ];
        }

        return [ 'applicable' => true ] + $advice;
    }

    /** @param array<string,mixed> $args @return array<string,mixed>|WP_Error */
    public static function checkDesign( array $args ): array|WP_Error
    {
        $content = self::content( $args );
        if ( $content instanceof WP_Error ) {
            return $content;
        }

        return DesignCheck::run( $content, StyleReferences::knownIds() );
    }

    /**
     * The layout to look at: post_content, or the stored content of a page the user may edit. Exactly one of the two.
     *
     * @param array<string,mixed> $args
     */
    private static function content( array $args ): string|WP_Error
    {
        $hasContent = isset( $args['post_content'] ) && is_string( $args['post_content'] ) && '' !== $args['post_content'];
        $hasPage    = isset( $args['page_id'] ) && (int) $args['page_id'] > 0;
        if ( $hasContent === $hasPage ) {
            return new WP_Error( 'jhmg_invalid_params', 'Pass exactly one of post_content or page_id.', [ 'status' => 400 ] );
        }
        if ( $hasContent ) {
            $content = (string) $args['post_content'];
        } else {
            $id     = (int) $args['page_id'];
            $target = EditTarget::resolve( $id );
            if ( EditTarget::NOT_FOUND === $target['error'] ) {
                return new WP_Error( 'jhmg_not_found', "Page or Project {$id} not found.", [ 'status' => 404 ] );
            }
            if ( EditTarget::FORBIDDEN === $target['error'] ) {
                return new WP_Error( 'jhmg_forbidden', "You do not have permission to access page or Project {$id}.", [ 'status' => 403 ] );
            }
            $content = (string) $target['post']->post_content;
        }
        if ( strlen( $content ) > self::MAX_CONTENT_BYTES ) {
            return new WP_Error( 'jhmg_too_large', 'The layout is larger than ' . self::MAX_CONTENT_BYTES . ' bytes.', [ 'status' => 400 ] );
        }

        return $content;
    }
}
