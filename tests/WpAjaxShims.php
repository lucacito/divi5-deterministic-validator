<?php

declare(strict_types=1);

/**
 * Stand-ins for the admin-ajax helpers the Divi 5 Lab (Studio) handlers use. In WordPress wp_send_json_* ends the request;
 * here they throw AiedAjaxResponse so a test can read what would have been sent and so nothing after the call runs.
 * check_ajax_referer answers from $GLOBALS['__ajax_nonce_ok'] (default true) and records the action it was asked for in
 * $GLOBALS['__ajax_nonce_action']. Guarded like every other shim.
 */
if ( ! class_exists( 'AiedAjaxResponse' ) ) {
    final class AiedAjaxResponse extends \RuntimeException
    {
        public function __construct( public bool $success, public mixed $data, public int $status )
        {
            parent::__construct( $success ? 'success' : 'error' );
        }
    }
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null, $status_code = null, $flags = 0 ) {
        throw new AiedAjaxResponse( true, $data, (int) ( $status_code ?? 200 ) );
    }
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null, $status_code = null, $flags = 0 ) {
        throw new AiedAjaxResponse( false, $data, (int) ( $status_code ?? 200 ) );
    }
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
        $GLOBALS['__ajax_nonce_action'] = $action;
        $GLOBALS['__ajax_nonce_arg']    = $query_arg;

        return ( $GLOBALS['__ajax_nonce_ok'] ?? true ) ? 1 : false;
    }
}
