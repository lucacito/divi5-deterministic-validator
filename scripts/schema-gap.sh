#!/usr/bin/env bash
# schema-gap.sh — compare the modules Divi ships with what the validator knows.
# Reads divi/Divi.zip (never committed). Writes build/proposals.json + build/candidates.json.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

ZIP="divi/Divi.zip"
[ -f "$ZIP" ] || { echo "[schema-gap] $ZIP not found (place Divi.zip there first)." >&2; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
PKG="Divi/includes/builder-5/visual-builder/packages"

unzip -q "$ZIP" "$PKG/*module.json" Divi/style.css -d "$TMP"
VERSION="$(grep -m1 -E '^[[:space:]]*Version:' "$TMP/Divi/style.css" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')"
echo "[schema-gap] Divi version in $ZIP: $VERSION"

mkdir -p build
echo "$VERSION" > build/divi-version.txt
php scripts/derive-schema.php "$TMP/$PKG" --json=build/proposals.json --candidates=build/candidates.json --report
