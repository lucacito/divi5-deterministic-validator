<?php
/**
 * Real-site check of the Pro tool bulk_adjust_prices: the real tool with the REAL WooProductWriter (WooCommerce's own CRUD classes, its
 * real catalogue query), the real batch table and undo, and the free plugin's own read path (ProductService::get) plus WooCommerce's
 * getters, so what the tool calculated is compared with what WooCommerce stored through independent code paths. It proves: a dry run
 * changes nothing and returns a token; a real run needs the single-use token; percent and amount changes are applied with the shop's
 * rounding and with round_to; the filter finds what it should (category, draft status, ids, the 100-product cap); sale prices are never
 * touched; products that cannot be adjusted are skipped with the right reason, a variable product as a whole; a product changed between
 * preview and run is skipped and one that newly matches is left alone; a failing writer and a writer that saves something else put
 * every product back and record no batch; one undo restores every price exactly (and refuses, reason conflicts, when a sale price added
 * since would be discarded); an editor without edit_products is refused; and the products the site already has are byte-identical afterwards.
 *
 * Self-cleaning: it force-deletes every product it created (SKU AIED-BA-*, or name "AIED BA ..."; also trashed ones), its product
 * categories (slugs aied-ba-*), its throwaway users (aied_pro_ba_*) and the batch rows it recorded. It records many batches and the
 * batch store keeps only the newest 20, so it evicts EVERY batch row already on the dev site: dev sites only.
 *
 * Run: make verify-pro-bulk-adjust-prices   (needs `make up`, WooCommerce, the free plugin and the Pro add-on active; no licence is
 * needed: the tool is called directly, not through the licence-gated registry)
 *
 * A FAIL is a finding about the tool or the writer: report it, never edit this script to hide it. A NOTE records a behaviour of
 * WooCommerce that the design leaves open.
 */

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;
use AiEditorDivi5\Pro\Tools\BulkAdjustPrices;
use AiEditorDivi5\WP\ProductService;
use AiEditorDivi5\WP\WooProductGateway;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( BulkAdjustPrices::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro (with bulk_adjust_prices) on this site first.' );
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

const AIED_BA_SKU    = 'AIED-BA-';
const AIED_BA_NAME   = 'AIED BA ';
const AIED_BA_SLUG   = 'aied-ba-';
const AIED_BA_USERS  = [ 'editor' => 'aied_pro_ba_editor', 'shop_manager' => 'aied_pro_ba_shop' ];
const AIED_BA_SECRET = '/var/www/html/secret.php';

/** Decorates the real writer: the Nth apply() throws a foreign exception with a path in it, or one field is silently put back after a save. */
final class AiedBaFailingWriter implements ProductWriter
{
    public int $applyCalls = 0;

    public function __construct( private WooProductWriter $real, private int $failOn = 0, private ?string $dropField = null )
    {
    }

    public function isActive(): bool { return $this->real->isActive(); }
    public function skuTaken( string $sku ): bool { return $this->real->skuTaken( $sku ); }
    public function skuOwner( string $sku ): int { return $this->real->skuOwner( $sku ); }
    public function termIds( string $taxonomy, array $slugs ): array { return $this->real->termIds( $taxonomy, $slugs ); }
    public function imageUsable( int $id ): bool { return $this->real->imageUsable( $id ); }
    public function create( array $values ): int { return $this->real->create( $values ); }
    public function snapshot( int $id ): ?array { return $this->real->snapshot( $id ); }
    public function typeOf( int $id ): ?string { return $this->real->typeOf( $id ); }
    public function product( int $id ): ?array { return $this->real->product( $id ); }
    public function delete( int $id ): bool { return $this->real->delete( $id ); }
    public function editUrl( int $id ): string { return $this->real->editUrl( $id ); }
    public function findProducts( array $criteria, int $limit ): array { return $this->real->findProducts( $criteria, $limit ); }
    public function priceDecimals(): int { return $this->real->priceDecimals(); }

    public function apply( int $id, array $changes ): void
    {
        if ( $this->failOn === ++$this->applyCalls ) {
            throw new \RuntimeException( AIED_BA_SECRET . ' simulated writer failure' );
        }
        $was = null !== $this->dropField ? ( $this->real->product( $id )[ $this->dropField ] ?? null ) : null;
        $this->real->apply( $id, $changes );
        if ( null !== $this->dropField && array_key_exists( $this->dropField, $changes ) && 1 === $this->applyCalls ) {
            $this->real->apply( $id, [ $this->dropField => $was ] ); // the store "saved something else"
        }
    }
}

/** Delete the throwaway users this script creates: only those exact logins, never user 1. */
$deleteUsers = static function (): void {
    foreach ( AIED_BA_USERS as $login ) {
        $u = get_user_by( 'login', $login );
        if ( $u instanceof \WP_User && $login === $u->user_login && (int) $u->ID > 1 ) {
            wp_delete_user( (int) $u->ID );
        }
    }
};
/** Every product or variation (any status, the trash too) this script could have made: SKU AIED-BA-* or name "AIED BA ...". */
$mine = static function (): array {
    global $wpdb;
    $bySku  = (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' WHERE p.post_type IN ('product','product_variation') AND m.meta_value LIKE %s",
        $wpdb->esc_like( AIED_BA_SKU ) . '%'
    ) );
    $byName = (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_title LIKE %s",
        $wpdb->esc_like( AIED_BA_NAME ) . '%'
    ) );
    $ids = array_values( array_unique( array_map( 'intval', array_merge( $bySku, $byName ) ) ) );
    sort( $ids );

    return $ids;
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
        $wpdb->esc_like( AIED_BA_NAME ) . '%'
    ) ) );
};
$myTerms = static function (): array {
    $out = [];
    foreach ( [ 'product_cat', 'product_tag' ] as $tax ) {
        $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
        foreach ( is_array( $terms ) ? $terms : [] as $t ) {
            if ( str_starts_with( (string) $t->slug, AIED_BA_SLUG ) ) {
                $out[] = [ (int) $t->term_id, $tax ];
            }
        }
    }

    return $out;
};
$removeThrowaways = static function () use ( $deleteUsers, $mine, $forceDelete, $myAttachments, $myTerms ): void {
    foreach ( array_reverse( $mine() ) as $id ) { // variations (higher ids) before their parents
        $forceDelete( $id );
    }
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
$note = static function ( string $label ): void {
    WP_CLI::log( 'NOTE  ' . $label );
};

wp_set_current_user( 1 );
// A previous run that was killed may have left its throwaways behind: remove only those exact ones.
$removeThrowaways();

$batchIds = [];
BatchTable::ensureReady( true );
$check( BatchTable::exists(), 'the batch table exists on the real database (' . BatchTable::name() . ')' );
$log    = Batches::log();
$undo   = Batches::undo();
$tool   = new BulkAdjustPrices( $log );
$writer = new WooProductWriter();

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
$run = static function ( array $args, ?BulkAdjustPrices $with = null ) use ( &$tool, $track ): array {
    try {
        return [ $track( ( $with ?? $tool )->run( $args ) ), null ];
    } catch ( \InvalidArgumentException $e ) {
        return [ [], $e->getMessage() ];
    } catch ( \Throwable $e ) {
        return [ [], 'UNEXPECTED ' . get_class( $e ) . ': ' . $e->getMessage() ];
    }
};
/** Preview, then a real run with the token. Returns [ preview, result, error ]. */
$go = static function ( array $args, ?BulkAdjustPrices $with = null ) use ( $run ): array {
    [ $p, $perr ] = $run( $args + [ 'dry_run' => true ], $with );
    if ( null !== $perr || empty( $p['token'] ) ) {
        return [ $p, [], $perr ?? 'the preview made no token' ];
    }
    [ $r, $err ] = $run( $args + [ 'token' => $p['token'] ], $with );

    return [ $p, $r, $err ];
};
$newestId = static fn (): int => (int) ( $log->recent( 1 )[0]['id'] ?? 0 );
/** The free plugin's get_product, through its own gateway: a read path independent of WooProductWriter::product(). */
$readBack = static function ( int $id ): array {
    clean_post_cache( $id );
    $r = ProductService::get( $id, new WooProductGateway(), 'current_user_can' );

    return true === ( $r['ok'] ?? false ) ? (array) ( $r['data']['product'] ?? [] ) : [];
};
$termIds = static fn ( array $p, string $key ): array => array_map( 'intval', array_column( (array) ( $p[ $key ] ?? [] ), 'id' ) );
/** WooCommerce's own getters (edit context) for one product or variation: a second independent read path. */
$wc = static function ( int $id ): array {
    clean_post_cache( $id );
    $p = wc_get_product( $id );
    if ( ! $p ) {
        return [];
    }
    $from = $p->get_date_on_sale_from( 'edit' );
    $to   = $p->get_date_on_sale_to( 'edit' );

    return [
        'regular' => (string) $p->get_regular_price( 'edit' ), 'sale' => (string) $p->get_sale_price( 'edit' ), 'price' => (string) $p->get_price( 'edit' ),
        'from' => $from ? $from->date( 'Y-m-d' ) : '', 'to' => $to ? $to->date( 'Y-m-d' ) : '', 'on_sale' => $p->is_on_sale( 'edit' ),
        'manage' => (bool) $p->get_manage_stock( 'edit' ), 'qty' => $p->get_stock_quantity( 'edit' ), 'status' => (string) $p->get_stock_status( 'edit' ),
        'slug' => (string) get_post_field( 'post_name', $id ), 'post_status' => (string) get_post_status( $id ), 'date' => (string) get_post_field( 'post_date', $id ),
    ];
};
$day = static fn ( int $offset ): string => (string) wp_date( 'Y-m-d', time() + $offset * DAY_IN_SECONDS );

$makeSimple = static function ( string $name, string $sku, array $props ): int {
    $p = new WC_Product_Simple();
    $p->set_name( $name );
    $p->set_sku( $sku );
    $p->set_status( 'publish' );
    foreach ( $props as $key => $value ) {
        $p->{'set_' . $key}( $value );
    }

    return (int) $p->save();
};

$before       = $snapshot();
$beforeTotal  = $totalProducts();
$beforeOnly   = $totalProducts( "'product'" );
$beforeIds    = $mine();
$check( [] === $beforeIds, "the site starts without throwaways ({$beforeOnly} products, {$beforeTotal} products and variations, in every status)" );

$users = [];
try {
    // ---- fixtures ------------------------------------------------------------------------------------------------------------
    $cat   = wp_insert_term( AIED_BA_NAME . 'Cat', 'product_cat', [ 'slug' => AIED_BA_SLUG . 'cat' ] );
    $other = wp_insert_term( AIED_BA_NAME . 'Other', 'product_cat', [ 'slug' => AIED_BA_SLUG . 'other' ] );
    $many  = wp_insert_term( AIED_BA_NAME . 'Many', 'product_cat', [ 'slug' => AIED_BA_SLUG . 'many' ] );
    if ( is_wp_error( $cat ) || is_wp_error( $other ) || is_wp_error( $many ) ) {
        // Not WP_CLI::error(): it exits at once and would skip the cleanup in the `finally` below.
        throw new \RuntimeException( 'Could not create the throwaway categories.' );
    }
    $catId   = (int) $cat['term_id'];
    $otherId = (int) $other['term_id'];
    $manyId  = (int) $many['term_id'];
    $inCat   = static fn ( string $name, string $sku, array $props, int $cid = 0 ): int => $makeSimple( $name, $sku, $props + [ 'category_ids' => [ $cid > 0 ? $cid : $catId ] ] );

    $P1 = $inCat( AIED_BA_NAME . 'Hat', AIED_BA_SKU . '1', [ 'regular_price' => '20' ] );
    $P2 = $inCat( AIED_BA_NAME . 'Scarf', AIED_BA_SKU . '2', [ 'regular_price' => '19.99' ] );
    $P3 = $inCat( AIED_BA_NAME . 'Mug', AIED_BA_SKU . '3', [ 'regular_price' => '10', 'sale_price' => '8' ] );
    $P4 = $inCat( AIED_BA_NAME . 'Gift', AIED_BA_SKU . '4', [] );
    $P5 = $inCat( AIED_BA_NAME . 'Draft', AIED_BA_SKU . '5', [ 'regular_price' => '30', 'status' => 'draft' ] );
    $X  = $inCat( AIED_BA_NAME . 'Outsider', AIED_BA_SKU . 'X', [ 'regular_price' => '50' ], $otherId );

    $ext = new WC_Product_External();
    $ext->set_name( AIED_BA_NAME . 'External' );
    $ext->set_sku( AIED_BA_SKU . '6' );
    $ext->set_status( 'publish' );
    $ext->set_product_url( 'https://example.com/buy' );
    $ext->set_regular_price( '5' );
    $ext->set_category_ids( [ $catId ] );
    $P6 = (int) $ext->save();

    $var = new WC_Product_Variable();
    $var->set_name( AIED_BA_NAME . 'Variable' );
    $var->set_sku( AIED_BA_SKU . 'V' );
    $var->set_status( 'publish' );
    $var->set_category_ids( [ $catId ] );
    $attr = new WC_Product_Attribute();
    $attr->set_id( 0 );
    $attr->set_name( 'Size' );
    $attr->set_options( [ 'Small', 'Large' ] );
    $attr->set_position( 0 );
    $attr->set_visible( true );
    $attr->set_variation( true );
    $var->set_attributes( [ $attr ] );
    $V    = (int) $var->save();
    $vids = [];
    foreach ( [ 'Small' => [ '10', '' ], 'Large' => [ '12', '9' ] ] as $size => [ $price, $sale ] ) {
        $v = new WC_Product_Variation();
        $v->set_parent_id( $V );
        $v->set_attributes( [ 'size' => $size ] );
        $v->set_regular_price( $price );
        if ( '' !== $sale ) {
            $v->set_sale_price( $sale );
        }
        $v->set_status( 'publish' );
        $vids[ $size ] = (int) $v->save();
    }
    WC_Product_Variable::sync( $V );
    [ $vSmall, $vLarge ] = array_values( $vids );
    $check( $P1 > 0 && $P2 > 0 && $P3 > 0 && $P4 > 0 && $P5 > 0 && $P6 > 0 && $X > 0 && $V > 0 && $vSmall > 0 && $vLarge > 0 && $catId > 0 && $otherId > 0, 'the throwaway fixtures exist: six simple/external products (one without a price, one a draft), a variable product with two variations (one on sale), an outsider and categories' );
    $decimals = $writer->priceDecimals();
    $check( 2 === $decimals, "the shop shows prices with {$decimals} decimals (this gate's expectations assume 2)" );
    $allIds   = [ $P1, $P2, $P3, $P4, $P5, $P6, $X, $V ];
    $stateOf  = static function ( array $ids ) use ( $writer ): array {
        $out = [];
        foreach ( $ids as $id ) {
            clean_post_cache( $id );
            $out[ $id ] = $writer->product( $id );
        }

        return $out;
    };
    $fixtures = static fn (): string => md5( serialize( $stateOf( $allIds ) ) );
    $original = $stateOf( $allIds );
    $adjust   = static fn ( array $filter, string $mode, string $value, array $extra = [] ): array => [ 'filter' => $filter, 'change' => [ 'mode' => $mode, 'value' => $value ] ] + $extra;
    $inCatF   = [ 'categories' => [ AIED_BA_SLUG . 'cat' ] ];
    $undoLast = static function ( array $r ) use ( $undo, $trackUndo ): array {
        return $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    };

    // ---- preview: percent +10 over the category -------------------------------------------------------------------------------------
    $newestBefore = $newestId();
    $fixBefore    = $fixtures();
    [ $p, $err ] = $run( $adjust( $inCatF, 'percent', '10' ) + [ 'dry_run' => true ] );
    $ids = array_map( 'intval', array_column( (array) ( $p['products'] ?? [] ), 'id' ) );
    $check( null === $err && false === ( $p['changed'] ?? null ) && [ $P1, $P2, $P3, $P6, $V ] === $ids, 'a dry run over the category previews the five products that can change (draft, outsider and the price-less product are not among them)' . ( null === $err ? '' : ': ' . $err ) );
    $skipIds = array_map( 'intval', array_column( (array) ( $p['skipped'] ?? [] ), 'id' ) );
    $check( [ $P4 ] === $skipIds && 'no_regular_price' === ( $p['skipped'][0]['reason'] ?? '' ) && 6 === ( $p['summary']['matched'] ?? 0 ), 'the product without a regular price is skipped (no_regular_price); six products matched' );
    $check( ! empty( $p['token'] ) && $fixBefore === $fixtures() && $newestBefore === $newestId() && ! isset( $p['batch'] ), 'a dry run returns a token, changes nothing and records no batch' );
    $token = (string) ( $p['token'] ?? '' );
    [ , $err ] = $run( $adjust( $inCatF, 'percent', '10' ) );
    $check( null !== $err && str_contains( $err, 'needs the token' ) && $fixBefore === $fixtures(), 'a real run without a token is refused and changes nothing' );
    [ , $err ] = $run( $adjust( $inCatF, 'percent', '11' ) + [ 'token' => $token ] );
    $check( null !== $err && str_contains( $err, 'needs the token' ) && $fixBefore === $fixtures(), 'the token of another request is refused' );

    // ---- the real run ---------------------------------------------------------------------------------------------------------------
    [ $r, $err ] = $run( $adjust( $inCatF, 'percent', '10' ) + [ 'token' => $token ] );
    $check( null === $err && true === ( $r['changed'] ?? null ) && 5 === count( (array) ( $r['updated'] ?? [] ) ) && true === ( $r['batch']['undoable'] ?? false ), 'a real run with the token updates the five products and records one undoable batch' . ( null === $err ? '' : ': ' . $err ) );
    $main = $r;
    $w1 = $readBack( $P1 );
    $w2 = $readBack( $P2 );
    $w3 = $readBack( $P3 );
    $w6 = $readBack( $P6 );
    $check( '22.00' === ( $w1['regular_price'] ?? null ) && '21.99' === ( $w2['regular_price'] ?? null ) && '11.00' === ( $w3['regular_price'] ?? null ) && '5.50' === ( $w6['regular_price'] ?? null ),
        'get_product (free) reads back the new regular prices: 20 -> 22.00, 19.99 -> 21.99 (21.989 rounded), 10 -> 11.00, external 5 -> 5.50' . ( '22.00' === ( $w1['regular_price'] ?? null ) ? '' : ': ' . wp_json_encode( [ $w1['regular_price'] ?? null, $w2['regular_price'] ?? null, $w3['regular_price'] ?? null, $w6['regular_price'] ?? null ] ) ) );
    $check( '8' === ( $w3['sale_price'] ?? null ) || '8.00' === ( $w3['sale_price'] ?? null ), 'a sale price is never touched (the mug still has its sale price 8)' );
    $check( '11.00' === $wc( $vSmall )['regular'] && '13.20' === $wc( $vLarge )['regular'] && in_array( $wc( $vLarge )['sale'], [ '9', '9.00' ], true ), 'the variable product\'s variation rows moved (10 -> 11.00, 12 -> 13.20) and the sale price of the second is untouched' );
    clean_post_cache( $V );
    $parent = wc_get_product( $V );
    $check( 9.0 === (float) $parent->get_variation_price( 'min', true ) && 11.0 === (float) $parent->get_variation_price( 'max', true ), 'the variable parent\'s price range follows its rows (min 9 = the sale price, max 11)' );
    $check( 'draft' === $wc( $P5 )['post_status'] && '30' === $wc( $P5 )['regular'] && '50' === $wc( $X )['regular'] && '' === $wc( $P4 )['regular'], 'the draft, the outsider and the product without a price are untouched' );
    $check( 'publish' === $wc( $P1 )['post_status'] && $original[ $P1 ]['status'] === $wc( $P1 )['post_status'], 'status is unchanged' );
    [ , $err ] = $run( $adjust( $inCatF, 'percent', '10' ) + [ 'token' => $token ] );
    $check( null !== $err && str_contains( $err, 'needs the token' ), 'the token works once' );

    // ---- one undo restores every price exactly; the undo can be undone ------------------------------------------------------------------
    $u1 = $undoLast( $main );
    $check( 5 === ( $u1['restored'] ?? 0 ) && true === ( $u1['fully_undone'] ?? false ) && $original === $stateOf( $allIds ), 'undo_batch restores all five products and every product state equals its original' );
    $u2 = $trackUndo( $undo->undo( (int) ( $u1['undo_batch']['id'] ?? 0 ) ) );
    $check( 5 === ( $u2['restored'] ?? 0 ) && '22.00' === $wc( $P1 )['regular'], 'the undo can be undone' );
    $trackUndo( $undo->undo( (int) ( $u2['undo_batch']['id'] ?? 0 ) ) );
    $check( $original === $stateOf( $allIds ), 'and undone once more: the originals are back' );

    // ---- amount, round_to and the skip reasons -------------------------------------------------------------------------------------------
    [ $p, $r, $err ] = $go( $adjust( [ 'ids' => [ $P1, $P3 ] ], 'amount', '-5' ) );
    $check( null === $err && [ $P1 ] === array_map( 'intval', array_column( (array) ( $r['updated'] ?? [] ) , 'id' ) ) && '15.00' === $wc( $P1 )['regular'], 'an amount of -5 takes the hat from 20 to 15.00' . ( null === $err ? '' : ': ' . $err ) );
    $check( 'not_above_sale' === ( array_column( (array) ( $p['skipped'] ?? [] ), 'reason', 'id' )[ $P3 ] ?? '' ) && '10' === $wc( $P3 )['regular'], 'the mug (10 - 5 = 5, below its sale price 8) is skipped with not_above_sale and keeps its price' );
    $undoLast( $r );
    [ , $r, $err ] = $go( $adjust( [ 'ids' => [ $P1 ] ], 'percent', '10', [ 'round_to' => '0.95' ] ) );
    $check( null === $err && '21.95' === $wc( $P1 )['regular'], 'round_to 0.95 makes the new price end in .95 (22.00 -> 21.95)' . ( null === $err ? '' : ': ' . $err ) );
    $undoLast( $r );
    [ $p ] = $run( $adjust( [ 'ids' => [ $P1 ] ], 'amount', '-20', [ 'dry_run' => true ] ) );
    $check( 'not_positive' === ( $p['skipped'][0]['reason'] ?? '' ), 'a change that would make the price zero is skipped (not_positive)' );
    [ $p ] = $run( $adjust( [ 'ids' => [ $V ] ], 'amount', '-5', [ 'dry_run' => true ] ) );
    $check( 'variation_blocked' === ( $p['skipped'][0]['reason'] ?? '' ) && [ $vLarge ] === array_map( 'intval', array_column( (array) ( $p['skipped'][0]['rows'] ?? [] ), 'id' ) ) && empty( $p['token'] ), 'a variable product with one blocked row is skipped as a whole (12 - 5 = 7 is below the sale 9); the other row is not adjusted either' );

    // ---- the filter ---------------------------------------------------------------------------------------------------------------------
    [ $p ] = $run( $adjust( $inCatF, 'percent', '10', [ 'dry_run' => true ] ) );
    $check( ! in_array( $P5, array_map( 'intval', array_column( (array) ( $p['products'] ?? [] ), 'id' ) ), true ), 'only published products are matched by default' );
    [ $p, $r, $err ] = $go( $adjust( [ 'ids' => [ $P5 ], 'status' => [ 'draft' ] ], 'percent', '10' ) );
    $check( null === $err && '33.00' === $wc( $P5 )['regular'] && 'draft' === $wc( $P5 )['post_status'], 'with status ["draft"] the draft is adjusted (30 -> 33.00) and stays a draft' . ( null === $err ? '' : ': ' . $err ) );
    $undoLast( $r );
    [ $p ] = $run( $adjust( [ 'on_sale' => true, 'categories' => [ AIED_BA_SLUG . 'cat' ] ], 'percent', '10', [ 'dry_run' => true ] ) );
    $check( [ $P3, $V ] === array_map( 'intval', array_column( (array) ( $p['products'] ?? [] ), 'id' ) ), 'on_sale=true finds the products that are on sale now (the mug and the variable product with a sale variation) - decided in PHP' );
    [ , $err ] = $run( $adjust( [ 'categories' => [ AIED_BA_SLUG . 'nope' ] ], 'percent', '10', [ 'dry_run' => true ] ) );
    $check( null !== $err && str_contains( $err, 'does not exist' ), 'an unknown category slug is refused by name' );
    [ , $err ] = $run( $adjust( [ 'status' => [ 'draft' ] ], 'percent', '10', [ 'dry_run' => true ] ) );
    $check( null !== $err && str_contains( $err, 'must narrow the products' ), 'a filter that narrows nothing is refused' );
    [ , $err ] = $run( [ 'filter' => $inCatF, 'change' => [ 'mode' => 'percent', 'value' => 10 ], 'dry_run' => true ] );
    $check( null !== $err && str_contains( $err, 'signed decimal string' ), 'a number instead of a string for the change is refused' );

    // ---- between the preview and the run ------------------------------------------------------------------------------------------------
    [ $p ] = $run( $adjust( $inCatF, 'percent', '10', [ 'dry_run' => true ] ) );
    $edit = wc_get_product( $P1 );
    $edit->set_regular_price( '21' ); // the owner edits it after the preview
    $edit->save();
    $late = $inCat( AIED_BA_NAME . 'Late', AIED_BA_SKU . 'L', [ 'regular_price' => '40' ] ); // matches the filter now, was not previewed
    [ $r, $err ] = $run( $adjust( $inCatF, 'percent', '10' ) + [ 'token' => (string) ( $p['token'] ?? '' ) ] );
    $skippedBy = array_column( (array) ( $r['skipped'] ?? [] ), 'reason', 'id' );
    $updatedIds = array_map( 'intval', array_column( (array) ( $r['updated'] ?? [] ), 'id' ) );
    sort( $updatedIds );
    $expectUpd = [ $P2, $P3, $P6, $V ];
    sort( $expectUpd );
    $check( null === $err && 'changed_since_preview' === ( $skippedBy[ $P1 ] ?? '' ) && 'no_regular_price' === ( $skippedBy[ $P4 ] ?? '' ) && 2 === count( $skippedBy ) && '21' === $wc( $P1 )['regular'], 'a product changed after the preview is skipped with exactly the reason changed_since_preview (besides the price-less gift, no_regular_price) and keeps the owner\'s price' . ( null === $err ? '' : ': ' . $err ) . ' (skipped: ' . wp_json_encode( $skippedBy ) . ')' );
    $check( $expectUpd === $updatedIds && '21.99' === $wc( $P2 )['regular'], 'in that same run the other four previewed products (scarf, mug, external, variable) were updated (updated: ' . wp_json_encode( $updatedIds ) . ')' );
    $check( 1 === ( $r['not_in_preview'] ?? -1 ) && '40' === $wc( $late )['regular'], 'a product that matches now but was not previewed is not touched (not_in_preview: 1)' );
    $undoLast( $r );
    $edit = wc_get_product( $P1 );
    $edit->set_regular_price( '20' );
    $edit->save();
    wp_delete_post( $late, true );

    // ---- failure paths: every product the run touched is put back, no batch ----------------------------------------------------------------------
    $fixBefore    = $fixtures();
    $stateBefore  = $stateOf( $allIds );
    $newestBefore = $newestId();
    $failWriter = new AiedBaFailingWriter( new WooProductWriter(), 2 );
    $failing    = new BulkAdjustPrices( $log, $failWriter );
    [ , , $err ] = $go( $adjust( $inCatF, 'percent', '10' ), $failing );
    $check( null !== $err && str_contains( $err, 'Could not update' ) && ! str_contains( $err, '/var/www' ) && ! str_contains( $err, 'simulated' ), 'a failing second save is refused with a message that shows no path: ' . (string) $err );
    $check( $fixBefore === $fixtures() && $stateBefore === $stateOf( $allIds ) && $newestBefore === $newestId(), 'after the failing save every product is exactly as before and no batch was recorded' );
    $check( $failWriter->applyCalls >= 3, "the failing run saved the first product, failed on the second and re-applied the first one's original values through the writer (apply called {$failWriter->applyCalls} times, at least 3)" );
    $dropping = new BulkAdjustPrices( $log, new AiedBaFailingWriter( new WooProductWriter(), 0, 'regular_price' ) );
    [ , , $err ] = $go( $adjust( $inCatF, 'percent', '10' ), $dropping );
    $check( null !== $err && str_contains( $err, 'saved differently than asked (regular_price)' ), 'a store that saves something else is caught by the read-back: ' . (string) $err );
    $check( $fixBefore === $fixtures() && $stateBefore === $stateOf( $allIds ) && $newestBefore === $newestId(), 'after the read-back difference every product is exactly as before and no batch was recorded' );

    // ---- undo refuses when it would make WooCommerce discard a sale price added since ---------------------------------------------------------
    [ , $r, $err ] = $go( $adjust( [ 'ids' => [ $P1 ] ], 'percent', '10' ) );
    $edit = wc_get_product( $P1 );
    $edit->set_sale_price( '21' ); // valid under the new 22.00, but not under the old 20
    $edit->save();
    $ux = $undoLast( $r );
    $check( 0 === ( $ux['restored'] ?? -1 ) && 'conflicts' === ( $ux['items'][0]['reason'] ?? '' ) && '22.00' === $wc( $P1 )['regular'] && in_array( $wc( $P1 )['sale'], [ '21', '21.00' ], true ), 'undo refuses (conflicts) rather than let WooCommerce discard the sale price the owner added since' );
    $edit = wc_get_product( $P1 );
    $edit->set_sale_price( '' );
    $edit->set_regular_price( '20' );
    $edit->save();

    // ---- the 100-product cap ------------------------------------------------------------------------------------------------------------
    for ( $i = 1; $i <= 101; $i++ ) {
        $makeSimple( AIED_BA_NAME . "M{$i}", AIED_BA_SKU . "M{$i}", [ 'regular_price' => '10', 'category_ids' => [ $manyId ] ] );
    }
    $fixBefore    = $fixtures();
    $newestBefore = $newestId();
    [ , $err ] = $run( $adjust( [ 'categories' => [ AIED_BA_SLUG . 'many' ] ], 'percent', '10', [ 'dry_run' => true ] ) );
    $check( null !== $err && str_contains( $err, 'More than 100 products match' ) && $newestBefore === $newestId(), '101 matching products are refused (never truncated), nothing is changed' );

    // ---- permissions --------------------------------------------------------------------------------------------------------------------
    foreach ( AIED_BA_USERS as $role => $login ) {
        $uid = wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.invalid', 'role' => $role ] );
        if ( is_wp_error( $uid ) ) {
            throw new \RuntimeException( 'Could not create a throwaway user.' );
        }
        $users[ $role ] = (int) $uid;
    }
    wp_set_current_user( $users['editor'] );
    [ , $err ] = $run( $adjust( $inCatF, 'percent', '10', [ 'dry_run' => true ] ) );
    $check( null !== $err && str_contains( $err, 'edit_products' ), 'an editor without edit_products is refused' );
    wp_set_current_user( $users['shop_manager'] );
    [ , $r, $err ] = $go( $adjust( [ 'ids' => [ $P1 ] ], 'percent', '10' ) );
    $check( null === $err && '22.00' === $wc( $P1 )['regular'], 'a shop manager adjusts a price' . ( null === $err ? '' : ': ' . $err ) );
    $undoLast( $r );
    wp_set_current_user( 1 );

    // ---- ids with exclude_ids: the exclusion is honoured (a WP_Query quirk once ignored it when ids were given) -----------------------------------
    [ $p, $r, $err ] = $go( $adjust( [ 'ids' => [ $P1, $P2 ], 'exclude_ids' => [ $P2 ] ], 'percent', '10' ) );
    $listed = array_map( 'intval', array_column( (array) ( $p['products'] ?? [] ), 'id' ) );
    $check( null === $err && [ $P1 ] === $listed && ! in_array( $P2, array_map( 'intval', array_column( (array) ( $p['skipped'] ?? [] ), 'id' ) ), true ) && '22.00' === $wc( $P1 )['regular'] && '19.99' === $wc( $P2 )['regular'],
        'ids [hat, scarf] with exclude_ids [scarf]: the dry run lists only the hat, the run adjusts the hat (20 -> 22.00) and leaves the scarf at 19.99' . ( null === $err ? '' : ': ' . $err ) . ' (listed: ' . wp_json_encode( $listed ) . ')' );
    $undoLast( $r );
    $check( '20' === $wc( $P1 )['regular'], 'the exclude_ids run is undone (the hat is back at 20)' );

    // ---- search matches the product NAME only ------------------------------------------------------------------------------------------------
    $plain = $makeSimple( AIED_BA_NAME . 'Plain', AIED_BA_SKU . 'PL', [ 'regular_price' => '12', 'description' => 'A long text with the unique word aiedbazinga in it', 'short_description' => 'aiedbazinga', 'category_ids' => [ $catId ] ] );
    [ $p, $err ] = $run( $adjust( [ 'search' => 'aiedbazinga' ], 'percent', '10', [ 'dry_run' => true ] ) );
    $listed = array_map( 'intval', array_column( (array) ( $p['products'] ?? [] ), 'id' ) );
    $check( null === $err && $plain > 0 && ! in_array( $plain, $listed, true ) && [] === $listed, 'a search for a word that is only in a product DESCRIPTION lists nothing, and is answered normally, not by a refusal (the name is what is matched)' . ( null === $err ? '' : ' [answer: ' . $err . ']' ) . ' (listed: ' . wp_json_encode( $listed ) . ')' );
    $odd = $makeSimple( AIED_BA_NAME . 'Odd -Scarf Hat', AIED_BA_SKU . 'ODD', [ 'regular_price' => '15' ] ); // positive control: its name holds the literal text "-Scarf"
    [ $p, $err ] = $run( $adjust( [ 'search' => 'Scarf' ], 'percent', '10', [ 'dry_run' => true ] ) );
    $listed = array_map( 'intval', array_column( (array) ( $p['products'] ?? [] ), 'id' ) );
    $check( null === $err && in_array( $P2, $listed, true ) && in_array( $odd, $listed, true ) && ! in_array( $P1, $listed, true ) && ! in_array( $plain, $listed, true ), 'a search for "Scarf" (a word in a product NAME) lists the scarf and the odd -Scarf hat, not the hat' . ( null === $err ? '' : ': ' . $err ) );
    [ $p, $err ] = $run( $adjust( [ 'search' => '-Scarf', 'ids' => [ $odd, $P2 ] ], 'percent', '10', [ 'dry_run' => true ] ) );
    $listed = array_map( 'intval', array_column( (array) ( $p['products'] ?? [] ), 'id' ) );
    $check( null === $err && [ $odd ] === $listed, 'a search for "-Scarf" (with ids [odd hat, scarf]) lists exactly the product whose name holds the literal text "-Scarf": the phrase is matched literally, not read as an exclusion' . ( null === $err ? '' : ': ' . $err ) . ' (listed: ' . wp_json_encode( $listed ) . ')' );
    [ $p, $err ] = $run( $adjust( [ 'search' => '-Scarf' ], 'percent', '10', [ 'dry_run' => true ] ) ); // no ids; the 101 cap products exist, so an exclusion regression would match >100 products and be refused
    $listed = array_map( 'intval', array_column( (array) ( $p['products'] ?? [] ), 'id' ) );
    $check( null === $err && in_array( $odd, $listed, true ) && ! in_array( $P2, $listed, true ) && ! in_array( $P1, $listed, true ), 'a search for "-Scarf" alone (no ids) is answered normally and lists the odd -Scarf hat but neither the scarf nor the hat' . ( null === $err ? '' : ': ' . $err ) . ' (listed: ' . wp_json_encode( $listed ) . ')' );

    // ---- a child category is included when its parent is filtered ------------------------------------------------------------------------------
    $child = wp_insert_term( AIED_BA_NAME . 'Child', 'product_cat', [ 'slug' => AIED_BA_SLUG . 'child', 'parent' => $catId ] );
    if ( is_wp_error( $child ) ) {
        throw new \RuntimeException( 'Could not create the throwaway child category.' );
    }
    $childProd = $makeSimple( AIED_BA_NAME . 'Child Product', AIED_BA_SKU . 'CH', [ 'regular_price' => '8', 'category_ids' => [ (int) $child['term_id'] ] ] );
    [ $p, $err ] = $run( $adjust( $inCatF, 'percent', '10', [ 'dry_run' => true ] ) );
    $listed = array_map( 'intval', array_column( (array) ( $p['products'] ?? [] ), 'id' ) );
    $check( null === $err && in_array( $childProd, $listed, true ), 'filtering by the parent category aied-ba-cat includes the product that sits only in its CHILD category aied-ba-child' . ( null === $err ? '' : ': ' . $err ) );
    [ $p, $err ] = $run( $adjust( [ 'categories' => [ AIED_BA_SLUG . 'child' ] ], 'percent', '10', [ 'dry_run' => true ] ) );
    $listed = array_map( 'intval', array_column( (array) ( $p['products'] ?? [] ), 'id' ) );
    $check( null === $err && [ $childProd ] === $listed, 'filtering by the child category alone lists only the child product' . ( null === $err ? '' : ': ' . $err ) );

    // ---- a stored price that is not a plain decimal is skipped, never an exception -----------------------------------------------------------------
    // WooCommerce itself normalises a stored "1,5" to 15 on every read (wc_format_decimal drops the comma), so the tool can only ever see the
    // normalised value: that case is recorded as a NOTE. What survives WooCommerce's reading and PriceAdjuster cannot handle is a price with
    // more than 10 integer digits or more than 6 decimals: those must be skipped with unreadable_price.
    $bad = $makeSimple( AIED_BA_NAME . 'Comma', AIED_BA_SKU . 'CM', [ 'regular_price' => '10', 'category_ids' => [ $catId ] ] );
    update_post_meta( $bad, '_regular_price', '1,5' );
    update_post_meta( $bad, '_price', '1,5' );
    clean_post_cache( $bad );
    [ $p, $err ] = $run( $adjust( [ 'ids' => [ $bad ] ], 'percent', '10', [ 'dry_run' => true ] ) );
    $note( 'a regular price stored as "1,5" is read by WooCommerce as "' . (string) $writer->product( $bad )['regular_price'] . '" (so the dry run plans ' . wp_json_encode( $p['products'][0]['changes']['regular_price'] ?? null ) . '); the tool cannot see the raw meta' . ( null === $err ? '' : ' [answer: ' . $err . ']' ) );
    foreach ( [ [ '12345678901234', 'more than 10 integer digits' ], [ '1.1234567', 'more than 6 decimals' ], [ '-5', 'a malformed shape: a negative price' ] ] as [ $raw, $why ] ) { // a list of pairs: a numeric-string array key would turn into an int
        update_post_meta( $bad, '_regular_price', $raw );
        update_post_meta( $bad, '_price', $raw );
        clean_post_cache( $bad );
        if ( $raw !== (string) $writer->product( $bad )['regular_price'] ) {
            $note( "WooCommerce reads a stored \"{$raw}\" as \"" . (string) $writer->product( $bad )['regular_price'] . '", so this shape cannot reach the tool' );
            continue;
        }
        [ $p, $err ] = $run( $adjust( [ 'ids' => [ $bad ] ], 'percent', '10', [ 'dry_run' => true ] ) );
        $check( null === $err && 'unreadable_price' === ( $p['skipped'][0]['reason'] ?? '' ) && $bad === (int) ( $p['skipped'][0]['id'] ?? 0 ) && [] === ( $p['products'] ?? [] ) && empty( $p['token'] ),
            "a regular price stored as \"{$raw}\" ({$why}) is skipped with unreadable_price (no exception, nothing planned, no token)" . ( null === $err ? '' : ': ' . $err ) . ' (skipped: ' . wp_json_encode( $p['skipped'] ?? null ) . ')' );
        $check( $raw === get_post_meta( $bad, '_regular_price', true ), "the unreadable price \"{$raw}\" is left exactly as stored" );
    }
    update_post_meta( $bad, '_regular_price', '10' );
    update_post_meta( $bad, '_price', '10' );

    // ---- everything the checks above changed is back ----------------------------------------------------------------------------------------
    $check( $original === $stateOf( $allIds ), 'the eight fixtures end in exactly their original state, variation rows included' );
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

$check( [] === $mine() && [] === $myTerms(), 'cleanup: every throwaway product (any status) and category is gone' );
$check( ! get_user_by( 'login', AIED_BA_USERS['editor'] ) && ! get_user_by( 'login', AIED_BA_USERS['shop_manager'] ), 'cleanup: the throwaway users are gone' );
$leftBatches = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batchIds ) ) ) . ')' ); // phpcs:ignore
$check( 0 === $leftBatches, 'cleanup: the ' . count( array_unique( $batchIds ) ) . ' batch rows this run recorded are gone' );
$check( $snapshot() === $before, 'cleanup: every original product and variation is byte-identical (' . $before . ')' );
$check( $beforeTotal === $totalProducts() && $beforeOnly === $totalProducts( "'product'" ), "cleanup: the numbers of products ({$beforeOnly}) and of products and variations ({$beforeTotal}) in every status, the trash included, are the original ones again" );

if ( [] !== $failures ) {
    WP_CLI::error( 'bulk_adjust_prices NOT verified: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'bulk_adjust_prices verified on the real site.' );
