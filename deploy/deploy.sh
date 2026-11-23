#!/usr/bin/env bash
# Deploys an image tag on the droplet: pulls it from GHCR, runs migrations and
# restarts the stack. The deploy workflow copies this script and
# compose.yaml next to .env in the stack directory and runs it over
# SSH (see docs/deploy.md); run it by hand there to deploy or roll back.
#
# Usage: ./deploy.sh <image-tag>   (a full commit SHA from main, or `latest`)
set -euo pipefail

tag="${1:?usage: $0 <image-tag>}"

cd "$(dirname "$0")"

# Record the tag in .env so later `docker compose` commands use the same image.
if grep -q '^IMAGE_TAG=' .env; then
    sed -i "s/^IMAGE_TAG=.*/IMAGE_TAG=${tag}/" .env
else
    printf '\nIMAGE_TAG=%s\n' "$tag" >> .env
fi

docker compose pull --quiet
docker compose up -d --wait pgsql
docker compose run --rm app php artisan migrate --force
docker compose up -d --remove-orphans --wait

# Drop older app images (a rollback pulls its tag again) and dangling layers.
docker image ls ghcr.io/ablunier/pixelforge-assessment --format '{{.Repository}}:{{.Tag}}' \
    | grep -vx "ghcr.io/ablunier/pixelforge-assessment:${tag}" \
    | xargs -r docker image rm > /dev/null 2>&1 || true
docker image prune -f > /dev/null

echo "Deployed ghcr.io/ablunier/pixelforge-assessment:${tag}"
