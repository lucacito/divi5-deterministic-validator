<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Divi's own default look showing through where a design was meant (a grey image placeholder, a stock blue button, a pale tab
 * bar). Not built yet: each default must come from a real render on the real Divi, and that evidence is not in the repository
 * (docs/companion-plan.md, question 1). Until it is, this check says so instead of passing.
 */
final class DefaultFallbacks
{
    /** @return array<string,mixed> */
    public static function check( string $content ): array
    {
        return [
            'status' => 'not_checked',
            'reason' => 'Divi\'s default fallbacks (grey image placeholders, stock blue buttons, pale tab bars) are not checked yet.',
        ];
    }
}
