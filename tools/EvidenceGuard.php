<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

/** Decides whether a new evidence document would lose proof the committed one holds. Pure. */
final class EvidenceGuard
{
    /**
     * @param array<string,mixed> $old decoded committed evidence (has a `results` map keyed by module name)
     * @param array<string,mixed> $new decoded candidate evidence
     * @return array{missing:list<string>,demoted:list<string>}
     *   missing: modules in $old absent from $new; demoted: modules that were `pass` and no longer are
     */
    public static function lostModules(array $old, array $new): array
    {
        $oldResults = self::results($old);
        $newResults = self::results($new);

        $missing = [];
        $demoted = [];
        foreach ($oldResults as $name => $result) {
            $name = (string) $name;
            if (!array_key_exists($name, $newResults)) {
                $missing[] = $name;
                continue;
            }
            if (self::status($result) === 'pass' && self::status($newResults[$name]) !== 'pass') {
                $demoted[] = $name;
            }
        }
        sort($missing);
        sort($demoted);

        return ['missing' => $missing, 'demoted' => $demoted];
    }

    /** @return array<string,mixed> */
    private static function results(array $doc): array
    {
        return is_array($doc['results'] ?? null) ? $doc['results'] : [];
    }

    private static function status(mixed $result): ?string
    {
        return is_array($result) && is_string($result['status'] ?? null) ? $result['status'] : null;
    }
}
