<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only code in the add-on that reads and writes a post's custom fields. Writes go through ACF's own update_field() when an ACF
 * selector (the field key) is given and ACF is active, otherwise through update_post_meta(). WordPress unslashes meta values it is
 * given, and so does ACF's update_field(), so both the plain path and the ACF path slash the value first. Every write is verified by reading the raw value back.
 */
class PostMetaStore // not final: the tool tests extend it with doubles that fail a write or report a key stored twice
{
    /** @var ?callable(string,string,int):mixed */
    private $acfWriter;
    /** @var ?callable(string,int):mixed */
    private $acfDeleter;

    public function __construct( ?callable $acfWriter = null, ?callable $acfDeleter = null )
    {
        $this->acfWriter  = $acfWriter;
        $this->acfDeleter = $acfDeleter;
    }

    public static function wp(): self
    {
        return new self(
            function_exists( 'update_field' ) ? 'update_field' : null,
            function_exists( 'delete_field' ) ? 'delete_field' : null
        );
    }

    /** @return list<string> every meta key the post stores (as WordPress reports them, hidden ones included) */
    public function keys( int $postId ): array
    {
        $all = get_post_custom( $postId );

        return is_array( $all ) ? array_map( 'strval', array_keys( $all ) ) : [];
    }

    /** ACF's reference row for a key: the post's `_{key}` meta as a string ('' when absent or not a string). Holds the field key (`field_...`) of an ACF-managed value. */
    public function reference( int $postId, string $key ): string
    {
        $ref = get_post_meta( $postId, '_' . $key, true );

        return is_string( $ref ) ? $ref : '';
    }

    /** @return array{exists:bool, value:?string, rows:int, structured:bool} */
    public function read( int $postId, string $key ): array
    {
        $all  = get_post_custom( $postId );
        $rows = is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? count( $all[ $key ] ) : 0;
        if ( 0 === $rows ) {
            return [ 'exists' => false, 'value' => null, 'rows' => 0, 'structured' => false ];
        }
        $value      = get_post_meta( $postId, $key, true );
        $structured = is_array( $value ) || is_object( $value );

        return [ 'exists' => true, 'value' => is_scalar( $value ) ? (string) $value : null, 'rows' => $rows, 'structured' => $structured ];
    }

    /** True only when the raw value read back equals $value. */
    public function write( int $postId, string $key, string $value, ?string $acfSelector = null ): bool
    {
        if ( null !== $acfSelector && null !== $this->acfWriter ) {
            // ACF's update_field() unslashes its value like update_post_meta() does (verified live on ACF 6.8.10), so it is slashed
            // here too. The read-back check below is the guard if an ACF version ever behaves differently.
            ( $this->acfWriter )( $acfSelector, wp_slash( $value ), $postId );
        } else {
            update_post_meta( $postId, $key, wp_slash( $value ) );
        }
        $now = $this->read( $postId, $key );

        return $now['exists'] && 1 === $now['rows'] && $now['value'] === $value;
    }

    /** True when the key no longer exists. */
    public function delete( int $postId, string $key, ?string $acfSelector = null ): bool
    {
        if ( null !== $acfSelector && null !== $this->acfDeleter ) {
            ( $this->acfDeleter )( $acfSelector, $postId );
        }
        delete_post_meta( $postId, $key ); // also clears a plain key; for an ACF key it removes any row ACF left behind

        return ! $this->read( $postId, $key )['exists'];
    }
}
