<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A READ-ONLY view of the live Divi Theme Builder: which templates exist and which header, footer
 * and body layout posts they use. Nothing here writes, creates or publishes anything. The meta keys
 * are the ones Divi's own Theme Builder code stores (verified against the shipped theme).
 */
final class ThemeLayouts
{
    public const HEADER = 'et_header_layout';
    public const FOOTER = 'et_footer_layout';
    public const BODY   = 'et_body_layout';

    private const TEMPLATE = 'et_template';

    /** 'header' / 'footer' for the two editable layout post types, null for everything else. */
    public static function kindOf( string $postType ): ?string
    {
        return match ( $postType ) {
            self::HEADER => 'header',
            self::FOOTER => 'footer',
            default      => null,
        };
    }

    /**
     * Template ids of the LIVE Theme Builder, read through the two given Divi functions. Both must be
     * callable (Divi's Theme Builder present); otherwise the result is empty. The Theme Builder post is
     * requested with $create = false: reading must never create one.
     *
     * @return list<int>
     */
    public static function liveTemplateIdsWith( ?callable $postIdFn, ?callable $idsFn ): array
    {
        if ( null === $postIdFn || null === $idsFn ) {
            return [];
        }
        $themeBuilderId = (int) $postIdFn( true, false );
        if ( $themeBuilderId <= 0 ) {
            return [];
        }

        return array_values( array_filter(
            array_map( 'intval', (array) $idsFn( true, $themeBuilderId ) ),
            static fn ( int $id ): bool => $id > 0
        ) );
    }

    /** @return list<int> */
    public static function liveTemplateIds(): array
    {
        return self::liveTemplateIdsWith(
            function_exists( 'et_theme_builder_get_theme_builder_post_id' ) ? 'et_theme_builder_get_theme_builder_post_id' : null,
            function_exists( 'et_theme_builder_get_theme_builder_template_ids' ) ? 'et_theme_builder_get_theme_builder_template_ids' : null
        );
    }

    /**
     * The live templates with their layouts. A layout entry is null when the template has no usable
     * layout of that kind (id 0, missing, wrong post type or not published).
     *
     * @return list<array<string,mixed>>
     */
    public static function liveTemplates(): array
    {
        $out = [];
        foreach ( self::liveTemplateIds() as $templateId ) {
            $template = get_post( $templateId );
            if ( ! $template || self::TEMPLATE !== $template->post_type || 'publish' !== $template->post_status ) {
                continue;
            }
            $out[] = [
                'template_id'  => $templateId,
                'title'        => (string) $template->post_title,
                'default'      => '1' === (string) get_post_meta( $templateId, '_et_default', true ),
                'enabled'      => '1' === (string) get_post_meta( $templateId, '_et_enabled', true ),
                'use_on'       => self::strings( get_post_meta( $templateId, '_et_use_on', false ) ),
                'exclude_from' => self::strings( get_post_meta( $templateId, '_et_exclude_from', false ) ),
                'header'       => self::layout( $templateId, '_et_header_layout_id', '_et_header_layout_enabled', self::HEADER ),
                'footer'       => self::layout( $templateId, '_et_footer_layout_id', '_et_footer_layout_enabled', self::FOOTER ),
                'body'         => self::layout( $templateId, '_et_body_layout_id', '_et_body_layout_enabled', self::BODY ),
            ];
        }

        return $out;
    }

    /**
     * Layout id => 'header'|'footer' for every layout the plugin may edit (used by a live template).
     *
     * @return array<int,string>
     */
    public static function liveLayoutKinds(): array
    {
        $kinds = [];
        foreach ( self::liveTemplates() as $template ) {
            foreach ( [ 'header', 'footer' ] as $kind ) {
                if ( null !== $template[ $kind ] ) {
                    $kinds[ (int) $template[ $kind ]['layout_id'] ] = $kind;
                }
            }
        }

        return $kinds;
    }

    /**
     * What `list_theme_layouts` returns: absent layouts are omitted, each present layout says whether
     * the current user can edit it, and body layouts are listed but never editable.
     *
     * @return array{templates: list<array<string,mixed>>, count: int, note: ?string}
     */
    public static function listing(): array
    {
        $templates = [];
        foreach ( self::liveTemplates() as $template ) {
            foreach ( [ 'header', 'footer', 'body' ] as $kind ) {
                if ( null === $template[ $kind ] ) {
                    unset( $template[ $kind ] );
                    continue;
                }
                $template[ $kind ]['editable'] = 'body' !== $kind && current_user_can( 'edit_post', (int) $template[ $kind ]['layout_id'] );
            }
            $templates[] = $template;
        }

        return [
            'templates' => $templates,
            'count'     => count( $templates ),
            'note'      => [] === $templates ? 'No live Divi Theme Builder with templates was found, so there is no header or footer to edit.' : null,
        ];
    }

    /** @return array{layout_id:int, title:string, enabled:bool}|null */
    private static function layout( int $templateId, string $idKey, string $enabledKey, string $postType ): ?array
    {
        $layoutId = (int) get_post_meta( $templateId, $idKey, true );
        $post     = $layoutId > 0 ? get_post( $layoutId ) : null;
        if ( ! $post || $postType !== $post->post_type || 'publish' !== $post->post_status ) {
            return null;
        }

        return [
            'layout_id' => $layoutId,
            'title'     => (string) $post->post_title,
            'enabled'   => '1' === (string) get_post_meta( $templateId, $enabledKey, true ),
        ];
    }

    /** @return list<string> */
    private static function strings( mixed $raw ): array
    {
        return array_values( array_filter(
            array_map( 'strval', (array) $raw ),
            static fn ( string $s ): bool => '' !== $s
        ) );
    }
}
