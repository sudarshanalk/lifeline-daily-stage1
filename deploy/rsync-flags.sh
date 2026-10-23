# Preserve existing Hostinger permissions/ownership and directory timestamps.
# mktemp creates the release root as 700; archive mode alone would publish that.
RSYNC_FLAGS=(-az --no-perms --no-owner --no-group --omit-dir-times --delay-updates --itemize-changes)
