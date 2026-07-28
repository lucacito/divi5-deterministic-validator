#!/usr/bin/env bash
#
# Build the distributable ai-editor-divi5.zip from wp-plugin/.
#
# The archive is a clean copy of wp-plugin/'s contents under a top-level
# `ai-editor-divi5/` folder (the WordPress plugin slug), with no macOS temp
# junk. Rebuild after changing anything under wp-plugin/ so the installable
# distributable stays current. See CLAUDE.md → "The plugin build".
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SRC="wp-plugin"
SLUG="ai-editor-divi5"
OUT="$ROOT/$SLUG.zip"

if [ ! -f "$SRC/$SLUG.php" ]; then
  echo "[build] ERROR: $SRC/$SLUG.php not found — run from the repo root." >&2
  exit 1
fi

VERSION="$(grep -E "^\s*\*\s*Version:" "$SRC/$SLUG.php" | head -1 | sed -E 's/.*Version:\s*//')"
echo "[build] Packaging $SLUG version $VERSION"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

cp -R "$SRC" "$STAGE/$SLUG"
# Strip anything that must never ship in a WordPress plugin zip.
find "$STAGE/$SLUG" \
  \( -name '.DS_Store' -o -name '__MACOSX' -o -name '*.map' -o -name '.git*' \) \
  -exec rm -rf {} + 2>/dev/null || true

rm -f "$OUT"
( cd "$STAGE" && zip -rXq "$OUT" "$SLUG" -x '*.DS_Store' )

echo "[build] Wrote $OUT ($(du -h "$OUT" | cut -f1))"
echo "[build] Top-level entries:"
unzip -l "$OUT" | awk '{print $4}' | grep -E "^$SLUG/[^/]+/?$" | sort -u | sed 's/^/  /'
