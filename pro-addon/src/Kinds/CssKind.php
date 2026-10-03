<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\ValueKind;
use AiEditorDivi5\Pro\Settings\CustomCss;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The managed block of Additional CSS. Only the block counts as "changed since"; the owner's own CSS outside it does not. */
final class CssKind extends ValueKind
{
    public function name(): string
    {
        return 'custom_css';
    }

    public function current( array $item = [] ): mixed
    {
        return CustomCss::extract( (string) wp_get_custom_css() );
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'edit_css' );
    }

    protected function write( array $item, mixed $value ): bool
    {
        $res = wp_update_custom_css_post( CustomCss::apply( (string) wp_get_custom_css(), is_string( $value ) ? $value : null ) );

        return ! is_wp_error( $res );
    }
}
