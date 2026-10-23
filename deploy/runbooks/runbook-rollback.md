# Runbook: roll back an overlay deploy

Every deploy stores `~/lld-backups/<timestamp>-<sha>.tgz` on the server (last 10 kept) containing `tools/` and `wp-content/mu-plugins/lld-tools*` as they were before the deploy.

1. List backups: `ssh <host> 'ls -1t ~/lld-backups'`.
2. Restore: `ssh <host> 'cd $DOCROOT && tar xzf ~/lld-backups/<name>.tgz'` (this restores the previous `status.json` too; re-apply a withdrawal if one was active).
3. Re-apply the managed `.htaccess` block from the previous release if headers changed (rarely needed; the block is versioned in Git at that tag).
4. Purge LiteSpeed and run `deploy/smoke.sh <url>`.
5. Record in `deploy/log/<env>.jsonl` and, for production, in `releases/<tag>.json.rollback`.

Target time: under 5 minutes. Rollback never touches the WordPress database.
