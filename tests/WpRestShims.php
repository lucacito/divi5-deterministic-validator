<?php

declare(strict_types=1);

/**
 * The few WordPress REST/user shims needed to drive the real RestController and McpHandler
 * handlers in a unit test. Required only by the tests that need them; every definition is
 * guarded so it never clashes with another shim.
 */

if ( ! class_exists( 'WP_Post' ) ) {
    class WP_Post {
        public int $ID = 0;
        public string $post_type = 'page';
        public string $post_status = 'publish';
        public string $post_title = '';
        public string $post_content = '';
    }
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        /**
         * @param array<string,mixed>|null $json what WordPress parsed from a JSON body (null: no JSON, as for a body sent without a JSON Content-Type)
         * @param array<string,mixed> $params
         * @param string|null $body the raw body (default: the JSON encoded again, or empty)
         */
        public function __construct( private ?array $json = [], private array $params = [], private ?string $body = null ) {}
        public function get_json_params() { return $this->json; }
        public function get_body() { return $this->body ?? ( [] === $this->json || null === $this->json ? '' : json_encode( $this->json ) ); }
        public function get_param( $key ) { return $this->params[ $key ] ?? null; }
        public function get_header( $key ) { return null; }
    }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public function __construct( private $data = null, private int $status = 200 ) {}
        public function get_data() { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap, ...$args ) {
        if ( isset( $GLOBALS['__wp_caps'] ) && is_array( $GLOBALS['__wp_caps'] ) ) {
            $perId = $cap . ':' . ( $args[0] ?? '' );
            if ( array_key_exists( $perId, $GLOBALS['__wp_caps'] ) ) {
                return (bool) $GLOBALS['__wp_caps'][ $perId ]; // per-id override, e.g. 'edit_post:202' => false
            }

            return (bool) ( $GLOBALS['__wp_caps'][ $cap ] ?? false ); // per-capability map: anything not listed is denied
        }

        return $GLOBALS['__wp_can'] ?? true;
    }
}
if ( ! function_exists( 'get_the_title' ) ) {
    function get_the_title( $post = 0 ) { return is_object( $post ) ? $post->post_title : 'Title'; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    // Like WordPress: script and style elements are removed with their content, then the remaining tags are stripped.
    function sanitize_text_field( $s ) { return trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ); }
}
if ( ! function_exists( 'wp_hash' ) ) {
    function wp_hash( $s ) { return hash( 'sha256', (string) $s ); }
}
