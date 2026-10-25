<?php
/**
 * Render-fatal sweep. Runs INSIDE WordPress on the DEV site only:
 *
 *   docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file \
 *     /scripts/sweep-render-fatals.php [out=/fixtures/shapes/render-fatals.json] [only=divi/x,divi/y] --user=1
 *   ... eval-file /scripts/sweep-render-fatals.php http-setup --user=1    (publishes the calibration pages, prints ids)
 *   ... eval-file /scripts/sweep-render-fatals.php http-case=divi/heading:title:object,... --user=1
 *       (publishes the evidence file's baseline for each case, mutated, plus the clean baseline; prints ids
 *        to fetch from the host: curl -s -o /dev/null -w '%{http_code}' 'http://localhost:8181/?page_id=ID')
 *   ... eval-file /scripts/sweep-render-fatals.php cleanup --user=1       (deletes every "aied sweep " page)
 *
 * For every Divi module, the content keys and their shape (object or scalar) come from Divi's own
 * definition files (scripts/sweep-module-keys.php, read from the active theme). A BASELINE is a real
 * block of that module taken from fixtures/valid/*.json (real builder exports) or from the served
 * section recipes, wrapped in its real ancestors only (siblings dropped). It must render clean in
 * process first. Then each content key is mutated (`<key>.innerContent.desktop.value` set to the
 * string "sweep-x", the object {"text":"sweep-x"}, null, []) and rendered again through the_content
 * inside the post context of ONE throwaway draft page (titled "aied sweep <token> ..." and always
 * deleted in a finally). A Throwable is a `fatal` (on the front end Divi answers HTTP 500), a new PHP
 * diagnostic (not seen in the baseline render) is `php-notice/warning`, anything else is `ok`.
 * After every fatal the baseline is rendered again to prove the harness state is still sound.
 * Nothing is invented: a module with no real block is reported `no-baseline`.
 */

require_once '/scripts/sweep-module-keys.php';

if (!function_exists('parse_blocks')) {
    WP_CLI::error('Run through wp eval-file.');
}

$mode = 'sweep';
$outFile = '/fixtures/shapes/render-fatals.json';
$only = null;
foreach ((array) ($args ?? []) as $a) {
    if ($a === 'http-setup' || $a === 'cleanup') {
        $mode = $a;
    } elseif (str_starts_with($a, 'http-case=')) {
        $mode = 'http-case';
        $httpCases = array_filter(explode(',', substr($a, 10)));
    } elseif (str_starts_with($a, 'out=')) {
        $outFile = substr($a, 4);
    } elseif (str_starts_with($a, 'only=')) {
        $only = array_filter(explode(',', substr($a, 5)));
    }
}

const SWEEP_TITLE = 'aied sweep ';
const SWEEP_STRING = 'sweep-x';

$cleanup = function (): int {
    $ids = get_posts([
        'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids',
        's' => trim(SWEEP_TITLE), 'suppress_filters' => true,
    ]);
    $n = 0;
    foreach ($ids as $id) {
        if (str_starts_with((string) get_the_title($id), SWEEP_TITLE)) {
            wp_delete_post((int) $id, true);
            $n++;
        }
    }

    return $n;
};

$token = substr(md5((string) microtime(true) . wp_rand()), 0, 8);

$newPage = function (string $label, string $content, string $status) use ($token): int {
    $id = wp_insert_post([
        'post_type' => 'page', 'post_status' => $status,
        'post_title' => SWEEP_TITLE . $token . ' ' . $label,
        'post_content' => wp_slash($content),
        'meta_input' => ['_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on'],
    ], true);
    if (is_wp_error($id) || !$id) {
        throw new RuntimeException('could not create the throwaway page');
    }

    return (int) $id;
};

/* ---------- baselines from real blocks ---------- */

/** @return list<array{source:string, markup:string}> */
$sources = function (): array {
    $out = [];
    $files = glob('/fixtures/valid/*.json') ?: [];
    sort($files);
    foreach ($files as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (is_array($d) && is_string($d['post_content'] ?? null)) {
            $out[] = ['source' => 'fixtures/valid/' . basename($f), 'markup' => $d['post_content']];
        }
    }
    if (class_exists('AiEditorDivi5\\WP\\SectionRecipes')) {
        $names = \AiEditorDivi5\WP\SectionRecipes::names();
        sort($names);
        foreach ($names as $name) {
            $m = \AiEditorDivi5\WP\SectionRecipes::recipe($name);
            if (is_string($m)) {
                $out[] = ['source' => 'recipe:' . $name, 'markup' => $m];
            }
        }
    }

    return $out;
};

/** Strips a block to a single path: the ancestor keeps its attrs, its only child is $child. */
$wrap = function (array $ancestor, array $child): array {
    $ancestor['innerBlocks'] = [$child];
    $ancestor['innerHTML'] = '';
    $ancestor['innerContent'] = [null];

    return $ancestor;
};

/** @return array<string, list<array{source:string, path:string, ancestors:list<array>, block:array}>> */
$collect = function (array $sources): array {
    $found = [];
    $walk = function (array $blocks, array $ancestors, string $source, string $path) use (&$walk, &$found): void {
        foreach ($blocks as $i => $b) {
            $name = $b['blockName'] ?? null;
            if (!is_string($name)) {
                continue;
            }
            $p = $path . '/' . $i;
            if (str_starts_with($name, 'divi/')) {
                $found[$name][] = ['source' => $source, 'path' => $p, 'ancestors' => $ancestors, 'block' => $b];
            }
            if (!empty($b['innerBlocks'])) {
                $walk($b['innerBlocks'], array_merge($ancestors, [$b]), $source, $p);
            }
        }
    };
    foreach ($sources as $s) {
        $walk(parse_blocks($s['markup']), [], $s['source'], '');
    }
    ksort($found);

    return $found;
};

$assemble = function (array $ancestors, array $block) use ($wrap): string {
    $node = $block;
    for ($i = count($ancestors) - 1; $i >= 0; $i--) {
        $node = $wrap($ancestors[$i], $node);
    }

    return serialize_blocks([$node]);
};

/* ---------- in-process render ---------- */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('ignore_repeated_errors', '0');

$diagnostics = [];
set_error_handler(function (int $no, string $msg, string $file = '', int $line = 0) use (&$diagnostics): bool {
    if (!(error_reporting() & $no)) {
        return true; // @-suppressed
    }
    $label = match ($no) {
        E_WARNING, E_USER_WARNING => 'Warning',
        E_NOTICE, E_USER_NOTICE => 'Notice',
        E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
        default => 'E' . $no,
    };
    $file = preg_replace('#^/var/www/html/#', '', $file);
    $diagnostics[$label . ': ' . substr($msg, 0, 200) . ' (' . $file . ':' . $line . ')'] = true;

    return true;
});

$pageId = 0;

/** @return array{result:string, error:?string, diagnostics:list<string>, html_bytes:int} */
$render = function (string $markup) use (&$diagnostics, &$pageId): array {
    $diagnostics = [];
    $post = get_post($pageId);
    $post->post_content = $markup;
    $GLOBALS['post'] = $post;
    setup_postdata($post);
    $level = ob_get_level();
    ob_start();
    $error = null;
    $html = '';
    try {
        $html = (string) apply_filters('the_content', $markup);
    } catch (\Throwable $e) {
        $error = get_class($e) . ': ' . substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200);
    } finally {
        $printed = '';
        while (ob_get_level() > $level) {
            $printed = (string) ob_get_clean() . $printed;
        }
        wp_reset_postdata();
    }
    $diag = array_keys($diagnostics);
    sort($diag);

    return [
        'result' => $error !== null ? 'fatal' : ($diag !== [] ? 'php-notice/warning' : 'ok'),
        'error' => $error,
        'diagnostics' => $diag,
        'html_bytes' => strlen($html),
        'html' => $html,
    ];
};

$setKey = function (array $block, string $key, $value): array {
    $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
    if (!is_array($attrs[$key] ?? null)) {
        $attrs[$key] = [];
    }
    if (!is_array($attrs[$key]['innerContent'] ?? null)) {
        $attrs[$key]['innerContent'] = [];
    }
    if (!is_array($attrs[$key]['innerContent']['desktop'] ?? null)) {
        $attrs[$key]['innerContent']['desktop'] = [];
    }
    $attrs[$key]['innerContent']['desktop']['value'] = $value;
    $block['attrs'] = $attrs;

    return $block;
};

/* ---------- modes ---------- */

if ($mode === 'cleanup') {
    WP_CLI::log('deleted ' . $cleanup() . ' page(s)');

    return;
}

// The two calibration cases from the blind page-building run, both from real blocks.
$calibrationBlocks = function () use ($sources, $collect): array {
    $found = $collect($sources());
    $t = $found['divi/testimonial'][0] ?? null;
    if ($t === null) {
        throw new RuntimeException('no real testimonial block');
    }

    return $t;
};

if ($mode === 'http-case') {
    $cleanup();
    $evidence = json_decode((string) file_get_contents($outFile), true);
    $found = $collect($sources());
    $ids = [];
    foreach ($httpCases as $case) {
        [$block, $key, $kind] = explode(':', $case) + [null, null, null];
        $src = $evidence['baselines'][$block]['source'] ?? null;
        $cand = null;
        foreach ($found[$block] ?? [] as $c) {
            if ($c['source'] . $c['path'] === $src) {
                $cand = $c;
            }
        }
        if ($cand === null) {
            WP_CLI::warning("no baseline for $case");
            continue;
        }
        $value = $kind === 'string' ? SWEEP_STRING : ['text' => SWEEP_STRING];
        $ids[$case] = $newPage('http ' . $case, $assemble($cand['ancestors'], $setKey($cand['block'], $key, $value)), 'publish');
        $ids[$block . ':clean'] = $newPage('http ' . $block . ' clean', $assemble($cand['ancestors'], $cand['block']), 'publish');
    }
    WP_CLI::log(json_encode($ids, JSON_UNESCAPED_SLASHES));

    return;
}

if ($mode === 'http-setup') {
    $cleanup();
    $t = $calibrationBlocks();
    $clean = $assemble($t['ancestors'], $t['block']);
    $bad = $assemble($t['ancestors'], $setKey($t['block'], 'company', 'Corner Table Cafe'));
    $a = $newPage('http clean', $clean, 'publish');
    $b = $newPage('http company-string', $bad, 'publish');
    $c = $newPage('http author-object', $assemble($t['ancestors'], $setKey($t['block'], 'author', ['text' => SWEEP_STRING])), 'publish');
    WP_CLI::log(json_encode(['clean' => $a, 'company_string' => $b, 'author_object' => $c, 'source' => $t['source'] . $t['path']]));

    return;
}

$cleanup();
$derived = sweep_derive_all(get_template_directory() . '/includes/builder-5');
$modules = $derived['modules'];
$rules = sweep_load_rules('/validator-src');
$entries = [];
$baselines = [];
$calibration = [];

try {
    $pageId = $newPage('render host', '', 'draft');
    $found = $collect($sources());

    // Calibration BEFORE trusting anything: the known clean render and the known crash.
    $t = $found['divi/testimonial'][0] ?? null;
    if ($t === null) {
        throw new RuntimeException('calibration: no real testimonial block');
    }
    $c1 = $render($assemble($t['ancestors'], $t['block']));
    $c2 = $render($assemble($t['ancestors'], $setKey($t['block'], 'company', 'Corner Table Cafe')));
    $c3 = $render($assemble($t['ancestors'], $t['block']));
    $calibration = [
        'source' => $t['source'] . $t['path'],
        'clean' => $c1['result'], 'company_string' => $c2['result'], 'company_string_error' => $c2['error'],
        'clean_again' => $c3['result'],
    ];
    if ($c1['result'] !== 'ok' || $c2['result'] !== 'fatal' || $c3['result'] !== 'ok'
        || !str_contains((string) $c2['error'], 'ArrayUtility::get_value')) {
        throw new RuntimeException('calibration failed: ' . json_encode($calibration));
    }
    WP_CLI::log('calibration ok: ' . $c2['error']);

    foreach ($modules as $block => $keys) {
        if ($only !== null && !in_array($block, $only, true)) {
            continue;
        }
        if ($keys === []) {
            continue; // no innerContent key: nothing to sweep
        }
        // Baseline: the real block (fixtures first, then recipes) that carries the most content keys
        // and renders clean. Up to 6 tried.
        $cands = $found[$block] ?? [];
        usort($cands, function ($a, $b) use ($keys) {
            $na = count(array_intersect(array_keys($keys), array_keys((array) ($a['block']['attrs'] ?? []))));
            $nb = count(array_intersect(array_keys($keys), array_keys((array) ($b['block']['attrs'] ?? []))));

            return [$nb, $a['source'], $a['path']] <=> [$na, $b['source'], $b['path']];
        });
        $base = null;
        $tried = [];
        foreach (array_slice($cands, 0, 6) as $cand) {
            $r = $render($assemble($cand['ancestors'], $cand['block']));
            $tried[] = $cand['source'] . $cand['path'] . ' => ' . $r['result'] . ($r['error'] ? ' ' . $r['error'] : '');
            if ($r['result'] !== 'fatal' && $r['html_bytes'] > 0) {
                $base = $cand + ['render' => $r];
                break;
            }
        }
        if ($base === null) {
            $status = $cands === [] ? 'no-baseline' : 'baseline-failed';
            $baselines[$block] = ['status' => $status, 'tried' => $tried];
            foreach ($keys as $key => $c) {
                $entries[] = ['module' => $block, 'key' => $key, 'shape' => $c['verdict'], 'direction' => 'all', 'result' => $status];
            }
            continue;
        }
        $baseDiag = $base['render']['diagnostics'];
        // The module's own class must be in the baseline render (same marker rule as tools/RenderEvidence.php),
        // otherwise an `ok` mutation would only prove that nothing rendered.
        $marker = 'et_pb_' . str_replace('-', '_', substr($block, 5));
        $baselines[$block] = [
            'status' => $base['render']['result'] === 'ok' ? 'ok' : 'ok-with-diagnostics',
            'marker_seen' => str_contains($base['render']['html'], $marker),
            'source' => $base['source'] . $base['path'],
            'keys_present' => array_values(array_intersect(array_keys($keys), array_keys((array) ($base['block']['attrs'] ?? [])))),
        ] + ($baseDiag === [] ? [] : ['diagnostics' => $baseDiag]);

        foreach ($keys as $key => $c) {
            $shape = $c['verdict'];
            $mutations = [
                'string' => SWEEP_STRING,
                'object' => ['text' => SWEEP_STRING],
                'null' => null,
                'array' => [],
            ];
            foreach ($mutations as $kind => $value) {
                $direction = match (true) {
                    $kind === 'null' => 'null-value',
                    $kind === 'array' => 'array-value',
                    $shape === 'object' && $kind === 'string' => 'scalar-where-object',
                    $shape === 'scalar' && $kind === 'object' => 'object-where-scalar',
                    $shape === 'object' && $kind === 'object' => 'control-object-where-object',
                    $shape === 'scalar' && $kind === 'string' => 'control-string-where-scalar',
                    default => $kind . '-where-' . $shape,
                };
                $r = $render($assemble($base['ancestors'], $setKey($base['block'], $key, $value)));
                $new = array_values(array_diff($r['diagnostics'], $baseDiag));
                $result = $r['result'] === 'fatal' ? 'fatal' : ($new !== [] ? 'php-notice/warning' : 'ok');
                $e = ['module' => $block, 'key' => $key, 'shape' => $shape, 'direction' => $direction, 'result' => $result];
                if ($result === 'fatal') {
                    $e['error'] = $r['error'];
                    $again = $render($assemble($base['ancestors'], $base['block']));
                    $e['baseline_after'] = $again['result'] === 'fatal' ? 'fatal' : 'clean';
                    if ($again['result'] === 'fatal') {
                        throw new RuntimeException("harness state broken after $block $key $kind: " . $again['error']);
                    }
                } elseif ($result === 'php-notice/warning') {
                    $e['diagnostics'] = $new;
                }
                if ($shape === 'object' && $direction === 'scalar-where-object') {
                    $guard = false;
                    foreach ($rules[$block] ?? [] as [$k, , $mustBeObject]) {
                        $guard = $guard || ($k === $key && $mustBeObject);
                    }
                    $e['validator_guards'] = $guard;
                }
                $entries[] = $e;
            }
        }
        WP_CLI::log($block . ' done (' . $baselines[$block]['source'] . ')');
    }
} finally {
    if ($pageId) {
        wp_delete_post($pageId, true);
    }
    $left = $cleanup();
    if ($left) {
        WP_CLI::log("cleanup removed $left leftover page(s)");
    }
}

usort($entries, fn ($a, $b) => [$a['module'], $a['key'], $a['direction']] <=> [$b['module'], $b['key'], $b['direction']]);
ksort($baselines);
$counts = [];
foreach ($entries as $e) {
    $counts[$e['direction']][$e['result']] = ($counts[$e['direction']][$e['result']] ?? 0) + 1;
}
ksort($counts);
foreach ($counts as &$c) {
    ksort($c);
}
unset($c);

$doc = [
    'what' => 'Render-fatal sweep: every innerContent key of every Divi module (shape from Divi\'s own definitions) mutated on a real baseline block and rendered in process through the_content. Generated by scripts/sweep-render-fatals.php; see docs/render-fatal-sweep.md.',
    'divi_version' => (string) wp_get_theme(get_template())->get('Version'),
    'date' => gmdate('Y-m-d'),
    'calibration' => $calibration,
    'compound_field_components' => $derived['compound_components'],
    'counts' => $counts,
    'baselines' => $baselines,
    'entries' => $entries,
];
if ($only === null) {
    file_put_contents($outFile, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    WP_CLI::success('wrote ' . $outFile . ' (' . count($entries) . ' entries)');
} else {
    WP_CLI::log(json_encode(['counts' => $counts, 'baselines' => $baselines, 'entries' => array_values(array_filter($entries, fn ($e) => $e['result'] !== 'ok'))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
