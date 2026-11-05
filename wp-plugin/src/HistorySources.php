<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Where a change to a Divi page came from, and which of those sources keep an undo point. Pure: no hooks, no database.
 *
 * Page history used to record only this plugin's own saves. HistoryCapture now records the previous content of a Divi page
 * before any save, whatever made it, and labels it with one of these sources. The label is stored in the history row's `tool`
 * column (and the name of an ability or application password in `label`); a snapshot from this plugin's own tools keeps the
 * tool name it always had, so nothing already stored changes meaning.
 */
final class HistorySources
{
    public const OPTION = 'ai_editor_divi5_history_sources';

    public const ABILITY              = 'ability';
    public const APPLICATION_PASSWORD = 'application_password';
    public const REST                 = 'rest';
    public const BUILDER              = 'builder';
    public const UNKNOWN              = 'unknown';

    /** @var array<string,bool> which sources keep an undo point by default. WordPress revisions already cover builder saves. */
    public const DEFAULTS = [
        self::ABILITY              => true,
        self::APPLICATION_PASSWORD => true,
        self::REST                 => true,
        self::BUILDER              => false,
        self::UNKNOWN              => true,
    ];

    /** The REST namespace Divi's own interface saves through (verified on Divi 5.15.1: /divi/v1/sync-to-server). */
    public const DIVI_REST_PREFIX = '/divi/v1/';

    /** admin-ajax action of Divi's older builder save. */
    public const DIVI_AJAX_SAVE = 'et_fb_ajax_save';

    /** @return array<string,string> source => plain-English name, for the settings screen */
    public static function names(): array
    {
        return [
            self::ABILITY              => 'An AI agent calling an ability (for example Divi\'s own MCP)',
            self::APPLICATION_PASSWORD => 'A tool signed in with an application password',
            self::REST                 => 'Other requests to the WordPress REST API',
            self::BUILDER              => 'Divi\'s visual builder (WordPress revisions already cover these)',
            self::UNKNOWN              => 'Anything else (other plugins, WP-CLI, the classic editor)',
        ];
    }

    /**
     * @return array<string,bool> one entry per source, every missing or malformed value replaced by its default
     */
    public static function normalize( mixed $raw ): array
    {
        $out = self::DEFAULTS;
        if ( is_array( $raw ) ) {
            foreach ( self::DEFAULTS as $type => $default ) {
                if ( array_key_exists( $type, $raw ) ) {
                    $out[ $type ] = filter_var( $raw[ $type ], FILTER_VALIDATE_BOOLEAN );
                }
            }
        }

        return $out;
    }

    /** @return array<string,bool> */
    public static function settings(): array
    {
        return self::normalize( get_option( self::OPTION, [] ) );
    }

    /** Stores the owner's choice. Called only from the nonce-protected Settings form, never by an AI tool. @param array<string,mixed> $chosen */
    public static function save( array $chosen ): void
    {
        update_option( self::OPTION, self::normalize( $chosen ), false );
    }

    public static function enabled( string $type ): bool
    {
        return self::settings()[ $type ] ?? false;
    }

    /**
     * Decides the source from what the request looked like.
     *
     * Order matters: an ability call is the most specific thing known (the request may also carry an application password),
     * then Divi's own interface, then an application password, then any other REST request. Nothing else is guessed: a save
     * that matches none of these is "unknown".
     *
     * @param array{ability?:?string, route?:?string, app_password?:?string, ajax_action?:?string} $context
     * @return array{type:string, label:?string}
     */
    public static function classify( array $context ): array
    {
        $ability = self::text( $context['ability'] ?? null );
        $route   = (string) ( $context['route'] ?? '' );
        $app     = self::text( $context['app_password'] ?? null );
        $ajax    = (string) ( $context['ajax_action'] ?? '' );

        if ( null !== $ability ) {
            return [ 'type' => self::ABILITY, 'label' => null !== $app ? $ability . ' (via ' . $app . ')' : $ability ];
        }
        if ( str_starts_with( $route, self::DIVI_REST_PREFIX ) || self::DIVI_AJAX_SAVE === $ajax ) {
            return [ 'type' => self::BUILDER, 'label' => null ];
        }
        if ( null !== $app ) {
            return [ 'type' => self::APPLICATION_PASSWORD, 'label' => $app ];
        }
        if ( '' !== $route ) {
            return [ 'type' => self::REST, 'label' => null ];
        }

        return [ 'type' => self::UNKNOWN, 'label' => null ];
    }

    /** True for the values classify() produces; false for a tool name of this plugin ("update_page_layout"). */
    public static function isSource( string $tool ): bool
    {
        return array_key_exists( $tool, self::DEFAULTS );
    }

    /** One line for the Dashboard: where a saved version came from. */
    public static function describe( string $tool, ?string $label = null ): string
    {
        $label = null === $label || '' === trim( $label ) ? null : trim( $label );

        return match ( $tool ) {
            self::ABILITY              => 'AI agent' . ( null !== $label ? ': ' . $label : '' ),
            self::APPLICATION_PASSWORD => 'Application password' . ( null !== $label ? ': ' . $label : '' ),
            self::REST                 => 'REST API',
            self::BUILDER              => 'Divi builder',
            self::UNKNOWN              => 'Unknown source',
            default                    => $tool,
        };
    }

    /** A single-line, plain label: tags and control characters removed, cut to a safe length. Null when nothing is left. */
    private static function text( mixed $value ): ?string
    {
        if ( ! is_string( $value ) ) {
            return null;
        }
        $value = trim( (string) preg_replace( '/[\x00-\x1F\x7F<>]+/', ' ', $value ) );

        return '' === $value ? null : mb_substr( $value, 0, 120 );
    }
}
