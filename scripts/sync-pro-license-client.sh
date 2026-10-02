#!/usr/bin/env bash
#
# Generate the Pro add-on's licence client from the canonical divi5lab client in the separate
# `layoutlab` repo (read-only: this script never writes there). The canonical source hardcodes
# another product's namespace and text domain, so each consumer rewrites them with sed, exactly
# like layoutlab/scripts/sync-license-client.sh does for its own destinations.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="${AIED_LICENSE_CLIENT_SRC:-$ROOT/../layoutlab/lib/license-server/php-client/class-license-client.php}"
DEST="$ROOT/pro-addon/src/Licensing/LicenseClient.php"

if [ ! -f "$SRC" ]; then
  echo "[sync] canonical licence client not found: $SRC" >&2
  echo "[sync] set AIED_LICENSE_CLIENT_SRC to its path" >&2
  exit 1
fi

mkdir -p "$(dirname "$DEST")"
sed \
  -e 's/ElementorDivi5Converter\\Pro\\Licensing/AiEditorDivi5\\Pro\\Licensing/g' \
  -e 's/jhmg-converter-for-elementor-to-divi-pro/jhmg-ai-editor-for-divi-5-pro/g' \
  -e 's/JHMG Converter Pro/AI Editor for Divi 5 Pro/g' \
  -e 's/tools\.php/admin.php/g' \
  -e 's/tab=license/tab=pro/g' \
  "$SRC" > "$DEST"

php -l "$DEST" >/dev/null
echo "[sync] wrote $DEST"
