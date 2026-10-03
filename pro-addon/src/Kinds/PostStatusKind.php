<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\ValueKind;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** A page's status (draft, pending, future, publish). `ref` is the page id. */
final class PostStatusKind extends ValueKind
{
    public function name(): string
    {
        return 'post_status';
    }

    public function current( array $item ): mixed
    {
        $post = get_post( (int) $item['ref'] );

        return $post ? (string) $post->post_status : null;
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'publish_pages' ) && current_user_can( 'edit_post', (int) $item['ref'] );
    }

    protected function write( array $item, mixed $value ): bool
    {
        if ( ! is_string( $value ) || ! in_array( $value, [ 'draft', 'pending', 'future', 'publish' ], true ) ) {
            return false;
        }

        return ! is_wp_error( wp_update_post( [ 'ID' => (int) $item['ref'], 'post_status' => $value ], true ) );
    }
}
