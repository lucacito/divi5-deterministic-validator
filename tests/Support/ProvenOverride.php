<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

use AiEditorDivi5\WP\DiviMarkup;

/**
 * Runs a callable with other STYLE_PROVEN decisions in DiviMarkup (dev/test-side only). The plugin has no hook for this: the private
 * static override is set through reflection and always restored, so a false decision can be planted without a switch in shipped code.
 */
final class ProvenOverride
{
    /**
     * @param array<string,bool> $proven exactly the STYLE_PROVEN keys, in order
     */
    public static function run( array $proven, callable $fn ): mixed
    {
        if ( array_keys( $proven ) !== array_keys( DiviMarkup::STYLE_PROVEN ) ) {
            throw new \InvalidArgumentException( 'ProvenOverride takes exactly the STYLE_PROVEN keys, in order' );
        }
        $property = new \ReflectionProperty( DiviMarkup::class, 'provenOverride' );
        $before   = $property->getValue();
        $property->setValue( null, $proven );
        try {
            return $fn();
        } finally {
            $property->setValue( null, $before );
        }
    }
}
