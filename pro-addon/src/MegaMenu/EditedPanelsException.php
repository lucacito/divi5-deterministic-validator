<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** An update would replace a panel the owner edited in the Divi builder, and the owner has not said what to do with it. Nothing was changed. */
final class EditedPanelsException extends \RuntimeException
{
    /** @param array<int,string> $panels menu item id => the item's title */
    public function __construct( public readonly array $panels )
    {
        parent::__construct( 'You changed ' . self::names( $panels ) . ' in the Divi builder. Choose whether to keep your changes or replace them, then update again.' );
    }

    /** @param array<int,string> $panels */
    public static function names( array $panels ): string
    {
        $names = array_map( static fn ( string $t ): string => '"' . $t . '"', array_values( $panels ) );

        return 1 === count( $names ) ? 'the panel of ' . $names[0] : 'the panels of ' . implode( ', ', array_slice( $names, 0, -1 ) ) . ' and ' . end( $names );
    }
}
