<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Settings\CustomCss;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Site-wide CSS in one managed block of Additional CSS. CSS cannot execute code; the block is validated and undoable. */
final class SetCustomCss implements ProTool
{
    public const NAME = 'set_custom_css';

    public function __construct( private BatchLog $log )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_css';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Add site-wide CSS inside one marked block of WordPress Additional CSS. The owner\'s own CSS outside the block is never touched. Replaces the block on each call. Undoable. dry_run=true previews.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'css'     => [ 'type' => 'string', 'description' => 'Plain CSS to apply site-wide (no HTML tags)' ],
                    'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'css' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $css = trim( (string) Args::string( $arguments, 'css', true, CustomCss::MAX_BYTES ), " \t\n\r" );
        $dry = Args::bool( $arguments, 'dry_run' );
        $why = CustomCss::problem( $css );
        if ( null !== $why ) {
            throw new \InvalidArgumentException( $why );
        }

        $all    = (string) wp_get_custom_css();
        $before = CustomCss::extract( $all );
        $bytes  = strlen( $css );
        if ( $before === $css ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'bytes' => $bytes, 'message' => 'The managed CSS block already holds exactly this CSS; nothing changed.' ];
        }
        if ( $dry ) {
            return [ 'changed' => false, 'dry_run' => true, 'bytes' => $bytes, 'would_change' => [ 'from_bytes' => null === $before ? null : strlen( $before ), 'to_bytes' => $bytes ] ];
        }

        $saved = wp_update_custom_css_post( CustomCss::apply( $all, $css ) );
        if ( is_wp_error( $saved ) ) {
            throw new \RuntimeException( $saved->get_error_message() );
        }
        // Hash what is stored now, not what was sent: WordPress may normalise the CSS on save.
        $batch = $this->log->record(
            self::NAME,
            "Set the managed custom CSS block ({$bytes} bytes)",
            [ Item::make( 'custom_css', 'custom_css', 'Managed Additional CSS block', $before, CustomCss::extract( (string) wp_get_custom_css() ) ) ]
        );

        return BatchLog::attach(
            [ 'changed' => true, 'dry_run' => false, 'bytes' => $bytes, 'message' => 'Custom CSS applied site-wide (Appearance > Customize > Additional CSS, inside the marked block).' ],
            $batch
        );
    }
}
