#!/usr/bin/env bash
# Disposable GitHub runner only. Exercises the real SSH + rsync dry-run path.
set -euo pipefail
sudo apt-get update
sudo apt-get install -y openssh-server rsync php-cli
sudo service ssh start
key="$RUNNER_TEMP/lld-test-key"
ssh-keygen -q -t ed25519 -N '' -f "$key"
mkdir -p "$HOME/.ssh"; chmod 700 "$HOME/.ssh"
cat "$key.pub" >> "$HOME/.ssh/authorized_keys"; chmod 600 "$HOME/.ssh/authorized_keys"
ssh-keyscan -t ed25519 127.0.0.1 > "$RUNNER_TEMP/lld-known-hosts"
root="$HOME/domains/lifelinedaily.com/public_html/stg"
mkdir -p "$root/tools/api" "$root/wp-content"
printf '<?php // fixture only\n' > "$root/wp-config.php"
printf '<?php return [];\n' > "$root/tools/api/config.php"
printf '{"withdrawn":true}\n' > "$root/tools/status.json"
printf 'unmanaged wordpress fixture\n' > "$root/index.php"
printf '#!/bin/sh\nexit 0\n' | sudo tee /usr/local/bin/wp >/dev/null
sudo chmod 755 /usr/local/bin/wp
export DEPLOY_HOST=127.0.0.1 DEPLOY_PORT=22 DEPLOY_USER="$USER"
export DEPLOY_KEY_FILE="$key" DEPLOY_KNOWN_HOSTS_FILE="$RUNNER_TEMP/lld-known-hosts"
export DEPLOY_DOCROOT=domains/lifelinedaily.com/public_html/stg DEPLOY_URL=https://stg.lifelinedaily.com
before=$(find "$root" -type f -exec sha256sum {} + | sort)
bash deploy/deploy.sh staging --dry-run
after=$(find "$root" -type f -exec sha256sum {} + | sort)
test "$before" = "$after"
test ! -e "$HOME/lld-backups"
echo 'SSH rehearsal passed: document root unchanged, no backup or mutation.'
