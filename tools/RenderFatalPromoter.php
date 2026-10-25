<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

/**
 * Turns the render-fatal sweep (fixtures/shapes/render-fatals.json, scripts/sweep-render-fatals.php) into the tables of
 * src/VerifiedContentTypes.php. Only a LIVE-PROVEN fatal is promoted: a key Divi's definitions call an object or a
 * scalar but that rendered without a throw stays unguarded, so no layout Divi renders is refused because of this file.
 *
 *   OBJECT_KEYS: module => keys whose `scalar-where-object` result is `fatal` (a string where Divi needs an object).
 *   SCALAR_KEYS: module => keys whose `object-where-scalar` or `array-value` result is `fatal` (an object or an empty
 *                array where Divi needs a plain string or HTML).
 */
final class RenderFatalPromoter
{
    public const EVIDENCE = 'fixtures/shapes/render-fatals.json';

    /**
     * @param array<string,mixed> $evidence the decoded evidence file
     * @return array{object:array<string,list<string>>, scalar:array<string,list<string>>, version:string, date:string}
     */
    public static function promote(array $evidence): array
    {
        $version = $evidence['divi_version'] ?? null;
        $date    = $evidence['date'] ?? null;
        if (!is_string($version) || preg_match('/^\d+\.\d+(\.\d+)?\z/', $version) !== 1
            || !is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            throw new \UnexpectedValueException('The evidence needs a "divi_version" (x.y.z) and a "date" (YYYY-MM-DD).');
        }
        // The sweep only trusts itself after its calibration case behaved as the HTTP check did (docs/render-fatal-sweep.md).
        $cal = $evidence['calibration'] ?? [];
        if (!is_array($cal) || ($cal['clean'] ?? null) !== 'ok' || ($cal['company_string'] ?? null) !== 'fatal' || ($cal['clean_again'] ?? null) !== 'ok') {
            throw new \UnexpectedValueException('The evidence is not calibrated (clean ok, company string fatal, clean again ok).');
        }
        $entries = $evidence['entries'] ?? null;
        if (!is_array($entries) || $entries === []) {
            throw new \UnexpectedValueException('The evidence has no entries.');
        }

        $object = [];
        $scalar = [];
        foreach ($entries as $e) {
            if (!is_array($e) || ($e['result'] ?? null) !== 'fatal') {
                continue;
            }
            $module = (string) ($e['module'] ?? '');
            $key    = (string) ($e['key'] ?? '');
            self::assertName($module, $key);
            if (($e['baseline_after'] ?? null) !== 'clean') {
                throw new \UnexpectedValueException("$module $key: a fatal whose baseline did not render clean again cannot be trusted.");
            }
            $direction = $e['direction'] ?? null;
            if ($direction === 'scalar-where-object') {
                $object[$module][] = $key;
            } elseif ($direction === 'object-where-scalar' || $direction === 'array-value') {
                if ($direction === 'array-value' && ($e['shape'] ?? null) !== 'scalar') {
                    throw new \UnexpectedValueException("$module $key: an empty-array fatal on a key that is not a scalar is not a string guard.");
                }
                $scalar[$module][] = $key;
            } else {
                throw new \UnexpectedValueException("$module $key: a fatal in direction " . var_export($direction, true) . ' has no guard.');
            }
        }

        foreach ([&$object, &$scalar] as &$map) {
            foreach ($map as $module => $keys) {
                $keys = array_values(array_unique($keys));
                sort($keys);
                $map[$module] = $keys;
            }
            ksort($map);
        }
        unset($map);

        foreach ($object as $module => $keys) {
            $both = array_intersect($keys, $scalar[$module] ?? []);
            if ($both !== []) {
                throw new \UnexpectedValueException("$module: " . implode(', ', $both) . ' fatal both as a string and as an object.');
            }
        }

        return ['object' => $object, 'scalar' => $scalar, 'version' => $version, 'date' => $date];
    }

    /** Names end up in generated PHP source, so they must be plain slugs and attribute names. */
    private static function assertName(string $module, string $key): void
    {
        if (preg_match('#^divi/[a-z0-9-]+\z#', $module) !== 1 || preg_match('/^[A-Za-z][A-Za-z0-9]*\z/', $key) !== 1) {
            throw new \UnexpectedValueException('Invalid module or key: ' . var_export($module, true) . ' ' . var_export($key, true));
        }
    }

    /** @param array{object:array<string,list<string>>, scalar:array<string,list<string>>, version:string, date:string} $sets */
    public static function render(array $sets): string
    {
        $map = function (array $items): string {
            if ($items === []) {
                return '[]';
            }
            $out = "[\n";
            foreach ($items as $module => $keys) {
                foreach ($keys as $k) {
                    self::assertName((string) $module, (string) $k);
                }
                $out .= '        ' . var_export($module, true) . ' => [' . implode(', ', array_map(fn ($x) => var_export($x, true), $keys)) . "],\n";
            }

            return $out . '    ]';
        };
        $count = fn (array $m): int => array_sum(array_map('count', $m));

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Divi5Validator;\n\n"
            . "/**\n * GENERATED by scripts/promote-render-fatals.php from " . self::EVIDENCE . "\n"
            . ' * (Divi ' . $sets['version'] . ', ' . $sets['date'] . "). Do not hand-edit: every entry is a live-proven render fatal.\n"
            . " *\n"
            . " * OBJECT_KEYS: a plain string in <key>.innerContent.desktop.value threw while Divi rendered the module (HTTP 500).\n"
            . " * SCALAR_KEYS: an object or an empty array there threw; the value must be a plain string or HTML.\n"
            . " * Keys that rendered without a throw are not listed (nothing is guarded on Divi's definitions alone).\n"
            . ' * ' . $count($sets['object']) . ' object keys in ' . count($sets['object']) . ' modules, '
            . $count($sets['scalar']) . ' scalar keys in ' . count($sets['scalar']) . " modules.\n */\n"
            . "final class VerifiedContentTypes\n{\n"
            . '    public const DIVI_VERSION = ' . var_export($sets['version'], true) . ";\n\n"
            . '    public const CALIBRATED_ON = ' . var_export('Divi ' . $sets['version'] . ', ' . $sets['date'], true) . ";\n\n"
            . '    public const OBJECT_KEYS = ' . $map($sets['object']) . ";\n\n"
            . '    public const SCALAR_KEYS = ' . $map($sets['scalar']) . ";\n}\n";
    }
}
