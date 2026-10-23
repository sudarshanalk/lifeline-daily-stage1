#!/usr/bin/env bash
# Post-deploy smoke checks. Usage: deploy/smoke.sh https://host
set -euo pipefail
BASE="${1:?base url}"; fail=0
chk() { local want="$1" url="$2"; local got; got=$(curl -s -o /dev/null -w '%{http_code}' -A "lld-smoke" "$BASE$url"); if [ "$got" = "$want" ]; then echo "ok   $got $url"; else echo "FAIL $got (want $want) $url"; fail=1; fi; }
chk 200 /health-tools/
chk 200 /health-tools/protein/
chk 200 /health-tools/diabetes-risk-check/
chk 200 /tools/protein-requirement-tool.html
chk 200 /tools/diabetes-risk-tool.html
chk 403 /tools/api/config.php
chk 403 /tools/api/mailer.php
sj=$(curl -s -D - -o /tmp/status.json -A lld-smoke "$BASE/tools/status.json" | tr -d '\r'); echo "$sj" | grep -qi '^HTTP/.* 200' && echo "ok   200 /tools/status.json" || { echo "FAIL status.json"; fail=1; }
echo "$sj" | grep -qi 'cache-control:.*no-store' && echo "ok   status.json no-store" || echo "WARN status.json lacks Cache-Control: no-store"
for f in protein-requirement-tool.html diabetes-risk-tool.html; do
  served=$(curl -s -A lld-smoke "$BASE/tools/$f" | sha256sum | cut -d' ' -f1)
  # the deployed copy has __LLD_ORIGIN__ substituted (DEC-34); compare against the same substitution
  local_=$(sed "s#__LLD_ORIGIN__#${DEPLOY_ORIGIN:-$BASE}#g" "site/public_html/tools/$f" | sha256sum | cut -d' ' -f1)
  [ "$served" = "$local_" ] && echo "ok   hash $f" || { echo "FAIL hash mismatch $f"; fail=1; }
done
code=$(curl -s -o /dev/null -w '%{http_code}' -A lld-smoke -X POST -H 'Content-Type: application/json' --data 'not json' "$BASE/tools/submit.php"); [ "$code" = "400" ] && echo "ok   400 invalid body submit.php" || { echo "FAIL submit.php invalid body -> $code"; fail=1; }
exit $fail
