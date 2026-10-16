#!/usr/bin/env bash
# divi-currency.sh: react to a new Divi release in one command.
#
#   make divi-currency ZIP=divi/Divi-5-15-1.zip [OLD=divi/Divi-5.14.0.zip] [INSTALL=1] [GATES=1]
#
# 1. Unpacks the new theme (and the old one: OLD, or the current divi/Divi.zip when it differs) and writes
#    docs/divi-currency-<version>.md: new and removed modules, changed module definitions, new and changed server code,
#    and the changelog entries since the old version. This part touches nothing on the site.
# 2. INSTALL=1: makes the new zip divi/Divi.zip (the old one is kept as divi/Divi-<oldversion>.zip), upgrades the theme on the Docker
#    dev site, then runs `make schema-gap` and `make verify-modules` (which writes docs/module-verification-<X.Y>.json).
# 3. GATES=1 (needs INSTALL=1): runs the unit suite and every real-site gate that depends on Divi, and appends a PASS/FAIL table to
#    the report: the free gates always, the Pro gates when the Pro add-on is active on the dev site.
#
# It reports; it never promotes a module, edits a rule or saves anything outside the dev site. A new block type still needs a real
# export and a live render before the validator accepts it (CLAUDE.md, "No Divi schema from memory").
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

NEW_ZIP="${1:-}"; OLD_ZIP="${2:-}"; INSTALL="${3:-0}"; GATES="${4:-0}"
[ -n "$NEW_ZIP" ] && [ -f "$NEW_ZIP" ] || { echo "[divi-currency] Usage: make divi-currency ZIP=divi/Divi-X-Y-Z.zip [OLD=...] [INSTALL=1] [GATES=1]" >&2; exit 2; }
[ "$GATES" = "1" ] && [ "$INSTALL" != "1" ] && { echo "[divi-currency] GATES=1 needs INSTALL=1 (the gates run against the installed theme)." >&2; exit 2; }

if [ -z "$OLD_ZIP" ] && [ -f divi/Divi.zip ] && ! cmp -s divi/Divi.zip "$NEW_ZIP"; then OLD_ZIP="divi/Divi.zip"; fi

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
unpack() { local zip="$1" dest="$2"; mkdir -p "$dest"; unzip -q "$zip" -d "$dest"; find "$dest" -mindepth 1 -maxdepth 1 -type d | head -1; }
NEW_DIR="$(unpack "$NEW_ZIP" "$TMP/new")"
OLD_DIR="-"; [ -n "$OLD_ZIP" ] && [ -f "$OLD_ZIP" ] && OLD_DIR="$(unpack "$OLD_ZIP" "$TMP/old")"
NEW_VERSION="$(grep -m1 -E '^[[:space:]]*Version:' "$NEW_DIR/style.css" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')"
OLD_VERSION=""; [ "$OLD_DIR" != "-" ] && OLD_VERSION="$(grep -m1 -E '^[[:space:]]*Version:' "$OLD_DIR/style.css" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')"
REPORT="docs/divi-currency-${NEW_VERSION}.md"

php scripts/divi-currency.php "$OLD_DIR" "$NEW_DIR" "$REPORT" || exit 1

if [ "$INSTALL" = "1" ]; then
  if ! cmp -s "$NEW_ZIP" divi/Divi.zip 2>/dev/null; then
    [ -n "$OLD_VERSION" ] && [ -f divi/Divi.zip ] && [ ! -f "divi/Divi-${OLD_VERSION}.zip" ] && cp divi/Divi.zip "divi/Divi-${OLD_VERSION}.zip"
    cp "$NEW_ZIP" divi/Divi.zip
  fi
  docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp theme install /divi-install/Divi.zip --activate --force >/dev/null 2>&1
  INSTALLED="$(docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp theme get Divi --field=version 2>/dev/null | tr -d '\r\n')"
  [ "$INSTALLED" = "$NEW_VERSION" ] || { echo "[divi-currency] the dev site runs Divi '$INSTALLED', not $NEW_VERSION: stopping." >&2; exit 1; }
  echo "[divi-currency] the dev site now runs Divi $INSTALLED"
  make schema-gap 2>&1 | sed -n '1,4p' | sed 's/^/[schema-gap] /'
  make verify-modules > "$TMP/verify-modules.log" 2>&1; VM=$?
  echo "[divi-currency] verify-modules exit $VM (evidence: docs/module-verification-${NEW_VERSION%.*}.json)"
fi

if [ "$GATES" = "1" ]; then
  PRO_ACTIVE="$(docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin is-active jhmg-ai-editor-for-divi-5-pro >/dev/null 2>&1 && echo yes || echo no)"
  GATE_LIST="test verify-html-to-divi verify-guide-shapes verify-components verify-style-values verify-global-styles verify-page-title verify-history"
  [ "$PRO_ACTIVE" = "yes" ] && GATE_LIST="$GATE_LIST verify-pro-global-variables verify-pro-global-presets verify-pro-global-colors verify-pro-theme-layouts"
  {
    echo "## Gates on Divi ${NEW_VERSION}"
    echo
    echo "| Gate | Result |"
    echo "| --- | --- |"
    echo "| verify-modules | $([ "${VM:-1}" = "0" ] && echo PASS || echo FAIL) |"
  } >> "$REPORT"
  FAILED=0
  for g in $GATE_LIST; do
    if make "$g" > "$TMP/$g.log" 2>&1; then R=PASS; else R="FAIL"; FAILED=1; fi
    echo "[divi-currency] $g: $R"
    echo "| $g | $R |" >> "$REPORT"
  done
  [ "$PRO_ACTIVE" = "yes" ] || echo "| Pro gates | skipped (the Pro add-on is not active on the dev site) |" >> "$REPORT"
  echo >> "$REPORT"; echo "Not run here: verify-oauth, verify-products, the other verify-pro-* gates. Run them before a release." >> "$REPORT"
  [ "$FAILED" = "0" ] && [ "${VM:-1}" = "0" ] || { echo "[divi-currency] at least one gate failed: see $REPORT"; exit 1; }
fi
echo "[divi-currency] done: $REPORT"
