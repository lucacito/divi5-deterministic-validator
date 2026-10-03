#!/usr/bin/env bash
#
# Build the installable Pro add-on zip: dist/jhmg-ai-editor-for-divi-5-pro.zip. It is sold from
# divi5lab.com, never uploaded to WordPress.org, and `dist/` is git-ignored. Only the main file,
# uninstall.php, readme.txt and src/ ship; tests, staged files and docs stay out.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SRC="pro-addon"
SLUG="jhmg-ai-editor-for-divi-5-pro"
MAIN="$SLUG.php"
OUT_DIR="$ROOT/dist"
OUT="$OUT_DIR/$SLUG.zip"

if [ ! -f "$SRC/$MAIN" ]; then
  echo "[build-pro] ERROR: $SRC/$MAIN not found - run from the repo root." >&2
  exit 1
fi

VERSION="$(grep -E "^\s*\*\s*Version:" "$SRC/$MAIN" | head -1 | sed -E 's/.*Version:[[:space:]]*//')"
echo "[build-pro] Packaging $SLUG version $VERSION"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG"
cp "$SRC/$MAIN" "$SRC/uninstall.php" "$SRC/readme.txt" "$STAGE/$SLUG/"
cp -R "$SRC/src" "$STAGE/$SLUG/src"
find "$STAGE" -name '.DS_Store' -delete

mkdir -p "$OUT_DIR"
rm -f "$OUT"
( cd "$STAGE" && zip -q -r "$OUT" "$SLUG" )
echo "[build-pro] wrote $OUT ($(wc -c < "$OUT" | tr -d ' ') bytes)"
