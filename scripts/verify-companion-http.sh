#!/usr/bin/env bash
# The HTTP half of `make verify-companion`: a REAL request signed in with an application password changes a Divi page, and the
# undo point must carry that password's name. Dev site only (localhost:8181 from the host).
set -euo pipefail
BASE="${AIED_SITE:-http://localhost:8181}"
cd "$(dirname "$0")/.."
line="$(docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-companion.php --user=1 http-setup | grep '^AIED_HTTP ' | sed 's/^AIED_HTTP //')"
page="$(printf '%s' "$line" | python3 -c 'import json,sys; print(json.load(sys.stdin)["page"])')"
pass="$(printf '%s' "$line" | python3 -c 'import json,sys; print(json.load(sys.stdin)["password"])')"
content='<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->'
code="$(curl -s -o /dev/null -w '%{http_code}' -u "admin:${pass}" -X POST "${BASE}/wp-json/wp/v2/pages/${page}" --data-urlencode "content=${content}")" || true
echo "INFO  application-password request answered HTTP ${code}"
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-companion.php --user=1 http-check "${page}"
