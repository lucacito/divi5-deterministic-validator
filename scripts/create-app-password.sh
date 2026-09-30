#!/usr/bin/env bash
# create-app-password.sh — generate a WordPress Application Password for REST/API testing
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
set -a; source .env; set +a

WP_USER="${WP_ADMIN_USER:-admin}"

echo "[app-password] Creating Application Password for user: $WP_USER"

RESULT=$(docker compose exec -T wpcli wp user application-password create "$WP_USER" "Divi5 MCP Server" --porcelain 2>&1)

if [ $? -ne 0 ]; then
    echo "[app-password] ERROR: $RESULT"
    exit 1
fi

APP_PASS="$RESULT"

echo ""
echo "================================================================"
echo "  Application Password created!"
echo ""
echo "  User:     $WP_USER"
echo "  Password: $APP_PASS"
echo ""
echo "  Use with the REST API (Basic auth) or your .env:"
echo ""
echo "    WP_URL=http://localhost:${WP_PORT:-8181}"
echo "    WP_USER=$WP_USER"
echo "    WP_APP_PASSWORD=$APP_PASS"
echo ""
echo "================================================================"
echo ""
