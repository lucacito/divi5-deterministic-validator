#!/usr/bin/env bash
# Install + activate WooCommerce and import the 16 sample products into the
# render env, so the divi/shop grid renders populated screenshots. Idempotent.
set -euo pipefail
cd "$(dirname "$0")/.."

wpcli() { docker compose exec -T wpcli wp "$@"; }

echo "==> Ensuring WooCommerce is installed + active"
wpcli plugin install woocommerce --activate

echo "==> Ensuring the WordPress importer is available"
wpcli plugin install wordpress-importer --activate

count="$(wpcli post list --post_type=product --format=count 2>/dev/null || echo 0)"
if [ "$count" -ge 1 ]; then
  echo "==> $count products already present — skipping sample import (idempotent)"
else
  echo "==> Importing sample products"
  wpcli import /var/www/html/wp-content/plugins/woocommerce/sample-data/sample_products.xml --authors=create
fi

echo "==> Done. Product count: $(wpcli post list --post_type=product --format=count)"
