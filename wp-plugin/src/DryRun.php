<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Validator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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

    /** @return array<string,mixed> */
    public static function preview( string $before, string $after ): array
    {
        $verdict = ( new Validator() )->validateContent( $after );

        return [
            'saved'      => false,
            'dry_run'    => true,
            'valid'      => $verdict->isValid(),
            'violations' => array_map( static fn ( $v ) => $v->toArray(), $verdict->violations() ),
        ] + LayoutDiff::compare( $before, $after ) + [ 'message' => self::MESSAGE ];
    }
}
