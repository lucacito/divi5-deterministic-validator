<?php
/**
 * Real-site check of the Pro tool update_products: the real tool with the REAL WooProductWriter (WooCommerce's own CRUD classes), the
 * real batch table, the real undo, WordPress's REAL wp_kses_post() and wp_strip_all_tags() (the unit tests only use stand-ins), and
 * the free plugin's own read path (ProductService::get) plus WooCommerce's getters, so what the AI asked for is compared with what
 * WooCommerce stored through independent code paths. It proves: a dry run changes nothing; a real run needs the single-use token;
 * prices, a sale window, stock, texts, SKU, terms, images, weight and variation rows are applied and read back; the variable parent's
 * derived price follows its variations; a product changed between the preview and the run is skipped; a failing writer, and a
 * writer that saves something else, put every product back and record no batch; one undo restores every value exactly and an undo
 * can be undone; a field edited after the run is skipped by the undo; the shop manager role works (core kses stores "&" as "&amp;");
 * an editor without edit_products is refused; grouped, trashed, missing and variation ids are refused; and that the products the
 * site already has are byte-identical afterwards.
 *
 * Self-cleaning: it force-deletes every product it created (SKU AIED-UP-*, or name "AIED UP ..."; also trashed ones), its product
 * category and tag (slugs aied-up-*), its Media Library files ("AIED UP ..."), its throwaway users (aied_pro_up_*) and the batch rows
 * it recorded. It records about 22 batches and the batch store keeps only the newest 20, so it evicts EVERY batch row already on the
 * dev site: dev sites only.
 *
 * Run: make verify-pro-update-products   (needs `make up`, WooCommerce, the free plugin and the Pro add-on active; no licence is
 * needed: the tool is called directly, not through the licence-gated registry)
 *
 * A FAIL is a finding about the tool or the writer: report it, never edit this script to hide it. A NOTE records a behaviour of
 * WooCommerce that the design leaves open (see the spec's "Open points").
 */

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;
use AiEditorDivi5\Pro\Tools\UpdateProducts;
use AiEditorDivi5\WP\ProductService;
use AiEditorDivi5\WP\WooProductGateway;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( UpdateProducts::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro (with update_products) on this site first.' );
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

const AIED_UP_SKU    = 'AIED-UP-';
const AIED_UP_NAME   = 'AIED UP ';
const AIED_UP_SLUG   = 'aied-up-';
const AIED_UP_USERS  = [ 'editor' => 'aied_pro_up_editor', 'shop_manager' => 'aied_pro_up_shop' ];
const AIED_UP_SECRET = '/var/www/html/secret.php';

/** Decorates the real writer: the Nth apply() throws a foreign exception with a path in it, or one field is silently put back after a save. */
final class AiedUpFailingWriter implements ProductWriter
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

    public function apply( int $id, array $changes ): void
    {
        if ( $this->failOn === ++$this->applyCalls ) {
            throw new \RuntimeException( AIED_UP_SECRET . ' simulated writer failure' );
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
    foreach ( AIED_UP_USERS as $login ) {
        $u = get_user_by( 'login', $login );
        if ( $u instanceof \WP_User && $login === $u->user_login && (int) $u->ID > 1 ) {
            wp_delete_user( (int) $u->ID );
        }
    }
};
/** Every product or variation (any status, the trash too) this script could have made: SKU AIED-UP-* or name "AIED UP ...". */
$mine = static function (): array {
    global $wpdb;
    $bySku  = (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' WHERE p.post_type IN ('product','product_variation') AND m.meta_value LIKE %s",
        $wpdb->esc_like( AIED_UP_SKU ) . '%'
    ) );
    $byName = (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_title LIKE %s",
        $wpdb->esc_like( AIED_UP_NAME ) . '%'
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
        $wpdb->esc_like( AIED_UP_NAME ) . '%'
    ) ) );
};
$myTerms = static function (): array {
    $out = [];
    foreach ( [ 'product_cat', 'product_tag' ] as $tax ) {
        $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
        foreach ( is_array( $terms ) ? $terms : [] as $t ) {
            if ( str_starts_with( (string) $t->slug, AIED_UP_SLUG ) ) {
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
$tool   = new UpdateProducts( $log );
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
$run = static function ( array $args, ?UpdateProducts $with = null ) use ( &$tool, $track ): array {
    try {
        return [ $track( ( $with ?? $tool )->run( $args ) ), null ];
    } catch ( \InvalidArgumentException $e ) {
        return [ [], $e->getMessage() ];
    } catch ( \Throwable $e ) {
        return [ [], 'UNEXPECTED ' . get_class( $e ) . ': ' . $e->getMessage() ];
    }
};
/** Preview, then a real run with the token. Returns [ preview, result, error ]. */
$go = static function ( array $updates, ?UpdateProducts $with = null ) use ( $run ): array {
    [ $p, $perr ] = $run( [ 'updates' => $updates, 'dry_run' => true ], $with );
    if ( null !== $perr || empty( $p['token'] ) ) {
        return [ $p, [], $perr ?? 'the preview made no token' ];
    }
    [ $r, $err ] = $run( [ 'updates' => $updates, 'token' => $p['token'] ], $with );

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
    $cat = wp_insert_term( AIED_UP_NAME . 'Cat', 'product_cat', [ 'slug' => AIED_UP_SLUG . 'cat' ] );
    $tag = wp_insert_term( AIED_UP_NAME . 'Tag', 'product_tag', [ 'slug' => AIED_UP_SLUG . 'tag' ] );
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
    $img = $attach( 'aied-up-image.png', $png, 'image/png', AIED_UP_NAME . 'image' );
    $doc = $attach( 'aied-up-doc.txt', 'not an image', 'text/plain', AIED_UP_NAME . 'doc' );

    $S = $makeSimple( AIED_UP_NAME . 'Simple', AIED_UP_SKU . 'S', [
        'regular_price' => '20', 'manage_stock' => true, 'stock_quantity' => 10, 'description' => '<p>Old description</p>', 'short_description' => 'Old short', 'weight' => '1', 'low_stock_amount' => 3,
    ] );
    $U = $makeSimple( AIED_UP_NAME . 'Plain', AIED_UP_SKU . 'U', [ 'stock_status' => 'outofstock' ] );
    $O = $makeSimple( AIED_UP_NAME . 'Other', AIED_UP_SKU . 'O', [ 'regular_price' => '5' ] );
    $T = $makeSimple( AIED_UP_NAME . 'Trashed', AIED_UP_SKU . 'T', [ 'regular_price' => '5' ] );
    wp_trash_post( $T );

    $ext = new WC_Product_External();
    $ext->set_name( AIED_UP_NAME . 'External' );
    $ext->set_sku( AIED_UP_SKU . 'E' );
    $ext->set_status( 'publish' );
    $ext->set_product_url( 'https://example.com/buy' );
    $ext->set_regular_price( '9' );
    $E = (int) $ext->save();

    $grp = new WC_Product_Grouped();
    $grp->set_name( AIED_UP_NAME . 'Grouped' );
    $grp->set_sku( AIED_UP_SKU . 'G' );
    $grp->set_status( 'publish' );
    $grp->set_children( [ $S ] );
    $G = (int) $grp->save();

    $var = new WC_Product_Variable();
    $var->set_name( AIED_UP_NAME . 'Variable' );
    $var->set_sku( AIED_UP_SKU . 'V' );
    $var->set_status( 'publish' );
    $attr = new WC_Product_Attribute();
    $attr->set_id( 0 );
    $attr->set_name( 'Size' );
    $attr->set_options( [ 'Small', 'Large', 'Huge' ] );
    $attr->set_position( 0 );
    $attr->set_visible( true );
    $attr->set_variation( true );
    $var->set_attributes( [ $attr ] );
    $V = (int) $var->save();
    $vids = [];
    foreach ( [ 'Small' => [ '10', 5 ], 'Large' => [ '12', 3 ], 'Huge' => [ '15', 0 ] ] as $size => [ $price, $qty ] ) {
        $v = new WC_Product_Variation();
        $v->set_parent_id( $V );
        $v->set_attributes( [ 'size' => $size ] );
        $v->set_regular_price( $price );
        $v->set_manage_stock( true );
        $v->set_stock_quantity( $qty );
        if ( 'Small' === $size ) {
            $v->set_low_stock_amount( 2 );
        }
        $v->set_status( 'publish' );
        $vids[ $size ] = (int) $v->save();
    }
    WC_Product_Variable::sync( $V );
    [ $vSmall, $vLarge, $vHuge ] = array_values( $vids );
    $check( $S > 0 && $U > 0 && $O > 0 && $T > 0 && $E > 0 && $G > 0 && $V > 0 && 3 === count( array_filter( $vids ) ) && $catId > 0 && $tagId > 0 && $img > 0 && $doc > 0,
        'the throwaway fixtures exist: simple, plain, other, trashed, external, grouped and variable products (3 variations), a category, a tag, an image and a text file' );
    $defaultCat = (int) get_option( 'default_product_cat', 0 );
    $allIds     = [ $S, $U, $O, $E, $G, $V ];
    $fixtures   = static function () use ( $allIds, $writer ): string {
        $rows = [];
        foreach ( $allIds as $id ) {
            clean_post_cache( $id );
            $rows[ $id ] = $writer->product( $id );
        }

        return md5( serialize( $rows ) );
    };
    $stateOf = static function ( array $ids ) use ( $writer ): array {
        $out = [];
        foreach ( $ids as $id ) {
            clean_post_cache( $id );
            $out[ $id ] = $writer->product( $id );
        }

        return $out;
    };
    $original   = $stateOf( [ $S, $U, $V ] );
    $slugBefore = [ $S => get_post_field( 'post_name', $S ), $U => get_post_field( 'post_name', $U ), $V => get_post_field( 'post_name', $V ) ];
    $dateBefore = [ $S => get_post_field( 'post_date', $S ), $U => get_post_field( 'post_date', $U ), $V => get_post_field( 'post_date', $V ) ];

    // ---- one big update: prices and a sale window, stock, texts, SKU, terms, image, size, variation rows --------------------------
    $yesterday = $day( -1 );
    $inMonth   = $day( 30 );
    $big       = [
        [ 'id' => $S, 'name' => AIED_UP_NAME . 'Simple Two', 'regular_price' => '25', 'sale_price' => '19.50', 'sale_from' => $yesterday, 'sale_to' => $inMonth, 'stock_quantity' => 7,
          'description' => 'Fish & chips <p>New</p>', 'sku' => AIED_UP_SKU . 'S2' ],
        [ 'id' => $U, 'manage_stock' => true, 'stock_quantity' => 4, 'weight' => '2.5', 'dimensions' => [ 'length' => '10', 'height' => '2.5' ], 'tax_status' => 'none',
          'categories' => [ AIED_UP_SLUG . 'cat' ], 'tags' => [ AIED_UP_SLUG . 'tag' ], 'image_id' => $img ],
        [ 'id' => $V, 'variations' => [ [ 'id' => $vSmall, 'regular_price' => '11', 'stock_quantity' => 9 ], [ 'id' => $vLarge, 'sale_price' => '9.50' ], [ 'id' => $vHuge, 'stock_quantity' => 6 ] ] ],
    ];

    // dry run
    $newestBefore = $newestId();
    $fixBefore    = $fixtures();
    [ $p, $err ] = $run( [ 'updates' => $big, 'dry_run' => true ] );
    $check( null === $err && false === ( $p['changed'] ?? null ) && true === ( $p['dry_run'] ?? null ) && 3 === ( $p['would_change'] ?? 0 ) && ! empty( $p['token'] ), 'a dry run of three products previews all three and returns a token' . ( null === $err ? '' : ': ' . $err ) );
    $check( $fixBefore === $fixtures() && $newestBefore === $newestId() && ! isset( $p['batch'] ), 'a dry run changes nothing and records no batch' );
    $check( '20' === ( $p['products'][0]['changes']['regular_price']['before'] ?? null ) && '25' === ( $p['products'][0]['changes']['regular_price']['after'] ?? null ), 'the preview shows before and after of each changed field' );
    $token = (string) ( $p['token'] ?? '' );

    // refusals of a real run without a good token
    [ , $err ] = $run( [ 'updates' => $big ] );
    $check( null !== $err && str_contains( $err, 'needs the token' ) && $fixBefore === $fixtures(), 'a real run without a token is refused and changes nothing' );
    [ , $err ] = $run( [ 'updates' => array_slice( $big, 0, 2 ), 'token' => $token ] );
    $check( null !== $err && str_contains( $err, 'needs the token' ) && $fixBefore === $fixtures(), 'the token of other arguments is refused' );

    // the real run
    [ $r, $err ] = $run( [ 'updates' => $big, 'token' => $token ] );
    $check( null === $err && true === ( $r['changed'] ?? null ) && 3 === count( (array) ( $r['updated'] ?? [] ) ) && [] === ( $r['skipped'] ?? [ 1 ] ), 'a real run with the token updates all three' . ( null === $err ? '' : ': ' . $err ) );
    $check( true === ( $r['batch']['undoable'] ?? false ), 'the run recorded one undoable batch' );
    $batchMain = (int) ( $r['batch']['id'] ?? 0 );

    $s = $readBack( $S );
    $check( AIED_UP_NAME . 'Simple Two' === ( $s['name'] ?? null ) && '25' === ( $s['regular_price'] ?? null ) && '19.50' === ( $s['sale_price'] ?? null ) && 7 === ( $s['stock_quantity'] ?? null ) && AIED_UP_SKU . 'S2' === ( $s['sku'] ?? null ),
        'get_product (free) reads back name, prices, stock and SKU of the simple product' . ( ( $s['name'] ?? null ) === AIED_UP_NAME . 'Simple Two' ? '' : ': ' . wp_json_encode( $s ) ) );
    $check( 'Fish & chips <p>New</p>' === ( $s['description'] ?? null ), 'the description with "&" is stored as sent (administrator, unfiltered_html)' );
    $w = $wc( $S );
    $check( $yesterday === $w['from'] && $inMonth === $w['to'] && true === $w['on_sale'] && '19.50' === $w['price'], "the sale window is read back as {$yesterday} to {$inMonth}, the product is on sale and its price is the sale price" . ( $yesterday === $w['from'] ? '' : ': ' . wp_json_encode( $w ) ) );
    $check( 'Old short' === ( $s['short_description'] ?? null ) && '1' === ( $s['weight'] ?? null ) && 'taxable' === ( $s['tax_status'] ?? null ), 'fields the update did not name are untouched (short description, weight, tax status)' );
    $check( $slugBefore[ $S ] === $w['slug'] && 'publish' === $w['post_status'] && $dateBefore[ $S ] === $w['date'], 'the simple product keeps its slug, its publish status and its date' );

    $u = $readBack( $U );
    $check( true === ( $u['manage_stock'] ?? null ) && 4 === ( $u['stock_quantity'] ?? null ) && 'instock' === ( $u['stock_status'] ?? null ), 'the plain product now manages stock: quantity 4, status derived to instock' );
    $check( '2.5' === ( $u['weight'] ?? null ) && 'none' === ( $u['tax_status'] ?? null ) && '10' === ( $u['dimensions']['length'] ?? null ) && '2.5' === ( $u['dimensions']['height'] ?? null ) && '' === ( $u['dimensions']['width'] ?? 'x' ), 'weight, tax status and the two dimension axes given are stored; the third is untouched' );
    $check( [ $catId ] === $termIds( $u, 'categories' ) && [ $tagId ] === $termIds( $u, 'tags' ) && wp_get_attachment_url( $img ) === ( $u['images']['featured'] ?? null ), 'categories and tags replaced the old ones and the featured image is set (free read path)' );
    $wu = $wc( $U );
    $check( $slugBefore[ $U ] === $wu['slug'] && 'publish' === $wu['post_status'], 'the plain product keeps its slug and its publish status' );

    $rs = $wc( $vSmall );
    $rl = $wc( $vLarge );
    $rh = $wc( $vHuge );
    $check( '11' === $rs['regular'] && 9 === $rs['qty'], 'variation Small: regular price 11, quantity 9' );
    $check( '9.50' === $rl['sale'] && '12' === $rl['regular'] && true === $rl['on_sale'], 'variation Large: sale price 9.50 under its regular 12' );
    $check( 6 === $rh['qty'] && 'instock' === $rh['status'], 'variation Huge: quantity 6, status derived to instock' );
    clean_post_cache( $V );
    $parent = wc_get_product( $V );
    $check( 9.5 === (float) $parent->get_variation_price( 'min', true ) && 15.0 === (float) $parent->get_variation_price( 'max', true ), 'the variable parent\'s price range follows its rows (min 9.50, max 15)' );
    $wv = $wc( $V );
    $check( $slugBefore[ $V ] === $wv['slug'] && 'publish' === $wv['post_status'] && $dateBefore[ $V ] === $wv['date'], 'the variable product keeps its slug, its publish status and its date' );

    // the token is single use
    [ , $err ] = $run( [ 'updates' => $big, 'token' => $token ] );
    $check( null !== $err && str_contains( $err, 'needs the token' ), 'the token works once: a second run with it is refused' );

    // ---- one undo puts every value back exactly --------------------------------------------------------------------------------
    $u1 = $trackUndo( $undo->undo( $batchMain ) );
    $check( 3 === ( $u1['restored'] ?? 0 ) && true === ( $u1['fully_undone'] ?? false ), 'undo_batch restores all three products and reports a full undo' );
    $now = $stateOf( [ $S, $U, $V ] );
    $diff = [];
    foreach ( $original as $id => $o ) {
        if ( $o !== $now[ $id ] ) {
            $diff[] = $id . ':' . implode( ',', array_keys( array_diff_assoc( array_map( 'serialize', $o ), array_map( 'serialize', (array) $now[ $id ] ) ) ) );
        }
    }
    $check( [] === $diff, 'after the undo every field of the three products equals its original value, variation rows included' . ( [] === $diff ? '' : ' (differs: ' . implode( '; ', $diff ) . ')' ) );
    $check( $original[ $U ]['category_ids'] === $now[ $U ]['category_ids'], 'the plain product is back under its original category (' . implode( ',', $now[ $U ]['category_ids'] ) . ')' );
    $inv = (int) ( $u1['undo_batch']['id'] ?? 0 );
    $u2  = $trackUndo( $undo->undo( $inv ) );
    $check( 3 === ( $u2['restored'] ?? 0 ) && '25' === $wc( $S )['regular'] && 9 === $wc( $vSmall )['qty'], 'the undo can be undone: the updated values are back' );
    $u3 = $trackUndo( $undo->undo( (int) ( $u2['undo_batch']['id'] ?? 0 ) ) );
    $check( 3 === ( $u3['restored'] ?? 0 ) && '20' === $wc( $S )['regular'], 'and undone once more: the originals are back' );

    // ---- a product changed between the preview and the run is skipped; the others proceed ------------------------------------------
    $two = [ [ 'id' => $S, 'regular_price' => '30' ], [ 'id' => $U, 'weight' => '3' ] ];
    [ $p, $perr ] = $run( [ 'updates' => $two, 'dry_run' => true ] );
    $sp = wc_get_product( $S );
    $sp->set_regular_price( '28' ); // the owner edits it after the preview
    $sp->save();
    [ $r, $err ] = $run( [ 'updates' => $two, 'token' => (string) ( $p['token'] ?? '' ) ] );
    $check( null === $err && [ $U ] === array_map( 'intval', array_column( (array) ( $r['updated'] ?? [] ), 'id' ) ) && [ $S ] === array_map( 'intval', array_column( (array) ( $r['skipped'] ?? [] ), 'id' ) ), 'a product changed after the preview is skipped and reported, the other proceeds' . ( null === $err ? '' : ': ' . $err ) );
    $check( '28' === $wc( $S )['regular'], 'the skipped product keeps the owner\'s value' );
    $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    $sp = wc_get_product( $S );
    $sp->set_regular_price( '20' );
    $sp->save();

    // ---- a field edited after a run is skipped by the undo -----------------------------------------------------------------------------
    [ , $r, $err ] = $go( [ [ 'id' => $S, 'regular_price' => '33' ], [ 'id' => $U, 'weight' => '4' ] ] );
    $check( null === $err && '4' === (string) $writer->product( $U )['weight'] && '33' === $wc( $S )['regular'], 'the run set the weights and prices the undo test relies on (U weight 4, S regular price 33)' );
    $sp = wc_get_product( $S );
    $sp->set_regular_price( '34' ); // the owner edits a field the run changed
    $sp->save();
    $ux = $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    $results = array_column( (array) ( $ux['items'] ?? [] ), 'result' );
    $check( null === $err && [ 'changed_since', 'restored' ] === $results && '34' === $wc( $S )['regular'] && '' === (string) $writer->product( $U )['weight'], 'undo restores the untouched product and skips the one whose field the owner edited since' );
    $sp = wc_get_product( $S );
    $sp->set_regular_price( '20' );
    $sp->save();

    // ---- the failure paths: every product the run touched is put back, no batch ----------------------------------------------------
    $failUpdates = [ [ 'id' => $S, 'regular_price' => '31', 'stock_quantity' => 2 ], [ 'id' => $U, 'weight' => '4', 'tax_status' => 'shipping' ], [ 'id' => $V, 'variations' => [ [ 'id' => $vSmall, 'regular_price' => '99.5' ] ] ] ];
    $fixBefore    = $fixtures();
    $stateBefore  = $stateOf( [ $S, $U, $V ] );
    $newestBefore = $newestId();
    $failWriter = new AiedUpFailingWriter( new WooProductWriter(), 2 );
    $failing    = new UpdateProducts( $log, $failWriter );
    [ , , $err ] = $go( $failUpdates, $failing );
    $check( null !== $err && str_contains( $err, 'Could not update Product 2' ) && ! str_contains( $err, '/var/www' ) && ! str_contains( $err, 'simulated' ), 'a failing second save is refused with a message that shows no path: ' . (string) $err );
    $check( $failWriter->applyCalls >= 3, "the failing run re-applied the first product's original values through the writer (apply called {$failWriter->applyCalls} times, at least 3)" );
    $check( $fixBefore === $fixtures() && $stateBefore === $stateOf( [ $S, $U, $V ] ) && $newestBefore === $newestId(), 'after the failing save every product is exactly as before (first product put back) and no batch was recorded' );

    $dropping = new UpdateProducts( $log, new AiedUpFailingWriter( new WooProductWriter(), 0, 'regular_price' ) );
    [ , , $err ] = $go( $failUpdates, $dropping );
    $check( null !== $err && str_contains( $err, 'saved differently than asked (regular_price)' ), 'a store that saves something else is caught by the read-back: ' . (string) $err );
    $check( $fixBefore === $fixtures() && $stateBefore === $stateOf( [ $S, $U, $V ] ) && $newestBefore === $newestId(), 'after the read-back difference every product is exactly as before and no batch was recorded' );

    // ---- permissions and the shop manager role ---------------------------------------------------------------------------------------------
    foreach ( AIED_UP_USERS as $role => $login ) {
        $uid = wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.invalid', 'role' => $role ] );
        if ( is_wp_error( $uid ) ) {
            throw new \RuntimeException( 'Could not create a throwaway user.' );
        }
        $users[ $role ] = (int) $uid;
    }
    wp_set_current_user( $users['editor'] );
    $fixBefore = $fixtures();
    [ , $err ] = $run( [ 'updates' => [ [ 'id' => $S, 'regular_price' => '40' ] ], 'dry_run' => true ] );
    $check( null !== $err && str_contains( $err, 'edit_products' ) && $fixBefore === $fixtures(), 'an editor without edit_products is refused and nothing changes' );

    wp_set_current_user( $users['shop_manager'] );
    $fixBefore = $fixtures();
    $smBefore  = $readBack( $S );
    [ , $r, $err ] = $go( [ [ 'id' => $S, 'name' => AIED_UP_NAME . 'Salt & Pepper', 'description' => 'Fish & chips' ] ] );
    $check( null === $err && true === ( $r['changed'] ?? null ), 'a shop manager changes a name and a description containing "&" (core kses stores "&amp;"; the read-back accepts it)' . ( null === $err ? '' : ': ' . $err ) );
    $sm = $readBack( $S );
    $smName = (string) ( $sm['name'] ?? '' );
    $smDesc = (string) ( $sm['description'] ?? '' );
    $nameForm = AIED_UP_NAME . 'Salt &amp; Pepper' === $smName ? '&amp;' : ( AIED_UP_NAME . 'Salt & Pepper' === $smName ? '&' : '' );
    $descForm = 'Fish &amp; chips' === $smDesc ? '&amp;' : ( 'Fish & chips' === $smDesc ? '&' : '' );
    $check( '' !== $nameForm && $smName !== (string) ( $smBefore['name'] ?? '' ), 'the shop manager\'s name is stored as "Salt & Pepper" in the "' . $nameForm . '" form and differs from the previous name: ' . $smName );
    $check( '' !== $descForm && $smDesc !== (string) ( $smBefore['description'] ?? '' ), 'the shop manager\'s description (real wp_kses_post()) is stored as "Fish & chips" in the "' . $descForm . '" form and differs from the previous description: ' . $smDesc );
    $note( 'wp_kses_post() stored the shop manager\'s "&" as "' . $descForm . '" in the description and "' . $nameForm . '" in the name' );
    [ $p2 ] = $run( [ 'updates' => [ [ 'id' => $S, 'name' => AIED_UP_NAME . 'Salt & Pepper' ] ], 'dry_run' => true ] );
    $check( [ $S ] === array_map( 'intval', (array) ( $p2['unchanged'] ?? [] ) ), 'asking for the same name again is "unchanged" (the stored "&amp;" is the same name)' );
    $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    wp_set_current_user( 1 );
    $check( $fixBefore === $fixtures(), 'the shop manager\'s change was undone' );

    // ---- refusals: nothing is changed, no batch -----------------------------------------------------------------------------------------
    $fixBefore    = $fixtures();
    $newestBefore = $newestId();
    $cases = [
        'a grouped product'                  => [ [ [ 'id' => $G, 'name' => AIED_UP_NAME . 'x' ] ], 'grouped products are not supported' ],
        'a variation id as the product id'   => [ [ [ 'id' => $vSmall, 'regular_price' => '1' ] ], 'product not found' ],
        'a trashed product'                  => [ [ [ 'id' => $T, 'regular_price' => '1' ] ], 'product not found' ],
        'a missing id'                       => [ [ [ 'id' => 99999999, 'regular_price' => '1' ] ], 'product not found' ],
        'status in the update'               => [ [ [ 'id' => $S, 'status' => 'draft' ] ], 'never changes a product\'s status, type or slug' ],
        'a slug in the update'               => [ [ [ 'id' => $S, 'slug' => 'new-slug' ] ], '"slug" is not a supported field' ],
        'a sale price above the regular'     => [ [ [ 'id' => $S, 'sale_price' => '99' ] ], 'must be lower than the "regular_price"' ],
        'a sale date without a sale price'   => [ [ [ 'id' => $S, 'sale_from' => $day( 1 ) ] ], 'sale dates need a "sale_price"' ],
        'a SKU another product has'          => [ [ [ 'id' => $S, 'sku' => AIED_UP_SKU . 'O' ] ], 'already used by another product' ],
        'markup in a SKU'                    => [ [ [ 'id' => $S, 'sku' => '<b>x</b>' ] ], '"sku" must not contain < or >' ],
        'a backslash in a name'              => [ [ [ 'id' => $S, 'name' => AIED_UP_NAME . 'back\\slash' ] ], 'backslash' ],
        'a script in a description'          => [ [ [ 'id' => $S, 'description' => '<script>alert(1)</script>' ] ], 'script' ],
        'an unknown category'                => [ [ [ 'id' => $S, 'categories' => [ AIED_UP_SLUG . 'nope' ] ] ], 'does not exist' ],
        'an image that is a text file'       => [ [ [ 'id' => $S, 'image_id' => $doc ] ], 'is not an image' ],
        'stock on an external product'       => [ [ [ 'id' => $E, 'stock_quantity' => 3 ] ], 'not available on an external product' ],
        'a price on a variable product'      => [ [ [ 'id' => $V, 'regular_price' => '5' ] ], 'not available on a variable product' ],
        'a variation of another product'     => [ [ [ 'id' => $V, 'variations' => [ [ 'id' => $S, 'regular_price' => '1' ] ] ] ], 'does not belong to this product' ],
        'a stock status while managed'       => [ [ [ 'id' => $S, 'stock_status' => 'outofstock' ] ], 'cannot be set while stock is managed' ],
    ];
    foreach ( $cases as $label => [ $updates, $needle ] ) {
        [ , $err ] = $run( [ 'updates' => array_merge( [ [ 'id' => $O, 'regular_price' => '6' ] ], $updates ), 'dry_run' => true ] );
        $check( null !== $err && str_starts_with( $err, 'Nothing was changed.' ) && str_contains( $err, $needle ) && $fixBefore === $fixtures() && $newestBefore === $newestId(),
            "refused: {$label} (the valid product beside it was not changed, no batch)" . ( null !== $err && str_contains( $err, $needle ) ? '' : ': ' . (string) $err ) );
    }

    // ---- what WooCommerce does with a sale window (the design's open points) -------------------------------------------------------------
    [ , $r, $err ] = $go( [ [ 'id' => $S, 'sale_price' => '15', 'sale_from' => $day( 10 ) ] ] );
    $ws = $wc( $S );
    $check( null === $err && false === $ws['on_sale'] && '20' === $ws['price'], 'a sale that starts in ten days leaves the price at the regular price (not on sale yet)' . ( null === $err ? '' : ': ' . $err ) );
    $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    [ , $r, $err ] = $go( [ [ 'id' => $S, 'sale_price' => '10', 'sale_from' => '2020-01-01', 'sale_to' => '2020-02-01' ] ] );
    if ( null === $err ) {
        $note( 'a sale that ended in 2020 was accepted and read back as asked: on_sale=' . wp_json_encode( $wc( $S )['on_sale'] ) . ', price=' . $wc( $S )['price'] );
        $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    } else {
        $note( 'a sale that ended in 2020 was refused by the read-back (WooCommerce changed it): ' . $err );
        $check( '20' === $wc( $S )['regular'] && '' === $wc( $S )['sale'], 'and the product was left as it was' );
    }

    // ---- the stock group round trip: low_stock_amount, manage_stock false, a lone backorders change --------------------------------
    $check( 3 === (int) ( $original[ $S ]['low_stock_amount'] ?? -1 ) && 2 === (int) ( $original[ $vSmall ]['low_stock_amount'] ?? $original[ $V ]['variations'][ $vSmall ]['low_stock_amount'] ?? -1 ), 'the fixtures start with low stock thresholds (S: 3, variation Small: 2) in the tool\'s own read of them: ' . wp_json_encode( [ $original[ $S ]['low_stock_amount'] ?? null ] ) );
    $stockBefore = $stateOf( [ $S ] );
    [ , $r, $err ] = $go( [ [ 'id' => $S, 'manage_stock' => false ] ] );
    $ws = $wc( $S );
    $pS = wc_get_product( $S );
    $lowAfter = $pS->get_low_stock_amount( 'edit' );
    $check( null === $err && true === ( $r['changed'] ?? null ) && false === $ws['manage'], 'manage_stock false is applied: WooCommerce reports the simple product as unmanaged' . ( null === $err ? '' : ': ' . $err ) );
    $note( 'after manage_stock false WooCommerce ' . ( '' === (string) $lowAfter || null === $lowAfter ? 'CLEARED' : 'KEPT' ) . ' the low stock threshold (value now: ' . wp_json_encode( $lowAfter ) . '); quantity now ' . wp_json_encode( $ws['qty'] ) . ', status ' . $ws['status'] );
    $check( null === $ws['qty'] || '' === (string) $lowAfter || null === $lowAfter, 'the flip changed at least one other stock field (quantity null or threshold cleared), so the undo below has something to restore' );
    $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    $ws = $wc( $S );
    $pS = wc_get_product( $S );
    $check( true === $ws['manage'] && 10 === $ws['qty'], 'undoing it makes the product managed again with its original quantity (10)' );
    $check( 3 === (int) $pS->get_low_stock_amount( 'edit' ), 'undoing it puts the low stock threshold back to 3 (it is \'' . wp_json_encode( $pS->get_low_stock_amount( 'edit' ) ) . '\')' );
    $check( $stockBefore === $stateOf( [ $S ] ), 'and every field of the product equals its value before the run' );

    $check( 'no' === (string) wc_get_product( $S )->get_backorders( 'edit' ), 'the simple product starts with backorders "no"' );
    [ , $r, $err ] = $go( [ [ 'id' => $S, 'backorders' => 'notify' ] ] );
    $check( null === $err && true === ( $r['changed'] ?? null ) && 'notify' === (string) wc_get_product( $S )->get_backorders( 'edit' ) && 10 === $wc( $S )['qty'] && true === $wc( $S )['manage'], 'a lone backorders change on a managed product is read back as "notify" by WooCommerce (quantity and management untouched)' . ( null === $err ? '' : ': ' . $err ) );
    $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    $check( 'no' === (string) wc_get_product( $S )->get_backorders( 'edit' ), 'undoing it puts backorders back to "no"' );

    // ---- image_id: set an image, then image_id 0 removes it ---------------------------------------------------------------------------
    [ , $r, $err ] = $go( [ [ 'id' => $S, 'image_id' => $img ] ] );
    $check( null === $err && $img === (int) wc_get_product( $S )->get_image_id( 'edit' ), 'image_id sets the featured image of the simple product' . ( null === $err ? '' : ': ' . $err ) );
    $setBatch = (int) ( $r['batch']['id'] ?? 0 );
    [ , $r2, $err ] = $go( [ [ 'id' => $S, 'image_id' => 0 ] ] );
    $check( null === $err && 0 === (int) wc_get_product( $S )->get_image_id( 'edit' ) && [] !== $readBack( $S )['images'] && '' === (string) ( $readBack( $S )['images']['featured'] ?? '' ), 'a run with image_id 0 removes the featured image (get_image_id() is 0)' . ( null === $err ? '' : ': ' . $err ) );
    $trackUndo( $undo->undo( (int) ( $r2['batch']['id'] ?? 0 ) ) );
    $check( $img === (int) wc_get_product( $S )->get_image_id( 'edit' ), 'undoing the removal puts the image back' );
    $trackUndo( $undo->undo( $setBatch ) );
    $check( 0 === (int) wc_get_product( $S )->get_image_id( 'edit' ), 'undoing the first run leaves the product without an image again' );

    // ---- everything the checks above changed is back --------------------------------------------------------------------------------------
    $finalState = $stateOf( [ $S, $U, $V ] );
    $check( $finalState === $original, 'the three fixtures end in exactly their original state, variation rows included' );
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
$check( ! get_user_by( 'login', AIED_UP_USERS['editor'] ) && ! get_user_by( 'login', AIED_UP_USERS['shop_manager'] ), 'cleanup: the throwaway users are gone' );
$leftBatches = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batchIds ) ) ) . ')' ); // phpcs:ignore
$check( 0 === $leftBatches, 'cleanup: the ' . count( array_unique( $batchIds ) ) . ' batch rows this run recorded are gone' );
$check( $snapshot() === $before, 'cleanup: every original product and variation is byte-identical (' . $before . ')' );
$check( $beforeTotal === $totalProducts() && $beforeOnly === $totalProducts( "'product'" ), "cleanup: the numbers of products ({$beforeOnly}) and of products and variations ({$beforeTotal}) in every status, the trash included, are the original ones again" );

if ( [] !== $failures ) {
    WP_CLI::error( 'update_products NOT verified: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'update_products verified on the real site.' );
