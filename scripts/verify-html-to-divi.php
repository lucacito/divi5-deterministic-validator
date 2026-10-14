<?php
/**
 * Real-site check for html_to_divi: every HTML fixture is converted and saved as a throwaway DRAFT through the real
 * REST handler (and one through the real MCP handler), read back from the database, and rendered with the real Divi.
 * Proves: the stored content is exactly the validated layout (the < escapes survive wp_slash), the page is a
 * draft with the Divi 5 flags, every word of the source is on the rendered page, and nothing from the source's
 * scripts, handlers or forms is.
 *
 * Run: make verify-html-to-divi   (dev site only; every page it creates is deleted again)
 */

use AiEditorDivi5\WP\HtmlSource;
use AiEditorDivi5\WP\LayoutGate;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

if ( ! class_exists( \AiEditorDivi5\WP\HtmlToDiviService::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (this branch) on this site first.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

$created = [];
$render  = static function ( int $id ): array {
    $post = get_post( $id );
    $notices = [];
    set_error_handler( static function ( int $no, string $str ) use ( &$notices ): bool {
        $notices[] = substr( $str, 0, 160 );

        return true;
    } );
    $html = '';
    $err  = null;
    try {
        query_posts( [ 'p' => $id, 'post_type' => 'page', 'post_status' => 'any' ] );
        if ( have_posts() ) {
            the_post();
        }
        $html = (string) apply_filters( 'the_content', $post->post_content );
    } catch ( Throwable $e ) {
        $err = get_class( $e ) . ': ' . $e->getMessage();
    } finally {
        wp_reset_query();
        restore_error_handler();
    }

    return [ 'html' => $html, 'notices' => array_values( array_unique( $notices ) ), 'error' => $err ];
};
$nows = static fn( string $s ): string => (string) preg_replace( '/\s+/u', '', html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

$fixtures = glob( '/fixtures/html/*.html' );
if ( ! $fixtures ) {
    WP_CLI::error( 'The fixtures are not mounted at /fixtures/html in the wpcli container (see docker-compose.yml).' );
}
sort( $fixtures );

try {
    foreach ( $fixtures as $file ) {
        $name = basename( $file );
        $html = (string) file_get_contents( $file );
        $req  = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/html-to-divi' );
        $req->set_header( 'content-type', 'application/json' );
        $req->set_body( wp_json_encode( [ 'html' => $html, 'create' => true, 'title' => 'aied h2d ' . $name ] ) );
        $res  = rest_do_request( $req );
        $data = json_decode( (string) wp_json_encode( $res->get_data() ), true );

        if ( '11-empty.html' === $name ) {
            $check( 400 === $res->get_status() && ! ( $data['converted'] ?? true ), $name . ': refused with a plain reason (' . ( $data['error'] ?? '' ) . ')' );
            continue;
        }
        $check( 200 === $res->get_status() && ( $data['created'] ?? false ), $name . ': created (status ' . $res->get_status() . ')' );
        if ( ! isset( $data['page']['id'] ) ) {
            continue;
        }
        $id        = (int) $data['page']['id'];
        $created[] = $id;
        $post      = get_post( $id );

        $check( 'draft' === $post->post_status, $name . ': it is a draft' );
        $check( 'on' === get_post_meta( $id, '_et_pb_use_divi_5', true ) && 'on' === get_post_meta( $id, '_et_pb_use_builder', true ), $name . ': it carries the Divi 5 builder flags' );
        $gate = LayoutGate::check( $post->post_content );
        $check( $gate->isValid(), $name . ': the STORED content passes the validator' . ( $gate->isValid() ? '' : ' ' . wp_json_encode( array_map( fn( $v ) => $v->toArray(), $gate->violations() ) ) ) );
        $check( ! preg_match( '/[^\\\\]u003c/', $post->post_content ), $name . ': no corrupted "u003c" (the backslashes survived the save)' );
        $check( false !== strpos( $post->post_content, '\\u003c' ) || false === strpos( $html, '<p' ), $name . ': HTML in text modules is stored escaped' );

        $r = $render( $id );
        $check( null === $r['error'] && array() === $r['notices'], $name . ': Divi renders it without errors or notices' . ( $r['error'] ?? '' ) . implode( '; ', $r['notices'] ) );
        $check( strlen( $r['html'] ) > 100, $name . ': the rendered page is not empty (' . strlen( $r['html'] ) . ' bytes)' );

        $source = HtmlSource::parse( $html );
        if ( null === $source['error'] && $source['body'] instanceof DOMElement ) {
            $clone = $source['body']->cloneNode( true );
            foreach ( iterator_to_array( $clone->getElementsByTagName( 'nav' ) ) as $nav ) {
                $nav->parentNode->removeChild( $nav );
            }
            // The source's plain text is compared as it is (it may contain a literal "<h2>" as text); only the rendered HTML is stripped of tags.
            $expect = (string) preg_replace( '/\s+/u', '', $clone->textContent );
            $got    = $nows( $r['html'] );
            $ok     = '' === $expect || str_contains( $got, $expect );
            $diff   = '';
            if ( ! $ok ) {
                $i = 0;
                $max = min( mb_strlen( $expect ), mb_strlen( $got ) );
                while ( $i < $max && mb_substr( $expect, $i, 1 ) === mb_substr( $got, $i, 1 ) ) {
                    ++$i;
                }
                $diff = ' source: ' . mb_substr( $expect, max( 0, $i - 8 ), 30 ) . ' | rendered: ' . mb_substr( $got, max( 0, $i - 8 ), 30 );
            }
            $check( $ok, $name . ': every word of the source is on the rendered page (' . mb_strlen( $expect ) . ' characters)' . $diff );
        }
        foreach ( [ 'alert(', 'onclick', 'javascript:', 'This script should be removed' ] as $bad ) {
            $check( ! str_contains( $r['html'], $bad ), $name . ': the rendered page has no "' . $bad . '" from the source' );
        }
        $check( ! str_contains( $r['html'], '<!-- wp:divi' ), $name . ': no raw block comments leak into the page' );
    }

    // One through the real MCP handler as well.
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . \AiEditorDivi5\WP\ApiKey::get(); // the MCP route authenticates by key
    $mcp = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $mcp->set_header( 'content-type', 'application/json' );
    $mcp->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'html_to_divi', 'arguments' => [ 'html' => '<h1>MCP check</h1><p>Hello <a href="/x">there</a></p>', 'create' => true, 'title' => 'aied h2d mcp' ] ] ] ) );
    $mres  = rest_do_request( $mcp );
    unset( $_SERVER['HTTP_AUTHORIZATION'] );
    $mdata = json_decode( (string) wp_json_encode( $mres->get_data() ), true );
    $mbody = json_decode( (string) ( $mdata['result']['content'][0]['text'] ?? '{}' ), true );
    $check( ( $mbody['created'] ?? false ) && isset( $mbody['page']['id'] ), 'MCP: created a draft through tools/call' );
    if ( isset( $mbody['page']['id'] ) ) {
        $created[] = (int) $mbody['page']['id'];
    }

    // A preview creates nothing.
    $before = (int) wp_count_posts( 'page' )->draft;
    $prev   = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/html-to-divi' );
    $prev->set_header( 'content-type', 'application/json' );
    $prev->set_body( wp_json_encode( [ 'html' => '<h1>Preview</h1>' ] ) );
    $pres = rest_do_request( $prev );
    $check( 200 === $pres->get_status() && (int) wp_count_posts( 'page' )->draft === $before, 'a preview creates nothing' );

    // A user who cannot publish pages cannot create, only preview.
    $uid = wp_insert_user( [ 'user_login' => 'aied_h2d_contrib', 'user_pass' => wp_generate_password(), 'user_email' => 'aied-h2d@example.test', 'role' => 'contributor' ] );
    if ( is_int( $uid ) ) {
        $orig = get_current_user_id();
        wp_set_current_user( $uid );
        $c = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/html-to-divi' );
        $c->set_header( 'content-type', 'application/json' );
        $c->set_body( wp_json_encode( [ 'html' => '<h1>x</h1>', 'create' => true, 'title' => 'aied h2d contrib' ] ) );
        $cres = rest_do_request( $c );
        $check( in_array( $cres->get_status(), [ 401, 403 ], true ), 'a contributor is refused (' . $cres->get_status() . ')' );
        wp_set_current_user( $orig );
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $uid );
    }
} finally {
    foreach ( $created as $id ) {
        wp_delete_post( $id, true );
    }
}

$left = (int) count( get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 's' => 'aied h2d', 'numberposts' => 50, 'fields' => 'ids' ] ) );
$check( 0 === $left, 'every throwaway page was deleted (' . $left . ' left)' );
$check( false === get_user_by( 'login', 'aied_h2d_contrib' ), 'the throwaway user was deleted' );

if ( $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'html_to_divi converts, saves drafts and renders correctly on the real site.' );
