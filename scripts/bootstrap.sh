#!/usr/bin/env bash
# bootstrap.sh — idempotent environment setup for Divi 5 Deterministic Validator
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIVI_ZIP="$PROJECT_ROOT/divi/Divi.zip"
ENV_FILE="$PROJECT_ROOT/.env"
ENV_EXAMPLE="$PROJECT_ROOT/.env.example"

# ---------------------------------------------------------------
# 1. Check for Divi.zip — the only manual step
# ---------------------------------------------------------------
if [ ! -f "$DIVI_ZIP" ]; then
    echo ""
    echo "================================================================"
    echo "  BLOCKER: Divi.zip not found"
    echo "================================================================"
    echo ""
    echo "  Divi 5 is commercial software that must be provided manually."
    echo ""
    echo "  WHAT TO DO:"
    echo "    1. Log in to your Elegant Themes account at elegantthemes.com"
    echo "    2. Download the Divi theme zip (Divi 5)"
    echo "    3. Place it at:"
    echo ""
    echo "       $DIVI_ZIP"
    echo ""
    echo "    The filename must be exactly: Divi.zip"
    echo "    (capital D, no version number in the name)"
    echo ""
    echo "  Then re-run: make up"
    echo "================================================================"
    echo ""
    exit 1
fi

echo "[bootstrap] Found divi/Divi.zip ✓"

# ---------------------------------------------------------------
# 2. Create .env from .env.example if missing
# ---------------------------------------------------------------
if [ ! -f "$ENV_FILE" ]; then
    cp "$ENV_EXAMPLE" "$ENV_FILE"
    echo "[bootstrap] Created .env from .env.example (review and adjust if needed)"
fi

# ---------------------------------------------------------------
# 3. Bring up containers (docker compose will fail loudly on missing vars)
# ---------------------------------------------------------------
cd "$PROJECT_ROOT"
echo "[bootstrap] Starting containers..."
docker compose up -d

# ---------------------------------------------------------------
# 4. Wait for WordPress container to be ready (HTTP check)
# ---------------------------------------------------------------
source "$ENV_FILE"
WP_PORT="${WP_PORT:-8080}"
echo "[bootstrap] Waiting for WordPress to respond on port $WP_PORT..."
MAX_WAIT=120
ELAPSED=0
until curl -sf "http://localhost:$WP_PORT/" > /dev/null 2>&1; do
    if [ $ELAPSED -ge $MAX_WAIT ]; then
        echo "[bootstrap] ERROR: WordPress did not respond after ${MAX_WAIT}s"
        exit 1
    fi
    sleep 3
    ELAPSED=$((ELAPSED + 3))
    echo "[bootstrap]   ...still waiting (${ELAPSED}s)"
done
echo "[bootstrap] WordPress is up ✓"

# ---------------------------------------------------------------
# 5. Install WordPress via WP-CLI (idempotent)
# ---------------------------------------------------------------
WP_INSTALLED=$(docker compose exec -T wpcli wp core is-installed 2>&1 || echo "not-installed")
if echo "$WP_INSTALLED" | grep -q "not-installed\|Error"; then
    echo "[bootstrap] Installing WordPress..."
    docker compose exec -T wpcli wp core install \
        --url="http://localhost:${WP_PORT}" \
        --title="${WP_SITE_TITLE:-Divi5 Validator Dev}" \
        --admin_user="${WP_ADMIN_USER:-admin}" \
        --admin_password="${WP_ADMIN_PASSWORD:-admin}" \
        --admin_email="${WP_ADMIN_EMAIL:-admin@example.local}" \
        --skip-email
    echo "[bootstrap] WordPress installed ✓"
else
    echo "[bootstrap] WordPress already installed ✓"
fi

# ---------------------------------------------------------------
# 6. Install & activate Divi 5 (idempotent)
# ---------------------------------------------------------------
DIVI_ACTIVE=$(docker compose exec -T wpcli wp theme status divi 2>&1 || echo "not-found")
if echo "$DIVI_ACTIVE" | grep -q "Active"; then
    echo "[bootstrap] Divi theme already active ✓"
else
    echo "[bootstrap] Installing Divi from divi/Divi.zip..."
    docker compose exec -T wpcli wp theme install /divi-install/Divi.zip --activate
    echo "[bootstrap] Divi theme installed and activated ✓"
fi

# ---------------------------------------------------------------
# 7. Done
# ---------------------------------------------------------------
echo ""
echo "================================================================"
echo "  Environment ready!"
echo ""
echo "  WordPress:  http://localhost:${WP_PORT}/"
echo "  Admin:      http://localhost:${WP_PORT}/wp-admin/"
echo "  User:       ${WP_ADMIN_USER:-admin}"
echo "  Password:   ${WP_ADMIN_PASSWORD:-admin}"
echo ""
echo "  Next step: make export-layouts"
echo "================================================================"
echo ""
