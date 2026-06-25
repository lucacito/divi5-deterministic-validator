#!/usr/bin/env bash
# export-layouts.sh — capture real Divi 5 layout JSON from the running environment
#
# Strategy (in order of preference):
#   1. Use WP-CLI + wp-eval to call Divi's own library export / data API
#   2. Read raw post meta / post content for pages using the Divi builder
#   3. Fall back to printing manual instructions
#
# Outputs are saved to fixtures/valid/ with descriptive names.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURES_DIR="$PROJECT_ROOT/fixtures/valid"

cd "$PROJECT_ROOT"

source .env 2>/dev/null || true
WP_PORT="${WP_PORT:-8080}"

echo "[export] Checking Divi 5 installation..."

# Verify Divi is active
DIVI_STATUS=$(docker compose exec -T wpcli wp theme status divi 2>&1 || echo "error")
if ! echo "$DIVI_STATUS" | grep -qi "active"; then
    echo "[export] ERROR: Divi theme is not active. Run 'make up' first."
    exit 1
fi

# ---------------------------------------------------------------
# Create sample pages with Divi builder content via WP-CLI
# We use wp post create + inject Divi layout data.
# Since we cannot hand-write Divi 5 JSON (the format is empirically unknown),
# we instead:
#   1. Create a page using the REST API / WP-CLI that Divi wraps
#   2. Read back whatever Divi stores (post content, post meta, or custom table)
#   3. Save that raw storage as our fixture
# ---------------------------------------------------------------

echo "[export] Creating test page via WP-CLI..."

# Create a simple page — Divi 5 may transform the content on save
PAGE_ID=$(docker compose exec -T wpcli wp post create \
    --post_type=page \
    --post_status=publish \
    --post_title="Validator Test Page — Simple" \
    --post_content="" \
    --porcelain)

echo "[export] Created page ID: $PAGE_ID"

# ---------------------------------------------------------------
# Activate the Divi builder on the page and set minimal layout data.
# Divi 5 uses a REST endpoint / block-based approach. We use wp eval
# to call internal Divi functions that set/get the layout JSON.
# ---------------------------------------------------------------

# First, check if Divi 5 has a layout data function or uses Gutenberg blocks
echo "[export] Probing Divi 5 data storage mechanism..."

docker compose exec -T wpcli wp eval '
$page_id = (int) $GLOBALS["argv"][1] ?? 0;

// Try to find where Divi 5 stores layout data
$post = get_post('"$PAGE_ID"');
echo "=== POST CONTENT ===\n";
echo $post->post_content . "\n\n";

// Check post meta for Divi-specific keys
$meta = get_post_meta('"$PAGE_ID"');
$divi_meta = array_filter($meta, function($key) {
    return strpos($key, "_et_") !== false
        || strpos($key, "et_pb") !== false
        || strpos($key, "divi") !== false;
}, ARRAY_FILTER_USE_KEY);

echo "=== DIVI META KEYS ===\n";
foreach ($divi_meta as $key => $val) {
    echo $key . ": " . print_r($val, true) . "\n";
}

// Check for Divi custom tables
global $wpdb;
$tables = $wpdb->get_results("SHOW TABLES LIKE \"%" . $wpdb->prefix . "et_%\"", ARRAY_N);
echo "\n=== DIVI CUSTOM TABLES ===\n";
foreach ($tables as $t) { echo $t[0] . "\n"; }

// List available Divi 5 classes/functions
echo "\n=== DIVI FUNCTIONS (sample) ===\n";
$fns = get_defined_functions()["user"];
$divi_fns = array_filter($fns, fn($f) => stripos($f, "divi") !== false || strpos($f, "et_") !== false);
foreach (array_slice($divi_fns, 0, 20) as $f) { echo $f . "\n"; }
' 2>&1 | tee /tmp/divi5_probe.txt

echo ""
echo "[export] Probe output saved to /tmp/divi5_probe.txt"

# ---------------------------------------------------------------
# Read whatever Divi stored and export it
# ---------------------------------------------------------------
echo "[export] Reading stored layout data for page $PAGE_ID..."

docker compose exec -T wpcli wp eval '
$page_id = '"$PAGE_ID"';
$post = get_post($page_id);

// Dump post content (Divi 5 may store JSON or blocks here)
$content = $post->post_content;

// Also collect all post meta
$meta = get_post_meta($page_id);

$output = [
    "source"       => "real-wp-export",
    "divi_version" => defined("ET_CORE_VERSION") ? ET_CORE_VERSION : (defined("DIVI_VERSION") ? DIVI_VERSION : "unknown"),
    "page_id"      => $page_id,
    "post_content" => $content,
    "post_meta"    => $meta,
    "exported_at"  => date("c"),
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
' 2>&1 > "$FIXTURES_DIR/page-${PAGE_ID}-raw.json"

echo "[export] Saved raw data to fixtures/valid/page-${PAGE_ID}-raw.json"

# ---------------------------------------------------------------
# Try Divi Library export if the class is available
# ---------------------------------------------------------------
echo "[export] Attempting Divi Library export..."

docker compose exec -T wpcli wp eval '
// Try ET_Builder_Library or equivalent Divi 5 class
if (class_exists("ET_Builder_Layout")) {
    $layouts = get_posts([
        "post_type"      => "et_pb_layout",
        "posts_per_page" => 5,
        "post_status"    => "publish",
    ]);
    echo "Found " . count($layouts) . " Divi library layouts\n";
    foreach ($layouts as $layout) {
        $meta = get_post_meta($layout->ID);
        $out = [
            "id"           => $layout->ID,
            "title"        => $layout->post_title,
            "post_content" => $layout->post_content,
            "meta"         => $meta,
        ];
        $filename = "/fixtures/valid/library-layout-" . $layout->ID . ".json";
        file_put_contents($filename, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "Saved: $filename\n";
    }
} else {
    echo "ET_Builder_Layout class not found\n";
}

// Also try REST API approach for Divi 5 blocks
$registry = WP_Block_Type_Registry::get_instance();
$all_types = $registry->get_all_registered();
$divi_blocks = array_filter(array_keys($all_types), fn($k) => strpos($k, "divi") !== false || strpos($k, "et/") !== false || strpos($k, "et-") !== false);
echo "\nRegistered Divi block types:\n";
foreach ($divi_blocks as $b) { echo "  $b\n"; }
' 2>&1

echo ""
echo "[export] ================================================================"
echo "[export] Export complete. Check fixtures/valid/ for captured data."
echo ""
echo "  If the files look empty or content is unexpected, Divi 5 may require"
echo "  at least one page to be saved through the Divi Builder UI before it"
echo "  stores its JSON layout format. In that case:"
echo ""
echo "    1. Open http://localhost:${WP_PORT}/wp-admin/"
echo "    2. Create or edit a page using the Divi Builder"
echo "    3. Add a few modules (text, image, etc.) and save"
echo "    4. Re-run: make export-layouts"
echo ""
echo "[export] ================================================================"
