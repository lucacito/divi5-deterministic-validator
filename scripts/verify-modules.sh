#!/usr/bin/env bash
# verify-modules.sh — render every placeable Divi module (still-missing AND already-promoted) on the real
# Divi (Docker WP) and record evidence. Refuses to overwrite committed evidence with a result set that
# loses or demotes modules (scripts/check-evidence.php).
# Requires: make up (WP + Divi running), and divi/Divi.zip matching the installed Divi.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

bash scripts/schema-gap.sh
VERSION="$(cat build/divi-version.txt)"

INSTALLED="$(docker compose exec -T wpcli wp theme get Divi --field=version | tr -d '\r\n')"
if [ "$INSTALLED" != "$VERSION" ]; then
  echo "[verify] Installed Divi ($INSTALLED) != divi/Divi.zip ($VERSION). Upgrade the theme first:" >&2
  echo "         docker compose exec -T wpcli wp theme install /divi-install/Divi.zip --activate --force" >&2
  exit 1
fi

docker compose exec -T -u root wpcli rm -rf /tmp/divi5-tools /tmp/verify-modules.php /tmp/candidates.json /tmp/results.json
docker compose cp tools wpcli:/tmp/divi5-tools
docker compose cp scripts/verify-modules.php wpcli:/tmp/verify-modules.php
docker compose cp build/candidates.json wpcli:/tmp/candidates.json

docker compose exec -T wpcli wp eval-file /tmp/verify-modules.php /tmp/candidates.json /tmp/results.json "$VERSION"

EVIDENCE="docs/module-verification-${VERSION%.*}.json"
NEW="$(mktemp "${TMPDIR:-/tmp}/verify-modules-results.XXXXXX")"
trap 'rm -f "$NEW"' EXIT

docker compose cp wpcli:/tmp/results.json "$NEW"

if [ -f "$EVIDENCE" ]; then
  if ! php scripts/check-evidence.php "$EVIDENCE" "$NEW"; then
    echo "[verify] Guard failed: $EVIDENCE left untouched (new results discarded)." >&2
    exit 1
  fi
fi

chmod 644 "$NEW"
mv "$NEW" "$EVIDENCE"
echo "[verify] Evidence written to $EVIDENCE"
