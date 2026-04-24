#!/usr/bin/env bash
#
# Hard reset Cloudflare infrastructure for joshuaheidorn.com.
#
# Deletes the Worker, D1 database (incl. all rows), and R2 bucket (incl. all
# objects), then recreates the D1 and R2 resources empty. Prints the new D1
# database_id for you to paste into wrangler.jsonc.
#
# This is irreversible. Site is down from Worker delete until the next
# successful `yarn deploy`.

set -euo pipefail

WORKER_NAME="joshuaheidorn"
D1_NAME="joshuaheidorn-content"
R2_BUCKET="joshuaheidorn-media"

cd "$(dirname "$0")/.."

cat <<EOF

This will DELETE from Cloudflare (prod):
  - Worker:   $WORKER_NAME   (custom domain goes down until redeploy)
  - D1:       $D1_NAME       (posts, pages, media rows, revisions — all gone)

Then it will recreate empty:
  - D1:       $D1_NAME       (new database_id)

R2 bucket $R2_BUCKET is preserved (wrangler CLI has no bulk-empty; delete it
via the Cloudflare dashboard if you want the binaries gone. Orphaned files are
harmless — no D1 row references them after this runs.)

Irreversible. The site will 404 on the custom domain until the next deploy.

EOF

read -rp "Type 'blow it all up' to confirm: " confirm
[ "$confirm" = "blow it all up" ] || { echo "aborted."; exit 1; }

echo

# --- Step 1: Delete D1 database ---

echo "→ Deleting D1 database: $D1_NAME"
# -y skips the interactive confirm; fallback to yes-pipe if flag unsupported
yarn wrangler d1 delete "$D1_NAME" -y 2>&1 || \
	yes | yarn wrangler d1 delete "$D1_NAME" 2>&1 || \
	echo "  (delete failed — may not exist, continuing)"

# --- Step 2: Delete Worker ---

echo
echo "→ Deleting Worker: $WORKER_NAME"
yarn wrangler delete --name "$WORKER_NAME" 2>&1 || \
	yes | yarn wrangler delete --name "$WORKER_NAME" 2>&1 || \
	echo "  (delete failed — may not exist, continuing)"

# --- Step 3: Recreate D1 database ---

echo
echo "→ Creating D1 database: $D1_NAME"
create_output=$(yarn wrangler d1 create "$D1_NAME")
echo "$create_output"

# Try to extract the new database_id from wrangler's output.
new_db_id=$(printf '%s' "$create_output" | grep -oE '"database_id"\s*:\s*"[^"]+"' | head -1 | grep -oE '[a-f0-9-]{36}' || true)

cat <<EOF

==================================================
Hard reset complete.

Next steps:
EOF

if [ -n "$new_db_id" ]; then
	echo "  1. Update wrangler.jsonc d1_databases[0].database_id to:"
	echo "       $new_db_id"
else
	echo "  1. Copy the new database_id from the output above into"
	echo "     wrangler.jsonc (d1_databases[0].database_id)"
fi

cat <<'EOF'
  2. Scaffold or apply your clean EmDash baseline.
  3. yarn install
  4. yarn bootstrap          # emdash init + seed into the new D1
  5. yarn deploy             # redeploys the Worker, custom domain comes back

Note: custom domain bindings in wrangler.jsonc will be re-applied on deploy.
==================================================
EOF
