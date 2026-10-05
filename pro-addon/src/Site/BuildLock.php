<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Site;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One add_page or launch at a time per user. An MCP client may send tool calls in parallel; two of them must not both read the
 * build, both create a page and both write the batch back (one page would then be missing from the build and from its undo).
 *
 * The lock is a row of the options table and it is atomic on the database, NOT through `add_option` (core's add_option ends in
 * INSERT ... ON DUPLICATE KEY UPDATE, so two requests can both "win" it): `INSERT IGNORE` whose affected-row count says who won,
 * a compare-and-swap `UPDATE` to take over a lock older than `$expirySeconds` (a crashed request; only one waiter can win it),
 * and a `DELETE` that only removes the value this request wrote, so a request that ran past the expiry cannot release the lock
 * of the one that took over. Where there is no `$wpdb` (unit tests, a tool run outside WordPress) the same steps go through the
 * options functions, which is correct but not atomic.
 */
final class BuildLock
{
    private const STEP_MS = 100;
    private const PREFIX  = 'aied_pro_buildlock_';

    /** @var array<int,string> the value this request wrote, per user */
    private array $held = [];

    /** @param string $prefix the option-name prefix; the user id is appended (a different prefix is a different, independent lock) */
    public function __construct( private int $waitMs = 5000, private int $expirySeconds = 15, private string $prefix = self::PREFIX )
    {
    }

    public function acquire( int $userId ): bool
    {
        $key   = $this->prefix . $userId;
        $token = bin2hex( random_bytes( 8 ) );
        for ( $waited = 0; ; $waited += self::STEP_MS ) {
            $value = time() . ':' . $token;
            if ( $this->insert( $key, $value ) ) {
                $this->held[ $userId ] = $value;

                return true;
            }
            $current = $this->read( $key );
            if ( null !== $current && (int) $current > 0 && time() - (int) $current >= $this->expirySeconds && $this->swap( $key, $current, $value ) ) {
                $this->held[ $userId ] = $value;

                return true;
            }
            if ( $waited >= $this->waitMs ) {
                return false;
            }
            usleep( self::STEP_MS * 1000 );
        }
    }

    public function release( int $userId ): void
    {
        if ( isset( $this->held[ $userId ] ) ) {
            $this->delete( $this->prefix . $userId, $this->held[ $userId ] );
            unset( $this->held[ $userId ] );
        }
    }

    private static function sql(): bool
    {
        return isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'query' );
    }

    private function insert( string $key, string $value ): bool
    {
        if ( self::sql() ) {
            global $wpdb;

            return 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $key, $value, 'no' ) );
        }

        return add_option( $key, $value, '', 'no' );
    }

    private function read( string $key ): ?string
    {
        if ( self::sql() ) {
            global $wpdb;
            $v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );

            return null === $v ? null : (string) $v;
        }
        $v = get_option( $key, null );

        return null === $v || false === $v ? null : (string) $v;
    }

    private function swap( string $key, string $old, string $new ): bool
    {
        if ( self::sql() ) {
            global $wpdb;

            return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new, $key, $old ) );
        }
        if ( (string) get_option( $key, '' ) !== $old ) {
            return false;
        }
        update_option( $key, $new, false );

        return true;
    }

    private function delete( string $key, string $value ): void
    {
        if ( self::sql() ) {
            global $wpdb;
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $value ) );

            return;
        }
        if ( (string) get_option( $key, '' ) === $value ) {
            delete_option( $key );
        }
    }
}
