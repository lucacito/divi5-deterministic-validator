<?php
/**
 * Pure derivation (no WordPress, no network, no AI): which content keys of each Divi 5 module are
 * OBJECT-valued and which are SCALAR, read from Divi's OWN generated definition files.
 *
 *   php scripts/sweep-module-keys.php <builder-5 json root> [--json] [--src=<validator src dir>]
 *
 * The root is a copy of the theme's includes/builder-5 tree (or any directory holding the
 * per-module folders); it is searched recursively for module.json. Copy it out of the dev site with
 *   docker exec divi5val_wp sh -c 'cd /var/www/html/wp-content/themes/Divi/includes/builder-5 && \
 *     find . -name "*.json" -path "*components*" | tar cf - -T -' | tar xf - -C <dir>
 *
 * Three Divi-owned sources per module folder, all generated files Divi ships:
 *  1. module.json: every object (anywhere in the file) with `attrName: "<key>.innerContent"`. A
 *     `subName` means `<key>.innerContent.<bp>.value` is an object with that sub key; an item without
 *     `subName` means the value itself is the field (a scalar).
 *  2. conversion-outline.json (Divi 4 -> 5 map): `"<key>.innerContent.*"` = scalar,
 *     `"<key>.innerContent.*.<sub>"` = object with `<sub>`.
 *  3. module-default-render-attributes.json: a default `<key>.innerContent.desktop.value` that is an
 *     object (its keys are subs) or a scalar.
 * A key is listed when its attribute definition has `settings.innerContent` or any source names
 * `<key>.innerContent`. Verdict: `object` when every source that speaks says object, `scalar` when
 * every source says scalar, `mixed` when sources disagree (reported, never guessed),
 * `unclassified` when no source says anything (e.g. `settings.innerContent: {}` with no item).
 *
 * Used as a library by scripts/sweep-render-fatals.php (functions only; main() runs from the CLI).
 */

/** @return array<string, array{dir:string, files:array<string,string>}> block name => folder + files */
function sweep_find_modules(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $dirs = [];
    foreach ($it as $file) {
        if ($file->getFilename() === 'module.json') {
            $dirs[] = $file->getPath();
        }
    }
    sort($dirs);
    foreach ($dirs as $dir) {
        $def = json_decode((string) file_get_contents($dir . '/module.json'), true);
        if (!is_array($def) || !is_string($def['name'] ?? null)) {
            continue;
        }
        $name = $def['name'];
        if (isset($out[$name])) {
            continue; // a duplicate definition (e.g. a copy in another package): first, by sorted path, wins
        }
        $files = ['module.json' => $dir . '/module.json'];
        foreach (['conversion-outline.json', 'module-default-render-attributes.json'] as $f) {
            if (is_file($dir . '/' . $f)) {
                $files[$f] = $dir . '/' . $f;
            }
        }
        $out[$name] = ['dir' => $dir, 'files' => $files];
    }
    ksort($out);

    return $out;
}

/** Collects every array node that has attrName (string) anywhere in $node. */
function sweep_attr_items($node, array &$found): void
{
    if (!is_array($node)) {
        return;
    }
    if (isset($node['attrName']) && is_string($node['attrName'])) {
        $found[] = $node;
    }
    foreach ($node as $v) {
        if (is_array($v)) {
            sweep_attr_items($v, $found);
        }
    }
}

/** Collects every string leaf of $node as [its own key, value]. */
function sweep_string_leaves($node, array &$found, string $key = ''): void
{
    if (is_string($node)) {
        $found[] = [$key, $node];

        return;
    }
    if (is_array($node)) {
        foreach ($node as $k => $v) {
            if ($k === '_comment' || $k === 'valueExpansionFunctionMap') {
                continue;
            }
            sweep_string_leaves($v, $found, is_string($k) ? $k : $key);
        }
    }
}

/**
 * @return array<string, array{verdict:string, subs:list<string>, evidence:array<string,array{says:list<string>,subs:list<string>}>}>
 */
function sweep_classify_module(array $files): array
{
    $def = json_decode((string) file_get_contents($files['module.json']), true);
    $keys = [];
    $say = function (string $key, string $source, string $kind, ?string $sub) use (&$keys): void {
        $keys[$key]['evidence'][$source]['says'][$kind] = true;
        if ($sub !== null && $sub !== '') {
            $keys[$key]['evidence'][$source]['subs'][$sub] = true;
        }
    };
    foreach ((array) ($def['attributes'] ?? []) as $key => $attr) {
        if (is_array($attr) && is_array($attr['settings'] ?? null) && array_key_exists('innerContent', $attr['settings'])) {
            $keys[$key] ??= ['evidence' => []];
        }
    }

    // 1. module.json attrName items.
    $items = [];
    sweep_attr_items($def, $items);
    foreach ($items as $item) {
        if (preg_match('/^([A-Za-z0-9_]+)\.innerContent$/', $item['attrName'], $m) !== 1) {
            continue;
        }
        $sub = isset($item['subName']) && is_string($item['subName']) ? $item['subName'] : null;
        if ($sub !== null) {
            $say($m[1], 'module.json', 'object', $sub);
            continue;
        }
        $component = is_array($item['component'] ?? null) ? $item['component'] : null;
        if ($component === null) {
            continue; // a component's props block that repeats attrName, not a field
        }
        if (($component['type'] ?? null) === 'group') {
            // A group component (divi/button-link, divi/link ...) edits several sub keys of one object.
            $say($m[1], 'module.json', 'object', null);
            continue;
        }
        // A field without subName: the field's component writes the whole value. Recorded with its
        // component name; sweep_derive() decides from the data whether that component writes an object.
        $keys[$m[1]]['fields'][(string) ($component['name'] ?? '?')] = true;
    }

    // 2. conversion-outline.json.
    if (isset($files['conversion-outline.json'])) {
        $outline = json_decode((string) file_get_contents($files['conversion-outline.json']), true);
        $expanded = is_array($outline['valueExpansionFunctionMap'] ?? null) ? $outline['valueExpansionFunctionMap'] : [];
        $leaves = [];
        sweep_string_leaves($outline, $leaves);
        foreach ($leaves as [$from, $path]) {
            if (preg_match('/^([A-Za-z0-9_]+)\.innerContent\.\*(?:\.([A-Za-z0-9_]+))?/', $path, $m) !== 1) {
                continue;
            }
            if (isset($expanded[$from])) {
                continue; // the Divi 4 value goes through a conversion function: the target shape is not stated
            }
            $sub = $m[2] ?? null;
            $say($m[1], 'conversion-outline.json', ($sub === null || $sub === '') ? 'scalar' : 'object', $sub);
        }
    }

    // 3. module-default-render-attributes.json.
    if (isset($files['module-default-render-attributes.json'])) {
        $defaults = json_decode((string) file_get_contents($files['module-default-render-attributes.json']), true);
        foreach ((array) $defaults as $key => $attr) {
            if (!is_array($attr) || !is_array($attr['innerContent'] ?? null)) {
                continue;
            }
            foreach ($attr['innerContent'] as $bp => $states) {
                if (!is_array($states) || !array_key_exists('value', $states)) {
                    continue;
                }
                $v = $states['value'];
                if (is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) {
                    foreach (array_keys($v) as $sub) {
                        $say((string) $key, 'module-default-render-attributes.json', 'object', (string) $sub);
                    }
                } elseif (is_scalar($v)) {
                    $say((string) $key, 'module-default-render-attributes.json', 'scalar', null);
                }
            }
        }
    }

    ksort($keys);

    return $keys;
}

/**
 * Field components (module.json items without subName) that write an OBJECT: a component is
 * compound when at least one key it edits is called an object by the other sources and no key it
 * edits is called a scalar by them (keys with no other evidence count neither way). On 5.15.1 that
 * is divi/map and divi/icon-picker. Derived from the data, never listed by hand.
 *
 * @return list<string>
 */
function sweep_compound_components(array $raw): array
{
    $verdict = [];
    foreach ($raw as $keys) {
        foreach ($keys as $info) {
            $kinds = [];
            foreach ($info['evidence'] ?? [] as $e) {
                foreach (array_keys($e['says'] ?? []) as $k) {
                    $kinds[$k] = true;
                }
            }
            $kinds = array_keys($kinds);
            sort($kinds);
            foreach (array_keys($info['fields'] ?? []) as $c) {
                $verdict[$c] ??= ['object' => false, 'scalar' => false];
                if (in_array('object', $kinds, true)) {
                    $verdict[$c]['object'] = true;
                }
                if (in_array('scalar', $kinds, true)) {
                    $verdict[$c]['scalar'] = true;
                }
            }
        }
    }
    $out = array_keys(array_filter($verdict, fn ($v) => $v['object'] && !$v['scalar']));
    sort($out);

    return $out;
}

function sweep_finalize(array $keys, array $compound): array
{
    $out = [];
    foreach ($keys as $key => $info) {
        foreach (array_keys($info['fields'] ?? []) as $c) {
            $info['evidence']['module.json']['says'][in_array($c, $compound, true) ? 'object' : 'scalar'] = true;
            $info['evidence']['module.json']['fields'][$c] = true;
        }
        $kinds = [];
        $subs = [];
        $evidence = [];
        foreach ($info['evidence'] ?? [] as $source => $e) {
            $says = array_keys($e['says'] ?? []);
            sort($says);
            $s = array_keys($e['subs'] ?? []);
            sort($s);
            $evidence[$source] = ['says' => $says, 'subs' => $s];
            if (isset($e['fields'])) {
                $f = array_keys($e['fields']);
                sort($f);
                $evidence[$source]['field_components'] = $f;
            }
            foreach ($says as $k) {
                $kinds[$k] = true;
            }
            foreach ($s as $x) {
                $subs[$x] = true;
            }
        }
        ksort($evidence);
        $kinds = array_keys($kinds);
        sort($kinds);
        $subs = array_keys($subs);
        sort($subs);
        $verdict = match (true) {
            $kinds === [] => 'unclassified',
            $kinds === ['object'] => 'object',
            $kinds === ['scalar'] => 'scalar',
            default => 'mixed',
        };
        $out[(string) $key] = ['verdict' => $verdict, 'subs' => $subs, 'evidence' => $evidence];
    }

    return $out;
}

/** @return array{modules: array<string, array<string, array>>, compound_components: list<string>} */
function sweep_derive_all(string $root): array
{
    $raw = [];
    foreach (sweep_find_modules($root) as $name => $m) {
        $raw[$name] = sweep_classify_module($m['files']);
    }
    $compound = sweep_compound_components($raw);
    $result = [];
    foreach ($raw as $name => $keys) {
        $result[$name] = sweep_finalize($keys, $compound);
    }

    return ['modules' => $result, 'compound_components' => $compound];
}

/** @return array<string, array<string, array>> block => key => classification */
function sweep_derive(string $root): array
{
    return sweep_derive_all($root)['modules'];
}

/**
 * Compares the derivation with the validator's contentKeyRules() ([key, required, mustBeObject]).
 * The validator only checks one direction (a scalar where an object is required), so an object key
 * is `guarded` when a rule with mustBeObject=true exists; a scalar key is never type-guarded.
 *
 * @return array{guarded:list<string>, missing:list<string>, rule_not_object_by_divi:list<string>}
 */
function sweep_guard_report(array $derived, array $rules): array
{
    $guarded = [];
    $missing = [];
    $odd = [];
    foreach ($derived as $block => $keys) {
        foreach ($keys as $key => $c) {
            if ($c['verdict'] !== 'object') {
                continue;
            }
            $isGuarded = false;
            foreach ($rules[$block] ?? [] as [$k, , $mustBeObject]) {
                if ($k === $key && $mustBeObject) {
                    $isGuarded = true;
                }
            }
            if ($isGuarded) {
                $guarded[] = "$block $key";
            } else {
                $missing[] = "$block $key";
            }
        }
    }
    foreach ($rules as $block => $list) {
        foreach ($list as [$k, , $mustBeObject]) {
            if ($mustBeObject && (($derived[$block][$k]['verdict'] ?? 'absent') !== 'object')) {
                $odd[] = "$block $k (" . ($derived[$block][$k]['verdict'] ?? 'absent') . ')';
            }
        }
    }

    return ['guarded' => $guarded, 'missing' => $missing, 'rule_not_object_by_divi' => $odd];
}

/** Loads the validator's rules from a src dir (host: <repo>/src, wpcli container: /validator-src). */
function sweep_load_rules(string $srcDir): array
{
    if (!class_exists('Divi5Validator\\SchemaRules', false)) {
        require_once $srcDir . '/VerifiedModules.php';
        require_once $srcDir . '/SchemaRules.php';
    }

    return (new Divi5Validator\SchemaRules())->contentKeyRules();
}

function sweep_keys_main(array $argv): int
{
    $root = null;
    $json = false;
    $src = dirname(__DIR__) . '/src';
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--json') {
            $json = true;
        } elseif (str_starts_with($a, '--src=')) {
            $src = substr($a, 6);
        } else {
            $root = $a;
        }
    }
    if ($root === null || !is_dir($root)) {
        fwrite(STDERR, "usage: php scripts/sweep-module-keys.php <builder-5 json root> [--json] [--src=<dir>]\n");

        return 2;
    }
    $all = sweep_derive_all($root);
    $derived = $all['modules'];
    $guard = sweep_guard_report($derived, sweep_load_rules($src));
    if ($json) {
        $modules = array_map(fn ($k) => $k === [] ? new stdClass() : array_map(function ($c) {
            $c['evidence'] = $c['evidence'] === [] ? new stdClass() : $c['evidence'];

            return $c;
        }, $k), $derived);
        echo json_encode(['modules' => $modules, 'compound_components' => $all['compound_components'], 'guard' => $guard], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

        return 0;
    }
    $counts = [];
    foreach ($derived as $block => $keys) {
        echo $block, "\n";
        foreach ($keys as $key => $c) {
            $counts[$c['verdict']] = ($counts[$c['verdict']] ?? 0) + 1;
            echo '  ', str_pad($key, 28), ' ', str_pad($c['verdict'], 12), $c['subs'] === [] ? '' : '{' . implode(',', $c['subs']) . '}', "\n";
        }
    }
    ksort($counts);
    echo "\ncompound field components (derived): ", implode(', ', $all['compound_components']), "\n";
    echo "modules: ", count($derived), '; keys: ', json_encode($counts), "\n";
    echo 'object keys guarded by contentKeyRules(): ', count($guard['guarded']), "\n";
    echo 'object keys NOT guarded: ', count($guard['missing']), "\n";
    foreach ($guard['missing'] as $m) {
        echo "  $m\n";
    }
    if ($guard['rule_not_object_by_divi'] !== []) {
        echo "rules with mustBeObject whose key Divi does not call object:\n";
        foreach ($guard['rule_not_object_by_divi'] as $m) {
            echo "  $m\n";
        }
    }

    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(sweep_keys_main($argv));
}
