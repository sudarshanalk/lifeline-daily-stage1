#!/usr/bin/env bash
set -euo pipefail
ENV_NAME=${1:?environment}; MODE=${2:-}
[[ "$MODE" == '' || "$MODE" == '--dry-run' ]]
cd "$(dirname "$0")/.."
python3 deploy/preflight.py "$ENV_NAME"
source deploy/connection.sh
STAMP="$(date -u +%Y%m%dT%H%M%SZ)-$(git rev-parse --short=12 HEAD)"
STAGE_DIR=$(mktemp -d); LOCKED=false
cleanup() { result=$?; if [[ "$LOCKED" == true ]]; then remote unlock || result=1; fi; rm -rf "$STAGE_DIR"; exit "$result"; }
trap cleanup EXIT
python3 deploy/prepare.py "$STAGE_DIR" "$EXPECTED_URL"
find "$STAGE_DIR" -type f -name '*.php' -print0 | xargs -0 -r -n1 php -l >/dev/null
remote check
if [[ "$MODE" != '--dry-run' ]]; then
 LOCKED=true
 remote backup
fi
# No root-level --delete. Only explicit managed files are sent; obsolete files
# require a reviewed removal, never inference from a sparse overlay checkout.
source deploy/rsync-flags.sh
flags=("${RSYNC_FLAGS[@]}")
[[ "$MODE" != '--dry-run' ]] || flags+=(--dry-run)
rsync "${flags[@]}" -e "$RSYNC_SSH" "$STAGE_DIR/" "$DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_DOCROOT/"
if [[ "$MODE" == '--dry-run' ]]; then echo 'Read-only rehearsal complete; no backup, write, purge or POST performed.'; exit 0; fi
remote headers
remote dbupdate
remote purge
DEPLOY_ORIGIN="$EXPECTED_URL" bash deploy/smoke.sh "$EXPECTED_URL"
remote finish
mkdir -p deploy/log
printf '{"env":"%s","sha":"%s","backup":"%s","smoke":"passed"}\n' "$ENV_NAME" "$(git rev-parse HEAD)" "$STAMP" > "deploy/log/$ENV_NAME.jsonl"
echo "Deployment passed; rollback backup: $STAMP"
