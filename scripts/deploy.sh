#!/usr/bin/env bash
# Deploys a commit on the droplet: checks it out, rebuilds the image, runs
# migrations and restarts the stack. Run by the deploy workflow over SSH
# (see docs/deploy.md); safe to run by hand too.
#
# Usage: scripts/deploy.sh [git-sha]   (defaults to origin/main)
set -euo pipefail

cd "$(dirname "$0")/.."

if [ -z "${PIXELFORGE_DEPLOY_CHECKED_OUT:-}" ]; then
    git fetch --quiet origin main
    git reset --hard --quiet "${1:-origin/main}"
    # Continue with the freshly checked-out version of this script.
    PIXELFORGE_DEPLOY_CHECKED_OUT=1 exec "$0"
fi

compose() {
    docker compose -f compose.production.yaml "$@"
}

compose build --pull app
compose up -d --wait pgsql
compose run --rm app php artisan migrate --force
compose up -d --remove-orphans --wait

docker image prune -f > /dev/null

echo "Deployed $(git rev-parse --short HEAD)"
