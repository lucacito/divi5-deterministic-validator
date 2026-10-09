<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\ValueKind;
use AiEditorDivi5\Pro\Fields\PostMetaStore;
use AiEditorDivi5\WP\FieldTarget;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One custom field of one post. `ref` is "{postId}:{key}". The canonical value (and the stored `before`) is
 * `{ exists: bool, value: ?string }`, so undoing a call that created a key deletes it and undoing a change puts the old value back.
 * An item written through ACF carries `meta.acf` (the ACF field key) so the restore uses the same path.
 */
final class PostMetaKind extends ValueKind
{
    public const NAME = 'post_meta';

    private ?PostMetaStore $store;

    public function __construct( ?PostMetaStore $store = null )
    {
        $this->store = $store;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function current( array $item ): mixed
    {
        [ $postId, $key ] = self::parse( (string) ( $item['ref'] ?? '' ) );
        if ( $postId < 1 ) {
            return [ 'exists' => false, 'value' => null ];
        }
        $now = $this->store()->read( $postId, $key );

        return [ 'exists' => $now['exists'], 'value' => $now['value'] ];
    }

    public function allowed( array $item ): bool
    {
        [ $postId, $key ] = self::parse( (string) ( $item['ref'] ?? '' ) );
        if ( $postId < 1 || '' === $key ) {
            return false;
        }

        return null === FieldTarget::resolve( $postId )['error'] && current_user_can( 'edit_post_meta', $postId, $key );
    }

    public function restore( array $item ): array
    {
        $result = parent::restore( $item );
        if ( $result['ok'] && isset( $item['meta'] ) ) {
            $result['inverse']['meta'] = $item['meta']; // the undo of the undo must use the same ACF path
        }

        return $result;
    }

    protected function write( array $item, mixed $value ): bool
    {
        [ $postId, $key ] = self::parse( (string) ( $item['ref'] ?? '' ) );
        if ( $postId < 1 || '' === $key ) {
            return false;
        }
        $acf = isset( $item['meta']['acf'] ) && is_string( $item['meta']['acf'] ) && '' !== $item['meta']['acf'] ? $item['meta']['acf'] : null;
        if ( is_array( $value ) && ! empty( $value['exists'] ) ) {
            return $this->store()->write( $postId, $key, (string) ( $value['value'] ?? '' ), $acf );
        }

        return $this->store()->delete( $postId, $key, $acf );
    }

    /** @return array{0:int,1:string} post id (0 when the ref is malformed) and key */
    private static function parse( string $ref ): array
    {
        $pos = strpos( $ref, ':' );
        if ( false === $pos ) {
            return [ 0, '' ];
        }
        $id = substr( $ref, 0, $pos );

        return [ ctype_digit( $id ) ? (int) $id : 0, substr( $ref, $pos + 1 ) ];
    }

    private function store(): PostMetaStore
    {
        return $this->store ??= PostMetaStore::wp();
    }
}
