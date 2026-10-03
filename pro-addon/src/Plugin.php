<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

use AiEditorDivi5\Pro\Batch\BatchTable;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Boots the add-on, but only against a free plugin it understands. */
final class Plugin
{
    /** plugins_loaded priority 20: the free plugin has already loaded its classes by then. */
    public static function boot(): void
    {
        self::bootWith(
            defined( 'AI_EDITOR_DIVI5_VERSION' ) ? (string) AI_EDITOR_DIVI5_VERSION : null,
            static fn ( string $class ): bool => class_exists( $class )
        );
    }

    /**
     * @param callable(string):bool $classExists
     * @return bool whether the add-on was wired up
     */
    public static function bootWith( ?string $freeVersion, callable $classExists ): bool
    {
        $check = Compat::check( $freeVersion, $classExists );
        if ( ! $check['ok'] ) {
            if ( is_admin() ) {
                add_action( 'admin_notices', static function () use ( $check ): void {
                    printf(
                        '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                        esc_html( 'JHMG AI Editor for Divi 5 Pro' ),
                        esc_html( $check['message'] )
                    );
                } );
            }

            return false;
        }
        self::wire();

        return true;
    }

    /** Everything that attaches to the free plugin or to WordPress; only reached when the free plugin is compatible. */
    private static function wire(): void
    {
        add_filter( 'pre_set_site_transient_update_plugins', [ Licensing::client(), 'inject_update' ] );
        add_action( 'admin_init', static function (): void {
            Licensing::refresh();
        } );
        add_action( 'admin_notices', static function (): void {
            if ( 'ai-editor-divi5' === sanitize_key( wp_unslash( (string) ( $_GET['page'] ?? '' ) ) ) ) {
                Licensing::client()->status_notice();
            }
        } );

        $registry = new ToolRegistry( Tools::all(), static fn (): bool => Licensing::isPremium() );
        $registry->register();

        ( new ProPage( $registry ) )->register();

        add_action( 'init', [ BatchTable::class, 'ensureReady' ] );
        add_filter( 'wpmu_drop_tables', [ BatchTable::class, 'dropTablesFilter' ] );
    }
}
