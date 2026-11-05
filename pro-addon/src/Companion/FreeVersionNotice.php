<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Companion;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tells the site owner, in the admin, when the free plugin is too old for the Pro features that need it, instead of those
 * features silently not appearing. Every other Pro tool keeps working; the notice only explains what is off and what to do.
 */
final class FreeVersionNotice
{
    /** The notice text, or null when nothing needs saying (the version is unknown or recent enough). */
    public static function message( ?string $freeVersion ): ?string
    {
        if ( null === $freeVersion || '' === $freeVersion || version_compare( $freeVersion, ProAbilities::MIN_FREE, '>=' ) ) {
            return null;
        }

        return sprintf(
            'JHMG AI Editor for Divi 5 Pro: please update the free plugin "JHMG AI Editor for Divi 5" to version %1$s or newer (you have %2$s). Until you do, these Pro features are off: the Pro abilities for the WordPress Abilities API, undo for a batch of changes made by any tool, and strict validation. Every other Pro tool keeps working.',
            ProAbilities::MIN_FREE,
            $freeVersion
        );
    }

    public function register(): void
    {
        add_action( 'admin_notices', [ $this, 'render' ] );
    }

    public function render(): void
    {
        if ( ! current_user_can( 'update_plugins' ) || ! defined( 'AI_EDITOR_DIVI5_VERSION' ) ) {
            return;
        }
        $message = self::message( (string) AI_EDITOR_DIVI5_VERSION );
        if ( null === $message ) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html( $message ),
            esc_url( admin_url( 'plugins.php' ) ),
            esc_html__( 'Go to Plugins', 'jhmg-ai-editor-for-divi-5-pro' )
        );
    }
}
