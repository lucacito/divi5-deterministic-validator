<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Kinds\ProductCreatedKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Products\ProductSaveFailed;
use AiEditorDivi5\Pro\Products\ProductValuePolicy;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create new SIMPLE WooCommerce products as drafts. Every product is checked first; if any is refused nothing is created. Each saved
 * product is read back and compared with what was asked; a failed save or a difference deletes every product this call created.
 * One undoable batch per call. Never publishes and never touches an existing product.
 */
final class CreateProduct implements ProTool
{
    public const NAME         = 'create_product';
    public const MAX_PRODUCTS = 25;
    private const MAX_MESSAGE = 6000;

    public function __construct( private BatchLog $log, private ?ProductWriter $writer = null )
    {
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

        return [
            'name'        => self::NAME,
            'description' => 'Create up to 25 new simple WooCommerce products as DRAFTS (never published). Sets name, texts, SKU, prices, stock, existing categories, tags and Media Library images. All or nothing; one undoable batch. dry_run previews. If list_products is available, use it first to see the catalogue.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'products' => [
                        'type'        => 'array',
                        'description' => 'The products to create (1 to 25)',
                        'maxItems'    => self::MAX_PRODUCTS,
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'name'              => $string( 'Product name (plain text, at most 200 characters)' ),
                                'description'       => $string( 'Long description (HTML allowed, no scripts)' ),
                                'short_description' => $string( 'Short description (HTML allowed, no scripts)' ),
                                'sku'               => $string( 'Unique SKU' ),
                                'regular_price'     => $string( 'Decimal string, for example "20" or "20.50"' ),
                                'sale_price'        => $string( 'Decimal string lower than regular_price' ),
                                'manage_stock'      => [ 'type' => 'boolean', 'description' => 'Track stock quantity' ],
                                'stock_quantity'    => [ 'type' => 'integer', 'description' => 'Required when manage_stock is true' ],
                                'stock_status'      => $string( 'instock, outofstock or onbackorder (only when manage_stock is false)' ),
                                'backorders'        => $string( 'no, notify or yes (only when manage_stock is true)' ),
                                'categories'        => $list( 'Existing category slugs', 'string' ),
                                'tags'              => $list( 'Existing tag slugs', 'string' ),
                                'image_id'          => [ 'type' => 'integer', 'description' => 'Media Library image id' ],
                                'gallery_image_ids' => $list( 'Media Library image ids', 'integer' ),
                                'weight'            => $string( 'Decimal string' ),
                                'dimensions'        => [ 'type' => 'object', 'description' => 'length, width and height as decimal strings' ],
                                'tax_status'        => $string( 'taxable, shipping or none' ),
                                'virtual'           => [ 'type' => 'boolean', 'description' => 'A product that is not shipped' ],
                                'sold_individually' => [ 'type' => 'boolean', 'description' => 'Limit purchases to one per order' ],
                            ],
                            'required'   => [ 'name' ],
                        ],
                    ],
                    'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is created' ],
                ],
                'required'   => [ 'products' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $dry    = Args::bool( $arguments, 'dry_run' );
        $items  = Args::listOf( $arguments, 'products', self::MAX_PRODUCTS );
        $writer = $this->writer();

        if ( ! $writer->isActive() ) {
            throw new \InvalidArgumentException( 'WooCommerce is not active on this site.' );
        }
        if ( ! current_user_can( 'edit_products' ) ) {
            throw new \InvalidArgumentException( 'You need the edit_products permission to create products.' );
        }

        $kses  = static fn ( string $s ): string => (string) wp_kses_post( $s );
        $strip = static fn ( string $s ): string => (string) wp_strip_all_tags( $s );

        $plans    = [];
        $refusals = [];
        $skus     = [];
        foreach ( $items as $i => $raw ) {
            $n = $i + 1;
            if ( ! is_array( $raw ) || array_is_list( $raw ) ) {
                $refusals[] = "Product {$n}: must be an object with at least a \"name\"";
                continue;
            }
            $label   = self::label( $n, $raw );
            $checked = ProductValuePolicy::check( $raw, $kses, $strip );
            $reasons = $checked['reasons'];
            $values  = $checked['values'];
            if ( null !== $values ) {
                $reasons = $this->siteChecks( $values, $skus, $writer );
            }
            if ( [] !== $reasons ) {
                foreach ( $reasons as $reason ) {
                    $refusals[] = "{$label}: {$reason}";
                }
                continue;
            }
            $plans[] = [ 'n' => $n, 'label' => $label, 'values' => $values ];
        }
        if ( [] !== $refusals ) {
            $message = 'Nothing was created. ' . count( $refusals ) . ' problem(s): ' . implode( '; ', $refusals ) . '.';
            if ( mb_strlen( $message ) > self::MAX_MESSAGE ) {
                $message = mb_substr( $message, 0, self::MAX_MESSAGE - 3 ) . '...';
            }
            throw new \InvalidArgumentException( $message );
        }

        if ( $dry ) {
            return [ 'changed' => false, 'dry_run' => true, 'products' => array_map( static fn ( array $p ): array => self::preview( $p['values'] ), $plans ) ];
        }

        $created = [];
        $current = $plans[0] ?? [ 'label' => 'Product 1' ];
        try {
            foreach ( $plans as $plan ) {
                $current = $plan;
                $id        = $writer->create( $plan['values'] );
                $created[] = [ 'id' => $id, 'plan' => $plan, 'snapshot' => null ];
                $snap      = $writer->snapshot( $id );
                if ( null === $snap ) {
                    throw new ProductSaveFailed( 'the saved product could not be read back' );
                }
                $created[ count( $created ) - 1 ]['snapshot'] = $snap;
                $differences = self::differences( $plan['values'], $snap );
                if ( [] !== $differences ) {
                    throw new ProductSaveFailed( 'it was saved differently than asked (' . implode( ', ', $differences ) . ')' );
                }
            }
        } catch ( \Throwable $e ) {
            $notRemoved = $this->rollback( $created, $writer );
            // Only our own constant messages (ProductSaveFailed) and WooCommerce's data exceptions are safe to show; any other message
            // (a foreign RuntimeException, a hook, a database error) could carry a server path.
            $safe = $e instanceof ProductSaveFailed || is_a( $e, 'WC_Data_Exception' );
            if ( $e instanceof ProductSaveFailed && null !== $e->leftoverId ) {
                $notRemoved[] = $e->leftoverId;
            }
            $reason = $safe ? $e->getMessage() : 'WooCommerce could not save it';
            $text   = "Could not create {$current['label']}: {$reason}.";
            if ( [] !== $notRemoved ) {
                throw new \InvalidArgumentException( $text . ' These products may still exist as drafts and should be checked or trashed: #' . implode( ', #', $notRemoved ) . '.' );
            }
            if ( $e instanceof ProductSaveFailed && $e->mayHaveLeftover ) {
                $name = isset( $current['values']['name'] ) ? self::cut( (string) $current['values']['name'] ) : '';
                $what = '' !== $name ? "A draft product named \"{$name}\"" : 'A draft product';
                throw new \InvalidArgumentException( $text . " {$what} may have been left behind; check your drafts in WooCommerce." );
            }
            throw new \InvalidArgumentException( $text . ' Nothing was created.' );
        }

        $products = [];
        $records  = [];
        foreach ( $created as $c ) {
            $name       = (string) $c['snapshot']['name'];
            $products[] = [ 'id' => $c['id'], 'name' => $name, 'sku' => (string) $c['snapshot']['sku'], 'status' => (string) $c['snapshot']['status'], 'edit_url' => $writer->editUrl( $c['id'] ) ];
            $records[]  = ProductCreatedKind::itemFor( $c['id'], $c['snapshot'], $name );
        }
        $count = count( $records );
        $batch = $this->log->record( self::NAME, "Created {$count} draft product(s)", $records );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'products' => $products ], $batch );
    }

    /**
     * Checks that need the site: SKUs, term slugs, images, term permission. Fills `category_ids` / `tag_ids` into $values.
     *
     * @param array<string,mixed> $values
     * @param array<string,bool> $skus lower-cased SKUs already used in this call
     * @return list<string> reasons
     */
    private function siteChecks( array &$values, array &$skus, ProductWriter $writer ): array
    {
        $r = [];
        if ( '' !== $values['sku'] ) {
            $key = strtolower( (string) $values['sku'] );
            if ( isset( $skus[ $key ] ) ) {
                $r[] = "sku \"{$values['sku']}\" appears more than once in this call";
            } elseif ( $writer->skuTaken( (string) $values['sku'] ) ) {
                $r[] = "sku \"{$values['sku']}\" is already used by another product";
            }
            $skus[ $key ] = true;
        }

        $values['category_ids'] = [];
        $values['tag_ids']      = [];
        if ( [] !== $values['category_slugs'] || [] !== $values['tag_slugs'] ) {
            if ( ! current_user_can( 'assign_product_terms' ) ) {
                $r[] = 'you need the assign_product_terms permission to use categories or tags';
            } else {
                foreach ( [ 'category' => [ 'product_cat', 'category_slugs', 'category_ids' ], 'tag' => [ 'product_tag', 'tag_slugs', 'tag_ids' ] ] as $word => [ $taxonomy, $slugKey, $idKey ] ) {
                    if ( [] === $values[ $slugKey ] ) {
                        continue;
                    }
                    $found = $writer->termIds( $taxonomy, $values[ $slugKey ] );
                    foreach ( $found['missing'] as $slug ) {
                        $r[] = "{$word} \"{$slug}\" does not exist (create it in WooCommerce first)";
                    }
                    $values[ $idKey ] = $found['ids'];
                }
            }
        }

        if ( (int) $values['image_id'] > 0 && ! $writer->imageUsable( (int) $values['image_id'] ) ) {
            $r[] = "image_id {$values['image_id']} is not an image in the Media Library that you can use";
        }
        foreach ( $values['gallery_image_ids'] as $id ) {
            if ( ! $writer->imageUsable( (int) $id ) ) {
                $r[] = "gallery_image_ids {$id} is not an image in the Media Library that you can use";
            }
        }

        return $r;
    }

    /**
     * @param array<string,mixed> $values
     * @param array<string,mixed> $snap
     * @return list<string> the names of the fields that came back different
     */
    private static function differences( array $values, array $snap ): array
    {
        $bad  = [];
        $same = static function ( string $a, string $b ): bool {
            return '' === $a || '' === $b ? $a === $b : 0 === ProductValuePolicy::compareDecimals( $a, $b );
        };

        if ( 'draft' !== ( $snap['status'] ?? '' ) ) {
            $bad[] = 'status';
        }
        foreach ( [ 'sku', 'tax_status' ] as $f ) {
            if ( (string) $values[ $f ] !== (string) ( $snap[ $f ] ?? '' ) ) {
                $bad[] = $f;
            }
        }
        // Core's kses (for a user without unfiltered_html) stores a bare "&" as "&amp;" in the title and the texts: the same text.
        foreach ( [ 'name', 'description', 'short_description' ] as $f ) {
            if ( ! ProductValuePolicy::sameText( (string) $values[ $f ], (string) ( $snap[ $f ] ?? '' ) ) ) {
                $bad[] = $f;
            }
        }
        foreach ( [ 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' ] as $f ) {
            if ( ! $same( (string) $values[ $f ], (string) ( $snap[ $f ] ?? '' ) ) ) {
                $bad[] = $f;
            }
        }
        if ( (bool) $values['manage_stock'] !== (bool) ( $snap['manage_stock'] ?? false ) ) {
            $bad[] = 'manage_stock';
        } elseif ( $values['manage_stock'] ) {
            if ( $values['stock_quantity'] !== ( $snap['stock_quantity'] ?? null ) ) {
                $bad[] = 'stock_quantity';
            }
        } elseif ( (string) $values['stock_status'] !== (string) ( $snap['stock_status'] ?? '' ) ) {
            $bad[] = 'stock_status';
        }
        if ( $values['manage_stock'] && (string) $values['backorders'] !== (string) ( $snap['backorders'] ?? '' ) ) {
            $bad[] = 'backorders';
        }
        // WooCommerce files a product saved without categories under its default category, so extra ids in the read-back are normal.
        // Only the categories and tags that were ASKED for must all be there.
        foreach ( [ 'category_ids' => 'categories', 'tag_ids' => 'tags' ] as $key => $word ) {
            if ( [] !== $values[ $key ] && [] !== array_diff( $values[ $key ], array_map( 'intval', (array) ( $snap[ $key ] ?? [] ) ) ) ) {
                $bad[] = $word;
            }
        }
        if ( (int) $values['image_id'] !== (int) ( $snap['image_id'] ?? 0 ) ) {
            $bad[] = 'image_id';
        }
        if ( array_map( 'intval', $values['gallery_image_ids'] ) !== array_map( 'intval', array_values( (array) ( $snap['gallery_image_ids'] ?? [] ) ) ) ) {
            $bad[] = 'gallery_image_ids';
        }
        foreach ( [ 'virtual', 'sold_individually' ] as $f ) {
            if ( (bool) $values[ $f ] !== (bool) ( $snap[ $f ] ?? false ) ) {
                $bad[] = $f;
            }
        }

        return $bad;
    }

    /**
     * Deletes the products this call created, newest first. Returns the ids that could not be removed.
     *
     * @param list<array{id:int, plan:array<string,mixed>, snapshot:mixed}> $created
     * @return list<int>
     */
    private function rollback( array $created, ProductWriter $writer ): array
    {
        $left = [];
        foreach ( array_reverse( $created ) as $c ) {
            try {
                $ok = $writer->delete( $c['id'] );
            } catch ( \Throwable $e ) {
                $ok = false;
            }
            if ( ! $ok ) {
                $left[] = $c['id'];
            }
        }

        return $left;
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private static function preview( array $values ): array
    {
        return [
            'name'           => $values['name'],
            'sku'            => $values['sku'],
            'regular_price'  => $values['regular_price'],
            'sale_price'     => $values['sale_price'],
            'manage_stock'   => $values['manage_stock'],
            'stock_quantity' => $values['stock_quantity'],
            'stock_status'   => $values['manage_stock'] ? null : $values['stock_status'],
            'categories'     => $values['category_slugs'],
            'tags'           => $values['tag_slugs'],
            'image_id'       => $values['image_id'],
            'status'         => 'draft',
        ];
    }

    /** @param array<string,mixed> $raw */
    private static function label( int $n, array $raw ): string
    {
        $name = $raw['name'] ?? null;
        if ( is_string( $name ) && '' !== trim( $name ) ) {
            return "Product {$n} \"" . self::cut( $name ) . '"';
        }

        return "Product {$n}";
    }

    private static function cut( string $name ): string
    {
        return mb_strlen( $name ) > 60 ? mb_substr( $name, 0, 60 ) . '...' : $name;
    }

    private function writer(): ProductWriter
    {
        return $this->writer ??= new WooProductWriter();
    }
}
