#!/bin/sh
# Runs before the web server, queue worker, scheduler and one-off artisan
# commands. Config is cached here, not at build time, because the
# production .env only exists on the production host.
set -e

cd /app

# The storage volume may be empty or from an older image.
mkdir -p storage/app/public storage/app/private \
    storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

php artisan optimize --quiet

exec "$@"
