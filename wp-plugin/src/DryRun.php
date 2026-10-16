<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/ViolationReport.php';

/**
 * The one response body a dry run returns, shared by the MCP and REST transports: the checker's
 * verdict on the would-be content plus a diff against the current content. It saves nothing and
 * has no side effects.
 */
final class DryRun
{
    /** Usage-log endpoint name for previews. */
    public const ENDPOINT = 'dry_run';

    public const MESSAGE = 'Preview only. Nothing was saved.';

    /**
     * @param ?array<string,mixed> $title PageTitle::report() when the call also changes the page title
     * @return array<string,mixed>
     */
    public static function preview( string $before, string $after, ?array $title = null ): array
    {
        $verdict = LayoutGate::check( $after, $before );
        $out     = [
            'saved'      => false,
            'dry_run'    => true,
            'valid'      => $verdict->isValid(),
            'violations' => ViolationReport::compact( $verdict->violations() ),
        ] + LayoutDiff::compare( $before, $after );
        if ( null !== $title ) {
            $out['title'] = $title;
        }

        return $out + [ 'message' => self::MESSAGE ];
    }

    /**
     * A title-only preview: the layout is not part of the change, so it is neither checked nor diffed.
     *
     * @param array<string,mixed> $title
     * @return array<string,mixed>
     */
    public static function previewTitle( array $title ): array
    {
        return [
            'saved'          => false,
            'dry_run'        => true,
            'valid'          => true,
            'violations'     => [],
            'layout_changed' => false,
            'diff'           => null,
            'title'          => $title,
            'message'        => self::MESSAGE,
        ];
    }
}
