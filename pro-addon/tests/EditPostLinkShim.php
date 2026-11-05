<?php

declare(strict_types=1);

namespace {
    if ( ! function_exists( 'get_edit_post_link' ) ) {
        function get_edit_post_link( $id = 0, $context = 'display' )
        {
            return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
        }
    }
}
