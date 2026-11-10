<?php
/**
 * Dev-only dogfood helper for the Lab assistant tool (docs/dogfood.md). Called by scripts/dogfood-lab.sh:
 *
 *   wp eval-file /scripts/dogfood-lab-server.php <command> <base64 JSON> --user=1
 *
 * Commands: tools-list (the lab_build entry exactly as an MCP client sees it), call (one tools/call through the REAL MCP route with
 * the plugin's API key, the way an assistant calls it), seed (save a brief for user 1 the way the Lab screen does and print the
 * hand-off instruction the screen would copy), cleanup (delete the pages and briefs a run made, by id, only if their title starts
 * with `aied dogfood lab `). Dev site only (scripts/dev-guard.php). Prints one line `@@AIED@@<json>`.
 */

use AiEditorDivi5\WP\StudioBrief;
use AiEditorDivi5\WP\StudioService;
use AiEditorDivi5\WP\StudioStore;

require __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$cmd = $args[0] ?? '';
$p   = json_decode( (string) base64_decode( (string) ( $args[1] ?? '' ), true ), true );
$p   = is_array( $p ) ? $p : [];
$out = static function ( $data ): void {
    echo '@@AIED@@' . wp_json_encode( $data ) . "\n";
};
$mcp = static function ( string $method, array $params ): array {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params ] ) );
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    $res = rest_do_request( $req );
    unset( $_SERVER['HTTP_AUTHORIZATION'] );
    wp_set_current_user( 1 );

    return (array) $res->get_data();
};

switch ( $cmd ) {
    case 'tools-list':
        $data = $mcp( 'tools/list', [] );
        foreach ( (array) ( $data['result']['tools'] ?? [] ) as $tool ) {
            if ( 'lab_build' === ( $tool['name'] ?? '' ) ) {
                $out( $tool );
                return;
            }
        }
        $out( [ 'error' => 'lab_build is not in tools/list' ] );
        break;

    case 'call':
        $data = $mcp( 'tools/call', [ 'name' => (string) ( $p['tool'] ?? '' ), 'arguments' => (object) ( $p['arguments'] ?? [] ) ] );
        $text = isset( $data['result']['content'][0]['text'] ) ? (string) $data['result']['content'][0]['text'] : '';
        $out( [ 'rpc_error' => $data['error'] ?? null, 'isError' => (bool) ( $data['result']['isError'] ?? false ), 'result' => '' === $text ? null : json_decode( $text, true ) ] );
        break;

    case 'seed':
        $checked = StudioBrief::check( $p['brief'] ?? null );
        if ( ! $checked['ok'] ) {
            $out( [ 'error' => 'the seed brief is invalid', 'problems' => $checked['problems'] ] );
            return;
        }
        $saved = StudioStore::save( 1, (array) $checked['brief'] );
        $hand  = StudioService::handoff( 1, (int) $saved['id'], (string) home_url() );
        $out( [ 'brief_id' => (int) $saved['id'], 'instruction' => $hand['instruction'] ] );
        break;

    case 'cleanup':
        $gone = [ 'pages' => [], 'briefs' => [] ];
        foreach ( (array) ( $p['pages'] ?? [] ) as $id ) {
            $id = (int) $id;
            if ( $id > 0 && str_starts_with( (string) get_the_title( $id ), 'aied dogfood lab ' ) ) {
                wp_delete_post( $id, true );
                $gone['pages'][] = $id;
            }
        }
        foreach ( (array) ( $p['briefs'] ?? [] ) as $id ) {
            $row = StudioStore::get( 1, (int) $id );
            if ( null !== $row && str_starts_with( (string) ( $row['brief']['title'] ?? '' ), 'aied dogfood lab ' ) ) {
                StudioStore::delete( 1, (int) $id );
                $gone['briefs'][] = (int) $id;
            }
        }
        $out( $gone );
        break;

    default:
        WP_CLI::error( 'Unknown command ' . $cmd );
}
