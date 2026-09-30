#!/usr/bin/env bash
# verify-modules.sh — render each missing Divi module on the real Divi (Docker WP) and record evidence.
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

docker compose cp wpcli:/tmp/results.json "docs/module-verification-${VERSION%.*}.json"
echo "[verify] Evidence written to docs/module-verification-${VERSION%.*}.json"
