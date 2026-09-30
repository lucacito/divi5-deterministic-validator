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
$candidates = json_decode((string) file_get_contents($candidatesFile), true);
if (!is_array($candidates)) {
    WP_CLI::error('candidates.json is not valid JSON');
}

$probe = function (string $module, ?string $child, string $placement) use ($version): array {
    $markup = MarkupBuilder::page($module, $placement, $child, $version);
    $id = wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => 'aied-verify',
        'post_content' => wp_slash($markup), // wp_slash: core strips backslashes otherwise
    ]);
    update_post_meta($id, '_et_pb_use_divi_5', 'on');
    update_post_meta($id, '_et_pb_use_builder', 'on');

    $post = get_post($id);
    $GLOBALS['post'] = $post;
    setup_postdata($post);
    ob_start();
    try {
        $html = (string) apply_filters('the_content', $post->post_content);
    } catch (\Throwable $e) {
        $html = 'Fatal error: ' . $e->getMessage();
    }
    $noise = (string) ob_get_clean();
    wp_delete_post($id, true);
    wp_reset_postdata();

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
    $ok = array_keys(array_filter($placements, fn ($r) => $r['status'] === 'pass'));
    if ($ok !== []) {
        $status = 'pass';
    } elseif (array_filter($placements, fn ($r) => $r['status'] === 'fail')) {
        $status = 'fail';
    } else {
        $status = 'needs-real-export';
    }

    return ['status' => $status, 'placements_ok' => $ok, 'placements' => $placements];
};

// Controls prove the harness can tell real from unreal BEFORE we trust any result.
$controls = [];
foreach (['divi/heading', 'divi/blurb', 'divi/shop'] as $known) {
    $controls[$known] = $verify(['name' => $known, 'children' => []]);
}
$controls['divi/not-a-module'] = $verify(['name' => 'divi/not-a-module', 'children' => []]);

$bad = [];
foreach (['divi/heading', 'divi/blurb', 'divi/shop'] as $known) {
    if (!in_array(MarkupBuilder::PLACEMENT_COLUMN, $controls[$known]['placements_ok'], true)) {
        $bad[] = "positive control $known did not pass in a column";
    }
}
if ($controls['divi/not-a-module']['status'] === 'pass') {
    $bad[] = 'negative control divi/not-a-module passed';
}
if ($bad !== []) {
    file_put_contents($outFile, json_encode(['controls' => $controls, 'error' => $bad], JSON_PRETTY_PRINT));
    WP_CLI::error('Harness calibration failed: ' . implode('; ', $bad) . " (see $outFile)");
}

$results = [];
foreach ($candidates as $p) {
    $results[$p['name']] = $verify($p);
    WP_CLI::log(sprintf('%-46s %s [%s]', $p['name'], $results[$p['name']]['status'], implode(',', $results[$p['name']]['placements_ok'])));
}

file_put_contents($outFile, json_encode(
    ['divi_version' => $version, 'results' => $results, 'controls' => $controls],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n");
WP_CLI::success("Wrote $outFile");
