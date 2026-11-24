# syntax=docker/dockerfile:1

# Production image: one image runs the web app (FrankenPHP), the queue
# worker and the scheduler. Local development uses Laravel Sail instead.

FROM dunglas/frankenphp:1-php8.5-trixie AS base

RUN install-php-extensions pdo_pgsql pcntl intl zip bcmath opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/production/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"

WORKDIR /app

# --- PHP dependencies --------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

# --- Front-end assets (Wayfinder needs PHP + artisan to generate routes) -----
FROM vendor AS assets

COPY --from=node:22-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

ARG VITE_APP_NAME=PixelForge
ENV VITE_APP_NAME=$VITE_APP_NAME

RUN npm ci --no-audit --no-fund && npm run build

# --- Runtime -----------------------------------------------------------------
FROM base AS app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:8080

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/production/entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint \
    && rm -f /app/public/hot && ln -sfn ../storage/app/public /app/public/storage \
    && mkdir -p /app/storage/app/public /app/storage/app/private \
        /app/storage/framework/cache/data /app/storage/framework/sessions \
        /app/storage/framework/views /app/storage/logs \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data /config

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up > /dev/null || exit 1

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
