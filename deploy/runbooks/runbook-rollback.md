# Roll back an LLD overlay

Use the dedicated LLD SSH connection. Backups are private: ~/lld-backups/staging/<id>.tgz or ~/lld-backups/production/<id>.tgz. IDs have UTC timestamp plus 12-character commit prefix. Each archive includes a checksummed inventory and the previous managed header block, excluding credentials, status.json and runtime data.

1. Identify the backup from the failed deployment output and the previous deployed commit from Actions history. Check out that previous release for its expected smoke hashes, while using the current reviewed rollback tooling if that release predates it.
2. Configure the connection variables from deploy/environments/<environment>.env.example. Check any server deploy.lock belongs to a stopped job before removing a stale lock manually; never interrupt an active deploy.
3. Run bash deploy/rollback.sh <staging|production> <backup-id>.
4. The runner verifies archive checksums, restores previous managed files, removes new managed files, restores only the managed header block, purges LiteSpeed and checks smoke. Runtime data, credentials, withdrawal state and the WordPress database are preserved.
5. Record the rollback and its measured duration in release evidence. The five-minute target requires an actual staging drill; unit tests alone do not establish it.

A failure must be investigated; never report success just because extraction completed. There is no database rollback in this overlay-only workflow.
