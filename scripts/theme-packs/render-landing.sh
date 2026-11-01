#!/bin/sh
# DEV-ONLY. Usage: sh scripts/theme-packs/render-landing.sh <slug>   (run from the validator repo root; builds dist/landings/<slug>.json first)
# Renders the built landing on the Docker dev site at 1280 and 400 px with the real Divi -> dist/landings-shots/<slug>/shot-1280.png, shot-400.png, render.json.
# dogfood-render must run ONE at a time, so this takes a lock (waits up to 15 minutes) and releases it on exit.
set -e
slug="$1"; [ -n "$slug" ] || { echo "usage: render-landing.sh <slug>"; exit 2; }
f="dist/landings/$slug.json"; [ -f "$f" ] || { echo "build first: php scripts/theme-packs/build-landings.php $slug"; exit 2; }
lock=/private/tmp/claude-501/landing-render.lock
i=0; while ! mkdir "$lock" 2>/dev/null; do i=$((i+1)); [ $i -gt 180 ] && { echo "lock timeout"; exit 3; }; sleep 5; done
trap 'rmdir "$lock"' EXIT INT TERM
mkdir -p "dist/landings-shots/$slug"
php -r '$j=json_decode(file_get_contents($argv[1]),true); file_put_contents($argv[2],$j["post_content"]);' "$f" "dist/landings-shots/$slug/page.html"
node scripts/dogfood-render.cjs "dist/landings-shots/$slug/page.html" --out "dist/landings-shots/$slug" || true
ls "dist/landings-shots/$slug"
