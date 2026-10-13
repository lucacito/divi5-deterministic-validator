<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

use AiEditorDivi5\Pro\Fields\FieldValuePolicy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * What one new simple product may contain. Pure: no WordPress or WooCommerce call (the strip-tags and kses checks are passed in as
 * closures). It returns the normalised values or EVERY reason the product is refused. Checks that need the site (a taken SKU,
 * whether a category exists, whether an image may be used, capabilities) belong to CreateProduct.
 */
final class ProductValuePolicy
{
    public const KEYS = [
        'name', 'description', 'short_description', 'sku', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status',
        'backorders', 'categories', 'tags', 'image_id', 'gallery_image_ids', 'weight', 'dimensions', 'tax_status', 'virtual', 'sold_individually',
    ];

    /** The fields an update_products entry may carry besides the product `id`. */
    public const UPDATE_KEYS = [
        'name', 'description', 'short_description', 'sku', 'regular_price', 'sale_price', 'sale_from', 'sale_to', 'manage_stock', 'stock_quantity',
        'stock_status', 'backorders', 'categories', 'tags', 'image_id', 'gallery_image_ids', 'weight', 'dimensions', 'tax_status', 'variations',
    ];

    public const MAX_VARIATIONS  = 100;
    public const MAX_NAME        = 200;
    public const MAX_DESCRIPTION = 10000;
    public const MAX_SHORT       = 1000;
    public const MAX_CATEGORIES  = 10;
    public const MAX_TAGS        = 20;
    public const MAX_GALLERY     = 10;
    public const MAX_STOCK       = 1000000;

    /** wp_insert_post() unslashes what it saves, so a backslash would silently disappear (proven on the real store). */
    private const BACKSLASH = 'must not contain a backslash (\\): WordPress removes it when it saves the product';

    private const DECIMAL = '/^\d{1,10}(\.\d{1,6})?$/D';
    private const SLUG    = '/^[A-Za-z0-9%_-]{1,200}$/D';
    private const STOCK_STATUSES = [ 'instock', 'outofstock', 'onbackorder' ];
    private const BACKORDERS     = [ 'no', 'notify', 'yes' ];
    private const TAX_STATUSES   = [ 'taxable', 'shipping', 'none' ];

    /**
     * @param array<string,mixed> $product
     * @param \Closure(string):string $kses wp_kses_post
     * @param \Closure(string):string $stripTags wp_strip_all_tags
     * @return array{values: ?array<string,mixed>, reasons: list<string>}
     */
    public static function check( array $product, \Closure $kses, \Closure $stripTags ): array
    {
        $r = [];
        $v = [];

        foreach ( array_keys( $product ) as $key ) {
            if ( ! is_string( $key ) || ! in_array( $key, self::KEYS, true ) ) {
                $r[] = '"' . self::shown( (string) $key ) . '" is not a supported field';
            }
        }

        // name
        $v['name'] = self::validName( $product['name'] ?? null, $stripTags, '"name" is required and must be a non-empty string', $r ) ?? '';

        $v['description']      = self::text( $product, 'description', self::MAX_DESCRIPTION, $kses, $r );
        $v['short_description'] = self::text( $product, 'short_description', self::MAX_SHORT, $kses, $r );

        // sku
        $v['sku'] = '';
        if ( self::given( $product, 'sku' ) ) {
            $v['sku'] = self::validSku( $product['sku'], $r ) ?? '';
        }

        $v['regular_price'] = self::decimal( $product, 'regular_price', $r );
        $v['sale_price']    = self::decimal( $product, 'sale_price', $r );
        if ( '' !== $v['sale_price'] ) {
            if ( '' === $v['regular_price'] ) {
                $r[] = '"sale_price" needs a "regular_price"';
            } elseif ( self::compareDecimals( $v['sale_price'], $v['regular_price'] ) >= 0 ) {
                $r[] = '"sale_price" must be lower than "regular_price"';
            }
        }

        // stock
        $manage = false;
        if ( self::given( $product, 'manage_stock' ) ) {
            if ( is_bool( $product['manage_stock'] ) ) {
                $manage = $product['manage_stock'];
            } else {
                $r[] = '"manage_stock" must be true or false';
            }
        }
        $v['manage_stock']   = $manage;
        $v['stock_quantity'] = null;
        $v['stock_status']   = 'instock';
        $v['backorders']     = 'no';
        if ( $manage ) {
            $qty = $product['stock_quantity'] ?? null;
            if ( ! is_int( $qty ) || $qty < 0 || $qty > self::MAX_STOCK ) {
                $r[] = '"stock_quantity" is required when manage_stock is true: a whole number from 0 to 1,000,000';
            } else {
                $v['stock_quantity'] = $qty;
            }
            if ( self::given( $product, 'stock_status' ) ) {
                $r[] = '"stock_status" cannot be set when manage_stock is true (WooCommerce works it out from the quantity)';
            }
            $v['backorders'] = self::enum( $product, 'backorders', self::BACKORDERS, 'no', $r );
        } else {
            if ( self::given( $product, 'stock_quantity' ) ) {
                $r[] = '"stock_quantity" needs "manage_stock": true';
            }
            if ( self::given( $product, 'backorders' ) ) {
                $r[] = '"backorders" needs "manage_stock": true';
            }
            $v['stock_status'] = self::enum( $product, 'stock_status', self::STOCK_STATUSES, 'instock', $r );
        }

        $v['category_slugs'] = self::slugs( $product, 'categories', self::MAX_CATEGORIES, $r );
        $v['tag_slugs']      = self::slugs( $product, 'tags', self::MAX_TAGS, $r );

        // images
        $v['image_id'] = 0;
        if ( self::given( $product, 'image_id' ) ) {
            if ( is_int( $product['image_id'] ) && $product['image_id'] >= 1 ) {
                $v['image_id'] = $product['image_id'];
            } else {
                $r[] = '"image_id" must be the id (a whole number from 1) of an image in the Media Library';
            }
        }
        $v['gallery_image_ids'] = [];
        if ( self::given( $product, 'gallery_image_ids' ) ) {
            $v['gallery_image_ids'] = self::galleryList( $product['gallery_image_ids'], $r ) ?? [];
        }

        $v['weight'] = self::decimal( $product, 'weight', $r );
        $v['length'] = '';
        $v['width']  = '';
        $v['height'] = '';
        if ( self::given( $product, 'dimensions' ) ) {
            $d = $product['dimensions'];
            if ( ! is_array( $d ) || array_is_list( $d ) || [] !== array_diff( array_keys( $d ), [ 'length', 'width', 'height' ] ) ) {
                $r[] = '"dimensions" must be an object with only "length", "width" and "height"';
            } else {
                foreach ( [ 'length', 'width', 'height' ] as $axis ) {
                    if ( ! array_key_exists( $axis, $d ) || null === $d[ $axis ] ) {
                        continue;
                    }
                    if ( is_string( $d[ $axis ] ) && 1 === preg_match( self::DECIMAL, $d[ $axis ] ) ) {
                        $v[ $axis ] = $d[ $axis ];
                    } else {
                        $r[] = "\"dimensions\" {$axis} must be a decimal string such as \"2.5\"";
                    }
                }
            }
        }

        $v['tax_status'] = self::enum( $product, 'tax_status', self::TAX_STATUSES, 'taxable', $r );
        $v['virtual']           = self::flag( $product, 'virtual', $r );
        $v['sold_individually'] = self::flag( $product, 'sold_individually', $r );

        return [ 'values' => [] === $r ? $v : null, 'reasons' => $r ];
    }

    /**
     * The update-mode twin of check(): the given fields of ONE existing product, judged against its current state (ProductWriter::product()).
     * Pure, like check(). An omitted field (or null) is left alone; `""` clears a text, the SKU, a price, a sale date or the weight.
     * Relative rules (a sale price below the regular price, sale dates need a sale price, a quantity needs managed stock) are checked
     * against the RESULTING state, and only for the group the update touches, so an untouched legacy inconsistency never blocks an
     * unrelated change.
     *
     * `changes` holds ONLY what differs from the current state, under the keys of product(): scalar fields, `length`/`width`/`height`,
     * `variations` (variation id => changed row fields), and `category_slugs` / `tag_slugs` when given (the tool resolves them to ids
     * and drops them when they match). Switching managed stock off also lists the values WooCommerce derives (`stock_quantity` null,
     * `backorders` "no") so that the preview and the undo show them.
     *
     * @param array<string,mixed> $update the entry without its `id`
     * @param array<string,mixed> $current ProductWriter::product()
     * @param \Closure(string):string $kses wp_kses_post
     * @param \Closure(string):string $stripTags wp_strip_all_tags
     * @return array{changes: ?array<string,mixed>, reasons: list<string>}
     */
    public static function checkPartial( array $update, array $current, \Closure $kses, \Closure $stripTags ): array
    {
        $r    = [];
        $c    = [];
        $type = (string) ( $current['type'] ?? '' );

        foreach ( array_keys( $update ) as $key ) {
            if ( ! is_string( $key ) || ! in_array( $key, self::UPDATE_KEYS, true ) ) {
                $note = in_array( $key, [ 'status', 'type', 'slug' ], true ) ? " (this tool never changes a product's status, type or slug)" : '';
                $r[]  = '"' . self::shown( (string) $key ) . '" is not a supported field' . $note;
            }
        }

        if ( self::given( $update, 'name' ) ) {
            $name = self::validName( $update['name'], $stripTags, '"name" must be a non-empty string', $r );
            if ( null !== $name && ! self::sameText( $name, (string) ( $current['name'] ?? '' ) ) ) {
                $c['name'] = $name;
            }
        }
        foreach ( [ 'description' => self::MAX_DESCRIPTION, 'short_description' => self::MAX_SHORT ] as $key => $max ) {
            if ( self::given( $update, $key ) ) {
                $before = count( $r );
                $text   = self::text( $update, $key, $max, $kses, $r );
                if ( count( $r ) === $before && ! self::sameText( $text, (string) ( $current[ $key ] ?? '' ) ) ) {
                    $c[ $key ] = $text;
                }
            }
        }
        if ( self::given( $update, 'sku' ) ) {
            $sku = '' === $update['sku'] ? '' : self::validSku( $update['sku'], $r );
            if ( null !== $sku && $sku !== (string) ( $current['sku'] ?? '' ) ) {
                $c['sku'] = $sku;
            }
        }

        // Prices, the sale window and stock. A variable product keeps its prices in its variation rows.
        $own = $update;
        if ( 'variable' === $type ) {
            foreach ( [ 'regular_price', 'sale_price', 'sale_from', 'sale_to' ] as $key ) {
                if ( self::given( $update, $key ) ) {
                    $r[] = "\"{$key}\" is not available on a variable product (change the prices of its variations instead)";
                }
                unset( $own[ $key ] );
            }
        }
        $c += self::rowChanges( $own, $current, 'external' !== $type, '', $r );

        // Texts and catalogue data.
        if ( self::given( $update, 'weight' ) ) {
            $weight = self::decimalOrClear( $update['weight'], '"weight"', $r );
            if ( null !== $weight && ! self::sameValue( 'weight', $weight, (string) ( $current['weight'] ?? '' ) ) ) {
                $c['weight'] = $weight;
            }
        }
        if ( self::given( $update, 'dimensions' ) ) {
            $d = $update['dimensions'];
            if ( ! is_array( $d ) || array_is_list( $d ) || [] !== array_diff( array_keys( $d ), [ 'length', 'width', 'height' ] ) ) {
                $r[] = '"dimensions" must be an object with only "length", "width" and "height"';
            } else {
                foreach ( [ 'length', 'width', 'height' ] as $axis ) {
                    if ( ! array_key_exists( $axis, $d ) || null === $d[ $axis ] ) {
                        continue;
                    }
                    $value = self::decimalOrClear( $d[ $axis ], "\"dimensions\" {$axis}", $r );
                    if ( null !== $value && ! self::sameValue( $axis, $value, (string) ( $current[ $axis ] ?? '' ) ) ) {
                        $c[ $axis ] = $value;
                    }
                }
            }
        }
        if ( self::given( $update, 'tax_status' ) ) {
            $tax = self::enum( $update, 'tax_status', self::TAX_STATUSES, (string) ( $current['tax_status'] ?? 'taxable' ), $r );
            if ( $tax !== (string) ( $current['tax_status'] ?? 'taxable' ) ) {
                $c['tax_status'] = $tax;
            }
        }
        if ( self::given( $update, 'image_id' ) ) {
            if ( is_int( $update['image_id'] ) && $update['image_id'] >= 0 ) {
                if ( $update['image_id'] !== (int) ( $current['image_id'] ?? 0 ) ) {
                    $c['image_id'] = $update['image_id'];
                }
            } else {
                $r[] = '"image_id" must be the id of an image in the Media Library, or 0 to remove the image';
            }
        }
        if ( self::given( $update, 'gallery_image_ids' ) ) {
            $gallery = self::galleryList( $update['gallery_image_ids'], $r );
            if ( null !== $gallery && $gallery !== array_map( 'intval', array_values( (array) ( $current['gallery_image_ids'] ?? [] ) ) ) ) {
                $c['gallery_image_ids'] = $gallery;
            }
        }
        if ( self::given( $update, 'categories' ) ) {
            $c['category_slugs'] = self::slugs( $update, 'categories', self::MAX_CATEGORIES, $r );
        }
        if ( self::given( $update, 'tags' ) ) {
            $c['tag_slugs'] = self::slugs( $update, 'tags', self::MAX_TAGS, $r );
        }

        // Variation rows (variable products only).
        if ( self::given( $update, 'variations' ) ) {
            $rows = [];
            self::variationChanges( $update['variations'], $current, $type, $rows, $r );
            if ( [] !== $rows ) {
                $c['variations'] = $rows;
            }
        }

        return [ 'changes' => [] === $r ? $c : null, 'reasons' => $r ];
    }

    /**
     * @param array<string,mixed> $current
     * @param array<int,array<string,mixed>> $out
     * @param list<string> $r
     */
    private static function variationChanges( mixed $list, array $current, string $type, array &$out, array &$r ): void
    {
        if ( 'variable' !== $type ) {
            $r[] = '"variations" is only for variable products';

            return;
        }
        if ( ! is_array( $list ) || ! array_is_list( $list ) || [] === $list || count( $list ) > self::MAX_VARIATIONS ) {
            $r[] = '"variations" must be a list of 1 to ' . self::MAX_VARIATIONS . ' objects';

            return;
        }
        $seen = [];
        foreach ( $list as $i => $row ) {
            $n = $i + 1;
            if ( ! is_array( $row ) || array_is_list( $row ) ) {
                $r[] = "variations row {$n} must be an object with the variation \"id\"";
                continue;
            }
            $vid = $row['id'] ?? null;
            if ( ! is_int( $vid ) || $vid < 1 ) {
                $r[] = "variations row {$n}: \"id\" must be a whole variation id";
                continue;
            }
            if ( ! isset( $current['variations'][ $vid ] ) ) {
                $r[] = "variation {$vid} does not belong to this product";
                continue;
            }
            if ( isset( $seen[ $vid ] ) ) {
                $r[] = "variation {$vid} appears more than once";
                continue;
            }
            $seen[ $vid ] = true;
            foreach ( array_keys( $row ) as $key ) {
                if ( 'id' !== $key && ! in_array( $key, ProductFields::ROW, true ) ) {
                    $r[] = "variation {$vid}: \"" . self::shown( (string) $key ) . '" is not a supported field';
                }
            }
            $changes = self::rowChanges( $row, $current['variations'][ $vid ], true, "variation {$vid}: ", $r );
            if ( [] !== $changes ) {
                $out[ $vid ] = $changes;
            }
        }
    }

    /**
     * The price, sale window and stock part, shared by a product and a variation row: returns the fields of ProductFields::ROW that
     * differ from `$cur`. Reasons are prefixed with `$at` (for example "variation 12: ").
     *
     * @param array<string,mixed> $in
     * @param array<string,mixed> $cur
     * @param list<string> $r
     * @return array<string,mixed>
     */
    private static function rowChanges( array $in, array $cur, bool $stockAllowed, string $at, array &$r ): array
    {
        $rr  = [];
        $c   = [];
        $new = [];
        foreach ( [ 'regular_price', 'sale_price' ] as $key ) {
            if ( self::given( $in, $key ) ) {
                $value = self::decimalOrClear( $in[ $key ], "\"{$key}\"", $rr );
                if ( null !== $value ) {
                    $new[ $key ] = $value;
                }
            }
        }
        foreach ( [ 'sale_from', 'sale_to' ] as $key ) {
            if ( self::given( $in, $key ) ) {
                $value = self::dateOrClear( $in[ $key ], "\"{$key}\"", $rr );
                if ( null !== $value ) {
                    $new[ $key ] = $value;
                }
            }
        }
        if ( [] !== $new ) {
            $res = $new + [
                'regular_price' => (string) ( $cur['regular_price'] ?? '' ), 'sale_price' => (string) ( $cur['sale_price'] ?? '' ),
                'sale_from'     => (string) ( $cur['sale_from'] ?? '' ), 'sale_to' => (string) ( $cur['sale_to'] ?? '' ),
            ];
            if ( ( isset( $new['regular_price'] ) || isset( $new['sale_price'] ) ) && '' !== $res['sale_price'] ) {
                if ( '' === $res['regular_price'] ) {
                    $rr[] = 'the resulting "sale_price" needs a "regular_price"';
                } elseif ( self::compareDecimals( $res['sale_price'], $res['regular_price'] ) >= 0 ) {
                    $rr[] = 'the resulting "sale_price" must be lower than the "regular_price"';
                }
            }
            if ( isset( $new['sale_price'] ) || isset( $new['sale_from'] ) || isset( $new['sale_to'] ) ) {
                if ( ( '' !== $res['sale_from'] || '' !== $res['sale_to'] ) && '' === $res['sale_price'] ) {
                    $rr[] = 'sale dates need a "sale_price" (to end a sale, clear "sale_price", "sale_from" and "sale_to" together)';
                }
                if ( '' !== $res['sale_from'] && '' !== $res['sale_to'] && $res['sale_to'] < $res['sale_from'] ) {
                    $rr[] = '"sale_to" must not be before "sale_from"';
                }
            }
            foreach ( $new as $key => $value ) {
                if ( ! self::sameValue( $key, $value, (string) ( $cur[ $key ] ?? '' ) ) ) {
                    $c[ $key ] = $value;
                }
            }
        }

        $given = array_values( array_filter( ProductFields::STOCK, static fn ( string $key ): bool => self::given( $in, $key ) ) );
        if ( [] !== $given && ! $stockAllowed ) {
            foreach ( $given as $key ) {
                $rr[] = "\"{$key}\" is not available on an external product";
            }
        } elseif ( [] !== $given ) {
            $curManage = (bool) ( $cur['manage_stock'] ?? false );
            $manage    = $curManage;
            if ( self::given( $in, 'manage_stock' ) ) {
                if ( is_bool( $in['manage_stock'] ) ) {
                    $manage = $in['manage_stock'];
                } else {
                    $rr[] = '"manage_stock" must be true or false';
                }
            }
            if ( $manage ) {
                $qty = $curManage ? ( $cur['stock_quantity'] ?? null ) : null;
                if ( self::given( $in, 'stock_quantity' ) ) {
                    if ( is_int( $in['stock_quantity'] ) && $in['stock_quantity'] >= 0 && $in['stock_quantity'] <= self::MAX_STOCK ) {
                        $qty = $in['stock_quantity'];
                    } else {
                        $rr[] = '"stock_quantity" must be a whole number from 0 to 1,000,000';
                    }
                } elseif ( null === $qty ) {
                    $rr[] = 'managed stock needs a "stock_quantity"';
                }
                if ( self::given( $in, 'stock_status' ) ) {
                    $rr[] = '"stock_status" cannot be set while stock is managed (WooCommerce works it out from the quantity)';
                }
                $backorders = self::enum( $in, 'backorders', self::BACKORDERS, (string) ( $cur['backorders'] ?? 'no' ), $rr );
                if ( $manage !== $curManage ) {
                    $c['manage_stock'] = $manage;
                }
                if ( null !== $qty && ( ! $curManage || $qty !== ( $cur['stock_quantity'] ?? null ) ) ) {
                    $c['stock_quantity'] = $qty;
                }
                if ( $backorders !== (string) ( $cur['backorders'] ?? 'no' ) ) {
                    $c['backorders'] = $backorders;
                }
            } else {
                foreach ( [ 'stock_quantity', 'backorders' ] as $key ) {
                    if ( self::given( $in, $key ) ) {
                        $rr[] = "\"{$key}\" needs managed stock (\"manage_stock\": true)";
                    }
                }
                $status = self::enum( $in, 'stock_status', self::STOCK_STATUSES, (string) ( $cur['stock_status'] ?? 'instock' ), $rr );
                if ( $curManage ) {
                    // Switching managed stock off: list what WooCommerce clears so the preview and the undo show it.
                    $c['manage_stock'] = false;
                    if ( null !== ( $cur['stock_quantity'] ?? null ) ) {
                        $c['stock_quantity'] = null;
                    }
                    if ( 'no' !== (string) ( $cur['backorders'] ?? 'no' ) ) {
                        $c['backorders'] = 'no';
                    }
                }
                if ( $status !== (string) ( $cur['stock_status'] ?? 'instock' ) ) {
                    $c['stock_status'] = $status;
                }
            }
        }

        foreach ( $rr as $reason ) {
            $r[] = $at . $reason;
        }

        return $c;
    }

    /** Decimal strings compare as numbers ("20" equals "20.00"), everything else exactly. */
    private static function sameValue( string $key, string $new, string $old ): bool
    {
        $decimal = in_array( $key, [ 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' ], true );

        return $decimal && '' !== $new && '' !== $old ? 0 === self::compareDecimals( $new, $old ) : $new === $old;
    }

    /** @param list<string> $r @return ?string the decimal, "" to clear it, or null (with a reason) when it is neither */
    private static function decimalOrClear( mixed $value, string $label, array &$r ): ?string
    {
        if ( '' === $value ) {
            return '';
        }
        if ( is_string( $value ) && 1 === preg_match( self::DECIMAL, $value ) ) {
            return $value;
        }
        $r[] = "{$label} must be a decimal string such as \"20\" or \"20.50\" (no currency symbol or thousands separator), or \"\" to clear it";

        return null;
    }

    /** @param list<string> $r @return ?string a real calendar date (YYYY-MM-DD), "" to clear it, or null (with a reason) */
    private static function dateOrClear( mixed $value, string $label, array &$r ): ?string
    {
        if ( '' === $value ) {
            return '';
        }
        if ( is_string( $value ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
            return $value;
        }
        $r[] = "{$label} must be a date such as \"2026-12-31\" (YYYY-MM-DD), or \"\" to clear it";

        return null;
    }

    /** @param list<string> $r @return ?string the name when it is allowed, otherwise null and a reason */
    private static function validName( mixed $name, \Closure $stripTags, string $emptyMessage, array &$r ): ?string
    {
        if ( ! is_string( $name ) || '' === trim( $name ) ) {
            $r[] = $emptyMessage;
        } elseif ( 1 !== preg_match( '//u', $name ) ) {
            $r[] = '"name" is not valid UTF-8';
        } elseif ( mb_strlen( $name ) > self::MAX_NAME ) {
            $r[] = '"name" is longer than 200 characters';
        } elseif ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
            $r[] = '"name" must not contain control characters';
        } elseif ( trim( $name ) !== $name ) {
            $r[] = '"name" must not start or end with spaces';
        } elseif ( str_contains( $name, '<' ) || str_contains( $name, '>' ) ) {
            // Core's title filter (kses, for a user without unfiltered_html) entity-encodes a lone ">" and cuts the name at a "<".
            $r[] = '"name" must not contain < or >';
        } elseif ( str_contains( $name, '\\' ) ) {
            $r[] = '"name" ' . self::BACKSLASH;
        } elseif ( $stripTags( $name ) !== $name ) {
            $r[] = '"name" must be plain text (no HTML tags)';
        } else {
            return $name;
        }

        return null;
    }

    /** @param list<string> $r @return ?string the SKU when it is allowed, otherwise null and a reason */
    private static function validSku( mixed $sku, array &$r ): ?string
    {
        if ( ! is_string( $sku ) || 1 !== preg_match( '/^[\x20-\x7E]{1,100}$/D', $sku ) || trim( $sku ) !== $sku ) {
            $r[] = '"sku" must be 1 to 100 printable ASCII characters without leading or trailing spaces';
        } elseif ( str_contains( $sku, '<' ) || str_contains( $sku, '>' ) ) {
            // WooCommerce's own SKU input runs wc_clean(), and its product page prints the SKU unescaped: never store markup there.
            $r[] = '"sku" must not contain < or >';
        } else {
            return $sku;
        }

        return null;
    }

    /** @param list<string> $r @return ?list<int> */
    private static function galleryList( mixed $g, array &$r ): ?array
    {
        if ( ! is_array( $g ) || ! array_is_list( $g ) || count( $g ) > self::MAX_GALLERY || count( $g ) !== count( array_unique( $g, SORT_REGULAR ) ) ) {
            $r[] = '"gallery_image_ids" must be a list of at most 10 different image ids';

            return null;
        }
        foreach ( $g as $id ) {
            if ( ! is_int( $id ) || $id < 1 ) {
                $r[] = '"gallery_image_ids" must contain only whole image ids from 1';

                return null;
            }
        }

        return array_values( $g );
    }

    /**
     * The one text comparison the tool uses (input vs kses output, asked vs read back, title vs our name): equal once core's only
     * harmless rewrite, a bare "&" stored as "&amp;" (kses, for a user without unfiltered_html), is undone on both sides.
     */
    public static function sameText( string $a, string $b ): bool
    {
        return str_replace( '&amp;', '&', $a ) === str_replace( '&amp;', '&', $b );
    }

    /** Exact comparison of two decimal strings that match DECIMAL (no float rounding). @return int -1, 0 or 1 */
    public static function compareDecimals( string $a, string $b ): int
    {
        [ $ai, $af ] = array_pad( explode( '.', $a, 2 ), 2, '' );
        [ $bi, $bf ] = array_pad( explode( '.', $b, 2 ), 2, '' );
        $ai = ltrim( $ai, '0' );
        $bi = ltrim( $bi, '0' );
        if ( strlen( $ai ) !== strlen( $bi ) ) {
            return strlen( $ai ) <=> strlen( $bi );
        }
        if ( $ai !== $bi ) {
            return strcmp( $ai, $bi ) <=> 0;
        }
        $len = max( strlen( $af ), strlen( $bf ) );

        return strcmp( str_pad( $af, $len, '0' ), str_pad( $bf, $len, '0' ) ) <=> 0;
    }

    /** @param array<string,mixed> $p */
    private static function given( array $p, string $key ): bool
    {
        return array_key_exists( $key, $p ) && null !== $p[ $key ];
    }

    /** @param array<string,mixed> $p @param list<string> $r */
    private static function text( array $p, string $key, int $max, \Closure $kses, array &$r ): string
    {
        if ( ! self::given( $p, $key ) ) {
            return '';
        }
        $value = $p[ $key ];
        if ( ! is_string( $value ) ) {
            $r[] = "\"{$key}\" must be a string";

            return '';
        }
        if ( mb_strlen( $value ) > $max ) {
            $r[] = "\"{$key}\" is longer than " . number_format( $max ) . ' characters';

            return '';
        }
        if ( str_contains( $value, '\\' ) ) {
            $r[] = "\"{$key}\" " . self::BACKSLASH;

            return '';
        }
        $why = FieldValuePolicy::refusal( $value );
        if ( null !== $why ) {
            $r[] = "\"{$key}\" {$why}";

            return '';
        }
        // Core's wp_kses_post() rewrites a bare "&" to "&amp;"; that is not unsafe markup, so the comparison undoes it (sameText).
        if ( ! self::sameText( $kses( $value ), $value ) ) {
            $r[] = "\"{$key}\" contains markup WordPress does not allow in post content";

            return '';
        }

        return $value;
    }

    /** @param array<string,mixed> $p @param list<string> $r */
    private static function decimal( array $p, string $key, array &$r ): string
    {
        if ( ! self::given( $p, $key ) ) {
            return '';
        }
        if ( is_string( $p[ $key ] ) && 1 === preg_match( self::DECIMAL, $p[ $key ] ) ) {
            return $p[ $key ];
        }
        $r[] = "\"{$key}\" must be a decimal string such as \"20\" or \"20.50\" (no currency symbol or thousands separator)";

        return '';
    }

    /** @param array<string,mixed> $p @param list<string> $allowed @param list<string> $r */
    private static function enum( array $p, string $key, array $allowed, string $default, array &$r ): string
    {
        if ( ! self::given( $p, $key ) ) {
            return $default;
        }
        if ( is_string( $p[ $key ] ) && in_array( $p[ $key ], $allowed, true ) ) {
            return $p[ $key ];
        }
        $r[] = "\"{$key}\" must be one of: " . implode( ', ', $allowed );

        return $default;
    }

    /** @param array<string,mixed> $p @param list<string> $r */
    private static function flag( array $p, string $key, array &$r ): bool
    {
        if ( ! self::given( $p, $key ) ) {
            return false;
        }
        if ( is_bool( $p[ $key ] ) ) {
            return $p[ $key ];
        }
        $r[] = "\"{$key}\" must be true or false";

        return false;
    }

    /**
     * @param array<string,mixed> $p @param list<string> $r
     * @return list<string>
     */
    private static function slugs( array $p, string $key, int $max, array &$r ): array
    {
        if ( ! self::given( $p, $key ) ) {
            return [];
        }
        $list = $p[ $key ];
        if ( ! is_array( $list ) || ! array_is_list( $list ) || count( $list ) > $max || count( $list ) !== count( array_unique( $list, SORT_REGULAR ) ) ) {
            $r[] = "\"{$key}\" must be a list of at most {$max} different slugs";

            return [];
        }
        foreach ( $list as $slug ) {
            if ( ! is_string( $slug ) || 1 !== preg_match( self::SLUG, $slug ) ) {
                $r[] = "\"{$key}\" must contain only slugs (letters, digits, \"-\", \"_\" or \"%\")";

                return [];
            }
        }

        return array_values( $list );
    }

    private static function shown( string $key ): string
    {
        return mb_strlen( $key ) > 80 ? mb_substr( $key, 0, 80 ) . '...' : $key;
    }
}
