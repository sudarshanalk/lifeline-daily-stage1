#!/usr/bin/env bash
# stage3-hw-content: loads the synthetic Healthy Weight test content (tests/stage3/e2e/screens/hw-test-bundle.mjs: made-up
# questions, notes, focus blocks and festival calendar, no approved member text) into a throwaway test WordPress, for the
# Stage 3 browser job's hw-*.spec.mjs journeys (P3-35). Called by tests/ci/stage2-wp.sh for that job only. It refuses any
# site that is not a CI or local test site (LLD_ENV ci or local), so it can never reach staging or production.
# Needs: the lld-members plugin active and the lld-tools mu-plugin (its `wp lld content load`), node.
# Usage: bash tests/ci/stage3-hw-content.sh <wordpress dir>
set -euo pipefail
DIR=${1:?wordpress directory}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
WP=${WP_CLI:-wp}
ENV_NOW=$($WP eval 'echo defined("LLD_ENV") ? LLD_ENV : "";' --path="$DIR")
if [ "$ENV_NOW" != "ci" ] && [ "$ENV_NOW" != "local" ]; then
  echo "stage3-hw-content: LLD_ENV is '$ENV_NOW'; the test content loads only into a CI or local test site"; exit 1
fi
OUT=$(mktemp -d)
node "$REPO/tests/stage3/e2e/screens/hw-test-bundle.mjs" "$OUT/hw-test-bundle.json"
# The Stage 3 content (stage 3 items) shows only where content_new allows: the listed test accounts, as every other
# Stage 3 switch here. Ordinary members keep the Stage 2 library.
$WP lld-members feature content_new test_accounts --path="$DIR" --quiet
$WP lld content load --file="$OUT/hw-test-bundle.json" --backup-dir="$OUT/backups" --user=ci-admin --path="$DIR"
$WP lld content verify --file="$OUT/hw-test-bundle.json" --path="$DIR"
rm -rf "$OUT"
