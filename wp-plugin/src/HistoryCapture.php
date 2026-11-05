<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/HistorySources.php';
require_once __DIR__ . '/EditTarget.php';

/**
 * Keeps an undo point for a Divi page whoever changes it: Divi's own MCP, another plugin's tool, an application password, the
 * REST API.
 *
 * It listens to `pre_post_update`, the one place where core still holds the OLD content, and snapshots it through
 * HistoryService, the same store and limits (last 10 per page, 512 KB each) as this plugin's own saves. It never changes,
 * delays or blocks a save, and it reads no credential: for an application password it keeps only the name the user gave it.
 *
 * A save is skipped, cheapest check first, when: this plugin's own write path is running (it snapshots for itself), the
 * post is not a page or Divi Project, the content did not change, the old content is not Divi 5 markup, or the source is
 * switched off in the settings.
 *
 * The ability behind a save is found by looking up the call stack for the running WP_Ability, not by remembering a name between
 * two hooks: core does not fire `wp_after_execute_ability` when an ability fails, so a remembered name could go stale and
 * be credited to a later, unrelated save. The stack is read only when a Divi page's content is really about to change.
 */
final class HistoryCapture
{
    private static int $suspended = 0;

    /** @var array{route:?string, app_password:?string} */
    private static array $context = [ 'route' => null, 'app_password' => null ];

    public static function register(): void
    {
        add_action( 'pre_post_update', [ self::class, 'onPreUpdate' ], 10, 2 );
        add_filter( 'rest_pre_dispatch', [ self::class, 'onRestPreDispatch' ], 0, 3 );
        add_action( 'application_password_did_authenticate', [ self::class, 'onApplicationPassword' ], 10, 2 );
    }

    /** Runs $fn with capture off: the caller snapshots for itself (HistoryService). Always switches capture back on. */
    public static function suspend( callable $fn ): mixed
    {
        ++self::$suspended;
        try {
            return $fn();
        } finally {
            --self::$suspended;
        }
    }

    /** True while this plugin's own write path (which snapshots and validates for itself) is saving. */
    public static function isSuspended(): bool
    {
        return self::$suspended > 0;
    }

    /**
     * Where the save that is happening right now came from, by the same rules that label a snapshot.
     *
     * @return array{type:string, label:?string}
     */
    public static function currentSource(): array
    {
        return HistorySources::classify( self::requestContext() );
    }

    public static function reset(): void
    {
        self::$suspended = 0;
        self::$context   = [ 'route' => null, 'app_password' => null ];
    }

    /** `rest_pre_dispatch`: remember which route this request is for, then pass the value through untouched. */
    public static function onRestPreDispatch( mixed $result, mixed $server = null, mixed $request = null ): mixed
    {
        if ( is_object( $request ) && method_exists( $request, 'get_route' ) ) {
            self::$context['route'] = (string) $request->get_route();
        }

        return $result;
    }

    /** `application_password_did_authenticate`: keep only the name of the application password. */
    public static function onApplicationPassword( mixed $user = null, mixed $item = null ): void
    {
        if ( is_array( $item ) && isset( $item['name'] ) && is_string( $item['name'] ) ) {
            self::$context['app_password'] = $item['name'];
        }
    }

    /** The name of the ability whose execute() is running right now, or null. Reads the call stack; keeps nothing. */
    public static function runningAbility(): ?string
    {
        if ( ! class_exists( '\WP_Ability', false ) ) {
            return null;
        }
        foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 60 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- the only way to know which ability is running
            if ( isset( $frame['object'] ) && $frame['object'] instanceof \WP_Ability && in_array( $frame['function'] ?? '', [ 'execute', 'do_execute' ], true ) ) {
                return $frame['object']->get_name();
            }
        }

        return null;
    }

    /**
     * `pre_post_update`: snapshot the content a save is about to replace.
     *
     * @param array<string,mixed> $data the unslashed post data about to be written
     */
    public static function onPreUpdate( mixed $postId = 0, mixed $data = [] ): void
    {
        if ( self::$suspended > 0 || ! is_int( $postId ) || $postId <= 0 || ! is_array( $data ) || ! array_key_exists( 'post_content', $data ) ) {
            return;
        }
        $old = get_post( $postId );
        if ( ! is_object( $old ) || ! in_array( (string) ( $old->post_type ?? '' ), EditTarget::ALLOWED_TYPES, true ) ) {
            return;
        }
        $before = (string) ( $old->post_content ?? '' );
        if ( $before === (string) $data['post_content'] || ! str_contains( $before, '<!-- wp:divi/' ) ) {
            return;
        }

        $source = HistorySources::classify( self::requestContext() );
        if ( ! HistorySources::enabled( $source['type'] ) ) {
            return;
        }
        HistoryService::capture( $postId, $before, $source['type'], $source['label'] );
    }

    /** @return array{ability:?string, route:?string, app_password:?string, ajax_action:?string} */
    private static function requestContext(): array
    {
        $ajax = null;
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- only compared to a fixed string, never used
            $ajax = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : null;
        }

        return self::$context + [ 'ability' => self::runningAbility(), 'ajax_action' => $ajax ];
    }
}
