#!/usr/bin/env bash
set -euo pipefail
BASE=${1:?base url}; fail=0
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
CURL=(curl --silent --show-error --connect-timeout 10 --max-time 30 -A lld-smoke)
chk() {
 local want=$1 path=$2 got
 got=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE$path") || got=network-error
 if [[ "$got" == "$want" ]]; then echo "ok $got $path"; else echo "FAIL $path: $got (expected $want)"; fail=1; fi
}
for path in /health-tools/ /health-tools/protein/ /health-tools/diabetes-risk-check/ /tools/health-tools-hub.html /tools/protein-requirement-tool.html /tools/diabetes-risk-tool.html; do chk 200 "$path"; done
chk 403 /tools/api/config.php
chk 403 /tools/api/mailer.php
code=$("${CURL[@]}" -D "$TMP/headers" -o "$TMP/status" -w '%{http_code}' "$BASE/tools/status.json") || code=network-error
[[ "$code" == 200 ]] || { echo 'FAIL status response'; fail=1; }
grep -qi '^cache-control:.*no-store' "$TMP/headers" || { echo 'FAIL status no-store missing'; fail=1; }
python3 -m json.tool "$TMP/status" >/dev/null || { echo 'FAIL status JSON'; fail=1; }
for f in protein-requirement-tool.html diabetes-risk-tool.html; do
 if ! "${CURL[@]}" --fail -o "$TMP/page" "$BASE/tools/$f"; then fail=1; continue; fi
 served=$(sha256sum "$TMP/page" | cut -d' ' -f1)
 expected=$(python3 - "$f" "${DEPLOY_ORIGIN:-$BASE}" <<'PY'
import hashlib,sys
from pathlib import Path
print(hashlib.sha256((Path('site/public_html/tools')/sys.argv[1]).read_bytes().replace(b'__LLD_ORIGIN__',sys.argv[2].encode())).hexdigest())
PY
)
 [[ "$served" == "$expected" ]] && echo "ok hash $f" || { echo "FAIL $f: served=$served expected=$expected"; fail=1; }
done
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data 'not json' "$BASE/tools/submit.php") || code=network-error
[[ "$code" == 400 ]] || { echo "FAIL invalid JSON: HTTP $code"; fail=1; }
exit "$fail"
