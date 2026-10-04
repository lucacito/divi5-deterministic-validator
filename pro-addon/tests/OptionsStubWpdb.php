<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

/**
 * A stand-in for the few `$wpdb` statements the build lock sends, over `$GLOBALS['__wp_options']`. It interprets exactly
 * the statements BuildLock writes (INSERT IGNORE, compare-and-swap UPDATE, owner-checked DELETE, SELECT of one value), so the
 * lock's logic is proven here; that MySQL really behaves like this is proven by scripts/verify-pro-build-site.php.
 */
final class OptionsStubWpdb
{
    public string $options = 'wp_options';
    /** @var list<string> every statement received */
    public array $statements = [];

    public function prepare( string $query, mixed ...$args ): string
    {
        foreach ( $args as $a ) {
            $query = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . addslashes( (string) $a ) . "'", $query, 1 ) ?? $query;
        }

        return $query;
    }

    public function query( string $sql ): int
    {
        $this->statements[] = $sql;
        if ( 1 === preg_match( "/^INSERT IGNORE INTO wp_options \\(option_name, option_value, autoload\\) VALUES \\('([^']*)', '([^']*)', 'no'\\)\$/", $sql, $m ) ) {
            if ( array_key_exists( $m[1], $GLOBALS['__wp_options'] ) ) {
                return 0;
            }
            $GLOBALS['__wp_options'][ $m[1] ] = $m[2];

            return 1;
        }
        if ( 1 === preg_match( "/^UPDATE wp_options SET option_value = '([^']*)' WHERE option_name = '([^']*)' AND option_value = '([^']*)'\$/", $sql, $m ) ) {
            if ( ( $GLOBALS['__wp_options'][ $m[2] ] ?? null ) !== $m[3] ) {
                return 0;
            }
            $GLOBALS['__wp_options'][ $m[2] ] = $m[1];

            return 1;
        }
        if ( 1 === preg_match( "/^DELETE FROM wp_options WHERE option_name = '([^']*)' AND option_value = '([^']*)'\$/", $sql, $m ) ) {
            if ( ( $GLOBALS['__wp_options'][ $m[1] ] ?? null ) !== $m[2] ) {
                return 0;
            }
            unset( $GLOBALS['__wp_options'][ $m[1] ] );

            return 1;
        }
        throw new \LogicException( 'the stub does not know this statement: ' . $sql );
    }

    public function get_var( string $sql ): ?string
    {
        $this->statements[] = $sql;
        if ( 1 === preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '([^']*)'\$/", $sql, $m ) ) {
            return isset( $GLOBALS['__wp_options'][ $m[1] ] ) ? (string) $GLOBALS['__wp_options'][ $m[1] ] : null;
        }
        throw new \LogicException( 'the stub does not know this statement: ' . $sql );
    }
}
