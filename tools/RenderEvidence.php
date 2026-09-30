<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

/** Decides, from what Divi rendered, whether a module is proven. Pure. */
final class RenderEvidence
{
    private const ERROR_PATTERN = '/(Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:)/i';

    /** @return array{status:string, reasons:list<string>} */
    public static function classify(string $html, string $noise, string $module): array
    {
        $reasons = [];

        if (preg_match(self::ERROR_PATTERN, $html . "\n" . $noise, $m) === 1) {
            return ['status' => 'fail', 'reasons' => ['PHP diagnostic in output: ' . $m[1]]];
        }

        if (trim($html) === '') {
            return ['status' => 'needs-real-export', 'reasons' => ['render produced no output']];
        }

        foreach (self::markers($module) as $marker) {
            if (stripos($html, $marker) !== false) {
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
