<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stores AI-proposed PHP snippets for a HUMAN to review. They are strings in an option: nothing in this add-on
 * includes, evaluates or writes them to a file, so an API key never becomes a code-execution credential.
 */
final class PhpProposals
{
    public const OPTION = 'aied_pro_php_proposals';
    public const MAX    = 50;

    /** @return list<array{id:string,title:string,description:string,code:string,created:int,user_id:int}> newest first */
    public static function all(): array
    {
        $data = get_option( self::OPTION, [] );

        return is_array( $data ) ? array_values( $data ) : [];
    }

    /** Store a proposal (inert) and return its id. Only the newest MAX are kept. */
    public static function add( string $title, string $description, string $code, int $userId ): string
    {
        $items = self::all();
        $id    = 'php_' . bin2hex( random_bytes( 6 ) );
        array_unshift( $items, [
            'id'          => $id,
            'title'       => '' !== $title ? $title : 'Untitled snippet',
            'description' => $description,
            'code'        => $code,
            'created'     => time(),
            'user_id'     => $userId,
        ] );
        update_option( self::OPTION, array_slice( $items, 0, self::MAX ), false );

        return $id;
    }

    public static function delete( string $id ): void
    {
        $items = array_filter( self::all(), static fn ( array $p ): bool => $p['id'] !== $id );
        update_option( self::OPTION, array_values( $items ), false );
    }

    public static function clear(): void
    {
        delete_option( self::OPTION );
    }

    public static function count(): int
    {
        return count( self::all() );
    }
}
