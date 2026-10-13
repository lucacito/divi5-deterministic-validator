<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\ProductFieldsKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Products\ProductDiff;
use AiEditorDivi5\Pro\Products\ProductFields;
use AiEditorDivi5\Pro\Products\ProductSaveFailed;
use AiEditorDivi5\Pro\Products\ProductValuePolicy;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;
use AiEditorDivi5\Pro\Site\PreviewToken;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Change existing simple, external and variable WooCommerce products (and the price and stock of a variable product's variation rows).
 * It changes live products, so a real run needs the single-use token of a dry run of the same arguments; a product that changed since
 * that preview is skipped and reported, never overwritten. Every product is checked first (all or nothing), applied one at a time, read
 * back and compared; a failed save or a difference puts every product this run touched back and records nothing. One undoable batch per
 * run. It never changes a product's status, type or slug and never creates or deletes anything.
 */
final class UpdateProducts implements ProTool
{
    public const NAME            = 'update_products';
    public const MAX_PRODUCTS    = 25;
    public const MAX_ROWS        = 200;
    public const MAX_TEXT_BYTES  = 524288;
    public const MAX_PREVIOUS    = 1048576;
    private const MAX_MESSAGE    = 6000;
    private const NEEDS_TOKEN    = 'A real run needs the token from a dry run of the same arguments, made by you in the last 30 minutes and not used before. Call again with dry_run=true.';
    private const CHANGED        = 'changed_since_preview';

    private \Closure $clock;

    /** @param (\Closure():int)|null $clock fn(): unix time */
    public function __construct( private BatchLog $log, private ?ProductWriter $writer = null, ?\Closure $clock = null )
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_posts';
    }

    public function definition(): array
    {
        $string = static fn ( string $d ): array => [ 'type' => 'string', 'description' => $d ];
        $list   = static fn ( string $d, string $item ): array => [ 'type' => 'array', 'description' => $d, 'items' => [ 'type' => $item ] ];
        $row    = [
            'id'             => [ 'type' => 'integer', 'description' => 'The variation id (it must belong to this product)' ],
            'regular_price'  => $string( 'Decimal string, or "" to clear' ),
            'sale_price'     => $string( 'Decimal string lower than regular_price, or "" to clear' ),
            'sale_from'      => $string( 'YYYY-MM-DD, or "" to clear' ),
            'sale_to'        => $string( 'YYYY-MM-DD, or "" to clear' ),
            'manage_stock'   => [ 'type' => 'boolean', 'description' => 'Track stock quantity' ],
            'stock_quantity' => [ 'type' => 'integer', 'description' => 'Needs manage_stock' ],
            'stock_status'   => $string( 'instock, outofstock or onbackorder (only when stock is not managed)' ),
            'backorders'     => $string( 'no, notify or yes (only when stock is managed)' ),
        ];

        return [
            'name'        => self::NAME,
            'description' => 'Change existing WooCommerce products: prices and sale dates, stock, texts, SKU, categories, tags, images, size and variation rows. Never publishes, creates or deletes. Call with dry_run=true first, then repeat with its token. One undoable batch. Read current values with get_product first.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'updates' => [
                        'type'        => 'array',
                        'description' => 'The products to change (1 to 25); omit a field to leave it alone',
                        'maxItems'    => self::MAX_PRODUCTS,
                        'items'       => [
                            'type'       => 'object',
                            'properties' => $row + [
                                'name'              => $string( 'Product name (plain text, at most 200 characters)' ),
                                'description'       => $string( 'Long description (HTML allowed, no scripts), or "" to clear' ),
                                'short_description' => $string( 'Short description (HTML allowed, no scripts), or "" to clear' ),
                                'sku'               => $string( 'Unique SKU, or "" to clear' ),
                                'categories'        => $list( 'Existing category slugs; the list replaces the current categories', 'string' ),
                                'tags'              => $list( 'Existing tag slugs; the list replaces the current tags', 'string' ),
                                'image_id'          => [ 'type' => 'integer', 'description' => 'Media Library image id, or 0 to remove the image' ],
                                'gallery_image_ids' => $list( 'Media Library image ids; the list replaces the gallery', 'integer' ),
                                'weight'            => $string( 'Decimal string, or "" to clear' ),
                                'dimensions'        => [ 'type' => 'object', 'description' => 'length, width and height as decimal strings; only the axes given change' ],
                                'tax_status'        => $string( 'taxable, shipping or none' ),
                                'variations'        => [
                                    'type'        => 'array',
                                    'description' => 'Variable products only: up to 100 variation rows to change',
                                    'items'       => [ 'type' => 'object', 'properties' => $row, 'required' => [ 'id' ] ],
                                ],
                            ],
                            'required'   => [ 'id' ],
                        ],
                    ],
                    'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview only; returns the token a real run needs' ],
                    'token'   => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                ],
                'required'   => [ 'updates' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $dry    = Args::bool( $arguments, 'dry_run' );
        $items  = Args::listOf( $arguments, 'updates', self::MAX_PRODUCTS );
        $token  = Args::string( $arguments, 'token', false, 8000 );
        $writer = $this->writer();

        if ( ! $writer->isActive() ) {
            throw new \InvalidArgumentException( 'WooCommerce is not active on this site.' );
        }
        if ( ! current_user_can( 'edit_products' ) ) {
            throw new \InvalidArgumentException( 'You need the edit_products permission to change products.' );
        }

        $now      = ( $this->clock )();
        $userId   = (int) get_current_user_id();
        $argsHash = Canon::hash( $items );
        $map      = null;
        if ( ! $dry ) {
            $map = null === $token ? null : PreviewToken::open( $token, $argsHash, $now, $userId );
            if ( null === $map ) {
                throw new \InvalidArgumentException( self::NEEDS_TOKEN );
            }
        }

        $kses  = static fn ( string $s ): string => (string) wp_kses_post( $s );
        $strip = static fn ( string $s ): string => (string) wp_strip_all_tags( $s );

        $plans     = [];
        $skipped   = [];
        $unchanged = [];
        $refusals  = [];
        $seen      = [];
        $rows      = 0;
        $bytes     = 0;
        foreach ( $items as $i => $raw ) {
            $n = $i + 1;
            if ( ! is_array( $raw ) || array_is_list( $raw ) ) {
                $refusals[] = "Product {$n}: must be an object with an \"id\" and the fields to change";
                continue;
            }
            $id = $raw['id'] ?? null;
            if ( ! is_int( $id ) || $id < 1 ) {
                $refusals[] = "Product {$n}: \"id\" must be a whole product id";
                continue;
            }
            if ( isset( $seen[ $id ] ) ) {
                $refusals[] = "Product {$n} (#{$id}): the product appears more than once in this call";
                continue;
            }
            $seen[ $id ] = true;
            unset( $raw['id'] );

            $loaded = $this->load( $id, $writer );
            if ( null === $loaded['current'] ) {
                if ( $dry ) {
                    $refusals[] = "Product {$n} (#{$id}): {$loaded['why']}";
                } else {
                    $skipped[] = [ 'id' => $id, 'reason' => self::CHANGED ];
                }
                continue;
            }
            $current = $loaded['current'];
            $label   = self::label( $n, $id, (string) $current['name'] );
            $hash    = substr( Canon::hash( $current ), 0, 32 );
            $inMap   = null !== $map && isset( $map[ $id ] );
            if ( $inMap && $map[ $id ] !== $hash ) {
                $skipped[] = [ 'id' => $id, 'reason' => self::CHANGED ];
                continue;
            }

            $plan = $this->plan( $id, $raw, $current, $writer, $kses, $strip );
            if ( ! $dry && ! $inMap ) {
                // The preview saw nothing to change on this product. It stays out of the skipped list only if that is still true.
                if ( [] === $plan['reasons'] && [] === $plan['changes'] ) {
                    $unchanged[] = $id;
                } else {
                    $skipped[] = [ 'id' => $id, 'reason' => self::CHANGED ];
                }
                continue;
            }
            if ( [] !== $plan['reasons'] ) {
                foreach ( $plan['reasons'] as $reason ) {
                    $refusals[] = "{$label}: {$reason}";
                }
                continue;
            }
            if ( [] === $plan['changes'] ) {
                $unchanged[] = $id;
                continue;
            }
            $rows    += $plan['rows'];
            $bytes   += $plan['bytes'];
            $plans[] = $plan + [ 'id' => $id, 'label' => $label, 'hash' => $hash, 'name' => (string) $current['name'] ];
        }
        if ( $rows > self::MAX_ROWS ) {
            $refusals[] = "These updates change {$rows} variation rows; the limit is " . self::MAX_ROWS . ' per call, so split the call';
        }
        if ( $bytes > self::MAX_PREVIOUS ) {
            $refusals[] = 'The previous texts of these products, kept so the change can be undone, add up to more than 1 MB; split the call into smaller ones';
        }
        if ( [] !== $refusals ) {
            $message = 'Nothing was changed. ' . count( $refusals ) . ' problem(s): ' . implode( '; ', $refusals ) . '.';
            if ( mb_strlen( $message ) > self::MAX_MESSAGE ) {
                $message = mb_substr( $message, 0, self::MAX_MESSAGE - 3 ) . '...';
            }
            throw new \InvalidArgumentException( $message );
        }

        if ( $dry ) {
            return $this->preview( $plans, $unchanged, $argsHash, $now, $userId );
        }
        PreviewToken::spend( (string) $token );
        if ( [] === $plans ) {
            return [ 'changed' => false, 'dry_run' => false, 'updated' => [], 'skipped' => $skipped, 'unchanged' => $unchanged, 'message' => 'Nothing was changed.' ];
        }

        return $this->apply( $plans, $skipped, $unchanged );
    }

    /**
     * @return array{current: ?array<string,mixed>, why: string}
     */
    private function load( int $id, ProductWriter $writer ): array
    {
        $type = $writer->typeOf( $id );
        // A missing id, a trashed product, a variation, another post type and a product the user may not edit all answer alike.
        if ( null === $type || ! current_user_can( 'edit_post', $id ) ) {
            return [ 'current' => null, 'why' => 'product not found' ];
        }
        if ( 'grouped' === $type ) {
            return [ 'current' => null, 'why' => 'grouped products are not supported (their price comes from their children)' ];
        }
        $current = $writer->product( $id );
        if ( null === $current ) {
            return [ 'current' => null, 'why' => "{$type} products are not supported" ];
        }

        return [ 'current' => $current, 'why' => '' ];
    }

    /**
     * One product: the policy, then the checks that need the site (SKU owner, terms, images, size of the texts kept for undo).
     *
     * @param array<string,mixed> $raw the update without its id
     * @param array<string,mixed> $current
     * @return array{reasons: list<string>, changes: array<string,mixed>, previous: array<string,mixed>, display: array<string,mixed>, current: array<string,mixed>, rows: int, bytes: int}
     */
    private function plan( int $id, array $raw, array $current, ProductWriter $writer, \Closure $kses, \Closure $strip ): array
    {
        $none = [ 'reasons' => [], 'changes' => [], 'previous' => [], 'display' => [ 'fields' => [], 'variations' => [] ], 'current' => $current, 'rows' => 0, 'bytes' => 0 ];
        $checked = ProductValuePolicy::checkPartial( $raw, $current, $kses, $strip );
        if ( null === $checked['changes'] ) {
            return [ 'reasons' => $checked['reasons'] ] + $none;
        }
        $changes  = $checked['changes'];
        $r        = [];
        $slugsFor = [];

        if ( isset( $changes['sku'] ) && '' !== $changes['sku'] ) {
            $owner = $writer->skuOwner( (string) $changes['sku'] );
            if ( $owner > 0 && $owner !== $id ) {
                $r[] = "sku \"{$changes['sku']}\" is already used by another product";
            }
        }
        foreach ( [ 'category' => [ 'product_cat', 'category_slugs', 'category_ids' ], 'tag' => [ 'product_tag', 'tag_slugs', 'tag_ids' ] ] as $word => [ $taxonomy, $slugKey, $idKey ] ) {
            if ( ! array_key_exists( $slugKey, $changes ) ) {
                continue;
            }
            $slugs = $changes[ $slugKey ];
            unset( $changes[ $slugKey ] );
            if ( ! current_user_can( 'assign_product_terms' ) ) {
                $r[] = 'you need the assign_product_terms permission to use categories or tags';
                continue;
            }
            $found = [] === $slugs ? [ 'ids' => [], 'missing' => [] ] : $writer->termIds( $taxonomy, $slugs );
            foreach ( $found['missing'] as $slug ) {
                $r[] = "{$word} \"{$slug}\" does not exist (create it in WooCommerce first)";
            }
            $ids = array_map( 'intval', $found['ids'] );
            sort( $ids );
            if ( $ids !== array_map( 'intval', (array) $current[ $idKey ] ) ) {
                $changes[ $idKey ]  = $ids;
                $slugsFor[ $idKey ] = $slugs;
            }
        }
        if ( isset( $changes['image_id'] ) && (int) $changes['image_id'] > 0 && ! $writer->imageUsable( (int) $changes['image_id'] ) ) {
            $r[] = "image_id {$changes['image_id']} is not an image in the Media Library that you can use";
        }
        foreach ( (array) ( $changes['gallery_image_ids'] ?? [] ) as $gid ) {
            if ( ! in_array( $gid, (array) $current['gallery_image_ids'], true ) && ! $writer->imageUsable( (int) $gid ) ) {
                $r[] = "gallery_image_ids {$gid} is not an image in the Media Library that you can use";
            }
        }

        // The previous values are stored for undo: a text that cannot be stored safely is not changed.
        $previous = ProductFields::pick( $current, ProductFields::tracked( $changes ) );
        $bytes    = 0;
        foreach ( ProductFields::TEXTS as $field ) {
            if ( ! isset( $previous['fields'][ $field ] ) ) {
                continue;
            }
            $old = (string) $previous['fields'][ $field ];
            if ( 1 !== preg_match( '//u', $old ) ) {
                $r[] = "its current \"{$field}\" is not valid UTF-8, so the change could not be undone safely";
            } elseif ( strlen( $old ) > self::MAX_TEXT_BYTES ) {
                $r[] = "its current \"{$field}\" is larger than 512 KB, too large to keep for undo, so this tool will not change it";
            }
            $bytes += strlen( $old );
        }
        if ( [] !== $r ) {
            return [ 'reasons' => $r ] + $none;
        }

        return [
            'reasons'  => [],
            'changes'  => $changes,
            'previous' => $previous,
            'display'  => self::display( $changes, $current, $slugsFor ),
            'current'  => $current,
            'rows'     => count( (array) ( $changes['variations'] ?? [] ) ),
            'bytes'    => $bytes,
        ];
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @param list<int> $unchanged
     * @return array<string,mixed>
     */
    private function preview( array $plans, array $unchanged, string $argsHash, int $now, int $userId ): array
    {
        if ( [] === $plans ) {
            return [ 'changed' => false, 'dry_run' => true, 'would_change' => 0, 'products' => [], 'unchanged' => $unchanged, 'message' => 'Nothing to change: every product already has the requested values, so there is nothing to apply.' ];
        }
        $today    = function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d', $now ) : gmdate( 'Y-m-d', $now );
        $map      = [];
        $products = [];
        foreach ( $plans as $plan ) {
            $map[ $plan['id'] ] = $plan['hash'];
            $row                = [ 'id' => $plan['id'], 'name' => $plan['name'], 'type' => (string) $plan['current']['type'], 'changes' => $plan['display']['fields'] ];
            if ( [] !== $plan['display']['variations'] ) {
                $row['variations'] = $plan['display']['variations'];
            }
            $notes = self::notes( $plan['changes'], $today );
            if ( [] !== $notes ) {
                $row['notes'] = $notes;
            }
            $products[] = $row;
        }

        return [
            'changed'      => false,
            'dry_run'      => true,
            'would_change' => count( $plans ),
            'products'     => $products,
            'unchanged'    => $unchanged,
            'token'        => PreviewToken::make( $argsHash, $map, $now, $userId ),
            'message'      => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to apply it (it works once, within 30 minutes).',
        ];
    }

    /**
     * Apply product by product, read each back and compare; any failure puts every product this run touched back and records nothing.
     *
     * @param list<array<string,mixed>> $plans
     * @param list<array{id:int, reason:string}> $skipped
     * @param list<int> $unchanged
     * @return array<string,mixed>
     */
    private function apply( array $plans, array $skipped, array $unchanged ): array
    {
        $writer  = $this->writer();
        $done    = [];
        $failing = $plans[0];
        try {
            foreach ( $plans as $k => $plan ) {
                $failing = $plan;
                $done[]  = $plan;
                $writer->apply( $plan['id'], $plan['changes'] );
                $back = $writer->product( $plan['id'] );
                if ( null === $back ) {
                    throw new ProductSaveFailed( 'the saved product could not be read back' );
                }
                $asked = ProductFields::asShape( $plan['changes'] );
                $diff  = ProductDiff::compare( $asked, ProductFields::pick( $back, $asked ) );
                if ( [] !== $diff ) {
                    throw new ProductSaveFailed( 'it was saved differently than asked (' . implode( ', ', $diff ) . ')' );
                }
                $plans[ $k ]['after']      = ProductFields::pick( $back, $plan['previous'] );
                $plans[ $k ]['name_after'] = (string) $back['name'];
            }
        } catch ( \Throwable $e ) {
            $notRestored = $this->rollback( $done, $writer );
            // Only our own constant messages (ProductSaveFailed) and WooCommerce's data exceptions are safe to show; any other message
            // (a foreign RuntimeException, a hook, a database error) could carry a server path.
            $safe   = $e instanceof ProductSaveFailed || is_a( $e, 'WC_Data_Exception' );
            $reason = $safe ? $e->getMessage() : 'WooCommerce could not save it';
            $text   = "Could not update {$failing['label']}: {$reason}.";
            if ( [] !== $notRestored ) {
                throw new \InvalidArgumentException( $text . ' These products may not be fully restored and should be checked: #' . implode( ', #', $notRestored ) . '.' );
            }
            throw new \InvalidArgumentException( $text . ' Every product this call had touched was put back; nothing was changed.' );
        }

        $updated = [];
        $records = [];
        foreach ( $plans as $plan ) {
            $row = [ 'id' => $plan['id'], 'name' => $plan['name_after'], 'changes' => $plan['display']['fields'] ];
            if ( [] !== $plan['display']['variations'] ) {
                $row['variations'] = $plan['display']['variations'];
            }
            $updated[] = $row;
            $records[] = ProductFieldsKind::itemFor( $plan['id'], self::cut( $plan['name_after'] ), self::what( $plan['changes'] ), $plan['previous'], $plan['after'] );
        }
        $count = count( $records );
        $batch = $this->log->record( self::NAME, "Updated {$count} product(s)", $records );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'updated' => $updated, 'skipped' => $skipped, 'unchanged' => $unchanged ], $batch );
    }

    /**
     * Puts the previous values back on every product the run started to change, newest first, and checks each read-back.
     *
     * @param list<array<string,mixed>> $done
     * @return list<int> the ids that could not be put back
     */
    private function rollback( array $done, ProductWriter $writer ): array
    {
        $left = [];
        foreach ( array_reverse( $done ) as $plan ) {
            try {
                $writer->apply( $plan['id'], ProductFields::asChanges( $plan['previous'] ) );
                $back = $writer->product( $plan['id'] );
                if ( null === $back || [] !== ProductDiff::compare( $plan['previous'], ProductFields::pick( $back, $plan['previous'] ) ) ) {
                    $left[] = $plan['id'];
                }
            } catch ( \Throwable $e ) {
                $left[] = $plan['id'];
            }
        }

        return $left;
    }

    /**
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $current
     * @param array<string,list<string>> $slugsFor category_ids / tag_ids => the slugs that were asked for
     * @return array{fields: array<string,mixed>, variations: list<array<string,mixed>>}
     */
    private static function display( array $changes, array $current, array $slugsFor ): array
    {
        $fields = [];
        foreach ( $changes as $field => $value ) {
            if ( 'variations' === $field ) {
                continue;
            }
            if ( 'category_ids' === $field || 'tag_ids' === $field ) {
                $key            = 'category_ids' === $field ? 'categories' : 'tags';
                $before         = 'category_ids' === $field ? $current['category_slugs'] : $current['tag_slugs'];
                $fields[ $key ] = [ 'before' => $before, 'after' => $slugsFor[ $field ] ?? $value ];
                continue;
            }
            $fields[ $field ] = [ 'before' => self::shown( $current[ $field ] ?? null ), 'after' => self::shown( $value ) ];
        }
        $rows = [];
        foreach ( (array) ( $changes['variations'] ?? [] ) as $vid => $row ) {
            $diff = [];
            foreach ( $row as $field => $value ) {
                $diff[ $field ] = [ 'before' => self::shown( $current['variations'][ $vid ][ $field ] ?? null ), 'after' => self::shown( $value ) ];
            }
            $rows[] = [ 'id' => (int) $vid, 'changes' => $diff ];
        }

        return [ 'fields' => $fields, 'variations' => $rows ];
    }

    /** A sale that starts in the future leaves the price at the regular price until WooCommerce's own scheduler starts it. @param array<string,mixed> $changes @return list<string> */
    private static function notes( array $changes, string $today ): array
    {
        $dates = isset( $changes['sale_from'] ) ? [ (string) $changes['sale_from'] ] : [];
        foreach ( (array) ( $changes['variations'] ?? [] ) as $row ) {
            if ( isset( $row['sale_from'] ) ) {
                $dates[] = (string) $row['sale_from'];
            }
        }
        foreach ( $dates as $date ) {
            if ( '' !== $date && $date > $today ) {
                return [ "A sale that starts on {$date} leaves the price at the regular price until WooCommerce's own scheduler starts it." ];
            }
        }

        return [];
    }

    /** @param array<string,mixed> $changes */
    private static function what( array $changes ): string
    {
        $names = [];
        foreach ( array_keys( $changes ) as $field ) {
            $names[] = 'variations' === $field ? count( (array) $changes['variations'] ) . ' variation row(s)' : (string) $field;
        }
        $what = implode( ', ', $names );

        return mb_strlen( $what ) > 120 ? mb_substr( $what, 0, 117 ) . '...' : $what;
    }

    private static function shown( mixed $value ): mixed
    {
        return is_string( $value ) && mb_strlen( $value ) > 200 ? mb_substr( $value, 0, 200 ) . '...' : $value;
    }

    private static function label( int $n, int $id, string $name ): string
    {
        return "Product {$n} (#{$id} \"" . self::cut( $name ) . '")';
    }

    private static function cut( string $name ): string
    {
        $name = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $name );

        return mb_strlen( $name ) > 60 ? mb_substr( $name, 0, 60 ) . '...' : $name;
    }

    private function writer(): ProductWriter
    {
        return $this->writer ??= new WooProductWriter();
    }
}
