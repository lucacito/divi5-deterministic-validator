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
        $name = $product['name'] ?? null;
        $v['name'] = '';
        if ( ! is_string( $name ) || '' === trim( $name ) ) {
            $r[] = '"name" is required and must be a non-empty string';
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
            $v['name'] = $name;
        }

        $v['description']       = self::text( $product, 'description', self::MAX_DESCRIPTION, $kses, $r );
        $v['short_description'] = self::text( $product, 'short_description', self::MAX_SHORT, $kses, $r );

        // sku
        $v['sku'] = '';
        if ( self::given( $product, 'sku' ) ) {
            $sku = $product['sku'];
            if ( ! is_string( $sku ) || 1 !== preg_match( '/^[\x20-\x7E]{1,100}$/D', $sku ) || trim( $sku ) !== $sku ) {
                $r[] = '"sku" must be 1 to 100 printable ASCII characters without leading or trailing spaces';
            } elseif ( str_contains( $sku, '<' ) || str_contains( $sku, '>' ) ) {
                // WooCommerce's own SKU input runs wc_clean(), and its product page prints the SKU unescaped: never store markup there.
                $r[] = '"sku" must not contain < or >';
            } else {
                $v['sku'] = $sku;
            }
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
            $g = $product['gallery_image_ids'];
            if ( ! is_array( $g ) || ! array_is_list( $g ) || count( $g ) > self::MAX_GALLERY || count( $g ) !== count( array_unique( $g, SORT_REGULAR ) ) ) {
                $r[] = '"gallery_image_ids" must be a list of at most 10 different image ids';
            } else {
                foreach ( $g as $id ) {
                    if ( ! is_int( $id ) || $id < 1 ) {
                        $r[] = '"gallery_image_ids" must contain only whole image ids from 1';
                        break;
                    }
                    $v['gallery_image_ids'][] = $id;
                }
            }
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
