#!/usr/bin/env bash
# Shared by deploy and rollback; inputs are validated before shell interpolation.
set -euo pipefail
case "$ENV_NAME" in
 staging) EXPECTED_PATH=domains/lifelinedaily.com/public_html/stg; EXPECTED_URL=https://stg.lifelinedaily.com ;;
 production) EXPECTED_PATH=domains/lifelinedaily.com/public_html; EXPECTED_URL=https://lifelinedaily.com ;;
 *) echo 'Unknown environment' >&2; exit 2 ;;
esac
[[ "${DEPLOY_DOCROOT:-}" == "$EXPECTED_PATH" && "${DEPLOY_URL:-}" == "$EXPECTED_URL" ]]
[[ "${DEPLOY_HOST:-}" =~ ^[a-zA-Z0-9.-]+$ && "${DEPLOY_USER:-}" =~ ^[a-zA-Z0-9_-]+$ && "${DEPLOY_PORT:-}" =~ ^[0-9]+$ ]]
[[ -s "${DEPLOY_KEY_FILE:-}" && -s "${DEPLOY_KNOWN_HOSTS_FILE:-}" ]]
[[ "$DEPLOY_KEY_FILE" != *' '* && "$DEPLOY_KNOWN_HOSTS_FILE" != *' '* ]]
SSH_ARGS=(-p "$DEPLOY_PORT" -i "$DEPLOY_KEY_FILE" -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$DEPLOY_KNOWN_HOSTS_FILE" -o ConnectTimeout=15)
SSH=(ssh "${SSH_ARGS[@]}" "$DEPLOY_USER@$DEPLOY_HOST")
RSYNC_SSH="ssh -p $DEPLOY_PORT -i $DEPLOY_KEY_FILE -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$DEPLOY_KNOWN_HOSTS_FILE -o ConnectTimeout=15"
remote() { "${SSH[@]}" "python3 - '$ENV_NAME' \"\$HOME/$DEPLOY_DOCROOT\" '$1' '$STAMP'" < deploy/remote.py; }
