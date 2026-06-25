#!/usr/bin/env bash
# export-layouts.sh — export real Divi 5 layouts from all existing pages
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURES_DIR="$PROJECT_ROOT/fixtures/valid"

cd "$PROJECT_ROOT"
set -a; source .env; set +a

echo "[export] Finding Divi 5 pages..."

# Get IDs of all pages using the Divi 5 builder with non-empty content
PAGE_IDS=$(docker compose exec -T wpcli wp post list \
    --post_type=page \
    --post_status=publish \
    --meta_key=_et_pb_use_divi_5 \
    --meta_value=on \
    --fields=ID \
    --format=csv 2>&1 | tail -n +2)

if [ -z "$PAGE_IDS" ]; then
    echo "[export] No Divi 5 pages found. Create and publish a page using the Divi builder first."
    exit 1
fi

COUNT=0
for PAGE_ID in $PAGE_IDS; do
    PAGE_ID=$(echo "$PAGE_ID" | tr -d '[:space:]')

    # Check post_content is non-empty
    CONTENT_LEN=$(docker compose exec -T wpcli wp post get "$PAGE_ID" --field=post_content 2>&1 | wc -c | tr -d ' ')
    if [ "$CONTENT_LEN" -lt 10 ]; then
        echo "[export] Skipping page $PAGE_ID — empty post_content (not saved via Divi builder yet)"
        continue
    fi

    TITLE=$(docker compose exec -T wpcli wp post get "$PAGE_ID" --field=post_title 2>&1 | tr ' ' '-' | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9-')
    FILENAME="$FIXTURES_DIR/page-${PAGE_ID}-${TITLE}.json"

    echo "[export] Exporting page $PAGE_ID: $TITLE..."

    docker compose exec -T wpcli wp eval '
$page_id = '"$PAGE_ID"';
$post = get_post($page_id);
$output = [
    "source"       => "wp-divi5-export",
    "format"       => "gutenberg-blocks",
    "divi_version" => defined("ET_CORE_VERSION") ? ET_CORE_VERSION : "unknown",
    "page_id"      => $page_id,
    "post_title"   => get_the_title($page_id),
    "post_status"  => $post->post_status,
    "post_content" => $post->post_content,
    "divi_meta"    => [
        "_et_pb_use_divi_5"  => get_post_meta($page_id, "_et_pb_use_divi_5", true),
        "_et_pb_use_builder" => get_post_meta($page_id, "_et_pb_use_builder", true),
    ],
    "exported_at"  => date("c"),
];
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
' 2>&1 > "$FILENAME"

    echo "[export] Saved: fixtures/valid/$(basename "$FILENAME")"
    COUNT=$((COUNT + 1))
done

echo ""
echo "[export] Done — exported $COUNT page(s) to fixtures/valid/"
echo ""
echo "  Next: run 'make test' to validate all fixtures."
echo "  If new block types appear, add them to src/SchemaRules.php."
