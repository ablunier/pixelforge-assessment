# PixelForge

Image-generation app that resells Pixelforge's provider at cost plus markup. See [GLOSSARY.md](GLOSSARY.md) and [docs/adr](docs/adr).

## Run locally ([Laravel Sail](https://laravel.com/docs/sail))

```sh
cp .env.example .env
docker run --rm -v "$PWD":/app -w /app composer:2 composer install --ignore-platform-reqs   # first clone only
npm install && npm run build
docker compose up --build
./vendor/bin/sail artisan key:generate   # once, on a fresh .env
```

Starts the app (http://localhost:8000), a queue worker, the scheduler and Postgres; migrations run automatically. Fill in `PIXELFORGE_BASE_URL`, `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` in `.env` to talk to Pixelforge.

## Tests

Tests run against Postgres (`testing` database, created by Sail):

```sh
./vendor/bin/sail composer ci:check
```

## Production

Live at <https://pixelforge.blunier.me>. Every push to `main` is tested and then deployed to the droplet; see [docs/deploy.md](docs/deploy.md) for the stack and the one-time manual setup (DNS, droplet bootstrap, GitHub secrets).
