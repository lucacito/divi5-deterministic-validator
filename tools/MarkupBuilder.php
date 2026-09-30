<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

/**
 * Builds the minimal Divi 5 block markup used to probe a module: the same shape
 * as the real-export fixture fixtures/valid/woo-shop-section.json.
 */
final class MarkupBuilder
{
    public const PLACEMENT_COLUMN  = 'column';
    public const PLACEMENT_SECTION = 'section';

    public static function page(string $module, string $placement, ?string $child = null, string $version = '5.14.0'): string
    {
        if (!in_array($placement, [self::PLACEMENT_COLUMN, self::PLACEMENT_SECTION], true)) {
            throw new \InvalidArgumentException("Unknown placement: {$placement}");
        }

        $attrs = '{"builderVersion":"' . $version . '"}';

        $block = $child === null
            ? sprintf('<!-- wp:%s %s /-->', $module, $attrs)
            : sprintf('<!-- wp:%1$s %2$s --><!-- wp:%3$s %2$s /--><!-- /wp:%1$s -->', $module, $attrs, $child);

        if ($placement === self::PLACEMENT_COLUMN) {
            $column = '{"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"' . $version . '"}';
            $block  = sprintf(
                '<!-- wp:divi/row %1$s --><!-- wp:divi/column %2$s -->%3$s<!-- /wp:divi/column --><!-- /wp:divi/row -->',
                $attrs,
                $column,
                $block
            );
        }

        return sprintf(
            '<!-- wp:divi/placeholder --><!-- wp:divi/section %1$s -->%2$s<!-- /wp:divi/section --><!-- /wp:divi/placeholder -->',
            $attrs,
            $block
        );
    }
}
