#!/usr/bin/env bash
#
# One-command deploy to the shared host.
#
#   - Builds the Svelte islands locally (the host has no Node).
#   - rsyncs the app to the host (vendor + .env + public/uploads are NOT synced:
#     vendor is rebuilt on the host, .env lives only on the host, and
#     public/uploads is owned by the admin on the host — clobbering it would
#     delete media uploaded through /admin/media).
#   - Runs composer + Phinx migrations on the host over SSH.
#
# First-time only (run manually on the host after the first deploy):
#   - upload the initial public/uploads/* (e.g. `rsync -az public/uploads/ HOST:PATH/public/uploads/`)
#   - `php bin/seed.php`   (import existing content)
#   - `php bin/user.php`   (create the admin user; set ADMIN_* in the host .env first)
#
# Config: copy .deploy.env.example to .deploy.env and fill it in.

set -euo pipefail
cd "$(dirname "$0")"

[ -f .deploy.env ] && source .deploy.env
: "${DEPLOY_HOST:?set DEPLOY_HOST (e.g. user@server) in .deploy.env}"
: "${DEPLOY_PATH:?set DEPLOY_PATH (remote app directory, above public_html) in .deploy.env}"
DEPLOY_PORT="${DEPLOY_PORT:-22}"
# PHP binary on the host used for Composer + Phinx (the host's default `php` may
# be too old). e.g. /opt/alt/php82/usr/bin/php on Hostinger.
PHP_BIN="${PHP_BIN:-php}"

echo "==> Building assets locally"
yarn install --frozen-lockfile
yarn build

echo "==> Syncing to ${DEPLOY_HOST}:${DEPLOY_PATH} (port ${DEPLOY_PORT})"
rsync -az --delete -e "ssh -p ${DEPLOY_PORT}" \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='.env' \
  --exclude='.deploy.env' \
  --exclude='public/uploads' \
  --exclude='.phpunit.cache' \
  --exclude='data.db' \
  ./ "${DEPLOY_HOST}:${DEPLOY_PATH}/"

echo "==> Installing PHP deps + migrating on host"
ssh -p "${DEPLOY_PORT}" "${DEPLOY_HOST}" "cd '${DEPLOY_PATH}' && ${PHP_BIN} \"\$(command -v composer)\" install --no-dev --optimize-autoloader && ${PHP_BIN} vendor/bin/phinx migrate -e production"

echo "==> Deploy complete."
