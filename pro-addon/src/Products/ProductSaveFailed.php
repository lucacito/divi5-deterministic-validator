<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A product could not be created or did not come back as asked. The message is always one of OURS (a constant text, never a message
 * taken from WooCommerce or another plugin), so create_product may show it to the client. `leftoverId` is the id of a product the
 * writer inserted but could not clean up again, or null when nothing is known to be left behind.
 */
final class ProductSaveFailed extends \RuntimeException
{
    public function __construct( string $message, public readonly ?int $leftoverId = null )
    {
        parent::__construct( $message );
    }
}
