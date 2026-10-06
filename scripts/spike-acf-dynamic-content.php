<?php
/**
 * ONE-OFF evidence spike (backlog 2b, ACF awareness step 2): can a layout bind Divi 5 dynamic content to ACF fields, does our gate
 * accept it, and what does Divi render? Creates (and always deletes) one ACF field group, one attachment and a few throwaway pages.
 * The binding shape is the one in the owner's real Theme Builder export ($variable({"type":"content","value":{"name":"post_meta_key",
 * "settings":{"select_meta_key":"custom_meta_<field>",...}}})$) and the simple form Divi 5.14's own source also reads
 * (name "custom_meta_<field>"). Output: JSON on stdout.
 *
 * Run: docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/spike-acf-dynamic-content.php --user=1
 * Dev sites only (scripts/dev-guard.php). The free plugin and the free ACF plugin must be active. Set AIED_LOOPBACK_BASE if http://wordpress is not reachable.
 */

use AiEditorDivi5\WP\LayoutGate;

if ( ! class_exists( LayoutGate::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 first.' );
}
if ( ! function_exists( 'acf_import_field_group' ) || ! function_exists( 'update_field' ) ) {
    WP_CLI::error( 'Activate the free ACF plugin (advanced-custom-fields) first.' );
}
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$fetch = static function ( string $url ) use ( &$fetch ): string {
    $p    = wp_parse_url( $url );
    $host = ( $p['host'] ?? 'localhost' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
    $path = ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
    $base = rtrim( (string) ( getenv( 'AIED_LOOPBACK_BASE' ) ?: 'http://wordpress' ), '/' );
    $res  = wp_remote_get( $base . $path, [ 'headers' => [ 'Host' => $host ], 'timeout' => 30, 'redirection' => 0, 'sslverify' => false ] );
    if ( is_wp_error( $res ) ) {
        return '';
    }
    $code = (int) wp_remote_retrieve_response_code( $res );
    if ( in_array( $code, [ 301, 302 ], true ) && '' !== (string) wp_remote_retrieve_header( $res, 'location' ) ) {
        return $fetch( (string) wp_remote_retrieve_header( $res, 'location' ) );
    }

    return (string) wp_remote_retrieve_body( $res );
};
$context = static function ( string $haystack, string $needle, int $max = 2 ): array {
    $out = [];
    $off = 0;
    while ( count( $out ) < $max && false !== ( $pos = strpos( $haystack, $needle, $off ) ) ) {
        $out[] = substr( $haystack, max( 0, $pos - 120 ), strlen( $needle ) + 240 );
        $off   = $pos + strlen( $needle );
    }

    return $out;
};

// ---- binding forms ------------------------------------------------------------------------------------------------
// Legacy form: exactly what the owner's real export holds.
$legacy = static fn ( string $field, string $before = '', string $after = '' ): string => '$variable(' . wp_json_encode( [ 'type' => 'content', 'value' => [ 'name' => 'post_meta_key', 'settings' => [ 'before' => $before, 'after' => $after, 'select_meta_key' => 'custom_meta_' . $field, 'meta_key' => '', 'date_format' => 'default', 'custom_date_format' => '', 'enable_html' => 'off' ] ] ] ) . ')$';
// Simple form: what Divi 5.14's own render code calls "preferred".
$simple = static fn ( string $field ): string => '$variable(' . wp_json_encode( [ 'type' => 'content', 'value' => [ 'name' => 'custom_meta_' . $field, 'settings' => new stdClass() ] ] ) . ')$';

$V = '5.14.0';
$wrap = static fn ( string $inner ): string => '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"' . $V . '"} --><!-- wp:divi/row {"builderVersion":"' . $V . '"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"' . $V . '"} -->' . $inner . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
$text   = static fn ( string $value ): string => '<!-- wp:divi/text ' . serialize_block_attributes( [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => $value ] ] ], 'builderVersion' => '5.14.0' ] ) . ' /-->';
$image  = static fn ( string $src ): string => '<!-- wp:divi/image ' . serialize_block_attributes( [ 'image' => [ 'innerContent' => [ 'desktop' => [ 'value' => [ 'src' => $src, 'id' => '', 'alt' => '', 'titleText' => '' ] ] ] ], 'builderVersion' => '5.14.0' ] ) . ' /-->';
$button = static fn ( string $label, string $url ): string => '<!-- wp:divi/button ' . serialize_block_attributes( [ 'button' => [ 'innerContent' => [ 'desktop' => [ 'value' => [ 'text' => $label, 'linkUrl' => $url ] ] ] ], 'builderVersion' => '5.14.0' ] ) . ' /-->';
$blurb  = static fn ( string $title ): string => '<!-- wp:divi/blurb ' . serialize_block_attributes( [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => [ 'text' => $title ] ] ] ], 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '' ] ] ], 'builderVersion' => '5.14.0' ] ) . ' /-->';

$groupId = 0;
$attId   = 0;
$pages   = [];
$out     = [ 'acf_version' => defined( 'ACF_VERSION' ) ? ACF_VERSION : null, 'divi_version' => defined( 'ET_BUILDER_PRODUCT_VERSION' ) ? ET_BUILDER_PRODUCT_VERSION : null ];

try {
    // ---- ACF field group -----------------------------------------------------------------------------------------
    $fields = [
        [ 'key' => 'field_aied_text', 'label' => 'AIED text', 'name' => 'aied_text', 'type' => 'text' ],
        [ 'key' => 'field_aied_textarea', 'label' => 'AIED textarea', 'name' => 'aied_textarea', 'type' => 'textarea' ],
        [ 'key' => 'field_aied_image', 'label' => 'AIED image array', 'name' => 'aied_image', 'type' => 'image', 'return_format' => 'array' ],
        [ 'key' => 'field_aied_image_id', 'label' => 'AIED image id', 'name' => 'aied_image_id', 'type' => 'image', 'return_format' => 'id' ],
        [ 'key' => 'field_aied_image_url', 'label' => 'AIED image url', 'name' => 'aied_image_url', 'type' => 'image', 'return_format' => 'url' ],
        [ 'key' => 'field_aied_url', 'label' => 'AIED url', 'name' => 'aied_url', 'type' => 'url' ],
        [ 'key' => 'field_aied_number', 'label' => 'AIED number', 'name' => 'aied_number', 'type' => 'number' ],
    ];
    $group = acf_import_field_group( [ 'key' => 'group_aied_spike', 'title' => 'AIED spike group', 'fields' => $fields, 'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ] ] ], 'active' => true ] );
    $groupId = (int) ( $group['ID'] ?? 0 );
    $out['field_group_id'] = $groupId;

    // ---- one attachment (a small generated PNG) ------------------------------------------------------------------
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mP8z8Dwn4EIwDiqkL4KAZ7eBAr5C2z1AAAAAElFTkSuQmCC';
    $up  = wp_upload_bits( 'aied-acf-spike.png', null, (string) base64_decode( $png ) );
    if ( ! empty( $up['error'] ) ) {
        throw new RuntimeException( 'upload: ' . $up['error'] );
    }
    $attId = (int) wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'aied acf spike', 'post_status' => 'inherit' ], $up['file'] );
    wp_update_attachment_metadata( $attId, wp_generate_attachment_metadata( $attId, $up['file'] ) );
    $attUrl = (string) wp_get_attachment_url( $attId );
    $out['attachment'] = [ 'id' => $attId, 'url' => $attUrl ];

    // ---- one probe page: layout built per case, same ACF values ---------------------------------------------------
    $textVal = 'AIED-ACF-TEXT-VALUE';
    $areaVal = 'AIED-ACF-AREA-VALUE';
    $linkVal = 'https://example.com/aied-acf-link';
    $probe = static function ( string $label, string $content, array $expect ) use ( &$pages, $fetch, $context, $attId, $textVal, $areaVal, $linkVal ): array {
        $gate = LayoutGate::check( $content );
        $id   = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'aied acf spike ' . $label, 'post_content' => wp_slash( $content ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ], true );
        if ( is_wp_error( $id ) ) {
            throw new RuntimeException( $id->get_error_message() );
        }
        $pages[] = (int) $id;
        update_field( 'field_aied_text', $textVal, $id );
        update_field( 'field_aied_textarea', $areaVal, $id );
        update_field( 'field_aied_image', $attId, $id );
        update_field( 'field_aied_image_id', $attId, $id );
        update_field( 'field_aied_image_url', $attId, $id );
        update_field( 'field_aied_url', $linkVal, $id );
        update_field( 'field_aied_number', 42, $id );
        $html = $fetch( add_query_arg( 'page_id', $id, home_url( '/' ) ) );
        $row  = [
            'gate_valid'      => $gate->isValid(),
            'gate_codes'      => array_values( array_unique( array_map( static fn ( $v ): string => (string) ( $v->toArray()['code'] ?? '' ), $gate->violations() ) ) ),
            'rendered_bytes'  => strlen( $html ),
            'served_page_id'  => preg_match( '/<body[^>]*\bpage-id-(\d+)/', $html, $m ) ? (int) $m[1] : null,
            'probe_page_id'   => (int) $id,
            'token_leaks'     => substr_count( $html, '$variable(' ) + substr_count( $html, 'u0024variable(' ),
            'stored_meta_ok'  => get_post_meta( $id, 'aied_text', true ),
        ];
        foreach ( $expect as $name => $needle ) {
            $row[ 'has_' . $name ] = false !== strpos( $html, $needle );
        }
        $row['rendered'] = [];
        foreach ( [ 'class="et_pb_text_0 et_pb_text ', 'aied-acf-spike.png', 'aied-acf-link', 'et_pb_blurb_0 ' ] as $needle ) {
            $c = $context( $html, $needle, 1 );
            if ( [] !== $c ) {
                $row['rendered'][ $needle ] = preg_replace( '/\s+/', ' ', $c[0] );
            }
        }

        return $row;
    };

    $out['A_text_legacy']      = $probe( 'A', $wrap( $text( $legacy( 'aied_text' ) ) ), [ 'text_value' => $textVal ] );
    $out['B_text_simple']      = $probe( 'B', $wrap( $text( $simple( 'aied_text' ) ) ), [ 'text_value' => $textVal ] );
    $out['C_textarea_legacy']  = $probe( 'C', $wrap( $text( $legacy( 'aied_textarea' ) ) ), [ 'area_value' => $areaVal ] );
    $out['D_before_after']     = $probe( 'D', $wrap( $text( $legacy( 'aied_text', '<h2>Hi ', '!</h2>' ) ) ), [ 'wrapped' => '<h2>Hi ' . $textVal . '!</h2>', 'text_value' => $textVal ] );
    $out['E_image_array']      = $probe( 'E', $wrap( $image( $legacy( 'aied_image' ) ) ), [ 'att_url' => basename( $attUrl ) ] );
    $out['F_image_id']         = $probe( 'F', $wrap( $image( $legacy( 'aied_image_id' ) ) ), [ 'att_url' => basename( $attUrl ) ] );
    $out['G_image_url']        = $probe( 'G', $wrap( $image( $legacy( 'aied_image_url' ) ) ), [ 'att_url' => basename( $attUrl ) ] );
    $out['H_image_simple']     = $probe( 'H', $wrap( $image( $simple( 'aied_image' ) ) ), [ 'att_url' => basename( $attUrl ) ] );
    $out['I_button_link']      = $probe( 'I', $wrap( $button( 'Go', $legacy( 'aied_url' ) ) ), [ 'href' => 'href="' . $linkVal . '"' ] );
    $out['J_button_text']      = $probe( 'J', $wrap( $button( $legacy( 'aied_text' ), 'https://example.com/x' ) ), [ 'text_value' => $textVal ] );
    $out['K_number']           = $probe( 'K', $wrap( $text( $legacy( 'aied_number' ) ) ), [ 'number_value' => '>42<' ] );
    $out['L_blurb_title']      = $probe( 'L', $wrap( $blurb( $legacy( 'aied_text' ) ) ), [ 'text_value' => $textVal ] );
    $out['M_missing_field']    = $probe( 'M', $wrap( $text( $legacy( 'aied_missing_xyz' ) ) ), [ 'text_value' => $textVal ] );
    $out['N_control_literal']  = $probe( 'N', $wrap( $text( 'AIED-LITERAL' ) ), [ 'literal' => 'AIED-LITERAL' ] );
} finally {
    foreach ( $pages as $pid ) {
        wp_delete_post( $pid, true );
    }
    if ( $attId > 0 ) {
        wp_delete_attachment( $attId, true );
    }
    // The field group and its fields (ACF stores them as posts).
    foreach ( [ 'group_aied_spike' ] as $key ) {
        $g = get_posts( [ 'post_type' => 'acf-field-group', 'post_status' => 'any', 'name' => $key, 'numberposts' => -1 ] );
        foreach ( $g as $gp ) {
            foreach ( get_posts( [ 'post_type' => 'acf-field', 'post_status' => 'any', 'post_parent' => $gp->ID, 'numberposts' => -1 ] ) as $f ) {
                wp_delete_post( $f->ID, true );
            }
            wp_delete_post( $gp->ID, true );
        }
    }
    $left = get_posts( [ 'post_type' => [ 'page', 'attachment', 'acf-field-group', 'acf-field' ], 'post_status' => 'any', 's' => 'aied', 'numberposts' => -1 ] );
    $out['leftovers'] = count( $left );
}

echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
