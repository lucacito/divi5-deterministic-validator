<?php

declare(strict_types=1);

// Only the free plugin's tests load this: the Pro suite has its own is_user_logged_in() that reads __wp_logged_in.
namespace {
    if ( ! function_exists( 'is_user_logged_in' ) ) {
        function is_user_logged_in()
        {
            return ( $GLOBALS['__wp_current_user_id'] ?? 7 ) > 0;
        }
    }
}
