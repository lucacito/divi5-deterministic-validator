<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Products\PriceAdjuster;
use AiEditorDivi5\Pro\Products\ProductFields;
use AiEditorDivi5\Pro\Products\ProductFilter;
use AiEditorDivi5\Pro\Products\ProductUpdateRun;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;
use AiEditorDivi5\Pro\Site\PreviewToken;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Raise or lower the REGULAR prices of the simple, external and variable WooCommerce products a filter matches, by a percent or an
 * amount, with exact decimal arithmetic. It changes live prices, so a real run needs the single-use token of a dry run of the same
 * arguments; the token fixes the list of products and each product's state, and a product that changed since is skipped. A product that
 * cannot be adjusted is skipped with a reason, the rest proceed. The apply, read-back, rollback and batch are the engine update_products
 * uses (ProductUpdateRun). Never touches a sale price, a status or anything but the regular price.
 */
final class BulkAdjustPrices implements ProTool
{
    public const NAME         = 'bulk_adjust_prices';
    public const MAX_PRODUCTS = 100;
    public const MAX_FIELDS   = 400;
    private const MAX_MESSAGE = 6000;
    private const NEEDS_TOKEN = 'A real run needs the token from a dry run of the same arguments, made by you in the last 30 minutes and not used before. Call again with dry_run=true.';
    private const CHANGED     = 'changed_since_preview';

    /** What a skip code means, for the answer. */
    public const REASONS = [
        'no_variations'     => 'the variable product has no variations to adjust',
        'variation_blocked' => 'a variation of this product cannot be adjusted, so the product is left as it is',
        'not_found'         => 'the product could not be read',
        'unreadable_price'  => 'a stored price could not be read as a plain decimal number',
    ] + PriceAdjuster::REASONS;

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
        $list = static fn ( string $d, string $item ): array => [ 'type' => 'array', 'description' => $d, 'items' => [ 'type' => $item ] ];

        return [
            'name'        => self::NAME,
            'description' => 'Raise or lower the REGULAR prices of the WooCommerce products a filter matches, by a percent or an amount, rounded exactly. Never touches sale prices or anything else. Call with dry_run=true first, then repeat with its token. Unadjustable products are skipped with a reason. One undoable batch.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'filter'   => [
                        'type'        => 'object',
                        'description' => 'Which products. Give at least one of categories, tags, types, stock_status, on_sale, search, ids - or all=true. Only published products unless status says otherwise.',
                        'properties'  => [
                            'categories'   => $list( 'Category slugs (also matches child categories)', 'string' ),
                            'tags'         => $list( 'Tag slugs', 'string' ),
                            'types'        => $list( 'simple, variable and/or external', 'string' ),
                            'stock_status' => [ 'type' => 'string', 'description' => 'instock, outofstock or onbackorder' ],
                            'on_sale'      => [ 'type' => 'boolean', 'description' => 'Only products on sale now (true) or not (false)' ],
                            'search'       => [ 'type' => 'string', 'description' => 'Words in the product name' ],
                            'ids'          => $list( 'Product ids (at most 100)', 'integer' ),
                            'all'          => [ 'type' => 'boolean', 'description' => 'true = every product (in the allowed statuses); cannot be combined with the narrowing filters' ],
                            'exclude_ids'  => $list( 'Product ids to leave out', 'integer' ),
                            'status'       => $list( 'publish (default), private, draft and/or pending', 'string' ),
                        ],
                    ],
                    'change'   => [
                        'type'        => 'object',
                        'description' => 'mode "percent" (-90 to 500) or "amount", value a signed decimal STRING, for example {"mode":"percent","value":"-10"}',
                        'properties'  => [
                            'mode'  => [ 'type' => 'string', 'description' => 'percent or amount' ],
                            'value' => [ 'type' => 'string', 'description' => 'Signed decimal string, for example "10", "-12.5", "-5"' ],
                        ],
                        'required'    => [ 'mode', 'value' ],
                    ],
                    'round_to' => [ 'type' => 'string', 'description' => 'Optional: what new prices should end in, for example "0.95" or "0.5"; "0" for whole numbers. Without it prices are rounded to the shop\'s decimals.' ],
                    'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview only; returns the token a real run needs' ],
                    'token'    => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                ],
                'required'   => [ 'filter', 'change' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $dry    = Args::bool( $arguments, 'dry_run' );
        $token  = Args::string( $arguments, 'token', false, 8000 );
        $parsed = ProductFilter::parse( $arguments );
        if ( null === $parsed['spec'] ) {
            self::refuse( $parsed['reasons'] );
        }
        $spec   = $parsed['spec'];
        $writer = $this->writer();

        if ( ! $writer->isActive() ) {
            throw new \InvalidArgumentException( 'WooCommerce is not active on this site.' );
        }
        if ( ! current_user_can( 'edit_products' ) ) {
            throw new \InvalidArgumentException( 'You need the edit_products permission to change products.' );
        }
        $missing = [];
        foreach ( [ 'category' => [ 'product_cat', 'categories' ], 'tag' => [ 'product_tag', 'tags' ] ] as $word => [ $taxonomy, $key ] ) {
            if ( [] !== $spec['filter'][ $key ] ) {
                foreach ( $writer->termIds( $taxonomy, $spec['filter'][ $key ] )['missing'] as $slug ) {
                    $missing[] = "{$word} \"{$slug}\" does not exist";
                }
            }
        }
        if ( [] !== $missing ) {
            self::refuse( $missing );
        }

        $now      = ( $this->clock )();
        $userId   = (int) get_current_user_id();
        $argsHash = Canon::hash( $spec );
        $map      = null;
        if ( ! $dry ) {
            $map = null === $token ? null : PreviewToken::open( $token, $argsHash, $now, $userId );
            if ( null === $map ) {
                throw new \InvalidArgumentException( self::NEEDS_TOKEN );
            }
        }

        // Which products: what the filter matches now; a real run also takes the products the preview listed.
        $found = $writer->findProducts( $spec['filter'], self::MAX_PRODUCTS );
        $ids   = array_values( array_filter( array_map( 'intval', $found['ids'] ), static fn ( int $id ): bool => current_user_can( 'edit_post', $id ) ) );
        if ( $dry && ( $found['truncated'] || count( $ids ) > self::MAX_PRODUCTS ) ) {
            self::refuse( [ 'More than ' . self::MAX_PRODUCTS . ' products match this filter (or too many to check); narrow it with categories, tags, types, ids or search and try again' ] );
        }
        $candidates = $ids;
        if ( null !== $map ) {
            $candidates = array_values( array_unique( array_merge( $ids, array_keys( $map ) ) ) );
            sort( $candidates );
        }

        $plans        = [];
        $skipped      = [];
        $unchanged    = [];
        $notInPreview = 0;
        $fields       = 0;
        foreach ( $candidates as $i => $id ) {
            $current = $this->load( $id, $writer );
            $inMap   = null !== $map && isset( $map[ $id ] );
            if ( null === $current ) {
                if ( $inMap ) {
                    $skipped[] = [ 'id' => $id, 'reason' => self::CHANGED ];
                } elseif ( null === $map ) {
                    $skipped[] = [ 'id' => $id, 'name' => '', 'reason' => 'not_found', 'detail' => self::REASONS['not_found'] ];
                }
                continue;
            }
            $name = ProductUpdateRun::cut( (string) $current['name'] );
            $adj  = self::adjustment( $current, $spec['change'], $writer->priceDecimals(), $spec['round_to'] );
            // The token binds the product's state AND the change planned for it (so a different shop-decimals setting, or anything else
            // that moves the computed price, makes a previewed product "changed since the preview"). null = nothing planned.
            $hash = substr( Canon::hash( [ $current, 'change' === $adj['status'] ? $adj['changes'] : null ] ), 0, 32 );
            if ( $inMap && $map[ $id ] !== $hash ) {
                $skipped[] = [ 'id' => $id, 'reason' => self::CHANGED ];
                continue;
            }
            if ( 'skip' === $adj['status'] ) {
                $skipped[] = [ 'id' => $id, 'name' => $name, 'reason' => $adj['reason'], 'detail' => self::REASONS[ $adj['reason'] ] ] + ( isset( $adj['rows'] ) ? [ 'rows' => $adj['rows'] ] : [] );
                continue;
            }
            if ( 'unchanged' === $adj['status'] ) {
                $unchanged[] = $id;
                continue;
            }
            if ( null !== $map && ! $inMap ) {
                $notInPreview++; // it matches now but was not previewed: never touched
                continue;
            }
            $fields += $adj['fields'];
            $plans[] = [
                'id'       => $id,
                'label'    => 'Product ' . ( $i + 1 ) . " (#{$id} \"{$name}\")",
                'name'     => (string) $current['name'],
                'hash'     => $hash,
                'current'  => $current,
                'changes'  => $adj['changes'],
                'previous' => ProductFields::pick( $current, ProductFields::tracked( $adj['changes'] ) ),
                'display'  => ProductUpdateRun::display( $adj['changes'], $current ),
            ];
        }
        if ( $fields > self::MAX_FIELDS ) {
            self::refuse( [ "This adjustment would change {$fields} prices; the limit is " . self::MAX_FIELDS . ' per call, so narrow the filter' ] );
        }

        if ( $dry ) {
            return $this->preview( $plans, $skipped, $unchanged, count( $ids ), $argsHash, $now, $userId );
        }
        PreviewToken::spend( (string) $token );
        if ( [] === $plans ) {
            return [ 'changed' => false, 'dry_run' => false, 'updated' => [], 'skipped' => $skipped, 'unchanged' => $unchanged, 'not_in_preview' => $notInPreview, 'message' => 'Nothing was changed.' ];
        }

        return ( new ProductUpdateRun( $this->log, $writer ) )->execute( $plans, $skipped, $unchanged, self::NAME, 'Adjusted prices of %d product(s)', [ 'not_in_preview' => $notInPreview ] );
    }

    /**
     * What the change does to one product's regular price(s). A variable product is adjusted as a whole or not at all.
     *
     * @param array<string,mixed> $current ProductWriter::product()
     * @param array{mode:string, value:string} $change
     * @return array{status: string, reason?: string, rows?: list<array{id:int, reason:string, detail:string}>, changes?: array<string,mixed>, fields?: int}
     */
    private static function adjustment( array $current, array $change, int $decimals, ?string $roundTo ): array
    {
        if ( 'variable' === $current['type'] ) {
            if ( [] === $current['variations'] ) {
                return [ 'status' => 'skip', 'reason' => 'no_variations' ];
            }
            $changes = [];
            $bad     = [];
            foreach ( $current['variations'] as $vid => $row ) {
                if ( ! self::readable( (string) $row['regular_price'], (string) $row['sale_price'] ) ) {
                    $bad[] = [ 'id' => (int) $vid, 'reason' => 'unreadable_price', 'detail' => self::REASONS['unreadable_price'] ];
                    continue;
                }
                $r = PriceAdjuster::adjust( (string) $row['regular_price'], (string) $row['sale_price'], $change['mode'], $change['value'], $decimals, $roundTo );
                if ( null !== $r['reason'] ) {
                    $bad[] = [ 'id' => (int) $vid, 'reason' => $r['reason'], 'detail' => self::REASONS[ $r['reason'] ] ];
                } elseif ( ! PriceAdjuster::same( (string) $r['price'], (string) $row['regular_price'] ) ) {
                    $changes[ (int) $vid ] = [ 'regular_price' => $r['price'] ];
                }
            }
            if ( [] !== $bad ) {
                return [ 'status' => 'skip', 'reason' => 'variation_blocked', 'rows' => $bad ];
            }

            return [] === $changes ? [ 'status' => 'unchanged' ] : [ 'status' => 'change', 'changes' => [ 'variations' => $changes ], 'fields' => count( $changes ) ];
        }
        if ( ! self::readable( (string) $current['regular_price'], (string) $current['sale_price'] ) ) {
            return [ 'status' => 'skip', 'reason' => 'unreadable_price' ];
        }
        $r = PriceAdjuster::adjust( (string) $current['regular_price'], (string) $current['sale_price'], $change['mode'], $change['value'], $decimals, $roundTo );
        if ( null !== $r['reason'] ) {
            return [ 'status' => 'skip', 'reason' => $r['reason'] ];
        }
        if ( PriceAdjuster::same( (string) $r['price'], (string) $current['regular_price'] ) ) {
            return [ 'status' => 'unchanged' ];
        }

        return [ 'status' => 'change', 'changes' => [ 'regular_price' => $r['price'] ], 'fields' => 1 ];
    }

    /**
     * Whether the stored prices are plain decimals PriceAdjuster can read (at most 10 integer and 6 decimal digits). A rogue stored price
     * ("1,5", "1e3", 14 digits) must skip its product, never throw out of a whole preview. An empty sale price is fine; an empty regular
     * price is left to PriceAdjuster (reason no_regular_price) and the sale price is then never read.
     */
    private static function readable( string $regular, string $sale ): bool
    {
        if ( '' === $regular ) {
            return true;
        }
        foreach ( [ $regular, $sale ] as $price ) {
            if ( '' !== $price && 1 !== preg_match( '/^\d{1,10}(\.\d{1,6})?$/D', $price ) ) {
                return false;
            }
        }

        return true;
    }

    /** @return ?array<string,mixed> the product's state, or null when it is gone, not editable by this user or not adjustable */
    private function load( int $id, ProductWriter $writer ): ?array
    {
        $type = $writer->typeOf( $id );
        if ( null === $type || ! current_user_can( 'edit_post', $id ) ) {
            return null;
        }

        return $writer->product( $id );
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @param list<array<string,mixed>> $skipped
     * @param list<int> $unchanged
     * @return array<string,mixed>
     */
    private function preview( array $plans, array $skipped, array $unchanged, int $matched, string $argsHash, int $now, int $userId ): array
    {
        $map      = [];
        $products = [];
        foreach ( $plans as $plan ) {
            $map[ $plan['id'] ] = $plan['hash'];
            $row                = [ 'id' => $plan['id'], 'name' => ProductUpdateRun::cut( $plan['name'] ), 'type' => (string) $plan['current']['type'], 'changes' => $plan['display']['fields'] ];
            if ( [] !== $plan['display']['variations'] ) {
                $row['variations'] = $plan['display']['variations'];
            }
            $products[] = $row;
        }
        $out = [
            'changed'   => false,
            'dry_run'   => true,
            'summary'   => [ 'matched' => $matched, 'would_change' => count( $plans ), 'skipped' => count( $skipped ), 'unchanged' => count( $unchanged ) ],
            'products'  => $products,
            'skipped'   => $skipped,
            'unchanged' => $unchanged,
        ];
        if ( [] === $plans ) {
            return $out + [ 'message' => 'Nothing to adjust: no matched product would change. There is nothing to apply.' ];
        }

        return $out + [
            'token'   => PreviewToken::make( $argsHash, $map, $now, $userId ),
            'message' => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to apply it (it works once, within 30 minutes).',
        ];
    }

    /** @param list<string> $reasons */
    private static function refuse( array $reasons ): never
    {
        $message = 'Nothing was changed. ' . count( $reasons ) . ' problem(s): ' . implode( '; ', $reasons ) . '.';
        if ( mb_strlen( $message ) > self::MAX_MESSAGE ) {
            $message = mb_substr( $message, 0, self::MAX_MESSAGE - 3 ) . '...';
        }
        throw new \InvalidArgumentException( $message );
    }

    private function writer(): ProductWriter
    {
        return $this->writer ??= new WooProductWriter();
    }
}
