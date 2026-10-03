<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\ValueKind;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The static front page: the `show_on_front` and `page_on_front` options, read and written as one value. */
final class FrontPageKind extends ValueKind
{
    public function name(): string
    {
        return 'front_page';
    }

    /** @return array{show_on_front:string, page_on_front:int} */
    public static function read(): array
    {
        return [ 'show_on_front' => (string) get_option( 'show_on_front', 'posts' ), 'page_on_front' => (int) get_option( 'page_on_front', 0 ) ];
    }

    public static function set( string $show, int $page ): void
    {
        update_option( 'show_on_front', $show );
        update_option( 'page_on_front', $page );
    }

    public function current( array $item = [] ): mixed
    {
        return self::read();
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'manage_options' );
    }

    protected function write( array $item, mixed $value ): bool
    {
        if ( ! is_array( $value ) || ! isset( $value['show_on_front'], $value['page_on_front'] ) ) {
            return false;
        }
        self::set( (string) $value['show_on_front'], (int) $value['page_on_front'] );

        return true;
    }
}
