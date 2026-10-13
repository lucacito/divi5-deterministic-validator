<?php
/**
 * Real-site check for list_products and get_product: the REAL REST routes and MCP handler on the Docker site's WooCommerce
 * products. Totals against direct counts, every filter and sort against WooCommerce itself, the four product types, a
 * password-protected product, a product with a download and a purchase note (the privacy sweep), a variation id and a blog
 * post (both look missing), an editor without the product permissions, and the product count in get_site_structure.
 *
 * Run: make verify-products   (needs `make up`, WooCommerce active and the plugin active on the dev site)
 * Creates three throwaway products (SKU AIED-VERIFY-*) and one throwaway user (aied_prod_editor) and always deletes them;
 * the existing products must be byte-identical afterwards. Dev sites only (scripts/dev-guard.php).
 */

use AiEditorDivi5\WP\ProductService;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();
if ( ! function_exists( 'wc_get_products' ) ) {
    WP_CLI::error( 'WooCommerce is not active on this site; activate it first.' );
}
if ( ! class_exists( ProductService::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (with list_products and get_product) on this site first.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! defined( 'AIED_PROD_USER' ) ) {
    define( 'AIED_PROD_USER', 'aied_prod_editor' );
    define( 'AIED_PROD_STATUSES', [ 'publish', 'draft', 'pending', 'private', 'future' ] );
}

$throwawayIds = static function (): array {
    global $wpdb;

    return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' WHERE p.post_type = 'product' AND m.meta_value LIKE %s",
        $wpdb->esc_like( 'AIED-VERIFY-' ) . '%'
    ) ) );
};
$forceDelete = static function ( int $id ): void {
    $p = wc_get_product( $id );
    if ( $p ) {
        $p->delete( true );
    } else {
        wp_delete_post( $id, true );
    }
};

// A previous run that was killed may have left its throwaways behind: remove only those exact ones.
$stale = get_user_by( 'login', AIED_PROD_USER );
if ( $stale && $stale->ID > 1 ) {
    wp_delete_user( $stale->ID );
}
foreach ( $throwawayIds() as $id ) {
    $forceDelete( $id );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$rest = static function ( string $method, string $route, array $params = [] ): array {
    $req = new WP_REST_Request( $method, '/ai-editor-divi5/v1' . $route );
    foreach ( $params as $k => $v ) {
        $req->set_param( $k, $v );
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

    return [ 'error' => $data['error'] ?? null, 'text' => (string) $text, 'result' => (array) json_decode( (string) $text, true ) ];
};
// Every product and variation the site has (any status), as one hash: proof that nothing was written to them.
$snapshot = static function (): string {
    global $wpdb;
    $ids  = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') ORDER BY ID" ) );
    $rows = [];
    foreach ( $ids as $id ) {
        $post = get_post( $id );
        $p    = wc_get_product( $id );
        $rows[] = [
            $id, $post ? $post->post_title : null, $post ? $post->post_status : null,
            $p ? $p->get_regular_price( 'edit' ) : null, $p ? $p->get_sale_price( 'edit' ) : null,
            $p ? $p->get_stock_status( 'edit' ) : null, $p ? $p->get_stock_quantity( 'edit' ) : null,
            $post ? $post->post_modified_gmt : null, $post ? $post->post_password : null,
        ];
    }

    return md5( serialize( $rows ) );
};
$ids        = static fn ( array $r ): array => array_map( 'intval', array_column( (array) ( $r['data']['products'] ?? [] ), 'id' ) );
$sameSet    = static function ( array $a, array $b ): bool {
    sort( $a );
    sort( $b );

    return $a === $b;
};
$directIds  = static fn ( array $q ): array => array_map( 'intval', (array) wc_get_products( $q + [ 'status' => AIED_PROD_STATUSES, 'limit' => -1, 'return' => 'ids' ] ) );
$normalised = static fn ( $v ) => json_decode( (string) wp_json_encode( $v ), true );
$nonIncreasing = static function ( array $values ): bool {
    for ( $i = 1, $n = count( $values ); $i < $n; $i++ ) {
        if ( $values[ $i ] > $values[ $i - 1 ] ) {
            return false;
        }
    }

    return true;
};
$nonDecreasing = static fn ( array $values ): bool => $nonIncreasing( array_reverse( $values ) );

wp_set_current_user( 1 );
$before     = $snapshot();
$origTotals = $rest( 'GET', '/products', [ 'per_page' => '50' ] )['data']['totals'] ?? null;
$check( is_array( $origTotals ), 'the original totals were read (' . (int) ( $origTotals['all'] ?? 0 ) . ' products)' );

$made      = [];
$userId    = 0;
$responses = [];
try {
    // 0. three throwaways (WooCommerce CRUD; a dev script, not plugin code)
    $draft = new WC_Product_Simple();
    $draft->set_name( 'AIED-VERIFY Draft' );
    $draft->set_sku( 'AIED-VERIFY-DRAFT' );
    $draft->set_status( 'draft' );
    $draft->set_regular_price( '11' );
    $made['draft'] = (int) $draft->save();

    $locked = new WC_Product_Simple();
    $locked->set_name( 'AIED-VERIFY Locked' );
    $locked->set_sku( 'AIED-VERIFY-LOCKED' );
    $locked->set_status( 'publish' );
    $locked->set_regular_price( '12' );
    $locked->set_post_password( 'pw' );
    $locked->set_description( 'AIED-SECRET-DESC' );
    $locked->set_short_description( 'AIED-SECRET-DESC short' );
    $made['locked'] = (int) $locked->save();

    $dl = new WC_Product_Simple();
    $dl->set_name( 'AIED-VERIFY Download' );
    $dl->set_sku( 'AIED-VERIFY-DOWNLOAD' );
    $dl->set_status( 'publish' );
    $dl->set_regular_price( '13' );
    $dl->set_virtual( true );
    $dl->set_downloadable( true );
    $dl->set_purchase_note( 'AIED-SECRET-PURCHASE-NOTE' );
    $made['download'] = (int) $dl->save();
    // The download file goes straight into the meta WooCommerce reads, so no approved-download-directory rule is added to the site.
    $uuid = wp_generate_uuid4();
    update_post_meta( $made['download'], '_downloadable_files', [ $uuid => [ 'id' => $uuid, 'name' => 'secret', 'file' => 'https://example.test/AIED-SECRET-DOWNLOAD.zip', 'enabled' => true ] ] );
    clean_post_cache( $made['download'] );
    $reloaded = wc_get_product( $made['download'] );
    $check( 3 === count( array_filter( $made ) ) && $reloaded && str_contains( (string) wp_json_encode( array_map( static fn ( $d ) => $d->get_file(), $reloaded->get_downloads() ) ), 'AIED-SECRET-DOWNLOAD' ), 'three throwaway products exist (the download file is stored)' );

    // 1. the list and its totals
    $all = $rest( 'GET', '/products', [ 'per_page' => '50' ] );
    $responses[] = $all;
    $counts      = (array) wp_count_posts( 'product' );
    $direct      = 0;
    foreach ( AIED_PROD_STATUSES as $s ) {
        $direct += (int) ( $counts[ $s ] ?? 0 );
    }
    $totals = (array) ( $all['data']['totals'] ?? [] );
    $check( 200 === $all['status'], 'REST list: 200' );
    $check( $direct === ( $totals['all'] ?? null ), "REST list: totals.all ({$direct}) equals wp_count_posts publish+draft+pending+private+future" );
    $check( (int) ( $counts['publish'] ?? 0 ) === ( $totals['by_status']['publish'] ?? null ), 'REST list: totals.by_status.publish equals the direct count' );
    $check( 1 === ( $totals['by_status']['draft'] ?? null ), 'REST list: totals.by_status.draft counts the draft throwaway' );
    $typeOk = true;
    $typeNotes = [];
    foreach ( [ 'simple', 'variable', 'grouped', 'external' ] as $t ) {
        $n = count( wc_get_products( [ 'type' => $t, 'status' => 'publish', 'limit' => -1, 'return' => 'ids' ] ) );
        $typeNotes[] = "{$t} {$n}/" . ( $totals['published_by_type'][ $t ] ?? 'missing' );
        $typeOk = $typeOk && $n === ( $totals['published_by_type'][ $t ] ?? null );
    }
    $check( $typeOk, 'REST list: totals.published_by_type equals a direct wc_get_products count per type (' . implode( ', ', $typeNotes ) . ')' );
    $every = $directIds( [] );
    $check( $sameSet( $every, $ids( $all ) ), 'REST list: per_page=50 returns every product the site has (' . count( $every ) . ')' );
    $check( count( $every ) === ( $all['data']['total_matching'] ?? null ), 'REST list: total_matching equals the number of products' );

    // 2. filters
    $r = $rest( 'GET', '/products', [ 'status' => 'draft', 'per_page' => '50' ] );
    $check( 200 === $r['status'] && [ $made['draft'] ] === $ids( $r ), 'REST list: status=draft returns exactly the draft throwaway' );
    $r         = $rest( 'GET', '/products', [ 'type' => 'variable', 'per_page' => '50' ] );
    $variables = $directIds( [ 'type' => 'variable' ] );
    $check( 200 === $r['status'] && [] !== $variables && $sameSet( $variables, $ids( $r ) ), 'REST list: type=variable returns exactly the variable products' );
    $check( [] === array_filter( (array) $r['data']['products'], static fn ( $p ) => 'variable' !== ( $p['type'] ?? '' ) ), 'REST list: every type=variable row says type variable' );
    $cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC' ] );
    if ( is_array( $cats ) && [] !== $cats ) {
        $slug = (string) $cats[0]->slug;
        $r    = $rest( 'GET', '/products', [ 'category' => $slug, 'per_page' => '50' ] );
        $want = $directIds( [ 'category' => [ $slug ] ] );
        $check( 200 === $r['status'] && [] !== $want && $sameSet( $want, $ids( $r ) ), "REST list: category={$slug} matches wc_get_products (" . count( $want ) . ' products)' );
    } else {
        $check( false, 'REST list: the site has a product category to filter by' );
    }
    $r = $rest( 'GET', '/products', [ 'search' => 'AIED-VERIFY', 'per_page' => '50' ] );
    $check( 200 === $r['status'] && $sameSet( array_values( $made ), $ids( $r ) ), 'REST list: search=AIED-VERIFY returns the three throwaways' );
    $onSale = [];
    foreach ( $every as $id ) {
        $p = wc_get_product( $id );
        if ( $p && $p->is_on_sale() ) {
            $onSale[] = $id;
        }
    }
    $r = $rest( 'GET', '/products', [ 'on_sale' => 'true', 'per_page' => '50' ] );
    $check( 200 === $r['status'] && [] !== $onSale && $sameSet( $onSale, $ids( $r ) ), 'REST list: on_sale=true returns exactly the products whose is_on_sale() is true (' . count( $onSale ) . ')' );
    $r = $rest( 'GET', '/products', [ 'on_sale' => 'false', 'per_page' => '50' ] );
    $check( 200 === $r['status'] && $sameSet( array_values( array_diff( $every, $onSale ) ), $ids( $r ) ), 'REST list: on_sale=false returns exactly the rest' );

    // 3. sorting: the unit-test shim sorts by itself, so only the real wc_get_products proves these
    $names = array_column( (array) $all['data']['products'], 'name' );
    $lower = array_map( 'strtolower', $names );
    $check( $nonDecreasing( $lower ), 'REST list: the default orderby=title is case-insensitive ascending by name' );
    $r = $rest( 'GET', '/products', [ 'orderby' => 'title', 'order' => 'desc', 'per_page' => '50' ] );
    $check( 200 === $r['status'] && $nonIncreasing( array_map( 'strtolower', array_column( (array) $r['data']['products'], 'name' ) ) ) && count( $every ) === count( $ids( $r ) ), 'REST list: orderby=title&order=desc is case-insensitive descending by name' );
    $dates = static fn ( array $res ): array => array_map( static fn ( $id ) => (string) get_post_field( 'post_date_gmt', $id ), $ids( $res ) );
    $r     = $rest( 'GET', '/products', [ 'orderby' => 'date', 'per_page' => '50' ] );
    $check( 200 === $r['status'] && $nonDecreasing( $dates( $r ) ) && count( $every ) === count( $ids( $r ) ), 'REST list: orderby=date is ascending by publish date' );
    $r = $rest( 'GET', '/products', [ 'orderby' => 'date', 'order' => 'desc', 'per_page' => '50' ] );
    $check( 200 === $r['status'] && $nonIncreasing( $dates( $r ) ), 'REST list: orderby=date&order=desc is descending by publish date' );
    $prices = static fn ( array $res ): array => array_map( static fn ( $p ) => (float) ( $p['price'] ?? 0 ), (array) ( $res['data']['products'] ?? [] ) );
    $r      = $rest( 'GET', '/products', [ 'orderby' => 'price', 'per_page' => '50' ] );
    $check( 200 === $r['status'] && $nonDecreasing( $prices( $r ) ) && count( $every ) === count( $ids( $r ) ), 'REST list: orderby=price is non-decreasing in price (' . implode( ',', $prices( $r ) ) . ')' );
    $r = $rest( 'GET', '/products', [ 'orderby' => 'price', 'order' => 'desc', 'per_page' => '50' ] );
    $check( 200 === $r['status'] && $nonIncreasing( $prices( $r ) ), 'REST list: orderby=price&order=desc is non-increasing in price (' . implode( ',', $prices( $r ) ) . ')' );

    // 4. bad input and paging
    $check( 400 === $rest( 'GET', '/products', [ 'per_page' => '51' ] )['status'], 'REST list: per_page=51 is 400' );
    $check( 400 === $rest( 'GET', '/products', [ 'page' => 'abc' ] )['status'], 'REST list: page=abc is 400' );
    $check( 400 === $rest( 'GET', '/products', [ 'status' => 'trash' ] )['status'], 'REST list: status=trash is 400' );
    $r = $rest( 'GET', '/products', [ 'page' => '99', 'per_page' => '50' ] );
    $check( 200 === $r['status'] && [] === ( $r['data']['products'] ?? null ), 'REST list: a page past the end is 200 with an empty list' );
    $r = $rest( 'GET', '/products', [ 'per_page' => '5', 'page' => '2' ] );
    $check( 200 === $r['status'] && array_slice( $ids( $all ), 5, 5 ) === $ids( $r ), 'REST list: per_page=5&page=2 is the second slice of the full list' );

    // 5. get_product for each type
    $get = static function ( int $id ) use ( $rest, &$responses ): array {
        $r           = $rest( 'GET', "/products/{$id}" );
        $responses[] = $r;

        return $r;
    };
    $simpleId = $directIds( [ 'type' => 'simple', 'status' => 'publish', 'exclude' => array_values( $made ) ] )[0] ?? 0;
    $r        = $get( $simpleId );
    $p        = wc_get_product( $simpleId );
    $d        = (array) ( $r['data']['product'] ?? [] );
    $check(
        200 === $r['status'] && $p && $simpleId === ( $d['id'] ?? null ) && $p->get_name() === ( $d['name'] ?? null ) && 'simple' === ( $d['type'] ?? null )
        && $p->get_sku() === ( $d['sku'] ?? null ) && (string) $p->get_price() === ( $d['price'] ?? null ) && (string) $p->get_regular_price() === ( $d['regular_price'] ?? null )
        && (string) $p->get_sale_price() === ( $d['sale_price'] ?? null ) && $p->get_stock_status() === ( $d['stock_status'] ?? null ) && $p->get_status() === ( $d['status'] ?? null )
        && (bool) $p->is_on_sale() === ( $d['on_sale'] ?? null ) && get_permalink( $simpleId ) === ( $d['url'] ?? null ),
        "REST get: a simple product ({$simpleId}) matches wc_get_product"
    );
    $check( true === ( $r['data']['woocommerce']['active'] ?? null ) && defined( 'WC_VERSION' ) && WC_VERSION === ( $r['data']['woocommerce']['version'] ?? null ), 'REST get: woocommerce.active and the real version' );

    $variableId = $variables[0] ?? 0;
    $r          = $get( $variableId );
    $vp         = wc_get_product( $variableId );
    $d          = (array) ( $r['data']['product'] ?? [] );
    $children   = array_map( 'intval', (array) ( $vp ? $vp->get_children() : [] ) );
    $check( 200 === $r['status'] && [] !== $children && $sameSet( $children, array_map( 'intval', array_column( (array) ( $d['variations'] ?? [] ), 'id' ) ) ), "REST get: every child of the variable product ({$variableId}) appears in variations (" . count( $children ) . ')' );
    $taxAttrs = array_filter( (array) ( $d['attributes'] ?? [] ), static fn ( $a ) => ! empty( $a['taxonomy'] ) );
    if ( [] === $taxAttrs ) {
        WP_CLI::log( 'NOTE  the variable product has no taxonomy attribute; the option-name check is covered by the unit tests' );
    } else {
        $wantNames = [];
        foreach ( (array) $vp->get_attributes() as $a ) {
            if ( $a->is_taxonomy() ) {
                $wantNames[] = array_values( array_map( static fn ( $t ) => (string) $t->name, (array) $a->get_terms() ) );
            }
        }
        $gotNames = array_values( array_map( static fn ( $a ) => array_values( (array) $a['options'] ), $taxAttrs ) );
        $numeric  = array_filter( array_merge( [], ...$gotNames ), static fn ( $o ) => is_numeric( $o ) );
        $check( [] === $numeric && $wantNames === $gotNames, 'REST get: taxonomy attribute options are term names, not ids (' . implode( ', ', array_merge( [], ...$gotNames ) ) . ')' );
    }

    // Variation attribute values: WooCommerce stores the term SLUG (percent-encoded on a non-English store); the AI must read the term NAME,
    // and a custom attribute comes back as written, under the same label the product-level attributes use.
    $varChecked = 0;
    $varBad     = [];
    foreach ( $variables as $vpId ) {
        $vParent = wc_get_product( $vpId );
        $vData   = (array) ( $get( $vpId )['data']['product']['variations'] ?? [] );
        $byId    = [];
        foreach ( $vData as $row ) {
            $byId[ (int) $row['id'] ] = (array) ( $row['attributes'] ?? [] );
        }
        foreach ( $byId as $vid => $got ) {
            $wc = wc_get_product( $vid );
            if ( ! $wc || ! $vParent ) {
                continue;
            }
            foreach ( (array) $wc->get_attributes() as $name => $slug ) {
                $label = (string) wc_attribute_label( (string) $name, $vParent );
                $slug  = (string) $slug;
                $want  = $slug;
                if ( '' !== $slug && taxonomy_exists( (string) $name ) ) {
                    $term = get_term_by( 'slug', $slug, (string) $name );
                    $want = $term ? (string) $term->name : $slug;
                }
                ++$varChecked;
                if ( ( $got[ $label ] ?? null ) !== $want ) {
                    $varBad[] = "variation {$vid} {$label}: got " . wp_json_encode( $got[ $label ] ?? null ) . ', want ' . wp_json_encode( $want );
                }
            }
        }
    }
    $check( $varChecked > 0 && [] === $varBad, "REST get: every variation attribute value is the term NAME (custom attributes keep their own label) across " . count( $variables ) . " variable products ({$varChecked} values)" . ( [] === $varBad ? '' : ': ' . implode( '; ', $varBad ) ) );

    $groupedId = $directIds( [ 'type' => 'grouped' ] )[0] ?? 0;
    $r         = $get( $groupedId );
    $gp        = wc_get_product( $groupedId );
    $check( 200 === $r['status'] && $gp && array_map( 'intval', (array) $gp->get_children() ) === array_map( 'intval', (array) ( $r['data']['product']['children'] ?? [ 'x' ] ) ), "REST get: the grouped product ({$groupedId}) children equal get_children()" );

    $externalId = $directIds( [ 'type' => 'external' ] )[0] ?? 0;
    $r          = $get( $externalId );
    $ep         = wc_get_product( $externalId );
    $check( 200 === $r['status'] && $ep && '' !== $ep->get_product_url() && $ep->get_product_url() === ( $r['data']['product']['external']['product_url'] ?? null ), "REST get: the external product ({$externalId}) product_url equals WooCommerce's" );

    $r = $get( $made['locked'] );
    $check( 200 === $r['status'] && true === ( $r['data']['product']['password_protected'] ?? null ) && array_key_exists( 'description', (array) $r['data']['product'] ) && null === $r['data']['product']['description'], 'REST get: the password-protected product says so and hides its description' );
    $check( ! str_contains( (string) wp_json_encode( $r['data'] ), 'AIED-SECRET-DESC' ), 'REST get: the protected description appears nowhere' );
    $r = $get( $made['download'] );
    $check( 200 === $r['status'] && true === ( $r['data']['product']['downloadable'] ?? null ), 'REST get: the downloadable product reports the flag' );

    $missing   = $rest( 'GET', '/products/99999999' );
    $variation = $rest( 'GET', '/products/' . ( $children[0] ?? 0 ) );
    $norm      = static fn ( array $res, int $id ): string => $res['status'] . '|' . ( $res['data']['code'] ?? '' ) . '|' . str_replace( (string) $id, 'N', (string) ( $res['data']['message'] ?? '' ) );
    $check( 404 === $missing['status'] && $norm( $missing, 99999999 ) === $norm( $variation, (int) ( $children[0] ?? 0 ) ), 'REST get: a variation id is a 404 identical to a missing id' );
    $postId = (int) ( get_posts( [ 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ] )[0] ?? 0 );
    $check( $postId > 0 && 404 === $rest( 'GET', "/products/{$postId}" )['status'], "REST get: a blog post id ({$postId}) is a 404" );

    // 6. privacy sweep over every answer above
    $blob = (string) wp_json_encode( array_column( $responses, 'data' ) );
    foreach ( [ 'AIED-SECRET-DOWNLOAD', 'AIED-SECRET-PURCHASE-NOTE', 'AIED-SECRET-DESC', 'purchase_note', '_downloadable_files' ] as $secret ) {
        $check( ! str_contains( $blob, $secret ), "privacy: \"{$secret}\" appears in no answer (" . count( $responses ) . ' answers)' );
    }

    // 7. MCP parity
    $m = $mcp( 'list_products', [ 'per_page' => 50 ] );
    $check( null === $m['error'] && $normalised( $all['data'] ) === $m['result'], 'MCP: list_products equals the REST list' );
    $m = $mcp( 'get_product', [ 'id' => $variableId ] );
    $check( null === $m['error'] && $normalised( $rest( 'GET', "/products/{$variableId}" )['data'] ) === $m['result'], 'MCP: get_product equals the REST answer' );
    $check( -32602 === ( $mcp( 'get_product', [ 'id' => (string) $simpleId ] )['error']['code'] ?? null ), 'MCP: a non-integer id is rejected' );
    $check( -32602 === ( $mcp( 'list_products', [ 'per_page' => '50' ] )['error']['code'] ?? null ), 'MCP: a string per_page is rejected (MCP is typed)' );

    // 8. an editor (edit_pages, no product permissions)
    $inserted = wp_insert_user( [ 'user_login' => AIED_PROD_USER, 'user_pass' => wp_generate_password(), 'role' => 'editor' ] );
    if ( is_wp_error( $inserted ) ) {
        throw new RuntimeException( 'could not create the throwaway editor: ' . $inserted->get_error_message() );
    }
    $userId = (int) $inserted;
    wp_set_current_user( $userId );
    $denied    = $rest( 'GET', '/products' );
    $deniedGet = $rest( 'GET', "/products/{$simpleId}" );
    wp_set_current_user( 1 );
    $check( current_user_can( 'edit_others_products' ) && 403 === $denied['status'] && str_contains( (string) ( $denied['data']['message'] ?? '' ), 'edit_others_products' ), 'REST: an editor is refused the list (403 naming edit_others_products)' );
    $check( 404 === $deniedGet['status'], 'REST: an editor gets 404 for a real product' );

    // 9. get_site_structure counts
    $s     = $rest( 'GET', '/site-structure' );
    $types = array_column( (array) ( $s['data']['post_types'] ?? [] ), 'count', 'name' );
    $check( 200 === $s['status'] && (int) wp_count_posts( 'product' )->publish === ( $types['product'] ?? null ), 'get_site_structure: product count equals the published products (' . ( $types['product'] ?? 'missing' ) . ')' );
    $check( (int) wp_count_posts( 'page' )->publish === ( $types['page'] ?? null ), 'get_site_structure: page count equals the published pages (' . ( $types['page'] ?? 'missing' ) . ')' );

    // 10. status=any never returns a trashed product, and the totals drop it
    $allBefore = (int) ( $totals['all'] ?? 0 );
    wp_trash_post( $made['draft'] );
    $r = $rest( 'GET', '/products', [ 'per_page' => '50' ] );
    $check( 'trash' === get_post_status( $made['draft'] ) && ! in_array( $made['draft'], $ids( $r ), true ), 'REST list: status=any does not return a trashed product' );
    $check( $allBefore - 1 === ( $r['data']['totals']['all'] ?? null ) && $allBefore - 1 === ( $r['data']['total_matching'] ?? null ), 'REST list: the totals leave the trashed product out' );
    $check( 404 === $rest( 'GET', "/products/{$made['draft']}" )['status'], 'REST get: a trashed product is a 404, like a missing one' );
} catch ( Throwable $e ) {
    $check( false, 'unexpected error: ' . $e->getMessage() );
} finally {
    wp_set_current_user( 1 );
    foreach ( array_unique( array_merge( array_values( array_filter( $made ) ), $throwawayIds() ) ) as $id ) {
        $forceDelete( (int) $id );
    }
    // Only ever delete the throwaway user: never id 1 or any other account.
    if ( $userId > 1 ) {
        $u = get_userdata( $userId );
        if ( $u && AIED_PROD_USER === $u->user_login ) {
            wp_delete_user( $userId );
        }
    }
    wp_set_current_user( 1 );
}

// 11. nothing was written; the throwaways are gone; the totals are back
$check( [] === $throwawayIds() && ! get_user_by( 'login', AIED_PROD_USER ), 'cleanup: the throwaway products and user are gone' );
$check( $snapshot() === $before, 'cleanup: every original product and variation is byte-identical (' . $before . ')' );
// WordPress caches wp_count_posts() and clears it only on a status change; a permanent delete of a PUBLISHED post (this
// cleanup) is none, so within this one process the cached count is stale. Every real MCP/REST call is a new request and
// starts without it (no persistent object cache on the dev site), so clear it the way a new request would.
wp_cache_delete( _count_posts_cache_key( 'product' ), 'counts' );
wp_cache_delete( _count_posts_cache_key( 'product', 'readable' ), 'counts' );
$after = $rest( 'GET', '/products', [ 'per_page' => '50' ] )['data']['totals'] ?? null;
$check( is_array( $origTotals ) && $origTotals === $after, 'cleanup: the totals equal the original counts again (' . wp_json_encode( $after ) . ')' );

if ( [] !== $failures ) {
    WP_CLI::error( 'products NOT verified: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'list_products and get_product verified on the real site.' );
