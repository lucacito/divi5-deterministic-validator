<?php
/**
 * Real-site check for the custom-field advisory (backlog 2b): a layout that binds Divi dynamic content to a custom field the
 * site does not have gets `advisories.style_references.unknown_custom_fields` from the REAL REST and MCP handlers, fields the
 * site has (an ACF field, an ACF group sub field, a plain post meta key) are not flagged, and Pro's audit_site reports the
 * same finding. It also proves the plugin only READS Divi's presets, colors and variables (byte-identical afterwards).
 *
 * Needs the free ACF plugin active on the dev site (it is NOT part of the dev environment):
 *   docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin install advanced-custom-fields --activate
 * Run: make verify-acf-custom-fields
 * Afterwards remove ACF again: deactivate it, delete its folder (never `wp plugin delete`) and its acf_* options.
 * Creates (and always deletes) one ACF field group and a few throwaway pages. Dev sites only (scripts/dev-guard.php).
 */

use AiEditorDivi5\WP\CustomFields;

if ( ! class_exists( CustomFields::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (4.7.0 or later) on this site first.' );
}
if ( ! function_exists( 'acf_import_field_group' ) ) {
    WP_CLI::error( 'Activate the free ACF plugin (advanced-custom-fields) first; see the header of this script.' );
}
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$bind   = static fn ( string $field ): string => '$variable(' . wp_json_encode( [ 'type' => 'content', 'value' => [ 'name' => 'post_meta_key', 'settings' => [ 'before' => '', 'after' => '', 'select_meta_key' => 'custom_meta_' . $field, 'meta_key' => '', 'date_format' => 'default', 'custom_date_format' => '', 'enable_html' => 'off' ] ] ] ) . ')$';
$layout = static function ( array $fields ) use ( $bind ): string {
    $blocks = '';
    foreach ( $fields as $f ) {
        $blocks .= '<!-- wp:divi/text ' . serialize_block_attributes( [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => $bind( $f ) ] ] ], 'builderVersion' => '5.14.0' ] ) . ' /-->';
    }

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} -->' . $blocks . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};
$rest = static function ( string $method, string $route, array $body = [] ): array {
    $req = new WP_REST_Request( $method, '/ai-editor-divi5/v1' . $route );
    $req->set_header( 'content-type', 'application/json' );
    if ( [] !== $body ) {
        $req->set_body( wp_json_encode( $body ) );
    }
    $res = rest_do_request( $req );

    return [ 'status' => $res->get_status(), 'data' => (array) $res->get_data() ];
};
$mcp = static function ( string $tool, array $args ): array {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => (object) $args ] ] ) );
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    $res                           = rest_do_request( $req );
    unset( $_SERVER['HTTP_AUTHORIZATION'] );
    wp_set_current_user( 1 );
    $data = (array) $res->get_data();
    $text = $data['result']['content'][0]['text'] ?? '';

    return [ 'isError' => (bool) ( $data['result']['isError'] ?? false ), 'text' => (string) $text, 'result' => (array) json_decode( (string) $text, true ) ];
};
$fingerprint = static function (): string {
    $divi = (array) get_option( 'et_divi', [] );

    return md5( serialize( [ get_option( 'et_divi_builder_global_presets_d5', null ), get_option( 'et_divi_global_variables', null ), $divi['et_global_data'] ?? null ] ) );
};
$ids = static fn ( array $payload ): array => array_map( static fn ( array $r ): string => (string) $r['id'], (array) ( $payload['advisories']['style_references']['unknown_custom_fields'] ?? [] ) );

$fpBefore = $fingerprint();
$pages    = [];
try {
    acf_import_field_group( [ 'key' => 'group_aied_verify', 'title' => 'AIED verify group', 'fields' => [
        [ 'key' => 'field_aied_v_known', 'label' => 'Known', 'name' => 'aied_known', 'type' => 'text' ],
        [ 'key' => 'field_aied_v_grp', 'label' => 'Grp', 'name' => 'aied_grp', 'type' => 'group', 'sub_fields' => [ [ 'key' => 'field_aied_v_sub', 'label' => 'Sub', 'name' => 'aied_sub', 'type' => 'text' ] ] ],
    ], 'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ] ] ], 'active' => true ] );

    // A throwaway draft page holding a plain (non-ACF) meta key, which also serves as the page the writes are previewed on.
    $pid = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied acf verify', 'post_content' => wp_slash( $layout( [ 'aied_known' ] ) ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on', 'aied_plain_meta' => 'x' ] ], true );
    if ( is_wp_error( $pid ) ) {
        throw new RuntimeException( $pid->get_error_message() );
    }
    $pages[] = (int) $pid;
    $check( true, 'a throwaway draft page exists' );

    $all   = $layout( [ 'aied_known', 'aied_grp_aied_sub', 'aied_plain_meta', 'aied_missing_zzz' ] );
    $clean = $layout( [ 'aied_known', 'aied_grp_aied_sub', 'aied_plain_meta' ] );

    // 1. validate over REST and MCP
    $r = $rest( 'POST', '/validate', [ 'post_content' => $all ] );
    $check( 200 === $r['status'] && true === ( $r['data']['valid'] ?? null ), 'REST validate: the layout is valid (a dead field never blocks)' );
    $check( [ 'aied_missing_zzz' ] === $ids( $r['data'] ), 'REST validate: only the missing field is reported (the ACF field, the group sub field and the plain meta key are known)' );
    $m = $mcp( 'validate_layout', [ 'post_content' => $all ] );
    $check( true === ( $m['result']['valid'] ?? null ) && [ 'aied_missing_zzz' ] === $ids( $m['result'] ), 'MCP validate_layout: the same single finding' );
    $r2 = $rest( 'POST', '/validate', [ 'post_content' => $clean ] );
    $check( ! isset( $r2['data']['advisories'] ), 'REST validate: a layout binding only known fields carries no advisory' );

    // 2. a preview and a real save carry it too, and the save still goes through
    $d = $rest( 'PUT', '/pages/' . $pid, [ 'post_content' => $all, 'dry_run' => true ] );
    $check( 200 === $d['status'] && [ 'aied_missing_zzz' ] === $ids( $d['data'] ), 'dry run: the preview carries the advisory' );
    $check( get_post_field( 'post_content', $pid ) === $layout( [ 'aied_known' ] ), 'dry run: the page is untouched' );
    $w = $rest( 'PUT', '/pages/' . $pid, [ 'post_content' => $all ] );
    $check( 200 === $w['status'] && true === ( $w['data']['saved'] ?? null ) && [ 'aied_missing_zzz' ] === $ids( $w['data'] ), 'a real save goes through and still reports the field' );

    // 3. the guide teaches it and the ACF listing names the fields
    $guide = $mcp( 'get_style_guide', [] );
    $text  = $guide['text'];
    $check( str_contains( $text, 'unknown_custom_fields' ) && str_contains( $text, 'custom_meta_FIELD_NAME' ), 'get_style_guide teaches the binding and names the advisory' );
    $s     = $mcp( 'get_site_structure', [ 'include' => [ 'acf' ] ] );
    $names = [];
    foreach ( (array) ( $s['result']['acf']['groups'] ?? [] ) as $g ) {
        foreach ( (array) ( $g['fields'] ?? [] ) as $f ) {
            $names[] = (string) ( $f['name'] ?? '' );
        }
    }
    $check( in_array( 'aied_known', $names, true ), 'get_site_structure lists the ACF field the layout binds' );

    // 4. Pro: audit_site reports the same finding for the saved page
    if ( class_exists( \AiEditorDivi5\Pro\ToolRegistry::class ) ) {
        $registry = new \AiEditorDivi5\Pro\ToolRegistry( \AiEditorDivi5\Pro\Tools::all(), static fn (): bool => true );
        $res      = $registry->claim( null, 'audit_site', [ 'scope' => 'pages', 'cursor' => (int) $pid - 1 ], 1 );
        $payload  = $res instanceof WP_REST_Response ? (array) json_decode( (string) ( ( (array) $res->get_data() )['result']['content'][0]['text'] ?? '' ), true ) : [];
        $found    = false;
        foreach ( (array) ( $payload['findings'] ?? [] ) as $f ) {
            if ( (int) ( $f['id'] ?? 0 ) === (int) $pid && 'UNKNOWN_CUSTOM_FIELD' === ( $f['code'] ?? '' ) && 'aied_missing_zzz' === ( $f['detail'] ?? '' ) ) {
                $found = true;
            }
        }
        $check( $found, 'Pro audit_site reports UNKNOWN_CUSTOM_FIELD for the saved page' );
    } else {
        WP_CLI::log( 'SKIP  Pro add-on is not active: audit_site not checked' );
    }
} finally {
    foreach ( $pages as $p ) {
        wp_delete_post( $p, true );
    }
    foreach ( get_posts( [ 'post_type' => 'acf-field-group', 'post_status' => 'any', 'name' => 'group_aied_verify', 'numberposts' => -1 ] ) as $gp ) {
        $stack = [ $gp->ID ];
        while ( [] !== $stack ) {
            $parent = array_pop( $stack );
            foreach ( get_posts( [ 'post_type' => 'acf-field', 'post_status' => 'any', 'post_parent' => $parent, 'numberposts' => -1 ] ) as $f ) {
                $stack[] = $f->ID;
            }
            wp_delete_post( $parent, true );
        }
    }
}
$left = get_posts( [ 'post_type' => [ 'page', 'acf-field-group', 'acf-field' ], 'post_status' => 'any', 's' => 'aied', 'numberposts' => -1 ] );
$check( [] === $left, 'the throwaway pages and the field group are gone' );
$check( $fpBefore === $fingerprint(), 'Divi presets, global colors and variables are byte-identical afterwards (the plugin only reads)' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'The custom-field advisory is verified on the real REST and MCP handlers (and Pro audit_site).' );
