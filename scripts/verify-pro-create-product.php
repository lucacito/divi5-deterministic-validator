<?php
/**
 * Real-site check of the Pro tool create_product: the real tool with the REAL WooProductWriter (WooCommerce's own CRUD classes),
 * the real batch table, the real undo, WordPress's REAL wp_kses_post() and wp_strip_all_tags() (the unit tests only use stand-ins),
 * and the free plugin's own read path (ProductService::get), so what the AI asked for is compared with what WooCommerce stored
 * through a second, independent code path. It also proves the rollback (a writer that fails on its second product), a hook that
 * throws inside WooCommerce's insert, the permission rules (an editor is refused, a shop manager may create) and that the
 * products the site already has are byte-identical afterwards.
 *
 * Self-cleaning: it force-deletes every product it created (SKU AIED-CP-*, or name "AIED CP ..."; also trashed ones and rollback
 * leftovers), its product category and tag (slugs aied-cp-*), its Media Library files ("AIED CP ..."), its throwaway users
 * (aied_pro_cp_*) and the batch rows it recorded. It records several batches and the batch store keeps only the newest 20, so on a
 * site that already has batches it evicts the oldest real ones: dev sites only.
 *
 * Run: make verify-pro-create-product   (needs `make up`, WooCommerce, the free plugin and the Pro add-on active; no licence is
 * needed: the tool is called directly, not through the licence-gated registry)
 */

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Products\ProductValuePolicy;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;
use AiEditorDivi5\Pro\Tools\CreateProduct;
use AiEditorDivi5\WP\ProductService;
use AiEditorDivi5\WP\WooProductGateway;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( CreateProduct::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro (with create_product) on this site first.' );
}
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();
if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'wc_get_product' ) ) {
    WP_CLI::error( 'WooCommerce is not active on this site; activate it first.' );
}
if ( ! class_exists( ProductService::class ) || ! class_exists( WooProductGateway::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (with get_product) on this site first.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

const AIED_CP_SKU    = 'AIED-CP-';
const AIED_CP_NAME   = 'AIED CP ';
const AIED_CP_SLUG   = 'aied-cp-';
const AIED_CP_USERS  = [ 'editor' => 'aied_pro_cp_editor', 'shop_manager' => 'aied_pro_cp_shop' ];
const AIED_CP_SECRET = '/var/www/html/secret.php';

/** Delete the throwaway users this script creates: only those exact logins, never user 1. */
$deleteUsers = static function (): void {
    foreach ( AIED_CP_USERS as $login ) {
        $u = get_user_by( 'login', $login );
        if ( $u instanceof \WP_User && $login === $u->user_login && (int) $u->ID > 1 ) {
            wp_delete_user( (int) $u->ID );
        }
    }
};
/** Every product or variation (any status, the trash too) this script could have made: SKU AIED-CP-* or name "AIED CP ...". */
$mine = static function (): array {
    global $wpdb;
    $bySku  = (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' WHERE p.post_type IN ('product','product_variation') AND m.meta_value LIKE %s",
        $wpdb->esc_like( AIED_CP_SKU ) . '%'
    ) );
    $byName = (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_title LIKE %s",
        $wpdb->esc_like( AIED_CP_NAME ) . '%'
    ) );
    $ids = array_values( array_unique( array_map( 'intval', array_merge( $bySku, $byName ) ) ) );
    sort( $ids );

    return $ids;
};
/** Products (any status, the trash too) whose SKU or name is exactly this. */
$withSkuOrName = static function ( string $sku, string $name ): array {
    global $wpdb;

    return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' WHERE p.post_type = 'product' AND ( m.meta_value = %s OR p.post_title = %s )",
        $sku,
        $name
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
$myAttachments = static function (): array {
    global $wpdb;

    return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title LIKE %s",
        $wpdb->esc_like( AIED_CP_NAME ) . '%'
    ) ) );
};
$myTerms = static function (): array {
    $out = [];
    foreach ( [ 'product_cat', 'product_tag' ] as $tax ) {
        $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
        foreach ( is_array( $terms ) ? $terms : [] as $t ) {
            if ( str_starts_with( (string) $t->slug, AIED_CP_SLUG ) ) {
                $out[] = [ (int) $t->term_id, $tax ];
            }
        }
    }

    return $out;
};
$removeThrowaways = static function () use ( $deleteUsers, $mine, $forceDelete, $myAttachments, $myTerms ): void {
    foreach ( $mine() as $id ) {
        $forceDelete( $id );
    }
    foreach ( $myAttachments() as $id ) {
        wp_delete_attachment( $id, true );
    }
    foreach ( $myTerms() as [ $termId, $tax ] ) {
        wp_delete_term( $termId, $tax );
    }
    $deleteUsers();
};
// Every product and variation the site has (any status), as one hash: proof that nothing was written to them.
$snapshot = static function (): string {
    global $wpdb;
    $ids  = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') ORDER BY ID" ) );
    $rows = [];
    foreach ( $ids as $id ) {
        clean_post_cache( $id );
        $post   = get_post( $id );
        $p      = wc_get_product( $id );
        $rows[] = [
            $id, $post ? $post->post_title : null, $post ? $post->post_status : null,
            $p ? $p->get_regular_price( 'edit' ) : null, $p ? $p->get_sale_price( 'edit' ) : null,
            $p ? $p->get_stock_status( 'edit' ) : null, $p ? $p->get_stock_quantity( 'edit' ) : null,
            $post ? $post->post_modified_gmt : null, $post ? $post->post_password : null,
        ];
    }

    return md5( serialize( $rows ) );
};
$totalProducts = static function ( string $types = "'product','product_variation'" ): int {
    global $wpdb;

    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$types})" ); // phpcs:ignore -- a constant list
};

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$skip = static function ( string $label ): void {
    WP_CLI::log( 'SKIP  ' . $label );
};
$note = static function ( string $label ): void {
    WP_CLI::log( 'NOTE  ' . $label );
};

wp_set_current_user( 1 );
// A previous run that was killed may have left its throwaways behind: remove only those exact ones.
$removeThrowaways();

$batchIds = [];
BatchTable::ensureReady( true );
$check( BatchTable::exists(), 'the batch table exists on the real database (' . BatchTable::name() . ')' );
$log  = Batches::log();
$undo = Batches::undo();
$tool = new CreateProduct( $log );

$track = static function ( array $r ) use ( &$batchIds ): array {
    if ( isset( $r['batch']['id'] ) ) {
        $batchIds[] = (int) $r['batch']['id'];
    }

    return $r;
};
$trackUndo = static function ( array $r ) use ( &$batchIds ): array {
    if ( isset( $r['undo_batch']['id'] ) ) {
        $batchIds[] = (int) $r['undo_batch']['id'];
    }

    return $r;
};
/** Run a tool (the real one by default); returns [ payload, error message or null ]. Anything but a refusal is "UNEXPECTED". */
$run = static function ( array $args, ?CreateProduct $with = null ) use ( &$tool, $track ): array {
    try {
        return [ $track( ( $with ?? $tool )->run( $args ) ), null ];
    } catch ( \InvalidArgumentException $e ) {
        return [ [], $e->getMessage() ];
    } catch ( \Throwable $e ) {
        return [ [], 'UNEXPECTED ' . get_class( $e ) . ': ' . $e->getMessage() ];
    }
};
$newestId = static fn (): int => (int) ( $log->recent( 1 )[0]['id'] ?? 0 );
/** The free plugin's get_product, through its own gateway: a read path independent of WooProductWriter::snapshot(). */
$readBack = static function ( int $id ): array {
    clean_post_cache( $id );
    $r = ProductService::get( $id, new WooProductGateway(), 'current_user_can' );

    return true === ( $r['ok'] ?? false ) ? (array) ( $r['data']['product'] ?? [] ) : [];
};
$termIds = static fn ( array $p, string $key ): array => array_map( 'intval', array_column( (array) ( $p[ $key ] ?? [] ), 'id' ) );
$ids     = static fn ( array $r ): array => array_map( 'intval', array_column( (array) ( $r['products'] ?? [] ), 'id' ) );

$before       = $snapshot();
$beforeTotal  = $totalProducts();
$beforeOnly   = $totalProducts( "'product'" );
$beforeIds    = $mine();
$check( [] === $beforeIds, "the site starts without throwaways ({$beforeOnly} products, {$beforeTotal} products and variations, in every status)" );

$users = [];
try {
    // ---- fixtures: a category, a tag, two images and one non-image file ---------------------------------------------------------
    $cat = wp_insert_term( AIED_CP_NAME . 'Cat', 'product_cat', [ 'slug' => AIED_CP_SLUG . 'cat' ] );
    $tag = wp_insert_term( AIED_CP_NAME . 'Tag', 'product_tag', [ 'slug' => AIED_CP_SLUG . 'tag' ] );
    if ( is_wp_error( $cat ) || is_wp_error( $tag ) ) {
        // Not WP_CLI::error(): it exits at once and would skip the cleanup in the `finally` below.
        throw new \RuntimeException( 'Could not create the throwaway category or tag.' );
    }
    $catId = (int) $cat['term_id'];
    $tagId = (int) $tag['term_id'];
    $png   = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
    $attach = static function ( string $file, string $bits, string $mime, string $title ): int {
        $up = wp_upload_bits( $file, null, $bits );
        if ( ! empty( $up['error'] ) ) {
            throw new \RuntimeException( 'Could not upload a throwaway file.' );
        }
        $id = wp_insert_attachment( [ 'post_title' => $title, 'post_mime_type' => $mime, 'post_status' => 'inherit', 'guid' => $up['url'] ], $up['file'], 0, true );
        if ( is_wp_error( $id ) ) {
            @unlink( $up['file'] ); // phpcs:ignore
            throw new \RuntimeException( 'Could not create a throwaway attachment.' );
        }

        return (int) $id;
    };
    $img  = $attach( 'aied-cp-image.png', $png, 'image/png', AIED_CP_NAME . 'image' );
    $img2 = $attach( 'aied-cp-image-2.png', $png, 'image/png', AIED_CP_NAME . 'image 2' );
    $doc  = $attach( 'aied-cp-doc.txt', 'not an image', 'text/plain', AIED_CP_NAME . 'doc' );
    $check( $catId > 0 && $tagId > 0 && $img > 0 && $img2 > 0 && $doc > 0, 'the throwaway category, tag, two images and a text file exist' );
    $defaultCat = (int) get_option( 'default_product_cat', 0 );

    // ---- dry run -------------------------------------------------------------------------------------------------------------
    $newestBefore = $newestId();
    [ $r, $err ] = $run( [ 'dry_run' => true, 'products' => [
        [ 'name' => AIED_CP_NAME . 'Dry 1', 'sku' => AIED_CP_SKU . 'DRY-1', 'regular_price' => '10' ],
        [ 'name' => AIED_CP_NAME . 'Dry 2', 'sku' => AIED_CP_SKU . 'DRY-2', 'categories' => [ AIED_CP_SLUG . 'cat' ] ],
    ] ] );
    $check( null === $err && false === ( $r['changed'] ?? null ) && true === ( $r['dry_run'] ?? null ) && 2 === count( (array) ( $r['products'] ?? [] ) ), 'a dry run of two valid products previews both (changed: false)' );
    $check( [] === wc_get_products( [ 'sku' => AIED_CP_SKU . 'DRY-1', 'status' => 'any', 'return' => 'ids' ] ) && [] === $mine() && $beforeTotal === $totalProducts(), 'a dry run creates nothing' );
    $check( ! isset( $r['batch'] ) && $newestBefore === $newestId(), 'a dry run records no batch' );

    // ---- a real run of three products ------------------------------------------------------------------------------------------
    $publishBefore = (int) wp_count_posts( 'product' )->publish;
    $three = [
        [ 'name' => AIED_CP_NAME . 'Simple', 'sku' => AIED_CP_SKU . '1', 'regular_price' => '20', 'sale_price' => '15.50', 'manage_stock' => true, 'stock_quantity' => 7 ],
        [ 'name' => AIED_CP_NAME . 'Termed', 'sku' => AIED_CP_SKU . '2', 'regular_price' => '9.99', 'categories' => [ AIED_CP_SLUG . 'cat' ], 'tags' => [ AIED_CP_SLUG . 'tag' ], 'image_id' => $img ],
        [ 'name' => AIED_CP_NAME . 'Minimal' ],
    ];
    [ $r, $err ] = $run( [ 'products' => $three ] );
    $created     = $ids( $r );
    $check( null === $err && true === ( $r['changed'] ?? null ) && 3 === count( array_filter( $created ) ), 'a real run of three products returns three ids' . ( null === $err ? '' : ': ' . $err ) );
    $check( 3 === count( array_filter( (array) ( $r['products'] ?? [] ), static fn ( $p ) => 'draft' === ( $p['status'] ?? '' ) ) ), 'all three answer status: draft' );
    $check( true === ( $r['batch']['undoable'] ?? false ), 'the call recorded one undoable batch' );
    $batch1 = (int) ( $r['batch']['id'] ?? 0 );
    if ( 3 === count( $created ) ) {
        foreach ( $created as $i => $id ) {
            $want = $three[ $i ];
            $got  = $readBack( $id );
            $ok   = $want['name'] === ( $got['name'] ?? null ) && ( $want['sku'] ?? '' ) === ( $got['sku'] ?? null )
                && ( $want['regular_price'] ?? '' ) === ( $got['regular_price'] ?? null ) && ( $want['sale_price'] ?? '' ) === ( $got['sale_price'] ?? null )
                && array_key_exists( 'stock_quantity', $got ) && ( $want['stock_quantity'] ?? null ) === $got['stock_quantity'] && 'draft' === ( $got['status'] ?? null ) && 'simple' === ( $got['type'] ?? null );
            $check( $ok, "get_product (free) reads back what product " . ( $i + 1 ) . " asked for: name, SKU, prices, stock (#{$id})" . ( $ok ? '' : ': ' . wp_json_encode( $got ) ) );
        }
        $termed = $readBack( $created[1] );
        $check( in_array( $catId, $termIds( $termed, 'categories' ), true ), 'the requested category is in the product\'s categories (free read path)' );
        $check( [ $tagId ] === $termIds( $termed, 'tags' ), 'the requested tag is the product\'s tag (free read path)' );
        $check( wp_get_attachment_url( $img ) === ( $termed['images']['featured'] ?? null ), 'the requested image is the product\'s featured image (free read path)' );
        $minimal = $readBack( $created[2] );
        $check( [] !== $termIds( $minimal, 'categories' ) && ( $defaultCat <= 0 || in_array( $defaultCat, $termIds( $minimal, 'categories' ), true ) ), "a product created without categories passed the read-back although WooCommerce filed it under its default category (#{$defaultCat})" );
        $check( 'instock' === ( $minimal['stock_status'] ?? null ) && false === ( $minimal['manage_stock'] ?? null ) && '' === ( $minimal['regular_price'] ?? null ), 'the minimal product has the documented defaults (in stock, no stock management, no price)' );
    }
    $check( [] === array_filter( $created, static fn ( $id ) => 'publish' === get_post_status( $id ) ) && $publishBefore === (int) wp_count_posts( 'product' )->publish, 'none of the three is published; the published count is unchanged' );

    // ---- a full product: every supported field -------------------------------------------------------------------------------
    $full = [
        'name'              => AIED_CP_NAME . 'Full',
        'description'       => 'Fish & chips <p>A <strong>full</strong> paragraph.</p>',
        'short_description' => 'Short & <em>sweet</em>',
        'sku'               => AIED_CP_SKU . 'FULL',
        'regular_price'     => '30',
        'sale_price'        => '25.5',
        'manage_stock'      => true,
        'stock_quantity'    => 3,
        'backorders'        => 'notify',
        'categories'        => [ AIED_CP_SLUG . 'cat' ],
        'tags'              => [ AIED_CP_SLUG . 'tag' ],
        'image_id'          => $img,
        'gallery_image_ids' => [ $img2 ],
        'weight'            => '1.5',
        'dimensions'        => [ 'length' => '10', 'width' => '5', 'height' => '2.5' ],
        'tax_status'        => 'none',
        'virtual'           => true,
        'sold_individually' => true,
    ];
    [ $r, $err ] = $run( [ 'products' => [ $full ] ] );
    $fullId      = $ids( $r )[0] ?? 0;
    $check( null === $err && $fullId > 0, 'a product with every supported field is created and passes the read-back' . ( null === $err ? '' : ': ' . $err ) );
    if ( $fullId > 0 ) {
        $got = $readBack( $fullId );
        $exp = [
            'name' => $full['name'], 'sku' => $full['sku'], 'regular_price' => '30', 'sale_price' => '25.5', 'manage_stock' => true, 'stock_quantity' => 3,
            'stock_status' => 'instock', 'backorders' => 'notify', 'description' => $full['description'], 'short_description' => $full['short_description'],
            'weight' => '1.5', 'dimensions' => $full['dimensions'], 'tax_status' => 'none', 'virtual' => true, 'sold_individually' => true, 'status' => 'draft',
        ];
        $diff = [];
        foreach ( $exp as $k => $v ) {
            if ( ( $got[ $k ] ?? null ) !== $v ) {
                $diff[] = $k . '=' . wp_json_encode( $got[ $k ] ?? null );
            }
        }
        $check( [] === $diff, 'get_product (free) reads back every scalar field of the full product exactly as sent' . ( [] === $diff ? '' : ': ' . implode( ', ', $diff ) ) );
        $check( [ $catId ] === $termIds( $got, 'categories' ) && [ $tagId ] === $termIds( $got, 'tags' ), 'the full product has exactly the requested category and tag' );
        $check( wp_get_attachment_url( $img ) === ( $got['images']['featured'] ?? null ) && [ wp_get_attachment_url( $img2 ) ] === ( $got['images']['gallery'] ?? null ), 'the full product has the requested featured image and gallery image' );
        $batchFull = (int) ( $r['batch']['id'] ?? 0 );
        $check( $batchFull > 0, 'the full product\'s call recorded a batch' );
    }

    // ---- real wp_kses_post(): what passes, what is refused --------------------------------------------------------------------
    [ $r, $err ] = $run( [ 'products' => [ [ 'name' => AIED_CP_NAME . 'Kses', 'sku' => AIED_CP_SKU . 'KSES', 'description' => 'a & b <p>x</p>' ] ] ] );
    $ksesId      = $ids( $r )[0] ?? 0;
    $check( null === $err && $ksesId > 0 && 'a & b <p>x</p>' === ( $readBack( $ksesId )['description'] ?? null ), 'a description "a & b <p>x</p>" is accepted and stored as sent (real wp_kses_post)' . ( null === $err ? '' : ': ' . $err ) );

    // ---- refusals: nothing is created, no batch --------------------------------------------------------------------------------
    $companion = [ 'name' => AIED_CP_NAME . 'Companion', 'sku' => AIED_CP_SKU . 'COMPANION', 'regular_price' => '5' ];
    $cases     = [
        'a SKU a created product already has' => [ [ [ 'name' => AIED_CP_NAME . 'Dup', 'sku' => AIED_CP_SKU . '1' ] ], 'already used by another product' ],
        'a SKU twice in one call'             => [ [ [ 'name' => AIED_CP_NAME . 'Twice A', 'sku' => AIED_CP_SKU . 'TWICE' ], [ 'name' => AIED_CP_NAME . 'Twice B', 'sku' => AIED_CP_SKU . 'twice' ] ], 'more than once' ],
        'an unknown category'                 => [ [ [ 'name' => AIED_CP_NAME . 'Cat?', 'categories' => [ AIED_CP_SLUG . 'no-such-cat' ] ] ], 'category "aied-cp-no-such-cat" does not exist' ],
        'an unknown tag'                      => [ [ [ 'name' => AIED_CP_NAME . 'Tag?', 'tags' => [ AIED_CP_SLUG . 'no-such-tag' ] ] ], 'tag "aied-cp-no-such-tag" does not exist' ],
        'a price "$5"'                        => [ [ [ 'name' => AIED_CP_NAME . 'Dollar', 'regular_price' => '$5' ] ], 'decimal string' ],
        'a sale price above the regular one'  => [ [ [ 'name' => AIED_CP_NAME . 'Sale', 'regular_price' => '10', 'sale_price' => '12' ] ], 'lower than' ],
        'a script in a description'           => [ [ [ 'name' => AIED_CP_NAME . 'Script', 'description' => '<script>alert(1)</script>' ] ], 'script' ],
        'a <form> in a description (kses)'    => [ [ [ 'name' => AIED_CP_NAME . 'Form', 'description' => '<form action="x">hi</form>' ] ], 'markup WordPress does not allow' ],
        'an image_id that is a text file'     => [ [ [ 'name' => AIED_CP_NAME . 'Doc', 'image_id' => $doc ] ], "image_id {$doc} is not an image" ],
        'a missing image_id'                  => [ [ [ 'name' => AIED_CP_NAME . 'Gone', 'image_id' => 99999999 ] ], 'image_id 99999999 is not an image' ],
        'a gallery id that is a text file'    => [ [ [ 'name' => AIED_CP_NAME . 'Gal', 'gallery_image_ids' => [ $doc ] ] ], "gallery_image_ids {$doc} is not an image" ],
        'an unknown key (status)'             => [ [ [ 'name' => AIED_CP_NAME . 'Status', 'status' => 'publish' ] ], '"status" is not a supported field' ],
        'manage_stock without stock_quantity' => [ [ [ 'name' => AIED_CP_NAME . 'Stock', 'manage_stock' => true ] ], '"stock_quantity" is required' ],
        'a name with leading/trailing spaces' => [ [ [ 'name' => '  ' . AIED_CP_NAME . 'Spaced  ' ] ], '"name" must not start or end with spaces' ],
        'a name with "<"'                     => [ [ [ 'name' => AIED_CP_NAME . 'Size < 10' ] ], '"name" must not contain < or >' ],
        'markup in a SKU'                     => [ [ [ 'name' => AIED_CP_NAME . 'Sku tag', 'sku' => '<b>x</b>' ] ], '"sku" must not contain < or >' ],
        'a backslash in a name'               => [ [ [ 'name' => AIED_CP_NAME . 'back\\slash' ] ], '"name" must not contain a backslash' ],
        'a backslash in a description'        => [ [ [ 'name' => AIED_CP_NAME . 'Back desc', 'description' => 'C:\\dir' ] ], '"description" must not contain a backslash' ],
    ];
    foreach ( $cases as $label => [ $products, $needle ] ) {
        $mineBefore   = $mine();
        $newestBefore = $newestId();
        [ , $err ]    = $run( [ 'products' => array_merge( [ $companion ], $products ) ] );
        $check(
            null !== $err && str_starts_with( $err, 'Nothing was created.' ) && str_contains( $err, $needle ) && $mineBefore === $mine() && $newestBefore === $newestId(),
            "refused: {$label} (the valid product beside it was not created, no batch recorded)" . ( null !== $err && str_contains( $err, $needle ) ? '' : ': ' . (string) $err )
        );
    }
    $many = [];
    for ( $i = 0; $i < 26; $i++ ) {
        $many[] = [ 'name' => AIED_CP_NAME . 'Many ' . $i ];
    }
    $mineBefore   = $mine();
    $newestBefore = $newestId();
    [ , $err ]    = $run( [ 'products' => $many ] );
    $check( null !== $err && str_contains( $err, 'at most 25' ) && $mineBefore === $mine() && $newestBefore === $newestId(), 'refused: 26 products, nothing created' );

    // ---- rollback: a writer that fails on its second product ---------------------------------------------------------------------
    $failing = new class( new WooProductWriter() ) implements ProductWriter {
        /** @var list<int> */
        public array $made = [];
        private int $calls = 0;

        public function __construct( private WooProductWriter $real )
        {
        }

        public function isActive(): bool
        {
            return $this->real->isActive();
        }

        public function skuTaken( string $sku ): bool
        {
            return $this->real->skuTaken( $sku );
        }

        public function skuOwner( string $sku ): int
        {
            return $this->real->skuOwner( $sku );
        }

        public function typeOf( int $id ): ?string
        {
            return $this->real->typeOf( $id );
        }

        public function product( int $id ): ?array
        {
            return $this->real->product( $id );
        }

        public function apply( int $id, array $changes ): void
        {
            $this->real->apply( $id, $changes );
        }

        public function termIds( string $taxonomy, array $slugs ): array
        {
            return $this->real->termIds( $taxonomy, $slugs );
        }

        public function imageUsable( int $id ): bool
        {
            return $this->real->imageUsable( $id );
        }

        public function create( array $values ): int
        {
            if ( 2 === ++$this->calls ) {
                throw new \RuntimeException( AIED_CP_SECRET . ' simulated writer failure' );
            }
            $id           = $this->real->create( $values );
            $this->made[] = $id;

            return $id;
        }

        public function snapshot( int $id ): ?array
        {
            return $this->real->snapshot( $id );
        }

        public function delete( int $id ): bool
        {
            return $this->real->delete( $id );
        }

        public function editUrl( int $id ): string
        {
            return $this->real->editUrl( $id );
        }
    };
    $newestBefore = $newestId();
    [ , $err ]    = $run( [ 'products' => [ [ 'name' => AIED_CP_NAME . 'Roll 1', 'sku' => AIED_CP_SKU . 'ROLL-1' ], [ 'name' => AIED_CP_NAME . 'Roll 2', 'sku' => AIED_CP_SKU . 'ROLL-2' ] ] ], new CreateProduct( $log, $failing ) );
    $firstRoll    = $failing->made[0] ?? 0;
    clean_post_cache( $firstRoll );
    $check( null !== $err && ! str_starts_with( $err, 'UNEXPECTED' ) && $firstRoll > 0, 'a writer failing on the second product makes the call fail' . ( null === $err ? '' : ': ' . $err ) );
    $check( $firstRoll > 0 && null === get_post( $firstRoll ) && [] === $withSkuOrName( AIED_CP_SKU . 'ROLL-1', AIED_CP_NAME . 'Roll 1' ), 'the first product of the failed call is deleted (not in the trash)' );
    $check( $newestBefore === $newestId(), 'the failed call recorded no batch' );
    $check( null !== $err && ! str_contains( $err, '/var/www' ) && str_ends_with( $err, 'Nothing was created.' ), 'the failure message shows no server path and says nothing was created' );

    // ---- a hook that throws INSIDE WooCommerce's insert -------------------------------------------------------------------------
    $hookCase = static function ( string $hook, int $priority, int $accepted, string $suffix ) use ( $run, $withSkuOrName, $newestId, $check ): void {
        $name   = AIED_CP_NAME . 'Hook ' . $suffix;
        $sku    = AIED_CP_SKU . 'HOOK-' . strtoupper( $suffix );
        $thrower = 'save_post_product' === $hook
            ? static function ( $id, $post ) use ( $name ): void {
                if ( is_object( $post ) && $name === $post->post_title ) {
                    throw new \RuntimeException( AIED_CP_SECRET . ' simulated hook failure' );
                }
            }
            : static function ( $new, $old, $post ) use ( $name ): void {
                if ( is_object( $post ) && 'product' === $post->post_type && $name === $post->post_title ) {
                    throw new \RuntimeException( AIED_CP_SECRET . ' simulated hook failure' );
                }
            };
        $newestBefore = $newestId();
        add_action( $hook, $thrower, $priority, $accepted );
        try {
            [ , $err ] = $run( [ 'products' => [ [ 'name' => $name, 'sku' => $sku, 'regular_price' => '3' ] ] ] );
        } finally {
            remove_action( $hook, $thrower, $priority );
        }
        $left = $withSkuOrName( $sku, $name );
        $check( null !== $err && ! str_starts_with( $err, 'UNEXPECTED' ), "{$hook} throwing during the insert: the call fails" . ( null === $err ? '' : ': ' . $err ) );
        $check( null !== $err && ! str_contains( $err, '/var/www' ), "{$hook} throwing: the message shows no server path" );
        $check( [] === $left, "{$hook} throwing: no product with that SKU or name remains in any status, the trash included" . ( [] === $left ? '' : ' (left: #' . implode( ', #', $left ) . ')' ) );
        $check( null !== $err && ( ! str_contains( $err, 'Nothing was created.' ) || [] === $left ), "{$hook} throwing: the message claims \"Nothing was created.\" only when nothing is left" );
        $check( $newestBefore === $newestId(), "{$hook} throwing: no batch recorded" );
    };
    $hookCase( 'save_post_product', 10, 2, 'save' );
    $hookCase( 'transition_post_status', 20, 3, 'transition' );

    // ---- undo, changed-since, undo of the undo ----------------------------------------------------------------------------------
    if ( $batch1 > 0 && 3 === count( $created ) ) {
        $u = $trackUndo( $undo->undo( $batch1, false ) );
        foreach ( $created as $id ) {
            clean_post_cache( $id );
        }
        $check( true === ( $u['fully_undone'] ?? false ) && 3 === ( $u['restored'] ?? 0 ) && [ 'trash', 'trash', 'trash' ] === array_map( 'get_post_status', $created ), 'undo trashes all three created products (fully_undone)' );
        $undoBatch1 = (int) ( $u['undo_batch']['id'] ?? 0 );
        if ( $undoBatch1 > 0 ) {
            $uu = $trackUndo( $undo->undo( $undoBatch1, false ) );
            foreach ( $created as $id ) {
                clean_post_cache( $id );
            }
            $check( true === ( $uu['fully_undone'] ?? false ) && [ 'draft', 'draft', 'draft' ] === array_map( 'get_post_status', $created ), 'the undo of the undo brings all three back as drafts' );
        } else {
            $check( false, 'the undo recorded an undo batch' );
        }
    } else {
        $skip( 'undo of the three products (the real run did not create them)' );
    }

    [ $r, $err ] = $run( [ 'products' => [ [ 'name' => AIED_CP_NAME . 'Edit A', 'sku' => AIED_CP_SKU . 'EA' ], [ 'name' => AIED_CP_NAME . 'Edit B', 'sku' => AIED_CP_SKU . 'EB' ] ] ] );
    [ $ea, $eb ] = array_pad( $ids( $r ), 2, 0 );
    $batchE      = (int) ( $r['batch']['id'] ?? 0 );
    if ( null === $err && $ea > 0 && $eb > 0 && $batchE > 0 ) {
        wp_update_post( [ 'ID' => $ea, 'post_title' => AIED_CP_NAME . 'Edit A changed by a person' ] );
        wp_update_post( [ 'ID' => $eb, 'post_status' => 'publish' ] );
        $newestBefore = $newestId();
        $u            = $trackUndo( $undo->undo( $batchE, false ) );
        clean_post_cache( $ea );
        clean_post_cache( $eb );
        $results = array_column( (array) ( $u['items'] ?? [] ), 'result' );
        $check( 0 === ( $u['restored'] ?? -1 ) && [ 'changed_since', 'changed_since' ] === $results && false === ( $u['fully_undone'] ?? true ), 'undo skips a product edited since and one published since, and reports both (changed_since)' );
        $check( 'draft' === get_post_status( $ea ) && 'publish' === get_post_status( $eb ) && $newestBefore === $newestId(), 'neither is trashed and no undo batch is recorded' );
        $u = $trackUndo( $undo->undo( $batchE, true ) );
        clean_post_cache( $eb );
        $check( 'publish' === get_post_status( $eb ) && in_array( 'not_draft', array_column( (array) ( $u['items'] ?? [] ), 'reason' ), true ), 'even a forced undo never trashes the published product (not_draft)' );
    } else {
        $check( false, 'the edit/publish products were created' . ( null === $err ? '' : ': ' . $err ) );
    }

    // Any owner edit, not only name or price, makes the undo skip the product (the hash covers every read-back field but status).
    [ $r, $err ] = $run( [ 'products' => [ [ 'name' => AIED_CP_NAME . 'Edit T', 'sku' => AIED_CP_SKU . 'ET' ], [ 'name' => AIED_CP_NAME . 'Edit W', 'sku' => AIED_CP_SKU . 'EW', 'weight' => '1' ] ] ] );
    [ $et, $ew ] = array_pad( $ids( $r ), 2, 0 );
    $batchTW     = (int) ( $r['batch']['id'] ?? 0 );
    if ( null === $err && $et > 0 && $ew > 0 && $batchTW > 0 ) {
        wp_set_post_terms( $et, [ $tagId ], 'product_tag' );
        $w = wc_get_product( $ew );
        $w->set_weight( '9' );
        $w->save();
        $u = $trackUndo( $undo->undo( $batchTW, false ) );
        clean_post_cache( $et );
        clean_post_cache( $ew );
        $check( 0 === ( $u['restored'] ?? -1 ) && [ 'changed_since', 'changed_since' ] === array_column( (array) ( $u['items'] ?? [] ), 'result' ) && 'draft' === get_post_status( $et ) && 'draft' === get_post_status( $ew ), 'undo skips a product whose only edit is a tag and one whose only edit is the weight (changed_since), neither is trashed' );
    } else {
        $check( false, 'the tag/weight edit products were created' . ( null === $err ? '' : ': ' . $err ) );
    }

    // ---- permissions: an editor is refused, a shop manager may create ----------------------------------------------------------
    foreach ( AIED_CP_USERS as $role => $login ) {
        $uid = wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.invalid', 'role' => $role ] );
        if ( is_wp_error( $uid ) ) {
            throw new \RuntimeException( "Could not create the throwaway {$role}." );
        }
        $users[ $role ] = (int) $uid;
    }
    wp_set_current_user( $users['editor'] );
    $editorCan    = current_user_can( 'edit_pages' );
    $newestBefore = $newestId();
    $mineBefore   = $mine();
    [ , $err ]    = $run( [ 'products' => [ [ 'name' => AIED_CP_NAME . 'By editor' ] ] ] );
    wp_set_current_user( 1 );
    $check( $editorCan && null !== $err && str_contains( $err, 'edit_products' ) && $mineBefore === $mine() && $newestBefore === $newestId(), 'an editor (edit_pages, no edit_products) is refused with the edit_products message' );

    wp_set_current_user( $users['shop_manager'] );
    $shopFiltered = ! current_user_can( 'unfiltered_html' );
    [ $r, $err ]  = $run( [ 'products' => [ [ 'name' => AIED_CP_NAME . 'By shop manager', 'sku' => AIED_CP_SKU . 'SHOP', 'description' => 'Salt & pepper <p>x</p>', 'categories' => [ AIED_CP_SLUG . 'cat' ], 'image_id' => $img ] ] ] );
    $shopId       = $ids( $r )[0] ?? 0;
    $shopGot      = $shopId > 0 ? $readBack( $shopId ) : [];
    wp_set_current_user( 1 );
    $check( null === $err && $shopId > 0 && 'draft' === ( $shopGot['status'] ?? null ), 'a shop manager creates a draft (with a category and an image)' . ( null === $err ? '' : ': ' . $err ) );
    $check( in_array( str_replace( '&amp;', '&', (string) ( $shopGot['description'] ?? '' ) ), [ 'Salt & pepper <p>x</p>' ], true ), 'the shop manager\'s description survives core\'s kses filter (' . ( $shopFiltered ? 'filtered user' : 'unfiltered user' ) . ': ' . wp_json_encode( $shopGot['description'] ?? null ) . ')' );

    // ---- names with "&" as a filtered user (shop manager) and as admin: core stores "&amp;" for the first, the read-back must pass ---
    foreach ( [ 'shop_manager' => $users['shop_manager'], 'admin' => 1 ] as $who => $uid ) {
        wp_set_current_user( $uid );
        $sku         = AIED_CP_SKU . 'SALT-' . strtoupper( $who );
        [ $r, $err ] = $run( [ 'products' => [ [ 'name' => AIED_CP_NAME . 'Salt & Pepper', 'sku' => $sku, 'short_description' => 'Salt & pepper' ] ] ] );
        $id          = $ids( $r )[0] ?? 0;
        $got         = $id > 0 ? $readBack( $id ) : [];
        wp_set_current_user( 1 );
        $check(
            null === $err && $id > 0 && 'draft' === ( $got['status'] ?? null ) && ProductValuePolicy::sameText( AIED_CP_NAME . 'Salt & Pepper', (string) ( $got['name'] ?? '' ) ) && $sku === ( $got['sku'] ?? null ),
            "the {$who} creates \"AIED CP Salt & Pepper\" and it reads back (free get_product) as " . wp_json_encode( $got['name'] ?? null ) . ( null === $err ? '' : ': ' . $err )
        );
    }

    // ---- backslashes: the real store drops them, so the tool refuses them (as admin and as a shop manager) ------------------
    $probeValues = [ 'name' => AIED_CP_NAME . 'probe back\\slash', 'description' => 'D:\\dir', 'short_description' => 'a\\b', 'sku' => '', 'regular_price' => '', 'sale_price' => '', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock', 'backorders' => 'no', 'category_ids' => [], 'tag_ids' => [], 'image_id' => 0, 'gallery_image_ids' => [], 'weight' => '', 'length' => '', 'width' => '', 'height' => '', 'tax_status' => 'taxable', 'virtual' => false, 'sold_individually' => false ];
    foreach ( [ 'admin' => 1, 'shop_manager' => $users['shop_manager'] ] as $who => $uid ) {
        wp_set_current_user( $uid );
        $writer = new WooProductWriter();
        $pid    = $writer->create( $probeValues ); // straight to the writer, past the policy: what the store itself keeps
        $snap   = (array) $writer->snapshot( $pid );
        $mineBefore   = $mine();
        $newestBefore = $newestId();
        [ , $err ]    = $run( [ 'products' => [ [ 'name' => AIED_CP_NAME . 'Back ' . $who, 'description' => 'C:\\dir' ] ] ] );
        wp_set_current_user( 1 );
        $note( "backslash probe ({$who}, writer only): name " . wp_json_encode( $snap['name'] ?? null ) . ', description ' . wp_json_encode( $snap['description'] ?? null ) . ', short ' . wp_json_encode( $snap['short_description'] ?? null ) );
        $check( ! str_contains( (string) ( $snap['name'] ?? '' ), '\\' ) && ! str_contains( (string) ( $snap['description'] ?? '' ), '\\' ), "the real store drops a backslash from the name and the description ({$who}): that is why the tool refuses it" );
        $check( null !== $err && str_contains( $err, 'must not contain a backslash' ) && $mineBefore === $mine() && $newestBefore === $newestId(), "the tool refuses a backslash ({$who}) and creates nothing" );
        $forceDelete( $pid );
    }

    // ---- a FOREIGN product inserted by another hook during OUR save, then our save fails -------------------------------------
    // The writer must clean up only the draft THIS save inserted (InsertedProduct::matches), never another plugin's product.
    $foreignCase = static function ( string $victimName, string $foreignName, string $sku ) use ( $run, $withSkuOrName, $newestId ): array {
        $foreign  = [ 'id' => 0, 'row' => null, 'inserting' => false ];
        $inserter = static function () use ( &$foreign, $foreignName ): void {
            if ( 0 === $foreign['id'] && ! $foreign['inserting'] ) {
                $foreign['inserting'] = true;
                $id                   = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => $foreignName ], true );
                if ( ! is_wp_error( $id ) ) {
                    $foreign['id'] = (int) $id;
                    $row           = get_post( (int) $id );
                    $foreign['row'] = $row ? [ $row->post_type, $row->post_status, $row->post_title, $row->post_modified_gmt ] : null;
                }
                $foreign['inserting'] = false;
            }
        };
        $throwOnce = false;
        $thrower   = static function ( $id, $post ) use ( $victimName, $foreignName, &$throwOnce, &$foreign ): void {
            // Our product only, never the foreign one the inserter is making (with the same title, told apart by the flag and the id).
            if ( ! $throwOnce && ! $foreign['inserting'] && is_object( $post ) && $victimName === $post->post_title && (int) $post->ID !== $foreign['id'] ) {
                $throwOnce = true;
                throw new \RuntimeException( AIED_CP_SECRET . ' simulated hook failure' );
            }
        };
        $newestBefore = $newestId();
        add_action( 'woocommerce_before_product_object_save', $inserter, 10, 0 );
        add_action( 'save_post_product', $thrower, 10, 2 );
        try {
            [ , $err ] = $run( [ 'products' => [ [ 'name' => $victimName, 'sku' => $sku ] ] ] );
        } finally {
            remove_action( 'woocommerce_before_product_object_save', $inserter, 10 );
            remove_action( 'save_post_product', $thrower, 10 );
        }
        $now = null;
        if ( $foreign['id'] > 0 ) {
            clean_post_cache( $foreign['id'] );
            $now = get_post( $foreign['id'] );
        }
        $ours = array_values( array_diff( $withSkuOrName( $sku, $victimName ), [ $foreign['id'] ] ) );

        return [
            'err'          => (string) $err,
            'foreign_id'   => $foreign['id'],
            'foreign_same' => null !== $foreign['row'] && $now && [ $now->post_type, $now->post_status, $now->post_title, $now->post_modified_gmt ] === $foreign['row'],
            'ours_left'    => $ours,
            'no_batch'     => $newestBefore === $newestId(),
        ];
    };
    $f = $foreignCase( AIED_CP_NAME . 'Victim', AIED_CP_NAME . 'Foreign', AIED_CP_SKU . 'VICTIM' );
    $check( $f['foreign_id'] > 0 && $f['foreign_same'], "a foreign product (another title) inserted by a hook during our failing save still exists, unchanged, not trashed (#{$f['foreign_id']})" );
    $check( [] === $f['ours_left'], 'our own orphan draft of that failing save is gone from every status' . ( [] === $f['ours_left'] ? '' : ' (left: #' . implode( ', #', $f['ours_left'] ) . ')' ) );
    $check( '' !== $f['err'] && ! str_starts_with( $f['err'], 'UNEXPECTED' ) && ! str_contains( $f['err'], '/var/www' ) && ( ! str_contains( $f['err'], 'Nothing was created.' ) || [] === $f['ours_left'] ), 'the message is honest and shows no path: ' . $f['err'] );
    $check( $f['no_batch'], 'the failing save with a foreign insert recorded no batch' );

    // Residual (documented, not asserted): a foreign DRAFT with exactly OUR name, inserted before our own insert.
    $s = $foreignCase( AIED_CP_NAME . 'Same title', AIED_CP_NAME . 'Same title', AIED_CP_SKU . 'SAME' );
    $note( 'same-title residual: foreign #' . $s['foreign_id'] . ' ' . ( $s['foreign_id'] > 0 && $s['foreign_same'] ? 'survived unchanged' : 'was deleted or changed' ) . '; our draft ' . ( [] === $s['ours_left'] ? 'is gone' : 'was left behind (#' . implode( ', #', $s['ours_left'] ) . ')' ) . '; message: ' . $s['err'] );

    // A failure AFTER WooCommerce set the id on the object (woocommerce_new_product runs at the end of its insert): get_id() wins.
    $lateName = AIED_CP_NAME . 'Late failure';
    $late     = static function ( $id ) use ( $lateName ): void {
        if ( $lateName === get_post_field( 'post_title', (int) $id ) ) {
            throw new \RuntimeException( AIED_CP_SECRET . ' simulated late failure' );
        }
    };
    $newestBefore = $newestId();
    add_action( 'woocommerce_new_product', $late, 10, 1 );
    try {
        [ , $err ] = $run( [ 'products' => [ [ 'name' => $lateName, 'sku' => AIED_CP_SKU . 'LATE' ] ] ] );
    } finally {
        remove_action( 'woocommerce_new_product', $late, 10 );
    }
    $lateLeft = $withSkuOrName( AIED_CP_SKU . 'LATE', $lateName );
    $check( null !== $err && ! str_starts_with( $err, 'UNEXPECTED' ) && [] === $lateLeft && ! str_contains( $err, '/var/www' ) && $newestBefore === $newestId(), 'a failure after WooCommerce set the id (woocommerce_new_product) deletes our draft, shows no path, records no batch: ' . (string) $err );
} catch ( Throwable $e ) {
    $check( false, 'unexpected error: ' . $e->getMessage() );
} finally {
    global $wpdb;
    wp_set_current_user( 1 );
    $removeThrowaways();
    if ( [] !== $batchIds ) {
        $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batchIds ) ) ) . ')' ); // phpcs:ignore
    }
}

$check( [] === $mine() && [] === $myAttachments() && [] === $myTerms(), 'cleanup: every throwaway product (any status), the category, the tag and the files are gone' );
$check( ! get_user_by( 'login', AIED_CP_USERS['editor'] ) && ! get_user_by( 'login', AIED_CP_USERS['shop_manager'] ), 'cleanup: the throwaway users are gone' );
$leftBatches = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batchIds ) ) ) . ')' ); // phpcs:ignore
$check( 0 === $leftBatches, 'cleanup: the ' . count( array_unique( $batchIds ) ) . ' batch rows this run recorded are gone' );
$check( $snapshot() === $before, 'cleanup: every original product and variation is byte-identical (' . $before . ')' );
$check( $beforeTotal === $totalProducts() && $beforeOnly === $totalProducts( "'product'" ), "cleanup: the numbers of products ({$beforeOnly}) and of products and variations ({$beforeTotal}) in every status, the trash included, are the original ones again" );

if ( [] !== $failures ) {
    WP_CLI::error( 'create_product NOT verified: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'create_product verified on the real site.' );
