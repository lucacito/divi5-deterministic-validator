<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

/** Decides, from what Divi rendered, whether a module is proven. Pure. */
final class RenderEvidence
{
    /** Detects PHP diagnostics including HTML-formatted and WP-CLI Error prefix. */
    private const ERROR_PATTERN = '/(Fatal error|Parse error|Uncaught|(?:Warning|Notice|Deprecated|Strict Standards)(?:<\/b>)?\s*:|(?:^|\n)Error:)/i';

    /** Structural wrapper types that cannot be proven by a probe page wrapped in themselves. */
    private const WRAPPER_TYPES = [
        'divi/section',
        'divi/row',
        'divi/column',
        'divi/row-inner',
        'divi/column-inner',
        'divi/placeholder',
    ];

    /** @return array{status:string, reasons:list<string>} */
    public static function classify(string $html, string $noise, string $module): array
    {
        $reasons = [];

        // Wrapper types cannot be proven by a probe page that is itself wrapped in them.
        if (in_array($module, self::WRAPPER_TYPES, true)) {
            return ['status' => 'needs-real-export', 'reasons' => ['structural wrapper: cannot be proven by a probe page that is itself wrapped in it']];
        }

        if (preg_match(self::ERROR_PATTERN, $html . "\n" . $noise, $m) === 1) {
            return ['status' => 'fail', 'reasons' => ['PHP diagnostic in output: ' . $m[1]]];
        }

        if (trim($html) === '') {
            return ['status' => 'needs-real-export', 'reasons' => ['render produced no output']];
        }

        foreach (self::markers($module) as $marker) {
            // Use boundary-aware regex matching to avoid prefix collisions.
            // Pattern matches the marker with optional numeric suffix (_0, _1, etc.) and no preceding/following identifier chars.
            $pattern = '/(?<![a-z0-9_])' . preg_quote($marker, '/') . '(?:_\d+)?(?![a-z0-9_])/i';
            if (preg_match($pattern, $html) === 1) {
                return ['status' => 'pass', 'reasons' => ['found marker ' . $marker]];
            }
        }

        $reasons[] = 'output had none of the expected module markers';

        return ['status' => 'needs-real-export', 'reasons' => $reasons];
    }

    /** @return list<string> */
    public static function markers(string $module): array
    {
        $slug       = preg_replace('#^divi/#', '', $module) ?? $module;
        $underscore = str_replace('-', '_', $slug);

        // Module-specific only. A bare slug such as "link" or "map" occurs in unrelated markup
        // and would produce false passes. Calibration (Task 3) may ADD further markers, but each
        // must contain the full module name.
        return ['et_pb_' . $underscore];
    }
}
