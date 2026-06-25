#!/usr/bin/env bash
# self-test.sh — run the PHPUnit suite; exits non-zero on any failure
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

if [ ! -f vendor/autoload.php ]; then
    echo "[test] Running composer install..."
    composer install --no-interaction
fi

echo "[test] Running PHPUnit..."
vendor/bin/phpunit --colors=always "$@"
