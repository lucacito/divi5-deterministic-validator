<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only code in the add-on that reads and writes a post's custom fields. Writes go through ACF's own update_field() when an ACF
 * selector (the field key) is given and ACF is active, otherwise through update_post_meta(). WordPress unslashes meta values it is
 * given, so the plain path slashes first. Every write is verified by reading the raw value back.
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
            ( $this->acfWriter )( $acfSelector, $value, $postId );
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
