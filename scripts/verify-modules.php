<?php
/**
 * Runs INSIDE WordPress:  wp eval-file verify-modules.php <candidates.json> <out.json> <divi-version>
 * For each candidate module, renders minimal markup in each placement through the real
 * Divi, then classifies the evidence. Scratch pages are always deleted.
 */

use Divi5Validator\Tools\MarkupBuilder;
use Divi5Validator\Tools\RenderEvidence;

require_once '/tmp/divi5-tools/MarkupBuilder.php';
require_once '/tmp/divi5-tools/RenderEvidence.php';

[$candidatesFile, $outFile, $version] = $args;

// WP_DEBUG is off on the test site, and WordPress lowers error_reporting / disables display_errors
// while loading. Re-enable every diagnostic and route it to the output buffer the probes capture,
// otherwise a module emitting a notice or deprecation would be indistinguishable from a clean one.
// (A one-shot script: nothing is restored afterwards.) The calibration control below PROVES this works.
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('html_errors', '0');
// This PHP build has ignore_repeated_errors=1, which silently DROPS a diagnostic identical to the
// previous one (same message/file/line). Two probes hitting the same notice would then look clean.
ini_set('ignore_repeated_errors', '0');
ini_set('ignore_repeated_source', '0');
$candidates = json_decode((string) file_get_contents($candidatesFile), true);
if (!is_array($candidates)) {
    WP_CLI::error('candidates.json is not valid JSON');
}

/** Deletes every scratch page (any status) left by this or a previously aborted run. */
$sweep = function (): int {
    $ids = get_posts([
        'post_type'        => 'page',
        'post_status'      => 'any',
        'title'            => 'aied-verify',
        'numberposts'      => -1,
        'fields'           => 'ids',
        'suppress_filters' => true,
    ]);
    foreach ($ids as $stale) {
        wp_delete_post((int) $stale, true);
    }

    return count($ids);
};

$probe = function (string $module, ?string $child, string $placement, ?callable $inject = null) use ($version): array {
    $startLevel = ob_get_level();
    $id = 0;
    $html = '';
    $noise = '';
    try {
        $markup = MarkupBuilder::page($module, $placement, $child, $version);
        $id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_title'   => 'aied-verify',
            'post_content' => wp_slash($markup), // wp_slash: core strips backslashes otherwise
        ], true);
        if (is_wp_error($id) || !$id) {
            $why = is_wp_error($id) ? $id->get_error_message() : 'wp_insert_post returned 0';
            $id = 0;

            return ['status' => 'fail', 'reasons' => ['could not create scratch page: ' . $why], 'html_bytes' => 0, 'sample' => ''];
        }
        update_post_meta($id, '_et_pb_use_divi_5', 'on');
        update_post_meta($id, '_et_pb_use_builder', 'on');

        $post = get_post($id);
        $GLOBALS['post'] = $post;
        setup_postdata($post);
        ob_start();
        if ($inject !== null) {
            add_filter('the_content', $inject, 999); // calibration only: emulates a module emitting a diagnostic
        }
        try {
            $html = (string) apply_filters('the_content', $post->post_content);
        } catch (\Throwable $e) {
            $html = 'Fatal error: ' . $e->getMessage();
        } finally {
            if ($inject !== null) {
                remove_filter('the_content', $inject, 999);
            }
        }
        $noise = (string) ob_get_clean();
    } catch (\Throwable $e) {
        $html = 'Fatal error: ' . $e->getMessage();
    } finally {
        if ($id) {
            wp_delete_post((int) $id, true);
        }
        while (ob_get_level() > $startLevel) {
            ob_end_clean();
        }
        unset($GLOBALS['post']);
        wp_reset_postdata();
    }

    // Excerpt around the module's own marker so a reader can eyeball the real class attribute.
    $at = false;
    foreach (RenderEvidence::markers($module) as $marker) {
        $at = strpos($html, $marker);
        if ($at !== false) {
            break;
        }
    }
    $sample = substr($html, $at === false ? 0 : max(0, $at - 20), 400);

    return RenderEvidence::classify($html, $noise, $module) + ['html_bytes' => strlen($html), 'sample' => $sample];
};

$verify = function (array $p) use ($probe): array {
    $child = $p['children'][0] ?? null;
    $placements = [];
    foreach ([MarkupBuilder::PLACEMENT_COLUMN, MarkupBuilder::PLACEMENT_SECTION] as $pl) {
        $placements[$pl] = $probe($p['name'], $child, $pl);
    }
    $rendered = array_keys(array_filter($placements, fn ($r) => $r['status'] === 'pass'));
    $failed   = array_filter($placements, fn ($r) => $r['status'] === 'fail') !== [];
    if ($failed) {
        $status = 'fail'; // any failing placement (diagnostics) poisons the verdict
    } elseif ($rendered !== []) {
        $status = 'pass';
    } else {
        $status = 'needs-real-export';
    }

    return ['status' => $status, 'placements_rendered' => $rendered, 'placements' => $placements];
};

$placementNote = "placements_rendered records where a module's class rendered without diagnostics. "
    . 'Rendering cannot discriminate placement (the harness has no control for a placement Divi should reject), '
    . 'so this is NOT proof that the builder allows the placement.';

$sweep(); // a prior aborted run must never leave pages behind
try {
    // Controls prove the harness can tell real from unreal BEFORE we trust any result.
    $controls = [];
    foreach (['divi/heading', 'divi/blurb', 'divi/shop'] as $known) {
        $controls[$known] = $verify(['name' => $known, 'children' => []]);
    }
    $controls['divi/not-a-module'] = $verify(['name' => 'divi/not-a-module', 'children' => []]);

    // Diagnostics control: a render that emits a PHP diagnostic through the real the_content path
    // (same ob_start()/classify route every probe uses) MUST classify as 'fail'. If it does not,
    // the harness cannot see notices/warnings/deprecations and no 'pass' can be trusted.
    $diagnosticLevels = [
        'E_USER_WARNING'    => E_USER_WARNING,
        'E_USER_NOTICE'     => E_USER_NOTICE,
        'E_USER_DEPRECATED' => E_USER_DEPRECATED,
    ];
    foreach ($diagnosticLevels as $label => $level) {
        $inject = function ($content) use ($level) {
            trigger_error('aied-calibration', $level);

            return $content;
        };
        $result = $probe('divi/heading', null, MarkupBuilder::PLACEMENT_COLUMN, $inject);
        $controls['diagnostic:' . $label] = $result;
    }

    $bad = [];
    foreach (['divi/heading', 'divi/blurb', 'divi/shop'] as $known) {
        if (!in_array(MarkupBuilder::PLACEMENT_COLUMN, $controls[$known]['placements_rendered'], true)) {
            $bad[] = "positive control $known did not pass in a column";
        }
    }
    if ($controls['divi/not-a-module']['status'] === 'pass') {
        $bad[] = 'negative control divi/not-a-module passed';
    }
    foreach (array_keys($diagnosticLevels) as $label) {
        $c = $controls['diagnostic:' . $label];
        // Must be a captured Warning/Notice/Deprecated diagnostic, not merely some failure (e.g. a caught Fatal).
        if ($c['status'] !== 'fail' || preg_match('/PHP diagnostic in output: (Warning|Notice|Deprecated)/i', implode(' ', $c['reasons'])) !== 1) {
            $bad[] = "diagnostics control $label was not captured as a failure (status {$c['status']}): the harness cannot see PHP diagnostics";
        }
    }
    if ($bad !== []) {
        file_put_contents($outFile, json_encode(['calibrated' => false, 'controls' => $controls, 'error' => $bad], JSON_PRETTY_PRINT));
        $calibrationError = 'Harness calibration failed: ' . implode('; ', $bad) . " (see $outFile)";
    } else {
        $results = [];
        foreach ($candidates as $p) {
            $results[$p['name']] = $verify($p);
            WP_CLI::log(sprintf('%-46s %s [%s]', $p['name'], $results[$p['name']]['status'], implode(',', $results[$p['name']]['placements_rendered'])));
        }

        file_put_contents($outFile, json_encode(
            [
                'divi_version'   => $version,
                'calibrated'     => true,
                'placement_note' => $placementNote,
                'results'        => $results,
                'controls'       => $controls,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . "\n");
    }
} finally {
    $swept = $sweep();
    if ($swept > 0) {
        WP_CLI::warning("Swept $swept leftover aied-verify page(s) at exit.");
    }
}

if (isset($calibrationError)) {
    WP_CLI::error($calibrationError);
}
WP_CLI::success("Wrote $outFile");
