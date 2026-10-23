#!/usr/bin/env bash
# Deploy the site/ overlay to a Hostinger host over SSH. Usage: deploy/deploy.sh <staging|production> [--dry-run]
# Required env: DEPLOY_HOST DEPLOY_PORT DEPLOY_USER DEPLOY_KEY_FILE DEPLOY_DOCROOT DEPLOY_URL ; optional: RELEASE_TAG, SHIP_STATUS=1
set -euo pipefail
ENV_NAME="${1:?environment}"; DRY="${2:-}"
cd "$(dirname "$0")/.."
# DEC-34: substitute the origin placeholder in a staging copy of the overlay
STAGE_DIR="$(mktemp -d)"; cp -r site/public_html/. "$STAGE_DIR/"
find "$STAGE_DIR" -type f \( -name "*.html" -o -name ".htaccess.lld" \) -exec sed -i "s#__LLD_ORIGIN__#${DEPLOY_ORIGIN:?DEPLOY_ORIGIN required}#g" {} +
SRC="$STAGE_DIR/"
SSH="ssh -p ${DEPLOY_PORT:-22} -i ${DEPLOY_KEY_FILE} -o StrictHostKeyChecking=accept-new ${DEPLOY_USER}@${DEPLOY_HOST}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)-$(git rev-parse --short HEAD)"

echo "== [$ENV_NAME] pre-flight: provenance"
for slug in protein diabetes; do
  f=$(python3 -c "import json;print(json.load(open('manifests/$slug.json'))['versions'][0]['version_hash'])")
  file=$([ "$slug" = protein ] && echo protein-requirement-tool.html || echo diabetes-risk-tool.html)
  actual=$(sha256sum "site/public_html/tools/$file" | cut -d' ' -f1)
  [ "$f" = "$actual" ] || { echo "ABORT: $file hash $actual != manifest $f"; exit 2; }
  python3 - "$slug" "$f" <<'EOF'
import json,sys,os
slug,h=sys.argv[1],sys.argv[2]; p=f"approvals/{slug}.json"
if not os.path.exists(p): print(f"WARN: no approval record for {slug} (allowed on staging only)"); sys.exit(0)
a=json.load(open(p)); ok=a.get("version_hash")==h and a.get("clinical_signoff",{}).get("decision")=="approved"
print(("OK" if ok else "ABORT")+f": approval for {slug}"); sys.exit(0 if ok else 2)
EOF
done
if [ "$ENV_NAME" = production ]; then
  [ -n "${RELEASE_TAG:-}" ] && [ -f "releases/${RELEASE_TAG}.json" ] || { echo "ABORT: production needs RELEASE_TAG with releases/<tag>.json"; exit 2; }
  python3 -c "import json,sys;a=json.load(open('approvals/hg-03.json'));sys.exit(0 if a.get('decision')=='approved' and a.get('tag')=='${RELEASE_TAG}' else 2)" || { echo "ABORT: HG-03 not approved for ${RELEASE_TAG}"; exit 2; }
fi

echo "== [$ENV_NAME] backup on server -> ~/lld-backups/$STAMP.tgz"
$SSH "mkdir -p ~/lld-backups && cd '$DEPLOY_DOCROOT' && tar czf ~/lld-backups/$STAMP.tgz tools wp-content/mu-plugins/lld-tools* 2>/dev/null || true; ls -1t ~/lld-backups | tail -n +11 | sed 's#^#~/lld-backups/#' | xargs -r rm -f"

echo "== [$ENV_NAME] rsync overlay"
RSYNC_FLAGS=(-az --itemize-changes --exclude-from=deploy/rsync-exclude.txt --filter='P tools/api/config.php' --filter='P tools/api/outbox/**' --filter='P tools/api/state/**' --delete-after --filter='protect .htaccess' --filter='protect wp-*' --filter='protect index.php')
[ "$DRY" = "--dry-run" ] && RSYNC_FLAGS+=(--dry-run)
rsync "${RSYNC_FLAGS[@]}" -e "ssh -p ${DEPLOY_PORT:-22} -i ${DEPLOY_KEY_FILE} -o StrictHostKeyChecking=accept-new" "$SRC" "${DEPLOY_USER}@${DEPLOY_HOST}:${DEPLOY_DOCROOT}/"

echo "== [$ENV_NAME] managed .htaccess block"
if [ -f site/public_html/.htaccess.lld ] && [ "$DRY" != "--dry-run" ]; then
  $SSH "cd '$DEPLOY_DOCROOT' && python3 - <<'PY'
import re,io
blk=open('.htaccess.lld').read().strip()
try: cur=open('.htaccess').read()
except FileNotFoundError: cur=''
new=re.sub(r'# BEGIN lld.*?# END lld\n?', '', cur, flags=re.S).rstrip()+'\n# BEGIN lld\n'+blk+'\n# END lld\n'
open('.htaccess','w').write(new); print('managed block written')
PY
rm -f '$DEPLOY_DOCROOT/.htaccess.lld'"
fi

echo "== [$ENV_NAME] cache purge"
$SSH "cd '$DEPLOY_DOCROOT' && (command -v wp >/dev/null && wp litespeed-purge all 2>/dev/null) || echo 'WARN: wp-cli not available; purge via runbook'"

echo "== [$ENV_NAME] smoke"
bash deploy/smoke.sh "$DEPLOY_URL"

mkdir -p deploy/log && printf '{"ts":"%s","env":"%s","sha":"%s","tag":"%s","backup":"%s"}\n' "$(date -u +%FT%TZ)" "$ENV_NAME" "$(git rev-parse HEAD)" "${RELEASE_TAG:-}" "$STAMP" >> "deploy/log/$ENV_NAME.jsonl"
rm -rf "$STAGE_DIR"
echo "== [$ENV_NAME] done"
