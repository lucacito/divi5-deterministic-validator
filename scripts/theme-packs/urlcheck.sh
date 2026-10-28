#!/bin/sh
# Usage: sh scripts/theme-packs/urlcheck.sh <pack>  -> lists any pinned Pexels id that does not answer 200
grep -ohE "px:[0-9]+" "$(dirname "$0")/$1.php" | cut -d: -f2 | sort -u | while read id; do
  c=$(curl -s -o /dev/null -w "%{http_code}" -I "https://images.pexels.com/photos/$id/pexels-photo-$id.jpeg?auto=compress&cs=tinysrgb&w=400"); [ "$c" != 200 ] && echo "BAD $id $c"; done; echo "urlcheck $1 done"
