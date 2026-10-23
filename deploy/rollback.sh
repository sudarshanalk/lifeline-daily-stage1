#!/usr/bin/env bash
set -euo pipefail
ENV_NAME=${1:?environment}; STAMP=${2:?backup id}
[[ "$STAMP" =~ ^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{12}$ ]]
cd "$(dirname "$0")/.."
source deploy/connection.sh
# Use the previous release checkout for the expected smoke hashes.
cleanup() { result=$?; remote unlock || result=1; exit "$result"; }
trap cleanup EXIT
remote check
remote restore
remote purge
DEPLOY_ORIGIN="$EXPECTED_URL" bash deploy/smoke.sh "$EXPECTED_URL"
echo 'Rollback verified. Database, credentials and withdrawal state were preserved.'
