<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shapes ONE WooCommerce product for the AI: pure, no WooCommerce and no WordPress calls, so the caps and the privacy rules
 * are unit-testable. It receives a plain array (filled by WooProductGateway) and returns only the contracted keys: anything
 * else in the array (downloads, purchase note, meta, cost) is dropped by construction because it is never copied.
 */
final class ProductReader
{
    public const MAX_NAME        = 200;
    public const MAX_DESCRIPTION = 5000;
    public const MAX_SHORT       = 1000;
    public const MAX_CATEGORIES  = 20;
    public const MAX_TAGS        = 20;
    public const MAX_ATTRIBUTES  = 50;
    public const MAX_OPTIONS     = 50;
    public const MAX_IMAGES      = 20;
    public const MAX_IDS         = 50;
    public const MAX_CHILDREN    = 100;
    public const MAX_VARIATIONS  = 100;

    private const LIST_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];
    private const CORE_TYPES    = [ 'simple', 'variable', 'grouped', 'external' ];

    /** Cuts a text to $max characters and marks the cut with an ellipsis (so a cut text is $max + 1 long). */
    public static function cut( string $text, int $max ): string
    {
        return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . '…' : $text;
    }

    /**
     * One list row.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function row( array $raw ): array
    {
        $type   = self::str( $raw['type'] ?? '' );
        $manage = (bool) ( $raw['manage_stock'] ?? false );
        $qty    = $raw['stock_quantity'] ?? null;
        $row    = [
            'id'             => (int) ( $raw['id'] ?? 0 ),
            'name'           => self::cut( self::str( $raw['name'] ?? '' ), self::MAX_NAME ),
            'type'           => $type,
            'status'         => self::str( $raw['status'] ?? '' ),
            'sku'            => self::str( $raw['sku'] ?? '' ),
            'price'          => self::str( $raw['price'] ?? '' ),
            'regular_price'  => self::str( $raw['regular_price'] ?? '' ),
            'sale_price'     => self::str( $raw['sale_price'] ?? '' ),
            'on_sale'        => (bool) ( $raw['on_sale'] ?? false ),
            'stock_status'   => self::str( $raw['stock_status'] ?? '' ),
            'manage_stock'   => $manage,
            'stock_quantity' => $manage && null !== $qty ? (int) $qty : null,
            'categories'     => self::terms( $raw['categories'] ?? [], self::MAX_CATEGORIES ),
            'url'            => self::str( $raw['url'] ?? '' ),
        ];
        if ( 'variable' === $type ) {
            $row['variations_count'] = (int) ( $raw['variations_count'] ?? 0 );
        }

        return $row;
    }

    /**
     * Everything the AI may know about one product.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function detail( array $raw ): array
    {
        $out       = self::row( $raw );
        $protected = (bool) ( $raw['password_protected'] ?? false );
        $dim       = is_array( $raw['dimensions'] ?? null ) ? $raw['dimensions'] : [];
        $images    = is_array( $raw['images'] ?? null ) ? $raw['images'] : [];
        $low       = $raw['low_stock_amount'] ?? null;

        $out['password_protected'] = $protected;
        if ( $protected ) {
            $out['description_hidden'] = true;
            $out['description']        = null;
            $out['short_description']  = null;
        } else {
            $out['description']       = self::cut( self::str( $raw['description'] ?? '' ), self::MAX_DESCRIPTION );
            $out['short_description'] = self::cut( self::str( $raw['short_description'] ?? '' ), self::MAX_SHORT );
        }
        $out['tax_status']         = self::str( $raw['tax_status'] ?? '' );
        $out['tax_class']          = self::str( $raw['tax_class'] ?? '' );
        $out['weight']             = self::str( $raw['weight'] ?? '' );
        $out['dimensions']         = [
            'length' => self::str( $dim['length'] ?? '' ),
            'width'  => self::str( $dim['width'] ?? '' ),
            'height' => self::str( $dim['height'] ?? '' ),
        ];
        $out['shipping_class']     = self::str( $raw['shipping_class'] ?? '' );
        $out['sold_individually']  = (bool) ( $raw['sold_individually'] ?? false );
        $out['backorders']         = self::str( $raw['backorders'] ?? '' );
        $out['low_stock_amount']   = null === $low || '' === $low ? null : (int) $low;
        $out['virtual']            = (bool) ( $raw['virtual'] ?? false );
        $out['downloadable']       = (bool) ( $raw['downloadable'] ?? false ); // a flag only: never the files
        $out['featured']           = (bool) ( $raw['featured'] ?? false );
        $out['catalog_visibility'] = self::str( $raw['catalog_visibility'] ?? '' );
        $out['attributes']         = self::attributes( $raw['attributes'] ?? [] );
        $out['images']             = [
            'featured' => isset( $images['featured'] ) && is_string( $images['featured'] ) && '' !== $images['featured'] ? $images['featured'] : null,
            'gallery'  => array_slice( self::strings( $images['gallery'] ?? [] ), 0, self::MAX_IMAGES ),
        ];
        $out['tags']               = self::terms( $raw['tags'] ?? [], self::MAX_TAGS );
        $out['upsell_ids']         = array_slice( self::ints( $raw['upsell_ids'] ?? [] ), 0, self::MAX_IDS );
        $out['cross_sell_ids']     = array_slice( self::ints( $raw['cross_sell_ids'] ?? [] ), 0, self::MAX_IDS );
        $out['date_created']       = isset( $raw['date_created'] ) && is_string( $raw['date_created'] ) ? $raw['date_created'] : null;
        $out['date_modified']      = isset( $raw['date_modified'] ) && is_string( $raw['date_modified'] ) ? $raw['date_modified'] : null;
        $out['average_rating']     = self::str( $raw['average_rating'] ?? '' );
        $out['review_count']       = (int) ( $raw['review_count'] ?? 0 );

        $type = $out['type'];
        if ( 'external' === $type && is_array( $raw['external'] ?? null ) ) {
            $out['external'] = [
                'product_url' => self::str( $raw['external']['product_url'] ?? '' ),
                'button_text' => self::cut( self::str( $raw['external']['button_text'] ?? '' ), self::MAX_NAME ),
            ];
        }
        if ( 'grouped' === $type ) {
            $out['children'] = array_slice( self::ints( $raw['children'] ?? [] ), 0, self::MAX_CHILDREN );
        }
        if ( 'variable' === $type ) {
            $vars                         = is_array( $raw['variations'] ?? null ) ? array_slice( array_values( $raw['variations'] ), 0, self::MAX_VARIATIONS ) : [];
            $total                        = (int) ( $raw['variations_total'] ?? count( $vars ) );
            $out['variations']            = array_map( [ self::class, 'variation' ], $vars );
            $out['variations_total']      = $total;
            $out['variations_truncated']  = $total > count( $out['variations'] );
        }

        return $out;
    }

    /**
     * The totals block: always the same keys, so the AI can rely on them.
     *
     * @param array<string,mixed> $byStatus          post status => count (wp_count_posts)
     * @param array<string,mixed> $publishedByType   product type slug => published count
     * @return array{all:int, by_status:array<string,int>, published_by_type:array<string,int>}
     */
    public static function totals( array $byStatus, array $publishedByType ): array
    {
        $status = [];
        $all    = 0;
        foreach ( self::LIST_STATUSES as $s ) {
            $n          = max( 0, (int) ( $byStatus[ $s ] ?? 0 ) );
            $status[ $s ] = $n;
            $all       += $n;
        }
        $types = [];
        foreach ( self::CORE_TYPES as $t ) {
            $types[ $t ] = max( 0, (int) ( $publishedByType[ $t ] ?? 0 ) );
        }
        foreach ( $publishedByType as $t => $n ) { // product types added by other plugins
            if ( is_string( $t ) && ! isset( $types[ $t ] ) && 1 === preg_match( '/^[a-z0-9_-]{1,40}$/', $t ) ) {
                $types[ $t ] = max( 0, (int) $n );
            }
        }

        return [ 'all' => $all, 'by_status' => $status, 'published_by_type' => $types ];
    }

    /** @param array<string,mixed> $v @return array<string,mixed> */
    private static function variation( array $v ): array
    {
        $manage = (bool) ( $v['manage_stock'] ?? false );
        $qty    = $v['stock_quantity'] ?? null;
        $attrs  = [];
        foreach ( is_array( $v['attributes'] ?? null ) ? $v['attributes'] : [] as $name => $value ) {
            if ( count( $attrs ) >= self::MAX_ATTRIBUTES ) {
                break;
            }
            $attrs[ self::cut( self::str( $name ), self::MAX_NAME ) ] = self::cut( self::str( $value ), self::MAX_NAME );
        }

        return [
            'id'             => (int) ( $v['id'] ?? 0 ),
            'sku'            => self::str( $v['sku'] ?? '' ),
            'status'         => self::str( $v['status'] ?? '' ),
            'price'          => self::str( $v['price'] ?? '' ),
            'regular_price'  => self::str( $v['regular_price'] ?? '' ),
            'sale_price'     => self::str( $v['sale_price'] ?? '' ),
            'on_sale'        => (bool) ( $v['on_sale'] ?? false ),
            'stock_status'   => self::str( $v['stock_status'] ?? '' ),
            'manage_stock'   => $manage,
            'stock_quantity' => $manage && null !== $qty ? (int) $qty : null,
            'attributes'     => $attrs,
            'image'          => isset( $v['image'] ) && is_string( $v['image'] ) && '' !== $v['image'] ? $v['image'] : null,
        ];
    }

    /** @param mixed $list @return list<array{name:string,options:list<string>,variation:bool,visible:bool,taxonomy:bool}> */
    private static function attributes( mixed $list ): array
    {
        $out = [];
        foreach ( is_array( $list ) ? $list : [] as $a ) {
            if ( ! is_array( $a ) || count( $out ) >= self::MAX_ATTRIBUTES ) {
                continue;
            }
            $out[] = [
                'name'      => self::cut( self::str( $a['name'] ?? '' ), self::MAX_NAME ),
                'options'   => array_map( fn ( string $o ): string => self::cut( $o, self::MAX_NAME ), array_slice( self::strings( $a['options'] ?? [] ), 0, self::MAX_OPTIONS ) ),
                'variation' => (bool) ( $a['variation'] ?? false ),
                'visible'   => (bool) ( $a['visible'] ?? false ),
                'taxonomy'  => (bool) ( $a['taxonomy'] ?? false ),
            ];
        }

        return $out;
    }

    /** @param mixed $list @return list<array{id:int,name:string,slug:string}> */
    private static function terms( mixed $list, int $max ): array
    {
        $out = [];
        foreach ( is_array( $list ) ? $list : [] as $t ) {
            if ( ! is_array( $t ) || count( $out ) >= $max ) {
                continue;
            }
            $out[] = [
                'id'   => (int) ( $t['id'] ?? 0 ),
                'name' => self::cut( self::str( $t['name'] ?? '' ), self::MAX_NAME ),
                'slug' => self::cut( self::str( $t['slug'] ?? '' ), self::MAX_NAME ),
            ];
        }

        return $out;
    }

    private static function str( mixed $v ): string
    {
        return is_scalar( $v ) ? (string) $v : '';
    }

    /** @param mixed $list @return list<string> */
    private static function strings( mixed $list ): array
    {
        $out = [];
        foreach ( is_array( $list ) ? $list : [] as $v ) {
            if ( is_scalar( $v ) && '' !== (string) $v ) {
                $out[] = (string) $v;
            }
        }

        return $out;
    }

    /** @param mixed $list @return list<int> */
    private static function ints( mixed $list ): array
    {
        $out = [];
        foreach ( is_array( $list ) ? $list : [] as $v ) {
            if ( is_numeric( $v ) ) {
                $out[] = (int) $v;
            }
        }

        return $out;
    }
}
