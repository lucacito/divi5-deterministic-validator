#!/usr/bin/env bash
# Dev-only: drive the Lab assistant tool on the Docker dev site and log every call (docs/dogfood.md).
#   scripts/dogfood-lab.sh <run> tools-list
#   scripts/dogfood-lab.sh <run> call <tool> '<arguments JSON>'
#   scripts/dogfood-lab.sh <run> seed '<brief JSON>'
#   scripts/dogfood-lab.sh <run> cleanup
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
RUN="$1"; CMD="$2"; shift 2
DIR="$ROOT/dist/dogfood-lab/$RUN"; mkdir -p "$DIR"
case "$CMD" in
  tools-list) PARAMS='{}' ;;
  call) PARAMS=$(python3 -c 'import json,sys; print(json.dumps({"tool": sys.argv[1], "arguments": json.loads(sys.argv[2])}))' "$1" "${2:-{\}}") ;;
  seed) PARAMS=$(python3 -c 'import json,sys; print(json.dumps({"brief": json.loads(sys.argv[1])}))' "$1") ;;
  cleanup) PARAMS=$(python3 - "$DIR/calls.jsonl" <<'PY'
import json,sys,os
pages=set();briefs=set()
if os.path.exists(sys.argv[1]):
    for line in open(sys.argv[1]):
        r=json.loads(line).get("response") or {}
        res=r.get("result") if isinstance(r,dict) else None
        if isinstance(res,dict):
            if isinstance(res.get("page"),dict) and res["page"].get("id"): pages.add(res["page"]["id"])
            if res.get("brief_id"): briefs.add(res["brief_id"])
            b=res.get("build")
            if isinstance(b,dict) and isinstance(b.get("page"),dict) and b["page"].get("id"): pages.add(b["page"]["id"])
        if isinstance(r,dict) and r.get("brief_id"): briefs.add(r["brief_id"])
print(json.dumps({"pages":sorted(pages),"briefs":sorted(briefs)}))
PY
  ) ;;
  *) echo "unknown command $CMD" >&2; exit 2 ;;
esac
B64=$(printf '%s' "$PARAMS" | base64 | tr -d '\n')
RAW=$(cd "$ROOT" && docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/dogfood-lab-server.php "${CMD/tools-list/tools-list}" "$B64" --user=1 2>&1)
JSON=$(printf '%s\n' "$RAW" | sed -n 's/^@@AIED@@//p' | tail -1)
if [ -z "$JSON" ]; then echo "$RAW" >&2; exit 1; fi
python3 - "$DIR/calls.jsonl" "$CMD" "$PARAMS" "$JSON" <<'PY'
import json,sys,datetime
log,cmd,params,resp=sys.argv[1:5]
with open(log,"a") as f:
    f.write(json.dumps({"t":datetime.datetime.utcnow().isoformat(),"cmd":cmd,"params":json.loads(params),"response":json.loads(resp)})+"\n")
PY
printf '%s\n' "$JSON" | python3 -m json.tool
